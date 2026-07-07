#!/usr/bin/env bash
# =============================================================================
#  revert.sh — roll back a pause-groups update (Fedora CoreOS)
# =============================================================================
#
#  Restores the database, encryption key, AND application code to the state
#  captured by update.sh's most recent backup (or a backup you name). Use this
#  when an update misbehaves.
#
#  WHAT IT DOES:
#    1. Picks the backup to restore (latest update by default).
#    2. Snapshots the CURRENT (possibly broken) state first, so the revert
#       itself is reversible.
#    3. Stops PHP-FPM + the timers so nothing writes during the restore.
#    4. Rolls the code back to the commit that was live before the update
#       (git checkout in the source clone + re-sync into the app dir).
#    5. Restores the encryption key and the database from the backup.
#    6. Restarts services and health-checks.
#
#  USAGE:
#    sudo bash revert.sh                 # roll back the most recent update
#    sudo bash revert.sh <timestamp>     # e.g. 20260707-193000
#    sudo bash revert.sh /var/persist/pause-groups-backups/update-XX…
#    sudo bash revert.sh --list          # list available backups
# =============================================================================

# Re-exec from a private copy — the code rollback below (git checkout) can
# rewrite this script mid-run.
if [[ "${_PG_REVERT_REEXEC:-}" != "1" ]]; then
    _self_copy="$(mktemp /tmp/pg-revert.XXXXXX.sh)"
    cp -- "$0" "$_self_copy"
    export _PG_REVERT_REEXEC=1
    exec bash "$_self_copy" "$@"
fi

set -euo pipefail

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; BLU='\033[0;34m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
info() { echo -e "${BLU}[INFO]${NC}  $*"; }
ok()   { echo -e "${GRN}[OK]${NC}    $*"; }
warn() { echo -e "${YLW}[WARN]${NC}  $*"; }
die()  { echo -e "${RED}[FATAL]${NC} $*" >&2; exit 1; }
note() { echo -e "${DIM}         $*${NC}"; }
hdr()  { echo -e "\n${BOLD}── $* ${NC}"; }

INSTALL_DIR="/var/persist/pause-groups"
SRC_DIR="/var/persist/pause-groups-src"
DATA_DIR="${INSTALL_DIR}/data"
ENV_FILE="${INSTALL_DIR}/.env"
DB_FILE="${DATA_DIR}/pause_groups.db"
BACKUP_ROOT="/var/persist/pause-groups-backups"
LATEST_MARKER="${BACKUP_ROOT}/LATEST_UPDATE"
FPM_SERVICE="pause-groups-fpm"
TIMERS=("pause-groups-watchdog.timer" "pause-groups-daily.timer")
SERVICES=("pause-groups-watchdog.service" "pause-groups-daily.service")

list_backups() {
    echo "Available backups under ${BACKUP_ROOT}:"
    local d ts sha
    for d in $(ls -1dt "${BACKUP_ROOT}"/update-*/ 2>/dev/null || true); do
        ts="$(grep -oE 'created_utc=.*' "${d}manifest.txt" 2>/dev/null | cut -d= -f2 || true)"
        sha="$(head -c 12 "${d}deployed_commit.txt" 2>/dev/null || true)"
        printf "  %-70s  commit %s\n" "$d" "${sha:-?}"
    done
}

[[ $EUID -eq 0 ]] || die "Must run as root:  sudo bash revert.sh"

if [[ "${1:-}" == "--list" ]]; then list_backups; exit 0; fi

# --- Choose the backup ------------------------------------------------------
BK=""
if [[ -n "${1:-}" ]]; then
    if   [[ -d "$1" ]]; then BK="$1"
    elif [[ -d "${BACKUP_ROOT}/update-$1" ]]; then BK="${BACKUP_ROOT}/update-$1"
    elif [[ -d "${BACKUP_ROOT}/$1" ]]; then BK="${BACKUP_ROOT}/$1"
    else die "No backup matching '$1'. Try:  sudo bash revert.sh --list"; fi
elif [[ -f "$LATEST_MARKER" ]] && [[ -d "$(cat "$LATEST_MARKER")" ]]; then
    BK="$(cat "$LATEST_MARKER")"
else
    BK="$(ls -1dt "${BACKUP_ROOT}"/update-*/ 2>/dev/null | head -1 || true)"
fi
BK="${BK%/}"
[[ -n "$BK" && -d "$BK" ]] || { list_backups; die "No backup found. Name one explicitly."; }
[[ -f "${BK}/pause_groups.db" ]] || die "Backup ${BK} has no pause_groups.db — cannot restore."

RESTORE_SHA="$(head -n1 "${BK}/deployed_commit.txt" 2>/dev/null || true)"

# --- Confirm (destructive) --------------------------------------------------
echo -e "${BOLD}${YLW}This will roll back to a previous backup.${NC}"
echo "  Backup:          ${BK}"
echo "  Restore DB from: ${BK}/pause_groups.db  ($(du -h "${BK}/pause_groups.db" | cut -f1))"
echo "  Restore code to: ${RESTORE_SHA:0:12}${RESTORE_SHA:+ (git commit)}"
echo ""
warn "The current database will be REPLACED (a safety snapshot is taken first)."
read -r -p "Type 'yes' to roll back: " confirm
[[ "$confirm" == "yes" ]] || { echo "Aborted."; exit 0; }

