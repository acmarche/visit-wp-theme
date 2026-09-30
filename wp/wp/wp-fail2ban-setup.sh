#!/bin/bash
#
# wp-fail2ban-setup.sh
# Installs and configures Fail2ban to block brute-force attempts against
# wp-login.php and xmlrpc.php across nginx or Apache-served WordPress sites.
#
# Usage:
#   sudo ./wp-fail2ban-setup.sh
#
# Assumes access logs are in the standard Debian locations:
#   nginx : /var/log/nginx/access.log (or per-site logs under /var/log/nginx/)
#   apache: /var/log/apache2/access.log (or per-site logs under /var/log/apache2/)
#
# If your sites use separate per-vhost log files, edit the "logpath" lines
# in the jail below to a glob covering them, e.g.:
#   logpath = /var/log/nginx/*access.log

set -uo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Please run this script as root (sudo)."
    exit 1
fi

echo "=== Installing Fail2ban ==="
apt update -y
apt install -y fail2ban

echo "=== Creating WordPress-specific filter ==="
cat > /etc/fail2ban/filter.d/wp-login.conf << 'EOF'
# Fail2ban filter for WordPress wp-login.php and xmlrpc.php brute-force attempts
[Definition]
failregex = ^<HOST> .* "POST /wp-login.php
            ^<HOST> .* "POST /xmlrpc.php
ignoreregex =
EOF

echo "=== Creating jail for wp-login/xmlrpc ==="
cat > /etc/fail2ban/jail.d/wp-login.conf << 'EOF'
[wp-login]
enabled  = true
port     = http,https
filter   = wp-login
logpath  = /var/log/nginx/access.log
           /var/log/apache2/access.log
maxretry = 5
findtime = 600
bantime  = 3600
action   = iptables-multiport[name=wp-login, port="http,https", protocol=tcp]
EOF

echo "=== Creating a stricter repeat-offender jail (optional but recommended) ==="
cat > /etc/fail2ban/jail.d/wp-login-recidive.conf << 'EOF'
# Escalates the ban for IPs that get caught by wp-login multiple times
[recidive]
enabled  = true
filter   = recidive
logpath  = /var/log/fail2ban.log
action   = iptables-allports[name=recidive]
bantime  = 604800
findtime = 86400
maxretry = 3
EOF

echo "=== Restarting Fail2ban ==="
systemctl restart fail2ban
systemctl enable fail2ban

echo ""
echo "=== Status ==="
fail2ban-client status
echo ""
fail2ban-client status wp-login

echo ""
echo "Done. Useful commands:"
echo "  fail2ban-client status wp-login        # see currently banned IPs"
echo "  fail2ban-client set wp-login unbanip X.X.X.X   # manually unban an IP"
echo "  tail -f /var/log/fail2ban.log           # watch it working live"
echo ""
echo "IMPORTANT: if your sites have separate per-vhost access logs instead of"
echo "one shared nginx/apache access.log, edit:"
echo "  /etc/fail2ban/jail.d/wp-login.conf"
echo "and set 'logpath' to a glob that covers all of them, e.g.:"
echo "  logpath = /var/log/nginx/*-access.log"
echo "then run: systemctl restart fail2ban"
