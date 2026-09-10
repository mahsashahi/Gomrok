# Phase 11 — Packages module: catalog & availability

## Execution Summary

- Phase: 11 — Packages module: catalog & availability
- Start Datetime: 2026-09-09 23:51
- End Datetime: 2026-09-10 00:43
- Estimated Duration: 4–6h
- Actual Duration: ~52m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; `composer ci` green — 201 unit tests. The 5 migrations +
  `PackagesPersistenceTest` run in CI — no usable local MySQL here.)

## Work Completed

- New **Packages module** (`src/Modules/Packages/`, registered in `ContainerFactory`). The
  client-owned catalogue: one `packages` table with `client_id`, `code` unique per client — no
  global catalogue, no `client_packages` junction (Package Ownership Rule).
- 5 migrations: `packages` + four dedicated availability join tables (`package_countries`,
  `package_currencies`, `package_payment_methods`, `package_provider_accounts`). **27 tables
  total.** Each dimension is **fail open** — an empty set = available everywhere for it (Q2).
- `Package` aggregate (+ `PackageCode` VO, `PackageStatus` enum), `PackageRepository` port +
  `PdoPackageRepository`.
- `PackageCatalog` — a concrete Application class (like `ProviderRouter`) that resolves a
  market context `(clientId, country, currency, ?method)` to `list<ResolvedPackage>`, narrowing
  each package's provider-account set to the client's active accounts. `PackageDirectory` read
  port + `PdoPackageDirectory` (`GROUP_CONCAT` projection).
- Use cases: `CreatePackage`, `UpdatePackage` (name / description / metadata),
  `ChangePackageStatus` (disable / enable), `SetPackageAvailability` (all 4 dimension lists,
  full-replace) — each returns `Result` and writes an audited `package.*` action.
- CLI: `bin/{CreatePackage,UpdatePackage,SetPackageAvailability,ListPackages}.php`
  (`composer package:*`). Env-gated `PackagesSeeder` — `local-dev` gets `starter` (unrestricted)
  and `pro` (DE + EUR).
- **`GET /api/v1/packages` is NOT mounted** (Q3) — deferred to Phase 13 so the endpoint ships
  once with price + purchase types.

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/2026091012000{1..5}_*.php` → `Create{Packages,PackageCountries,PackageCurrencies,PackagePaymentMethods,PackageProviderAccounts}Table`
- `src/Database/Seeds/PackagesSeeder.php`

**Packages / Domain** — `Package.php`, `PackageCode.php`, `PackageStatus.php`,
`PackageRepository.php`

**Packages / Application** — `PackageCatalog.php`, `ResolvedPackage.php`, `PackageDirectory.php`,
`PackageSummary.php`, `PackageAuditSnapshot.php`; use-case folders `CreatePackage`
(`Command` / `Handler` / `Result`), `UpdatePackage` (`Command` / `Handler`),
`ChangePackageStatus` (`Handler`), `SetPackageAvailability` (`Command` / `Handler`)

**Packages / Infrastructure** — `PdoPackageRepository.php`, `PdoPackageDirectory.php`,
`definitions.php`

**CLI** — `bin/CreatePackage.php`, `bin/UpdatePackage.php`, `bin/SetPackageAvailability.php`,
`bin/ListPackages.php`

**Tests** — `tests/Unit/Modules/Packages/Domain/PackageTest.php`,
`tests/Unit/Modules/Packages/Application/PackageCatalogTest.php`,
`tests/Unit/Modules/Packages/Application/PackageHandlersTest.php`,
`tests/Integration/PackagesPersistenceTest.php`,
`tests/Support/InMemoryPackageRepository.php`

`.claude/PhaseResults/Phase11Result.md` — this file.

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` gains
  `src/Modules/Packages/Infrastructure/definitions.php`.
- `composer.json` / `composer.lock` — `package:create` / `:update` / `:set-availability` /
  `:list` scripts + descriptions; lock hash refreshed (no dependency change).
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables in `TABLES`.
- Docs: `.claude/docs/database-design.md` (→ 27 tables, Phase 11 section, migrations list),
  `.claude/docs/database-diagram.md` (Packages ER + module map, 9 mermaid blocks),
  `.claude/docs/database-diagram.html` (snapshot ER + module-map label, 9 blocks),
  `.claude/docs/db_explain.md` (Phase 11 per-table guide), `.claude/docs/Architecture.md`
  (§8 Packages, §9 pipeline), `.claude/docs/Phases.md` (row 11 → ☑, scope rewritten),
  `.claude/docs/Commands.md`, `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`,
  `.claude/Orders.md` (D14), `.claude/Changelog.md`.

## Implementation Details

- **`Package`** aggregate: `create()` / `fromStorage()`; `update()` (null = leave,
  `clearDescription` / `clearMetadata` explicitly null), `setAvailability()` (upper-cases +
  dedups countries/currencies, dedups methods by enum value, dedups provider ids), `disable()` /
  `enable()` (idempotent). `availableInCountry()` / `availableInCurrency()` /
  `availableViaMethod()` / `availableViaProviderAccount()` all return `true` for an empty set.
- **`PackageCode`** — `/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/` (lowercase, digits, `-`, `_`;
  no leading/trailing separator). `PackageCode::error()` → `package.invalid_code`.
- **`PackageCatalog::resolve()`** — `providerAccounts->forClient()` once, index active ids;
  `packages->forClient()`, skip disabled / non-matching; `narrowProviderAccounts()` returns the
  client's active accounts ∩ (package set or "all" if empty).
- **`PdoPackageRepository::save()`** — insert/update `packages` (metadata `json_encode`d), then
  delete-and-reinsert the four child tables. `hydrate()` `json_decode`s metadata to
  `array<string,mixed>|null`.
