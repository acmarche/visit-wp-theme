#!/bin/bash
#
# wp-harden-all.sh
# Runs wp-harden.sh across a fixed list of site directories under /var/www/.
#
# Usage:
#   sudo ./wp-harden-all.sh
#
# Requires wp-harden.sh to be in the same directory (or edit HARDEN_SCRIPT below).
# Skips any directory that doesn't look like a WordPress install (no wp-config.php
# and no wp-includes/) so it won't error out on non-WP sites in the same list.

set -uo pipefail

BASE_DIR="/var/www"
HARDEN_SCRIPT="$(dirname "$0")/wp-harden.sh"
WEB_USER="www-data"
WEB_GROUP="www-data"
SUMMARY_LOG="/var/log/wp-harden-all-$(date +%Y%m%d-%H%M%S).log"

# Site subdirectories to process, relative to $BASE_DIR
SITES=(
    "ale"
    "artistes/debailleul"
    "artistes"
    "associations/balouches"
    "atelierscp"
    "cercle-historique"
    "cyclofamenne"
    "dentelle"
    "ecolescommunales"
    "fonddesvaulx"
    "harmonie"
    "jardin"
    "lasource"
    "lemousqueton"
    "marche1900"
    "mda"
    "placeauxfoires"
    "sallescommunales"
    "terreferme"
    "vieillecense"
    "cinemarche"
    "jujutsu-traditionnel"
)

if [[ $EUID -ne 0 ]]; then
    echo "Please run this script as root (sudo)."
    exit 1
fi

if [[ ! -x "$HARDEN_SCRIPT" ]]; then
    echo "Error: $HARDEN_SCRIPT not found or not executable."
    echo "Put wp-harden.sh in the same folder as this script and chmod +x it."
    exit 1
fi

echo "===================================================================" | tee -a "$SUMMARY_LOG"
echo "  WordPress bulk hardening run - $(date)" | tee -a "$SUMMARY_LOG"
echo "===================================================================" | tee -a "$SUMMARY_LOG"

PROCESSED=()
SKIPPED=()
FAILED=()

for SITE in "${SITES[@]}"; do
    FULL_PATH="$BASE_DIR/$SITE"

    echo "" | tee -a "$SUMMARY_LOG"
    echo "-------------------------------------------------------------------" | tee -a "$SUMMARY_LOG"
    echo "Site: $FULL_PATH" | tee -a "$SUMMARY_LOG"
    echo "-------------------------------------------------------------------" | tee -a "$SUMMARY_LOG"

    if [[ ! -d "$FULL_PATH" ]]; then
        echo "  -> SKIPPED: directory does not exist." | tee -a "$SUMMARY_LOG"
        SKIPPED+=("$SITE (missing)")
        continue
    fi

    # Try to auto-detect the actual WordPress root within the site folder,
    # in case WP lives in a subfolder like html/ or public_html/ or htdocs/
    WP_ROOT=""
    if [[ -f "$FULL_PATH/wp-config.php" ]]; then
        WP_ROOT="$FULL_PATH"
    else
        CANDIDATE=$(find "$FULL_PATH" -maxdepth 3 -type f -name "wp-config.php" 2>/dev/null | head -n 1)
        if [[ -n "$CANDIDATE" ]]; then
            WP_ROOT=$(dirname "$CANDIDATE")
        fi
    fi

    if [[ -z "$WP_ROOT" ]]; then
        echo "  -> SKIPPED: no wp-config.php found (not a WordPress site, or nested deeper than 3 levels)." | tee -a "$SUMMARY_LOG"
        SKIPPED+=("$SITE (no wp-config.php)")
        continue
    fi

    echo "  -> WordPress root detected at: $WP_ROOT" | tee -a "$SUMMARY_LOG"
    echo "  -> Running wp-harden.sh ..." | tee -a "$SUMMARY_LOG"

    if "$HARDEN_SCRIPT" "$WP_ROOT" "$WEB_USER" "$WEB_GROUP" >> "$SUMMARY_LOG" 2>&1; then
        echo "  -> DONE." | tee -a "$SUMMARY_LOG"
        PROCESSED+=("$SITE")
    else
        echo "  -> FAILED - check $SUMMARY_LOG for details." | tee -a "$SUMMARY_LOG"
        FAILED+=("$SITE")
    fi
done

echo "" | tee -a "$SUMMARY_LOG"
echo "===================================================================" | tee -a "$SUMMARY_LOG"
echo "  Summary" | tee -a "$SUMMARY_LOG"
echo "===================================================================" | tee -a "$SUMMARY_LOG"
echo "Processed (${#PROCESSED[@]}): ${PROCESSED[*]:-none}" | tee -a "$SUMMARY_LOG"
echo "Skipped   (${#SKIPPED[@]}): ${SKIPPED[*]:-none}" | tee -a "$SUMMARY_LOG"
echo "Failed    (${#FAILED[@]}): ${FAILED[*]:-none}" | tee -a "$SUMMARY_LOG"
echo "" | tee -a "$SUMMARY_LOG"
echo "Full log: $SUMMARY_LOG"
echo "Individual per-site logs are inside /var/log/wp-harden-*.log as usual."
