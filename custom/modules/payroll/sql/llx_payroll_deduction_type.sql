-- Catalogue of deduction/contribution types for the Payroll module.
-- Configured in the module Setup page.
-- deduction_class: employee = reduces net pay, employer = paid on top (e.g. super)
-- calc_type: manual, percent_gross, percent_net, fixed
-- Pre-seeded with PAYG, SUPER, HECS, CS - edit/add via Setup page.

CREATE TABLE IF NOT EXISTS llx_payroll_deduction_type (
    rowid           INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)   NOT NULL,
    label           VARCHAR(100)  NOT NULL,
    deduction_class VARCHAR(20)   NOT NULL DEFAULT 'employee',
    calc_type       VARCHAR(20)   NOT NULL DEFAULT 'manual',
    calc_value      DECIMAL(10,4) NOT NULL DEFAULT 0,
    account_debit   VARCHAR(20)   DEFAULT NULL,
    account_credit  VARCHAR(20)   DEFAULT NULL,
    is_mandatory         TINYINT NOT NULL DEFAULT 0,
    is_super_applicable  TINYINT NOT NULL DEFAULT 0,
    position             INT     NOT NULL DEFAULT 10,
    active          TINYINT       NOT NULL DEFAULT 1,
    entity          INT           NOT NULL DEFAULT 1,
    UNIQUE KEY uk_payroll_ded_code (code, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed mandatory deduction types (only inserted if they don't already exist).
-- Accounts: 6400.10=Wages, 6450.02=Super expense, 2111=Super liability,
--           2112=PAYG liability, 1101=Bank (Macquarie).
-- is_mandatory=1: shown on every employee, 0: opt-in per employee.

INSERT IGNORE INTO llx_payroll_deduction_type
    (code, label, deduction_class, calc_type, calc_value, account_debit, account_credit, is_mandatory, position, entity)
VALUES
    ('PAYG',  'PAYG Withholding',      'employee', 'manual',        0,     '6400.10', '2112', 1, 10, 1),
    ('HECS',  'HECS/HELP Repayment',   'employee', 'hecs_auto',     0,     '6400.10', '2112', 0, 20, 1),
    ('SUPER', 'Super SGC',             'employer', 'percent_gross',  12.00, '6450.02', '2111', 1, 30, 1),
    ('CS',    'Child Support',         'employee', 'fixed',          0,     '6400.10', '2113', 0, 40, 1);
