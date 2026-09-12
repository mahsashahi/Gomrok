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

The baseline of pricing. Phase 14 (`price_rules`, below) layers dimension overrides, Phase 15 A/B lists. All
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

---

## Pricing — dimension overrides (Phase 14)

The layer *above* the Phase 13 base price. One table.

### `price_rules`

One `(client, package)` override table keyed by **7 nullable dimensions** — `pricing_group_id`,
`country_code`, `provider_account_id`, `payment_method`, `purchase_type`,
`subscription_interval`, `currency_code`. A null dimension is a wildcard. Every non-null
dimension of a rule must equal the request for the rule to match. `UNIQUE` across all 7
dimensions + `package_id` (`uniq_price_rules_dimensions`); because MySQL treats NULLs as
distinct in a unique index, the upsert path (`PdoPriceRuleRepository::findByDimensions`) matches
with the null-safe `<=>` operator instead of `ON DUPLICATE KEY`.

- **`is_available`** — `1` (default) with `amount_minor` set overrides the price; `0` with
  `amount_minor` NULL marks the combination **not for sale**.
- **Domain guards** (`PriceRule::validate`): an interval needs a subscription/recurring
  `purchase_type`; available needs an amount; available needs a `pricing_group_id` **or**
  `currency_code` pinned (so a bare "card = €25" rule must also pin a currency); unavailable
  must not carry an amount.
- **Handler guards** (`SetPriceRuleHandler`): every dimension is checked to belong to the
  client (group, provider account, country, currency); a pinned group + currency must agree
  (`price_rule.currency_mismatch`).
- **Resolution** — `PriceResolver` resolves the Phase 13 base first (which fixes the pricing
  group + currency), then `PriceRuleResolver` picks the winning rule: **most matched dimensions**
  → the fixed dimension priority `subscription_interval > purchase_type > payment_method >
  provider_account_id > currency_code > country_code > pricing_group_id` → highest `id`. An
  available winner replaces the amount (`ResolvedPrice.source = dimension_override`,
  `applied_rule_id` + `applied_dimensions` populated); an unavailable winner fails the resolve
  with `pricing.combination_unavailable` (**no fallback** to the base price). A currency-pinned
  rule only matches inside a group of that currency.
- **Set / removed by** `bin/SetPriceRule.php`, `bin/DeletePriceRule.php`, listed by
  `bin/ListPriceRules.php`. Exposed through `GET /api/v1/pricing/resolve?...&method=&purchase_type=&interval=`.
- **Referenced by:** nothing yet (payments snapshot the resolved price from Phase 19+).

---

## Pricing — A/B price lists (Phase 15)

Price experiments inside a pricing group, layered between the Phase 13 base amount and the Phase
14 `price_rules` step. Two tables.

### `price_lists`

One row per A/B list in a pricing group. Exactly one is the **control** (`is_control = 1`,
`factor` pinned at `1.0000`, `is_enabled` always `1`, undeletable). `UNIQUE (pricing_group_id,
name)`.

- **`factor DECIMAL(6,4)`** — the list's multiplier on the resolved base amount
  (`base × factor`, HALF_EVEN). `0.9000` = −10%. The control list is always `1.0000`.
- **Domain guards** (`PriceList`): a non-control factor is a positive decimal with ≤2 integer +
  ≤4 fractional digits (`price_list.invalid_factor` / `price_list.non_positive_factor`); the
  control list rejects `disable()` (`price_list.cannot_disable_control`) and `changeFactor()`
  (`price_list.control_factor_locked`).
- **Handler guards** (`CreatePriceListHandler`): the pricing group belongs to the client; the
  name is free in the group and is not the reserved control name.
- **Created:** the control row is written by `CreatePricingGroupHandler` (same transaction as
  the group) and backfilled for pre-existing groups by migration `20260910160001`. Experiment
  lists via `bin/CreatePriceList.php`; status / factor via `bin/SetPriceListStatus.php` /
  `bin/SetPriceListFactor.php`; listed by `bin/ListPriceLists.php`.

### `price_list_packages`

