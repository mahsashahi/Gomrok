# Phase 19 — Resolution API endpoints

## Execution Summary

- Phase: 19 — Resolution API endpoints
- Start Datetime: 2026-09-11 15:50
- End Datetime: 2026-09-11 16:40
- Estimated Duration: 3–5h
- Actual Duration: 50m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

`GET /api/v1/packages` and `GET /api/v1/pricing/resolve` already existed from Phases 13–14. This
phase added the two remaining pieces of the resolution API surface named in `.claude/docs/Phases.md`:

- **`GET /api/v1/packages/{packageId}`** (`PackageDetailAction`) — one package from the exact
  same resolved catalogue `PriceCatalog::resolve()` produces for the list endpoint, so the two
  can never disagree. `{packageId}` accepts either the numeric `packages.id` or the package
  `code` (Q1). A package that exists but isn't available/sellable in the requested
  country/currency/method context is `404 package.not_found_in_context` (Q2) — the same
  fail-closed posture `pricing/resolve` already applies to an unresolvable combination.
- **`GET /api/v1/vouchers/validate`** (`VouchersValidateAction` + new
  `Vouchers\Application\ValidateVoucher\ValidateVoucherHandler`) — a non-locking eligibility +
  discount preview (Q3): resolves the package's price internally via `PriceResolver` (never
  trusts a client-supplied amount), runs the same `VoucherEligibilityEvaluator` that
  `ReserveVoucherRedemptionHandler` uses as its authoritative locked gate — here unlocked, as a
  pre-check — and, only when eligible, the Phase 17 `VoucherDiscountCalculator`. Nothing is
  reserved, redeemed, or written. `GET`, not the `POST` CLAUDE.md suggests (Q4), extending the
  precedent already set by `pricing/resolve`: a pure read stays clear of the `/api/v1`
  write-idempotency requirement.

Both new actions were tested with action-level direct-invoke tests (Q5), matching the existing
`MeActionTest`/`HealthActionTest` convention — no full-app Slim harness was introduced.

## Files Created

- `src/Modules/Vouchers/Application/ValidateVoucher/ValidateVoucherCommand.php` — input DTO
  (raw string fields; enum parsing happens in the handler).
- `src/Modules/Vouchers/Application/ValidateVoucher/ValidateVoucherResult.php` — output DTO
  (`eligible`, `reasons`, voucher identity, resolved price, nullable discount fields).
- `src/Modules/Vouchers/Application/ValidateVoucher/ValidateVoucherHandler.php` — composes
  `Packages\Application\PackageDirectory`, `Pricing\Application\PriceResolver`,
  `Vouchers\Domain\VoucherRepository`, `VoucherEligibilityEvaluator`, `VoucherDiscountCalculator`.
