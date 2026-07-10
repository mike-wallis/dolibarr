<?php
require '../../main.inc.php';
llxHeader('', 'Help — Expense Reports');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Expense Reports (Reimbursing Yourself or Staff)</h1>

<p>Use this workflow when you (or a staff member) buy something for the business personally
and need to be paid back — fuel, a quick supplies run, parking, etc. This is different from
<a href="expense-invoices.php">Expense Invoices</a>, which is for bills that arrive
<em>from a supplier</em> (phone, electricity) and get paid straight from the business bank
account — nobody is being personally reimbursed there.</p>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Why not just a bank entry?</strong> A bank entry only records money leaving the bank —
  it can't track that the business currently <em>owes you</em> money between the day you spend it
  and the day you're reimbursed, and it won't sort the cost into the right expense account
  (fuel vs supplies vs meals) for the ledger.
</div>

<hr>

<h2>1 — One-time setup (do this once, not per claim)</h2>

<p>Fresh Dolibarr installs don't map anything here by default — if you've tried to submit a
claim and couldn't see how to allocate it to an expense account, this is why: the mapping
step below hasn't been done yet.</p>

<ol>
  <li>Go to <strong>Setup → Dictionaries</strong>, find <strong>"Type of expense report fees"</strong>
      (this is the list of claim types: Fuel, Km allowance, Meal, Office Supplies, Other, etc.)</li>
  <li>For each type, set the <strong>Account</strong> column to the correct expense account —
      use the same accounts as the <a href="expense-invoices.php">Expense Invoices</a> table
      (e.g. fuel → the relevant vehicle account, office supplies → <strong>6000.11</strong>,
      general/uncategorised → <strong>6600.21</strong>)</li>
  <li>Go to <strong>Accounting → Setup → Default accounts</strong> and set the
      <strong>"Expense report account"</strong> — this is the account that tracks what the
      business currently owes you, between submitting a claim and being paid back
      (think of it as a mini accounts-payable just for reimbursements)</li>
  <li><em>Optional, worth doing since more than one person claims expenses:</em> on each
      person's own <strong>user card</strong> (Users &amp; Groups → their name), fill in the
      <strong>Accountancy Code</strong> field. Without this, everyone's owed reimbursements get
      pooled into one account with no way to tell whose is whose from the ledger. With it,
      each person gets tracked separately.</li>
</ol>

<hr>

<h2>2 — Submit a claim</h2>

<ol>
  <li>Go to <strong>HRM</strong> in the top menu, then <strong>Expense reports</strong> in the left sidebar</li>
  <li>Click <strong>New expense report</strong></li>
  <li>Add a line for each purchase:
    <ul>
      <li><strong>Date:</strong> date you paid</li>
      <li><strong>Type of fee:</strong> pick from the dictionary you set up in step 1 — this is
          what decides which expense account it posts to, so pick the closest match</li>
      <li><strong>Amount</strong> (and GST rate if the receipt shows GST)</li>
      <li><strong>Comments:</strong> what it was / attach a photo of the receipt if you have one</li>
    </ul>
  </li>
  <li>Click <strong>Validate</strong>, then <strong>Submit</strong> for approval</li>
</ol>

<hr>

<h2>3 — Approve and pay it back</h2>

<ol>
  <li>Whoever approves expense reports opens it and clicks <strong>Approve</strong></li>
  <li>When you actually transfer the money back, open the report and click <strong>Enter Payment</strong></li>
  <li>Set the payment date, method (usually Bank transfer), and the bank account it came out of</li>
  <li>Click <strong>Save</strong> — the report status changes to <strong>Paid</strong></li>
</ol>

<hr>

<h2>4 — Transfer to accounting (do weekly or monthly)</h2>

<ol>
  <li>Go to <strong>Accounting</strong> in the top menu</li>
  <li>Under <strong>Transfer in accounting</strong> in the left sidebar, click
      <strong>Expense Reports Ventilation</strong></li>
  <li>Each approved line shows up with an account already suggested (from your step 1 mapping)
      — check it, adjust any individual line if needed, and confirm</li>
  <li>Click <strong>Expense reports</strong> journal in the left sidebar, set the date range,
      <strong>Refresh</strong>, then <strong>Record Transactions in Accounting</strong></li>
  <li>Click <strong>Bank (Finance journal)</strong>, same date range, <strong>Refresh</strong>,
      then <strong>Record Transactions in Accounting</strong> — this is the step that clears the
      "owed to you" balance once the reimbursement payment is recorded</li>
</ol>

<p>This is two separate postings, not one — the cost is recorded (and the debt to you created)
the moment the claim is approved; the debt is only cleared later when you're actually paid back:</p>

<p><strong>On approval — Expense reports journal:</strong></p>
<table class="noborder" style="width:auto;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;">Account</th>
      <th style="padding:0.4rem 1rem;">Description</th>
      <th style="padding:0.4rem 1rem;text-align:right;">Debit</th>
      <th style="padding:0.4rem 1rem;text-align:right;">Credit</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.3rem 1rem;">6501.01</td>
      <td style="padding:0.3rem 1rem;">Expense account (e.g. Fuel — from your step 1 mapping)</td>
      <td style="padding:0.3rem 1rem;text-align:right;">20.00</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
    </tr>
    <tr>
      <td style="padding:0.3rem 1rem;">(expense report account)</td>
      <td style="padding:0.3rem 1rem;">Owed to you — set in step 1</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
      <td style="padding:0.3rem 1rem;text-align:right;">20.00</td>
    </tr>
  </tbody>
</table>

<p><strong>On reimbursement — Bank journal:</strong></p>
<table class="noborder" style="width:auto;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;">Account</th>
      <th style="padding:0.4rem 1rem;">Description</th>
      <th style="padding:0.4rem 1rem;text-align:right;">Debit</th>
      <th style="padding:0.4rem 1rem;text-align:right;">Credit</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.3rem 1rem;">(expense report account)</td>
      <td style="padding:0.3rem 1rem;">Owed to you — cleared</td>
      <td style="padding:0.3rem 1rem;text-align:right;">20.00</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
    </tr>
    <tr>
      <td style="padding:0.3rem 1rem;">1101</td>
      <td style="padding:0.3rem 1rem;">Bank account</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
      <td style="padding:0.3rem 1rem;text-align:right;">20.00</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>Check with your accountant/BAS agent</strong> before relying on this for BAS: this
  "accrue on approval, settle on payment" treatment is standard double-entry practice, but
  whether it matches how you want GST timing to work for your BAS (which this install
  calculates on a cash/payment basis — see <a href="gst-bas.php">GST &amp; BAS</a>) is worth
  confirming before go-live.
</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
