<?php
require '../../main.inc.php';
llxHeader('', 'Help — Bank Reconciliation');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Bank Reconciliation</h1>

<p>Dolibarr's reconciliation works the same way as Reckon — you tick off each entry that appears
on your bank statement. Payments (money out) and deposits (money in) are in separate Debit and
Credit columns with a running balance as you work through the list.</p>

<hr>

<h2>Getting to the Reconcile screen</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Banks | Cash</strong> in the top menu</li>
      <li>In the left sidebar click <strong>List</strong> — shows your bank accounts</li>
      <li>Click <strong>01-main</strong> (Macquarie Transaction Account)</li>
      <li>Click the <strong>Reconcile</strong> tab at the top of the account page</li>
    </ol>
    <p>You will see all unreconciled entries with Debit, Credit, Balance and Reconciled columns.</p>
  </div>

  <!-- Banks | Cash sidebar mock-up -->
  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#8fbc3f;">&#9646;</span> Banks | Cash
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New financial account</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        List &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List entries</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List entries/category</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Internal transfer</div>
    </div>
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-top:1px solid #ddd;border-bottom:1px solid #ddd;font-weight:bold;color:#333;margin-top:0.25rem;">
      <span style="color:#8fbc3f;">&#9646;</span> Payment by credit transfer
    </div>
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-top:1px solid #ddd;font-weight:bold;color:#333;margin-top:0.1rem;">
      <span style="color:#8fbc3f;">&#9646;</span> Deposits slips
    </div>
  </div>

</div>

<!-- Tab mock-up -->
<div style="max-width:780px;margin-bottom:1.5rem;">
  <div style="display:flex;border-bottom:2px solid #5c73a0;font-size:0.875rem;">
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;">Bank account</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Bank entries</div>
    <div style="padding:0.4rem 1rem;font-weight:bold;color:#fff;background:#5c73a0;border-radius:4px 4px 0 0;margin-left:2px;">Reconcile &#8592;</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Account statements</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Reports</div>
  </div>
</div>

<hr>

<h2>Reconciling — step by step</h2>

<ol>
  <li>
    <strong>Enter the statement reference</strong> in the <em>YYYYMM</em> field at the top<br>
    e.g. for July 2026 statement: <code>202607</code>
  </li>
  <li>
    <strong>Open your Macquarie bank statement</strong> (download from Macquarie Online or print to PDF)
  </li>
  <li>
    <strong>Work through the statement line by line.</strong> For each transaction on the statement,
    find the matching row in Dolibarr and <strong>tick the checkbox</strong> on the left:
    <ul style="margin-top:0.5rem;">
      <li><strong>Debit column</strong> = money out (payments to suppliers, expenses)</li>
      <li><strong>Credit column</strong> = money in (customer payments received)</li>
      <li><strong>Balance column</strong> = running balance updates as you tick</li>
    </ul>
  </li>
  <li>
    When all matching entries are ticked, the <strong>running balance should equal your statement closing balance</strong>
  </li>
  <li>
    Click <strong>RECONCILE</strong> — ticked entries are marked as reconciled (Reconciled = Yes)
  </li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>SAVE STATEMENT ONLY</strong> saves the statement reference without marking anything reconciled.
  Use this if you want to record the statement period but aren't ready to finalise the reconciliation yet.
</div>

<hr>

<h2>When the balance doesn't match</h2>

<table class="noborder" style="width:100%;max-width:700px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Situation</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Fix</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Item on bank statement, missing from Dolibarr</td>
      <td style="padding:0.4rem 1rem;">Click the <strong>+</strong> button on the Reconcile screen to add the entry directly (e.g. bank fee, direct debit)</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Item in Dolibarr, not on statement yet</td>
      <td style="padding:0.4rem 1rem;">Leave it unticked — it will appear in next month's reconciliation</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Amount differs</td>
      <td style="padding:0.4rem 1rem;">Edit the Dolibarr entry (pencil icon on the row) to correct the amount</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Duplicate entry in Dolibarr</td>
      <td style="padding:0.4rem 1rem;">Delete the duplicate (bin icon on the row)</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>Importing bank statements</h2>

