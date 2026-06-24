<?php
/**
 * Payroll module configuration — Financial Years, Tax Coefficients, HECS Brackets.
 * Accessed via the gear icon on Setup > Modules > Payroll card.
 *
 * Tabs:
 *   fy         — super rate, HECS system, minimum wage per financial year
 *   taxtables  — ATO tax tables: PAYG coefficients (NAT 1004), MLA params (NAT 1008/1009), STSL brackets (NAT 3539)
 *   tests      — ATO sample data test cases for PAYG verification (import/view per FY)
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/PaygCalculator.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(['compta']);

$action = GETPOST('action', 'aZ09');
$tab    = GETPOST('tab', 'alpha') ?: 'fy';
// Legacy tab names — redirect to unified taxtables tab
if ($tab === 'coeff' || $tab === 'hecs') {
    $tab = 'taxtables';
}
$rowid  = GETPOSTINT('rowid');

$error   = '';
$message = '';

// ── Test runner — JSON endpoints (AJAX, early exit before HTML) ───────────────
if (in_array($action, ['run_tests_withholding', 'run_tests_mla2', 'run_tests_mla6', 'run_tests_stsl'], true)) {
    header('Content-Type: application/json; charset=utf-8');

    $type_map = [
        'run_tests_withholding' => ['tbl' => 'payroll_test_withholding', 'type' => 'withholding'],
        'run_tests_mla2'        => ['tbl' => 'payroll_test_mla2',        'type' => 'mla2'],
        'run_tests_mla6'        => ['tbl' => 'payroll_test_mla6',        'type' => 'mla6'],
        'run_tests_stsl'        => ['tbl' => 'payroll_test_stsl',        'type' => 'stsl'],
    ];
    $ds   = $type_map[$action];
    $tbl  = MAIN_DB_PREFIX . $ds['tbl'];
    $type = $ds['type'];

    $res_fy = $db->query("SELECT DISTINCT fy FROM $tbl WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC");
    $result = ['dataset' => $type, 'fys' => []];

    while ($res_fy && ($fy_row = $db->fetch_object($res_fy))) {
        $fy   = $fy_row->fy;
        $pass = 0; $fail = 0; $failures = [];

        if ($type === 'withholding') {
            $res = $db->query("SELECT label, gross, period, scale, expected_payg FROM $tbl"
                . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
                . " ORDER BY position, rowid");
            while ($res && ($r = $db->fetch_object($res))) {
                $got = PaygCalculator::calculate((float)$r->gross, $r->period, $r->scale, false, $fy)['payg'];
                $exp = (int)$r->expected_payg;
                if ($got === $exp) { $pass++; } else {
                    $fail++;
                    $failures[] = ['label' => $r->label, 'gross' => (float)$r->gross,
                        'period' => $r->period, 'scale' => $r->scale, 'expected' => $exp, 'got' => $got];
                }
            }
        } elseif ($type === 'mla2') {
            $res = $db->query("SELECT label, gross, period, num_dependants, expected_adjustment FROM $tbl"
                . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
                . " ORDER BY position, rowid");
            while ($res && ($r = $db->fetch_object($res))) {
                $deps = (int)$r->num_dependants;
                $base = PaygCalculator::calculate((float)$r->gross, $r->period, 'scale2', false, $fy)['payg'];
                $adj  = PaygCalculator::calculate((float)$r->gross, $r->period, 'scale2', false, $fy, true, $deps)['payg'];
                $got  = $base - $adj;
                $exp  = (int)$r->expected_adjustment;
                if ($got === $exp) { $pass++; } else {
                    $fail++;
                    $failures[] = ['label' => $r->label, 'gross' => (float)$r->gross,
                        'period' => $r->period, 'num_deps' => $deps, 'expected' => $exp, 'got' => $got];
                }
            }
        } elseif ($type === 'mla6') {
            $res = $db->query("SELECT label, gross, period, num_children, expected_adjustment FROM $tbl"
                . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
                . " ORDER BY position, rowid");
            while ($res && ($r = $db->fetch_object($res))) {
                $children = (int)$r->num_children;
                $base = PaygCalculator::calculate((float)$r->gross, $r->period, 'scale6', false, $fy)['payg'];
                $adj  = PaygCalculator::calculate((float)$r->gross, $r->period, 'scale6', false, $fy, true, $children)['payg'];
                $got  = $base - $adj;
                $exp  = (int)$r->expected_adjustment;
                if ($got === $exp) { $pass++; } else {
                    $fail++;
                    $failures[] = ['label' => $r->label, 'gross' => (float)$r->gross,
                        'period' => $r->period, 'num_children' => $children, 'expected' => $exp, 'got' => $got];
                }
            }
        } elseif ($type === 'stsl') {
            $res = $db->query("SELECT label, gross, period, scale, expected_payg FROM $tbl"
                . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity
                . " ORDER BY position, rowid");
            while ($res && ($r = $db->fetch_object($res))) {
                $got = PaygCalculator::calculate((float)$r->gross, $r->period, $r->scale, true, $fy)['total'];
                $exp = (int)$r->expected_payg;
                if ($got === $exp) { $pass++; } else {
                    $fail++;
                    $failures[] = ['label' => $r->label, 'gross' => (float)$r->gross,
                        'period' => $r->period, 'scale' => $r->scale, 'expected' => $exp, 'got' => $got];
                }
            }
        }
        $result['fys'][$fy] = ['pass' => $pass, 'fail' => $fail, 'failures' => $failures];
    }

    echo json_encode($result);
    exit;
}

// ── Template downloads (early exit — must run before any HTML output) ────────

if ($action === 'download_template_coeff') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payg-coefficients-template.csv"');
    echo "scale,position,max_weekly,a_coeff,b_coeff\n";
    // Pre-fill with 2026-27 values (same as 2025-26 per ATO NAT 1004 published 17 Jun 2026)
    $tpl_path = dol_buildpath('/custom/payroll/lib/tax-tables/2026-27.php', 0);
    if (file_exists($tpl_path)) {
        $tpl = require $tpl_path;
        foreach ($tpl as $sc => $rows) {
            if (strpos($sc, 'scale') !== 0) {
                continue;
            }
            $p = 10;
            foreach ($rows as $row) {
                [$mw, $a, $b] = $row;
                if ($mw >= 9000000000000) {
                    $mw = 9999999;
                }
                echo $sc . ',' . $p . ',' . (int)$mw . ','
                    . number_format($a, 5, '.', '') . ','
                    . number_format($b, 4, '.', '') . "\n";
                $p += 10;
            }
        }
    } else {
        echo "# Error: lib/tax-tables/2026-27.php not found. Create the file first.\n";
        echo "scale1,10,187,0.15000,0.1500\n";
        echo "scale1,70,9999999,0.47000,493.1893\n";
    }
    exit;
}

if ($action === 'download_template_hecs') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hecs-brackets-template.csv"');
    echo "position,income_from,income_to,rate,base_amount,is_flat_total\n";
    // Marginal-rate format (2025-26+). For flat-rate (pre-2025-26) set is_flat_total=1 on all rows.
    echo "10,0,67000,0.00000,0.00,0\n";
    echo "20,67000,125000,0.15000,0.00,0\n";
    echo "30,125000,179285,0.17000,8700.00,0\n";
    echo "40,179285,9999999,0.10000,0.00,1\n";
    exit;
}

if ($action === 'download_template_withholding') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ato-withholding-template.csv"');
    echo "label,gross,period,scale,expected_payg,source\n";
    echo "\"S1 \$370/wk\",370,weekly,scale1,66,ATO 2026-27 sample data\n";
    echo "\"S2 \$362/wk\",362,weekly,scale2,0,ATO 2026-27 sample data\n";
    echo "\"S2 \$865/wk\",865,weekly,scale2,94,ATO 2026-27 sample data\n";
    echo "\"S3 \$538/wk\",538,weekly,scale3,161,ATO 2026-27 sample data\n";
    echo "\"S2 \$3930.33/mo\",3930.33,monthly,scale2,468,ATO 2026-27 sample data\n";
    exit;
}

if ($action === 'download_template_mla2') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ato-mla2-template.csv"');
    echo "label,gross,period,num_dependants,expected_adjustment,source\n";
    echo "\"MLA2 \$604/wk (spouse only)\",604,weekly,0,7,ATO MLA Scale 2 2026-27 sample data\n";
    echo "\"MLA2 \$604/wk (1 child)\",604,weekly,1,7,ATO MLA Scale 2 2026-27 sample data\n";
    echo "\"MLA2 \$945/wk (spouse only)\",945,weekly,0,15,ATO MLA Scale 2 2026-27 sample data\n";
    echo "\"MLA2 \$1208/fn (spouse only)\",1208,fortnightly,0,14,ATO MLA Scale 2 2026-27 sample data\n";
    echo "\"MLA2 \$3059.33/mo (spouse only)\",3059.33,monthly,0,30,ATO MLA Scale 2 2026-27 sample data\n";
    exit;
}

if ($action === 'download_template_mla6') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ato-mla6-template.csv"');
    echo "label,gross,period,num_children,expected_adjustment,source\n";
    echo "\"MLA6 \$1020/wk (1 child)\",1020,weekly,1,6,ATO MLA Scale 6 2026-27 sample data\n";
    echo "\"MLA6 \$1020/wk (2 children)\",1020,weekly,2,6,ATO MLA Scale 6 2026-27 sample data\n";
    echo "\"MLA6 \$2040/fn (1 child)\",2040,fortnightly,1,12,ATO MLA Scale 6 2026-27 sample data\n";
    echo "\"MLA6 \$4420/mo (1 child)\",4420,monthly,1,26,ATO MLA Scale 6 2026-27 sample data\n";
    exit;
}

if ($action === 'download_template_stsl') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ato-stsl-template.csv"');
    echo "label,gross,period,scale,expected_payg,source\n";
    echo "\"STSL \$116/wk (scale1)\",116,weekly,scale1,19,ATO STSL 2026-27 sample data\n";
    echo "\"STSL \$116/wk (scale2)\",116,weekly,scale2,0,ATO STSL 2026-27 sample data\n";
    echo "\"STSL \$116/wk (scale3)\",116,weekly,scale3,35,ATO STSL 2026-27 sample data\n";
    echo "\"STSL \$938/wk (scale1)\",938,weekly,scale1,215,ATO STSL 2026-27 sample data\n";
    exit;
}

// ── Bundled ATO data file downloads ──────────────────────────────────────────
// fy parameter selects which year's file to serve (e.g. fy=2026-27).
// Add new files to data/ each July — the download card auto-discovers them.
$ato_download_datasets = [
    'download_ato_withholding' => 'withholding',
    'download_ato_mla2'        => 'mla2',
    'download_ato_mla6'        => 'mla6',
    'download_ato_stsl'        => 'stsl',
];
if (isset($ato_download_datasets[$action])) {
    $dataset  = $ato_download_datasets[$action];
    $fy       = preg_replace('/[^0-9-]/', '', GETPOST('fy', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy)) {
        http_response_code(400);
        echo 'Invalid FY.';
        exit;
    }
    $filename = 'ato-' . $dataset . '-' . $fy . '.csv';
    $path     = dol_buildpath('/custom/payroll/data/' . $filename, 0);
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'File not found: ' . htmlspecialchars($filename);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Bundled tax-table file downloads ─────────────────────────────────────────
// Serves tax-coeff-*.csv, tax-mla-*.csv, tax-stsl-*.csv from data/.
// fy parameter selects which year's file to serve (e.g. fy=2026-27).
$taxtable_download_datasets = [
    'download_taxtable_coeff' => 'tax-coeff',
    'download_taxtable_mla'   => 'tax-mla',
    'download_taxtable_stsl'  => 'tax-stsl',
];
if (isset($taxtable_download_datasets[$action])) {
    $prefix = $taxtable_download_datasets[$action];
    $fy     = preg_replace('/[^0-9-]/', '', GETPOST('fy', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy)) {
        http_response_code(400);
        echo 'Invalid FY.';
        exit;
    }
    $filename = $prefix . '-' . $fy . '.csv';
    $path     = dol_buildpath('/custom/payroll/data/' . $filename, 0);
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'File not found: ' . htmlspecialchars($filename);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function payroll_fy_options($db, $conf)
{
    $opts = [];
    $res  = $db->query("SELECT fy FROM " . MAIN_DB_PREFIX . "payroll_fy_config"
        . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC");
    while ($obj = $db->fetch_object($res)) {
        $opts[$obj->fy] = $obj->fy;
    }
    return $opts ?: ['2024-25' => '2024-25', '2025-26' => '2025-26'];
}

function payroll_scale_options()
{
    return [
        'scale1' => 'Scale 1 — Resident, no tax-free threshold',
        'scale2' => 'Scale 2 — Resident, TFT claimed (most employees)',
        'scale3' => 'Scale 3 — Foreign resident',
        'scale4' => 'Scale 4 — No TFN',
        'scale5' => 'Scale 5 — Full Medicare levy exemption (TFT claimed)',
        'scale6' => 'Scale 6 — Half Medicare levy exemption',
    ];
}

/**
 * Scan data/ for bundled ATO CSVs matching ato-{dataset}-{FY}.csv.
 * Returns a sorted array of FY strings, e.g. ['2026-27', '2027-28'].
 * New years appear automatically once the file is dropped into data/.
 */
