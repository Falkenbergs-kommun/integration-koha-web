# JSON-fält Fix Checklista ✓

**Skriv ut denna checklista och bocka av när du går igenom tutorialen!**

---

## DEL 1: Förberedelse (5 min)

- [ ] Öppnat Directus Admin UI (https://nav.utvecklingfalkenberg.se/admin)
- [ ] Loggat in framgångsrikt
- [ ] Navigerat till Settings → Data Model
- [ ] Hittat collection "kft_koha_enriched"
- [ ] Öppnat collection och ser fältlistan

---

## DEL 2: Ta bort felaktiga fält (5 min)

- [ ] **subjects** - Raderat (⋮ → Delete Field → Delete)
- [ ] **tags** - Raderat (⋮ → Delete Field → Delete)
- [ ] **grounding_search_queries** - Raderat (⋮ → Delete Field → Delete)
- [ ] **grounding_sources** - Raderat (⋮ → Delete Field → Delete)

**✓ Alla fyra fält är nu borta från fältlistan**

---

## DEL 3: Skapa nya fält med JSON-typ (10 min)

### Fält 1: subjects

- [ ] Klickat "+ Create Field"
- [ ] Valt Field Type: **JSON**
- [ ] Klickat "Continue"
- [ ] Satt Key: `subjects`
- [ ] Valt Interface: **Tags** (INTE List!)
- [ ] Satt Width: Half
- [ ] Satt Note: `Array of subject categories`
- [ ] Interface Options: Allow Custom Values ✓
- [ ] Verifierat Schema → Type: **JSON**
- [ ] Klickat "Save"

### Fält 2: tags

- [ ] Klickat "+ Create Field"
- [ ] Valt Field Type: **JSON**
- [ ] Klickat "Continue"
- [ ] Satt Key: `tags`
- [ ] Valt Interface: **Tags**
- [ ] Satt Width: Half
- [ ] Satt Note: `Array of searchable tags`
- [ ] Interface Options: Allow Custom Values ✓, Alphabetize ✓
- [ ] Verifierat Schema → Type: **JSON**
- [ ] Klickat "Save"

### Fält 3: grounding_search_queries

- [ ] Klickat "+ Create Field"
- [ ] Valt Field Type: **JSON**
- [ ] Klickat "Continue"
- [ ] Satt Key: `grounding_search_queries`
- [ ] Valt Interface: **Tags** (INTE List!)
- [ ] Satt Width: Half
- [ ] Satt Note: `Search queries used by AI`
- [ ] Interface Options: Allow Custom Values ✓
- [ ] Verifierat Schema → Type: **JSON**
- [ ] Klickat "Save"

### Fält 4: grounding_sources

- [ ] Klickat "+ Create Field"
- [ ] Valt Field Type: **JSON**
- [ ] Klickat "Continue"
- [ ] Satt Key: `grounding_sources`
- [ ] Valt Interface: **Input** (Code/JSON)
- [ ] Satt Width: Full
- [ ] Satt Note: `Sources with URIs and titles`
- [ ] Interface Options: Language: JSON, Line Numbers ✓
- [ ] Verifierat Schema → Type: **JSON**
- [ ] Klickat "Save"

**✓ Alla fyra fält är återskapade med JSON-typ**

---

## DEL 4: Verifiering i Data Model (2 min)

- [ ] subjects → Klickat på fält → Schema tab → Type: JSON ✓
- [ ] tags → Klickat på fält → Schema tab → Type: JSON ✓
- [ ] grounding_search_queries → Klickat på fält → Schema tab → Type: JSON ✓
- [ ] grounding_sources → Klickat på fält → Schema tab → Type: JSON ✓

**✓ Alla fält har korrekt JSON-typ**

---

## DEL 5: Re-importera Data (3 min)

### Via Terminal/SSH:

- [ ] SSH:at in till server
- [ ] Navigerat till: `/home/httpd/fbg-intranet/integrationer/integration-koha-web`
- [ ] Kört: `php docs/import-enriched-data.php`
- [ ] Sett output: "Success: 10, Errors: 0"
- [ ] Ingen felmeddelande visades

**✓ Import slutfördes framgångsrikt**

---

## DEL 6: Verifiering i Directus GUI (5 min)

- [ ] Navigerat till Content → kft_koha_enriched
- [ ] Ser 10 biblios i listan
- [ ] Öppnat första posten (biblio_id 71069)

### Kontroll av fält:

- [ ] **subjects** - Visar som LISTA (inte textsträng)
  - Samhällskunskap
  - Nationalekonomi
  - Statsvetenskap
  - osv...

- [ ] **tags** - Visar som PILLS/BADGES (inte textsträng)
  - samhällskunskap
  - gymnasieskolan
  - läromedel
  - osv...

- [ ] **grounding_search_queries** - Visar som lista med sökfrågor

- [ ] **grounding_sources** - Visar JSON-struktur korrekt

- [ ] **Inget felmeddelande** om "not compatible with repeater interface"

**✓ Alla fält visar korrekt formaterad data**

---

## DEL 7: Test av redigering (Optional - 2 min)

- [ ] I samma post, scrollat till subjects
- [ ] Klickat "+ Add New"
- [ ] Lagt till "Test Subject"
- [ ] Klickat "Save"
- [ ] Sparades utan fel
- [ ] Tagit bort "Test Subject"
- [ ] Klickat "Save" igen

**✓ Kan redigera JSON-fält utan problem**

---

## 🎉 SAMMANFATTNING

### Checkpoints:

- [ ] **4 fält raderade** från Data Model
- [ ] **4 fält återskapade** med JSON-typ
- [ ] **Data re-importerad** (10 biblios)
- [ ] **GUI visar korrekt** (listor och arrays, inte strängar)
- [ ] **Inga felmeddelanden** i Directus
- [ ] **Kan redigera fält** utan problem

---

## 📸 Dokumentation (Optional men rekommenderat)

Ta screenshots av:

- [ ] Data Model med alla fyra fält (visar JSON-typ)
- [ ] Content view med en post öppen (visar korrekta listor)
- [ ] subjects-fältet som visar array/lista
- [ ] tags-fältet som visar pills/badges

Spara screenshots i: `docs/screenshots/` för framtida referens.

---

## ⏱️ Total tidsåtgång: ~30 minuter

**Datum genomfört:** _______________

**Genomfört av:** _______________

**Status:** ✅ Klart / ⏳ Pågående / ❌ Problem

**Anteckningar/Problem:**
_____________________________________________
_____________________________________________
_____________________________________________

---

## 🆘 Om något går fel

**Kontakta:** [Din systemadministratör]

**Bifoga:**
- Denna checklista (ifylld)
- Screenshots av problemet
- Output från import-skriptet
- Felmeddelanden från Directus

**Support-filer:**
- `docs/DIRECTUS_GUI_FIX_TUTORIAL.md` (fullständig guide)
- `docs/FIX_JSON_FIELDS.md` (felsökning)
- `recreate-collection-with-json.sql` (SQL-alternativ)

---

**Lycka till! 🚀**
