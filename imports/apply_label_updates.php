<?php
/**
 * Apply approved label updates from label_suggestions.csv to Dolibarr products.
 *
 * Column logic (all resolved per row before writing):
 *   Category    = APPROVED CATEGORY if set (format: "Parent | Child"),
 *                 else the category already in SUGGESTED LABEL (if not a placeholder)
 *   Manufacturer = APPROVED MANUFACTURER if set,
 *                 else the manufacturer already in SUGGESTED LABEL (if not a placeholder)
 *   Product name = always taken from SUGGESTED LABEL (4th pipe segment = the description)
 *
 * A row is skipped when either category or manufacturer is still unresolved
 * (i.e. would produce a label containing [CAT?], [SUBCAT?], or [MANUF?]).
 *
 * Usage:
 *   php imports/apply_label_updates.php                  (dry run — default)
 *   php imports/apply_label_updates.php --apply          (write to dev DB)
 *   php imports/apply_label_updates.php --apply --live   (write to live DB)
 */

$apply   = in_array('--apply', $argv ?? []);
$useLive = in_array('--live',  $argv ?? []);

// ── DB ────────────────────────────────────────────────────────────────────────
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
echo "=== Apply Label Updates $mode — DB: $target ===\n\n";

// ── Read CSV ──────────────────────────────────────────────────────────────────
$csvFile = __DIR__ . '/label_suggestions.csv';
$fh = fopen($csvFile, 'r');

// Read header — find column indices dynamically
$header = fgetcsv($fh);
$header = array_map('trim', $header);
// Strip UTF-8 BOM from first column if present
$header[0] = ltrim($header[0], "\xEF\xBB\xBF");
$col = array_flip($header);

$need = ['REF', 'SUGGESTED LABEL'];
foreach ($need as $c) {
    if (!isset($col[$c])) {
        echo "ERROR: required column '$c' not found in CSV header.\n";
        echo "Header: " . implode(', ', $header) . "\n";
        exit(1);
    }
}

$iRef       = $col['REF'];
$iSuggested = $col['SUGGESTED LABEL'];
$iApprCat   = $col['APPROVED CATEGORY']     ?? null;
$iApprManuf = $col['APPROVED MANUFACTURER'] ?? null;

if ($iApprCat === null && $iApprManuf === null) {
    echo "NOTE: Neither 'APPROVED CATEGORY' nor 'APPROVED MANUFACTURER' column found.\n";
    echo "      Will apply SUGGESTED LABEL directly for complete rows (no placeholders).\n\n";
}

// ── Process rows ──────────────────────────────────────────────────────────────
$stmt    = $pdo->prepare("UPDATE llx_product SET label = ? WHERE ref = ? AND entity = 1");
$applied = 0;
$skipped = 0;
$already = 0;
$errors  = [];

while (($row = fgetcsv($fh)) !== false) {
    $ref       = trim($row[$iRef] ?? '');
    $suggested = trim($row[$iSuggested] ?? '');
    if ($ref === '' || $suggested === '') continue;

    // Parse the 4 pipe-delimited parts of SUGGESTED LABEL
    // Format: "Parent Cat | Child Cat | MANUFACTURER | Product Name"
    $parts = explode(' | ', $suggested, 4);
    if (count($parts) < 4) {
        $skipped++;
        continue; // malformed row
    }
    [$sugCatParent, $sugCatChild, $sugManuf, $productName] = $parts;

    // Resolve category
    $apprCatRaw = trim($row[$iApprCat] ?? '');
    if ($apprCatRaw !== '') {
        // "Parent | Child" format expected; fall back to just parent if no pipe
        $catParts     = explode(' | ', $apprCatRaw, 2);
        $finalCat     = count($catParts) === 2
            ? trim($catParts[0]) . ' | ' . trim($catParts[1])
            : trim($catParts[0]) . ' | [SUBCAT?]';
    } else {
        $finalCat = "$sugCatParent | $sugCatChild";
    }

    // Resolve manufacturer
    $apprManuf = trim($row[$iApprManuf] ?? '');
    $finalManuf = $apprManuf !== '' ? strtoupper($apprManuf) : $sugManuf;

    // Build final label
    $finalLabel = "$finalCat | $finalManuf | $productName";

    // Skip if any placeholder remains
    if (str_contains($finalLabel, '[CAT?]') ||
        str_contains($finalLabel, '[SUBCAT?]') ||
        str_contains($finalLabel, '[MANUF?]')) {
        $skipped++;
        continue;
    }

    // Check current label from DB
    $cur = $pdo->prepare("SELECT label FROM llx_product WHERE ref = ? AND entity = 1");
    $cur->execute([$ref]);
    $currentLabel = $cur->fetchColumn();

    if ($currentLabel === false) {
        $errors[] = "$ref — not found in DB";
        continue;
    }

    if ($currentLabel === $finalLabel) {
        $already++;
        continue;
    }

    echo ($apply ? 'UPDATE' : 'WOULD UPDATE') . ": $ref\n";
    echo "  FROM: $currentLabel\n";
    echo "    TO: $finalLabel\n\n";

    if ($apply) {
        $stmt->execute([$finalLabel, $ref]);
    }
    $applied++;
}

fclose($fh);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "─────────────────────────────────────────────\n";
echo ($apply ? 'Updated' : 'Would update') . ": $applied\n";
echo "Already correct:  $already\n";
echo "Skipped (missing cat/manuf): $skipped\n";
if ($errors) {
    echo "Not found in DB:  " . count($errors) . "\n";
    foreach ($errors as $e) echo "  $e\n";
}
if (!$apply) {
    echo "\nRun with --apply to write to DB";
    echo $useLive ? '' : " (add --live for production)";
    echo ".\n";
}
