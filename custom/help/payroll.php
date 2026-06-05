<?php
require '../../main.inc.php';
llxHeader('', 'Help — Payroll');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Payroll</h1>

<p><strong>Division of responsibility:</strong> Reckon calculates and lodges everything (STP, PAYG
withholding, super). Dolibarr records the results so your P&amp;L and balance sheet stay correct.</p>

<p>Get the figures from your Reckon pay run first, then enter them here.</p>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>&#9888; Payday Super (from 1 July 2026):</strong> Super must be paid every pay run and reach
  the employee's fund within 7 business days. Submit to SBSCH on pay day — allow 3–4 days processing time.
</div>

<hr>

<h2>Accounts used</h2>
<table class="noborder" style="width:auto;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Account</th>
      <th style="padding:0.5rem 1rem;">What it records</th>
      <th style="padding:0.5rem 1rem;">Type</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>6400.10</strong> Wages &amp; Salaries</td>
      <td style="padding:0.4rem 1rem;">Gross wages cost</td>
      <td style="padding:0.4rem 1rem;">Expense</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>6450.02</strong> Super contrib. - SGC</td>
      <td style="padding:0.4rem 1rem;">Super guarantee (12% of ordinary time earnings)</td>
      <td style="padding:0.4rem 1rem;">Expense</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>2112</strong> PAYG TAX</td>
      <td style="padding:0.4rem 1rem;">PAYG withheld — owed to ATO</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>2111</strong> Super Guarantee</td>
      <td style="padding:0.4rem 1rem;">Super owed to employee funds</td>
      <td style="padding:0.4rem 1rem;">Liability</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>One-time setup — create employee users</h2>

<p>Each employee needs a Dolibarr user account so they can be selected when entering salaries.</p>

