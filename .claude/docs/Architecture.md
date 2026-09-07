# Architecture.md — Gomrok

The target architecture, decided in Phase 1 from first principles. Greenfield: no predecessor
system. This is a baseline sketch — module internals, the database schema, and adapter details
are designed in their own later phases. Where this file and `CLAUDE.md` differ on intent,
`CLAUDE.md` wins and this file is corrected.

Phase 1 decisions (see `PhaseResults/Phase01Result.md` for the Q&A):

| # | Decision |
| --- | --- |
| 1 | Module-based hexagonal, **per-module** `Domain / Application / Infrastructure / Http` layers |
| 2 | Cross-module: **direct calls through published interfaces** + an **in-process synchronous domain-event dispatcher** for reactions |
| 3 | **BIGINT auto-increment** primary/foreign keys + a **public ULID** column on every externally-visible row |
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
  Shared/
    Domain/         Money, Currency, CountryCode, Ulid, typed ids, Result, DomainError, Clock
    Application/    Command/Query bus contracts, DomainEvent + Dispatcher contracts, Pagination
    Infrastructure/ PDO helpers, transaction runner, ULID generator, JSON logger, event dispatcher impl
    Http/           Base action, JSON responder, error handler (DomainError → HTTP), middleware base
  Bootstrap/        AppFactory — the composition root (builds the DI container + Slim app)
  Http/             app-level HTTP endpoints owned by no module (e.g. HealthAction)
  Config/           container.php (PHP-DI), routes.php, Settings/DatabaseSettings, env loading
  Database/
    Migrations/     Phinx migration classes (first ones in Phase 4)
    Seeds/          Phinx seeders (reference data, Phase 4/5)
  Jobs/             queue worker entrypoint, job handlers registry
  Public/           index.php (Slim front controller)
```

- **`src/Bootstrap/`** and **`src/Http/`** are app-level (non-module) code: the composition root
  and endpoints that belong to no business module. Added in Phase 2 — see
  `PhaseResults/Phase02Result.md` / `PhaseDecisions.md` Phase 2 Q6.
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
Initial event catalogue (each module owns the events it raises):

| Event | Raised by | Typical subscribers |
| --- | --- | --- |
| `PaymentCreated` | Payments | (audit) |
| `PaymentStatusChanged` (→ paid / failed / expired / refunded …) | Payments | Notifications, Subscriptions, Reconciliation |
| `RefundRecorded` | Payments | Notifications |
| `SubscriptionStatusChanged` | Subscriptions | Notifications |
| `WebhookReceived` / `WebhookProcessed` | Webhooks | (audit, metrics) |
| `ClientNotificationFailed` | Notifications | (alerting, admin dead-letter) |

Async only where it must be (webhook processing, callback delivery): the entry point stores the
work and enqueues a job; the job handler does the real processing and dispatches the resulting
domain events. No event is used to cross a process boundary — the queue is.

## 6. Identifiers

- **Primary/foreign keys:** `BIGINT UNSIGNED AUTO_INCREMENT`. Tight InnoDB clustered index, cheap
  joins.
- **Public reference:** a `ulid CHAR(26)` column (Crockford base32, time-sortable), `UNIQUE`, on
  every row a client, provider, or admin URL can reference — clients, payments, subscriptions,
  packages, provider accounts, vouchers, refunds, webhook events, etc.
- **APIs, client callbacks, admin routes use the ULID, never the numeric `id`.** The numeric id
  never leaves the database boundary.
- Optional short type prefix for readability in logs/UX (e.g. `pay_…`, `sub_…`) — decided per
  entity in its module's phase; the stored value is the bare ULID.
- ULID generation lives in `Shared\Infrastructure\UlidGenerator` behind a `Shared\Domain\Ulid`
  value object; time source is the injected `Clock`.

## 7. Money

- Library: **`brick/money`** for all arithmetic — rounding modes, per-currency scale (JPY 0dp,
  USD/EUR 2dp, BHD/KWD 3dp — relevant to Turkey/Gulf/EU pricing), `allocate()` for splits,
  overflow-safe.
- Wrapped: `Shared\Domain\Money` is our value object; it holds a `Brick\Money\Money` internally
  and exposes only domain-meaningful operations (`fromMinor`, `plus`, `minus`, `multipliedBy`,
  `allocate`, `toMinor`, `currency`). The domain depends on our `Money`, never on `brick`.
- Storage: `amount_minor BIGINT` + `currency CHAR(3)` (ISO 4217). Never `FLOAT`/`DOUBLE`; a
  `DECIMAL` column only where a third party demands one.
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

**Capability descriptor (runtime gating).**
`ProviderCapabilities` is a value object listing flags (`one_time_payment`, `hosted_checkout`,
`redirect_payment`, `authorization`, `capture`, `refund`, `partial_refund`, `subscription`,
`subscription_cancel`, `customer_portal`, `webhook`, `return_url`, `three_d_secure`,
`manual_status_polling`, …). It is the intersection of: the provider type's declared caps, the
provider account's config, and the client/country config. The application layer checks it
**before** attempting an action and rejects unsupported combinations explicitly — never silently
downgrades a requested purchase type.

**Routing.** `ProviderRouter` resolves an ordered candidate list: client default order → country
/ provider-group override → filter by package, currency, method, requested purchase type,
capability → pick the first enabled, capable account. Result is snapshotted on the payment.

## 9. Resolution pipelines (sketch)

Order is deterministic and will be documented precisely in the Pricing/Vouchers/Providers phases.

```
PACKAGE LIST
  client + country + currency + method + purchase type (+ user)
    → pricing group match (→ default fallback group)
    → price list assignment (stable hash of user id)
    → per-package: resolved price, currency, available providers/methods/purchase types

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
| **DI** | PHP-DI; definitions in `src/Config/container.php`. Nothing is `new`-ed in a controller/handler. |
| **Config** | `.env` → typed settings object; secrets from env or a secret store, never committed, never logged. |
| **Logging** | Structured JSON via `Shared` logger; every payment/subscription flow carries `correlation_id`, `client_id`, `client_user_id?`, `package_id?`, `payment_id?`, `subscription_id?`, `voucher_id?`, `country`, `currency`, `purchase_type`, `payment_method?`, `provider`, provider txn/sub ids. Never log secrets. |
| **Idempotency** | `Idempotency-Key` on client writes → `idempotency_keys` table stores the response; provider webhook event ids deduped. Duplicate webhooks/retries never double-apply. |
| **Errors** | Domain returns `Result`/`DomainError`; a single HTTP error handler maps to status + problem body. Provider/network failures use retry-with-backoff; unrecoverable work → dead-letter / failed-jobs table, retryable from the admin panel. |
| **Background jobs** | A queue + worker (`src/Jobs/`). Webhook processing, callback delivery + retries, reconciliation, expired-payment cleanup, refund/subscription reconciliation, voucher reservation expiry. Webhook responses never block on processing. |
| **Security** | API-key auth + per-client scoping on every request; webhook signature verification; replay protection; admin RBAC enforced at UI **and** backend; provider secrets masked in the UI (reveal-on-demand). A client can never see another client's data. |

## 12. Testing approach

- **Unit** (`tests/Unit/`): domain + application. No DB, no network. Covers value objects
  (`Money`, `Ulid`), resolution engines (pricing, routing, voucher), status mapping, state
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
