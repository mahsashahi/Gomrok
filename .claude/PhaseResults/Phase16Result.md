# Phase 16 — Vouchers module: definitions & eligibility

## Execution Summary

- Phase: 16 — Vouchers module: definitions & eligibility
- Start Datetime: 2026-09-10 16:08
- End Datetime: 2026-09-10 20:01
- Estimated Duration: 4–6h
- Actual Duration: 3h 53m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- Built the new **Vouchers module** (`src/Modules/Vouchers/`): voucher definitions and the
  eligibility gate. Discount calculation, redemption, and per-user/per-client usage counting
  are explicitly out of scope — Phase 17.
- Added three tables: `vouchers` (client-scoped, default discount, usage-limit columns),
  `voucher_eligibility_rules` (one `(voucher, dimension, value)` table across 7 dimensions),
  `voucher_currency_discounts` (per-currency override of the default discount).
- Built `VoucherEligibilityEvaluator`, which reports **every** unmet condition (not fail-fast):
  status, validity window, client scope, every restricted dimension, discount applicability,
  same-currency minimum purchase, first-purchase-only (with a distinct "unknown" reason), and
  the global usage cap. Declared (but did not implement) `VoucherUsagePort` as the seam for
  Phase 17's per-user / per-client checks.
- Added 7 audited use cases, a `VoucherDirectory`, PDO adapters, 8 CLI scripts, and an
  env-gated seeder (`WELCOME10`, `EU5`).
