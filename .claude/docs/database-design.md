# Database design — canonical spec

The authoritative description of Gomrok's schema. **Living document** — every migration updates
this file, `database-diagram.md` (+ `.html`), and `db_explain.md` in the same change
(`.claude/Rule.md` §5). If this and a migration disagree, fix the migration to match this, or
this to match an agreed change.

The schema is built **incrementally** — one slice per phase, gated by confirmation. This file
grows as phases land. See `.claude/docs/Phases.md` → *Database strategy*.

## Conventions

- **Primary key:** every table has `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  (values from 1). **Never `BIGINT`.**
- **Foreign keys:** plain `INT UNSIGNED` for entity references. Natural-key FKs (e.g. a 3-char
  currency code) are used only where noted.
- **No ULID / UUID / typed-ID classes.** The `id` is used directly in DB, PHP, and URLs.
- **Engine / charset:** InnoDB, `utf8mb4`, `utf8mb4_0900_ai_ci`.
- **Timestamps:** business tables get `created_at DATETIME NOT NULL` + `updated_at DATETIME NULL`
  (UTC). **Reference / lookup tables have no timestamps** — they are static, seeded data.
- **Money:** `amount_minor BIGINT NOT NULL` + `currency CHAR(3) NOT NULL`. `BIGINT` here is
  deliberate (minor-unit totals can exceed `INT`); the "no BIGINT" rule is keys-only.
- **Index names:** `idx_<table>_<cols>`, `uniq_<table>_<cols>`. **FK names:** `fk_<table>_<col>`.
- Full rules: `.claude/agents/DatabaseAgent.md`.

## Table count

| Group | Tables |
| --- | --- |
| Reference data (Phase 4) | `currencies`, `countries`, `provider_types` — **3** |
| Cross-cutting (Phase 5) | `idempotency_keys`, `audit_logs`, `error_logs` — **3** |
| Clients (Phase 6) | `clients`, `client_api_keys`, `client_endpoints` — **3** |
| Client API auth (Phase 7) | `client_auth_attempts` — **1** |
| Providers — capabilities (Phase 8) | `provider_capabilities`, `provider_type_capabilities`, `provider_type_purchase_types` — **3** |
| Providers — accounts (Phase 9) | `provider_accounts`, `provider_account_endpoints`, `provider_account_countries`, `provider_account_methods` — **4** |
| Providers — routing (Phase 10) | `provider_groups`, `provider_group_countries`, `provider_group_accounts`, `provider_group_purchase_types`, `provider_group_methods` — **5** |
| Packages — catalog & availability (Phase 11) | `packages`, `package_countries`, `package_currencies`, `package_payment_methods`, `package_provider_accounts` — **5** |
| Packages — capabilities & provider defs (Phase 12) | `package_purchase_capabilities`, `package_country_purchase_capabilities`, `package_provider_definitions` — **3** (+ `badge` / `highlighted` / `client_package_id` columns on `packages`) |
| Pricing — groups & default prices (Phase 13) | `pricing_groups`, `pricing_group_countries`, `default_package_prices`, `client_exchange_rates`, `pricing_group_packages` — **5** |
| Pricing — dimension overrides (Phase 14) | `price_rules` — **1** |
| Pricing — A/B price lists (Phase 15) | `price_lists`, `price_list_packages` — **2** (visitor assignment table listed under Phase 24) |
| Vouchers — definitions & eligibility (Phase 16) | `vouchers`, `voucher_eligibility_rules`, `voucher_currency_discounts` — **3** |
| Vouchers — redemption lifecycle (Phase 17) | `voucher_redemptions` — **1** |
| Checkout + decision snapshots (Phase 18) | `checkout_attempts` (Checkout module) + `pricing_decision_snapshots` (Pricing) + `voucher_decision_snapshots` (Vouchers) + `provider_routing_decision_snapshots` (Providers) — **4** |
| Resolution API endpoints (Phase 19) | (no new tables) |
| Payments — aggregate & lifecycle (Phase 20) | `payments`, `payment_attempts`, `provider_transactions`, `provider_customers`, `gateway_references` — **5** |
| Provider adapter port & Stripe adapter (Phase 21) | (no new tables) |
| Mollie & PayPal adapters (Phase 22) | (no new tables) |
| Ziraat adapter (Phase 23) | deferred — no tables |
| Payment creation flow (Phase 24) | `price_list_assignments` — **1** (Q6/Q7); `gateway_references` gained a nullable `checkout_attempt_id` column (Q1) and `checkout_attempts` gained `hash_return_token` (Q4) |
| Webhooks module (Phase 25) | `webhook_events` — **1** |
| Subscriptions module (Phase 26) | `subscriptions`, `subscription_events`, `subscription_payment_links` — **3**; `payments.checkout_attempt_id` made nullable (Q2) and `gateway_references` gained a nullable `subscription_id` column |
| Client callbacks / notifications (Phase 28) | `provider_account_notification_overrides`, `client_notification_logs` — **2** |
| Background jobs, reconciliation & observability (Phase 29) | `jobs`, `reconciliation_findings` — **2** |

**Total: 60 tables.** Phase 6 also added the `client_id` foreign keys on the three Phase 5
cross-cutting tables (deferred from Phase 5).

**Known gap (not this phase's to fix, flagged for visibility):** Phase 27's `admin_users`,
`admin_sessions`, `admin_login_attempts` tables were never added to this file, the diagram, or
`db_explain.md` — a pre-existing violation of the Database Diagram Maintenance Rule from that
phase, discovered while updating this file for Phase 28. Left as-is here rather than silently
backfilled outside the phase that should own that write-up; call it out if you want it done as a
follow-up.

---

## Reference data (Phase 4)

### `currencies`

Every ISO 4217 currency. Seeded from `brick/money`'s `ISOCurrencyProvider` (166 rows) — the same
source `Shared\Domain\Currency` / `Money` validate against, so the table cannot drift from the
code. Static.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `code` | `CHAR(3)` | no | ISO 4217 alpha — `USD`, `EUR` |
| `numeric_code` | `SMALLINT UNSIGNED` | no | ISO 4217 numeric — `840`, `978` |
| `name` | `VARCHAR(64)` | no | `US Dollar` |
| `minor_unit_scale` | `TINYINT UNSIGNED` | no | decimal places — `2` / `0` / `3` |

Indexes: `uniq_currencies_code (code)`, `uniq_currencies_numeric_code (numeric_code)`.
No foreign keys. Nothing FKs *to* it at DB level (money `currency` columns are validated in code,
not constrained — see `.claude/agents/DatabaseAgent.md` / decision in `PhaseResults/PhaseDecisions.md` Phase 4).

### `countries`

The markets Gomrok operates in — **curated**, not the full ISO 3166 list. New markets are added
by a later migration + seed, never by editing the Phase 4 migration. Static.

Seeded rows (18): TR, US, GB, DE, FR, NL, IT, ES, IE, BE, AT, PT, SE, DK, FI, PL, AE, SA
(from `src/Database/Seeds/data/countries.json`).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `code` | `CHAR(2)` | no | ISO 3166-1 alpha-2 — `TR`, `US` |
| `name` | `VARCHAR(80)` | no | `Türkiye`, `United States` |
| `default_currency` | `CHAR(3)` | no | the country's primary currency |

Indexes: `uniq_countries_code (code)`, `idx_countries_default_currency (default_currency)`.
FK: `fk_countries_default_currency (default_currency) → currencies(code)`
`ON DELETE RESTRICT ON UPDATE CASCADE`.

### `provider_types`

The payment providers Gomrok integrates with. Low-churn — adding one is a whole phase. Static.

Seeded rows (4):

| code | name | requires_registration | api_capable |
| --- | --- | --- | --- |
| `stripe` | Stripe | 1 | 1 |
| `mollie` | Mollie | 1 | 1 |
| `paypal` | PayPal | 1 | 1 |
| `ziraat` | Ziraat Bank | 0 | 0 |

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `code` | `VARCHAR(32)` | no | `stripe`, `ziraat` |
| `name` | `VARCHAR(64)` | no | display name |
| `requires_registration` | `TINYINT(1)` | no | a product/price object must exist provider-side before selling |
| `api_capable` | `TINYINT(1)` | no | the provider exposes an API |

Index: `uniq_provider_types_code (code)`.
The provider-**capability** catalogue is **Phase 8**, not here (`PhaseResults/PhaseDecisions.md` Phase 4 Q4).

---

## Cross-cutting (Phase 5)

Tables nearly every later module writes to. Business tables, so they carry timestamps.
`client_id` columns exist now but **carry no FK yet** — `clients` is Phase 6, which adds
`fk_idempotency_keys_client_id` / `fk_audit_logs_client_id` / `fk_error_logs_client_id` via an
additive migration.

Decisions: `PhaseResults/PhaseDecisions.md` Phase 5 Q1–Q5.

### `idempotency_keys`

Shields client write endpoints against duplicate submission. Row is claimed (`status =
processing`) before the handler runs, moved to `done` (with the created entity's
`target_type` / `target_id`) on success, or `failed` on error. An expired row is treated as
absent on lookup and deleted by `bin/PurgeIdempotencyKeys.php`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `client_id` | `INT UNSIGNED` | no | owning client (FK added Phase 6) |
| `idempotency_key` | `VARCHAR(255)` | no | value of the client's `Idempotency-Key` header |
| `request_fingerprint` | `CHAR(64)` | no | SHA-256 of method + path + raw body |
| `status` | `VARCHAR(20)` | no | `processing` / `done` / `failed` (app-enforced) |
| `target_type` | `VARCHAR(64)` | yes | e.g. `payment` — set when `done` |
| `target_id` | `INT UNSIGNED` | yes | id of the created entity — set when `done` |
| `response_status` | `SMALLINT UNSIGNED` | yes | HTTP status of the original success |
| `created_at` | `DATETIME` | no | UTC |
| `updated_at` | `DATETIME` | yes | UTC |
| `expires_at` | `DATETIME` | no | `created_at + 24h`; past → treated as absent |

Indexes: `uniq_idempotency_keys_client_key (client_id, idempotency_key)`,
`idx_idempotency_keys_expires_at (expires_at)`.

### `audit_logs`

Append-only trail of sensitive admin / system actions. `before` / `after` hold the full target
row as JSON with secret-bearing keys redacted; `before` is null on create, `after` is null on
delete. No `updated_at` — rows are never modified.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `actor_type` | `VARCHAR(20)` | no | `admin_user` / `client` / `system` |
| `actor_id` | `INT UNSIGNED` | yes | admin user / client id; null for `system` |
| `client_id` | `INT UNSIGNED` | yes | client scope of the action, when applicable |
| `action` | `VARCHAR(100)` | no | verb string — `provider_config.updated` |
| `target_type` | `VARCHAR(64)` | yes | entity type acted on |
| `target_id` | `INT UNSIGNED` | yes | entity id acted on |
| `before` | `JSON` | yes | full target row before (null on create), redacted |
| `after` | `JSON` | yes | full target row after (null on delete), redacted |
| `context` | `JSON` | yes | extra detail (reason, request params) |
| `correlation_id` | `VARCHAR(128)` | yes | request correlation id |
| `ip` | `VARCHAR(45)` | yes | actor IP (IPv6-capable) |
| `user_agent` | `VARCHAR(255)` | yes | |
| `created_at` | `DATETIME` | no | UTC |

Indexes: `idx_audit_logs_client_created (client_id, created_at)`,
`idx_audit_logs_target (target_type, target_id)`, `idx_audit_logs_action (action)`.

### `error_logs`

Operator triage surface, written only by the explicit `ErrorLogWriter` (never a log handler).
Backs the admin Error Logs screen in Phase 27; `resolved_at` / `resolved_by` support its "mark
resolved" action.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `level` | `VARCHAR(20)` | no | `error` / `critical` |
| `source` | `VARCHAR(40)` | no | `http` / `webhook` / `provider` / `notification` / `job` |
| `message` | `TEXT` | no | |
| `exception_class` | `VARCHAR(255)` | yes | |
| `code` | `VARCHAR(64)` | yes | domain / provider error code |
| `client_id` | `INT UNSIGNED` | yes | FK added Phase 6 |
| `correlation_id` | `VARCHAR(128)` | yes | |
| `context` | `JSON` | yes | payment_id, provider, provider_transaction_id, … (redacted) |
| `stack_trace` | `MEDIUMTEXT` | yes | |
| `created_at` | `DATETIME` | no | UTC |
| `resolved_at` | `DATETIME` | yes | set from the admin panel |
| `resolved_by` | `INT UNSIGNED` | yes | admin user id |

Indexes: `idx_error_logs_created (created_at)`, `idx_error_logs_client (client_id)`,
`idx_error_logs_unresolved (resolved_at)`.

---

## Clients (Phase 6)

The tenant model. Decisions: `PhaseResults/PhaseDecisions.md` Phase 6 Q1–Q5.

### `clients`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `slug` | `VARCHAR(64)` | no | **unique**, immutable, `^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$` |
| `name` | `VARCHAR(150)` | no | display, mutable |
| `status` | `VARCHAR(20)` | no | default `active` — `active` \| `disabled` (app-enforced) |
| `default_currency` | `CHAR(3)` | no | FK → `currencies(code)` |
| `default_country` | `CHAR(2)` | yes | FK → `countries(code)` |
| `timezone` | `VARCHAR(64)` | no | default `UTC` (IANA name) |
| `notification_signing_secret` | `VARCHAR(128)` | no | HMAC-signs Gomrok→client callbacks; never logged, redacted in audit |
| `disabled_at` | `DATETIME` | yes | |
| `disabled_by` | `INT UNSIGNED` | yes | admin user id (no FK — admin users are Phase 26) |
| `disabled_reason` | `VARCHAR(255)` | yes | |
| `created_at` | `DATETIME` | no | |
| `updated_at` | `DATETIME` | yes | |

Indexes: `uniq_clients_slug (slug)`, `idx_clients_status`, `idx_clients_default_currency`,
`idx_clients_default_country`.
FKs: `fk_clients_default_currency → currencies(code)` RESTRICT/CASCADE;
`fk_clients_default_country → countries(code)` RESTRICT/CASCADE.

### `client_api_keys`

Secret stored as `sha256(secret)`; the token `gk_<mode>_<key_id>.<secret>` is shown once.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `client_id` | `INT UNSIGNED` | no | FK → `clients(id)` ON DELETE CASCADE |
| `key_id` | `VARCHAR(32)` | no | **unique** — public lookup token (16 hex) |
| `secret_hash` | `CHAR(64)` | no | `sha256(secret)` hex; never logged |
| `prefix` | `VARCHAR(16)` | no | `gk_live` \| `gk_test` |
| `last_four` | `CHAR(4)` | no | last 4 chars of the secret, for display |
| `label` | `VARCHAR(100)` | yes | |
| `status` | `VARCHAR(20)` | no | default `active` — `active` \| `revoked` |
| `created_at` | `DATETIME` | no | |
| `last_used_at` | `DATETIME` | yes | stamped by the Phase 7 auth path |
| `expires_at` | `DATETIME` | yes | optional |
| `revoked_at` | `DATETIME` | yes | |
| `revoked_by` | `INT UNSIGNED` | yes | admin user id (no FK yet) |

Indexes: `uniq_client_api_keys_key_id (key_id)`, `idx_client_api_keys_client (client_id)`,
`idx_client_api_keys_client_status (client_id, status)`.
FK: `fk_client_api_keys_client_id → clients(id)` ON DELETE CASCADE.

### `client_endpoints`

Callback URLs Gomrok posts status updates to. One active URL per `(client_id, purpose)`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `client_id` | `INT UNSIGNED` | no | FK → `clients(id)` ON DELETE CASCADE |
| `purpose` | `VARCHAR(40)` | no | `payment_status` \| `subscription_status` \| `refund_status` |
| `url` | `VARCHAR(2048)` | no | https callback URL |
| `is_active` | `TINYINT(1)` | no | default `1` |
| `created_at` | `DATETIME` | no | |
| `updated_at` | `DATETIME` | yes | |

Indexes: `uniq_client_endpoints_client_purpose (client_id, purpose)`,
`idx_client_endpoints_client (client_id)`.
FK: `fk_client_endpoints_client_id → clients(id)` ON DELETE CASCADE.

### Cross-cutting FKs added in Phase 6

`AddClientFksToCrossCuttingTables` (migration `..._150004`):

| Constraint | Table | On delete |
| --- | --- | --- |
| `fk_idempotency_keys_client_id → clients(id)` | `idempotency_keys` | CASCADE |
| `fk_audit_logs_client_id → clients(id)` | `audit_logs` | SET NULL |
| `fk_error_logs_client_id → clients(id)` | `error_logs` | SET NULL |

---

## Client API authentication (Phase 7)

Decisions: `PhaseResults/PhaseDecisions.md` Phase 7 Q1–Q5.

### `client_auth_attempts`

One append-only row per authentication attempt on `/api/v1` (success and failure). Backs the
admin security view and any future rate limiter. Written best-effort by the authenticator.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `outcome` | `VARCHAR(20)` | no | `success` \| `failure` (app-enforced) |
| `reason` | `VARCHAR(40)` | no | `ok` / `missing_authorization` / `malformed_token` / `unknown_key` / `invalid_secret` / `key_expired` / `key_revoked` / `client_disabled` |
| `key_id` | `VARCHAR(32)` | yes | parsed token id; null if absent/unparseable. **Never the secret.** |
| `client_id` | `INT UNSIGNED` | yes | resolved client; null if the key was unknown |
| `ip` | `VARCHAR(45)` | yes | |
| `user_agent` | `VARCHAR(255)` | yes | |
| `correlation_id` | `VARCHAR(128)` | yes | ties to the request's `X-Correlation-Id` |
| `created_at` | `DATETIME` | no | UTC; append-only |

Indexes: `idx_client_auth_attempts_ip_created (ip, created_at)`,
`idx_client_auth_attempts_key_created (key_id, created_at)`,
`idx_client_auth_attempts_client_created (client_id, created_at)`.
FK: `fk_client_auth_attempts_client_id → clients(id)` **ON DELETE SET NULL**.

No new columns on `client_api_keys` — `last_used_at` (Phase 6) is now stamped, throttled to once
per key per 5 minutes.

---

## Providers (Phase 8)

What each provider *type* can do, before any account / client / country config narrows it.
Decisions: `PhaseResults/PhaseDecisions.md` Phase 8 Q1–Q5. All three tables are static /
seeded — **no timestamps** (Phase 4 convention).

### `provider_capabilities`

Catalogue of capability flags. Seeded mirror of the
`Gomrok\Modules\Providers\Domain\Capability` enum (the source of truth). 19 rows.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `code` | `VARCHAR(40)` | no | **unique** — equals the `Capability` enum value |
| `label` | `VARCHAR(80)` | no | admin display |
| `description` | `VARCHAR(255)` | yes | |
| `capability_group` | `VARCHAR(20)` | no | `payment` \| `refund` \| `subscription` \| `security` \| `operational` (app-enum) |

Indexes: `uniq_provider_capabilities_code (code)`, `idx_provider_capabilities_group (capability_group)`.
No FKs out. **Purchase types** (`one_time_payment`, `recurring_payment`, `auto_charge`,
`subscription`) are a **separate** `PurchaseType` enum — deliberately not in this table.

### `provider_type_capabilities`

Which capabilities a provider type declares. Seeded for **stripe + paypal** only (Q5);
ziraat / mollie rows are added in their adapter phases.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `provider_type_id` | `INT UNSIGNED` | no | FK → `provider_types(id)` ON DELETE CASCADE |
| `capability_id` | `INT UNSIGNED` | no | FK → `provider_capabilities(id)` ON DELETE CASCADE |

Indexes: `uniq_provider_type_capabilities (provider_type_id, capability_id)`,
`idx_provider_type_capabilities_capability (capability_id)`.

### `provider_type_purchase_types`

Which purchase types a provider type supports. Seeded for stripe + paypal.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `provider_type_id` | `INT UNSIGNED` | no | FK → `provider_types(id)` ON DELETE CASCADE |
| `purchase_type` | `VARCHAR(20)` | no | `PurchaseType` enum value (`one_time_payment` \| `recurring_payment` \| `auto_charge` \| `subscription`) — app-enforced |

Indexes: `uniq_provider_type_purchase_types (provider_type_id, purchase_type)`,
`idx_provider_type_purchase_types_type (purchase_type)`.

**Not modelled yet (Q4):** payment methods (`payment_methods` catalogue,
`provider_type_method_capabilities`) — deferred to Phase 9/12. Method-level nuance is an in-code
placeholder (`MethodCapabilityRules`) until then.

---

## Providers — accounts (Phase 9)

A client's credentials for a provider type in one `mode`. Decisions:
`PhaseResults/PhaseDecisions.md` Phase 9 Q1–Q5. Business tables (`created_at` / `updated_at`;
join tables carry `created_at` only).

**Non-schema:** `Settings` / `.env.example` / CI gain `APP_ENCRYPTION_KEY` (base64 32-byte key
for `Shared\Application\SecretCipher` → `SodiumSecretCipher`). Missing key → the cipher throws
when first resolved (never a silent fallback).

### `provider_accounts`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `client_id` | `INT UNSIGNED` | no | FK → `clients(id)` ON DELETE CASCADE |
| `provider_type_id` | `INT UNSIGNED` | no | FK → `provider_types(id)` ON DELETE **RESTRICT** |
| `slug` | `VARCHAR(64)` | no | client-scoped (e.g. `stripe-live`, `stripe-test`) |
| `name` | `VARCHAR(150)` | no | display, mutable |
| `mode` | `VARCHAR(10)` | no | `live` \| `test` (Q2) — the request's key prefix picks the pool |
| `status` | `VARCHAR(20)` | no | default `active` — `active` \| `disabled` |
| `public_key` | `VARCHAR(255)` | yes | publishable key / merchant id — **not secret**, plaintext |
| `secret_ciphertext` | `TEXT` | no | secret key, `SecretCipher`-encrypted (Q1) |
| `secret_last_four` | `CHAR(4)` | no | display hint (`••••abcd`) |
| `disabled_at` / `disabled_by` / `disabled_reason` | — | yes | soft disable |
| `created_at` | `DATETIME` | no | |
| `updated_at` | `DATETIME` | yes | |

Indexes: `uniq_provider_accounts_client_slug (client_id, slug)`,
`idx_provider_accounts_client_type_mode (client_id, provider_type_id, mode)`,
`idx_provider_accounts_provider_type`, `idx_provider_accounts_status`.

### `provider_account_endpoints`

Verification config for inbound provider messages (Q3). One active per `kind` (app-enforced).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | `INT UNSIGNED` | no | PK, auto-increment |
| `provider_account_id` | `INT UNSIGNED` | no | FK → `provider_accounts(id)` ON DELETE CASCADE |
| `kind` | `VARCHAR(10)` | no | `webhook` \| `callback` \| `return` |
| `token` | `VARCHAR(64)` | yes | **unique** (multi-NULL ok); segment in `/api/v1/webhooks/{provider}/{token}`, resolved via `ProviderAccountDirectory::findByEndpointToken()` (Phase 25). Null for `return`. |
| `signing_secret_ciphertext` | `TEXT` | yes | `SecretCipher`-encrypted; null for `return` |
| `is_active` | `TINYINT(1)` | no | default `1` |
| `created_at` / `updated_at` | — | | |

Indexes: `uniq_provider_account_endpoints_token (token)`,
`idx_provider_account_endpoints_account_kind (provider_account_id, kind)`.

### `provider_account_countries` / `provider_account_methods` (Q4)

Which configured markets / payment methods an account serves — the Phase 10 router filters on
these.

| Table | Columns | Constraints |
| --- | --- | --- |
| `provider_account_countries` | `id`, `provider_account_id` FK CASCADE, `country_code CHAR(2)` FK → `countries(code)` RESTRICT, `created_at` | `UNIQUE (provider_account_id, country_code)` |
| `provider_account_methods` | `id`, `provider_account_id` FK CASCADE, `payment_method VARCHAR(20)` (`PaymentMethod` enum), `created_at` | `UNIQUE (provider_account_id, payment_method)` |

Account-level capability / purchase-type narrowing is **not** modelled (Q4) — inherited from the
provider type.

---

## Providers — routing (Phase 10)

Country → provider routing config. Per Phase 10 Q1 there is **one** mechanism — provider groups —
and **no** `country_provider_configs` / `country_provider_priorities` / `country_payment_methods`
/ `country_purchase_capabilities` tables. All five tables are client-scoped through
`provider_groups.client_id`.

### `provider_groups` (Q1)

A named routing rule: a set of countries → an ordered list of the client's provider accounts,
plus the purchase types / methods the market sells.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `slug` | VARCHAR(64) | no | client-facing handle |
| `name` | VARCHAR(150) | no | |
| `is_default` | BOOLEAN | no | default `false`; the fallback group — no country rows |
| `device_type` | VARCHAR(10) | yes | `web` / `ios` / `android` (`DeviceType` enum); NULL = any |
| `currency_code` | CHAR(3) | yes | FK → `currencies(code)` RESTRICT; NULL = don't gate on currency |
| `status` | VARCHAR(20) | no | `active` / `disabled` (`ProviderGroupStatus`), default `active` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

Indexes: `UNIQUE (client_id, slug)`, `(client_id, is_default, device_type)`, `(client_id, status)`.
**App-enforced:** at most one `is_default = true` per `(client_id, device_type)`.

### `provider_group_countries` (Q1)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | INT UNSIGNED AI | PK |
| `provider_group_id` | INT UNSIGNED | FK → `provider_groups(id)` CASCADE |
| `country_code` | CHAR(2) | FK → `countries(code)` RESTRICT |
| `created_at` | DATETIME | |

`UNIQUE (provider_group_id, country_code)`, `INDEX (country_code)`. The default group has zero
rows. **App-enforced:** a country is in at most one non-default group per `(client_id, device_type)`.

### `provider_group_accounts` (Q1)

The ordered provider-account list. `priority` ascending = tried first; `is_enabled = 0` parks an
entry without losing its place.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | INT UNSIGNED AI | PK |
| `provider_group_id` | INT UNSIGNED | FK → `provider_groups(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | FK → `provider_accounts(id)` CASCADE |
| `priority` | SMALLINT UNSIGNED | default 0 |
| `is_enabled` | BOOLEAN | default true |
| `created_at` | DATETIME | |

`UNIQUE (provider_group_id, provider_account_id)`, `(provider_group_id, priority)`,
`(provider_account_id)`. **App-enforced:** the linked account's `client_id` = the group's.

### `provider_group_purchase_types` / `provider_group_methods` (Q2)

Group-level enablement. The router intersects the purchase-type set with each account's
provider-type declaration; an empty purchase-type set = nothing sellable (fail closed). An empty
method set = every method the resolved account supports (fail open).

| Table | Columns | Constraints |
| --- | --- | --- |
| `provider_group_purchase_types` | `id`, `provider_group_id` FK CASCADE, `purchase_type VARCHAR(20)` (`PurchaseType` enum), `created_at` | `UNIQUE (provider_group_id, purchase_type)` |
| `provider_group_methods` | `id`, `provider_group_id` FK CASCADE, `payment_method VARCHAR(20)` (`PaymentMethod` enum), `created_at` | `UNIQUE (provider_group_id, payment_method)` |

Routing decisions are **not** persisted this phase (Q4) — `RoutingDecision` is an in-memory VO
with a `toArray()` / `fromArray()` contract for a future payment snapshot (Phase 17).

---

## Packages — catalog & availability (Phase 11)

The client-owned catalogue. Per the Package Ownership Rule: one `packages` table carrying
`client_id`, `code` unique **per client**, no global catalogue, no `client_packages` junction.
All five tables are client-scoped through `packages.client_id`.

### `packages` (Q4)

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `code` | VARCHAR(64) | no | client-facing identifier |
| `name` | VARCHAR(150) | no | |
| `description` | TEXT | yes | |
| `status` | VARCHAR(20) | no | `active` / `disabled` (`PackageStatus`), default `active` |
| `metadata` | JSON | yes | free-form client attributes; validated as an object at the use-case boundary |
| `badge` | VARCHAR(40) | yes | *(Phase 12)* short display label |
| `highlighted` | TINYINT(1) | no | *(Phase 12)* default `0` |
| `client_package_id` | VARCHAR(64) | yes | *(Phase 12)* the client's own id for this package; **not** unique-enforced by Gomrok |
| `created_at` / `updated_at` | DATETIME | no / yes | |

Indexes: `UNIQUE (client_id, code)`, `(client_id, status)`. `status = disabled` is the per-client
hide switch — there is **no** `packages.enable_for_client` permission.

### Availability child tables (Q1)

Four dedicated join tables, mirroring `provider_account_*` / `provider_group_*`. **An empty set
for a dimension = available everywhere for that dimension** (Q2 — fail open); rows restrict.

| Table | Columns | Constraints |
| --- | --- | --- |
| `package_countries` | `id`, `package_id` FK CASCADE, `country_code CHAR(2)` FK → `countries(code)` RESTRICT, `created_at` | `UNIQUE (package_id, country_code)` |
| `package_currencies` | `id`, `package_id` FK CASCADE, `currency_code CHAR(3)` FK → `currencies(code)` RESTRICT, `created_at` | `UNIQUE (package_id, currency_code)` |
| `package_payment_methods` | `id`, `package_id` FK CASCADE, `payment_method VARCHAR(20)` (`PaymentMethod` value, app-enforced), `created_at` | `UNIQUE (package_id, payment_method)` |
| `package_provider_accounts` | `id`, `package_id` FK CASCADE, `provider_account_id` FK → `provider_accounts(id)` CASCADE, `created_at` | `UNIQUE (package_id, provider_account_id)` |

**App-enforced:** a `package_provider_accounts` row's account belongs to the package's client.
Purchase capabilities, price, trial config and provider definitions are **not** in Phase 11 —
Phases 12–13.

### Resolution (`PackageCatalog::resolve`)

For `(clientId, country, currency, ?method)`: active packages of the client kept only if each
dimension matches (`rows == [] → match`, else `requested ∈ rows`) **and** the country-effective
purchase-capability set (Phase 12) is non-empty. Returns `ResolvedPackage` (id, code, name,
description, metadata, badge, highlighted, clientPackageId, `availableProviderAccountIds` = the
package's set ∩ the client's active accounts, `availableMethods`, `purchaseCapabilities`). Still
no `price` — Phase 13.

---

## Packages — capabilities & provider definitions (Phase 12)

### `package_purchase_capabilities` (Q1)

The purchase types a package supports, with per-type config. An **empty** set makes the package
not sellable (fail closed — the catalogue drops it).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `purchase_type` | VARCHAR(20) | no | `PurchaseType` value, app-enforced |
| `has_trial` | TINYINT(1) | no | default `0` |
| `trial_days` | SMALLINT UNSIGNED | yes | required when `has_trial = 1`; domain-enforced only for `subscription` / `recurring_payment` |
| `duration_months` | SMALLINT UNSIGNED | yes | entitlement length per purchase; NULL = open-ended |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (package_id, purchase_type)`, `INDEX (purchase_type)`.

### `package_country_purchase_capabilities` (Q2)

Per-country override of the purchase-type set. Rows present for `(package, country)` **replace**
the global set for that country; absent ⇒ inherit. A listed type must be in the package's global
set (validated).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `country_code` | CHAR(2) | no | FK → `countries(code)` RESTRICT |
| `purchase_type` | VARCHAR(20) | no | |
| `created_at` | DATETIME | no | |

`UNIQUE (package_id, country_code, purchase_type)`, `INDEX (country_code)`.

### `package_provider_definitions` (Q3)

Where a package exists on one provider account's side. One row per linked `(package, provider
account)`, created lazily.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `provider_side_name` | VARCHAR(150) | yes | product / plan name to use on the provider |
| `remote_id` | VARCHAR(191) | yes | provider product/plan id; NULL for `not_created` / `not_needed` |
| `sync_state` | VARCHAR(20) | no | `not_created` / `synced` / `drift` / `not_needed` (`PackageProviderSyncState`), default `not_created` |
| `last_synced_at` | DATETIME | yes | |
| `last_error` | VARCHAR(255) | yes | last sync failure message (no secrets) |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (package_id, provider_account_id)`, `INDEX (provider_account_id)`, `INDEX (sync_state)`,
`INDEX (remote_id)` (reverse lookup for Phase 25 webhooks). **App-enforced:** the provider
account belongs to the package's client.

**Drift sweep (Q5):** editing a package (`UpdatePackage` / `SetPackageAvailability` /
`SetPackagePurchaseCapabilities` / `SetPackageCountryPurchaseCapabilities`) runs
`UPDATE … SET sync_state = 'drift' WHERE package_id = ? AND sync_state = 'synced'` in the same
transaction. Provider-API product creation is **not** implemented — Phases 21–23.

---

## Pricing — groups & default prices (Phase 13)

The **baseline** of pricing — Phase 14 (`price_rules`, below) layers dimension overrides,
Phase 15 A/B lists, 16–17 vouchers. All client-scoped.

### `pricing_groups` (Q1)

Priority-ordered country grouping for pricing. A country may be in several groups; the lowest
`priority` wins. `is_default` is the fallback (no country rows, resolved last regardless of
stored priority). One currency per group. Distinct from Phase 10 provider groups (exclusive
routing) — pricing groups deliberately overlap.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `slug` | VARCHAR(64) | no | |
| `name` | VARCHAR(150) | no | |
| `priority` | SMALLINT UNSIGNED | no | ascending = checked first |
| `device_type` | VARCHAR(10) | yes | `web` / `ios` / `android`; NULL = any |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `is_default` | BOOLEAN | no | default `false` |
| `status` | VARCHAR(20) | no | `active` / `disabled` (`PricingGroupStatus`), default `active` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (client_id, slug)`; `INDEX (client_id, status, priority)`. **App-enforced:** `priority`
unique per client; exactly one `is_default` per client; the default group can't be disabled.

### `pricing_group_countries` (Q1)

`id`, `pricing_group_id` FK CASCADE, `country_code CHAR(2)` FK → `countries(code)` RESTRICT,
`created_at`. `UNIQUE (pricing_group_id, country_code)`. **Overlap across groups is allowed.**

### `default_package_prices` (Q2)

The one baseline price per package. `id`, `package_id` FK CASCADE **`UNIQUE`**,
`amount_minor BIGINT UNSIGNED`, `currency_code CHAR(3)` FK → `currencies(code)` RESTRICT,
`created_at` / `updated_at`.

### `client_exchange_rates` (Q2)

Client-configured, effective-dated FX. `id`, `client_id` FK CASCADE, `base_currency` /
`quote_currency CHAR(3)` FK → `currencies(code)` RESTRICT, `rate DECIMAL(18,8)`,
`effective_from DATETIME`, `created_at`. `UNIQUE (client_id, base_currency, quote_currency,
effective_from)`. The most recent row with `effective_from <= now` for a pair wins; used only
for a `status=default` cross-currency resolve.

### `pricing_group_packages` (Q3)

Per `(pricing group, package)`. **No row = implicit `status=default`.**

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `pricing_group_id` | INT UNSIGNED | no | FK → `pricing_groups(id)` CASCADE |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `status` | VARCHAR(20) | no | `default` / `override` / `disabled` (`PricingRowStatus`), default `default` |
| `amount_minor` | BIGINT UNSIGNED | yes | set iff `status=override` |
| `currency_code` | CHAR(3) | yes | set iff `status=override`; FK → `currencies(code)` RESTRICT; = group currency |
| `name_override` / `badge_override` | VARCHAR | yes | |
| `highlighted_override` | TINYINT(1) | yes | NULL = inherit `packages.highlighted` |
| `display_order` | SMALLINT UNSIGNED | no | default `0` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (pricing_group_id, package_id)`; `INDEX (pricing_group_id, display_order)`;
`INDEX (package_id)`. **Domain guards:** `override` ⇒ amount + currency set, currency = group
currency; else both NULL.

### Resolution (`PriceResolver` / `PriceCatalog`)

Pricing group by `priority` (default last, device filter) → `(group, package)` row (or implicit
default). `disabled` → package unavailable in that group. `override` → the row's amount
(`source = group_override`). `default` → `default_package_prices`: same currency → `baseline`,
else convert via the client's rate → `converted` (or `pricing.no_exchange_rate`). Effective
`name` / `badge` / `highlighted` after overrides. `GET /api/v1/packages` +
`GET /api/v1/pricing/resolve` mount this phase.

---

## Pricing — dimension overrides (Phase 14)

### `price_rules`

One table of `(client, package)` price overrides keyed by up to **7 nullable dimensions**. A
null dimension is a wildcard. A rule either overrides the amount (`is_available = 1`, amount set)
or marks the combination **not for sale** (`is_available = 0`, amount NULL). Layered *after* the
Phase 13 base price: the base amount is resolved first, then the most-specific matching rule (if
any) replaces it or fails the resolve.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `pricing_group_id` | INT UNSIGNED | yes | FK → `pricing_groups(id)` CASCADE — dimension |
| `country_code` | CHAR(2) | yes | FK → `countries(code)` RESTRICT — dimension |
| `provider_account_id` | INT UNSIGNED | yes | FK → `provider_accounts(id)` CASCADE — dimension |
| `payment_method` | VARCHAR(20) | yes | `PaymentMethod` value — dimension |
| `purchase_type` | VARCHAR(20) | yes | `PurchaseType` value — dimension |
| `subscription_interval` | VARCHAR(20) | yes | `SubscriptionInterval` (`monthly`/`quarterly`/`yearly`) — dimension; requires a subscription/recurring `purchase_type` |
| `currency_code` | CHAR(3) | yes | FK → `currencies(code)` RESTRICT — dimension |
| `is_available` | TINYINT(1) | no | default `1`; `0` ⇒ combination unavailable |
| `amount_minor` | BIGINT UNSIGNED | yes | set iff `is_available = 1` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (package_id, pricing_group_id, country_code, provider_account_id, payment_method,
purchase_type, subscription_interval, currency_code)` = `uniq_price_rules_dimensions` (NULLs are
distinct in MySQL, so the upsert path uses a null-safe `<=>` lookup, not `ON DUPLICATE KEY`);
`INDEX (client_id, package_id)`, `INDEX (provider_account_id)`, `INDEX (pricing_group_id)`.

**Domain guards (`PriceRule::validate`):** `subscription_interval` needs a subscription/recurring
`purchase_type` (`price_rule.interval_needs_subscription`); available ⇒ amount set
(`price_rule.amount_required`); available ⇒ at least `pricing_group_id` or `currency_code` pinned
(`price_rule.needs_group_or_currency`); unavailable ⇒ amount NULL
(`price_rule.unavailable_has_amount`). **Handler-enforced:** every dimension belongs to the
client; a pinned group + currency must agree (`price_rule.currency_mismatch`).

### Resolution (`PriceRuleResolver` → `PriceResolver::applyRules`)

The base price (Phase 13) is resolved first, giving the effective pricing group + currency. The
`PriceRuleContext` (`pricing_group_id`, `country`, `currency`, `?provider_account_id`,
`?payment_method`, `?purchase_type`, `?subscription_interval`) is matched against
`price_rules.forClientPackage`: a rule matches when **every** non-null dimension equals the
request. Winner (Phase 14 Q3):

1. most matched dimensions wins;
2. tie → fixed dimension priority `subscription_interval > purchase_type > payment_method >
   provider_account_id > currency_code > country_code > pricing_group_id`;
3. still tied → highest `id`.

Winner `is_available = 0` → hard `pricing.combination_unavailable` (no fallback to the base
price). Winner available → its `amount_minor` replaces the base
(`ResolvedPrice.source = dimension_override`, `applied_rule_id` + `applied_dimensions` set). No
match → the base price stands. A currency-pinned rule only matches inside a group of that
currency, so an EUR rule never bleeds into a USD group (the base `converted` price stands there).

The `price_rules` step runs **after** the Phase 15 price-list step, so a matching rule overrides
whatever the assigned A/B list produced.

---

## Pricing — A/B price lists (Phase 15; visitor assignment — Phase 24 Q6/Q7)

Price experiments inside a pricing group. Every group has exactly one **control** list; a
non-control list shifts the resolved base price by `factor` or (per package) by an exact
`price_list_packages` amount. The step sits between the Phase 13 base amount and the Phase 14
`price_rules` step.

Visitor→list assignment (Phase 15 Q4/Q5, deferred and re-asked fresh at Phase 24 Q6/Q7) is now
built: `price_list_assignments` persists each visitor's bucket per pricing group, a deterministic
hash-based bucketing service assigns it on first sight, and `GET /api/v1/packages` /
`GET /api/v1/packages/{packageId}` / `GET /api/v1/pricing/resolve` all accept an optional
`visitor_ref` query param that resolves and persists the assignment.

### `price_lists`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `pricing_group_id` | INT UNSIGNED | no | FK → `pricing_groups(id)` CASCADE |
| `name` | VARCHAR(100) | no | e.g. `List A · control`, `List B · -10%` |
| `is_control` | TINYINT(1) | no | default `0`; **exactly one `1` per group** (app-enforced) |
| `factor` | DECIMAL(6,4) | no | default `1.0000`; `> 0`; control is pinned at `1.0000` |
| `is_enabled` | TINYINT(1) | no | default `1`; control can never be `0` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (pricing_group_id, name)` = `uniq_price_lists_group_name`; `INDEX (client_id,
pricing_group_id)`. **App-enforced:** one control per group; the control list can't be renamed to
`List A · control` by anyone else, can't be disabled (`price_list.cannot_disable_control`),
can't change factor (`price_list.control_factor_locked`); a non-control list needs a positive
decimal factor (`price_list.invalid_factor` / `price_list.non_positive_factor`). The control row
is created with the pricing group (`CreatePricingGroupHandler`) and backfilled for pre-existing
groups by the migration.

### `price_list_packages`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `price_list_id` | INT UNSIGNED | no | FK → `price_lists(id)` CASCADE |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `amount_minor` | BIGINT UNSIGNED | no | exact price for this package on this list (`> 0`) |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT; **must equal the pricing-group currency** |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (price_list_id, package_id)` = `uniq_price_list_packages`; `INDEX (package_id)`.
**Handler-enforced:** not allowed on a control list
(`price_list.control_has_no_package_prices`); `package_id` belongs to the list's client;
currency = pricing-group currency (`price_list_package.currency_mismatch`).

### Resolution (`PriceListResolver` → `PriceResolver::resolve`)

After the base amount, `PriceResolver` calls `PriceListResolver::apply(groupId, packageId,
?priceListId, base)`. The list is the one identified by `$priceListId` **if** it belongs to the
group and is enabled — otherwise the group's control list (this is the disable-fallback: a
stored assignment pointing at a now-disabled list drops to control). Then:

