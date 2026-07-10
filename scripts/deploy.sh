#!/usr/bin/env bash
# deploy.sh — copy custom source files into the Dolibarr htdocs installation
#
# Usage (server):
#   cd /home/vi6ie1gyagot/dolibarr_repo
#   HTDOCS_DIR=/home/vi6ie1gyagot/erp_brightcs/htdocs bash scripts/deploy.sh
#
# Usage (local Linux/Mac, htdocs beside repo):
#   bash scripts/deploy.sh

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Default: htdocs is beside the repo root (local dev layout).
# On the server, override with: HTDOCS_DIR=/path/to/htdocs
HTDOCS_DIR="${HTDOCS_DIR:-$REPO_DIR/htdocs}"

if [ ! -d "$HTDOCS_DIR" ]; then
    echo "ERROR: htdocs not found at: $HTDOCS_DIR"
    echo "Set HTDOCS_DIR env var to the correct path."
    exit 1
fi

deploy_file() {
    local src="$1"
    local dest="$2"
    mkdir -p "$(dirname "$dest")"
    cp -f "$src" "$dest"
    echo "  ${src#$REPO_DIR/}"
}

echo ""
echo "Deploying custom files to: $HTDOCS_DIR"
echo ""

# ── Help pages ────────────────────────────────────────────────────────────────
echo "Help pages:"
DEST_HELP="$HTDOCS_DIR/custom/help"
mkdir -p "$DEST_HELP"
for f in "$REPO_DIR"/custom/help/*.php; do
    deploy_file "$f" "$DEST_HELP/$(basename "$f")"
done

# ── Invoice PDF templates ─────────────────────────────────────────────────────
echo "Invoice templates:"
for f in "$REPO_DIR"/custom/core/modules/facture/doc/*.php; do
    deploy_file "$f" "$HTDOCS_DIR/core/modules/facture/doc/$(basename "$f")"
done

# ── Supplier order (PO) PDF templates ────────────────────────────────────────
echo "Purchase order templates:"
for f in "$REPO_DIR"/custom/core/modules/supplier_order/doc/*.php; do
    deploy_file "$f" "$HTDOCS_DIR/core/modules/supplier_order/doc/$(basename "$f")"
done

# ── Quote / Proposal PDF templates ───────────────────────────────────────────
echo "Quote templates:"
for f in "$REPO_DIR"/custom/core/modules/propale/doc/*.php; do
    deploy_file "$f" "$HTDOCS_DIR/core/modules/propale/doc/$(basename "$f")"
done

# ── Core triggers ────────────────────────────────────────────────────────────
echo "Core triggers:"
if [ -d "$REPO_DIR/custom/core/triggers" ]; then
    for f in "$REPO_DIR"/custom/core/triggers/*.php; do
        [ -e "$f" ] || continue
        deploy_file "$f" "$HTDOCS_DIR/core/triggers/$(basename "$f")"
    done
fi

# ── Custom modules ───────────────────────────────────────────────────────────
echo "Custom modules:"
for mod_dir in "$REPO_DIR"/custom/modules/*/; do
    [ -d "$mod_dir" ] || continue
    mod_name="$(basename "$mod_dir")"
    dest="$HTDOCS_DIR/custom/$mod_name"
    mkdir -p "$dest"
    cp -rf "$mod_dir/." "$dest/"
    echo "  modules/$mod_name/"
done

echo ""
echo "Done."
echo ""
