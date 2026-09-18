# Q: Phase 29 revision complete — Q4 (renewal-payment actions) and Q5 (persistent daemon worker)

Phase 29's two previously-open decisions are now revised and implemented, per your instructions.

**Q4 — cancel/refund/capture on renewal-originated payments (now Option 1, fixed):**
- `ResolvePaymentActionContext::forPayment()` no longer returns `null` for a payment with no
  checkout attempt — it resolves the provider account/payment method via
  `subscription_payment_links` → the owning `Subscription`, then converges on the exact same
  downstream capability/adapter/gateway-reference resolution a checkout-originated payment already
  used. No existing check was bypassed.
- Discovered mid-implementation that this alone wasn't enough: all three of
  `CancelPaymentCommand`/`RefundPaymentCommand`/`CapturePaymentCommand` could only address a
  payment by `checkoutAttemptId`, and a renewal payment has none — so there was no way to even
  identify one to these commands. Each command gained a trailing optional `paymentId`
  (`checkoutAttemptId` is now nullable); the checkout-originated path is byte-for-byte unchanged,
  every existing positional call site untouched.
- Added 7 new tests across the three handler test files covering the renewal path (successful
  action, provider-not-supported, cross-client not-found).
- No HTTP route reaches this yet — only direct command construction — since no admin payments
  screen or new API endpoint was requested.

**Q5 — persistent daemon worker (now Option 2, built):**
- Built `bin/Worker.php` (`composer jobs:worker`): continuously polls/claims due jobs through the
  unchanged `RunDueJobsHandler` abstraction, drains a backlog in a tight loop, sleeps otherwise,
  and traps SIGTERM/SIGINT to stop *after* the in-flight batch — never mid-batch.
- Before writing the schema I asked one clarifying question on the max-attempts/backoff shape,
  since your literal Q5 requirements implied real design choices. Your answers: recurring jobs
  never dead-letter or get escalating backoff from repeated failure (they keep retrying at their
  fixed interval forever), but health must be tracked and a spam-guarded alert must surface once a
  job crosses 3 consecutive failures — visible on the admin Jobs screen, silenceable by an admin,
  auto-clearing on the job's next success.
- Proposed and got your confirmation on one additive migration (7 new `jobs` columns:
  `consecutive_failures`, `total_failures`, `last_failed_at`, `last_success_at`, `alerted_at`,
  `alert_acknowledged_at`, `alert_acknowledged_by`) before creating it.
- `Job::recordFailure()`/`recordSuccess()` now maintain this bookkeeping; a new
  `AcknowledgeJobAlertHandler` (`jobs.retry`, idempotent) backs a new
  `POST /admin/jobs/{jobId}/acknowledge-alert` route. A job handler's *returned* failure (not just
  a thrown exception) is now also logged to the existing `error_logs` table, so per-occurrence
  debugging history lives in the Error Logs screen already built for exactly this, rather than a
  new table.
- The admin Jobs screen now shows an "N alerting" badge, a per-row healthy/alerting/ack'd pill,
  failure counts and timestamps in the expanded row, and an "Acknowledge alert" button.

**Real evidence captured:** the daemon was smoke-tested directly — it self-bootstrapped and ran
all 7 real job types on first poll, idled silently with nothing due on a second run, and shut down
cleanly on SIGTERM (captured process logs, all included in the phase result). A throwaway job was
forced through 3 failures to seed a real alert; the Jobs screen was screenshotted showing "1
alerting," a real `POST .../acknowledge-alert` was executed and verified against the database and
audit log, and re-screenshotted showing "ack'd." All throwaway data was cleaned up afterward; the 7
real job rows were untouched throughout.

**Tests:** 24 new (869 → 893 total), all passing; `phpstan analyse` clean.

**Explicitly still out of scope, called out rather than silently dropped:**
- No admin or client-facing route yet reaches a renewal payment's cancel/refund/capture — only
  direct command construction is wired up and tested.
- Production process-supervisor configuration for `bin/Worker.php` (systemd/supervisord/
  equivalent) is explicitly Phase 30's, per your own Q5 requirement — this phase only built and
  documented the daemon script itself.
- The alert threshold (3) is a single hardcoded constant, not per-job-type or DB-configurable —
  matches "a configurable threshold, e.g. 3 or 5" read as "pick one sensible value."

Full detail: `.claude/PhaseResults/Phase29Result.md` (its "Q4/Q5 revision" subsections).
`.claude/Changelog.md`, `.claude/docs/Phases.md`, and `.claude/PhaseResults/PhaseDecisions.md`
(with full change history on the revised Q4/Q5 entries) are all updated.

Phase 29 is now fully complete with nothing knowingly deferred except what's explicitly called out
above. Next recommended phase: **Phase 30 — Hardening, docs & first-client go-live**, which now
also owns setting up the real production supervisor for the daemon this phase built.
