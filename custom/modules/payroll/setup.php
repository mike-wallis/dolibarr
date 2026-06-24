<?php
/**
 * Payroll module setup — manage deduction/contribution types.
 * Admin only. Linked from the Billing left menu as "Payroll Setup".
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/PaygCalculator.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(['compta', 'banks']);

$action = GETPOST('action', 'aZ09');
$rowid  = GETPOSTINT('rowid');


// ── Load all active GL accounts for dropdowns ─────────────────────────────

$sql_accounts = "SELECT account_number, label FROM " . MAIN_DB_PREFIX . "accounting_account"
    . " WHERE active = 1 AND entity = " . (int)$conf->entity
    . " ORDER BY account_number";
$res_acc  = $db->query($sql_accounts);
$gl_accounts = ['' => '— none —'];
while ($obj = $db->fetch_object($res_acc)) {
    $gl_accounts[$obj->account_number] = $obj->account_number . ' — ' . $obj->label;
}

// ── Handle form actions ────────────────────────────────────────────────────

$error = '';

if ($action === 'save' || $action === 'create') {
    $code            = trim(GETPOST('code',            'alpha'));
    $label           = trim(GETPOST('label',           'alphanohtml'));
    $deduction_class = GETPOST('deduction_class',      'alpha');
    $calc_type       = GETPOST('calc_type',            'alpha');
    $calc_value      = (float)str_replace(',', '.', GETPOST('calc_value', 'alpha'));
    $account_debit   = GETPOST('account_debit',        'alpha');
    $account_credit  = GETPOST('account_credit',       'alpha');
    $is_mandatory        = GETPOSTINT('is_mandatory');
    $is_super_applicable = GETPOSTINT('is_super_applicable');
    $position            = GETPOSTINT('position') ?: 10;

    if (!$code || !$label) {
        $error = 'Code and Label are required.';
    } else {
        if ($action === 'create') {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "payroll_deduction_type"
                . " (code, label, deduction_class, calc_type, calc_value, account_debit, account_credit, is_mandatory, is_super_applicable, position, entity)"
                . " VALUES ('" . $db->escape($code) . "', '" . $db->escape($label) . "'"
                . ", '" . $db->escape($deduction_class) . "', '" . $db->escape($calc_type) . "'"
                . ", " . (float)$calc_value
                . ", " . ($account_debit  ? "'" . $db->escape($account_debit)  . "'" : "NULL")
                . ", " . ($account_credit ? "'" . $db->escape($account_credit) . "'" : "NULL")
                . ", " . (int)$is_mandatory . ", " . (int)$is_super_applicable . ", " . (int)$position . ", " . (int)$conf->entity . ")";
        } else {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "payroll_deduction_type SET"
                . " code = '" . $db->escape($code) . "'"
                . ", label = '" . $db->escape($label) . "'"
                . ", deduction_class = '" . $db->escape($deduction_class) . "'"
                . ", calc_type = '" . $db->escape($calc_type) . "'"
                . ", calc_value = " . (float)$calc_value
                . ", account_debit = " . ($account_debit  ? "'" . $db->escape($account_debit)  . "'" : "NULL")
                . ", account_credit = " . ($account_credit ? "'" . $db->escape($account_credit) . "'" : "NULL")
                . ", is_mandatory = " . (int)$is_mandatory
                . ", is_super_applicable = " . (int)$is_super_applicable
                . ", position = " . (int)$position
                . " WHERE rowid = " . (int)$rowid . " AND entity = " . (int)$conf->entity;
        }
        $db->query($sql);
        header('Location: setup.php?mainmenu=billing&leftmenu=payroll_setup&saved=1');
        exit;
    }
}

if ($action === 'toggle') {
    $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_deduction_type"
        . " SET active = 1 - active"
        . " WHERE rowid = " . (int)$rowid . " AND entity = " . (int)$conf->entity);
    header('Location: setup.php?mainmenu=billing&leftmenu=payroll_setup');
    exit;
}

if ($action === 'delete') {
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_deduction_type"
        . " WHERE rowid = " . (int)$rowid . " AND entity = " . (int)$conf->entity);
    header('Location: setup.php?mainmenu=billing&leftmenu=payroll_setup');
    exit;
}

// ── Load existing deduction types ──────────────────────────────────────────

$sql  = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_deduction_type"
    . " WHERE entity = " . (int)$conf->entity . " ORDER BY position, code";
$res  = $db->query($sql);
$types = [];
while ($obj = $db->fetch_object($res)) {
    $types[] = $obj;
}

// Editing a specific row?
$edit = null;
if ($action === 'edit' && $rowid) {
    foreach ($types as $t) {
        if ($t->rowid == $rowid) {
            $edit = $t;
            break;
        }
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function glSelect($name, $selected, $accounts, $required = false)
{
    $r = $required ? ' required' : '';
    echo '<select name="' . $name . '" class="flat"' . $r . '>';
    foreach ($accounts as $num => $lbl) {
        $sel = ($num === $selected) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($num) . '"' . $sel . '>' . htmlspecialchars($lbl) . '</option>';
    }
    echo '</select>';
}

function calcTypeLabel($ct)
{
    return [
        'manual'       => 'Manual (enter each pay run)',
        'percent_gross'=> '% of gross',
        'percent_net'  => '% of net pay',
        'fixed'        => 'Fixed $ per period',
        'hecs_auto'    => 'HECS/HELP auto-calculate',
    ][$ct] ?? $ct;
}

/**
 * Load withholding test cases for a given FY from the DB.
 * Tries llx_payroll_test_withholding first (new format, no has_hecs/has_mla columns).
 * Falls back to legacy llx_payroll_test_case if the new table is empty for that FY.
 * Returns an empty array if neither table has data, so the caller falls back to hardcoded defaults.
 * Each element: [label, gross, period, scale, has_hecs, has_mla, mla_deps, expected_payg, source]
 */
