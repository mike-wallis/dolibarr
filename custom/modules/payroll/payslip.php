<?php
/**
 * Payslip — print-ready pay record for one employee pay run.
 * URL: /custom/payroll/payslip.php?id=ROWID
 *
 * Fair Work mandatory fields covered:
 *   - Employer name and ABN
 *   - Employee name, pay period, pay date
 *   - Hourly rate and ordinary hours worked
 *   - OT hours and loading
 *   - PAYG withholding
 *   - Gross and net pay
 *   - Super fund, amount, and USI (if on file)
 *   - Leave balances (non-casuals)
 *   - YTD totals
 */

require '../../main.inc.php';

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Email helper: build self-contained HTML payslip for email ──────────────
function buildPayslipEmailHtml($row, $ytd)
{
    $empname = trim($row->firstname . ' ' . $row->lastname) ?: $row->login;
    $company = getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
    $abn     = getDolGlobalString('MAIN_INFO_SOCIETE_IDPROF2');
    $period  = dol_print_date(strtotime($row->pay_period_start), '%d %b %Y')
             . ' – ' . dol_print_date(strtotime($row->pay_period_end), '%d %b %Y');
    $paydt   = dol_print_date(strtotime($row->pay_date), '%d %b %Y');

    $h  = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;max-width:620px;margin:0 auto;">';
    $h .= '<div style="background:#2c3e50;color:#fff;padding:1rem 1.5rem;">';
    $h .= '<div style="font-size:1.4em;font-weight:bold;letter-spacing:0.05em;">PAYSLIP</div>';
    $h .= '<div style="color:#ccc;font-size:0.9em;">' . htmlspecialchars($company);
    if ($abn) $h .= ' &nbsp;ABN ' . htmlspecialchars($abn);
    $h .= '</div></div>';
    $h .= '<div style="padding:1rem 1.5rem;border:1px solid #ddd;border-top:none;">';

    // Meta
    $h .= '<table width="100%" style="margin-bottom:1rem;font-size:0.92em;"><tbody>';
    $h .= '<tr><td style="color:#666;width:40%;padding:0.15rem 0;">Employee</td><td><strong>' . htmlspecialchars($empname) . '</strong></td></tr>';
    $h .= '<tr><td style="color:#666;padding:0.15rem 0;">Pay period</td><td>' . $period . '</td></tr>';
    $h .= '<tr><td style="color:#666;padding:0.15rem 0;">Pay date</td><td><strong>' . $paydt . '</strong></td></tr>';
    $h .= '<tr><td style="color:#666;padding:0.15rem 0;">Financial year</td><td>' . htmlspecialchars($row->fy) . '</td></tr>';
    $h .= '</tbody></table>';

    // Earnings
    $h .= '<table width="100%" style="border-top:2px solid #333;padding-top:0.5rem;font-size:0.92em;"><tbody>';
    if ($row->is_salary) {
        $h .= '<tr><td style="padding:0.2rem 0;">Salary</td><td align="right">$' . number_format((float)$row->salary_amount, 2) . '</td></tr>';
    } else {
        if ((float)$row->ord_hrs > 0) {
            $h .= '<tr><td style="padding:0.2rem 0;">Ordinary hours (' . number_format((float)$row->ord_hrs, 2) . ' h × $' . number_format((float)$row->ord_rate, 2) . '/h)</td><td align="right">$' . number_format((float)$row->ord_hrs * (float)$row->ord_rate, 2) . '</td></tr>';
        }
        if ((float)$row->ot1_hrs > 0) {
            $h .= '<tr><td style="padding:0.2rem 0;">Overtime &times;' . number_format((float)$row->ot1_mult, 2) . ' (' . number_format((float)$row->ot1_hrs, 2) . ' h)</td><td align="right">$' . number_format((float)$row->ot1_hrs * (float)$row->ord_rate * (float)$row->ot1_mult, 2) . '</td></tr>';
        }
        if ((float)$row->al_hrs > 0) {
            $h .= '<tr><td style="padding:0.2rem 0;">Annual leave (' . number_format((float)$row->al_hrs, 2) . ' h)</td><td align="right">$' . number_format((float)$row->al_hrs * (float)$row->ord_rate, 2) . '</td></tr>';
        }
        if ((float)$row->sick_hrs > 0) {
            $h .= '<tr><td style="padding:0.2rem 0;">Personal/carer\'s leave (' . number_format((float)$row->sick_hrs, 2) . ' h)</td><td align="right">$' . number_format((float)$row->sick_hrs * (float)$row->ord_rate, 2) . '</td></tr>';
        }
        if ((float)$row->bere_hrs > 0) {
            $h .= '<tr><td style="padding:0.2rem 0;">Compassionate leave (' . number_format((float)$row->bere_hrs, 2) . ' h)</td><td align="right">$' . number_format((float)$row->bere_hrs * (float)$row->ord_rate, 2) . '</td></tr>';
        }
    }
    $h .= '<tr style="font-weight:bold;border-top:1px solid #ccc;"><td style="padding-top:0.4rem;">Gross pay</td><td align="right" style="padding-top:0.4rem;">$' . number_format((float)$row->gross, 2) . '</td></tr>';
    if ((float)$row->payg > 0) {
        $h .= '<tr><td style="padding:0.2rem 0;">PAYG withholding</td><td align="right">($' . number_format((float)$row->payg, 2) . ')</td></tr>';
    }
    $h .= '<tr style="font-weight:bold;font-size:1.05em;"><td style="padding:0.4rem 0;background:#e8f4ff;">Net pay</td><td align="right" style="padding:0.4rem 0;background:#e8f4ff;">$' . number_format((float)$row->net, 2) . '</td></tr>';
    // Super
    if ((float)$row->super_amount > 0) {
        $h .= '<tr style="border-top:1px solid #eee;"><td style="padding:0.3rem 0;color:#666;">Employer super (SGC)</td><td align="right" style="color:#666;">$' . number_format((float)$row->super_amount, 2) . '</td></tr>';
        if ($row->super_fund) {
            $h .= '<tr><td colspan="2" style="color:#888;font-size:0.85em;padding-bottom:0.3rem;">' . htmlspecialchars($row->super_fund);
            if ($row->super_fund_usi)      $h .= ' &middot; USI: ' . htmlspecialchars($row->super_fund_usi);
            if ($row->super_member_number) $h .= ' &middot; Member: ' . htmlspecialchars($row->super_member_number);
            $h .= '</td></tr>';
        }
    }
    $h .= '</tbody></table>';

    // YTD
    if ($ytd) {
        $h .= '<div style="background:#f4f4f4;border:1px solid #ddd;border-radius:4px;padding:0.75rem;margin-top:1rem;font-size:0.88em;">';
        $h .= '<div style="font-weight:bold;margin-bottom:0.4rem;font-size:0.8em;text-transform:uppercase;color:#555;">Year-to-date — ' . htmlspecialchars($row->fy) . '</div>';
        $h .= '<table width="100%"><tbody><tr>';
        $h .= '<td><span style="color:#666;font-size:0.85em;">Gross YTD</span><br><strong>$' . number_format((float)$ytd->ytd_gross, 2) . '</strong></td>';
        $h .= '<td><span style="color:#666;font-size:0.85em;">PAYG YTD</span><br><strong>$' . number_format((float)$ytd->ytd_payg, 2) . '</strong></td>';
        $h .= '<td><span style="color:#666;font-size:0.85em;">Super YTD</span><br><strong>$' . number_format((float)$ytd->ytd_super, 2) . '</strong></td>';
        $h .= '<td><span style="color:#666;font-size:0.85em;">Net YTD</span><br><strong>$' . number_format((float)$ytd->ytd_net, 2) . '</strong></td>';
        $h .= '</tr></tbody></table></div>';
    }

    $h .= '</div>';
    $h .= '<div style="padding:0.5rem 1.5rem;font-size:0.8em;color:#999;">Keep this payslip for your tax records.</div>';
    $h .= '</body></html>';
    return $h;
}

