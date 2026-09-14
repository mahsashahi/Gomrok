# Phase 24 — Payment creation flow

## Execution Summary

- Phase: 24 — Payment creation flow
- Start Datetime: 2026-09-13 09:00
- End Datetime: 2026-09-13 18:58
- Estimated Duration: 5–8h
- Actual Duration: N/A (work spanned multiple sessions/turns; no reliable hands-on clock was kept)
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

Phase 24 built the end-to-end "create a payment" path and everything CLAUDE.md's API design and
Phases.md named for it, across three increments:

1. **Payment creation + return flow.** `POST /api/v1/payments` (the full pipeline: create
   checkout attempt → resolve pricing → optionally reserve a voucher → select a provider →
   create the provider-hosted checkout session), `GET /payments/return` (public — the provider's
   return endpoint, signed-token identified, re-verifies status with the provider before
   redirecting to a per-client configured success/cancel URL), `GET /api/v1/payments/{id}` (plain
   read of current state), `GET /api/v1/payments/{id}/status` (authenticated on-demand live
   re-check).
2. **Cancel/refund/capture.** `POST /api/v1/payments/{id}/cancel`, `/refund`, `/capture`, each
   gated by both the resolved provider capability and the adapter's actual capability interface,
   resolving the correct `GatewayReference` to act on.
3. **A/B price-list visitor assignment** (Phase 15 Q4/Q5, deferred there and re-asked fresh here
   as Q6/Q7): persisted visitor→list bucketing, wired into the packages/pricing-resolve read
   endpoints.

## Files Created

