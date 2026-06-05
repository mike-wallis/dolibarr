<?php
require '../../main.inc.php';
llxHeader('', 'Help — BCS vs SSS Invoices');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>BCS vs SSS — Getting the Right Invoice</h1>

<p>You have two invoice PDF templates: <strong>brightcs</strong> (Bright Cleaning Solutions) and
<strong>southside</strong> (South Side Supplies). Each customer belongs to one brand.
To get the correct letterhead, logo, phone, email, and bank details on an invoice you need to:</p>

<ol style="font-size:1.05rem;line-height:2;">
  <li>Tag the customer with <strong>SSS Customer</strong> or <strong>BCS Customer</strong></li>
  <li>Select the matching template when you generate the invoice PDF</li>
</ol>

<div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin:1.5rem 0;">
  <div style="flex:1;min-width:240px;border:3px solid #2c8a3e;border-radius:8px;padding:1rem;">
    <h3 style="margin-top:0;color:#2c8a3e;">BCS — Bright Cleaning Solutions</h3>
    <p style="margin:0;">Tag: <strong>BCS Customer</strong><br>Template: <code>brightcs</code></p>
  </div>
  <div style="flex:1;min-width:240px;border:3px solid #2980b9;border-radius:8px;padding:1rem;">
    <h3 style="margin-top:0;color:#2980b9;">SSS — South Side Supplies</h3>
    <p style="margin:0;">Tag: <strong>SSS Customer</strong><br>Template: <code>southside</code></p>
  </div>
</div>

<hr>

<h2>Step 1 — Tag the customer</h2>

<p>Do this once per customer when you first set them up (or go back and tag existing customers now).</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:280px;">
    <ol>
      <li><strong>Third parties</strong> (top menu) &gt; <strong>Customers</strong> &gt; click the customer name</li>
      <li>Click the <strong>Categories</strong> tab on the customer record</li>
      <li>Tick <strong>SSS Customer</strong> (or <strong>BCS Customer</strong>) and click <strong>Save</strong></li>
    </ol>
    <p>The coloured tag now appears on the customer record — easy to see at a glance which brand they belong to.</p>
    <div class="alert alert-warning" style="margin:0.5rem 0;">
      Only tick one brand tag per customer. If a customer somehow buys from both brands, create separate customer records.
    </div>
  </div>

  <!-- Third parties sidebar mock-up -->
  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#e08e2a;">&#9646;</span> Third parties
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New third party</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Customers</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Prospects</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Suppliers</div>
    </div>
    <div style="padding:0.4rem 0.75rem;background:#f4f4f4;border-top:1px solid #ddd;font-size:0.8rem;color:#888;">
      <em>— click customer name —</em>
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">Summary</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Contacts</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        Categories &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Invoices</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Orders</div>
    </div>
  </div>
</div>

<hr>

<h2>Step 2 — Select the template when generating the invoice PDF</h2>

