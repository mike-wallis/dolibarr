<?php
require '../../main.inc.php';
llxHeader('', 'Help — South Side Supplies');
?>
<div class="fiche">

<h1>South Side Supplies — Help</h1>
<p>Quick reference for the workflows used in this Dolibarr installation.</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:2rem;max-width:900px;">

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">💳 <a href="supplier-payments.php">Supplier Payments</a></h3>
    <p>30-day EOM terms, reconciling statements, paying multiple invoices in one go, remittance advice workaround.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">📧 <a href="email.php">Sending Emails</a></h3>
    <p>Which From address to use for BCS vs SSS documents, switching brands in the send dialog, troubleshooting.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">📦 <a href="stock.php">Stock &amp; Inventory</a></h3>
    <p>Purchase workflow (PO → Reception → stock in) and sales workflow (Order → Shipment → stock out). Key gotchas.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">🧾 <a href="gst-bas.php">GST &amp; BAS</a></h3>
    <p>Cash-basis GST, running the quarterly VAT report, what numbers to enter on the ATO portal BAS form.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">👷 <a href="payroll.php">Payroll</a></h3>
    <p>Recording pay runs from Reckon into Dolibarr — wages, PAYG withholding, super. Clearing liabilities when you pay ATO and SBSCH.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">🔁 <a href="recurring.php">Recurring Invoices</a></h3>
    <p>Setting up invoice templates for regular customer billing and supplier bills. Monthly generation routine.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">🏦 <a href="reconcile.php">Bank Reconciliation</a></h3>
    <p>Ticking off Dolibarr entries against your Macquarie statement. Separate debit/credit columns, running balance, handling missing entries.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">📊 <a href="reports.php">Reports</a></h3>
    <p>Day-to-day, monthly, and accountant reports — outstanding debtors/creditors, trial balance, P&amp;L, general ledger, and year-end checklist.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">💸 <a href="bank-entries.php">Quick Bank Entries</a></h3>
    <p>Cheque-book style direct entry for bank fees, ATO payments, super, subscriptions — anything that hits the bank without a supplier invoice behind it.</p>
  </div>

  <div class="div-table-responsive" style="border:1px solid #ddd;border-radius:6px;padding:1.5rem;">
    <h3 style="margin-top:0;">🏷️ <a href="branding.php">BCS vs SSS Invoices</a></h3>
    <p>Tagging customers with the right brand, selecting the correct invoice template (brightcs or southside), and what each template prints.</p>
  </div>

</div>

<?php if ($user->admin): ?>
<div style="margin-top:1.5rem;max-width:900px;">
  <div class="div-table-responsive" style="border:1px solid #c00;border-radius:6px;padding:1.5rem;background:#fff8f8;">
    <h3 style="margin-top:0;color:#c00;">🔒 <a href="tfn.php" style="color:#c00;">Tax File Numbers</a> <small style="font-size:0.6em;font-weight:normal;">(admin only)</small></h3>
    <p>Encrypt TFNs for employee records, verify existing encrypted values, view all employee TFNs.</p>
  </div>
</div>
<?php endif; ?>

</div>
<?php llxFooter(); ?>