An exact price for one package on one **non-control** list — overrides that list's `factor` for
that package. `UNIQUE (price_list_id, package_id)`.

- **Handler guards** (`SetPriceListPackagePriceHandler`): the list is not control
  (`price_list.control_has_no_package_prices`); the package belongs to the client; the amount is
  positive; the currency equals the list's pricing-group currency
  (`price_list_package.currency_mismatch`).
- **Set by** `bin/SetPriceListPackagePrice.php`.

### Resolution (`PriceListResolver` → `PriceResolver::resolve`)

After the base amount, `PriceResolver` calls `PriceListResolver::apply(groupId, packageId,
?priceListId, base)`. The list is the one named by `$priceListId` **if** it is in the group and
enabled — otherwise the group's **control** list (this is the disable-fallback). Then: exact
`price_list_packages` amount → else `base × factor` → else base unchanged. `ResolvedPrice`
always carries `price_list_id` / `price_list_name` / `price_list_factor`; `source` becomes
`price_list` only when the amount actually moved.

**`$priceListId` is `null` for every caller in Phase 15** — the visitor→list assignment (the
`price_list_assignments` table + hashing service + `visitor_ref` params) is deferred to Phase 24
(Phase 15 Q4/Q5). So every resolve currently uses the control list; the machinery is in place
for Phase 24 to pass a real list id.

- **Referenced by:** nothing yet (Phase 24 assignment; payment snapshots later).

---

## Vouchers — definitions & eligibility (Phase 16)

Voucher definitions and the eligibility gate. **`.claude/Voucher.md` is the source of truth for
all voucher behaviour** — this section is the per-table summary; read the two together.

### `vouchers`

Client-scoped (`UNIQUE (client_id, code)`, `code` upper-case). Carries the **default discount**
(`default_discount_type` `none`/`percentage`/`full` + `default_percent_bp` — **never `fixed`**,
because a fixed amount is inherently currency-bound and only exists as a
`voucher_currency_discounts` override), the validity window, `first_purchase_only`, an optional
minimum purchase (amount + currency, both or neither), and the three **usage-limit columns**
(Phase 16 Q3 — the user chose columns over a child table): `max_total_redemptions` /
`max_per_user` / `max_per_client`, each **nullable = unlimited** for that dimension. The
canonical "valid for everyone, once per user" voucher is `NULL / 1 / NULL`. `redeemed_count`
is the global tally — a `vouchers` column, default `0`, that only **Phase 17** ever increments
(this module reads it for the global cap check).

### `voucher_eligibility_rules`

One row per `(voucher, dimension, value)` — `country` / `currency` / `package` /
`provider_account` / `payment_method` / `purchase_type` / `subscription_interval`. Several rows
for one dimension OR together; different dimensions AND together; **no rows for a dimension = it
imposes no restriction**. `package` and `provider_account` values are validated against the
voucher's client at write time (`SetVoucherEligibilityHandler`) since `value` is a plain VARCHAR
with no DB FK. Full-replace only — there's no incremental add/remove of a single rule
(`VoucherEligibilityRuleRepository::replaceForVoucher`).

### `voucher_currency_discounts`

The **per-currency override** of the voucher's default discount (Phase 16 Q2 — extended by the
user from a plain per-currency-amount table to "default + override"). A currency using the
default carries no row here. Each row fully specifies its own `discount_type` — an override can
be a different kind from the default (e.g. default 5% but a `TRY` row is `fixed`). `max_discount_minor`
only makes sense with `percentage` and is expressed in that row's own currency's minor units —
there is no cap on the currency-agnostic default, because a cap is inherently currency-specific.

### Resolution

**Discount** for a checkout in currency X: `voucher_currency_discounts` row for X → else the
voucher's default (`percentage`/`full`) → else (`none`) the voucher is inapplicable in X — this
*is* one of the eligibility checks (`voucher.no_discount_for_currency`), not a separate lookup.

