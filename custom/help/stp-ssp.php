<?php
require '../../main.inc.php';

if (!$user->admin) {
    accessforbidden();
}

llxHeader('', 'STP & SSP — ATO Reporting Plan');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Single Touch Payroll (STP) &amp; Sending Service Providers (SSP)</h1>
<p style="color:#555;">Research notes and transition plan — June 2026. Not a day-to-day workflow; this is planning context for Michael.</p>

<hr>

<h2>The Two-Layer Model</h2>
<p>The ATO separates two roles:</p>
<table class="noborder" style="max-width:700px;width:100%;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Role</th>
      <th style="padding:0.5rem 1rem;text-align:left;">What it does</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Who</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;font-weight:bold;">DSP<br><small style="font-weight:normal;">Digital Service Provider</small></td>
      <td style="padding:0.4rem 1rem;">Calculates pay, produces the PAYEVNT data, is responsible for accuracy</td>
      <td style="padding:0.4rem 1rem;">Dolibarr Payroll Module (eventually)</td>
    </tr>
    <tr style="background:#f9f9f9;">
      <td style="padding:0.4rem 1rem;font-weight:bold;">SSP<br><small style="font-weight:normal;">Sending Service Provider</small></td>
      <td style="padding:0.4rem 1rem;">Wraps the payroll data in an SBR ebMS3 XML envelope and transmits it to the ATO. Handles authentication, schemas, retry logic, response handling.</td>
      <td style="padding:0.4rem 1rem;">Third-party gateway (e.g. SuperChoice)</td>
    </tr>
  </tbody>
</table>
<p><strong>Key point:</strong> the payroll module only needs to produce <em>STP-compliant data</em> (CSV, TXT, or XML with all required PAYEVNT fields).
The SSP handles all the protocol complexity. You do not need to implement SBR2/ebMS3 yourself.</p>

<hr>

<h2>Current Plan</h2>
<ul>
  <li>Continue using <strong>Reckon</strong> for STP submission until licence expires (~May 2027)</li>
  <li>During that time: select an SSP, confirm their data format, build export function in the Dolibarr payroll module</li>
  <li>After Reckon: submit STP via chosen SSP using export from Dolibarr payroll module</li>
</ul>

<hr>

<h2>Data the Module Must Export (PAYEVNT Phase 2)</h2>
<p>STP Phase 2 submissions are <strong>YTD cumulative</strong> — not per-payrun. Each event updates running totals for all active employees.</p>

<h3>Submission Header</h3>
<ul>
  <li>Employer ABN</li>
  <li>BMS (software) name, version, Product ID</li>
  <li>SSID — 10-digit Software Subscription ID issued by the SSP for this employer/software combo (stored in module config)</li>
  <li>Pay event date</li>
</ul>

<h3>Per-Employee (YTD cumulative)</h3>
<table class="noborder" style="max-width:700px;width:100%;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Field</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Module status</th>
    </tr>
  </thead>
  <tbody>
    <tr><td style="padding:0.35rem 1rem;">TFN</td><td style="padding:0.35rem 1rem;">Stored encrypted in user extrafields</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">Full name, date of birth</td><td style="padding:0.35rem 1rem;">In user record</td></tr>
    <tr><td style="padding:0.35rem 1rem;">Employment type (full-time, part-time, casual, labour hire&hellip;)</td><td style="padding:0.35rem 1rem;color:#c00;">Not tracked yet</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">Income type (salary &amp; wages, working holiday maker&hellip;)</td><td style="padding:0.35rem 1rem;color:#c00;">Not tracked yet</td></tr>
    <tr><td style="padding:0.35rem 1rem;">Tax scale / PAYG withholding scale</td><td style="padding:0.35rem 1rem;">Stored on employee record</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">YTD gross income</td><td style="padding:0.35rem 1rem;">Calculable from payrun lines</td></tr>
    <tr><td style="padding:0.35rem 1rem;">YTD PAYG withheld</td><td style="padding:0.35rem 1rem;">Calculable from payrun lines</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">YTD super guarantee</td><td style="padding:0.35rem 1rem;">Calculable from payrun lines</td></tr>
    <tr><td style="padding:0.35rem 1rem;">YTD salary sacrifice (if applicable)</td><td style="padding:0.35rem 1rem;color:#c00;">Not tracked yet</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">YTD reportable employer super (if applicable)</td><td style="padding:0.35rem 1rem;color:#c00;">Not tracked yet</td></tr>
    <tr><td style="padding:0.35rem 1rem;">YTD reportable fringe benefits (if applicable)</td><td style="padding:0.35rem 1rem;color:#c00;">Not tracked yet</td></tr>
    <tr style="background:#f9f9f9;"><td style="padding:0.35rem 1rem;">Employment start/end dates (new starters &amp; leavers)</td><td style="padding:0.35rem 1rem;color:#888;">May not be tracked</td></tr>
    <tr><td style="padding:0.35rem 1rem;">Address</td><td style="padding:0.35rem 1rem;">In user record</td></tr>
  </tbody>
