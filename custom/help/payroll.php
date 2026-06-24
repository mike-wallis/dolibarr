<?php
require '../../main.inc.php';
llxHeader('', 'Help — Payroll');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Payroll Manual</h1>

<p>The Payroll module handles the full pay run in Dolibarr — it calculates PAYG withholding and HECS
using the ATO Schedule 1 formula, calculates SGC super, creates salary records, and posts all
journal entries automatically. You still need to transfer net pay from your bank and submit to
the ATO (PAYG via BAS, super via SBSCH).</p>

<div style="background:#f0f7ff;border:1px solid #b8d4f0;border-radius:6px;padding:0.75rem 1.2rem;margin:1rem 0;max-width:800px;">
  <strong>Quick links:</strong>
  &nbsp;<a href="/dolibarr/custom/payroll/employees.php?mainmenu=billing">Payroll Employees</a>
  &nbsp;|&nbsp;<a href="/dolibarr/custom/payroll/payrun.php?mainmenu=billing">Pay Run</a>
  &nbsp;|&nbsp;<a href="/dolibarr/custom/payroll/setup.php?mainmenu=billing">Payroll Setup (deductions)</a>
  &nbsp;|&nbsp;<a href="/dolibarr/custom/payroll/config.php?mainmenu=admintools">Tax Table Config</a>
</div>

<div style="background:#fff8e1;border:1px solid #f0c060;border-radius:6px;padding:0.75rem 1.2rem;margin:1rem 0 0;max-width:800px;">
  <strong>&#9888; Payday Super — from 1 July 2026:</strong>
  Super must be paid <strong>every pay run</strong> and reach the fund within <strong>7 business days</strong>.
  Submit to SBSCH on pay day. The old quarterly deadlines no longer apply.
</div>

<nav style="margin:1.5rem 0;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:0.75rem 1.2rem;max-width:800px;">
  <strong>Contents:</strong>
  <ol style="margin:0.5rem 0 0;padding-left:1.4rem;line-height:2;">
    <li><a href="#accounts">Accounts used</a></li>
    <li><a href="#setup-employee">One-time: create an employee user</a></li>
    <li><a href="#payroll-profile">One-time: set up each employee's payroll profile</a></li>
    <li><a href="#deductions">Deduction &amp; addition types explained</a></li>
    <li><a href="#additions">Additions to pay — commission, allowances, bonuses</a></li>
    <li><a href="#payrun">Running a pay run — step by step</a></li>
    <li><a href="#journal">What gets posted to the ledger</a></li>
    <li><a href="#pay-payg">Paying PAYG to the ATO (via BAS)</a></li>
    <li><a href="#pay-super">Paying super (Payday Super — SBSCH)</a></li>
    <li><a href="#each-july">Each July — annual update</a></li>
    <li><a href="#tax-config">Tax Table Config page — tabs explained</a></li>
    <li><a href="#balances">Checking payroll balances</a></li>
    <li><a href="#hecs">HECS/HELP — how it works</a></li>
    <li><a href="#medicare">Medicare levy — scales, exemptions &amp; adjustment</a></li>
    <li><a href="#troubleshoot">Troubleshooting</a></li>
  </ol>
</nav>

<hr>

<?php
// Load payroll account numbers from deduction type setup — used throughout this page.
// Falls back to generic account-type placeholders if no account is configured in
// Payroll Setup (6xxx = expense, 2xxx = liability, 1xxx = asset).
$_pr_acct = [];
$_pr_res  = $db->query(
    "SELECT code, account_debit, account_credit"
    . " FROM " . MAIN_DB_PREFIX . "payroll_deduction_type"
    . " WHERE code IN ('PAYG','SUPER') AND entity=" . (int)$conf->entity
);
if ($_pr_res) {
    while ($_pr_obj = $db->fetch_object($_pr_res)) {
        $_pr_acct[$_pr_obj->code] = $_pr_obj;
    }
}
$_wages_dr = htmlspecialchars(($_pr_acct['PAYG']->account_debit   ?? '') ?: '6xxx');
$_super_dr = htmlspecialchars(($_pr_acct['SUPER']->account_debit  ?? '') ?: '6xxx');
$_payg_cr  = htmlspecialchars(($_pr_acct['PAYG']->account_credit  ?? '') ?: '2xxx');
$_super_cr = htmlspecialchars(($_pr_acct['SUPER']->account_credit ?? '') ?: '2xxx');
$_payg_dr  = $_payg_cr; // payment account — same as PAYG liability unless separately configured
$_cs_cr    = '2xxx';
?>

<!-- ── 1. Accounts ──────────────────────────────────────────────────────────── -->
<h2 id="accounts">1. Accounts used</h2>

<table class="noborder" style="width:100%;max-width:750px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Account</th>
      <th style="padding:0.5rem 1rem;">Records</th>
      <th style="padding:0.5rem 1rem;">Type</th>
      <th style="padding:0.5rem 1rem;">Side</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong><?= $_wages_dr ?></strong> Wages &amp; Salaries</td>
      <td style="padding:0.4rem 1rem;">Gross wages cost (net pay + PAYG + HECS)</td>
      <td style="padding:0.4rem 1rem;">Expense</td>
      <td style="padding:0.4rem 1rem;">Dr</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong><?= $_super_dr ?></strong> Super SGC</td>
      <td style="padding:0.4rem 1rem;">Super guarantee expense (employer, on top of gross)</td>
      <td style="padding:0.4rem 1rem;">Expense</td>
      <td style="padding:0.4rem 1rem;">Dr</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong><?= $_payg_cr ?></strong> PAYG Tax — withheld</td>
      <td style="padding:0.4rem 1rem;">PAYG withheld each pay run (+ HECS if applicable) — owed to ATO</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
      <td style="padding:0.4rem 1rem;">Cr</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong><?= $_payg_dr ?></strong> PAYG Tax — payment</td>
      <td style="padding:0.4rem 1rem;">Dr when recording quarterly ATO payment — usually the same account as above</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
      <td style="padding:0.4rem 1rem;">Dr</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong><?= $_super_cr ?></strong> Super Guarantee</td>
      <td style="padding:0.4rem 1rem;">Super owed to employee funds</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
      <td style="padding:0.4rem 1rem;">Cr</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong><?= $_cs_cr ?></strong> Child Support</td>
      <td style="padding:0.4rem 1rem;">Child support deducted, owed to DHS (optional)</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
      <td style="padding:0.4rem 1rem;">Cr</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Bank</strong></td>
      <td style="padding:0.4rem 1rem;">Net pay to employees / ATO payment / super payment</td>
      <td style="padding:0.4rem 1rem;">Asset</td>
      <td style="padding:0.4rem 1rem;">Cr</td>
    </tr>
  </tbody>
