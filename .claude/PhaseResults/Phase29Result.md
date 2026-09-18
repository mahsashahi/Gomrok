# Phase 29 — Background jobs, reconciliation & observability

## Execution Summary

- Phase: 29 — Background jobs, reconciliation & observability
- Start Datetime: 2026-09-17 17:34
- End Datetime: 2026-09-18 00:08
- Estimated Duration: 6–9h
- Actual Duration: 6h34m — the phase was first completed at 2026-09-17 19:22 (see the original
  "1h48m" figure this line replaces), then explicitly reopened by the user, who revised two
  recorded decisions (Q4: Option 2 → Option 1; Q5: Pending → Option 2) and asked for the phase to
  continue. This figure is real wall-clock Start-to-End, not active work time — it spans a gap
  between the two work sessions.
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- Designed and confirmed a unified, DB-backed background-job queue (Phase 29 Q1) that every
  background task — the two already-built Phase 25/28 jobs and every new Phase 29 job — runs
  through, instead of one dedicated table/scheduler per job type.
- Migrated the Phase 25 webhook-retry job and the Phase 28 notification-retry job onto the new
  queue as `webhook_retry_scan` / `notification_retry_scan`, reusing their existing use-case
  handlers unchanged. The original standalone `src/Jobs/*.php` + `bin/*.php` scripts were left
  operational (Q5, the worker-invocation shape is still undecided) rather than retired.
- Added two new sweep jobs: `voucher_reservation_sweep` (releases a `reserved` voucher redemption
  stale for 60+ minutes, reusing `ReleaseVoucherRedemptionHandler` unchanged) and
  `checkout_abandonment_sweep` (transitions a non-terminal checkout attempt stale for 60+ minutes
  to `abandoned`, reusing `ChangeCheckoutAttemptStatusHandler` unchanged).
