# Setup Scripts

This directory contains one-time setup and maintenance scripts for configuring the Directus collections and data structures.

## ⚠️ Important

These scripts are for **initial setup and troubleshooting only**. They should **not** be run during normal operations.

## Setup Scripts

### Initial Collection Setup

**setup-directus-collection.php**
- Creates the `kft_koha_enriched` collection in Directus via API
- Sets up all fields with correct types and interfaces
- Creates foreign key relation to `kft_koha_biblios`
- **When to use:** First-time setup of enriched collection
- **Requirements:** DIRECTUS_API_URL and DIRECTUS_API_TOKEN in `.env`

**directus-create-enriched-collection.sql**
- SQL DDL for creating the enriched table manually
- Includes JSON column types and indexes
- **When to use:** If you prefer direct SQL setup over API
- **Alternative to:** setup-directus-collection.php

**directus-collection-kft_koha_enriched.json**
- Complete collection definition in JSON format
- Can be imported via Directus Admin GUI
- **When to use:** Manual import through Directus interface

**directus-relation-config.json**
- Relation configuration between collections
- **When to use:** Setting up FK relation manually

### Data Import

**import-enriched-data.php**
- Imports enriched book data from `enrich/enriched_books.json` to Directus
- **When to use:** Initial data import or re-import after structure changes
- **Note:** Now superseded by `enrich/enrich_from_directus.py` for ongoing enrichment

**clear-and-reimport.php**
- Deletes all data from `kft_koha_enriched` and re-imports from JSON
- **When to use:** Fresh start after data corruption or structure changes
- **⚠️ WARNING:** Destructive operation - deletes all enriched data!

### Troubleshooting & Fixes

**fix-directus-json-fields.php**
- Fixes JSON field datatypes via Directus API
- Converts LONGTEXT to JSON type
- **When to use:** After discovering JSON fields stored as text

**fix-json-types-php.php**
- Alternative JSON field type fixer
- Uses different API approach
- **When to use:** If fix-directus-json-fields.php doesn't work

**fix-interface-options.php**
- Updates field interface options in Directus
- Fixes Tags interface configuration
- **When to use:** When GUI shows "not compatible with interface" errors

**create-remaining-fields.php**
- Creates any missing fields in the collection
- Used during incremental setup
- **When to use:** Adding new fields to existing collection

### SQL Maintenance

**recreate-collection-with-json.sql**
- Drops and recreates table with proper JSON datatypes
- **⚠️ WARNING:** Destroys all data in kft_koha_enriched!
- **When to use:** Complete reset with correct schema

**fix-column-types.sql**
- ALTER TABLE statements to fix column datatypes
- Converts LONGTEXT to JSON without data loss
- **When to use:** Fixing datatypes on existing table with data

**fix-json-fields.sql**
- Alternative JSON datatype fix script
- **When to use:** If fix-column-types.sql doesn't work

## Typical Setup Workflow

### First-Time Setup

```bash
# 1. Create collection via API (easiest)
php setup/setup-directus-collection.php

# 2. Import initial data (optional - now use enrich_from_directus.py instead)
php setup/import-enriched-data.php
```

### If You Have Issues

```bash
# If JSON fields show as text in MySQL
mysql < setup/fix-column-types.sql

# If Directus GUI shows interface errors
php setup/fix-interface-options.php

# If data is corrupted and you want fresh start
php setup/clear-and-reimport.php
```

## Current Enrichment Workflow

**For ongoing book enrichment, do NOT use these scripts.**

Instead, use the Python enrichment pipeline:

```bash
cd enrich/
uv run enrich_from_directus.py --limit 10
```

This automatically:
- Fetches books without abstracts from `kft_koha_biblios`
- Enriches them with Gemini AI
- Saves to `kft_koha_enriched` via API
- Tracks costs per enrichment

See `enrich/README.md` for details.

## Documentation

Related documentation in `docs/`:
- **ENRICHED_DATA_SETUP.md** - Complete setup guide
- **DIRECTUS_GUI_FIX_TUTORIAL.md** - Step-by-step GUI troubleshooting
- **INTERFACE_EXPLAINED.md** - Understanding Directus interfaces
- **QUICK_REFERENCE_FIELD_CONFIG.md** - Field configuration reference
- **GEMINI_ENRICHMENT.md** - Gemini API integration guide

## Support

If you encounter issues with setup:
1. Check the documentation in `docs/`
2. Review error messages carefully
3. Ensure `.env` has correct credentials
4. Verify MySQL user has CREATE/ALTER permissions
