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
  `Architecture.md` §6, `DatabaseAgent.md`, `PhaseResults/PhaseDecisions.md` Phase 1 Q3.)
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

## Provider capabilities (Phase 8)

- **Two vocabularies, kept separate** (Phase 8 Q2): `PurchaseType` (`one_time_payment`,
  `recurring_payment`, `auto_charge`, `subscription`) is the routing-level concept — country
  config, package availability, the payment flow all speak it. `Capability` (19 flags:
  `hosted_checkout`, `partial_refund`, `subscription_cancel`, `three_d_secure`,
  `manual_status_polling`, …) is the finer "can it do this action" layer. `subscription` is a
  purchase type, **not** a capability flag.
- `Capability` enum is the source of truth; `provider_capabilities` is a seeded mirror for FK
  integrity + admin display. An integration test keeps them in lock-step (like `currencies`).
- Per-type declarations (`provider_type_capabilities` / `provider_type_purchase_types`) are
  seeded for **stripe + paypal only**. Ziraat & Mollie rows land in their adapter phases (23/22).
  When an adapter lands, its `getCapabilities()` must be a **subset** of what the type declares
  in the DB — the DB is the ceiling.
- Payment methods are **not** a table yet (Phase 8 Q4). Method-level nuance ("Mollie via PayPal
  can't do subscriptions / recurring") is `MethodCapabilityRules`, an in-code placeholder that
  `provider_type_method_capabilities` replaces in Phase 9/12. The `ProviderCapabilityResolver`
  signature already carries `?PaymentMethod` so that's an additive change, not a refactor.
- `ProviderCapabilityResolver` resolves: type declaration − method exclusions. Account (Phase 9)
  and client/country (Phase 10) narrowing wrap it. Unknown provider → `null` / `false`, never a
  silent "yes".

## Provider accounts & secrets (Phase 9)

- Provider **secret keys** are encrypted at rest: `Shared\Application\SecretCipher` port +
  `SodiumSecretCipher` (libsodium `crypto_secretbox`, key from base64 `APP_ENCRYPTION_KEY`).
  Missing key → the cipher throws when first resolved (never a silent fallback). CI + `phpunit.xml`
  set a throwaway key; `.env.example` ships it blank.
- Stored: `secret_ciphertext` + `secret_last_four`. The plaintext is only ever in memory during
  create / rotate (encrypt) and inside a provider adapter (decrypt via `ProviderAccountCredentials`
  — the one decrypt path, kept off `ProviderAccountDirectory`). `public_key` is not secret →
  plaintext.
- `provider_accounts.mode` (`live` / `test`) maps 1:1 to the API key's `gk_live` / `gk_test`
  prefix. **Enforcement is Phase 10 routing** — a test request must never resolve a live account.
- `provider_account_endpoints`: one active per `kind` (webhook/callback/return), enforced in the
  aggregate (`addEndpoint` deactivates the prior active). Rows are never deleted → rotated tokens
  keep history. `token` (unique, `whk_…`) is the URL segment consumed by the Webhooks module
  (Phase 25).
- Account-level capability narrowing is **not** modelled (Phase 9 Q4) — an account inherits its
  provider type's `ProviderCapabilities`; `provider_account_countries` / `_methods` are the only
  account-level filters (used by the Phase 10 router).
- Secret rotation, endpoint add, disable/enable are all **audited** (`provider_account.*` actions);
  `SecretRedactor` + `ProviderAccountAuditSnapshot` keep plaintext/ciphertext out of the audit JSON.

## Provider routing (Phase 10)

- **Provider groups are the only country→provider mechanism** (Phase 10 Q1). There is no
  `country_provider_configs` / `country_provider_priorities` / `country_payment_methods` /
  `country_purchase_capabilities` table — `Phases.md` originally listed them; the Q1 decision
  superseded that.
- A `provider_group` = countries + optional `device_type` + optional `currency_code` + ordered
  `provider_group_accounts` + `provider_group_purchase_types` / `_methods`. `is_default = true`
  is the fallback group (no country rows), one per `(client_id, device_type)`.
- Three invariants are **app-enforced, not DB constraints** (MySQL has no cross-table check):
  one default per scope; a country in ≤1 non-default group per `(client, device_type)`
  (`ConfigureProviderGroup` rejects overlap); a `provider_group_accounts` link's account belongs
  to the group's client (`SetProviderGroupAccounts` rejects otherwise).
- `ProviderRouter::route()` returns `Result<RoutingDecision>`. Purchase-type gate = group set
  **∩** the account's `ProviderTypeDeclaration` (so Turkey `one_time_payment`-only rejects a
  subscription even though Stripe supports it). Empty group purchase-type set = fail closed;
  empty method set = fail open.
