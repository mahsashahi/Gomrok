# Database — per-table guide

Plain-language notes on each table: what it holds, when rows appear, what references it.
**Living document** — kept in lock-step with `database-design.md` and `database-diagram.md`
(`.claude/Rule.md` §5).

---

## Reference data (Phase 4)

These three tables are **static seeded data** — populated by `composer db:setup`, never written
to by the application at runtime. No timestamps.

### `currencies`

Every ISO 4217 currency (166 rows). It exists so the admin panel can list currencies and so
reports have currency names/scales to join against. It is **not** enforced as a foreign key on
money columns — `Shared\Domain\Currency::of()` already rejects unknown codes using the same list,
so a DB constraint on every `currency CHAR(3)` column would add write cost for no extra safety.

- **Rows added:** only by re-running `CurrenciesSeeder` (idempotent). The ISO list changes rarely.
- **Referenced by:** `countries.default_currency` (real FK). Money columns store `currency CHAR(3)`
  by value, unconstrained.

### `countries`

The markets Gomrok has decided to operate in — 18 rows to start (Türkiye, US, UK, most of the
EU/EEA, UAE, Saudi Arabia). Each row is a *decision*: a country here will get provider routing,
pricing groups, and capability rules in later phases.

- **Rows added:** by a small follow-up migration + seed when Gomrok opens a new market. Not by
  editing the Phase 4 migration.
- **`default_currency`:** the currency normally used there — a convenience/default, not a
  constraint on what a client may price in.
- **Referenced by:** pricing groups, provider routing, capability config (all later phases),
  which will FK to `countries.id`.

### `provider_types`

The four payment providers Gomrok integrates with: Stripe, Mollie, PayPal, Ziraat Bank.

- **`requires_registration`:** Stripe/Mollie/PayPal need a product/price object created on their
  side before a package can be sold through them; Ziraat does not (it's charge-only).
- **`api_capable`:** Stripe/Mollie/PayPal expose an API; Ziraat is bank-hosted pages +
  webhooks/callbacks + manual status polling only.
- **Rows added:** by a migration when a new provider integration is built (a whole phase).
- **Referenced by:** provider accounts (Phase 9, per client), the capability catalogue (Phase 8),
  provider routing — all FK to `provider_types.id`.

---

## Cross-cutting (Phase 5)

Business tables (they carry `created_at` / `updated_at`), written by shared infrastructure rather
than any one module. `client_id` columns exist now; they become real foreign keys to `clients`
in Phase 6.

### `idempotency_keys`

One row per `(client_id, Idempotency-Key)` on a client write request. `IdempotencyMiddleware`
claims the row `processing` before running the handler, then flips it to `done` (recording the
created entity in `target_type` / `target_id`) or `failed`.

- **Rows added:** by the middleware, on the first write request carrying a given key. Registered
  per-route by the client-API layer in **Phase 7** — not yet on any route.
- **Replay:** a repeat of a `done` key returns the entity's *current* state (via an
  `IdempotentReplayResolver`, or a pointer body if none is registered). A repeat of a
  `processing` key → `409`. The same key with a different request body → `422`.
- **Expiry:** `expires_at = created_at + 24h`. An expired row is ignored on lookup (so
  correctness never depends on the purge) and deleted by `bin/PurgeIdempotencyKeys.php`
  (`composer idempotency:purge`), which will be scheduled once the job runner exists.
- **Referenced by:** nothing.

### `audit_logs`

Append-only history of sensitive admin / system actions — provider-config changes, secret
rotation, refunds, retries, pricing / voucher / country-override edits, permission changes.

- **Rows added:** explicitly, by the admin write paths that land from Phase 6 on, via
  `AuditLogWriter::record(AuditEntry)`.
- **`before` / `after`:** the whole target row as JSON, with secret-bearing keys replaced by
  `[redacted]` (`SecretRedactor`). `before` is null on create, `after` null on delete.
- **Never updated or deleted** by the application.
- **Referenced by:** nothing; the admin panel reads it.

### `error_logs`

Operator triage surface — the table behind the admin **Error Logs** screen (Phase 27).

