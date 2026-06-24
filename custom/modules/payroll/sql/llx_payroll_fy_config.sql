-- Per-financial-year payroll configuration.
-- One row per FY (Australian tax year, 1 July – 30 June).
-- start_date/end_date: used at pay-run time to select the correct tax tables for a pay date.
-- hecs_system: flat = rate on total income (2024-25), marginal = rate on excess (2025-26+)

CREATE TABLE IF NOT EXISTS llx_payroll_fy_config (
    rowid        INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fy           VARCHAR(10)   NOT NULL,
    start_date   DATE          NULL,
    end_date     DATE          NULL,
    super_rate   DECIMAL(5,2)  NOT NULL DEFAULT 12.00,
    hecs_system  VARCHAR(10)   NOT NULL DEFAULT 'flat',
    min_wage     DECIMAL(8,2)  NOT NULL DEFAULT 0,
    notes        TEXT          DEFAULT NULL,
    active       TINYINT       NOT NULL DEFAULT 1,
    entity       INT           NOT NULL DEFAULT 1,
    UNIQUE KEY uk_payroll_fy (fy, entity),
    KEY idx_payroll_fy_dates (start_date, end_date, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed rows for 2024-25, 2025-26, 2026-27.
-- super_rate: 11.5% in 2024-25, 12.0% from 2025-26 (scheduled increase).
-- min_wage: Fair Work Commission hourly rate — verify each July (2026-27 not yet confirmed).
-- hecs_system: marginal system applies from 2025-26 onwards (Universities Accord Sep 2025).
INSERT IGNORE INTO llx_payroll_fy_config (fy, start_date, end_date, super_rate, hecs_system, min_wage, entity)
VALUES
    ('2024-25', '2024-07-01', '2025-06-30', 11.50, 'flat',     23.23, 1),
    ('2025-26', '2025-07-01', '2026-06-30', 12.00, 'marginal', 24.10, 1),
    ('2026-27', '2026-07-01', '2027-06-30', 12.00, 'marginal',  0.00, 1);