function payroll_load_test_cases($db, $conf, $fy)
{
    // Try new table first
    $res = $db->query("SELECT label, gross, period, scale, expected_payg, source"
        . " FROM " . MAIN_DB_PREFIX . "payroll_test_withholding"
        . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
        . " ORDER BY position, rowid");
    if ($res && $db->num_rows($res) > 0) {
        $tests = [];
        while ($obj = $db->fetch_object($res)) {
            $tests[] = [$obj->label, (float)$obj->gross, $obj->period, $obj->scale,
                        false, false, 0, (int)$obj->expected_payg, $obj->source];
        }
        return $tests;
    }

    // Fall back to legacy table
    $res = $db->query("SELECT label, gross, period, scale, has_hecs, has_mla,"
        . " mla_deps, expected_payg, source"
        . " FROM " . MAIN_DB_PREFIX . "payroll_test_case"
        . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
        . " ORDER BY position, rowid");
    if (!$res) {
        return [];
    }
    $tests = [];
    while ($obj = $db->fetch_object($res)) {
        $tests[] = [
            $obj->label, (float)$obj->gross, $obj->period, $obj->scale,
            (bool)$obj->has_hecs, (bool)$obj->has_mla, (int)$obj->mla_deps,
            (int)$obj->expected_payg, $obj->source,
        ];
    }
    return $tests;
}

// ── Output ─────────────────────────────────────────────────────────────────

llxHeader('', 'Payroll Setup');
?>
<div class="fiche">
<h1>Payroll Setup — Deduction &amp; Contribution Types</h1>
<p>Configure the deductions and employer contributions that appear on the pay run form.
   <strong>Mandatory</strong> items appear for every employee.
   <strong>Optional</strong> items can be enabled per-employee on their payroll profile.</p>

