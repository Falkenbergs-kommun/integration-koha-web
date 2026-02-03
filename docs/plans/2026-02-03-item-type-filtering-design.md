# Design Document: Item Type Filtering for latest.php

**Date:** 2026-02-03
**Status:** Implemented
**Endpoint:** latest.php

## Overview

Added optional `item_type_id` parameter to latest.php endpoint to filter biblios by item type (e.g., BARNBOK, BARNDVD).

## Requirements

- Accept comma-separated list of item_type_ids: `?item_type_id=BARNBOK,BARNDVD`
- Return only biblios that have items matching the specified types (OR logic)
- Show each biblio once (deduplicate)
- Sort by biblio_id descending (newest first)
- Include filter in cache key
- Maintain backward compatibility (no filter = original behavior)

## Architecture Decision

**Chosen Approach:** Dual-strategy pattern with items-first when filtering

### Strategy Selection

**When no filter (original behavior):**
```
GET /biblios?_order_by=-biblio_id&_per_page={limit}
→ Returns biblios directly
```

**When filter present:**
```
GET /items?item_type_id[]={type1}&item_type_id[]={type2}&_per_page={limit*5}
Header: x-koha-embed: biblio
→ Returns items with embedded biblio data
→ Deduplicate by biblio_id
→ Sort by biblio_id descending
→ Limit to requested count
```

### Rationale

1. **Avoids N+1 queries** - Single API call instead of fetching all biblios then checking each for items
2. **Leverages Koha API filtering** - Server-side filtering more efficient than client-side
3. **Uses x-koha-embed** - Gets biblio metadata embedded in items response (1 call instead of 2)
4. **Backward compatible** - No filter = original code path unchanged

## Implementation

### New Function: `getFilteredBibliosFromItems()` (common.php)

**Location:** common.php, lines 434-528

**Parameters:**
- `$apiBaseUrl` - Base API URL (points to /biblios/)
- `$apiToken` - OAuth bearer token
- `$itemTypes` - Array of item_type_id values to filter
- `$limit` - Maximum biblios to return

**Returns:** Array of biblio objects (same format as GET /biblios)

**Algorithm:**
1. Construct /items endpoint URL (replace /biblios/ with /items)
2. Build query with item_type_id filters and _per_page={limit*5}
3. Add x-koha-embed: biblio header
4. Make API call
5. Extract embedded biblio data from items
6. Deduplicate using biblio_id as key
7. Sort by biblio_id descending
8. Slice to requested limit

**Over-fetching Factor:** 5x limit to account for multiple items per biblio

### Modified: latest.php

**Changes:**

1. **Parameter parsing (lines 12-20):**
   - Parse item_type_id GET parameter
   - Split by comma, trim, uppercase
   - Sort for consistent cache keys

2. **Cache key generation (lines 36-38):**
   - Include item_type_ids in cache filename
   - Format: `cache_latest_{limit}_{format}_{TYPE1}_{TYPE2}.cache`

3. **Conditional API strategy (lines 58-96):**
   - Check if itemTypeIds is empty
   - If empty: Use original /biblios endpoint
   - If not empty: Call getFilteredBibliosFromItems()

## API Details

### Koha API Endpoints Used

**GET /items**
- Query parameter: `q={"item_type_id":["BOK","DVD"]}` (JSON query format for OR logic)
- Query parameters: `_per_page={count}`, `_order_by=-item_id`
- Header: `x-koha-embed: biblio` (embeds biblio data in response)
- Returns: Array of items with nested biblio object

**Item Schema:**
```json
{
  "item_id": 12345,
  "item_type_id": "BARNBOK",
  "biblio_id": 67890,
  "biblio": {
    "biblio_id": 67890,
    "title": "...",
    "isbn": "...",
    ... (full biblio metadata)
  }
}
```

## Data Flow

### Filtered Request

```
1. Client: GET /latest.php?limit=10&item_type_id=BARNBOK,BARNDVD

2. latest.php:
   - Parse: itemTypeIds = ['BARNBOK', 'BARNDVD']
   - Cache key: cache_latest_10_json_BARNBOK_BARNDVD.cache
   - Cache miss

3. getFilteredBibliosFromItems():
   - Query: /items?item_type_id[]=BARNBOK&item_type_id[]=BARNDVD&_per_page=50
   - Header: x-koha-embed: biblio
   - Receive 50 items (maybe 15 unique biblios)
   - Deduplicate: Extract unique biblios by biblio_id
   - Sort: By biblio_id DESC
   - Limit: Take first 10

4. latest.php:
   - Process via processLatestBooks() (enrich with images)
   - Cache result
   - Return JSON
```

