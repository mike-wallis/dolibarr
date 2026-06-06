<?php
require '../../main.inc.php';
llxHeader('', 'Help — Product Setup & Naming');
?>
<div class="fiche">

<p><a href="index.php">← Help home</a></p>
<h1>Product Setup &amp; Naming Convention</h1>

<p>Every product in Dolibarr has four fields that serve different purposes. Understanding which field
does what is the key to fast searching and clean invoices.</p>

<hr>

<h2>The four fields — what each one is for</h2>

<table class="noborder" style="width:100%;max-width:900px;margin-bottom:1.5rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.5rem 1rem;text-align:left;">Field</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Purpose</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Appears on invoice?</th>
      <th style="padding:0.5rem 1rem;text-align:left;">Example</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.5rem 1rem;"><strong>Reference</strong></td>
      <td style="padding:0.5rem 1rem;">The SKU — unique identifier, matches the website variant SKU</td>
      <td style="padding:0.5rem 1rem;color:green;">Yes — shown next to the line</td>
      <td style="padding:0.5rem 1rem;"><code>BLAZ5</code></td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.5rem 1rem;"><strong>Label</strong></td>
      <td style="padding:0.5rem 1rem;">Internal search string — used when adding products to invoices and orders. Never seen by customers.</td>
      <td style="padding:0.5rem 1rem;color:#c00;">No — suppressed in our templates</td>
      <td style="padding:0.5rem 1rem;"><code>Hard Floor/Surface | Degreaser | ECOLAB | Blue Lazer 5L</code></td>
    </tr>
    <tr>
      <td style="padding:0.5rem 1rem;"><strong>Description</strong></td>
      <td style="padding:0.5rem 1rem;">Customer-facing text. Copied to the invoice line when you add the product.</td>
      <td style="padding:0.5rem 1rem;color:green;">Yes — the main line text</td>
      <td style="padding:0.5rem 1rem;"><code>Blue Lazer Kitchen &amp; Bathroom Degreaser 5L</code></td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.5rem 1rem;"><strong>Category</strong></td>
      <td style="padding:0.5rem 1rem;">Hierarchy used for product list filtering, reports, and website sync.</td>
      <td style="padding:0.5rem 1rem;color:#888;">No</td>
      <td style="padding:0.5rem 1rem;">Hard Floor/Surface → Degreaser</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>Label format — the naming convention</h2>

<p>The Label follows this pattern:</p>

<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.5rem;max-width:700px;font-family:monospace;font-size:1rem;margin-bottom:1rem;">
  Category | Sub-category | MANUFACTURER | Product Name Variant
</div>

<ul>
  <li><strong>Category</strong> — top-level category, must match the category tree exactly</li>
  <li><strong>Sub-category</strong> — leaf-level category, must match the category tree exactly</li>
  <li><strong>MANUFACTURER</strong> — supplier brand name in ALL CAPS (makes it stand out when searching)</li>
  <li><strong>Product Name</strong> — the brand's product name</li>
  <li><strong>Variant</strong> — size, colour, or other distinguishing detail at the end</li>
</ul>

<h3>Examples</h3>

<table class="noborder" style="width:100%;max-width:900px;margin-bottom:1.5rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 0.8rem;text-align:left;">Reference (SKU)</th>
      <th style="padding:0.4rem 0.8rem;text-align:left;">Label (internal search)</th>
      <th style="padding:0.4rem 0.8rem;text-align:left;">Description (on invoice)</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 0.8rem;"><code>BLAZ5</code></td>
      <td style="padding:0.4rem 0.8rem;">Hard Floor/Surface | Degreaser | ECOLAB | Blue Lazer 5L</td>
      <td style="padding:0.4rem 0.8rem;">Blue Lazer Kitchen &amp; Bathroom Degreaser 5L</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 0.8rem;"><code>BLAZ25</code></td>
      <td style="padding:0.4rem 0.8rem;">Hard Floor/Surface | Degreaser | ECOLAB | Blue Lazer 25L</td>
      <td style="padding:0.4rem 0.8rem;">Blue Lazer Kitchen &amp; Bathroom Degreaser 25L</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 0.8rem;"><code>KC44301</code></td>
      <td style="padding:0.4rem 0.8rem;">Hand Towel | Ultraslim | KIMBERLY-CLARK | Scott Ultraslim Hand Towel</td>
      <td style="padding:0.4rem 0.8rem;">Scott Ultraslim Hand Towel 24 x 150 sheets</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 0.8rem;"><code>CC0051901</code></td>
      <td style="padding:0.4rem 0.8rem;">Toilet Tissue | Rolls | CAPRICE | Toilet Tissue 2ply 400 sheet</td>
      <td style="padding:0.4rem 0.8rem;">Caprice Toilet Tissue 2ply 400 sheet 48 rolls</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>How search works when entering invoice lines</h2>

