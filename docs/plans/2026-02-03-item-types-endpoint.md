# Item Types Endpoint Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a new REST endpoint that lists all available item types from the Koha API with medium-detail information.

**Architecture:** Simple JSON-only endpoint following existing patterns (list.php, book.php). No caching - always fetch fresh data. OAuth authentication via existing helper functions.

**Tech Stack:** PHP 7.4+, cURL for HTTP requests, Koha REST API

---

## Task 1: Add getItemTypesFromApi() function to common.php

**Files:**
- Modify: `/home/httpd/fbg-intranet/integrationer/integration-koha-web/common.php` (add function at end of file)

**Step 1: Add the API function**

Add this function at the end of common.php (before the closing `?>` if it exists):

```php
// Funktion för att hämta item types från API
function getItemTypesFromApi($apiBaseUrl, $apiToken) {
    $ch = curl_init();

    // Bygg URL - API-endpoint för item_types
    $url = rtrim($apiBaseUrl, '/') . '/item_types';

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Returnera strukturerad respons med felhantering
    if ($httpCode !== 200 || !$response) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $error ?: 'API returnerade inte status 200',
            'data' => []
        ];
    }

    // Parsa JSON-respons
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'Kunde inte parsa JSON: ' . json_last_error_msg(),
            'data' => []
        ];
    }

    // Extrahera relevanta fält från varje item type
    $itemTypes = [];
    foreach ($data as $item) {
        $itemTypes[] = [
            'item_type_id' => $item['item_type_id'] ?? null,
            'description' => $item['description'] ?? null,
            'parent_type' => $item['parent_type'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'searchcategory' => $item['searchcategory'] ?? null,
            'hide_in_opac' => $item['hide_in_opac'] ?? false
        ];
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'error' => null,
        'data' => $itemTypes
    ];
}
```

**Step 2: Verify syntax**

Run: `php -l /home/httpd/fbg-intranet/integrationer/integration-koha-web/common.php`

Expected: "No syntax errors detected in..."

**Step 3: Commit**

```bash
git add common.php
git commit -m "Add getItemTypesFromApi() function for fetching item types from Koha API

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Task 2: Create item-types.php endpoint

**Files:**
- Create: `/home/httpd/fbg-intranet/integrationer/integration-koha-web/item-types.php`

**Step 1: Create the endpoint file**

Create item-types.php with this content:

```php
<?php
// Endpoint för att lista alla item types från Koha API
require_once __DIR__ . '/common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/.env');

// Sätt headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Hämta konfiguration från .env
$apiBaseUrl = getenv('API_BASE_URL');
$oauthUrl = getenv('OAUTH_URL');
$clientId = getenv('CLIENT_ID');
$clientSecret = getenv('CLIENT_SECRET');

// Hämta OAuth-token
$apiToken = getOAuthToken($oauthUrl, $clientId, $clientSecret);
if (!$apiToken) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta OAuth-token'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Hämta item types från API
$result = getItemTypesFromApi($apiBaseUrl, $apiToken);

// Kontrollera om hämtningen lyckades
if (!$result['success']) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta item types från API',
        'http_code' => $result['http_code'],
        'error' => $result['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bygg framgångsrik respons
$response = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'count' => count($result['data']),
    'item_types' => $result['data']
];

// Returnera JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
```

**Step 2: Verify syntax**

Run: `php -l /home/httpd/fbg-intranet/integrationer/integration-koha-web/item-types.php`

Expected: "No syntax errors detected in..."

**Step 3: Commit**

```bash
git add item-types.php
git commit -m "Add item-types.php endpoint for listing all item types

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Task 3: Manual testing

**Files:**
- None (testing only)

**Step 1: Test endpoint returns data**

Run: `curl -s http://localhost/bibliotek/item-types.php | head -20`

Expected: JSON response starting with:
```json
{
    "status": "ok",
    "timestamp": "2026-02-03 ...",
    "count": ...
```

**Step 2: Verify JSON is valid**

Run: `curl -s http://localhost/bibliotek/item-types.php | jq '.status'`

Expected: `"ok"` (or error message if OAuth/API fails)

**Step 3: Check count matches array length**

Run: `curl -s http://localhost/bibliotek/item-types.php | jq '.count, (.item_types | length)'`

Expected: Both numbers should be identical

**Step 4: Inspect a single item type**

Run: `curl -s http://localhost/bibliotek/item-types.php | jq '.item_types[0]'`

Expected: Object with 6 fields:
```json
{
  "item_type_id": "...",
  "description": "...",
  "parent_type": ...,
  "image_url": ...,
  "searchcategory": ...,
  "hide_in_opac": ...
}
```

**Step 5: Document test results**

Create a simple test log showing the endpoint works. No commit needed - this is verification only.

---

## Task 4: Update CLAUDE.md with endpoint documentation

**Files:**
- Modify: `/home/httpd/fbg-intranet/integrationer/integration-koha-web/CLAUDE.md`

**Step 1: Add endpoint to documentation**

Find the "Core Components" section and add:

```markdown
- **item-types.php**: Item types listing endpoint - fetches all available item types from Koha API with no caching
```

Find the "Key Functions (common.php)" section and add:

```markdown
- `getItemTypesFromApi($apiBaseUrl, $apiToken)` - Fetch all item types with 6 metadata fields (id, description, parent, image, category, visibility)
```

**Step 2: Verify documentation is clear**

Run: `cat /home/httpd/fbg-intranet/integrationer/integration-koha-web/CLAUDE.md | grep -A2 "item-types.php"`

Expected: Shows the new endpoint documentation

**Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "Document item-types endpoint in CLAUDE.md

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Implementation Notes

### DRY Principles
- Reuse existing `getOAuthToken()` and error handling patterns
- Follow same header structure as list.php and book.php
- Use consistent JSON encoding with `JSON_UNESCAPED_UNICODE`

### YAGNI Principles
- No caching (not needed for rarely-changing data)
- No XML format (JSON-only is sufficient)
- No filtering parameters (client can filter the full list)
- No sorting (client can sort the array)

### Error Handling
All errors return 500 status with structured JSON:
```json
{"status": "error", "message": "...", ...}
```

### Testing Strategy
Manual testing only (no automated tests in this codebase). Focus on:
1. Syntax validation (`php -l`)
2. Live endpoint testing with curl
3. JSON structure verification with jq

---

## Success Criteria

- [ ] `common.php` has `getItemTypesFromApi()` function
- [ ] `item-types.php` endpoint exists and returns valid JSON
- [ ] Endpoint returns all 6 fields per item type
- [ ] Count field matches array length
- [ ] No caching implemented (always fresh data)
- [ ] CLAUDE.md documents the new endpoint
- [ ] All commits follow project conventions
