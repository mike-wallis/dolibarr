<?php
/**
 * Import vendor prices and supplier URLs from product-supplier-pricing.csv.
 *
 * Rules:
 *   - Skip rows where URL is empty
 *   - Skip rows where Cost is empty or NULL
 *   - Skip rows where Supplier name is not in the mapping below
 *   - Skip rows where Our_SKU does not exist in Dolibarr
 *   - Skip if a vendor price already exists for the same product + supplier + qty
 *
 * Usage:
 *   php imports/import_vendor_prices.php              (dry run)
 *   php imports/import_vendor_prices.php --apply      (write to dev)
 *   php imports/import_vendor_prices.php --apply --live
 */

$apply   = in_array('--apply', $argv ?? []);
$useLive = in_array('--live',  $argv ?? []);

// ── DB ─────────────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
$env = [];
foreach (file($envFile) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
if ($useLive) {
    $host = $env['LIVE_DB_HOST']; $name = $env['LIVE_DB_NAME'];
    $user = $env['LIVE_DB_USER']; $pass = $env['LIVE_DB_PASS'];
} else {
    $host = $env['DB_HOST']; $name = $env['DB_NAME'];
    $user = $env['DB_USER']; $pass = $env['DB_PASS'];
}
$pdo = new PDO(
    "mysql:host=$host;dbname=$name;charset=utf8mb4",
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$mode   = $apply ? ($useLive ? '[LIVE APPLY]' : '[DEV APPLY]') : '[DRY RUN]';
$target = $useLive ? "LIVE ($name)" : "dev ($name)";
echo "=== Import Vendor Prices $mode — DB: $target ===\n\n";

// ── Supplier name → Dolibarr rowid ─────────────────────────────────────────────
$supplierMap = [
    'Agar'          => 24,
    'Croft & Co'    => 42,
    'Synergy'       => 89,
    'Hanleys'       => 53,
    'EnviroChoice'  => 46,
];

// Verify supplier IDs are correct for this DB (name may differ on live)
echo "Verifying supplier IDs:\n";
foreach ($supplierMap as $csvName => $id) {
    $nom = $pdo->query("SELECT nom FROM llx_societe WHERE rowid=$id AND entity=1")->fetchColumn();
    $ok  = $nom !== false ? "✓ $nom" : "✗ NOT FOUND";
    echo "  '$csvName' → $id  $ok\n";
}
echo "\n";

// ── Build product ref → rowid cache ────────────────────────────────────────────
$products = $pdo->query(
    "SELECT ref, rowid FROM llx_product WHERE entity=1 AND fk_product_type=0"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Prepared statements ─────────────────────────────────────────────────────────
$stmtInsertPrice = $pdo->prepare("
    INSERT INTO llx_product_fournisseur_price
        (datec, fk_soc, fk_product, ref_fourn, price, unitprice,
         quantity, remise_percent, tva_tx, fk_user, entity)
    VALUES
        (NOW(), :fk_soc, :fk_product, :ref_fourn, :price, :price,
         1, 0, 10, 1, 1)
");

$stmtInsertExt = $pdo->prepare("
    INSERT INTO llx_product_fournisseur_price_extrafields (fk_object, supplier_url)
    VALUES (:fk_object, :url)
    ON DUPLICATE KEY UPDATE supplier_url = VALUES(supplier_url)
");

$stmtCheckExists = $pdo->prepare("
    SELECT rowid FROM llx_product_fournisseur_price
    WHERE fk_product = :fk_product AND fk_soc = :fk_soc AND quantity = 1 AND entity = 1
    LIMIT 1
");

// ── Read CSV ────────────────────────────────────────────────────────────────────
$csvFile = __DIR__ . '/raw_samples/product-supplier-pricing.csv';
$fh = fopen($csvFile, 'r');
$header = fgetcsv($fh);
$header[0] = ltrim($header[0], "\xEF\xBB\xBF"); // strip BOM
$col = array_flip(array_map('trim', $header));

$inserted = 0;
$skippedNoUrl = 0;
$skippedNoCost = 0;
$skippedNoSupplier = 0;
$skippedNoProduct = 0;
$skippedExists = 0;

while (($row = fgetcsv($fh)) !== false) {
    $ourSku      = trim($row[$col['Our_SKU']]    ?? '');
    $supplierName = trim($row[$col['Supplier']]   ?? '');
    $cost        = trim($row[$col['Cost']]        ?? '');
    $supplierSku = trim($row[$col['Supplier_SKU']] ?? '');
    $url         = trim($row[$col['URL']]         ?? '');

    // Skip if no URL
    if ($url === '') { $skippedNoUrl++; continue; }

    // Skip if no cost or NULL
    if ($cost === '' || strtoupper($cost) === 'NULL' || (float)$cost <= 0) {
        $skippedNoCost++;
        continue;
    }

    // Skip if supplier not in our map
    if (!isset($supplierMap[$supplierName])) {
        $skippedNoSupplier++;
        if ($supplierName !== '') echo "  UNKNOWN SUPPLIER: '$supplierName' (SKU $ourSku)\n";
        continue;
    }

    // Skip if product not in Dolibarr
    if (!isset($products[$ourSku])) { $skippedNoProduct++; continue; }

    $fkSoc     = $supplierMap[$supplierName];
    $fkProduct = $products[$ourSku];
    $price     = (float)$cost;
    if ($supplierSku === '') $supplierSku = $ourSku;

    // Check if vendor price already exists for this product + supplier
    $stmtCheckExists->execute([':fk_product' => $fkProduct, ':fk_soc' => $fkSoc]);
    $existingId = $stmtCheckExists->fetchColumn();
    if ($existingId !== false) {
        $skippedExists++;
        echo "  EXISTS:  $ourSku / $supplierName (rowid=$existingId) — skipping\n";
        continue;
    }

    echo ($apply ? 'INSERT' : 'WOULD INSERT') . ": $ourSku"
       . " | supplier=$supplierName (id=$fkSoc)"
       . " | vendor_sku=$supplierSku"
       . " | cost=$price"
       . " | url=" . substr($url, 0, 60) . (strlen($url) > 60 ? '…' : '') . "\n";

    if ($apply) {
        $stmtInsertPrice->execute([
            ':fk_soc'     => $fkSoc,
            ':fk_product' => $fkProduct,
            ':ref_fourn'  => $supplierSku,
            ':price'      => $price,
        ]);
        $newId = $pdo->lastInsertId();
        $stmtInsertExt->execute([':fk_object' => $newId, ':url' => $url]);
    }
    $inserted++;
}
fclose($fh);

echo "\n─────────────────────────────────────────────\n";
echo ($apply ? 'Inserted' : 'Would insert') . ": $inserted\n";
echo "Skipped — no URL:      $skippedNoUrl\n";
echo "Skipped — no cost:     $skippedNoCost\n";
echo "Skipped — unknown supplier: $skippedNoSupplier\n";
echo "Skipped — not in Dolibarr: $skippedNoProduct\n";
echo "Skipped — already exists:  $skippedExists\n";

if (!$apply) {
    echo "\nRun with --apply to write to DB";
    echo $useLive ? '' : " (add --live for production)";
    echo ".\n";
}
