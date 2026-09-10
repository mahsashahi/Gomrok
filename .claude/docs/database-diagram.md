# Database diagram

Mermaid ER diagrams, grouped by module, plus the module map. **Living document** — kept in
lock-step with `database-design.md` and `db_explain.md` (`.claude/Rule.md` §5). The standalone
page `database-diagram.html` reads the Mermaid blocks from this file when served over HTTP.

## Module map

```mermaid
flowchart TD
    Ref["Reference data<br/>currencies · countries · provider_types"]
    Xc["Cross-cutting<br/>idempotency_keys · audit_logs · error_logs"]
    Clients["Clients<br/>clients · client_api_keys · client_endpoints"]
    Providers["Providers<br/>capabilities + purchase types (P8)<br/>provider_accounts + endpoints/countries/methods (P9)<br/>provider_groups + routing (P10)"]
    Packages["Packages<br/>packages + country/currency/method/provider availability (P11)<br/>purchase capabilities + provider definitions (P12)"]
    Pricing["Pricing<br/>pricing_groups + default_package_prices + client_exchange_rates + group-package rows (P13)"]
    Ref -.-> Clients
    Ref -.-> Providers
    Ref -.-> Packages
    Ref -.-> Pricing
    Clients --> Xc
    Clients --> Packages
    Providers --> Packages
    Clients --> Pricing
    Packages --> Pricing
    Clients --> Payments
    Providers --> Payments
    Packages --> Payments
    Pricing --> Payments
    Vouchers --> Payments
    Payments --> Subscriptions
    Payments --> Webhooks
    Payments --> Notifications
    Admin -.-> Payments

    classDef done fill:#d5f5e3,stroke:#27ae60;
    classDef todo fill:#f8f9fa,stroke:#adb5bd,color:#868e96;
    class Ref,Xc,Clients,Providers,Packages,Pricing done;
    class Vouchers,Payments,Subscriptions,Webhooks,Notifications,Admin todo;
```

Green = tables exist. Grey = designed in that module's phase.

## Reference data (Phase 4)

```mermaid
erDiagram
    currencies {
        int id PK
        char code UK "ISO 4217 alpha"
        smallint numeric_code UK "ISO 4217 numeric"
        varchar name
        tinyint minor_unit_scale "decimal places"
    }
    countries {
        int id PK
        char code UK "ISO 3166-1 alpha-2"
        varchar name
        char default_currency FK "-> currencies.code"
    }
    provider_types {
        int id PK
        varchar code UK
        varchar name
        tinyint requires_registration
        tinyint api_capable
    }

    currencies ||--o{ countries : "default_currency"
```

`provider_types` has no relationships yet — the capability tables that will reference it are
Phase 8.

## Cross-cutting (Phase 5)

```mermaid
erDiagram
    idempotency_keys {
        int id PK
        int client_id "FK -> clients (Phase 6)"
        varchar idempotency_key
        char request_fingerprint "sha256(method+path+body)"
        varchar status "processing | done | failed"
        varchar target_type "set when done"
        int target_id "set when done"
        smallint response_status
        datetime created_at
        datetime updated_at
        datetime expires_at "created_at + 24h"
    }
    audit_logs {
        int id PK
        varchar actor_type "admin_user | client | system"
        int actor_id
        int client_id "FK -> clients (Phase 6)"
        varchar action
        varchar target_type
        int target_id
        json before "full row, redacted"
        json after "full row, redacted"
        json context
        varchar correlation_id
        varchar ip
        varchar user_agent
        datetime created_at
    }
    error_logs {
        int id PK
        varchar level "error | critical"
        varchar source "http | webhook | provider | notification | job"
        text message
        varchar exception_class
        varchar code
        int client_id "FK -> clients (Phase 6)"
        varchar correlation_id
        json context "redacted"
        mediumtext stack_trace
        datetime created_at
        datetime resolved_at
        int resolved_by
    }
```

The `client_id` foreign keys were added in Phase 6 (`AddClientFksToCrossCuttingTables`):
`idempotency_keys` → CASCADE, `audit_logs` / `error_logs` → SET NULL.
`uniq_idempotency_keys_client_key (client_id, idempotency_key)` enforces one key per client.

## Clients (Phase 6)