</table>

<p><strong>The full cost per employee per pay run = Net pay + PAYG + HECS + Super.</strong>
Super is an employer cost, not deducted from the employee.</p>

<hr>

<!-- ── 2. Create employee user ──────────────────────────────────────────────── -->
<h2 id="setup-employee">2. One-time: create an employee user</h2>

<p>Each employee needs a Dolibarr user account so they appear on the Payroll Employees list and can be selected on pay runs.</p>

<ol>
  <li>Go to <strong>Users &amp; Groups &gt; New user</strong></li>
  <li>Fill in:
    <ul>
      <li><strong>Login:</strong> firstname, e.g. <code>jane</code></li>
      <li><strong>First name / Last name</strong></li>
      <li><strong>Employee:</strong> tick this checkbox</li>
      <li>Set a password (they don't need to log in — this is just a record)</li>
    </ul>
  </li>
  <li>Save. Repeat for each employee.</li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  Employee users don't need any module permissions — leave all permission boxes unticked.
  They exist only so you can select them on pay runs.
</div>

<hr>

<!-- ── 3. Payroll profile ───────────────────────────────────────────────────── -->
<h2 id="payroll-profile">3. One-time: set up each employee's payroll profile</h2>

<p>Go to <strong>Billing | Payment &gt; Payroll Employees</strong>. Each employee has a
<em>Set up payroll profile</em> button until their profile is configured.</p>

<div style="display:flex;gap:2rem;flex-wrap:wrap;align-items:flex-start;max-width:800px;">
  <div style="flex:1;min-width:280px;">
    <table class="noborder" style="width:100%;">
      <thead>
        <tr style="background:#f5f5f5;">
          <th style="padding:0.4rem 0.8rem;">Field</th>
          <th style="padding:0.4rem 0.8rem;">What to enter</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>Position type</strong></td>
          <td style="padding:0.35rem 0.8rem;">FT, Casual, Part Time, etc.</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>Pay period</strong></td>
          <td style="padding:0.35rem 0.8rem;">Weekly, Fortnightly, Monthly, etc.</td>
        </tr>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>Pay rate type</strong></td>
          <td style="padding:0.35rem 0.8rem;">Hourly OR Salary (for salaried employees, enter the salary per period)</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>Rate / Standard hours</strong></td>
          <td style="padding:0.35rem 0.8rem;">Hourly rate + standard hours per period, OR period salary amount</td>
        </tr>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>OT rates</strong></td>
          <td style="padding:0.35rem 0.8rem;">Overtime multipliers (casuals: typically ×1.5 and ×2.0)</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>Tax scale</strong></td>
          <td style="padding:0.35rem 0.8rem;">Scale 2 (TFT claimed) for most employees; see <a href="#tax-scales">tax scales</a></td>
        </tr>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>HECS/HELP</strong></td>
          <td style="padding:0.35rem 0.8rem;">Tick if the employee has a student loan. Get their TFN declaration.</td>
        </tr>
      </tbody>
    </table>
    <p style="margin-top:0.75rem;">The <strong>Optional deductions</strong> section at the bottom lets you enable
    Child Support for an individual employee and set the fixed amount per period.</p>
  </div>

  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Billing | Payment
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Pay Run</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Payroll Employees &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payroll Setup</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payroll Manual</div>
    </div>
  </div>
</div>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>Tax scale guide:</strong>
  <ul style="margin:0.4rem 0 0;">
    <li><strong>Scale 2</strong> — resident, tax-free threshold claimed on TFN declaration (most employees)</li>
    <li><strong>Scale 1</strong> — resident, did NOT claim the tax-free threshold</li>
    <li><strong>Scale 3</strong> — foreign resident</li>
    <li><strong>Scale 4</strong> — no TFN provided (47% flat rate)</li>
    <li><strong>Scale 5</strong> — full Medicare levy exemption (TFT claimed) — e.g. certain visa holders or medical exemptions</li>
    <li><strong>Scale 6</strong> — half Medicare levy exemption — employee has lodged a Medicare levy variation declaration (NAT 0929) claiming half exemption</li>
  </ul>
  <p style="margin:0.5rem 0 0;">See <a href="#medicare">Section 13</a> for Medicare levy adjustment (a further reduction for low-income earners on Scale 2 or Scale 6).</p>
</div>

<hr>

<!-- ── 4. Deduction types ───────────────────────────────────────────────────── -->
<h2 id="deductions">4. Deduction &amp; addition types explained</h2>

<p>Manage these under <strong>Billing | Payment &gt; Payroll Setup</strong>.</p>

<h3>Deductions &amp; employer contributions</h3>
<table class="noborder" style="width:100%;max-width:780px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Code</th>
      <th style="padding:0.5rem 1rem;">What it is</th>
      <th style="padding:0.5rem 1rem;">Who bears the cost</th>
      <th style="padding:0.5rem 1rem;">How calculated</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>PAYG</strong></td>
      <td style="padding:0.4rem 1rem;">Tax withheld for the ATO</td>
      <td style="padding:0.4rem 1rem;">Employee (deducted from gross)</td>
      <td style="padding:0.4rem 1rem;">Auto-calculated via ATO Schedule 1 formula — editable before processing</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>HECS</strong></td>
      <td style="padding:0.4rem 1rem;">Student loan repayment — sent to ATO with PAYG</td>
      <td style="padding:0.4rem 1rem;">Employee (deducted from gross)</td>
      <td style="padding:0.4rem 1rem;">Auto-calculated via ATO Schedule 8; shown combined with PAYG in account <?= $_payg_cr ?></td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>SUPER</strong></td>
      <td style="padding:0.4rem 1rem;">SGC superannuation guarantee</td>
      <td style="padding:0.4rem 1rem;">Employer (paid on top of gross — does NOT reduce net pay)</td>
      <td style="padding:0.4rem 1rem;">Auto-calculated: 12% of applicable gross (from 1 July 2025)</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>CS</strong></td>
      <td style="padding:0.4rem 1rem;">Child support — forwarded to DHS</td>
      <td style="padding:0.4rem 1rem;">Employee (deducted from gross)</td>
      <td style="padding:0.4rem 1rem;">Fixed $ per period — set per employee on their payroll profile</td>
    </tr>
  </tbody>
</table>

<hr>

<!-- ── 5. Additions ─────────────────────────────────────────────────────────── -->
<h2 id="additions">5. Additions to pay — commission, allowances, bonuses</h2>

<p>Additions are <strong>taxable extras that are added to gross before PAYG is calculated</strong>.
They <em>increase</em> net pay (they are not deducted). They appear as <span style="color:#1a7cb8;font-weight:bold;">blue columns</span>
on the pay run form.</p>

<table class="noborder" style="width:100%;max-width:780px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Code</th>
      <th style="padding:0.5rem 1rem;">What it is</th>
      <th style="padding:0.5rem 1rem;">Attracts super?</th>
      <th style="padding:0.5rem 1rem;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>COMM</strong></td>
      <td style="padding:0.4rem 1rem;">Commission</td>
      <td style="padding:0.4rem 1rem;">Yes (OTE)</td>
      <td style="padding:0.4rem 1rem;">Ordinary Time Earnings — SGC super applies</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>BONUS</strong></td>
      <td style="padding:0.4rem 1rem;">Bonus</td>
      <td style="padding:0.4rem 1rem;">Yes (OTE)</td>
      <td style="padding:0.4rem 1rem;">Ordinary Time Earnings — SGC super applies</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>CARALW</strong></td>
      <td style="padding:0.4rem 1rem;">Car allowance</td>
      <td style="padding:0.4rem 1rem;">No</td>
      <td style="padding:0.4rem 1rem;">Allowances are generally not OTE — verify with your accountant</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>TOOLALW</strong></td>
      <td style="padding:0.4rem 1rem;">Tool allowance</td>
      <td style="padding:0.4rem 1rem;">No</td>
      <td style="padding:0.4rem 1rem;">As above</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>PHRALW</strong></td>
      <td style="padding:0.4rem 1rem;">Phone allowance</td>
      <td style="padding:0.4rem 1rem;">No</td>
      <td style="padding:0.4rem 1rem;">As above</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>OTHRALW</strong></td>
      <td style="padding:0.4rem 1rem;">Other allowance</td>
      <td style="padding:0.4rem 1rem;">No</td>
      <td style="padding:0.4rem 1rem;">As above — confirm OTE status with your accountant</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>How additions work on the pay run form:</strong>
  <ul style="margin:0.4rem 0 0;">
    <li>Enter the addition amount in the blue column for the relevant employee</li>
    <li>The <strong>Gross</strong> column automatically includes the addition</li>
    <li><strong>PAYG</strong> is calculated on the full gross (base + all additions)</li>
    <li><strong>Super</strong> is calculated on a reduced base: base pay + <em>only OTE additions</em> (those marked +super). Non-OTE allowances do not attract super.</li>
    <li>Additions are <em>not</em> deducted from net pay — they add to it</li>
  </ul>
</div>

<div class="alert alert-warning" style="margin:1rem 0;max-width:700px;">
  <strong>Accountant/BAS agent check recommended.</strong> The OTE (super applicable) flag on each addition type is set in <a href="/dolibarr/custom/payroll/setup.php?mainmenu=billing">Payroll Setup</a>. The defaults above follow common ATO guidance but your specific allowances may differ — verify with your accountant before the first live pay run.
</div>

<hr>

<!-- ── 5. Pay run ───────────────────────────────────────────────────────────── -->
<h2 id="payrun">5. Running a pay run — step by step</h2>

<p>Go to <strong>Billing | Payment &gt; Pay Run</strong>.</p>

<h3>Step 1 — Set the period</h3>
<ul>
  <li><strong>Period start / end:</strong> dates covered by this pay run (e.g. 16 Jun – 22 Jun 2026)</li>
  <li><strong>Pay date:</strong> when the money hits employees' bank accounts</li>
  <li><strong>Bank account:</strong> which bank account the net pay is drawn from</li>
  <li><strong>Financial year:</strong> current FY — controls super rate and HECS thresholds</li>
</ul>

<h3>Step 2 — Enter hours or confirm salary</h3>

<div style="display:flex;gap:2rem;flex-wrap:wrap;max-width:800px;">
  <div style="flex:1;min-width:240px;">
    <p><strong>Hourly employees (casual / part time):</strong></p>
    <ul>
      <li>Enter <em>Ord hrs</em> (ordinary hours at base rate)</li>
      <li>Enter <em>OT×1.5 hrs</em> and/or <em>OT×2.0 hrs</em> if applicable</li>
      <li>Rate defaults from their payroll profile — change if needed</li>
      <li>Gross is calculated automatically: (ord hrs × rate) + (OT hrs × rate × multiplier)</li>
    </ul>
  </div>
  <div style="flex:1;min-width:240px;">
    <p><strong>Salaried employees:</strong></p>
    <ul>
      <li>A single <em>Gross salary</em> field appears — defaults from their profile</li>
      <li>Change it only if this period differs (e.g. extra day worked, salary increase)</li>
    </ul>
  </div>
</div>

<h3>Step 3 — Review calculated amounts</h3>
<p>For each employee the form shows:</p>
<table class="noborder" style="width:100%;max-width:700px;">
  <tbody>
    <tr style="background:#f5f5f5;">
      <td style="padding:0.35rem 1rem;"><strong>Gross</strong></td>
      <td style="padding:0.35rem 1rem;">Total before any deductions — this is the wages expense</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>PAYG</strong></td>
      <td style="padding:0.35rem 1rem;">Auto-calculated from ATO Schedule 1 (includes HECS if applicable). <em>Editable</em> — adjust if the STP service calculates a different amount.</td>
    </tr>
    <tr style="background:#f5f5f5;">
      <td style="padding:0.35rem 1rem;"><strong>Super</strong></td>
      <td style="padding:0.35rem 1rem;">SGC (12% of gross). Shown in green because it is an employer cost over and above the gross.</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Net pay</strong></td>
      <td style="padding:0.35rem 1rem;">What transfers to the employee's bank account: Gross − PAYG − CS (HECS is already inside PAYG)</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>PAYG is a guide, not a guarantee.</strong> The ATO Schedule 1 formula gives a close
  approximation. If you use an STP service (e.g. Xero Payroll, Reckon), use its PAYG figure
  and type it into the PAYG field. The field is always editable before you click Process.
</div>

<h3>Step 4 — Process the pay run</h3>
<ol>
  <li>Check all amounts look right</li>
  <li>Click <strong>Process pay run</strong></li>
  <li>Dolibarr creates salary records and journal entries for all employees (see <a href="#journal">Section 6</a>)</li>
  <li>A summary page shows each employee's amounts and the full journal entry breakdown — print or screenshot this for your records</li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;max-width:700px;">
  <strong>Transfer net pay from your bank separately.</strong> Dolibarr records the journal entry —
  it does not initiate a bank transfer. Log into your bank and send each employee their net pay amount.
</div>

<hr>

<!-- ── 6. Journal entries ───────────────────────────────────────────────────── -->
<h2 id="journal">6. What gets posted to the ledger</h2>

<p>When you process a pay run, Dolibarr creates the following automatically for each employee:</p>

<h3>BQ journal (bank payment — Finance journal)</h3>
<table class="noborder" style="width:100%;max-width:650px;margin-bottom:0.5rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Account</th>
      <th style="padding:0.4rem 1rem;">Dr</th>
      <th style="padding:0.4rem 1rem;">Cr</th>
      <th style="padding:0.4rem 1rem;">Amount</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><?= $_wages_dr ?> Wages &amp; Salaries</td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">Net pay</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;">Bank</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td style="padding:0.35rem 1rem;">Net pay</td>
    </tr>
  </tbody>
</table>

<h3>OD journal (general journal) — one line per deduction/contribution</h3>
<table class="noborder" style="width:100%;max-width:650px;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Account</th>
      <th style="padding:0.4rem 1rem;">Dr</th>
      <th style="padding:0.4rem 1rem;">Cr</th>
      <th style="padding:0.4rem 1rem;">Amount</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><?= $_wages_dr ?> Wages &amp; Salaries</td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">PAYG (incl. HECS)</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><?= $_payg_cr ?> PAYG Tax</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td style="padding:0.35rem 1rem;">PAYG (incl. HECS)</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><?= $_super_dr ?> Super SGC</td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">Super</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><?= $_super_cr ?> Super Guarantee</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td style="padding:0.35rem 1rem;">Super</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><?= $_wages_dr ?> Wages &amp; Salaries</td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">Child Support (if applicable)</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><?= $_cs_cr ?> Child Support</td>
      <td></td>
      <td style="padding:0.35rem 1rem;">✓</td>
      <td style="padding:0.35rem 1rem;">Child Support (if applicable)</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-info" style="margin:0 0 1rem;max-width:700px;">
  <strong>Result:</strong> Account <?= $_wages_dr ?> ends up with the full <em>gross</em> cost (net + PAYG + HECS + CS).
  Account <?= $_super_dr ?> records the additional super cost. Accounts <?= $_payg_cr ?>, <?= $_super_cr ?>, and your CS account hold the liabilities
  until you pay them.<br><br>
  <strong>HECS in account <?= $_payg_cr ?>:</strong> HECS is combined with PAYG in account <?= $_payg_cr ?> because both are
  remitted together to the ATO. The journal entry note for PAYG includes "(incl. HECS $X.XX)" so the
  split is visible in the bookkeeping detail.
</div>

<hr>

<!-- ── 7. Pay PAYG ──────────────────────────────────────────────────────────── -->
<h2 id="pay-payg">7. Paying PAYG to the ATO (via BAS)</h2>

<p>PAYG withheld is reported and paid quarterly through your BAS (label W2 on the IAS/BAS form).
Check account <strong><?= $_payg_cr ?> PAYG Tax</strong> balance — this is what you owe the ATO for the quarter.</p>

<ol>
  <li>At quarter end, check the balance of <strong><?= $_payg_cr ?> PAYG Tax</strong>
      (Accounting &gt; Account Balance, filter on <?= $_payg_cr ?>)</li>
  <li>Report it on your BAS at label <strong>W2</strong></li>
  <li>Pay via <strong>ATO Business Portal</strong> or bank transfer using the ATO payment reference</li>
  <li>Record in Dolibarr — <strong>Accounting &gt; Journal entries &gt; New entry</strong> (General journal):
    <ul>
      <li>Dr <strong><?= $_payg_cr ?> PAYG Tax</strong> — amount paid</li>
      <li>Cr <strong>Bank</strong> — amount paid</li>
    </ul>
  </li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  HECS repayments are included in the W2 amount — you do not need to break them out separately
  when paying the ATO. Dolibarr keeps them combined in account <?= $_payg_cr ?>.
</div>

<hr>

<!-- ── 8. Pay super ─────────────────────────────────────────────────────────── -->
<h2 id="pay-super">8. Paying super — Payday Super (from 1 July 2026)</h2>

<div class="alert alert-warning" style="margin:0 0 1rem;max-width:700px;">
  <strong>&#9888; Law changed from 1 July 2026.</strong>
  Super must be paid <strong>every pay run</strong>, not quarterly. The payment must
  reach the employee's super fund within <strong>7 business days</strong> of pay day.
  Submit to SBSCH on pay day (allow 3–4 days processing time). The old quarterly deadlines
  (28 Oct, 28 Jan, 28 Apr, 28 Jul) no longer apply. Late super incurs the SGC charge
  — which is <strong>not tax-deductible</strong>.
</div>

<h3>How to pay super each pay run</h3>
<ol>
  <li>Get the total super amount from the Pay Run summary (or check account <strong><?= $_super_cr ?></strong>)</li>
  <li>Log in to <strong>ATO Online Services for Business</strong> &gt; SBSCH (Small Business Super Clearing House)</li>
  <li>Enter the payment for each employee and submit</li>
  <li>Transfer the money from your bank to SBSCH on or before pay day</li>
  <li>Record in Dolibarr — <strong>Accounting &gt; Journal entries &gt; New entry</strong> (General journal):
    <ul>
      <li>Dr <strong><?= $_super_cr ?> Super Guarantee</strong> — amount paid</li>
      <li>Cr <strong>Bank</strong> — amount paid</li>
    </ul>
  </li>
</ol>

<p>SBSCH is free for businesses with fewer than 20 employees. Allow 3–4 business days for SBSCH
to process and forward payment to the fund, so the money must reach SBSCH <em>before</em> the 7-day deadline.</p>

<hr>

<!-- ── 9. Each July ─────────────────────────────────────────────────────────── -->
<h2 id="each-july">9. Each July — annual update</h2>

<p>Each financial year the ATO may change PAYG coefficients, HECS thresholds, and the SGC super rate.
Do this checklist at the start of each new financial year:</p>

<ol>
  <li>
    <strong>Check for new ATO tax tables</strong><br>
    Download <a href="https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld" target="_blank">NAT 1004 – Schedule 1</a>
    (PAYG coefficients) and
    <a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank">NAT 3539 – Schedule 8</a>
    (STSL combined coefficient tables — Tables 3–7 give combined PAYG+STSL per scale) from the ATO website.
    Also download the <strong>Withholding amounts sample data</strong> PDF (published alongside NAT 1004)
    — you'll need it to verify the new coefficients in step 4.
  </li>
  <li>
    <strong>Go to the Payroll config page</strong> (Setup &gt; Modules &gt; Payroll &gt; gear icon):
    <ul>
      <li><strong>Financial Years tab</strong> — add the new FY row, set the correct super rate
          and min wage, select the HECS system (marginal from 2025-26 onwards).</li>
      <li>
        <strong>Tax Coefficients tab</strong> — update the coefficients using one of these methods:
        <ul>
          <li><em>CSV import (easiest):</em> click <strong>Download template</strong> to get a pre-filled CSV,
              update the values from NAT 1004, then use <strong>Import from CSV</strong> to load them.
              Select the new FY before importing.</li>
          <li><em>PHP file + Seed:</em> create <code>custom/modules/payroll/lib/tax-tables/YYYY-YY.php</code>
              (copy the format from <code>2025-26.php</code>), update values, then click
              <strong>Seed from PHP file</strong>. Also add the new FY to <code>PaygCalculator::$fy_table_map</code>
              and <code>availableYears()</code>.</li>
          <li><em>Manual edit:</em> click Edit on individual rows if only a few values changed.</li>
        </ul>
      </li>
      <li>
        <strong>STSL / HECS tab</strong> — update the STSL combined coefficient tables (2025-26+) or
        legacy HECS bracket thresholds (2024-25 only) using CSV import or Seed from PHP file.
        For 2025-26+, seed the five <code>stsl_scale*</code> tables from NAT 3539 Schedule 8 (Tables 3–7).
        Only needed when the ATO publishes updated Schedule 8 coefficient values.
      </li>
    </ul>
  </li>
  <li>
    <strong>Check the Fair Work minimum wage</strong> — update the <em>Min wage</em> field on the
    Financial Years tab (reference only, used in pay run for casual rate checks).
  </li>
  <li>
    <strong>Import and verify ATO sample data</strong>:
    <ol style="margin:0.4rem 0 0 1rem;">
      <li>Go to the <strong>Payroll config page</strong> &gt; <strong>Verification Tests</strong> tab.</li>
      <li>The tab has four sections — one for each ATO dataset. For each dataset:
          download the ATO's CSV from the ATO website, then import it for the new FY using the
          <strong>Import CSV</strong> form in that section. Pre-built 2026-27 CSV files are also
          available at <code>imports/payroll/</code> (see below).</li>
      <li>Go to <strong>Payroll Setup</strong> (left menu) and scroll to
          <strong>PAYG Calculation Verification</strong>. All tests should show ✓.</li>
    </ol>
    If any test fails, re-check the coefficient values against the NAT 1004 PDF.
  </li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  The PAYG field on the pay run form is always editable, so approximate coefficients are workable
  for testing. But verify against the official NAT 1004 PDF before the first live pay run of the new FY.
</div>

<hr>

<!-- ── Tax Table Config page ─────────────────────────────────────────────────── -->
<h2 id="tax-config">Tax Table Config page — tabs explained</h2>

<p>The <strong>Tax Table Config</strong> page is where all ATO tax data lives — coefficients,
HECS thresholds, financial year settings, and verification test cases. Access it via the
<a href="/dolibarr/custom/payroll/config.php?mainmenu=admintools">Tax Table Config</a> quick link
at the top of this page, or via <strong>Setup → Modules → Payroll → gear icon</strong>.</p>

<p>It has three tabs:</p>

<table class="noborder" style="width:100%;max-width:820px;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;white-space:nowrap;">Tab</th>
      <th style="padding:0.5rem 1rem;">What it contains</th>
      <th style="padding:0.5rem 1rem;">When to use it</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;white-space:nowrap;"><strong>Financial Years</strong></td>
      <td style="padding:0.4rem 1rem;">One row per FY — SGC rate (%), minimum weekly wage, HECS system (flat or marginal), and period dates (1 Jul – 30 Jun).
          The dates are used at pay-run time to auto-select the correct tax tables for a given pay date.
          A row must exist before you can run a pay run for that FY.</td>
      <td style="padding:0.4rem 1rem;">Add a row at the start of each new FY. Update SGC rate when it changes (11.5% for 2024-25, 12% from 2025-26).</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;white-space:nowrap;"><strong>Tax Tables</strong></td>
      <td style="padding:0.4rem 1rem;">Four sections — all ATO tax data in one place:
          <ol style="margin:0.3rem 0 0 1rem;font-size:0.9em;">
            <li><strong>PAYG Coefficients (NAT 1004)</strong> — <em>a</em> and <em>b</em> values for every tax scale and weekly income bracket.
                The pay-run formula: <code>withholding = round(a × (floor(weekly_gross) + 0.99) − b)</code>.</li>
            <li><strong>MLA Scale 2 Parameters (NAT 1008)</strong> — The 7 threshold/rate values used by the Medicare levy adjustment formula for Scale 2 (resident, TFT claimed) employees with a Medicare levy variation declaration.</li>
            <li><strong>MLA Scale 6 Parameters (NAT 1009)</strong> — Same formula, different values. For Scale 6 (half Medicare levy exemption) employees with children.</li>
            <li><strong>STSL Combined Tables (NAT 3539 – Schedule 8)</strong> — Combined PAYG+STSL coefficient tables for employees with a student loan (HELP/VSL/SSL/TSL/SFSS).
                From 2025-26, five <code>stsl_scale*</code> tables (one per tax scale) replace the old income bracket approach.
                The calculator uses the same <code>round(a × x − b)</code> formula as Schedule 1; STSL = combined − Schedule 1 PAYG.
                For 2024-25 and earlier: legacy bracket thresholds are stored in the <code>hecs_YYYY_YY</code> arrays.</li>
          </ol>
          Each section has: <em>Seed from PHP file</em>, <em>Import CSV</em>, <em>Download bundled file</em>, and <em>Add/edit individual rows</em>.
          Bundled 2026-27 files are already loaded and available to download from the page.</td>
      <td style="padding:0.4rem 1rem;">Update all four sections each July when the ATO publishes new values.
          MLA parameters (sections 2 and 3) often don't change year to year — check the ATO page to confirm before reloading.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;white-space:nowrap;"><strong>Verification Tests</strong></td>
      <td style="padding:0.4rem 1rem;">Four import forms for the ATO's four sample data files:
          Withholding Amounts, MLA Scale 2, MLA Scale 6, and STSL.
          Pre-built 2026-27 files are available as bundled downloads on this tab (from <code>data/</code>).</td>
      <td style="padding:0.4rem 1rem;">Import all four datasets each July after updating tax tables, then go to
          <strong>Payroll Setup</strong> and scroll to <strong>PAYG Calculation Verification</strong> to run pass/fail tests.</td>
    </tr>
  </tbody>
</table>

<h3 style="margin-top:1rem;">Loading data — methods available on each Tax Tables section</h3>

<table class="noborder" style="width:100%;max-width:820px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Method</th>
      <th style="padding:0.5rem 1rem;">How</th>
      <th style="padding:0.5rem 1rem;">Best for</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Download bundled file</strong></td>
      <td style="padding:0.4rem 1rem;">Click <strong>Download</strong> in the "Bundled ATO data" card next to each section.
          A year selector appears if multiple years are available. These pre-built files live in
          <code>data/</code> inside the payroll module and are ready to use without modification.</td>
      <td style="padding:0.4rem 1rem;">First-time setup or restoring data — no editing needed.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Import from CSV</strong></td>
      <td style="padding:0.4rem 1rem;">Download a bundled file or the template, update values from the ATO PDF/page,
          then use <strong>Import from CSV</strong>. Select the FY before importing.
          Replaces existing rows for the selected FY (and any scales present in the file).</td>
      <td style="padding:0.4rem 1rem;">Annual update when ATO changes the values — edit the CSV from last year.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Seed from PHP file</strong><br><small style="color:#888;">(Coefficients and STSL only)</small></td>
      <td style="padding:0.4rem 1rem;">Create <code>lib/tax-tables/YYYY-YY.php</code> with the new values
          (copy from <code>2026-27.php</code>), deploy, then click <strong>Seed</strong> on the relevant section.
          Also update <code>PaygCalculator::$fy_table_map</code> and <code>availableYears()</code>.</td>
      <td style="padding:0.4rem 1rem;">Developer workflow — keeps ATO data in the git repo.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Seed defaults</strong><br><small style="color:#888;">(MLA sections only)</small></td>
      <td style="padding:0.4rem 1rem;">Click <strong>Seed</strong> on the MLA Scale 2 or MLA Scale 6 section.
          Inserts or updates the 7 ATO MLA threshold/rate parameters for the selected FY using the hardcoded 2026-27 values.
          Safe to re-run — uses INSERT … ON DUPLICATE KEY UPDATE.</td>
      <td style="padding:0.4rem 1rem;">First-time setup, or when the ATO confirms no change to MLA parameters year to year.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Edit individual rows</strong></td>
      <td style="padding:0.4rem 1rem;">Click <strong>Edit</strong> on any row in the existing table and save in-place.</td>
      <td style="padding:0.4rem 1rem;">Minor corrections (one or two values changed).</td>
    </tr>
  </tbody>
</table>

<hr>

<!-- ── 10. Checking balances ────────────────────────────────────────────────── -->
<h2 id="balances">10. Checking payroll balances</h2>


<div style="display:flex;gap:2rem;flex-wrap:wrap;align-items:flex-start;max-width:800px;">
  <div style="flex:1;min-width:280px;">
    <p>Go to <strong>Accounting</strong> in the top menu, then in the left sidebar:</p>
    <ul>
      <li><strong>Account Balance</strong> — summary balance per account. Check:
        <ul>
          <li><strong><?= $_payg_cr ?></strong> — PAYG withheld, owed to ATO this quarter</li>
          <li><strong><?= $_super_cr ?></strong> — super owed to employee funds (should be zero each pay run from 1 Jul 2026)</li>
          <li><strong><?= $_wages_dr ?></strong> — total gross wages YTD</li>
          <li><strong><?= $_super_dr ?></strong> — total super expense YTD</li>
        </ul>
      </li>
      <li><strong>Bookkeeping</strong> — full transaction detail. Filter by account to see individual pay run entries.</li>
    </ul>
    <p>Also: <strong>Billing | Payment &gt; Salaries &gt; List</strong> shows all salary records by employee.</p>
  </div>

  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Accounting
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Bookkeeping</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Journals</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Account Balance &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Export Accountancy</div>
    </div>
  </div>
</div>

<hr>

<!-- ── 11. HECS / STSL ────────────────────────────────────────────────────────── -->
<h2 id="hecs">11. STSL/HECS — how it works</h2>

<p>STSL (Study and Training Support Loans) covers HELP, VSL, SSL, TSL, and SFSS student debt.
Employees with a STSL debt have additional withholding calculated each pay and remitted to the ATO
together with their PAYG. Tick <strong>HECS/HELP</strong> on their payroll profile to enable it.</p>

<h3>Which system applies?</h3>
<table class="noborder" style="width:100%;max-width:780px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">FY</th>
      <th style="padding:0.4rem 1rem;">System</th>
      <th style="padding:0.4rem 1rem;">How it works</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Up to 2024-25</td>
      <td style="padding:0.4rem 1rem;">Flat rate on annualised income</td>
      <td style="padding:0.4rem 1rem;">The module annualises the weekly gross, applies the ATO flat-rate table (e.g. 3% on total income above $54,435), then scales back to the pay period.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">2025-26+</td>
      <td style="padding:0.4rem 1rem;">ATO Schedule 8 coefficient tables</td>
      <td style="padding:0.4rem 1rem;">Uses the same coefficient formula as PAYG (Schedule 1) but with <em>combined</em> tables that give PAYG + STSL in one step. No annualisation needed. Source: <a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank">NAT 3539 – Schedule 8</a>.</td>
    </tr>
  </tbody>
</table>

<h3>How the 2025-26+ coefficient approach works</h3>
<p>ATO Schedule 8 (Tables 3–7) publishes a separate set of <em>combined</em> <code>a</code> and <code>b</code>
coefficients for each tax scale. These give the total withholding (PAYG + STSL) in one calculation:</p>
<ol>
  <li>Compute <code>x = floor(weekly_gross) + 0.99</code> — same as Schedule 1</li>
  <li>Look up the <code>stsl_scale*</code> table for the employee's scale</li>
  <li><code>weekly_combined = round(a × x − b)</code> — the total (PAYG + STSL)</li>
  <li>Scale to the pay period (monthly: × 13 ÷ 3; fortnightly: × 2)</li>
  <li>STSL component = combined total − Schedule 1 PAYG (shown separately on the pay run form)</li>
</ol>
<p>Scale 4 (no TFN) is exempt from STSL — the 47% flat rate applies regardless.</p>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>STSL on the pay run form:</strong> the PAYG column shows the full withholding including any STSL
  component (e.g. "PAYG $246 (incl. STSL $12)"). Both are remitted to the ATO via BAS (label W2).
</div>

<p>Verify current Schedule 8 coefficient tables at
<a href="https://www.ato.gov.au/tax-rates-and-codes/schedule-8-statement-of-formulas-for-calculating-study-and-training-support-loans-components" target="_blank">ato.gov.au — Schedule 8 (NAT 3539)</a>.
A built-in verification test (885 ATO sample data rows) is available on the
<a href="/dolibarr/custom/payroll/config.php?mainmenu=admintools&tab=tests">Tax Table Config — Tests tab</a>.</p>

<hr>

<!-- ── 13. Medicare ─────────────────────────────────────────────────────────── -->
<h2 id="medicare">13. Medicare levy — scales, exemptions &amp; adjustment</h2>

<h3>Medicare levy and the tax scales</h3>
<p>The standard Medicare levy (2% of taxable income) is already embedded in the Scale 1 and Scale 2
withholding coefficients. You do not need to calculate it separately. Employees with a full or half
exemption use a different scale:</p>

<table class="noborder" style="width:100%;max-width:700px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Scale</th>
      <th style="padding:0.4rem 1rem;">When to use</th>
      <th style="padding:0.4rem 1rem;">Declaration required</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Scale 1 or 2</strong></td>
      <td style="padding:0.35rem 1rem;">Most employees (full Medicare levy included in coefficients)</td>
      <td style="padding:0.35rem 1rem;">TFN declaration only</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Scale 5</strong></td>
      <td style="padding:0.35rem 1rem;">Full Medicare levy exemption — certain visa holders, specific medical exemptions</td>
      <td style="padding:0.35rem 1rem;">Medicare levy variation declaration (NAT 0929), question 8</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Scale 6</strong></td>
      <td style="padding:0.35rem 1rem;">Half Medicare levy — partial exemption</td>
      <td style="padding:0.35rem 1rem;">Medicare levy variation declaration (NAT 0929), question 7</td>
    </tr>
  </tbody>
</table>

<p>To change an employee's tax scale, go to <strong>Billing | Payment &gt; Payroll Employees</strong>
and open their payroll profile.</p>

<h3>Medicare levy adjustment — for low-income earners</h3>

<p>An employee on <strong>Scale 2</strong> (earning ≥ $538/week) or <strong>Scale 6</strong>
(≥ $908/week) may be entitled to a <em>Medicare levy adjustment</em> — a reduction in their
withholding because their expected annual income falls in the Medicare levy low-income zone.</p>

<p>This only applies if the employee has lodged a <strong>Medicare levy variation declaration
(NAT 0929)</strong> and answered yes to questions 9 (spouse/partner) and/or 12 (dependent children)
and question 10.</p>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>Who this applies to in practice:</strong> An employee earning roughly $540–$1,135/week
  on Scale 2 with a spouse or dependent children, where the family income is expected to be low
  enough to reduce their Medicare levy liability. Most employees at standard wages above $1,135/week
  will be past the shading-out point and no adjustment applies.
</div>

<h3>How to set it up</h3>
<ol>
  <li>The employee lodges a Medicare levy variation declaration (NAT 0929) with you</li>
  <li>Go to <strong>Billing | Payment &gt; Payroll Employees</strong> and open their payroll profile</li>
  <li>Tick <strong>Medicare levy adjustment applicable</strong></li>
  <li>Enter the number of <strong>Dependent children</strong> from question 12 (or 0 if only a spouse was claimed at question 9)</li>
  <li>Save — the adjustment will automatically reduce their PAYG on the next pay run</li>
</ol>

<h3>How the adjustment is calculated</h3>
<p>The system uses the ATO's published formula (NAT 1004, published 17 June 2026). The weekly
levy adjustment (WLA) is based on the employee's weekly earnings and their weekly family threshold
(WFT), which depends on the number of dependent children:</p>

<table class="noborder" style="width:100%;max-width:680px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Earnings (x = floor + $0.99)</th>
      <th style="padding:0.4rem 1rem;">Formula (Scale 2)</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;">Below $538.67</td>
      <td style="padding:0.35rem 1rem;">No adjustment</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;">$538.67 up to $673</td>
      <td style="padding:0.35rem 1rem;">WLA = (x − $538.67) × 10%</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;">$673 up to WFT</td>
      <td style="padding:0.35rem 1rem;">WLA = x × 2%</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;">WFT up to shading-out point</td>
      <td style="padding:0.35rem 1rem;">WLA = (WFT × 2%) − ([x − WFT] × 8%) — reduces toward zero</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;">Above shading-out point</td>
      <td style="padding:0.35rem 1rem;">No adjustment</td>
    </tr>
  </tbody>
</table>

<p>WFT (no children, spouse only) = $908.42/week. Each dependent child adds ~$83.42/week to the WFT.
The shading-out point is WFT ÷ 0.08 × 0.1. WLA is rounded to the nearest dollar and then scaled to
the employee's pay period (fortnightly × 2; monthly × 13 ÷ 3).</p>

<p>You can verify the calculated WLA against the ATO's own examples at
<a href="https://www.ato.gov.au/tax-rates-and-codes/tax-tables/medicare-levy-adjustment" target="_blank">ato.gov.au — Medicare levy adjustment</a>.
A built-in verification test is also available in <a href="/dolibarr/custom/payroll/setup.php?mainmenu=billing">Payroll Setup</a>.</p>

<hr>

<!-- ── 14. Troubleshoot ─────────────────────────────────────────────────────── -->
<h2 id="troubleshoot">14. Troubleshooting</h2>

<table class="noborder" style="width:100%;max-width:780px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Symptom</th>
      <th style="padding:0.4rem 1rem;">Likely cause</th>
      <th style="padding:0.4rem 1rem;">Fix</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Employee not on Pay Run form</td>
      <td style="padding:0.4rem 1rem;">No payroll profile set up, or user is not marked as Employee</td>
      <td style="padding:0.4rem 1rem;">Go to Payroll Employees and click Set up payroll profile; check user has Employee checkbox ticked</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">PAYG seems too high or too low</td>
      <td style="padding:0.4rem 1rem;">Wrong tax scale selected; Medicare levy adjustment not enabled; or employee earning below $538/wk where no withholding applies on Scale 2</td>
      <td style="padding:0.4rem 1rem;">Check employee tax scale on their payroll profile. If eligible, tick <em>Medicare levy adjustment applicable</em>. Use the PAYG verification test in Payroll Setup to confirm the calculator matches ATO sample data. Override the PAYG field manually if needed.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">No HECS calculated despite ticking HECS</td>
      <td style="padding:0.4rem 1rem;">Income below the HECS threshold, or HECS brackets not in DB</td>
      <td style="padding:0.4rem 1rem;">If income is above $67,000/yr, seed HECS brackets from the config page (Tax Config &gt; HECS tab &gt; Seed)</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">SQL error when enabling module</td>
      <td style="padding:0.4rem 1rem;">Semicolon in a SQL comment line (the init() parser splits on semicolons)</td>
      <td style="padding:0.4rem 1rem;">Check all SQL files in <code>custom/modules/payroll/sql/</code> — no semicolons or non-ASCII characters in comment lines</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Gear icon missing from module card</td>
      <td style="padding:0.4rem 1rem;">Old version of modPayroll.class.php cached (OPcache) or wrong deploy path</td>
      <td style="padding:0.4rem 1rem;">Restart Apache from WAMP tray, then Ctrl+F5. Make sure files are deployed to <code>htdocs/custom/payroll/</code> (use <code>scripts/deploy.ps1</code>)</td>
    </tr>
  </tbody>
</table>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
