<?php
/**
 * Brand Router — hook handler.
 *
 * Reads brand routing config from llx_const (BRAND_MAP_JSON), saved by admin/setup.php.
 * Falls back to built-in defaults if the config has never been saved.
 *
 * To extend to other document types: add the card context to modBrand->module_parts['hooks']
 * and add the relevant selectors in buildScript().
 */
class ActionsBrand
{
    public $db;
    public $errors    = [];
    public $resprints = '';

    /** Keyed by category label; populated in constructor from config or defaults. */
    private array $brand_map = [];

    public function __construct($db)
    {
        $this->db = $db;
        $this->loadBrandMap();
    }

    /**
     * Hook: formObjectOptions
     * Called during invoice and quote card rendering (view, presend, etc.).
     * Both doc types share the same template naming ('brightcs'/'southside'),
     * so the one 'invoice_template' config value drives both — no separate
     * "quote_template" setting needed.
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        if (strpos($parameters['context'], 'invoicecard') === false
            && strpos($parameters['context'], 'propalcard') === false) {
            return 0;
        }
        // For new invoices/quotes $object->socid is 0 — fall back to parameters or GET/POST
        $socid = (int) ($object->socid ?: ($parameters['socid'] ?? 0) ?: GETPOSTINT('socid'));
        if (empty($socid) || empty($this->brand_map)) {
            return 0;
        }

        $brand = $this->getBrandForThirdparty($socid);
        if (!$brand || !isset($this->brand_map[$brand])) {
            return 0;
        }

        $cfg      = $this->brand_map[$brand];
        $template = dol_escape_js($cfg['invoice_template'] ?? '');
        $fromMail = dol_escape_js($cfg['email_from'] ?? '');

        $this->resprints = $this->buildScript($template, $fromMail);

        return 0;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Loads brand_map from llx_const (BRAND_MAP_JSON).
     * Falls back to hardcoded defaults so the module works before setup is saved.
     */
    private function loadBrandMap(): void
    {
        $json = getDolGlobalString('BRAND_MAP_JSON');
        if ($json) {
            $rows = json_decode($json, true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!empty($row['category'])) {
                        $this->brand_map[$row['category']] = [
                            'invoice_template' => $row['invoice_template'] ?? '',
                            'email_from'       => $row['email_from'] ?? '',
                        ];
                    }
                }
                return;
            }
        }

        // Defaults — active until the admin saves the setup page for the first time
        $this->brand_map = [
            'SSS Customer' => ['invoice_template' => 'southside', 'email_from' => 'southsidesupplies.yes@gmail.com'],
            'BCS Customer' => ['invoice_template' => 'brightcs',  'email_from' => 'michaelw@brightcs.com.au'],
        ];
    }

    private function getBrandForThirdparty(int $socid): ?string
    {
        $labels = array_map(
            fn($l) => "'" . $this->db->escape($l) . "'",
            array_keys($this->brand_map)
        );

        $sql = "SELECT c.label"
             . " FROM " . MAIN_DB_PREFIX . "categorie c"
             . " JOIN " . MAIN_DB_PREFIX . "categorie_societe cs ON cs.fk_categorie = c.rowid"
             . " WHERE cs.fk_soc = " . $socid
             . " AND c.label IN (" . implode(',', $labels) . ")"
             . " LIMIT 1";

        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) > 0) {
            $row = $this->db->fetch_array($res);
            return $row['label'];
        }
        return null;
    }

    private function buildScript(string $template, string $fromEmail): string
    {
        return <<<SCRIPT
<script>
(function ($) {
    function setSelect(\$el, val) {
        // Set selected attribute directly so Select2 picks it up after its own init
        \$el.find('option').prop('selected', false);
        \$el.find('option[value="' + val + '"]').prop('selected', true);
        \$el.val(val);
        \$el.trigger('change');           // native + jQuery listeners
        \$el.trigger('change.select2');   // Select2 internal event
        \$el.trigger('chosen:updated');   // Chosen fallback
        if (\$el[0]) \$el[0].dispatchEvent(new Event('change', { bubbles: true }));
    }

    function applyBrandDefaults() {
        var \$model = \$('select[name="model"], #model').first();
        if (\$model.length && \$model.find('option[value="{$template}"]').length) {
            setSelect(\$model, '{$template}');
        }

        // From-email selector on the presend / send-by-email dialog.
        // Dolibarr renders this as select[name="fromtype"] with option values like
        // "senderprofile_1_1" — so match by option text, not value.
        var \$from = \$('select[name="fromtype"], .fromforsendingprofile').first();
        if (\$from.length) {
            \$from.find('option').each(function () {
                if ($(this).text().indexOf('{$fromEmail}') !== -1) {
                    setSelect(\$from, $(this).val());
                    return false;
                }
            });
        }
    }

    // Run after $(window).load so Select2 is fully initialised before we override.
    // Poll a few times to cover any late-loading widgets.
    $(window).on('load', function () {
        applyBrandDefaults();
        var tries = 0;
        var poll = setInterval(function () {
            applyBrandDefaults();
            if (++tries >= 5) clearInterval(poll);
        }, 300);
    });
}(jQuery));
</script>
SCRIPT;
    }
}