</table>

<hr>

<h2>SSP Integration Options</h2>
<table class="noborder" style="max-width:700px;width:100%;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Model</th>
      <th style="padding:0.5rem 1rem;text-align:left;">How it works</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Effort</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 1rem;font-weight:bold;">File upload</td>
      <td style="padding:0.4rem 1rem;">Module generates a CSV/XML file; Michael uploads it to the SSP's web portal after each payrun</td>
      <td style="padding:0.4rem 1rem;color:#2d6a2d;">Low — realistic first step</td>
    </tr>
    <tr style="background:#f9f9f9;">
      <td style="padding:0.4rem 1rem;font-weight:bold;">Direct API</td>
      <td style="padding:0.4rem 1rem;">Module POSTs to SSP's API endpoint automatically after payrun</td>
      <td style="padding:0.4rem 1rem;color:#7a5000;">Medium — once SSP is chosen</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 1rem;font-weight:bold;">Intermediary-initiated</td>
      <td style="padding:0.4rem 1rem;">BAS agent submits through SSP on employer's behalf</td>
      <td style="padding:0.4rem 1rem;color:#888;">N/A for self-lodgement</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>SSP Providers Researched (June 2026)</h2>

<h3>SuperChoice — Most Relevant</h3>
<p><strong>superchoiceservices.com</strong> — ABN 78 109 509 739</p>
<ul>
  <li>Explicitly offers STP as a gateway service <em>to DSPs</em> — "Seamless integration to enhance your product and UX"</li>
  <li>Products: ATO Gateway, STP submission, Clearing House, Payday Super (ready for 1 July 2026)</li>
  <li>Explicitly lists "Digital Service Providers" as a target market with a DSP integration path</li>
  <li>Established company; serves super funds, DSPs, and employers</li>
  <li>Contact: <strong>sales@superchoice.com.au</strong></li>
</ul>
<p><strong>Action: Contact SuperChoice and ask:</strong></p>
<ol>
  <li>Do they accept third-party payroll data from small DSPs (single-business install)?</li>
  <li>What file format / API do they accept from the DSP side?</li>
  <li>What is the SSID issuance process?</li>
  <li>What does it cost?</li>
</ol>

<h3>Reckon GovConnect STP</h3>
<p>This is Reckon's internal ATO transmission pipe — not available as a standalone SSP for third-party software. Cannot be used once Reckon licence ends.</p>

<h3>Other Providers (full payroll software, not SSP gateways)</h3>
<ul>
  <li><strong>Employment Hero / KeyPay</strong> — full payroll DSP, not an SSP gateway</li>
  <li><strong>CloudPayroll</strong> — full payroll software; has developer API at dev.cloudpayroll.com.au but for managing payroll data, not for third-party STP submission</li>
  <li><strong>Microkeeper</strong> — full payroll software, $2.25/user/month for payroll + STP only plan; possible fallback if module approach doesn't work</li>
  <li><strong>Payroll Metrics</strong> — full payroll software, ISO 27001 certified, has API</li>
  <li><strong>Iotas</strong> — URL resolved to an unrelated wireless testing company (wrong lead)</li>
  <li><strong>Transmission / Paypa Plane</strong> — both domains returned DNS errors; possibly defunct or rebranded</li>
</ul>

<h3>Find the Full SSP Register</h3>
<p>The ATO product register at <code>softwaredevelopers.ato.gov.au/product-register</code> links to a separate
"Sending service providers" register which lists all accredited SSPs. Browse that list for additional options.</p>

<hr>

<h2>About the SSID</h2>
<p>The SSP issues a unique 10-digit <strong>SSID (Software Subscription ID)</strong> per employer/software combination.
It must be Modulo-10 valid and registered in ATO Access Manager.
Once issued, it becomes a stored config value in the payroll module — a settings field will be needed for it.</p>

<hr>

<h2>Next Steps</h2>
<ol>
  <li>Contact <strong>SuperChoice</strong> (sales@superchoice.com.au) to confirm DSP integration model and data format</li>
  <li>Browse the ATO SSP register for additional options</li>
  <li>Add missing employee fields to the module: employment type, income type, salary sacrifice, start/end dates</li>
  <li>Build a YTD export function in the payroll module (format TBD once SSP is confirmed)</li>
  <li>Add an SSID config field to the module settings</li>
  <li>File upload first; API integration once the SSP and format are confirmed</li>
</ol>

<div class="alert alert-info" style="max-width:700px;margin-top:1.5rem;">
  <strong>Source:</strong> Researched via Playwright against ATO softwaredevelopers site, ATO product register, SuperChoice, KeyPay, Employment Hero, CloudPayroll, Microkeeper, Reckon, and several other providers — June 2026.
</div>

</div>
<?php llxFooter(); ?>