1. an exact `price_list_packages` row for `(list, package)` → that `amount_minor`
   (`source = price_list`);
2. else a non-neutral list → `base_amount × factor` HALF_EVEN (`source = price_list`);
3. else (control / `factor = 1.0000`) → the base amount unchanged, but `price_list_id` /
   `price_list_name` / `price_list_factor` are still stamped on the `ResolvedPrice`.

`$priceListId` is resolved from an explicit override, else from a `$visitorRef` via
`ResolveVisitorPriceListAssignment` (below), else `null` (→ always control).

### `price_list_assignments` (Phase 24 Q6/Q7)

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `pricing_group_id` | INT UNSIGNED | no | FK → `pricing_groups(id)` CASCADE |
| `visitor_ref_hash` | VARCHAR(64) | no | `SHA-256(pricing_group_id . ':' . visitor_ref)` — the raw `visitor_ref` is never stored |
| `price_list_id` | INT UNSIGNED | no | FK → `price_lists(id)` CASCADE; the visitor's current bucket |
| `assigned_at` | DATETIME | no | when the row was first created |
| `reassigned_at` | DATETIME | yes | set when the assigned list was later disabled and the row moved to control |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (pricing_group_id, visitor_ref_hash)` = `uniq_price_list_assignments_group_visitor`;
`INDEX (client_id)`, `INDEX (price_list_id)`.

### Visitor bucketing (`ResolveVisitorPriceListAssignment`)

Called by `PriceResolver::resolve()` and `PriceCatalog::resolve()` when a caller supplies
`visitorRef` and no explicit `priceListId`. On first sight for a `(pricingGroupId, visitorRef)`
pair: bucket by a deterministic hash (`hexdec(substr(hash, 0, 8)) % count(enabledLists)`) over
`PriceListRepository::enabledForGroup()` (control first, then by id — a stable order), then
persist via `insertOrGetExisting()` — a `LAST_INSERT_ID(id)`-on-conflict upsert so a concurrent
first-visit race always resolves to one authoritative row, never a thrown exception. Later visits
read the stored row; if its list has since been disabled, the row is reassigned to the group's
control list on that read (`reassigned_at` stamped) — the same disable-fallback
`PriceListResolver::apply()` already gives an explicit `priceListId`. If the pricing group has no
price list at all (not even a control row — possible only for a group built outside
`CreatePricingGroupHandler`, e.g. in tests), resolution degrades to `null` rather than throwing,
and `PriceListResolver::apply()` leaves the base price untouched.

---

## Vouchers — definitions & eligibility (Phase 16)

Voucher definitions and the eligibility gate (discount calc + redemption is Phase 17, below).
**Source of truth for all voucher behaviour: `.claude/Voucher.md`** — read it alongside this
section; if they ever disagree, `Voucher.md` wins.

### `vouchers`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `code` | VARCHAR(64) | no | upper-case, `^[A-Z0-9][A-Z0-9_-]{2,63}$`; `UNIQUE (client_id, code)` |
| `name` | VARCHAR(150) | no | |
| `description` | VARCHAR(500) | yes | |
| `status` | VARCHAR(20) | no | `active` / `disabled`, default `active` |
| `valid_from` / `valid_until` | DATETIME | yes / yes | `NULL` = no bound on that side |
| `first_purchase_only` | TINYINT(1) | no | default `0` |
| `min_purchase_minor` | BIGINT UNSIGNED | yes | `NULL` = no minimum |
| `min_purchase_currency` | CHAR(3) | yes | FK → `currencies(code)` RESTRICT; required iff `min_purchase_minor` set |
| `default_discount_type` | VARCHAR(20) | no | `none` / `percentage` / `full` — **never `fixed`** |
| `default_percent_bp` | SMALLINT UNSIGNED | yes | 1–10000; required iff type `percentage` |
| `max_total_redemptions` | INT UNSIGNED | yes | **`NULL` = unlimited globally** (Phase 16 Q3) |
| `max_per_user` | INT UNSIGNED | yes | **`NULL` = unlimited per client user** |
| `max_per_client` | INT UNSIGNED | yes | **`NULL` = unlimited per client** |
| `redeemed_count` | INT UNSIGNED | no | default `0`; global tally, incremented atomically on confirm (Phase 17) |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (client_id, code)` = `uniq_vouchers_client_code`; `INDEX (client_id, status)` =
`idx_vouchers_client_status`.