**Increment 1 — creation & return flow**
- `src/Database/Migrations/20260913090001_add_checkout_attempt_id_to_gateway_references.php`
- `src/Database/Migrations/20260913090002_add_hash_return_token_to_checkout_attempts.php`
- `src/Modules/Checkout/Domain/CheckoutReturnToken.php`
- `src/Modules/Checkout/Application/CheckoutPayableAmount.php`, `ResolveCheckoutPayableAmount.php`
- `src/Modules/Checkout/Application/CreateProviderCheckout/{CreateProviderCheckoutCommand,CreateProviderCheckoutResult,CreateProviderCheckoutHandler}.php`
- `src/Modules/Checkout/Application/CreateCheckoutPayment/{CreateCheckoutPaymentCommand,CreateCheckoutPaymentResult,CreateCheckoutPaymentHandler}.php`
- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/{ReconcileCheckoutStatusResult,ReconcileCheckoutStatusHandler}.php`
- `src/Modules/Checkout/Application/ConfirmCheckoutReturn/{ConfirmCheckoutReturnCommand,ConfirmCheckoutReturnResult,ConfirmCheckoutReturnHandler}.php`
- `src/Http/Api/Payments{Create,Return,Show,Status}Action.php`
- Tests: `tests/Unit/Modules/Checkout/Domain/CheckoutReturnTokenTest.php`,
  `tests/Unit/Modules/Checkout/Application/{CreateProviderCheckoutHandlerTest,CreateCheckoutPaymentHandlerTest,ReconcileCheckoutStatusHandlerTest}.php`,
  `tests/Unit/Http/Payments{Create,Return,Show,Status}ActionTest.php`
- Test doubles: `tests/Support/{FakePaymentProviderPort,StubProviderAdapterFactory,InMemoryCheckoutAttemptDirectory,InMemoryPaymentDirectory}.php`

**Increment 2 — cancel/refund/capture**
- `src/Modules/Payments/Application/PaymentActionContext.php`, `ResolvePaymentActionContext.php`
- `src/Modules/Payments/Application/CancelPayment/{CancelPaymentCommand,CancelPaymentResult,CancelPaymentHandler}.php`
- `src/Modules/Payments/Application/RefundPayment/{RefundPaymentCommand,RefundPaymentResult,RefundPaymentHandler}.php`
- `src/Modules/Payments/Application/CapturePayment/{CapturePaymentCommand,CapturePaymentResult,CapturePaymentHandler}.php`
- `src/Http/Api/Payments{Cancel,Refund,Capture}Action.php`
- Tests: `tests/Unit/Modules/Payments/Application/{CapturePaymentHandlerTest,RefundPaymentHandlerTest,CancelPaymentHandlerTest}.php`,
  `tests/Unit/Http/Payments{Cancel,Refund,Capture}ActionTest.php`

**Increment 3 — A/B visitor assignment**
- `src/Database/Migrations/20260913090003_create_price_list_assignments_table.php`
- `src/Modules/Pricing/Domain/PriceListAssignment.php`, `PriceListAssignmentRepository.php`
- `src/Modules/Pricing/Infrastructure/PdoPriceListAssignmentRepository.php`
- `src/Modules/Pricing/Application/ResolveVisitorPriceListAssignment.php`
- `tests/Support/InMemoryPriceListAssignmentRepository.php`

## Files Modified

**Increment 1**
- `src/Modules/Payments/Domain/GatewayReference.php`, `GatewayReferenceRepository.php`,
  `Infrastructure/PdoGatewayReferenceRepository.php` — dual-nullable-parent columns
  (`checkoutAttemptId`/`paymentId`), `forCheckoutAttempt()`/`forPayment()` named constructors
  replacing the old `record()`.
- `src/Modules/Checkout/Domain/CheckoutAttempt.php`,
  `Infrastructure/PdoCheckoutAttemptRepository.php` — `hashReturnToken()`, `issueReturnToken()`.
- `src/Modules/Checkout/Application/CheckoutAttemptDirectory.php`,
  `Infrastructure/PdoCheckoutAttemptDirectory.php` — `findById()`.
- `src/Modules/Clients/Domain/EndpointPurpose.php` — `CheckoutSuccess`/`CheckoutCancel` cases.
- `src/Modules/Clients/Application/ClientDirectory.php`, `Infrastructure/PdoClientDirectory.php`
  — `findActiveEndpointUrl()`.
- `src/Modules/Payments/Application/CreatePayment/CreatePaymentHandler.php` — refactored to use
  `ResolveCheckoutPayableAmount`.
- `src/Shared/Domain/ErrorType.php`, `DomainError.php` — `UpstreamFailure` (HTTP 502).
- `src/Config/Settings.php`, `.env.example` — `appBaseUrl`, `checkoutReturnTokenSecret`
  (intentionally hardcoded, not env-backed yet).
- `src/Config/routes.php` — `GET /payments/return` (public) + the four authenticated routes.
- `src/Shared/Http/ClientContext.php` — `keyMode()`.

**Increment 2**
- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/ReconcileCheckoutStatusHandler.php`
  — gained a `ChangePaymentStatusHandler` dependency; drives a newly-created `Payment`
  `created → pending → paid`; persists a second `GatewayReference::forPayment(...,
  GatewayReferenceType::PaymentIntent, ...)` when `ProviderPaymentStatus::$paymentIntentReference`
  is non-null.
- `src/Config/routes.php` — three new POST routes.
- `tests/Support/FakePaymentProviderPort.php` — implements `SupportsRefunds`/`SupportsAuthCapture`;
  `mapProviderStatusToInternalStatus()` now actually maps instead of always `Pending`.
- `tests/Unit/Http/{PaymentsReturnActionTest,PaymentsStatusActionTest}.php`,
  `tests/Unit/Modules/Checkout/Application/ReconcileCheckoutStatusHandlerTest.php` — updated for
  the new constructor parameter.

**Increment 3**
- `src/Modules/Pricing/Application/PriceResolver.php` — gained `?string $visitorRef`; resolves a
  `priceListId` via `ResolveVisitorPriceListAssignment` when no explicit `priceListId` is given.
- `src/Modules/Pricing/Application/PriceCatalog.php` — gained `?string $visitorRef`; resolves the
  visitor's bucket once per catalogue request and applies it via `PriceListResolver` to every
  item (previously `/packages` never called `PriceListResolver` at all).
- `src/Http/Api/{PackagesAction,PackageDetailAction,PricingResolveAction}.php` — accept an
  optional `visitor_ref` query parameter.
- `src/Modules/Pricing/Infrastructure/definitions.php` — registered `PriceListAssignmentRepository`.
- `tests/Unit/Http/PackagesApiTest.php` — swapped in `InMemoryPriceListAssignmentRepository`
  (this functional test boots the real DI container; the new repository dependency would
  otherwise force a real `PDO` connection).
