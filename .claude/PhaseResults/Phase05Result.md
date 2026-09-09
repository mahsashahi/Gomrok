# Phase 05 — Migration workflow & cross-cutting tables

## Execution Summary

- Phase: 05 — Migration workflow & cross-cutting tables
- Start Datetime: 2026-09-08 13:35
- End Datetime: 2026-09-08 15:04
- Estimated Duration: 2–4h
- Actual Duration: 1h 29m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; migration execution against real MySQL runs in CI, not
  in this environment)

## Work Completed

- Designed and (after user confirmation) created three cross-cutting tables — `idempotency_keys`,
  `audit_logs`, `error_logs` — as namespaced Phinx `up()`/`down()` migrations.
- Built the idempotency slice: `IdempotencyStore` port, `PdoIdempotencyStore` adapter (claim /
  lock / reset-expired-or-failed / mark-completed / mark-failed / purge-expired),
  `IdempotencyMiddleware` (per-route, not global), `IdempotencyContext` (handler → middleware
  target hand-off), `IdempotentReplayResolver` (no implementation yet).
- Built the audit slice: `AuditLogWriter` port, `AuditEntry` (`for*` factories + `with*`
  immutable copies), `AuditActor` enum, `PdoAuditLogWriter` (full before/after JSON, redacted).
- Built the error-log slice: `ErrorLogWriter` port, `ErrorLogEntry` (+ `fromThrowable`),
  `ErrorLogLevel` enum, `PdoErrorLogWriter` (swallows its own failures), `NullErrorLogWriter`.
- Shared `SecretRedactor` (recursive, case-insensitive key denylist).
- Wired `ErrorLogWriter` + `CorrelationId` into `JsonErrorHandler` — unhandled non-HTTP
  exceptions now land in `error_logs` with `source = http`.
- `PurgeExpiredIdempotencyKeys` job + `bin/PurgeIdempotencyKeys.php` CLI +
  `composer idempotency:purge`.
- Extracted `ContainerFactory` from `AppFactory` so HTTP and CLI wire dependencies identically.
- Migration-workflow hardening: `composer rollback:all` / `db:reset` / `db:fresh`;
  `MigrationRoundTripTest` (down-to-empty then back up, re-seed in tearDown);
  `.github/workflows/Ci.yml` running `composer ci` then `composer db:setup` +
  `composer test:integration` against a `mysql:8.4` service container.
- Registered the three ports → PDO adapters in `src/Config/container.php`.
- Added `bin/` to PHPStan `paths` and the php-cs-fixer finder.
- Updated all four DB docs, Architecture §11, Changelog, Phases (row 5), FileIndex, Knowledge,
  Commands, Orders (D8).

## Files Created

- `src/Database/Migrations/20260908140001_create_idempotency_keys_table.php` — `CreateIdempotencyKeysTable`.
- `src/Database/Migrations/20260908140002_create_audit_logs_table.php` — `CreateAuditLogsTable`.
- `src/Database/Migrations/20260908140003_create_error_logs_table.php` — `CreateErrorLogsTable`.
- `src/Shared/Application/Idempotency/IdempotencyStore.php` — port.
- `src/Shared/Application/Idempotency/IdempotencyRecord.php` — value object returned by `claim()`.
- `src/Shared/Application/Idempotency/IdempotencyStatus.php` — enum `processing|done|failed`.
- `src/Shared/Application/Audit/AuditLogWriter.php` — port.
- `src/Shared/Application/Audit/AuditEntry.php` — audit row builder.
- `src/Shared/Application/Audit/AuditActor.php` — enum `admin_user|client|system`.
- `src/Shared/Application/ErrorLog/ErrorLogWriter.php` — port.
- `src/Shared/Application/ErrorLog/ErrorLogEntry.php` — error row + `fromThrowable()`.
- `src/Shared/Application/ErrorLog/ErrorLogLevel.php` — enum `error|critical`.
- `src/Shared/Infrastructure/SecretRedactor.php` — recursive key-based redaction.
- `src/Shared/Infrastructure/Persistence/PdoIdempotencyStore.php` — MySQL adapter (uses `TransactionRunner`).
- `src/Shared/Infrastructure/Persistence/PdoAuditLogWriter.php` — MySQL adapter.
- `src/Shared/Infrastructure/Persistence/PdoErrorLogWriter.php` — MySQL adapter, failure-swallowing.
- `src/Shared/Infrastructure/Persistence/NullErrorLogWriter.php` — no-op adapter.
- `src/Shared/Http/IdempotencyMiddleware.php` — PSR-15 middleware (registered per-route in Phase 7).
- `src/Shared/Http/IdempotencyContext.php` — per-request created-entity slot.
- `src/Shared/Http/IdempotentReplayResolver.php` — interface, no implementation this phase.
- `src/Bootstrap/ContainerFactory.php` — builds the PHP-DI container.
- `src/Jobs/PurgeExpiredIdempotencyKeys.php` — invokable purge job.
- `bin/PurgeIdempotencyKeys.php` — CLI entrypoint.
- `.github/workflows/Ci.yml` — GitHub Actions CI.
- `tests/Support/InMemoryIdempotencyStore.php` — test double with the adapter's semantics.
- `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php` — 11 tests.
- `tests/Unit/Shared/Infrastructure/SecretRedactorTest.php` — 3 tests.
- `tests/Unit/Shared/Application/Audit/AuditEntryTest.php` — 4 tests.
- `tests/Unit/Shared/Application/ErrorLog/ErrorLogEntryTest.php` — 2 tests.
- `tests/Unit/Jobs/PurgeExpiredIdempotencyKeysTest.php` — 1 test.
- `tests/Integration/MigrationRoundTripTest.php` — 2 tests (self-skip w/o MySQL).
- `tests/Integration/CrossCuttingWritersTest.php` — 4 tests (self-skip w/o MySQL).
- `.claude/PhaseResults/Phase05Result.md` — this file.

