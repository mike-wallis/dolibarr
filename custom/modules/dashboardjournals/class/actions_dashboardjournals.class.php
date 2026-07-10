<?php
/**
 * Dashboard Journals — hook handler.
 *
 * Dolibarr's home dashboard (htdocs/index.php) renders each module's workboard
 * as a flat, unlabelled list of tiles ("box-flex-item" divs), one per module,
 * in a fixed left-to-right order with no grouping. There's no core hook that
 * lets a module change that layout — only hooks that add more tiles or rename
 * an individual tile's group ('addOpenElementsDashboardLine' /
 * 'addOpenElementsDashboardGroup').
 *
 * To visually cluster related tiles into labelled "journal" boxes without
 * touching htdocs/index.php, this hook injects CSS + JS via the same
 * 'printCommonFooter' hook the stickynotes module uses. The JS runs after the
 * dashboard has rendered, finds each tile by its stable CSS class
 * (".bg-infobox-{key}", where {key} is Dolibarr's internal element name —
 * NOT translated text, so this works regardless of UI language), and MOVES
 * (not clones) those tile elements into a new wrapper div with a title and
 * optional note text above/below, right where the first tile of that group
 * used to sit. Tiles for inactive modules, or modules with nothing currently
 * to show (e.g. Bank Account only appears when there's something to
 * reconcile), simply won't exist in the DOM — the JS silently skips any key
 * it can't find, and skips an entire group if none of its tiles are present.
 *
 * Config (group titles, note text, show/hide toggles) is edited on this
 * module's setup page and stored as JSON in DASHBOARDJOURNALS_CONFIG_JSON.
 */
class ActionsDashboardjournals
{
    public $db;
    public $errors    = [];
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Fixed group definitions: which of Dolibarr's dashboard-group keys
     * (the CSS class suffix on each tile) belong to each journal, and which
     * real Dolibarr module gates that key (used to skip a group server-side
     * when every module backing it is switched off).
     */
    public static function groupDefinitions(): array
    {
        return [
            'sales' => [
                'tiles'  => ['propal', 'commande', 'facture'],
                'module' => ['propal', 'order', 'invoice'],
            ],
            'purchase' => [
                'tiles'  => ['supplier_proposal', 'order_supplier', 'invoice_supplier'],
                'module' => ['supplier_proposal', 'supplier_order', 'supplier_invoice'],
            ],
            'finance' => [
                'tiles'  => ['expensereport', 'bank_account'],
                'module' => ['expensereport', 'bank'],
                // Not a real dashboard tile — Accounting has no workboard entry.
                // Shown as a small linked box alongside Bank Account when the
                // Accounting module itself is active. menuId matches Dolibarr's
                // own top-menu item id (#mainmenutd_{menuId}) — the JS clones that
                // item's live markup/link rather than us reproducing its icon,
                // colours, and href by hand.
                'staticBox' => [
                    'label'  => 'Accounting →',
                    'href'   => 'accountancy',
                    'module' => ['accounting', 'comptabilite'],
                    'menuId' => 'accountancy',
                ],
            ],
        ];
    }

    /**
     * Human-readable label for each "slot" in a group, in the same order as
     * groupDefinitions()[$key]['tiles'], plus one extra trailing label for
     * the group's staticBox if it has one. Used both for the setup page's
     * per-tile note fields and to size the noteAbove/noteBelow arrays.
     */
    public static function tileLabels(): array
    {
        return [
            'sales'    => ['Proposals', 'Orders', 'Invoices'],
            'purchase' => ['Vendor Quotes', 'Purchase Orders', 'Vendor Invoices'],
            'finance'  => ['Expense Reports', 'Bank Account', 'Accounting'],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'enabled'      => true,
            'openInNewTab' => false,
            'groups'  => [
                'sales' => [
                    'title'      => 'Sales Journal',
                    'showAbove'  => true,
                    // Blank = use Dolibarr's own status labels automatically (see
                    // autoNoteAbove()). Only set here if you want to override that
                    // for a specific slot.
                    'noteAbove'  => ['', '', ''],
                    'showBelow'  => true,
                    'noteBelow'  => ['', 'Delivered (shipment: all or part)', 'Receive payment / credit note'],
                ],
                'purchase' => [
                    'title'      => 'Purchase Journal',
                    'showAbove'  => true,
                    'noteAbove'  => ['', '', ''],
                    'showBelow'  => false,
                    'noteBelow'  => ['', '', ''],
                ],
                'finance' => [
                    'title'      => 'Finance Journal',
                    'showAbove'  => false,
                    'noteAbove'  => ['', '', ''],
                    'showBelow'  => true,
                    'noteBelow'  => ['', '', 'Link journals to ledger — Sales, Purchases, Bank etc.'],
                ],
            ],
        ];
    }

