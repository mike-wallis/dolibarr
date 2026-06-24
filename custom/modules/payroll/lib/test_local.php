<?php
/**
 * Standalone test runner (no Dolibarr bootstrap needed).
 * Run from the command line:  php test_local.php
 */

// Minimal stubs so PaygCalculator can load without Dolibarr
define('MAIN_DB_PREFIX', 'llx_');
if (!defined('PHP_INT_MAX')) define('PHP_INT_MAX', PHP_INT_MAX);

// Provide an empty $db and $conf so PaygCalculator's DB path is skipped
$db   = null;
$conf = null;

require_once __DIR__ . '/PaygCalculator.php';

$data_dir = __DIR__ . '/../data/';

// ── Withholding ───────────────────────────────────────────────────────────────
echo "=== Withholding (ato-withholding-2026-27.csv) ===\n";
$rows = array_map('str_getcsv', file($data_dir . 'ato-withholding-2026-27.csv'));
$header = array_shift($rows);  // label,gross,period,scale,expected_payg,source
$pass = 0; $fail = 0;
foreach ($rows as $r) {
    [$label, $gross, $period, $scale, $expected] = $r;
    $gross    = (float)$gross;
    $expected = (int)$expected;
    $result   = PaygCalculator::calculate($gross, $period, $scale, false, '2026-27');
    $got      = $result['payg'];
    if ($got === $expected) {
        $pass++;
    } else {
        $fail++;
        printf("FAIL  %-45s  expected=%4d  got=%4d  diff=%+d\n",
            $label, $expected, $got, $got - $expected);
    }
}
printf("Withholding: %d passed, %d failed\n\n", $pass, $fail);

// ── STSL ─────────────────────────────────────────────────────────────────────
echo "=== STSL (ato-stsl-2026-27.csv) ===\n";
$rows = array_map('str_getcsv', file($data_dir . 'ato-stsl-2026-27.csv'));
$header = array_shift($rows);  // label,gross,period,scale,expected_payg,source
$pass = 0; $fail = 0;
foreach ($rows as $r) {
    [$label, $gross, $period, $scale, $expected] = $r;
    $gross    = (float)$gross;
    $expected = (int)$expected;
    $result   = PaygCalculator::calculate($gross, $period, $scale, true, '2026-27');
    $got      = $result['total'];  // combined PAYG + STSL
    if ($got === $expected) {
        $pass++;
    } else {
        $fail++;
        printf("FAIL  %-45s  expected=%4d  got=%4d  diff=%+d\n",
            $label, $expected, $got, $got - $expected);
    }
}
printf("STSL: %d passed, %d failed\n\n", $pass, $fail);
