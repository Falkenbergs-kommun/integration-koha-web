#!/usr/bin/env python3
"""Check the Directus field schema for isbn_clean to see current max length."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

headers = {'Authorization': f'Bearer {DIRECTUS_API_TOKEN}'}

# Get field metadata for isbn_clean in kft_koha_enriched collection
url = f"{DIRECTUS_API_URL}/fields/kft_koha_enriched/isbn_clean"

response = requests.get(url, headers=headers, timeout=30)
response.raise_for_status()
field_data = response.json()

print("Current isbn_clean field configuration:")
print(json.dumps(field_data, indent=2))

# Check schema property which contains database-level constraints
if 'data' in field_data:
    schema = field_data['data'].get('schema', {})
    print("\n--- Key Schema Properties ---")
    print(f"Data type: {schema.get('data_type')}")
    print(f"Max length: {schema.get('max_length')}")
    print(f"Is nullable: {schema.get('is_nullable')}")
