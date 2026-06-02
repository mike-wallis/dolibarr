<?php
/**
 * Import suppliers from Reckon IIF/CSV export into Dolibarr.
 *
 * Skips HIDDEN=Y suppliers.
 * Bank details stored in note_private for reference when setting up payments.
 *
 * Usage:
 *   php import-suppliers.php             # import all
 *   php import-suppliers.php --dry-run   # preview without writing
 */

require_once __DIR__ . '/../api/DolibarrClient.php';

$dryRun = in_array('--dry-run', $argv);

foreach (file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$api     = new DolibarrClient($_ENV['DOLIBARR_URL'], $_ENV['DOLIBARR_API_KEY']);
$csvIn   = __DIR__ . '/../../imports/raw_samples/suppliers.csv';
$logFile = __DIR__ . '/../../imports/import-suppliers-log.csv';

$termsMap = [
    'net 30'         => '30D',
    'net 15'         => '14D',
    'net 45'         => '45D',
    'net 60'         => '60D',
    'eom'            => '30DENDMONTH',
    'eom 15 days'    => '14DENDMONTH',
    'eom 30 days'    => '30DENDMONTH',
    '7 days'         => '10D',
    '14 days'        => '14D',
    'due on receipt' => 'RECEP',
];

function col(array $row, array $hi, string $key): string
{
    return trim($row[$hi[$key] ?? -1] ?? '');
}

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

$suppliers = [];
foreach (array_slice($rows, 1) as $r) {
    if (count($r) < 10) continue;
    if (col($r, $hi, 'HIDDEN') === 'Y') continue;
    $suppliers[] = $r;
}

printf("Suppliers to import: %d%s\n\n", count($suppliers), $dryRun ? ' (DRY RUN)' : '');

// Pre-fetch existing third parties
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
$log    = [['reckon_key', 'company_name', 'status', 'dolibarr_id', 'note']];
$counts = ['created' => 0, 'skipped' => 0, 'error' => 0];
$seen   = [];

foreach ($suppliers as $r) {
    $reckonKey   = col($r, $hi, 'NAME');
    $companyName = col($r, $hi, 'COMPANYNAME') ?: col($r, $hi, 'PRINTAS') ?: $reckonKey;
    // Normalise case (e.g. "google" → "Google")
    $companyName = ucfirst($companyName);

    // Handle duplicate legal names by appending Reckon key
    $name = $companyName;
    if (isset($seen[strtolower($name)]) || isset($existing[strtolower($name)])) {
        $name = "$companyName ($reckonKey)";
    }
    $seen[strtolower($companyName)] = true;

    // Address — ADDR1 often repeats company name, skip if so
    $addr = array_filter(array_map('trim', [
        col($r, $hi, 'ADDR1'),
        col($r, $hi, 'ADDR2'),
        col($r, $hi, 'ADDR3'),
        col($r, $hi, 'ADDR4'),
        col($r, $hi, 'ADDR5'),
    ]));
    if (reset($addr) === $companyName || reset($addr) === $reckonKey) {
        array_shift($addr);
    }
    $suburbLine = array_pop($addr) ?? '';
    if (!preg_match('/\d{4}/', $suburbLine)) {
        $addr[]     = $suburbLine;
        $suburbLine = '';
    }
    $geo    = parseSuburbLine($suburbLine);
    $street = implode("\n", $addr);

    $phone = col($r, $hi, 'PHONE1') ?: col($r, $hi, 'PHONE2');
    $fax   = col($r, $hi, 'FAXNUM');
    $email = col($r, $hi, 'EMAIL');

    $reckonTerms = col($r, $hi, 'TERMS');
    $termCode    = $termsMap[strtolower($reckonTerms)] ?? null;

    $taxid   = col($r, $hi, 'TAXID');
    $notepad = col($r, $hi, 'NOTEPAD');
    $cont1   = col($r, $hi, 'CONT1');
    $cont2   = col($r, $hi, 'CONT2');
    $vtype   = col($r, $hi, 'VTYPE');

    // Bank details for payment setup
    $bankName   = col($r, $hi, 'BANKNAME');
    $acctNum    = col($r, $hi, 'ACCNTNUM');
    $branchNum  = col($r, $hi, 'BRANCHNUM');
    $lodgement  = col($r, $hi, 'LODGEMENT');

    $noteParts = array_filter([
        $vtype                                ? "Type: $vtype"                       : '',
        $reckonTerms                          ? "Reckon terms: $reckonTerms"          : '',
        !$termCode && $reckonTerms            ? "(no exact Dolibarr match)"           : '',
        $taxid                                ? "ABN: $taxid"                         : '',
        $cont1                                ? "Contact 1: $cont1"                   : '',
        $cont2                                ? "Contact 2: $cont2"                   : '',
        $bankName || $acctNum                 ? "Bank: $bankName BSB:$branchNum Acct:$acctNum" . ($lodgement ? " Ref:$lodgement" : '') : '',
        $notepad                              ? "\n---\n$notepad"                      : '',
    ]);

    printf("  %-45s [%s]\n", substr($name, 0, 45), $reckonKey);

    if (isset($existing[strtolower($name)])) {
        $counts['skipped']++;
        $log[] = [$reckonKey, $name, 'skipped', $existing[strtolower($name)], 'already exists'];
        continue;
    }

    if ($dryRun) {
        $counts['created']++;
        $log[] = [$reckonKey, $name, 'dry-run', '', ''];
        continue;
    }

    try {
        $body = [
            'name'                => $name,
            'name_alias'          => $reckonKey !== $name ? $reckonKey : '',
            'client'              => 0,
            'fournisseur'         => 1,
            'address'             => $street,
            'zip'                 => $geo['zip'],
            'town'                => $geo['town'],
            'country_code'        => 'AU',
            'phone'               => $phone,
            'fax'                 => $fax,
            'email'               => $email,
            'note_private'        => implode("\n", $noteParts),
            'code_fournisseur'    => -1,
        ];
        if ($taxid) $body['idprof1'] = $taxid;
        if ($termCode) $body['cond_reglement_code'] = $termCode;

        $id = (int) $api->post('thirdparties', $body);
        $existing[strtolower($name)] = $id;
        $counts['created']++;
        $log[] = [$reckonKey, $name, 'created', $id, ''];

    } catch (RuntimeException $e) {
        $counts['error']++;
        $log[] = [$reckonKey, $name, 'error', '', $e->getMessage()];
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

$fh = fopen($logFile, 'w');
foreach ($log as $row) fputcsv($fh, $row);
fclose($fh);

echo "\n";
printf("Created: %d  Skipped: %d  Errors: %d\n", $counts['created'], $counts['skipped'], $counts['error']);
printf("Log: %s\n", $logFile);
