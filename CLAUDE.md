# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based RSS-to-JSON converter for Falkenbergs bibliotek (Falkenberg Library). It fetches RSS feeds from the library's Koha system, enriches book data via an external API, downloads and caches book cover images from Syndetics, and serves the data as JSON with CORS support.

## Architecture

### Core Components

- **index.php**: Single-list endpoint (hardcoded to list 247) - fetches RSS, enriches with API data, caches result
- **list.php**: Dynamic multi-list endpoint - accepts `?id=XXX` parameter to fetch any library list
- **latest.php**: Latest books endpoint - fetches newest biblios, supports optional `?item_type_id=TYPE1,TYPE2` filtering
- **item-types.php**: Item types listing endpoint - fetches all available item types from Koha API with no caching
- **common.php**: Shared utilities library containing all reusable functions
- **debug.php**: Development tool for inspecting raw RSS feed responses and debugging XML parsing issues

### Data Flow

1. Client requests JSON endpoint (index.php or list.php)
2. Check file-based JSON cache (1 hour TTL) - return immediately if valid
3. Fetch RSS feed from bibliotekskatalog.falkenberg.se
4. Parse XML and extract biblionumber from each item
5. Authenticate with external API via OAuth 2.0 client credentials
6. Fetch detailed book metadata for each biblionumber from API
7. Extract ISBN, generate Syndetics image URL, download and cache book covers locally
8. Build comprehensive JSON response with RSS + API + image data
9. Write to JSON cache file and return to client

### Key Functions (common.php)

- `loadEnv($filePath)` - Simple .env parser without external dependencies
- `getOAuthToken($oauthUrl, $clientId, $clientSecret)` - OAuth 2.0 client credentials flow
- `getBookDataFromApi($biblioId, $apiBaseUrl, $apiToken)` - Fetch 20+ metadata fields per book
- `getItemTypesFromApi($apiBaseUrl, $apiToken)` - Fetch all item types with 6 metadata fields (id, description, parent, image, category, visibility)
- `getFilteredBibliosFromItems($apiBaseUrl, $apiToken, $itemTypes, $limit)` - Fetch biblios filtered by item_type_id using items endpoint with biblio embedding
- `extractBiblioId($url)` - Parse biblionumber from Koha URLs using regex
- `getFirstIsbn($isbnString)` - Extract first ISBN from pipe/comma-separated lists
- `getImageUrl($isbn)` - Build Syndetics image URL from ISBN
- `cacheImage($isbn, $syndeticsUrl)` - Download and locally cache book covers to images/ directory
- `fetchRssFeed($rssUrl)` - Robust RSS fetching with cookies and user agent
- `processRssFeed($xml, $apiBaseUrl, $apiToken)` - Main orchestration function combining all data sources

## Environment Configuration

Required `.env` variables:

**Koha API:**
- `API_BASE_URL` - Base URL for book metadata API (biblionumber appended)
- `OAUTH_URL` - OAuth token endpoint URL
- `CLIENT_ID` - OAuth client ID
- `CLIENT_SECRET` - OAuth client secret

