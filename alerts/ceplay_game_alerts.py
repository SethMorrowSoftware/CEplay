#!/usr/bin/env python3
"""
CEplay Game Out-of-Service Slack Alerter.

Polls the CenterEdge Card System API once per minute (configurable) and
posts a single Slack alert each time a game transitions into operationStatus
"outOfService". Designed to run as a systemd service.

A small JSON state file tracks which games are currently flagged so we
don't re-alert every minute while a game stays down. When a game recovers,
its entry is silently cleared so the next outage will alert again.

No third-party dependencies; uses Python stdlib only.
"""

from __future__ import annotations

import argparse
import base64
import configparser
import datetime as dt
import hashlib
import json
import logging
import os
import signal
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Tuple

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

DEFAULT_CONFIG_PATH = "/etc/ceplay-alerts/config.ini"
DEFAULT_STATE_PATH = "/var/lib/ceplay-alerts/state.json"
DEFAULT_INTERVAL_SECONDS = 60
DEFAULT_HTTP_TIMEOUT = 20
DEFAULT_MAX_RETRIES = 3
DEFAULT_PAGE_SIZE = 100  # API maximum per spec.
SLACK_API_URL = "https://slack.com/api/chat.postMessage"

OOS_STATUS = "outOfService"
USER_AGENT = "ceplay-game-alerts/1.0"

log = logging.getLogger("ceplay-alerts")


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------


class Config:
    """Typed view over the INI config with sensible defaults."""

    def __init__(self, parser: configparser.ConfigParser) -> None:
        ce = parser["centeredge"]
        self.ce_base_url: str = ce["base_url"].rstrip("/")
        self.ce_username: str = ce["username"]
        self.ce_password: str = ce["password"]
        self.ce_api_key: Optional[str] = ce.get("api_key", fallback="") or None

        sl = parser["slack"]
        self.slack_bot_token: str = sl["bot_token"]
        self.slack_channel: str = sl["channel"]
        self.slack_mention: str = sl.get("mention", fallback="").strip()

        po = parser["polling"] if parser.has_section("polling") else None
        self.interval_seconds: int = (
            int(po.get("interval_seconds", DEFAULT_INTERVAL_SECONDS))
            if po
            else DEFAULT_INTERVAL_SECONDS
        )
        self.state_file: str = (
            po.get("state_file", DEFAULT_STATE_PATH) if po else DEFAULT_STATE_PATH
        )
        self.http_timeout: int = (
            int(po.get("http_timeout", DEFAULT_HTTP_TIMEOUT))
            if po
            else DEFAULT_HTTP_TIMEOUT
        )
        self.location_label: str = (
            po.get("location_label", "") if po else ""
        ).strip()

        if self.interval_seconds < 5:
            raise ValueError("polling.interval_seconds must be >= 5")
        if not self.ce_base_url.startswith(("http://", "https://")):
            raise ValueError("centeredge.base_url must start with http:// or https://")
        if not self.slack_bot_token.startswith("xoxb-"):
            log.warning(
                "slack.bot_token does not look like a bot token (expected 'xoxb-' prefix)"
            )


def load_config(path: str) -> Config:
    if not os.path.isfile(path):
        raise SystemExit(
            f"Config file not found: {path}\n"
            f"Copy alerts/config.example.ini to {path} and fill in the values."
        )
    parser = configparser.ConfigParser()
    parser.read(path, encoding="utf-8")
    for section in ("centeredge", "slack"):
        if not parser.has_section(section):
            raise SystemExit(f"Config is missing required [{section}] section")
    return Config(parser)


# ---------------------------------------------------------------------------
# State persistence
# ---------------------------------------------------------------------------


