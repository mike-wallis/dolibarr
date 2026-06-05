# South Side Supplies Invoice

How the SSS invoice template works, how to configure discount rates, and how to set up customers.

---

## Overview

South Side Supplies (SSS) and Bright Cleaning Solutions (BCS) share one Dolibarr installation and one set of accounts. They have separate invoice templates with different branding and different business rules.

The SSS template (`southside`) adds an automatic discount system on top of the standard BCS layout:

- **Delivery Zone discount** — always applied to every SSS invoice
- **Large Order discount** — applied when the invoice subtotal reaches a threshold
- **Member discount** — applied when the customer is flagged as a Premium Member
- **Early Payment discount** — shown as an informational box (not deducted from Balance Due)

---

## PDF templates

| Template | File | Used for |
|---|---|---|
| `brightcs` | `htdocs/core/modules/facture/doc/pdf_brightcs.modules.php` | Bright Cleaning Solutions invoices |
| `southside` | `htdocs/core/modules/facture/doc/pdf_southside.modules.php` | South Side Supplies invoices |

`pdf_southside` extends `pdf_brightcs` — it inherits the full BCS layout and overrides only the brand colours, brand constants prefix, discount totals block, early payment box, and footer.

Select the template when generating a document from the invoice card (Linked files → Doc template dropdown → Generate).

---

## Discount constants

All rates and thresholds are stored in `llx_const` and editable via **Home → Setup → Other setup**.

| Constant | Default | Description |
|---|---|---|
| `SSS_DISC_ZONE` | 2.5 | Delivery zone discount % — always applied |
| `SSS_DISC_LARGE` | 5 | Large order discount % |
| `SSS_LARGE_ORDER_MIN` | 150 | Minimum subtotal ($) to trigger the large order discount |
| `SSS_DISC_MEMBER` | 10 | Premium member discount % |
| `SSS_DISC_EARLY` | 2.5 | Early payment discount % (informational only) |
| `SSS_EARLY_DAYS` | 7 | Days from invoice date to qualify for early payment |

To change a value: **Home → Setup → Other setup** → click the value field next to the constant name → edit → Save.

Changes take effect on the next invoice — re-adding a line to an existing draft will recalculate using the new rates.

---

## Brand constants

SSS brand values are also stored in `llx_const` and editable via **Home → Setup → Other setup**.

| Constant | Example value | Description |
|---|---|---|
| `SSS_NAME` | South Side Supplies Pty Ltd | Company name on invoice header |
| `SSS_ADDR1` | 70 Brisbane Corso | Address line 1 |
| `SSS_ADDR2` | Fairfield QLD 4103 | Address line 2 |
| `SSS_PHONE` | 0431 779 857 | Phone shown on invoice |
| `SSS_EMAIL` | southsidesupplies.yes@gmail.com | Email shown on invoice |
| `SSS_BSB` | 182-512 | BSB in footer bank details |
| `SSS_ACC` | 000974446429 | Account number in footer |
| `SSS_LOGO` | southside_logo.png | Logo filename (in `documents/mycompany/logos/`) |
| `SSS_TAGLINE` | Fast local delivery | Bold coloured text in footer |
| `SSS_OWNERSHIP` | Ownership of the goods... | Italic text in footer |

ABN is shared with BCS — comes from **Setup → Company/Organisation → SIREN/ABN**.

---

## Customer setup

Two extra fields on the customer (Third Party) card control SSS discounts:

| Field | Location | Effect |
|---|---|---|
| **SSS Customer** | Customer card → More section | Must be ticked for the trigger to add discount lines. Without this the invoice behaves like a standard BCS invoice. |
| **Premium Member** | Customer card → More section | Adds the Member discount (`SSS_DISC_MEMBER` %) to every invoice for this customer. |

### How to set up a new SSS customer

1. Open the customer card (Third Parties → customer name).
2. Click **Modify**.
3. Scroll to the **More** section and expand it.
4. Tick **SSS Customer**.
5. Tick **Premium Member** if they qualify.
6. Click **Save**.

---

## How the discount trigger works

A Dolibarr trigger (`htdocs/core/triggers/interface_99_all_SSSDiscounts.class.php`) fires automatically whenever a line is added, changed, or deleted on a draft invoice.