    /**
     * Auto-computed "above" captions — reads Dolibarr's own translated status
     * labels (the same text shown on status badges throughout the app) via
     * each object's LibStatut(), instead of hand-typed English strings, so
     * these stay correct if the status wording ever changes or the UI
     * language isn't English. Used as the fallback for any slot the admin
     * hasn't overridden with their own text on the setup page (see
     * effectiveNoteAbove()).
     *
     * None of these LibStatut() methods are static (confirmed against
     * Dolibarr 23.0.3 core), so this instantiates a throwaway object per
     * class — cheap, no DB I/O happens until fetch()/create() is called.
     */
    public static function autoNoteAbove(): array
    {
        global $db, $langs;

        require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/supplier_proposal/class/supplier_proposal.class.php';
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/expensereport/class/expensereport.class.php';

        // Commande::LibStatut() doesn't self-load its lang file (unlike the
        // others below) — load everything up front so all calls are safe
        // regardless of what the current page has already loaded. ExpenseReport's
        // labels live in the (historically-named) 'trips' lang file, and its
        // LibStatut() doesn't self-load that either.
        $langs->loadLangs(['propal', 'orders', 'bills', 'supplier_proposal', 'trips']);

        $propal          = new Propal($db);
        $commande        = new Commande($db);
        $facture         = new Facture($db);
        $supplierProposal = new SupplierProposal($db);
        $commandeFourn   = new CommandeFournisseur($db);
        $factureFourn    = new FactureFournisseur($db);
        $expenseReport   = new ExpenseReport($db);

        // mode=1 on every call below = plain short text label, no HTML/picto
        // (Dolibarr's dolGetStatus() only returns bare text for mode 0 or 1).
        return [
            'sales' => [
                implode(' → ', [
                    $propal->LibStatut(Propal::STATUS_DRAFT, 1),
                    $propal->LibStatut(Propal::STATUS_VALIDATED, 1),
                    $propal->LibStatut(Propal::STATUS_SIGNED, 1),
                ]),
                implode(' → ', [
                    // Commande::LibStatut($status, $billed, $mode, $donotshowbilled)
                    $commande->LibStatut(Commande::STATUS_DRAFT, 0, 1, 1),
                    $commande->LibStatut(Commande::STATUS_VALIDATED, 0, 1, 1),
                    $commande->LibStatut(Commande::STATUS_SHIPMENTONPROCESS, 0, 1, 1),
                ]),
                implode(' → ', [
                    // Facture::LibStatut($paye, $status, $mode, $alreadypaid). Invoices have no
                    // distinct "Validated" label separate from payment state — "Not paid" IS
                    // Dolibarr's own label for a validated-but-unpaid invoice, so it stands in
                    // as the validated-equivalent state here. $alreadypaid must be passed as 0
                    // (not left default -1) to get "Not paid" rather than the partial-payment
                    // "Started" label.
                    $facture->LibStatut(0, Facture::STATUS_DRAFT, 1, 0),
                    $facture->LibStatut(0, Facture::STATUS_VALIDATED, 1, 0),
                    $facture->LibStatut(1, Facture::STATUS_VALIDATED, 1),
                ]),
            ],
            'purchase' => [
                implode(' → ', [
                    $supplierProposal->LibStatut(SupplierProposal::STATUS_DRAFT, 1),
                    $supplierProposal->LibStatut(SupplierProposal::STATUS_VALIDATED, 1),
                    $supplierProposal->LibStatut(SupplierProposal::STATUS_SIGNED, 1),
                ]),
                implode(' → ', [
                    $commandeFourn->LibStatut(CommandeFournisseur::STATUS_DRAFT, 1),
                    $commandeFourn->LibStatut(CommandeFournisseur::STATUS_VALIDATED, 1),
                    $commandeFourn->LibStatut(CommandeFournisseur::STATUS_RECEIVED_COMPLETELY, 1),
                ]),
                implode(' → ', [
                    $factureFourn->LibStatut(0, FactureFournisseur::STATUS_DRAFT, 1, 0),
                    $factureFourn->LibStatut(0, FactureFournisseur::STATUS_VALIDATED, 1, 0),
                    $factureFourn->LibStatut(1, FactureFournisseur::STATUS_VALIDATED, 1),
                ]),
            ],
            'finance' => [
                implode(' → ', [
                    // ExpenseReport::STATUS_CLOSED(=6) is the final "paid/reimbursed"
                    // state despite the generic constant name — confirmed against the
                    // class's own label map, not assumed from the name.
                    $expenseReport->LibStatut(ExpenseReport::STATUS_DRAFT, 1),
                    $expenseReport->LibStatut(ExpenseReport::STATUS_VALIDATED, 1),
                    $expenseReport->LibStatut(ExpenseReport::STATUS_CLOSED, 1),
                ]),
                // Bank Account / Accounting have no comparable Dolibarr workflow-status
                // sequence — leave blank; admins can still type their own text for these.
                '',
                '',
            ],
        ];
    }

