#!/bin/bash
#
# wp-harden-all.sh
#
# Applies a baseline hardening pass to every WordPress site listed in SITES:
#   1. ownership and file/directory permissions
#   2. blocking xmlrpc.php at the web server level
#   3. WP-CLI status checks and core/plugin/theme updates
#   4. wp-config.php hardening (DISALLOW_FILE_EDIT)
#
# THIS SCRIPT MODIFIES FILES. Run --dry-run first to see what it would do.
#
# Usage:
#   sudo ./wp-harden-all.sh [options]
#
# Options:
#   -n, --dry-run       Print every change without applying it.
#   -y, --yes           Skip the confirmation prompt (for cron / CI).
#   -s, --site NAME     Only process NAME. Repeatable. Defaults to all sites.
#       --no-chown      Skip step 1 entirely (ownership + permissions).
#       --no-update     Run the WP-CLI checks but do not install updates.
#   -h, --help          Show this help.
#
# Output: one combined log file, path printed at the end.

set -uo pipefail

BASE_DIR="/var/www"
WEB_USER="www-data"
WEB_GROUP="www-data"
LOG_FILE="/var/log/wp-harden-all-$(date +%Y%m%d-%H%M%S).log"
NGINX_SNIPPET="/etc/nginx/snippets/wp-xmlrpc-block.conf"

SITES=(
    "adl"
    "esquare"
    "esquare-wp"
    "marche-wp"
    "objectifemploi"
    "visit"
)

DRY_RUN=0
ASSUME_YES=0
DO_CHOWN=1
DO_UPDATE=1
SELECTED=()

usage() { awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "$0"; exit "${1:-0}"; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        -n|--dry-run)  DRY_RUN=1 ;;
        -y|--yes)      ASSUME_YES=1 ;;
        -s|--site)     [[ -n "${2:-}" ]] || { echo "--site needs a name" >&2; exit 2; }
                       SELECTED+=("$2"); shift ;;
        --no-chown)    DO_CHOWN=0 ;;
        --no-update)   DO_UPDATE=0 ;;
        -h|--help)     usage 0 ;;
        *)             echo "Unknown option: $1" >&2; usage 2 ;;
    esac
    shift
done

