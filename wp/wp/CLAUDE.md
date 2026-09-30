# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Not an application codebase — a sysadmin toolkit of four standalone bash scripts that scan and harden a fleet of ~22 WordPress sites hosted under `/var/www/` on a Debian + nginx + PHP-FPM server. Alongside them sit captured artifacts from that server: a copy of its `/etc/nginx` tree, rotated nginx/mail logs, and scan reports.

There is no build, test, lint, or dependency step. Not a git repository.

## Critical: this machine is not the target server

The scripts operate on `/var/www/<site>` and `/etc/nginx` **of the production webserver**. This workstation is not that host — nginx is not installed here (only `nginx-common`), `/var/log/nginx/access.log` is empty, and the local `/var/www/` holds unrelated projects. Confirmed during a prior session investigating server saturation.

Consequences:

- **`nginx/` is a read-only snapshot** copied from the production server. Editing files there changes nothing live. Treat it as reference material for understanding the production config; deploying a change means getting it onto the real host.
- **The `*.log`, `mail.*`, and `nginx/*.log` files are stale captures**, not live tails. Check timestamps before drawing conclusions — some are from months prior.
- **`nginx/ssl/` contains private keys** (`*.key`). Do not read, print, or copy them.
- Running any of the scripts here would target the wrong filesystem. Assume they are meant to be transferred to and run on the server unless the user says otherwise.

## The scripts

All four require root and use `set -uo pipefail` (note: no `-e`, so steps continue after failure by design).

| Script | Effect | Notes |
|---|---|---|
| `wp-scan-all.sh` | **Read-only.** Malware/backdoor indicators across all sites → one report | Run this first |
| `wp-harden.sh <wp-path> [user] [group]` | **Destructive.** Single site | See below |
| `wp-harden-all.sh` | Loops `wp-harden.sh` over the site list | Requires `wp-harden.sh` executable in the same dir |
| `wp-fail2ban-setup.sh` | Installs fail2ban, writes jails for `wp-login.php`/`xmlrpc.php` | Overwrites `/etc/fail2ban/{filter.d,jail.d}/wp-*.conf` unconditionally |

### Intended order

`wp-scan-all.sh` → **manual review of flagged files** → cleanup → `wp-harden-all.sh`. The scan report explains why: hardening before cleanup bakes correct ownership and permissions onto malicious files you still intend to delete.

### What `wp-harden.sh` mutates

Recursive `chown`/`chmod` over the whole site, appends blocks to `.htaccess` and `wp-content/uploads/.htaccess`, `sed`-injects `DISALLOW_FILE_EDIT` into `wp-config.php`, writes `/etc/nginx/snippets/wp-xmlrpc-block.conf`, and runs `wp core update` / `wp plugin update --all` / `wp theme update --all`. That last group upgrades live production sites — never invoke it casually to "check something." Use `wp-scan-all.sh` for inspection.

The nginx side is advisory only: the script prints `include` and `location` directives for the operator to add by hand. It never edits a vhost.

## Conventions to preserve when editing

- **The `SITES` array is duplicated** in `wp-scan-all.sh` and `wp-harden-all.sh`. Adding or removing a site means editing both, or they silently drift.
- Both bulk scripts **auto-detect the WordPress root** by looking for `wp-config.php` at the site dir, then `find -maxdepth 3`, and skip the site if neither hits. Preserve this — some sites nest WP under `html/`, and non-WP directories share the list.
- Reports and logs are timestamped: scans → `/root/wp-scan-report-*.txt`, hardening → `/var/log/wp-harden{,-all}-*.log`.
- Scripts frame findings as leads, not verdicts (legitimate plugins use `base64_decode`, `eval`). Keep that hedging in any output you add.

## Production nginx layout (per the snapshot)

Per-site vhosts in `sites-available/`, activated in `sites-enabled/` — in this captured copy those are plain files, not the symlinks they are on the server. Shared config lives in `global/` (`wordpress-restrictions.conf` carries the uploads/`.php` and dotfile denies), and `snippets/`. Sites `include global/wordpress-restrictions.conf`, `try_files $uri $uri/ /index.php?$args`, and `fastcgi_pass php` (an upstream). Most log to the shared `access.log` with a `main_ext` format, with per-site `*_error.log` files present.

Relevant to `wp-fail2ban-setup.sh`: its jail hardcodes `logpath = /var/log/nginx/access.log`. That matches this shared-log setup, but sites with per-vhost access logs would need a glob.

## Known rough edge

In `wp-scan-all.sh`, the WP-CLI admin-user call redirects as `2>&1 >> "$REPORT"` — the order sends stderr to the terminal rather than the report, so WP-CLI errors do not land in the file. Fix by reordering to `>> "$REPORT" 2>&1` if touching that block.
