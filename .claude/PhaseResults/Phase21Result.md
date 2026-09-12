# Phase 21 — Provider adapter port & Stripe adapter

## Execution Summary

- Phase: 21 — Provider adapter port & Stripe adapter
- Start Datetime: 2026-09-11 18:45
- End Datetime: 2026-09-11 20:11
- Estimated Duration: 6–9h
- Actual Duration: 1h 26m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

The one interface every provider implements, plus the first real provider. A new
`Modules\Providers\Application\Adapter\` namespace holds the port: the required core
`PaymentProviderPort` (`createPayment`, `getPaymentStatus`, `verifyWebhookSignature`,
`parseWebhook`, `mapProviderStatusToInternalStatus`, `getCapabilities`) plus five optional
capability interfaces (`SupportsSubscriptions`, `SupportsRefunds`, `SupportsAuthCapture`,
`SupportsCustomerPortal`, `SupportsManualPolling`) — the hybrid shape already decided in Phase 1
Q5 and sketched in `Architecture.md` §8, now turned into real, tested code.

A new `StripeAdapter` (`Modules\Providers\Infrastructure\Adapter\Stripe\`) implements the core
plus all four applicable capability interfaces (everything but `SupportsManualPolling`, which
has no implementer until Ziraat, Phase 23), using the new `stripe/stripe-php` SDK dependency —
used only inside this one class, per Hexagonal Architecture Rule 5. `createPayment()` and
`createSubscription()` both create a Stripe Checkout Session (hosted UI); `getPaymentStatus()`
reads the session back, preferring the underlying PaymentIntent's status when Stripe has
expanded it, for a more precise mapping; `getCapabilities()` delegates to the Phase 8 seeded
`ProviderTypeDeclarations::findByCode('stripe')` rather than hardcoding a duplicate capability
list. A new `DefaultProviderAdapterFactory` resolves a `provider_account_id` to a fresh,
credentialed adapter instance, with one `match` arm per provider type — only `'stripe'` this
phase; any other type throws `UnsupportedProviderType`.

A real, documented mapping decision worth calling out: `StripeStatusMapper` (pure, dependency-free,
fully unit-testable) never maps an unrecognised raw status to a terminal or false-positive
internal status — it always falls back to `PaymentStatus::Pending`, honouring CLAUDE.md's
"unknown provider statuses must be stored safely and handled carefully" literally, since `Pending`
is the only status that asserts nothing the mapper can't back up.

Per Phase 21 Q5, this phase stops at a standalone, fully-tested adapter + CLI — it is **not**
wired into the Payments module's `RecordProviderTransactionHandler` yet; that orchestration is
explicitly Phase 24's job (the payment-creation flow).

## Files Created

- **Providers module — Application/Adapter (the port)**:
  `PaymentProviderPort.php`, `SupportsSubscriptions.php`, `SupportsRefunds.php`,
  `SupportsAuthCapture.php`, `SupportsCustomerPortal.php`, `SupportsManualPolling.php`,
  `ProviderAdapterFactory.php`.
- **Port DTOs**: `CreatePaymentCommand.php`, `ProviderPaymentResult.php`,
  `ProviderPaymentStatus.php`, `CreateSubscriptionCommand.php`, `ProviderSubscriptionResult.php`,
  `ProviderSubscriptionStatus.php`, `ProviderRefundResult.php`,
  `ProviderBillingPortalSession.php`, `RawWebhook.php`, `ParsedWebhookEvent.php`.
- **Exceptions**: `ProviderAdapterException.php`, `ProviderRequestFailed.php`,
  `ProviderAuthenticationFailed.php`, `ProviderWebhookVerificationFailed.php`,
  `UnsupportedProviderType.php`.
- **Providers module — Infrastructure**:
  `Adapter/Stripe/StripeAdapter.php`, `Adapter/Stripe/StripeStatusMapper.php`,
  `DefaultProviderAdapterFactory.php`.
- CLI: `bin/StripeCreateCheckoutSession.php`, `bin/StripeGetPaymentStatus.php`.
- Tests: `tests/Unit/Modules/Providers/Infrastructure/Adapter/Stripe/{StripeStatusMapperTest,
  StripeAdapterTest}.php`, `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php`,
  `tests/Integration/StripeAdapterLiveTest.php`; `tests/Support/{FakeStripeHttpClient,
  StubProviderAccountCredentials}.php`.
- `.claude/PhaseResults/Phase21Result.md` (this file).

## Files Modified

- `composer.json` — added `stripe/stripe-php:^17.0` (resolved to v17.6.0); added
  `stripe:create-checkout-session`/`stripe:get-payment-status` scripts + descriptions;
  `composer.lock` refreshed.
- `src/Modules/Providers/Application/ProviderAccountDirectory.php` — gained
  `findById(int $id): ?ProviderAccountSummary` (additive; no client scoping, matching how the
  factory only has an account id to work with).
- `src/Modules/Providers/Infrastructure/PdoProviderAccountDirectory.php` — implements the new
  `findById()`.
- `tests/Support/StubProviderAccountDirectory.php` — implements the new `findById()`.
- `src/Modules/Providers/Infrastructure/definitions.php` — wired `ProviderAdapterFactory` to
  `DefaultProviderAdapterFactory`.
- `.env.example` — documented the optional `STRIPE_TEST_SECRET_KEY`.
- `tests/Integration/MigrationRoundTripTest.php` — unchanged (no schema change this phase).
- Documentation kept in lock-step (per `.claude/Rule.md` §5 and the standing doc rules):
  `.claude/docs/database-design.md` (table-count summary row only — no new tables);
  `.claude/docs/Architecture.md` (§3 Providers row, §8 rewritten from the Phase 1 sketch to the
  as-built design, §13 deferred work); `.claude/docs/Phases.md` (row 21 → ☑, as-built scope);
  `.claude/Changelog.md` (new dated entry); `.claude/FileIndex.md` (new Providers-module detail,
  new bin/test-support rows); `.claude/knowledge/Knowledge.md` (new "Provider adapter port &
  Stripe adapter (Phase 21)" section); `.claude/docs/Commands.md` (new `stripe:*` CLI
  documentation, corrected a stale "no real provider adapter yet" note on the Payments CLI
  section); `.claude/Orders.md` (new D24 row).
  `.claude/docs/database-diagram.md`/`.html` and `.claude/docs/db_explain.md` are **unchanged** —
  no database structure changed this phase, so there is nothing for them to reflect.

## Implementation Details

- **Cross-module enum dependency, deliberately**: `PaymentProviderPort::mapProviderStatusToInternalStatus()`
  returns `Payments\Domain\PaymentStatus` directly — a Providers-module Application interface
  depending on a Payments-module Domain enum. This mirrors the existing, established precedent of
  `PurchaseType`/`PaymentMethod` (Providers' own Domain enums) already being imported directly by
  Checkout/Vouchers/Pricing/Payments — lightweight, behavior-free enums are treated as shared
  vocabulary across module lines in this codebase, unlike aggregates/repositories which stay
  behind Application-published ports.
- **`StripeAdapter` error handling**: every SDK call is wrapped in
  `catch (AuthenticationException $e) { throw new ProviderAuthenticationFailed(...); } catch
  (ApiErrorException $e) { throw new ProviderRequestFailed(...); }` — `AuthenticationException`
  extends `ApiErrorException` in the Stripe SDK, so the narrower catch must come first.
- **`getPaymentStatus()`** retrieves the Checkout Session with `expand => ['payment_intent']`; if
  Stripe returns the expanded `PaymentIntent` object (not just its id string), the adapter prefers
  `StripeStatusMapper::fromPaymentIntent()` (the richer vocabulary) over
  `fromCheckoutSession()` for the mapped status, while still reporting the session's own raw
  status as `ProviderPaymentStatus::$rawStatus`.
- **Webhook handling** uses the real `\Stripe\Webhook::constructEvent()` for both
  `verifyWebhookSignature()` (catches `SignatureVerificationException`/`UnexpectedValueException`,
  returns `bool`) and `parseWebhook()` (same call, but throws
  `ProviderWebhookVerificationFailed` on failure) — real HMAC verification, no custom signature
  logic reimplemented.
- **`parseWebhook()`** reads the event's nested resource (`$event->data->object`, a generic
  `\Stripe\StripeObject`) via array access (`$object['id']`, `$object['status']`) rather than
  property access, since `StripeObject` has no statically-known properties for an arbitrary
  resource type — each access is guarded with `is_string()` before use.
- **`DefaultProviderAdapterFactory`**: `for(int $providerAccountId)` looks up the account via
  `ProviderAccountDirectory::findById()` (new this phase), the decrypted secret via
  `ProviderAccountCredentials::secretFor()` (Phase 9), and `match`es `$account->providerTypeCode`
  to a concrete adapter — currently only `'stripe' => new StripeAdapter(new
  StripeClient($secret), $this->declarations)`; any other code throws `UnsupportedProviderType`.
- **Testing the real SDK without real network or credentials**: `tests/Support/FakeStripeHttpClient.php`
  implements `\Stripe\HttpClient\ClientInterface` as a queued-response double, swapped in via the
  SDK's own `\Stripe\ApiRequestor::setHttpClient()` (a process-global setter). This lets
  `StripeAdapterTest` exercise the actual Stripe SDK's request-building and response/exception
  parsing — a queued HTTP 401 with a Stripe-shaped error body genuinely produces a real
  `\Stripe\Exception\AuthenticationException`, which the adapter genuinely catches and converts.
  `tearDown()` resets the global client to a fresh `\Stripe\HttpClient\CurlClient` so no fake
  leaks into unrelated tests.

## Database Changes

**No database changes.** `ProviderAccountDirectory::findById()` is an additive Application-layer
port method, not a schema change.

## API Changes

**No API changes.** All access is via the new `stripe:*` CLI scripts
(`composer stripe:create-checkout-session`, `composer stripe:get-payment-status`) — no HTTP
endpoint mounts this phase (deferred to Phase 24, the payment-creation flow).

## Tests and Validation

**Tests created:** 25 new tests total.
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/Stripe/StripeStatusMapperTest.php` (16) —
  every `fromCheckoutSession()` branch (complete+paid, complete+no_payment_required,
  complete+unpaid, open, expired, an unrecognised session status), every `fromPaymentIntent()`
  branch (all 7 named PaymentIntent statuses plus an unrecognised one), case/whitespace
  insensitivity, and `fromSubscription()`'s provisional pass-through. This is the phase's own
  named exit criterion.
