#!/bin/bash
#
# wp-hunt-acm.sh
# Targeted hunt for the "advanced-code-manager / auto_batch_backdoor.php" compromise
# (bsdidi toolkit). READ-ONLY: reports only, changes nothing.
#
# Unlike wp-scan-all.sh's generic pattern grep, this looks for the exact artifacts
# that auto_batch_backdoor.php is hardcoded to create:
#   1. Shells named from its two fixed filename lists (23 names)
#   2. sync.php dropped outside any legitimate location
#   3. The fake wp-compat-cache plugin + its .gz second stage
#   4. Sibling .htaccess files in directories that must never contain one
#   5. The installer/templates themselves (auto_batch_backdoor.php, file.php, ...)
#   6. active_plugins DB rows referencing the fake plugins
#
# Usage:
#   sudo ./wp-hunt-acm.sh
#
# Exit status is 0 even when findings exist - read the report.

set -uo pipefail

BASE_DIR="/var/www"
REPORT="/root/wp-hunt-acm-$(date +%Y%m%d-%H%M%S).txt"

# Keep in sync with wp-scan-all.sh / wp-harden-all.sh
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

# --- Indicators extracted verbatim from auto_batch_backdoor.php ---

# fileBackdoorNames() + loginBackdoorNames()
SHELL_NAMES=(
    # file upload backdoor
    "wp-schedule-events.php" "wp-task-runner.php" "class-wp-cron-legacy.php"
    "admin-en_US-opt.php" "theme-compat-en_US.php" "ms-langs-manager.php"
    "load-styles-vendor.php" "script-loader-map.php" "wp-editor-fluent.php"
    "class-wp-db-delta.php" "wp-object-cache-helper.php"
    "class-wp-Block-Patterns-Upgrader.php"
    # admin login backdoor
    "mysqli-cluster-config.php" "config-helper.php" "wp-vulnerability-check.php"
    "class-wp-http-extra.php" "wp-compat-fix.php" "wp-feed-manager.php"
    "network-options-init.php" "admin-ajax-cache.php" "user-profile-secure.php"
    "plugin-compat-report.php" "class-wp-Sitemaps-Styles.php"
)

# Installer + its templates
INSTALLER_NAMES=(
    "auto_batch_backdoor.php" "login_admin.php" "wp-compat-cache.gz"
    "cache-purge-trigger.php" "compat-env-check.php"
)

# backdoorRandomPaths() - the 29 drop directories
DROP_PATHS=(
    "wp-content/themes/expert-carpenter/inc/getting-started"
    "wp-content/themes/preschool-classes/page-template"
    "wp-content/themes/twentytwentyfive/patterns"
    "wp-content/themes/twentytwentyfour/assets/css"
    "wp-content/themes/twentytwentyfour/patterns"
    "wp-content/themes/twentytwentythree/patterns"
    "wp-content/themes/twentytwentyone"
    "wp-includes/blocks/button" "wp-includes/blocks/freeform"
    "wp-includes/blocks/gallery" "wp-includes/blocks/list"
    "wp-includes/blocks/list-item" "wp-includes/blocks/math"
    "wp-includes/blocks/missing" "wp-includes/blocks/more"
    "wp-includes/blocks/navigation" "wp-includes/blocks/nextpage"
    "wp-includes/blocks/paragraph" "wp-includes/blocks/pattern"
    "wp-includes/blocks/post-terms" "wp-includes/blocks/preformatted"
    "wp-includes/blocks/pullquote" "wp-includes/blocks/query"
    "wp-includes/blocks/social-link" "wp-includes/blocks/spacer"
    "wp-includes/blocks/table" "wp-includes/blocks/verse"
    "wp-includes/Text/Diff/Engine" "wp-includes/Text/Diff/Renderer"
)

# Known-bad plugin slugs (installed or self-destructed)
BAD_PLUGINS=("advanced-code-manager" "advanced-code-manager-1" "wp-compat-cache")

# md5 of the installer sample recovered 2026-07-22
KNOWN_MD5="b24ce2e92b612a0c8e3c0254f74cd24d"

if [[ $EUID -ne 0 ]]; then
    echo "Please run this script as root (sudo) so it can read all files."
    exit 1
fi

# Counters
HITS_SHELL=0; HITS_INSTALLER=0; HITS_PLUGIN=0; HITS_HTACCESS=0; HITS_SYNC=0; HITS_DB=0

say() { echo "$@" >> "$REPORT"; }

{
echo "==================================================================="
echo "  advanced-code-manager / bsdidi backdoor hunt - $(date)"
echo "  READ-ONLY: no files were modified, moved, or deleted."
echo "==================================================================="
} > "$REPORT"

