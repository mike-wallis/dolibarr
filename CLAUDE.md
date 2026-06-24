# CLAUDE.md

## Project Overview

This is a local Dolibarr ERP/CRM exploration, development, repair, and testing site running on Michael's laptop (WAMP, Windows 11).

The purpose of this project is to determine whether Dolibarr can be made suitable for use in an Australian small business accounting and operations environment (South Side Supplies / Bright Cleaning Solutions), and whether it can reasonably go live by **1 July 2026** (start of the Australian financial year).

This is **not** currently a production system. Treat it as a controlled local development and evaluation environment. Real customer/supplier/financial data should not be committed to git.

## Current Status (update as work progresses)

As of June 2026, the following has already been done — don't re-litigate these as if starting from scratch:

- **Smoke tests** of core Dolibarr workflows completed.
- **Data imported** via `scripts/import/`: chart of accounts, customers, suppliers, products/stock (see `imports/*-log.csv` for what was run and when).
- **Inventory valuation decision made**: AVCO (not FIFO) — see [docs/decisions/inventory-decisions.md](docs/decisions/inventory-decisions.md). Accountant review still required before go-live.
- **Custom modules built** (in `custom/modules/`):
  - `bas` — Australian BAS/PAYG report, calculates GST from Dolibarr payments
  - `brand` — auto-selects PDF template and "From" email address based on customer brand (BCS vs SSS)
  - `searchbox` — Google-style autocomplete on Dolibarr list pages
  - `stickynotes` — draggable/resizable notes on any page
- **Core overrides** for `facture`, `propale`, `supplier_order` PDF models live in `custom/core/modules/` (includes the BCS Purchase Order template).
- **Help pages** for staff (`custom/help/`) covering bank entries, GST/BAS, stock, reconciliation, recurring invoices, reports, supplier payments, TFN, payroll, branding, products, email.
- **Backup system built** — automated daily backups to `C:\Users\mhwal\OneDrive\Dolibarr_backups\`:
  - Dev site: `scripts/backup.ps1` (DB via mysqldump + documents folder + conf.php). Task Scheduler job: `DolibarrBackup`.
  - Live site: `scripts/backup-live.ps1` (DB via PHP PDO + conf.php via cPanel API + FTP mirror of `data/` folder). Task Scheduler job: `DolibarrLiveBackup`.
  - See KM2608 in the Knowledge Base for full procedure and restore steps.

Open / not yet done: not assumed — check before relying on anything not listed here.

## Repo Layout & Conventions

- Git repo root: `C:\wamp64\www\dolibarr\`. GitHub: `https://github.com/mike-wallis/dolibarr.git`.
- `htdocs/` (Dolibarr core) is excluded via `.gitignore` — never assume core files are tracked.
- `custom/` — custom modules, core overrides, help pages, email/print templates. This is copied into `htdocs/custom/` for deployment (see `scripts/deploy.ps1` / `.sh`).
- `docs/` — setup guides, workflow docs, migration mappings, **decisions** (`docs/decisions/`). New non-trivial decisions (e.g. valuation method, COA mapping choices, module trade-offs) should get a short doc here, following the style of `inventory-decisions.md`.
- `scripts/` — import scripts (`scripts/import/`), API test scripts (`scripts/api/`), deploy/host scripts.
- `imports/` — anonymised CSV samples, import logs, raw samples. No real business data.
- **Credentials**: in `.env` at repo root (DB name `dolibarr_dev`, user `dolibarr_dev`). Never read or write credentials in `conf.php`. Never commit `.env`.
- DB: MySQL 9.1.0 via `C:/wamp64/bin/mysql/mysql9.1.0/bin/mysql.exe`. Note: Dolibarr is officially tested on MySQL 8.0/MariaDB 10.x — flag any behaviour that might be a 9.x compatibility quirk rather than a Dolibarr bug.
- **Updating the live DB**: The local MySQL 9.1.0 client cannot connect to the live server (ERROR 2059 — `mysql_native_password` plugin not available in MySQL 9.1). Use PHP PDO instead:
  ```
  php -r "
  \$pdo = new PDO('mysql:host=LIVE_DB_HOST;dbname=LIVE_DB_NAME;charset=utf8', 'LIVE_DB_USER', 'LIVE_DB_PASS');
  \$pdo->exec(\"YOUR SQL HERE\");
  echo 'Done' . PHP_EOL;
  "
  ```
  Substitute credentials from `.env` (`LIVE_DB_HOST`, `LIVE_DB_NAME`, `LIVE_DB_USER`, `LIVE_DB_PASS`). Do not hardcode credentials in CLAUDE.md or scripts.