<ol>
  <li>Go to <strong>Users &amp; Groups &gt; New user</strong></li>
  <li>Fill in:
    <ul>
      <li><strong>Login:</strong> firstname (e.g. <code>jane</code>)</li>
      <li><strong>First name / Last name</strong></li>
      <li><strong>Employee:</strong> tick this checkbox</li>
      <li>Set a password (they don't need to log in — this is just a record)</li>
    </ul>
  </li>
  <li>Save</li>
  <li>Repeat for the second employee</li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;">
  Employee users don't need any module permissions — leave all permission boxes unticked.
  They exist only so you can select them on salary entries.
</div>

<hr>

<h2>Each pay run — step by step</h2>

<p>Do this for <strong>each employee</strong> after running payroll in Reckon.</p>

<h3>Step 1 — Get the figures from Reckon</h3>
<p>From the Reckon pay run, note for each employee:</p>
<ul>
  <li>Gross wages</li>
  <li>PAYG withheld (tax)</li>
  <li>Net pay (gross minus PAYG — this is what hits their bank account)</li>
  <li>Super (12% of ordinary time earnings)</li>
</ul>

<h3>Step 2 — Record the salary in Dolibarr</h3>
<ol>
  <li>Go to <strong>Billing | Payment</strong> &gt; left sidebar &gt; <strong>Salaries &gt; New salary</strong></li>
  <li>Select the employee</li>
  <li>Set the pay period dates</li>
  <li>Enter the <strong>Net pay</strong> amount (what you actually transferred to their account)</li>
  <li>Label: e.g. <em>Jane — pay period 1–14 Jun</em></li>
  <li>Save and validate</li>
  <li>Click <strong>Add payment</strong> — select bank01 (Macquarie), enter the payment date</li>
</ol>
<p>This records: Dr 6400.10 Wages / Cr Bank (net amount).</p>

<h3>Step 3 — Journal entry for PAYG and super</h3>
<p>One journal entry per pay run covers all employees.</p>
<ol>
  <li>Go to <strong>Accounting &gt; Journal entries &gt; New entry</strong></li>
  <li>Journal: General</li>
  <li>Date: pay date</li>
  <li>Add these lines (totals across all employees for this run):</li>
</ol>

<table class="noborder" style="width:100%;max-width:650px;margin:0.5rem 0 1rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">Account</th>
      <th style="padding:0.5rem 1rem;">Debit</th>
      <th style="padding:0.5rem 1rem;">Credit</th>
      <th style="padding:0.5rem 1rem;">Why</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">6400.10 Wages &amp; Salaries</td>
      <td style="padding:0.4rem 1rem;">PAYG amount</td>
      <td style="padding:0.4rem 1rem;"></td>
      <td style="padding:0.4rem 1rem;">Gross up wages expense</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">2112 PAYG TAX</td>
      <td style="padding:0.4rem 1rem;"></td>
      <td style="padding:0.4rem 1rem;">PAYG amount</td>
      <td style="padding:0.4rem 1rem;">Record liability to ATO</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">6450.02 Super SGC</td>
      <td style="padding:0.4rem 1rem;">Super amount</td>
      <td style="padding:0.4rem 1rem;"></td>
      <td style="padding:0.4rem 1rem;">Record super expense</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">2111 Super Guarantee</td>
      <td style="padding:0.4rem 1rem;"></td>
      <td style="padding:0.4rem 1rem;">Super amount</td>
      <td style="padding:0.4rem 1rem;">Record liability to super fund</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Example:</strong> Jane earns $800 gross. PAYG = $85. Net = $715. Super = $96.<br>
  Salary entry: $715 (net) — Dr 6400.10 / Cr Bank.<br>
  Journal entry: Dr 6400.10 $85 / Cr 2112 $85 &nbsp;&nbsp; Dr 6450.02 $96 / Cr 2111 $96.<br>
  Result: 6400.10 total = $800 gross ✓
</div>

<hr>

<h2>When you pay PAYG to the ATO (via BAS)</h2>
<p>PAYG withheld is reported and paid quarterly via your BAS.</p>
<ol>
  <li>Note the total from account <strong>2112 PAYG TAX</strong> — this is what you owe</li>
  <li>Pay via ATO Business Portal</li>
  <li>Record in Dolibarr — <strong>Accounting &gt; Journal entries &gt; New entry</strong>:
    <ul>
      <li>Dr 2112 PAYG TAX (amount paid)</li>
      <li>Cr Bank (amount paid)</li>
    </ul>
  </li>
</ol>

<hr>

<h2>When you pay super (via ATO SBSCH)</h2>

<div class="alert alert-warning" style="margin:0 0 1rem 0;">
  <strong>&#9888; Payday Super — law changed from 1 July 2026</strong><br>
  Super must now be paid <strong>every pay run</strong>, not quarterly.
  The money must reach the employee's super fund within <strong>7 business days</strong> of pay day.
  The old quarterly deadlines (28 Oct, 28 Jan, 28 Apr, 28 Jul) no longer apply.
</div>

<p>Use the <strong>ATO Small Business Superannuation Clearing House (SBSCH)</strong> — free for businesses with fewer than 20 employees. Allow 3–4 business days for SBSCH to process and forward the payment to the fund, so submit it a few days <em>before</em> or on pay day.</p>

<ol>
  <li>Calculate the super amount from your Reckon pay run (12% of ordinary time earnings)</li>
  <li>Log in to <strong>ATO Online Services for Business</strong> &gt; SBSCH &gt; make payment</li>
  <li>Submit on or before pay day to ensure it reaches the fund within 7 business days</li>
  <li>Record in Dolibarr — <strong>Accounting &gt; Journal entries &gt; New entry</strong>:
    <ul>
      <li>Dr 2111 Super Guarantee (amount paid)</li>
      <li>Cr Bank (amount paid)</li>
    </ul>
  </li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>Late super = SGC charge:</strong> The Superannuation Guarantee Charge applies if super is
  late or underpaid. SGC is <strong>not tax-deductible</strong> and includes interest. Under Payday Super,
  this risk occurs every pay run — not just quarterly.
  Verify current ATO guidance at <strong>ato.gov.au/paydaysuper</strong>.
</div>

<hr>

<h2>Checking payroll balances</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <p>Click <strong>Accounting</strong> in the top menu, then in the left sidebar under <strong>Accounting</strong>:</p>
    <ul>
      <li><strong>Account Balance</strong> — summary balance for every account. Filter on account number to check:
        <ul>
          <li><strong>2112</strong> — PAYG withheld, owed to ATO</li>
          <li><strong>2111</strong> — Super owed to employee funds</li>
        </ul>
      </li>
      <li><strong>Bookkeeping</strong> — full transaction list by account, useful to see individual pay run entries</li>
    </ul>
    <p>Also: <strong>Billing | Payment &gt; Salaries &gt; List</strong> shows all salary records by employee.</p>
  </div>

  <!-- Sidebar mock-up -->
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
      <div style="padding:0.2rem 0.75rem;color:#555;">Reportings</div>
    </div>
  </div>

</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
