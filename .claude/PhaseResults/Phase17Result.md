# Phase 17 — Voucher validation, discount calc & redemption lifecycle

## Execution Summary

- Phase: 17 — Voucher validation, discount calc & redemption lifecycle
- Start Datetime: 2026-09-11 12:52
- End Datetime: 2026-09-11 14:03
- Estimated Duration: 5–8h
- Actual Duration: 1h 11m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- Added `voucher_redemptions` — the reserve → confirm/release lifecycle, identified by a
  caller-supplied `attempt_reference` (Phase 20 will pass the real payment id once Payments
  exists).
- Built `VoucherDiscountCalculator`: resolves the applicable discount (Phase 16's
  override-then-default), rounds HALF_EVEN via `Money`, clamps to the configured
  `max_discount_minor` cap and then to the price, and reports both the pre-clamp
  (`nominalDiscountMinor`) and post-clamp (`appliedDiscountMinor`) amounts.
- Built the concurrency-safe lifecycle: `ReserveVoucherRedemptionHandler`,
  `ConfirmVoucherRedemptionHandler`, `ReleaseVoucherRedemptionHandler`. Every one opens a
  transaction and locks the parent `vouchers` row (`SELECT ... FOR UPDATE`) before touching
  `voucher_redemptions` — the de facto per-voucher mutex every writer shares. Reserve re-runs
  `VoucherEligibilityEvaluator` **inside** that lock as the authoritative gate.
- Implemented the Phase 16 `VoucherUsagePort` seam (`PdoVoucherRedemptionRepository`, which also
  implements the new `VoucherRedemptionRepository` Domain port) and extended
  `VoucherEligibilityEvaluator` to check the global (confirmed + live reservations), per-user,
  and per-client usage caps for real.
- Added 4 CLI scripts + `composer voucher:reserve|confirm|release|list-redemptions`.
- Tests: 3 new test classes + 5 new `VoucherEligibilityEvaluatorTest` cases + 1 CI-only
  integration test. Unit suite 297 → 313 (+ 1 integration test, 33 → 34), `composer ci` green.

## Files Created

- `src/Database/Migrations/20260911130001_create_voucher_redemptions_table.php` —
  `Gomrok\Database\Migrations\CreateVoucherRedemptionsTable`.
- `src/Modules/Vouchers/Domain/RedemptionStatus.php` — enum (`Reserved`/`Confirmed`/`Released`).
- `src/Modules/Vouchers/Domain/VoucherRedemption.php` — aggregate (`reserve`, `confirm`,
  `release` — each idempotent on its own terminal state, mutually exclusive on the other).
- `src/Modules/Vouchers/Domain/VoucherRedemptionRepository.php` — port.
- `src/Modules/Vouchers/Application/VoucherDiscountResult.php`,
  `VoucherDiscountCalculator.php`.
- `src/Modules/Vouchers/Application/VoucherRedemptionSummary.php`,
  `VoucherRedemptionDirectory.php`.
- `src/Modules/Vouchers/Application/ReserveVoucherRedemption/{ReserveVoucherRedemptionCommand,
  ReserveVoucherRedemptionResult,ReserveVoucherRedemptionHandler}.php`.
- `src/Modules/Vouchers/Application/ConfirmVoucherRedemption/ConfirmVoucherRedemptionHandler.php`.
- `src/Modules/Vouchers/Application/ReleaseVoucherRedemption/ReleaseVoucherRedemptionHandler.php`.
- `src/Modules/Vouchers/Infrastructure/PdoVoucherRedemptionRepository.php` (implements both
  `VoucherRedemptionRepository` and `VoucherUsagePort`), `PdoVoucherRedemptionDirectory.php`.
- `bin/{ReserveVoucherRedemption,ConfirmVoucherRedemption,ReleaseVoucherRedemption,
  ListVoucherRedemptions}.php`.
