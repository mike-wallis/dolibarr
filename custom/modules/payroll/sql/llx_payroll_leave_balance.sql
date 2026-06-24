-- Running leave balance per employee per leave type.
-- Annual leave and sick leave have balances; bereavement does not accrue.
-- One row per (fk_user, entity, leave_type) — updated on every pay run.

CREATE TABLE IF NOT EXISTS llx_payroll_leave_balance (
    rowid           INT              NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_user         INT              NOT NULL,
    entity          INT              NOT NULL DEFAULT 1,
    leave_type      ENUM('annual','sick') NOT NULL,
    balance_hours   DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    date_updated    DATETIME         NULL,
    UNIQUE KEY uk_leave_bal (fk_user, entity, leave_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
