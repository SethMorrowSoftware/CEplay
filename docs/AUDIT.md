# CEplay Robustness Audit (Castle Fun Center ↔ CenterEdge)

**Date:** 2026-05-08  
**Scope reviewed:** API handlers, scheduler/watchdog flow, CenterEdge client, auth/session controls, DB layer, install/runtime configuration.  
**Explicitly out of scope (per request):** custom roles and user types.

---

## Executive Summary

CEplay has a solid reliability foundation (WAL-enabled SQLite, retry + circuit breaker behavior, stale-cache fallback, watchdog reconciliation, and detailed audit logging). The system is clearly designed to self-heal around transient CenterEdge issues.

That said, there are several logic and operational weaknesses that can still cause surprising behavior in production. The most impactful risks are:

1. **No distributed locking across hosts**: file locks only protect one machine; running cron/watchdog on two app nodes can double-execute actions.
2. **Idempotency gaps around game/kiosk state writes**: retries and watchdog reconciliation are strong, but some action paths can still race and emit duplicate side effects/logs under concurrency.
3. **Health signaling is present but not fully “operator-grade”**: degraded upstream conditions are tracked, but escalation/visibility can still be too passive for frontline ops.
4. **Schedule model excludes overnight windows by design**: this is documented in code, but operationally it creates a fragile two-row workaround with edge-case failure potential.
5. **SQLite single-writer limits remain a scaling boundary** during heavy watchdog + admin activity.

Bottom line: this can be made “rock solid,” but only if runtime topology constraints, scheduling edge cases, and operational observability are tightened.

---

## What Is Already Strong

- **CenterEdge request hardening:** redirects are explicitly not followed, reducing credential leak risk from 30x behavior.
- **Context-aware retries:** UI context is short-backoff; CLI context has deeper retries.
- **Circuit-breaker behavior for UI traffic:** prevents operator request pileups during upstream incidents.
- **Stale-cache fallback for categories/capabilities:** protects admin UI continuity during upstream API instability.
- **Watchdog reconciliation loop + pending retry table:** good pattern for eventually consistent enforcement.
- **Security baseline:** bcrypt hashes, session fixation mitigation, strict same-site cookies, CSRF token flow.

---

## High-Priority Findings

### 1) Single-host lock strategy can fail in multi-node deployments
**Severity:** High  
**Why it matters:** locking uses local files (`LOCK_FILE`, `WATCHDOG_LOCK_FILE`). If Castle Fun Center runs CEplay on multiple web/cron nodes sharing the same database but not the same filesystem lock file, both nodes can run scheduler/watchdog concurrently.

**Failure mode:** duplicate action execution, duplicated retries, conflicting state flips, noisy logs.

**Recommendation:** either (a) enforce single-node scheduler/watchdog deployment as a hard requirement, or (b) migrate locks to DB-backed advisory/lease locks.

---

### 2) Overnight schedule support is operationally fragile
**Severity:** High  
**Why it matters:** schedule creation enforces `start_time < end_time` and instructs operators to split overnight windows into two entries. That works, but increases misconfiguration risk and makes DST/day-boundary behavior harder to reason about.

**Failure mode:** partial coverage (e.g., forgot second segment), unintended pause windows at midnight boundaries, higher support burden.

**Recommendation:** keep current behavior if needed short-term, but document and validate “paired overnight segments” at the UI/API level with explicit linting warnings.

---

### 3) SQLite write contention remains a real resilience boundary
**Severity:** High  
**Why it matters:** WAL + busyTimeout are good, but CEplay still has a single-writer database. Watchdog ingestion, scheduled action writes, login attempt writes, and admin updates can converge.

**Failure mode:** long write waits, intermittent request latency spikes, occasional failed write bursts under peak conditions.

**Recommendation:** add explicit write-pressure monitoring and set operational SLO thresholds (max watchdog loop duration, max DB busy wait); consider future migration path if throughput grows.

---

## Medium-Priority Findings

### 4) Degraded-upstream signaling is tracked, but escalation path is weak
Current design records `ce_last_success_at`, consecutive failure count, and last failure message. This is good, but without active alerting/clear UI urgency, operators may miss prolonged degraded mode.

**Recommendation:** define alert thresholds (e.g., 5+ consecutive failures or no upstream success for N minutes) and surface hard warnings on dashboard.

### 5) Retry/reconciliation can still generate confusing duplicate operator signals
Retries and watchdog enforcement are resilient, but concurrent/manual actions can produce repeated logs and “already in desired state” churn that looks like instability.

**Recommendation:** strengthen idempotency semantics in operator messaging and reporting (distinguish “noop already compliant” from true state change).

### 6) Authentication anti-bruteforce controls are IP-based only
Progressive delay + rate limiting are good, but IP-only control can be noisy behind NAT/shared networks and weaker against distributed attacks.

**Recommendation:** add username+IP dimensional controls and lockout telemetry to improve reliability for legitimate staff without weakening protection.

---

## Low-Priority / Hygiene Risks

- **Installer/runtime artifact exposure risk:** reliability/security assumes installer scripts are removed or blocked after setup.
- **Config fragility around encryption key provisioning:** missing/invalid key fails fast (good for safety) but should be proactively checked by a health preflight so failures are caught before runtime operations.

---

## Prioritized Hardening Plan (No Roles/User Types)

### Phase 1 (Immediate)
1. Publish a **deployment invariant**: exactly one scheduler/watchdog executor unless distributed lock is implemented.
2. Add **dashboard health escalation** for sustained CenterEdge degradation.
3. Add **overnight schedule safety checks** (detect likely missing second segment).

### Phase 2 (Near-term)
4. Add **DB contention observability** (busy incidents, loop duration, write latency buckets).
5. Improve **idempotent operator feedback** for reconciler/manual-action overlap.
6. Expand login-abuse controls beyond IP-only heuristics.

### Phase 3 (Maturity)
7. Consider **DB-backed lease locking** to safely support HA/multi-node execution.
8. Define and test **incident runbooks** for CenterEdge outage, DB contention, and cron drift.

---

## Reliability Acceptance Criteria

Treat this integration as robust when all criteria are consistently met:

- A 10-minute CenterEdge outage does **not** cause operator API timeouts or unusable UI workflows.
- Degraded mode is visible to operators in under 60 seconds with clear “action required” messaging.
- No duplicate schedule/watchdog execution occurs across deployment topology.
- Overnight schedule intent can be validated pre-save with low operator error rate.
- Watchdog reconciliation recovers system state automatically after transient failures with bounded lag.

---

## Final Assessment

CEplay is close to production-grade robustness and already includes many of the right primitives. The largest remaining risks are less about missing features and more about **operational guarantees**: topology-safe locking, schedule edge-case safety, and high-signal observability for degraded conditions.

Addressing those areas will make this a resilient, dependable Castle Fun Center ↔ CenterEdge integration without introducing custom roles/user types.
