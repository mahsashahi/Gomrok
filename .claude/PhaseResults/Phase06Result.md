# Phase 06 — Clients module: domain & persistence

## Execution Summary

- Phase: 06 — Clients module: domain & persistence
- Start Datetime: 2026-09-08 15:14
- End Datetime: 2026-09-08 16:33
- Estimated Duration: 3–5h
- Actual Duration: 1h 19m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; migrations + module integration tests run in CI, not in
  this environment)

## Work Completed

- First `src/Modules/` module — established the `Domain` / `Application` / `Infrastructure` /
  `Http` layout and a per-module `Infrastructure/definitions.php` merged by `ContainerFactory`.
- Designed and (after user confirmation) created `clients`, `client_api_keys`,
  `client_endpoints`, plus the additive `AddClientFksToCrossCuttingTables` migration (the
  `client_id` FKs deferred from Phase 5).
- Built the `Client` aggregate (+ `ClientEndpoint`, `ClientApiKey`), the `ClientSlug` value
  object, `ApiKeyToken` parser, repository ports + PDO adapters, and the published
  `ClientDirectory` read port with `ClientSnapshot`.
- 8 use-case handlers (`CreateClient`, `UpdateClient`, `DisableClient`, `EnableClient`,
  `SetClientEndpoint`, `RemoveClientEndpoint`, `IssueApiKey`, `RevokeApiKey`) — each returns
  `Result` / `DomainError`, wraps writes in `Transactions`, and records an `audit_logs` row.
- Shared additions: `TokenGenerator` + `RandomTokenGenerator`, `ReferenceCatalog` +
  `PdoReferenceCatalog`, `Transactions` port (now implemented by `TransactionRunner`), `Row`
  persistence-boundary coercion helper, `DomainEvent` marker + the module's `Domain/Events/*`.
- CLI onboarding: `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php`
  (`composer client:*`), and `ClientsSeeder` (env-gated `local-dev` client + fixed dev key).
- Updated the four DB docs, Architecture §5/§11, Changelog, Phases (row 6), FileIndex, Knowledge,
  Commands, Orders (D9), `.env.example`.

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/20260908150001_create_clients_table.php` — `CreateClientsTable`
- `src/Database/Migrations/20260908150002_create_client_api_keys_table.php` — `CreateClientApiKeysTable`
- `src/Database/Migrations/20260908150003_create_client_endpoints_table.php` — `CreateClientEndpointsTable`
- `src/Database/Migrations/20260908150004_add_client_fks_to_cross_cutting_tables.php` — `AddClientFksToCrossCuttingTables`
- `src/Database/Seeds/ClientsSeeder.php` — env-gated dev client (`APP_ENV ∈ {local, testing}`)

**Clients / Domain** — `Client`, `ClientEndpoint`, `ClientApiKey`, `ClientSlug`,
`InvalidClientSlug`, `ClientStatus`, `ApiKeyStatus`, `ApiKeyPrefix`, `EndpointPurpose`,
`ClientRepository`, `ClientApiKeyRepository`, `ApiKeyGenerator`, `ApiKeyToken`, `GeneratedApiKey`,
`Events/{ClientCreated,ClientUpdated,ClientDisabled,ClientEnabled,ApiKeyIssued,ApiKeyRevoked}`.

**Clients / Application** — `ClientDirectory`, `ClientSnapshot`, `ClientAuditSnapshot`, and each
of `CreateClient` / `UpdateClient` / `DisableClient` / `EnableClient` / `SetClientEndpoint` /
`RemoveClientEndpoint` / `IssueApiKey` / `RevokeApiKey` as `*Handler` + `*Command` [+ `*Result`].

**Clients / Infrastructure** — `PdoClientRepository`, `PdoClientApiKeyRepository`,
`PdoClientDirectory`, `RandomApiKeyGenerator`, `definitions.php`.

**Shared** — `src/Shared/Application/{TokenGenerator,ReferenceCatalog,Transactions}.php`,
`src/Shared/Infrastructure/RandomTokenGenerator.php`,
`src/Shared/Infrastructure/Persistence/{PdoReferenceCatalog,Row}.php`,
`src/Shared/Domain/DomainEvent.php`.

**CLI** — `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php`.

**Tests** — `tests/Unit/Modules/Clients/Domain/{ClientSlugTest,ClientTest,ApiKeyTokenTest,ClientApiKeyTest}.php`,
`tests/Unit/Modules/Clients/Infrastructure/RandomApiKeyGeneratorTest.php`,
`tests/Unit/Modules/Clients/Application/{CreateClientHandlerTest,ClientHandlersTest}.php`,
`tests/Integration/ClientsPersistenceTest.php`,
`tests/Support/{SynchronousTransactions,InMemoryClientRepository,InMemoryClientApiKeyRepository,FixedTokenGenerator,InMemoryReferenceCatalog,RecordingAuditLogWriter}.php`.

## Files Modified

- `src/Shared/Infrastructure/Persistence/TransactionRunner.php` — `implements Transactions`.
- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` list; merges each module's
  `definitions.php`.
