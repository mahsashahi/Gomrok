# Phase 25 — Webhooks module

## Execution Summary

- Phase: 25 — Webhooks module
- Start Datetime: 2026-09-14 12:00
- End Datetime: 2026-09-14 19:31
- Estimated Duration: 5–8h
- Actual Duration: N/A (no reliable hands-on clock was kept)
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

Built the whole Webhooks module: inbound provider webhook ingestion, per-provider signature
verification (reusing the `PaymentProviderPort::parseWebhook()` primitives Phases 21–22 already
built), storage-before-processing, a dedup key that correctly handles Mollie's lack of real
per-occurrence event ids, inline processing in the same HTTP request, and a cron-invokable retry
mechanism that reuses the exact same processor. Endpoint-token resolution
(`provider_account_endpoints` + its `token` column) had already been built in Phase 9 with this
phase explicitly in mind, so this phase's own scope was the storage/dedup/processing/retry layer
around already-existing primitives, not the primitives themselves.

## Files Created

- `src/Database/Migrations/20260914090001_create_webhook_events_table.php`
- `src/Modules/Webhooks/Domain/WebhookEvent.php`
- `src/Modules/Webhooks/Domain/WebhookEventStatus.php`
- `src/Modules/Webhooks/Domain/WebhookEventRepository.php`
- `src/Modules/Webhooks/Infrastructure/PdoWebhookEventRepository.php`
- `src/Modules/Webhooks/Infrastructure/definitions.php`
- `src/Modules/Webhooks/Application/IngestWebhookEvent/IngestWebhookEventCommand.php`
- `src/Modules/Webhooks/Application/IngestWebhookEvent/IngestWebhookEventResult.php`
- `src/Modules/Webhooks/Application/IngestWebhookEvent/IngestWebhookEventHandler.php`
- `src/Modules/Webhooks/Application/ProcessWebhookEvent/ProcessWebhookEventResult.php`
- `src/Modules/Webhooks/Application/ProcessWebhookEvent/ProcessWebhookEventHandler.php`
- `src/Http/Api/WebhooksReceiveAction.php`
- `src/Jobs/RetryPendingWebhookEvents.php`
- `bin/RetryPendingWebhookEvents.php`
- `tests/Support/InMemoryWebhookEventRepository.php`
- `tests/Unit/Modules/Webhooks/Application/ProcessWebhookEventHandlerTest.php`
- `tests/Unit/Modules/Webhooks/Application/IngestWebhookEventHandlerTest.php`
- `tests/Unit/Jobs/RetryPendingWebhookEventsTest.php`
- `tests/Unit/Http/WebhooksReceiveActionTest.php`

## Files Modified

- `src/Modules/Providers/Application/ProviderAccountDirectory.php` — new `findByEndpointToken(string $token): ?ProviderAccountSummary`.
- `src/Modules/Providers/Infrastructure/PdoProviderAccountDirectory.php` — implemented it (joins
  `provider_account_endpoints`, `kind = 'webhook'`, `is_active = 1`).
- `src/Bootstrap/ContainerFactory.php` — registered `src/Modules/Webhooks/Infrastructure/definitions.php`.
- `src/Config/routes.php` — `POST /api/v1/webhooks/{provider}/{token}`, registered directly on
  `$app` (public, outside the authenticated `/api/v1` group).
- `composer.json` — `webhook:retry-pending` script + its description line.
- `tests/Support/FakePaymentProviderPort.php` — added `webhookParseResult()`,
  `throwOnParseWebhook()`, `lastWebhook`; `parseWebhook()` now honors them instead of returning a
  fixed value.
- `tests/Support/StubProviderAccountDirectory.php` — added `withWebhookToken()` and
  `findByEndpointToken()`.
- `.claude/docs/Architecture.md` — new "Webhooks (Phase 25 — complete)" subsection; module map
  table updated; "Background jobs" and "Idempotency" cross-cutting rows updated to reflect what's
  actually built; several stale Phase 20/24 "deferred to later phases" notes corrected.
- `.claude/docs/database-design.md` — new "Webhooks (Phase 25)" section (`webhook_events` table +
  processing lifecycle + URL/auth); table-count summary updated to 53; migrations table backfilled
  with four previously-undocumented Phase 24/25 migrations.
- `.claude/docs/database-diagram.md` / `.html` — module map updated (`Webhooks` node now "done");
  new `webhook_events` ER diagram section in both files.
- `.claude/docs/db_explain.md` — new "Webhooks (Phase 25)" narrative section.

## Implementation Details

- **`WebhookEvent`** (Domain aggregate): `receive()` (factory), `markProcessing()`,
  `markProcessed(now, paymentId)`, `markRetryPending(now, errorCode, errorMessage)` (increments
  `attemptCount`), `markFailed(now, errorCode, errorMessage)` (increments `attemptCount`,
  terminal). `payloadArray()` decodes the stored `rawPayload` string on demand.