    /**
     * Merges the auto-computed labels under any slot the admin has left blank
     * in $groupCfg['noteAbove'], and returns the effective per-slot array to
     * actually render for the given group key.
     */
    public static function effectiveNoteAbove(string $key, array $groupCfg, array $auto): array
    {
        $result = (array) ($groupCfg['noteAbove'] ?? []);
        $autoForGroup = $auto[$key] ?? [];
        foreach ($autoForGroup as $i => $autoText) {
            if (trim((string) ($result[$i] ?? '')) === '') {
                $result[$i] = $autoText;
            }
        }
        return $result;
    }

    public static function loadConfig(): array
    {
        $defaults = self::defaultConfig();
        $json     = getDolGlobalString('DASHBOARDJOURNALS_CONFIG_JSON');
        if (!$json) {
            return $defaults;
        }
        $saved = json_decode($json, true);
        if (!is_array($saved)) {
            return $defaults;
        }
        // Shallow-merge saved values over defaults so a partially-saved config
        // (or a config saved by an older version of this module) can't blow up.
        $config = $defaults;
        $config['enabled']      = $saved['enabled'] ?? $defaults['enabled'];
        $config['openInNewTab'] = $saved['openInNewTab'] ?? $defaults['openInNewTab'];
        foreach ($defaults['groups'] as $key => $groupDefaults) {
            if (isset($saved['groups'][$key]) && is_array($saved['groups'][$key])) {
                $config['groups'][$key] = array_merge($groupDefaults, $saved['groups'][$key]);
            }
            // noteAbove/noteBelow must be arrays (one entry per tile slot). A config
            // saved by the older per-group-string version of this module, or any
            // other malformed value, falls back to that group's default array here
            // rather than reaching the JS as a string (which would render as
            // individual characters instead of text).
            $slotCount = count(self::tileLabels()[$key] ?? []);
            foreach (['noteAbove', 'noteBelow'] as $field) {
                $val = $config['groups'][$key][$field] ?? null;
                if (!is_array($val) || count($val) !== $slotCount) {
                    $config['groups'][$key][$field] = $groupDefaults[$field];
                }
            }
        }
        return $config;
    }

