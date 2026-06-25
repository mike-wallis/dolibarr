<?php
/**
 * Per-employee payroll configuration.
 * Linked from the employee's user profile or accessed directly.
 * URL: /custom/payroll/employee_payroll.php?userid=N
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/PaygCalculator.php';
require_once __DIR__ . '/lib/TfnHelper.php';

if (!$user->admin) {
    accessforbidden();
}

$userid = GETPOSTINT('userid') ?: GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!$userid) {
    setEventMessages('No employee specified.', null, 'errors');
    header('Location: ' . DOL_URL_ROOT . '/user/list.php');
    exit;
}

// Load the Dolibarr user record
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
$employee = new User($db);
$employee->fetch($userid);

if (!$employee->id || !$employee->employee) {
    setEventMessages('User not found or not an employee.', null, 'errors');
    header('Location: ' . DOL_URL_ROOT . '/user/list.php');
    exit;
}

$empname = trim($employee->firstname . ' ' . $employee->lastname) ?: $employee->login;

// Position type labels (from Reckon Employee Defaults)
$position_types = [
    'FT'   => 'Full Time (FT)',
    'FTT'  => 'Full Time Temporary (FTT)',
    'CA'   => 'Casual (CA)',
    'CAPT' => 'Casual Part Time (CAPT)',
    'PT'   => 'Part Time (PT)',
    'AP'   => 'Apprentice (AP)',
    'O'    => 'Other (O)',
];

$pay_periods = [
    'weekly'      => 'Weekly',
    'fortnightly' => 'Fortnightly',
    'halfmonthly' => 'Half Monthly',
    'monthly'     => 'Monthly',
    'fourweekly'  => 'Four Weekly',
];

$pay_rate_types = [
    'hourly' => 'Hourly rate',
    'salary' => 'Salary (fixed per period)',
];

// Load existing payroll employee record
$sql_pe = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_employee"
    . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity . " LIMIT 1";
$res_pe = $db->query($sql_pe);
$pe     = $res_pe ? $db->fetch_object($res_pe) : null;

// Load all deduction types
$sql_dt = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_deduction_type"
    . " WHERE entity = " . (int)$conf->entity . " AND active = 1 ORDER BY position, code";
$res_dt     = $db->query($sql_dt);
$ded_types  = [];
while ($obj = $db->fetch_object($res_dt)) {
    $ded_types[$obj->rowid] = $obj;
}

// Load per-employee deduction assignments
$sql_ed = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_employee_deduction"
    . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity;
$res_ed    = $db->query($sql_ed);
$emp_deds  = [];
while ($obj = $db->fetch_object($res_ed)) {
    $emp_deds[$obj->fk_deduction] = $obj;
}

// ── Handle TFN save ────────────────────────────────────────────────────────

if ($action === 'save_tfn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tfnKey = tfn_load_key();
    if (GETPOSTINT('clear_tfn')) {
        $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_employee"
            . " SET tfn_encrypted = NULL"
            . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity);
        setEventMessages('TFN cleared.', null, 'mesgs');
    } else {
        $plain = preg_replace('/\D/', '', GETPOST('tfn_plain', 'alphanohtml'));
        if (!preg_match('/^\d{8,9}$/', $plain)) {
            setEventMessages('TFN must be 8 or 9 digits (no spaces or hyphens).', null, 'errors');
        } elseif (!$tfnKey) {
            setEventMessages('TFN_KEY not found in .env — cannot encrypt.', null, 'errors');
        } elseif (!$pe) {
            setEventMessages('Save the payroll profile first before entering a TFN.', null, 'warnings');
        } else {
            $enc = tfn_encrypt($plain, $tfnKey);
            $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_employee"
                . " SET tfn_encrypted = '" . $db->escape($enc) . "'"
                . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity);
            setEventMessages('TFN saved (encrypted).', null, 'mesgs');
        }
    }
    header('Location: employee_payroll.php?userid=' . (int)$userid . '&mainmenu=billing&leftmenu=payroll_employees#tfn-section');
    exit;
}

// ── Handle save ───────────────────────────────────────────────────────────

if ($action === 'save') {
    $position_type = GETPOST('position_type', 'alpha');
    $pay_period    = GETPOST('pay_period',    'alpha');
    $pay_rate      = (float)str_replace(',', '.', GETPOST('pay_rate',  'alpha'));
    $pay_rate_type = GETPOST('pay_rate_type', 'alpha');
    $std_hours         = (float)str_replace(',', '.', GETPOST('std_hours',         'alpha'));
    $ot_rate1          = (float)str_replace(',', '.', GETPOST('ot_rate1',           'alpha'));
    $ot_rate2      = (float)str_replace(',', '.', GETPOST('ot_rate2',  'alpha'));
    $tax_scale           = GETPOST('tax_scale',     'alpha');
    $has_hecs            = GETPOSTINT('has_hecs');
    $has_medicare_adj    = GETPOSTINT('has_medicare_adj');
    $medicare_dependants = GETPOSTINT('medicare_dependants');
    $emp_start_raw       = GETPOST('employment_start_date', 'alpha');
    $emp_start_val       = ($emp_start_raw && strtotime($emp_start_raw))
                             ? "'" . $db->escape($emp_start_raw) . "'" : 'NULL';
    $super_fund_name     = trim(GETPOST('super_fund_name',     'alphanohtml'));
    $super_fund_usi      = trim(GETPOST('super_fund_usi',      'alphanohtml'));
    $super_member_number = trim(GETPOST('super_member_number', 'alphanohtml'));
    $pay_bsb             = trim(GETPOST('pay_bsb',             'alphanohtml'));
    $pay_account         = trim(GETPOST('pay_account',         'alphanohtml'));

    $db->begin();

    if ($pe) {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "payroll_employee SET"
            . " position_type = '"        . $db->escape($position_type)    . "'"
            . ", pay_period = '"          . $db->escape($pay_period)       . "'"
            . ", pay_rate = "             . (float)$pay_rate
            . ", pay_rate_type = '"       . $db->escape($pay_rate_type)    . "'"
            . ", std_hours = "            . (float)$std_hours
            . ", ot_rate1 = "             . (float)$ot_rate1
            . ", ot_rate2 = "             . (float)$ot_rate2
            . ", tax_scale = '"           . $db->escape($tax_scale)        . "'"
            . ", has_hecs = "             . (int)$has_hecs
            . ", has_medicare_adj = "     . (int)$has_medicare_adj
            . ", medicare_dependants = "  . (int)$medicare_dependants
            . ", employment_start_date = " . $emp_start_val
            . ", super_fund_name = '"     . $db->escape($super_fund_name)     . "'"
            . ", super_fund_usi = '"      . $db->escape($super_fund_usi)      . "'"
            . ", super_member_number = '" . $db->escape($super_member_number) . "'"
            . ", pay_bsb = '"             . $db->escape($pay_bsb)             . "'"
            . ", pay_account = '"         . $db->escape($pay_account)         . "'"
            . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity;
    } else {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "payroll_employee"
            . " (fk_user, position_type, pay_period, pay_rate, pay_rate_type, std_hours, ot_rate1, ot_rate2,"
            . "  tax_scale, has_hecs, has_medicare_adj, medicare_dependants, employment_start_date,"
            . "  super_fund_name, super_fund_usi, super_member_number, pay_bsb, pay_account, entity)"
            . " VALUES (" . (int)$userid
            . ", '" . $db->escape($position_type)     . "'"
            . ", '" . $db->escape($pay_period)         . "'"
            . ", "  . (float)$pay_rate
            . ", '" . $db->escape($pay_rate_type)      . "'"
            . ", "  . (float)$std_hours
            . ", "  . (float)$ot_rate1
            . ", "  . (float)$ot_rate2
            . ", '" . $db->escape($tax_scale)          . "'"
            . ", "  . (int)$has_hecs
            . ", "  . (int)$has_medicare_adj
            . ", "  . (int)$medicare_dependants
            . ", "  . $emp_start_val
            . ", '" . $db->escape($super_fund_name)     . "'"
            . ", '" . $db->escape($super_fund_usi)      . "'"
            . ", '" . $db->escape($super_member_number) . "'"
            . ", '" . $db->escape($pay_bsb)             . "'"
            . ", '" . $db->escape($pay_account)         . "'"
            . ", "  . (int)$conf->entity . ")";
    }
    $db->query($sql);

    // Save optional deduction assignments
    foreach ($ded_types as $dtid => $dt) {
        if ($dt->is_mandatory) {
            continue; // mandatory ones always active, no row needed
        }
        $active          = GETPOSTINT('ded_active_'  . $dtid) ? 1 : 0;
        $rate_override   = GETPOST('ded_rate_'   . $dtid, 'alpha');
        $amount_override = GETPOST('ded_amount_' . $dtid, 'alpha');

        $rate_val   = ($rate_override   !== '')  ? (float)str_replace(',', '.', $rate_override)   : 'NULL';
        $amount_val = ($amount_override !== '')  ? (float)str_replace(',', '.', $amount_override) : 'NULL';

        if (isset($emp_deds[$dtid])) {
            $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_employee_deduction SET"
                . " active = $active"
                . ", rate_override = " . ($rate_val === 'NULL' ? 'NULL' : $rate_val)
                . ", amount_override = " . ($amount_val === 'NULL' ? 'NULL' : $amount_val)
                . " WHERE rowid = " . (int)$emp_deds[$dtid]->rowid);
        } elseif ($active) {
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_employee_deduction"
                . " (fk_user, fk_deduction, rate_override, amount_override, active, entity)"
                . " VALUES (" . (int)$userid . ", " . (int)$dtid
                . ", " . ($rate_val === 'NULL' ? 'NULL' : $rate_val)
                . ", " . ($amount_val === 'NULL' ? 'NULL' : $amount_val)
                . ", 1, " . (int)$conf->entity . ")");
        }
    }

    // Opening balance save (FT/PT only — casuals have no accruing leave)
    $casual_types = ['CA', 'CAPT'];
    if (!in_array($position_type, $casual_types)) {
        $adj_date = GETPOST('adj_date', 'alpha');
        $adj_note = trim(GETPOST('adj_note', 'alpha'));
        $adj_date_safe = ($adj_date && strtotime($adj_date)) ? $db->escape($adj_date) : date('Y-m-d');
        $adj_note_safe = $adj_note !== '' ? $db->escape($adj_note) : 'Manual balance adjustment';
        foreach (['annual', 'sick'] as $lt) {
            $ob_val = GETPOST('opening_' . $lt, 'alpha');
            if ($ob_val !== '' && $ob_val !== null) {
                $ob_hours = round((float)str_replace(',', '.', $ob_val), 2);
                if ($ob_hours != 0) {
                    // Add adjustment to running balance (never drop below zero)
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_balance"
                        . " (fk_user, entity, leave_type, balance_hours, date_updated)"
                        . " VALUES (" . (int)$userid . ", " . (int)$conf->entity
                        . ", '" . $db->escape($lt) . "', GREATEST(0, " . $ob_hours . "), NOW())"
                        . " ON DUPLICATE KEY UPDATE"
                        . " balance_hours = GREATEST(0, balance_hours + " . $ob_hours . ")"
                        . ", date_updated = NOW()");
                    // Record dated adjustment transaction
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                        . " (fk_user, entity, leave_type, transaction_type, hours, date_transaction, note)"
                        . " VALUES (" . (int)$userid . ", " . (int)$conf->entity
                        . ", '" . $db->escape($lt) . "', 'adjustment', " . $ob_hours
                        . ", '" . $adj_date_safe . "', '" . $adj_note_safe . "')");
                }
            }
        }
    }

    $db->commit();

    // Reload after save
    $res_pe = $db->query($sql_pe);
    $pe     = $db->fetch_object($res_pe);
    $res_ed = $db->query($sql_ed);
    $emp_deds = [];
    while ($obj = $db->fetch_object($res_ed)) {
        $emp_deds[$obj->fk_deduction] = $obj;
    }

    setEventMessages('Payroll profile saved.', null, 'mesgs');
    $action = '';
}

// ── Load leave balances ────────────────────────────────────────────────────

$leave_balances = ['annual' => 0.00, 'sick' => 0.00];
$res_lb = $db->query("SELECT leave_type, balance_hours FROM " . MAIN_DB_PREFIX . "payroll_leave_balance"
    . " WHERE fk_user = " . (int)$userid . " AND entity = " . (int)$conf->entity);
while ($obj = $db->fetch_object($res_lb)) {
    $leave_balances[$obj->leave_type] = (float)$obj->balance_hours;
}

$is_casual = in_array($pe->position_type ?? 'CA', ['CA', 'CAPT']);

// ── Load TFN ───────────────────────────────────────────────────────────────

$tfnKey    = tfn_load_key();
$tfn_enc   = $pe->tfn_encrypted ?? '';
$tfn_set   = !empty($tfn_enc);
$tfn_plain = ($tfn_set && $tfnKey) ? (tfn_decrypt($tfn_enc, $tfnKey) ?: '') : '';

// ── Output ─────────────────────────────────────────────────────────────────

llxHeader('', 'Payroll Profile — ' . $empname);

$back = DOL_URL_ROOT . '/user/card.php?id=' . $userid;
?>
<div class="fiche">

<div style="margin-bottom:1rem;">
  <a href="<?= $back ?>">← Back to <?= dol_htmlentities($empname) ?>'s profile</a>
  &nbsp;|&nbsp;
  <a href="employees.php?mainmenu=billing&leftmenu=payroll_employees">← All employees</a>
</div>

<?php if (GETPOST('msg', 'alpha') === 'newemployee'): ?>
<div class="alert alert-success" style="margin:0 0 1rem;">
  <strong>Employee created.</strong>
  A Dolibarr user account and linked Contact have been set up for
  <strong><?= dol_htmlentities($empname) ?></strong>.
  Fill in the payroll details below, then save.
  <br><small style="color:#555;">To set a password so they can log in, use
  <em>Tools → Users &amp; Groups → Edit user → Change password</em>.</small>
</div>
<?php endif; ?>

<h1>Payroll Profile — <?= dol_htmlentities($empname) ?></h1>

<form method="post" action="employee_payroll.php?userid=<?= (int)$userid ?>">
<input type="hidden" name="token"  value="<?= newToken() ?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="userid" value="<?= (int)$userid ?>">

<h3>Employment &amp; Pay Period</h3>
<table class="noborder" style="max-width:750px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:230px;"><label><strong>Position type</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="position_type" class="flat" id="position_type" onchange="toggleHours()">
        <?php foreach ($position_types as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= ($pe->position_type ?? 'CA') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Employment start date</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="date" name="employment_start_date" class="flat" style="width:160px;"
             value="<?= dol_htmlentities($pe->employment_start_date ?? '') ?>">
      <?php
      $esd = $pe->employment_start_date ?? null;
      if ($esd && strtotime($esd)) {
          $d1   = new DateTime($esd);
          $d2   = new DateTime();
          $diff = $d1->diff($d2);
          $yrs  = $diff->y;
          $mos  = $diff->m;
          $svc  = $yrs . ' yr' . ($yrs != 1 ? 's' : '') . ($mos ? ', ' . $mos . ' month' . ($mos != 1 ? 's' : '') : '');
          if ($yrs >= 10) {
              $lsl = '<strong style="color:#155724;">Full LSL entitlement reached</strong> — employee may take 8.67 weeks leave (QLD)';
          } elseif ($yrs >= 7) {
              $lsl = '<span style="color:#856404;">Pro-rata LSL payable on eligible termination</span> (7–10 yr QLD threshold reached)';
          } else {
              $remaining = 7 - $yrs - round($mos / 12, 1);
              $lsl = '<span style="color:#888;">~' . number_format(max(0, 7 - $yrs - $mos/12), 1) . ' yrs until pro-rata LSL eligibility (7-yr QLD threshold)</span>';
          }
          echo '<small style="margin-left:0.5rem;">' . $svc . ' continuous service — ' . $lsl . '</small>';
      }
      ?>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Pay period</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="pay_period" class="flat">
        <?php foreach ($pay_periods as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= ($pe->pay_period ?? 'weekly') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Rate type</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="pay_rate_type" class="flat" id="pay_rate_type" onchange="toggleHours()">
        <option value="hourly" <?= ($pe->pay_rate_type ?? 'hourly') === 'hourly' ? 'selected' : '' ?>>Hourly rate</option>
        <option value="salary" <?= ($pe->pay_rate_type ?? '') === 'salary' ? 'selected' : '' ?>>Salary (fixed per period)</option>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong id="rate_label">Rate ($/hr)</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="pay_rate" value="<?= number_format((float)($pe->pay_rate ?? 0), 4, '.', '') ?>"
             min="0" step="0.0001" style="width:120px;" class="flat">
    </td>
  </tr>
  <tr id="row_std_hours">
    <td style="padding:0.5rem 1rem;"><label><strong>Standard hours / period</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="std_hours" value="<?= number_format((float)($pe->std_hours ?? 0), 2, '.', '') ?>"
             min="0" step="0.5" style="width:80px;" class="flat">
      <small style="color:#666;margin-left:0.5rem;">Pre-fills pay run form and drives leave accrual. Weekly FT = 38, fortnightly FT = 76, monthly FT ≈ 165.</small>
    </td>
  </tr>
  <tr id="row_ot_rates">
    <td style="padding:0.5rem 1rem;"><label><strong>Overtime multipliers</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <label>OT×1: <input type="number" name="ot_rate1" value="<?= number_format((float)($pe->ot_rate1 ?? 1.5), 2, '.', '') ?>"
             min="1" step="0.05" style="width:70px;" class="flat"></label>
      &nbsp;&nbsp;
      <label>OT×2: <input type="number" name="ot_rate2" value="<?= number_format((float)($pe->ot_rate2 ?? 2.0), 2, '.', '') ?>"
             min="1" step="0.05" style="width:70px;" class="flat"></label>
      <small style="color:#666;margin-left:0.5rem;">Multiples of base rate (e.g. 1.5 = time-and-a-half)</small>
    </td>
  </tr>
</table>

<h3 style="margin-top:1.5rem;">Tax Settings</h3>
<table class="noborder" style="max-width:750px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:230px;"><label><strong>Tax scale</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="tax_scale" class="flat">
        <?php foreach (PaygCalculator::scaleLabels() as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= ($pe->tax_scale ?? 'scale2') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>HECS/HELP debt</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <label>
        <input type="checkbox" name="has_hecs" value="1" <?= ($pe->has_hecs ?? 0) ? 'checked' : '' ?>>
        Employee has a HECS/HELP debt (adds extra withholding — Schedule 8)
      </label>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Medicare levy adjustment</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <label>
        <input type="checkbox" name="has_medicare_adj" id="has_medicare_adj" value="1"
               <?= ($pe->has_medicare_adj ?? 0) ? 'checked' : '' ?>
               onchange="toggleMedicareAdj()">
        Employee has lodged a Medicare levy variation declaration (NAT 0929)
      </label>
      <br><small style="color:#666;">Reduces PAYG withholding for Scale 2 (earnings ≥$538/wk) or Scale 6 (≥$908/wk) employees with a low family income. Confirm with your accountant or BAS agent.</small>
    </td>
  </tr>
  <tr id="row_medicare_deps" style="display:<?= ($pe->has_medicare_adj ?? 0) ? '' : 'none' ?>;">
    <td style="padding:0.5rem 1rem;"><label><strong>Dependent children</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="medicare_dependants"
             value="<?= (int)($pe->medicare_dependants ?? 0) ?>"
             min="0" max="20" step="1" style="width:70px;" class="flat">
      <small style="color:#666;margin-left:0.5rem;">0 = spouse/partner only (Q9 yes, Q12 no) &nbsp;|&nbsp; enter N if dependent children were listed at Q12</small>
    </td>
  </tr>
</table>

<h3 id="tfn-section" style="margin-top:1.5rem;">Tax File Number</h3>
<?php if (!$tfnKey): ?>
<div class="alert alert-warning" style="max-width:650px;margin-top:0;">
  <strong>TFN_KEY not set in .env</strong> — TFN encryption is unavailable. Add a <code>TFN_KEY</code>
  line to <code>.env</code> (base64-encoded 32-byte key) to enable TFN storage. See
  <a href="<?= DOL_URL_ROOT ?>/custom/payroll/tfn.php?mainmenu=billing">TFN Manager</a> for setup notes.
</div>
<?php else: ?>
<p style="margin-top:0;color:#555;">
  Encrypted with AES-256 — only the encrypted blob is stored in the database.
  The key lives in <code>.env</code> only.
  <a href="<?= DOL_URL_ROOT ?>/custom/payroll/tfn.php?mainmenu=billing" style="margin-left:0.5rem;">TFN Manager (all employees)</a>
</p>
<table class="noborder" style="max-width:750px;margin-bottom:0.5rem;">
  <tr>
    <td style="padding:0.5rem 1rem;width:220px;"><strong>Current TFN</strong></td>
    <td style="padding:0.5rem 1rem;">
      <?php if ($tfn_set): ?>
        <span style="color:#155724;font-weight:600;">✓ Set</span>
        <?php if ($tfn_plain && strlen($tfn_plain) >= 3): ?>
          <span style="color:#555;margin-left:0.5rem;font-family:monospace;">···<?= htmlspecialchars(substr($tfn_plain, -3)) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span style="color:#856404;">⚠ Not set</span>
        <span style="color:#888;margin-left:0.25rem;">— employee has not provided a TFN declaration</span>
      <?php endif; ?>
    </td>
  </tr>
</table>
<?php if (!$pe): ?>
<p style="color:#888;margin-left:1rem;">Save the payroll profile first before entering a TFN.</p>
<?php else: ?>
<form method="post" action="employee_payroll.php?userid=<?= (int)$userid ?>&mainmenu=billing&leftmenu=payroll_employees">
  <input type="hidden" name="token"  value="<?= newToken() ?>">
  <input type="hidden" name="action" value="save_tfn">
  <input type="hidden" name="userid" value="<?= (int)$userid ?>">
  <table class="noborder" style="max-width:750px;">
    <tr>
      <td style="padding:0.4rem 1rem;width:220px;">
        <label><strong><?= $tfn_set ? 'Replace TFN' : 'Enter TFN' ?></strong></label>
      </td>
      <td style="padding:0.4rem 1rem;">
        <input type="text" name="tfn_plain" class="flat" style="width:130px;letter-spacing:0.08em;"
               maxlength="9" placeholder="digits only" autocomplete="off" inputmode="numeric">
        <button type="submit" class="button buttonaction" style="margin-left:0.5rem;">Save TFN</button>
        <small style="display:block;margin-top:0.25rem;color:#666;">8 or 9 digits — no spaces or hyphens</small>
      </td>
    </tr>
    <?php if ($tfn_set): ?>
    <tr>
      <td></td>
      <td style="padding:0 1rem 0.5rem;">
        <label style="color:#c00;">
          <input type="checkbox" name="clear_tfn" value="1">
          Clear TFN — remove from this employee's record
        </label>
      </td>
    </tr>
    <?php endif; ?>
  </table>
</form>
<?php endif; ?>
<?php endif; ?>

<h3 style="margin-top:1.5rem;">Superannuation</h3>
<p style="margin-top:0;color:#555;">Snapshotted onto each pay run record and shown on payslips and the super payments list.
   Find the USI on the fund's website or via the ATO's <a href="https://superfundlookup.gov.au" target="_blank">Super Fund Lookup</a>.</p>

<table class="noborder" style="max-width:750px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:220px;"><label><strong>Super fund name</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="super_fund_name" class="flat" style="width:280px;"
             value="<?= dol_htmlentities($pe->super_fund_name ?? '') ?>"
             placeholder="e.g. AustralianSuper">
    </td>
  </tr>
  <tr style="background:#fafafa;">
    <td style="padding:0.5rem 1rem;"><label><strong>USI</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="super_fund_usi" class="flat" style="width:180px;"
             value="<?= dol_htmlentities($pe->super_fund_usi ?? '') ?>"
             placeholder="e.g. STA0100AU" maxlength="50">
      <small style="color:#777;margin-left:0.5rem;">Unique Superannuation Identifier — required for SBSCH submissions</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Member number</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="super_member_number" class="flat" style="width:180px;"
             value="<?= dol_htmlentities($pe->super_member_number ?? '') ?>"
             placeholder="Employee's fund member number" maxlength="50">
    </td>
  </tr>
</table>

<h3 style="margin-top:1.5rem;">Bank Account</h3>
<p style="margin-top:0;color:#555;">Employee's bank account for salary payments. Used for future ABA/BECS bank transfer file generation.</p>
<table class="noborder" style="max-width:750px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:220px;"><label><strong>BSB</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="pay_bsb" class="flat" style="width:110px;letter-spacing:0.05em;"
             value="<?= dol_htmlentities($pe->pay_bsb ?? '') ?>"
             placeholder="000-000" maxlength="10">
    </td>
  </tr>
  <tr style="background:#fafafa;">
    <td style="padding:0.5rem 1rem;"><label><strong>Account number</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="pay_account" class="flat" style="width:160px;"
             value="<?= dol_htmlentities($pe->pay_account ?? '') ?>"
             placeholder="e.g. 123456789" maxlength="20">
    </td>
  </tr>
</table>

<h3 style="margin-top:1.5rem;">Optional Deductions &amp; Contributions</h3>
<p style="margin-top:0;color:#555;">Mandatory items (PAYG, Super) always appear on the pay run — no config needed here.
   Tick optional items to enable them for this employee.</p>

<table class="noborder" style="max-width:750px;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;">Active</th>
      <th style="padding:0.4rem 1rem;">Code</th>
      <th style="padding:0.4rem 1rem;">Label</th>
      <th style="padding:0.4rem 1rem;">Default calc</th>
      <th style="padding:0.4rem 1rem;">Rate override (%)</th>
      <th style="padding:0.4rem 1rem;">Amount override ($)</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($ded_types as $dtid => $dt):
        if ($dt->is_mandatory) continue;
        $ed = $emp_deds[$dtid] ?? null;
    ?>
    <tr>
      <td style="padding:0.4rem 1rem;text-align:center;">
        <input type="checkbox" name="ded_active_<?= $dtid ?>" value="1"
               <?= ($ed && $ed->active) ? 'checked' : '' ?>>
      </td>
      <td style="padding:0.4rem 1rem;font-weight:600;"><?= dol_htmlentities($dt->code) ?></td>
      <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($dt->label) ?></td>
      <td style="padding:0.4rem 1rem;font-size:0.85em;">
        <?php
        if ($dt->calc_type === 'percent_gross') {
            echo number_format($dt->calc_value, 2) . '% of gross';
        } elseif ($dt->calc_type === 'fixed') {
            echo '$' . number_format($dt->calc_value, 2) . '/period';
        } elseif ($dt->calc_type === 'hecs_auto') {
            echo 'Auto (ATO Schedule 8)';
        } else {
            echo 'Manual';
        }
        ?>
      </td>
      <td style="padding:0.4rem 1rem;">
        <?php if ($dt->calc_type === 'percent_gross' || $dt->calc_type === 'percent_net'): ?>
        <input type="number" name="ded_rate_<?= $dtid ?>"
               value="<?= $ed && $ed->rate_override !== null ? number_format($ed->rate_override, 4, '.', '') : '' ?>"
               placeholder="<?= number_format($dt->calc_value, 2) ?>"
               min="0" step="0.01" style="width:90px;" class="flat">
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="padding:0.4rem 1rem;">
        <?php if ($dt->calc_type === 'fixed' || $dt->calc_type === 'manual'): ?>
        <input type="number" name="ded_amount_<?= $dtid ?>"
               value="<?= $ed && $ed->amount_override !== null ? number_format($ed->amount_override, 2, '.', '') : '' ?>"
               placeholder="0.00"
               min="0" step="0.01" style="width:90px;" class="flat">
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!array_filter(array_map(fn($dt) => !$dt->is_mandatory, $ded_types))): ?>
    <tr><td colspan="6" style="padding:0.5rem 1rem;color:#888;">No optional deductions configured. Add them in <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup">Payroll Setup</a>.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<h3 style="margin-top:1.5rem;">Leave Balances</h3>
<?php if ($is_casual): ?>
<p style="margin-top:0;color:#888;">Casual employees do not accrue paid leave — leave balances are not applicable for this position type.</p>
<?php else: ?>
<?php
    $std_h  = (float)($pe->std_hours ?? 0);
    $period_labels = ['weekly'=>'weekly','fortnightly'=>'fortnightly','halfmonthly'=>'half-monthly','monthly'=>'monthly','fourweekly'=>'four-weekly'];
    $period_lbl = $period_labels[$pe->pay_period ?? 'weekly'] ?? ($pe->pay_period ?? 'weekly');
    $acc_al   = $std_h > 0 ? round($std_h / 13, 2) : null;
    $acc_sick = $std_h > 0 ? round($std_h / 26, 2) : null;
?>
<p style="margin-top:0;color:#555;">
  Current balances are shown below. To adjust (e.g. carrying hours from a previous system),
  click <strong>Add balance adjustment</strong> and enter the hours, date, and a note.
</p>
<table class="noborder" style="max-width:550px;margin-bottom:0.25rem;">
  <tr>
    <td style="padding:0.3rem 1rem;width:230px;"><strong>Annual leave</strong></td>
    <td style="padding:0.3rem 1rem;"><?= number_format($leave_balances['annual'], 2) ?> h</td>
  </tr>
  <tr>
    <td style="padding:0.3rem 1rem;"><strong>Sick / carer's leave</strong></td>
    <td style="padding:0.3rem 1rem;"><?= number_format($leave_balances['sick'], 2) ?> h</td>
  </tr>
</table>
<?php if ($acc_al !== null): ?>
<p style="margin:0.4rem 0 0.75rem 1rem;color:#666;font-size:0.88em;">
  Each <?= $period_lbl ?> pay run accrues approximately
  <strong><?= number_format($acc_al, 2) ?> h</strong> annual leave
  and <strong><?= number_format($acc_sick, 2) ?> h</strong> sick / carer's leave
  (based on <?= number_format($std_h, 2) ?> h / period — ordinary hours ÷ 13 and ÷ 26 respectively,
  giving 4 weeks annual leave and 10 days sick leave per full year).
</p>
<?php else: ?>
<p style="margin:0.4rem 0 0.75rem 1rem;color:#888;font-size:0.88em;">
  Set "Standard hours / period" above to see accrual estimates.
</p>
<?php endif; ?>
<p style="margin-top:0.5rem;">
  <button type="button" class="button" onclick="document.getElementById('adj_fields').style.display='';this.style.display='none';">
    Add balance adjustment
  </button>
</p>
<div id="adj_fields" style="display:none;border:1px solid #ddd;border-radius:4px;padding:0.75rem 1rem;max-width:650px;background:#fafafa;">
  <p style="margin:0 0 0.5rem;color:#555;font-size:0.9em;">
    Enter the adjustment in hours (positive to add, negative to deduct) and a note explaining the reason.
  </p>
  <table class="noborder" style="max-width:600px;">
    <tr>
      <td style="padding:0.4rem 1rem;width:230px;"><label><strong>Date</strong></label></td>
      <td style="padding:0.4rem 1rem;">
        <input type="date" name="adj_date" value="<?= date('Y-m-d') ?>" class="flat" style="width:150px;">
      </td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><label><strong>Annual leave</strong></label></td>
      <td style="padding:0.4rem 1rem;">
        <input type="number" name="opening_annual" value="" placeholder="0"
               step="0.5" style="width:90px;" class="flat">
        <small style="color:#666;margin-left:0.5rem;">hours (+ to add, − to deduct)</small>
      </td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><label><strong>Sick / carer's leave</strong></label></td>
      <td style="padding:0.4rem 1rem;">
        <input type="number" name="opening_sick" value="" placeholder="0"
               step="0.5" style="width:90px;" class="flat">
        <small style="color:#666;margin-left:0.5rem;">hours (+ to add, − to deduct)</small>
      </td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><label><strong>Note</strong></label></td>
      <td style="padding:0.4rem 1rem;">
        <input type="text" name="adj_note" value="" placeholder="e.g. Carried forward from previous system — 30 Jun 2026"
               style="width:350px;" class="flat" required>
      </td>
    </tr>
  </table>
</div>
<?php endif; ?>

<div style="margin:1.5rem 1rem;">
  <button type="submit" class="button buttonaction">Save payroll profile</button>
  &nbsp;
  <a href="<?= $back ?>" class="button">Cancel</a>
</div>
</form>

<script>
function toggleMedicareAdj() {
    var row = document.getElementById('row_medicare_deps');
    var chk = document.getElementById('has_medicare_adj');
    if (row) row.style.display = chk.checked ? '' : 'none';
}
function toggleHours() {
    var rt  = document.getElementById('pay_rate_type').value;
    var pt  = document.getElementById('position_type').value;
    var lbl = document.getElementById('rate_label');
    var rowHrs    = document.getElementById('row_std_hours');
    var rowOT     = document.getElementById('row_ot_rates');

    var isSalaried = (rt === 'salary');
    var isHourly   = !isSalaried;
    var isCasual   = (pt === 'CA' || pt === 'CAPT');

    lbl.textContent = isSalaried ? 'Salary (per period)' : 'Rate ($/hr)';
    rowOT.style.display  = isHourly   ? ''     : 'none';
}
document.addEventListener('DOMContentLoaded', toggleHours);
</script>

</div>
<?php llxFooter(); ?>
