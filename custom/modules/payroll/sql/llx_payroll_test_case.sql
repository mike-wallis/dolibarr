-- PAYG verification test cases per financial year.
-- Stores ATO sample data and other known-good values used to verify
-- the PaygCalculator matches published ATO outputs after any coefficient update.
-- If no rows exist for a given FY the setup.php page falls back to hardcoded defaults.

CREATE TABLE IF NOT EXISTS llx_payroll_test_case (
    rowid         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity        INT           NOT NULL DEFAULT 1,
    fy            VARCHAR(10)   NOT NULL,
    position      INT           NOT NULL DEFAULT 10,
    label         VARCHAR(255)  NOT NULL,
    gross         DECIMAL(10,2) NOT NULL,
    period        VARCHAR(20)   NOT NULL DEFAULT 'weekly',
    scale         VARCHAR(10)   NOT NULL DEFAULT 'scale2',
    has_hecs      TINYINT       NOT NULL DEFAULT 0,
    has_mla       TINYINT       NOT NULL DEFAULT 0,
    mla_deps      INT           NOT NULL DEFAULT 0,
    expected_payg INT           NOT NULL DEFAULT 0,
    source        VARCHAR(100)  DEFAULT NULL,
    KEY idx_payroll_test (fy, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
