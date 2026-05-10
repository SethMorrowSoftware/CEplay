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
import email.utils
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
SLACK_MAX_RETRIES = 4
SLACK_MAX_RETRY_AFTER = 30  # Cap honored Retry-After so we don't stall a tick.
STATE_VERSION = 1

OOS_STATUS = "outOfService"
USER_AGENT = "ceplay-game-alerts/1.0"

log = logging.getLogger("ceplay-alerts")


# ---------------------------------------------------------------------------
# HTTP: a urllib opener that REFUSES redirects.
#
# urllib's default HTTPRedirectHandler will silently re-send the
# Authorization / X-Api-Key headers to the redirect target — even on a
# cross-host redirect — which would leak the bearer token (or Slack bot
# token) to whoever the misconfigured/malicious upstream points at. The
# CEplay PHP client refuses redirects for exactly this reason; we do the
# same. A 30x is surfaced as an HTTPError so callers can decide how to
# react.
# ---------------------------------------------------------------------------


class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        # Returning None tells urllib not to follow; HTTPError will be raised.
        return None


_OPENER = urllib.request.build_opener(_NoRedirectHandler())


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
        if self.http_timeout < 1:
            raise ValueError("polling.http_timeout must be >= 1")
        if not self.ce_base_url.startswith(("http://", "https://")):
            raise ValueError("centeredge.base_url must start with http:// or https://")
        if not self.ce_username or not self.ce_password:
            raise ValueError("centeredge.username and centeredge.password are required")
        if not self.slack_bot_token:
            raise ValueError("slack.bot_token is required")
        if not self.slack_channel:
            raise ValueError("slack.channel is required")
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
    try:
        parser.read(path, encoding="utf-8")
    except configparser.Error as e:
        raise SystemExit(f"Config file {path} is not a valid INI file: {e}") from e
    for section in ("centeredge", "slack"):
        if not parser.has_section(section):
            raise SystemExit(f"Config is missing required [{section}] section")
    try:
        return Config(parser)
    except (KeyError, ValueError) as e:
        raise SystemExit(f"Config error in {path}: {e}") from e


# ---------------------------------------------------------------------------
# State persistence
# ---------------------------------------------------------------------------


