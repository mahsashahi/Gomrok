# Changelog

All meaningful changes to Gomrok. Newest first. Each entry: date, summary, files changed,
reason, migration notes (if any), breaking changes (if any).

(Doc file locations have moved twice. Original: project root. 2026-09-06: `Documents/`.
2026-09-07: `.claude/` (this file is now `.claude/Changelog.md`). Older entries name the paths
that were correct when written.)

## 2026-09-08 — Phase 7: Client API authentication & scoping

**Summary.** Gomrok's first real API surface: `/api/v1` group behind Bearer API-key auth, plus
`GET /api/v1/me`; `/health` stays public. Decisions (`PhaseDecisions.md` Phase 7 Q1–Q5):
`Authorization: Bearer` only · a per-request `ClientContext` holder + request attributes ·
`last_used_at` written throttled (≤ 1/key/5 min) · `401 unauthorized` for any credential fault,
`403 client_disabled` for a valid key on a disabled client, generic bodies · `Idempotency-Key`
required on `/api/v1` writes + failed-attempt logging to a new table, **no rate limiting yet**.
**Schema confirmed by the user** before the migration.

**Files created**
- Migration `20260908170001_create_client_auth_attempts_table.php` → `CreateClientAuthAttemptsTable`.
- Shared\Http: `ClientAuthenticator` (port), `AuthResult`, `AuthenticatedClient`,
  `AuthRequestMeta`, `ClientContext`, `AuthenticationMiddleware`.
- Clients: `Domain/AuthFailureReason`; `Application/Authenticate/{ApiKeyAuthenticator,AuthAttempt,AuthAttemptLog}`;
  `Infrastructure/PdoAuthAttemptLog`.
- `src/Http/Api/MeAction.php` — `GET /api/v1/me`.
- Tests: `tests/Unit/Shared/Http/{ClientContextTest,AuthenticationMiddlewareTest}.php`,
  `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php`,
  `tests/Unit/Http/{MeActionTest,ApiRoutingTest}.php`,
  `tests/Integration/AuthAttemptsPersistenceTest.php`,
  `tests/Support/{StubClientAuthenticator,RecordingAuthAttemptLog,InMemoryClientDirectory}.php`.
- `.claude/PhaseResults/Phase07Result.md`.

**Files modified**
- `src/Config/routes.php` — `/api/v1` group with `AuthenticationMiddleware` + `IdempotencyMiddleware`;
  `/health` stays outside it.
- `src/Shared/Http/IdempotencyMiddleware.php` — `requireKeyOnWrites` flag (keyless write → 400).
- `src/Config/container.php` — `IdempotencyMiddleware` autowired with `requireKeyOnWrites: true`,
  `replayResolver: null`.
- `src/Bootstrap/AppFactory.php` — `create(?ContainerInterface $container = null)`.
- `src/Modules/Clients/Domain/ClientApiKeyRepository.php` (+ Pdo impl, + in-memory double) —
  `touchLastUsed()`.
- `src/Modules/Clients/Infrastructure/definitions.php` — binds `ClientAuthenticator`,
  `AuthAttemptLog`.
- `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php` — keyless-write 400 case.
  `tests/Integration/MigrationRoundTripTest.php` — `client_auth_attempts` in the table list.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 10 tables),
  `.claude/docs/Phases.md` (row 7 → ☑), `Architecture.md`, `FileIndex.md`, `knowledge/Knowledge.md`,
  `Commands.md`, `Orders.md` (D10), `PhaseDecisions.md` (Phase 7 Q1–Q5).

**DB changes.** New table `client_auth_attempts` (FK to `clients(id)` SET NULL, three
`(*, created_at)` indexes). No changes to existing tables.

**Verification.** `composer ci` green — 124 unit tests, 383 assertions. PHPStan `max` +
strict-rules clean (175 files). php-cs-fixer clean. `composer test:integration` → 21 tests, all
self-skip (no Docker). Real HTTP responses captured: `GET /health` → 200
`{"status":"ok","service":"gomrok"}`; `GET /api/v1/me` no key → 401 +
`WWW-Authenticate: Bearer realm="gomrok"`; with a (stubbed) valid key → 200 client JSON.