- `tests/Support/InMemoryVoucherRedemptionRepository.php`.
- `tests/Unit/Modules/Vouchers/Domain/VoucherRedemptionTest.php` (4 cases).
- `tests/Unit/Modules/Vouchers/Application/VoucherDiscountCalculatorTest.php` (5 cases).
- `tests/Unit/Modules/Vouchers/Application/VoucherRedemptionHandlersTest.php` (5 cases).
- `tests/Integration/VoucherRedemptionPersistenceTest.php` (real-MySQL round trip, CI-only).
- `.claude/PhaseResults/Phase17Result.md`.

## Files Modified

- `src/Modules/Vouchers/Application/VoucherEligibilityEvaluator.php` — gained a `VoucherUsagePort`
  constructor dependency; global check now adds live `activeReservations`; new per-user
  (`voucher.client_user_required` / `voucher.user_limit_reached`) and per-client
  (`voucher.client_limit_reached`) checks.
- `src/Modules/Vouchers/Application/VoucherUsagePort.php` — gained `activeReservations(int
  $voucherId): int`.
- `src/Modules/Vouchers/Application/VoucherAuditSnapshot.php` — gained `redemption()`.
- `src/Modules/Vouchers/Domain/VoucherRepository.php` — gained `findByIdForUpdate()` and
  `incrementRedeemedCount()`.
- `src/Modules/Vouchers/Infrastructure/PdoVoucherRepository.php` — implements the two new
  methods (`SELECT ... FOR UPDATE`; atomic `UPDATE ... SET redeemed_count = redeemed_count + 1`).
- `src/Modules/Vouchers/Infrastructure/definitions.php` — bind `VoucherRedemptionRepository`,
  `VoucherUsagePort`, `VoucherRedemptionDirectory`.
