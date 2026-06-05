<?php
/**
 * BAS & PAYG Withholding Report
 *
 * Calculates Australian Business Activity Statement fields:
 *
 * GST:  G1, (G2, G3, G10, G11 — full mode), 1A, 1B
 * PAYG: W1, W2, (W3, W4 — full mode), W5
 * Summary: 1A, 1B, net GST, field 4 (=W5), field 9 (total payable)
 *
 * GST figures come from Dolibarr payment/invoice records (cash or accrual basis),
 * overridden by journal entries when accounts are configured in Setup.
 * PAYG figures come from the journal when accounts are configured, otherwise
 * manual entry saved per quarter.
 */

$res = 0;
if (!$res && is_file('../../main.inc.php'))   { require '../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../main.inc.php')) { require '../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Helpers ───────────────────────────────────────────────────────────────────

function bas_fy(int $m, int $y): int { return ($m >= 7) ? $y + 1 : $y; }
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
function bas_parse(string $s): array
{
    return array_values(array_filter(array_map('trim', explode(',', $s))));
}
function bas_fmt(float $v): string
{
    return ($v < 0 ? '−' : '').'$'.number_format(abs($v), 2);
}

$qlabels = [1=>'Q1 — Jul to Sep', 2=>'Q2 — Oct to Dec', 3=>'Q3 — Jan to Mar', 4=>'Q4 — Apr to Jun'];

// ── Period ────────────────────────────────────────────────────────────────────

$now_m  = (int) date('n');
$now_y  = (int) date('Y');
$cur_fy = bas_fy($now_m, $now_y);
$cur_q  = bas_quarter($now_m);

$fy    = max(2020, min(2035, (int)(GETPOST('fy',    'int') ?: $cur_fy)));
$q     = max(1,    min(4,    (int)(GETPOST('q',     'int') ?: $cur_q)));
$basis = GETPOST('basis', 'alpha') === 'accrual' ? 'accrual' : 'cash';

[$qstart_default, $qend_default] = bas_dates($fy, $q);

// Allow manual date override — user can adjust the calculated quarter dates
$from_raw = GETPOST('from', 'alpha');
$to_raw   = GETPOST('to',   'alpha');
$qstart   = ($from_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_raw)) ? $from_raw : $qstart_default;
$qend     = ($to_raw   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_raw))   ? $to_raw   : $qend_default;

$entity = (int) $conf->entity;
$qs     = $db->escape($qstart);
$qe     = $db->escape($qend);

// ── Load config ───────────────────────────────────────────────────────────────

$bas_type = getDolGlobalString('BAS_TYPE') ?: 'simpler';
$full     = ($bas_type === 'full');

$acct = [
    'gst_collected' => trim(getDolGlobalString('BAS_ACCOUNT_GST_COLLECTED')),
    'gst_itc'       => trim(getDolGlobalString('BAS_ACCOUNT_GST_ITC')),
    'g2'            => trim(getDolGlobalString('BAS_ACCOUNT_G2')),
    'g3'            => trim(getDolGlobalString('BAS_ACCOUNT_G3')),
    'g10'           => bas_parse(getDolGlobalString('BAS_ACCOUNTS_G10')),
    'wages'         => bas_parse(getDolGlobalString('BAS_ACCOUNTS_WAGES')),
    'payg'          => trim(getDolGlobalString('BAS_ACCOUNT_PAYG')),
    'w3'            => trim(getDolGlobalString('BAS_ACCOUNT_W3')),
    'w4'            => trim(getDolGlobalString('BAS_ACCOUNT_W4')),
    'super'         => bas_parse(getDolGlobalString('BAS_ACCOUNTS_SUPER')),
];

// ── llx_const keys for saved PAYG overrides (keyed by FY+Q, not custom dates) ──

$key = fn(string $f) => 'BAS_'.$f.'_'.$fy.$q;

// ── Handle: print (standalone HTML, no Dolibarr chrome) ──────────────────────

// Deferred — rendered after calculations below. Flag it here so we skip llxHeader.
$print_mode = ($action === 'print');

// ── Handle: save PAYG overrides ───────────────────────────────────────────────

if ($action === 'save') {
    $amt = fn($k) => max(0.0, (float) str_replace(',', '', GETPOST($k, 'alpha')));
    foreach (['w1','w2','w3','w4'] as $f) {
        dolibarr_set_const($db, $key(strtoupper($f)), $amt($f), 'chaine', 0, '', $entity);
    }
    setEventMessages('PAYG saved for FY'.$fy.' '.$qlabels[$q].'.', null, 'mesgs');
    header('Location: '.$_SERVER['PHP_SELF'].'?fy='.$fy.'&q='.$q.'&basis='.$basis.'&mainmenu=accountancy&leftmenu=bas_report');
    exit;
}

