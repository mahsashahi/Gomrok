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
| **Vouchers** | Voucher definitions, eligibility rules, usage limits, discount calculation, and a concurrency-safe redemption lifecycle (Phases 16–17 — implemented). |
| **Checkout** | The pre-payment lifecycle anchor: `checkout_attempts` and its monotonic-rank status machine, orchestrating pricing resolution, voucher reservation, and provider selection before a payment exists (Phase 18 — implemented). |
| **Payments** | Payment aggregate, internal status lifecycle, payment attempts, provider transactions, provider customers, gateway references, refunds/captures/cancellations; converts a confirmed `checkout_attempts` row via its `commercialSnapshot()` (Phase 20 — implemented; no real provider adapter or HTTP endpoint yet). |
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

### Pricing (Phase 13 — baseline, Phase 14 — dimension overrides, Phase 15 — A/B lists)

`Modules/Pricing`. The baseline of the pricing pipeline, its override layer, and the A/B
price-list layer; Phases 16–17 add vouchers, 18 tax/fee.

- **`pricing_groups`** — priority-ordered country grouping *for pricing* (separate from Phase 10
  provider groups). A country may be in several groups; the lowest `priority` wins, so a
  "Global iOS" overlay can shadow a regional group for one device. `is_default` is the fallback
  (no countries, resolved last, can't be disabled). One `currency_code` per group.
- **`default_package_prices`** — one baseline per package. **`client_exchange_rates`** —
  client-configured, effective-dated FX; used only for a `status=default` cross-currency resolve.
- **`pricing_group_packages`** — per `(group, package)`: `status` `default` / `override` /
  `disabled` + `amount`/`currency` (override) + `name`/`badge`/`highlighted` overrides +
  `display_order`. No row = implicit `default`.
- **`PriceResolver`** — group match → row → baseline / convert / override → `ResolvedPrice`
  (`source` = `baseline` / `converted` / `group_override`). **`PriceCatalog`** wraps
  `PackageCatalog` and attaches a base price to each package (browse list — no dimension rules).
- **`price_rules` (Phase 14)** — one `(client, package)` override table keyed by 7 nullable
  dimensions (`pricing_group_id`, `country_code`, `provider_account_id`, `payment_method`,
  `purchase_type`, `subscription_interval`, `currency_code`; null = wildcard). Each rule either
  overrides `amount_minor` (`is_available = 1`) or marks the combination not for sale
  (`is_available = 0`). **`PriceRuleResolver`** picks the winner — most matched dimensions →
  fixed dimension priority (`subscription_interval > purchase_type > payment_method >
  provider_account_id > currency_code > country_code > pricing_group_id`) → highest `id`. An
  available winner sets `ResolvedPrice.source = dimension_override` (+ `applied_rule_id` /
  `applied_dimensions`); an unavailable winner → `pricing.combination_unavailable`, **no
  fallback**. `PriceResolver::applyRules` runs this after the base price. Set via
  `bin/SetPriceRule.php` / `DeletePriceRule.php` / `ListPriceRules.php`.
- **`price_lists` + `price_list_packages` (Phase 15)** — A/B experiments inside a pricing group.
  Every group owns one **control** list (`is_control`, `factor 1.0000`, undeletable, never
  disabled — created with the group + backfilled). A non-control list shifts the base by
  `factor`, or by an exact `price_list_packages` amount per package. **`PriceListResolver`** runs
  between the base amount and `price_rules`: exact list-package amount → else `base × factor` →
  else base unchanged (`source = price_list` when moved). `ResolvedPrice` always carries
  `priceListId` / `priceListName` / `priceListFactor`. A `$priceListId` that is unknown, from
  another group, or disabled falls back to control (disable-fallback). Set via
  `bin/CreatePriceList.php` / `SetPriceListStatus.php` / `SetPriceListFactor.php` /
  `SetPriceListPackagePrice.php`; listed by `ListPriceLists.php`. **The visitor→list assignment
  itself (persistence, hashing, `visitor_ref` params) is deferred to Phase 24** (Phase 15
  Q4/Q5); until then `$priceListId` is always `null` and every resolve uses control.
- **HTTP:** `GET /api/v1/packages?country=…` (resolved catalogue, base prices),
  `GET /api/v1/packages/{packageId}?country=…` (one package from that same resolved catalogue —
  Phase 19 Q1/Q2; `{packageId}` accepts either the numeric id or the code, and a package that
  isn't sellable in the requested context is a `404 package.not_found_in_context`), and
  `GET /api/v1/pricing/resolve?package=…&country=…&method=…&purchase_type=…&interval=…` (one
  price, dimension rules applied) — client-authenticated. Gomrok never trusts a client-supplied
  price.

### Vouchers (Phase 16 — definitions & eligibility, Phase 17 — discount calc & redemption, Phase 19 — validate endpoint)

`Modules/Vouchers`. **Source of truth for all voucher behaviour: `.claude/Voucher.md`** — this
is the architecture-level summary; Phase 18 added the `voucher_decision_snapshots` table (below
and in §8 Checkout); Phase 20 wires real payments as the `attempt_reference`.

- **`vouchers`** — client-scoped (`UNIQUE (client_id, code)`), a validity window, an optional
  minimum purchase, `first_purchase_only`, a **default discount** (`default_discount_type`
  `none`/`percentage`/`full` — never `fixed`), and three **nullable usage-limit columns**
  (`max_total_redemptions` / `max_per_user` / `max_per_client`; `NULL` = unlimited on that axis —
  Phase 16 Q3, columns chosen over a per-scope child table) + a global `redeemed_count`,
  incremented atomically on confirm (Phase 17).
- **`voucher_eligibility_rules`** — one `(voucher, dimension, value)` table (Phase 16 Q1) across
  country / currency / package / provider account / payment method / purchase type /
  subscription interval; OR within a dimension, AND across, no rows = unrestricted.
- **`voucher_currency_discounts`** — a per-currency **override** of the default discount
  (Phase 16 Q2, extended by the user): resolution is override row → else the voucher default →
  else (`none`) not applicable. Each override fully specifies its own type (can differ from the
  default), with an optional percentage cap in that currency.
- **`VoucherEligibilityEvaluator`** reports **every** unmet condition, not just the first —
  status, window, client scope, every restricted dimension, discount applicability, same-currency
  minimum purchase, first-purchase-only (unknown vs. known-false are distinct reasons), and —
  since Phase 17 — the **global** (confirmed + live reservations), **per-user**, and
  **per-client** usage caps, via `VoucherUsagePort` (declared Phase 16, implemented Phase 17 by
  `PdoVoucherRedemptionRepository`).
- **`voucher_redemptions` (Phase 17)** — the reserve → confirm/release lifecycle, keyed by a
  caller-supplied `attempt_reference` (Phase 17 Q1; Phase 20 passes the real payment id).
  `reserved` counts toward every cap immediately, until `released`; no automatic expiry (a
  stale-reservation sweep is Phase 29). **`VoucherDiscountCalculator`** resolves the discount
  (override → default → inapplicable) against a real price, clamping first to the configured
  `max_discount_minor` cap then to the price, and reports both `nominalDiscountMinor` (pre-clamp)
  and `appliedDiscountMinor` (post-clamp). `ReserveVoucherRedemptionHandler` /
  `ConfirmVoucherRedemptionHandler` / `ReleaseVoucherRedemptionHandler` each open a transaction
  and `SELECT ... FOR UPDATE` the `vouchers` row first (Phase 17 Q3) — the de facto per-voucher
  mutex every writer shares — and Reserve re-runs the (now usage-aware) evaluator **inside** that
  lock as the authoritative gate. Reserve is idempotent by `attempt_reference`; Confirm/Release
  are idempotent on their own terminal state and reject the other one.
- Audited handlers (`CreateVoucher`, `UpdateVoucher`, `SetVoucherEligibility` full-replace,
  `SetVoucherCurrencyDiscount` / `RemoveVoucherCurrencyDiscount`, `SetVoucherUsageLimits`,
  `ChangeVoucherStatus`, `ReserveVoucherRedemption`, `ConfirmVoucherRedemption`,
  `ReleaseVoucherRedemption`) + `voucher:*` CLI + an env-gated seeder.
- **`ValidateVoucherHandler` (Phase 19 Q3)** — a non-locking, unlocked pre-check: resolves the
  package's price via `PriceResolver` (same as `pricing/resolve`, never trusts a client-supplied
  amount), runs the same `VoucherEligibilityEvaluator` used as `ReserveVoucherRedemptionHandler`'s
  authoritative gate, and — only when eligible — the Phase 17 `VoucherDiscountCalculator` for a
  discount preview. Nothing is reserved, redeemed, or written. **HTTP:**
  `GET /api/v1/vouchers/validate?package=…&country=…&code=…[&device=][&method=][&purchase_type=]
  [&interval=][&client_user_ref=][&first_purchase=]` — `GET`, not the `POST` CLAUDE.md suggests,
  for the same reason `pricing/resolve` is a `GET` (Phase 19 Q4): it mutates nothing, so it stays
  clear of the `/api/v1` write-idempotency requirement.

### Checkout (Phase 18 — pre-payment lifecycle anchor)

`Modules/Checkout`. Introduced at the user's explicit direction (Phase 18 Q1, a substantial
expansion of the originally proposed design) to make the pre-payment flow — the steps that
happen *before* a `payments` row can exist — a first-class, queryable, auditable thing rather
than transient in-memory state.