function payroll_ato_available_years($dataset)
{
    $dir   = dol_buildpath('/custom/payroll/data', 0);
    $years = [];
    foreach (glob($dir . '/ato-' . $dataset . '-*.csv') ?: [] as $f) {
        if (preg_match('/ato-' . preg_quote($dataset, '/') . '-(\d{4}-\d{2})\.csv$/', basename($f), $m)) {
            $years[] = $m[1];
        }
    }
    sort($years);
    return $years;
}

/**
 * Render the "Bundled ATO data" download card for one dataset section.
 * One year available  → plain download link labelled with that year.
 * Multiple years      → year <select> + Download link; JS updates href on change.
 */
function payroll_bundled_ato_card($base_url, $dataset, $source, $rows_desc, $note = '')
{
    $years  = payroll_ato_available_years($dataset);
    $action = 'download_ato_' . $dataset;
    $uid    = 'ato_' . $dataset;

    echo '<div style="background:#f0f7ff;border:1px solid #b0d0f0;border-radius:4px;padding:1rem;min-width:220px;max-width:280px;">';
    echo '<strong>Bundled ATO data</strong>';
    echo '<p style="font-size:0.85em;color:#555;margin:0.4rem 0 0.75rem;">';
    echo 'Pre-built file from the ATO\'s<br><em>' . $source . '</em>.<br>';
    echo '<span style="color:#888;">' . $rows_desc . '</span>';
    if ($note) echo '<br><em style="color:#999;">' . $note . '</em>';
    echo '</p>';
    if (empty($years)) {
        echo '<span style="font-size:0.85em;color:#999;">No bundled files found in data/.</span>';
    } elseif (count($years) === 1) {
        echo '<a href="' . $base_url . '&amp;action=' . $action . '&amp;fy=' . rawurlencode($years[0]) . '" class="button">'
           . 'Download ' . htmlspecialchars($years[0]) . '</a>';
    } else {
        // Multiple years: select + link; JS updates href on change so no form needed.
        $latest = end($years);
        $js_pfx = addslashes($base_url . '&action=' . $action . '&fy=');
        echo '<div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">';
        echo '<select id="sel_' . $uid . '" style="font-size:0.9em;padding:0.2rem 0.4rem;"'
           . ' onchange="document.getElementById(\'btn_' . $uid . '\').href=\'' . $js_pfx . '\'+encodeURIComponent(this.value);">';
        foreach (array_reverse($years) as $y) {
            echo '<option value="' . htmlspecialchars($y) . '">' . htmlspecialchars($y) . '</option>';
        }
        echo '</select>';
        echo '<a id="btn_' . $uid . '" href="' . $base_url . '&amp;action=' . $action . '&amp;fy=' . rawurlencode($latest) . '" class="button">Download</a>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Seed PAYG coefficients for one FY from the corresponding PHP tax-table file.
 * Reads lib/tax-tables/YYYY-YY.php, deletes existing coefficient rows for that
 * FY, and re-inserts from the PHP arrays.
 */
function payroll_seed_coefficients($db, $conf, $fy)
{
    $fy_map   = ['2024-25' => '2025-26', '2025-26' => '2025-26'];
    $file_key = $fy_map[$fy] ?? $fy;
    $path     = dol_buildpath('/custom/payroll/lib/tax-tables/' . $file_key . '.php', 0);

    if (!file_exists($path)) {
        return ['error' => "Tax table file not found: lib/tax-tables/$file_key.php — create it first.", 'message' => ''];
    }

    $tables  = require $path;
    $seeded  = 0;

    foreach ($tables as $scale => $rows) {
        if (strpos($scale, 'scale') !== 0) {
            continue; // skip hecs_* and any other non-scale keys
        }

        $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
            . " WHERE fy='" . $db->escape($fy) . "'"
            . " AND scale='" . $db->escape($scale) . "'"
            . " AND entity=" . (int)$conf->entity);

        $pos = 10;
        foreach ($rows as $row) {
            [$max_w, $a, $b] = $row;
            // PHP_INT_MAX is used as a sentinel in the PHP files; cap to 9999999 for DB
            if ($max_w >= 9000000000000) {
                $max_w = 9999999;
            }
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
                . " (fy, scale, max_weekly, a_coeff, b_coeff, position, entity)"
                . " VALUES ('" . $db->escape($fy) . "', '" . $db->escape($scale) . "'"
                . ", " . (float)$max_w . ", " . (float)$a . ", " . (float)$b
                . ", $pos, " . (int)$conf->entity . ")");
            $seeded++;
            $pos += 10;
        }
    }

    return [
        'message' => "Seeded $seeded coefficient rows for $fy (source: $file_key.php). TODO: verify all values against the NAT 1004 PDF.",
        'error'   => '',
    ];
}

/**
 * Seed HECS brackets for one FY from the PHP tax-table file.
 * For flat-rate FYs (2024-25): source key hecs_2024_25, format [max_income, rate].
 * For marginal FYs (2025-26+): source key hecs_YYYY_YY, format [threshold, rate, base_amount].
 */
function payroll_seed_hecs($db, $conf, $fy)
{
    $fy_map   = ['2024-25' => '2025-26', '2025-26' => '2025-26'];
    $file_key = $fy_map[$fy] ?? $fy;
    $path     = dol_buildpath('/custom/payroll/lib/tax-tables/' . $file_key . '.php', 0);

    if (!file_exists($path)) {
        return ['error' => "Tax table file not found: lib/tax-tables/$file_key.php", 'message' => ''];
    }

    $tables   = require $path;
    $hecs_key = 'hecs_' . str_replace('-', '_', $fy);

    if (empty($tables[$hecs_key])) {
        return ['error' => "No HECS data found for $fy in $file_key.php (expected key: $hecs_key).", 'message' => ''];
    }

    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
        . " WHERE fy='" . $db->escape($fy) . "' AND entity=" . (int)$conf->entity);

    $brackets = $tables[$hecs_key];
    $seeded   = 0;
    $pos      = 10;
    $prev_to  = 0;

    // Detect system: flat uses 2-element arrays, marginal uses 3-element
    $is_marginal = count($brackets[0]) === 3;

    foreach ($brackets as $i => $bracket) {
        if ($is_marginal) {
            [$income_to, $rate, $base_amount] = $bracket;
            $income_from  = $prev_to;
            $is_flat_total = ($i === count($brackets) - 1) ? 1 : 0;
        } else {
            [$income_to, $rate] = $bracket;
            $income_from  = $prev_to;
            $base_amount  = 0;
            $is_flat_total = 1;
        }

        if ($income_to >= 9000000000000) {
            $income_to = 9999999;
        }

        $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
            . " (fy, income_from, income_to, rate, base_amount, is_flat_total, position, entity)"
            . " VALUES ('" . $db->escape($fy) . "'"
            . ", $income_from, $income_to, $rate, $base_amount, $is_flat_total, $pos, " . (int)$conf->entity . ")");

        $prev_to = $income_to;
        $seeded++;
        $pos += 10;
    }

    return [
        'message' => "Seeded $seeded HECS bracket rows for $fy (source: $file_key.php).",
        'error'   => '',
    ];
}

// ── Handle actions ────────────────────────────────────────────────────────────

// FY config actions
if ($action === 'save_fy') {
    $tab        = 'fy';
    $fy_val     = trim(GETPOST('fy', 'alpha'));
    $sup_r      = (float)str_replace(',', '.', GETPOST('super_rate',  'alpha'));
    $hecs_s     = GETPOST('hecs_system', 'alpha');
    $min_w      = (float)str_replace(',', '.', GETPOST('min_wage',    'alpha'));
    $notes      = trim(GETPOST('notes', 'alphanohtml'));
    $start_date = trim(GETPOST('start_date', 'alpha'));
    $end_date   = trim(GETPOST('end_date',   'alpha'));

    if (!preg_match('/^\d{4}-\d{2}$/', $fy_val)) {
        $error = 'FY must be in YYYY-YY format (e.g. 2025-26).';
    } else {
        // Auto-derive ATO tax-year dates from FY string if not supplied or invalid.
        $start_year = (int)substr($fy_val, 0, 4);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date = $start_year . '-07-01';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $end_date = ($start_year + 1) . '-06-30';
        }

        $notes_sql = $notes ? "'" . $db->escape($notes) . "'" : 'NULL';
        if ($rowid) {
            $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_fy_config SET"
                . " fy='" . $db->escape($fy_val) . "'"
                . ", start_date='" . $db->escape($start_date) . "'"
                . ", end_date='" . $db->escape($end_date) . "'"
                . ", super_rate=$sup_r"
                . ", hecs_system='" . $db->escape($hecs_s) . "'"
                . ", min_wage=$min_w"
                . ", notes=$notes_sql"
                . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
        } else {
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_fy_config"
                . " (fy, start_date, end_date, super_rate, hecs_system, min_wage, notes, entity)"
                . " VALUES ('" . $db->escape($fy_val) . "'"
                . ", '" . $db->escape($start_date) . "', '" . $db->escape($end_date) . "'"
                . ", $sup_r, '" . $db->escape($hecs_s) . "', $min_w, $notes_sql"
                . ", " . (int)$conf->entity . ")");
        }
        header('Location: config.php?tab=fy&saved=1&mainmenu=admintools');
        exit;
    }
}

if ($action === 'delete_fy' && $rowid) {
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_fy_config"
        . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    header('Location: config.php?tab=fy&mainmenu=admintools');
    exit;
}

// Coefficient actions
if ($action === 'seed_coeff') {
    $tab = 'taxtables';
    $r   = payroll_seed_coefficients($db, $conf, GETPOST('fy_seed', 'alpha'));
    $message = $r['message'];
    $error   = $r['error'];
}

if ($action === 'save_coeff') {
    $tab   = 'coeff';
    $fy_v  = trim(GETPOST('fy', 'alpha'));
    $sc_v  = GETPOST('scale', 'alpha');
    $mw_v  = (float)str_replace(',', '.', GETPOST('max_weekly', 'alpha'));
    $a_v   = (float)str_replace(',', '.', GETPOST('a_coeff',    'alpha'));
    $b_v   = (float)str_replace(',', '.', GETPOST('b_coeff',    'alpha'));
    $pos_v = GETPOSTINT('position') ?: 10;
    if ($rowid) {
        $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_tax_coefficient SET"
            . " fy='" . $db->escape($fy_v) . "'"
            . ", scale='" . $db->escape($sc_v) . "'"
            . ", max_weekly=$mw_v, a_coeff=$a_v, b_coeff=$b_v, position=$pos_v"
            . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    } else {
        $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
            . " (fy, scale, max_weekly, a_coeff, b_coeff, position, entity)"
            . " VALUES ('" . $db->escape($fy_v) . "', '" . $db->escape($sc_v) . "'"
            . ", $mw_v, $a_v, $b_v, $pos_v, " . (int)$conf->entity . ")");
    }
    header('Location: config.php?tab=taxtables&saved=1&mainmenu=admintools');
    exit;
}

if ($action === 'delete_coeff' && $rowid) {
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
        . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    header('Location: config.php?tab=taxtables&mainmenu=admintools');
    exit;
}

// HECS actions
if ($action === 'seed_hecs') {
    $tab = 'taxtables';
    $r   = payroll_seed_hecs($db, $conf, GETPOST('fy_seed', 'alpha'));
    $message = $r['message'];
    $error   = $r['error'];
}

if ($action === 'save_hecs') {
    $tab   = 'hecs';
    $fy_v  = trim(GETPOST('fy', 'alpha'));
    $fr_v  = (float)str_replace(',', '.', GETPOST('income_from', 'alpha'));
    $to_v  = (float)str_replace(',', '.', GETPOST('income_to',   'alpha'));
    $rt_v  = (float)str_replace(',', '.', GETPOST('rate',        'alpha'));
    $ba_v  = (float)str_replace(',', '.', GETPOST('base_amount', 'alpha'));
    $fl_v  = GETPOSTINT('is_flat_total');
    $pos_v = GETPOSTINT('position') ?: 10;
    if ($rowid) {
        $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_hecs_bracket SET"
            . " fy='" . $db->escape($fy_v) . "'"
            . ", income_from=$fr_v, income_to=$to_v, rate=$rt_v"
            . ", base_amount=$ba_v, is_flat_total=$fl_v, position=$pos_v"
            . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    } else {
        $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
            . " (fy, income_from, income_to, rate, base_amount, is_flat_total, position, entity)"
            . " VALUES ('" . $db->escape($fy_v) . "', $fr_v, $to_v, $rt_v"
            . ", $ba_v, $fl_v, $pos_v, " . (int)$conf->entity . ")");
    }
    header('Location: config.php?tab=taxtables&saved=1&mainmenu=admintools');
    exit;
}

if ($action === 'delete_hecs' && $rowid) {
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
        . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    header('Location: config.php?tab=taxtables&mainmenu=admintools');
    exit;
}

