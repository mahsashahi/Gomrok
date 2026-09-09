# Knowledge

Domain knowledge about payments, providers, and edge cases learned while building Gomrok.
Not a plan and not a spec — durable facts and gotchas worth keeping.

## Providers

- **Ziraat Bank (Turkey)** is charge-only: bank-hosted payment page / 3D Secure redirect,
  return-URL + webhook/callback, and manual status polling. **No subscriptions, no auto-charge.**
  It exposes no product/registration API (`requires_registration = false`, `api_capable = false`).
- **Stripe / Mollie / PayPal** require a product/price object on their side before a package can
  be sold, and expose an API to create it. Gomrok tracks this per package per provider account
  as a sync state: `synced | not_created | drift | not_needed`.
- **Mollie** capabilities are payment-method-dependent — e.g. recurring may work for card but not
  for a given alternative method. Resolve allowed purchase types per (provider, method) pair, not
  per provider.
- Provider-specific statuses must be mapped to Gomrok's internal statuses; unknown ones are
  stored raw and flagged, never dropped.

## Money

- Currency scale varies: JPY = 0 decimals, USD/EUR = 2, BHD/KWD = 3. Never assume "×100".
  `brick/money` knows the scales; our `Money` VO delegates to it.
- Store integer minor units + ISO 4217 `CHAR(3)`. Never float.

## Pricing / vouchers

- A client never sends a price. Gomrok resolves package availability, price, provider, method,
  purchase type, and voucher validity server-side, then snapshots the decision on the
  payment/subscription so later rule changes don't rewrite history.
- Same package can legitimately cost different amounts by country, currency, provider, and
  method (gateway fees, taxes, market decisions) — e.g. cheaper in Turkey than the EU/US.
- Voucher redemption must be concurrency-safe and idempotent: a duplicate payment request or
  webhook retry must not redeem a voucher twice. Reserve → finalise on paid → release on
  failed/cancelled/expired.

## Identifiers

- Plain `INT UNSIGNED AUTO_INCREMENT` primary keys (from 1); plain `INT` foreign keys.
  **No ULID / UUID / typed-ID classes.** The same `int` is used in the DB, in PHP, and in
  API paths / callback URLs / admin routes. (Decision changed 2026-09-08 — see
  `Architecture.md` §6, `DatabaseAgent.md`, `PhaseDecisions.md` Phase 1 Q3.)
- Because sequential ints are guessable, cross-tenant safety comes from **authorization** — every
  query is scoped to the authenticated client (`TenantIsolation.md`), never from unguessable ids.
- `BIGINT` is still fine for non-key columns (e.g. `amount_minor`); the "no BIGINT" rule is
  keys-only.

## Reference data

- `brick/money`'s ISO provider has **166** currencies. `currencies` is seeded from it verbatim.
- Money `currency CHAR(3)` columns are **not** DB-FK'd to `currencies` — `Currency::of()`
  validates against the same list in code. `countries.default_currency` *is* a real FK. (Phase 4)
- `countries` holds only the markets Gomrok has decided to operate in (18 to start), not the full
  ISO list — new markets are a follow-up migration.

## Idempotency / audit / error logs (Phase 5)

- `idempotency_keys` stores a **lock + a pointer** (`target_type`/`target_id`), never response
  bodies. A replayed `done` key returns the referenced entity's *current* state, so a status
  change between the first call and the retry is reflected. `processing` → 409, same key +
  different request fingerprint → 422. `expires_at = created_at + 24h`; an expired row is ignored
  on lookup (correctness never depends on the purge job).
- `IdempotencyMiddleware` is **not on the global stack** — the client-API layer registers it
  per write route in Phase 7. It needs a `client_id` request attribute (`authClientId`) that the
  Phase 7 auth middleware will set; with no client resolved it passes straight through.
- `audit_logs.before` / `after` store the **whole** target row as JSON (decision Phase 5 Q2),
  not a changed-columns diff. `SecretRedactor` masks secret-ish keys before any write to
  `audit_logs` or `error_logs.context` — defence in depth; secrets should not be in those arrays
  to begin with.
- `error_logs` is written **only** by the explicit `ErrorLogWriter`, never a Monolog handler
  (decision Phase 5 Q3). `PdoErrorLogWriter` swallows its own failures (logs to stderr) — logging
  an error must not throw over the top of it. Phase 5 wires one call site: `JsonErrorHandler`.
- CI (`.github/workflows/Ci.yml`) is the first place migrations run against real MySQL — there is
  no Docker daemon or usable local DB in this dev environment.

