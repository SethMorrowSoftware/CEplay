# Pause Group Automation Audit (Reliability + Security)

Date: 2026-03-21
Scope reviewed: API routes, auth/session handling, scheduler/cron flow, installer behavior, crypto/storage patterns, and operational docs present in repository.

## Executive Summary

Overall, the project has a solid baseline for a small internal operations tool:
- Parameterized SQL usage throughout the codebase.
- Session auth + CSRF enforcement for state-changing API operations.
- CLI-only guards on job runner scripts.
- Locking in cron/runner to reduce concurrency races.

The highest-impact issue identified in earlier review was an installer hardening gap that could allow **unauthenticated creation of additional admin users** if `install.php` remained web-accessible after first setup. This is fixed in this branch.

This review additionally found reliability risks in setup validation: installer prerequisites did not verify the required `curl` extension and did not warn about missing `at` scheduler binaries. Both are now addressed, and scheduler execution now supports fallback mode when `at` is unavailable.

Latest beta sweep updates:
- Added a documented beta smoke checklist to `README.md` so release verification is repeatable.
- Fixed `fresh_install.php` to avoid PHP `match` syntax, preventing parse-time failures on PHP 7.4 environments.

## What Was Fixed

### 1) Installer post-setup lockout (Critical)

**Issue:** In web mode, `install.php` allowed `POST step=create_admin` processing even after setup had been completed, as long as a new username was supplied.

**Impact:** If `install.php` was left reachable (common in rushed deployments), an attacker could create an admin account without authentication.

**Fix:** Added an early guard in POST handling to block all installer actions once any admin exists.

### 2) Installer prerequisite coverage for runtime dependencies (High)

**Issue:** Setup scripts did not verify that `curl` was installed, despite being required for all CenterEdge API calls. They also did not surface missing `at`/`atrm` scheduler binaries.

**Impact:** Deployments could appear successful but fail at runtime when syncing games or scheduling actions.

**Fix:** Updated `install.php` and `fresh_install.php` prerequisite checks to require `curl`, and added explicit warnings when `at`/`atrm` are missing.

## Remaining Findings (Prioritized)

### High Priority

1. ~~**Credential encryption uses AES-CBC without an integrity tag (MAC/AEAD).**~~ **RESOLVED.**
   - `lib/crypto.php` now implements encrypt-then-MAC using HMAC-SHA256 with separate encryption and MAC sub-keys derived via HKDF-like HMAC derivation from the master key. Integrity is verified before decryption. Backward-compatible decryption of legacy (pre-HMAC) data is preserved.

2. ~~**No explicit brute-force throttling strategy for login endpoint beyond fixed sleep(1).**~~ **RESOLVED.**
   - Login now uses progressive delays: 1s (attempts 3-5), 3s (attempts 6-8), 5s (attempts 9-10), then full lockout (10+ attempts within 15-minute window). `Retry-After` header included in 429 responses.

3. ~~**No enforced deployment guardrails for install endpoint.**~~ **RESOLVED.**
   - `install.php` web mode now returns 403 immediately when any admin user exists (before rendering any HTML). Health endpoint and dashboard display warnings when `install.php` or `fresh_install.php` remain on disk.

### Medium Priority

1. **Missing stronger HTTP response header posture.**
   - Consider adding a CSP, `Permissions-Policy`, and (if always HTTPS) HSTS at web server layer.

2. **Authentication/session hardening opportunities.**
   - Consider setting `session.use_only_cookies=1` and a strict cookie secure policy in production (HTTPS-only deployments).

3. **Shared-hosting timer precision depends on cron cadence when `at` is unavailable.**
   - Fallback mode executes due actions via watchdog/missed-action checks; use 1-minute cron for best reliability on hosts without `at`.

### Low Priority

1. **No automated test suite currently in repo.**
   - Add smoke/integration checks for auth, schedule planning, and override conflict resolution.

2. ~~**Runbook docs are minimal.**~~ **RESOLVED.**
   - Backup/restore, key rotation, and incident response (missing cron heartbeat) runbooks added to this document.

## Reliability Notes

- The lock-file approach in `cron.php` and `run_action.php` is good and prevents duplicate runners.
- Replan-on-change behavior in schedules/overrides improves operational correctness.
- Database busy timeout and WAL mode are sensible for lightweight concurrent access.

