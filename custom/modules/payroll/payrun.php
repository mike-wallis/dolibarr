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

$sql_emp = "SELECT u.rowid, u.login, u.firstname, u.lastname, u.email,"
    . " pe.position_type, pe.pay_period, pe.pay_rate, pe.pay_rate_type,"
    . " pe.std_hours, pe.ot_rate1, pe.ot_rate2, pe.tax_scale, pe.has_hecs,"
    . " pe.has_medicare_adj, pe.medicare_dependants,"
    . " pe.super_fund_name, pe.super_fund_usi, pe.super_member_number"
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

    $accountid = GETPOSTINT('accountid');
    $fy        = GETPOST('fy', 'alpha') ?: '2025-26';
    $ba        = $bank_accounts[$accountid] ?? null;
    if (!$ba) {
        $errors[] = 'Please select a bank account.';
    }

    // Per-period-type dates — each section on the form has its own date block
    $pr_pt_label = [
        'weekly'      => 'Weekly',
        'fortnightly' => 'Fortnightly',
        'fourweekly'  => 'Four Weekly',
        'halfmonthly' => 'Half Monthly',
        'monthly'     => 'Monthly',
    ];
    $period_dates = [];
    foreach (array_keys($pr_pt_label) as $pt) {
        $sp = GETPOST('datesp_' . $pt, 'alpha');
        $ep = GETPOST('dateep_' . $pt, 'alpha');
        $pp = GETPOST('datep_'  . $pt, 'alpha');
        if ($sp && $ep && $pp) {
            $period_dates[$pt] = [
                'start'    => $sp,
                'end'      => $ep,
                'pay'      => $pp,
                'ts_start' => strtotime($sp),
                'ts_end'   => strtotime($ep),
                'ts_pay'   => strtotime($pp),
            ];
        }
    }
    // If a specific period type was requested, restrict processing to those employees
    $process_pt = GETPOST('process_pt', 'aZ09');
    if ($process_pt && array_key_exists($process_pt, $pr_pt_label)) {
        $employees = array_filter($employees, function($emp) use ($process_pt) {
            return ($emp->pay_period ?? 'weekly') === $process_pt;
        });
    } else {
        $process_pt = '';
    }

    // Every employee must have a date block for their period type
    $missing_date_pts = [];
    foreach ($employees as $uid => $emp) {
        $pt = $emp->pay_period ?? 'weekly';
        if (!isset($period_dates[$pt]) && !in_array($pt, $missing_date_pts)) {
            $missing_date_pts[] = $pt;
            $errors[] = 'Please enter dates for ' . ($pr_pt_label[$pt] ?? $pt) . ' employees.';
        }
    }

    // Collect employees with earnings (base_gross only — additions added per-employee below)
    $emp_rows     = [];
    $emp_leave    = []; // [uid => ['al'=>h,'sick'=>h,'bere'=>h,'ord_hrs'=>h,'rate'=>r]]
    $casual_types = ['CA', 'CAPT'];

    foreach ($employees as $uid => $emp) {
        if (empty(GETPOST('incl_'.$uid, 'alpha'))) continue;
        $is_casual_emp = in_array($emp->position_type ?? 'CA', $casual_types);
        $al_hrs     = (!$is_casual_emp) ? (float)GETPOST('al_hrs_'     . $uid, 'alpha') : 0;
        $sick_hrs   = (!$is_casual_emp) ? (float)GETPOST('sick_hrs_'   . $uid, 'alpha') : 0;
        $bere_hrs   = (!$is_casual_emp) ? (float)GETPOST('bere_hrs_'   . $uid, 'alpha') : 0;
        $leave_note = (!$is_casual_emp) ? substr(strip_tags(GETPOST('leave_note_' . $uid, 'restricthtml')), 0, 255) : '';

        $base_gross = 0;
        $ord_hrs_val = 0;
        $rate_val    = 0;
        if (($emp->pay_rate_type ?? 'hourly') === 'salary') {
            $base_gross = round((float)GETPOST('salary_' . $uid, 'alpha'), 2);
        } else {
            $ord_hrs_val = (float)GETPOST('ord_hrs_' . $uid, 'alpha');
            $ot1         = (float)GETPOST('ot1_hrs_' . $uid, 'alpha');
            $ot2         = (float)GETPOST('ot2_hrs_' . $uid, 'alpha');
            $rate_val    = (float)GETPOST('rate_'    . $uid, 'alpha');
            $leave_pay   = ($al_hrs + $sick_hrs + $bere_hrs) * $rate_val;
            $base_gross  = round($ord_hrs_val * $rate_val
                              + $ot1 * $rate_val * (float)($emp->ot_rate1 ?? 1.5)
                              + $ot2 * $rate_val * (float)($emp->ot_rate2 ?? 2.0)
                              + $leave_pay, 2);
        }
        if ($base_gross > 0) {
            $emp_rows[$uid] = $base_gross;
        }
        // Store leave data for every employee (even if no base pay — leave-only pay runs)
        $emp_leave[$uid] = [
            'al'       => $al_hrs,
            'sick'     => $sick_hrs,
            'bere'     => $bere_hrs,
            'ord_hrs'  => $ord_hrs_val,
            'rate'     => $rate_val,
            'is_casual'=> $is_casual_emp,
            'note'     => $leave_note,
        ];
    }

    if (empty($emp_rows)) {
        $errors[] = 'Enter earnings for at least one employee.';
    }

    // Duplicate check: block if any employee already has a payrun_line for the same pay period end
    if (empty($errors)) {
        foreach ($emp_rows as $uid => $base_gross) {
            $emp    = $employees[$uid];
            $emp_pt = $emp->pay_period ?? 'weekly';
            if (!isset($period_dates[$emp_pt])) continue;
            $check_end = $period_dates[$emp_pt]['end'];
            $dup_res   = $db->query(
                "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payrun_line"
                . " WHERE fk_user = " . (int)$uid
                . " AND entity = " . (int)$conf->entity
                . " AND pay_period_end = '" . $db->escape($check_end) . "'"
                . " LIMIT 1"
            );
            if ($dup_res && $db->fetch_object($dup_res)) {
                $empname  = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;
                $errors[] = dol_htmlentities($empname) . ' already has a pay run recorded for period ending '
                    . dol_print_date(strtotime($check_end), '%d %b %Y') . '. Delete the existing record before re-processing.';
            }
        }
    }

    if (empty($errors)) {
        $db->begin();

        $payslip_ids = [];
        foreach ($emp_rows as $uid => $base_gross) {
            $emp     = $employees[$uid];
            $empname = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;

            // Per-employee dates from their period-type's date block
            $emp_pt     = $emp->pay_period ?? 'weekly';
            $emp_pd     = $period_dates[$emp_pt];
            $datesp_str = $emp_pd['start'];
            $dateep_str = $emp_pd['end'];
            $datep_str  = $emp_pd['pay'];
            $datesp     = $emp_pd['ts_start'];
            $dateep     = $emp_pd['ts_end'];
            $datep      = $emp_pd['ts_pay'];
            $pay_label  = 'Period ending ' . dol_print_date($dateep, '%d %b %Y');

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

            $lv = $emp_leave[$uid];
            if (($emp->pay_rate_type ?? 'hourly') === 'salary') {
                $salary->note = 'Salary $' . number_format($base_gross, 2) . ' gross';
            } else {
                $ord = $lv['ord_hrs'];
                $rt  = $lv['rate'];
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
            if ($lv['al']   > 0) $salary->note .= sprintf(' + %.1f h annual leave',     $lv['al']);
            if ($lv['sick'] > 0) $salary->note .= sprintf(' + %.1f h sick/carer\'s',    $lv['sick']);
            if ($lv['bere'] > 0) $salary->note .= sprintf(' + %.1f h bereavement',       $lv['bere']);
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

            // 6. Leave accrual and balance update
            $lv = $emp_leave[$uid];
            $accrual_al   = 0;
            $accrual_sick = 0;
            if (!$lv['is_casual']) {
                if (($emp->pay_rate_type ?? 'hourly') === 'salary') {
                    // Salaried: accrue on contracted hours per period (stored in std_hours)
                    $paid_ordinary = (float)($emp->std_hours ?? 0);
                } else {
                    // Hourly: accrue on all paid ordinary hours (worked + leave taken)
                    $paid_ordinary = $lv['ord_hrs'] + $lv['al'] + $lv['sick'] + $lv['bere'];
                }

                $accrual_al   = round($paid_ordinary / 13.0, 4);  // 4 weeks/52 = 1/13
                $accrual_sick = round($paid_ordinary / 26.0, 4);  // 10 days/yr ≈ 1/26

                $date_tx = dol_print_date($dateep, '%Y-%m-%d');

                // Helper: upsert balance and write transactions
                $leave_updates = [
                    'annual' => ['accrual' => $accrual_al,   'taken' => $lv['al']],
                    'sick'   => ['accrual' => $accrual_sick, 'taken' => $lv['sick']],
                ];
                foreach ($leave_updates as $lt => $vals) {
                    if ($vals['accrual'] > 0 || $vals['taken'] > 0) {
                        // Write accrual transaction
                        if ($vals['accrual'] > 0) {
                            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                                . " (fk_user, entity, leave_type, transaction_type, hours, date_transaction, fk_salary)"
                                . " VALUES (" . (int)$uid . ", " . (int)$conf->entity
                                . ", '" . $db->escape($lt) . "', 'accrual', " . (float)$vals['accrual']
                                . ", '" . $db->escape($date_tx) . "', " . (int)$salary_id . ")");
                        }
                        // Write taken transaction
                        if ($vals['taken'] > 0) {
                            $tx_note = ($lv['note'] ?? '') !== '' ? "'" . $db->escape($lv['note']) . "'" : "NULL";
                            $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                                . " (fk_user, entity, leave_type, transaction_type, hours, date_transaction, fk_salary, note)"
                                . " VALUES (" . (int)$uid . ", " . (int)$conf->entity
                                . ", '" . $db->escape($lt) . "', 'taken', " . (float)$vals['taken']
                                . ", '" . $db->escape($date_tx) . "', " . (int)$salary_id . ", " . $tx_note . ")");
                        }
                        // Upsert running balance: old_balance + accrual - taken
                        $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_balance"
                            . " (fk_user, entity, leave_type, balance_hours, date_updated)"
                            . " VALUES (" . (int)$uid . ", " . (int)$conf->entity
                            . ", '" . $db->escape($lt) . "'"
                            . ", " . round($vals['accrual'] - $vals['taken'], 4)
                            . ", NOW())"
                            . " ON DUPLICATE KEY UPDATE"
                            . "   balance_hours = GREATEST(0, balance_hours + " . round($vals['accrual'] - $vals['taken'], 4) . ")"
                            . ", date_updated = NOW()");
                    }
                }

                // Bereavement: transaction only (no balance)
                if ($lv['bere'] > 0) {
                    $bere_note = ($lv['note'] ?? '') !== '' ? "'" . $db->escape($lv['note']) . "'" : "NULL";
                    $db->query("INSERT INTO " . MAIN_DB_PREFIX . "payroll_leave_transaction"
                        . " (fk_user, entity, leave_type, transaction_type, hours, date_transaction, fk_salary, note)"
                        . " VALUES (" . (int)$uid . ", " . (int)$conf->entity
                        . ", 'bereavement', 'taken', " . (float)$lv['bere']
                        . ", '" . $db->escape($date_tx) . "', " . (int)$salary_id . ", " . $bere_note . ")");
                }
            }

            // 7. Persist pay run line for payslip / YTD
            $prl_payg  = 0;
            $prl_super = 0;
            $prl_other_deds = [];
            $prl_adds       = [];
            foreach ($emp_ded_amounts as $dtid => $amount) {
                $dt2 = $ded_types[$dtid];
                if ($dt2->code === 'PAYG')       { $prl_payg  = $amount; }
                elseif ($dt2->code === 'SUPER')  { $prl_super = $amount; }
                elseif ($dt2->code !== 'HECS')   {
                    $prl_other_deds[$dt2->code] = ['label' => $dt2->label, 'amount' => $amount, 'class' => $dt2->deduction_class];
                }
            }
            foreach ($emp_add_amounts as $dtid => $amount) {
                $dt2 = $add_types[$dtid];
                $prl_adds[$dt2->code] = ['label' => $dt2->label, 'amount' => $amount, 'is_super' => (bool)$dt2->is_super_applicable];
            }
            $prl_is_sal = ($emp->pay_rate_type ?? 'hourly') === 'salary';
            $prl_ot1    = (float)GETPOST('ot1_hrs_' . $uid, 'alpha');
            $prl_ot2    = (float)GETPOST('ot2_hrs_' . $uid, 'alpha');
            $bal_al_after   = !$lv['is_casual'] ? max(0, round(($leave_bals[$uid]['annual'] ?? 0) + $accrual_al   - $lv['al'],   4)) : null;
            $bal_sick_after = !$lv['is_casual'] ? max(0, round(($leave_bals[$uid]['sick']   ?? 0) + $accrual_sick - $lv['sick'], 4)) : null;
            $db->query(
                "INSERT INTO " . MAIN_DB_PREFIX . "payroll_payrun_line"
                . " (fk_salary,fk_user,entity,pay_period_start,pay_period_end,pay_date,fy,"
                . "  is_salary,ord_hrs,ord_rate,ot1_hrs,ot1_mult,ot2_hrs,ot2_mult,"
                . "  salary_amount,al_hrs,sick_hrs,bere_hrs,"
                . "  gross,net,payg,super_amount,super_fund,super_fund_usi,super_member_number,"
                . "  bal_annual_after,bal_sick_after,"
                . "  deductions_json,additions_json,leave_note,date_created)"
                . " VALUES ("
                . (int)$salary_id . "," . (int)$uid . "," . (int)$conf->entity . ","
                . " '" . $db->escape($datesp_str) . "','" . $db->escape($dateep_str) . "','" . $db->escape($datep_str) . "',"
                . " '" . $db->escape($fy) . "',"
                . ($prl_is_sal ? 1 : 0) . ","
                . ($prl_is_sal ? "0,0" : ((float)$lv['ord_hrs'] . "," . (float)$lv['rate'])) . ","
                . (float)$prl_ot1 . "," . (float)($emp->ot_rate1 ?? 1.5) . ","
                . (float)$prl_ot2 . "," . (float)($emp->ot_rate2 ?? 2.0) . ","
                . ($prl_is_sal ? (float)$base_gross : "0") . ","
                . (float)$lv['al'] . "," . (float)$lv['sick'] . "," . (float)$lv['bere'] . ","
                . (float)$gross . "," . (float)$net . "," . (float)$prl_payg . "," . (float)$prl_super . ","
                . ($emp->super_fund_name     ? "'" . $db->escape($emp->super_fund_name)     . "'" : "NULL") . ","
                . ($emp->super_fund_usi      ? "'" . $db->escape($emp->super_fund_usi)      . "'" : "NULL") . ","
                . ($emp->super_member_number ? "'" . $db->escape($emp->super_member_number) . "'" : "NULL") . ","
                . ($bal_al_after   !== null ? (float)$bal_al_after   : "NULL") . ","
                . ($bal_sick_after !== null ? (float)$bal_sick_after : "NULL") . ","
                . (count($prl_other_deds) ? "'" . $db->escape(json_encode($prl_other_deds)) . "'" : "NULL") . ","
                . (count($prl_adds)       ? "'" . $db->escape(json_encode($prl_adds))       . "'" : "NULL") . ","
                . (($lv['note'] ?? '') !== '' ? "'" . $db->escape($lv['note']) . "'" : "NULL") . ","
                . " NOW())"
            );
            $payslip_ids[$uid] = $db->last_insert_id(MAIN_DB_PREFIX . 'payroll_payrun_line');

            $result_rows[] = [
                'name'       => $empname,
                'email'      => $emp->email ?? '',
                'base_gross' => $base_gross,
                'adds'       => $emp_add_amounts,
                'gross'      => $gross,
                'net'        => $net,
                'deds'       => $emp_ded_amounts,
                'leave'      => $emp_leave[$uid],
                'payslip_id' => $payslip_ids[$uid] ?? null,
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

// ── Leave balances for all employees ─────────────────────────────────────────

$leave_bals = [];
$res_lb = $db->query("SELECT fk_user, leave_type, balance_hours FROM " . MAIN_DB_PREFIX . "payroll_leave_balance"
    . " WHERE entity = " . (int)$conf->entity);
while ($obj = $db->fetch_object($res_lb)) {
    $leave_bals[(int)$obj->fk_user][$obj->leave_type] = (float)$obj->balance_hours;
}

// ── Bereavement FY usage — FWA entitlement is 2 days per occasion (not accrued) ─
// Shows total hours used this FY so multi-occasion use is visible.

$form_fy     = GETPOST('fy', 'alpha') ?: '2025-26';
$fy_cfg_res  = $db->query("SELECT start_date, end_date FROM " . MAIN_DB_PREFIX . "payroll_fy_config"
    . " WHERE fy = '" . $db->escape($form_fy) . "' AND entity = " . (int)$conf->entity . " LIMIT 1");
$fy_cfg      = ($fy_cfg_res ? $db->fetch_object($fy_cfg_res) : null);

$bere_ytd = [];
if ($fy_cfg && $fy_cfg->start_date) {
    $res_by = $db->query("SELECT fk_user, SUM(hours) AS total"
        . " FROM " . MAIN_DB_PREFIX . "payroll_leave_transaction"
        . " WHERE entity = " . (int)$conf->entity
        . " AND leave_type = 'bereavement' AND transaction_type = 'taken'"
        . " AND date_transaction >= '" . $db->escape($fy_cfg->start_date) . "'"
        . " AND date_transaction <= '" . $db->escape($fy_cfg->end_date) . "'"
        . " GROUP BY fk_user");
    while ($obj = $db->fetch_object($res_by)) {
        $bere_ytd[(int)$obj->fk_user] = (float)$obj->total;
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

// Suggest next period dates per pay period type, from the most recent recorded pay run.
$suggested_dates = [];
$res_sd = $db->query(
    "SELECT pe.pay_period, MAX(prl.pay_period_end) AS last_end"
    . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
    . " JOIN " . MAIN_DB_PREFIX . "payroll_employee pe"
    . "   ON pe.fk_user = prl.fk_user AND pe.entity = prl.entity"
    . " WHERE prl.entity = " . (int)$conf->entity
    . " GROUP BY pe.pay_period"
);
if ($res_sd) {
    while ($sd_obj = $db->fetch_object($res_sd)) {
        $pt       = $sd_obj->pay_period;
        $ts_last  = strtotime($sd_obj->last_end);
        $ts_start = $ts_last + 86400;
        switch ($pt) {
            case 'fortnightly': $ts_end = $ts_start + 13 * 86400; break;
            case 'fourweekly':  $ts_end = $ts_start + 27 * 86400; break;
            case 'monthly':
                // Last day of the month that $ts_start falls in
                $ts_end = mktime(0, 0, 0, (int)date('n', $ts_start) + 1, 0, (int)date('Y', $ts_start));
                break;
            case 'halfmonthly':
                $day = (int)date('j', $ts_start);
                $ts_end = ($day <= 15)
                    ? mktime(0, 0, 0, (int)date('n', $ts_start), 15, (int)date('Y', $ts_start))
                    : mktime(0, 0, 0, (int)date('n', $ts_start) + 1, 0, (int)date('Y', $ts_start));
                break;
            default: // weekly
                $ts_end = $ts_start + 6 * 86400;
        }
        $suggested_dates[$pt] = [
            'start' => date('Y-m-d', $ts_start),
            'end'   => date('Y-m-d', $ts_end),
            'pay'   => date('Y-m-d', $ts_end),
        ];
    }
}

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
  $total_gross  = array_sum(array_column($result_rows, 'gross'));
  $total_net    = array_sum(array_column($result_rows, 'net'));
  $run_ids_arr  = array_filter(array_values($payslip_ids));
  $run_ref      = $run_ids_arr ? 'PR' . str_pad((string)min($run_ids_arr), 6, '0', STR_PAD_LEFT) : '';
  $ids_js       = '[' . implode(',', $run_ids_arr) . ']';
  $ids_csv      = implode(',', $run_ids_arr);
  $run_pts      = $process_pt ? [$process_pt => ($period_dates[$process_pt] ?? [])] : $period_dates;
  ?>

  <div style="background:#f0f9f0;border:1px solid #7dba7d;border-radius:6px;padding:1rem 1.25rem;margin:0 0 1.5rem;max-width:980px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
      <div>
        <div style="font-size:1.05em;font-weight:700;color:#2a6e2a;margin-bottom:0.35rem;">Pay run complete<?= $run_ref ? ' — ' . dol_htmlentities($run_ref) : '' ?></div>
        <?php foreach ($run_pts as $rpt => $rpd): if (!$rpd) continue; ?>
        <div style="font-size:0.9em;color:#333;margin-bottom:0.1rem;">
          <strong><?= dol_htmlentities($pr_pt_label[$rpt] ?? ucfirst($rpt)) ?>:</strong>
          <?= dol_print_date($rpd['ts_start'] ?? strtotime($rpd['start']), '%d %b %Y') ?> &ndash;
          <?= dol_print_date($rpd['ts_end']   ?? strtotime($rpd['end']),   '%d %b %Y') ?>
          &nbsp;&middot;&nbsp; Pay date: <strong><?= dol_print_date($rpd['ts_pay'] ?? strtotime($rpd['pay']), '%d %b %Y') ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <button onclick="openAllPayslips(<?= $ids_js ?>)" class="button" style="font-size:0.88em;">Print all payslips</button>
        <form method="post" action="payslip.php" style="display:inline;margin:0;">
          <input type="hidden" name="action" value="email_batch">
          <input type="hidden" name="ids"    value="<?= dol_htmlentities($ids_csv) ?>">
          <input type="hidden" name="token"  value="<?= newToken() ?>">
          <button type="submit" class="button" style="font-size:0.88em;">Email all payslips</button>
        </form>
      </div>
    </div>
  </div>
  <script>
  function openAllPayslips(ids) {
    ids.forEach(function(id) { window.open('payslip.php?id=' + id + '&mainmenu=billing', '_blank'); });
  }
  </script>

  <h3 style="margin:1.5rem 0 0.5rem;">Employee Summary</h3>
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
        <th style="padding:0.5rem 1rem;text-align:center;">Payslip</th>
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
        <td style="padding:0.4rem 1rem;text-align:center;white-space:nowrap;">
          <?php if (!empty($row['payslip_id'])): ?>
          <a href="payslip.php?id=<?= (int)$row['payslip_id'] ?>&mainmenu=billing" target="_blank"
             class="button" style="font-size:0.85em;padding:0.2rem 0.6rem;">View</a>
          <?php if (!empty($row['email'])): ?>
          <form method="post" action="payslip.php" style="display:inline;margin:0 0 0 0.3rem;">
            <input type="hidden" name="action" value="email_payslip">
            <input type="hidden" name="id"     value="<?= (int)$row['payslip_id'] ?>">
            <input type="hidden" name="token"  value="<?= newToken() ?>">
            <button type="submit" class="button" style="font-size:0.85em;padding:0.2rem 0.6rem;background:#17a2b8;border-color:#148fa5;color:#fff;">Email</button>
          </form>
          <?php else: ?>
          <span style="display:inline-block;margin-left:0.3rem;font-size:0.8em;color:#aaa;" title="No email on employee profile">no email</span>
          <?php endif; ?>
          <?php endif; ?>
        </td>
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
        <td></td>
      </tr>
    </tfoot>
  </table>

  <?php
  // Super payments due — query payrun_lines we just inserted
  $super_run_rows = [];
  if (!empty($payslip_ids)) {
      $ids_in  = implode(',', array_map('intval', array_values($payslip_ids)));
      $sql_sup = "SELECT prl.*, u.firstname, u.lastname, u.login"
          . " FROM " . MAIN_DB_PREFIX . "payroll_payrun_line prl"
          . " JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = prl.fk_user"
          . " WHERE prl.rowid IN (" . $ids_in . ") AND prl.super_amount > 0"
          . " ORDER BY u.lastname, u.firstname";
      $res_sup = $db->query($sql_sup);
      while ($obj = $db->fetch_object($res_sup)) {
          $super_run_rows[] = $obj;
      }
  }
  if ($super_run_rows):
      $super_run_total = array_sum(array_map(fn($r) => (float)$r->super_amount, $super_run_rows));
      $sgcDue = function($d) {
          $m = (int)date('n', strtotime($d));
          $y = (int)date('Y', strtotime($d));
          if ($m >= 7  && $m <= 9)  return mktime(0,0,0,10,28,$y);
          if ($m >= 10)             return mktime(0,0,0,1,28,$y+1);
          if ($m <= 3)              return mktime(0,0,0,4,28,$y);
          return mktime(0,0,0,7,28,$y);
      };
  ?>
  <h3 style="margin:2rem 0 0.35rem;">Super payments due</h3>
  <p style="font-size:0.87em;color:#666;margin:0 0 0.6rem;">
    Due date = 28 days after end of the quarter the pay date falls in (ATO SGC rule).
    Submit via SBSCH before this date. <strong>⚠ fund details not set</strong> = go to Payroll Employees → Edit payroll profile.
  </p>
  <div style="margin-bottom:0.75rem;">
    <button onclick="window.print()" class="button" style="font-size:0.88em;">Print SBSCH list</button>
  </div>
  <table class="noborder" style="width:100%;max-width:940px;margin-bottom:2rem;">
    <thead>
      <tr style="background:#f4f4f4;">
        <th style="padding:0.5rem 1rem;text-align:left;">Employee</th>
        <th style="padding:0.5rem 0.75rem;text-align:left;">Super fund</th>
        <th style="padding:0.5rem 0.75rem;text-align:left;">USI</th>
        <th style="padding:0.5rem 0.75rem;text-align:left;">Member no.</th>
        <th style="padding:0.5rem 0.75rem;text-align:right;">Amount</th>
        <th style="padding:0.5rem 0.75rem;text-align:left;">SGC due</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($super_run_rows as $i => $sr):
          $srname = trim($sr->firstname . ' ' . $sr->lastname) ?: $sr->login;
          $due_ts = $sgcDue($sr->pay_date);
          $alt    = $i % 2 ? 'background:#fafafa;' : '';
      ?>
      <tr style="<?= $alt ?>">
        <td style="padding:0.4rem 1rem;"><?= dol_htmlentities($srname) ?></td>
        <td style="padding:0.4rem 0.75rem;"><?= $sr->super_fund
              ? dol_htmlentities($sr->super_fund)
              : '<span style="color:#c00;font-size:0.85em;">⚠ not set</span>' ?></td>
        <td style="padding:0.4rem 0.75rem;font-family:monospace;font-size:0.9em;"><?= $sr->super_fund_usi ? dol_htmlentities($sr->super_fund_usi) : '—' ?></td>
        <td style="padding:0.4rem 0.75rem;"><?= $sr->super_member_number ? dol_htmlentities($sr->super_member_number) : '—' ?></td>
        <td style="padding:0.4rem 0.75rem;text-align:right;font-weight:600;">$<?= number_format((float)$sr->super_amount, 2) ?></td>
        <td style="padding:0.4rem 0.75rem;"><?= dol_print_date($due_ts, '%d %b %Y') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#f9f9f9;font-weight:600;">
        <td style="padding:0.5rem 1rem;" colspan="4">Total super due this run</td>
        <td style="padding:0.5rem 0.75rem;text-align:right;">$<?= number_format($super_run_total, 2) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

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

<!-- Global: bank account + financial year -->
<table class="noborder" style="margin-bottom:1.5rem;">
  <tr>
    <td style="padding:0.4rem 1rem;"><label><strong>Bank account</strong></label></td>
    <td style="padding:0.4rem 1.5rem;">
      <select name="accountid" class="flat">
        <?php foreach ($bank_accounts as $ba_id => $ba): ?>
          <option value="<?= (int)$ba_id ?>" <?= ($ba_id == GETPOSTINT('accountid') || (!GETPOSTINT('accountid') && $ba_id == array_key_first($bank_accounts))) ? 'selected' : '' ?>>
            <?= dol_htmlentities($ba->ref . ' — ' . $ba->label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </td>
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

<?php
// Group employees by pay period type, then casual vs FT/PT within each group
$casual_types_pr = ['CA', 'CAPT'];
$period_order_pr = ['weekly', 'fortnightly', 'fourweekly', 'halfmonthly', 'monthly'];
$period_labels_pr = [
    'weekly'      => 'Weekly Pay',
    'fortnightly' => 'Fortnightly Pay',
    'fourweekly'  => 'Four Weekly Pay',
    'halfmonthly' => 'Half Monthly Pay',
    'monthly'     => 'Monthly Pay',
];
$period_groups = [];
foreach ($employees as $uid => $emp) {
    $pt = $emp->pay_period ?? 'weekly';
    $is_casual = in_array($emp->position_type ?? 'CA', $casual_types_pr);
    $period_groups[$pt][$is_casual ? 'casual' : 'ftpt'][$uid] = $emp;
}
uksort($period_groups, function($a, $b) use ($period_order_pr) {
    return ((int)(array_search($a, $period_order_pr) ?? 99))
         - ((int)(array_search($b, $period_order_pr) ?? 99));
});
?>

<?php // ── Reusable macro: earnings cells for one employee row ── ?>
<?php // (inline because it needs $uid, $emp, $col_adds, $col_deds, $emp_deds in scope) ?>

<?php foreach ($period_groups as $pt => $groups):
    $sugg = $suggested_dates[$pt] ?? null;
    $pt_heading = $period_labels_pr[$pt] ?? ucfirst($pt);
?>
<div style="margin-top:1.5rem;padding-top:0.75rem;border-top:2px solid #c0c4d0;">
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin:0 0 0.5rem;max-width:960px;">
  <h3 style="margin:0;font-size:1em;text-transform:uppercase;letter-spacing:0.05em;color:#444;"><?= dol_htmlentities($pt_heading) ?></h3>
  <button type="submit" name="process_pt" value="<?= dol_htmlentities($pt) ?>" class="button buttonaction" style="font-size:0.9em;padding:0.3rem 1rem;">Process <?= dol_htmlentities($pt_heading) ?> →</button>
</div>

<div style="display:flex;align-items:center;gap:0.4rem 1.2rem;flex-wrap:wrap;margin:0 0 0.9rem;padding:0.5rem 0.75rem;background:#f0f2f8;border-radius:4px;max-width:960px;">
  <label style="white-space:nowrap;font-size:0.9em;"><strong>Period start:</strong></label>
  <input type="date" name="datesp_<?= $pt ?>"
         value="<?= dol_htmlentities(GETPOST('datesp_'.$pt,'alpha') ?: ($sugg['start'] ?? '')) ?>"
         class="flat" style="width:140px;">
  <label style="white-space:nowrap;font-size:0.9em;"><strong>Period end:</strong></label>
  <input type="date" name="dateep_<?= $pt ?>"
         value="<?= dol_htmlentities(GETPOST('dateep_'.$pt,'alpha') ?: ($sugg['end'] ?? '')) ?>"
         class="flat" style="width:140px;" onchange="recalcAll()">
  <label style="white-space:nowrap;font-size:0.9em;"><strong>Pay date:</strong></label>
  <input type="date" name="datep_<?= $pt ?>"
         value="<?= dol_htmlentities(GETPOST('datep_'.$pt,'alpha') ?: ($sugg['pay'] ?? '')) ?>"
         class="flat" style="width:140px;">
  <?php if ($sugg): ?>
  <small style="color:#667;">↑ Auto-filled from last <?= strtolower($pt_heading) ?></small>
  <?php endif; ?>
</div>

<?php if (!empty($groups['casual'])): ?>
<div style="font-size:0.8em;text-transform:uppercase;letter-spacing:0.05em;color:#888;margin:0 0 0.25rem;font-weight:600;">Casual</div>
<div style="overflow-x:auto;margin-bottom:1rem;">
<table class="noborder" style="min-width:820px;border-collapse:collapse;">
  <thead>
    <tr style="background:#e8eaf0;font-size:0.88em;">
      <th style="padding:0.5rem 0.4rem;width:1%;"></th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 0.4rem;text-align:center;">Type</th>
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
  <?php foreach ($groups['casual'] as $uid => $emp):
      $empname     = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;
      $is_salary   = ($emp->pay_rate_type ?? 'hourly') === 'salary';
      $pos_type    = $emp->position_type ?? '?';
      $is_included = ($action !== 'process') || (GETPOST('incl_'.$uid,'alpha') === '1');
  ?>
  <tr data-uid="<?= $uid ?>"<?= !$is_included ? ' style="opacity:0.4;"' : '' ?>>
    <td style="padding:0.4rem 0.5rem;text-align:center;vertical-align:middle;">
      <input type="checkbox" name="incl_<?= $uid ?>" value="1" <?= $is_included ? 'checked' : '' ?>
             onchange="toggleEmployeeRow(this,<?= $uid ?>)" style="width:16px;height:16px;cursor:pointer;">
    </td>
    <td style="padding:0.4rem 0.75rem;min-width:130px;">
      <?= dol_htmlentities($empname) ?>
      <?php if (!$emp->position_type): ?><br><small><a href="employee_payroll.php?userid=<?= $uid ?>">⚠ add profile</a></small><?php endif; ?>
    </td>
    <td style="padding:0.4rem 0.4rem;text-align:center;font-size:0.8em;"><?= dol_htmlentities($pos_type) ?></td>
    <?php if ($is_salary): ?>
      <td colspan="3" style="padding:0.4rem 0.25rem;"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="salary_<?= $uid ?>" id="salary_<?= $uid ?>" value="<?= GETPOST('salary_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>" min="0" step="0.01" style="width:90px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php else: ?>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ord_hrs_<?= $uid ?>" id="ord_hrs_<?= $uid ?>" value="<?= GETPOST('ord_hrs_'.$uid,'alpha') ?: (($emp->std_hours??0)>0 ? number_format((float)$emp->std_hours,1,'.',''): '') ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ot1_hrs_<?= $uid ?>" id="ot1_hrs_<?= $uid ?>" value="<?= GETPOST('ot1_hrs_'.$uid,'alpha') ?: '0' ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ot2_hrs_<?= $uid ?>" id="ot2_hrs_<?= $uid ?>" value="<?= GETPOST('ot2_hrs_'.$uid,'alpha') ?: '0' ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="rate_<?= $uid ?>" id="rate_<?= $uid ?>" value="<?= GETPOST('rate_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>" min="0" step="0.01" style="width:75px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php endif; ?>
    <?php foreach ($col_adds as $dtid => $dt): ?>
    <td style="padding:0.4rem 0.25rem;"><input type="number" name="add_<?= $uid ?>_<?= $dtid ?>" id="add_<?= $uid ?>_<?= $dtid ?>" value="<?= GETPOST('add_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>" min="0" step="0.01" style="width:80px;text-align:right;border-color:#1a7cb8;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php endforeach; ?>
    <td style="padding:0.4rem 0.75rem;text-align:right;background:#d4edda;font-weight:600;white-space:nowrap;">$<span id="gross_<?= $uid ?>">0.00</span></td>
    <?php foreach ($col_deds as $dtid => $dt):
        $show = $dt->is_mandatory || isset($emp_deds[$uid][$dtid]);
        $bg   = $dt->deduction_class === 'employer' ? 'background:#d4edda;' : '';
    ?>
    <td style="padding:0.4rem 0.25rem;<?= $bg ?>">
      <?php if ($show): ?><input type="number" name="ded_<?= $uid ?>_<?= $dtid ?>" id="ded_<?= $uid ?>_<?= $dtid ?>" value="<?= GETPOST('ded_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>" min="0" step="0.01" style="width:80px;text-align:right;" class="flat" onchange="recalcNet(<?= $uid ?>)" oninput="recalcNet(<?= $uid ?>)"><?php else: ?><span style="color:#ccc;display:block;text-align:center;">—</span><?php endif; ?>
    </td>
    <?php endforeach; ?>
    <td style="padding:0.4rem 0.75rem;text-align:right;background:#cfe2ff;font-weight:700;white-space:nowrap;">$<span id="net_<?= $uid ?>">0.00</span></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; // casual ?>

<?php if (!empty($groups['ftpt'])): ?>
<div style="font-size:0.8em;text-transform:uppercase;letter-spacing:0.05em;color:#888;margin:0 0 0.25rem;font-weight:600;">FT / PT</div>
<div style="overflow-x:auto;margin-bottom:1rem;">
<table class="noborder" style="min-width:820px;border-collapse:collapse;">
  <thead>
    <tr style="background:#e8eaf0;font-size:0.88em;">
      <th style="padding:0.5rem 0.4rem;width:1%;"></th>
      <th style="padding:0.5rem 0.75rem;text-align:left;">Employee</th>
      <th style="padding:0.5rem 0.4rem;text-align:center;">Type</th>
      <th style="padding:0.5rem 0.4rem;text-align:right;">Hrs / Salary</th>
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
  <?php foreach ($groups['ftpt'] as $uid => $emp):
      $empname     = trim(($emp->firstname ?? '') . ' ' . ($emp->lastname ?? '')) ?: $emp->login;
      $is_salary   = ($emp->pay_rate_type ?? 'hourly') === 'salary';
      $pos_type    = $emp->position_type ?? '?';
      $bal_al      = $leave_bals[$uid]['annual'] ?? 0.0;
      $bal_sick    = $leave_bals[$uid]['sick']   ?? 0.0;
      $bere_used   = $bere_ytd[$uid] ?? 0.0;
      $is_included = ($action !== 'process') || (GETPOST('incl_'.$uid,'alpha') === '1');
  ?>
  <tr data-uid="<?= $uid ?>"<?= !$is_included ? ' style="opacity:0.4;"' : '' ?>>
    <td style="padding:0.4rem 0.5rem;text-align:center;vertical-align:middle;">
      <input type="checkbox" name="incl_<?= $uid ?>" value="1" <?= $is_included ? 'checked' : '' ?>
             onchange="toggleEmployeeRow(this,<?= $uid ?>)" style="width:16px;height:16px;cursor:pointer;">
    </td>
    <td style="padding:0.4rem 0.75rem;min-width:140px;">
      <?= dol_htmlentities($empname) ?>
      <?php if (!$emp->position_type): ?><br><small><a href="employee_payroll.php?userid=<?= $uid ?>">⚠ add profile</a></small><?php endif; ?>
    </td>
    <td style="padding:0.4rem 0.4rem;text-align:center;font-size:0.8em;"><?= dol_htmlentities($pos_type) ?></td>
    <?php if ($is_salary): ?>
      <td colspan="3" style="padding:0.4rem 0.25rem;"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="salary_<?= $uid ?>" id="salary_<?= $uid ?>" value="<?= GETPOST('salary_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>" min="0" step="0.01" style="width:90px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php else: ?>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ord_hrs_<?= $uid ?>" id="ord_hrs_<?= $uid ?>" value="<?= GETPOST('ord_hrs_'.$uid,'alpha') ?: (($emp->std_hours??0)>0 ? number_format((float)$emp->std_hours,1,'.',''): '') ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ot1_hrs_<?= $uid ?>" id="ot1_hrs_<?= $uid ?>" value="<?= GETPOST('ot1_hrs_'.$uid,'alpha') ?: '0' ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="ot2_hrs_<?= $uid ?>" id="ot2_hrs_<?= $uid ?>" value="<?= GETPOST('ot2_hrs_'.$uid,'alpha') ?: '0' ?>" min="0" step="0.5" style="width:65px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
      <td style="padding:0.4rem 0.25rem;"><input type="number" name="rate_<?= $uid ?>" id="rate_<?= $uid ?>" value="<?= GETPOST('rate_'.$uid,'alpha') ?: number_format((float)($emp->pay_rate??0),2,'.','')?>" min="0" step="0.01" style="width:75px;text-align:right;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php endif; ?>
    <?php foreach ($col_adds as $dtid => $dt): ?>
    <td style="padding:0.4rem 0.25rem;"><input type="number" name="add_<?= $uid ?>_<?= $dtid ?>" id="add_<?= $uid ?>_<?= $dtid ?>" value="<?= GETPOST('add_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>" min="0" step="0.01" style="width:80px;text-align:right;border-color:#1a7cb8;" class="flat" onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)"></td>
    <?php endforeach; ?>
    <td style="padding:0.4rem 0.75rem;text-align:right;background:#d4edda;font-weight:600;white-space:nowrap;">$<span id="gross_<?= $uid ?>">0.00</span></td>
    <?php foreach ($col_deds as $dtid => $dt):
        $show = $dt->is_mandatory || isset($emp_deds[$uid][$dtid]);
        $bg   = $dt->deduction_class === 'employer' ? 'background:#d4edda;' : '';
    ?>
    <td style="padding:0.4rem 0.25rem;<?= $bg ?>">
      <?php if ($show): ?><input type="number" name="ded_<?= $uid ?>_<?= $dtid ?>" id="ded_<?= $uid ?>_<?= $dtid ?>" value="<?= GETPOST('ded_'.$uid.'_'.$dtid,'alpha') ?: '0.00' ?>" min="0" step="0.01" style="width:80px;text-align:right;" class="flat" onchange="recalcNet(<?= $uid ?>)" oninput="recalcNet(<?= $uid ?>)"><?php else: ?><span style="color:#ccc;display:block;text-align:center;">—</span><?php endif; ?>
    </td>
    <?php endforeach; ?>
    <td style="padding:0.4rem 0.75rem;text-align:right;background:#cfe2ff;font-weight:700;white-space:nowrap;">$<span id="net_<?= $uid ?>">0.00</span></td>
  </tr>
  <tr class="leave-row" data-uid="<?= $uid ?>" style="background:#eef2ff;border-top:1px dashed #c0c8e8;border-bottom:2px solid #c8d4f8;">
    <td colspan="20" style="padding:0.6rem 1rem 0.7rem;">
      <div style="display:flex;align-items:flex-end;flex-wrap:wrap;gap:0.5rem 2rem;">
        <div>
          <div style="font-size:0.75em;font-weight:700;color:#445;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.15rem;">Annual leave</div>
          <div style="font-size:0.8em;color:#777;margin-bottom:0.25rem;">
            Balance: <strong><span id="al_bal_<?= $uid ?>"><?= number_format($bal_al, 2) ?></span> h</strong>
            <span id="al_warn_<?= $uid ?>" style="color:#c00;display:none;margin-left:0.4rem;font-weight:700;">⚠ over balance</span>
          </div>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <input type="number" name="al_hrs_<?= $uid ?>" id="al_hrs_<?= $uid ?>"
                   value="<?= GETPOST('al_hrs_'.$uid,'alpha') ?: '0' ?>"
                   min="0" step="0.5" style="width:70px;text-align:right;" class="flat"
                   onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
            <span style="font-size:0.9em;color:#555;">h used</span>
          </div>
        </div>
        <div>
          <div style="font-size:0.75em;font-weight:700;color:#445;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.15rem;">Sick / carer's</div>
          <div style="font-size:0.8em;color:#777;margin-bottom:0.25rem;">
            Balance: <strong><span id="sick_bal_<?= $uid ?>"><?= number_format($bal_sick, 2) ?></span> h</strong>
            <span id="sick_warn_<?= $uid ?>" style="color:#c00;display:none;margin-left:0.4rem;font-weight:700;">⚠ over balance</span>
          </div>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <input type="number" name="sick_hrs_<?= $uid ?>" id="sick_hrs_<?= $uid ?>"
                   value="<?= GETPOST('sick_hrs_'.$uid,'alpha') ?: '0' ?>"
                   min="0" step="0.5" style="width:70px;text-align:right;" class="flat"
                   onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
            <span style="font-size:0.9em;color:#555;">h used</span>
          </div>
        </div>
        <div>
          <div style="font-size:0.75em;font-weight:700;color:#445;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.15rem;">Bereavement / compassionate</div>
          <div style="font-size:0.8em;color:#777;margin-bottom:0.25rem;">
            FY usage: <strong><?= number_format($bere_used, 2) ?> h</strong>
            <span style="color:#888;margin-left:0.3rem;">· FWA: 2 days per occasion</span>
          </div>
          <div style="display:flex;align-items:center;gap:0.35rem;">
            <input type="number" name="bere_hrs_<?= $uid ?>" id="bere_hrs_<?= $uid ?>"
                   value="<?= GETPOST('bere_hrs_'.$uid,'alpha') ?: '0' ?>"
                   min="0" step="0.5" style="width:70px;text-align:right;" class="flat"
                   onchange="recalcRow(<?= $uid ?>)" oninput="recalcRow(<?= $uid ?>)">
            <span style="font-size:0.9em;color:#555;">h this period</span>
          </div>
        </div>
        <div style="flex:1;min-width:240px;max-width:420px;">
          <div style="font-size:0.75em;font-weight:700;color:#445;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.15rem;">Leave note</div>
          <div style="font-size:0.8em;color:#777;margin-bottom:0.25rem;">Saved to audit ledger and payslip</div>
          <input type="text" name="leave_note_<?= $uid ?>" id="leave_note_<?= $uid ?>"
                 value="<?= dol_htmlentities(GETPOST('leave_note_'.$uid,'restricthtml') ?: '') ?>"
                 style="width:100%;" class="flat"
                 placeholder="e.g. Sick Mon–Tue, medical certificate provided" maxlength="255">
        </div>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; // ftpt ?>

</div><!-- end period group -->
<?php endforeach; // period_groups ?>

<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:0.75rem 1rem;max-width:850px;margin-bottom:1.5rem;font-size:0.88em;">
  <strong>PAYG</strong> = base withholding + HECS (if any) auto-calculated from ATO Schedule 1/8. Field is editable.<br>
  <strong>HECS</strong> is included in the PAYG column and posts to 2112 combined. A note in the journal entry records the HECS component.<br>
  <strong>Super</strong> (green) = employer contribution — does not reduce net pay. Defaults to 12% of applicable gross.<br>
  <strong style="color:#1a7cb8;">Additions</strong> (blue) = taxable extras (commission, allowances). Added to gross before PAYG. "+super" means this addition attracts SGC super.<br>
  Employees not on this pay run: leave all earnings blank or 0 — they will be skipped.
</div>

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
        'is_casual'        => in_array($emp->position_type ?? 'CA', ['CA', 'CAPT']),
        'rate'             => (float)($emp->pay_rate ?? 0),
        'ot1'              => (float)($emp->ot_rate1 ?? 1.5),
        'ot2'              => (float)($emp->ot_rate2 ?? 2.0),
        'period'           => $emp->pay_period ?? 'weekly',
        'scale'            => $emp->tax_scale ?? 'scale2',
        'has_hecs'         => (bool)($emp->has_hecs ?? false),
        'medicare_adj'     => (bool)($emp->has_medicare_adj ?? false),
        'medicare_deps'    => (int)($emp->medicare_dependants ?? 0),
        'bal_annual'       => (float)($leave_bals[$uid]['annual'] ?? 0),
        'bal_sick'         => (float)($leave_bals[$uid]['sick']   ?? 0),
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

function toggleEmployeeRow(cb, uid) {
    var opacity = cb.checked ? '' : '0.4';
    var row = document.querySelector('tr[data-uid="' + uid + '"]:not(.leave-row)');
    var leaveRow = document.querySelector('tr.leave-row[data-uid="' + uid + '"]');
    if (row) row.style.opacity = opacity;
    if (leaveRow) leaveRow.style.opacity = opacity;
}

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

    // Leave hours (FT/PT only — zero for casuals)
    var alHrs   = emp.is_casual ? 0 : (parseFloat((document.getElementById('al_hrs_'  +uid)||{value:0}).value)||0);
    var sickHrs = emp.is_casual ? 0 : (parseFloat((document.getElementById('sick_hrs_'+uid)||{value:0}).value)||0);
    var bereHrs = emp.is_casual ? 0 : (parseFloat((document.getElementById('bere_hrs_'+uid)||{value:0}).value)||0);

    // Balance warnings
    if (!emp.is_casual) {
        var alWarn   = document.getElementById('al_warn_'  +uid);
        var sickWarn = document.getElementById('sick_warn_'+uid);
        if (alWarn)   alWarn.style.display   = (alHrs   > emp.bal_annual) ? '' : 'none';
        if (sickWarn) sickWarn.style.display = (sickHrs > emp.bal_sick)   ? '' : 'none';
    }

    if (emp.is_salary) {
        var el = document.getElementById('salary_'+uid);
        baseGross = el ? (parseFloat(el.value)||0) : 0;
        // Salaried: leave hours don't change the dollar amount — salary is fixed per period
    } else {
        var ord = parseFloat((document.getElementById('ord_hrs_'+uid)||{value:0}).value)||0;
        var ot1 = parseFloat((document.getElementById('ot1_hrs_'+uid)||{value:0}).value)||0;
        var ot2 = parseFloat((document.getElementById('ot2_hrs_'+uid)||{value:0}).value)||0;
        var rt  = parseFloat((document.getElementById('rate_'+uid)||{value:0}).value)||0;
        // Leave hours are paid at base rate (same as ordinary hours)
        baseGross = Math.round((ord*rt + ot1*rt*emp.ot1 + ot2*rt*emp.ot2 + (alHrs+sickHrs+bereHrs)*rt)*100)/100;
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