**Eligibility** (`VoucherEligibilityEvaluator`, Phase 16 Q4 + Phase 17) reports **every** unmet
condition in one pass, not just the first: status, validity window, client scope, each
restricted dimension, discount applicability for the checkout currency, minimum purchase (only
compared when the checkout currency equals `min_purchase_currency` — no FX conversion in this
check), first-purchase (an *unknown* `isFirstPurchase` is its own reason,
`voucher.first_purchase_unknown`, distinct from a *known-false* one), and — since Phase 17 — the
global, per-user, and per-client usage caps via `VoucherUsagePort`.

- **Set / removed by** `bin/CreateVoucher.php`, `UpdateVoucher.php`, `SetVoucherEligibility.php`,
  `SetVoucherCurrencyDiscount.php`, `RemoveVoucherCurrencyDiscount.php`,
  `SetVoucherUsageLimits.php`, `SetVoucherStatus.php`; listed by `ListVouchers.php`.
- **Referenced by:** `voucher_redemptions` (Phase 17, below); `POST /api/v1/vouchers/validate`
  (Phase 19); the payment/subscription voucher-decision snapshot (Phase 18).

---

## Vouchers — redemption lifecycle (Phase 17)

Discount calculation and the reserve → confirm/release lifecycle. One table.

### `voucher_redemptions`

Identified by `(voucher_id, attempt_reference)` — `attempt_reference` is an opaque,
caller-supplied string (Phase 17 Q1); Phase 20 will pass the real payment id once Payments
exists, with no schema or handler change needed then.

- **`status`** — `reserved` (counts toward every cap) → terminal `confirmed` (permanent,
  increments `vouchers.redeemed_count` once) or terminal `released` (frees the cap; no counter
  to decrement — a released row simply stops matching the `reserved`/`confirmed` filter every
  cap query uses). No automatic expiry of an abandoned `reserved` row in this phase — that sweep
  is a **Phase 29** background job (`reserved_at` / `INDEX (status)` are here for it).
- **`price_minor` / `nominal_discount_minor` / `applied_discount_minor` / `payable_minor`** — a
  snapshot from `VoucherDiscountCalculator` at reservation time. `nominal` is the discount rule's
  raw value; `applied` is after the merchant-configured `max_discount_minor` cap (percentage
  only) and then the hard price-floor clamp — the two figures differ only when one of those
  clamps actually fired, which is exactly when you'd want to see it in a later audit/snapshot.
- **Concurrency** (Phase 17 Q3): `ReserveVoucherRedemptionHandler` / `ConfirmVoucherRedemptionHandler`
  / `ReleaseVoucherRedemptionHandler` all open a transaction and immediately
  `VoucherRepository::findByIdForUpdate` the parent `vouchers` row (`SELECT ... FOR UPDATE`)
  before touching this table — since every writer does this first, the `vouchers` row is a
  de facto per-voucher mutex. Reserve re-runs `VoucherEligibilityEvaluator` (which now includes
  the usage checks) **inside** that lock, so the eligibility re-check and the insert are atomic
  with respect to any other reserve/confirm/release for the same voucher.
- **Idempotency:** Reserve looks up `(voucher_id, attempt_reference)` first and, if found,
  returns it unchanged (whatever its current status) instead of re-validating — a client/webhook
  retry of the same attempt is always a no-op. Confirm/Release are similarly idempotent on their
  own terminal state, but reject the *other* terminal transition
  (`voucher_redemption.already_released` / `voucher_redemption.already_confirmed`).
- **Set / advanced by** `bin/ReserveVoucherRedemption.php`, `ConfirmVoucherRedemption.php`,
  `ReleaseVoucherRedemption.php`; listed by `ListVoucherRedemptions.php`.
- **Referenced by:** `voucher_decision_snapshots.voucher_redemption_id` (Phase 18, `UNIQUE` —
  at most one decision snapshot per redemption). Payments passing a real payment id as
  `attempt_reference` is still Phase 20.

---

## Checkout + decision snapshots (Phase 18)

The new `Checkout` module's anchor table, plus one write-once decision-snapshot table per owning
module (Pricing / Vouchers / Providers). Source of the full design: `.claude/docs/database-design.md`
→ "Checkout + decision snapshots (Phase 18)"; this section is the narrative "why".

