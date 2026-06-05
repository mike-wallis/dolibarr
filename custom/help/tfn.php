<?php
require '../../main.inc.php';

// Admin only
if (!$user->admin) {
    accessforbidden();
}

// Load .env key — file is at repo root, two levels above htdocs/
$envFile = DOL_DOCUMENT_ROOT . '/../../.env';
$tfnKey  = '';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        if (preg_match('/^TFN_KEY\s*=\s*(.+)$/', trim($line), $m)) {
            $tfnKey = base64_decode(trim($m[1]));
        }
    }
}

function tfn_encrypt($plain, $key) {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function tfn_decrypt($blob, $key) {
    $raw = base64_decode($blob);
    if (strlen($raw) < 17) return false;
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

$action    = GETPOST('action', 'alpha');
$encrypted = '';
$decrypted = '';
$error     = '';

if ($action === 'encrypt' && $tfnKey) {
    $plain = trim(GETPOST('plain', 'alphanohtml'));
    if (preg_match('/^\d{8,9}$/', $plain)) {
        $encrypted = tfn_encrypt($plain, $tfnKey);
    } else {
        $error = 'TFN must be 8 or 9 digits.';
    }
}
if ($action === 'decrypt' && $tfnKey) {
    $blob = trim(GETPOST('blob', 'restricthtml'));
    $result = tfn_decrypt($blob, $tfnKey);
    if ($result !== false) {
        $decrypted = $result;
    } else {
        $error = 'Decryption failed — check the encrypted value.';
    }
}

// Load employee TFNs from DB
$employees = [];
$sql = "SELECT u.rowid, u.firstname, u.lastname, ue.tfn
        FROM llx_user u
        LEFT JOIN llx_user_extrafields ue ON ue.fk_object = u.rowid
        WHERE u.employee = 1 AND u.entity = 1
        ORDER BY u.lastname";
$resql = $db->query($sql);
if ($resql) {
    while (($obj = $db->fetch_object($resql)) !== null) {
        $tfnPlain = '';
        if (!empty($obj->tfn) && $tfnKey) {
            $tfnPlain = tfn_decrypt($obj->tfn, $tfnKey);
        }
        $employees[] = [
            'name' => $obj->firstname . ' ' . $obj->lastname,
            'encrypted' => $obj->tfn ?: '',
            'plain' => $tfnPlain ?: ($obj->tfn ? '⚠ Decrypt failed' : '— not set —'),
        ];
    }
}

llxHeader('', 'TFN — Restricted');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Tax File Numbers <small style="font-size:0.6em;color:#c00;">(Admin only — restricted page)</small></h1>

<?php if (!$tfnKey): ?>
<div class="alert alert-danger">
  <strong>TFN_KEY not found in .env</strong> — encryption/decryption is unavailable.
  Check that <code>.env</code> contains a <code>TFN_KEY</code> line.
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<hr>

<h2>Employee TFNs</h2>
<table class="noborder" style="width:100%;max-width:600px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 1rem;text-align:left;">TFN</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($employees as $emp): ?>
    <tr>
      <td style="padding:0.4rem 1rem;"><?= htmlspecialchars($emp['name']) ?></td>
      <td style="padding:0.4rem 1rem;font-family:monospace;"><?= htmlspecialchars($emp['plain']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p style="font-size:0.85rem;color:#888;margin-top:0.5rem;">
  To update a TFN: encrypt it below, copy the result, paste into the employee's user record
  (<strong>Users &amp; Groups &gt; [employee] &gt; Other attributes</strong>).
</p>

<hr>

<h2>Encrypt a TFN</h2>
<p>Enter the plain TFN — copy the encrypted result and paste it into the employee's <strong>Tax File Number</strong> field in their user record.</p>
<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
  <input type="hidden" name="token" value="<?= newToken() ?>">
  <input type="hidden" name="action" value="encrypt">
  <table class="noborder" style="max-width:500px;">
    <tr>
      <td style="padding:0.4rem 0.75rem;"><label>Plain TFN (digits only):</label></td>
      <td style="padding:0.4rem 0.75rem;"><input type="text" name="plain" maxlength="9" pattern="\d{8,9}" class="flat" style="width:120px;" autocomplete="off"></td>
    </tr>
    <?php if ($encrypted): ?>
    <tr>
      <td style="padding:0.4rem 0.75rem;color:#155724;font-weight:bold;">Encrypted value:</td>
      <td style="padding:0.4rem 0.75rem;">
        <input type="text" readonly value="<?= htmlspecialchars($encrypted) ?>" class="flat" style="width:340px;font-family:monospace;font-size:0.8rem;" onclick="this.select()">
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

<h2>Decrypt a value</h2>
<p>Paste an encrypted TFN value to verify it decrypts correctly.</p>
<form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
  <input type="hidden" name="token" value="<?= newToken() ?>">
  <input type="hidden" name="action" value="decrypt">
  <table class="noborder" style="max-width:500px;">
    <tr>
      <td style="padding:0.4rem 0.75rem;"><label>Encrypted value:</label></td>
      <td style="padding:0.4rem 0.75rem;"><input type="text" name="blob" class="flat" style="width:340px;font-family:monospace;font-size:0.8rem;" autocomplete="off"></td>
    </tr>
    <?php if ($decrypted): ?>
    <tr>
      <td style="padding:0.4rem 0.75rem;color:#155724;font-weight:bold;">Decrypted TFN:</td>
      <td style="padding:0.4rem 0.75rem;font-family:monospace;font-size:1.1rem;"><?= htmlspecialchars($decrypted) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td></td>
      <td style="padding:0.4rem 0.75rem;"><input type="submit" class="butAction" value="Decrypt"></td>
    </tr>
  </table>
</form>

<hr>
<div class="alert alert-warning" style="max-width:600px;">
  <strong>Security reminders:</strong>
  <ul style="margin:0.5rem 0 0;">
    <li>Do not share the URL of this page</li>
    <li>Do not store plain TFNs anywhere in Dolibarr — only encrypted blobs</li>
    <li>Back up <code>.env</code> securely — if the TFN_KEY is lost, encrypted TFNs cannot be recovered</li>
    <li>This page is only visible to Dolibarr admin accounts</li>
  </ul>
</div>

</div>
<?php llxFooter(); ?>
