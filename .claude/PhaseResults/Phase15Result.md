# Phase 15 — Price lists (A/B)

## Execution Summary

- Phase: 15 — Price lists (A/B)
- Start Datetime: 2026-09-10 14:57
- End Datetime: 2026-09-10 16:04
- Estimated Duration: 3–5h
- Actual Duration: 1h 07m
- Tokens Used: N/A
- Final Status: Complete (narrowed — visitor→list assignment deferred to Phase 24 by the user)

## Work Completed

- Added two tables: `price_lists` (one **control** row per pricing group + non-control
  experiment lists with a `DECIMAL(6,4)` factor) and `price_list_packages` (exact per-package
  amount on a non-control list, overriding the factor).
- Built the `PriceList` aggregate with the control/experiment invariants and `PriceListPackage`
  VO, plus `PriceListRepository` + `PriceListPackageRepository` ports and PDO adapters.
- Built `PriceListResolver` and slotted it into `PriceResolver::resolve` **between** the Phase 13
  base amount and the Phase 14 `price_rules` step. `ResolvedPrice` gained `priceListId` /
  `priceListName` / `priceListFactor` (+ `withList`), and `PriceSource` gained `PriceList`.
- `CreatePricingGroupHandler` now writes the control `price_lists` row in the same transaction
  as the group; the migration backfills a control row for every pre-existing group.
- Added `CreatePriceList` / `ChangePriceListStatus` / `SetPriceListFactor` /
  `SetPriceListPackagePrice` audited use cases + `PriceListDirectory` + 5 `bin/` scripts + 5
  `composer pricing:*` entries + seeder fixtures.
