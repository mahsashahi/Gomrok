# Phase 07 — Client API authentication & scoping

## Execution Summary

- Phase: 07 — Client API authentication & scoping
- Start Datetime: 2026-09-08 16:56
- End Datetime: 2026-09-08 17:49
- Estimated Duration: 3–5h
- Actual Duration: 53m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; the one migration + persistence tests run in CI)

## Work Completed

- Introduced Gomrok's first API surface: the `/api/v1` route group behind Bearer API-key
  authentication, plus `GET /api/v1/me`. `GET /health` stays public (outside the group, no I/O,
  returns only `{"status":"ok","service":"gomrok"}`).
- `ClientAuthenticator` port in `Shared\Http` + `ApiKeyAuthenticator` in the Clients module:
  parse the bearer token, point-read the key by `key_id`, constant-time secret compare, check
  key status/expiry then client status, throttle `last_used_at`, record the attempt.
- `AuthenticationMiddleware` (Shared\Http) on the group — populates a per-request `ClientContext`
  holder and the `authClient` / `authClientId` / `authKeyMode` request attributes; returns
  `401 unauthorized` for any credential fault (with `WWW-Authenticate: Bearer realm="gomrok"`)
  and `403 client_disabled` for a valid key on a disabled client, both with generic bodies.
- `IdempotencyMiddleware` gained a `requireKeyOnWrites` flag and is attached to the group so a
  `/api/v1` write with no `Idempotency-Key` gets `400 idempotency_key_required`.
- New table `client_auth_attempts` + `AuthAttemptLog` port + `PdoAuthAttemptLog` — one
  append-only row per auth attempt (success and failure), best-effort.
- `ClientApiKeyRepository::touchLastUsed()`; `AppFactory::create()` now takes an optional
  container so a functional test can boot the real Slim stack with the DB adapters stubbed.

## Files Created

- `src/Database/Migrations/20260908170001_create_client_auth_attempts_table.php` — `CreateClientAuthAttemptsTable`.
- `src/Shared/Http/ClientAuthenticator.php` (port), `AuthResult.php`, `AuthenticatedClient.php`,
  `AuthRequestMeta.php`, `ClientContext.php`, `AuthenticationMiddleware.php`.
- `src/Modules/Clients/Domain/AuthFailureReason.php`.
- `src/Modules/Clients/Application/Authenticate/{ApiKeyAuthenticator,AuthAttempt,AuthAttemptLog}.php`.
- `src/Modules/Clients/Infrastructure/PdoAuthAttemptLog.php`.
- `src/Http/Api/MeAction.php`.
- `tests/Unit/Shared/Http/{ClientContextTest,AuthenticationMiddlewareTest}.php`,
  `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php`,
  `tests/Unit/Http/{MeActionTest,ApiRoutingTest}.php`,
  `tests/Integration/AuthAttemptsPersistenceTest.php`,
  `tests/Support/{StubClientAuthenticator,RecordingAuthAttemptLog,InMemoryClientDirectory}.php`.
- `.claude/PhaseResults/Phase07Result.md`.

## Files Modified

- `src/Config/routes.php` — `/api/v1` group + middleware; `/health` left public.
- `src/Config/container.php` — `IdempotencyMiddleware` autowired with `requireKeyOnWrites: true`,
  `replayResolver: null`.
- `src/Shared/Http/IdempotencyMiddleware.php` — `requireKeyOnWrites` constructor flag; a keyless
  write returns `400 idempotency_key_required` when set.
- `src/Bootstrap/AppFactory.php` — `create(?ContainerInterface $container = null)`.
- `src/Modules/Clients/Domain/ClientApiKeyRepository.php`,
  `src/Modules/Clients/Infrastructure/PdoClientApiKeyRepository.php`,
  `tests/Support/InMemoryClientApiKeyRepository.php` — `touchLastUsed()`.
- `src/Modules/Clients/Infrastructure/definitions.php` — binds `ClientAuthenticator` →
  `ApiKeyAuthenticator`, `AuthAttemptLog` → `PdoAuthAttemptLog`.
