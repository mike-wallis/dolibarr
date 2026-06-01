# Dolibarr GST Configuration (Australia)

## GST Rates

Australia is pre-configured in Dolibarr's database with the correct rates. Once your company country is set to Australia, these are available automatically — no manual rate setup needed:

- 0% (GST-free)
- 10% (standard GST)

## Cash Basis GST — Critical Setting

The VAT report has three modes controlled by `TAX_MODE` in Admin > Taxes:

| Mode | Behaviour |
|---|---|
| 0 (default) | Accrual — GST triggered on invoice date |
| 1 | Debit option for services only |
| **2** | **Payment date — GST triggered when payment received/made** |

For Australian cash basis GST, set Mode 2, plus set both `TAX_MODE_SELL_PRODUCT` and `TAX_MODE_SELL_SERVICE` to `payment`.

Path: Admin > Taxes > Tax module setup

## What the VAT Report Gives You for BAS

The built-in quarterly VAT report (`Home > Billing and Taxes > VAT Reports`) produces:

| Report output | Maps to BAS label |
|---|---|
| GST collected (sales) | **1A** |
| GST credits (purchases) | **1B** |
| Balance (net payable) | **1A minus 1B** |
| Quarterly subtotals | Matches your BAS period |
| Detailed drill-down by invoice | Supports your workpapers |

## BAS Lodgment Gap

No Australian BAS module exists in Dolibarr — there is no pre-filled G1–G20 form. Quarterly process:

1. Run the quarterly VAT report in Dolibarr
2. Read off 1A (GST on sales) and 1B (GST credits)
3. Manually enter into BAS lodgment via myGovID / ATO business portal

## "VAT" vs "GST" Label

Dolibarr uses "VAT" throughout the UI — there is no built-in toggle to "GST". Options:
- Create a custom translation file to override labels
- Customise the PDF invoice template separately for customer-facing documents

## Setup Checklist

1. Setup > Company > Country = **Australia**
2. Admin > Modules > Enable **Tax/VAT** module
3. Admin > Taxes > Set tax mode to **Payment date** (cash basis)
4. Chart of accounts: map GST Collected and GST Credits accounts
5. Test: create a draft invoice and confirm 10% GST appears automatically
