# Book Enrichment Scripts

Automatisk berikning av bibliografisk metadata med Google Gemini AI.

## Scripts

### 1. `enrich_smart.py` (✨ REKOMMENDERAD FÖR STORA DATASET)

**Optimerad för skalbarhet** - Kan hantera 26,000+ böcker utan minnesproblem.

Använder smart pagination för att hitta oberikade böcker även när 1000-tals böcker redan är berikade.

**Funktioner:**
- ✅ **Pagination-baserad sökning** - hoppar över redan berikade böcker
- ✅ Batched checking (50 böcker åt gången)
- ✅ Start-offset support - börja från godtycklig position
- ✅ Kan hantera miljontals poster utan minnesslut
- ✅ Google Search grounding + cost tracking

**Usage:**
```bash
# Berika 100 böcker (rekommenderat)
uv run enrich_smart.py --limit 100

# Starta från specific offset (om du vet var oberikade böcker börjar)
uv run enrich_smart.py --limit 50 --start-offset 3000

# Dry-run för att se vad som skulle berika
uv run enrich_smart.py --dry-run --limit 10

# Med custom delay mellan anrop
uv run enrich_smart.py --limit 100 --delay 2.0
```

**VIKTIGT:** Använd endast modell `gemini-3-flash-preview` (default). Andra modeller stöder inte Google Search + JSON samtidigt.

**Varför använda denna:**
- ✅ Fungerar när du har 3000+ redan berikade böcker
- ✅ Undviker "Request Header Too Large"-fel
- ✅ Skannar automatiskt igenom databas tills oberikade böcker hittas
- ✅ Sparar tid genom att inte läsa in alla ID:n i minnet

### 2. `enrich_from_directus.py` (Original, fungerar för små dataset < 3000 böcker)

Hämtar böcker från Directus, berikar med Gemini AI, och sparar direkt tillbaka till Directus.

**Funktioner:**
- ✅ Hämtar böcker från `kft_koha_biblios` som saknar abstract
- ✅ Filtrerar bort böcker som redan finns i `kft_koha_enriched`
- ✅ Berikar med Google Gemini API + Google Search grounding
- ✅ Sparar direkt till `kft_koha_enriched` via Directus API
- ✅ Rate limiting för att undvika API-begränsningar

**Usage:**
```bash
# Berika 10 böcker (default)
uv run enrich_from_directus.py

# Berika 25 böcker
uv run enrich_from_directus.py --limit 25

# Dry-run (visa vad som skulle göras utan att spara)
uv run enrich_from_directus.py --dry-run

# Längre delay mellan anrop
uv run enrich_from_directus.py --delay 2.0
```

**OBS:** Fungerar bara när < 3000 böcker redan är berikade. För större dataset, använd `enrich_smart.py`.

**Kräver i `.env`:**
```env
GEMINI_API_KEY=your-gemini-api-key
DIRECTUS_API_URL=https://nav.utvecklingfalkenberg.se
DIRECTUS_API_TOKEN=your-directus-token
```

### 3. `abstract_enrichment.py` (Fil-baserad, för export/import)

Läser från fil, berikar, och sparar till fil. Bra för batch-processing av exporterad data.

**Usage:**
```bash
# Läs från books.json, spara till enriched_books.json
uv run abstract_enrichment.py --input books.json --output enriched_books.json

# Använd annan modell
uv run abstract_enrichment.py --model gemini-1.5-pro

# Custom delay
uv run abstract_enrichment.py --delay 2.0
```

## Installation

### Förutsättningar

- Python 3.11+
- `uv` (Python package installer)

### Setup

```bash
# Installera dependencies med uv
cd enrich/
uv pip install -e .

# Eller manuellt:
uv pip install google-genai pydantic python-dotenv requests
```

### Hämta Gemini API-nyckel

1. Gå till https://aistudio.google.com/app/apikey
2. Logga in med Google-konto
3. Klicka "Create API key"
4. Kopiera nyckeln
5. Lägg till i `.env`:
   ```env
   GEMINI_API_KEY=your-api-key-here
   ```

## Workflow

### Rekommenderad Process (För stora dataset)

```bash
# 1. Berika 100 böcker med smart pagination
uv run enrich_smart.py --limit 100

# 2. Om du vet var oberikade böcker börjar, använd start-offset
uv run enrich_smart.py --limit 100 --start-offset 3100

# 3. Verifiera i Directus GUI
#    https://nav.utvecklingfalkenberg.se/admin
#    Content → kft_koha_enriched

# 4. Fortsätt med nästa batch
uv run enrich_smart.py --limit 100 --start-offset 3200
```

### Alternativ Process (För små dataset < 3000 böcker)

