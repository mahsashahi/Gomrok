# Q: Phase 21 — Provider adapter port & Stripe adapter (completion summary)

Phase 21 is complete. Here's what was built, verified, and documented.

## What was implemented

The one interface every provider implements, plus the first real provider. A new
`Modules\Providers\Application\Adapter\` namespace holds the port: the required core
`PaymentProviderPort` (`createPayment`, `getPaymentStatus`, `verifyWebhookSignature`,
`parseWebhook`, `mapProviderStatusToInternalStatus`, `getCapabilities`) plus five optional
capability interfaces (`SupportsSubscriptions`, `SupportsRefunds`, `SupportsAuthCapture`,
`SupportsCustomerPortal`, `SupportsManualPolling`) — the hybrid shape decided back in Phase 1 Q5,
now real code.

`StripeAdapter` implements the core plus four capability interfaces using the new
`stripe/stripe-php` SDK — used only inside this one class. `createPayment()`/`createSubscription()`
create a Stripe Checkout Session; `getPaymentStatus()` prefers the underlying PaymentIntent's
status when expanded, for precision; `getCapabilities()` reads the Phase 8 seeded declaration
rather than duplicating it. `StripeStatusMapper` (pure, no SDK) maps Stripe's vocabulary to
`PaymentStatus` — an unrecognised status always falls back to `Pending`, never a false claim.
`DefaultProviderAdapterFactory` resolves a `provider_account_id` to a fresh, credentialed adapter
instance (Q4).

**Per Q5, this phase stops at a standalone, fully-tested adapter + CLI — it is not wired into the
Payments module yet.** That orchestration is explicitly Phase 24's job.

## Files created

- `Modules\Providers\Application\Adapter\` — the port interfaces, 10 DTOs, 5 exceptions.
- `Modules\Providers\Infrastructure\Adapter\Stripe\{StripeAdapter,StripeStatusMapper}.php`.
- `Modules\Providers\Infrastructure\DefaultProviderAdapterFactory.php`.
- CLI: `bin/{StripeCreateCheckoutSession,StripeGetPaymentStatus}.php`.
- Tests: 25 new (`StripeStatusMapperTest` 16, `StripeAdapterTest` 6, `DefaultProviderAdapterFactoryTest`
  3) + `StripeAdapterLiveTest` (CI-only, self-skips without a real Stripe key);
  `FakeStripeHttpClient` + `StubProviderAccountCredentials` test doubles.
- `.claude/PhaseResults/Phase21Result.md`.

## Files updated

`ProviderAccountDirectory` gained `findById()` (additive); DI wired the new factory;
`composer.json` gained `stripe/stripe-php` + 2 CLI scripts; `.env.example` documented
`STRIPE_TEST_SECRET_KEY`. Every governance doc updated in lock-step: `Architecture.md` (§3, §8
rewritten from sketch to as-built, §13), `Phases.md` (row 21 ☑), `Changelog.md`, `FileIndex.md`,
`Knowledge.md`, `Commands.md`, `Orders.md` (D24). No DB-diagram/db_explain changes — no schema
changed this phase.

## Database changes

**None.**

## Tests — how to run and real results

```
composer ci
```
→ PHPStan: 685 files, **0 errors**. PHPUnit: **401 tests, 1553 assertions, OK** (+25 new).

```
composer test:integration
```
→ **37 tests, 37 skipped** (no local MySQL; the new `StripeAdapterLiveTest` also self-skips
without a real `STRIPE_TEST_SECRET_KEY` — same convention as every MySQL-dependent test).

**Honest note on "integration tests against Stripe test mode":** this sandbox has no real Stripe
account, so the genuine-network test self-skips here, same as every MySQL test does without a
database. What *is* real and verified: `StripeAdapterTest` drives the actual Stripe PHP SDK
(`StripeClient`, `Webhook::constructEvent()`, its own HTTP-status-to-exception mapping) through a
fake transport — a queued real Stripe 401 body genuinely produces a real
`AuthenticationException`, a real HMAC signature genuinely verifies. Only the network call itself
is faked. (Network to `api.stripe.com` was confirmed reachable from this environment — no
credentials were available to authenticate with.)

## Captured evidence

CLI usage strings captured for both new scripts. A standalone script drove the real factory +
adapter against the fake transport, exercising the full port surface:

```
factory resolves stripe account #1                         -> StripeAdapter
getCapabilities()                                           -> 17 capabilities (hosted_checkout=yes, refund=yes)
factory resolves ziraat account #2 (no adapter yet)          -> ERROR  No adapter implemented for provider type 'ziraat' yet.
factory resolves unknown account #999                        -> ERROR  Provider account 999 was not found.
createPayment()                                              -> #cs_test_abc123 [open] https://checkout.stripe.com/...
getPaymentStatus()                                           -> raw=complete mapped=paid (via payment_intent pi_test_xyz)
createPayment() with a bad key                                -> ERROR  ProviderAuthenticationFailed: Invalid API Key provided
verifyWebhookSignature() correct secret / wrong secret        -> true / false
parseWebhook()                                                -> type=checkout.session.completed reference=cs_test_abc123
```

## Known limitations

- Not wired into `RecordProviderTransactionHandler`/`CreatePaymentHandler` yet — Phase 24.
- Mollie/PayPal — Phase 22. Ziraat (first `SupportsManualPolling`) — Phase 23.
- `mapProviderSubscriptionStatusToInternalStatus()` is a provisional `string` pass-through until
  the `Subscriptions` module (Phase 26) formalizes a real status enum.
- No `gateway_references` writer yet — awaits a real caller alongside Phase 24.

## Next recommended phase

**Phase 22 — Mollie & PayPal adapters.**
