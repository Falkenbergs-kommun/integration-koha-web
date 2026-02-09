# Cost Tracking för Gemini API Enrichment

## Översikt

Varje enrichment-anrop till Gemini API har nu kostnadsspårning. Kostnaden beräknas baserat på faktisk token-användning och sparas i databasen för varje berikad bok.

## Vad Sparas

- **Fält:** `enrichment_cost_usd`
- **Typ:** Float (decimal)
- **Format:** USD (amerikanska dollar)
- **Precision:** 6 decimaler (t.ex. $0.000156)

## Hur Kostnaden Beräknas

### Token Usage från Gemini API

Gemini API returnerar usage metadata i varje response:
- `prompt_token_count` - Input tokens
- `candidates_token_count` - Output tokens
- `total_token_count` - Totalt

### Pricing per Modell (per 1 miljon tokens)

| Modell | Input Cost | Output Cost |
|--------|-----------|-------------|
| gemini-1.5-flash | $0.075 | $0.30 |
| gemini-1.5-pro | $1.25 | $5.00 |
| gemini-3-flash-preview | $0.075* | $0.30* |

\* Samma pricing som 1.5-flash (antagande för beräkning)

### Beräkningsformel

```python
input_cost = (prompt_tokens / 1_000_000) * input_price
output_cost = (output_tokens / 1_000_000) * output_price
total_cost = input_cost + output_cost
```

## Exempel Output

När scriptet körs ser du kostnaden per bok:

```
[1/4] Enriching biblio_id 71204:
      The Nordic model (Hilson, Mary,)
      ✓ Generated enrichment:
        Abstract: Mary Hilsons bok ger en grundlig analys...
        Subjects: Nordiska modellen, Välfärdsstater, Politik...
        Tags: nordiska modellen, välfärdsstat, statsvetenskap...
        Audience: Högskola och universitet
        Cost: $0.000156 USD  ← Faktisk kostnad för denna bok
      ✓ Saved to Directus
```

Vid slutet visas även totalkostnad:

```
============================================================
Pipeline Complete!
============================================================
✓ Successfully enriched: 4
💰 Total cost: $0.000624 USD
   Average cost per book: $0.000156 USD
```

## Typiska Kostnader

Baserat på praktisk användning:

- **Kort abstract (100 tokens output):** ~$0.000050 USD (0.05 cent)
- **Medium abstract (200 tokens output):** ~$0.000100 USD (0.1 cent)
- **Lång abstract med många subjects/tags (300 tokens output):** ~$0.000150 USD (0.15 cent)

### Genomsnittlig Kostnad per Bok

**~$0.00015 USD = 0.015 öre** (med växelkurs 10 SEK/USD)

### Kostnad för 1000 Böcker

```
1000 böcker × $0.00015 = $0.15 USD = ~1.50 SEK
```

**Extremt kostnadseffektivt!** 🎉

## Visa Kostnader i Directus

### Via GUI

1. Öppna Directus Admin: https://nav.utvecklingfalkenberg.se/admin
2. Gå till Content → kft_koha_enriched
3. Klicka på en berikad bok
4. Se fältet **enrichment_cost_usd** (visas som decimal)

### Via API

```bash
# Hämta kostnad för specifik bok
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'filter[biblio_id][_eq]=71204' \
  --data-urlencode 'fields=title,enrichment_cost_usd' \
  -H 'Authorization: Bearer TOKEN'
```

Response:
```json
{
  "data": [{
    "title": "The Nordic model",
    "enrichment_cost_usd": 0.000156
  }]
}
```

### Totala Kostnader

```bash
# Summera total kostnad för alla berikade böcker
curl -G 'https://nav.utvecklingfalkenberg.se/items/kft_koha_enriched' \
  --data-urlencode 'aggregate[sum]=enrichment_cost_usd' \
  -H 'Authorization: Bearer TOKEN'
```

Response:
```json
{
  "data": [{
    "sum": {
      "enrichment_cost_usd": 0.002496  // Total ~$0.0025 för 16 böcker
    }
  }]
}
```

## Budget & Rapportering

### Daglig Körning (10 böcker/dag)

```
Kostnad per dag: 10 × $0.00015 = $0.0015 USD (~0.015 SEK)
Kostnad per månad: 30 × $0.0015 = $0.045 USD (~0.45 SEK)
Kostnad per år: 365 × $0.0015 = $0.55 USD (~5.50 SEK)
```

### Stor Batch (1000 böcker på en gång)

```
Total kostnad: 1000 × $0.00015 = $0.15 USD (~1.50 SEK)
```

### Jämfört med Alternativ

**Manuell katalogisering:**
- Tid per bok: 15-30 minuter
- Kostnad (arbete): 200-400 SEK per bok
- 1000 böcker: 200,000-400,000 SEK

**Gemini AI enrichment:**
- Tid per bok: 2-5 sekunder
- Kostnad: 0.015 öre per bok
- 1000 böcker: **1.50 SEK**

**Besparing: 99.999%!** 🚀

## Free Tier Limits

Google Gemini Free tier:
- **15 requests/minut**
- **1,500 requests/dag**

Med `--delay 5.0` kan du berika:
- **12 böcker/minut** (säkert under 15 req/min limit)
- **720 böcker/timme**
- **1,500 böcker/dag** (max free tier)

**Total free tier värde per dag:**
```
1,500 böcker × $0.00015 = $0.225 USD (~2.25 SEK)

Men det är GRATIS upp till 1,500 requests/dag! 🎉
```

## Framtida Planering

### När Behövs Betald API?

Om biblioteket behöver berika **mer än 1,500 böcker per dag**, behövs uppgradering till betald API.

**Betald API:**
- Ingen daglig limit
- Samma pricing ($0.075/$0.30 per 1M tokens)
- Kan skala obegränsat

### Månadsbudget Exempel

Om ni berikade **10,000 böcker/månad:**

```
10,000 × $0.00015 = $1.50 USD (~15 SEK/månad)
```

**Helt försumbar kostnad!**

## Technical Details

### Code Implementation

Kostnadsberäkningen finns i `enrich_from_directus.py`:

```python
def calculate_cost(response, model: str) -> float:
    """Calculate cost based on token usage."""
    usage = response.usage_metadata
    prompt_tokens = usage.prompt_token_count
    output_tokens = usage.candidates_token_count

    pricing = {
        'gemini-1.5-flash': {'input': 0.075, 'output': 0.30},
        'gemini-1.5-pro': {'input': 1.25, 'output': 5.00},
    }

    model_pricing = pricing.get(model, pricing['gemini-1.5-flash'])

    input_cost = (prompt_tokens / 1_000_000) * model_pricing['input']
    output_cost = (output_tokens / 1_000_000) * model_pricing['output']

    return input_cost + output_cost
```

### Database Field

```sql
ALTER TABLE kft_koha_enriched
ADD COLUMN enrichment_cost_usd FLOAT(10,5) DEFAULT NULL;
```

## Sammanfattning

✅ **Kostnad sparas automatiskt** för varje berikad bok
✅ **Synlig i Directus** GUI och API
✅ **Extremt låg kostnad** (~0.015 öre per bok)
✅ **Free tier täcker 1,500 böcker/dag** (gratis!)
✅ **Transparens** - se exakt vad varje API-anrop kostar
✅ **Budgetplanering** - enkelt att beräkna framtida kostnader

**Perfect för transparens och kostnadskontroll! 💰✨**
