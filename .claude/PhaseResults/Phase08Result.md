# Phase 08 — Providers module: types & capability model

## Execution Summary

- Phase: 08 — Providers module: types & capability model
- Start Datetime: 2026-09-09 17:11
- End Datetime: 2026-09-09 17:53
- Estimated Duration: 4–6h
- Actual Duration: 42m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; the 3 migrations + persistence tests run in CI)

## Work Completed

- Second `src/Modules/` module (`Providers`) — models what each provider *type* can do, before
  any SDK is wired (adapters are Phases 21–23).
- `Capability` backed enum (19 flags) with `group()` / `label()`; `provider_capabilities` table
  as a **seeded mirror** of it (Q1).
- `PurchaseType` backed enum (`one_time_payment`, `recurring_payment`, `auto_charge`,
  `subscription`) — a first-class concept kept **separate** from `Capability` (Q2).
- `provider_type_capabilities` + `provider_type_purchase_types` join tables (Q3), seeded for
  **stripe + paypal only** (Q5) from `ProviderTypeDeclarations.json`.
- `PaymentMethod` enum + `MethodCapabilityRules` / `MethodConstraint` — an in-code placeholder
  for method-level nuance (Q4; no `payment_methods` table this phase).
- `ProviderCapabilities` VO (immutable set: `has` / `all` / `without` / `intersect` / `equals`),
  `ProviderTypeDeclaration` VO, `ProviderTypeDeclarations` read port + `PdoProviderTypeDeclarations`.
- `ProviderCapabilityResolver` — resolves a type's declaration narrowed by payment method;
  account (Phase 9) and client/country (Phase 10) narrowing wrap it later. Unknown provider →
  `null` / `false`.
- `ProviderCatalog` published read port + `ProviderTypeSummary` DTO + `PdoProviderCatalog`.

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/20260909170001_create_provider_capabilities_table.php` — `CreateProviderCapabilitiesTable`
- `src/Database/Migrations/20260909170002_create_provider_type_capabilities_table.php` — `CreateProviderTypeCapabilitiesTable`
- `src/Database/Migrations/20260909170003_create_provider_type_purchase_types_table.php` — `CreateProviderTypePurchaseTypesTable`
- `src/Database/Seeds/ProviderCapabilitiesSeeder.php` — from the `Capability` enum
- `src/Database/Seeds/ProviderTypeDeclarationsSeeder.php` + `src/Database/Seeds/data/ProviderTypeDeclarations.json` — stripe + paypal

**Providers / Domain** — `Capability`, `CapabilityGroup`, `PurchaseType`, `PaymentMethod`,
`ProviderCapabilities`, `ProviderTypeDeclaration`, `ProviderTypeDeclarations` (port),
`MethodConstraint`, `MethodCapabilityRules`.

**Providers / Application** — `ProviderCapabilityResolver`, `ResolvedProviderCapabilities`,
`ProviderCatalog` (port), `ProviderTypeSummary`.

**Providers / Infrastructure** — `PdoProviderTypeDeclarations`, `PdoProviderCatalog`,
`definitions.php`.

**Tests** — `tests/Unit/Modules/Providers/Domain/{CapabilityTest,ProviderCapabilitiesTest,MethodCapabilityRulesTest}.php`,
`tests/Unit/Modules/Providers/Application/ProviderCapabilityResolverTest.php`,
`tests/Unit/Database/ProviderTypeDeclarationsDataTest.php`,
`tests/Integration/ProviderCapabilitiesPersistenceTest.php`,
`tests/Support/InMemoryProviderTypeDeclarations.php`.

`.claude/PhaseResults/Phase08Result.md` — this file.

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — `src/Modules/Providers/Infrastructure/definitions.php`
  added to `MODULE_DEFINITIONS`.
- `tests/Integration/MigrationRoundTripTest.php` — 3 provider tables added to `TABLES`.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — now 13
  tables), `.claude/docs/Phases.md` (row 8 → ☑), `Architecture.md` §8, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/Orders.md` (D11),
  `.claude/PhaseResults/PhaseDecisions.md` (Phase 8 Q1–Q5, prepended per the newest-first rule).

## Implementation Details

- **`ProviderCapabilities`** stores capabilities keyed by value for O(1) `has()`; `all()` returns
  them in `Capability` declaration order (deterministic, de-duplicated). `intersect()` / `without()`
  return new instances.
- **`ProviderCapabilityResolver::resolve()`** loads the `ProviderTypeDeclaration`, then — if a
  `PaymentMethod` is given — subtracts `MethodCapabilityRules::constraintFor()` exclusions from
  both the capability set and the purchase-type list. `supports()` / `supportsPurchaseType()` are
  thin wrappers returning `false` for an unknown provider type.
- **`MethodCapabilityRules`** currently encodes one rule: Mollie via `paypal` / `ideal` /
  `bancontact` loses recurring / auto-charge / subscription (purchase types) and the
  subscription-management capabilities. Explicitly a `// Phase 8 placeholder`.
