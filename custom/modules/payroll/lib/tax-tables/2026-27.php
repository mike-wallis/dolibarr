<?php
/**
 * ATO PAYG withholding coefficients and HECS thresholds for 2026-27.
 *
 * Source: ATO NAT 1004 – Schedule 1, published 17 June 2026.
 *         ATO "Withholding amounts sample data" (NAT 1004), published 17 June 2026.
 *
 * PAYG coefficients (Scales 1–6) are IDENTICAL to 2025-26.
 * Confirmed by the ATO publication dated 17 June 2026 (downloaded 23 June 2026).
 *
 * HECS/HELP (hecs_2026_27): ⚠ PLACEHOLDER — 2026-27 thresholds not yet published
 * (NAT 3539 – Schedule 8). Using 2025-26 values until the ATO publishes Schedule 8
 * for 2026-27. Update this key and re-seed the DB when the document is available.
 *
 * All PAYG coefficients verified against the ATO sample data table.
 * Do not edit without re-verifying against the published sample data.
 *
 * ── HOW TO UPDATE EACH JULY ────────────────────────────────────────────────
 * 1. Download NAT 1004 – Schedule 1 coefficient table from ato.gov.au.
 * 2. Download the "Withholding amounts sample data" and spot-check a few rows.
 * 3. If coefficients changed: copy this file as YYYY-YY.php with the new values.
 * 4. For HECS: download NAT 3539 – Schedule 8; update hecs_YYYY_YY array.
 * 5. Add 'YYYY-YY' => 'YYYY-YY' to PaygCalculator::$fy_table_map and availableYears().
 * 6. Re-seed coefficients and HECS via Payroll > Configuration > Seed buttons.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * FORMULA (applied to weekly equivalent of any pay period):
 *   x = floor(weekly_gross) + 0.99
 *   weekly_withholding = round(a * x - b)
 * Result is scaled back to the actual pay period.
 *
 * ── COEFFICIENT TABLE FORMAT ─────────────────────────────────────────────────
 * Each scale is an array of ranges: [max_weekly, a, b]
 * "Less than $X" in the ATO table → max_weekly = X - 1.
 * Ranges tested in order; first match (floor(weekly) <= max_weekly) wins.
 * Last entry has max_weekly = PHP_INT_MAX (catches all higher incomes).
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Scales:
 *   scale1 = Resident, NO tax-free threshold claimed
 *   scale2 = Resident, tax-free threshold CLAIMED  ← most employees
 *   scale3 = Foreign resident
 *   scale4 = No TFN provided (47% flat)
 *   scale5 = Full Medicare levy exemption (TFT claimed)
 *   scale6 = Half Medicare levy exemption
 */

