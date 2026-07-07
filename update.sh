#!/usr/bin/env bash
# =============================================================================
#  update.sh — safe, idempotent update for the pause-groups app (Fedora CoreOS)
# =============================================================================
#
#  WHAT IT DOES (in order):
#    1. Backs up the database (a CONSISTENT, VALIDATED snapshot) + the encryption
#       key, and records the currently-deployed git commit — into a timestamped
#       folder under /var/persist/pause-groups-backups/, and marks it as the
#       latest update so revert.sh can find it.
#    2. git pull in the source clone (/var/persist/pause-groups-src).
#    3. Syncs the new code into the live app dir, PRESERVING data/ and .env.
#    4. Removes install.php / fresh_install.php from the web root (security).
#    5. Runs the database migrations (idempotent, non-destructive).
#    6. Restarts PHP-FPM so the new code goes live.
#    7. Health-checks the app.
#
#  Your database, users, API keys and encryption key are preserved. Safe to
#  re-run. If an update goes wrong, roll it back with:  sudo bash revert.sh
#
#  USAGE:
#    sudo bash update.sh            # normal update
#    sudo bash update.sh --force    # discard uncommitted edits in the source
#                                   # clone (git reset --hard + clean) first
# =============================================================================

# --- Re-exec from a private copy -------------------------------------------
# The `git pull` below can rewrite THIS script mid-run (update.sh ships in the
# repo). Running from a temp copy makes the in-flight script immutable.
if [[ "${_PG_UPDATE_REEXEC:-}" != "1" ]]; then
    _self_copy="$(mktemp /tmp/pg-update.XXXXXX.sh)"
    cp -- "$0" "$_self_copy"
    export _PG_UPDATE_REEXEC=1
    exec bash "$_self_copy" "$@"
fi

set -euo pipefail

# --- Colours / helpers ------------------------------------------------------
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; BLU='\033[0;34m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
info() { echo -e "${BLU}[INFO]${NC}  $*"; }
ok()   { echo -e "${GRN}[OK]${NC}    $*"; }
warn() { echo -e "${YLW}[WARN]${NC}  $*"; }
die()  { echo -e "${RED}[FATAL]${NC} $*" >&2; exit 1; }
note() { echo -e "${DIM}         $*${NC}"; }
hdr()  { echo -e "\n${BOLD}── $* ${NC}"; }

# Track how far we got so the ERR trap can tell the operator whether the box was
# actually touched (backup/pull phase = nothing changed; sync onward = new code
# may be on disk and a revert is the clean way back).
PHASE="startup"
trap 'rc=$?; if [[ "$PHASE" == "pre-sync" ]]; then
        echo -e "\n${RED}update.sh failed at line ${LINENO} (before any code was changed).${NC} Nothing was modified — fix the cause and re-run (this script is idempotent)." >&2
      else
        echo -e "\n${RED}update.sh failed at line ${LINENO} during \"${PHASE}\".${NC} New code may already be on disk.\n  • Restore the pre-update state with:  sudo bash revert.sh\n  • Or fix the cause and re-run (this script is idempotent)." >&2
      fi; exit $rc' ERR

# --- Configuration (matches setup-fcos.sh) ---------------------------------
INSTALL_DIR="/var/persist/pause-groups"
SRC_DIR="/var/persist/pause-groups-src"
DATA_DIR="${INSTALL_DIR}/data"
ENV_FILE="${INSTALL_DIR}/.env"
DB_FILE="${DATA_DIR}/pause_groups.db"
DEPLOYED_MARKER="${INSTALL_DIR}/.deployed_commit"
BACKUP_ROOT="/var/persist/pause-groups-backups"
LATEST_MARKER="${BACKUP_ROOT}/LATEST_UPDATE"
FPM_SERVICE="pause-groups-fpm"
KEEP_BACKUPS=15

# --- Options ----------------------------------------------------------------
FORCE=0
for arg in "$@"; do
    case "$arg" in
        --force|-f) FORCE=1 ;;
        --help|-h)  sed -n '2,20p' "$0"; exit 0 ;;
        *) die "Unknown option '${arg}'. Usage: sudo bash update.sh [--force]" ;;
    esac
done

# Detect the PHP image from the installed FPM unit so we match the operator's
# chosen PHP version/variant; fall back to the setup-fcos.sh default. Match the
# FULL image token (including any -fpm-<variant> suffix) rather than stopping at
# "-fpm", so a pinned variant like php:8.3-fpm-alpine is preserved.
PHP_IMAGE="$(grep -ohE '(docker\.io/)?(library/)?php:[A-Za-z0-9._-]+' \
    "/etc/systemd/system/${FPM_SERVICE}.service" 2>/dev/null | head -1 || true)"
