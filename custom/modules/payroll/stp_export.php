<?php
/**
 * STP YTD Export — generates a CSV of per-employee Year-to-Date payroll totals
 * in the format required for STP Phase 2 PAYEVNT submission via an SSP.
 *
 * File upload model: download this CSV, upload to your SSP's portal.
 * Direct API model (future): SSP integration to be added once SSP is confirmed.
 *
 * See docs/howto/stp-ssp.md for the STP/SSP research and transition plan.
 */

require '../../main.inc.php';
require_once __DIR__ . '/lib/TfnHelper.php';

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$fy     = GETPOST('fy', 'alpha');

// ── Employment basis mapping (position_type → STP Phase 2 code) ──────────────
// F=Full time, P=Part time, C=Casual, L=Labour hire, V=Volunteer, D=Death benefit, N=Non-employee
$basis_map = [
    'FT'   => 'F',
    'FTT'  => 'F',
    'PT'   => 'P',
    'CA'   => 'C',
    'CAPT' => 'C',
    'AP'   => 'P',
    'O'    => 'P',
];

$basis_labels = [
    'F' => 'Full time',
    'P' => 'Part time',
    'C' => 'Casual',
];

// ── Available FYs ─────────────────────────────────────────────────────────────
$res_fys = $db->query("SELECT DISTINCT fy FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
    . " WHERE entity = " . (int)$conf->entity . " ORDER BY fy DESC");
$avail_fys = [];
while ($obj = $db->fetch_object($res_fys)) {
    $avail_fys[] = $obj->fy;
}
if (!$fy && !empty($avail_fys)) {
    $fy = $avail_fys[0]; // default to most recent FY
}

// ── CSV download (early exit) ─────────────────────────────────────────────────
if ($action === 'download_csv' && $fy && preg_match('/^\d{4}-\d{2}$/', $fy)) {
    $rows = stp_build_ytd_rows($db, $conf, $fy);
    $filename = 'stp-ytd-' . $fy . '-' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, stp_csv_headers());
    foreach ($rows as $r) {
        fputcsv($out, stp_row_to_csv($r));
    }
    fclose($out);
    exit;
}

// ── Build YTD rows ────────────────────────────────────────────────────────────

/**
 * Fetch all pay run lines for the FY, aggregate per employee in PHP.
 * Returns array of employee YTD summary objects.
 */
function stp_build_ytd_rows($db, $conf, $fy)
{
    $tfnKey = tfn_load_key();

    // Fetch all payrun lines for this FY
    $sql = "SELECT prl.fk_user, prl.gross, prl.payg, prl.super_amount, prl.deductions_json,"
        . " pe.position_type, pe.tax_scale, pe.tfn_encrypted,"
        . " u.firstname, u.lastname, u.address, u.zip, u.town, u.state"
        . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
        . " JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = prl.fk_user"
        . " LEFT JOIN " . MAIN_DB_PREFIX . "payroll_employee pe"
        . "   ON pe.fk_user = prl.fk_user AND pe.entity = prl.entity"
        . " WHERE prl.fy = '" . $db->escape($fy) . "'"
        . "   AND prl.entity = " . (int)$conf->entity
        . " ORDER BY u.lastname, u.firstname, prl.pay_period_end";

    $res = $db->query($sql);
    $by_user = [];

    while ($obj = $db->fetch_object($res)) {
        $uid = (int)$obj->fk_user;
        if (!isset($by_user[$uid])) {
            $by_user[$uid] = [
                'fk_user'        => $uid,
                'lastname'       => $obj->lastname,
                'firstname'      => $obj->firstname,
                'address'        => $obj->address,
                'suburb'         => $obj->town,
                'state'          => $obj->state,
                'postcode'       => $obj->zip,
                'position_type'  => $obj->position_type,
                'tax_scale'      => $obj->tax_scale,
                'tfn_encrypted'  => $obj->tfn_encrypted,
                'ytd_gross'      => 0.0,
                'ytd_payg'       => 0.0,
                'ytd_hecs'       => 0.0,
                'ytd_super'      => 0.0,
                'pay_run_count'  => 0,
            ];
        }
        $by_user[$uid]['ytd_gross']     += (float)$obj->gross;
        $by_user[$uid]['ytd_payg']      += (float)$obj->payg;
        $by_user[$uid]['ytd_super']     += (float)$obj->super_amount;
        $by_user[$uid]['pay_run_count'] += 1;

        // Extract HECS from deductions_json
        if ($obj->deductions_json) {
            $deds = json_decode($obj->deductions_json, true);
            if (isset($deds['HECS']['amount'])) {
                $by_user[$uid]['ytd_hecs'] += (float)$deds['HECS']['amount'];
            }
        }
    }

    // Decrypt TFNs and derive computed fields
    foreach ($by_user as &$row) {
        $row['tfn'] = '';
        if ($row['tfn_encrypted'] && $tfnKey) {
            $dec = tfn_decrypt($row['tfn_encrypted'], $tfnKey);
            $row['tfn'] = ($dec !== false) ? $dec : '';
        }

        $row['ytd_gross']     = round($row['ytd_gross'],  2);
        $row['ytd_payg']      = round($row['ytd_payg'],   2);
        $row['ytd_hecs']      = round($row['ytd_hecs'],   2);
        $row['ytd_super']     = round($row['ytd_super'],  2);
        $row['ytd_payg_total'] = round($row['ytd_payg'] + $row['ytd_hecs'], 2);
    }
    unset($row);

    return array_values($by_user);
}

