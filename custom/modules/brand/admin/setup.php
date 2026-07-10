<?php
/**
 * Brand Router — module setup page.
 * Reached via the gear icon on the Brand module card (Setup > Modules).
 */

$res = 0;
if (!$res && is_file('../../../main.inc.php'))   { require '../../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../../main.inc.php')) { require '../../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php'; // for dolibarr_set_const()

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Load current config ──────────────────────────────────────────────────────

$configJson = getDolGlobalString('BRAND_MAP_JSON');
$brandRows  = ($configJson && is_array($tmp = json_decode($configJson, true))) ? $tmp : [
    ['category' => 'SSS Customer', 'invoice_template' => 'southside', 'email_from' => 'southsidesupplies.yes@gmail.com'],
    ['category' => 'BCS Customer', 'invoice_template' => 'brightcs',  'email_from' => 'michaelw@brightcs.com.au'],
];

// ── Handle save ──────────────────────────────────────────────────────────────

if ($action === 'save') {
    $newRows = [];
    foreach ((array) ($_POST['category'] ?? []) as $i => $cat) {
        $cat = trim(strip_tags($cat));
        if ($cat === '') {
            continue;
        }
        $newRows[] = [
            'category'         => $cat,
            'invoice_template' => trim(strip_tags($_POST['invoice_template'][$i] ?? '')),
            'email_from'       => trim(strip_tags($_POST['email_from'][$i] ?? '')),
        ];
    }
    dolibarr_set_const($db, 'BRAND_MAP_JSON', json_encode($newRows), 'chaine', 0, '', $conf->entity);
    $brandRows = $newRows;
    setEventMessages('Settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Data for dropdowns ───────────────────────────────────────────────────────

// Customer categories (type 2 = customer)
$cats = [];
$resql = $db->query("SELECT label FROM " . MAIN_DB_PREFIX . "categorie WHERE type = 2 ORDER BY label");
while ($resql && ($obj = $db->fetch_object($resql))) {
    $cats[] = $obj->label;
}

// Available invoice PDF templates (scan the doc directory)
$tpls = [];
foreach (glob(DOL_DOCUMENT_ROOT . '/core/modules/facture/doc/pdf_*.modules.php') ?: [] as $f) {
    $tpls[] = preg_replace('/^pdf_(.+)\.modules\.php$/', '$1', basename($f));
}
sort($tpls);

// ── Helpers ──────────────────────────────────────────────────────────────────

function brandOptHtml(array $opts, string $selected): string
{
    $h = '<option value="">— select —</option>';
    foreach ($opts as $o) {
        $sel = ($o === $selected) ? ' selected' : '';
        $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '"' . $sel . '>'
            . htmlspecialchars($o, ENT_QUOTES) . '</option>';
    }
    return $h;
}

// ── Page output ──────────────────────────────────────────────────────────────

llxHeader('', 'Brand Router — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'Brand Router',
    -1,
    'generic'
);
?>

<p style="margin-bottom:1.5rem;">
  Map each <strong>customer category</strong> to the PDF template and email From address used for that brand's invoices.
  The hook runs automatically — users never need to select manually.
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">

<div class="div-table-responsive">
<table class="noborder centpercent" id="brand-table">
<thead>
  <tr class="liste_titre">
    <th>Customer Category</th>
    <th>Invoice Template</th>
    <th>Email From</th>
    <th class="center" style="width:6rem;"></th>
  </tr>
</thead>
<tbody id="brand-rows">
<?php foreach ($brandRows as $row): ?>
<tr>
  <td>
    <select name="category[]" class="flat">
      <?php echo brandOptHtml($cats, $row['category'] ?? ''); ?>
    </select>
  </td>
  <td>
    <select name="invoice_template[]" class="flat">
      <?php echo brandOptHtml($tpls, $row['invoice_template'] ?? ''); ?>
    </select>
  </td>
  <td>
    <input type="email" name="email_from[]" class="flat minwidth200"
           value="<?php echo htmlspecialchars($row['email_from'] ?? '', ENT_QUOTES); ?>">
  </td>
  <td class="center">
    <a href="#" class="butActionDelete" onclick="this.closest('tr').remove();return false;">Delete</a>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div style="margin-top:0.75rem;">
  <a href="#" class="butAction" onclick="brandAddRow();return false;">+ Add brand</a>
</div>

<div class="center" style="margin-top:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<script>
var brandCats = <?php echo json_encode($cats); ?>;
var brandTpls = <?php echo json_encode($tpls); ?>;

function brandMakeSelect(name, opts) {
    var html = '<select name="' + name + '" class="flat"><option value="">— select —</option>';
    for (var i = 0; i < opts.length; i++) {
        html += '<option value="' + esc(opts[i]) + '">' + esc(opts[i]) + '</option>';
    }
    return html + '</select>';
}

function brandAddRow() {
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + brandMakeSelect('category[]', brandCats) + '</td>' +
        '<td>' + brandMakeSelect('invoice_template[]', brandTpls) + '</td>' +
        '<td><input type="email" name="email_from[]" class="flat minwidth200" value=""></td>' +
        '<td class="center"><a href="#" class="butActionDelete" ' +
            'onclick="this.closest(\'tr\').remove();return false;">Delete</a></td>';
    document.getElementById('brand-rows').appendChild(tr);
}

function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php
print dol_get_fiche_end();
llxFooter();
