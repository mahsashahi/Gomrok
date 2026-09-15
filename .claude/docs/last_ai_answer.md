# Q: Start Phase 25 (Webhooks module)

Built the whole Webhooks module end to end: inbound webhook ingestion, per-provider signature
verification, store-before-process, correct dedup (including Mollie's lack of real
per-occurrence event ids), inline processing, and a cron-invokable retry mechanism. This
completes Phase 25.

## Decisions (Q1–Q5)

- **Q1** — webhooks only ever update an **existing** `Payment`; never create one, and never
  independently confirm a checkout attempt to `Payment`. The recommended option (reusing
  `ReconcileCheckoutStatusHandler` as a third confirmation trigger alongside the return endpoint
  and status poll) was declined in favor of this narrower scope, with a sweep job for stuck
  pre-payment attempts explicitly left as future work.
- **Q2** — the dedup key is `(provider_account_id, event_id, raw_status)`, not `event_id` alone.
  Mollie's `parseWebhook()` returns the payment id itself as `eventId` — identical across every
  status change on that payment — so `raw_status` has to be part of the key or `pending → paid`
  and a later `paid → refunded` would collide and the second one would be silently dropped.
- **Q3** (user-specified, full spec in `PhaseDecisions.md`) — store the raw event first, process
  inline in the same HTTP request immediately after, keep a failed attempt `retry_pending`
  (never lost, never immediately terminal on the first miss), add a cron-invokable
  `webhook:retry-pending` job that reuses the exact same processor as inline handling. Statuses:
  `received` / `processing` / `processed` / `retry_pending` / `failed`. `max_attempts` is a code
  constant (`ProcessWebhookEventHandler::MAX_ATTEMPTS = 5`), not a column.
- **Q4** — the HTTP response to the provider is always `200` once the event is stored and its
  signature verified, regardless of the inline processing outcome — the cron job is the sole
  retry mechanism, never the provider's own at-least-once redelivery.
- **Q5** — `POST /api/v1/webhooks/{provider}/{token}`. This confirmed a path shape that had
  already been hardcoded into Phase 9's `AddProviderAccountEndpointHandler` output — `{provider}`
  is logging-only, `{token}` alone resolves the account.

## What was built

- **`webhook_events`** table (confirmed design before migrating) — stores the raw payload
  (never re-encoded), headers, parsed fields, status, attempt count, and the resolved payment id.
- **`WebhookEvent`** domain aggregate + **`WebhookEventRepository`** port +
  **`PdoWebhookEventRepository`**.
- **`IngestWebhookEventHandler`** — resolves the endpoint token (via a new
  `ProviderAccountDirectory::findByEndpointToken()`), verifies + parses the webhook through the
  adapter (already built in Phases 21–22), stores the row, and calls the processor inline.
- **`ProcessWebhookEventHandler`** — the one processor both inline ingestion and the cron retry
  call, unchanged. Resolves the `GatewayReference` (trying `PaymentIntent` → `CheckoutSession` →
  `Order` → `Transaction`), then delegates the actual state transition to the existing (Phase 20)
  `RecordProviderTransactionHandler` — `PaymentStatus::transitionTo()`'s own guard is what makes
  redelivery and retry safe, not anything webhook-specific. A domain-rule rejection (an illegal
  transition) goes straight to `failed` without consuming retry attempts, since replaying the
  same status can never produce a different outcome.
- **`POST /api/v1/webhooks/{provider}/{token}`** (`WebhooksReceiveAction`) — public, outside the
  authenticated `/api/v1` group.
- **`RetryPendingWebhookEvents`** (`src/Jobs/`) + `bin/RetryPendingWebhookEvents.php` +
  `composer webhook:retry-pending` — the cron-invokable retry, matching Phase 5's
  `PurgeExpiredIdempotencyKeys` "plain invokable until the real job runner (Phase 29) exists"
  pattern exactly.

## Tests

17 new tests: `ProcessWebhookEventHandlerTest` (6), `IngestWebhookEventHandlerTest` (5),
`RetryPendingWebhookEventsTest` (2), `WebhooksReceiveActionTest` (4). Full suite: **578 tests,
1933 assertions**; `composer ci` (CS + PHPStan + tests) clean.

## Documentation

`.claude/docs/Architecture.md` (new Webhooks subsection, module map, corrected several stale
Phase 20/24 notes), `database-design.md`, `database-diagram.md`/`.html`, `db_explain.md` (all
gained a `webhook_events` section), `.claude/Changelog.md`, `.claude/FileIndex.md`,
`.claude/knowledge/Knowledge.md`, `.claude/docs/Phases.md` (Phase 25 now ☑ complete),
`.claude/PhaseResults/PhaseDecisions.md` (Q1–Q5 recorded), and
`.claude/PhaseResults/Phase25Result.md` created recording the full phase.

## What's left

Nothing outstanding for Phase 25 itself. No git commit has been made yet for this work. Deferred
beyond this phase: a sweep job for checkout attempts stuck pre-payment (Q1's narrower scope), and
subscription-related webhook events are stored but can't resolve to anything until Phase 26
builds the `Subscriptions` module. Next up per `Phases.md` is Phase 26.
