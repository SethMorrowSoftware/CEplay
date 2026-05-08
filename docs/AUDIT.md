# CEplay Robustness Audit (Castle Fun Center / CenterEdge)

**Date:** 2026-05-08  
**Audience:** Castle Fun Center operations + engineering owners  
**Goal of this rewrite:** Identify practical bugs, failure modes, and logic flaws that can keep CEplay from being a **rock-solid, resilient integration** with CenterEdge.  
**Explicit non-goal:** Custom roles/user types (per request).

---

## Executive Summary

CEplay is already a strong operational base: local caching, retry loops, cron watchdogs, stale-fallback caches, and comprehensive audit logging are all in place. The platform can run reliably day-to-day.

However, there are several reliability risks that should be considered **high priority** because they can directly affect availability, correctness, or operator confidence:

1. **All outbound CenterEdge requests follow redirects (`CURLOPT_FOLLOWLOCATION=true`)**. This can leak credentials/tokens to unexpected hosts if upstream or a proxy misbehaves.  
2. **Automatic retries are synchronous and blocking (`sleep(2/4/8)`)** in request paths. Under repeated upstream trouble, operator-facing API calls can stall for long periods and pile up PHP workers.  
3. **SQLite write pressure is concentrated** (transactions feed ingestion + scheduler writes + UI actions) without explicit write queuing/decoupling, creating risk of lock contention and latency spikes during busy windows.  
4. **Credential and connectivity failures are not always surfaced as actionable health states** for operators; some issues remain in logs only.

These are solvable with targeted hardening and observability improvements.

---

## What Looks Solid Today

- **Idempotent cache sync patterns** for games/kiosks and safe pruning strategy for large venues.  
- **Token reuse with proactive refresh + 401 re-auth retry**, reducing login churn.  
- **Tiered enforcement model** (request-time + watchdog) that reduces drift risk if cron jitter occurs.  
- **Stale-on-failure cache fallback** for capabilities/categories to keep UI operational through short upstream outages.  
- **Audit logging coverage** across major administrative actions.

These design choices are directionally correct for resilience.

---

## High-Priority Findings (Reliability / Robustness)

### 1) Redirect-following on authenticated API requests
**Severity:** High  
**Why it matters:** `httpRequest()` enables cURL redirect following while also sending bearer/API-key credentials in headers. In faulted proxy/CDN setups or malicious redirect scenarios, this can increase credential exposure risk and create confusing cross-host behavior.

**Evidence:** `lib/centeredge_client.php` sets `CURLOPT_FOLLOWLOCATION => true` with auth headers.  

**Impact path:** Unexpected 30x responses can silently reroute traffic, producing hard-to-debug failures or security exposure.

---

### 2) Blocking retry strategy can starve request handling
**Severity:** High  
**Why it matters:** API retries use inline `sleep()` (2s/4s/8s). A transient upstream outage can make each request take ~14+ seconds before failing, especially if multiple endpoints trigger retries simultaneously.

**Evidence:** `request()` in `lib/centeredge_client.php` retries inside request thread/process using `sleep()`.

**Impact path:** Slow admin UI responses, exhausted PHP-FPM worker pool, cascading timeout behavior.

---

### 3) Single shared scheduler lock can serialize too much work
**Severity:** Medium-High  
**Why it matters:** Daily cron, watchdog, and run-action scripts share a single lock file. During long operations, the watchdog may defer critical retries/enforcement until later cycles.

**Evidence:** `LOCK_FILE` shared in `config.php`; scheduler scripts coordinate through that shared lock.

**Impact path:** Temporary state drift, delayed corrective actions, or delayed feed polling under load.

---

### 4) Upstream outage behavior is resilient but not operator-obvious
**Severity:** Medium-High  
**Why it matters:** Some failover behaviors (stale caches, legacy decrypt path notices, repeated transient errors) primarily log to PHP/system logs. Operators may see “works partially” without understanding they are running degraded.

**Evidence:** Error signaling in client helper paths is often `error_log(...)` based.

**Impact path:** Prolonged degraded operation without prompt intervention.

---

### 5) Transaction feed ingestion is strong but still vulnerable to DB contention bursts
**Severity:** Medium  
**Why it matters:** Poll loop inserts many rows + checkpoint writes while scheduler and UI may write concurrently. SQLite WAL helps, but heavy write overlap can still produce latency or `busy` waits.

**Evidence:** `pollGameTransactions()` writes each transaction row + per-page checkpoint updates.

**Impact path:** Slow requests, watchdog lag, and delayed analytics freshness during peak activity.

---

## Medium Findings

### 6) Capabilities/categories stale fallback can mask upstream changes too long
If upstream capabilities change unexpectedly, stale cache behavior protects availability but may hide feature changes/incompatibilities until TTL refresh or manual refresh.

### 7) Retry policy is global-ish but not endpoint-specific
Transient retry behavior is mostly generic. Different CenterEdge routes may benefit from route-specific retry caps/timeouts to reduce blast radius during partial outages.

### 8) Installer artifacts remain a deployment footgun if not removed
The project guidance is clear, but reliability/security posture depends on correctly removing install utilities after bootstrap.

---

## Logic/Operational Flaws That Could Surprise Production

- **Synchronous API behavior in user-driven paths** means transient upstream health directly impacts operator UX speed.  
- **Health/degraded-state communication gap** means “degraded but not failed” may go unnoticed.  
- **Shared lock topology** prioritizes safety but can reduce timeliness under busy or fault conditions.

None of these invalidate CEplay’s architecture, but they are exactly the class of issues that make systems feel “unreliable” during real incidents.

---

## Prioritized Hardening Plan (No Role System Required)

### Phase 1 — Immediate (highest value)
1. Disable redirect following for authenticated CE requests, or enforce strict same-host redirect policy.  
2. Replace blocking in-request retries with bounded/non-blocking strategy for UI-triggered routes.  
3. Add explicit degraded-health signals surfaced in UI + `/api/health` (e.g., stale cache age, repeated upstream failures, last successful CE call timestamp).

### Phase 2 — Next
4. Split lock strategy (or shorten critical sections) so watchdog enforcement cannot be delayed by unrelated long tasks.  
5. Add per-endpoint timeout/retry budgets and circuit-breaker-style suppression during ongoing upstream incidents.

### Phase 3 — Maturity
6. Add structured incident telemetry: retry counts, queue depth, oldest pending retry, polling lag, DB busy metrics.  
7. Introduce synthetic canary checks against critical CE endpoints and alert when degraded mode exceeds threshold.

---

## Suggested Reliability Acceptance Criteria

You can treat CEplay as “rock solid” when these are true in production:

- CenterEdge 5xx burst for 10+ minutes does **not** freeze operator UI actions end-to-end.  
- Degraded mode is visible within 1 minute to operators (not just logs).  
- Watchdog enforcement latency remains bounded during long run-action windows.  
- Feed polling backlog is measurable and automatically recovers after outages without manual intervention.  
- No authenticated API call follows off-host redirects.

---

## Bottom Line

CEplay is close to a resilient production-grade integration and already has many correct reliability primitives. The key remaining work is to reduce blocking behavior, tighten outbound request safety, improve degraded-state visibility, and reduce lock/contention sensitivity.

If those hardening items are completed, this can be a robust and dependable Castle Fun Center ↔ CenterEdge operations bridge without introducing custom roles/user types.