- **`checkout_attempts`** is the anchor. `attempt_reference` is the external, caller-supplied
  idempotent key (`UNIQUE (client_id, attempt_reference)`); `checkout_attempts.id` is the
  internal relational anchor that `pricing_decision_snapshots`, `voucher_decision_snapshots`, and
  `provider_routing_decision_snapshots` each FK to (one per owning module, Phase 18 Q4, each
  `UNIQUE (checkout_attempt_id)` and write-once — no update method on any of the three
  repository ports). `ReserveCheckoutVoucherHandler` reuses the checkout attempt's own
  `attempt_reference` as the voucher redemption's `attempt_reference` (Phase 17), so one
  caller-supplied string threads both records.
- **`CheckoutAttemptStatus`** (Phase 18 Q3, fully dictated by the user) is a monotonic-rank state
  machine: 9 ranked happy-path statuses (`started` → `pricing_resolved` → `voucher_reserved` →
  `provider_selected` → `provider_checkout_created` → `redirected_to_provider` →
  `returned_from_provider` → `confirmed` → `converted_to_payment`) plus 4 unranked exit statuses
  (`failed` / `canceled` / `expired` / `abandoned`) reachable from any non-terminal status.
  `transitionTo()` allows a same-status no-op, an exit from anywhere non-terminal,
  `converted_to_payment` only from `confirmed`, or any strictly-higher rank — **skipping ranks is
  allowed** (no voucher used ⇒ `pricing_resolved → provider_selected` directly). Once terminal, no
  further transition is accepted. Phase 18 drives `started → pricing_resolved → (voucher_reserved
  →) provider_selected`, the no-op, and any non-terminal → exit for real; `provider_checkout_created`
  through `converted_to_payment` are modelled (rank + guards + tests) for Payments (Phase 20) and
  the provider adapters (Phase 21+) to drive later.
