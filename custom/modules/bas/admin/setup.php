<?php
/**
 * BAS & PAYG — module setup page.
 *
 * Configures BAS type (Simpler / Full GST) and the accounting accounts
 * used to auto-calculate each ATO field from journal entries.
 * All account fields are optional — leave blank to fall back to invoice
 * totals (GST) or manual entry (PAYG).
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
    // BAS type
    $bas_type = GETPOST('bas_type', 'alpha') === 'full' ? 'full' : 'simpler';
    dolibarr_set_const($db, 'BAS_TYPE', $bas_type, 'chaine', 0, '', $conf->entity);

    // Single-account fields
    $single_keys = [
        'BAS_ACCOUNT_GST_COLLECTED',
        'BAS_ACCOUNT_GST_ITC',
        'BAS_ACCOUNT_G2',
        'BAS_ACCOUNT_G3',
        'BAS_ACCOUNT_PAYG',
        'BAS_ACCOUNT_W3',
        'BAS_ACCOUNT_W4',
    ];
    foreach ($single_keys as $key) {
        $val = trim(strip_tags(GETPOST(strtolower($key), 'alpha')));
        dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity);
    }

    // Multi-account fields (arrays from <select multiple>)
    $multi_keys = ['BAS_ACCOUNTS_WAGES', 'BAS_ACCOUNTS_G10', 'BAS_ACCOUNTS_SUPER'];
    foreach ($multi_keys as $key) {
        $arr = array_filter(array_map('trim', (array)($_POST[strtolower($key)] ?? [])));
        dolibarr_set_const($db, $key, implode(',', $arr), 'chaine', 0, '', $conf->entity);
    }

    setEventMessages('Settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Load current config ───────────────────────────────────────────────────────

$bas_type = getDolGlobalString('BAS_TYPE') ?: 'simpler';

$cfg = [
    'gst_collected' => getDolGlobalString('BAS_ACCOUNT_GST_COLLECTED'),
    'gst_itc'       => getDolGlobalString('BAS_ACCOUNT_GST_ITC'),
    'g2'            => getDolGlobalString('BAS_ACCOUNT_G2'),
    'g3'            => getDolGlobalString('BAS_ACCOUNT_G3'),
    'g10'           => array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('BAS_ACCOUNTS_G10'))))),
    'payg'          => getDolGlobalString('BAS_ACCOUNT_PAYG'),
    'wages'         => array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('BAS_ACCOUNTS_WAGES'))))),
    'w3'            => getDolGlobalString('BAS_ACCOUNT_W3'),
    'w4'            => getDolGlobalString('BAS_ACCOUNT_W4'),
    'super'         => array_values(array_filter(array_map('trim', explode(',', getDolGlobalString('BAS_ACCOUNTS_SUPER'))))),
];

// ── Chart of accounts ─────────────────────────────────────────────────────────

$accounts = [];
$sql = "SELECT aa.account_number, aa.label"
     . " FROM " . MAIN_DB_PREFIX . "accounting_account aa"
     . " WHERE aa.active = 1 AND aa.entity = " . (int)$conf->entity
     . " ORDER BY aa.account_number";
$resql = $db->query($sql);
while ($resql) {
    $row = $db->fetch_object($resql);
    if (!($row instanceof stdClass)) break;
    $accounts[] = $row;
}
$has_accounts = !empty($accounts);

// ── HTML helpers ──────────────────────────────────────────────────────────────

function bas_select_single(string $name, array $accounts, string $selected, string $empty_label = '— not configured —'): string
{
    $h  = '<select name="' . $name . '" class="flat bas-single" style="min-width:320px;">';
    $h .= '<option value="">' . htmlspecialchars($empty_label) . '</option>';
    foreach ($accounts as $acc) {
        if (!isset($acc->account_number)) continue;
        $sel = ($acc->account_number === $selected) ? ' selected' : '';
        $h  .= '<option value="' . htmlspecialchars($acc->account_number, ENT_QUOTES) . '"' . $sel . '>'
             . htmlspecialchars($acc->account_number . ' — ' . $acc->label, ENT_QUOTES)
             . '</option>';
    }
    return $h . '</select>';
}

function bas_select_multi(string $name, array $accounts, array $selected): string
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

function bas_text_single(string $name, string $value, string $placeholder): string
{
    return '<input type="text" name="' . $name . '" class="flat minwidth200"'
         . ' value="' . htmlspecialchars($value, ENT_QUOTES) . '"'
         . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '">';
}

function bas_text_multi(string $name, array $values, string $placeholder): string
{
    return '<input type="text" name="' . $name . '" class="flat minwidth250"'
         . ' value="' . htmlspecialchars(implode(',', $values), ENT_QUOTES) . '"'
         . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '">'
         . '<div style="font-size:0.8em;color:#888;margin-top:2px;">Comma-separated</div>';
}

// ── Render row ────────────────────────────────────────────────────────────────
function bas_row(string $field, string $desc, string $widget, string $how, bool $full_only = false): string
{
    $cls = $full_only ? ' class="bas-full-row"' : '';
    return '<tr' . $cls . '>'
         . '<td class="nowrap"><strong>' . $field . '</strong> &mdash; ' . $desc . '</td>'
         . '<td>' . $widget . '</td>'
         . '<td style="font-size:0.85em;color:#555;">' . $how . '</td>'
         . '</tr>';
}

// ── Page ──────────────────────────────────────────────────────────────────────

llxHeader('', 'BAS & PAYG — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'BAS &amp; PAYG — Setup',
    -1,
    'accountancy'
);
?>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token"  value="<?php echo newToken(); ?>">

<?php // ── BAS Type ─────────────────────────────────────────────────────────── ?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead><tr class="liste_titre"><th colspan="2">BAS Type</th></tr></thead>
<tbody>
  <tr>
    <td style="width:11rem;"><strong>Reporting mode</strong></td>
    <td>
      <label style="margin-right:1.5rem;">
        <input type="radio" name="bas_type" value="simpler" <?=$bas_type==='simpler'?'checked':''?>>
        <strong>Simpler BAS</strong>
        <span style="color:#555;"> &mdash; G1, 1A, 1B, W1, W2, W5 (recommended for most small businesses)</span>
      </label>
      <br>
      <label>
        <input type="radio" name="bas_type" value="full" <?=$bas_type==='full'?'checked':''?>>
        <strong>Full GST reporting</strong>
        <span style="color:#555;"> &mdash; adds G2, G3, G10, G11, W3, W4</span>
      </label>
    </td>
  </tr>
</tbody>
</table>
</div>

<?php // ── GST Accounts ──────────────────────────────────────────────────────── ?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre"><th style="width:13rem;">BAS field</th><th>Account</th><th>How it's calculated</th></tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      GST &mdash; leave blank to calculate from invoice/payment totals
    </th>
  </tr>
</thead>
<tbody>
<?php
$w = fn($k,$v,$p) => $has_accounts ? bas_select_single($k,$accounts,$v) : bas_text_single($k,$v,$p);
$wm = fn($k,$v,$p) => $has_accounts ? bas_select_multi($k,$accounts,$v) : bas_text_multi($k,$v,$p);

echo bas_row('1A','GST collected (sales)',
    $w('bas_account_gst_collected',$cfg['gst_collected'],'e.g. 2-1100'),
    'Sum of <strong>CREDIT</strong> entries in the quarter'
);
echo bas_row('1B','GST credits / ITC (purchases)',
    $w('bas_account_gst_itc',$cfg['gst_itc'],'e.g. 1-3300'),
    'Sum of <strong>DEBIT</strong> entries in the quarter'
);
echo bas_row('G2','Export sales',
    $w('bas_account_g2',$cfg['g2'],'e.g. 4-9000'),
    'Sum of <strong>CREDIT</strong> entries — used to split G1 (Full GST only)',
    true
);
echo bas_row('G3','Other GST-free sales',
    $w('bas_account_g3',$cfg['g3'],'e.g. 4-9100'),
    'Sum of <strong>CREDIT</strong> entries — used to split G1 (Full GST only)',
    true
);
echo bas_row('G10','Capital purchases accounts',
    $wm('bas_accounts_g10',$cfg['g10'],'e.g. 1-6000,1-6100'),
    'Sum of <strong>DEBIT</strong> entries — split from G11 (Full GST only)',
    true
);
?>
</tbody>
</table>
</div>

<?php // ── PAYG Accounts ─────────────────────────────────────────────────────── ?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre"><th style="width:13rem;">BAS field</th><th>Account(s)</th><th>How it's calculated</th></tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      PAYG Withholding &mdash; leave blank to enter W1/W2 manually on the report page
    </th>
  </tr>
</thead>
<tbody>
<?php
echo bas_row('W1','Total wages &amp; salary paid',
    $wm('bas_accounts_wages',$cfg['wages'],'e.g. 6400.01,6400.02'),
    'Sum of <strong>DEBIT</strong> entries to these accounts<br>'
    .'<div style="font-size:0.8em;color:#888;margin-top:2px;">'.($has_accounts?'Hold Ctrl / Cmd for multiple':'Comma-separated').'</div>'
);
echo bas_row('W2','Withheld from wages (PAYG payable)',
    $w('bas_account_payg',$cfg['payg'],'e.g. 2111'),
    'Sum of <strong>CREDIT</strong> entries — payroll journal credits this when tax is withheld'
);
echo bas_row('W3','Other amounts withheld',
    $w('bas_account_w3',$cfg['w3'],'e.g. 2113'),
    'Sum of <strong>CREDIT</strong> entries in the quarter (Full only)',
    true
);
echo bas_row('W4','Withheld — no ABN quoted',
    $w('bas_account_w4',$cfg['w4'],'e.g. 2114'),
    'Sum of <strong>CREDIT</strong> entries in the quarter (Full only)',
    true
);
?>
<tr>
  <td><strong>W5</strong> &mdash; Total PAYG withheld</td>
  <td colspan="2" style="font-size:0.85em;color:#555;font-style:italic;">Calculated automatically: W2 + W3 + W4</td>
</tr>
</tbody>
</table>
</div>

<?php // ── Super ─────────────────────────────────────────────────────────────── ?>
<div class="div-table-responsive" style="max-width:800px;margin-bottom:2rem;">
<table class="noborder centpercent">
<thead>
  <tr class="liste_titre"><th style="width:13rem;">Field</th><th>Account(s)</th><th>How it's calculated</th></tr>
  <tr class="liste_titre_sel">
    <th colspan="3" style="font-weight:normal;font-style:italic;">
      Superannuation &mdash; informational only, not included in BAS totals
    </th>
  </tr>
</thead>
<tbody>
<?php
echo bas_row('Super','Superannuation expense',
    $wm('bas_accounts_super',$cfg['super'],'e.g. 6300.02'),
    'Sum of <strong>DEBIT</strong> entries in the quarter<br>'
    .'<div style="font-size:0.8em;color:#888;margin-top:2px;">'.($has_accounts?'Hold Ctrl / Cmd for multiple':'Comma-separated').'</div>'
);
?>
</tbody>
</table>
</div>

<div style="margin-bottom:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<?php if (!$has_accounts): ?>
<p style="color:#888;font-size:0.9em;">
  Tip: Set up your chart of accounts in Accounting to get searchable dropdowns instead of text fields.
</p>
<?php endif; ?>

<script>
function bas_update_visibility() {
    var full = $('input[name="bas_type"]:checked').val() === 'full';
    $('.bas-full-row').toggle(full);
}
$(document).ready(function () {
    bas_update_visibility();
    $('input[name="bas_type"]').on('change', bas_update_visibility);

    <?php if ($has_accounts): ?>
    $('.bas-single').select2({ width: '350px', allowClear: true, placeholder: '— not configured —' });
    $('.bas-multi').select2({ width: '350px', placeholder: '— select accounts —' });
    <?php endif; ?>
});
</script>

<?php
print dol_get_fiche_end();
llxFooter();
