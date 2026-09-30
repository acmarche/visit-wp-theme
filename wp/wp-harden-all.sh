#!/bin/bash
#
# wp-harden-all.sh
#
# Applies a baseline hardening pass to every WordPress site listed in SITES:
#   1. ownership and file/directory permissions
#   2. WP-CLI status checks and core/plugin/theme updates
#   3. XML-RPC: confirm nginx denies it, and guard xmlrpc.php if it does not
#   4. wp-config.php hardening (DISALLOW_FILE_EDIT)
#
# THIS SCRIPT MODIFIES FILES. Run --dry-run first to see what it would do.
#
# These servers run nginx, not Apache, so there is no .htaccess step. XML-RPC
# is blocked by /etc/nginx/global/wordpress-restrictions.conf, which each
# vhost pulls in with `include global/wordpress-restrictions.conf;`. Step 3
# follows the include chain of the vhost that serves each site and only falls
# back to editing xmlrpc.php when that chain does not deny it.
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
#       --xmlrpc-die    Always add the PHP guard to xmlrpc.php, even when nginx
#                       already denies it (defence in depth). Note that this
#                       makes `wp core verify-checksums` report xmlrpc.php as
#                       modified from then on.
#       --no-xmlrpc-die Never touch xmlrpc.php; only report on nginx coverage.
#   -h, --help          Show this help.
#
# Output: one combined log file, path printed at the end.

set -uo pipefail

BASE_DIR="/var/www"
WEB_USER="www-data"
WEB_GROUP="www-data"
NGINX_PREFIX="/etc/nginx"
# The shared restrictions file every vhost is expected to include.
NGINX_RESTRICTIONS="global/wordpress-restrictions.conf"
LOG_FILE="/var/log/wp-harden-all-$(date +%Y%m%d-%H%M%S).log"

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
# auto  - guard xmlrpc.php only where nginx coverage cannot be confirmed
# force - always guard it;  never - never touch it
XMLRPC_MODE="auto"
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
        --xmlrpc-die)     XMLRPC_MODE="force" ;;
        --no-xmlrpc-die)  XMLRPC_MODE="never" ;;
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
# nginx inspection (read-only) and the XML-RPC step
# --------------------------------------------------------------------------