```mermaid
erDiagram
    clients {
        int id PK
        varchar slug UK "immutable public handle"
        varchar name
        varchar status "active | disabled"
        char default_currency FK "-> currencies.code"
        char default_country FK "-> countries.code (nullable)"
        varchar timezone
        varchar notification_signing_secret "redacted in audit"
        datetime disabled_at
        int disabled_by
        varchar disabled_reason
        datetime created_at
        datetime updated_at
    }
    client_api_keys {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        varchar key_id UK "public lookup"
        char secret_hash "sha256(secret)"
        varchar prefix "gk_live | gk_test"
        char last_four
        varchar label
        varchar status "active | revoked"
        datetime created_at
        datetime last_used_at
        datetime expires_at
        datetime revoked_at
        int revoked_by
    }
    client_endpoints {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        varchar purpose "payment_status | subscription_status | refund_status"
        varchar url
        tinyint is_active
        datetime created_at
        datetime updated_at
    }

    clients ||--o{ client_api_keys : "issues"
    clients ||--o{ client_endpoints : "registers"
    currencies ||--o{ clients : "default_currency"
    countries ||--o{ clients : "default_country"
```

`UNIQUE (client_id, purpose)` on `client_endpoints` — one active URL per purpose.

## Client API authentication (Phase 7)

```mermaid
erDiagram
    client_auth_attempts {
        int id PK
        varchar outcome "success | failure"
        varchar reason "ok | missing_authorization | malformed_token | unknown_key | invalid_secret | key_expired | key_revoked | client_disabled"
        varchar key_id "parsed token id — never the secret"
        int client_id FK "-> clients.id (SET NULL)"
        varchar ip
        varchar user_agent
        varchar correlation_id
        datetime created_at
    }

    clients ||--o{ client_auth_attempts : "attempts"
```

Append-only. Written best-effort by `ApiKeyAuthenticator` on every `/api/v1` auth attempt. No
schema change to `client_api_keys` — `last_used_at` is now stamped (throttled ≤ 1/key/5 min).

## Providers (Phase 8)

```mermaid
erDiagram
    provider_types {
        int id PK
        varchar code UK
        varchar name
        tinyint requires_registration
        tinyint api_capable
    }
    provider_capabilities {
        int id PK
        varchar code UK "= Capability enum value"
        varchar label
        varchar description
        varchar capability_group "payment | refund | subscription | security | operational"
    }
    provider_type_capabilities {
        int id PK
        int provider_type_id FK "-> provider_types.id (CASCADE)"
        int capability_id FK "-> provider_capabilities.id (CASCADE)"
    }
    provider_type_purchase_types {
        int id PK
        int provider_type_id FK "-> provider_types.id (CASCADE)"
        varchar purchase_type "one_time_payment | recurring_payment | auto_charge | subscription"
    }

    provider_types ||--o{ provider_type_capabilities : "declares"
    provider_capabilities ||--o{ provider_type_capabilities : "declared by"
    provider_types ||--o{ provider_type_purchase_types : "supports"
```

Seeded for **stripe + paypal** only (Phase 8 Q5); ziraat / mollie capability rows land in their
adapter phases. `provider_capabilities` is a seeded mirror of the `Capability` enum. Payment
methods are **not** modelled at the type level (Q4).

## Providers — accounts (Phase 9)

```mermaid
erDiagram
    provider_accounts {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        int provider_type_id FK "-> provider_types.id (RESTRICT)"
        varchar slug "client-scoped"
        varchar name
        varchar mode "live | test"
        varchar status "active | disabled"
        varchar public_key "not secret"
        text secret_ciphertext "SecretCipher-encrypted"
        char secret_last_four
        datetime created_at
        datetime updated_at
    }
    provider_account_endpoints {
        int id PK
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        varchar kind "webhook | callback | return"
        varchar token UK "URL segment, nullable"
        text signing_secret_ciphertext "encrypted, nullable"
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    provider_account_countries {
        int id PK
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        char country_code FK "-> countries.code (RESTRICT)"
        datetime created_at
    }
    provider_account_methods {
        int id PK
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        varchar payment_method "PaymentMethod enum"
        datetime created_at
    }

    clients ||--o{ provider_accounts : "connects"
    provider_types ||--o{ provider_accounts : "of type"
    provider_accounts ||--o{ provider_account_endpoints : "verifies via"
    provider_accounts ||--o{ provider_account_countries : "serves"
    provider_accounts ||--o{ provider_account_methods : "offers"
```

Secret keys are encrypted with `Shared\Application\SecretCipher` (`SodiumSecretCipher`, key from
`APP_ENCRYPTION_KEY`). Account-level capability narrowing is **not** modelled — inherited from
the provider type (Phase 9 Q4). `local-dev` gets a seeded `stripe/test` account
(`APP_ENV ∈ {local, testing}` only).

