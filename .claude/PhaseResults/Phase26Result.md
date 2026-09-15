# Phase 26 — Subscriptions module

## Execution Summary

- Phase: 26 — Subscriptions module
- Start Datetime: N/A (not tracked this session)
- End Datetime: N/A (not tracked this session)
- Estimated Duration: 6–9h
- Actual Duration: N/A
- Tokens Used: N/A
- Final Status: Complete, with known limitations

## Work Completed

Built the whole Subscriptions module by reusing the Checkout pipeline (Q1) rather than a parallel
one: a `Subscription` aggregate with an explicit branching status graph, its ownership-model
queries, `subscription_events` (a write-once log), and `subscription_payment_links` (the join
tying a subscription to every `Payment` charged under it). Added a second `Payment`-creation path
(Q2) for renewal charges, requiring `payments.checkout_attempt_id` to become nullable. Built
`POST /api/v1/subscriptions`, `GET /api/v1/subscriptions/{id}`, and
`POST /api/v1/subscriptions/{id}/cancel`, all mirroring the Phase 24 Payments endpoints'
addressing and guard patterns exactly. Deliberately did **not** wire webhook-driven renewal
automation this phase (see Deferred Work) — `RecordSubscriptionPaymentHandler` is built and fully
tested as the reusable unit a future trigger will call unchanged.

## Files Created

- `src/Database/Migrations/20260914120001_create_subscriptions_tables.php`
- `src/Database/Migrations/20260914120002_make_payments_checkout_attempt_id_nullable.php`
- `src/Database/Migrations/20260914120003_add_subscription_id_to_gateway_references.php`
- `src/Modules/Subscriptions/Domain/Subscription.php`
- `src/Modules/Subscriptions/Domain/SubscriptionStatus.php`
- `src/Modules/Subscriptions/Domain/SubscriptionRepository.php`
- `src/Modules/Subscriptions/Domain/SubscriptionEvent.php`
- `src/Modules/Subscriptions/Domain/SubscriptionEventRepository.php`
- `src/Modules/Subscriptions/Domain/SubscriptionPaymentLink.php`
- `src/Modules/Subscriptions/Domain/SubscriptionPaymentLinkRepository.php`
- `src/Modules/Subscriptions/Infrastructure/PdoSubscriptionRepository.php`
- `src/Modules/Subscriptions/Infrastructure/PdoSubscriptionEventRepository.php`
- `src/Modules/Subscriptions/Infrastructure/PdoSubscriptionPaymentLinkRepository.php`
- `src/Modules/Subscriptions/Infrastructure/PdoSubscriptionDirectory.php`
- `src/Modules/Subscriptions/Infrastructure/definitions.php`
- `src/Modules/Subscriptions/Application/SubscriptionSummary.php`
- `src/Modules/Subscriptions/Application/SubscriptionDirectory.php`
- `src/Modules/Subscriptions/Application/SubscriptionAuditSnapshot.php`
- `src/Modules/Subscriptions/Application/SubscriptionActionContext.php`
- `src/Modules/Subscriptions/Application/ResolveSubscriptionActionContext.php`
- `src/Modules/Subscriptions/Application/CreateSubscription/CreateSubscriptionCommand.php`
- `src/Modules/Subscriptions/Application/CreateSubscription/CreateSubscriptionResult.php`
- `src/Modules/Subscriptions/Application/CreateSubscription/CreateSubscriptionHandler.php`
- `src/Modules/Subscriptions/Application/CancelSubscription/CancelSubscriptionCommand.php`
- `src/Modules/Subscriptions/Application/CancelSubscription/CancelSubscriptionResult.php`
- `src/Modules/Subscriptions/Application/CancelSubscription/CancelSubscriptionHandler.php`
- `src/Modules/Subscriptions/Application/RecordSubscriptionPayment/RecordSubscriptionPaymentCommand.php`
- `src/Modules/Subscriptions/Application/RecordSubscriptionPayment/RecordSubscriptionPaymentResult.php`
- `src/Modules/Subscriptions/Application/RecordSubscriptionPayment/RecordSubscriptionPaymentHandler.php`
- `src/Modules/Checkout/Application/CreateProviderSubscription/CreateProviderSubscriptionCommand.php`
- `src/Modules/Checkout/Application/CreateProviderSubscription/CreateProviderSubscriptionResult.php`
- `src/Modules/Checkout/Application/CreateProviderSubscription/CreateProviderSubscriptionHandler.php`
- `src/Modules/Checkout/Application/CreateCheckoutSubscription/CreateCheckoutSubscriptionCommand.php`
- `src/Modules/Checkout/Application/CreateCheckoutSubscription/CreateCheckoutSubscriptionResult.php`
- `src/Modules/Checkout/Application/CreateCheckoutSubscription/CreateCheckoutSubscriptionHandler.php`
- `src/Http/Api/SubscriptionsCreateAction.php`
- `src/Http/Api/SubscriptionsShowAction.php`
- `src/Http/Api/SubscriptionsCancelAction.php`
- `tests/Support/InMemorySubscriptionRepository.php`
- `tests/Support/InMemorySubscriptionEventRepository.php`
- `tests/Support/InMemorySubscriptionPaymentLinkRepository.php`
- `tests/Support/InMemorySubscriptionDirectory.php`
- `tests/Unit/Modules/Subscriptions/Application/CreateSubscriptionHandlerTest.php`
- `tests/Unit/Modules/Subscriptions/Application/RecordSubscriptionPaymentHandlerTest.php`
- `tests/Unit/Modules/Subscriptions/Application/CancelSubscriptionHandlerTest.php`
- `tests/Unit/Modules/Subscriptions/Application/SubscriptionOwnershipTest.php`
- `tests/Unit/Modules/Checkout/Application/CreateProviderSubscriptionHandlerTest.php`
- `tests/Unit/Modules/Checkout/Application/CreateCheckoutSubscriptionHandlerTest.php`
- `tests/Unit/Http/SubscriptionsCreateActionTest.php`
- `tests/Unit/Http/SubscriptionsShowActionTest.php`
- `tests/Unit/Http/SubscriptionsCancelActionTest.php`