- Every other call site of `PriceResolver`/`PriceCatalog` in the test suite (10 files) updated for
  the new constructor parameters — no behavioral change, all pass `null`/an unused in-memory
  double.

**Documentation (all three increments):** `.claude/docs/Architecture.md`, `.claude/Changelog.md`,
`.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`, `.claude/docs/Phases.md`,
`.claude/PhaseResults/PhaseDecisions.md`, `.claude/docs/database-design.md`,
`.claude/docs/database-diagram.md`/`.html`, `.claude/docs/db_explain.md`,
`.claude/docs/last_ai_answer.md`.

## Implementation Details

- **`CheckoutReturnToken`** (pure domain VO): `return_token = {checkout_attempt_id}_{hash}`,
  `hash = HMAC-SHA256(checkout_attempt_id, secret)`, `hash_equals()` verification. Secret bound
  once via `Settings::$checkoutReturnTokenSecret` (hardcoded `"gomrokimo"` for now, by explicit
  instruction).
- **`ReconcileCheckoutStatusHandler`** is the single shared core for "ask the provider, transition
  the attempt, create/advance the Payment" — used by both the public return endpoint and the
  authenticated status poll. Idempotent: an already-terminal attempt short-circuits without a
  second provider call.
- **`ResolvePaymentActionContext`** resolves the adapter, resolved `ProviderCapabilities`, and the
  `GatewayReference` value a cancel/refund/capture call needs, preferring a "deeper"
  `GatewayReferenceType::PaymentIntent` reference (Stripe's PaymentIntent id; PayPal's capture id,
  surfaced through the same `ProviderPaymentStatus::$paymentIntentReference` field) and falling
  back to the original `CheckoutSession` reference when none was recorded (Mollie: one id serves
  every action).
- **`CancelPaymentHandler`/`RefundPaymentHandler`/`CapturePaymentHandler`** each validate the
  payment's current status, resolve the provider context, check capability (both the resolved
  `ProviderCapabilityResolver` capability and an `instanceof SupportsRefunds`/`SupportsAuthCapture`
  guard), call the adapter, then delegate the actual state transition + audit to the existing
  Phase 20 `RecordProviderTransactionHandler`.
- **`ResolveVisitorPriceListAssignment`**: on first sight for a `(pricingGroupId, visitorRef)`
  pair, buckets by `hexdec(substr(SHA-256(pricingGroupId . ':' . visitorRef), 0, 8)) %
  count(enabledLists)` over `PriceListRepository::enabledForGroup()` (control first, then by id),
  persists via `PdoPriceListAssignmentRepository::insertOrGetExisting()` (`INSERT ... ON DUPLICATE
  KEY UPDATE id = LAST_INSERT_ID(id)`, so a concurrent first-visit race always resolves to one
  authoritative row instead of throwing). A later read of a since-disabled bucket reassigns to
  control (`reassigned_at` stamped). Degrades to `null` — never throws — when the pricing group
  has no price list at all.

## Database Changes

- `gateway_references.checkout_attempt_id` — nullable INT UNSIGNED, FK → `checkout_attempts(id)`
  CASCADE (migration `20260913090001`).
- `checkout_attempts.hash_return_token` — nullable VARCHAR(64) (migration `20260913090002`).
- `price_list_assignments` — new table: `id`, `client_id`, `pricing_group_id`,
  `visitor_ref_hash` VARCHAR(64), `price_list_id`, `assigned_at`, `reassigned_at`, `created_at`,
  `updated_at`; `UNIQUE (pricing_group_id, visitor_ref_hash)`; FKs to `clients`/`pricing_groups`/
  `price_lists` (migration `20260913090003`).

All three migrations are additive; none rename or drop a column, none are destructive.

## API Changes

- `POST /api/v1/payments` (new) — creates a checkout attempt and provider-hosted checkout;
  returns `201 {checkout_attempt_id, status, redirect_url, provider_reference}`.
- `GET /payments/return` (new, public) — `302` to the client's configured success/cancel URL, or
  `200 {checkout_attempt_id, status}` if none is configured.
