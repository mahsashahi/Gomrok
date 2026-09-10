# Phase 12 — Package purchase capabilities & provider definitions

## Execution Summary

- Phase: 12 — Package purchase capabilities & provider definitions
- Start Datetime: 2026-09-10 10:16
- End Datetime: 2026-09-10 13:16
- Estimated Duration: 4–6h
- Actual Duration: ~3h
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; `composer ci` green — 218 unit tests. The 4 migrations
  + `PackagesPersistenceTest` run in CI — no usable local MySQL here.)

## Work Completed

- **Purchase capabilities.** `package_purchase_capabilities` — the purchase types a package
  supports (`PurchaseType` value) with per-row `has_trial` / `trial_days` / `duration_months`.
  **Fail closed:** a package with no rows is not sellable and the catalogue drops it.
  `package_country_purchase_capabilities` — a per-country override that *replaces* the global set
  for that country (a listed type must be in the global set).
- **Display fields.** `badge` / `highlighted` / `client_package_id` added to `packages` by an
  additive `ALTER`.
- **`PackagePurchaseCapabilityResolver`** — a concrete Application class returning the
  country-effective capability set (`PackageCapabilitySet` of `ResolvedPurchaseCapability`). The
  market/provider intersection (provider group + provider-type declaration) is left to the
  payment flow (Phase 17). `ResolvedPackage` / `PackageCatalog` / `PackageSummary` extended.
- **Provider definitions.** `package_provider_definitions` — one row per linked `(package,
  provider account)`, created lazily, `sync_state` ∈ `not_created` / `synced` / `drift` /
  `not_needed` (`PackageProviderSyncState`). Manual `remote_id` accepted now; provider-API
  product creation is **not** implemented (Phases 21–23).
- **Drift sweep.** Editing a package (`UpdatePackage`, `SetPackageAvailability`,
  `SetPackagePurchaseCapabilities`, `SetPackageCountryPurchaseCapabilities`) flips every `synced`
  definition of that package to `drift` in the same transaction (`markStaleForPackage()`).
- Use cases: `SetPackagePurchaseCapabilities`, `SetPackageCountryPurchaseCapabilities`,
  `LinkPackageProvider`, `ChangePackageProviderSyncState`; `UpdatePackage` extended. CLI
  `composer package:set-capabilities` / `:set-country-capabilities` / `:link-provider`.
  `PackagesSeeder` gives `starter` one-time and `pro` one-time + subscription (7-day trial).