- **`IngestWebhookEventHandler::handle(IngestWebhookEventCommand)`**: resolves `{token}` via
  `ProviderAccountDirectory::findByEndpointToken()` (404 if unknown); resolves the endpoint's
  decrypted signing secret via `ProviderAccountCredentials::endpointSigningSecret($accountId,
  'webhook')`, resolved fresh on every call (so a rotated secret is picked up immediately);
  builds a `RawWebhook` (headers uppercased via `array_change_key_case`, `Stripe-Signature`
  extracted as `signatureHeader`); calls the resolved adapter's `parseWebhook()`. On
  `ProviderWebhookVerificationFailed`, stores a `WebhookEvent` with `event_id`/`event_type`/
  `raw_status` all `null`, marks it `failed` immediately, and returns `401`. On success, checks
  `WebhookEventRepository::findByDedupKey()` — an existing `processed` row short-circuits as
  `'duplicate'`; an existing not-yet-processed row is reused (no new row created); otherwise a new
  row is inserted with the parsed fields already populated. Always hands off to
  `ProcessWebhookEventHandler::process()` and returns `Result::ok()` regardless of that call's
  outcome.
- **`ProcessWebhookEventHandler::process(WebhookEvent)`**: marks `processing`, saves, then in
  `attempt()`: resolves the `GatewayReference` by trying `GatewayReferenceType::PaymentIntent` →
  `CheckoutSession` → `Order` → `Transaction` in order via
  `GatewayReferenceRepository::findByReference()`; if the matched reference only has a
  `checkoutAttemptId` (no `paymentId`), looks up `PaymentDirectory::findByCheckoutAttemptId()` —
  a `null` result (Payment not yet created) is `retry_pending`, not an error; maps the raw status
  via `ProviderAdapterFactory::for($accountId)->mapProviderStatusToInternalStatus()`; calls
  `RecordProviderTransactionHandler::handle()` with `kind: 'webhook'`; a `Result::err()` from that
  call (a domain-rule rejection, e.g. an illegal transition) is treated as **definitive** —
  straight to `failed`, no retry attempts consumed. Any thrown `Throwable` is caught at the
  `process()` level, logged via `ErrorLogWriter` (`source: 'webhook'`), and treated as
  `retry_pending`/`failed` per the normal attempt-count logic.
- **`RetryPendingWebhookEvents`** (`src/Jobs/`): `__invoke()` calls
  `WebhookEventRepository::findRetryable(100)` and `ProcessWebhookEventHandler::process()` for
  each, returning/logging a `{attempted, processed, retry_pending, failed}` summary.
  `findRetryable()` selects `received`/`retry_pending` rows plus any `processing` row stuck past a
  10-minute staleness threshold (a crash-recovery net for an inline attempt that died mid-flight).