- **`PdoProviderTypeDeclarations`** joins `provider_types` → `provider_type_capabilities` →
  `provider_capabilities` and `provider_types` → `provider_type_purchase_types`. Unknown enum
  strings from the DB are skipped defensively (`Capability::tryFrom` / `PurchaseType::tryFrom`).
- **`ProviderTypeDeclarationsSeeder`** resolves `provider_type_id` / `capability_id` by code via
  small cached lookups, upserts into both join tables (idempotent). Depends on
  `ProviderTypesSeeder` + `ProviderCapabilitiesSeeder`.

## Database Changes

3 new tables — full columns/indexes in `database-design.md`. All static / seeded, **no
timestamps** (Phase 4 convention).

- `provider_capabilities` — `uniq_provider_capabilities_code`, `idx_provider_capabilities_group`. 19 seeded rows.
- `provider_type_capabilities` — `uniq (provider_type_id, capability_id)`, FK to both parents CASCADE.
- `provider_type_purchase_types` — `uniq (provider_type_id, purchase_type)`, FK to `provider_types` CASCADE.

No changes to `provider_types` (Phase 4). Payment-method tables deferred to Phase 9/12.

## API Changes

No API changes. `ProviderCatalog` / `ProviderCapabilityResolver` are internal ports for Phases
9–10 and the admin panel.

## Tests and Validation

- Tests created: 4 unit classes (17 tests) + 1 integration class (5 tests) + 1 support double +
  1 seed-data guard test.
- Tests modified: `MigrationRoundTripTest` table list.
- Commands run:
  - `composer cs` → clean (203 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules + phpunit, 202 files).
  - `composer test` → `OK (141 tests, 506 assertions)`.
  - `composer test:integration` → `Skipped: 26` (no Docker / local MariaDB rejects `gomrok`).
  - `composer ci` → green (exit 0).
  - Migration + seeder classes load and extend the right Phinx bases; `Capability` = 19 cases;
    the seed JSON contains stripe + paypal; the DI container wires `ProviderCapabilityResolver`.
- **Exit criterion (resolution matrix)** — `ProviderCapabilityResolverTest`:
  - `stripeIsAFullFeaturedProvider`, `ziraatIsChargeOnly` (Stripe vs Ziraat).
  - `mollieCardKeepsSubscriptionButMolliePayPalDoesNot` (Mollie card vs PayPal).
  - `unknownProviderResolvesToNothing`.
  Ziraat / Mollie use in-code fixtures (`InMemoryProviderTypeDeclarations::withKnownProviders()`)
  since only stripe / paypal are persisted.

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 8 Q1–Q5:

1. Capability catalogue = **enum (source of truth) + seeded `provider_capabilities` mirror**.
2. Purchase types = a **separate `PurchaseType` enum**, distinct from `Capability`.
3. Per-type declarations = **join tables** (`provider_type_capabilities`, `provider_type_purchase_types`).
4. Payment methods = **code-only enum + in-code `MethodCapabilityRules`**; method tables deferred.
5. Seed = **stripe + paypal only** (user chose this over the recommended all-four); ziraat /
   mollie rows land in their adapter phases; resolver tests use in-code fixtures for the rest.

## Problems Encountered

- PHPStan `nullCoalesce.offset` — `self::DESCRIPTIONS[$value] ?? null` was dead because the
  description map is exhaustive over the enum.
- PHPStan `phpDoc.parseError` on `@todo(Phase 12) …`.
- PHPStan `argument.type` — `PDOStatement::fetch()` returns `array<mixed,mixed>`, not
  `array<string,mixed>`.
- PHPStan `staticMethod.alreadyNarrowedType` — `assertInstanceOf(CapabilityGroup::class, …)` on
  a non-nullable return.

## Resolutions

- Dropped the `?? null` — the map must stay exhaustive (PHPStan now enforces it).
- Reworded the `@todo` to prose.
- Widened the `toSummary()` param to `array<array-key, mixed>` and read via `Row::` coercers.
- Replaced the tautological assertion with a group-membership + label check.

## Deferred Work

- `payment_methods` catalogue + `provider_type_methods` + `provider_type_method_capabilities` —
  Phase 9 or Phase 12, whichever first needs persisted per-method data. `MethodCapabilityRules`
  is the placeholder.
- Ziraat & Mollie `provider_type_*` seed rows — their adapter phases (23 / 22).
- Account-level and client/country-level capability narrowing — Phases 9 and 10 wrap
  `ProviderCapabilityResolver`.
- Executing the migrations + `ProviderCapabilitiesPersistenceTest` against real MySQL — CI, or
  the user locally.

## Final Result

13 tables. The Providers module has the capability/purchase-type vocabulary, per-type
declarations for stripe + paypal, a resolver that already accounts for payment method, and a
published `ProviderCatalog`. Phase 10's routing and the Phase 27 admin panel have a real model
to build on.

Next recommended phase: **Phase 9 — Provider accounts (per client).**