    /**
     * Hook: printCommonFooter — called by llxFooter() at the bottom of every
     * full-page render. We only act on the actual home dashboard page.
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $user;

        if (empty($user->id)) {
            return 0;
        }
        if (!$this->isHomeDashboardPage()) {
            return 0;
        }

        $config = self::loadConfig();
        if (empty($config['enabled'])) {
            return 0;
        }

        $definitions = self::groupDefinitions();
        $autoAbove   = self::autoNoteAbove();
        $jsGroups    = [];

        foreach ($definitions as $key => $def) {
            // Skip entirely server-side if every module behind this group is off —
            // no point sending tile keys to the JS that could never be found.
            $anyModuleOn = false;
            foreach ($def['module'] as $modKey) {
                if (isModEnabled($modKey)) {
                    $anyModuleOn = true;
                    break;
                }
            }

            $staticBox = null;
            if (!empty($def['staticBox'])) {
                $sbModOn = false;
                foreach ($def['staticBox']['module'] as $modKey) {
                    if (isModEnabled($modKey)) {
                        $sbModOn = true;
                        break;
                    }
                }
                if ($sbModOn) {
                    $staticBox = [
                        'label'  => $def['staticBox']['label'],
                        'href'   => dol_buildpath('/' . $def['staticBox']['href'] . '/index.php', 1),
                        'menuId' => $def['staticBox']['menuId'] ?? null,
                    ];
                }
            }

            if (!$anyModuleOn && !$staticBox) {
                continue; // Nothing this group could ever show — skip it.
            }

            $groupCfg = $config['groups'][$key] ?? [];
            $jsGroups[] = [
                'key'       => $key,
                'tiles'     => $def['tiles'],
                'title'     => (string) ($groupCfg['title'] ?? ''),
                'showAbove' => !empty($groupCfg['showAbove']),
                'noteAbove' => array_map('strval', self::effectiveNoteAbove($key, $groupCfg, $autoAbove)),
                'showBelow' => !empty($groupCfg['showBelow']),
                'noteBelow' => array_map('strval', (array) ($groupCfg['noteBelow'] ?? [])),
                'staticBox' => $staticBox,
            ];
        }

        if (empty($jsGroups)) {
            return 0;
        }

        ob_start();
        $this->outputCss();
        $this->outputJs($jsGroups, !empty($config['openInNewTab']));
        $this->resprints = ob_get_clean();

        return 0;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * True only for the real home dashboard (htdocs/index.php), not any other
     * page in the app that happens to also be named index.php.
     */
    private function isHomeDashboardPage(): bool
    {
        if (empty($_SERVER['SCRIPT_FILENAME'])) {
            return false;
        }
        if (basename($_SERVER['SCRIPT_FILENAME']) !== 'index.php') {
            return false;
        }
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'])), '/');
        $rootDir   = rtrim(str_replace('\\', '/', DOL_DOCUMENT_ROOT), '/');
        return $scriptDir === $rootDir;
    }

