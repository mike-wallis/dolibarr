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
  &nbsp;|&nbsp;<a href="/dolibarr/custom/payroll/payruns.php?mainmenu=billing">Pay Run History</a>
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
    <li><a href="#setup-employee">One-time: add a new employee</a></li>
    <li><a href="#payroll-profile">One-time: set up each employee's payroll profile</a></li>
    <li><a href="#deductions">Deduction &amp; addition types explained</a></li>
    <li><a href="#additions">Additions to pay — commission, allowances, bonuses</a></li>
    <li><a href="#payrun">Running a pay run — step by step</a></li>
    <li><a href="#payslip">Payslips — results page, emailing, print all</a></li>
    <li><a href="#history">Pay Run History — viewing completed runs</a></li>
    <li><a href="#journal">What gets posted to the ledger</a></li>
    <li><a href="#pay-payg">Paying PAYG to the ATO (via BAS)</a></li>
    <li><a href="#pay-super">Paying super (Payday Super — SBSCH)</a></li>
    <li><a href="#each-july">Each July — annual update</a></li>
    <li><a href="#tax-config">Tax Table Config page — tabs explained</a></li>
    <li><a href="#balances">Checking payroll balances</a></li>
    <li><a href="#leave">Leave tracking — annual, sick &amp; bereavement</a></li>
    <li><a href="#hecs">STSL/HECS — how it works</a></li>
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

<!-- ── 2. Add new employee ──────────────────────────────────────────────────── -->
<h2 id="setup-employee">2. One-time: add a new employee</h2>

<p>Go to <strong>Billing | Payment &gt; Payroll Employees</strong> and click
<strong>+ Add New Employee</strong>. This opens a short form:</p>

<table class="noborder" style="width:100%;max-width:650px;margin-bottom:0.75rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Field</th>
      <th style="padding:0.4rem 1rem;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>First name</strong></td>
      <td style="padding:0.35rem 1rem;">Optional but recommended — used on payslips</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Last name</strong> <span style="color:#c00;">*</span></td>
      <td style="padding:0.35rem 1rem;">Required</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Email</strong></td>
      <td style="padding:0.35rem 1rem;">Optional — used on payslips and as their Dolibarr login email</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Login</strong> <span style="color:#c00;">*</span></td>
      <td style="padding:0.35rem 1rem;">Auto-filled as initial + surname (e.g. Jane Smith → <code>jsmith</code>). Edit if that login is already taken.</td>
    </tr>
  </tbody>
</table>

<p>Click <strong>Add employee &amp; set up payroll profile →</strong>. Two records are created:</p>
<ul>
  <li>A Dolibarr <strong>User</strong> with the Employee flag set — appears on pay runs</li>
  <li>A <strong>Contact</strong> linked to the user — visible under <em>Third Parties → Contacts</em></li>
</ul>
<p>You land straight on the payroll profile page to fill in the rest of their details.</p>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>No password is set by the Add Employee form.</strong> The employee record exists for payroll
  purposes — they don't need to log in. If you want them to have a Dolibarr login,
  set a password later via <em>Tools → Users &amp; Groups → Edit user → Change password</em>.
  Leave all module permission boxes unticked unless they need access to a specific area.
</div>

<details style="margin:0.5rem 0;max-width:700px;">
  <summary style="cursor:pointer;color:#555;font-size:0.9em;">Alternative: create via Tools → Users &amp; Groups</summary>
  <div style="padding:0.75rem 0 0 1rem;font-size:0.9em;">
    <ol>
      <li>Go to <strong>Tools → Users &amp; Groups → New user</strong></li>
      <li>Enter login, first name, last name, email</li>
      <li>Tick the <strong>Employee</strong> checkbox</li>
      <li>Save, then go to <strong>Payroll Employees</strong> and click <em>Set up payroll profile</em></li>
    </ol>
    <p style="margin:0.5rem 0 0;color:#777;">This does <em>not</em> create a Contact record automatically — use the Payroll Employees form if you want the Contact link.</p>
  </div>
