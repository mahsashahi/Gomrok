# FileIndex.md — Gomrok

A map of the files worth knowing about. Keep in sync when files are added / moved / removed
(`.claude/Rule.md` §7).

## Governance & docs (`.claude/`)

| Path | What |
|---|---|
| `CLAUDE.md` (project root) | Entry-point instructions / detailed project spec. |
| `.claude/Rule.md` | Consolidated standing-rules catalogue. |
| `.claude/Changelog.md` | Chronological change log (newest first). |
| `.claude/PhaseDecisions.md` | Every phase decision question, options, and selection. |
| `.claude/FileIndex.md` | This file. |
| `.claude/docs/Architecture.md` | Phase-1 architecture baseline. |
| `.claude/docs/Phases.md` | 30-phase plan + Status & execution tracking table (at the top). |
| `.claude/docs/Commands.md` | Everyday commands. |
| `.claude/docs/LastAiAnswer.md` | Single-slot buffer: most recent substantive answer. |
| `.claude/docs/ClaudeOld.md` | Superseded spec archive — do not follow. |
| `.claude/Orders.md` | Requirements & decisions register (index over `PhaseDecisions.md`). |
| `.claude/struct.md` | The user's target `.claude/` structure spec (do not edit). |
| `.claude/PhaseResults/PhaseNNResult.md` `+ {Readme,Template}.md` | Per-phase records + convention/template. |
| `.claude/knowledge/Knowledge.md` | Durable domain knowledge / gotchas. |
| `.claude/knowledge/{SecurityRules,TenantIsolation,RolePermissionModel}.md` | Policy statements pinning the matching `CLAUDE.md` sections. |
| `.claude/knowledge/{DeploymentRunbook,DnsRecords,LocalAssets,MediaStorage}.md` | Placeholders — filled when real infra exists. |
| `.claude/docs/{ProjectDescription,Domain,Permissions,Ui}.md` | Thin reference docs pointing at `CLAUDE.md` / `Architecture.md` / `.claude/docs/Design/`. |
| `.claude/docs/{Deployment,Server}.md` | Placeholders — no infra chosen yet (Phase 30). |
| `.claude/docs/Recommendations.md` | Cross-phase rollup of open follow-ups. |
| `.claude/agents/<Role>Agent.md` | 10 subagent definition templates (`BackendAgent`, `ReviewAgent`, …). |
| `.claude/commands/<Command>.md` (+ `phases/`, `workflow/`) | Slash-command templates: `/Implement /Plan /Refactor /Review /Spec`, `/workflow:*`, `/phases:*`. |
| `.claude/skills/<Topic>Skill.md` | How-to guide templates (`BackendSkill`, `TestingSkill`, …). |
| `.claude/**/{FeatureTemplate,PolicyTemplate,SkillTemplate,PhaseTemplate}.md` | Copy-me templates for `struct.md`'s `[…]` placeholders. |
| `.claude/{agents,commands,skills,commands/phases}/Readme.md` | What each folder is for. |
| `.claude/docs/Design/` | Admin-panel design export — `GomrokAdminPanelV4.dc.html` (+ export variant, `Support.js`); see `.claude/docs/Design/Readme.md`. |

## Source (`src/`, PSR-4 `Gomrok\`)

