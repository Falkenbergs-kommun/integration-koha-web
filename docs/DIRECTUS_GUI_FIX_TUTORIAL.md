# Directus GUI Tutorial: Fixa JSON-fält (Komplett Guide)

## 📋 Översikt

Denna guide visar **exakt** hur du fixar JSON-fält i Directus Admin UI utan att behöva använda SQL-kommandon. Följ varje steg noggrant.

**Tidsåtgång:** ~15 minuter
**Svårighetsgrad:** Lätt
**Vad du behöver:** Tillgång till Directus Admin UI

---

## 🎯 Mål

- [x] Ta bort fält med fel datatyp (LONGTEXT)
- [x] Återskapa fält med korrekt datatyp (JSON)
- [x] Re-importera data
- [x] Verifiera att allt fungerar

---

## 📝 Steg-för-Steg Instruktioner

### STEG 1: Logga in i Directus Admin

1. Öppna webbläsare
2. Gå till: `https://nav.utvecklingfalkenberg.se/admin`
3. Logga in med dina credentials
4. Du bör nu se Directus Dashboard

**✅ Checkpoint:** Du ser huvudmenyn med "Content", "User Directory", "File Library", "Insights", "Settings"

---

### STEG 2: Navigera till Data Model

1. Klicka på **"Settings"** (kugghjulet) i vänstermenyn (längst ner)
2. I Settings-menyn, klicka på **"Data Model"**
3. Du ser nu en lista över alla collections

**✅ Checkpoint:** Du ser en lista med collections inkl. "kft_koha_enriched"

---

### STEG 3: Öppna kft_koha_enriched Collection

1. I listan över collections, hitta och klicka på **"kft_koha_enriched"**
2. Du ser nu alla fält i denna collection
3. Leta efter fälten: `subjects`, `tags`, `grounding_search_queries`, `grounding_sources`

**✅ Checkpoint:** Du ser fältlistan med en massa fält som id, biblio_id, isbn_clean, osv.

---

### STEG 4: Ta bort fältet "subjects" (första av fyra)

Vi börjar med `subjects`-fältet:

1. **Leta upp fältet "subjects"** i fältlistan
2. **Hovra över fältet** - du bör se tre prickar (⋮) eller ett "Edit"-ikon på höger sida
3. **Klicka på de tre prickarna** (⋮)
4. Välj **"Delete Field"** (röd text)
5. En bekräftelsedialog visas: **"Are you sure you want to delete this field?"**
6. Klicka **"Delete"** (röd knapp)

**✅ Checkpoint:** Fältet "subjects" är nu borta från listan

**⚠️ OBS:** Detta raderar även datan i fältet! Men vi kommer re-importera den senare.

---

### STEG 5: Ta bort resterande felaktiga fält

Upprepa **Steg 4** för följande fält (ett i taget):

- [ ] `tags`
- [ ] `grounding_search_queries`
- [ ] `grounding_sources`

För varje fält:
1. Hitta fältet i listan
2. Klicka ⋮ (tre prickar)
3. "Delete Field"
4. Bekräfta "Delete"

**✅ Checkpoint:** Alla fyra fälten (subjects, tags, grounding_search_queries, grounding_sources) är nu borttagna

---

### STEG 6: Skapa "subjects"-fält med JSON-typ

Nu skapar vi fältet på nytt med **korrekt JSON-typ**:

1. I fältlistan för `kft_koha_enriched`, klicka på **"+ Create Field"** (blå knapp, oftast uppe till höger)
2. En modal öppnas: "Create Field"

#### 6.1 Välj Field Type

1. Du ser en lista med field types (String, Text, Boolean, JSON, osv.)
2. **Klicka på "JSON"** (inte "Text" eller "String"!)
3. Klicka **"Continue"** längst ner

#### 6.2 Konfigurera Field

Under **"Key"** (fältets namn):
- Skriv: `subjects`

Under **"Interface"** (hur fältet visas):
- Välj: **"Tags"**
- ⚠️ **VIKTIGT:** Välj INTE "List" eller "Repeater" - dessa kräver field definitions!
- "Tags" interface är perfekt för simple string arrays
- Om "Tags" inte finns, välj "Input" och sätt Language till JSON

Under **"Schema"** tab (klicka på "Schema" tab om den inte syns):
- **Type:** Bör stå "JSON" (gråmarkerad, kan inte ändras)
- **Nullable:** ✓ (checkad)
- **Indexed:** (låt vara tom)

Under **"Field"** tab (klicka på "Field"):
- **Note:** Skriv: `Array of subject categories`
- **Width:** Välj "Half"

Under **"Interface"** tab (om tillgänglig):
- **Allow Custom Values:** ✓ (checked - låter dig lägga till egna värden)
- **Placeholder:** "Add subject..."

Klicka **"Save"** (blå knapp längst upp till höger)

**✅ Checkpoint:** Fältet "subjects" finns nu i fältlistan med typ "JSON"

