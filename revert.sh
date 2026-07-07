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
#    1. Picks the backup to restore, and VALIDATES it is a real, non-empty
#       database BEFORE touching anything (a bad backup aborts with the app
#       still running).
#    2. Stops PHP-FPM + the timers so nothing writes during the restore.
#    3. Snapshots the CURRENT (possibly broken) state — with writers stopped, so
#       the snapshot is consistent — making the revert itself reversible.
#    4. Rolls the code back to the commit that was live before the update
#       (git checkout in the source clone + re-sync into the app dir).
#    5. Restores the encryption key and the database (atomically) from the backup.
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
DEPLOYED_MARKER="${INSTALL_DIR}/.deployed_commit"
BACKUP_ROOT="/var/persist/pause-groups-backups"
LATEST_MARKER="${BACKUP_ROOT}/LATEST_UPDATE"
FPM_SERVICE="pause-groups-fpm"
KEEP_PRE_REVERT=15
TIMERS=("pause-groups-watchdog.timer" "pause-groups-daily.timer")
SERVICES=("pause-groups-watchdog.service" "pause-groups-daily.service")

# Detect the PHP image (full token, incl. any -fpm-<variant>) so validation +
# snapshots run under the operator's chosen image; fall back to the default.
PHP_IMAGE="$(grep -ohE '(docker\.io/)?(library/)?php:[A-Za-z0-9._-]+' \
    "/etc/systemd/system/${FPM_SERVICE}.service" 2>/dev/null | head -1 || true)"
PHP_IMAGE="${PHP_IMAGE:-docker.io/library/php:8.3-fpm}"

# PRE is the pre-revert safety snapshot path; the ERR trap points the operator
# at it if anything blows up mid-restore. Empty until step 3 creates it.
PRE=""
trap 'rc=$?; echo -e "\n${RED}revert.sh failed at line ${LINENO}.${NC}" >&2
      if [[ -n "$PRE" && -d "$PRE" ]]; then
          echo -e "  A snapshot of the database as it was BEFORE this revert is safe at:\n    ${PRE}\n  To undo a half-finished revert: copy ${PRE}/pause_groups.db back to\n    ${DB_FILE}  (and remove any ${DB_FILE}-wal / -shm), then start ${FPM_SERVICE}." >&2
      fi
      echo -e "  Services may be stopped — check:  systemctl status ${FPM_SERVICE}" >&2
      exit $rc' ERR

# --- SQLite validation helpers ---------------------------------------------
db_header_ok() {   # cheap, container-free: is $1 a non-empty SQLite file?
    local f="$1" sz hdr
    [[ -f "$f" ]] || return 1
    sz="$(stat -c%s "$f" 2>/dev/null || echo 0)"
    (( sz >= 512 )) || return 1
    hdr="$(head -c 15 "$f" 2>/dev/null)"
    [[ "$hdr" == "SQLite format 3" ]]
}
db_deep_valid() {  # integrity_check=ok AND admin_users has >=1 row (needs image)
    local f="$1" dir
    dir="$(dirname "$f")"
    podman run --rm --network none -v "${dir}:${dir}:z" "$PHP_IMAGE" \
        php -r '$f=$argv[1];
            $s=new SQLite3($f, SQLITE3_OPEN_READONLY); $s->enableExceptions(true);
            if ($s->querySingle("PRAGMA integrity_check") !== "ok") { fwrite(STDERR,"integrity_check failed\n"); exit(3); }
            if ((int)$s->querySingle("SELECT COUNT(*) FROM admin_users") < 1) { fwrite(STDERR,"admin_users empty\n"); exit(4); }
            $s->close();' "$f" 2>/dev/null
}
# Consistent snapshot of a (quiesced) DB: VACUUM INTO in the container when the
# image is local, else a plain copy of db + sidecars. Callers MUST stop writers
# first so the copy path is consistent too.
snapshot_db() {
    local src="$1" dest="$2" destdir
    destdir="$(dirname "$dest")"
    if podman image exists "$PHP_IMAGE" 2>/dev/null && \
       podman run --rm --network none \
            -v "${INSTALL_DIR}:${INSTALL_DIR}:z" -v "${BACKUP_ROOT}:${BACKUP_ROOT}:z" \
            "$PHP_IMAGE" \
            php -r '$s=new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $s->enableExceptions(true); $s->busyTimeout(60000); $s->exec("VACUUM INTO ".chr(39).$argv[2].chr(39)); $s->close();' \
            "$src" "$dest" 2>/dev/null; then
        return 0
    fi
    cp -a "$src" "$dest"
    [[ -f "${src}-wal" ]] && cp -a "${src}-wal" "${dest}-wal" || true
    [[ -f "${src}-shm" ]] && cp -a "${src}-shm" "${dest}-shm" || true
}