**NOT verified here.** Migration + `AuthAttemptsPersistenceTest` against real MySQL — runs in
GitHub Actions, or `docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** None (all additive; `AppFactory::create()` gained an optional parameter).

## 2026-09-08 — Phase 6: Clients module (domain & persistence)

**Summary.** The tenant model — the first `src/Modules/` module. Decisions (`PhaseDecisions.md`
Phase 6 Q1–Q5): API key = prefixed token + `sha256(secret)` looked up by a public `key_id` ·
client settings = typed columns on `clients` + a `client_endpoints` table · required immutable
`slug` · soft reversible `active`/`disabled` (keys untouched) · CLI commands + an
`APP_ENV`-gated dev seeder. **Schema confirmed by the user** before any migration.

**Files created**
- Migrations: `20260908150001..04_*` → `Create{Clients,ClientApiKeys,ClientEndpoints}Table`,
  `AddClientFksToCrossCuttingTables` (the Phase 5 `client_id` FKs). Seeder: `ClientsSeeder`.
- `src/Modules/Clients/Domain/*` — `Client`, `ClientEndpoint`, `ClientApiKey`, `ClientSlug`,
  `InvalidClientSlug`, `ClientStatus`, `ApiKeyStatus`, `ApiKeyPrefix`, `EndpointPurpose`,
  `ClientRepository`, `ClientApiKeyRepository`, `ApiKeyGenerator`, `ApiKeyToken`,
  `GeneratedApiKey`, `Events/{ClientCreated,ClientUpdated,ClientDisabled,ClientEnabled,ApiKeyIssued,ApiKeyRevoked}`.
- `src/Modules/Clients/Application/*` — `ClientDirectory`, `ClientSnapshot`, `ClientAuditSnapshot`,
  and `{CreateClient,UpdateClient,DisableClient,EnableClient,SetClientEndpoint,RemoveClientEndpoint,IssueApiKey,RevokeApiKey}/`
  (handler + command [+ result]).
- `src/Modules/Clients/Infrastructure/*` — `PdoClientRepository`, `PdoClientApiKeyRepository`,
  `PdoClientDirectory`, `RandomApiKeyGenerator`, `definitions.php`.
- Shared: `src/Shared/Application/{TokenGenerator,ReferenceCatalog,Transactions}.php`,
  `src/Shared/Infrastructure/RandomTokenGenerator.php`,
  `src/Shared/Infrastructure/Persistence/{PdoReferenceCatalog,Row}.php`,
  `src/Shared/Domain/DomainEvent.php`.
- CLI: `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php`.
- Tests: `tests/Unit/Modules/Clients/**` (Domain: ClientSlug, Client, ApiKeyToken, ClientApiKey;
  Infrastructure: RandomApiKeyGenerator; Application: CreateClientHandler, ClientHandlers),
  `tests/Integration/ClientsPersistenceTest.php`, `tests/Support/{SynchronousTransactions,
  InMemoryClientRepository,InMemoryClientApiKeyRepository,FixedTokenGenerator,
  InMemoryReferenceCatalog,RecordingAuditLogWriter}.php`.
- `.claude/PhaseResults/Phase06Result.md`.

**Files modified**
- `src/Shared/Infrastructure/Persistence/TransactionRunner.php` — implements the new
  `Transactions` port. `src/Bootstrap/ContainerFactory.php` — merges module `definitions.php`.
- `src/Config/container.php` — binds `TokenGenerator`, `ReferenceCatalog`, `Transactions`.
- `composer.json` — `client:create` / `client:issue-key` / `client:revoke-key` / `client:list`.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 9 tables),
  `.claude/docs/Phases.md` (row 6 → ☑), `Architecture.md`, `FileIndex.md`, `knowledge/Knowledge.md`,
  `Commands.md`, `Orders.md` (D9), `.env.example`, `PhaseDecisions.md` (Phase 6 Q1–Q5).

**DB changes.** New tables `clients`, `client_api_keys`, `client_endpoints` (all FK to
`clients(id)` CASCADE). Added `fk_idempotency_keys_client_id` (CASCADE),
`fk_audit_logs_client_id` / `fk_error_logs_client_id` (SET NULL). Seeder `ClientsSeeder` is a
no-op outside `local` / `testing`.

**Verification.** `composer ci` green — 104 unit tests, 306 assertions. PHPStan `max` +
strict-rules clean (153 files). php-cs-fixer clean. `composer test:integration` → 19 tests, all
self-skip (no Docker). Migration classes load and extend the right Phinx bases; the DI container
builds and resolves the module ports (only the live DB connection fails here).

**NOT verified here.** Migrations + `ClientsPersistenceTest` + `ClientsSeeder` against real
MySQL — runs in GitHub Actions, or `docker compose up -d mysql && composer db:reset && composer test:integration`.

**Breaking changes.** None (all additive; `TransactionRunner` gained an interface it already
satisfied).

## 2026-09-08 — Phase 5: Migration workflow & cross-cutting tables

**Summary.** The tables nearly every later module writes to, plus their ports/adapters, plus CI
that finally executes migrations against real MySQL. Decisions (`PhaseDecisions.md` Phase 5
Q1–Q5): idempotency = lock + entity mapping (no stored response bodies) · audit = event + full
before/after row snapshots · error log = explicit writer only (no Monolog DB handler) ·
idempotency retention = `expires_at` + purge job · hardening = round-trip test + `db:reset` +
GitHub Actions CI. **Schema confirmed by the user** before any migration.

**Files created**
- Migrations: `src/Database/Migrations/2026090814000{1,2,3}_create_{idempotency_keys,audit_logs,error_logs}_table.php`
  → `Gomrok\Database\Migrations\Create{IdempotencyKeys,AuditLogs,ErrorLogs}Table`.
- Idempotency: `src/Shared/Application/Idempotency/{IdempotencyStore,IdempotencyRecord,IdempotencyStatus}.php`,
  `src/Shared/Infrastructure/Persistence/PdoIdempotencyStore.php`,
  `src/Shared/Http/{IdempotencyMiddleware,IdempotencyContext,IdempotentReplayResolver}.php`.
- Audit: `src/Shared/Application/Audit/{AuditLogWriter,AuditEntry,AuditActor}.php`,
  `src/Shared/Infrastructure/Persistence/PdoAuditLogWriter.php`.
- Error log: `src/Shared/Application/ErrorLog/{ErrorLogWriter,ErrorLogEntry,ErrorLogLevel}.php`,
  `src/Shared/Infrastructure/Persistence/{PdoErrorLogWriter,NullErrorLogWriter}.php`.
- `src/Shared/Infrastructure/SecretRedactor.php` (shared redaction helper).
- `src/Jobs/PurgeExpiredIdempotencyKeys.php`, `bin/PurgeIdempotencyKeys.php`.
- `src/Bootstrap/ContainerFactory.php` (extracted from `AppFactory`; shared by HTTP + CLI).
- `.github/workflows/Ci.yml`.
- Tests: `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php`,
  `tests/Unit/Shared/Infrastructure/SecretRedactorTest.php`,
  `tests/Unit/Shared/Application/Audit/AuditEntryTest.php`,
  `tests/Unit/Shared/Application/ErrorLog/ErrorLogEntryTest.php`,
  `tests/Unit/Jobs/PurgeExpiredIdempotencyKeysTest.php`,
  `tests/Support/InMemoryIdempotencyStore.php`,
  `tests/Integration/{MigrationRoundTripTest,CrossCuttingWritersTest}.php`.
- `.claude/PhaseResults/Phase05Result.md`.

**Files modified**
- `src/Shared/Http/JsonErrorHandler.php` — now takes `ErrorLogWriter` + `CorrelationId`; logs
  unhandled (non-HTTP) exceptions to `error_logs`.
- `src/Bootstrap/AppFactory.php` — uses `ContainerFactory`.
- `src/Config/container.php` — binds `IdempotencyStore`, `AuditLogWriter`, `ErrorLogWriter` to
  their PDO adapters.
- `composer.json` — `+ rollback:all`, `db:reset`, `db:fresh`, `idempotency:purge` scripts.
- `phpstan.neon`, `.php-cs-fixer.dist.php` — analyse/lint `bin/`.
- `tests/Unit/Shared/Http/JsonErrorHandlerTest.php` — new constructor args + error-log assertion.
- DB docs: `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md` (3 new tables, total 6). `.claude/docs/Phases.md` (row 5 → ☑),
  `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`,
  `.claude/Rule.md` (## Project Documents / §7), `.claude/docs/Architecture.md`.

**DB changes.** New tables `idempotency_keys`, `audit_logs`, `error_logs`. Business tables
(timestamps). `client_id` columns are unconstrained until Phase 6 adds the `clients` FKs. No
seeders. Migrations: `up()` creates, `down()` drops.

**Verification.** `composer ci` green — 63 unit tests, 190 assertions. PHPStan `max` +
strict-rules clean (76 files). php-cs-fixer clean. `composer test:integration` → 13 tests, all
self-skip (no Docker / local MariaDB rejects `gomrok`). Migration classes load and extend
`Phinx\Migration\AbstractMigration`. Container builds and resolves the non-DB services.

**NOT verified here.** `composer db:setup` / `db:reset` / `test:integration` against real MySQL
and the `Ci.yml` run — needs Docker or GitHub Actions. Run
`docker compose up -d mysql && composer db:reset && composer test:integration`, or let CI do it
on push.

**Breaking changes.** `JsonErrorHandler::__construct` gained two required parameters (internal;
autowired via the container).

## 2026-09-08 — Phase 4: Database foundations (reference tables)

**Summary.** Migration workflow + the three reference tables. Decisions (`PhaseDecisions.md`
Phase 4 Q1–Q5): DB docs = the spec's kebab-case files + `mkdocs.yml` · namespaced Phinx
migrations, no base class · currencies from `brick/money`, countries from a bundled JSON ·
capability catalogue **deferred to Phase 8** · full ISO currencies + 18 curated countries.
**Schema confirmed by the user** before any migration.

**Files created**
- `src/Database/Migrations/20260908130001_create_currencies_table.php` (+ `..._countries_`,
  `..._provider_types_`) — `Gomrok\Database\Migrations\Create{Currencies,Countries,ProviderTypes}Table`.
- `src/Database/Seeds/{CurrenciesSeeder,CountriesSeeder,ProviderTypesSeeder}.php` +
  `src/Database/Seeds/data/countries.json` (18 rows).
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md`; root `mkdocs.yml`.
- `tests/Integration/ReferenceTablesTest.php`.
- `.claude/PhaseResults/Phase04Result.md`.

**Files modified**
- `phinx.php` — namespaced `paths` + collation. `composer.json` — `+ seed`, `db:setup` scripts;
  `exclude-from-classmap` for the migrations dir. Removed `src/Database/{Migrations,Seeds}/.gitkeep`.
- `.claude/Rule.md` §3.1 (exceptions: DB docs kebab-case, Phinx migration filenames), §3.3 note
  resolved, ## Project Documents row. `.claude/docs/Architecture.md` §4, `.claude/docs/Commands.md`,
  `.claude/docs/Phases.md` (Phase 4 scope trimmed + row → ☑; Phase 8 scope gains the capability
  catalogue), `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`.

**DB changes.** New tables `currencies`, `countries` (FK → `currencies.code`), `provider_types`.
Reference data only — no timestamps.

**Verification.** PHPStan `max` clean (incl. migrations/seeders), cs clean, `composer ci` green
(42 tests). Migration + seeder classes load and extend the correct Phinx bases; `countries.json`
validated (18 rows, every `default_currency` in `brick`'s list; brick has 166 currencies).
**Not verified:** `composer db:setup` / `migrate` / `rollback` and `ReferenceTablesTest` against
a real MySQL — no Docker daemon and the local MariaDB rejects the `gomrok` user. Run
`docker compose up -d mysql && composer db:setup && composer test:integration`.

**Migration notes.** `composer install` (autoload change), then `composer db:setup`.
**Breaking changes.** None.

## 2026-09-08 — Phase 3: Shared kernel

**Summary.** Built `src/Shared/**` — 15 classes every module will use. No business logic, no
schema. Decisions (`PhaseDecisions.md` Phase 3 Q1–Q5): richer `Money` API · plain-int IDs (Q2,
tied to the Q3-of-Phase-1 change) · **hybrid** error model (`Result`/`DomainError` returned;
exceptions for bugs/infra) · **Monolog** logger · **PSR-20** clock.

**Files created**
- `src/Shared/Domain/` — `Money.php`, `Currency.php`, `CountryCode.php`, `Result.php`,
  `DomainError.php`, `ErrorType.php`.
- `src/Shared/Infrastructure/` — `SystemClock.php`, `CorrelationId.php`,
  `Logging/{LoggerFactory,CorrelationIdProcessor}.php`, `Persistence/TransactionRunner.php`.
- `src/Shared/Http/` — `Action.php`, `JsonResponder.php`, `JsonErrorHandler.php`,
  `CorrelationIdMiddleware.php`.
- `tests/Support/FrozenClock.php` + 11 unit test files + `tests/Integration/TransactionRunnerTest.php`.
- `.claude/PhaseResults/Phase03Result.md`.

**Files modified**
- `composer.json` / `composer.lock` — `+ monolog/monolog:^3`, `psr/clock:^1`, `brick/money:^0.10`;
  pinned `brick/math:~0.12.0` (avoids a `brick/money` internal deprecation).
- `src/Config/container.php` — bind `ClockInterface`, `LoggerInterface`, `ResponseFactoryInterface`.
- `src/Bootstrap/AppFactory.php` — add `CorrelationIdMiddleware` + `JsonErrorHandler`.
- `.claude/docs/Architecture.md` §4, `.claude/docs/Phases.md` (row → ☑), `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`.

**API changes.** All error responses are now JSON (was: possibly HTML). `GET /health` gains an
`X-Correlation-Id` response header.

**Verification.** `composer ci` green (42 tests, 113 assertions); PHPStan `max` clean; cs clean;
live `/health` returns JSON + `X-Correlation-Id` (inbound header reused); `/nope` → JSON 404.
Integration test (`TransactionRunnerTest`) written, **skipped** — no Docker daemon.

**Migration notes.** `composer install` after pulling. **Breaking changes.** None.

## 2026-09-08 — Decision change: simple integer IDs (no ULID / typed IDs)

**Summary.** User reversed the Phase 1 Q3 identifier decision. Now: every table PK is
`id INT UNSIGNED AUTO_INCREMENT` (from 1, **not `BIGINT`**); FKs plain `INT UNSIGNED`; **no**
ULID / UUID / typed-ID value objects / entity-specific ID classes; the same `int` in DB, PHP,
and API/callback/admin URLs. Isolation is enforced by authorization, not by unguessable IDs.
`BIGINT` still allowed for non-key columns (money `amount_minor`).

**Files modified**
- `.claude/PhaseDecisions.md` — Phase 1 Q3 marked *changed* (Previously/Current/Changed/Reason);
  Phase 3 Q2 resolved as "no ID abstraction".
- `.claude/docs/Architecture.md` — §6 rewritten; §3 decision table; §4 folder layout; §7 Money
  note; §12 testing mention.
- `.claude/docs/Phases.md` — Phase 3 scope (drop `Ulid` / `UlidGenerator` / typed IDs).
- `.claude/Rule.md` §5 — new "Simple integer IDs" rule + client-scoped note.
- `.claude/knowledge/Knowledge.md`, `.claude/knowledge/TenantIsolation.md` — identifier sections.
- `.claude/Orders.md` — D3 superseded.
- `.claude/agents/DatabaseAgent.md` — **filled in** with the identifier rules + schema conventions
  + workflow (per the user's "add it to databaseagent.md").
- `.claude/FileIndex.md`; `.claude/PhaseResults/Phase01Result.md` + `Phase02Result.md` —
  forward-pointer / superseded markers (result files not rewritten).

**Reason.** User: "I do not want unnecessarily complex ID abstractions."
**Migration notes.** No code/schema exists yet — nothing to migrate.
**Breaking changes.** None (supersedes an unbuilt decision).

## 2026-09-08 — Removed `.claude/CLAUDE.md` pointer stub

**Summary.** Deleted the `.claude/CLAUDE.md` pointer stub (created from `struct.md`). The
project-root `CLAUDE.md` is the single entry point; a second file added only confusion.

**Files removed:** `.claude/CLAUDE.md`.
**Files modified:** `.claude/Rule.md` (§3.3 tree, "outside" note, ## Project Documents row),
`.claude/FileIndex.md` — references removed. `struct.md`'s `CLAUDE.md` entry is now noted as
covered by the root file.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-08 — Design folder moved into `.claude/docs/`

**Summary.** `Design/` (project root) → `.claude/docs/Design/`. PascalCase kept (our own sub-dir).
Contents (`GomrokAdminPanelV4.dc.html`, `GomrokAdminPanelV4Export.dc.html`, `Support.js`,
`Readme.md`) unchanged; the `.dc.html` `<script src="./Support.js">` stays correct (moved
together).

**Files modified** (references `Design/` → `.claude/docs/Design/`)
- `CLAUDE.md` (Documentation-directory note), `.claude/Rule.md` (§3.1, §3.3 tree + "outside"
  list, §3.4, ## Project Documents, §7), `.claude/FileIndex.md`, `.claude/docs/Ui.md`,
  `.claude/docs/LastAiAnswer.md`, `.claude/skills/FrontendSkill.md`,
  `.claude/docs/Design/Readme.md`.

**Migration notes.** Design bookmarks: `Design/…` → `.claude/docs/Design/…`.
**Breaking changes.** None.

## 2026-09-08 — Design: keep only v4

**Summary.** Deleted the superseded admin-panel design iterations; only v4 is kept.

**Files removed**
- `Design/GomrokAdminPanel.dc.html` (v1)
- `Design/GomrokAdminPanelV2.dc.html`
- `Design/GomrokAdminPanelV3.dc.html`

**Kept:** `Design/GomrokAdminPanelV4.dc.html` (the design), `Design/GomrokAdminPanelV4Export.dc.html`
(redundant export — same UI as v4), `Design/Support.js`, `Design/Readme.md`.

**Files modified**
- `Design/Readme.md` — file table trimmed to the kept files.

**Reason.** v1–v3 are no longer relevant; recoverable from git history if needed.
**Migration notes.** None. **Breaking changes.** None.

## 2026-09-07 — `.claude/` structure completed from `struct.md`

**Summary.** Built out the full `.claude/` tree described in `.claude/struct.md`, keeping every
existing file and its content untouched. `struct.md`'s `SCREAMING_CASE`/`kebab-case` names mapped
to PascalCase per `.claude/Rule.md` §3.1.

**Files created (51)**
- `.claude/CLAUDE.md` (pointer stub), `.claude/Orders.md` (requirements/decisions register).
- `.claude/agents/` — 10 `<Role>Agent.md` templates (Backend, Database, Deployment, Discovery,
  Docs, Frontend, Qa, Review, Security, Testing).
- `.claude/commands/` — `Implement.md Plan.md Refactor.md Review.md Spec.md`; `workflow/` (same 5,
  multi-agent variants); `phases/` (`Phase00Foundation`, `Phase01ProjectDiscovery`,
  `Phase02RepositoryBootstrap`, `PhaseTemplate`, `Readme`).
- `.claude/docs/` — `ProjectDescription Domain Permissions Ui Recommendations Deployment Server
  FeatureTemplate`.
- `.claude/knowledge/` — `SecurityRules TenantIsolation RolePermissionModel DeploymentRunbook
  DnsRecords LocalAssets MediaStorage PolicyTemplate`.
- `.claude/skills/` — `BackendSkill DatabaseSkill DeploymentSkill FrontendSkill GitSkill
  SecuritySkill TestingSkill SkillTemplate`.

**Files modified (additive only)**
- `.claude/Rule.md` — §3.3 tree + naming notes; ## Project Documents rows for the new families.
- `.claude/FileIndex.md` — new entries.

**Files preserved (unchanged):** every pre-existing `.claude/` file — `Rule.md` content,
`Changelog.md`, `PhaseDecisions.md`, `FileIndex.md`, `docs/*`, `knowledge/Knowledge.md`,
`{agents,commands,skills}/Readme.md`, `PhaseResults/*`, and the project-root `CLAUDE.md`.

**Notes.** The agent files carry valid frontmatter and the command files carry `description`
frontmatter, so Claude Code will now surface ~10 subagents and ~15 slash commands — **all marked
TEMPLATE**. Deployment/Server/DNS/media/runbook files are deliberate empty placeholders (no
infrastructure decided). Nothing outside `.claude/` was touched (no source, tests, DB, Docker,
Composer).

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-07 — `PhaseResults/` moved into `.claude/`

**Summary.** `PhaseResults/` (project root) → `.claude/PhaseResults/`. Name stays PascalCase
(our own dir, not a Claude Code tool folder). Contents unchanged.

**Files modified** (references repointed `PhaseResults/` → `.claude/PhaseResults/`)
- `CLAUDE.md` (Phase Completion Rule, Documentation-directory note).
- `.claude/Rule.md` (§3.3 layout + text, §3.4, §7, ## Project Documents, §4.1).
- `.claude/FileIndex.md`, `.claude/PhaseDecisions.md`, `.claude/docs/{Architecture,Phases}.md`,
  `.claude/PhaseResults/{Readme,Template,Phase01Result}.md`.

**Migration notes.** `PhaseResults/PhaseNNResult.md` → `.claude/PhaseResults/PhaseNNResult.md`.
**Breaking changes.** None.

## 2026-09-07 — Documentation reorganised into `.claude/`

**Summary.** Adopted the standard Claude Code project layout. `Documents/` retired; all docs now
live under `.claude/` (`Rule.md`, `Changelog.md`, `PhaseDecisions.md`, `FileIndex.md` at the
root; `docs/`, `knowledge/`, plus empty `agents/`, `commands/`, `skills/` skeletons). Folder
skeleton adopted, existing docs adapted into it, PascalCase file names kept, `CLAUDE.md` left at
the project root, phase-tracking artefacts kept.

**Files moved** (`Documents/` → `.claude/`)
- `Rule.md`, `Changelog.md`, `PhaseDecisions.md` → `.claude/`
- `Architecture.md`, `Commands.md`, `Phases.md`, `LastAiAnswer.md`, `ClaudeOld.md` → `.claude/docs/`
- `Knowledge.md` → `.claude/knowledge/`
- `Documents/` directory removed.

**Files created**
- `.claude/FileIndex.md` — repo-wide file map.
- `.claude/{agents,commands,skills}/Readme.md` — skeleton placeholders.

**Files modified** (references repointed to `.claude/…`)
- `CLAUDE.md` — "Documentation directory" note; naming-rule pointer.
- `.claude/Rule.md` — §3.1 (`.claude/` sub-dir naming), §3.3 rewritten around the `.claude/`
  layout, §3.4, §7, ## Project Documents (+ `FileIndex.md`, skeletons).
- `.claude/docs/{Architecture,Phases}.md`, `.claude/PhaseDecisions.md`, `.claude/knowledge/Knowledge.md`,
  `PhaseResults/{Readme,Phase01Result,Phase02Result}.md`, `Design/Readme.md`.

**Reason.** Match the conventional Claude Code structure (native `agents/`/`commands/`/`skills/`)
and keep the project root clean.

**Migration notes.** Doc bookmarks change: `Documents/Phases.md` → `.claude/docs/Phases.md`, etc.
**Breaking changes.** None (no code references these paths).

## 2026-09-06 — Phase 2: project scaffold & toolchain

**Summary.** A bootable, testable, empty Slim 4 app. No business logic, no database tables.
Decisions: Docker Compose · PHP 8.4 · Phinx · PHPUnit 11 · PHPStan max + strict-rules +
php-cs-fixer PSR-12 · keep `src/Bootstrap` + `src/Http` as app-level dirs
(`Documents/PhaseDecisions.md` Phase 2 Q1–Q6).

**Files created**
- Root: `composer.json` (+ `composer.lock`), `.gitignore`, `.env.example`, `Dockerfile`,
  `docker-compose.yml`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `phinx.php`.
- `src/Config/{Settings.php, DatabaseSettings.php, container.php, routes.php}`,
  `src/Bootstrap/AppFactory.php`, `src/Http/HealthAction.php`, `src/Public/index.php`,
  `src/Database/{Migrations,Seeds}/.gitkeep`.
- `tests/Unit/SmokeTest.php`, `tests/Unit/Config/SettingsTest.php`,
  `tests/Unit/Http/HealthActionTest.php`, `tests/Integration/DatabaseConnectionTest.php`.
- `Documents/Commands.md`.
- `PhaseResults/Phase02Result.md`.

**Files modified**
- `Documents/Architecture.md` — §4 folder layout (adds `src/Bootstrap/`, `src/Http/`, splits
  `Database/`, notes lowercase config files).
- `Documents/Rule.md` — §3.1 exceptions (`Dockerfile`, `phpstan.neon`, `phinx.php`,
  `.php-cs-fixer.dist.php`, non-class config files); ## Project Documents (+ `Commands.md`).
- `Documents/PhaseDecisions.md`, `Documents/Phases.md` (Phase 2 row → ☑; stray `f` typo on the
  "Column meanings" line removed).

**Verification.** `composer test` OK (4/16); `composer stan` [OK] level max; `composer cs` clean;
`GET /health` → 200 `{"status":"ok","service":"gomrok"}`; `/nope` → 404. Integration test written
but **skipped** — no Docker daemon / no `gomrok` MySQL user in this environment.

**Migration notes.** Run `cp .env.example .env && composer install`. **Breaking changes.** None.

## 2026-09-06 — Phases.md: status table moved to the top

**Summary.** In `Documents/Phases.md`, the `## Status & execution tracking` section (column
meanings + the 30-phase table) was moved to the very top, immediately after the H1 and before
the intro / *How each phase runs* / *Database strategy* / the detailed phase sections. Table
content and phase data unchanged (row-by-row verified identical).

**Files modified**
- `Documents/Phases.md` — section reordered; one consequential wording fix: step 6 of *How each
  phase runs* now says "the *Status & execution tracking* table (top of file)" instead of "the
  table below".

**Reason.** So the current phase status is visible immediately on opening the file.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Project-document registry in Rule.md

**Summary.** Added a **## Project Documents** section to `Documents/Rule.md` — a table of every
documentation file (name, path, purpose) — plus **§3.5** requiring it to be kept in sync
automatically whenever a doc file is created / renamed / moved / removed.

**Files modified**
- `Documents/Rule.md` — new `## Project Documents` table (13 rows) + `### 3.5 Project-document
  registry` rule; §7 "Documents kept current" row for `Rule.md` extended.
- `CLAUDE.md` — "Documentation directory" note now points at the registry.

**Reason.** Single place to see what every doc file is for; keeps the doc set discoverable.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Documentation moved under `Documents/`

**Summary.** All project documentation moved out of the project root into a new root-level
`Documents/` directory, and this made a permanent rule in `Documents/Rule.md` §3.3.

**Files moved** (project root → `Documents/`)
- `Architecture.md`, `Changelog.md`, `ClaudeOld.md`, `Knowledge.md`, `LastAiAnswer.md`,
  `PhaseDecisions.md`, `Phases.md`, `Rule.md`

**Left at the project root (intentional)**
- `CLAUDE.md` — the harness auto-loads `./CLAUDE.md`; moving it breaks that. It now points into
  `Documents/` for everything else.
- `PhaseResults/`, `Design/` — special-purpose directories, not documentation; unchanged.
- `.claude/docs/*` — the DB-docs location is a Phase 4 decision; unchanged for now.

**Files modified** (references updated to `Documents/…`)
- `CLAUDE.md` — every doc reference; new "Documentation directory" note; `LastAiAnswer.md` rule
  path; "Project Rules File" section.
- `Documents/Rule.md` — new **§3.3 Documentation directory** rule; §3.4 (was §3.3) paths;
  §1/§4/§7 references; §3.1 naming examples clarified.
- `Documents/Phases.md`, `Documents/PhaseDecisions.md`, `Documents/Architecture.md` — internal
  references.
- `Design/Readme.md`, `PhaseResults/Readme.md`, `PhaseResults/Phase01Result.md` — references
  (Phase01Result also carries a dated relocation note).

**Reason.** Keep the project root clean; make documentation location a permanent, enforced
convention.

**Migration notes.** Any external bookmark to a root-level doc path (e.g. `Phases.md`) is now
`Documents/Phases.md`. **Breaking changes.** None (no code depends on these paths yet).

## 2026-09-06 — Naming compliance: Changelog.md / Knowledge.md

**Summary.** Renamed two docs that had been created in all-caps to PascalCase per `Rule.md` §3.1.

**Files renamed**
- `CHANGELOG.md` → `Changelog.md`
- `KNOWLEDGE.md` → `Knowledge.md`

**Files modified** (references updated)
- `CLAUDE.md` — Changelog Rule + Phase Completion Rule + Expected Claude Behavior.
- `Rule.md` — §3.1 gained an explicit "all-caps community filenames are still PascalCased" bullet
  (`Changelog.md`, `Knowledge.md`, `Readme.md`, …); §4 and §7 references.
- `Phases.md` — *How each phase runs* step 5, Phase 1 + Phase 2 scope.
- `PhaseResults/Phase01Result.md` — recorded file paths corrected (error fix).

**Reason.** `CHANGELOG.md` / `KNOWLEDGE.md` are community conventions, not tool-mandated names, so
the PascalCase rule applies. `Rule.md` now says so explicitly to prevent recurrence.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Phase 1: architecture baseline

**Summary.** Established the target architecture from first principles (greenfield — no
predecessor system). No application code, no database.

**Files created**
- `Architecture.md` — hexagonal + modular architecture, module map, folder layout, cross-module
  communication (published interfaces + in-process domain events), identifier strategy
  (BIGINT PK + public ULID), money representation (`brick/money` + `Money` VO), provider-adapter
  model (core `PaymentProviderPort` + optional capability interfaces + `ProviderCapabilities`),
  resolution-pipeline sketches, payment lifecycle, cross-cutting concerns, testing approach,
  deferred items.
- `Changelog.md`, `Knowledge.md` — seeded (initially created as `CHANGELOG.md` / `KNOWLEDGE.md`;
  see the naming-compliance entry above).
- `PhaseResults/Phase01Result.md` — Phase 1 record.

**Files modified**
- `Phases.md` — Phase 1 tracking row filled (status ☑, start/end datetime, actual duration).

**Reason.** Lock the structural decisions every later phase depends on, via the 5 interactive
questions.

**Migration notes.** None. **Breaking changes.** None.