<?php if (GETPOST('saved')): ?>
  <div class="alert alert-success" style="margin:0.5rem 0 1rem;">Saved.</div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger" style="margin:0.5rem 0 1rem;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<table class="noborder" style="width:100%;max-width:1000px;margin-bottom:2rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;">Code</th>
      <th style="padding:0.5rem 1rem;">Label</th>
      <th style="padding:0.5rem 1rem;">Class</th>
      <th style="padding:0.5rem 1rem;">Calculation</th>
      <th style="padding:0.5rem 1rem;">Rate/Amt</th>
      <th style="padding:0.5rem 1rem;">Dr account</th>
      <th style="padding:0.5rem 1rem;">Cr account</th>
      <th style="padding:0.5rem 1rem;">Mandatory</th>
      <th style="padding:0.5rem 1rem;">Super?</th>
      <th style="padding:0.5rem 1rem;">Active</th>
      <th style="padding:0.5rem 1rem;"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($types as $t): ?>
    <tr style="<?= $t->active ? '' : 'opacity:0.5;' ?>">
      <td style="padding:0.4rem 1rem;font-weight:600;"><?= htmlspecialchars($t->code) ?></td>
      <td style="padding:0.4rem 1rem;"><?= htmlspecialchars($t->label) ?></td>
      <td style="padding:0.4rem 1rem;">
        <?php
        echo match($t->deduction_class) {
            'employer'  => '<span style="color:#27ae60;">Employer</span>',
            'addition'  => '<span style="color:#1a7cb8;">Addition</span>',
            default     => 'Employee',
        };
        ?>
      </td>
      <td style="padding:0.4rem 1rem;"><?= calcTypeLabel($t->calc_type) ?></td>
      <td style="padding:0.4rem 1rem;text-align:right;">
        <?php
        if ($t->calc_type === 'percent_gross' || $t->calc_type === 'percent_net') {
            echo number_format($t->calc_value, 2) . '%';
        } elseif ($t->calc_type === 'fixed') {
            echo '$' . number_format($t->calc_value, 2);
        } else {
            echo '—';
        }
        ?>
      </td>
      <td style="padding:0.4rem 1rem;font-size:0.85em;"><?= htmlspecialchars($t->account_debit ?? '—') ?></td>
      <td style="padding:0.4rem 1rem;font-size:0.85em;"><?= htmlspecialchars($t->account_credit ?? '—') ?></td>
      <td style="padding:0.4rem 1rem;text-align:center;"><?= $t->is_mandatory ? '✓' : '' ?></td>
      <td style="padding:0.4rem 1rem;text-align:center;"><?= $t->is_super_applicable ? '✓' : '' ?></td>
      <td style="padding:0.4rem 1rem;text-align:center;">
        <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup&action=toggle&rowid=<?= $t->rowid ?>&token=<?= newToken() ?>">
          <?= $t->active ? 'Disable' : '<strong>Enable</strong>' ?>
        </a>
      </td>
      <td style="padding:0.4rem 1rem;">
        <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup&action=edit&rowid=<?= $t->rowid ?>">Edit</a>
        &nbsp;
        <?php if (!$t->is_mandatory): ?>
        <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup&action=delete&rowid=<?= $t->rowid ?>&token=<?= newToken() ?>"
           onclick="return confirm('Delete <?= htmlspecialchars($t->code) ?>?')">Del</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2><?= $edit ? 'Edit: ' . htmlspecialchars($edit->code) : 'Add deduction / contribution type' ?></h2>

<form method="post" action="setup.php?mainmenu=billing&leftmenu=payroll_setup">
<input type="hidden" name="token"  value="<?= newToken() ?>">
<input type="hidden" name="action" value="<?= $edit ? 'save' : 'create' ?>">
<?php if ($edit): ?>
<input type="hidden" name="rowid"  value="<?= (int)$edit->rowid ?>">
<?php endif; ?>

<table class="noborder" style="max-width:700px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:200px;"><label><strong>Code</strong> <small>(e.g. PAYG, SUPER)</small></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="code" value="<?= htmlspecialchars($edit->code ?? '') ?>"
             maxlength="20" class="flat" required <?= ($edit && $edit->is_mandatory) ? 'readonly' : '' ?>>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Label</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="label" value="<?= htmlspecialchars($edit->label ?? '') ?>"
             maxlength="100" style="width:300px;" class="flat" required>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Class</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="deduction_class" class="flat">
        <option value="employee" <?= ($edit->deduction_class ?? 'employee') === 'employee' ? 'selected' : '' ?>>Employee deduction (reduces net pay)</option>
        <option value="employer" <?= ($edit->deduction_class ?? '') === 'employer' ? 'selected' : '' ?>>Employer contribution (paid on top of gross)</option>
        <option value="addition" <?= ($edit->deduction_class ?? '') === 'addition' ? 'selected' : '' ?>>Addition to pay (adds to gross before tax)</option>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Calculation</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <select name="calc_type" id="calc_type" class="flat" onchange="toggleValue()">
        <?php foreach (['manual'=>'Manual (enter each pay run)', 'percent_gross'=>'% of gross', 'percent_net'=>'% of net pay', 'fixed'=>'Fixed $ per period', 'hecs_auto'=>'HECS/HELP auto-calculate'] as $val => $lbl): ?>
        <option value="<?= $val ?>" <?= ($edit->calc_type ?? 'manual') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr id="row_calc_value">
    <td style="padding:0.5rem 1rem;"><label id="calc_value_label"><strong>Rate / Amount</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="calc_value" id="calc_value"
             value="<?= number_format((float)($edit->calc_value ?? 0), 4, '.', '') ?>"
             min="0" step="0.0001" style="width:120px;" class="flat">
      <span id="calc_value_suffix"></span>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Debit account</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <?php glSelect('account_debit', $edit->account_debit ?? '', $gl_accounts) ?>
      <small style="color:#666;margin-left:0.5rem;">Expense or wages account</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Credit account</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <?php glSelect('account_credit', $edit->account_credit ?? '', $gl_accounts) ?>
      <small style="color:#666;margin-left:0.5rem;">Liability or bank account</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Attracts super?</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <label>
        <input type="checkbox" name="is_super_applicable" value="1" <?= ($edit->is_super_applicable ?? 0) ? 'checked' : '' ?>>
        Counts as Ordinary Time Earnings — SGC super applies
        <small style="color:#888;display:block;margin-top:2px;">Tick for commission, bonuses. Leave unticked for most allowances. Verify with your accountant.</small>
      </label>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Mandatory</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <label>
        <input type="checkbox" name="is_mandatory" value="1" <?= ($edit->is_mandatory ?? 0) ? 'checked' : '' ?>>
        Show on every employee (cannot be disabled per-employee)
      </label>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><label><strong>Sort order</strong></label></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="position" value="<?= (int)($edit->position ?? 10) ?>"
             min="1" style="width:70px;" class="flat">
    </td>
  </tr>
