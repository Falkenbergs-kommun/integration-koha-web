#!/usr/bin/env python3
"""
AI Book Enrichment Pipeline - Version 2 (Optimized)
===================================================

Improved version that uses GraphQL and smart filtering to efficiently find
books that need enrichment, even with 26,000+ books in the database.

Key improvements:
- Uses GraphQL for efficient LEFT JOIN-style queries
- Fallback to paginated REST API if GraphQL not available
- Batched processing for better memory efficiency
- Direct database filtering instead of loading all IDs into memory

Usage:
    uv run enrich_from_directus_v2.py --limit 100
    uv run enrich_from_directus_v2.py --limit 50 --model gemini-1.5-pro
    uv run enrich_from_directus_v2.py --dry-run
"""

import os
import sys
import json
import time
import argparse
from pathlib import Path
from typing import Optional

import requests
from dotenv import load_dotenv
from pydantic import BaseModel, Field
from google import genai
from google.genai.types import GenerateContentConfig, GoogleSearch, Tool


# ── Configuration ─────────────────────────────────────────────────────────

# Load environment variables
env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

# API Configuration
DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY')

# Default model. OBS: gemini-1.5-serien är avvecklad ur API:t (404 sedan 2026)
DEFAULT_MODEL = 'gemini-3-flash-preview'
DEFAULT_DELAY = 1.0  # seconds between API calls


# ── Pydantic Models ───────────────────────────────────────────────────────

class EnrichedBook(BaseModel):
    """Schema for enriched book metadata."""

    abstract_enriched: str = Field(
        ...,
        description=(
            "En koncis sammanfattning på svenska (2-4 meningar) som beskriver vad boken handlar om, "
            "dess huvudteman, målgrupp och eventuella anmärkningsvärda aspekter."
        )
    )
    subjects: list[str] = Field(
        default_factory=list,
        description="Lista med ämnesord/nyckelord relevanta för boken (på svenska).",
    )
    tags: list[str] = Field(
        default_factory=list,
        description=(
            "5-10 beskrivande, sökbara taggar på svenska som fångar bokens "
            "ämnen, teman och praktiska områden. Använd lowercase."
        ),
    )
    target_audience: Optional[str] = Field(
        default=None,
        description="Målgrupp för boken, t.ex. 'Gymnasiet', 'Högskola', 'Allmänheten', 'Barn'.",
    )


# ── Directus API ──────────────────────────────────────────────────────────

