-- ATO Medicare half-levy adjustment Scale 6 test cases.
-- Stores ATO "Medicare half-levy adjustment scale 6 sample data" values used to
-- verify the MLA reduction for Scale 6 (half Medicare levy) earners with children.
-- num_children: 1-5.
-- CSV format: label,gross,period,num_children,expected_adjustment,source

CREATE TABLE IF NOT EXISTS llx_payroll_test_mla6 (
    rowid               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity              INT           NOT NULL DEFAULT 1,
    fy                  VARCHAR(10)   NOT NULL,
    position            INT           NOT NULL DEFAULT 10,
    label               VARCHAR(255)  NOT NULL,
    gross               DECIMAL(10,2) NOT NULL,
    period              VARCHAR(20)   NOT NULL DEFAULT 'weekly',
    num_children        INT           NOT NULL DEFAULT 0,
    expected_adjustment INT           NOT NULL DEFAULT 0,
    source              VARCHAR(100)  DEFAULT NULL,
    KEY idx_payroll_test_mla6 (fy, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
