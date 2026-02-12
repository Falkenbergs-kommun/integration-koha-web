#!/usr/bin/env python3
"""
Debug script to test saving enriched data to Directus and see detailed error messages.
"""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

# Load environment
env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

# Test data based on the failing book
test_data = {
    'biblio_id': 66738,
    'isbn_clean': None,  # Might not have ISBN
    'title': 'Roliga rumpor i Ruts värld',
    'abstract_enriched': 'Rut är fem år och ser världen ur ett perspektiv där vuxnas rumpor ofta hamnar i fokus. Denna charmiga barnbok utforskar vardagliga situationer genom ett barns ögon med humor och igenkänning.',
    'subjects': ['Kroppen', 'Barnperspektiv', 'Humor i barnlitteratur'],
    'tags': ['lättläst', 'högläsning', 'lästräning', 'barnbok', 'kroppen'],
    'target_audience': 'Barn (ca 5–8 år)',
    'grounding_search_queries': [],
    'grounding_sources': [],
    'enrichment_cost_usd': 0.000141
}

print("Testing Directus API save...")
print(f"URL: {DIRECTUS_API_URL}/items/kft_koha_enriched")
print(f"\nData to save:")
print(json.dumps(test_data, indent=2, ensure_ascii=False))
print("\n" + "="*60)

headers = {
    'Authorization': f'Bearer {DIRECTUS_API_TOKEN}',
    'Content-Type': 'application/json'
}

try:
    response = requests.post(
        f"{DIRECTUS_API_URL}/items/kft_koha_enriched",
        headers=headers,
        json=test_data,
        timeout=30
    )

    print(f"\nResponse Status: {response.status_code}")
    print(f"Response Headers: {dict(response.headers)}")
    print(f"\nResponse Body:")

    try:
        response_json = response.json()
        print(json.dumps(response_json, indent=2, ensure_ascii=False))
    except:
        print(response.text)

    if response.status_code == 200 or response.status_code == 201:
        print("\n✅ SUCCESS! Data saved successfully.")
    else:
        print(f"\n❌ FAILED with status {response.status_code}")

        # Try to extract specific error
        if response.status_code == 400:
            try:
                error_data = response.json()
                if 'errors' in error_data:
                    print("\nDetailed errors:")
                    for error in error_data['errors']:
                        print(f"  - {error}")
            except:
                pass

except Exception as e:
    print(f"\n❌ Exception: {e}")
    import traceback
    traceback.print_exc()
