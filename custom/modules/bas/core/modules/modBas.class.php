<?php
/**
 * BAS & PAYG — Dolibarr module descriptor.
 * Adds "BAS & PAYG" entry under the Accounting left menu.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modBas extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero      = 500002;
        $this->rights_class = 'bas';
        $this->family      = 'financial';
        $this->picto       = 'accountancy';
        $this->name        = 'Bas';
        $this->description = 'Australian BAS and PAYG Withholding report. Calculates GST from Dolibarr payments; manual PAYG entry saved per quarter.';
        $this->version     = '1.0';
        $this->const_name  = 'MAIN_MODULE_BAS';
        $this->editor_name  = 'Dolibarr User Australia';
        $this->editor_url   = 'mailto:dolibarruseraustralia@gmail.com';
        $this->config_page_url = ['setup.php@bas'];

        $r = 0;
        $this->menu[$r] = [
            'fk_menu'  => 'fk_mainmenu=accountancy',
            'type'     => 'left',
            'titre'    => 'BAS &amp; PAYG',
            'mainmenu' => 'accountancy',
            'leftmenu' => 'bas_report',
            'url'      => '/custom/bas/report.php?mainmenu=accountancy&leftmenu=bas_report',
            'langs'    => '',
            'position' => 900,
            'enabled'  => '$conf->bas->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];
    }

    public function init($options = '')
    {
        $sql = [];
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = [];
        return $this->_remove($sql, $options);
    }
}