- **Deferred (Phase 15 Q4 + Q5, user's decision):** the `price_list_assignments` table, the
  deterministic visitor→list bucketing service, disable-fallback-by-sweep, and the `visitor_ref`
  endpoint params — moved to Phase 24 (Payment creation flow). `PriceResolver::resolve` passes
  `$priceListId = null` everywhere in this phase, so every resolve uses the control list. The
  resolver hook (`?int $priceListId`) and disable-fallback-in-resolver are in place for Phase 24.
- Tests: 3 new classes + 1 new `PriceResolverTest` case + 5 existing tests updated for the new
  `PriceResolver` / `CreatePricingGroupHandler` constructor args. Unit suite 256 → 271,
  `composer ci` green.

## Files Created

- `src/Database/Migrations/20260910160001_create_price_lists_tables.php` —
  `Gomrok\Database\Migrations\CreatePriceListsTables` (creates both tables + backfills a control
  list per existing `pricing_groups` row).
- `src/Modules/Pricing/Domain/PriceList.php` — aggregate (`control`, `experiment`, `fromStorage`,
  `assignId`, `rename`, `changeFactor`, `enable`, `disable`, `isControl`, `isEnabled`,
  `isNeutral`, `factor`, `validateFactor`).
- `src/Modules/Pricing/Domain/PriceListPackage.php` — exact-price VO.
- `src/Modules/Pricing/Domain/PriceListRepository.php` — port (`save`, `findById`, `delete`,
  `findControlForGroup`, `existsForGroupWithName`, `forGroup`, `enabledForGroup`).
- `src/Modules/Pricing/Domain/PriceListPackageRepository.php` — port (`save`, `find`, `delete`,
  `forList`).
- `src/Modules/Pricing/Application/PriceListResolver.php` — the list→price service.
- `src/Modules/Pricing/Application/PriceListAuditSnapshot.php`,
  `PriceListSummary.php`, `PriceListDirectory.php`.
- `src/Modules/Pricing/Application/CreatePriceList/{CreatePriceListCommand,CreatePriceListResult,CreatePriceListHandler}.php`.
- `src/Modules/Pricing/Application/ChangePriceListStatus/ChangePriceListStatusHandler.php`
  (`enable` / `disable`).
- `src/Modules/Pricing/Application/SetPriceListFactor/{SetPriceListFactorCommand,SetPriceListFactorHandler}.php`.
- `src/Modules/Pricing/Application/SetPriceListPackagePrice/{SetPriceListPackagePriceCommand,SetPriceListPackagePriceResult,SetPriceListPackagePriceHandler}.php`.
- `src/Modules/Pricing/Infrastructure/{PdoPriceListRepository,PdoPriceListPackageRepository,PdoPriceListDirectory}.php`.
- `bin/{CreatePriceList,SetPriceListStatus,SetPriceListFactor,SetPriceListPackagePrice,ListPriceLists}.php`.
- `tests/Support/{InMemoryPriceListRepository,InMemoryPriceListPackageRepository}.php`.
- `tests/Unit/Modules/Pricing/Domain/PriceListTest.php` (4 cases).
- `tests/Unit/Modules/Pricing/Application/PriceListResolverTest.php` (7 cases).
- `tests/Unit/Modules/Pricing/Application/PriceListHandlersTest.php` (4 cases).
- `.claude/PhaseResults/Phase15Result.md`.

## Files Modified

- `src/Modules/Pricing/Application/PriceResolver.php` — constructor gained `PriceListResolver`;
  `resolve()` gained a trailing `?int $priceListId = null`; new price-list step before `applyRules`.
- `src/Modules/Pricing/Application/PriceSource.php` — `case PriceList = 'price_list'`.
- `src/Modules/Pricing/Application/ResolvedPrice.php` — `priceListId` / `priceListName` /
  `priceListFactor` + `withList()`; `withRule()` carries them forward.
- `src/Modules/Pricing/Application/CreatePricingGroup/CreatePricingGroupHandler.php` — new
  `PriceListRepository` dep; writes + audits the control list in the group's transaction.
- `src/Modules/Pricing/Infrastructure/definitions.php` — bind the 3 new ports.
- `src/Database/Seeds/PricingSeeder.php` — a control list per seeded group + a disabled `dach`
  "List B · -10%" (factor `0.9000`) with an exact `pro` €21.00.
- `composer.json` / `composer.lock` — 5 `pricing:*-list*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — `price_lists`, `price_list_packages` added.
- `tests/Integration/PricingPersistenceTest.php`,
  `tests/Unit/Http/PackagesApiTest.php`,
  `tests/Unit/Modules/Pricing/Application/{PriceResolverTest,PriceCatalogTest,PricingHandlersTest}.php`
  — updated for the new constructor args / container bindings.

## Implementation Details

- **`PriceList`** — `control()` fixes `name = 'List A · control'`, `factor = '1.0000'`,
  `isControl = true`, `isEnabled = true`. `experiment()` trims the name and normalises the factor
  via `number_format(…, 4)`. `changeFactor()` / `disable()` return a `DomainError` (not throw)
  for the control-list guards (`price_list.control_factor_locked` /
  `price_list.cannot_disable_control`). `validateFactor()` — regex `^\d{1,2}(\.\d{1,4})?$` and
  `> 0` (`price_list.invalid_factor` / `price_list.non_positive_factor`). `isNeutral()` = control
  or `factor == 1.0`.
- **`PriceListResolver::apply(pricingGroupId, packageId, ?priceListId, base)`** — `resolveList`
  returns the `$priceListId` list only if it is in the group **and** enabled, else
  `findControlForGroup` (the disable-fallback). Then: an exact `price_list_packages` row whose
  currency matches → `base->withList(exact, …, PriceSource::PriceList, …)`; else a non-neutral
  list → `Money::fromMinor(base)->multipliedBy(factor)` (HALF_EVEN) → `withList(…, PriceList, …)`;
  else `withList(base amount, base amount decimal, base->source, …)` (stamp only).
- **`PriceResolver::resolve`** — after `priceForGroup` returns ok, calls
  `$this->priceLists->apply($groupId, $packageId, $priceListId, $resolved)` then `applyRules`.
  `PriceCatalog` (the `/packages` browse list) still calls `priceForGroup` directly → no price
  lists there.
- **Handlers** — `CreatePriceListHandler` checks group ownership, reserved name, name
  uniqueness, factor; `SetPriceListPackagePriceHandler` checks list ownership, not-control,
  positive amount, valid + configured currency, currency == pricing-group currency. Every
  handler audits (`price_list.created` / `.enabled` / `.disabled` / `.factor_changed` /
  `price_list_package.set`).
- **`SetPriceListPackagePriceHandler`** takes no clock — the `PriceListPackage` VO has no
  timestamps; `PdoPriceListPackageRepository` stamps `created_at`/`updated_at` itself with
  `ON DUPLICATE KEY UPDATE`.

## Database Changes

Two new tables, migration `20260910160001_create_price_lists_tables.php` (additive; no changes
to existing tables). The migration also runs an `INSERT … SELECT` backfilling one control
`price_lists` row per existing `pricing_groups` row.

- **`price_lists`** — `id` PK; `client_id` FK → `clients(id)` CASCADE; `pricing_group_id` FK →
  `pricing_groups(id)` CASCADE; `name VARCHAR(100)`; `is_control TINYINT(1) DEFAULT 0`;
  `factor DECIMAL(6,4) DEFAULT 1.0000`; `is_enabled TINYINT(1) DEFAULT 1`; timestamps.
  `UNIQUE (pricing_group_id, name)` = `uniq_price_lists_group_name`;
  `INDEX (client_id, pricing_group_id)` = `idx_price_lists_client_group`.
- **`price_list_packages`** — `id` PK; `price_list_id` FK → `price_lists(id)` CASCADE;
  `package_id` FK → `packages(id)` CASCADE; `amount_minor BIGINT UNSIGNED`;
  `currency_code CHAR(3)` FK → `currencies(code)` RESTRICT; timestamps.
  `UNIQUE (price_list_id, package_id)` = `uniq_price_list_packages`;
  `INDEX (package_id)` = `idx_price_list_packages_package`.
- Total table count: **38**.

## API Changes

**No API changes.** `ResolvedPrice` carries the applied price-list fields internally (for the
Phase 24 assignment work and payment snapshots), but no JSON response was modified — both
`/api/v1/packages` and `/api/v1/pricing/resolve` behave exactly as after Phase 14. The
`visitor_ref` query params are part of the deferred Phase 24 scope.

## Tests and Validation

- **Created:** `PriceListTest` (4), `PriceListResolverTest` (7), `PriceListHandlersTest` (4).
- **Modified:** `PriceResolverTest` (+1: `anAssignedExperimentListShiftsTheBaseBeforePriceRules`),
  `PriceCatalogTest`, `PricingHandlersTest`, `PackagesApiTest`, `PricingPersistenceTest`,
  `MigrationRoundTripTest` (constructor args / table list).
- **Commands run:** `composer dump-autoload`, `composer stan`, `composer cs:fix`,
  `composer test`, `composer test:integration`, `composer ci`,
  `composer update --lock --no-install`, `composer validate --no-check-publish`, `php -l` on the
  migration + 5 bin scripts + the seeder, and a captured-evidence script.

Captured output:

```
$ composer stan
 [OK] No errors

$ composer test
OK (271 tests, 1064 assertions)

$ composer test:integration
OK, but some tests were skipped!
Tests: 33, Assertions: 0, Skipped: 33.        # no local MySQL — run in CI

$ composer ci
 [OK] No errors        # php-cs-fixer + phpstan (max + strict-rules)
OK (271 tests, 1064 assertions)

$ composer validate --no-check-publish
./composer.json is valid
```

Captured evidence — `PriceListEvidence.php` (in-memory resolver; the `$priceListId` hook is
passed directly since assignment is deferred):

```
=== Phase 15 — A/B price lists (control default; assignment deferred) ===

no list arg (control)                        ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
List B (factor 0.9000)                       ->   2610 EUR  source=price_list         list=List B · -10% (x0.9000)
List C (exact pro = 1999)                    ->   1999 EUR  source=price_list         list=List C · hero price (x1.0000)
List D (disabled -> control)                 ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
List B + card (price rule wins)              ->   2500 EUR  source=dimension_override list=List B · -10% (x0.9000)
unknown list id 999 -> control               ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
```

(Row 2: `2900 × 0.9 = 2610`. Row 3: exact `price_list_packages` amount beats the factor. Rows 4
& 6: disabled / unknown list id → control. Row 5: a Phase 14 card rule still overrides the
experiment, but the list is still stamped.)

CLI usage (a full run needs MySQL, unavailable locally — consistent with Phases 9–14):

```
$ php bin/CreatePriceList.php
usage: php bin/CreatePriceList.php --client=<slug|id> --group=<slug> --name=<name> [--factor=1.0000]

$ php bin/SetPriceListStatus.php --client=x --list=1
usage: php bin/SetPriceListStatus.php --client=<slug|id> --list=<id> (--enable | --disable)
```

## Technical Decisions

- **Q1 — explicit control row (Option 2).** The user overrode the "implicit control"
  recommendation: every pricing group has a real `price_lists` row (`is_control = 1`), created
  with the group and backfilled for existing groups; assignments will always FK a real row.
- **Q2 — factor + optional exact per-package price (Option 3).** `factor` is the list default;
  `price_list_packages` pins an exact amount for one package. Precedence: exact → factor → base.
- **Q3 — list applies to the base, before `price_rules` (Option 1).** A deliberate Phase 14
  dimension rule still wins over the experiment; a `price_rules` "unavailable" still fails.
- **Q4 + Q5 — DEFERRED to Phase 24.** The user asked not to decide visitor→list assignment
  persistence or endpoint wiring in Phase 15, and to be re-asked at the payment/checkout phase.
  Recorded in `PhaseDecisions.md` (Q4 "Selected: Option 2" withdrawn; Q5 marked deferred) with a
  re-ask instruction; also in `Phases.md` (Phase 15 + Phase 24) and a project memory.

## Problems Encountered

- PHPStan: `SetPriceListPackagePriceHandler::$clock` written-but-never-read; `!preg_match(...)`
  in `PriceList::validateFactor` (`booleanNot.exprNotBoolean` — `preg_match` returns `int|false`).
- `PriceListResolverTest::anEnabledExperimentAppliesItsFactor` asserted `amountDecimal` as
  `'2610.00'` — `ResolvedPrice.amountDecimal` is the **major-unit** decimal (`'26.10'`).

## Resolutions

- Removed the unused `ClockInterface` dep from `SetPriceListPackagePriceHandler` (and its test
  wiring); changed the guard to `preg_match(...) !== 1`.
- Corrected the assertion to `'26.10'`.

## Deferred Work

- **Visitor→list assignment** — the `price_list_assignments` table, the deterministic
  hash-bucket service, the "even split" + "stable assignment" behaviour, and the `visitor_ref`
  query params on `/packages` + `/pricing/resolve`. Owned by **Phase 24 (Payment creation
  flow)**; Phase 15 Q4 + Q5 must be re-asked there with their full option lists.
- Exposing the applied price list in API responses — with the assignment work in Phase 24.
- `price_lists` DB round-trip is asserted only in CI (no local MySQL).

## Final Result

Gomrok pricing now has three layers: the Phase 13 base price → the Phase 15 A/B price list
(control by default; an exact `price_list_packages` amount or `base × factor`) → the Phase 14
`price_rules` override. Every pricing group owns an undeletable control list; experiment lists
and their per-package prices are managed through audited handlers + the `pricing:*-list*` CLI +
the seeder. The visitor→list assignment that actually routes traffic into an experiment is
deferred to Phase 24. 38 tables; 271 unit tests + 33 CI-only integration tests; `composer ci`
green.

**Next recommended phase:** Phase 16 — Vouchers module: definitions & eligibility.