</table>

<div style="margin:1rem 1rem 2rem;">
  <button type="submit" class="button buttonaction"><?= $edit ? 'Save changes' : 'Add type' ?></button>
  <?php if ($edit): ?>
  &nbsp;<a href="setup.php?mainmenu=billing&leftmenu=payroll_setup" class="button">Cancel</a>
  <?php endif; ?>
</div>
</form>

<div style="max-width:700px;margin-top:2rem;padding:1rem;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;font-size:0.9em;">
  <strong>Annual reminder (each July):</strong> Download the updated
  <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank">NAT 1004 – Schedule 1</a>
  from the ATO and update the coefficient tables in
  <code>custom/modules/payroll/lib/tax-tables/YYYY-YY.php</code>.
  Also check
  <a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank">NAT 3539 – Schedule 8</a>
  for any HECS threshold changes.
</div>

<?php
// ── PAYG Verification Test ────────────────────────────────────────────────
// Run PaygCalculator against known ATO values and show pass/fail.
// Sources:
//   "ATO sample data"    = values from "Withholding amounts sample data" PDF (NAT 1004, 17 Jun 2026)
//   "ATO example"        = worked examples from ato.gov.au/tax-rates-and-codes/.../medicare-levy-adjustment
//   "from coefficients"  = expected value computed from the ATO coefficient table — tests
//                          routing and formula logic, not coefficient accuracy

