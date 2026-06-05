<?php
require '../../main.inc.php';
llxHeader('', 'Help — Reports');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Reports</h1>

<p>Most Dolibarr report screens have a CSV/Excel export button — useful for sending data to your
accountant or working with it in a spreadsheet.</p>

<hr>

<h2>Day-to-day</h2>

<h3>Who owes you money — Outstanding debtors</h3>
<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:260px;">
    <p><strong>Billing | Payment</strong> &gt; Customer invoices &gt; <strong>Not paid</strong></p>
    <p>Shows all unpaid customer invoices with due dates. Sort by due date to see what's overdue.
    Export to CSV to give to a debt-chaser or for your records.</p>
  </div>
  <div style="flex-shrink:0;width:195px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;"><span style="color:#5cb85c;">&#9646;</span> Customer invoices</div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.2rem 1.2rem;color:#888;font-size:0.82rem;">Draft</div>
      <div style="padding:0.3rem 1.2rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">Not paid &nbsp;&#8592;</div>
      <div style="padding:0.2rem 1.2rem;color:#aaa;font-size:0.82rem;">Paid</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>
</div>

<h3>What you owe — Outstanding creditors</h3>
<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:260px;">
    <p><strong>Billing | Payment</strong> &gt; Vendor invoices &gt; <strong>Not paid</strong></p>
    <p>Shows all unpaid supplier invoices. Useful before the end of month payment run —
    see <a href="supplier-payments.php">Supplier Payments</a> for the batch payment process.</p>
  </div>
  <div style="flex-shrink:0;width:195px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;"><span style="color:#5cb85c;">&#9646;</span> Vendor invoices</div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.2rem 1.2rem;color:#aaa;font-size:0.82rem;">Draft</div>
      <div style="padding:0.3rem 1.2rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">Not paid &nbsp;&#8592;</div>
      <div style="padding:0.2rem 1.2rem;color:#aaa;font-size:0.82rem;">Paid</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>
</div>

<h3>Stock levels</h3>
<p><strong>Products</strong> (top menu) &gt; click any product &gt; <strong>Stock</strong> tab — current quantity per warehouse.<br>
For all products: <strong>Products</strong> &gt; <strong>Stocks</strong> in the left sidebar &gt; <strong>Stock by warehouse</strong> or <strong>Stock movements</strong> for full transaction history.</p>

<hr>

<h2>Monthly</h2>

<h3>GST / BAS report</h3>
<p>See <a href="gst-bas.php">GST &amp; BAS help page</a> — run at end of each quarter before lodging.</p>

<h3>Bank reconciliation</h3>
<p>See <a href="reconcile.php">Bank Reconciliation help page</a> — reconcile within a few days of receiving your Macquarie statement.</p>

<h3>Sales statistics</h3>
<p><strong>Billing | Payment</strong> &gt; Customer invoices &gt; <strong>Statistics</strong> — revenue by month, by customer, or by product/service. Good for spotting trends.</p>

<hr>

<h2>For your accountant</h2>

<p>At year-end (30 June) or when your accountant asks, pull these four reports and export each to CSV or print to PDF.</p>

<h3>1 — Trial Balance</h3>
<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:260px;">
    <p><strong>Accounting</strong> (top menu) &gt; left sidebar &gt; Accounting section &gt; <strong>Account Balance</strong></p>
    <p>Shows the debit and credit balance for every account at the selected date.
    The accountant uses this to prepare financial statements. Set the date to 30 June for year-end.
    Export to CSV.</p>
  </div>
  <div style="flex-shrink:0;width:195px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;"><span style="color:#5cb85c;">&#9646;</span> Accounting</div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Bookkeeping</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Journals</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">Account Balance &nbsp;&#8592;</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Export Accountancy</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Reportings</div>
    </div>
  </div>
</div>

