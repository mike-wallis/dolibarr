<?php
require '../../main.inc.php';
llxHeader('', 'Help — Delivery Addresses on Invoices');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Delivery Addresses — Shipping to a Different Site Than Bill To</h1>

<p>Some customers have one billing address (head office) but multiple sites you actually deliver
to — a warehouse, a job site, a second shop. Dolibarr can print a <strong>SHIP TO</strong> address
on the invoice PDF that's different from <strong>BILL TO</strong>, on a per-invoice basis, without
creating a separate customer record for every site.</p>

<div class="alert alert-info" style="margin:1rem 0;">
  If you don't link a shipping contact to an invoice, the SHIP TO box just shows the same address
  as BILL TO — this is the normal, default behaviour and needs no extra steps. The steps below are
  only for the customers who actually have more than one delivery site.
</div>

<hr>

<h2>Step 1 — Add the extra address to the customer, once</h2>

<ol style="font-size:1.05rem;line-height:2;">
  <li><strong>Third parties</strong> (top menu) &gt; <strong>Customers</strong> &gt; click the customer name</li>
  <li>Click the <strong>Contacts/Addresses</strong> tab</li>
  <li>Click <strong>New Contact/Address</strong></li>
  <li>Give it a name that describes the site — e.g. <em>"Warehouse"</em> or <em>"Site 2 — Smith St"</em>
      — and fill in that site's address</li>
  <li>Save</li>
</ol>

<p>Do this once per extra delivery site. It stays on the customer record permanently — you don't
re-enter it for every invoice.</p>

<hr>

<h2>Step 2 — Link it to a specific invoice as the shipping contact</h2>

<ol style="font-size:1.05rem;line-height:2;">
  <li>Open the invoice</li>
  <li>Click the <strong>Contacts/Addresses</strong> tab on the invoice (not the customer's — this tab
      exists on the invoice itself)</li>
  <li>Click <strong>Add</strong></li>
  <li>Choose the address you created in Step 1 from the contact list</li>
  <li>For <strong>Type of contact</strong>, select <strong>"Customer shipping contact"</strong></li>
  <li>Save, then (re)generate the PDF</li>
</ol>

<p>The PDF's <strong>SHIP TO</strong> box now shows that address instead of duplicating BILL TO.
BILL TO is untouched — it always reflects the customer's main address.</p>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>You must regenerate the PDF after linking the contact.</strong> Linking the contact alone
  doesn't update a PDF that was already generated — go to <strong>Generate document</strong> at the
  bottom of the invoice and click <strong>Generate</strong> again.
</div>

<hr>

<h2>Different invoices, different sites</h2>

<p>Because the shipping contact is linked per-invoice (not per-customer), the same customer can
have different invoices shipping to different sites — this month's invoice to the warehouse, next
month's to Site 2 — just by linking the relevant contact on each invoice individually.</p>

<hr>

<h2>Works for both BCS and SSS</h2>

<p>This applies to both invoice templates (<code>brightcs</code> and <code>southside</code>) — see
<a href="branding.php">BCS vs SSS Invoices</a> for choosing the right template. The shipping address
logic is the same either way.</p>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
