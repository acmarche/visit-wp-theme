#!/bin/bash
#
# wp-harden.sh
# WordPress hardening / cleanup helper for Debian-based servers (nginx or Apache).
#
# What it does:
#   1. Fixes file/directory ownership and permissions
#   2. Disables xmlrpc.php access (webserver level, works even if a plugin re-enables it)
#   3. Runs WP-CLI update checks/updates for core, plugins, themes
#   4. Applies extra hardening: disables file editing, blocks user enumeration,
#      disables PHP execution in uploads, hides wp-config.php, checks for
#      known indicators of compromise (webshell patterns), lists admin users.
#
# Usage:
#   sudo ./wp-harden.sh /path/to/wordpress [web-user] [web-group]
#
# Example:
#   sudo ./wp-harden.sh /var/www/marche.be/html www-data www-data
#
# Review the output at each step before proceeding to the next site.

set -uo pipefail

# ---------- Config / args ----------
WP_PATH="${1:-}"
WEB_USER="${2:-www-data}"
WEB_GROUP="${3:-www-data}"
LOG_FILE="/var/log/wp-harden-$(date +%Y%m%d-%H%M%S).log"

if [[ -z "$WP_PATH" ]]; then
    echo "Usage: $0 /path/to/wordpress [web-user] [web-group]"
    exit 1
fi

if [[ ! -d "$WP_PATH" ]]; then
    echo "Error: directory '$WP_PATH' does not exist."
    exit 1
fi

if [[ $EUID -ne 0 ]]; then
    echo "Please run this script as root (sudo)."
    exit 1
fi

log() {
    echo -e "$1" | tee -a "$LOG_FILE"
}

section() {
    log "\n==================================================================="
    log "  $1"
    log "===================================================================\n"
}

cd "$WP_PATH" || exit 1

section "WordPress hardening: $WP_PATH"
log "Web user/group: $WEB_USER:$WEB_GROUP"
log "Log file: $LOG_FILE"

# ---------- 1. Ownership & permissions ----------
section "1. Fixing ownership and permissions"

chown -R "$WEB_USER":"$WEB_GROUP" "$WP_PATH"
log "Ownership set to $WEB_USER:$WEB_GROUP recursively."

find "$WP_PATH" -type d -exec chmod 755 {} \;
log "Directories set to 755."

find "$WP_PATH" -type f -exec chmod 644 {} \;
log "Files set to 644."

if [[ -f "$WP_PATH/wp-config.php" ]]; then
    chmod 640 "$WP_PATH/wp-config.php"
    log "wp-config.php locked down to 640."
fi

if [[ -d "$WP_PATH/wp-content/uploads" ]]; then
    find "$WP_PATH/wp-content/uploads" -type d -exec chmod 755 {} \;
    log "Uploads directories confirmed at 755."
fi

# ---------- 2. Disable xmlrpc.php ----------
section "2. Disabling xmlrpc.php"

if command -v nginx >/dev/null 2>&1; then
    NGINX_SNIPPET="/etc/nginx/snippets/wp-xmlrpc-block.conf"
    cat > "$NGINX_SNIPPET" << 'EOF'
location = /xmlrpc.php {
    deny all;
    return 403;
}
EOF
    log "Created $NGINX_SNIPPET"
    log "Add 'include snippets/wp-xmlrpc-block.conf;' inside each site's server {} block, then run:"
    log "  nginx -t && systemctl reload nginx"
fi

if [[ -f "$WP_PATH/.htaccess" ]] || command -v apache2 >/dev/null 2>&1; then
    HTACCESS="$WP_PATH/.htaccess"
    if ! grep -q "xmlrpc.php" "$HTACCESS" 2>/dev/null; then
        cat >> "$HTACCESS" << 'EOF'

# Block xmlrpc.php (added by wp-harden.sh)
<Files xmlrpc.php>
Order Deny,Allow
Deny from all
</Files>
EOF
        log "Added xmlrpc.php block rule to $HTACCESS"
    else
        log "xmlrpc.php rule already present in .htaccess, skipping."
    fi
fi

log "Note: blocking at the webserver level stops access even if a plugin re-enables XML-RPC."

# ---------- 3. WP-CLI checks / updates ----------
section "3. WordPress core, plugin, theme status"

if ! command -v wp >/dev/null 2>&1; then
    log "WP-CLI not found. Install it with:"
    log "  curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
    log "  chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
else
    WP="sudo -u $WEB_USER wp --path=$WP_PATH"

    log "-- Current version --"
    $WP core version | tee -a "$LOG_FILE"

    log "\n-- Checking core integrity (checksums) --"
    $WP core verify-checksums 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Plugins --"
    $WP plugin list --format=table 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Themes --"
    $WP theme list --format=table 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Applying updates: core, plugins, themes --"
    $WP core update 2>&1 | tee -a "$LOG_FILE"
    $WP core update-db 2>&1 | tee -a "$LOG_FILE"
    $WP plugin update --all 2>&1 | tee -a "$LOG_FILE"
    $WP theme update --all 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Verifying plugin checksums (where available) --"
    $WP plugin verify-checksums --all 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Admin users (review for anything unrecognized) --"
    $WP user list --role=administrator --fields=ID,user_login,user_email,user_registered 2>&1 | tee -a "$LOG_FILE"

    log "\n-- Inactive plugins/themes (consider removing unused code) --"
    $WP plugin list --status=inactive --format=table 2>&1 | tee -a "$LOG_FILE"
    $WP theme list --status=inactive --format=table 2>&1 | tee -a "$LOG_FILE"