- `tests/Unit/Modules/Providers/Infrastructure/Adapter/Stripe/StripeAdapterTest.php` (6) — a real
  Checkout Session creation response parsed correctly (including asserting the exact request
  params sent), a real Stripe 401 producing `ProviderAuthenticationFailed`, a real 402 producing
  `ProviderRequestFailed`, a real valid webhook signature verified and parsed, a tampered
  signature rejected by both `verifyWebhookSignature()` (returns `false`) and `parseWebhook()`
  (throws), and `getCapabilities()` delegating to the seeded declaration.
- `tests/Unit/Modules/Providers/Infrastructure/DefaultProviderAdapterFactoryTest.php` (3) — builds
  a `StripeAdapter` for a stripe account, rejects a provider type with no adapter yet, rejects an
  unknown account.
- `tests/Integration/StripeAdapterLiveTest.php` (1, CI-only) — a genuine Stripe test-mode API
  call: creates a real Checkout Session and reads its status back. Self-skips without a real
  `STRIPE_TEST_SECRET_KEY` in the environment.

**Tests modified:** none.

**Commands actually run, this session:**

```
$ composer ci
```
```
PHP CS Fixer 3.95.24 ... Found 0 of 686 files that can be fixed
Note: Using configuration file /Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon.
 [OK] No errors
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...............................................................  63 / 401 ( 15%)
............................................................... 126 / 401 ( 31%)
............................................................... 189 / 401 ( 47%)
............................................................... 252 / 401 ( 62%)
............................................................... 315 / 401 ( 78%)
............................................................... 378 / 401 ( 94%)
.......................                                         401 / 401 (100%)
Time: 00:00.245, Memory: 24.00 MB
OK (401 tests, 1553 assertions)
```