PHP_IMAGE="${PHP_IMAGE:-docker.io/library/php:8.3-fpm}"

echo -e "${BOLD}${GRN}pause-groups — update${NC}"
echo "  App:    ${INSTALL_DIR}"
echo "  Source: ${SRC_DIR}"
echo "  Image:  ${PHP_IMAGE}"
[[ $FORCE -eq 1 ]] && echo -e "  Mode:   ${YLW}--force (local source edits will be discarded)${NC}"
echo ""

PHASE="pre-sync"

# --- Pre-flight -------------------------------------------------------------
[[ $EUID -eq 0 ]] || die "Must run as root:  sudo bash update.sh"
command -v podman &>/dev/null || die "podman not found (expected on Fedora CoreOS)."
[[ -d "$SRC_DIR/.git" ]] || die "Source clone not found at ${SRC_DIR}. Clone the repo there first (see INSTALL-FCOS.md)."
[[ -d "$INSTALL_DIR" ]] || die "App dir ${INSTALL_DIR} missing. Run setup-fcos.sh for a first-time install."
[[ -f "$DB_FILE" ]] || die "No database at ${DB_FILE}. Run setup-fcos.sh for a first-time install."
systemctl list-unit-files "${FPM_SERVICE}.service" &>/dev/null \
    || die "${FPM_SERVICE} is not installed. Run setup-fcos.sh for a first-time install."

# Uncommitted local edits in the source clone would be lost by the pull or cause
# a merge conflict. Refuse by default; --force discards them deliberately.
if [[ -n "$(git -C "$SRC_DIR" status --porcelain 2>/dev/null)" ]]; then
    if [[ $FORCE -eq 1 ]]; then
        warn "Discarding uncommitted local changes in ${SRC_DIR} (--force)."
        git -C "$SRC_DIR" reset --hard 2>&1 | sed 's/^/    /' || true
        git -C "$SRC_DIR" clean -fd 2>&1 | sed 's/^/    /' || true
    else
        die "Source clone has uncommitted local changes.\n  Review:  git -C ${SRC_DIR} status\n  Then commit/stash them, or re-run to discard:  sudo bash update.sh --force"
    fi
fi

BRANCH="$(git -C "$SRC_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo HEAD)"
if [[ "$BRANCH" == "HEAD" ]]; then
    # Detached HEAD is what revert.sh leaves behind. --force reattaches to the
    # remote's default branch so a forced update can recover from that state.
    if [[ $FORCE -eq 1 ]]; then
        DEF="$(git -C "$SRC_DIR" symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##' || true)"
        DEF="${DEF:-main}"
        warn "Source clone is on a detached commit; checking out '${DEF}' (--force)."
        git -C "$SRC_DIR" checkout "$DEF" 2>&1 | sed 's/^/    /' || die "Could not checkout ${DEF}. Run:  git -C ${SRC_DIR} checkout ${DEF}   then re-run."
        BRANCH="$DEF"
    else
        die "Source clone is on a detached commit (left by a previous revert?). Run:  git -C ${SRC_DIR} checkout main   then re-run update.sh (or use --force)."
    fi
fi

# The commit currently DEPLOYED is what we last wrote into INSTALL_DIR, recorded
# in the marker file — NOT the source clone's HEAD, which revert.sh moves
# independently. Fall back to the clone's HEAD for installs that predate the
# marker. This is the commit revert.sh will restore code to.
if [[ -f "$DEPLOYED_MARKER" ]] && [[ -s "$DEPLOYED_MARKER" ]]; then
    FROM_SHA="$(head -n1 "$DEPLOYED_MARKER" | tr -d '[:space:]')"
else
    FROM_SHA="$(git -C "$SRC_DIR" rev-parse HEAD)"
fi

# --- SQLite validation helpers ---------------------------------------------
# Cheap, container-free check that a file is a non-empty SQLite database. Catches
# the silent killer: a 0-byte / truncated snapshot that would otherwise pass a
# bare `-f` test and later overwrite good data.
db_header_ok() {
    local f="$1" sz hdr
    [[ -f "$f" ]] || return 1
    sz="$(stat -c%s "$f" 2>/dev/null || echo 0)"
    (( sz >= 512 )) || return 1
    hdr="$(head -c 15 "$f" 2>/dev/null)"
    [[ "$hdr" == "SQLite format 3" ]]
}
# Deep validation in the PHP container: integrity_check must be 'ok' AND the
# admin_users sentinel table must have at least one row (a wiped/empty DB fails).
db_deep_valid() {
    local f="$1" dir
    dir="$(dirname "$f")"
    podman run --rm --network none -v "${dir}:${dir}:z" "$PHP_IMAGE" \
        php -r '$f=$argv[1];
            $s=new SQLite3($f, SQLITE3_OPEN_READONLY); $s->enableExceptions(true);
            if ($s->querySingle("PRAGMA integrity_check") !== "ok") { fwrite(STDERR,"integrity_check failed\n"); exit(3); }
            if ((int)$s->querySingle("SELECT COUNT(*) FROM admin_users") < 1) { fwrite(STDERR,"admin_users empty\n"); exit(4); }
            $s->close();' "$f" 2>/dev/null
}

