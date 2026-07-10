<?php
/**
 * Brand Router — Dolibarr module descriptor.
 *
 * Registers the hook contexts this module responds to.
 * Enable/disable at: Setup > Modules/Applications > Brand
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modBrand extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero       = 500001;
        $this->rights_class = 'brand';
        $this->family       = 'crm';
        $this->picto        = 'generic';
        $this->name         = 'Brand';
        $this->description  = 'Auto-selects PDF template and email From address based on customer brand category, on invoice and quote cards.';
        $this->version      = '1.1';
        $this->const_name   = 'MAIN_MODULE_BRAND';
        $this->editor_name  = 'South Side Supplies';

        $this->config_page_url = ['setup.php@brand'];

        // Add more card contexts here as needed, e.g. 'supplierordercard'
        $this->module_parts = [
            'hooks' => ['invoicecard', 'propalcard'],
        ];
    }

    public function init($options = '')
    {
        return $this->_init([], $options);
    }

    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
