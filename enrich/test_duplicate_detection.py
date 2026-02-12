#!/usr/bin/env python3
"""Test that duplicate error detection works correctly."""

# Simulate the actual error response from Directus
error_response = {
    "errors": [
        {
            "message": "Value for field \"koha_enriched_biblio_id\" in collection \"kft\" has to be unique.",
            "extensions": {
                "collection": "kft",
                "field": "koha_enriched_biblio_id",
                "primaryKey": False,
                "code": "RECORD_NOT_UNIQUE"
            }
        }
    ]
}

# Test the detection logic from enrich_smart.py
errors = error_response['errors']
is_duplicate = False

for error in errors:
    error_str = str(error)
    # Check for duplicate/unique in message or RECORD_NOT_UNIQUE code
    if ('duplicate' in error_str.lower() or
        'unique' in error_str.lower() or
        'RECORD_NOT_UNIQUE' in error_str):
        is_duplicate = True
        break

print(f"Error: {errors[0]}")
print(f"\nDetected as duplicate: {is_duplicate}")
print(f"✅ SUCCESS - Duplicate detection works!" if is_duplicate else "❌ FAILED - Not detected as duplicate")