## Providers — routing (Phase 10)

```mermaid
erDiagram
    provider_groups {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        varchar slug "client-scoped"
        varchar name
        tinyint is_default "fallback group, no countries"
        varchar device_type "web | ios | android | NULL"
        char currency_code FK "-> currencies.code (RESTRICT), nullable"
        varchar status "active | disabled"
        datetime created_at
        datetime updated_at
    }
    provider_group_countries {
        int id PK
        int provider_group_id FK "-> provider_groups.id (CASCADE)"
        char country_code FK "-> countries.code (RESTRICT)"
        datetime created_at
    }
    provider_group_accounts {
        int id PK
        int provider_group_id FK "-> provider_groups.id (CASCADE)"
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        smallint priority "asc = tried first"
        tinyint is_enabled
        datetime created_at
    }
    provider_group_purchase_types {
        int id PK
        int provider_group_id FK "-> provider_groups.id (CASCADE)"
        varchar purchase_type "PurchaseType enum"
        datetime created_at
    }
    provider_group_methods {
        int id PK
        int provider_group_id FK "-> provider_groups.id (CASCADE)"
        varchar payment_method "PaymentMethod enum"
        datetime created_at
    }

    clients ||--o{ provider_groups : "routes via"
    currencies ||--o{ provider_groups : "settles in"
    provider_groups ||--o{ provider_group_countries : "covers"
    countries ||--o{ provider_group_countries : "grouped by"
    provider_groups ||--o{ provider_group_accounts : "prioritises"
    provider_accounts ||--o{ provider_group_accounts : "listed in"
    provider_groups ||--o{ provider_group_purchase_types : "sells"
    provider_groups ||--o{ provider_group_methods : "allows"
```

Provider groups are the **only** country→provider mechanism (Phase 10 Q1 — no
`country_provider_configs` tables). `ProviderRouter` resolves the group for a request, then
filters the ordered accounts by mode / status / served country / purchase type (group set ∩
provider-type declaration) / method, and returns an in-memory `RoutingDecision` (ordered
candidates + rejections). Unsupported combinations are **rejected, never downgraded**. No
routing snapshot table this phase (Q4). `local-dev` gets seeded `turkey` / `germany` /
`netherlands` / `default` groups (`APP_ENV ∈ {local, testing}` only).

## Packages — catalog & availability (Phase 11)

```mermaid
erDiagram
    packages {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        varchar code "UNIQUE (client_id, code)"
        varchar name
        text description "nullable"
        varchar status "active | disabled"
        json metadata "nullable, free-form"
        varchar badge "nullable (P12)"
        tinyint highlighted "P12"
        varchar client_package_id "nullable (P12)"
        datetime created_at
        datetime updated_at
    }
    package_countries {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        char country_code FK "-> countries.code (RESTRICT)"
        datetime created_at
    }
    package_currencies {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        char currency_code FK "-> currencies.code (RESTRICT)"
        datetime created_at
    }
    package_payment_methods {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        varchar payment_method "PaymentMethod enum"
        datetime created_at
    }
    package_provider_accounts {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        datetime created_at
    }

    clients ||--o{ packages : "owns"
    packages ||--o{ package_countries : "sold in"
    countries ||--o{ package_countries : "market for"
    packages ||--o{ package_currencies : "priced in"
    currencies ||--o{ package_currencies : "currency for"
    packages ||--o{ package_payment_methods : "sold via"
    packages ||--o{ package_provider_accounts : "bought through"
    provider_accounts ||--o{ package_provider_accounts : "sells"
    packages ||--o{ package_purchase_capabilities : "sold as"
    packages ||--o{ package_country_purchase_capabilities : "restricted in"
    countries ||--o{ package_country_purchase_capabilities : "restricts"
    packages ||--o{ package_provider_definitions : "defined on"
    provider_accounts ||--o{ package_provider_definitions : "hosts"
```