- **Rows added:** only by the explicit `ErrorLogWriter::log(ErrorLogEntry)` — never by a log
  handler (decision Phase 5 Q3). Phase 5 wires one call site: `JsonErrorHandler` for unhandled
  HTTP exceptions. Provider / webhook / notification / job call sites are added in their phases.
- **`source`:** which subsystem caught the failure. **`context`:** structured operational detail
  (payment id, provider, …), redacted.
- **`resolved_at` / `resolved_by`:** set only from the admin panel's "mark resolved" action.
- **Writer failures are swallowed** (logged to stderr) so logging an error never masks it.
- **Referenced by:** nothing; the admin panel reads it.

Phase 6 added `fk_{idempotency_keys,audit_logs,error_logs}_client_id → clients(id)` —
`idempotency_keys` cascades on client delete, the two log tables null the column.

---

## Clients (Phase 6)

The tenant model. Every business row Gomrok creates from Phase 8 on will carry a `client_id`
pointing here.

### `clients`

One row per tenant. `slug` is the immutable public handle used in CLI, logs, and cross-module
config/fixtures; `id` is the foreign key everywhere.

- **Rows added:** by `bin/CreateClient.php` (→ `CreateClient` use case) or, for `local` /
  `testing`, by `ClientsSeeder` (the `local-dev` client). No admin UI yet (Phase 26).
- **`status`:** `active` / `disabled` — a soft, reversible switch (Phase 6 Q4). Disabling stamps
  `disabled_at/by/reason`; it does **not** touch the client's API keys. The Phase 7 auth
  middleware is the single place that rejects a request for a disabled client.
- **`notification_signing_secret`:** HMAC key for signing Gomrok→client callbacks. Never logged;
  the audit redactor masks it.
- **`default_currency` / `default_country`:** market defaults, FK-checked against the reference
  tables. A use case also rejects a currency/country that isn't a configured market before the
  FK would.
- **Referenced by:** `client_api_keys`, `client_endpoints`, the three cross-cutting tables, and
  every later business table.

### `client_api_keys`

One row per issued key. The token `gk_<mode>_<key_id>.<secret>` is shown once; only
`sha256(secret)`, the `prefix`, and `last_four` are kept (Phase 6 Q1).

- **Rows added:** by `CreateClient` (first key) and `IssueApiKey`. Never by hand — the generator
  needs a CSPRNG.
- **Auth (Phase 7):** parse the token → point-read by the unique `key_id` → constant-time
  compare `sha256(presented)` with `secret_hash` → check `status` / `expires_at` → stamp
  `last_used_at`.
- **Revocation** is a `status` flip + `revoked_at/by`; rows are never deleted (audit trail).
- **`key_id` is safe to log**; the secret and `secret_hash` are not.

### `client_endpoints`

Callback URLs. `UNIQUE (client_id, purpose)` — at most one active URL per purpose
(`payment_status`, `subscription_status`, `refund_status`).

- **Rows added:** by `SetClientEndpoint` (upsert per purpose); removed by `RemoveClientEndpoint`.
  The repository rewrites all of a client's endpoint rows on every `save()` (there are only a
  few).
- **Read by:** Notifications (Phase 24) when delivering a status callback.

---

## Client API authentication (Phase 7)

### `client_auth_attempts`

One append-only row per authentication attempt on `/api/v1` — both failures and successes.

- **Rows added:** by `ApiKeyAuthenticator` (via the `AuthAttemptLog` port → `PdoAuthAttemptLog`),
  best-effort — a write failure is logged and the request continues on its own merits.
- **`reason`:** the real cause of a failure. It is **never** returned to the caller (the API
  says only `401 unauthorized` / `403 client_disabled` — Phase 7 Q4); this table is where support
  and the admin panel see why.
- **`key_id`:** the parsed token id when the token was well-formed. The secret never reaches
  this table.
- **Read by:** the admin security view (Phase 26) and a future rate limiter (per-IP / per-key
  windows over `created_at`). No retention job yet.
- **Not** the same as `error_logs` — auth failures are expected traffic, not errors.

---

## Providers (Phase 8)