### `checkout_attempts`

- **Why it exists at all** (Phase 18 Q1, a user-directed expansion of the original proposal): a
  payment doesn't materialize atomically — pricing gets resolved, a voucher may get reserved, a
  provider gets chosen, the customer may get redirected and come back — and every one of those
  steps can fail or be abandoned *before* a `payments` row would ever exist. Without this table
  that whole pre-payment story is invisible: no abandoned-checkout tracking, no way to see "how
  far did this customer get", nothing for admin debugging or reconciliation to look at.
- **`attempt_reference` vs `id`** — exactly the Phase 17 `voucher_redemptions.attempt_reference`
  pattern, one layer up: `attempt_reference` is the opaque, caller-supplied idempotency key
  (`UNIQUE (client_id, attempt_reference)` — re-`start()` with the same string returns the
  existing attempt untouched); `id` is the internal relational anchor every decision-snapshot
  table FKs to. `ReserveCheckoutVoucherHandler` reuses the *same string* as the voucher
  redemption's own `attempt_reference`, so both records key off one caller-supplied value.
- **`status`** — see the `CheckoutAttemptStatus` lifecycle below. `error_code` / `error_message`
  are only ever set on a `failed` exit. `abandoned_at` / `expired_at` are reserved columns — no
  handler writes them yet; they exist now so the Phase 29 automatic-abandonment sweep needs no
  migration when it lands.
- **Set / advanced by** `bin/CreateCheckoutAttempt.php`, `ResolveCheckoutPricing.php`,
  `ReserveCheckoutVoucher.php`, `SelectCheckoutProvider.php`, `SetCheckoutAttemptStatus.php`;
  listed by `ListCheckoutAttempts.php`.
- **Referenced by:** the three decision-snapshot tables below, each `checkout_attempt_id FK
  CASCADE`. A future `payments` row (Phase 20) will also reference it, and will copy — never
  re-derive — `CheckoutAttempt::commercialSnapshot()`'s fields at the moment of conversion.

### Lifecycle (`CheckoutAttemptStatus`, Phase 18 Q3)

A monotonic-rank state machine, specified in full by the user rather than picked from the
proposed options: 9 ranked happy-path statuses (`started` … `converted_to_payment`) plus 4
unranked exit statuses (`failed` / `canceled` / `expired` / `abandoned`). A transition is legal
when it repeats the current status (no-op), or targets an exit (allowed from any non-terminal
status), or `converted_to_payment` from exactly `confirmed`, or any strictly-higher rank —
**skipping ranks is allowed**, which is the whole point: a checkout with no voucher jumps
`pricing_resolved → provider_selected` directly rather than being forced through
`voucher_reserved`. Once terminal (`converted_to_payment` or any exit), the row is frozen —
`transitionTo()` rejects everything, including a repeat of the same terminal status.

Only the transitions the user explicitly asked for in Phase 18 have a real caller: `started →
pricing_resolved`, `pricing_resolved → voucher_reserved` (voucher used) or directly `→
provider_selected` (no voucher), `voucher_reserved → provider_selected`, the same-status no-op,
and any non-terminal → exit. `provider_checkout_created` through `converted_to_payment` are fully
modelled (rank, guards, tests) but wait for Payments (Phase 20) and real provider adapters
(Phase 21+) to actually drive them — the user was explicit that these should be "ready" now, not
built out ahead of the phases that need them.

### `pricing_decision_snapshots` / `voucher_decision_snapshots` / `provider_routing_decision_snapshots`

- **Why three tables, not one** (Phase 18 Q4): each snapshot's shape depends on a value object
  that already lives in its own module's Application layer — `ResolvedPrice` (Pricing),
  `RoutingDecision` (Providers, reused from Phase 10 as-is) — so the snapshot VO and its
  repository port live beside that type instead of crossing the dependency direction the other
  way. `voucher_decision_snapshots` stays in the Vouchers module for the same reason, but is
  deliberately **thin**: the actual amounts already live, immutably, on `voucher_redemptions`
  (Phase 17), so duplicating them here would just be a second copy to keep in sync. It stores only
  the voucher's *identity at decision time* (`voucher_code` / `voucher_name`), because a later
  `UpdateVoucher` rename must not rewrite history.
