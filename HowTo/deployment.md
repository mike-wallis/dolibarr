# South Side Supplies — Dolibarr Deployment Notes

Running record of what we've learned, gotchas, and things still to do.
Update this file as new issues are found and resolved.

---

## Environment

- Dolibarr 23.0.3
- WAMP: Apache 2.4.62, PHP 8.3.14 (mod_php), MySQL 9.1.0
- Windows 11 Home
- Local URL: http://dolibarr.test
- DB: `dolibarr_dev` / user `dolibarr_dev`
- Admin: `admin` (password in `.env`)
- API key: in `.env` (generate once REST API is configured)

---

## Infrastructure Gotchas

### Virtual host
Apache vhost at `C:\wamp64\bin\apache\apache2.4.62.1\conf\extra\httpd-vhosts.conf`.
DocumentRoot points to `c:/wamp64/www/dolibarr/htdocs`.

### Hosts file
`C:\Windows\System32\drivers\etc\hosts` — must contain `127.0.0.1 dolibarr.test`.
**Do NOT add `::1 dolibarr.test`** — IPv6 loopback triggers Dolibarr WAF injection false positive.
If the file gets corrupted, restore it by running as admin:
```
scripts\restore-hosts.ps1
```

### PHP config (.htaccess, not .user.ini)
WAMP uses mod_php — `.user.ini` is ignored. PHP settings go in `htdocs\.htaccess`:
```apache
php_flag display_errors Off
php_value output_buffering 65536
```
Without `output_buffering`, PHP warnings printed before redirects cause "Cannot modify header information" crashes (known issue in reception/card.php).

---

## Known WAF Issues (Dolibarr Web Application Firewall)

Dolibarr's `waf.inc.php` blocks requests it considers injection attacks.
When blocked, view page source — there's an HTML comment showing which param triggered it.

### Issue 1: IPv6 loopback
`::1` in any parameter value matches the WAF injection pattern.
**Fix:** remove `::1 dolibarr.test` from hosts file (see above).

### Issue 2: Chrome extension injection
Chrome extensions can inject `<script src="chrome-extension://...">` into WYSIWYG/rich-text fields (e.g. product descriptions, warehouse notes). WAF blocks the form submit.
**Fix:** use Incognito mode (Ctrl+Shift+N) for any form with a rich-text description editor.
Long-term fix: identify and disable the offending extension for dolibarr.test.
The extension ID found so far: `fpelahbljekknahkcaegamhcndkfpfnc`.

---

## Australian Setup

### Company
- Country: Australia
- Currency: AUD
- Fiscal year start: July (month 7)

### GST / Tax
- Rates are pre-loaded for Australia (0% and 10%)
- Tax mode must be set to **2 (Payment date)** for cash basis GST
- Path: Admin > Taxes > Tax module setup
- Also set `TAX_MODE_SELL_PRODUCT` and `TAX_MODE_SELL_SERVICE` to `payment`
- See `HowTo/GST.md` for BAS output details

### BAS
- No built-in BAS form — use quarterly VAT report and manually enter 1A/1B into ATO portal
- VAT report: Home > Billing and Taxes > VAT Reports

---

## Custom Files (must deploy to htdocs/custom/)

These files live in the repo under `custom/` and must exist at the matching path under `htdocs/custom/` on any new environment. `dolibarr_main_document_root_alt` in conf.php must point to `htdocs/custom/` or none of these are found.

| Repo source | Deploy to (relative to htdocs/) |
|---|---|
| `custom/core/modules/propale/doc/pdf_brightcs.modules.php` | **`core/modules/propale/doc/pdf_brightcs.modules.php`** |
| `custom/core/modules/facture/doc/pdf_brightcs.modules.php` | **`core/modules/facture/doc/pdf_brightcs.modules.php`** |
| `custom/langs/en_GB/main.lang` | `custom/langs/en_GB/main.lang` |
| `custom/help/index.php` | `custom/help/index.php` |
| `custom/help/supplier-payments.php` | `custom/help/supplier-payments.php` |
| `custom/help/email.php` | `custom/help/email.php` |
| `custom/help/stock.php` | `custom/help/stock.php` |
| `custom/help/gst-bas.php` | `custom/help/gst-bas.php` |

