<?php
/**
 * Adds the 'supplier_url' extra field to products.
 * Renders as a clickable link in Dolibarr product card.
 *
 * Usage:
 *   php imports/add-supplier-url-field.php --dry-run
 *   php imports/add-supplier-url-field.php
 *   php imports/add-supplier-url-field.php --live
 */

$dryRun  = in_array('--dry-run', $argv ?? []);
$useLive = in_array('--live',    $argv ?? []);

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

$target = $useLive ? "LIVE ($name)" : "dev ($name)";
echo "=== Add supplier_url extra field " . ($dryRun ? "[DRY RUN]" : "[LIVE RUN]") . " — DB: $target ===\n\n";

$exists = $pdo->query(
    "SELECT COUNT(*) FROM llx_extrafields WHERE name = 'supplier_url' AND elementtype = 'product'"
)->fetchColumn();

if ($exists) {
    echo "Extra field 'supplier_url' already exists — nothing to do.\n";
    $col = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'llx_product_extrafields'
           AND COLUMN_NAME  = 'supplier_url'"
    )->fetchColumn();
    echo "Column in llx_product_extrafields: " . ($col ? "EXISTS" : "MISSING") . "\n";
    exit(0);
}

if ($dryRun) {
    echo "Would INSERT into llx_extrafields:\n";
    echo "  name        = supplier_url\n";
    echo "  label       = Supplier URL\n";
    echo "  type        = url\n";
    echo "  elementtype = product\n";
    echo "  entity      = 0 (all entities)\n\n";
    echo "Would ALTER TABLE llx_product_extrafields ADD COLUMN supplier_url varchar(255)\n\n";
    echo "Run without --dry-run to apply.\n";
    exit(0);
}

try {
    $pdo->prepare("
        INSERT INTO llx_extrafields
            (name, label, type, size, elementtype, fieldunique, fieldrequired,
             param, alwayseditable, perms, langs, list, printable, totalizable,
             fielddefault, fieldcomputed, entity, enabled, pos, help)
        VALUES
            ('supplier_url', 'Supplier URL', 'url', '255', 'product', 0, 0,
             '', 1, '1', '', 1, 0, 0,
             '', '', 0, '1', 110, 'Link to this product on the supplier website.')
    ")->execute();
    echo "Inserted extra field into llx_extrafields.\n";

    $pdo->exec("ALTER TABLE llx_product_extrafields ADD COLUMN supplier_url varchar(255) DEFAULT NULL");
    echo "Added column 'supplier_url' to llx_product_extrafields.\n";

    echo "\nDone. Field appears on the product card under 'Other attributes'.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