# nginx_flatten <file> [depth] - the config as nginx sees it: every line as
# "<source file><TAB><line>", with include directives expanded in place so the
# resulting order is the order nginx itself resolves. Relative paths resolve
# against the prefix and globs are expanded, as nginx does.
nginx_flatten() {
    local file="$1" depth="${2:-0}" line inc g
    [[ $depth -gt 6 || ! -f "$file" ]] && return 0
    while IFS= read -r line; do
        printf '%s\t%s\n' "$file" "$line"
        if [[ "$line" =~ ^[[:space:]]*include[[:space:]]+([^\;]+)\; ]]; then
            inc="${BASH_REMATCH[1]}"
            inc="${inc%\"}"; inc="${inc#\"}"
            inc="${inc%\'}"; inc="${inc#\'}"
            [[ "$inc" != /* ]] && inc="$NGINX_PREFIX/$inc"
            for g in $inc; do
                [[ -f "$g" ]] && nginx_flatten "$g" $((depth + 1))
            done
        fi
    done < "$file"
}

# xmlrpc_status - read a flattened config on stdin and emit one record per
# finding, tab separated:
#   DENY     <line no>  <file that denies xmlrpc>
#   HANDLER  <line no>  <location line that executes PHP>
#
# A "handler" is a regex location whose body reaches PHP (fastcgi_pass /
# proxy_pass). Deny-only locations - including the uploads rule in the
# restrictions file itself - are not handlers and cannot shadow anything.
#
# Matching on bare 'xmlrpc': the config states it as a regex, so the literal
# text is xmlrpc\.php, backslash included, and an escaped-dot pattern misses it.
xmlrpc_status() {
    awk -F'\t' '
        function braces(str,   t, o, c) {
            t = str; o = gsub(/\{/, "", t)
            t = str; c = gsub(/\}/, "", t)
            return o - c
        }
        {
            line = substr($0, index($0, "\t") + 1)
            idx++
        }
        line ~ /^[[:space:]]*#/ { next }

        # Track a regex location block and whether it hands off to PHP.
        !inloc && line ~ /^[[:space:]]*location[[:space:]]+~/ && line !~ /xmlrpc/ {
            loctext = line; sub(/^[[:space:]]+/, "", loctext)
            locidx = idx; depth = braces(line); haspass = 0
            inloc = (depth > 0)
            next
        }
        inloc {
            if (line ~ /fastcgi_pass|proxy_pass|include[[:space:]]+[^;]*fastcgi/) haspass = 1
            depth += braces(line)
            if (depth <= 0) {
                if (haspass) printf "HANDLER\t%d\t%s\n", locidx, loctext
                inloc = 0
            }
            next
        }

        # The deny itself: xmlrpc named, then denied within a few lines.
        !deny && line ~ /xmlrpc/ { window = 6; xsrc = $1 }
        window > 0 {
            if (line ~ /deny[[:space:]]+all|return[[:space:]]+(403|404|444)/) {
                deny = idx
                printf "DENY\t%d\t%s\n", idx, xsrc
                window = 0
            } else window--
        }
    '
}

# loc_matches_xmlrpc <location line> - true when that location regex would
# actually match /xmlrpc.php. nginx uses PCRE and so does preg_match, so the
# test is exact rather than a guess. Without PHP we cannot decide, and assume
# it matches (the conservative answer: it produces a warning, not a silent ok).
loc_matches_xmlrpc() {
    local line="$1" pat ci=""
    command -v php >/dev/null 2>&1 || return 0
    [[ "$line" =~ location[[:space:]]+~\* ]] && ci="i"
    pat="$(sed -E 's/^[[:space:]]*location[[:space:]]+~\*?[[:space:]]*//; s/[[:space:]]*\{[[:space:]]*$//' <<<"$line")"
    [[ -z "$pat" ]] && return 0
    php -r 'exit(@preg_match("#".$argv[1]."#".$argv[2], "/xmlrpc.php") === 1 ? 0 : 1);' \
        "$pat" "$ci" 2>/dev/null
}

# site_vhosts - print the enabled nginx config files whose root is $WP_PATH.
site_vhosts() {
    local dirs=() d
    for d in "$NGINX_PREFIX/sites-enabled" "$NGINX_PREFIX/conf.d"; do
        [[ -d "$d" ]] && dirs+=("$d")
    done
    [[ -f "$NGINX_PREFIX/nginx.conf" ]] && dirs+=("$NGINX_PREFIX/nginx.conf")
    [[ ${#dirs[@]} -eq 0 ]] && return 0
    grep -rlE "^[[:space:]]*root[[:space:]]+${WP_PATH%/}/?[[:space:]]*;" "${dirs[@]}" 2>/dev/null
}

XMLRPC_MARK="wp-harden-all.sh: XML-RPC disabled"

# xmlrpc_guard - insert a 403 guard directly after the opening <?php of
# xmlrpc.php. Idempotent, and syntax-checked before it replaces the original.
xmlrpc_guard() {
    local f="$WP_PATH/xmlrpc.php" tmp
    if [[ ! -f "$f" ]]; then
        log "  xmlrpc.php is not present in this install, nothing to guard."
        return 0
    fi
    if grep -qF "$XMLRPC_MARK" "$f"; then
        log "  PHP guard already present in xmlrpc.php, skipping."
        return 0
    fi
    if [[ $DRY_RUN -eq 1 ]]; then
        log "  [dry-run] would insert a 403 guard at the top of $f"
        return 0
    fi

    tmp="$(mktemp)" || return 1
    awk -v mark="$XMLRPC_MARK" '
        !ins && /^<\?php/ {
            print
            print ""
            print "// " mark
            print "http_response_code( 403 );"
            print "header( \"Content-Type: text/plain; charset=utf-8\" );"
            print "exit( \"XML-RPC services are disabled on this site.\" );"
            ins = 1
            next
        }
        { print }
        END { exit(ins ? 0 : 1) }
    ' "$f" > "$tmp"
    if [[ $? -ne 0 ]]; then
        rm -f "$tmp"
        log "  FAILED: no opening <?php found in xmlrpc.php, left untouched."
        return 1
    fi
    if command -v php >/dev/null 2>&1 && ! php -l "$tmp" >>"$LOG_FILE" 2>&1; then
        rm -f "$tmp"
        log "  FAILED: the guarded xmlrpc.php did not pass php -l, left untouched."
        return 1
    fi
    cat "$tmp" > "$f" && rm -f "$tmp"
    chown "$WEB_USER":"$WEB_GROUP" "$f"
    chmod 644 "$f"
    log "  Inserted a 403 guard at the top of xmlrpc.php."
    log "  It returns 403 before WordPress loads, so pingback and every other"
    log "  method is gone - unlike the xmlrpc_enabled filter, which only"
    log "  disables the methods that require authentication."
    log "  NOTE: a core update restores the stock file. This script re-applies"
    log "  the guard on the next run, and it runs after the update step."
    log "  NOTE: xmlrpc.php will now be reported by 'wp core verify-checksums'."
    return 0
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
    echo "This will chown -R to $WEB_USER, reset permissions, edit wp-config.php,"
    echo "run WordPress updates on the sites above, and - where nginx does not"
    echo "already deny it - add a 403 guard to xmlrpc.php."
    read -r -p "Continue? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

TOTAL_HARDENED=0
TOTAL_SKIPPED=0
TOTAL_FAILED=0

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

    # ---------- 2. WP-CLI checks / updates ----------
    section "2. WordPress core, plugin and theme status"

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

    # ---------- 3. XML-RPC ----------
    # After the update step on purpose: `core update` reinstalls the stock
    # xmlrpc.php, which would silently drop a guard applied before it.
    section "3. XML-RPC"

    XMLRPC_STATE="unknown"
    if [[ ! -d "$NGINX_PREFIX" ]]; then
        log "  No $NGINX_PREFIX on this machine - cannot tell how this site is served."
    else
        mapfile -t VHOSTS < <(site_vhosts)
        if [[ ${#VHOSTS[@]} -eq 0 ]]; then
            log "  No enabled nginx server block has 'root $WP_PATH;'."
            log "  Either the site is served from elsewhere, or its vhost is not enabled."
        else
            for VHOST in "${VHOSTS[@]}"; do
                log "  vhost: $VHOST"
                FLAT="$(nginx_flatten "$VHOST")"

                if ! printf '%s\n' "$FLAT" | cut -f1 | grep -qF "$NGINX_RESTRICTIONS"; then
                    log "    [!] its include chain does not reach $NGINX_RESTRICTIONS."
                    log "        Add 'include $NGINX_RESTRICTIONS;' inside the server block"
                    log "        (reference copy: wp/wordpress-restrictions.conf)."
                fi

                DENY_IDX=0; DENY_SRC=""; SHADOW=""
                HANDLERS=()
                while IFS=$'\t' read -r KIND POS TEXT; do
                    case "$KIND" in
                        DENY)    DENY_IDX="$POS"; DENY_SRC="$TEXT" ;;
                        HANDLER) HANDLERS+=("$POS"$'\t'"$TEXT") ;;
                    esac
                done < <(printf '%s\n' "$FLAT" | xmlrpc_status)

                # Only a PHP handler that comes first AND matches /xmlrpc.php
                # takes precedence over the deny.
                for H in ${HANDLERS[0]+"${HANDLERS[@]}"}; do
                    HPOS="${H%%$'\t'*}"; HTEXT="${H#*$'\t'}"
                    [[ $DENY_IDX -ne 0 && $HPOS -gt $DENY_IDX ]] && continue
                    if loc_matches_xmlrpc "$HTEXT"; then SHADOW="$HTEXT"; break; fi
                done

                if [[ $DENY_IDX -eq 0 ]]; then
                    log "    [!] nothing in its include chain denies xmlrpc.php."
                    XMLRPC_STATE="uncovered"
                elif [[ -n "$SHADOW" ]]; then
                    log "    [!] $DENY_SRC denies xmlrpc.php, but this comes first:"
                    log "          $SHADOW"
                    log "        nginx takes the FIRST matching regex location, so that"
                    log "        handler wins and xmlrpc.php still reaches PHP-FPM."
                    log "        Move 'include $NGINX_RESTRICTIONS;' above it."
                    XMLRPC_STATE="uncovered"
                else
                    log "    xmlrpc.php is denied by $DENY_SRC, ahead of any PHP handler."
                    [[ "$XMLRPC_STATE" == "unknown" ]] && XMLRPC_STATE="covered"
                fi
            done
        fi
    fi

    case "$XMLRPC_MODE:$XMLRPC_STATE" in
        never:*)
            log "  --no-xmlrpc-die: xmlrpc.php left untouched (report only)." ;;
        force:*)
            log "  --xmlrpc-die: applying the PHP guard regardless of nginx."
            xmlrpc_guard || SITE_FAILED=1 ;;
        auto:covered)
            log "  nginx already returns 403 before PHP runs, so xmlrpc.php is left"
            log "  stock and core checksums stay clean. Use --xmlrpc-die to guard it anyway." ;;
        auto:uncovered)
            log "  nginx does not deny it here - falling back to the PHP guard."
            xmlrpc_guard || SITE_FAILED=1 ;;
        auto:unknown)
            log "  Could not confirm how xmlrpc.php is served, so nothing was changed."
            log "  Re-run with --xmlrpc-die to guard the file anyway." ;;
    esac

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