<p>When you add a product line to an invoice or quote, Dolibarr searches both the <strong>Label</strong>
and the <strong>Reference</strong> as a substring match. Because the Label contains the full hierarchy,
you can type any of the following and get a filtered result:</p>

<table class="noborder" style="width:100%;max-width:700px;margin-bottom:1.5rem;">
  <thead>
    <tr style="background:#f5f5f5;">
      <th style="padding:0.4rem 0.8rem;text-align:left;">You type</th>
      <th style="padding:0.4rem 0.8rem;text-align:left;">You get</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td style="padding:0.4rem 0.8rem;"><code>Hard Floor</code></td>
      <td style="padding:0.4rem 0.8rem;">Every hard floor/surface product</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 0.8rem;"><code>Degreaser</code></td>
      <td style="padding:0.4rem 0.8rem;">All degreasers across all categories</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 0.8rem;"><code>ECOLAB</code></td>
      <td style="padding:0.4rem 0.8rem;">All Ecolab products</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 0.8rem;"><code>Blue Lazer</code></td>
      <td style="padding:0.4rem 0.8rem;">Both sizes of Blue Lazer</td>
    </tr>
    <tr>
      <td style="padding:0.4rem 0.8rem;"><code>BLAZ5</code></td>
      <td style="padding:0.4rem 0.8rem;">Exact SKU match</td>
    </tr>
    <tr style="background:#fafafa;">
      <td style="padding:0.4rem 0.8rem;"><code>Hand Towel</code></td>
      <td style="padding:0.4rem 0.8rem;">All hand towel products (rolls and ultraslim)</td>
    </tr>
  </tbody>
</table>

<hr>

<h2>How the invoice looks</h2>

<p>Our invoice templates (BCS and SSS) suppress the Label and show only the <strong>Description</strong>
and <strong>Reference (SKU)</strong> on each line. The customer sees clean product text and the SKU —
nothing from the internal Label hierarchy.</p>

<div style="border:1px solid #ccc;border-radius:6px;overflow:hidden;max-width:700px;margin-bottom:1.5rem;font-size:0.9rem;">
  <div style="background:#f4f4f4;padding:0.5rem 1rem;font-weight:bold;border-bottom:1px solid #ddd;">Invoice line — what the customer sees</div>
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="background:#fafafa;border-bottom:1px solid #eee;">
        <th style="padding:0.4rem 1rem;text-align:left;">SKU</th>
        <th style="padding:0.4rem 1rem;text-align:left;">Description</th>
        <th style="padding:0.4rem 1rem;text-align:right;">Qty</th>
        <th style="padding:0.4rem 1rem;text-align:right;">Unit Price</th>
        <th style="padding:0.4rem 1rem;text-align:right;">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td style="padding:0.4rem 1rem;">BLAZ5</td>
        <td style="padding:0.4rem 1rem;">Blue Lazer Kitchen &amp; Bathroom Degreaser 5L</td>
        <td style="padding:0.4rem 1rem;text-align:right;">4</td>
        <td style="padding:0.4rem 1rem;text-align:right;">$18.50</td>
        <td style="padding:0.4rem 1rem;text-align:right;">$74.00</td>
      </tr>
      <tr style="background:#fafafa;">
        <td style="padding:0.4rem 1rem;">KC44301</td>
        <td style="padding:0.4rem 1rem;">Scott Ultraslim Hand Towel 24 x 150 sheets</td>
        <td style="padding:0.4rem 1rem;text-align:right;">2</td>
        <td style="padding:0.4rem 1rem;text-align:right;">$42.00</td>
        <td style="padding:0.4rem 1rem;text-align:right;">$84.00</td>
      </tr>
    </tbody>
  </table>
</div>

<hr>

<h2>Formatting rules</h2>

<ul>
  <li><strong>Sizes:</strong> always <code>5L</code>, <code>25L</code>, <code>750mL</code> — not <em>5 litre</em>, <em>5l</em>, or <em>5ltr</em></li>
  <li><strong>Counts:</strong> <code>250 sheets</code>, <code>12pk</code>, <code>6 roll</code></li>
  <li><strong>Colours:</strong> full word — <code>Blue</code>, <code>Red</code> — not <em>BLU</em> or <em>blu</em></li>
  <li><strong>Manufacturer names:</strong> ALL CAPS in the Label field — <code>ECOLAB</code>, <code>TORK</code>, <code>KIMBERLY-CLARK</code> — so they stand out when searching</li>
  <li><strong>Separator:</strong> space-pipe-space &nbsp;<code> | </code>&nbsp; between each segment</li>
