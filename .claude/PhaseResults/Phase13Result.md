# Phase 13 — Pricing module: default prices & pricing groups

## Execution Summary

- Phase: 13 — Pricing module: default prices & pricing groups
- Start Datetime: 2026-09-10 13:23
- End Datetime: 2026-09-10 13:58
- Estimated Duration: 4–6h
- Actual Duration: ~35m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; `composer ci` green — 242 unit tests. The 5 migrations
  + `PricingPersistenceTest` run in CI — no usable local MySQL here.)

## Work Completed

- New **Pricing module** (`src/Modules/Pricing/`, registered in `ContainerFactory`) — the
  baseline of the pricing pipeline.
- 5 migrations: `pricing_groups`, `pricing_group_countries`, `default_package_prices`,
  `client_exchange_rates`, `pricing_group_packages`. **35 tables total.**
- **`PricingGroup`** aggregate — priority-ordered, **overlapping** country membership, optional
  `device_type`, one currency, one `is_default` fallback (pinned last, can't be disabled).
  Deliberately distinct from Phase 10 provider groups (exclusive routing).
- **`PricingGroupPackage`** aggregate — per `(group, package)`: `status`
  `default` / `override` / `disabled`, `amount_minor` + `currency` (override only, = group
  currency), `name` / `badge` / `highlighted` overrides, `display_order`. No row = implicit
  `default`.
- **`DefaultPackagePrice`** / **`ClientExchangeRate`** VOs + repos — one baseline per package;
  client-configured, effective-dated FX.
- **`PriceResolver`** — group match (priority, device, default last) → row → baseline /
  `Money::convertTo` via the client rate / override → `ResolvedPrice` (`source`
  `baseline` / `converted` / `group_override`, effective name/badge/highlighted).
  **`PriceCatalog`** wraps `PackageCatalog` and attaches a price to each package.
- **HTTP:** `GET /api/v1/packages?country=…` and `GET /api/v1/pricing/resolve?package=…&country=…`
  — client-authenticated, mounted this phase. (`GET`, not the `POST` CLAUDE.md suggests — pure
  reads; a POST would hit the write-idempotency middleware.)
- 7 audited use-case handlers + `pricing:*` CLI (5 scripts) + env-gated `PricingSeeder`.
- `Shared\Domain\Money::amount()` — decimal-string accessor.

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/2026091014000{1..5}_*.php` → `Create{PricingGroups,PricingGroupCountries,DefaultPackagePrices,ClientExchangeRates,PricingGroupPackages}Table`
- `src/Database/Seeds/PricingSeeder.php`

**Pricing / Domain** — `PricingGroup.php`, `PricingGroupPackage.php`, `DefaultPackagePrice.php`,
`ClientExchangeRate.php`, `PricingGroupStatus.php`, `PricingRowStatus.php`, `PricingGroupSlug.php`,
`PricingGroupRepository.php`, `PricingGroupPackageRepository.php`,
`DefaultPackagePriceRepository.php`, `ClientExchangeRateRepository.php`

**Pricing / Application** — `PriceResolver.php`, `PriceCatalog.php`, `ResolvedPrice.php`,
`ResolvedCatalogPackage.php`, `PriceSource.php`, `PricingGroupDirectory.php`,
`PricingGroupSummary.php`, `PricingAuditSnapshot.php`; use-case folders `CreatePricingGroup`
(`Command` / `Handler` / `Result`), `SetPricingGroupCountries`, `ReorderPricingGroups`,
`ChangePricingGroupStatus` (`Handler`), `SetDefaultPackagePrice`, `SetClientExchangeRate`,
`SetPricingGroupPackage`

**Pricing / Infrastructure** — `PdoPricingGroupRepository.php`, `PdoPricingGroupPackageRepository.php`,
`PdoDefaultPackagePriceRepository.php`, `PdoClientExchangeRateRepository.php`,
`PdoPricingGroupDirectory.php`, `definitions.php`

**HTTP** — `src/Http/Api/PackagesAction.php`, `src/Http/Api/PricingResolveAction.php`

**CLI** — `bin/CreatePricingGroup.php`, `bin/SetDefaultPackagePrice.php`,
`bin/SetClientExchangeRate.php`, `bin/SetPricingGroupPackage.php`, `bin/ListPricing.php`

**Tests** — `tests/Unit/Modules/Pricing/Application/{PriceResolverTest,PricingHandlersTest,PriceCatalogTest}.php`,
`tests/Unit/Modules/Pricing/Domain/PricingGroupTest.php`, `tests/Unit/Http/PackagesApiTest.php`,
`tests/Integration/PricingPersistenceTest.php`,
`tests/Support/{StubPackageDirectory,InMemoryPricingGroupRepository,InMemoryPricingGroupPackageRepository,InMemoryDefaultPackagePriceRepository,InMemoryClientExchangeRateRepository}.php`

`.claude/PhaseResults/Phase13Result.md` — this file.

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — registers the Pricing module `definitions.php`.
- `src/Config/routes.php` — `/api/v1/packages` + `/api/v1/pricing/resolve`.
- `src/Shared/Domain/Money.php` — `amount()`.
- `composer.json` / `composer.lock` — `pricing:*` scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables.
- DB docs (`database-design.md` → 35 tables, `database-diagram.md` + `.html` 11/11 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Pricing, §9 pipeline); `Phases.md` (row 13 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D16).

## Implementation Details

- **`PriceResolver::resolveGroup()`** sorts the client's groups
  `[isDefault, priority, slug]` ascending, so the `is_default` group is always evaluated last
  regardless of stored priority; the first active group whose countries contain the request
  country and whose `device_type` matches (or is NULL) wins.
- **Conversion:** `Money::fromMinor($baseMinor, Currency::of($baseCcy))->convertTo(Currency::of($groupCcy), $rate->rate)->toMinor()`
  — brick/money handles the minor-unit scale difference and rounds HALF_EVEN. `Money` never
  fetches rates.
- **`ClientExchangeRateRepository::findRate()`** returns the most recent row with
  `effective_from <= $at` for the pair (`ORDER BY effective_from DESC LIMIT 1`).
- **`PricingGroupPackage::validate()`** — `override` ⇒ non-negative amount + currency = group
  currency; non-override ⇒ amount/currency forced NULL by `create()`.
- **`PriceCatalog::resolve()`** resolves the group once, prices each `ResolvedPackage`, and drops
  a package whose row is `disabled` (or has no baseline). Sorted by `display_order` then `code`.
- **`GET /api/v1/pricing/resolve` is a `GET`** — a `POST` would be caught by the
  `/api/v1` `IdempotencyMiddleware` (`requireKeyOnWrites`), which is wrong for a side-effect-free
  price check. Recorded in Phase 13 Q4.
- Audit actions: `pricing_group.created` / `.countries_updated` / `.reordered` / `.disabled` /
  `.enabled`, `default_package_price.set`, `client_exchange_rate.set`, `pricing_group_package.set`.

## Database Changes

5 new tables (see `database-design.md` → *Pricing — groups & default prices (Phase 13)*):

- `pricing_groups` — FK `client_id` CASCADE + `currency_code` → `currencies(code)` RESTRICT.
  `UNIQUE (client_id, slug)`.
- `pricing_group_countries` — FK `pricing_group_id` CASCADE + `country_code` → `countries(code)`
  RESTRICT. `UNIQUE (pricing_group_id, country_code)`. **Overlap allowed.**
- `default_package_prices` — FK `package_id` CASCADE **`UNIQUE`** + `currency_code` RESTRICT.
  `amount_minor BIGINT UNSIGNED`.
- `client_exchange_rates` — FK `client_id` CASCADE + `base`/`quote_currency` RESTRICT.
  `UNIQUE (client_id, base_currency, quote_currency, effective_from)`. `rate DECIMAL(18,8)`.
- `pricing_group_packages` — FK `pricing_group_id` / `package_id` CASCADE + `currency_code`
  RESTRICT. `UNIQUE (pricing_group_id, package_id)`.

No existing-table changes. No backfill. `priority` uniqueness / one-default / override-currency
are app-enforced.

## API Changes

**New endpoints** (client-authenticated, Phase 7 middleware):

- `GET /api/v1/packages?country=DE[&method=card][&device=ios]` → `{ country, currency, packages: [ { id, code, name, description, badge, highlighted, client_package_id, price: { amount_minor, amount, currency, source, pricing_group }, purchase_types, available_methods, available_provider_account_ids, metadata } ] }`. `422` if `country` is missing / an unknown method; the `pricing.*` errors as RFC-7807 problems.
- `GET /api/v1/pricing/resolve?package=<code>&country=DE[&device=ios]` → `{ package, name, badge, highlighted, price: { amount_minor, amount, currency, source, pricing_group } }`.

Both deviate from CLAUDE.md's suggested `POST` for `pricing/resolve` — kept as `GET` (pure read).

## Tests and Validation

- **Tests created:** `PriceResolverTest` (8 — the exit matrix), `PricingGroupTest` (5),
  `PricingHandlersTest` (7), `PriceCatalogTest` (1), `PackagesApiTest` (3, functional),
  `PricingPersistenceTest` (1, self-skips); 5 support doubles. **+24 net.**
- **Tests modified:** `MigrationRoundTripTest` (table list).
- **Commands run:**
  - `composer cs` → clean (417 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules, 411 files).
  - `composer test` → **`OK (242 tests, 921 assertions)`**.
  - `composer test:integration` → `Skipped: 33` (no usable local MySQL).
  - `composer ci` → green (exit 0).
- **Exit-criteria evidence** — captured `PriceResolver` output (see *Final Result*): priority
  match (`Global iOS` before `DACH`), device fallthrough, fallback group, and cross-currency
  conversion (29.00 EUR → 31.32 USD @ 1.08).

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 13 Q1–Q5:

1. **Priority-ordered, overlapping pricing groups** + `device_type` + `is_default` last.
2. **Single baseline per package + `client_exchange_rates`** (effective-dated) for
   `status=default` cross-currency; overrides bypass conversion.
3. **One `(group, package)` row**, `no row = implicit default`; `override` currency = group
   currency.
4. **Dedicated `PriceResolver` / `PriceCatalog` + `ResolvedPrice`**; `GET /api/v1/packages` +
   `GET /api/v1/pricing/resolve` mount now.
5. **One audited handler per operation** + `pricing:*` CLI + `PricingSeeder`.

## Problems Encountered

- PHPStan `cast.useless` (`(string)` on an already-`string` rate) and `arrayValues.list` (on an
  already-`list` in `PriceCatalog`).
- PHPStan `instanceof.alwaysTrue` in `bin/ListPricing.php` (redundant `assert`).
- `POST /api/v1/pricing/resolve` would be caught by the write-idempotency middleware.

## Resolutions

- Dropped the redundant cast / `array_values` / `assert`.
- Mounted `pricing/resolve` as a `GET` and read query params instead of a body.

## Deferred Work

- Override dimensions (currency / provider / method / purchase-type / interval) + the full
  precedence engine — Phase 14.
- A/B price lists (`price_lists`, per-list prices, visitor → list assignment) — Phase 15.
- Voucher discount, tax / fee — Phases 16–18.
- The price *snapshot* on a payment / subscription — Phase 17.
- `ReorderPricingGroups` and `SetPricingGroupCountries` / `ChangePricingGroupStatus` have
  handlers but no dedicated CLI script yet (admin panel, Phase 27; or add later).
- Admin views for pricing — Phase 27.
- Executing the 5 migrations + `PricingPersistenceTest` against real MySQL — CI, or the user.

## Final Result

35 tables. A client's catalogue now has a **resolved price**: pricing group by priority (with a
"Global iOS" style overlay possible), baseline price, client FX conversion, and per-group
overrides. `GET /api/v1/packages` and `GET /api/v1/pricing/resolve` are live — the first
client-facing catalogue endpoints. Phase 14 layers the finer override rules on top of this shape.

Captured `PriceResolver` output (real, from the exit-criteria script — `pro` baseline €29.00,
`dach` override €24.00, EUR→USD 1.08):

```
DE / ios   -> 29.00 EUR  group=global-ios  source=baseline        name="Pro"
DE / web   -> 24.00 EUR  group=dach         source=group_override  name="Pro (DACH)"
AT / web   -> 24.00 EUR  group=dach         source=group_override  name="Pro (DACH)"
US / web   -> 31.32 USD  group=us           source=converted       name="Pro"
FR / web   -> 29.00 EUR  group=default      source=baseline        name="Pro"
```

`DE/ios` picks `global-ios` (priority 1) over `dach` (priority 2) — the exit criterion. `DE/web`
falls through to `dach` (no device match on `global-ios`). `FR` hits the `default` fallback.
`US` converts.

`composer test` — `OK (242 tests, 921 assertions)`; `composer stan` — `[OK] No errors`;
`composer cs` — clean; `composer test:integration` — `Skipped: 33`.

Next recommended phase: **Phase 14 — Pricing overrides & resolution engine.**
