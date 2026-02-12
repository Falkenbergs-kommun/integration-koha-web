#!/usr/bin/env python3
"""Check if a biblio is already enriched."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

biblio_id = 66738

headers = {'Authorization': f'Bearer {DIRECTUS_API_TOKEN}'}
url = f"{DIRECTUS_API_URL}/items/kft_koha_enriched"
params = {'filter[biblio_id][_eq]': biblio_id}

response = requests.get(url, headers=headers, params=params)
data = response.json()

print(f"Checking biblio_id: {biblio_id}")
print(f"Found records: {len(data.get('data', []))}")

if data.get('data'):
    print(f"\n✅ Book IS already enriched:")
    for record in data['data']:
        print(f"  - ID: {record['id']}, Date: {record.get('date_created')}")
        print(f"  - Title: {record.get('title')}")
else:
    print(f"\n❌ Book is NOT enriched yet")