class State:
    """
    Tracks games currently flagged outOfService so we don't re-alert on every
    poll while a game stays down.

    Schema:
        {
          "outage_states": {
            "<gameId>": {
              "name": "...",
              "since": "2026-05-10T13:00:00Z"
            }
          },
          "last_run": "2026-05-10T13:01:00Z"
        }
    """

    def __init__(self, path: str) -> None:
        self.path = path
        self.outages: Dict[str, Dict[str, Any]] = {}
        self.last_run: Optional[str] = None
        self._load()

    def _load(self) -> None:
        if not os.path.isfile(self.path):
            log.info("No prior state file at %s; starting fresh.", self.path)
            return
        try:
            with open(self.path, "r", encoding="utf-8") as f:
                data = json.load(f)
            self.outages = data.get("outage_states", {}) or {}
            self.last_run = data.get("last_run")
            log.info(
                "Loaded state: %d game(s) currently flagged out of service.",
                len(self.outages),
            )
        except (OSError, json.JSONDecodeError) as e:
            log.warning(
                "Could not read state file %s (%s); starting fresh.", self.path, e
            )
            self.outages = {}
            self.last_run = None

    def save(self) -> None:
        directory = os.path.dirname(self.path) or "."
        try:
            os.makedirs(directory, exist_ok=True)
            tmp = self.path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(
                    {
                        "outage_states": self.outages,
                        "last_run": self.last_run,
                    },
                    f,
                    indent=2,
                )
            os.replace(tmp, self.path)
        except OSError as e:
            log.error("Failed to persist state to %s: %s", self.path, e)


# ---------------------------------------------------------------------------
# CenterEdge API client (stdlib only)
# ---------------------------------------------------------------------------


class CenterEdgeError(Exception):
    pass


