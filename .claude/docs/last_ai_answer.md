# Q: Phase 26 Subscriptions module completion

Phase 26 (Subscriptions module) is complete, with known limitations, and fully documented.

## What was built

Reused the entire Checkout pipeline for subscription creation (Q1) rather than a parallel one:
`POST /api/v1/subscriptions` runs `CreateCheckoutAttemptHandler → ResolveCheckoutPricingHandler →
(ReserveCheckoutVoucherHandler) → SelectCheckoutProviderHandler → CreateProviderSubscriptionHandler`,
the exact same shape as the payment-creation flow. `ReconcileCheckoutStatusHandler` was extended
so a `Confirmed` subscription-purchase-type attempt also gets a `Subscription` row created (via
the new `CreateSubscriptionHandler`), right after its first `Payment`.

A renewal charge becomes a real `payments` row via a second creation path (Q2):
`payments.checkout_attempt_id` is now nullable, and `RecordSubscriptionPaymentHandler` creates
the `Payment` directly from subscription context, linked via a new `subscription_payment_links`
table instead of a checkout attempt. It's idempotent by `providerPaymentReference`, reuses the
existing `RecordProviderTransactionHandler` unchanged, and transitions the subscription to
`active`/`past_due` based on the outcome.

`CancelSubscriptionHandler` is capability-gated on `Capability::SubscriptionCancel` **and**
`instanceof SupportsSubscriptions` (the same "both, not either" pattern Phase 24 established for
payment actions), with reference resolution preferring the real provider Subscription-resource
reference and falling back to the original checkout-session reference for Mollie's provisional
support.

`subscriptions.client_user_ref` is mandatory (Q4) — the one place this module diverges from
`payments`' nullable precedent, per CLAUDE.md's Subscription Ownership Model. Mid-phase, the user
gave a direct correction: `payment_method` is nullable and there's no `country` column on
`subscriptions` at all (a handler that needs one reads it from the origin checkout attempt
instead).

36 new tests across 9 files, including a dedicated `SubscriptionOwnershipTest` that directly
exercises both ownership-model queries CLAUDE.md names (gateway subscription id → internal
record; client user → their subscriptions). Full suite: 575 tests, 2064 assertions, all passing;
`composer stan` and `composer cs` both clean.

## Known limitations

1. **Webhook-driven subscription automation is not wired this phase.**
   `ProcessWebhookEventHandler` (Phase 25) doesn't resolve `Subscription`-typed gateway
   references or call `RecordSubscriptionPaymentHandler` automatically — deferred to Phase 29,
   alongside Q3's deferred Mollie renewal scheduler. The handler itself is built and fully tested
   as the reusable unit a future trigger will call unchanged.
2. Cancel/refund/capture don't work on a renewal-originated `Payment` yet (no checkout attempt to
   resolve provider context from).
3. No admin panel views for subscriptions yet (Phase 27).
4. Migrations were not re-verified against a live database this session (Docker daemon
   unavailable locally) — schema correctness rests on the earlier design confirmation and the
   repository layer's test coverage.

## Documentation

Full pass completed across `PhaseDecisions.md` (addendum), `database-design.md`,
`database-diagram.md`/`.html`, `db_explain.md`, `Architecture.md`, `Changelog.md`, `FileIndex.md`,
`Knowledge.md`, `Phases.md` (marked complete), and the new `PhaseResults/Phase26Result.md`.

Ready for a "Commit this" from the user.
