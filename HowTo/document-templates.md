# Document Templates

How to create, edit and deploy PDF/document templates in Dolibarr.

---

## How it works

Dolibarr generates documents (invoices, quotes, orders, etc.) by merging live data into a template file. Two template types are available:

| Type | Format | Best for |
|---|---|---|
| PHP templates | `.php` file | Production hosting, full control |
| ODT templates | `.odt` file | Easy layout editing, requires LibreOffice on server |

---

## Production hosting — which template type to use

ODT templates require **LibreOffice** (`soffice`) installed on the web server to convert `.odt` → `.pdf`. This is available on a VPS or dedicated server but **not on shared hosting**.

South Side Supplies will be hosted alongside the existing website on shared hosting — **PHP templates are the correct choice for production**.

| Scenario | Use |
|---|---|
| Shared hosting (cPanel, Plesk, etc.) | PHP templates only |
| VPS / dedicated server | Either — LibreOffice can be installed |
| Dev machine (this install) | Either — LibreOffice is installed and configured |

---

## Directory structure

```
C:\wamp64\www\dolibarr\
├── htdocs\
│     └── core\modules\facture\doc\   ← PHP invoice templates (built-in AND custom)
└── documents\
      └── doctemplates\
            ├── invoices\              ← ODT invoice templates
            ├── proposals\
            └── ...
```

**Custom PHP template location:** place your file directly in `core\modules\facture\doc\` using a unique name. Dolibarr upgrades only overwrite their own named files (`pdf_crabe`, `pdf_sponge`, `pdf_octopus`) — a file named `pdf_brightcs.modules.php` is never touched.

> Note: `custom\core\modules\facture\doc\` does NOT work — Dolibarr's `dol_buildpath()` function explicitly skips `core/` paths when searching alternative directories (line 1659 of `functions.lib.php`).

The `documents` folder is never directly accessible via URL — Dolibarr serves files through its own permission-checked layer.

---

## Registering a custom PHP template

After creating the file, register it in the database so it appears in the Doc template dropdown:

```sql
INSERT INTO llx_document_model (nom, type, entity)
VALUES ('brightcs', 'invoice', 1);
```

Replace `brightcs` with your template name and `invoice` with the document type. Without this row, the template file exists but never appears in the UI.

---

## PHP templates

### Built-in templates

| Template | File |
|---|---|
| crabe | `htdocs\core\modules\facture\doc\pdf_crabe.modules.php` |
| sponge | `htdocs\core\modules\facture\doc\pdf_sponge.modules.php` |
| octopus | `htdocs\core\modules\facture\doc\pdf_octopus.modules.php` |

### Creating a custom template by extending an existing one

The recommended approach: extend `pdf_crabe` and override only the methods you need. This avoids copying ~2500 lines of code.

```php
require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/doc/pdf_crabe.modules.php';

class pdf_brightcs extends pdf_crabe
{
    public function __construct($db)
    {
        parent::__construct($db);
        $this->name        = 'brightcs';
        $this->description = 'Bright Cleaning Solutions invoice';
        // Override column positions here
    }

