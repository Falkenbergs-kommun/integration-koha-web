#!/usr/bin/env python3
"""
Smart Book Enrichment - Uses offset pagination to find unenriched books

Instead of loading all enriched IDs into memory, this version:
1. Fetches biblios without abstracts using pagination (offset)
2. For each batch, checks if they're already enriched
3. Enriches the ones that aren't
4. Continues until we've enriched the requested number

This solves the scalability problem for 26,000+ books.
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
from google import genai
from google.genai.types import GenerateContentConfig, GoogleSearch, Tool
from pydantic import BaseModel, Field


# ── Configuration ─────────────────────────────────────────────────────────

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY')

DEFAULT_MODEL = 'gemini-3-flash-preview'  # Supports both Google Search + JSON output
DEFAULT_DELAY = 1.0
BATCH_SIZE = 50  # Fetch this many biblios at a time


# ── Pydantic Models ───────────────────────────────────────────────────────

class BookEnrichment(BaseModel):
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


# ── Directus Client ───────────────────────────────────────────────────────

class DirectusClient:
    """Client for interacting with Directus API."""

    def __init__(self, api_url: str, api_token: str):
        self.api_url = api_url.rstrip('/')
        self.api_token = api_token
        self.headers = {
            'Authorization': f'Bearer {api_token}',
            'Content-Type': 'application/json'
        }

    def get_biblios_without_abstract_paginated(
        self, offset: int = 0, limit: int = 50
    ) -> tuple[list[dict], bool]:
        """
        Fetch a batch of biblios without abstracts.

        Returns:
            (biblios, has_more) - list of biblios and whether there are more to fetch
        """
        url = f"{self.api_url}/items/kft_koha_biblios"
        params = {
            'filter[abstract][_null]': 'true',
            'fields': 'biblio_id,isbn_clean,isbn,title,author',
            'limit': limit,
            'offset': offset,
            'sort': '-biblio_id'  # Newest first
        }

        try:
            response = requests.get(url, headers=self.headers, params=params, timeout=30)
            response.raise_for_status()
            data = response.json()
            biblios = data.get('data', [])

            # Check if there are more results
            has_more = len(biblios) == limit

            return biblios, has_more

        except Exception as e:
            print(f"Error fetching biblios: {e}", file=sys.stderr)
            return [], False

    def is_enriched(self, biblio_id: int) -> bool:
        """Check if a single biblio is already enriched."""
        url = f"{self.api_url}/items/kft_koha_enriched"
        params = {
            'filter[biblio_id][_eq]': biblio_id,
            'limit': 1,
            'fields': 'id'
        }

        try:
            response = requests.get(url, headers=self.headers, params=params, timeout=10)
            response.raise_for_status()
            data = response.json()
            return len(data.get('data', [])) > 0

        except Exception as e:
            print(f"Error checking enrichment for {biblio_id}: {e}", file=sys.stderr)
            return False  # Assume not enriched on error

    def are_enriched_batch(self, biblio_ids: list[int]) -> set[int]:
        """
        Check which biblios in a batch are already enriched.

        Returns set of enriched biblio_ids.
        """
        if not biblio_ids:
            return set()

        url = f"{self.api_url}/items/kft_koha_enriched"

        # Use POST request with filter to avoid URL length issues
        # Actually, we'll use multiple requests with smaller batches
        enriched = set()
        chunk_size = 100

        for i in range(0, len(biblio_ids), chunk_size):
            chunk = biblio_ids[i:i+chunk_size]
            ids_str = ','.join(map(str, chunk))

            params = {
                'filter[biblio_id][_in]': ids_str,
                'fields': 'biblio_id',
                'limit': chunk_size
            }

            try:
                response = requests.get(url, headers=self.headers, params=params, timeout=30)
                response.raise_for_status()
                data = response.json()
                enriched.update(item['biblio_id'] for item in data.get('data', []))

            except Exception as e:
                print(f"Error checking batch: {e}", file=sys.stderr)
                continue

        return enriched

    def save_enriched_book(self, enriched_data: dict) -> tuple[bool, str]:
        """
        Save enriched book data to kft_koha_enriched.

        Returns:
            (success, error_message)
        """
        url = f"{self.api_url}/items/kft_koha_enriched"

        try:
            response = requests.post(url, headers=self.headers, json=enriched_data, timeout=30)
            response.raise_for_status()
            return True, ""
        except requests.exceptions.HTTPError as e:
            # Try to parse error response
            error_msg = str(e)
            try:
                error_data = e.response.json()
                if 'errors' in error_data:
                    errors = error_data['errors']
                    # Check for duplicate key error
                    for error in errors:
                        error_str = str(error)
                        # Check for duplicate/unique in message or RECORD_NOT_UNIQUE code
                        if ('duplicate' in error_str.lower() or
                            'unique' in error_str.lower() or
                            'RECORD_NOT_UNIQUE' in error_str):
                            return False, "DUPLICATE"
                    error_msg = '; '.join([str(err) for err in errors])
                elif 'message' in error_data:
                    error_msg = error_data['message']
            except:
                pass

            print(f"Error saving enriched book: {error_msg}", file=sys.stderr)
            return False, error_msg
        except Exception as e:
            print(f"Error saving enriched book: {e}", file=sys.stderr)
            return False, str(e)


# ── Gemini Enrichment ─────────────────────────────────────────────────────

def enrich_book(
    client: genai.Client, model: str, book: dict
) -> tuple[Optional[BookEnrichment], dict, float]:
    """Enrich a book using Gemini API with Google Search grounding."""
    title = book.get("title", "")
    author = book.get("author", "")
    isbn = book.get("isbn_clean") or book.get("isbn", "")

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
            model=model,
            contents=prompt,
            config=GenerateContentConfig(
                tools=[Tool(google_search=GoogleSearch())],
                response_mime_type="application/json",
                response_json_schema=BookEnrichment.model_json_schema(),
            ),
        )

        enrichment = BookEnrichment.model_validate_json(response.text)
        grounding = extract_grounding(response)
        cost = calculate_cost(response, model)
        return enrichment, grounding, cost

    except Exception as exc:
        print(f"      Error enriching: {exc}", file=sys.stderr)
        return None, {"search_queries": [], "sources": []}, 0.0


def extract_grounding(response) -> dict:
    """Extract grounding metadata from Gemini response."""
    grounding = {"search_queries": [], "sources": []}
    candidate = response.candidates[0] if response.candidates else None
    if not candidate or not candidate.grounding_metadata:
        return grounding

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

    return grounding


def calculate_cost(response, model: str) -> float:
    """Calculate cost in USD based on token usage."""
    if not hasattr(response, 'usage_metadata') or not response.usage_metadata:
        return 0.0

    usage = response.usage_metadata
    prompt_tokens = getattr(usage, 'prompt_token_count', 0)
    output_tokens = getattr(usage, 'candidates_token_count', 0)

    pricing = {
        'gemini-1.5-flash': {'input': 0.075, 'output': 0.30},
        'gemini-1.5-pro': {'input': 1.25, 'output': 5.00},
        'gemini-3-flash-preview': {'input': 0.075, 'output': 0.30},
    }

    model_pricing = pricing.get(model, pricing['gemini-1.5-flash'])

    input_cost = (prompt_tokens / 1_000_000) * model_pricing['input']
    output_cost = (output_tokens / 1_000_000) * model_pricing['output']

    return input_cost + output_cost


# ── Main Pipeline ─────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Smart book enrichment with pagination')
    parser.add_argument('--limit', type=int, default=10, help='Number of books to enrich')
    parser.add_argument('--model', type=str, default=DEFAULT_MODEL, help='Gemini model to use')
    parser.add_argument('--delay', type=float, default=DEFAULT_DELAY, help='Delay between API calls')
    parser.add_argument('--start-offset', type=int, default=0, help='Starting offset (to skip already processed batches)')
    parser.add_argument('--dry-run', action='store_true', help='Find books but do not enrich')

    args = parser.parse_args()

    # Validate environment
    if not all([DIRECTUS_API_URL, DIRECTUS_API_TOKEN, GEMINI_API_KEY]):
        print("Error: Missing environment variables. Check .env file.", file=sys.stderr)
        sys.exit(1)

    print("=" * 60)
    print("Smart Book Enrichment Pipeline")
    print("=" * 60)
    print()

    # Initialize clients
    directus = DirectusClient(DIRECTUS_API_URL, DIRECTUS_API_TOKEN)
    gemini_client = genai.Client(api_key=GEMINI_API_KEY)

    # Find and enrich books
    enriched_count = 0
    total_cost = 0.0
    offset = args.start_offset
    books_to_enrich = []

    print(f"[1/2] Finding unenriched biblios (starting at offset {offset})...")
    print()

    # Keep fetching until we have enough books to enrich
    while enriched_count < args.limit:
        # Fetch next batch
        biblios, has_more = directus.get_biblios_without_abstract_paginated(offset, BATCH_SIZE)

        if not biblios:
            print(f"      No more biblios found at offset {offset}")
            break

        print(f"      Checking batch at offset {offset} ({len(biblios)} biblios)...", end='')

        # Check which ones are already enriched
        biblio_ids = [b['biblio_id'] for b in biblios]
        enriched_ids = directus.are_enriched_batch(biblio_ids)

        # Filter to unenriched
        unenriched = [b for b in biblios if b['biblio_id'] not in enriched_ids]
        print(f" found {len(unenriched)} unenriched")

        # Add to our list
        books_to_enrich.extend(unenriched)

        # Stop if we have enough
        if len(books_to_enrich) >= args.limit:
            books_to_enrich = books_to_enrich[:args.limit]
            break

        # Move to next batch
        offset += BATCH_SIZE

        if not has_more:
            print(f"      No more biblios available")
            break

    print()
    print(f"      Found {len(books_to_enrich)} biblios to enrich")
    print()

    if not books_to_enrich:
        print("✓ No biblios to enrich. All done!")
        return

    if args.dry_run:
        print("DRY RUN - First 10 biblios that would be enriched:")
        for i, biblio in enumerate(books_to_enrich[:10], 1):
            print(f"  {i}. {biblio['biblio_id']}: {biblio.get('title', 'N/A')}")
        return

    # Enrich books
    print(f"[2/2] Enriching {len(books_to_enrich)} books with Gemini API...")
    print()

    for i, book in enumerate(books_to_enrich, 1):
        biblio_id = book['biblio_id']
        title = book.get('title', 'unknown')
        author = book.get('author', 'unknown')

        print(f"[{i}/{len(books_to_enrich)}] Enriching biblio_id {biblio_id}:")
        print(f"      {title} ({author})")

        # Enrich
        enrichment, grounding, cost_usd = enrich_book(gemini_client, args.model, book)

        if not enrichment:
            print(f"      ✗ Enrichment failed")
            continue

        # Preview
        print(f"      ✓ Generated enrichment:")
        print(f"        Abstract: {enrichment.abstract_enriched[:80]}...")
        print(f"        Subjects: {', '.join(enrichment.subjects[:3])}...")
        print(f"        Tags: {', '.join(enrichment.tags[:5])}...")
        print(f"        Audience: {enrichment.target_audience}")
        print(f"        Cost: ${cost_usd:.6f} USD")

        # Save
        enriched_data = {
            'biblio_id': biblio_id,
            'isbn_clean': book.get('isbn_clean'),
            'title': title,
            'abstract_enriched': enrichment.abstract_enriched,
            'subjects': enrichment.subjects,
            'tags': enrichment.tags,
            'target_audience': enrichment.target_audience,
            'grounding_search_queries': grounding.get('search_queries', []),
            'grounding_sources': grounding.get('sources', []),
            'enrichment_cost_usd': cost_usd
        }

        success, error_msg = directus.save_enriched_book(enriched_data)
        if success:
            print(f"      ✓ Saved to Directus")
            enriched_count += 1
            total_cost += cost_usd
        else:
            if error_msg == "DUPLICATE":
                print(f"      ⚠ Already enriched (skipping)")
                # Count as success since book is enriched
                enriched_count += 1
                total_cost += cost_usd
            else:
                print(f"      ✗ Failed to save to Directus")

        print()

        # Rate limiting
        if i < len(books_to_enrich):
            time.sleep(args.delay)

    # Summary
    print("=" * 60)
    print("Pipeline Complete!")
    print("=" * 60)
    print(f"✓ Successfully enriched: {enriched_count}")
    print(f"💰 Total cost: ${total_cost:.6f} USD")
    if enriched_count > 0:
        print(f"   Average cost per book: ${total_cost/enriched_count:.6f} USD")


if __name__ == '__main__':
    main()
