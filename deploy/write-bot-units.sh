#!/usr/bin/env bash
# =============================================================================
#  write-bot-units.sh — single source of truth for the Slack bots' systemd units
# =============================================================================
#
#  Called by BOTH update.sh (routine deploys) and the bots' own install.sh
#  scripts (guided first-time setup), so the units can never drift between the
#  two paths — the same arrangement write-fpm-unit.sh and write-daily-unit.sh
#  give the FPM and daily services.
#
#  Handles either bot:
#      write-bot-units.sh birthdays     ... -> ceplay-birthdays.{service,timer}
#      write-bot-units.sh anniversaries ... -> ceplay-anniversaries.{service,timer}
#
#  TWO RULES, AND BOTH EXIST TO PROTECT SETTINGS SOMEBODY ALREADY CHOSE.
#
#  1. THE SERVICE UNIT IS ALWAYS REWRITTEN; THE TIMER IS NOT.
#     The service carries the install path and the container image, so it goes
#     stale across a deploy and has to be refreshed. The timer carries nothing
#     but the schedule — which is the operator's choice, made once during
#     install.sh — so rewriting it on every update would silently move the
#     posting time back to the shipped default. An existing timer is therefore
#     left completely alone unless a time is passed explicitly.
#
#  2. THE POSTING TIME IS PINNED TO THE APP'S TIMEZONE, NOT THE MACHINE'S.
#     systemd fires OnCalendar on the SYSTEM zone. At this venue the host runs
#     UTC and the app runs America/New_York, so a bare "09:00" posts at 05:00
#     local — which is exactly what the birthday bot did for months before
#     anybody noticed. Where the two zones differ and systemd is new enough
#     (252+) to parse a zone in a calendar spec, the zone is appended to each
#     line. Never "fix" this by converting to a fixed UTC offset: that breaks
#     at every DST changeover.
#
#  USAGE:
#    write-bot-units.sh <bot> <env_file> <install_dir> <data_dir> <runtime_image> [load_tar] [HH:MM]
#
#    bot            birthdays | anniversaries
#    runtime_image  the image the bot runs — it reads the CenterEdge MSSQL
#                   database for the roster, so this must be the pdo_dblib
#                   overlay, not the stock php:fpm image
#    load_tar       optional; when given, an ExecStartPre reloads runtime_image
#                   from this tar whenever podman's cache lost it (FCOS OS
#                   rebuilds wipe /var/lib/containers)
#    HH:MM          optional; when given, the timer is (re)written for that
#                   time plus two catch-up firings. Omitted, an existing timer
#                   is preserved and a missing one gets the shipped default.
#
#  Writes /etc/systemd/system/ceplay-<bot>.{service,timer} and daemon-reloads.
#  Does NOT enable or start the timer — callers decide when.

set -euo pipefail

BOT="${1:?usage: write-bot-units.sh <birthdays|anniversaries> <env_file> <install_dir> <data_dir> <runtime_image> [load_tar] [HH:MM]}"
ENV_FILE="${2:?missing env_file}"
INSTALL_DIR="${3:?missing install_dir}"
DATA_DIR="${4:?missing data_dir}"
RUNTIME_IMAGE="${5:?missing runtime_image}"
LOAD_TAR="${6:-}"
POST_TIME="${7:-}"

case "$BOT" in
    birthdays)     BOT_SCRIPT="birthdays/birthday_bot.php";        BOT_DESC="birthday Slack bot" ;;
    anniversaries) BOT_SCRIPT="anniversaries/anniversary_bot.php"; BOT_DESC="work-anniversary Slack bot" ;;
    *) echo "write-bot-units.sh: unknown bot '${BOT}' (expected birthdays or anniversaries)" >&2; exit 2 ;;
esac

# PG_UNIT_DIR override exists for tests; production callers leave it unset.
UNIT_DIR="${PG_UNIT_DIR:-/etc/systemd/system}"
SERVICE_FILE="${UNIT_DIR}/ceplay-${BOT}.service"
TIMER_FILE="${UNIT_DIR}/ceplay-${BOT}.timer"
LOG_FILE="${DATA_DIR}/${BOT}.log"

IMAGE_LOAD_PRE=""
if [[ -n "$LOAD_TAR" ]]; then
    IMAGE_LOAD_PRE="ExecStartPre=-/usr/bin/bash -c '/usr/bin/podman image exists ${RUNTIME_IMAGE} || /usr/bin/podman load -i ${LOAD_TAR}'"
fi

# --- The service: paths and image, so it is always rewritten ----------------
mkdir -p "$UNIT_DIR"
cat > "$SERVICE_FILE" <<UNIT
[Unit]
Description=CEplay ${BOT_DESC}
After=pause-groups-fpm.service

