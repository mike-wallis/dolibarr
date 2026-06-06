<?php
/**
 * Adds the 'purchase_description' extra field to products.
 *
 * Usage:
 *   php imports/add-purchase-description-field.php --dry-run
 *   php imports/add-purchase-description-field.php
 *   php imports/add-purchase-description-field.php --live
 */

$dryRun  = in_array('--dry-run', $argv ?? []);
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
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$target = $useLive ? "LIVE ($name)" : "dev ($name)";
echo "=== Add purchase_description extra field " . ($dryRun ? "[DRY RUN]" : "[LIVE RUN]") . " — DB: $target ===\n\n";

// ── Check if already exists ───────────────────────────────────────────────────
$exists = $pdo->query(
    "SELECT COUNT(*) FROM llx_extrafields WHERE name = 'purchase_description' AND elementtype = 'product'"
)->fetchColumn();

if ($exists) {
    echo "Extra field 'purchase_description' already exists in llx_extrafields — nothing to do.\n";

    // Still check if the column exists on the data table
    $col = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'llx_product_extrafields'
           AND COLUMN_NAME  = 'purchase_description'"
    )->fetchColumn();

    echo "Column in llx_product_extrafields: " . ($col ? "EXISTS" : "MISSING — would need manual ALTER") . "\n";
    exit(0);
}

// ── Dry run preview ───────────────────────────────────────────────────────────
if ($dryRun) {
    echo "Would INSERT into llx_extrafields:\n";
    echo "  name        = purchase_description\n";
    echo "  label       = Purchase Description\n";
    echo "  type        = text (textarea)\n";
    echo "  elementtype = product\n";
    echo "  entity      = 0 (all entities)\n\n";
    echo "Would ALTER TABLE llx_product_extrafields ADD COLUMN purchase_description text\n\n";
    echo "Run without --dry-run to apply.\n";
    exit(0);
}

// ── Live run ──────────────────────────────────────────────────────────────────
// Note: ALTER TABLE causes an implicit commit in MySQL — no wrapping transaction.
try {
    // 1. Register the extra field
    $pdo->prepare("
        INSERT INTO llx_extrafields
            (name, label, type, size, elementtype, fieldunique, fieldrequired,
             param, alwayseditable, perms, langs, list, printable, totalizable,
             fielddefault, fieldcomputed, entity, enabled, pos, help)
        VALUES
            ('purchase_description', 'Purchase Description', 'text', '5', 'product', 0, 0,
             '', 1, '1', '', 1, 0, 0,
             '', '', 0, '1', 100, 'Description shown on purchase orders instead of the standard description.')
    ")->execute();
    echo "Inserted extra field into llx_extrafields.\n";

    // 2. Add the column to the product extra fields data table
    $pdo->exec("ALTER TABLE llx_product_extrafields ADD COLUMN purchase_description text DEFAULT NULL");
    echo "Added column 'purchase_description' to llx_product_extrafields.\n";

    echo "\nDone. The field will appear on product edit pages under 'Other attributes'.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