- `GET /api/v1/payments/{id}` (new) — the checkout attempt's or payment's current state.
- `GET /api/v1/payments/{id}/status` (new) — live re-check against the provider.
- `POST /api/v1/payments/{id}/cancel` (new) — `200 {checkout_attempt_id, payment_id, status}`.
- `POST /api/v1/payments/{id}/refund` (new) — optional `amount_minor` body field; `200
  {checkout_attempt_id, payment_id, status, provider_reference, refunded_minor}`.
- `POST /api/v1/payments/{id}/capture` (new) — optional `amount_minor` body field; `200
  {checkout_attempt_id, payment_id, status, provider_reference}`.
- `GET /api/v1/packages`, `GET /api/v1/packages/{packageId}`, `GET /api/v1/pricing/resolve` —
  each gained an optional `visitor_ref` query parameter that resolves and persists an A/B
  price-list assignment.

## Tests and Validation

- Tests created across the phase: `CheckoutReturnTokenTest` (11), `CreateProviderCheckoutHandlerTest`
  (6), `CreateCheckoutPaymentHandlerTest` (4), `ReconcileCheckoutStatusHandlerTest` (5),
  `PaymentsCreateActionTest` (3), `PaymentsReturnActionTest` (4), `PaymentsShowActionTest` (2),
  `PaymentsStatusActionTest` (2), `CapturePaymentHandlerTest` (5), `RefundPaymentHandlerTest` (7),
  `CancelPaymentHandlerTest` (3), `PaymentsCancelActionTest` (2), `PaymentsRefundActionTest` (2),
  `PaymentsCaptureActionTest` (2), plus new cases added to `PriceCatalogTest`, `PriceResolverTest`,
  and `PackagesApiTest` for the A/B visitor-assignment wiring (stability across calls,
  disable-fallback reassignment, no-price-list-at-all degradation, end-to-end `visitor_ref`
  acceptance on `/packages` and `/pricing/resolve`).
- Tests modified: `CreatePaymentHandlerTest` (refactored constructor); every call site of
  `PriceResolver`/`PriceCatalog` across ~10 test files (new constructor parameters, no behavior
  change).
- Commands actually run at the end of this phase:
  - `composer cs` (php-cs-fixer, dry run) → `Found 0 of 758 files that can be fixed.`
  - `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`
  - `vendor/bin/phpunit` → `Tests: 561, Assertions: 1870, Skipped: 39.` (all passing; the 39
    skips are the pre-existing `tests/Integration/*` suite self-skipping because this local
    environment's MySQL/MariaDB is up but the `gomrok` user's credentials currently don't
    authenticate — an environment condition unrelated to any code in this phase, verified by
    directly querying the running MariaDB server and getting `Access denied for user
    'gomrok'@'localhost'`).

## Technical Decisions

Full verbatim questions/options/selections are in `PhaseResults/PhaseDecisions.md` (Phase 24
Q1–Q7). Summary:

- **Q1** — `gateway_references` dual-nullable-parent columns (`checkout_attempt_id`/`payment_id`)
  instead of a separate pre-payment reference table.
- **Q2** — Gomrok owns the return URL; the provider redirects back to Gomrok's own endpoint
  first, which re-verifies real status before redirecting onward.
- **Q3** — the onward redirect target is a per-client, admin-configured URL, never a
  client-supplied parameter.
- **Q4** — the return token format was dictated exactly by the user:
  `{checkout_attempt_id}_{hash}`, HMAC-SHA256, hardcoded secret for now.
- **Q5** — refund/capture/cancel resolve the acting `GatewayReference` via reference-type-per-
  action (prefer the deeper `PaymentIntent`-type reference, fall back to `CheckoutSession`).
- **Q5b** — capability gating checks both the resolved provider capability and the adapter's
  actual capability interface.
- **Q6** (re-ask of Phase 15 Q4, full option list) — persisted `price_list_assignments`.
- **Q7** (re-ask of Phase 15 Q5, full option list) — both `/packages` and `/pricing/resolve`
  persist the assignment (diverges from the original recommendation).

## Problems Encountered

1. A newly created `Payment` was never driven past its initial `created` status —
   `CreatePaymentHandler` always creates at `Created`, and nothing called
   `ChangePaymentStatusHandler` afterward, even though the only caller
   (`ReconcileCheckoutStatusHandler`) only reaches that code path because the provider already
   confirmed the payment as paid.