### `voucher_eligibility_rules` (Phase 16 Q1)

One row per `(voucher, dimension, value)` — `dimension` ∈ `country` / `currency` / `package` /
`provider_account` / `payment_method` / `purchase_type` / `subscription_interval`; `value` a
code / id-as-string / enum value. `UNIQUE (voucher_id, dimension, value)` =
`uniq_voucher_eligibility`; `INDEX (voucher_id, dimension)` = `idx_voucher_eligibility_dim`.
Semantics: OR within a dimension, AND across, no rows = unrestricted. `package` /
`provider_account` values are validated to belong to the voucher's client at write time (no DB
FK on `value`).

### `voucher_currency_discounts` (Phase 16 Q2)

A per-currency **override** of the voucher's default discount — a currency using the default has
no row here.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `discount_type` | VARCHAR(20) | no | `fixed` / `percentage` / `full` |
| `percent_bp` | SMALLINT UNSIGNED | yes | 1–10000; set iff `percentage` |
| `amount_minor` | BIGINT UNSIGNED | yes | this currency's minor units; set iff `fixed`, `> 0` |
| `max_discount_minor` | BIGINT UNSIGNED | yes | optional cap, this currency; only with `percentage` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (voucher_id, currency_code)` = `uniq_voucher_currency_discounts`.

### Resolution

**Discount** for checkout currency X: override row for X → else the voucher default
(`percentage` / `full`) → else (`none`) not applicable in X.
**Eligibility** (`VoucherEligibilityEvaluator`, Phase 16 Q4): reports **every** unmet condition —
status, window, client scope, every restricted dimension, discount applicability, minimum
purchase (same-currency comparison only), first-purchase-only, and the global / per-user /
per-client usage caps (Phase 17 — see below). Full detail: `.claude/Voucher.md` §5.

---

## Vouchers — redemption lifecycle (Phase 17)

Discount calculation and a concurrency-safe reserve → confirm/release lifecycle. One table.

### `voucher_redemptions`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE (= the voucher's client) |
| `client_user_ref` | VARCHAR(120) | yes | required at reserve time iff the voucher's `max_per_user` is set |
| `attempt_reference` | VARCHAR(191) | no | opaque, caller-supplied (Phase 17 Q1); Phase 20 passes the payment id |
| `status` | VARCHAR(20) | no | `reserved` / `confirmed` / `released`, default `reserved` |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `price_minor` | BIGINT UNSIGNED | no | pre-discount price at reservation time |
| `nominal_discount_minor` | BIGINT UNSIGNED | no | the discount rule's value before any clamping |
| `applied_discount_minor` | BIGINT UNSIGNED | no | after the configured cap + price-floor clamp |
| `payable_minor` | BIGINT UNSIGNED | no | `price_minor - applied_discount_minor` |
| `reserved_at` | DATETIME | no | |
| `confirmed_at` / `released_at` | DATETIME | yes / yes | set on the matching transition |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (voucher_id, attempt_reference)` = `uniq_voucher_redemptions_attempt` (the idempotency
key); `INDEX (voucher_id, client_user_ref, status)` = `idx_voucher_redemptions_user`;
`INDEX (voucher_id, client_id, status)` = `idx_voucher_redemptions_client`;
`INDEX (status)` = `idx_voucher_redemptions_status` (for a future Phase 29 stale-reservation
sweep). No change to `vouchers` — `redeemed_count` stays "confirmed, globally"; the live
`reserved` count is a query over this table.