// ── Batch email ────────────────────────────────────────────────────────────
if ($action === 'email_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
    $ids  = array_filter(array_map('intval', explode(',', GETPOST('ids', 'alphanohtml'))));
    $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
    $sent = 0;
    $fails = [];
    foreach ($ids as $pid) {
        $sql_e = "SELECT prl.*, u.firstname, u.lastname, u.login, u.email"
            . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
            . " JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = prl.fk_user"
            . " WHERE prl.rowid = " . (int)$pid
            . " AND prl.entity = " . (int)$conf->entity;
        $res_e = $db->query($sql_e);
        $row_e = ($res_e ? $db->fetch_object($res_e) : null);
        if (!$row_e) continue;
        if (!$row_e->email) {
            $fails[] = trim($row_e->firstname . ' ' . $row_e->lastname) . ' (no email)';
            continue;
        }
        $ytd_r = $db->query("SELECT SUM(gross) AS ytd_gross, SUM(net) AS ytd_net,"
            . " SUM(payg) AS ytd_payg, SUM(super_amount) AS ytd_super"
            . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
            . " WHERE fk_user = " . (int)$row_e->fk_user
            . " AND entity = " . (int)$conf->entity
            . " AND fy = '" . $db->escape($row_e->fy) . "'");
        $ytd_e   = ($ytd_r ? $db->fetch_object($ytd_r) : null);
        $empname = trim($row_e->firstname . ' ' . $row_e->lastname) ?: $row_e->login;
        $subject = 'Payslip — ' . $empname . ' — ' . dol_print_date(strtotime($row_e->pay_date), '%d %b %Y');
        $body    = buildPayslipEmailHtml($row_e, $ytd_e);
        $mail    = new CMailFile($subject, $row_e->email, $from, $body, [], [], '', '', 0, 1);
        if ($mail->sendfile()) {
            $sent++;
        } else {
            $fails[] = $empname . ' (' . $mail->error . ')';
        }
    }
    $msg = $sent . ' payslip' . ($sent !== 1 ? 's' : '') . ' emailed.';
    if ($fails) {
        $msg .= ' Failed: ' . implode(', ', $fails);
    }
    setEventMessages($msg, null, $fails ? 'warnings' : 'mesgs');
    header('Location: payrun.php?mainmenu=billing&leftmenu=payroll_run');
    exit;
}

