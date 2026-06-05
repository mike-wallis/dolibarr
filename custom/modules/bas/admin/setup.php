<?php
/**
 * BAS & PAYG — module setup page.
 * Configure which accounting accounts are used to auto-calculate
 * W1 (wages), W2 (PAYG withheld), and super from journal entries.
 */

$res = 0;
if (!$res && is_file('../../../main.inc.php'))   { require '../../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../../main.inc.php')) { require '../../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Handle save ───────────────────────────────────────────────────────────────

if ($action === 'save') {
    $wages_raw = trim(strip_tags(GETPOST('accounts_wages',      'alpha')));
    $payg_raw  = trim(strip_tags(GETPOST('account_payg',        'alpha')));
    $super_raw = trim(strip_tags(GETPOST('accounts_super',      'alpha')));

    // Normalise: strip spaces around commas, uppercase
    $normalise = fn($s) => implode(',', array_filter(array_map('trim', explode(',', $s))));

    dolibarr_set_const($db, 'BAS_ACCOUNTS_WAGES', $normalise($wages_raw), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BAS_ACCOUNT_PAYG',   trim($payg_raw),        'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'BAS_ACCOUNTS_SUPER',  $normalise($super_raw), 'chaine', 0, '', $conf->entity);

    setEventMessages('Account settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Load current config ───────────────────────────────────────────────────────

$cfg_wages = getDolGlobalString('BAS_ACCOUNTS_WAGES');
$cfg_payg  = getDolGlobalString('BAS_ACCOUNT_PAYG');
$cfg_super = getDolGlobalString('BAS_ACCOUNTS_SUPER');

// ── Load chart of accounts for reference ─────────────────────────────────────

$accounts = [];
$sql = "SELECT aa.account_number, aa.label"
     . " FROM " . MAIN_DB_PREFIX . "accounting_account aa"
     . " WHERE aa.active = 1"
     . " AND aa.entity = " . (int) $conf->entity
     . " ORDER BY aa.account_number";
$res = $db->query($sql);
while ($res && ($row = $db->fetch_object($res))) {
    $accounts[] = $row;
}

// ── Page output ───────────────────────────────────────────────────────────────

llxHeader('', 'BAS & PAYG — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'BAS &amp; PAYG — Account Setup',
    -1,
    'accountancy'
);
?>

<p style="margin-bottom:1.5rem;">
  Configure which accounting accounts Dolibarr reads when auto-calculating PAYG figures.
  The report sums journal entries (from <code>llx_accounting_bookkeeping</code>) for the selected quarter.
  Leave blank to enter W1/W2 manually on the report page.
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">

<div class="div-table-responsive" style="max-width:700px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre">
    <th style="width:12rem;">BAS field</th>
    <th>Account number(s)</th>
    <th>How it's calculated</th>
  </tr>
</thead>
<tbody>

  <tr>
    <td><strong>W1</strong> — Wages &amp; salary</td>
    <td>
      <input type="text" name="accounts_wages" class="flat minwidth200"
             value="<?php echo htmlspecialchars($cfg_wages, ENT_QUOTES); ?>"
             placeholder="e.g. 6400.01,6400.02">
      <div style="font-size:0.8em;color:#888;margin-top:2px;">Comma-separated if multiple accounts</div>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>DEBIT</strong> entries to these accounts in the quarter
    </td>
  </tr>

  <tr>
    <td><strong>W2</strong> — PAYG withheld</td>
    <td>
      <input type="text" name="account_payg" class="flat minwidth200"
             value="<?php echo htmlspecialchars($cfg_payg, ENT_QUOTES); ?>"
             placeholder="e.g. 2111">
      <div style="font-size:0.8em;color:#888;margin-top:2px;">Single account (PAYG withholding payable)</div>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>CREDIT</strong> entries to this account in the quarter<br>
      <em>(payroll journal credits this account when tax is withheld)</em>
    </td>
  </tr>

  <tr>
    <td><strong>Super</strong> <small>(info only)</small></td>
    <td>
      <input type="text" name="accounts_super" class="flat minwidth200"
             value="<?php echo htmlspecialchars($cfg_super, ENT_QUOTES); ?>"
             placeholder="e.g. 6300.02">
      <div style="font-size:0.8em;color:#888;margin-top:2px;">Shown in report — not included in BAS totals</div>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>DEBIT</strong> entries to these accounts in the quarter
    </td>
  </tr>

</tbody>
</table>
</div>

<div style="margin-bottom:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<?php if (!empty($accounts)): ?>
<div class="div-table-responsive" style="max-width:700px;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre">
    <th colspan="2">Chart of Accounts — reference</th>
  </tr>
  <tr class="liste_titre_sel">
    <th style="width:10rem;">Account number</th>
    <th>Label</th>
  </tr>
</thead>
<tbody>
<?php foreach ($accounts as $acc): ?>
<tr>
  <td class="nowrap"><code><?php echo htmlspecialchars($acc->account_number); ?></code></td>
  <td><?php echo htmlspecialchars($acc->label); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php elseif ($db->lasterror()): ?>
<p style="color:#c00;">
  Could not load chart of accounts — accounting module may not be set up yet.<br>
  You can still enter account numbers manually above once accounts are configured.
</p>
<?php else: ?>
<p style="color:#888;">No active accounts found. Set up your chart of accounts in Accounting first.</p>
<?php endif; ?>

<?php
print dol_get_fiche_end();
llxFooter();
