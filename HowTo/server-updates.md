# Deploying changes to the server

## Why two steps?

The server has two separate directories:

| Directory | Purpose |
|---|---|
| `~/erp_dolibarr/repo/` | Git repo — your custom files (help pages, invoice templates, scripts) |
| `~/erp_dolibarr/public_html/` | Dolibarr htdocs — the full app the web server serves |

`git pull` only updates the repo copy. The deploy script copies your custom files from the repo into the correct locations inside `public_html/`. Dolibarr's core files (thousands of files not in git) stay untouched.

---

## Full update workflow

### 1. Edit and test locally (laptop)

Edit files in `c:\wamp64\www\dolibarr\custom\` then deploy to local WAMP:

```powershell
cd C:\wamp64\www\dolibarr
.\scripts\deploy.ps1
```

Test at `http://dolibarr.test`

### 2. Commit and push to GitHub

```powershell
git add custom/
git commit -m "describe what changed"
git push origin master
```

### 3. Pull and deploy on the server

SSH in:

```
SSH SouthSideSupplies
```

Then:

```bash
cd ~/erp_dolibarr/repo
git pull
HTDOCS_DIR=~/erp_dolibarr/public_html bash scripts/deploy.sh
```

---

## Quick reference — server paths

| What | Path |
|---|---|
| Dolibarr URL | `https://erp.southsidesupplies.com.au` |
| htdocs (web root) | `/home/vi6ie1gyagot/erp_dolibarr/public_html` |
| Data / documents dir | `/home/vi6ie1gyagot/erp_dolibarr/data` |
| Git repo | `/home/vi6ie1gyagot/erp_dolibarr/repo` |
| .env (TFN key) | `/home/vi6ie1gyagot/erp_dolibarr/.env` |
| Database | `vi6ie1gyagot_dolibarr` |
| DB user | `vi6ie1gyagot_dol_admin` |

---

## What the deploy script copies

| Source (repo) | Destination (htdocs) |
|---|---|
| `custom/help/*.php` | `custom/help/` |
| `custom/core/modules/facture/doc/*.php` | `core/modules/facture/doc/` |
| `custom/core/modules/supplier_order/doc/*.php` | `core/modules/supplier_order/doc/` |
| `custom/core/modules/propale/doc/*.php` | `core/modules/propale/doc/` |
| `custom/modules/brand/` | `custom/brand/` (Brand Router module) |

---

## If you need to re-run the full server setup

See `HowTo/deployment.md` for the initial installation steps.
