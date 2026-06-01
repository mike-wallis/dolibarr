# Inventory Decisions

## Stock Valuation Method: FIFO vs AVCO

### Finding (confirmed 2026-06-01)

Dolibarr 23.0.3 does **not** support FIFO as a configurable inventory valuation method.

Dolibarr records the entry price on every stock movement (`llx_stock_mouvement.price`) but all
inventory valuations in reports use **AVCO (average weighted cost)**. There is no setting to switch
this to FIFO.

Reckon currently uses FIFO. This is a difference between the two systems.

### Impact assessment

| Area | Impact |
|---|---|
| BAS / GST reporting | None — GST is based on invoices, not stock valuation |
| Stock value on balance sheet | Small difference when purchase prices vary between batches |
| COGS (cost of goods sold) | Small difference — AVCO smooths price fluctuations |
| Day-to-day operations | No impact |

For cleaning and washroom products with relatively stable supplier prices, the practical
difference between FIFO and AVCO is expected to be small.

### Decision

Proceed with evaluation using AVCO. Document the difference for accountant review before
any live migration. Reconcile opening stock values at migration time.

### Accountant review required

⚠ Before going live: confirm with accountant whether the switch from FIFO to AVCO
requires a one-off stock revaluation journal entry and whether this has any tax implications.
