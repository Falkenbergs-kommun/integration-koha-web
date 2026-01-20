# Koha integration för Falkenbergs bibliotek

RSS till JSON/XML-konverterare som hämtar boklistor från Kohas bibliotekssystem, berikar med metadata från API, cachar bokomslag och serverar data i JSON eller XML-format.

## Översikt

Detta system hämtar RSS-feeds från Falkenbergs biblioteks Koha-system, autentiserar mot ett externt API via OAuth 2.0, hämtar detaljerad bokmetadata, laddar ner och cachar bokomslag från Syndetics, och serverar en komplett dataset i JSON eller XML-format med CORS-stöd.

### Huvudfunktioner

- **RSS till JSON/XML**: Konverterar Kohas RSS-feeds till välstrukturerat JSON eller XML
- **API-berikning**: Hämtar 20+ metadatafält per bok från externt API
- **OAuth 2.0**: Automatisk autentisering med client credentials flow
- **Bildcachning**: Laddar ner och cachar bokomslag från Syndetics lokalt
- **Flexibel endpoint**: Stöd för olika boklistor via GET-parametrar
- **Format-alternativ**: Returnerar data i JSON eller XML baserat på förfrågan
- **Filbaserad cache**: 1-timmars cache för snabba svar
- **CORS-aktiverad**: Tillgänglig för frontends på andra domäner

## Installation

### Förutsättningar

- PHP 7.4 eller högre
- PHP-extensions: curl, simplexml, dom
- Webbserver (Apache/Nginx) eller PHP built-in server
- Skrivbehörighet för cache- och bildkataloger

### Setup

1. **Klona eller placera projektet**
```bash
cd /home/httpd/fbg-intranet/integrationer/integration-koha-web
```

2. **Konfigurera miljövariabler**
```bash
cp .env.example .env
```

3. **Redigera `.env` med dina credentials**
```env
# API-konfiguration för Koha-integration
API_BASE_URL=https://your-api.com/api/v1/biblios/
OAUTH_URL=https://your-api.com/oauth/token
CLIENT_ID=your-client-id
CLIENT_SECRET=your-client-secret

# Base URL för cachade bilder (med avslutande /)
BASE_URL=https://bibliotek.falkenberg.se/fbg_apps/services/koha/

# Syndetics-konfiguration för bokomslag
SYNDETICS_CLIENT=bibfalken

# Cache-inställningar (i sekunder)
CACHE_TTL_LATEST=3600
```

4. **Säkerställ katalogstrukturen**
```bash
# Cache-katalogen skapas automatiskt
# Bildkatalogen skapas automatiskt vid första körning
```

5. **Sätt rättigheter**
```bash
chmod 755 .
chmod 600 .env
# Webbservern behöver skrivbehörighet för cache och images/
```

## Användning

### API-endpoints

#### 1. **shelf.php** - Flexibel listendpoint (REKOMMENDERAD)

**JSON-format (default):**
```bash
# Default shelf (247)
https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php

# Specifik shelf
https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?shelfnumber=123

# Med både shelfnumber och format
https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?shelfnumber=247&format=json
```

**XML-format:**
```bash
# Default shelf (247) i XML
https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?format=xml

# Specifik shelf i XML
https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?shelfnumber=123&format=xml
```

#### 2. **index.php** - Hårdkodad till shelf 247 (JSON)

```bash
https://bibliotek.falkenberg.se/fbg_apps/services/koha/index.php
```

#### 3. **list.php** - Dynamisk lista med id-parameter (JSON)

```bash
https://bibliotek.falkenberg.se/fbg_apps/services/koha/list.php?id=247
```

#### 4. **latest.php** - Senaste böckerna i katalogen (nyinköp)

**JSON-format (default):**
```bash
# Senaste 10 böckerna (default)
https://bibliotek.falkenberg.se/fbg_apps/services/koha/latest.php

# Anpassat antal (max 50)
https://bibliotek.falkenberg.se/fbg_apps/services/koha/latest.php?limit=20

# Med format
https://bibliotek.falkenberg.se/fbg_apps/services/koha/latest.php?limit=15&format=json
```

**XML-format:**
```bash
# Senaste 10 böckerna i XML
https://bibliotek.falkenberg.se/fbg_apps/services/koha/latest.php?format=xml

# Anpassat antal i XML
https://bibliotek.falkenberg.se/fbg_apps/services/koha/latest.php?limit=20&format=xml
```