return [

    // ── Scale 1: Resident, no tax-free threshold ──────────────────────────
    'scale1' => [
        //  [max_weekly,       a,          b      ]
        [  187,  0.1500,    0.1500  ],
        [  370,  0.2084,   11.0185  ],
        [  514,  0.1790,    0.1066  ],
        [  931,  0.3227,   74.1674  ],
        [ 2245,  0.3200,   71.6508  ],
        [ 3302,  0.3900,  228.8816  ],
        [ PHP_INT_MAX, 0.4700, 493.1893 ],
    ],

    // ── Scale 2: Resident, tax-free threshold claimed ─────────────────────
    // < $362/wk ($18,824/yr approx): nil withholding
    'scale2' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  537,  0.1500,   54.3462  ],
        [  672,  0.2500,  108.2135  ],
        [  720,  0.1700,   54.3473  ],
        [  864,  0.1790,   60.8377  ],
        [ 1281,  0.3227,  185.1935  ],
        [ 2595,  0.3200,  181.7319  ],
        [ 3652,  0.3900,  363.4627  ],
        [ PHP_INT_MAX, 0.4700, 655.7704 ],
    ],

    // ── Scale 3: Foreign residents ────────────────────────────────────────
    'scale3' => [
        //  [max_weekly,       a,          b      ]
        [ 2595,  0.3000,    0.3000  ],
        [ 3652,  0.3700,  181.7308  ],
        [ PHP_INT_MAX, 0.4500, 474.0385 ],
    ],

    // ── Scale 4: No TFN ───────────────────────────────────────────────────
    // 47% flat rate on all income (apply to floor of earnings, ignore cents in result).
    'scale4' => [
        [ PHP_INT_MAX, 0.4700, 0.00 ],
    ],

    // ── Scale 5: Full Medicare levy exemption (TFT claimed) ───────────────
    // < $362/wk: nil withholding
    'scale5' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  720,  0.1500,   54.3462  ],
        [  864,  0.1590,   60.8365  ],
        [ 1281,  0.3027,  185.1923  ],
        [ 2595,  0.3000,  181.7308  ],
        [ 3652,  0.3700,  363.4615  ],
        [ PHP_INT_MAX, 0.4500, 655.7692 ],
    ],

    // ── Scale 6: Half Medicare levy exemption ─────────────────────────────
    // < $362/wk: nil withholding
    'scale6' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  720,  0.1500,   54.3462  ],
        [  864,  0.1590,   60.8365  ],
        [  907,  0.3027,  185.1923  ],
        [ 1134,  0.3527,  230.6135  ],
        [ 1281,  0.3127,  185.1923  ],
        [ 2595,  0.3100,  181.7308  ],
        [ 3652,  0.3800,  363.4615  ],
        [ PHP_INT_MAX, 0.4600, 655.7692 ],
    ],

    // ── Schedule 8: STSL combined (PAYG + STSL) coefficient tables ───────────
    //
    // Source: ATO Schedule 8 – Statement of formulas for calculating study and
    //         training support loans components, published 17 June 2026.
    //         https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components
    //
    // Use when employee has a study/training loan (HELP, VSL, FS, SSL, AASL).
    // Formula (same x as Schedule 1):
    //   x = floor(weekly_gross) + 0.99
    //   weekly_combined = round(a × x − b)   ← COMBINED PAYG + STSL, not PAYG only
    // For monthly: period_combined = round(weekly_combined × 13 / 3)
    // For fortnightly: period_combined = weekly_combined × 2
    //
    // STSL component = period_combined − period_payg_from_schedule1
    // Max-weekly = ATO "Less than $X" → X−1. Last bracket = PHP_INT_MAX.
    //
    // Note: does NOT apply to scale4 (no TFN — 47% flat rate, no STSL).
    // Note: does NOT apply when employee also has a Medicare levy variation
    //       declaration (ATO Schedule 8 exclusion — treat as no-STSL case).

    // Scale 1 combined — No tax-free threshold claimed
    'stsl_scale1' => [
        //  [max_weekly,       a,          b      ]
        [  187,  0.1500,    0.1500  ],
        [  370,  0.2084,   11.0185  ],
        [  514,  0.1790,    0.1066  ],
        [  931,  0.3227,   74.1674  ],
        [  986,  0.3200,   71.6508  ],
        [ 2143,  0.4700,  219.7124  ],
        [ 2245,  0.4900,  262.6035  ],
        [ 2726,  0.5600,  419.8343  ],
        [ 3302,  0.4900,  228.8816  ],
        [ PHP_INT_MAX, 0.5700, 493.1893 ],
    ],

    // Scale 2 combined — Tax-free threshold claimed
    'stsl_scale2' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  537,  0.1500,   54.3462  ],
        [  672,  0.2500,  108.2135  ],
        [  720,  0.1700,   54.3473  ],
        [  864,  0.1790,   60.8377  ],
        [ 1281,  0.3227,  185.1935  ],
        [ 1336,  0.3200,  181.7319  ],
        [ 2493,  0.4700,  382.2935  ],
        [ 2595,  0.4900,  432.1846  ],
        [ 3576,  0.5600,  613.9154  ],
        [ 3652,  0.4900,  363.4627  ],
        [ PHP_INT_MAX, 0.5700, 655.7704 ],
    ],

    // Scale 3 combined — Foreign residents
    'stsl_scale3' => [
        //  [max_weekly,       a,          b      ]
        [ 1336,  0.3000,    0.3000  ],
        [ 2493,  0.4500,  200.5615  ],
        [ 2595,  0.4700,  250.4527  ],
        [ 3576,  0.5400,  432.1835  ],
        [ 3652,  0.4700,  181.7308  ],
        [ PHP_INT_MAX, 0.5500, 474.0385 ],
    ],

    // Scale 5 combined — Full Medicare levy exemption (TFT claimed)
    'stsl_scale5' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  720,  0.1500,   54.3462  ],
        [  864,  0.1590,   60.8365  ],
        [ 1281,  0.3027,  185.1923  ],
        [ 1336,  0.3000,  181.7308  ],
        [ 2493,  0.4500,  382.2923  ],
        [ 2595,  0.4700,  432.1835  ],
        [ 3576,  0.5400,  613.9142  ],
        [ 3652,  0.4700,  363.4615  ],
        [ PHP_INT_MAX, 0.5500, 655.7692 ],
    ],

    // Scale 6 combined — Half Medicare levy exemption
    'stsl_scale6' => [
        //  [max_weekly,       a,          b      ]
        [  361,  0.0000,    0.0000  ],
        [  720,  0.1500,   54.3462  ],
        [  864,  0.1590,   60.8365  ],
        [  907,  0.3027,  185.1923  ],
        [ 1134,  0.3527,  230.6135  ],
        [ 1281,  0.3127,  185.1923  ],
        [ 1336,  0.3100,  181.7308  ],
        [ 2493,  0.4600,  382.2923  ],
        [ 2595,  0.4800,  432.1835  ],
        [ 3576,  0.5500,  613.9142  ],
        [ 3652,  0.4800,  363.4615  ],
        [ PHP_INT_MAX, 0.5600, 655.7692 ],
    ],

    // ── HECS/HELP repayment thresholds (2024-25 flat-rate, kept for legacy) ─
    // Source: ATO NAT 3539 – Schedule 8.
    //
    // 2024-25 (graduated flat-rate system — rate applied to TOTAL annual income):
    'hecs_2024_25' => [
        //  [max_annual_income,  rate (applied to TOTAL income)]
        [  54434,  0.000 ],
        [  62849,  0.010 ],
        [  66618,  0.020 ],
        [  70618,  0.025 ],
        [  74855,  0.030 ],
        [  79347,  0.035 ],
        [  84107,  0.040 ],
        [  89154,  0.045 ],
        [  94503,  0.050 ],
        [ 100174,  0.055 ],
        [ 106185,  0.060 ],
        [ 112556,  0.065 ],
        [ 119309,  0.070 ],
        [ 126468,  0.075 ],
        [ 134057,  0.080 ],
        [ 142100,  0.085 ],
        [ 150626,  0.090 ],
        [ 159663,  0.095 ],
        [ PHP_INT_MAX, 0.100 ],
    ],

    // 2025-26 (marginal rate system — Universities Accord, from 1 July 2025):
    // Rate applied to INCOME ABOVE EACH THRESHOLD (not total income), except the
    // final row which is 10% of total income. See PaygCalculator::hecsAmount().
    'hecs_2025_26' => [
        // [threshold, marginal_rate, base_amount]
        [  67000,  0.00,     0.00 ],  // $0–$67,000: nil
        [ 125000,  0.15,     0.00 ],  // 15% on excess over $67,000
        [ 179285,  0.17,  8700.00 ],  // 17% on excess over $125,000; base = $8,700
        [ PHP_INT_MAX, 0.10, 0.00 ],  // 10% of TOTAL income (flat rate at top)
    ],

    // 2026-27 (marginal rate system — ⚠ PLACEHOLDER using 2025-26 values):
    // Download NAT 3539 – Schedule 8 for 2026-27 from ato.gov.au and update these
    // values, then re-seed via Payroll > Configuration > HECS brackets > Seed.
    'hecs_2026_27' => [
        // [threshold, marginal_rate, base_amount]
        [  67000,  0.00,     0.00 ],  // ⚠ 2025-26 placeholder — update from NAT 3539
        [ 125000,  0.15,     0.00 ],
        [ 179285,  0.17,  8700.00 ],
        [ PHP_INT_MAX, 0.10, 0.00 ],
    ],

];