**Directus (for enriched metadata storage):**
- `DIRECTUS_API_URL` - Directus instance URL (e.g., https://nav.utvecklingfalkenberg.se)
- `DIRECTUS_API_TOKEN` - Directus API token with read/write access to kft_koha_enriched collection

**Google Gemini AI (for metadata enrichment):**
- `GEMINI_API_KEY` - Google Gemini API key from https://aistudio.google.com/app/apikey

**Qdrant Vector Database (for hybrid search):**
- `QDRANT_URL` - Qdrant instance URL (e.g., https://qdrant.utvecklingfalkenberg.se)
- `QDRANT_API_KEY` - Qdrant API key for authentication

**OpenAI (for embeddings):**
- `OPENAI_API_KEY` - OpenAI API key for text-embedding-3-large

See `.env.example` for template and `docs/GEMINI_ENRICHMENT.md` for detailed setup guide.

## Caching Strategy

- **JSON response cache**: `cache.json` (index.php), `cache_list_{id}.json` (list.php), or `cache_latest_{limit}_{format}_{itemtypes}.cache` (latest.php) - 1 hour TTL
- **Image cache**: `images/{isbn}.jpg` - persistent, never expires
- **Cache invalidation**: Automatic after 1 hour via filemtime check
- **Filter-based caching**: Each unique item_type_id combination gets separate cache file (e.g., `cache_latest_10_json_BARNBOK_BARNDVD.cache`)

## Development Commands

Since this is a simple PHP project without a build system, development is straightforward:

```bash
# Run on PHP's built-in server (development only)
php -S localhost:8000

# Test single list endpoint
curl http://localhost/bibliotek/index.php

# Test dynamic list endpoint
curl http://localhost/bibliotek/list.php?id=247

# Debug RSS feed issues
curl http://localhost/bibliotek/debug.php
```

## Important Implementation Notes

### Security Considerations
- SSL verification is disabled (`CURLOPT_SSL_VERIFYPEER = false`) throughout - acceptable for internal library systems but should be reviewed for production
- No input sanitization beyond numeric validation on list ID - risk is minimal given controlled data sources
- OAuth credentials stored in .env file (properly gitignored)

### API Response Structure
The final JSON includes 20+ metadata fields per book including ISBN, title, author, abstract, subtitle, publisher, publication year/place, pages, material size, edition, series, age restriction, URL, EAN, and notes. Always maintain this comprehensive structure when modifying data processing.

### RSS Feed Quirks
- Requires `KOHA_INIT=1` cookie to bypass library system login redirects
- May return JavaScript redirects instead of XML on auth failures
- debug.php is essential for diagnosing feed issues

### Image Caching
- Images are checked before download (file_exists)
- Content-Type validation ensures real images vs error pages
- Failed downloads return null, not errors
- images/ directory is auto-created with 0755 permissions

## Koha → Directus Sync (directus/)

### Sync Scripts

- **`sync_koha_to_directus.php`** – Daglig cron-synk (körs via `sync_cron.sh` kl 03:00). Hämtar alla biblios från Koha API, synkar mot `kft_koha_biblios` i Directus med soft-delete-strategi.
- **`sync_koha_branches.php`** – Synkar ~11 filialer från Koha `/libraries` till `kft_koha_branches`. Snabb, ingen paginering.
- **`sync_koha_items.php`** – Synkar ~155k exemplar från Koha `/items` till `kft_koha_items`. Streaming-arkitektur med cursor-paginering (`q={"item_id":{">":<id>}}`), automatisk OAuth-tokenförnyelse var 45:e minut, och fallback vid korrupta poster. Stöder `--start-from=N` och `--verbose`.
- **`DirectusClient.php`** – PHP-klient för Directus REST API (CRUD + bulk-create/delete). Exponerar `getBaseUrl()` och `getToken()` för custom-queries utanför standard-CRUD.
- **`cleanup_duplicates.php`** – Rensar dubbletter i `kft_koha_biblios`. Kör med `--dry-run` för förhandsvisning.
- **`sync_cron.sh`** – Kör alla syncar i sekvens: Branches → Biblios → Items → Holds → Qdrant vectors (dagligen kl 03:00). Skickar start/success/fail-ping till healthchecks.io med per-steg-payload (nyckeltal som created/updated/errors). Konfigureras via `HEALTHCHECK_SYNC_ID` i `.env`.
- **`prepare_embedding_text.php`** – Aggregerar data från alla 4 Directus-kollektioner och bygger strukturerad embedding-text + metadata per biblio. Stöder `--output=json|jsonl|csv`, `--limit=N`, `--biblio-id=N`.

### Synklogik (sync_koha_to_directus.php)

1. Hämtar alla Koha-biblios via paginerad API (500/sida, sortering `-biblio_id`)
2. Hämtar alla Directus-poster via paginerad offset (`sort=id ASC` – kritiskt för stabilitet)
3. Bygger lookup-map `biblio_id → Directus-id`; dubbletter samlas för radering
4. Uppdaterar befintliga / skapar nya poster
5. Raderar ev. dubbletter (steg 4b)
6. Markerar poster som `inactive` om biblio_id försvunnit från Koha

### Viktiga designbeslut

- **Koha API har korrupta poster** – Vissa items har ogiltiga datumfält (`replacement_price_date` med "Month out of range") som ger HTTP 500. `sync_koha_items.php` hanterar detta med fallback: 500 → 10 → hoppa förbi.
- **OAuth-tokens löper ut efter ~1h** – Items-synken tar ~2.5h, så token förnyas automatiskt var 45:e minut. Koha returnerar tom array (inte felkod) vid utgången token – tyst fel.
- **`--start-from` hoppar över soft-delete** – Vid partiell sync kan man inte avgöra vilka poster som saknas i källan, så inactive-markering skippas.
- **`safeTruncate()` i `common.php`** – Delad multibyte-safe trunkering, används av alla sync-scripts.
- **`image_cached` och `image_cached_url` skrivs INTE av synken** – dessa fält ägs av webbendpointsen (`common.php`, `latest.php`) som cachar bilder lokalt. Synken skriver dem aldrig, annars nollas cachade bilder varje natt.
- **Soft delete** – poster som försvinner från Koha markeras `status=inactive`, raderas inte.
- **`sort=id` i Directus-paginering** – utan explicit sortering är offset-pagination instabil och kan missa poster vid stora kataloger.

### Felsökning dubbletter

```bash
# Kontrollera om dubbletter finns
php directus/cleanup_duplicates.php --dry-run

# Rensa dubbletter
php directus/cleanup_duplicates.php

# Kontrollera antal poster via API
curl -H "Authorization: Bearer TOKEN" \
  "https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios?aggregate[count]=id"
```

## AI Enrichment & Directus Integration

### Enriched Metadata Collection

The project includes AI-powered enrichment of bibliographic metadata using Google Gemini API. Enriched data is stored in Directus collection `kft_koha_enriched`:

**Enriched fields:**
- `abstract_enriched` - AI-generated enhanced book descriptions (Swedish)
- `subjects` - Structured subject categories (JSON array)
- `tags` - Searchable tags for discovery (JSON array)
- `target_audience` - Automatically identified audience (Gymnasiet, Allmänheten, etc.)
- `grounding_sources` - Sources and search queries used by AI (for transparency)
- `enrichment_cost_usd` - Cost per enrichment in USD (for tracking)

### Automated Enrichment Pipeline

**Python enrichment script (enrich/enrich_from_directus.py):**
- Fetches books without abstracts from `kft_koha_biblios`
- Enriches with Google Gemini API + Google Search grounding
- Saves directly to `kft_koha_enriched` via Directus API
- Tracks and saves API costs per enrichment
- Automatically excludes already enriched books

**Usage:**
```bash
cd enrich/
uv run enrich_from_directus.py --limit 10        # Enrich 10 books
uv run enrich_from_directus.py --dry-run        # Test without saving
uv run enrich_from_directus.py --model gemini-1.5-pro  # Use Pro model
```

**Cost tracking:**
- Typical cost: ~$0.00015 per book (0.015 öre)
- Costs calculated from actual token usage
- Saved to database for transparency
- See `enrich/COST_TRACKING.md` for details

### Setup & Troubleshooting

**Initial setup scripts (setup/ directory):**
- `setup-directus-collection.php` - Create collection via API
- `import-enriched-data.php` - Import enriched data to Directus
- `directus-create-enriched-collection.sql` - SQL DDL for manual setup
- `fix-*.php` - Troubleshooting scripts for field types and interfaces
- See `setup/README.md` for detailed usage

**Documentation (docs/ directory):**
- `ENRICHED_DATA_SETUP.md` - Full setup guide for Directus collection
- `QUICK_START_ENRICHED.md` - Quick start guide with API examples
- `GEMINI_ENRICHMENT.md` - Google Gemini API integration guide
- `DIRECTUS_GUI_FIX_TUTORIAL.md` - Troubleshooting JSON field issues
- `INTERFACE_EXPLAINED.md` - Understanding Directus interface types

**Directus API endpoints:**
```bash
# Get enriched data for a biblio
GET /items/kft_koha_enriched?filter[biblio_id][_eq]=71069

# Search in enriched abstracts
GET /items/kft_koha_enriched?search=demokrati

# Filter by target audience
GET /items/kft_koha_enriched?filter[target_audience][_eq]=Gymnasiet

# Get total enrichment costs
GET /items/kft_koha_enriched?aggregate[sum]=enrichment_cost_usd
```

The enriched data can be integrated into existing endpoints (index.php, list.php, latest.php) by fetching from Directus API and merging with Koha metadata.

## Qdrant Hybrid Search (qdrant/)

### Overview

The project includes a vector search pipeline that enables hybrid search (semantic + keyword) over the entire library catalog (~68k books). Data flows from Directus through PHP aggregation into Qdrant via Python embedding.

### Collection: `koha-biblios`

- **Dense vectors**: OpenAI `text-embedding-3-large` (3072 dims, cosine distance) — semantic search
- **Sparse vectors**: BM25 via `fastembed` with `Qdrant/bm25` model (`Modifier.IDF`) — keyword matching
- **Fusion**: Reciprocal Rank Fusion (RRF) combines both rankings at query time
- **Payload indexes**: 12 filterable fields (biblio_id, title, author, isbn, publication_year, publisher, media_types, target_audience, subjects, tags, branches, series_title)

### Sync Script

**`qdrant/sync_to_qdrant.py`** – Python script (uv) that:
1. Calls `prepare_embedding_text.php --output=jsonl` via subprocess to get aggregated data
2. Compares SHA256 content hashes with existing Qdrant state (incremental sync)
3. Generates dense embeddings (OpenAI API) + sparse embeddings (local BM25) for changed records
4. Upserts to Qdrant with deterministic UUIDs (`uuid5("koha-biblio-{biblio_id}")`)
5. Deletes entries for biblios no longer active in Directus

**Usage:**
```bash
cd qdrant/
uv run sync_to_qdrant.py                    # Full sync
uv run sync_to_qdrant.py --dry-run          # Preview changes
uv run sync_to_qdrant.py --force            # Re-embed all
uv run sync_to_qdrant.py --limit=50 -v      # Test with 50 books
```

### Viktiga designbeslut

- **PHP→Python bridge**: `prepare_embedding_text.php` är single source of truth för embedding-text och metadata. Python anropar den via subprocess och parsar JSONL.
- **Inkrementell sync**: SHA256 av embedding-texten sparas som `content_hash` i Qdrant payload. Bara ändrade poster omgenereras (~$0.01-0.03/dag vs ~$0.73 för full sync).
- **IPv4-workaround**: Qdrant-servern har AAAA-records men lyssnar inte på IPv6. Scriptet tvingar IPv4 via socket monkey-patch (samma som crawler-projektet).
- **BM25 sparse vectors**: Genereras lokalt (ingen API-kostnad). IDF-viktning beräknas server-side av Qdrant.
- **Cron**: Körs som steg 5 i `sync_cron.sh` (efter Branches → Biblios → Items → Holds).

### Qdrant API

```bash
# Kontrollera collection
curl -H "api-key: KEY" "https://qdrant.utvecklingfalkenberg.se/collections/koha-biblios"

# Antal punkter
curl -H "api-key: KEY" "https://qdrant.utvecklingfalkenberg.se/collections/koha-biblios" | jq '.result.points_count'
```

## Code Style Conventions
- Swedish comments and variable names (bibliotek domain language)
- Functions return structured arrays, not objects
- Error responses use proper HTTP status codes (400, 500) with JSON error messages
- All JSON output uses `JSON_UNESCAPED_UNICODE` to preserve Swedish characters