function stp_csv_headers()
{
    return [
        'employee_id',
        'lastname',
        'firstname',
        'tfn',
        'employment_basis',   // F/P/C per STP Phase 2
        'income_type',        // SAW = Salary and Wages (ATO default)
        'tax_scale',
        'ytd_gross',
        'ytd_payg',           // PAYG withholding (excl. HECS)
        'ytd_hecs',           // HECS/HELP/study loan repayment
        'ytd_payg_total',     // PAYG + HECS combined (total withheld)
        'ytd_super',          // Super guarantee
        'pay_runs_in_fy',
        'address',
        'suburb',
        'state',
        'postcode',
        // Fields not yet populated — placeholders for SSP mapping
        'ytd_salary_sacrifice',    // not tracked yet
        'ytd_reportable_super',    // not tracked yet
        'ytd_fringe_benefits',     // not tracked yet
        'employment_start_date',   // not tracked yet
        'employment_end_date',     // not tracked yet
        'notes',
    ];
}

function stp_row_to_csv($row)
{
    global $basis_map;
    $basis = $basis_map[$row['position_type'] ?? ''] ?? '';
    $notes = [];
    if (!$row['tfn']) {
        $notes[] = 'TFN not set';
    }
    if (!$row['position_type']) {
        $notes[] = 'employment basis not set';
    }

    return [
        $row['fk_user'],
        $row['lastname'],
        $row['firstname'],
        $row['tfn'],
        $basis,
        'SAW',   // Salary and Wages — default income type
        $row['tax_scale'],
        number_format($row['ytd_gross'],     2, '.', ''),
        number_format($row['ytd_payg'],      2, '.', ''),
        number_format($row['ytd_hecs'],      2, '.', ''),
        number_format($row['ytd_payg_total'],2, '.', ''),
        number_format($row['ytd_super'],     2, '.', ''),
        $row['pay_run_count'],
        $row['address'],
        $row['suburb'],
        $row['state'],
        $row['postcode'],
        '',   // ytd_salary_sacrifice — not tracked
        '',   // ytd_reportable_super — not tracked
        '',   // ytd_fringe_benefits  — not tracked
        '',   // employment_start_date — not tracked
        '',   // employment_end_date   — not tracked
        implode('; ', $notes),
    ];
}

// ── Load preview data ─────────────────────────────────────────────────────────
$preview_rows = [];
$preview_totals = ['gross' => 0, 'payg' => 0, 'hecs' => 0, 'super' => 0];
if ($fy && preg_match('/^\d{4}-\d{2}$/', $fy)) {
    $preview_rows = stp_build_ytd_rows($db, $conf, $fy);
    foreach ($preview_rows as $r) {
        $preview_totals['gross'] += $r['ytd_gross'];
        $preview_totals['payg']  += $r['ytd_payg'];
        $preview_totals['hecs']  += $r['ytd_hecs'];
        $preview_totals['super'] += $r['ytd_super'];
    }
}