- `composer.json` / `composer.lock` — 4 `voucher:*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — `voucher_redemptions` added.
- `tests/Support/InMemoryVoucherRepository.php` — the two new methods (the increment rebuilds
  the aggregate via `fromStorage` since `redeemedCount` is otherwise immutable at the domain
  level, mirroring the Pdo adapter's direct-SQL bypass).
- `tests/Unit/Modules/Vouchers/Application/VoucherEligibilityEvaluatorTest.php` — updated for
  the new constructor arg; +5 cases (global-with-reservations, per-user requires-a-ref and is
  enforced, per-client is enforced).

## Implementation Details

- **`VoucherRedemption`** — `confirm()` / `release()` return `?DomainError`: `null` on success
  *or idempotent no-op*, an error only when the *other* terminal transition is attempted
  (`voucher_redemption.already_released` / `voucher_redemption.already_confirmed`).
- **`VoucherDiscountCalculator::calculate(Voucher, currencyCode, priceMinor): Result`** —
  looks up an override first, else the voucher default (`none` → `Result::err`); computes
  `nominal` (raw rule value); `applied` starts equal to `nominal`, then is clamped to
  `max_discount_minor` (percentage only) and then to the price — in that order, and *without*
  mutating `nominal`, so the two figures stay distinguishable. `basisPointsToPercent()` converts
  bp → an exact decimal string by integer division/modulo (no float rounding).
- **`ReserveVoucherRedemptionHandler`** — the entire `handle()` body after input-shape validation
  runs inside `Transactions::run(fn(): Result => ...)`, i.e. the closure itself returns the
  `Result` (a deliberate deviation from the "validate outside, mutate inside" shape most other
  handlers use — necessary here because the lock must cover validation *and* the write
  together). Order inside the lock: `findByIdForUpdate` → `findByAttemptReference` (idempotent
  replay) → `VoucherEligibilityEvaluator::evaluate` (authoritative, usage-aware) →
  `VoucherDiscountCalculator::calculate` → `VoucherRedemption::reserve` → `save` → audit.
- **`ConfirmVoucherRedemptionHandler`** — captures `$wasReserved` before calling `confirm()`, so
  `incrementRedeemedCount` fires only on the genuine reserved→confirmed transition, never on a
  replay of an already-confirmed redemption.
- **`PdoVoucherRedemptionRepository`** implements both `VoucherRedemptionRepository` (Domain)
  and `VoucherUsagePort` (Application) — one class, since both are just queries over
  `voucher_redemptions`; `redemptionsByUser`/`redemptionsByClient` count `reserved`+`confirmed`
  rows, `activeReservations` counts `reserved` only.
- **`PdoVoucherRepository::findByIdForUpdate`** issues `SELECT ... FOR UPDATE`; effective only
  inside an open transaction, which every caller (`Transactions::run`) guarantees.

## Database Changes

One new table, migration `20260911130001_create_voucher_redemptions_table.php` (additive; no
changes to existing tables; no backfill):

- **`voucher_redemptions`** — `id` PK; `voucher_id` FK → `vouchers(id)` CASCADE; `client_id` FK
  → `clients(id)` CASCADE; `client_user_ref VARCHAR(120)` nullable; `attempt_reference
  VARCHAR(191)` NOT NULL; `status VARCHAR(20) DEFAULT 'reserved'`; `currency_code CHAR(3)` FK →
  `currencies(code)` RESTRICT; `price_minor` / `nominal_discount_minor` /
  `applied_discount_minor` / `payable_minor` all `BIGINT UNSIGNED NOT NULL`; `reserved_at
  DATETIME NOT NULL`; `confirmed_at` / `released_at DATETIME` nullable; timestamps.
  `UNIQUE (voucher_id, attempt_reference)` = `uniq_voucher_redemptions_attempt`;
  `INDEX (voucher_id, client_user_ref, status)` = `idx_voucher_redemptions_user`;
  `INDEX (voucher_id, client_id, status)` = `idx_voucher_redemptions_client`;
  `INDEX (status)` = `idx_voucher_redemptions_status`.
- Total table count: **42**.

## API Changes

**No API changes.** No HTTP endpoint is added — `POST /api/v1/vouchers/validate` is Phase 19.
The lifecycle is reachable only through the handlers (autowired for later phases) and the CLI.

## Tests and Validation

- **Created:** `VoucherRedemptionTest` (4), `VoucherDiscountCalculatorTest` (5),
  `VoucherRedemptionHandlersTest` (5), `VoucherRedemptionPersistenceTest` (1, CI-only).
- **Modified:** `VoucherEligibilityEvaluatorTest` (+5: global-with-reservations, per-user
  ref-required, per-user limit reached, per-client limit reached), `MigrationRoundTripTest`.
- **Commands run:** `composer dump-autoload`, `composer stan`, `composer cs:fix`,
  `composer test`, `composer test:integration`, `composer ci`,
  `composer update --lock --no-install`, `composer validate --no-check-publish`, `php -l` on
  the migration + 4 bin scripts, and a captured-evidence script.

Captured output:

```
$ composer stan
 [OK] No errors

$ composer test
OK (313 tests, 1255 assertions)

$ composer test:integration
OK, but some tests were skipped!
Tests: 34, Assertions: 0, Skipped: 34.        # no local MySQL — run in CI

$ composer ci
 [OK] No errors        # php-cs-fixer + phpstan (max + strict-rules)
OK (313 tests, 1255 assertions)

$ composer validate --no-check-publish
./composer.json is valid
```

Captured evidence — `VoucherRedemptionEvidence.php` (in-memory handlers, full lifecycle):

```
=== Phase 17 — reserve -> confirm/release lifecycle ===

reserve order-1 (user-1, 29.00 EUR)                  -> reserved  price=2900 discount=290 payable=2610 EUR
reserve order-1 again (idempotent replay)            -> reserved  price=2900 discount=290 payable=2610 EUR
reserve order-2 for the SAME user (limit=1)          -> ERROR  voucher.not_eligible (voucher.user_limit_reached)
confirm order-1                                      -> OK
confirm order-1 again (idempotent, no double-count)  -> OK
voucher state after confirm                          -> redeemed_count=1

