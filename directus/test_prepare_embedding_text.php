<?php
/**
 * Tester för aggregeringen i prepare_embedding_text.php (utan Directus).
 *
 *   php directus/test_prepare_embedding_text.php
 */

require_once __DIR__ . '/prepare_embedding_text.php';

$fel = 0;
function lika($vantat, $faktiskt, string $namn): void
{
    global $fel;
    if ($vantat === $faktiskt) {
        echo "ok   $namn\n";
        return;
    }
    $fel++;
    echo "FEL  $namn: väntade " . var_export($vantat, true) . ', fick ' . var_export($faktiskt, true) . "\n";
}

function exemplar(array $falt = []): array
{
    return $falt + [
        'biblio_id' => 1, 'status' => 'active',
        'home_library_id' => 'FBG', 'holding_library_id' => 'FBG',
        'checked_out_date' => null, 'not_for_loan_status' => 0,
        'damaged_status' => 0, 'lost_status' => 0, 'withdrawn' => 0,
    ];
}

// ── isItemOnOrder ──
lika(true,  isItemOnOrder(exemplar(['not_for_loan_status' => -1])), 'beställt exemplar är under inköp');
lika(true,  isItemOnOrder(exemplar(['not_for_loan_status' => '-1'])), 'värdet som sträng från Directus');
lika(false, isItemOnOrder(exemplar()), 'vanligt exemplar är inte under inköp');
lika(false, isItemOnOrder(exemplar(['not_for_loan_status' => 1])), 'referensexemplar är inte under inköp');
lika(false, isItemOnOrder(exemplar(['not_for_loan_status' => -1, 'lost_status' => 1])), 'beställt men förkommet räknas inte');
lika(false, isItemOnOrder(exemplar(['not_for_loan_status' => -1, 'withdrawn' => 1])), 'beställt men makulerat räknas inte');

// ── aggregateItemsByBiblio ──
$agg = aggregateItemsByBiblio([
    exemplar(),                                       // inne
    exemplar(['not_for_loan_status' => -1]),          // under inköp
    exemplar(['not_for_loan_status' => -1]),          // under inköp
    exemplar(['not_for_loan_status' => 1]),           // referens
    exemplar(['not_for_loan_status' => -1, 'status' => 'inactive']), // borttaget ur Koha
])[1];
lika(4, $agg['total_items'], 'totalt räknar aktiva exemplar');
lika(1, $agg['available_items'], 'beställda räknas inte som tillgängliga');
lika(2, $agg['on_order_items'], 'beställda räknas för sig');

// ── Embeddingtext och metadata ──
$biblio = ['biblio_id' => 1, 'title' => 'Testbok'];
$text = buildEmbeddingText($biblio, null, $agg, null, $ITEM_TYPE_LABELS);
lika(1, substr_count($text, 'Exemplar: 4 totalt, 1 tillgängliga, 2 under inköp'), 'texten nämner under inköp');

$utanBestallda = aggregateItemsByBiblio([exemplar()])[1];
$text = buildEmbeddingText($biblio, null, $utanBestallda, null, $ITEM_TYPE_LABELS);
lika(1, substr_count($text, "Exemplar: 1 totalt, 1 tillgängliga\n") + (str_ends_with($text, 'Exemplar: 1 totalt, 1 tillgängliga') ? 1 : 0),
    'texten är oförändrad utan beställda exemplar');

$meta = buildMetadata($biblio, null, $agg, null, $ITEM_TYPE_LABELS);
lika(2, $meta['on_order_items'], 'metadata bär on_order_items');

echo $fel === 0 ? "\nAlla tester gick igenom.\n" : "\n$fel test fallerade.\n";
exit($fel === 0 ? 0 : 1);
