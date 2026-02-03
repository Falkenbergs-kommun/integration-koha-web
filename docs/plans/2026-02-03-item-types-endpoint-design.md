# Item Types Endpoint Design

**Date:** 2026-02-03
**Status:** Approved

## Overview

Create a new endpoint `item-types.php` that lists all available item types from the Koha API. This endpoint provides medium-detail information about each item type without caching, ensuring always-fresh data.

## Requirements

- Endpoint URL: `/item-types.php`
- Format: JSON only
- Caching: None (always fetch fresh data)
- Filtering: Return all item types (including hidden ones)
- Fields: Medium-level detail (6 fields per item type)

## Architecture and Data Flow

The endpoint follows the same pattern as existing endpoints (list.php, book.php) but with simplified logic since no caching is used.

**Data Flow:**
1. Client makes GET request to `/item-types.php`
2. Load .env configuration via `loadEnv()`
3. Fetch OAuth token via `getOAuthToken()` (same as other endpoints)
4. Call Koha API's `/item_types` endpoint
5. Parse JSON response and extract relevant fields
6. Return formatted JSON directly to client (no cache)

**API Call:**
According to the OpenAPI documentation, the endpoint is at `/item_types` (GET) which returns an array of item_type objects. We need to make a simple GET request with Bearer token authentication.

**Advantages of No-Cache:**
- Guaranteed fresh data
- No disk I/O or cache invalidation
- Simpler code without cache logic
- Item types change rarely, so API performance is acceptable

## Implementation Details

### New Function in common.php

Create a new function `getItemTypesFromApi($apiBaseUrl, $apiToken)` that:
- Makes GET request to `{$apiBaseUrl}/item_types`
- Uses cURL with Bearer token authentication
- Parses JSON response
- Extracts the 6 selected fields per item type
- Returns an array with structured data

### Field Mapping from API

Extract these 6 fields from each item_type object:

```php
[
    'item_type_id' => $item->item_type_id,      // string, unique identifier
    'description' => $item->description,         // string, display name
    'parent_type' => $item->parent_type ?? null, // string|null, parent category
    'image_url' => $item->image_url ?? null,     // string|null, icon URL
    'searchcategory' => $item->searchcategory ?? null, // string|null, grouping
    'hide_in_opac' => $item->hide_in_opac ?? false    // boolean, visibility flag
]
```

### Response Structure

```json
{
    "status": "ok",
    "timestamp": "2026-02-03 14:30:45",
    "count": 15,
    "item_types": [
        {
            "item_type_id": "BK",
            "description": "Books",
            "parent_type": null,
            "image_url": "/images/itemtypes/book.png",
            "searchcategory": "print",
            "hide_in_opac": false
        },
        ...
    ]
}
```

Include `count` field so consumers can quickly see how many item types exist without counting the array.

## Error Handling

### Error Scenarios and HTTP Status Codes

1. **OAuth Error (500):** If `getOAuthToken()` returns false
   ```json
   {"status": "error", "message": "Kunde inte hämta OAuth-token"}
   ```

2. **API Call Error (500):** If cURL call to `/item_types` fails
   ```json
   {
       "status": "error",
       "message": "Kunde inte hämta item types från API",
       "http_code": 404
   }
   ```

3. **JSON Parse Error (500):** If API response is not valid JSON
   ```json
   {"status": "error", "message": "Kunde inte parsa API-respons"}
   ```

### Response Headers

- `Content-Type: application/json; charset=utf-8`
- `Access-Control-Allow-Origin: *` (CORS support like other endpoints)
- `Cache-Control: no-cache, must-revalidate` (no browser caching)

### Robustness

- Use 10-second timeout for API calls (consistent with other endpoints)
- Handle all nullable fields with null-coalescing operator (`??`) to avoid undefined index warnings
- Disable SSL verification (`CURLOPT_SSL_VERIFYPEER = false`) consistent with existing code

## Files to Create/Modify

1. **Create:** `/item-types.php` - Main endpoint file
2. **Modify:** `/common.php` - Add `getItemTypesFromApi()` function

## Testing

Manual testing commands:
```bash
# Test the endpoint
curl http://localhost/bibliotek/item-types.php

# Verify JSON structure
curl http://localhost/bibliotek/item-types.php | jq '.'

# Check count matches array length
curl http://localhost/bibliotek/item-types.php | jq '.count, (.item_types | length)'
```

## Future Enhancements (Out of Scope)

- Query parameters for filtering (e.g., `?hide_in_opac=false`)
- Sorting options (e.g., `?sort=description`)
- XML format support (via `?format=xml`)
- Caching with configurable TTL
