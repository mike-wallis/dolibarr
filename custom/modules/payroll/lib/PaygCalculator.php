<?php
/**
 * ATO PAYG withholding calculator.
 *
 * Implements the ATO Schedule 1 (NAT 1004) formula:
 *   x = floor(weekly_equivalent) + 0.99
 *   weekly_withholding = round(a * x - b)
 *
 * HECS/HELP implemented separately (Schedule 8 / NAT 3539):
 *   - 2024-25: flat rate on total income (old graduated system)
 *   - 2025-26: marginal rate system (Universities Accord, from Sep 2025)
 *
 * Usage:
 *   $result = PaygCalculator::calculate(955.00, 'weekly', 'scale2', true, '2025-26');
 *   // $result = ['payg' => 146.00, 'hecs' => 0.00, 'total' => 146.00]
 */
class PaygCalculator
{
    // Pay period multipliers: period gross × multiplier = weekly equivalent
    private static $period_divisors = [
        'weekly'       => 1,
        'fortnightly'  => 2,
        'halfmonthly'  => 2.1667,   // 26 fortnights / 12 months ≈ 2.1667
        'monthly'      => 4.3333,   // 52 weeks / 12 months
        'fourweekly'   => 4,
    ];

    // Financial year → tax table file key
    private static $fy_table_map = [
        '2024-25' => '2025-26',  // same coefficients confirmed by ATO
        '2025-26' => '2025-26',
        '2026-27' => '2026-27',  // same coefficients confirmed by ATO (NAT 1004 published 17 June 2026)
    ];

    /**
     * Main entry point.
     *
     * @param float  $gross_period  Gross earnings for this pay period
     * @param string $period        weekly|fortnightly|halfmonthly|monthly|fourweekly
     * @param string $scale                 scale1|scale2|scale3|scale4|scale5|scale6
     * @param bool   $has_hecs              Employee has a HECS/HELP debt
     * @param string $fy                    Financial year: '2024-25', '2025-26', or '2026-27'
     * @param bool   $has_medicare_adj      Employee has lodged a Medicare levy variation declaration
     * @param int    $medicare_dependants   0 = spouse only (Q9 yes, Q12 no); N = N dependent children
     *
     * @return array ['payg'=>float, 'hecs'=>float, 'total'=>float]
     */
    public static function calculate(
        float  $gross_period,
        string $period  = 'weekly',
        string $scale   = 'scale2',
        bool   $has_hecs = false,
        string $fy       = '2025-26',
        bool   $has_medicare_adj     = false,
        int    $medicare_dependants  = 0
    ): array {
        $divisor = self::$period_divisors[$period] ?? 1;
        $tables  = self::loadTables($fy);

        // Step 1: convert to weekly equivalent
        $weekly = $gross_period / $divisor;
        $x      = floor($weekly) + 0.99;

        // Step 2: PAYG withholding (weekly, then scale back to period)
        $weekly_payg  = self::applyScale($x, $scale, $tables);
        $period_payg  = (int) round($weekly_payg * $divisor);

        // Step 3: Medicare levy adjustment (reduces withholding — Scale 2/6 only)
        // Source: ato.gov.au/tax-rates-and-codes/tax-tables/medicare-levy-adjustment (17 June 2026)
        if ($has_medicare_adj && in_array($scale, ['scale2', 'scale6'])) {
            $weekly_wla = self::medicareAdjustment($x, $scale, $medicare_dependants);
            // ATO: monthly = WLA × 13 ÷ 3; all other periods = WLA × divisor
            $period_wla = ($period === 'monthly')
                ? (int) round($weekly_wla * 13 / 3)
                : (int) round($weekly_wla * $divisor);
            $period_payg = max(0, $period_payg - $period_wla);
        }

        // Step 4: HECS repayment (annualised, then scaled to period)
        $period_hecs = 0;
        if ($has_hecs) {
            $annual_income = $weekly * 52;
            $annual_hecs   = self::hecsAmount($annual_income, $fy, $tables);
            $period_hecs   = (int) ceil(($annual_hecs / 52) * $divisor);
        }

        return [
            'payg'  => max(0, $period_payg),
            'hecs'  => max(0, $period_hecs),
            'total' => max(0, $period_payg + $period_hecs),
        ];
    }