#### 5. **debug.php** - Utvecklingsverktyg för RSS-felsökning

```bash
https://bibliotek.falkenberg.se/fbg_apps/services/koha/debug.php
```

### GET-parametrar

| Parameter | Värden | Standard | Beskrivning |
|-----------|--------|----------|-------------|
| `shelfnumber` | integer | 247 | Kohas shelf-ID (shelf.php) |
| `format` | json, xml | json | Svarsformat (shelf.php, latest.php) |
| `id` | integer | - | List-ID (endast list.php) |
| `limit` | integer | 10 | Antal böcker, max 50 (endast latest.php) |

### Exempel på svar

**JSON-struktur:**
```json
{
  "status": "ok",
  "cached_at": "2024-12-05 14:30:00",
  "channel": {
    "title": "Senaste inköp",
    "link": "https://bibliotekskatalog.falkenberg.se/...",
    "description": "Nya böcker på biblioteket",
    "language": "sv-se",
    "lastBuildDate": "Thu, 05 Dec 2024 14:00:00 +0100"
  },
  "items": [
    {
      "title": "En spännande bok",
      "link": "https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=12345",
      "description": "Bokbeskrivning...",
      "pubDate": "Thu, 05 Dec 2024 10:00:00 +0100",
      "guid": "https://...",
      "biblio_id": "12345",
      "isbn": "978-91-8836-915-4",
      "isbn_clean": "9789188369154",
      "api_title": "En spännande bok",
      "api_author": "Författare Namn",
      "abstract": "Sammanfattning av boken...",
      "subtitle": "Undertitel",
      "publisher": "Bokförlaget",
      "publication_year": "2024",
      "publication_place": "Stockholm",
      "pages": "320",
      "material_size": "21 cm",
      "edition_statement": "Första upplagan",
      "series_title": "Serie namn",
      "age_restriction": "15 år",
      "url": "https://...",
      "ean": "9789188369154",
      "notes": "Anteckningar...",
      "image_url": "https://secure.syndetics.com/index.aspx?isbn=9789188369154/LC.JPG&client=bibfalken&type=xw12",
      "image_cached": "images/9789188369154.jpg",
      "image_cached_url": "https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/9789188369154.jpg"
    }
  ]
}
```

**XML-struktur:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<response>
  <status>ok</status>
  <cached_at>2024-12-05 14:30:00</cached_at>
  <channel>
    <title>Senaste inköp</title>
    <link>https://bibliotekskatalog.falkenberg.se/...</link>
    <description>Nya böcker på biblioteket</description>
    <language>sv-se</language>
    <lastBuildDate>Thu, 05 Dec 2024 14:00:00 +0100</lastBuildDate>
  </channel>
  <items>
    <item>
      <title>En spännande bok</title>
      <biblio_id>12345</biblio_id>
      <isbn>978-91-8836-915-4</isbn>
      <isbn_clean>9789188369154</isbn_clean>
      <api_title>En spännande bok</api_title>
      <api_author>Författare Namn</api_author>
      <image_cached_url>https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/9789188369154.jpg</image_cached_url>
      <!-- ... fler fält ... -->
    </item>
  </items>