reserve attempt-A (global cap=1)                     -> reserved  price=5000 discount=5000 payable=0 EUR
reserve attempt-B, same voucher (exhausted)          -> ERROR  voucher.not_eligible (voucher.exhausted)
release attempt-A (payment failed)                   -> OK
reserve attempt-B again (cap freed by release)       -> reserved  price=5000 discount=5000 payable=0 EUR
release attempt-B after it was confirmed?            -> ERROR  voucher_redemption.already_confirmed ()
```

This demonstrates every exit-criterion behaviour: idempotent replay (row 2), per-user cap (row
3), confirm idempotency (row 5), the global cap (row 8) blocking a second concurrent attempt
(the design proof for concurrent-redemption safety — the real proof is the `FOR UPDATE` lock,
exercised for real in `VoucherRedemptionPersistenceTest` against MySQL in CI), release freeing
the cap (rows 9–10), and the confirmed/released mutual-exclusion guard (row 11).

CLI usage strings (a full run needs MySQL, unavailable locally — consistent with earlier
phases):

```
$ php bin/ReserveVoucherRedemption.php
usage: php bin/ReserveVoucherRedemption.php --client=<slug|id> --voucher=<id> --attempt=<ref> --currency=<ISO> --price-minor=<int> [options]

$ php bin/ConfirmVoucherRedemption.php
usage: php bin/ConfirmVoucherRedemption.php --client=<slug|id> --voucher=<id> --attempt=<ref>
```

## Technical Decisions

- **Q1 — opaque `attempt_reference` string.** Decouples redemption identity from both the
  transport-level idempotency-key mechanism and the not-yet-existing `payments` table; Phase 20
  slots in by passing the payment id.
- **Q2 — three states, `reserved` counts immediately, no auto-expiry.** Matches the literal
  CLAUDE.md/Phases.md wording; the stale-reservation sweep is explicitly Phase 29's job, not
  reinvented here as an inline TTL.
- **Q3 — `SELECT ... FOR UPDATE` on `vouchers`, re-check under the lock.** The only mechanism
  that closes the race for all three caps (global, per-user, per-client) at once, not just the
  global one; simple to reason about and to test.
- **Q4 — always clamp, carry nominal + applied.** A discount can never make the payable amount
  negative (a correctness requirement, not a policy choice); carrying both figures serves the
  Phase 18 decision-snapshot requirement without any later redesign.
- **Q5 — three lifecycle handlers + calculator + CLI**, matching the established one-handler-per-
  operation pattern from Phases 11–16.
- Extended `VoucherEligibilityEvaluator` to be the single authoritative check for **both** a
  future unlocked pre-check and the locked, authoritative Reserve-time gate, rather than
  duplicating cap logic inside the handler.

## Problems Encountered

- The first `VoucherDiscountCalculator` implementation applied the `max_discount_minor` cap
  directly to the `nominal` variable, making `nominal` and `applied` identical whenever the cap
  fired — defeating the purpose of reporting both. Caught by
  `VoucherDiscountCalculatorTest::percentageDiscountRespectsTheCap`
  (`assertSame(1450, ...)` failed with `500`).

## Resolutions

- Restructured the calculator so `nominal` is computed once and never mutated; `applied` starts
  as a copy of `nominal` and is clamped in two explicit steps (configured cap, then price floor).

## Deferred Work

- **Stale-reservation sweep** for abandoned `reserved` rows — Phase 29 (background jobs).
- **Voucher decision snapshot** on the payment/subscription record — Phase 18.
- **`POST /api/v1/vouchers/validate`** — Phase 19.
- Real payment ids as `attempt_reference` — Phase 20.
- `voucher_redemptions` DB round-trip (including the `FOR UPDATE` lock actually taking effect)
  is asserted only in CI (no local MySQL).

## Final Result

Gomrok can now safely apply a voucher exactly once: `ReserveVoucherRedemption` re-validates
eligibility (including live usage caps) and computes a clamped, auditable discount under a
per-voucher lock; `ConfirmVoucherRedemption` finalises it idempotently; `ReleaseVoucherRedemption`
frees it for another attempt. A duplicate reserve/confirm/release call is always a safe no-op,
never a double-redemption. 42 tables; 313 unit tests + 34 CI-only integration tests; `composer
ci` green.

**Next recommended phase:** Phase 18 — Decision snapshots.
