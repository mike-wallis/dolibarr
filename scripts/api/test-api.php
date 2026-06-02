<?php

require_once __DIR__ . '/DolibarrClient.php';

// Load .env manually (no library needed for dev)
foreach (file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}

$api = new DolibarrClient($_ENV['DOLIBARR_URL'], $_ENV['DOLIBARR_API_KEY']);

// --- 1. List products ---
echo "=== Products ===\n";
$products = $api->get('products', ['limit' => 10, 'mode' => 1]);
foreach ($products as $p) {
    printf("  [%s] %s  price=%.2f  stock=%s\n",
        $p['ref'], $p['label'], $p['price'], $p['stock_reel'] ?? 'n/a');
}

// --- 2. Single product by ref ---
echo "\n=== Product by ref (TEST-001) ===\n";
$results = $api->get('products', ['sqlfilters' => "(t.ref:=:'TEST-001')"]);
if (!empty($results)) {
    $p = $results[0];
    printf("  ref=%s  label=%s  price_ex=%.2f  price_inc=%.2f  gst=%.0f%%  stock=%d  avg_cost=%.2f\n",
        $p['ref'], $p['label'], $p['price'], $p['price_ttc'],
        $p['tva_tx'], $p['stock_reel'] ?? 0, $p['pmp']);
}

// --- 3. List customers (third parties of type customer) ---
echo "\n=== Customers ===\n";
$customers = $api->get('thirdparties', ['mode' => 1, 'limit' => 10]);
foreach ($customers as $c) {
    printf("  [%d] %s\n", $c['id'], $c['name']);
}

// --- 4. List unpaid customer invoices ---
echo "\n=== Unpaid customer invoices ===\n";
$invoices = $api->get('invoices', ['status' => 1, 'limit' => 10]); // 1 = unpaid
foreach ($invoices as $inv) {
    printf("  [%s] %s  total=%.2f  due=%.2f\n",
        $inv['ref'], $inv['socid'] ?? '', $inv['total_ttc'], $inv['remaintopay'] ?? 0);
}

echo "\nDone.\n";