// ── HTML ──────────────────────────────────────────────────────────────────────

llxHeader('', 'STP YTD Export');
?>
<div class="fiche">
<h1>STP YTD Export</h1>
<p style="color:#555;max-width:780px;">
  Generates a CSV of Year-to-Date payroll totals per employee for STP Phase 2 PAYEVNT submission.
  Download the file and upload it to your SSP's portal after each pay run (or use for EOFY finalisation).
  See <a href="<?= DOL_URL_ROOT ?>/custom/help/stp-ssp.php?mainmenu=billing" target="_blank">STP &amp; SSP Plan</a>
  for provider research and transition notes.
</p>

<?php
$tfnKey = tfn_load_key();
if (!$tfnKey):
?>
<div class="alert alert-warning" style="max-width:780px;">
  <strong>TFN_KEY not found in .env</strong> — TFNs will be blank in the export. Add the key to decrypt TFNs.
</div>
<?php endif; ?>

<!-- FY selector -->
<form method="get" action="stp_export.php" style="margin:1rem 0 1.5rem;">
  <input type="hidden" name="mainmenu" value="billing">
  <input type="hidden" name="leftmenu" value="payroll_stp">
  <label style="font-weight:bold;">Financial Year:</label>
  &nbsp;
  <select name="fy" class="flat" style="width:100px;">
    <?php foreach ($avail_fys as $f): ?>
    <option value="<?= dol_htmlentities($f) ?>"<?= $f === $fy ? ' selected' : '' ?>><?= dol_htmlentities($f) ?></option>
    <?php endforeach; ?>
  </select>
  &nbsp;
  <button type="submit" class="button">Preview</button>
</form>

<?php if ($preview_rows && $fy): ?>

<!-- Download button -->
<form method="get" action="stp_export.php" style="margin:0 0 1.5rem;">
  <input type="hidden" name="action" value="download_csv">
  <input type="hidden" name="fy"     value="<?= dol_htmlentities($fy) ?>">
  <input type="hidden" name="mainmenu" value="billing">
  <button type="submit" class="button buttonaction">
    ⬇ Download CSV — <?= dol_htmlentities($fy) ?>
  </button>
  <span style="margin-left:1rem;font-size:0.85em;color:#666;">
    <?= count($preview_rows) ?> employee<?= count($preview_rows) !== 1 ? 's' : '' ?>,
    <?= count(array_filter($preview_rows, fn($r) => !$r['tfn'])) ?> missing TFN
  </span>
</form>

