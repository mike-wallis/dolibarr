-- Per-employee deduction assignments.
-- Only needed for non-mandatory deductions (HECS, CS, custom).
-- Mandatory deductions (PAYG, SUPER) always appear regardless.
-- rate_override / amount_override: NULL = use the deduction type default.

CREATE TABLE IF NOT EXISTS llx_payroll_employee_deduction (
    rowid            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_user          INT           NOT NULL,
    fk_deduction     INT           NOT NULL,
    rate_override    DECIMAL(10,4) DEFAULT NULL,
    amount_override  DECIMAL(12,2) DEFAULT NULL,
    active           TINYINT       NOT NULL DEFAULT 1,
    entity           INT           NOT NULL DEFAULT 1,
    UNIQUE KEY uk_payroll_emp_ded (fk_user, fk_deduction, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
