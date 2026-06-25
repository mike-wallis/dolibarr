<?php
/**
 * Payroll — employee list.
 * Lists all active Dolibarr employees with payroll profile status.
 * Also handles creating a new employee (Dolibarr User + linked Contact).
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$errors = [];
$msg    = '';

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

// ── Create new employee ───────────────────────────────────────────────────────

if ($action === 'create_employee') {
    $firstname = trim(GETPOST('emp_firstname', 'alpha'));
    $lastname  = trim(GETPOST('emp_lastname',  'alpha'));
    $email     = trim(GETPOST('emp_email',     'email'));
    $login     = trim(GETPOST('emp_login',     'aZ09'));

    if (!$lastname) {
        $errors[] = 'Last name is required.';
    }
    if (!$login) {
        $errors[] = 'Login is required.';
    }

    if (empty($errors)) {
        // Check login is unique
        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE login = '" . $db->escape($login) . "' LIMIT 1");
        if ($db->fetch_object($chk)) {
            $errors[] = 'Login "' . dol_htmlentities($login) . '" is already in use — choose a different one.';
        }
    }

    if (empty($errors)) {
        $db->begin();

        // 1. Create Dolibarr User
        $newuser            = new User($db);
        $newuser->firstname = $firstname;
        $newuser->lastname  = $lastname;
        $newuser->email     = $email;
        $newuser->login     = $login;
        $newuser->employee  = 1;
        $newuser->statut    = 1; // active
        $newuser->entity    = (int)$conf->entity;

        $newid = $newuser->create($user);
        if ($newid <= 0) {
            $errors[] = 'Could not create user: ' . ($newuser->error ?: 'unknown error');
        }

        // 2. Create linked Contact (Third Party → Contacts)
        if (empty($errors)) {
            $contact            = new Contact($db);
            $contact->firstname = $firstname;
            $contact->lastname  = $lastname;
            $contact->email     = $email;
            $contact->statut    = 1;
            $contact->fk_soc    = 0;
            $contact->entity    = (int)$conf->entity;

            $contact_id = $contact->create($user);
            if ($contact_id > 0) {
                // Link the contact back to the user
                $db->query("UPDATE " . MAIN_DB_PREFIX . "user SET fk_socpeople = " . (int)$contact_id
                    . " WHERE rowid = " . (int)$newid);
            }
            // Contact creation failure is non-fatal — user was created, continue
        }

        if (empty($errors)) {
            $db->commit();
            header('Location: employee_payroll.php?userid=' . (int)$newid
                . '&mainmenu=billing&leftmenu=payroll_employees&msg=newemployee');
            exit;
        } else {
            $db->rollback();
        }
    }
}

// ── Load employee list ────────────────────────────────────────────────────────

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

// ── HTML ──────────────────────────────────────────────────────────────────────

llxHeader('', 'Payroll Employees');
?>
<div class="fiche">
<h1>Payroll Employees</h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin:0 0 1rem;">
  <strong>Could not add employee:</strong><br>
  <?php foreach ($errors as $e): ?>&bull; <?= dol_htmlentities($e) ?><br><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (GETPOST('deleted', 'alpha')): ?>
<div class="alert alert-success" style="margin:0 0 1rem;">Employee removed.</div>
<?php endif; ?>

<!-- ── Add New Employee panel ──────────────────────────────────────────────── -->

<div style="margin-bottom:1.2rem;">
  <button type="button" id="btn-add-emp" class="button buttonaction"
          onclick="toggleNewEmp()">+ Add New Employee</button>
</div>

<div id="new-emp-panel" style="display:<?= (!empty($errors) && $action === 'create_employee') ? '' : 'none' ?>;
     background:#f4f6ff;border:1px solid #c0ccf0;border-radius:5px;
     padding:1.2rem 1.5rem;max-width:680px;margin-bottom:1.5rem;">
  <h3 style="margin:0 0 1rem;font-size:1em;color:#333;">New Employee</h3>

  <form method="post" action="employees.php?mainmenu=billing&leftmenu=payroll_employees">
    <input type="hidden" name="token"  value="<?= newToken() ?>">
    <input type="hidden" name="action" value="create_employee">

    <table style="border:none;border-collapse:separate;border-spacing:0 0.5rem;width:100%;">
      <tr>
        <td style="padding:0 1rem 0 0;white-space:nowrap;width:1%;"><label>First name</label></td>
        <td style="padding:0 1.5rem 0 0;">
          <input type="text" name="emp_firstname" id="emp_firstname" class="flat" style="width:190px;"
                 value="<?= dol_htmlentities(GETPOST('emp_firstname','alpha')) ?>"
                 oninput="autoLogin()" autofocus>
        </td>
        <td style="padding:0 1rem 0 0;white-space:nowrap;width:1%;"><label>Last name <span style="color:#c00;">*</span></label></td>
        <td>
          <input type="text" name="emp_lastname" id="emp_lastname" class="flat" style="width:190px;"
                 value="<?= dol_htmlentities(GETPOST('emp_lastname','alpha')) ?>"
                 oninput="autoLogin()" required>
        </td>
      </tr>
      <tr>
        <td style="padding:0 1rem 0 0;"><label>Email</label></td>
        <td style="padding:0 1.5rem 0 0;">
          <input type="email" name="emp_email" class="flat" style="width:190px;"
                 value="<?= dol_htmlentities(GETPOST('emp_email','email')) ?>">
        </td>
        <td style="padding:0 1rem 0 0;"><label>Login <span style="color:#c00;">*</span></label></td>
        <td>
          <input type="text" name="emp_login" id="emp_login" class="flat" style="width:190px;"
                 value="<?= dol_htmlentities(GETPOST('emp_login','aZ09')) ?>"
                 pattern="[a-zA-Z0-9_\-\.]+" title="Letters, numbers, _ - . only" required>
          <br><small style="color:#888;">Auto-filled — edit if needed. Letters/numbers only.</small>
        </td>
      </tr>
    </table>

    <div style="margin-top:1rem;display:flex;align-items:center;gap:0.75rem;">
      <button type="submit" class="button buttonaction">Add employee &amp; set up payroll profile →</button>
      <button type="button" class="button" onclick="toggleNewEmp()">Cancel</button>
    </div>
    <p style="margin:0.75rem 0 0;font-size:0.82em;color:#777;">
      Creates a Dolibarr user account (Employee flag set) and a linked Contact record
      so the employee appears in Third Parties → Contacts. Set a password later via
      <em>Tools → Users &amp; Groups</em> if the employee needs Dolibarr login access.
      You'll set up payroll details on the next screen.
    </p>
  </form>
</div>

<script>
var loginEdited = <?= (GETPOST('emp_login','aZ09') ? 'true' : 'false') ?>;
function autoLogin() {
    if (loginEdited) return;
    var fn = (document.getElementById('emp_firstname').value || '').trim();
    var ln = (document.getElementById('emp_lastname').value  || '').trim();
    var proposed = (fn ? fn.charAt(0) : '') + ln;
    proposed = proposed.toLowerCase().replace(/[^a-z0-9]/g, '');
    document.getElementById('emp_login').value = proposed;
}
document.getElementById('emp_login').addEventListener('input', function() { loginEdited = true; });
function toggleNewEmp() {
    var p = document.getElementById('new-emp-panel');
    p.style.display = (p.style.display === 'none') ? '' : 'none';
}
</script>

<!-- ── Employee list ───────────────────────────────────────────────────────── -->

<p style="margin:0 0 0.75rem;color:#555;">
  All active Dolibarr employees. Click <strong>Edit Payroll Profile</strong> to configure
  position type, pay period, rate, tax scale, HECS status, and optional deductions.
</p>

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
        $name       = trim($emp->firstname . ' ' . $emp->lastname) ?: $emp->login;
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
        No employees found. Use the <strong>Add New Employee</strong> button above, or ensure
        existing users have the <em>Employee</em> checkbox ticked in
        <em>Tools → Users &amp; Groups → Edit user</em>.
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
