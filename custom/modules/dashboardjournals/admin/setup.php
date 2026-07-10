<?php
/**
 * Dashboard Journals — module setup page.
 * Reached via the gear icon on the Dashboard Journals module card (Setup > Modules).
 */

$res = 0;
if (!$res && is_file('../../../main.inc.php'))   { require '../../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../../main.inc.php')) { require '../../../../main.inc.php'; $res = 1; }
if (!$res) { die('Cannot find main.inc.php'); }

require_once __DIR__ . '/../class/actions_dashboardjournals.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php'; // for dolibarr_set_const()

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// ── Load current config ──────────────────────────────────────────────────────

$config = ActionsDashboardjournals::loadConfig();

// ── Handle save ──────────────────────────────────────────────────────────────

if ($action === 'save') {
    $newConfig  = [
        'enabled'      => (GETPOST('enabled', 'alpha') === '1'),
        'openInNewTab' => (GETPOST('openInNewTab', 'alpha') === '1'),
        'groups'       => [],
    ];
    $tileLabels = ActionsDashboardjournals::tileLabels();

    foreach (array_keys(ActionsDashboardjournals::defaultConfig()['groups']) as $key) {
        $slotCount = count($tileLabels[$key]);
        $aboveRaw  = (array) ($_POST['noteAbove_' . $key] ?? []);
        $belowRaw  = (array) ($_POST['noteBelow_' . $key] ?? []);
        $noteAbove = [];
        $noteBelow = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $noteAbove[] = trim(strip_tags((string) ($aboveRaw[$i] ?? '')));
            $noteBelow[] = trim(strip_tags((string) ($belowRaw[$i] ?? '')));
        }
        $newConfig['groups'][$key] = [
            'title'     => trim(strip_tags(GETPOST('title_' . $key, 'alphanohtml'))),
            'showAbove' => (GETPOST('showAbove_' . $key, 'alpha') === '1'),
            'noteAbove' => $noteAbove,
            'showBelow' => (GETPOST('showBelow_' . $key, 'alpha') === '1'),
            'noteBelow' => $noteBelow,
        ];
    }

    dolibarr_set_const($db, 'DASHBOARDJOURNALS_CONFIG_JSON', json_encode($newConfig), 'chaine', 0, '', $conf->entity);
    $config = $newConfig;
    setEventMessages('Settings saved.', null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Page output ──────────────────────────────────────────────────────────────

llxHeader('', 'Dashboard Journals — Setup');
print dol_get_fiche_head(
    [['setup.php', 'Settings', 'settings']],
    'settings',
    'Dashboard Journals',
    -1,
    'generic'
);
?>

<p style="margin-bottom:1.5rem;">
  Groups the home dashboard's tiles into labelled Sales / Purchase / Finance journal boxes.
  Each tile can have its own small caption above and/or below it. <strong>Captions above
  a tile are read automatically from Dolibarr's own status labels</strong> (e.g. "Draft →
  Open → Signed") — leave a "Caption above" field blank to use that automatic text (shown
  as the field's placeholder), or type your own text to override it for that tile only.
  Captions below are plain free text, not automatic. Every caption links through to that
  tile's own list page in Dolibarr. A tile only appears if its module is active and
  currently has something to show — this page does not change that, it only controls the
  labels and captions drawn around whichever tiles Dolibarr already renders.
</p>

<form method="post" action="setup.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">

<div class="marginbottomonly">
  <label>
    <input type="checkbox" name="enabled" value="1" <?php echo !empty($config['enabled']) ? 'checked' : ''; ?>>
    <strong>Enable journal grouping on the home dashboard</strong>
  </label>
</div>
<div class="marginbottomonly">
  <label>
    <input type="checkbox" name="openInNewTab" value="1" <?php echo !empty($config['openInNewTab']) ? 'checked' : ''; ?>>
    Open caption links (and the Accounting box) in a new tab
  </label>
</div>

<?php
$labels = [
    'sales'    => 'Sales Journal (Proposals → Orders → Invoices)',
    'purchase' => 'Purchase Journal (Vendor Quotes → Purchase Orders → Vendor Invoices)',
    'finance'  => 'Finance Journal (Expense Reports, Bank Account → Accounting)',
];
$tileLabels = ActionsDashboardjournals::tileLabels();
$autoAbove  = ActionsDashboardjournals::autoNoteAbove();
foreach ($labels as $key => $sectionLabel):
    $g = $config['groups'][$key];
?>
<div class="div-table-responsive" style="margin-top:1.5rem;padding:1rem;border:1px solid #ddd;border-radius:6px;">
  <h4><?php echo $sectionLabel; ?></h4>
  <table class="noborder centpercent">
    <tr>
      <td class="titlefield">Group title</td>
      <td><input type="text" class="flat minwidth200" name="title_<?php echo $key; ?>"
                 value="<?php echo htmlspecialchars($g['title'], ENT_QUOTES); ?>"></td>
    </tr>
    <tr>
      <td class="titlefield">
        <label>
          <input type="checkbox" name="showAbove_<?php echo $key; ?>" value="1" <?php echo !empty($g['showAbove']) ? 'checked' : ''; ?>>
          Show captions above tiles
        </label>
      </td>
      <td>
        <label>
          <input type="checkbox" name="showBelow_<?php echo $key; ?>" value="1" <?php echo !empty($g['showBelow']) ? 'checked' : ''; ?>>
          Show captions below tiles
        </label>
      </td>
    </tr>
  </table>

  <table class="noborder centpercent" style="margin-top:0.75rem;">
    <thead>
      <tr class="liste_titre">
        <th>Tile</th>
        <th>Caption above</th>
        <th>Caption below</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tileLabels[$key] as $i => $tileLabel): ?>
      <tr>
        <td><?php echo htmlspecialchars($tileLabel, ENT_QUOTES); ?></td>
        <td><input type="text" class="flat centpercent" name="noteAbove_<?php echo $key; ?>[]"
                   placeholder="<?php echo htmlspecialchars('Auto: ' . ($autoAbove[$key][$i] ?? ''), ENT_QUOTES); ?>"
                   value="<?php echo htmlspecialchars($g['noteAbove'][$i] ?? '', ENT_QUOTES); ?>"></td>
        <td><input type="text" class="flat centpercent" name="noteBelow_<?php echo $key; ?>[]"
                   value="<?php echo htmlspecialchars($g['noteBelow'][$i] ?? '', ENT_QUOTES); ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<div class="center" style="margin-top:2rem;">
  <input type="submit" class="butAction" value="Save">
</div>
</form>

<?php
print dol_get_fiche_end();
llxFooter();