## Files Modified

- `src/Shared/Http/JsonErrorHandler.php` — constructor gained `ErrorLogWriter` + `CorrelationId`;
  logs unhandled non-HTTP exceptions to `error_logs`.
- `src/Bootstrap/AppFactory.php` — delegates container build to `ContainerFactory`.
- `src/Config/container.php` — binds `IdempotencyStore` → `PdoIdempotencyStore`,
  `AuditLogWriter` → `PdoAuditLogWriter`, `ErrorLogWriter` → `PdoErrorLogWriter`.
- `composer.json` — `rollback:all`, `db:reset`, `db:fresh`, `idempotency:purge` scripts + descriptions.
- `phpstan.neon` — `bin` added to `paths`.
- `.php-cs-fixer.dist.php` — `bin` added to the finder.
- `tests/Unit/Shared/Http/JsonErrorHandlerTest.php` — updated for the new constructor; asserts an
  error-log entry is written for a 500 and none for a Slim `HttpException`.
- `.claude/docs/database-design.md`, `database-diagram.md`, `database-diagram.html`,
  `db_explain.md` — 3 new tables, total 6.
- `.claude/docs/Architecture.md` §11 — Idempotency / Audit log / Error log / Background jobs rows.
- `.claude/docs/Phases.md` — Phase 5 tracking row → ☑; scope/exit rewritten to match.
- `.claude/docs/Commands.md` — DB reset/fresh, background-jobs, CI sections.
- `.claude/Changelog.md`, `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`,
  `.claude/Orders.md` (D8), `.claude/PhaseDecisions.md` (Phase 5 Q1–Q5).

## Implementation Details

- **`PdoIdempotencyStore::claim()`** runs inside `TransactionRunner::run()`; it `SELECT … FOR
  UPDATE`s any existing row. Expired or `failed` rows are reset to `processing` in place and
  reported as a fresh claim (`null`). A lost `INSERT` race (`SQLSTATE 1062`) is caught and
  resolved by re-reading the row. Scalar coercion helpers (`str` / `nullableStr`) keep the PDO
  boundary type-clean without inline `@var`.
- **`IdempotencyMiddleware`** engages only for `POST/PUT/PATCH/DELETE` + an `Idempotency-Key`
  header + an `authClientId` request attribute; otherwise it is a pass-through. `done` replay
  uses the `IdempotentReplayResolver` if present, else emits `{idempotent_replay, target_type,
  target_id}` with header `Idempotent-Replayed: true` and the stored `response_status`.
  Malformed key format → 400; concurrent `processing` → 409; same key + different request
  fingerprint → 422.
- **`AuditEntry`** is `final readonly` with `forAdminUser` / `forClient` / `forSystem` factories
  and `withTarget` / `withChange` / `withContext` / `withRequest` copy-withers.
- **`PdoAuditLogWriter`** does *not* swallow failures (a lost audit trail must surface);
  **`PdoErrorLogWriter`** does (logging must not mask the original error).
- **`SecretRedactor`** matches keys against
  `secret|password|passwd|pwd|token|api_key|private_key|client_secret|authorization|signature|webhook_secret`
  (case-insensitive), recursively, replacing values with `[redacted]`.

## Database Changes

Three new tables (business tables, timestamps). Full column/index lists in
`.claude/docs/database-design.md`.

- `idempotency_keys` — `uniq_idempotency_keys_client_key (client_id, idempotency_key)`,
  `idx_idempotency_keys_expires_at`.
- `audit_logs` — `idx_audit_logs_client_created`, `idx_audit_logs_target`, `idx_audit_logs_action`.
- `error_logs` — `idx_error_logs_created`, `idx_error_logs_client`, `idx_error_logs_unresolved`.

No foreign keys — `client_id` columns are unconstrained until Phase 6 adds the `clients` FKs.
No seeders. `down()` on each is a plain `drop`.

