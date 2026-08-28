#!/usr/bin/env bash
# =============================================================================
#  write-daily-unit.sh — single source of truth for pause-groups-daily.service
# =============================================================================
#
#  Called by BOTH setup-fcos.sh (fresh install) and update.sh (routine
#  updates), so the unit file can never drift between the two paths — the same
#  arrangement write-fpm-unit.sh gives the FPM service.
#
#  WHY THE DAILY UNIT NEEDS ITS OWN WRITER
#  ---------------------------------------
#  cron.php's nightly steps read the CenterEdge MSSQL database: the venue-wide
#  daily rollup (venue_daily_stats) that backs the year-over-year card and the
#  Analytics deep-history view is refreshed there, and the one-time historical
#  backfills retry there. All of that needs the pdo_dblib overlay image — the
#  stock php:fpm image ships no MSSQL driver.
#
#  update.sh used to rewrite ONLY the FPM unit with the overlay tag, leaving
#  this unit pinned to the stock image from install time. The result was a
#  silent freeze: every night Scheduler::refreshVenueDailyStatsRecent() threw
#  "No MSSQL PDO driver is installed in this PHP runtime", cron logged a
#  WARNING nobody reads, and venue_daily_stats stayed frozen at whatever day
#  the last one-time backfill wrote — which the dashboard then reported, quite
#  honestly, as "Actuals through <that day>" for weeks afterwards.
#
#  The per-minute WATCHDOG unit deliberately stays on the stock image: it never
#  touches MSSQL, and it is the safety-critical pause/unpause path — there is
#  nothing to gain by moving it onto a heavier image.
#
#  USAGE:
#    write-daily-unit.sh <env_file> <install_dir> <data_dir> <runtime_image> [load_tar]
#
#    runtime_image  image the service runs (stock php:fpm, or the MSSQL overlay)
#    load_tar       optional; when given, an ExecStartPre reloads runtime_image
#                   from this tar whenever podman's cache lost it (FCOS OS
#                   rebuilds wipe /var/lib/containers)
#
#  Writes /etc/systemd/system/pause-groups-daily.service and daemon-reloads.
#  Does NOT enable/start the timer — callers decide when.

set -euo pipefail

ENV_FILE="${1:?usage: write-daily-unit.sh <env_file> <install_dir> <data_dir> <runtime_image> [load_tar]}"
INSTALL_DIR="${2:?missing install_dir}"
DATA_DIR="${3:?missing data_dir}"
RUNTIME_IMAGE="${4:?missing runtime_image}"
LOAD_TAR="${5:-}"

# PG_DAILY_UNIT_FILE override exists for tests; production callers leave it unset.
UNIT_FILE="${PG_DAILY_UNIT_FILE:-/etc/systemd/system/pause-groups-daily.service}"

IMAGE_LOAD_PRE=""
if [[ -n "$LOAD_TAR" ]]; then
    IMAGE_LOAD_PRE="ExecStartPre=-/usr/bin/bash -c '/usr/bin/podman image exists ${RUNTIME_IMAGE} || /usr/bin/podman load -i ${LOAD_TAR}'"
fi

cat > "$UNIT_FILE" <<UNIT
[Unit]
Description=pause-groups daily planner (game sync, schedule planning)
# Don't run if FPM isn't up — the planner writes to the same DB
After=pause-groups-fpm.service

[Service]
Type=oneshot
# Podman's image cache is wiped by FCOS OS rebuilds; reload the runtime image
# from /var/persist when it's gone (no-op while it's cached).
${IMAGE_LOAD_PRE}
# Run as uid 33 (www-data) so the container writes files owned by the same
# user as the FPM service, avoiding permission conflicts on the DB.
ExecStart=/usr/bin/podman run --rm \\
    --network host \\
    --env-file ${ENV_FILE} \\
    -v ${INSTALL_DIR}:${INSTALL_DIR}:z \\
    -w ${INSTALL_DIR} \\
    -u 33:33 \\
    ${RUNTIME_IMAGE} \\
    php cron.php
StandardOutput=append:${DATA_DIR}/cron.log
StandardError=append:${DATA_DIR}/cron.log
UNIT

# The unit file is already written at this point; a daemon-reload hiccup
# shouldn't abort a caller mid-update. The next timer firing picks it up.
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || echo "WARN: systemctl daemon-reload failed — run it manually" >&2
fi
echo "wrote ${UNIT_FILE} (image: ${RUNTIME_IMAGE}$( [[ -n "$LOAD_TAR" ]] && echo ", reload tar: ${LOAD_TAR}" || true ))"
