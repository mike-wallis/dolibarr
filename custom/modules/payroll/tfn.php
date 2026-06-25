<?php
/**
 * TFN Manager — admin page for viewing and managing employee Tax File Numbers.
 * TFNs are encrypted with AES-256-CBC; key is in .env (TFN_KEY).
 * Stored in llx_payroll_employee.tfn_encrypted (NOT in user_extrafields).
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/TfnHelper.php';

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');
$tfnKey = tfn_load_key();

$encrypted = '';
$decrypted = '';
$error     = '';

if ($action === 'encrypt' && $tfnKey) {
    $plain = preg_replace('/\D/', '', GETPOST('plain', 'alphanohtml'));
    if (preg_match('/^\d{8,9}$/', $plain)) {
        $encrypted = tfn_encrypt($plain, $tfnKey);
    } else {
        $error = 'TFN must be 8 or 9 digits.';
    }
}
if ($action === 'decrypt' && $tfnKey) {
    $blob   = trim(GETPOST('blob', 'restricthtml'));
    $result = tfn_decrypt($blob, $tfnKey);
    if ($result !== false) {
        $decrypted = $result;
    } else {
        $error = 'Decryption failed — check the encrypted value.';
    }
}

// Load all employees with payroll profiles
$employees = [];
$sql = "SELECT u.rowid, u.firstname, u.lastname, u.login, pe.tfn_encrypted, pe.rowid AS pe_id"
    . " FROM " . MAIN_DB_PREFIX . "user u"
    . " LEFT JOIN " . MAIN_DB_PREFIX . "payroll_employee pe"
    . "   ON pe.fk_user = u.rowid AND pe.entity = " . (int)$conf->entity
    . " WHERE u.employee = 1 AND u.entity = " . (int)$conf->entity
    . " ORDER BY u.lastname, u.firstname";
$resql = $db->query($sql);
if ($resql) {
    while (($obj = $db->fetch_object($resql)) !== null) {
        $tfnPlain = '';
        if (!empty($obj->tfn_encrypted) && $tfnKey) {
            $tfnPlain = tfn_decrypt($obj->tfn_encrypted, $tfnKey) ?: '⚠ Decrypt failed';
        }
        $employees[] = [
            'id'        => $obj->rowid,
            'name'      => trim($obj->firstname . ' ' . $obj->lastname) ?: $obj->login,
            'has_pe'    => !empty($obj->pe_id),
            'tfn_set'   => !empty($obj->tfn_encrypted),
            'tfn_plain' => $tfnPlain,
        ];
    }
}

llxHeader('', 'TFN Manager');
?>
<div class="fiche">

<div style="margin-bottom:1rem;">
  <a href="<?= DOL_URL_ROOT ?>/custom/payroll/employees.php?mainmenu=billing&leftmenu=payroll_employees">← Payroll Employees</a>
</div>

<h1>TFN Manager <small style="font-size:0.55em;color:#c00;font-weight:normal;">(Admin only — restricted)</small></h1>

<?php if (!$tfnKey): ?>
<div class="alert alert-danger" style="max-width:700px;">
  <strong>TFN_KEY not found in .env</strong> — encryption/decryption is unavailable.<br>
  Generate a key with: <code style="display:inline-block;margin-top:0.4rem;">php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"</code>
  then add <code>TFN_KEY=&lt;result&gt;</code> to <code>.env</code>. Restart Apache after editing .env.
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= dol_htmlentities($error) ?></div>
<?php endif; ?>

<p style="color:#555;max-width:680px;">
  TFNs are encrypted with AES-256 before storage. Only the encrypted blob is stored in the database —
  the key lives in <code>.env</code> only. Entering a TFN on an employee's
  <a href="<?= DOL_URL_ROOT ?>/custom/payroll/employees.php?mainmenu=billing">payroll profile</a>
  handles encryption automatically.
</p>

<hr>

<h2>Employee TFNs</h2>
<table class="noborder" style="width:100%;max-width:650px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 1rem;text-align:left;">TFN</th>
      <th style="padding:0.5rem 1rem;"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($employees as $emp): ?>
    <tr>
      <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($emp['name']) ?></td>
      <td style="padding:0.4rem 1rem;font-family:monospace;">
        <?php if (!$emp['has_pe']): ?>
          <span style="color:#aaa;">no payroll profile</span>
        <?php elseif (!$tfnKey): ?>
          <span style="color:#aaa;"><?= $emp['tfn_set'] ? 'set (key unavailable)' : 'not set' ?></span>
        <?php elseif ($emp['tfn_set']): ?>
          <span style="color:#155724;font-weight:600;">✓</span>
          <?= dol_htmlentities($emp['tfn_plain']) ?>
        <?php else: ?>
          <span style="color:#856404;">not set</span>
        <?php endif; ?>
      </td>
      <td style="padding:0.4rem 1rem;">
        <a href="employee_payroll.php?userid=<?= (int)$emp['id'] ?>&mainmenu=billing&leftmenu=payroll_employees#tfn-section"
           class="button" style="font-size:0.85em;padding:0.2rem 0.6rem;">Edit profile</a>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?>
    <tr><td colspan="3" style="padding:0.5rem 1rem;color:#888;">No employees with payroll profiles found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
<p style="font-size:0.85rem;color:#888;margin-top:0.5rem;">
  To set or update a TFN: click <strong>Edit profile</strong> and use the Tax File Number section.
</p>

<hr>

<h2>Encrypt utility</h2>
<p>Paste a plain TFN to get the encrypted blob — useful for bulk imports or verification.</p>
<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?mainmenu=billing&leftmenu=payroll_tfn">
  <input type="hidden" name="token"  value="<?= newToken() ?>">
  <input type="hidden" name="action" value="encrypt">
  <table class="noborder" style="max-width:500px;">
    <tr>
      <td style="padding:0.4rem 0.75rem;"><label>Plain TFN (digits only):</label></td>
      <td style="padding:0.4rem 0.75rem;">
        <input type="text" name="plain" maxlength="9" class="flat" style="width:120px;letter-spacing:0.08em;"
               autocomplete="off" inputmode="numeric" placeholder="e.g. 123456789">
      </td>
    </tr>
    <?php if ($encrypted): ?>
    <tr>
      <td style="padding:0.4rem 0.75rem;color:#155724;font-weight:bold;">Encrypted:</td>
      <td style="padding:0.4rem 0.75rem;">
        <input type="text" readonly value="<?= dol_htmlentities($encrypted) ?>" class="flat"
               style="width:340px;font-family:monospace;font-size:0.8rem;" onclick="this.select()">
        <br><small>Click to select all, then copy</small>
      </td>
    </tr>
    <?php endif; ?>
    <tr>
      <td></td>
      <td style="padding:0.4rem 0.75rem;"><input type="submit" class="butAction" value="Encrypt"></td>
    </tr>
  </table>
</form>

<hr>

<h2>Decrypt / verify</h2>
<p>Paste an encrypted blob to confirm it decrypts correctly.</p>
<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?mainmenu=billing&leftmenu=payroll_tfn">
  <input type="hidden" name="token"  value="<?= newToken() ?>">
  <input type="hidden" name="action" value="decrypt">
  <table class="noborder" style="max-width:500px;">
    <tr>
      <td style="padding:0.4rem 0.75rem;"><label>Encrypted value:</label></td>
      <td style="padding:0.4rem 0.75rem;">
        <input type="text" name="blob" class="flat"
               style="width:340px;font-family:monospace;font-size:0.8rem;" autocomplete="off">
      </td>
    </tr>
    <?php if ($decrypted): ?>
    <tr>
      <td style="padding:0.4rem 0.75rem;color:#155724;font-weight:bold;">Decrypted TFN:</td>
      <td style="padding:0.4rem 0.75rem;font-family:monospace;font-size:1.1rem;"><?= dol_htmlentities($decrypted) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td></td>
      <td style="padding:0.4rem 0.75rem;"><input type="submit" class="butAction" value="Decrypt"></td>
    </tr>
  </table>
</form>

<hr>
<div class="alert alert-warning" style="max-width:640px;">
  <strong>Security reminders:</strong>
  <ul style="margin:0.5rem 0 0;">
    <li>Do not share the URL of this page or leave it open on a shared screen</li>
    <li>Do not store plain TFNs anywhere in Dolibarr — only encrypted blobs</li>
    <li>Back up <code>.env</code> securely — if TFN_KEY is lost, encrypted TFNs cannot be recovered</li>
    <li>ATO requires you to keep TFN declarations (NAT 3092) for the duration of employment plus 5 years</li>
  </ul>
</div>

</div>
<?php llxFooter(); ?>