### Resolution (Phase 17)

**`VoucherDiscountCalculator`** resolves the applicable discount (override → default → `none`)
against a real price: `nominalDiscountMinor` (pre-clamp) → apply the configured
`max_discount_minor` cap (percentage only) → clamp to the price → `appliedDiscountMinor` →
`payableMinor = price - applied`.

**Reserve → confirm/release** (`ReserveVoucherRedemptionHandler` /
`ConfirmVoucherRedemptionHandler` / `ReleaseVoucherRedemptionHandler`): every handler opens a
transaction and locks the `vouchers` row (`SELECT ... FOR UPDATE`, Phase 17 Q3) before touching
`voucher_redemptions` for that voucher — this lock is the per-voucher mutex every writer shares.
Reserve looks up `(voucher_id, attempt_reference)` first and returns an existing row unchanged
(idempotent replay); otherwise it re-runs `VoucherEligibilityEvaluator` (now including the
global/per-user/per-client usage checks via `VoucherUsagePort`) and the discount calculator, then
inserts a `reserved` row. A `reserved` row counts toward every cap from creation until it's
`released` (Phase 17 Q2 — no automatic expiry; a stale-reservation sweep is Phase 29). Confirm
increments `vouchers.redeemed_count` exactly once and is otherwise idempotent; release is
idempotent and a confirmed redemption can never be released.