</details>

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
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>HECS/HELP</strong></td>
          <td style="padding:0.35rem 0.8rem;">Tick if the employee has a student loan. Get their TFN declaration.</td>
        </tr>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>Super fund name</strong></td>
          <td style="padding:0.35rem 0.8rem;">e.g. AustralianSuper — appears on payslips and the super payments list</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>USI</strong></td>
          <td style="padding:0.35rem 0.8rem;">Unique Superannuation Identifier — required when submitting via SBSCH. Find it on the fund's website or <a href="https://superfundlookup.gov.au" target="_blank">superfundlookup.gov.au</a>.</td>
        </tr>
        <tr>
          <td style="padding:0.35rem 0.8rem;"><strong>Fund ABN</strong></td>
          <td style="padding:0.35rem 0.8rem;">Optional — from the same lookup</td>
        </tr>
        <tr style="background:#fafafa;">
          <td style="padding:0.35rem 0.8rem;"><strong>Member number</strong></td>
          <td style="padding:0.35rem 0.8rem;">Employee's membership number with the fund — on their member statement or welcome letter</td>
        </tr>
      </tbody>
    </table>
    <p style="margin-top:0.75rem;">Super fund details are <strong>snapshotted</strong> onto each pay run record at
    processing time, so payslips always show the fund details that were current at the time of payment —
    even if the employee changes fund later.</p>
    <p>The <strong>Optional deductions</strong> section lets you enable
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

<p>At the top of the pay run form, select the <strong>bank account</strong> and <strong>financial year</strong>.
These apply to the whole pay run.</p>

<p>Below that, the form shows <strong>one date block per pay period type</strong> — one for weekly employees,
one for fortnightly employees, and so on. Only the period types that have active employees are shown.</p>

<table class="noborder" style="width:100%;max-width:680px;margin-bottom:0.75rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Field</th>
      <th style="padding:0.4rem 1rem;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Period start</strong></td>
      <td style="padding:0.35rem 1rem;">First day of this period (e.g. 16 Jun 2026). Auto-filled from the last pay run for this period type — check before processing.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Period end</strong></td>
      <td style="padding:0.35rem 1rem;">Last day of this period (e.g. 22 Jun 2026 for weekly). Auto-filled.</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Pay date</strong></td>
      <td style="padding:0.35rem 1rem;">When the money hits employees' bank accounts. Auto-filled to the same day as last time.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Bank account</strong></td>
      <td style="padding:0.35rem 1rem;">Which bank account net pay is drawn from — applies to all employees.</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Financial year</strong></td>
      <td style="padding:0.35rem 1rem;">Current FY — controls super rate and HECS thresholds.</td>
    </tr>
  </tbody>
</table>

<p>If dates were auto-filled, a small "↑ Auto-filled from last [weekly] pay" hint appears. Always verify
the dates are correct — especially at the start of a new period or after a public holiday.</p>

<h3>Step 2 — Include / exclude employees</h3>

<p>Each employee row starts with a <strong>checkbox</strong>. All employees are ticked by default.
Untick any employee who should be skipped this period — their row dims to make it visually clear they
are excluded. Excluded employees get no salary record and no journal entries for this run.</p>

<p>Common reasons to exclude someone: they are on unpaid leave, they didn't work this period, or they
are on a different pay schedule being processed separately.</p>

<h3>Step 3 — Enter hours or confirm salary</h3>

<p>Under each period date block the employees are split into two sub-sections based on their position type:</p>

<div style="display:flex;gap:2rem;flex-wrap:wrap;max-width:800px;margin-bottom:0.75rem;">
  <div style="flex:1;min-width:240px;">
    <p><strong>Casual</strong> (position type CA or CAPT):</p>
    <ul>
      <li>Enter <em>Ord hrs</em> worked at base rate</li>
      <li>Enter <em>OT×1.5 hrs</em> and/or <em>OT×2.0 hrs</em> if applicable</li>
      <li>Rate defaults from their payroll profile — change if needed for this period</li>
      <li>No leave rows — casuals have no NES entitlement to annual or sick leave</li>
    </ul>
  </div>
  <div style="flex:1;min-width:240px;">
    <p><strong>FT / PT</strong> (FT, FTT, PT, AP, O):</p>
    <ul>
      <li>Hourly: enter <em>Ord hrs</em>, <em>OT×1.5</em>, <em>OT×2.0</em> as above</li>
      <li>Salaried: a single <em>Gross salary</em> field (defaults from profile)</li>
      <li>Each employee has a <strong>leave sub-row</strong> (see below)</li>
    </ul>
  </div>
</div>

