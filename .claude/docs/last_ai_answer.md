# Q: Implement the Mollie adapter (Phase 22, first half)

Implemented the Mollie half of Phase 22 — the second real provider on the `PaymentProviderPort`
from Phase 21. PayPal (the other half of this phase) is still outstanding.

## Decisions made first (recorded in `PhaseResults/PhaseDecisions.md`)

1. **Mollie via the official `mollie/mollie-api-php` SDK** (mirrors Stripe's precedent).
2. **PayPal via raw REST over a shared HTTP client**, not the generated official SDK (still to be
   built).
3. **`CreatePaymentCommand` gained an additive, optional `paymentMethod` field** — Mollie's
   Checkout can be locked to the method Gomrok's routing already resolved; other adapters ignore
   it.
4. **PayPal's future client_id/client_secret pair packs into the existing single
   `secret_ciphertext` column as one JSON value** — no schema change.
5. **`RawWebhook` gained an additive `headers` bag** for providers needing more than Stripe's one
   signature header + one secret (PayPal's verify-webhook-signature call needs five headers).
6. **(Discovered mid-implementation) Mollie has no single-call subscription flow** — a customer
   must authorize recurring charges via a one-off "first payment" before a mandate exists.
   `MollieAdapter::createSubscription()` performs only that step and returns the first payment's
   id/checkout URL; the real Mollie Subscription resource is deferred to Phase 25 (webhook
   processing), once the mandate is confirmed.

## What was built

- **`MollieAdapter`** (`src/Modules/Providers/Infrastructure/Adapter/Mollie/MollieAdapter.php`) —
  implements the core `PaymentProviderPort` + `SupportsRefunds` + `SupportsSubscriptions` +
  `SupportsManualPolling`. Deliberately **not** `SupportsCustomerPortal`: while implementing, I
  found the Phase 10 seed incorrectly declared Mollie as having a `customer_portal` capability —
  Mollie has no hosted self-service billing portal like Stripe's. Corrected
  `src/Database/Seeds/data/ProviderTypeDeclarations.json` (removed it) and
  `tests/Support/InMemoryProviderTypeDeclarations.php` (removed it, and added the
  `manual_status_polling` capability it was separately missing for Mollie).
- **`MollieStatusMapper`** — pure, dependency-free status translation
  (`open`/`pending`→Pending, `authorized`→Authorized, `paid`→Paid, `failed`→Failed,
  `canceled`→Canceled, `expired`→Expired, unrecognised→Pending), same "never a false-positive
  terminal status" rule as `StripeStatusMapper`.
- **`DefaultProviderAdapterFactory`** gained a `'mollie'` match arm.
- **Webhooks**: Mollie's classic per-payment webhook has no signature at all (just POSTs
  `id=tr_xxx`) — `verifyWebhookSignature()`/`parseWebhook()` re-fetch the payment by that id as
  the real authenticity check, rather than checking a signature that doesn't exist.
- **Money conversion**: outgoing amounts use `Gomrok\Shared\Domain\Money::fromMinor()->amount()`
  (correct per-currency decimal scale); incoming decimal strings (refund amounts) convert back via
  `Brick\Money\Money::of()`.
- **CLI**: `bin/MollieCreateCheckoutSession.php`, `bin/MollieGetPaymentStatus.php` (mirror the
  Stripe pair; `composer mollie:create-checkout-session` / `mollie:get-payment-status`).
- **Tests**: `MollieAdapterTest` (13 tests, using the Mollie SDK's own official test double
  `MollieApiClient::fake()`/`MockMollieClient` — no hand-rolled fake needed, unlike Stripe's
  `FakeStripeHttpClient`), `MollieStatusMapperTest` (10 tests), a Mollie case added to
  `DefaultProviderAdapterFactoryTest`, and `tests/Integration/MollieAdapterLiveTest.php`
  (self-skips without a real `MOLLIE_TEST_API_KEY`, same pattern as `StripeAdapterLiveTest`).

## Verified (real captured output)

- `composer ci` → CS clean, PHPStan clean, **424 tests / 1593 assertions, all passing** (up from
  401 before this change).
- `composer test:integration` → 38 skipped (up from 37 — the new Mollie live test self-skips,
  same as every MySQL-dependent integration test without a database).
- Both CLI scripts invoked with no arguments print their real usage banners.
- A standalone demo script exercised the real `DefaultProviderAdapterFactory` + `MollieAdapter`
  against Mollie's fake transport end-to-end: factory resolution, `getCapabilities()` (9
  capabilities, `customer_portal=no`), `createPayment()` → real checkout URL, `getPaymentStatus()`
  → `raw=paid mapped=paid`, a genuine Mollie 401 → `ProviderAuthenticationFailed` with the real
  Mollie error message, and webhook verify/parse via re-fetch.

## Docs updated

`.claude/Changelog.md` (new dated entry), `.claude/FileIndex.md`, `.claude/docs/Architecture.md`
§8 (Mollie's as-built shape + the customer_portal correction), `.claude/docs/Commands.md` (new
`mollie:*` CLI docs), `.claude/docs/Phases.md` (row 22 → ◐ in progress),
`.claude/knowledge/Knowledge.md` (new "Mollie adapter" section covering the SDK's fake-client
testing pattern, the no-signature webhook model, the provisional subscription flow, and the
seed-data correction).

## Not done yet

Phase 22 is **not complete** — the PayPal adapter is still outstanding. No `Phase22Result.md` has
been created (that happens once the whole phase, including PayPal, is done). No database changes
this phase.
