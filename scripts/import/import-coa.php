<?php
/**
 * Import Chart of Accounts from Reckon CSV export into Dolibarr.
 *
 * ⚠️  REQUIRES ACCOUNTANT REVIEW before running on production data.
 *    Review HowTo/COA.md and get sign-off on the mapping before go-live.
 *
 * Uses direct MySQL (not REST API — no COA endpoint exists).
 * Safe to run multiple times — skips accounts that already exist.
 *
 * Usage:
 *   php import-coa.php --dry-run   # preview without writing
 *   php import-coa.php             # import
 */

$dryRun = in_array('--dry-run', $argv);

foreach (file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$pdo = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$csvIn   = __DIR__ . '/../../imports/raw_samples/COA.CSV';
$logFile = __DIR__ . '/../../imports/import-coa-log.csv';
$PCG_VERSION = 'AU-SSS-2026';

// Reckon type → Dolibarr pcg_type
$typeMap = [
    'Bank'                   => 'ASSET',
    'Accounts Receivable'    => 'ASSET',
    'Other Current Asset'    => 'ASSET',
    'Fixed Asset'            => 'ASSET',
    'Other Asset'            => 'ASSET',
    'Accounts Payable'       => 'LIABILITY',
    'Other Current Liability'=> 'LIABILITY',
    'Long Term Liability'    => 'LIABILITY',
    'Equity'                 => 'EQUITY',
    'Income'                 => 'INCOME',
    'Cost of Goods Sold'     => 'EXPENSE',
    'Expense'                => 'EXPENSE',
    'Other Income'           => 'INCOME',
    'Other Expense'          => 'EXPENSE',
    'Non-Posting'            => null,  // skip — Reckon-only (estimates, POs, SOs)
];

// Accounts flagged for accountant review before go-live
$reviewFlags = [
    '1190', '1195',                         // director/employee loans
    '2300', '2310', '2314', '2316', '2317', // personal loans
    '2320', '2338', '2350',                 // loan suspense/personal
    '1800',                                 // owner income tax (not a company account)
    '2900',                                 // payments for named individual
];

function stripAccountPrefix(string $full): string
{
    // "1101 – Label" or "1500.01 – Label" — strip the number prefix (dash may be multi-byte)
    $clean = preg_replace('/^[\d.]+\s+\S+\s+/', '', trim($full));
    return $clean ?: trim($full);
}

// --- Parse CSV ---
$rows   = array_map('str_getcsv', file($csvIn));
$header = $rows[0]; // ,"Account","Type","Balance Total","Description","Accnt. #","Tax Line"
$hi     = array_flip($header);

// Column indices (first col is empty/blank)
$COL_ACCOUNT = 1; // full "XXXX – Label"
$COL_TYPE    = 2;
$COL_DESC    = 4;
$COL_NUM     = 5; // clean account number

$accounts = [];
foreach (array_slice($rows, 1) as $r) {
    if (count($r) < 6) continue;
    $num  = trim($r[$COL_NUM]);
    $type = trim($r[$COL_TYPE]);
    if ($num === '' || !isset($typeMap[$type])) continue;
    if ($typeMap[$type] === null) continue; // skip Non-Posting

    $label   = stripAccountPrefix($r[$COL_ACCOUNT]);
    $desc    = trim($r[$COL_DESC]);
    $pcgType = $typeMap[$type];
    $needsReview = in_array(strtok($num, '.'), $reviewFlags);

    // Determine parent account number (e.g. "4010.01" → parent is "4010")
    $parentNum = str_contains($num, '.') ? strtok($num, '.') : null;

    $accounts[$num] = [
        'num'          => $num,
        'label'        => $label,
        'desc'         => $desc,
        'reckon_type'  => $type,
        'pcg_type'     => $pcgType,
        'parent_num'   => $parentNum,
        'needs_review' => $needsReview,
    ];
}

$total = count($accounts);
printf("Accounts to import: %d%s\n\n", $total, $dryRun ? ' (DRY RUN)' : '');

// --- Ensure pcg_version exists ---
$existing = $pdo->query("SELECT pcg_version FROM llx_accounting_system WHERE pcg_version = '$PCG_VERSION'")->fetch();
if (!$existing) {
    if (!$dryRun) {
        $pdo->exec("INSERT INTO llx_accounting_system (fk_country, pcg_version, label, active)
                    VALUES (28, '$PCG_VERSION', 'South Side Supplies - Australian COA 2026', 1)");
        echo "Created pcg_version: $PCG_VERSION\n\n";
    } else {
        echo "[DRY] Would create pcg_version: $PCG_VERSION\n\n";
    }
}

// Pre-load existing account numbers for this version
$existingAccounts = [];
foreach ($pdo->query("SELECT account_number, rowid FROM llx_accounting_account WHERE fk_pcg_version = '$PCG_VERSION'") as $row) {
    $existingAccounts[$row['account_number']] = (int) $row['rowid'];
}
printf("Accounts already in Dolibarr: %d\n\n", count($existingAccounts));

// --- Pass 1: insert parent accounts (no dot in number) ---
echo "Pass 1: top-level accounts...\n";
$insertStmt = $pdo->prepare(
    "INSERT INTO llx_accounting_account (entity, fk_pcg_version, pcg_type, account_number, account_parent, label, labelshort, active, reconcilable)
     VALUES (1, :ver, :type, :num, 0, :label, :short, 1, 1)"
);

$counts = ['created' => 0, 'skipped' => 0];

foreach ($accounts as $num => $a) {
    if ($a['parent_num'] !== null) continue; // child — handle in pass 2
    if (isset($existingAccounts[$num])) { $counts['skipped']++; continue; }

    $reviewNote = $a['needs_review'] ? ' ⚠ REVIEW' : '';
    printf("  %s  %-50s [%s]%s\n", $num, substr($a['label'], 0, 50), $a['pcg_type'], $reviewNote);

    if (!$dryRun) {
        $insertStmt->execute([
            ':ver'   => $PCG_VERSION,
            ':type'  => $a['pcg_type'],
            ':num'   => $num,
            ':label' => $a['label'],
            ':short' => substr($a['label'], 0, 50),
        ]);
        $existingAccounts[$num] = (int) $pdo->lastInsertId();
    }
    $counts['created']++;
}

// --- Pass 2: insert child accounts ---
echo "\nPass 2: sub-accounts...\n";
foreach ($accounts as $num => $a) {
    if ($a['parent_num'] === null) continue; // parent — already done
    if (isset($existingAccounts[$num])) { $counts['skipped']++; continue; }

    $parentId = $existingAccounts[$a['parent_num']] ?? 0;
    $reviewNote = $a['needs_review'] ? ' ⚠ REVIEW' : '';
    printf("  %s  %-50s [%s]%s\n", $num, substr($a['label'], 0, 50), $a['pcg_type'], $reviewNote);

    if (!$dryRun) {
        $insertStmt->execute([
            ':ver'   => $PCG_VERSION,
            ':type'  => $a['pcg_type'],
            ':num'   => $num,
            ':label' => $a['label'],
            ':short' => substr($a['label'], 0, 50),
        ]);
        $existingAccounts[$num] = (int) $pdo->lastInsertId();
    }
    $counts['created']++;
}

// --- Write log / review CSV ---
$fh = fopen($logFile, 'w');
fputcsv($fh, ['account_num', 'label', 'reckon_type', 'dolibarr_type', 'parent', 'needs_accountant_review', 'notes']);
foreach ($accounts as $a) {
    fputcsv($fh, [
        $a['num'],
        $a['label'],
        $a['reckon_type'],
        $a['pcg_type'],
        $a['parent_num'] ?? '',
        $a['needs_review'] ? 'YES' : '',
        $a['desc'],
    ]);
}
fclose($fh);

echo "\n";
printf("Created: %d  Skipped: %d\n", $counts['created'], $counts['skipped']);
printf("Review CSV: %s\n", $logFile);
printf("\n⚠  %d accounts flagged for accountant review — see imports/import-coa-log.csv\n",
    count(array_filter($accounts, fn($a) => $a['needs_review'])));