// ── Handle: clear overrides ───────────────────────────────────────────────────

if ($action === 'recalc') {
    foreach (['W1','W2','W3','W4'] as $f) {
        dolibarr_del_const($db, $key($f), $entity);
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?fy='.$fy.'&q='.$q.'&basis='.$basis.'&mainmenu=accountancy&leftmenu=bas_report');
    exit;
}

// ── Journal helper ────────────────────────────────────────────────────────────

$journal_sum = function(array $accounts, string $col) use ($db, $qs, $qe, $entity): float {
    if (empty($accounts)) return 0.0;
    $col = ($col === 'debit') ? 'debit' : 'credit';
    $in  = implode(',', array_map(fn($a) => "'".$db->escape($a)."'", $accounts));
    $sql = "SELECT COALESCE(SUM($col),0) AS total"
         . " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping"
         . " WHERE numero_compte IN ($in)"
         . " AND DATE(doc_date) BETWEEN '$qs' AND '$qe'"
         . " AND entity=$entity";
    $r   = $db->query($sql);
    $row = ($r && ($tmp = $db->fetch_object($r)) instanceof stdClass) ? $tmp : null;
    return round((float)($row?->total ?? 0), 2);
};

// ── GST: invoice/payment queries (always run for G1, G11, fallback 1A/1B) ────

// ── G1 / G11: informational totals from invoice/payment records ──────────────
// These are always available and show total sales/purchases volume.
// 1A and 1B are calculated ONLY from journal accounts (configured in Setup).

// inv_1a / inv_1b are prorated GST from invoice records — used for reconciliation
// against the journal figures, not for the actual BAS calculation.
if ($basis === 'cash') {
    $sql_sales =
        "SELECT COALESCE(SUM(pf.amount),0) AS g1,"
        ."  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc,0)),0) AS inv_1a,"
        ."  COUNT(DISTINCT p.rowid) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."paiement p"
        ." INNER JOIN ".MAIN_DB_PREFIX."paiement_facture pf ON pf.fk_paiement=p.rowid"
        ." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid=pf.fk_facture"
        ." WHERE DATE(p.datep) BETWEEN '$qs' AND '$qe' AND f.entity=$entity";

    $sql_purch =
        "SELECT COALESCE(SUM(pf.amount),0) AS g11,"
        ."  COALESCE(SUM(pf.amount * f.total_tva / NULLIF(f.total_ttc,0)),0) AS inv_1b,"
        ."  COUNT(DISTINCT p.rowid) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."paiementfourn p"
        ." INNER JOIN ".MAIN_DB_PREFIX."paiementfourn_facture pf ON pf.fk_paiementfourn=p.rowid"
        ." INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid=pf.fk_facturefourn"
        ." WHERE DATE(p.datep) BETWEEN '$qs' AND '$qe' AND f.entity=$entity";
} else {
    $sql_sales =
        "SELECT COALESCE(SUM(total_ttc),0) AS g1, COALESCE(SUM(total_tva),0) AS inv_1a, COUNT(*) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."facture"
        ." WHERE DATE(datef) BETWEEN '$qs' AND '$qe'"
        ." AND fk_statut >= 1 AND entity=$entity";

    $sql_purch =
        "SELECT COALESCE(SUM(total_ttc),0) AS g11, COALESCE(SUM(total_tva),0) AS inv_1b, COUNT(*) AS cnt"
        ." FROM ".MAIN_DB_PREFIX."facture_fourn"
        ." WHERE DATE(datef) BETWEEN '$qs' AND '$qe'"
        ." AND fk_statut >= 1 AND entity=$entity";
}

$r1  = $db->query($sql_sales);
$rs  = ($r1 && ($tmp = $db->fetch_object($r1)) instanceof stdClass) ? $tmp : null;
$r2  = $db->query($sql_purch);
$rp  = ($r2 && ($tmp = $db->fetch_object($r2)) instanceof stdClass) ? $tmp : null;

$g1        = round((float)($rs?->g1     ?? 0), 2);
$inv_1a    = round((float)($rs?->inv_1a ?? 0), 2);
$sales_cnt = (int)($rs?->cnt ?? 0);
$g11       = round((float)($rp?->g11    ?? 0), 2);
$inv_1b    = round((float)($rp?->inv_1b ?? 0), 2);
$purch_cnt = (int)($rp?->cnt ?? 0);

// ── Full-mode extras (G2, G3, G10) from journal ───────────────────────────────

$g2  = $full && !empty($acct['g2'])  ? $journal_sum([$acct['g2']],  'credit') : 0.0;
$g3  = $full && !empty($acct['g3'])  ? $journal_sum([$acct['g3']],  'credit') : 0.0;
$g10 = $full && !empty($acct['g10']) ? $journal_sum($acct['g10'],   'debit')  : 0.0;

// ── 1A and 1B: REQUIRED from journal accounts — null if not configured ────────

$gst_1a = !empty($acct['gst_collected']) ? $journal_sum([$acct['gst_collected']], 'credit') : null;
$gst_1b = !empty($acct['gst_itc'])       ? $journal_sum([$acct['gst_itc']],       'debit')  : null;

$gst_ready = ($gst_1a !== null && $gst_1b !== null);
$net_gst   = $gst_ready ? round($gst_1a - $gst_1b, 2) : null;

// ── Reconciliation: journal vs invoice figures ────────────────────────────────
// Amounts within $0.10 of each other are considered a match (handles rounding).
$recon_tolerance = 0.10;
$recon_1a_diff   = $gst_ready ? round($gst_1a - $inv_1a, 2) : null;
$recon_1b_diff   = $gst_ready ? round($gst_1b - $inv_1b, 2) : null;
$recon_1a_ok     = $recon_1a_diff !== null && abs($recon_1a_diff) <= $recon_tolerance;
$recon_1b_ok     = $recon_1b_diff !== null && abs($recon_1b_diff) <= $recon_tolerance;
$recon_ok        = $recon_1a_ok && $recon_1b_ok;

// ── PAYG from journal ─────────────────────────────────────────────────────────

$journal_w1    = $journal_sum($acct['wages'],               'debit');
$journal_w2    = !empty($acct['payg']) ? $journal_sum([$acct['payg']], 'credit') : 0.0;
$journal_w3    = !empty($acct['w3'])   ? $journal_sum([$acct['w3']],   'credit') : 0.0;
$journal_w4    = !empty($acct['w4'])   ? $journal_sum([$acct['w4']],   'credit') : 0.0;
$journal_super = $journal_sum($acct['super'],               'debit');

$has_payg_config = !empty($acct['wages']) || !empty($acct['payg']);

// Load saved overrides; fall back to journal values if not saved
$saved = [];
foreach (['W1','W2','W3','W4'] as $f) {
    $saved[$f] = getDolGlobalString($key($f));
}
$has_saved = array_filter($saved, fn($v) => $v !== '');

$resolve = function(string $f, float $journal_val) use ($saved, $has_payg_config): float {
    if ($saved[$f] !== '') return (float) $saved[$f];
    if ($has_payg_config)  return $journal_val;
    return 0.0;
};

$w1 = $resolve('W1', $journal_w1);
$w2 = $resolve('W2', $journal_w2);
$w3 = $resolve('W3', $journal_w3);
$w4 = $resolve('W4', $journal_w4);
$w5 = round($w2 + $w3 + $w4, 2);  // W5 = W2 + W3 + W4

// Field 4 = W5 (PAYG withholding transferred to BAS payment section)
$field_4 = $w5;
// Field 9 only available when GST accounts are configured
$field_9 = $gst_ready ? round($net_gst + $field_4, 2) : null;

$w_source = $has_saved ? 'saved' : ($has_payg_config ? 'journal' : 'manual');

// ── Print mode — standalone HTML, no Dolibarr chrome ─────────────────────────

if ($print_mode) {
    $co_name = htmlspecialchars(getDolGlobalString('MAIN_INFO_SOCIETE_NOM') ?: 'South Side Supplies');
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html><html lang="en"><head>
    <meta charset="utf-8">
    <title>BAS &amp; PAYG — FY<?=$fy?> <?=htmlspecialchars($qlabels[$q])?></title>
    <style>
      body  { font-family:Arial,sans-serif; font-size:11pt; margin:2cm; color:#000; }
      h1    { font-size:14pt; margin-bottom:0.3rem; }
      .meta { font-size:9pt; color:#666; margin-bottom:1.5rem; }
      h2    { font-size:10pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.05em;
              margin:1.2rem 0 0.2rem; border-bottom:1px solid #ccc; padding-bottom:3px; }
      table { width:100%; border-collapse:collapse; margin-bottom:0.5rem; }
      td    { padding:4px 6px; border-bottom:1px solid #eee; }
      td.code { width:3rem; color:#777; font-size:9pt; }
      td.amt  { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
      tr.sub td { font-weight:bold; }
      tr.total td { font-weight:bold; border-top:2px solid #000; border-bottom:none; }
      tr.payable td { font-weight:bold; font-size:12pt; background:#fffde7; border-top:2px solid #999; }
      footer { margin-top:3rem; font-size:8pt; color:#aaa; border-top:1px solid #eee; padding-top:0.5rem; }
    </style></head><body>
    <h1><?=$co_name?> — Business Activity Statement</h1>
    <p class="meta">
      <?=htmlspecialchars($qlabels[$q])?> &nbsp;FY<?=$fy?> &nbsp;|&nbsp;
      <?=$qstart?> to <?=$qend?> &nbsp;|&nbsp;
      <?=ucfirst($basis)?> basis &nbsp;|&nbsp; <?=ucfirst($bas_type)?> BAS
    </p>

    <h2>GST</h2>
    <table>
      <tr><td class="code">G1</td><td>Total sales (inc. GST)</td><td class="amt"><?=bas_fmt($g1)?></td></tr>
      <?php if ($full && ($g2 || $g3)): ?>
      <tr><td class="code">G2</td><td>Export sales</td><td class="amt"><?=bas_fmt($g2)?></td></tr>
      <tr><td class="code">G3</td><td>Other GST-free sales</td><td class="amt"><?=bas_fmt($g3)?></td></tr>
      <?php endif; ?>
      <tr><td class="code">G11</td><td>Total purchases (inc. GST)</td><td class="amt"><?=bas_fmt($g11)?></td></tr>
      <?php if ($full && $g10): ?>
      <tr><td class="code">G10</td><td>Capital purchases (inc. GST)</td><td class="amt"><?=bas_fmt($g10)?></td></tr>
      <?php endif; ?>
      <tr class="sub"><td class="code">1A</td><td>GST on sales</td><td class="amt"><?=bas_fmt($gst_1a)?></td></tr>
      <tr class="sub"><td class="code">1B</td><td>GST credits on purchases</td><td class="amt"><?=bas_fmt($gst_1b)?></td></tr>
      <tr class="total"><td></td><td>Net GST (1A &minus; 1B)</td><td class="amt"><?=bas_fmt($net_gst)?></td></tr>
    </table>

    <?php if ($w1 > 0 || $w5 > 0): ?>
    <h2>PAYG Withholding</h2>
    <table>
      <tr><td class="code">W1</td><td>Total wages paid (before tax)</td><td class="amt"><?=bas_fmt($w1)?></td></tr>
      <tr><td class="code">W2</td><td>Withheld from wages</td><td class="amt"><?=bas_fmt($w2)?></td></tr>
      <?php if ($full && ($w3 || $w4)): ?>
      <tr><td class="code">W3</td><td>Other amounts withheld</td><td class="amt"><?=bas_fmt($w3)?></td></tr>
      <tr><td class="code">W4</td><td>Withheld — no ABN</td><td class="amt"><?=bas_fmt($w4)?></td></tr>
      <?php endif; ?>
      <tr class="total"><td class="code">W5</td><td>Total PAYG withheld</td><td class="amt"><?=bas_fmt($w5)?></td></tr>
    </table>
    <?php endif; ?>

    <?php if (!empty($acct['super']) && $journal_super > 0): ?>
    <h2>Superannuation (informational — not on BAS)</h2>
    <table>
      <tr><td></td><td>Superannuation expense</td><td class="amt"><?=bas_fmt($journal_super)?></td></tr>
    </table>
    <?php endif; ?>

    <h2>BAS Summary — amounts to enter on the ATO portal</h2>
    <table>
      <tr><td class="code">1A</td><td>GST on sales</td><td class="amt"><?=bas_fmt($gst_1a)?></td></tr>
      <tr><td class="code">1B</td><td>GST credits</td><td class="amt"><?=bas_fmt($gst_1b)?></td></tr>
      <tr class="sub"><td></td><td>Net GST (1A &minus; 1B)</td><td class="amt"><?=bas_fmt($net_gst)?></td></tr>
      <?php if ($w5 > 0): ?>
      <tr><td class="code">W1</td><td>Wages paid</td><td class="amt"><?=bas_fmt($w1)?></td></tr>
      <tr><td class="code">W5</td><td>Total PAYG withheld</td><td class="amt"><?=bas_fmt($w5)?></td></tr>
      <tr><td class="code">4</td><td>PAYG withholding (= W5)</td><td class="amt"><?=bas_fmt($field_4)?></td></tr>
      <?php endif; ?>
      <tr class="payable"><td class="code">9</td><td>Total payable to ATO</td><td class="amt"><?=bas_fmt($field_9)?></td></tr>
    </table>

    <?php if ($field_9 < 0): ?>
    <p style="color:#2a7;">ATO owes you a refund of <?=bas_fmt(abs($field_9))?>.</p>
    <?php endif; ?>

    <footer>
      Prepared from <?=$co_name?> Dolibarr &mdash; printed <?=date('d/m/Y H:i')?>
    </footer>
    <script>window.print();</script>
    </body></html><?php
    exit;
}

// ── Page ──────────────────────────────────────────────────────────────────────

function bas_tr(string $code, string $label, float $amt, bool $bold = false, string $bg = ''): string
{
    $s  = $bg ? ' style="background:'.$bg.'"' : '';
    $b  = $bold ? '<strong>' : '';
    $be = $bold ? '</strong>' : '';
    return '<tr'.$s.'>'
        .'<td class="nowrap" style="width:3rem;color:#555;">'.$b.htmlspecialchars($code).$be.'</td>'
        .'<td>'.$b.htmlspecialchars($label).$be.'</td>'
        .'<td class="right nowrap">'.$b.bas_fmt($amt).$be.'</td>'
        .'</tr>';
}

$title = 'BAS &amp; PAYG — FY'.$fy.' '.$qlabels[$q].' ('.$bas_type.')';
llxHeader('', strip_tags($title));
print dol_get_fiche_head([], '', $title, -1, 'accountancy');
print load_fiche_titre('Australian BAS &amp; PAYG Activity Statement', '', 'accountancy');
?>

<!-- Period + basis selector -->
<form method="get" action="report.php" style="margin-bottom:1rem;">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">
<table style="border:none;margin:0;">
<tr>
  <td style="padding:0 6px 4px 0;white-space:nowrap;"><strong>Quarter:</strong></td>
  <td style="padding:0 6px 4px 0;">
    <select name="fy" id="bas_fy" class="flat">
    <?php for ($y=$cur_fy; $y>=$cur_fy-5; $y--): ?>
      <option value="<?=$y?>" <?=$y===$fy?'selected':''?>>FY<?=$y?></option>
    <?php endfor; ?>
    </select>
  </td>
  <td style="padding:0 6px 4px 0;">
    <select name="q" id="bas_q" class="flat">
    <?php foreach ($qlabels as $n=>$lbl): ?>
      <option value="<?=$n?>" <?=$n===$q?'selected':''?>><?=htmlspecialchars($lbl)?></option>
    <?php endforeach; ?>
    </select>
  </td>
  <td style="padding:0 10px 4px 0;color:#aaa;">&#x2192;</td>
  <td style="padding:0 4px 4px 0;white-space:nowrap;"><strong>From:</strong></td>
  <td style="padding:0 6px 4px 0;">
    <input type="date" name="from" id="bas_from" class="flat" value="<?=htmlspecialchars($qstart)?>">
  </td>
  <td style="padding:0 6px 4px 0;white-space:nowrap;"><strong>To:</strong></td>
  <td style="padding:0 10px 4px 0;">
    <input type="date" name="to" id="bas_to" class="flat" value="<?=htmlspecialchars($qend)?>">
  </td>
  <td style="padding:0 10px 4px 0;">
    <input type="submit" class="butAction" value="Refresh">
  </td>
  <td style="padding:0 0 4px 0;white-space:nowrap;">
    <label style="font-weight:normal;margin-right:0.5rem;">
      <input type="radio" name="basis" value="cash"    <?=$basis==='cash'   ?'checked':''?>> Cash
    </label>
    <label style="font-weight:normal;">
      <input type="radio" name="basis" value="accrual" <?=$basis==='accrual'?'checked':''?>> Accrual
    </label>
  </td>
</tr>
</table>
</form>
<script>
(function () {
    var qDates = {
        1: function(fy) { return [fy-1+'-07-01', fy-1+'-09-30']; },
        2: function(fy) { return [fy-1+'-10-01', fy-1+'-12-31']; },
        3: function(fy) { return [fy+'-01-01',   fy+'-03-31']; },
        4: function(fy) { return [fy+'-04-01',   fy+'-06-30']; }
    };
    function fillDates() {
        var fy = parseInt(document.getElementById('bas_fy').value);
        var q  = parseInt(document.getElementById('bas_q').value);
        if (qDates[q]) {
            var d = qDates[q](fy);
            document.getElementById('bas_from').value = d[0];
            document.getElementById('bas_to').value   = d[1];
        }
    }
    document.getElementById('bas_fy').addEventListener('change', fillDates);
    document.getElementById('bas_q').addEventListener('change',  fillDates);
}());
</script>

<?php
// ── Section 1: GST ──────────────────────────────────────────────────────────
$vol_note = $basis === 'cash'
    ? $sales_cnt.' customer payment'.($sales_cnt!==1?'s':'').', '.$purch_cnt.' supplier payment'.($purch_cnt!==1?'s':'')
    : $sales_cnt.' customer invoice'.($sales_cnt!==1?'s':'').', '.$purch_cnt.' supplier invoice'.($purch_cnt!==1?'s':'');
?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">

<?php if (!$gst_ready): ?>
<div style="padding:0.75rem 1rem;background:#fff3cd;border-left:4px solid #e6a817;border-radius:3px;margin-bottom:0.75rem;max-width:620px;">
  <strong>&#9888; GST accounts not configured.</strong>
  G1 and G11 (volume totals) are shown below, but <strong>1A, 1B, and Net GST cannot be calculated</strong>
  until you configure the GST Collected and GST Paid (purchases) accounts in
  <a href="<?=DOL_URL_ROOT?>/custom/bas/admin/setup.php"><strong>Setup</strong></a>.
</div>
<?php endif; ?>

<table class="noborder" style="max-width:620px;">
<thead><tr class="liste_titre">
  <th colspan="3">
    GST &mdash; <?=$basis==='cash'?'cash basis':'accrual basis'?>
    <span style="font-weight:normal;font-size:0.8em;margin-left:1rem;color:#888;"><?=$vol_note?></span>
    <?php if ($gst_ready): ?>
    <span style="font-weight:normal;font-size:0.8em;margin-left:0.5rem;color:#2a7;">&#9432; 1A/1B from journal</span>
    <?php endif; ?>
  </th>
</tr></thead>
<tbody>
  <?=bas_tr('G1', 'Total sales (inc. GST)', $g1)?>
  <?php if ($full): ?>
  <?=bas_tr('G2', 'Export sales (GST-free)', $g2)?>
  <?=bas_tr('G3', 'Other GST-free sales', $g3)?>
  <?php endif; ?>
  <?=bas_tr('G11', 'Total purchases (inc. GST)', $g11)?>
  <?php if ($full): ?>
  <?=bas_tr('G10', 'Capital purchases (inc. GST)', $g10)?>
  <?php endif; ?>
  <?php if ($gst_ready): ?>
  <tr><td colspan="3" style="padding:0.2rem;border:none;"></td></tr>
  <?=bas_tr('1A', 'GST on sales', $gst_1a, true, '#f0fff0')?>
  <?=bas_tr('1B', 'GST credits on purchases', $gst_1b, true, '#f0fff0')?>
  <tr style="border-top:2px solid #ccc;">
    <td></td>
    <td><strong>Net GST <small style="font-weight:normal">(1A &minus; 1B)</small></strong></td>
    <td class="right nowrap"><strong><?=bas_fmt($net_gst)?></strong></td>
  </tr>
  <?php endif; ?>
</tbody>
</table>
<?php if ($gst_ready && $net_gst < 0): ?>
<p style="color:#c00;font-size:0.85em;margin-top:0.25rem;">&#9888; Net GST is negative — you have more credits than collected. Enter 0 for field 9 GST and apply for a refund.</p>
<?php endif; ?>
<?php if ($full && ($g2 > 0 || $g3 > 0)): ?>
<p style="font-size:0.85em;color:#555;margin-top:0.25rem;">
  Note: G2 and G3 are subsets of G1. Taxable sales = G1 &minus; G2 &minus; G3 = <?=bas_fmt($g1-$g2-$g3)?>.
</p>
<?php endif; ?>

<?php if ($gst_ready): ?>
<div style="margin-top:0.75rem;max-width:620px;padding:0.6rem 1rem;border-radius:3px;font-size:0.85em;
     background:<?=$recon_ok?'#f0fff0':'#fff3cd'?>;border-left:4px solid <?=$recon_ok?'#4caf50':'#e6a817'?>;">
  <?php if ($recon_ok): ?>
    <strong>&#10003; Reconciled</strong> — journal figures match invoice totals
    (1A <?=bas_fmt($gst_1a)?>, 1B <?=bas_fmt($gst_1b)?>).
  <?php else: ?>
    <strong>&#9888; Reconciliation difference detected</strong> — journal and invoice totals do not match.
    Check for invoices not yet transferred to accounting.
    <table style="margin-top:0.5rem;border-collapse:collapse;width:100%;">
    <tr style="color:#555;">
      <th style="text-align:left;font-weight:normal;padding:2px 8px 2px 0;width:3rem;"></th>
      <th style="text-align:right;font-weight:normal;padding:2px 8px;">Journal (used)</th>
      <th style="text-align:right;font-weight:normal;padding:2px 8px;">From invoices</th>
      <th style="text-align:right;font-weight:normal;padding:2px 8px;">Difference</th>
    </tr>
    <tr>
      <td style="padding:2px 8px 2px 0;color:#555;">1A</td>
      <td style="text-align:right;padding:2px 8px;"><?=bas_fmt($gst_1a)?></td>
      <td style="text-align:right;padding:2px 8px;"><?=bas_fmt($inv_1a)?></td>
      <td style="text-align:right;padding:2px 8px;color:<?=$recon_1a_ok?'#4caf50':'#c00'?>;">
        <?=$recon_1a_ok ? '&#10003;' : bas_fmt($recon_1a_diff)?>
      </td>
    </tr>
    <tr>
      <td style="padding:2px 8px 2px 0;color:#555;">1B</td>
      <td style="text-align:right;padding:2px 8px;"><?=bas_fmt($gst_1b)?></td>
      <td style="text-align:right;padding:2px 8px;"><?=bas_fmt($inv_1b)?></td>
      <td style="text-align:right;padding:2px 8px;color:<?=$recon_1b_ok?'#4caf50':'#c00'?>;">
        <?=$recon_1b_ok ? '&#10003;' : bas_fmt($recon_1b_diff)?>
      </td>
    </tr>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<?php
// ── Section 2: PAYG ──────────────────────────────────────────────────────────
if ($w_source === 'journal') {
    $w_note = '&#9432; calculated from journal entries';
} elseif ($w_source === 'saved') {
    $j_note = $has_payg_config
        ? ' (journal: W1 '.bas_fmt($journal_w1).', W2 '.bas_fmt($journal_w2).')'
        : '';
    $recalc_url = '?fy='.$fy.'&q='.$q.'&basis='.$basis.'&action=recalc&token='.newToken().'&mainmenu=accountancy&leftmenu=bas_report';
    $w_note = 'Showing saved overrides'.$j_note.'. <a href="'.$recalc_url.'">Recalculate from journal</a>.';
} else {
    $w_note = 'No accounts configured — <a href="'.DOL_URL_ROOT.'/custom/bas/admin/setup.php">set up accounts</a> or enter manually.';
}
?>
<div class="div-table-responsive" style="margin-bottom:1.5rem;">
<p style="font-size:0.85em;color:#<?=$w_source==='journal'?'2a7':'888'?>;margin-bottom:0.5rem;"><?=$w_note?></p>

<form method="post" action="report.php">
<input type="hidden" name="action"   value="save">
<input type="hidden" name="token"    value="<?php echo newToken(); ?>">
<input type="hidden" name="fy"       value="<?=$fy?>">
<input type="hidden" name="q"        value="<?=$q?>">
<input type="hidden" name="basis"    value="<?=$basis?>">
<input type="hidden" name="mainmenu" value="accountancy">
<input type="hidden" name="leftmenu" value="bas_report">

<table class="noborder" style="max-width:620px;">
<thead><tr class="liste_titre"><th colspan="3">PAYG Withholding</th></tr></thead>
<tbody>

<?php
// Helper: editable PAYG row
function payg_row(string $code, string $label, float $val, ?string $acct_hint = null): string
{
    $hint = $acct_hint ? '<small style="color:#888;"> — account: '.htmlspecialchars($acct_hint).'</small>' : '';
    return '<tr>'
        .'<td class="nowrap" style="width:3rem;color:#555;"><strong>'.htmlspecialchars($code).'</strong></td>'
        .'<td>'.htmlspecialchars($label).$hint.'</td>'
        .'<td class="right"><input type="text" name="'.strtolower($code).'" class="flat right" style="width:9rem;"'
        .' value="'.($val > 0 ? number_format($val, 2) : '').'" placeholder="0.00"></td>'
        .'</tr>';
}

echo payg_row('W1', 'Total wages & salary paid (before tax)', $w1,
    !empty($acct['wages']) ? implode(', ', $acct['wages']) : null);
echo payg_row('W2', 'Withheld from wages (PAYG to ATO)', $w2,
    $acct['payg'] ?: null);
if ($full) {
    echo payg_row('W3', 'Other amounts withheld (not W2 or W4)', $w3,
        $acct['w3'] ?: null);
    echo payg_row('W4', 'Withheld — no ABN quoted', $w4,
        $acct['w4'] ?: null);
}
?>

<tr style="border-top:1px solid #ccc;">
  <td class="nowrap" style="width:3rem;color:#555;"><strong>W5</strong></td>
  <td><strong>Total PAYG withheld</strong> <small style="font-weight:normal;">(W2<?=$full?' + W3 + W4':''?>)</small></td>
  <td class="right nowrap"><strong><?=bas_fmt($w5)?></strong></td>
</tr>

<?php if (!empty($acct['super'])): ?>
<tr style="color:#777;border-top:1px dashed #ddd;">
  <td></td>
  <td><em>Superannuation expense <small style="color:#888;">(informational — not on BAS)</small></em></td>
  <td class="right nowrap"><em><?=bas_fmt($journal_super)?></em></td>
</tr>
<?php endif; ?>

</tbody>
</table>
<div style="margin-top:0.5rem;">
  <input type="submit" class="butAction" value="Save PAYG">
</div>
</form>
</div>

<?php
// ── Section 3: BAS Summary ────────────────────────────────────────────────────
?>
<div class="div-table-responsive" style="margin-bottom:2rem;">
<?php if (!$gst_ready): ?>
<div style="padding:0.75rem 1rem;background:#f8f8f8;border:1px solid #ddd;border-radius:3px;color:#888;max-width:620px;">
  BAS Summary not available — configure GST accounts in
  <a href="<?=DOL_URL_ROOT?>/custom/bas/admin/setup.php">Setup</a> first.
</div>
<?php else: ?>
<table class="noborder" style="max-width:620px;">
<thead><tr class="liste_titre"><th colspan="3">BAS Summary &mdash; enter these on the ATO portal</th></tr></thead>
<tbody>
  <?=bas_tr('1A', 'GST on sales',             $gst_1a,  false, '#f9f9f9')?>
  <?=bas_tr('1B', 'GST credits on purchases', $gst_1b,  false, '#f9f9f9')?>
  <?=bas_tr('',   'Net GST (1A − 1B)',        $net_gst, true)?>
  <?php if ($w5 > 0 || $w1 > 0): ?>
  <tr><td colspan="3" style="padding:0.2rem;border:none;"></td></tr>
  <?=bas_tr('W1', 'Total wages paid',          $w1,      false, '#f9f9f9')?>
  <?=bas_tr('W2', 'PAYG withheld from wages',  $w2,      false, '#f9f9f9')?>
  <?php if ($full && ($w3 > 0 || $w4 > 0)): ?>
  <?=bas_tr('W3', 'Other amounts withheld',    $w3,      false, '#f9f9f9')?>
  <?=bas_tr('W4', 'Withheld — no ABN',         $w4,      false, '#f9f9f9')?>
  <?php endif; ?>
  <?=bas_tr('W5', 'Total PAYG withheld',       $w5,      true)?>
  <?=bas_tr('4',  'PAYG withholding (= W5)',   $field_4, false, '#f9f9f9')?>
  <?php endif; ?>
  <tr style="border-top:2px solid #aaa;background:#fffde7;">
    <td><strong>9</strong></td>
    <td><strong>Total amount to pay ATO</strong> <small style="font-weight:normal;">(net GST + field 4)</small></td>
    <td class="right nowrap"><strong><?=bas_fmt($field_9)?></strong></td>
  </tr>
</tbody>
</table>
<?php if ($field_9 < 0): ?>
<p style="color:#2a7;font-size:0.85em;margin-top:0.25rem;">Field 9 is negative — the ATO owes you a refund of <?=bas_fmt(abs($field_9))?>.</p>
<?php endif; ?>
<?php endif; ?>
</div>

<div class="noprint" style="margin-top:0.5rem;">
  <?php
  $print_url = DOL_URL_ROOT.'/custom/bas/report.php?action=print'
      .'&fy='.$fy.'&q='.$q.'&from='.urlencode($qstart).'&to='.urlencode($qend)
      .'&basis='.$basis.'&mainmenu=accountancy&leftmenu=bas_report';
  ?>
  <a href="<?=htmlspecialchars($print_url)?>" target="_blank" class="butAction">
    Print / Save as PDF
  </a>
  <span style="margin-left:1rem;font-size:0.85em;color:#888;">
    Opens a clean print-ready page &mdash; <?=htmlspecialchars(ucfirst($bas_type))?> BAS &mdash;
    <a href="<?=DOL_URL_ROOT?>/custom/bas/admin/setup.php">Setup</a>
  </span>
</div>

<?php
print dol_get_fiche_end();
llxFooter();