- `tests/Unit/Shared/Http/IdempotencyMiddlewareTest.php` — keyless-write 400 test.
- `tests/Integration/MigrationRoundTripTest.php` — `client_auth_attempts` added to `TABLES`.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — now 10
  tables), `.claude/docs/Phases.md` (row 7 → ☑), `Architecture.md` §11, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`, `.claude/Orders.md` (D10),
  `.claude/PhaseDecisions.md` (Phase 7 Q1–Q5).

## Implementation Details

- **Layering:** `ClientAuthenticator` / `AuthResult` / `AuthenticatedClient` / `ClientContext`
  live in `Shared\Http` (HTTP concepts — status codes, headers), so `AuthenticationMiddleware`
  never depends on the Clients module. `ApiKeyAuthenticator` (Clients Application) implements the
  port and converts `ClientSnapshot` → `AuthenticatedClient`.
- **`ApiKeyAuthenticator` order:** missing header → `MissingAuthorization`; unparseable →
  `MalformedToken`; no key or prefix mismatch → `UnknownKey`; secret mismatch → `InvalidSecret`;
  revoked → `KeyRevoked`; active-but-expired → `KeyExpired`; client missing → `UnknownKey`;
  client disabled → `ClientDisabled` (403). All others → 401.
- **`last_used_at` throttle:** `LAST_USED_THROTTLE_SECONDS = 300`; skipped unless `last_used_at`
  is null or older than that. `touchLastUsed()` is a single targeted `UPDATE`. Best-effort —
  a failure is logged, not raised.
- **Attempt logging** is best-effort too (`safeRecord` swallows + logs). `client_auth_attempts`
  stores the parsed `key_id` only — never the secret.
- **Middleware order** on the group: `AuthenticationMiddleware` (outer, runs first, sets
  `authClientId`) then `IdempotencyMiddleware` (inner). Slim group middleware callbacks must be
  non-static closures.

## Database Changes

New table `client_auth_attempts` — columns and indexes in `database-design.md`. FK
`fk_client_auth_attempts_client_id → clients(id)` ON DELETE SET NULL. Append-only, no
`updated_at`. No changes to any existing table (`client_api_keys.last_used_at` already existed).

## API Changes

- New: `GET /api/v1/me` → `200` with the authenticated client (id, slug, name, status,
  default_currency, default_country, timezone, key_mode). No secrets.
- New group behaviour: every `/api/v1/*` request requires `Authorization: Bearer …`
  (`401 unauthorized` + `WWW-Authenticate` otherwise; `403 client_disabled` for a disabled
  client). `/api/v1` writes require `Idempotency-Key` (`400 idempotency_key_required`).
- `GET /health` unchanged and still public.

## Tests and Validation

- Tests created: 6 unit classes (18 tests) + 1 integration class (2 tests) + 3 support doubles.
- Tests modified: `IdempotencyMiddlewareTest` (+1), `MigrationRoundTripTest` (table list).
- Commands run:
  - `composer cs` → clean (175 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules + phpunit, 175 files).
  - `composer test` → `OK (124 tests, 383 assertions)`.
  - `composer test:integration` → `Skipped: 21` (no Docker / local MariaDB rejects `gomrok`).
  - `composer ci` → green (exit 0).
- Captured HTTP evidence (real `App::handle()` responses, DB adapters stubbed):
  - `GET /health` → `200` · `{"status":"ok","service":"gomrok"}`
  - `GET /api/v1/me` (no `Authorization`) → `401` · `WWW-Authenticate: Bearer realm="gomrok"` ·
    `{"type":"about:blank","title":"Authentication required","status":401,"code":"unauthorized"}`
  - `GET /api/v1/me` (stubbed valid key) → `200` ·
    `{"id":7,"slug":"televika",...,"key_mode":"gk_live"}`
  - `POST /api/v1/me` → `405` (no POST route).
- Migration class loads and extends `Phinx\Migration\AbstractMigration`; the DI container builds
  and wires `AuthenticationMiddleware` (only the live DB connection fails here).

## Technical Decisions

`PhaseDecisions.md` Phase 7 Q1–Q5:

1. `Authorization: Bearer <token>` only (no `X-Api-Key` alias yet).
2. Authenticated client via a per-request `ClientContext` holder **plus** request attributes.
3. `last_used_at` written **throttled** — only if stale by > 5 min.
4. `401` for every credential fault (opaque body), `403` only for a disabled client.
5. `Idempotency-Key` required on all `/api/v1` writes; abuse protection = failed-attempt logging
   to `client_auth_attempts` only. **No rate limiting / lockout this phase.**

In-phase: `AppFactory::create()` optional-container parameter for functional testing; the
`ClientAuthenticator` port kept in `Shared\Http` to preserve the "Shared depends on no module"
rule.

## Problems Encountered

- PHPStan `nullsafe.neverNull` on `RecordingAuthAttemptLog::last()?->…` in tests.
- PHPStan `cast`/`offsetAccess` on `PDOStatement::fetchAll()` mixed values.
- PHPUnit "Cannot bind an instance to a static closure" — Slim binds `$this` to a group
  middleware callback, which cannot be a `static` closure.
- A manual verification script reused one container across `set()` calls and saw a stale
  singleton middleware (test code was already correct — fresh container per request).

## Resolutions

- Gave `RecordingAuthAttemptLog` non-null `last()` + `lastReason()` / `lastOutcome()` helpers;
  tests no longer use `?->`.
- Rewrote the persistence assertion with a `fetch()` loop + `Row::str` / `Row::nullableInt`.
- Made the `/api/v1` group callback in `routes.php` a non-static closure.
- Noted the container-`set()` singleton caveat in `Knowledge.md`; each `ApiRoutingTest` case
  builds its own container.

## Deferred Work

- Rate limiting / failed-auth lockout — its own future concern, reading `client_auth_attempts`
  (per-plan limits, `429` + `Retry-After`, admin override).
- `X-Api-Key` header alias — add if a real client needs it.
- `client_auth_attempts` retention/purge job — when volume warrants it.
- Repositories in later modules applying `WHERE client_id = ClientContext::clientId()` — done
  per module as those modules land.
- Executing the migration + `AuthAttemptsPersistenceTest` against real MySQL — GitHub Actions,
  or the user locally.

## Final Result

10 tables. `/api/v1` is authenticated and client-scoped; `GET /api/v1/me` works end to end (with
the key store stubbed); `/health` stays public. 124 unit tests pass, PHPStan `max` clean.
`ClientContext` is ready for later modules to scope their queries against.

Next recommended phase: **Phase 8 — Providers module: types & capability model.**
