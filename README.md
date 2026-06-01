# South Side Supplies — Dolibarr Dev Environment

Local Dolibarr development, evaluation, and migration project for South Side Supplies.

## What this repo contains

- `docs/` — setup guides, workflow docs, migration mappings, decisions
- `scripts/` — Reckon CSV import scripts, API test scripts, DB backup/restore
- `imports/` — anonymised CSV samples and import templates (no real business data)
- `custom/` — custom Dolibarr modules, print templates, email templates

## What this repo does NOT contain

- Dolibarr core code (`htdocs/` is excluded via .gitignore)
- Real customer, supplier, or financial data
- Database credentials (stored in `.env`, excluded via .gitignore)

## Local setup

See `docs/setup/local-dev-setup.md`.

## Environment

- Windows 11 / WAMP
- PHP 8.3
- MySQL 9.x
- Dolibarr accessed at http://dolibarr.test/