- **30 tables total.**

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/2026091013000{1..4}_*.php` → `Create{PackagePurchaseCapabilities,PackageCountryPurchaseCapabilities,PackageProviderDefinitions}Table`, `AddDisplayFieldsToPackages`

**Packages / Domain** — `PackagePurchaseCapability.php`, `PackageCountryPurchaseCapability.php`,
`PackageProviderDefinition.php`, `PackageProviderSyncState.php`,
`PackageProviderDefinitionRepository.php`

**Packages / Application** — `PackagePurchaseCapabilityResolver.php`, `PackageCapabilitySet.php`,
`ResolvedPurchaseCapability.php`, `PackageProviderDefinitionDirectory.php`,
`PackageProviderDefinitionSummary.php`, `PackageProviderDefinitionAuditSnapshot.php`; use-case
folders `SetPackagePurchaseCapabilities` (`Command` / `Handler` / `PurchaseCapabilityInput`),
`SetPackageCountryPurchaseCapabilities` (`Command` / `Handler`), `LinkPackageProvider`
(`Command` / `Handler`), `ChangePackageProviderSyncState` (`Handler`)

**Packages / Infrastructure** — `PdoPackageProviderDefinitionRepository.php`,
`PdoPackageProviderDefinitionDirectory.php`

**CLI** — `bin/SetPackageCapabilities.php`, `bin/SetPackageCountryCapabilities.php`,
`bin/LinkPackageProvider.php`

**Tests** — `tests/Unit/Modules/Packages/Domain/PackagePurchaseCapabilityTest.php`,
`tests/Unit/Modules/Packages/Domain/PackageProviderDefinitionTest.php`,
`tests/Unit/Modules/Packages/Application/PackageCapabilityHandlersTest.php`,
`tests/Support/InMemoryPackageProviderDefinitionRepository.php`

`.claude/PhaseResults/Phase12Result.md` — this file.

## Files Modified

- `src/Modules/Packages/Domain/Package.php` — 5 new fields (badge / highlighted /
  clientPackageId / purchase capabilities / country overrides); `updateDisplay()`,
  `setPurchaseCapabilities()`, `setCountryPurchaseCapabilities()`, `effectiveCapabilities()`,
  `supportsPurchaseTypeGlobally()`, `isSellable()`.
- `src/Modules/Packages/Infrastructure/PdoPackageRepository.php` — new columns + 2 capability
  child tables (delete-and-reinsert).
- `src/Modules/Packages/Infrastructure/PdoPackageDirectory.php` — new columns + `purchase_types`
  `GROUP_CONCAT`.
- `src/Modules/Packages/Infrastructure/definitions.php` — binds
  `PackageProviderDefinitionRepository` / `PackageProviderDefinitionDirectory`.
- `src/Modules/Packages/Application/{PackageCatalog,ResolvedPackage,PackageSummary,PackageAuditSnapshot}.php`
  — extended; `PackageCatalog` injects the resolver and drops non-sellable packages.
- `src/Modules/Packages/Application/UpdatePackage/{UpdatePackageCommand,UpdatePackageHandler}.php`
  and `.../SetPackageAvailability/SetPackageAvailabilityHandler.php` — display fields + drift
  sweep (`PackageProviderDefinitionRepository` dependency).
- `src/Database/Seeds/PackagesSeeder.php` — purchase-capability rows.
- `composer.json` / `composer.lock` — `package:set-capabilities` / `:set-country-capabilities` /
  `:link-provider` scripts + descriptions.
- `bin/UpdatePackage.php` / `bin/ListPackages.php` — display fields / purchase-type column.
- `tests/Integration/MigrationRoundTripTest.php` — 3 new tables; `tests/Integration/PackagesPersistenceTest.php`
  — second test; `tests/Unit/Modules/Packages/Application/{PackageHandlersTest,PackageCatalogTest}.php`
  — new constructor args + capability setup.
- DB docs (`database-design.md` → 30 tables, `database-diagram.md` + `.html` 10/10 mermaid,
  `db_explain.md`); `Architecture.md` (§8 Packages, §9 pipeline); `Phases.md` (row 12 → ☑);
  `.claude/FileIndex.md`; `.claude/knowledge/Knowledge.md`; `.claude/docs/Commands.md`;
  `.claude/Orders.md` (D15).

## Implementation Details

- **`PackagePurchaseCapability::validate()`** returns a `DomainError` (`package.trial_days_required`
  / `package.trial_not_allowed` / `package.invalid_duration`) for the handlers; `of()` throws for
  a programmer error. `has_trial = 0` forces `trial_days` NULL.
- **`Package::effectiveCapabilities(?country)`** — no `package_country_purchase_capabilities`
  rows for that country → global set; else global ∩ overridden types.
- **`PdoPackageProviderDefinitionRepository::markStaleForPackage()`** —
  `UPDATE … SET sync_state = 'drift' WHERE package_id = ? AND sync_state = 'synced'`, returns the
  row count; the editing handlers put the count in the audit `context`.
- **`LinkPackageProviderHandler`** resolves account ownership via
  `ProviderAccountDirectory::forClient(package.clientId)`; a new row is `synced` when a
  `remote_id` is given, `not_created` otherwise; an existing row is renamed / re-synced.
- **`ChangePackageProviderSyncStateHandler::markSynced()`** rejects a blank `remote_id`
  (`package.remote_id_required`); `markNotNeeded()` drops the `remote_id`.
- Audit actions: `package.capabilities_updated`, `package.country_capabilities_updated`,
  `package.provider_linked`, `package.provider_synced`, `package.provider_not_needed` (+ the
  existing `package.updated` / `package.availability_updated`, now carrying a
  `provider_definitions_drifted` context count).

## Database Changes

3 new tables + 1 additive `ALTER TABLE packages` (see `database-design.md` → *Packages —
capabilities & provider definitions (Phase 12)*):

- `package_purchase_capabilities` — FK `package_id` CASCADE. `UNIQUE (package_id, purchase_type)`.
- `package_country_purchase_capabilities` — FK `package_id` CASCADE + `country_code` →
  `countries(code)` RESTRICT. `UNIQUE (package_id, country_code, purchase_type)`.
- `package_provider_definitions` — FK `package_id` CASCADE + `provider_account_id` →
  `provider_accounts(id)` CASCADE. `UNIQUE (package_id, provider_account_id)`;
  `INDEX (provider_account_id / sync_state / remote_id)`.
- `packages` — `ADD COLUMN badge VARCHAR(40) NULL`, `highlighted TINYINT(1) NOT NULL DEFAULT 0`,
  `client_package_id VARCHAR(64) NULL` (all after `metadata`).

No backfill. Cross-client integrity on `package_provider_definitions` is app-enforced.

## API Changes

No HTTP endpoints (`GET /api/v1/packages` is Phase 13). `PackagePurchaseCapabilityResolver` /
`PackageProviderDefinitionDirectory` are internal ports. New CLI: 3 `bin/` scripts;
`bin/UpdatePackage.php` gained `--badge` / `--highlight` / `--client-package-id` flags.

## Tests and Validation

- **Tests created:** `PackagePurchaseCapabilityTest` (5), `PackageProviderDefinitionTest` (5),
  `PackageCapabilityHandlersTest` (5); `PackageCatalogTest` gained 2; `PackagesPersistenceTest`
  gained 1 integration test; 1 support double. **+17 net.**
- **Tests modified:** `PackageHandlersTest`, `PackageCatalogTest`, `PackagesPersistenceTest`
  (new constructor args), `MigrationRoundTripTest` (table list).
- **Commands run:**
  - `composer cs` → clean (354 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules, 353 files).
  - `composer test` → **`OK (218 tests, 826 assertions)`**.
  - `composer test:integration` → `Skipped: 32` (no usable local MySQL).
  - `composer ci` → green (exit 0).
- **Exit-criteria evidence** — a standalone script driving the real resolver + aggregate (see
  *Final Result*): global vs country-override purchase types, fail-closed catalogue drop, and the
  `not_created → synced → drift → not_needed` transitions incl. the edit sweep.

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 12 Q1–Q5:

1. **Join table with per-row trial/duration** (`package_purchase_capabilities`) + `badge` /
   `highlighted` / `client_package_id` columns on `packages`.
2. **`package_country_purchase_capabilities` override table** — rows *replace* the global set for
   a country; absent ⇒ inherit.
3. **`package_provider_definitions`** — one row per linked `(package, provider account)`, lazily
   created, 4-state `sync_state`; manual `remote_id` now, provider-API deferred.
4. **Dedicated `PackagePurchaseCapabilityResolver`** + extended `ResolvedPackage`; the
   market/provider ∩ stays with the payment flow.
5. **In-handler drift sweep** (`markStaleForPackage()`), no event bus; `composer package:*`
   CLI + seeder.

## Problems Encountered

- PHPStan `cast.useless` — `(string)` on an array key already typed `string` by phpdoc.
- PHPStan `argument.type` — `array_map('strval', …)` / `implode` over a `mixed` array in
  `bin/ListPackages.php`.
- Phase 11 package tests referenced the old constructor signatures of `PackageCatalog` /
  `SetPackageAvailabilityHandler` / `UpdatePackageHandler`.

## Resolutions

- Dropped the redundant cast (trust the phpdoc contract).
- Coerced closure values to strings with an `is_scalar` guard.
- Updated the Phase 11 tests: added the resolver / definitions-repo args and gave catalogue
  fixtures a default `one_time_payment` capability so they stay visible.

## Deferred Work

- Provider-API product/plan creation (`createRemoteProduct`) — Phases 21–23, per adapter.
- Resolved `price` on `ResolvedPackage` and the `GET /api/v1/packages` endpoint — Phase 13.
- Remote-side drift detection (a provider says its product changed) — a reconcile job, Phase
  19/29. Phase 12 only handles *local*-edit drift.
- `auto_charge` trial semantics — currently treated like any non-subscription type (no trial).
- Admin views for capabilities + definitions — Phase 27.
- Executing the 4 migrations + `PackagesPersistenceTest` against real MySQL — CI, or the user
  locally.

## Final Result

30 tables. A package now declares *what it can be sold as* (purchase types + trial/duration,
with per-country overrides — fail closed) and *where it lives on the provider side*
(`package_provider_definitions` with a 4-state sync machine and automatic local-edit drift).
`PackageCatalog::resolve()` returns sellable, market-matching packages with their
country-effective capabilities — one step from Phase 13 adding price.

Captured evidence (real, from the exit-criteria script):

```
=== catalogue: DE / EUR ===
  pro        purchase=one_time_payment,subscription  badge="Best value"  [highlighted]
  starter    purchase=one_time_payment
  (draft omitted — no purchase capability)

=== 'pro' effective purchase types by country ===
  DE -> one_time_payment / 1mo, subscription (trial 7d) / 1mo
  TR -> one_time_payment / 1mo

=== provider-definition state machine ===
  link (no remote id)     -> not_created
  markSynced(prod_ABC)    -> synced (remote_id=prod_ABC)
  package edited (sweep)  -> drift
  markNotNeeded           -> not_needed (remote_id=NULL)
```

`composer test` — `OK (218 tests, 826 assertions)`; `composer stan` — `[OK] No errors`;
`composer cs` — clean; `composer test:integration` — `Skipped: 32`.

Next recommended phase: **Phase 13 — Pricing module: default prices & pricing groups.**