## Clients / API keys (Phase 6)

- A client's **`slug`** is the immutable public handle — cross-module fixtures, config, and CLI
  refer to a client by slug; runtime FKs use the integer `id`.
- API keys: the token `gk_<live|test>_<key_id>.<secret>` is shown **once**. Stored: `key_id`
  (unique, public, safe to log), `sha256(secret)`, `prefix`, `last_four`. Auth (Phase 7) =
  parse → point-read by `key_id` → `hash_equals(sha256(presented), stored)`. No slow KDF — the
  secret is already 192-bit random.
- **Disabling a client is soft and reversible and does NOT revoke its keys** (Phase 6 Q4). The
  Phase 7 auth middleware is the single chokepoint that rejects a disabled client's requests.
- Domain events (`ClientCreated`, …) are **defined but not dispatched** — no subscriber yet. Use
  cases return the event in their result and write an `audit_logs` row directly (actor =
  `system`; a real admin actor comes with the Phase 26 admin panel).
- `Transactions` is the port use cases depend on; `TransactionRunner` (PDO) implements it, tests
  use `SynchronousTransactions`. `Row` coerces `PDOStatement::fetch()` mixed values at the
  adapter boundary.
- Each module ships `Infrastructure/definitions.php` (PHP-DI array); `ContainerFactory` merges
  them — add a row to its `MODULE_DEFINITIONS` list per new module.
- `ClientsSeeder` creates `local-dev` + a **fixed** dev key, and is a **no-op unless
  `APP_ENV ∈ {local, testing}`** — never a known secret in production.

## API authentication & scoping (Phase 7)

- Auth is **`Authorization: Bearer gk_<mode>_<key_id>.<secret>`** only. `/api/v1/*` all require
  it; `/health` is public (outside the group), does no I/O, returns only
  `{"status":"ok","service":"gomrok"}`.
- Failure responses are deliberately opaque: **`401 unauthorized`** for *any* credential fault
  (missing header, bad scheme, unparseable token, unknown key, wrong secret, expired, revoked) +
  `WWW-Authenticate: Bearer realm="gomrok"`; **`403 client_disabled`** only for a valid key on a
  disabled client. The real reason is in `client_auth_attempts.reason`, never the response body.
- `ApiKeyAuthenticator` (Clients) implements the `Shared\Http\ClientAuthenticator` port — the
  middleware never depends on the Clients module. It also records the attempt and throttles
  `last_used_at` (≤ 1 write per key per 5 min).
- The authenticated client rides in **`ClientContext`** (per-request DI singleton, like
  `CorrelationId`) plus request attributes `authClient` / `authClientId` / `authKeyMode`.
  `authClientId` is what `IdempotencyMiddleware` reads. Later modules' repositories take
  `ClientContext` and apply `WHERE client_id = :ctx` themselves.
- `Idempotency-Key` is **required on every `/api/v1` write** — a keyless write gets
  `400 idempotency_key_required` (`IdempotencyMiddleware(requireKeyOnWrites: true)`).
- **No rate limiting yet.** `client_auth_attempts` (success + failure rows) is the groundwork; a
  proper limiter (`429` + `Retry-After`, per-plan limits, admin override) is its own future
  concern.
- `AppFactory::create(?ContainerInterface)` accepts an override container so functional tests
  boot the real Slim stack with the DB adapters stubbed.

## Gotchas

- `brick/money 0.10.3` calls `BigDecimal::dividedBy()` without a scale internally (via
  `allocate()`); `brick/math 0.14` deprecated that. We pin `brick/math:~0.12.0`. Unpin once
  `brick/money` releases a fix. (Phase 3)
- `Money` arithmetic across currencies throws `MoneyMismatchException` — treated as a programmer
  error (500), not a `DomainError`.
- Webhooks: store the raw event **before** processing, verify signature, respond fast, process
  async. Duplicate webhook ids must be deduped.
- Never silently downgrade a requested purchase type (a subscription request that can't be
  satisfied is an error, not a one-time payment).
- Gomrok is greenfield — there is no "Dexter"/legacy system. Ignore any doc that says otherwise.
- Slim **group** middleware callbacks must NOT be `static` closures (`$this` is bound to the
  `RouteCollectorProxy`) — `routes.php` uses a non-static closure for `$app->group('/api/v1', …)`.
- Swapping a container entry with `$container->set()` after the graph resolved does **not**
  replace an already-built singleton (e.g. a middleware). Functional tests build a fresh
  container per request.
