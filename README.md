# Dolibarr Local Dev — South Side Supplies

**Status:** Local development environment for evaluation and testing  
**Business:** South Side Supplies (B2B cleaning/washroom supplier)  
**Purpose:** Evaluate Dolibarr as replacement for Reckon; test workflows; prepare Reckon→Dolibarr migration

---

## Quick Start

1. **Setup:** Follow `SETUP.md` for complete step-by-step instructions
2. **Database:** `scripts/backup-restore.sh` for backups
3. **Workflows:** See `docs/workflows/` for each business process
4. **Migration:** See `docs/migration/` for Reckon import plan

---

## What's In This Repo

- **`dolibarr-core/`** — Dolibarr source (Git submodule, pinned version)
- **`custom/`** — South Side Supplies customizations (hooks, templates, modules)
- **`scripts/`** — Utilities: Reckon CSV import, API examples, backups
- **`docs/`** — Setup guides, workflows, migration plan, decisions
- **`imports/`** — CSV templates and anonymized test samples (NO real business data)

---

## Key Decision

- **Stack:** WAMP (PHP 8.3.14, MariaDB) on Windows, vs Docker
- **Approach:** Local dev only; plans for live deployment later
- **Git Strategy:** Custom code tracked; Dolibarr core via submodule; real data excluded
- **Data:** Separate production DB (dev uses test samples only)
- **Integration:** Dolibarr API as source of truth for website sync

---

## Clarifications Made

✓ GitHub: Private repo  
✓ Dolibarr version: Git submodule (pinned)  
✓ Custom dev: Hooks/custom fields (start simple)  
✓ Email: `southsidesupplies.yes@gmail.com`  
✓ Website: Dolibarr API = source of truth  
✓ Accountant: Will review CoA, opening balances, GST setup  

---

## Phases

| Phase | What | Status |
|-------|------|--------|
| 1 | Folder structure + Git init | ✓ Complete |
| 2 | Download Dolibarr + add submodule | ⏳ Next |
| 3 | Configure WAMP vhost + hosts | ⏳ Pending |
| 4 | Create MariaDB database + user | ⏳ Pending |
| 5 | Run Dolibarr install wizard | ⏳ Pending |
| 6 | Create backup/restore script | ⏳ Pending |

---

## Next Steps

1. Run Phase 2: Download Dolibarr and add as Git submodule
2. Configure WAMP vhost (Phase 3)
3. Create database (Phase 4)
4. Run install wizard (Phase 5)

---

## Important Notes

- **No Real Business Data:** Dev environment uses only anonymized test samples
- **Accountant Review Required:** Chart of accounts, opening balances, GST setup need approval before live
- **API Integration:** Website will sync with Dolibarr via API (document in `docs/architecture/api-integration.md`)
- **Backup Strategy:** Use `scripts/backup-restore.sh` regularly

---

## Timeline

- **2–3 weeks:** Phases 1–5 (dev ready, workflows tested)
- **4–6 weeks:** Testing, import validation, decision on Dolibarr vs ERPNext

---

## Resources

- **Dolibarr Docs:** https://wiki.dolibarr.org/
- **Dolibarr API:** https://wiki.dolibarr.org/index.php/API_index
- **This Repo:** All documentation in `docs/`