fi

# ---------- 4. Extra hardening ----------
section "4. Additional hardening"

# 4a. Disable in-dashboard file editing
if [[ -f "$WP_PATH/wp-config.php" ]] && ! grep -q "DISALLOW_FILE_EDIT" "$WP_PATH/wp-config.php"; then
    sed -i "/\/\* That's all, stop editing/i define('DISALLOW_FILE_EDIT', true);\ndefine('DISALLOW_FILE_MODS', false); // set to true to also block plugin/theme install from dashboard\n" "$WP_PATH/wp-config.php"
    log "Added DISALLOW_FILE_EDIT to wp-config.php (blocks Appearance > Editor / Plugin editor)."
else
    log "DISALLOW_FILE_EDIT already set or wp-config.php not found, skipping."
fi

# 4b. Block PHP execution inside uploads
if [[ -d "$WP_PATH/wp-content/uploads" ]]; then
    UPLOADS_HTACCESS="$WP_PATH/wp-content/uploads/.htaccess"
    if [[ ! -f "$UPLOADS_HTACCESS" ]] || ! grep -q "php_admin_flag engine off" "$UPLOADS_HTACCESS" 2>/dev/null; then
        cat > "$UPLOADS_HTACCESS" << 'EOF'
# Block PHP execution in uploads (added by wp-harden.sh)
<FilesMatch "\.(php|php3|php4|php5|php7|phtml|pl|py|cgi)$">
Order Deny,Allow
Deny from all
</FilesMatch>
EOF
        log "Blocked PHP execution in wp-content/uploads via .htaccess."
    fi
    log "For nginx, add this inside the server {} block instead:"
    log '  location ~* /wp-content/uploads/.*\.php$ { deny all; }'
fi

# 4c. Hide wp-config.php from being served if somehow inside webroot access
if [[ -f "$WP_PATH/.htaccess" ]]; then
    if ! grep -q "wp-config.php" "$WP_PATH/.htaccess" 2>/dev/null; then
        cat >> "$WP_PATH/.htaccess" << 'EOF'

# Protect wp-config.php (added by wp-harden.sh)
<Files wp-config.php>
Order Deny,Allow
Deny from all
</Files>
EOF
        log "Added wp-config.php protection to .htaccess."
    fi
fi
log "For nginx, add:"
log '  location ~* wp-config.php { deny all; }'

# 4d. Disable directory listing reminder (nginx/apache)
log "Ensure directory listing is off:"
log "  nginx: 'autoindex off;' (default)"
log "  Apache: 'Options -Indexes' in your vhost or .htaccess"

# 4e. Quick indicator-of-compromise scan (does not delete anything, just reports)
section "5. Quick malware indicator scan (report only, does not delete)"

log "Scanning for suspicious PHP patterns (eval, base64_decode, gzinflate, obfuscated goto chains, etc.)..."
grep -rlE "eval\(|assert\(|base64_decode\(|gzinflate\(|str_rot13\(|shell_exec\(|passthru\(|system\(|goto [A-Za-z0-9_]+;" \
    --include="*.php" "$WP_PATH" 2>/dev/null | tee -a "$LOG_FILE" > /tmp/wp-harden-suspects.txt

SUSPECT_COUNT=$(wc -l < /tmp/wp-harden-suspects.txt)
log "\nFound $SUSPECT_COUNT file(s) matching suspicious patterns. Review each one manually — this is a"
log "starting point, not proof of infection (some legitimate plugins use these functions too)."

log "\nChecking for PHP files inside uploads (should never be there):"
find "$WP_PATH/wp-content/uploads" -iname "*.php" 2>/dev/null | tee -a "$LOG_FILE"

log "\nChecking for recently modified PHP files (last 7 days):"
find "$WP_PATH" -iname "*.php" -mtime -7 2>/dev/null | tee -a "$LOG_FILE"

# ---------- Summary ----------
section "Done"
log "Full log saved to: $LOG_FILE"
log "List of suspicious files saved to: /tmp/wp-harden-suspects.txt"
log ""
log "Manual steps still required:"
log "  - Reload nginx/Apache after adding the config snippets shown above."
log "  - Review the admin user list and remove any account you don't recognize."
log "  - Review suspicious files found in the scan before deleting anything."
log "  - Change WordPress admin, database, and SSH/hosting passwords if not already done."
log "  - Consider a Web Application Firewall (Wordfence, or Cloudflare/ModSecurity rules)."
