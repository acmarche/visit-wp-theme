#!/usr/bin/env bash
#
# clean-hacked-sites.sh — Medusa cloaking-toolkit eradication for WordPress sites
# Incident ref MDA-2026-09-22
#
# Removes the Medusa gate/webshell toolkit, force-reinstalls core+plugins+themes,
# rotates credentials, and audits what it could not fix automatically.
#
# DRY RUN BY DEFAULT. Nothing is changed until you pass --execute.
#
# Files are never deleted: they are MOVED to a quarantine tree outside the web
# root, so every action is reversible and the evidence survives.
#
# Usage:
#   ./clean-hacked-sites.sh                      # dry run, all sites in sites.txt
#   ./clean-hacked-sites.sh --execute            # do it
#   ./clean-hacked-sites.sh --site mda           # one site (repeatable)
#   ./clean-hacked-sites.sh --execute --phase 1-3
#   ./clean-hacked-sites.sh --execute --skip-passwords --skip-updates
#
# Phase order matters. The malware installs two self-repair mu-plugins that
# rewrite index.php on every page load. Phase 2 removes those FIRST; running
# phases out of order lets the infection rebuild itself behind you.
#
set -uo pipefail

# ─────────────────────────────────────────────────────────── configuration ──

WEB_ROOT="${WEB_ROOT:-/var/www}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITES_FILE="${SITES_FILE:-$SCRIPT_DIR/sites.txt}"
STAMP="$(date +%Y%m%d-%H%M%S)"
IR_ROOT="${IR_ROOT:-/root/ir/$STAMP}"
QUARANTINE="$IR_ROOT/quarantine"
LOG="$IR_ROOT/clean.log"
REPORT="$IR_ROOT/report.txt"

DRY_RUN=1
SKIP_UPDATES=0
SKIP_PASSWORDS=0
PASSWORD_EMAIL=1
PHASE_FROM=1
PHASE_TO=8
ONLY_SITES=()

# wp-cli flags. --allow-root is added automatically when running as root.
WP_FLAGS=("--skip-plugins" "--skip-themes")

# wp-cli binary name. The production server calls it `wpcli`; other hosts use
# `wp`. Autodetected, preferring `wpcli`. Override with WP_BIN=/path/to/binary.
if [[ -z "${WP_BIN:-}" ]]; then
  for _c in wpcli wp wp-cli; do
    if command -v "$_c" >/dev/null 2>&1; then WP_BIN="$_c"; break; fi
  done
  WP_BIN="${WP_BIN:-wpcli}"
  unset _c
fi

# Web server user, used when resetting ownership.
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

# This server is nginx: .htaccess is never read, so the script neither
# writes nor regenerates one. Hardening is audited against the shared
# include below instead. Set SERVER_TYPE=apache to re-enable .htaccess.
SERVER_TYPE="${SERVER_TYPE:-nginx}"
NGINX_INCLUDE="${NGINX_INCLUDE:-/etc/nginx/global/wordpress-restrictions.conf}"
NGINX_INCLUDE_ALT="${NGINX_INCLUDE_ALT:-$SCRIPT_DIR/wordpress-restrictions.conf}"
FPM_POOL_DIR="${FPM_POOL_DIR:-/etc/php/8.5/fpm/pool.d}"

# ───────────────────────────────────────────── malware indicators (rev 2) ──

# Content signatures. A file containing any of these is toolkit-owned.
# Kept deliberately specific so no legitimate file matches.
SIGNATURES=(
  'MSENC-V2'
  'FM_ENC_LOADER'
  'FM_PASSWORD_HASH'
  'FM_GATE_REDIRECT_URL'
  'MS_DROP_SEAL'
  'MS_PREPEND_GATE'
  'MS_BOT_JP_SHIELD'
  'X-Medusa-Prepend'
  'X-Medusa-Mu-Gate'
  'ms_gate_body_ok'
  'ms_jp_guard_run'
  'ms_panel_self_heal'
  'ms-bot-prepend.php'
  'Medusa Early Router'
  'Medusa FM'
  'istanbulescortladys'
  'sensizolmaz'
  'onlineresmigiris'
)

# Exact paths, relative to each site root. Quarantined if the file exists AND
# (it matches a signature OR it is in the unconditional list below).
KNOWN_PATHS=(
  'ms-home-gate.php'
  'ms-bot-prepend.php'
  'ist-dest.txt'
  '.ms_index.bak.php'
  '.ms_gate_redirect.json'
  '.ms_gate_kontrol_ok.json'
  'wp-content/ms-early-router.php'
  'wp-content/.ms_index.bak.php'
  'wp-content/.ms_htaccess.bak'
  'wp-content/.ms_gate_redirect.json'
  'wp-content/fm_index_heal.php'
  'wp-content/mu-plugins/ms-vitrin-gate.php'
  'wp-content/mu-plugins/ms-index-heal.php'
  'wp-content/mu-plugins/00-ms-jp-guard.php'
)

# Self-repair components. Removed in phase 2, before anything else.
HEALER_PATHS=(
  'wp-content/mu-plugins/00-ms-jp-guard.php'
  'wp-content/mu-plugins/ms-index-heal.php'
  'wp-content/mu-plugins/ms-vitrin-gate.php'
  'wp-content/fm_index_heal.php'
)

# Filename patterns swept across the whole site tree.
NAME_PATTERNS=(
  '.ms_*' 'ms-home-gate.php' 'ms-bot-prepend.php' 'ms-early-router.php'
  'ms-vitrin-gate.php' 'ms-index-heal.php' '00-ms-jp-guard.php'
  '.fm_*' 'fm_fleet_post.php' 'fm_index_heal.php' 'ist-dest.txt'
)

# Directories where PHP must never exist (relative to site root).
NO_PHP_DIRS=(
  'images'
  'wp-content/uploads'
  'wp-content/cache'
  'wp-content/upgrade'
)

# ──────────────────────────────────────────────────────────────── plumbing ──