[[ ${#SELECTED[@]} -gt 0 ]] && SITES=("${SELECTED[@]}")

if [[ $EUID -ne 0 ]]; then
    echo "Please run this script as root (sudo) so it can change ownership on all sites." >&2
    exit 1
fi

# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------

log() { printf '%s\n' "$*" | tee -a "$LOG_FILE"; }

section() {
    {
        echo ""
        echo "  --- $* ---"
    } | tee -a "$LOG_FILE"
}

site_header() {
    {
        echo ""
        echo "==================================================================="
        echo "  Site: $*"
        echo "==================================================================="
    } | tee -a "$LOG_FILE"
}

# run <description> -- <command...>
# Executes the command, or just reports it when --dry-run is active.
run() {
    local desc="$1"; shift
    [[ "${1:-}" == "--" ]] && shift
    if [[ $DRY_RUN -eq 1 ]]; then
        log "  [dry-run] $desc"
        return 0
    fi
    if "$@" >>"$LOG_FILE" 2>&1; then
        log "  $desc"
    else
        log "  FAILED: $desc (see $LOG_FILE)"
        return 1
    fi
}

# The bundled phar emits screenfuls of E_DEPRECATED on newer PHP; they drown
# out the findings and say nothing about the site.
WP_CLI_PHP_ARGS="-d error_reporting=E_ALL&~E_DEPRECATED"

# wp_cli <args...> - run WP-CLI as the web user against the current $WP_PATH.
# A non-zero exit is counted so the summary cannot claim success for a site
# whose wp-config.php fatals under the CLI.
wp_cli() {
    sudo -u "$WEB_USER" env WP_CLI_PHP_ARGS="$WP_CLI_PHP_ARGS" \
        wp --path="$WP_PATH" --skip-plugins --skip-themes "$@" 2>&1 | tee -a "$LOG_FILE"
    local rc=${PIPESTATUS[0]}
    [[ $rc -ne 0 ]] && ((WP_FAILURES++))
    return "$rc"
}

# Same, but with plugins/themes loaded (required by update and list commands).
wp_cli_full() {
    sudo -u "$WEB_USER" env WP_CLI_PHP_ARGS="$WP_CLI_PHP_ARGS" \
        wp --path="$WP_PATH" "$@" 2>&1 | tee -a "$LOG_FILE"
    local rc=${PIPESTATUS[0]}
    [[ $rc -ne 0 ]] && ((WP_FAILURES++))
    return "$rc"
}

# --------------------------------------------------------------------------
# Confirmation
# --------------------------------------------------------------------------

{
echo "==================================================================="
echo "  WordPress hardening pass - $(date)"
if [[ $DRY_RUN -eq 1 ]]; then
    echo "  DRY RUN: nothing will be modified."
else
    echo "  WARNING: this run MODIFIES files, permissions and databases."
fi
echo "  Sites: ${SITES[*]}"
echo "  Owner: $WEB_USER:$WEB_GROUP   Updates: $([[ $DO_UPDATE -eq 1 ]] && echo yes || echo no)"
echo "==================================================================="
} | tee "$LOG_FILE"

if [[ $DRY_RUN -eq 0 && $ASSUME_YES -eq 0 ]]; then
    echo ""
    echo "This will chown -R to $WEB_USER, reset permissions, edit wp-config.php"
    echo "and run WordPress updates on the sites above."
    read -r -p "Continue? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

TOTAL_HARDENED=0
TOTAL_SKIPPED=0
TOTAL_FAILED=0

# --------------------------------------------------------------------------
# One-off: nginx snippet (shared by every vhost, so created outside the loop)
# --------------------------------------------------------------------------

if command -v nginx >/dev/null 2>&1; then
    section "nginx: xmlrpc.php block snippet"
    if [[ -f "$NGINX_SNIPPET" ]]; then
        log "  $NGINX_SNIPPET already exists, leaving it alone."
    elif [[ $DRY_RUN -eq 1 ]]; then
        log "  [dry-run] would create $NGINX_SNIPPET"
    else
        mkdir -p "$(dirname "$NGINX_SNIPPET")"
        cat > "$NGINX_SNIPPET" << 'EOF'
location = /xmlrpc.php {
    deny all;
    return 403;
}
EOF
        log "  Created $NGINX_SNIPPET"
    fi
    log "  Add 'include snippets/wp-xmlrpc-block.conf;' inside each site's server {} block, then:"
    log "    nginx -t && systemctl reload nginx"
fi

# --------------------------------------------------------------------------
# Per-site hardening
# --------------------------------------------------------------------------

for SITE in "${SITES[@]}"; do
    WP_PATH="$BASE_DIR/$SITE"
    site_header "$WP_PATH"

    if [[ ! -d "$WP_PATH" ]]; then
        log "SKIPPED: directory does not exist."
        ((TOTAL_SKIPPED++))
        continue
    fi

    if [[ ! -f "$WP_PATH/wp-load.php" ]]; then
        log "SKIPPED: no wp-load.php, this is not a WordPress root."
        ((TOTAL_SKIPPED++))
        continue
    fi

    SITE_FAILED=0
    WP_FAILURES=0

    # ---------- 1. Ownership & permissions ----------
    if [[ $DO_CHOWN -eq 1 ]]; then
        section "1. Ownership and permissions"

        run "Ownership set to $WEB_USER:$WEB_GROUP recursively." \
            -- chown -R "$WEB_USER":"$WEB_GROUP" "$WP_PATH" || SITE_FAILED=1

        # .git is pruned: rewriting its modes creates noise and breaks nothing useful.
        run "Directories set to 755." \
            -- find "$WP_PATH" -name .git -prune -o -type d -exec chmod 755 {} + || SITE_FAILED=1

        run "Files set to 644." \
            -- find "$WP_PATH" -name .git -prune -o -type f -exec chmod 644 {} + || SITE_FAILED=1

        if [[ -f "$WP_PATH/wp-config.php" ]]; then
            run "wp-config.php locked down to 640." \
                -- chmod 640 "$WP_PATH/wp-config.php" || SITE_FAILED=1
        fi

        # Backups from step 4 hold the same DB credentials, so they must not be
        # left at 644 by the sweep above.
        if compgen -G "$WP_PATH/wp-config.php.bak-*" >/dev/null; then
            run "Existing wp-config.php backups locked down to 600." \
                -- chmod 600 "$WP_PATH"/wp-config.php.bak-* || SITE_FAILED=1
        fi

        if [[ -d "$WP_PATH/bin" ]]; then
            run "Restored the execute bit on $SITE/bin/*." \
                -- chmod 755 -R "$WP_PATH/bin" || SITE_FAILED=1
        fi

        log "  Note: 644 clears the execute bit on any other scripts under $SITE."
    else
        section "1. Ownership and permissions (skipped: --no-chown)"
    fi

    # ---------- 2. Disable xmlrpc.php ----------
    section "2. Disabling xmlrpc.php"

    if [[ -f "$WP_PATH/.htaccess" ]] || command -v apache2 >/dev/null 2>&1; then
        HTACCESS="$WP_PATH/.htaccess"
        if grep -q "xmlrpc.php" "$HTACCESS" 2>/dev/null; then
            log "  xmlrpc.php rule already present in .htaccess, skipping."
        elif [[ $DRY_RUN -eq 1 ]]; then
            log "  [dry-run] would append an xmlrpc.php deny block to $HTACCESS"
        else
            cat >> "$HTACCESS" << 'EOF'

# Block xmlrpc.php (added by wp-harden-all.sh)
<Files xmlrpc.php>
Require all denied
</Files>
EOF
            chown "$WEB_USER":"$WEB_GROUP" "$HTACCESS"
            chmod 644 "$HTACCESS"
            log "  Added xmlrpc.php block rule to $HTACCESS"
        fi
    else
        log "  No .htaccess and no apache2 binary, nothing to do here."
    fi

    log "  Note: blocking at the webserver level stops access even if a plugin re-enables XML-RPC."

    # ---------- 3. WP-CLI checks / updates ----------
    section "3. WordPress core, plugin and theme status"

    if ! command -v wp >/dev/null 2>&1; then
        log "  WP-CLI not found. Install it with:"
        log "    curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
        log "    chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
    else
        log ""
        log "-- Current version --"
        wp_cli core version

        log ""
        log "-- Core integrity (checksums) --"
        wp_cli core verify-checksums

        log ""
        log "-- Plugins --"
        wp_cli_full plugin list --format=table

        log ""
        log "-- Themes --"
        wp_cli_full theme list --format=table

        if [[ $DO_UPDATE -eq 1 && $DRY_RUN -eq 0 ]]; then
            log ""
            log "-- Applying updates: core, plugins, themes --"
            wp_cli core update
            wp_cli core update-db
            wp_cli_full plugin update --all
            wp_cli_full theme update --all
        else
            log ""
            log "-- Updates skipped ($([[ $DRY_RUN -eq 1 ]] && echo dry-run || echo --no-update)); pending items: --"
            wp_cli_full core check-update
            wp_cli_full plugin list --update=available --format=table
            wp_cli_full theme list --update=available --format=table
        fi

        log ""
        log "-- Plugin checksums (where available) --"
        wp_cli_full plugin verify-checksums --all

        log ""
        log "-- Admin users (review for anything unrecognized) --"
        wp_cli user list --role=administrator --fields=ID,user_login,user_email,user_registered

        log ""
        log "-- Inactive plugins/themes (consider removing unused code) --"
        wp_cli_full plugin list --status=inactive --format=table
        wp_cli_full theme list --status=inactive --format=table
    fi

    # ---------- 4. Extra hardening ----------
    section "4. Additional hardening"

    WP_CONFIG="$WP_PATH/wp-config.php"
    if [[ ! -f "$WP_CONFIG" ]]; then
        log "  wp-config.php not found, skipping DISALLOW_FILE_EDIT."
    elif grep -q "DISALLOW_FILE_EDIT" "$WP_CONFIG"; then
        log "  DISALLOW_FILE_EDIT already set, skipping."
    elif ! grep -q "That's all, stop editing" "$WP_CONFIG"; then
        log "  Could not find the 'That's all, stop editing' anchor in wp-config.php."
        log "  Add this line manually, above the require of wp-settings.php:"
        log "    define('DISALLOW_FILE_EDIT', true);"
    elif [[ $DRY_RUN -eq 1 ]]; then
        log "  [dry-run] would insert DISALLOW_FILE_EDIT into $WP_CONFIG"
    else
        BACKUP="$WP_CONFIG.bak-$(date +%Y%m%d-%H%M%S)"
        cp -p "$WP_CONFIG" "$BACKUP"
        chmod 600 "$BACKUP"
        sed -i \
            -e "/That's all, stop editing/i define('DISALLOW_FILE_EDIT', true);" \
            -e "/That's all, stop editing/i // define('DISALLOW_FILE_MODS', true); // also blocks plugin\/theme installs from the dashboard" \
            "$WP_CONFIG"
        chown "$WEB_USER":"$WEB_GROUP" "$WP_CONFIG"
        chmod 640 "$WP_CONFIG"
        log "  Added DISALLOW_FILE_EDIT to wp-config.php (blocks Appearance > Editor and the plugin editor)."
        log "  Backup kept alongside it as $(basename "$BACKUP") (mode 600)."
    fi

    if [[ $WP_FAILURES -gt 0 ]]; then
        log ""
        log "  WARNING: $WP_FAILURES WP-CLI command(s) failed on $SITE - its status above is"
        log "  incomplete. A fatal here usually means wp-config.php cannot bootstrap from a"
        log "  working directory other than its own (check for relative include paths)."
        SITE_FAILED=1
    fi

    if [[ $SITE_FAILED -eq 1 ]]; then
        ((TOTAL_FAILED++))
    else
        ((TOTAL_HARDENED++))
    fi
done

# --------------------------------------------------------------------------
# Summary
# --------------------------------------------------------------------------

{
echo ""
echo "==================================================================="
echo "  Summary"
echo "==================================================================="
echo "  Hardened : $TOTAL_HARDENED"
echo "  Skipped  : $TOTAL_SKIPPED"
echo "  Failed   : $TOTAL_FAILED"
[[ $DRY_RUN -eq 1 ]] && echo "  (dry run - nothing was actually changed)"
} | tee -a "$LOG_FILE"

echo ""
echo "Log saved to: $LOG_FILE"

[[ $TOTAL_FAILED -gt 0 ]] && exit 1
exit 0