<p>If you have employees on different pay schedules (e.g. some weekly, some fortnightly), each group gets
its own heading and date block. All weekly employees appear together, all fortnightly employees appear
together — you only see the groups that actually have active employees.</p>

<h4>Leave sub-row (FT / PT only)</h4>
<p>Below each FT/PT employee's hours row, a shaded band shows three leave fields:</p>
<table class="noborder" style="width:100%;max-width:780px;margin-bottom:0.75rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Field</th>
      <th style="padding:0.4rem 1rem;">What to enter</th>
      <th style="padding:0.4rem 1rem;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Annual leave</strong></td>
      <td style="padding:0.35rem 1rem;">Hours of paid annual leave taken this period</td>
      <td style="padding:0.35rem 1rem;">Current balance shown in brackets. ⚠ appears if hours exceed balance.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Sick / carer's</strong></td>
      <td style="padding:0.35rem 1rem;">Hours of personal / carer's leave taken this period</td>
      <td style="padding:0.35rem 1rem;">Current balance shown. ⚠ if hours exceed balance.</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Bereavement</strong></td>
      <td style="padding:0.35rem 1rem;">Hours taken for compassionate/bereavement leave</td>
      <td style="padding:0.35rem 1rem;">Shows FY usage to date. FWA: 2 days per occasion — no annual cap.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Leave note</strong></td>
      <td style="padding:0.35rem 1rem;">Optional explanation (e.g. "Sick Mon–Wed", "Dad's funeral")</td>
      <td style="padding:0.35rem 1rem;">Appears on the payslip and in the leave audit ledger.</td>
    </tr>
  </tbody>
</table>
<p>Leave the leave fields at 0 if no leave was taken that period. For hourly FT/PT employees,
leave hours are paid at base rate and add to gross — enter only the hours the employee was
<em>actually absent and paid as leave</em>, not ordinary working hours.</p>

<h3>Step 4 — Review calculated amounts</h3>
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

<h3>Step 5 — Process the pay run</h3>
<ol>
  <li>Check all amounts look right</li>
  <li>Click the <strong>Process [Period] Pay →</strong> button in the heading of the period group you want to process
      (e.g. <em>Process Weekly Pay →</em> for weekly employees). Each period group has its own button —
      you can run weekly and fortnightly employees independently.</li>
  <li>Dolibarr creates salary records and journal entries for the included employees in that period group
      (see <a href="#journal">Section 6</a>)</li>
  <li>A results page shows: a green header with the <strong>run reference</strong> (e.g. PR000042), pay period, and pay date;
      the employee summary table with View and Email payslip buttons; and below that a
      <strong>Super payments due</strong> table and <strong>Journal entries posted</strong> section</li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;max-width:700px;">
  <strong>Duplicate prevention:</strong> if you click Process again for the same pay period end date,
  Dolibarr will block it with an error for each affected employee — nothing is saved.
  This prevents accidental double-ups. To redo a pay run you must first delete the existing
  payrun_line record (and the linked salary/payment records) from the database.
</div>

<div class="alert alert-warning" style="margin:1rem 0;max-width:700px;">
  <strong>Transfer net pay from your bank separately.</strong> Dolibarr records the journal entry —
  it does not initiate a bank transfer. Log into your bank and send each employee their net pay amount.
</div>

<hr>

<!-- ── Payslips ────────────────────────────────────────────────────────────── -->
<h2 id="payslip">Payslips</h2>

<h3>Results page — after processing</h3>
<p>After processing, the results page shows a green header with the <strong>run reference</strong>
(e.g. PR000042), pay period, and pay date. Below that is the employee summary table with per-row buttons:</p>

<table class="noborder" style="width:100%;max-width:680px;margin-bottom:0.75rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Button</th>
      <th style="padding:0.4rem 1rem;">What it does</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>View</strong></td>
      <td style="padding:0.35rem 1rem;">Opens the print-ready payslip for that employee in a new tab</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Email</strong> (teal)</td>
      <td style="padding:0.35rem 1rem;">Sends the payslip as an HTML email to that employee's address. Only shown if the employee has an email on their profile. Uses Dolibarr's SMTP settings.</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Print all payslips</strong></td>
      <td style="padding:0.35rem 1rem;">Opens each payslip in a new browser tab simultaneously. Your browser must allow popups from localhost. Print each tab, or use "Print all tabs".</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Email all payslips</strong></td>
      <td style="padding:0.35rem 1rem;">Sends payslips to all employees who have an email address. Shows a summary of how many were sent and any failures.</td>
    </tr>
  </tbody>
