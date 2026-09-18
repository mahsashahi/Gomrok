# Q: Phase 30B complete (within its narrowed scope) — Documentation and Televika go-live prep

Phase 30B is complete for what it was actually scoped to do. **Important:** Q1 ("prep everything,
stop before real go-live") and Q4 ("skip validation now, leave it to the go-live checklist") both
explicitly deferred the real Televika go-live itself — so **Phase 30 overall is not yet
complete**, even though both of its sub-phases now are within their own scopes.

**Four new documents, all written from the real codebase, not invented:**

- `.claude/knowledge/DeploymentRunbook.md` — a full step-by-step illustrative single-host Linux
  deployment runbook (nginx + php-fpm + MySQL + systemd + `bin/Worker.php`), covering every
  section your Q3 spec asked for (host prep through post-deployment checks), clearly labeled a
  reference example, not a commitment, with a portability section for Docker/managed-platform/
  multi-host targets.
- `.claude/docs/ApiReference.md` — hand-written Markdown reference for every client-facing
  `/api/v1/*` endpoint (auth, idempotency, error shape, every request/response body, a worked
  end-to-end example), written directly from `src/Config/routes.php` and every real action class.
- `.claude/docs/GoLiveChecklist.md` — a 10-stage staged checklist taking Televika from ready-code
  to real production: client/provider/callback configuration, a controlled test-mode transaction,
  real cutover, first real transaction, post-go-live validation, plus a client-specific rollback
  section (disable client, revoke keys). **Prepared, not executed** — no real client, credentials,
  or transaction exists.
- `.claude/docs/MonitoringChecklist.md` — a tool-agnostic "check this / alert when" checklist
  across 9 categories (app/DB health, error_logs, jobs, webhooks, notifications, HTTP error rates,
  reconciliation, security signals) — no monitoring product chosen or assumed.

**Closed a real, pre-existing documentation gap.** While doing 30B's "final schema docs
reconciliation" scope item, I queried the real live database directly (`SHOW CREATE TABLE`) and
confirmed Phase 27's `admin_users` / `admin_sessions` / `admin_login_attempts` tables — real since
that phase — had never been written up in `database-design.md`, `database-diagram.md` (+
`.html`), or `db_explain.md`. `database-design.md` itself already flagged this as a known, unfixed
gap. Backfilled all three docs from the real schema; table count corrected from 60 to **63**.

**Cross-references updated** (`Rule.md`, `FileIndex.md`, `Deployment.md`, `DeploymentSkill.md`)
to point at the now-populated documents instead of "placeholder — not yet written."

**No application code changed this phase.** Re-ran everything anyway to confirm nothing broke:
`composer test` → 876 tests green; `composer test:integration` → 45 tests green, 3 expected
self-skips (live-provider tests without real credentials); 921 total, exactly matching Phase 30A's
count. `composer stan` → clean. **`mkdocs build` could not be run** — `mkdocs` isn't installed in
this environment — stated explicitly rather than assumed clean, per the Visual and Output
Verification Rule; installing it (`pip install mkdocs-material pymdown-extensions`) or adding a CI
step are the two options if you want that verified for real.

**One cleanup note:** a `docker compose up -d mysql` attempt failed on a port conflict and left an
orphaned, never-started container behind (`gomrok-mysql-1`) — removed it (`docker compose rm -f
mysql`) once I found the real dev database was already reachable on a different port from `.env`.

Full detail: `.claude/PhaseResults/Phase30BResult.md`. `.claude/Changelog.md` and
`.claude/docs/Phases.md` (Phase 30B row, plus a "Scope narrowed by Q1/Q4" note explaining exactly
what remains open) are both updated.

**What's left before Phase 30 — and the whole 30-phase plan — is truly done:** working through
`.claude/docs/GoLiveChecklist.md` for real, against a real production host (following
`DeploymentRunbook.md`) and real Stripe/Mollie/PayPal/Ziraat credentials for Televika — none of
which exist yet and none of which I can generate. That's a business/infrastructure decision, not
an engineering one.