## Cache Strategy

### Cache Key Format

**Unfiltered:** `cache_latest_10_json.cache`

**Filtered:** `cache_latest_10_json_BARNBOK_BARNDVD.cache`

**Key components:**
- `limit` - Number of results
- `format` - json or xml
- `itemTypeIds` - Sorted, underscore-separated

### Cache Isolation

- Each filter combination gets separate cache file
- No cross-contamination between filtered/unfiltered
- Standard 1-hour TTL applies (CACHE_TTL_LATEST)

## Testing

### Manual Test Commands

```bash
# Test 1: Unfiltered (regression test)
curl "http://localhost/bibliotek/latest.php?limit=5"

# Test 2: Single item type
curl "http://localhost/bibliotek/latest.php?limit=5&item_type_id=BARNBOK"

# Test 3: Multiple item types
curl "http://localhost/bibliotek/latest.php?limit=10&item_type_id=BARNBOK,BARNDVD"

# Test 4: Check cache files
ls -lh cache/cache_latest_*

# Test 5: Verify deduplication (all biblio_ids should be unique)
curl -s "http://localhost/bibliotek/latest.php?limit=10&item_type_id=BOK" | jq '.items[].biblio_id' | sort | uniq -d
# Expected: empty output (no duplicates)

# Test 6: Verify sorting (biblio_ids should be descending)
curl -s "http://localhost/bibliotek/latest.php?limit=5&item_type_id=BARNBOK" | jq '.items[].biblio_id'
```

### Expected Behavior

- Empty item_type_id parameter → unfiltered behavior
- Non-existent item_type_id → empty results (not error)
- Multiple types → OR logic (biblio included if ANY item matches)
- Deduplication → each biblio appears once
- Sorting → biblio_id descending

## Performance Considerations

### Over-fetching Trade-off

**Why 5x limit?**
- Multiple items can belong to same biblio
- Example: 50 items might represent only 10 unique biblios
- 5x multiplier provides safety margin

**Worst case:**
- Request limit=10, fetch 50 items
- If all 50 items belong to 3 biblios → return only 3
- Future improvement: Could increase multiplier if needed

### API Call Comparison

**Old approach (if we had used N+1):**
- 1 call to /biblios (get 10 biblios)
- 10 calls to /biblios/{id}/items (check each)
- **Total: 11 API calls**

**New approach:**
- 1 call to /items with embed
- **Total: 1 API call** ✅

## Error Handling

### Scenarios

1. **API returns empty results**
   - Return empty items array
   - Status: ok (not error)
   - Expected when no items match filter

2. **API error (500, timeout)**
   - getFilteredBibliosFromItems() returns []
   - latest.php returns 500 error to client

3. **Invalid item_type_id**
   - No validation (Koha API handles)
   - Returns empty results if type doesn't exist

4. **OAuth failure**
   - Existing error handling applies
   - Returns 500 before reaching filter logic

## Security

### Input Sanitization

```php
// Trim, uppercase, remove empty values
$itemTypeIds = array_filter(
    array_map('trim', array_map('strtoupper', explode(',', $_GET['item_type_id']))),
    function($id) { return !empty($id); }
);
```

**No SQL injection risk:** All filtering done by Koha API

**No additional attack surface:** OAuth token still required

## Future Extensions

### This pattern enables:

1. **Additional filters:**
   - `?location=MAIN` - Filter by item location
   - `?available=true` - Only show available items
   - `?item_type_exclude=DVD` - Exclude certain types

2. **Apply to other endpoints:**
   - list.php - Filter RSS lists by item type
   - shelf.php - Filter shelves by item type

3. **Combine with existing filters:**
   - `?limit=20&format=xml&item_type_id=BARNBOK`

### Reusable Component

`getFilteredBibliosFromItems()` can be called from any endpoint that needs item-based filtering.

## Files Modified

1. **common.php** - Added getFilteredBibliosFromItems() (~95 lines)
2. **latest.php** - Modified parameter parsing, cache key, API strategy (~30 lines changed)

## Backward Compatibility

✅ **Fully backward compatible**

- No filter → Original code path
- Existing cache files unaffected
- Response structure unchanged (same 23 fields per biblio)
- No breaking changes to API contract