---

### STEG 7: Skapa "tags"-fält med JSON-typ

Upprepa **Steg 6** men med dessa värden:

1. Klicka **"+ Create Field"**
2. Välj field type: **"JSON"**
3. Click "Continue"
4. Konfigurera:
   - **Key:** `tags`
   - **Interface:** **"Tags"** (VIKTIGT: Tags, inte List!)
   - **Note:** `Array of searchable tags`
   - **Width:** Half
   - **Interface Options:**
     - Allow Custom Values: ✓
     - Alphabetize: ✓ (optional)
     - Placeholder: "Add tag..."
5. Klicka **"Save"**

**✅ Checkpoint:** Fältet "tags" finns nu i fältlistan

---

### STEG 8: Skapa "grounding_search_queries"-fält

Upprepa **Steg 6** men med dessa värden:

1. Klicka **"+ Create Field"**
2. Välj field type: **"JSON"**
3. Click "Continue"
4. Konfigurera:
   - **Key:** `grounding_search_queries`
   - **Interface:** **"Tags"** (INTE List!)
   - **Note:** `Search queries used by AI for grounding`
   - **Width:** Half
   - **Interface Options:**
     - Allow Custom Values: ✓
     - Placeholder: "Add query..."
5. Klicka **"Save"**

**✅ Checkpoint:** Fältet "grounding_search_queries" finns nu i fältlistan

---

### STEG 9: Skapa "grounding_sources"-fält

⚠️ **Detta fält är annorlunda** - det innehåller array av objekt (inte bara strings):

```json
[
  {"uri": "https://example.com", "title": "Example"},
  {"uri": "https://another.com", "title": "Another"}
]
```

**Enklaste lösningen:**

1. Klicka **"+ Create Field"**
2. Välj field type: **"JSON"**
3. Click "Continue"
4. Konfigurera:
   - **Key:** `grounding_sources`
   - **Interface:** **"Input"** (välj Code/JSON interface)
   - **Note:** `Sources used by AI with URIs and titles`
   - **Width:** Full
   - **Interface Options** (om tillgängligt):
     - Language: JSON
     - Line Numbers: ✓
5. Klicka **"Save"**

**ℹ️ Info:** Detta kommer visa råa JSON i GUI, vilket är OK för detta fält eftersom det är huvudsakligen read-only grounding-information.

**✅ Checkpoint:** Alla fyra fält är nu återskapade med JSON-typ

---

### STEG 10: Verifiera Field Types

Låt oss kontrollera att fälten har korrekt typ:

1. Fortfarande i Data Model för `kft_koha_enriched`
2. För varje fält (subjects, tags, grounding_search_queries, grounding_sources):
   - Klicka på fältnamnet för att öppna edit-modal
   - Klicka på **"Schema"** tab
   - **Verifiera:** Under "Type" bör det stå **"JSON"** (inte "text" eller "longtext")
   - Klicka "Cancel" eller "X" för att stänga
3. Om något fält har fel typ, ta bort det och återskapa enligt steg ovan

**✅ Checkpoint:** Alla fyra fält har Type: "JSON"

---

### STEG 11: Re-importera Data via Terminal/SSH

Nu när fälten har korrekt typ, re-importera datan:

1. Öppna terminal/SSH till servern
2. Navigera till projekt-directory:
   ```bash
   cd /home/httpd/fbg-intranet/integrationer/integration-koha-web
   ```

3. Kör import-skriptet:
   ```bash
   php docs/import-enriched-data.php
   ```

4. Du bör se:
   ```
   Reading enriched data from: .../enrich/enriched_books.json
   Found 10 enriched biblios to import

   Processing biblio_id: 71069 (0/10)
     Found existing record with id: 11 - updating
     ✓ Success (PATCH)

   [... mer output ...]

   ===========================================
   Import complete!
     Success: 10
     Errors: 0
   ===========================================
   ```

**✅ Checkpoint:** Import slutfördes utan fel (Success: 10, Errors: 0)

---

### STEG 12: Verifiera i Directus GUI

Nu kontrollerar vi att allt fungerar i GUI:n:

1. I Directus Admin, gå tillbaka till huvudmenyn
2. Klicka på **"Content"** i vänstermenyn
3. Hitta och klicka på **"kft_koha_enriched"**
4. Du ser nu en tabell med alla importerade böcker
5. Klicka på **första raden** (biblio_id: 71069) för att öppna posten

#### 12.1 Kontrollera "subjects"-fältet

- Scrolla ner till fältet **"subjects"**
- Du bör se en **lista med ämnesord**:
  - Samhällskunskap
  - Nationalekonomi
  - Statsvetenskap
  - Demokrati
  - Sociologi
  - Mänskliga rättigheter
- **Du bör INTE se** en lång textsträng som `["Samhällskunskap",...]`
- **Du bör INTE se** felmeddelandet "not compatible with repeater interface"