- **`CreateCheckoutAttemptHandler`** / **`ResolveCheckoutPricingHandler`** /
  **`ReserveCheckoutVoucherHandler`** / **`SelectCheckoutProviderHandler`** /
  **`ChangeCheckoutAttemptStatusHandler`** each open one `Transactions::run()` that advances
  `checkout_attempts.status`, writes the owning module's decision snapshot (where applicable),
  and audits — atomically. `ResolveCheckoutPricingHandler` calls `PriceResolver` (Pricing),
  `ReserveCheckoutVoucherHandler` calls `ReserveVoucherRedemptionHandler` (Vouchers),
  `SelectCheckoutProviderHandler` calls `ProviderRouter` (Providers) — cross-module calls through
  each module's published `Application/` port, never through `Domain/` or `Infrastructure/`.
- **`CheckoutAttempt::commercialSnapshot()`** returns only the attempt's own immutable commercial
  context (client, package, country, currency, purchase type, payment method, subscription
  interval, status) — the exact shape a future `payments` row (Phase 20) copies at conversion
  time. No checkout-attempt or decision-snapshot row is ever deleted or mutated by that step —
  the full pre-payment history stays available for audit, debugging, abandoned-checkout tracking,
  and admin visibility, per the user's explicit requirement.
- Audited handlers + `checkout:*` CLI (`bin/CreateCheckoutAttempt.php`,
  `ResolveCheckoutPricing.php`, `ReserveCheckoutVoucher.php`, `SelectCheckoutProvider.php`,
  `SetCheckoutAttemptStatus.php`, `ListCheckoutAttempts.php`). No HTTP endpoint yet (mounts with
  the payment-creation flow, Phase 24).

### Payments (Phase 20 — aggregate & lifecycle)

`Modules/Payments`. Built with no real provider adapter yet (Phase 21+) and no HTTP endpoint yet
(Phase 24) — purely the aggregate, schema, and lifecycle this phase.