---

## Checkout + decision snapshots (Phase 18)

A new `Checkout` module anchors the whole pre-payment lifecycle (Phase 18 Q1/Q2, a user-directed
expansion of the original proposal). `checkout_attempts` is the parent: `attempt_reference` is
the external, caller-supplied idempotent key (the same device as `voucher_redemptions.attempt_reference`,
Phase 17); `checkout_attempts.id` is the internal relational anchor every decision-snapshot table
below FKs to. Three write-once decision-snapshot tables sit beside it, one per owning module
(Phase 18 Q4) — `pricing_decision_snapshots` (Pricing), `voucher_decision_snapshots` (Vouchers),
`provider_routing_decision_snapshots` (Providers) — each `UNIQUE (checkout_attempt_id)`: at most
one decision of that kind per attempt, and no update method on any of the three repository ports.
When a later phase creates the final `payments` record, it links back to the checkout attempt and
copies only the immutable commercial snapshot the attempt itself owns (`commercialSnapshot()`,
below) — none of the checkout-attempt or decision-snapshot rows are ever deleted or mutated by
that step, so the full pre-payment history stays available for audit, debugging, abandoned-checkout
tracking, and admin visibility.

### `checkout_attempts`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK — the internal anchor every snapshot table FKs to |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `client_user_ref` | VARCHAR(120) | yes | opaque caller-supplied user reference; required by `ReserveCheckoutVoucher` iff the voucher needs one |
| `attempt_reference` | VARCHAR(191) | no | opaque, caller-supplied idempotency key; trimmed only (case preserved) |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `country` | CHAR(2) | no | FK → `countries(code)` RESTRICT; upper-cased on `start()` |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT; upper-cased on `start()` |
| `purchase_type` | VARCHAR(20) | yes | `PurchaseType` value, set at `start()` |
| `payment_method` | VARCHAR(20) | yes | set once a provider/method is selected |
| `subscription_interval` | VARCHAR(20) | yes | set iff the purchase type is recurring/subscription |
| `status` | VARCHAR(30) | no | `CheckoutAttemptStatus`, default `started` — see lifecycle below |
| `error_code` / `error_message` | VARCHAR(100) / VARCHAR(500) | yes / yes | set on a `failed` exit |
| `created_at` / `updated_at` | DATETIME | no / yes | |
| `abandoned_at` / `expired_at` | DATETIME | yes / yes | reserved for the Phase 29 automatic-detection sweep; not yet written by any handler |

`UNIQUE (client_id, attempt_reference)` = `uniq_checkout_attempts_client_ref` (the idempotency
key — re-`start()` with the same reference returns the existing attempt unchanged);
`INDEX (client_id, status)` = `idx_checkout_attempts_client_status`;
`INDEX (package_id)` = `idx_checkout_attempts_package`.

### `pricing_decision_snapshots`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `checkout_attempt_id` | INT UNSIGNED | no | FK → `checkout_attempts(id)` CASCADE; `UNIQUE` |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `amount_minor` | BIGINT UNSIGNED | no | the resolved `ResolvedPrice::$amountMinor` |
| `source` | VARCHAR(20) | no | `ResolvedPrice::$source->value` (`baseline` / `dimension_override` / `price_list`) |
| `payload` | JSON | no | full `ResolvedPrice::toArray()` — package code/name, base/converted amounts, applied rule/list refs, etc. |
| `created_at` | DATETIME | no | write-once — **no `updated_at`** |

`UNIQUE (checkout_attempt_id)` = `uniq_pricing_decision_snapshots_attempt`;
`INDEX (client_id, package_id)` = `idx_pricing_decision_snapshots_client_package`.

### `voucher_decision_snapshots`

Deliberately thin (Phase 18 Q4): the immutable amounts already live on `voucher_redemptions`
(Phase 17, never mutated after creation), so this only denormalizes the voucher's *identity* at
decision time, in case a later `UpdateVoucher` renames it.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `checkout_attempt_id` | INT UNSIGNED | no | FK → `checkout_attempts(id)` CASCADE; `UNIQUE` |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `voucher_redemption_id` | INT UNSIGNED | no | FK → `voucher_redemptions(id)` CASCADE; `UNIQUE` — the amounts live there |
| `voucher_code` | VARCHAR(64) | no | denormalized at decision time |
| `voucher_name` | VARCHAR(150) | no | denormalized at decision time |
| `created_at` | DATETIME | no | write-once — **no `updated_at`** |

`UNIQUE (checkout_attempt_id)` = `uniq_voucher_decision_snapshots_attempt`;
`UNIQUE (voucher_redemption_id)` = `uniq_voucher_decision_snapshots_redemption`;
`INDEX (client_id, voucher_id)` = `idx_voucher_decision_snapshots_client_voucher`. A checkout
attempt without a voucher simply has no row here — the join is optional.

### `provider_routing_decision_snapshots`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `checkout_attempt_id` | INT UNSIGNED | no | FK → `checkout_attempts(id)` CASCADE; `UNIQUE` |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE — `RoutingDecision::chosen()->accountId` |
| `payment_method` | VARCHAR(20) | yes | `RoutingDecision::$paymentMethod` |
| `purchase_type` | VARCHAR(20) | no | `RoutingDecision::$purchaseType` |
| `payload` | JSON | no | full `RoutingDecision::toArray()` (Phase 10 VO, reused as-is) — candidates considered, chosen account, rejection reasons |
| `created_at` | DATETIME | no | write-once — **no `updated_at`** |

`UNIQUE (checkout_attempt_id)` = `uniq_provider_routing_decision_snapshots_attempt`;
`INDEX (client_id, provider_account_id)` = `idx_provider_routing_decision_snapshots_client_account`.

### Lifecycle (`CheckoutAttemptStatus`, Phase 18 Q3)

A monotonic-rank state machine with 9 ranked "happy path" statuses and 4 unranked exit statuses,
dictated in full by the user:

```text
1 started
2 pricing_resolved
3 voucher_reserved
4 provider_selected
5 provider_checkout_created
6 redirected_to_provider
7 returned_from_provider
8 confirmed
9 converted_to_payment
```

`transitionTo($new)` allows a move when, in order: (1) the current status is already terminal →
always **rejected**; (2) `$new === current` → **idempotent no-op**; (3) `$new` is one of the 4
exits (`failed` / `canceled` / `expired` / `abandoned`) → **allowed** from any non-terminal
status; (4) `$new === converted_to_payment` → allowed **only if** current is exactly `confirmed`
(`checkout_attempt.not_confirmed` otherwise); (5) else → allowed only if `rank($new) >
rank(current)` (`checkout_attempt.invalid_transition` otherwise) — **skipping ranks is allowed**
(e.g. no voucher used ⇒ `pricing_resolved → provider_selected` directly). Terminal states —
`converted_to_payment`, `failed`, `canceled`, `expired`, `abandoned` — accept no further
transition (`checkout_attempt.terminal`).

**Implemented for real in Phase 18:** `started → pricing_resolved`
(`ResolveCheckoutPricingHandler`); `pricing_resolved → voucher_reserved` when a voucher is used,
or directly `pricing_resolved → provider_selected` when none is (`ReserveCheckoutVoucherHandler` /
`SelectCheckoutProviderHandler`); `voucher_reserved → provider_selected`; the same-status no-op;
any non-terminal → `failed` / `canceled` / `expired` / `abandoned`
(`ChangeCheckoutAttemptStatusHandler`). **Modelled but not yet driven by a real caller:**
`provider_checkout_created`, `redirected_to_provider`, `returned_from_provider`, `confirmed`,
`converted_to_payment` — ready for Payments (Phase 20) and the provider adapters (Phase 21+).

`CheckoutAttempt::commercialSnapshot()` returns only the attempt's own immutable commercial
context (`checkout_attempt_id`, `client_id`, `client_user_ref`, `attempt_reference`, `package_id`,
`country`, `currency_code`, `purchase_type`, `payment_method`, `subscription_interval`, `status`)
— the exact shape a future `payments` row copies from; it does not reach into the decision
snapshots (those stay linked by `checkout_attempt_id`, not duplicated).

---

## Payments — aggregate & lifecycle (Phase 20)

The `Payments` module. A payment is created from exactly one confirmed `checkout_attempts` row
(Phase 20 Q1) — no other creation path exists. Three tables model the provider-facing side as a
deliberate hierarchy (Q3): `payments` → `payment_attempts` (one per distinct "try" against a
provider) → `provider_transactions` (one immutable row per raw call/response under an attempt).
`provider_customers` and `gateway_references` (Q4) are the durable-customer-identity and generic
reverse-lookup tables the Gateway Reference Lookup Rule calls for. There is no real provider
adapter yet (Phase 21+) — every status transition in this phase is caller-supplied, not derived
from a real provider response.