# Ensure the PHP image is present locally BEFORE we depend on it. We need it for
# a consistent VACUUM snapshot and for the migration; pulling it up front means
# no step silently degrades to a hot copy and we fail early (before any change)
# if the box is offline.
if ! podman image exists "$PHP_IMAGE" 2>/dev/null; then
    info "Pulling PHP image ${PHP_IMAGE} (needed for a consistent backup + migrations)…"
    podman pull "$PHP_IMAGE" >/dev/null 2>&1 \
        || die "Could not pull ${PHP_IMAGE}. Check connectivity, then re-run. (Nothing has changed.)"
fi

# =============================================================================
#  1. Backup (BEFORE anything changes)
# =============================================================================
hdr "1/6  Backup database + key"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
BK="${BACKUP_ROOT}/update-${STAMP}"
mkdir -p "$BK"

# Consistent single-file DB snapshot via SQLite VACUUM INTO, run in the PHP
# container (the FCOS host has no sqlite3 CLI). enableExceptions() makes a failed
# VACUUM return non-zero instead of silently exiting 0 with no output.
if ! podman run --rm --network none \
        -v "${INSTALL_DIR}:${INSTALL_DIR}:z" \
        -v "${BACKUP_ROOT}:${BACKUP_ROOT}:z" \
        "$PHP_IMAGE" \
        php -r '$s=new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $s->enableExceptions(true); $s->busyTimeout(60000); $s->exec("VACUUM INTO ".chr(39).$argv[2].chr(39)); $s->close();' \
        "$DB_FILE" "${BK}/pause_groups.db"; then
    rm -rf "$BK"
    die "Consistent DB snapshot (VACUUM INTO) failed — aborting before any change."
fi

# Validate the snapshot we just wrote before trusting it. A backup that isn't a
# valid, non-empty SQLite DB is worse than useless — revert.sh would later
# restore it over the live database.
db_header_ok "${BK}/pause_groups.db" \
    || { rm -rf "$BK"; die "Backup snapshot is not a valid SQLite file — aborting before any change."; }
db_deep_valid "${BK}/pause_groups.db" \
    || { rm -rf "$BK"; die "Backup snapshot failed integrity/row-count validation — aborting before any change."; }
ok "Consistent DB snapshot written + validated (VACUUM INTO)."

if [[ -f "$ENV_FILE" ]]; then cp -a "$ENV_FILE" "${BK}/env.bak"; fi
# Record what was deployed so revert.sh can restore both DB AND code.
printf '%s\n' "$FROM_SHA" > "${BK}/deployed_commit.txt"
{
    echo "created_utc=${STAMP}"
    echo "branch=${BRANCH}"
    echo "from_commit=${FROM_SHA}"
    echo "php_image=${PHP_IMAGE}"
} > "${BK}/manifest.txt"
chmod -R go-rwx "$BK" || true
printf '%s\n' "$BK" > "$LATEST_MARKER"
ok "Backup: ${BK}"
note "Deployed commit recorded: ${FROM_SHA:0:12}"

