<?php
/**
 * Import products from Reckon IIF/CSV export into Dolibarr.
 *
 * Filters applied:
 *   - INVITEMTYPE = STOCK only (services excluded)
 *   - HIDDEN = N (active products only)
 *   - PRICE > 0 and DESC not empty (real products, not category header rows)
 *
 * Stock quantities are NOT imported here — run import-stock.php on 1 July.
 *
 * Usage:
 *   php import-products.php             # import all
 *   php import-products.php --dry-run   # preview without writing
 *   php import-products.php --limit=10  # test with first 10 products
 */

require_once __DIR__ . '/../api/DolibarrClient.php';

// --- Args ---
$dryRun = in_array('--dry-run', $argv);
$limitArg = array_values(array_filter($argv, fn($a) => str_starts_with($a, '--limit=')));
$limit = $limitArg ? (int) explode('=', $limitArg[0])[1] : PHP_INT_MAX;

// --- Config ---
foreach (file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$api     = new DolibarrClient($_ENV['DOLIBARR_URL'], $_ENV['DOLIBARR_API_KEY']);
$csvIn   = __DIR__ . '/../../imports/raw_samples/items.csv';
$logFile = __DIR__ . '/../../imports/import-products-log.csv';

// --- Parse and filter CSV ---
$rows   = array_map('str_getcsv', file($csvIn));
$header = $rows[0];
$hi     = array_flip($header);

function col(array $row, array $hi, string $key): string
{
    return trim($row[$hi[$key] ?? -1] ?? '');
}

$products = [];
foreach (array_slice($rows, 1) as $r) {
    if (count($r) < 10) continue;
    if (col($r, $hi, 'INVITEMTYPE') !== 'STOCK') continue;
    if (col($r, $hi, 'HIDDEN') === 'Y') continue;
    if ((float) col($r, $hi, 'PRICE') <= 0) continue;
    if (col($r, $hi, 'DESC') === '') continue;
    $products[] = $r;
}

$total = min(count($products), $limit);
printf("Products to import: %d%s\n\n", $total, $dryRun ? ' (DRY RUN — no changes will be made)' : '');

// --- Pre-fetch existing products to skip duplicates ---
echo "Fetching existing Dolibarr products...\n";
$existing = [];
$page = 0;
do {
    $batch = $api->get('products', ['limit' => 500, 'page' => $page, 'mode' => 1]);
    foreach ($batch as $p) $existing[$p['ref']] = (int) $p['id'];
    $page++;
} while (count($batch) === 500);
printf("  %d products already exist\n\n", count($existing));

// --- Category cache: "Label|parent_id" => category_id ---
echo "Fetching existing categories...\n";
$catCache = [];
$allCats = $api->get('categories', ['type' => 'product', 'limit' => 500]);
foreach ($allCats as $c) {
    $key = $c['label'] . '|' . ((int) ($c['fk_parent'] ?? 0));
    $catCache[$key] = (int) $c['id'];
}
printf("  %d categories already exist\n\n", count($catCache));

function ensureCategory(DolibarrClient $api, array &$catCache, string $label, int $parentId, bool $dryRun): int
{
    $key = $label . '|' . $parentId;
    if (isset($catCache[$key])) return $catCache[$key];

    if ($dryRun) {
        $fakeId = -(count($catCache) + 1);
        $catCache[$key] = $fakeId;
        printf("  [DRY] Create category: %s (parent=%d)\n", $label, $parentId);
        return $fakeId;
    }

    $body = ['label' => $label, 'type' => 0];
    if ($parentId > 0) $body['fk_parent'] = $parentId;
    $id = (int) $api->post('categories', $body);
    $catCache[$key] = $id;
    printf("  + Category: %s (id=%d)\n", $label, $id);
    return $id;
}

// A part is a category/subcategory if it is NOT all-uppercase (e.g. "Aerosol", "BinLiners").
// All-uppercase parts are manufacturer codes or SKUs (e.g. "AIRWICK", "ST0331579PK").
function isCategoryPart(string $part): bool
{
    return $part !== strtoupper($part);
}

// --- Import ---
$log    = [['ref', 'label', 'category_path', 'status', 'dolibarr_id', 'note']];
$counts = ['created' => 0, 'skipped' => 0, 'error' => 0];
$done   = 0;

foreach (array_slice($products, 0, $limit) as $r) {
    $name  = col($r, $hi, 'NAME');
    $parts = explode(':', $name);
    $ref   = $parts[array_key_last($parts)];
    $label = col($r, $hi, 'DESC');
    $price = (float) col($r, $hi, 'PRICE');
    $cost  = (float) col($r, $hi, 'COST');
    $pref  = col($r, $hi, 'PREFVEND');
    $mfpn  = col($r, $hi, 'MANUFACPARTNO');

    // Build category path from Title-case parts only (stop at first ALL-CAPS part)
    $catParts = [];
    foreach (array_slice($parts, 0, -1) as $p) { // exclude the last part (SKU)
        if (!isCategoryPart($p)) break;
        $catParts[] = $p;
    }

    // Ensure category hierarchy (up to 2 levels deep)
    $catId   = 0;
    $catPath = '';
    foreach (array_slice($catParts, 0, 2) as $i => $catLabel) {
        $parentId = $catId;
        $catId    = ensureCategory($api, $catCache, $catLabel, $parentId, $dryRun);
        $catPath  = $catPath ? "$catPath > $catLabel" : $catLabel;
    }

    // Skip if already in Dolibarr
    if (isset($existing[$ref])) {
        $counts['skipped']++;
        $log[] = [$ref, $label, $catPath, 'skipped', $existing[$ref], 'already exists'];
        continue;
    }

    $done++;
    printf("[%d/%d] %s — %s\n", $done, $total, $ref, substr($label, 0, 60));

    if ($dryRun) {
        $counts['created']++;
        $log[] = [$ref, $label, $catPath, 'dry-run', '', ''];
        continue;
    }

    try {
        $noteParts = array_filter([
            $pref               ? "Supplier: $pref"     : '',
            $mfpn && $mfpn !== $ref ? "Mfr part#: $mfpn" : '',
            "Reckon ref: $name",
        ]);

        $body = [
            'ref'              => $ref,
            'label'            => $label,
            'type'             => 0,
            'status'           => 1,
            'status_buy'       => 1,
            'price'            => $price,
            'price_base_type'  => 'HT',
            'tva_tx'           => 10,
            'default_vat_code' => 'GST',
            'note_private'     => implode("\n", $noteParts),
        ];
        if ($cost > 0) $body['cost_price'] = $cost;

        $productId = (int) $api->post('products', $body);

        if ($catId > 0 && $productId > 0) {
            $api->post("categories/$catId/objects/product/$productId", []);
        }

        $existing[$ref] = $productId;
        $counts['created']++;
        $log[] = [$ref, $label, $catPath, 'created', $productId, ''];

    } catch (RuntimeException $e) {
        $counts['error']++;
        $log[] = [$ref, $label, $catPath, 'error', '', $e->getMessage()];
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// --- Write log ---
$fh = fopen($logFile, 'w');
foreach ($log as $row) fputcsv($fh, $row);
fclose($fh);

echo "\n";
printf("Created: %d  Skipped: %d  Errors: %d\n", $counts['created'], $counts['skipped'], $counts['error']);
if (!$dryRun) printf("Log: %s\n", $logFile);