- `src/Config/container.php` — binds `TokenGenerator` → `RandomTokenGenerator`,
  `ReferenceCatalog` → `PdoReferenceCatalog`, `Transactions` → `TransactionRunner`.
- `composer.json` — `client:create` / `client:issue-key` / `client:revoke-key` / `client:list`
  scripts + descriptions.
- `tests/Integration/MigrationRoundTripTest.php` — `TABLES` gains the three clients tables.
- Docs: `.claude/docs/database-design.md`, `database-diagram.md` + `.html`, `db_explain.md`,
  `Phases.md` (row 6 → ☑, scope/exit rewritten), `Architecture.md` §1/§5,
  `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`,
  `.claude/Orders.md` (D9), `.env.example`, `.claude/PhaseDecisions.md` (Phase 6 Q1–Q5).

## Implementation Details

- **`Client`** is a mutable aggregate with a `?int $id` assigned by the repository; state-changing
  methods take the current time and stamp `updatedAt`. `disable()` / `enable()` are idempotent
  (a second call is a no-op and does not overwrite the first stamp). Endpoints are held keyed by
  purpose; `setEndpoint()` upserts.
- **`RandomApiKeyGenerator`** mints `key_id` = 8 random bytes hex, `secret` = 24 random bytes
  URL-safe base64; token `gk_<mode>_<key_id>.<secret>`; stores `sha256(secret)` + `last_four`.
  **`ApiKeyToken::parse()`** validates the shape (`/^(gk_(?:live|test))_([0-9a-f]{16})\.([A-Za-z0-9_-]{16,128})$/`)
  and returns `null` for anything malformed — Phase 7 treats that as an auth failure with no DB
  hit.
- **`ClientApiKey::matchesSecret()`** uses `hash_equals`; `isUsable()` = active **and** not
  expired.
- **Handlers** validate slug (`ClientSlug::isValid`), currency/country format (`Currency::of` /
  `CountryCode::of`), and market membership (`ReferenceCatalog`) before the FK would fire; slug
  uniqueness is checked in code **and** enforced by `uniq_clients_slug`.
- **`PdoClientRepository::save()`** inserts-or-updates the `clients` row then rewrites all of the
  client's `client_endpoints` (delete-all + reinsert — a handful of rows). Callers wrap it in
  `Transactions`.
- **`ContainerFactory`** now loads `src/Config/container.php` then each entry in
  `MODULE_DEFINITIONS` (`src/Modules/Clients/Infrastructure/definitions.php`).
- **Domain events** are constructed and returned in use-case results but **not dispatched** — no
  subscriber exists; `Shared\Domain\DomainEvent` is just a marker.

## Database Changes

