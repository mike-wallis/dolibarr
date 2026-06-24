<?php
/**
 * Pay Run module descriptor.
 * Installs 3 payroll tables and adds menu items for Pay Run and Setup.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modPayroll extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero       = 500006;
        $this->rights_class = 'payroll';
        $this->family       = 'hr';
        $this->picto        = 'salary';
        $this->name            = 'Payroll';
        $this->description     = 'Pay run entry — positions, pay periods, PAYG/HECS/super in one step.';
        $this->version         = '2.2';
        $this->const_name      = 'MAIN_MODULE_PAYROLL';
        $this->editor_name     = 'South Side Supplies';
        $this->config_page_url = ['/custom/payroll/config.php?mainmenu=admintools'];

        // SQL tables to create on module init
        $this->module_parts = [];

        $r = 0;

        // Pay Run — main pay entry page
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'Pay Run',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_run',
            'url'      => '/custom/payroll/payrun.php?mainmenu=billing&leftmenu=payroll_run',
            'langs'    => '',
            'position' => 900,
            'enabled'  => '$conf->payroll->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];

        // Payroll Employees — list with Edit Payroll Profile buttons
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'Payroll Employees',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_employees',
            'url'      => '/custom/payroll/employees.php?mainmenu=billing&leftmenu=payroll_employees',
            'langs'    => '',
            'position' => 901,
            'enabled'  => '$conf->payroll->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];

        // Payroll Setup — deduction types, accounts
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'Payroll Setup',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_setup',
            'url'      => '/custom/payroll/setup.php?mainmenu=billing&leftmenu=payroll_setup',
            'langs'    => '',
            'position' => 902,
            'enabled'  => '$conf->payroll->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];

        // Payroll Manual — staff-facing help page
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'Payroll Manual',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_manual',
            'url'      => '/custom/help/payroll.php?mainmenu=billing&leftmenu=payroll_manual',
            'langs'    => '',
            'position' => 903,
            'enabled'  => '$conf->payroll->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];
    }

    public function init($options = '')
    {
        $sql = [];

        // Read SQL files and execute them
        $sqldir = dol_buildpath('/custom/payroll/sql', 0);
        $tables_to_create = [
            'llx_payroll_employee',
            'llx_payroll_deduction_type',
            'llx_payroll_employee_deduction',
            'llx_payroll_fy_config',
            'llx_payroll_tax_coefficient',
            'llx_payroll_hecs_bracket',
            'llx_payroll_test_case',        // legacy — kept for backward compat
            'llx_payroll_test_withholding', // ATO Schedule 1 withholding amounts
            'llx_payroll_test_mla2',        // ATO MLA Scale 2 (dependants)
            'llx_payroll_test_mla6',        // ATO MLA Scale 6 (half Medicare, children)
            'llx_payroll_test_stsl',        // ATO Schedule 8 STSL/HECS total withholding
            'llx_payroll_alter',
        ];
        foreach ($tables_to_create as $table) {
            $file = $sqldir . '/' . $table . '.sql';
            if (file_exists($file)) {
                $queries = explode(';', file_get_contents($file));
                foreach ($queries as $q) {
                    $q = trim($q);
                    if ($q) {
                        $sql[] = $q;
                    }
                }
            }
        }

        // Schema migrations — done in PHP because MySQL 9.x lacks ADD COLUMN IF NOT EXISTS.
        $migrations = [
            ['llx_payroll_deduction_type', 'is_super_applicable',  'TINYINT NOT NULL DEFAULT 0'],
            ['llx_payroll_employee',       'has_medicare_adj',      'TINYINT NOT NULL DEFAULT 0'],
            ['llx_payroll_employee',       'medicare_dependants',   'TINYINT NOT NULL DEFAULT 0'],
        ];
        foreach ($migrations as [$table, $col, $def]) {
            $res = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = '" . $table . "'"
                . " AND COLUMN_NAME = '" . $col . "'"
            );
            if ($res && $obj = $this->db->fetch_object($res)) {
                if (!$obj->cnt) {
                    $sql[] = "ALTER TABLE $table ADD COLUMN $col $def";
                }
            }
        }

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
