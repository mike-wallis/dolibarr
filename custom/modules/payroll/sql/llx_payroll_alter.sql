-- Schema seed for addition deduction types.
-- The ALTER TABLE for is_super_applicable is handled in init() PHP code
-- (MySQL 9.x does not support ADD COLUMN IF NOT EXISTS).
-- INSERT IGNORE is idempotent: safe to run on every module enable.

-- Seed built-in addition types (commission, allowances, bonus).
-- deduction_class=addition: shown as positive additions to gross on the pay run form.
-- is_super_applicable: 1 = counts as Ordinary Time Earnings (attracts SGC super).
-- Verify each with your accountant or BAS agent.
-- account_credit is NULL for additions (paid directly to employee as part of net pay).
INSERT IGNORE INTO llx_payroll_deduction_type
    (code, label, deduction_class, calc_type, calc_value,
     account_debit, account_credit, is_mandatory, is_super_applicable, position, entity)
VALUES
    ('COMM',    'Commission',      'addition', 'manual', 0, '6400.10', NULL, 0, 1,  50, 1),
    ('CARALW',  'Car Allowance',   'addition', 'manual', 0, '6400.10', NULL, 0, 0,  60, 1),
    ('TOOLALW', 'Tool Allowance',  'addition', 'manual', 0, '6400.10', NULL, 0, 0,  70, 1),
    ('PHRALW',  'Phone Allowance', 'addition', 'manual', 0, '6400.10', NULL, 0, 0,  80, 1),
    ('BONUS',   'Bonus',           'addition', 'manual', 0, '6400.10', NULL, 0, 1,  90, 1),
    ('OTHRALW', 'Other Allowance', 'addition', 'manual', 0, '6400.10', NULL, 0, 0, 100, 1)
