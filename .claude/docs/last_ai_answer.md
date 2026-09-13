# Q: Implement the PayPal adapter (Phase 22, completing the phase)

Implemented the PayPal adapter — the third provider on `PaymentProviderPort`, completing Phase 22
(Mollie + PayPal). Full detail is in `.claude/PhaseResults/Phase22Result.md`; this is the summary.

## Decisions made first (recorded in `PhaseResults/PhaseDecisions.md` as Q7/Q8)

1. **PayPal's Orders API is a genuine two-step redirect flow, even for "immediate capture"** — the
   customer must approve before either a capture-intent order can be captured or an
   authorize-intent order can be authorized. Decided to implement the real dance:
   `capturePayment()` fetches the order, checks its own `intent`, and performs whichever real
   calls that intent needs (`/capture` directly for `CAPTURE`; `/authorize` then
   `/authorizations/{id}/capture` for `AUTHORIZE`) — callers always call the one method regardless
   of which flow created the order. This fully honors PayPal's seeded authorization/capture/cancel
   capabilities rather than leaving them half-implemented.
2. **PayPal Subscriptions need a persisted Billing "Plan" resource** created ahead of time —
   unlike Stripe/Mollie's ad-hoc, inline pricing at subscription-creation time. Decided to defer
   PayPal subscriptions entirely this phase rather than build ephemeral Plan provisioning without
   a considered caching/reuse strategy — `PayPalAdapter` does not implement `SupportsSubscriptions`
   despite the seeded capability data saying PayPal supports it.

## What was built

- **`PayPalAdapter`** (`src/Modules/Providers/Infrastructure/Adapter/PayPal/PayPalAdapter.php`) —
  core `PaymentProviderPort` + `SupportsAuthCapture` + `SupportsRefunds`. Talks to PayPal's REST
  API directly over `guzzlehttp/guzzle` (no official SDK — its current one is a large generated
  client, overkill for the handful of endpoints needed), fetching a fresh OAuth2
  client-credentials token per call rather than caching one.
- **`PayPalStatusMapper`** — pure status translation covering PayPal's combined Order/
  Authorization/Capture vocabulary in one table (the port gives no hint which resource a raw
  status came from), same "unrecognised → Pending, never a false terminal claim" rule as the other
  two mappers.
- **Refunds key off the capture id, not the order id** — surfaced via
  `ProviderPaymentStatus::$paymentIntentReference`, the same field `StripeAdapter` already uses
  for its PaymentIntent id. `cancelPayment()` voids an existing Authorization if one exists and
  throws otherwise (PayPal has no "cancel this order" endpoint).
- **`DefaultProviderAdapterFactory`** gained a `'paypal'` match arm: decodes the account's secret
  as a `{client_id, client_secret}` JSON pair (packed into the existing `secret_ciphertext`
  column, no schema change) and picks the sandbox-vs-live host from the account's `mode` — PayPal
  is the first provider where the factory actually reads `mode` for anything.
- **Webhook verification** calls PayPal's real `/v1/notifications/verify-webhook-signature`
  endpoint, reading its five required headers from `RawWebhook::$headers` (the bag added during
  the Mollie half) and the registered webhook id from `$webhookSigningSecret`.
- **CLI**: `bin/PayPalCreateCheckoutSession.php`, `bin/PayPalGetPaymentStatus.php`.
- **Tests**: `PayPalAdapterTest` (15 tests, using Guzzle's own `MockHandler` — no hand-rolled fake
  needed), `PayPalStatusMapperTest` (17 tests), two new `DefaultProviderAdapterFactoryTest` cases
  (success + malformed-credentials).

## Verified (real captured output)

- `composer ci` → CS clean, PHPStan clean, **458 tests / 1683 assertions, all passing** (up from
  424 after the Mollie half).
- `composer test:integration` → 39 skipped (up from 38 — the new PayPal live test self-skips).
- Both CLI scripts invoked with no arguments print their real usage banners.
- A standalone demo script exercised the real `DefaultProviderAdapterFactory` + `PayPalAdapter`
  against PayPal's fake transport end-to-end: factory resolution (reading `mode=live` correctly),
  `getCapabilities()` (10 capabilities, authorization/capture/refund all yes), `createPayment()` →
  a real CAPTURE-intent order + approve URL, `capturePayment()` for both a CAPTURE-intent order
  (one `/capture` call) and an AUTHORIZE-intent order (the real `/authorize` →
  `/authorizations/{id}/capture` dance), a genuine PayPal 401 → `ProviderAuthenticationFailed`,
  and a real webhook-signature verification call.

## Problems hit and fixed along the way

- PHPStan strict rules fought Guzzle's own generics on `Middleware::history()` (its by-ref
  container param is typed as a bare `array|\ArrayAccess<int, array>`, incompatible with any more
  specific property type) — resolved by writing a small custom middleware that appends
  `RequestInterface` objects directly into a normally-typed `list<RequestInterface>` property
  instead of fighting Guzzle's generics.
- Deep, untyped JSON field access (`purchase_units[0].payments.authorizations[0].id`) needed a
  small `dig()`/`digString()` helper pair for real type-safety rather than chained `??`, which is
  runtime-safe but still flagged by PHPStan.
- Mollie's refund request factory requires an explicit `amount` even for a "full" refund (found
  during the Mollie half) — resolved by always passing the payment's own already-known full amount
  when the caller didn't specify one.

## Docs updated

`.claude/Changelog.md` (new dated entry for the PayPal completion), `.claude/docs/Phases.md` (row
22 → ☑ complete, full "as built" rewrite for both providers), `.claude/docs/Architecture.md` §8,
`.claude/docs/Commands.md` (new `paypal:*` CLI docs), `.claude/FileIndex.md`,
`.claude/knowledge/Knowledge.md` (new "PayPal adapter" section), and
`.claude/PhaseResults/Phase22Result.md` (new — the phase's final result file, now that both Mollie
and PayPal are done).

## Not done yet

PayPal subscriptions (deferred, Q8), Mollie's real Subscription resource (deferred to Phase 25),
multiple partial captures against one PayPal authorization, and wiring any of the three adapters
into the actual Payments module — that's Phase 24. Phase 23 (Ziraat) is next.
