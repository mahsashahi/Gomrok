# Changelog

All meaningful changes to Gomrok. Newest first. Each entry: date, summary, files changed,
reason, migration notes (if any), breaking changes (if any).

(Doc file locations have moved twice. Original: project root. 2026-09-06: `Documents/`.
2026-09-07: `.claude/` (this file is now `.claude/Changelog.md`). Older entries name the paths
that were correct when written.)

## 2026-09-14 — Phase 26 complete: Subscriptions module

**Summary.** Built the whole Subscriptions module by reusing the Checkout pipeline (Q1): a
`Subscription` aggregate, its ownership model, `subscription_events`, and
`subscription_payment_links`; a second `Payment`-creation path for renewal charges (Q2, no
checkout attempt precursor); creation, show, and capability-gated cancel HTTP endpoints. Not a
breaking change for existing one-time-payment flows — every schema change is additive/nullable.

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 26 Q1–Q4, plus a mid-phase addendum):
- **Q1** reuse the Checkout pipeline for subscription creation — `CreateCheckoutSubscriptionHandler`
  runs the same `CreateCheckoutAttemptHandler → ResolveCheckoutPricingHandler →
  (ReserveCheckoutVoucherHandler) → SelectCheckoutProviderHandler` steps as the payment flow,
  ending in a new `CreateProviderSubscriptionHandler` instead of `CreateProviderCheckoutHandler`;
  `ReconcileCheckoutStatusHandler` (Phase 24) is extended to also create the `Subscription` once a
  subscription-purchase-type attempt is `Confirmed`; the existing `GET /payments/return` handles
  the redirect back for subscriptions too — no separate return endpoint.
- **Q2** a renewal charge becomes a real `payments` row via a second creation path —
  `payments.checkout_attempt_id` is now nullable; `RecordSubscriptionPaymentHandler` creates the
  `Payment` directly from subscription context, linked via the new `subscription_payment_links`
  table instead of a checkout attempt.
- **Q3** Mollie's renewal automation is deferred to Phase 29 — Mollie's `createSubscription()`
  stays provisional (customer + first payment only, `sequenceType: FIRST`, no real recurring
  mechanism); Stripe's real Subscription resource auto-renews on its own, but wiring Gomrok to
  react to that via webhooks is *also* deferred this phase (see Known Limitations).
- **Q4** `subscriptions.client_user_ref` is `NOT NULL`, unlike `payments.client_user_ref` — the
  one place CLAUDE.md's Subscription Ownership Model requires a mandatory owner.
- **Addendum** (direct user correction, not a Q1–Q4 option): "Make payment_method nullable and
  skip the country column for now" — `subscriptions.payment_method` is nullable, and
  `subscriptions` has **no `country` column at all**; a handler that needs one
  (`RecordSubscriptionPaymentHandler`) reads it from the origin checkout attempt instead.

**Database design confirmed** before migrating: two new tables plus one existing table's schema
change.

**Files created**
- `src/Database/Migrations/20260914120001_create_subscriptions_tables.php` — `subscriptions`,
  `subscription_events`, `subscription_payment_links`.
- `src/Database/Migrations/20260914120002_make_payments_checkout_attempt_id_nullable.php`.
- `src/Database/Migrations/20260914120003_add_subscription_id_to_gateway_references.php`.
- `src/Modules/Subscriptions/Domain/{Subscription,SubscriptionStatus,SubscriptionRepository,SubscriptionEvent,SubscriptionEventRepository,SubscriptionPaymentLink,SubscriptionPaymentLinkRepository}.php`.
- `src/Modules/Subscriptions/Infrastructure/{PdoSubscriptionRepository,PdoSubscriptionEventRepository,PdoSubscriptionPaymentLinkRepository,PdoSubscriptionDirectory,definitions.php}.php`.
- `src/Modules/Subscriptions/Application/{SubscriptionSummary,SubscriptionDirectory,SubscriptionAuditSnapshot,SubscriptionActionContext,ResolveSubscriptionActionContext}.php`.
- `src/Modules/Subscriptions/Application/CreateSubscription/{CreateSubscriptionCommand,CreateSubscriptionResult,CreateSubscriptionHandler}.php`
  — creates the `Subscription`, idempotent by `checkout_attempt_id`; trial terms resolved from
  `PackagePurchaseCapabilityResolver` (Phase 12), never from the request.