## Files Modified

- `src/Modules/Checkout/Application/ReconcileCheckoutStatus/ReconcileCheckoutStatusHandler.php`
  — new `CreateSubscriptionHandler` constructor dependency; after creating a `Confirmed`
  attempt's first `Payment`, if `attempt->purchaseType() === PurchaseType::Subscription` it also
  calls `CreateSubscriptionHandler`, passing `status->subscriptionReference` through.
- `src/Modules/Payments/Domain/GatewayReference.php` — added `public ?int $subscriptionId`
  (third nullable parent, alongside `checkoutAttemptId`/`paymentId`) and a new
  `forSubscription()` named constructor.
- `src/Modules/Payments/Domain/GatewayReferenceRepository.php` — added
  `forSubscription(int $subscriptionId): array`.
- `src/Modules/Payments/Infrastructure/PdoGatewayReferenceRepository.php` — `subscription_id` in
  the INSERT and `hydrate()`; implemented `forSubscription()`.
- `src/Modules/Payments/Domain/Payment.php` — `checkoutAttemptId` changed from `int` to `?int`
  everywhere (constructor property, `create()`, `fromStorage()`, `checkoutAttemptId()` getter);
  class docblock documents why (Q2).
- `src/Modules/Payments/Infrastructure/PdoPaymentRepository.php` — `hydrate()` uses
  `Row::nullableInt()` for `checkout_attempt_id`.
- `src/Modules/Payments/Application/PaymentSummary.php` — `checkoutAttemptId` param is `?int`.
- `src/Modules/Payments/Infrastructure/PdoPaymentDirectory.php` — `toSummary()` uses
  `Row::nullableInt()`.
- `src/Modules/Payments/Application/ResolvePaymentActionContext.php` — `forPayment()` returns
  `null` early when `checkoutAttemptId` is `null` (a renewal-originated payment); docblock
  documents this as an accepted Phase 26 limitation.
- `src/Modules/Providers/Application/Adapter/ProviderPaymentStatus.php` — added
  `public ?string $subscriptionReference = null` (5th constructor param).
