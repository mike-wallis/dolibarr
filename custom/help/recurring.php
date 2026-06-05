<?php
require '../../main.inc.php';
llxHeader('', 'Help — Recurring Invoices');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Recurring Invoices</h1>

<p>Recurring invoices are <strong>templates</strong> that generate real invoices on a schedule.
The template stores all the details — customer, lines, amounts, GST — and sits in a list until
you (or the system) generates the next invoice from it.</p>

<hr>

<h2>Setting up a recurring customer invoice</h2>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Billing | Payment</strong> in the top menu</li>
      <li>Under <strong>Customer invoices</strong> in the left sidebar, click <strong>List of templates</strong></li>
      <li>Click <strong>New template invoice</strong></li>
      <li>Fill in exactly as you would a normal invoice:
        <ul>
          <li>Customer</li>
          <li>Lines, quantities, unit prices, GST rate</li>
          <li>Payment terms</li>
        </ul>
      </li>
      <li>Set the recurrence options (see below)</li>
      <li>Save</li>
    </ol>
  </div>

  <!-- Sidebar mock-up -->
  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Customer invoices
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        List of templates &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>

</div>

<h3 style="margin-top:1.5rem;">Recurrence options</h3>

<table class="noborder" style="width:100%;max-width:650px;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Field</th>
      <th style="padding:0.5rem 1rem;text-align:left;">What it does</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Example</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Frequency</strong></td>
      <td style="padding:0.4rem 1rem;">Number + unit (days / months / years)</td>
      <td style="padding:0.4rem 1rem;">1 month = monthly</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Date next run</strong></td>
      <td style="padding:0.4rem 1rem;">When the next invoice will be generated</td>
      <td style="padding:0.4rem 1rem;">01/07/2026</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;"><strong>Auto-validate</strong></td>
      <td style="padding:0.4rem 1rem;">Generate as Draft (you review) or validated (sent straight away)</td>
      <td style="padding:0.4rem 1rem;">Leave as Draft to review first</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 1rem;"><strong>Max period / End date</strong></td>
      <td style="padding:0.4rem 1rem;">Stop generating after N invoices or after a date</td>
      <td style="padding:0.4rem 1rem;">Leave blank for ongoing</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>Generating the invoices (manual)</h2>

<p>On WAMP there is no automatic scheduler running in the background — you trigger generation manually.
Do this at the start of each month (or whenever the invoices are due).</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Billing | Payment</strong> in the top menu</li>
      <li>Click <strong>List of templates</strong> under Customer invoices</li>
      <li>The list shows each template and its <strong>Next date</strong></li>
      <li>Templates due today or overdue are highlighted</li>
      <li>Click <strong>Generate pending invoices</strong> (button at top of list)</li>
      <li>Dolibarr creates draft invoices from all due templates</li>
      <li>Go to <strong>List</strong> (customer invoices) — find the new drafts, review and validate each one</li>
      <li>Send as normal</li>
    </ol>

    <div class="alert alert-info" style="margin:1rem 0;">
      After generating, the template's <strong>Next date</strong> automatically advances by one period
      (e.g. 1 July → 1 August). You don't need to update it manually.
    </div>
  </div>

  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Customer invoices
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        List of templates &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>

</div>

<hr>

<h2>Recurring supplier invoices</h2>

<p>Same process — use this for regular bills you receive on a known schedule (e.g. rent, phone, subscriptions).</p>

<div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;">

  <div style="flex:1;min-width:280px;">
    <ol>
      <li>Click <strong>Billing | Payment</strong> > <strong>Vendor invoices</strong> > <strong>List of templates</strong></li>
      <li>Create a template for each recurring supplier bill</li>
      <li>Each month, generate pending invoices from the template list</li>
      <li>Review the drafts, validate, then pay via the normal <a href="supplier-payments.php">batch payment</a> process</li>
    </ol>

    <div class="alert alert-info" style="margin:1rem 0;">
      Recurring supplier invoices are useful even if the amount varies slightly — create the template
      with the typical amount and edit the draft before validating if the actual bill differs.
    </div>
  </div>

  <div style="flex-shrink:0;width:200px;border:1px solid #ccc;border-radius:6px;background:#fff;font-size:0.875rem;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
    <div style="padding:0.5rem 0.75rem;background:#f4f4f4;border-bottom:1px solid #ddd;font-weight:bold;color:#333;">
      <span style="color:#5cb85c;">&#9646;</span> Vendor invoices
    </div>
    <div style="padding:0.35rem 0;">
      <div style="padding:0.2rem 0.75rem;color:#555;">New invoice</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">List</div>
      <div style="padding:0.3rem 0.75rem;background:#fff3cd;font-weight:600;color:#333;border-left:3px solid #f0ad4e;">
        List of templates &nbsp;&#8592;
      </div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Payments</div>
      <div style="padding:0.2rem 0.75rem;color:#555;">Statistics</div>
    </div>
  </div>

</div>

<hr>

<h2>Monthly routine (suggested)</h2>

<p>At the start of each month, in order:</p>

<ol>
  <li><strong>Generate customer invoice templates</strong> — Billing | Payment > Customer invoices > List of templates > Generate pending</li>
  <li><strong>Review and validate</strong> the new draft customer invoices, send to customers</li>
  <li><strong>Generate supplier invoice templates</strong> — Billing | Payment > Vendor invoices > List of templates > Generate pending</li>
  <li><strong>Validate</strong> the new draft supplier invoices, adjust amounts if the actual bill differs</li>
  <li>Pay suppliers via normal <a href="supplier-payments.php">batch payment</a> process at end of month</li>
</ol>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
