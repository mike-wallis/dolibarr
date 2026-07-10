<?php
/**
 * Pay Run History — list of completed pay runs, with per-run detail view.
 * URL: /custom/payroll/payruns.php
 */

require '../../main.inc.php';

if (!$user->admin) {
    accessforbidden();
}

$fy_filter  = GETPOST('fy',    'alpha');
$view_start = GETPOST('start', 'alpha');
$view_end   = GETPOST('end',   'alpha');
$view_pay   = GETPOST('pay',   'alpha');

// ── Detail rows for one run ────────────────────────────────────────────────

$detail_rows = [];
$detail_ids  = [];
if ($view_start && $view_end && $view_pay) {
    $sql_d = "SELECT prl.*, u.firstname, u.lastname, u.login, u.email"
        . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
        . " JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = prl.fk_user"
        . " WHERE prl.entity = "          . (int)$conf->entity
        . " AND prl.pay_period_start = '" . $db->escape($view_start) . "'"
        . " AND prl.pay_period_end = '"   . $db->escape($view_end)   . "'"
        . " AND prl.pay_date = '"         . $db->escape($view_pay)   . "'"
        . " ORDER BY u.lastname, u.firstname";
    $res_d = $db->query($sql_d);
    while ($obj = $db->fetch_object($res_d)) {
        $detail_rows[] = $obj;
        $detail_ids[]  = (int)$obj->rowid;
    }
}

// ── Run list (grouped) ─────────────────────────────────────────────────────

$sql_runs = "SELECT"
    . " pay_period_start, pay_period_end, pay_date, fy,"
    . " COUNT(*) AS emp_count,"
    . " SUM(gross)        AS total_gross,"
    . " SUM(payg)         AS total_payg,"
    . " SUM(super_amount) AS total_super,"
    . " SUM(net)          AS total_net,"
    . " MIN(rowid)        AS run_ref_id"
    . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
    . " WHERE entity = " . (int)$conf->entity;
if ($fy_filter) {
    $sql_runs .= " AND fy = '" . $db->escape($fy_filter) . "'";
}
$sql_runs .= " GROUP BY pay_period_start, pay_period_end, pay_date, fy"
    . " ORDER BY pay_period_end DESC, pay_date DESC";

$res_runs = $db->query($sql_runs);
$runs = [];
while ($obj = $db->fetch_object($res_runs)) {
    $runs[] = $obj;
}

// Available FY values for the filter
$res_fys = $db->query("SELECT DISTINCT fy FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
    . " WHERE entity = " . (int)$conf->entity . " ORDER BY fy DESC");
$fys = [];
while ($obj = $db->fetch_object($res_fys)) {
    $fys[] = $obj->fy;
}

llxHeader('', 'Pay Run History');
?>
<div class="fiche">
<div style="display:flex;align-items:baseline;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
  <h1 style="margin:0;">Pay Run History</h1>
  <a href="payrun.php?mainmenu=billing&leftmenu=payroll_run" class="button" style="font-size:0.88em;">New pay run</a>
  <a href="stp_export.php?mainmenu=billing&leftmenu=payroll_stp" class="button" style="font-size:0.88em;">STP Export</a>
</div>

<?php if ($detail_rows): ?>
<?php
  $run_ref   = 'PR' . str_pad((string)min($detail_ids), 6, '0', STR_PAD_LEFT);
  $ids_js    = '[' . implode(',', $detail_ids) . ']';
  $ids_csv   = implode(',', $detail_ids);
  $d_gross   = array_sum(array_column($detail_rows, 'gross'));
  $d_payg    = array_sum(array_column($detail_rows, 'payg'));
  $d_super   = array_sum(array_column($detail_rows, 'super_amount'));
  $d_net     = array_sum(array_column($detail_rows, 'net'));
?>