Help pages deploy to `htdocs/custom/help/` (same relative path as repo source) and are served
at `http://dolibarr.test/custom/help/`. The `require '../../main.inc.php'` in each file is
relative to their location in `htdocs/custom/help/`.

**PDF templates must go into `htdocs/core/...`, NOT `htdocs/custom/core/...`.**
`dol_buildpath()` (functions.lib.php ~line 1659) explicitly skips the custom alt directory
for any path starting with `core/`. The file must be in the main core path to be found.

### Adding the Help menu item

Add a top-level menu link so the help pages are accessible from within Dolibarr:

1. **Setup > Menus** — select the active menu manager (default: `eldy_menu`)
2. Click **New menu entry**
3. Fill in:
   | Field | Value |
   |---|---|
   | Menu manager | eldy_menu |
   | Type | Top menu |
   | Position | (choose a number, e.g. 90 to place at the right) |
   | Label | Help |
   | URL | `/custom/help/index.php` |
   | Enabled | 1 |
   | Rights | (leave blank — visible to all) |
4. Save

The **Help** item will appear in the top navigation bar linking to the help home page.

---

### Registering custom PDF templates in the database

Dolibarr builds its template dropdown from `llx_document_model`, NOT by scanning the filesystem.
Dropping a file into `htdocs/custom/core/modules/propale/doc/` is necessary but not sufficient —
you must also insert a row:

```sql
INSERT INTO llx_document_model (nom, type, entity, libelle)
VALUES ('brightcs', 'propal', 1, 'Bright Cleaning Solutions quote template');
```

Do the same for invoice templates (`type = 'facture'`). If the row is missing, the template
file is silently ignored and only the built-in templates appear in the dropdown.

### BCS Quote template (`pdf_brightcs` for proposals)

**File:** `custom/core/modules/propale/doc/pdf_brightcs.modules.php`
**Deploys to:** `htdocs/core/modules/propale/doc/pdf_brightcs.modules.php`
**Extends:** `pdf_azur` (the invoice template extends `pdf_crabe` — different base class)

The template overrides two methods:

**`_tableau()`** — column headers
Injects translation overrides directly into `$outputlangs->tab_translate` at render time (bypasses the file-loader break issue), then delegates to the parent. Keys overridden:

| Key | Original | Replaced with |
|---|---|---|
| `VAT` | GST | GST |
| `PriceUHT` | Price (excl. tax) | Price (ex GST) |
| `TotalHTShort` | Total | Amount (ex GST) |
| `AmountInCurrency` | Amount in AU Dollars currency | ` ` (single space — see below) |

**`_tableau_tot()`** — totals block
Full copy of `pdf_azur::_tableau_tot()` with two changes:
1. GST label hardcoded as `'Total GST '` instead of using `transcountrynoentities("TotalVAT", ...)` — bypasses the translate load-order issue entirely.
2. All currency amounts prefixed with `$` using `'$'.price(...)` instead of bare `price(...)`.
3. Labels "Total (excl. GST)" and "Total (inc. GST)" are hardcoded strings.

**Why `' '` (space) not `''` for AmountInCurrency:**
`transnoentities()` checks `!empty($this->tab_translate[$key])` — empty string is falsy, so it falls through and returns the key name literally. A single space is truthy, so it renders as blank.

**Activate in Dolibarr:** Proposals > Setup > PDF model > select "Bright Cleaning Solutions quote template"

### Translation override — why TotalVATAU not TotalVAT

Dolibarr's translation loader uses PHP `+=` (first-loaded wins). The core `en_GB/main.lang`
defines `TotalVAT=Total VAT`, which blocks any custom `TotalVAT=Total GST` override.

The fix is country-specific keys (`TotalVATAU`, etc.) — not present in core files, so they
load cleanly regardless of order. `transcountrynoentities("TotalVAT", "AU")` checks
`TotalVATAU` first, finds it, and returns "Total GST".

**Do not simplify the lang file by removing `TotalVATAU` — the plain `TotalVAT` key alone
will not work due to load-order.**

Note: the lang file approach is largely irrelevant now — GST labels are hardcoded directly
in `pdf_brightcs._tableau_tot()` because `translate.class.php` line 395 breaks out of the
directory search loop after the first file, so `htdocs/custom/langs/en_GB/main.lang` is
never loaded regardless of what's in it.

---

## Modules Enabled

