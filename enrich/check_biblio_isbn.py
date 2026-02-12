#!/usr/bin/env python3
"""Check the ISBN field for a specific biblio to understand the length issue."""

import os
import json
from pathlib import Path
import requests
from dotenv import load_dotenv

env_path = Path(__file__).parent.parent / '.env'
load_dotenv(env_path)

API_BASE_URL = os.getenv('API_BASE_URL')
OAUTH_URL = os.getenv('OAUTH_URL')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')

def get_oauth_token():
    """Get OAuth token."""
    response = requests.post(
        OAUTH_URL,
        data={
            'grant_type': 'client_credentials',
            'client_id': CLIENT_ID,
            'client_secret': CLIENT_SECRET
        },
        timeout=30
    )
    response.raise_for_status()
    return response.json()['access_token']

def get_book_data(biblio_id, token):
    """Fetch book data from Koha API."""
    base_url = API_BASE_URL.rstrip('/')
    url = f"{base_url}/{biblio_id}"
    headers = {'Authorization': f'Bearer {token}'}
    response = requests.get(url, headers=headers, timeout=30)
    response.raise_for_status()
    return response.json()

# Check biblio_id 66496
biblio_id = 66496

print(f"Fetching data for biblio_id {biblio_id}...\n")

token = get_oauth_token()
book = get_book_data(biblio_id, token)

print("Book Data:")
print(f"  Title: {book.get('title')}")
print(f"  ISBN: {book.get('isbn')}")
print(f"  ISBN (clean): {book.get('isbn_clean')}")

isbn = book.get('isbn_clean') or book.get('isbn', '')
print(f"\nISBN value: '{isbn}'")
print(f"ISBN length: {len(isbn)} characters")
print(f"ISBN type: {type(isbn)}")

if isbn:
    print(f"\nISBN breakdown:")
    if '|' in isbn:
        parts = isbn.split('|')
        print(f"  Contains {len(parts)} ISBNs separated by '|'")
        for i, part in enumerate(parts, 1):
            print(f"    {i}. '{part}' ({len(part)} chars)")
    elif ',' in isbn:
        parts = isbn.split(',')
        print(f"  Contains {len(parts)} ISBNs separated by ','")
        for i, part in enumerate(parts, 1):
            print(f"    {i}. '{part.strip()}' ({len(part.strip())} chars)")
