<?php
/**
 * Invoice Line Columns — Dolibarr module descriptor.
 *
 * Registers the 'pdfgeneration' hook context so ActionsInvoicelines can correct
 * the GST/Price/Qty column content on the brightcs and southside invoice PDF
 * templates, and the brightcs_po purchase order PDF template. See
 * class/actions_invoicelines.class.php for the actual logic.
 *
 * Enable at: Setup > Modules/Applications > Invoice Line Columns
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modInvoicelines extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero       = 500012;
        $this->rights_class = 'invoicelines';
        $this->family       = 'other';
        $this->picto        = 'generic';
        $this->name         = 'Invoice Line Columns';
        $this->description  = 'Fixes the GST/Price/Qty column content and order on the BCS and SSS invoice PDF templates, and the BCS Purchase Order PDF template.';
        $this->version      = '1.0';
        $this->const_name   = 'MAIN_MODULE_INVOICELINES';
        $this->editor_name  = 'Dolibarr User Australia';
        $this->editor_url   = 'mailto:dolibarruseraustralia@gmail.com';

        $this->module_parts = [
            'hooks' => ['pdfgeneration'],
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