$_fy         = '2025-26';
$_tests_db   = payroll_load_test_cases($db, $conf, $_fy);
$_tests_from_db = !empty($_tests_db);
$_tests = $_tests_from_db ? $_tests_db : [
    // [label, gross, period, scale, has_hecs, has_mla, mla_deps, expected_payg, source]
    ['Scale 1 — $370/wk',                370.00,  'weekly',       'scale1', false, false, 0,   66, 'ATO sample data'],
    ['Scale 1 — $907/wk',                907.00,  'weekly',       'scale1', false, false, 0,  219, 'ATO sample data'],
    ['Scale 2 — $362/wk (nil TFT)',      362.00,  'weekly',       'scale2', false, false, 0,    0, 'ATO sample data'],
    ['Scale 2 — $370/wk',                370.00,  'weekly',       'scale2', false, false, 0,    1, 'ATO sample data'],
    ['Scale 2 — $865/wk',                865.00,  'weekly',       'scale2', false, false, 0,   94, 'ATO sample data'],
    ['Scale 2 — $1,282/wk',            1282.00,  'weekly',       'scale2', false, false, 0,  229, 'ATO sample data'],
    ['Scale 3 — $500/wk',               500.00,  'weekly',       'scale3', false, false, 0,  150, 'from coefficients'],
    ['Scale 4 — $500/wk (no TFN)',      500.00,  'weekly',       'scale4', false, false, 0,  235, 'from coefficients'],
    ['Scale 5 — $1,000/wk (full Medi)', 1000.00, 'weekly',       'scale5', false, false, 0,  118, 'from coefficients'],
    ['Scale 6 — $1,000/wk (half Medi)', 1000.00, 'weekly',       'scale6', false, false, 0,  122, 'from coefficients'],
    // Medicare levy adjustment — ato.gov.au medicare-levy-adjustment page (17 Jun 2026)
    // Zail: Scale 2, $633.06/wk, 0 deps. ATO: WLA = $10. Normal PAYG = $50 → adjusted = $40.
    ['MLA — Zail $633.06/wk Scale 2, 0 deps',      633.06,  'weekly',       'scale2', false, true, 0,  40, 'ATO example (WLA=$10)'],
    // Scott: $3,066.33/mth, Scale 2, 0 deps. ATO: WLA = $14/wk → $61/mth adj. Normal PAYG = $286 → adj = $225.
    ['MLA — Scott $3,066.33/mth Scale 2, 0 deps',  3066.33, 'monthly',      'scale2', false, true, 0, 225, 'ATO example (adj=$61/mth)'],
    // Kareem: $2,026.77/fn, Scale 2, 1 child. ATO: WLA = $18/wk → $36/fn. Normal PAYG = $284 → adj = $248.
    ['MLA — Kareem $2,026.77/fn Scale 2, 1 child', 2026.77, 'fortnightly',  'scale2', false, true, 1, 248, 'ATO example (adj=$36/fn)'],
];

$_pass = 0; $_fail = 0;
foreach ($_tests as &$_t) {
    $r = PaygCalculator::calculate($_t[1], $_t[2], $_t[3], $_t[4], $_fy, $_t[5], $_t[6]);
    $_t['actual'] = $r['payg'];
    $_t['ok']     = ($r['payg'] == $_t[7]);
    if ($_t['ok']) $_pass++; else $_fail++;
}
unset($_t);
?>

<hr style="margin:2.5rem 0;">
<h2>PAYG Calculation Verification</h2>
<p style="max-width:750px;">Tests the PAYG calculator against published ATO data for 2025-26.
   Run this after any coefficient update to confirm results are correct before doing a live pay run.
   All deduction fields on the pay run form are editable — use the ATO's own
   <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank">tax withheld calculator</a>
   to double-check any figure you are unsure of.</p>

<div style="margin-bottom:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
  <?php if ($_fail === 0): ?>
    <span style="background:#d4edda;color:#155724;padding:0.4rem 1rem;border-radius:4px;font-weight:600;">
      ✓ All <?= $_pass ?> tests passed
    </span>
  <?php else: ?>
    <span style="background:#f8d7da;color:#721c24;padding:0.4rem 1rem;border-radius:4px;font-weight:600;">
      ✗ <?= $_fail ?> test<?= $_fail > 1 ? 's' : '' ?> failed — check coefficients in lib/tax-tables/2025-26.php
    </span>
  <?php endif; ?>
  <small style="color:#888;">
    <?= $_tests_from_db
        ? 'Tests loaded from imported CSV'
        : 'Using built-in test cases — <a href="/custom/payroll/config.php?mainmenu=admintools&tab=tests">import CSV on the Configuration page</a> to override' ?>
  </small>
</div>

<table class="noborder" style="width:100%;max-width:1000px;margin-bottom:1rem;font-size:0.9em;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;text-align:left;">Test case</th>
      <th style="padding:0.4rem 0.6rem;text-align:center;">Period</th>
      <th style="padding:0.4rem 0.6rem;text-align:right;">Expected PAYG</th>
      <th style="padding:0.4rem 0.6rem;text-align:right;">Actual PAYG</th>
      <th style="padding:0.4rem 0.6rem;text-align:center;">Result</th>
      <th style="padding:0.4rem 1rem;text-align:left;color:#666;">Source</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($_tests as $i => $_t): ?>
    <tr style="<?= $i % 2 ? 'background:#fafafa;' : '' ?><?= $_t['ok'] ? '' : 'background:#fff3f3;' ?>">
      <td style="padding:0.35rem 1rem;"><?= htmlspecialchars($_t[0]) ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:center;font-size:0.85em;"><?= ucfirst($_t[2]) ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:right;">$<?= $_t[7] ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:right;">$<?= $_t['actual'] ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:center;font-weight:bold;<?= $_t['ok'] ? 'color:#27ae60;' : 'color:#c0392b;' ?>">
        <?= $_t['ok'] ? '✓' : '✗' ?>
      </td>
      <td style="padding:0.35rem 1rem;color:#666;font-size:0.85em;"><?= htmlspecialchars($_t[8]) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p style="font-size:0.85em;color:#666;max-width:800px;">
  <strong>ATO sample data</strong> = values from "Withholding amounts sample data" PDF (NAT 1004, published 17 June 2026).<br>
  <strong>ATO example</strong> = worked example from the ATO Medicare levy adjustment page (published 17 June 2026). The expected PAYG shown is normal Scale 2 withholding minus the ATO-stated adjustment.<br>
  <strong>From coefficients</strong> = expected value computed directly from the ATO coefficient table — tests routing and formula logic, not coefficient accuracy. Replace with ATO sample data values when available.
