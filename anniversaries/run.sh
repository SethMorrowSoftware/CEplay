#!/usr/bin/env bash
# =============================================================================
#  Run an anniversary-bot command inside the app's MSSQL-enabled container.
#
#  The bot needs the pdo_dblib driver to read the roster, and that driver only
#  exists inside the overlay image setup-fcos.sh builds — not in the host PHP.
#  This wrapper finds that image and runs whatever you pass it, so you don't
#  have to retype the podman invocation.
#
#  The twin of birthdays/run.sh, pointed at this bot's scripts.
#
#  USAGE (from anywhere):
#     sudo bash /var/persist/pause-groups/anniversaries/run.sh --list
#     sudo bash /var/persist/pause-groups/anniversaries/run.sh --dry-run
#     sudo bash /var/persist/pause-groups/anniversaries/run.sh --check
#     sudo bash /var/persist/pause-groups/anniversaries/run.sh --test-slack
#     sudo bash /var/persist/pause-groups/anniversaries/run.sh discover
#
#  Override the defaults with environment variables if your install differs:
#     PG_INSTALL_DIR=/opt/ceplay sudo -E bash anniversaries/run.sh --list
# =============================================================================
set -euo pipefail

INSTALL_DIR="${PG_INSTALL_DIR:-/var/persist/pause-groups}"
ENV_FILE="${PG_ENV_FILE:-${INSTALL_DIR}/.env}"
MSSQL_IMAGE="${PG_MSSQL_IMAGE:-localhost/pause-groups-fpm-mssql:latest}"
MSSQL_TAR="${PG_MSSQL_TAR:-${INSTALL_DIR}/php-fpm-mssql.tar}"

RED='\033[0;31m'; YLW='\033[1;33m'; DIM='\033[2m'; NC='\033[0m'
die()  { echo -e "${RED}[FATAL]${NC} $*" >&2; exit 1; }
warn() { echo -e "${YLW}[WARN]${NC}  $*" >&2; }

[[ -d "$INSTALL_DIR" ]] || die "Install dir not found: ${INSTALL_DIR}
Set PG_INSTALL_DIR to point at your CEplay install."
[[ -f "${INSTALL_DIR}/anniversaries/anniversary_bot.php" ]] || die \
    "${INSTALL_DIR}/anniversaries/ is missing — deploy the code first (sudo bash update.sh)."
command -v podman >/dev/null 2>&1 || die "podman not found on this host."

# The overlay image may not be loaded after an FCOS rebuild; it is persisted to
# a tar for exactly that case, so load it back before giving up.
if ! podman image exists "$MSSQL_IMAGE" 2>/dev/null; then
    if [[ -f "$MSSQL_TAR" ]] && podman load -i "$MSSQL_TAR" >/dev/null 2>&1; then
        :
    else
        die "The MSSQL overlay image (${MSSQL_IMAGE}) isn't available.
Build it by re-running:  sudo bash ${INSTALL_DIR}/update.sh
Without it the bot cannot read the employee roster."
    fi
fi

# `discover` is a convenience alias for the schema probe — the thing you run
# first, because it is what finds this venue's hire-date column.
SCRIPT="anniversaries/anniversary_bot.php"
if [[ "${1:-}" == "discover" ]]; then
    SCRIPT="anniversaries/discover.php"
    shift
fi

ENV_ARGS=()
if [[ -f "$ENV_FILE" ]]; then
    ENV_ARGS=(--env-file "$ENV_FILE")
else
    warn "No env file at ${ENV_FILE}; the encryption key may be missing."
fi

# The progress line goes to stderr, so callers that capture stdout stay clean.
# PG_QUIET silences it for scripts (install.sh) that need stderr for REAL errors.
[[ -n "${PG_QUIET:-}" ]] || echo -e "${DIM}→ ${MSSQL_IMAGE} php ${SCRIPT} $*${NC}" >&2
exec podman run --rm \
    --network host \
    "${ENV_ARGS[@]}" \
    -v "${INSTALL_DIR}:${INSTALL_DIR}:z" \
    -w "$INSTALL_DIR" \
    -u 33:33 \
    "$MSSQL_IMAGE" \
    php "$SCRIPT" "$@"
