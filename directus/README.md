# Koha → Directus Sync

Automatisk synkronisering av bokdata från Koha API till Directus collection `kft_koha_biblios`.

## Översikt

Detta system hämtar böcker från Koha bibliotekssystem via API och synkroniserar dem till Directus med support för:
- **CREATE**: Nya böcker läggs till
- **UPDATE**: Befintliga böcker uppdateras
- **SOFT DELETE**: Borttagna böcker markeras som inactive

## Installation

### 1. Förutsättningar
- PHP 7.4+
- Directus API-access (credentials i .env)
- Koha API OAuth credentials (redan konfigurerat)

### 2. Konfiguration

Directus credentials finns redan i `.env`:
```env
DIRECTUS_API_URL=https://nav.utvecklingfalkenberg.se
DIRECTUS_API_TOKEN=fGw_FW-mzf7BxRAbcRhj3EMS-rc3CoAZ
```

### 3. Skapa Collection (En gång)

```bash
cd /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus
php create_koha_biblios_collection.php
```

**Output:**
```
📚 Koha Biblios → Directus Collection Creator
==============================================

🔨 Creating collection 'kft_koha_biblios'...
✅ Collection created!

📝 Creating 25 fields...
✅ Collection setup complete!
```

## Användning

### Manuell Synkronisering

```bash
# Normal körning
php sync_koha_to_directus.php

# Med verbose output (rekommenderat första gången)
php sync_koha_to_directus.php -v
```

**Förväntat output:**
```
╔════════════════════════════════════════════════════════════╗
║  Koha → Directus Sync - Biblios                           ║
╚════════════════════════════════════════════════════════════╝

🔄 Step 1/5: Getting OAuth token from Koha...
✅ OAuth token obtained

🔄 Step 2/5: Fetching 100 biblios from Koha API...
✅ Fetched 100 biblios from Koha

🔄 Step 3/5: Fetching existing biblios from Directus...
✅ Found 0 existing biblios in Directus

🔄 Step 4/5: Synchronizing biblios...
  Processing: 10/100 - biblio_id=71060
  ...
✅ Synchronization complete

🔄 Step 5/5: Marking inactive biblios...
✅ Soft delete complete

╔════════════════════════════════════════════════════════════╗
║  SYNC STATISTICS                                           ║
╚════════════════════════════════════════════════════════════╝

📊 Koha biblios:              100
📊 Directus before sync:      0
───────────────────────────────────────────────────────────
✅ Created:                   100
🔄 Updated:                   0
⏸️  Marked inactive:           0
❌ Errors:                    0
───────────────────────────────────────────────────────────
📚 Total in Directus now:     100
⏱️  Duration:                  17.91s

✨ Sync complete!
🔗 View at: https://nav.utvecklingfalkenberg.se/admin/content/kft_koha_biblios
```

### Automatisk Synkronisering (Cron)

#### Setup Crontab

```bash
# Redigera crontab
crontab -e

# Lägg till följande rad för daglig körning kl 03:00
0 3 * * * /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus/sync_cron.sh
```

#### Loggar

- **Huvudlogg**: `directus/sync.log`
- **Fellogg**: `directus/sync_errors.log`

```bash
# Visa senaste synkronisering
tail -50 directus/sync.log

# Följ live-logg
tail -f directus/sync.log
```

## Collection Schema

### kft_koha_biblios (25 fält)

| Fält | Typ | Beskrivning |
|------|-----|-------------|
| `biblio_id` | integer | Primärnyckel från Koha |
| `status` | string | 'active' eller 'inactive' (soft delete) |
| `last_synced` | timestamp | Senaste synkronisering |
| `isbn` | string | ISBN (kan innehålla flera) |
| `isbn_clean` | string | Första ISBN, rensat |
| `title` | string | Boktitel |
| `author` | string | Författare |
| `abstract` | text | Sammanfattning |
| `subtitle` | string | Undertitel |
| `publisher` | string | Förlag |
| `publication_year` | string | Utgivningsår |
| `publication_place` | string | Utgivningsort |
| `pages` | string | Sidantal |
| `material_size` | string | Materialstorlek |
| `edition_statement` | string | Upplaga |
| `series_title` | string | Serietitel |
| `age_restriction` | string | Åldersrestriktion |
| `ean` | string | EAN-kod |
| `notes` | text | Anteckningar |
| `url` | string | Extern URL |
| `catalog_link` | string | URL till Koha katalogpost |
| `image_url` | string | Syndetics bild-URL |
| `image_cached` | string | Lokal cache-path (null för nu) |
| `image_cached_url` | string | Full URL till cachad bild (null för nu) |
| `raw_data` | json | Komplett API-respons |