// ── MLA params CRUD ──────────────────────────────────────────────────────────
// Seed default MLA param values (2026-27 hardcoded — same as 2025-26 per ATO).
// Accepts ?fy=2026-27 — seeds that FY for both scale2 and scale6.
if ($action === 'seed_mla') {
    $tab    = 'taxtables';
    $fy_s   = trim(GETPOST('fy_seed', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_s)) {
        $error = 'Invalid FY format.';
    } else {
        $defaults = [
            'scale2' => [
                'weekly_threshold' => 538.67, 'mid_threshold' => 673.00,
                'phase_in_rate' => 0.10,  'levy_rate' => 0.02, 'shade_out_rate' => 0.08,
                'annual_base' => 47238,   'annual_per_child' => 4338,
            ],
            'scale6' => [
                'weekly_threshold' => 908.42, 'mid_threshold' => 1135.00,
                'phase_in_rate' => 0.05,  'levy_rate' => 0.01, 'shade_out_rate' => 0.04,
                'annual_base' => 47238,   'annual_per_child' => 4338,
            ],
        ];
        $seeded = 0;
        foreach ($defaults as $sc => $params) {
            foreach ($params as $key => $val) {
                $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_mla_params"
                    . " (fy, scale, param_key, param_value, entity)"
                    . " VALUES ('" . $db->escape($fy_s) . "', '" . $db->escape($sc) . "'"
                    . ", '" . $db->escape($key) . "', $val, " . (int)$conf->entity . ")"
                    . " ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)");
                $seeded++;
            }
        }
        $message = "Seeded $seeded MLA parameter rows for $fy_s (scale2 + scale6)."
            . " These are 2026-27 ATO values — verify against the Medicare levy adjustment page each July.";
    }
}

if ($action === 'save_mla') {
    $tab   = 'taxtables';
    $fy_v  = trim(GETPOST('fy',         'alpha'));
    $sc_v  = trim(GETPOST('scale',      'alpha'));
    $pk_v  = trim(GETPOST('param_key',  'alpha'));
    $pv_v  = (float)str_replace(',', '.', GETPOST('param_value', 'alpha'));
    $valid_scales = ['scale2', 'scale6'];
    $valid_keys   = ['weekly_threshold','mid_threshold','phase_in_rate','levy_rate','shade_out_rate','annual_base','annual_per_child'];
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_v)) {
        $error = 'Invalid FY format.';
    } elseif (!in_array($sc_v, $valid_scales)) {
        $error = 'Scale must be scale2 or scale6.';
    } elseif (!in_array($pk_v, $valid_keys)) {
        $error = 'Invalid param_key.';
    } else {
        if ($rowid) {
            $db->query("UPDATE " . MAIN_DB_PREFIX . "payroll_mla_params SET"
                . " fy='" . $db->escape($fy_v) . "'"
                . ", scale='" . $db->escape($sc_v) . "'"
                . ", param_key='" . $db->escape($pk_v) . "'"
                . ", param_value=$pv_v"
                . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
        } else {
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_mla_params"
                . " (fy, scale, param_key, param_value, entity)"
                . " VALUES ('" . $db->escape($fy_v) . "', '" . $db->escape($sc_v) . "'"
                . ", '" . $db->escape($pk_v) . "', $pv_v, " . (int)$conf->entity . ")"
                . " ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)");
        }
        header('Location: config.php?tab=taxtables&saved=1&mainmenu=admintools');
        exit;
    }
}

if ($action === 'delete_mla' && $rowid) {
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_mla_params"
        . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
    header('Location: config.php?tab=taxtables&mainmenu=admintools');
    exit;
}

// ── CSV import: PAYG coefficients ─────────────────────────────────────────────
if ($action === 'import_coeff') {
    $tab    = 'taxtables';
    $fy_imp = trim(GETPOST('fy_import', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_imp)) {
        $error = 'Invalid FY format — expected YYYY-YY.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or upload error (code ' . ($_FILES['csv_file']['error'] ?? '?') . ').';
    } else {
        $valid_scales = ['scale1', 'scale2', 'scale3', 'scale4', 'scale5', 'scale6'];
        $fp      = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($fp);
        if ($headers !== false) {
            // Strip UTF-8 BOM from first column if Excel added it
            if (isset($headers[0]) && substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
                $headers[0] = substr($headers[0], 3);
            }
            $headers = array_map('trim', $headers);
        }
        $expected = ['scale', 'position', 'max_weekly', 'a_coeff', 'b_coeff'];
        if ($headers !== $expected) {
            $error = 'CSV headers must be: ' . implode(',', $expected) . '<br>Got: ' . implode(',', (array)$headers);
        } else {
            $import_rows = [];
            $row_errors  = [];
            $rownum = 1;
            while (($row = fgetcsv($fp)) !== false) {
                $rownum++;
                if (count($row) < 5) {
                    $row_errors[] = "Row $rownum: not enough columns.";
                    continue;
                }
                [$sc, $pos, $mw, $a, $b] = array_map('trim', $row);
                $sc  = strtolower($sc);
                $pos = (int)$pos;
                $mw  = (float)str_replace(',', '.', $mw);
                $a   = (float)str_replace(',', '.', $a);
                $b   = (float)str_replace(',', '.', $b);
                if (!in_array($sc, $valid_scales)) {
                    $row_errors[] = "Row $rownum: unknown scale '$sc' (must be scale1–scale6).";
                    continue;
                }
                if ($pos < 1) {
                    $row_errors[] = "Row $rownum: position must be ≥ 1.";
                    continue;
                }
                if ($mw <= 0) {
                    $row_errors[] = "Row $rownum: max_weekly must be > 0 (use 9999999 for the top bracket).";
                    continue;
                }
                $import_rows[] = [$sc, $pos, $mw, $a, $b];
            }
            fclose($fp);

            if ($row_errors) {
                $error = implode('<br>', array_map('htmlspecialchars', $row_errors));
            } elseif (empty($import_rows)) {
                $error = 'No data rows found in the CSV.';
            } else {
                // Replace ALL coefficient rows for this FY (all scales present in file)
                $imported_scales = array_unique(array_column($import_rows, 0));
                foreach ($imported_scales as $sc) {
                    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
                        . " WHERE fy='" . $db->escape($fy_imp) . "'"
                        . " AND scale='" . $db->escape($sc) . "'"
                        . " AND entity=" . (int)$conf->entity);
                }
                foreach ($import_rows as $r) {
                    [$sc, $pos, $mw, $a, $b] = $r;
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
                        . " (fy, scale, max_weekly, a_coeff, b_coeff, position, entity)"
                        . " VALUES ('" . $db->escape($fy_imp) . "', '" . $db->escape($sc) . "'"
                        . ", $mw, $a, $b, $pos, " . (int)$conf->entity . ")");
                }
                $message = 'Imported ' . count($import_rows) . ' coefficient rows for ' . $fy_imp
                    . ' (' . implode(', ', $imported_scales) . ').';
            }
        }
    }
}

// ── CSV import: HECS brackets ─────────────────────────────────────────────────
if ($action === 'import_hecs') {
    $tab    = 'taxtables';
    $fy_imp = trim(GETPOST('fy_import', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_imp)) {
        $error = 'Invalid FY format — expected YYYY-YY.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or upload error (code ' . ($_FILES['csv_file']['error'] ?? '?') . ').';
    } else {
        $fp      = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($fp);
        if ($headers !== false) {
            if (isset($headers[0]) && substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
                $headers[0] = substr($headers[0], 3);
            }
            $headers = array_map('trim', $headers);
        }
        $expected = ['position', 'income_from', 'income_to', 'rate', 'base_amount', 'is_flat_total'];
        if ($headers !== $expected) {
            $error = 'CSV headers must be: ' . implode(',', $expected) . '<br>Got: ' . implode(',', (array)$headers);
        } else {
            $import_rows = [];
            $row_errors  = [];
            $rownum = 1;
            while (($row = fgetcsv($fp)) !== false) {
                $rownum++;
                if (count($row) < 6) {
                    $row_errors[] = "Row $rownum: not enough columns.";
                    continue;
                }
                [$pos, $from, $to, $rate, $base, $flat] = array_map('trim', $row);
                $pos  = (int)$pos;
                $from = (float)str_replace(',', '.', $from);
                $to   = (float)str_replace(',', '.', $to);
                $rate = (float)str_replace(',', '.', $rate);
                $base = (float)str_replace(',', '.', $base);
                $flat = (int)$flat;
                if ($pos < 1) {
                    $row_errors[] = "Row $rownum: position must be ≥ 1.";
                    continue;
                }
                if ($to <= 0) {
                    $row_errors[] = "Row $rownum: income_to must be > 0 (use 9999999 for the top bracket).";
                    continue;
                }
                if ($rate < 0 || $rate > 1) {
                    $row_errors[] = "Row $rownum: rate must be 0–1 (e.g. 0.15 for 15%).";
                    continue;
                }
                $import_rows[] = [$pos, $from, $to, $rate, $base, $flat];
            }
            fclose($fp);

            if ($row_errors) {
                $error = implode('<br>', array_map('htmlspecialchars', $row_errors));
            } elseif (empty($import_rows)) {
                $error = 'No data rows found in the CSV.';
            } else {
                $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
                    . " WHERE fy='" . $db->escape($fy_imp) . "' AND entity=" . (int)$conf->entity);
                foreach ($import_rows as $r) {
                    [$pos, $from, $to, $rate, $base, $flat] = $r;
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
                        . " (fy, income_from, income_to, rate, base_amount, is_flat_total, position, entity)"
                        . " VALUES ('" . $db->escape($fy_imp) . "', $from, $to, $rate, $base, $flat"
                        . ", $pos, " . (int)$conf->entity . ")");
                }
                $message = 'Imported ' . count($import_rows) . ' HECS bracket rows for ' . $fy_imp . '.';
            }
        }
    }
}

// ── CSV import: MLA parameters ────────────────────────────────────────────────
// CSV columns: scale,param_key,param_value  (FY from UI selector)
if ($action === 'import_mla') {
    $tab    = 'taxtables';
    $fy_imp = trim(GETPOST('fy_import', 'alpha'));
    $valid_scales = ['scale2', 'scale6'];
    $valid_keys   = ['weekly_threshold','mid_threshold','phase_in_rate','levy_rate','shade_out_rate','annual_base','annual_per_child'];
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_imp)) {
        $error = 'Invalid FY format — expected YYYY-YY.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or upload error (code ' . ($_FILES['csv_file']['error'] ?? '?') . ').';
    } else {
        $fp      = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $headers = fgetcsv($fp);
        if ($headers !== false) {
            if (isset($headers[0]) && substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
                $headers[0] = substr($headers[0], 3);
            }
            $headers = array_map('trim', $headers);
        }
        $expected = ['scale', 'param_key', 'param_value'];
        if ($headers !== $expected) {
            $error = 'CSV headers must be: ' . implode(',', $expected) . '<br>Got: ' . implode(',', (array)$headers);
        } else {
            $import_rows = [];
            $row_errors  = [];
            $rownum = 1;
            while (($row = fgetcsv($fp)) !== false) {
                $rownum++;
                if (count($row) < 3) { $row_errors[] = "Row $rownum: not enough columns."; continue; }
                [$sc, $pk, $pv] = array_map('trim', $row);
                $sc = strtolower($sc);
                $pk = strtolower($pk);
                $pv = (float)str_replace(',', '.', $pv);
                if (!in_array($sc, $valid_scales)) { $row_errors[] = "Row $rownum: scale must be scale2 or scale6."; continue; }
                if (!in_array($pk, $valid_keys))   { $row_errors[] = "Row $rownum: unknown param_key '$pk'."; continue; }
                $import_rows[] = [$sc, $pk, $pv];
            }
            fclose($fp);
            if ($row_errors) {
                $error = implode('<br>', array_map('htmlspecialchars', $row_errors));
            } elseif (empty($import_rows)) {
                $error = 'No data rows found in the CSV.';
            } else {
                $imported_scales = array_unique(array_column($import_rows, 0));
                foreach ($imported_scales as $sc) {
                    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "payroll_mla_params"
                        . " WHERE fy='" . $db->escape($fy_imp) . "'"
                        . " AND scale='" . $db->escape($sc) . "'"
                        . " AND entity=" . (int)$conf->entity);
                }
                foreach ($import_rows as [$sc, $pk, $pv]) {
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_mla_params"
                        . " (fy, scale, param_key, param_value, entity)"
                        . " VALUES ('" . $db->escape($fy_imp) . "', '" . $db->escape($sc) . "'"
                        . ", '" . $db->escape($pk) . "', $pv, " . (int)$conf->entity . ")");
                }
                $message = 'Imported ' . count($import_rows) . ' MLA parameter rows for ' . $fy_imp
                    . ' (' . implode(', ', $imported_scales) . ').';
            }
        }
    }
}

// ── Delete handlers for each test table ──────────────────────────────────────
$delete_test_tables = [
    'delete_test_withholding' => 'payroll_test_withholding',
    'delete_test_mla2'        => 'payroll_test_mla2',
    'delete_test_mla6'        => 'payroll_test_mla6',
    'delete_test_stsl'        => 'payroll_test_stsl',
];
// Per-row delete (legacy — kept for any manually-entered rows)
foreach ($delete_test_tables as $act => $tbl) {
    if ($action === $act && $rowid) {
        $db->query("DELETE FROM " . MAIN_DB_PREFIX . $tbl
            . " WHERE rowid=$rowid AND entity=" . (int)$conf->entity);
        header('Location: config.php?tab=tests&mainmenu=admintools');
        exit;
    }
}
// Bulk delete by FY (e.g. delete_test_withholding_fy)
foreach ($delete_test_tables as $act => $tbl) {
    if ($action === $act . '_fy') {
        $del_fy = trim(GETPOST('fy', 'alpha'));
        if (preg_match('/^\d{4}-\d{2}$/', $del_fy)) {
            $db->query("DELETE FROM " . MAIN_DB_PREFIX . $tbl
                . " WHERE fy='" . $db->escape($del_fy) . "' AND entity=" . (int)$conf->entity);
        }
        header('Location: config.php?tab=tests&mainmenu=admintools');
        exit;
    }
}