```mermaid
erDiagram
    package_purchase_capabilities {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        varchar purchase_type "PurchaseType enum"
        tinyint has_trial
        smallint trial_days "nullable"
        smallint duration_months "nullable"
        datetime created_at
        datetime updated_at
    }
    package_country_purchase_capabilities {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        char country_code FK "-> countries.code (RESTRICT)"
        varchar purchase_type "PurchaseType enum"
        datetime created_at
    }
    package_provider_definitions {
        int id PK
        int package_id FK "-> packages.id (CASCADE)"
        int provider_account_id FK "-> provider_accounts.id (CASCADE)"
        varchar provider_side_name "nullable"
        varchar remote_id "nullable, indexed"
        varchar sync_state "not_created | synced | drift | not_needed"
        datetime last_synced_at "nullable"
        varchar last_error "nullable"
        datetime created_at
        datetime updated_at
    }
```

Client-owned catalogue (one `packages` table with `client_id`, `code` unique per client — no
global catalogue, no `client_packages` junction). Each of the four availability dimensions is
**fail open**: an empty set = available everywhere for that dimension (Phase 11 Q2); rows
restrict. A package's **purchase types** (Phase 12) are stored in `package_purchase_capabilities`
(fail **closed** — no rows = not sellable) with per-type trial / duration; a
`package_country_purchase_capabilities` override **replaces** the global set for one country.
`PackageCatalog::resolve(client, country, currency, ?method)` returns the active, market-matching
**sellable** packages with provider accounts narrowed to the client's active set and the
country-effective purchase capabilities. `package_provider_definitions` tracks where a package
exists on each provider account (`sync_state` 4-state machine; editing a package flips `synced` →
`drift`). **No price yet — Phase 13.** `local-dev` seeds `starter` (one-time) + `pro`
(one-time + subscription, 7-day trial) (`APP_ENV ∈ {local, testing}` only).

## Pricing — groups & default prices (Phase 13)

```mermaid
erDiagram
    pricing_groups {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        varchar slug "UNIQUE (client_id, slug)"
        varchar name
        smallint priority "ascending = checked first; default last"
        varchar device_type "web | ios | android | NULL"
        char currency_code FK "-> currencies.code (RESTRICT)"
        tinyint is_default "fallback, no countries"
        varchar status "active | disabled"
        datetime created_at
        datetime updated_at
    }
    pricing_group_countries {
        int id PK
        int pricing_group_id FK "-> pricing_groups.id (CASCADE)"
        char country_code FK "-> countries.code (RESTRICT); overlap allowed"
        datetime created_at
    }
    default_package_prices {
        int id PK
        int package_id FK "-> packages.id (CASCADE), UNIQUE"
        bigint amount_minor
        char currency_code FK "-> currencies.code (RESTRICT)"
        datetime created_at
        datetime updated_at
    }
    client_exchange_rates {
        int id PK
        int client_id FK "-> clients.id (CASCADE)"
        char base_currency FK "-> currencies.code (RESTRICT)"
        char quote_currency FK "-> currencies.code (RESTRICT)"
        decimal rate "1 base = rate quote"
        datetime effective_from
        datetime created_at
    }
    pricing_group_packages {
        int id PK
        int pricing_group_id FK "-> pricing_groups.id (CASCADE)"
        int package_id FK "-> packages.id (CASCADE)"
        varchar status "default | override | disabled"
        bigint amount_minor "override only, nullable"
        char currency_code FK "-> currencies.code, override only, = group currency"
        varchar name_override "nullable"
        varchar badge_override "nullable"
        tinyint highlighted_override "nullable"
        smallint display_order
        datetime created_at
        datetime updated_at
    }

    clients ||--o{ pricing_groups : "prices via"
    currencies ||--o{ pricing_groups : "settles in"
    pricing_groups ||--o{ pricing_group_countries : "covers"
    countries ||--o{ pricing_group_countries : "grouped by"
    packages ||--o| default_package_prices : "baseline"
    clients ||--o{ client_exchange_rates : "converts with"
    pricing_groups ||--o{ pricing_group_packages : "prices"
    packages ||--o{ pricing_group_packages : "priced in"
```

Priority-ordered country grouping (overlap allowed — lowest `priority` wins; `is_default` last).
`default_package_prices` is the one baseline per package; a `status=default` group-package in a
different currency converts via `client_exchange_rates` (the latest effective row).
`status=override` carries its own amount in the group currency; `status=disabled` hides the
package in that group; no row = implicit `default`. `PriceResolver` / `PriceCatalog` produce a
`ResolvedPrice` (`baseline` / `converted` / `group_override`); `GET /api/v1/packages` and
`GET /api/v1/pricing/resolve` mount this phase. `local-dev` seeds `default` (EUR) / `dach`
(DE/AT/CH, EUR, `pro` overridden €24) / `us` (USD, converted) groups + an EUR→USD rate.
