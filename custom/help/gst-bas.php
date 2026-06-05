<?php
require '../../main.inc.php';
llxHeader('', 'Help — GST & BAS');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>GST &amp; BAS</h1>

<p>Dolibarr is set to <strong>cash-basis GST</strong> — GST is reported in the period the payment
is received or made, not when the invoice is issued. This matches how most small Australian
businesses lodge their BAS.</p>

<hr>

<h2>Running the quarterly VAT report</h2>
<p>Run this at the end of each quarter (Sep, Dec, Mar, Jun) before lodging your BAS.</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Billing | Payment</strong> in the top menu</li>
      <li>In the left sidebar find <strong>Taxes | Special expenses</strong> and click <strong>VAT</strong> — this expands the sub-menu</li>
      <li>Click <strong>Report per month</strong></li>
      <li>Set the period to cover the quarter<br>
          e.g. for Jul–Sep: <code>01/07/2026</code> to <code>30/09/2026</code></li>
      <li>Click <strong>Search</strong></li>
      <li>The report shows:
        <ul>
          <li><strong>Total sales (incl. GST)</strong> — your G1</li>
          <li><strong>GST collected on sales</strong> — your 1A</li>
          <li><strong>GST paid on purchases</strong> — your 1B</li>
        </ul>
      </li>
    </ol>

    <div class="alert alert-info" style="margin:1rem 0;">
      The report only includes <strong>paid</strong> invoices — cash basis means unpaid invoices
      are not counted. Make sure all payments for the quarter are entered before running the report.
    </div>
  </div>

  <!-- Sidebar mock-up -->
  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Taxes | Special expenses
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Social/fiscal taxes</div>
      <div style="padding:0.2rem 0.75rem;font-weight:600;color:#333;">VAT</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">New</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">List</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Payments</div>
      <div style="padding:0.3rem 1.4rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Report per month &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Report per third party</div>
      <div style="padding:0.2rem 1.4rem;color:#888;font-size:0.82rem;">Report per rate</div>
    </div>
  </div>

</div>

<hr>

<h2>BAS form — where the numbers go</h2>

<table class="noborder" style="width:auto;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;">BAS label</th>
      <th style="padding:0.5rem 1rem;">What it is</th>
      <th style="padding:0.5rem 1rem;">Where to find it</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.5rem 1rem;"><strong>G1</strong></td>
      <td style="padding:0.5rem 1rem;">Total sales (incl. GST)</td>
      <td style="padding:0.5rem 1rem;">VAT report — Total TTC sales</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.5rem 1rem;"><strong>1A</strong></td>
      <td style="padding:0.5rem 1rem;">GST collected (on sales)</td>
      <td style="padding:0.5rem 1rem;">VAT report — GST on sales</td>
    </tr>
    <tr>
      <td style="padding:0.5rem 1rem;"><strong>1B</strong></td>
      <td style="padding:0.5rem 1rem;">GST credits (on purchases)</td>
      <td style="padding:0.5rem 1rem;">VAT report — GST on purchases</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.5rem 1rem;"><strong>Net GST</strong></td>
      <td style="padding:0.5rem 1rem;">1A minus 1B = amount to pay ATO (or refund)</td>
      <td style="padding:0.5rem 1rem;">Calculate manually</td>
    </tr>
  </tbody>
</table>

<p style="margin-top:1rem;">Enter these figures manually into the ATO Business Portal or your BAS lodgement form.
Dolibarr does not submit to the ATO directly.</p>

<hr>

<h2>Zero-rated sales (GST-free)</h2>
<p>Products with a 0% GST rate appear separately in the VAT report. They count toward G1 (total sales)
but not 1A (GST collected). Make sure products that are genuinely GST-free are set to the 0% rate.</p>

<hr>

<h2>Checking a specific payment's GST</h2>
<ol>
  <li>Open the customer invoice or supplier invoice</li>
  <li>The invoice shows the GST amount on each line and in the totals block</li>
  <li>For supplier invoices, this is your 1B credit — only counted once the payment is entered</li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>Cash basis reminder:</strong> An invoice dated in June but paid in July counts in the
  <em>July quarter</em>, not June. Always check payment dates, not invoice dates, when reconciling your BAS.
</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
