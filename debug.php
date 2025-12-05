<?php
// Debug-script för att se RSS-strukturen
header('Content-Type: text/html; charset=utf-8');

$rssUrl = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-shelves.pl?rss=1&op=view&shelfnumber=247';

// Hämta RSS-feed med full info
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $rssUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

echo "<h2>HTTP Status: $httpCode</h2>";
echo "<h3>Effective URL:</h3>";
echo "<pre>" . htmlspecialchars($effectiveUrl) . "</pre>";

echo "<h3>Response Headers:</h3>";
echo "<pre>" . htmlspecialchars($headers) . "</pre>";

echo "<h3>Content Type Check:</h3>";
if (strpos($body, '<?xml') !== false) {
    echo "<p>✓ Innehåller XML-deklaration</p>";
} else {
    echo "<p>✗ Saknar XML-deklaration</p>";
}

if (strpos($body, '<rss') !== false) {
    echo "<p>✓ Innehåller RSS-tag</p>";
} else {
    echo "<p>✗ Saknar RSS-tag</p>";
}

if (strpos($body, '<script') !== false) {
    echo "<p>⚠ Innehåller JavaScript</p>";
}

if (strpos($body, 'window.location') !== false || strpos($body, 'location.href') !== false) {
    echo "<p>⚠ Innehåller JavaScript redirect</p>";
}

echo "<h3>First 500 characters of body:</h3>";
echo "<pre>" . htmlspecialchars(substr($body, 0, 500)) . "</pre>";

echo "<h3>Full Raw Response:</h3>";
echo "<pre>" . htmlspecialchars($body) . "</pre>";

echo "<h3>Parsed XML structure:</h3>";
$xml = @simplexml_load_string($body);
if ($xml) {
    echo "<pre>";
    print_r($xml);
    echo "</pre>";
} else {
    echo "<p style='color:red;'>Kunde inte parsa som XML!</p>";
    echo "<h4>XML Errors:</h4>";
    echo "<pre>";
    print_r(libxml_get_errors());
    echo "</pre>";
}
?>