- **`WebhooksReceiveAction`**: reads the raw request body via `(string) $request->getBody()`,
  collects all request headers into an uppercased map, and delegates to
  `IngestWebhookEventHandler`. Maps the result to HTTP status via the standard `DomainError` →
  `JsonResponder::problem()` path; a successful `Result::ok()` is always `200` regardless of the
  inner processing outcome (`processed`/`retry_pending`/`failed`/`duplicate` are all reported in
  the JSON body's `outcome` field, never reflected in the status code).

## Database Changes

One new table, `webhook_events` — see `.claude/docs/database-design.md` → "Webhooks (Phase 25)"
for the full column list. Additive only; no existing table was altered. `UNIQUE
(provider_account_id, event_id, raw_status)`; FKs to `clients`, `provider_accounts`, `payments`
(all `CASCADE`); indexes on `status`, `client_id`, `payment_id`.

## API Changes

- `POST /api/v1/webhooks/{provider}/{token}` (new) — public, outside the authenticated `/api/v1`
  group. Request: raw provider webhook body + headers (no fixed shape — provider-specific, parsed
  by the resolved adapter). Response: `200 {webhook_event_id, outcome}` on any accepted request
  (accepted = token resolved + signature verified), `401` on signature verification failure,
  `404` on an unknown token.

## Tests and Validation

- Tests created: `ProcessWebhookEventHandlerTest` (6 tests — processes an event for an existing
  payment; leaves the event retryable when no gateway reference matches; leaves it retryable when
  the checkout attempt has no payment yet; retrying does not create a new payment; marks the event
  failed once `MAX_ATTEMPTS` is reached; an illegal transition fails immediately without consuming
  retries), `IngestWebhookEventHandlerTest` (5 tests — unknown token is 404; invalid signature is
  rejected and still stored; a valid webhook is stored and processed inline; an event is stored
  even when inline processing fails; a redelivered event with the same status is idempotent),
  `RetryPendingWebhookEventsTest` (2 tests — processes a retry-pending event and updates the
  payment; skips events that are already processed or terminally failed),
  `WebhooksReceiveActionTest` (4 tests — a valid webhook returns 200 and updates the payment; an
  invalid signature returns 401; an unknown token returns 404; a processing failure still returns
  200).
- Tests modified: none directly for this phase's own logic; `StubProviderAccountDirectory` and
  `FakePaymentProviderPort` (test support, not test files) were extended as noted above.
- Commands actually run at the end of this phase:
  - `composer cs` (php-cs-fixer, dry run) → `Found 0 of 777 files that can be fixed.`
  - `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`
  - `vendor/bin/phpunit` → `Tests: 578, Assertions: 1933, Skipped: 39.` (all passing; the 39
    skips are the pre-existing `tests/Integration/*` suite self-skipping because this local
    environment's MySQL/MariaDB user credentials currently don't authenticate — verified directly
    against the running server, unrelated to any code in this phase).

## Technical Decisions

Full verbatim questions/options/selections are in `PhaseResults/PhaseDecisions.md` (Phase 25
Q1–Q5). Summary:

- **Q1** — webhooks only ever update an existing `Payment`; never create one, never independently
  confirm a checkout attempt (diverges from the recommendation of reusing
  `ReconcileCheckoutStatusHandler` as a third trigger).
- **Q2** — dedup key is `(provider_account_id, event_id, raw_status)`, to correctly handle
  Mollie's reuse of the payment id as `event_id` across distinct status changes.
- **Q3** (user-specified) — store first, process inline in the same request, keep a failed
  attempt `retry_pending` rather than immediately terminal, cron-invokable
  `webhook:retry-pending` reusing the exact same processor, `max_attempts` as a code constant.
- **Q4** — always `200` to the provider once stored+verified, regardless of the inline processing
  outcome; the cron job is the sole retry mechanism.
- **Q5** — `/api/v1/webhooks/{provider}/{token}`, confirming the path Phase 9 had already
  hardcoded into `AddProviderAccountEndpointHandler`'s output.

Beyond the five asked questions, one implementation-level judgment call is worth recording: a
domain-rule rejection from `RecordProviderTransactionHandler` (an illegal `PaymentStatus`
transition) is treated as **definitive** rather than transient — it goes straight to `failed`
without consuming `retry_pending` cycles, since replaying the exact same already-parsed status can
never produce a different outcome. This wasn't asked as a separate question because it follows
directly from Q3's already-decided retry semantics ("retry never creates duplicate state
transitions") rather than opening a new fork.

## Problems Encountered

None that required deviating from the plan. One real design puzzle worth recording: the dedup key
(`provider_account_id`, `event_id`, `raw_status`) isn't known until *after* the webhook is parsed,
but the phase's own store-first requirement says the raw payload must be persisted before any
processing happens — a chicken-and-egg sequencing question. Resolved by treating "processing" as
specifically the business-logic mutation step (gateway-reference resolution + the actual status
transition), not the parse/verify step — the row is inserted with the parsed fields already
populated (or all `null` on a verification failure), which is still strictly before any payment
state is ever touched.

## Resolutions

N/A — no problems required a resolution distinct from the design decision described above.

## Deferred Work

- A sweep job for a checkout attempt genuinely stuck pre-payment (no `Payment` ever created) —
  Q1's narrower scope means this isn't solved by the webhook path alone today; it only self-heals
  if the browser-return flow or an authenticated status poll independently creates the `Payment`
  first, at which point the next `webhook:retry-pending` pass picks it up naturally. A dedicated
  sweep was explicitly named as future work during Q1, not built here.
- A dedicated `refunds` table recording a refund's own provider-issued reference — still recorded
  only in `provider_transactions.response_payload` (a pre-existing Phase 24 limitation, not
  addressed by this phase).
- Subscription-related webhook events are stored (and their gateway-reference lookup attempted)
  but nothing exists to resolve them to yet — `Subscriptions` doesn't exist until Phase 26.

## Final Result

Phase 25's full scope is implemented, tested, and documented: `POST /api/v1/webhooks/{provider}/{token}`
stores every inbound event before any processing, verifies the provider's signature, dedupes
correctly even for providers without real per-occurrence event ids (Mollie), processes inline in
the same request, and falls back to a cron-invokable retry (`composer webhook:retry-pending`) that
reuses the identical processor for anything left unresolved — up to a bounded `max_attempts`
before landing in `failed` for admin visibility. The full test suite (578 tests, 1933 assertions)
passes, `composer cs`/`phpstan` are clean, and every decision (Q1–Q5) is recorded in
`PhaseResults/PhaseDecisions.md`. No commit has been made yet for this phase's work.