New tables `clients`, `client_api_keys`, `client_endpoints` — full columns/indexes in
`database-design.md`. All three FK to `clients(id)` ON DELETE CASCADE. `client_endpoints` has
`UNIQUE (client_id, purpose)`; `client_api_keys` has `UNIQUE (key_id)`; `clients` has
`UNIQUE (slug)` and FKs to `currencies(code)` / `countries(code)`.

`AddClientFksToCrossCuttingTables` adds `fk_idempotency_keys_client_id` (CASCADE),
`fk_audit_logs_client_id` / `fk_error_logs_client_id` (SET NULL).

`ClientsSeeder` inserts one `local-dev` client + a fixed `gk_test` key — no-op unless `APP_ENV`
is `local` / `testing`.

## API Changes

No HTTP endpoints. `ClientDirectory` is the module's published read interface for later modules.
CLI: four `bin/` scripts. Internal: use cases depend on the new `Transactions` port;
`TransactionRunner` implements it (backward compatible).

## Tests and Validation

- Tests created: 7 unit classes (32 test methods) + 1 integration class + 6 support doubles.
- Tests modified: `MigrationRoundTripTest` table list.
- Commands run:
  - `composer cs` → clean (153 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules + phpunit, 153 files).
  - `composer test` → `OK (104 tests, 306 assertions)`.
  - `composer test:all` → `OK, but some tests were skipped! Tests: 123 … Skipped: 19` (no Docker
    / local MariaDB rejects `gomrok`).
  - `composer ci` → green (exit 0).
  - Migration/seeder classes load and extend the correct Phinx bases; `ContainerFactory` builds
    and resolves the module ports (only the live DB connection fails here).

## Technical Decisions

`PhaseDecisions.md` Phase 6 Q1–Q5:

1. API key = prefixed token + `sha256(secret)`, looked up by a public `key_id`.
2. Client settings = typed columns on `clients` + a `client_endpoints` table (not JSON / EAV).
3. Required, unique, **immutable** `slug`.
4. Client status = `active` / `disabled`, soft + reversible, **keys not touched**; the Phase 7
   auth middleware is the enforcement point.
5. Onboarding via CLI commands + an `APP_ENV`-gated dev seeder.

In-phase: extracted a `Transactions` port so use cases are unit-testable without a PDO;
per-module `definitions.php` for DI; `Row` helper for the PDO type boundary; a `ReferenceCatalog`
so unknown currency/country is a clean 422 rather than a raw FK violation.

## Problems Encountered

- PHPStan: `?int` aggregate ids passed to `AuditEntry::withTarget(string, int)`.
- PHPStan: `assertStringEndsWith` wants `non-empty-string`.
- Test design: `CountryCode::of('ZZ')` is well-formed, so it doesn't exercise the
  malformed-code path.

## Resolutions

- Captured the non-null `int` (from the command or a post-save `\assert`) into a local before
  building the audit entry.
- Replaced `assertStringEndsWith(...)` with `assertSame(substr($secret, -4), $key->lastFour())`.
- Used `'z1'` (fails the `[A-Z]{2}` format) for the malformed-country test; `'FR'` (well-formed,
  not a seeded market) for the unknown-market test.

## Deferred Work

- API-key authentication middleware + per-request client scoping + `Idempotency-Key` wiring —
  **Phase 7**.
- The domain-event dispatcher — the first phase with a subscriber.
- Admin-actor propagation into audit entries (currently always `system`) — **Phase 26** (admin
  panel).
- `RotateSigningSecret` use case — when Notifications (Phase 24) needs it.
- Executing the migrations / `ClientsPersistenceTest` / `ClientsSeeder` against real MySQL —
  GitHub Actions, or the user locally.

## Final Result

9 tables (3 reference + 3 cross-cutting + 3 clients), with the Phase 5 `client_id` FKs now in
place. The Clients module has a full domain model, 8 use cases, PDO adapters, a published
`ClientDirectory`, CLI onboarding, and 104 passing unit tests. Nothing is exposed over HTTP yet.

Next recommended phase: **Phase 7 — Client API authentication & scoping.**