C_RESET=$'\033[0m'; C_DIM=$'\033[2m'; C_RED=$'\033[31m'
C_GRN=$'\033[32m'; C_YEL=$'\033[33m'; C_BLU=$'\033[34m'; C_BLD=$'\033[1m'
[[ -t 1 ]] || { C_RESET=''; C_DIM=''; C_RED=''; C_GRN=''; C_YEL=''; C_BLU=''; C_BLD=''; }

log()   { printf '%s\n' "$*" | tee -a "$LOG" >/dev/null; }
say()   { printf '%s\n' "$*"; log "$*"; }
info()  { printf '%s\n' "${C_DIM}    $*${C_RESET}"; log "    $*"; }
ok()    { printf '%s\n' "${C_GRN}  ✔ $*${C_RESET}"; log "  [ok] $*"; }
warn()  { printf '%s\n' "${C_YEL}  ! $*${C_RESET}"; log "  [warn] $*"; }
bad()   { printf '%s\n' "${C_RED}  ✘ $*${C_RESET}"; log "  [FAIL] $*"; }
act()   { printf '%s\n' "${C_BLU}  → $*${C_RESET}"; log "  [act] $*"; }

hdr() {
  printf '\n%s\n' "${C_BLD}$*${C_RESET}"
  log ""; log "=== $* ==="
}

phase_hdr() {
  printf '\n%s\n%s\n' "${C_BLD}── Phase $1: $2 ${C_RESET}" \
    "${C_DIM}$(printf '─%.0s' {1..66})${C_RESET}"
  log ""; log "───── Phase $1: $2 ─────"
}

# Counters for the closing report.
declare -A SEEN=()
declare -a REPORT_LINES=()
declare -a MANUAL_ITEMS=()
N_QUARANTINED=0; N_SITES=0; N_WP=0; N_ERRORS=0

note()   { REPORT_LINES+=("$*"); }
manual() { MANUAL_ITEMS+=("$*"); log "  [MANUAL] $*"; }

phase_enabled() { (( $1 >= PHASE_FROM && $1 <= PHASE_TO )); }

# Run a mutating command, honouring dry run.
run() {
  if (( DRY_RUN )); then
    printf '%s\n' "${C_DIM}    would run: $*${C_RESET}"
    log "    [dry] $*"
    return 0
  fi
  log "    [run] $*"
  "$@" >>"$LOG" 2>&1
}

# ────────────────────────────────────────────────────────────── arg parsing ──

usage() { sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0; }

while (( $# )); do
  case "$1" in
    --execute)         DRY_RUN=0 ;;
    --dry-run)         DRY_RUN=1 ;;
    --site)            ONLY_SITES+=("$2"); shift ;;
    --skip-updates)    SKIP_UPDATES=1 ;;
    --skip-passwords)  SKIP_PASSWORDS=1 ;;
    --no-email)        PASSWORD_EMAIL=0 ;;
    --phase)
      if [[ "$2" == *-* ]]; then PHASE_FROM="${2%%-*}"; PHASE_TO="${2##*-}"
      else PHASE_FROM="$2"; PHASE_TO="$2"; fi; shift ;;
    --web-root)        WEB_ROOT="$2"; shift ;;
    -h|--help)         usage ;;
    *) printf 'Unknown option: %s (try --help)\n' "$1" >&2; exit 2 ;;
  esac
  shift
done

[[ $EUID -eq 0 ]] && WP_FLAGS+=("--allow-root")

# ───────────────────────────────────────────────────────────────── preflight ──

mkdir -p "$IR_ROOT" "$QUARANTINE" || { echo "cannot create $IR_ROOT" >&2; exit 1; }
: >"$LOG"

printf '%s\n' "${C_BLD}Medusa eradication — incident MDA-2026-09-22${C_RESET}"
printf '%s\n' "${C_DIM}started $(date -Is) on $(hostname)${C_RESET}"

if (( DRY_RUN )); then
  printf '\n%s\n' "${C_YEL}${C_BLD}DRY RUN — nothing will be changed.${C_RESET}"
  printf '%s\n' "${C_YEL}Re-run with --execute to apply. Review this output first.${C_RESET}"
else
  printf '\n%s\n' "${C_RED}${C_BLD}EXECUTE MODE — files will be moved and credentials rotated.${C_RESET}"
fi
info "web root:    $WEB_ROOT"
info "quarantine:  $QUARANTINE"
info "log:         $LOG"
info "phases:      $PHASE_FROM-$PHASE_TO"

[[ -r "$SITES_FILE" ]] || { bad "cannot read $SITES_FILE"; exit 1; }
# shellcheck disable=SC1090
source "$SITES_FILE"
[[ -n "${SITES+x}" ]] || { bad "$SITES_FILE did not define SITES"; exit 1; }

