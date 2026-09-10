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
| — | (more business tables land per module from Phase 14) |

**Total: 35 tables.** Phase 6 also added the `client_id` foreign keys on the three Phase 5
cross-cutting tables (deferred from Phase 5).

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
| `token` | `VARCHAR(64)` | yes | **unique** (multi-NULL ok); segment in `/api/v1/webhooks/{provider}/{token}` (consumed Phase 25). Null for `return`. |
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

The **baseline** of pricing — Phase 14 layers dimension overrides, Phase 15 A/B lists, 16–17
vouchers. All client-scoped.

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
| `src/Database/Seeds/PricingSeeder.php` | `Gomrok\Database\Seeds\PricingSeeder` (env-gated: `local-dev` gets default/dach/us groups + baselines + EUR→USD rate) |

Seeders are idempotent (`INSERT … ON DUPLICATE KEY UPDATE`). Run:
`composer db:setup` (= `migrate` + `seed`). `composer db:reset` rolls everything back and rebuilds;
`composer db:fresh` does the same without seeding. `ClientsSeeder` creates the `local-dev` client
+ a fixed dev key and is a no-op unless `APP_ENV` is `local` or `testing`.