- **`payments`** is created from exactly one confirmed `checkout_attempts` row (Q1) —
  `checkout_attempt_id` is a required, `UNIQUE` FK, never populated any other way. `amount_minor`
  is frozen at creation from the checkout attempt's pricing/voucher decision snapshots (the
  voucher's `payable_minor` when one was reserved, else the pricing snapshot's `amount_minor`)
  and never re-derived later.
- **`PaymentStatus`** (Q2) is an explicit allowed-next-statuses graph per status, not a single
  rank — a payment genuinely branches (`paid` → `refunded`/`partially_refunded`/`disputed`;
  `disputed` → `chargeback` or back to `paid`). The graph was arrived at after a flagged
  conflict: the option first selected ("no rule engine yet") would have made this phase's own
  exit criterion ("rejection of illegal transitions tested") impossible to satisfy, so the
  explicit adjacency-list design was adopted instead. `transitionTo()` checks terminal before
  same-status, exactly like `CheckoutAttemptStatus` — a repeat of the current terminal status is
  rejected, not treated as a no-op.
- **`payment_attempts` → `provider_transactions`** (Q3) is a deliberate two-level hierarchy
  matching CLAUDE.md's three distinct Required Database Concepts: an attempt is one distinct
  "try" against a provider (`attempt_number` incrementing on retry), a transaction is one raw
  provider call/response under that attempt (write-once, `provider_status_raw` unmapped and
  never leaked into `PaymentStatus`). `payment_attempts.status` (`started`/`succeeded`/`failed`)
  is a separate, smaller enum from `PaymentStatus`.
- **`provider_customers` / `gateway_references`** (Q4) — a durable customer identity reused
  across payments vs. a generic, provider-agnostic reverse-lookup table
  (`reference_type` ∈ `checkout_session`/`payment_intent`/`order`/`transaction`/`subscription`/
  `customer`/`other`), the mechanism CLAUDE.md's Gateway Reference Lookup Rule calls for. No
  `subscription_id` column until Phase 26 adds it additively.
- **`CreatePaymentHandler`** / **`RecordProviderTransactionHandler`** / **`ChangePaymentStatusHandler`**
  / **`LinkProviderCustomerHandler`** (Q5) — the same one-handler-per-step pattern as every prior
  module. `RecordProviderTransactionHandler` reuses the payment's latest attempt while it's still
  `started`, else opens a new one; the attempt is only completed
  (`succeeded`/`failed`) via an explicit `attemptOutcome` parameter, never inferred from the
  payment's own status change. Audited handlers + `payment:*` CLI
  (`bin/CreatePayment.php`, `RecordProviderTransaction.php`, `SetPaymentStatus.php`,
  `LinkProviderCustomer.php`, `ListPayments.php`).

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
    → PriceResolver / PriceCatalog  [Pricing module — implemented Phase 13]
        pricing group match (priority, device, is_default last)
        → (group, package) row: status default / override / disabled
        → baseline / convert via client_exchange_rates / group override
        → ResolvedPrice (amount, currency, source, effective name/badge/highlighted)
        (PriceCatalog browse list stops here — no price lists, no dimension rules)

PRICE  (GET /api/v1/pricing/resolve — PriceResolver)
  1. client + package
  2. package default price (default_package_prices)
  3. pricing-group match + (group, package) row  →  baseline / converted / group_override
  3.5 price list (PriceListResolver)                         [Phase 15]
       - control list (default; the assigned list once Phase 24 wires assignment)
       - exact price_list_packages amount, else base x factor, else unchanged
       - unknown / foreign / disabled list id → control (disable-fallback)
  4. price_rules: most-specific matching rule                [Phase 14]
       - available rule  → override amount (source = dimension_override)
       - unavailable rule → pricing.combination_unavailable (hard stop, no fallback)
       - precedence: matched-dimension count → fixed dimension priority → highest id
  5. voucher eligibility check (VoucherEligibilityEvaluator, all reasons)   [Phase 16–17 — implemented]
       - status / window / client scope / scoping dimensions / discount applicability /
         minimum purchase / first-purchase / global + per-user + per-client usage caps
  6. resolve + apply voucher discount (currency override -> else default,   [Phase 17 — implemented]
     clamp to configured cap then to price -> nominal vs. applied amounts)
       (steps 1-6, unlocked and without step 6.5, are exactly what          [Phase 19 — implemented]
        GET /api/v1/vouchers/validate runs as a standalone preview —
        ValidateVoucherHandler)
  6.5 reserve -> confirm/release the redemption (FOR UPDATE on vouchers,    [Phase 17 — implemented]
      idempotent by attempt_reference; Phase 20 supplies the payment id)
  7. tax / fee rules (if any)                                [deferred — no phase yet]
  8. final payable amount  → snapshot (pricing_decision_snapshots /          [Phase 18 — implemented]
     voucher_decision_snapshots, keyed to one checkout_attempts row)

