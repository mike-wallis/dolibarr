<?php
/**
 * Payroll — employee list.
 * Shows all Dolibarr employees with their payroll profile status
 * and a direct Edit Payroll Profile button for each.
 */

require '../../main.inc.php';

if (!$user->admin) {
    accessforbidden();
}

$position_labels = [
    'FT'   => 'Full Time',
    'FTT'  => 'Full Time Temp',
    'CA'   => 'Casual',
    'CAPT' => 'Casual Part Time',
    'PT'   => 'Part Time',
    'AP'   => 'Apprentice',
    'O'    => 'Other',
];

$period_labels = [
    'weekly'      => 'Weekly',
    'fortnightly' => 'Fortnightly',
    'halfmonthly' => 'Half Monthly',
    'monthly'     => 'Monthly',
    'fourweekly'  => 'Four Weekly',
];

$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname, u.email,"
    . " pe.position_type, pe.pay_period, pe.pay_rate, pe.pay_rate_type,"
    . " pe.tax_scale, pe.has_hecs"
    . " FROM " . MAIN_DB_PREFIX . "user u"
    . " LEFT JOIN " . MAIN_DB_PREFIX . "payroll_employee pe"
    . "   ON pe.fk_user = u.rowid AND pe.entity = " . (int)$conf->entity
    . " WHERE u.employee = 1 AND u.statut = 1"
    . " ORDER BY u.lastname, u.firstname";

$res       = $db->query($sql);
$employees = [];
while ($obj = $db->fetch_object($res)) {
    $employees[] = $obj;
}

llxHeader('', 'Payroll Employees');
?>
<div class="fiche">
<h1>Payroll Employees</h1>
<p>All active Dolibarr employees. Click <strong>Edit Payroll Profile</strong> to configure an employee's
   position type, pay period, rate, tax scale, HECS status, and optional deductions.</p>

<table class="noborder" style="width:100%;max-width:950px;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Position</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Pay period</th>
      <th style="padding:0.5rem 1rem;text-align:right;">Rate</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Tax scale</th>
      <th style="padding:0.5rem 1rem;text-align:center;">HECS</th>
      <th style="padding:0.5rem 1rem;"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($employees as $emp):
        $name    = trim($emp->firstname . ' ' . $emp->lastname) ?: $emp->login;
        $has_profile = !empty($emp->position_type);
    ?>
    <tr style="border-top:1px solid #eee;">
      <td style="padding:0.5rem 1rem;">
        <strong><?= dol_htmlentities($name) ?></strong>
        <?php if ($emp->email): ?>
          <br><small style="color:#888;"><?= dol_htmlentities($emp->email) ?></small>
        <?php endif; ?>
      </td>
      <td style="padding:0.5rem 1rem;">
        <?php if ($has_profile): ?>
          <?= dol_htmlentities($position_labels[$emp->position_type] ?? $emp->position_type) ?>
        <?php else: ?>
          <span style="color:#c00;">⚠ No profile</span>
        <?php endif; ?>
      </td>
      <td style="padding:0.5rem 1rem;">
        <?= $has_profile ? dol_htmlentities($period_labels[$emp->pay_period] ?? $emp->pay_period) : '—' ?>
      </td>
      <td style="padding:0.5rem 1rem;text-align:right;">
        <?php if ($has_profile): ?>
          $<?= number_format((float)$emp->pay_rate, 2) ?>
          <small style="color:#888;"><?= $emp->pay_rate_type === 'salary' ? '/period' : '/hr' ?></small>
        <?php else: ?>
          —
        <?php endif; ?>
      </td>
      <td style="padding:0.5rem 1rem;">
        <?php if ($has_profile): ?>
          <?php
          $scale_short = [
              'scale1' => 'Scale 1 (no TFT)',
              'scale2' => 'Scale 2 (TFT)',
              'scale3' => 'Scale 3 (foreign)',
              'scale4' => 'Scale 4 (no TFN)',
              'scale6' => 'Scale 6 (WHM)',
          ];
          echo dol_htmlentities($scale_short[$emp->tax_scale] ?? $emp->tax_scale);
          ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </td>
      <td style="padding:0.5rem 1rem;text-align:center;">
        <?= ($has_profile && $emp->has_hecs) ? '✓' : '—' ?>
      </td>
      <td style="padding:0.5rem 1rem;text-align:right;white-space:nowrap;">
        <a href="employee_payroll.php?userid=<?= (int)$emp->rowid ?>&mainmenu=billing&leftmenu=payroll_employees"
           class="button<?= $has_profile ? '' : ' buttonaction' ?>">
          <?= $has_profile ? 'Edit payroll profile' : 'Set up payroll profile' ?>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($employees)): ?>
    <tr>
      <td colspan="7" style="padding:1rem;color:#888;">
        No employees found. Make sure users have the <em>Employee</em> checkbox ticked in their user profile
        (Tools → Users &amp; Groups → Edit user → Employee section).
      </td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>

<div style="margin-top:1.5rem;">
  <a href="payrun.php?mainmenu=billing&leftmenu=payroll_run" class="button">Go to Pay Run</a>
  &nbsp;
  <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup" class="button">Payroll Setup</a>
</div>

</div>
<?php llxFooter(); ?>
