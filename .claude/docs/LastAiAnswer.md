# Q: Phase 7 — Client API authentication & scoping

**Status:** ☑ code + docs complete, `composer ci` green (124 unit tests). The one migration +
persistence tests run in GitHub Actions (no Docker / usable MySQL here). 2026-09-08 16:56 →
17:49, 53m (Est. 3–5h).

## Decisions (PhaseDecisions.md Phase 7 Q1–Q5)

1. **`Authorization: Bearer gk_<mode>_<key_id>.<secret>`** only. `/api/v1/*` all require it;
   `/health` stays public (outside the group, no I/O, `{"status":"ok","service":"gomrok"}`).
2. Authenticated client rides in a per-request **`ClientContext`** holder (DI singleton, like
   `CorrelationId`) **plus** request attributes `authClient` / `authClientId` / `authKeyMode`.
3. `last_used_at` written **throttled** — only if null or stale by > 5 min (`touchLastUsed()`).
4. **`401 unauthorized`** for any credential fault (opaque body, `WWW-Authenticate: Bearer`);
   **`403 client_disabled`** only for a valid key on a disabled client.
5. **`Idempotency-Key` required on every `/api/v1` write** (`400 idempotency_key_required`).
   Abuse protection = failed/successful auth logged to a new `client_auth_attempts` table.
   **No rate limiting this phase** — its own future concern.

## Built (schema confirmed by the user first)

- **Migration** `CreateClientAuthAttemptsTable` (`client_auth_attempts` — one append-only row per
  auth attempt; FK to `clients` SET NULL; parsed `key_id` only, never the secret). **10 tables.**
- `Shared\Http`: `ClientAuthenticator` port, `AuthResult`, `AuthenticatedClient`,
  `AuthRequestMeta`, `ClientContext`, `AuthenticationMiddleware`.
- `Clients`: `AuthFailureReason`, `Application/Authenticate/{ApiKeyAuthenticator, AuthAttempt,
  AuthAttemptLog}`, `Infrastructure/PdoAuthAttemptLog`. `ApiKeyAuthenticator` implements the
  Shared port so the middleware never depends on the module.
- `IdempotencyMiddleware` gained `requireKeyOnWrites`; attached to the `/api/v1` group.
- `src/Http/Api/MeAction.php` → `GET /api/v1/me`. `routes.php` group. `AppFactory::create()`
  takes an optional container for functional tests. `ClientApiKeyRepository::touchLastUsed()`.

## Verified

`composer cs` clean · `composer stan` `[OK]` (175 files, level `max` + strict-rules) ·
`composer test` **OK (124 tests, 383 assertions)** · `composer ci` green ·
`composer test:integration` → 21 tests, all self-skip. **Real HTTP:** `GET /health` → 200
`{"status":"ok","service":"gomrok"}`; `GET /api/v1/me` no key → 401 +
`WWW-Authenticate: Bearer realm="gomrok"`; stubbed valid key → 200 client JSON.

## NOT verified here

Migration + `AuthAttemptsPersistenceTest` against real MySQL. Push to `main` (CI) or
`docker compose up -d mysql && composer db:reset && composer test:integration`.

## `.claude/` updated

`database-design.md` / `database-diagram.md` / `.html` / `db_explain.md` (10 tables);
`Architecture.md` §11; `Phases.md` (row 7 → ☑); `Changelog.md`; `FileIndex.md`; `Knowledge.md`;
`Commands.md`; `Orders.md` (D10); `PhaseDecisions.md`; `PhaseResults/Phase07Result.md`.

Next: **Phase 8 — Providers module: types & capability model** (the provider-capability
catalogue deferred from Phase 4 Q4 — enum and/or `provider_capabilities` table + per-type
capability mapping).
