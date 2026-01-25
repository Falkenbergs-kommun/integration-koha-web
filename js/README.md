# Koha Shelf Widget

JavaScript-widget för att visa Koha-bokhyllor som UIkit 3 cards i Yootheme Pro / Joomla.

## Funktioner

- ✅ Asynkron laddning (blockerar inte sidladdning)
- ✅ Snygg laddningsindikator med UIkit spinner
- ✅ Responsiv grid-layout (2-6 kolumner)
- ✅ Anpassningsbara kortstorlekar
- ✅ Automatisk API-URL detektering
- ✅ Lazy loading av bilder
- ✅ Fallback för saknade bilder
- ✅ XSS-skydd
- ✅ Fungerar med AJAX-laddat innehåll
- ✅ MutationObserver för dynamiskt innehåll

## Installation

### Steg 1: Lägg till UIkit 3 (om inte redan installerat)

UIkit 3 är normalt redan inkluderat i Yootheme Pro. Om inte, lägg till i `<head>`:

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.16.26/dist/css/uikit.min.css" />
<script src="https://cdn.jsdelivr.net/npm/uikit@3.16.26/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.16.26/dist/js/uikit-icons.min.js"></script>
```

### Steg 2: Inkludera koha-shelf.js

I Joomla admin:
1. Gå till **System → Global Configuration → Custom Code**
2. Lägg till i **Before `</head>` tag**:

```html
<script src="https://dev-intra.falkenberg.se/integrationer/integration-koha-web/js/koha-shelf.js" defer></script>
```

### Steg 3: Använd i Yootheme Pro

I Yootheme Pro page builder:
1. Lägg till ett **HTML-element**
2. Klistra in:

```html
<div class="koha-shelf" data-shelf-id="1"></div>
```

Byt ut `"1"` mot ditt shelf-id.

## Användning

### Grundläggande exempel

```html
<div class="koha-shelf" data-shelf-id="1"></div>
```

### Med anpassningar

```html
<div class="koha-shelf"
     data-shelf-id="1"
     data-columns="4"
     data-card-size="small"></div>
```

## Konfiguration

### Data-attribut

| Attribut | Typ | Standard | Beskrivning |
|----------|-----|----------|-------------|
| `data-shelf-id` | number | - | **Obligatoriskt.** ID för bokhyllan från Koha |
| `data-columns` | number | 3 | Antal kolumner (2, 3, 4, 5, eller 6) |
| `data-card-size` | string | default | Kortstorlek: `small`, `default`, `large` |
| `data-api-url` | string | auto | API bas-URL (auto-detekteras normalt) |

### Exempel med olika kolumnantal

**2 kolumner (bra för större skärmar):**
```html
<div class="koha-shelf" data-shelf-id="1" data-columns="2"></div>
```

**3 kolumner (standard):**
```html
<div class="koha-shelf" data-shelf-id="1"></div>
```

**4 kolumner (kompakt):**
```html
<div class="koha-shelf" data-shelf-id="1" data-columns="4"></div>
```

**6 kolumner (mycket kompakt):**
```html
<div class="koha-shelf" data-shelf-id="1" data-columns="6"></div>
```

### Exempel med olika kortstorlekar

**Små kort:**
```html
<div class="koha-shelf" data-shelf-id="1" data-card-size="small"></div>
```

**Standard kort:**
```html
<div class="koha-shelf" data-shelf-id="1"></div>
```

**Stora kort:**
```html
<div class="koha-shelf" data-shelf-id="1" data-card-size="large"></div>
```

## Responsivitet

Widgeten är helt responsiv och anpassar sig automatiskt:

- **Mobil (< 640px):** 1 kolumn
- **Tablet (640px - 960px):** 2 kolumner
- **Desktop (> 960px):** Ditt valda antal kolumner

## Laddningstillstånd

Widgeten visar automatiskt:
1. **Laddning:** UIkit spinner med text "Laddar bokhylla..."
2. **Fel:** Varningsmeddelande om laddning misslyckas
3. **Tom hylla:** Information om inga böcker finns
4. **Lyckad laddning:** Grid med bokkort

## Vad visas på varje kort?

Varje bokkort innehåller:
- ✅ Bokomslag (med fallback om saknas)
- ✅ Titel (klickbar länk)
- ✅ Författare
- ✅ Beskrivning/abstract (trunkerad till 150 tecken)
- ✅ "Visa i katalogen"-knapp (öppnas i ny flik)

## API-integration

Widgeten anropar:
```
GET /integrationer/integration-koha-web/shelf.php?shelf_id={ID}
```

Förväntat svar:
```json
{
  "status": "ok",
  "shelf_id": 1,
  "shelf_name": "Nyheter",
  "books": [
    {
      "biblio_id": 71069,
      "title": "Bokens titel",
      "author": "Författarens namn",
      "abstract": "Beskrivning av boken...",
      "image_url": "https://...",
      "image_cached_url": "https://...",
      "catalog_link": "https://..."
    }
  ]
}
```

## Prestanda

- **Asynkron laddning:** Blockerar inte sidladdning
- **Lazy loading:** Bilder laddas endast när de syns
- **Caching:** API:et cachar data för snabbare laddning
- **Fallback:** Placeholder-bild för saknade omslag

## Felsökning

### Inget visas
- Kontrollera att `data-shelf-id` är korrekt
- Öppna Developer Console (F12) och kolla efter fel
- Verifiera att `shelf.php` är tillgänglig

### Felmeddelande visas
- Kontrollera att shelf-id:t existerar i Koha
- Verifiera API-URL i Developer Console → Network

### Bilderna laddas inte
- Kontrollera att bildsökvägar är korrekta
- Verifiera CORS-inställningar om bilderna kommer från annan domän

## Manuel initialisering

Om du lägger till shelf-element dynamiskt via JavaScript:

```javascript
// Initiera alla shelves
KohaShelf.init();

// Kontrollera version
console.log('Koha Shelf Widget version:', KohaShelf.version);
```

## Browser-support

Fungerar i alla moderna webbläsare:
- ✅ Chrome/Edge (senaste)
- ✅ Firefox (senaste)
- ✅ Safari (senaste)
- ✅ Opera (senaste)

## Exempel

Se [example.html](example.html) för fullständiga exempel.

## Licens

MIT License

## Support

För frågor eller problem, kontakta utvecklingsavdelningen.
