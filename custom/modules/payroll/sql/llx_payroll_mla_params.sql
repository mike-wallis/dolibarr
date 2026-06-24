-- MLA formula parameters per FY and scale.
-- Stores the ATO-published threshold values used in the Medicare Levy Adjustment
-- formula (NAT 1008 / NAT 1009). One row per FY+scale+param_key.
-- These values are hardcoded in PaygCalculator as a fallback; seeding this table
-- makes them editable without code changes and auditable per FY.
--
-- param_key values (per scale):
--   weekly_threshold  - min weekly income for any WLA to apply
--   mid_threshold     - upper bound of phase-in zone (compared with WFT, lower wins)
--   phase_in_rate     - rate applied in phase-in zone
--   levy_rate         - rate applied to x in full levy zone
--   shade_out_rate    - rate applied above Weekly Family Threshold (reduces WLA to 0)
--   annual_base       - annual family threshold (no children) used to derive WFT
--   annual_per_child  - additional annual amount per dependent child

CREATE TABLE IF NOT EXISTS llx_payroll_mla_params (
    rowid      INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fy         VARCHAR(10)   NOT NULL,
    scale      VARCHAR(10)   NOT NULL,
    param_key  VARCHAR(40)   NOT NULL,
    param_value DECIMAL(12,5) NOT NULL,
    entity     INT           NOT NULL DEFAULT 1,
    UNIQUE KEY uk_mla_param (fy, scale, param_key, entity),
    KEY idx_mla_params (fy, scale, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed 2026-27 values (same as 2025-26 — ATO confirmed no change for 2026-27)
-- Source: ATO Medicare levy adjustment page, published 17 June 2026
INSERT IGNORE INTO llx_payroll_mla_params (fy, scale, param_key, param_value, entity) VALUES
    ('2026-27', 'scale2', 'weekly_threshold',  538.67, 1),
    ('2026-27', 'scale2', 'mid_threshold',     673.00, 1),
    ('2026-27', 'scale2', 'phase_in_rate',       0.10, 1),
    ('2026-27', 'scale2', 'levy_rate',           0.02, 1),
    ('2026-27', 'scale2', 'shade_out_rate',      0.08, 1),
    ('2026-27', 'scale2', 'annual_base',      47238.00, 1),
    ('2026-27', 'scale2', 'annual_per_child',  4338.00, 1),
    ('2026-27', 'scale6', 'weekly_threshold',  908.42, 1),
    ('2026-27', 'scale6', 'mid_threshold',    1135.00, 1),
    ('2026-27', 'scale6', 'phase_in_rate',       0.05, 1),
    ('2026-27', 'scale6', 'levy_rate',           0.01, 1),
    ('2026-27', 'scale6', 'shade_out_rate',      0.04, 1),
    ('2026-27', 'scale6', 'annual_base',      47238.00, 1),
    ('2026-27', 'scale6', 'annual_per_child',  4338.00, 1);