    /**
     * Medicare levy weekly adjustment (WLA).
     * ATO source: medicare-levy-adjustment page, published 17 June 2026.
     *
     * Applies to Scale 2 employees earning ≥$538/wk and Scale 6 ≥$908/wk
     * who have lodged a Medicare levy variation declaration (NAT 0929).
     *
     * @param float  $x           floor(weekly_gross) + 0.99
     * @param string $scale       'scale2' or 'scale6'
     * @param int    $dependants  0 = spouse/partner only; N = N dependent children
     * @return float Weekly levy adjustment in whole dollars (0.5 rounds up)
     */
    private static function medicareAdjustment(float $x, string $scale, int $dependants): float
    {
        // Weekly Family Threshold: $908.42 (no children) + $83.42 per child
        $wft = round((4338 * $dependants + 47238) / 52, 2);

        // Shading Out Point: ignore cents
        $sop = (int)(($wft * 0.1) / 0.08);

        if ($scale === 'scale2') {
            if ($x < 538.67 || $x >= $sop) {
                return 0.0;
            }
            if ($x < min(673.0, $wft)) {
                $wla = ($x - 538.67) * 0.1;
            } elseif ($x < $wft) {
                $wla = $x * 0.02;
            } else {
                $wla = ($wft * 0.02) - (($x - $wft) * 0.08);
            }
        } else { // scale6
            if ($x < 908.42 || $x >= $sop) {
                return 0.0;
            }
            if ($x < min(1135.0, $wft)) {
                $wla = ($x - 908.42) * 0.05;
            } elseif ($x < $wft) {
                $wla = $x * 0.01;
            } else {
                $wla = ($wft * 0.01) - (($x - $wft) * 0.04);
            }
        }

        // Round to nearest dollar; 0.50 cents rounds up
        return (float)(int)(max(0.0, $wla) + 0.5);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function applyScale(float $x, string $scale, array $tables): float
    {
        $ranges = $tables[$scale] ?? $tables['scale2'];
        foreach ($ranges as [$max_weekly, $a, $b]) {
            if ($x <= $max_weekly + 0.99) {
                return max(0, $a * $x - $b);
            }
        }
        // Fallback: last range
        [$max_weekly, $a, $b] = end($ranges);
        return max(0, $a * $x - $b);
    }

    private static function hecsAmount(float $annual_income, string $fy, array $tables): float
    {
        if ($fy === '2024-25') {
            return self::hecsFlat($annual_income, $tables['hecs_2024_25']);
        }
        // 2025-26 and later: marginal rate system (Universities Accord)
        $key = 'hecs_' . str_replace('-', '_', $fy);
        return self::hecsMarginal($annual_income, $tables[$key] ?? $tables['hecs_2025_26']);
    }

    /**
     * Old graduated flat-rate system (2024-25).
     * Rate applies to TOTAL income.
     */
    private static function hecsFlat(float $annual_income, array $brackets): float
    {
        foreach ($brackets as [$max, $rate]) {
            if ($annual_income <= $max) {
                return $annual_income * $rate;
            }
        }
        [$max, $rate] = end($brackets);
        return $annual_income * $rate;
    }

    /**
     * New marginal system (2025-26).
     * Brackets: [threshold, marginal_rate, base_amount]
     * Rate applies to income ABOVE each threshold.
     * The final bracket (10%) is a flat rate on total income.
     */
    private static function hecsMarginal(float $annual_income, array $brackets): float
    {
        // brackets are [threshold, rate, base_amount] in ascending order
        // Find which bracket we're in
        $prev_threshold = 0;
        $prev_base      = 0;
        foreach ($brackets as [$threshold, $rate, $base]) {
            if ($annual_income <= $threshold) {
                if ($rate == 0.0) {
                    return 0.0;
                }
                return $prev_base + $rate * ($annual_income - $prev_threshold);
            }
            $prev_threshold = $threshold;
            $prev_base = $base > 0 ? $base : $prev_base + $rate * ($threshold - $prev_threshold);
        }
        // Final bracket: 10% flat on total income
        return $annual_income * 0.10;
    }

    private static function loadTables(string $fy): array
    {
        $tables = self::loadTablesFromDb($fy);
        if ($tables !== null) {
            return $tables;
        }

        $file_key = self::$fy_table_map[$fy] ?? '2025-26';
        $path = __DIR__ . '/tax-tables/' . $file_key . '.php';
        if (!file_exists($path)) {
            $path = __DIR__ . '/tax-tables/2025-26.php';
        }
        return require $path;
    }

    /**
     * Try to load coefficient and HECS tables from the database.
     * Returns null if no coefficient rows exist for this FY (triggers PHP-file fallback).
     */
    private static function loadTablesFromDb(string $fy): ?array
    {
        global $db, $conf;
        if (empty($db)) {
            return null;
        }

        // Load coefficients for this FY
        $sql = "SELECT scale, max_weekly, a_coeff, b_coeff"
            . " FROM " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
            . " WHERE fy = '" . $db->escape($fy) . "'"
            . " AND entity = " . (int)$conf->entity
            . " ORDER BY scale, position, max_weekly";
        $res = $db->query($sql);
        if (!$res || $db->num_rows($res) === 0) {
            return null; // No DB data — fall back to PHP file
        }

        $tables = [];
        while ($obj = $db->fetch_object($res)) {
            $tables[$obj->scale][] = [(float)$obj->max_weekly, (float)$obj->a_coeff, (float)$obj->b_coeff];
        }

        // Load HECS for 2024-25 (flat-rate: income_to used as upper bound)
        $sql2 = "SELECT income_to, rate FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
            . " WHERE fy = '2024-25' AND entity = " . (int)$conf->entity
            . " ORDER BY position, income_from";
        $res2 = $db->query($sql2);
        $tables['hecs_2024_25'] = [];
        if ($res2) {
            while ($obj = $db->fetch_object($res2)) {
                $tables['hecs_2024_25'][] = [(float)$obj->income_to, (float)$obj->rate];
            }
        }

        // Load HECS for 2025-26 (marginal: [income_to, rate, base_amount])
        $sql3 = "SELECT income_to, rate, base_amount FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
            . " WHERE fy = '2025-26' AND entity = " . (int)$conf->entity
            . " ORDER BY position, income_from";
        $res3 = $db->query($sql3);
        $tables['hecs_2025_26'] = [];
        if ($res3) {
            while ($obj = $db->fetch_object($res3)) {
                $tables['hecs_2025_26'][] = [(float)$obj->income_to, (float)$obj->rate, (float)$obj->base_amount];
            }
        }

        // Load HECS for 2026-27 (marginal)
        $sql4 = "SELECT income_to, rate, base_amount FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
            . " WHERE fy = '2026-27' AND entity = " . (int)$conf->entity
            . " ORDER BY position, income_from";
        $res4 = $db->query($sql4);
        $tables['hecs_2026_27'] = [];
        if ($res4) {
            while ($obj = $db->fetch_object($res4)) {
                $tables['hecs_2026_27'][] = [(float)$obj->income_to, (float)$obj->rate, (float)$obj->base_amount];
            }
        }

        // If DB HECS rows are missing, pull them from the PHP file as fallback
        if (empty($tables['hecs_2024_25']) || empty($tables['hecs_2025_26'])) {
            $php_path = __DIR__ . '/tax-tables/2025-26.php';
            if (file_exists($php_path)) {
                $php = require $php_path;
                if (empty($tables['hecs_2024_25'])) {
                    $tables['hecs_2024_25'] = $php['hecs_2024_25'];
                }
                if (empty($tables['hecs_2025_26'])) {
                    $tables['hecs_2025_26'] = $php['hecs_2025_26'];
                }
            }
        }
        if (empty($tables['hecs_2026_27'])) {
            $php_path = __DIR__ . '/tax-tables/2026-27.php';
            if (file_exists($php_path)) {
                $php = require $php_path;
                $tables['hecs_2026_27'] = $php['hecs_2026_27'] ?? $tables['hecs_2025_26'];
            } else {
                $tables['hecs_2026_27'] = $tables['hecs_2025_26'];
            }
        }

        return $tables;
    }

    /**
     * List available financial years (for UI dropdowns).
     */
    public static function availableYears(): array
    {
        return ['2024-25', '2025-26', '2026-27'];
    }

    /**
     * Human-readable scale labels.
     */
    public static function scaleLabels(): array
    {
        return [
            'scale2' => 'Scale 2 — Resident, tax-free threshold claimed (most employees)',
            'scale1' => 'Scale 1 — Resident, no tax-free threshold',
            'scale3' => 'Scale 3 — Foreign resident',
            'scale4' => 'Scale 4 — No TFN provided',
            'scale5' => 'Scale 5 — Full Medicare levy exemption (TFT claimed)',
            'scale6' => 'Scale 6 — Half Medicare levy exemption',
        ];
    }

    /**
     * Period multipliers (useful for UI).
     */
    public static function periodMultipliers(): array
    {
        return self::$period_divisors;
    }
}
