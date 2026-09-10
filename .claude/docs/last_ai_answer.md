# Q: Phase 11 — Packages module: catalog & availability

**Status:** ☑ code + docs complete. `composer ci` green — `composer cs` clean, `composer stan`
`[OK] No errors` (level `max` + strict-rules, 321 files), `composer test` **OK (201 tests, 757
assertions)**. `composer test:integration` → `Skipped: 31` (no usable local MySQL; runs in CI).
2026-09-09 23:51 → 2026-09-10 00:43.

## Decisions (PhaseResults/PhaseDecisions.md Phase 11 Q1–Q5)

1. **Four dedicated availability join tables** (`package_countries` / `_currencies` /
   `_payment_methods` / `_provider_accounts`) — typed, FK'd, indexable; mirrors
   `provider_account_*` / `provider_group_*`.
2. **Empty set = available everywhere for that dimension** (fail open), per dimension.
3. **Internal `PackageCatalog` port only** this phase; `GET /api/v1/packages` mounted in Phase
   13 once price + purchase types exist.
4. **Lean `packages` table** — Phase 12 adds trial / badge / duration / purchase-capability
   columns additively.
5. **Separate `SetPackageAvailability` full-replace handler** + `composer package:*` CLI +
   env-gated seeder.

## Built (schema confirmed by the user first)

- **New Packages module** (`src/Modules/Packages/`, registered in `ContainerFactory`).
- **Migrations `20260910120001-05`:** `packages` (FK `client_id` CASCADE, `UNIQUE (client_id,
  code)`, `metadata` JSON) + 4 fail-open availability join tables. **27 tables total.**
- **Domain:** `Package` aggregate + `PackageCode` VO, `PackageStatus` enum, `PackageRepository`
  + `PdoPackageRepository`.
- **`PackageCatalog::resolve(clientId, country, currency, ?method)`** → `list<ResolvedPackage>`
  — active, market-matching packages with provider accounts narrowed to the client's active set.
  **No `price` (Phase 13), no `purchaseTypes` (Phase 12).** `PackageDirectory` raw read port +
  `PdoPackageDirectory` (`GROUP_CONCAT` projection).
- **Use cases:** `CreatePackage`, `UpdatePackage`, `ChangePackageStatus`, `SetPackageAvailability`
  — `Result` + audited `package.*`.
- **CLI:** `bin/{CreatePackage,UpdatePackage,SetPackageAvailability,ListPackages}.php`
  (`composer package:*`); env-gated `PackagesSeeder` (`local-dev` → `starter` + `pro`).

## Verified — captured `PackageCatalog` output

```
DE / EUR             -> [pro, starter]
DE / EUR / card      -> [pro, starter]
DE / EUR / paypal    -> [starter]      (pro is card-only)
FR / EUR             -> [starter]      (pro is DE-only)
DE / USD             -> [starter]      (pro is EUR-only)
```

`legacy` (disabled) never appears; `pro`'s provider accounts narrow to `[1]` (its set; disabled
account excluded). Exit criteria — per-client code uniqueness (`PackageHandlersTest`),
availability filtering + resolved-list (`PackageCatalogTest` + this script) — all pass.

## Tests

- New: `PackageTest` (6), `PackageCatalogTest` (3), `PackageHandlersTest` (5),
  `PackagesPersistenceTest` (1, self-skips), `InMemoryPackageRepository`.
- Modified: `MigrationRoundTripTest` (table list).

## NOT verified here

The 5 migrations + `PackagesPersistenceTest` against real MySQL — CI, or
`docker compose up -d mysql && composer db:reset && composer test:integration`. The local MySQL
rejects the `gomrok` DB user (same as Phases 5–10).

## `.claude/` updated

`database-design.md` (27 tables) / `database-diagram.md` + `.html` (9/9 mermaid parity) /
`db_explain.md`; `Architecture.md` §8 (Packages) + §9 (pipeline); `Phases.md` (row 11 → ☑,
scope rewritten); `Changelog.md`; `FileIndex.md`; `knowledge/Knowledge.md`; `Commands.md`;
`Orders.md` (D14); `PhaseResults/PhaseDecisions.md` (Phase 11 Q1–Q5 Decided);
`PhaseResults/Phase11Result.md`.

Next: **Phase 12 — Package purchase capabilities & provider definitions.**
