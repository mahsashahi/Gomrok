# Architecture.md — Gomrok

The target architecture, decided in Phase 1 from first principles. Greenfield: no predecessor
system. This is a baseline sketch — module internals, the database schema, and adapter details
are designed in their own later phases. Where this file and `CLAUDE.md` differ on intent,
`CLAUDE.md` wins and this file is corrected.

Phase 1 decisions (see `.claude/PhaseResults/Phase01Result.md` for the Q&A):

| # | Decision |
| --- | --- |
| 1 | Module-based hexagonal, **per-module** `Domain / Application / Infrastructure / Http` layers |
| 2 | Cross-module: **direct calls through published interfaces** + an **in-process synchronous domain-event dispatcher** for reactions |
| 3 | ~~BIGINT PK + public ULID~~ → **changed 2026-09-08**: plain `INT AUTO_INCREMENT` PKs (from 1), plain `INT` FKs, no ULID/UUID, no typed-ID classes — `int` in DB and PHP (§6) |
| 4 | Money via **`brick/money`**, wrapped in our own `Money` value object; stored as integer **minor units + `CHAR(3)`** currency |
| 5 | Provider adapters: a **required core `PaymentProviderPort`** + **optional capability interfaces** (`SupportsSubscriptions`, `SupportsRefunds`, …) + a runtime `ProviderCapabilities` descriptor |

---

## 1. Principles

- **Hexagonal.** The domain and application layers never depend on Slim, PDO/MySQL, provider
  SDKs, HTTP clients, or any framework code. Those are reached only through **ports** (interfaces
  owned by domain/application) implemented by **adapters** in the infrastructure layer.
- **Modular.** Code is organised around business domains, not technical layers. A module owns its
  entities, use cases, ports, adapters, and HTTP surface.
- **Multi-client from day one.** Every business row is scoped to a `client_id`. No single client's
  behaviour is hard-coded; client-specific behaviour is configuration.
- **Gomrok owns provider communication.** Client apps never call a provider directly. Gomrok
  resolves package, price, provider, method, purchase type, and voucher **server-side** and never
  trusts a client-supplied price.
- **Simple over clever.** Prefer boring, explicit code. Add mechanism (events, caching, queues)
  in the phase that first needs it, not speculatively.

## 2. The dependency rule

```
        HTTP (Slim)            Jobs / CLI            Admin (server-rendered)
             │                     │                        │
             ▼                     ▼                        ▼
      ┌─────────────────────────────────────────────────────────┐
      │  Application layer  — use cases / command+query handlers │
      │                     — Ports (interfaces)                 │
      └─────────────────────────────────────────────────────────┘
             │  depends on                    ▲  implemented by
             ▼                                │
      ┌───────────────────┐          ┌────────────────────────────┐
      │   Domain layer     │         │  Infrastructure adapters    │
      │  entities, VOs,    │         │  MySQL repos, provider SDK  │
      │  domain services,  │         │  adapters, HTTP clients,    │
      │  domain events     │         │  logger, queue, clock       │
      └───────────────────┘          └────────────────────────────┘
```

- Arrows point **inward**. Domain depends on nothing. Application depends on Domain. Infrastructure
  and the entry points (HTTP, Jobs, Admin) depend on Application/Domain and are wired by the DI
  container.
- A controller/handler is thin: parse input → call one use case → map the result to a response.
- Provider SDKs appear **only** inside that provider's infrastructure adapter. Database queries
  appear **only** inside repositories/infrastructure.

### Request flow (create a payment, abbreviated)

```
POST /api/v1/payments
  → ClientAuthMiddleware (API key → Client, scope)
  → IdempotencyMiddleware (Idempotency-Key → stored result or continue)
  → CreatePaymentAction (Http)
      → CreatePaymentHandler (Application use case)
          → PricingResolver.resolve(ctx)            [Pricing module, via interface]
          → VoucherValidator.validate(ctx)          [Vouchers module, via interface]
          → ProviderRouter.route(ctx)               [Providers module, via interface]
          → PaymentProviderPort.createPayment(...)  [chosen adapter]
          → PaymentRepository.save(payment + snapshots + gateway refs)
          → dispatch PaymentCreated
      ← redirect / checkout URL
```

