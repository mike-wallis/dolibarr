<?php
/**
 * Searchbox — module setup page.
 * Choose which list pages have autocomplete enabled.
 */

$res = 0;
if (!$res && is_file('../../../main.inc.php'))   { require '../../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../../main.inc.php')) { require '../../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (!$user->admin) {
    accessforbidden();
}

$pages = [
    'SEARCHBOX_ENABLE_PRODUCTS'       => 'Products',
    'SEARCHBOX_ENABLE_SOCIETE'        => 'Companies / Third parties',
    'SEARCHBOX_ENABLE_CONTACT'        => 'Contacts',
    'SEARCHBOX_ENABLE_PROPAL'         => 'Sales quotes',
    'SEARCHBOX_ENABLE_COMMANDE'       => 'Sales orders',
    'SEARCHBOX_ENABLE_FACTURE'        => 'Customer invoices',
    'SEARCHBOX_ENABLE_FOURN_COMMANDE' => 'Purchase orders',
    'SEARCHBOX_ENABLE_FOURN_FACTURE'  => 'Supplier invoices',
    'SEARCHBOX_ENABLE_PROJET'         => 'Projects',
];

$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
    foreach ($pages as $const => $label) {
        $val = GETPOSTINT($const) ? '1' : '0';
        dolibarr_set_const($db, $const, $val, 'chaine', 0, '', $conf->entity);
    }
    setEventMessages('Settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

llxHeader('', 'Searchbox — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'Searchbox',
    -1,
    'search'
);
?>

<p style="margin-bottom:0.5rem;max-width:600px;">
  Tick the list pages where you want Google-style autocomplete suggestions.
  Typing 2 or more characters in the <em>Ref</em> or <em>Label</em> search box will show matching results
  in a dropdown — click one to go directly to that record.
</p>
<p style="margin-bottom:1.5rem;max-width:600px;color:#555;">
  Use <strong>+</strong> to search for multiple terms within the same field —
  e.g. <code>hard+floor+deg</code> returns records where the ref or label contains all three words.
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">

<table class="noborder" style="width:auto;min-width:400px;">
<thead>
  <tr class="liste_titre">
    <th style="width:280px;">List page</th>
    <th style="width:80px;text-align:center;">Enable</th>
  </tr>
</thead>
<tbody>
<?php foreach ($pages as $const => $label): ?>
  <tr class="oddeven">
    <td><?php echo htmlspecialchars($label); ?></td>
    <td style="text-align:center;">
      <input type="checkbox" name="<?php echo $const; ?>" value="1"
        <?php echo getDolGlobalString($const) ? 'checked' : ''; ?>>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="center" style="margin-top:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<?php
print dol_get_fiche_end();
llxFooter();