## Live Server Access

Live site: `https://erp.southsidesupplies.com.au` (VentraIP shared hosting, cPanel `vi6ie1gyagot`).

Directory layout on server:
- `/home/vi6ie1gyagot/erp_dolibarr/public_html/` — Dolibarr web root (no `htdocs/` subdirectory on live)
- `/home/vi6ie1gyagot/erp_dolibarr/public_html/conf/conf.php` — live config
- `/home/vi6ie1gyagot/erp_dolibarr/data/` — documents root (equivalent to local `documents/`)

**cPanel UAPI** (GET requests only — POST times out on VentraIP shared hosting):
- Base URL: `https://southsidesupplies.com.au:2083/execute/MODULE/FUNCTION`
- Auth header: `Authorization: cpanel vi6ie1gyagot:TOKEN` (token in `.env` as `LIVE_CPANEL_TOKEN`)
- `Fileman/get_file_content` works for text files. Binary file download does not honour `encoding=base64` — returns raw binary.
- `Fileman/compress` is disabled by VentraIP. All POST calls (including `save_file_content`) time out.
- cPanel requires TLS; use cert bypass in PowerShell (self-signed cert on shared IP).

**FTP** (used by `scripts/backup-live.ps1` for the data folder mirror):
- FTP server: `ftp.southsidesupplies.com.au` port 21 (explicit FTPS/TLS required; shared IP cert mismatch — use `-k` to bypass)
- Account: `dol_backup@southsidesupplies.com.au`, rooted at `/home/vi6ie1gyagot/erp_dolibarr/`; credentials in `.env` as `LIVE_FTP_USER` / `LIVE_FTP_PASS`
- The backup script uses `curl.exe` (built into Windows 11) with `--ftp-ssl-reqd -k` for recursive mirror; filenames with spaces are URL-encoded via `[System.Uri]::EscapeDataString`
- Do not use the special FTP account `vi6ie1gyagot` (requires cPanel password managed via VentraIP SSO)

**All credentials** (DB, cPanel API token, FTP) live in `.env` — never hardcode, never commit.

## Brands: BCS and SSS

Michael operates two trading brands under one legal entity/ABN, both running in this single Dolibarr instance (entity 1):

- **Bright Cleaning Solutions Pty Ltd (BCS)** — constants prefixed `BCS_`
- **South Side Supplies Pty Ltd (SSS)** — constants prefixed `SSS_`

Both sets of constants live in **Setup > Other Setup** (`llx_const` table). PDF templates, email templates, and custom code should read brand details via `getDolGlobalString('BCS_NAME')` etc. rather than hardcoding values. The `brand` module auto-selects which template/From-address to use based on the customer's brand category — check that module before adding brand-specific logic elsewhere.

## Primary Goal

Evaluate, configure, test, repair, and document Dolibarr so Michael can decide whether it is suitable to become the live business system from 1/7/2026.

The target outcome is a reliable Dolibarr setup that can support Australian business workflows including:

- Customers, suppliers, products and services
- Inventory / stock
- Quotes, sales orders, supplier orders
- Invoices, payments
- Documents / ECM
- Basic reporting
- Australian GST/BAS/accounting requirements
- Possible accounting export or integration with an external accounting system

## Business Context

Important Australian accounting/compliance areas to consider:

- GST treatment on sales and purchases
- BAS reporting requirements
- Tax invoice requirements
- ABN / ACN fields where relevant
- Australian address formats, AUD currency, Australian date formats
- Australian financial year (1 July – 30 June)
- Chart of accounts suitability
- Bank/payment reconciliation workflow
- Supplier invoices and purchase tracking
- Customer invoices and payment tracking
- Stock valuation and inventory workflow
- EOFY considerations
- Whether payroll is supported, unsupported, or should remain external

Do not assume Dolibarr is compliant for Australian accounting without checking and testing — but also don't re-ask questions already answered in "Current Status" or `docs/decisions/`.

## Go-Live Decision Date

**1 July 2026** — aligns with the start of the Australian financial year.

All work should help answer: *Can this Dolibarr setup be made reliable, understandable, maintainable, and suitable enough to go live on 1 July 2026?*

## Important Instruction for Claude

When helping with this project, do not just write code blindly.

For every change, explain:

- What the problem is
- What file or setting is involved
- What the proposed fix is
- Why the fix is safe or appropriate
- How to test it
- How to undo it if needed

Prefer clear, practical explanations over abstract theory.

## Development Rules

- Inspect the relevant files first; identify whether the issue is Dolibarr core, configuration, a module, database data, permissions, or server setup.
- Avoid modifying Dolibarr core files unless there is no better option. Prefer configuration, custom modules (`custom/modules/`), hooks, or core-override extension points (`custom/core/modules/`) — this project already uses both patterns.
- If a core change is unavoidable, clearly mark it and document it (in `docs/decisions/` if it affects upgrade risk).
- Keep changes small and testable. Do not make large multi-file rewrites unless explicitly requested.
- Do not delete code, comments, settings, or files just because they appear unused — explain why removal is safe first.
- Check for existing Dolibarr conventions, and existing custom modules in this repo, before inventing new ones.

## Testing Rules

After any change, suggest a test procedure. Where relevant, include:

- Browser test steps
- Admin/settings test steps
- Database checks
- Invoice/order/customer workflow checks
- GST/tax checks
- Permissions checks
- Error log checks
- Regression checks (existing Dolibarr functions still work)

Use realistic Australian business examples for test data (ABN-holding customer/supplier, GST and GST-free products, supplier invoice, customer invoice, payment received, purchase order, stock movement, end-of-month check).

## Accounting and Compliance Focus

For any accounting-related work, be extra careful. Always consider:

- Whether the result matches Australian GST requirements
- Whether invoices contain the required tax invoice details
- Whether GST is shown correctly
- Whether reports can support BAS preparation (note: `bas` module already exists — extend it rather than building a parallel report)
- Whether exports are compatible with an accountant's workflow
- Whether Dolibarr's accounting module is authoritative or only supporting operational records
- Whether an external accounting system is still required

Do not give legal, tax, or accounting compliance guarantees. Instead, identify what needs to be checked with an accountant or BAS agent.

## Dolibarr Evaluation Areas

1. **Setup and Configuration** — company settings, localisation, currency, tax rates, invoice numbering, email settings, PDF templates, user permissions, module activation, backup process.
2. **Sales Workflow** — customer setup, quotes/proposals, sales orders, delivery, customer invoices, payments, credit notes, statements.
3. **Purchasing Workflow** — supplier setup, supplier price lists, supplier orders/invoices/payments, purchase history, reordering.
4. **Products and Inventory** — product records, categories, supplier prices, stock levels, warehouses, stock movements, valuation, low-stock alerts, barcode/SKU.
5. **Documents / ECM** — storage of supplier price lists, invoices, purchase/customer documents; naming and folder structure; retrievability.
6. **Accounting / BAS Readiness** — chart of accounts, GST collected/paid, BAS-supporting reports, export to accountant, bank/payment workflow, EOM/EOFY workflow.
7. **Australian Fit** — ABN support, address format, AUD currency, date format, 1 July financial year, GST tax invoice format, practical usability.
8. **Maintainability** — ease of backups/updates, risk of custom changes breaking after updates, custom modules vs core changes, documentation quality, day-to-day usability for a non-expert.

## Repair and Debugging Approach