- `src/Modules/Providers/Infrastructure/Adapter/Stripe/StripeAdapter.php` — `getPaymentStatus()`
  extracts the checkout session's `subscription` field and passes it as `subscriptionReference`.
- `src/Bootstrap/ContainerFactory.php` — added
  `'src/Modules/Subscriptions/Infrastructure/definitions.php'` to `MODULE_DEFINITIONS`.
- `src/Config/routes.php` — `POST /subscriptions`, `GET /subscriptions/{id}`,
  `POST /subscriptions/{id}/cancel` registered inside the existing authenticated `/api/v1` group.
- `tests/Support/FakePaymentProviderPort.php` — now also implements `SupportsSubscriptions`
  (`createSubscription()`, `getSubscriptionStatus()`, `cancelSubscription()`,
  `mapProviderSubscriptionStatusToInternalStatus()`), plus `throwOnCreateSubscription()`/
  `throwOnCancelSubscription()`, `createSubscriptionResult()`/`subscriptionStatusResult()`,
  `lastCreateSubscriptionCommand`/`lastCancelSubscriptionReference`.
- `tests/Unit/Modules/Checkout/Application/ReconcileCheckoutStatusHandlerTest.php`,
  `tests/Unit/Http/PaymentsStatusActionTest.php`, `tests/Unit/Http/PaymentsReturnActionTest.php`
  — updated to construct the new `CreateSubscriptionHandler` dependency
  `ReconcileCheckoutStatusHandler` now requires.
- `.claude/docs/Architecture.md` — new "Subscriptions (Phase 26 — complete, with known
  limitations)" subsection; module map table's Subscriptions row updated; several stale
  Phase 20/25 "deferred to Phase 26" notes in §13 corrected to reflect what actually happened.
- `.claude/docs/database-design.md` — new "Subscriptions (Phase 26)" section (three tables +
  lifecycle + resolution); `payments.checkout_attempt_id` and `gateway_references` entries
  updated; three new migration rows appended.
- `.claude/docs/database-diagram.md` / `.html` — module map updated (`Subscriptions` node now
  "done"); new Subscriptions ER diagram section in both files; `payments`/`gateway_references`
  diagrams updated for the nullable column and third parent.
- `.claude/docs/db_explain.md` — new "Subscriptions (Phase 26)" narrative section; `payments` and
  `gateway_references` entries updated.
- `.claude/Changelog.md` — new Phase 26 entry (prepended); the Phase 25 entry's stale
  "Subscriptions doesn't exist yet" note corrected.
- `.claude/FileIndex.md` — new/updated rows for every file above.
- `.claude/knowledge/Knowledge.md` — new "Subscriptions module (Phase 26, complete)" section
  capturing five reusable patterns (see Technical Decisions).
