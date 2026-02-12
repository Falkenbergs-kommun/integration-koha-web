#!/usr/bin/env python3
"""Check biblio data from Directus to understand ISBN field length."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

DIRECTUS_API_URL = os.getenv('DIRECTUS_API_URL')
DIRECTUS_API_TOKEN = os.getenv('DIRECTUS_API_TOKEN')

biblio_id = 66496

headers = {'Authorization': f'Bearer {DIRECTUS_API_TOKEN}'}
url = f"{DIRECTUS_API_URL}/items/kft_koha_biblios"
params = {
    'filter[biblio_id][_eq]': biblio_id,
    'fields': '*'
}

response = requests.get(url, headers=headers, params=params, timeout=30)
response.raise_for_status()
data = response.json()

if not data.get('data'):
    print(f"No data found for biblio_id {biblio_id}")
else:
    book = data['data'][0]
    print(f"Book Data for biblio_id {biblio_id}:")
    print(f"  Title: {book.get('title')}")
    print(f"  Author: {book.get('author')}")
    print(f"  ISBN: {book.get('isbn')}")
    print(f"  ISBN (clean): {book.get('isbn_clean')}")

    isbn_clean = book.get('isbn_clean')
    isbn = book.get('isbn')

    print(f"\n--- ISBN Analysis ---")
    if isbn_clean:
        print(f"isbn_clean value: '{isbn_clean}'")
        print(f"isbn_clean length: {len(isbn_clean)} characters")
        print(f"isbn_clean type: {type(isbn_clean)}")
    else:
        print(f"isbn_clean: None/Empty")

    if isbn:
        print(f"\nisbn value: '{isbn}'")
        print(f"isbn length: {len(isbn)} characters")
        print(f"isbn type: {type(isbn)}")
