# Discounts

How to apply discounts to invoices in Dolibarr.

---

## Line item discounts

A discount percentage can be applied to any individual line item when creating or editing an invoice.

### Entering a line discount

1. Open or create an invoice.
2. Add or edit a line — the **Discount (%)** field is on the line entry form.
3. Enter the percentage (e.g. `10` for 10%).
4. The line total is recalculated automatically: `Price × Qty × (1 − discount/100)`.

### On the PDF

Line discounts are rare so there is no dedicated discount column on the invoice template. Instead, when a line has a discount, it is appended to the description automatically:

```
ESG30026 - Baywest Jumbo Rolls 2 Ply Recycled Paper
Discount: 10%
```

The AMOUNT (ex GST) column already reflects the discounted price — no separate column is needed.

---

## Invoice-level (global) discounts

Dolibarr handles invoice-level discounts as **Absolute Discounts** — a fixed dollar amount credit assigned to a customer, then applied to an invoice.

### Step 1 — Create the discount on the customer

1. Open the customer card (Third Parties → customer name).
2. Go to the **Discounts** tab.
3. Click **Add an absolute discount**.
4. Enter the amount (e.g. `$25.00`) and a reason.
5. Save — the credit sits on the customer's account until used.

### Step 2 — Apply to an invoice

1. Open the invoice.
2. In the **Discounts** section (below the line items), the available customer discount appears.
3. Click **Use** next to the discount.
4. The amount is deducted from the invoice total and shown in the totals block.

### On the PDF

The deducted amount appears automatically in the totals block alongside the GST lines:

```
Total (excl. GST)    $500.00
Total GST 10%         $47.50
Discount             -$25.00
Total (inc. GST)     $522.50
```

---

## Percentage discount on the whole invoice

Dolibarr does not have a native "apply X% to the whole invoice" field. Options:

| Approach | When to use |
|---|---|
| Apply the % on each line item individually | Small number of lines |
| Calculate the dollar amount and use an absolute discount | Large invoices or recurring discount amounts |

---

## Customer-level default discounts

A default discount percentage can be set on the customer card so it pre-fills on every new invoice line for that customer:

1. Open the customer card.
2. **Sales** tab → **Discount** field.
3. Enter the percentage.

This pre-populates the discount field on each line — it can still be overridden per line.
