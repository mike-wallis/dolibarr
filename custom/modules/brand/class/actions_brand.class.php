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
     * Called during invoice card rendering (view, presend, etc.).
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        if (strpos($parameters['context'], 'invoicecard') === false) {
            return 0;
        }
        if (empty($object->socid) || empty($this->brand_map)) {
            return 0;
        }

        $brand = $this->getBrandForThirdparty((int) $object->socid);
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
             . " WHERE cs.fk_societe = " . $socid
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
    function setBrandDefaults() {
        var \$model = \$('#model');
        if (\$model.length && \$model.val() !== '{$template}') {
            \$model.val('{$template}');
            if (\$model.data('select2')) \$model.trigger('change');
        }
        var \$from = \$('#frommail');
        if (\$from.length) {
            \$from.find('option').each(function () {
                if ($(this).val().indexOf('{$fromEmail}') !== -1) {
                    \$from.val($(this).val());
                    if (\$from.data('select2')) \$from.trigger('change');
                    return false;
                }
            });
        }
    }
    $(document).ready(function () {
        setBrandDefaults();
        setTimeout(setBrandDefaults, 300);
    });
}(jQuery));
</script>
SCRIPT;
    }
}
