<?php
/**
 * BAS & PAYG — module setup page.
 *
 * Configures which accounting accounts are used to auto-calculate
 * GST and PAYG figures from journal entries (llx_accounting_bookkeeping).
 * All fields are optional — leave blank to fall back to invoice totals / manual entry.
 */

$res = 0;
if (!$res && is_file('../../../main.inc.php'))   { require '../../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../../main.inc.php')) { require '../../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Normalise a comma-separated account string ────────────────────────────────
function bas_normalise(string $s): string
{
    return implode(',', array_values(array_filter(array_map('trim', explode(',', $s)))));
}

// ── Handle save ───────────────────────────────────────────────────────────────

if ($action === 'save') {
    // Single-account fields
    $single = ['BAS_ACCOUNT_GST_COLLECTED', 'BAS_ACCOUNT_GST_ITC', 'BAS_ACCOUNT_PAYG'];
    foreach ($single as $key) {
        $post_key = strtolower($key);   // form field names are lowercase
        $val = trim(strip_tags(GETPOST($post_key, 'alpha')));
        dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity);
    }

    // Multi-account fields (submitted as arrays from <select multiple>)
    $multi = ['BAS_ACCOUNTS_WAGES', 'BAS_ACCOUNTS_SUPER'];
    foreach ($multi as $key) {
        $post_key = strtolower($key);
        $arr = array_filter(array_map('trim', (array)($_POST[$post_key] ?? [])));
        dolibarr_set_const($db, $key, implode(',', $arr), 'chaine', 0, '', $conf->entity);
    }

    setEventMessages('Account settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Load current config ───────────────────────────────────────────────────────

$cfg = [
    'gst_collected' => getDolGlobalString('BAS_ACCOUNT_GST_COLLECTED'),
    'gst_itc'       => getDolGlobalString('BAS_ACCOUNT_GST_ITC'),
    'payg'          => getDolGlobalString('BAS_ACCOUNT_PAYG'),
    'wages'         => array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('BAS_ACCOUNTS_WAGES'))))),
    'super'         => array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('BAS_ACCOUNTS_SUPER'))))),
];

// ── Load chart of accounts ────────────────────────────────────────────────────

$accounts = [];
$sql = "SELECT aa.account_number, aa.label"
     . " FROM " . MAIN_DB_PREFIX . "accounting_account aa"
     . " WHERE aa.active = 1 AND aa.entity = " . (int)$conf->entity
     . " ORDER BY aa.account_number";
$resql = $db->query($sql);
while ($resql) {
    $row = $db->fetch_object($resql);
    if (!$row) break;
    $accounts[] = $row;
}
$has_accounts = !empty($accounts);

// ── HTML helpers ──────────────────────────────────────────────────────────────

/**
 * Single-account combobox (Select2 searchable).
 */
function bas_select_single(string $name, array $accounts, string $selected, string $placeholder = '— not configured (leave blank to use invoice totals) —'): string
{
    $h  = '<select name="' . $name . '" class="flat bas-single" style="min-width:320px;">';
    $h .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
    foreach ($accounts as $acc) {
        if (!isset($acc->account_number)) continue;
        $sel = ($acc->account_number === $selected) ? ' selected' : '';
        $h  .= '<option value="' . htmlspecialchars($acc->account_number, ENT_QUOTES) . '"' . $sel . '>'
             . htmlspecialchars($acc->account_number . ' — ' . $acc->label, ENT_QUOTES)
             . '</option>';
    }
    return $h . '</select>';
}

/**
 * Multi-account combobox (Select2 multi-select).
 */
function bas_select_multi(string $name, array $accounts, array $selected, string $placeholder = '— select accounts —'): string
{
    $h  = '<select name="' . $name . '[]" class="flat bas-multi" multiple style="min-width:320px;">';
    foreach ($accounts as $acc) {
        if (!isset($acc->account_number)) continue;
        $sel = in_array($acc->account_number, $selected, true) ? ' selected' : '';
        $h  .= '<option value="' . htmlspecialchars($acc->account_number, ENT_QUOTES) . '"' . $sel . '>'
             . htmlspecialchars($acc->account_number . ' — ' . $acc->label, ENT_QUOTES)
             . '</option>';
    }
    return $h . '</select>';
}

/**
 * Fallback text input when no accounts are in the COA yet.
 */
function bas_text_input(string $name, string $value, string $placeholder): string
{
    return '<input type="text" name="' . $name . '" class="flat minwidth200"'
         . ' value="' . htmlspecialchars($value, ENT_QUOTES) . '"'
         . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '">';
}

// ── Page ──────────────────────────────────────────────────────────────────────

llxHeader('', 'BAS & PAYG — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'BAS &amp; PAYG — Account Setup',
    -1,
    'accountancy'
);

if (!$has_accounts): ?>
<div class="info" style="margin-bottom:1rem;">
  No accounts found in the chart of accounts. Set up your chart of accounts in Accounting first,
  or enter account numbers as free text using the fields below.
