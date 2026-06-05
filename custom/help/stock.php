<?php
require '../../main.inc.php';
llxHeader('', 'Help — Stock & Inventory');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Stock &amp; Inventory</h1>

<p>Stock moves when specific documents are <strong>validated</strong>. The two triggers are:</p>
<ul>
  <li><strong>Stock IN:</strong> Validate a <strong>Reception</strong> (purchase side)</li>
  <li><strong>Stock OUT:</strong> Validate a <strong>Customer Invoice</strong> — Dolibarr asks which warehouse to deduct from</li>
</ul>
<p>Shipments can still be created for delivery documentation but they do <strong>not</strong> trigger stock movement.</p>

<hr>

<h2>Purchase workflow — stock coming in</h2>

<table class="noborder" style="width:100%;max-width:700px;">
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>1. Create Purchase Order</strong> (Draft)</td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>2. Approve PO</strong> — changes status to Approved</td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>3. Order PO</strong> — marks it as sent to supplier</td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#e8f5e9;border-radius:4px;"><strong>4. Create Reception</strong> — stock increases when you validate this</td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>5. Create Supplier Invoice</strong> — from the PO or from Billing</td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>6. Validate Supplier Invoice</strong></td>
  </tr>
  <tr><td style="padding:0.2rem 0.8rem;color:#888;">↓</td></tr>
  <tr>
    <td style="padding:0.4rem 0.8rem;background:#f0f4ff;border-radius:4px;"><strong>7. Enter Payment</strong> — see <a href="supplier-payments.php">Supplier Payments</a> for batch payment steps</td>
  </tr>
</table>

<div class="alert alert-warning" style="margin:1.5rem 0;">
  <strong>Gotcha — "Classify Received" does NOT move stock.</strong><br>
  The <em>Classify Received</em> button on the PO only changes the PO's status label.
  Stock only moves when you create a <strong>Reception</strong> document and validate it.
  If you don't see the "Create Reception" button, check that
  <strong>STOCK_CALCULATE_ON_RECEPTION</strong> is enabled in Stocks settings.
</div>

<hr>

<h2>Sales — two workflows, both move stock correctly</h2>

<p>Stock OUT always happens the same way: <strong>validate the customer invoice</strong> and select a warehouse.
Use whichever workflow suits the sale — both work.</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin:1rem 0 1.5rem;max-width:860px;">

  <div style="border:1px solid #c8e6c9;border-radius:6px;overflow:hidden;">
    <div style="background:#e8f5e9;padding:0.6rem 1rem;font-weight:bold;">Workflow A — Fill &amp; invoice immediately</div>
    <table class="noborder" style="width:100%;">
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">1. Create Customer Invoice</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#fce8e8;"><strong>2. Validate Invoice</strong> → select warehouse → <strong>stock decreases</strong> ✓</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">3. Enter Payment</td></tr>
    </table>
  </div>

  <div style="border:1px solid #c8e6c9;border-radius:6px;overflow:hidden;">
    <div style="background:#e8f5e9;padding:0.6rem 1rem;font-weight:bold;">Workflow B — Waiting for stock</div>
    <table class="noborder" style="width:100%;">
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">1. Create Sales Order</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">2. Raise PO → receive stock via Reception → <strong>stock increases</strong> ✓</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">3. Create Customer Invoice (from the Sales Order)</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#fce8e8;"><strong>4. Validate Invoice</strong> → select warehouse → <strong>stock decreases</strong> ✓</td></tr>
      <tr><td style="padding:0.1rem 0.8rem;color:#888;">↓</td></tr>
      <tr><td style="padding:0.4rem 0.8rem;background:#f0f4ff;">5. Enter Payment</td></tr>
    </table>
  </div>

</div>

<div class="alert alert-info" style="margin:0 0 1.5rem 0;">
  <strong>Why stock moves on invoice, not shipment:</strong>
  Dolibarr supports two stock-out triggers — shipment or invoice. This installation uses
  <strong>invoice</strong> (<code>STOCK_CALCULATE_ON_BILL</code>) so that both workflows above
  work correctly without double-counting. Shipment documents can still be created for
  delivery records but will not affect stock levels.
</div>

<hr>

<h2>Checking stock levels</h2>
<ul>
  <li><strong>Products &gt; [product] &gt; Stock tab</strong> — shows current stock per warehouse</li>
  <li><strong>Stock &gt; Stock movements</strong> — full history of every in/out movement</li>
  <li><strong>Stock &gt; Stock by warehouse</strong> — all products across all warehouses</li>
</ul>

<h2>Valuation method</h2>
<p>Dolibarr uses <strong>AVCO</strong> (average weighted cost). The purchase price on your Receptions
sets the cost, and Dolibarr averages it across existing stock automatically.</p>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