    private function outputCss(): void
    {
        echo <<<CSS
<style id="dj-styles">
.dj-layout{
    display:grid;
    grid-template-columns: 1fr 380px;
    margin:6px 10px 14px;
}
.dj-group{
    position:relative;
    border:2px solid #cfd8e3;border-radius:10px;
    padding:5px;margin:6px 8px;
    background:rgba(148,163,184,0.06);
}
.dj-group-sales{grid-column:1;grid-row:1;}
.dj-group-purchase{grid-column:1;grid-row:2;}
.dj-group-finance{
    grid-column:2;grid-row:1 / span 2;
    display:flex;flex-direction:column;
    min-width:0; /* grid items default to min-width:auto, which can make a fixed-width
                    track overflow if inner content wants more room than the track — this
                    lets the 380px track above be the real, respected minimum. */
}
.dj-group-finance .dj-tiles-row{flex-direction:column;align-items:stretch;}
.dj-group-finance .dj-arrow{align-self:center;transform:rotate(90deg);}
.dj-group-title{
    font-weight:bold;font-size:13px;color:#c2703d;
    text-transform:uppercase;letter-spacing:.03em;
    margin-bottom:6px;
}
.dj-note{
    font-size:12px;color:#5b6b7c;font-style:italic;
    margin:4px 0;
}
.dj-tile-col{
    display:flex;flex-direction:column;align-items:center;
    flex-shrink:0;
}
.dj-tile-col .dj-note{
    font-size:11px;text-align:center;margin:2px 0;max-width:180px;
}
a.dj-note{
    color:#5b6b7c;text-decoration:none;cursor:pointer;
}
a.dj-note:hover{
    text-decoration:underline;color:#3b4a5a;
}
.dj-tiles-row{
    display:flex;flex-wrap:nowrap;align-items:center;gap:4px;
    overflow-x:auto;
}
.dj-arrow{
    font-size:20px;color:#93a3b5;padding:0 4px;flex-shrink:0;
}
.dj-static-box{
    display:inline-flex;align-items:center;justify-content:center;
    min-width:120px;min-height:60px;
    border:1px dashed #93a3b5;border-radius:6px;
    padding:8px 14px;margin:4px;
    font-size:13px;font-weight:600;color:#3b4a5a;
    text-decoration:none;background:#fff;
}
.dj-static-box:hover{background:#f3f6f9;}
.dj-static-box-menuclone{
    /* The real top-menu icon/label render white-on-transparent — their dark navy
       background is the whole navbar's colour, not a per-item box, so it has to
       be reproduced here to isolate the item the way it looks cropped out of
       the navbar. #263c5c and white text/icon colour were read directly off
       the live menu (getComputedStyle), not guessed. */
    display:inline-flex !important;flex-direction:column;align-items:center;
    background:#263c5c;border-radius:6px;padding:10px 16px;margin:4px;
    cursor:pointer;
}
.dj-static-box-menuclone,
.dj-static-box-menuclone *{
    color:#fff !important;
}
.dj-static-box-menuclone a{
    text-decoration:none;
}
@media (max-width:900px){
    .dj-layout{grid-template-columns:1fr;}
    .dj-group-finance{grid-row:auto;grid-column:1;}
    .dj-group-finance .dj-tiles-row{flex-direction:row;}
    .dj-group-finance .dj-arrow{transform:none;}
}
</style>
CSS;
    }

    private function outputJs(array $groups, bool $openNewTab): void
    {
        $groupsJson    = json_encode($groups);
        $openNewTabJson = json_encode($openNewTab);
        echo <<<JS
<script>
(function () {
    var djGroups = {$groupsJson};
    var djOpenNewTab = {$openNewTabJson};

    function djText(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        if (text) el.textContent = text;
        return el;
    }

    function djApplyNewTab(a) {
        if (djOpenNewTab) {
            a.target = '_blank';
            a.rel = 'noopener';
        }
    }

    // Builds a static box (e.g. "Accounting") to look and link exactly like
    // Dolibarr's own top-menu item, by cloning its live markup rather than
    // reproducing the icon/colours/href by hand — stays correct automatically
    // if the theme or the menu's link ever changes.
    function buildStaticBox(staticBox) {
        var menuLi = staticBox.menuId ? document.getElementById('mainmenutd_' + staticBox.menuId) : null;
        var center = menuLi ? menuLi.querySelector('.tmenucenter') : null;
        var box;
        if (center) {
            var clone = center.cloneNode(true);
            clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            clone.classList.add('dj-static-box-menuclone'); // layout only — visuals come from Dolibarr's own classes
            box = clone;
        } else {
            // Fallback if the menu item couldn't be found (e.g. Dolibarr theme change).
            var sb = document.createElement('a');
            sb.className = 'dj-static-box';
            sb.href = staticBox.href;
            sb.textContent = staticBox.label;
            box = sb;
        }
        box.querySelectorAll('a[href]').forEach(djApplyNewTab);
        if (box.tagName === 'A') djApplyNewTab(box);
        return box;
    }

    // Finds the tile's own "view list" link and strips its status filter, so a
    // caption can link to the general (unfiltered) list for that object type
    // rather than whichever specific status Dolibarr's tile happened to filter
    // by. Returns null if the tile has no such link (e.g. the static box).
    function tileListHref(tileEl) {
        if (!tileEl || !tileEl.querySelectorAll) return null;
        var links = tileEl.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href');
            if (href && href.indexOf('list.php') !== -1) {
                return href.replace(/([?&])search_status=[^&]*/, '$1').replace(/[&?]+$/, '');
            }
        }
        return null;
    }

    // Same as djText, but builds a clickable <a> instead of a plain <div> when
    // href is available (falls back to plain text if the tile has no list link).
    function djNote(className, text, href) {
        if (!href) return djText('div', className, text);
        var a = document.createElement('a');
        a.className = className;
        a.textContent = text;
        a.href = href;
        djApplyNewTab(a);
        return a;
    }

    // Wraps one tile (or the static box) plus its own above/below note text,
    // looked up by slot index — noteAbove/noteBelow are arrays with one entry
    // per tile in group.tiles, plus one trailing entry for the staticBox.
    function djTileCol(el, group, slotIndex) {
        var col = djText('div', 'dj-tile-col', null);
        var href = tileListHref(el);
        var above = group.showAbove && group.noteAbove && group.noteAbove[slotIndex];
        if (above) col.appendChild(djNote('dj-note dj-note-above', above, href));
        col.appendChild(el);
        var below = group.showBelow && group.noteBelow && group.noteBelow[slotIndex];
        if (below) col.appendChild(djNote('dj-note dj-note-below', below, href));
        return col;
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Pass 1: just find each group's tiles — don't move anything yet. Moving a
        // tile changes its parentNode, so the anchor used to position the whole
        // layout must be captured while every tile is still in its original spot.
        // Each found tile keeps its slot index (position within group.tiles) so
        // its note text can be looked up later even though missing tiles mean
        // "found" isn't the same length as group.tiles.
        var withTiles = [];
        djGroups.forEach(function (group) {
            var found = [];
            (group.tiles || []).forEach(function (key, slotIndex) {
                var span = document.querySelector('.bg-infobox-' + key);
                if (!span) return;
                var tile = span.closest('.box-flex-item');
                if (tile) found.push({ el: tile, slotIndex: slotIndex });
            });
            if (found.length === 0 && !group.staticBox) return; // nothing this group could show
            withTiles.push({ group: group, found: found });
        });
        if (withTiles.length === 0) return;

        // Anchor the whole combined layout where the first real (non-static-only)
        // tile currently sits, so it drops into place among Dolibarr's other tiles
        // rather than jumping to the end of the page.
        var anchorEntry = withTiles.filter(function (e) { return e.found.length > 0; })[0];
        if (!anchorEntry) return; // only static boxes and nothing real to anchor to — skip
        var anchor = anchorEntry.found[0].el;
        var parent = anchor.parentNode;
        if (!parent) return;

        var layout = djText('div', 'dj-layout', null);
        parent.insertBefore(layout, anchor); // while anchor is still a child of parent

        // Pass 2: now build each group box and move its tiles into it.
        withTiles.forEach(function (entry) {
            var group = entry.group, found = entry.found;

            var wrap = djText('div', 'dj-group dj-group-' + group.key, null);
            if (group.title) wrap.appendChild(djText('div', 'dj-group-title', group.title));

            var row = djText('div', 'dj-tiles-row', null);
            found.forEach(function (item, i) {
                if (i > 0) row.appendChild(djText('span', 'dj-arrow', '\\u2192'));
                row.appendChild(djTileCol(item.el, group, item.slotIndex)); // moves the existing tile node, doesn't clone it
            });
            if (group.staticBox) {
                if (found.length) row.appendChild(djText('span', 'dj-arrow', '\\u2192'));
                var sb = buildStaticBox(group.staticBox);
                // The static box is always the last slot, after every real tile.
                row.appendChild(djTileCol(sb, group, (group.tiles || []).length));
            }
            wrap.appendChild(row);

            layout.appendChild(wrap);
        });
    });
}());
</script>
JS;
    }
}