if (( ${#ONLY_SITES[@]} )); then
  SITES=("${ONLY_SITES[@]}")
  info "limited to:  ${SITES[*]}"
fi

have_wp=1
if command -v "$WP_BIN" >/dev/null 2>&1; then
  info "wp-cli:      $WP_BIN ($(command -v "$WP_BIN"))"
else
  have_wp=0
  warn "wp-cli binary '$WP_BIN' not found — phases 5-7 will be skipped"
  info "set WP_BIN=<name> if it is called something else"
fi

# Tally sites up front so the closing report is accurate for any phase subset.
for _s in "${SITES[@]}"; do
  [[ -d "$WEB_ROOT/$_s" ]] || continue
  (( N_SITES++ ))
  [[ -f "$WEB_ROOT/$_s/wp-includes/version.php" ]] && (( N_WP++ ))
done
unset _s
info "found:       $N_SITES site director$( (( N_SITES == 1 )) && echo y || echo ies ), $N_WP WordPress"

# ────────────────────────────────────────────────────────────────── helpers ──

# Signature matching. One recursive fixed-string pass per site populates
# SIG_HITS; per-file checks are then just a lookup. Scanning each file against
# each pattern individually is far too slow across thousands of files.
SIGFILE=""
declare -A SIG_HITS=()
declare -A SIG_SCANNED=()

init_sigfile() {
  [[ -n "$SIGFILE" ]] && return
  SIGFILE="$IR_ROOT/.signatures"
  printf '%s\n' "${SIGNATURES[@]}" >"$SIGFILE"
}

# Build the signature hit set for one site tree.
scan_site_signatures() {
  local root="$1"
  [[ -n "${SIG_SCANNED[$root]:-}" ]] && return
  SIG_SCANNED["$root"]=1
  init_sigfile
  local f
  while IFS= read -r f; do
    [[ -n "$f" ]] && SIG_HITS["$f"]=1
  done < <(grep -rlFf "$SIGFILE" "$root" \
             --include='*.php' --include='*.json' --include='*.txt' \
             --include='*.html' --include='*.ini' --include='.htaccess' \
             --include='.user.ini' 2>/dev/null)
}

# Forget the cached verdict for one path, after editing it in place.
invalidate_signature() { unset 'SIG_HITS[$1]'; }

# Does this file contain a toolkit signature?
is_malicious_content() {
  local f="$1"
  [[ -n "${SIG_HITS[$f]:-}" ]] && return 0
  # Fall back to a direct check for paths outside the cached include set.
  init_sigfile
  grep -qFf "$SIGFILE" -- "$f" 2>/dev/null
}

# WordPress's own upload guards look like "<?php // Silence is golden." —
# the malware's stub calls http_response_code(404). Keep the former.
is_silence_guard() {
  local body
  body="$(head -c 120 "$1" 2>/dev/null | tr -d '[:space:]')"
  [[ "$body" == '<?php//Silenceisgolden.' || "$body" == '<?php//Silenceisgolden' ]]
}

# Move a path into quarantine, preserving its position in the tree.
quarantine() {
  local site="$1" abs="$2" reason="$3"
  local rel="${abs#"$WEB_ROOT"/}"
  local dest="$QUARANTINE/$rel"

  # A path can match several rules; only act on it once.
  [[ -n "${SEEN[$abs]:-}" ]] && return 0
  SEEN["$abs"]=1

  act "quarantine  ${rel}  ${C_DIM}[$reason]${C_RESET}"
  if (( DRY_RUN )); then
    log "    [dry] mv $abs -> $dest ($reason)"
  else
    mkdir -p "$(dirname "$dest")"
    # Files are chmod 0444 and their parent may be locked.
    chmod u+w "$(dirname "$abs")" 2>/dev/null || true
    chmod 0644 "$abs" 2>/dev/null || true
    if mv -f "$abs" "$dest" 2>>"$LOG"; then
      log "    [ok] quarantined $rel ($reason)"
    else
      bad "could not move $rel — check permissions"
      (( N_ERRORS++ )); return 1
    fi
  fi
  (( N_QUARANTINED++ ))
  return 0
}

site_root() { printf '%s/%s' "$WEB_ROOT" "$1"; }
is_wp()     { [[ -f "$1/wp-includes/version.php" ]]; }

wpx() {  # wp-cli for a given site path; prints stdout, tolerates failure
  local path="$1"; shift
  "$WP_BIN" "$@" --path="$path" "${WP_FLAGS[@]}" 2>>"$LOG"
}

wpdb_ok() { wpx "$1" db check >/dev/null 2>&1; }

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 1 — inventory and evidence preservation
# ═══════════════════════════════════════════════════════════════════════════
phase1_inventory() {
  phase_hdr 1 "Inventory and evidence preservation"

  local manifest="$IR_ROOT/manifest.txt"
  : >"$manifest"

  local site root found
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || { warn "$site — no such directory, skipping"; continue; }
    scan_site_signatures "$root"
    found=0
    {
      printf '\n### %s\n' "$site"
      # Named artifacts anywhere in the tree.
      local pat
      for pat in "${NAME_PATTERNS[@]}"; do
        find "$root" -name "$pat" -printf '%10s  %M  %p\n' 2>/dev/null
      done
      # Signature hits in text-ish files.
      local f
      for f in "${!SIG_HITS[@]}"; do
        [[ "$f" == "$root"/* ]] || continue
        printf '%10s  %s  %s  [signature]\n' \
          "$(stat -c%s "$f" 2>/dev/null)" "$(stat -c%A "$f" 2>/dev/null)" "$f"
      done
      # Renamed-aside originals.
      find "$root" -name '*.off-*' -printf '%10s  %M  %p  [disabled by malware]\n' 2>/dev/null
    } | sort -u >>"$manifest"

    if grep -q -- "$root" "$manifest" 2>/dev/null; then
      warn "$site — indicators present"
    else
      ok "$site — clean on inventory"
    fi
  done

  info "manifest written to $manifest"

  # Evidence archive of everything we are about to touch, timestamps intact.
  hdr "Evidence archive"
  local tarball="$IR_ROOT/evidence-$STAMP.tgz"
  local -a targets=()
  local pat
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    for pat in "${NAME_PATTERNS[@]}"; do
      while IFS= read -r -d '' f; do targets+=("$f"); done \
        < <(find "$root" -name "$pat" -print0 2>/dev/null)
    done
    for f in "$root/index.php" "$root/.htaccess" "$root/.user.ini" \
             "$root/license.html" "$root/wp-config.php" \
             "$root/wp-content/advanced-cache.php"; do
      [[ -e "$f" ]] && targets+=("$f")
    done
    [[ -d "$root/images" ]] && targets+=("$root/images")
    [[ -d "$root/wp-content/mu-plugins" ]] && targets+=("$root/wp-content/mu-plugins")
  done

  if (( ${#targets[@]} == 0 )); then
    info "nothing to archive"
  elif (( DRY_RUN )); then
    info "would archive ${#targets[@]} paths to $tarball"
  else
    if tar -czpf "$tarball" --absolute-names "${targets[@]}" 2>>"$LOG"; then
      ok "archived ${#targets[@]} paths → $tarball"
    else
      warn "archive reported errors — see $LOG"
    fi
    find "${targets[@]}" -maxdepth 3 -printf '%T@ %TY-%Tm-%Td %TH:%TM:%TS %M %10s %p\n' \
      2>/dev/null | sort -rn >"$IR_ROOT/timestamps.txt"
    ok "original timestamps recorded → $IR_ROOT/timestamps.txt"
  fi
  note "Evidence archive: $tarball"
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 2 — disarm the self-repair components  (MUST run before phase 3)
# ═══════════════════════════════════════════════════════════════════════════
phase2_disarm() {
  phase_hdr 2 "Disarm self-repair components"
  info "These rewrite index.php on every page load. They go first."

  local site root p abs
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue

    scan_site_signatures "$root"
    local hit=0
    for p in "${HEALER_PATHS[@]}"; do
      abs="$root/$p"
      [[ -f "$abs" ]] || continue
      quarantine "$site" "$abs" "self-repair" && hit=1
    done

    # Any other mu-plugin carrying a signature.
    if [[ -d "$root/wp-content/mu-plugins" ]]; then
      while IFS= read -r -d '' abs; do
        [[ -f "$abs" ]] || continue
        is_malicious_content "$abs" || continue
        quarantine "$site" "$abs" "malicious mu-plugin" && hit=1
      done < <(find "$root/wp-content/mu-plugins" -maxdepth 1 -name '*.php' -print0 2>/dev/null)
    fi

    (( hit )) && ok "$site — repair components removed" || info "$site — none present"
  done
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 3 — remove gate, shells and payloads
# ═══════════════════════════════════════════════════════════════════════════
phase3_remove() {
  phase_hdr 3 "Remove gate, shells and spam payloads"

  local site root p abs
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    scan_site_signatures "$root"
    printf '%s\n' "${C_BLD}  $site${C_RESET}"

    # 3a — known toolkit paths
    for p in "${KNOWN_PATHS[@]}"; do
      abs="$root/$p"
      [[ -f "$abs" ]] && quarantine "$site" "$abs" "toolkit file"
    done

    # 3b — named artifacts anywhere (sessions, pings, gate configs)
    for p in "${NAME_PATTERNS[@]}"; do
      while IFS= read -r -d '' abs; do
        [[ -e "$abs" ]] || continue
        quarantine "$site" "$abs" "toolkit artifact"
      done < <(find "$root" -name "$p" -print0 2>/dev/null)
    done

    # 3c — any PHP in a directory where PHP must not exist (catches all shells)
    local d
    for d in "${NO_PHP_DIRS[@]}"; do
      [[ -d "$root/$d" ]] || continue
      while IFS= read -r -d '' abs; do
        is_silence_guard "$abs" && continue
        quarantine "$site" "$abs" "PHP in $d/"
      done < <(find "$root/$d" -type f \
                 \( -name '*.php' -o -name '*.phtml' -o -name '*.php5' -o -name '*.phar' \) \
                 -print0 2>/dev/null)
      # attacker .htaccess that re-permits cache.php
      while IFS= read -r -d '' abs; do
        is_malicious_content "$abs" && quarantine "$site" "$abs" "attacker .htaccess"
      done < <(find "$root/$d" -name '.htaccess' -print0 2>/dev/null)
    done

    # 3d — signature sweep across the rest of the tree
    scan_site_signatures "$root"
    for abs in "${!SIG_HITS[@]}"; do
      [[ "$abs" == "$root"/* ]] || continue
      [[ -f "$abs" ]] || continue
      # index.php / .htaccess / wp-config.php are repaired, not removed
      case "${abs#"$root"/}" in
        index.php|.htaccess|wp-config.php) continue ;;
      esac
      quarantine "$site" "$abs" "signature match"
    done

    # 3e — spam payload: only if it really is the spam page
    for p in license.html wp-content/license.html; do
      abs="$root/$p"
      [[ -f "$abs" ]] || continue
      if grep -qiE 'escort|istanbul' "$abs" 2>/dev/null; then
        quarantine "$site" "$abs" "spam payload"
      else
        warn "$site — $p present but no spam markers; left in place, review manually"
        manual "$site: review $p (no spam markers found)"
      fi
    done

    # 3f — advanced-cache.php drop-in, restoring any legitimate predecessor
    abs="$root/wp-content/advanced-cache.php"
    if [[ -f "$abs" ]] && is_malicious_content "$abs"; then
      quarantine "$site" "$abs" "malicious drop-in"
      local prev="$root/wp-content/advanced-cache.ms-prev.php"
      if [[ -f "$prev" ]]; then
        act "restore    wp-content/advanced-cache.php from .ms-prev backup"
        run mv -f "$prev" "$abs"
      fi
    fi

    # 3g — restore files the malware renamed aside
    while IFS= read -r -d '' abs; do
      local orig="${abs%%.off-*}"
      if [[ -e "$orig" ]]; then
        warn "$site — cannot restore $(basename "$abs"): target exists"
      else
        act "restore    ${orig#"$root"/}  ${C_DIM}[was disabled by malware]${C_RESET}"
        run mv -f "$abs" "$orig"
      fi
    done < <(find "$root" -name '*.off-*' -print0 2>/dev/null)
  done
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 4 — repair configuration files
# ═══════════════════════════════════════════════════════════════════════════
phase4_config() {
  phase_hdr 4 "Repair wp-config.php, .htaccess and .user.ini"

  local site root abs
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    scan_site_signatures "$root"

    # 4a — the injected WP_CACHE line that activates the early router
    abs="$root/wp-config.php"
    if [[ -f "$abs" ]] && grep -q 'Medusa early router' "$abs"; then
      act "$site — strip injected WP_CACHE line from wp-config.php"
      if (( DRY_RUN )); then
        info "would remove: $(grep -n 'Medusa early router' "$abs" | head -1)"
      else
        cp -p "$abs" "$IR_ROOT/$(echo "$site" | tr / _)-wp-config.php.bak"
        sed -i "/Medusa early router/d" "$abs"
        grep -q 'Medusa early router' "$abs" \
          && bad "$site — line still present, edit manually" \
          || ok "$site — wp-config.php cleaned"
      fi
    fi

    # 4b — .user.ini auto_prepend (the layer that actually fires on PHP-FPM)
    abs="$root/.user.ini"
    if [[ -f "$abs" ]] && grep -qE 'auto_prepend_file|ms-bot-prepend' "$abs"; then
      if [[ "$(grep -cvE '^\s*(;|#|$)' "$abs")" -le 1 ]]; then
        quarantine "$site" "$abs" "auto_prepend_file"
      else
        act "$site — strip auto_prepend_file from .user.ini (other directives kept)"
        run sed -i '/auto_prepend_file/d' "$abs"
        (( DRY_RUN )) || invalidate_signature "$abs"
      fi
    fi

    # 4c — .htaccess: strip the malware blocks, keep anything legitimate
    abs="$root/.htaccess"
    if [[ -f "$abs" ]] && is_malicious_content "$abs"; then
      act "$site — strip malware blocks from .htaccess"
      if (( DRY_RUN )); then
        info "would remove MS_DROP_SEAL / MS_BOT_JP_SHIELD / Medusa vitrin blocks"
      else
        cp -p "$abs" "$IR_ROOT/$(echo "$site" | tr / _)-htaccess.bak"
        chmod 0644 "$abs" 2>/dev/null || true
        sed -i -e '/# BEGIN MS_DROP_SEAL/,/# END MS_DROP_SEAL/d' \
               -e '/# BEGIN MS_BOT_JP_SHIELD/,/# END MS_BOT_JP_SHIELD/d' \
               -e '/Medusa vitrin/,/^<\/IfModule>/d' \
               -e '/ms-home-gate/d' \
               -e '/auto_prepend_file/d' "$abs"
        # Drop any now-empty leftover and re-read from disk, not from cache.
        sed -i -e '/^[[:space:]]*$/{/./!d}' "$abs" 2>/dev/null || true
        invalidate_signature "$abs"
        if is_malicious_content "$abs"; then
          warn "$site — .htaccess still matches a signature; quarantining whole file"
          quarantine "$site" "$abs" ".htaccess unsalvageable"
          manual "$site: regenerate .htaccess (wp rewrite flush --hard)"
        else
          ok "$site — .htaccess cleaned"
        fi
      fi
    fi

    # 4d — index.php was replaced wholesale by the gate. Restore the canonical
    #      WordPress loader here rather than waiting for phase 5, which needs a
    #      database; phase 5 then confirms it against upstream checksums.
    abs="$root/index.php"
    if [[ -f "$abs" ]] && grep -qE 'license\.html|ms-home-gate|IST_TXT_URL|sensizolmaz' "$abs"; then
      if ! is_wp "$root"; then
        warn "$site — index.php is the gate but this is not WordPress"
        manual "$site: restore index.php by hand (not a WordPress install)"
      elif (( DRY_RUN )); then
        act "$site — would replace gate index.php with the stock WordPress loader"
      else
        chmod 0644 "$abs" 2>/dev/null || true
        cp -p "$abs" "$IR_ROOT/$(echo "$site" | tr / _)-index.php.gate"
        cat >"$abs" <<'STOCK_INDEX'
<?php
/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
STOCK_INDEX
        chown "$WEB_USER:$WEB_GROUP" "$abs" 2>/dev/null || true
        invalidate_signature "$abs"
        ok "$site — index.php restored to the stock WordPress loader"
      fi
    fi

    # 4e — an .htaccess left empty by the block removal serves no purpose
    abs="$root/.htaccess"
    if [[ -f "$abs" ]] && [[ ! -s "$abs" ]]; then
      act "$site — remove .htaccess left empty after cleaning"
      run rm -f "$abs"
      if [[ "$SERVER_TYPE" != "nginx" ]] && is_wp "$root"; then
        manual "$site: regenerate rewrite rules ($WP_BIN rewrite flush --hard)"
      fi
    fi
  done
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 5 — force reinstall core, plugins, themes
# ═══════════════════════════════════════════════════════════════════════════
phase5_updates() {
  phase_hdr 5 "Force reinstall core, plugins and themes"
  (( SKIP_UPDATES )) && { info "skipped (--skip-updates)"; return; }
  (( have_wp )) || { warn "wp-cli unavailable — skipped"; return; }

  local site root
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    if ! is_wp "$root"; then info "$site — not WordPress, skipped"; continue; fi
    printf '%s\n' "${C_BLD}  $site${C_RESET}"

    if ! wpdb_ok "$root"; then
      bad "$site — no database connection; cannot update. Fix DB access and re-run phase 5."
      manual "$site: database unreachable — phases 5-7 not applied"
      (( N_ERRORS++ )); continue
    fi

    # 5a — core, forced. This also restores a pristine index.php.
    act "core update --force"
    if (( DRY_RUN )); then
      info "would run: wp core update --force && wp core update-db"
    else
      if wpx "$root" core update --force >/dev/null; then
        wpx "$root" core update-db >/dev/null
        ok "core reinstalled ($(wpx "$root" core version))"
      else
        bad "core update failed — see $LOG"; (( N_ERRORS++ ))
      fi
    fi

    # 5b — plugins, force-reinstalled from the repository so injected
    #      files inside plugin folders are overwritten.
    local -a plugins=()
    mapfile -t plugins < <(wpx "$root" plugin list --field=name 2>/dev/null)
    if (( ${#plugins[@]} )); then
      act "force-reinstall ${#plugins[@]} plugins"
      local p
      for p in "${plugins[@]}"; do
        [[ -n "$p" ]] || continue
        if (( DRY_RUN )); then
          info "would run: wp plugin install $p --force"
        else
          if wpx "$root" plugin install "$p" --force >/dev/null; then
            info "reinstalled $p"
          else
            warn "$p — not in the wordpress.org repository; verify by hand"
            manual "$site: plugin '$p' could not be reinstalled (premium/custom) — verify manually"
          fi
        fi
      done
      (( DRY_RUN )) || ok "plugins processed"
    fi

    # 5c — themes, same treatment
    local -a themes=()
    mapfile -t themes < <(wpx "$root" theme list --field=name 2>/dev/null)
    if (( ${#themes[@]} )); then
      act "force-reinstall ${#themes[@]} themes"
      local t
      for t in "${themes[@]}"; do
        [[ -n "$t" ]] || continue
        if (( DRY_RUN )); then
          info "would run: wp theme install $t --force"
        else
          wpx "$root" theme install "$t" --force >/dev/null \
            && info "reinstalled $t" \
            || { warn "$t — not in the repository; verify by hand"
                 manual "$site: theme '$t' could not be reinstalled — verify manually"; }
        fi
      done
    fi

    # 5d — language files, then verify
    run "$WP_BIN" language core update --path="$root" "${WP_FLAGS[@]}"
  done
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 6 — credentials: sessions, salts, every user password
# ═══════════════════════════════════════════════════════════════════════════
phase6_credentials() {
  phase_hdr 6 "Rotate credentials and force password changes"
  (( SKIP_PASSWORDS )) && { info "skipped (--skip-passwords)"; return; }
  (( have_wp )) || { warn "wp-cli unavailable — skipped"; return; }

  local site root
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    is_wp "$root" || continue
    wpdb_ok "$root" || { warn "$site — no DB, skipped"; continue; }
    printf '%s\n' "${C_BLD}  $site${C_RESET}"

    # 6a — administrator inventory BEFORE touching anything
    local admins="$IR_ROOT/$(echo "$site" | tr / _)-admins.txt"
    wpx "$root" user list --role=administrator \
        --fields=ID,user_login,user_email,user_registered,display_name \
        --format=table >"$admins" 2>/dev/null || true
    if [[ -s "$admins" ]]; then
      local n; n="$(( $(wc -l <"$admins") - 1 ))"
      info "$n administrator account(s) recorded → $(basename "$admins")"
      # Flag accounts created during or after the intrusion window.
      local recent
      recent="$(wpx "$root" user list --role=administrator --field=user_login \
                 --user_registered=">=2026-09-01" 2>/dev/null || true)"
      if [[ -n "$recent" ]]; then
        warn "administrators registered since 2026-09-01: $(tr '\n' ' ' <<<"$recent")"
        manual "$site: verify recent admin accounts: $(tr '\n' ' ' <<<"$recent")"
      fi
      manual "$site: review administrator list in $(basename "$admins")"
    fi

    # 6b — invalidate every existing session and cookie
    act "shuffle auth salts (logs everyone out, kills stolen cookies)"
    run "$WP_BIN" config shuffle-salts --path="$root" "${WP_FLAGS[@]}"

    act "destroy all user sessions"
    run "$WP_BIN" user session destroy --all --path="$root" "${WP_FLAGS[@]}"

    # 6c — reset every user's password
    local -a uids=()
    mapfile -t uids < <(wpx "$root" user list --field=ID 2>/dev/null)
    if (( ${#uids[@]} )); then
      local emailflag=()
      (( PASSWORD_EMAIL )) || emailflag=("--skip-email")
      act "reset passwords for ${#uids[@]} user(s)$( (( PASSWORD_EMAIL )) && printf ' (notification emails sent)' )"
      if (( DRY_RUN )); then
        info "would run: wp user reset-password ${uids[*]} ${emailflag[*]}"
      else
        if wpx "$root" user reset-password "${uids[@]}" "${emailflag[@]}" >/dev/null; then
          ok "${#uids[@]} password(s) reset"
          (( PASSWORD_EMAIL )) || manual "$site: passwords reset with --skip-email — distribute reset links yourself"
        else
          bad "password reset failed — see $LOG"; (( N_ERRORS++ ))
        fi
      fi
    fi

    # 6d — application passwords are a separate credential store
    act "revoke application passwords"
    if (( DRY_RUN )); then
      info "would revoke application passwords for all users"
    else
      local u
      for u in "${uids[@]}"; do
        wpx "$root" user application-password delete "$u" --all >/dev/null 2>&1 || true
      done
    fi
  done

  hdr "Database credentials"
  warn "wp-config.php database passwords are NOT rotated by this script."
  manual "Rotate every DB password in wp-config.php — they were readable via the shell."
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 7 — database and integrity audit
# ═══════════════════════════════════════════════════════════════════════════
phase7_audit() {
  phase_hdr 7 "Database and integrity audit"
  (( have_wp )) || { warn "wp-cli unavailable — skipped"; return; }

  local site root
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue
    is_wp "$root" || continue
    printf '%s\n' "${C_BLD}  $site${C_RESET}"

    if ! wpdb_ok "$root"; then warn "no DB connection, skipped"; continue; fi
    local out="$IR_ROOT/$(echo "$site" | tr / _)-audit.txt"
    : >"$out"

    # 7a — core checksums
    {
      printf '## core verify-checksums\n'
      wpx "$root" core verify-checksums 2>&1 || true
      printf '\n## plugin verify-checksums\n'
      wpx "$root" plugin verify-checksums --all 2>&1 || true
      printf '\n## siteurl / home\n'
      wpx "$root" option get siteurl 2>&1
      wpx "$root" option get home 2>&1
      printf '\n## active plugins\n'
      wpx "$root" option get active_plugins --format=json 2>&1
      printf '\n## mu-plugins on disk\n'
      ls -la "$root/wp-content/mu-plugins" 2>&1 || true
      printf '\n## scheduled events\n'
      wpx "$root" cron event list --format=table 2>&1 || true
      printf '\n## users\n'
      wpx "$root" user list --fields=ID,user_login,user_email,roles,user_registered --format=table 2>&1
    } >>"$out"

    if wpx "$root" core verify-checksums >/dev/null 2>&1; then
      ok "core verifies against checksums"
    else
      warn "core checksum mismatch — see $(basename "$out")"
      manual "$site: core checksum mismatch, inspect $(basename "$out")"
    fi

    # 7b — hunt the spam domains in the database
    local dbhits
    dbhits="$(wpx "$root" db search 'istanbulescortladys\|sensizolmaz\|onlineresmigiris\|X-Medusa' \
               --all-tables --stats 2>/dev/null | tail -5 || true)"
    if [[ -n "$dbhits" ]] && ! grep -qi 'Success: Found 0' <<<"$dbhits"; then
      warn "spam domain references found in the database"
      printf '%s\n' "$dbhits" | tee -a "$out" >/dev/null
      manual "$site: DB contains spam-domain references — inspect $(basename "$out")"
    else
      ok "no spam domains in the database"
    fi

    # 7c — clear caches and transients so nothing malicious is served from cache
    act "flush caches, transients and rewrite rules"
    run "$WP_BIN" transient delete --all --path="$root" "${WP_FLAGS[@]}"
    run "$WP_BIN" cache flush --path="$root" "${WP_FLAGS[@]}"
    if [[ "$SERVER_TYPE" == "nginx" ]]; then
      run "$WP_BIN" rewrite flush --path="$root" "${WP_FLAGS[@]}"
    else
      run "$WP_BIN" rewrite flush --hard --path="$root" "${WP_FLAGS[@]}"
    fi

    info "audit written to $out"
  done
}

# ═══════════════════════════════════════════════════════════════════════════
#  PHASE 8 — hardening and permissions
# ═══════════════════════════════════════════════════════════════════════════
phase8_harden() {
  phase_hdr 8 "Permissions and hardening"

  local site root
  for site in "${SITES[@]}"; do
    root="$(site_root "$site")"
    [[ -d "$root" ]] || continue

    act "$site — reset ownership and permissions"
    if (( DRY_RUN )); then
      info "would chown -R $WEB_USER:$WEB_GROUP and set dirs 755 / files 644"
    else
      chown -R "$WEB_USER:$WEB_GROUP" "$root" 2>>"$LOG" || warn "chown incomplete"
      find "$root" -type d -exec chmod 755 {} + 2>>"$LOG" || true
      find "$root" -type f -exec chmod 644 {} + 2>>"$LOG" || true
      [[ -f "$root/wp-config.php" ]] && chmod 640 "$root/wp-config.php"
      ok "$site — permissions normalised"
    fi

    # No per-directory .htaccess is written: nginx never reads it, and an
    # extra .htaccess here would only muddy the next investigation. PHP
    # execution is blocked centrally — audited below.
    if [[ "$SERVER_TYPE" != "nginx" ]]; then
      local d
      for d in wp-content/uploads images; do
        [[ -d "$root/$d" ]] || continue
        if (( DRY_RUN )); then
          info "would write deny-PHP .htaccess in $d/"
        else
          printf '%s\n' '<FilesMatch "\.(?i:php|phtml|php[0-9]|phar|inc)$">' \
            '  Require all denied' '</FilesMatch>' >"$root/$d/.htaccess"
          chown "$WEB_USER:$WEB_GROUP" "$root/$d/.htaccess"
        fi
      done
    fi
  done

  # ── Audit the shared nginx include against this incident ────────────
  if [[ "$SERVER_TYPE" == "nginx" ]]; then
    hdr "nginx hardening audit"

    local inc=""
    local cand
    for cand in "$NGINX_INCLUDE" "$NGINX_INCLUDE_ALT"; do
      [[ -r "$cand" ]] && { inc="$cand"; break; }
    done

    if [[ -z "$inc" ]]; then
      warn "no wordpress-restrictions.conf found at either:"
      info "$NGINX_INCLUDE"
      info "$NGINX_INCLUDE_ALT"
      manual "Locate the shared nginx include and audit it by hand"
    else
      info "auditing $inc"

      # The directories the sites actually write into, versus those the
      # include refuses PHP in. `images` was the gap in this incident.
      local -a want_dirs=(uploads files images media cache upgrade tmp backup)
      local -a missing=()
      local d php_locs
      # Location lines that restrict PHP, comments stripped. A single combined
      # rule using (?:php[0-9]?|phtml) must count for every directory it names.
      php_locs="$(grep -vE '^[[:space:]]*#' "$inc" 2>/dev/null \
                  | grep -E 'location[^{]*(php|phtml|phar)' || true)"
      for d in "${want_dirs[@]}"; do
        grep -qE "(^|[^a-z])$d([^a-z]|$)" <<<"$php_locs" || missing+=("$d")
      done
      if (( ${#missing[@]} )); then
        bad "PHP execution NOT denied in: ${missing[*]}"
        info "this is how /images/images/cache.php stayed executable"
        manual "nginx: add ${missing[*]} to the PHP deny rule in $inc"
      else
        ok "PHP denied in all expected asset directories"
      fi

      # An empty location block has no fastcgi_pass, so nginx falls back to
      # the static handler and serves PHP SOURCE instead of denying it.
      local empties
      empties="$(grep -vE '^[[:space:]]*#' "$inc" 2>/dev/null | awk '
        /location/ && /\{/ { inloc=1; name=$0; has=0; next }
        inloc && /\}/ { if (!has) { sub(/^[ \t]+/,"",name); print name }; inloc=0; next }
        inloc { l=$0; sub(/#.*/,"",l); gsub(/[ \t]/,"",l); if (l != "") has=1 }
      ' 2>/dev/null)"
      if [[ -n "$empties" ]]; then
        bad "empty location block(s) — these serve PHP SOURCE, not a deny:"
        while IFS= read -r l; do [[ -n "$l" ]] && info "$l"; done <<<"$empties"
        manual "nginx: empty location block in $inc needs 'deny all;' or removal"
      else
        ok "no empty location blocks"
      fi

      # Dotfiles: blocks .user.ini, .ms_*, .fm_*, .ms_index.bak.php
      if grep -qE 'location[^{]*/\\\.' "$inc" 2>/dev/null; then
        ok "dotfiles denied"
      else
        warn "dotfiles are servable (.user.ini, .ms_*, .fm_*)"
        manual "nginx: add a dotfile deny rule to $inc"
      fi

      grep -q 'xmlrpc' "$inc" 2>/dev/null \
        && ok "xmlrpc.php denied" \
        || manual "nginx: deny /xmlrpc.php in $inc"

      local proposed="$SCRIPT_DIR/wordpress-restrictions.conf.proposed"
      if [[ -r "$proposed" ]]; then
        info "revised include ready for review: $proposed"
        manual "Review $proposed, then: nginx -t && systemctl reload nginx"
      fi
    fi

    # ── PHP-FPM: the only place the .user.ini vector can be closed ────
    hdr "PHP-FPM audit"
    local fpm_ok=0
    if [[ -d "$FPM_POOL_DIR" ]]; then
      if grep -rqE 'user_ini\.filename[[:space:]]*=[[:space:]]*$|auto_prepend_file' "$FPM_POOL_DIR" 2>/dev/null; then
        ok "pool config mentions user_ini.filename / auto_prepend_file"
        fpm_ok=1
      fi
    else
      info "pool directory $FPM_POOL_DIR not found on this host"
    fi
    if (( ! fpm_ok )); then
      bad ".user.ini is still honoured — the persistence vector is OPEN"
      info "nginx cannot close this; PHP-FPM reads .user.ini off disk"
      manual "PHP-FPM: set 'php_admin_value[user_ini.filename] =' in the pool — see php-fpm-hardening.ini"
    fi
    local fpmconf="$SCRIPT_DIR/php-fpm-hardening.ini"
    [[ -r "$fpmconf" ]] && info "suggested pool settings: $fpmconf"
  fi
}

# ═══════════════════════════════════════════════════════════════════════════
#  Closing report
# ═══════════════════════════════════════════════════════════════════════════
final_report() {
  {
    printf '\n'
    printf '═══════════════════════════════════════════════════════════════════\n'
    printf ' Medusa eradication report — %s\n' "$(date -Is)"
    printf ' Incident MDA-2026-09-22    host %s\n' "$(hostname)"
    printf '═══════════════════════════════════════════════════════════════════\n\n'
    printf ' Mode                 %s\n' "$( (( DRY_RUN )) && echo 'DRY RUN — nothing changed' || echo 'EXECUTED' )"
    printf ' Phases run           %s-%s\n' "$PHASE_FROM" "$PHASE_TO"
    printf ' Sites processed      %s  (%s WordPress)\n' "$N_SITES" "$N_WP"
    printf ' Files quarantined    %s\n' "$N_QUARANTINED"
    printf ' Errors               %s\n' "$N_ERRORS"
    printf ' Evidence + logs      %s\n' "$IR_ROOT"
    printf '\n'

    if (( ${#MANUAL_ITEMS[@]} )); then
      printf ' ─── REQUIRES A HUMAN ──────────────────────────────────────────\n'
      local i
      for i in "${MANUAL_ITEMS[@]}"; do printf '  • %s\n' "$i"; done
      printf '\n'
    fi

    printf ' ─── NOT DONE BY THIS SCRIPT ───────────────────────────────────\n'
    cat <<'EOF'
  • Rotating database passwords in wp-config.php  (shell could read them)
  • Rotating SMTP / API keys stored in site config
  • Deleting unrecognised administrator accounts   (reported, never auto-removed)
  • Reviewing /var/www-break/mailing-lists/public for a personal-data breach
  • Requesting Google Search Console reconsideration after verification
  • Determining the original entry vector — needs logs beyond 22 Sep 20:37
EOF
    printf '\n'
    printf ' ─── VERIFY AFTERWARDS ─────────────────────────────────────────\n'
    sed "s/WPBIN/$WP_BIN/" <<'EOF'
  curl -sI https://<site>/ | grep -i -e location -e x-medusa
      → expect NO redirect and no X-Medusa header

  curl -s -A 'Googlebot/2.1' https://<site>/ | grep -ci escort
      → expect 0

  WPBIN core verify-checksums --path=/var/www/<site>
      → expect "Success"
EOF
    printf '\n'
    printf ' Re-run the scanner to confirm nothing returned:\n'
    printf '   %s --site <name>        # dry run shows any remaining indicators\n' "$0"
    printf '\n'
  } | tee -a "$REPORT"

  printf '%s\n' "${C_BLD}Report saved to $REPORT${C_RESET}"
  if (( DRY_RUN )); then
    printf '\n%s\n' "${C_YEL}${C_BLD}This was a DRY RUN. Nothing changed.${C_RESET}"
    printf '%s\n' "${C_YEL}Review the output above, then re-run with --execute.${C_RESET}"
  fi
}

# ═══════════════════════════════════════════════════════════════════════════
#  Main
# ═══════════════════════════════════════════════════════════════════════════

if (( ! DRY_RUN )); then
  hdr "Confirmation"
  cat <<EOF
About to modify ${#SITES[@]} site(s) under $WEB_ROOT.

  • Toolkit files moved to $QUARANTINE
  • Core, plugins and themes force-reinstalled
  • Every user password reset, all sessions destroyed
  • Ownership and permissions reset

Take the sites offline first if you can. The self-repair components are removed
in phase 2, but any request served between now and then can rewrite index.php.
EOF
  read -r -p "Type CLEAN to proceed: " confirm
  [[ "$confirm" == "CLEAN" ]] || { echo "Aborted."; exit 1; }
fi

phase_enabled 1 && phase1_inventory
phase_enabled 2 && phase2_disarm
phase_enabled 3 && phase3_remove
phase_enabled 4 && phase4_config
phase_enabled 5 && phase5_updates
phase_enabled 6 && phase6_credentials
phase_enabled 7 && phase7_audit
phase_enabled 8 && phase8_harden

final_report
exit 0
