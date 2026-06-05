<?php
require '../../main.inc.php';
llxHeader('', 'Help — Sending Emails');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Sending Emails — Two Brands</h1>

<p>Both brands send through the same Google Workspace SMTP relay. The From address is what
changes depending on which brand the document belongs to.</p>

<hr>

<h2>Bright Cleaning Solutions (BCS) — default</h2>
<p>BCS is the default. For invoices, quotes, and purchase orders:</p>
<ol>
  <li>Open the document and click <strong>Send by email</strong></li>
  <li>The From field will already show <code>accounts@brightcs.com.au</code></li>
  <li>Add the recipient, adjust the message if needed, click <strong>Send</strong></li>
</ol>
<p>No extra steps needed — BCS is the Dolibarr default sender.</p>

<hr>

<h2>South Side Supplies (SSS)</h2>
<p>SSS invoices must be sent from <code>southsidesupplies.yes@gmail.com</code>. The send dialog
has a From dropdown — you need to switch it before sending.</p>
<ol>
  <li>Open the SSS invoice and click <strong>Send by email</strong></li>
  <li>In the send dialog, find the <strong>From</strong> dropdown at the top</li>
  <li>Select <strong>South Side Supplies</strong> from the list</li>
  <li>The From address changes to <code>southsidesupplies.yes@gmail.com</code></li>
  <li>Add the recipient and send</li>
</ol>

<div class="alert alert-warning" style="margin:1rem 0;">
  <strong>Important:</strong> If you forget to switch the From field, the email will arrive from
  <code>accounts@brightcs.com.au</code> — the wrong brand. Always check the From before sending
  an SSS document.
</div>

<p>The South Side Supplies sender profile is configured at
<strong>Setup &gt; Emails &gt; Emails sender profiles</strong>.</p>

<hr>

<h2>Troubleshooting</h2>

<h3>Email not arriving</h3>
<ul>
  <li>Check the recipient's spam/junk folder</li>
  <li>Check the Dolibarr event log: open the document &gt; <strong>Events</strong> tab — shows whether the send succeeded or failed</li>
  <li>Test with <strong>Setup &gt; Emails &gt; Send test email</strong></li>
</ul>

<h3>From address is wrong</h3>
<ul>
  <li>For BCS: check <strong>Setup &gt; Emails</strong> — "Sender email for automatic emails" should be <code>accounts@brightcs.com.au</code></li>
  <li>For SSS: you must manually select South Side Supplies from the From dropdown in the send dialog each time</li>
</ul>

<h3>535 authentication error</h3>
<p>The SMTP App Password has expired or been revoked. Generate a new one at
<code>myaccount.google.com/apppasswords</code> (sign in as <code>michaelw@brightcs.com.au</code>),
then update it in <strong>Setup &gt; Emails &gt; SMTP password</strong>.</p>

<hr>

<h2>How it works (summary)</h2>
<table class="noborder" style="width:auto;">
  <tr>
    <th style="padding:0.5rem 1rem;">Brand</th>
    <th style="padding:0.5rem 1rem;">From address</th>
    <th style="padding:0.5rem 1rem;">SMTP relay</th>
    <th style="padding:0.5rem 1rem;">Auth as</th>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;">BCS</td>
    <td style="padding:0.5rem 1rem;"><code>accounts@brightcs.com.au</code></td>
    <td style="padding:0.5rem 1rem;">smtp.gmail.com:587</td>
    <td style="padding:0.5rem 1rem;"><code>michaelw@brightcs.com.au</code></td>
  </tr>
  <tr>
    <td style="padding:0.5rem 1rem;">SSS</td>
    <td style="padding:0.5rem 1rem;"><code>southsidesupplies.yes@gmail.com</code></td>
    <td style="padding:0.5rem 1rem;">smtp.gmail.com:587</td>
    <td style="padding:0.5rem 1rem;"><code>michaelw@brightcs.com.au</code></td>
  </tr>
</table>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
