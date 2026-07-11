<?php
/**
 * Dashboard Journals — Dolibarr module descriptor.
 *
 * Visually groups the home dashboard's existing workboard tiles (Proposals,
 * Orders, Invoices, Supplier Orders, Supplier Invoices, Bank Account) into
 * labeled "Sales Journal" / "Purchase Journal" / "Finance Journal" boxes with
 * optional editable notes above/below, without touching any core files.
 *
 * Enable at: Setup > Modules/Applications > Dashboard Journals
 * Configure at: the module's setup page (gear icon on its module card)
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modDashboardjournals extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero       = 500013;
        $this->rights_class = 'dashboardjournals';
        $this->family       = 'other';
        $this->picto        = 'generic';
        $this->name         = 'Fancy Dashboard';
        $this->description  = 'Groups the home dashboard tiles into Sales/Purchase/Finance journal boxes with editable notes, plus an optional Employees Management section.';
        $this->version      = '1.2';
        $this->const_name   = 'MAIN_MODULE_DASHBOARDJOURNALS';
        $this->editor_name  = 'South Side Supplies';

        $this->config_page_url = ['setup.php@dashboardjournals'];

        $this->module_parts = [
            'hooks' => ['main'],
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
