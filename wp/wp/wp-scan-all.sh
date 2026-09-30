#!/bin/bash
#
# wp-scan-all.sh
# READ-ONLY malware/backdoor indicator scan across multiple WordPress sites.
# Makes NO changes (no ownership, no permissions, no deletions) - report only.
# Run this BEFORE wp-harden-all.sh so you can review findings first.
#
# Usage:
#   sudo ./wp-scan-all.sh
#
# Output: one combined report file, printed path at the end.

set -uo pipefail

BASE_DIR="/var/www"
REPORT="/root/wp-scan-report-$(date +%Y%m%d-%H%M%S).txt"

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
    echo "Please run this script as root (sudo) so it can read all files."
    exit 1
fi

{
echo "==================================================================="
echo "  WordPress malware indicator scan - $(date)"
echo "  READ-ONLY: no files were modified, moved, or deleted."
echo "==================================================================="
} > "$REPORT"

TOTAL_SUSPECT=0
TOTAL_UPLOAD_PHP=0

for SITE in "${SITES[@]}"; do
    FULL_PATH="$BASE_DIR/$SITE"

    {
    echo ""
    echo "-------------------------------------------------------------------"
    echo "Site: $FULL_PATH"
    echo "-------------------------------------------------------------------"
    } >> "$REPORT"

    if [[ ! -d "$FULL_PATH" ]]; then
        echo "SKIPPED: directory does not exist." >> "$REPORT"
        continue
    fi

    # 1. Suspicious PHP patterns (obfuscation / dangerous function combos)
    echo "" >> "$REPORT"
    echo "[1] Files with suspicious patterns (eval, base64_decode, gzinflate, goto chains, etc.):" >> "$REPORT"
    MATCHES=$(grep -rlE "eval\(|assert\(|base64_decode\(|gzinflate\(|str_rot13\(|shell_exec\(|passthru\(|system\(|goto [A-Za-z0-9_]+;|\\\$GLOBALS\[[\"'][a-z]{1,3}[\"']\]" \
        --include="*.php" "$FULL_PATH" 2>/dev/null)
    if [[ -n "$MATCHES" ]]; then
        echo "$MATCHES" >> "$REPORT"
        COUNT=$(echo "$MATCHES" | wc -l)
        TOTAL_SUSPECT=$((TOTAL_SUSPECT + COUNT))
    else
        echo "  (none found)" >> "$REPORT"
    fi

    # 2. PHP files inside uploads (should never happen)
    echo "" >> "$REPORT"
    echo "[2] PHP files inside wp-content/uploads (should never be there):" >> "$REPORT"
    if [[ -d "$FULL_PATH/wp-content/uploads" ]]; then
        UPLOAD_PHP=$(find "$FULL_PATH/wp-content/uploads" -iname "*.php" 2>/dev/null)
        if [[ -n "$UPLOAD_PHP" ]]; then
            echo "$UPLOAD_PHP" >> "$REPORT"
            COUNT=$(echo "$UPLOAD_PHP" | wc -l)
            TOTAL_UPLOAD_PHP=$((TOTAL_UPLOAD_PHP + COUNT))
        else
            echo "  (none found)" >> "$REPORT"
        fi
    else
        echo "  (no uploads directory found)" >> "$REPORT"
    fi

    # 3. Recently modified PHP files (last 14 days) - often flags fresh drops
    echo "" >> "$REPORT"
    echo "[3] PHP files modified in the last 14 days:" >> "$REPORT"
    RECENT=$(find "$FULL_PATH" -iname "*.php" -mtime -14 2>/dev/null)
    if [[ -n "$RECENT" ]]; then
        echo "$RECENT" >> "$REPORT"
    else
        echo "  (none found)" >> "$REPORT"
    fi

    # 4. Suspicious/hidden directories (.trash*, random hex names, etc.)
    echo "" >> "$REPORT"
    echo "[4] Hidden or suspicious directories (.trash*, dot-folders outside .git):" >> "$REPORT"
    HIDDEN=$(find "$FULL_PATH" -iname ".trash*" -o -iname ".ic*" -o -iname ".tmb*" 2>/dev/null)
    if [[ -n "$HIDDEN" ]]; then
        echo "$HIDDEN" >> "$REPORT"
    else
        echo "  (none found)" >> "$REPORT"
    fi

    # 5. Unexpected admin users (requires WP-CLI, read-only call)
    echo "" >> "$REPORT"
    echo "[5] Administrator accounts:" >> "$REPORT"
    if command -v wp >/dev/null 2>&1; then
        WP_ROOT=""
        if [[ -f "$FULL_PATH/wp-config.php" ]]; then
            WP_ROOT="$FULL_PATH"
        else
            CANDIDATE=$(find "$FULL_PATH" -maxdepth 3 -type f -name "wp-config.php" 2>/dev/null | head -n 1)
            [[ -n "$CANDIDATE" ]] && WP_ROOT=$(dirname "$CANDIDATE")
        fi
        if [[ -n "$WP_ROOT" ]]; then
            sudo -u www-data wp --path="$WP_ROOT" user list --role=administrator \
                --fields=ID,user_login,user_email,user_registered 2>&1 >> "$REPORT"
        else
            echo "  (no wp-config.php found, skipped)" >> "$REPORT"
        fi
    else
        echo "  (wp-cli not installed, skipped)" >> "$REPORT"
    fi
done

{
echo ""
echo "==================================================================="
echo "  SUMMARY"
echo "==================================================================="
echo "Total files matching suspicious patterns : $TOTAL_SUSPECT"
echo "Total PHP files found inside uploads/     : $TOTAL_UPLOAD_PHP"
echo ""
echo "This is a REPORT ONLY. Nothing was changed, moved, or deleted."
echo "Review each flagged file manually before deleting - some legitimate"
echo "plugins/themes use eval()/base64_decode() for non-malicious reasons"
echo "(license checks, minified code, etc.), so treat matches as leads,"
echo "not automatic confirmation."
echo ""
echo "Suggested next steps:"
echo "  1. Open each flagged file and check if you recognize it / it belongs"
echo "     to a known plugin/theme."
echo "  2. Cross-check unfamiliar admin users against your real admin list."
echo "  3. Once you're confident about what's malicious, remove it manually"
echo "     (or via the rebuild approach for badly infected sites)."
echo "  4. THEN run wp-harden-all.sh to fix permissions/ownership and apply"
echo "     hardening - doing this before cleanup risks 'baking in' correct"
echo "     permissions on files you still need to delete."
} >> "$REPORT"

echo "Scan complete."
echo "Combined report saved to: $REPORT"
