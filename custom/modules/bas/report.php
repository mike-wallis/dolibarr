<?php
/**
 * BAS & PAYG Withholding Report
 *
 * Calculates Australian Business Activity Statement figures:
 *   - GST collected (1A) and GST credits (1B) from Dolibarr payment records (cash basis)
 *   - PAYG withholding (W1, W2) entered manually and saved per quarter
 *
 * Accessed via Accounting > BAS & PAYG (left menu).
 */

$res = 0;
if (!$res && is_file('../../main.inc.php'))   { require '../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../main.inc.php')) { require '../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Australian financial year and quarter helpers ─────────────────────────────

function bas_fy_for_month(int $m, int $y): int
{
    return ($m >= 7) ? $y + 1 : $y;
}

function bas_quarter_for_month(int $m): int
{
    if ($m >= 7 && $m <= 9)  return 1;
    if ($m >= 10)             return 2;
    if ($m <= 3)              return 3;
    return 4;
}

/**
 * Returns [start_date, end_date] for the given Australian FY and quarter.
 * FY is the year the financial year ends (e.g. 2026 = Jul 2025–Jun 2026).
 */
function bas_quarter_dates(int $fy, int $q): array
{
    return match ($q) {
        1 => [($fy - 1) . '-07-01', ($fy - 1) . '-09-30'],
        2 => [($fy - 1) . '-10-01', ($fy - 1) . '-12-31'],
        3 => [$fy . '-01-01',       $fy . '-03-31'],
        4 => [$fy . '-04-01',       $fy . '-06-30'],
        default => ['', ''],
    };
}

$quarter_labels = [
    1 => 'Q1 — Jul to Sep',
    2 => 'Q2 — Oct to Dec',
    3 => 'Q3 — Jan to Mar',
    4 => 'Q4 — Apr to Jun',
];

// ── Resolve period ────────────────────────────────────────────────────────────

$now_m  = (int) date('n');
$now_y  = (int) date('Y');
$cur_fy = bas_fy_for_month($now_m, $now_y);
$cur_q  = bas_quarter_for_month($now_m);

$fy = (int) (GETPOST('fy', 'int') ?: $cur_fy);
$q  = (int) (GETPOST('q',  'int') ?: $cur_q);
$fy = max(2020, min(2035, $fy));
$q  = max(1, min(4, $q));

[$qstart, $qend] = bas_quarter_dates($fy, $q);

$const_w1 = 'BAS_W1_' . $fy . $q;
$const_w2 = 'BAS_W2_' . $fy . $q;

// ── Handle PAYG save ──────────────────────────────────────────────────────────

if ($action === 'save') {
    $w1 = max(0.0, (float) str_replace(',', '', GETPOST('w1', 'alpha')));
    $w2 = max(0.0, (float) str_replace(',', '', GETPOST('w2', 'alpha')));
    dolibarr_set_const($db, $const_w1, $w1, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, $const_w2, $w2, 'chaine', 0, '', $conf->entity);
    setEventMessages('PAYG saved for FY' . $fy . ' ' . $quarter_labels[$q] . '.', null, 'mesgs');
    $redirect = $_SERVER['PHP_SELF'] . '?fy=' . $fy . '&q=' . $q . '&mainmenu=accountancy&leftmenu=bas_report';
    header('Location: ' . $redirect);
    exit;
}

// ── Load saved PAYG ───────────────────────────────────────────────────────────

$w1 = (float) getDolGlobalString($const_w1);
$w2 = (float) getDolGlobalString($const_w2);

// ── GST queries (cash basis) ──────────────────────────────────────────────────

$entity = (int) $conf->entity;
$qs     = $db->escape($qstart);
$qe     = $db->escape($qend);

// 1A — GST collected: prorated against each payment applied to customer invoices
$sql_sales =
    "SELECT"
    . "  COALESCE(SUM(pf.amount), 0) AS sales_inc_gst,"
    . "  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc, 0)), 0) AS gst_1a,"
    . "  COUNT(DISTINCT p.rowid) AS pay_count"
    . " FROM " . MAIN_DB_PREFIX . "paiement p"
    . " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_paiement = p.rowid"
    . " INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = pf.fk_facture"
    . " WHERE DATE(p.datep) BETWEEN '" . $qs . "' AND '" . $qe . "'"
    . " AND f.entity = " . $entity;

$r_sales       = $db->query($sql_sales);
$row_sales     = ($r_sales && $db->num_rows($r_sales)) ? $db->fetch_object($r_sales) : null;
$sales_inc_gst = round((float)($row_sales->sales_inc_gst ?? 0), 2);
$gst_1a        = round((float)($row_sales->gst_1a        ?? 0), 2);
$sales_count   = (int)($row_sales->pay_count ?? 0);

// 1B — GST credits: prorated against each payment applied to supplier invoices
$sql_purch =
    "SELECT"
    . "  COALESCE(SUM(pf.amount), 0) AS purch_inc_gst,"
    . "  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc, 0)), 0) AS gst_1b,"
    . "  COUNT(DISTINCT p.rowid) AS pay_count"
    . " FROM " . MAIN_DB_PREFIX . "paiementfourn p"
    . " INNER JOIN " . MAIN_DB_PREFIX . "paiementfourn_facture pf ON pf.fk_paiementfourn = p.rowid"
    . " INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn f ON f.rowid = pf.fk_facturefourn"
    . " WHERE DATE(p.datep) BETWEEN '" . $qs . "' AND '" . $qe . "'"
    . " AND f.entity = " . $entity;

$r_purch       = $db->query($sql_purch);
$row_purch     = ($r_purch && $db->num_rows($r_purch)) ? $db->fetch_object($r_purch) : null;
$purch_inc_gst = round((float)($row_purch->purch_inc_gst ?? 0), 2);
$gst_1b        = round((float)($row_purch->gst_1b        ?? 0), 2);
$purch_count   = (int)($row_purch->pay_count ?? 0);

// Summary
$net_gst       = round($gst_1a - $gst_1b, 2);
$total_payable = round($net_gst + $w2, 2);

// ── Helpers ───────────────────────────────────────────────────────────────────

function bas_fmt(float $v): string
{
    $neg = $v < 0;
    return ($neg ? '−' : '') . '$' . number_format(abs($v), 2);
}

function bas_row(string $code, string $label, float $amount, bool $bold = false, string $bg = ''): string
{
    $style = $bg ? ' style="background:' . $bg . '"' : '';
    $wrap  = $bold ? '<strong>' : '';
    $wend  = $bold ? '</strong>' : '';
    return '<tr' . $style . '>'
        . '<td class="nowrap" style="width:3rem;color:#555;">' . htmlspecialchars($code) . '</td>'
        . '<td>' . $wrap . htmlspecialchars($label) . $wend . '</td>'
        . '<td class="right nowrap">' . $wrap . bas_fmt($amount) . $wend . '</td>'
        . '</tr>';
}

// ── Page output ───────────────────────────────────────────────────────────────

$title = 'BAS &amp; PAYG — FY' . $fy . ' ' . $quarter_labels[$q];
llxHeader('', strip_tags($title));
print dol_get_fiche_head([], '', $title, -1, 'accountancy');

// Period selector
?>
<form method="get" action="report.php" style="margin-bottom:1.5rem;">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">
<strong>Period:</strong>&nbsp;
<select name="fy" class="flat">
<?php for ($y = $cur_fy; $y >= $cur_fy - 5; $y--): ?>
  <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>>FY<?= $y ?></option>
<?php endfor; ?>
</select>
&nbsp;
<select name="q" class="flat">
<?php foreach ($quarter_labels as $n => $lbl): ?>
  <option value="<?= $n ?>" <?= $n === $q ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
<?php endforeach; ?>
</select>
&nbsp;
<input type="submit" class="butAction" value="Show">
<span style="margin-left:1.5rem;color:#888;font-size:0.85em;">
  <?= htmlspecialchars($qstart) ?> to <?= htmlspecialchars($qend) ?>
</span>
</form>

<?php
// ─── Section 1: GST ──────────────────────────────────────────────────────────
?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">
<table class="noborder" style="max-width:600px;">
<thead>
  <tr class="liste_titre">
    <th colspan="3">
      GST &mdash; calculated from Dolibarr (cash basis)
      <span style="font-weight:normal;font-size:0.8em;margin-left:1rem;">
        <?= $sales_count ?> customer payment<?= $sales_count !== 1 ? 's' : '' ?>,
        <?= $purch_count ?> supplier payment<?= $purch_count !== 1 ? 's' : '' ?>
      </span>
    </th>
  </tr>
</thead>
<tbody>
  <?= bas_row('G1',  'Total sales received (inc. GST)',    $sales_inc_gst) ?>
  <?= bas_row('1A',  'GST on sales',                       $gst_1a, true, '#f0fff0') ?>
  <?= bas_row('G11', 'Total purchases paid (inc. GST)',    $purch_inc_gst) ?>
  <?= bas_row('1B',  'GST credits on purchases',           $gst_1b, true, '#f0fff0') ?>
  <tr style="border-top:2px solid #ccc;">
    <td style="width:3rem;"></td>
    <td><strong>Net GST &nbsp;<small style="font-weight:normal;">(1A &minus; 1B)</small></strong></td>
    <td class="right nowrap"><strong><?= bas_fmt($net_gst) ?></strong></td>
  </tr>
</tbody>
</table>
<?php if ($net_gst < 0): ?>
<p style="color:#c00;font-size:0.85em;margin-top:0.25rem;">
  &#9888; Net GST is negative — you have more credits than collected. Enter 0 for field 9 GST and apply for a GST refund.
</p>
<?php endif; ?>
</div>

<?php
// ─── Section 2: PAYG Withholding ─────────────────────────────────────────────
?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">
<form method="post" action="report.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="fy" value="<?= $fy ?>">
<input type="hidden" name="q"  value="<?= $q ?>">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">

<table class="noborder" style="max-width:600px;">
<thead>
  <tr class="liste_titre">
    <th colspan="3">PAYG Withholding &mdash; enter from payroll records (Reckon)</th>
  </tr>
</thead>
<tbody>
  <tr>
    <td class="nowrap" style="width:3rem;color:#555;">W1</td>
    <td>Total salary &amp; wages paid (before tax)</td>
    <td class="right">
      <input type="text" name="w1" class="flat right" style="width:9rem;"
             value="<?= $w1 > 0 ? number_format($w1, 2) : '' ?>"
             placeholder="0.00">
    </td>
  </tr>
  <tr>
    <td class="nowrap" style="width:3rem;color:#555;">W2</td>
    <td>Tax withheld (PAYG you owe the ATO)</td>
    <td class="right">
      <input type="text" name="w2" class="flat right" style="width:9rem;"
             value="<?= $w2 > 0 ? number_format($w2, 2) : '' ?>"
             placeholder="0.00">
    </td>
  </tr>
</tbody>
</table>
<div style="margin-top:0.5rem;">
  <input type="submit" class="butAction" value="Save PAYG">
  <?php if ($w1 > 0 || $w2 > 0): ?>
    <span style="margin-left:1rem;color:#888;font-size:0.85em;">Last saved values shown above.</span>
  <?php endif; ?>
</div>
</form>
</div>

<?php
// ─── Section 3: BAS Summary ───────────────────────────────────────────────────
$show_payg = ($w1 > 0 || $w2 > 0);
?>
<div class="div-table-responsive" style="margin-bottom:2rem;">
<table class="noborder" style="max-width:600px;">
<thead>
  <tr class="liste_titre">
    <th colspan="3">BAS Summary &mdash; enter these on the ATO portal</th>
  </tr>
</thead>
<tbody>
  <?= bas_row('1A', 'GST on sales',         $gst_1a,  false, '#f9f9f9') ?>
  <?= bas_row('1B', 'GST credits',           $gst_1b,  false, '#f9f9f9') ?>
  <?= bas_row('',   'Net GST (1A &minus; 1B)', $net_gst, true) ?>
  <?php if ($show_payg): ?>
  <tr><td colspan="3" style="padding:0.25rem 0;"></td></tr>
  <?= bas_row('W1', 'Wages paid (before tax)', $w1, false, '#f9f9f9') ?>
  <?= bas_row('W2', 'PAYG withheld',           $w2, false, '#f9f9f9') ?>
  <?php endif; ?>
  <tr style="border-top:2px solid #aaa;background:#fffde7;">
    <td style="width:3rem;color:#555;"><strong>9</strong></td>
    <td><strong>Total payable to ATO</strong>
      <?php if (!$show_payg): ?>
        <small style="font-weight:normal;"> (enter W1 &amp; W2 above to include PAYG)</small>
      <?php endif; ?>
    </td>
    <td class="right nowrap"><strong><?= bas_fmt($total_payable) ?></strong></td>
  </tr>
</tbody>
</table>
</div>

<div class="noprint" style="margin-bottom:1rem;">
  <button class="butAction" onclick="window.print()">Print / Save as PDF</button>
</div>

<style>
@media print {
  .noprint, form, .fiche .tabBar { display: none !important; }
  body, .fiche { font-size: 12pt; }
  table { border-collapse: collapse; width: 100%; }
  td, th { padding: 4px 8px; border-bottom: 1px solid #ccc; }
}
</style>

<?php
print dol_get_fiche_end();
llxFooter();