<!-- Preview table -->
<div style="overflow-x:auto;">
<table class="noborder" style="width:100%;max-width:1100px;font-size:0.9em;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 0.75rem;text-align:left;">Employee</th>
      <th style="padding:0.4rem 0.5rem;text-align:left;">TFN</th>
      <th style="padding:0.4rem 0.5rem;text-align:center;">Basis</th>
      <th style="padding:0.4rem 0.5rem;text-align:center;">Scale</th>
      <th style="padding:0.4rem 0.75rem;text-align:right;">YTD Gross</th>
      <th style="padding:0.4rem 0.75rem;text-align:right;">YTD PAYG</th>
      <th style="padding:0.4rem 0.75rem;text-align:right;">YTD HECS</th>
      <th style="padding:0.4rem 0.75rem;text-align:right;">YTD Total<br><small>Withheld</small></th>
      <th style="padding:0.4rem 0.75rem;text-align:right;">YTD Super</th>
      <th style="padding:0.4rem 0.5rem;text-align:center;">Runs</th>
      <th style="padding:0.4rem 0.5rem;"></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($preview_rows as $i => $r):
      $name  = trim($r['firstname'] . ' ' . $r['lastname']);
      $basis = $basis_map[$r['position_type'] ?? ''] ?? '';
      $basis_lbl = $basis_labels[$basis] ?? ($r['position_type'] ?: '⚠ not set');
      $alt   = $i % 2 ? 'background:#fafafa;' : '';
      $warns = [];
      if (!$r['tfn'])            $warns[] = 'TFN';
      if (!$r['position_type'])  $warns[] = 'Basis';
  ?>
  <tr style="<?= $alt ?>">
    <td style="padding:0.35rem 0.75rem;font-weight:600;"><?= dol_htmlentities($name) ?></td>
    <td style="padding:0.35rem 0.5rem;font-family:monospace;">
      <?php if ($r['tfn']): ?>
        ●●●-●●●-<?= substr(dol_htmlentities($r['tfn']), -3) ?>
      <?php else: ?>
        <span style="color:#c00;font-size:0.85em;">not set</span>
      <?php endif; ?>
    </td>
    <td style="padding:0.35rem 0.5rem;text-align:center;">
      <?= $basis ? '<strong>' . dol_htmlentities($basis) . '</strong> <small style="color:#888;">' . dol_htmlentities($basis_lbl) . '</small>' : '<span style="color:#c00;font-size:0.85em;">⚠ ' . dol_htmlentities($basis_lbl) . '</span>' ?>
    </td>
    <td style="padding:0.35rem 0.5rem;text-align:center;font-size:0.85em;">
      <?= dol_htmlentities($r['tax_scale'] ?: '—') ?>
    </td>
    <td style="padding:0.35rem 0.75rem;text-align:right;">$<?= number_format($r['ytd_gross'],     2) ?></td>
    <td style="padding:0.35rem 0.75rem;text-align:right;">$<?= number_format($r['ytd_payg'],      2) ?></td>
    <td style="padding:0.35rem 0.75rem;text-align:right;"><?= $r['ytd_hecs'] > 0 ? '$' . number_format($r['ytd_hecs'], 2) : '—' ?></td>
    <td style="padding:0.35rem 0.75rem;text-align:right;font-weight:600;">$<?= number_format($r['ytd_payg_total'], 2) ?></td>
    <td style="padding:0.35rem 0.75rem;text-align:right;">$<?= number_format($r['ytd_super'],     2) ?></td>
    <td style="padding:0.35rem 0.5rem;text-align:center;color:#777;"><?= (int)$r['pay_run_count'] ?></td>
    <td style="padding:0.35rem 0.5rem;">
      <?php if ($warns): ?>
        <span style="color:#c00;font-size:0.8em;">⚠ <?= implode(', ', array_map('dol_htmlentities', $warns)) ?></span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f4f4f4;font-weight:700;">
      <td style="padding:0.45rem 0.75rem;" colspan="4">Totals — FY <?= dol_htmlentities($fy) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format($preview_totals['gross'], 2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format($preview_totals['payg'],  2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format($preview_totals['hecs'],  2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format($preview_totals['payg'] + $preview_totals['hecs'], 2) ?></td>
      <td style="padding:0.45rem 0.75rem;text-align:right;">$<?= number_format($preview_totals['super'], 2) ?></td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>
</div>

<div class="alert alert-info" style="max-width:780px;margin-top:1.5rem;">
  <strong>What's in the CSV:</strong> YTD totals per employee — gross, PAYG (excl. HECS), HECS/study loan, total withheld, super.
  Income type defaults to <strong>SAW</strong> (Salary and Wages). Columns for salary sacrifice, reportable super, fringe benefits,
  and employment dates are included but blank — populate these once your SSP confirms their required format.
  <br><br>
  <strong>TFN masking:</strong> the preview masks TFNs (shows last 3 digits only). The CSV contains full decrypted TFNs — store and transmit securely.
</div>

<?php elseif (empty($avail_fys)): ?>
<p style="color:#888;">No completed pay runs found. Process a pay run first.</p>
<?php elseif ($fy): ?>
<p style="color:#888;">No pay runs found for <?= dol_htmlentities($fy) ?>.</p>
<?php endif; ?>

<div style="margin-top:1.5rem;">
  <a href="payruns.php?mainmenu=billing&leftmenu=payroll_history" class="button">← Pay Run History</a>
  &nbsp;
  <a href="<?= DOL_URL_ROOT ?>/custom/help/stp-ssp.php?mainmenu=billing" class="button" target="_blank">STP &amp; SSP Plan</a>
</div>

</div>
<?php llxFooter(); ?>
