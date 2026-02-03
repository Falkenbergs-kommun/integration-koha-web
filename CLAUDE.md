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
- `API_BASE_URL` - Base URL for book metadata API (biblionumber appended)
- `OAUTH_URL` - OAuth token endpoint URL
- `CLIENT_ID` - OAuth client ID
- `CLIENT_SECRET` - OAuth client secret

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

## Code Style Conventions
- Swedish comments and variable names (bibliotek domain language)
- Functions return structured arrays, not objects
- Error responses use proper HTTP status codes (400, 500) with JSON error messages
- All JSON output uses `JSON_UNESCAPED_UNICODE` to preserve Swedish characters