Three static tables describing **what each provider type can do in principle**. Nothing here is
per-client or per-account — that narrowing arrives in Phases 9–10.

### `provider_capabilities`

The catalogue of ~19 capability flags. **Seeded from the `Capability` enum** (`ProviderCapabilitiesSeeder`)
— the enum is the source of truth, the table is its mirror so join rows can carry an FK and the
admin panel has labels + a `capability_group` to render. An integration test asserts the two
stay in lock-step.

- **Not** where purchase types live — `one_time_payment` / `recurring_payment` / `auto_charge` /
  `subscription` are a separate `PurchaseType` enum (Phase 8 Q2).

### `provider_type_capabilities`

Join: which capabilities each `provider_types` row declares. **Seeded for stripe + paypal only**
(Phase 8 Q5, from `ProviderTypeDeclarations.json`); ziraat / mollie rows are added by their
adapter phases (23 / 22). When an adapter lands, its `getCapabilities()` must be a **subset** of
what this table declares — the DB is the ceiling.

### `provider_type_purchase_types`

Join: which purchase types each provider type supports. Seeded for stripe + paypal.

- **Read by:** `ProviderCapabilityResolver` (Phase 8), then `ProviderRouter` (Phase 10 — "filter
  candidate providers by requested purchase type / capability") and the admin panel (Phase 27).
- **Payment methods** are **not** a table yet (Phase 8 Q4) — `provider_type_method_capabilities`
  is deferred to Phase 9/12; until then method nuance ("Mollie PayPal can't do subscriptions")
  is the in-code `MethodCapabilityRules` placeholder.

---

## Providers — accounts (Phase 9)

A client's live/test credentials for a provider. Business tables (timestamps). Decisions:
`PhaseResults/PhaseDecisions.md` Phase 9 Q1–Q5.

### `provider_accounts`

- **Rows added:** by `bin/CreateProviderAccount.php` (→ `CreateProviderAccount` use case) or, for
  `local` / `testing`, by `ProviderAccountsSeeder`. No admin UI yet (Phase 27).
- **`mode`** (`live` / `test`) is a hard boundary — the Phase 10 router derives the request mode
  from the authenticated API key's `gk_live` / `gk_test` prefix and never resolves a mismatched
  account.
- **`secret_ciphertext`** — the provider secret key, encrypted with
  `Shared\Application\SecretCipher` (`SodiumSecretCipher`, libsodium, key from
  `APP_ENCRYPTION_KEY`). Only ever decrypted via `ProviderAccountCredentials` (used by adapters
  in Phases 21–23). `secret_last_four` is the only part shown anywhere. Secret rotation is its
  own audited use case.
- **`public_key`** is not a secret — stored plaintext.
- **Referenced by:** `provider_account_endpoints` / `_countries` / `_methods`; later `payments`,
  `subscriptions`, `webhook_events`.

### `provider_account_endpoints`

Verification config for inbound provider messages. `webhook` / `callback` kinds get a random
`token` used in `/api/v1/webhooks/{provider}/{token}` (consumed by the Webhooks module, Phase
25); `return` kind is just a redirect flag.

- **Rows added:** by `bin/AddProviderAccountEndpoint.php` (→ `AddProviderAccountEndpoint`).
  Adding an active endpoint of a kind **deactivates** the previous active one of that kind —
  rows are never deleted, so a rotated token's history stays.
- **`signing_secret_ciphertext`** — the provider's webhook signing secret / Ziraat callback hash
  key, `SecretCipher`-encrypted. Null for `return`.

### `provider_account_countries` / `provider_account_methods`

Which markets / payment methods the account serves — the Phase 10 router's country/method
filter. `country_code` FKs to `countries` (an account can only serve a configured market).
Account-level capability narrowing is **not** modelled (Q4) — an account inherits its provider
type's capabilities.

## Providers — routing (Phase 10)

Country → provider routing config, all client-scoped. Per Phase 10 Q1 provider groups are the
**only** mechanism — there are no `country_provider_configs` tables. The resolver
(`Modules\Providers\Application\Routing\ProviderRouter`) reads these tables, never writes; it
returns an in-memory `RoutingDecision` and never downgrades an unsupported request.

### `provider_groups`

A client's routing rule. Resolution: the client's non-default `active` group whose
`provider_group_countries` contains the request country and whose `device_type` matches (or is
NULL) → else the client's `is_default` group for that device → else a `no_group_for_market`
error.

- **Rows added:** by `bin/CreateProviderGroup.php` (→ `CreateProviderGroup`). The admin panel
  (Phase 27) is the eventual UI.
- **`is_default`** — the fallback; carries no `provider_group_countries` rows. One per
  `(client, device_type)`, app-enforced.
- **`currency_code`** — usually NULL (pricing groups own currency from Phase 13); set it only to
  hard-gate a single-currency market now.
- **`status = disabled`** — the router skips the group; its countries fall through to the default.
- **Referenced by:** the four child tables below.

### `provider_group_countries`

The markets a non-default group covers. `country_code` FKs to `countries`. App-enforced: a
country sits in at most one non-default group per `(client, device_type)` — the
`ConfigureProviderGroup` handler rejects an overlap.

### `provider_group_accounts`

The group's ordered provider-account list. `priority` ascending = tried first; the router keeps
the survivors in that order as the fallback chain on the `RoutingDecision`. `is_enabled = 0`
parks an entry. App-enforced: the linked account belongs to the group's client.

- **Rows set:** by `bin/SetProviderGroupAccounts.php` (→ `SetProviderGroupAccounts`) — a full
  replace.

### `provider_group_purchase_types` / `provider_group_methods`

Group-level enablement (Phase 10 Q2). The router keeps an account only if the requested purchase
type is in **both** the group set and the account's provider-type declaration — so Turkey =
`one_time_payment` only makes a subscription request fail even though Stripe could do it. Empty
purchase-type set = nothing sellable (fail closed); empty method set = any method the account
supports (fail open). Set by `bin/ConfigureProviderGroup.php` (→ `ConfigureProviderGroup`).

## Packages — catalog & availability (Phase 11)

The client-owned catalogue. Business tables (timestamps). All client-scoped through
`packages.client_id`.

### `packages`

One row per catalogue entry. `code` is unique **per client** (`UNIQUE (client_id, code)`) — the
same `code` recurs across clients. There is no global catalogue and no `client_packages`
junction.

- **Rows added:** by `bin/CreatePackage.php` (→ `CreatePackage`). Edited via `UpdatePackage`
  (name / description / metadata), `ChangePackageStatus` (disable / enable), `SetPackageAvailability`.
- **`status = disabled`** is the per-client hide switch — a disabled package never appears in a
  `PackageCatalog::resolve()` list. There is **no** `packages.enable_for_client` permission;
  creating / status-toggling the row *is* the enable/disable action.
- **`metadata`** — free-form client JSON, validated as an object at the use-case boundary,
  stored verbatim, never queried by Gomrok. Trial config, `durationMonths`, `badge`,
  `highlighted`, purchase capabilities and pricing are **not** here — Phases 12–13 add them.
- **Referenced by:** the four availability child tables; later `default_package_prices`,
  `package_purchase_capabilities`, payment records.

### `package_countries` / `package_currencies` / `package_payment_methods` / `package_provider_accounts`

The four availability dimensions (Phase 11 Q1). Each is **fail open** — an empty set means
"available everywhere for that dimension" (Q2); rows restrict. `country_code` / `currency_code`
FK to the reference tables; `payment_method` is an app-enforced `PaymentMethod` value (no lookup
table, like `provider_group_methods`); `provider_account_id` FKs to `provider_accounts` and the
`SetPackageAvailability` handler additionally checks the account belongs to the package's client.
`PackageCatalog::resolve()` narrows a package's provider-account set to the client's **active**
accounts before returning it.

## Packages — capabilities & provider definitions (Phase 12)

### `package_purchase_capabilities`

What a package can be **sold as** — a subset of `one_time_payment` / `recurring_payment` /
`auto_charge` / `subscription`, with per-type config. Unlike availability, this is **fail
closed**: a package with zero rows is not sellable and `PackageCatalog::resolve()` drops it.

- **Rows set:** by `bin/SetPackageCapabilities.php` (→ `SetPackagePurchaseCapabilities`) —
  full replace.
- **`has_trial` / `trial_days`** — a trial is domain-valid only for `subscription` /
  `recurring_payment`; `has_trial = 0` forces `trial_days` NULL.
- **`duration_months`** — entitlement length per purchase (NULL = open-ended / provider-defined).

### `package_country_purchase_capabilities`

Per-country override. When any row exists for `(package, country)` the listed types **replace**
the package's global set for that country; no rows ⇒ inherit. A row's type must be in the global
set — an override narrows, never widens. Set by `bin/SetPackageCountryCapabilities.php`
(→ `SetPackageCountryPurchaseCapabilities`). The full payment-flow resolution (Phase 17) is:
package global caps → country override → ∩ provider-group purchase types → ∩ provider-type
declaration.

### `package_provider_definitions`

Where a package exists on one provider account's side (a Stripe Product, PayPal plan, or
nothing). One row per linked `(package, provider account)`, created lazily by
`bin/LinkPackageProvider.php` (→ `LinkPackageProvider`).

