#!/usr/bin/env bash
# =============================================================================
#  Birthday bot — one-command installer.
#
#      sudo bash /var/persist/pause-groups/birthdays/install.sh
#
#  Asks for the Slack token and channel, checks everything works, writes the
#  config with the right owner and permissions, and installs the daily timer.
#  Safe to re-run: it edits the existing config in place rather than starting
#  over, so use it to change the channel or the posting time later too.
#
#      sudo bash .../install.sh --uninstall     remove the timer (keeps config)
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
CONFIG="${DATA_DIR}/birthday_config.php"
EXAMPLE="${SCRIPT_DIR}/config.example.php"
UNIT_SRC="${SCRIPT_DIR}/systemd"
UNIT_DST="${PG_UNIT_DIR:-/etc/systemd/system}"
SERVICE="ceplay-birthdays.service"
TIMER="ceplay-birthdays.timer"

# --- Uninstall --------------------------------------------------------------
if [[ "${1:-}" == "--uninstall" ]]; then
    hdr "Removing the birthday bot timer"
    systemctl disable --now "$TIMER" 2>/dev/null || true
    rm -f "${UNIT_DST}/${TIMER}" "${UNIT_DST}/${SERVICE}"
    systemctl daemon-reload
    ok "Timer removed. Nothing will post from now on."
    echo -e "${DIM}  Your config is still at ${CONFIG} — delete it by hand if you want it gone.${NC}\n"
    exit 0
fi

echo -e "\n${BOLD}Birthday bot installer${NC}"
echo -e "${DIM}${INSTALL_DIR}${NC}"

# =============================================================================
#  1. Preflight — fail with the fix, not a stack trace
# =============================================================================
hdr "1/5  Checking this machine"

[[ $EUID -eq 0 ]] || die "Run this with sudo — it writes to ${DATA_DIR} and /etc/systemd/system.

  sudo bash ${BASH_SOURCE[0]}"

[[ -f "${SCRIPT_DIR}/birthday_bot.php" ]] || die "Can't find birthday_bot.php next to this script."
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

run_bot() { bash "${SCRIPT_DIR}/run.sh" "$@" 2>/dev/null; }

# =============================================================================
#  2. Slack
# =============================================================================
hdr "2/5  Slack"

EXISTING_TOKEN=""
if [[ -f "$CONFIG" ]]; then
    EXISTING_TOKEN="$(grep -oE "'slack_bot_token'[^']*'[^']*'" "$CONFIG" | tail -1 | grep -oE "xoxb-[^']*" || true)"
fi

if [[ -n "$EXISTING_TOKEN" ]]; then
    ok "Found an existing bot token in ${CONFIG##*/}"
    read -rp "  Keep it? [Y/n] " keep
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

# Channel: accept the ID, or the URL people actually copy out of Slack.
echo
echo "  Which channel should it post in?"
echo -e "${DIM}    In Slack: right-click the channel -> Copy link, and paste that here.${NC}"
echo -e "${DIM}    Or paste the channel ID from View channel details (e.g. C0123456789).${NC}"
echo
read -rp "  Channel: " CHAN_RAW
CHAN="$(echo "$CHAN_RAW" | grep -oE '[CGD][A-Z0-9]{6,}' | head -1 || true)"
[[ -n "$CHAN" ]] || die "Couldn't find a channel ID in \"${CHAN_RAW}\".
Paste either the channel link or an ID that looks like C0123456789."
ok "Channel ${CHAN}"

# =============================================================================
#  3. Options
# =============================================================================
hdr "3/5  Options (press Enter for the default)"

echo "  About a fifth of your staff are under 18. The bot never posts an age or"
echo "  a birth year, but you can also shorten how names appear."
echo
echo "    1) Full name      Happy Birthday, Mason Quinones!   [default]"
echo "    2) First name     Happy Birthday, Mason!"
echo "    3) First + init.  Happy Birthday, Mason Q.!"
read -rp "  Choice [1]: " ns
case "${ns:-1}" in
    2) NAME_STYLE="first" ;;
    3) NAME_STYLE="first_initial" ;;
    *) NAME_STYLE="full" ;;