class DirectusClient:
    """Client for interacting with Directus API with GraphQL support."""

    def __init__(self, api_url: str, api_token: str):
        self.api_url = api_url.rstrip('/')
        self.api_token = api_token
        self.headers = {
            'Authorization': f'Bearer {api_token}',
            'Content-Type': 'application/json'
        }
        self.graphql_available = self._check_graphql_support()

    def _check_graphql_support(self) -> bool:
        """Check if GraphQL endpoint is available."""
        try:
            url = f"{self.api_url}/graphql"
            response = requests.post(
                url,
                headers=self.headers,
                json={"query": "{__schema{types{name}}}"},
                timeout=5
            )
            return response.status_code == 200
        except:
            return False

    def get_unenriched_biblios_graphql(self, limit: int = 10, offset: int = 0) -> list[dict]:
        """
        Fetch biblios that need enrichment using GraphQL.

        This performs a LEFT JOIN to find biblios where:
        - abstract is null or empty
        - biblio_id does NOT exist in kft_koha_enriched
        """
        query = """
        query GetUnenrichedBiblios($limit: Int!, $offset: Int!) {
          kft_koha_biblios(
            filter: {
              _and: [
                { abstract: { _null: true } }
              ]
            }
            limit: $limit
            offset: $offset
            sort: "-biblio_id"
          ) {
            biblio_id
            isbn_clean
            isbn
            title
            author
            enriched: kft_koha_enriched_by_biblio_id {
              id
            }
          }
        }
        """

        variables = {"limit": limit * 3, "offset": offset}  # Fetch extra, filter client-side

        try:
            response = requests.post(
                f"{self.api_url}/graphql",
                headers=self.headers,
                json={"query": query, "variables": variables},
                timeout=30
            )
            response.raise_for_status()
            data = response.json()

            if 'errors' in data:
                print(f"GraphQL errors: {data['errors']}", file=sys.stderr)
                return []

            # Filter out biblios that already have enrichment
            biblios = data.get('data', {}).get('kft_koha_biblios', [])
            unenriched = [b for b in biblios if not b.get('enriched')]

            # Remove the enriched field from results
            for biblio in unenriched:
                biblio.pop('enriched', None)

            return unenriched[:limit]  # Return only requested limit

        except Exception as e:
            print(f"GraphQL query failed: {e}", file=sys.stderr)
            return []

    def get_unenriched_biblios_rest(self, limit: int = 10, batch_size: int = 1000) -> list[dict]:
        """
        Fetch biblios that need enrichment using REST API with pagination.

        This fetches enriched IDs in batches to avoid memory issues,
        then fetches biblios without abstracts and filters them.
        """
        print("      Using REST API with pagination...")

        # Step 1: Get enriched biblio_ids in batches
        enriched_ids = set()
        offset = 0

        while True:
            url = f"{self.api_url}/items/kft_koha_enriched"
            params = {
                'fields': 'biblio_id',
                'limit': batch_size,
                'offset': offset
            }

            try:
                response = requests.get(url, headers=self.headers, params=params, timeout=30)
                response.raise_for_status()
                data = response.json()

                batch = data.get('data', [])
                if not batch:
                    break

                enriched_ids.update(item['biblio_id'] for item in batch)
                offset += batch_size

                # Progress indicator
                if offset % 1000 == 0:
                    print(f"      Loaded {len(enriched_ids)} enriched IDs...", end='\r')

            except Exception as e:
                print(f"\n      Error fetching enriched IDs: {e}", file=sys.stderr)
                break

        print(f"      Loaded {len(enriched_ids)} enriched IDs (total)")

        # Step 2: Fetch biblios without abstracts
        url = f"{self.api_url}/items/kft_koha_biblios"
        params = {
            'filter[abstract][_null]': 'true',
            'fields': 'biblio_id,isbn_clean,isbn,title,author',
            'limit': limit * 5,  # Fetch extra since we'll filter
            'sort': '-biblio_id'
        }

        try:
            response = requests.get(url, headers=self.headers, params=params, timeout=30)
            response.raise_for_status()
            data = response.json()
            biblios = data.get('data', [])

            # Filter out already enriched
            unenriched = [b for b in biblios if b['biblio_id'] not in enriched_ids]
            return unenriched[:limit]

        except Exception as e:
            print(f"Error fetching biblios: {e}", file=sys.stderr)
            return []

    def get_unenriched_biblios_sql_filter(self, limit: int = 10) -> list[dict]:
        """
        Fetch biblios using SQL-like filtering with _nin (not in).

        This approach fetches a sample of enriched IDs and excludes them
        from the query, which is more efficient than loading all IDs.
        """
        # Get recent enriched biblio_ids (last 5000)
        url = f"{self.api_url}/items/kft_koha_enriched"
        params = {
            'fields': 'biblio_id',
            'limit': 5000,
            'sort': '-id'  # Most recent first
        }

        try:
            response = requests.get(url, headers=self.headers, params=params, timeout=30)
            response.raise_for_status()
            data = response.json()
            enriched_ids = [item['biblio_id'] for item in data.get('data', [])]

            # Now fetch biblios without abstracts, excluding known enriched IDs
            url = f"{self.api_url}/items/kft_koha_biblios"

            # Build filter with _nin (not in)
            filter_params = {
                'filter[abstract][_null]': 'true',
                'fields': 'biblio_id,isbn_clean,isbn,title,author',
                'limit': limit,
                'sort': '-biblio_id'
            }

            # Add _nin filter if we have enriched IDs
            if enriched_ids:
                # Directus _nin filter syntax
                filter_params['filter[biblio_id][_nin]'] = ','.join(map(str, enriched_ids))

            response = requests.get(url, headers=self.headers, params=filter_params, timeout=30)
            response.raise_for_status()
            data = response.json()

            return data.get('data', [])

        except Exception as e:
            print(f"SQL filter query failed: {e}", file=sys.stderr)
            return []

    def get_unenriched_biblios(self, limit: int = 10) -> list[dict]:
        """
        Smart method that tries different strategies to find unenriched biblios.

        Priority:
        1. GraphQL (most efficient)
        2. SQL filter with _nin (good for medium datasets)
        3. REST with pagination (fallback for large datasets)
        """
        print("      Finding unenriched biblios...")

        # Try GraphQL first (most efficient)
        if self.graphql_available:
            print("      Using GraphQL query...")
            biblios = self.get_unenriched_biblios_graphql(limit)
            if biblios:
                return biblios

        # Try SQL filter approach (good balance)
        print("      GraphQL not available, trying SQL filter...")
        biblios = self.get_unenriched_biblios_sql_filter(limit)
        if biblios:
            return biblios

        # Fall back to REST with pagination
        print("      Falling back to REST API with pagination...")
        return self.get_unenriched_biblios_rest(limit)

    def save_enriched_book(self, enriched_data: dict) -> bool:
        """Save enriched book data to kft_koha_enriched."""
        url = f"{self.api_url}/items/kft_koha_enriched"

        try:
            response = requests.post(url, headers=self.headers, json=enriched_data, timeout=30)
            response.raise_for_status()
            return True
        except Exception as e:
            print(f"Error saving enriched book: {e}", file=sys.stderr)
            return False


