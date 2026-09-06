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
#  WHY THIS ALSO WRITES THE TIMERS
#  -------------------------------
#  1. THE NIGHTLY TIME IS PINNED TO THE APP'S TIMEZONE. systemd fires
#     OnCalendar on the SYSTEM zone. This venue's host is UTC and the app is
#     America/New_York, so the original `OnCalendar=*-*-* 00:05:00` fired at
#     20:05 the PREVIOUS local evening. cron.php then set the app timezone and
#     computed "today" as that previous day — and the venue rollup, which never
#     writes the running day, could only reach the day before THAT. The result
#     was a reporting lag of two days that no amount of healthy cron runs could
#     close: on Sep 6 the newest complete day was Sep 4 at best. Pinning the
#     zone (systemd 252+, same mechanism write-bot-units.sh uses for the two
#     Slack bots) puts the run after local midnight, where it covers yesterday.
#     NEVER convert to a fixed UTC offset instead — it breaks at every DST
#     changeover.
#
#  2. THE ROLLUP GETS MORE THAN ONE ATTEMPT A DAY. The nightly planner is a
#     single shot: if it is skipped (the scheduler lock is held by the
#     per-minute watchdog at that exact moment), or MSSQL blips, or the box is
#     busy, the venue rollup does not advance and there is no retry for 24
#     hours. Every miss costs a whole day of reporting, and misses accumulate
#     silently. So a SECOND, cheap timer runs run_backfills.php through the
#     day: it takes no scheduler lock (so it cannot collide with the
#     watchdog), it is idempotent and flag-guarded, and it catches the rollup
#     up to yesterday. A missed night now self-heals within hours instead of
#     waiting for the next one.
#
#  USAGE:
#    write-daily-unit.sh <env_file> <install_dir> <data_dir> <runtime_image> [load_tar]
#
#    runtime_image  image the services run (stock php:fpm, or the MSSQL overlay)
#    load_tar       optional; when given, an ExecStartPre reloads runtime_image
#                   from this tar whenever podman's cache lost it (FCOS OS
#                   rebuilds wipe /var/lib/containers)
#
#  Writes, and daemon-reloads:
#    pause-groups-daily.service   + .timer   (nightly planner, app-local 00:05)
#    pause-groups-refresh.service + .timer   (reporting catch-up, every 2h)
#  Does NOT enable/start the timers — callers decide when.

set -euo pipefail

ENV_FILE="${1:?usage: write-daily-unit.sh <env_file> <install_dir> <data_dir> <runtime_image> [load_tar]}"
INSTALL_DIR="${2:?missing install_dir}"
DATA_DIR="${3:?missing data_dir}"
RUNTIME_IMAGE="${4:?missing runtime_image}"
LOAD_TAR="${5:-}"

# PG_DAILY_UNIT_FILE / PG_UNIT_DIR overrides exist for tests; production callers
# leave them unset.
UNIT_FILE="${PG_DAILY_UNIT_FILE:-/etc/systemd/system/pause-groups-daily.service}"
UNIT_DIR="$(dirname "$UNIT_FILE")"
DAILY_TIMER="${UNIT_DIR}/pause-groups-daily.timer"
REFRESH_UNIT="${UNIT_DIR}/pause-groups-refresh.service"
REFRESH_TIMER="${UNIT_DIR}/pause-groups-refresh.timer"

IMAGE_LOAD_PRE=""
if [[ -n "$LOAD_TAR" ]]; then
    IMAGE_LOAD_PRE="ExecStartPre=-/usr/bin/bash -c '/usr/bin/podman image exists ${RUNTIME_IMAGE} || /usr/bin/podman load -i ${LOAD_TAR}'"
fi

# ---------------------------------------------------------------------------
#  Which clock does "00:05" mean?
# ---------------------------------------------------------------------------
# Ask the app, exactly as write-bot-units.sh asks each bot --print-timezone.
# Where the host and app zones differ, every OnCalendar line carries the app's
# zone so the nightly run lands after LOCAL midnight — otherwise it runs on the
# previous local evening and the rollup is structurally a day further behind
# than it needs to be, forever.
APP_TZ=""
if command -v podman >/dev/null 2>&1; then
    APP_TZ="$(podman run --rm --network host \
        $( [[ -f "$ENV_FILE" ]] && echo --env-file "$ENV_FILE" ) \
        -v "${INSTALL_DIR}:${INSTALL_DIR}:z" -w "$INSTALL_DIR" -u 33:33 \
        "$RUNTIME_IMAGE" php print_timezone.php 2>/dev/null \
        | tr -d '[:space:]' || true)"
fi
HOST_TZ="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
HOST_TZ="${HOST_TZ:-UTC}"
# "systemd 254 (254.5-1.fc39)" / "systemd 252 (v252.4-1.fc37)" -> 254 / 252
SYSTEMD_VER="$(systemctl --version 2>/dev/null | head -1 | awk '{print $2}' | tr -cd '0-9')"

