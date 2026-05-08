#!/usr/bin/env python3
"""
Book enrichment pipeline: Fetch from Directus, enrich with Gemini, save to Directus.

This script:
1. Fetches books from kft_koha_biblios that lack abstracts
2. Filters out books already in kft_koha_enriched
3. Enriches up to N books using Google Gemini API with Google Search grounding
4. Saves enriched data directly to kft_koha_enriched via Directus API

Usage:
    uv run enrich_from_directus.py --limit 10

Requires:
- GEMINI_API_KEY in .env
- DIRECTUS_API_URL in .env
- DIRECTUS_API_TOKEN in .env
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path
from typing import Optional

import requests
from dotenv import load_dotenv
from google import genai
from google.genai.types import GenerateContentConfig, GoogleSearch, Tool
from pydantic import BaseModel, Field


# ── Structured output schema ──────────────────────────────────────────────

class BookEnrichment(BaseModel):
    """Enrichment data returned by Gemini with Google Search grounding."""
    abstract_enriched: str = Field(
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
    """Client for interacting with Directus API."""

    def __init__(self, api_url: str, api_token: str):
        self.api_url = api_url.rstrip('/')
        self.api_token = api_token
        self.headers = {
            'Authorization': f'Bearer {api_token}',
            'Content-Type': 'application/json'
        }

    def get_biblios_without_abstract(self, limit: int = 10) -> list[dict]:
        """Fetch biblios that have no or empty abstract."""
        url = f"{self.api_url}/items/kft_koha_biblios"
        params = {
            'filter[abstract][_null]': 'true',
            'fields': 'biblio_id,isbn_clean,isbn,title,author',
            'limit': limit * 3,  # Fetch extra in case some are already enriched
            'sort': '-biblio_id'  # Get newest first
        }

        try:
            response = requests.get(url, headers=self.headers, params=params)
            response.raise_for_status()
            data = response.json()
            return data.get('data', [])
        except Exception as e:
            print(f"Error fetching biblios: {e}", file=sys.stderr)
            return []

    def get_enriched_biblio_ids(self) -> set[int]:
        """Get set of biblio_ids that already exist in kft_koha_enriched."""
        url = f"{self.api_url}/items/kft_koha_enriched"
        params = {
            'fields': 'biblio_id',
            'limit': -1  # Get all
        }

        try:
            response = requests.get(url, headers=self.headers, params=params)
            response.raise_for_status()
            data = response.json()
            return {item['biblio_id'] for item in data.get('data', [])}
        except Exception as e:
            print(f"Error fetching enriched biblio_ids: {e}", file=sys.stderr)
            return set()

    def save_enriched_book(self, enriched_data: dict) -> bool:
        """Save enriched book data to kft_koha_enriched."""
        url = f"{self.api_url}/items/kft_koha_enriched"

        try:
            response = requests.post(url, headers=self.headers, json=enriched_data)
            response.raise_for_status()
            return True
        except Exception as e:
            print(f"  ✗ Error saving to Directus: {e}", file=sys.stderr)
            if hasattr(e, 'response') and e.response is not None:
                print(f"     Response: {e.response.text}", file=sys.stderr)
            return False


# ── Enrichment ───────────────────────────────────────────────────────────

def extract_grounding(response) -> dict:
    """Extract grounding metadata (sources) from a Gemini response."""
    grounding: dict = {"search_queries": [], "sources": []}
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
    """Calculate cost in USD based on token usage and model pricing.

    Pricing (as of 2024):
    - gemini-1.5-flash: $0.075/1M input, $0.30/1M output
    - gemini-1.5-pro: $1.25/1M input, $5.00/1M output
    - gemini-3-flash-preview: Free tier (assumed same pricing as 1.5-flash for calculation)
    """
    if not hasattr(response, 'usage_metadata') or not response.usage_metadata:
        return 0.0

    usage = response.usage_metadata
    prompt_tokens = getattr(usage, 'prompt_token_count', 0)
    output_tokens = getattr(usage, 'candidates_token_count', 0)

    # Pricing per 1M tokens
    pricing = {
        'gemini-1.5-flash': {'input': 0.075, 'output': 0.30},
        'gemini-1.5-pro': {'input': 1.25, 'output': 5.00},
        'gemini-3-flash-preview': {'input': 0.075, 'output': 0.30},  # Assumed
    }

    # Default to flash pricing if model not found
    model_pricing = pricing.get(model, pricing['gemini-1.5-flash'])

    # Calculate cost
    input_cost = (prompt_tokens / 1_000_000) * model_pricing['input']
    output_cost = (output_tokens / 1_000_000) * model_pricing['output']
    total_cost = input_cost + output_cost

    return total_cost


def enrich_book(
    client: genai.Client, model: str, book: dict
) -> tuple[Optional[BookEnrichment], dict, float]:
    """Call Gemini with Google Search grounding to enrich a single book.
    Returns (enrichment, grounding_metadata, cost_usd)."""
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
        return enrichment, grounding, cost, False
    except Exception as exc:
        print(f"  ⚠  Error enriching '{title}': {exc}", file=sys.stderr)
        err_str = str(exc)
        is_quota_err = "429" in err_str or "RESOURCE_EXHAUSTED" in err_str
        return None, {"search_queries": [], "sources": []}, 0.0, is_quota_err


# ── Pipeline ──────────────────────────────────────────────────────────────

def run_enrichment_pipeline(
    limit: int,
    model: str,
    delay: float,
    gemini_api_key: str,
    directus_url: str,
    directus_token: str,
    dry_run: bool = False
) -> dict:
    """Main pipeline: fetch → filter → enrich → save.

    Returns a summary dict so the caller can decide exit code based on the
    success/failure ratio. Empty dict means no work was needed.
    """

    print("=" * 60)
    print("Book Enrichment Pipeline: Directus → Gemini → Directus")
    print("=" * 60)
    print()

    # Initialize clients
    directus = DirectusClient(directus_url, directus_token)
    gemini_client = genai.Client(api_key=gemini_api_key)

    # Step 1: Fetch biblios without abstract
    print(f"[1/4] Fetching biblios without abstract...")
    biblios = directus.get_biblios_without_abstract(limit=limit)
    print(f"      Found {len(biblios)} biblios without abstract")

    # Step 2: Filter out already enriched
    print(f"[2/4] Filtering out already enriched biblios...")
    enriched_ids = directus.get_enriched_biblio_ids()
    print(f"      Found {len(enriched_ids)} already enriched")

    to_enrich = [b for b in biblios if b['biblio_id'] not in enriched_ids]
    to_enrich = to_enrich[:limit]  # Limit to requested number
    print(f"      {len(to_enrich)} biblios to enrich")
    print()

    if not to_enrich:
        print("✓ No biblios to enrich. All done!")
        return {}

    # Step 3: Enrich books
    print(f"[3/4] Enriching {len(to_enrich)} books with Gemini API...")
    print()

    success_count = 0
    error_count = 0
    quota_error_count = 0
    total_cost = 0.0

    for i, book in enumerate(to_enrich, 1):
        biblio_id = book['biblio_id']
        title = book.get('title', 'unknown')
        author = book.get('author', 'unknown')

        print(f"[{i}/{len(to_enrich)}] Enriching biblio_id {biblio_id}:")
        print(f"      {title} ({author})")

        # Enrich with Gemini
        enrichment, grounding, cost_usd, is_quota_err = enrich_book(gemini_client, model, book)

        if not enrichment:
            print(f"      ✗ Enrichment failed")
            error_count += 1
            if is_quota_err:
                quota_error_count += 1
            continue

        # Preview enrichment
        print(f"      ✓ Generated enrichment:")
        print(f"        Abstract: {enrichment.abstract_enriched[:80]}...")
        print(f"        Subjects: {', '.join(enrichment.subjects[:3])}...")
        print(f"        Tags: {', '.join(enrichment.tags[:5])}...")
        print(f"        Audience: {enrichment.target_audience}")
        print(f"        Cost: ${cost_usd:.6f} USD")

        # Step 4: Save to Directus
        if not dry_run:
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

            if directus.save_enriched_book(enriched_data):
                print(f"      ✓ Saved to Directus")
                success_count += 1
                total_cost += cost_usd
            else:
                print(f"      ✗ Failed to save")
                error_count += 1
        else:
            print(f"      (dry-run: would save to Directus)")
            success_count += 1
            total_cost += cost_usd

        print()

        # Rate limiting
        if i < len(to_enrich) and delay > 0:
            time.sleep(delay)

    # Summary
    print("=" * 60)
    print("Pipeline Complete!")
    print("=" * 60)
    print(f"✓ Successfully enriched: {success_count}")
    if error_count > 0:
        print(f"✗ Errors: {error_count}")
    if quota_error_count > 0:
        print(f"⚠  Gemini quota (429 RESOURCE_EXHAUSTED): {quota_error_count}")
    print(f"💰 Total cost: ${total_cost:.6f} USD")
    if success_count > 0:
        print(f"   Average cost per book: ${total_cost/success_count:.6f} USD")
    print()

    return {
        'success_count': success_count,
        'error_count': error_count,
        'quota_error_count': quota_error_count,
        'total_cost': total_cost,
    }


# ── CLI ───────────────────────────────────────────────────────────────────

def main() -> None:
    # Load .env from parent directory
    env_path = Path(__file__).parent.parent / '.env'
    load_dotenv(env_path)

    parser = argparse.ArgumentParser(
        description="Enrich books from Directus using Gemini API with Google Search grounding.",
    )
    parser.add_argument(
        "--limit", "-n",
        type=int,
        default=10,
        help="Number of books to enrich (default: 10)",
    )
    parser.add_argument(
        "--model", "-m",
        default="gemini-3-flash-preview",
        help="Gemini model to use (default: gemini-3-flash-preview)",
    )
    parser.add_argument(
        "--delay", "-d",
        type=float,
        default=1.0,
        help="Seconds to wait between API calls (default: 1.0)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Don't save to Directus, just show what would be done",
    )
    args = parser.parse_args()

    # Get API keys from environment
    gemini_api_key = os.getenv('GEMINI_API_KEY')
    directus_url = os.getenv('DIRECTUS_API_URL')
    directus_token = os.getenv('DIRECTUS_API_TOKEN')

    if not gemini_api_key:
        print("ERROR: GEMINI_API_KEY not found in .env", file=sys.stderr)
        sys.exit(1)

    if not directus_url or not directus_token:
        print("ERROR: DIRECTUS_API_URL or DIRECTUS_API_TOKEN not found in .env", file=sys.stderr)
        sys.exit(1)

    summary = run_enrichment_pipeline(
        limit=args.limit,
        model=args.model,
        delay=args.delay,
        gemini_api_key=gemini_api_key,
        directus_url=directus_url,
        directus_token=directus_token,
        dry_run=args.dry_run
    )

    # Exit non-zero if more books failed than succeeded — gives Healthchecks a fail signal
    # when Gemini quota is exhausted or another systemic issue is hitting the batch.
    if summary and summary['error_count'] > summary['success_count']:
        sys.exit(1)


if __name__ == "__main__":
    main()