<div class="alert alert-warning" style="margin:0 0 1rem 0;">
  <strong>Dolibarr 23.0.3 has no native bank statement import</strong> — there is no built-in OFX,
  CSV, or QIF importer for the bank register. This is a known gap.
</div>

<p>What Dolibarr DOES have on the bank account page is an <strong>Account statements</strong> tab
where you can <strong>attach the PDF or CSV file</strong> from Macquarie as a reference document.
This is useful for record-keeping but does not import any transactions.</p>

<h3>How Dolibarr's register works (why import is less critical than it sounds)</h3>
<p>Unlike Xero or MYOB which start with bank feeds and match against invoices, Dolibarr works the
other way around:</p>
<ul>
  <li>Most transactions are <strong>already in Dolibarr</strong> — every time you record a customer
  payment or pay a supplier invoice, Dolibarr creates a bank register entry automatically</li>
  <li>The Reconcile screen shows those existing entries — you just tick them off against the statement</li>
  <li>The only transactions you need to <strong>add manually</strong> are ones that happened at the
  bank but weren't generated by a Dolibarr document (bank fees, direct debits, ATO/super payments, etc.)</li>
</ul>
<p>For most months, those additions will be a small number of lines — bank fee, any direct debits —
so the manual workflow is manageable without import.</p>

<h3>Options if you want proper CSV import</h3>
<table class="noborder" style="width:100%;max-width:700px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Option</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Notes</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Custom import script</strong></td>
      <td style="padding:0.4rem 1rem;">A PHP script tailored to Macquarie's CSV export — reads the file, skips entries already in Dolibarr, inserts missing ones. Buildable as a custom tool in this repo.</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Dolibarr app store module</strong></td>
      <td style="padding:0.4rem 1rem;">Several third-party bank import modules exist at apps.dolibarr.org — quality varies, check reviews and Dolibarr version compatibility before buying.</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Manual + PDF attach</strong></td>
      <td style="padding:0.4rem 1rem;">Attach the Macquarie PDF/CSV to the Account statement for reference, add the few missing entries via the + button during reconciliation.</td>
    </tr>
  </tbody>
</table>

<p style="margin-top:1rem;">The custom import script is the most practical path — it can be built
to match Macquarie's exact CSV column layout and handle duplicate detection automatically.
Ask when ready to build it.</p>

<hr>

<h2>Common entries to add manually</h2>

<p>These won't appear in Dolibarr automatically — add them via the <strong>+</strong> button during reconciliation,
or enter them ahead of time using the <a href="bank-entries.php">Quick Bank Entries</a> workflow.</p>

<ul>
  <li><strong>Bank fees</strong> — monthly account keeping fee, transaction fees</li>
  <li><strong>Interest earned</strong> — any interest credited by Macquarie</li>
  <li><strong>Direct debits</strong> — subscriptions, insurance, ATO payments made directly</li>
  <li><strong>Payroll transfers</strong> — net pay to Eloise and Daniel (if not already entered via Salaries module)</li>
  <li><strong>Super / PAYG payments</strong> — transfers to SBSCH or ATO</li>
</ul>

<p>When adding an entry directly, set the correct accounting account so it posts to the right place in your P&amp;L.</p>

<hr>

<h2>After reconciliation</h2>

<p>Reconciled entries move off the unreconciled list. You can view them by changing the
<strong>Reconciled</strong> filter (dropdown on the right of the search bar) from <em>No</em> to <em>Yes</em> or <em>All</em>.</p>

<p>The <strong>Account statements</strong> tab shows a history of all completed reconciliations by statement reference.</p>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Suggested routine:</strong> Reconcile monthly, within a few days of receiving your statement.
  Leaving it longer makes it harder to remember what transactions relate to.
</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