- **`sync_state`** — `not_created` (no remote yet) → `synced` (`remote_id` set, current) →
  `drift` (local package edited since) → or `not_needed` (provider has no product model, e.g.
  Ziraat redirect). Transitions: `LinkPackageProvider` / `ChangePackageProviderSyncState`
  (`markSynced` / `markNotNeeded`); the **drift sweep** in the package-editing handlers flips
  every `synced` row of that package to `drift` in the same transaction.
- **`remote_id`** — provider product/plan id, **indexed** for reverse lookup from a provider
  webhook (Phase 25). Accepted manually now; provider-API creation lands per adapter (21–23).
- **App-enforced:** the provider account belongs to the package's client.

## Pricing — groups & default prices (Phase 13)

The baseline of pricing. Phase 14 layers dimension overrides, Phase 15 A/B lists. All
client-scoped.

### `pricing_groups`

A client's country grouping **for pricing** — separate from Phase 10 provider groups (routing).
Priority-ordered; a country can be in several groups and the lowest `priority` wins (so a
"Global iOS" overlay can shadow a regional group for one device). `is_default` is the fallback:
no `pricing_group_countries` rows, resolved last regardless of stored priority, and **cannot be
disabled**.

- **Rows added:** `bin/CreatePricingGroup.php` (→ `CreatePricingGroup`); order set by
  `ReorderPricingGroups` (priority 1..n, default always last).
