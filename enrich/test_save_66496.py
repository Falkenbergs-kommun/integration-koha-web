#!/usr/bin/env python3
"""Test saving biblio_id 66496 with the updated isbn_clean field length."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

# First, check if it's already enriched
headers = {'Authorization': f'Bearer {DIRECTUS_API_TOKEN}'}
check_url = f"{DIRECTUS_API_URL}/items/kft_koha_enriched"
check_params = {'filter[biblio_id][_eq]': 66496}

response = requests.get(check_url, headers=headers, params=check_params, timeout=30)
existing = response.json().get('data', [])

if existing:
    print(f"✓ Book 66496 is already enriched (ID: {existing[0]['id']})")
    print("Deleting it first to test the save...")
    delete_url = f"{DIRECTUS_API_URL}/items/kft_koha_enriched/{existing[0]['id']}"
    requests.delete(delete_url, headers=headers, timeout=30)
    print("✓ Deleted\n")

# Test data for biblio_id 66496
test_data = {
    'biblio_id': 66496,
    'isbn_clean': '9780759109681(cloth:alk.paper)',  # 30 characters
    'title': 'The manual of strategic planning for museums',
    'abstract_enriched': 'Denna handbok erbjuder en omfattande och praktisk guide för strategisk planering inom museivärlden.',
    'subjects': ['Museiorganisation', 'Strategisk planering', 'Kulturadministration'],
    'tags': ['museer', 'strategisk planering', 'ledarskap', 'verksamhetsutveckling', 'kulturmanagement'],
    'target_audience': 'Högskola och yrkesverksamma inom museisektorn',
    'grounding_search_queries': [],
    'grounding_sources': [],
    'enrichment_cost_usd': 0.000067
}

print("Testing save with 30-character isbn_clean...")
print(f"isbn_clean: '{test_data['isbn_clean']}' ({len(test_data['isbn_clean'])} chars)\n")

headers['Content-Type'] = 'application/json'
save_url = f"{DIRECTUS_API_URL}/items/kft_koha_enriched"

response = requests.post(save_url, headers=headers, json=test_data, timeout=30)

print(f"Response Status: {response.status_code}")

if response.status_code in [200, 201]:
    print("✅ SUCCESS! Book saved successfully.\n")
    result = response.json()
    saved_data = result['data']
    print(f"Saved record ID: {saved_data['id']}")
    print(f"Saved isbn_clean: '{saved_data['isbn_clean']}'")
else:
    print(f"❌ FAILED with status {response.status_code}")
    try:
        error = response.json()
        print(json.dumps(error, indent=2))
    except:
        print(response.text)