# ── Gemini Enrichment ─────────────────────────────────────────────────────

def enrich_book(
    client: genai.Client,
    model_name: str,
    book: dict
) -> tuple[Optional[EnrichedBook], Optional[dict], float]:
    """
    Enrich a book using Gemini API with Google Search grounding.

    Returns:
        (enriched_book, grounding_data, cost_usd)
    """
    isbn = book.get('isbn_clean') or book.get('isbn', '')
    title = book.get('title', 'Unknown')
    author = book.get('author', 'Unknown')

    prompt = (
        f"Sök information om följande bok och beskriv vad den handlar om.\n\n"
        f"Titel: {title}\n"
        f"Författare: {author}\n"
        f"ISBN: {isbn}\n\n"
        f"Ge en koncis sammanfattning på svenska om bokens innehåll, teman och målgrupp. "
        f"Inkludera relevanta ämnesord samt 5-10 beskrivande taggar (lowercase) som fångar "
        f"bokens ämnen, teman och praktiska områden."
    )

    try:
        response = client.models.generate_content(
            model=model_name,
            contents=prompt,
            config=GenerateContentConfig(
                tools=[Tool(google_search=GoogleSearch())],
                response_mime_type="application/json",
                response_json_schema=EnrichedBook.model_json_schema(),
            ),
        )

        # Parse enriched data
        enriched = EnrichedBook.model_validate_json(response.text)

        # Extract grounding metadata
        grounding = {"search_queries": [], "sources": []}
        candidate = response.candidates[0] if response.candidates else None
        if candidate and candidate.grounding_metadata:
            meta = candidate.grounding_metadata

            if meta.web_search_queries:
                grounding["search_queries"] = list(meta.web_search_queries)

            if meta.grounding_chunks:
                for chunk in meta.grounding_chunks:
                    if chunk.web:
                        grounding["sources"].append({
                            "uri": chunk.web.uri,
                            "title": chunk.web.title,
                        })

        # Calculate cost
        cost_usd = calculate_cost(response, model_name)

        return enriched, grounding, cost_usd

    except Exception as e:
        print(f"      Enrichment error: {e}", file=sys.stderr)
        return None, {"search_queries": [], "sources": []}, 0.0


def calculate_cost(response, model: str) -> float:
    """Calculate cost based on token usage and model pricing."""
    if not hasattr(response, 'usage_metadata'):
        return 0.0

    usage = response.usage_metadata
    prompt_tokens = usage.prompt_token_count
    output_tokens = usage.candidates_token_count

    # Pricing per 1M tokens (as of 2025)
    pricing = {
        'gemini-1.5-flash': {'input': 0.075, 'output': 0.30},
        'gemini-1.5-pro': {'input': 1.25, 'output': 5.00},
        'gemini-2.0-flash-exp': {'input': 0.075, 'output': 0.30},
        'gemini-3-flash-preview': {'input': 0.075, 'output': 0.30},
    }

    model_pricing = pricing.get(model, pricing['gemini-1.5-flash'])

    input_cost = (prompt_tokens / 1_000_000) * model_pricing['input']
    output_cost = (output_tokens / 1_000_000) * model_pricing['output']

    return input_cost + output_cost


