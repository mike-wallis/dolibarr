<?php
/**
 * Pay Run — record wages, PAYG, HECS, super and other deductions in one step.
 *
 * For each employee with hours/salary > 0:
 *   1. Creates a Salary record (net pay)
 *   2. Creates a PaymentSalary + bank line (marks salary Paid)
 *   3. Posts BQ journal entries: Dr wages / Cr bank (net pay)
 *   4. Posts OD journal entries per deduction (PAYG, HECS combined → 2112, Super, CS, etc.)
 * All in a single DB transaction.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/salaries/class/salary.class.php';
require_once DOL_DOCUMENT_ROOT . '/salaries/class/paymentsalary.class.php';
require_once DOL_DOCUMENT_ROOT . '/accountancy/class/bookkeeping.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/lib/PaygCalculator.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(['bills', 'salaries', 'banks', 'compta']);

$action = GETPOST('action', 'aZ09');

// ── Reference data ────────────────────────────────────────────────────────

$sql_emp = "SELECT u.rowid, u.login, u.firstname, u.lastname,"
    . " pe.position_type, pe.pay_period, pe.pay_rate, pe.pay_rate_type,"
    . " pe.std_hours, pe.ot_rate1, pe.ot_rate2, pe.tax_scale, pe.has_hecs,"
    . " pe.has_medicare_adj, pe.medicare_dependants"
    . " FROM " . MAIN_DB_PREFIX . "user u"
    . " LEFT JOIN " . MAIN_DB_PREFIX . "payroll_employee pe"
    . "   ON pe.fk_user = u.rowid AND pe.entity = " . (int)$conf->entity
    . " WHERE u.employee = 1 AND u.statut = 1"
    . " ORDER BY u.lastname, u.firstname";
$res_emp   = $db->query($sql_emp);
$employees = [];
while ($obj = $db->fetch_object($res_emp)) {
    $employees[$obj->rowid] = $obj;
}

$sql_ba = "SELECT ba.rowid, ba.ref, ba.label, ba.account_number,"
    . " aj.code AS journal_code, aj.label AS journal_label"
    . " FROM " . MAIN_DB_PREFIX . "bank_account ba"
    . " LEFT JOIN " . MAIN_DB_PREFIX . "accounting_journal aj ON ba.fk_accountancy_journal = aj.rowid"
    . " WHERE ba.entity = " . (int)$conf->entity . " AND ba.clos = 0 ORDER BY ba.ref";
$res_ba       = $db->query($sql_ba);
$bank_accounts = [];
while ($obj = $db->fetch_object($res_ba)) {
    $bank_accounts[$obj->rowid] = $obj;
}

$sql_dt = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_deduction_type"
    . " WHERE entity = " . (int)$conf->entity . " AND active = 1 ORDER BY position, code";
$res_dt    = $db->query($sql_dt);
$ded_types = [];
$add_types = [];
while ($obj = $db->fetch_object($res_dt)) {
    if ($obj->deduction_class === 'addition') {
        $add_types[$obj->rowid] = $obj;
    } else {
        $ded_types[$obj->rowid] = $obj;
    }
}

$sql_ed = "SELECT * FROM " . MAIN_DB_PREFIX . "payroll_employee_deduction"
    . " WHERE entity = " . (int)$conf->entity . " AND active = 1";
$res_ed   = $db->query($sql_ed);
$emp_deds = [];
while ($obj = $db->fetch_object($res_ed)) {
    $emp_deds[$obj->fk_user][$obj->fk_deduction] = $obj;
}

$res_pt = $db->query("SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'VIR' AND entity IN (0,1) LIMIT 1");
$vir_id = (int)$db->fetch_object($res_pt)->id;

// ── Helpers ───────────────────────────────────────────────────────────────

function addBKLine($db, $user, $date, $doc_type, $doc_ref, $fk_doc,
                   $journal_code, $journal_label,
                   $numero_compte, $label_compte, $label_op,
                   $debit, $credit)
{
    global $conf;
    $bk = new BookKeeping($db);
    $bk->doc_date        = $date;
    $bk->doc_type        = $doc_type;
    $bk->doc_ref         = $doc_ref;
    $bk->fk_doc          = (int)$fk_doc;
    $bk->fk_docdet       = 0;
    $bk->code_journal    = $journal_code;
    $bk->journal_label   = $journal_label;
    $bk->numero_compte   = $numero_compte;
    $bk->label_compte    = $label_compte;
    $bk->label_operation = $label_op;
    $bk->debit           = (float)$debit;
    $bk->credit          = (float)$credit;
    $bk->montant         = (float)($debit > 0 ? $debit : $credit);
    $bk->sens            = ($debit > 0 ? 'D' : 'C');
    $bk->fk_user_author  = $user->id;
    $bk->entity          = (int)$conf->entity;
    return $bk->create($user);
}

function glLabel($db, $account_number)
{
    global $conf;
    $res = $db->query("SELECT label FROM " . MAIN_DB_PREFIX . "accounting_account"
        . " WHERE account_number = '" . $db->escape($account_number) . "'"
        . " AND entity = " . (int)$conf->entity . " LIMIT 1");
    if ($obj = $db->fetch_object($res)) {
        return $obj->label;
    }
    return $account_number;
}

// ── Process POST ──────────────────────────────────────────────────────────

$errors      = [];
$result_rows = [];
$ded_totals  = [];
$processed   = false;

if ($action === 'process') {

    $datesp_str = GETPOST('datesp', 'alpha');
    $dateep_str = GETPOST('dateep', 'alpha');
    $datep_str  = GETPOST('datep',  'alpha');
    $accountid  = GETPOSTINT('accountid');
    $fy         = GETPOST('fy', 'alpha') ?: '2025-26';

    $datesp = $datesp_str ? strtotime($datesp_str) : 0;
    $dateep = $dateep_str ? strtotime($dateep_str) : 0;
    $datep  = $datep_str  ? strtotime($datep_str)  : 0;

    if (!$datesp || !$dateep || !$datep) {
        $errors[] = 'Please enter all three dates.';
    }
    $ba = $bank_accounts[$accountid] ?? null;
    if (!$ba) {
        $errors[] = 'Please select a bank account.';
    }

    // Collect employees with earnings (base_gross only — additions added per-employee below)
    $emp_rows = [];
    foreach ($employees as $uid => $emp) {
        $base_gross = 0;
        if (($emp->pay_rate_type ?? 'hourly') === 'salary') {
            $base_gross = round((float)GETPOST('salary_' . $uid, 'alpha'), 2);
        } else {
            $ord  = (float)GETPOST('ord_hrs_' . $uid, 'alpha');
            $ot1  = (float)GETPOST('ot1_hrs_' . $uid, 'alpha');
            $ot2  = (float)GETPOST('ot2_hrs_' . $uid, 'alpha');
            $rate = (float)GETPOST('rate_'    . $uid, 'alpha');
            $base_gross = round($ord * $rate + $ot1 * $rate * (float)($emp->ot_rate1 ?? 1.5)
                              + $ot2 * $rate * (float)($emp->ot_rate2 ?? 2.0), 2);
        }
        if ($base_gross > 0) {
            $emp_rows[$uid] = $base_gross;
        }
    }

    if (empty($emp_rows)) {
        $errors[] = 'Enter earnings for at least one employee.';
    }

    if (empty($errors)) {
        $pay_label = 'Week ending ' . dol_print_date($dateep, '%d %b %Y');
        $db->begin();

        foreach ($emp_rows as $uid => $base_gross) {
            $emp     = $employees[$uid];
            $empname = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;

            // Collect addition amounts (add to gross, taxable)
            $emp_add_amounts = [];
            $add_total       = 0;
            foreach ($add_types as $dtid => $dt) {
                $amount = round((float)GETPOST('add_' . $uid . '_' . $dtid, 'alpha'), 2);
                if ($amount > 0) {
                    $emp_add_amounts[$dtid] = $amount;
                    $add_total += $amount;
                }
            }
            $gross = round($base_gross + $add_total, 2);

            // Collect deduction amounts
            $emp_ded_amounts     = [];
            $total_employee_deds = 0;

            foreach ($ded_types as $dtid => $dt) {
                $is_for_emp = $dt->is_mandatory || isset($emp_deds[$uid][$dtid]);
                if (!$is_for_emp) {
                    continue;
                }
                $amount = round((float)GETPOST('ded_' . $uid . '_' . $dtid, 'alpha'), 2);
                if ($amount > 0) {
                    $emp_ded_amounts[$dtid] = $amount;
                    if ($dt->deduction_class === 'employee') {
                        $total_employee_deds += $amount;
                    }
                }
            }

            $net = round($gross - $total_employee_deds, 2);

            // 1. Salary record
            $salary                 = new Salary($db);
            $salary->fk_user        = $uid;
            $salary->label          = $pay_label;
            $salary->datesp         = $datesp;
            $salary->dateep         = $dateep;
            $salary->amount         = $net;
            $salary->type_payment   = $vir_id;
            $salary->accountid      = $accountid;
            $salary->fk_user_author = $user->id;

            if (($emp->pay_rate_type ?? 'hourly') === 'salary') {
                $salary->note = 'Salary $' . number_format($base_gross, 2) . ' gross';
            } else {
                $ord = (float)GETPOST('ord_hrs_' . $uid, 'alpha');
                $rt  = (float)GETPOST('rate_'    . $uid, 'alpha');
                $ot1 = (float)GETPOST('ot1_hrs_' . $uid, 'alpha');
                $ot2 = (float)GETPOST('ot2_hrs_' . $uid, 'alpha');
                $salary->note = sprintf('%.1f hrs @ $%.2f/hr', $ord, $rt);
                if ($ot1 > 0) {
                    $salary->note .= sprintf(' + %.1f OT×%.2f hrs', $ot1, $emp->ot_rate1 ?? 1.5);
                }
                if ($ot2 > 0) {
                    $salary->note .= sprintf(' + %.1f OT×%.2f hrs', $ot2, $emp->ot_rate2 ?? 2.0);
                }
            }
            if ($add_total > 0) {
                $add_note_parts = [];
                foreach ($emp_add_amounts as $dtid => $amt) {
                    $add_note_parts[] = $add_types[$dtid]->label . ' $' . number_format($amt, 2);
                }
                $salary->note .= ' + ' . implode(', ', $add_note_parts);
            }

            $salary_id = $salary->create($user);
            if ($salary_id < 0) {
                $errors[] = "Salary create failed for $empname: " . $salary->error;
                break;
            }

            // 2. PaymentSalary
            $payment                 = new PaymentSalary($db);
            $payment->datep          = $datep;
            $payment->datev          = $datep;
            $payment->fk_typepayment = $vir_id;
            $payment->amounts        = [$salary_id => $net];
            $payment->amount         = $net;
            $payment->fk_user_author = $user->id;

            $payment_id = $payment->create($user, 1);
            if ($payment_id < 0) {
                $errors[] = "Payment create failed for $empname: " . $payment->error;
                break;
            }

            // 3. Bank line
            if ($payment->addPaymentToBank($user, 'payment_salary', '(SalaryPayment)', $accountid, '', '') < 0) {
                $errors[] = "Bank line failed for $empname";
                break;
            }
            $bank_line_id = $payment->bank_line;

            // 4. BQ journal: Dr wages / Cr bank
            $bk_label = 'Salary — ' . $empname . ' / ' . $pay_label;
            $doc_ref  = 'Salary payment ' . $payment_id;

            if (addBKLine($db, $user, $datep, 'bank', $doc_ref, $bank_line_id,
                    $ba->journal_code, $ba->journal_label,
                    '6400.10', glLabel($db, '6400.10'), $bk_label, $net, 0) < 0
                || addBKLine($db, $user, $datep, 'bank', $doc_ref, $bank_line_id,
                    $ba->journal_code, $ba->journal_label,
                    $ba->account_number, glLabel($db, $ba->account_number), $bk_label, 0, $net) < 0) {
                $errors[] = "BQ journal failed for $empname";
                break;
            }

            // 5. OD journal entries per deduction
            foreach ($emp_ded_amounts as $dtid => $amount) {
                $dt      = $ded_types[$dtid];
                $ded_ref = $dt->code . '-' . dol_print_date($dateep, '%Y%m%d') . '-' . $uid;
                $ded_lbl = $dt->label . ' — ' . $empname . ' / ' . $pay_label;
                $dr_acc  = $dt->account_debit  ?? '6400.10';
                $cr_acc  = $dt->account_credit ?? '2112';

                // PAYG field contains base PAYG + HECS combined — note that in the label
                if ($dt->code === 'PAYG') {
                    $hecs_dtid = null;
                    foreach ($ded_types as $id => $d) {
                        if ($d->code === 'HECS') {
                            $hecs_dtid = $id;
                            break;
                        }
                    }
                    $hecs_amount = $hecs_dtid ? ($emp_ded_amounts[$hecs_dtid] ?? 0) : 0;
                    if ($hecs_amount > 0) {
                        $ded_lbl .= ' (incl. HECS $' . number_format($hecs_amount, 2) . ')';
                    }
                }

                if (addBKLine($db, $user, $datep, 'various', $ded_ref, 0, 'OD', 'General journal',
                        $dr_acc, glLabel($db, $dr_acc), $ded_lbl, $amount, 0) < 0
                    || addBKLine($db, $user, $datep, 'various', $ded_ref, 0, 'OD', 'General journal',
                        $cr_acc, glLabel($db, $cr_acc), $ded_lbl, 0, $amount) < 0) {
                    $errors[] = "OD entry failed for $empname / " . $dt->code;
                    break 2;
                }

                $ded_totals[$dtid] = ($ded_totals[$dtid] ?? 0) + $amount;
            }

            $result_rows[] = [
                'name'       => $empname,
                'base_gross' => $base_gross,
                'adds'       => $emp_add_amounts,
                'gross'      => $gross,
                'net'        => $net,
                'deds'       => $emp_ded_amounts,
            ];
        }

        if (empty($errors)) {
            $db->commit();
            $processed = true;
        } else {
            $db->rollback();
        }
    }
}

// ── Deduction columns (mandatory + any employee has it active) ─────────────

$col_deds = [];
foreach ($ded_types as $dtid => $dt) {
    if ($dt->is_mandatory) {
        $col_deds[$dtid] = $dt;
    } else {
        foreach ($employees as $uid => $emp) {
            if (isset($emp_deds[$uid][$dtid])) {
                $col_deds[$dtid] = $dt;
                break;
            }
        }
    }
}

// All active addition types shown as optional columns (apply to any employee this pay run)
$col_adds = $add_types;

// ── HTML output ───────────────────────────────────────────────────────────

llxHeader('', 'Pay Run');
?>
<div class="fiche">
<h1>Pay Run</h1>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger" style="margin:1rem 0;">
    <strong>Errors — nothing was saved:</strong><br>
    <?php foreach ($errors as $e): ?>&bull; <?= dol_htmlentities($e) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($processed): ?>
  <?php
  $total_gross = array_sum(array_column($result_rows, 'gross'));
  $total_net   = array_sum(array_column($result_rows, 'net'));
  ?>
  <div class="alert alert-success" style="margin:1rem 0 2rem;"><strong>Pay run complete.</strong></div>

  <h3>Employee Summary</h3>
  <?php
  $add_totals = [];
  foreach ($result_rows as $row) {
      foreach ($row['adds'] as $dtid => $amt) {
          $add_totals[$dtid] = ($add_totals[$dtid] ?? 0) + $amt;
      }
  }
  ?>
  <table class="noborder" style="width:100%;max-width:1100px;margin-bottom:1.5rem;">
    <thead>
      <tr style="background:#f4f4f4;">
        <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
        <th style="padding:0.5rem 1rem;text-align:right;">Base</th>
        <?php foreach ($add_types as $dtid => $dt):
            if (!isset($add_totals[$dtid])) continue; ?>
        <th style="padding:0.5rem 0.75rem;text-align:right;color:#1a7cb8;"><?= dol_htmlentities($dt->code) ?></th>
        <?php endforeach; ?>
        <th style="padding:0.5rem 1rem;text-align:right;">Gross</th>
        <?php foreach ($ded_types as $dtid => $dt):
            if (!isset($ded_totals[$dtid])) continue; ?>
        <th style="padding:0.5rem 0.75rem;text-align:right;"><?= dol_htmlentities($dt->code) ?></th>
        <?php endforeach; ?>
        <th style="padding:0.5rem 1rem;text-align:right;">Net pay</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result_rows as $row): ?>
      <tr>
        <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($row['name']) ?></td>
        <td style="padding:0.4rem 1rem;text-align:right;">$<?= number_format($row['base_gross'], 2) ?></td>
        <?php foreach ($add_types as $dtid => $dt):
            if (!isset($add_totals[$dtid])) continue; ?>
        <td style="padding:0.4rem 0.75rem;text-align:right;color:#1a7cb8;">
          <?= isset($row['adds'][$dtid]) ? '$' . number_format($row['adds'][$dtid], 2) : '—' ?>
        </td>
        <?php endforeach; ?>
        <td style="padding:0.4rem 1rem;text-align:right;font-weight:600;">$<?= number_format($row['gross'], 2) ?></td>
        <?php foreach ($ded_types as $dtid => $dt):
            if (!isset($ded_totals[$dtid])) continue; ?>
        <td style="padding:0.4rem 0.75rem;text-align:right;">
          <?= isset($row['deds'][$dtid]) ? '$' . number_format($row['deds'][$dtid], 2) : '—' ?>
        </td>
        <?php endforeach; ?>
        <td style="padding:0.4rem 1rem;text-align:right;font-weight:600;">$<?= number_format($row['net'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#f9f9f9;font-weight:600;">
        <td style="padding:0.5rem 1rem;">Totals</td>
        <td style="padding:0.5rem 1rem;text-align:right;">$<?= number_format(array_sum(array_column($result_rows, 'base_gross')), 2) ?></td>
        <?php foreach ($add_types as $dtid => $dt):
            if (!isset($add_totals[$dtid])) continue; ?>
        <td style="padding:0.5rem 0.75rem;text-align:right;color:#1a7cb8;">$<?= number_format($add_totals[$dtid], 2) ?></td>
        <?php endforeach; ?>
        <td style="padding:0.5rem 1rem;text-align:right;">$<?= number_format($total_gross, 2) ?></td>
        <?php foreach ($ded_types as $dtid => $dt):
            if (!isset($ded_totals[$dtid])) continue; ?>
        <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($ded_totals[$dtid], 2) ?></td>
        <?php endforeach; ?>
        <td style="padding:0.5rem 1rem;text-align:right;">$<?= number_format($total_net, 2) ?></td>
      </tr>
    </tfoot>
  </table>

  <h3>Journal entries posted</h3>
  <table class="noborder" style="width:100%;max-width:750px;margin-bottom:2rem;">
    <thead>
      <tr style="background:#f4f4f4;">
        <th style="padding:0.4rem 1rem;">Jnl</th><th style="padding:0.4rem 1rem;">Type</th>
        <th style="padding:0.4rem 0.75rem;">Account</th><th style="padding:0.4rem 1rem;">Description</th>
        <th style="padding:0.4rem 0.75rem;text-align:right;">Dr</th>
        <th style="padding:0.4rem 0.75rem;text-align:right;">Cr</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result_rows as $i => $row):
          $alt = $i % 2 ? 'background:#fafafa;' : ''; ?>
      <tr style="<?= $alt ?>">
        <td style="padding:0.3rem 1rem;">BQ</td><td style="padding:0.3rem 1rem;">Net wages</td>
        <td style="padding:0.3rem 0.75rem;">6400.10</td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($row['name']) ?></td>
        <td style="padding:0.3rem 0.75rem;text-align:right;">$<?= number_format($row['net'], 2) ?></td><td></td>
      </tr>
      <tr style="<?= $alt ?>">
        <td style="padding:0.3rem 1rem;">BQ</td><td style="padding:0.3rem 1rem;">Bank</td>
        <td style="padding:0.3rem 0.75rem;">1101</td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($row['name']) ?></td>
        <td></td><td style="padding:0.3rem 0.75rem;text-align:right;">$<?= number_format($row['net'], 2) ?></td>
      </tr>
      <?php foreach ($row['deds'] as $dtid => $amount):
          $dt = $ded_types[$dtid]; ?>
      <tr style="<?= $alt ?>">
        <td style="padding:0.3rem 1rem;">OD</td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($dt->code) ?></td>
        <td style="padding:0.3rem 0.75rem;"><?= dol_htmlentities($dt->account_debit ?? '6400.10') ?></td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($dt->label) ?> — <?= dol_htmlentities($row['name']) ?></td>
        <td style="padding:0.3rem 0.75rem;text-align:right;">$<?= number_format($amount, 2) ?></td><td></td>
      </tr>
      <tr style="<?= $alt ?>">
        <td style="padding:0.3rem 1rem;">OD</td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($dt->code) ?></td>
        <td style="padding:0.3rem 0.75rem;"><?= dol_htmlentities($dt->account_credit ?? '2112') ?></td>
        <td style="padding:0.3rem 1rem;"><?= dol_htmlentities($dt->label) ?> liability</td>
        <td></td><td style="padding:0.3rem 0.75rem;text-align:right;">$<?= number_format($amount, 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p>
    <a href="payrun.php?mainmenu=billing&leftmenu=payroll_run" class="button">New pay run</a>
    &nbsp;
    <a href="<?= DOL_URL_ROOT ?>/salaries/list.php" class="button">View all salaries</a>
  </p>

<?php else: // ── PAY RUN FORM ────────────────────────────────────────────── ?>

<p>Employees flagged ⚠ need a <strong>Payroll Profile</strong> before PAYG can be auto-estimated.
   All deduction fields are editable — correct any calculated value before submitting.</p>

<form method="post" action="payrun.php?mainmenu=billing&leftmenu=payroll_run">
<input type="hidden" name="token"  value="<?= newToken() ?>">
<input type="hidden" name="action" value="process">

<table class="noborder" style="margin-bottom:1.5rem;">
  <tr>
    <td style="padding:0.4rem 1rem;"><label><strong>Period start</strong></label></td>
    <td style="padding:0.4rem 1rem;"><input type="date" name="datesp" value="<?= dol_htmlentities(GETPOST('datesp','alpha')) ?>" required class="flat"></td>
    <td style="padding:0.4rem 2rem;"><label><strong>Period end</strong></label></td>
    <td style="padding:0.4rem 1rem;"><input type="date" name="dateep" id="dateep" value="<?= dol_htmlentities(GETPOST('dateep','alpha')) ?>" required class="flat" onchange="recalcAll()"></td>
  </tr>
  <tr>
    <td style="padding:0.4rem 1rem;"><label><strong>Pay date</strong></label></td>
    <td style="padding:0.4rem 1rem;"><input type="date" name="datep" value="<?= dol_htmlentities(GETPOST('datep','alpha')) ?>" required class="flat"></td>
    <td style="padding:0.4rem 2rem;"><label><strong>Bank account</strong></label></td>
    <td style="padding:0.4rem 1rem;">
      <select name="accountid" class="flat">
        <?php foreach ($bank_accounts as $ba_id => $ba): ?>
          <option value="<?= (int)$ba_id ?>" <?= ($ba_id == GETPOSTINT('accountid') || (!GETPOSTINT('accountid') && $ba_id == array_key_first($bank_accounts))) ? 'selected' : '' ?>>
            <?= dol_htmlentities($ba->ref . ' — ' . $ba->label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
  <tr>
    <td style="padding:0.4rem 1rem;"><label><strong>Financial year</strong></label></td>
    <td style="padding:0.4rem 1rem;">
      <select name="fy" id="fy_select" class="flat" onchange="recalcAll()">
        <?php foreach (PaygCalculator::availableYears() as $fy): ?>
          <option value="<?= $fy ?>" <?= (GETPOST('fy','alpha') ?: '2025-26') === $fy ? 'selected' : '' ?>><?= $fy ?></option>
        <?php endforeach; ?>
      </select>
    </td>
  </tr>
</table>

<div style="overflow-x:auto;margin-bottom:1rem;">
<table class="noborder" style="min-width:850px;border-collapse:collapse;">
  <thead>
    <tr style="background:#e8eaf0;font-size:0.88em;">
      <th style="padding:0.5rem 0.75rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 0.4rem;text-align:center;">Type</th>
      <th style="padding:0.5rem 0.4rem;text-align:center;">Period</th>
      <th style="padding:0.5rem 0.4rem;text-align:right;">Ord hrs</th>
      <th style="padding:0.5rem 0.4rem;text-align:right;">OT×1.5</th>
      <th style="padding:0.5rem 0.4rem;text-align:right;">OT×2.0</th>
      <th style="padding:0.5rem 0.4rem;text-align:right;">$/hr</th>
      <?php foreach ($col_adds as $dtid => $dt): ?>
      <th style="padding:0.5rem 0.4rem;text-align:right;color:#1a7cb8;" title="Addition to pay — taxable">
        <?= dol_htmlentities($dt->code) ?><br>
        <small style="font-weight:normal;font-size:0.8em;"><?= $dt->is_super_applicable ? '+super' : 'add' ?></small>
      </th>
      <?php endforeach; ?>
      <th style="padding:0.5rem 0.75rem;text-align:right;background:#d4edda;">Gross</th>
      <?php foreach ($col_deds as $dtid => $dt): ?>
      <th style="padding:0.5rem 0.4rem;text-align:right;<?= $dt->deduction_class==='employer' ? 'background:#d4edda;' : '' ?>">
        <?= dol_htmlentities($dt->code) ?>
        <?php if ($dt->deduction_class === 'employer'): ?><br><small style="font-weight:normal;">employer</small><?php endif; ?>
      </th>
      <?php endforeach; ?>
      <th style="padding:0.5rem 0.75rem;text-align:right;background:#cfe2ff;">Net pay</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($employees as $uid => $emp):
      $empname   = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;
      $is_salary = ($emp->pay_rate_type ?? 'hourly') === 'salary';
      $period    = $emp->pay_period ?? 'weekly';
      $pos_type  = $emp->position_type ?? '?';
  ?>
  <tr data-uid="<?= $uid ?>">
    <td style="padding:0.4rem 0.75rem;min-width:130px;">
      <?= dol_htmlentities($empname) ?>
      <?php if (!$emp->position_type): ?>
        <br><small><a href="employee_payroll.php?userid=<?= $uid ?>">⚠ add profile</a></small>
      <?php endif; ?>
    </td>
    <td style="padding:0.4rem 0.4rem;text-align:center;font-size:0.8em;"><?= dol_htmlentities($pos_type) ?></td>
    <td style="padding:0.4rem 0.4rem;text-align:center;font-size:0.8em;"><?= ucfirst($period) ?></td>

    <?php if ($is_salary): ?>
      <td colspan="3" style="padding:0.4rem 0.25rem;"></td>
      <td colspan="1" style="padding:0.4rem 0.25rem;"><!-- rate/salary label -->
        <input type="number" name="salary_<?= $uid ?>" id="salary_<?= $uid ?>"
               value="<?= GETPOST('salary_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>"
               min="0" step="0.01" style="width:90px;text-align:right;" class="flat"
               onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
      </td>
    <?php else: ?>
      <td style="padding:0.4rem 0.25rem;">
        <input type="number" name="ord_hrs_<?= $uid ?>" id="ord_hrs_<?= $uid ?>"
               value="<?= GETPOST('ord_hrs_'.$uid,'alpha') ?: (($emp->std_hours??0)>0 ? number_format((float)$emp->std_hours,1,'.',''): '') ?>"
               min="0" step="0.5" style="width:65px;text-align:right;" class="flat"
               onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
      </td>
      <td style="padding:0.4rem 0.25rem;">
        <input type="number" name="ot1_hrs_<?= $uid ?>" id="ot1_hrs_<?= $uid ?>"
               value="<?= GETPOST('ot1_hrs_'.$uid,'alpha') ?: '0' ?>"
               min="0" step="0.5" style="width:65px;text-align:right;" class="flat"
               onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
      </td>
      <td style="padding:0.4rem 0.25rem;">
        <input type="number" name="ot2_hrs_<?= $uid ?>" id="ot2_hrs_<?= $uid ?>"
               value="<?= GETPOST('ot2_hrs_'.$uid,'alpha') ?: '0' ?>"
               min="0" step="0.5" style="width:65px;text-align:right;" class="flat"
               onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
      </td>
      <td style="padding:0.4rem 0.25rem;">
        <input type="number" name="rate_<?= $uid ?>" id="rate_<?= $uid ?>"
               value="<?= GETPOST('rate_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>"
               min="0" step="0.01" style="width:75px;text-align:right;" class="flat"
               onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
      </td>
    <?php endif; ?>

    <?php foreach ($col_adds as $dtid => $dt): ?>
    <td style="padding:0.4rem 0.25rem;">
      <input type="number" name="add_<?= $uid ?>_<?= $dtid ?>" id="add_<?= $uid ?>_<?= $dtid ?>"
             value="<?= GETPOST('add_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>"
             min="0" step="0.01" style="width:80px;text-align:right;border-color:#1a7cb8;" class="flat"
             onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
    </td>
    <?php endforeach; ?>

    <td style="padding:0.4rem 0.75rem;text-align:right;background:#d4edda;font-weight:600;white-space:nowrap;">
      $<span id="gross_<?= $uid ?>">0.00</span>
    </td>

    <?php foreach ($col_deds as $dtid => $dt):
        $show = $dt->is_mandatory || isset($emp_deds[$uid][$dtid]);
        $bg   = $dt->deduction_class === 'employer' ? 'background:#d4edda;' : '';
    ?>
    <td style="padding:0.4rem 0.25rem;<?= $bg ?>">
      <?php if ($show): ?>
      <input type="number" name="ded_<?= $uid ?>_<?= $dtid ?>" id="ded_<?= $uid ?>_<?= $dtid ?>"
             value="<?= GETPOST('ded_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>"
             min="0" step="0.01" style="width:80px;text-align:right;" class="flat"
             onchange="recalcNet(<?= $uid ?>)" oninput="recalcNet(<?= $uid ?>)">
      <?php else: ?>
        <span style="color:#ccc;display:block;text-align:center;">—</span>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>

    <td style="padding:0.4rem 0.75rem;text-align:right;background:#cfe2ff;font-weight:700;white-space:nowrap;">
      $<span id="net_<?= $uid ?>">0.00</span>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:0.75rem 1rem;max-width:850px;margin-bottom:1.5rem;font-size:0.88em;">
  <strong>PAYG</strong> = base withholding + HECS (if any) auto-calculated from ATO Schedule 1/8. Field is editable.<br>
  <strong>HECS</strong> is included in the PAYG column and posts to 2112 combined. A note in the journal entry records the HECS component.<br>
  <strong>Super</strong> (green) = employer contribution — does not reduce net pay. Defaults to 12% of applicable gross.<br>
  <strong style="color:#1a7cb8;">Additions</strong> (blue) = taxable extras (commission, allowances). Added to gross before PAYG. "+super" means this addition attracts SGC super.<br>
  Employees not on this pay run: leave all earnings blank or 0 — they will be skipped.
</div>

<button type="submit" class="button buttonaction" style="font-size:1.05em;padding:0.5rem 1.5rem;">Process Pay Run</button>
&nbsp;
<a href="<?= DOL_URL_ROOT ?>/salaries/list.php" class="button">View salary list</a>
&nbsp;
<a href="setup.php?mainmenu=billing&leftmenu=payroll_setup" class="button">Payroll Setup</a>

</form>

<script>
// Employee config for JS recalculation
var EMP = <?php
$emp_js = [];
foreach ($employees as $uid => $emp) {
    $deds = [];
    foreach ($col_deds as $dtid => $dt) {
        $show = $dt->is_mandatory || isset($emp_deds[$uid][$dtid]);
        if (!$show) continue;
        $ed   = $emp_deds[$uid][$dtid] ?? null;
        $rate = ($ed && $ed->rate_override !== null) ? (float)$ed->rate_override : (float)$dt->calc_value;
        $amt  = ($ed && $ed->amount_override !== null) ? (float)$ed->amount_override : (float)$dt->calc_value;
        $deds[(string)$dtid] = [
            'code'  => $dt->code,
            'class' => $dt->deduction_class,
            'calc'  => $dt->calc_type,
            'rate'  => $rate,
            'fixed' => $amt,
        ];
    }
    $emp_js[(string)$uid] = [
        'is_salary'        => ($emp->pay_rate_type ?? 'hourly') === 'salary',
        'rate'             => (float)($emp->pay_rate ?? 0),
        'ot1'              => (float)($emp->ot_rate1 ?? 1.5),
        'ot2'              => (float)($emp->ot_rate2 ?? 2.0),
        'period'           => $emp->pay_period ?? 'weekly',
        'scale'            => $emp->tax_scale ?? 'scale2',
        'has_hecs'         => (bool)($emp->has_hecs ?? false),
        'medicare_adj'     => (bool)($emp->has_medicare_adj ?? false),
        'medicare_deps'    => (int)($emp->medicare_dependants ?? 0),
        'deds'             => $deds,
    ];
}
echo json_encode($emp_js);
?>;

// Addition types — keyed by dtid
var ADD_TYPES = <?php
$add_js = [];
foreach ($col_adds as $dtid => $dt) {
    $add_js[(string)$dtid] = [
        'code'     => $dt->code,
        'is_super' => (bool)$dt->is_super_applicable,
    ];
}
echo json_encode($add_js);
?>;

// ATO NAT 1004 2025-26 — published 17 June 2026.
// Formula: x = floor(weekly) + 0.99;  y = round(a*x - b)
// max_weekly = ATO "Less than $X" boundary minus 1  (first match wins).
// Verified against ATO sample data table (same publication).
var PAYG_SCALES = {
    // Scale 1: resident, tax-free threshold NOT claimed
    scale1: [
        [ 187, 0.1500,   0.1500],
        [ 370, 0.2084,  11.0185],
        [ 514, 0.1790,   0.1066],
        [ 931, 0.3227,  74.1674],
        [2245, 0.3200,  71.6508],
        [3302, 0.3900, 228.8816],
        [1e9,  0.4700, 493.1893]
    ],
    // Scale 2: resident, tax-free threshold claimed  ← most employees
    // < $362/wk: nil withholding
    scale2: [
        [ 361, 0.0000,   0.0000],
        [ 537, 0.1500,  54.3462],
        [ 672, 0.2500, 108.2135],
        [ 720, 0.1700,  54.3473],
        [ 864, 0.1790,  60.8377],
        [1281, 0.3227, 185.1935],
        [2595, 0.3200, 181.7319],
        [3652, 0.3900, 363.4627],
        [1e9,  0.4700, 655.7704]
    ],
    // Scale 3: foreign residents
    scale3: [
        [2595, 0.3000,   0.3000],
        [3652, 0.3700, 181.7308],
        [1e9,  0.4500, 474.0385]
    ],
    // Scale 4: no TFN — flat 47% (floor earnings, ignore cents in result)
    scale4: [[1e9, 0.4700, 0.00]],
    // Scale 5: full Medicare levy exemption (TFT claimed)
    // < $362/wk: nil withholding
    scale5: [
        [ 361, 0.0000,   0.0000],
        [ 720, 0.1500,  54.3462],
        [ 864, 0.1590,  60.8365],
        [1281, 0.3027, 185.1923],
        [2595, 0.3000, 181.7308],
        [3652, 0.3700, 363.4615],
        [1e9,  0.4500, 655.7692]
    ],
    // Scale 6: half Medicare levy exemption
    // < $362/wk: nil withholding
    scale6: [
        [ 361, 0.0000,   0.0000],
        [ 720, 0.1500,  54.3462],
        [ 864, 0.1590,  60.8365],
        [ 907, 0.3027, 185.1923],
        [1134, 0.3527, 230.6135],
        [1281, 0.3127, 185.1923],
        [2595, 0.3100, 181.7308],
        [3652, 0.3800, 363.4615],
        [1e9,  0.4600, 655.7692]
    ],
};

var PERIOD_DIV = {weekly:1,fortnightly:2,halfmonthly:2.1667,monthly:4.3333,fourweekly:4};

function paygWeekly(weeklyGross, scale) {
    var ranges = PAYG_SCALES[scale] || PAYG_SCALES.scale2;
    var x = Math.floor(weeklyGross) + 0.99;
    for (var i = 0; i < ranges.length; i++) {
        if (x <= ranges[i][0] + 0.99) return Math.max(0, ranges[i][1]*x - ranges[i][2]);
    }
    var l = ranges[ranges.length-1]; return Math.max(0, l[1]*x - l[2]);
}

function hecsAnnual(ann, fy) {
    if (ann <= 0) return 0;
    if (fy === '2024-25') {
        var t = [[54434,0],[62849,.01],[66618,.02],[70618,.025],[74855,.03],[79347,.035],
                 [84107,.04],[89154,.045],[94503,.05],[100174,.055],[106185,.06],
                 [112556,.065],[119309,.07],[126468,.075],[134057,.08],[142100,.085],
                 [150626,.09],[159663,.095],[1e9,.10]];
        for (var i=0;i<t.length;i++) { if (ann <= t[i][0]) return ann * t[i][1]; }
        return ann * 0.10;
    }
    // 2025-26 marginal
    if (ann <= 67000)  return 0;
    if (ann <= 125000) return (ann - 67000) * 0.15;
    if (ann <= 179285) return 8700 + (ann - 125000) * 0.17;
    return ann * 0.10;
}

// Medicare levy adjustment — ato.gov.au/tax-rates-and-codes/tax-tables/medicare-levy-adjustment
// Applies to Scale 2 (x >= 538.67) and Scale 6 (x >= 908.42) where employee has lodged NAT 0929.
// Returns the weekly adjustment in whole dollars (0.5c rounds up). Period scaling done in recalcRow.
function medicareWLA(x, scale, hasMedicareAdj, dependants) {
    if (!hasMedicareAdj || (scale !== 'scale2' && scale !== 'scale6')) return 0;
    var wft = Math.round((4338 * dependants + 47238) / 52 * 100) / 100;
    var sop = Math.floor((wft * 0.1) / 0.08);
    var wla = 0;
    if (scale === 'scale2') {
        if (x < 538.67 || x >= sop) return 0;
        if (x < Math.min(673, wft))  wla = (x - 538.67) * 0.1;
        else if (x < wft)            wla = x * 0.02;
        else                         wla = (wft * 0.02) - ((x - wft) * 0.08);
    } else {
        if (x < 908.42 || x >= sop) return 0;
        if (x < Math.min(1135, wft)) wla = (x - 908.42) * 0.05;
        else if (x < wft)            wla = x * 0.01;
        else                         wla = (wft * 0.01) - ((x - wft) * 0.04);
    }
    return Math.floor(Math.max(0, wla) + 0.5); // nearest dollar; 0.5c rounds up
}

function recalcRow(uid) {
    var emp = EMP[uid]; if (!emp) return;
    var baseGross = 0;
    if (emp.is_salary) {
        var el = document.getElementById('salary_'+uid);
        baseGross = el ? (parseFloat(el.value)||0) : 0;
    } else {
        var ord = parseFloat((document.getElementById('ord_hrs_'+uid)||{value:0}).value)||0;
        var ot1 = parseFloat((document.getElementById('ot1_hrs_'+uid)||{value:0}).value)||0;
        var ot2 = parseFloat((document.getElementById('ot2_hrs_'+uid)||{value:0}).value)||0;
        var rt  = parseFloat((document.getElementById('rate_'+uid)||{value:0}).value)||0;
        baseGross = Math.round((ord*rt + ot1*rt*emp.ot1 + ot2*rt*emp.ot2)*100)/100;
    }

    // Sum additions — added to gross before tax; track super-applicable separately
    var addTotal = 0, superAddTotal = 0;
    for (var adtid in ADD_TYPES) {
        var ael = document.getElementById('add_'+uid+'_'+adtid);
        var aamt = ael ? (parseFloat(ael.value)||0) : 0;
        addTotal += aamt;
        if (ADD_TYPES[adtid].is_super) superAddTotal += aamt;
    }
    var gross     = Math.round((baseGross + addTotal) * 100) / 100;
    var superBase = Math.round((baseGross + superAddTotal) * 100) / 100;
    document.getElementById('gross_'+uid).textContent = gross.toFixed(2);

    // Auto-calculate deductions on total gross
    var div  = PERIOD_DIV[emp.period] || 1;
    var wkly = gross / div;
    var fy   = document.getElementById('fy_select').value || '2025-26';
    var annualIncome = wkly * 52;

    var x           = Math.floor(wkly) + 0.99;
    var wklyWLA     = medicareWLA(x, emp.scale, emp.medicare_adj, emp.medicare_deps);
    var periodWLA   = (emp.period === 'monthly')
                        ? Math.round(wklyWLA * 13 / 3)
                        : Math.round(wklyWLA * div);
    var paygBase    = Math.max(0, Math.round(paygWeekly(wkly, emp.scale) * div) - periodWLA);
    var hecsAmt     = emp.has_hecs ? Math.ceil((hecsAnnual(annualIncome, fy)/52)*div) : 0;
    var paygTotal   = Math.max(0, paygBase + hecsAmt);

    for (var dtid in emp.deds) {
        var ded = emp.deds[dtid];
        var el  = document.getElementById('ded_'+uid+'_'+dtid);
        if (!el) continue;
        var val = 0;
        if      (ded.code === 'PAYG')          val = paygTotal;
        else if (ded.code === 'HECS')          val = hecsAmt;
        else if (ded.code === 'SUPER')         val = Math.round(superBase*(ded.rate/100)*100)/100;
        else if (ded.calc==='percent_gross')   val = Math.round(gross*(ded.rate/100)*100)/100;
        else if (ded.calc==='percent_net')     { /* can't calc net yet */ val = 0; }
        else if (ded.calc==='fixed')           val = ded.fixed;
        el.value = val.toFixed(2);
    }
    recalcNet(uid);
}

function recalcNet(uid) {
    var emp = EMP[uid]; if (!emp) return;
    var gross = parseFloat(document.getElementById('gross_'+uid).textContent)||0;
    var totalDeds = 0;
    for (var dtid in emp.deds) {
        var ded = emp.deds[dtid];
        if (ded.class !== 'employee') continue;
        var el = document.getElementById('ded_'+uid+'_'+dtid);
        if (el) totalDeds += parseFloat(el.value)||0;
    }
    var net = Math.max(0, Math.round((gross - totalDeds)*100)/100);
    document.getElementById('net_'+uid).textContent = net.toFixed(2);
}

function recalcAll() {
    for (var uid in EMP) recalcRow(parseInt(uid));
}

document.addEventListener('DOMContentLoaded', recalcAll);
</script>

<?php endif; ?>
</div>
<?php llxFooter(); ?>
