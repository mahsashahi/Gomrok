# Phase 28 — Client callbacks / outbound notifications

## Execution Summary

- Phase: 28 — Client callbacks / outbound notifications
- Start Datetime: 2026-09-17 15:00
- End Datetime: 2026-09-17 23:35
- Estimated Duration: 4–6h
- Actual Duration: ~8h 35m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- Asked and recorded all 5 decision questions (Q1–Q5) in `.claude/PhaseResults/PhaseDecisions.md`
  before any decision-dependent implementation, per the Interactive Phase Rule.
- Proposed and confirmed the database design for two new tables, then created and applied the
  migrations.
- Finally wired the in-process `DomainEventDispatcher` that `Architecture.md` §5 sketched at
  Phase 6 and explicitly left unbuilt ("lands with the first subscriber").
- Added `PaymentStatusChanged` / `SubscriptionStatusChanged` domain events, raised — only on a
  genuine status change, never a same-status no-op — by the four handlers that transition
  payment/subscription status.
- Built the full `Notifications` module (Domain/Application/Infrastructure): notify-worthy status
  filtering, endpoint resolution (client default + provider-account override), HMAC signing,
  enqueue-on-event subscribers, synchronous-but-network-free enqueue, a separate delivery handler
  that does the actual HTTP call, exponential backoff/dead-letter, and a manual-retry handler.
- Built the cron-invokable delivery/retry job and its `bin/` entry point, mirroring
  `RetryPendingWebhookEvents`'s established shape.
- Built the admin Notifications screen (`/admin/notifications`) and its manual-retry action,
  wiring the sidebar link and permissions that existed since Phase 27 but pointed nowhere.
- Updated `database-design.md`, `database-diagram.md`, `database-diagram.html`, and `db_explain.md`
  in the same change as the schema change, per the Database Diagram Maintenance Rule.
- Verified the entire pipeline end-to-end with real captured evidence (not just unit tests) — see
  *Tests and Validation*.

## Files Created

**Migrations**
- `src/Database/Migrations/20260917150001_create_provider_account_notification_overrides_table.php`
- `src/Database/Migrations/20260917150002_create_client_notification_logs_table.php`

**Shared — domain-event dispatcher**
- `src/Shared/Application/Events/DomainEventDispatcher.php` — dispatch port.
- `src/Shared/Application/Events/DomainEventSubscriber.php` — subscriber contract.
- `src/Shared/Infrastructure/Events/SynchronousDomainEventDispatcher.php` — in-process
  synchronous implementation; a subscriber that throws is logged via `ErrorLogWriter`
  (`source: 'domain_event'`) and skipped, never propagated.

**Domain events**
- `src/Modules/Payments/Domain/Events/PaymentStatusChanged.php`
- `src/Modules/Subscriptions/Domain/Events/SubscriptionStatusChanged.php`

**Notifications module**
- `src/Modules/Notifications/Domain/ClientNotification.php` — the aggregate (`enqueue`,
  `recordSuccess`, `recordRetry`, `recordDeadLetter`, `retryFromDeadLetter`).
