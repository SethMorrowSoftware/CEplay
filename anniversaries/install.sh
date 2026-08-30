#!/usr/bin/env bash
# =============================================================================
#  Work-anniversary bot — one-command installer.
#
#      sudo bash /var/persist/pause-groups/anniversaries/install.sh
#
#  Asks for the Slack token and channel, checks everything works, writes the
#  config with the right owner and permissions, and installs the daily timer.
#  Safe to re-run: it edits the existing config in place rather than starting
#  over, so use it to change the channel or the posting time later too.
#
#      sudo bash .../install.sh --uninstall     remove the timer (keeps config)
#
#  The twin of birthdays/install.sh. If you have already installed that bot,
#  this is the same five steps and the same token will do.
# =============================================================================
set -euo pipefail

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'; BLU='\033[0;34m'
BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
info() { echo -e "${BLU}→${NC} $*"; }
ok()   { echo -e "${GRN}✓${NC} $*"; }
warn() { echo -e "${YLW}!${NC} $*"; }
die()  { echo -e "\n${RED}✗ $*${NC}\n" >&2; exit 1; }
hdr()  { echo -e "\n${BOLD}$*${NC}\n$(printf '─%.0s' $(seq 1 62))"; }

# The installer ships inside the tree it installs, so the install dir is simply
# its own parent — no guessing, and it works wherever the app is deployed.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${PG_INSTALL_DIR:-$(dirname "$SCRIPT_DIR")}"
DATA_DIR="${INSTALL_DIR}/data"
CONFIG="${DATA_DIR}/anniversary_config.php"
EXAMPLE="${SCRIPT_DIR}/config.example.php"
BDAY_CONFIG="${DATA_DIR}/birthday_config.php"
UNIT_SRC="${SCRIPT_DIR}/systemd"
UNIT_DST="${PG_UNIT_DIR:-/etc/systemd/system}"
SERVICE="ceplay-anniversaries.service"
TIMER="ceplay-anniversaries.timer"

# --- Uninstall --------------------------------------------------------------
if [[ "${1:-}" == "--uninstall" ]]; then
    hdr "Removing the work-anniversary bot timer"
    systemctl disable --now "$TIMER" 2>/dev/null || true
    rm -f "${UNIT_DST}/${TIMER}" "${UNIT_DST}/${SERVICE}"
    systemctl daemon-reload
    ok "Timer removed. Nothing will post from now on."
    echo -e "${DIM}  Your config is still at ${CONFIG} — delete it by hand if you want it gone.${NC}\n"
    exit 0
fi

echo -e "\n${BOLD}Work-anniversary bot installer${NC}"
echo -e "${DIM}${INSTALL_DIR}${NC}"

# =============================================================================
#  1. Preflight — fail with the fix, not a stack trace
# =============================================================================
hdr "1/5  Checking this machine"

[[ $EUID -eq 0 ]] || die "Run this with sudo — it writes to ${DATA_DIR} and /etc/systemd/system.

  sudo bash ${BASH_SOURCE[0]}"

[[ -f "${SCRIPT_DIR}/anniversary_bot.php" ]] || die "Can't find anniversary_bot.php next to this script."
ok "Bot files present"

[[ -d "$DATA_DIR" ]] || die "No data directory at ${DATA_DIR}.
Is ${INSTALL_DIR} really your CEplay install? Override with PG_INSTALL_DIR."
ok "App data directory found"

command -v podman >/dev/null 2>&1 || die "podman isn't installed on this host."
command -v systemctl >/dev/null 2>&1 || die "systemctl isn't available; install the timer by hand (see README)."

MSSQL_IMAGE="${PG_MSSQL_IMAGE:-localhost/pause-groups-fpm-mssql:latest}"
MSSQL_TAR="${PG_MSSQL_TAR:-${INSTALL_DIR}/php-fpm-mssql.tar}"
if ! podman image exists "$MSSQL_IMAGE" 2>/dev/null; then
    if [[ -f "$MSSQL_TAR" ]] && podman load -i "$MSSQL_TAR" >/dev/null 2>&1; then
        ok "MSSQL image restored from ${MSSQL_TAR##*/}"
    else
        die "The MSSQL driver image (${MSSQL_IMAGE}) isn't built yet.

The bot needs it to read the employee roster. Build it with:

  sudo bash ${INSTALL_DIR}/update.sh

then run this installer again."
    fi
else
    ok "MSSQL driver image ready"
fi

# PG_QUIET drops run.sh's progress line but keeps stderr intact — the channel
# lookup below reads real error messages off it, and `2>/dev/null` here would
# throw away the one thing that tells the operator what went wrong.
run_bot() { PG_QUIET=1 bash "${SCRIPT_DIR}/run.sh" "$@"; }

# =============================================================================
#  2. Slack
# =============================================================================
hdr "2/5  Slack"

EXISTING_TOKEN=""
TOKEN_SOURCE=""
if [[ -f "$CONFIG" ]]; then
    EXISTING_TOKEN="$(grep -oE "'slack_bot_token'[^']*'[^']*'" "$CONFIG" | tail -1 | grep -oE "xoxb-[^']*" || true)"
    [[ -n "$EXISTING_TOKEN" ]] && TOKEN_SOURCE="${CONFIG##*/}"
fi
# The birthday bot wants the same scopes, so its token works here. Offering it
# saves a trip to api.slack.com for the common case of one app posting both.
if [[ -z "$EXISTING_TOKEN" && -f "$BDAY_CONFIG" ]]; then
    EXISTING_TOKEN="$(grep -oE "'slack_bot_token'[^']*'[^']*'" "$BDAY_CONFIG" | tail -1 | grep -oE "xoxb-[^']*" || true)"
    [[ -n "$EXISTING_TOKEN" ]] && TOKEN_SOURCE="the birthday bot's config"
fi

if [[ -n "$EXISTING_TOKEN" ]]; then
    ok "Found a bot token in ${TOKEN_SOURCE}"
    read -rp "  Use it? [Y/n] " keep
    [[ "${keep,,}" == "n" ]] && EXISTING_TOKEN=""
fi

if [[ -z "$EXISTING_TOKEN" ]]; then
    cat <<EOF

  Need a bot token. The quickest route (about 2 minutes):

    1. https://api.slack.com/apps  ->  Create New App  ->  From an app manifest
    2. Pick your workspace, choose YAML, and paste the contents of:
         ${SCRIPT_DIR}/slack-app-manifest.yml
       (that presets the name and every permission — no scope hunting)
    3. Create  ->  Install to Workspace  ->  Allow
    4. OAuth & Permissions  ->  copy the Bot User OAuth Token

EOF
    read -rsp "  Paste the xoxb- token (hidden): " TOKEN; echo
    TOKEN="$(echo "$TOKEN" | tr -d '[:space:]')"
    [[ -n "$TOKEN" ]] || die "No token entered."
    [[ "$TOKEN" == xoxb-* ]] || die "That doesn't look like a bot token — it should start with 'xoxb-'.
(An 'xoxp-' token is a user token and won't work here.)"
else
    TOKEN="$EXISTING_TOKEN"
fi

# =============================================================================
#  3. Write the config far enough to look the channel up
#
#  The token has to be on disk before the bot can ask Slack anything, so the
#  config is created here rather than at the end. Everything after this point
#  edits it in place.
# =============================================================================

set_key() {  # set_key <key> <php-literal>
    sed -i -E "s|^([[:space:]]*'${1}'[[:space:]]*=>[[:space:]]*).*\$|\1${2},|" "$CONFIG"
}

if [[ ! -f "$CONFIG" ]]; then
    cp "$EXAMPLE" "$CONFIG"
    info "Created ${CONFIG}"
else
    cp "$CONFIG" "${CONFIG}.bak"
    info "Updating ${CONFIG} (previous version saved as ${CONFIG##*/}.bak)"
fi
set_key slack_bot_token "'${TOKEN}'"
# uid 33 is what the app's container runs as; a root-owned 0600 file would be
# unreadable to the bot itself — and this has to work before the lookup below.
chown 33:33 "$CONFIG"
chmod 600 "$CONFIG"

hdr "3/5  Channel"

echo "  Which channel should it post in?"
echo -e "${DIM}    Just the name is fine: #general${NC}"
echo -e "${DIM}    A pasted channel link or a raw ID (C0123456789) also work.${NC}"
echo

CHAN=""
# Created here, not inline in the redirect below: `${VAR:=$(mktemp)}` inside a
# command substitution assigns in the SUBSHELL, leaving it unset out here — and
# `set -u` then turns the error path into its own error.
TMP_ERR="$(mktemp)"
trap 'rm -f "$TMP_ERR"' EXIT
for attempt in 1 2 3; do
    read -rp "  Channel: " CHAN_RAW
    CHAN_RAW="$(echo "$CHAN_RAW" | tr -d '[:space:]')"
    if [[ -z "$CHAN_RAW" ]]; then
        warn "Nothing entered."
        continue
    fi
    # The bot does the lookup, so this understands names, links and IDs
    # identically — and the same code runs at post time.
    if CHAN="$(run_bot --resolve-channel="$CHAN_RAW" 2>"$TMP_ERR")"; then
        CHAN="$(echo "$CHAN" | tr -d '[:space:]')"
        RESOLVED_NOTE="$(cat "$TMP_ERR" 2>/dev/null || true)"
        [[ -n "$RESOLVED_NOTE" ]] && echo -e "${DIM}    ${RESOLVED_NOTE}${NC}"
        ok "Channel ${CHAN}"
        break
    fi
    CHAN=""
    warn "$(cat "$TMP_ERR" 2>/dev/null | head -3 || echo 'Lookup failed.')"
    if [[ $attempt -lt 3 ]]; then
        echo -e "${DIM}    A private channel needs the bot invited first: /invite @your-bot-name${NC}"
    fi
done
if [[ -z "$CHAN" ]]; then
    echo
    warn "Couldn't look that channel up — Slack may be unreachable from this host."
    read -rp "  Store what you typed and let the bot resolve it later? [y/N] " asis
    [[ "${asis,,}" == "y" ]] || die "Stopped. Fix the Slack connection (or use a channel ID) and re-run."
    CHAN="$CHAN_RAW"
fi
set_key slack_channel "'${CHAN}'"

# =============================================================================
#  4. Options
# =============================================================================
hdr "4/5  Options (press Enter for the default)"

echo "    1) Full name      Happy 5th anniversary, Mason Quinones!   [default]"
echo "    2) First name     Happy 5th anniversary, Mason!"
echo "    3) First + init.  Happy 5th anniversary, Mason Q.!"
read -rp "  How should names appear? [1]: " ns
case "${ns:-1}" in
    2) NAME_STYLE="first" ;;
    3) NAME_STYLE="first_initial" ;;
    *) NAME_STYLE="full" ;;
esac

echo
echo "  Which anniversaries should it post?"
echo
echo "    1) Every year                                       [default]"
echo "    2) Milestone years only (1, 5, 10, 15, 20, 25 …)"
echo -e "${DIM}    Milestone-only is much quieter, but a third year then passes${NC}"
echo -e "${DIM}    in silence. You can change this later on the Anniversaries page.${NC}"
read -rp "  Choice [1]: " cy
case "${cy:-1}" in
    2) CELEBRATE="milestones" ;;
    *) CELEBRATE="all" ;;
esac

echo
echo "  A free Giphy key gives a fresh GIF every day instead of cycling a fixed"
echo "  list (https://developers.giphy.com — 2 minutes). Enter to skip."
read -rp "  Giphy API key [skip]: " GIPHY
GIPHY="$(echo "${GIPHY:-}" | tr -d '[:space:]')"

echo
echo "  Anniversary posts appear under your Slack app's own name. If that app is"
echo "  shared with other integrations (the birthday bot, say), you can have"
echo "  these posts show a different name instead."
echo -e "${DIM}    Needs the chat:write.customize scope on the app. Without it the${NC}"
echo -e "${DIM}    message still posts — just under the app's own name.${NC}"
read -rp "  Display name [leave blank to keep the app's name]: " BOTNAME
BOTNAME="$(echo "${BOTNAME:-}" | sed 's/^ *//;s/ *$//')"
BOTICON=""
if [[ -n "$BOTNAME" ]]; then
    read -rp "  Icon emoji [:trophy:]: " BOTICON
    BOTICON="$(echo "${BOTICON:-:trophy:}" | tr -d '[:space:]')"
    # Slack wants it colon-wrapped; accept it typed either way.
    [[ "$BOTICON" == :*: ]] || BOTICON=":${BOTICON//:/}:"
fi

echo
read -rp "  Should the bot add the first 🎉 reaction itself? [Y/n] " rx
if [[ "${rx,,}" == "n" ]]; then ADD_REACTIONS="false"; else ADD_REACTIONS="true"; fi

# Which clock does "09:10" mean?
#
# systemd fires OnCalendar on the SYSTEM timezone; the app runs on its own.
# At this venue the host is UTC and the app is America/New_York, so a timer
# written as 09:00 posted at 05:00 local — for months, with nothing saying so.
# Resolve both and reconcile them, rather than leaving a four-hour trap in a
# comment nobody reads.
APP_TZ="$(run_bot --print-timezone 2>/dev/null | tr -d '[:space:]' || true)"
HOST_TZ="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
HOST_TZ="${HOST_TZ:-UTC}"
# "systemd 254 (254.5-1.fc39)" / "systemd 252 (v252.4-1.fc37)" -> 254 / 252
SYSTEMD_VER="$(systemctl --version 2>/dev/null | head -1 | awk '{print $2}' | tr -cd '0-9')"

echo
if [[ -n "$APP_TZ" ]]; then
    info "The app's clock is ${APP_TZ}; this machine's is ${HOST_TZ}."
fi
# The default is ten minutes after the birthday bot's usual time: on a morning
# when both fire, two posts in the same second read as one noisy blast.
read -rp "  What time should it post${APP_TZ:+, ${APP_TZ} time}? [09:10] " PTIME
PTIME="${PTIME:-09:10}"
[[ "$PTIME" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "Time must be HH:MM in 24-hour form, e.g. 09:10 or 16:30."

# Pin the zone onto the calendar spec so the posting time means what was just
# typed. Only when the two zones actually differ (otherwise the plain form is
# equivalent and reads better) and only on systemd 252+, which is where
# calendar specs learned to carry a timezone — emitting it on an older systemd
# would produce a unit that fails to parse and a timer that never fires at all.
TZ_SUFFIX=""
if [[ -n "$APP_TZ" && "$APP_TZ" != "$HOST_TZ" ]]; then
    if [[ -n "$SYSTEMD_VER" ]] && (( SYSTEMD_VER >= 252 )); then
        TZ_SUFFIX=" ${APP_TZ}"
        ok "The timer will be written in ${APP_TZ}, so ${PTIME} means ${PTIME} at the venue."
    else
        warn "This machine's clock is ${HOST_TZ} but the app runs on ${APP_TZ}, and"
        warn "systemd ${SYSTEMD_VER:-<unknown>} is too old (needs 252+) to put a zone in the"
        warn "timer. The message will go out at ${PTIME} ${HOST_TZ}, NOT ${PTIME} at the venue."
        warn "Either set the machine's zone (sudo timedatectl set-timezone ${APP_TZ})"
        warn "or pick the ${HOST_TZ} time you actually want."
        read -rp "  Continue anyway? [y/N] " tzok
        [[ "${tzok,,}" == "y" ]] || die "Stopped. Fix the timezone, then re-run."
    fi
fi

set_key name_style      "'${NAME_STYLE}'"
set_key celebrate_years "'${CELEBRATE}'"
set_key add_reactions   "${ADD_REACTIONS}"
set_key bot_username    "'${BOTNAME}'"
set_key bot_icon_emoji  "'${BOTICON}'"
[[ -n "$GIPHY" ]] && set_key giphy_api_key "'${GIPHY}'"
chown 33:33 "$CONFIG"; chmod 600 "$CONFIG"
ok "Config written, owned by uid 33, mode 600"

# Lint the config — but NOT with a bare `php`. This host runs PHP only inside
# the app's container, so a host interpreter usually does not exist; calling one
# and treating its absence as a parse failure reports a broken config when the
# config is fine. Use the container when there is no host php, and print the
# real parser output rather than asserting what went wrong.
lint_config() {
    if command -v php >/dev/null 2>&1; then
        php -l "$CONFIG" 2>&1
    else
        podman run --rm \
            -v "${INSTALL_DIR}:${INSTALL_DIR}:z" \
            -w "$INSTALL_DIR" -u 33:33 \
            "$MSSQL_IMAGE" php -l "$CONFIG" 2>&1
    fi
}
if ! LINT_OUT="$(lint_config)"; then
    die "The config didn't parse:

${LINT_OUT}

Restore the previous version with:
  sudo cp ${CONFIG}.bak ${CONFIG}"
fi
ok "Config parses cleanly"

# =============================================================================
#  5. Verify, then schedule
# =============================================================================
hdr "5/5  Checking it works"

if run_bot --check; then
    ok "All checks passed"
else
    warn "Some checks failed — see above."
    echo
    # The likeliest failure on a first install is the hire-date column, and the
    # fix for it is one command, so name it here rather than leaving --check's
    # one-line hint to be scrolled past.
    echo -e "${DIM}  If the roster query failed with \"Invalid column name\", this venue calls${NC}"
    echo -e "${DIM}  the hire-date column something other than DateOfHire. Find it with:${NC}"
    echo -e "${DIM}      sudo bash ${SCRIPT_DIR}/run.sh discover${NC}"
    echo -e "${DIM}  then paste its query into the Anniversaries page (or roster_sql above).${NC}"
    echo
    read -rp "  Install the timer anyway? [y/N] " anyway
    [[ "${anyway,,}" == "y" ]] || die "Stopped. Fix the problems above and re-run this installer."
fi

echo
read -rp "  Post a test message to ${CHAN} now? [Y/n] " tm
if [[ "${tm,,}" != "n" ]]; then
    if run_bot --test-slack; then
        ok "Test message sent — check the channel."
    else
        warn "Test message failed. The most common cause is the bot not being in"
        warn "the channel: in Slack, run  /invite @your-bot-name  and try again."
    fi
fi

# The chosen time, plus two catch-up firings the same day.
#
# A message that arrives tomorrow is not an anniversary message, so one shot a
# day is not enough: a blip at the posting minute would lose it outright. The
# retries cost nothing and cannot double-post — the bot records who it has
# congratulated, so a run with nothing left to do exits silently.
#
# Catch-ups that would spill past midnight are dropped rather than wrapped: at
# 23:45 they would land on the FOLLOWING day, which is a different day's
# message, not a retry of this one.
firing_times() {
    local base=$((10#${PTIME%%:*} * 60 + 10#${PTIME##*:}))
    local off t
    printf '%s\n' "$PTIME"
    for off in 30 90; do
        t=$((base + off))
        (( t < 1440 )) && printf '%02d:%02d\n' $((t / 60)) $((t % 60))
    done
}

CAL_LINES=""
while read -r t; do
    CAL_LINES+="OnCalendar=*-*-* ${t}:00${TZ_SUFFIX}"$'\n'
done < <(firing_times)
RETRY_TIMES="$(firing_times | tail -n +2 | paste -sd' ' -)"

echo
info "Installing the daily timer for ${PTIME}${TZ_SUFFIX:+ ${APP_TZ}}${RETRY_TIMES:+ (catch-up at ${RETRY_TIMES})}"
install -m 0644 "${UNIT_SRC}/${SERVICE}" "${UNIT_DST}/${SERVICE}"
# Replace the whole shipped block of OnCalendar lines with the generated one,
# rather than rewriting each in place — the unit ships with three of them, and
# a per-line substitution would give three copies of the same time.
awk -v cal="$CAL_LINES" '
    /^OnCalendar=/ { if (!seen) { printf "%s", cal; seen = 1 } next }
    { print }
' "${UNIT_SRC}/${TIMER}" > "${UNIT_DST}/${TIMER}"
chmod 0644 "${UNIT_DST}/${TIMER}"

# The units ship with the default install path baked in; rewrite them if this
# install lives somewhere else, or the timer would fire against the wrong tree.
if [[ "$INSTALL_DIR" != "/var/persist/pause-groups" ]]; then
    sed -i "s|/var/persist/pause-groups|${INSTALL_DIR}|g" "${UNIT_DST}/${SERVICE}"
    info "Units repointed at ${INSTALL_DIR}"
fi

systemctl daemon-reload
systemctl enable --now "$TIMER" >/dev/null 2>&1
if systemctl is-active --quiet "$TIMER"; then
    ok "Timer enabled"
else
    die "The timer didn't start. Check: systemctl status ${TIMER}"
fi

NEXT="$(systemctl list-timers "$TIMER" --no-pager --no-legend 2>/dev/null | awk '{print $1, $2, $3}' || true)"

hdr "Done"
cat <<EOF
  Posting to      ${CHAN} at ${PTIME}${TZ_SUFFIX:+ ${APP_TZ}} every day
  Celebrating     $([[ "$CELEBRATE" == "milestones" ]] && echo "milestone years only" || echo "every year")
  Shows up as     ${BOTNAME:-your Slack app's own name}
  Next run        ${NEXT:-see: systemctl list-timers ${TIMER}}
  Config          ${CONFIG}
  Log             ${DATA_DIR}/anniversaries.log

  Useful later:
    sudo bash ${SCRIPT_DIR}/run.sh --check      is everything still wired up?
    sudo bash ${SCRIPT_DIR}/run.sh --list       upcoming anniversaries
    sudo bash ${SCRIPT_DIR}/run.sh --dry-run    today's message, posting nothing
    sudo bash ${SCRIPT_DIR}/run.sh discover     find this venue's hire-date column
    sudo systemctl start ${SERVICE}             run it right now
    sudo bash ${BASH_SOURCE[0]}                 change the channel or time
    sudo bash ${BASH_SOURCE[0]} --uninstall     stop it posting

  Everything above is also editable on the Work Anniversaries page in CEplay,
  which is the easier place to change the wording.

EOF