esac

echo
echo "  A free Giphy key gives a fresh GIF every day instead of cycling a fixed"
echo "  list (https://developers.giphy.com — 2 minutes). Enter to skip."
read -rp "  Giphy API key [skip]: " GIPHY
GIPHY="$(echo "${GIPHY:-}" | tr -d '[:space:]')"

echo
read -rp "  Should the bot add the first 🎉 reaction itself? [Y/n] " rx
if [[ "${rx,,}" == "n" ]]; then ADD_REACTIONS="false"; else ADD_REACTIONS="true"; fi

echo
read -rp "  What time should it post? [09:00] " PTIME
PTIME="${PTIME:-09:00}"
[[ "$PTIME" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "Time must be HH:MM in 24-hour form, e.g. 09:00 or 16:30."

# =============================================================================
#  4. Write the config
# =============================================================================
hdr "4/5  Writing the config"

# data/ rather than birthdays/: update.sh syncs the repo over the install
# directory with `rsync --delete`, and this file is gitignored — so a copy
# beside the code would be deleted by the next deploy.
if [[ ! -f "$CONFIG" ]]; then
    cp "$EXAMPLE" "$CONFIG"
    info "Created ${CONFIG}"
else
    cp "$CONFIG" "${CONFIG}.bak"
    info "Updating ${CONFIG} (previous version saved as ${CONFIG##*/}.bak)"
fi

set_key() {  # set_key <key> <php-literal>
    local key="$1" val="$2"
    sed -i -E "s|^([[:space:]]*'${key}'[[:space:]]*=>[[:space:]]*).*\$|\1${val},|" "$CONFIG"
}
set_key slack_bot_token "'${TOKEN}'"
set_key slack_channel   "'${CHAN}'"
set_key name_style      "'${NAME_STYLE}'"
set_key add_reactions   "${ADD_REACTIONS}"
[[ -n "$GIPHY" ]] && set_key giphy_api_key "'${GIPHY}'"

# uid 33 is what the app's container runs as; root-owned 0600 would be
# unreadable to the bot itself.
chown 33:33 "$CONFIG"
chmod 600 "$CONFIG"
ok "Config written, owned by uid 33, mode 600"

php -l "$CONFIG" >/dev/null 2>&1 || die "The config has a syntax error — restore ${CONFIG}.bak and report this."

# =============================================================================
#  5. Verify, then schedule
# =============================================================================
hdr "5/5  Checking it works"

if run_bot --check; then
    ok "All checks passed"
else
    warn "Some checks failed — see above."
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

echo
info "Installing the daily timer for ${PTIME}"
install -m 0644 "${UNIT_SRC}/${SERVICE}" "${UNIT_DST}/${SERVICE}"
sed -e "s|^OnCalendar=.*|OnCalendar=*-*-* ${PTIME}:00|" \
    -e "s|— 09:00 daily|— ${PTIME} daily|" \
    "${UNIT_SRC}/${TIMER}" > "${UNIT_DST}/${TIMER}"
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
  Posting to      ${CHAN} at ${PTIME} every day
  Next run        ${NEXT:-see: systemctl list-timers ${TIMER}}
  Config          ${CONFIG}
  Log             ${DATA_DIR}/birthdays.log

  Useful later:
    sudo bash ${SCRIPT_DIR}/run.sh --check      is everything still wired up?
    sudo bash ${SCRIPT_DIR}/run.sh --list       upcoming birthdays
    sudo bash ${SCRIPT_DIR}/run.sh --dry-run    today's message, posting nothing
    sudo systemctl start ${SERVICE}             run it right now
    sudo bash ${BASH_SOURCE[0]}                 change the channel or time
    sudo bash ${BASH_SOURCE[0]} --uninstall     stop it posting

EOF
