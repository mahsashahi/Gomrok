# Phase 22 — Mollie & PayPal adapters

## Execution Summary

- Phase: 22 — Mollie & PayPal adapters
- Start Datetime: 2026-09-11 23:22
- End Datetime: 2026-09-12 23:00
- Estimated Duration: 6–9h
- Actual Duration: N/A (spans two separate working sessions; wall-clock elapsed across the gap
  between them is not hands-on time, and no reliable hands-on-time instrumentation is available)
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

Two more real providers on the `PaymentProviderPort` from Phase 21, completing the phase.

**Mollie** (`Modules\Providers\Infrastructure\Adapter\Mollie\`): `MollieAdapter` implements the
core port plus `SupportsRefunds`, `SupportsSubscriptions`, and `SupportsManualPolling` — not
`SupportsCustomerPortal`, because the Phase 10 seed incorrectly declared Mollie as having a
`customer_portal` capability (Mollie has no hosted self-service billing portal product like
Stripe's). That seed error was found while deciding which capability interfaces to implement, and
corrected in `src/Database/Seeds/data/ProviderTypeDeclarations.json` and
`tests/Support/InMemoryProviderTypeDeclarations.php` (which also gained the
`manual_status_polling` capability it was separately missing for Mollie). `MollieAdapter` uses the
official `mollie/mollie-api-php` SDK (v3.14.0), used only inside this class. Mollie has no webhook
signature at all — its classic per-payment webhook is a form-encoded `id=tr_xxx` body —
so `verifyWebhookSignature()`/`parseWebhook()` re-fetch the payment by that id as the real
authenticity check. Mollie subscriptions are provisional: `createSubscription()` creates a Mollie
Customer plus a "first payment" (`sequenceType: 'first'`) and returns that payment's id/checkout
URL — the real Mollie Subscription resource is deferred to Phase 25's webhook processing, once the
mandate is confirmed (a mismatch discovered mid-implementation, not anticipated by the phase's
original 5 questions — recorded as Q6).

**PayPal** (`Modules\Providers\Infrastructure\Adapter\PayPal\`): `PayPalAdapter` implements the
core port plus `SupportsAuthCapture` and `SupportsRefunds` — not `SupportsSubscriptions`, deferred
because PayPal Subscriptions require a persisted Billing "Plan" resource created ahead of time,
unlike Stripe/Mollie's ad-hoc, inline pricing (Q8). `PayPalAdapter` talks to PayPal's REST API
directly over `guzzlehttp/guzzle` (no official SDK, per Q2), fetching a fresh OAuth2
client-credentials token on every call rather than caching one, matching the established
"adapters are built fresh per call, setup cost is negligible" design. PayPal's Orders API is a
genuine two-step redirect flow even for "immediate capture" — `capturePayment()` fetches the
order, checks its own `intent`, and performs whichever real dance that intent needs: one
`/capture` call for a `CAPTURE`-intent order, or `/authorize` (creating the Authorization
resource) followed by `/authorizations/{id}/capture` for an `AUTHORIZE`-intent order — callers
always call the same one method regardless of which flow created the order (Q7, discovered
mid-implementation). `cancelPayment()` voids an existing Authorization if the order has one and
throws otherwise, since PayPal has no "cancel this order" endpoint and an unauthorized order
simply lapses on its own. `refundPayment()` operates on the **capture** id, not the order id —
that capture id is surfaced via `ProviderPaymentStatus::$paymentIntentReference`, the same field
`StripeAdapter` already uses for its PaymentIntent id.

Both adapters ship pure, dependency-free status mappers (`MollieStatusMapper`,
`PayPalStatusMapper`) following the same "an unrecognised raw status always falls back to
`Pending`, never a false-positive terminal claim" rule as `StripeStatusMapper`.

`DefaultProviderAdapterFactory` gained `'mollie'` and `'paypal'` match arms. The `'paypal'` arm
decodes the account's secret as a `{client_id, client_secret}` JSON pair (Q4 — packed into the
existing single `secret_ciphertext` column, no schema change) and picks the sandbox-vs-live host
from the account's own `mode` — PayPal is the first provider type where the factory reads `mode`
for anything, since Stripe/Mollie encode test-vs-live in the API key prefix itself and hit the
same hostname either way.

Two additive Application-layer DTO changes, decided for the whole phase before either adapter was
built: `CreatePaymentCommand` gained an optional `paymentMethod` field (so Mollie's Checkout can
be locked to the method Gomrok's routing already resolved; other adapters ignore it), and
`RawWebhook` gained an additive `headers` bag (so PayPal's webhook verification can read its five
required headers; Stripe/Mollie don't use it).

## Files Created

- `src/Modules/Providers/Infrastructure/Adapter/Mollie/MollieAdapter.php`
- `src/Modules/Providers/Infrastructure/Adapter/Mollie/MollieStatusMapper.php`
- `src/Modules/Providers/Infrastructure/Adapter/PayPal/PayPalAdapter.php`
- `src/Modules/Providers/Infrastructure/Adapter/PayPal/PayPalStatusMapper.php`
- `bin/MollieCreateCheckoutSession.php`, `bin/MollieGetPaymentStatus.php`
- `bin/PayPalCreateCheckoutSession.php`, `bin/PayPalGetPaymentStatus.php`
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/Mollie/MollieAdapterTest.php` (13 tests)
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/Mollie/MollieStatusMapperTest.php` (10 tests)
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/PayPal/PayPalAdapterTest.php` (15 tests)
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/PayPal/PayPalStatusMapperTest.php` (17 tests)
- `tests/Integration/MollieAdapterLiveTest.php` (self-skips without `MOLLIE_TEST_API_KEY`)
- `tests/Integration/PayPalAdapterLiveTest.php` (self-skips without `PAYPAL_TEST_CLIENT_ID`/
  `PAYPAL_TEST_CLIENT_SECRET`)
- `.claude/PhaseResults/Phase22Result.md` (this file)

## Files Modified

- `composer.json`/`composer.lock` — added `mollie/mollie-api-php:^3.0` (resolved v3.14.0) and
  `guzzlehttp/guzzle:^7.9` (resolved 7.15.5); added `mollie:*`/`paypal:*` create-checkout-session
  and get-payment-status scripts + descriptions.
- `src/Modules/Providers/Application/Adapter/CreatePaymentCommand.php` — additive `paymentMethod`
  field (Q3).
- `src/Modules/Providers/Application/Adapter/RawWebhook.php` — additive `headers` field (Q5).
- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php` — `'mollie'` and
  `'paypal'` match arms; a new private `buildPayPalAdapter()` helper (JSON credential decode,
  sandbox/live host selection by `mode`).
- `src/Database/Seeds/data/ProviderTypeDeclarations.json` — removed Mollie's incorrect
  `customer_portal` capability.
- `tests/Support/InMemoryProviderTypeDeclarations.php` — same Mollie correction, plus added the
  `manual_status_polling` capability Mollie's real seed already had.
- `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php` — added
  Mollie and PayPal success cases plus a malformed-PayPal-credentials case.
- `.env.example` — documented `MOLLIE_TEST_API_KEY`, `PAYPAL_TEST_CLIENT_ID`,
  `PAYPAL_TEST_CLIENT_SECRET`.
- Documentation kept in lock-step: `.claude/docs/Phases.md` (row 22 → ☑ complete; the Phase 22
  section rewritten "as built" for both providers), `.claude/docs/Architecture.md` §8 (Mollie's
  and PayPal's as-built shape, the Mollie capability correction), `.claude/docs/Commands.md` (new
  `mollie:*`/`paypal:*` CLI docs), `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md` (new
  "Mollie adapter" and "PayPal adapter" sections), `.claude/Changelog.md` (two dated entries — the
  Mollie half and the PayPal completion), `.claude/PhaseResults/PhaseDecisions.md` (Q1–Q8, in
  descending order, Q6/Q7/Q8 recorded as discovered mid-implementation).
  `.claude/docs/database-diagram.md`/`.html` and `.claude/docs/db_explain.md` are **unchanged** —
  no database structure changed this phase.

## Implementation Details

- **Mollie money conversion**: outgoing amounts use `Gomrok\Shared\Domain\Money::fromMinor()->amount()`
  (correct per-currency decimal scale, e.g. JPY's 0 decimals); incoming decimal strings (refund
  amounts) convert back to minor units via `Brick\Money\Money::of()` directly, since
  `Shared\Domain\Money` has no "from decimal string" constructor.
- **Mollie payment-method mapping**: `PaymentMethod::Card→creditcard`, `PayPal→paypal`,
  `Ideal→ideal`, `Bancontact→bancontact`, `SepaDirectDebit→directdebit`; `BankHostedCard` (a
  Ziraat-only concept) and `null` both mean "no restriction" — Mollie shows its own method picker.
- **Mollie webhook parsing**: `parse_str()` handles the classic form-encoded `id=tr_xxx` body,
  with a JSON fallback; the extracted id is then re-fetched via `payments->get()` as the real
  authenticity check (a forged/unknown id fails because the fetch fails).
- **PayPal amount helpers**: `toPayPalAmount()`/`fromPayPalAmount()` convert between Gomrok's `int`
  minor units and PayPal's `{currency_code, value}` decimal-string amount objects, reusing the
  same `Gomrok\Shared\Domain\Money`/`Brick\Money` split as Mollie.
- **PayPal deep-array access under PHPStan strict rules**: reading nested, untyped JSON response
  fields (`purchase_units[0].payments.authorizations[0].id`, etc.) safely required a small generic
  `dig()`/`digString()` helper pair (walks a key/index path, returns `null` the moment any step
  isn't an array or the key is missing) rather than chained `??` null-coalescing, which is
  runtime-safe but still flagged by PHPStan as "offset access on mixed."
- **PayPal HTTP plumbing**: `request()`/`fetchAccessToken()` use Guzzle's `http_errors => false`
  and manually inspect `getStatusCode()`, mapping 401/403 to `ProviderAuthenticationFailed` and
  everything else ≥400 to `ProviderRequestFailed`; `GuzzleHttp\Exception\GuzzleException` (network/
  connection-level failures, which `http_errors => false` does not suppress) is also caught and
  wrapped as `ProviderRequestFailed`.
- **PayPal webhook verification**: `verifyWebhookSignature()` calls the real
  `POST /v1/notifications/verify-webhook-signature` endpoint with the five `PAYPAL-*` headers from
  `RawWebhook::$headers` and the registered webhook id from `$webhookSigningSecret`; `parseWebhook()`
  calls `verifyWebhookSignature()` first and throws `ProviderWebhookVerificationFailed` if it
  returns false, then parses `$payload['resource']` for the reference/status.
- **`DefaultProviderAdapterFactory::buildPayPalAdapter()`**: `json_decode($secret, true)` and
  validates both `client_id`/`client_secret` are present strings, throwing `RuntimeException`
  otherwise; base URI is `api-m.paypal.com` for `mode === 'live'`, else
  `api-m.sandbox.paypal.com`.

## Database Changes

**No database changes.** The Mollie seed correction (removing `customer_portal`) is a content fix
to existing seed data, not a schema change.

## API Changes

**No API changes.** All access is via the new `mollie:*`/`paypal:*` CLI scripts — no HTTP endpoint
mounts this phase (deferred to Phase 24, the payment-creation flow, same as Phase 21).

## Tests and Validation

**Tests created:** 55 new tests total (25 Mollie, 30 PayPal split across adapter/mapper files) plus
3 new `DefaultProviderAdapterFactoryTest` cases (1 Mollie, 2 PayPal).

- `MollieStatusMapperTest` (10) — every `fromPaymentStatus()` branch, case/whitespace
  insensitivity, and `fromSubscription()`'s provisional pass-through.
- `MollieAdapterTest` (13) — using the Mollie SDK's own official test double
  (`MollieApiClient::fake()`/`MockMollieClient`): a real checkout response parsed, a real 401 →
  `ProviderAuthenticationFailed`, a real 422 → `ProviderRequestFailed`, `getPaymentStatus()`
  mapping, webhook verify/parse via re-fetch (success and no-id-in-payload failure), a full
  refund (SDK requires an explicit amount even for "full" — satisfied by passing the payment's own
  amount), `createSubscription()`'s customer+first-payment flow, `cancelSubscription()`'s payment
  cancel, and `getCapabilities()` excluding `CustomerPortal` while including
  `ManualStatusPolling`.
- `PayPalStatusMapperTest` (17) — every named Order/Authorization/Capture status plus
  case-insensitivity and an unrecognised fallback.
- `PayPalAdapterTest` (15) — using Guzzle's `MockHandler`: `createPayment()`/`authorizePayment()`
  request bodies (intent, amount, urls), `capturePayment()` for both a `CAPTURE`-intent order
  (direct capture) and an `AUTHORIZE`-intent order (the real authorize-then-capture dance, with
  request paths asserted), `cancelPayment()` voiding an existing authorization and failing when
  there's nothing to void, `getPaymentStatus()` surfacing the capture id as the deeper reference,
  full and partial refunds, a 401 on the token call, a generic 422 error, webhook
  verify/parse (success, unverified-signature rejection), and `getCapabilities()`.
- `DefaultProviderAdapterFactoryTest` — added `buildsAMollieAdapterForAMollieAccount`,
  `buildsAPayPalAdapterForAPayPalAccount`, `rejectsAPayPalAccountWithMalformedCredentials`.

**Tests modified:** `tests/Support/InMemoryProviderTypeDeclarations.php` (Mollie capability
correction, described above — not a test file itself but exercised by every test using it).

**Commands actually run, this session:**

```
$ composer ci
```
```
PHP CS Fixer 3.95.24 ... Found 0 of 700 files that can be fixed
Note: Using configuration file /Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon.
 [OK] No errors
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...............................................................  63 / 458 ( 13%)
............................................................... 126 / 458 ( 27%)
............................................................... 189 / 458 ( 41%)
............................................................... 252 / 458 ( 55%)
............................................................... 315 / 458 ( 68%)
............................................................... 378 / 458 ( 82%)
............................................................... 441 / 458 ( 96%)
.................                                               458 / 458 (100%)
Time: 00:00.297, Memory: 28.00 MB
OK (458 tests, 1683 assertions)
```

```
$ composer test:integration
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
SSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS                           39 / 39 (100%)
Time: 00:00.085, Memory: 10.00 MB
OK, but some tests were skipped!
Tests: 39, Assertions: 0, Skipped: 39.
```

**Honest accounting of what "integration tests against sandbox" means this session:** this
sandbox has no real Mollie or PayPal account, so `MollieAdapterLiveTest`/`PayPalAdapterLiveTest` —
the tests that would make genuine network calls to the real Mollie/PayPal APIs — self-skip here,
exactly like every MySQL-dependent integration test in this suite self-skips without a database.
What **is** real, runnable, and verified this session is `MollieAdapterTest` (drives the actual
Mollie SDK through its own official fake transport) and `PayPalAdapterTest` (drives the actual
Guzzle client through `MockHandler`) — both exercise real request-building, response-hydration,
and exception-mapping logic; only the network call itself is faked in both cases.

`php -l` was run against every new source file, all four CLI scripts, and every new test file —
all reported "No syntax errors detected."

**CLI evidence (real captured output, each script invoked with no arguments):**

```
$ php bin/MollieCreateCheckoutSession.php
usage: php bin/MollieCreateCheckoutSession.php --client=<slug|id> --account=<account-slug> --attempt=<ref> --amount-minor=<n> --currency=<ISO> --description=<text> --success-url=<url> --cancel-url=<url> [--customer-email=] [--payment-method=]

$ php bin/MollieGetPaymentStatus.php
usage: php bin/MollieGetPaymentStatus.php --client=<slug|id> --account=<account-slug> --reference=<payment-id>

$ php bin/PayPalCreateCheckoutSession.php
usage: php bin/PayPalCreateCheckoutSession.php --client=<slug|id> --account=<account-slug> --attempt=<ref> --amount-minor=<n> --currency=<ISO> --description=<text> --success-url=<url> --cancel-url=<url> [--customer-email=]

$ php bin/PayPalGetPaymentStatus.php
usage: php bin/PayPalGetPaymentStatus.php --client=<slug|id> --account=<account-slug> --reference=<order-id>
```

**End-to-end evidence (real captured output)** — two standalone scripts wiring the real
`DefaultProviderAdapterFactory` + adapter against a fake transport, exercising the full port
surface for each provider:

```
=== Phase 22 — Mollie adapter ===

factory resolves mollie account #1                           -> Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie\MollieAdapter
getCapabilities() (Phase 8 seeded declaration, corrected)    -> 9 capabilities (has refund=yes, customer_portal=no)
factory resolves ziraat account #2 (no adapter yet)          -> ERROR  No adapter implemented for provider type 'ziraat' yet.
factory resolves unknown account #999                        -> ERROR  Provider account 999 was not found.

--- createPayment() against a fake Mollie transport (real SDK, no network) ---
createPayment()                                              -> #tr_demo_abc123 [open]
https://www.mollie.com/checkout/select-method/tr_demo_abc123

--- getPaymentStatus() reads the payment back ---
getPaymentStatus()                                           -> raw=paid mapped=paid

--- a real Mollie 401 genuinely produces ProviderAuthenticationFailed ---
createPayment() with a bad key                               -> ERROR  Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed: [...] Your request wasn't executed due to failed authentication. [...]

--- webhook handling (no signature — verified by re-fetch, no network here) ---
verifyWebhookSignature() (re-fetches by id)                  -> true
parseWebhook()                                                -> type=payment.updated reference=tr_demo_abc123 rawStatus=paid
```

```
=== Phase 22 — PayPal adapter (completes the phase) ===

factory resolves paypal account #1 (mode=live)               -> Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter
getCapabilities() (Phase 8 seeded declaration)               -> 10 capabilities (authorization=yes, capture=yes, refund=yes)
factory resolves ziraat account #2 (no adapter yet)          -> ERROR  No adapter implemented for provider type 'ziraat' yet.

--- createPayment() creates a CAPTURE-intent order against a fake PayPal transport (real Guzzle, no network) ---
createPayment()                                              -> #ORDER-DEMO-1 [CREATED]
https://www.paypal.com/checkoutnow?token=ORDER-DEMO-1

--- capturePayment() on a CAPTURE-intent order: GET order, then one /capture call ---
capturePayment() (CAPTURE-intent order)                      -> #ORDER-DEMO-1 [COMPLETED]

--- capturePayment() on an AUTHORIZE-intent order: GET order, /authorize, then /authorizations/{id}/capture ---
capturePayment() (AUTHORIZE-intent order)                    -> #AUTH-DEMO-1 [COMPLETED]

--- a real PayPal 401 genuinely produces ProviderAuthenticationFailed ---
createPayment() with a bad client secret                     -> ERROR  Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed: PayPal request failed with status 401.

--- webhook verification via PayPal's real verify-webhook-signature endpoint ---
verifyWebhookSignature() (real PayPal verify call)           -> true
```

## Technical Decisions

- **Q1 — Mollie via the official `mollie/mollie-api-php` SDK:** mirrors the Phase 21 Stripe
  precedent; mature, small, and reduces custom HTTP/error-mapping code versus raw REST.
- **Q2 — PayPal via raw REST over a shared HTTP client:** PayPal's current official SDK
  (`paypal/paypal-server-sdk`) is a large, code-generated client covering PayPal's whole product
  surface — heavy for the handful of endpoints Gomrok needs; PayPal's REST + OAuth2
  client-credentials exchange is simple enough to call directly.
- **Q3 — `CreatePaymentCommand` gains an optional `paymentMethod` field:** keeps Gomrok's own
  pricing/routing decision authoritative for Mollie's Checkout, rather than letting Mollie's own
  method picker override what was already resolved and priced.
- **Q4 — PayPal's client_id/client_secret pack into the existing single `secret_ciphertext`
  column as JSON:** no schema change, matching the phase's stated "DB: none new" scope.
- **Q5 — `RawWebhook` gains an additive `headers` bag:** Stripe's `signatureHeader`/
  `webhookSigningSecret` shape doesn't generalize to PayPal's five-header verification call; no
  real caller exists yet (Webhooks module is Phase 25), so widening the DTO now is low-risk.
- **Q6 (discovered mid-Mollie-implementation) — `createSubscription()` performs only the
  first-payment step:** Mollie has no single-call subscription flow; the real Subscription
  resource is deferred to Phase 25's webhook processing, once the mandate is confirmed.
- **Q7 (discovered mid-PayPal-implementation) — implement the real authorize/capture dance:**
  PayPal's seeded capabilities already include authorization + capture + cancel; skipping
  `SupportsAuthCapture` would leave a declared, real PayPal feature unimplemented for no
  structural reason, since the two-step dance is bounded and well-documented.
- **Q8 (discovered mid-PayPal-implementation) — defer PayPal subscriptions this phase:** building
  ephemeral Billing Plan provisioning without a considered caching/reuse strategy risked either
  scope creep or a half-real implementation; better to make the gap explicit and revisit
  deliberately.
- **Mollie seed correction (not a formal decision question — a data-accuracy fix):** removed
  Mollie's incorrect `customer_portal` capability from `ProviderTypeDeclarations.json`. Mollie has
  no hosted self-service billing portal product; the `Architecture.md` §8 forward-note written
  during Phase 21 already only expected "core + subscriptions + refunds" for Mollie, not a
  customer portal, supporting that this was a stale/inaccurate Phase 10 seed entry rather than an
  intentional design choice.

## Problems Encountered

- Guzzle's `Middleware::history()` types its by-reference container parameter as a bare
  `array|\ArrayAccess<int, array>` in its own docblock. PHPStan's byref-argument variance checking
  then rejected every more specific property type tried for `PayPalAdapterTest`'s request-history
  property (`list<array{request:...}>`, `array<int, array<string,mixed>>`, even
  `array<int,mixed>|ArrayAccess<int,mixed>`), each for a different covariance reason.
- Several PayPal response fields (`purchase_units[0].payments.authorizations[0].id` and similar)
  are accessed through untyped, arbitrarily-nested JSON — chained `??` null-coalescing is
  runtime-safe but PHPStan still flags "offset access on mixed" at each level.
- The Mollie SDK's `CreatePaymentRefundRequestFactory` requires an `amount` key unconditionally
  (via `MoneyFactory`), even though Mollie's actual HTTP API allows omitting it for a full refund.

## Resolutions

- Replaced `Middleware::history()` with a small custom closure-based middleware that appends the
  `RequestInterface` directly into a normally-typed `list<RequestInterface>` property — sidesteps
  Guzzle's own generics entirely rather than fighting them.
- Added a small generic `dig()`/`digString()` helper pair to `PayPalAdapter` that walks a
  key/index path through a decoded JSON structure, returning `null` the moment any step isn't an
  array or the key is missing — real type-safety, not a suppressed warning.
- `MollieAdapter::refundPayment()` always includes an explicit `amount` in the refund payload: the
  caller's amount when given, or the payment's own full amount (already fetched via `payments->get()`)
  when `$amountMinor` is `null` — functionally equivalent to a "full refund," satisfying the SDK's
  factory requirement.

## Deferred Work

- **Mollie subscriptions**: the real Mollie Subscription resource (vs. the provisional
  first-payment reference `createSubscription()` returns today) is Phase 25's responsibility, once
  webhook processing can confirm the mandate and create it out-of-band.
- **PayPal subscriptions**: `PayPalAdapter` does not implement `SupportsSubscriptions` at all this
  phase; a future phase must decide how to provision (and likely cache/reuse) PayPal Billing Plans
  before this can be built.
- **PayPal delayed authorize-then-capture across multiple partial captures**: `capturePayment()`
  passes `final_capture: true` unconditionally for the `AUTHORIZE`-intent path — multiple partial
  captures against the same authorization (PayPal supports this) are not implemented.
- Neither Mollie's nor PayPal's adapter is wired into the Payments module's
  `RecordProviderTransactionHandler` — that orchestration remains Phase 24's job, per Phase 21 Q5,
  same as Stripe.
- **Ziraat adapter** (Phase 23) is the next provider — payment-only, `SupportsManualPolling` only.

## Final Result

Gomrok now has three real, tested provider adapters (Stripe, Mollie, PayPal) behind the single
`PaymentProviderPort`, each resolvable via `DefaultProviderAdapterFactory::for($providerAccountId)`.
458 tests pass (`composer ci` clean — CS, PHPStan, and the full unit suite); 39 integration tests
self-skip without real sandbox credentials, the same convention used throughout the project for
anything needing external infrastructure. No database schema changed this phase; one stale seed
data error (Mollie's `customer_portal` capability) was found and corrected. Six CLI scripts now
exist across the three providers for standalone manual verification. The next phase (23) adds
Ziraat; wiring any of the four providers into the actual payment-creation flow is Phase 24.
