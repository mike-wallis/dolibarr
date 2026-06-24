-- HECS/HELP/STSL repayment threshold brackets per financial year.
-- For flat-rate system (hecs_system=flat on llx_payroll_fy_config):
--   rate applies to TOTAL annual income. base_amount=0. is_flat_total=1 on all rows.
-- For marginal system (hecs_system=marginal, from 2025-26):
--   rate applies to income ABOVE income_from. base_amount = cumulative HECS at bottom of bracket.
--   is_flat_total=1 on the final bracket only (10% of total income above top threshold).
-- income_to: use 9999999 for the unlimited top bracket.

CREATE TABLE IF NOT EXISTS llx_payroll_hecs_bracket (
    rowid         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fy            VARCHAR(10)   NOT NULL,
    income_from   DECIMAL(12,2) NOT NULL DEFAULT 0,
    income_to     DECIMAL(12,2) NOT NULL,
    rate          DECIMAL(8,5)  NOT NULL DEFAULT 0,
    base_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_flat_total TINYINT       NOT NULL DEFAULT 0,
    position      INT           NOT NULL DEFAULT 10,
    entity        INT           NOT NULL DEFAULT 1,
    KEY idx_payroll_hecs (fy, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2024-25: flat-rate system (rate on TOTAL income, 19 brackets)
-- Source: ATO NAT 3539 Schedule 8 2024-25
INSERT IGNORE INTO llx_payroll_hecs_bracket
    (fy, income_from, income_to, rate, base_amount, is_flat_total, position, entity)
VALUES
    ('2024-25',      0,  54434, 0.00000, 0, 1,  10, 1),
    ('2024-25',  54435,  62849, 0.01000, 0, 1,  20, 1),
    ('2024-25',  62850,  66618, 0.02000, 0, 1,  30, 1),
    ('2024-25',  66619,  70618, 0.02500, 0, 1,  40, 1),
    ('2024-25',  70619,  74855, 0.03000, 0, 1,  50, 1),
    ('2024-25',  74856,  79347, 0.03500, 0, 1,  60, 1),
    ('2024-25',  79348,  84107, 0.04000, 0, 1,  70, 1),
    ('2024-25',  84108,  89154, 0.04500, 0, 1,  80, 1),
    ('2024-25',  89155,  94503, 0.05000, 0, 1,  90, 1),
    ('2024-25',  94504, 100174, 0.05500, 0, 1, 100, 1),
    ('2024-25', 100175, 106185, 0.06000, 0, 1, 110, 1),
    ('2024-25', 106186, 112556, 0.06500, 0, 1, 120, 1),
    ('2024-25', 112557, 119309, 0.07000, 0, 1, 130, 1),
    ('2024-25', 119310, 126468, 0.07500, 0, 1, 140, 1),
    ('2024-25', 126469, 134057, 0.08000, 0, 1, 150, 1),
    ('2024-25', 134058, 142100, 0.08500, 0, 1, 160, 1),
    ('2024-25', 142101, 150626, 0.09000, 0, 1, 170, 1),
    ('2024-25', 150627, 159663, 0.09500, 0, 1, 180, 1),
    ('2024-25', 159664, 9999999, 0.10000, 0, 1, 190, 1);

-- 2025-26: marginal system (Universities Accord from Sep 2025)
-- Source: ATO NAT 3539 Schedule 8 2025-26
-- Brackets: rate applies to EXCESS over income_from (except last row: flat 10% of total)
INSERT IGNORE INTO llx_payroll_hecs_bracket
    (fy, income_from, income_to, rate, base_amount, is_flat_total, position, entity)
VALUES
    ('2025-26',      0,   67000, 0.00000,    0.00, 0,  10, 1),
    ('2025-26',  67000,  125000, 0.15000,    0.00, 0,  20, 1),
    ('2025-26', 125000,  179285, 0.17000, 8700.00, 0,  30, 1),
    ('2025-26', 179285, 9999999, 0.10000,    0.00, 1,  40, 1);