## Dataflöde

1. **OAuth-autentisering** mot Koha API
2. **Hämta 100 biblios** från `/api/v1/biblios?_order_by=-biblio_id&_per_page=100`
3. **Transform** Koha-fält till Directus-format
4. **Hämta befintliga** biblios från Directus
5. **CREATE/UPDATE** biblios i Directus
6. **SOFT DELETE** böcker som inte längre finns (status='inactive')
7. **Statistik** och rapportering

## Funktioner

### Transform-funktioner (från common.php)
- `getFirstIsbn()` - Extraherar första ISBN från fält med flera
- `cleanTitle()` - Rensar avslutande `/` och `:` från titel
- `getImageUrl()` - Genererar Syndetics bild-URL

### DirectusClient metoder
- `getItems()` - Hämta items från collection
- `createItem()` - Skapa nytt item
- `updateItem()` - Uppdatera befintligt item (HTTP 200/204)
- `collectionExists()` - Kontrollera om collection finns

## Verifiering

### Via Directus UI

Besök: https://nav.utvecklingfalkenberg.se/admin/content/kft_koha_biblios

Kontrollera:
- ✅ 100 biblios synliga
- ✅ Fält korrekt ifyllda (title, author, isbn, etc.)
- ✅ Status='active' för alla
- ✅ last_synced timestamp nyligen

### Via API

```bash
# Hämta första 5 biblios
curl -H "Authorization: Bearer fGw_FW-mzf7BxRAbcRhj3EMS-rc3CoAZ" \
  "https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios?limit=5" | jq .

# Hämta specifik biblio
curl -H "Authorization: Bearer fGw_FW-mzf7BxRAbcRhj3EMS-rc3CoAZ" \
  "https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios/71069" | jq .

# Räkna totalt antal
curl -H "Authorization: Bearer fGw_FW-mzf7BxRAbcRhj3EMS-rc3CoAZ" \
  "https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios?limit=1&meta=*" | jq '.meta.filter_count'
```

## Filer

```
directus/
├── DirectusClient.php                  # Directus API-klient (cURL-baserad)
├── create_koha_biblios_collection.php  # Skapa collection (kör en gång)
├── sync_koha_to_directus.php           # Huvudsynk-script
├── sync_cron.sh                        # Cron-wrapper med loggning
├── sync.log                            # Huvudlogg (skapas automatiskt)
├── sync_errors.log                     # Fellogg (skapas vid fel)
└── README.md                           # Denna fil
```

## Felsökning

### Collection finns inte
```
❌ Fatal error: Collection 'kft_koha_biblios' does not exist in Directus.
Please run 'php create_koha_biblios_collection.php' first.
```
**Lösning**: Kör `php create_koha_biblios_collection.php`

### OAuth-fel
```
❌ Fatal error: Failed to obtain OAuth token from Koha
```
**Lösning**: Kontrollera OAUTH_URL, CLIENT_ID och CLIENT_SECRET i .env

### Directus API-fel
```
Directus PATCH failed (HTTP 403): {"errors":[{"message":"Forbidden"}]}
```
**Lösning**: Kontrollera DIRECTUS_API_TOKEN i .env

## Begränsningar

- **Max 100 böcker** per synkronisering (kan ökas vid behov)
- **Endast URL:er** för bokomslag (inga faktiska bilder laddas upp)
- **Ingen paginering** för närvarande (tar senaste 100 böckerna)

## Framtida Förbättringar

1. **Paginering** - Synka alla böcker i Koha-systemet
2. **Bild-uppladdning** - Ladda upp faktiska bilder till Directus Assets
3. **Inkrementell sync** - Endast synka ändrade böcker
4. **Relationer** - Koppla till författare/förlag collections
5. **Webbhooks** - Trigga sync vid nya böcker i Koha

## Support

För frågor och support, kontakta utvecklingsteamet på Falkenbergs kommun.

---

**Skapad**: 2026-01-23
**Version**: 1.0
**Status**: ✅ Fungerar - 100 böcker synkroniserade framgångsrikt