## 3. Module map

Each module lives at `src/Modules/<Name>/` with `Domain/`, `Application/`, `Infrastructure/`,
`Http/`, `Tests/` (Admin also has `Views/`).

| Module | Owns |
| --- | --- |
| **Clients** | Client applications, API keys (hashed), client settings, authentication & per-request scoping. |
| **Providers** | Provider types, provider accounts (per client, multiple per type), the capability model & capability resolution, country/group provider routing, provider adapters. |
| **Packages** | Client-scoped package catalogue, availability rules, purchase-type capabilities, package↔provider definitions (remote id + sync state). |
| **Pricing** | Pricing groups, default prices, override dimensions, the deterministic price-resolution engine, A/B price lists + visitor assignment. |
| **Vouchers** | Voucher definitions, eligibility rules, usage limits, discount calculation, concurrency-safe redemption lifecycle. |
| **Payments** | Payment aggregate, internal status lifecycle, payment attempts, provider transactions, provider customers, gateway references, refunds/captures/cancellations, decision snapshots. |
| **Subscriptions** | Subscription aggregate & ownership model, subscription events, subscription↔payment links, lifecycle from webhooks. |
| **Webhooks** | Inbound provider webhook ingestion (store-first), signature verification, idempotent processing, replay protection, reverse lookup to internal records. |
| **Notifications** | Outbound client callbacks per provider account, delivery, retry/backoff, dead-letter, admin retry. |
| **Admin** | Admin auth, RBAC (`admin`, `support_agent`), the server-rendered panel and its screens, error-log viewer, reconciliation views. |
| **Shared** | Cross-cutting building blocks (below). Depended on by every module; depends on no module. |

**Boundary rules**

- A module never imports another module's `Domain/` or `Infrastructure/` directly. It depends on
  a small interface published in the other module's `Application/` (e.g.
  `Modules\Pricing\Application\PricingResolver`), wired by DI.
- Reactions across modules go through domain events (§5), not chained calls.
- `Shared` holds only genuinely generic code — no business rules.

## 4. Folder layout

```
src/
  Modules/
    Clients/        { Domain, Application, Infrastructure, Http, Tests }
    Providers/      { Domain, Application, Infrastructure, Http, Tests }
    Packages/       { Domain, Application, Infrastructure, Http, Tests }
    Pricing/        { Domain, Application, Infrastructure, Http, Tests }
    Vouchers/       { Domain, Application, Infrastructure, Http, Tests }
    Payments/       { Domain, Application, Infrastructure, Http, Tests }
    Subscriptions/  { Domain, Application, Infrastructure, Http, Tests }
    Webhooks/       { Domain, Application, Infrastructure, Http, Tests }
    Notifications/  { Domain, Application, Infrastructure, Http, Tests }
    Admin/          { Domain, Application, Infrastructure, Http, Views, Tests }
  Shared/          (Phase 3 — no ID types; IDs are plain int)
    Domain/         Money, Currency, CountryCode, Result, DomainError, ErrorType
    Infrastructure/ SystemClock (PSR-20), CorrelationId, Logging/{LoggerFactory,CorrelationIdProcessor}, Persistence/TransactionRunner
    Http/           Action (base), JsonResponder (json + RFC-7807 problem), JsonErrorHandler, CorrelationIdMiddleware
    Application/    Command/Query + DomainEvent + Dispatcher contracts, Pagination  (added when first needed)
  Bootstrap/        AppFactory — the composition root (builds the DI container + Slim app)
  Http/             app-level HTTP endpoints owned by no module (e.g. HealthAction)
  Config/           container.php (PHP-DI), routes.php, Settings/DatabaseSettings, env loading
  Database/
    Migrations/     Phinx migrations — namespaced `Gomrok\Database\Migrations\`, `YYYYMMDDHHMMSS_*.php`
    Seeds/          Phinx seeders (`CurrenciesSeeder`, `CountriesSeeder`, `ProviderTypesSeeder`) + data/countries.json
  Jobs/             queue worker entrypoint, job handlers registry
  Public/           index.php (Slim front controller)
```

