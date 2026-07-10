# SSS Automatic Discount Trigger: Untracked Core File, Extended to Quotes

## Problem (found 2026-07-10)

Michael reported that a South Side Supplies (SSS) quote for a discount-eligible customer
(tagged `is_sss_customer` in the customer's extra fields) showed no discount lines or discount
breakdown, even though the same customer's invoices correctly show the "Delivery Zone / Large
Order / Member" discount rows built into `custom/core/modules/propale/doc/pdf_southside.modules.php`.

Investigation found the discount lines (`DISC-ZONE`, `DISC-LARGE`, `DISC-MEMBER`) are not part of
the PDF template at all — they're real product lines added to the invoice/quote itself by a
Dolibarr **trigger**, `InterfaceSSSDiscounts`
(`htdocs/core/triggers/interface_99_all_SSSDiscounts.class.php`), which recalculates them
whenever a line is added, edited, or deleted. That trigger only ever listened for invoice line
events (`LINEBILL_INSERT`/`LINEBILL_MODIFY`/`LINEBILL_DELETE`) — quotes fire different event names
(`LINEPROPAL_INSERT`/`LINEPROPAL_MODIFY`/`LINEPROPAL_DELETE`) that it simply didn't handle. So no
SSS quote has ever had these lines auto-calculated; the PDF template's discount-rendering code
(added earlier, mirroring the invoice) was effectively dead — nothing ever populated the data it
was built to display.

## A second, unrelated problem found along the way: this file was never version-controlled

`htdocs/` is entirely excluded from git (`.gitignore`), on the basis that it's third-party
Dolibarr core code, not ours. But `InterfaceSSSDiscounts` is a **custom** business-logic file that
happens to live under `htdocs/core/triggers/` — Dolibarr's convention is that any
`interface_*.class.php` file dropped into `core/triggers/` is auto-loaded for every module/action,
no module registration required, which is presumably why it was placed there directly rather than
under `custom/`. Practical effect: this trigger — the *only* copy of SSS's discount-calculation
business logic — had no git history and would be silently lost if `htdocs/` were ever wiped or the
site reinstalled from a fresh Dolibarr download.

## Fix

1. **Extended the trigger** (`runTrigger()`) to also handle `LINEPROPAL_*` actions, alongside the
   existing `LINEBILL_*` handling. Refactored the invoice-only `applyDiscounts()` method into
   `applyDiscountsInvoice()` and a new `applyDiscountsPropal()`, since `Facture::addline()` and
   `Propal::addline()` have different parameter orders/counts, and line deletion is
   `Facture::deleteline()` (lowercase) vs `Propal::deleteLine()` (capital L) — easy to get wrong,
   documented inline in the trigger.
2. **Moved the file under version control**: source of truth is now
   `custom/core/triggers/interface_99_all_SSSDiscounts.class.php`. `scripts/deploy.ps1` and
   `scripts/deploy.sh` gained a new "Core triggers" step that copies `custom/core/triggers/*.php`
   → `htdocs/core/triggers/`, the same pattern already used for the PDF template overrides.

Quotes only get discount lines recalculated while still in **Draft** status — `Propal::deleteLine()`
and `Propal::addline()` both require draft status internally (same rule as editing any other quote
line), so once a quote is validated, discount lines are frozen along with everything else. This
matches how invoices already behaved and needed no special handling.

## Verified

On dev: edited an existing line on a Draft SSS quote for a customer tagged `is_sss_customer`
(not a member, order under the large-order threshold). Confirmed in the database that
`DISC-ZONE`/`DISC-LARGE`/`DISC-MEMBER` lines were created with the correct amounts (zone discount
applied, large-order and member discounts correctly zeroed with their "why not" reasons), and that
the regenerated SSS quote PDF rendered the discount breakdown and adjusted totals correctly.

## Upgrade risk

Low for the trigger logic itself — `interface_99_all_*` naming and the `LINEBILL_*`/`LINEPROPAL_*`
trigger action names are stable, documented Dolibarr extension points. The real risk this fix
addresses was operational (an untracked file with no backup), not code-compatibility. Going
forward: any edit to this trigger must be made in `custom/core/triggers/` and deployed via
`scripts/deploy.ps1`/`.sh`, not edited directly in `htdocs/` — a direct edit there would work
until the next deploy, then be silently overwritten by the (older) tracked copy.

## Cross-reference

`custom/core/triggers/interface_99_all_SSSDiscounts.class.php` — the trigger itself, same
explanation in its docblock. `custom/core/modules/propale/doc/pdf_southside.modules.php` — the
quote PDF template that renders the `DISC-*` lines this trigger creates (see
[invoice-line-column-hook.md](invoice-line-column-hook.md) for the sibling invoice-side pattern).