# ── Main Pipeline ─────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Enrich books with AI metadata (v2 - optimized)')
    parser.add_argument('--limit', type=int, default=10, help='Number of books to enrich')
    parser.add_argument('--model', type=str, default=DEFAULT_MODEL, help='Gemini model to use')
    parser.add_argument('--delay', type=float, default=DEFAULT_DELAY, help='Delay between API calls (seconds)')
    parser.add_argument('--dry-run', action='store_true', help='Find books but do not enrich')

    args = parser.parse_args()

    # Validate environment
    if not all([DIRECTUS_API_URL, DIRECTUS_API_TOKEN, GEMINI_API_KEY]):
        print("Error: Missing environment variables. Check .env file.", file=sys.stderr)
        sys.exit(1)

    print("=" * 60)
    print("Book Enrichment Pipeline v2: Optimized for Scale")
    print("=" * 60)
    print()

    # Initialize clients
    directus = DirectusClient(DIRECTUS_API_URL, DIRECTUS_API_TOKEN)
    gemini_client = genai.Client(api_key=GEMINI_API_KEY)

    # Step 1: Find unenriched biblios
    print(f"[1/3] Finding biblios that need enrichment...")
    biblios = directus.get_unenriched_biblios(args.limit)
    print(f"      Found {len(biblios)} biblios to enrich")
    print()

    if not biblios:
        print("✓ No biblios to enrich. All done!")
        return

    if args.dry_run:
        print("DRY RUN - showing first 5 biblios that would be enriched:")
        for biblio in biblios[:5]:
            print(f"  - {biblio['biblio_id']}: {biblio.get('title', 'N/A')}")
        return

    # Step 2: Enrich books
    print(f"[2/3] Enriching {len(biblios)} books with Gemini API...")
    print()

    success_count = 0
    error_count = 0
    total_cost = 0.0

    for i, book in enumerate(biblios, 1):
        biblio_id = book['biblio_id']
        title = book.get('title', 'unknown')
        author = book.get('author', 'unknown')

        print(f"[{i}/{len(biblios)}] Enriching biblio_id {biblio_id}:")
        print(f"      {title} ({author})")

        # Enrich with Gemini
        enrichment, grounding, cost_usd = enrich_book(gemini_client, args.model, book)

        if not enrichment:
            print(f"      ✗ Enrichment failed")
            error_count += 1
            continue

        # Preview enrichment
        print(f"      ✓ Generated enrichment:")
        print(f"        Abstract: {enrichment.abstract_enriched[:80]}...")
        print(f"        Subjects: {', '.join(enrichment.subjects[:3])}...")
        print(f"        Tags: {', '.join(enrichment.tags[:5])}...")
        print(f"        Audience: {enrichment.target_audience}")
        print(f"        Cost: ${cost_usd:.6f} USD")

        # Save to Directus
        enriched_data = {
            'biblio_id': biblio_id,
            'isbn_clean': book.get('isbn_clean'),
            'title': title,
            'abstract_enriched': enrichment.abstract_enriched,
            'subjects': enrichment.subjects,
            'tags': enrichment.tags,
            'target_audience': enrichment.target_audience,
            'enrichment_cost_usd': cost_usd
        }

        if grounding:
            enriched_data['grounding_search_queries'] = [grounding['search_queries']]
            enriched_data['grounding_sources'] = grounding['sources']

        if directus.save_enriched_book(enriched_data):
            print(f"      ✓ Saved to Directus")
            success_count += 1
            total_cost += cost_usd
        else:
            print(f"      ✗ Failed to save to Directus")
            error_count += 1

        print()

        # Rate limiting
        if i < len(biblios):
            time.sleep(args.delay)

    # Summary
    print("=" * 60)
    print("Pipeline Complete!")
    print("=" * 60)
    print(f"✓ Successfully enriched: {success_count}")
    if error_count > 0:
        print(f"✗ Errors: {error_count}")
    print(f"💰 Total cost: ${total_cost:.6f} USD")
    if success_count > 0:
        print(f"   Average cost per book: ${total_cost/success_count:.6f} USD")


if __name__ == '__main__':
    main()
