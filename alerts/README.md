# CEplay Game Out-of-Service Slack Alerter

A small Python service that polls the CenterEdge Card System API once a
minute and posts a Slack message when a game transitions into
`operationStatus = "outOfService"`. Designed to run as a systemd service
on Kubuntu (or any Linux box).

This is **read-only** with respect to CenterEdge — it only calls
`GET /games`. It does not modify anything in CEplay.

## What it does

- Logs in to CenterEdge using the same SHA-1 / bearer-token flow CEplay
  uses (see `lib/centeredge_client.php`).
- Polls `GET /games` (paginated) on a configurable interval (default 60s).
- For each game whose `operationStatus` is `outOfService` and that we
  haven't already alerted on, posts a single Slack message via
  `chat.postMessage` using a bot token.
- Persists a small JSON state file so a restart won't re-spam alerts for
  games that were already known to be down.
- Silently clears state for games that recover, so the next outage will
  alert again.

There are no recovery messages and no repeat alerts — one alert per
outage.

## Requirements

- Python 3.8+ (Kubuntu 22.04+ ships 3.10).
- Network egress to your CenterEdge API host and to `slack.com`.
- A Slack app with a bot token (`xoxb-...`) and the `chat:write` scope.
- No third-party Python packages — stdlib only.

## 1. Create the Slack bot

1. Go to https://api.slack.com/apps → **Create New App** → **From scratch**.
2. Name it (e.g. `CEplay Alerts`) and pick your workspace.
3. **OAuth & Permissions** → **Scopes** → **Bot Token Scopes** → add
   `chat:write`. (Add `chat:write.public` too if you want it to post in
   channels it isn't a member of.)
4. **Install to Workspace** → copy the **Bot User OAuth Token** (starts
   with `xoxb-`).
5. In Slack, invite the bot to your alerts channel:
   `/invite @CEplay Alerts`
6. Get the **channel ID** (right-click the channel → *View channel
   details* → scroll to the bottom). It looks like `C0123456789`.

## 2. Install the script

From the repo root, on your Kubuntu host:

```bash
sudo install -m 0755 alerts/ceplay_game_alerts.py /usr/local/bin/ceplay_game_alerts.py
```

## 3. Create the config file

```bash
sudo mkdir -p /etc/ceplay-alerts
sudo cp alerts/config.example.ini /etc/ceplay-alerts/config.ini
sudo nano /etc/ceplay-alerts/config.ini      # fill in real values
sudo chmod 600 /etc/ceplay-alerts/config.ini
```

Fill in:

- `[centeredge]` — `base_url`, `username`, `password` (and `api_key` if
  your CenterEdge install requires one).
- `[slack]` — `bot_token` (`xoxb-...`) and `channel` (channel ID, not name).
- `[polling]` — `interval_seconds` defaults to 60; change `location_label`
  to something useful for your site.

## 4. Test the config before installing the service

Run a one-shot poll cycle as your user:

```bash
sudo /usr/local/bin/ceplay_game_alerts.py --config /etc/ceplay-alerts/config.ini --once -v
```

Or just verify Slack delivery:

```bash
sudo /usr/local/bin/ceplay_game_alerts.py --config /etc/ceplay-alerts/config.ini --test-slack
```

You should see a `:wave:` test message land in your channel.

## 5. Install the systemd service

Create the service user (no shell, no home), drop in the unit file,
and enable it:

```bash
# Dedicated unprivileged user.
sudo useradd --system --no-create-home --shell /usr/sbin/nologin ceplay-alerts

# Let the service user read its config.
sudo chown root:ceplay-alerts /etc/ceplay-alerts/config.ini
sudo chmod 640 /etc/ceplay-alerts/config.ini

# Install the unit.
sudo install -m 0644 alerts/ceplay-game-alerts.service /etc/systemd/system/ceplay-game-alerts.service

sudo systemctl daemon-reload
sudo systemctl enable --now ceplay-game-alerts.service
```

The unit declares `StateDirectory=ceplay-alerts`, so systemd will create
`/var/lib/ceplay-alerts/` owned by the service user automatically. The
state file (`state.json`) lives there.

## Operating the service

```bash
# Status
sudo systemctl status ceplay-game-alerts

# Live logs
sudo journalctl -u ceplay-game-alerts -f

# Restart after editing config
sudo systemctl restart ceplay-game-alerts

# Stop / disable
sudo systemctl stop ceplay-game-alerts
sudo systemctl disable ceplay-game-alerts
```

## Troubleshooting

- **`Config file not found`** — make sure `/etc/ceplay-alerts/config.ini`
  exists and is readable by `ceplay-alerts`.
- **`Slack rejected message: error=not_in_channel`** — invite the bot to
  the channel: `/invite @your-bot-name`.
- **`Slack rejected message: error=channel_not_found`** — you used a
  channel name instead of an ID, or the ID is wrong.
- **`HTTP 401` on every poll** — wrong CenterEdge username/password, or
  the system clock is off by more than 5 minutes (the API's SHA-1 auth
  rejects stale timestamps). Check `timedatectl status`.
- **Alerts firing for games that aren't actually broken** — verify in the
  CEplay UI that those games really are showing as `outOfService` from
  the card system. This service only reflects what the API reports.

## Files in this directory

| File                            | Purpose                                      |
|---------------------------------|----------------------------------------------|
| `ceplay_game_alerts.py`         | The service script (stdlib only, single file)|
| `config.example.ini`            | Annotated config template                    |
| `ceplay-game-alerts.service`    | systemd unit (hardened)                      |
| `.gitignore`                    | Ignores local `config.ini` / `state.json`    |
| `README.md`                     | This file                                    |
