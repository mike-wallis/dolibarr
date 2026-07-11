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
                // Shown as a small linked card alongside Bank Account when the
                // Accounting module itself is active, built server-side the same
                // way as quickLinks below (icon via img_picto(), not DOM-cloned).
                'staticBox' => [
                    'label'  => 'Accounting →',
                    'url'    => '/accountancy/index.php?mainmenu=accountancy&leftmenu=',
                    'picto'  => 'accountancy',
                    'module' => ['accounting', 'comptabilite'],
                ],
                // Small one-line icon+label links shown above the group title.
                // Unlike the tiles/staticBox above, these have no equivalent
                // dashboard workboard entry or persistent top-menu item to find
                // in the DOM, so they're built server-side from Dolibarr's own
                // menu target/picto/permission (see eldy.lib.php's "Various
                // payment" and "Loans" entries) rather than cloned from a live
                // element. Each is only included if its module is enabled AND
                // the current user has read permission — printCommonFooter()
                // does that filtering before handing this list to the JS.
                'quickLinks' => [
                    [
                        'label'  => 'Misc. payments',
                        'url'    => '/compta/bank/various_payment/list.php?leftmenu=tax_various&mainmenu=billing',
                        'picto'  => 'payment',
                        'module' => 'bank',
                        'perm'   => ['banque', 'lire'],
                    ],
                    [
                        'label'  => 'Loans',
                        'url'    => '/loan/list.php?leftmenu=tax_loan&mainmenu=billing',
                        'picto'  => 'loan',
                        'module' => 'loan',
                        'perm'   => ['loan', 'read'],
                    ],
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
            'enabled'              => true,
            'openInNewTab'         => false,
            // Employees Management is a separately-toggleable bonus section (not
            // part of the sales/purchase/finance journal concept), built by
            // resolveEmployeesColumns() rather than the generic groupDefinitions()
            // loop — see printCommonFooter().
            'showEmployeesSection' => true,
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
        $config['enabled']              = $saved['enabled'] ?? $defaults['enabled'];
        $config['showEmployeesSection'] = $saved['showEmployeesSection'] ?? $defaults['showEmployeesSection'];
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
                        'label' => $def['staticBox']['label'],
                        'href'  => dol_buildpath($def['staticBox']['url'], 1),
                        'icon'  => img_picto('', $def['staticBox']['picto'], 'class="paddingright pictofixedwidth"'),
                    ];
                }
            }

            $quickLinks = [];
            foreach ((array) ($def['quickLinks'] ?? []) as $ql) {
                if ($resolved = $this->resolveLink($ql)) {
                    $quickLinks[] = $resolved;
                }
            }

            if (!$anyModuleOn && !$staticBox && !$quickLinks) {
                continue; // Nothing this group could ever show — skip it.
            }

            $groupCfg = $config['groups'][$key] ?? [];

            // The staticBox is always the trailing slot (one past the last real
            // tile) — its own noteBelow text now renders INSIDE the card (see
            // buildStaticBox() in outputJs()) instead of as a separate line
            // underneath it, so pull it out here rather than leaving it in the
            // noteBelow array for djTileCol() to render a second time.
            if ($staticBox) {
                $noteBelowAll = array_map('strval', (array) ($groupCfg['noteBelow'] ?? []));
                $staticBox['caption'] = $noteBelowAll[count($def['tiles'])] ?? '';
            }
            $jsGroups[] = [
                'key'        => $key,
                'tiles'      => $def['tiles'],
                'title'      => (string) ($groupCfg['title'] ?? ''),
                'showAbove'  => !empty($groupCfg['showAbove']),
                'noteAbove'  => array_map('strval', self::effectiveNoteAbove($key, $groupCfg, $autoAbove)),
                'showBelow'  => !empty($groupCfg['showBelow']),
                'noteBelow'  => array_map('strval', (array) ($groupCfg['noteBelow'] ?? [])),
                'staticBox'  => $staticBox,
                'quickLinks' => $quickLinks,
            ];
        }

        // Employees Management — a separately-toggleable bonus section, not part
        // of the sales/purchase/finance journal concept, so it isn't in
        // groupDefinitions(). Four fixed columns (Users & Groups | Employees +
        // Skills management | Leaves | Salaries + Pay Run); the "Leaves" tile is
        // a real Dolibarr dashboard tile (found via the same .bg-infobox-holiday
        // mechanism as every other group's tiles — see 'tiles' below), the rest
        // are built server-side like the finance group's quickLinks.
        if (!empty($config['showEmployeesSection'])) {
            $columns   = $this->resolveEmployeesColumns();
            $leavesOn  = isModEnabled('holiday');
            $hasLinks  = false;
            foreach ($columns as $col) {
                if ($col) {
                    $hasLinks = true;
                    break;
                }
            }
            if ($hasLinks || $leavesOn) {
                $jsGroups[] = [
                    'key'     => 'employees',
                    'title'   => 'Employees Management',
                    'tiles'   => $leavesOn ? ['holiday'] : [],
                    'columns' => $columns,
                    'tileCol' => 2, // 0-indexed — Leaves lands in the 3rd column
                ];
            }
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

    /**
     * Resolves one link definition (label/url/picto/module/perm/adminOnly) into
     * the {label, href, icon} shape the JS card-builder expects, or null if the
     * current user shouldn't see it — module off, missing permission, or
     * (for payroll links, which gate on admin like the rest of that module's
     * menu — see modPayroll.class.php) not an admin. Shared by the finance
     * group's quickLinks and resolveEmployeesColumns() below.
     */
    private function resolveLink(array $item): ?array
    {
        global $user;

        if (!empty($item['module']) && !isModEnabled($item['module'])) {
            return null;
        }
        if (!empty($item['adminOnly']) && empty($user->admin)) {
            return null;
        }
        if (!empty($item['perm']) && !$user->hasRight(...$item['perm'])) {
            return null;
        }
        return [
            'label' => $item['label'],
            'href'  => dol_buildpath($item['url'], 1),
            'icon'  => img_picto('', $item['picto'], 'class="paddingright pictofixedwidth"'),
        ];
    }

    /**
     * Four columns for the Employees Management section. Column 3 (index 2) is
     * deliberately left empty — it's reserved for the "Leaves" tile, a real
     * Dolibarr dashboard tile moved into place by the JS (see printCommonFooter()
     * and buildEmployeesGroup() in outputJs()), not a built card like the rest.
     * URLs/picto/perms match Dolibarr's own menu entries for these pages
     * (eldy.lib.php) exactly, so these look and behave like the native links.
     */
    private function resolveEmployeesColumns(): array
    {
        $defs = [
            [
                ['label' => 'Users & Groups', 'url' => '/user/home.php?leftmenu=users&mainmenu=home', 'picto' => 'user', 'module' => null, 'perm' => ['user', 'user', 'read']],
            ],
            [
                ['label' => 'Employees', 'url' => '/user/list.php?mainmenu=hrm&leftmenu=hrm&contextpage=employeelist', 'picto' => 'user', 'module' => 'hrm', 'perm' => ['user', 'user', 'read']],
                ['label' => 'Skills management', 'url' => '/hrm/skill_list.php?mainmenu=hrm&leftmenu=hrm_sm', 'picto' => 'shapes', 'module' => 'hrm', 'perm' => ['hrm', 'all', 'read']],
            ],
            [], // reserved for the Leaves tile
            [
                ['label' => 'Salaries', 'url' => '/salaries/list.php?leftmenu=tax_salary&mainmenu=billing', 'picto' => 'salary', 'module' => 'salaries', 'perm' => ['salaries', 'read']],
                ['label' => 'Pay Run', 'url' => '/custom/payroll/payrun.php?mainmenu=billing&leftmenu=payroll_run', 'picto' => 'fa-money-bill-wave', 'module' => 'payroll', 'adminOnly' => true],
            ],
        ];

        return array_map(function ($col) {
            $resolved = [];
            foreach ($col as $item) {
                if ($r = $this->resolveLink($item)) {
                    $resolved[] = $r;
                }
            }
            return $resolved;
        }, $defs);
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
.dj-group-employees{
    grid-column: 1 / -1; /* full width, below both journal columns above it */
}
.dj-emp-grid{
    display:grid;
    grid-template-columns: repeat(4, minmax(160px, 1fr));
    gap:8px;
}
.dj-emp-col{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    min-width:0; /* same overflow fix as .dj-group-finance — a column must stay
                    at its grid track width even when empty ("not collapse"),
                    and must not let a wide child (the Leaves tile) push it out. */
}
.dj-emp-col:nth-child(2){
    align-items:flex-start; /* Employees + Skills management — left-align so their
                                left edges line up instead of each being centered
                                independently (they're different widths). */
}
.dj-emp-col:nth-child(2) .dj-static-box{
    align-self:flex-start; /* .dj-static-box has its own align-self:center (set
                               for its other use in the finance quicklinks row),
                               which overrides the parent's align-items above —
                               this puts the left-alignment back for these two. */
}
.dj-group-title{
    font-weight:bold;font-size:13px;color:#c2703d;
    text-transform:uppercase;letter-spacing:.03em;
    margin-bottom:6px;
}
.dj-quicklinks{
    display:flex;flex-wrap:wrap;justify-content:center;gap:6px;
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
    /* Shaped like a real Dolibarr dashboard tile (.info-box): coloured icon
       panel on the left, title + caption stacked on the right. Used for the
       quickLinks row (Misc. payments / Loans) too, without a caption — see
       buildStaticBox() in outputJs(). align-self centers it when it's the
       last item in the finance column's stretched tile stack. */
    display:inline-flex;align-items:stretch;align-self:center;
    border:1px solid #cfd8e3;border-radius:6px;
    overflow:hidden;margin:4px;max-width:230px;
    text-decoration:none;background:#fff;
}
.dj-static-box:hover{background:#f3f6f9;}
.dj-static-box-icon{
    display:flex;align-items:center;justify-content:center;
    background:#263c5c; /* same navy as the top menu bar */
    padding:0 12px;flex-shrink:0;
}
.dj-static-box-icon .pictofixedwidth{width:18px;}
.dj-static-box-icon span{color:#fff !important;}
.dj-static-box-content{
    display:flex;flex-direction:column;justify-content:center;
    padding:6px 10px;min-width:0;
}
.dj-static-box-title{
    font-size:13px;font-weight:600;color:#3b4a5a;
}
.dj-static-box-caption{
    font-size:11px;color:#5b6b7c;font-style:italic;
    margin-top:2px;
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

    // Builds a static box (e.g. "Accounting") shaped like a real Dolibarr
    // dashboard tile (.info-box): a coloured icon panel on the left, title +
    // optional caption stacked on the right — rather than the plain pill used
    // for quickLinks, since this one is meant to read as a tile in its own
    // right at the end of the Finance Journal's tile row.
    function buildStaticBox(staticBox) {
        var box = document.createElement('a');
        box.className = 'dj-static-box';
        box.href = staticBox.href;

        var icon = djText('span', 'dj-static-box-icon', null);
        icon.innerHTML = staticBox.icon; // Dolibarr-rendered markup, not user input

        var content = djText('span', 'dj-static-box-content', null);
        content.appendChild(djText('span', 'dj-static-box-title', staticBox.label));
        if (staticBox.caption) {
            content.appendChild(djText('span', 'dj-static-box-caption', staticBox.caption));
        }

        box.appendChild(icon);
        box.appendChild(content);
        djApplyNewTab(box);
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

    // Builds the Employees Management section: a fixed 4-column grid (see
    // .dj-emp-grid), each column a vertical stack of icon+label cards (built
    // the same way as the finance group's quickLinks) — except group.tileCol,
    // which gets the "Leaves" tile moved into it instead, if found.
    function buildEmployeesGroup(group, found) {
        var wrap = djText('div', 'dj-group dj-group-employees', null);
        if (group.title) wrap.appendChild(djText('div', 'dj-group-title', group.title));

        var grid = djText('div', 'dj-emp-grid', null);
        (group.columns || []).forEach(function (colLinks, colIndex) {
            var colEl = djText('div', 'dj-emp-col', null);
            colLinks.forEach(function (link) {
                colEl.appendChild(buildStaticBox(link));
            });
            if (colIndex === group.tileCol && found.length) {
                colEl.appendChild(found[0].el); // moves the existing Leaves tile node, doesn't clone it
            }
            grid.appendChild(colEl);
        });
        wrap.appendChild(grid);
        return wrap;
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
            var hasColumnLinks = group.columns && group.columns.some(function (c) { return c.length; });
            if (found.length === 0 && !group.staticBox && !hasColumnLinks) return; // nothing this group could show
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

            if (group.columns) {
                layout.appendChild(buildEmployeesGroup(group, found));
                return;
            }

            var wrap = djText('div', 'dj-group dj-group-' + group.key, null);
            if (group.title) wrap.appendChild(djText('div', 'dj-group-title', group.title));
            if (group.quickLinks && group.quickLinks.length) {
                var qlRow = djText('div', 'dj-quicklinks', null);
                group.quickLinks.forEach(function (ql) {
                    qlRow.appendChild(buildStaticBox(ql)); // same icon+title card as "Accounting", no caption
                });
                wrap.appendChild(qlRow);
            }

            var row = djText('div', 'dj-tiles-row', null);
            found.forEach(function (item, i) {
                if (i > 0) row.appendChild(djText('span', 'dj-arrow', '\\u2192'));
                row.appendChild(djTileCol(item.el, group, item.slotIndex)); // moves the existing tile node, doesn't clone it
            });
            if (group.staticBox) {
                if (found.length) row.appendChild(djText('span', 'dj-arrow', '\\u2192'));
                // Builds its own title+caption internally (see buildStaticBox()) —
                // no djTileCol() wrapper needed, unlike the real tiles above.
                row.appendChild(buildStaticBox(group.staticBox));
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