# Prune old backups (keep newest KEEP_BACKUPS of each kind: update-* AND the
# pre-revert-* snapshots revert.sh leaves behind, so neither grows unbounded).
prune_backups() {
    local prefix="$1" keep="$2" dirs=() i
    while IFS= read -r d; do [[ -n "$d" ]] && dirs+=("$d"); done \
        < <(ls -1dt "${BACKUP_ROOT}/${prefix}"-*/ 2>/dev/null || true)
    if (( ${#dirs[@]} > keep )); then
        for (( i=keep; i<${#dirs[@]}; i++ )); do rm -rf "${dirs[$i]}"; done
        note "Pruned old ${prefix} backups (kept ${keep})."
    fi
}
prune_backups update "$KEEP_BACKUPS"
prune_backups pre-revert "$KEEP_BACKUPS"

# =============================================================================
#  2. Pull
# =============================================================================
hdr "2/6  git pull (${BRANCH})"
pulled=0
for attempt in 1 2 3 4; do
    if git -C "$SRC_DIR" pull --ff-only 2>&1; then pulled=1; break; fi
    warn "pull failed (attempt ${attempt}); retrying..."; sleep $((2**attempt))
done
[[ $pulled -eq 1 ]] || die "git pull failed. If the branch diverged, reconcile it manually, then re-run."
TO_SHA="$(git -C "$SRC_DIR" rev-parse HEAD)"
if [[ "$TO_SHA" == "$FROM_SHA" ]]; then
    info "Deployed commit unchanged (${TO_SHA:0:12}). Re-syncing anyway to be safe."
else
    ok "Updated ${FROM_SHA:0:12} → ${TO_SHA:0:12}"
fi

# =============================================================================
#  3. Sync code into the live app dir (preserving data/ and .env)
# =============================================================================
hdr "3/6  Sync app files"
PHASE="sync app files"
if command -v rsync &>/dev/null; then
    rsync -a --delete \
        --exclude='.git/' --exclude='data/' --exclude='.env' --exclude='.deployed_commit' \
        "${SRC_DIR}/" "${INSTALL_DIR}/"
else
    # No rsync: DON'T cp the source straight over the app dir — a gitignored
    # data/ or .env in the clone would clobber the live DB and key. Stage a
    # pruned copy first, then mirror that in (this skips --delete semantics, so
    # files removed upstream linger until the next rsync-capable run).
    warn "rsync not found — using a staged copy (upstream deletions won't propagate)."
    _stage="$(mktemp -d /tmp/pg-stage.XXXXXX)"
    cp -a "${SRC_DIR}/." "${_stage}/"
    rm -rf "${_stage}/.git" "${_stage}/data" "${_stage}/.env" "${_stage}/.deployed_commit"
    cp -a "${_stage}/." "${INSTALL_DIR}/"
    rm -rf "${_stage}"
fi
ok "App files synced (data/ and .env untouched)."

# Record the commit now actually deployed, so a future revert restores THIS code
# regardless of where the source clone's HEAD later points.
printf '%s\n' "$TO_SHA" > "$DEPLOYED_MARKER"

# =============================================================================
#  4. Security cleanup — never leave installers in the web root
# =============================================================================
hdr "4/6  Remove installers from web root"
for f in install.php fresh_install.php; do
    if [[ -f "${INSTALL_DIR}/${f}" ]]; then rm -f "${INSTALL_DIR}/${f}"; ok "Removed ${f}"; fi
done

# --- Fix ownership (data writable by the container's uid 33) ---------------
chown -R 33:33 "$DATA_DIR"; chmod 770 "$DATA_DIR"
chmod -R o+rX "$INSTALL_DIR"
[[ -f "$ENV_FILE" ]] && chmod o-rX "$ENV_FILE" || true

# =============================================================================
#  5. Run database migrations (idempotent, non-destructive)
# =============================================================================
hdr "5/6  Migrate database"
PHASE="migrate database"
# DB::getInstance() runs CREATE TABLE IF NOT EXISTS + the ALTER/data migrations.
podman run --rm --network host \
    --env-file "$ENV_FILE" \
    -v "${INSTALL_DIR}:${INSTALL_DIR}:z" \
    -w "$INSTALL_DIR" -u 33:33 \
    "$PHP_IMAGE" \
    php -r 'require "config.php"; require "lib/db.php"; DB::getInstance(); fwrite(STDERR, "schema-ok\n");' \
    && ok "Schema migrated." || die "Migration step failed — restore with: sudo bash revert.sh"

# =============================================================================
#  6. Restart + health check
# =============================================================================
hdr "6/6  Restart + verify"
PHASE="restart"
systemctl restart "$FPM_SERVICE" || warn "systemctl restart ${FPM_SERVICE} returned non-zero."
sleep 4
if systemctl is-active --quiet "$FPM_SERVICE"; then ok "${FPM_SERVICE} is active."; else warn "${FPM_SERVICE} not active — check: journalctl -eu ${FPM_SERVICE}"; fi

resp="$(curl -s --max-time 6 http://localhost/api/health 2>/dev/null || true)"
if echo "$resp" | grep -q '"database":true'; then
    ok "App responding, database OK."
else
    warn "Health check inconclusive (app may still be warming up, or Nginx not set up)."
    note "Response: ${resp:-<none>}"
fi

echo ""
echo -e "${BOLD}${GRN}Update complete.${NC}"
echo "  ${FROM_SHA:0:12} → ${TO_SHA:0:12}"
echo "  Backup:   ${BK}"
echo "  Roll back this update:   sudo bash revert.sh"
echo ""
note "In the browser, hard-refresh (Ctrl+Shift+R / Cmd+Shift+R) to pick up new"
note "CSS/JS — /public assets are cached for up to 1 hour."