- **Write-once by construction** — none of the three repository ports expose an update method,
  only `save(): int` and `findByCheckoutAttemptId()`. This is the architectural enforcement of "a
  pricing/voucher/routing rule change must never rewrite a past decision" (the same principle
  Phase 13–17 already apply to `default_package_prices` snapshots being resolved fresh each time,
  taken one step further: here the *resolved result itself* is frozen). `UNIQUE
  (checkout_attempt_id)` on each backs this up at the schema level — at most one decision of each
  kind per attempt, ever.
- **`payload JSON`** on the pricing and routing snapshots is the full `ResolvedPrice::toArray()` /
  `RoutingDecision::toArray()` — everything the resolver considered, not just the winning
  numbers — so a later admin view or dispute investigation can see *why* a decision was made, not
  only what it was. `voucher_decision_snapshots` has no `payload` column; there was nothing left
  to denormalize once the amounts stayed on `voucher_redemptions`.
- **Set / advanced by** `ResolveCheckoutPricingHandler`, `ReserveCheckoutVoucherHandler`,
  `SelectCheckoutProviderHandler` — each runs inside the same `Transactions::run()` call that
  advances the parent `checkout_attempts.status`, so a snapshot row and its status transition
  commit or roll back together.
- **Referenced by:** `payments` (Phase 20) reads `pricing_decision_snapshots` and
  `voucher_decision_snapshots` at creation time to derive `payments.amount_minor` — it takes only
  the number, never ownership of the rows themselves.

---

## Payments — aggregate & lifecycle (Phase 20)

The `Payments` module. No real provider adapter exists yet (Phase 21+), so every status
transition here is caller-supplied, not derived from an actual provider response — this phase is
purely the aggregate, schema, and lifecycle.

### `payments`

- **Why it can only come from a confirmed checkout attempt** (Q1): the user was explicit that
  every payment should be able to answer "which checkout attempt produced you" the same
  unambiguous way, and `checkout_attempts.status = confirmed` → `converted_to_payment` was
  designed in Phase 18 specifically for this hand-off — `payments.checkout_attempt_id` is a
  required, `UNIQUE` FK, never nullable, never populated any other way.
- **`amount_minor` is frozen, not re-derived** — `CreatePaymentHandler` reads
  `pricing_decision_snapshots.amount_minor` for the base case, or
  `voucher_redemptions.payable_minor` (via the linked `voucher_decision_snapshots` row) when a
  voucher was used, and copies that one number onto the payment permanently. A later pricing or
  voucher-discount rule change must never change what a customer already paid.
- **`status`** — see the lifecycle section below. `error_code` / `error_message` are only ever
  set on a `failed` transition.
- **Set / advanced by** `CreatePaymentHandler`, `RecordProviderTransactionHandler`,
  `ChangePaymentStatusHandler`; listed by `bin/ListPayments.php`.
- **Referenced by:** `payment_attempts.payment_id`, `gateway_references.payment_id` (nullable).

### Lifecycle (`PaymentStatus`, Phase 20 Q2)

Unlike `CheckoutAttemptStatus`'s single linear rank, a payment's lifecycle genuinely branches —
`paid` can move to `refunded`, `partially_refunded`, *or* `disputed`, and a dispute can resolve
back to `paid` or escalate to `chargeback` — so each status carries its own explicit set of legal
next-statuses (`PaymentStatus::allowedNextStatuses()`) instead of a rank number.

This decision had a real back-and-forth worth recording: when first asked, the option initially
selected was "no rule engine yet — `transitionTo()` accepts anything, validation deferred to
Phase 21+." That was flagged immediately as self-defeating, because this phase's own exit
criterion is "the state machine and rejection of illegal transitions tested" — with no rule at
all, there is nothing to reject and the criterion becomes unsatisfiable. The explicit
adjacency-list design (below) was adopted instead once that conflict was raised.

