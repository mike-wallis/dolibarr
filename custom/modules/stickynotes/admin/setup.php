<?php
/**
 * Sticky Notes — module setup page.
 * Configure note background colours.
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

$cp = getDolGlobalString('STICKYNOTES_COLOR_PRIVATE') ?: '#fef9c3';
$cu = getDolGlobalString('STICKYNOTES_COLOR_PUBLIC')  ?: '#bbf7d0';

if ($action === 'save') {
    $new_cp = GETPOST('color_private', 'alphanohtml');
    $new_cu = GETPOST('color_public',  'alphanohtml');

    // Accept only valid hex colors
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $new_cp)) {
        dolibarr_set_const($db, 'STICKYNOTES_COLOR_PRIVATE', $new_cp, 'chaine', 0, '', $conf->entity);
        $cp = $new_cp;
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $new_cu)) {
        dolibarr_set_const($db, 'STICKYNOTES_COLOR_PUBLIC', $new_cu, 'chaine', 0, '', $conf->entity);
        $cu = $new_cu;
    }

    setEventMessages('Settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

llxHeader('', 'Sticky Notes — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'Sticky Notes',
    -1,
    'note'
);
?>

<p style="margin-bottom:1.5rem;max-width:600px;">
  Configure the background colour for each note type.
  Changes take effect immediately on the next page load.
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">

<table class="noborder" style="width:auto;min-width:480px;">
<thead>
  <tr class="liste_titre">
    <th style="width:220px;">Note type</th>
    <th style="width:120px;">Colour</th>
    <th style="width:120px;">Preview</th>
  </tr>
</thead>
<tbody>
  <tr class="oddeven">
    <td><strong>Private</strong> — only visible to you</td>
    <td><input type="color" name="color_private" value="<?php echo htmlspecialchars($cp, ENT_QUOTES); ?>"></td>
    <td>
      <div id="prev-private" style="width:80px;height:32px;border-radius:4px;border:1px solid rgba(0,0,0,.15);background:<?php echo htmlspecialchars($cp); ?>;"></div>
    </td>
  </tr>
  <tr class="oddeven">
    <td><strong>Public</strong> — visible to all users</td>
    <td><input type="color" name="color_public" value="<?php echo htmlspecialchars($cu, ENT_QUOTES); ?>"></td>
    <td>
      <div id="prev-public" style="width:80px;height:32px;border-radius:4px;border:1px solid rgba(0,0,0,.15);background:<?php echo htmlspecialchars($cu); ?>;"></div>
    </td>
  </tr>
</tbody>
</table>

<div class="center" style="margin-top:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<script>
document.querySelector('input[name="color_private"]').addEventListener('input', function () {
    document.getElementById('prev-private').style.background = this.value;
});
document.querySelector('input[name="color_public"]').addEventListener('input', function () {
    document.getElementById('prev-public').style.background = this.value;
});
</script>

<?php
print dol_get_fiche_end();
llxFooter();
