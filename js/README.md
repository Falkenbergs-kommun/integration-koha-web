# Koha Shelf Widget

JavaScript-widget för att visa Koha-bokhyllor som UIkit 3 cards i Yootheme Pro / Joomla.

## Funktioner

- ✅ Asynkron laddning (blockerar inte sidladdning)
- ✅ Snygg laddningsindikator med UIkit spinner
- ✅ Responsiv grid-layout (2-6 kolumner)
- ✅ Karusell/slider-läge med touch-stöd
- ✅ Anpassningsbara kortstorlekar
- ✅ Filtrering på item-typer (t.ex. BARNBOK, BARNDVD)
- ✅ Valfri visning av bokbeskrivningar (abstract)
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

**Visa böcker från en bokhylla:**
```html
<div class="koha-shelf" data-shelf-id="1"></div>
```

**Visa senaste inköpen:**
```html
<div class="koha-shelf" data-source="latest"></div>
```

**Visa endast senaste barnböcker:**
```html
<div class="koha-shelf" data-source="latest" data-item-types="BARNBOK"></div>
```

**Visa som karusell/slider:**
```html
<div class="koha-shelf" data-source="latest" data-display-mode="slider" data-columns="4"></div>
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
| `data-source` | string | shelf | Källa: `shelf` (bokhylla) eller `latest` (senaste inköp) |
| `data-shelf-id` | number | - | **Obligatoriskt för shelf.** ID för bokhyllan från Koha |
| `data-item-types` | string | - | Filtrera senaste böcker på item-typer (kommaseparerat, t.ex. "BARNBOK,BARNDVD") |
| `data-display-mode` | string | grid | Visningsläge: `grid` eller `slider` (karusell) |
| `data-show-abstract` | boolean | true | Visa bokbeskrivningar. Sätt till `false` för att dölja |
| `data-columns` | number | 3 | Antal kolumner i grid eller synliga objekt i slider (2, 3, 4, 5, eller 6) |
| `data-card-size` | string | default | Kortstorlek: `small`, `default`, `large` |
| `data-max-books` | number | - | Max antal böcker att visa (slumpar om fler finns) |
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

### Exempel med senaste inköp

**Visa senaste inköpen (standard 10 böcker):**
```html
<div class="koha-shelf" data-source="latest"></div>
```

**Visa 6 senaste inköpen i 3 kolumner:**
```html
<div class="koha-shelf" data-source="latest" data-max-books="6"></div>
```

**Visa 12 senaste inköpen i 4 kolumner:**
```html
<div class="koha-shelf" data-source="latest" data-columns="4" data-max-books="12"></div>
```

**Visa senaste barnböcker i 3 kolumner:**
```html
<div class="koha-shelf" data-source="latest" data-item-types="BARNBOK" data-columns="3"></div>
```

**Visa 8 senaste barn-DVDs och barnböcker i 4 kolumner:**
```html
<div class="koha-shelf" data-source="latest" data-item-types="BARNDVD,BARNBOK" data-columns="4" data-max-books="8"></div>
```

### Exempel med slider/karusell

**Visa böcker som en slider med 4 synliga böcker:**
```html
<div class="koha-shelf" data-shelf-id="1" data-display-mode="slider" data-columns="4"></div>
```

**Visa senaste barnböcker som slider med 3 synliga böcker:**
```html
<div class="koha-shelf" data-source="latest" data-item-types="BARNBOK" data-display-mode="slider" data-columns="3"></div>
```

**Slider med 5 synliga böcker, utan beskrivningar:**
```html
<div class="koha-shelf" data-source="latest" data-display-mode="slider" data-columns="5" data-show-abstract="false"></div>
```

Slider-läget:
- Visar navigeringsknappar (pil vänster/höger) vid hover
- Stödjer touch/swipe på mobila enheter
- Kolumnantalet anger hur många böcker som syns samtidigt
- Responsivt: 2 böcker på tablet, 1 på mobil (oavsett kolumninställning)

### Exempel med döljda beskrivningar

**Grid utan bokbeskrivningar:**
```html
<div class="koha-shelf" data-shelf-id="1" data-show-abstract="false"></div>
```

**Senaste böcker utan beskrivningar i 4 kolumner:**
```html
<div class="koha-shelf" data-source="latest" data-columns="4" data-show-abstract="false"></div>
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

### Exempel med begränsat antal böcker

**Visa max 5 slumpmässigt valda böcker:**
```html
<div class="koha-shelf" data-shelf-id="1" data-max-books="5"></div>
```

**Visa 8 böcker i 4 kolumner:**
```html
<div class="koha-shelf" data-shelf-id="1" data-columns="4" data-max-books="8"></div>
```

Om API:et returnerar fler böcker än `data-max-books` kommer widgeten att slumpmässigt välja ut det angivna antalet böcker att visa. Detta är användbart för att:
- Skapa dynamisk variation på sidan (olika böcker vid varje sidladdning)
- Begränsa antal böcker när bokhyllan är stor
- Skapa "tips"-sektioner med roterande innehåll

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
- ✅ Bokomslag (enhetlig höjd 400px, anpassad för portrait-format, med fallback om saknas)
- ✅ Titel
- ✅ Författare
- ✅ Beskrivning/abstract (trunkerad till 150 tecken)
- ✅ Hela kortet är klickbart och öppnar boken i katalogen (ny flik)

**Bildhantering:**
- Alla bokomslag visas med samma höjd (400px) för enhetligt utseende
- Bilder använder `object-fit: cover` för att fylla utrymmet utan att förvrängas
- Optimerad för portrait-format (standard bokformat 2:3)
- Texten börjar alltid på samma nivå oavsett bildens ursprungliga storlek

**Interaktion:**
- Hover-effekt visar att kortet är klickbart
- Klick på vilket ställe som helst på kortet öppnar boken i bibliotekskatalogen
- Öppnas i ny flik för att behålla kontexten

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