## API Changes

No new endpoints. `IdempotencyMiddleware` exists but is not attached to any route yet (Phase 7).
Internal: `JsonErrorHandler::__construct` gained two required parameters (autowired).

## Tests and Validation

- Tests created: `IdempotencyMiddlewareTest` (11), `SecretRedactorTest` (3), `AuditEntryTest`
  (4), `ErrorLogEntryTest` (2), `PurgeExpiredIdempotencyKeysTest` (1), `MigrationRoundTripTest`
  (2, integration), `CrossCuttingWritersTest` (4, integration); support double
  `InMemoryIdempotencyStore`.
- Tests modified: `JsonErrorHandlerTest` (new constructor + error-log assertions).
- Commands run:
  - `composer cs` → `Found 0 of 77 files that can be fixed` (clean).
  - `composer stan` → `[OK] No errors` (76 files, level `max` + strict-rules + phpunit).
  - `composer test` → `OK (63 tests, 190 assertions)`.
  - `composer test:integration` → `OK, but some tests were skipped! Tests: 13, ... Skipped: 13`
    (no Docker / local MariaDB rejects `gomrok`).
  - `composer ci` → green (exit 0).
  - Migration classes: verified they load (direct `require`, dir is `exclude-from-classmap`) and
    extend `Phinx\Migration\AbstractMigration`; `ContainerFactory::create()` builds and resolves
    `ClockInterface`, `LoggerInterface`.

## Technical Decisions

`PhaseDecisions.md` Phase 5 Q1–Q5:

1. Idempotency storage = **lock + entity mapping** (no stored response bodies).
2. Audit content = **event + full before/after row snapshots** (user chose over the recommended
   changed-columns diff).
3. Error-log capture = **explicit writer only** (no Monolog DB handler).
4. Idempotency retention = **`expires_at` (24h) + purge job**.
5. Migration hardening = **round-trip test + `db:reset` + GitHub Actions CI**.

Additional in-phase choices: `PdoIdempotencyStore` delegates transactions to the existing
`TransactionRunner` (also sidesteps a PHPStan false "dead branch" on inline `inTransaction()`
bookkeeping); `ContainerFactory` extracted rather than duplicating container-build code in the
CLI entrypoint.

## Problems Encountered

- PHPStan `booleanNot.alwaysTrue` on hand-rolled transaction bookkeeping in `PdoIdempotencyStore`
  (PHPStan assumes an injected `PDO` is not mid-transaction).
- PHPStan `cast.string` / `offsetAccess.nonOffsetAccessible` on `PDOStatement::fetch()` results
  and nested redactor output.
- PHPStan `staticMethod.impossibleType` — `assertNotNull()` on a method-call expression that had
  been narrowed to `null` by an identical `assertNull()` call on the line above.
- PHPUnit: a header value containing `\n` cannot be set via PSR-7 (`InvalidArgumentException`).
- PHPUnit: `tearDown()` ran after `markTestSkipped()` in `setUp()` and touched uninitialised
  typed properties.

## Resolutions

- Wrapped `claim()` in `TransactionRunner::run()` — removed the manual bookkeeping entirely.
- Added `PdoIdempotencyStore::str()` / `nullableStr()` scalar coercers; used local vars +
  `assertIsArray` / `assertIsString` in tests instead of inline `@var` or bare casts.
- Captured method-call results into local variables before asserting; used `assertInstanceOf`
  for the "record returned" checks.
- Tested the malformed-key path with `'has spaces and tabs'` (a legal header, rejected by the
  middleware's `\x21-\x7e` check).
- Guarded both integration `tearDown()`s with `isset($this->…)`.

## Deferred Work

- Attaching `IdempotencyMiddleware` to write routes and providing an `IdempotentReplayResolver` —
  **Phase 7** (client API auth & scoping); the `authClientId` request attribute is set there.
- `fk_{idempotency_keys,audit_logs,error_logs}_client_id` → `clients(id)` — **Phase 6** (Clients).
- Provider / webhook / notification / job `ErrorLogWriter` call sites — their own phases.
- Scheduling `PurgeExpiredIdempotencyKeys` — the background-job runner phase (29); cron until then.
- Actual execution of the migrations + integration suite against MySQL — GitHub Actions, or the
  user running `docker compose up -d mysql && composer db:reset && composer test:integration`.

## Final Result

Six tables designed and migratable (3 reference from Phase 4 + 3 cross-cutting). The idempotency,
audit, and error-log ports exist with MySQL adapters and unit coverage; `JsonErrorHandler` feeds
`error_logs`. A CI workflow will execute the migrations and integration suite on every push.
`composer ci` is green, PHPStan `max` clean, 63 unit tests passing. Nothing is wired to an HTTP
route yet — that starts in Phase 6 (Clients) and Phase 7 (client API auth).

Next recommended phase: **Phase 6 — Clients module: domain & persistence.**