- `RoutingDecision` is an **in-memory VO** — no table this phase (Q4). It has `toArray()` /
  `fromArray()` (version-tagged) for Phase 17 to snapshot on the payment. `chosen()` = first
  candidate; the rest are the fallback chain.
- `mode` (live/test) enforcement lives here: `RoutingRequest->mode` comes from the API key
  prefix; a `test` request rejecting a `live` account shows up as `RejectionReason::ModeMismatch`
  (and `provider_routing.no_provider_for_market` if that leaves no candidate).
- Ziraat + Mollie provider-type declarations are now seeded (`data/ProviderTypeDeclarations.json`);
  Ziraat has **no** subscription/auto_charge/recurring purchase types — keep it that way.

## Packages (Phase 11)

- One `packages` table with `client_id`; `code` unique **per client** (`UNIQUE (client_id,
  code)`). **No** global catalogue, **no** `client_packages` junction, **no**
  `packages.enable_for_client` permission — `status = disabled` is the per-client hide switch.
- Availability = 4 dedicated join tables (`package_countries` / `_currencies` /
  `_payment_methods` / `_provider_accounts`), all **fail open**: an empty set for a dimension =
  available everywhere for it (Phase 11 Q2). Same fail-open direction as `provider_group_methods`.
- `PackageCatalog::resolve()` is a **concrete** Application class (like `ProviderRouter`), not a
  port — depends on `PackageRepository` + `ProviderAccountDirectory`. It narrows each package's
  provider-account set to the client's **active** accounts. `PackageDirectory` is the raw read
  port (`Pdo` adapter, `GROUP_CONCAT` projection).
- `ResolvedPackage` deliberately has **no `price`** (Phase 13) and **no `purchaseTypes`** (Phase
  12). `GET /api/v1/packages` is **not** mounted yet — Phase 13 wires it once those exist (Q3).
- `packages.metadata` is a pass-through JSON object — validated as an object at the use-case
  boundary, `json_encode`/`json_decode` at the persistence boundary, never queried.
- Phase 12 adds trial config / `durationMonths` / `badge` / `highlighted` / `clientPackageId` /
  purchase capabilities / package-provider definitions by **additive migration** — the Phase 11
  `packages` table is deliberately lean (Q4).
- `SetPackageAvailability` validates every country/currency (`ReferenceCatalog`), method
  (`PaymentMethod`), and provider-account id (must be in
  `ProviderAccountDirectory::forClient(package.clientId)` — cross-client guard, app-enforced).

## Package capabilities & provider definitions (Phase 12)

- **Purchase capabilities are fail *closed*** — `package_purchase_capabilities` empty = the
  package is **not sellable** and `PackageCatalog::resolve()` drops it. This is the opposite of
  the Phase 11 availability dimensions (fail open). `Package::isSellable()` = active AND ≥1
  global capability.
- Trial config lives on the capability **row** (per purchase type), not on `packages`. Domain
  guard: `has_trial` ⇒ `trial_days` set AND type ∈ {subscription, recurring_payment};
  `PackagePurchaseCapability::of()` throws, `::validate()` returns a `DomainError` for handlers.
- `package_country_purchase_capabilities` **replaces** (not intersects) the global set for a
  country. `Package::effectiveCapabilities(?country)`: no override rows for that country → global
  set; else global ∩ overridden types. The market/provider ∩ (provider group + provider-type
  declaration) is the **payment flow's** job (Phase 17), not the resolver's.