```text
created            → pending, canceled, failed
pending            → requires_action, authorized, paid, failed, canceled, expired
requires_action    → authorized, paid, failed, canceled, expired
authorized         → paid, canceled, expired, failed
paid               → refunded, partially_refunded, disputed
partially_refunded → refunded, disputed
disputed           → chargeback, paid   (resolved in the merchant's favor)
refunded, canceled, expired, failed, chargeback → terminal
```

`transitionTo()` checks terminal *before* same-status, exactly like `CheckoutAttemptStatus` (Phase
18) — so even a repeat call of the current status is rejected once a payment is terminal, not
treated as a no-op. This mirrors the Phase 18 precedent deliberately, for consistency across the
two state machines in the codebase.

### `payment_attempts` / `provider_transactions`

- **Why two tables, not one** (Q3): CLAUDE.md names `payments`, `payment attempts`, and `provider
  transactions` as three distinct Required Database Concepts, and merging the last two would blur
  "the customer retried with a different card" (a new attempt) from "the provider made two calls
  for one try" (two transactions under the same attempt, e.g. a separate authorize and capture).
  `payment_attempts.status` is a small, separate enum (`started`/`succeeded`/`failed`) from the
  parent `PaymentStatus` — an attempt only ever answers "did this try work."
- **`attempt_number`** is app-assigned, not a DB auto-increment scoped per payment —
  `RecordProviderTransactionHandler` reuses the payment's latest attempt while it's still
  `started`, and only opens `attempt_number + 1` once the previous one has been explicitly
  completed (`succeeded`/`failed`, via the `attemptOutcome` parameter — never inferred from the
  payment's own status change, since a still-in-progress attempt can legitimately see several
  status-advancing transactions in a row).
- **`provider_transactions` is write-once** — no `updated_at`, no update method on the repository
  port; it is the immutable log of what the provider actually said, kind by kind
  (`authorize`/`capture`/`refund`/`void`/`status_check`/…, a generic label, not FK'd to any
  provider-specific type). `provider_status_raw` is the **unmapped** provider status string,
  stored safely and never leaked into `PaymentStatus` — each Phase 21+ adapter owns translating
  it into a `newStatus` value the handler can validate.
- **Set / advanced by** `RecordProviderTransactionHandler` only.

### `provider_customers` / `gateway_references`

- **Why two tables, not one** (Q4): a `provider_customers` row is a *durable identity* — reused
  across many future payments/subscriptions for the same `(client, client user, provider
  account)` — while a `gateway_references` row points at one specific transaction/session/order.
  Conflating them would mean a customer id and a one-off checkout-session id living in the same
  table with very different lifetimes and reuse patterns.
- **`gateway_references` is deliberately generic** — `reference_type` is a small, provider-agnostic
  enum (`checkout_session`/`payment_intent`/`order`/`transaction`/`subscription`/`customer`/`other`)
  rather than one column per provider's id kind. This is exactly what CLAUDE.md's Gateway
  Reference Lookup Rule asks for: given *any* provider webhook's raw reference string, find the
  client/payment it belongs to via one `findByReference(providerAccountId, type, value)` call,
  with no schema change needed when Phase 22/23 bring Mollie/PayPal/Ziraat's differently-shaped
  ids.
- **No `subscription_id` column yet** — `subscriptions` doesn't exist until Phase 26; per the
  project's incremental-schema strategy, it's added there as an additive, nullable column rather
  than reserved now against a table that doesn't exist.
- **Set / advanced by** `LinkProviderCustomerHandler` (idempotent by `(provider_account_id,
  provider_customer_id)`, `conflict` if the same provider customer id is claimed by a different
  client); `gateway_references` rows are expected to be written by `RecordProviderTransactionHandler`
  once Phase 21+'s adapters actually return reference ids to record (no dedicated handler yet in
  Phase 20 — the repository and schema exist ahead of a real caller, the same pattern used for
  `checkout_attempts.abandoned_at`/`expired_at` in Phase 18).