- **`src/Bootstrap/`** and **`src/Http/`** are app-level (non-module) code: the composition root
  and endpoints that belong to no business module. Added in Phase 2 — see
  `.claude/PhaseResults/Phase02Result.md` / `PhaseResults/PhaseDecisions.md` Phase 2 Q6.
- PHP namespace root: `Gomrok\` → `src/` (PSR-4). Class name == file name (PascalCase, satisfies
  `.claude/Rule.md`); non-class config files (`container.php`, `routes.php`) keep the
  conventional lowercase name (`Rule.md` §3.1).
- Tests mirror the tree under `tests/` split into `Unit/` (domain + application, no DB, no
  network) and `Integration/` (adapters, repositories, provider sandboxes).

## 5. Cross-module communication

**Commands & queries — direct, synchronous, via published interfaces.**
A module that needs another module's behaviour depends on an interface in that module's
`Application/` namespace; PHP-DI binds the implementation. Example:
`CreatePaymentHandler` depends on `Pricing\Application\PricingResolver`,
`Vouchers\Application\VoucherValidator`, `Providers\Application\ProviderRouter`.

**Reactions — an in-process synchronous domain-event dispatcher.**
`Shared\Application\DomainEventDispatcher` (implemented in `Shared\Infrastructure`). Handlers are
registered per module via DI. Events are dispatched **after** the triggering transaction commits.
*Status:* `Shared\Domain\DomainEvent` (marker) and the per-module `Domain/Events/*` classes
exist from Phase 6; the **dispatcher itself is not built yet** — it lands with the first
subscriber (a later module). Until then, use cases return their event(s) in the result and write
audit rows directly.

Initial event catalogue (each module owns the events it raises):

| Event | Raised by | Typical subscribers |
| --- | --- | --- |
| `PaymentCreated` | Payments | (audit) |
| `PaymentStatusChanged` (→ paid / failed / expired / refunded …) | Payments | Notifications, Subscriptions, Reconciliation |
| `RefundRecorded` | Payments | Notifications |
| `SubscriptionStatusChanged` | Subscriptions | Notifications |
| `WebhookReceived` / `WebhookProcessed` | Webhooks | (audit, metrics) |
| `ClientNotificationFailed` | Notifications | (alerting, admin dead-letter) |
| `ClientCreated` / `ClientUpdated` / `ClientDisabled` / `ClientEnabled` | Clients | (audit) |
| `ApiKeyIssued` / `ApiKeyRevoked` | Clients | (audit) |

Async only where it must be (webhook processing, callback delivery): the entry point stores the
work and enqueues a job; the job handler does the real processing and dispatches the resulting
domain events. No event is used to cross a process boundary — the queue is.

## 6. Identifiers

**Decision changed 2026-09-08** (was: BIGINT PK + public ULID — see `PhaseResults/PhaseDecisions.md` Phase 1
Q3). Plain numeric IDs, no abstraction:

- **Primary key:** every table has `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`, values
  from 1. **Not `BIGINT`.**
- **Foreign keys:** plain `INT UNSIGNED`.
- **No `ulid` / public-reference column.** No UUID, no typed-ID value objects, no per-entity ID
  classes (`PaymentId`, `ClientId`, …). Domain entities and repositories use `int` for identity,
  in PHP and in the DB alike.
- **The `id` is used directly everywhere** — DB, PHP, and API paths / callback URLs / admin
  routes (`/api/v1/payments/42`).
- **Enumeration is not prevented by the ID.** Sequential ints are guessable, so cross-tenant
  isolation is enforced by **authorization** — every query is scoped to the authenticated client
  (§11, `Rule.md` §8, `knowledge/TenantIsolation.md`). A client requesting another client's
  `id` gets 404/403, not the row.
- `INT UNSIGNED` holds ~4.29 billion rows/table — revisit only if a table realistically nears
  that.
- See `.claude/agents/DatabaseAgent.md` for the migration rules.

## 7. Money

- Library: **`brick/money`** for all arithmetic — rounding modes, per-currency scale (JPY 0dp,
  USD/EUR 2dp, BHD/KWD 3dp — relevant to Turkey/Gulf/EU pricing), `allocate()` for splits,
  overflow-safe.
- Wrapped: `Shared\Domain\Money` is our value object; it holds a `Brick\Money\Money` internally.
  API (Phase 3 Q1 — richer): `fromMinor`, `zero`, `toMinor`, `plus`, `minus`, `multipliedBy`,
  `allocate`, `percentage`, `ratioOf`, `equals`, `isZero/isPositive/isNegative`, `currency`,
  `format(locale)` (via `ext-intl`), `convertTo(Currency, rate)` (caller supplies the rate —
  `Money` never fetches rates). Rounding fixed to `HALF_EVEN`. The domain depends on our `Money`,
  never on `brick`.
- Storage: `amount_minor BIGINT` + `currency CHAR(3)` (ISO 4217). **`BIGINT` here is deliberate**
  — currency minor-unit amounts can exceed `INT` (e.g. large JPY sums); the "no BIGINT" rule
  (§6) is about primary/foreign keys only. Never `FLOAT`/`DOUBLE`; a `DECIMAL` column only where
  a third party demands one.
- Every payment/subscription creation persists a **price snapshot** (base, overrides applied,
  currency, discount, tax, fee, final) so later pricing changes never alter history.

## 8. Providers & capabilities

**Adapter shape (hybrid).**

```php
// Required of every provider adapter:
interface PaymentProviderPort {
    createPayment(CreatePaymentCommand): ProviderPaymentResult;      // hosted checkout / redirect
    getPaymentStatus(ProviderPaymentRef): ProviderPaymentStatus;
    verifyWebhookSignature(RawWebhook): bool;
    parseWebhook(RawWebhook): ParsedWebhookEvent;
    mapProviderStatusToInternalStatus(string): PaymentStatus;
    getCapabilities(): ProviderCapabilities;
}

// Optional — implemented only when the provider really supports it:
interface SupportsSubscriptions   { createSubscription(...); getSubscriptionStatus(...); cancelSubscription(...); mapProviderSubscriptionStatusToInternalStatus(...); }
interface SupportsRefunds         { refundPayment(...); }            // partial vs full flagged in capabilities
interface SupportsAuthCapture     { authorizePayment(...); capturePayment(...); cancelPayment(...); }
interface SupportsCustomerPortal  { createBillingPortalSession(...); }
interface SupportsManualPolling   { pollPaymentStatus(...); }        // e.g. Ziraat
```

- `StripeAdapter` implements the core + subscriptions + refunds + auth/capture + portal.
  `MollieAdapter` core + subscriptions + refunds (method-dependent). `PayPalAdapter` core +
  subscriptions + refunds. `ZiraatAdapter` core + manual polling **only** — it doesn't implement
  `SupportsSubscriptions`, so "subscribe via Ziraat" is impossible at the type level, not a
  runtime throw.

**Capability descriptor (runtime gating).** *Implemented Phase 8 — `Modules/Providers`.*
`Capability` (backed enum, 19 flags — `hosted_checkout`, `partial_refund`, `subscription_cancel`,
`three_d_secure`, `manual_status_polling`, …) is the source of truth; `provider_capabilities` is
a seeded mirror. **Purchase types** (`one_time_payment` / `recurring_payment` / `auto_charge` /
`subscription`) are a **separate** `PurchaseType` enum — the routing-level concept — deliberately
not capability flags (Phase 8 Q2). `ProviderCapabilities` is the immutable set VO; a provider
type's `ProviderTypeDeclaration` pairs it with the supported purchase types
(`provider_type_capabilities` / `provider_type_purchase_types`, seeded stripe + paypal +
mollie + ziraat as of Phase 10).
`ProviderCapabilityResolver` narrows the type declaration by payment method
(`MethodCapabilityRules` — an in-code placeholder until `provider_type_method_capabilities`
lands in Phase 9/12); account config (Phase 9) and client/country config (Phase 10) wrap it. The
application layer checks it **before** attempting an action and rejects unsupported combinations
explicitly — never silently downgrades a requested purchase type. `ProviderCatalog` is the
module's published read port.

**Provider accounts (Phase 9).** `provider_accounts` — a client's credentials per provider type
per `mode` (`live` / `test`, matched to the API key prefix by the Phase 10 router). The secret
key is `SecretCipher`-encrypted (§11 *Secrets at rest*); `provider_account_endpoints` holds
per-account webhook/callback verification config (token + encrypted signing secret);
`provider_account_countries` / `provider_account_methods` are the account-level filters the
router uses (capabilities are inherited from the type). `ProviderAccountDirectory` is the
published read port (no secrets); `ProviderAccountCredentials` is the separate decrypt path.

**Routing (Phase 10).** Provider groups are the **single** country→provider mechanism (Q1 — no
`country_provider_configs` tables). A `provider_group` binds a set of countries (+ optional
`device_type`, optional `currency_code`) to an ordered list of the client's provider accounts
(`provider_group_accounts.priority`), plus the purchase types / methods it sells
(`provider_group_purchase_types` / `_methods`). `is_default = true` is the fallback group (no
countries). `Modules\Providers\Application\Routing\ProviderRouter::route(RoutingRequest)`:

1. resolve the client's group for `(country, deviceType)` → else the `is_default` group → else
   `provider_routing.no_group_for_market`.
2. group must allow the requested purchase type (`provider_routing.purchase_type_not_enabled`) —
   this is where a subscription request in a one-time-only market **fails**, never downgrades.
3. group currency (if pinned) and group methods must match.
4. keep each `provider_group_accounts` entry only if: enabled, account active, `mode` matches
   the request (from the API key prefix), account serves the country, the provider-type
   declaration supports the purchase type, and the account's methods allow it — else record a
   `RejectionReason`.
5. survivors ordered by `priority`; `chosen = first`.

Returns a `RoutingDecision` VO (ordered `candidates` + `rejections` + resolved group) with a
`toArray()` / `fromArray()` snapshot contract — **not** persisted this phase; Phase 17 snapshots
it on the payment (Q3/Q4).

### Packages (Phase 11)

`Modules/Packages`. The client-owned catalogue: `packages` (one table with `client_id`, `code`
unique per client — no global catalogue, no `client_packages` junction) + four **fail-open**
availability join tables (`package_countries` / `_currencies` / `_payment_methods` /
`_provider_accounts` — an empty set for a dimension = available everywhere for it, Phase 11 Q2).
`status = disabled` is the per-client hide switch. Use cases: `CreatePackage`, `UpdatePackage`,
`ChangePackageStatus`, `SetPackageAvailability` (full-replace, audited `package.*`).

`PackageCatalog::resolve(clientId, country, currency, ?method)` → `list<ResolvedPackage>`: the
client's active, **sellable** packages whose dimensions all match, each with its provider
accounts narrowed to the client's active set and its country-effective purchase capabilities.
**No `price` (Phase 13)** yet; `GET /api/v1/packages` is mounted in Phase 13. `PackageDirectory`
is the raw read port.

**Purchase capabilities (Phase 12).** `package_purchase_capabilities` (the purchase types a
package supports + per-type trial / `durationMonths`) is **fail closed** — a package with none is
not sellable. `package_country_purchase_capabilities` **replaces** the global set for one
country. `PackagePurchaseCapabilityResolver::for(package, ?country)` returns the country-effective
set; the payment-creation flow (Phase 17) then intersects it with the provider group (Phase 10)
and the provider-type declaration (Phase 8) — reject, never downgrade. `packages` also gained
`badge` / `highlighted` / `client_package_id`.

**Provider definitions (Phase 12).** `package_provider_definitions` — one row per linked
`(package, provider account)` with a `sync_state` machine (`not_created` → `synced` → `drift` /
`not_needed`). `LinkPackageProvider` accepts a manual `remote_id` now; provider-API product
creation lands with each adapter (Phases 21–23). Editing a package flips its `synced` definitions
to `drift` in-transaction.

## 9. Resolution pipelines (sketch)

Order is deterministic and will be documented precisely in the Pricing/Vouchers/Providers phases.

```
PACKAGE LIST
  client + country + currency + method (+ purchase type, user)
    → PackageCatalog::resolve  [Packages module — implemented Phase 11–12]
        active + sellable packages of the client, kept if every availability dimension matches
        (country / currency / method — empty set = matches anything) and the
        country-effective purchase-capability set is non-empty
        → per package: id, code, name, description, metadata, badge, highlighted, clientPackageId,
          provider accounts narrowed to the client's active set, available methods,
          country-effective purchase capabilities (type + trial + durationMonths)
    → pricing group match (→ default fallback group)          [Phase 13]
    → price list assignment (stable hash of user id)          [Phase 13]

PRICE
  1. client + package
  2. package default price
  3. country / pricing-group override
  4. currency / provider / payment-method / purchase-type / interval override  (more specific wins)
  5. voucher eligibility check
  6. apply voucher discount
  7. tax / fee rules (if any)
  8. final payable amount  → snapshot

PROVIDER
  1. client default provider config
  2. country / provider-group override
  3. filter: package availability
  4. filter: currency
  5. filter: payment method
  6. filter: requested purchase type
  7. filter: declared provider capability
  8. select configured default, else next in priority order  → snapshot
     (no candidate → explicit rejection, never a downgrade)
```

## 10. Payment lifecycle

Internal statuses (providers map into these; provider strings never leak into core logic):
`created → pending → requires_action → authorized → paid → failed → canceled → expired →
refunded → partially_refunded → disputed → chargeback`. Unknown provider statuses are stored raw
and flagged, never dropped.

## 11. Cross-cutting

| Concern | Approach |
| --- | --- |
| **DI** | PHP-DI; shared definitions in `src/Config/container.php`, per-module in `src/Modules/<Name>/Infrastructure/definitions.php` (merged by `ContainerFactory`). Nothing is `new`-ed in a controller/handler. Use cases depend on the `Transactions` port, not `TransactionRunner` directly. |
| **Config** | `.env` → typed settings object; secrets from env or a secret store, never committed, never logged. |
| **Secrets at rest (Phase 9)** | Provider secret keys + webhook signing secrets stored encrypted — `Shared\Application\SecretCipher` port, `SodiumSecretCipher` default (libsodium, key from base64 `APP_ENCRYPTION_KEY`; missing key = boot-time error). Only `Modules\Providers\Application\ProviderAccountCredentials` decrypts (adapters only); everywhere else sees `secret_last_four`. A Vault / KMS `SecretCipher` can replace the default with no schema change. |
| **Logging** | Structured JSON via `Shared` logger; every payment/subscription flow carries `correlation_id`, `client_id`, `client_user_id?`, `package_id?`, `payment_id?`, `subscription_id?`, `voucher_id?`, `country`, `currency`, `purchase_type`, `payment_method?`, `provider`, provider txn/sub ids. Never log secrets. |
| **Idempotency** | `Idempotency-Key` on client writes → `IdempotencyMiddleware` + `idempotency_keys` (Phase 5, decision Q1: **lock + entity mapping**, no stored response bodies). Claim `processing` → run handler → `done` (records `target_type`/`target_id`) or `failed`. Replay of `done` re-serialises the entity's *current* state via an `IdempotentReplayResolver`; `processing` → 409; same key, different request fingerprint → 422. 24h TTL, purge job. Middleware wired to routes in Phase 7. Provider webhook event ids deduped separately. |
| **Audit log** | `AuditLogWriter` port + `audit_logs` (Phase 5, decision Q2: **event + full before/after row snapshots**, secret keys redacted). Called by sensitive admin write paths from Phase 6 on. Append-only. |
| **Error log** | `ErrorLogWriter` port + `error_logs` (Phase 5, decision Q3: **explicit writer only**, never a log handler). Backs the admin Error Logs screen (Phase 27). `JsonErrorHandler` logs unhandled non-HTTP exceptions here; provider/webhook/notification/job call sites added per phase. Writer failures are swallowed. |
| **Errors** | **Hybrid** (Phase 3 Q3): use cases **return `Result<T>`** (`ok` / `err(DomainError)`) for anything the caller must branch on — validation, business-rule / eligibility / capability rejections, "not found", invalid voucher, unsupported provider·country·method combo, idempotency conflicts. They **throw** for programmer errors, config errors, and infra/transport faults (DB down, provider timeout/5xx). The `Shared\Http` handler maps `DomainError` → 4xx problem body, uncaught `Throwable` → 500/502 (logged with stack trace + correlation id). Provider/network faults use retry-with-backoff first; unrecoverable work → dead-letter / failed-jobs table, retryable from the admin panel. |
| **Background jobs** | A queue + worker (`src/Jobs/`). Webhook processing, callback delivery + retries, reconciliation, expired-payment cleanup, refund/subscription reconciliation, voucher reservation expiry, expired-idempotency-key purge (`PurgeExpiredIdempotencyKeys` — Phase 5, a plain invokable + `bin/PurgeIdempotencyKeys.php` until the runner exists). Webhook responses never block on processing. |
| **Security** | API-key auth + per-client scoping on every request; webhook signature verification; replay protection; admin RBAC enforced at UI **and** backend; provider secrets masked in the UI (reveal-on-demand). A client can never see another client's data. |
| **API auth (Phase 7)** | `/api/v1` group behind `AuthenticationMiddleware` — `Authorization: Bearer gk_<mode>_<key_id>.<secret>`; `ApiKeyAuthenticator` (Clients) implements the `Shared\Http\ClientAuthenticator` port. Success → `ClientContext` (per-request holder) + `authClient*` attributes; failure → `401 unauthorized` (opaque) / `403 client_disabled`. `last_used_at` stamped throttled; every attempt logged to `client_auth_attempts`. `/health` is the only public route. Writes require `Idempotency-Key`. Rate limiting: deferred (own concern), `client_auth_attempts` is its groundwork. |

## 12. Testing approach

- **Unit** (`tests/Unit/`): domain + application. No DB, no network. Covers value objects
  (`Money`, `Currency`), resolution engines (pricing, routing, voucher), status mapping, state
  machines, capability gating.
- **Integration** (`tests/Integration/`): infrastructure adapters against a real MySQL (migrations
  applied) and provider **sandboxes** (Stripe/Mollie/PayPal test mode; a Ziraat stub until real
  credentials).
- Tests are written **with** the code each phase, and each phase result records the commands run
  and the real output.

## 13. Deferred to later phases

- Concrete database schema — incremental, per phase, gated by confirmation (Phases 4+).
- The exact provider-adapter method signatures and DTOs — Phase 21 (port) then per provider.
- The full domain-event list and handler wiring — grows per module.
- Admin panel structure and RBAC schema — Phase 27 (+ its schema proposed earlier when needed).
- Pricing/voucher/routing resolution *precise* ordering and edge cases — Phases 10, 14–17.
- Queue technology choice (DB-backed vs Redis vs …) — Phase 29 (a `Jobs` port is defined earlier).
- `mkdocs` site + DB docs location convention (repo-root vs `.claude/docs/`) — Phase 4.
