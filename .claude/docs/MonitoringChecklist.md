# MonitoringChecklist.md

**Purpose.** What to watch in production and why — tool-agnostic (Phase 30B Q5), matching Phase 29
Q3's earlier decision to scope observability to Gomrok's own trace logs and admin screens rather
than commit to a specific monitoring product. Every check below names a concrete, already-real
Gomrok source (a table, an admin screen, a log line) — wire whichever monitoring/alerting tool
ends up chosen (Datadog, Grafana, a cron+curl script, anything) to poll these, on whatever cadence
fits. This document does not choose or configure a tool itself.

---

## 1. Application health

| Check | Source | Alert when |
|---|---|---|
| Process is up | `GET /health` | Non-`200`, timeout, or connection refused for more than 1–2 consecutive checks |
| Web process (php-fpm/equivalent) alive | `systemctl is-active php8.4-fpm` or platform equivalent | Not `active` |
| Worker process alive | `systemctl is-active gomrok-worker`, or the most recent line in its log/journal | No successful poll cycle logged within `2 × JOBS_WORKER_POLL_SECONDS` |
| Production-safety guard passed boot | Web/worker process actually started (a `ProductionSafetyViolation` — Phase 30A Q5 — prevents boot entirely) | Process fails to start after a deploy |

## 2. Database health

| Check | Source | Alert when |
|---|---|---|
| Connection availability | Any real request that touches the DB — `/health` deliberately does **not** (it's a pure liveness probe, no I/O); use `GET /api/v1/me` with a real key, or a direct DB ping, instead | Connection refused / timeout |
| Replication lag (if a replica is later introduced) | N/A today — Gomrok runs against a single MySQL instance as of Phase 30 | — |
| Slow queries | MySQL's own slow-query log | Any query the DB host flags as slow on a hot path (`payments`, `idempotency_keys`, `voucher_redemptions`) |
| Lock contention | MySQL `SHOW ENGINE INNODB STATUS`, or the load-test scripts under `tools/loadtest/` re-run periodically as a synthetic check | Elevated deadlock/lock-wait-timeout rate beyond what `PdoIdempotencyStore`'s retry-safe design (Phase 30A) already absorbs |

## 3. Errors — `error_logs`

| Check | Source | Alert when |
|---|---|---|
| Unresolved error count | `/admin/error-logs`, or a direct count of `error_logs` rows with no resolution | Any new unresolved entry (low volume expected — treat non-zero as worth a look, not necessarily paging) |
| Error rate trend | `error_logs.created_at` bucketed over time | A sudden spike relative to the recent baseline, even if each individual error is "expected" |

Every entry already has `correlation_id`/`client_id`/context fields populated per CLAUDE.md's
structured-logging requirement — use those to group/dedupe before alerting on raw count.

## 4. Background jobs — `/admin/jobs`

| Check | Source | Alert when |
|---|---|---|
| Jobs "alerting" count | `/admin/jobs` badge, backed by `JobRepository::countAlerting()` (a job past its configured consecutive-failure threshold) | Count > 0 |
| Plain failed-job count | `/admin/jobs` "failed" filter | A sustained rise, not just an isolated one-off (transient provider/network errors happen; the worker's own retry-with-backoff already absorbs those) |
| Worker throughput | Worker log lines (`attempted=N succeeded=N failed=N`) | `attempted` stays at 0 for longer than expected (the worker itself has stalled, not just "no work to do" — cross-check against known job types that should always have periodic work, e.g. reconciliation) |

## 5. Webhooks — `webhook_events`

| Check | Source | Alert when |
|---|---|---|
| Unprocessed/failed webhook backlog | `webhook_events` rows not yet successfully processed, or the equivalent admin view | Backlog grows instead of draining — the `webhook:retry-pending` job (Phase 25) should keep this near zero |
| Signature verification failures | `error_logs` / webhook processing logs for a specific provider | A sudden burst — could mean a rotated provider signing secret Gomrok wasn't updated with (`provider-account:rotate-secret`), or a real attack |

## 6. Client notifications — `/admin/notifications`

| Check | Source | Alert when |
|---|---|---|
| Dead-lettered notification count | `/admin/notifications`, Phase 28's dead-letter view | Any client's dead-letter count rises — the client's callback endpoint may be down or misconfigured |
| Retry success rate | Same screen, after a manual/automatic retry | Repeated retries failing for the same client — escalate to that client's own team rather than retrying indefinitely |

## 7. HTTP-level error rates

| Check | Source | Alert when |
|---|---|---|
| `5xx` rate on `/api/v1/*` | nginx/access log status codes, or an APM/proxy-level metric once one is chosen | Any sustained non-zero rate — a `5xx` is always a Gomrok-side bug or outage, never a client mistake |
| `401`/`429` rate | Same, filtered to `/api/v1/*` | A sudden spike could mean a client's key rotated without updating their integration, or a credential-stuffing attempt hitting the Phase 30A lockout repeatedly |
| `4xx` validation-error rate per client | Same, or `error_logs` if validation failures are also logged there | A single client suddenly producing many `4xx`s usually means their integration changed/broke, worth a proactive heads-up to them |

## 8. Reconciliation — `/admin/reconciliation`

| Check | Source | Alert when |
|---|---|---|
| Open reconciliation findings | `/admin/reconciliation` (Phase 29) | Any new finding — these represent a real detected mismatch between Gomrok's stored state and a provider's, and should not accumulate unresolved |

## 9. Security-relevant signals

| Check | Source | Alert when |
|---|---|---|
| Client API key lockouts | `client_auth_attempts` rows with outcome `too_many_attempts` (Phase 30A Q1) | A spike for one `key_id`, or across many different keys from related IPs (credential stuffing) |
| Admin login lockouts | The equivalent admin-side attempt log (pre-existing pattern Phase 30A's client lockout mirrors) | Any lockout on a production admin account outside expected human error |
| Sensitive audit-log actions | `/admin/audit-logs` — provider-secret rotation, refunds, pricing/voucher changes, permission changes (CLAUDE.md "Security Rules") | Any such action outside a known change window, or performed by an account not expected to need it |

---

## Suggested minimum viable setup (no tool chosen yet)

Until a specific monitoring product is chosen, the cheapest real coverage is: an external uptime
check against `GET /health` (any free uptime-ping service), plus a daily human glance at
`/admin/error-logs`, `/admin/jobs`, `/admin/notifications`, and `/admin/reconciliation` — all four
already exist and require no new infrastructure. Escalate from there to a real
metrics/alerting tool once real production traffic volume justifies the investment.
