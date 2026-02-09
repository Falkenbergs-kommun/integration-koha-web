# Quick Start: AI-Enriched Biblio Data

## ✅ Installation Klar!

Collectionen `kft_koha_enriched` är nu skapad och populerad med 10 enriched biblios.

## 📊 API-endpoints

### 1. Hämta alla enriched biblios

```bash
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'fields=biblio_id,title,target_audience' \
  -H 'Authorization: Bearer D0duzBgxKihFrcv0gXjU3P0OF71eXWLL'
```

### 2. Hämta en specifik biblio med full enriched data

```bash
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'filter[biblio_id][_eq]=71069' \
  --data-urlencode 'fields=*' \
  -H 'Authorization: Bearer D0duzBgxKihFrcv0gXjU3P0OF71eXWLL'
```

### 3. Sök i enriched abstracts och metadata

```bash
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'search=demokrati' \
  --data-urlencode 'fields=biblio_id,title,abstract_enriched' \
  -H 'Authorization: Bearer D0duzBgxKihFrcv0gXjU3P0OF71eXWLL'
```

### 4. Filtrera på målgrupp

```bash
# Hitta alla gymnasieböcker
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'filter[target_audience][_eq]=Gymnasiet' \
  -H 'Authorization: Bearer D0duzBgxKihFrcv0gXjU3P0OF71eXWLL'
```

### 5. Kombinera filter och sökning

```bash
# Sök efter samhällskunskap bland gymnasieböcker
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'search=samhällskunskap' \
  --data-urlencode 'filter[target_audience][_eq]=Gymnasiet' \
  -H 'Authorization: Bearer D0duzBgxKihFrcv0gXjU3P0OF71eXWLL'
```

## 🔗 Join med kft_koha_biblios

För att hämta både original-data och enriched data tillsammans, använd biblio_id som nyckel:

```php
// 1. Hämta biblio från kft_koha_biblios
$biblioId = 71069;
$biblio = fetch("https://nav.utvecklingfalkenberg.se/items/kft_koha_biblios?filter[biblio_id][_eq]=$biblioId");

// 2. Hämta enriched data
$enriched = fetch("https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?filter[biblio_id][_eq]=$biblioId");

// 3. Kombinera
$combined = array_merge($biblio, $enriched);
```

## 📝 Tillgängliga fält i kft_koha_enriched

| Fält | Typ | Beskrivning |
|------|-----|-------------|
| `id` | integer | Primary key (internt) |
| `biblio_id` | integer | Referens till kft_koha_biblios |
| `isbn_clean` | string | ISBN utan bindestreck |
| `title` | string | Boktitel (kopia för snabb åtkomst) |
| `abstract_enriched` | text | **AI-genererad utökad beskrivning** |
| `subjects` | JSON array | **Ämnesord/kategorier** |
| `tags` | JSON array | **Sökbara taggar** |
| `target_audience` | string | **Målgrupp** (Gymnasiet, Allmänheten, etc.) |
| `grounding_search_queries` | JSON array | Sökfrågor AI:n använde |
| `grounding_sources` | JSON array | Källor med URI och titel |
| `date_created` | timestamp | När posten skapades |
| `date_updated` | timestamp | När posten uppdaterades |

## 🔄 Uppdatera enriched data

Om du får ny enriched data från AI:n:

```bash
# 1. Uppdatera enrich/enriched_books.json med ny data

# 2. Kör import igen (uppdaterar befintliga poster automatiskt)
php setup/import-enriched-data.php
```

## 💡 Användningsexempel

### Exempel 1: Visa enriched abstract på webbplats

```javascript
// Fetch enriched data
fetch('https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?filter[biblio_id][_eq]=71069')
  .then(res => res.json())
  .then(data => {
    const enriched = data.data[0];
    document.getElementById('abstract').innerHTML = enriched.abstract_enriched;
    document.getElementById('tags').innerHTML = JSON.parse(enriched.tags).join(', ');
  });
```

### Exempel 2: Sökfunktion med enriched data

```javascript
// Search across enriched metadata
async function searchBooks(query) {
  const url = new URL('https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched');
  url.searchParams.set('search', query);
  url.searchParams.set('fields', 'biblio_id,title,abstract_enriched,subjects,tags');

  const response = await fetch(url, {
    headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
  });

  return await response.json();
}
```

### Exempel 3: Filtrera på ämnesområde

```javascript
// Find all books about "Samhällskunskap"
// Note: Since subjects is JSON, you need to use search or fetch all and filter client-side
const url = 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched?search=Samhällskunskap';
```

## 🎯 Nästa steg

1. **Integrera i koha-shelf.js widget**: Lägg till enriched abstracts och tags i bokvisningen
2. **Skapa filter-UI**: Låt användare filtrera på målgrupp och ämnen
3. **Förbättra sök**: Använd enriched data för bättre sökresultat
4. **Visa källor**: Visa grounding_sources för transparens om AI-genererad innehåll

## 📚 Dokumentation

- **Fullständig setup-guide**: `docs/ENRICHED_DATA_SETUP.md`
- **Collection schema**: `setup/directus-collection-kft_koha_enriched.json`
- **Import-skript**: `setup/import-enriched-data.php`

## 🐛 Felsökning

### Problem: "You don't have permission to access this"

API-tokenet kanske har utgått. Generera ett nytt i Directus Admin UI:
Settings → Access Tokens → Create New Token

### Problem: "Invalid foreign key"

Detta problem är nu löst. Collectionen använder ett vanligt integer-fält istället för FK-constraint.

### Problem: JSON-fält returneras som strings

Detta är normalt. Parsa med `JSON.parse()` i JavaScript eller `json_decode()` i PHP:

```javascript
const subjects = JSON.parse(enriched.subjects);
const tags = JSON.parse(enriched.tags);
```

## 🎉 Lycka till!

Du har nu en fullt fungerande AI-enriched metadata collection i Directus!