1. Reproduce the issue.
2. Check Dolibarr settings and enabled modules.
3. Check browser console if relevant.
4. Check PHP errors / web server logs / Dolibarr logs.
5. Check file permissions.
6. Check database records.
7. Search the codebase for the relevant function, hook, class, template, or configuration (including `custom/`).
8. Propose the smallest safe fix.
9. Test the fix.
10. Document what changed.

Do not guess where the issue is if it can be inspected.

## Code Style and Safety

- Follow the style of the existing Dolibarr codebase and this repo's existing custom modules.
- Use Dolibarr conventions where possible; preserve upgrade compatibility.
- Prefer modules/hooks/overrides over core edits.
- Avoid hardcoding business-specific values — use the `BCS_*`/`SSS_*` constants pattern (Setup > Other Setup) for brand-specific values.
- Never expose secrets, passwords, API keys, or database credentials. Never commit `.env` or real business data.
- Do not make production assumptions from the local dev environment.

## Documentation Requirements

Keep notes as the project evolves. Where useful, create or update documentation for:

- Setup steps, modules enabled, configuration decisions
- Australian tax/accounting settings
- Customisations made, bugs found, fixes applied, test results
- Go-live risks
- Open questions for accountant/BAS agent or Dolibarr community/forum

There are three places documentation lives — pick based on audience:

- **`docs/decisions/`** — git-tracked decisions and core-file patch records (upgrade-risk focus). Audience: future Claude/Michael working on the repo. See existing `inventory-decisions.md` for format/tone.
- **`custom/help/`** — staff-facing how-to pages, rendered inside Dolibarr's admin UI. Audience: day-to-day users (Michael/staff) doing a workflow.
- **In-app Knowledge Base** (see below) — bug/symptom/fix write-ups for things found during dev/testing. Audience: whoever hits the same symptom again, dev or staff.

