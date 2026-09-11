# Q: Phase 16 — Vouchers module: definitions & eligibility (completion summary)

## What was implemented

The new **Vouchers module**: voucher definitions and the eligibility gate — not discount
calculation or redemption (Phase 17).

- **`vouchers`** — client-scoped, `code` unique per client; validity window,
  `first_purchase_only`, minimum purchase; a **default discount** (`none`/`percentage`/`full` —
  never `fixed`) plus three **nullable usage-limit columns** (`max_total_redemptions` /
  `max_per_user` / `max_per_client`, `NULL` = unlimited on that axis) + `redeemed_count`
  (global tally, Phase 17-owned).
- **`voucher_eligibility_rules`** — one `(voucher, dimension, value)` table across 7 dimensions
  (country, currency, package, provider account, payment method, purchase type, subscription
  interval). OR within a dimension, AND across, no rows = unrestricted.
- **`voucher_currency_discounts`** — a per-currency **override** of the default discount.
  Resolution: override row → else default → else (`none`) not applicable in that currency.
- **`VoucherEligibilityEvaluator`** reports **every** unmet condition in one pass (not
  fail-fast): status, window, client scope, every restricted dimension, discount applicability,
  same-currency minimum purchase, first-purchase (unknown vs. known-false are distinct reasons),
  and the global usage cap. Per-user/per-client caps are deferred to Phase 17 behind a declared
  `VoucherUsagePort`.
- 7 audited use cases, `VoucherDirectory`, PDO adapters, 8 `bin/` CLI scripts +
  `composer voucher:*`, and an env-gated seeder (`WELCOME10`, `EU5`).

**Two decisions the user changed from the recommendation:** Q2 discount model became "default +
per-currency overrides" (not just per-currency amounts); Q3 usage limits became plain nullable
columns on `vouchers` (not a `voucher_usage_limits` child table).

**New standing rule (your instruction):** `.claude/Voucher.md` is now the single source of truth
for all voucher behaviour. `CLAUDE.md` gained a "Voucher Rules File" section requiring every
future voucher-related change to also be recorded there.

## Files created

- Migration `20260910170001_create_voucher_tables.php`.
- `src/Modules/Vouchers/` — full `Domain/`, `Application/`, `Infrastructure/` (see
  `.claude/Voucher.md` §10 for the exact class list).
- 8 `bin/*Voucher*.php` CLI scripts.
- `src/Database/Seeds/VouchersSeeder.php`.
- `tests/Support/InMemory{Voucher,VoucherEligibilityRule,VoucherCurrencyDiscount}Repository.php`.
- `tests/Unit/Modules/Vouchers/{Domain,Application}/*` (4 test classes).
- `.claude/Voucher.md`, `.claude/PhaseResults/Phase16Result.md`.

## Files updated

- `src/Bootstrap/ContainerFactory.php`, `composer.json`/`.lock`,
  `tests/Integration/MigrationRoundTripTest.php`.
- `CLAUDE.md` (new Voucher Rules File section), `.claude/Rule.md` (Project Documents).
- Docs: `database-design.md` (41 tables), `database-diagram.md` + `.html` (14/14 mermaid — also
  fixed a stale module-map snapshot from Phases 14–15), `db_explain.md`, `Architecture.md`
  (§3/§8/§9/§13), `Phases.md` (row 16 → ☑), `Changelog.md`, `FileIndex.md`, `Knowledge.md`,
  `Commands.md`, `Orders.md` (D19).

## Files removed

None.

## Database changes

Three additive tables (`vouchers`, `voucher_eligibility_rules`, `voucher_currency_discounts`).
No existing-table changes, no backfill. Every cross-field consistency rule (discount shape,
min-purchase pairing, window ordering, usage-limit positivity) is app-enforced, not DB-enforced.
Total: **41 tables**.

## Tests added / changed

- New: `VoucherTest` (6), `VoucherCurrencyDiscountTest` (3),
  `VoucherEligibilityEvaluatorTest` (10 — the exit criterion), `VoucherHandlersTest` (6).
- Changed: `MigrationRoundTripTest` (3 new tables).
- Run with: `composer test` (unit), `composer test:integration` (CI-only), `composer ci`.

## Captured evidence

```
$ composer ci
 [OK] No errors
OK (297 tests, 1176 assertions)
```

`VoucherEvidence.php` (in-memory evaluator):

```
WELCOME10, any country/currency, no card       -> ELIGIBLE
WELCOME10, disabled                            -> blocked   voucher.disabled
EU5, pro (42), EUR (override exists)           -> ELIGIBLE
EU5, pro (42), TRY (no override, default=none) -> blocked   voucher.no_discount_for_currency
EU5, starter (99), EUR (wrong package)         -> blocked   voucher.package_not_eligible
GATED, below minimum                            -> blocked  voucher.below_minimum, voucher.first_purchase_unknown, voucher.exhausted
GATED, meets everything but exhausted           -> blocked  voucher.exhausted
```

## Known limitations

- No discount calculation or redemption yet (Phase 17); `VoucherUsagePort` is declared only.
- No `POST /api/v1/vouchers/validate` endpoint (Phase 19).
- `vouchers` DB round-trip asserted only in CI.

## Next recommended phase

**Phase 17 — Voucher validation, discount calc & redemption lifecycle.**
