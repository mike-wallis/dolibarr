-- ATO Schedule 1 (NAT 1004) coefficient rows per financial year and scale.
-- Formula: weekly_withholding = a_coeff * (floor(weekly_gross) + 0.99) - b_coeff
-- max_weekly: upper bound of this range in weekly earnings (9999999 = catch-all last row).
-- Rows are ordered by position then max_weekly (first match wins).
-- Populate via the Payroll config page (Seed from PHP file button) or enter manually
-- from the ATO NAT 1004 PDF downloaded each July.

CREATE TABLE IF NOT EXISTS llx_payroll_tax_coefficient (
    rowid      INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fy         VARCHAR(10)   NOT NULL,
    scale      VARCHAR(10)   NOT NULL,
    max_weekly DECIMAL(12,2) NOT NULL,
    a_coeff    DECIMAL(10,5) NOT NULL,
    b_coeff    DECIMAL(12,4) NOT NULL,
    position   INT           NOT NULL DEFAULT 10,
    entity     INT           NOT NULL DEFAULT 1,
    KEY idx_payroll_coeff (fy, scale, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