$id = GETPOSTINT('id');
if (!$id) {
    header('Location: payrun.php?mainmenu=billing&leftmenu=payroll_run');
    exit;
}

// Load payrun_line + user
$sql = "SELECT prl.*, u.firstname, u.lastname, u.login, u.email"
    . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
    . " JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = prl.fk_user"
    . " WHERE prl.rowid = " . (int)$id
    . " AND prl.entity = " . (int)$conf->entity;
$res = $db->query($sql);
if (!$res || !($row = $db->fetch_object($res))) {
    setEventMessages('Payslip record not found.', null, 'errors');
    header('Location: payrun.php?mainmenu=billing&leftmenu=payroll_run');
    exit;
}

// YTD totals for same employee + FY (includes this run)
$ytd_res = $db->query(
    "SELECT SUM(gross) AS ytd_gross, SUM(net) AS ytd_net,"
    . " SUM(payg) AS ytd_payg, SUM(super_amount) AS ytd_super, COUNT(*) AS ytd_runs"
    . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
    . " WHERE fk_user = " . (int)$row->fk_user
    . " AND entity = "    . (int)$conf->entity
    . " AND fy = '"       . $db->escape($row->fy) . "'"
);
$ytd = ($ytd_res ? $db->fetch_object($ytd_res) : null);

// Parse stored JSON
$deds_extra  = $row->deductions_json ? json_decode($row->deductions_json, true) : [];
$adds_detail = $row->additions_json  ? json_decode($row->additions_json,  true) : [];