<!-- Detail view header -->
<div style="background:#f0f9f0;border:1px solid #7dba7d;border-radius:6px;padding:1rem 1.25rem;margin:0 0 1.25rem;max-width:980px;">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
    <div>
      <div style="font-size:1.05em;font-weight:700;color:#2a6e2a;margin-bottom:0.3rem;"><?= dol_htmlentities($run_ref) ?></div>
      <div style="font-size:0.9em;color:#333;">
        Period: <strong><?= dol_print_date(strtotime($view_start), '%d %b %Y') ?> &ndash; <?= dol_print_date(strtotime($view_end), '%d %b %Y') ?></strong>
        &nbsp;&middot;&nbsp; Pay date: <strong><?= dol_print_date(strtotime($view_pay), '%d %b %Y') ?></strong>
      </div>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
      <button onclick="openAllPayslips(<?= $ids_js ?>)" class="button" style="font-size:0.88em;">Print all payslips</button>
      <form method="post" action="payslip.php" style="display:inline;margin:0;">
        <input type="hidden" name="action" value="email_batch">
        <input type="hidden" name="ids"    value="<?= dol_htmlentities($ids_csv) ?>">
        <input type="hidden" name="token"  value="<?= newToken() ?>">
        <button type="submit" class="button" style="font-size:0.88em;">Email all payslips</button>
      </form>
      <a href="payruns.php?mainmenu=billing&leftmenu=payroll_history<?= $fy_filter ? '&fy='.urlencode($fy_filter) : '' ?>"
         class="button" style="font-size:0.88em;">← All runs</a>
    </div>
  </div>
</div>
<script>
function openAllPayslips(ids) {
  ids.forEach(function(id) { window.open('payslip.php?id=' + id + '&mainmenu=billing', '_blank'); });
}
</script>

<!-- Detail table -->
<table class="noborder" style="width:100%;max-width:1000px;margin-bottom:2rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Gross</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">PAYG</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Super</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Net pay</th>
      <th style="padding:0.5rem 0.75rem;text-align:center;">Payslip</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($detail_rows as $dr):
        $drname = trim($dr->firstname . ' ' . $dr->lastname) ?: $dr->login; ?>
    <tr>
      <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($drname) ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:right;">$<?= number_format((float)$dr->gross, 2) ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:right;"><?= (float)$dr->payg > 0 ? '$'.number_format((float)$dr->payg, 2) : '—' ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:right;">$<?= number_format((float)$dr->super_amount, 2) ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:right;font-weight:600;">$<?= number_format((float)$dr->net, 2) ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:center;white-space:nowrap;">
        <a href="payslip.php?id=<?= (int)$dr->rowid ?>&mainmenu=billing" target="_blank"
           class="button" style="font-size:0.85em;padding:0.2rem 0.6rem;">View</a>
        <?php if (!empty($dr->email)): ?>
        <form method="post" action="payslip.php" style="display:inline;margin:0 0 0 0.3rem;">
          <input type="hidden" name="action" value="email_payslip">
          <input type="hidden" name="id"     value="<?= (int)$dr->rowid ?>">
          <input type="hidden" name="token"  value="<?= newToken() ?>">
          <button type="submit" class="button" style="font-size:0.85em;padding:0.2rem 0.6rem;background:#17a2b8;border-color:#148fa5;color:#fff;">Email</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f9f9f9;font-weight:600;">
      <td style="padding:0.5rem 1rem;">Totals</td>
      <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($d_gross, 2) ?></td>
      <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($d_payg,  2) ?></td>
      <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($d_super, 2) ?></td>
      <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($d_net,   2) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>

<?php
// Super payments due — only employees with super_amount > 0
$super_rows = array_filter($detail_rows, fn($r) => (float)$r->super_amount > 0);
if ($super_rows):
    $super_total = array_sum(array_map(fn($r) => (float)$r->super_amount, $super_rows));
    $sgcDue = function($d) {
        $m = (int)date('n', strtotime($d));
        $y = (int)date('Y', strtotime($d));
        if ($m >= 7  && $m <= 9)  return mktime(0,0,0,10,28,$y);
        if ($m >= 10)             return mktime(0,0,0,1,28,$y+1);
        if ($m <= 3)              return mktime(0,0,0,4,28,$y);
        return mktime(0,0,0,7,28,$y); // Apr–Jun → 28 Jul
    };
?>
<h3 style="margin:2rem 0 0.35rem;">Super payments due</h3>
<p style="font-size:0.87em;color:#666;margin:0 0 0.6rem;">
  Due date = 28 days after end of the quarter the pay date falls in (ATO SGC rule).
  Submit via SBSCH before this date. <strong>⚠ fund details not set</strong> = go to Payroll Employees → Edit payroll profile.
</p>
<div class="noPrint" style="margin-bottom:0.75rem;">
  <button onclick="window.print()" class="button" style="font-size:0.88em;">Print SBSCH list</button>