| Module | Notes |
|---|---|
| Products/Services | Core |
| Stocks/Inventory | Required for stock movements |
| Suppliers / Purchase Orders | Purchase workflow |
| Customers / Sales Orders | Sales workflow |
| Invoicing (customer + supplier) | Billing |
| Bank/Cash | Required to record payments |
| Tax/VAT | GST reporting |
| Subtotals | Enabled — cosmetic line grouping on docs |

---

## Stock Settings (critical)

These constants must be set or stock movements won't fire.

| Setting | Value | Effect |
|---|---|---|
| STOCK_CALCULATE_ON_RECEPTION | 1 (ON) | Stock increases when reception is validated |
| STOCK_CALCULATE_ON_BILL | 1 (ON) | Stock decreases when customer invoice is validated |
| STOCK_CALCULATE_ON_SHIPMENT | 0 (OFF) | Disabled — shipments do not move stock |

**Why STOCK_CALCULATE_ON_BILL instead of STOCK_CALCULATE_ON_SHIPMENT:**
Two sales workflows are used — fill-and-invoice immediately, and sales-order-then-invoice (waiting for stock).
Using invoice as the single stock-out trigger means both workflows work without double-counting.
Shipment documents can still be created for delivery records but will not affect stock levels.
Confirmed working 2026-06-04.

**Without STOCK_CALCULATE_ON_RECEPTION:** the "Create Reception" button doesn't appear on POs.

---

## Missing Database Tables (fresh install)

Some tables introduced in Dolibarr 23.0 may be absent on a fresh install. Hit a "table doesn't exist" error? Run the SQL from `htdocs/install/mysql/tables/` manually.

| Table | Error trigger | SQL files |
|---|---|---|
| `llx_categorie_propal` | Tags/categories field on proposals | `llx_categorie_propal-propal.sql` + `.key.sql` |

Run both files in order (table first, then keys) against `dolibarr_dev`.

---

## Workflows

### Purchase (incoming stock)
```
Create PO (Draft) → Approve → Order (send to supplier) → Create Reception → Validate Reception → Create Supplier Invoice → Validate Invoice → Enter Payment
```
- "Classify Received" on PO only changes PO status — it does NOT move stock
- Reception document is what creates the stock movement
- Supplier invoice can be created from the PO or from Billing > Supplier Invoices

### Sales (outgoing stock)
```
Create Sales Order → Validate → Create Shipment → Validate Shipment → Create Customer Invoice → Validate Invoice → Enter Payment
```
- Invoice → Payment alone does NOT decrease stock
- A Shipment document is required (equivalent of Reception for purchases)
- STOCK_CALCULATE_ON_SHIPMENT must be enabled

### Payment entry
- Requires a Bank account to exist first (Banking > Bank Accounts > New)
- bank01 was created during smoke test (BSB/account numbers are placeholder)

---

## Inventory Valuation

Dolibarr uses **AVCO** (average weighted cost) only — no FIFO option.
Current Reckon system uses FIFO.
Practical impact on cleaning supplies: small (stable supplier prices).
**Action before go-live:** accountant review — confirm whether FIFO→AVCO switch requires a revaluation journal entry and any tax implications.
See `docs/decisions/inventory-decisions.md` for full analysis.

---

## Outbound Email

Both brands send through the Google Workspace SMTP relay. See `HowTo/email.md` for full setup steps and gotchas.

| Setting | Value |
|---|---|
| SMTP host | `smtp.gmail.com:587` (STARTTLS) |
| Auth username | `michaelw@brightcs.com.au` (primary — NOT the alias) |
| Auth password | App Password (in `.env`) |
| Default From | `accounts@brightcs.com.au` (BCS documents) |
| SSS From | `southsidesupplies.yes@gmail.com` (change manually in send dialog) |

**Critical gotcha:** `accounts@brightcs.com.au` is a Workspace alias — SMTP auth must use the primary address `michaelw@brightcs.com.au` or Google returns 535 bad credentials.

**Before go-live:** clear the "Send all emails to" test override in Setup > Emails.

Gmail Send as addresses configured on `michaelw@brightcs.com.au`:
- `accounts@brightcs.com.au` (alias, treat as alias ✓)
- `southsidesupplies.yes@gmail.com` (not alias, via smtp.gmail.com with its own App Password)

---

## Git / Repo

