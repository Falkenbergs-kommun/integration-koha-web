# Google Gemini AI Enrichment

## Översikt

Detta projekt använder Google Gemini API för att berika (enrich) bibliografisk metadata med AI-genererat innehåll.

## Vad berikas?

För varje bok i bibliotekskatalogen genereras:

- **abstract_enriched**: Utökade, informativa beskrivningar
- **subjects**: Strukturerade ämnesord och kategorier
- **tags**: Sökbara taggar för bättre discovery
- **target_audience**: Automatiskt identifierad målgrupp (Gymnasiet, Allmänheten, etc.)
- **grounding_sources**: Källor och sökfrågor som AI:n använde (för transparens)

## Hämta API-nyckel

1. Gå till [Google AI Studio](https://aistudio.google.com/app/apikey)
2. Logga in med ditt Google-konto
3. Klicka på "Get API key" eller "Create API key"
4. Kopiera nyckeln
5. Lägg till i `.env`:
   ```env
   GEMINI_API_KEY=din-api-nyckel-här
   ```

## API-modeller

Google Gemini erbjuder flera modeller:

- **gemini-2.0-flash-exp**: Snabbaste, gratis tier, bra för bulk-enrichment
- **gemini-1.5-pro**: Mer avancerad, större kontext, högre kostnad
- **gemini-1.5-flash**: Balans mellan hastighet och kvalitet

För detta projekt rekommenderas **gemini-2.0-flash-exp** för utveckling och **gemini-1.5-flash** för produktion.

## Rate Limits (Free Tier)

Google Gemini Free tier har följande begränsningar:

- **Gemini 2.0 Flash**: 15 requests/minut, 1,500 requests/dag
- **Gemini 1.5 Flash**: 15 requests/minut, 1,500 requests/dag
- **Gemini 1.5 Pro**: 2 requests/minut, 50 requests/dag

För större volymer kan du uppgradera till betald API-åtkomst.

## Användning i Projektet

### Manuell Enrichment

Om du har ett skript för att berika böcker (t.ex. `enrich/enrich_books.php`):

```bash
php enrich/enrich_books.php
```

Skriptet kommer att:
1. Läsa böcker från Koha API
2. Använda Gemini API för att generera enriched metadata
3. Spara resultatet i `enrich/enriched_books.json`
4. (Optional) Automatiskt importera till Directus

### Automatisk Import till Directus

Efter enrichment, importera till Directus:

```bash
php docs/import-enriched-data.php
```

## Exempel: PHP-anrop till Gemini API

```php
<?php
require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

$apiKey = getenv('GEMINI_API_KEY');
$model = 'gemini-2.0-flash-exp';
$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";

$prompt = "Skriv en pedagogisk sammanfattning av boken: Nationalencyklopedins samhällskunskap";

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 500
    ]
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$result = json_decode($response, true);
curl_close($ch);

$enrichedText = $result['candidates'][0]['content']['parts'][0]['text'];
echo $enrichedText;
```

## Grounding Search (Källbaserad AI)

För bättre kvalitet kan du använda Gemini med **Google Search grounding**:

```json
{
  "contents": [...],
  "tools": [{
    "googleSearchRetrieval": {
      "dynamicRetrievalConfig": {
        "mode": "MODE_DYNAMIC",
        "dynamicThreshold": 0.7
      }
    }
  }]
}
```

Detta gör att Gemini söker på webben och baserar svar på faktiska källor, vilket ökar trovärdigheten.

## Best Practices

### 1. Batch Processing
Berika böcker i batchar för att undvika rate limits:

```php
$books = getBooks(); // 100 böcker
$batchSize = 15; // 15 requests/minut

foreach (array_chunk($books, $batchSize) as $batch) {
    foreach ($batch as $book) {
        enrichBook($book);
    }
    sleep(60); // Vänta 1 minut mellan batchar
}
```

### 2. Caching
Cacha enriched data för att undvika onödiga API-anrop:

```php
$cacheFile = "cache/enriched_$biblioId.json";
if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 86400) {
    return json_decode(file_get_contents($cacheFile), true);
}

$enriched = callGeminiApi($book);
file_put_contents($cacheFile, json_encode($enriched));
return $enriched;
```

### 3. Error Handling
Hantera API-fel gracefully:

```php
try {
    $enriched = enrichBook($book);
} catch (Exception $e) {
    error_log("Failed to enrich biblio $biblioId: " . $e->getMessage());
    // Fallback till original metadata
    $enriched = [
        'abstract_enriched' => $book['abstract'],
        'subjects' => [],
        'tags' => [],
        'target_audience' => 'Allmänheten'
    ];
}
```

## Kostnadskalkyl

För produktion med betald API:

- **Gemini 1.5 Flash**: $0.075 per 1M input tokens, $0.30 per 1M output tokens
- Genomsnittlig bok: ~500 input tokens + ~300 output tokens
- **Kostnad per bok**: ~$0.00013 (mindre än 1 öre)
- **1000 böcker**: ~$0.13 (1.30 SEK)

Extremt kostnadseffektivt jämfört med manuell katalogisering!

## Säkerhet

⚠️ **Viktigt:**

- Lägg ALDRIG till `GEMINI_API_KEY` i Git
- `.env` är gitignored
- Använd `.env.example` för att dokumentera vilka nycklar som behövs
- För produktion, använd secrets management (t.ex. Kubernetes Secrets, AWS Secrets Manager)

## Resurser

- [Google AI Studio](https://aistudio.google.com/)
- [Gemini API Documentation](https://ai.google.dev/docs)
- [Gemini Pricing](https://ai.google.dev/pricing)
- [API Quickstart Guide](https://ai.google.dev/tutorials/rest_quickstart)

## Support

För frågor om Gemini API integration, kontakta projektansvarig eller se [CLAUDE.md](../CLAUDE.md) för projektet.
