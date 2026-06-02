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

| Setting | Location | Effect |
|---|---|---|
| STOCK_CALCULATE_ON_RECEPTION | Stocks gear > Main tab | Stock increases when reception is validated |
| STOCK_CALCULATE_ON_SHIPMENT | Stocks gear > Main tab | Stock decreases when shipment is validated |

**Without STOCK_CALCULATE_ON_RECEPTION:** the "Create Reception" button doesn't appear on POs.
**Without STOCK_CALCULATE_ON_SHIPMENT:** selling products doesn't decrease stock (outstanding issue as of 2026-06-02).

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
- [ ] Enable STOCK_CALCULATE_ON_SHIPMENT and test shipment workflow — verify stock drops to 12 after 3-unit sale
- [ ] Disable or scope Chrome extension so Incognito isn't needed for rich-text forms

### This week
- [ ] Generate REST API key (Setup > API/Web services)
- [ ] Test REST API: GET /products, GET /orders, POST /invoices from PHP
- [ ] Test customer-specific pricing (price list per customer)
- [ ] Configure cash accounting mode and verify BAS VAT report output

### Before go-live
- [ ] Export Reckon data as CSV, anonymise samples, map columns to Dolibarr import format
- [ ] Chart of accounts: map Reckon COA to Dolibarr accounts (accountant review)
- [ ] Confirm FIFO→AVCO switch with accountant (see inventory-decisions.md)
- [ ] Set TAX_MODE to 2 (payment date) and test BAS output
- [ ] Add real bank account details to bank01
- [ ] Load real products, customers, suppliers
- [ ] Test PDF invoice output matches required format
- [ ] Backup strategy for production: database dumps + documents/ folder

### Deferred / Nice to have
- [ ] Custom translation to replace "VAT" with "GST" in UI
- [ ] Custom PDF template with GST wording for customer invoices
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
| Shipment → Stock decrease on sale | ❌ Stock still 15, should be 12 |
| REST API test | ⬜ Not started |
| Customer-specific pricing | ⬜ Not started |
| BAS / VAT report | ⬜ Not started |