def _fsync_dir(path: str) -> None:
    """Best-effort fsync of a directory so a rename is durable across power loss."""
    try:
        fd = os.open(path, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    except OSError:
        # Some filesystems (e.g. tmpfs) don't support directory fsync. Ignore.
        pass


class State:
    """
    Tracks games currently flagged outOfService so we don't re-alert on every
    poll while a game stays down.

    Schema (see STATE_VERSION):
        {
          "version": 1,
          "outage_states": {
            "<gameId>": {"name": "...", "since": "2026-05-10T13:00:00Z"}
          },
          "last_run": "2026-05-10T13:01:00Z"
        }
    """

    def __init__(self, path: str) -> None:
        self.path = path
        self.outages: Dict[str, Dict[str, Any]] = {}
        self.last_run: Optional[str] = None
        # True until the first successful save with real state. When True,
        # tick() will populate state silently rather than firing alerts for
        # every currently-OOS game (avoids alert storms after a fresh install
        # or state-file loss).
        self.needs_seed: bool = False
        self._load()

    def _load(self) -> None:
        if not os.path.isfile(self.path):
            log.info(
                "No prior state file at %s; first cycle will seed silently.",
                self.path,
            )
            self.needs_seed = True
            return
        try:
            with open(self.path, "r", encoding="utf-8") as f:
                data = json.load(f)
            if not isinstance(data, dict):
                raise ValueError("state file is not a JSON object")
            outages = data.get("outage_states", {}) or {}
            if not isinstance(outages, dict):
                raise ValueError("outage_states is not a JSON object")
            self.outages = {str(k): v for k, v in outages.items() if isinstance(v, dict)}
            self.last_run = data.get("last_run")
            log.info(
                "Loaded state: %d game(s) currently flagged out of service.",
                len(self.outages),
            )
        except (OSError, json.JSONDecodeError, ValueError) as e:
            log.warning(
                "Could not read state file %s (%s); first cycle will seed silently.",
                self.path,
                e,
            )
            self.outages = {}
            self.last_run = None
            self.needs_seed = True

    def save(self) -> None:
        """Atomic, fsync'd save. Logs and swallows OSError so the loop survives."""
        directory = os.path.dirname(self.path) or "."
        try:
            os.makedirs(directory, exist_ok=True)
            tmp = self.path + ".tmp"
            with open(tmp, "w", encoding="utf-8") as f:
                json.dump(
                    {
                        "version": STATE_VERSION,
                        "outage_states": self.outages,
                        "last_run": self.last_run,
                    },
                    f,
                    indent=2,
                )
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.path)
            _fsync_dir(directory)
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
        """
        Fetch every game from /games, paginating with skip/take.

        Termination rules, in order:
          - When the API reports `totalCount`, stop after we've collected that many.
          - Otherwise, stop when the API returns fewer than `DEFAULT_PAGE_SIZE`.
          - Hard cap at 100 pages (10,000 games) to defend against a server that
            ignores `skip` and returns the same page forever.
        """
        all_games: List[Dict[str, Any]] = []
        skip = 0
        total: Optional[int] = None
        for _ in range(100):
            qs = urllib.parse.urlencode({"skip": skip, "take": DEFAULT_PAGE_SIZE})
            result = self._http("GET", f"/games?{qs}")
            games = result.get("games") or []
            if not isinstance(games, list):
                raise CenterEdgeError("/games response 'games' is not a list")
            all_games.extend(games)
            reported = result.get("totalCount")
            if isinstance(reported, int) and reported >= 0:
                total = reported
            skip += len(games)
            if total is not None:
                if skip >= total:
                    return all_games
                # Don't trust a short page when the server told us a total.
                # Keep paging until we've reached totalCount or hit the cap.
                if not games:
                    # Server told us more exist but returned nothing — bail
                    # rather than spin.
                    raise CenterEdgeError(
                        f"/games returned 0 games but totalCount={total} at skip={skip}"
                    )
                continue
            # No totalCount reported: short page is the only termination signal.
            if len(games) < DEFAULT_PAGE_SIZE:
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
        last_err: Exception = CenterEdgeError(
            f"All retries exhausted for {method} {path}"
        )
        relogin_done = False

        for attempt in range(self.max_retries):
            try:
                headers = {
                    "Accept": "application/json",
                    "User-Agent": USER_AGENT,
                }
                if body is not None:
                    headers["Content-Type"] = "application/json"
                if authed:
                    token = self._ensure_token()
                    headers["Authorization"] = f"Bearer {token}"
                if self.api_key:
                    headers["X-Api-Key"] = self.api_key

                payload = json.dumps(body).encode("utf-8") if body is not None else None
                req = urllib.request.Request(
                    url, data=payload, method=method, headers=headers
                )
                with _OPENER.open(req, timeout=self.timeout) as resp:
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

                # 30x: refused by our NoRedirectHandler. Don't retry — operator
                # needs to fix base_url.
                if 300 <= status < 400:
                    raise CenterEdgeError(
                        f"HTTP {status} redirect from {method} {path} refused "
                        f"(check base_url): {err_body}"
                    ) from e

                if status == 401 and authed and not relogin_done:
                    log.info("Got 401 on %s %s; re-authenticating.", method, path)
                    self._token = None
                    relogin_done = True
                    last_err = CenterEdgeError(
                        f"HTTP 401 from {method} {path}: {err_body}"
                    )
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

        raise last_err

    def _sleep_backoff(
        self, attempt: int, backoff: List[int], err: Exception
    ) -> None:
        # No point sleeping after the last attempt — we're about to raise.
        if attempt + 1 >= self.max_retries:
            return
        delay = backoff[min(attempt, len(backoff) - 1)]
        log.warning(
            "Transient API error (attempt %d/%d): %s — sleeping %ds.",
            attempt + 1,
            self.max_retries,
            err,
            delay,
        )
        _interruptible_sleep(delay)


