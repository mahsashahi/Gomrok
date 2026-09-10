# Q: Phase 13 — Pricing module: default prices & pricing groups

**Status:** ☑ code + docs complete. `composer ci` green — `composer cs` clean, `composer stan`
`[OK] No errors` (level `max` + strict-rules, 411 files), `composer test` **OK (242 tests, 921
assertions)**. `composer test:integration` → `Skipped: 33` (no usable local MySQL; runs in CI).
2026-09-10 13:23 → 13:58.

## Decisions (PhaseResults/PhaseDecisions.md Phase 13 Q1–Q5)

1. **Priority-ordered, overlapping pricing groups** + optional `device_type` + `is_default`
   pinned last. Distinct from Phase 10 provider groups (exclusive routing) — a "Global iOS"
   overlay can shadow a regional group for one device.
2. **Single baseline per package** (`default_package_prices`) + **`client_exchange_rates`**
   (client-configured, effective-dated) for `status=default` cross-currency conversion.
3. **One `(group, package)` row**; `no row = implicit default`; `override` amount must be in the
   group currency.
4. **Dedicated `PriceResolver` / `PriceCatalog` + `ResolvedPrice`**; `GET /api/v1/packages` +
   `GET /api/v1/pricing/resolve` mount now (GET not POST — pure reads).
5. **One audited handler per operation** + `pricing:*` CLI + `PricingSeeder`.

## Built (schema confirmed by the user first)

- **New Pricing module** + **migrations `20260910140001-05`** (5 tables). **35 tables total.**
- `PricingGroup` / `PricingGroupPackage` aggregates, `DefaultPackagePrice` / `ClientExchangeRate`
  VOs, 4 repository ports + 5 `Pdo*` adapters.
- `PriceResolver`: group match (priority, device, default last) → row → baseline /
  `Money::convertTo` via client rate / override → `ResolvedPrice` (`source` = `baseline` /
  `converted` / `group_override`). `PriceCatalog` wraps `PackageCatalog` + attaches a price.
- **HTTP:** `GET /api/v1/packages?country=DE[&method&device]`, `GET /api/v1/pricing/resolve?package&country[&device]` — client-authenticated.
- 7 use-case handlers (`CreatePricingGroup`, `SetPricingGroupCountries`, `ReorderPricingGroups`,
  `ChangePricingGroupStatus`, `SetDefaultPackagePrice`, `SetClientExchangeRate`,
  `SetPricingGroupPackage`); 5 `bin/` scripts; `PricingSeeder` (default/dach/us groups + EUR→USD rate).
- `Money::amount()` — decimal-string accessor.

## Verified — captured `PriceResolver` output

```
DE / ios   -> 29.00 EUR  group=global-ios  source=baseline        name="Pro"
DE / web   -> 24.00 EUR  group=dach         source=group_override  name="Pro (DACH)"
AT / web   -> 24.00 EUR  group=dach         source=group_override  name="Pro (DACH)"
US / web   -> 31.32 USD  group=us           source=converted       name="Pro"
FR / web   -> 29.00 EUR  group=default      source=baseline        name="Pro"
```

Exit criteria — `DE/ios` picks `global-ios` (priority 1) over `dach` (priority 2); `FR` falls to
the `default` group; `US` converts 29.00 EUR → 31.32 USD @ 1.08. Covered by `PriceResolverTest`
(8) + `PricingPersistenceTest` + `PackagesApiTest`.

## Tests

- New: `PriceResolverTest` (8), `PricingGroupTest` (5), `PricingHandlersTest` (7),
  `PriceCatalogTest` (1), `PackagesApiTest` (3, functional), `PricingPersistenceTest` (1,
  self-skips); 5 support doubles.
- Modified: `MigrationRoundTripTest`.

## NOT verified here

The 5 migrations + `PricingPersistenceTest` against real MySQL — CI, or `docker compose up -d
mysql && composer db:reset && composer test:integration`.

## `.claude/` updated

`database-design.md` (35 tables) / `database-diagram.md` + `.html` (11/11 mermaid) /
`db_explain.md`; `Architecture.md` §8 + §9; `Phases.md` (row 13 → ☑); `Changelog.md`;
`FileIndex.md`; `knowledge/Knowledge.md`; `Commands.md`; `Orders.md` (D16);
`PhaseResults/PhaseDecisions.md` (Phase 13 Q1–Q5 Decided); `PhaseResults/Phase13Result.md`.

Next: **Phase 14 — Pricing overrides & resolution engine.**