| Path | What |
|---|---|
| `src/Public/index.php` | Front controller (web root). |
| `src/Bootstrap/ContainerFactory.php` | Builds the PHP-DI container (shared by HTTP + CLI). |
| `src/Bootstrap/AppFactory.php` | Composition root — builds the Slim app on `ContainerFactory`. |
| `src/Config/Settings.php`, `DatabaseSettings.php` | Env-driven immutable settings. |
| `src/Config/container.php` | PHP-DI definitions. |
| `src/Config/routes.php` | Route registration. |
| `src/Http/HealthAction.php` | `GET /health` — public, no auth, no I/O. |
| `src/Http/Api/MeAction.php` | `GET /api/v1/me` — echoes the authenticated client (Phase 7). |
| `src/Database/Migrations/` | Namespaced Phinx migrations (`Gomrok\Database\Migrations\`, timestamped files). Phase 4: currencies, countries, provider_types. Phase 5: idempotency_keys, audit_logs, error_logs. |
| `src/Database/Seeds/` | Phinx seeders + `data/countries.json`. `ClientsSeeder` (env-gated dev client). |
| `.claude/docs/database-design.md` etc. | The schema docs (design / diagram / +`.html` / `db_explain`) + root `mkdocs.yml`. Kebab-case (`Rule.md` §3.1 exception). |
| `src/Modules/Clients/` | **Clients module**: `Domain/` (`Client` aggregate, `ClientApiKey`, `ClientEndpoint`, `ClientSlug`, `ApiKeyToken`, `AuthFailureReason`, repository ports, `Events/`), `Application/` (8 use-case handlers, `ClientDirectory` / `ClientSnapshot`, `Authenticate/` — `ApiKeyAuthenticator` + `AuthAttempt` + `AuthAttemptLog`), `Infrastructure/` (`Pdo*` adapters incl. `PdoAuthAttemptLog`, `RandomApiKeyGenerator`, `definitions.php`). |
| `src/Modules/<Name>/` | Further business modules (per phase from Phase 8 on). |
| `src/Shared/Domain/` | `Money` (brick/money), `Currency`, `CountryCode`, `Result` + `DomainError` + `ErrorType`, `DomainEvent` marker. IDs are plain `int` — no ID types. |
| `src/Shared/Application/` | Cross-module ports: `TokenGenerator`, `ReferenceCatalog`, `Transactions`; `Idempotency/` (`IdempotencyStore` …), `Audit/` (`AuditLogWriter`, `AuditEntry`, `AuditActor`), `ErrorLog/` (`ErrorLogWriter`, `ErrorLogEntry`, `ErrorLogLevel`). |
| `src/Shared/Infrastructure/` | `SystemClock` (PSR-20), `CorrelationId`, `SecretRedactor`, `RandomTokenGenerator`, `Logging/` (Monolog JSON), `Persistence/` — `TransactionRunner`, `Row`, `Pdo{IdempotencyStore,AuditLogWriter,ErrorLogWriter,ReferenceCatalog}`, `NullErrorLogWriter`. |
| `src/Shared/Http/` | `Action` base, `JsonResponder` (+ RFC-7807 `problem()`), `JsonErrorHandler`, `CorrelationIdMiddleware`, `IdempotencyMiddleware` (+ `IdempotencyContext`, `IdempotentReplayResolver`), `AuthenticationMiddleware` (+ `ClientAuthenticator` port, `AuthResult`, `AuthenticatedClient`, `AuthRequestMeta`, `ClientContext`). |
| `tests/Support/` | `FrozenClock`, `InMemoryIdempotencyStore`, `SynchronousTransactions`, `InMemoryClient{,ApiKey}Repository`, `InMemoryClientDirectory`, `FixedTokenGenerator`, `InMemoryReferenceCatalog`, `RecordingAuditLogWriter`, `StubClientAuthenticator`, `RecordingAuthAttemptLog`. |
| `src/Jobs/` | `PurgeExpiredIdempotencyKeys` (Phase 5). Queue worker itself: Phase 29. |
| `bin/PurgeIdempotencyKeys.php` | CLI entrypoint for the purge job (`composer idempotency:purge`). |
| `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php` | Client onboarding CLI (`composer client:*`) — Phase 6, interim until the admin panel. |

## Tests (`tests/`, PSR-4 `Gomrok\Tests\`)

| Path | What |
|---|---|
| `tests/Unit/` | Domain + application tests, no DB / network. |
| `tests/Integration/` | Adapter / repository / provider-sandbox tests (need MySQL). |

## Config & toolchain (project root)

| Path | What |
|---|---|
| `composer.json` / `composer.lock` | Dependencies + scripts (`test`, `stan`, `cs`, `ci`, `migrate`, …). |
| `phpunit.xml` | Test suites (`unit`, `integration`). |
| `phpstan.neon` | Static analysis — level `max` + strict rules. |
| `.php-cs-fixer.dist.php` | Code style — `@PSR12` + extras. |
| `phinx.php` | Migration runner config. |
| `.github/workflows/Ci.yml` | GitHub Actions — `composer ci` + migrations/integration against a `mysql:8.4` service. |
| `Dockerfile`, `docker-compose.yml` | PHP 8.4 + MySQL 8.4 dev stack. |
| `.env.example` | Copy to `.env`. |