</p>

<?php
// ── 2026-27 test block ─────────────────────────────────────────────────────
// Coefficients are identical to 2025-26 (confirmed from ATO NAT 1004 published 17 June 2026).
// These tests verify: (a) FY routing selects the 2026-27 table; (b) all periods work correctly.
// Weekly and fortnightly values verified by hand against the coefficients.
// Monthly values taken directly from the ATO sample data PDF.
// HECS 2026-27 is a placeholder (2025-26 values) — not tested here until NAT 3539 is published.

$_fy2            = '2026-27';
$_tests2_db      = payroll_load_test_cases($db, $conf, $_fy2);
$_tests2_from_db = !empty($_tests2_db);
$_tests2 = $_tests2_from_db ? $_tests2_db : [
    // [label, gross, period, scale, has_hecs, has_mla, mla_deps, expected_payg, source]
    // ── Weekly ────────────────────────────────────────────────────────────
    ['Scale 1 — $188/wk (S1 bracket 2)',      188.00, 'weekly', 'scale1', false, false, 0,   28, 'ATO sample data'],
    ['Scale 2 — $362/wk (nil)',               362.00, 'weekly', 'scale2', false, false, 0,    0, 'ATO sample data'],
    ['Scale 2 — $538/wk (first withholding)', 538.00, 'weekly', 'scale2', false, false, 0,   27, 'ATO sample data'],
    ['Scale 2 — $865/wk',                     865.00, 'weekly', 'scale2', false, false, 0,   94, 'ATO sample data'],
    ['Scale 3 — $538/wk',                     538.00, 'weekly', 'scale3', false, false, 0,  161, 'ATO sample data'],
    ['Scale 5 — $865/wk (full Medi exempt)',  865.00, 'weekly', 'scale5', false, false, 0,   77, 'ATO sample data'],
    ['Scale 6 — $931/wk (half Medi, div pt)', 931.00, 'weekly', 'scale6', false, false, 0,   98, 'ATO sample data'],
    // ── Fortnightly ───────────────────────────────────────────────────────
    ['Scale 1 — $740/fn',                     740.00, 'fortnightly', 'scale1', false, false, 0,  132, 'ATO sample data'],
    ['Scale 2 — $1,728/fn',                  1728.00, 'fortnightly', 'scale2', false, false, 0,  188, 'ATO sample data'],
    ['Scale 6 — $2,564/fn',                  2564.00, 'fortnightly', 'scale6', false, false, 0,  432, 'ATO sample data'],
    // ── Monthly ───────────────────────────────────────────────────────────
    ['Scale 2 — $3,930.33/mth',             3930.33, 'monthly', 'scale2', false, false, 0,   468, 'ATO sample data'],
    ['Scale 2 — $7,990.67/mth',             7990.67, 'monthly', 'scale2', false, false, 0,  1772, 'ATO sample data'],
    ['Scale 5 — $7,990.67/mth (full Medi)', 7990.67, 'monthly', 'scale5', false, false, 0,  1612, 'ATO sample data'],
];

$_pass2 = 0; $_fail2 = 0;
foreach ($_tests2 as &$_t2) {
    $r2 = PaygCalculator::calculate($_t2[1], $_t2[2], $_t2[3], $_t2[4], $_fy2, $_t2[5], $_t2[6]);
    $_t2['actual'] = $r2['payg'];
    $_t2['ok']     = ($r2['payg'] == $_t2[7]);
    if ($_t2['ok']) $_pass2++; else $_fail2++;
}
unset($_t2);
?>