list_backups() {
    echo "Available backups under ${BACKUP_ROOT}:"
    local d sha
    for d in $(ls -1dt "${BACKUP_ROOT}"/update-*/ 2>/dev/null || true); do
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

RESTORE_SHA="$(head -n1 "${BK}/deployed_commit.txt" 2>/dev/null | tr -d '[:space:]' || true)"

# --- Confirm (destructive) --------------------------------------------------
echo -e "${BOLD}${YLW}This will roll back to a previous backup.${NC}"
echo "  Backup:          ${BK}"
echo "  Restore DB from: ${BK}/pause_groups.db  ($(du -h "${BK}/pause_groups.db" | cut -f1))"
echo "  Restore code to: ${RESTORE_SHA:0:12}${RESTORE_SHA:+ (git commit)}"
echo ""
warn "The current database will be REPLACED (a safety snapshot is taken first)."
if ! read -r -p "Type 'yes' to roll back: " confirm; then echo "Aborted (no input)."; exit 0; fi
[[ "$confirm" == "yes" ]] || { echo "Aborted."; exit 0; }

# =============================================================================
#  1. Validate the backup BEFORE touching anything (app still running)
# =============================================================================
hdr "1/6  Validate backup"
db_header_ok "${BK}/pause_groups.db" \
    || die "Backup DB is empty or not a valid SQLite file — refusing to restore it over the live database. The live database is untouched."
if [[ ! -f "${BK}/pause_groups.db-wal" ]] && podman image exists "$PHP_IMAGE" 2>/dev/null; then
    db_deep_valid "${BK}/pause_groups.db" \
        || die "Backup DB failed integrity/row-count validation — refusing to restore. The live database is untouched."
    ok "Backup validated (SQLite integrity OK, non-empty)."
else
    note "Backup carries a WAL sidecar or the PHP image isn't local — deep check skipped; header OK."
    ok "Backup looks like a valid SQLite file."
fi

# =============================================================================
#  2. Stop writers (BEFORE snapshotting, so the snapshot is consistent)
# =============================================================================
hdr "2/6  Stop services"
systemctl stop "${TIMERS[@]}" 2>/dev/null || true
systemctl stop "${SERVICES[@]}" 2>/dev/null || true
systemctl stop "$FPM_SERVICE" 2>/dev/null || true
ok "PHP-FPM and timers stopped."

# =============================================================================
#  3. Snapshot the current state (reversible revert) — writers are stopped
# =============================================================================
hdr "3/6  Snapshot current state"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
PRE="${BACKUP_ROOT}/pre-revert-${STAMP}"
mkdir -p "$PRE"
if [[ -f "$DB_FILE" ]]; then
    snapshot_db "$DB_FILE" "${PRE}/pause_groups.db"
fi
[[ -f "$ENV_FILE" ]] && cp -a "$ENV_FILE" "${PRE}/env.bak" || true
chmod -R go-rwx "$PRE" || true
ok "Current state saved to ${PRE}"

# Prune old pre-revert snapshots so repeated reverts don't fill /var/persist.
{
    _pre=()
    while IFS= read -r d; do [[ -n "$d" ]] && _pre+=("$d"); done \
        < <(ls -1dt "${BACKUP_ROOT}"/pre-revert-*/ 2>/dev/null || true)
    if (( ${#_pre[@]} > KEEP_PRE_REVERT )); then
        for (( i=KEEP_PRE_REVERT; i<${#_pre[@]}; i++ )); do rm -rf "${_pre[$i]}"; done
        note "Pruned old pre-revert snapshots (kept ${KEEP_PRE_REVERT})."
    fi
}

# =============================================================================
#  4. Roll back the code
# =============================================================================
hdr "4/6  Restore application code"
CODE_ROLLED_BACK=0
if [[ -n "$RESTORE_SHA" ]] && [[ -d "$SRC_DIR/.git" ]]; then
    if git -C "$SRC_DIR" cat-file -e "${RESTORE_SHA}^{commit}" 2>/dev/null; then
        # Capture git's output and check its real exit status (piping to sed
        # would otherwise mask a checkout failure behind `|| true`).
        if _co_out="$(git -C "$SRC_DIR" checkout -f "$RESTORE_SHA" 2>&1)"; then
            echo "$_co_out" | sed 's/^/    /'
            ok "Source checked out at ${RESTORE_SHA:0:12} (detached)."
            if command -v rsync &>/dev/null; then
                rsync -a --delete \
                    --exclude='.git/' --exclude='data/' --exclude='.env' --exclude='.deployed_commit' \
                    "${SRC_DIR}/" "${INSTALL_DIR}/"
            else
                warn "rsync not found — using a staged copy (upstream deletions won't propagate)."
                _stage="$(mktemp -d /tmp/pg-revert-stage.XXXXXX)"
                cp -a "${SRC_DIR}/." "${_stage}/"
                rm -rf "${_stage}/.git" "${_stage}/data" "${_stage}/.env" "${_stage}/.deployed_commit"
                cp -a "${_stage}/." "${INSTALL_DIR}/"
                rm -rf "${_stage}"
            fi
            for f in install.php fresh_install.php; do [[ -f "${INSTALL_DIR}/${f}" ]] && rm -f "${INSTALL_DIR}/${f}"; done
            printf '%s\n' "$RESTORE_SHA" > "$DEPLOYED_MARKER"
            CODE_ROLLED_BACK=1
            ok "App files rolled back."
        else
            echo "$_co_out" | sed 's/^/    /' >&2
            die "git checkout ${RESTORE_SHA:0:12} failed — code left as-is and DB NOT restored (live DB untouched). Fix the source clone, then re-run. Restart with:  systemctl start ${FPM_SERVICE}"
        fi
    else
        warn "Commit ${RESTORE_SHA:0:12} not found locally — leaving code as-is, restoring DB only."
    fi
else
    warn "No recorded commit — restoring DB/key only, leaving code as-is."
fi

# =============================================================================
#  5. Restore key + database (atomic: stage then mv into place)
# =============================================================================
hdr "5/6  Restore key + database"
if [[ -f "${BK}/env.bak" ]]; then cp -a "${BK}/env.bak" "$ENV_FILE"; ok "Encryption key restored."; fi

# Stage the restore next to the live DB first. If the copy fails, the live DB is
# still intact (nothing was removed yet). The final `mv` is atomic on the same
# filesystem, so the live DB is never left deleted.
cp -a "${BK}/pause_groups.db" "${DB_FILE}.restore"
[[ -f "${BK}/pause_groups.db-wal" ]] && cp -a "${BK}/pause_groups.db-wal" "${DB_FILE}.restore-wal" || true
[[ -f "${BK}/pause_groups.db-shm" ]] && cp -a "${BK}/pause_groups.db-shm" "${DB_FILE}.restore-shm" || true
db_header_ok "${DB_FILE}.restore" || die "Staged restore copy is not a valid SQLite file — aborting. Live DB left as-is at ${DB_FILE}; remove ${DB_FILE}.restore."

rm -f "${DB_FILE}-wal" "${DB_FILE}-shm"
mv -f "${DB_FILE}.restore" "$DB_FILE"
[[ -f "${DB_FILE}.restore-wal" ]] && mv -f "${DB_FILE}.restore-wal" "${DB_FILE}-wal" || true
[[ -f "${DB_FILE}.restore-shm" ]] && mv -f "${DB_FILE}.restore-shm" "${DB_FILE}-shm" || true

chown -R 33:33 "$DATA_DIR"; chmod 770 "$DATA_DIR"
chmod -R o+rX "$INSTALL_DIR"; [[ -f "$ENV_FILE" ]] && chmod o-rX "$ENV_FILE" || true
ok "Database restored."

# =============================================================================
#  6. Restart + verify
# =============================================================================
hdr "6/6  Restart + verify"
# A failed FPM start must NOT abort before the timers come back up — otherwise
# the venue's scheduled pause/unpause enforcement is left dead. Surface it, then
# always restart the timers.
systemctl start "$FPM_SERVICE" || warn "systemctl start ${FPM_SERVICE} returned non-zero (see below)."
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
if [[ $CODE_ROLLED_BACK -eq 1 ]]; then
    note "The source clone is now on a detached commit. To move forward again later:"
    note "  git -C ${SRC_DIR} checkout main   &&   sudo bash update.sh"
fi
note "Hard-refresh the browser (Ctrl+Shift+R) to load the rolled-back CSS/JS."
