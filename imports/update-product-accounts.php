<?php
/**
 * Update product accounting codes from Items.csv
 *
 * Reads ACCNT (sales) and COGSACCNT (COGS) from the QB export and writes
 * accountancy_code_sell / accountancy_code_buy to llx_product.
 *
 * Run from CLI: php imports/update-product-accounts.php
 * Or via browser: http://dolibarr.test/imports/update-product-accounts.php
 */

$csvPath = __DIR__ . '/raw_samples/Items.csv';
$logPath = __DIR__ . '/import-product-accounts-log.csv';

// ── QB account name → Dolibarr account code ──────────────────────────────────

$SELL_MAP = [
    'Sales:Sales - Chemicals'                                       => '4010.01',
    'Sales:Sales - Bin Liners'                                      => '4010.04',
    'Sales:Sales - Paper'                                           => '4010.02',
    'Sales:Sales - Misc'                                            => '4010.07',
    'Sales'                                                         => '4010',
    'Service and Repairs'                                           => '4030',
    'Freight Income'                                                => '4110',
    'Re-imbursed Expenses'                                          => '4300',
    'Plant & Equipment'                                             => '4910',
    'Cost of Services Sold'                                         => '5015',
    'Other expenses:Bad debts written off'                          => '6600.12',
    'Operating Expenses:Dispenser Installs:Dispenising equipment'   => '6007.01',
    'Operating Expenses:Dispenser Installs:Other Install costs'     => '6007.05',
    'COGS:COGS Misc'                                                => '5010.07',
];

$BUY_MAP = [
    'COGS:COGS Chemicals'  => '5010.01',
    'COGS:COGS Bin Liners' => '5010.04',
    'COGS:COGS Paper'      => '5010.02',
    'COGS:COGS Misc'       => '5010.07',
];

// ── DB connection ─────────────────────────────────────────────────────────────

$db = new mysqli('localhost', 'dolibarr_dev', 'DolDev2026!', 'dolibarr_dev');
if ($db->connect_errno) {
    die("DB connect failed: " . $db->connect_error . "\n");
}
$db->set_charset('utf8');

// ── CSV column indices (0-based) ─────────────────────────────────────────────
define('COL_TYPE',       0);
define('COL_NAME',       1);  // colon-separated hierarchy; last segment = product ref
define('COL_INVTYPE',    4);  // SERV or STOCK
define('COL_ACCNT',      7);  // sales income account
define('COL_COGSACCNT',  9);  // COGS account

// ── Process ───────────────────────────────────────────────────────────────────

$logRows = [['ref', 'name', 'sell_qb', 'sell_code', 'buy_qb', 'buy_code', 'status', 'note']];

$fh = fopen($csvPath, 'r');
$headerSkipped = false;
$updated = $skipped = $notFound = $errors = 0;

while (($row = fgetcsv($fh)) !== false) {
    // Skip the header line
    if (!$headerSkipped) {
        $headerSkipped = true;
        continue;
    }

    // Only process actual inventory records
    if (strtoupper(trim($row[COL_TYPE] ?? '')) !== 'INVITEM') continue;

    // Ref = last colon-separated segment of NAME (e.g. "Aerosol:...:ST0331579PK" → "ST0331579PK")
    // Category-only nodes have no sellable leaf ref — they have no items in Dolibarr.
    $nameParts = explode(':', trim($row[COL_NAME] ?? ''));
    $ref = trim(end($nameParts));
    if ($ref === '') continue;
    // Skip pure category nodes (NAME contains colons but the last segment still looks
    // like a category word with no digits/special chars) — but let the DB lookup decide;
    // if it's not in Dolibarr it will just land in not_found.

    $name     = trim($row[COL_NAME] ?? '');
    $sellQb   = trim($row[COL_ACCNT] ?? '');
    $buyQb    = trim($row[COL_COGSACCNT] ?? '');

    $sellCode = $SELL_MAP[$sellQb] ?? null;
    $buyCode  = $BUY_MAP[$buyQb]  ?? null;

    // Nothing to set for this product
    if ($sellCode === null && $buyCode === null) {
        $logRows[] = [$ref, $name, $sellQb, '', $buyQb, '', 'skipped', 'no mapping found'];
        $skipped++;
        continue;
    }

    // Find the product in Dolibarr
    $safeRef = $db->real_escape_string($ref);
    $res = $db->query("SELECT rowid FROM llx_product WHERE ref = '$safeRef' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        $logRows[] = [$ref, $name, $sellQb, $sellCode ?? '', $buyQb, $buyCode ?? '', 'not_found', ''];
        $notFound++;
        continue;
    }
    $pid = (int) $res->fetch_row()[0];

    // Build SET clause only for non-null mappings
    $sets = [];
    if ($sellCode !== null) $sets[] = "accountancy_code_sell = '" . $db->real_escape_string($sellCode) . "'";
    if ($buyCode  !== null) $sets[] = "accountancy_code_buy  = '" . $db->real_escape_string($buyCode)  . "'";

    $sql = "UPDATE llx_product SET " . implode(', ', $sets) . " WHERE rowid = $pid";
    if ($db->query($sql)) {
        $logRows[] = [$ref, $name, $sellQb, $sellCode ?? '', $buyQb, $buyCode ?? '', 'updated', ''];
        $updated++;
    } else {
        $logRows[] = [$ref, $name, $sellQb, $sellCode ?? '', $buyQb, $buyCode ?? '', 'error', $db->error];
        $errors++;
    }
}
fclose($fh);
$db->close();

// ── Write log ─────────────────────────────────────────────────────────────────

$lh = fopen($logPath, 'w');
foreach ($logRows as $r) fputcsv($lh, $r);
fclose($lh);

// ── Summary ───────────────────────────────────────────────────────────────────

$summary = "Done. updated=$updated  skipped=$skipped  not_found=$notFound  errors=$errors\n";
$summary .= "Log written to: $logPath\n";

if (php_sapi_name() === 'cli') {
    echo $summary;
} else {
    header('Content-Type: text/plain');
    echo $summary;
}