- `src/Modules/Notifications/Domain/ClientNotificationStatus.php`,
  `NotificationTargetType.php`, `NotifyWorthyStatuses.php` (Q5's curated lists),
  `BackoffSchedule.php` (Q4's schedule), `ClientNotificationRepository.php`,
  `ProviderAccountNotificationOverride.php`, `ProviderAccountNotificationOverrideRepository.php`.
- `src/Modules/Notifications/Application/ClientNotificationDirectory.php`,
  `ClientNotificationFilter.php`, `ClientNotificationSendOutcome.php`,
  `ClientNotificationSender.php`, `NotificationEndpointResolver.php` (Q1's hybrid resolution),
  `NotificationSigner.php` (Q3's signing port).
- `src/Modules/Notifications/Application/EnqueueClientNotification/EnqueueClientNotificationHandler.php`
- `src/Modules/Notifications/Application/DeliverClientNotification/DeliverClientNotificationHandler.php`
- `src/Modules/Notifications/Application/RetryClientNotification/{RetryClientNotificationCommand,RetryClientNotificationHandler}.php`
- `src/Modules/Notifications/Application/Subscribers/{EnqueueOnPaymentStatusChanged,EnqueueOnSubscriptionStatusChanged}.php`
- `src/Modules/Notifications/Infrastructure/{PdoClientNotificationRepository,PdoClientNotificationDirectory,PdoProviderAccountNotificationOverrideRepository,HmacNotificationSigner,GuzzleClientNotificationSender,definitions.php}`

**Clients module — the notification-secret port** (kept separate from `ClientDirectory` /
`ClientSnapshot`, which "carries no secrets", mirroring `ProviderAccountCredentials`'s split from
`ProviderAccountDirectory`)
- `src/Modules/Clients/Application/ClientNotificationSecret.php`
- `src/Modules/Clients/Infrastructure/PdoClientNotificationSecret.php`

**Admin — Notifications screen**
- `src/Modules/Admin/Application/Notifications/{NotificationsFilterState,NotificationRow,NotificationsScreenResult,NotificationsScreenHandler}.php`
- `src/Http/Admin/AdminNotificationsAction.php`, `AdminNotificationRetryAction.php`
- `src/Modules/Admin/Views/notifications.html.twig`

**Job**
- `src/Jobs/RetryPendingClientNotifications.php`
- `bin/RetryPendingClientNotifications.php`

**Tests**
- `tests/Unit/Modules/Notifications/Domain/{ClientNotificationTest,NotifyWorthyStatusesTest,BackoffScheduleTest}.php`
- `tests/Unit/Modules/Notifications/Infrastructure/HmacNotificationSignerTest.php`
- `tests/Unit/Modules/Notifications/Application/{NotificationEndpointResolverTest,EnqueueClientNotificationHandlerTest,DeliverClientNotificationHandlerTest,RetryClientNotificationHandlerTest}.php`
- `tests/Unit/Modules/Notifications/Application/Subscribers/{EnqueueOnPaymentStatusChangedTest,EnqueueOnSubscriptionStatusChangedTest}.php`
- `tests/Unit/Modules/Admin/Application/Notifications/NotificationsScreenHandlerTest.php`
- `tests/Unit/Jobs/RetryPendingClientNotificationsTest.php`
- `tests/Integration/ClientNotificationsPersistenceTest.php`
- `tests/Support/{InMemoryClientNotificationRepository,InMemoryClientNotificationDirectory,InMemoryProviderAccountNotificationOverrideRepository,FakeClientNotificationSecret,FakeClientNotificationSender,RecordingDomainEventDispatcher}.php`

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — added `Notifications/Infrastructure/definitions.php` to
  the module list.
- `src/Config/container.php` — bound `DomainEventDispatcher` to `SynchronousDomainEventDispatcher`
  with the two Notifications subscribers as its subscriber list (cross-module, so wired here
  rather than in any one module's own `definitions.php`).
- `src/Config/routes.php` — `GET /admin/notifications`, `POST /admin/notifications/{id}/retry`.
- `src/Modules/Clients/Infrastructure/definitions.php` — bound `ClientNotificationSecret`.
- `src/Public/admin.css` — `.status-pill.status-sent` / `.status-dead_lettered` rules.
- `composer.json` — `notifications:retry-pending` script + description.
- `src/Modules/Payments/Application/RecordProviderTransaction/RecordProviderTransactionHandler.php` —
  raises `PaymentStatusChanged` after a real (non-no-op) transition, once the transaction commits.
- `src/Modules/Payments/Application/ChangePaymentStatus/ChangePaymentStatusHandler.php` — same;
  also gained a `PaymentAttemptRepository` dependency to resolve the producing `providerAccountId`
  (this handler has no provider-account context of its own — it's the "escape hatch" path).
- `src/Modules/Subscriptions/Application/CancelSubscription/CancelSubscriptionHandler.php` —
  raises `SubscriptionStatusChanged`.
- `src/Modules/Subscriptions/Application/RecordSubscriptionPayment/RecordSubscriptionPaymentHandler.php` —
  raises `SubscriptionStatusChanged` for the subscription's own transition (the nested
  `RecordProviderTransactionHandler` calls it makes already raise `PaymentStatusChanged` for the
  renewal payment itself — no duplicate wiring needed there).
- `tests/Integration/MigrationRoundTripTest.php` — added both new tables to the tracked-table list.
- Every test file that directly constructs one of the four modified handlers (19 files) — updated
  to pass a `RecordingDomainEventDispatcher` (and, for `ChangePaymentStatusHandler`, an
  `InMemoryPaymentAttemptRepository`) as the new trailing constructor argument(s).
  `ChangePaymentStatusHandlerTest.php` additionally gained a real assertion that the dispatcher
  received a `PaymentStatusChanged` event with the correct `fromStatus`/`toStatus`.
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`, `db_explain.md` —
  new "Client notifications (Phase 28)" sections; table-count and module-map updates; a flagged
  (not fixed) note about Phase 27's admin tables never having been documented there.
- `.claude/docs/Phases.md` — Phase 28 tracking row.
- `.claude/Changelog.md`, `.claude/PhaseResults/PhaseDecisions.md` — this phase's entries.

## Implementation Details

**Dispatch timing.** Every one of the four handlers dispatches *after* its `Transactions::run()`
closure returns successfully — never from inside the transaction — matching Architecture.md's
explicit rule ("Events are dispatched after the triggering transaction commits").

**"Genuine change" detection.** `Payment::transitionTo()` / `Subscription::transitionTo()` return
`null` both for a real transition *and* for a same-status no-op, with no way to tell them apart
from the return value alone. Each handler now captures `$previousStatus` immediately before
calling `transitionTo()` and compares it to the post-call status; the event is only raised when
they differ. This is also why no DB-level dedup constraint was needed (see *Technical Decisions*).

**Enqueue vs. deliver split.** `EnqueueClientNotificationHandler` (called synchronously from the
dispatcher, inside the original request) does no network I/O — it only resolves the endpoint URL
and inserts a `pending` row with `next_attempt_at = now`. `DeliverClientNotificationHandler` (the
only thing that calls `ClientNotificationSender::send()`) is called exclusively by
`RetryPendingClientNotifications` (cron) and `RetryClientNotificationHandler` (admin manual
retry) — never from inside a webhook/checkout-return/admin request that changed a status. This
directly satisfies CLAUDE.md's "do not block provider webhook responses with long processing."

**Endpoint resolution (Q1).** `NotificationEndpointResolver::resolve(clientId, providerAccountId,
purpose)`: an active `provider_account_notification_overrides` row for `(providerAccountId,
purpose)` wins when present; otherwise `ClientDirectory::findActiveEndpointUrl(clientId,
purpose)` (Phase 6, previously unused). Neither configured → `null`, and the caller simply does
not enqueue — a client-configuration gap, not a Gomrok failure.

**Signing (Q3).** `HmacNotificationSigner::sign()` produces
`t=<unix_ts>,v1=<hex HMAC-SHA256 of "{t}.{raw_json_body}">` using
`ClientNotificationSecret::secretFor()` (reads `clients.notification_signing_secret`, Phase 6,
previously unused). Verified by hand in the end-to-end run (see *Tests and Validation*).

**Backoff (Q4).** `BackoffSchedule::nextAttemptAt(int $failedAttempts, DateTimeImmutable $now)`
maps 1→1m, 2→5m, 3→30m, 4→2h, 5→6h, 6→12h, 7→24h, 8+→24h; `isExhausted(8)` is `true`, at which
point `DeliverClientNotificationHandler` calls `recordDeadLetter()` instead of `recordRetry()`.

**Retry semantics.** `ClientNotification::retryFromDeadLetter()` resets `attemptCount` to `0`
rather than continuing from `8` — a `dead_lettered` row an operator retries gets the *full* 8-step
backoff sequence again, not an immediate re-dead-letter on its very next failure. Verified live
(see below): retrying against a deliberately unreachable URL moved the row back to `pending` with
`attempt_count = 1` and a fresh 1-minute `next_attempt_at`, not back to `dead_lettered`.

**No signing secret.** `DeliverClientNotificationHandler` dead-letters immediately (no HTTP call
attempted) if `ClientNotificationSecret::secretFor()` returns `null` — covered by
`DeliverClientNotificationHandlerTest::noSigningSecretDeadLettersImmediatelyWithoutCallingTheSender`.

**HTTP client.** `GuzzleClientNotificationSender` uses short timeouts (`connect_timeout: 3.0`,
`timeout: 8.0`) since it calls an arbitrary client server, not a trusted payment provider, and
must never hang the delivery/retry job; `http_errors: false` so a non-2xx is read as a normal
response rather than an exception.

## Database Changes

Two new tables — full column/index/FK detail in `.claude/docs/database-design.md` →
"Client notifications (Phase 28)":

- **`provider_account_notification_overrides`** — `id`, `provider_account_id` (FK →
  `provider_accounts.id`, CASCADE), `purpose`, `url`, `is_active`, `created_at`, `updated_at`.
  `UNIQUE (provider_account_id, purpose)`.
- **`client_notification_logs`** — `id`, `client_id` (FK → `clients.id`, CASCADE), `target_type`,
  `target_id` (polymorphic, no FK — same shape as `audit_logs`/`error_logs`), `purpose`,
  `status_value`, `provider_account_id` (FK → `provider_accounts.id`, SET NULL, nullable),
  `endpoint_url`, `payload`, `status`, `attempt_count`, `next_attempt_at`, `last_attempted_at`,
  `last_response_status`, `last_response_body`, `last_error`, `created_at`, `updated_at`.
  Indexes: `(client_id, status, next_attempt_at)` (the retry job's scan), `(target_type,
  target_id)`, `(client_id)`.

Migration round-trip verified: `vendor/bin/phinx rollback` then `vendor/bin/phinx migrate` on
both new migrations individually, and both added to `MigrationRoundTripTest`'s tracked-table list
(exercised by the full rollback-to-empty-and-back cycle, which passed).

## API Changes

No public `/api/v1` changes. Two new admin routes:

- `GET /admin/notifications` — list with `status`/`purpose`/`client_id` filters and pagination.
  Gated on `notifications.view` (both roles).
- `POST /admin/notifications/{notificationId}/retry` — manual retry of a `dead_lettered` row only
  (rejects any other status with `client_notification.not_dead_lettered`). Gated on
  `notifications.retry` (`admin` only, via the existing `.retry`-suffix convention).

## Tests and Validation

**Tests created:** 15 new test files (11 unit, 1 integration, plus test-support doubles) —
enumerated under *Files Created*.

**Tests modified:** 19 existing test files updated to construct the four modified handlers with
their new constructor argument(s); `ChangePaymentStatusHandlerTest.php` gained a real event
assertion; `MigrationRoundTripTest.php` gained the two new tables.

**Full suite, actually executed:**

```
vendor/bin/phpunit
```

Real captured result:
```
Tests: 794, Assertions: 3039, Skipped: 3.
OK, but some tests were skipped!
```
(755 tests before this phase + 39 new; the 3 skips are the pre-existing self-skipping live-provider
tests — Stripe/Mollie/PayPal — unrelated to this phase.)

**Static analysis, actually executed:**
```
vendor/bin/phpstan analyse
```
Real captured result: `[OK] No errors`

**Code style:** `composer cs:fix` run; fixed import ordering in 19 files (no logic changes).

**End-to-end verification against the real DI container** (not test doubles), via a one-off
script (`verify-notifications-flow.php`, not committed — dev-only scratch tooling):

1. Registered `local-dev`'s `payment_status` endpoint at a local echo-receiver
   (`http://127.0.0.1:8097/hook`, a throwaway PHP script logging what it receives).
2. Created a real `Payment` and drove it `created → pending → paid` through the real, container-
   resolved `RecordProviderTransactionHandler`.
3. Confirmed a `client_notification_logs` row was enqueued (`status=pending`, correct
   `status_value=paid`, correct resolved `endpoint_url`) — before any delivery was attempted.
4. Ran the real `RetryPendingClientNotifications` job (`attempted=1`).
5. The echo receiver captured a real HTTP POST with header
   `X-Gomrok-Signature: t=1789687725,v1=9a6199...0040b64` and body
   `{"type":"payment_status","id":3,"status":"paid","occurred_at":"2026-09-17T23:28:45+00:00"}`.
   The signature was independently recomputed by hand
   (`hash_hmac('sha256', "{t}.{body}", 'localdevsigningsecret00000000000000000000')`) and matched
   exactly.
6. The row's final state: `status=sent`, `attempts=1`, `last_response_status=200`.

**Admin screen — real captured screenshots** (Playwright, `tools/screenshots/screenshot.js`, the
same tool used throughout Phase 27), logged in as a real admin user against the real local MySQL:

- `/admin/notifications` showing three seeded rows (one `sent`/green, one `dead_lettered`/red with
  a "1 dead-lettered" badge and a visible "Retry now" button, one `pending`/amber) with correct
  status-pill coloring and filters.
- A real `POST /admin/notifications/3/retry` executed via `curl` with a logged-in session cookie
  against the dead-lettered row (deliberately pointed at an unreachable `https://televika.example/...`
  URL) — real response: `HTTP 302` redirecting with `success=Retried — still failing (status:
  pending).`
- `/admin/notifications` re-screenshotted afterward: the dead-lettered badge is gone, that row now
  reads `pending`, `attempts=1`, `next 2026-09-17 23:31` — the reset-then-reschedule behavior
  working exactly as designed, not merely asserted in a unit test.

All demo/verification data (the throwaway payment, its notification row, the temporary
`http://` endpoint override) was cleaned from the shared local dev database after verification;
three illustrative rows (`sent`/`dead_lettered`/`pending`) were re-seeded through the real
aggregate for the screenshots and were left in place.

## Technical Decisions

Full record with options, recommendations, and the user's actual selections:
`.claude/PhaseResults/PhaseDecisions.md` → "Phase 28 — Client callbacks / outbound notifications".
Summary:

- **Q1 — Callback-URL model:** user-specified hybrid (client-scoped default + optional per-
  provider-account override), not either of the two offered options.
- **Q2 — Trigger mechanism:** wire the `DomainEventDispatcher` (the recommended option).
- **Q3 — Signing scheme:** timestamped HMAC-SHA256, Stripe-style (the recommended option).
- **Q4 — Backoff policy:** exponential, 8 attempts, 1m–24h (the recommended option).
- **Q5 — Notify-worthy statuses:** curated lists, user-specified exact values — overriding the
  simpler "notify on everything" default that was recommended — plus explicit constraints (server-
  to-server framing, same-status/duplicate-webhook idempotency, centralized status lists) that
  shaped the implementation beyond the question itself.

Judgment calls made without a separate question (all low-stakes, reversible, and consistent with
established codebase precedent):

- Refund statuses (`refunded`/`partially_refunded`) route through `EndpointPurpose::PaymentStatus`,
  not the older, still-unused `EndpointPurpose::RefundStatus` — they're `PaymentStatus` values, not
  a separate aggregate.
- No DB-level uniqueness constraint on `(target_type, target_id, status_value)` — checked
  `PaymentStatus::allowedNextStatuses()` and found `Paid ↔ Disputed` can legitimately cycle, which
  a hard constraint would break; relies instead on the domain's own no-op guard plus Phase 25's
  webhook dedup.
- Admin manual retry attempts delivery synchronously (real feedback for the operator) rather than
  just resetting `next_attempt_at` and waiting for the next cron tick.
- `retryFromDeadLetter()` resets `attemptCount` to `0` rather than continuing the count, so a
  manually-retried row gets the full backoff sequence again.

## Problems Encountered

- **Same-status no-op events.** `transitionTo()`'s return value (`?DomainError`, `null` on
  success) doesn't distinguish a genuine transition from a same-status no-op. Solved by comparing
  status before/after the call in every one of the four handlers rather than changing the domain
  method's signature (out of scope, and other callers rely on its current contract).
- **`ChangePaymentStatusHandler` has no provider-account context.** Unlike the other three
  handlers, it's a generic "change to any status" escape hatch called from return-flow
  reconciliation and admin actions, with no `providerAccountId` in scope. Resolved by injecting
  `PaymentAttemptRepository` and reading the payment's latest attempt's `providerAccountId()` —
  `null` if the payment somehow has no attempts yet.
- **19 existing tests directly construct the four modified handlers positionally.** Rather than
  give the new `DomainEventDispatcher` parameter a default value (a shortcut with no precedent
  anywhere else in the codebase, and a real risk of silently swallowing events in production if a
  binding were ever missed), every call site was updated to pass a real
  `RecordingDomainEventDispatcher` test double.
- **`SetClientEndpointHandler` rejects non-`https://` URLs** (correctly, for real client
  callbacks), which blocked using it to point at the local `http://` echo receiver during
  end-to-end verification. Worked around by writing the `client_endpoints` row directly for that
  one-off verification script only — production code path and its own validation were not
  touched or weakened.

## Resolutions

All of the above were resolved within this phase; none carried forward. See *Technical Decisions*
and *Implementation Details* for the specifics of each resolution.

## Deferred Work

- **Real queue/worker.** Both enqueue and delivery still run as plain PHP + cron
  (`RetryPendingClientNotifications` via `bin/RetryPendingClientNotifications.php`), the same
  shape Phase 25 established and explicitly deferred replacing until Phase 29's "Background jobs,
  reconciliation & observability."
- **Admin UI for managing `provider_account_notification_overrides`.** The domain/repository/DI
  layer is complete and tested, but there is no admin screen to create or edit an override —
  CLAUDE.md's Phase 28 scope text didn't call for one explicitly, and building it wasn't necessary
  to satisfy Q1's hybrid design or the phase's core deliverable (the notification pipeline
  itself). A future phase (or a small addition to this one, if requested) can add it to the
  Providers screen alongside provider account management.
- **Phase 27's undocumented admin tables**, discovered while updating the DB docs for this phase —
  flagged in `database-design.md` and the Changelog, not fixed here (not this phase's scope).

## Final Result

Gomrok now tells clients when their payments or subscriptions change status, reliably and
verifiably: a curated set of status transitions (Q5) raises a domain event, which a Notifications
subscriber turns into a durable, signed (Q3), resolvable (Q1) delivery record with no network call
in the triggering request; a cron job delivers it with exponential backoff and eventual dead-
lettering (Q4); and an operator can see the whole history and manually retry a dead-lettered row
from the admin panel. The pipeline was proven end-to-end against the real container, real MySQL,
and a real HTTP receiver — not only asserted in unit tests — and the full suite (794 tests) plus
static analysis are clean. `client_notification_logs` and `provider_account_notification_overrides`
are documented in all three canonical DB docs, in lock-step with the schema.
