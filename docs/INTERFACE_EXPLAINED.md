# Directus Interfaces Förklaring: Tags vs List/Repeater

## ⚠️ Viktigt att förstå!

När du skapar JSON-fält i Directus finns det olika **Interfaces** att välja mellan. Valet av interface beror på **vilken typ av data** fältet innehåller.

---

## 📊 Datatyper och Rätt Interface

### 1. Simple String Array (Strängar)

**Exempel:**
```json
["Samhällskunskap", "Demokrati", "Statsvetenskap"]
```

**Rätt Interface:** **Tags** eller **Input (JSON)**

**Fel Interface:** ~~List~~ eller ~~Repeater~~

**Varför?**
- "Tags" interface är designat för simple string arrays
- "List/Repeater" är för **array av objekt** med definierade fält
- Om du använder List/Repeater för strings får du:
  - ❌ "not compatible with repeater interface"
  - ❌ GUI kan inte visa data
  - ❌ Du måste definiera field structure (men det finns ingen - det är bara strings!)

---

### 2. Array av Objekt med Fält

**Exempel:**
```json
[
  {"uri": "https://example.com", "title": "Example"},
  {"uri": "https://another.com", "title": "Another"}
]
```

**Rätt Interface (2 alternativ):**

#### Alternativ A: **Input (Code)** - Enklast
- Visar råa JSON
- Bra för read-only eller sällan ändrad data
- Ingen setup krävs

#### Alternativ B: **Repeater** - Snyggare
- Visar varje objekt som formulär
- Bra för frequently ändrad data
- **Kräver:** Du måste definiera repeater fields (uri, title)

**Fel Interface:** ~~Tags~~ (Tags funkar bara för strings)

---

## 🎯 Våra Fält och Rätt Interface

### subjects (String Array)
```json
["Samhällskunskap", "Nationalekonomi", "Statsvetenskap"]
```
✅ **Interface: Tags**
- Varje subject är en string
- Tags låter dig lägga till/ta bort subjects lätt
- Visar som klickbara pills/badges

❌ **INTE List/Repeater** - det finns inga fält att definiera!

---

### tags (String Array)
```json
["samhällskunskap", "gymnasieskolan", "läromedel"]
```
✅ **Interface: Tags**
- Perfekt för tagging
- Kan autocomplete
- Visar som pills

❌ **INTE List/Repeater** - det är bara strings!

---

### grounding_search_queries (String Array)
```json
["Query 1", "Query 2"]
```
✅ **Interface: Tags**
- Varje query är en string
- Lätt att lägga till nya queries

❌ **INTE List/Repeater** - bara strings!

---

### grounding_sources (Array av Objekt)
```json
[
  {"uri": "https://...", "title": "bokus.com"},
  {"uri": "https://...", "title": "ne.se"}
]
```

✅ **Interface: Input (Code)** - Rekommenderat för detta projekt
- Visar råa JSON
- Enkel setup
- Bra eftersom detta är grounding-info (sällan ändras manuellt)

✅ **Interface: Repeater** - Alternativ (mer komplext)
- Kräver field setup (se nedan)
- Snyggare GUI
- Bra om du ofta redigerar källor manuellt

---

## 🔧 Hur man Sätter upp Repeater (för grounding_sources)

Om du vill använda Repeater interface för `grounding_sources`:

### Steg 1: Skapa fältet
1. Field Type: JSON
2. Interface: **Repeater**
3. Key: `grounding_sources`

### Steg 2: Definiera Repeater Fields

När du väljer Repeater-interface får du en dialog "Configure Repeater Fields":

**Field 1:**
- Field Name: `uri`
- Field Type: String
- Interface: Input
- Note: "URL to source"

**Field 2:**
- Field Name: `title`
- Field Type: String
- Interface: Input
- Note: "Source title/name"

### Steg 3: Spara

Nu kommer varje source att visas som:
```
Source 1:
  uri:   [https://example.com        ]
  title: [Example Source             ]

Source 2:
  uri:   [https://another.com        ]
  title: [Another Source             ]

[+ Add New]
```

---

## 💡 Sammanfattning: Vilket Interface ska jag välja?

| Data Type | Exempel | Rätt Interface | Fel Interface |
|-----------|---------|----------------|---------------|
| String array | `["a", "b"]` | **Tags** eller Input | ~~List/Repeater~~ |
| Number array | `[1, 2, 3]` | **Input (JSON)** | ~~List/Repeater~~ |
| Object array | `[{"x": 1}]` | **Input** eller Repeater | ~~Tags~~ |
| Mixed array | `["a", 1, {"x": 2}]` | **Input (JSON)** | ~~Allt annat~~ |

---

## 🚫 Varför "not compatible with repeater interface"?

Detta felmeddelande visas när:

1. **Fältet har List/Repeater interface**
2. **Men datan är string array** (inte objekt med definierade fält)

Directus förväntar sig:
```json
[
  {"field1": "value", "field2": "value"},
  {"field1": "value", "field2": "value"}
]
```

Men får istället:
```json
["string1", "string2", "string3"]
```

**Lösning:** Byt till **Tags** interface!

---

## ✅ Best Practices

### För String Arrays (90% av fallen)
```
Field Type: JSON
Interface:  Tags
Options:    Allow Custom Values ✓
```

### För Object Arrays (sällan ändrade)
```
Field Type: JSON
Interface:  Input (Code)
Options:    Language: JSON
```

### För Object Arrays (ofta ändrade)
```
Field Type: JSON
Interface:  Repeater
Setup:      Definiera fält för varje property
```

---

## 🔍 Hur vet jag vilken typ min data har?

Kolla i `enriched_books.json`:

```json
{
  "subjects": ["string", "string"],           // ← String array = Tags
  "tags": ["string", "string"],               // ← String array = Tags
  "grounding_search_queries": ["string"],     // ← String array = Tags
  "grounding_sources": [                      // ← Object array = Input eller Repeater
    {"uri": "...", "title": "..."},
    {"uri": "...", "title": "..."}
  ]
}
```

---

## 🎓 Lär dig mer

- **Tags Interface:** Bäst för simple arrays av strings eller numbers
- **List/Repeater:** Bara för arrays av objekt med definierad struktur
- **Input (JSON):** Universal fallback - funkar för allt, men mindre user-friendly

**Tumregel:** Börja alltid med **Tags** för arrays. Om det inte funkar, byt till **Input**.

---

**Nu förstår du varför vi använder Tags istället för List! 🎉**
