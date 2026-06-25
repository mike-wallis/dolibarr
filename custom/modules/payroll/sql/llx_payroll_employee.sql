-- Per-employee payroll defaults for the Payroll module.
-- Stores position type, pay period, rate type, and tax settings.
-- One row per employee (fk_user), per entity.

CREATE TABLE IF NOT EXISTS llx_payroll_employee (
    rowid           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_user         INT          NOT NULL,
    position_type   VARCHAR(10)  NOT NULL DEFAULT 'CA',
    pay_period      VARCHAR(20)  NOT NULL DEFAULT 'weekly',
    pay_rate        DECIMAL(12,4)NOT NULL DEFAULT 0,
    pay_rate_type   VARCHAR(10)  NOT NULL DEFAULT 'hourly',
    std_hours       DECIMAL(8,2) NOT NULL DEFAULT 0,
    ot_rate1        DECIMAL(5,2) NOT NULL DEFAULT 1.50,
    ot_rate2        DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    tax_scale       VARCHAR(10)  NOT NULL DEFAULT 'scale2',
    has_hecs                TINYINT      NOT NULL DEFAULT 0,
    employment_start_date   DATE         NULL,
    super_fund_name         VARCHAR(255) NULL,
    super_fund_usi          VARCHAR(50)  NULL,
    super_fund_abn          VARCHAR(20)  NULL,
    super_member_number     VARCHAR(50)  NULL,
    entity                  INT          NOT NULL DEFAULT 1,
    UNIQUE KEY uk_payroll_emp (fk_user, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
