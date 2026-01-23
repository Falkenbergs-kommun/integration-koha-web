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

### Två Synkroniseringsalternativ

Det finns två synkroniseringsskript:

1. **`sync_koha_to_directus.php`** - Testsynk (100 böcker)
   - Snabb och säker för testning
   - Begränsad till 100 nyaste böcker
   - Rekommenderas för verifiering och dagliga uppdateringar

2. **`sync_koha_to_directus_full.php`** - Full katalogsynk (~71,000 böcker)
   - Synkroniserar hela katalogen
   - Tar ~3.8 timmar
   - Implementerar 500ms fördröjningar mellan batchar
   - Rekommenderas för initial sync och månadsvis

### Manuell Synkronisering

#### Testsynk (100 böcker)

```bash
# Normal körning
php sync_koha_to_directus.php

# Med verbose output (rekommenderat första gången)
php sync_koha_to_directus.php -v
```

#### Full Katalogsynk (~71,000 böcker)

```bash
# Full sync med standardinställningar (batch 100, delay 500ms)
php sync_koha_to_directus_full.php

# Med verbose output
php sync_koha_to_directus_full.php -v

# Custom batch size och delay
php sync_koha_to_directus_full.php --batch-size=250 --delay=1000

# Begränsa antal för testning
php sync_koha_to_directus_full.php --limit=1000

# Resumera från specifikt biblio_id
php sync_koha_to_directus_full.php --start-from=50000
```

**Flaggor för full sync:**
- `-v, --verbose` - Detaljerad output
- `--batch-size=N` - Antal biblios per batch (standard: 100)
- `--delay=N` - Fördröjning mellan batchar i millisekunder (standard: 500)
- `--limit=N` - Begränsa totalt antal biblios (för testning)
- `--start-from=ID` - Börja från specifikt biblio_id (för återupptagning)

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

### Performance och Rekommendationer

### Katalogstorlek och Tiduppskattning

Baserat på analys av Koha-katalogen (2026-01-23):

- **Totalt antal biblios**: ~71,069
- **Biblio ID-spann**: 2 till 71,069
- **Optimal batch size**: 100 (9ms/item från Koha API)
- **Full sync-tid**: ~3.8 timmar
  - Koha API-hämtning: 10.5 minuter
  - Directus-synkronisering: 213.2 minuter
  - Fördröjningar (500ms): 5.9 minuter
- **API-belastning**: 711 requests, 1 request var ~1.4 sekund

### Rekommendationer

✅ **För initial setup**:
1. Kör först testsynk (100 böcker) för att verifiera
2. Kör sedan full sync nattetid (02:00-05:00)
3. Använd verbose mode för att övervaka progress

✅ **För återkommande synkronisering**:
- **Dagligt**: Använd testsynk (100 nyaste böcker) för att fånga nya titlar
- **Månatligt**: Kör full sync för att säkerställa fullständig synkronisering

✅ **System load considerations**:
- Full sync är skonsam: 1 request var 1.4 sekund
- 500ms fördröjningar mellan batchar minskar belastning
- Koha är verksamhetskritiskt - kör alltid nattetid

⚠️ **Viktigt**:
- Koör aldrig full sync under kontorstid (08:00-17:00)
- Övervaka första full sync manuellt
- Använd `--limit` för att testa med mindre dataset först

## Automatisk Synkronisering (Cron)

#### Setup Crontab

```bash
# Redigera crontab
crontab -e

# Daglig testsynk (100 nyaste böcker) kl 03:00
0 3 * * * cd /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus && php sync_koha_to_directus.php >> sync.log 2>&1

# Månatlig full sync (hela katalogen) första söndagen kl 02:00
0 2 * * 0 [ $(date +\%d) -le 7 ] && cd /home/httpd/fbg-intranet/integrationer/integration-koha-web/directus && php sync_koha_to_directus_full.php >> sync_full.log 2>&1
```

**Alternativt**, använd den befintliga cron-wrappern som inkluderar log rotation:
```bash
# Daglig körning med wrapper
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

### kft_koha_biblios (30 fält)

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
| `issn` | string | ISSN för serials |
| `notes` | text | Anteckningar |
| `creation_date` | date | Datum när posten skapades i Koha |
| `koha_timestamp` | timestamp | Senaste ändringen i Koha |
| `copyright_date` | string | Upphovsrättsår |
| `lc_control_number` | string | Library of Congress kontrollnummer |
| `serial` | boolean | Om det är en serial |
| `url` | string | Extern URL |
| `catalog_link` | string | URL till Koha katalogpost |
| `image_url` | string | Syndetics bild-URL |
| `image_cached` | string | Lokal cache-path (null för nu) |
| `image_cached_url` | string | Full URL till cachad bild (null för nu) |
| `raw_data` | json | Komplett API-respons |

## Dataflöde

### Testsynk (sync_koha_to_directus.php)

1. **OAuth-autentisering** mot Koha API
2. **Hämta 100 biblios** från `/api/v1/biblios?_order_by=-biblio_id&_per_page=100`
3. **Transform** Koha-fält till Directus-format
4. **Hämta befintliga** biblios från Directus
5. **CREATE/UPDATE** biblios i Directus
6. **SOFT DELETE** böcker som inte längre finns (status='inactive')
7. **Statistik** och rapportering

### Full Katalogsynk (sync_koha_to_directus_full.php)

1. **OAuth-autentisering** mot Koha API
2. **Hämta första batch** för att få totalt antal (X-Total-Count header)
3. **Beräkna totalt antal batchar** (~711 batchar för 71,069 böcker med batch size 100)
4. **Hämta alla batchar** med paginering (`_page=1,2,3...`)
5. **För varje biblio**:
   - Transform Koha-fält till Directus-format
   - Försök UPDATE först (snabbare efter initial sync)
   - Vid 404: CREATE ny post
   - Progress-rapportering med ETA
6. **500ms fördröjning** mellan varje batch (mjuk belastning på Koha)
7. **Statistik** med rate och duration

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
├── DirectusClient.php                     # Directus API-klient (cURL-baserad)
├── create_koha_biblios_collection.php     # Skapa collection (kör en gång)
├── sync_koha_to_directus.php              # Testsynk (100 böcker)
├── sync_koha_to_directus_full.php         # Full katalogsynk (~71,000 böcker)
├── sync_cron.sh                           # Cron-wrapper med loggning
├── sync.log                               # Huvudlogg (skapas automatiskt)
├── sync_errors.log                        # Fellogg (skapas vid fel)
└── README.md                              # Denna fil
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

- **Endast URL:er** för bokomslag (inga faktiska bilder laddas upp)
- **Full sync tar ~3.8 timmar** (rekommenderas att köras nattetid 02:00-05:00)

## Framtida Förbättringar

1. ~~**Paginering**~~ - ✅ Implementerat (sync_koha_to_directus_full.php)
2. **Bild-uppladdning** - Ladda upp faktiska bilder till Directus Assets
3. **Inkrementell sync** - Endast synka ändrade böcker (kräver timestamp i Koha)
4. **Relationer** - Koppla till författare/förlag collections
5. **Webbhooks** - Trigga sync vid nya böcker i Koha

## Support

För frågor och support, kontakta utvecklingsteamet på Falkenbergs kommun.

---

**Skapad**: 2026-01-23
**Senast uppdaterad**: 2026-01-23
**Version**: 1.1
**Status**: ✅ Fullt fungerande
- Testsynk: 100 böcker (17.91s)
- Full katalogsynk: ~71,000 böcker (~3.8 timmar)
