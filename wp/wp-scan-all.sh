#!/bin/bash
#
# wp-scan-all.sh
#
# Read-only malware indicator scan across every WordPress site in SITES.
# NOTHING is modified, moved, deleted or quarantined. Safe to run on a live
# site and safe to run repeatedly.
#
# Built around the indicators found in the September 2026 incident:
#   - eval(gzinflate(base64_decode(...))) appended to wp-blog-header.php
#     (SEO cloaking loader, beacons to api.pshvpn.com / tz-2jd.pages.dev)
#   - an unauthenticated POST webshell in wp-includes/update.php
#     (dispatches on $_POST['update_type_charts'])
#   - Adminer disguised as wp-admin/images/wordpress-logo-manager.php
#
# Usage:
#   sudo ./wp-scan-all.sh [options]
#
# Options:
#   -s, --site NAME     Only scan NAME. Repeatable. Defaults to all sites.
#   -o, --output FILE   Write the report here.
#   -q, --quiet         Only print the per-site verdict lines to the terminal.
#   -h, --help          Show this help.
#
# Exit status: 0 clean, 1 indicators found, 2 usage error.

set -uo pipefail

BASE_DIR="/var/www"
WEB_USER="www-data"
SITES=(
    "adl"
    "esquare"
    "esquare-wp"
    "marche-wp"
    "objectifemploi"
    "visit"
)

SELECTED=()
QUIET=0
REPORT=""

usage() { awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "$0"; exit "${1:-0}"; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        -s|--site)   [[ -n "${2:-}" ]] || { echo "--site needs a name" >&2; exit 2; }
                     SELECTED+=("$2"); shift ;;
        -o|--output) [[ -n "${2:-}" ]] || { echo "--output needs a path" >&2; exit 2; }
                     REPORT="$2"; shift ;;
        -q|--quiet)  QUIET=1 ;;
        -h|--help)   usage 0 ;;
        *)           echo "Unknown option: $1" >&2; usage 2 ;;
    esac
    shift
done

