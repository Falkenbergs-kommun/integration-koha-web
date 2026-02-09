# Quick Reference: Field Configuration

**Ha denna fil uppe på skärmen medan du skapar fälten i Directus!**

---

## Fält 1: subjects

```
Field Type:  JSON
Key:         subjects
Interface:   Tags
  └─ (INTE List/Repeater - det kräver field definitions!)
Width:       Half
Note:        Array of subject categories

Schema:
  Type:      json (auto-set)
  Nullable:  ✓ (checked)

Interface Options:
  Allow Custom Values: ✓ (checked)
  Placeholder: "Add subject..."
```

---

## Fält 2: tags

```
Field Type:  JSON
Key:         tags
Interface:   Tags
  └─ (Perfekt för simple string arrays)
Width:       Half
Note:        Array of searchable tags

Schema:
  Type:      json (auto-set)
  Nullable:  ✓ (checked)

Interface Options:
  Allow Custom Values: ✓ (checked)
  Placeholder: "Add tag..."
  Alphabetize: ✓ (checked)
```

---

## Fält 3: grounding_search_queries

```
Field Type:  JSON
Key:         grounding_search_queries
Interface:   Tags
  └─ (INTE List - det är bara strings, inte objekt!)
Width:       Half
Note:        Search queries used by AI for grounding

Schema:
  Type:      json (auto-set)
  Nullable:  ✓ (checked)

Interface Options:
  Allow Custom Values: ✓ (checked)
  Placeholder: "Add query..."
```

---

## Fält 4: grounding_sources

⚠️ **Detta fält är komplext - array av objekt med {uri, title}**

### Alternativ A: Input (Enklast - visa råa JSON)

```
Field Type:  JSON
Key:         grounding_sources
Interface:   Input (Code)
Width:       Full
Note:        Sources used by AI with URIs and titles

Schema:
  Type:      json (auto-set)
  Nullable:  ✓ (checked)

Interface Options:
  Language: JSON
  Line Numbers: ✓
```

### Alternativ B: Repeater (Snyggare - men mer komplext)

Om du vill använda Repeater, läs REPEATER_SETUP.md för hur man konfigurerar fält-strukturen.

**Rekommendation:** Använd **Alternativ A (Input)** för enklast setup.

---

## ⚠️ VIKTIGT!

- **Field Type** måste vara **JSON** (INTE "Text" eller "String"!)
- **Key** måste stava EXAKT som ovan (inkl. understreck)
- **Schema → Type** bör automatiskt bli "json" när du väljer JSON field type
- Om Schema → Type blir "text" eller "longtext" = FEL!

---

## Visuell Kontroll Efter Skapande

När du är klar och öppnar en post i Content:

### ✅ RÄTT (så här ska det se ut):

**subjects:**
```
• Samhällskunskap
• Nationalekonomi
• Statsvetenskap
```
(Lista med items, varje item på egen rad)

**tags:**
```
[samhällskunskap] [gymnasieskolan] [läromedel]
```
(Klickbara "pills" eller badges)

### ❌ FEL (så här ska det INTE se ut):

**subjects:**
```
["Samhällskunskap","Nationalekonomi","Statsvetenskap"]
```
(En lång textsträng)

**tags:**
```
["samhällskunskap","gymnasieskolan","läromedel"]
```
(En lång textsträng)

---

## Copy-Paste Värden

För snabb kopiering (Key-värden):

```
subjects
tags
grounding_search_queries
grounding_sources
```

För snabb kopiering (Notes):

```
Array of subject categories
Array of searchable tags
Search queries used by AI for grounding
Sources used by AI with URIs and titles
```

---

**Lycka till! 🚀**