</div>
<?php endif; ?>

<p style="margin-bottom:1.5rem;">
  Map each BAS field to the accounting account(s) Dolibarr should read from the journal
  (<code>llx_accounting_bookkeeping</code>).
  <strong>All fields are optional</strong> — leave blank to fall back to invoice totals (GST)
  or manual entry (PAYG).
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token"  value="<?php echo newToken(); ?>">

<?php
// ── Section: GST ─────────────────────────────────────────────────────────────
?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre">
    <th style="width:11rem;">BAS field</th>
    <th>Account</th>
    <th>How it's calculated</th>
  </tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      GST &mdash; leave blank to calculate from invoice totals (recommended if accounting module is not set up)
    </th>
  </tr>
</thead>
<tbody>
  <tr>
    <td><strong>1A</strong> &mdash; GST collected</td>
    <td>
      <?php if ($has_accounts):
        echo bas_select_single('bas_account_gst_collected', $accounts, $cfg['gst_collected']);
      else:
        echo bas_text_input('bas_account_gst_collected', $cfg['gst_collected'], 'e.g. 2-1100');
      endif; ?>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>CREDIT</strong> entries to this account in the quarter
    </td>
  </tr>
  <tr>
    <td><strong>1B</strong> &mdash; GST credits (ITC)</td>
    <td>
      <?php if ($has_accounts):
        echo bas_select_single('bas_account_gst_itc', $accounts, $cfg['gst_itc']);
      else:
        echo bas_text_input('bas_account_gst_itc', $cfg['gst_itc'], 'e.g. 1-3300');
      endif; ?>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>DEBIT</strong> entries to this account in the quarter
    </td>
  </tr>
</tbody>
</table>
</div>

<?php
// ── Section: PAYG ─────────────────────────────────────────────────────────────
?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre">
    <th style="width:11rem;">BAS field</th>
    <th>Account(s)</th>
    <th>How it's calculated</th>
  </tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      PAYG Withholding &mdash; leave blank to enter W1/W2 manually on the report page
    </th>
  </tr>
</thead>
<tbody>
  <tr>
    <td><strong>W1</strong> &mdash; Wages &amp; salary</td>
    <td>
      <?php if ($has_accounts):
        echo bas_select_multi('bas_accounts_wages', $accounts, $cfg['wages']);
      else:
        echo bas_text_input('bas_accounts_wages', implode(',', $cfg['wages']), 'e.g. 6400.01,6400.02');
      endif; ?>
      <div style="font-size:0.8em;color:#888;margin-top:3px;">
        <?php echo $has_accounts ? 'Hold Ctrl / Cmd to select multiple' : 'Comma-separated'; ?>
      </div>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>DEBIT</strong> entries to these accounts in the quarter
    </td>
  </tr>
  <tr>
    <td><strong>W2</strong> &mdash; PAYG withheld</td>
    <td>
      <?php if ($has_accounts):
        echo bas_select_single('bas_account_payg', $accounts, $cfg['payg'], '— not configured —');
      else:
        echo bas_text_input('bas_account_payg', $cfg['payg'], 'e.g. 2111');
      endif; ?>
      <div style="font-size:0.8em;color:#888;margin-top:3px;">PAYG withholding payable account</div>
    </td>
    <td style="font-size:0.85em;color:#555;">
      Sum of <strong>CREDIT</strong> entries to this account in the quarter<br>
      <em>(payroll journal credits this when tax is withheld)</em>
    </td>
  </tr>
</tbody>
</table>
</div>

<?php
// ── Section: Super ────────────────────────────────────────────────────────────
?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre">
    <th style="width:11rem;">Field</th>
    <th>Account(s)</th>
    <th>How it's calculated</th>
  </tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      Superannuation &mdash; shown in report as informational; not included in BAS totals
    </th>
  </tr>
</thead>
<tbody>
  <tr>
    <td><strong>Super</strong> <small>(info only)</small></td>
    <td>
      <?php if ($has_accounts):
        echo bas_select_multi('bas_accounts_super', $accounts, $cfg['super']);
      else:
        echo bas_text_input('bas_accounts_super', implode(',', $cfg['super']), 'e.g. 6300.02');
      endif; ?>
      <div style="font-size:0.8em;color:#888;margin-top:3px;">
        <?php echo $has_accounts ? 'Hold Ctrl / Cmd to select multiple' : 'Comma-separated'; ?>
      </div>
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

<?php if ($has_accounts): ?>
<script>
$(document).ready(function () {
    $('.bas-single').select2({
        width: '350px',
        allowClear: true,
        placeholder: '— not configured —'
    });
    $('.bas-multi').select2({
        width: '350px',
        placeholder: '— select accounts —'
    });
});
</script>
<?php endif; ?>

<?php
print dol_get_fiche_end();
llxFooter();