A bug fix that involves a core-file patch typically needs **both** a `docs/decisions/` page (the diff + upgrade-risk record) **and** a Knowledge Base entry (the symptom/fix from a user's point of view), cross-linked to each other — see `vat-rate-dropdown-patch.html` / KM2607 as the pattern.

## Knowledge Base (Dolibarr Knowledge Management module)

Dolibarr's built-in **Knowledge Management** module is enabled (`MAIN_MODULE_KNOWLEDGEMANAGEMENT`, Setup > Modules) and is used as a searchable record of bugs/symptoms/fixes found while evaluating this install — separate from `docs/decisions/` (git-tracked, upgrade-risk focus) and `custom/help/` (staff workflow how-tos).

- Stored in table `llx_knowledgemanagement_knowledgerecord`: `ref` (e.g. `KM2606-0001`, `KM2607`), `question` (short title/symptom), `answer` (longtext HTML — symptom/root cause/fix, can include code blocks), `status`.
- Browse/search via the Dolibarr UI (Knowledge Management module menu) or query the table directly via the dev DB (see `.env` for credentials).
- When a fix involves a core-file patch (anything in `htdocs/`), the KB entry should describe the symptom/fix for a user, and link to the matching `docs/decisions/*.html` page for the actual diff/upgrade-risk record (and vice versa) — don't duplicate the diff in both places.
- Existing entries as of June 2026: `KM2606-0001` (supplier/vendor price lists), `KM2607` (GST not auto-populating on PO lines — covers the `price_suppliers.php` VAT code patch, cross-linked with `docs/decisions/vat-rate-dropdown-patch.html`), `KM2608` (backup system — dev and live backup scripts, schedule, restore procedure).

## Go-Live Readiness Checklist

- [~] Backup and restore tested — dev and live backups fully automated (DB + conf.php + data folder FTP mirror); restore procedure documented in KM2608 but not yet drilled end-to-end
- [ ] Admin user and staff users configured
- [ ] Permissions tested
- [ ] Company details correct (both BCS and SSS)
- [ ] GST/tax settings checked
- [ ] Invoice template checked (per brand)
- [x] Chart of accounts imported (review with accountant still required)
- [x] Customers, suppliers, products/stock imported
- [ ] Quote/order/invoice workflow tested end-to-end
- [ ] Supplier workflow tested end-to-end
- [ ] Product and stock workflow tested
- [ ] Payment workflow tested
- [ ] BAS-supporting reports tested (`bas` module)
- [ ] Email sending tested (per brand From-address via `brand` module)
- [ ] Document storage tested
- [ ] Data import process tested/repeatable
- [ ] Update process understood
- [ ] Accountant/BAS agent has reviewed accounting outputs, incl. AVCO vs FIFO impact
- [ ] Known limitations documented
- [ ] Decision made on payroll: Dolibarr, external system, or not used
- [ ] Decision made on whether Dolibarr accounting is authoritative or supporting only

## Preferred Working Style

Michael prefers:

- Step-by-step instructions, clear file paths
- Clear explanations of what each setting/file does
- Small, safe changes with test instructions after each fix
- Warnings when something could break updates
- Australian-specific considerations, plain English explanations

Avoid: large unexplained code dumps, unnecessary theory, overcomplicated architecture, changing core files without warning, assuming Dolibarr is already suitable for Australian accounting, assuming this will definitely go live.

## Playwright for ATO Data Scraping

Playwright is installed in `C:\Users\mhwal\AppData\Local\Temp\pw_ato\` and is used to scrape ATO web pages for tax table data and verification test data.

**Why headed mode is required**: The ATO website blocks headless browsers ("Access Denied"). Always launch with `headless: false`.

**Project location**: `C:\Users\mhwal\AppData\Local\Temp\pw_ato\`
- `package.json` / `node_modules\` — npm project with `playwright` and `xlsx` packages
- `ato_browse.mjs` — general browser/link exploration script
- `ato_scrape.mjs` — scrapes ATO sample data tables into CSVs
- `convert_stsl.mjs` — converts the ATO STSL Excel (NAT 3539) download to CSV

**Running a script**:
```powershell
Set-Location "C:\Users\mhwal\AppData\Local\Temp\pw_ato"
node ato_scrape.mjs
```

**ATO sample data pages (all confirmed 2026-07-01 update)**:
- Withholding amounts: `.../sample-data/withholding-amounts-sample-data`
- MLA Scale 2: `.../sample-data/medicare-level-adjustment-scale-2-sample-data`
- MLA Scale 6: `.../sample-data/medicare-half-levy-adjustment-scale-6-sample-data`
  - All 3 are under: `https://www.ato.gov.au/tax-rates-and-codes/payg-withholding-schedule-1-statement-of-formulas-for-calculating-amounts-to-be-withheld/`
  - Data is inline HTML tables (no CSV download); scrape with Playwright
- STSL Schedule 8 Excel: `https://www.ato.gov.au/api/public/content/f9885733974348d3b17aa7e657acaee0?v=9aaf689f`
  - Direct file download (xlsx); convert with `convert_stsl.mjs` using the `xlsx` npm package

**Bundled CSV outputs** (copy to `custom/modules/payroll/data/` after scraping):
- `ato-withholding-2026-27.csv` — 720 rows, 5 scales × 3 periods
- `ato-mla2-2026-27.csv` — 864 rows, spouse+5 children × 3 periods
- `ato-mla6-2026-27.csv` — 720 rows, 1–5 children × 3 periods
- `ato-stsl-2026-27.csv` — 885 rows, 5 scales × 3 periods (from ATO Excel, not scraped)

**Anti-bot config** (copy into new scripts if writing a new one):
```javascript
const browser = await chromium.launch({
  headless: false,
  args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'],
});
const context = await browser.newContext({
  userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  locale: 'en-AU',
  timezoneId: 'Australia/Sydney',
});
await context.addInitScript(() => {
  Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
});
```

## Decision-Making Standard

The aim is not just to make Dolibarr work technically. The aim is to decide whether Dolibarr is reliable, understandable, maintainable, accounting-compatible, practical, and safe enough to go live on 1 July 2026.

If the answer appears to be no, explain why and suggest alternatives or workarounds.