/**
 * Generic CSV import handler for ATO test tables.
 * $action    — e.g. 'import_withholding'
 * $table     — DB table name without prefix
 * $expected  — expected CSV column names
 * $validator — callable($row_fields, $rownum, &$row_errors): ?array of sanitised values
 * $inserter  — callable($db, $conf, $fy, $pos, $fields): void
 */
function payroll_import_test_csv($db, $conf, $action, $table, $expected, $validator, $inserter)
{
    $fy_imp = trim(GETPOST('fy_import', 'alpha'));
    if (!preg_match('/^\d{4}-\d{2}$/', $fy_imp)) {
        return ['', 'Invalid FY format — expected YYYY-YY.'];
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        return ['', 'No file uploaded or upload error (code ' . ($_FILES['csv_file']['error'] ?? '?') . ').'];
    }
    $fp      = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $headers = fgetcsv($fp);
    if ($headers !== false) {
        if (isset($headers[0]) && substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
            $headers[0] = substr($headers[0], 3);
        }
        $headers = array_map('trim', $headers);
    }
    if ($headers !== $expected) {
        fclose($fp);
        return ['', 'CSV headers must be: ' . implode(',', $expected) . '<br>Got: ' . implode(',', (array)$headers)];
    }
    $import_rows = [];
    $row_errors  = [];
    $rownum      = 1;
    while (($row = fgetcsv($fp)) !== false) {
        $rownum++;
        $fields = array_map('trim', $row);
        $result = $validator($fields, $rownum, $row_errors);
        if ($result !== null) {
            $import_rows[] = $result;
        }
    }
    fclose($fp);

    if ($row_errors) {
        return ['', implode('<br>', array_map('htmlspecialchars', $row_errors))];
    }
    if (empty($import_rows)) {
        return ['', 'No data rows found in the CSV.'];
    }

    $db->query("DELETE FROM " . MAIN_DB_PREFIX . $table
        . " WHERE fy='" . $db->escape($fy_imp) . "' AND entity=" . (int)$conf->entity);
    $pos = 10;
    foreach ($import_rows as $r) {
        $inserter($db, $conf, $fy_imp, $pos, $r);
        $pos += 10;
    }
    return ['Imported ' . count($import_rows) . ' rows into ' . $table . ' for ' . $fy_imp . '.', ''];
}

// ── CSV import: Withholding amounts (Schedule 1 / NAT 1004) ──────────────────
if ($action === 'import_withholding') {
    $tab = 'tests';
    $valid_periods = ['weekly', 'fortnightly', 'halfmonthly', 'monthly', 'fourweekly'];
    $valid_scales  = ['scale1', 'scale2', 'scale3', 'scale4', 'scale5', 'scale6'];
    [$message, $error] = payroll_import_test_csv(
        $db, $conf, $action, 'payroll_test_withholding',
        ['label', 'gross', 'period', 'scale', 'expected_payg', 'source'],
        function ($f, $rn, &$errs) use ($valid_periods, $valid_scales) {
            if (count($f) < 6) { $errs[] = "Row $rn: not enough columns."; return null; }
            [$label, $gross, $period, $scale, $expected_payg, $source] = $f;
            $gross         = (float)str_replace(',', '.', $gross);
            $period        = strtolower($period);
            $scale         = strtolower($scale);
            $expected_payg = (int)$expected_payg;
            if ($gross <= 0) { $errs[] = "Row $rn: gross must be > 0."; return null; }
            if (!in_array($period, $valid_periods)) { $errs[] = "Row $rn: unknown period '$period'."; return null; }
            if (!in_array($scale, $valid_scales)) { $errs[] = "Row $rn: unknown scale '$scale'."; return null; }
            return [$label, $gross, $period, $scale, $expected_payg, $source];
        },
        function ($db, $conf, $fy, $pos, $r) {
            [$label, $gross, $period, $scale, $expected_payg, $source] = $r;
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_test_withholding"
                . " (fy, position, label, gross, period, scale, expected_payg, source, entity)"
                . " VALUES ('" . $db->escape($fy) . "', $pos"
                . ", '" . $db->escape($label) . "', $gross"
                . ", '" . $db->escape($period) . "', '" . $db->escape($scale) . "'"
                . ", $expected_payg"
                . ", " . ($source ? "'" . $db->escape($source) . "'" : 'NULL')
                . ", " . (int)$conf->entity . ")");
        }
    );
}

// ── CSV import: MLA Scale 2 ───────────────────────────────────────────────────
if ($action === 'import_mla2') {
    $tab = 'tests';
    $valid_periods = ['weekly', 'fortnightly', 'halfmonthly', 'monthly', 'fourweekly'];
    [$message, $error] = payroll_import_test_csv(
        $db, $conf, $action, 'payroll_test_mla2',
        ['label', 'gross', 'period', 'num_dependants', 'expected_adjustment', 'source'],
        function ($f, $rn, &$errs) use ($valid_periods) {
            if (count($f) < 6) { $errs[] = "Row $rn: not enough columns."; return null; }
            [$label, $gross, $period, $num_deps, $expected_adj, $source] = $f;
            $gross       = (float)str_replace(',', '.', $gross);
            $period      = strtolower($period);
            $num_deps    = (int)$num_deps;
            $expected_adj = (int)$expected_adj;
            if ($gross <= 0) { $errs[] = "Row $rn: gross must be > 0."; return null; }
            if (!in_array($period, $valid_periods)) { $errs[] = "Row $rn: unknown period '$period'."; return null; }
            if ($num_deps < 0 || $num_deps > 5) { $errs[] = "Row $rn: num_dependants must be 0–5."; return null; }
            return [$label, $gross, $period, $num_deps, $expected_adj, $source];
        },
        function ($db, $conf, $fy, $pos, $r) {
            [$label, $gross, $period, $num_deps, $expected_adj, $source] = $r;
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_test_mla2"
                . " (fy, position, label, gross, period, num_dependants, expected_adjustment, source, entity)"
                . " VALUES ('" . $db->escape($fy) . "', $pos"
                . ", '" . $db->escape($label) . "', $gross"
                . ", '" . $db->escape($period) . "'"
                . ", $num_deps, $expected_adj"
                . ", " . ($source ? "'" . $db->escape($source) . "'" : 'NULL')
                . ", " . (int)$conf->entity . ")");
        }
    );
}

// ── CSV import: MLA Scale 6 ───────────────────────────────────────────────────
if ($action === 'import_mla6') {
    $tab = 'tests';
    $valid_periods = ['weekly', 'fortnightly', 'halfmonthly', 'monthly', 'fourweekly'];
    [$message, $error] = payroll_import_test_csv(
        $db, $conf, $action, 'payroll_test_mla6',
        ['label', 'gross', 'period', 'num_children', 'expected_adjustment', 'source'],
        function ($f, $rn, &$errs) use ($valid_periods) {
            if (count($f) < 6) { $errs[] = "Row $rn: not enough columns."; return null; }
            [$label, $gross, $period, $num_ch, $expected_adj, $source] = $f;
            $gross        = (float)str_replace(',', '.', $gross);
            $period       = strtolower($period);
            $num_ch       = (int)$num_ch;
            $expected_adj = (int)$expected_adj;
            if ($gross <= 0) { $errs[] = "Row $rn: gross must be > 0."; return null; }
            if (!in_array($period, $valid_periods)) { $errs[] = "Row $rn: unknown period '$period'."; return null; }
            if ($num_ch < 1 || $num_ch > 5) { $errs[] = "Row $rn: num_children must be 1–5."; return null; }
            return [$label, $gross, $period, $num_ch, $expected_adj, $source];
        },
        function ($db, $conf, $fy, $pos, $r) {
            [$label, $gross, $period, $num_ch, $expected_adj, $source] = $r;
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_test_mla6"
                . " (fy, position, label, gross, period, num_children, expected_adjustment, source, entity)"
                . " VALUES ('" . $db->escape($fy) . "', $pos"
                . ", '" . $db->escape($label) . "', $gross"
                . ", '" . $db->escape($period) . "'"
                . ", $num_ch, $expected_adj"
                . ", " . ($source ? "'" . $db->escape($source) . "'" : 'NULL')
                . ", " . (int)$conf->entity . ")");
        }
    );
}

// ── CSV import: STSL (Schedule 8 / NAT 3539) ─────────────────────────────────
if ($action === 'import_stsl') {
    $tab = 'tests';
    $valid_periods = ['weekly', 'fortnightly', 'halfmonthly', 'monthly', 'fourweekly'];
    $valid_scales  = ['scale1', 'scale2', 'scale3', 'scale4', 'scale5', 'scale6'];
    [$message, $error] = payroll_import_test_csv(
        $db, $conf, $action, 'payroll_test_stsl',
        ['label', 'gross', 'period', 'scale', 'expected_payg', 'source'],
        function ($f, $rn, &$errs) use ($valid_periods, $valid_scales) {
            if (count($f) < 6) { $errs[] = "Row $rn: not enough columns."; return null; }
            [$label, $gross, $period, $scale, $expected_payg, $source] = $f;
            $gross         = (float)str_replace(',', '.', $gross);
            $period        = strtolower($period);
            $scale         = strtolower($scale);
            $expected_payg = (int)$expected_payg;
            if ($gross <= 0) { $errs[] = "Row $rn: gross must be > 0."; return null; }
            if (!in_array($period, $valid_periods)) { $errs[] = "Row $rn: unknown period '$period'."; return null; }
            if (!in_array($scale, $valid_scales)) { $errs[] = "Row $rn: unknown scale '$scale'."; return null; }
            return [$label, $gross, $period, $scale, $expected_payg, $source];
        },
        function ($db, $conf, $fy, $pos, $r) {
            [$label, $gross, $period, $scale, $expected_payg, $source] = $r;
            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_test_stsl"
                . " (fy, position, label, gross, period, scale, expected_payg, source, entity)"
                . " VALUES ('" . $db->escape($fy) . "', $pos"
                . ", '" . $db->escape($label) . "', $gross"
                . ", '" . $db->escape($period) . "', '" . $db->escape($scale) . "'"
                . ", $expected_payg"
                . ", " . ($source ? "'" . $db->escape($source) . "'" : 'NULL')
                . ", " . (int)$conf->entity . ")");
        }
    );
}

// ── Load data ─────────────────────────────────────────────────────────────────

$fy_list = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_fy_config"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC");
while ($obj = $db->fetch_object($res)) {
    $fy_list[] = $obj;
}

$coeff_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_tax_coefficient"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, scale, position, max_weekly");
while ($obj = $db->fetch_object($res)) {
    $coeff_rows[] = $obj;
}

$hecs_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_hecs_bracket"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, position, income_from");
while ($obj = $db->fetch_object($res)) {
    $hecs_rows[] = $obj;
}

$mla_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_mla_params"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, scale, param_key");
if ($res) {
    while ($obj = $db->fetch_object($res)) {
        $mla_rows[] = $obj;
    }
}

// Load test data for all 4 ATO datasets
$test_wth_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_test_withholding"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, position, rowid");
if ($res) {
    while ($obj = $db->fetch_object($res)) { $test_wth_rows[] = $obj; }
}

$test_mla2_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_test_mla2"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, position, rowid");
if ($res) {
    while ($obj = $db->fetch_object($res)) { $test_mla2_rows[] = $obj; }
}

$test_mla6_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_test_mla6"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, position, rowid");
if ($res) {
    while ($obj = $db->fetch_object($res)) { $test_mla6_rows[] = $obj; }
}

$test_stsl_rows = [];
$res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "payroll_test_stsl"
    . " WHERE entity=" . (int)$conf->entity . " ORDER BY fy DESC, position, rowid");
if ($res) {
    while ($obj = $db->fetch_object($res)) { $test_stsl_rows[] = $obj; }
}

$edit_fy    = null;
$edit_coeff = null;
$edit_hecs  = null;
$edit_mla   = null;
if ($action === 'edit_fy' && $rowid) {
    foreach ($fy_list as $r) {
        if ($r->rowid == $rowid) { $edit_fy = $r; $tab = 'fy'; break; }
    }
}
if ($action === 'edit_coeff' && $rowid) {
    foreach ($coeff_rows as $r) {
        if ($r->rowid == $rowid) { $edit_coeff = $r; $tab = 'taxtables'; break; }
    }
}
if ($action === 'edit_hecs' && $rowid) {
    foreach ($hecs_rows as $r) {
        if ($r->rowid == $rowid) { $edit_hecs = $r; $tab = 'taxtables'; break; }
    }
}
if ($action === 'edit_mla' && $rowid) {
    foreach ($mla_rows as $r) {
        if ($r->rowid == $rowid) { $edit_mla = $r; $tab = 'taxtables'; break; }
    }
}

$fy_options    = payroll_fy_options($db, $conf);
$scale_options = payroll_scale_options();

function payroll_select($name, $options, $selected, $class = 'flat')
{
    echo '<select name="' . $name . '" class="' . $class . '">';
    foreach ($options as $val => $lbl) {
        $sel = ($val == $selected) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($lbl) . '</option>';
    }
    echo '</select>';
}