- **`currency_code`** — one per group. `status=default` packages in a different baseline
  currency are converted (see `client_exchange_rates`).
- **App-enforced:** `priority` unique per client; one `is_default` per client.

### `pricing_group_countries`

The markets a non-default group covers. **Overlap across a client's groups is allowed** — unlike
`provider_group_countries` (Phase 10), which is exclusive.

### `default_package_prices`

One baseline row per package (`UNIQUE (package_id)`). `amount_minor` in the currency's minor
unit. Set by `bin/SetDefaultPackagePrice.php`. A package with no row → `pricing.no_default_price`
when a `status=default` group tries to price it.

### `client_exchange_rates`

Client-configured, **effective-dated** FX. `1 base_currency = rate quote_currency`. The most
recent row with `effective_from <= now` for the pair wins. Used **only** for a `status=default`
cross-currency resolve; `status=override` rows carry their own amount and are never converted.
No rate for a needed pair → `pricing.no_exchange_rate`. Set by `bin/SetClientExchangeRate.php`.

### `pricing_group_packages`

Per `(pricing group, package)`. **No row = implicit `status=default`.** `status=override` carries
`amount_minor` + `currency_code` (must equal the group currency — domain-enforced);
`status=disabled` hides the package in that group. `name_override` / `badge_override` /
`highlighted_override` refine the display; `display_order` is the customer-facing position. Set
by `bin/SetPricingGroupPackage.php`. `PriceResolver` reads all of this and returns a
`ResolvedPrice` with `source` = `baseline` / `converted` / `group_override`.
