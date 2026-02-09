# Directus Collection för AI-Enriched Biblio Data

Denna dokumentation beskriver hur man skapar och populerar en Directus collection för att lagra AI-genererad metadata för biblioteksböcker.

## Översikt

Den enriched data innehåller:
- **abstract_enriched**: AI-genererade utökade bokbeskrivningar
- **subjects**: Kategoriserade ämnesord
- **tags**: Sökbara taggar
- **target_audience**: Målgrupp (Allmänheten, Gymnasiet, Högskola, etc.)
- **grounding**: Källor och sökfrågor som AI:n använde (för transparens)

## Förutsättningar

1. Tillgång till Directus-instans på `https://nav.utvecklingfalkenberg.se`
2. API-token med rättigheter att skapa collections och items
3. Befintlig collection `kft_koha_biblios` med fältet `biblio_id`

## Steg-för-steg Installation

### Steg 1: Skapa Collection i Directus

Du kan välja mellan två metoder:

#### Metod A: Via Directus Admin UI + SQL

1. Kör SQL DDL-skriptet direkt mot databasen:
   ```bash
   mysql -u username -p database_name < setup/directus-create-enriched-collection.sql
   ```

2. Logga in i Directus Admin UI och verifiera att tabellen syns under Settings → Data Model

#### Metod B: Via Directus API

1. Importera collection-definitionen via API:
   ```bash
   curl -X POST https://nav.utvecklingfalkenberg.se/collections \
     -H "Authorization: Bearer YOUR_DIRECTUS_TOKEN" \
     -H "Content-Type: application/json" \
     -d @setup/directus-collection-kft_koha_enriched.json
   ```

### Steg 2: Konfigurera Environment Variables

Lägg till i `.env`:
```env
# Directus API Configuration
DIRECTUS_API_URL=https://nav.utvecklingfalkenberg.se
DIRECTUS_API_TOKEN=your_directus_api_token_here
```

### Steg 3: Importera Enriched Data

Kör import-skriptet:
```bash
php setup/import-enriched-data.php
```

Skriptet kommer att:
- Läsa `enrich/enriched_books.json`
- Kontrollera om varje biblio redan finns (baserat på `biblio_id`)
- Uppdatera befintliga poster eller skapa nya
- Visa progress och sammanfattning

## Collection Schema

### Fält

| Fält | Typ | Beskrivning |
|------|-----|-------------|
| `id` | integer | Primary key (auto-increment) |
| `biblio_id` | integer | **FK till kft_koha_biblios.biblio_id** (unique) |
| `isbn_clean` | string(20) | Rensat ISBN utan bindestreck |
| `title` | string(500) | Boktitel (denormaliserad för snabb åtkomst) |
| `abstract_enriched` | text | AI-genererad utökad beskrivning |
| `subjects` | JSON | Array med ämnesord |
| `tags` | JSON | Array med sökbara taggar |
| `target_audience` | string(255) | Målgrupp/läsnivå |
| `grounding_search_queries` | JSON | Sökfrågor AI:n använde |
| `grounding_sources` | JSON | Källor med URI och titel |
| `date_created` | timestamp | Skapad-tidsstämpel |
| `date_updated` | timestamp | Uppdaterad-tidsstämpel |

### Index

- `idx_biblio_id`: För joins med kft_koha_biblios
- `idx_isbn_clean`: För ISBN-sökning
- `idx_target_audience`: För målgruppsfiltrering
- `idx_abstract_enriched`: Fulltext-index för textsökning
- `idx_subjects`: Fulltext-index för ämnesord
- `idx_tags`: Fulltext-index för taggar

## API-användning

### Hämta enriched data för en biblio

```bash
curl "https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?filter[biblio_id][_eq]=71069" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Join med kft_koha_biblios

```bash
curl "https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios/71069?fields=*,enriched.*" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

*(Kräver att en relation är konfigurerad i Directus mellan collections)*

### Sök i enriched abstracts

```bash
curl "https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?search=demokrati" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Filtrera på målgrupp

```bash
curl "https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?filter[target_audience][_eq]=Gymnasiet" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Filtrera på tags (JSON-innehåll)

```bash
# Hitta alla böcker taggade med "samhällskunskap"
curl "https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?filter[tags][_contains]=samhällskunskap" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Integration med befintlig RSS-to-JSON API

Du kan utöka befintliga endpoints (`index.php`, `list.php`, `latest.php`) för att inkludera enriched data:

### Exempel: Lägg till enriched data i common.php

```php
/**
 * Hämta enriched metadata från Directus
 */
function getEnrichedData($biblioId, $directusUrl, $directusToken) {
    $url = "$directusUrl/items/kft_koha_enriched?filter[biblio_id][_eq]=$biblioId";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $directusToken
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['data']) && count($data['data']) > 0) {
        return $data['data'][0];
    }

    return null;
}
```

### Användning i processRssFeed():

```php
foreach ($items as $item) {
    // ... befintlig kod för att hämta book data från API ...

    // Lägg till enriched data
    $enriched = getEnrichedData(
        $bookData['biblionumber'],
        getenv('DIRECTUS_API_URL'),
        getenv('DIRECTUS_API_TOKEN')
    );

    if ($enriched) {
        $bookData['abstract_enriched'] = $enriched['abstract_enriched'];
        $bookData['subjects'] = $enriched['subjects'];
        $bookData['tags'] = $enriched['tags'];
        $bookData['target_audience'] = $enriched['target_audience'];
    }

    $processedItems[] = $bookData;
}
```

## Underhåll och Uppdateringar

### Re-importera enriched data

Om du har uppdaterad enriched data:
```bash
php setup/import-enriched-data.php
```

Skriptet detekterar automatiskt befintliga poster och uppdaterar dem.

### Radera och återskapa collection

```sql
-- Varning: Detta raderar all data!
DROP TABLE IF EXISTS kft_koha_enriched;
```

Kör sedan DDL-skriptet igen för att återskapa tabellen.

## Felsökning

### "Foreign key constraint fails"

Säkerställ att alla `biblio_id` i `enriched_books.json` finns i `kft_koha_biblios`:

```sql
-- Kontrollera saknade biblios
SELECT e.biblio_id
FROM (
    -- Extrahera biblio_id från JSON-filen manuellt
    SELECT 71069 AS biblio_id
    UNION SELECT 71068
    -- ... etc
) e
LEFT JOIN kft_koha_biblios b ON e.biblio_id = b.biblio_id
WHERE b.biblio_id IS NULL;
```

### "Duplicate entry for biblio_id"

Tabellen har en UNIQUE constraint på `biblio_id`. Import-skriptet hanterar detta genom att uppdatera befintliga poster istället för att skapa dubbletter.

### API returnerar 401 Unauthorized

Verifiera att `DIRECTUS_API_TOKEN` är giltig:
```bash
curl -I https://nav.utvecklingfalkenberg.se/users/me \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Licens och Attribution

Enriched data är genererad via AI (Google Vertex AI) med grounding sources. Källorna sparas i `grounding_sources` för transparens och attribution.

## Kontakt

För frågor om denna integration, kontakta systemansvarig för Falkenbergs bibliotek.
