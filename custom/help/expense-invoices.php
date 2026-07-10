<?php
require '../../main.inc.php';
llxHeader('', 'Help — Expense Invoices');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Expense Invoices (Phone, Utilities, Fuel, Office Supplies)</h1>

<p>Use this workflow for regular business expenses that arrive as a bill from a supplier —
phone, electricity, rent, fuel, office supplies, etc. It ensures GST is captured correctly
for BAS and the cost hits the right expense account in the ledger. If you or a staff member
paid for something personally and need to be paid back, use
<a href="expense-reports.php">Expense Reports</a> instead — the supplier isn't being paid
directly here, a person is.</p>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Why not just use a bank entry?</strong> A bank entry only records one amount to one account —
  it cannot split the GST out. Using a supplier invoice properly separates $90.91 expense
  from $9.09 GST, which is required for your BAS input tax credit.
</div>

<hr>

<h2>1 — One-time setup: create a service record for each expense type</h2>

<p>Do this once per expense type (phone line, electricity, etc.). After that, just pick it from the list each month.</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Go to <strong>Products | Services</strong> in the top menu</li>
      <li>Click <strong>New service</strong> in the left sidebar</li>
      <li>Fill in:
        <ul>
          <li><strong>Reference:</strong> e.g. <code>PHONE-MB-0438</code></li>
          <li><strong>Label:</strong> e.g. <code>Amaysim Mobile 0438 840 281</code></li>
          <li><strong>Status (Sell):</strong> Not for sale</li>
          <li><strong>Status (Purchase):</strong> For purchase</li>
          <li><strong>VAT Rate:</strong> 10% (GST)</li>
          <li><strong>Accounting code (purchase):</strong> select the correct expense account — see table below</li>
        </ul>
      </li>
      <li>Click <strong>Create</strong></li>
    </ol>
    <p>Leave the selling price blank — you'll enter the actual amount on each invoice.</p>
  </div>

  <div style="flex-shrink:0;width:190px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#6c757d;">&#9646;</span> Services
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        New service &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>

</div>

<h3>Common expense accounts</h3>
<table class="noborder" style="width:auto;margin-bottom:1rem;">
  <thead>
    <tr style="background:#f4f4f4;">
      <th style="padding:0.4rem 1rem;">Expense type</th>
      <th style="padding:0.4rem 1rem;">Account</th>
    </tr>
  </thead>
  <tbody>
    <tr><td style="padding:0.3rem 1rem;">Amaysim mobile 0438 840 281</td><td style="padding:0.3rem 1rem;"><strong>6050.11</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Other mobiles / office phone</td><td style="padding:0.3rem 1rem;"><strong>6050.01 – 6050.22</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Internet / web hosting</td><td style="padding:0.3rem 1rem;"><strong>6050.02 / 6000.23</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Electricity</td><td style="padding:0.3rem 1rem;"><strong>6100.03</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Rent</td><td style="padding:0.3rem 1rem;"><strong>6100.01</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Vito petrol</td><td style="padding:0.3rem 1rem;"><strong>6501.01</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Hilux petrol</td><td style="padding:0.3rem 1rem;"><strong>6502.01</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Printing &amp; stationery</td><td style="padding:0.3rem 1rem;"><strong>6000.11</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Postage</td><td style="padding:0.3rem 1rem;"><strong>6000.12</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">Business insurance</td><td style="padding:0.3rem 1rem;"><strong>6020.05</strong></td></tr>
    <tr><td style="padding:0.3rem 1rem;">General / uncategorised</td><td style="padding:0.3rem 1rem;"><strong>6600.21</strong></td></tr>
  </tbody>
</table>

<hr>

<h2>2 — Record the invoice</h2>

