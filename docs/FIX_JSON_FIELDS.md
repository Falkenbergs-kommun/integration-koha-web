# Fix: JSON Fields Stored as Strings

## Problem

När du öppnar `kft_koha_enriched` i Directus GUI får du felmeddelandet:

> "The current data is not compatible with the repeater interface and will be overriden when adding items to the repeater"

Detta beror på att fälten `subjects`, `tags`, `grounding_search_queries` och `grounding_sources` skapades som **LONGTEXT** istället för **JSON** i databasen.

## Orsak

När Directus collection skapades via API specificerades `type: "json"`, men MySQL-tabellen skapades med LONGTEXT. Detta kan bero på:
1. Directus API-version som inte stöder JSON-typ fullt ut
2. MySQL-version som inte har stöd för JSON
3. Automatisk typ-konvertering i Directus

## Verifiering

Kör detta för att se field-typen:

```bash
curl -s 'https://nav.utvecklingfalkenberg.se/fields/kft_koha_enriched/subjects' \
  -H 'Authorization: Bearer YOUR_TOKEN' | jq '.data.schema.data_type'
```

Om svaret är `"longtext"` eller `"text"` istället för `"json"`, har du problemet.

## Lösning

### Alternativ 1: SQL-fix (Rekommenderat - Snabbast)

Om du har direkt MySQL-åtkomst:

```bash
# 1. Kör SQL-skriptet
mysql -u username -p directus < recreate-collection-with-json.sql

# 2. Re-importera data
php docs/import-enriched-data.php
```

SQL-skriptet droppar och återskapar tabellen med korrekt JSON-typ.

**⚠️ Varning:** Detta raderar all befintlig data i tabellen!

### Alternativ 2: Manual ALTER TABLE

Om du vill behålla befintliga poster men ändra typen:

```sql
USE directus;

-- Tömma befintlig data (stringifierad JSON kan inte konverteras automatiskt)
UPDATE kft_koha_enriched SET
    subjects = NULL,
    tags = NULL,
    grounding_search_queries = NULL,
    grounding_sources = NULL;

-- Ändra kolumntyper
ALTER TABLE kft_koha_enriched
    MODIFY COLUMN subjects JSON,
    MODIFY COLUMN tags JSON,
    MODIFY COLUMN grounding_search_queries JSON,
    MODIFY COLUMN grounding_sources JSON;

-- Verifiera
DESCRIBE kft_koha_enriched;
```

Sedan re-importera:
```bash
php docs/import-enriched-data.php
```

### Alternativ 3: Via Directus Admin UI

1. Gå till Settings → Data Model → kft_koha_enriched
2. För varje fält (subjects, tags, grounding_search_queries, grounding_sources):
   - Klicka på fältet
   - Under "Schema":
     - **Type**: JSON (inte Text)
     - **Max Length**: ta bort
   - Spara
3. Om det inte går att ändra typ, radera fältet och återskapa det:
   - Field Name: `subjects`
   - Type: **JSON**
   - Interface: **Tags** (för subjects, tags, queries) - INTE List/Repeater!
   - Width: Half
   - ⚠️ **Viktigt:** List/Repeater kräver field definitions och funkar inte för simple string arrays!

4. Re-importera data:
```bash
php docs/import-enriched-data.php
```

## Verifiering Efter Fix

### 1. Kontrollera datatyp i databas

```sql
DESCRIBE kft_koha_enriched;
```

Du bör se:
```
subjects               | json    | YES  |     | NULL
tags                   | json    | YES  |     | NULL
grounding_search_queries | json  | YES  |     | NULL
grounding_sources      | json    | YES  |     | NULL
```

### 2. Kontrollera via API

```bash
curl -s -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'filter[biblio_id][_eq]=71069' \
  --data-urlencode 'fields=subjects,tags' \
  -H 'Authorization: Bearer YOUR_TOKEN' | jq '.data[0].subjects'
```

Du bör få en **array** tillbaka:
```json
[
  "Samhällskunskap",
  "Nationalekonomi",
  "Statsvetenskap"
]
```

**INTE** en sträng:
```json
"[\"Samhällskunskap\",\"Nationalekonomi\",\"Statsvetenskap\"]"
```

### 3. Kontrollera i Directus GUI

1. Öppna Directus Admin UI
2. Gå till Content → kft_koha_enriched
3. Öppna en post (t.ex. biblio_id 71069)
4. Fälten `subjects`, `tags`, etc. bör nu visas som listor/arrays
5. Inget felmeddelande om "not compatible with repeater interface"

## Framtida Förebyggande

För nya collections, använd alltid SQL DDL för att skapa tabeller med JSON-typ:

```sql
CREATE TABLE my_collection (
    id INT PRIMARY KEY AUTO_INCREMENT,
    my_json_field JSON  -- Inte TEXT eller LONGTEXT!
);
```

Eller via Directus API, säkerställ explicit typ:

```json
{
  "field": "my_json_field",
  "type": "json",
  "schema": {
    "data_type": "json"
  }
}
```

## Felsökning

### "Data truncated for column 'subjects'"

Detta händer om du försöker insertera JSON som sträng. Lösning: Säkerställ att PHP skickar faktiska arrayer:

```php
// RÄTT
$data = ['subjects' => ['Ämne 1', 'Ämne 2']];  // Array

// FEL
$data = ['subjects' => '["Ämne 1", "Ämne 2"]'];  // String
```

### "Cannot modify column from longtext to json"

MySQL kanske inte tillåter direkt konvertering. Lösning:

```sql
-- Ta bort kolumnen helt
ALTER TABLE kft_koha_enriched DROP COLUMN subjects;

-- Lägg till igen med JSON-typ
ALTER TABLE kft_koha_enriched ADD COLUMN subjects JSON AFTER abstract_enriched;
```

### Tags visas som tomma trots data i JSON

Om databasen har JSON-typ men GUI visar tomt, kontrollera interface-typ:

1. Gå till Settings → Data Model → kft_koha_enriched → tags
2. Interface: **Tags** (inte List)
3. Special: Ingen
4. Spara

## Support

Om problemet kvarstår, kontakta systemadministratör eller se [Directus Documentation](https://docs.directus.io/).