- **`SetPackageAvailabilityHandler`** validates every country / currency (`ReferenceCatalog`),
  method (`PaymentMethod::tryFrom`), and provider-account id (must be in
  `ProviderAccountDirectory::forClient(package.clientId)` — `package.account_not_owned`).
- **Audit actions:** `package.created`, `package.updated`, `package.disabled` / `package.enabled`,
  `package.availability_updated`.

## Database Changes

5 new tables (see `database-design.md` → *Packages — catalog & availability (Phase 11)*):

- `packages` — FK `client_id` → `clients(id)` CASCADE. `UNIQUE (client_id, code)`;
  `INDEX (client_id, status)`. `metadata` JSON nullable.
- `package_countries` — FK `package_id` CASCADE, `country_code` → `countries(code)` RESTRICT.
  `UNIQUE (package_id, country_code)`.
- `package_currencies` — FK `package_id` CASCADE, `currency_code` → `currencies(code)` RESTRICT.
  `UNIQUE (package_id, currency_code)`.
- `package_payment_methods` — FK `package_id` CASCADE, `payment_method VARCHAR(20)` (app-enforced).
  `UNIQUE (package_id, payment_method)`.
- `package_provider_accounts` — FK `package_id` CASCADE, `provider_account_id` →
  `provider_accounts(id)` CASCADE. `UNIQUE (package_id, provider_account_id)`.

No changes to existing tables. No backfill.

## API Changes

No HTTP endpoints — `GET /api/v1/packages` is deferred to Phase 13 (Q3). `PackageCatalog` /
`PackageDirectory` are internal ports consumed by the payment-creation flow (Phase 17). New CLI:
4 `bin/` scripts.

## Tests and Validation

- **Tests created:** 3 unit classes (13 tests) — `PackageTest` (6), `PackageCatalogTest` (3),
  `PackageHandlersTest` (5, incl. per-client code uniqueness + same-code-different-client);
  1 integration class (`PackagesPersistenceTest`, 1 test); 1 support double.
- **Tests modified:** `MigrationRoundTripTest` (table list).
- **Commands run:**
  - `composer cs` → clean (322 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules, 321 files).
  - `composer test` → **`OK (201 tests, 757 assertions)`**.
  - `composer test:integration` → `Skipped: 31` (no usable local MySQL).
  - `composer ci` → green (exit 0).
- **Exit-criteria evidence** — a standalone script driving `PackageCatalog` against in-memory
  data (see *Final Result* for the captured output): per-client uniqueness (`PackageHandlersTest`),
  availability filtering across country / currency / method, disabled-package exclusion, and
  provider-account narrowing.

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 11 Q1–Q5:

1. **Four dedicated availability join tables** (not a generic table or JSON) — typed, FK'd,
   indexable; mirrors `provider_account_*` / `provider_group_*`.
2. **Empty set = available everywhere for that dimension** (fail open), per dimension.
3. **Internal `PackageCatalog` port only** this phase; `GET /api/v1/packages` mounted in Phase
   13 once price + purchase types exist.
4. **Lean `packages` table** (`code`, `name`, `description`, `status`, `metadata` JSON);
   Phase 12 adds its fields additively.
5. **Separate `SetPackageAvailability` full-replace handler** + `composer package:*` CLI +
   env-gated seeder.

## Problems Encountered

- PHPStan `argument.type` — `implode()` on a value PHPStan couldn't prove was `array<string>`
  inside the `bin/ListPackages.php` closure.
- PHPStan `instanceof.alwaysTrue` / `function.alreadyNarrowedType` — an `assert($x instanceof
  PackageSummary)` on an already-typed `list<PackageSummary>` in the same file.

## Resolutions

- Coerced values to strings inside the closure (`is_scalar` guard) and passed the raw list.
- Removed the redundant `assert()`.

## Deferred Work

- `GET /api/v1/packages` HTTP endpoint — Phase 13 (with price + purchase types).
- Package purchase capabilities, country restrictions on purchase types, trial config
  (`hasTrial` / `trialDays`), `durationMonths`, `badge`, `highlighted`, `clientPackageId`,
  package-provider definitions (remote id + sync state) — Phase 12 (additive migrations on
  `packages`).
- Resolved `price` on `ResolvedPackage` — Phase 13.
- A denylist availability dimension ("everywhere except X") — additive if a real case appears.
- Admin views for the catalogue — Phase 27.
- Executing the 5 migrations + `PackagesPersistenceTest` against real MySQL — CI, or the user
  locally.

## Final Result

27 tables. A client owns its catalogue: `packages` (code unique per client) + four fail-open
availability dimensions. `PackageCatalog::resolve()` returns the market-filtered list with
provider accounts narrowed to the client's active set — ready for the payment-creation flow and
the Phase 13 endpoint.

Captured `PackageCatalog` output (real, from the exit-criteria script — `starter` unrestricted,
`pro` = DE + EUR + card + account 1, `legacy` disabled):

```
DE / EUR             -> [pro, starter]
DE / EUR / card      -> [pro, starter]
DE / EUR / paypal    -> [starter]
FR / EUR             -> [starter]
DE / USD             -> [starter]

--- resolved 'pro' (DE / EUR) ---
{
    "id": 2, "code": "pro", "name": "Pro", "description": "Full plan",
    "metadata": { "tier": 2 },
    "availableProviderAccountIds": [ 1 ],
    "availableMethods": [ "card" ]
}
```

`composer test` — `OK (201 tests, 757 assertions)`; `composer stan` — `[OK] No errors`;
`composer cs` — clean; `composer test:integration` — `Skipped: 31`.

Next recommended phase: **Phase 12 — Package purchase capabilities & provider definitions.**
