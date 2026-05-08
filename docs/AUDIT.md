# CEplay Robustness Audit (Castle Fun Center ↔ CenterEdge, Single FCOS Instance)

**Date:** 2026-05-08  
**Deployment assumption:** single Fedora CoreOS (FCOS) instance (no multi-node cluster).  
**Scope reviewed:** API handlers, scheduler/watchdog flow, CenterEdge client, auth/session controls, DB layer, install/runtime configuration.  
**Explicitly out of scope (per request):** custom roles and user types.

---

## Executive Summary

For a **single-instance FCOS deployment**, CEplay has a strong base: WAL-enabled SQLite, short UI retries with circuit-breaker behavior, watchdog reconciliation, stale-cache fallback for key data, and broad audit logging.

The biggest remaining robustness risks are now mostly about **logic edge cases and operability**, not topology:

1. **Overnight schedule handling is fragile** (requires two rows and careful operator setup).
2. **SQLite single-writer contention remains the main scaling bottleneck** under concurrent watchdog/admin activity.
3. **Degraded-upstream state is tracked but can still be too easy to miss operationally**.
4. **Retry/reconciliation behavior can generate noisy or confusing duplicate signals during incidents**.
5. **Auth rate limiting is IP-based only**, which can punish shared-NAT users and miss distributed attack patterns.

Bottom line: for single-node FCOS, this can be made very reliable by tightening schedule safety, DB pressure visibility, and operator-facing health signals.

---

## What Is Already Strong

- **CenterEdge credential safety hardening:** redirects are refused instead of auto-followed.
- **Context-aware retry behavior:** fast-fail bias for UI, deeper retry in CLI workers.
- **Circuit-breaker behavior for operator traffic:** avoids UI worker pileups during upstream instability.
- **Stale cache fallback (categories/capabilities):** keeps UI usable through short outages.
- **Watchdog + retry table pattern:** good eventual consistency model for state enforcement.
- **Security baseline:** bcrypt password hashing, session fixation mitigation, CSRF token flow, secure cookie posture.

---

## High-Priority Findings

### 1) Overnight schedule model is error-prone
**Severity:** High  
**Why it matters:** schedule creation enforces `start_time < end_time`, so overnight windows must be split into two entries. This is workable but fragile in day-boundary and DST contexts.

**Failure mode:** missed second segment, accidental coverage gaps, incorrect midnight behavior, increased support overhead.

**Recommendation:** add explicit “overnight intent” validation/linting in admin workflows (or API-level validation guidance) so operators get warnings before saving risky combinations.

---

### 2) SQLite write contention is the primary resilience boundary
**Severity:** High  
**Why it matters:** even with WAL and busy timeout, SQLite stays single-writer. Watchdog ingestion, scheduled-action updates, auth attempt logging, and admin writes can overlap during busy periods.

**Failure mode:** bursty latency, long waits, occasional failed writes/timeouts under load.

**Recommendation:** add DB pressure observability (busy waits, loop duration, write latency) and define SLO-based thresholds for intervention.

---

### 3) Degraded-upstream signaling lacks strong operator escalation
**Severity:** High  
**Why it matters:** the system tracks upstream failures and last-success time, but without prominent/active escalation operators can run degraded for too long before responding.

**Failure mode:** partial functionality appears “mostly fine” while critical automation reliability degrades.

**Recommendation:** promote degraded mode to high-visibility dashboard warnings + alert thresholds (e.g., no successful CE call for N minutes).

---

## Medium-Priority Findings

### 4) Reconciliation/idempotency signals can look unstable during incidents
Retries and watchdog enforcement are robust, but repeated attempts can create log noise and confusing operator perception (“things are flapping”) even when the system is converging.

**Recommendation:** improve event labeling so operators can distinguish true state change vs. noop/retry convergence.

### 5) IP-only auth throttling has operational downsides
Progressive delay + lockout are helpful, but IP-only strategy can over-throttle legitimate users behind shared egress and under-detect distributed credential attacks.

**Recommendation:** add combined dimensions (username + IP + time window) and expose lockout telemetry.

---

## Low-Priority / Hygiene Risks

- **Installer/runtime artifact handling:** reliability/security posture still assumes install paths are removed or blocked post-setup.
- **Encryption key preflight:** missing/invalid key fails safely, but this should be surfaced early by startup/health checks before operator workflows are impacted.

---

## Prioritized Hardening Plan (Single-Instance FCOS)

### Phase 1 (Immediate)
1. Add **overnight schedule safety validation** and operator warnings.
2. Add **dashboard degraded-state escalation** with clear action text.
3. Add **health thresholds** based on upstream last-success age and consecutive failures.

### Phase 2 (Near-term)
4. Add **SQLite contention telemetry** (busy events, watchdog runtime, write latency buckets).
5. Improve **idempotent status messaging** for retries/reconciliation.
6. Strengthen auth protection with **username+IP aware throttling metrics**.

### Phase 3 (Maturity)
7. Build **incident runbooks** for CenterEdge outage, DB contention, and cron drift.
8. Add **periodic resilience drills** (simulated CE outage + recovery timing verification).

---

## Reliability Acceptance Criteria (Single Instance)

Treat this integration as robust when all criteria are consistently met:

- A 10-minute CenterEdge outage does **not** make operator UI workflows unusable.
- Degraded state is visibly surfaced to operators within 60 seconds.
- Overnight schedule configurations are validated pre-save with low misconfiguration rate.
- Watchdog reconciliation converges automatically after transient failures with bounded lag.
- SQLite contention is measurable and remains within defined SLO limits during peak operations.

---

## Final Assessment

Given your **single FCOS instance** architecture, CEplay is close to a robust production solution. The key remaining work is practical hardening: safer overnight scheduling workflows, stronger degraded-mode visibility, and measurable DB/runtime health.

Addressing those items will materially improve reliability without adding custom roles/user types.