/** FY select with an "add a new year" hint link after the dropdown. */
function payroll_fy_select($name, $fy_options, $default)
{
    payroll_select($name, $fy_options, $default);
    echo ' <small style="margin-left:0.3rem;white-space:nowrap;">'
       . '<a href="config.php?tab=fy&amp;mainmenu=admintools" style="color:#888;" title="Manage financial years">'
       . '+ add year</a></small>';
}

// ── Output ────────────────────────────────────────────────────────────────────

llxHeader('', 'Payroll Config');

$base_url = 'config.php?mainmenu=admintools';
?>
<div class="fiche">
<h1>Payroll Module — Configuration</h1>

<?php if (GETPOST('saved')): ?>
  <div class="alert alert-success" style="margin:0 0 1rem;">Saved.</div>
<?php endif; ?>
<?php if ($message): ?>
  <div class="alert alert-success" style="margin:0 0 1rem;"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger" style="margin:0 0 1rem;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php
// ── Tab navigation ────────────────────────────────────────────────────────────
$tabs = [
    'fy'         => 'Financial Years',
    'taxtables'  => 'Tax Tables',
    'tests'      => 'Verification Tests',
];
echo '<ul class="tabs" role="tablist" style="margin-bottom:1.5rem;">';
foreach ($tabs as $t => $label) {
    $active = ($tab === $t) ? ' active' : '';
    echo '<li class="tab' . $active . '"><a href="' . $base_url . '&tab=' . $t . '">' . htmlspecialchars($label) . '</a></li>';
}
echo '</ul>';
?>

<?php // ===================================================================
      // TAB: Financial Years
      if ($tab === 'fy'):
?>
<h2>Financial Year Settings</h2>
<p>One row per financial year. Controls the SGC super rate, minimum wage reference, and which HECS calculation
   system to use (flat rate up to 2024-25; marginal from 2025-26).</p>

<table class="noborder" style="width:100%;max-width:900px;margin-bottom:2rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.5rem 1rem;text-align:left;">FY</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Period</th>
      <th style="padding:0.5rem 1rem;text-align:right;">Super rate</th>
      <th style="padding:0.5rem 1rem;text-align:left;">HECS system</th>
      <th style="padding:0.5rem 1rem;text-align:right;">Min wage ($/hr)</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Notes</th>
      <th style="padding:0.5rem 1rem;"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($fy_list as $fy_obj): ?>
    <tr style="border-top:1px solid #eee;">
      <td style="padding:0.4rem 1rem;font-weight:600;"><?= htmlspecialchars($fy_obj->fy) ?></td>
      <td style="padding:0.4rem 1rem;font-size:0.85em;color:#555;">
        <?php if ($fy_obj->start_date && $fy_obj->end_date): ?>
          <?= date('d/m/Y', strtotime($fy_obj->start_date)) ?> – <?= date('d/m/Y', strtotime($fy_obj->end_date)) ?>
        <?php else: ?>
          <span style="color:#c00;">No dates set</span>
        <?php endif; ?>
      </td>
      <td style="padding:0.4rem 1rem;text-align:right;"><?= number_format($fy_obj->super_rate, 2) ?>%</td>
      <td style="padding:0.4rem 1rem;">
        <?= $fy_obj->hecs_system === 'marginal'
            ? '<span style="color:#1a7cb8;">Marginal (2025-26+)</span>'
            : 'Flat rate (pre-2025-26)' ?>
      </td>
      <td style="padding:0.4rem 1rem;text-align:right;">$<?= number_format($fy_obj->min_wage, 2) ?></td>
      <td style="padding:0.4rem 1rem;font-size:0.85em;color:#666;"><?= htmlspecialchars($fy_obj->notes ?? '') ?></td>
      <td style="padding:0.4rem 1rem;white-space:nowrap;">
        <a href="<?= $base_url ?>&tab=fy&action=edit_fy&rowid=<?= $fy_obj->rowid ?>">Edit</a>
        &nbsp;
        <a href="<?= $base_url ?>&tab=fy&action=delete_fy&rowid=<?= $fy_obj->rowid ?>&token=<?= newToken() ?>"
           onclick="return confirm('Delete FY <?= htmlspecialchars($fy_obj->fy) ?>?')">Del</a>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($fy_list)): ?>
    <tr><td colspan="7" style="padding:1rem;color:#888;">No financial years configured yet. Add one below.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<h3><?= $edit_fy ? 'Edit: ' . htmlspecialchars($edit_fy->fy) : 'Add financial year' ?></h3>
<form method="post" action="<?= $base_url ?>&tab=fy">
<input type="hidden" name="token"  value="<?= newToken() ?>">
<input type="hidden" name="action" value="save_fy">
<?php if ($edit_fy): ?>
<input type="hidden" name="rowid" value="<?= (int)$edit_fy->rowid ?>">
<?php endif; ?>
<table class="noborder" style="max-width:600px;">
  <tr>
    <td style="padding:0.5rem 1rem;width:180px;"><strong>Financial year</strong></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="fy" id="fy_input" value="<?= htmlspecialchars($edit_fy->fy ?? '') ?>"
             placeholder="2026-27" maxlength="10" style="width:100px;" class="flat" required
             oninput="payrollAutoFillDates(this.value)">
      <small style="color:#888;margin-left:0.5rem;">Format: YYYY-YY</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>Period start</strong></td>
    <td style="padding:0.5rem 1rem;">
      <input type="date" name="start_date" id="fy_start" value="<?= htmlspecialchars($edit_fy->start_date ?? '') ?>"
             style="width:150px;" class="flat">
      <small style="color:#888;margin-left:0.5rem;">Auto-filled from FY (1 July) — override if needed</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>Period end</strong></td>
    <td style="padding:0.5rem 1rem;">
      <input type="date" name="end_date" id="fy_end" value="<?= htmlspecialchars($edit_fy->end_date ?? '') ?>"
             style="width:150px;" class="flat">
      <small style="color:#888;margin-left:0.5rem;">Auto-filled from FY (30 June) — override if needed</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>SGC super rate</strong></td>
    <td style="padding:0.5rem 1rem;">
      <input type="number" name="super_rate" value="<?= number_format((float)($edit_fy->super_rate ?? 12.00), 2, '.', '') ?>"
             min="0" max="100" step="0.01" style="width:80px;" class="flat"> %
      <small style="color:#888;margin-left:0.5rem;">11.5% in 2024-25; 12% from 2025-26</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>HECS/HELP system</strong></td>
    <td style="padding:0.5rem 1rem;">
      <?php payroll_select('hecs_system', ['flat' => 'Flat rate on total income (pre-2025-26)', 'marginal' => 'Marginal rate on excess (2025-26+)'], $edit_fy->hecs_system ?? 'flat') ?>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>Min wage ($/hr)</strong></td>
    <td style="padding:0.5rem 1rem;">
      $<input type="number" name="min_wage" value="<?= number_format((float)($edit_fy->min_wage ?? 0), 2, '.', '') ?>"
              min="0" step="0.01" style="width:80px;" class="flat">
      <small style="color:#888;margin-left:0.5rem;">For reference only — check Fair Work each July</small>
    </td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;"><strong>Notes</strong></td>
    <td style="padding:0.5rem 1rem;">
      <input type="text" name="notes" value="<?= htmlspecialchars($edit_fy->notes ?? '') ?>"
             maxlength="255" style="width:300px;" class="flat">
    </td>
  </tr>
</table>
<div style="margin:0.75rem 1rem 2rem;">
  <button type="submit" class="button buttonaction"><?= $edit_fy ? 'Save' : 'Add' ?></button>
  <?php if ($edit_fy): ?>
  &nbsp;<a href="<?= $base_url ?>&tab=fy" class="button">Cancel</a>
  <?php endif; ?>
</div>
</form>
<script>
function payrollAutoFillDates(fy) {
    var m = fy.match(/^(\d{4})-\d{2}$/);
    if (!m) return;
    var y = parseInt(m[1], 10);
    var s = document.getElementById('fy_start');
    var e = document.getElementById('fy_end');
    if (s && !s.value) s.value = y + '-07-01';
    if (e && !e.value) e.value = (y + 1) + '-06-30';
}
</script>

<?php // ===================================================================
      // TAB: Tax Tables
      elseif ($tab === 'taxtables'):

// Group coefficient rows by FY for display
$coeff_by_fy = [];
foreach ($coeff_rows as $cr) {
    $coeff_by_fy[$cr->fy][$cr->scale][] = $cr;
}

// Group MLA rows by FY then scale
$mla_by_fy_scale = [];
foreach ($mla_rows as $mr) {
    $mla_by_fy_scale[$mr->fy][$mr->scale][$mr->param_key] = $mr;
}

// Group HECS/STSL rows by FY
$hecs_by_fy = [];
foreach ($hecs_rows as $hr) {
    $hecs_by_fy[$hr->fy][] = $hr;
}

// Helper: get a tax-table bundled download card
function payroll_taxtable_download_card($base_url, $prefix, $label, $note = '')
{
    $dir   = dol_buildpath('/custom/payroll/data', 0);
    $years = [];
    foreach (glob($dir . '/' . $prefix . '-*.csv') ?: [] as $f) {
        if (preg_match('/' . preg_quote($prefix, '/') . '-(\d{4}-\d{2})\.csv$/', basename($f), $m)) {
            $years[] = $m[1];
        }
    }
    sort($years);
    $action = 'download_taxtable_' . str_replace('tax-', '', $prefix);
    $uid    = 'tt_' . str_replace('-', '_', $prefix);

    echo '<div style="background:#f0f7ff;border:1px solid #b0d0f0;border-radius:4px;padding:1rem;min-width:200px;max-width:260px;">';
    echo '<strong>Bundled ATO data</strong>';
    echo '<p style="font-size:0.85em;color:#555;margin:0.4rem 0 0.75rem;">' . htmlspecialchars($label);
    if ($note) echo '<br><em style="color:#999;">' . htmlspecialchars($note) . '</em>';
    echo '</p>';
    if (empty($years)) {
        echo '<span style="font-size:0.85em;color:#999;">No bundled files in data/.</span>';
    } elseif (count($years) === 1) {
        echo '<a href="' . $base_url . '&amp;action=' . $action . '&amp;fy=' . rawurlencode($years[0]) . '" class="button">'
           . 'Download ' . htmlspecialchars($years[0]) . '</a>';
    } else {
        $latest = end($years);
        $js_pfx = addslashes($base_url . '&action=' . $action . '&fy=');
        echo '<div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">';
        echo '<select id="sel_' . $uid . '" style="font-size:0.9em;padding:0.2rem 0.4rem;"'
           . ' onchange="document.getElementById(\'btn_' . $uid . '\').href=\'' . $js_pfx . '\'+encodeURIComponent(this.value);">';
        foreach (array_reverse($years) as $y) {
            echo '<option value="' . htmlspecialchars($y) . '">' . htmlspecialchars($y) . '</option>';
        }
        echo '</select>';
        echo '<a id="btn_' . $uid . '" href="' . $base_url . '&amp;action=' . $action . '&amp;fy=' . rawurlencode($latest) . '" class="button">Download</a>';
        echo '</div>';
    }
    echo '</div>';
}

$mla_param_labels = [
    'weekly_threshold' => 'Weekly threshold ($)',
    'mid_threshold'    => 'Mid threshold ($)',
    'phase_in_rate'    => 'Phase-in rate',
    'levy_rate'        => 'Levy rate',
    'shade_out_rate'   => 'Shade-out rate',
    'annual_base'      => 'Annual base ($)',
    'annual_per_child' => 'Annual per child ($)',
];
$mla_valid_scales = ['scale2', 'scale6'];
$mla_valid_keys   = array_keys($mla_param_labels);
?>

<h2>Tax Tables</h2>
<p style="max-width:900px;color:#555;">
  ATO-published tables used to calculate PAYG withholding. Update each July from the ATO website.
  All four datasets must be verified against the ATO PDFs before using in a live pay run.
  &nbsp;<a href="https://www.ato.gov.au/tax-rates-and-codes/tax-tables-overview" target="_blank">Tax tables overview ↗</a>
</p>

<?php
// ── SECTION 1: PAYG Coefficients ─────────────────────────────────────────────
?>
<h3 style="border-bottom:2px solid #1a7cb8;padding-bottom:0.3rem;margin-top:2rem;color:#1a7cb8;">
  1. PAYG Withholding Coefficients — Schedule 1
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank" style="color:#1a7cb8;">(NAT 1004) ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:900px;">
  Coefficients for the ATO formula: <code>withholding = round(a × (floor(weekly_gross) + 0.99) − b)</code>.
  If coefficients change each July, update <code>lib/tax-tables/YYYY-YY.php</code> then use Seed, or import a CSV.
</p>

<?php if (empty($coeff_rows)): ?>
<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;max-width:900px;margin-bottom:1rem;">
  No coefficient rows yet. Use <strong>Seed from PHP file</strong> or <strong>Import CSV</strong> below.
