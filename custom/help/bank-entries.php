<?php
require '../../main.inc.php';
llxHeader('', 'Help — Quick Bank Entries');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Quick Bank Entries</h1>

<p>The closest Dolibarr equivalent to Reckon's cheque book — enter a direct bank payment or receipt
without creating a supplier invoice first. Use it for bank fees, direct debits, ATO/SBSCH payments,
subscriptions, and anything else that hits the bank but has no Dolibarr document behind it.</p>

<div class="alert alert-info" style="max-width:700px;">
  <strong>When to use direct entry vs paying via an invoice:</strong>
  <ul style="margin:0.5rem 0 0;">
    <li><strong>Direct entry</strong> — bank fees, interest, ATO PAYG, super, subscriptions, direct debits with no supplier invoice</li>
    <li><strong>Pay via invoice</strong> — any supplier you have an invoice for in Dolibarr (use Billing | Payment &gt; Vendor invoices &gt; pay). This keeps the invoice marked as paid and the bank register entry is created automatically.</li>
  </ul>
</div>

<hr>

<h2>Getting to the entry form</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Banks | Cash</strong> in the top menu</li>
      <li>In the left sidebar click <strong>List</strong></li>
      <li>Click <strong>01-main</strong> (Macquarie Transaction Account)</li>
      <li>Click the <strong>Bank entries</strong> tab</li>
      <li>Click the green <strong>+</strong> button (top right of the entries list)</li>
    </ol>
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
    <div style="padding:0.4rem 1rem;font-weight:bold;color:#fff;background:#5c73a0;border-radius:4px 4px 0 0;margin-left:2px;">Bank entries &nbsp;&#8592;</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Reconcile</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Account statements</div>
    <div style="padding:0.4rem 1rem;color:#555;border:1px solid #ddd;border-bottom:none;border-radius:4px 4px 0 0;background:#f9f9f9;margin-left:2px;">Reports</div>
  </div>
</div>

<hr>

<h2>Filling in the entry form</h2>

<table class="noborder" style="width:100%;max-width:650px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Field</th>
      <th style="padding:0.5rem 1rem;text-align:left;">What to enter</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Date</strong></td>
      <td style="padding:0.4rem 1rem;">The date it actually hit the bank statement</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Type</strong></td>
      <td style="padding:0.4rem 1rem;">Bank transfer (most common); Cheque if paying by cheque</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Label</strong></td>
      <td style="padding:0.4rem 1rem;">Description — e.g. "Macquarie monthly fee", "ATO PAYG Jun 2026"</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Amount</strong></td>
      <td style="padding:0.4rem 1rem;">Enter as a <strong>positive number</strong> — the Debit/Credit selector handles direction</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Debit / Credit</strong></td>
      <td style="padding:0.4rem 1rem;"><strong>Debit</strong> = money out of the bank &nbsp;|&nbsp; <strong>Credit</strong> = money in</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Account</strong></td>
      <td style="padding:0.4rem 1rem;">The accounting account the expense posts to — see table below</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Third party</strong></td>
      <td style="padding:0.4rem 1rem;">Optional — link to a supplier if you want the entry attributed. Leave blank for bank fees etc.</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>Common direct entries and their accounts</h2>

<table class="noborder" style="width:100%;max-width:700px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Transaction</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Debit / Credit</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Account</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Bank account keeping fee</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">6300.01 — Bank charges</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Bank transaction fees</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">6300.01 — Bank charges</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Interest earned</td>
      <td style="padding:0.4rem 1rem;">Credit (in)</td>
      <td style="padding:0.4rem 1rem;">7000.01 — Interest income</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">ATO — PAYG withholding payment</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">2112 — PAYG TAX liability</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Super — SBSCH payment</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">2111 — Super Guarantee liability</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">ATO — income tax instalment (PAYG-I)</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">9500 — Income tax (or as advised by accountant)</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Software subscription (e.g. Reckon, Adobe)</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">6200.03 — Software / IT expenses</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Insurance premium</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">6250 — Insurance</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Net wages — direct payroll transfer</td>
      <td style="padding:0.4rem 1rem;">Debit (out)</td>
      <td style="padding:0.4rem 1rem;">6400.10 — Wages &amp; Salaries</td>
    </tr>
  </tbody>
</table>

<div class="alert alert-warning" style="max-width:700px;margin-top:1rem;">
  <strong>Check your COA first</strong> — the account numbers above are based on the BCS/SSS chart of
  accounts. If you are unsure which account to use, check
  <strong>Accounting &gt; Account Balance</strong> to browse the full list, or ask your accountant.
</div>

<hr>

<h2>Editing or deleting an entry</h2>

<p>On the <strong>Bank entries</strong> tab, each row has a pencil icon (edit) and a bin icon (delete) on the right.
You can correct the date, label, amount, or account at any time <strong>before reconciliation</strong>.
Once an entry is reconciled (ticked off against a statement) you should not delete it — add a reversing
entry instead and note the reason in the label.</p>

<hr>

<h2>How this links to reconciliation</h2>

<p>Every entry you add here will appear on the <strong>Reconcile</strong> tab. When the same transaction
appears on your Macquarie statement, tick it off there. That's all — no extra step needed.</p>

<p>See <a href="reconcile.php">Bank Reconciliation</a> for the full reconciliation walkthrough.</p>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