- Closed the Mollie/Stripe subscription-renewal asymmetry (Q2): Stripe's billing engine already
  pushes renewal webhooks natively; Mollie needed Gomrok to explicitly create a real Mollie
  Subscription resource after the first-payment mandate confirms. Built
  `mollie_subscription_activation_scan` plus the supporting `SupportsDeferredSubscriptionActivation`
  adapter interface, `MollieAdapter::activateSubscription()`, and a `ProcessWebhookEventHandler`
  fallback path that resolves a subscription-reference webhook (`invoice.payment_*` for Stripe,
  Mollie's own subscription-linked payment webhooks) when the primary gateway-reference lookup
  misses.
- Built a new `Reconciliation` module: `payment_reconciliation_scan` and
  `subscription_reconciliation_scan` (30-minute recurrence, 72-hour lookback window) poll each
  recently-touched payment/subscription's real provider status and record a
  `ReconciliationFinding` when it disagrees with Gomrok's local status — detection only, nothing
  here ever mutates the payment/subscription itself.
- Built the admin Jobs screen (`/admin/jobs`) with a real "Run now" write action on a `pending`
  job, and the admin Reconciliation screen (`/admin/reconciliation`) with a real "Mark resolved"
  write action on an open finding.
- Added 75 new unit/integration tests covering every new piece of Phase 29 logic (see Tests and
  Validation).

### Revision: Q4 and Q5 reopened and implemented

After the phase above was first marked complete, the user revised both remaining decisions and
asked for the phase to continue:

- **Q4 (revised from "leave deferred" to "fix it this phase")** — fixed
  `ResolvePaymentActionContext::forPayment()`'s null-for-renewal-payment gap. A payment with no
  `checkout_attempt_id` now resolves its provider account/payment method through
  `subscription_payment_links` → the owning `Subscription`, converging on the exact same
  downstream capability/adapter/gateway-reference resolution a checkout-originated payment already
  used — no bypass of any existing check. `CancelPaymentCommand`/`RefundPaymentCommand`/
  `CapturePaymentCommand` each gained an optional trailing `paymentId` (alongside the existing
  `checkoutAttemptId`, now nullable) so a renewal-originated payment — which has no checkout
  attempt to address it by — can actually be targeted by these actions at all; every existing
  positional call site is untouched and behaves identically.
- **Q5 (revised from "pending" to "persistent daemon worker")** — built `bin/Worker.php`, a
  persistent daemon that continuously polls/claims due jobs via the unchanged
  `RunDueJobsHandler` abstraction until it receives SIGTERM/SIGINT, at which point it finishes the
  in-flight batch and exits cleanly. Per the user's explicit sub-requirements, gathered via a
  follow-up clarifying question on the max-attempts/backoff shape: a recurring job never
  dead-letters or gets escalating backoff from repeated failure (it keeps retrying at its fixed
  interval forever), but its health is now tracked (`consecutive_failures`, `total_failures`,
  `last_failed_at`, `last_success_at`) and surfaced as a visible, spam-guarded alert
  (`alerted_at`/`alert_acknowledged_at`/`alert_acknowledged_by`) on the admin Jobs screen once
  `consecutive_failures` crosses a threshold of 3.

## Files Created

**Shared job-queue infrastructure:**
- `src/Shared/Domain/Jobs/Job.php`, `JobStatus.php`, `JobRepository.php`
- `src/Shared/Application/Jobs/JobHandler.php`, `JobRunResult.php`, `RunDueJobsHandler.php`,
  `JobFilter.php`, `JobDirectory.php`
- `src/Shared/Infrastructure/Persistence/PdoJobRepository.php`, `PdoJobDirectory.php`

**Job handlers (one per module):**
- `src/Modules/Webhooks/Application/Jobs/WebhookRetryScanHandler.php`
- `src/Modules/Notifications/Application/Jobs/NotificationRetryScanHandler.php`
- `src/Modules/Vouchers/Application/Jobs/VoucherReservationSweepHandler.php`
- `src/Modules/Checkout/Application/Jobs/CheckoutAbandonmentSweepHandler.php`
- `src/Modules/Subscriptions/Application/Jobs/MollieSubscriptionActivationScanHandler.php`
- `src/Modules/Reconciliation/Application/Jobs/PaymentReconciliationScanHandler.php`
- `src/Modules/Reconciliation/Application/Jobs/SubscriptionReconciliationScanHandler.php`

**Mollie deferred-activation support:**
- `src/Modules/Providers/Application/Adapter/ActivateSubscriptionCommand.php`
- `src/Modules/Providers/Application/Adapter/SupportsDeferredSubscriptionActivation.php`

**New Reconciliation module (`src/Modules/Reconciliation/`):**
- `Domain/ReconciliationFinding.php`, `ReconciliationFindingRepository.php`, `ReconciliationTargetType.php`
- `Application/ReconciliationFindingDirectory.php`, `ReconciliationFindingFilter.php`
- `Application/ResolveReconciliationFinding/ResolveReconciliationFindingCommand.php`, `ResolveReconciliationFindingHandler.php`
- `Infrastructure/PdoReconciliationFindingDirectory.php`, `PdoReconciliationFindingRepository.php`, `definitions.php`

**Admin Jobs screen:**
- `src/Modules/Admin/Application/Jobs/JobsFilterState.php`, `JobRow.php`, `JobsScreenResult.php`, `JobsScreenHandler.php`
- `src/Modules/Admin/Application/Jobs/RunJobNow/RunJobNowCommand.php`, `RunJobNowHandler.php`
- `src/Http/Admin/AdminJobsAction.php`, `AdminJobRunNowAction.php`
- `src/Modules/Admin/Views/jobs.html.twig`

**Admin Reconciliation screen:**
- `src/Modules/Admin/Application/Reconciliation/ReconciliationFilterState.php`, `ReconciliationRow.php`, `ReconciliationScreenResult.php`, `ReconciliationScreenHandler.php`
- `src/Http/Admin/AdminReconciliationAction.php`, `AdminReconciliationResolveAction.php`
- `src/Modules/Admin/Views/reconciliation.html.twig`

**Migrations:**
- `src/Database/Migrations/20260917180001_create_jobs_table.php`
- `src/Database/Migrations/20260917180002_create_reconciliation_findings_table.php`
- `src/Database/Migrations/20260917180003_add_subscription_reference_to_webhook_events.php`

**Test doubles (`tests/Support/`):**
- `InMemoryJobRepository.php` (doubles as `JobRepository` + `JobDirectory`)
- `InMemoryReconciliationFindingRepository.php` (doubles as `ReconciliationFindingRepository` + `ReconciliationFindingDirectory`)
- `FakeJobHandler.php`, `RecordingErrorLogWriter.php`

**Tests (18 new test files, 75 new tests):**
- `tests/Unit/Shared/Domain/Jobs/JobTest.php`
- `tests/Unit/Shared/Application/Jobs/RunDueJobsHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/Jobs/JobsScreenHandlerTest.php`, `RunJobNowHandlerTest.php`
- `tests/Unit/Modules/Webhooks/Application/Jobs/WebhookRetryScanHandlerTest.php`
- `tests/Unit/Modules/Notifications/Application/Jobs/NotificationRetryScanHandlerTest.php`
- `tests/Unit/Modules/Vouchers/Application/Jobs/VoucherReservationSweepHandlerTest.php`
- `tests/Unit/Modules/Checkout/Application/Jobs/CheckoutAbandonmentSweepHandlerTest.php`
- `tests/Unit/Modules/Subscriptions/Application/Jobs/MollieSubscriptionActivationScanHandlerTest.php`
- `tests/Unit/Modules/Reconciliation/Domain/ReconciliationFindingTest.php`
- `tests/Unit/Modules/Reconciliation/Application/ResolveReconciliationFindingHandlerTest.php`
- `tests/Unit/Modules/Reconciliation/Application/Jobs/PaymentReconciliationScanHandlerTest.php`, `SubscriptionReconciliationScanHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/Reconciliation/ReconciliationScreenHandlerTest.php`
- `tests/Integration/JobsReconciliationPersistenceTest.php`

**Evidence screenshots (`tools/screenshots/out/`):**
- `phase29-jobs.png`, `phase29-jobs-runnow-success.png`, `phase29-reconciliation.png`, `phase29-reconciliation-resolved.png`
- `phase29q5-jobs-alerting.png`, `phase29q5-jobs-acknowledged.png` (Q5 revision evidence)

### Files Created — Q4/Q5 revision

**Persistent daemon:**
- `bin/Worker.php`

**Job alert acknowledgement (admin):**
- `src/Modules/Admin/Application/Jobs/AcknowledgeJobAlert/AcknowledgeJobAlertCommand.php`, `AcknowledgeJobAlertHandler.php`
- `src/Http/Admin/AdminJobAcknowledgeAlertAction.php`

**Migration:**
- `src/Database/Migrations/20260917235500_add_health_tracking_to_jobs_table.php`

**Tests:**
- `tests/Unit/Modules/Admin/Application/Jobs/AcknowledgeJobAlertHandlerTest.php`
- New test cases (not new files) added to `JobTest.php`, `RunDueJobsHandlerTest.php`,
  `JobsScreenHandlerTest.php`, `CancelPaymentHandlerTest.php`, `RefundPaymentHandlerTest.php`,
  `CapturePaymentHandlerTest.php` for the health-tracking/alert domain logic and the
  renewal-originated payment action paths.

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — registered `Reconciliation`'s `definitions.php` in `MODULE_DEFINITIONS`.
- `src/Config/container.php` — wired `JobRepository`/`JobDirectory` to their Pdo implementations and `RunDueJobsHandler`'s `handlers` array (all 7 job handlers).
- `src/Config/routes.php` — added `/admin/jobs`, `/admin/jobs/{jobId}/run-now`, `/admin/reconciliation`, `/admin/reconciliation/{findingId}/resolve`.
- `src/Modules/Admin/Application/AdminPermission.php` — added `ReconciliationResolve = 'reconciliation.resolve'` (`ReconciliationView` already existed from CLAUDE.md's suggested list).
- `src/Modules/Admin/Views/layouts/shell.html.twig` — added "Jobs" and "Reconciliation" sidebar nav items.
- `src/Public/admin.css` — added `status-done`/`status-processing`/`status-open` status-pill color rules.
- `src/Modules/Providers/Application/Adapter/ParsedWebhookEvent.php` — added trailing optional `subscriptionReference`.
- `src/Modules/Providers/Application/Adapter/ProviderSubscriptionResult.php` — added trailing optional `customerId`.
- `src/Modules/Providers/Infrastructure/Adapter/Mollie/MollieAdapter.php` — `parseWebhook()` extracts `subscriptionReference`; `createSubscription()` returns `customerId`; new `activateSubscription()`/`toMollieInterval()`; now `implements SupportsDeferredSubscriptionActivation`.
- `src/Modules/Providers/Infrastructure/Adapter/Stripe/StripeAdapter.php` — `parseWebhook()` special-cases `invoice.payment_*` events with synthetic raw statuses.
- `src/Modules/Providers/Infrastructure/Adapter/Stripe/StripeStatusMapper.php` — `fromPaymentIntent()` gained a `'payment_failed'` case.
- `src/Modules/Webhooks/Domain/WebhookEvent.php` — added trailing optional `subscriptionReference` + accessor.
- `src/Modules/Webhooks/Application/IngestWebhookEvent/IngestWebhookEventHandler.php` — passes `subscriptionReference` through to `WebhookEvent::receive()`.
- `src/Modules/Webhooks/Infrastructure/PdoWebhookEventRepository.php` — INSERT/bindings/hydrate updated for `subscription_reference`.
- `src/Modules/Webhooks/Application/ProcessWebhookEvent/ProcessWebhookEventHandler.php` — new `RecordSubscriptionPaymentHandler` constructor dependency; `attempt()` falls back to `attemptSubscriptionRenewal()` when the primary reference lookup misses and a subscription reference is present.
- `src/Modules/Checkout/Application/CreateProviderSubscription/CreateProviderSubscriptionHandler.php` — also records a `Customer`-type gateway reference for Mollie.
- `src/Modules/Checkout/Domain/CheckoutAttemptRepository.php` / `Infrastructure/PdoCheckoutAttemptRepository.php` — added `findStaleNonTerminal()`.
- `src/Modules/Payments/Domain/PaymentRepository.php` / `Infrastructure/PdoPaymentRepository.php` — added `findRecentForReconciliation()`.
- `src/Modules/Subscriptions/Domain/SubscriptionRepository.php` / `Infrastructure/PdoSubscriptionRepository.php` — added `findPendingMollieActivation()`, `findRecentForReconciliation()`.
- `src/Modules/Subscriptions/Infrastructure/definitions.php` — wired `MollieSubscriptionActivationScanHandler`'s `appBaseUrl` string parameter.
- `src/Modules/Vouchers/Domain/VoucherRedemptionRepository.php` / `Infrastructure/PdoVoucherRedemptionRepository.php` — added `findStaleReserved()`.
- `tests/Integration/MigrationRoundTripTest.php` — added `jobs`, `reconciliation_findings` to the tracked TABLES list.
- `tests/Support/FakePaymentProviderPort.php` — now also `implements SupportsDeferredSubscriptionActivation`; added `activateSubscription()` + configuration setters.
- `tests/Support/InMemoryCheckoutAttemptRepository.php`, `InMemoryPaymentRepository.php`, `InMemorySubscriptionRepository.php`, `InMemoryVoucherRedemptionRepository.php` — implemented the new repository methods above.
- `tests/Unit/Http/WebhooksReceiveActionTest.php`, `tests/Unit/Jobs/RetryPendingWebhookEventsTest.php`, `tests/Unit/Modules/Webhooks/Application/IngestWebhookEventHandlerTest.php`, `tests/Unit/Modules/Webhooks/Application/ProcessWebhookEventHandlerTest.php` — updated to construct `ProcessWebhookEventHandler`'s new `RecordSubscriptionPaymentHandler` dependency.
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`, `db_explain.md` — new "Background jobs, reconciliation & observability (Phase 29)" sections; `webhook_events` gained `subscription_reference`.
- `.claude/PhaseResults/PhaseDecisions.md` — Phase 29 Q1–Q5 recorded.
- `.claude/docs/Phases.md` — Phase 29 row: Status ☑, End Datetime, Actual Duration.
- `.claude/Changelog.md` — Phase 29 entry.

### Files Modified — Q4/Q5 revision

- `src/Modules/Payments/Application/ResolvePaymentActionContext.php` — `forPayment()` now
  resolves the provider account/payment method for a renewal-originated payment (no checkout
  attempt) via `SubscriptionPaymentLinkRepository` → `SubscriptionRepository`, converging on the
  same downstream capability/adapter/gateway-reference resolution as the checkout-originated path.
- `src/Modules/Payments/Application/CancelPayment/CancelPaymentCommand.php`,
  `src/Modules/Payments/Application/RefundPayment/RefundPaymentCommand.php`,
  `src/Modules/Payments/Application/CapturePayment/CapturePaymentCommand.php` — `checkoutAttemptId`
  made nullable (default `null`), each gained a trailing optional `paymentId`.
- `src/Modules/Payments/Application/CancelPayment/CancelPaymentHandler.php`,
  `RefundPayment/RefundPaymentHandler.php`, `CapturePayment/CapturePaymentHandler.php` — each
  gained a private `resolvePayment()`/`notFoundMessage()` pair: resolves by `paymentId` when
  given, else by `checkoutAttemptId` as before.
- `src/Shared/Domain/Jobs/Job.php` — 7 new fields (`consecutiveFailures`, `totalFailures`,
  `lastFailedAt`, `lastSuccessAt`, `alertedAt`, `alertAcknowledgedAt`, `alertAcknowledgedBy`),
  `ALERT_THRESHOLD = 3` constant, `acknowledgeAlert()`, `hasOpenAlert()`,
  `isAlertUnacknowledged()`; `recordFailure()`/`recordSuccess()` updated accordingly.
  `fromStorage()`'s signature changed (7 new required params) — both `PdoJobRepository` and
  `PdoJobDirectory` updated; `schedule()`'s signature is unchanged.
- `src/Shared/Infrastructure/Persistence/PdoJobRepository.php`, `PdoJobDirectory.php` — INSERT/
  UPDATE/hydrate updated for the 7 new columns.
- `src/Shared/Application/Jobs/JobDirectory.php` — added `countAlerting(): int`.
- `src/Shared/Application/Jobs/RunDueJobsHandler.php` — a handler's returned
  `JobRunResult::failure()` (not just a thrown exception) is now also logged to `error_logs`
  (`source: 'job'`), guarded against double-logging when the failure came from a caught exception.
- `src/Modules/Admin/Application/Jobs/JobRow.php`, `JobsScreenResult.php`, `JobsScreenHandler.php`
  — surface the new health/alert fields; `JobsScreenHandler` gained an `AdminUserRepository`
  dependency to resolve `alertAcknowledgedBy` to a name.
- `src/Config/routes.php` — added `/admin/jobs/{jobId}/acknowledge-alert`.
- `src/Modules/Admin/Views/jobs.html.twig` — alerting/ack'd status pill per row, an "alerting"
  count badge, health detail in the expanded row, and an "Acknowledge alert" button.
- `tests/Support/InMemoryJobRepository.php` — implements the new `countAlerting()`.
- `tests/Support/FakePaymentProviderPort.php` — no further change this revision (already extended
  earlier in the phase for Q2).
- `composer.json` — added the `jobs:worker` script (`php bin/Worker.php`).
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md` — `jobs` table gained the 7 health/alert columns and `idx_jobs_alerted`.
- `.claude/PhaseResults/PhaseDecisions.md` — Q4 revised with change history (Decided → Decided
  (revised)); Q5 changed from Pending to Decided, with the user's detailed sub-requirements
  recorded verbatim.

## Implementation Details

**Unified queue design.** A single `jobs` table (no `client_id` — system-wide) with `type`,
`status` (`pending`/`processing`/`done`/`failed`/`dead_lettered`), `attempts`, `run_at`,
`locked_at`/`locked_by`, `last_error`/`last_result`, `created_at`/`updated_at`. Recurring job
types have no separate schedule table — a handler that finishes always re-enqueues its own next
occurrence (`Job::recordSuccess()`/`recordFailure()` take an optional `nextRunAt`; passing one
reschedules back to `pending`, `null` leaves the job terminal). `PdoJobRepository::claimDue()`
uses `SELECT ... FOR UPDATE SKIP LOCKED` inside a transaction, matching `PdoIdempotencyStore`'s
existing claim-under-lock pattern, so multiple future workers can't double-claim the same row.
`RunDueJobsHandler::run()` self-bootstraps every registered recurring handler via
`ensureScheduled()` before claiming — a handler with no existing row (first-ever run, or one just
added in code) gets seeded due immediately; a no-op once a pending/processing row exists.

**Admin "run now."** `RunDueJobsHandler::runOne(int $jobId, string $lockedBy): ?bool` claims one
specific job by id regardless of `run_at`, dispatches it, and returns `null` (job doesn't exist or
isn't `pending`) / `true` (succeeded) / `false` (ran, but the handler reported failure — a real
outcome, not a rejection). `RunJobNowHandler` (`jobs.retry`) treats only the `null` case as an
error; a job that ran and failed still gets an audit entry, since the action itself succeeded.

**Mollie subscription activation.** `MollieSubscriptionActivationScanHandler` finds subscriptions
via `SubscriptionRepository::findPendingMollieActivation()` (active/trialing, Mollie provider
type, no existing `Subscription`-type gateway reference), resolves the Mollie customer id
recorded against the origin checkout attempt (`Customer`-type gateway reference, added by
`CreateProviderSubscriptionHandler` this phase), resolves the account's active webhook endpoint
URL, and calls `MollieAdapter::activateSubscription()`. On success it records a
`Subscription`-type gateway reference, after which Mollie's own renewal webhooks resolve through
`ProcessWebhookEventHandler`'s new fallback path exactly like Stripe's already do.

**Reconciliation is detection-only.** Both scan handlers compare local status to the provider's
real status and record a `ReconciliationFinding` on disagreement; neither ever calls a status-
changing handler. `ResolveReconciliationFindingHandler`'s "resolve" means "an admin reviewed this
drift," never "this was fixed" — the same convention Phase 27's Error Logs `resolved_at`/
`resolved_by` uses.

**Fault isolation.** `RunDueJobsHandler::dispatch()` catches any `Throwable` a handler throws,
logs it via `ErrorLogWriter` (`source: 'job'`), and records the job as `failed` — one bad job
never aborts the rest of the claimed batch. Every scan-type handler additionally catches
`ProviderAdapterException`/`UnsupportedProviderType` per-item internally, so one payment/
subscription/account with a broken provider connection doesn't stop the whole scan.

## Database Changes

Two new tables, one additive column, all confirmed via the Database Design Confirmation Rule
before creation (the `webhook_events.subscription_reference` column separately, mid-phase, after
being discovered necessary):

- **`jobs`** — `id`, `type` (varchar 60), `payload` (text, nullable), `status` (varchar 20,
  default `pending`), `attempts` (smallint unsigned), `run_at` (datetime), `locked_at`/`locked_by`
  (nullable), `last_error`/`last_result` (text, nullable), `created_at`/`updated_at`. Indexes:
  `idx_jobs_claim_scan` on `(status, run_at)`, `idx_jobs_type` on `(type)`. No `client_id` — a
  system-wide queue, unlike every other business table in this schema.
- **`reconciliation_findings`** — `id`, `client_id` (FK `clients`, `ON DELETE CASCADE`),
  `target_type`/`target_id` (polymorphic), `local_status`, `provider_status_raw`,
  `mapped_provider_status` (nullable), `detected_at`, `resolved_at`/`resolved_by` (nullable),
  `created_at`. Indexes on `(client_id, resolved_at)` and `(target_type, target_id)`.
- **`webhook_events.subscription_reference`** — additive `VARCHAR(191) NULL`, positioned after
  `provider_reference`.

Migrations: `20260917180001_create_jobs_table.php`, `20260917180002_create_reconciliation_findings_table.php`,
`20260917180003_add_subscription_reference_to_webhook_events.php`. All three verified round-trip
clean (`vendor/bin/phinx migrate` / `rollback` / `migrate` again), and covered by
`tests/Integration/MigrationRoundTripTest.php` (updated table list) and the new
`tests/Integration/JobsReconciliationPersistenceTest.php`.

**Q4/Q5 revision — one additional additive migration**, proposed and confirmed separately:

- **`jobs`** gained 7 columns: `consecutive_failures` (smallint unsigned, default `0`),
  `total_failures` (int unsigned, default `0`), `last_failed_at` / `last_success_at` (datetime,
  nullable), `alerted_at` (datetime, nullable), `alert_acknowledged_at` (datetime, nullable),
  `alert_acknowledged_by` (int unsigned, nullable, no FK — same unconstrained convention as
  `error_logs.resolved_by` / `reconciliation_findings.resolved_by`). New index
  `idx_jobs_alerted` on `(alerted_at)`.

Migration: `20260917235500_add_health_tracking_to_jobs_table.php`. Verified round-trip clean
(`vendor/bin/phinx migrate` / `rollback` / `migrate` again) against the real local dev database.

## API Changes

No client-facing `/api/v1` changes. Two new admin-only routes:

- `GET /admin/jobs` (`jobs.view`) — list + filter (`status`, `type`), paginated.
- `POST /admin/jobs/{jobId}/run-now` (`jobs.retry`) — runs one `pending` job immediately.
- `GET /admin/reconciliation` (`reconciliation.view`) — list + filter (`client_id`, `resolution`), paginated, defaults to `open`.
- `POST /admin/reconciliation/{findingId}/resolve` (`reconciliation.resolve`) — idempotent mark-resolved.

**Q4/Q5 revision:** one more new admin-only route — `POST /admin/jobs/{jobId}/acknowledge-alert`
(`jobs.retry`, idempotent). No client-facing `/api/v1` route was added for Q4's fix — the existing
`/api/v1/payments/{id}/cancel|refund|capture` endpoints are unchanged (`{id}` still means the
checkout attempt id); the new `paymentId`-addressable path on `CancelPaymentCommand`/
`RefundPaymentCommand`/`CapturePaymentCommand` is reachable today only by constructing the command
directly (e.g. a future admin payments screen, out of scope this phase) — no HTTP surface exists
yet for a renewal-originated payment specifically, since none was requested.

## Tests and Validation

**Tests created** (18 files, 75 tests):
- `tests/Unit/Shared/Domain/Jobs/JobTest.php` — 9 tests (schedule/claim/recordSuccess/recordFailure/deadLetter/assignId/fromStorage).
- `tests/Unit/Shared/Application/Jobs/RunDueJobsHandlerTest.php` — 11 tests (self-bootstrap, reschedule on success/failure, throw-is-caught-and-logged, dead-letter on unhandled type, one bad job doesn't block the batch, `runOne()`'s null/true/false outcomes).
- `tests/Unit/Modules/Admin/Application/Jobs/JobsScreenHandlerTest.php` — 6 tests. `RunJobNowHandlerTest.php` — 4 tests.
- `tests/Unit/Modules/Webhooks/Application/Jobs/WebhookRetryScanHandlerTest.php` — 3 tests (against a real `ProcessWebhookEventHandler` with in-memory doubles).
- `tests/Unit/Modules/Notifications/Application/Jobs/NotificationRetryScanHandlerTest.php` — 3 tests.
- `tests/Unit/Modules/Vouchers/Application/Jobs/VoucherReservationSweepHandlerTest.php` — 3 tests.
- `tests/Unit/Modules/Checkout/Application/Jobs/CheckoutAbandonmentSweepHandlerTest.php` — 3 tests.
- `tests/Unit/Modules/Subscriptions/Application/Jobs/MollieSubscriptionActivationScanHandlerTest.php` — 3 tests.
- `tests/Unit/Modules/Reconciliation/Domain/ReconciliationFindingTest.php` — 5 tests. `Application/ResolveReconciliationFindingHandlerTest.php` — 3 tests. `Application/Jobs/PaymentReconciliationScanHandlerTest.php` — 4 tests. `SubscriptionReconciliationScanHandlerTest.php` — 4 tests.
- `tests/Unit/Modules/Admin/Application/Reconciliation/ReconciliationScreenHandlerTest.php` — 9 tests.
- `tests/Integration/JobsReconciliationPersistenceTest.php` — 4 tests (real MySQL round-trip for `jobs`, `reconciliation_findings`, `webhook_events.subscription_reference`).

**Tests modified:** none of the pre-existing test *behavior* changed; 4 files were updated only
to construct `ProcessWebhookEventHandler`'s new required constructor dependency (see Files
Modified).

**Tests actually executed (original phase completion):**
```
vendor/bin/phpstan analyse
vendor/bin/phpunit
vendor/bin/phpunit --testsuite integration
```

**Results at original completion (real, captured output):**
- `vendor/bin/phpstan analyse` → `[OK] No errors` (1179 files analysed).
- `vendor/bin/phpunit` → `Tests: 869, Assertions: 3272, Skipped: 3.` — `OK, but some tests were
  skipped!` (the 3 skips are the pre-existing Stripe/Mollie/PayPal live-credential tests, unrelated
  to this phase). Before Phase 29's changes the suite stood at 794 tests; this phase added 75.
- `vendor/bin/phpunit --testsuite integration` → `Tests: 45, Assertions: 420, Skipped: 3.` `OK`.

### Q4/Q5 revision — tests created and results

**Tests created:**
- `tests/Unit/Modules/Admin/Application/Jobs/AcknowledgeJobAlertHandlerTest.php` — 4 tests.
- New test cases added within `JobTest.php` (+7: `recordFailure` increment,
  alert-raised-at-threshold, no-re-raise-on-a-4th-failure, `recordSuccess` resets/clears,
  acknowledge no-op/idempotent/stays-open), `RunDueJobsHandlerTest.php` (+3: returned-failure also
  logged to `error_logs`, recurring failure never dead-letters across 10 iterations, alert raised
  at the 3rd consecutive failure — plus its pre-existing throwing-handler test was strengthened to
  also assert the log isn't doubled, not counted as new), `JobsScreenHandlerTest.php` (+3: alerting count, acknowledger name resolution, unacknowledged
  has no acknowledger label), `CancelPaymentHandlerTest.php` (+3: renewal cancel by `paymentId`,
  provider-not-supported on a renewal payment, cross-client not-found), `RefundPaymentHandlerTest.php`
  (+2: renewal full refund, provider-not-supported on a renewal payment), `CapturePaymentHandlerTest.php`
  (+2: renewal capture, provider-not-supported on a renewal payment).
- `tests/Integration/JobsReconciliationPersistenceTest.php`'s job round-trip test continues to
  pass unchanged against the now-wider `jobs` schema (implicitly exercises the 7 new columns via
  `PdoJobRepository::save()`/`findById()`).

**Tests actually executed:**
```
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

**Final results (real, captured output):**
- `vendor/bin/phpstan analyse` → `[OK] No errors` (1185 files analysed).
- `vendor/bin/phpunit` → `Tests: 893, Assertions: 3359, Skipped: 3.` `OK, but some tests were
  skipped!` (same 3 pre-existing live-credential skips). 869 → 893 across the revision (+24 tests:
  4 new `AcknowledgeJobAlertHandlerTest` + 7 `JobTest` + 3 `RunDueJobsHandlerTest` + 3
  `JobsScreenHandlerTest` + 7 across the three payment-action handler test files — one test,
  `recordFailureIncrementsConsecutiveAndTotalFailures`, was also fixed mid-authoring for an
  `assertSame`-on-freshly-constructed-`DateTimeImmutable`-objects mistake, not counted as new).

Run with: `composer test` (unit only), `composer test:integration`, `composer test:all`.

## Technical Decisions

Full record with all options/tradeoffs in `.claude/PhaseResults/PhaseDecisions.md`. Summary:

- **Q1 — Unified DB-backed `jobs` table** (recommended, selected) over a per-job-type table or a
  real message broker. Mid-implementation, explicitly re-confirmed with the user that "unified"
  meant migrating the two already-built Phase 25/28 jobs onto the same table too, not just new
  Phase 29 work — confirmed yes.
- **Q2 — Finish real Mollie Subscriptions this phase** (recommended, selected) rather than
  deferring the renewal-automation gap again.
- **Q3 — Full breadth (all listed job types + reconciliation), trace-logs-only observability**
  (recommended, selected) — no metrics/alerts/dashboards infrastructure introduced.
- **Q4 (revised) — Fix `ResolvePaymentActionContext::forPayment()`'s null-for-renewal-payment
  gap this phase**, reversing the earlier "leave deferred" choice. User-specified: resolve via
  `subscription_payment_links` when there's no checkout attempt; keep the checkout-originated path
  unchanged; keep every existing gateway-reference/capability check; add tests.
- **Q5 (revised) — Persistent daemon worker**, not the recommended cron-batch shape. User-specified:
  continuous poll/claim loop, concurrency-safe claiming (already true, kept unchanged), recurring
  jobs never dead-letter or get escalating backoff from repeated failure but their health must be
  tracked and surfaced with an alert once they cross a failure threshold, worker logic stays behind
  the existing `JobHandler`/`RunDueJobsHandler` abstraction, no business handler changes, document
  the need for a production process supervisor (systemd/supervisord) — Phase 30 finalizes the
  actual deployment/supervisor config.

Additional decisions made during implementation (not separately numbered, but load-bearing):
- `RunOne()`'s three-way `null`/`true`/`false` return, distinguishing "not runnable" from "ran and
  failed," so the admin "run now" action doesn't misreport a legitimate failure as a rejection.
- `FakePaymentProviderPort` (a shared test double used across many existing tests) was extended to
  additionally implement `SupportsDeferredSubscriptionActivation` — additive only, no existing
  test's behavior changed.
- **Q5 revision — max-attempts/dead-letter scope**, resolved via a follow-up clarifying question
  (not one of the original 5) before writing the health-tracking schema: recurring jobs never
  dead-letter or backoff from repeated failure (user explicitly rejected the recommended
  per-job-type cap), but consecutive/total failure counts, last-failed/succeeded timestamps, and a
  spam-guarded admin-visible alert (raised once per failure episode, cleared on the next success or
  an admin acknowledgement) are tracked. Backoff shape: fixed interval, no escalation (user
  explicitly rejected the recommended exponential-with-cap option) — `RunDueJobsHandler`'s
  `nextRunAt` computation is completely unchanged from the original phase.
- **Q4 architectural note:** `Payments\Application\ResolvePaymentActionContext` now depends on
  `Subscriptions\Domain\{SubscriptionPaymentLinkRepository,SubscriptionRepository}` — a new
  Payments → Subscriptions dependency alongside the pre-existing Subscriptions → Payments one.
  Accepted deliberately rather than inverted behind a new port: this codebase's application layer
  already cross-references sibling modules' domain repositories directly throughout (e.g.
  `Subscriptions\Application\ResolveSubscriptionActionContext` already imports from `Providers`
  and `Payments`), and PHP's autoloading has no static compile-time cycle problem with this —
  introducing a port here to avoid a bidirectional *module* reference would be ceremony this
  codebase's established style doesn't otherwise use.

## Problems Encountered

- `RunDueJobsHandler::runOne()`'s original `bool` return type conflated "job not found/not
  pending" with "job ran but its handler failed" — both produced `false`, which would have made
  `RunJobNowHandler` report a legitimate scan failure as "this job could not be run."
- `ReconciliationFinding::detect()` was initially authored with a bug —
  `new self($clientId === null ? 0 : $clientId, $clientId, ...)` — passing `$clientId` (always
  non-null `int`) into the `$id` constructor slot instead of `null`.
- `MollieSubscriptionActivationScanHandler::findCustomerId()` initially had a dead null-check on
  `Subscription::checkoutAttemptId()`, which is declared non-nullable `int`.
- `ResolveReconciliationFindingHandler`'s docblock referenced a non-existent `reconciliation.view`
  permission for its "resolve" action instead of the real `reconciliation.resolve`.
- The admin Jobs and Reconciliation screenshot evidence had to be recaptured once: running the
  full `vendor/bin/phpunit` suite between the first evidence-gathering pass and the second wiped
  the shared local dev database's `jobs` and `admin_users` tables (the integration suite truncates
  tables it has persistence tests for) — the seeded evidence admin user and job rows were gone on
  the next request.

### Q4/Q5 revision

- All three of `CancelPaymentCommand`/`RefundPaymentCommand`/`CapturePaymentCommand` are keyed
  solely by `checkoutAttemptId`, and every `/api/v1/payments/{id}*` HTTP action passes the
  checkout attempt id straight through — discovered mid-implementation that fixing
  `ResolvePaymentActionContext` alone wasn't sufficient for Q4's literal ask ("renewal-originated
  payments should support refund/capture/cancel"), since there was no way for any caller to even
  identify a renewal payment (which has no checkout attempt) to these commands at all.
- `CapturePaymentHandlerTest::capturesARenewalOriginatedPaymentByPaymentIdViaTheSubscriptionPaymentLink`
  initially asserted the wrong value against `$this->adapter->lastCaptureReference` — confused the
  *recorded* gateway reference passed into `capturePayment()` with the *returned* provider
  reference from the capture result (two different, deliberately distinct strings in the test
  fixture).
- Two new `RunDueJobsHandlerTest` cases initially failed:
  `aRecurringFailureNeverDeadLettersAndKeepsReschedulingAtTheFixedInterval` (asserted
  `consecutiveFailures() === 10` after 10 loop iterations but got `1`) and
  `aRecurringJobRaisesAnAlertOnceConsecutiveFailuresCrossTheThreshold`. Both used a `FrozenClock`
  that never advances between iterations, so after the first failure `run_at` was rescheduled 5
  minutes into a "now" that never actually moved — every subsequent `claimDue()` found nothing due.
- `JobTest::recordFailureIncrementsConsecutiveAndTotalFailures` initially failed `assertSame()` on
  `$job->lastFailedAt()` — `$this->now()` constructs a brand-new `DateTimeImmutable` object on
  every call, so two separate calls are equal in value but not the same object instance.

## Resolutions

- Changed `runOne()`'s return type to `?bool`: `null` = not runnable (job missing or not
  `pending`), `true`/`false` = ran and succeeded/failed. `RunJobNowHandler` now only rejects the
  `null` case.
- Fixed `ReconciliationFinding::detect()` to pass `null` for the id parameter, matching every other
  entity's "id is null until persisted" pattern.
- Removed the dead null-check in `findCustomerId()`.
- Corrected the docblock to `reconciliation.resolve`.
- Re-ran the admin user creation and a live `RunDueJobsHandler->run()` against the dev DB
  immediately before each screenshot capture, and captured all four screenshots (Jobs, Jobs after
  "run now," Reconciliation, Reconciliation after "resolve") in one uninterrupted pass before
  running `phpunit` again. Afterward, cleaned up the throwaway seeded reconciliation findings and
  the temporary evidence admin user from the shared dev database — the real `jobs` rows were left
  in place as genuine steady-state, not demo data (mirroring the same real-vs-throwaway-data
  distinction Phase 28's evidence cleanup used).

### Q4/Q5 revision

- Added `resolvePayment()`/`notFoundMessage()` private helpers to all three handlers that resolve
  by `paymentId` when given, falling back to `checkoutAttemptId` — the existing checkout-originated
  code path is byte-for-byte the same query it always was, just reached through the new helper.
- Fixed the test's `lastCaptureReference` assertion to check the recorded reference (what
  `capturePayment()` was called *with*), added a separate assertion on `$value->providerReference`
  for what the adapter returned.
- Added `$this->clock->advanceSeconds(301)` between loop iterations in both new
  `RunDueJobsHandlerTest` cases so each simulated "next poll" actually finds the just-rescheduled
  job due again, matching how real wall-clock time would pass between a daemon's poll cycles.
- Captured the failure timestamp once into a local `$failedAt` variable and reused it for both the
  `recordFailure()` call and the assertion, instead of calling `$this->now()` twice.
- For the admin UI evidence: seeded a throwaway `evidence_alerting_job` job type directly via
  `JobRepository` (3 forced failures, `run_at` set a year out so the real daemon/worker would never
  touch it), captured before/after screenshots around a real `POST .../acknowledge-alert`, verified
  the DB row and a `job.alert_acknowledged` audit entry, then deleted the evidence job row, its
  audit entries, and the temporary evidence admin user — the 7 real job rows were untouched
  throughout.

## Deferred Work

- **Q4 and Q5 are no longer deferred** — both were revised and implemented this phase (see
  "Revision: Q4 and Q5 reopened and implemented" above).
- **No client-facing `/api/v1` or admin HTTP route reaches a renewal-originated payment's
  cancel/refund/capture yet.** `ResolvePaymentActionContext`/the three command objects can resolve
  and act on one when constructed directly with a `paymentId`, and this is tested, but no admin
  payments screen or API endpoint exists to actually drive it from the UI — building that screen
  was not requested and is out of scope here.
- **Production supervisor configuration for `bin/Worker.php` is explicitly Phase 30's**, per the
  user's own Q5 requirement ("Phase 30 can finalize the actual deployment/supervisor
  configuration"). This phase only builds and documents the daemon script itself — no systemd
  unit file, supervisord config, or container entrypoint wiring was created.
- **The alert failure threshold (3) is a single hardcoded constant**, not per-job-type or
  DB-configurable — matches what was actually asked for ("a configurable failure threshold, for
  example after 3 or 5"), read as "pick one sensible value," not "build a tuning UI."
- **Per-occurrence failure history beyond the single most-recent `last_error`/`last_failed_at`
  lives in `error_logs`, not a dedicated job-failure-history table** — every returned
  `JobRunResult::failure()` (not just a thrown exception) is now logged there, so the existing
  Error Logs screen is the debugging trail; no new admin UI was built specifically for
  "job N's failure history."
- **Concurrent-daemon behavior was verified via the existing `claimDue()`/`FOR UPDATE SKIP LOCKED`
  mechanism and its integration test, not by literally running two `bin/Worker.php` processes
  against each other during this phase** — the locking mechanism itself predates this revision
  (built and tested earlier in Phase 29) and is unchanged by making the invocation shape
  persistent.
- Metrics, alerts, and dashboards (CLAUDE.md's "Logging and Observability" section) were
  explicitly scoped out this phase (Q3) — trace/error logs only.
- No admin UI exists yet to view a job's full `last_result`/`last_error` history across multiple
  runs — only the current row's latest values.

## Final Result

Every background task in Gomrok — old and new — now runs through one unified, self-rescheduling
`jobs` table with admin visibility and a manual "run now" override, **continuously invoked by a
real persistent daemon (`bin/Worker.php`)** rather than left unscheduled. A recurring job that
fails repeatedly keeps retrying forever at its normal cadence (never dead-letters, never backs
off) but now surfaces a spam-guarded, admin-acknowledgeable alert once it crosses 3 consecutive
failures, with every failure — thrown or returned — logged to the existing Error Logs screen for
debugging history. Mollie subscriptions are no longer permanently stuck without real renewal
automation: once a mandate confirms, Gomrok creates the actual Mollie Subscription resource and
becomes webhook-driven exactly like Stripe. Gomrok can detect (though not auto-correct) drift
between its own payment/subscription status and what each provider actually reports, with an
admin report to review and clear findings. Cancel/refund/capture now work on a
subscription-renewal-originated payment (no checkout attempt of its own) exactly as they do on a
checkout-originated one, addressed by `paymentId` and resolved through `subscription_payment_links`
— though no admin or client-facing route reaches this path yet, only direct command construction.

893 tests pass (75 at original completion + 24 in this revision), `phpstan analyse` is clean, and
six admin-surface interactions were exercised against the real local dev database with captured
screenshot + HTTP-response + database + audit-log evidence (Jobs list, Jobs "run now,"
Reconciliation list, Reconciliation "resolve," Jobs alert display, Jobs "acknowledge alert"). The
persistent daemon was smoke-tested directly: it self-bootstrapped and ran all 7 job types on first
poll, idled silently when nothing was due, and shut down cleanly on SIGTERM after finishing its
in-flight batch. Both decisions this document originally listed as open (Q4, Q5) are now resolved
and implemented, per the user's explicit revision. Next recommended phase: **Phase 30 —
Hardening, docs & first-client go-live**, which per Q5's own scope now owns configuring the actual
production process supervisor (systemd/supervisord/equivalent) that keeps `bin/Worker.php` running
and auto-restarted — this phase built and documented the daemon script itself but not its
production supervision.