- `.claude/docs/Phases.md` — Phase 26's tracking-table row marked complete; its roadmap section
  rewritten in the completed-phase format (Decisions/Built/DB/Exit/Known limitations, matching
  Phase 25's own format); the Phase 25 section's stale forward-reference and two other stale
  Phase 26 forward-references elsewhere in the file corrected.
- `.claude/PhaseResults/PhaseDecisions.md` — added an addendum under the existing Phase 26 Q1-Q4
  section recording the mid-phase "payment_method nullable, skip country column" correction.

## Implementation Details

- **`Subscription`** (Domain aggregate): `create()` (computes `trialEndsAt`/initial status from
  `hasTrial`/`trialDays`), `fromStorage()`, `transitionTo()` (same guard shape as `Payment`:
  terminal rejects everything, same-status is a no-op, illegal transition rejected;
  `PastDue` stores `errorCode`/`errorMessage`, every other transition clears them),
  `recordPeriod()`. `id` is `null` until persisted (`assignId()`).
- **`SubscriptionStatus`**: `Active`/`Trialing`/`PastDue`/`Cancelled`, `allowedNextStatuses()`:
  `Trialing → [Active, PastDue, Cancelled]`; `Active → [PastDue, Cancelled]`;
  `PastDue → [Active, Cancelled]`; `Cancelled → []` (terminal).
- **`CreateSubscriptionHandler::handle(CreateSubscriptionCommand)`**: idempotent by
  `checkout_attempt_id` (`findByCheckoutAttemptId()` short-circuits with the existing row);
  requires the attempt to exist for the calling client, have a non-null `clientUserRef`
  (Q4) and `subscriptionInterval`, and a resolved pricing/routing decision; resolves trial terms
  via `PackagePurchaseCapabilityResolver::forId($packageId, $country)->for(PurchaseType::Subscription)`
  — never from the request; saves the `Subscription`, links the originating `Payment` via
  `SubscriptionPaymentLink::link()`, records a `created` `SubscriptionEvent`, and — when the
  command carries a `subscriptionProviderReference` — saves a
  `GatewayReference::forSubscription()` row; audits `subscription.created`.
- **`RecordSubscriptionPaymentHandler::handle(RecordSubscriptionPaymentCommand)`** (Q2's second
  creation path): idempotent by `providerPaymentReference` — checks
  `GatewayReferenceRepository::findByReference($providerAccountId, PaymentIntent, $reference)`
  before creating anything; if found, returns the existing payment's outcome
  (`alreadyRecorded: true`), no new row. Otherwise loads the subscription's origin
  `CheckoutAttempt` for `country` (since `subscriptions` has none), creates the `Payment` via
  `Payment::create(..., checkoutAttemptId: null, ...)`, links it via
  `SubscriptionPaymentLink`, records the deep `GatewayReference::forPayment()` (type
  `PaymentIntent`, doubling as the idempotency key), then drives the payment
  `created → pending → paid|failed` through the existing (unchanged)
  `RecordProviderTransactionHandler`, and finally transitions the subscription itself to
  `Active` (paid) or `PastDue` (failed, storing `error_code`/`error_message`), recording a
  `renewed`/`charge_failed` `SubscriptionEvent`.
- **`CancelSubscriptionHandler::handle(CancelSubscriptionCommand)`**: rejects an already-`Cancelled`
  subscription (`subscription.already_cancelled`) before contacting the provider; resolves
  context via `ResolveSubscriptionActionContext::forSubscription()`; requires both
  `Capability::SubscriptionCancel` (resolved for the subscription's own `providerAccountId` +
  `paymentMethod`) and `adapter instanceof SupportsSubscriptions`; calls
  `adapter->cancelSubscription($providerReference)`; transitions to `Cancelled`, records a
  `cancelled` `SubscriptionEvent`, audits `subscription.cancelled`.
- **`ResolveSubscriptionActionContext::forSubscription()`**: resolves the adapter/capabilities
  directly from the subscription's own `providerAccountId` (no routing-snapshot lookup needed,
  unlike `ResolvePaymentActionContext`); reference resolution prefers the real
  `GatewayReferenceType::Subscription` row (`forSubscription()` lookup), falling back to the
  `CheckoutSession` reference recorded under the subscription's `checkoutAttemptId` when no
  deeper reference exists (Mollie's provisional case).
- **`CreateProviderSubscriptionHandler`**: mirrors `CreateProviderCheckoutHandler` but rejects a
  non-`Subscription` purchase type (`checkout_attempt.not_a_subscription`), requires a non-null
  `subscriptionInterval`, and — after resolving the adapter — requires
  `$adapter instanceof SupportsSubscriptions` (`checkout_attempt.subscriptions_not_supported`)
  before calling `createSubscription()`.
- **`CreateCheckoutSubscriptionHandler`**: mirrors `CreateCheckoutPaymentHandler`'s pipeline
  exactly, pinning `purchaseType: PurchaseType::Subscription->value` and calling
  `CreateProviderSubscriptionHandler` as the terminal step instead of
  `CreateProviderCheckoutHandler`.
- **`SubscriptionsCreateAction`/`SubscriptionsShowAction`/`SubscriptionsCancelAction`**: thin
  HTTP wrappers, addressing every route by the checkout attempt id (`{id}`), mirroring the
  Payments endpoints exactly — `SubscriptionsShowAction` reports the checkout attempt's own
  pre-conversion state until a `subscriptions` row exists; `SubscriptionsCancelAction` resolves
  `{id}` → the real `subscriptions.id` via `SubscriptionDirectory::findByCheckoutAttemptId()`
  before calling the handler.

## Database Changes

- `subscriptions` (new): `id`, `client_id`, `client_user_ref` (`NOT NULL`), `checkout_attempt_id`
  (`NOT NULL`, `UNIQUE`), `package_id`, `provider_account_id`, `currency_code`, `amount_minor`,
  `payment_method` (nullable), `subscription_interval` (`NOT NULL`), `status` (default
  `active`), `trial_ends_at`, `current_period_start`, `current_period_end`, `error_code`,
  `error_message`, `created_at`, `updated_at`. No `country` column. FKs to `clients`,
  `checkout_attempts`, `packages`, `provider_accounts`, `currencies`.
- `subscription_events` (new): `id`, `subscription_id`, `kind`, `provider_status_raw`
  (nullable), `payload` (nullable JSON), `created_at`. Write-once. FK to `subscriptions`.
- `subscription_payment_links` (new): `id`, `subscription_id`, `payment_id` (`UNIQUE`),
  `billing_period_start`/`billing_period_end` (nullable), `created_at`. FKs to `subscriptions`,
  `payments`.
- `payments.checkout_attempt_id`: `NOT NULL` → nullable (Phinx `changeColumn`); the existing
  `UNIQUE` index is unaffected (MySQL allows multiple `NULL`s under a `UNIQUE` index).
- `gateway_references.subscription_id` (new, additive, nullable): third parent column alongside
  `checkout_attempt_id`/`payment_id`, with its own FK and index.

All three migrations are additive or widen an existing constraint — no destructive change, no
data loss on either `up()` or `down()`.

## API Changes

- `POST /api/v1/subscriptions` (new, auth, idempotent via `Idempotency-Key`) — requires
  `attempt_reference`, `package`, `country`, `currency`, `client_user_ref`,
  `subscription_interval` in the body; accepts optional `payment_method`, `device`,
  `voucher_code`, `first_purchase`, `customer_email`. Returns `201`
  `{checkout_attempt_id, status, redirect_url, provider_reference}` on success; `422
  subscriptions.missing_fields` if a required field is absent; `404 package.not_found` for an
  unknown package.
- `GET /api/v1/subscriptions/{id}` (new, auth) — `{id}` is the checkout attempt id. Returns the
  checkout attempt's own pre-conversion fields until a `subscriptions` row exists, then the real
  subscription's fields (`subscription_id`, `client_user_ref`, `status`, `currency`,
  `amount_minor`, `payment_method`, `subscription_interval`, trial/period timestamps,
  error fields, timestamps). `404 subscription.not_found` for an unknown/foreign id.