# --- Snapshot the current state first (so the revert is reversible) --------
hdr "1/5  Snapshot current state"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
PRE="${BACKUP_ROOT}/pre-revert-${STAMP}"
mkdir -p "$PRE"
if [[ -f "$DB_FILE" ]]; then
    cp -a "$DB_FILE" "${PRE}/pause_groups.db"
    if [[ -f "${DB_FILE}-wal" ]]; then cp -a "${DB_FILE}-wal" "${PRE}/pause_groups.db-wal"; fi
    if [[ -f "${DB_FILE}-shm" ]]; then cp -a "${DB_FILE}-shm" "${PRE}/pause_groups.db-shm"; fi
fi
[[ -f "$ENV_FILE" ]] && cp -a "$ENV_FILE" "${PRE}/env.bak" || true
chmod -R go-rwx "$PRE" || true
ok "Current state saved to ${PRE}"

# --- Stop writers -----------------------------------------------------------
hdr "2/5  Stop services"
systemctl stop "${TIMERS[@]}" 2>/dev/null || true
systemctl stop "${SERVICES[@]}" 2>/dev/null || true
systemctl stop "$FPM_SERVICE" 2>/dev/null || true
ok "PHP-FPM and timers stopped."

# --- Roll back the code -----------------------------------------------------
hdr "3/5  Restore application code"
if [[ -n "$RESTORE_SHA" ]] && [[ -d "$SRC_DIR/.git" ]]; then
    if git -C "$SRC_DIR" cat-file -e "${RESTORE_SHA}^{commit}" 2>/dev/null; then
        git -C "$SRC_DIR" checkout -f "$RESTORE_SHA" 2>&1 | sed 's/^/    /' || true
        ok "Source checked out at ${RESTORE_SHA:0:12} (detached)."
        if command -v rsync &>/dev/null; then
            rsync -a --delete --exclude='.git/' --exclude='data/' --exclude='.env' "${SRC_DIR}/" "${INSTALL_DIR}/"
        else
            cp -a "${SRC_DIR}/." "${INSTALL_DIR}/"; rm -rf "${INSTALL_DIR}/.git"
        fi
        for f in install.php fresh_install.php; do [[ -f "${INSTALL_DIR}/${f}" ]] && rm -f "${INSTALL_DIR}/${f}"; done
        ok "App files rolled back."
    else
        warn "Commit ${RESTORE_SHA:0:12} not found locally — leaving code as-is, restoring DB only."
    fi
else
    warn "No recorded commit — restoring DB/key only, leaving code as-is."
fi

# --- Restore key + database -------------------------------------------------
hdr "4/5  Restore key + database"
if [[ -f "${BK}/env.bak" ]]; then cp -a "${BK}/env.bak" "$ENV_FILE"; ok "Encryption key restored."; fi
rm -f "$DB_FILE" "${DB_FILE}-wal" "${DB_FILE}-shm"
cp -a "${BK}/pause_groups.db" "$DB_FILE"
if [[ -f "${BK}/pause_groups.db-wal" ]]; then cp -a "${BK}/pause_groups.db-wal" "${DB_FILE}-wal"; fi
if [[ -f "${BK}/pause_groups.db-shm" ]]; then cp -a "${BK}/pause_groups.db-shm" "${DB_FILE}-shm"; fi
chown -R 33:33 "$DATA_DIR"; chmod 770 "$DATA_DIR"
chmod -R o+rX "$INSTALL_DIR"; [[ -f "$ENV_FILE" ]] && chmod o-rX "$ENV_FILE" || true
ok "Database restored."

# --- Restart ----------------------------------------------------------------
hdr "5/5  Restart + verify"
systemctl start "$FPM_SERVICE"
systemctl start "${TIMERS[@]}" 2>/dev/null || true
sleep 4
if systemctl is-active --quiet "$FPM_SERVICE"; then ok "${FPM_SERVICE} is active."; else warn "${FPM_SERVICE} not active — journalctl -eu ${FPM_SERVICE}"; fi
resp="$(curl -s --max-time 6 http://localhost/api/health 2>/dev/null || true)"
if echo "$resp" | grep -q '"database":true'; then ok "App responding, database OK."; else warn "Health inconclusive: ${resp:-<none>}"; fi

echo ""
echo -e "${BOLD}${GRN}Rollback complete.${NC}"
echo "  Restored from: ${BK}"
echo "  Pre-revert snapshot (undo the revert): ${PRE}"
echo ""
note "The source clone is now on a detached commit. To move forward again later:"
note "  git -C ${SRC_DIR} checkout main   &&   sudo bash update.sh"
note "Hard-refresh the browser (Ctrl+Shift+R) to load the rolled-back CSS/JS."