### `payments`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `checkout_attempt_id` | INT UNSIGNED | **yes** (Phase 26 Q2) | FK → `checkout_attempts(id)` CASCADE; **`UNIQUE`** — one payment per attempt (Q1). `NULL` for a subscription renewal charge (no checkout attempt of its own — created via `RecordSubscriptionPaymentHandler`, linked instead through `subscription_payment_links`); MySQL allows multiple `NULL`s under a `UNIQUE` index, so this doesn't weaken the one-payment-per-attempt guarantee |
| `client_user_ref` | VARCHAR(120) | yes | copied from the checkout attempt |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `country` | CHAR(2) | no | FK → `countries(code)` RESTRICT |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `amount_minor` | BIGINT UNSIGNED | no | frozen payable amount at creation — the voucher's `payable_minor` when one was reserved, else the pricing snapshot's `amount_minor`; never re-derived |
| `purchase_type` | VARCHAR(20) | no | copied from the checkout attempt |
| `payment_method` | VARCHAR(20) | yes | copied from the checkout attempt |
| `subscription_interval` | VARCHAR(20) | yes | copied from the checkout attempt |
| `status` | VARCHAR(20) | no | `PaymentStatus`, default `created` — see lifecycle below |
| `error_code` / `error_message` | VARCHAR(100) / VARCHAR(500) | yes / yes | set on a `failed` transition |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (checkout_attempt_id)` = `uniq_payments_checkout_attempt`; `INDEX (client_id, status)` =
`idx_payments_client_status`; `INDEX (package_id)` = `idx_payments_package`.

### `payment_attempts`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `payment_id` | INT UNSIGNED | no | FK → `payments(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `attempt_number` | SMALLINT UNSIGNED | no | 1, 2, 3… per payment, app-assigned |
| `status` | VARCHAR(20) | no | `PaymentAttemptStatus`: `started` / `succeeded` / `failed` — smaller and separate from `PaymentStatus` |
| `payment_method` | VARCHAR(20) | yes | |
| `error_code` / `error_message` | VARCHAR(100) / VARCHAR(500) | yes / yes | |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (payment_id, attempt_number)` = `uniq_payment_attempts_number`;
`INDEX (provider_account_id)` = `idx_payment_attempts_provider_account`.

### `provider_transactions`

Write-once event log — no `updated_at`, no update method on the repository port.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `payment_attempt_id` | INT UNSIGNED | no | FK → `payment_attempts(id)` CASCADE |
| `kind` | VARCHAR(30) | no | generic operation label (`authorize`/`capture`/`refund`/`void`/`status_check`/…), not FK'd |
| `request_payload` / `response_payload` | JSON | yes / yes | redacted before storage by the adapter writing them (Phase 21+) — never card data or secrets |
| `provider_status_raw` | VARCHAR(100) | no | the **unmapped** provider status string |
| `created_at` | DATETIME | no | write-once |

`INDEX (payment_attempt_id)` = `idx_provider_transactions_attempt`.

### `provider_customers`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `client_user_ref` | VARCHAR(120) | no | |
| `provider_customer_id` | VARCHAR(191) | no | the raw external customer id |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (provider_account_id, provider_customer_id)` = `uniq_provider_customers_account_ref`;
`INDEX (client_id, client_user_ref)` = `idx_provider_customers_client_user`.

### `gateway_references`

The generic, provider-agnostic reverse-lookup table (Q4).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `reference_type` | VARCHAR(30) | no | `GatewayReferenceType`: `checkout_session` / `payment_intent` / `order` / `transaction` / `subscription` / `customer` / `other` |
| `reference_value` | VARCHAR(191) | no | the raw provider id string |
| `checkout_attempt_id` | INT UNSIGNED | yes | FK → `checkout_attempts(id)` CASCADE; set when the reference is recorded before a `payments` row exists (Phase 24 Q1) |
| `payment_id` | INT UNSIGNED | yes | FK → `payments(id)` CASCADE; null when the reference is subscription-only or predates the payment |
| `subscription_id` | INT UNSIGNED | yes | FK → `subscriptions(id)` CASCADE (Phase 26) — set for a real provider Subscription-resource reference, recorded once `CreateSubscriptionHandler` creates the `subscriptions` row |
| `created_at` | DATETIME | no | write-once |

`UNIQUE (provider_account_id, reference_type, reference_value)` =
`uniq_gateway_references_account_type_value`; `INDEX (payment_id)` =
`idx_gateway_references_payment`; `INDEX (checkout_attempt_id)` =
`idx_gateway_references_checkout_attempt` (Phase 24); `INDEX (subscription_id)` =
`idx_gateway_references_subscription` (Phase 26). Exactly one of `checkout_attempt_id` /
`payment_id` / `subscription_id` is set on any row — app-enforced (`GatewayReference::forCheckoutAttempt()` /
`::forPayment()` / `::forSubscription()`), not a DB constraint: a provider checkout-session
reference (Stripe session id, Mollie payment id, PayPal order id) is created at
`checkout_attempts.status = provider_checkout_created`, before the attempt reaches `confirmed`
(`Payment::create()`'s precondition, Phase 20 Q1), so `checkout_attempt_id` is the only parent
available at that point; `payment_id` is filled in for references recorded afterward;
`subscription_id` is filled in once a `subscriptions` row exists (Phase 26 — the real provider
Subscription resource id, Stripe's `sub_...`, distinct from the checkout-session reference the
subscription's first charge still carries).

### Lifecycle (`PaymentStatus`, Phase 20 Q2)

An explicit allowed-next-statuses graph per status, not a single rank — a payment genuinely
branches, unlike `CheckoutAttemptStatus`'s linear happy path:

```text
created            → pending, canceled, failed
pending            → requires_action, authorized, paid, failed, canceled, expired
requires_action    → authorized, paid, failed, canceled, expired
authorized         → paid, canceled, expired, failed
paid               → refunded, partially_refunded, disputed
partially_refunded → refunded, disputed
disputed           → chargeback, paid   (resolved in the merchant's favor)
refunded, canceled, expired, failed, chargeback → (none — terminal)
```

`transitionTo($new)`: (1) if the current status is terminal → always **rejected**, including a
repeat of the current terminal status itself; (2) `$new === current` → **idempotent no-op**; (3)
else → allowed only if `$new` is in `current`'s `allowedNextStatuses()`
(`payment.invalid_transition` otherwise). `payment_attempts.status` (`started` / `succeeded` /
`failed`) is a separate, smaller enum — an attempt only answers "did this try work," while the
parent payment carries the branching lifecycle above.

### Resolution

**Creation** (`CreatePaymentHandler`): requires the checkout attempt's status to be `confirmed`;
reads the frozen `pricing_decision_snapshots` row for the base amount, and — if a
`voucher_decision_snapshots` row exists — substitutes the linked `voucher_redemptions.payable_minor`
instead; copies every other field from `CheckoutAttempt::commercialSnapshot()`; transitions the
attempt to `converted_to_payment`. Idempotent by `checkout_attempt_id`.

**Provider transactions** (`RecordProviderTransactionHandler`, Phase 20 Q5): reuses the payment's
latest attempt if it's still `started`, else opens a new one (`attempt_number` incrementing);
appends one `provider_transactions` row; optionally completes the attempt
(`succeeded`/`failed`, caller-supplied — never inferred from the new payment status); transitions
the payment per the graph above. `ChangePaymentStatusHandler` is the escape hatch for any other
transition (e.g. an admin-driven cancel).

---

## Webhooks (Phase 25)

Every inbound provider webhook is stored before any processing is attempted (CLAUDE.md).

### `webhook_events`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `provider_type_code` | VARCHAR(50) | no | denormalized copy, for admin filtering without a join |
| `event_id` | VARCHAR(191) | yes | the provider's event id (Mollie: the payment id — see below); null when signature verification failed |
| `event_type` | VARCHAR(100) | yes | null when signature verification failed |
| `raw_status` | VARCHAR(100) | yes | the unmapped provider status string; null when signature verification failed |
| `provider_reference` | VARCHAR(191) | yes | the resource id used for the `gateway_references` reverse lookup |
| `subscription_reference` | VARCHAR(191) | yes | Phase 29 Q2, additive — the subscription a renewal-charge webhook's payment belongs to (Mollie's `subscriptionId` / a Stripe invoice's `subscription`); resolved against a `GatewayReferenceType::Subscription` row when `provider_reference` doesn't resolve on its own |
| `raw_payload` | MEDIUMTEXT | no | the exact request body received, never re-encoded — replayable byte-for-byte |
| `headers` | JSON | yes | raw request headers (uppercased keys), needed to rebuild a `RawWebhook` (PayPal's verification reads 5 named headers) |
| `status` | VARCHAR(20) | no | `received` / `processing` / `processed` / `retry_pending` / `failed` (Q3) |
| `attempt_count` | SMALLINT UNSIGNED | no | default `0`; incremented by `markRetryPending()` / `markFailed()` |
| `error_code` / `error_message` | VARCHAR(191) / TEXT | yes / yes | set on the last failed attempt; cleared on success |
| `payment_id` | INT UNSIGNED | yes | FK → `payments(id)` CASCADE; set once processed |
| `processed_at` | DATETIME | yes | |
| `last_attempted_at` | DATETIME | yes | stamped by every `markProcessing()` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (provider_account_id, event_id, raw_status)` = `uniq_webhook_events_dedup` (Q2);
`INDEX (status)` = `idx_webhook_events_status` (the retry job's query); `INDEX (client_id)`;
`INDEX (payment_id)`. `max_attempts` is a code constant
(`ProcessWebhookEventHandler::MAX_ATTEMPTS = 5`), not a column.

**Dedup key is `(provider_account_id, event_id, raw_status)`, not `event_id` alone (Q2).** Mollie's
`parseWebhook()` returns the payment id itself as `eventId` — the same value for every status
change on that payment (`pending → paid`, then later `paid → refunded`). Deduping on `event_id`
alone would silently drop every status change after the first. `raw_status` in the key makes each
distinct status change its own row while still collapsing a true redelivery of the identical
event.

### Processing lifecycle (Q1, Q3 — user-specified)

**Webhooks only ever update an existing `Payment` (Q1)** — never create one, and never drive a
checkout attempt to `confirmed`. A webhook whose resolved gateway reference points to a checkout
attempt with no `Payment` yet is left `retry_pending` (`error_code = webhook.payment_not_found_yet`);
it self-heals on a later retry once the browser-return flow (or an authenticated status poll)
creates the `Payment` — no separate sweep mechanism was needed to make that work, since the
existing `webhook:retry-pending` retry loop already re-attempts it.

**Store → process split (Q3):** `IngestWebhookEventHandler` resolves the `{token}` to a
`ProviderAccount`, verifies the signature via the adapter's `parseWebhook()`, stores the row
(status `received`), then calls `ProcessWebhookEventHandler` — the shared processor — **inline, in
the same HTTP request**. A signature failure is stored too (status `failed` immediately, never
retried) but is a `401`, not a processing outcome. Once genuinely stored and verified, the HTTP
response is always `200` regardless of the inline processing outcome (Q4) — `webhook:retry-pending`
(a cron-invoked `src/Jobs/RetryPendingWebhookEvents.php`) is the sole retry mechanism, never the
provider's own redelivery.

`ProcessWebhookEventHandler` never re-contacts the provider or re-verifies the signature on
retry — it replays the already-parsed `event_id` / `raw_status` / `provider_reference` columns
already on the row. It resolves the `gateway_references` row (trying `payment_intent` then
`checkout_session` then `order` then `transaction`, in that order), then delegates the actual
status transition to the existing (Phase 20) `RecordProviderTransactionHandler` — duplicate
delivery and retry are both safe because `PaymentStatus::transitionTo()`'s own same-status-no-op /
illegal-transition-rejected guard is what's actually doing the idempotency work, not anything
webhook-specific. A domain-rule rejection from that handler (e.g. an illegal transition) is
treated as a **definitive** failure (straight to `failed`, no retry attempts consumed) since
replaying the same status will never produce a different outcome; any other failure is
`retry_pending` until `attempt_count` reaches `MAX_ATTEMPTS`, then `failed`.

### URL & authentication (Q5)

`POST /api/v1/webhooks/{provider}/{token}` — **public**, outside the authenticated `/api/v1`
group (a provider never sends a Gomrok API key). `{token}` is `provider_account_endpoints.token`
(Phase 9); `{provider}` is read only for logging/readability, never trusted — this path was
already hardcoded into `AddProviderAccountEndpointHandler`'s `$inboundPath` output back in Phase 9,
so Q5 confirmed the existing shape rather than choosing a new one. The endpoint's decrypted
signing secret comes from `ProviderAccountCredentials::endpointSigningSecret($accountId,
'webhook')` — resolved fresh on every attempt (inline and retry alike), so a rotated secret is
picked up immediately without needing to touch any stored webhook row.

---

## Subscriptions (Phase 26)

The `Subscriptions` module. A `Subscription` is created from exactly one confirmed
`checkout_attempts` row (Q1 — reuses the Checkout pipeline, the same origin `payments` already
requires) once `ReconcileCheckoutStatusHandler` sees the attempt's `purchaseType` is
`subscription`; `subscription_events` is a write-once log mirroring `provider_transactions`;
`subscription_payment_links` ties a subscription to every `payments` row charged under it —
including renewal charges, which have no `checkout_attempts` row of their own (Q2).
`subscriptions` has **no `country` column** — a mid-phase user correction (see
`PhaseDecisions.md`'s Phase 26 addendum); a handler that needs one (e.g.
`RecordSubscriptionPaymentHandler`) reads it from the origin `checkout_attempts.country` via
`checkout_attempt_id`.

### `subscriptions`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `client_user_ref` | VARCHAR(120) | no | **mandatory** (Q4) — unlike `payments.client_user_ref`, which stays nullable |
| `checkout_attempt_id` | INT UNSIGNED | no | FK → `checkout_attempts(id)` CASCADE; **`UNIQUE`** — one subscription per originating attempt |
| `package_id` | INT UNSIGNED | no | FK → `packages(id)` CASCADE |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `amount_minor` | BIGINT UNSIGNED | no | the recurring charge amount, frozen at creation |
| `payment_method` | VARCHAR(20) | **yes** | nullable — mid-phase user correction (see addendum above) |
| `subscription_interval` | VARCHAR(20) | no | `SubscriptionInterval`: `monthly` / `quarterly` / `yearly` |
| `status` | VARCHAR(20) | no | `SubscriptionStatus`, default `active` — see lifecycle below |
| `trial_ends_at` | DATETIME | yes | set at creation when the package's resolved purchase capability grants a trial (Phase 12) |
| `current_period_start` / `current_period_end` | DATETIME | yes / yes | set by `recordPeriod()` when a billing period is known |
| `error_code` / `error_message` | VARCHAR(100) / VARCHAR(500) | yes / yes | set on a `past_due` transition |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (checkout_attempt_id)` = `uniq_subscriptions_checkout_attempt`; `INDEX (client_id,
client_user_ref)` = `idx_subscriptions_client_user`; `INDEX (client_id, status)` =
`idx_subscriptions_client_status`; `INDEX (package_id)` = `idx_subscriptions_package`.

### `subscription_events`

Write-once event log — no `updated_at`, no update method on the repository port. Mirrors
`provider_transactions`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `subscription_id` | INT UNSIGNED | no | FK → `subscriptions(id)` CASCADE |
| `kind` | VARCHAR(30) | no | generic label (`created` / `renewed` / `charge_failed` / `cancelled` / …), not FK'd |
| `provider_status_raw` | VARCHAR(100) | yes | the unmapped provider status string, when one exists |
| `payload` | JSON | yes | |
| `created_at` | DATETIME | no | write-once |

`INDEX (subscription_id)` = `idx_subscription_events_subscription`.

### `subscription_payment_links`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `subscription_id` | INT UNSIGNED | no | FK → `subscriptions(id)` CASCADE |
| `payment_id` | INT UNSIGNED | no | FK → `payments(id)` CASCADE; **`UNIQUE`** — a payment belongs to at most one subscription |
| `billing_period_start` / `billing_period_end` | DATETIME | yes / yes | |
| `created_at` | DATETIME | no | write-once |

`UNIQUE (payment_id)` = `uniq_subscription_payment_links_payment`; `INDEX (subscription_id)` =
`idx_subscription_payment_links_subscription`.

### Lifecycle (`SubscriptionStatus`)

An explicit allowed-next-statuses graph per status, the same pattern `PaymentStatus` established
(Phase 20 Q2) — active↔past_due can cycle, so a single rank doesn't fit:

```text
trialing → active, past_due, cancelled
active   → past_due, cancelled
past_due → active, cancelled
cancelled → (none — terminal)
```

`transitionTo($new)`: (1) terminal (`cancelled`) → always **rejected**; (2) `$new === current` →
**idempotent no-op**; (3) else → allowed only if `$new` is in `current`'s
`allowedNextStatuses()` (`subscription.invalid_transition` otherwise). A `past_due` transition
stores `error_code`/`error_message`; every other transition clears them.

### Resolution

**Creation** (`CreateSubscriptionHandler`): called by `ReconcileCheckoutStatusHandler` right
after it creates the attempt's first `Payment`, for a `Confirmed` attempt whose `purchaseType` is
`subscription`. Idempotent by `checkout_attempt_id`. Resolves trial terms from
`PackagePurchaseCapabilityResolver` (Phase 12) for the attempt's country — never from the
request. Links the first `Payment` via `subscription_payment_links` and records a `created`
`subscription_events` row; records a `GatewayReference::forSubscription()` row when the provider
returned a real Subscription-resource id (Stripe's `subscriptionReference`, surfaced on
`ProviderPaymentStatus`; `null` for Mollie's provisional support).

**Renewal charges** (`RecordSubscriptionPaymentHandler`, Q2's "second creation path"): the one
place that creates a `payments` row outside `CreatePaymentHandler`. Idempotent by
`providerPaymentReference` — checked via the existing `GatewayReferenceType::PaymentIntent` row
before creating anything, so a duplicate delivery can never create a second payment for the same
charge. Reads `country` from the subscription's origin checkout attempt (no `country` column on
`subscriptions`); drives the new `Payment` through the existing `RecordProviderTransactionHandler`
unchanged; on success transitions the subscription to `active`, on failure to `past_due`.
**Not yet wired to an automatic trigger this phase** — see Phase26Result.md's Known Limitations
(webhook-driven renewal automation is deferred to Phase 29, alongside Q3's deferred Mollie
renewal scheduler).

**Cancellation** (`CancelSubscriptionHandler`): capability-gated on `Capability::SubscriptionCancel`
AND `adapter instanceof SupportsSubscriptions` (mirrors Phase 24 Q5b's "both" pattern for payment
actions). Reference resolution (`ResolveSubscriptionActionContext`) prefers the real
`GatewayReferenceType::Subscription` reference, falling back to the original `CheckoutSession`
reference recorded at the subscription's first checkout when no deeper one exists (Mollie's
provisional case, Q3).

---

## Client notifications (Phase 28)

Server-to-server callbacks from Gomrok to a client when a payment/subscription reaches a
notify-worthy status (Q5) — never an in-app/UI notification. `client_endpoints` (Phase 6) stays
the client-scoped default URL per purpose; a provider account may register its own override URL
for a purpose (Q1, user-specified hybrid), used only when that account produced the event.

### `provider_account_notification_overrides`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `provider_account_id` | INT UNSIGNED | no | FK → `provider_accounts(id)` CASCADE |
| `purpose` | VARCHAR(40) | no | same vocabulary as `client_endpoints.purpose` |
| `url` | VARCHAR(2048) | no | |
| `is_active` | BOOLEAN | no | default `true` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (provider_account_id, purpose)` = `uniq_pano_account_purpose`. Deliberately separate from
`provider_account_endpoints` (Phase 9), which is exclusively inbound (webhook/callback/return
verification) — no shared rows or concerns.

### `client_notification_logs`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `target_type` | VARCHAR(20) | no | `payment` \| `subscription` — polymorphic, same unenforced-FK shape as `audit_logs`/`error_logs` |
| `target_id` | INT UNSIGNED | no | no FK (polymorphic) |
| `purpose` | VARCHAR(40) | no | resolved `EndpointPurpose` — `payment_status` or `subscription_status` (refund statuses route through `payment_status`; `refund_status` stays reserved/unused, see Q-notes in Phase28Result.md) |
| `status_value` | VARCHAR(30) | no | the specific normalized status that triggered this — from Q5's curated lists only |
| `provider_account_id` | INT UNSIGNED | yes | FK → `provider_accounts(id)` SET NULL; the account that produced the event, used to resolve the Q1 override |
| `endpoint_url` | VARCHAR(2048) | no | **snapshot** of the resolved URL at enqueue time |
| `payload` | TEXT (JSON) | no | the exact body sent; stored once, resent unchanged on every retry |
| `status` | VARCHAR(20) | no | `pending` → `sent` \| `dead_lettered` |
| `attempt_count` | SMALLINT UNSIGNED | no | default `0` |
| `next_attempt_at` | DATETIME | yes | null once `sent`/`dead_lettered`; drives the retry job |
| `last_attempted_at` | DATETIME | yes | |
| `last_response_status` | SMALLINT UNSIGNED | yes | HTTP status of the last attempt |
| `last_response_body` | TEXT | yes | truncated at write time |
| `last_error` | VARCHAR(255) | yes | network-level failure when there was no HTTP response |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`INDEX (client_id, status, next_attempt_at)` = `idx_cnl_retry_scan` (the retry job's scan);
`INDEX (target_type, target_id)` = `idx_cnl_target`; `INDEX (client_id)` = `idx_cnl_client`.

**Backoff schedule (Q4, user-specified):** 1m, 5m, 30m, 2h, 6h, 12h, 24h, 24h across 8 attempts,
then `dead_lettered`. A code constant, not a column — mirrors `ProcessWebhookEventHandler::
MAX_ATTEMPTS` not being a column either.

**No DB-level uniqueness constraint on `(target_type, target_id, status_value)`.** Checked
`PaymentStatus::allowedNextStatuses()`: almost every status is reachable at most once per payment
— except `Paid ↔ Disputed`, which can legitimately cycle (a resolved dispute returns to `paid`; a
second chargeback re-enters `disputed`). A hard unique constraint would silently swallow that
second, real notification. Idempotency instead relies on two upstream guards that already exist:
`Payment::transitionTo()`'s same-status no-op guard (no event fires for a no-op transition) and
Phase 25's webhook-dedup (`uniq_webhook_events_dedup`) rejecting a true duplicate provider event
before it ever reaches a handler twice.

---

## Background jobs, reconciliation & observability (Phase 29)

### `jobs`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `type` | VARCHAR(60) | no | fixed, code-defined set — no lookup table |
| `payload` | TEXT (JSON) | yes | parameters for a one-off job; every recurring scan type needs none |
| `status` | VARCHAR(20) | no | `pending` → `processing` → `done` \| `failed` \| `dead_lettered` |
| `attempts` | SMALLINT UNSIGNED | no | default `0`, incremented on every claim regardless of outcome |
| `consecutive_failures` | SMALLINT UNSIGNED | no | default `0`; resets to `0` on success, drives the alert threshold (Q5 revised) |
| `total_failures` | INT UNSIGNED | no | default `0`; lifetime count, never resets |
| `last_failed_at` / `last_success_at` | DATETIME / DATETIME | yes / yes | most recent failure/success timestamps |
| `alerted_at` | DATETIME | yes | set once when `consecutive_failures` first crosses `Job::ALERT_THRESHOLD` (3); stays set while still failing so a new alert isn't raised every run; cleared on the next success |
| `alert_acknowledged_at` / `alert_acknowledged_by` | DATETIME / INT UNSIGNED | yes / yes | an admin silencing a still-failing job's alert without it re-firing before the next failure; no FK on `alert_acknowledged_by`, same unconstrained convention as `error_logs.resolved_by` / `reconciliation_findings.resolved_by` |
| `run_at` | DATETIME | no | when the job becomes claimable |
| `locked_at` / `locked_by` | DATETIME / VARCHAR(64) | yes / yes | claim markers, `SELECT ... FOR UPDATE SKIP LOCKED` |
| `last_error` | TEXT | yes | |
| `last_result` | TEXT (JSON) | yes | summary counts from the last run, shown on the admin Jobs screen |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`INDEX (status, run_at)` = `idx_jobs_claim_scan` (the claim query); `INDEX (type)` =
`idx_jobs_type`; `INDEX (alerted_at)` = `idx_jobs_alerted` (the admin screen's "currently
alerting" query). No `client_id` — every job type this phase introduces is a global scan across
clients, not a per-client entity.

**Unified, self-rescheduling design (Q1, user-confirmed).** Every recurring background task —
webhook retry and notification delivery/retry (migrated from their standalone Phase 25/28
`bin/*.php` scanners), the voucher stale-reservation sweep, the checkout-attempt abandonment
sweep, Mollie subscription renewal charging, and both reconciliation scans — is a job type here.
No separate cron-schedule table: when a handler finishes, it enqueues its own next occurrence
(`run_at = now + <fixed interval per type>`, a PHP constant, not a DB value).

**Recurring jobs never dead-letter from repeated failure, and never get escalating backoff (Q5
revised, user-specified).** A recurring job keeps rescheduling at its fixed interval forever, no
matter how many consecutive failures — only a genuinely one-off job type (none exist yet) uses
the classic single-attempt terminal `failed`/`dead_lettered` behavior. Instead, health is tracked
and surfaced: `consecutive_failures` crossing `Job::ALERT_THRESHOLD` (3) raises a visible alert on
the admin Jobs screen once per failure episode (not on every subsequent failure — `alerted_at`
guards this), which an admin can acknowledge or which clears automatically on the job's next
success. Per-occurrence failure detail for debugging is **not** duplicated in a new table — a job
handler's returned `JobRunResult::failure()` (not just a thrown exception) is now also logged to
the existing `error_logs` table (`source: 'job'`), so history lives in the Error Logs screen
already built for exactly this purpose.

### `reconciliation_findings`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `target_type` | VARCHAR(20) | no | `payment` \| `subscription` — polymorphic, same shape as `client_notification_logs.target_type` |
| `target_id` | INT UNSIGNED | no | no FK (polymorphic) |
| `local_status` | VARCHAR(30) | no | Gomrok's status at detection time |
| `provider_status_raw` | VARCHAR(100) | no | the raw status the provider returned when polled |
| `mapped_provider_status` | VARCHAR(30) | yes | raw status mapped through the adapter's `mapProviderStatusToInternalStatus()`; null if unmapped |
| `detected_at` | DATETIME | no | |
| `resolved_at` / `resolved_by` | DATETIME / INT UNSIGNED | yes / yes | an admin marking a finding reviewed — same convention as `error_logs` (Phase 27) |
| `created_at` | DATETIME | no | |

`INDEX (client_id, resolved_at)` = `idx_reconciliation_findings_client_resolved` (the admin
report's "open findings" default view); `INDEX (target_type, target_id)` =
`idx_reconciliation_findings_target`.

**Detection only — never auto-corrects (deliberate, not a schema detail).** Reconciliation
records drift between Gomrok's local status and what the provider reports; it never calls
`transitionTo()` itself. Auto-correcting could apply a status Gomrok's own allowed-transitions
guard would otherwise reject, or mask a real bug instead of surfacing it. Every real status
change still comes from an actual webhook or an explicit admin action.

---

## Migrations & seeders

| File | Class |
| --- | --- |
| `src/Database/Migrations/20260908130001_create_currencies_table.php` | `Gomrok\Database\Migrations\CreateCurrenciesTable` |
| `src/Database/Migrations/20260908130002_create_countries_table.php` | `Gomrok\Database\Migrations\CreateCountriesTable` |
| `src/Database/Migrations/20260908130003_create_provider_types_table.php` | `Gomrok\Database\Migrations\CreateProviderTypesTable` |
| `src/Database/Seeds/CurrenciesSeeder.php` | `Gomrok\Database\Seeds\CurrenciesSeeder` |
| `src/Database/Seeds/CountriesSeeder.php` | `Gomrok\Database\Seeds\CountriesSeeder` (depends on `CurrenciesSeeder`) |
| `src/Database/Seeds/ProviderTypesSeeder.php` | `Gomrok\Database\Seeds\ProviderTypesSeeder` |
| `src/Database/Migrations/20260908140001_create_idempotency_keys_table.php` | `Gomrok\Database\Migrations\CreateIdempotencyKeysTable` |
| `src/Database/Migrations/20260908140002_create_audit_logs_table.php` | `Gomrok\Database\Migrations\CreateAuditLogsTable` |
| `src/Database/Migrations/20260908140003_create_error_logs_table.php` | `Gomrok\Database\Migrations\CreateErrorLogsTable` |
| `src/Database/Migrations/20260908150001_create_clients_table.php` | `Gomrok\Database\Migrations\CreateClientsTable` |
| `src/Database/Migrations/20260908150002_create_client_api_keys_table.php` | `Gomrok\Database\Migrations\CreateClientApiKeysTable` |
| `src/Database/Migrations/20260908150003_create_client_endpoints_table.php` | `Gomrok\Database\Migrations\CreateClientEndpointsTable` |
| `src/Database/Migrations/20260908150004_add_client_fks_to_cross_cutting_tables.php` | `Gomrok\Database\Migrations\AddClientFksToCrossCuttingTables` |
| `src/Database/Seeds/ClientsSeeder.php` | `Gomrok\Database\Seeds\ClientsSeeder` (env-gated: `local` / `testing` only) |
| `src/Database/Migrations/20260908170001_create_client_auth_attempts_table.php` | `Gomrok\Database\Migrations\CreateClientAuthAttemptsTable` |
| `src/Database/Migrations/20260909170001_create_provider_capabilities_table.php` | `Gomrok\Database\Migrations\CreateProviderCapabilitiesTable` |
| `src/Database/Migrations/20260909170002_create_provider_type_capabilities_table.php` | `Gomrok\Database\Migrations\CreateProviderTypeCapabilitiesTable` |
| `src/Database/Migrations/20260909170003_create_provider_type_purchase_types_table.php` | `Gomrok\Database\Migrations\CreateProviderTypePurchaseTypesTable` |
| `src/Database/Seeds/ProviderCapabilitiesSeeder.php` | `Gomrok\Database\Seeds\ProviderCapabilitiesSeeder` (from the `Capability` enum) |
| `src/Database/Seeds/ProviderTypeDeclarationsSeeder.php` | `Gomrok\Database\Seeds\ProviderTypeDeclarationsSeeder` (stripe + paypal + mollie + ziraat, from `data/ProviderTypeDeclarations.json`) |
| `src/Database/Migrations/20260909193001_create_provider_accounts_table.php` | `Gomrok\Database\Migrations\CreateProviderAccountsTable` |
| `src/Database/Migrations/20260909193002_create_provider_account_endpoints_table.php` | `Gomrok\Database\Migrations\CreateProviderAccountEndpointsTable` |
| `src/Database/Migrations/20260909193003_create_provider_account_countries_table.php` | `Gomrok\Database\Migrations\CreateProviderAccountCountriesTable` |
| `src/Database/Migrations/20260909193004_create_provider_account_methods_table.php` | `Gomrok\Database\Migrations\CreateProviderAccountMethodsTable` |
| `src/Database/Seeds/ProviderAccountsSeeder.php` | `Gomrok\Database\Seeds\ProviderAccountsSeeder` (env-gated: `local-dev` gets a test Stripe account) |
| `src/Database/Migrations/20260909220001_create_provider_groups_table.php` | `Gomrok\Database\Migrations\CreateProviderGroupsTable` |
| `src/Database/Migrations/20260909220002_create_provider_group_countries_table.php` | `Gomrok\Database\Migrations\CreateProviderGroupCountriesTable` |
| `src/Database/Migrations/20260909220003_create_provider_group_accounts_table.php` | `Gomrok\Database\Migrations\CreateProviderGroupAccountsTable` |
| `src/Database/Migrations/20260909220004_create_provider_group_purchase_types_table.php` | `Gomrok\Database\Migrations\CreateProviderGroupPurchaseTypesTable` |
| `src/Database/Migrations/20260909220005_create_provider_group_methods_table.php` | `Gomrok\Database\Migrations\CreateProviderGroupMethodsTable` |
| `src/Database/Seeds/ProviderGroupsSeeder.php` | `Gomrok\Database\Seeds\ProviderGroupsSeeder` (env-gated: `local-dev` gets turkey / germany / netherlands / default groups) |
| `src/Database/Migrations/20260910120001_create_packages_table.php` | `Gomrok\Database\Migrations\CreatePackagesTable` |
| `src/Database/Migrations/20260910120002_create_package_countries_table.php` | `Gomrok\Database\Migrations\CreatePackageCountriesTable` |
| `src/Database/Migrations/20260910120003_create_package_currencies_table.php` | `Gomrok\Database\Migrations\CreatePackageCurrenciesTable` |
| `src/Database/Migrations/20260910120004_create_package_payment_methods_table.php` | `Gomrok\Database\Migrations\CreatePackagePaymentMethodsTable` |
| `src/Database/Migrations/20260910120005_create_package_provider_accounts_table.php` | `Gomrok\Database\Migrations\CreatePackageProviderAccountsTable` |
| `src/Database/Seeds/PackagesSeeder.php` | `Gomrok\Database\Seeds\PackagesSeeder` (env-gated: `local-dev` gets `starter` + `pro` packages, with purchase capabilities) |
| `src/Database/Migrations/20260910130001_create_package_purchase_capabilities_table.php` | `Gomrok\Database\Migrations\CreatePackagePurchaseCapabilitiesTable` |
| `src/Database/Migrations/20260910130002_create_package_country_purchase_capabilities_table.php` | `Gomrok\Database\Migrations\CreatePackageCountryPurchaseCapabilitiesTable` |
| `src/Database/Migrations/20260910130003_create_package_provider_definitions_table.php` | `Gomrok\Database\Migrations\CreatePackageProviderDefinitionsTable` |
| `src/Database/Migrations/20260910130004_add_display_fields_to_packages.php` | `Gomrok\Database\Migrations\AddDisplayFieldsToPackages` |
| `src/Database/Migrations/20260910140001_create_pricing_groups_table.php` | `Gomrok\Database\Migrations\CreatePricingGroupsTable` |
| `src/Database/Migrations/20260910140002_create_pricing_group_countries_table.php` | `Gomrok\Database\Migrations\CreatePricingGroupCountriesTable` |
| `src/Database/Migrations/20260910140003_create_default_package_prices_table.php` | `Gomrok\Database\Migrations\CreateDefaultPackagePricesTable` |
| `src/Database/Migrations/20260910140004_create_client_exchange_rates_table.php` | `Gomrok\Database\Migrations\CreateClientExchangeRatesTable` |
| `src/Database/Migrations/20260910140005_create_pricing_group_packages_table.php` | `Gomrok\Database\Migrations\CreatePricingGroupPackagesTable` |
| `src/Database/Migrations/20260910150001_create_price_rules_table.php` | `Gomrok\Database\Migrations\CreatePriceRulesTable` |
| `src/Database/Migrations/20260910160001_create_price_lists_tables.php` | `Gomrok\Database\Migrations\CreatePriceListsTables` (also backfills a control list per existing pricing group) |
| `src/Database/Seeds/PricingSeeder.php` | `Gomrok\Database\Seeds\PricingSeeder` (env-gated: `local-dev` gets default/dach/us groups + baselines + EUR→USD rate + two `pro` price rules + a control list per group + a disabled `dach` "List B · -10%" with an exact `pro` €21.00) |
| `src/Database/Migrations/20260910170001_create_voucher_tables.php` | `Gomrok\Database\Migrations\CreateVoucherTables` |
| `src/Database/Seeds/VouchersSeeder.php` | `Gomrok\Database\Seeds\VouchersSeeder` (env-gated: `local-dev` gets `WELCOME10` [10%, once/user] + `EU5` [`none` default, EUR/USD/GBP fixed overrides, `pro`-only]) |
| `src/Database/Migrations/20260911130001_create_voucher_redemptions_table.php` | `Gomrok\Database\Migrations\CreateVoucherRedemptionsTable` |
| `src/Database/Migrations/20260911150001_create_checkout_and_decision_snapshot_tables.php` | `Gomrok\Database\Migrations\CreateCheckoutAndDecisionSnapshotTables` |
| `src/Database/Migrations/20260911180001_create_payment_tables.php` | `Gomrok\Database\Migrations\CreatePaymentTables` |
| `src/Database/Migrations/20260913090001_add_checkout_attempt_id_to_gateway_references.php` | `Gomrok\Database\Migrations\AddCheckoutAttemptIdToGatewayReferences` |
| `src/Database/Migrations/20260913090002_add_hash_return_token_to_checkout_attempts.php` | `Gomrok\Database\Migrations\AddHashReturnTokenToCheckoutAttempts` |
| `src/Database/Migrations/20260913090003_create_price_list_assignments_table.php` | `Gomrok\Database\Migrations\CreatePriceListAssignmentsTable` |
| `src/Database/Migrations/20260914090001_create_webhook_events_table.php` | `Gomrok\Database\Migrations\CreateWebhookEventsTable` |
| `src/Database/Migrations/20260914120001_create_subscriptions_tables.php` | `Gomrok\Database\Migrations\CreateSubscriptionsTables` |
| `src/Database/Migrations/20260914120002_make_payments_checkout_attempt_id_nullable.php` | `Gomrok\Database\Migrations\MakePaymentsCheckoutAttemptIdNullable` |
| `src/Database/Migrations/20260914120003_add_subscription_id_to_gateway_references.php` | `Gomrok\Database\Migrations\AddSubscriptionIdToGatewayReferences` |
| `src/Database/Migrations/20260917150001_create_provider_account_notification_overrides_table.php` | `Gomrok\Database\Migrations\CreateProviderAccountNotificationOverridesTable` |
| `src/Database/Migrations/20260917150002_create_client_notification_logs_table.php` | `Gomrok\Database\Migrations\CreateClientNotificationLogsTable` |
| `src/Database/Migrations/20260917180001_create_jobs_table.php` | `Gomrok\Database\Migrations\CreateJobsTable` |
| `src/Database/Migrations/20260917180002_create_reconciliation_findings_table.php` | `Gomrok\Database\Migrations\CreateReconciliationFindingsTable` |
| `src/Database/Migrations/20260917180003_add_subscription_reference_to_webhook_events.php` | `Gomrok\Database\Migrations\AddSubscriptionReferenceToWebhookEvents` |
| `src/Database/Migrations/20260917235500_add_health_tracking_to_jobs_table.php` | `Gomrok\Database\Migrations\AddHealthTrackingToJobsTable` |

Seeders are idempotent (`INSERT … ON DUPLICATE KEY UPDATE`). Run:
`composer db:setup` (= `migrate` + `seed`). `composer db:reset` rolls everything back and rebuilds;
`composer db:fresh` does the same without seeding. `ClientsSeeder` creates the `local-dev` client
+ a fixed dev key and is a no-op unless `APP_ENV` is `local` or `testing`.