- `POST /api/v1/subscriptions/{id}/cancel` (new, auth, idempotent) — `{id}` is the checkout
  attempt id, resolved to the real subscription before cancelling. Returns `200
  {checkout_attempt_id, subscription_id, status}` on success; `404 subscription.not_found` if no
  subscription exists yet for that attempt or the attempt is unknown/foreign; `409
  subscription.already_cancelled`; `422 subscription.provider_context_unavailable`; `501` (or the
  project's unsupported-mapping) `subscription.cancel_not_supported`; `502
  subscription.cancel_failed` on a provider error.

## Tests and Validation

- Tests created:
  - `CreateSubscriptionHandlerTest` (6 tests — creates a subscription linked to its first
    payment; starts in trial when the package has one; idempotent for a repeat call on the same
    attempt (no duplicate link created for a different payment id); rejects an attempt with no
    subscription interval; rejects an attempt with no `client_user_ref`; an unknown checkout
    attempt is not found).
  - `RecordSubscriptionPaymentHandlerTest` (6 tests — records a successful renewal charge as a
    real payment; a failed charge moves the subscription to `past_due`; a duplicate charge
    reference is idempotent and creates no second payment; two distinct charges each get their
    own payment; an unknown subscription is not found; an unknown mapped status is rejected).
  - `CancelSubscriptionHandlerTest` (5 tests — cancels using the real subscription reference;
    falls back to the checkout-session reference when no real one exists; rejects an
    already-cancelled subscription; rejects cancel when the provider doesn't support it; an
    unknown subscription is not found).
  - `SubscriptionOwnershipTest` (2 tests — a gateway subscription id resolves to its internal
    subscription; a client user resolves to their own subscriptions, scoped to their own
    client).
  - `CreateProviderSubscriptionHandlerTest` (6 tests — creates the provider subscription and
    advances the attempt; passes the subscription interval to the adapter; rejects a one-time-
    payment purchase type; rejects an attempt with no provider selected; rejects a provider that
    doesn't implement `SupportsSubscriptions`; maps a provider adapter exception to an upstream
    failure).
  - `CreateCheckoutSubscriptionHandlerTest` (3 tests — creates a subscription end to end and
    returns the redirect; passes the client_user_ref through to the attempt; rejects an unknown
    subscription interval).
  - `SubscriptionsCreateActionTest` (3), `SubscriptionsShowActionTest` (3),
    `SubscriptionsCancelActionTest` (2).
- Tests modified: `ReconcileCheckoutStatusHandlerTest.php`, `PaymentsStatusActionTest.php`,
  `PaymentsReturnActionTest.php` — updated to construct and pass the new
  `CreateSubscriptionHandler` dependency.
- Commands actually run at the end of this phase:
  - `composer stan` (PHPStan) → `[OK] No errors`
  - `composer cs` (PHP-CS-Fixer, dry run) → `Found 0 of 828 files that can be fixed.`
  - `composer test` (PHPUnit, unit suite) → `Tests: 575, Assertions: 2064.` (all passing)
- Migrations were **not** re-verified against a live database this session — the local Docker
  daemon was unavailable, and the only reachable MySQL instance on port 3306 rejected the
  project's configured credentials (not a project database). Schema correctness rests on the
  earlier database-design confirmation and the repository layer's own test coverage, not a fresh
  `composer migrate` run.

## Technical Decisions

Full verbatim questions/options/selections are in `PhaseResults/PhaseDecisions.md` (Phase 26
Q1–Q4, plus an addendum). Summary:

- **Q1** — reuse the Checkout pipeline for subscription creation; extend
  `ReconcileCheckoutStatusHandler`; reuse `GET /payments/return`.
- **Q2** — a renewal charge becomes a real `payments` row via a second creation path;
  `payments.checkout_attempt_id` becomes nullable; linked via `subscription_payment_links`.
- **Q3** — defer Mollie's renewal automation to Phase 29.
- **Q4** — `subscriptions.client_user_ref` is `NOT NULL`.
- **Addendum** (direct correction, not a Q1–Q4 option) — `subscriptions.payment_method` is
  nullable; `subscriptions` has no `country` column at all.

Beyond the four asked questions, several implementation-level judgment calls are worth recording:

1. **Where a subscription-context handler gets a `country` from, given `subscriptions` has no
   `country` column.** `RecordSubscriptionPaymentHandler` needs one to call `Payment::create()`
   (a non-nullable, FK'd field). Resolved by reading `checkout_attempts.country` via the
   subscription's `checkout_attempt_id` — every subscription has exactly one, so this is always
   resolvable without a schema change.
2. **`ResolveSubscriptionActionContext`'s reference fallback needed two different repository
   queries, not one.** The "deeper" `Subscription`-type reference lives under
   `forSubscription(subscriptionId)`, but the fallback `CheckoutSession` reference was recorded
   against the *checkout attempt*, not the subscription — so the fallback query is
   `forCheckoutAttempt(checkoutAttemptId)`, not a second call to `forSubscription()`. Missing
   this distinction would have made the Mollie-fallback case silently return nothing.
3. **`RecordSubscriptionPaymentHandler` reuses `RecordProviderTransactionHandler` rather than
   writing its own status-transition logic.** Since `PaymentStatus::Created` can't jump straight
   to `Paid` (the allowed-next-statuses graph requires `Pending` first), the handler makes two
   calls — `Pending` then the mapped terminal status — mirroring the exact two-hop pattern
   `ReconcileCheckoutStatusHandler` already established for a checkout-originated payment's first
   status advance.
4. **Webhook-driven subscription automation was deliberately left unbuilt this phase** (see
   Deferred Work) rather than attempting a partial wire-up — judged safer than building real
   automation on top of `mapProviderSubscriptionStatusToInternalStatus()`'s still-documented
   "provisional pass-through" status, and requiring adapter-layer changes (extracting a
   subscription id from a Stripe invoice webhook) that weren't in scope.

## Problems Encountered

- PHPStan flagged `nullsafe.neverNull` on `$capability?->hasTrial ?? false` in
  `CreateSubscriptionHandler` — resolved by replacing it with an explicit
  `$capability !== null && $capability->hasTrial` check.
- Extending `ReconcileCheckoutStatusHandler`'s constructor broke three existing tests that
  positionally constructed it (`ReconcileCheckoutStatusHandlerTest`, `PaymentsStatusActionTest`,
  `PaymentsReturnActionTest`) — each needed a real `CreateSubscriptionHandler` instance wired
  with in-memory repositories, which in turn required creating four new test-support doubles
  (`InMemorySubscriptionRepository`/`SubscriptionEventRepository`/`SubscriptionPaymentLinkRepository`/
  `SubscriptionDirectory`) that didn't exist yet.
- `FakePaymentProviderPort` didn't implement `SupportsSubscriptions`, needed by
  `CancelSubscriptionHandlerTest`/`CreateProviderSubscriptionHandlerTest` — extended it to
  implement the interface unconditionally (matching how it already implements
  `SupportsRefunds`/`SupportsAuthCapture`), then had to write one extra anonymous-class
  `PaymentProviderPort`-only double for the specific "provider doesn't implement
  `SupportsSubscriptions`" rejection test, since the shared fake now always implements it.
- An initial `CancelSubscriptionHandlerTest` assertion checked
  `gatewayReferences->forSubscription()` for the renewal-payment's deep reference, which is
  actually stored under `forPayment()` (the deep `PaymentIntent`-type reference has no
  `subscriptionId`) — caught immediately by the test itself, fixed by asserting the right
  repository method.

## Resolutions

All of the above were resolved within this session — see Problems Encountered for the fix
applied to each; none required a design change beyond what's already recorded in Technical
Decisions.

## Deferred Work

- **Webhook-driven subscription automation** — `ProcessWebhookEventHandler` (Phase 25) does not
  resolve `Subscription`-typed gateway references or call `RecordSubscriptionPaymentHandler`.
  Deferred to **Phase 29**, alongside Q3's deferred Mollie renewal scheduler and the real
  queue/worker system Phase 25 already flagged. Two concrete reasons this wasn't attempted
  partially: `mapProviderSubscriptionStatusToInternalStatus()` is still a documented "provisional
  pass-through," and resolving a renewal charge's subscription id from a Stripe invoice webhook
  needs an adapter-layer change (`ParsedWebhookEvent` doesn't currently carry a subscription
  reference) not built this phase.
- **Cancel/refund/capture on a renewal-originated `Payment`** — `ResolvePaymentActionContext::forPayment()`
  returns `null` for one today (no checkout attempt to resolve provider context from). No phase
  assigned yet.
- **Admin panel views for subscriptions** — Phase 27 (Admin Module Views and Panels).
- **Live-database migration verification** — the local Docker daemon was unavailable this
  session; the three new migrations were written and reviewed carefully (column types, FKs,
  indexes match the confirmed design) but not run against a real MySQL instance this session.

## Final Result

Phase 26's scope is implemented, tested, and documented: subscription creation reuses the entire
Checkout pipeline end to end, a subscription's ownership queries are directly testable and
tested, cancellation is capability-gated the same way payment actions are, and a renewal charge's
"second creation path" is built and fully tested even though nothing yet triggers it
automatically. The full unit test suite (575 tests, 2064 assertions) passes, `composer stan` and
`composer cs` are both clean, and every decision (Q1–Q4 plus the mid-phase addendum) is recorded
in `PhaseResults/PhaseDecisions.md`. No commit has been made yet for this phase's work.
