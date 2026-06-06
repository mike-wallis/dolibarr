<?php
/**
 * Suggest product label updates to comply with the naming convention:
 *   Category | Sub-category | MANUFACTURER | Product Name Variant
 *
 * Sources:
 *   - Manufacturer: extracted from Reckon NAME hierarchy in Items.csv
 *   - Category:     from Dolibarr category assignments (post-migration)
 *   - Product name: current Dolibarr description (falls back to label)
 *
 * Usage:
 *   php imports/suggest_label_updates.php
 *   php imports/suggest_label_updates.php --live
 *
 * Output: imports/label_suggestions.csv  (UTF-8 BOM, opens cleanly in Excel)
 */

$useLive = in_array('--live', $argv ?? []);

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
echo "=== Label Suggestion Report — DB: $target ===\n\n";

// ── 1. Parse CSV ───────────────────────────────────────────────────────────────
// First pass: collect all NAME paths that are category nodes (empty DESC).
// These let us distinguish manufacturer segments from category segments.
$csvFile = __DIR__ . '/raw_samples/Items.csv';
// Reckon NAME structure for leaf items:
//   2 segments: TopCat:SKU                        → no manufacturer
//   3 segments: TopCat:SubCat:SKU                 → no manufacturer (SubCat is not a brand)
//   4 segments: TopCat:SubCat:MANUFACTURER:SKU    → segment[2] is always the manufacturer
//   5 segments: same pattern, one extra level deep
// Using this rule avoids false positives where manufacturer names double as category nodes.

$csvByRef = []; // ref → ['manuf' => str, 'csvDesc' => str]

$handle = fopen($csvFile, 'r');
fgetcsv($handle); // header
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 6) continue;
    if (($row[4] ?? '') !== 'STOCK') continue;
    $desc = trim($row[5]);
    if ($desc === '') continue; // category node, not a real product

    $parts     = explode(':', $row[1]);
    $n         = count($parts);
    $lastSeg   = trim(end($parts));
    // Manufacturer is the penultimate segment only when NAME has 4+ segments
    $manuf     = ($n >= 4) ? trim($parts[$n - 2]) : '';
    $mfgPartNo = isset($row[52]) ? trim($row[52]) : '';

    foreach (array_unique(array_filter([$lastSeg, $mfgPartNo])) as $ref) {
        if (!isset($csvByRef[$ref])) {
            $csvByRef[$ref] = ['manuf' => $manuf, 'csvDesc' => $desc];
        }
    }
}
fclose($handle);

// ── 2. Query DB — products with their first category assignment ────────────────
$dbRows = $pdo->query("
    SELECT
        p.ref,
        TRIM(p.label) AS label,
        TRIM(COALESCE(NULLIF(p.description, ''), p.label)) AS display_desc,
        MIN(pc.label) AS parent_cat,
        MIN(cc.label) AS child_cat
    FROM llx_product p
    LEFT JOIN llx_categorie_product cp ON cp.fk_product = p.rowid
    LEFT JOIN llx_categorie cc ON cc.rowid = cp.fk_categorie AND cc.type = 0
    LEFT JOIN llx_categorie pc ON pc.rowid = cc.fk_parent AND pc.type = 0
    WHERE p.entity = 1
      AND p.fk_product_type = 0
    GROUP BY p.rowid, p.ref, p.label, p.description
    ORDER BY p.ref
")->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Generate suggestions ────────────────────────────────────────────────────
$out = [['REF', 'CURRENT LABEL', 'SUGGESTED LABEL', 'STATUS', 'CATEGORY ASSIGNED', 'MANUFACTURER FROM CSV']];

$counts = ['COMPLETE' => 0, 'NEEDS_MANUF' => 0, 'NEEDS_CAT' => 0, 'NEEDS_BOTH' => 0];

foreach ($dbRows as $row) {
    $ref       = $row['ref'];
    $curLabel  = $row['label'];
    $descPart  = $row['display_desc'];

    // Clean up description: collapse whitespace, trim
    $descPart = preg_replace('/\s+/', ' ', $descPart);

    $parentCat = trim($row['parent_cat'] ?? '');
    $childCat  = trim($row['child_cat'] ?? '');
    $csvInfo   = $csvByRef[$ref] ?? null;
    $manuf     = $csvInfo ? strtoupper(trim($csvInfo['manuf'])) : '';

    $hasCat   = $parentCat !== '' && $childCat !== '';
    $hasManuf = $manuf !== '';

    if ($hasCat && $hasManuf) {
        $status   = 'COMPLETE';
        $catPart  = "$parentCat | $childCat";
        $mfgPart  = $manuf;
    } elseif ($hasCat) {
        $status   = 'NEEDS_MANUF';
        $catPart  = "$parentCat | $childCat";
        $mfgPart  = '[MANUF?]';
    } elseif ($hasManuf) {
        $status   = 'NEEDS_CAT';
        $catPart  = '[CAT?] | [SUBCAT?]';
        $mfgPart  = $manuf;
    } else {
        $status   = 'NEEDS_BOTH';
        $catPart  = '[CAT?] | [SUBCAT?]';
        $mfgPart  = '[MANUF?]';
    }

    $suggested = "$catPart | $mfgPart | $descPart";

    if ($suggested === $curLabel) continue; // already correct

    $counts[$status]++;
    $out[] = [
        $ref,
        $curLabel,
        $suggested,
        $status,
        $hasCat ? "$parentCat | $childCat" : '',
        $hasManuf ? $manuf : '',
    ];
}

// ── 4. Write CSV ──────────────────────────────────────────────────────────────
$outFile = __DIR__ . '/label_suggestions.csv';
$fh = fopen($outFile, 'w');
fprintf($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens without encoding issues
foreach ($out as $r) {
    fputcsv($fh, $r);
}
fclose($fh);

// ── 5. Print summary ──────────────────────────────────────────────────────────
$total = count($out) - 1;
echo "Products needing label update: $total\n";
echo "  COMPLETE  (cat + manuf found): {$counts['COMPLETE']}\n";
echo "  NEEDS_MANUF (cat found, no manuf in CSV): {$counts['NEEDS_MANUF']}\n";
echo "  NEEDS_CAT  (manuf found, no category):    {$counts['NEEDS_CAT']}\n";
echo "  NEEDS_BOTH (neither found):               {$counts['NEEDS_BOTH']}\n";
echo "\nWritten to: $outFile\n";
echo "\nColumns in CSV:\n";
echo "  REF              — the product SKU\n";
echo "  CURRENT LABEL    — what is in Dolibarr now\n";
echo "  SUGGESTED LABEL  — proposed replacement (edit cells before applying)\n";
echo "  STATUS           — COMPLETE / NEEDS_MANUF / NEEDS_CAT / NEEDS_BOTH\n";
echo "  CATEGORY ASSIGNED — parent | child from Dolibarr categories\n";
echo "  MANUFACTURER FROM CSV — extracted from Reckon item name hierarchy\n";