class CenterEdgeClient:
    def __init__(
        self,
        base_url: str,
        username: str,
        password: str,
        api_key: Optional[str] = None,
        timeout: int = DEFAULT_HTTP_TIMEOUT,
        max_retries: int = DEFAULT_MAX_RETRIES,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.username = username
        self.password = password
        self.api_key = api_key
        self.timeout = timeout
        self.max_retries = max(1, max_retries)
        self._token: Optional[str] = None

    # ---- Auth ----

    def _login(self) -> str:
        """
        Authenticate using SHA-1(username + password + requestTimestamp), base64.
        Mirrors the PHP client behaviour in lib/centeredge_client.php.
        """
        now = dt.datetime.now(dt.timezone.utc)
        # ISO-8601 UTC with milliseconds, 'Z' suffix — matches the PHP client.
        ts = now.strftime("%Y-%m-%dT%H:%M:%S.") + f"{now.microsecond // 1000:03d}Z"
        digest = hashlib.sha1(
            (self.username + self.password + ts).encode("utf-8")
        ).digest()
        password_hash = base64.b64encode(digest).decode("ascii")

        body = {
            "username": self.username,
            "passwordHash": password_hash,
            # Plaintext is included to match the PHP client (some deployments
            # require it). The card system can ignore it if not enabled.
            "password": self.password,
            "requestTimestamp": ts,
        }
        log.debug("Authenticating to %s/login", self.base_url)
        result = self._http("POST", "/login", body=body, authed=False)
        token = result.get("bearerToken")
        if not token:
            raise CenterEdgeError("Login response missing 'bearerToken'")
        self._token = token
        return token

    def _ensure_token(self) -> str:
        if not self._token:
            return self._login()
        return self._token

    # ---- Public ----

    def get_all_games(self) -> List[Dict[str, Any]]:
        """Fetch every game from /games, paginating with skip/take."""
        all_games: List[Dict[str, Any]] = []
        skip = 0
        # Defensive cap: 100 pages * 100 games = 10,000 games.
        for _ in range(100):
            qs = urllib.parse.urlencode({"skip": skip, "take": DEFAULT_PAGE_SIZE})
            result = self._http("GET", f"/games?{qs}")
            games = result.get("games") or []
            all_games.extend(games)
            total = result.get("totalCount")
            if len(games) < DEFAULT_PAGE_SIZE:
                return all_games
            skip += len(games)
            if isinstance(total, int) and skip >= total:
                return all_games
        raise CenterEdgeError("Pagination exceeded safety limit while fetching games")

    # ---- Internals ----

    def _http(
        self,
        method: str,
        path: str,
        body: Optional[Dict[str, Any]] = None,
        authed: bool = True,
    ) -> Dict[str, Any]:
        """
        HTTP request with auto-relogin on 401 and retry-with-backoff on transient
        errors (network failure, 5xx, 408, 429).
        """
        url = self.base_url + path
        backoff = [1, 2, 4]
        last_err: Optional[Exception] = None
        relogin_done = False

        for attempt in range(self.max_retries):
            try:
                headers = {
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "User-Agent": USER_AGENT,
                }
                if authed:
                    token = self._ensure_token()
                    headers["Authorization"] = f"Bearer {token}"
                if self.api_key:
                    headers["X-Api-Key"] = self.api_key

                payload = json.dumps(body).encode("utf-8") if body is not None else None
                req = urllib.request.Request(
                    url, data=payload, method=method, headers=headers
                )
                with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                    raw = resp.read()
                    if not raw:
                        return {}
                    return json.loads(raw.decode("utf-8"))

            except urllib.error.HTTPError as e:
                status = e.code
                err_body = ""
                try:
                    err_body = e.read().decode("utf-8", errors="replace")[:300]
                except Exception:
                    pass

                if status == 401 and authed and not relogin_done:
                    log.info("Got 401; re-authenticating and retrying.")
                    self._token = None
                    relogin_done = True
                    continue  # Retry without consuming a backoff slot.

                if status in (408, 429) or 500 <= status < 600:
                    last_err = CenterEdgeError(
                        f"HTTP {status} from {method} {path}: {err_body}"
                    )
                    self._sleep_backoff(attempt, backoff, last_err)
                    continue

                raise CenterEdgeError(
                    f"HTTP {status} from {method} {path}: {err_body}"
                ) from e

            except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
                last_err = CenterEdgeError(f"Network error on {method} {path}: {e}")
                self._sleep_backoff(attempt, backoff, last_err)
                continue
            except json.JSONDecodeError as e:
                raise CenterEdgeError(
                    f"Non-JSON response from {method} {path}: {e}"
                ) from e

        assert last_err is not None
        raise last_err

    @staticmethod
    def _sleep_backoff(attempt: int, backoff: List[int], err: Exception) -> None:
        delay = backoff[min(attempt, len(backoff) - 1)]
        log.warning(
            "Transient API error (attempt %d): %s — sleeping %ds.",
            attempt + 1,
            err,
            delay,
        )
        time.sleep(delay)


# ---------------------------------------------------------------------------
# Slack
# ---------------------------------------------------------------------------


def post_slack_message(
    bot_token: str,
    channel: str,
    text: str,
    blocks: Optional[List[Dict[str, Any]]] = None,
    timeout: int = DEFAULT_HTTP_TIMEOUT,
) -> None:
    """
    Post via Slack's chat.postMessage. Logs (and swallows) any error so a Slack
    outage does not crash the polling loop.
    """
    payload: Dict[str, Any] = {"channel": channel, "text": text}
    if blocks:
        payload["blocks"] = blocks
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        SLACK_API_URL,
        data=data,
        method="POST",
        headers={
            "Authorization": f"Bearer {bot_token}",
            "Content-Type": "application/json; charset=utf-8",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            try:
                parsed = json.loads(body)
            except json.JSONDecodeError:
                log.error("Slack returned non-JSON response: %s", body[:200])
                return
            if not parsed.get("ok"):
                log.error(
                    "Slack rejected message: error=%s response=%s",
                    parsed.get("error"),
                    body[:300],
                )
            else:
                log.info("Slack alert delivered to %s", channel)
    except urllib.error.HTTPError as e:
        log.error("Slack HTTP error %s: %s", e.code, e.read()[:200])
    except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
        log.error("Slack network error: %s", e)


def build_outage_blocks(
    game: Dict[str, Any], location_label: str, mention: str
) -> Tuple[str, List[Dict[str, Any]]]:
    name = game.get("name", "(unnamed)")
    gid = game.get("id", "?")
    categories = game.get("categories") or []
    cat_str = ", ".join(str(c) for c in categories) if categories else "—"
    where = f" at *{location_label}*" if location_label else ""
    fallback = f":rotating_light: Game OUT OF SERVICE: {name} (id {gid})"

    body_text = (
        f"*{name}* (id `{gid}`) is reporting `outOfService`{where}.\n"
        f"*Detected:* {now_iso()}\n"
        f"*Categories:* {cat_str}"
    )
    if mention:
        body_text = f"{mention}\n{body_text}"

    blocks: List[Dict[str, Any]] = [
        {
            "type": "header",
            "text": {"type": "plain_text", "text": ":rotating_light: Game Out of Service"},
        },
        {"type": "section", "text": {"type": "mrkdwn", "text": body_text}},
    ]
    return fallback, blocks


# ---------------------------------------------------------------------------
# Polling loop
# ---------------------------------------------------------------------------


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Alerter:
    def __init__(self, cfg: Config, state: State, client: CenterEdgeClient) -> None:
        self.cfg = cfg
        self.state = state
        self.client = client

    def tick(self) -> None:
        """Run one poll cycle. Logs and swallows errors so the loop survives."""
        try:
            games = self.client.get_all_games()
        except CenterEdgeError as e:
            log.error("Failed to fetch games this cycle: %s", e)
            return

        log.info("Fetched %d game(s) from CenterEdge.", len(games))
        seen_oos_ids: set = set()
        now_str = now_iso()

        for game in games:
            gid = str(game.get("id", "")).strip()
            if not gid:
                continue
            if game.get("operationStatus") != OOS_STATUS:
                continue
            seen_oos_ids.add(gid)
            if gid in self.state.outages:
                # Already alerted; skip until it recovers.
                continue
            log.warning(
                "NEW outOfService game: %s (id %s)", game.get("name"), gid
            )
            self.state.outages[gid] = {
                "name": game.get("name"),
                "since": now_str,
            }
            fallback, blocks = build_outage_blocks(
                game, self.cfg.location_label, self.cfg.slack_mention
            )
            post_slack_message(
                self.cfg.slack_bot_token,
                self.cfg.slack_channel,
                fallback,
                blocks,
                timeout=self.cfg.http_timeout,
            )

        # Silently clear entries for games that recovered, so the next outage
        # will alert again. No recovery message is sent.
        recovered = [gid for gid in self.state.outages if gid not in seen_oos_ids]
        for gid in recovered:
            entry = self.state.outages.pop(gid)
            log.info(
                "Cleared recovered game from state: %s (id %s)", entry.get("name"), gid
            )

        self.state.last_run = now_str
        self.state.save()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


_stop_requested = False


def _handle_signal(signum, _frame) -> None:
    global _stop_requested
    log.info("Received signal %s; will exit after current cycle.", signum)
    _stop_requested = True


def setup_logging(verbose: bool) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
        stream=sys.stdout,
    )


def parse_args(argv: Optional[List[str]] = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument(
        "-c",
        "--config",
        default=os.environ.get("CEPLAY_ALERTS_CONFIG", DEFAULT_CONFIG_PATH),
        help=f"Path to INI config (default: {DEFAULT_CONFIG_PATH})",
    )
    p.add_argument(
        "--once",
        action="store_true",
        help="Run a single poll cycle and exit (useful for testing).",
    )
    p.add_argument(
        "--test-slack",
        action="store_true",
        help="Send a single test message to the configured Slack channel and exit.",
    )
    p.add_argument(
        "-v", "--verbose", action="store_true", help="Enable DEBUG logging."
    )
    return p.parse_args(argv)


def main(argv: Optional[List[str]] = None) -> int:
    args = parse_args(argv)
    setup_logging(args.verbose)

    cfg = load_config(args.config)
    log.info("Loaded config from %s", args.config)

    if args.test_slack:
        post_slack_message(
            cfg.slack_bot_token,
            cfg.slack_channel,
            ":wave: ceplay-game-alerts test message — connection works.",
            timeout=cfg.http_timeout,
        )
        return 0

    state = State(cfg.state_file)
    client = CenterEdgeClient(
        base_url=cfg.ce_base_url,
        username=cfg.ce_username,
        password=cfg.ce_password,
        api_key=cfg.ce_api_key,
        timeout=cfg.http_timeout,
    )
    alerter = Alerter(cfg, state, client)

    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)

    if args.once:
        alerter.tick()
        return 0

    log.info(
        "Starting polling loop: every %ds, state at %s.",
        cfg.interval_seconds,
        cfg.state_file,
    )
    while not _stop_requested:
        cycle_started = time.monotonic()
        try:
            alerter.tick()
        except Exception:  # noqa: BLE001 — never let the loop die.
            log.exception("Unhandled error in poll cycle; continuing.")
        elapsed = time.monotonic() - cycle_started
        # Sleep the remainder of the interval, but in 1-second slices so SIGTERM
        # is responsive (systemd default TimeoutStopSec is 90s).
        deadline = time.monotonic() + max(0.0, cfg.interval_seconds - elapsed)
        while not _stop_requested and time.monotonic() < deadline:
            time.sleep(min(1.0, deadline - time.monotonic()))
    log.info("Shutdown complete.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