</div>
<?php else: ?>
<?php foreach ($coeff_by_fy as $fy_key => $by_scale): ?>
<h4 style="margin-top:1rem;"><?= htmlspecialchars($fy_key) ?></h4>
<table class="noborder" style="width:100%;max-width:900px;margin-bottom:1rem;font-size:0.9em;">
  <thead><tr style="background:#f4f4f4;">
    <th style="padding:0.4rem 0.75rem;text-align:left;">Scale</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Max weekly ($)</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">a coeff</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">b coeff</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Sort</th>
    <th style="padding:0.4rem 0.75rem;"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($by_scale as $scale_key => $rows):
      $scale_lbl = $scale_options[$scale_key] ?? $scale_key;
  ?>
  <tr style="background:#f9f9f9;">
    <td colspan="6" style="padding:0.3rem 0.75rem;font-style:italic;color:#555;font-size:0.85em;">
      <?= htmlspecialchars($scale_lbl) ?>
    </td>
  </tr>
  <?php foreach ($rows as $cr): ?>
  <tr style="border-top:1px solid #eee;">
    <td style="padding:0.3rem 0.75rem;"></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;">
      <?= $cr->max_weekly >= 9999999 ? '∞' : number_format($cr->max_weekly, 2) ?>
    </td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;"><?= number_format($cr->a_coeff, 5) ?></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;"><?= number_format($cr->b_coeff, 4) ?></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;"><?= (int)$cr->position ?></td>
    <td style="padding:0.3rem 0.75rem;white-space:nowrap;">
      <a href="<?= $base_url ?>&tab=taxtables&action=edit_coeff&rowid=<?= $cr->rowid ?>">Edit</a>
      &nbsp;
      <a href="<?= $base_url ?>&tab=taxtables&action=delete_coeff&rowid=<?= $cr->rowid ?>&token=<?= newToken() ?>"
         onclick="return confirm('Delete this row?')">Del</a>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-top:1rem;max-width:960px;">
  <!-- Seed -->
  <div style="background:#f0f7ff;border:1px solid #b0d0f0;border-radius:4px;padding:1rem;min-width:260px;">
    <strong>Seed from PHP file</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.7rem;">
      Reads <code>lib/tax-tables/YYYY-YY.php</code> and replaces coefficient rows for the selected FY.
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="display:flex;gap:0.5rem;align-items:center;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="seed_coeff">
      <?php payroll_fy_select('fy_seed', $fy_options, '2026-27') ?>
      <button type="submit" class="button buttonaction">Seed</button>
    </form>
  </div>
  <!-- Import CSV -->
  <div style="background:#f0fff4;border:1px solid #90c090;border-radius:4px;padding:1rem;min-width:280px;">
    <strong>Import from CSV</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.75rem;">
      Columns: <code>scale,position,max_weekly,a_coeff,b_coeff</code><br>
      <a href="<?= $base_url ?>&action=download_template_coeff">Download template</a>
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" enctype="multipart/form-data">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="import_coeff">
      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <div style="display:flex;gap:0.5rem;align-items:center;"><span style="font-size:0.87em;">FY:</span>
          <?php payroll_fy_select('fy_import', $fy_options, '2026-27') ?>
        </div>
        <input type="file" name="csv_file" accept=".csv" required style="font-size:0.85em;">
        <button type="submit" class="button buttonaction"
                onclick="return confirm('Replace coefficient rows for the selected FY?')">Import CSV</button>
      </div>
    </form>
  </div>
  <!-- Bundled -->
  <?php payroll_taxtable_download_card($base_url, 'tax-coeff', 'Pre-built from lib/tax-tables/ (Scales 1–6, all brackets)') ?>
  <!-- Add/edit row -->
  <div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;min-width:360px;">
    <strong><?= $edit_coeff ? 'Edit coefficient row' : 'Add coefficient row' ?></strong>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="margin-top:0.5rem;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="save_coeff">
      <?php if ($edit_coeff): ?><input type="hidden" name="rowid" value="<?= (int)$edit_coeff->rowid ?>"><?php endif; ?>
      <table><tr>
        <td style="padding:0.3rem 0.5rem;">FY</td>
        <td style="padding:0.3rem 0.5rem;"><?php payroll_fy_select('fy', $fy_options, $edit_coeff->fy ?? '2026-27') ?></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Scale</td>
        <td style="padding:0.3rem 0.5rem;"><?php payroll_select('scale', $scale_options, $edit_coeff->scale ?? 'scale2') ?></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Max weekly $</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="max_weekly" value="<?= htmlspecialchars($edit_coeff->max_weekly ?? '') ?>"
               step="0.01" style="width:100px;" class="flat" placeholder="9999999 for last" required></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">a coeff</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="a_coeff" value="<?= htmlspecialchars($edit_coeff->a_coeff ?? '') ?>"
               step="0.00001" style="width:100px;" class="flat" required></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">b coeff</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="b_coeff" value="<?= htmlspecialchars($edit_coeff->b_coeff ?? '') ?>"
               step="0.0001" style="width:100px;" class="flat" required></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Sort</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="position" value="<?= (int)($edit_coeff->position ?? 10) ?>"
               min="1" style="width:60px;" class="flat"></td>
      </tr></table>
      <div style="margin-top:0.5rem;">
        <button type="submit" class="button buttonaction"><?= $edit_coeff ? 'Save' : 'Add row' ?></button>
        <?php if ($edit_coeff): ?>&nbsp;<a href="<?= $base_url ?>&tab=taxtables" class="button">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php
// ── SECTION 2: MLA Scale 2 ────────────────────────────────────────────────────
?>
<h3 style="border-bottom:2px solid #27ae60;padding-bottom:0.3rem;margin-top:2.5rem;color:#27ae60;">
  2. Medicare Levy Adjustment — Scale 2
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank" style="color:#27ae60;">(NAT 1008) ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:900px;">
  Parameters for the weekly levy adjustment (WLA) formula for Scale 2 employees who have lodged a
  Medicare levy variation declaration. These values are loaded from the DB at pay-run time;
  hardcoded fallback is used if no DB rows exist.
</p>

<?php foreach (['scale2', 'scale6'] as $mla_scale):
    $mla_section_num   = ($mla_scale === 'scale2') ? 2 : 3;
    $mla_section_color = ($mla_scale === 'scale2') ? '#27ae60' : '#8e44ad';
    $mla_nat           = ($mla_scale === 'scale2') ? 'NAT 1008' : 'NAT 1009';
    $mla_section_label = ($mla_scale === 'scale2') ? 'Scale 2' : 'Scale 6 (half Medicare)';

    if ($mla_scale === 'scale6'):
?>
<h3 style="border-bottom:2px solid #8e44ad;padding-bottom:0.3rem;margin-top:2.5rem;color:#8e44ad;">
  3. Medicare Half-Levy Adjustment — Scale 6
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank" style="color:#8e44ad;">(NAT 1009) ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:900px;">
  Same WLA formula as Scale 2 but with different threshold values. For employees with a half
  Medicare levy exemption (e.g. temporary residents from countries with a health agreement).
</p>
<?php endif; ?>

<?php
// Display MLA rows for this scale, grouped by FY
$has_mla_rows = false;
foreach ($mla_by_fy_scale as $fy_key => $by_scale) {
    if (isset($by_scale[$mla_scale])) { $has_mla_rows = true; break; }
}
if (!$has_mla_rows): ?>
<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;max-width:900px;margin-bottom:1rem;">
  No <?= htmlspecialchars($mla_scale) ?> rows yet. Use <strong>Seed defaults</strong> or <strong>Import CSV</strong> below.
</div>
<?php else:
    foreach ($mla_by_fy_scale as $fy_key => $by_scale):
        if (!isset($by_scale[$mla_scale])) continue;
        $params = $by_scale[$mla_scale];
?>
<h4 style="margin-top:1rem;"><?= htmlspecialchars($fy_key) ?></h4>
<table class="noborder" style="max-width:600px;margin-bottom:1rem;font-size:0.9em;">
  <thead><tr style="background:#f4f4f4;">
    <th style="padding:0.4rem 0.75rem;text-align:left;">Parameter</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Value</th>
    <th style="padding:0.4rem 0.75rem;"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($mla_param_labels as $pk => $plabel):
      $mrow = $params[$pk] ?? null;
  ?>
  <tr style="border-top:1px solid #eee;">
    <td style="padding:0.3rem 0.75rem;"><?= htmlspecialchars($plabel) ?> <small style="color:#aaa;">(<?= $pk ?>)</small></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;">
      <?= $mrow ? htmlspecialchars(number_format((float)$mrow->param_value, in_array($pk, ['annual_base','annual_per_child']) ? 0 : 5)) : '<em style="color:#c00;">missing</em>' ?>
    </td>
    <td style="padding:0.3rem 0.75rem;white-space:nowrap;">
      <?php if ($mrow): ?>
      <a href="<?= $base_url ?>&tab=taxtables&action=edit_mla&rowid=<?= $mrow->rowid ?>">Edit</a>
      &nbsp;
      <a href="<?= $base_url ?>&tab=taxtables&action=delete_mla&rowid=<?= $mrow->rowid ?>&token=<?= newToken() ?>"
         onclick="return confirm('Delete this row?')">Del</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; // fy loop
endif; // has_mla_rows ?>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-top:1rem;max-width:960px;">
  <!-- Seed defaults -->
  <div style="background:#f0f7ff;border:1px solid #b0d0f0;border-radius:4px;padding:1rem;min-width:260px;">
    <strong>Seed 2026-27 defaults</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.7rem;">
      Inserts or updates the <?= htmlspecialchars($mla_scale) ?> ATO MLA parameter values for the selected FY.
      Source: ATO Medicare levy adjustment page.
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="display:flex;gap:0.5rem;align-items:center;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="seed_mla">
      <?php payroll_fy_select('fy_seed', $fy_options, '2026-27') ?>
      <button type="submit" class="button buttonaction">Seed</button>
    </form>
  </div>
  <!-- Import CSV -->
  <div style="background:#f0fff4;border:1px solid #90c090;border-radius:4px;padding:1rem;min-width:280px;">
    <strong>Import from CSV</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.75rem;">
      Columns: <code>scale,param_key,param_value</code><br>
      File may include both scale2 and scale6 rows.<br>
      <a href="<?= $base_url ?>&action=download_taxtable_mla&fy=2026-27">Download bundled 2026-27 file</a>
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" enctype="multipart/form-data">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="import_mla">
      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <div style="display:flex;gap:0.5rem;align-items:center;"><span style="font-size:0.87em;">FY:</span>
          <?php payroll_fy_select('fy_import', $fy_options, '2026-27') ?>
        </div>
        <input type="file" name="csv_file" accept=".csv" required style="font-size:0.85em;">
        <button type="submit" class="button buttonaction"
                onclick="return confirm('Replace MLA rows for scales in this file?')">Import CSV</button>
      </div>
    </form>
  </div>
  <!-- Bundled -->
  <?php payroll_taxtable_download_card($base_url, 'tax-mla', 'ATO MLA parameters (scale2 + scale6)') ?>
  <!-- Add/edit single param row -->
  <div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;min-width:320px;">
    <strong><?= $edit_mla ? 'Edit MLA parameter' : 'Add MLA parameter' ?></strong>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="margin-top:0.5rem;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="save_mla">
      <?php if ($edit_mla): ?><input type="hidden" name="rowid" value="<?= (int)$edit_mla->rowid ?>"><?php endif; ?>
      <table><tr>
        <td style="padding:0.3rem 0.5rem;">FY</td>
        <td style="padding:0.3rem 0.5rem;"><?php payroll_fy_select('fy', $fy_options, $edit_mla->fy ?? '2026-27') ?></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Scale</td>
        <td style="padding:0.3rem 0.5rem;">
          <?php payroll_select('scale', ['scale2' => 'Scale 2', 'scale6' => 'Scale 6'], $edit_mla->scale ?? $mla_scale) ?>
        </td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Parameter</td>
        <td style="padding:0.3rem 0.5rem;">
          <?php payroll_select('param_key', $mla_param_labels, $edit_mla->param_key ?? '') ?>
        </td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Value</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="param_value"
               value="<?= htmlspecialchars($edit_mla->param_value ?? '') ?>"
               step="0.00001" style="width:120px;" class="flat" required></td>
      </tr></table>
      <div style="margin-top:0.5rem;">
        <button type="submit" class="button buttonaction"><?= $edit_mla ? 'Save' : 'Add' ?></button>
        <?php if ($edit_mla): ?>&nbsp;<a href="<?= $base_url ?>&tab=taxtables" class="button">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div><!-- close action cards flex container -->
<?php endforeach; // scale loop ?>

<?php
// ── SECTION 4: STSL Brackets ──────────────────────────────────────────────────
?>
<h3 style="border-bottom:2px solid #e67e22;padding-bottom:0.3rem;margin-top:2.5rem;color:#e67e22;">
  4. STSL Brackets — Schedule 8
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank" style="color:#e67e22;">(NAT 3539) ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:900px;">
  STSL (HELP/VSL/SSL/TSL/SFSS) income brackets and repayment rates.
  <strong>Flat-rate system (up to 2024-25):</strong> rate on TOTAL income.
  <strong>Marginal system (2025-26+):</strong> rate on income above threshold; final bracket (is_flat=1) is 10% flat.
</p>

<?php if (empty($hecs_rows)): ?>
<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;max-width:900px;margin-bottom:1rem;">
  No STSL bracket rows yet. Use <strong>Seed from PHP file</strong> or <strong>Import CSV</strong> below.
</div>
<?php else:
    foreach ($hecs_by_fy as $fy_key => $rows):
        $hecs_sys_label = 'flat';
        foreach ($fy_list as $fyc) {
            if ($fyc->fy === $fy_key) { $hecs_sys_label = $fyc->hecs_system; break; }
        }
