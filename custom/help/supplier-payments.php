<?php
require '../../main.inc.php';
llxHeader('', 'Help — Supplier Payments');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Supplier Payments</h1>

<p>Your suppliers are on 30-day EOM terms. At the end of each month you receive statements,
check them against your open invoices, and pay the total in one hit.</p>

<hr>

<h2>1 — Payment terms (30 days EOM)</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <p>Set this on each supplier record so due dates calculate automatically when you validate an invoice.</p>
    <ol>
      <li>Click <strong>Third Parties</strong> in the top menu, then <strong>Vendors</strong> in the left sidebar</li>
      <li>Open the supplier by clicking their name</li>
      <li>Click the <strong>Edit</strong> pencil icon</li>
      <li>Find the <strong>Payment terms</strong> field — select <em>30 days end of month</em></li>
      <li>Save</li>
    </ol>
    <p>Once set, every supplier invoice for that supplier will show the correct due date automatically.</p>
  </div>

  <!-- Sidebar mock-up -->
  <div style="flex-shrink:0;width:190px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#6c757d;">&#9646;</span> Third party
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New Third Party</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Prospects</div>
      <div style="padding:0.2rem 2rem;color:#aaa;font-size:0.78rem;">New Prospect</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Customers</div>
      <div style="padding:0.2rem 2rem;color:#aaa;font-size:0.78rem;">New Customer</div>
      <div style="padding:0.3rem 1.4rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Vendors &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 2rem;color:#aaa;font-size:0.78rem;">New Vendor</div>
    </div>
  </div>

</div>

<hr>

<h2>2 — Checking what you owe (before the statement arrives)</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <p>Click <strong>Billing | Payment</strong> in the top menu. The left sidebar shows your invoice sections.</p>
    <ol>
      <li>Under <strong>Vendor invoices</strong>, click <strong>List</strong></li>
      <li>Click <strong>Not paid</strong> to filter to unpaid only</li>
      <li>Use the supplier search/filter to narrow to one supplier</li>
    </ol>
    <p>Compare this list to the supplier's statement. If something is on their statement but missing here,
    you haven't entered it yet — create the invoice before paying.</p>

    <div class="alert alert-warning" style="margin:1rem 0;">
      <strong>Note:</strong> There is no dedicated reconciliation screen — the comparison is manual.
      Tick off Dolibarr invoices against the paper/PDF statement line by line.
    </div>
  </div>

  <!-- Sidebar mock-up -->
  <div style="flex-shrink:0;width:190px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Vendor invoices
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Draft</div>
      <div style="padding:0.3rem 1.4rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Not paid &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Paid</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List of templates</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>

</div>

<hr>

<h2>3 — Paying multiple invoices in one payment</h2>
<p>One payment record, multiple invoices ticked — this is the standard Dolibarr flow.</p>
<ol>
  <li>Go to <strong>Billing &gt; Suppliers &gt; Supplier invoices</strong></li>
  <li>Open <strong>any one</strong> of the unpaid invoices for that supplier</li>
  <li>Click <strong>Enter payment</strong> (bottom of the invoice page)</li>
  <li>On the payment screen, you will see <strong>all unpaid invoices for that supplier</strong> listed</li>
  <li>Tick each invoice you are paying</li>
  <li>Enter:
    <ul>
      <li><strong>Amount:</strong> the total you are paying (should match the ticked invoices)</li>
      <li><strong>Payment method:</strong> Bank transfer</li>
      <li><strong>Bank account:</strong> Macquarie (bank01)</li>
      <li><strong>Date:</strong> the date the transfer was made</li>
    </ul>
  </li>
  <li>Click <strong>Save</strong></li>
</ol>
<p>All ticked invoices are marked Paid. One payment record is created, linked to all of them.</p>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Partial payments:</strong> If you are paying less than the full amount, enter the actual
  amount paid. Dolibarr will mark the invoices as partially paid and carry forward the balance.
</div>

<hr>

<h2>4 — Remittance advice</h2>
<p>Dolibarr does not generate a remittance advice PDF. Workaround:</p>
<ol>
  <li>After saving the payment, open the payment record</li>
  <li>It lists all invoices paid with amounts — use <strong>Ctrl+P</strong> (Print) and save as PDF</li>
  <li>Email that PDF to the supplier, or copy the invoice numbers and amounts into an email</li>
</ol>
<p>Most suppliers accept an email with the invoice numbers and total. If a specific supplier requires
a formal remittance advice PDF, this can be built as a custom Dolibarr template — ask Michael.</p>

<hr>

<h2>5 — Prepaying a supplier before the bill arrives</h2>

<p>Sometimes you need to pay a supplier a deposit or prepayment before their actual invoice
exists — a deposit on a large order, for example. Don't just wait and enter it as a normal
payment later; record it properly so it's a real, dated transaction that automatically reduces
what you owe once the real bill turns up.</p>

<ol>
  <li>Go to <strong>Billing | Payment</strong> in the top menu, then under <strong>Vendor invoices</strong>
      in the left sidebar, click <strong>New invoice</strong></li>
  <li>Select the supplier as normal</li>
  <li>For <strong>Type</strong>, choose <strong>Deposit</strong> instead of the default
      "Standard invoice"</li>
  <li>Enter the prepayment amount and <strong>Validate</strong></li>
  <li>Click <strong>Enter Payment</strong> and record it exactly like paying any other invoice —
      this is the real money leaving the bank account now</li>
  <li>Once the deposit invoice is validated and paid, a <strong>"Convert to Reduction"</strong>
      button appears on it — click it. This turns the paid deposit into a standing credit against
      that supplier (Dolibarr calls it an "absolute discount"), no longer tied to the deposit
      invoice itself</li>
  <li>When the supplier's <strong>real invoice</strong> eventually arrives, create it as a normal
      <strong>Standard</strong> invoice. Dolibarr will show that supplier's available credit in
      the <strong>"Relative and absolute discounts"</strong> section on the invoice — tick it to
      apply it, and it reduces what you actually owe by the amount already prepaid</li>
</ol>

<div class="alert alert-info" style="margin:1rem 0;">
  <strong>Why bother with the Deposit type instead of just waiting?</strong> It keeps the
  prepayment as its own proper, auditable transaction — money out, GST if applicable, dated when
  it actually happened — rather than a note stuck to a bill that doesn't exist yet. The credit
  just sits there cleanly until there's a real invoice to apply it against, however long that
  takes.
</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