CHECKOUT ATTEMPT  (Checkout module — orchestrates the above, Phase 18 — implemented)
  1. start (client + package + country + currency + purchase type + attempt_reference)
       → checkout_attempts row, status = started (idempotent replay by attempt_reference)
  2. resolve pricing  → PriceResolver (steps 1-4 above) → pricing_decision_snapshots
       → status = pricing_resolved
  3. reserve voucher (optional)  → ReserveVoucherRedemptionHandler (steps 5-6.5 above)
       → voucher_decision_snapshots  → status = voucher_reserved
  4. select provider  → ProviderRouter (PROVIDER pipeline below) → provider_routing_decision_snapshots
       → status = provider_selected (reachable directly from pricing_resolved when no voucher)
  5. (Phase 21+) provider checkout / redirect / return / confirm → converted_to_payment
       — modelled now, driven once the real provider adapters exist

PAYMENT  (Payments module, Phase 20 — implemented; no real provider calls yet)
  1. create  → CreatePaymentHandler — requires checkout_attempts.status = confirmed (Q1)
       → amount_minor frozen from pricing_decision_snapshots / voucher_redemptions.payable_minor
       → checkout_attempts transitions to converted_to_payment  → payments row, status = created
  2. record a provider transaction (repeatable)  → RecordProviderTransactionHandler
       → reuses the payment's open attempt or starts a new one (attempt_number++)
       → appends a provider_transactions row  → payment transitions per the PaymentStatus graph (Q2)
  3. (Phase 21+) real provider adapter calls replace the caller-supplied newStatus/providerStatusRaw
       with values actually mapped from the provider's response
  4. any other transition  → ChangePaymentStatusHandler (the escape hatch, e.g. admin cancel)

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
- A/B price-list **visitor→list assignment** (persistence, deterministic bucketing,
  disable-fallback, `visitor_ref` params) — deferred from Phase 15 (Q4/Q5) to **Phase 24**
  (Payment creation flow); the `price_lists` data model + resolver hook exist from Phase 15.
- Voucher **stale-reservation sweep** — an abandoned `reserved` row never auto-expires in
  Phase 17 (Q2); deferred to **Phase 29** (background jobs). The originally-planned "Payments
  passes a real payment id as `attempt_reference`" idea is superseded — Phase 18 already reused
  the checkout attempt's own `attempt_reference` for the voucher redemption, and Phase 20's
  `payments.checkout_attempt_id` FK gives full traceability without a second identifier.
- Checkout attempt **automatic abandonment/expiry detection** — `abandoned_at` / `expired_at`
  columns exist on `checkout_attempts` (Phase 18) but no handler writes them yet; deferred to
  **Phase 29** (background jobs), same sweep as the voucher reservation cleanup.
- `checkout_attempts → payments` conversion is now implemented end to end
  (`CreatePaymentHandler`, **Phase 20**); the `provider_checkout_created` / `redirected_to_provider`
  / `returned_from_provider` / `confirmed` transitions on `checkout_attempts` remain modelled but
  undriven until the provider adapters exist (**Phase 21+**).
- Payments module leftovers from Phase 20: a real `PaymentProviderPort` and status mapping
  (**Phase 21** — the port — then per-provider adapters, Phases 21–23); any HTTP endpoint for
  payments (**Phase 24**, the payment-creation flow); a `gateway_references` writer wired to a
  real caller (repository + schema exist; no handler writes to it yet — the same "ahead of a real
  caller" pattern as `checkout_attempts.abandoned_at`); `gateway_references.subscription_id`
  (**Phase 26**, additive column once `subscriptions` exists); a `refunds` table for actual
  capture/refund action records (**Phase 24**).
- Queue technology choice (DB-backed vs Redis vs …) — Phase 29 (a `Jobs` port is defined earlier).
- `mkdocs` site + DB docs location convention (repo-root vs `.claude/docs/`) — Phase 4.