- `PackagePurchaseCapabilityResolver` is a **concrete** Application class (like `ProviderRouter`
  / `PackageCatalog`). `PackageCatalog` now takes it as a 3rd constructor arg.
- `package_provider_definitions` `sync_state`: `not_created` → `synced` (manual `remote_id`) →
  `drift` (local edit) → `not_needed` (opt out). The **drift sweep** is an explicit
  `markStaleForPackage()` repo call inside `UpdatePackage` / `SetPackageAvailability` /
  `SetPackagePurchaseCapabilities` / `SetPackageCountryPurchaseCapabilities` handlers — **not**
  `ChangePackageStatus` (disabling doesn't change the product shape). No event bus (Q5).
- Provider-API product creation (`createRemoteProduct`) is **not** implemented — Phases 21–23
  wire it per adapter. Phase 12 only stores state + accepts a manual id.
- `packages` gained `badge` / `highlighted` / `client_package_id` via additive migration
  `20260910130004` — `client_package_id` is the client's own reconciliation id, **not**
  unique-enforced by Gomrok.
- Editing constructors changed: `UpdatePackageHandler` and `SetPackageAvailabilityHandler` now
  take `PackageProviderDefinitionRepository` — update any test that news them up directly.

## Pricing (Phase 13 — baseline)

- **Pricing groups ≠ provider groups.** Provider groups (Phase 10) are *exclusive* by country
  (routing). Pricing groups are **priority-ordered and overlapping** — a country can be in
  several; the lowest `priority` wins, and `is_default` is forced last. This lets a "Global iOS"
  overlay shadow a regional group for one device (the exit criterion).
- `priority` unique per client, one `is_default` per client, the default group can't be disabled
  — all **app-enforced** (no partial unique in MySQL).
- `default_package_prices` = **one** baseline row per package (`UNIQUE (package_id)`).
  Cross-currency: a `status=default` group in a different currency converts via
  `client_exchange_rates` (client-configured, **effective-dated** — the latest row with
  `effective_from <= now`). `status=override` rows carry their own amount and are never
  converted. No rate → `pricing.no_exchange_rate`.
- Conversion goes through `Shared\Domain\Money::convertTo($currency, $rateString)` (brick,
  HALF_EVEN); `Money` never fetches rates. Added `Money::amount()` (decimal string).
- `pricing_group_packages`: **no row = implicit `status=default`**. `override` ⇒ amount +
  currency, currency = group currency (`PricingGroupPackage::validate`). BIGINT `amount_minor`
  is allowed (keys-only "no BIGINT" rule).
- `PriceResolver` is concrete (like `ProviderRouter` / `PackageCatalog`). `resolveGroup()` +
  `priceForGroup(group, packageId, code, name, badge, highlighted)` are public so `PriceCatalog`
  can resolve the group once. `ResolvedPrice.source` ∈ `baseline` / `converted` / `group_override`.
- **`GET /api/v1/pricing/resolve` is a `GET`, not `POST`** (CLAUDE.md suggests POST). It's a pure
  read; the `/api/v1` write-idempotency middleware would demand an `Idempotency-Key` on any
  POST. Same reasoning will apply to `vouchers/validate`.
- `GET /api/v1/packages` is finally mounted (deferred through Phases 11–12). Response shape is
  stable — Phase 14 overrides / Phase 15 A/B change the *number*, not the structure.

## Pricing overrides (Phase 14)

- **One `price_rules` table, 7 nullable dimensions** (`pricing_group_id`, `country_code`,
  `provider_account_id`, `payment_method`, `purchase_type`, `subscription_interval`,
  `currency_code`). Null = wildcard. `PriceRule::DIMENSIONS` is the canonical order and doubles
  as the tie-break priority (subscription_interval first … pricing_group_id last).
- **Winner** (`PriceRuleResolver`): most matched dimensions → dimension-priority order → highest
  `id`. Only rules where *every* pinned dimension equals the request are candidates.
- **Unavailable is a hard stop.** The most-specific matching rule with `is_available = 0` →
  `pricing.combination_unavailable`; the resolver does **not** fall back to the base price or a
  less-specific rule. This is the exit criterion — a disabled combo never yields a wrong price.
- `PriceResolver` runs rules **after** the Phase 13 base price, so the pricing group + currency
  are already fixed. The `PriceRuleContext.currency` is the *resolved group* currency — a rule
  that pins `currency_code = EUR` therefore never matches inside a USD group (the `converted`
  base price stands there). A pinned group + currency must agree at write time
  (`price_rule.currency_mismatch`).
- Available rule needs `pricing_group_id` **or** `currency_code` pinned (`PriceRule::validate`),
  so a bare "card = €25" must also pin the currency — expect `appliedDimensions` to include
  `currency_code` in that case.
- **NULL-distinct unique index.** `uniq_price_rules_dimensions` spans 8 columns with NULLs;
  MySQL treats NULLs as distinct, so `ON DUPLICATE KEY` won't fire. `PdoPriceRuleRepository`
  upserts via a null-safe (`<=>`) `findByDimensions` lookup; `PricingSeeder` deletes-then-inserts.
- `PriceCatalog` (the browse list, `GET /api/v1/packages`) still uses `priceForGroup` only — **no
  dimension rules**. Rules apply on `GET /api/v1/pricing/resolve` (which gained `method` /
  `purchase_type` / `interval` query params).
- New `SubscriptionInterval` enum: `monthly` / `quarterly` / `yearly`. A rule pinning it needs a
  subscription/recurring `purchase_type` (`price_rule.interval_needs_subscription`).

## A/B price lists (Phase 15 — narrowed)

- **Only the data model + resolver + CRUD shipped.** Visitor→list assignment (persistence,
  hashing, `visitor_ref` params) is **deferred to Phase 24** (Phase 15 Q4/Q5, user's call). See
  [[phase15-ab-assignment-deferred]]. Every `PriceResolver::resolve` currently passes
  `$priceListId = null` → the group's control list. Re-ask Phase 15 Q4 + Q5 at Phase 24.
- **Explicit control row (Q1 Option 2 — user changed the recommendation).** Every pricing group
  owns one `price_lists` row with `is_control = 1`, `factor = 1.0000`, `is_enabled = 1`. Created
  by `CreatePricingGroupHandler` in the same transaction as the group; the migration backfills
  one per pre-existing group; `PricingSeeder` upserts one per seeded group (it uses raw SQL, not
  the handler). The control row can't be renamed-to-collide, disabled, deleted, or re-factored
  (`price_list.cannot_disable_control` / `control_factor_locked`).
- **Pipeline slot: base → price list → `price_rules`** (Q3). `PriceListResolver::apply` runs in
  `PriceResolver::resolve` (not in `PriceCatalog` — the `/packages` browse list stays on the
  base price). A matching Phase 14 `price_rule` still overrides whatever the list produced.
- **Precedence within a list** (Q2): exact `price_list_packages` amount → else `base × factor`
  (HALF_EVEN, `Money::multipliedBy`) → else base unchanged. `ResolvedPrice.source` becomes
  `price_list` **only when the amount moved**; a control / factor-1 list just stamps
  `priceListId` / `priceListName` / `priceListFactor`.
- **Disable-fallback is in the resolver, not a sweep.** `PriceListResolver::resolveList` returns
  the control list whenever `$priceListId` is null, unknown, from another group, or disabled — so
  a future stored assignment pointing at a killed experiment silently drops to control.
- `price_lists.factor` is `DECIMAL(6,4)` — PDO returns it as a string (`"0.9000"`);
  `PriceList::experiment` / `changeFactor` normalise via `number_format(...,4)`.
- `price_list_packages` currency **must** equal the pricing-group currency
  (`price_list_package.currency_mismatch`); not allowed on a control list
  (`price_list.control_has_no_package_prices`).

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