Repo: https://github.com/mike-wallis/dolibarr.git
Working dir: `C:\wamp64\www\dolibarr\`

**Never commit:**
- `.env` (DB creds, API key)
- `htdocs/conf/conf.php` (DB password)
- `documents/` (Dolibarr data files)
- `images/` (screenshots)
- `htdocs/` (Dolibarr core — not our code)

All of the above are in `.gitignore`.

---

## To Do

### Immediate
- [x] Enable STOCK_CALCULATE_ON_SHIPMENT and test shipment workflow — stock confirmed at 12 after 3-unit sale
- [ ] Disable or scope Chrome extension so Incognito isn't needed for rich-text forms

### This week
- [x] Generate REST API key
- [x] Test REST API: GET /products, GET /customers, GET /invoices from PHP
- [ ] Test customer-specific pricing (price list per customer)
- [ ] Configure cash accounting mode and verify BAS VAT report output

### Migration (cutover 1 July 2026)
Week 1: Export from Reckon, anonymise samples, analyse column structure
Week 2: COA mapping + import (accountant input required)
Week 2-3: Products, customers, suppliers import + verify
Week 3: Stock levels/values snapshot (can't finalise until 30 June)
Week 4: UAT, manually enter outstanding bills/invoices, go-live prep
1 July: Import final stock snapshot, go live

- [x] Export products from Reckon → import complete (473 created, 10 skipped, 0 errors)
- [x] Export customers from Reckon → import complete (20 created, 0 errors)
- [x] Export suppliers from Reckon → import complete (78 created, 0 errors)
- [x] Chart of accounts: 279 accounts imported (AU-SSS-2026), accountant signed off
- [ ] Confirm FIFO→AVCO switch with accountant (see inventory-decisions.md)
- [ ] Stock valuation snapshot from Reckon as at 30 June → import as opening stock
- [ ] Manually enter outstanding bills and invoices at cutover

### Before go-live
- [x] Set TAX_MODE to 2 (payment date / cash basis) ← selected in accounting module setup
- [x] Accounting default accounts configured (AR, AP, GST, sales, COGS, bank)
- [x] VAT accounts: GST and FRE linked to account 2200
- [x] Bank account (bank01 / Macquarie) linked to COA account 1101, journal BQ
- [ ] Assign product-level accounting accounts (4010.01 Chemicals, 4010.04 Bin Liners, etc.)
- [ ] Test BAS / VAT report output for a sample quarter
- [x] Test PDF invoice output — brightcs invoice + quote templates created and working
- [x] Outbound email configured — BCS from accounts@brightcs.com.au, SSS from southsidesupplies.yes@gmail.com (see HowTo/email.md)
- [ ] Clear "Send all emails to" test override before go-live
- [ ] Backup strategy for production: database dumps + documents/ folder

### Deferred / Nice to have
- [x] Custom translation to replace "VAT" with "GST" in PDF output (custom/langs/en_GB/main.lang — uses TotalVATAU key, see deployment notes above)
- [ ] CLAUDE.md in repo root

---

## Smoke Test Status (as of 2026-06-02)

| Step | Status |
|---|---|
| Installation | ✅ |
| Company / AUD / July fiscal year | ✅ |
| GST rates (10% / 0%) | ✅ |
| Warehouse created | ✅ |
| Supplier created | ✅ |
| Product (TEST-001) created | ✅ |
| Opening stock set (10 units) | ✅ |
| Purchase Order → Reception → Stock increase | ✅ Stock at 15 (added 5) |
| Supplier Invoice → Payment | ✅ SI2606-0001 Paid |
| Sales Order → Customer Invoice → Payment | ✅ IN2606-0001 Paid |
| Shipment → Stock decrease on sale | ✅ Stock at 12 (sold 3) — now handled by invoice trigger |
| Invoice → Stock decrease (STOCK_CALCULATE_ON_BILL) | ✅ Confirmed 2026-06-04 |
| REST API test | ⬜ Not started |
| Customer-specific pricing | ⬜ Not started |
| BAS / VAT report | ⬜ Not started |
| Outbound email — BCS (accounts@brightcs.com.au) | ✅ Confirmed 2026-06-04 |
| Outbound email — SSS (southsidesupplies.yes@gmail.com) | ✅ Confirmed 2026-06-04 |