## Recommended Next Actions (Practical, Not Over-Engineered)

1. **Deployment hardening now (same day):**
   - Block web access to `install.php` in Nginx/Apache.
   - Ensure HTTPS termination and secure cookie usage.
   - Verify filesystem permissions (`data/` writable only by app user).

2. **Security uplift (short sprint):**
   - ~~Add login rate limiter.~~ Done — progressive delays (1s/3s/5s) with full lockout at 10 attempts.
   - ~~Migrate credential encryption to AEAD with backward-compatible decrypt path.~~ Done — encrypt-then-MAC (HMAC-SHA256) implemented.

3. **Reliability uplift (short sprint):**
   - Add a small test harness for key API and scheduler paths.
   - ~~Add runbook docs for backup/restore and incident response.~~ Done — see Backup & Restore Runbook section above.

## Backup & Restore Runbook

### Backup (Recommended: Daily, Automated)

The entire application state lives in a single SQLite database (`data/pause_groups.db`). WAL mode is enabled, so use the SQLite `.backup` command for a consistent snapshot:

```bash
# Hot backup (safe while app is running)
sqlite3 /path/to/data/pause_groups.db ".backup '/path/to/backups/pause_groups_$(date +%Y%m%d_%H%M%S).db'"

# Or use the sqlite3 CLI backup API
sqlite3 /path/to/data/pause_groups.db "VACUUM INTO '/path/to/backups/pause_groups_$(date +%Y%m%d).db';"
```

**Automate via cron:**
```
30 2 * * * sqlite3 /path/to/data/pause_groups.db ".backup '/path/to/backups/pause_groups_$(date +\%Y\%m\%d).db'" && find /path/to/backups -name 'pause_groups_*.db' -mtime +30 -delete
```

**What to back up:**
- `data/pause_groups.db` — all application data (users, groups, schedules, overrides, logs)
- `config.php` — encryption key (if not using env var) and custom settings
- Cron entries (`crontab -l`)

**Do NOT use `cp` on a live SQLite database** — it may capture a corrupted mid-write state. Always use `sqlite3 .backup` or `VACUUM INTO`.

### Restore

```bash
# 1. Stop the application (or put in maintenance)
sudo systemctl stop php-fpm  # or apache2/nginx

# 2. Replace the database
cp /path/to/backups/pause_groups_YYYYMMDD.db /path/to/data/pause_groups.db

# 3. Fix ownership and permissions
chown www-data:www-data /path/to/data/pause_groups.db
chmod 660 /path/to/data/pause_groups.db

# 4. Restart application
sudo systemctl start php-fpm

# 5. Re-plan today's schedules (picks up any missed actions)
sudo -u www-data php /path/to/cron.php
```

### Encryption Key Rotation

If `PG_ENCRYPTION_KEY` needs to be rotated:

1. Back up the database first.
2. Decrypt all stored credentials using the current key (Settings page > re-save API credentials).
3. Update `PG_ENCRYPTION_KEY` in environment.
4. Re-save the API credentials via Settings — they will be re-encrypted with the new key.
5. Verify by testing the CenterEdge API connection in Settings.

### Incident Response: Missing Cron Heartbeat

If `/api/health` shows `cron.healthy: false` or `watchdog.healthy: false`:

1. Check cron is running: `crontab -l | grep pause`
2. Check recent logs: `tail -50 /path/to/data/cron.log` and `tail -50 /path/to/data/watchdog.log`
3. Check for lock contention: `ls -la /path/to/data/.scheduler.lock`
4. Force re-plan: `sudo -u www-data php /path/to/cron.php`
5. If lock is stale, remove it: `rm /path/to/data/.scheduler.lock` then re-run cron

## Suggested Production Checklist

- [ ] `install.php` blocked or removed after install.
- [ ] `PG_ENCRYPTION_KEY` set and rotated through secure process.
- [ ] HTTPS enforced end-to-end.
- [ ] `data/` permissions verified least-privilege.
- [ ] Cron configured + log file monitored.
- [ ] Alerting added for repeated API failures and action execution errors.
- [ ] Backup/restore tested on a non-production copy.