?>
<h4 style="margin-top:1rem;"><?= htmlspecialchars($fy_key) ?>
  <small style="font-size:0.8em;color:#777;margin-left:0.5rem;">
    <?= $hecs_sys_label === 'marginal' ? 'Marginal system' : 'Flat-rate system' ?>
  </small>
</h4>
<table class="noborder" style="width:100%;max-width:900px;margin-bottom:1rem;font-size:0.9em;">
  <thead><tr style="background:#f4f4f4;">
    <th style="padding:0.4rem 0.75rem;text-align:right;">Income from ($)</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Income to ($)</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Rate</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Base STSL ($)</th>
    <th style="padding:0.4rem 0.75rem;text-align:center;">Flat total?</th>
    <th style="padding:0.4rem 0.75rem;text-align:right;">Sort</th>
    <th style="padding:0.4rem 0.75rem;"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $hr): ?>
  <tr style="border-top:1px solid #eee;">
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;"><?= number_format($hr->income_from, 0) ?></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;">
      <?= $hr->income_to >= 9999999 ? '∞' : number_format($hr->income_to, 0) ?>
    </td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;"><?= number_format($hr->rate * 100, 1) ?>%</td>
    <td style="padding:0.3rem 0.75rem;text-align:right;font-family:monospace;">
      <?= $hr->base_amount > 0 ? '$' . number_format($hr->base_amount, 2) : '—' ?>
    </td>
    <td style="padding:0.3rem 0.75rem;text-align:center;"><?= $hr->is_flat_total ? '✓' : '' ?></td>
    <td style="padding:0.3rem 0.75rem;text-align:right;"><?= (int)$hr->position ?></td>
    <td style="padding:0.3rem 0.75rem;white-space:nowrap;">
      <a href="<?= $base_url ?>&tab=taxtables&action=edit_hecs&rowid=<?= $hr->rowid ?>">Edit</a>
      &nbsp;
      <a href="<?= $base_url ?>&tab=taxtables&action=delete_hecs&rowid=<?= $hr->rowid ?>&token=<?= newToken() ?>"
         onclick="return confirm('Delete this bracket?')">Del</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-top:1rem;max-width:960px;">
  <!-- Seed -->
  <div style="background:#f0f7ff;border:1px solid #b0d0f0;border-radius:4px;padding:1rem;min-width:260px;">
    <strong>Seed from PHP file</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.7rem;">
      Replaces ALL STSL brackets for the selected FY from <code>lib/tax-tables/YYYY-YY.php</code>.
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="display:flex;gap:0.5rem;align-items:center;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="seed_hecs">
      <?php payroll_fy_select('fy_seed', $fy_options, '2026-27') ?>
      <button type="submit" class="button buttonaction">Seed</button>
    </form>
  </div>
  <!-- Import CSV -->
  <div style="background:#f0fff4;border:1px solid #90c090;border-radius:4px;padding:1rem;min-width:280px;">
    <strong>Import from CSV</strong>
    <p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.75rem;">
      Columns: <code>position,income_from,income_to,rate,base_amount,is_flat_total</code><br>
      Rate as decimal. Replaces ALL brackets for the FY.<br>
      <a href="<?= $base_url ?>&action=download_template_hecs">Download template</a>
    </p>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" enctype="multipart/form-data">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="import_hecs">
      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <div style="display:flex;gap:0.5rem;align-items:center;"><span style="font-size:0.87em;">FY:</span>
          <?php payroll_fy_select('fy_import', $fy_options, '2026-27') ?>
        </div>
        <input type="file" name="csv_file" accept=".csv" required style="font-size:0.85em;">
        <button type="submit" class="button buttonaction"
                onclick="return confirm('Replace ALL STSL brackets for the selected FY?')">Import CSV</button>
      </div>
    </form>
  </div>
  <!-- Bundled -->
  <?php payroll_taxtable_download_card($base_url, 'tax-stsl', 'Pre-built STSL brackets from lib/tax-tables/') ?>
  <!-- Add/edit bracket row -->
  <div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:1rem;min-width:360px;">
    <strong><?= $edit_hecs ? 'Edit STSL bracket' : 'Add STSL bracket' ?></strong>
    <form method="post" action="<?= $base_url ?>&tab=taxtables" style="margin-top:0.5rem;">
      <input type="hidden" name="token"  value="<?= newToken() ?>">
      <input type="hidden" name="action" value="save_hecs">
      <?php if ($edit_hecs): ?><input type="hidden" name="rowid" value="<?= (int)$edit_hecs->rowid ?>"><?php endif; ?>
      <table><tr>
        <td style="padding:0.3rem 0.5rem;">FY</td>
        <td style="padding:0.3rem 0.5rem;"><?php payroll_fy_select('fy', $fy_options, $edit_hecs->fy ?? '2026-27') ?></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Income from $</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="income_from" value="<?= htmlspecialchars($edit_hecs->income_from ?? '0') ?>"
               step="1" style="width:100px;" class="flat" required></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Income to $ <small>(9999999=∞)</small></td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="income_to" value="<?= htmlspecialchars($edit_hecs->income_to ?? '') ?>"
               step="1" style="width:100px;" class="flat" required></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Rate (decimal)</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="rate" value="<?= htmlspecialchars($edit_hecs->rate ?? '0') ?>"
               step="0.00001" min="0" max="1" style="width:90px;" class="flat" required>
          <small style="color:#888;">&nbsp;e.g. 0.10 = 10%</small></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Base STSL $</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="base_amount" value="<?= htmlspecialchars($edit_hecs->base_amount ?? '0') ?>"
               step="0.01" min="0" style="width:90px;" class="flat"></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Flat on total?</td>
        <td style="padding:0.3rem 0.5rem;"><label>
          <input type="checkbox" name="is_flat_total" value="1" <?= ($edit_hecs->is_flat_total ?? 0) ? 'checked' : '' ?>>
          Rate applies to TOTAL income
        </label></td>
      </tr><tr>
        <td style="padding:0.3rem 0.5rem;">Sort</td>
        <td style="padding:0.3rem 0.5rem;"><input type="number" name="position" value="<?= (int)($edit_hecs->position ?? 10) ?>"
               min="1" style="width:60px;" class="flat"></td>
      </tr></table>
      <div style="margin-top:0.5rem;">
        <button type="submit" class="button buttonaction"><?= $edit_hecs ? 'Save' : 'Add row' ?></button>
        <?php if ($edit_hecs): ?>&nbsp;<a href="<?= $base_url ?>&tab=taxtables" class="button">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php // ===================================================================
      // TAB: Verification Tests
      elseif ($tab === 'tests'):

$period_labels = [
    'weekly' => 'Weekly', 'fortnightly' => 'Fortnightly',
    'halfmonthly' => 'Half-monthly', 'monthly' => 'Monthly', 'fourweekly' => 'Four-weekly',
];

// Helper: group an array of row objects by FY
function group_by_fy($rows)
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r->fy][] = $r;
    }
    return $out;
}

// Helper: "Run Tests" card — triggers AJAX run of calculator against stored test rows
function payroll_run_tests_card($base_url, $dataset)
{
    $id = 'run-results-' . htmlspecialchars($dataset, ENT_QUOTES);
    echo '<div style="background:#f4f8fc;border:1px solid #cce0f0;border-radius:4px;padding:0.75rem 1rem;min-width:220px;">';
    echo '<div style="font-size:0.8em;font-weight:600;color:#1a7cb8;margin-bottom:0.5rem;">Run Tests</div>';
    echo '<button type="button"'
        . ' onclick="payrollRunTests(\'' . $dataset . '\', \'' . addslashes($base_url) . '\', document.getElementById(\'' . $id . '\'))"'
        . ' style="background:#1a7cb8;color:#fff;border:none;border-radius:3px;padding:0.35rem 0.8rem;cursor:pointer;font-size:0.85em;">'
        . '&#9654; Run all tests</button>';
    echo '<div id="' . $id . '" style="margin-top:0.5rem;font-size:0.82em;max-width:700px;"></div>';
    echo '</div>';
}

// Helper: import form card
function payroll_test_import_card($base_url, $fy_options, $action_name, $dl_action, $label, $desc)
{
    $default_fy = $fy_options ? array_key_first($fy_options) : '2026-27';
    echo '<div style="background:#f0fff4;border:1px solid #90c090;border-radius:4px;padding:1rem;min-width:300px;max-width:420px;">';
    echo '<strong>Import CSV</strong>';
    echo '<p style="font-size:0.87em;color:#444;margin:0.4rem 0 0.6rem;">' . $desc;
    echo '<br><a href="' . $base_url . '&amp;action=' . $dl_action . '">Download template</a></p>';
    echo '<form method="post" action="' . $base_url . '&amp;tab=tests" enctype="multipart/form-data">';
    echo '<input type="hidden" name="token"  value="' . newToken() . '">';
    echo '<input type="hidden" name="action" value="' . $action_name . '">';
    echo '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
    echo '<div style="display:flex;gap:0.5rem;align-items:center;"><span style="font-size:0.87em;">FY:</span>';
    payroll_fy_select('fy_import', $fy_options, $default_fy);
    echo '</div>';
    echo '<input type="file" name="csv_file" accept=".csv" required style="font-size:0.85em;">';
    echo '<div><button type="submit" class="button buttonaction"'
        . ' onclick="return confirm(\'Replace ALL ' . htmlspecialchars($label) . ' rows for the selected FY?\')">Import</button></div>';
    echo '</div></form></div>';
}

// Helper: compact imported-data summary — FY chip with row count and a Clear button
function payroll_test_data_table($rows_by_fy, $base_url, $del_action, $headers, $row_cells, $period_labels)
{
    if (empty($rows_by_fy)) {
        echo '<p style="color:#888;font-size:0.88em;margin:0.25rem 0 0.5rem;">No data imported yet.</p>';
        return;
    }
    echo '<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.5rem 0 1rem;">';
    foreach ($rows_by_fy as $fy_key => $rows) {
        $count   = count($rows);
        $del_url = $base_url . '&amp;tab=tests&amp;action=' . $del_action . '_fy'
            . '&amp;fy=' . urlencode($fy_key) . '&amp;token=' . newToken();
        echo '<div style="display:flex;align-items:center;gap:0.6rem;background:#eaf4fb;'
            . 'border:1px solid #b3d8ee;border-radius:4px;padding:0.3rem 0.75rem;font-size:0.88em;">';
        echo '<span style="font-weight:600;">' . htmlspecialchars($fy_key) . '</span>';
        echo '<span style="color:#555;">' . number_format($count) . ' rows</span>';
        echo '<a href="' . $del_url . '"'
            . ' onclick="return confirm(\'Clear all ' . $count . ' rows for '
            . htmlspecialchars($fy_key, ENT_QUOTES) . '?\')"'
            . ' style="color:#c0392b;font-size:0.85em;text-decoration:none;" title="Remove all imported rows for this FY">'
            . '&#10005; Clear</a>';
        echo '</div>';
    }
    echo '</div>';
}
?>

<h2>ATO PAYG Verification Test Data</h2>

<div style="background:#fffbe6;border:1px solid #f0d080;border-radius:4px;padding:0.75rem 1rem;margin-bottom:1.5rem;max-width:960px;">
  Import the ATO's published sample data for each dataset below. After importing, go to
  <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup">Payroll Setup</a>
  to run pass/fail verification before doing a live pay run.
  The ATO publishes updated sample data each June alongside the tax table formulas.
  <strong>All four datasets for 2026-27 were published 17 June 2026.</strong>
  <br>
  <table style="margin-top:0.6rem;font-size:0.87em;border-collapse:collapse;">
    <tr>
      <td style="padding:0.2rem 0.8rem 0.2rem 0;white-space:nowrap;">1. Withholding amounts (NAT 1004)</td>
      <td><a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank">ATO page</a></td>
    </tr>
    <tr>
      <td style="padding:0.2rem 0.8rem 0.2rem 0;white-space:nowrap;">2. Medicare levy adjustment — Scale 2 (NAT 1008)</td>
      <td><a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank">ATO page</a></td>
    </tr>
    <tr>
      <td style="padding:0.2rem 0.8rem 0.2rem 0;white-space:nowrap;">3. Medicare half-levy adjustment — Scale 6 (NAT 1009)</td>
      <td><a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank">ATO page</a></td>
    </tr>
    <tr>
      <td style="padding:0.2rem 0.8rem 0.2rem 0;white-space:nowrap;">4. STSL — Schedule 8 (NAT 3539)</td>
      <td><a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank">ATO page</a></td>
    </tr>
  </table>
</div>

<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:0.75rem 1rem;margin-bottom:1.5rem;max-width:900px;">
  <strong>ATO Tax Tables — Quick Links</strong>
  &nbsp;&nbsp;
  <a href="https://www.ato.gov.au/tax-rates-and-codes/tax-tables-overview" target="_blank">
    tax-tables-overview ↗
  </a>
  <span style="color:#888;font-size:0.85em;margin-left:0.5rem;">— start here to find formula pages and sample data download pages for each schedule</span>
</div>

<?php
// ── 1. Withholding amounts ────────────────────────────────────────────────────
$wth_by_fy = group_by_fy($test_wth_rows);
?>
<h3 style="border-bottom:2px solid #1a7cb8;padding-bottom:0.3rem;margin-top:2rem;color:#1a7cb8;display:flex;align-items:baseline;gap:1rem;">
  <span>1. Withholding Amounts — Schedule 1 (NAT 1004)</span>
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank" style="color:#1a7cb8;">ATO formulas ↗</a>
    &nbsp;·&nbsp;<a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld/sample-data/withholding-amounts-sample-data" target="_blank" style="color:#1a7cb8;">Sample data ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:800px;">
  Standard PAYG withholding for Scales 1–3, 5, 6. This is the primary verification dataset —
  results on Payroll Setup show pass/fail for every imported row.
  Format: <code>label, gross, period, scale, expected_payg, source</code>