# ---------------------------------------------------------------------------
# Slack
# ---------------------------------------------------------------------------


def _parse_retry_after(header: Optional[str]) -> Optional[float]:
    """
    Parse an HTTP Retry-After header. Returns seconds, or None if unparseable.
    Handles both delta-seconds and HTTP-date forms.
    """
    if not header:
        return None
    header = header.strip()
    try:
        return max(0.0, float(header))
    except ValueError:
        pass
    try:
        when = email.utils.parsedate_to_datetime(header)
    except (TypeError, ValueError):
        return None
    if when is None:
        return None
    if when.tzinfo is None:
        when = when.replace(tzinfo=dt.timezone.utc)
    delta = (when - dt.datetime.now(dt.timezone.utc)).total_seconds()
    return max(0.0, delta)


def post_slack_message(
    bot_token: str,
    channel: str,
    text: str,
    blocks: Optional[List[Dict[str, Any]]] = None,
    timeout: int = DEFAULT_HTTP_TIMEOUT,
    max_retries: int = SLACK_MAX_RETRIES,
) -> bool:
    """
    Post via Slack's chat.postMessage. Returns True if the message was
    delivered (Slack responded with ok=true), False otherwise.

    Retries on 429 (honoring Retry-After up to SLACK_MAX_RETRY_AFTER), 5xx,
    and network errors. Caller is expected to retry-on-False on the next
    poll cycle.
    """
    payload: Dict[str, Any] = {"channel": channel, "text": text}
    if blocks:
        payload["blocks"] = blocks
    data = json.dumps(payload).encode("utf-8")
    backoff = [1, 2, 4, 8]

    for attempt in range(max_retries):
        if _stop_requested:
            log.info("Shutdown requested; abandoning Slack send.")
            return False
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
            with _OPENER.open(req, timeout=timeout) as resp:
                body = resp.read().decode("utf-8", errors="replace")
                try:
                    parsed = json.loads(body)
                except json.JSONDecodeError:
                    log.error("Slack returned non-JSON response: %s", body[:200])
                    return False
                if parsed.get("ok"):
                    log.info("Slack alert delivered to %s", channel)
                    return True
                # Slack responded with ok=false. Most error codes are
                # not retriable (channel_not_found, invalid_auth, etc.).
                err = parsed.get("error", "")
                log.error("Slack rejected message: error=%s", err)
                if err in ("rate_limited", "ratelimited"):
                    # Slack returns these as 429 normally, but treat ok=false
                    # rate_limited as retryable too.
                    delay = backoff[min(attempt, len(backoff) - 1)]
                    log.warning(
                        "Slack rate-limited (in body); sleeping %ds and retrying.",
                        delay,
                    )
                    _interruptible_sleep(delay)
                    continue
                return False
        except urllib.error.HTTPError as e:
            err_body = ""
            try:
                err_body = e.read().decode("utf-8", errors="replace")[:200]
            except Exception:
                pass
            if e.code == 429:
                ra = _parse_retry_after(e.headers.get("Retry-After"))
                delay = min(ra if ra is not None else backoff[min(attempt, 3)],
                            float(SLACK_MAX_RETRY_AFTER))
                log.warning(
                    "Slack 429 rate-limited; sleeping %.1fs (Retry-After=%s).",
                    delay, e.headers.get("Retry-After"),
                )
                _interruptible_sleep(delay)
                continue
            if 500 <= e.code < 600:
                delay = backoff[min(attempt, len(backoff) - 1)]
                log.warning(
                    "Slack HTTP %s (attempt %d/%d); sleeping %ds.",
                    e.code, attempt + 1, max_retries, delay,
                )
                _interruptible_sleep(delay)
                continue
            log.error("Slack HTTP error %s: %s", e.code, err_body)
            return False
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            delay = backoff[min(attempt, len(backoff) - 1)]
            log.warning(
                "Slack network error (attempt %d/%d): %s — sleeping %ds.",
                attempt + 1, max_retries, e, delay,
            )
            _interruptible_sleep(delay)
            continue

    log.error("Slack delivery failed after %d attempts; will retry next cycle.", max_retries)
    return False