<ol>
  <li>Go to <strong>Billing | Payment</strong> in the top menu</li>
  <li>In the left sidebar under <strong>Vendor invoices</strong>, click <strong>New invoice</strong></li>
  <li>Select the supplier (e.g. Amaysim Australia Limited)</li>
  <li>Set:
    <ul>
      <li><strong>Invoice date:</strong> date on the bill</li>
      <li><strong>Supplier invoice ref:</strong> the reference number on their bill</li>
      <li><strong>Label:</strong> e.g. <code>June 2026</code></li>
      <li><strong>Payment method:</strong> Bank transfer (or Direct debit if auto-debited)</li>
      <li><strong>Bank account:</strong> 01-main</li>
    </ul>
  </li>
  <li>On the line items area, select the <strong>Predefined products/services</strong> radio button</li>
  <li>Search for and select the service record (e.g. <code>PHONE-MB-0438</code>)</li>
  <li>Enter the <strong>unit price inc. tax</strong> (e.g. <code>100.00</code>) — Dolibarr splits GST automatically</li>
  <li>Click <strong>Add</strong></li>
  <li>Click <strong>Validate</strong></li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>Always use the predefined service</strong> — do not use the free-text line for expenses with GST.
  Free-text lines use a generic default account (6000.20) instead of the correct specific account.
</div>

<hr>

<h2>3 — Record the payment</h2>

<ol>
  <li>On the validated invoice, click <strong>Enter Payment</strong></li>
  <li>Set:
    <ul>
      <li><strong>Payment date:</strong> when you paid (or when it was direct debited)</li>
      <li><strong>Payment method:</strong> Bank transfer</li>
      <li><strong>Bank account:</strong> 01-main</li>
      <li><strong>Amount:</strong> the full amount inc. GST</li>
    </ul>
  </li>
  <li>Click <strong>Save</strong> — invoice status changes to <strong>Paid</strong></li>
</ol>

<hr>

<h2>4 — Transfer to accounting (do weekly or monthly)</h2>

<p>Dolibarr does not post to the ledger automatically. You need to run this step to create the journal entries.</p>

<ol>
  <li>Go to <strong>Accounting</strong> in the top menu</li>
  <li>Under <strong>Transfer in accounting</strong> in the left sidebar, click <strong>Vendor invoice linking</strong></li>
  <li>Click <strong>Link Automatically</strong> — this binds each invoice line to its GL account</li>
  <li>Click <strong>Purchases</strong> in the left sidebar</li>
  <li>Set the date range to cover your invoice date and click <strong>Refresh</strong></li>
  <li>Click <strong>Record Transactions in Accounting</strong></li>
  <li>Click <strong>Bank (Finance journal)</strong> in the left sidebar</li>
  <li>Set the same date range, click <strong>Refresh</strong>, then <strong>Record Transactions in Accounting</strong></li>
</ol>

<p>After this, the journal will show three entries for the invoice:</p>

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
      <td style="padding:0.3rem 1rem;">2010</td>
      <td style="padding:0.3rem 1rem;">Trade creditors (supplier)</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
      <td style="padding:0.3rem 1rem;text-align:right;">100.00</td>
    </tr>
    <tr>
      <td style="padding:0.3rem 1rem;">6050.11</td>
      <td style="padding:0.3rem 1rem;">Expense account (e.g. Mobile)</td>
      <td style="padding:0.3rem 1rem;text-align:right;">90.91</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
    </tr>
    <tr>
      <td style="padding:0.3rem 1rem;">2200</td>
      <td style="padding:0.3rem 1rem;">GST</td>
      <td style="padding:0.3rem 1rem;text-align:right;">9.09</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
    </tr>
  </tbody>
</table>

<p>And two entries for the payment (bank journal):</p>

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
      <td style="padding:0.3rem 1rem;">2010</td>
      <td style="padding:0.3rem 1rem;">Trade creditors cleared</td>
      <td style="padding:0.3rem 1rem;text-align:right;">100.00</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
    </tr>
    <tr>
      <td style="padding:0.3rem 1rem;">1101</td>
      <td style="padding:0.3rem 1rem;">Bank account</td>
      <td style="padding:0.3rem 1rem;text-align:right;"></td>
      <td style="padding:0.3rem 1rem;text-align:right;">100.00</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>5 — Next month: clone the invoice</h2>

<p>You don't need to re-enter everything from scratch. Open last month's invoice and click <strong>Clone</strong>.
A new draft invoice is created with the same supplier, service line, and accounts.
Update the date, supplier reference, and amount — then validate and pay as above.</p>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
