<?php
/**
 * Searchbox — Dolibarr module descriptor.
 *
 * Enable at: Setup > Modules/Applications > Searchbox
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modSearchbox extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero          = 500011;
        $this->rights_class    = 'searchbox';
        $this->family          = 'other';
        $this->picto           = 'search';
        $this->name            = 'Searchbox';
        $this->description     = 'Google-style autocomplete suggestions on Dolibarr list pages.';
        $this->version         = '1.0';
        $this->const_name      = 'MAIN_MODULE_SEARCHBOX';
        $this->editor_name     = 'Dolibarr User Australia';
        $this->editor_url      = 'mailto:dolibarruseraustralia@gmail.com';
        $this->config_page_url = ['setup.php@searchbox'];

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