</response>
```

## Dataflöde

### RSS-baserade endpoints (shelf.php, list.php, index.php)

1. Klient gör förfrågan till endpoint (t.ex. `shelf.php?shelfnumber=247&format=json`)
2. Kontrollera filbaserad cache (1 timmes TTL) - returnera omedelbart om giltig
3. Hämta RSS-feed från bibliotekskatalog.falkenberg.se
4. Parsa XML och extrahera biblionumber från varje post
5. Autentisera mot externt API via OAuth 2.0 client credentials
6. Hämta detaljerad bokmetadata för varje biblionumber från API
7. Extrahera ISBN, generera Syndetics bild-URL, ladda ner och cacha bokomslag lokalt
8. Bygg komplett JSON/XML-svar med RSS + API + bilddata
9. Skriv till cache-fil och returnera till klient

### API-baserad endpoint (latest.php)

1. Klient gör förfrågan (t.ex. `latest.php?limit=10&format=json`)
2. Kontrollera filbaserad cache (konfigurerbar TTL via CACHE_TTL_LATEST) - returnera om giltig
3. Autentisera mot externt API via OAuth 2.0 client credentials
4. Hämta senaste böckerna direkt från Koha API (`/biblios?_order_by=-biblio_id`)
5. Extrahera ISBN från varje bok, generera Syndetics bild-URL
6. Ladda ner och cacha bokomslag lokalt
7. Bygg komplett JSON/XML-svar med API-data + bilddata
8. Skriv till cache-fil och returnera till klient

## Projektstruktur

```
.
├── shelf.php            # Flexibel endpoint med shelfnumber och format-stöd
├── index.php            # Enkel endpoint (shelf 247, JSON)
├── list.php             # Dynamisk lista med ?id parameter
├── latest.php           # Senaste böckerna via API (nyinköp)
├── common.php           # Delad funktionsbibliotek
├── debug.php            # Utvecklingsverktyg för RSS-debugging
├── .env                 # API-credentials och konfiguration (ej i git)
├── .env.example         # Mall för miljövariabler
├── .gitignore           # Git-undantag (cache, bilder, .env)
├── cache.json           # JSON-cache för index.php
├── cache_shelf*.cache   # Cache-filer per shelf och format
├── cache_latest_*.cache # Cache-filer för latest.php
├── cache_list_*.json    # Cache-filer för list.php
├── images/              # Cachade bokomslag (persistenta)
├── docs/                # API-dokumentation (OpenAPI)
├── CLAUDE.md            # Teknisk dokumentation för Claude Code
└── README.md            # Denna fil
```

## Cachning

### JSON/XML Response Cache

- **Filnamn**:
  - `cache.json` (index.php)
  - `cache_shelf{nummer}_{format}.cache` (shelf.php)
  - `cache_list_{id}.json` (list.php)
  - `cache_latest_{limit}_{format}.cache` (latest.php)
- **TTL**:
  - 1 timme (3600 sekunder) - default för index.php, shelf.php, list.php
  - Konfigurerbar via `CACHE_TTL_LATEST` för latest.php (default 3600)
- **Invalidering**: Automatisk via filemtime-kontroll
- **Läge**: Läs/skriv för webbserver

### Bildcache

- **Katalog**: `images/`
- **Filnamn**: `{isbn}.jpg`
- **TTL**: Persistent (raderas aldrig automatiskt)
- **Validering**: Content-Type måste vara image/*
- **Fallback**: Returnerar null om nedladdning misslyckas

## Tekniska detaljer

### API-anrop och OAuth

Systemet använder OAuth 2.0 client credentials flow:

```php
POST {OAUTH_URL}
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id={CLIENT_ID}
&client_secret={CLIENT_SECRET}

→ Returnerar access_token som används för API-anrop
```

### Bokomslag från Syndetics

```
https://secure.syndetics.com/index.aspx?isbn={ISBN}/LC.JPG&client={SYNDETICS_CLIENT}&type=xw12
```

- **LC.JPG**: Large Cover format
- **xw12**: Bildstorlek (extra wide, 12-format)
- **client**: Biblioteks-ID för Syndetics-tjänsten

### Datarensning

**api_title rensning:**
- Tar bort avslutande `/` (slash)
- Tar bort avslutande `:` (kolon)
- Tar bort avslutande whitespace
- Exempel: `"En bok / "` → `"En bok"`

**ISBN-extraktion:**
- Hanterar flera ISBN separerade med `|` eller `,`
- Tar första ISBN i listan
- Tar bort bindestreck och mellanslag

### RSS Feed-quirks

- Kräver `KOHA_INIT=1` cookie för att kringgå inloggning
- Kan returnera JavaScript-redirects vid auth-fel
- User-Agent krävs för vissa Koha-installationer

## Felhantering

### HTTP-statuskoder

- **200**: Success
- **500**: Serverfel (RSS-fel, API-fel, OAuth-fel)

### Felsvar (JSON)

```json
{
  "status": "error",
  "message": "Kunde inte hämta RSS-feed",
  "error": "Connection timeout",
  "http_code": 0
}
```

### Vanliga fel

**OAuth-fel:**
```
Kunde inte hämta OAuth-token
```
**Lösning**: Kontrollera CLIENT_ID, CLIENT_SECRET och OAUTH_URL i .env

**RSS-fel:**
```
Kunde inte hämta RSS-feed
```
**Lösning**: Kontrollera att shelfnumber finns och är publikt tillgänglig

**XML-parsningsfel:**
```
Kunde inte parsa XML-data
```
**Lösning**: Använd debug.php för att inspektera rå RSS-feed

## Utveckling

### Lokal utveckling

```bash
# PHP built-in server
php -S localhost:8000