TZ_SUFFIX=""
if [[ -n "$APP_TZ" && "$APP_TZ" != "$HOST_TZ" ]]; then
    if [[ -n "$SYSTEMD_VER" ]] && (( SYSTEMD_VER >= 252 )); then
        TZ_SUFFIX=" ${APP_TZ}"
    else
        echo "WARN: this machine's clock is ${HOST_TZ} but the app runs on ${APP_TZ}," >&2
        echo "WARN: and systemd ${SYSTEMD_VER:-<unknown>} is too old (needs 252+) to put a zone" >&2
        echo "WARN: in a timer. The nightly job will run at 00:05 ${HOST_TZ}, which is the" >&2
        echo "WARN: previous evening in ${APP_TZ} — reporting rollups will sit an extra day" >&2
        echo "WARN: behind. Fix with: sudo timedatectl set-timezone ${APP_TZ}" >&2
    fi
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

# The nightly timer. Unlike the two Slack bots' timers this one IS rewritten on
# every deploy: it carries no operator-chosen time (00:05 is not a preference,
# it is "just after midnight"), and the zone pin is a correctness fix that has
# to reach installs that already have the file.
cat > "$DAILY_TIMER" <<TIMER
[Unit]
Description=pause-groups daily planner — 00:05 every night

# Persistent=true covers a box that was powered off at 00:05: the job runs as
# soon as it boots.
#
# TIMEZONE: systemd fires OnCalendar on the SYSTEM zone, which is not
# necessarily the app's. Where they differ this line carries the app's zone
# (systemd 252+), so the run lands after LOCAL midnight. Without it, a UTC host
# running an Eastern venue fires this at 20:05 the previous evening and every
# nightly date calculation — the venue rollup above all — is a day short.
# Never substitute a fixed UTC offset: it breaks at every DST changeover.

[Timer]
OnCalendar=*-*-* 00:05:00${TZ_SUFFIX}
Persistent=true

[Install]
WantedBy=timers.target
TIMER
chmod 0644 "$DAILY_TIMER"

# ---------------------------------------------------------------------------
#  Reporting catch-up — the nightly job's second chance
# ---------------------------------------------------------------------------
cat > "$REFRESH_UNIT" <<UNIT
[Unit]
Description=pause-groups reporting catch-up (venue rollup + pending backfills)
After=pause-groups-fpm.service

[Service]
Type=oneshot
${IMAGE_LOAD_PRE}
# run_backfills.php takes NO scheduler lock — it only widens analytics tables —
# so this can never collide with the watchdog or delay a pause/unpause.
ExecStart=/usr/bin/podman run --rm \\
    --network host \\
    --env-file ${ENV_FILE} \\
    -v ${INSTALL_DIR}:${INSTALL_DIR}:z \\
    -w ${INSTALL_DIR} \\
    -u 33:33 \\
    ${RUNTIME_IMAGE} \\
    php run_backfills.php
StandardOutput=append:${DATA_DIR}/cron.log
StandardError=append:${DATA_DIR}/cron.log
UNIT

cat > "$REFRESH_TIMER" <<TIMER
[Unit]
Description=pause-groups reporting catch-up — every 2 hours

# WHY THIS EXISTS. venue_daily_stats is advanced only by
# Scheduler::refreshVenueDailyStatsRecent(), and before this timer that ran in
# exactly one place: the nightly planner. One shot a day, no retry. A night the
# planner was skipped (the watchdog held the scheduler lock at 00:05), or MSSQL
# blipped, cost a full day of every reporting figure — and the misses stack up
# with nothing to say so.
#
# This is the cheap half of that job on its own schedule: pending one-time
# backfills, then catch the venue rollup up to yesterday. Idempotent and
# flag-guarded, so the common case is a couple of config reads and one bounded
# grouped query. Yesterday's numbers now appear within a couple of hours of
# local midnight instead of waiting on a single nightly firing that might not
# come.

[Timer]
OnCalendar=*-*-* 00/2:35:00${TZ_SUFFIX}
Persistent=true
RandomizedDelaySec=300

[Install]
WantedBy=timers.target
TIMER
chmod 0644 "$REFRESH_TIMER"

# The unit files are already written at this point; a daemon-reload hiccup
# shouldn't abort a caller mid-update. The next timer firing picks it up.
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || echo "WARN: systemctl daemon-reload failed — run it manually" >&2
fi
echo "wrote ${UNIT_FILE} (image: ${RUNTIME_IMAGE}$( [[ -n "$LOAD_TAR" ]] && echo ", reload tar: ${LOAD_TAR}" || true ))"
echo "wrote ${DAILY_TIMER} (00:05${TZ_SUFFIX:+ ${APP_TZ}})"
echo "wrote ${REFRESH_TIMER} (every 2h${TZ_SUFFIX:+, ${APP_TZ}})"
