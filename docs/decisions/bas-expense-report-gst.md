# BAS Report: Including Expense Report (Staff Reimbursement) GST

## Problem (found 2026-07-10)

Michael asked whether GST on staff/owner expense-report reimbursements (see
[custom/help/expense-reports.php](../../custom/help/expense-reports.php)) gets picked up by the
custom `bas` module's quarterly GST report. Investigation found the report's SQL never
referenced expense reports at all — they're stored in a separate table
(`llx_payment_expensereport`) from customer/supplier invoice payments (`llx_paiement` /
`llx_paiementfourn`), which is all `custom/modules/bas/report.php` originally queried.

## Important nuance: the real 1A/1B number was not actually broken

`custom/modules/bas/report.php` calculates two *different* things that both look like "1B":

1. **The real 1B** (`$gst_1b`, drives Net GST / field 9 — what you actually enter on the ATO
   portal) — calculated purely from the accounting journal: `journal_sum()` sums debits to
   whatever account is configured as `BAS_ACCOUNT_GST_ITC`, regardless of which Dolibarr module
   posted them.
2. **`inv_1b`** — an informational figure computed directly from invoice/payment records, used
   only to show a reconciliation warning if it disagrees with the real 1B (`$gst_1b`).

Dolibarr's own `expensereportsjournal.php` and `purchasesjournal.php` (supplier invoices) both
resolve the GST account to debit through the *same* mechanism — `getTaxesFromId()` →
`llx_c_tva.accountancy_code_buy`, falling back to the global `ACCOUNTING_VAT_BUY_ACCOUNT` — so
once an expense report is approved and transferred to accounting (Accountancy > Transfer >
Expense Reports Ventilation > Expense reports journal), its GST lands in the *same* GL account
as supplier invoice GST. In this install, `BAS_ACCOUNT_GST_ITC` and `ACCOUNTING_VAT_BUY_ACCOUNT`
are both `2200` — confirmed matching. **So the real 1B was already correct** for any quarter
where expense reports were actually transferred to accounting; nothing had been transferred yet
at the time of this fix (zero `doc_type='expense_report'` rows existed in
`llx_accounting_bookkeeping`), so nothing had actually been missed in practice.

## What was actually missing

The *informational* figures — `G11` (purchase volume, not required for Simpler BAS but shown
in the report) and `inv_1b` (the reconciliation check against the real journal-based 1B) — only
queried supplier invoice payments, not expense report reimbursements. Practical effect: once
staff started claiming GST-bearing expenses, the reconciliation check would have thrown a false
"mismatch" warning (real 1B correct, informational figure understated), and the working CSV
export (audit trail of every transaction making up 1B) wouldn't have listed those transactions.

## Fix

Added expense-report queries (cash basis: `llx_payment_expensereport` join `llx_expensereport`,
prorated by payment same as supplier payments; accrual basis: `llx_expensereport` by
`date_valid`) to:
- The `G11`/`inv_1b` summary calculation (both cash and accrual branches).
- The cash-basis working CSV's 1B section, as its own sub-listing.

**Deliberately did not touch** `$gst_1b` / `journal_sum()` / the accrual-basis working CSV
(which already reads the journal) — since those already correctly include expense-report GST
once transferred, and duplicating that logic would double-count it.

## Verified

Inserted a test expense report ($110 total, $10 GST, paid, not yet transferred to accounting),
confirmed: G11 increased by $110, `inv_1b` increased by $10, the reconciliation check correctly
flagged the $10 difference (real journal 1B still $0, matching reality since it wasn't
transferred), and the working CSV listed the transaction under a new "GST on expense report
reimbursements" section. Test data removed after verification.

## Operational takeaway for go-live checklist

For expense-report GST to reach the *real* BAS number, the Accountancy > Transfer > Expense
Reports Ventilation + Expense reports journal + Bank journal steps must actually be run each
quarter — same discipline already required for supplier invoices. See
[custom/help/expense-reports.php](../../custom/help/expense-reports.php) section 4.