</ul>

<hr>

<h2>Category tree</h2>

<p>Categories match the South Side Supplies website — two levels: <strong>Category → Sub-category</strong>.
Always assign to the sub-category (the child), not the parent.</p>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;max-width:1100px;margin-bottom:1.5rem;font-size:0.88rem;">

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">All Purpose</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner+Deodoriser+Disinfectant<br>PH Neutral Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Bathroom</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner<br>Urinal</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Bin Liner</strong>
    <div style="color:#555;margin-left:0.75rem;">Mobile Bin<br>Small<br>Standard</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Bleach</strong>
    <div style="color:#555;margin-left:0.75rem;">Liquid<br>Powder</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Carpet</strong>
    <div style="color:#555;margin-left:0.75rem;">Stain Remover</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Carpet &amp; Fabric</strong>
    <div style="color:#555;margin-left:0.75rem;">Stain Remover<br>Urine Remover</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Catering</strong>
    <div style="color:#555;margin-left:0.75rem;">Baking Paper<br>Containers<br>Cups<br>Foils<br>Wraps</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Combi Oven</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Concrete</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Descaler</strong>
    <div style="color:#555;margin-left:0.75rem;">Lime &amp; Rust</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Dish Washing</strong>
    <div style="color:#555;margin-left:0.75rem;">Detergent</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Gloves</strong>
    <div style="color:#555;margin-left:0.75rem;">Latex<br>Vinyl</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Graffiti</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Hand Care</strong>
    <div style="color:#555;margin-left:0.75rem;">Sanitiser<br>Soap: HvyDuty<br>Soap: Liquid<br>Soap: Pods</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Hand Towel</strong>
    <div style="color:#555;margin-left:0.75rem;">Rolls<br>Ultraslim</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Hard Floor/Surface</strong>
    <div style="color:#555;margin-left:0.75rem;">Chlorinated<br>Cleaner+Deodoriser+Disinfectant<br>Cleaner+Disinfectant<br>Degreaser<br>Hvy Duty</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Hospital Grade</strong>
    <div style="color:#555;margin-left:0.75rem;">Disinfectant</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Kitchen Towel</strong>
    <div style="color:#555;margin-left:0.75rem;">Rolls</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Laundry</strong>
    <div style="color:#555;margin-left:0.75rem;">Detergent<br>Powder<br>Pre Stain<br>Softener</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Mould</strong>
    <div style="color:#555;margin-left:0.75rem;">Remover</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Odour Control</strong>
    <div style="color:#555;margin-left:0.75rem;">Air Freshener<br>Air Freshener + Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Oven &amp; Grill</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Sanitiser</strong>
    <div style="color:#555;margin-left:0.75rem;">No Rinse</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Spray and Wipe</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Stainless Steel</strong>
    <div style="color:#555;margin-left:0.75rem;">Polish</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Toilet Tissue</strong>
    <div style="color:#555;margin-left:0.75rem;">Jumbo Roll<br>Rolls</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Trap &amp; Drain</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Vehicle</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Vomit</strong>
    <div style="color:#555;margin-left:0.75rem;">Absorbant</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Ware Wash</strong>
    <div style="color:#555;margin-left:0.75rem;">Detergent<br>Glass Washing<br>Rinse Aid</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Window Glass</strong>
    <div style="color:#555;margin-left:0.75rem;">Cleaner</div>
  </div>

  <div style="border:1px solid #ddd;border-radius:6px;padding:0.75rem 1rem;">
    <strong style="display:block;margin-bottom:0.4rem;">Wipes</strong>
    <div style="color:#555;margin-left:0.75rem;">Absorbant</div>
  </div>

</div>

<div class="alert alert-info" style="margin:0 0 1.5rem 0;">
  <strong>Keep Dolibarr categories in sync with the website.</strong>
  When the website sync is built, website categories will map directly to Dolibarr categories.
  If you add a new category on the website, add the matching category in Dolibarr at the same time,
  then re-run <code>php imports/migrate_categories.php</code> to update the assignments.
</div>

<hr>
<p><a href="index.php">← Back to Help home</a></p>

</div>
<?php llxFooter(); ?>