```
$ composer test:integration
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
SSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS                             37 / 37 (100%)
Time: 00:00.030, Memory: 10.00 MB
OK, but some tests were skipped!
Tests: 37, Assertions: 0, Skipped: 37.
```

**Honest accounting of what "integration tests against Stripe test mode" means this session:**
this sandbox has no real Stripe account, so `StripeAdapterLiveTest` — the test that would make a
genuine network call to Stripe's actual test-mode API — self-skips here, exactly like every
MySQL-dependent integration test in this suite self-skips without a database. What **is** real,
runnable, and verified in this session is `StripeAdapterTest`: it drives the actual Stripe PHP
SDK (`StripeClient`, `Webhook::constructEvent()`, the SDK's own HTTP-status-to-exception mapping)
through a fake transport, so the adapter's request-building, response-parsing, and error-handling
logic is genuinely exercised — only the network call itself is faked. Network access to
`api.stripe.com` was confirmed reachable from this environment (a bare `curl` returned a real
`401` from Stripe's actual API), but no real secret key was available to authenticate with.

`php -l` was run against every new source file, the two CLI scripts, and every new test file —
all reported "No syntax errors detected". `composer validate --no-check-publish` → valid;
`composer update --lock --no-install` → "Nothing to modify in lock file".

**CLI evidence (real captured output, each script invoked with no arguments):**

```
$ php bin/StripeCreateCheckoutSession.php
usage: php bin/StripeCreateCheckoutSession.php --client=<slug|id> --account=<account-slug> --attempt=<ref> --amount-minor=<n> --currency=<ISO> --description=<text> --success-url=<url> --cancel-url=<url> [--customer-email=]

$ php bin/StripeGetPaymentStatus.php
usage: php bin/StripeGetPaymentStatus.php --client=<slug|id> --account=<account-slug> --reference=<checkout-session-id>
```

**End-to-end evidence (real captured output)** — a standalone script wiring the real
`DefaultProviderAdapterFactory` + `StripeAdapter` against the fake Stripe transport, exercising
the full port surface:

```
=== Phase 21 — provider adapter port + Stripe adapter ===

factory resolves stripe account #1                         -> Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter
getCapabilities() (from the Phase 8 seeded declaration)    -> 17 capabilities (has hosted_checkout=yes, refund=yes)
factory resolves ziraat account #2 (no adapter yet)        -> ERROR  No adapter implemented for provider type 'ziraat' yet.
factory resolves unknown account #999                      -> ERROR  Provider account 999 was not found.

--- createPayment() against a fake Stripe transport (real SDK, no network) ---

createPayment()                                            -> #cs_test_abc123 [open]
https://checkout.stripe.com/c/pay/cs_test_abc123

--- getPaymentStatus() reads the session back, with an underlying PaymentIntent expanded ---

getPaymentStatus()                                         -> raw=complete mapped=paid (via payment_intent pi_test_xyz)

--- a real Stripe 401 genuinely produces ProviderAuthenticationFailed ---

createPayment() with a bad key                             -> ERROR  Gomrok\Modules\Providers\Application\Adapter\ProviderAuthenticationFailed: Invalid API Key provided

--- webhook signature verification (real HMAC, no network) ---

verifyWebhookSignature() with the correct secret           -> true
verifyWebhookSignature() with the wrong secret              -> false
parseWebhook()                                              -> type=checkout.session.completed reference=cs_test_abc123 rawStatus=complete
```

## Technical Decisions

- **Q1 — one core `createPayment()` hosted-flow method:** matches the existing `Architecture.md`
  §8 sketch; CLAUDE.md's `createCheckoutSession` is the same action under Stripe's own product
  name, not a second required method — every provider in this project is hosted-redirect-only.
- **Q2 — adapters throw a typed `ProviderAdapterException`:** a direct application of the
  already-decided Phase 3 Q3 error model (infra faults throw) and the existing DB-adapter
  convention, rather than a second, inconsistent pattern for provider adapters specifically.
- **Q3 — raw `int` minor units + `string` currency in port DTOs:** matches `Payment` (this
  port's actual caller) and every DTO along the same data path; `Money` stays reserved for
  arithmetic-heavy contexts.
- **Q4 — `ProviderAdapterFactory::for($providerAccountId)`:** keeps `PaymentProviderPort`'s
  method signatures clean and the adapter itself stateless; one obvious place per provider type
  for Phases 22–23 to register their own adapters.
- **Q5 — standalone adapter + CLI + tests only this phase:** matches this phase's own exit
  criteria exactly; avoids building payment-creation orchestration ahead of Phase 24, the phase
  actually scoped to own it.

## Problems Encountered

- The Stripe PHP SDK ships detailed PHPStan-compatible array-shape docblocks for its service
  methods' `$params` (e.g. `checkout->sessions->create()`), which initially rejected several of
  the adapter's request-building calls: `customer_email` typed as `string`, not `string|null`
  (the shape has no null variant — it's an optional key, not a nullable value);
  `payment_intent_data` requiring a specific narrow shape rather than a generic
  `array<string, mixed>`.
- Several Stripe SDK response properties (`Checkout\Session::$status`/`$url`,
  `PaymentIntent::$id`/`$status`, etc.) are typed via `@property` docblocks as either definitely
  non-nullable `string` or `null|string` — several `(string)`/`(int)` casts on already-known-string
  values triggered PHPStan's "useless cast" check, and one un-guarded nullable property
  (`Session::$status`) was passed where a non-nullable `string` was required.
- `Stripe\Event::$data->object` is a generic `\Stripe\StripeObject` with no statically-known
  properties for an arbitrary event's resource — direct `->id`/`->status` property access failed
  PHPStan's "undefined property" check.
- One instance of PHPStan's `nullsafe.neverNull` rule fired on
  `$this->declarations->findByCode(...)?->capabilities ?? ProviderCapabilities::none()` even
  though the interface's return type is genuinely nullable.
- A redundant `assertInstanceOf()` on an already-statically-typed return value (twice, in
  `StripeAdapterTest` and `StripeAdapterLiveTest`) triggered PHPStan's
  `staticMethod.alreadyNarrowedType` check.
- `ApiRequestor::setHttpClient(null)` in `tearDown()` (intending to reset to the SDK's lazy
  default) failed — the method's parameter type doesn't accept `null`.

## Resolutions

- Omitted `customer_email` from the request params array entirely when it's `null`, instead of
  passing the key with a `null` value; replaced the generic `?array $paymentIntentData` parameter
  with a narrower `?string $captureMethod`, building the properly-shaped `payment_intent_data`
  array only when needed.
- Added `?? 'open'` / `?? ''` fallbacks at each nullable-property read site (matching this
  project's established "safe non-committal default" philosophy — see `StripeStatusMapper`'s own
  unknown-status handling), and removed every cast on a property PHPStan already knew was a
  non-nullable `string`/`int`.
- Switched to array access (`$object['id'] ?? null`, `$object['status'] ?? null`) with
  `is_string()` guards instead of property access on the generic `StripeObject`.
- Replaced the nullsafe-and-`??` combo with an explicit `if ($declaration === null) { return
  ProviderCapabilities::none(); }` guard — matching the pattern `ProviderCapabilityResolver`
  (Phase 8) already uses for the exact same `findByCode()` call.
- Removed the redundant `assertInstanceOf()` calls; the surrounding code's static types already
  guarantee them.
- Replaced `setHttpClient(null)` with `setHttpClient(new \Stripe\HttpClient\CurlClient())` —
  constructs a fresh, real default transport instead of relying on lazy re-initialization.

## Deferred Work

- Wiring `StripeAdapter` into the Payments module's `RecordProviderTransactionHandler` (and
  `CreatePaymentHandler`, for the initial checkout-session creation) — explicitly Phase 24 (Q5).
- Mollie and PayPal adapters — Phase 22. Ziraat adapter (the first `SupportsManualPolling`
  implementer) — Phase 23.
- `mapProviderSubscriptionStatusToInternalStatus()`'s return type is a provisional `string`
  pass-through; expected to become a real `SubscriptionStatus` enum once the `Subscriptions`
  module exists (Phase 26).
- A `gateway_references` writer recording the Checkout Session id / PaymentIntent id as reverse
  lookups — awaits a real caller, expected alongside the Phase 24 wiring.
- Any HTTP endpoint for payments — Phase 24.

## Final Result

Gomrok now has a real, working provider-adapter port and its first real provider implementation:
`StripeAdapter` can create a hosted Checkout Session (one-time or subscription), read a payment's
status back with PaymentIntent-level precision, verify and parse real Stripe webhooks, declare
its capabilities from the single Phase 8 source of truth, and safely handle any status it doesn't
recognise — all provable without a real Stripe account via a fake-transport test harness that
exercises the actual SDK. `composer ci` is green (685 files analyzed by PHPStan with 0 errors,
401 unit tests / 1553 assertions passing, up from 376 tests / 1507 assertions before this phase —
25 new tests); the CI-only integration suite (37 tests, up from 36) is unaffected in kind and
continues to self-skip locally, including the new genuine-Stripe-API test which is expected to
run for real wherever `STRIPE_TEST_SECRET_KEY` is configured. All required documentation is in
lock-step with the code as built.

**Next recommended phase:** Phase 22 — Mollie & PayPal adapters.