</table>

<h3>What's on the payslip</h3>
<ul>
  <li><strong>Header:</strong> company name and ABN (from Dolibarr company settings), pay date</li>
  <li><strong>Employee:</strong> name, email, pay period dates, financial year</li>
  <li><strong>Earnings:</strong> ordinary hours, overtime (×1.5 and ×2.0), annual/sick/bereavement leave hours,
      plus any additions (commissions, allowances, bonuses) — with rates shown for each line</li>
  <li><strong>Deductions:</strong> PAYG withholding (includes HECS if applicable), any other employee deductions</li>
  <li><strong>Net pay</strong></li>
  <li><strong>Employer super:</strong> SGC amount; fund name, USI, and member number (snapshotted from the employee profile at the time of the pay run)</li>
  <li><strong>Leave balances</strong> (end of period — FT/PT only): annual leave and sick leave hours remaining; leave note if one was entered on the pay run</li>
  <li><strong>Year-to-date:</strong> gross, PAYG, super, and net YTD across all pay runs for this employee in the same financial year</li>
</ul>

<h3>Printing a single payslip</h3>
<p>Click <strong>Print payslip</strong> at the top of the payslip page. The Dolibarr navigation hides automatically
when printing — the printed page shows only the payslip content.</p>

<div class="alert alert-info" style="margin:1rem 0;max-width:700px;">
  <strong>Fair Work requirement:</strong> Employers must provide a payslip to each employee within
  1 business day of pay day. The payslip must include the mandatory fields listed above.
  Keep copies — employees can request payslips up to 7 years after the date of payment.
</div>

<hr>

<!-- ── Pay Run History ──────────────────────────────────────────────────────── -->
<h2 id="history">Pay Run History</h2>

<p>Go to <strong>Billing | Payment &gt; Pay Run History</strong> to browse all completed pay runs.
You can also get here immediately after processing via the <strong>← All runs</strong> button.</p>