<hr style="margin:2.5rem 0;">
<h2>PAYG Calculation Verification — 2026-27</h2>
<p style="max-width:750px;">Tests the PAYG calculator using the 2026-27 financial year.
   The ATO published NAT 1004 on 17 June 2026; the coefficients are identical to 2025-26.
   These tests verify that the FY routing is correct and all pay periods calculate accurately.</p>
<div style="margin-bottom:0.5rem;padding:0.5rem 1rem;background:#fff8e1;border-left:4px solid #f39c12;max-width:750px;font-size:0.9em;">
  <strong>HECS 2026-27:</strong> Using 2025-26 thresholds as a placeholder until NAT&nbsp;3539 (Schedule&nbsp;8) for 2026-27 is published by the ATO.
  Seed updated HECS brackets via <strong>Payroll &gt; Configuration &gt; HECS brackets &gt; Seed</strong> once available.
</div>

<div style="margin-bottom:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
  <?php if ($_fail2 === 0): ?>
    <span style="background:#d4edda;color:#155724;padding:0.4rem 1rem;border-radius:4px;font-weight:600;">
      ✓ All <?= $_pass2 ?> tests passed
    </span>
  <?php else: ?>
    <span style="background:#f8d7da;color:#721c24;padding:0.4rem 1rem;border-radius:4px;font-weight:600;">
      ✗ <?= $_fail2 ?> test<?= $_fail2 > 1 ? 's' : '' ?> failed — check lib/tax-tables/2026-27.php
    </span>
  <?php endif; ?>
  <small style="color:#888;">
    <?= $_tests2_from_db
        ? 'Tests loaded from imported CSV'
        : 'Using built-in test cases — <a href="/custom/payroll/config.php?mainmenu=admintools&tab=tests">import CSV on the Configuration page</a> to override' ?>
  </small>
</div>

<table class="noborder" style="width:100%;max-width:1000px;margin-bottom:1rem;font-size:0.9em;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;text-align:left;">Test case</th>
      <th style="padding:0.4rem 0.6rem;text-align:center;">Period</th>
      <th style="padding:0.4rem 0.6rem;text-align:right;">Expected PAYG</th>
      <th style="padding:0.4rem 0.6rem;text-align:right;">Actual PAYG</th>
      <th style="padding:0.4rem 0.6rem;text-align:center;">Result</th>
      <th style="padding:0.4rem 1rem;text-align:left;color:#666;">Source</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($_tests2 as $i => $_t2): ?>
    <tr style="<?= $i % 2 ? 'background:#fafafa;' : '' ?><?= $_t2['ok'] ? '' : 'background:#fff3f3;' ?>">
      <td style="padding:0.35rem 1rem;"><?= htmlspecialchars($_t2[0]) ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:center;font-size:0.85em;"><?= ucfirst($_t2[2]) ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:right;">$<?= $_t2[7] ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:right;">$<?= $_t2['actual'] ?></td>
      <td style="padding:0.35rem 0.6rem;text-align:center;font-weight:bold;<?= $_t2['ok'] ? 'color:#27ae60;' : 'color:#c0392b;' ?>">
        <?= $_t2['ok'] ? '✓' : '✗' ?>
      </td>
      <td style="padding:0.35rem 1rem;color:#666;font-size:0.85em;"><?= htmlspecialchars($_t2[8]) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p style="font-size:0.85em;color:#666;max-width:800px;">
  All values sourced from the ATO "Withholding amounts sample data" PDF (NAT 1004, published 17 June 2026).
  Weekly and fortnightly values also verified by hand against the coefficient table.
</p>

<script>
function toggleValue() {
    var ct = document.getElementById('calc_type').value;
    var row = document.getElementById('row_calc_value');
    var lbl = document.getElementById('calc_value_label');
    var sfx = document.getElementById('calc_value_suffix');
    if (ct === 'manual' || ct === 'hecs_auto') {
        row.style.display = 'none';
    } else {
        row.style.display = '';
        if (ct === 'percent_gross' || ct === 'percent_net') {
            lbl.innerHTML = '<strong>Rate</strong>';
            sfx.textContent = '%';
        } else {
            lbl.innerHTML = '<strong>Amount</strong>';
            sfx.textContent = '$ per period';
        }
    }
}
document.addEventListener('DOMContentLoaded', toggleValue);
</script>
</div>
<?php llxFooter(); ?>
