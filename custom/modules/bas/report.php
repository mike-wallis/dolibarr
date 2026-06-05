<?php
/**
 * BAS & PAYG Withholding Report
 *
 * GST figures (1A, 1B) are calculated automatically from Dolibarr payment records (cash basis).
 * PAYG figures (W1, W2) and super are calculated from the accounting journal when accounts
 * are configured in Setup; otherwise they can be entered manually.
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

// ── Australian FY / quarter helpers ───────────────────────────────────────────

function bas_fy(int $m, int $y): int          { return ($m >= 7) ? $y + 1 : $y; }
function bas_quarter(int $m): int
{
    if ($m >= 7 && $m <= 9)  return 1;
    if ($m >= 10)             return 2;
    if ($m <= 3)              return 3;
    return 4;
}
function bas_dates(int $fy, int $q): array
{
    return match ($q) {
        1 => [($fy-1).'-07-01', ($fy-1).'-09-30'],
        2 => [($fy-1).'-10-01', ($fy-1).'-12-31'],
        3 => [$fy.'-01-01',     $fy.'-03-31'],
        4 => [$fy.'-04-01',     $fy.'-06-30'],
        default => ['',''],
    };
}
function bas_parse_accounts(string $s): array
{
    return array_values(array_filter(array_map('trim', explode(',', $s))));
}

$qlabels = [1=>'Q1 — Jul to Sep', 2=>'Q2 — Oct to Dec', 3=>'Q3 — Jan to Mar', 4=>'Q4 — Apr to Jun'];

// ── Resolve period ────────────────────────────────────────────────────────────

$now_m  = (int) date('n');
$now_y  = (int) date('Y');
$cur_fy = bas_fy($now_m, $now_y);
$cur_q  = bas_quarter($now_m);

$fy    = max(2020, min(2035, (int)(GETPOST('fy',    'int')   ?: $cur_fy)));
$q     = max(1,    min(4,    (int)(GETPOST('q',     'int')   ?: $cur_q)));
$basis = GETPOST('basis', 'alpha') === 'accrual' ? 'accrual' : 'cash';

[$qstart, $qend] = bas_dates($fy, $q);
$entity  = (int) $conf->entity;
$qs      = $db->escape($qstart);
$qe      = $db->escape($qend);

// ── Load account config from Setup ───────────────────────────────────────────

$acct_wages = bas_parse_accounts(getDolGlobalString('BAS_ACCOUNTS_WAGES'));
$acct_payg  = trim(getDolGlobalString('BAS_ACCOUNT_PAYG'));
$acct_super = bas_parse_accounts(getDolGlobalString('BAS_ACCOUNTS_SUPER'));
$has_payg_config = (!empty($acct_wages) || !empty($acct_payg));

// ── llx_const keys for saved overrides ───────────────────────────────────────

$key_w1    = 'BAS_W1_'    . $fy . $q;
$key_w2    = 'BAS_W2_'    . $fy . $q;
$key_recalc = 'recalc';   // GET param to force journal recalc

// ── Handle: save overrides ────────────────────────────────────────────────────

if ($action === 'save') {
    $parse_amt = fn($k) => max(0.0, (float) str_replace(',', '', GETPOST($k, 'alpha')));
    dolibarr_set_const($db, $key_w1, $parse_amt('w1'), 'chaine', 0, '', $entity);
    dolibarr_set_const($db, $key_w2, $parse_amt('w2'), 'chaine', 0, '', $entity);
    setEventMessages('PAYG saved for FY'.$fy.' '.$qlabels[$q].'.', null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF'].'?fy='.$fy.'&q='.$q.'&mainmenu=accountancy&leftmenu=bas_report');
    exit;
}

// ── Handle: clear overrides (recalculate from journal) ────────────────────────

if ($action === 'recalc') {
    dolibarr_del_const($db, $key_w1, $entity);
    dolibarr_del_const($db, $key_w2, $entity);
    header('Location: '.$_SERVER['PHP_SELF'].'?fy='.$fy.'&q='.$q.'&mainmenu=accountancy&leftmenu=bas_report');
    exit;
}

// ── GST queries — cash basis uses payment dates; accrual uses invoice dates ───

if ($basis === 'cash') {
    // Cash: sum amounts actually received/paid in the period
    $sql_sales =
        "SELECT COALESCE(SUM(pf.amount),0) AS sales_inc,"
        ."  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc,0)),0) AS gst_1a,"
        ."  COUNT(DISTINCT p.rowid) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."paiement p"
        ." INNER JOIN ".MAIN_DB_PREFIX."paiement_facture pf ON pf.fk_paiement=p.rowid"
        ." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid=pf.fk_facture"
        ." WHERE DATE(p.datep) BETWEEN '$qs' AND '$qe' AND f.entity=$entity";

    $sql_purch =
        "SELECT COALESCE(SUM(pf.amount),0) AS purch_inc,"
        ."  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc,0)),0) AS gst_1b,"
        ."  COUNT(DISTINCT p.rowid) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."paiementfourn p"
        ." INNER JOIN ".MAIN_DB_PREFIX."paiementfourn_facture pf ON pf.fk_paiementfourn=p.rowid"
        ." INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid=pf.fk_facturefourn"
        ." WHERE DATE(p.datep) BETWEEN '$qs' AND '$qe' AND f.entity=$entity";
} else {
    // Accrual: all validated invoices dated in the period (paid or not)
    $sql_sales =
        "SELECT COALESCE(SUM(total_ttc),0) AS sales_inc,"
        ."  COALESCE(SUM(total_tva),0) AS gst_1a,"
        ."  COUNT(*) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."facture"
        ." WHERE DATE(datef) BETWEEN '$qs' AND '$qe'"
        ." AND fk_statut >= 1 AND entity=$entity";

    $sql_purch =
        "SELECT COALESCE(SUM(total_ttc),0) AS purch_inc,"
        ."  COALESCE(SUM(total_tva),0) AS gst_1b,"
        ."  COUNT(*) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."facture_fourn"
        ." WHERE DATE(datef) BETWEEN '$qs' AND '$qe'"
        ." AND fk_statut >= 1 AND entity=$entity";
}

$r1 = $db->query($sql_sales); $rs = $r1 ? $db->fetch_object($r1) : null;
$r2 = $db->query($sql_purch); $rp = $r2 ? $db->fetch_object($r2) : null;

$sales_inc   = round((float)($rs?->sales_inc ?? 0), 2);
$gst_1a      = round((float)($rs?->gst_1a   ?? 0), 2);
$sales_cnt   = (int)($rs?->cnt ?? 0);
$purch_inc   = round((float)($rp?->purch_inc ?? 0), 2);
$gst_1b      = round((float)($rp?->gst_1b   ?? 0), 2);
$purch_cnt   = (int)($rp?->cnt ?? 0);

// ── Load GST account config ───────────────────────────────────────────────────

$acct_gst_collected = trim(getDolGlobalString('BAS_ACCOUNT_GST_COLLECTED'));
$acct_gst_itc       = trim(getDolGlobalString('BAS_ACCOUNT_GST_ITC'));

// ── PAYG / super: from accounting journal ────────────────────────────────────

// Helper: sum debit or credit entries for given accounts in the period
$journal_sum = function(array $accounts, string $col) use ($db, $qs, $qe, $entity): float {
    if (empty($accounts)) return 0.0;
    $col   = ($col === 'debit') ? 'debit' : 'credit';   // whitelist
    $in    = implode(',', array_map(fn($a) => "'".$db->escape($a)."'", $accounts));
    $sql   = "SELECT COALESCE(SUM($col),0) AS total"
           . " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping"
           . " WHERE numero_compte IN ($in)"
           . " AND DATE(doc_date) BETWEEN '$qs' AND '$qe'"
           . " AND entity=$entity";
    $r = $db->query($sql);
    return $r ? round((float)($db->fetch_object($r)?->total ?? 0), 2) : 0.0;
};

$journal_w1    = $journal_sum($acct_wages,              'debit');
$journal_w2    = !empty($acct_payg)           ? $journal_sum([$acct_payg],           'credit') : 0.0;
$journal_super = $journal_sum($acct_super,             'debit');

// Override invoice-total GST with journal figures if accounts are configured
$gst_source = 'invoices';
if (!empty($acct_gst_collected)) {
    $gst_1a     = $journal_sum([$acct_gst_collected], 'credit');
    $gst_source = 'journal';
}
if (!empty($acct_gst_itc)) {
    $gst_1b     = $journal_sum([$acct_gst_itc],       'debit');
    $gst_source = 'journal';
}
$net_gst = round($gst_1a - $gst_1b, 2);

// Decide whether to use journal values or saved overrides
$saved_w1 = getDolGlobalString($key_w1);
$saved_w2 = getDolGlobalString($key_w2);
$has_saved = ($saved_w1 !== '' || $saved_w2 !== '');

if ($has_saved) {
    // User has previously saved overrides — use those
    $w1     = (float) $saved_w1;
    $w2     = (float) $saved_w2;
    $w_source = 'saved';
} elseif ($has_payg_config) {
    // No overrides yet — pre-fill from journal
    $w1     = $journal_w1;
    $w2     = $journal_w2;
    $w_source = 'journal';
} else {
    // No config and no saves — blank manual entry
    $w1     = 0.0;
    $w2     = 0.0;
    $w_source = 'manual';
}

$super_display = $has_payg_config ? $journal_super : 0.0;
$total_payable = round($net_gst + $w2, 2);

// ── Format helpers ────────────────────────────────────────────────────────────

function bas_fmt(float $v): string
{
    return ($v < 0 ? '−' : '') . '$' . number_format(abs($v), 2);
}
function bas_row(string $code, string $label, float $amt, bool $bold=false, string $bg=''): string
{
    $s  = $bg ? ' style="background:'.$bg.'"' : '';
    $b  = $bold ? '<strong>' : '';
    $be = $bold ? '</strong>' : '';
    return '<tr'.$s.'>'
        .'<td class="nowrap" style="width:3rem;color:#666;">'.$b.htmlspecialchars($code).$be.'</td>'
        .'<td>'.$b.htmlspecialchars($label).$be.'</td>'
        .'<td class="right nowrap">'.$b.bas_fmt($amt).$be.'</td>'
        .'</tr>';
}

// ── Page output ───────────────────────────────────────────────────────────────

$title = 'BAS &amp; PAYG — FY'.$fy.' '.$qlabels[$q];
llxHeader('', strip_tags($title));
print dol_get_fiche_head([], '', $title, -1, 'accountancy');
?>

<!-- Period selector -->
<form method="get" action="report.php" style="margin-bottom:1.5rem;">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">
<strong>Period:</strong>&nbsp;
<select name="fy" class="flat">
<?php for ($y=$cur_fy; $y>=$cur_fy-5; $y--): ?>
  <option value="<?=$y?>" <?=$y===$fy?'selected':''?>>FY<?=$y?></option>
<?php endfor; ?>
</select>&nbsp;
<select name="q" class="flat">
<?php foreach ($qlabels as $n=>$lbl): ?>
  <option value="<?=$n?>" <?=$n===$q?'selected':''?>><?=htmlspecialchars($lbl)?></option>
<?php endforeach; ?>
</select>&nbsp;
<input type="submit" class="butAction" value="Show">
&nbsp;&nbsp;
<label style="font-weight:normal;">
  <input type="radio" name="basis" value="cash"    <?=$basis==='cash'   ?'checked':''?>> Cash
</label>
&nbsp;
<label style="font-weight:normal;">
  <input type="radio" name="basis" value="accrual" <?=$basis==='accrual'?'checked':''?>> Accrual
</label>
<span style="margin-left:1.5rem;color:#888;font-size:0.85em;"><?=$qstart?> to <?=$qend?></span>
</form>

<?php // ── Section 1: GST ──────────────────────────────────────────────────── ?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">
<table class="noborder" style="max-width:600px;">
<thead><tr class="liste_titre">
  <th colspan="3">
    GST &mdash; <?=$basis==='cash'?'cash basis (payment dates)':'accrual basis (invoice dates)'?>
    <?php if ($gst_source === 'journal'): ?>
      <span style="font-weight:normal;font-size:0.8em;margin-left:0.5rem;color:#2a7;"
            title="1A from account <?=htmlspecialchars($acct_gst_collected)?>, 1B from <?=htmlspecialchars($acct_gst_itc)?>">
        &#9432; from journal
      </span>
    <?php else: ?>
      <span style="font-weight:normal;font-size:0.8em;margin-left:1rem;">
        <?=$sales_cnt?> customer <?=$basis==='cash'?'payment':'invoice'?><?=$sales_cnt!==1?'s':''?>,
        <?=$purch_cnt?> supplier <?=$basis==='cash'?'payment':'invoice'?><?=$purch_cnt!==1?'s':''?>
      </span>
    <?php endif; ?>
  </th>
</tr></thead>
<tbody>
  <?=bas_row('G1',  'Total sales received (inc. GST)',   $sales_inc)?>
  <?=bas_row('1A',  'GST on sales',                      $gst_1a,  true, '#f0fff0')?>
  <?=bas_row('G11', 'Total purchases paid (inc. GST)',   $purch_inc)?>
  <?=bas_row('1B',  'GST credits on purchases',          $gst_1b,  true, '#f0fff0')?>
  <tr style="border-top:2px solid #ccc;">
    <td></td>
    <td><strong>Net GST <small style="font-weight:normal">(1A &minus; 1B)</small></strong></td>
    <td class="right nowrap"><strong><?=bas_fmt($net_gst)?></strong></td>
  </tr>
</tbody>
</table>
<?php if ($net_gst < 0): ?>
<p style="color:#c00;font-size:0.85em;">&#9888; Net GST is negative — enter 0 for field 9 GST and apply for a refund.</p>
<?php endif; ?>
</div>

<?php // ── Section 2: PAYG & Super ──────────────────────────────────────── ?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">

<?php
// Source badge
if ($w_source === 'journal') {
    $acct_summary = [];
    if (!empty($acct_wages)) $acct_summary[] = 'wages: '.implode(', ', $acct_wages);
    if (!empty($acct_payg))  $acct_summary[] = 'PAYG payable: '.$acct_payg;
    if (!empty($acct_super)) $acct_summary[] = 'super: '.implode(', ', $acct_super);
    echo '<p style="font-size:0.85em;color:#2a6496;margin-bottom:0.5rem;">'
        .'&#9432; Calculated from journal entries &mdash; '
        .htmlspecialchars(implode('; ', $acct_summary))
        .'. Edit and Save to override, or <a href="?fy='.$fy.'&q='.$q
        .'&action=recalc&token='.newToken().'&mainmenu=accountancy&leftmenu=bas_report">Recalculate</a>.</p>';
} elseif ($w_source === 'saved') {
    echo '<p style="font-size:0.85em;color:#888;margin-bottom:0.5rem;">'
        .'Showing saved overrides. '
        .($has_payg_config
            ? '<a href="?fy='.$fy.'&q='.$q.'&action=recalc&token='.newToken()
              .'&mainmenu=accountancy&leftmenu=bas_report">Recalculate from journal</a> (journal: W1 '
              .bas_fmt($journal_w1).', W2 '.bas_fmt($journal_w2).').'
            : '')
        .'</p>';
} else {
    echo '<p style="font-size:0.85em;color:#888;margin-bottom:0.5rem;">'
        .'No accounts configured — enter manually, or <a href="'.DOL_URL_ROOT
        .'/custom/bas/admin/setup.php">set up accounts</a>.</p>';
}
?>

<form method="post" action="report.php">
<input type="hidden" name="action"   value="save">
<input type="hidden" name="token"    value="<?php echo newToken(); ?>">
<input type="hidden" name="fy"       value="<?=$fy?>">
<input type="hidden" name="q"        value="<?=$q?>">
<input type="hidden" name="basis"    value="<?=$basis?>">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">

<table class="noborder" style="max-width:600px;">
<thead><tr class="liste_titre">
  <th colspan="3">PAYG Withholding<?php if (!$has_payg_config): ?> &mdash; manual entry<?php endif; ?></th>
</tr></thead>
<tbody>
  <tr>
    <td class="nowrap" style="width:3rem;color:#666;"><strong>W1</strong></td>
    <td>Total salary &amp; wages paid (before tax)
      <?php if ($has_payg_config && !empty($acct_wages)): ?>
        <small style="color:#888;">— accounts: <?=htmlspecialchars(implode(', ', $acct_wages))?></small>
      <?php endif; ?>
    </td>
    <td class="right">
      <input type="text" name="w1" class="flat right" style="width:9rem;"
             value="<?=$w1>0 ? number_format($w1,2) : ''?>" placeholder="0.00">
    </td>
  </tr>
  <tr>
    <td class="nowrap" style="width:3rem;color:#666;"><strong>W2</strong></td>
    <td>Tax withheld (PAYG to remit to ATO)
      <?php if (!empty($acct_payg)): ?>
        <small style="color:#888;">— account: <?=htmlspecialchars($acct_payg)?></small>
      <?php endif; ?>
    </td>
    <td class="right">
      <input type="text" name="w2" class="flat right" style="width:9rem;"
             value="<?=$w2>0 ? number_format($w2,2) : ''?>" placeholder="0.00">
    </td>
  </tr>
  <?php if ($has_payg_config && !empty($acct_super)): ?>
  <tr style="color:#666;">
    <td class="nowrap"><em>Super</em></td>
    <td><em>Superannuation expense (info only — not on BAS)
      <small>— accounts: <?=htmlspecialchars(implode(', ', $acct_super))?></small>
    </em></td>
    <td class="right"><em><?=bas_fmt($super_display)?></em></td>
  </tr>
  <?php endif; ?>
</tbody>
</table>
<div style="margin-top:0.5rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>
</div>

<?php // ── Section 3: Summary ───────────────────────────────────────────── ?>
<div class="div-table-responsive" style="margin-bottom:2rem;">
<table class="noborder" style="max-width:600px;">
<thead><tr class="liste_titre">
  <th colspan="3">BAS Summary &mdash; enter these on the ATO portal</th>
</tr></thead>
<tbody>
  <?=bas_row('1A', 'GST on sales',           $gst_1a, false, '#f9f9f9')?>
  <?=bas_row('1B', 'GST credits',            $gst_1b, false, '#f9f9f9')?>
  <?=bas_row('',   'Net GST (1A − 1B)',      $net_gst, true)?>
  <?php if ($w1 > 0 || $w2 > 0): ?>
  <tr><td colspan="3" style="padding:0.2rem;"></td></tr>
  <?=bas_row('W1', 'Wages paid (before tax)',  $w1, false, '#f9f9f9')?>
  <?=bas_row('W2', 'PAYG withheld',            $w2, false, '#f9f9f9')?>
  <?php endif; ?>
  <tr style="border-top:2px solid #aaa;background:#fffde7;">
    <td><strong>9</strong></td>
    <td><strong>Total payable to ATO</strong>
      <?php if ($w1 == 0 && $w2 == 0): ?>
        <small style="font-weight:normal;"> (enter W1 &amp; W2 above to include PAYG)</small>
      <?php endif; ?>
    </td>
    <td class="right nowrap"><strong><?=bas_fmt($total_payable)?></strong></td>
  </tr>
</tbody>
</table>
</div>

<div class="noprint">
  <button class="butAction" onclick="window.print()">Print / Save as PDF</button>
</div>

<style>
@media print {
  .noprint, form, .tabBar, .fiche .tabBar { display:none !important; }
  body, .fiche { font-size:12pt; }
  table { border-collapse:collapse; width:100%; }
  td, th { padding:4px 8px; border-bottom:1px solid #ccc; }
}
</style>

<?php
print dol_get_fiche_end();
llxFooter();