<h3>2 — Profit &amp; Loss (Income Statement)</h3>
<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:260px;">
    <p><strong>Accounting</strong> &gt; left sidebar &gt; <strong>Reportings</strong> (click to expand) &gt; <strong>Income/Expense report</strong></p>
    <p>Revenue vs expenses for the period. Set the date range to 1 July – 30 June for the full financial year.
    Shows gross profit, expenses, and net result by account group.</p>
    <div class="alert alert-info" style="margin:0.5rem 0;">
      Dolibarr does not have a separate Balance Sheet report. Your accountant can derive it from
      the Trial Balance — all asset, liability and equity accounts are in there.
    </div>
  </div>
  <div style="flex-shrink:0;width:195px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;"><span style="color:#5cb85c;">&#9646;</span> Accounting</div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Bookkeeping</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Journals</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Account Balance</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Export Accountancy</div>
      <div style="padding:0.2rem 0.75rem;font-weight:600;color:#333;">Reportings</div>
      <div style="padding:0.3rem 1.2rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;font-size:0.82rem;">Income/Expense report &nbsp;&#8592;</div>
      <div style="padding:0.2rem 1.2rem;color:#aaa;font-size:0.78rem;">By predefined groups</div>
      <div style="padding:0.2rem 1.2rem;color:#aaa;font-size:0.78rem;">Revenue report</div>
    </div>
  </div>
</div>

<h3>3 — General Ledger</h3>
<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:260px;">
    <p><strong>Accounting</strong> &gt; left sidebar &gt; <strong>Bookkeeping</strong></p>
    <p>Every transaction for the period, listed by account. Filter by date range (1 Jul – 30 Jun)
    and export to CSV. Accountants use this to verify entries and check for anything unusual.
    You can also filter by a single account number to see all movements through it.</p>
  </div>
  <div style="flex-shrink:0;width:195px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;"><span style="color:#5cb85c;">&#9646;</span> Accounting</div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">Bookkeeping &nbsp;&#8592;</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Journals</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Account Balance</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Export Accountancy</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Reportings</div>
    </div>
  </div>
</div>

<h3>4 — GST Summary (all four quarters)</h3>
<p><strong>Billing | Payment</strong> &gt; Taxes | Special expenses &gt; VAT &gt; <strong>Report per month</strong></p>
<p>Run separately for each quarter (Jul–Sep, Oct–Dec, Jan–Mar, Apr–Jun), export each to CSV.
Your accountant needs these to reconcile GST and verify the BAS lodgements.
See the <a href="gst-bas.php">GST &amp; BAS help page</a> for the exact steps.</p>

<hr>

<h2>Accountant package checklist (year-end)</h2>

<table class="noborder" style="width:100%;max-width:650px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Item</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Where</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Format</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Trial Balance as at 30 June</td>
      <td style="padding:0.4rem 1rem;">Accounting &gt; Account Balance</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">P&amp;L for the year (1 Jul – 30 Jun)</td>
      <td style="padding:0.4rem 1rem;">Accounting &gt; Reportings &gt; Income/Expense</td>
      <td style="padding:0.4rem 1rem;">CSV or PDF</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">General Ledger for the year</td>
      <td style="padding:0.4rem 1rem;">Accounting &gt; Bookkeeping</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">GST report — Q1 (Jul–Sep)</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; VAT &gt; Report per month</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">GST report — Q2 (Oct–Dec)</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; VAT &gt; Report per month</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">GST report — Q3 (Jan–Mar)</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; VAT &gt; Report per month</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">GST report — Q4 (Apr–Jun)</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; VAT &gt; Report per month</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Bank reconciliation — confirm all 12 months done</td>
      <td style="padding:0.4rem 1rem;">Banks | Cash &gt; 01-main &gt; Account statements tab</td>
      <td style="padding:0.4rem 1rem;">Confirm in Dolibarr</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Outstanding debtors as at 30 June</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; Customer invoices &gt; Not paid</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Outstanding creditors as at 30 June</td>
      <td style="padding:0.4rem 1rem;">Billing &gt; Vendor invoices &gt; Not paid</td>
      <td style="padding:0.4rem 1rem;">CSV</td>
    </tr>
  </tbody>
</table>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