[[ ${#SELECTED[@]} -gt 0 ]] && SITES=("${SELECTED[@]}")

if [[ -z "$REPORT" ]]; then
    if [[ -w /var/log ]]; then
        REPORT="/var/log/wp-scan-all-$(date +%Y%m%d-%H%M%S).txt"
    else
        REPORT="./wp-scan-all-$(date +%Y%m%d-%H%M%S).txt"
    fi
fi

if [[ $EUID -ne 0 ]]; then
    echo "Warning: not running as root. Files you cannot read will be reported" >&2
    echo "as clean, which makes a CLEAN verdict meaningless. Re-run with sudo." >&2
    echo "" >&2
fi

# This script quotes every signature it hunts for, so it matches itself. So
# does the report. Both are excluded from all results.
SELF="$(readlink -f "$0")"
REPORT_ABS="$(readlink -f "$REPORT" 2>/dev/null || echo "$REPORT")"

# --------------------------------------------------------------------------
# Output helpers
# --------------------------------------------------------------------------

out() {
    printf '%s\n' "$*" >> "$REPORT"
    [[ $QUIET -eq 0 ]] && printf '%s\n' "$*"
    return 0
}

# Always reaches the terminal, even under --quiet.
verdict() {
    printf '%s\n' "$*" >> "$REPORT"
    printf '%s\n' "$*"
}

section() { out ""; out "  --- $* ---"; }

TOTAL_SITES=0
TOTAL_CLEAN=0
TOTAL_SUSPECT=0
TOTAL_SKIPPED=0
declare -a SUSPECT_SITES=()

# Drop this scanner and its own report out of any file list.
filter_self() { grep -vFx -e "$SELF" -e "$REPORT_ABS" || true; }

# hits <label> <regex> <dir...> - report every file matching regex.
# Returns 0 when something was found, so callers can count indicators.
hits() {
    local label="$1" pattern="$2"; shift 2
    local found
    found="$(grep -rlIE --include='*.php' --include='*.phtml' --include='*.inc' \
                 -- "$pattern" "$@" 2>/dev/null | filter_self)"
    [[ -n "${HITS_EXCLUDE:-}" ]] && found="$(printf '%s' "$found" | grep -vE "$HITS_EXCLUDE" || true)"
    [[ -z "$found" ]] && return 1
    out "  [!] $label"
    while IFS= read -r f; do
        [[ -n "$f" ]] && out "      $f"
    done <<< "$found"
    return 0
}

# --------------------------------------------------------------------------
# Header
# --------------------------------------------------------------------------

{
echo "==================================================================="
echo "  WordPress malware indicator scan - $(date)"
echo "  READ-ONLY: no files are modified, moved, or deleted."
echo "  Sites: ${SITES[*]}"
echo "==================================================================="
} > "$REPORT"
[[ $QUIET -eq 0 ]] && sed -n '1,5p' "$REPORT"

# --------------------------------------------------------------------------
# Per-site scan
# --------------------------------------------------------------------------

for SITE in "${SITES[@]}"; do
    WP_PATH="$BASE_DIR/$SITE"

    out ""
    out "==================================================================="
    out "  Site: $WP_PATH"
    out "==================================================================="

    if [[ ! -d "$WP_PATH" ]]; then
        verdict "  SKIPPED: directory does not exist."
        ((TOTAL_SKIPPED++)); continue
    fi
    if [[ ! -f "$WP_PATH/wp-load.php" ]]; then
        verdict "  SKIPPED: no wp-load.php, not a WordPress root."
        ((TOTAL_SKIPPED++)); continue
    fi

    ((TOTAL_SITES++))
    INDICATORS=0

    # ---------- A. Known indicators from this incident ----------
    # These are filesystem greps on purpose: they still work on a site whose
    # wp-config.php cannot bootstrap under WP-CLI.
    section "A. Known indicators (September 2026 incident)"

    hits "Cloaking loader: eval(gzinflate(base64_decode(...)))" \
        'eval[[:space:]]*\([[:space:]]*gzinflate[[:space:]]*\([[:space:]]*base64_decode' \
        "$WP_PATH" && ((INDICATORS++))

    hits "Webshell: \$_POST['update_type_charts'] dispatcher" \
        'update_type_charts' "$WP_PATH" && ((INDICATORS++))

    hits "Adminer database console hidden in the tree" \
        'Adminer - Compact database management|namespace[[:space:]]+Adminer' \
        "$WP_PATH" && ((INDICATORS++))

    hits "Known C2 / dead-drop hosts" \
        'api\.pshvpn\.com|tz-2jd\.pages\.dev|GuilerMonley' "$WP_PATH" && ((INDICATORS++))

    hits "XOR string-decoder stub (function _d_XXXX)" \
        'function[[:space:]]+_d_[A-Za-z0-9]{4,}[[:space:]]*\(' "$WP_PATH" && ((INDICATORS++))

    [[ $INDICATORS -eq 0 ]] && out "  ok - none of the known indicators are present."

    # ---------- B. Generic obfuscation in core ----------
    # Scoped to core only. wp-content legitimately contains base64_decode, so
    # the same patterns there would be mostly noise; it gets section C instead.
    section "B. Generic obfuscation in wp-admin / wp-includes / root"

    CORE_DIRS=("$WP_PATH/wp-admin" "$WP_PATH/wp-includes")
    CORE_B=0
    # Core ships these libraries and they legitimately call popen/proc_open.
    CORE_OK='/wp-includes/(Text/Diff|PHPMailer|ID3|SimplePie|Requests|sodium_compat)/'

    for pat_label in \
        'eval on encoded input|eval[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|pack)' \
        'assert on encoded input|assert[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|str_rot13)' \
        'create_function (removed in PHP 8, used by shells)|create_function[[:space:]]*\(' \
        'direct shell execution|(shell_exec|passthru|popen|proc_open)[[:space:]]*\(' \
        'request-driven code execution|(eval|system|exec)[[:space:]]*\([[:space:]]*\$_(GET|POST|REQUEST|COOKIE)' ; do
        lbl="${pat_label%%|*}"; pat="${pat_label#*|}"
        HITS_EXCLUDE="$CORE_OK"
        hits "$lbl" "$pat" "${CORE_DIRS[@]}" && { ((INDICATORS++)); CORE_B=1; }
    done
    # Root-level PHP files (wp-blog-header.php, wp-settings.php, index.php...)
    ROOT_PHP="$(find "$WP_PATH" -maxdepth 1 -name '*.php' 2>/dev/null)"
    if [[ -n "$ROOT_PHP" ]]; then
        # shellcheck disable=SC2086
        hits "obfuscation in a root-level PHP file" \
            'eval[[:space:]]*\(|gzinflate[[:space:]]*\(|create_function[[:space:]]*\(' \
            $ROOT_PHP && { ((INDICATORS++)); CORE_B=1; }
    unset HITS_EXCLUDE
    fi
    [[ $CORE_B -eq 0 ]] && out "  ok - no generic obfuscation in core."

    # ---------- C. Executable code where content belongs ----------
    section "C. PHP where only content should be"

    UP="$WP_PATH/wp-content/uploads"
    if [[ -d "$UP" ]]; then
        U="$(find "$UP" \( -name '*.php' -o -name '*.phtml' -o -name '*.php[0-9]' \
                        -o -name '*.phar' -o -name '.htaccess' \) 2>/dev/null | filter_self)"
        if [[ -n "$U" ]]; then
            out "  [!] executable/override files under uploads/"
            while IFS= read -r f; do [[ -n "$f" ]] && out "      $f"; done <<< "$U"
            ((INDICATORS++))
        else
            out "  ok - no PHP under uploads/."
        fi

        # A JPEG that opens with <?php is a shell wearing an image extension.
        D="$(grep -rlI --include='*.jpg' --include='*.jpeg' --include='*.png' \
                --include='*.gif' --include='*.svg' --include='*.ico' \
                -- '<?php' "$UP" 2>/dev/null | filter_self)"
        if [[ -n "$D" ]]; then
            out "  [!] image files containing PHP tags"
            while IFS= read -r f; do [[ -n "$f" ]] && out "      $f"; done <<< "$D"
            ((INDICATORS++))
        fi
    else
        out "  (no uploads directory)"
    fi

    # PHP in core asset directories. The listed names are genuine core files.
    A="$(find "$WP_PATH/wp-admin" "$WP_PATH/wp-includes" \
            \( -path '*/images/*' -o -path '*/css/*' \) \
            -name '*.php' 2>/dev/null \
        | grep -vE '/(registry|wp-tinymce)\.php$|\.asset\.php$' | filter_self)"
    if [[ -n "$A" ]]; then
        out "  [!] PHP inside core asset directories (images/css)"
        while IFS= read -r f; do [[ -n "$f" ]] && out "      $f"; done <<< "$A"
        ((INDICATORS++))
    else
        out "  ok - no stray PHP in core asset directories."
    fi

    # ---------- D. Core files touched after install ----------
    # Comparing against version.php flags most of wp-admin, because a core
    # update rewrites version.php too. Instead: find the day most core files
    # share (the day the tree was laid down) and flag what is newer.
    section "D. Core files newer than the rest of the tree"

    MTIMES="$(find "$WP_PATH/wp-admin" "$WP_PATH/wp-includes" -name '*.php' \
                  -printf '%TY-%Tm-%Td\n' 2>/dev/null)"
    TOTAL_CORE=$(printf '%s\n' "$MTIMES" | grep -c . || true)

    if [[ ${TOTAL_CORE:-0} -lt 50 ]]; then
        out "  (too few core files to establish a baseline)"
    else
        read -r MODE_N MODE_DAY <<< "$(printf '%s\n' "$MTIMES" | sort | uniq -c | sort -rn | head -1)"
        PCT=$(( MODE_N * 100 / TOTAL_CORE ))
        out "  Baseline: $PCT% of $TOTAL_CORE core PHP files share $MODE_DAY."

        if [[ $PCT -lt 50 ]]; then
            out "  Mtimes are too scattered for this check to mean anything"
            out "  (mixed partial updates). Rely on sections A, B and E."
        else
            NEWER="$(find "$WP_PATH/wp-admin" "$WP_PATH/wp-includes" -name '*.php' \
                        -newermt "$MODE_DAY 23:59:59" 2>/dev/null | filter_self)"
            NCOUNT=$(printf '%s\n' "$NEWER" | grep -c . || true)
            if [[ ${NCOUNT:-0} -gt 0 ]]; then
                out "  [!] $NCOUNT core PHP file(s) newer than $MODE_DAY:"
                printf '%s\n' "$NEWER" | head -30 | while IFS= read -r f; do
                    [[ -n "$f" ]] && out "      $f  ($(date -r "$f" '+%Y-%m-%d %H:%M'))"
                done
                [[ $NCOUNT -gt 30 ]] && out "      ... and $(( NCOUNT - 30 )) more"
                out "      A partial core update also produces this. Correlate with A/B/E."
                ((INDICATORS++))
            else
                out "  ok - no core PHP newer than the baseline day."
            fi
        fi
        if [[ "$MODE_DAY" == "$(date +%Y-%m-%d)" ]]; then
            out "  NOTE: the baseline day is today. If this tree was copied without"
            out "  'cp -p' / 'rsync -a', mtimes were reset and this section is void."
        fi
    fi

    # ---------- E. WP-CLI integrity ----------
    # Last, because it is the only part that needs a working bootstrap.
    section "E. Integrity checks (WP-CLI)"

    if ! command -v wp >/dev/null 2>&1; then
        out "  WP-CLI not found, skipping checksum verification."
    else
        WPC=(sudo -u "$WEB_USER" env WP_CLI_PHP_ARGS="-d error_reporting=E_ALL&~E_DEPRECATED"
             wp --path="$WP_PATH" --skip-plugins --skip-themes)
        out ""
        out "-- core version --"
        "${WPC[@]}" core version >> "$REPORT" 2>&1
        out "-- core verify-checksums --"
        if ! "${WPC[@]}" core verify-checksums >> "$REPORT" 2>&1; then
            out "  [!] core checksum verification FAILED (details above in report)"
            ((INDICATORS++))
        else
            out "  ok - core verifies against checksums."
        fi
        out "-- plugin verify-checksums --"
        if ! sudo -u "$WEB_USER" env WP_CLI_PHP_ARGS="-d error_reporting=E_ALL&~E_DEPRECATED" \
                wp --path="$WP_PATH" plugin verify-checksums --all >> "$REPORT" 2>&1; then
            out "  [!] plugin checksum verification reported problems"
            ((INDICATORS++))
        else
            out "  ok - plugins verify against checksums."
        fi
        out "-- administrator accounts --"
        "${WPC[@]}" user list --role=administrator \
            --fields=ID,user_login,user_email,user_registered >> "$REPORT" 2>&1 \
            || out "  (could not list users - wp-config.php may not bootstrap under the CLI)"
    fi

    # ---------- Verdict ----------
    out ""
    if [[ $INDICATORS -eq 0 ]]; then
        verdict "  VERDICT: $SITE - clean (no indicators)"
        ((TOTAL_CLEAN++))
    else
        verdict "  VERDICT: $SITE - SUSPECT ($INDICATORS indicator group(s)) <<<"
        SUSPECT_SITES+=("$SITE")
        ((TOTAL_SUSPECT++))
    fi
done

# --------------------------------------------------------------------------
# Summary
# --------------------------------------------------------------------------

verdict ""
verdict "==================================================================="
verdict "  Summary"
verdict "==================================================================="
verdict "  Scanned : $TOTAL_SITES"
verdict "  Clean   : $TOTAL_CLEAN"
verdict "  Suspect : $TOTAL_SUSPECT${SUSPECT_SITES[0]+  (${SUSPECT_SITES[*]})}"
verdict "  Skipped : $TOTAL_SKIPPED"
verdict ""
verdict "  Nothing was modified. Report: $REPORT"

[[ $TOTAL_SUSPECT -gt 0 ]] && exit 1
exit 0