On each event it:
1. Checks whether the customer has **SSS Customer** ticked — if not, does nothing.
2. Removes any existing `DISC-` lines from the invoice.
3. Recalculates the subtotal from the remaining product lines.
4. Adds three discount lines (`DISC-ZONE`, `DISC-LARGE`, `DISC-MEMBER`) with the current constant values.
5. Calls `update_price()` to recalculate the invoice totals.

The three discount lines are hidden from the line items table on the PDF — they appear instead in the totals block on the right side.

### Discount logic

| Discount | Applies when |
|---|---|
| Delivery Zone | Always (every SSS invoice) |
| Large Order | Subtotal ≥ `SSS_LARGE_ORDER_MIN` |
| Member | Customer has **Premium Member** ticked |

When a discount does not apply, it still appears in the totals block with a strikethrough and `$0.00`.

---

## Invoice PDF layout

```
┌─────────────────────────────────────────────────────────────┐
│  [Logo]                              TAX INVOICE             │
│  70 Brisbane Corso                   South Side Supplies     │
│  Fairfield QLD 4103                  P: 0431 779 857         │
│                                      E: ...                  │
│                          ┌──────────┬──────────┬───────────┐ │
│                          │ ABN      │ DATE     │ INVOICE # │ │
│                          └──────────┴──────────┴───────────┘ │
│  ┌───────────────┐  ┌───────────────┐                        │
│  │ BILL TO       │  │ SHIP TO       │                        │
│  └───────────────┘  └───────────────┘                        │
│  ┌──────┬────────┬──────────┬─────┬──────┬─────┐            │
│  │P.O.# │ TERMS  │ DUE DATE │ REP │ SHIP │ VIA │            │
│  └──────┴────────┴──────────┴─────┴──────┴─────┘            │
├─────────────────────────────────────────────────────────────┤
│  ITEM CODE / DESCRIPTION  │GST%│PRICE│QTY│UNIT│AMOUNT       │
│  product lines...                                            │
├─────────────────────────────────────────────────────────────┤
│  Payment Terms: 30 days EOM   │  Subtotal          $xxx.xx  │
│  ┌──────────────────────┐     │  Delivery Zone x%  -$xx.xx  │
│  │ x% EARLY PAYMENT...  │     │  Large Order x%    ~~$0.00~~│
│  │ Pay by dd/mm/yyyy    │     │  Member Discount x%-$xx.xx  │
│  │ $xxx.xx (save $x.xx) │     │  Total (excl. GST) $xxx.xx  │
│  └──────────────────────┘     │  Total GST 10%     $xx.xx   │
│                               │  Total (inc. GST)  $xxx.xx  │
├─────────────────────────────────────────────────────────────┤
│  BANK ACCOUNT DETAILS  BSB: xxx-xxx  Acc. No: xxxxxxxxxxxx  │
│         Ownership of the goods does not pass...             │
│                    Fast local delivery              1/1      │
└─────────────────────────────────────────────────────────────┘
```

---

## Early payment

The early payment discount is **informational only** — it is displayed in a box on the invoice but is not deducted from the Balance Due. The customer can choose to pay the discounted amount by the pay-by date; no credit note or correction invoice is needed.

Pay-by date = invoice date + `SSS_EARLY_DAYS` days.
Early payment total = Total (inc. GST) × (1 − `SSS_DISC_EARLY` / 100).

---

## Adding or changing a discount rate

Example: change the member discount from 10% to 12%.

1. **Home → Setup → Other setup**
2. Find `SSS_DISC_MEMBER` — click the value field, change `10` to `12`, Save.
3. The next invoice created for a member customer will use 12%.
4. Existing validated invoices are unaffected. To update an existing draft, unvalidate it, then re-add or modify a line to trigger recalculation, then revalidate.

---

## File reference

| File | Purpose |
|---|---|
| `htdocs/core/modules/facture/doc/pdf_southside.modules.php` | SSS invoice PDF template |
| `htdocs/core/modules/facture/doc/pdf_brightcs.modules.php` | Base BCS template (also parent of southside) |
| `htdocs/core/triggers/interface_99_all_SSSDiscounts.class.php` | Automatic discount calculation trigger |
| `documents/mycompany/logos/southside_logo.png` | SSS logo file |
