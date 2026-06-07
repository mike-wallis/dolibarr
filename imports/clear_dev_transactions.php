<?php
/**
 * Delete all transactional data from dev (or live) DB.
 * Clears: invoices, orders, receptions, shipments, payments, stock movements,
 *         accounting ledger, and ECM file records.
 *
 * Usage:
 *   php imports/clear_dev_transactions.php           (dry run — dev)
 *   php imports/clear_dev_transactions.php --apply   (execute on dev)
 *   php imports/clear_dev_transactions.php --apply --live  (execute on live — use with care)
 */

$apply   = in_array('--apply', $argv ?? []);
$useLive = in_array('--live',  $argv ?? []);

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
$mode   = $apply ? '[APPLY]' : '[DRY RUN]';
echo "=== Clear Transactions $mode — DB: $target ===\n\n";

if ($useLive && $apply) {
    echo "WARNING: You are about to permanently delete all transactions from the LIVE database.\n";
    echo "Press Ctrl+C within 5 seconds to abort...\n";
    sleep(5);
    echo "Proceeding...\n\n";
}

function tableExists(PDO $pdo, string $table): bool {
    return (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"
    )->fetchColumn();
}

// Delete order: children before parents, FK checks disabled for safety
$groups = [
    'Customer invoices' => [
        'llx_paiement_facture'    => 'payment↔invoice links',
        'llx_paiement'            => 'customer payments',
        'llx_facturedet'          => 'customer invoice lines',
        'llx_facture_extrafields' => 'customer invoice extra fields',
        'llx_facture'             => 'customer invoice headers',
    ],
    'Supplier invoices' => [
        'llx_paiementfourn_facturefourn' => 'supplier payment↔invoice links',
        'llx_paiementfourn'              => 'supplier payments',
        'llx_facture_fourn_det'          => 'supplier invoice lines',
        'llx_facture_fourn_extrafields'  => 'supplier invoice extra fields',
        'llx_facture_fourn'              => 'supplier invoice headers',
    ],
    'Sales orders' => [
        'llx_commandedet'          => 'sales order lines',
        'llx_commande_extrafields' => 'sales order extra fields',
        'llx_commande'             => 'sales order headers',
    ],
    'Purchase orders' => [
        'llx_commande_fournisseurdet'          => 'purchase order lines',
        'llx_commande_fournisseur_extrafields' => 'purchase order extra fields',
        'llx_commande_fournisseur'             => 'purchase order headers',
    ],
    'Receptions' => [
        'llx_receptiondet' => 'reception lines',
        'llx_reception'    => 'reception headers',
    ],
    'Shipments' => [
        'llx_expeditiondet' => 'shipment lines',
        'llx_expedition'    => 'shipment headers',
    ],
    'Stock movements' => [
        'llx_stock_mouvement' => 'stock movements',
    ],
    'Accounting ledger' => [
        'llx_accounting_bookkeeping_tmp' => 'accounting bookkeeping (temp)',
        'llx_accounting_bookkeeping'     => 'accounting bookkeeping (ledger)',
    ],
    'ECM file records' => [
        'llx_ecm_files' => 'document PDF path records',
    ],
];

$totalRows = 0;

if ($apply) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
}

foreach ($groups as $groupName => $tables) {
    echo "── $groupName ──\n";
    foreach ($tables as $table => $desc) {
        if (!tableExists($pdo, $table)) {
            echo "  SKIP $table (does not exist)\n";
            continue;
        }
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $totalRows += $count;
        echo sprintf("  %s %5d rows  %-50s  (%s)\n",
            $apply ? 'DELETE' : 'WOULD DELETE',
            $count, $table, $desc
        );
        if ($apply) {
            $pdo->exec("DELETE FROM `$table`");
        }
    }
    echo "\n";
}

if ($apply) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

echo "─────────────────────────────────────────────\n";
echo ($apply ? 'Deleted' : 'Would delete') . " $totalRows total rows.\n";

if (!$apply) {
    echo "\nRun with --apply to execute";
    echo $useLive ? " --live for production" : " (add --live for production)";
    echo ".\n";
}