<h3>List view</h3>
<p>One row per completed run, showing: run reference (PR######), pay period start → end, pay date, FY,
number of employees, and totals for gross, PAYG, super, and net pay. Filter by FY using the links at the top.
Click <strong>View</strong> on any row to open the detail view for that run.</p>

<h3>Detail view</h3>
<p>Shows the per-employee breakdown for that run with the same View and Email payslip buttons as the
post-processing results page. Also shows:</p>
<ul>
  <li>The green run header with reference number, period, and pay date</li>
  <li><strong>Print all payslips</strong> and <strong>Email all payslips</strong> buttons</li>
  <li>A <strong>Super payments due</strong> table (see below)</li>
</ul>

<h3>Super payments due table</h3>
<p>Shown on both the post-processing results page and the Pay Run History detail view. Lists each employee
with super_amount &gt; 0:</p>
<table class="noborder" style="width:100%;max-width:680px;margin-bottom:0.75rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 0.75rem;">Column</th>
      <th style="padding:0.4rem 1rem;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 0.75rem;"><strong>Super fund</strong></td>
      <td style="padding:0.35rem 1rem;">Snapshotted fund name. Shows <span style="color:#c00;">⚠ not set</span> if fund details weren't on the employee profile at pay time — go back and enter them via Payroll Employees &gt; Edit profile.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 0.75rem;"><strong>USI</strong></td>
      <td style="padding:0.35rem 1rem;">Unique Superannuation Identifier — required for SBSCH submission</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 0.75rem;"><strong>Member no.</strong></td>
      <td style="padding:0.35rem 1rem;">Employee's membership number with their fund</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 0.75rem;"><strong>Amount</strong></td>
      <td style="padding:0.35rem 1rem;">SGC contribution for this run (snapshotted at time of processing)</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 0.75rem;"><strong>SGC due</strong></td>
      <td style="padding:0.35rem 1rem;">28 days after the end of the quarter the pay date falls in. Q1 Jul–Sep → 28 Oct; Q2 Oct–Dec → 28 Jan; Q3 Jan–Mar → 28 Apr; Q4 Apr–Jun → 28 Jul.</td>
    </tr>
  </tbody>
</table>
<p>Click <strong>Print SBSCH list</strong> to print this table — use it as your entry sheet when logging
into ATO Online Services for Business &gt; SBSCH.</p>

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
  <li>After the pay run, use the <strong>Super payments due</strong> table on the results page — it lists each employee,
      their fund name, USI, member number, and super amount for that run. Use this as your SBSCH entry sheet.
      You can also find this table for any previous run via <strong>Pay Run History &gt; View</strong>
      (in case you need to re-check or print it later).</li>
  <li>Log in to <strong>ATO Online Services for Business</strong> &gt; SBSCH (Small Business Super Clearing House)</li>
  <li>Enter each employee's payment using the details from the super payments table and submit</li>
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

<!-- ── 12. Leave tracking ──────────────────────────────────────────────────────── -->
<h2 id="leave">12. Leave tracking — annual, sick &amp; bereavement</h2>

<p>The Payroll module tracks three leave types for full-time and part-time employees.
Casual employees have no entitlement to annual or sick leave under the NES — the module skips
leave accrual for employees with position type CA or CAPT.</p>

<table class="noborder" style="width:100%;max-width:820px;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Leave type</th>
      <th style="padding:0.4rem 1rem;">NES entitlement</th>
      <th style="padding:0.4rem 1rem;">Accrual</th>
      <th style="padding:0.4rem 1rem;">Balance tracked?</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Annual leave</strong></td>
      <td style="padding:0.35rem 1rem;">4 weeks/year of ordinary hours (FT/PT)</td>
      <td style="padding:0.35rem 1rem;font-family:monospace;">paid_hours ÷ 13</td>
      <td style="padding:0.35rem 1rem;">Yes — running balance; paid out on termination</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;"><strong>Sick / carer's leave</strong></td>
      <td style="padding:0.35rem 1rem;">10 days/year FT; pro-rata PT; shared pool</td>
      <td style="padding:0.35rem 1rem;font-family:monospace;">paid_hours ÷ 26</td>
      <td style="padding:0.35rem 1rem;">Yes — running balance; unused carries over</td>
    </tr>
    <tr>
      <td style="padding:0.35rem 1rem;"><strong>Bereavement / compassionate</strong></td>
      <td style="padding:0.35rem 1rem;">2 days per occasion (all employees incl. casuals); no annual cap</td>
      <td style="padding:0.35rem 1rem;">Does not accrue</td>
      <td style="padding:0.35rem 1rem;">No running balance — FY usage is shown on the pay run form for reference</td>
    </tr>
  </tbody>
</table>

<h3>How accrual is calculated</h3>
<p><strong>Paid ordinary hours</strong> = ordinary hours worked + any leave taken that period.
Leave accrues during paid leave — an employee on annual leave still accrues sick leave, and vice versa.
Overtime hours do not count toward accrual.</p>

<table class="noborder" style="width:100%;max-width:700px;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 1rem;">Employee type</th>
      <th style="padding:0.4rem 1rem;">Paid ordinary hours for accrual</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.35rem 1rem;">Hourly (FT/PT)</td>
      <td style="padding:0.35rem 1rem;">Ord hrs + annual leave taken + sick leave taken + bereavement taken</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.35rem 1rem;">Salaried (FT/PT)</td>
      <td style="padding:0.35rem 1rem;">Standard weekly hours × weeks in pay period (always full contracted hours)</td>
    </tr>
  </tbody>
</table>

<p><strong>Example — weekly, 38 h/wk FT employee:</strong></p>
<ul style="margin:0 0 0.5rem 1.5rem;">
  <li>Annual leave accrual per week: 38 ÷ 13 = <strong>2.92 h</strong></li>
  <li>Sick leave accrual per week: 38 ÷ 26 = <strong>1.46 h</strong></li>
  <li>Over a full year: 2.92 × 52 = 151.9 h annual leave ≈ 4 weeks; 1.46 × 52 = 76 h sick = 10 days ✓</li>
</ul>

<h3>Setting up an employee for leave tracking</h3>
<p>On the employee's <a href="/dolibarr/custom/payroll/employee_payroll.php">payroll profile</a>:</p>
<ol>
  <li><strong>Standard hours / week</strong> — enter their contracted weekly hours (38, 40, or the actual hours for part-time).
      This is required for salaried employees so the module knows their ordinary hours per period.</li>
  <li><strong>Position type</strong> — must be FT, FTT, PT, AP, or O for leave to accrue.
      CA and CAPT employees are skipped automatically.</li>
  <li><strong>Leave balances section</strong> — if the employee has existing entitlements (e.g. hours carried from a
      previous system), enter those here as opening balances and save. Leave blank to start at zero.</li>
</ol>

<h3>Entering leave on a pay run</h3>
<p>Each FT/PT employee row in the pay run form has a shaded <strong>leave sub-row</strong> with four fields
(see <a href="#payrun">Step 2</a> for the full table). In brief:</p>
<ul style="margin:0 0 0.5rem 1.5rem;">
  <li><strong>Annual leave</strong> — hours of paid annual leave taken this period. Current balance shown in brackets.
      A ⚠ warning appears if entered hours exceed the current balance.</li>
  <li><strong>Sick / carer's leave</strong> — hours of paid personal/carer's leave this period. Same balance display and warning.</li>
  <li><strong>Bereavement / compassionate</strong> — hours taken this period. FY usage to date is shown for reference.
      The NES provides 2 days per occasion — there is no annual cap, but leave this field at 0 unless bereavement leave was actually taken.</li>
  <li><strong>Leave note</strong> — optional free-text explanation (appears on the payslip and in the leave ledger).</li>
</ul>
<p>For <strong>hourly employees</strong>: leave hours are paid at base rate and added to gross pay.
   Enter ordinary hours worked separately — leave hours replace (or add to) ordinary hours depending on what actually happened.</p>
<p>For <strong>salaried employees</strong>: the salary amount does not change regardless of leave taken.
   The leave fields only update the leave balance records — leave them at 0 if no leave was taken.</p>

<h3>Leave loading (17.5%)</h3>
<p>The NES does not require leave loading — it is award-specific.
  Many awards (including cleaning services) include 17.5% leave loading on top of base rate when an employee takes annual leave.
  The module does not calculate this automatically. To pay leave loading:</p>
<ol>
  <li>Add an <strong>Annual Leave Loading</strong> addition type in <a href="/dolibarr/custom/payroll/setup.php">Payroll Setup</a>
      (set calc_type = manual, account = your wages expense account).</li>
  <li>On the pay run, enter the loading dollar amount manually in that employee's addition column.</li>
</ol>
<p>Confirm with your accountant or BAS agent which award applies and whether leave loading is payable.</p>

<h3>Viewing leave history</h3>
<p>Leave transactions (accruals, taken, opening balances) are stored in the
<code>llx_payroll_leave_transaction</code> table. Each transaction is linked to the salary record created
by that pay run (<code>fk_salary</code>). Current balances are in <code>llx_payroll_leave_balance</code>.
You can query these directly via the dev DB, or check the employee profile page which shows current balances.</p>

<h3>Termination — annual leave payout</h3>
<p>Under the NES, unused annual leave <strong>must be paid out</strong> when employment ends,
at the employee's current base rate (plus any leave loading entitlement under their award).
The module does not currently automate termination payouts — calculate the payout manually
(balance hours × current hourly rate), enter it as the final pay run, and record the leave
hours as annual leave taken to zero the balance. Mark the employee as inactive in Dolibarr after processing.</p>

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
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Pay run blocked — "already has a pay run recorded for period ending …"</td>
      <td style="padding:0.4rem 1rem;">That employee was already processed for the same pay period end date — either an accidental duplicate run or the dates weren't advanced correctly</td>
      <td style="padding:0.4rem 1rem;">Check <strong>Pay Run History</strong> — if the existing run is correct, do nothing. If it was a mistake, delete the relevant rows from <code>llx_payroll_payrun_line</code> (and the matching <code>llx_payroll_leave_transaction</code> rows if leave was recorded), then re-run with the correct dates. Do not delete records from a run that has already been paid or reported to the ATO.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Email payslip fails / not delivered</td>
      <td style="padding:0.4rem 1rem;">Dolibarr SMTP not configured, employee has no email address, or mail server rejected the message</td>
      <td style="padding:0.4rem 1rem;">Check <strong>Setup &gt; Emails</strong> — SMTP host, port, and credentials must be set. Confirm the employee's email address is on their Dolibarr user profile. Check the Dolibarr event log and your SMTP server's logs for the error message.</td>
    </tr>
  </tbody>
</table>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