</div>
<table class="noborder" style="width:100%;max-width:940px;margin-bottom:2rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Super fund</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">USI</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Member no.</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Amount</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">SGC due</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($super_rows as $i => $sr):
        $srname = trim($sr->firstname . ' ' . $sr->lastname) ?: $sr->login;
        $due_ts = $sgcDue($sr->pay_date);
        $alt    = $i % 2 ? 'background:#fafafa;' : '';
    ?>
    <tr style="<?= $alt ?>">
      <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($srname) ?></td>
      <td style="padding:0.4rem 0.75rem;"><?= $sr->super_fund
            ? dol_htmlentities($sr->super_fund)
            : '<span style="color:#c00;font-size:0.85em;">⚠ not set</span>' ?></td>
      <td style="padding:0.4rem 0.75rem;font-family:monospace;font-size:0.9em;"><?= $sr->super_fund_usi ? dol_htmlentities($sr->super_fund_usi) : '—' ?></td>
      <td style="padding:0.4rem 0.75rem;"><?= $sr->super_member_number ? dol_htmlentities($sr->super_member_number) : '—' ?></td>
      <td style="padding:0.4rem 0.75rem;text-align:right;font-weight:600;">$<?= number_format((float)$sr->super_amount, 2) ?></td>
      <td style="padding:0.4rem 0.75rem;"><?= dol_print_date($due_ts, '%d %b %Y') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f9f9f9;font-weight:600;">
      <td style="padding:0.5rem 1rem;" colspan="4">Total super due this run</td>
      <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($super_total, 2) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
<?php endif; ?>

<?php else: ?>

<!-- Run list -->
<?php if ($fys): ?>
<div style="margin-bottom:1rem;">
  <label style="font-size:0.9em;"><strong>Filter by FY:</strong></label>
  &nbsp;
  <a href="payruns.php?mainmenu=billing&leftmenu=payroll_history" style="margin-right:0.5rem;<?= !$fy_filter ? 'font-weight:700;' : '' ?>">All</a>
  <?php foreach ($fys as $fy): ?>
  <a href="payruns.php?fy=<?= urlencode($fy) ?>&mainmenu=billing&leftmenu=payroll_history"
     style="margin-right:0.5rem;<?= $fy_filter === $fy ? 'font-weight:700;' : '' ?>"><?= dol_htmlentities($fy) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($runs)): ?>
<p style="color:#888;">No completed pay runs found<?= $fy_filter ? ' for FY ' . dol_htmlentities($fy_filter) : '' ?>.</p>
<?php else: ?>
<table class="noborder" style="width:100%;max-width:1000px;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;text-align:left;">Ref</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Pay period</th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Pay date</th>
      <th style="padding:0.5rem 0.5rem;text-align:center;">FY</th>
      <th style="padding:0.5rem 0.5rem;text-align:center;">Emp</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Gross</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">PAYG</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Super</th>
      <th style="padding:0.5rem 0.75rem;text-align:right;">Net pay</th>
      <th style="padding:0.5rem 0.75rem;"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($runs as $i => $run):
        $ref = 'PR' . str_pad((string)$run->run_ref_id, 6, '0', STR_PAD_LEFT);
        $alt = $i % 2 ? 'background:#fafafa;' : '';
        $detail_url = 'payruns.php?start=' . urlencode($run->pay_period_start)
            . '&end='   . urlencode($run->pay_period_end)
            . '&pay='   . urlencode($run->pay_date)
            . ($fy_filter ? '&fy='.urlencode($fy_filter) : '')
            . '&mainmenu=billing&leftmenu=payroll_history';
    ?>
    <tr style="<?= $alt ?>">
      <td style="padding:0.45rem 1rem;font-family:monospace;color:#555;"><?= dol_htmlentities($ref) ?></td>
      <td style="padding:0.45rem 0.75rem;">
        <?= dol_print_date(strtotime($run->pay_period_start), '%d %b %Y') ?>
        &ndash;
        <?= dol_print_date(strtotime($run->pay_period_end), '%d %b %Y') ?>
      </td>
      <td style="padding:0.45rem 0.75rem;"><?= dol_print_date(strtotime($run->pay_date), '%d %b %Y') ?></td>
      <td style="padding:0.45rem 0.5rem;text-align:center;font-size:0.88em;color:#555;"><?= dol_htmlentities($run->fy) ?></td>
      <td style="padding:0.45rem 0.5rem;text-align:center;"><?= (int)$run->emp_count ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format((float)$run->total_gross, 2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;"><?= (float)$run->total_payg > 0 ? '$'.number_format((float)$run->total_payg, 2) : '—' ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format((float)$run->total_super, 2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;font-weight:600;">$<?= number_format((float)$run->total_net, 2) ?></td>
      <td style="padding:0.45rem 0.75rem;">
        <a href="<?= $detail_url ?>" class="button" style="font-size:0.85em;padding:0.2rem 0.7rem;">View</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php endif; ?>

</div>
<?php llxFooter(); ?>