def build_outage_blocks(
    game: Dict[str, Any], location_label: str, mention: str
) -> Tuple[str, List[Dict[str, Any]]]:
    name = (game.get("name") or "(unnamed)").strip() or "(unnamed)"
    gid_raw = game.get("id")
    gid = str(gid_raw).strip() if gid_raw is not None else "?"
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


def _interruptible_sleep(seconds: float) -> None:
    """Sleep in 1-second slices so SIGTERM is observed promptly."""
    if seconds <= 0:
        return
    deadline = time.monotonic() + seconds
    while not _stop_requested:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            return
        time.sleep(min(1.0, remaining))


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
        now_str = now_iso()

        # Defensive: a 0-game response from a working venue means the API is
        # confused. Don't act on it (would falsely "recover" everything).
        if not games:
            log.warning(
                "API returned 0 games; skipping state changes for this cycle."
            )
            self.state.last_run = now_str
            self.state.save()
            return

        # Index current OOS games by ID.
        current_oos: Dict[str, Dict[str, Any]] = {}
        for game in games:
            gid_raw = game.get("id")
            if gid_raw is None:
                continue
            gid = str(gid_raw).strip()
            if not gid:
                continue
            if game.get("operationStatus") == OOS_STATUS:
                current_oos[gid] = game

        # Seed mode: first run after install / state loss. Populate state
        # with every currently-OOS game without sending Slack messages, so
        # we don't page the on-call team for outages they already know about.
        if self.state.needs_seed:
            for gid, game in current_oos.items():
                self.state.outages[gid] = {
                    "name": game.get("name"),
                    "since": now_str,
                }
            log.warning(
                "Seeded state with %d already-out-of-service game(s); no Slack "
                "alerts sent for these (they were already down at startup).",
                len(current_oos),
            )
            self.state.needs_seed = False
            self.state.last_run = now_str
            self.state.save()
            return

        # Normal mode: alert on transitions into OOS.
        for gid, game in current_oos.items():
            if gid in self.state.outages:
                continue  # Already alerted; wait for recovery.
            log.warning("NEW outOfService game: %s (id %s)", game.get("name"), gid)
            fallback, blocks = build_outage_blocks(
                game, self.cfg.location_label, self.cfg.slack_mention
            )
            delivered = post_slack_message(
                self.cfg.slack_bot_token,
                self.cfg.slack_channel,
                fallback,
                blocks,
                timeout=self.cfg.http_timeout,
            )
            if delivered:
                # Mark as alerted ONLY after Slack confirms delivery, so a
                # Slack outage doesn't cause us to silently lose the alert
                # forever — we'll just retry next tick.
                self.state.outages[gid] = {
                    "name": game.get("name"),
                    "since": now_str,
                }
            else:
                log.warning(
                    "Will retry alert for %s (id %s) on next poll.",
                    game.get("name"), gid,
                )

        # Clear entries for games that recovered, so the next outage will
        # alert again. No recovery message is sent.
        recovered = [gid for gid in self.state.outages if gid not in current_oos]
        for gid in recovered:
            entry = self.state.outages.pop(gid)
            log.info(
                "Cleared recovered game from state: %s (id %s)",
                entry.get("name"), gid,
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
        ok = post_slack_message(
            cfg.slack_bot_token,
            cfg.slack_channel,
            ":wave: ceplay-game-alerts test message — connection works.",
            timeout=cfg.http_timeout,
        )
        return 0 if ok else 1

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
        # Sleep the remainder of the interval. _interruptible_sleep slices
        # into 1-second chunks and exits early on SIGTERM/SIGINT.
        _interruptible_sleep(max(0.0, cfg.interval_seconds - elapsed))
    log.info("Shutdown complete.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
