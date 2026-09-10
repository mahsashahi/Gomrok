# Q: Phase 12 — Package purchase capabilities & provider definitions

**Status:** ☑ code + docs complete. `composer ci` green — `composer cs` clean, `composer stan`
`[OK] No errors` (level `max` + strict-rules, 353 files), `composer test` **OK (218 tests, 826
assertions)**. `composer test:integration` → `Skipped: 32` (no usable local MySQL; runs in CI).
2026-09-10 10:16 → 13:16.

## Decisions (PhaseResults/PhaseDecisions.md Phase 12 Q1–Q5)

1. **Join table with per-row trial/duration** (`package_purchase_capabilities`) + `badge` /
   `highlighted` / `client_package_id` columns on `packages`.
2. **`package_country_purchase_capabilities` override table** — rows *replace* the global set for
   that country; absent ⇒ inherit.
3. **`package_provider_definitions`** — one row per linked `(package, provider account)`, lazily
   created, 4-state `sync_state` (`not_created` / `synced` / `drift` / `not_needed`); manual
   `remote_id` now, provider-API creation deferred to Phases 21–23.
4. **Dedicated `PackagePurchaseCapabilityResolver`** + extended `ResolvedPackage`; the
   market/provider ∩ stays with the payment flow (Phase 17).
5. **In-handler drift sweep** (`markStaleForPackage()`), no event bus; `composer package:*` CLI +
   seeder.

## Built (schema confirmed by the user first)

- **Migrations `20260910130001-04`:** 3 new tables + `ALTER packages` (3 cols). **30 tables total.**
- **Purchase capabilities** are **fail closed** — a package with no `package_purchase_capabilities`
  row is not sellable and `PackageCatalog::resolve()` drops it (Phase 11 availability is fail
  open — opposite direction). Trial config lives on the capability row; domain guard: trial ⇒
  `trial_days` set AND type ∈ {subscription, recurring_payment}.
- **Country override** *replaces* the global set for one country (`Package::effectiveCapabilities`).
- **`PackageProviderDefinition`** aggregate + `sync_state` machine; `LinkPackageProvider`,
  `ChangePackageProviderSyncState`; editing a package flips `synced` → `drift` in-transaction.
- **Use cases:** `SetPackagePurchaseCapabilities`, `SetPackageCountryPurchaseCapabilities`,
  `LinkPackageProvider`, `ChangePackageProviderSyncState`; `UpdatePackage` extended.
- **CLI:** `composer package:set-capabilities` / `:set-country-capabilities` / `:link-provider`;
  `PackagesSeeder` gives `pro` a subscription (7-day trial).

## Verified — captured evidence

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

Exit criteria — purchase-type gating (global + country override + fail-closed drop) and
provider-definition state transitions — covered by `PackagePurchaseCapabilityTest`,
`PackageProviderDefinitionTest`, `PackageCapabilityHandlersTest`, `PackageCatalogTest`.

## Tests

- New: `PackagePurchaseCapabilityTest` (5), `PackageProviderDefinitionTest` (5),
  `PackageCapabilityHandlersTest` (5), `PackageCatalogTest` +2, `PackagesPersistenceTest` +1,
  `InMemoryPackageProviderDefinitionRepository`.
- Modified: Phase 11 package tests (new constructor args), `MigrationRoundTripTest`.

## NOT verified here

The 4 migrations + `PackagesPersistenceTest` against real MySQL — CI, or `docker compose up -d
mysql && composer db:reset && composer test:integration`. Local MySQL rejects the `gomrok` user.

## `.claude/` updated

`database-design.md` (30 tables) / `database-diagram.md` + `.html` (10/10 mermaid) /
`db_explain.md`; `Architecture.md` §8 + §9; `Phases.md` (row 12 → ☑); `Changelog.md`;
`FileIndex.md`; `knowledge/Knowledge.md`; `Commands.md`; `Orders.md` (D15);
`PhaseResults/PhaseDecisions.md` (Phase 12 Q1–Q5 Decided); `PhaseResults/Phase12Result.md`.

Next: **Phase 13 — Pricing module: default prices & pricing groups.**