for SITE in "${SITES[@]}"; do
    FULL_PATH="$BASE_DIR/$SITE"

    say ""
    say "-------------------------------------------------------------------"
    say "Site: $FULL_PATH"
    say "-------------------------------------------------------------------"

    if [[ ! -d "$FULL_PATH" ]]; then
        say "SKIPPED: directory does not exist."
        continue
    fi

    # Resolve the WordPress root (same logic as the other scripts)
    WP_ROOT=""
    if [[ -f "$FULL_PATH/wp-config.php" ]]; then
        WP_ROOT="$FULL_PATH"
    else
        CANDIDATE=$(find "$FULL_PATH" -maxdepth 3 -type f -name "wp-config.php" 2>/dev/null | head -n 1)
        [[ -n "$CANDIDATE" ]] && WP_ROOT=$(dirname "$CANDIDATE")
    fi
    if [[ -z "$WP_ROOT" ]]; then
        say "SKIPPED: no wp-config.php found (not a WordPress site)."
        continue
    fi
    say "WordPress root: $WP_ROOT"

    # ---- [1] Planted shells, by exact filename, anywhere under the site ----
    # Names are distinctive enough that a match anywhere is significant, so we
    # do not restrict to DROP_PATHS - toolkit variants may use other locations.
    say ""
    say "[1] Planted shell filenames (from the installer's fixed name lists):"
    FOUND=""
    for NAME in "${SHELL_NAMES[@]}"; do
        MATCH=$(find "$WP_ROOT" -type f -iname "$NAME" 2>/dev/null)
        [[ -n "$MATCH" ]] && FOUND+="$MATCH"$'\n'
    done
    if [[ -n "$FOUND" ]]; then
        while IFS= read -r f; do
            [[ -z "$f" ]] && continue
            say "  !! $f"
            say "     mtime=$(stat -c %y "$f" 2>/dev/null)  size=$(stat -c %s "$f" 2>/dev/null)  md5=$(md5sum "$f" 2>/dev/null | cut -d' ' -f1)"
            HITS_SHELL=$((HITS_SHELL + 1))
        done <<< "$FOUND"
    else
        say "  (none found)"
    fi

    # ---- [2] sync.php ("undying code") ----
    say ""
    say "[2] sync.php outside legitimate locations:"
    SYNC=$(find "$WP_ROOT" -type f -name "sync.php" 2>/dev/null)
    if [[ -n "$SYNC" ]]; then
        while IFS= read -r f; do
            say "  !! $f  (mtime=$(stat -c %y "$f" 2>/dev/null))"
            HITS_SYNC=$((HITS_SYNC + 1))
        done <<< "$SYNC"
        say "     NOTE: some legitimate plugins ship a sync.php - check the path/vendor."
    else
        say "  (none found)"
    fi

    # ---- [3] Installer and template files ----
    say ""
    say "[3] Installer / template files:"
    FOUND=""
    for NAME in "${INSTALLER_NAMES[@]}"; do
        MATCH=$(find "$WP_ROOT" -type f -iname "$NAME" 2>/dev/null)
        [[ -n "$MATCH" ]] && FOUND+="$MATCH"$'\n'
    done
    if [[ -n "$FOUND" ]]; then
        while IFS= read -r f; do
            [[ -z "$f" ]] && continue
            MD5=$(md5sum "$f" 2>/dev/null | cut -d' ' -f1)
            FLAG=""
            [[ "$MD5" == "$KNOWN_MD5" ]] && FLAG="  <-- EXACT MATCH to known sample"
            say "  !! $f  md5=$MD5$FLAG"
            HITS_INSTALLER=$((HITS_INSTALLER + 1))
        done <<< "$FOUND"
    else
        say "  (none found)"
    fi

    # ---- [4] Known-bad plugin directories ----
    say ""
    say "[4] Known-bad plugin directories:"
    ANY=0
    for SLUG in "${BAD_PLUGINS[@]}"; do
        D="$WP_ROOT/wp-content/plugins/$SLUG"
        if [[ -d "$D" ]]; then
            say "  !! $D"
            say "     contents: $(ls -A "$D" 2>/dev/null | tr '\n' ' ')"
            HITS_PLUGIN=$((HITS_PLUGIN + 1)); ANY=1
        fi
    done
    [[ $ANY -eq 0 ]] && say "  (none found)"

    # ---- [5] Stray .htaccess in directories that never legitimately have one ----
    # This is the highest-signal indicator: the installer drops a sibling
    # .htaccess next to every shell it writes.
    say ""
    say "[5] .htaccess in wp-includes/ or theme subdirs (never legitimate):"
    ANY=0
    # The wp-includes sweep and the DROP_PATHS loop overlap, so dedupe.
    STRAY=$( { find "$WP_ROOT/wp-includes" -name ".htaccess" 2>/dev/null
               for P in "${DROP_PATHS[@]}"; do
                   [[ -f "$WP_ROOT/$P/.htaccess" ]] && echo "$WP_ROOT/$P/.htaccess"
               done
             } | sort -u )
    if [[ -n "${STRAY// /}" ]]; then
        while IFS= read -r f; do
            [[ -z "$f" ]] && continue
            say "  !! $f"
            # The installer's .htaccess re-enables PHP execution
            if grep -qiE "RewriteEngine off|Require all granted|Options \+Indexes" "$f" 2>/dev/null; then
                say "     ^ contains PHP-re-enabling directives (installer signature)"
            fi
            HITS_HTACCESS=$((HITS_HTACCESS + 1)); ANY=1
        done <<< "$STRAY"
    fi
    [[ $ANY -eq 0 ]] && say "  (none found)"

    # ---- [6] Any PHP file at all inside the 29 known drop directories ----
    say ""
    say "[6] Unexpected .php files in the installer's 29 drop directories:"
    ANY=0
    for P in "${DROP_PATHS[@]}"; do
        D="$WP_ROOT/$P"
        [[ -d "$D" ]] || continue
        # wp-includes/blocks/*/ legitimately contains only block.json/*.php from core;
        # report everything and let the operator diff against core.
        case "$P" in
            wp-includes/*)
                PHPS=$(find "$D" -maxdepth 1 -type f -name "*.php" -mtime -90 2>/dev/null)
                ;;
            *)
                PHPS=$(find "$D" -maxdepth 1 -type f -name "*.php" -mtime -90 2>/dev/null)
                ;;
        esac
        if [[ -n "$PHPS" ]]; then
            while IFS= read -r f; do
                say "  ?  $f  (mtime=$(stat -c %y "$f" 2>/dev/null | cut -d. -f1))"
                ANY=1
            done <<< "$PHPS"
        fi
    done
    [[ $ANY -eq 0 ]] && say "  (none modified in last 90 days)"

    # ---- [7] active_plugins in the database ----
    say ""
    say "[7] active_plugins DB row (the fake plugin is activated via direct SQL):"
    if command -v wp >/dev/null 2>&1; then
        ACTIVE=$(sudo -u www-data wp --path="$WP_ROOT" option get active_plugins --format=json 2>&1)
        if [[ $? -eq 0 && -n "$ACTIVE" ]]; then
            for SLUG in "${BAD_PLUGINS[@]}"; do
                if grep -q "$SLUG" <<< "$ACTIVE"; then
                    say "  !! active_plugins references '$SLUG'"
                    HITS_DB=$((HITS_DB + 1))
                fi
            done
            grep -q "advanced-code-manager\|wp-compat-cache" <<< "$ACTIVE" || say "  (clean)"
        else
            say "  (wp-cli could not read options: $ACTIVE)"
        fi
    else
        say "  (wp-cli not installed, skipped)"
    fi
done

{
echo ""
echo "==================================================================="
echo "  SUMMARY"
echo "==================================================================="
echo "Planted shell filenames matched : $HITS_SHELL"
echo "sync.php instances              : $HITS_SYNC"
echo "Installer/template files        : $HITS_INSTALLER"
echo "Known-bad plugin directories    : $HITS_PLUGIN"
echo "Stray .htaccess files           : $HITS_HTACCESS"
echo "Infected active_plugins rows    : $HITS_DB"
echo ""
echo "READ-ONLY: nothing was changed, moved, or deleted."
echo ""
echo "Interpreting results:"
echo "  - Sections [1], [3], [4] have effectively no false-positive rate: those"
echo "    filenames come from hardcoded lists in the installer and do not exist"
echo "    in WordPress core or any legitimate plugin."
echo "  - Section [5] is the strongest structural signal - wp-includes/ has no"
echo "    legitimate reason to contain a .htaccess file."
echo "  - Section [6] is advisory: it lists ALL recent .php in the drop dirs,"
echo "    including genuine core files. Diff against a clean WordPress of the"
echo "    same version before acting."
echo "  - Section [2] can legitimately match vendored plugin code."
echo ""
echo "If anything in [1]-[5] hit:"
echo "  1. Do NOT just delete and move on. The installer runs on every visit,"
echo "     picking NEW random paths each time, so multiple rounds leave multiple"
echo "     sets of files. Re-run this script after cleanup."
echo "  2. Rotate DB credentials - the installer reads wp-config.php and opens"
echo "     its own mysqli connection."
echo "  3. Audit administrator accounts (one payload is a login bypass) and"
echo "     force a password reset for all users."
echo "  4. Rotate WordPress salts in wp-config.php to invalidate stolen cookies."
echo "  5. Check active_plugins even if the plugin directory is gone - the"
echo "     self-destruct removes files but a partial run may leave the DB row."
} >> "$REPORT"

echo "Hunt complete."
echo "  shells=$HITS_SHELL sync=$HITS_SYNC installer=$HITS_INSTALLER plugins=$HITS_PLUGIN htaccess=$HITS_HTACCESS db=$HITS_DB"
echo "Report saved to: $REPORT"
