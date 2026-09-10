# Q: Phase 15 — Price lists (A/B) (completion summary)

## What was implemented

A/B price experiments inside a pricing group — **data model + resolver math + management
surface**. Two tables:

- **`price_lists`** — one **control** row per pricing group (`is_control = 1`, `factor 1.0000`,
  `is_enabled = 1`, undeletable, never disabled, created with the group + backfilled for existing
  groups by the migration) plus non-control experiment lists carrying a `DECIMAL(6,4)` factor.
- **`price_list_packages`** — an exact per-package amount on a non-control list, overriding the
  factor for that package.

**`PriceListResolver`** runs in `PriceResolver::resolve` **between** the Phase 13 base amount and
the Phase 14 `price_rules` step: exact list-package amount → else `base × factor` (HALF_EVEN) →
else base unchanged. `ResolvedPrice` gained `priceListId` / `priceListName` / `priceListFactor`
(always stamped) and `PriceSource` gained `PriceList` (set only when the amount moved). An
unknown / foreign-group / disabled `$priceListId` falls back to the control list.

### Deferred (Phase 15 Q4 + Q5 — your decision, 2026-09-10)

The **visitor→list assignment** — `price_list_assignments` table, deterministic bucketing /
hashing service, disable-fallback sweep, `visitor_ref` query params on `/packages` +
`/pricing/resolve` — is **not built**. It moves to **Phase 24 (Payment creation flow)**, where
Q4 and Q5 will be re-asked with their full option lists before anything is implemented. Until
then `PriceResolver::resolve` passes `$priceListId = null` and every resolve uses the control
list. Recorded in `PhaseDecisions.md`, `Phases.md` (Phase 15 + Phase 24), and a project memory.

Q1 was also revised on your instruction: **explicit control row (Option 2)**, not the
recommended implicit control.

## Files created

- Migration `20260910160001_create_price_lists_tables.php` (+ control-row backfill).
- `src/Modules/Pricing/Domain/` — `PriceList.php`, `PriceListPackage.php`,
  `PriceListRepository.php`, `PriceListPackageRepository.php`.
- `src/Modules/Pricing/Application/` — `PriceListResolver.php`, `PriceListAuditSnapshot.php`,
  `PriceListSummary.php`, `PriceListDirectory.php`, `CreatePriceList/`, `ChangePriceListStatus/`,
  `SetPriceListFactor/`, `SetPriceListPackagePrice/`.
- `src/Modules/Pricing/Infrastructure/` — `PdoPriceListRepository.php`,
  `PdoPriceListPackageRepository.php`, `PdoPriceListDirectory.php`.
- `bin/{CreatePriceList,SetPriceListStatus,SetPriceListFactor,SetPriceListPackagePrice,ListPriceLists}.php`.
- `tests/Support/{InMemoryPriceListRepository,InMemoryPriceListPackageRepository}.php`.
- `tests/Unit/Modules/Pricing/{Domain/PriceListTest,Application/PriceListResolverTest,Application/PriceListHandlersTest}.php`.
- `.claude/PhaseResults/Phase15Result.md`.

## Files updated

- Pricing: `PriceResolver.php` (new dep + `?int $priceListId` + the step), `PriceSource.php`,
  `ResolvedPrice.php`, `CreatePricingGroup/CreatePricingGroupHandler.php` (writes the control
  row), `Infrastructure/definitions.php`.
- `src/Database/Seeds/PricingSeeder.php` (control list per group + disabled `dach` List B).
- `composer.json` / `composer.lock` (5 `pricing:*-list*` scripts).
- Tests: `MigrationRoundTripTest`, `PricingPersistenceTest`, `PackagesApiTest`,
  `PriceResolverTest` (+1 case), `PriceCatalogTest`, `PricingHandlersTest`.
- Docs: `database-design.md` (38 tables), `database-diagram.md` + `.html` (13/13 mermaid),
  `db_explain.md`, `Architecture.md` (§8/§9/§13), `Phases.md` (row 15 ☑, row 24 gains the
  deferred work), `Changelog.md`, `FileIndex.md`, `Knowledge.md`, `Commands.md`, `Orders.md`
  (D18).

## Files removed

None.

## Database changes

Two additive tables (`price_lists`, `price_list_packages`), migration `20260910160001`, which
also backfills one control `price_lists` row per existing `pricing_groups` row via
`INSERT … SELECT`. `is_control` uniqueness and control-list immutability are app-enforced. Total:
**38 tables**.

## Tests added / changed

- New: `PriceListTest` (4), `PriceListResolverTest` (7), `PriceListHandlersTest` (4).
- Changed: `PriceResolverTest` (+1), `PriceCatalogTest`, `PricingHandlersTest`, `PackagesApiTest`,
  `PricingPersistenceTest`, `MigrationRoundTripTest`.
- Run: `composer test` (unit), `composer test:integration` (CI-only), `composer ci`.

## Captured evidence

```
$ composer ci
 [OK] No errors        # php-cs-fixer + phpstan (max + strict-rules)
OK (271 tests, 1064 assertions)

$ composer test:integration
Tests: 33, Assertions: 0, Skipped: 33.        # no local MySQL
```

`PriceListEvidence.php` (in-memory resolver; `$priceListId` passed directly since assignment is
deferred):

```
no list arg (control)            ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
List B (factor 0.9000)           ->   2610 EUR  source=price_list         list=List B · -10% (x0.9000)
List C (exact pro = 1999)        ->   1999 EUR  source=price_list         list=List C · hero price (x1.0000)
List D (disabled -> control)     ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
List B + card (price rule wins)  ->   2500 EUR  source=dimension_override list=List B · -10% (x0.9000)
unknown list id 999 -> control   ->   2900 EUR  source=baseline           list=List A · control (x1.0000)
```

## Known limitations

- No visitor→list assignment yet (deferred to Phase 24) — every resolve uses control.
- API responses unchanged; the applied list is carried only on the internal DTO.
- `price_lists` DB round-trip is asserted only in CI.

## Next recommended phase

**Phase 16 — Vouchers module: definitions & eligibility.**
