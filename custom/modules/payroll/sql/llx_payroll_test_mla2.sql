-- ATO Medicare levy adjustment Scale 2 test cases.
-- Stores ATO "Medicare levy adjustment scale 2 sample data" values used to
-- verify the MLA reduction for Scale 2 low-income earners with dependants.
-- num_dependants: 0 = spouse only; 1-5 = number of children.
-- CSV format: label,gross,period,num_dependants,expected_adjustment,source

CREATE TABLE IF NOT EXISTS llx_payroll_test_mla2 (
    rowid               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity              INT           NOT NULL DEFAULT 1,
    fy                  VARCHAR(10)   NOT NULL,
    position            INT           NOT NULL DEFAULT 10,
    label               VARCHAR(255)  NOT NULL,
    gross               DECIMAL(10,2) NOT NULL,
    period              VARCHAR(20)   NOT NULL DEFAULT 'weekly',
    num_dependants      INT           NOT NULL DEFAULT 0,
    expected_adjustment INT           NOT NULL DEFAULT 0,
    source              VARCHAR(100)  DEFAULT NULL,
    KEY idx_payroll_test_mla2 (fy, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