- `src/Modules/Subscriptions/Application/CancelSubscription/{CancelSubscriptionCommand,CancelSubscriptionResult,CancelSubscriptionHandler}.php`
  — capability-gated on `Capability::SubscriptionCancel` **and** `instanceof SupportsSubscriptions`
  (Phase 24 Q5b's "both" pattern).
- `src/Modules/Subscriptions/Application/RecordSubscriptionPayment/{RecordSubscriptionPaymentCommand,RecordSubscriptionPaymentResult,RecordSubscriptionPaymentHandler}.php`
  — the Q2 second creation path; idempotent by `providerPaymentReference`; drives the new payment
  through the existing `RecordProviderTransactionHandler` unchanged.
- `src/Modules/Checkout/Application/CreateProviderSubscription/{CreateProviderSubscriptionCommand,CreateProviderSubscriptionResult,CreateProviderSubscriptionHandler}.php`
  — subscription counterpart to `CreateProviderCheckoutHandler`; requires the resolved adapter to
  implement `SupportsSubscriptions`.
- `src/Modules/Checkout/Application/CreateCheckoutSubscription/{CreateCheckoutSubscriptionCommand,CreateCheckoutSubscriptionResult,CreateCheckoutSubscriptionHandler}.php`
  — subscription counterpart to `CreateCheckoutPaymentHandler`.
- `src/Http/Api/{SubscriptionsCreateAction,SubscriptionsShowAction,SubscriptionsCancelAction}.php`.
- Tests: `CreateSubscriptionHandlerTest` (6), `RecordSubscriptionPaymentHandlerTest` (6),
  `CancelSubscriptionHandlerTest` (5), `SubscriptionOwnershipTest` (2),
  `CreateProviderSubscriptionHandlerTest` (6), `CreateCheckoutSubscriptionHandlerTest` (3),
  `SubscriptionsCreateActionTest` (3), `SubscriptionsShowActionTest` (3),
  `SubscriptionsCancelActionTest` (2).
- Test doubles: `tests/Support/{InMemorySubscriptionRepository,InMemorySubscriptionEventRepository,InMemorySubscriptionPaymentLinkRepository,InMemorySubscriptionDirectory}.php`.

**Files modified**
- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/ReconcileCheckoutStatusHandler.php`
  — new `CreateSubscriptionHandler` constructor dependency; calls it after creating a Confirmed
  subscription attempt's first `Payment`.
- `src/Modules/Payments/Domain/GatewayReference.php` — third nullable parent `subscriptionId` +
  new `forSubscription()` named constructor.
- `src/Modules/Payments/Domain/GatewayReferenceRepository.php` /
  `Infrastructure/PdoGatewayReferenceRepository.php` — new `forSubscription(int): array`.
- `src/Modules/Payments/Domain/Payment.php` — `checkoutAttemptId` is `?int` everywhere (Q2).
- `src/Modules/Payments/Infrastructure/PdoPaymentRepository.php`,
  `Application/PaymentSummary.php`, `Infrastructure/PdoPaymentDirectory.php` — ripple from the
  nullable `checkoutAttemptId`.
- `src/Modules/Payments/Application/ResolvePaymentActionContext.php` — `forPayment()` returns
  `null` early for a renewal-originated payment (no checkout attempt to resolve provider context
  from) — a documented, honest Phase 26 limitation, not silently broken behavior.
- `src/Modules/Providers/Application/Adapter/ProviderPaymentStatus.php` — new field
  `?string $subscriptionReference` (Stripe's `mode: subscription` checkout session's own
  `subscription` field).
- `src/Modules/Providers/Infrastructure/Adapter/Stripe/StripeAdapter.php` — `getPaymentStatus()`
  extracts and passes `subscriptionReference`.
- `src/Bootstrap/ContainerFactory.php` — registered the Subscriptions module's `definitions.php`.
- `src/Config/routes.php` — `POST /subscriptions`, `GET /subscriptions/{id}`,
  `POST /subscriptions/{id}/cancel` inside the existing authenticated `/api/v1` group.
- `tests/Support/FakePaymentProviderPort.php` — extended to also implement
  `SupportsSubscriptions` (`createSubscription()`, `getSubscriptionStatus()`,
  `cancelSubscription()`, `mapProviderSubscriptionStatusToInternalStatus()`).
- Existing tests updated for the new `CreateSubscriptionHandler` constructor dependency:
  `ReconcileCheckoutStatusHandlerTest.php`, `PaymentsStatusActionTest.php`,
  `PaymentsReturnActionTest.php`.
- `.claude/docs/Architecture.md`, `database-design.md`, `database-diagram.md`/`.html`,
  `db_explain.md` — the new tables documented, `payments.checkout_attempt_id` and
  `gateway_references.subscription_id` changes reflected, a Subscriptions module section added,
  stale Phase 20/25 "deferred to Phase 26" notes in Architecture.md §13 updated to match reality.

**Database changes.** Two new tables (`subscriptions`, `subscription_events`,
`subscription_payment_links` — three, from one migration), one nullable-column change
(`payments.checkout_attempt_id`), one additive nullable column
(`gateway_references.subscription_id`). No destructive changes — every change is additive or
widens an existing constraint (`NOT NULL` → nullable), never the reverse.

**Tests.** 36 new tests across the nine files above. Full suite: 575 tests, 2064 assertions;
`composer stan` (PHPStan) and `composer cs` (PHP-CS-Fixer) both clean. Migrations were not
re-verified against a live database this session (Docker daemon unavailable locally) — schema
correctness rests on the earlier database-design confirmation and the repository layer's own test
coverage, not a fresh `composer migrate` run.

**Known limitations.** (1) Webhook-driven subscription automation is not wired this phase —
`ProcessWebhookEventHandler` (Phase 25) doesn't resolve `Subscription`-typed gateway references
or call `RecordSubscriptionPaymentHandler`; deferred to Phase 29 alongside Q3's deferred Mollie
renewal scheduler (`CreateSubscriptionHandler` and `RecordSubscriptionPaymentHandler` are built
and fully tested as the reusable units a future trigger will call unchanged). (2) Cancel/refund/
capture don't work on a renewal-originated `Payment` yet. (3) No admin panel views for
subscriptions yet (Phase 27).

## 2026-09-14 — Phase 25 complete: Webhooks module

**Summary.** Built the whole Webhooks module: `webhook_events` storage, per-provider signature
verification (reusing Phase 21–22's `PaymentProviderPort::parseWebhook()`), dedup, inline
processing, and a cron-invokable retry mechanism — `POST /api/v1/webhooks/{provider}/{token}`.
Endpoint-token resolution and signature-verification primitives were already built ahead of
schedule (Phases 9, 21–22); this phase is the storage/dedup/processing/retry machinery around
them.

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 25 Q1–Q5):
- **Q1** webhooks only ever update an **existing** `Payment`, never create one and never
  independently drive a checkout attempt to `Confirmed` — a webhook resolving to a checkout
  attempt with no `Payment` yet is left `retry_pending` rather than reusing
  `ReconcileCheckoutStatusHandler` as a third confirmation trigger (the recommended option).
- **Q2** the dedup key is `(provider_account_id, event_id, raw_status)`, not `event_id` alone —
  Mollie's webhooks reuse the payment id as `event_id` for every status change on that payment,
  so `event_id` alone would silently drop every status change after the first.
- **Q3** (user-specified, full spec recorded verbatim in `PhaseDecisions.md`) — store the raw
  event first, process inline in the same request immediately after, keep a failed attempt
  `retry_pending` (never lose it, never mark permanently failed on the first miss), and add a
  cron-invokable `webhook:retry-pending` job that reuses the exact same processor. Statuses:
  `received` / `processing` / `processed` / `retry_pending` / `failed`. `max_attempts` is a code
  constant, not a column.
- **Q4** the HTTP response to the provider is always `200` once the event is stored and its
  signature verified, regardless of the inline processing outcome — the cron job is the sole
  retry mechanism, never the provider's own redelivery.
- **Q5** `POST /api/v1/webhooks/{provider}/{token}` — confirmed the path shape Phase 9's
  `AddProviderAccountEndpointHandler` had already hardcoded into its output; `{provider}` is
  logging-only, `{token}` alone resolves the account.

**Database design confirmed** before migrating: one new table, `webhook_events`.

**Files created**
- `src/Database/Migrations/20260914090001_create_webhook_events_table.php`.
- `src/Modules/Webhooks/Domain/{WebhookEvent,WebhookEventStatus,WebhookEventRepository}.php`.
- `src/Modules/Webhooks/Infrastructure/{PdoWebhookEventRepository,definitions.php}`.
- `src/Modules/Webhooks/Application/IngestWebhookEvent/{IngestWebhookEventCommand,IngestWebhookEventResult,IngestWebhookEventHandler}.php`
  — resolves the token, verifies + parses the webhook, stores it, and calls the processor inline.
- `src/Modules/Webhooks/Application/ProcessWebhookEvent/{ProcessWebhookEventResult,ProcessWebhookEventHandler}.php`
  — the shared processor both inline ingestion and the cron retry call unchanged; resolves the
  `GatewayReference` (trying `PaymentIntent` → `CheckoutSession` → `Order` → `Transaction`) and
  delegates the actual transition to the existing (Phase 20) `RecordProviderTransactionHandler`.
- `src/Http/Api/WebhooksReceiveAction.php` — the public HTTP entry point.
- `src/Jobs/RetryPendingWebhookEvents.php` + `bin/RetryPendingWebhookEvents.php` — the
  cron-invokable retry, mirroring Phase 5's `PurgeExpiredIdempotencyKeys` "plain invokable until
  the real job runner (Phase 29) exists" pattern.
- Tests: `ProcessWebhookEventHandlerTest` (6), `IngestWebhookEventHandlerTest` (5),
  `RetryPendingWebhookEventsTest` (2), `WebhooksReceiveActionTest` (4).
- Test doubles: `tests/Support/InMemoryWebhookEventRepository.php`; extended
  `FakePaymentProviderPort` (`webhookParseResult()`, `throwOnParseWebhook()`) and
  `StubProviderAccountDirectory` (`withWebhookToken()`, `findByEndpointToken()`).

**Files modified**
- `src/Modules/Providers/Application/ProviderAccountDirectory.php` /
  `Infrastructure/PdoProviderAccountDirectory.php` — new `findByEndpointToken()`, the reverse
  lookup from the opaque `provider_account_endpoints.token` URL segment to the owning account
  (join against `provider_account_endpoints`, `kind = 'webhook'`, `is_active = 1`).
- `src/Bootstrap/ContainerFactory.php` — registered the Webhooks module's `definitions.php`.
- `src/Config/routes.php` — `POST /api/v1/webhooks/{provider}/{token}`, public, outside the
  authenticated `/api/v1` group (same reasoning as `/payments/return`).
- `composer.json` — `webhook:retry-pending` script + description.
- `.claude/docs/Architecture.md`, `database-design.md`, `database-diagram.md`/`.html`,
  `db_explain.md` — `webhook_events` documented; the module map, cross-cutting "Background jobs"
  and "Idempotency" rows, and several stale Phase 20/24 "deferred" notes in Architecture.md §13
  updated to match current reality.

**Database changes.** One additive migration (see above); no destructive changes.

**Tests.** 17 new tests across the four files above. Full suite: 578 tests, 1933 assertions;
`composer ci` (CS + PHPStan + tests) clean. `tests/Integration/*` continues to self-skip in this
environment (pre-existing MySQL/MariaDB credential issue unrelated to this change).

**Known limitations.** No sweep job exists yet for a checkout attempt genuinely stuck
pre-payment (Q1's narrower option, chosen over reusing `ReconcileCheckoutStatusHandler`) — today
it only self-heals if something else (the browser return, an authenticated status poll) later
creates the `Payment`, at which point a `retry_pending` webhook naturally succeeds on its next
cron pass. `Subscriptions` (Phase 26) didn't exist yet at the time this phase was written; as of
Phase 26, `ProcessWebhookEventHandler` still doesn't resolve `Subscription`-typed gateway
references — see Phase 26's own Known Limitations above for why that stayed deferred to Phase 29.

## 2026-09-13 — Phase 24 complete: A/B price-list visitor assignment

**Summary.** Closes out Phase 24 by re-asking the two decisions Phase 15 deferred (Q4/Q5,
persisted here as Q6/Q7) with their full original option lists — per the user's own standing
instruction not to assume the earlier recommendation — then building the deterministic
visitor→price-list bucket-assignment service and wiring it into the two catalogue/pricing read
endpoints.

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 24 Q6/Q7):
- **Q6** (re-ask of Phase 15 Q4): persisted `price_list_assignments`, not stateless recompute —
  first visit buckets and persists; later visits read the stored row; a since-disabled bucket
  reassigns to control on read.
- **Q7** (re-ask of Phase 15 Q5): **both** `GET /api/v1/packages` and `GET /api/v1/pricing/resolve`
  accept a `visitor_ref` and persist the assignment on first sight — diverging from the original
  recommendation of `/pricing/resolve`-only, in favour of symmetry between the two read endpoints.

**Database design confirmed** before migrating: one new table, `price_list_assignments`
(`client_id`, `pricing_group_id`, `visitor_ref_hash`, `price_list_id`, `assigned_at`,
`reassigned_at`, `created_at`/`updated_at`), `UNIQUE (pricing_group_id, visitor_ref_hash)`, FKs to
`clients`/`pricing_groups`/`price_lists`. Purely additive.

**Files created**
- `src/Database/Migrations/20260913090003_create_price_list_assignments_table.php`.
- `src/Modules/Pricing/Domain/PriceListAssignment.php`, `PriceListAssignmentRepository.php`.
- `src/Modules/Pricing/Infrastructure/PdoPriceListAssignmentRepository.php` — `insertOrGetExisting()`
  uses `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`, the standard MySQL
  upsert-race idiom, so a concurrent first-visit race for the same visitor always resolves to one
  authoritative row instead of throwing.
- `src/Modules/Pricing/Application/ResolveVisitorPriceListAssignment.php` — the bucket-assignment
  service: `hexdec(substr(SHA-256(pricing_group_id . ':' . visitor_ref), 0, 8)) %
  count(enabledLists)` over `PriceListRepository::enabledForGroup()` (control first, then by id —
  a stable order); reassigns a since-disabled bucket to control on read; degrades to `null`
  (never throws) when a pricing group has no price list at all.
- Tests: `tests/Support/InMemoryPriceListAssignmentRepository.php`; new cases in
  `PriceCatalogTest`, `PriceResolverTest` (stability, disable-fallback, no-list-at-all
  degradation), `PackagesApiTest` (visitor_ref wiring end-to-end).

**Files modified**
- `src/Modules/Pricing/Application/PriceResolver.php` — gained a `?string $visitorRef` param;
  resolves to a `priceListId` via the new service when no explicit `priceListId` is given.
- `src/Modules/Pricing/Application/PriceCatalog.php` — gained a `?string $visitorRef` param;
  resolves the visitor's bucket **once** per catalogue request (the bucket is per pricing group,
  not per package) and applies it via `PriceListResolver` to every item — previously `/packages`
  never touched `PriceListResolver` at all, so this is also the first time the catalog listing's
  displayed price can reflect an A/B experiment.
- `src/Http/Api/{PackagesAction,PackageDetailAction,PricingResolveAction}.php` — accept an
  optional `visitor_ref` query parameter.
- `src/Modules/Pricing/Infrastructure/definitions.php` — registered
  `PriceListAssignmentRepository`.
- `tests/Unit/Http/PackagesApiTest.php` — swapped in `InMemoryPriceListAssignmentRepository`
  (this functional test builds the real DI container; leaving the new repository unswapped
  would otherwise construct a real `PDO` connection just to satisfy the constructor, even though
  the tests never pass a `visitor_ref` — the same reason `PriceListRepository` was already
  swapped here since Phase 15).
- Every other call site of `PriceResolver`/`PriceCatalog` across the test suite updated for the
  new constructor parameter (no behavior change — all pass `null`/an unused in-memory double).
- `.claude/docs/Architecture.md`, `database-design.md`, `database-diagram.md`/`.html`,
  `db_explain.md` — `price_list_assignments` documented; Phase 24 marked complete.

**Database changes.** One additive migration (see above); no destructive changes.

**Tests.** New coverage across `PriceCatalogTest`, `PriceResolverTest`, `PackagesApiTest`
(11 new test methods total). Full suite: 561 tests, 1870 assertions; `composer ci` (CS + PHPStan +
tests) clean. `tests/Integration/*` continue to self-skip (need a real MySQL connection this
local environment doesn't currently have — pre-existing, unrelated to this change).

**Known limitations.** `visitor_ref` is not wired into `POST /api/v1/payments` or the checkout
pricing pipeline (`ResolveCheckoutPricingHandler`) — out of scope for Q7, which named exactly two
endpoints. A client that wants the checkout price to match what `/packages`/`/pricing/resolve`
displayed for a given visitor must pass the same `visitor_ref` itself if/when that gap is closed
in a later phase.

**This completes Phase 24.** All of Phase 24's scope (creation, return flow, show/status,
cancel/refund/capture, and the A/B visitor-assignment re-ask) is now built and tested. See
`.claude/docs/Phases.md` for the updated status and `.claude/PhaseResults/Phase24Result.md` for
the full phase-completion summary.

## 2026-09-13 — Phase 24 (in progress): cancel/refund/capture

**Summary.** Built the capability-gated `POST /api/v1/payments/{id}/cancel`, `/refund`,
`/capture` endpoints — the remaining action endpoints from Phase 24's original scope (the A/B
price-list re-ask is still pending). Also closed two latent gaps found while building this:
(1) a newly created `Payment` was never driven past its initial `created` status — nothing called
`ChangePaymentStatusHandler` after `CreatePaymentHandler` even though the checkout attempt only
reaches `Confirmed` because the provider already reported the payment paid; (2) Stripe's
PaymentIntent id (and PayPal's capture id, surfaced through the same field) was computed by every
`getPaymentStatus()` call and then discarded — refund/capture/cancel need it and it was never
persisted anywhere.

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 24 Q5/Q5b):
- **Q5** which stored `GatewayReference` a refund/capture/cancel acts on: reference-type-per-action
  — prefer the "deeper" `GatewayReferenceType::PaymentIntent` reference when one was recorded,
  falling back to the original `CheckoutSession` reference (Mollie: one id serves every action).
- **Q5b** capability gating: **both** the resolved `ProviderCapabilityResolver` capability (a
  client/country config can disable a capability the adapter technically supports) and the
  adapter's actual `SupportsRefunds`/`SupportsAuthCapture` implementation as a type-safe guard.

**Files created**
- `src/Modules/Payments/Application/PaymentActionContext.php`,
  `ResolvePaymentActionContext.php` — resolves the adapter/capabilities/reference a post-creation
  payment action needs, from the payment's own frozen checkout-attempt routing decision.
- `src/Modules/Payments/Application/CancelPayment/{CancelPaymentCommand,CancelPaymentResult,CancelPaymentHandler}.php`,
  `RefundPayment/{RefundPaymentCommand,RefundPaymentResult,RefundPaymentHandler}.php`,
  `CapturePayment/{CapturePaymentCommand,CapturePaymentResult,CapturePaymentHandler}.php` — each
  validates the payment's current status, resolves the provider context, checks capability, calls
  the adapter, then delegates the actual state change to the existing (Phase 20)
  `RecordProviderTransactionHandler` rather than duplicating its persistence/audit logic.
- `src/Http/Api/{PaymentsCancelAction,PaymentsRefundAction,PaymentsCaptureAction}.php` — `{id}` is
  the checkout attempt id, matching every other `/api/v1/payments/{id}*` route.
- Tests: `CapturePaymentHandlerTest` (5), `RefundPaymentHandlerTest` (7), `CancelPaymentHandlerTest`
  (3), `PaymentsCancelActionTest` (2), `PaymentsRefundActionTest` (2), `PaymentsCaptureActionTest`
  (2).

**Files modified**
- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/ReconcileCheckoutStatusHandler.php` —
  now takes a `ChangePaymentStatusHandler` dependency and, after creating a `Payment`, transitions
  it `created → pending → paid`; persists a second `GatewayReference::forPayment(...,
  GatewayReferenceType::PaymentIntent, ...)` when `ProviderPaymentStatus::$paymentIntentReference`
  is non-null.
- `src/Config/routes.php` — three new POST routes inside the `/api/v1` group.
- `tests/Support/FakePaymentProviderPort.php` — now implements `SupportsRefunds`/
  `SupportsAuthCapture` (`refundPayment`/`authorizePayment`/`capturePayment`/`cancelPayment`),
  gained a configurable `paymentIntentReference()`, `refundResult()`/`captureResult()`, and
  `throwOnRefund()`/`throwOnCapture()`; `mapProviderStatusToInternalStatus()` now actually maps
  instead of always returning `Pending` (unused by any existing assertion, so this is safe).
- `tests/Unit/Http/{PaymentsReturnActionTest,PaymentsStatusActionTest}.php`,
  `tests/Unit/Modules/Checkout/Application/ReconcileCheckoutStatusHandlerTest.php` — updated for
  `ReconcileCheckoutStatusHandler`'s new constructor parameter; the latter also asserts the
  created `Payment` ends at `PaymentStatus::Paid`.
- `.claude/docs/Architecture.md` §8 — the "Payment creation & the return flow" subsection extended
  with the cancel/refund/capture design and the two fixed gaps.

**No database changes** (the `GatewayReferenceType::PaymentIntent` case already existed).

**Tests.** 21 new tests across the six files above. Full suite green at the time of this entry:
555 tests, 1840 assertions; `composer ci` (CS + PHPStan + tests) clean.

**Known limitation.** A refund's own provider-issued reference (e.g. Stripe/Mollie/PayPal's
refund id) is recorded only in `provider_transactions.response_payload`, not as its own
`GatewayReference` row — a dedicated `refunds` table (CLAUDE.md's Required Database Concepts list)
is out of scope for this slice and needs its own database-design confirmation first.

**No breaking changes.**

## 2026-09-13 — Phase 24 (in progress): payment creation flow, provider return flow

**Summary.** Built the core of `POST /api/v1/payments`: authenticate → create a checkout attempt →
resolve pricing → (reserve a voucher, if a code was supplied) → select a provider → create the
provider-hosted checkout session → return a redirect URL. Also built the provider return flow
(`GET /payments/return`, public) and the two read endpoints (`GET /api/v1/payments/{id}`,
`/status`). Cancel/refund/capture and the deferred Phase 15 A/B visitor-assignment re-ask are not
built yet — the phase stays **in progress**.

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 24 Q1–Q4):
- **Q1** `gateway_references` gets both a nullable `checkout_attempt_id` and a nullable
  `payment_id` column (exactly one set per row) instead of a separate pre-payment table, since a
  provider checkout session must be recorded for reverse lookup before a `payments` row can exist.
- **Q2** Gomrok owns the return URL — the provider redirects back to Gomrok's own
  `GET /payments/return` first, which re-verifies the real status with the provider (the return
  hit itself is never treated as proof of payment) before redirecting the customer onward.
- **Q3** the onward redirect target is a per-client, admin-configured URL
  (`EndpointPurpose::CheckoutSuccess`/`CheckoutCancel`), never a client-supplied parameter.
- **Q4** the return token format was dictated exactly by the user, not chosen from options:
  `return_token={checkout_attempt_id}_{hash}` (exactly two `_`-separated parts),
  `hash = HMAC_SHA256(checkout_attempt_id, "gomrokimo")`, verified with `hash_equals()`, only the
  hash persisted (`checkout_attempts.hash_return_token`). The secret is intentionally hardcoded
  for now (`Settings::$checkoutReturnTokenSecret`) — moving it to environment config is explicitly
  deferred by the user's own instruction, not an oversight.

**Files created**
- `src/Modules/Checkout/Domain/CheckoutReturnToken.php` — HMAC-SHA256 issue/parse/verify value object.
- `src/Modules/Checkout/Application/CheckoutPayableAmount.php`,
  `ResolveCheckoutPayableAmount.php` — shared payable-amount resolution (pricing snapshot,
  overridden by a reserved voucher's payable amount).
- `src/Modules/Checkout/Application/CreateProviderCheckout/{Command,Result,Handler}.php` — calls
  the real provider adapter, builds the Gomrok-owned return URL, records the
  `GatewayReference::forCheckoutAttempt()`, transitions to `ProviderCheckoutCreated`.
- `src/Modules/Checkout/Application/CreateCheckoutPayment/{Command,Result,Handler}.php` — the
  `POST /api/v1/payments` pipeline orchestrator.
- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/{Result,Handler}.php` — shared core:
  calls the provider, transitions the attempt, creates the `Payment` when confirmed. Reused by
  both the public return endpoint and the authenticated status poll.
- `src/Modules/Checkout/Application/ConfirmCheckoutReturn/{Command,Result,Handler}.php` — verifies
  the return token, delegates to `ReconcileCheckoutStatusHandler`, resolves the client redirect URL.
- `src/Http/Api/Payments{Create,Return,Show,Status}Action.php` — the four new HTTP actions.
- `src/Database/Migrations/20260913090001_add_checkout_attempt_id_to_gateway_references.php`,
  `20260913090002_add_hash_return_token_to_checkout_attempts.php`.
- Tests: `CheckoutReturnTokenTest`, `CreateProviderCheckoutHandlerTest`,
  `CreateCheckoutPaymentHandlerTest`, `ReconcileCheckoutStatusHandlerTest`,
  `PaymentsCreateActionTest`, `PaymentsReturnActionTest`, `PaymentsShowActionTest`,
  `PaymentsStatusActionTest`.
- Test doubles: `tests/Support/InMemoryCheckoutAttemptDirectory.php`,
  `InMemoryPaymentDirectory.php`, `FakePaymentProviderPort.php`, `StubProviderAdapterFactory.php`.

**Files modified**
- `src/Modules/Payments/Domain/GatewayReference.php` / `GatewayReferenceRepository.php` /
  `Infrastructure/PdoGatewayReferenceRepository.php` — dual-parent columns, `forCheckoutAttempt()`
  read method.
- `src/Modules/Checkout/Domain/CheckoutAttempt.php` / `Infrastructure/PdoCheckoutAttemptRepository.php`
  — `hashReturnToken`, `issueReturnToken()`.
- `src/Modules/Checkout/Application/CheckoutAttemptDirectory.php` /
  `Infrastructure/PdoCheckoutAttemptDirectory.php` — `findById()`.
- `src/Modules/Clients/Domain/EndpointPurpose.php` — `CheckoutSuccess`/`CheckoutCancel` cases.
- `src/Modules/Clients/Application/ClientDirectory.php` / `Infrastructure/PdoClientDirectory.php`
  — `findActiveEndpointUrl()`.
- `src/Modules/Payments/Application/CreatePayment/CreatePaymentHandler.php` — refactored to use
  `ResolveCheckoutPayableAmount` instead of duplicating the pricing/voucher lookup.
- `src/Shared/Domain/ErrorType.php` / `DomainError.php` — new `UpstreamFailure` (HTTP 502), for
  provider-adapter failures during checkout creation.
- `src/Config/Settings.php`, `.env.example` — `appBaseUrl` (`APP_BASE_URL`),
  `checkoutReturnTokenSecret` (hardcoded `'gomrokimo'`, not env-backed yet — intentional).
- `src/Config/routes.php` — `GET /payments/return` outside the auth group; the four new routes
  inside `/api/v1`.
- `src/Shared/Http/ClientContext.php` — `keyMode()`.
- `.claude/docs/Architecture.md` §8 — new "Payment creation & the return flow" subsection;
  `.claude/docs/Phases.md` Phase 24 status/body updated to reflect what's actually built vs.
  pending; `.claude/docs/database-design.md` / `database-diagram.md` / `database-diagram.html` /
  `db_explain.md` — `gateway_references.checkout_attempt_id` documented (the `hash_return_token`
  column was already covered by the Checkout table entry's general shape).

**Database changes.** Two additive migrations (see above); no destructive changes, no renames.

**Tests.** `CheckoutReturnTokenTest` (11), `CreateProviderCheckoutHandlerTest` (6),
`CreateCheckoutPaymentHandlerTest` (4), `ReconcileCheckoutStatusHandlerTest` (5),
`PaymentsCreateActionTest` (3), `PaymentsReturnActionTest` (4), `PaymentsShowActionTest` (2),
`PaymentsStatusActionTest` (2), plus `CreatePaymentHandlerTest` updated for the refactored
constructor. Run with `vendor/bin/phpunit`. Full suite green at the time of this entry: 495 tests,
1778 assertions; `composer ci` (CS + PHPStan + tests) clean.

**Known limitation.** `CreateCheckoutPaymentHandler`'s pipeline is idempotent up through
`ProviderSelected` via each sub-handler's own domain-key idempotency, but not idempotent past that
point on a bare retry (a second full run fails with `checkout_attempt.provider_not_selected`) — the
HTTP `Idempotency-Key` header is the actual defense for the whole pipeline.

**No breaking changes.**

## 2026-09-13 — Phase 23: Ziraat adapter deferred

**Summary.** Decided not to build `ZiraatAdapter` this phase. There are no verified Ziraat sandbox
credentials, no confirmed-current official integration documentation, and no confirmed merchant
configuration available — building a "best-effort" bank-hosted POS protocol would mean guessing
request fields, a hash/signature format, and callback format, and risking wrong technical claims
presented as verified. **Ziraat integration is deferred until official documentation and
credentials are available.** This does not block Stripe/Mollie/PayPal (Phases 21–22, complete and
unaffected) or the generic provider adapter architecture, which already supports adding Ziraat
later with one more `match` arm plus a `ZiraatAdapter` class. No Ziraat-specific tests were added.
Reason: Phase 23 Q1 (`PhaseResults/PhaseDecisions.md`) — user-directed deferral.

**Files modified**
- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php` — no behavior change
  (a `'ziraat'` account already fell through to the `default` arm and threw
  `UnsupportedProviderType`); added a class-docblock note and an inline comment on the `match`
  block explicitly documenting the deferral with the required sentence above.
- `src/Database/Seeds/ProviderTypesSeeder.php` — added a matching docblock note; the seeded
  `ziraat` row itself is unchanged (still needed by country-routing/capability resolution).
- `.claude/docs/Phases.md` — Phase 23 renamed "Ziraat adapter (deferred)" in the tracking table
  and its own section rewritten to record the deferral, original scope, and why exit criteria
  aren't met yet.
- `.claude/docs/Architecture.md` §8 — the `ZiraatAdapter` forward-note rewritten to describe the
  deferral instead of an expected Phase 23 build; §12's testing-approach note updated (no more "a
  Ziraat stub" — there is no stub, just a documented gap).
- `.claude/knowledge/Knowledge.md` — new "Ziraat adapter — deferred" section.
- `.claude/PhaseResults/PhaseDecisions.md` — new Phase 23 Q1 recording the user's direct
  instruction (not a multiple-choice selection — the user rejected the offered options and gave
  an explicit directive instead).

**No database changes. No new tests** (none were needed — nothing new was built).

## 2026-09-12 — Phase 22 complete: PayPal adapter

**Summary.** The third real provider on the Phase 21 port, completing Phase 22. New
`PayPalAdapter` (`Modules\Providers\Infrastructure\Adapter\PayPal\`) implements the core
`PaymentProviderPort` + `SupportsAuthCapture` + `SupportsRefunds` — not `SupportsSubscriptions`
(PayPal needs a persisted Billing "Plan" resource ahead of time, unlike Stripe/Mollie's ad-hoc
pricing; deferred, Q8). Talks to PayPal's REST API directly over `guzzlehttp/guzzle` (Q2, no SDK),
fetching a fresh OAuth2 client-credentials token per call (no caching). `PayPalStatusMapper` is a
pure, dependency-free status translator covering PayPal's combined Order/Authorization/Capture
vocabulary. `DefaultProviderAdapterFactory` gained a `'paypal'` match arm that decodes the
account's `{client_id, client_secret}` JSON secret (Q4) and picks the sandbox-vs-live host from
the account's `mode` (Q4/Q7 area — PayPal is the first provider where the factory reads `mode` for
anything).

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 22 Q7–Q8, discovered mid-implementation):
**Q7** PayPal's Orders API is a real two-step redirect flow even for immediate capture —
`capturePayment()` inspects the order's own `intent` and performs the real dance that intent
needs (`/capture` directly for `CAPTURE`; `/authorize` then `/authorizations/{id}/capture` for
`AUTHORIZE`), so callers always call the same one method regardless of which flow created the
order · **Q8** PayPal subscriptions deferred this phase — `PayPalAdapter` does not implement
`SupportsSubscriptions` despite the seeded capability data, pending a decision on
pre-provisioning/caching PayPal Billing Plans. **No database changes.**

**Files created**
- `src/Modules/Providers/Infrastructure/Adapter/PayPal/{PayPalAdapter,PayPalStatusMapper}.php`.
- `bin/{PayPalCreateCheckoutSession,PayPalGetPaymentStatus}.php` — manual verification CLIs,
  mirroring the Stripe/Mollie ones.
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/PayPal/{PayPalAdapterTest,
  PayPalStatusMapperTest}.php` (15 + 17 tests); `tests/Integration/PayPalAdapterLiveTest.php`
  (self-skips without real `PAYPAL_TEST_CLIENT_ID`/`PAYPAL_TEST_CLIENT_SECRET`).

**Files modified**
- `composer.json` — added `paypal:create-checkout-session`/`paypal:get-payment-status` scripts +
  descriptions (no new PHP dependency — `guzzlehttp/guzzle` was already added for Mollie).
- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php` — `'paypal'` match arm
  + a private `buildPayPalAdapter()` helper (JSON credential decode, sandbox/live host selection).
- `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php` — added
  PayPal success + malformed-credentials cases.
- `.env.example` — documented the optional `PAYPAL_TEST_CLIENT_ID`/`PAYPAL_TEST_CLIENT_SECRET`.
- Documentation kept in lock-step: `.claude/docs/Phases.md` (row 22 → ☑ complete, "as built"
  section rewritten for both providers), `.claude/docs/Architecture.md` §8 (PayPal's as-built
  shape), `.claude/docs/Commands.md` (new `paypal:*` CLI docs), `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md` (new "PayPal adapter" section — the two-step capture dance, the
  capture-id-not-order-id refund reference, no SDK/no token caching, per-environment hostname, the
  deferred-subscriptions gap, and a Guzzle-testing gotcha around `Middleware::history()`'s generics
  fighting PHPStan's strict rules). `.claude/PhaseResults/Phase22Result.md` (new, this phase's
  final result file — created now that both Mollie and PayPal are done).

**Tests.** `composer ci` — CS clean, PHPStan clean, **458 tests / 1683 assertions, all passing**
(up from 424 after the Mollie half); `composer test:integration` — 39 skipped (up from 38 — the
new PayPal live test self-skips, same as every MySQL-dependent integration test without a
database).

## 2026-09-12 — Phase 22 (in progress): Mollie adapter

**Summary.** The second real provider on the Phase 21 port, plus two additive DTO changes decided
for the whole phase. New `MollieAdapter` (`Modules\Providers\Infrastructure\Adapter\Mollie\`)
implements the core `PaymentProviderPort` + `SupportsRefunds` + `SupportsSubscriptions` +
`SupportsManualPolling` (not `SupportsCustomerPortal` — see correction below), using the new
`mollie/mollie-api-php` SDK (+ `guzzlehttp/guzzle` as its PSR-18 transport) — used only inside
this class, per Hexagonal Architecture Rule 5. `MollieStatusMapper` is a pure, dependency-free
status translator mirroring `StripeStatusMapper`'s "unrecognised status never falsely resolves"
rule. `DefaultProviderAdapterFactory` gained a `'mollie'` match arm.

**Data correction.** The Phase 10 seed (`ProviderTypeDeclarations.json`) declared Mollie as
having `customer_portal` — incorrect; Mollie has no hosted self-service billing portal product
like Stripe's Billing Portal. Removed from the seed and from `InMemoryProviderTypeDeclarations`
(which also gained the `manual_status_polling` capability it was missing for Mollie, matching the
real seed).

**Decisions** (`PhaseResults/PhaseDecisions.md` Phase 22 Q1–Q6): **Q1** Mollie via the official
`mollie/mollie-api-php` SDK · **Q2** PayPal via raw REST over a shared HTTP client, not deferred
here (PayPal adapter is still to come) · **Q3** `CreatePaymentCommand` gained an additive,
optional `paymentMethod` field so Mollie's Checkout can be locked to the method Gomrok's routing
already resolved (`null` = no restriction, other adapters ignore it) · **Q4** PayPal's future
client_id/client_secret pair will pack into the existing single `secret_ciphertext` column as one
JSON value — no schema change · **Q5** `RawWebhook` gained an additive `headers` bag for
providers needing more than one signature header (PayPal); Mollie uses neither field — it has no
webhook signature at all, so `verifyWebhookSignature()`/`parseWebhook()` re-fetch the payment by
the id embedded in the payload · **Q6** (discovered mid-implementation) Mollie has no single-call
subscription flow — `createSubscription()` performs only the first-payment/mandate step and
returns that payment's id/checkout URL; the real Mollie Subscription resource is deferred to
Phase 25's webhook processing, once the mandate is confirmed. **No database changes.**

**Files created**
- `src/Modules/Providers/Infrastructure/Adapter/Mollie/{MollieAdapter,MollieStatusMapper}.php`.
- `bin/{MollieCreateCheckoutSession,MollieGetPaymentStatus}.php` — manual verification CLIs,
  mirroring the Stripe ones.
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/Mollie/{MollieAdapterTest,
  MollieStatusMapperTest}.php`; `tests/Integration/MollieAdapterLiveTest.php` (self-skips without
  a real `MOLLIE_TEST_API_KEY`).

**Files modified**
- `composer.json`/`composer.lock` — added `mollie/mollie-api-php:^3.0` (resolved v3.14.0) and
  `guzzlehttp/guzzle:^7.9` (resolved 7.15.5); added `mollie:create-checkout-session` /
  `mollie:get-payment-status` scripts + descriptions.
- `src/Modules/Providers/Application/Adapter/CreatePaymentCommand.php` — additive `paymentMethod`
  field (Q3).
- `src/Modules/Providers/Application/Adapter/RawWebhook.php` — additive `headers` field (Q5).
- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php` — `'mollie'` match arm.
- `src/Database/Seeds/data/ProviderTypeDeclarations.json` — removed Mollie's incorrect
  `customer_portal` capability.
- `tests/Support/InMemoryProviderTypeDeclarations.php` — same correction, plus added the
  `manual_status_polling` capability Mollie's real seed already had.
- `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php` — added a
  Mollie case.
- `.env.example` — documented the optional `MOLLIE_TEST_API_KEY`.

**Tests.** `composer ci` — CS clean, PHPStan clean, **424 tests / 1593 assertions, all passing**
(up from 401); `composer test:integration` — 38 skipped (self-skip, no MySQL/no Mollie key), up
from 37. Run: `vendor/bin/phpunit tests/Unit/Modules/Providers/Infrastructure/Adapter/Mollie
tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php`.

**Status.** Phase 22 is not complete — the PayPal adapter is still outstanding. No
`Phase22Result.md` yet; this entry covers only the Mollie half.

## 2026-09-11 — Phase 21: Provider adapter port & Stripe adapter

**Summary.** The one interface every provider implements, plus the first real provider. New
`Modules\Providers\Application\Adapter\` namespace: the core `PaymentProviderPort`
(`createPayment`, `getPaymentStatus`, `verifyWebhookSignature`, `parseWebhook`,
`mapProviderStatusToInternalStatus`, `getCapabilities`) plus 5 optional capability interfaces
(`SupportsSubscriptions`, `SupportsRefunds`, `SupportsAuthCapture`, `SupportsCustomerPortal`,
`SupportsManualPolling`) — the hybrid shape already decided in Phase 1 Q5, now real code. New
`StripeAdapter` (`Modules\Providers\Infrastructure\Adapter\Stripe\`) implements the core + 4
capability interfaces using the new `stripe/stripe-php` SDK dependency — the SDK is used only
here, per Hexagonal Architecture Rule 5. A new `DefaultProviderAdapterFactory` resolves a
`provider_account_id` to a fresh, credentialed adapter instance. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 21 Q1–Q5): **Q1** one core `createPayment()` hosted-flow
method (CLAUDE.md's `createCheckoutSession` is the same method under Stripe's own product name)
· **Q2** adapters throw a typed `ProviderAdapterException`, matching the Phase 3 Q3 error model ·
**Q3** raw `int` minor units + `string` currency in port DTOs, matching `Payment` · **Q4**
`ProviderAdapterFactory::for($providerAccountId)` · **Q5** standalone adapter + CLI + tests only
this phase — wiring into the Payments module waits for Phase 24. **No database changes** (added
`ProviderAccountDirectory::findById()`, an additive Application-port method the factory needs).

**Files created**
- `src/Modules/Providers/Application/Adapter/{PaymentProviderPort,SupportsSubscriptions,
  SupportsRefunds,SupportsAuthCapture,SupportsCustomerPortal,SupportsManualPolling,
  ProviderAdapterFactory}.php` — the port interfaces.
- `src/Modules/Providers/Application/Adapter/{CreatePaymentCommand,ProviderPaymentResult,
  ProviderPaymentStatus,CreateSubscriptionCommand,ProviderSubscriptionResult,
  ProviderSubscriptionStatus,ProviderRefundResult,ProviderBillingPortalSession,RawWebhook,
  ParsedWebhookEvent}.php` — the port DTOs.
- `src/Modules/Providers/Application/Adapter/{ProviderAdapterException,ProviderRequestFailed,
  ProviderAuthenticationFailed,ProviderWebhookVerificationFailed,UnsupportedProviderType}.php` —
  the exception hierarchy.
- `src/Modules/Providers/Infrastructure/Adapter/Stripe/{StripeAdapter,StripeStatusMapper}.php`.
- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php`.
- CLI: `bin/{StripeCreateCheckoutSession,StripeGetPaymentStatus}.php` +
  `composer stripe:create-checkout-session|get-payment-status`.
- Tests: `tests/Unit/Modules/Providers/Infrastructure/Adapter/Stripe/{StripeStatusMapperTest,
  StripeAdapterTest}.php`, `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php`,
  `tests/Integration/StripeAdapterLiveTest.php` (real Stripe test-mode call, self-skips without
  `STRIPE_TEST_SECRET_KEY`); `tests/Support/{FakeStripeHttpClient,StubProviderAccountCredentials}.php`.
- `.claude/PhaseResults/Phase21Result.md`.

**Files changed**
- `composer.json` — added `stripe/stripe-php:^17.0`; added `stripe:*` scripts + descriptions;
  `composer.lock` refreshed.
- `src/Modules/Providers/Application/ProviderAccountDirectory.php` — gained `findById(int $id):
  ?ProviderAccountSummary` (additive); implemented in `PdoProviderAccountDirectory` and
  `tests/Support/StubProviderAccountDirectory.php`.
- `src/Modules/Providers/Infrastructure/definitions.php` — wired `ProviderAdapterFactory` to
  `DefaultProviderAdapterFactory`.
- `.env.example` — documented the optional `STRIPE_TEST_SECRET_KEY`.
- DB docs (`database-design.md` — table-count summary only, no new tables; `database-diagram.md`/
  `.html` and `db_explain.md` unchanged — no schema change this phase); `Architecture.md` (§3, §8
  rewritten from sketch to as-built, §13 deferred); `Phases.md` (row 21 → ☑, as-built scope);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D24).

**Reason.** Phase 21 of the 30-phase plan.

**Migration notes.** None — no schema change.

**Breaking changes.** None. `ProviderAccountDirectory::findById()` is an additive interface
method; both existing implementers (production and test double) were updated in the same change.

## 2026-09-11 — Phase 20: Payments module: aggregate & lifecycle

**Summary.** The payment record and its internal status state machine. New `Payments` module:
`payments` is created from exactly one confirmed `checkout_attempts` row (`checkout_attempt_id`
required `UNIQUE` FK, realizing the Phase 18 `commercialSnapshot()`/`converted_to_payment`
hand-off), with `amount_minor` frozen at creation from the checkout attempt's pricing/voucher
decision snapshots. `PaymentStatus` is an explicit allowed-next-statuses graph per status (not a
rank) — `paid` branches to `refunded`/`partially_refunded`/`disputed`, `disputed` resolves to
`chargeback` or back to `paid`. `payment_attempts` → `provider_transactions` is a three-tier
model (one "try" per attempt, one immutable raw call/response per transaction);
`provider_customers` (durable customer identity) and `gateway_references` (generic
provider-agnostic reverse lookup) round out the schema. No real provider adapter exists yet
(Phase 21+) — every status transition this phase is caller-supplied. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 20 Q1–Q5): **Q1** payment requires a confirmed checkout
attempt · **Q2** explicit allowed-transitions graph (the first answer, "no rule engine yet," was
flagged as conflicting with this phase's own exit criterion and replaced with the graph before
implementation) · **Q3** three-tier `payments`→`payment_attempts`→`provider_transactions` ·
**Q4** generic `gateway_references` + separate `provider_customers` · **Q5** one handler per
step + `payment:*` CLI. **Schema confirmed by the user.**

**Files created**
- Migration `20260911180001_create_payment_tables.php` → `CreatePaymentTables` (5 tables).
- Payments module: `Domain/{PaymentStatus,Payment,PaymentRepository,PaymentAttemptStatus,
  PaymentAttempt,PaymentAttemptRepository,ProviderTransaction,ProviderTransactionRepository,
  ProviderCustomer,ProviderCustomerRepository,GatewayReferenceType,GatewayReference,
  GatewayReferenceRepository}.php`; `Application/{PaymentAuditSnapshot,PaymentSummary,
  PaymentDirectory}.php`; `Application/CreatePayment/`, `Application/RecordProviderTransaction/`,
  `Application/ChangePaymentStatus/`, `Application/LinkProviderCustomer/` (Command/Result/Handler
  each); `Infrastructure/{PdoPaymentRepository,PdoPaymentAttemptRepository,
  PdoProviderTransactionRepository,PdoProviderCustomerRepository,PdoGatewayReferenceRepository,
  PdoPaymentDirectory,definitions}.php`.
- CLI: `bin/{CreatePayment,RecordProviderTransaction,SetPaymentStatus,LinkProviderCustomer,
  ListPayments}.php` + `composer payment:create|record-transaction|set-status|link-customer|list`.
- Tests: 5 new in-memory test doubles (`tests/Support/InMemory{Payment,PaymentAttempt,
  ProviderTransaction,ProviderCustomer,GatewayReference}Repository.php`);
  `tests/Unit/Modules/Payments/Domain/{PaymentStatusTest,PaymentTest,PaymentAttemptTest}.php`;
  `tests/Unit/Modules/Payments/Application/{CreatePaymentHandlerTest,
  RecordProviderTransactionHandlerTest,ChangePaymentStatusHandlerTest,
  LinkProviderCustomerHandlerTest}.php`; `tests/Integration/PaymentPersistenceTest.php`
  (real-MySQL round trip, CI-only).
- `.claude/PhaseResults/Phase20Result.md`.

**Files changed**
- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` gained
  `Payments/Infrastructure/definitions.php`.
- `composer.json` / `composer.lock`.
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables added.
- DB docs (`database-design.md` → 51 tables + new "Payments — aggregate & lifecycle (Phase 20)"
  section, `database-diagram.md` + `.html` 17/17 mermaid, `db_explain.md`); `Architecture.md`
  (§3, new §8 Payments subsection, §9 pipeline, §13 deferred); `Phases.md` (row 20 → ☑, as-built
  scope); `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D23).
- Minor doc-accuracy fix noticed in passing: `checkout:*` CLI script names were mistakenly
  written as `checkout:create`/`checkout:list-attempts` in several Phase 19-and-earlier "live"
  docs (`FileIndex.md`, `Commands.md`, `Phases.md`); corrected to the actual `composer.json`
  names, `checkout:start`/`checkout:list`. Historical entries (`Changelog.md`'s Phase 18 entry,
  `PhaseResults/Phase18Result.md`) are left as originally written per the no-rewrite rule.

**Reason.** Phase 20 of the 30-phase plan.

**Migration notes.** 5 new tables, additive; no existing-table changes; no backfill. Every
lifecycle transition rule (the `PaymentStatus` graph, the `PaymentAttempt` complete-once guard)
is app-enforced, not a DB constraint.

**Breaking changes.** None. All changes are additive (new module, new tables, new CLI scripts).

## 2026-09-11 — Phase 19: Resolution API endpoints

**Summary.** Clients can now ask Gomrok for one package's resolved detail and check a voucher's
eligibility + discount over HTTP, without ever supplying a price. `GET /api/v1/packages` and
`GET /api/v1/pricing/resolve` already existed (Phases 13–14); this phase added
`GET /api/v1/packages/{packageId}` (`PackageDetailAction`, reusing `PriceCatalog::resolve` so it
can never disagree with the list endpoint; `{packageId}` accepts id or code) and
`GET /api/v1/vouchers/validate` (`VouchersValidateAction` + new
`Vouchers\Application\ValidateVoucher\ValidateVoucherHandler` — a non-locking preview composing
`PriceResolver`, `VoucherEligibilityEvaluator`, and `VoucherDiscountCalculator`, reserving
nothing). Both new endpoints are `GET`, matching the existing `pricing/resolve` precedent of
staying clear of the `/api/v1` write-idempotency rule for pure reads. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 19 Q1–Q5): **Q1** `{packageId}` accepts numeric id or
code · **Q2** unavailable-in-context → `404 package.not_found_in_context` · **Q3** eligibility +
discount preview, price resolved internally · **Q4** `GET`, no idempotency key · **Q5**
action-level direct-invoke tests, matching `MeActionTest`/`HealthActionTest`. **No database
changes.**

**Files created**
- `src/Modules/Vouchers/Application/ValidateVoucher/{ValidateVoucherCommand,ValidateVoucherResult,ValidateVoucherHandler}.php`.
- `src/Http/Api/PackageDetailAction.php`, `src/Http/Api/VouchersValidateAction.php`.
- Tests: `tests/Unit/Http/PackageDetailActionTest.php` (6 tests), `tests/Unit/Http/VouchersValidateActionTest.php`
  (3 tests), `tests/Unit/Modules/Vouchers/Application/ValidateVoucherHandlerTest.php` (5 tests).
- `.claude/PhaseResults/Phase19Result.md`.

**Files changed**
- `src/Config/routes.php` — registered `GET /api/v1/packages/{packageId}` and
  `GET /api/v1/vouchers/validate` in the authenticated `/api/v1` group.
- DB docs unchanged (no schema change this phase). `Architecture.md` (§3 Payments row untouched;
  §8 Pricing/Vouchers HTTP bullets updated, §9 pipeline note, §13 deferred-work trimmed);
  `Phases.md` (row 19 → ☑, as-built scope); `.claude/FileIndex.md` (new Http actions, new
  `ValidateVoucher` use case, updated Vouchers module row); `.claude/knowledge/Knowledge.md` (new
  Phase 19 gotchas section); `.claude/Orders.md` (D22); `.claude/Voucher.md` (new §9 "Validate
  endpoint (Phase 19 — implemented)", §10 decisions log, §11 implementation pointers, §12 open
  questions — renumbered from the old §9–§11).

**Reason.** Phase 19 of the 30-phase plan.

**Migration notes.** None — no schema change.

**Breaking changes.** None. Both new routes are additive; no existing endpoint's behavior or
response shape changed.

## 2026-09-11 — Phase 18: Decision snapshots

**Summary.** History never changes when rules change. New `Checkout` module anchored by
`checkout_attempts` — the parent record for the whole pre-payment lifecycle, so Gomrok can see
how far a customer got before a `payments` row exists, whether they abandoned checkout, and
which pricing/voucher/routing decisions were made. `attempt_reference` is the external,
caller-supplied idempotent key; `checkout_attempts.id` is the internal relational anchor that
three new write-once decision-snapshot tables FK to, one per owning module:
`pricing_decision_snapshots` (Pricing), `voucher_decision_snapshots` (Vouchers, thin — amounts
stay on `voucher_redemptions`), `provider_routing_decision_snapshots` (Providers). A new
`CheckoutAttemptStatus` enum implements a monotonic-rank state machine — 9 ranked happy-path
statuses plus 4 unranked exit statuses reachable from any non-terminal status, skipping ranks
allowed, terminal once reached. Decisions (`PhaseResults/PhaseDecisions.md` Phase 18 Q1–Q5 — Q1
and Q3 were full user-authored designs, not a choice from the presented options): **Q1**
`checkout_attempts` central anchor table, own status lifecycle, decision tables link to it,
final payment copies only immutable commercial data · **Q2** new `Checkout` module · **Q3**
monotonic-rank state machine with allowed skipping, exact transition set dictated by the user ·
**Q4** three per-module decision-snapshot tables, thin `voucher_decision_snapshots` · **Q5** one
handler per lifecycle step + `CheckoutAttempt::commercialSnapshot()`. **Schema confirmed by the
user.**

**Files created**
- Migration `20260911150001_create_checkout_and_decision_snapshot_tables.php` →
  `CreateCheckoutAndDecisionSnapshotTables` (4 tables: `checkout_attempts`,
  `pricing_decision_snapshots`, `voucher_decision_snapshots`, `provider_routing_decision_snapshots`).
- New `Checkout` module: `Domain/{CheckoutAttemptStatus,CheckoutAttempt,CheckoutAttemptRepository}.php`;
  `Application/{CheckoutAuditSnapshot,CheckoutAttemptSummary,CheckoutAttemptDirectory}.php`;
  `Application/CreateCheckoutAttempt/{Command,Result,Handler}.php`;
  `Application/ChangeCheckoutAttemptStatus/ChangeCheckoutAttemptStatusHandler.php`;
  `Application/ResolveCheckoutPricing/{Command,Result,Handler}.php`;
  `Application/ReserveCheckoutVoucher/{Command,Result,Handler}.php`;
  `Application/SelectCheckoutProvider/{Command,Result,Handler}.php`;
  `Infrastructure/{PdoCheckoutAttemptRepository,PdoCheckoutAttemptDirectory,definitions}.php`.
- Pricing: `Application/PricingDecisionSnapshot.php` (+ `of()`),
  `Application/PricingDecisionSnapshotRepository.php` port,
  `Infrastructure/PdoPricingDecisionSnapshotRepository.php`.
- Vouchers: `Domain/VoucherDecisionSnapshot.php`, `Domain/VoucherDecisionSnapshotRepository.php`
  port, `Infrastructure/PdoVoucherDecisionSnapshotRepository.php`.
- Providers: `Application/Routing/ProviderRoutingDecisionSnapshot.php` (+ `of()`),
  `Application/Routing/ProviderRoutingDecisionSnapshotRepository.php` port,
  `Infrastructure/PdoProviderRoutingDecisionSnapshotRepository.php`.
- CLI: `bin/{CreateCheckoutAttempt,ResolveCheckoutPricing,ReserveCheckoutVoucher,
  SelectCheckoutProvider,SetCheckoutAttemptStatus,ListCheckoutAttempts}.php` +
  `composer checkout:create|resolve-pricing|reserve-voucher|select-provider|set-status|list-attempts`.
- Tests: `tests/Support/{InMemoryCheckoutAttemptRepository,InMemoryPricingDecisionSnapshotRepository,
  InMemoryVoucherDecisionSnapshotRepository,InMemoryProviderRoutingDecisionSnapshotRepository}.php`;
  `tests/Unit/Modules/Checkout/Domain/{CheckoutAttemptStatusTest,CheckoutAttemptTest}.php`;
  `tests/Unit/Modules/Checkout/Application/CheckoutAttemptHandlersTest.php` (full cross-module
  wiring, happy path + no-voucher path); `tests/Integration/CheckoutAttemptPersistenceTest.php`
  (real-MySQL round trip, CI-only).
- `.claude/PhaseResults/Phase18Result.md`.

**Files changed**
- `src/Modules/Pricing/Application/ResolvedPrice.php` — gained `toArray()` for the pricing
  snapshot payload.
- `src/Modules/Providers/Application/Routing/RoutingDecision.php` — docblock updated to
  reference `ProviderRoutingDecisionSnapshot` (was stale Phase 17 text); no behavior change.
- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` gained
  `Checkout/Infrastructure/definitions.php`.
- `src/Modules/Pricing/Infrastructure/definitions.php`,
  `src/Modules/Vouchers/Infrastructure/definitions.php`,
  `src/Modules/Providers/Infrastructure/definitions.php` — wired the three new snapshot
  repositories.
- `composer.json` / `composer.lock`.
- `tests/Integration/MigrationRoundTripTest.php` — 4 new tables added.
- DB docs (`database-design.md` → 46 tables + a new "Checkout + decision snapshots (Phase 18)"
  section, `database-diagram.md` + `.html` 16/16 mermaid, `db_explain.md`); `Architecture.md`
  (§3, new §8 Checkout subsection, §9 pipeline, §13 deferred); `Phases.md` (row 18 → ☑, as-built
  scope); `.claude/Voucher.md` (§ note: `voucher_decision_snapshots` added Phase 18);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D21).

**Reason.** Phase 18 of the 30-phase plan.

**Migration notes.** 4 new tables, additive; no existing-table changes; no backfill. All four
decision-snapshot tables are write-once by construction (no update method on any repository
port); `checkout_attempts` is the only mutable one, driven only by `transitionTo()`'s app-enforced
rules (no DB constraint encodes the state machine).

**Breaking changes.** None. All changes are additive (new module, new tables, one new VO method,
one stale docblock correction).

## 2026-09-11 — Phase 17: Voucher validation, discount calc & redemption lifecycle

**Summary.** Applies a voucher safely, exactly once. Added `voucher_redemptions` — a reserve →
confirm/release lifecycle keyed by a caller-supplied `attempt_reference` (Phase 20 will pass the
real payment id). `VoucherDiscountCalculator` resolves the applicable discount (override →
default → inapplicable), rounds HALF_EVEN, clamps to the configured cap then to the price, and
reports both the pre-clamp (`nominal`) and post-clamp (`applied`) amounts. Concurrency safety:
every reserve/confirm/release handler `SELECT ... FOR UPDATE`s the parent `vouchers` row first —
the de facto per-voucher mutex — then re-checks the global/per-user/per-client caps under that
lock before writing; `ReserveVoucherRedemptionHandler` runs the now usage-aware
`VoucherEligibilityEvaluator` as the authoritative gate inside the lock. `VoucherUsagePort`
(declared Phase 16) is implemented by `PdoVoucherRedemptionRepository`, which also backs the new
`VoucherRedemptionRepository` Domain port. Decisions (`PhaseResults/PhaseDecisions.md` Phase 17
Q1–Q5): **Q1** opaque `attempt_reference` string · **Q2** three states, `reserved` counts
immediately, no auto-expiry (Phase 29 sweep) · **Q3** `FOR UPDATE` row-lock + re-check under
lock · **Q4** always clamp `[0, price]`, carry both nominal + applied amounts · **Q5** three
lifecycle handlers + calculator + CLI. **Schema confirmed by the user.**

**Files created**
- Migration `20260911130001_create_voucher_redemptions_table.php` → `CreateVoucherRedemptionsTable`.
- Vouchers domain: `RedemptionStatus` enum, `VoucherRedemption` aggregate (`reserve`, `confirm`,
  `release` — both idempotent on their own terminal state, mutually exclusive on the other),
  `VoucherRedemptionRepository` port.
- Vouchers application: `VoucherDiscountResult`, `VoucherDiscountCalculator`,
  `VoucherRedemptionSummary` + `VoucherRedemptionDirectory`; use cases `ReserveVoucherRedemption`
  (Command/Result/Handler), `ConfirmVoucherRedemption`, `ReleaseVoucherRedemption`.
- Vouchers infrastructure: `PdoVoucherRedemptionRepository` (implements both
  `VoucherRedemptionRepository` and `VoucherUsagePort`), `PdoVoucherRedemptionDirectory`.
- CLI: `bin/{ReserveVoucherRedemption,ConfirmVoucherRedemption,ReleaseVoucherRedemption,
  ListVoucherRedemptions}.php` + `composer voucher:reserve|confirm|release|list-redemptions`.
- Tests: `VoucherRedemptionTest`, `VoucherDiscountCalculatorTest`,
  `VoucherRedemptionHandlersTest` (idempotency, cap exhaustion, release-frees-cap); +5 new cases
  in `VoucherEligibilityEvaluatorTest` (global-with-reservations, per-user, per-client);
  `tests/Integration/VoucherRedemptionPersistenceTest.php` (real-MySQL round trip, CI-only);
  support double `InMemoryVoucherRedemptionRepository`.
- `.claude/PhaseResults/Phase17Result.md`.

**Files changed**
- `src/Modules/Vouchers/Application/VoucherEligibilityEvaluator.php` — gained a `VoucherUsagePort`
  dependency and global/per-user/per-client cap checks (`voucher.client_user_required`,
  `voucher.user_limit_reached`, `voucher.client_limit_reached`; `voucher.exhausted` now includes
  live reservations).
- `src/Modules/Vouchers/Application/VoucherUsagePort.php` — gained `activeReservations()`.
- `src/Modules/Vouchers/Application/VoucherAuditSnapshot.php` — gained `redemption()`.
- `src/Modules/Vouchers/Domain/VoucherRepository.php` — gained `findByIdForUpdate()` and
  `incrementRedeemedCount()`; `Infrastructure/{PdoVoucherRepository,definitions}.php` updated.
- `tests/Support/{InMemoryVoucherRepository,InMemoryVoucherEligibilityRuleRepository->unchanged}.php`
  — `InMemoryVoucherRepository` gained the two new methods;
  `tests/Unit/Modules/Vouchers/Application/VoucherEligibilityEvaluatorTest.php` updated for the
  new constructor arg.
- `composer.json` / `composer.lock`.
- `tests/Integration/MigrationRoundTripTest.php` — `voucher_redemptions` added.
- DB docs (`database-design.md` → 42 tables, `database-diagram.md` + `.html` 15/15 mermaid,
  `db_explain.md`); `Architecture.md` (§3, §8 Vouchers, §9 pipeline, §13 deferred); `Phases.md`
  (row 17 → ☑); `.claude/Voucher.md` (schema, discount rules, eligibility table, usage-limit
  mechanics, redemption lifecycle, decisions log, implementation pointers — all updated);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D20).

**Reason.** Phase 17 of the 30-phase plan.

**Migration notes.** 1 new table, additive; no existing-table changes; no backfill. Every
lifecycle transition rule (idempotent-vs-error per terminal state) and the concurrency mechanism
are app-enforced (a `SELECT ... FOR UPDATE` lock, not a DB constraint).

**Breaking changes.** None. `VoucherEligibilityEvaluator::__construct` gained a required
`VoucherUsagePort` argument — internal, the only call site (autowired via DI, plus one test) is
updated.

## 2026-09-10 — Phase 16: Vouchers module — definitions & eligibility

**Summary.** Voucher definitions and the eligibility gate — not the money math or redemption
(Phase 17). New `Vouchers` module: `vouchers` (client-scoped, default discount, usage-limit
columns), `voucher_eligibility_rules` (one `(voucher, dimension, value)` table across 7
dimensions), `voucher_currency_discounts` (per-currency override of the default). A
`VoucherEligibilityEvaluator` reports every unmet condition in one pass. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 16 Q1–Q5): **Q1** one `voucher_eligibility_rules` table
· **Q2** default discount + per-currency overrides (user extended the recommended "amounts
table" into a default-plus-override model — resolution: override → else default → else `none`
not applicable) · **Q3** usage limits as **nullable columns on `vouchers`** (user overrode the
recommended child table — `NULL` = unlimited; canonical "everyone, once per user" =
`NULL/1/NULL`) · **Q4** the evaluator returns every failing reason, not fail-fast; per-user/
per-client caps deferred to Phase 17 behind a declared `VoucherUsagePort` · **Q5** granular
audited handlers + `voucher:*` CLI + seeder. **Schema confirmed by the user.**

**New standing rule (user instruction this phase):** `.claude/Voucher.md` is now the **single
source of truth for all voucher behaviour** — every future voucher-related rule/decision/schema
change must also be recorded there. Added to `CLAUDE.md` ("Voucher Rules File" section) and
`.claude/Rule.md` → Project Documents.

**Files created**
- Migration `20260910170001_create_voucher_tables.php` → `CreateVoucherTables` (3 tables).
- **New `Vouchers` module** (`src/Modules/Vouchers/`, registered in `ContainerFactory`): domain
  (`Voucher` aggregate, `VoucherCurrencyDiscount` + `VoucherEligibilityRule` VOs,
  `VoucherStatus`/`DefaultDiscountType`/`DiscountType`/`VoucherEligibilityDimension` enums, 3
  repository ports); application (`VoucherContext`, `VoucherEligibility`, `VoucherUsagePort`
  (declared only), `VoucherEligibilityEvaluator`, `VoucherAuditSnapshot`, `VoucherSummary` +
  `VoucherDirectory`, 7 use-case folders); infrastructure (4 `Pdo*` adapters, `definitions.php`).
- CLI: `bin/{CreateVoucher,UpdateVoucher,SetVoucherEligibility,SetVoucherCurrencyDiscount,
  RemoveVoucherCurrencyDiscount,SetVoucherUsageLimits,SetVoucherStatus,ListVouchers}.php`.
- `src/Database/Seeds/VouchersSeeder.php` — `WELCOME10` (10%, once per user) + `EU5` (`none`
  default, EUR/USD/GBP fixed overrides, `pro`-only).
- Tests: `VoucherTest`, `VoucherCurrencyDiscountTest`, `VoucherEligibilityEvaluatorTest` (the
  exit criterion), `VoucherHandlersTest`; support doubles `InMemory{Voucher,
  VoucherEligibilityRule,VoucherCurrencyDiscount}Repository`.
- `.claude/Voucher.md` (new — source of truth for voucher behaviour).
- `.claude/PhaseResults/Phase16Result.md`.

**Files changed**
- `src/Bootstrap/ContainerFactory.php` (Vouchers module registered).
- `composer.json` / `composer.lock` — `voucher:*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — 3 new tables.
- `CLAUDE.md` — new "Voucher Rules File" section.
- `.claude/Rule.md` (Project Documents), `.claude/FileIndex.md`.
- DB docs (`database-design.md` → 41 tables, `database-diagram.md` + `.html` 14/14 mermaid —
  also brought the `.html` module-map snapshot back in sync with Phases 14–15, which had drifted;
  `db_explain.md`); `Architecture.md` (§3 module table, new §8 Vouchers, §9 pipeline, §13
  deferred); `Phases.md` (row 16 → ☑, scope rewritten "as built"); `.claude/knowledge/Knowledge.md`;
  `.claude/docs/Commands.md`; `.claude/Orders.md` (D19).

**Reason.** Phase 16 of the 30-phase plan.

**Migration notes.** 3 new tables, all additive; no existing-table changes; no backfill.
Uniqueness (`code` per client, one rule per `(voucher, dimension, value)`, one override per
`(voucher, currency)`) and every cross-field consistency rule (discount shape, min-purchase
pairing, window ordering, usage-limit positivity) are app-enforced.

**Breaking changes.** None.

## 2026-09-10 — Phase 15: Price lists (A/B) — data model, resolver & CRUD

**Summary.** A/B price experiments inside a pricing group. Two tables: `price_lists` (one
**control** row per group — `is_control`, `factor 1.0000`, undeletable, never disabled — plus
non-control experiment lists carrying a `DECIMAL(6,4)` factor) and `price_list_packages` (an
exact per-package amount that overrides the factor on a non-control list). `PriceListResolver`
runs between the Phase 13 base amount and the Phase 14 `price_rules` step: exact list-package
amount → else `base × factor` (HALF_EVEN) → else the base unchanged; `ResolvedPrice` always
carries `priceListId` / `priceListName` / `priceListFactor` and `source` becomes `price_list`
when the amount moved. A `$priceListId` that is unknown, from another group, or disabled falls
back to the control list. Decisions (`PhaseResults/PhaseDecisions.md` Phase 15): **Q1 Option 2**
explicit control row · **Q2 Option 3** factor + optional exact per-package price · **Q3 Option
1** list applies to the base, before `price_rules` · **Q4 + Q5 DEFERRED** to Phase 24 (payment
creation) — the visitor→list assignment table, hashing/bucketing service and `visitor_ref`
endpoint params are **not** built; every resolve currently uses the control list. **Schema
confirmed by the user.**

**Files created**
- Migration `20260910160001_create_price_lists_tables.php` → `CreatePriceListsTables` (also
  backfills a control list per existing pricing group).
- Pricing domain: `PriceList` aggregate (`control` / `experiment` / `fromStorage`, `rename`,
  `changeFactor`, `enable`, `disable`, `isNeutral`, `validateFactor`), `PriceListPackage` VO,
  `PriceListRepository` + `PriceListPackageRepository` ports.
- Pricing application: `PriceListResolver`, `PriceListAuditSnapshot`, `PriceListSummary`,
  `PriceListDirectory`, `PriceSource::PriceList`, `ResolvedPrice::withList` + `priceListId` /
  `priceListName` / `priceListFactor`. Use cases `CreatePriceList`, `ChangePriceListStatus`,
  `SetPriceListFactor`, `SetPriceListPackagePrice`.
- Pricing infrastructure: `PdoPriceListRepository`, `PdoPriceListPackageRepository`,
  `PdoPriceListDirectory`.
- CLI: `bin/{CreatePriceList,SetPriceListStatus,SetPriceListFactor,SetPriceListPackagePrice,ListPriceLists}.php`
  + `composer pricing:create-list|set-list-status|set-list-factor|set-list-price|list-lists`.
- Tests: `PriceListTest`, `PriceListResolverTest`, `PriceListHandlersTest`; +1 `PriceResolverTest`
  case; support doubles `InMemoryPriceListRepository`, `InMemoryPriceListPackageRepository`.
- `.claude/PhaseResults/Phase15Result.md`.

**Files changed**
- `src/Modules/Pricing/Application/PriceResolver.php` — new `PriceListResolver` ctor dep +
  `?int $priceListId` param + the price-list step; `PriceSource.php`, `ResolvedPrice.php`,
  `Infrastructure/definitions.php`.
- `src/Modules/Pricing/Application/CreatePricingGroup/CreatePricingGroupHandler.php` — creates
  the control `price_lists` row in the same transaction (new `PriceListRepository` dep).
- `src/Database/Seeds/PricingSeeder.php` — control list per group + a disabled `dach` "List B".
- `composer.json` / `composer.lock`.
- `tests/Integration/{MigrationRoundTripTest,PricingPersistenceTest}.php`;
  `tests/Unit/{Http/PackagesApiTest,Modules/Pricing/Application/{PriceResolverTest,PriceCatalogTest,PricingHandlersTest}}.php`.
- DB docs (`database-design.md` → 38 tables, `database-diagram.md` + `.html` 13/13 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Pricing, §9 PRICE pipeline, §13 deferred);
  `Phases.md` (row 15 → ☑ narrowed scope; row 24 gains the deferred assignment);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D18).

**Reason.** Phase 15 of the 30-phase plan (narrowed — visitor assignment deferred by the user).

**Migration notes.** 2 new tables, additive; no existing-table changes. The migration backfills
`price_lists` with a control row per existing `pricing_groups` row. `is_control` uniqueness and
the control-list immutability rules are app-enforced.

**Breaking changes.** None. `PriceResolver::__construct` gains a `PriceListResolver` argument and
`resolve()` a trailing optional `?int $priceListId`; `CreatePricingGroupHandler::__construct`
gains a `PriceListRepository` argument — all internal, all call sites updated.

## 2026-09-10 — Phase 14: Pricing overrides & resolution engine

**Summary.** The override layer above the Phase 13 base price: one `price_rules` table keyed by
7 nullable dimensions (`pricing_group_id`, `country_code`, `provider_account_id`,
`payment_method`, `purchase_type`, `subscription_interval`, `currency_code`; null = wildcard),
each row either overriding `amount_minor` or marking the combination not for sale
(`is_available = 0`). A dedicated `PriceRuleResolver` picks the winner — most matched dimensions
→ fixed dimension priority (`subscription_interval > purchase_type > payment_method >
provider_account_id > currency_code > country_code > pricing_group_id`) → highest `id`.
`PriceResolver` runs it after the base price; an unavailable winner is a hard
`pricing.combination_unavailable` with **no fallback**. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 14 Q1–Q5): single table w/ nullable dimensions · 7
dimensions + new `SubscriptionInterval` enum · matched-count → dimension-priority → id · row-level
`is_available`, no fallback · dedicated resolver + `/pricing/resolve` query params + CLI + seeder.
**Schema confirmed by the user.**

**Files created**
- Migration `20260910150001_create_price_rules_table.php` → `CreatePriceRulesTable`.
- Pricing domain: `SubscriptionInterval` enum, `PriceRule` aggregate (`DIMENSIONS`, `validate`,
  `matches`, `pinnedDimensions`, `specificity`, `tieBreak`), `PriceRuleRepository` port.
- Pricing application: `PriceRuleContext`, `PriceRuleResolver`, `PriceRuleAuditSnapshot`,
  `PriceRuleSummary`, `PriceRuleDirectory`, `SetPriceRule/{Command,Result,Handler}`,
  `DeletePriceRule/DeletePriceRuleHandler`. `PriceSource::DimensionOverride`;
  `ResolvedPrice::withRule` + `appliedRuleId` / `appliedDimensions`.
- Pricing infrastructure: `PdoPriceRuleRepository` (null-safe `<=>` upsert), `PdoPriceRuleDirectory`.
- CLI: `bin/{SetPriceRule,DeletePriceRule,ListPriceRules}.php` + `composer pricing:set-rule|delete-rule|list-rules`.
- Tests: `PriceRuleTest`, `PriceRuleResolverTest`, `PriceRuleHandlersTest`; +2 `PriceResolverTest`
  cases; support double `InMemoryPriceRuleRepository`.
- `.claude/PhaseResults/Phase14Result.md`.

**Files changed**
- `src/Modules/Pricing/Application/PriceResolver.php` (new `PriceRuleResolver` ctor dep + `applyRules`),
  `PriceSource.php`, `ResolvedPrice.php`, `Infrastructure/definitions.php`.
- `src/Http/Api/PricingResolveAction.php` — `method` / `purchase_type` / `interval` query params
  + `applied_rule_id` / `applied_dimensions` in the response.
- `src/Database/Seeds/PricingSeeder.php` — two `pro` price rules.
- `tests/Integration/{MigrationRoundTripTest,PricingPersistenceTest}.php`;
  `tests/Unit/{Http/PackagesApiTest,Modules/Pricing/Application/PriceCatalogTest}.php`.
- `composer.json` / `composer.lock`.
- DB docs (`database-design.md` → 36 tables, `database-diagram.md` + `.html` 12/12 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Pricing, §9 PRICE pipeline); `Phases.md` (row 14 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D17).

**Reason.** Phase 14 of the 30-phase plan.

**Migration notes.** 1 new table, additive; no existing-table changes; no backfill. The 8-column
unique index is NULL-distinct, so upserts use a null-safe lookup rather than `ON DUPLICATE KEY`.
Dimension ownership + group/currency agreement are handler-enforced.

**Breaking changes.** None. `PriceResolver::__construct` gains a `PriceRuleResolver` argument and
`resolve()` gains four optional trailing parameters — internal, all call sites updated.

## 2026-09-10 — Phase 13: Pricing module — default prices & pricing groups

**Summary.** The baseline of Gomrok pricing: priority-ordered pricing groups, one baseline price
per package, client-configured FX, per-group-per-package rows, a `PriceResolver` /
`PriceCatalog`, and the first two client-facing catalogue endpoints. Decisions
(`PhaseResults/PhaseDecisions.md` Phase 13 Q1–Q5): priority-ordered overlapping pricing groups
(distinct from Phase 10 provider groups) · single baseline + `client_exchange_rates` for
cross-currency · one `(group, package)` row, no row = implicit default · dedicated
`PriceResolver`/`PriceCatalog` + `ResolvedPrice`, `GET /api/v1/packages` mounts now · one
audited handler per operation + `pricing:*` CLI + seeder. **Schema confirmed by the user.**

**Files created**
- Migrations `20260910140001-05` → `Create{PricingGroups,PricingGroupCountries,DefaultPackagePrices,ClientExchangeRates,PricingGroupPackages}Table`; `PricingSeeder`.
- New **Pricing module** (`src/Modules/Pricing/`, registered in `ContainerFactory`): domain
  (`PricingGroup`, `PricingGroupPackage`, `DefaultPackagePrice`, `ClientExchangeRate`, enums,
  4 repository ports); application (`PriceResolver`, `PriceCatalog`, `ResolvedPrice` /
  `ResolvedCatalogPackage` / `PriceSource`, `PricingGroupDirectory` + `Summary`,
  `PricingAuditSnapshot`, 7 use-case folders); infrastructure (5 `Pdo*` adapters, `definitions.php`).
- HTTP: `src/Http/Api/PackagesAction.php` (`GET /api/v1/packages`),
  `src/Http/Api/PricingResolveAction.php` (`GET /api/v1/pricing/resolve`); routes wired.
- CLI: `bin/{CreatePricingGroup,SetDefaultPackagePrice,SetClientExchangeRate,SetPricingGroupPackage,ListPricing}.php`.
- Tests: `PriceResolverTest`, `PricingGroupTest`, `PricingHandlersTest`, `PriceCatalogTest`,
  `PackagesApiTest`, `PricingPersistenceTest`; support doubles
  `InMemory{PricingGroup,PricingGroupPackage,DefaultPackagePrice,ClientExchangeRate}Repository`,
  `StubPackageDirectory`.
- `Shared\Domain\Money::amount()` — decimal-string accessor.
- `.claude/PhaseResults/Phase13Result.md`.

**Files changed**
- `src/Bootstrap/ContainerFactory.php`, `src/Config/routes.php`.
- `composer.json` / `composer.lock` — `pricing:*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables.
- DB docs (`database-design.md` → 35 tables, `database-diagram.md` + `.html` 11/11 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Pricing, §9 pipeline); `Phases.md` (row 13 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D16).

**Reason.** Phase 13 of the 30-phase plan.

**Migration notes.** 5 new tables, all additive; no existing-table changes; no backfill.
`priority` uniqueness / one-default / override-currency-match are app-enforced.

**Breaking changes.** None. (`GET /api/v1/pricing/resolve` deviates from CLAUDE.md's suggested
`POST` — it is a pure read; documented in Phase 13 Q4.)

## 2026-09-10 — Phase 12: Package purchase capabilities & provider definitions

**Summary.** What a package can be sold *as* (purchase types + trial/duration, per-country
overrides) and where it exists on the provider side (`package_provider_definitions` with a
4-state sync machine). Decisions (`PhaseResults/PhaseDecisions.md` Phase 12 Q1–Q5): join table
with per-row trial/duration + display columns on `packages` · country override table that
replaces the global set · lazily-created definitions with `not_created`/`synced`/`drift`/`not_needed`
· dedicated `PackagePurchaseCapabilityResolver` + extended `ResolvedPackage` · in-handler drift
sweep. **Schema confirmed by the user.**

**Files created**
- Migrations `20260910130001-04` → `Create{PackagePurchaseCapabilities,PackageCountryPurchaseCapabilities,PackageProviderDefinitions}Table`, `AddDisplayFieldsToPackages`.
- `src/Modules/Packages/Domain/*` — `PackagePurchaseCapability`, `PackageCountryPurchaseCapability`,
  `PackageProviderDefinition` (aggregate), `PackageProviderSyncState`, `PackageProviderDefinitionRepository`.
- `src/Modules/Packages/Application/*` — `PackagePurchaseCapabilityResolver`, `PackageCapabilitySet`,
  `ResolvedPurchaseCapability`, `PackageProviderDefinitionDirectory` (+ `Summary`),
  `PackageProviderDefinitionAuditSnapshot`; use cases `SetPackagePurchaseCapabilities`
  (+ `PurchaseCapabilityInput`), `SetPackageCountryPurchaseCapabilities`, `LinkPackageProvider`,
  `ChangePackageProviderSyncState`.
- `src/Modules/Packages/Infrastructure/*` — `PdoPackageProviderDefinitionRepository`,
  `PdoPackageProviderDefinitionDirectory`.
- CLI: `bin/{SetPackageCapabilities,SetPackageCountryCapabilities,LinkPackageProvider}.php`.
- Tests: `PackagePurchaseCapabilityTest`, `PackageProviderDefinitionTest`,
  `PackageCapabilityHandlersTest`, `InMemoryPackageProviderDefinitionRepository`;
  `PackagesPersistenceTest` gained a second test.
- `.claude/PhaseResults/Phase12Result.md`.

**Files changed**
- `src/Modules/Packages/Domain/Package.php` — `badge` / `highlighted` / `clientPackageId` +
  global & per-country purchase capabilities, `effectiveCapabilities()` / `isSellable()`.
- `PdoPackageRepository` / `PdoPackageDirectory` / `PackageSummary` / `ResolvedPackage` /
  `PackageCatalog` / `PackageAuditSnapshot` — extended for the new fields; `PackageCatalog` drops
  non-sellable packages and injects the resolver.
- `UpdatePackage{Command,Handler}` / `SetPackageAvailabilityHandler` — display fields + drift
  sweep (`PackageProviderDefinitionRepository` dependency).
- `composer.json` / `.lock` — `package:set-capabilities` / `:set-country-capabilities` /
  `:link-provider` scripts. `bin/{UpdatePackage,ListPackages}.php` extended. `PackagesSeeder` —
  capabilities.
- `tests/Integration/MigrationRoundTripTest.php`; Phase 11 package tests updated for the new
  constructors.
- DB docs (`database-design.md` → 30 tables, `database-diagram.md` + `.html` 10/10 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Packages, §9 pipeline); `Phases.md` (row 12 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D15).

**Reason.** Phase 12 of the 30-phase plan.

**Migration notes.** 3 new tables + an additive `ADD COLUMN` on `packages` (nullable / defaulted,
no backfill). Cross-client integrity on `package_provider_definitions` is app-enforced.

**Breaking changes.** None.

## 2026-09-10 — Replace provider-shaped fake seed credentials

**Summary.** GitHub secret scanning flagged the `sk_test_…` / `whsec_…` fixture strings in the
env-gated dev seeders as real Stripe credentials and blocked the push. Replaced them with values
that are obviously not provider credentials (`gomrok-local-dev-fake-…`). Behaviour is unchanged —
the seeders still encrypt the fake secret via `SecretCipher` and store `secret_last_four`.

**Files changed**
- `src/Database/Seeds/ProviderAccountsSeeder.php` — `FAKE_SECRET`, `FAKE_WEBHOOK_SECRET`, and the
  inline fake `public_key`.
- `src/Database/Seeds/ProviderGroupsSeeder.php` — the generated fake `secret` + `public_key` in
  `upsertAccount()`.
- `.claude/PhaseResults/PhaseDecisions.md` — the Phase 9 Q5 note quoting the old string.

**Reason.** Unblock the push; keep committed fixtures from ever resembling live secrets.

**Migration notes.** None. Re-run `composer db:reset` locally if you already seeded — the upsert
refreshes the encrypted value.

**Breaking changes.** None.

## 2026-09-10 — Phase 11: Packages module — catalog & availability

**Summary.** Gomrok's client-owned package catalogue: `packages` (one table with `client_id`,
`code` unique per client — no global catalogue, no `client_packages` junction) + four fail-open
availability join tables, and a `PackageCatalog` that resolves the market-filtered list.
Decisions (`PhaseResults/PhaseDecisions.md` Phase 11 Q1–Q5): four dedicated join tables · empty
set = available everywhere per dimension · internal `PackageCatalog` port, HTTP endpoint
deferred to Phase 13 · lean `packages` table (Phase 12 adds its fields) · separate
`SetPackageAvailability` handler + CLI + dev seeder. **Schema confirmed by the user.**

**Files created**
- Migrations `20260910120001-05` → `Create{Packages,PackageCountries,PackageCurrencies,PackagePaymentMethods,PackageProviderAccounts}Table`; `PackagesSeeder` (env-gated).
- `src/Modules/Packages/Domain/*` — `Package` (aggregate), `PackageCode`, `PackageStatus`,
  `PackageRepository`.
- `src/Modules/Packages/Application/*` — `PackageCatalog` (+ `ResolvedPackage`), `PackageDirectory`
  (+ `PackageSummary`), `PackageAuditSnapshot`; use cases `CreatePackage`, `UpdatePackage`,
  `ChangePackageStatus`, `SetPackageAvailability`.
- `src/Modules/Packages/Infrastructure/*` — `PdoPackageRepository`, `PdoPackageDirectory`,
  `definitions.php`.
- CLI: `bin/{CreatePackage,UpdatePackage,SetPackageAvailability,ListPackages}.php`.
- Tests: `tests/Unit/Modules/Packages/Domain/PackageTest.php`,
  `tests/Unit/Modules/Packages/Application/{PackageCatalogTest,PackageHandlersTest}.php`,
  `tests/Integration/PackagesPersistenceTest.php`,
  `tests/Support/InMemoryPackageRepository.php`.
- `.claude/PhaseResults/Phase11Result.md`.

**Files changed**
- `src/Bootstrap/ContainerFactory.php` — registers the Packages module `definitions.php`.
- `composer.json` / `composer.lock` — `package:create` / `:update` / `:set-availability` /
  `:list` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables.
- DB docs (`database-design.md` → 27 tables, `database-diagram.md` + `.html` 9/9 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Packages, §9 pipeline); `Phases.md` (row 11 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D14).

**Reason.** Phase 11 of the 30-phase plan — the catalogue Gomrok owns so clients stop keeping
their own package definitions.

**Migration notes.** 5 new tables, all additive; no changes to existing tables; no backfill.
`package_provider_accounts` cross-client integrity is app-enforced.

**Breaking changes.** None.

## 2026-09-09 — Phase 10: Country provider configuration & routing resolution

**Summary.** Provider groups — the single mechanism (Phase 10 Q1) for "which provider account
for this purchase" — plus a deterministic `ProviderRouter` that rejects unsupported combinations
rather than downgrading them. Decisions (`PhaseResults/PhaseDecisions.md` Phase 10 Q1–Q5):
provider groups only (no `country_provider_configs` tables) · group-level purchase-type / method
enablement, intersected with each account's provider-type declaration · resolver returns an
ordered candidate list + `RoutingDecision` snapshot VO · VO only, no routing table this phase ·
seed real Ziraat + Mollie declarations. **Schema confirmed by the user.**

**Files created**
- Migrations `20260909220001-05` → `Create{ProviderGroups,ProviderGroupCountries,ProviderGroupAccounts,ProviderGroupPurchaseTypes,ProviderGroupMethods}Table`.
- Seeder `ProviderGroupsSeeder` (env-gated: `local-dev` turkey/germany/netherlands/default).
- `src/Modules/Providers/Domain/*` — `ProviderGroup` (aggregate), `ProviderGroupAccount`,
  `ProviderGroupSlug`, `ProviderGroupStatus`, `DeviceType`, `ProviderGroupRepository`.
- `src/Modules/Providers/Application/Routing/*` — `ProviderRouter`, `RoutingRequest`,
  `RoutingDecision` (+ `toArray()` / `fromArray()`), `RoutedAccount`, `RejectedAccount`,
  `RejectionReason`.
- `src/Modules/Providers/Application/*` — `ProviderGroupAuditSnapshot`; use cases
  `CreateProviderGroup`, `ConfigureProviderGroup`, `SetProviderGroupAccounts`
  (+ `ProviderGroupAccountInput`), `ChangeProviderGroupStatus`.
- `src/Modules/Providers/Infrastructure/PdoProviderGroupRepository.php`.
- CLI: `bin/{CreateProviderGroup,ConfigureProviderGroup,SetProviderGroupAccounts}.php`.
- Tests: `tests/Unit/Modules/Providers/Domain/ProviderGroupTest.php`,
  `tests/Unit/Modules/Providers/Application/Routing/{ProviderRouterTest,RoutingDecisionTest}.php`,
  `tests/Unit/Modules/Providers/Application/ProviderGroupHandlersTest.php`,
  `tests/Integration/ProviderGroupsPersistenceTest.php`,
  `tests/Support/{InMemoryProviderGroupRepository,StubProviderAccountDirectory}.php`.
- `.claude/PhaseResults/Phase10Result.md`.

**Files changed**
- `src/Database/Seeds/data/ProviderTypeDeclarations.json` — added `mollie` + `ziraat`.
- `src/Database/Seeds/ProviderTypeDeclarationsSeeder.php` — docblock (no code change).
- `src/Modules/Providers/Infrastructure/definitions.php` — binds `ProviderGroupRepository`.
- `composer.json` — `provider-group:*` scripts + descriptions; `composer.lock` hash refreshed.
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables in the list.
- `tests/Unit/Database/ProviderTypeDeclarationsDataTest.php` — accepts mollie / ziraat.
- DB docs (`database-design.md` → 22 tables, `database-diagram.md` + `.html`, `db_explain.md`);
  `Phases.md` (row 10 → ☑, scope rewritten to match Q1); `.claude/FileIndex.md`;
  `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`; `.claude/Orders.md`.

**Reason.** Phase 10 of the 30-phase plan — deterministic provider routing with no silent
purchase-type downgrades.

**Migration notes.** 5 new tables, all additive; no changes to existing tables; no backfill.
Cross-client and "one default per scope" / "country in one group" invariants are app-enforced
(no cross-table FK in MySQL).

**Breaking changes.** None.

## 2026-09-09 — Rename `LastAiAnswer.md` → `last_ai_answer.md`

**Summary.** The single-slot response-log buffer is renamed from `.claude/docs/LastAiAnswer.md`
to `.claude/docs/last_ai_answer.md` (user request).

**Files changed**
- `.claude/docs/LastAiAnswer.md` → `.claude/docs/last_ai_answer.md` (renamed, content unchanged).
- `CLAUDE.md` — *last_ai_answer.md Response Log Rule* heading + all in-rule paths; §3.1 naming
  example; §3.3 docs list.
- `.claude/Rule.md` — §1 working-agreement bullet; §3.1 (removed the old file from the PascalCase
  examples, added a new *response-log buffer* exception); §3.4 tree; Project Documents table;
  §"where things get written" table.
- `.claude/FileIndex.md`, `.claude/Orders.md` (P5 row) — path updated.

**Reason.** User asked for the lowercase snake_case name.

**Migration notes.** None (documentation only). Older Changelog entries keep the historical
`LastAiAnswer.md` name.

**Breaking changes.** None.

## 2026-09-09 — Phase 9: Provider accounts (per client)

**Summary.** A client's live/test provider credentials, with the secret key encrypted at rest.
Decisions (`PhaseResults/PhaseDecisions.md` Phase 9 Q1–Q5): app-encrypted column behind a
`SecretCipher` port (libsodium) · `mode` enum on the account, key prefix picks the pool ·
separate `provider_account_endpoints` table for webhook/callback config · countries + methods
join tables only, capabilities inherited from the type · CLI + env-gated dev seeder.
**Schema confirmed by the user.**

**Files created**
- Migrations `2026090919300{1..4}` → `Create{ProviderAccounts,ProviderAccountEndpoints,ProviderAccountCountries,ProviderAccountMethods}Table`.
  Seeder `ProviderAccountsSeeder`.
- `src/Shared/Application/{SecretCipher,SecretDecryptionFailed}.php`,
  `src/Shared/Infrastructure/Crypto/SodiumSecretCipher.php`.
- `src/Modules/Providers/Domain/*` — `ProviderAccount`, `ProviderAccountEndpoint`,
  `ProviderAccountSlug`, `EncryptedSecret`, `ProviderAccountMode`, `ProviderAccountStatus`,
  `EndpointKind`, `ProviderAccountRepository`.
- `src/Modules/Providers/Application/*` — `ProviderAccountDirectory` + `ProviderAccountSummary`,
  `ProviderAccountCredentials`, `ProviderAccountAuditSnapshot`, and `CreateProviderAccount` /
  `SetProviderAccountMarkets` / `RotateProviderAccountSecret` / `AddProviderAccountEndpoint` /
  `ChangeProviderAccountStatus` (handler + command [+ result]).
- `src/Modules/Providers/Infrastructure/*` — `PdoProviderAccountRepository`,
  `PdoProviderAccountDirectory`, `PdoProviderAccountCredentials`.
- CLI: `bin/{CreateProviderAccount,RotateProviderAccountSecret,AddProviderAccountEndpoint,ListProviderAccounts}.php`.
- Tests: `tests/Unit/Shared/Infrastructure/SodiumSecretCipherTest.php`,
  `tests/Unit/Modules/Providers/Domain/ProviderAccountTest.php`,
  `tests/Unit/Modules/Providers/Application/ProviderAccountHandlersTest.php`,
  `tests/Integration/ProviderAccountsPersistenceTest.php`,
  `tests/Support/{InMemoryProviderAccountRepository,StubProviderCatalog,StubClientDirectory}.php`.
- `.claude/PhaseResults/Phase09Result.md`.

**Files modified**
- `src/Config/Settings.php` — `?string $encryptionKeyBase64` from `APP_ENCRYPTION_KEY`.
- `src/Config/container.php` — lazy `SecretCipher` factory.
- `src/Modules/Providers/Infrastructure/definitions.php` — binds the account ports.
- `composer.json` — `ext-sodium`; `provider-account:*` scripts.
- `.env.example`, `phpunit.xml`, `.github/workflows/Ci.yml` — `APP_ENCRYPTION_KEY` (throwaway
  key for CI / tests; blank in `.env.example`). `Ci.yml` — `sodium` extension.
- `tests/Integration/MigrationRoundTripTest.php` — 4 provider-account tables.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 17 tables),
  `.claude/docs/Phases.md` (row 9 → ☑), `Architecture.md` §8/§11, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`, `.claude/Orders.md` (D12).

**DB changes.** 4 new tables — `provider_accounts` (FK `clients` CASCADE, `provider_types`
RESTRICT), `provider_account_endpoints` (unique `token`), `provider_account_countries` (FK
`countries.code`), `provider_account_methods`. No changes to existing tables. Non-schema:
`APP_ENCRYPTION_KEY` env var.

**Verification.** `composer ci` green — 157 unit tests, 572 assertions. PHPStan `max` +
strict-rules clean (247 files). php-cs-fixer clean. `composer test:integration` → 29 tests, all
self-skip (no Docker). `SecretCipher` round-trips; migration + seeder classes load; the DI
container wires it with `APP_ENCRYPTION_KEY` set.

**NOT verified here.** Migrations + `ProviderAccountsPersistenceTest` (secret round-trip against
real MySQL) — CI, or `docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** `ext-sodium` now required. `Settings::__construct` gained an optional
parameter (internal).

## 2026-09-09 — Phase 8: Providers module (types & capability model)

**Summary.** Second `src/Modules/` module — models what each provider *type* can do, no SDKs.
Decisions (`PhaseResults/PhaseDecisions.md` Phase 8 Q1–Q5): `Capability` enum + seeded
`provider_capabilities` mirror · separate `PurchaseType` enum (not capability flags) · join
tables for per-type declarations · payment methods = code-only enum + in-code
`MethodCapabilityRules` placeholder, no method tables yet · seed **stripe + paypal** only
(ziraat/mollie deferred to their adapter phases). **Schema confirmed by the user.**

**Files created**
- Migrations `2026090917000{1,2,3}` → `Create{ProviderCapabilities,ProviderTypeCapabilities,ProviderTypePurchaseTypes}Table`.
- Seeders `ProviderCapabilitiesSeeder` (from the enum), `ProviderTypeDeclarationsSeeder`
  (stripe + paypal, from `src/Database/Seeds/data/ProviderTypeDeclarations.json`).
- `src/Modules/Providers/Domain/*` — `Capability`, `CapabilityGroup`, `PurchaseType`,
  `PaymentMethod`, `ProviderCapabilities`, `ProviderTypeDeclaration`, `ProviderTypeDeclarations`
  (port), `MethodConstraint`, `MethodCapabilityRules`.
- `src/Modules/Providers/Application/*` — `ProviderCapabilityResolver`,
  `ResolvedProviderCapabilities`, `ProviderCatalog` (port) + `ProviderTypeSummary`.
- `src/Modules/Providers/Infrastructure/*` — `PdoProviderTypeDeclarations`, `PdoProviderCatalog`,
  `definitions.php`.
- Tests: `tests/Unit/Modules/Providers/**` (Domain: Capability, ProviderCapabilities,
  MethodCapabilityRules; Application: ProviderCapabilityResolver — the exit matrix),
  `tests/Unit/Database/ProviderTypeDeclarationsDataTest.php` (JSON ↔ enum guard),
  `tests/Integration/ProviderCapabilitiesPersistenceTest.php`,
  `tests/Support/InMemoryProviderTypeDeclarations.php`.
- `.claude/PhaseResults/Phase08Result.md`.

**Files modified**
- `src/Bootstrap/ContainerFactory.php` — Providers `definitions.php` added to `MODULE_DEFINITIONS`.
- `tests/Integration/MigrationRoundTripTest.php` — 3 provider tables added to `TABLES`.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 13 tables),
  `.claude/docs/Phases.md` (row 8 → ☑), `Architecture.md` §8, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/Orders.md` (D11).

**DB changes.** New: `provider_capabilities` (19 seeded rows), `provider_type_capabilities`,
`provider_type_purchase_types` (both FK `provider_types` + `provider_capabilities`, CASCADE;
seeded stripe + paypal). All three static — no timestamps. No changes to `provider_types`.

**Verification.** `composer ci` green — 141 unit tests, 506 assertions. PHPStan `max` +
strict-rules clean (202 files). php-cs-fixer clean. `composer test:integration` → 26 tests, all
self-skip (no Docker). Migration + seeder classes load; `Capability` = 19 cases; seed JSON =
stripe + paypal; container wires the resolver.

**NOT verified here.** Migrations + `ProviderCapabilitiesPersistenceTest` (incl. the enum ↔
`provider_capabilities` lock-step check) against real MySQL — runs in CI, or
`docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** None (all additive).

## 2026-09-08 — PhaseDecisions.md reordered newest-first

**Summary.** Reversed the order of every decision section in
`.claude/PhaseResults/PhaseDecisions.md`: newest phase now at the **top**, Phase 1 at the
bottom; within each phase the questions run in **descending** order (Q5 → Q1). This **reverses**
the "append-only, oldest-first" rule added earlier the same day (user request).

**Files changed**
- `.claude/PhaseResults/PhaseDecisions.md` — sections reordered (Phase 7→1; questions Q5→Q1
  within each). **Content preserved exactly** — the reorder was done mechanically and verified:
  identical byte count, identical line multiset, all 36 question bodies byte-for-byte unchanged.
  Intro "Order" note updated to describe newest-first.
- `.claude/Rule.md` §4.2, `CLAUDE.md` *Interactive Phase Rule*, `.claude/PhaseResults/Readme.md`,
  memory `phase-workflow-and-results.md` — the ordering rule flipped to **newest-first, prepend
  new entries to the top**.

**Reason.** User wants the most recent decisions first.

## 2026-09-08 — Move PhaseDecisions.md into PhaseResults/

**Summary.** `.claude/PhaseDecisions.md` → **`.claude/PhaseResults/PhaseDecisions.md`** (user
request). Content unchanged. Also made the file's append-only chronological order (oldest first,
new phases/questions appended to the end) an explicit written rule.

**Files changed**
- Moved `.claude/PhaseDecisions.md` → `.claude/PhaseResults/PhaseDecisions.md`.
- Path references updated in: `CLAUDE.md`, `.claude/Rule.md` (§3.3 tree, §3.4, §4.2, §7 tables),
  `.claude/FileIndex.md`, `.claude/Orders.md`, `.claude/PhaseResults/Readme.md`,
  `.claude/PhaseResults/Phase0{1..7}Result.md`, `.claude/Changelog.md`,
  `.claude/docs/{Phases,Architecture,LastAiAnswer,database-design}.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/commands/phases/*.md`,
  `src/Shared/Http/IdempotencyMiddleware.php`, `src/Shared/Application/ErrorLog/ErrorLogWriter.php`.
- `.claude/Rule.md` §4.2 + `CLAUDE.md` *Interactive Phase Rule* + `PhaseResults/PhaseDecisions.md`
  intro + `PhaseResults/Readme.md`: added the **append-only, chronological** rule.
- Fixed a stray `        د` prefix on `.claude/docs/Phases.md` line 1 (pre-existing typo).

**Reason.** Keep all per-phase history (results + decisions) together under `PhaseResults/`.

**Notes.** `struct.md` (user-owned) still shows the old `.claude/` root location and is left
as-is; the deviation is noted in `Rule.md` §3.3. No code behaviour change — the two PHP edits
are docblock comments only (`composer ci` still green).

## 2026-09-08 — Phase 7: Client API authentication & scoping

**Summary.** Gomrok's first real API surface: `/api/v1` group behind Bearer API-key auth, plus
`GET /api/v1/me`; `/health` stays public. Decisions (`PhaseResults/PhaseDecisions.md` Phase 7 Q1–Q5):
`Authorization: Bearer` only · a per-request `ClientContext` holder + request attributes ·
`last_used_at` written throttled (≤ 1/key/5 min) · `401 unauthorized` for any credential fault,
`403 client_disabled` for a valid key on a disabled client, generic bodies · `Idempotency-Key`
required on `/api/v1` writes + failed-attempt logging to a new table, **no rate limiting yet**.
**Schema confirmed by the user** before the migration.

**Files created**
- Migration `20260908170001_create_client_auth_attempts_table.php` → `CreateClientAuthAttemptsTable`.
- Shared\Http: `ClientAuthenticator` (port), `AuthResult`, `AuthenticatedClient`,
  `AuthRequestMeta`, `ClientContext`, `AuthenticationMiddleware`.
- Clients: `Domain/AuthFailureReason`; `Application/Authenticate/{ApiKeyAuthenticator,AuthAttempt,AuthAttemptLog}`;
  `Infrastructure/PdoAuthAttemptLog`.
- `src/Http/Api/MeAction.php` — `GET /api/v1/me`.
- Tests: `tests/Unit/Shared/Http/{ClientContextTest,AuthenticationMiddlewareTest}.php`,
  `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php`,
  `tests/Unit/Http/{MeActionTest,ApiRoutingTest}.php`,
  `tests/Integration/AuthAttemptsPersistenceTest.php`,
  `tests/Support/{StubClientAuthenticator,RecordingAuthAttemptLog,InMemoryClientDirectory}.php`.
- `.claude/PhaseResults/Phase07Result.md`.

**Files modified**
- `src/Config/routes.php` — `/api/v1` group with `AuthenticationMiddleware` + `IdempotencyMiddleware`;
  `/health` stays outside it.
- `src/Shared/Http/IdempotencyMiddleware.php` — `requireKeyOnWrites` flag (keyless write → 400).
- `src/Config/container.php` — `IdempotencyMiddleware` autowired with `requireKeyOnWrites: true`,
  `replayResolver: null`.
- `src/Bootstrap/AppFactory.php` — `create(?ContainerInterface $container = null)`.
- `src/Modules/Clients/Domain/ClientApiKeyRepository.php` (+ Pdo impl, + in-memory double) —
  `touchLastUsed()`.
- `src/Modules/Clients/Infrastructure/definitions.php` — binds `ClientAuthenticator`,
  `AuthAttemptLog`.
- `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php` — keyless-write 400 case.
  `tests/Integration/MigrationRoundTripTest.php` — `client_auth_attempts` in the table list.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 10 tables),
  `.claude/docs/Phases.md` (row 7 → ☑), `Architecture.md`, `FileIndex.md`, `knowledge/Knowledge.md`,
  `Commands.md`, `Orders.md` (D10), `PhaseResults/PhaseDecisions.md` (Phase 7 Q1–Q5).

**DB changes.** New table `client_auth_attempts` (FK to `clients(id)` SET NULL, three
`(*, created_at)` indexes). No changes to existing tables.

**Verification.** `composer ci` green — 124 unit tests, 383 assertions. PHPStan `max` +
strict-rules clean (175 files). php-cs-fixer clean. `composer test:integration` → 21 tests, all
self-skip (no Docker). Real HTTP responses captured: `GET /health` → 200
`{"status":"ok","service":"gomrok"}`; `GET /api/v1/me` no key → 401 +
`WWW-Authenticate: Bearer realm="gomrok"`; with a (stubbed) valid key → 200 client JSON.

**NOT verified here.** Migration + `AuthAttemptsPersistenceTest` against real MySQL — runs in
GitHub Actions, or `docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** None (all additive; `AppFactory::create()` gained an optional parameter).

## 2026-09-08 — Phase 6: Clients module (domain & persistence)

**Summary.** The tenant model — the first `src/Modules/` module. Decisions (`PhaseResults/PhaseDecisions.md`
Phase 6 Q1–Q5): API key = prefixed token + `sha256(secret)` looked up by a public `key_id` ·
client settings = typed columns on `clients` + a `client_endpoints` table · required immutable
`slug` · soft reversible `active`/`disabled` (keys untouched) · CLI commands + an
`APP_ENV`-gated dev seeder. **Schema confirmed by the user** before any migration.

**Files created**
- Migrations: `20260908150001..04_*` → `Create{Clients,ClientApiKeys,ClientEndpoints}Table`,
  `AddClientFksToCrossCuttingTables` (the Phase 5 `client_id` FKs). Seeder: `ClientsSeeder`.
- `src/Modules/Clients/Domain/*` — `Client`, `ClientEndpoint`, `ClientApiKey`, `ClientSlug`,
  `InvalidClientSlug`, `ClientStatus`, `ApiKeyStatus`, `ApiKeyPrefix`, `EndpointPurpose`,
  `ClientRepository`, `ClientApiKeyRepository`, `ApiKeyGenerator`, `ApiKeyToken`,
  `GeneratedApiKey`, `Events/{ClientCreated,ClientUpdated,ClientDisabled,ClientEnabled,ApiKeyIssued,ApiKeyRevoked}`.
- `src/Modules/Clients/Application/*` — `ClientDirectory`, `ClientSnapshot`, `ClientAuditSnapshot`,
  and `{CreateClient,UpdateClient,DisableClient,EnableClient,SetClientEndpoint,RemoveClientEndpoint,IssueApiKey,RevokeApiKey}/`
  (handler + command [+ result]).
- `src/Modules/Clients/Infrastructure/*` — `PdoClientRepository`, `PdoClientApiKeyRepository`,
  `PdoClientDirectory`, `RandomApiKeyGenerator`, `definitions.php`.
- Shared: `src/Shared/Application/{TokenGenerator,ReferenceCatalog,Transactions}.php`,
  `src/Shared/Infrastructure/RandomTokenGenerator.php`,
  `src/Shared/Infrastructure/Persistence/{PdoReferenceCatalog,Row}.php`,
  `src/Shared/Domain/DomainEvent.php`.
- CLI: `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php`.
- Tests: `tests/Unit/Modules/Clients/**` (Domain: ClientSlug, Client, ApiKeyToken, ClientApiKey;
  Infrastructure: RandomApiKeyGenerator; Application: CreateClientHandler, ClientHandlers),
  `tests/Integration/ClientsPersistenceTest.php`, `tests/Support/{SynchronousTransactions,
  InMemoryClientRepository,InMemoryClientApiKeyRepository,FixedTokenGenerator,
  InMemoryReferenceCatalog,RecordingAuditLogWriter}.php`.
- `.claude/PhaseResults/Phase06Result.md`.

**Files modified**
- `src/Shared/Infrastructure/Persistence/TransactionRunner.php` — implements the new
  `Transactions` port. `src/Bootstrap/ContainerFactory.php` — merges module `definitions.php`.
- `src/Config/container.php` — binds `TokenGenerator`, `ReferenceCatalog`, `Transactions`.
- `composer.json` — `client:create` / `client:issue-key` / `client:revoke-key` / `client:list`.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 9 tables),
  `.claude/docs/Phases.md` (row 6 → ☑), `Architecture.md`, `FileIndex.md`, `knowledge/Knowledge.md`,
  `Commands.md`, `Orders.md` (D9), `.env.example`, `PhaseResults/PhaseDecisions.md` (Phase 6 Q1–Q5).

**DB changes.** New tables `clients`, `client_api_keys`, `client_endpoints` (all FK to
`clients(id)` CASCADE). Added `fk_idempotency_keys_client_id` (CASCADE),
`fk_audit_logs_client_id` / `fk_error_logs_client_id` (SET NULL). Seeder `ClientsSeeder` is a
no-op outside `local` / `testing`.

**Verification.** `composer ci` green — 104 unit tests, 306 assertions. PHPStan `max` +
strict-rules clean (153 files). php-cs-fixer clean. `composer test:integration` → 19 tests, all
self-skip (no Docker). Migration classes load and extend the right Phinx bases; the DI container
builds and resolves the module ports (only the live DB connection fails here).

**NOT verified here.** Migrations + `ClientsPersistenceTest` + `ClientsSeeder` against real
MySQL — runs in GitHub Actions, or `docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** None (all additive; `TransactionRunner` gained an interface it already
satisfied).

## 2026-09-08 — Phase 5: Migration workflow & cross-cutting tables

**Summary.** The tables nearly every later module writes to, plus their ports/adapters, plus CI
that finally executes migrations against real MySQL. Decisions (`PhaseResults/PhaseDecisions.md` Phase 5
Q1–Q5): idempotency = lock + entity mapping (no stored response bodies) · audit = event + full
before/after row snapshots · error log = explicit writer only (no Monolog DB handler) ·
idempotency retention = `expires_at` + purge job · hardening = round-trip test + `db:reset` +
GitHub Actions CI. **Schema confirmed by the user** before any migration.

**Files created**
- Migrations: `src/Database/Migrations/2026090814000{1,2,3}_create_{idempotency_keys,audit_logs,error_logs}_table.php`
  → `Gomrok\Database\Migrations\Create{IdempotencyKeys,AuditLogs,ErrorLogs}Table`.
- Idempotency: `src/Shared/Application/Idempotency/{IdempotencyStore,IdempotencyRecord,IdempotencyStatus}.php`,
  `src/Shared/Infrastructure/Persistence/PdoIdempotencyStore.php`,
  `src/Shared/Http/{IdempotencyMiddleware,IdempotencyContext,IdempotentReplayResolver}.php`.
- Audit: `src/Shared/Application/Audit/{AuditLogWriter,AuditEntry,AuditActor}.php`,
  `src/Shared/Infrastructure/Persistence/PdoAuditLogWriter.php`.
- Error log: `src/Shared/Application/ErrorLog/{ErrorLogWriter,ErrorLogEntry,ErrorLogLevel}.php`,
  `src/Shared/Infrastructure/Persistence/{PdoErrorLogWriter,NullErrorLogWriter}.php`.
- `src/Shared/Infrastructure/SecretRedactor.php` (shared redaction helper).
- `src/Jobs/PurgeExpiredIdempotencyKeys.php`, `bin/PurgeIdempotencyKeys.php`.
- `src/Bootstrap/ContainerFactory.php` (extracted from `AppFactory`; shared by HTTP + CLI).
- `.github/workflows/Ci.yml`.
- Tests: `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php`,
  `tests/Unit/Shared/Infrastructure/SecretRedactorTest.php`,
  `tests/Unit/Shared/Application/Audit/AuditEntryTest.php`,
  `tests/Unit/Shared/Application/ErrorLog/ErrorLogEntryTest.php`,
  `tests/Unit/Jobs/PurgeExpiredIdempotencyKeysTest.php`,
  `tests/Support/InMemoryIdempotencyStore.php`,
  `tests/Integration/{MigrationRoundTripTest,CrossCuttingWritersTest}.php`.
- `.claude/PhaseResults/Phase05Result.md`.

**Files modified**
- `src/Shared/Http/JsonErrorHandler.php` — now takes `ErrorLogWriter` + `CorrelationId`; logs
  unhandled (non-HTTP) exceptions to `error_logs`.
- `src/Bootstrap/AppFactory.php` — uses `ContainerFactory`.
- `src/Config/container.php` — binds `IdempotencyStore`, `AuditLogWriter`, `ErrorLogWriter` to
  their PDO adapters.
- `composer.json` — `+ rollback:all`, `db:reset`, `db:fresh`, `idempotency:purge` scripts.
- `phpstan.neon`, `.php-cs-fixer.dist.php` — analyse/lint `bin/`.
- `tests/Unit/Shared/Http/JsonErrorHandlerTest.php` — new constructor args + error-log assertion.
- DB docs: `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md` (3 new tables, total 6). `.claude/docs/Phases.md` (row 5 → ☑),
  `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`,
  `.claude/Rule.md` (## Project Documents / §7), `.claude/docs/Architecture.md`.

**DB changes.** New tables `idempotency_keys`, `audit_logs`, `error_logs`. Business tables
(timestamps). `client_id` columns are unconstrained until Phase 6 adds the `clients` FKs. No
seeders. Migrations: `up()` creates, `down()` drops.

**Verification.** `composer ci` green — 63 unit tests, 190 assertions. PHPStan `max` +
strict-rules clean (76 files). php-cs-fixer clean. `composer test:integration` → 13 tests, all
self-skip (no Docker / local MariaDB rejects `gomrok`). Migration classes load and extend
`Phinx\Migration\AbstractMigration`. Container builds and resolves the non-DB services.

**NOT verified here.** `composer db:setup` / `db:reset` / `test:integration` against real MySQL
and the `Ci.yml` run — needs Docker or GitHub Actions. Run
`docker compose up -d mysql && composer db:reset && composer test:integration`, or let CI do it
on push.

**Breaking changes.** `JsonErrorHandler::__construct` gained two required parameters (internal;
autowired via the container).

## 2026-09-08 — Phase 4: Database foundations (reference tables)

**Summary.** Migration workflow + the three reference tables. Decisions (`PhaseResults/PhaseDecisions.md`
Phase 4 Q1–Q5): DB docs = the spec's kebab-case files + `mkdocs.yml` · namespaced Phinx
migrations, no base class · currencies from `brick/money`, countries from a bundled JSON ·
capability catalogue **deferred to Phase 8** · full ISO currencies + 18 curated countries.
**Schema confirmed by the user** before any migration.

**Files created**
- `src/Database/Migrations/20260908130001_create_currencies_table.php` (+ `..._countries_`,
  `..._provider_types_`) — `Gomrok\Database\Migrations\Create{Currencies,Countries,ProviderTypes}Table`.
- `src/Database/Seeds/{CurrenciesSeeder,CountriesSeeder,ProviderTypesSeeder}.php` +
  `src/Database/Seeds/data/countries.json` (18 rows).
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md`; root `mkdocs.yml`.
- `tests/Integration/ReferenceTablesTest.php`.
- `.claude/PhaseResults/Phase04Result.md`.

**Files modified**
- `phinx.php` — namespaced `paths` + collation. `composer.json` — `+ seed`, `db:setup` scripts;
  `exclude-from-classmap` for the migrations dir. Removed `src/Database/{Migrations,Seeds}/.gitkeep`.
- `.claude/Rule.md` §3.1 (exceptions: DB docs kebab-case, Phinx migration filenames), §3.3 note
  resolved, ## Project Documents row. `.claude/docs/Architecture.md` §4, `.claude/docs/Commands.md`,
  `.claude/docs/Phases.md` (Phase 4 scope trimmed + row → ☑; Phase 8 scope gains the capability
  catalogue), `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`.

**DB changes.** New tables `currencies`, `countries` (FK → `currencies.code`), `provider_types`.
Reference data only — no timestamps.

**Verification.** PHPStan `max` clean (incl. migrations/seeders), cs clean, `composer ci` green
(42 tests). Migration + seeder classes load and extend the correct Phinx bases; `countries.json`
validated (18 rows, every `default_currency` in `brick`'s list; brick has 166 currencies).
**Not verified:** `composer db:setup` / `migrate` / `rollback` and `ReferenceTablesTest` against
a real MySQL — no Docker daemon and the local MariaDB rejects the `gomrok` user. Run
`docker compose up -d mysql && composer db:setup && composer test:integration`.

**Migration notes.** `composer install` (autoload change), then `composer db:setup`.
**Breaking changes.** None.

## 2026-09-08 — Phase 3: Shared kernel

**Summary.** Built `src/Shared/**` — 15 classes every module will use. No business logic, no
schema. Decisions (`PhaseResults/PhaseDecisions.md` Phase 3 Q1–Q5): richer `Money` API · plain-int IDs (Q2,
tied to the Q3-of-Phase-1 change) · **hybrid** error model (`Result`/`DomainError` returned;
exceptions for bugs/infra) · **Monolog** logger · **PSR-20** clock.

**Files created**
- `src/Shared/Domain/` — `Money.php`, `Currency.php`, `CountryCode.php`, `Result.php`,
  `DomainError.php`, `ErrorType.php`.
- `src/Shared/Infrastructure/` — `SystemClock.php`, `CorrelationId.php`,
  `Logging/{LoggerFactory,CorrelationIdProcessor}.php`, `Persistence/TransactionRunner.php`.
- `src/Shared/Http/` — `Action.php`, `JsonResponder.php`, `JsonErrorHandler.php`,
  `CorrelationIdMiddleware.php`.
- `tests/Support/FrozenClock.php` + 11 unit test files + `tests/Integration/TransactionRunnerTest.php`.
- `.claude/PhaseResults/Phase03Result.md`.

**Files modified**
- `composer.json` / `composer.lock` — `+ monolog/monolog:^3`, `psr/clock:^1`, `brick/money:^0.10`;
  pinned `brick/math:~0.12.0` (avoids a `brick/money` internal deprecation).
- `src/Config/container.php` — bind `ClockInterface`, `LoggerInterface`, `ResponseFactoryInterface`.
- `src/Bootstrap/AppFactory.php` — add `CorrelationIdMiddleware` + `JsonErrorHandler`.
- `.claude/docs/Architecture.md` §4, `.claude/docs/Phases.md` (row → ☑), `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`.

**API changes.** All error responses are now JSON (was: possibly HTML). `GET /health` gains an
`X-Correlation-Id` response header.

**Verification.** `composer ci` green (42 tests, 113 assertions); PHPStan `max` clean; cs clean;
live `/health` returns JSON + `X-Correlation-Id` (inbound header reused); `/nope` → JSON 404.
Integration test (`TransactionRunnerTest`) written, **skipped** — no Docker daemon.

**Migration notes.** `composer install` after pulling. **Breaking changes.** None.

## 2026-09-08 — Decision change: simple integer IDs (no ULID / typed IDs)

**Summary.** User reversed the Phase 1 Q3 identifier decision. Now: every table PK is
`id INT UNSIGNED AUTO_INCREMENT` (from 1, **not `BIGINT`**); FKs plain `INT UNSIGNED`; **no**
ULID / UUID / typed-ID value objects / entity-specific ID classes; the same `int` in DB, PHP,
and API/callback/admin URLs. Isolation is enforced by authorization, not by unguessable IDs.
`BIGINT` still allowed for non-key columns (money `amount_minor`).

**Files modified**
- `.claude/PhaseResults/PhaseDecisions.md` — Phase 1 Q3 marked *changed* (Previously/Current/Changed/Reason);
  Phase 3 Q2 resolved as "no ID abstraction".
- `.claude/docs/Architecture.md` — §6 rewritten; §3 decision table; §4 folder layout; §7 Money
  note; §12 testing mention.
- `.claude/docs/Phases.md` — Phase 3 scope (drop `Ulid` / `UlidGenerator` / typed IDs).
- `.claude/Rule.md` §5 — new "Simple integer IDs" rule + client-scoped note.
- `.claude/knowledge/Knowledge.md`, `.claude/knowledge/TenantIsolation.md` — identifier sections.
- `.claude/Orders.md` — D3 superseded.
- `.claude/agents/DatabaseAgent.md` — **filled in** with the identifier rules + schema conventions
  + workflow (per the user's "add it to databaseagent.md").
- `.claude/FileIndex.md`; `.claude/PhaseResults/Phase01Result.md` + `Phase02Result.md` —
  forward-pointer / superseded markers (result files not rewritten).

**Reason.** User: "I do not want unnecessarily complex ID abstractions."
**Migration notes.** No code/schema exists yet — nothing to migrate.
**Breaking changes.** None (supersedes an unbuilt decision).

## 2026-09-08 — Removed `.claude/CLAUDE.md` pointer stub

**Summary.** Deleted the `.claude/CLAUDE.md` pointer stub (created from `struct.md`). The
project-root `CLAUDE.md` is the single entry point; a second file added only confusion.

**Files removed:** `.claude/CLAUDE.md`.
**Files modified:** `.claude/Rule.md` (§3.3 tree, "outside" note, ## Project Documents row),
`.claude/FileIndex.md` — references removed. `struct.md`'s `CLAUDE.md` entry is now noted as
covered by the root file.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-08 — Design folder moved into `.claude/docs/`

**Summary.** `Design/` (project root) → `.claude/docs/Design/`. PascalCase kept (our own sub-dir).
Contents (`GomrokAdminPanelV4.dc.html`, `GomrokAdminPanelV4Export.dc.html`, `Support.js`,
`Readme.md`) unchanged; the `.dc.html` `<script src="./Support.js">` stays correct (moved
together).

**Files modified** (references `Design/` → `.claude/docs/Design/`)
- `CLAUDE.md` (Documentation-directory note), `.claude/Rule.md` (§3.1, §3.3 tree + "outside"
  list, §3.4, ## Project Documents, §7), `.claude/FileIndex.md`, `.claude/docs/Ui.md`,
  `.claude/docs/LastAiAnswer.md`, `.claude/skills/FrontendSkill.md`,
  `.claude/docs/Design/Readme.md`.

**Migration notes.** Design bookmarks: `Design/…` → `.claude/docs/Design/…`.
**Breaking changes.** None.

## 2026-09-08 — Design: keep only v4

**Summary.** Deleted the superseded admin-panel design iterations; only v4 is kept.

**Files removed**
- `Design/GomrokAdminPanel.dc.html` (v1)
- `Design/GomrokAdminPanelV2.dc.html`
- `Design/GomrokAdminPanelV3.dc.html`

**Kept:** `Design/GomrokAdminPanelV4.dc.html` (the design), `Design/GomrokAdminPanelV4Export.dc.html`
(redundant export — same UI as v4), `Design/Support.js`, `Design/Readme.md`.

**Files modified**
- `Design/Readme.md` — file table trimmed to the kept files.

**Reason.** v1–v3 are no longer relevant; recoverable from git history if needed.
**Migration notes.** None. **Breaking changes.** None.

## 2026-09-07 — `.claude/` structure completed from `struct.md`

**Summary.** Built out the full `.claude/` tree described in `.claude/struct.md`, keeping every
existing file and its content untouched. `struct.md`'s `SCREAMING_CASE`/`kebab-case` names mapped
to PascalCase per `.claude/Rule.md` §3.1.

**Files created (51)**
- `.claude/CLAUDE.md` (pointer stub), `.claude/Orders.md` (requirements/decisions register).
- `.claude/agents/` — 10 `<Role>Agent.md` templates (Backend, Database, Deployment, Discovery,
  Docs, Frontend, Qa, Review, Security, Testing).
- `.claude/commands/` — `Implement.md Plan.md Refactor.md Review.md Spec.md`; `workflow/` (same 5,
  multi-agent variants); `phases/` (`Phase00Foundation`, `Phase01ProjectDiscovery`,
  `Phase02RepositoryBootstrap`, `PhaseTemplate`, `Readme`).
- `.claude/docs/` — `ProjectDescription Domain Permissions Ui Recommendations Deployment Server
  FeatureTemplate`.
- `.claude/knowledge/` — `SecurityRules TenantIsolation RolePermissionModel DeploymentRunbook
  DnsRecords LocalAssets MediaStorage PolicyTemplate`.
- `.claude/skills/` — `BackendSkill DatabaseSkill DeploymentSkill FrontendSkill GitSkill
  SecuritySkill TestingSkill SkillTemplate`.

**Files modified (additive only)**
- `.claude/Rule.md` — §3.3 tree + naming notes; ## Project Documents rows for the new families.
- `.claude/FileIndex.md` — new entries.

**Files preserved (unchanged):** every pre-existing `.claude/` file — `Rule.md` content,
`Changelog.md`, `PhaseResults/PhaseDecisions.md`, `FileIndex.md`, `docs/*`, `knowledge/Knowledge.md`,
`{agents,commands,skills}/Readme.md`, `PhaseResults/*`, and the project-root `CLAUDE.md`.

**Notes.** The agent files carry valid frontmatter and the command files carry `description`
frontmatter, so Claude Code will now surface ~10 subagents and ~15 slash commands — **all marked
TEMPLATE**. Deployment/Server/DNS/media/runbook files are deliberate empty placeholders (no
infrastructure decided). Nothing outside `.claude/` was touched (no source, tests, DB, Docker,
Composer).

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-07 — `PhaseResults/` moved into `.claude/`

**Summary.** `PhaseResults/` (project root) → `.claude/PhaseResults/`. Name stays PascalCase
(our own dir, not a Claude Code tool folder). Contents unchanged.

**Files modified** (references repointed `PhaseResults/` → `.claude/PhaseResults/`)
- `CLAUDE.md` (Phase Completion Rule, Documentation-directory note).
- `.claude/Rule.md` (§3.3 layout + text, §3.4, §7, ## Project Documents, §4.1).
- `.claude/FileIndex.md`, `.claude/PhaseResults/PhaseDecisions.md`, `.claude/docs/{Architecture,Phases}.md`,
  `.claude/PhaseResults/{Readme,Template,Phase01Result}.md`.

**Migration notes.** `PhaseResults/PhaseNNResult.md` → `.claude/PhaseResults/PhaseNNResult.md`.
**Breaking changes.** None.

## 2026-09-07 — Documentation reorganised into `.claude/`

**Summary.** Adopted the standard Claude Code project layout. `Documents/` retired; all docs now
live under `.claude/` (`Rule.md`, `Changelog.md`, `PhaseResults/PhaseDecisions.md`, `FileIndex.md` at the
root; `docs/`, `knowledge/`, plus empty `agents/`, `commands/`, `skills/` skeletons). Folder
skeleton adopted, existing docs adapted into it, PascalCase file names kept, `CLAUDE.md` left at
the project root, phase-tracking artefacts kept.

**Files moved** (`Documents/` → `.claude/`)
- `Rule.md`, `Changelog.md`, `PhaseResults/PhaseDecisions.md` → `.claude/`
- `Architecture.md`, `Commands.md`, `Phases.md`, `LastAiAnswer.md`, `ClaudeOld.md` → `.claude/docs/`
- `Knowledge.md` → `.claude/knowledge/`
- `Documents/` directory removed.

**Files created**
- `.claude/FileIndex.md` — repo-wide file map.
- `.claude/{agents,commands,skills}/Readme.md` — skeleton placeholders.

**Files modified** (references repointed to `.claude/…`)
- `CLAUDE.md` — "Documentation directory" note; naming-rule pointer.
- `.claude/Rule.md` — §3.1 (`.claude/` sub-dir naming), §3.3 rewritten around the `.claude/`
  layout, §3.4, §7, ## Project Documents (+ `FileIndex.md`, skeletons).
- `.claude/docs/{Architecture,Phases}.md`, `.claude/PhaseResults/PhaseDecisions.md`, `.claude/knowledge/Knowledge.md`,
  `PhaseResults/{Readme,Phase01Result,Phase02Result}.md`, `Design/Readme.md`.

**Reason.** Match the conventional Claude Code structure (native `agents/`/`commands/`/`skills/`)
and keep the project root clean.

**Migration notes.** Doc bookmarks change: `Documents/Phases.md` → `.claude/docs/Phases.md`, etc.
**Breaking changes.** None (no code references these paths).

## 2026-09-06 — Phase 2: project scaffold & toolchain

**Summary.** A bootable, testable, empty Slim 4 app. No business logic, no database tables.
Decisions: Docker Compose · PHP 8.4 · Phinx · PHPUnit 11 · PHPStan max + strict-rules +
php-cs-fixer PSR-12 · keep `src/Bootstrap` + `src/Http` as app-level dirs
(`Documents/PhaseDecisions.md` Phase 2 Q1–Q6).

**Files created**
- Root: `composer.json` (+ `composer.lock`), `.gitignore`, `.env.example`, `Dockerfile`,
  `docker-compose.yml`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `phinx.php`.
- `src/Config/{Settings.php, DatabaseSettings.php, container.php, routes.php}`,
  `src/Bootstrap/AppFactory.php`, `src/Http/HealthAction.php`, `src/Public/index.php`,
  `src/Database/{Migrations,Seeds}/.gitkeep`.
- `tests/Unit/SmokeTest.php`, `tests/Unit/Config/SettingsTest.php`,
  `tests/Unit/Http/HealthActionTest.php`, `tests/Integration/DatabaseConnectionTest.php`.
- `Documents/Commands.md`.
- `PhaseResults/Phase02Result.md`.

**Files modified**
- `Documents/Architecture.md` — §4 folder layout (adds `src/Bootstrap/`, `src/Http/`, splits
  `Database/`, notes lowercase config files).
- `Documents/Rule.md` — §3.1 exceptions (`Dockerfile`, `phpstan.neon`, `phinx.php`,
  `.php-cs-fixer.dist.php`, non-class config files); ## Project Documents (+ `Commands.md`).
- `Documents/PhaseDecisions.md`, `Documents/Phases.md` (Phase 2 row → ☑; stray `f` typo on the
  "Column meanings" line removed).

**Verification.** `composer test` OK (4/16); `composer stan` [OK] level max; `composer cs` clean;
`GET /health` → 200 `{"status":"ok","service":"gomrok"}`; `/nope` → 404. Integration test written
but **skipped** — no Docker daemon / no `gomrok` MySQL user in this environment.

**Migration notes.** Run `cp .env.example .env && composer install`. **Breaking changes.** None.

## 2026-09-06 — Phases.md: status table moved to the top

**Summary.** In `Documents/Phases.md`, the `## Status & execution tracking` section (column
meanings + the 30-phase table) was moved to the very top, immediately after the H1 and before
the intro / *How each phase runs* / *Database strategy* / the detailed phase sections. Table
content and phase data unchanged (row-by-row verified identical).

**Files modified**
- `Documents/Phases.md` — section reordered; one consequential wording fix: step 6 of *How each
  phase runs* now says "the *Status & execution tracking* table (top of file)" instead of "the
  table below".

**Reason.** So the current phase status is visible immediately on opening the file.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Project-document registry in Rule.md

**Summary.** Added a **## Project Documents** section to `Documents/Rule.md` — a table of every
documentation file (name, path, purpose) — plus **§3.5** requiring it to be kept in sync
automatically whenever a doc file is created / renamed / moved / removed.

**Files modified**
- `Documents/Rule.md` — new `## Project Documents` table (13 rows) + `### 3.5 Project-document
  registry` rule; §7 "Documents kept current" row for `Rule.md` extended.
- `CLAUDE.md` — "Documentation directory" note now points at the registry.

**Reason.** Single place to see what every doc file is for; keeps the doc set discoverable.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Documentation moved under `Documents/`

**Summary.** All project documentation moved out of the project root into a new root-level
`Documents/` directory, and this made a permanent rule in `Documents/Rule.md` §3.3.

**Files moved** (project root → `Documents/`)
- `Architecture.md`, `Changelog.md`, `ClaudeOld.md`, `Knowledge.md`, `LastAiAnswer.md`,
  `PhaseResults/PhaseDecisions.md`, `Phases.md`, `Rule.md`

**Left at the project root (intentional)**
- `CLAUDE.md` — the harness auto-loads `./CLAUDE.md`; moving it breaks that. It now points into
  `Documents/` for everything else.
- `PhaseResults/`, `Design/` — special-purpose directories, not documentation; unchanged.
- `.claude/docs/*` — the DB-docs location is a Phase 4 decision; unchanged for now.

**Files modified** (references updated to `Documents/…`)
- `CLAUDE.md` — every doc reference; new "Documentation directory" note; `LastAiAnswer.md` rule
  path; "Project Rules File" section.
- `Documents/Rule.md` — new **§3.3 Documentation directory** rule; §3.4 (was §3.3) paths;
  §1/§4/§7 references; §3.1 naming examples clarified.
- `Documents/Phases.md`, `Documents/PhaseDecisions.md`, `Documents/Architecture.md` — internal
  references.
- `Design/Readme.md`, `PhaseResults/Readme.md`, `PhaseResults/Phase01Result.md` — references
  (Phase01Result also carries a dated relocation note).

**Reason.** Keep the project root clean; make documentation location a permanent, enforced
convention.

**Migration notes.** Any external bookmark to a root-level doc path (e.g. `Phases.md`) is now
`Documents/Phases.md`. **Breaking changes.** None (no code depends on these paths yet).

## 2026-09-06 — Naming compliance: Changelog.md / Knowledge.md

**Summary.** Renamed two docs that had been created in all-caps to PascalCase per `Rule.md` §3.1.

**Files renamed**
- `CHANGELOG.md` → `Changelog.md`
- `KNOWLEDGE.md` → `Knowledge.md`

**Files modified** (references updated)
- `CLAUDE.md` — Changelog Rule + Phase Completion Rule + Expected Claude Behavior.
- `Rule.md` — §3.1 gained an explicit "all-caps community filenames are still PascalCased" bullet
  (`Changelog.md`, `Knowledge.md`, `Readme.md`, …); §4 and §7 references.
- `Phases.md` — *How each phase runs* step 5, Phase 1 + Phase 2 scope.
- `PhaseResults/Phase01Result.md` — recorded file paths corrected (error fix).

**Reason.** `CHANGELOG.md` / `KNOWLEDGE.md` are community conventions, not tool-mandated names, so
the PascalCase rule applies. `Rule.md` now says so explicitly to prevent recurrence.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Phase 1: architecture baseline

**Summary.** Established the target architecture from first principles (greenfield — no
predecessor system). No application code, no database.

**Files created**
- `Architecture.md` — hexagonal + modular architecture, module map, folder layout, cross-module
  communication (published interfaces + in-process domain events), identifier strategy
  (BIGINT PK + public ULID), money representation (`brick/money` + `Money` VO), provider-adapter
  model (core `PaymentProviderPort` + optional capability interfaces + `ProviderCapabilities`),
  resolution-pipeline sketches, payment lifecycle, cross-cutting concerns, testing approach,
  deferred items.
- `Changelog.md`, `Knowledge.md` — seeded (initially created as `CHANGELOG.md` / `KNOWLEDGE.md`;
  see the naming-compliance entry above).
- `PhaseResults/Phase01Result.md` — Phase 1 record.

**Files modified**
- `Phases.md` — Phase 1 tracking row filled (status ☑, start/end datetime, actual duration).

**Reason.** Lock the structural decisions every later phase depends on, via the 5 interactive
questions.

**Migration notes.** None. **Breaking changes.** None.
