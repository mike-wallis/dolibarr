<?php
/**
 * Per-employee payroll configuration.
 * Linked from the employee's user profile or accessed directly.
 * URL: /custom/payroll/employee_payroll.php?userid=N
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/PaygCalculator.php';

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

// ── Handle save ───────────────────────────────────────────────────────────

if ($action === 'save') {
    $position_type = GETPOST('position_type', 'alpha');
    $pay_period    = GETPOST('pay_period',    'alpha');
    $pay_rate      = (float)str_replace(',', '.', GETPOST('pay_rate',  'alpha'));
    $pay_rate_type = GETPOST('pay_rate_type', 'alpha');
    $std_hours         = (float)str_replace(',', '.', GETPOST('std_hours',         'alpha'));
    $std_weekly_hours  = (float)str_replace(',', '.', GETPOST('std_weekly_hours',  'alpha'));
    $ot_rate1          = (float)str_replace(',', '.', GETPOST('ot_rate1',           'alpha'));
    $ot_rate2      = (float)str_replace(',', '.', GETPOST('ot_rate2',  'alpha'));
    $tax_scale           = GETPOST('tax_scale',     'alpha');
    $has_hecs            = GETPOSTINT('has_hecs');
    $has_medicare_adj    = GETPOSTINT('has_medicare_adj');
    $medicare_dependants = GETPOSTINT('medicare_dependants');

    $db->begin();

    if ($pe) {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "payroll_employee SET"
            . " position_type = '"    . $db->escape($position_type) . "'"
            . ", pay_period = '"      . $db->escape($pay_period)    . "'"
            . ", pay_rate = "         . (float)$pay_rate
            . ", pay_rate_type = '"   . $db->escape($pay_rate_type) . "'"
            . ", std_hours = "           . (float)$std_hours
            . ", std_weekly_hours = "   . (float)$std_weekly_hours
            . ", ot_rate1 = "           . (float)$ot_rate1
            . ", ot_rate2 = "           . (float)$ot_rate2
            . ", tax_scale = '"         . $db->escape($tax_scale)     . "'"
            . ", has_hecs = "           . (int)$has_hecs
            . ", has_medicare_adj = "   . (int)$has_medicare_adj
            . ", medicare_dependants = " . (int)$medicare_dependants
            . " WHERE fk_user = "       . (int)$userid . " AND entity = " . (int)$conf->entity;
    } else {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "payroll_employee"
            . " (fk_user, position_type, pay_period, pay_rate, pay_rate_type, std_hours, std_weekly_hours, ot_rate1, ot_rate2, tax_scale, has_hecs, has_medicare_adj, medicare_dependants, entity)"
            . " VALUES (" . (int)$userid
            . ", '" . $db->escape($position_type)  . "'"
            . ", '" . $db->escape($pay_period)      . "'"
            . ", "  . (float)$pay_rate
            . ", '" . $db->escape($pay_rate_type)   . "'"
            . ", "  . (float)$std_hours
            . ", "  . (float)$std_weekly_hours
            . ", "  . (float)$ot_rate1
            . ", "  . (float)$ot_rate2
            . ", '" . $db->escape($tax_scale)       . "'"
            . ", "  . (int)$has_hecs
            . ", "  . (int)$has_medicare_adj
            . ", "  . (int)$medicare_dependants
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
        foreach (['annual', 'sick'] as $lt) {
            $ob_val = GETPOST('opening_' . $lt, 'alpha');
            if ($ob_val !== '' && $ob_val !== null) {
                $ob_hours = round((float)str_replace(',', '.', $ob_val), 2);
                if ($ob_hours >= 0) {
                    // Upsert balance
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_balance"
                        . " (fk_user, entity, leave_type, balance_hours, date_updated)"
                        . " VALUES (" . (int)$userid . ", " . (int)$conf->entity
                        . ", '" . $db->escape($lt) . "', " . $ob_hours . ", NOW())"
                        . " ON DUPLICATE KEY UPDATE balance_hours=" . $ob_hours . ", date_updated=NOW()");
                    // Record opening transaction (replace any previous opening row)
                    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                        . " WHERE fk_user=" . (int)$userid . " AND entity=" . (int)$conf->entity
                        . " AND leave_type='" . $db->escape($lt) . "'"
                        . " AND transaction_type='opening'");
                    if ($ob_hours > 0) {
                        $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                            . " (fk_user, entity, leave_type, transaction_type, hours, date_transaction, note)"
                            . " VALUES (" . (int)$userid . ", " . (int)$conf->entity
                            . ", '" . $db->escape($lt) . "', 'opening', " . $ob_hours
                            . ", CURDATE(), 'Opening balance set via employee profile')");
                    }
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

// ── Output ─────────────────────────────────────────────────────────────────

llxHeader('', 'Payroll Profile — ' . $empname);

$back = DOL_URL_ROOT . '/user/card.php?id=' . $userid;
?>
<div class="fiche">

<div style="margin-bottom:1rem;">
  <a href="<?= $back ?>">← Back to <?= dol_htmlentities($empname) ?>'s profile</a>
</div>

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
      <small style="color:#666;margin-left:0.5rem;">Pre-fills on pay run form</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Standard hours / week</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="std_weekly_hours" id="std_weekly_hours"
             value="<?= number_format((float)($pe->std_weekly_hours ?? 0), 2, '.', '') ?>"
             min="0" max="60" step="0.5" style="width:80px;" class="flat">
      <small style="color:#666;margin-left:0.5rem;">Used for leave accrual. Typically 38 (full-time) or 40. Enter actual contracted hours/week for part-time.</small>
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

<?php if (!$is_casual): ?>
<h3 style="margin-top:1.5rem;">Leave Balances</h3>
<p style="margin-top:0;color:#555;">
  Current balances are shown below. To set an opening balance (e.g. carrying hours from a previous system), enter the hours and save.
  Leave fields blank to keep the existing balance unchanged. Enter 0 to explicitly zero a balance.
</p>
<table class="noborder" style="max-width:750px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:230px;"><label><strong>Annual leave balance</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="opening_annual"
             value="" placeholder="<?= number_format($leave_balances['annual'], 2) ?> h current"
             min="0" step="0.5" style="width:100px;" class="flat">
      <small style="color:#666;margin-left:0.5rem;">
        Current: <strong><?= number_format($leave_balances['annual'], 2) ?> h</strong>
        — leave blank to keep, enter new value to replace
      </small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Sick / carer's leave balance</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="opening_sick"
             value="" placeholder="<?= number_format($leave_balances['sick'], 2) ?> h current"
             min="0" step="0.5" style="width:100px;" class="flat">
      <small style="color:#666;margin-left:0.5rem;">
        Current: <strong><?= number_format($leave_balances['sick'], 2) ?> h</strong>
        — leave blank to keep, enter new value to replace
      </small>
    </td>
  </tr>
</table>
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
    rowHrs.style.display = isSalaried ? 'none' : '';
    rowOT.style.display  = isHourly   ? ''     : 'none';
}
document.addEventListener('DOMContentLoaded', toggleHours);
</script>

</div>
<?php llxFooter(); ?>
