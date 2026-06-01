# Imports

This folder holds CSV samples and templates for importing data into Dolibarr.

## Rules

- `raw_samples/` — anonymised exports from Reckon. No real names, ABNs, addresses, or financial figures.
- `cleaned_samples/` — transformed versions ready to import via API scripts.
- `templates/` — blank CSV templates showing the expected column structure for each entity.

## Never commit

- Any CSV with real customer or supplier names
- Any CSV with real ABNs, phone numbers, or email addresses
- Any CSV with real prices, balances, or transaction history
