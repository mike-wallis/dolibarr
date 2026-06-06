<?php
/**
 * Category migration — replaces Dolibarr product categories with the website tree.
 *
 * Usage:
 *   php imports/migrate_categories.php --dry-run   (preview only)
 *   php imports/migrate_categories.php             (live run)
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$useLive = in_array('--live', $argv ?? []);

// ── DB connection via .env ────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
$env = [];
foreach (file($envFile) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

if ($useLive) {
    $host = $env['LIVE_DB_HOST'];
    $name = $env['LIVE_DB_NAME'];
    $user = $env['LIVE_DB_USER'];
    $pass = $env['LIVE_DB_PASS'];
} else {
    $host = $env['DB_HOST'];
    $name = $env['DB_NAME'];
    $user = $env['DB_USER'];
    $pass = $env['DB_PASS'];
}

$pdo = new PDO(
    "mysql:host=$host;dbname=$name;charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Load CSV ──────────────────────────────────────────────────────────────────
$csvFile = __DIR__ . '/raw_samples/categories.csv';
if (!file_exists($csvFile)) {
    echo "ERROR: CSV not found at $csvFile\n";
    exit(1);
}

$rows = [];
$handle = fopen($csvFile, 'r');
fgetcsv($handle); // skip header
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 3) continue;
    [$sku, $cat, $subcat] = array_map('trim', $row);
    if ($sku === '') continue;
    $rows[] = [$sku, $cat, $subcat];
}
fclose($handle);

// Build structures
$parents  = [];   // unique Cat1 labels
$combos   = [];   // unique "Cat1|Cat2" => true
$skuCats  = [];   // sku => [["Cat1|Cat2"], ...]

foreach ($rows as [$sku, $cat, $subcat]) {
    $parents[$cat]             = true;
    $key                       = "$cat|$subcat";
    $combos[$key]              = [$cat, $subcat];
    $skuCats[$sku][]           = $key;
}

// ── Summary ───────────────────────────────────────────────────────────────────
$target = $useLive ? "LIVE ($name)" : "dev ($name)";
echo "=== Category Migration " . ($dryRun ? "[DRY RUN]" : "[LIVE RUN]") . " — DB: $target ===\n";
echo count($parents) . " parent categories\n";
echo count($combos)  . " category combinations\n";
echo count($skuCats) . " unique SKUs\n\n";

// ── Dry run: preview + SKU check ──────────────────────────────────────────────
if ($dryRun) {
    echo "Categories to create:\n";
    foreach ($parents as $p => $_) {
        echo "  [parent] $p\n";
        foreach ($combos as $key => [$cat, $subcat]) {
            if ($cat === $p) echo "    [child]  $subcat\n";
        }
    }

    echo "\nChecking SKUs against Dolibarr...\n";
    $notFound = [];
    $stmt = $pdo->prepare("SELECT rowid FROM llx_product WHERE ref = ? AND entity = 1 LIMIT 1");
    foreach (array_keys($skuCats) as $sku) {
        $stmt->execute([$sku]);
        if (!$stmt->fetch()) $notFound[] = $sku;
    }

    if ($notFound) {
        echo count($notFound) . " SKUs not in Dolibarr (will be skipped on live run):\n";
        foreach ($notFound as $sku) echo "  - $sku\n";
    } else {
        echo "All SKUs found in Dolibarr.\n";
    }

    echo "\nRun without --dry-run to apply.\n";
    exit(0);
}

// ── Live run ──────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    // 1. Remove existing product-category links
    $n = $pdo->exec("
        DELETE FROM llx_categorie_product
        WHERE fk_categorie IN (
            SELECT rowid FROM llx_categorie WHERE type = 0 AND entity = 1
        )
    ");
    echo "Removed $n product-category links\n";

    // 2. Remove existing product categories
    $n = $pdo->exec("DELETE FROM llx_categorie WHERE type = 0 AND entity = 1");
    echo "Removed $n existing categories\n";

    // 3. Insert parent categories
    $insParent = $pdo->prepare(
        "INSERT INTO llx_categorie (label, type, fk_parent, entity) VALUES (?, 0, 0, 1)"
    );
    $parentIds = [];
    foreach (array_keys($parents) as $label) {
        $insParent->execute([$label]);
        $parentIds[$label] = (int) $pdo->lastInsertId();
    }
    echo count($parentIds) . " parent categories created\n";

    // 4. Insert child categories
    $insChild = $pdo->prepare(
        "INSERT INTO llx_categorie (label, type, fk_parent, entity) VALUES (?, 0, ?, 1)"
    );
    $childIds = [];
    foreach ($combos as $key => [$cat, $subcat]) {
        $insChild->execute([$subcat, $parentIds[$cat]]);
        $childIds[$key] = (int) $pdo->lastInsertId();
    }
    echo count($childIds) . " child categories created\n";

    // 5. Assign products
    $findProd = $pdo->prepare(
        "SELECT rowid FROM llx_product WHERE ref = ? AND entity = 1 LIMIT 1"
    );
    $insLink = $pdo->prepare(
        "INSERT IGNORE INTO llx_categorie_product (fk_categorie, fk_product) VALUES (?, ?)"
    );
    $assigned = 0;
    $notFound = [];

    foreach ($skuCats as $sku => $keys) {
        $findProd->execute([$sku]);
        $prod = $findProd->fetchColumn();
        if (!$prod) {
            $notFound[] = $sku;
            continue;
        }
        foreach ($keys as $key) {
            $insLink->execute([$childIds[$key], $prod]);
            $assigned++;
        }
    }
    echo "$assigned product-category assignments created\n";

    if ($notFound) {
        echo "\nSKUs not found in Dolibarr — skipped (" . count($notFound) . "):\n";
        foreach ($notFound as $sku) echo "  - $sku\n";
    }

    $pdo->commit();
    echo "\nMigration complete.\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR — rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