</p>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
  <?php payroll_test_import_card(
      $base_url, $fy_options, 'import_withholding', 'download_template_withholding',
      'withholding',
      'Columns: <code>label,gross,period,scale,expected_payg,source</code><br>'
      . '<code>scale</code>: scale1–scale6 &nbsp;·&nbsp; <code>period</code>: weekly | fortnightly | monthly'
  ); ?>
  <?php payroll_bundled_ato_card($base_url, 'withholding', 'Withholding amounts sample data', '720 rows — all 5 scales, 3 periods'); ?>
  <?php payroll_run_tests_card($base_url, 'withholding'); ?>
</div>

<?php payroll_test_data_table(
    $wth_by_fy, $base_url, 'delete_test_withholding',
    [['Label'], ['Gross', 'right'], ['Period', 'center'], ['Scale', 'center'], ['Expected PAYG', 'right'], ['Source', 'left']],
    function ($r, $pl) {
        echo '<td style="padding:0.25rem 0.6rem;">' . htmlspecialchars($r->label) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . number_format($r->gross, 2) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . ($pl[$r->period] ?? ucfirst($r->period)) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;font-family:monospace;">' . htmlspecialchars($r->scale) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . (int)$r->expected_payg . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;color:#888;font-size:0.85em;">' . htmlspecialchars($r->source ?? '') . '</td>';
    },
    $period_labels
); ?>

<?php
// ── 2. MLA Scale 2 ───────────────────────────────────────────────────────────
$mla2_by_fy = group_by_fy($test_mla2_rows);
?>
<h3 style="border-bottom:2px solid #27ae60;padding-bottom:0.3rem;margin-top:2.5rem;color:#27ae60;display:flex;align-items:baseline;gap:1rem;">
  <span>2. Medicare Levy Adjustment — Scale 2 (NAT 1008)</span>
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank" style="color:#27ae60;">ATO formulas ↗</a>
    &nbsp;·&nbsp;<a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld/sample-data/medicare-level-adjustment-scale-2-sample-data" target="_blank" style="color:#27ae60;">Sample data ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:800px;">
  Withholding reduction for Scale 2 low-income earners who have lodged a Medicare levy variation
  declaration (spouse and/or children). <code>num_dependants</code>: 0 = spouse only, 1–5 = children.
  Format: <code>label, gross, period, num_dependants, expected_adjustment, source</code>
</p>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
  <?php payroll_test_import_card(
      $base_url, $fy_options, 'import_mla2', 'download_template_mla2',
      'MLA Scale 2',
      'Columns: <code>label,gross,period,num_dependants,expected_adjustment,source</code><br>'
      . '<code>num_dependants</code>: 0=spouse only, 1–5=number of children'
  ); ?>
  <?php payroll_bundled_ato_card($base_url, 'mla2', 'Medicare levy adjustment scale 2 sample data', '864 rows — 3 periods, spouse + 5 child counts'); ?>
  <?php payroll_run_tests_card($base_url, 'mla2'); ?>
</div>

<?php payroll_test_data_table(
    $mla2_by_fy, $base_url, 'delete_test_mla2',
    [['Label'], ['Gross', 'right'], ['Period', 'center'], ['Deps', 'center'], ['Expected adj.', 'right'], ['Source', 'left']],
    function ($r, $pl) {
        $dep_lbl = $r->num_dependants == 0 ? 'spouse' : $r->num_dependants . ' ch';
        echo '<td style="padding:0.25rem 0.6rem;">' . htmlspecialchars($r->label) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . number_format($r->gross, 2) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . ($pl[$r->period] ?? ucfirst($r->period)) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . htmlspecialchars($dep_lbl) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . (int)$r->expected_adjustment . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;color:#888;font-size:0.85em;">' . htmlspecialchars($r->source ?? '') . '</td>';
    },
    $period_labels
); ?>

<?php
// ── 3. MLA Scale 6 ───────────────────────────────────────────────────────────
$mla6_by_fy = group_by_fy($test_mla6_rows);
?>
<h3 style="border-bottom:2px solid #8e44ad;padding-bottom:0.3rem;margin-top:2.5rem;color:#8e44ad;display:flex;align-items:baseline;gap:1rem;">
  <span>3. Medicare Half-Levy Adjustment — Scale 6 (NAT 1009)</span>
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld#ato-Medicarelevyadjustment" target="_blank" style="color:#8e44ad;">ATO formulas ↗</a>
    &nbsp;·&nbsp;<a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld/sample-data/medicare-half-levy-adjustment-scale-6-sample-data" target="_blank" style="color:#8e44ad;">Sample data ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:800px;">
  Withholding reduction for Scale 6 (half Medicare levy exemption) earners with children.
  Format: <code>label, gross, period, num_children, expected_adjustment, source</code>
</p>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
  <?php payroll_test_import_card(
      $base_url, $fy_options, 'import_mla6', 'download_template_mla6',
      'MLA Scale 6',
      'Columns: <code>label,gross,period,num_children,expected_adjustment,source</code><br>'
      . '<code>num_children</code>: 1–5'
  ); ?>
  <?php payroll_bundled_ato_card($base_url, 'mla6', 'Medicare half-levy adjustment scale 6 sample data', '720 rows — 3 periods, 1–5 children'); ?>
  <?php payroll_run_tests_card($base_url, 'mla6'); ?>
</div>

<?php payroll_test_data_table(
    $mla6_by_fy, $base_url, 'delete_test_mla6',
    [['Label'], ['Gross', 'right'], ['Period', 'center'], ['Children', 'center'], ['Expected adj.', 'right'], ['Source', 'left']],
    function ($r, $pl) {
        echo '<td style="padding:0.25rem 0.6rem;">' . htmlspecialchars($r->label) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . number_format($r->gross, 2) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . ($pl[$r->period] ?? ucfirst($r->period)) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . (int)$r->num_children . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . (int)$r->expected_adjustment . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;color:#888;font-size:0.85em;">' . htmlspecialchars($r->source ?? '') . '</td>';
    },
    $period_labels
); ?>

<?php
// ── 4. STSL ───────────────────────────────────────────────────────────────────
$stsl_by_fy = group_by_fy($test_stsl_rows);
?>
<h3 style="border-bottom:2px solid #e67e22;padding-bottom:0.3rem;margin-top:2.5rem;color:#e67e22;display:flex;align-items:baseline;gap:1rem;">
  <span>4. STSL — Schedule 8 (NAT 3539)</span>
  <span style="font-size:0.7em;font-weight:400;">
    <a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank" style="color:#e67e22;">ATO formulas ↗</a>
    &nbsp;·&nbsp;<a href="https://www.ato.gov.au/api/public/content/f9885733974348d3b17aa7e657acaee0?v=9aaf689f" target="_blank" style="color:#e67e22;">Sample data (Excel) ↗</a>
  </span>
</h3>
<p style="font-size:0.88em;max-width:800px;">
  Total withholding (PAYG + STSL component) for employees with a HELP, VSL, SSL, TSL, or SFSS debt.
  The STSL component replaces — not adds to — the base Scale coefficient formula.
  Same column format as Withholding amounts.
  Format: <code>label, gross, period, scale, expected_payg, source</code>
</p>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
  <?php payroll_test_import_card(
      $base_url, $fy_options, 'import_stsl', 'download_template_stsl',
      'STSL',
      'Columns: <code>label,gross,period,scale,expected_payg,source</code><br>'
      . 'Same format as Withholding amounts; <code>scale</code>: scale1–scale3, scale5, scale6'
  ); ?>
  <?php payroll_bundled_ato_card($base_url, 'stsl', 'STSL Sample Data (NAT 3539)', '885 rows — 3 periods, 5 scales (from ATO Excel NAT 3539)'); ?>
  <?php payroll_run_tests_card($base_url, 'stsl'); ?>
</div>

<?php payroll_test_data_table(
    $stsl_by_fy, $base_url, 'delete_test_stsl',
    [['Label'], ['Gross', 'right'], ['Period', 'center'], ['Scale', 'center'], ['Expected PAYG', 'right'], ['Source', 'left']],
    function ($r, $pl) {
        echo '<td style="padding:0.25rem 0.6rem;">' . htmlspecialchars($r->label) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . number_format($r->gross, 2) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;">' . ($pl[$r->period] ?? ucfirst($r->period)) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:center;font-family:monospace;">' . htmlspecialchars($r->scale) . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;text-align:right;font-family:monospace;">$' . (int)$r->expected_payg . '</td>';
        echo '<td style="padding:0.25rem 0.6rem;color:#888;font-size:0.85em;">' . htmlspecialchars($r->source ?? '') . '</td>';
    },
    $period_labels
); ?>

<?php endif; // end tab switch ?>

<script>
function payrollRunTests(dataset, baseUrl, resultEl) {
    resultEl.innerHTML = '<em style="color:#888;">Running…<\/em>';
    var url = baseUrl + '&action=run_tests_' + dataset;
    fetch(url)
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            var html = '';
            var fys  = Object.keys(data.fys);
            if (fys.length === 0) {
                html = '<em style="color:#888;">No test data imported yet — import a bundled CSV first.<\/em>';
            } else {
                fys.forEach(function(fy) {
                    var res   = data.fys[fy];
                    var total = res.pass + res.fail;
                    if (res.fail === 0) {
                        html += '<div style="display:inline-block;background:#d4edda;color:#155724;'
                            + 'padding:0.25rem 0.7rem;border-radius:3px;margin:0.15rem 0.2rem 0.15rem 0;">'
                            + '&#10003; ' + prEsc(fy) + ': all ' + total + ' passed<\/div>';
                    } else {
                        html += '<div style="background:#f8d7da;color:#721c24;padding:0.25rem 0.7rem;'
                            + 'border-radius:3px;margin:0.15rem 0 0.3rem;">'
                            + '&#10007; ' + prEsc(fy) + ': ' + res.fail + ' failed ('
                            + res.pass + '\/' + total + ' passed)<\/div>';
                        html += '<table style="width:100%;border-collapse:collapse;margin-bottom:0.5rem;">'
                            + '<thead><tr style="background:#f4f4f4;">'
                            + '<th style="text-align:left;padding:0.2rem 0.5rem;">Label<\/th>'
                            + '<th style="text-align:right;padding:0.2rem 0.5rem;">Gross<\/th>'
                            + '<th style="text-align:center;padding:0.2rem 0.4rem;">Period<\/th>'
                            + '<th style="text-align:center;padding:0.2rem 0.4rem;">Scale\/Deps<\/th>'
                            + '<th style="text-align:right;padding:0.2rem 0.5rem;">Expected<\/th>'
                            + '<th style="text-align:right;padding:0.2rem 0.5rem;color:#c0392b;">Got<\/th>'
                            + '<th style="text-align:right;padding:0.2rem 0.5rem;color:#c0392b;">Diff<\/th>'
                            + '<\/tr><\/thead><tbody>';
                        res.failures.forEach(function(f) {
                            var scaleOrDeps = f.scale
                                || (f.num_deps !== undefined ? f.num_deps + ' dep' : f.num_children + ' ch');
                            var diff = f.got - f.expected;
                            var sign = diff >= 0 ? '+' : '';
                            html += '<tr style="background:#fff3f3;">'
                                + '<td style="padding:0.15rem 0.5rem;">' + prEsc(f.label) + '<\/td>'
                                + '<td style="text-align:right;padding:0.15rem 0.5rem;font-family:monospace;">$'
                                + f.gross.toFixed(2) + '<\/td>'
                                + '<td style="text-align:center;padding:0.15rem 0.4rem;">' + prEsc(f.period) + '<\/td>'
                                + '<td style="text-align:center;padding:0.15rem 0.4rem;font-family:monospace;">' + prEsc(scaleOrDeps) + '<\/td>'
                                + '<td style="text-align:right;padding:0.15rem 0.5rem;font-family:monospace;">$' + f.expected + '<\/td>'
                                + '<td style="text-align:right;padding:0.15rem 0.5rem;font-family:monospace;color:#c0392b;">$' + f.got + '<\/td>'
                                + '<td style="text-align:right;padding:0.15rem 0.5rem;font-family:monospace;color:#c0392b;">'
                                + sign + diff + '<\/td><\/tr>';
                        });
                        html += '<\/tbody><\/table>';
                    }
                });
            }
            resultEl.innerHTML = html;
        })
        .catch(function(e) {
            resultEl.innerHTML = '<span style="color:#c0392b;">Error: ' + prEsc(e.message) + '<\/span>';
        });
}
function prEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<div style="margin-top:2rem;padding-top:1rem;border-top:1px solid #eee;">
  <a href="setup.php?mainmenu=billing&leftmenu=payroll_setup" class="button">Payroll Setup (deductions)</a>
  &nbsp;
  <a href="employees.php?mainmenu=billing&leftmenu=payroll_employees" class="button">Payroll Employees</a>
  &nbsp;
  <a href="../help/payroll.php?mainmenu=billing&leftmenu=payroll_manual" class="button">Payroll Manual</a>
</div>

</div>
<?php llxFooter(); ?>