[Service]
Type=oneshot
# Podman's image cache is wiped by FCOS OS rebuilds; reload the runtime image
# from /var/persist when it's gone (no-op while it's cached).
${IMAGE_LOAD_PRE}
# uid 33 (www-data) so anything the bot writes into data/ — its state file,
# heartbeat and lock — is owned by the same user as the FPM service.
ExecStart=/usr/bin/podman run --rm \\
    --network host \\
    --env-file ${ENV_FILE} \\
    -v ${INSTALL_DIR}:${INSTALL_DIR}:z \\
    -w ${INSTALL_DIR} \\
    -u 33:33 \\
    ${RUNTIME_IMAGE} \\
    php ${BOT_SCRIPT}
StandardOutput=append:${LOG_FILE}
StandardError=append:${LOG_FILE}
UNIT
chmod 0644 "$SERVICE_FILE"
echo "wrote ${SERVICE_FILE} (image: ${RUNTIME_IMAGE})"

# --- The timer: the operator's schedule, so it is preserved -----------------
if [[ -f "$TIMER_FILE" && -z "$POST_TIME" ]]; then
    echo "kept ${TIMER_FILE} (existing schedule left alone: $(grep -c '^OnCalendar=' "$TIMER_FILE") firing(s))"
else
    # Default posting time. The anniversary bot is offset from the birthday
    # bot's so that on a morning when both fire, the two posts read as two
    # pieces of news rather than one noisy blast.
    if [[ -z "$POST_TIME" ]]; then
        case "$BOT" in
            birthdays)     POST_TIME="09:00" ;;
            anniversaries) POST_TIME="09:10" ;;
        esac
    fi
    [[ "$POST_TIME" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] \
        || { echo "write-bot-units.sh: time must be HH:MM in 24-hour form, got '${POST_TIME}'" >&2; exit 2; }

    # Which clock does that time mean? Ask the bot itself — it resolves the
    # app's configured timezone, which is the one the operator typed against.
    APP_TZ=""
    if command -v podman >/dev/null 2>&1; then
        APP_TZ="$(podman run --rm --network host \
            $( [[ -f "$ENV_FILE" ]] && echo --env-file "$ENV_FILE" ) \
            -v "${INSTALL_DIR}:${INSTALL_DIR}:z" -w "$INSTALL_DIR" -u 33:33 \
            "$RUNTIME_IMAGE" php "$BOT_SCRIPT" --print-timezone 2>/dev/null \
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
            echo "WARN: in a timer. ${BOT} will post at ${POST_TIME} ${HOST_TZ}, NOT venue time." >&2
            echo "WARN: Fix with: sudo timedatectl set-timezone ${APP_TZ}" >&2
        fi
    fi

    # The chosen time plus two catch-up firings. A message that arrives
    # tomorrow is not a birthday (or anniversary) message, so one shot a day is
    # not enough: a blip at the posting minute would lose it outright. The
    # retries cannot double-post — the bot records who it greeted, so a run
    # with nothing left to do exits silently. Catch-ups that would spill past
    # midnight are dropped rather than wrapped: they would land on the
    # FOLLOWING day, which is a different day's message, not a retry of this
    # one.
    base=$((10#${POST_TIME%%:*} * 60 + 10#${POST_TIME##*:}))
    CAL_LINES="OnCalendar=*-*-* ${POST_TIME}:00${TZ_SUFFIX}"$'\n'
    for off in 30 90; do
        t=$((base + off))
        (( t < 1440 )) && CAL_LINES+="$(printf 'OnCalendar=*-*-* %02d:%02d:00%s' $((t / 60)) $((t % 60)) "$TZ_SUFFIX")"$'\n'
    done

    cat > "$TIMER_FILE" <<TIMER
[Unit]
Description=CEplay ${BOT_DESC} — daily

# Persistent=true covers a box that was powered off at the posting time: the
# job runs as soon as it boots.
#
# TIMEZONE: systemd fires OnCalendar on the SYSTEM zone, which is not
# necessarily the app's. Where they differ this file carries the zone on each
# line (systemd 252+). Re-run deploy/write-bot-units.sh with a time argument to
# change the schedule; update.sh deliberately leaves this file alone once it
# exists, so the time you chose survives every deploy.

[Timer]
${CAL_LINES}Persistent=true
RandomizedDelaySec=0

[Install]
WantedBy=timers.target
TIMER
    chmod 0644 "$TIMER_FILE"
    echo "wrote ${TIMER_FILE} (${POST_TIME}${TZ_SUFFIX:+ ${APP_TZ}}, plus catch-ups)"
fi

# The unit files are already written at this point; a daemon-reload hiccup
# shouldn't abort a caller mid-update. The next firing picks it up.
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || echo "WARN: systemctl daemon-reload failed — run it manually" >&2
fi
