#!/usr/bin/env python3
"""Increase isbn_clean field max length from 20 to 50 characters."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

headers = {
    'Authorization': f'Bearer {DIRECTUS_API_TOKEN}',
    'Content-Type': 'application/json'
}

# Update field schema
url = f"{DIRECTUS_API_URL}/fields/kft_koha_enriched/isbn_clean"

update_data = {
    "schema": {
        "max_length": 50
    },
    "meta": {
        "note": "Cleaned ISBN without dashes (max 50 chars)"
    }
}

print("Updating isbn_clean field max_length from 20 to 50...")
print(f"URL: {url}")
print(f"Data: {json.dumps(update_data, indent=2)}\n")

response = requests.patch(url, headers=headers, json=update_data, timeout=30)

print(f"Response Status: {response.status_code}")

if response.status_code == 200:
    print("✅ SUCCESS! Field updated successfully.\n")
    result = response.json()
    schema = result['data']['schema']
    print(f"New max_length: {schema['max_length']} characters")
else:
    print(f"❌ FAILED with status {response.status_code}")
    try:
        print(json.dumps(response.json(), indent=2))
    except:
        print(response.text)
