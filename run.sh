#!/usr/bin/env bash
# =============================================================================
#  Run one of the app's maintenance scripts inside the app's container.
#
#  There is NO php on the venue host — the app runs entirely in containers, so
#  `php run_backfills.php` fails with "php: command not found" and the scripts'
#  own usage lines are impossible to follow as written. Worse, the git checkout
#  (/var/persist/pause-groups-src) and the live install
#  (/var/persist/pause-groups) are different directories, so running a script
#  from the wrong one silently reads the wrong database.
#
#  This wrapper solves both: it picks the right image, mounts the INSTALL dir,
#  and runs as www-data so nothing in data/ ends up root-owned (which would
#  break the web tier). Same shape as birthdays/run.sh.
#
#  USAGE (from anywhere):
#     sudo bash /var/persist/pause-groups/run.sh check_rollups.php
#     sudo bash /var/persist/pause-groups/run.sh run_backfills.php
#     sudo bash /var/persist/pause-groups/run.sh cron.php
#     sudo bash /var/persist/pause-groups/run.sh backfill_card_activity.php
#
#  Override the defaults if your install differs:
#     PG_INSTALL_DIR=/opt/ceplay sudo -E bash run.sh check_rollups.php
# =============================================================================
set -euo pipefail

INSTALL_DIR="${PG_INSTALL_DIR:-/var/persist/pause-groups}"
ENV_FILE="${PG_ENV_FILE:-${INSTALL_DIR}/.env}"
MSSQL_IMAGE="${PG_MSSQL_IMAGE:-localhost/pause-groups-fpm-mssql:latest}"
MSSQL_TAR="${PG_MSSQL_TAR:-${INSTALL_DIR}/php-fpm-mssql.tar}"

RED='\033[0;31m'; YLW='\033[1;33m'; DIM='\033[2m'; NC='\033[0m'
die()  { echo -e "${RED}[FATAL]${NC} $*" >&2; exit 1; }
warn() { echo -e "${YLW}[WARN]${NC}  $*" >&2; }

if [[ $# -lt 1 ]]; then
    cat >&2 <<USAGE
Usage: sudo bash run.sh <script.php> [args...]

Commonly wanted:
  check_rollups.php           Is the reporting history advancing? (start here)
  run_backfills.php           Fill in deep history from the POS now
  cron.php                    The nightly job, run by hand
  backfill_card_activity.php  Guest ledger backfill only
USAGE
    exit 2
fi

SCRIPT="$1"; shift

[[ -d "$INSTALL_DIR" ]] || die "Install dir not found: ${INSTALL_DIR}
Set PG_INSTALL_DIR to point at your CEplay install."
# Deliberately checked against the INSTALL dir, not the caller's cwd: running
# from the -src checkout would operate on the wrong data/ directory.
[[ -f "${INSTALL_DIR}/${SCRIPT}" ]] || die \
    "${SCRIPT} is not in ${INSTALL_DIR}.
If you just pulled new code, deploy it first:  sudo bash update.sh"
command -v podman >/dev/null 2>&1 || die "podman not found on this host."

# The overlay image may not be loaded after an OS rebuild; it is persisted to a
# tar for exactly that case, so load it back before giving up.
IMAGE="$MSSQL_IMAGE"
if ! podman image exists "$IMAGE" 2>/dev/null; then
    if [[ -f "$MSSQL_TAR" ]] && podman load -i "$MSSQL_TAR" >/dev/null 2>&1; then
        :
    else
        die "The MSSQL overlay image (${MSSQL_IMAGE}) isn't available.
Build it by re-running:  sudo bash ${INSTALL_DIR}/update.sh
The stock php image has no MSSQL driver, so anything reading the POS would
report 'No MSSQL PDO driver is installed in this PHP runtime'."
    fi
fi

ENV_ARGS=()
if [[ -f "$ENV_FILE" ]]; then
    ENV_ARGS=(--env-file "$ENV_FILE")
else
    warn "No env file at ${ENV_FILE}; the encryption key may be missing."
fi

[[ -n "${PG_QUIET:-}" ]] || echo -e "${DIM}→ ${IMAGE} php ${SCRIPT} $*${NC}" >&2
exec podman run --rm \
    --network host \
    "${ENV_ARGS[@]}" \
    -v "${INSTALL_DIR}:${INSTALL_DIR}:z" \
    -w "$INSTALL_DIR" \
    -u 33:33 \
    "$IMAGE" \
    php "$SCRIPT" "$@"
