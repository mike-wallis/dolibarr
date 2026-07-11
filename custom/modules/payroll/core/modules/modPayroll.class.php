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
        $this->name            = 'Australian Payroll';
        $this->description     = 'Australian pay run entry — PAYG/HECS/super/bank in one step. Requires the Salaries module (uses its classes to create net-pay bank entries). Works best alongside the HRM module (employee profiles and positions).';
        $this->version         = '2.3';
        $this->const_name      = 'MAIN_MODULE_PAYROLL';
        $this->editor_name     = 'South Side Supplies';
        $this->config_page_url = ['/custom/payroll/config.php?mainmenu=admintools'];
        $this->depends         = ['modSalaries'];  // uses Salary + PaymentSalary classes for net-pay bank entries
        // HRM module recommended but not hard-required

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

        // Pay Run History — completed pay runs list
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'Pay Run History',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_history',
            'url'      => '/custom/payroll/payruns.php?mainmenu=billing&leftmenu=payroll_history',
            'langs'    => '',
            'position' => 900,
            'enabled'  => '$conf->payroll->enabled',
            'perms'    => '$user->admin',
            'target'   => '',
            'user'     => 0,
        ];

        // TFN Manager — encrypted TFN admin for all payroll employees
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'TFN Manager',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_tfn',
            'url'      => '/custom/payroll/tfn.php?mainmenu=billing&leftmenu=payroll_tfn',
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

        // STP Export — YTD CSV for SSP file upload
        $this->menu[$r++] = [
            'fk_menu'  => 'fk_mainmenu=billing',
            'type'     => 'left',
            'titre'    => 'STP Export',
            'mainmenu' => 'billing',
            'leftmenu' => 'payroll_stp',
            'url'      => '/custom/payroll/stp_export.php?mainmenu=billing&leftmenu=payroll_stp',
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
            'llx_payroll_mla_params',       // MLA formula parameters (DB-driven, replaces hardcoded values)
            'llx_payroll_leave_balance',    // running leave balance per employee per type
            'llx_payroll_leave_transaction',// full audit ledger of all leave movements
            'llx_payroll_payrun_line',      // persisted pay run detail for payslips and YTD
            'llx_payroll_alter',
        ];
        foreach ($tables_to_create as $table) {
            $file = $sqldir . '/' . $table . '.sql';
            if (file_exists($file)) {
                $content = preg_replace('/^--[^\n]*$/m', '', file_get_contents($file));
                $queries = explode(';', $content);
                foreach ($queries as $q) {
                    $q = trim($q);
                    if ($q) {
                        $sql[] = $q;
                    }
                }
            }
        }

        // Schema migrations — done in PHP because MySQL 9.x lacks ADD COLUMN IF NOT EXISTS.
        // Only for columns NOT already in the CREATE TABLE statements above (used to
        // upgrade an existing install created before that column existed). A column
        // that's both in the CREATE TABLE and listed here fails with "Duplicate column
        // name" on a brand-new install: the information_schema check below runs before
        // any of the $sql[] statements have actually executed, so it sees the table as
        // not-yet-existing (cnt=0) and queues a redundant ALTER, which then collides
        // with the CREATE TABLE that already added the column moments later. Found
        // 2026-07-11 when activating on a fresh (live) database — see
        // docs/decisions/payroll-duplicate-column-fix.md.
        $migrations = [
            ['llx_payroll_employee',       'has_medicare_adj',      'TINYINT NOT NULL DEFAULT 0'],
            ['llx_payroll_employee',       'medicare_dependants',   'TINYINT NOT NULL DEFAULT 0'],
            ['llx_payroll_employee',       'std_weekly_hours',      'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER std_hours'],
            ['llx_payroll_employee',       'tfn_encrypted',         'VARCHAR(500) NULL'],
            ['llx_payroll_employee',       'pay_bsb',               'VARCHAR(10) NULL'],
            ['llx_payroll_employee',       'pay_account',           'VARCHAR(20) NULL'],
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

        // Widen llx_user_extrafields.super_usi from VARCHAR(30) to VARCHAR(50) if still narrow.
        $res_col = $this->db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'llx_user_extrafields'"
            . " AND COLUMN_NAME = 'super_usi'"
        );
        if ($res_col && $obj_col = $this->db->fetch_object($res_col)) {
            if ((int)$obj_col->len < 50) {
                $sql[] = "ALTER TABLE llx_user_extrafields MODIFY COLUMN super_usi VARCHAR(50) NULL";
            }
        }

        // Seed 2026-27 FY config if not yet present (dates known; min_wage TBC each July).
        $sql[] = "INSERT IGNORE INTO llx_payroll_fy_config"
               . " (fy, start_date, end_date, super_rate, hecs_system, min_wage, entity)"
               . " VALUES ('2026-27', '2026-07-01', '2027-06-30', 12.00, 'marginal', 0.00, 1)";

        // Backfill start/end dates for rows that predate this migration.
        foreach (['2024-25' => ['2024-07-01', '2025-06-30'],
                  '2025-26' => ['2025-07-01', '2026-06-30'],
                  '2026-27' => ['2026-07-01', '2027-06-30']] as $fy => [$sd, $ed]) {
            $sql[] = "UPDATE llx_payroll_fy_config"
                   . " SET start_date = '$sd', end_date = '$ed'"
                   . " WHERE fy = '$fy' AND (start_date IS NULL OR start_date = '0000-00-00')";
        }

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
