# Enrichment Pipeline Fixes - Summary

This document summarizes the two critical fixes applied to the enrichment pipeline on 2026-02-12.

## Issue #1: Duplicate Key Errors Not Handled Gracefully

### Problem
When attempting to enrich a book that was already in the `kft_koha_enriched` collection, the pipeline would fail with:
```
400 Bad Request
Error code: RECORD_NOT_UNIQUE
```

The book would count as a failure even though it was already successfully enriched.

**Example:** biblio_id 66738 ("Roliga rumpor i Ruts värld") failed with this error.

### Root Cause
- Directus returns HTTP 400 (Bad Request) for duplicate key violations
- The error response includes code `RECORD_NOT_UNIQUE`
- The script didn't detect or handle this specific error type
- Books that were already enriched were counted as failures

### Solution Implemented

**Modified `enrich_smart.py`:**
- Changed `DirectusClient.save_enriched_book()` return type from `bool` to `tuple[bool, str]`
- Added intelligent error parsing to detect duplicate key errors:
  - Checks for `RECORD_NOT_UNIQUE` error code
  - Checks for "duplicate" or "unique" keywords in error messages
- Duplicate errors now trigger a friendly warning: `⚠ Already enriched (skipping)`
- Books already in database count toward `enriched_count` (no wasted API costs)

**Code Changes:**
```python
# Before
def save_enriched_book(self, enriched_data: dict) -> bool:
    # ...
    return True  # or False on error

# After
def save_enriched_book(self, enriched_data: dict) -> tuple[bool, str]:
    # ...
    return True, ""  # or (False, "DUPLICATE") for duplicates
```

**Caller handling:**
```python
success, error_msg = directus.save_enriched_book(enriched_data)
if success:
    print(f"      ✓ Saved to Directus")
    enriched_count += 1
elif error_msg == "DUPLICATE":
    print(f"      ⚠ Already enriched (skipping)")
    enriched_count += 1  # Still count as success
```

### Testing Scripts Created
- `check_existing.py` - Check if a biblio_id is already enriched
- `debug_save.py` - Test Directus API saves with detailed error logging
- `test_duplicate_detection.py` - Unit test for duplicate error detection ✅

**Commit:** `3ecb123` - "Handle duplicate key errors gracefully in enrich_smart.py"

---

## Issue #2: ISBN Field Length Too Short

### Problem
When attempting to save books with ISBNs longer than 20 characters, the pipeline would fail with:
```
Value for field "isbn_clean" in collection "kft_koha_enriched" is too long.
Error code: VALUE_TOO_LONG
```

**Example:** biblio_id 66496 ("The manual of strategic planning for museums")
- ISBN value: `9780759109681(cloth:alk.paper)` (30 characters)
- Field limit: VARCHAR(20)
- Result: Save failed

### Root Cause
- The `isbn_clean` field in Directus was set to VARCHAR(20)
- Many ISBNs include additional format information: `9780759109681(cloth:alk.paper)`
- These extended ISBNs can be 30+ characters long
- The field length was insufficient for real-world data

### Solution Implemented

**Updated Directus Field Schema:**
- Increased `isbn_clean` max_length from 20 to 50 characters
- Updated field note to reflect new limit
- Used Directus API to modify field schema programmatically

**Field Configuration:**
```json
{
  "schema": {
    "max_length": 50  // Changed from 20
  },
  "meta": {
    "note": "Cleaned ISBN without dashes (max 50 chars)"
  }
}
```

### Testing Scripts Created
- `check_biblio_isbn.py` - Check ISBN values from Koha API
- `check_directus_biblio.py` - Check ISBN values from Directus (confirmed 30-char ISBN)
- `check_field_schema.py` - Inspect Directus field schema (found VARCHAR(20) limit)
- `fix_isbn_field_length.py` - Update field max_length via Directus API ✅
- `test_save_66496.py` - Test saving book with 30-char ISBN ✅

**Commit:** `e7bae93` - "Fix isbn_clean field length limit in Directus"

---

## Verification

Both fixes have been tested and verified:

✅ **Test Run with 3 Books:**
```
[1/3] Enriching biblio_id 65878 → ✓ Saved to Directus
[2/3] Enriching biblio_id 62916 → ✓ Saved to Directus
[3/3] Enriching biblio_id 62915 → ✓ Saved to Directus

✓ Successfully enriched: 3
💰 Total cost: $0.000322 USD
```

✅ **Duplicate Handling:** Tested with debug_save.py - correctly detects RECORD_NOT_UNIQUE

✅ **ISBN Length Fix:** Successfully saved biblio_id 66496 with 30-character ISBN

---

## Impact

**Before Fixes:**
- ❌ Duplicate entries caused pipeline failures
- ❌ Long ISBNs caused save errors
- ❌ Wasted API costs on books that failed to save
- ❌ Manual intervention required to continue enrichment

**After Fixes:**
- ✅ Duplicate entries handled gracefully (skip with warning)
- ✅ ISBNs up to 50 characters supported
- ✅ API costs properly tracked even for duplicates
- ✅ Pipeline continues automatically without manual intervention

---

## Recommendations

1. **Monitor ISBN Lengths:** If ISBNs longer than 50 characters appear, increase field limit again
2. **Consider ISBN Normalization:** Strip format info like "(cloth:alk.paper)" if not needed
3. **Add Logging:** Consider adding detailed logging to track which books are skipped as duplicates
4. **Batch Statistics:** Track skip reasons (duplicate, error, etc.) in pipeline summary

---

## Related Documentation

- `enrich/README.md` - Full enrichment pipeline documentation
- `docs/GEMINI_ENRICHMENT.md` - Gemini API integration guide
- `docs/ENRICHED_DATA_SETUP.md` - Directus collection setup guide
- `CLAUDE.md` - Project overview

---

**Date:** 2026-02-12
**Author:** Claude Sonnet 4.5
**Status:** ✅ Complete and Tested