$empname = trim($row->firstname . ' ' . $row->lastname) ?: $row->login;

// ── Single payslip email ───────────────────────────────────────────────────
if ($action === 'email_payslip' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
    if (!$row->email) {
        setEventMessages('No email address on employee profile.', null, 'errors');
        header('Location: payslip.php?id=' . $id . '&mainmenu=billing');
        exit;
    }
    $from    = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
    $subject = 'Payslip — ' . $empname . ' — ' . dol_print_date(strtotime($row->pay_date), '%d %b %Y');
    $body    = buildPayslipEmailHtml($row, $ytd);
    $mail    = new CMailFile($subject, $row->email, $from, $body, [], [], '', '', 0, 1);
    if ($mail->sendfile()) {
        setEventMessages('Payslip emailed to ' . dol_htmlentities($row->email) . '.', null, 'mesgs');
    } else {
        setEventMessages('Email failed: ' . $mail->error, null, 'errors');
    }
    header('Location: payslip.php?id=' . $id . '&mainmenu=billing');
    exit;
}

// Company info from Dolibarr globals / mysoc
$company_name = getDolGlobalString('MAIN_INFO_SOCIETE_NOM') ?: ($mysoc->name ?? '');
$company_abn  = getDolGlobalString('MAIN_INFO_SOCIETE_IDPROF2') ?: ($mysoc->idprof2 ?? '');
$company_addr = getDolGlobalString('MAIN_INFO_SOCIETE_ADDRESS') ?: '';