    public function write_file($object, $outputlangs, ...) { ... }
    protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null) { ... }
    protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0, $heightforqrinvoice = 0) { ... }
    protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, ...) { ... }
    protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis) { ... }
}
```

### Template structure — key methods

| Method | What it draws | Returns |
|---|---|---|
| `write_file()` | Entry point — calls all other methods | 1=OK, <=0=error |
| `_pagehead()` | Logo, company info, ABN table, Bill To/Ship To, P.O./Terms row | `$top_shift` (extra mm added to header) |
| `_pagefoot()` | Bank details, tagline, page numbers | Height of footer in mm |
| `_tableau()` | Column header row with labels and vertical dividers | void |
| `_tableau_tot()` | Totals block: excl. GST, GST rate lines, incl. GST | Bottom Y position |
| `_tableau_info()` | Payment terms text (left side of totals area) | void |

All drawing uses **TCPDF** — positions are in millimetres from the top-left of the page.

### Column position variables

Set in the constructor. `write_file()` draws data in this fixed left-to-right order — positions **must increase** in this sequence:

```
posxdesc → posxtva → posxup → posxqty → posxunit → postotalht
```

| Variable | Role |
|---|---|
| `posxdesc` | Left edge of description column |
| `posxpicture` | Clips description cell width (set = posxqty when no product images) |
| `posxtva` | GST % column |
| `posxup` | Unit price column |
| `posxqty` | Quantity column |
| `posxunit` | Unit column |
| `posxdiscount` | Discount column (set = posxunit if not shown) |
| `postotalht` | Line total column |

> **Common mistake:** putting `posxtva` after `posxup` causes negative cell widths and garbled output. The order must match the sequence above regardless of the visual order you want for column headers.

### Overriding translations (GST terminology)

Dolibarr's translate class keeps the **first** value loaded for each key — standard lang files load before custom files, so a `custom/langs/` file cannot override existing keys.

The correct approach: inject into `$outputlangs->tab_translate` inside `write_file()` **before** calling `parent::write_file()`. Because the translate class skips already-set keys, your values take precedence:

```php
public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
{
    $t = &$outputlangs->tab_translate;
    $t['VAT']                         = 'GST';
    $t['TotalHT']                     = 'Total (excl. GST)';
    $t['TotalTTC']                    = 'Total (inc. GST)';
    $t['TotalVAT']                    = 'Total GST';
    $t['PaymentCondition30DENDMONTH'] = '30 days EOM';
    // add any other key overrides here

    return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
}
```

### Currency formatting in totals

Dolibarr's `price()` function returns plain numbers (e.g. `357.91`). To display `$357.91` in the totals block, override `_tableau_tot()` and use a helper:

```php
private function bcs_price($amount, $sign = 1)
{
    $val = (float) ($sign * $amount);
    return ($val < 0 ? '-$' : '$') . number_format(abs($val), 2, '.', ',');
}
```

Then use `$this->bcs_price($total_ht)` instead of `price($total_ht, 0, $outputlangs)` in your `_tableau_tot()` override. Line item amounts in the body columns are narrow so plain numbers are fine there.

### Dynamic company data

Retrieve live company data from `$this->emetteur` (the `Societe` object for your company):

| Property | Source constant | Example value |
|---|---|---|
| `$this->emetteur->name` | `MAIN_INFO_SOCIETE_NOM` | Bright Cleaning Solutions Pty Ltd |
| `$this->emetteur->address` | `MAIN_INFO_SOCIETE_ADDRESS` | 70 Brisbane Corso |
| `$this->emetteur->phone` | `MAIN_INFO_SOCIETE_TEL` | 0438840281 |
| `$this->emetteur->email` | `MAIN_INFO_SOCIETE_MAIL` | sales@brightcs.com.au |
| `$this->emetteur->idprof1` | `MAIN_INFO_SIREN` | 40 151 737 245 (ABN) |
| `$this->emetteur->logo` | `MAIN_INFO_SOCIETE_LOGO` | filename of uploaded logo |

Update these via **Home → Setup → Company/Organisation**.

### Payment condition labels

Two fields control what appears on documents:

| Field | Used for | Update via |
|---|---|---|
| `libelle` | Dropdown labels in the UI | Admin → Dictionary → Payment conditions |
| `libelle_facture` | Text shown on the invoice PDF | Same, or direct SQL |

```sql
UPDATE llx_c_payment_term SET libelle='30 days EOM', libelle_facture='30 days EOM'
WHERE code='30DENDMONTH';
```

The PDF also checks for a translation key `PaymentCondition<CODE>` first — inject this via `write_file()` as shown above.

---

## ODT templates (dev/testing only)

ODT templates use LibreOffice for conversion. Suitable for the dev site but not for production shared hosting.

### Tools
- **LibreOffice Writer** — recommended. ODT is its native format.
- **Microsoft Word** — works for simple changes. Save back as `.odt` not `.docx`.
- **Google Docs** — open from Drive, download as ODF when done.

### Workflow
1. Copy `documents\doctemplates\invoices\template_invoice.odt` to your Desktop.
2. Open in LibreOffice Writer, make changes.
3. Save as `.odt`.
4. Copy back to `documents\doctemplates\invoices\`.

Save with a different name (e.g. `brightcs_invoice.odt`) to keep it alongside the default.

### Enabling the ODT template module
1. **Home → Setup → Modules/Apps → Invoices → configure cog**
2. **Document templates** tab → enable **Generic ODT**

---

## Generating a document from an invoice

1. Open the invoice card: `http://dolibarr.test/compta/facture/card.php?id=<id>`
2. Scroll to the bottom — **Linked files** section.
3. Select your template from the **Doc template** dropdown.
4. Click **Generate**.
5. The PDF appears as a link — click to preview in browser (inline preview is enabled on this install).
6. Use **Send Email** to email it to the customer with the PDF attached.

---

## Previewing documents in the browser

`MAIN_DISABLE_FORCE_SAVEAS = 1` is set on this installation so PDFs open inline in a browser tab rather than downloading.

To enable on another installation:
- **Home → Setup → Other setup** → add `MAIN_DISABLE_FORCE_SAVEAS` = `1`

Or via SQL:
```sql
INSERT INTO llx_const (name, value, type, entity)
VALUES ('MAIN_DISABLE_FORCE_SAVEAS', '1', 'chaine', 1)
ON DUPLICATE KEY UPDATE value = '1';
```

---

## Logos and graphics

### Company logo (all documents)
Stored in `documents\mycompany\` — upload via **Setup → Company/Organisation → Logo**.
Dolibarr embeds it automatically in all generated PDFs via `$this->emetteur->logo`.

### Media library
General images (product photos, email content) stored in `documents\medias\`.
Managed via **Home → Tools → Media/File manager**.

### Images in ODT templates
Images embedded in a `.odt` file (LibreOffice: Insert → Image) are stored inside the file itself.

---

## Other document types

The same pattern applies to all document types — place the file directly in the `core` module directory:

| Document | Directory |
|---|---|
| Invoices | `core\modules\facture\doc\` |
| Quotes/Proposals | `core\modules\propale\doc\` |
| Sales orders | `core\modules\commande\doc\` |
| Supplier invoices | `core\modules\fournisseur\doc\` |
| Supplier orders | `core\modules\supplier_order\doc\` |
| Delivery/Shipments | `core\modules\expedition\doc\` |

Register each in `llx_document_model` with the appropriate `type` value (`invoice`, `propal`, `commande`, etc.).