# Testa endpoint
curl "http://localhost:8000/shelf.php?shelfnumber=247&format=json"
curl "http://localhost:8000/shelf.php?format=xml"
```

### Debug-verktyg

Besök `debug.php` för att se:
- Rå RSS-feed från Koha
- HTTP-statuskod
- Content-Type
- Eventuella fel

### Lägga till nya metadatafält

1. Uppdatera `getBookDataFromApi()` i common.php (rad 66-122)
2. Lägg till fält i returarrayen
3. Uppdatera `processRssFeed()` för att inkludera fältet (rad 270-297)
4. Testa med `curl` eller `debug.php`

### Rensa cache manuellt

```bash
# Rensa alla cache-filer
rm cache*.json cache*.cache

# Rensa endast specifik shelf-cache
rm cache_shelf247_*.cache

# Rensa bildcache (VARNING: tar bort alla cachade bilder)
rm -rf images/*
```

## Säkerhet

### Konfiguration

- **SSL-verifiering**: Avstängd (`CURLOPT_SSL_VERIFYPEER = false`) - acceptabelt för interna system
- **.env-skydd**: Filen är gitignore:ad och bör ha `chmod 600`
- **Input-validering**: shelfnumber valideras som integer
- **CORS**: Öppen för alla origins (`*`) - ändra om striktare säkerhet krävs

### Secrets i .env

Följande värden ska **ALDRIG** committas:
- `CLIENT_ID`
- `CLIENT_SECRET`
- `SYNDETICS_CLIENT`
- `API_BASE_URL`
- `OAUTH_URL`

### Filrättigheter

```bash
chmod 755 /path/to/integration-koha-web
chmod 600 /path/to/integration-koha-web/.env
chmod 755 /path/to/integration-koha-web/images
```

## Monitorering

### Kontrollera cache-status

```bash
# Se ålder på cache-fil
ls -lh cache_shelf247_json.cache

# Visa cache-innehåll
cat cache_shelf247_json.cache | jq .

# Antal cachade bilder
ls -1 images/ | wc -l
```

### Testa endpoints

```bash
# JSON med curl
curl -s "https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?shelfnumber=247" | jq .

# XML med curl
curl -s "https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php?format=xml" | xmllint --format -

# Kontrollera HTTP-headers
curl -I "https://bibliotek.falkenberg.se/fbg_apps/services/koha/shelf.php"
```

## Vanliga frågor (FAQ)

**Q: Varför används både index.php och shelf.php?**
A: `index.php` är legacy-endpoint för bakåtkompatibilitet. `shelf.php` är den moderna, flexibla endpointen med stöd för olika shelves och format.

**Q: Hur lång tid tar första anropet?**
A: 5-15 sekunder beroende på antal böcker och API-responstid. Efterföljande anrop inom 1 timme returnerar från cache på <100ms.

**Q: Kan jag ändra cache-tiden?**
A: Ja, för latest.php använd miljövariabeln `CACHE_TTL_LATEST` i .env. För övriga endpoints, ändra `$cacheMaxAge` i respektive PHP-fil (standard 3600 sekunder = 1 timme).

**Q: Vad händer om Syndetics-bilden saknas?**
A: `image_cached` och `image_cached_url` blir `null`, övriga data returneras normalt.

**Q: Stöds HTTPS?**
A: Ja, systemet fungerar över både HTTP och HTTPS.

**Q: Kan jag få data i andra format än JSON/XML?**
A: För närvarande stöds endast JSON och XML. Kontakta utvecklingsteamet för andra format.

**Q: Vad är skillnaden mellan shelf.php och latest.php?**
A: `shelf.php` hämtar böcker från en specifik boklista (shelf) via RSS. `latest.php` hämtar de senast tillagda böckerna i hela katalogen direkt via API, sorterat på biblio_id.

**Q: Hur bestäms vilka böcker som är "senaste" i latest.php?**
A: Böckerna sorteras på biblio_id i fallande ordning (-biblio_id), vilket ger de senast tillagda posterna i Koha-systemet.

## Licens

Internt projekt för Falkenbergs kommun.

## Kontakt

För frågor och support, kontakta Utvecklingsavdelningen på Falkenbergs kommun.

---

*Senast uppdaterad: 2026-01-20*
