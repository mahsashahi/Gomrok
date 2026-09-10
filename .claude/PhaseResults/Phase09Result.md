# Phase 09 — Provider accounts (per client)

## Execution Summary

- Phase: 09 — Provider accounts (per client)
- Start Datetime: 2026-09-09 19:21
- End Datetime: 2026-09-09 20:16
- Estimated Duration: 4–6h
- Actual Duration: 55m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; the 4 migrations + persistence tests run in CI)

## Work Completed

- Extended the `Providers` module with **provider accounts** — a client's credentials for a
  provider type in one `mode` (live / test).
- `Shared\Application\SecretCipher` port + `SodiumSecretCipher` (libsodium `crypto_secretbox`,
  key from base64 `APP_ENCRYPTION_KEY`, loaded via `Settings`). Missing key → the cipher throws
  when first resolved.
- `provider_accounts` (encrypted `secret_ciphertext` + `secret_last_four`, plaintext
  `public_key`), `provider_account_endpoints` (webhook / callback / return — random `token` +
  encrypted signing secret, one active per kind), `provider_account_countries` +
  `provider_account_methods` (the Phase 10 router's account-level filters).
- `ProviderAccount` aggregate + `ProviderAccountEndpoint`; `ProviderAccountRepository` port +
  MySQL adapter; `ProviderAccountDirectory` (published read, no secrets) + `ProviderAccountSummary`;
  `ProviderAccountCredentials` (the one decrypt path, for adapters).
- Use cases: `CreateProviderAccount`, `SetProviderAccountMarkets`, `RotateProviderAccountSecret`,
  `AddProviderAccountEndpoint`, `ChangeProviderAccountStatus` (disable / enable) — each returns
  `Result` and writes an audited `provider_account.*` action; secret rotation + endpoint changes
  are flagged sensitive.
- CLI: `bin/{CreateProviderAccount,RotateProviderAccountSecret,AddProviderAccountEndpoint,ListProviderAccounts}.php`
  (secrets read from a flag or stdin, never echoed). `ProviderAccountsSeeder` — env-gated
  `local-dev` test Stripe account + webhook endpoint.

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/2026090919300{1..4}_*.php` → `Create{ProviderAccounts,ProviderAccountEndpoints,ProviderAccountCountries,ProviderAccountMethods}Table`
- `src/Database/Seeds/ProviderAccountsSeeder.php`

**Shared** — `src/Shared/Application/{SecretCipher,SecretDecryptionFailed}.php`,
`src/Shared/Infrastructure/Crypto/SodiumSecretCipher.php`

**Providers / Domain** — `ProviderAccount`, `ProviderAccountEndpoint`, `ProviderAccountSlug`,
`EncryptedSecret`, `ProviderAccountMode`, `ProviderAccountStatus`, `EndpointKind`,
`ProviderAccountRepository`

**Providers / Application** — `ProviderAccountDirectory` + `ProviderAccountSummary`,
`ProviderAccountCredentials`, `ProviderAccountAuditSnapshot`, and the 5 use-case folders
(`CreateProviderAccount` / `SetProviderAccountMarkets` / `RotateProviderAccountSecret` /
`AddProviderAccountEndpoint` / `ChangeProviderAccountStatus`)

**Providers / Infrastructure** — `PdoProviderAccountRepository`, `PdoProviderAccountDirectory`,
`PdoProviderAccountCredentials`

**CLI** — `bin/{CreateProviderAccount,RotateProviderAccountSecret,AddProviderAccountEndpoint,ListProviderAccounts}.php`

**Tests** — `tests/Unit/Shared/Infrastructure/SodiumSecretCipherTest.php`,
`tests/Unit/Modules/Providers/Domain/ProviderAccountTest.php`,
`tests/Unit/Modules/Providers/Application/ProviderAccountHandlersTest.php`,
`tests/Integration/ProviderAccountsPersistenceTest.php`,
`tests/Support/{InMemoryProviderAccountRepository,StubProviderCatalog,StubClientDirectory}.php`

`.claude/PhaseResults/Phase09Result.md` — this file.

## Files Modified

- `src/Config/Settings.php` — `?string $encryptionKeyBase64` from `APP_ENCRYPTION_KEY`.
- `src/Config/container.php` — lazy `SecretCipher` factory (`SodiumSecretCipher::fromBase64Key`).
- `src/Modules/Providers/Infrastructure/definitions.php` — binds
  `ProviderAccountRepository` / `ProviderAccountDirectory` / `ProviderAccountCredentials`.
- `composer.json` — `ext-sodium`; `provider-account:*` scripts + descriptions.
- `.env.example` (blank `APP_ENCRYPTION_KEY` + note), `phpunit.xml` + `.github/workflows/Ci.yml`
  (throwaway key; `sodium` extension in CI).
- `tests/Integration/MigrationRoundTripTest.php` — 4 provider-account tables.
- DB docs (`database-design.md`, `database-diagram.md` + `.html`, `db_explain.md` — 17 tables),
  `.claude/docs/Phases.md` (row 9 → ☑), `Architecture.md` §8/§11, `.claude/FileIndex.md`,
  `.claude/knowledge/Knowledge.md`, `.claude/docs/Commands.md`, `.claude/Orders.md` (D12).

## Implementation Details

- **`SodiumSecretCipher`** storage form: base64 of `nonce (24B) || ciphertext+tag`. Each
  `encrypt()` uses a fresh random nonce. `decrypt()` throws `SecretDecryptionFailed` on a
  tampered ciphertext or the wrong key (never returns `false`).
- **`ProviderAccount::addEndpoint()`** deactivates any existing active endpoint of the same kind
  — rows are kept (rotated-token history), the FK is CASCADE.
- **`CreateProviderAccountHandler`** derives the slug (`{code}-{mode}` by default), validates the
  client (exists + active), provider type (`ProviderCatalog::find`), countries
  (`ReferenceCatalog`), methods (`PaymentMethod::tryFrom`) and slug uniqueness, then encrypts the
  secret and persists in one transaction with a `provider_account.created` audit row (plaintext
  never in the snapshot).
- **`PdoProviderAccountRepository::save()`** insert/update the row, then delete+reinsert
  countries/methods and insert-new/update-existing endpoints.
- **`PdoProviderAccountDirectory`** is a single projection query (subselect `GROUP_CONCAT` for
  countries/methods, subselect count for active endpoints) — no secret column selected.
- **`ProviderAccountCredentials`** is the only place `SecretCipher::decrypt` is called at
  runtime; it is a separate port from the directory so the decrypt capability isn't handed out
  with every read.

## Database Changes

4 new tables (see `database-design.md`). `provider_accounts` FK `clients(id)` CASCADE +
`provider_types(id)` **RESTRICT**; the three child tables FK `provider_accounts(id)` CASCADE;
`provider_account_countries.country_code` FK `countries(code)` RESTRICT. `provider_account_endpoints.token`
unique. No changes to existing tables.

**Non-schema:** `APP_ENCRYPTION_KEY` env var (`Settings` / `.env.example` / CI / `phpunit.xml`).

## API Changes

No HTTP endpoints. `ProviderAccountDirectory` / `ProviderAccountCredentials` are internal ports.
CLI: 4 `bin/` scripts. `Settings::__construct` gained an optional parameter.

## Tests and Validation

- Tests created: 3 unit classes (16 tests) + 1 integration class (3 tests) + 3 support doubles.
- Tests modified: `MigrationRoundTripTest` table list.
- Commands run:
  - `composer cs` → clean (248 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules + phpunit, 247 files).
  - `composer test` → `OK (157 tests, 572 assertions)`.
  - `composer test:integration` → `Skipped: 29` (no Docker / local MariaDB rejects `gomrok`).
  - `composer ci` → green (exit 0).
  - `SecretCipher` round-trips through the container (`APP_ENCRYPTION_KEY` set); migration +
    seeder classes load; the container throws without the key (verified — the intended
    boot-time failure).
- **Exit criteria** — `ProviderAccountHandlersTest::createsMultipleAccountsOfOneTypeForOneClient`
  (stripe/live + stripe/test for one client; stored ciphertext ≠ plaintext; decrypt = original)
  and `::rotateSecretChangesTheStoredSecretAndAudits` (last-four changes, audit `before`/`after`
  contain no plaintext). `SodiumSecretCipherTest` — round-trip, tamper, wrong key, missing key.

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 9 Q1–Q5:

1. Secret storage = **app-encrypted column behind a `SecretCipher` port** (libsodium default).
2. `mode` = **an enum on the account**; the request's key prefix picks the pool.
3. Webhook/callback config = **a separate `provider_account_endpoints` table** (user chose this
   over the recommended columns-on-account).
4. Account narrowing = **countries + methods join tables only**; capabilities inherited from the
   type.
5. CRUD = **CLI commands + an `APP_ENV`-gated dev seeder**.

## Problems Encountered

- PHPStan: `fetchOne()` returned `mixed` where `array|false` was declared.
- PHPStan: `array_key_last()` on a `list` that PHPStan couldn't prove non-empty.
- PHPStan (bin): short ternary not allowed.
- Test data: `substr('...newnewnew', -4)` isn't `news`.

## Resolutions

- Coerced the `fetch()` result with `is_array()` before returning.
- Added `assertNotEmpty()` before the `array_key_last()` access.
- Replaced `?:` with an explicit empty-array check.
- Used explicit `sk_live_rotated12345` → `2345` in the assertion.

## Deferred Work

- `payment_methods` catalogue + `provider_type_method_capabilities` — still deferred (Phase 12).
- Account-level `provider_account_capabilities` allow/deny — additive if a real case appears.
- The inbound webhook route that consumes `provider_account_endpoints.token` — Phase 25.
- Rotating the **encryption key** (re-encrypt migration) — when there's a deploy target
  (Phase 30); a Vault / KMS `SecretCipher` is the same-day option.
- Executing the migrations + `ProviderAccountsPersistenceTest` against real MySQL — CI, or the
  user locally.

## Final Result

17 tables. A client can connect multiple provider accounts per type (live + test), with the
secret key encrypted at rest and only decryptable through one narrow port. `ProviderAccountDirectory`
gives Phase 10's router the candidate accounts + their country/method filters; the Phase 27
admin panel has a masked-secret read model.

Next recommended phase: **Phase 10 — Country provider configuration & routing resolution.**
