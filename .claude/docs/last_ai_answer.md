# Q: Phase 17 — Voucher validation, discount calc & redemption lifecycle (completion summary)

## What was implemented

Applying a voucher safely, exactly once. New table **`voucher_redemptions`** — the reserve →
confirm/release lifecycle, identified by a caller-supplied `attempt_reference` (Phase 20 will
pass the real payment id once Payments exists).

- **`VoucherDiscountCalculator`** resolves the applicable discount (override → default →
  inapplicable), rounds HALF_EVEN, clamps first to the configured `max_discount_minor` cap then
  to the price, and reports **both** `nominalDiscountMinor` (pre-clamp) and
  `appliedDiscountMinor` (post-clamp) — a discount can never make the payable amount negative.
- **Concurrency safety:** every one of `ReserveVoucherRedemptionHandler` /
  `ConfirmVoucherRedemptionHandler` / `ReleaseVoucherRedemptionHandler` opens a transaction and
  locks the parent `vouchers` row (`SELECT ... FOR UPDATE`) before touching
  `voucher_redemptions` — the de facto per-voucher mutex every writer shares. Reserve re-runs
  `VoucherEligibilityEvaluator` **inside** that lock as the authoritative gate.
- **`VoucherUsagePort`** (declared in Phase 16) is now implemented by
  `PdoVoucherRedemptionRepository`; the evaluator gained real global (confirmed + live
  reservations), per-user, and per-client cap checks.
- Idempotent throughout: a repeat `reserve` with the same attempt returns the existing
  redemption unchanged; `confirm`/`release` are idempotent on their own terminal state and
  reject the *other* one.
- 4 CLI commands (`composer voucher:reserve|confirm|release|list-redemptions`).

## Files created

- Migration `20260911130001_create_voucher_redemptions_table.php`.
- `src/Modules/Vouchers/Domain/{RedemptionStatus,VoucherRedemption,VoucherRedemptionRepository}.php`.
- `src/Modules/Vouchers/Application/{VoucherDiscountResult,VoucherDiscountCalculator,
  VoucherRedemptionSummary,VoucherRedemptionDirectory}.php`, `ReserveVoucherRedemption/`,
  `ConfirmVoucherRedemption/`, `ReleaseVoucherRedemption/`.
- `src/Modules/Vouchers/Infrastructure/{PdoVoucherRedemptionRepository,
  PdoVoucherRedemptionDirectory}.php`.
- `bin/{ReserveVoucherRedemption,ConfirmVoucherRedemption,ReleaseVoucherRedemption,
  ListVoucherRedemptions}.php`.
- `tests/Support/InMemoryVoucherRedemptionRepository.php`.
- `tests/Unit/Modules/Vouchers/{Domain/VoucherRedemptionTest,Application/
  VoucherDiscountCalculatorTest,Application/VoucherRedemptionHandlersTest}.php`.
- `tests/Integration/VoucherRedemptionPersistenceTest.php` (CI-only).
- `.claude/PhaseResults/Phase17Result.md`.

## Files updated

- `VoucherEligibilityEvaluator.php` (new `VoucherUsagePort` dep + 3 new checks),
  `VoucherUsagePort.php` (+`activeReservations`), `VoucherAuditSnapshot.php` (+`redemption()`),
  `VoucherRepository.php` + `PdoVoucherRepository.php` (+`findByIdForUpdate`,
  `incrementRedeemedCount`), `Infrastructure/definitions.php`.
- `composer.json`/`.lock`, `MigrationRoundTripTest`, `InMemoryVoucherRepository`,
  `VoucherEligibilityEvaluatorTest` (+5 cases).
- Docs: `database-design.md` (42 tables), `database-diagram.md` + `.html` (15/15 mermaid),
  `db_explain.md`, `Architecture.md` (§3/§8/§9/§13), `Phases.md` (row 17 ☑), `Changelog.md`,
  `FileIndex.md`, `Knowledge.md`, `Commands.md`, `Orders.md` (D20), and **`.claude/Voucher.md`**
  (schema, discount rules, eligibility table, usage-limit mechanics, redemption lifecycle,
  decisions log, implementation pointers).

## Files removed

None.

## Database changes

One additive table (`voucher_redemptions`), no existing-table changes, no backfill. Every
lifecycle-transition rule and the concurrency mechanism are app-enforced (a row lock, not a DB
constraint). Total: **42 tables**.

## Tests added / changed

- New: `VoucherRedemptionTest` (4), `VoucherDiscountCalculatorTest` (5),
  `VoucherRedemptionHandlersTest` (5), `VoucherRedemptionPersistenceTest` (1, CI-only).
- Changed: `VoucherEligibilityEvaluatorTest` (+5), `MigrationRoundTripTest`.
- Run with: `composer test` (unit), `composer test:integration` (CI-only), `composer ci`.

## Captured evidence

```
$ composer ci
 [OK] No errors
OK (313 tests, 1255 assertions)
```

`VoucherRedemptionEvidence.php` (full lifecycle):

```
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
release attempt-B after it was confirmed?            -> ERROR  voucher_redemption.already_confirmed
```

## Known limitations

- No stale-reservation sweep yet (Phase 29); no decision snapshot (Phase 18); no
  `/vouchers/validate` endpoint (Phase 19); real payment ids as `attempt_reference` (Phase 20).
- The `FOR UPDATE` lock's real effect is asserted only in CI (no local MySQL).

## Next recommended phase

**Phase 18 — Decision snapshots.**