```bash
# 1. Berika 25 nya böcker
uv run enrich_from_directus.py --limit 25

# 2. Verifiera och upprepa
uv run enrich_from_directus.py --limit 25
```

### Manual Process (Fil-baserad)

```bash
# 1. Exportera böcker från Directus
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios' \
  --data-urlencode 'filter[abstract][_null]=true' \
  --data-urlencode 'limit=50' \
  -H 'Authorization: Bearer TOKEN' > books.json

# 2. Berika böckerna
uv run abstract_enrichment.py --input books.json --output enriched_books.json

# 3. Importera till Directus
php ../setup/import-enriched-data.php
```

## Output Format

Enriched data innehåller:

```json
{
  "biblio_id": 71069,
  "isbn_clean": "9789189915299",
  "title": "Nationalencyklopedins samhällskunskap",
  "abstract_enriched": "Detta heltäckande läromedel för gymnasieskolan...",
  "subjects": [
    "Samhällskunskap",
    "Nationalekonomi",
    "Statsvetenskap"
  ],
  "tags": [
    "samhällskunskap",
    "gymnasieskolan",
    "läromedel",
    "gy25"
  ],
  "target_audience": "Gymnasiet",
  "grounding_search_queries": [
    "Nationalencyklopedins samhällskunskap ISBN 9789189915299"
  ],
  "grounding_sources": [
    {
      "uri": "https://bokus.com/...",
      "title": "bokus.com"
    }
  ]
}
```

## Rate Limits

**Modell som används:** `gemini-3-flash-preview`
- Med billing: 360 requests/minut, 10,000+ requests/dag
- Gratis tier: 15 requests/minut, 1,500 requests/dag

**Rekommendation:**
- Default delay (1.0 sekund) fungerar bra för billing-konto
- För gratis tier: Använd `--delay 5.0` för att vara säker

## Kostnad

**Modell:** `gemini-3-flash-preview`
- **Per bok:** ~$0.00013 - $0.00015 (0.013-0.015 öre)
- **100 böcker:** ~$0.013 USD (~0.13 SEK)
- **1000 böcker:** ~$0.13 USD (~1.30 SEK)

**Gratis tier:** Upp till 1,500 requests/dag - perfekt för testning!

**Jämförelse:** Manuell katalogisering kostar 200-400 SEK per bok. AI-berikning är **99.9% billigare**!

## Troubleshooting

### "GEMINI_API_KEY not found"

Säkerställ att `.env` finns i projektets root-directory:
```bash
cat ../.env | grep GEMINI_API_KEY
```

### "Rate limit exceeded"

Öka delay mellan anrop:
```bash
uv run enrich_from_directus.py --delay 5.0
```

Eller vänta 1 minut och försök igen.

### "Invalid API key"

Generera ny API-nyckel på https://aistudio.google.com/app/apikey

### "No biblios to enrich"

Alla böcker har antingen:
1. Redan ett abstract i `kft_koha_biblios`, eller
2. Finns redan i `kft_koha_enriched`

Kolla i Directus:
```bash
# Hur många böcker saknar abstract?
curl -s 'https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios' \
  -G --data-urlencode 'filter[abstract][_null]=true' \
  -G --data-urlencode 'aggregate[count]=biblio_id' \
  -H 'Authorization: Bearer TOKEN'
```

## Best Practices

1. **Börja smått:** Testa med `--limit 5` först
2. **Använd dry-run:** Kör med `--dry-run` för att se vad som skulle hända
3. **Rate limiting:** Använd `--delay 2.0` eller högre för stora batchar
4. **Övervaka:** Kolla Directus GUI efter varje körning
5. **Backup:** Ta backup av `enriched_books.json` innan stora körningar

## Automation (Optional)

Skapa ett cron-jobb för automatisk berikning:

```bash
# Lägg till i crontab:
# Berika 50 böcker varje natt kl 02:00
0 2 * * * cd /home/httpd/fbg-intranet/integrationer/integration-koha-web/enrich && uv run enrich_smart.py --limit 50 >> /var/log/enrich.log 2>&1
```

**Tips:** Använd olika start-offsets för olika veckodagar för att sprida arbetet:
```bash
# Måndag: offset 0-1000
0 2 * * 1 cd /path/to/enrich && uv run enrich_smart.py --limit 50 --start-offset 0
# Tisdag: offset 1000-2000
0 2 * * 2 cd /path/to/enrich && uv run enrich_smart.py --limit 50 --start-offset 1000
# osv...
```

## Support

För frågor om enrichment-scripten, se:
- `../docs/GEMINI_ENRICHMENT.md` - Detaljerad Gemini API-guide
- `../CLAUDE.md` - Projekt-översikt

**Lycka till med berikningen! 🚀**
