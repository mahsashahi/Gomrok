# Q: Go with option 1, proceed with implementation (Phase 24 cancel/refund/capture)

Built the remaining action endpoints from Phase 24's original scope:
`POST /api/v1/payments/{id}/cancel`, `/refund`, `/capture`. The A/B price-list visitor-assignment
re-ask (deferred from Phase 15) is still the only thing left before Phase 24 as a whole is done.

## Decisions recorded (Q5, Q5b)

- **Q5 — which stored `GatewayReference` a refund/capture/cancel acts on.** Chosen:
  reference-type-per-action. When a provider's `getPaymentStatus()` surfaces a "deeper" reference
  (`ProviderPaymentStatus::$paymentIntentReference` — Stripe's PaymentIntent id, and, per
  `PayPalAdapter::refundPayment()`'s own docblock, PayPal's Capture id reusing the same field),
  it's now persisted as a second `GatewayReference::forPayment(..., GatewayReferenceType::PaymentIntent,
  ...)` row. `ResolvePaymentActionContext` prefers that deeper reference and falls back to the
  original `CheckoutSession` reference when none was recorded (Mollie: one id serves every action).
- **Q5b — capability gating.** Chosen: both. Every action checks the resolved
  `ProviderCapabilityResolver` capability (client/country config can disable something the adapter
  technically supports) **and** `$adapter instanceof SupportsRefunds`/`SupportsAuthCapture` as a
  type-safe guard before calling.

## What was built

- `src/Modules/Payments/Application/PaymentActionContext.php` + `ResolvePaymentActionContext.php`
  — resolves the adapter, resolved capabilities, and provider reference a post-creation action
  needs, from the payment's own frozen checkout-attempt routing decision.
- `CancelPaymentHandler`/`RefundPaymentHandler`/`CapturePaymentHandler` (each with its own
  Command/Result) — validate the payment's current status is eligible for that action, resolve the
  provider context, check capability, call the adapter, then delegate the actual persistence
  (provider_transactions row, PaymentAttempt bookkeeping, Payment status transition, audit entry)
  to the existing Phase 20 `RecordProviderTransactionHandler` rather than duplicating it.
- `PaymentsCancelAction`/`PaymentsRefundAction`/`PaymentsCaptureAction` — thin HTTP wrappers,
  `{id}` is the checkout attempt id (consistent with every other `/api/v1/payments/{id}*` route).
  Refund/capture accept an optional `amount_minor` body field for a partial refund/capture.

## Two real bugs found and fixed along the way

1. **A `Payment` was never driven past its initial `created` status.** `CreatePaymentHandler`
   always creates at `PaymentStatus::Created` and nothing ever called `ChangePaymentStatusHandler`
   afterward — even though the only caller (`ReconcileCheckoutStatusHandler`) only reaches that
   code path because the provider already confirmed the payment as paid. Fixed by having
   `ReconcileCheckoutStatusHandler` transition the new Payment `created → pending → paid`
   (two hops, since `PaymentStatus`'s allowed-next-statuses graph has no direct `created → paid`
   edge).
2. **Stripe's PaymentIntent id (and PayPal's capture id) was computed and discarded.** Every
   `getPaymentStatus()` call surfaced it via `ProviderPaymentStatus::$paymentIntentReference`, but
   nothing ever persisted it — so cancel/refund/capture would have had no way to look it up. Fixed
   by persisting it as a `GatewayReference::forPayment()` row in `ReconcileCheckoutStatusHandler`
   whenever non-null.

## Tests

21 new tests: `CapturePaymentHandlerTest` (5), `RefundPaymentHandlerTest` (7),
`CancelPaymentHandlerTest` (3), `PaymentsCancelActionTest` (2), `PaymentsRefundActionTest` (2),
`PaymentsCaptureActionTest` (2). `FakePaymentProviderPort` extended to implement
`SupportsRefunds`/`SupportsAuthCapture` with configurable results/exceptions. Full suite green:
**555 tests, 1840 assertions**; `composer ci` (CS + PHPStan + tests) clean.

## Documentation updated

`.claude/docs/Architecture.md` §8 (cancel/refund/capture design + the two fixed gaps),
`.claude/Changelog.md` (new dated entry), `.claude/FileIndex.md` (new Payments module files, new
HTTP actions, extended test double), `.claude/knowledge/Knowledge.md` (four new gotchas),
`.claude/docs/Phases.md` (Phase 24 body updated — only the A/B re-ask remains before this phase is
complete), `.claude/PhaseResults/PhaseDecisions.md` (Q5/Q5b recorded).

## What's still pending for Phase 24

- The deferred Phase 15 A/B price-list visitor-assignment re-ask (Q4: stateless vs. persisted
  `price_list_assignments`; Q5: management surface) plus the resulting bucket-assignment service —
  the only remaining item before this phase is complete.
- No git commit has been made for any of this work yet.
- `Phase24Result.md` is not created yet — only once the A/B item above is also done.
- **Known limitation, not yet addressed:** a refund's own provider-issued reference id is recorded
  only inside `provider_transactions.response_payload`, not as its own `GatewayReference` row — a
  dedicated `refunds` table (on CLAUDE.md's Required Database Concepts list) needs its own
  database-design confirmation before that's built.