<p>Each time you create an invoice and are ready to print or email it, you need to confirm the correct template is selected.</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.5rem;">
  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Open the invoice</li>
      <li>Scroll down to the <strong>Generate document</strong> section at the bottom of the invoice page</li>
      <li>Check the model dropdown — it will show the last-used model for this invoice</li>
      <li>
        Change it if needed:
        <ul style="margin-top:0.4rem;">
          <li>BCS invoice → select <strong>brightcs</strong></li>
          <li>SSS invoice → select <strong>southside</strong></li>
        </ul>
      </li>
      <li>Click <strong>Generate</strong> — the PDF is created with the correct branding</li>
    </ol>
    <p>Dolibarr remembers the last-used model for each individual invoice, so if you re-generate it later it will default to the same template.</p>
  </div>

  <!-- Generate document mock-up -->
  <div style="flex-shrink:0;width:320px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      Generate document
    </div>
    <div style="padding:0.75rem;">
      <table style="width:100%;font-size:0.85rem;border-collapse:collapse;">
        <tr>
          <td style="padding:0.3rem 0.5rem 0.3rem 0;color:#555;">Model:</td>
          <td style="padding:0.3rem 0;">
            <select class="flat" style="width:160px;font-family:monospace;font-size:0.85rem;">
              <option>sponge</option>
              <option selected style="font-weight:bold;">southside &#8592; SSS</option>
              <option>brightcs</option>
              <option>crabe</option>
            </select>
          </td>
        </tr>
        <tr>
          <td style="padding:0.3rem 0.5rem 0.3rem 0;color:#555;">Language:</td>
          <td style="padding:0.3rem 0;"><input type="text" value="en_AU" class="flat" style="width:80px;" readonly></td>
        </tr>
      </table>
      <div style="margin-top:0.75rem;">
        <span style="background:#5c73a0;color:#fff;padding:0.35rem 0.9rem;border-radius:4px;font-size:0.85rem;cursor:default;">Generate</span>
      </div>
    </div>
  </div>
</div>

<hr>

<h2>What each template prints</h2>

<table class="noborder" style="width:100%;max-width:700px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Element</th>
      <th style="padding:0.5rem 1rem;text-align:left;color:#2c8a3e;">brightcs (BCS)</th>
      <th style="padding:0.5rem 1rem;text-align:left;color:#2980b9;">southside (SSS)</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;">Trading name</td>
      <td style="padding:0.4rem 1rem;">Bright Cleaning Solutions</td>
      <td style="padding:0.4rem 1rem;">South Side Supplies</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Phone</td>
      <td style="padding:0.4rem 1rem;">0401 130 096</td>
      <td style="padding:0.4rem 1rem;">0431 779 857</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Email</td>
      <td style="padding:0.4rem 1rem;">accounts@brightcs.com.au</td>
      <td style="padding:0.4rem 1rem;">southsidesupplies.yes@gmail.com</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Bank BSB / Account</td>
      <td style="padding:0.4rem 1rem;">182-512 / 000974446429</td>
      <td style="padding:0.4rem 1rem;">182-512 / 000974446429</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;">Brand colour</td>
      <td style="padding:0.4rem 1rem;"><span style="background:#2c8a3e;color:#fff;padding:0.1rem 0.5rem;border-radius:3px;">Green</span></td>
      <td style="padding:0.4rem 1rem;"><span style="background:#2980b9;color:#fff;padding:0.1rem 0.5rem;border-radius:3px;">Blue</span></td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;">Early payment discount box</td>
      <td style="padding:0.4rem 1rem;">No</td>
      <td style="padding:0.4rem 1rem;">Yes — 2.5% if paid within 7 days</td>
    </tr>
  </tbody>
</table>

<p style="margin-top:1rem;font-size:0.9rem;color:#666;">
  Brand values (phone, email, bank details, tagline) are stored in
  <strong>Home &gt; Setup &gt; Other setup</strong> under <code>BCS_*</code> and <code>SSS_*</code> constants.
  Update them there if contact details change — no code changes needed.
</p>

<hr>

<h2>Tagging existing customers in bulk</h2>

<p>If you have a list of existing customers that haven't been tagged yet:</p>
<ol>
  <li><strong>Third parties</strong> &gt; Customers &gt; tick the checkboxes for a group of SSS customers</li>
  <li>Use the <strong>Actions</strong> dropdown at the bottom of the list &gt; <strong>Add category</strong> &gt; select <strong>SSS Customer</strong></li>
  <li>Repeat for BCS customers with the <strong>BCS Customer</strong> tag</li>
</ol>

<hr>

<h2>Sending the invoice by email</h2>

<p>After generating with the correct template, email it from the invoice page using the
<strong>Send email</strong> button. Make sure the <em>From</em> address matches the brand —
see <a href="email.php">Sending Emails</a> for how to switch between BCS and SSS sender addresses.</p>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
