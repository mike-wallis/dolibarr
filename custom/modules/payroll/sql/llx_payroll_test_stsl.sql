-- ATO STSL (Schedule 8 / NAT 3539) test cases.
-- Stores ATO "STSL Sample Data" values used to verify total withholding
-- (PAYG + STSL component) for employees with a study/training loan debt.
-- CSV format: label,gross,period,scale,expected_payg,source

CREATE TABLE IF NOT EXISTS llx_payroll_test_stsl (
    rowid         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity        INT           NOT NULL DEFAULT 1,
    fy            VARCHAR(10)   NOT NULL,
    position      INT           NOT NULL DEFAULT 10,
    label         VARCHAR(255)  NOT NULL,
    gross         DECIMAL(10,2) NOT NULL,
    period        VARCHAR(20)   NOT NULL DEFAULT 'weekly',
    scale         VARCHAR(10)   NOT NULL DEFAULT 'scale2',
    expected_payg INT           NOT NULL DEFAULT 0,
    source        VARCHAR(100)  DEFAULT NULL,
    KEY idx_payroll_test_stsl (fy, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