- **New standing project rule (user instruction):** created `.claude/Voucher.md` as the single
  source of truth for all voucher behaviour, and added a `CLAUDE.md` section ("Voucher Rules
  File") requiring every future voucher-related change to also be recorded there.
- During the decision phase the user revised two recommendations: **Q1 discount model** —
  extended from "per-currency amounts" to "default discount + per-currency overrides"; **Q3
  usage limits** — chose plain nullable columns on `vouchers` over a `voucher_usage_limits`
  child table.
- Tests: 4 new test classes covering the domain guards, the discount-override validation, the
  eligibility evaluator (the exit criterion), and every handler. Unit suite 271 → 297,
  `composer ci` green.

## Files Created

- `src/Database/Migrations/20260910170001_create_voucher_tables.php` —
  `Gomrok\Database\Migrations\CreateVoucherTables` (3 tables).
- `src/Modules/Vouchers/Domain/` — `Voucher.php` (aggregate), `VoucherCurrencyDiscount.php`
  (VO + `validate()`), `VoucherEligibilityRule.php` (VO), `VoucherStatus.php`,
  `DefaultDiscountType.php`, `DiscountType.php`, `VoucherEligibilityDimension.php`,
  `VoucherRepository.php`, `VoucherEligibilityRuleRepository.php`,
  `VoucherCurrencyDiscountRepository.php`.
- `src/Modules/Vouchers/Application/` — `VoucherContext.php`, `VoucherEligibility.php`,
  `VoucherUsagePort.php` (declared only), `VoucherEligibilityEvaluator.php`,
  `VoucherAuditSnapshot.php`, `VoucherSummary.php`, `VoucherDirectory.php`,
  `CreateVoucher/{Command,Result,Handler}.php`, `UpdateVoucher/{Command,Handler}.php`,
  `SetVoucherEligibility/{Command,Handler}.php`,
  `SetVoucherCurrencyDiscount/{Command,Result,Handler}.php`,
  `RemoveVoucherCurrencyDiscount/Handler.php`, `SetVoucherUsageLimits/{Command,Handler}.php`,
  `ChangeVoucherStatus/Handler.php`.
- `src/Modules/Vouchers/Infrastructure/` — `PdoVoucherRepository.php`,
  `PdoVoucherEligibilityRuleRepository.php`, `PdoVoucherCurrencyDiscountRepository.php`,
  `PdoVoucherDirectory.php`, `definitions.php`.
- `bin/{CreateVoucher,UpdateVoucher,SetVoucherEligibility,SetVoucherCurrencyDiscount,
  RemoveVoucherCurrencyDiscount,SetVoucherUsageLimits,SetVoucherStatus,ListVouchers}.php`.
- `src/Database/Seeds/VouchersSeeder.php`.
- `tests/Support/{InMemoryVoucherRepository,InMemoryVoucherEligibilityRuleRepository,
  InMemoryVoucherCurrencyDiscountRepository}.php`.
- `tests/Unit/Modules/Vouchers/Domain/{VoucherTest,VoucherCurrencyDiscountTest}.php`.
- `tests/Unit/Modules/Vouchers/Application/{VoucherEligibilityEvaluatorTest,
  VoucherHandlersTest}.php`.
- `.claude/Voucher.md` — the new voucher source-of-truth document.
- `.claude/PhaseResults/Phase16Result.md`.

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — registered the Vouchers module's `definitions.php`.
- `composer.json` / `composer.lock` — 8 `voucher:*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — 3 new tables added to `TABLES`.
- `CLAUDE.md` — new "Voucher Rules File" section (after "Voucher Requirement").
- `.claude/Rule.md` — `Voucher.md` row added to Project Documents.
- `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`,
  `.claude/Orders.md` (D19).
- DB docs: `database-design.md` (41 tables + Phase 16 section), `database-diagram.md` (module
  map + new Vouchers ER, 14 mermaid blocks), `database-diagram.html` (matching snapshot — also
  used this pass to fix the module-map flowchart, which had drifted since Phase 14/15),
  `db_explain.md` (Phase 16 section).
- `Architecture.md` — §3 module table, new "Vouchers (Phase 16)" subsection under §8, §9
  pipeline sketch (voucher eligibility step detail), §13 deferred work.
- `.claude/docs/Phases.md` — row 16 → ☑ with actual timestamps; scope rewritten "as built".

## Implementation Details

- **`Voucher`** — `create()` normalises `code` to upper-case and trims text fields; `redeemedCount`
  is set only via `fromStorage` (Phase 17 owns writing it). Static guards:
  `validateDefaultDiscount(DefaultDiscountType, ?percentBp)`, `validateMinPurchase(?amount,
  ?currency)`, `validateWindow(?from, ?until)`. Instance guard `setUsageLimits(...)` rejects `0`
  or negative caps (each field independently nullable = unlimited).
- **`VoucherCurrencyDiscount::validate(DiscountType, ?percentBp, ?amountMinor,
  ?maxDiscountMinor)`** — a `match` over the three types enforcing exactly the fields each type
  may carry (`fixed`: amount only; `percentage`: percent + optional cap; `full`: nothing).
- **`VoucherEligibilityEvaluator::evaluate(Voucher, VoucherContext): VoucherEligibility`** —
  accumulates reason codes into a list rather than returning on the first failure; groups
  `voucher_eligibility_rules` by dimension once, then checks each of the 7 dimensions via a
  shared `checkDimension()` helper (OR within, AND across). Discount applicability: a
  `default_discount_type = none` voucher needs a `voucher_currency_discounts` row for the
  context currency, or `voucher.no_discount_for_currency`. Minimum purchase only compares when
  `context.amountCurrency === voucher.minPurchaseCurrency()` — a different currency is silently
  skipped, not rejected. First-purchase distinguishes `isFirstPurchase === null`
  (`voucher.first_purchase_unknown`) from `=== false` (`voucher.not_first_purchase`). Global cap:
  `maxTotalRedemptions !== null && redeemedCount >= maxTotalRedemptions` → `voucher.exhausted`.
- **`SetVoucherEligibilityHandler`** validates every `(dimension, value)` pair against
  `ReferenceCatalog` (country/currency), `PackageDirectory` + `ProviderAccountDirectory`
  (ownership by client id), and the `PaymentMethod`/`PurchaseType`/`SubscriptionInterval` enums,
  de-duplicates by `dimension:normalisedValue`, then calls `replaceForVoucher` (full swap) inside
  one transaction, auditing the before/after rule lists.
- **`SetVoucherCurrencyDiscountHandler` / `RemoveVoucherCurrencyDiscountHandler`** upsert/delete
  keyed by `(voucher_id, currency_code)`; the remove handler 404s
  (`voucher_currency_discount.not_found`) rather than being a silent no-op.
- **`ChangeVoucherStatusHandler`** mirrors the Phase 13/15 enable/disable pattern — idempotent,
  no restriction on disabling (unlike the Phase 15 control-list guard).

## Database Changes

Three new tables, migration `20260910170001_create_voucher_tables.php` (additive; no changes to
existing tables; no backfill):

- **`vouchers`** — `id` PK; `client_id` FK → `clients(id)` CASCADE; `code VARCHAR(64)`
  (`UNIQUE (client_id, code)`); `name`, `description`, `status`; `valid_from` / `valid_until`;
  `first_purchase_only`; `min_purchase_minor` + `min_purchase_currency` (FK → `currencies(code)`
  RESTRICT); `default_discount_type` + `default_percent_bp`; `max_total_redemptions` /
  `max_per_user` / `max_per_client` (all nullable = unlimited); `redeemed_count` (default `0`);
  timestamps. `INDEX (client_id, status)`.
- **`voucher_eligibility_rules`** — `id` PK; `voucher_id` FK → `vouchers(id)` CASCADE;
  `dimension VARCHAR(30)`; `value VARCHAR(64)`; `created_at`.
  `UNIQUE (voucher_id, dimension, value)`; `INDEX (voucher_id, dimension)`.
- **`voucher_currency_discounts`** — `id` PK; `voucher_id` FK → `vouchers(id)` CASCADE;
  `currency_code CHAR(3)` FK → `currencies(code)` RESTRICT; `discount_type`; `percent_bp`;
  `amount_minor`; `max_discount_minor`; timestamps. `UNIQUE (voucher_id, currency_code)`.
- Total table count: **41**.

## API Changes

**No API changes.** No HTTP endpoint is added this phase — `POST /api/v1/vouchers/validate` is
Phase 19. Vouchers are managed entirely through the CLI / handlers in Phase 16.

## Tests and Validation

- **Created:** `VoucherTest` (6), `VoucherCurrencyDiscountTest` (3),
  `VoucherEligibilityEvaluatorTest` (10 — the exit criterion, covering every dimension plus
  state/window/discount/min-purchase/first-purchase/usage-cap), `VoucherHandlersTest` (6).
- **Modified:** `MigrationRoundTripTest` (3 new tables).
- **Commands run:** `composer dump-autoload`, `composer stan`, `composer cs:fix`,
  `composer test`, `composer test:integration`, `composer ci`,
  `composer update --lock --no-install`, `composer validate --no-check-publish`, `php -l` on the
  migration + seeder + 8 bin scripts, and a captured-evidence script.

Captured output:

```
$ composer ci
 [OK] No errors        # php-cs-fixer + phpstan (max + strict-rules)
OK (297 tests, 1176 assertions)

$ composer test:integration
OK, but some tests were skipped!
Tests: 33, Assertions: 0, Skipped: 33.        # no local MySQL — run in CI

$ composer validate --no-check-publish
./composer.json is valid
```

Captured evidence — `VoucherEvidence.php` (in-memory evaluator):

```
=== WELCOME10: 10%, once per user, everyone ===
any country/currency, no card                  -> ELIGIBLE
disabled voucher                               -> blocked   voucher.disabled

=== EU5: default=none, per-currency fixed overrides, package-restricted ===
pro (42), EUR -> override exists               -> ELIGIBLE
pro (42), TRY -> no override, default=none     -> blocked   voucher.no_discount_for_currency
starter (99), EUR -> wrong package             -> blocked   voucher.package_not_eligible

=== Minimum purchase + first-purchase-only + global cap ===
below minimum (500 < 1000 EUR)                 -> blocked   voucher.below_minimum, voucher.first_purchase_unknown, voucher.exhausted
meets minimum, first-purchase unknown          -> blocked   voucher.first_purchase_unknown, voucher.exhausted
meets minimum, not first purchase              -> blocked   voucher.not_first_purchase, voucher.exhausted
meets everything, but redeemed_count >= max_total -> blocked voucher.exhausted
```

(Demonstrates: multi-reason reporting in one call, dimension scoping with the package-restriction
example, the default-vs-override discount resolution, the same-currency-only minimum-purchase
rule, and the global usage cap.)

CLI usage strings (a full DB-backed run needs MySQL, unavailable locally — consistent with
Phases 9–15):

```
$ php bin/CreateVoucher.php
usage: php bin/CreateVoucher.php --client=<slug|id> --code=<code> --name=<name> [options]

$ php bin/SetVoucherEligibility.php
usage: php bin/SetVoucherEligibility.php --client=<slug|id> --voucher=<id> [--rule=dimension:value ...]
```

## Technical Decisions

- **Q1 — one `voucher_eligibility_rules` table** (not per-dimension tables or JSON): matches the
  `price_rules` precedent, and three of the eight named dimensions have no reference table to FK
  against anyway.
- **Q2 — default discount + per-currency overrides (user-extended).** The user changed the
  initial "per-currency amounts only" recommendation to a default-plus-override model:
  `vouchers.default_discount_type` (`none`/`percentage`/`full`) plus optional
  `voucher_currency_discounts` rows that can be any type. Resolution: override → default →
  `none`-inapplicable. Kept the two enums (`DefaultDiscountType` without `fixed`, `DiscountType`
  with it) so a currency-agnostic fixed amount can never be constructed.
- **Q3 — usage limits as nullable columns on `vouchers` (user-overridden).** The user chose
  plain columns over the recommended `voucher_usage_limits` child table, with `NULL` meaning
  unlimited on that axis, and required the "everyone, once per user" example to be directly
  expressible (`NULL / 1 / NULL`) — verified with a dedicated test.
- **Q4 — collect every eligibility reason, not fail-fast.** Chosen for debuggability and because
  the exit criterion ("evaluation across every dimension") is naturally asserted this way.
  Per-user/per-client checks explicitly deferred behind a declared `VoucherUsagePort` rather
  than a fake "always passes" stub.
- **Q5 — granular audited handlers + CLI + seeder**, matching the established module style.
- **User-directed process change:** created `.claude/Voucher.md` as the standing source of truth
  for voucher behaviour, with a new `CLAUDE.md` rule requiring every future voucher change to be
  recorded there.

## Problems Encountered

- PHPStan: an encapsed-string cast error in `bin/SetVoucherEligibility.php` (interpolating a
  possibly-non-string `$raw` in an error message); two "nullsafe on non-nullable" hits in
  `VoucherHandlersTest` after repeated identical `findById()` calls were narrowed by a prior
  assertion.
- Two-character test voucher codes (`'V1'`..`'V5'`) failed the real `code` regex
  (`^[A-Z0-9][A-Z0-9_-]{2,63}$`, minimum 3 characters), causing `CreateVoucherCommand` to return
  `voucher.invalid_code` and every dependent assertion in `VoucherHandlersTest` to fail with
  "Result::value() called on an error result."
- An incorrect assumption that `assertNull($voucher?->maxTotalRedemptions())` narrows `$voucher`
  to non-null for subsequent calls — it does not (unlike `assertSame`/`assertTrue`).

## Resolutions

- Replaced the string interpolation with `var_export($raw, true)`; extracted the repeated
  `findById()` result into a local variable and switched later calls to `->` once narrowed by an
  `assertSame`.
- Renamed the test codes to `VOU1`..`VOU5` (4 characters, valid).
- Added an explicit `self::assertNotNull($voucher)` before using `->` on the three subsequent
  calls instead of relying on `assertNull` to narrow.

## Deferred Work

- **Discount calculation, `voucher_redemptions`, the redemption lifecycle, and per-user /
  per-client usage-cap enforcement** — Phase 17. `VoucherUsagePort` is declared, not
  implemented.
- **Voucher decision snapshot** on the payment/subscription record — Phase 18.
- **`POST /api/v1/vouchers/validate`** — Phase 19.
- `vouchers` DB round-trip is asserted only in CI (no local MySQL).

## Final Result

Gomrok can now define client-scoped vouchers with a default discount, optional per-currency
overrides, a validity window, a minimum purchase, first-purchase-only gating, and three
independently-unlimited usage caps — and evaluate, in one pass, every reason a voucher would be
rejected for a given checkout context. `.claude/Voucher.md` is now the standing source of truth
for all voucher behaviour going forward. 41 tables; 297 unit tests + 33 CI-only integration
tests; `composer ci` green.

**Next recommended phase:** Phase 17 — Voucher validation, discount calc & redemption lifecycle.