llxHeader('', 'Payslip — ' . dol_htmlentities($empname));
?>
<style>
@media print {
    .dolibarrnavbar, .leftColumn, #mainmenu, .tmenu,
    #leftColumn, .tabBarHeaderLeft, .noPrint { display: none !important; }
    #id-right { margin: 0 !important; padding: 0 !important; }
    .payslip-wrap { max-width: 100% !important; box-shadow: none !important; }
}
.payslip-wrap {
    max-width: 800px;
    margin: 1rem auto 2rem;
    padding: 1.5rem 2rem;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 0.91em;
    color: #111;
    background: #fff;
    box-shadow: 0 1px 6px rgba(0,0,0,.12);
    border-radius: 4px;
}
.ps-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #333;
    padding-bottom: 0.8rem;
    margin-bottom: 1rem;
}
.ps-header h2 { margin: 0 0 0.2rem; font-size: 1.5em; letter-spacing: 0.04em; }
.ps-company { text-align: right; }
.ps-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.2rem 2rem;
    margin-bottom: 1rem;
    padding-bottom: 0.8rem;
    border-bottom: 1px solid #ccc;
    font-size: 0.9em;
}
.ps-meta span.lbl { color: #666; }
.ps-section { margin-bottom: 1.2rem; }
.ps-section h4 {
    margin: 0 0 0.4rem;
    font-size: 0.8em;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #555;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 0.2rem;
}
.ps-row { display: flex; justify-content: space-between; padding: 0.18rem 0; }
.ps-row.subtotal { border-top: 1px solid #ddd; padding-top: 0.3rem; margin-top: 0.2rem; }
.ps-row.total {
    font-weight: 700;
    font-size: 1.05em;
    border-top: 2px solid #333;
    padding-top: 0.35rem;
    margin-top: 0.3rem;
}
.ps-row.net-pay { background: #e8f4ff; padding: 0.4rem 0.6rem; margin: 0.3rem -0.6rem; border-radius: 3px; }
.ps-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
.ps-ytd {
    background: #f4f4f4;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 0.75rem 1rem;
    margin-top: 1.2rem;
}
.ps-ytd h4 { margin: 0 0 0.5rem; }
.ytd-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.4rem; }
.ytd-cell .lbl { font-size: 0.8em; color: #666; }
.ytd-cell strong { display: block; font-size: 1.1em; }
.ps-footer {
    margin-top: 1.5rem;
    font-size: 0.75em;
    color: #999;
    border-top: 1px solid #eee;
    padding-top: 0.5rem;
}
.noPrint { margin-bottom: 1rem; }
</style>

<div class="fiche">
<div class="noPrint">
  <button onclick="window.print()" class="button">Print payslip</button>
  &nbsp;
  <a href="payrun.php?mainmenu=billing&leftmenu=payroll_run" class="button">Back</a>
</div>

<div class="payslip-wrap">

  <!-- Header: payslip title + company -->
  <div class="ps-header">
    <div>
      <h2>PAYSLIP</h2>
      <div>Pay date: <strong><?= dol_print_date(strtotime($row->pay_date), '%d %b %Y') ?></strong></div>
    </div>
    <div class="ps-company">
      <strong><?= dol_htmlentities($company_name) ?></strong><br>
      <?php if ($company_abn): ?>ABN <?= dol_htmlentities($company_abn) ?><br><?php endif; ?>
      <?php if ($company_addr): ?><small style="color:#555;"><?= nl2br(dol_htmlentities($company_addr)) ?></small><?php endif; ?>
    </div>
  </div>

  <!-- Employee + period meta -->
  <div class="ps-meta">
    <div><span class="lbl">Employee: </span><strong><?= dol_htmlentities($empname) ?></strong></div>
    <div><span class="lbl">Pay period: </span><?= dol_print_date(strtotime($row->pay_period_start), '%d %b %Y') ?> – <?= dol_print_date(strtotime($row->pay_period_end), '%d %b %Y') ?></div>
    <?php if ($row->email): ?><div><span class="lbl">Email: </span><?= dol_htmlentities($row->email) ?></div><?php endif; ?>
    <div><span class="lbl">Financial year: </span><?= dol_htmlentities($row->fy) ?></div>
  </div>

  <!-- Earnings -->
  <div class="ps-section">
    <h4>Earnings</h4>
    <?php if ($row->is_salary): ?>
      <div class="ps-row">
        <span>Salary</span>
        <span>$<?= number_format((float)$row->salary_amount, 2) ?></span>
      </div>
    <?php else: ?>
      <?php if ((float)$row->ord_hrs > 0): ?>
      <div class="ps-row">
        <span>Ordinary hours (<?= number_format((float)$row->ord_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->ord_hrs * (float)$row->ord_rate, 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$row->ot1_hrs > 0): ?>
      <div class="ps-row">
        <span>Overtime ×<?= number_format((float)$row->ot1_mult, 2) ?> (<?= number_format((float)$row->ot1_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate * (float)$row->ot1_mult, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->ot1_hrs * (float)$row->ord_rate * (float)$row->ot1_mult, 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$row->ot2_hrs > 0): ?>
      <div class="ps-row">
        <span>Overtime ×<?= number_format((float)$row->ot2_mult, 2) ?> (<?= number_format((float)$row->ot2_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate * (float)$row->ot2_mult, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->ot2_hrs * (float)$row->ord_rate * (float)$row->ot2_mult, 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$row->al_hrs > 0): ?>
      <div class="ps-row">
        <span>Annual leave (<?= number_format((float)$row->al_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->al_hrs * (float)$row->ord_rate, 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$row->sick_hrs > 0): ?>
      <div class="ps-row">
        <span>Personal / carer's leave (<?= number_format((float)$row->sick_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->sick_hrs * (float)$row->ord_rate, 2) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$row->bere_hrs > 0): ?>
      <div class="ps-row">
        <span>Compassionate leave (<?= number_format((float)$row->bere_hrs, 2) ?> h × $<?= number_format((float)$row->ord_rate, 2) ?>/h)</span>
        <span>$<?= number_format((float)$row->bere_hrs * (float)$row->ord_rate, 2) ?></span>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php foreach ($adds_detail as $code => $add): ?>
    <div class="ps-row">
      <span><?= dol_htmlentities($add['label']) ?></span>
      <span>$<?= number_format((float)$add['amount'], 2) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="ps-row subtotal total">
      <span>Gross pay</span>
      <span>$<?= number_format((float)$row->gross, 2) ?></span>
    </div>
  </div>

  <!-- Deductions -->
  <div class="ps-section">
    <h4>Deductions</h4>
    <?php if ((float)$row->payg > 0): ?>
    <div class="ps-row">
      <span>PAYG withholding</span>
      <span>$<?= number_format((float)$row->payg, 2) ?></span>
    </div>
    <?php endif; ?>
    <?php foreach ($deds_extra as $code => $ded):
        if (($ded['class'] ?? '') !== 'employee') continue; ?>
    <div class="ps-row">
      <span><?= dol_htmlentities($ded['label']) ?></span>
      <span>$<?= number_format((float)$ded['amount'], 2) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="ps-row total net-pay">
      <span>Net pay</span>
      <span>$<?= number_format((float)$row->net, 2) ?></span>
    </div>
  </div>

  <div class="ps-grid2">

    <!-- Employer super -->
    <div class="ps-section">
      <h4>Employer superannuation</h4>
      <div class="ps-row">
        <span>SGC contribution</span>
        <span>$<?= number_format((float)$row->super_amount, 2) ?></span>
      </div>
      <?php if ($row->super_fund): ?>
      <div style="margin-top:0.4rem;font-size:0.85em;color:#444;line-height:1.6;">
        <?= dol_htmlentities($row->super_fund) ?>
        <?php if ($row->super_fund_usi): ?><br>USI: <?= dol_htmlentities($row->super_fund_usi) ?><?php endif; ?>
        <?php if ($row->super_member_number): ?><br>Member no: <?= dol_htmlentities($row->super_member_number) ?><?php endif; ?>
      </div>
      <?php else: ?>
      <div style="margin-top:0.4rem;font-size:0.82em;color:#999;">Super fund details — see employee profile</div>
      <?php endif; ?>
    </div>

    <!-- Leave balances -->
    <?php if ($row->bal_annual_after !== null): ?>
    <div class="ps-section">
      <h4>Leave balances (end of period)</h4>
      <div class="ps-row">
        <span>Annual leave</span>
        <span><?= number_format((float)$row->bal_annual_after, 2) ?> h</span>
      </div>
      <div class="ps-row">
        <span>Personal / carer's leave</span>
        <span><?= number_format((float)$row->bal_sick_after, 2) ?> h</span>
      </div>
      <?php if ($row->leave_note): ?>
      <div style="margin-top:0.4rem;font-size:0.85em;color:#555;font-style:italic;">
        Note: <?= dol_htmlentities($row->leave_note) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- YTD totals -->
  <?php if ($ytd): ?>
  <div class="ps-ytd">
    <h4>Year-to-date — <?= dol_htmlentities($row->fy) ?></h4>
    <div class="ytd-grid">
      <div class="ytd-cell"><div class="lbl">Gross YTD</div><strong>$<?= number_format((float)$ytd->ytd_gross, 2) ?></strong></div>
      <div class="ytd-cell"><div class="lbl">PAYG YTD</div><strong>$<?= number_format((float)$ytd->ytd_payg, 2) ?></strong></div>
      <div class="ytd-cell"><div class="lbl">Super YTD</div><strong>$<?= number_format((float)$ytd->ytd_super, 2) ?></strong></div>
      <div class="ytd-cell"><div class="lbl">Net YTD</div><strong>$<?= number_format((float)$ytd->ytd_net, 2) ?></strong></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="ps-footer">
    Keep this payslip for your tax records. &nbsp;|&nbsp; <?= dol_htmlentities($company_name) ?>
    <?php if ($row->date_created): ?> &nbsp;|&nbsp; Generated <?= dol_print_date(strtotime($row->date_created), '%d/%m/%Y %H:%M') ?><?php endif; ?>
  </div>

</div>
</div>
<?php llxFooter(); ?>