✅ **BINGO!** Om du ser en snygg lista = Success!

#### 12.2 Kontrollera "tags"-fältet

- Scrolla till fältet **"tags"**
- Du bör se **"tag pills"** (små boxar med taggar):
  - samhällskunskap
  - gymnasieskolan
  - läromedel
  - gy25
  - demokrati
  - ... osv
- Om tags-fältet har "Tags"-interface bör de se ut som klickbara pills/badges

✅ **BINGO!** Om du ser taggar = Success!

#### 12.3 Kontrollera övriga fält

- **grounding_search_queries:** Bör visa som lista med sökfrågor
- **grounding_sources:** Bör visa JSON-objekt eller lista med källor

**✅ Checkpoint:** Alla fält visar korrekt formaterad data utan felmeddelanden

---

### STEG 13: Testa redigering (Optional)

För att säkerställa att allt fungerar:

1. I samma post (biblio_id 71069)
2. Scrolla till **"subjects"**
3. Klicka på **"+ Add New"** under subjects-listan
4. Skriv in ett nytt ämne, t.ex. "Test Subject"
5. Klicka utanför fältet (eller Enter)
6. Klicka **"Save"** (blå knapp uppe till höger)
7. Om det sparar utan fel = Success!
8. Ta bort "Test Subject" och spara igen för att rensa testet

**✅ Checkpoint:** Du kan lägga till och ta bort items i JSON-fälten utan fel

---

## 🎉 KLART! Du har fixat JSON-fälten!

### Vad du har åstadkommit:

- ✅ Tagit bort felaktiga LONGTEXT-fält
- ✅ Skapat nya fält med korrekt JSON-datatyp
- ✅ Re-importerat all data
- ✅ Verifierat att Directus GUI fungerar korrekt
- ✅ Inga fler "not compatible with repeater interface"-fel

---

## 🔍 Felsökning

### Problem: "subjects" visar fortfarande som lång textsträng

**Lösning:**
1. Gå tillbaka till Settings → Data Model → kft_koha_enriched → subjects
2. Klicka på "Schema" tab
3. Verifiera att Type är "JSON" (inte "text")
4. Om Type är "text", radera fältet och återskapa det (se Steg 6)
5. Re-importera data igen

### Problem: Import-skriptet misslyckas med "Invalid JSON"

**Lösning:**
```bash
# Kontrollera att enriched_books.json är giltig JSON
php -r "json_decode(file_get_contents('enrich/enriched_books.json')); echo json_last_error_msg();"

# Bör visa: "No error"
```

### Problem: Kan inte hitta "JSON" som field type

**Orsak:** Din Directus-version kanske inte stöder JSON-typ fullt ut.

**Lösning (workaround):**
1. Skapa fält som "Text" istället för "JSON"
2. Använd Interface: "Input (Multiline)" eller "Code (JSON)"
3. Data kommer fortfarande vara strängar, men GUI kan visa dem bättre

Alternativt: Uppgradera Directus till senaste version som stöder JSON.

### Problem: Efter import visar fälten som JSON-strängar igen

**Orsak:** Databas-kolumntypen är fortfarande LONGTEXT.

**Lösning:** Du måste ändra direkt i databasen:
```sql
ALTER TABLE kft_koha_enriched
    MODIFY COLUMN subjects JSON,
    MODIFY COLUMN tags JSON,
    MODIFY COLUMN grounding_search_queries JSON,
    MODIFY COLUMN grounding_sources JSON;
```

Eller använd SQL-skriptet: `recreate-collection-with-json.sql`

---

## 📚 Relaterade Guider

- **FIX_JSON_FIELDS.md** - Alla fix-alternativ och felsökning
- **QUICK_START_ENRICHED.md** - Snabbguide för API-användning
- **ENRICHED_DATA_SETUP.md** - Komplett setup-guide

---

## 💡 Tips för Framtiden

När du skapar nya collections med JSON-fält:

1. **Använd alltid SQL först** för att skapa tabellen med JSON-typ
2. Låt Directus "upptäcka" tabellen istället för att skapa via API
3. Eller: Skapa via GUI och välj explicit "JSON" som field type

**Rekommenderad metod:**
```sql
CREATE TABLE my_collection (
    id INT PRIMARY KEY AUTO_INCREMENT,
    my_json_field JSON  -- Explicit JSON-typ
);
```

Sedan i Directus: Settings → Data Model → "Sync from Database"

---

## 🆘 Behöver du hjälp?

Om du fastnar:
1. Ta screenshots av varje steg där du fastnar
2. Notera exakt felmeddelande
3. Kontakta systemadministratör eller projektutvecklare

**Support-filer att inkludera:**
- Output från `php docs/import-enriched-data.php`
- Screenshot från Directus Data Model för fältet
- Output från `DESCRIBE kft_koha_enriched;` (om du har SQL-access)

---

**✨ Lycka till! Du klarar det här! ✨**