2. Stripe's PaymentIntent id (and PayPal's capture id, surfaced through the same
   `ProviderPaymentStatus::$paymentIntentReference` field) was computed by every
   `getPaymentStatus()` call and then discarded — nothing persisted it, so refund/capture/cancel
   would have had no way to look it up.
3. `PaymentStatus::allowedNextStatuses()` has no direct `created → paid` edge (only
   `pending`/`requires_action`/`authorized` can reach `paid`) — an initial fix that transitioned
   straight from `created` to `paid` failed 4 tests with `payment.invalid_transition`.
4. `ResolveVisitorPriceListAssignment`'s first draft used `\assert($control !== null)` after
   `findControlForGroup()`, which crashes for a pricing group with no price list at all (several
   unit tests build `PricingGroup` directly via `PricingGroup::define()`, bypassing
   `CreatePricingGroupHandler`'s auto-created control row).
5. `tests/Unit/Http/PackagesApiTest.php` (a functional test that boots the real DI container)
   started failing with a 500 (`PDOException: Access denied for user 'gomrok'@'localhost'`) after
   `PriceCatalog`/`PriceResolver` gained the new `ResolveVisitorPriceListAssignment` dependency —
   the container had no in-memory override for the new `PriceListAssignmentRepository`, so PHP-DI
   eagerly constructed a real `PDO` connection just to satisfy the constructor.

## Resolutions

1. `ReconcileCheckoutStatusHandler` now calls `ChangePaymentStatusHandler` right after creating
   the `Payment`, advancing it `created → pending → paid` in two hops.
2. `ReconcileCheckoutStatusHandler` persists `GatewayReference::forPayment(...,
   GatewayReferenceType::PaymentIntent, ...)` whenever `$status->paymentIntentReference` is
   non-null.
3. Changed the transition to two `ChangePaymentStatusHandler::handle()` calls (`Pending` then
   `Paid`) instead of one direct call to `Paid`.
4. Changed `ResolveVisitorPriceListAssignment::forVisitor()`/`bucket()` to return `?int` and
   degrade to `null` (no list resolved → `PriceListResolver::apply()` leaves the base price
   untouched) instead of asserting a control row must exist.
5. Added `$container->set(PriceListAssignmentRepository::class, new
   InMemoryPriceListAssignmentRepository());` to `PackagesApiTest.php`'s container setup,
   matching the existing pattern for every other pricing repository that test already swaps.

## Deferred Work

- `visitor_ref` is not wired into `POST /api/v1/payments` or the checkout pricing pipeline
  (`ResolveCheckoutPricingHandler`) — Q7 named exactly two endpoints. A client that wants the
  checkout price to match what `/packages`/`/pricing/resolve` displayed for a given visitor must
  pass the same `visitor_ref` itself; formally wiring it into checkout is future work if needed.
- A refund's own provider-issued reference (e.g. a Stripe/Mollie/PayPal refund id) is recorded
  only inside `provider_transactions.response_payload`, not as its own `GatewayReference` row — a
  dedicated `refunds` table (on CLAUDE.md's Required Database Concepts list) needs its own
  database-design confirmation before that's built; out of scope for this phase.
- Even-split distribution of the visitor-bucketing hash across a large simulated population was
  not separately load-tested — verified only at the unit level (same visitor → same bucket; a
  disabled bucket reassigns to control).

## Final Result

Phase 24's full scope is implemented, tested, and documented: `POST /api/v1/payments` creates a
checkout attempt end-to-end through a real provider-hosted checkout session; the public return
endpoint verifies real payment status before redirecting to a per-client success/cancel URL;
`GET /api/v1/payments/{id}` and `/status` address the whole pre- and post-payment lifecycle by one
stable id; `cancel`/`refund`/`capture` are capability-gated and resolve the correct provider
reference to act on; and the deferred Phase 15 A/B visitor-assignment mechanism is built and wired
into the catalogue/pricing read endpoints. The full test suite (561 tests, 1870 assertions) passes,
`composer cs`/`phpstan` are clean, and every decision (Q1–Q7) is recorded in
`PhaseResults/PhaseDecisions.md`. No commit has been made for the A/B-assignment increment yet —
increments 1 and 2 were committed earlier in commit `1d19ac4`.
