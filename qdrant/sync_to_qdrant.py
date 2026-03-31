#!/usr/bin/env python3
"""
Synka biblioteksdata från Directus till Qdrant med hybrid search (dense + sparse).

Använder prepare_embedding_text.php för att hämta och aggregera data,
sedan genererar dense (OpenAI) och sparse (BM25) vektorer för varje biblio.

Användning:
    uv run sync_to_qdrant.py                    # Full sync
    uv run sync_to_qdrant.py --dry-run          # Visa ändringar utan att synka
    uv run sync_to_qdrant.py --limit=50         # Synka max 50 biblios
    uv run sync_to_qdrant.py --force            # Omgenerera alla embeddings
    uv run sync_to_qdrant.py --verbose          # Detaljerad loggning
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import socket
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

from dotenv import load_dotenv
from openai import OpenAI
from qdrant_client import QdrantClient
from qdrant_client.models import (
    Distance,
    FieldCondition,
    Filter,
    MatchValue,
    PayloadSchemaType,
    PointStruct,
    SparseVector,
    SparseVectorParams,
    TextIndexParams,
    TokenizerType,
    VectorParams,
)

# ─── Konstanter ───────────────────────────────────────────────────────

COLLECTION_NAME = "koha-biblios"
EMBEDDING_MODEL = "text-embedding-3-large"
DIMENSIONS = 3072
EMBEDDING_BATCH_SIZE = 100
QDRANT_UPSERT_BATCH_SIZE = 100
SYNC_CHUNK_SIZE = 1000
MAX_RETRIES = 3

# Sökväg till PHP-scriptet (relativt denna fil)
PHP_SCRIPT = Path(__file__).parent.parent / "directus" / "prepare_embedding_text.php"

# ─── IPv4-workaround ─────────────────────────────────────────────────
# Qdrant-servern har AAAA-records men lyssnar inte på IPv6.

_original_getaddrinfo = socket.getaddrinfo


def _ipv4_getaddrinfo(host, port, family=0, type=0, proto=0, flags=0):
    if family == 0:
        family = socket.AF_INET
    return _original_getaddrinfo(host, port, family, type, proto, flags)


socket.getaddrinfo = _ipv4_getaddrinfo


# ─── Konfiguration ───────────────────────────────────────────────────


def load_config() -> dict:
    """Ladda miljövariabler från .env."""
    env_path = Path(__file__).parent.parent / ".env"
    load_dotenv(env_path)

    required = ["OPENAI_API_KEY", "QDRANT_URL", "QDRANT_API_KEY"]
    config = {}
    missing = []

    for key in required:
        val = os.getenv(key)
        if not val:
            missing.append(key)
        config[key] = val

    if missing:
        print(f"Saknar miljövariabler i .env: {', '.join(missing)}", file=sys.stderr)
        sys.exit(1)

    return config


# ─── Qdrant-klient ───────────────────────────────────────────────────


def make_qdrant_client(url: str, api_key: str | None) -> QdrantClient:
    """Skapa Qdrant-klient med explicit URL-parsning (port ignoreras annars)."""
    from urllib.parse import urlparse

    parsed = urlparse(url)
    port = parsed.port or (443 if parsed.scheme == "https" else 6333)

    kwargs: dict = {
        "host": parsed.hostname,
        "port": port,
        "https": parsed.scheme == "https",
        "timeout": 120,
    }
    if api_key:
        kwargs["api_key"] = api_key
    return QdrantClient(**kwargs)


def ensure_collection(client: QdrantClient) -> bool:
    """Skapa collection med dense + sparse vektorer och payload-index. Returnerar True om ny."""
    existing = [c.name for c in client.get_collections().collections]
    if COLLECTION_NAME in existing:
        return False

    # Skapa collection med namngivna vektorer
    client.create_collection(
        collection_name=COLLECTION_NAME,
        vectors_config={
            "dense": VectorParams(size=DIMENSIONS, distance=Distance.COSINE),
        },
        sparse_vectors_config={
            "sparse": SparseVectorParams(
                modifier="idf",
            ),
        },
    )

    # Payload-index för filtrering
    index_configs = [
        ("biblio_id", PayloadSchemaType.INTEGER),
        ("author", PayloadSchemaType.KEYWORD),
        ("isbn", PayloadSchemaType.KEYWORD),
        ("publication_year", PayloadSchemaType.INTEGER),
        ("publisher", PayloadSchemaType.KEYWORD),
        ("media_types", PayloadSchemaType.KEYWORD),
        ("target_audience", PayloadSchemaType.KEYWORD),
        ("subjects", PayloadSchemaType.KEYWORD),
        ("tags", PayloadSchemaType.KEYWORD),
        ("branches", PayloadSchemaType.KEYWORD),
        ("series_title", PayloadSchemaType.KEYWORD),
        ("language_code", PayloadSchemaType.KEYWORD),
        ("genre_form", PayloadSchemaType.KEYWORD),
        ("subjects_marc", PayloadSchemaType.KEYWORD),
        ("sab_classification", PayloadSchemaType.KEYWORD),
        ("contributors", PayloadSchemaType.KEYWORD),
    ]

    for field_name, schema_type in index_configs:
        client.create_payload_index(
            collection_name=COLLECTION_NAME,
            field_name=field_name,
            field_schema=schema_type,
        )

    # Fulltext-index på titel (tokenisering för sökning)
    client.create_payload_index(
        collection_name=COLLECTION_NAME,
        field_name="title",
        field_schema=TextIndexParams(
            type="text",
            tokenizer=TokenizerType.MULTILINGUAL,
            min_token_len=2,
            max_token_len=20,
        ),
    )

    return True


def ensure_indexes(client: QdrantClient) -> None:
    """Skapa saknade payload-index på befintlig collection (idempotent)."""
    new_indexes = [
        ("language_code", PayloadSchemaType.KEYWORD),
        ("genre_form", PayloadSchemaType.KEYWORD),
        ("subjects_marc", PayloadSchemaType.KEYWORD),
        ("sab_classification", PayloadSchemaType.KEYWORD),
        ("contributors", PayloadSchemaType.KEYWORD),
    ]

    for field_name, schema_type in new_indexes:
        try:
            client.create_payload_index(
                collection_name=COLLECTION_NAME,
                field_name=field_name,
                field_schema=schema_type,
            )
        except Exception:
            pass  # Index finns redan


# ─── Datahämtning via PHP ────────────────────────────────────────────


def fetch_embedding_data(limit: int | None, verbose: bool) -> list[dict]:
    """Kör prepare_embedding_text.php och parsa JSONL-output."""
    cmd = ["php", str(PHP_SCRIPT), "--output=jsonl"]
    if limit:
        cmd.append(f"--limit={limit}")
    if verbose:
        cmd.append("--verbose")

    if verbose:
        print(f"  Kör: {' '.join(cmd)}")

    result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)

    if result.returncode != 0:
        print(f"PHP-scriptet misslyckades (exit {result.returncode}):", file=sys.stderr)
        print(result.stderr, file=sys.stderr)
        sys.exit(1)

    records = []
    for line in result.stdout.strip().split("\n"):
        line = line.strip()
        if not line:
            continue
        # Filtrera bort PHP:s status-output (rader som inte börjar med {)
        if not line.startswith("{"):
            if verbose:
                print(f"  [PHP] {line}")
            continue
        try:
            records.append(json.loads(line))
        except json.JSONDecodeError:
            if verbose:
                print(f"  [PHP] {line}")
            continue

    return records


# ─── Hjälpfunktioner ─────────────────────────────────────────────────


def biblio_id_to_uuid(biblio_id: int) -> str:
    """Deterministiskt UUID från biblio_id för idempotenta upserts."""
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"koha-biblio-{biblio_id}"))


def content_hash(text: str) -> str:
    """SHA256-hash av text för change detection."""
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def parse_publication_year(year_str: str | None) -> int | None:
    """Extrahera årtal som heltal från varierade format."""
    if not year_str:
        return None
    # Hantera format som "2023", "c2023", "[2023]", "2023."
    import re
    match = re.search(r"(19|20)\d{2}", str(year_str))
    return int(match.group(0)) if match else None


# ─── Hämta befintligt tillstånd från Qdrant ──────────────────────────


def get_existing_state(client: QdrantClient, verbose: bool) -> dict[int, tuple[str, str]]:
    """Hämta {biblio_id: (point_id, content_hash)} för alla punkter i collectionen."""
    state: dict[int, tuple[str, str]] = {}
    offset = None

    while True:
        points, offset = client.scroll(
            collection_name=COLLECTION_NAME,
            with_payload=["biblio_id", "content_hash"],
            with_vectors=False,
            limit=100,
            offset=offset,
        )

        for p in points:
            bid = p.payload.get("biblio_id")
            chash = p.payload.get("content_hash", "")
            if bid is not None:
                state[int(bid)] = (p.id, chash)

        if offset is None:
            break

    if verbose:
        print(f"  Hämtade {len(state)} befintliga punkter från Qdrant")

    return state


# ─── Dense embeddings (OpenAI) ───────────────────────────────────────


def embed_dense(texts: list[str], api_key: str, verbose: bool) -> list[list[float]]:
    """Generera dense embeddings i batchar med retry."""
    client = OpenAI(api_key=api_key)
    all_embeddings: list[list[float]] = []
    total_batches = (len(texts) - 1) // EMBEDDING_BATCH_SIZE + 1

    for i in range(0, len(texts), EMBEDDING_BATCH_SIZE):
        batch = texts[i: i + EMBEDDING_BATCH_SIZE]
        batch_num = i // EMBEDDING_BATCH_SIZE + 1

        if verbose:
            print(f"  Dense embedding batch {batch_num}/{total_batches} ({len(batch)} texter)")

        for attempt in range(MAX_RETRIES):
            try:
                response = client.embeddings.create(
                    model=EMBEDDING_MODEL,
                    input=batch,
                    dimensions=DIMENSIONS,
                )
                all_embeddings.extend([e.embedding for e in response.data])
                break
            except Exception as e:
                if attempt < MAX_RETRIES - 1:
                    wait = 2 ** (attempt + 1)
                    print(f"  Retry {attempt + 1}/{MAX_RETRIES} efter {wait}s: {e}")
                    time.sleep(wait)
                else:
                    raise

    return all_embeddings


# ─── Sparse embeddings (BM25 via fastembed) ──────────────────────────


def embed_sparse(texts: list[str], model, verbose: bool) -> list[SparseVector]:
    """Generera BM25 sparse vectors lokalt via fastembed."""
    if verbose:
        print(f"  Genererar BM25 sparse vectors för {len(texts)} texter...")

    sparse_vectors = []
    for embedding in model.embed(texts, batch_size=32):
        sparse_vectors.append(
            SparseVector(
                indices=embedding.indices.tolist(),
                values=embedding.values.tolist(),
            )
        )

    return sparse_vectors


# ─── Bygg Qdrant payload ─────────────────────────────────────────────


def build_payload(record: dict, hash_val: str) -> dict:
    """Bygg payload-objekt från PHP-metadata."""
    meta = record.get("metadata", {})

    payload = {
        "biblio_id": meta.get("biblio_id"),
        "title": meta.get("title"),
        "author": meta.get("author"),
        "isbn": meta.get("isbn"),
        "publication_year": parse_publication_year(meta.get("publication_year")),
        "publisher": meta.get("publisher"),
        "media_types": meta.get("media_types", []),
        "target_audience": meta.get("target_audience"),
        "subjects": meta.get("subjects", []),
        "tags": meta.get("tags", []),
        "branches": meta.get("branches", []),
        "series_title": meta.get("series_title"),
        "part_number": meta.get("part_number"),
        "part_name": meta.get("part_name"),
        "subtitle": meta.get("subtitle"),
        "total_items": meta.get("total_items", 0),
        "available_items": meta.get("available_items", 0),
        "total_holds": meta.get("total_holds", 0),
        "has_enriched_abstract": meta.get("has_enriched_abstract", False),
        "age_restriction": meta.get("age_restriction"),
        "publication_place": meta.get("publication_place"),
        "catalog_link": meta.get("catalog_link"),
        "image_url": meta.get("image_url"),
        "ean": meta.get("ean"),
        "language_code": meta.get("language_code"),
        "subjects_marc": meta.get("subjects_marc", []),
        "genre_form": meta.get("genre_form", []),
        "sab_classification": meta.get("sab_classification"),
        "contributors": meta.get("contributors", []),
        "embedding_text": record.get("embedding_text", ""),
        "content_hash": hash_val,
        "last_synced": datetime.now(timezone.utc).isoformat(),
    }

    return payload


# ─── Huvudsynk ────────────────────────────────────────────────────────


def sync(force: bool, limit: int | None, dry_run: bool, verbose: bool) -> None:
    """Huvudfunktion: synka biblioteksdata till Qdrant."""
    start_time = time.time()

    print()
    print("=" * 60)
    print("  Koha → Qdrant hybrid search sync")
    print("=" * 60)
    print()

    # 1. Ladda konfiguration
    config = load_config()
    if verbose:
        print(f"  Qdrant URL: {config['QDRANT_URL']}")
        print(f"  Collection: {COLLECTION_NAME}")
        print()

    # 2. Hämta data från PHP
    print("Steg 1/5: Hämtar data från Directus via PHP...")
    records = fetch_embedding_data(limit, verbose)
    print(f"  Fick {len(records)} biblios\n")

    if not records:
        print("Inga poster att synka.")
        return

    # 3. Anslut till Qdrant och säkerställ collection
    print("Steg 2/5: Ansluter till Qdrant...")
    client = make_qdrant_client(config["QDRANT_URL"], config["QDRANT_API_KEY"])
    is_new = ensure_collection(client)
    if is_new:
        print("  Skapade ny collection med dense + sparse vektorer")
    else:
        print("  Collection finns redan")
        ensure_indexes(client)
    print()

    # 4. Hämta befintligt tillstånd
    print("Steg 3/5: Jämför med befintligt tillstånd...")
    existing = {} if is_new else get_existing_state(client, verbose)

    # Klassificera poster
    php_biblio_ids = set()
    to_embed = []
    unchanged = 0

    for record in records:
        bid = record.get("biblio_id")
        if bid is None:
            continue
        php_biblio_ids.add(bid)

        text = record.get("embedding_text", "")
        new_hash = content_hash(text)

        if force or bid not in existing or existing[bid][1] != new_hash:
            to_embed.append((record, new_hash))
        else:
            unchanged += 1

    # Hitta borttagna (finns i Qdrant men inte i PHP-output)
    to_delete = []
    for bid, (point_id, _) in existing.items():
        if bid not in php_biblio_ids:
            to_delete.append((bid, point_id))

    print(f"  Nya/ändrade: {len(to_embed)}")
    print(f"  Oförändrade: {unchanged}")
    print(f"  Att radera:  {len(to_delete)}")
    print()

    if len(to_embed) == 0 and len(to_delete) == 0:
        print("Allt är uppdaterat. Inget att göra.")
        return

    if dry_run:
        print("[DRY RUN] Inga ändringar gjorda.")
        if to_delete and verbose:
            print(f"  Skulle radera biblio_id: {[bid for bid, _ in to_delete[:20]]}")
        return

    # 5. Generera embeddings och upserta i chunkar
    if to_embed:
        from fastembed import SparseTextEmbedding

        total_chunks = (len(to_embed) - 1) // SYNC_CHUNK_SIZE + 1
        print(f"Steg 4-5/5: Embed + upsert i {total_chunks} chunkar à {SYNC_CHUNK_SIZE} poster...")

        bm25_model = SparseTextEmbedding(model_name="Qdrant/bm25")
        total_upserted = 0

        for chunk_start in range(0, len(to_embed), SYNC_CHUNK_SIZE):
            chunk = to_embed[chunk_start:chunk_start + SYNC_CHUNK_SIZE]
            chunk_num = chunk_start // SYNC_CHUNK_SIZE + 1
            texts = [rec["embedding_text"] for rec, _ in chunk]

            # Dense embeddings (OpenAI API, batchas internt i 100-grupper)
            dense_vectors = embed_dense(texts, config["OPENAI_API_KEY"], verbose)

            # Sparse embeddings (lokal BM25, återanvänd modell)
            sparse_vectors = embed_sparse(texts, bm25_model, verbose)

            # Bygg punkter och upserta direkt
            points = []
            for i, (record, hash_val) in enumerate(chunk):
                points.append(
                    PointStruct(
                        id=biblio_id_to_uuid(record["biblio_id"]),
                        vector={
                            "dense": dense_vectors[i],
                            "sparse": sparse_vectors[i],
                        },
                        payload=build_payload(record, hash_val),
                    )
                )

            for j in range(0, len(points), QDRANT_UPSERT_BATCH_SIZE):
                client.upsert(
                    collection_name=COLLECTION_NAME,
                    points=points[j:j + QDRANT_UPSERT_BATCH_SIZE],
                )

            total_upserted += len(chunk)
            print(f"  Chunk {chunk_num}/{total_chunks}: {len(chunk)} poster ({total_upserted}/{len(to_embed)} totalt)")

        print(f"  Upsertade {total_upserted} punkter")

    # Radera borttagna (hoppa över vid --limit, ofullständig dataset)
    if to_delete:
        if limit:
            print(f"\n  Hoppar över delete ({len(to_delete)} poster, --limit={limit} ger ofullständig dataset)")
        else:
            print(f"\nRaderar {len(to_delete)} inaktiva poster...")
            point_ids = [pid for _, pid in to_delete]
            client.delete(
                collection_name=COLLECTION_NAME,
                points_selector=point_ids,
            )
            if verbose:
                deleted_bids = [bid for bid, _ in to_delete[:10]]
                print(f"  Raderade biblio_id: {deleted_bids}{'...' if len(to_delete) > 10 else ''}")

    # Statistik
    actually_deleted = len(to_delete) if (to_delete and not limit) else 0
    duration = time.time() - start_time
    print()
    print("=" * 60)
    print("  KLAR")
    print("=" * 60)
    print(f"  Upsertade:  {len(to_embed)}")
    print(f"  Raderade:   {actually_deleted}")
    print(f"  Oförändrade:{unchanged}")
    print(f"  Tid:        {duration:.1f}s")
    print("=" * 60)
    print()


# ─── CLI ──────────────────────────────────────────────────────────────


def main():
    parser = argparse.ArgumentParser(
        description="Synka biblioteksdata från Directus till Qdrant med hybrid search"
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Visa ändringar utan att synka",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="Omgenerera alla embeddings oavsett hash",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Max antal biblios att synka",
    )
    parser.add_argument(
        "--verbose", "-v",
        action="store_true",
        help="Detaljerad loggning",
    )

    args = parser.parse_args()

    try:
        sync(
            force=args.force,
            limit=args.limit,
            dry_run=args.dry_run,
            verbose=args.verbose,
        )
    except KeyboardInterrupt:
        print("\nAvbrutet av användare.")
        sys.exit(1)
    except Exception as e:
        print(f"\nFel: {e}", file=sys.stderr)
        if os.getenv("DEBUG"):
            import traceback
            traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
