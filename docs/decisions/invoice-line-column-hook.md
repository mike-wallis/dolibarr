# Invoice PDF Line Columns: GST Amount + Column Reorder

## Problem (found 2026-07-09)

On the BCS and SSS invoice PDFs (`pdf_brightcs`/`pdf_southside`, extending Dolibarr core's
`pdf_crabe`), the line-item table had two issues:

1. The "GST %" column showed the tax **rate** (e.g. `10%`) on every line instead of the
   calculated GST **dollar amount** for that line.
2. Column order was fixed as `Item | GST% | Price | Qty | Unit | Amount`, not the requested
   `Item | Price | Qty | GST | Amount`.

## Why this isn't a simple template edit

`pdf_brightcs.modules.php` only overrides `_tableau()` (header), `_tableau_tot()` (totals),
`_pagehead()`, `_pagefoot()`. The line-item **content** is drawn inside Dolibarr core's
`pdf_crabe::write_file()` — a single ~780-line method (page breaks, QR codes, incoterms,
notes, multi-page description handling, hooks — all interleaved with the column-drawing code).
There is no smaller override point for "just the line row".

Two options were considered:

- **Copy and modify `write_file()`** (~780 lines duplicated into `pdf_brightcs.modules.php`).
  Most literal/readable result, but duplicates a large chunk of core logic — any future
  Dolibarr bugfix or security patch to that method silently stops applying to our invoices.
- **Hook the existing extension points** (chosen). Dolibarr's `pdf_getlinevatrate()`,
  `pdf_getlineupexcltax()`, and `pdf_getlineqty()` (in `htdocs/core/lib/pdf.lib.php`) each
  call `$hookmanager->executeHooks(...)` before falling back to their default text — this is
  Dolibarr's sanctioned way to override PDF line content without touching core files.

## Decision

New custom module `custom/modules/invoicelines/` (enable at Setup > Modules > "Invoice Line
Columns") with hook class `ActionsInvoicelines` (`class/actions_invoicelines.class.php`).

Because the three hookable functions are drawn by core in a fixed physical order
(GST%-slot, then Price-slot, then Qty-slot — each a fixed x-position), and the desired visual
order is Price, Qty, GST, the hook performs a **3-way content swap**: each hook method prints
the *other* column's value, not its own:

| Hook (core meaning)     | What it prints instead |
|---|---|
| `pdf_getlinevatrate()`   | Price (ex GST) |
| `pdf_getlineupexcltax()` | Qty |
| `pdf_getlineqty()`       | GST dollar amount (`$line->total_tva`) |

No page positions change — only *what* gets drawn in each existing slot. The matching header
labels/order live in `pdf_brightcs.modules.php`'s `_tableau()` override; the two files must be
read together (each cross-references the other in comments).

All three hook methods check `$object->model_pdf` and no-op (`return 0`, letting core run
normally) unless the invoice is using the `brightcs` or `southside` template — so this cannot
affect any other Dolibarr invoice model.

The "UNIT" column (per-product unit of measure) was dropped from the header since
`PRODUCT_USE_UNITS` isn't enabled for this business and it wasn't part of the requested layout;
the freed width was merged into the GST column's header for visual balance.

## Upgrade risk

Low. No core files (`htdocs/core/...`) were modified. If a future Dolibarr version renames or
removes `pdf_getlinevatrate()`/`pdf_getlineupexcltax()`/`pdf_getlineqty()` or changes their hook
signature, the BCS/SSS invoice line columns would silently revert to core's default content —
worth a quick visual check after any Dolibarr core upgrade.

## Cross-reference

`custom/modules/invoicelines/class/actions_invoicelines.class.php` — the hook implementation,
with the same explanation in its docblock.
