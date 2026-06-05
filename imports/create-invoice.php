<?php
/**
 * Create a single invoice in Dolibarr from parsed invoice data.
 * Bootstraps Dolibarr so all triggers/accounting fire correctly.
 *
 * Run: php imports/create-invoice.php
 * Or:  http://dolibarr.test/imports/create-invoice.php
 */

define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREAPI', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIRETRAN', 1);
define('NOREQUIREUSER', 1);

chdir(dirname(__FILE__) . '/../htdocs');
require_once './main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

// ── Invoice data ──────────────────────────────────────────────────────────────

$invoiceData = [
    'ref_client'       => '21115',           // original Reckon invoice number
    'socid'            => 23,                // Yeronga Park Swimming Pool
    'date'             => '2026-05-28',
    'date_due'         => '2026-06-30',
    'cond_reglement_id'=> 3,                 // 30DENDMONTH — 30 days end of month
    'lines' => [
        [
            'fk_product' => 451,             // ESG30026
            'qty'        => 7,
            'subprice'   => 51.13,           // ex GST per unit
            'tva_tx'     => 10,
            'desc'       => 'Baywest Jumbo Rolls 2 Ply Recycled Paper., 6 x 300M',
            'product_type' => 0,             // 0=product
            'unit'       => 'Ctn',
        ],
    ],
];

// ── Create ────────────────────────────────────────────────────────────────────

$invoice = new Facture($db);

$invoice->socid              = $invoiceData['socid'];
$invoice->ref_client         = $invoiceData['ref_client'];
$invoice->date               = dol_mktime(0, 0, 0,
    (int) substr($invoiceData['date'], 5, 2),
    (int) substr($invoiceData['date'], 8, 2),
    (int) substr($invoiceData['date'], 0, 4)
);
$invoice->date_lim_reglement = dol_mktime(0, 0, 0,
    (int) substr($invoiceData['date_due'], 5, 2),
    (int) substr($invoiceData['date_due'], 8, 2),
    (int) substr($invoiceData['date_due'], 0, 4)
);
$invoice->cond_reglement_id  = $invoiceData['cond_reglement_id'];
$invoice->type               = Facture::TYPE_STANDARD;
$invoice->module_source      = 'import';

$db->begin();

$invoiceId = $invoice->create($user);
if ($invoiceId <= 0) {
    $db->rollback();
    die("Failed to create invoice: " . $invoice->error . "\n");
}

// Add lines
foreach ($invoiceData['lines'] as $line) {
    $result = $invoice->addline(
        $line['desc'],
        $line['subprice'],
        $line['qty'],
        $line['tva_tx'],
        0,              // localtax1
        0,              // localtax2
        $line['fk_product'],
        0,              // remise_percent
        '',             // date_start
        '',             // date_end
        0,              // ventil
        0,              // info_bits
        'HT',           // price_base_type
        $line['product_type']
    );
    if ($result < 0) {
        $db->rollback();
        die("Failed to add line: " . $invoice->error . "\n");
    }
}

// Validate (sets status to open, generates ref, books accounting)
$result = $invoice->validate($user);
if ($result < 0) {
    $db->rollback();
    die("Failed to validate invoice: " . $invoice->error . "\n");
}

$db->commit();

echo "Invoice created and validated." . PHP_EOL;
echo "  Dolibarr ref : " . $invoice->ref . PHP_EOL;
echo "  Original ref : " . $invoice->ref_client . PHP_EOL;
echo "  Total ex GST : $" . number_format($invoice->total_ht, 2) . PHP_EOL;
echo "  GST          : $" . number_format($invoice->total_tva, 2) . PHP_EOL;
echo "  Total inc GST: $" . number_format($invoice->total_ttc, 2) . PHP_EOL;
echo "  Due date     : " . dol_print_date($invoice->date_lim_reglement, 'day') . PHP_EOL;
