#!/usr/bin/env python3
"""
Book enrichment pipeline using Google Gemini API with Google Search grounding
and structured output via Pydantic.

Usage:
    uv run enrich_books.py --input books.json --output enriched_books.json

Requires GEMINI_API_KEY environment variable to be set.
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path

from dotenv import load_dotenv
from google import genai
from google.genai.types import GenerateContentConfig, GoogleSearch, Tool
from pydantic import BaseModel, Field


# ── Structured output schema ──────────────────────────────────────────────

class BookEnrichment(BaseModel):
    """Enrichment data returned by Gemini with Google Search grounding."""
    abstract_enriched: str = Field(
        description=(
            "A concise summary in Swedish (2-4 sentences) describing what the book is about, "
            "its main themes, target audience, and any notable aspects."
        )
    )
    subjects: list[str] = Field(
        default_factory=list,
        description="List of subject keywords/tags relevant to the book (in Swedish).",
    )
    tags: list[str] = Field(
        default_factory=list,
        description=(
            "5-10 descriptive, searchable tags in Swedish that capture the book's "
            "topics, themes, and practical areas. Use lowercase. "
            "Example: a book titled 'Bosses snidarkonst' might get tags like "
            "['träslöjd', 'hobby-snickeri', 'arbeta med trä', 'hantverk', 'knivslöjd']."
        ),
    )
    target_audience: str | None = Field(
        default=None,
        description="Target audience for the book, e.g. 'Gymnasiet', 'Högskola', 'Allmänheten'.",
    )


# ── Pipeline ──────────────────────────────────────────────────────────────

def load_books(path: Path) -> list[dict]:
    """Load source JSON. Accepts a JSON array, a single object, or {"data": [...]}."""
    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, dict):
        if "data" in data and isinstance(data["data"], list):
            data = data["data"]
        else:
            data = [data]
    return data


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


def enrich_book(
    client: genai.Client, model: str, book: dict
) -> tuple[BookEnrichment | None, dict]:
    """Call Gemini with Google Search grounding to enrich a single book.
    Returns (enrichment, grounding_metadata)."""
    title = book.get("title", "")
    author = book.get("author", "")
    isbn = book.get("isbn_clean", "")

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
        return enrichment, grounding
    except Exception as exc:
        print(f"  ⚠  Error enriching '{title}': {exc}", file=sys.stderr)
        return None, {"search_queries": [], "sources": []}


def run_pipeline(
    input_path: Path,
    output_path: Path,
    model: str,
    delay: float,
    api_key: str | None,
) -> None:
    """Main pipeline: load → enrich → save."""
    books = load_books(input_path)
    print(f"Loaded {len(books)} book(s) from {input_path}")

    client_kwargs = {}
    if api_key:
        client_kwargs["api_key"] = api_key
    client = genai.Client(**client_kwargs)

    results: list[dict] = []

    for i, book in enumerate(books, 1):
        biblio_id = book.get("biblio_id")
        isbn_clean = book.get("isbn_clean")
        title = book.get("title", "unknown")
        author = book.get("author", "unknown")

        print(f"[{i}/{len(books)}] Enriching: {title} ({author})")

        enrichment, grounding = enrich_book(client, model, book)

        if enrichment:
            preview = enrichment.model_dump()
            preview["grounding"] = grounding
            print(json.dumps(preview, ensure_ascii=False, indent=2))
        else:
            print("  ⚠  No enrichment returned")

        record = {
            "biblio_id": biblio_id,
            "isbn_clean": isbn_clean,
            "title": title,
            "abstract_enriched": enrichment.abstract_enriched if enrichment else None,
            "subjects": enrichment.subjects if enrichment else [],
            "tags": enrichment.tags if enrichment else [],
            "target_audience": enrichment.target_audience if enrichment else None,
            "grounding": grounding,
        }
        results.append(record)

        # Rate-limit delay between requests
        if i < len(books) and delay > 0:
            time.sleep(delay)

    output_path.write_text(
        json.dumps(results, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"\n✓ Wrote {len(results)} enriched record(s) to {output_path}")


# ── CLI ───────────────────────────────────────────────────────────────────

def main() -> None:
    load_dotenv()

    parser = argparse.ArgumentParser(
        description="Enrich book records via Gemini + Google Search grounding.",
    )
    parser.add_argument(
        "--input", "-i",
        type=Path,
        default=Path("books.json"),
        help="Path to source JSON file (default: books.json)",
    )
    parser.add_argument(
        "--output", "-o",
        type=Path,
        default=Path("enriched_books.json"),
        help="Path to output JSON file (default: enriched_books.json)",
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
        "--api-key",
        default=None,
        help="Gemini API key (or set GEMINI_API_KEY env var)",
    )
    args = parser.parse_args()

    run_pipeline(
        input_path=args.input,
        output_path=args.output,
        model=args.model,
        delay=args.delay,
        api_key=args.api_key,
    )


if __name__ == "__main__":
    main()