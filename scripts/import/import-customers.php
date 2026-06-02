<?php
/**
 * Import customers from Reckon IIF/CSV export into Dolibarr.
 *
 * Skips HIDDEN=Y customers.
 * Opening balances are NOT imported — enter as invoices manually at cutover (1 July).
 *
 * Usage:
 *   php import-customers.php             # import all
 *   php import-customers.php --dry-run   # preview without writing
 */

require_once __DIR__ . '/../api/DolibarrClient.php';

$dryRun = in_array('--dry-run', $argv);

foreach (file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$api     = new DolibarrClient($_ENV['DOLIBARR_URL'], $_ENV['DOLIBARR_API_KEY']);
$csvIn   = __DIR__ . '/../../imports/raw_samples/customers.csv';
$logFile = __DIR__ . '/../../imports/import-customers-log.csv';

// Reckon terms → Dolibarr payment term code (best-effort mapping)
$termsMap = [
    'net 30'       => '30D',
    'net 15'       => '14D',          // no 15D in Dolibarr — closest is 14
    'eom'          => '30DENDMONTH',
    'eom 15 days'  => '14DENDMONTH',  // closest
    'eom 30 days'  => '30DENDMONTH',
    '7 days'       => '10D',          // no 7D — closest is 10
    '14 days'      => '14D',
    'due on receipt' => 'RECEP',
];

function col(array $row, array $hi, string $key): string
{
    return trim($row[$hi[$key] ?? -1] ?? '');
}

// Parse "Suburb  QLD  4300" or "Suburb, QLD 4300" into town + zip
function parseSuburbLine(string $line): array
{
    if (preg_match('/^(.+?),?\s+(?:Qld?\.?|QLD|NSW|VIC|WA|SA|NT|ACT|TAS)\.?\s+(\d{4})\s*$/i', $line, $m)) {
        return ['town' => trim($m[1], ', '), 'zip' => $m[2]];
    }
    if (preg_match('/^(.+?)\s+(\d{4})\s*$/', $line, $m)) {
        return ['town' => trim($m[1], ', '), 'zip' => $m[2]];
    }
    return ['town' => trim($line), 'zip' => ''];
}

// --- Parse and filter CSV ---
$rows   = array_map('str_getcsv', file($csvIn));
$header = $rows[0];
$hi     = array_flip($header);

$customers = [];
foreach (array_slice($rows, 1) as $r) {
    if (count($r) < 10) continue;
    if (col($r, $hi, 'HIDDEN') === 'Y') continue;
    $customers[] = $r;
}

printf("Customers to import: %d%s\n\n", count($customers), $dryRun ? ' (DRY RUN)' : '');

// Pre-fetch existing third parties (by name) to skip duplicates
echo "Fetching existing third parties...\n";
$existing = [];
$page = 0;
do {
    $batch = $api->get('thirdparties', ['limit' => 500, 'page' => $page]);
    foreach ($batch as $t) $existing[strtolower($t['name'])] = (int) $t['id'];
    $page++;
} while (count($batch) === 500);
printf("  %d already exist\n\n", count($existing));

// --- Import ---
$log    = [['reckon_name', 'company_name', 'status', 'dolibarr_id', 'opening_balance', 'note']];
$counts = ['created' => 0, 'skipped' => 0, 'error' => 0];
$seen   = [];

foreach ($customers as $r) {
    $reckonName  = col($r, $hi, 'NAME');
    $companyName = col($r, $hi, 'COMPANYNAME') ?: $reckonName;
    // If companyName would duplicate an already-seen name, append the Reckon trading name
    $name = $companyName;
    if (isset($seen[strtolower($name)]) || isset($existing[strtolower($name)])) {
        $name = "$companyName ($reckonName)";
    }
    $seen[strtolower($companyName)] = true;

    // Address
    $baddr = array_filter(array_map('trim', [
        col($r, $hi, 'BADDR1'),
        col($r, $hi, 'BADDR2'),
        col($r, $hi, 'BADDR3'),
        col($r, $hi, 'BADDR4'),
        col($r, $hi, 'BADDR5'),
    ]));

    // BADDR1 is often the company name — skip it if it matches
    if (reset($baddr) === $companyName || reset($baddr) === $reckonName) {
        array_shift($baddr);
    }

    // Last address line likely has suburb + postcode
    $suburbLine = array_pop($baddr) ?? '';
    // If last line looks like part of street (no postcode), put it back
    if (!preg_match('/\d{4}/', $suburbLine)) {
        $baddr[] = $suburbLine;
        $suburbLine = '';
    }
    $geo     = parseSuburbLine($suburbLine);
    $street  = implode("\n", $baddr);

    // Phone — prefer PHONE1
    $phone = col($r, $hi, 'PHONE1') ?: col($r, $hi, 'PHONE2');
    $fax   = col($r, $hi, 'FAXNUM');
    $email = col($r, $hi, 'EMAIL');

    // Payment terms
    $reckonTerms = col($r, $hi, 'TERMS');
    $termCode    = $termsMap[strtolower($reckonTerms)] ?? null;

    // GST registration
    $taxable = col($r, $hi, 'TAXABLE') === 'Y' ? 1 : 0;
    $abn     = col($r, $hi, 'TAXID');

    // Opening balance (for reference only — enter manually at cutover)
    $ob = col($r, $hi, 'OBAMOUNT');

    // Notes
    $notepad = col($r, $hi, 'NOTEPAD');
    $cont1   = col($r, $hi, 'CONT1');
    $cont2   = col($r, $hi, 'CONT2');
    $ctype   = col($r, $hi, 'CTYPE');

    $noteParts = array_filter([
        $ctype                    ? "Type: $ctype"                   : '',
        $reckonTerms              ? "Reckon terms: $reckonTerms"      : '',
        !$termCode && $reckonTerms? "(no exact Dolibarr match)"       : '',
        $abn                      ? "ABN: $abn"                       : '',
        $ob && $ob !== '0.00'     ? "Opening balance at export: \$$ob (enter manually at cutover)" : '',
        $cont1                    ? "Contact 1: $cont1"               : '',
        $cont2                    ? "Contact 2: $cont2"               : '',
        $notepad                  ? "\n---\n$notepad"                  : '',
    ]);

    printf("  %-40s [%s]\n", $name, $reckonName);

    if (isset($existing[strtolower($name)])) {
        $counts['skipped']++;
        $log[] = [$reckonName, $name, 'skipped', $existing[strtolower($name)], $ob, 'already exists'];
        continue;
    }

    if ($dryRun) {
        $counts['created']++;
        $log[] = [$reckonName, $name, 'dry-run', '', $ob, ''];
        continue;
    }

    try {
        $body = [
            'name'         => $name,
            'name_alias'   => $reckonName !== $name ? $reckonName : '',
            'client'       => 1,
            'fournisseur'  => 0,
            'address'      => $street,
            'zip'          => $geo['zip'],
            'town'         => $geo['town'],
            'country_code' => 'AU',
            'phone'        => $phone,
            'fax'          => $fax,
            'email'        => $email,
            'tva_assuj'    => $taxable,
            'note_private' => implode("\n", $noteParts),
            'code_client'  => -1,  // -1 = auto-generate using Dolibarr's configured mask
        ];
        if ($abn) $body['idprof1'] = $abn;
        if ($termCode) $body['cond_reglement_code'] = $termCode;

        $id = (int) $api->post('thirdparties', $body);
        $existing[strtolower($name)] = $id;
        $counts['created']++;
        $log[] = [$reckonName, $name, 'created', $id, $ob, ''];

    } catch (RuntimeException $e) {
        $counts['error']++;
        $log[] = [$reckonName, $name, 'error', '', $ob, $e->getMessage()];
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

$fh = fopen($logFile, 'w');
foreach ($log as $row) fputcsv($fh, $row);
fclose($fh);

echo "\n";
printf("Created: %d  Skipped: %d  Errors: %d\n", $counts['created'], $counts['skipped'], $counts['error']);
printf("Log: %s\n", $logFile);

if ($counts['created'] > 0 && !$dryRun) {
    echo "\nREMINDER: The following customers have opening balances to enter manually at cutover:\n";
    foreach ($log as $row) {
        if ($row[4] && $row[4] !== '0.00' && $row[4] !== 'opening_balance') {
            printf("  %-40s \$%s\n", $row[1], $row[4]);
        }
    }
}