- `src/Http/Api/PackageDetailAction.php` — `GET /api/v1/packages/{packageId}`.
- `src/Http/Api/VouchersValidateAction.php` — `GET /api/v1/vouchers/validate`.
- `tests/Unit/Http/PackageDetailActionTest.php` — 6 tests (by code, by numeric id, missing
  country, another client's package by id, unknown code, unavailable-in-context).
- `tests/Unit/Http/VouchersValidateActionTest.php` — 3 tests (eligible + discount preview,
  missing code validation error, unknown voucher 404 with no `discount` key).
- `tests/Unit/Modules/Vouchers/Application/ValidateVoucherHandlerTest.php` — 5 tests (eligible
  preview, ineligible with reasons, unknown voucher, unknown package, unknown payment method).
- `.claude/PhaseResults/Phase19Result.md` (this file).

## Files Modified

- `src/Config/routes.php` — registered `GET /api/v1/packages/{packageId}` (`PackageDetailAction`)
  and `GET /api/v1/vouchers/validate` (`VouchersValidateAction`) inside the existing
  authenticated `/api/v1` group; no change to the group's middleware stack.
- Documentation kept in lock-step (per `.claude/Rule.md` §5 and the standing doc rules):
  `.claude/docs/Architecture.md` (§3 unchanged — no new module; §8 Pricing/Vouchers HTTP bullets
  updated, new `ValidateVoucherHandler` bullet, §9 pipeline note, §13 deferred-work trimmed),
  `.claude/docs/Phases.md` (row 19 → ☑, as-built scope rewritten), `.claude/Changelog.md` (new
  dated entry), `.claude/FileIndex.md` (2 new Http Api rows, Vouchers module row updated),
  `.claude/knowledge/Knowledge.md` (new "Resolution API endpoints (Phase 19)" section),
  `.claude/docs/Commands.md` (new curl examples + explanation for both endpoints),
  `.claude/Orders.md` (new D22 row), `.claude/Voucher.md` (new §9 "Validate endpoint (Phase 19 —
  implemented)"; §10 decisions log gained two Phase 19 rows; §11 implementation pointers updated;
  §12 open questions — the whole file's §9–§11 renumbered to §10–§12 to make room).

## Implementation Details

- **`PackageDetailAction`**: resolves `{packageId}` by trying `ctype_digit()` first
  (`PackageDirectory::findById()`), else `PackageDirectory::find($clientId, $code)`. Explicitly
  checks `$summary->clientId !== $clientId` after an id-based lookup (unlike a code-based lookup,
  `findById()` doesn't scope by client) so another client's package by numeric id is `404
  package.not_found`, never leaked. Then validates `country` (mirroring `PackagesAction`),
  parses the optional `method`/`device` params the same way, calls `PriceCatalog::resolve()`, and
  linear-searches the returned list for the matching `package->id` — reusing
  `PriceCatalog::itemToArray()` for the response shape, so it is byte-for-byte the same as one
  item of the list endpoint's response.
- **`ValidateVoucherHandler`**: all raw-string validation (required fields, unknown enum values)
  happens inside the handler, not the Action — mirroring the Phase 17
  `ReserveVoucherRedemptionCommand`/`Handler` convention (raw `?string` fields on the command,
  `PaymentMethod::tryFrom()` etc. inside `handle()`) rather than the earlier
  `PricingResolveAction` convention (enum parsing inline in the Action). This keeps the Action a
  thin HTTP-shape mapper with zero business logic, per the Hexagonal Architecture Rules.
- **`VouchersValidateAction`**: builds a `ValidateVoucherCommand` from raw query params via one
  small `$str(key)` closure (empty-string-to-null coercion), calls the handler, and maps the
  `Result` to either a `problem()` response or a JSON body that omits the `discount` key entirely
  when `eligible` is `false` (rather than including it as `null`), since there is nothing to
  preview for an ineligible voucher.
- **Why the composition logic isn't in the Action** (a real design decision, not just style): the
  eligibility evaluator and discount calculator both require the actual Domain `Voucher`
  aggregate, not the read-only `VoucherSummary` a `VoucherDirectory` would provide. Injecting a
  Domain repository (`VoucherRepository`) into an Http Action would leak the Domain layer past
  Application, so the composition — package lookup, price resolve, voucher lookup, eligibility,
  discount — is entirely inside `ValidateVoucherHandler`, an Application-layer use case, matching
  the same pattern the Phase 18 Checkout handlers (e.g. `ReserveCheckoutVoucherHandler`) already
  use for cross-module composition.

## Database Changes

**No database changes.**

## API Changes

- **New:** `GET /api/v1/packages/{packageId}?country=…[&method=][&device=]` — `200` with the
  same shape as one item of `GET /api/v1/packages`; `404 package.not_found` for an unknown or
  cross-client id/code; `404 package.not_found_in_context` for a package that exists but isn't
  sellable in the requested market; `422 packages.country_required` / `422
  packages.unknown_method` for missing/invalid query params (matching `PackagesAction`'s
  existing validation codes).
- **New:** `GET /api/v1/vouchers/validate?package=…&country=…&code=…[&device=][&method=]
  [&purchase_type=][&interval=][&client_user_ref=][&first_purchase=]` — `200` with
  `{eligible, reasons, voucher, price}` plus a `discount` object only when `eligible` is `true`;
  `404 package.not_found` / `404 voucher.not_found`; `422 voucher_validate.*_required` /
  `422 voucher_validate.unknown_*` for missing/invalid params. No `Idempotency-Key` required
  (it's a `GET`).
- Both routes are authenticated the same way as every other `/api/v1` route
  (`AuthenticationMiddleware`, `ClientContext` scoping) — no change to `AuthenticationMiddleware`
  or `IdempotencyMiddleware` themselves.
- No existing endpoint's behavior or response shape changed.

## Tests and Validation

**Tests created:** 14 new tests total —
`tests/Unit/Http/PackageDetailActionTest.php` (6), `tests/Unit/Http/VouchersValidateActionTest.php`
(3), `tests/Unit/Modules/Vouchers/Application/ValidateVoucherHandlerTest.php` (5).

**Tests modified:** none.

**Commands actually run, this session:**

```
$ composer ci
```
```
PHP CS Fixer 3.95.24 ... Found 0 of 601 files that can be fixed
Note: Using configuration file /Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon.
 [OK] No errors
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...............................................................  63 / 346 ( 18%)
............................................................... 126 / 346 ( 36%)
............................................................... 189 / 346 ( 54%)
............................................................... 252 / 346 ( 72%)
............................................................... 315 / 346 ( 91%)
...............................                                 346 / 346 (100%)
Time: 00:00.238, Memory: 20.00 MB
OK (346 tests, 1407 assertions)
```

```
$ composer test:integration
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
SSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS                               35 / 35 (100%)
Time: 00:00.075, Memory: 10.00 MB
OK, but some tests were skipped!
Tests: 35, Assertions: 0, Skipped: 35.
```
(No local MySQL — every integration test self-skips; unaffected by this phase, which added no
database-touching integration test.)

`php -l` was run against all 5 new source files and all 3 new test files — all reported "No
syntax errors detected". `composer validate --no-check-publish` → valid; `composer update --lock
--no-install` → "Nothing to modify in lock file".

**Real captured HTTP-level evidence** (a standalone scratchpad script instantiating the real
`PackagesAction`, `PackageDetailAction`, and `VouchersValidateAction` against in-memory
repositories and a real Slim PSR-7 request/response pair, dispatching 8 real requests):

```
=== Phase 19 — Resolution API endpoints ===

--- GET /api/v1/packages?country=DE ---
200 application/json
{"country":"DE","currency":"EUR","packages":[{"id":1,"code":"pro","name":"Pro","description":null,"badge":null,"highlighted":false,"client_package_id":null,"price":{"amount_minor":2900,"amount":"29.00","currency":"EUR","source":"baseline","pricing_group":"default"},"purchase_types":[{"type":"one_time_payment","has_trial":false,"trial_days":null,"duration_months":null}],"available_methods":[],"available_provider_account_ids":[],"metadata":null}]}

--- GET /api/v1/packages/pro?country=DE ---
200 application/json
{"id":1,"code":"pro","name":"Pro", ... "price":{"amount_minor":2900,"amount":"29.00","currency":"EUR", ...}}

--- GET /api/v1/packages/1?country=DE (by numeric id) ---
200 application/json
{"id":1,"code":"pro", ...}

--- GET /api/v1/packages/pro?country=US (not available there -> 404 not_found_in_context) ---
404 application/json
{"type":"about:blank","title":"Package 'pro' is not available for country 'US'.","status":404,"code":"package.not_found_in_context","errorType":"not_found","context":{"package":"pro","country":"US"}}

--- GET /api/v1/packages/ghost?country=DE (unknown code -> 404 not_found) ---
404 application/json
{"type":"about:blank","title":"Package 'ghost' was not found.","status":404,"code":"package.not_found","errorType":"not_found","context":{}}

--- GET /api/v1/vouchers/validate?package=pro&country=DE&code=WELCOME10 ---
200 application/json
{"eligible":true,"reasons":[],"voucher":{"code":"WELCOME10","name":"Welcome"},"price":{"amount_minor":2900,"amount":"29.00","currency":"EUR"},"discount":{"nominal_minor":290,"applied_minor":290,"payable_minor":2610}}

--- GET /api/v1/vouchers/validate?package=pro&country=DE (missing code -> 422) ---
422 application/json
{"type":"about:blank","title":"A voucher \"code\" is required.","status":422,"code":"voucher_validate.code_required","errorType":"validation","context":{}}

--- GET /api/v1/vouchers/validate?package=pro&country=DE&code=GHOST (unknown voucher -> 404) ---
404 application/json
{"type":"about:blank","title":"Voucher 'GHOST' was not found for this client.","status":404,"code":"voucher.not_found","errorType":"not_found","context":{}}
```

(Full untruncated output is reproducible via
`/private/tmp/claude-502/-Users-mahsa-PhpstormProjects-Gomrok/865d2ad8-0d3c-42e9-899b-646b64ae7e45/scratchpad/Phase19Evidence.php`.)

## Technical Decisions

- **Q1 — `{packageId}` accepts numeric id or code:** matches the existing "id-or-reference"
  convention from the Phase 18 Checkout CLI, removing a class of avoidable round-trips for API
  consumers that only know one form of the identifier.
- **Q2 — unavailable-in-context is `404 package.not_found_in_context`, not a `200` with a flag:**
  consistent with the fail-closed posture `PriceResolver`/`PackageCatalog` already apply
  everywhere, and with what `pricing/resolve` already does for an unresolvable combination.
- **Q3 — eligibility + discount preview, price resolved internally:** the only option that
  actually answers "can I use this voucher and what would it save me" without ever trusting a
  client-supplied amount.
- **Q4 — `GET`, not `POST`:** extends the `pricing/resolve` precedent rather than adding a new
  per-route idempotency exemption or forcing an `Idempotency-Key` onto a call that mutates
  nothing.
- **Q5 — action-level direct-invoke tests:** matches the two tests that already exist
  (`MeActionTest`, `HealthActionTest`); the middleware stack itself already has its own dedicated
  unit tests, so a full-app harness would duplicate that coverage for no new signal.

## Problems Encountered

- `composer stan` initially flagged `ctype_digit($ref) && $ref !== ''` in `PackageDetailAction`
  as an always-true redundant check (`notIdentical.alwaysTrue`) — `ctype_digit()` already implies
  a non-empty string.
- The first draft of `VoucherEligibilityHandlerTest`'s "ineligible" case reused the same voucher
  (`WELCOME10`) with `setUsageLimits(null, 1, null, …)` for both the happy-path and ineligible
  tests, which would have made the happy-path test fail too (a `max_per_user` limit with no
  `client_user_ref` supplied makes *any* request ineligible via `voucher.client_user_required`).
- PHPStan flagged `offsetAccess.nonOffsetAccessible` across both new Http test files —
  `json_decode(..., true)` returns `mixed`, and array-offset access on `mixed` is rejected
  without a narrowing `@var` annotation.

## Resolutions

- Simplified the check to `ctype_digit($ref)` alone (the redundant `$ref !== ''` was removed).
- Split the ineligible-path fixture into a second, dedicated voucher (`LIMITED1`, once-per-user,
  already redeemed by `user-1`) so the happy-path voucher (`WELCOME10`) carries no usage limits
  and both tests assert what they intend to.
- Added precise `@var array{...}` shape annotations (not a blanket `array<string, mixed>`, which
  still fails on nested-key access) after every `json_decode()` call in the new test files,
  matching the existing `MeActionTest` convention.

## Deferred Work

- Package-list "voucher eligibility when safe to expose" (an optional CLAUDE.md field on the
  package response) — not implemented; `GET /api/v1/vouchers/validate` is the mechanism CLAUDE.md
  actually names for checking one voucher, and evaluating every active voucher against every
  catalogue package on every list call was judged out of scope for this phase.
- Payments passing a real payment id as the voucher `attempt_reference` — Phase 20.
- The voucher stale-reservation sweep — Phase 29 (unaffected by this phase; `validate` never
  reserves anything, so it cannot contribute stale reservations).

## Final Result

The full resolution-API surface named in `.claude/docs/Phases.md` for Phase 19 now exists:
clients can list packages, fetch one package's detail, resolve a price, and preview a voucher's
eligibility and discount — all without Gomrok ever trusting a client-supplied price, and all
`GET` (no mutation, no idempotency key needed). `composer ci` is green (600 files analyzed by
PHPStan with 0 errors, 346 unit tests / 1407 assertions passing, up from 332/1354 before this
phase); the CI-only integration suite (35 tests) is unaffected and continues to self-skip
locally. All required documentation is in lock-step with the code as built.

**Next recommended phase:** Phase 20 — Payments module: aggregate & lifecycle.
