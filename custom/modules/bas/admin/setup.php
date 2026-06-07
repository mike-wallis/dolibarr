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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

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

function bas_select_disabled(array $accounts, string $selected): string
{
    $h  = '<select class="flat" style="min-width:320px;opacity:0.8;cursor:default;" disabled>';
    $h .= '<option value="">— not configured —</option>';
    foreach ($accounts as $acc) {
        if (!isset($acc->account_number)) continue;
        $sel = ($acc->account_number === $selected) ? ' selected' : '';
        $h  .= '<option value="' . htmlspecialchars($acc->account_number, ENT_QUOTES) . '"' . $sel . '>'
             . htmlspecialchars($acc->account_number . ' — ' . $acc->label, ENT_QUOTES)
             . '</option>';
    }
    return $h . '</select>';
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

<div style="margin-bottom:1.25rem;padding:0.75rem 1rem;background:#f0f4ff;border-left:4px solid #5b7fd4;border-radius:3px;max-width:820px;">
  <strong>To run the BAS report:</strong>
  click <strong>Accounting</strong> in the top menu &rarr; <strong>BAS &amp; PAYG</strong> in the left sidebar.
  &nbsp;&nbsp;
  <a href="<?php echo DOL_URL_ROOT; ?>/custom/bas/report.php?mainmenu=accountancy&leftmenu=bas_report">
    Go to report &rarr;
  </a>
</div>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token"  value="<?php echo newToken(); ?>">

<div class="div-table-responsive" style="max-width:820px;">
<table class="noborder centpercent">

<?php
$w  = fn($k,$v,$p) => $has_accounts ? bas_select_single($k,$accounts,$v) : bas_text_single($k,$v,$p);
$wm = fn($k,$v,$p) => $has_accounts ? bas_select_multi($k,$accounts,$v)  : bas_text_multi($k,$v,$p);
?>

<!-- BAS Type -->
<tr class="liste_titre"><th colspan="3">BAS Type</th></tr>
<tr>
  <td style="width:11rem;"><strong>Reporting mode</strong></td>
  <td colspan="2">
    <label style="display:block;margin-bottom:0.4rem;">
      <input type="radio" name="bas_type" value="simpler" <?=$bas_type==='simpler'?'checked':''?>>
      <strong>Simpler BAS</strong>
      <span style="color:#555;"> &mdash; G1, 1A, 1B, W1, W2, W5 &nbsp;(recommended for most small businesses)</span>
    </label>
    <label style="display:block;">
      <input type="radio" name="bas_type" value="full" <?=$bas_type==='full'?'checked':''?>>
      <strong>Full GST reporting</strong>
      <span style="color:#555;"> &mdash; adds G2, G3, G10, G11, W3, W4</span>
    </label>
  </td>
</tr>

<!-- GST — Accrual basis box -->
<tr>
  <td colspan="3" style="padding:1rem 0 0.5rem;">
    <div id="bas-accrual-box" style="border:2px solid #5b7fd4;border-radius:6px;padding:1rem 1.25rem;background:#f8faff;transition:box-shadow 0.3s;">
      <div style="font-weight:bold;font-size:1rem;color:#3a5dbf;margin-bottom:0.35rem;">GST calculation — Accrual basis</div>
      <div style="font-size:0.875em;color:#555;margin-bottom:0.85rem;">
        All fields are read at <strong>invoice date</strong> — income and expenses are recognised
        when the invoice is raised, regardless of whether it has been paid.
        Requires journals to be transferred to the ledger
        (<em>Accounting &rarr; Journals &rarr; Transfer to ledger</em>).
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="font-size:0.8em;color:#888;">
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0.5rem 0.3rem 0;width:11rem;">Field</th>
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0.5rem;">Source</th>
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0;">How it's calculated</th>
          </tr>
        </thead>
        <tbody>
          <tr style="font-size:0.875em;border-top:1px solid #d0d8f0;">
            <td style="padding:0.35rem 0.5rem 0.35rem 0;vertical-align:top;color:#444;"><strong>G1 &amp; 1A</strong><br><span style="font-weight:normal;color:#666;">Sales</span></td>
            <td style="padding:0.35rem 0.5rem;vertical-align:top;">
              Validated <strong>customer invoices</strong>, by invoice date<br>
              <span style="color:#777;">(Billing &rarr; Customer Invoices)</span>
            </td>
            <td style="padding:0.35rem 0;vertical-align:top;color:#555;">
              G1 = sum of invoice totals (inc. GST)<br>
              <table style="margin-top:0.3rem;border-collapse:collapse;width:auto;">
                <tr>
                  <td style="white-space:nowrap;vertical-align:middle;padding-right:0.5rem;">1A = sum of <strong>CREDIT</strong> entries to:</td>
                  <td style="vertical-align:middle;"><?= $w('bas_account_gst_collected', $cfg['gst_collected'], 'e.g. 2-1100') ?></td>
                </tr>
              </table>
            </td>
          </tr>
          <tr style="font-size:0.875em;border-top:1px solid #d0d8f0;">
            <td style="padding:0.35rem 0.5rem 0.35rem 0;vertical-align:top;color:#444;"><strong>G11 &amp; 1B</strong><br><span style="font-weight:normal;color:#666;">Purchases</span></td>
            <td style="padding:0.35rem 0.5rem;vertical-align:top;">
              Validated <strong>supplier invoices</strong>, by invoice date<br>
              <span style="color:#777;">(Billing &rarr; Supplier Invoices)</span>
            </td>
            <td style="padding:0.35rem 0;vertical-align:top;color:#555;">
              G11 = sum of invoice totals (inc. GST)<br>
              <table style="margin-top:0.3rem;border-collapse:collapse;width:auto;">
                <tr>
                  <td style="white-space:nowrap;vertical-align:middle;padding-right:0.5rem;">1B = sum of <strong>DEBIT</strong> entries to:</td>
                  <td style="vertical-align:middle;"><?= $w('bas_account_gst_itc', $cfg['gst_itc'], 'e.g. 1-3300') ?></td>
                </tr>
              </table>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </td>
</tr>

<!-- GST — Cash basis box -->
<tr>
  <td colspan="3" style="padding:0.5rem 0 0.75rem;">
    <div style="border:2px solid #5da85b;border-radius:6px;padding:1rem 1.25rem;background:#f6fff6;">
      <div style="font-weight:bold;font-size:1rem;color:#2e7d32;margin-bottom:0.35rem;">GST calculation — Cash basis</div>
      <div style="font-size:0.875em;color:#555;margin-bottom:0.85rem;">
        G1 (total sales) and G11 (total purchases) are drawn from payment records — income and expenses
        are recognised when money changes hands. 1A and 1B are still read from the accounting journal
        using the same accounts configured above, so the journal must be kept up to date.
        Select <strong>Cash</strong> on the report page to use this mode.
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:0.75rem;border-top:1px solid #b8ddb8;">
        <thead>
          <tr style="font-size:0.8em;color:#888;">
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0.5rem 0.3rem 0;width:11rem;">Field</th>
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0.5rem;">Source</th>
            <th style="font-weight:normal;text-align:left;padding:0.3rem 0;">How GST is calculated</th>
          </tr>
        </thead>
        <tbody style="font-size:0.875em;">
          <tr>
            <td style="padding:0.35rem 0.5rem 0.35rem 0;vertical-align:top;color:#444;"><strong>G1 &amp; 1A</strong><br><span style="font-weight:normal;color:#666;">Sales</span></td>
            <td style="padding:0.35rem 0.5rem;vertical-align:top;">
              Customer invoice <strong>payments received</strong><br>
              <span style="color:#777;">(Billing &rarr; Customer Invoices &rarr; Record Payment)</span>
            </td>
            <td style="padding:0.35rem 0;vertical-align:top;color:#555;">
              1A = payment amount &times; (invoice GST &divide; invoice total)
            </td>
          </tr>
          <tr style="border-top:1px solid #d8ecd8;">
            <td style="padding:0.35rem 0.5rem 0.35rem 0;vertical-align:top;color:#444;"><strong>G11 &amp; 1B</strong><br><span style="font-weight:normal;color:#666;">Purchases</span></td>
            <td style="padding:0.35rem 0.5rem;vertical-align:top;">
              Supplier invoice <strong>payments made</strong><br>
              <span style="color:#777;">(Billing &rarr; Supplier Invoices &rarr; Record Payment)</span>
            </td>
            <td style="padding:0.35rem 0;vertical-align:top;color:#555;">
              1B = payment amount &times; (invoice GST &divide; invoice total)
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </td>
</tr>

<!-- Full GST additional fields (hidden unless Full GST mode selected) -->
<tr class="bas-full-row liste_titre">
  <th style="width:11rem;">BAS field</th><th>Account</th><th>How it's calculated</th>
</tr>
<tr class="bas-full-row liste_titre_sel">
  <th colspan="3" style="font-weight:normal;font-style:italic;">
    Full GST reporting &mdash; additional fields (accrual, from ledger)
  </th>
</tr>
<?php
echo bas_row('G2', 'Export sales',
    $w('bas_account_g2', $cfg['g2'], 'e.g. 4-9000'),
    'Sum of <strong>CREDIT</strong> entries &mdash; subset of G1 (Full only)', true);
echo bas_row('G3', 'Other GST-free sales',
    $w('bas_account_g3', $cfg['g3'], 'e.g. 4-9100'),
    'Sum of <strong>CREDIT</strong> entries &mdash; subset of G1 (Full only)', true);
echo bas_row('G10', 'Capital purchases',
    $wm('bas_accounts_g10', $cfg['g10'], 'e.g. 1-6000,1-6100'),
    'Sum of <strong>DEBIT</strong> entries &mdash; split from G11 (Full only)', true);
?>

<!-- PAYG -->
<tr class="liste_titre">
  <th>BAS field</th><th>Account(s)</th><th>How it's calculated</th>
</tr>
<tr class="liste_titre_sel">
  <th colspan="3" style="font-weight:normal;font-style:italic;">
    PAYG Withholding &mdash; leave blank to enter W1/W2 manually on the report page
  </th>
</tr>
<?php
echo bas_row('W1', 'Total wages &amp; salary paid',
    $wm('bas_accounts_wages', $cfg['wages'], 'e.g. 6400.01,6400.02'),
    'Sum of <strong>DEBIT</strong> entries'.($has_accounts ? '<br><small style="color:#888;">Ctrl/Cmd to select multiple</small>' : ''));
echo bas_row('W2', 'Withheld from wages (PAYG payable)',
    $w('bas_account_payg', $cfg['payg'], 'e.g. 2111'),
    'Sum of <strong>CREDIT</strong> entries &mdash; payroll credits this when tax is withheld');
echo bas_row('W3', 'Other amounts withheld',
    $w('bas_account_w3', $cfg['w3'], 'e.g. 2113'),
    'Sum of <strong>CREDIT</strong> entries (Full only)', true);
echo bas_row('W4', 'Withheld &mdash; no ABN quoted',
    $w('bas_account_w4', $cfg['w4'], 'e.g. 2114'),
    'Sum of <strong>CREDIT</strong> entries (Full only)', true);
?>
<tr>
  <td><strong>W5</strong> &mdash; Total PAYG withheld</td>
  <td colspan="2" style="color:#777;font-style:italic;">Calculated: W2 + W3 + W4</td>
</tr>

<!-- Super -->
<tr class="liste_titre">
  <th>Field</th><th>Account(s)</th><th>How it's calculated</th>
</tr>
<tr class="liste_titre_sel">
  <th colspan="3" style="font-weight:normal;font-style:italic;">
    Superannuation &mdash; informational only, not included in BAS totals
  </th>
</tr>
<?php
echo bas_row('Super', 'Superannuation expense',
    $wm('bas_accounts_super', $cfg['super'], 'e.g. 6300.02'),
    'Sum of <strong>DEBIT</strong> entries'.($has_accounts ? '<br><small style="color:#888;">Ctrl/Cmd to select multiple</small>' : ''));
?>

</table>
</div>

<div style="margin-top:1rem;margin-bottom:1rem;">
  <input type="submit" class="butAction" value="Save">
  <?php if (!$has_accounts): ?>
  <span style="margin-left:1rem;font-size:0.85em;color:#888;">
    Tip: set up a chart of accounts in Accounting to get searchable dropdowns.
  </span>
  <?php endif; ?>
</div>
</form>

<script>
function bas_focus_accrual(fieldName) {
    var $box = $('#bas-accrual-box');
    $('html, body').animate({ scrollTop: $box.offset().top - 80 }, 300);
    $box.css('box-shadow', '0 0 0 4px rgba(91,127,212,0.5)');
    setTimeout(function () { $box.css('box-shadow', ''); }, 1800);
    var $sel = $('select[name="' + fieldName + '"]');
    if ($sel.length) setTimeout(function () { $sel.focus(); }, 350);
}
function bas_update_visibility() {
    var full = $('input[name="bas_type"]:checked').val() === 'full';
    $('.bas-full-row').toggle(full);
}
$(document).ready(function () {
    bas_update_visibility();
    $('input[name="bas_type"]').on('change', bas_update_visibility);
    <?php if ($has_accounts): ?>
    $('.bas-single').select2({ width: '100%', allowClear: true, placeholder: '— not configured —' });
    $('.bas-multi').select2({ width: '100%', placeholder: '— select accounts —' });
    <?php endif; ?>
});
</script>

<?php
print dol_get_fiche_end();
llxFooter();
