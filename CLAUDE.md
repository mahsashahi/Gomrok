# CLAUDE.md

## Project Overview

This project is **Gomrok**, a brand-new, greenfield service. There is no legacy system, no prior codebase, and nothing to analyze, reuse, or migrate from. Do not assume or invent any predecessor.

Gomrok is a standalone, multi-client payment orchestration service. It must not be designed only for Televika.

Televika is only the first client application. In the future, other websites, mobile applications, internal platforms, or external platforms should be able to connect to Gomrok without changing the core system.

Gomrok is responsible for handling all communication between client applications and payment providers.

Client applications must never communicate directly with payment providers.

## Main Responsibilities

Gomrok must:

* Receive payment requests from client applications.
* Route payment requests to the correct payment provider.
* Communicate with providers such as Stripe, PayPal, Mollie, Ziraat Bank Turkey, and future providers.
* Use provider-hosted checkout/payment UI when available.
* Receive webhooks, callbacks, return URLs, and status updates from payment providers.
* Verify payment status with the gateway API when needed.
* Normalize provider-specific results into Gomrok internal statuses.
* Store payment state in its own database.
* Store gateway references, internal references, user ownership, subscription ownership, status logs, webhook logs, notification logs, audit logs, and error logs.
* Notify the correct client application when payment status changes.
* Support multiple clients, merchants, applications, or projects.
* Manage package lists inside Gomrok.
* Support voucher creation, validation, redemption, usage limits, and voucher-based discounts.
* Support different pricing rules per client, country, currency, gateway, payment method, package, or voucher.
* Support a default price configuration with country-specific price overrides.
* Support default providers and payment methods with country-specific overrides.
* Support country-specific purchase capabilities such as one-time payment, recurring payment, auto-charge, subscription, or a restricted subset of them.
* Keep provider-specific logic separate from core business logic.
* Make adding new clients and payment providers simple and safe.

## Backend Stack

Use the following backend stack:

* PHP: latest stable PHP version
* MySQL
* Composer
* Slim Framework 4
* PHP-DI for dependency injection

The backend must be clean, modular, testable, and maintainable.

The backend architecture must be modular and follow hexagonal architecture principles.

Use modern PHP practices, including:

* Strict typing where possible
* Composer autoloading
* Dependency injection
* PHP-DI container configuration
* Clear service classes
* Repository layer where useful
* DTOs or request objects where useful
* Environment-based configuration
* Secure secret management
* Proper error handling
* Structured logs

## Backend Architecture Requirement

The backend must use a **hexagonal architecture** and a **modular architecture**.

Gomrok must be organized around business modules and domain boundaries, not around technical layers only.

The architecture should separate:

* Core domain logic
* Application use cases
* Ports / interfaces
* Infrastructure adapters
* HTTP controllers
* Database repositories
* Payment provider adapters
* Background jobs
* Admin panel handlers

Core business logic must not depend directly on Slim, MySQL, external payment SDKs, HTTP clients, or framework-specific code.

External systems must be connected through adapters.

Examples of external adapters:

* Stripe adapter
* PayPal adapter
* Mollie adapter
* Ziraat adapter
* MySQL repositories
* Client notification HTTP adapter
* Webhook verification adapter
* Queue/job adapter
* Logger adapter

Suggested module-based structure:

```text
src/
  Modules/
    Clients/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Packages/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Pricing/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Vouchers/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Payments/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Providers/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Webhooks/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Notifications/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Subscriptions/
      Domain/
      Application/
      Infrastructure/
      Http/
      Tests/

    Admin/
      Domain/
      Application/
      Infrastructure/
      Http/
      Views/
      Tests/

  Shared/
    Domain/
    Application/
    Infrastructure/
    Http/

  Config/
  Database/
  Jobs/
  Public/
```

Each module should be responsible for its own business area.

Recommended module responsibilities:

* `Clients`: client applications, API keys, authentication, client settings
* `Packages`: package catalog, package availability, package metadata, package activation state
* `Pricing`: default package prices, country overrides, currency rules, provider-specific prices, payment-method prices, and final price resolution
* `Vouchers`: voucher definition, validation, redemption, eligibility, discount calculation, and usage tracking
* `Payments`: payment creation, status lifecycle, refunds, captures, cancellations
* `Providers`: provider registry, provider capabilities, provider adapters
* `Webhooks`: incoming provider webhooks, signature verification, webhook event storage
* `Notifications`: callbacks from Gomrok to client systems
* `Subscriptions`: subscription ownership, provider subscriptions, subscription events
* `Admin`: admin panel, dashboards, manual retry tools, reconciliation views

Hexagonal architecture rules:

1. Domain entities must not depend on framework code.
2. Application services/use cases should orchestrate business logic.
3. Infrastructure code should implement interfaces defined by the domain or application layer.
4. Controllers should be thin and only handle HTTP input/output.
5. Provider SDKs must only be used inside provider infrastructure adapters.
6. Database queries must only be used inside repositories or infrastructure services.
7. Business rules must not be placed inside controllers.
8. Business rules must not be placed inside provider SDK wrappers only.
9. Unit tests should target domain logic and application use cases without requiring real providers or a real database.
10. Integration tests can test infrastructure adapters, database repositories, and provider sandbox behavior.

Example dependency direction:

```text
HTTP Controller
  -> Application Use Case
    -> Domain Service / Domain Entity
    -> Port Interface
      <- Infrastructure Adapter
```

Bad:

```text
PaymentController directly calls Stripe SDK and updates MySQL.
```

Good:

```text
PaymentController calls CreatePaymentUseCase.
CreatePaymentUseCase uses PaymentProviderPort.
StripePaymentAdapter implements PaymentProviderPort.
PaymentRepository saves payment state.
```

The project should stay modular and hexagonal even if Slim 4 is used as the HTTP framework.

## Frontend Stack

Use a lightweight frontend stack:

* Alpine.js
* Tailwind CSS
* CSS

Do not use heavy frontend frameworks unless explicitly requested.

The frontend should only be used where needed, especially for the admin panel.

## Admin Panel Requirement

Gomrok must include an admin panel.

The admin panel should be lightweight and built with:

* Alpine.js
* Tailwind CSS
* CSS
* Server-rendered PHP views if needed

The admin panel should be used for:

* Managing clients, applications, or merchants
* Managing client API keys
* Managing package lists
* Managing package availability per client and country
* Managing default package prices
* Managing country-specific price overrides
* Managing provider and payment-method availability per country
* Managing default providers and country-specific provider overrides
* Managing one-time payment, recurring payment, auto-charge, and subscription availability per country
* Managing vouchers and voucher eligibility rules
* Viewing voucher usage and redemption history
* Managing provider configurations per client
* Viewing payments
* Viewing payment attempts
* Viewing provider transactions
* Viewing webhook events
* Viewing client notification logs
* Retrying failed client notifications
* Retrying failed jobs where safe
* Viewing subscription ownership
* Viewing gateway references
* Viewing audit logs
* Viewing error logs
* Basic reconciliation and debugging

The admin panel must respect multi-client isolation and access control.

No sensitive provider credentials or API secrets should be displayed in plain text.

## Admin Panel Role-Based Permission Requirement

The admin panel must include role-based access control.

For now, only these two roles are required:

* admin
* support_agent

Do not add more roles unless explicitly requested later.

Suggested responsibilities:

* `admin`: can manage operational settings, clients, packages, pricing, vouchers, payments, notifications, provider configurations, country overrides, admin panel access, and retry operations where allowed.
* `support_agent`: can view clients, users, packages, resolved prices, voucher status, payments, subscriptions, webhook status, and notification status, but cannot modify provider credentials, secrets, roles, permissions, pricing rules, voucher rules, or system-level configuration.

The admin panel must enforce permissions at both:

* UI level
* Backend/API level

Hiding a button in the UI is not enough. Every admin action must be checked on the backend.

Suggested permission examples:

* clients.view
* clients.create
* clients.update
* client_api_keys.view
* client_api_keys.create
* client_api_keys.revoke
* packages.view
* packages.create
* packages.update
* packages.disable
* pricing.view
* pricing.create
* pricing.update
* country_overrides.view
* country_overrides.create
* country_overrides.update
* vouchers.view
* vouchers.create
* vouchers.update
* vouchers.disable
* voucher_redemptions.view
* provider_configs.view
* provider_configs.create
* provider_configs.update
* payments.view
* payments.refund
* payments.cancel
* payments.capture
* subscriptions.view
* subscriptions.cancel
* webhooks.view
* webhooks.replay
* notifications.view
* notifications.retry
* jobs.view
* jobs.retry
* audit_logs.view
* error_logs.view
* reconciliation.view
* admin_users.view
* admin_users.create
* admin_users.update
* admin_users.disable

Do not design or create the admin permission database schema yet.

The admin role and permission database schema must be proposed later as a separate design and must be confirmed before implementation.

Admin security requirements:

* Store only password hashes, never plain passwords.
* Use secure password hashing.
* Do not log passwords, tokens, secrets, or full API keys.
* Store session tokens hashed if stored in database.
* Add login attempt logging.
* Add account status such as active, disabled, or locked.
* Add audit logs for sensitive admin actions.
* Sensitive actions must be logged, including provider config changes, secret rotation, refund actions, retries, pricing changes, voucher changes, country override changes, and permission changes.
* Consider two-factor authentication later if needed.

Permission checks must consider:

* Admin user role
* Permission key
* Client scope if applicable
* Action type
* Resource ownership

## Dependency Injection Requirement

Use dependency injection throughout the backend.

Use:

* PHP-DI

PHP-DI should be used for:

* Service registration
* Repository registration
* Provider adapter registration
* Controller dependencies
* Middleware dependencies
* Configuration dependencies
* Logger dependencies
* Database connection dependencies

Avoid creating services manually inside controllers.

Bad example:

```php
$service = new PaymentService();
```

Good example:

```php
public function __construct(private PaymentService $paymentService)
{
}
```

The project should have a clear dependency container configuration file.

Suggested location:

```text
src/Config/container.php
```

or:

```text
config/container.php
```

## Multi-Client Requirement

Gomrok must support multiple clients from day one.

Each client may have:

* Its own API credentials
* Its own enabled payment providers
* Its own provider credentials
* Its own callback URLs
* Its own currencies
* Its own countries
* Its own payment rules
* Its own enabled payment methods
* Its own subscription configuration
* Its own notification configuration
* Its own package list
* Its own package availability rules
* Its own default package prices
* Its own country-based price overrides
* Its own gateway-based prices
* Its own currency-based prices
* Its own payment-method-based prices
* Its own default providers
* Its own country-specific provider overrides
* Its own country-specific purchase capabilities
* Its own voucher rules
* Different prices for the same package depending on country, currency, gateway, payment method, voucher, or purchase capability

Example:

A package may have a default price, while Turkey may override that package price with a cheaper local price.

A country may also override the default provider selection.

For example:

* Turkey may use only Ziraat.
* Turkey may support one-time payment only.
* Turkey may not support auto-charge, recurring payment, or subscription.
* Germany may support Mollie Card.
* Germany may support Mollie PayPal.
* Germany may support both one-time payment and recurring payment when the selected provider capability allows it.
* The Netherlands may support PayPal only.

Another package may have different prices for Stripe, PayPal, Mollie, or Ziraat because of gateway fees, country rules, taxes, or business decisions.

Payment records must always belong to a specific client.

Webhook events, provider transactions, refunds, notifications, subscriptions, gateway references, package decisions, pricing decisions, voucher decisions, provider-routing decisions, and audit logs must also be linked to the correct client.

Do not hardcode Televika-specific logic inside Gomrok.

Any Televika-specific behavior should be handled through:

* Client configuration
* Client settings
* Integration adapter
* Database configuration

## Package Catalog Requirement

The package list must be managed inside Gomrok.

Client applications should request the package list from Gomrok instead of maintaining independent payment package definitions that may become inconsistent with Gomrok pricing and provider rules.

### Package Ownership Rule (client-scoped packages)

Packages are **client-scoped**. Each package belongs to exactly one client via a `client_id` column on the `packages` table.

* There is **no** global package catalogue and **no** `client_packages` junction table. The two are merged into a single `packages` table that carries `client_id`.
* Package `code` is unique **per client** (`UNIQUE (client_id, code)`), not globally. The same `code` may recur across different clients.
* Per-client availability is the package row's own `status` (`active` / `disabled`) — there is no separate per-client enable/disable table.
* Do not add a `packages.enable_for_client` permission; creating or `status`-toggling a client's package row is the enable/disable action.
* Every query, index, and FK that touches `packages` must be client-scoped like every other business table (composite indexes lead with `client_id`).

Gomrok must support:

* A package catalog per client.
* Active and inactive packages.
* Package names, public identifiers, descriptions, and metadata.
* Package availability by country.
* Package availability by currency.
* Package availability by payment method.
* Package availability by provider.
* Package support for one-time payment, recurring payment, auto-charge, subscription, or a combination of supported purchase types.
* Country-specific restrictions on package purchase types.
* Returning the resolved package list for a requested client, country, currency, and user context.

The package list returned to a client should include only packages that are available in the resolved market context.

The package response should be able to include:

* Package identifier
* Display name
* Description
* Resolved price
* Currency
* Available providers
* Available payment methods
* Available purchase types
* Voucher eligibility when safe to expose
* Any required checkout metadata

The package catalog must not contain hardcoded Televika-only business logic.

Do not create package-related database tables or migrations until the database design is proposed and confirmed.

## Pricing Requirement

Gomrok must support flexible pricing rules.

Pricing may depend on:

* Client
* Package
* Country
* Currency
* Payment provider
* Payment method
* Purchase type
* Subscription interval
* Campaign or promotion
* Voucher
* Tax or fee rules
* Business rules per market

Every package should be able to have a default price configuration.

Country-specific price rules may override the default price.

A country override may define:

* A different amount
* A different currency
* A provider-specific amount
* A payment-method-specific amount
* A purchase-type-specific amount
* A subscription-interval-specific amount
* A disabled price or unavailable combination

The price resolution order must be deterministic and documented.

A recommended conceptual order is:

```text
1. Find the client and package.
2. Load the package default price.
3. Apply the country-specific price override when one exists.
4. Apply currency, provider, payment-method, purchase-type, or interval-specific overrides when configured.
5. Validate voucher eligibility.
6. Apply the voucher discount.
7. Apply tax or fee rules if required.
8. Produce the final resolved price.
```

More specific valid rules should take precedence over less specific rules.

Gomrok must store a price snapshot on the payment or subscription creation record so later pricing changes do not modify historical transactions.

The snapshot should conceptually preserve:

* Package identifier
* Base/default amount
* Country override amount when used
* Currency
* Provider
* Payment method
* Purchase type
* Subscription interval when applicable
* Voucher code or voucher identifier when used
* Discount amount
* Tax amount when applicable
* Fee amount when applicable
* Final payable amount
* Pricing rule references or version information

Gomrok should be able to determine the correct price before creating a payment or subscription.

The pricing logic must be generic and not hardcoded for Televika.

Do not implement the database schema for pricing yet.

Before creating pricing-related tables or migrations, propose the design and ask for confirmation.

## Voucher Requirement

Gomrok must support vouchers.

A voucher may provide:

* A fixed-amount discount
* A percentage discount
* A full discount when explicitly allowed
* A package-specific discount
* A country-specific discount
* A currency-specific discount
* A provider-specific discount
* A payment-method-specific discount
* A purchase-type-specific discount
* A client-specific discount
* A limited-time campaign discount

Voucher validation may consider:

* Client
* Client user
* Package
* Country
* Currency
* Provider
* Payment method
* Purchase type
* Subscription interval
* Valid-from date
* Expiration date
* Global usage limit
* Per-user usage limit
* Per-client usage limit
* Minimum purchase amount
* Maximum discount amount
* First-purchase-only rules
* Active or disabled state

Voucher rules must be validated inside Gomrok before creating the provider payment or subscription.

The provider must receive only the final resolved amount or provider-supported discount representation.

Voucher usage must be idempotent and concurrency-safe.

A duplicate payment request, webhook retry, or client retry must not redeem the same voucher more than once.

Voucher redemption should only become final according to an explicitly designed lifecycle, for example after successful payment, while failed, canceled, or expired payment attempts should not incorrectly consume permanent voucher usage.

Gomrok must preserve a voucher decision snapshot for historical accuracy.

Do not create voucher-related database tables or migrations until the database design is proposed and confirmed.

## Country-Based Provider and Purchase Capability Requirement

Gomrok must support default provider and payment-method configuration with country-specific overrides.

Provider routing must not assume that every provider supports every purchase type.

The resolved country configuration should be able to define:

* Enabled providers
* Disabled providers
* Default provider
* Provider priority or fallback order
* Enabled payment methods per provider
* Enabled purchase types per provider
* One-time payment availability
* Recurring payment availability
* Auto-charge availability
* Subscription availability
* Currency restrictions
* Package restrictions

A provider configuration may be used for payment only, subscription only, or both, depending on its declared capabilities and the country/client configuration.

Examples:

```text
Turkey
  Default provider: Ziraat
  Enabled providers: Ziraat
  Enabled methods: Bank-hosted card payment
  Enabled purchase types: one_time_payment
  Disabled purchase types: recurring_payment, auto_charge, subscription
```

```text
Germany
  Enabled provider/method combinations:
    - Mollie / card
    - Mollie / PayPal
  Enabled purchase types:
    - one_time_payment
    - recurring_payment only where supported by the selected provider and method
    - subscription only where supported by the selected provider and method
```

```text
Netherlands
  Default provider: PayPal
  Enabled providers: PayPal
  Enabled methods: PayPal
  Enabled purchase types: based on PayPal capability and client configuration
```

Country overrides should replace or refine the default provider configuration according to clearly defined resolution rules.

A recommended conceptual provider resolution order is:

```text
1. Load the client default provider configuration.
2. Apply the country-specific provider override when one exists.
3. Filter providers by package availability.
4. Filter providers by currency.
5. Filter providers by payment method.
6. Filter providers by requested purchase type.
7. Filter providers by declared provider capabilities.
8. Select the configured default provider or the next allowed provider in priority order.
```

Gomrok must reject unsupported combinations instead of silently changing the requested purchase type.

For example:

* A subscription request in Turkey must not silently become a one-time Ziraat payment.
* Ziraat must not be selected for auto-charge when auto-charge is disabled or unsupported.
* A provider must not be selected for subscription unless both the provider capability and the client/country configuration allow subscription.

Do not create country-provider configuration tables or migrations until the database design is proposed and confirmed.

## Naming Rules

Do not describe Gomrok as a Televika-only payment middleware.

Use generic names:

* client
* merchant
* application
* payment provider
* payment method
* purchase type
* package
* voucher
* client notification
* merchant callback
* application callback
* client user
* subscription owner

Avoid Televika-specific names in core architecture, APIs, database tables, and services.

Bad:

```text
TelevikaNotification
televika_callback_url
sendToTelevika()
```

Good:

```text
ClientNotification
callback_url
notifyClient()
```

### File naming

Every file authored for this project is named in **PascalCase** (`Phases.md`, `LastAiAnswer.md`,
`ProviderAdapter.php`) — no snake_case, kebab-case, or spaces; acronyms treated as words
(`LastAiAnswer`, not `LastAIAnswer`). `CLAUDE.md` and ecosystem-owned names (`composer.json`,
`.env`, `mkdocs.yml`, …) are excepted. Full rule with exceptions: `.claude/Rule.md` §3.1.

### Documentation directory

All project documentation lives under **`.claude/`** — `.claude/Rule.md`, `.claude/Changelog.md`,
`.claude/PhaseDecisions.md`, `.claude/FileIndex.md`, `.claude/docs/*` (Architecture, Phases,
Commands, LastAiAnswer, …), `.claude/knowledge/*`, `.claude/PhaseResults/*`, plus the `agents/`
`commands/` `skills/` skeletons — never loose in the project root. `CLAUDE.md` is the one
exception (the harness auto-loads `./CLAUDE.md`); `Design/` also stays at the root (§3.3). Files
in `.claude/` are PascalCase (incl. the `PhaseResults/` sub-dir); the tool-recognised
`agents/ commands/ skills/` sub-dirs plus `docs/ knowledge/` are lowercase. Full rule:
`.claude/Rule.md` §3.3. Every documentation file is also registered in
`.claude/Rule.md` → **## Project Documents** (name, path, purpose), kept in sync whenever a doc
is created / renamed / moved / removed (§3.5).

## Project Rules File

`.claude/Rule.md` is the consolidated checklist of every standing rule — distilled from this
file plus decisions made in conversation (working agreement, project framing, file & domain
naming, phase workflow, database rules, evidence, docs, security). This file stays the detailed
spec; `.claude/Rule.md` is the quick catalogue with pointers back here. Read them together, keep them
consistent, and add any newly agreed rule to `.claude/Rule.md`.

## Required Database Concepts

Design the database around these concepts, but do not create the final schema without confirmation:

* clients
* client API keys
* client provider configurations
* client callback endpoints
* client payment methods
* payment providers
* payment provider capabilities
* client provider capabilities
* country provider configurations
* country provider priorities
* country payment methods
* country purchase capabilities
* packages (client-scoped; each package belongs to one client — no global catalogue, no `client_packages` junction)
* package availability rules
* package purchase capabilities
* package prices
* default package prices
* country-based price overrides
* gateway-based prices
* payment-method-based prices
* purchase-type-based prices
* currency-based prices
* pricing rules
* vouchers
* voucher eligibility rules
* voucher usage limits
* voucher redemptions
* voucher decision snapshots
* pricing decision snapshots
* provider routing decision snapshots
* payments
* payment attempts
* provider transactions
* provider customers
* subscriptions
* subscription events
* subscription payment links
* gateway references
* webhook events
* refunds
* client notification logs
* idempotency keys
* audit logs
* error logs
* admin users
* admin roles
* admin permissions
* admin sessions
* admin login attempts

Each payment must belong to one client.

Each provider configuration must belong to one client.

Each notification must belong to one client and one payment.

Each webhook event must be stored before processing to support idempotency, debugging, and replay protection.

Each subscription must belong to one client, one client user reference, one provider, and one internal Gomrok subscription record.

Each resolved payment or subscription must preserve the selected package, final price, country, currency, provider, payment method, purchase type, and voucher decision when applicable.

Admin users, roles, and permissions must be designed before implementing the admin panel.

Package, voucher, pricing, country-provider, and purchase-capability rules must be designed before implementing their database tables.

## Database Design Confirmation Rule

The schema is built **incrementally, never in one upfront pass.** There is no whole-system
schema-design phase. Only the stable base / reference tables (`countries`, `currencies`,
`provider_types`, capability catalogue, and similar lookup data) are designed early; every other
table is designed and created inside the phase that first needs it, and later phases may extend
an earlier module's tables with additive migrations as the "who connects to what" picture firms
up. The "Required Database Concepts" list above is the catalogue of what will eventually exist,
not a blueprint to build on day one. See `.claude/docs/Phases.md` → *Database strategy*.

Before creating, changing, or finalizing any part of the database schema, Claude must ask for confirmation.

Claude must not directly create migrations, tables, or final schema files without first presenting the proposed database design (for that phase's slice) and getting approval.

When proposing database design, Claude must explain:

* Tables to be created
* Important fields
* Relationships
* Indexes
* Unique constraints
* Foreign keys
* Nullable fields
* JSON fields
* Security-sensitive fields
* Migration risks
* Any alternatives if there are multiple good options

Before implementation, Claude must ask:

```text
Please confirm the database design before I create migrations or schema files.
```

If the user asks to change the database design, update the proposal first and ask for confirmation again.

Database design must include admin panel role-based permissions if the admin panel is part of the current phase.

Database design must include package, voucher, pricing, country-provider, and purchase-capability rules if those features are part of the current phase.

## Database Diagram Maintenance Rule

Gomrok keeps a rendered database diagram at `.claude/docs/database-diagram.md`. It is built for **MkDocs** (Material theme, Mermaid via `pymdownx.superfences`) and served through the repo-root `mkdocs.yml`.

`database-diagram.md` is a **living document**. It must always match the schema.

* Whenever the database structure changes — any table, column, index, unique constraint, or foreign key that is **added, removed, or renamed** — update `.claude/docs/database-diagram.md` in the **same change**, alongside `.claude/docs/database-design.md` (canonical spec) and `.claude/docs/db_explain.md` (per-table guide).
* Keep the **table count** and every table's shape identical across all three files. If they disagree, they are out of sync and must be reconciled.
* The file must contain three things, kept current: **DB structures** (entities with key columns), a **Mermaid diagram** (ER diagrams grouped by module, plus the module map), and **examples** (representative rows / resolution walkthroughs).
* New tables get a new entity in the correct module ER diagram, a row in the table catalogue, and — where useful — an example. Removed tables are deleted from all three places.
* Never let a migration or a schema-design edit land without the matching diagram update. Treat the diagram as part of the definition of done for any database change.
* `database-design.md` remains the source of truth; if the diagram and the spec ever conflict, fix the diagram to match the spec.

There is also a standalone web page `.claude/docs/database-diagram.html` (Mermaid via CDN, no build step — open by double-click). When served over HTTP it reads the Mermaid blocks **live** from `database-diagram.md`, so the diagrams there never drift. Its **embedded fallback snapshot** (the `<script class="mmd">` blocks used for `file://`) and its **catalogue JSON** (`#catData`) are static copies — refresh them in the same change whenever `database-diagram.md`'s diagrams or §7 catalogue change.

## Payment Lifecycle

Gomrok must use its own normalized internal payment statuses.

Suggested statuses:

* created
* pending
* requires_action
* authorized
* paid
* failed
* canceled
* expired
* refunded
* partially_refunded
* disputed
* chargeback

Provider-specific statuses must be mapped into Gomrok internal statuses.

Stripe, PayPal, Mollie, and Ziraat statuses must not leak directly into core business logic.

Unknown provider statuses must be stored safely and handled carefully.

## Provider Adapter Pattern

Each payment provider must have its own adapter or driver.

All providers must follow shared interfaces according to their supported capabilities.

Suggested methods:

* createPayment()
* createCheckoutSession()
* createSubscription()
* createBillingPortalSession()
* authorizePayment()
* capturePayment()
* cancelPayment()
* refundPayment()
* getPaymentStatus()
* getSubscriptionStatus()
* verifyWebhookSignature()
* parseWebhook()
* mapProviderStatusToInternalStatus()
* mapProviderSubscriptionStatusToInternalStatus()
* getCapabilities()

Core payment logic must not contain provider-specific code.

Adding a new provider should require creating a new provider adapter, not rewriting the core payment flow.

A provider adapter must not be forced to implement a capability that the provider does not support.

Unsupported operations must be rejected explicitly through capability validation.

## Payment Gateway Capability Structure

Add a payment gateway capability structure to Gomrok.

Each payment provider may support different features.

Gomrok must not assume that all gateways support the same capabilities.

Each provider should declare its capabilities.

Example capabilities:

* one_time_payment
* hosted_checkout
* redirect_payment
* embedded_payment_form
* card_tokenization
* authorization
* capture
* cancel
* refund
* partial_refund
* subscription
* subscription_cancel
* subscription_pause
* subscription_resume
* customer_portal
* billing_portal
* invoice
* recurring_payment
* auto_charge
* webhook
* return_url
* three_d_secure
* manual_status_polling

The database and provider adapter layer should support gateway capabilities.

Client-specific provider configuration should also be able to enable or disable capabilities per client and per country.

Payment methods may also have different capabilities under the same provider.

For example, a provider may support recurring card payments but not recurring PayPal payments, or vice versa.

Example:

Stripe may support:

* hosted_checkout
* one_time_payment
* subscription
* recurring_payment
* auto_charge
* customer_portal
* refund
* partial_refund
* webhook

Mollie may support different capabilities depending on the configured payment method.

For Germany, Gomrok may expose:

* Mollie Card
* Mollie PayPal

The allowed purchase types must be resolved separately for each provider and payment-method combination.

Ziraat may support:

* one_time_payment
* hosted_checkout
* redirect_payment
* three_d_secure
* webhook or callback
* manual_status_polling

Ziraat must not be treated as a subscription or auto-charge provider unless a future confirmed integration explicitly supports those capabilities.

Gomrok should use this capability structure when deciding what actions are possible for a provider, payment method, client, package, and country.

Do not create capability-related database tables or migrations until the database design is proposed and confirmed.

## Provider-Hosted Payment UI Rule

For each payment gateway, Gomrok should use the provider-hosted payment UI when available.

Examples:

* Stripe Checkout
* Stripe Billing Portal
* PayPal Checkout
* Mollie Checkout
* Bank-hosted payment page
* Bank-hosted 3D Secure page for Ziraat

Gomrok should not collect or process raw card details directly.

Gomrok’s responsibility is to:

* Resolve the package, price, country configuration, provider, payment method, purchase type, and voucher before creating the provider transaction.
* Create the payment, checkout session, subscription, or billing action through the gateway API.
* Redirect the customer to the gateway-hosted page when needed.
* Receive webhook, callback, or return-url events from the gateway.
* Verify the payment status with the gateway API.
* Normalize the result into Gomrok’s internal payment status.
* Notify the connected client system, for example Televika.
* Store gateway references, internal references, user ownership, subscription ownership, price snapshots, voucher snapshots, provider-routing snapshots, status logs, webhook logs, and error logs.

The checkout UI and sensitive payment entry should stay on the payment provider side whenever possible.

## Subscription Ownership Model

Gomrok must store enough data to identify subscription ownership across clients, users, payments, packages, and gateways.

The system must be able to answer:

* Which client owns this subscription?
* Which client user owns this subscription?
* Which package created this subscription?
* Which country and currency rules were used?
* Which gateway created this subscription?
* Which payment method was used?
* What is the gateway subscription ID?
* What is the internal Gomrok subscription ID?
* Which payments belong to this subscription?
* Which webhook events affected this subscription?
* Which client should be notified about subscription changes?
* Given a gateway subscription ID, which Gomrok subscription and client user does it belong to?
* Given a client user ID, which active subscriptions does the user have?

Important rule:

A subscription must always belong to:

* one client
* one client user reference
* one package when package-based
* one provider
* one Gomrok internal subscription record

A subscription may only be created when:

* The package supports subscription.
* The selected country allows subscription.
* The selected provider supports subscription.
* The selected payment method supports subscription.
* The client configuration enables subscription for that combination.

Do not create subscription-related database tables or migrations until the database design is proposed and confirmed.

## Gateway Reference Lookup Rule

Gomrok must store gateway references in a way that makes reverse lookup possible.

The system must be able to find the correct client, user, payment, or subscription from any provider webhook.

Examples:

* Stripe checkout session ID
* Stripe payment intent ID
* Stripe customer ID
* Stripe subscription ID
* PayPal order ID
* PayPal subscription ID
* Mollie payment ID
* Mollie customer ID
* Mollie subscription ID
* Ziraat transaction ID
* Ziraat order ID
* Ziraat reference ID

This allows Gomrok to map any incoming webhook or callback back to the correct internal record.

Do not create gateway reference database tables or migrations until the database design is proposed and confirmed.

## API Design

The API must be generic and client-based, not Televika-based.

Suggested endpoints:

```text
GET /api/v1/packages
GET /api/v1/packages/{packageId}
POST /api/v1/pricing/resolve
POST /api/v1/vouchers/validate
POST /api/v1/payments
GET /api/v1/payments/{paymentId}
GET /api/v1/payments/{paymentId}/status
POST /api/v1/payments/{paymentId}/cancel
POST /api/v1/payments/{paymentId}/refund
POST /api/v1/payments/{paymentId}/capture
POST /api/v1/subscriptions
GET /api/v1/subscriptions/{subscriptionId}
POST /api/v1/subscriptions/{subscriptionId}/cancel
POST /api/v1/webhooks/{provider}
POST /api/v1/client-notifications/retry
```

The package-list endpoint should support a resolved context such as:

* client
* country
* currency
* payment method
* purchase type
* client user when needed

The payment or subscription request should reference a Gomrok package identifier instead of trusting a client-provided price.

Gomrok must calculate and validate the final price itself.

Every write endpoint must support idempotency where needed.

Every client request must be authenticated.

Every request must be scoped to the correct client.

A client must never access another client’s packages, vouchers, payments, subscriptions, webhooks, configurations, pricing rules, country overrides, or logs.

## Security Rules

Gomrok must secure:

* Client to Gomrok requests
* Gomrok to client callbacks
* Provider webhooks to Gomrok
* Admin panel authentication
* Admin panel authorization
* Admin role-based permissions
* API keys
* Provider secrets
* Webhook signatures
* Replay attacks
* Idempotency keys
* Voucher redemption concurrency
* Price manipulation attempts
* Package manipulation attempts
* Audit logs
* Multi-client access control

Provider credentials and API secrets must never be hardcoded.

Use environment variables or secure secret storage.

Sensitive values must not be logged.

Client-supplied prices must not be trusted as the source of truth.

Gomrok must resolve package availability, price, provider, payment method, purchase type, and voucher validity internally.

## Idempotency Rules

Gomrok must prevent duplicate payment processing.

Use idempotency keys for client payment requests.

Store webhook event IDs or unique provider event references.

Duplicate webhooks must not create duplicate payments, refunds, subscriptions, voucher redemptions, or notifications.

Voucher validation and redemption must be safe under concurrent requests.

Webhook processing must be safe to retry.

Client notifications must be safe to retry.

## Error Handling and Retry Rules

Gomrok must handle:

* Provider timeouts
* Provider errors
* Network errors
* Duplicate webhooks
* Failed client notifications
* Unknown payment statuses
* Invalid client configuration
* Invalid provider credentials
* Invalid package
* Package unavailable in the requested country
* Missing country price configuration
* Invalid provider override
* Unsupported payment method
* Unsupported purchase type
* Subscription requested through a payment-only provider
* Auto-charge requested where not supported
* Invalid, expired, disabled, or exhausted voucher
* Concurrent voucher redemption
* Concurrent payment updates
* Database failures

Use retry policies with exponential backoff where appropriate.

Use a dead-letter queue or failed-jobs table for operations that cannot be completed.

Failed client notifications must be stored and retryable.

Manual retry tools should be available through the admin panel.

## Background Jobs

Use background jobs for:

* Webhook processing
* Client notifications
* Retrying failed notifications
* Payment reconciliation
* Expired payment cleanup
* Refund reconciliation
* Subscription reconciliation
* Provider status polling if needed
* Voucher reservation expiration if the approved voucher lifecycle uses reservations
* Package and pricing cache refresh if caching is introduced later

Do not block provider webhook responses with long processing.

Store the webhook first, then process it safely.

## Logging and Observability

Use structured logging.

Every payment and subscription flow should have:

* correlation_id
* client_id
* client_user_id when available
* package_id when available
* payment_id when available
* subscription_id when available
* voucher_id when available
* country
* currency
* purchase_type
* payment_method when available
* provider
* provider_transaction_id where available
* provider_subscription_id where available

Log important events, but never log secrets.

Gomrok should support:

* Package resolution logs
* Pricing decision logs
* Voucher validation and redemption logs
* Provider routing decision logs
* Payment trace logs
* Subscription trace logs
* Provider request logs
* Webhook logs
* Notification logs
* Error logs
* Metrics
* Alerts
* Dashboards
* Admin tools
* Reconciliation reports

## Testing Requirements

Every important feature must include tests.

Use unit tests where appropriate.

The project should include unit tests for:

* Package catalog resolution
* Package availability by country
* Payment lifecycle
* Subscription lifecycle
* Provider adapters
* Provider capability detection
* Provider and payment-method resolution
* Country-specific provider overrides
* Country-specific purchase capabilities
* Payment-only provider rejection for subscription requests
* Auto-charge availability
* Client authentication
* Client isolation
* Idempotency
* Webhook processing
* Webhook signature verification
* Duplicate webhook handling
* Payment status mapping
* Subscription ownership
* Gateway reference lookup
* Client notification retries
* Error handling
* Admin authentication
* Admin role-based authorization
* Admin permission checks
* Pricing rule selection
* Default price resolution
* Country-based price overrides
* Gateway-based pricing
* Payment-method-based pricing
* Purchase-type-based pricing
* Currency-based pricing
* Voucher validation
* Voucher eligibility
* Voucher discount calculation
* Voucher usage limits
* Duplicate voucher redemption prevention
* Pricing snapshots
* Voucher snapshots
* Provider-routing snapshots

Tests should be added during implementation, not postponed until the end.

Each phase should include relevant tests when code is added.

The phase summary must explain which tests were added and how to run them.

## Changelog Rule

Create and maintain a file called `.claude/Changelog.md` (PascalCase per `.claude/Rule.md` §3.1 — not the
all-caps `CHANGELOG.md` convention).

Every meaningful change must be recorded in `.claude/Changelog.md`.

Each changelog entry should include:

* Date
* Short summary
* Files changed
* Reason for change
* Migration notes if needed
* Breaking changes if any

Before making code changes, check `.claude/Changelog.md`.

After making code changes, update `.claude/Changelog.md`.

## Language Rule

When I write instructions in Persian, respond in English.

Do not answer my Persian instructions in Persian unless I explicitly ask you to respond in Persian.

## LastAiAnswer.md Response Log Rule

Every substantive assistant response in this project must be saved to `.claude/docs/LastAiAnswer.md` (`/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/LastAiAnswer.md`). The file is a **single-slot buffer**:

* **Command: always delete the last response from `.claude/docs/LastAiAnswer.md` and add the last answer in it.** Every time, first clear whatever is currently in `.claude/docs/LastAiAnswer.md`, then write the newest substantive answer. `.claude/docs/LastAiAnswer.md` must only ever hold the single most recent answer — nothing from before it.
* **Always overwrite** the file with the latest substantive response — never append.
* Use the `Write` tool on `.claude/docs/LastAiAnswer.md` (not `Edit`) — overwriting is the intended behavior.
* The file starts with `# Q: <one-line topic paraphrased from my question>` followed by the full response body verbatim.

What counts as substantive:

* Design analyses.
* Technical recommendations.
* Code plans.
* Architecture explanations.
* Phase summaries.
* ORD justifications.
* Anything with reasoning worth keeping.

What does NOT count (do not update `.claude/docs/LastAiAnswer.md` for these):

* Short administrative confirmations ("saved", "will do", "done").
* Pure tool-result echoes.
* One-line clarifying questions.
* Meta responses about the `.claude/docs/LastAiAnswer.md` ritual itself.

Do not ask permission each time. The rule is standing.

## Interactive Phase Rule

Before starting each implementation phase, explain what will be done in that phase.

Before making decision-dependent changes in a phase, ask the phase's decision questions
**one at a time** — never present them all at once. Ask Q1, wait for the answer, record it in
`.claude/PhaseDecisions.md`, then ask Q2, and so on. Do not start implementing a decision-dependent part
of the phase until its questions are answered.

Each question must show:

* Phase number
* Question number (e.g. `Q2 of 5`)
* The question
* All available options
* A short explanation of each option
* Your recommended option, when there is one
* Why you recommend it

**Do not auto-select the recommended option — the user makes the final choice, and you must
never guess it.**

Every question and its answer is persisted to `.claude/PhaseDecisions.md` immediately (not at end of
phase), with a `Status: Pending → Decided` marker and full change history if the user later
revises a choice. Full rules and the required format: `.claude/Rule.md` §4.2.

Do not continue with implementation until the phase decisions are clear. If the user already
answered a question earlier, do not ask it again — use the previous answer.

## Visual and Output Verification Rule

From this point forward, Claude must show real, captured evidence of results — not just written descriptions of what should happen.

This applies to two categories:

* **Views** — any admin panel page, frontend screen, or other rendered UI. Claude must capture and show an actual screenshot of the rendered view (e.g. via a headless browser or equivalent tool), not only a description of the markup or expected appearance.
* **Outputs** — any API response, CLI/command output, test run, migration run, or generated file. Claude must show the real captured output (actual HTTP response body/status, actual terminal output, actual test results), not a hypothetical or paraphrased version.

Rules:

* Every phase-completion summary must include the actual evidence (screenshot and/or captured output) for anything that phase produced, alongside the existing required summary items.
* If the current tooling cannot yet capture a screenshot or a given output (for example, no headless-browser tooling is installed yet), Claude must say so explicitly rather than describing the view/output as if it had been verified, and must propose how to add that capability (a new phase, or an addition to an existing phase such as Phase 27 — Admin Module Views and Panels).
* Adding a brand-new phase to the 30-phase plan, or materially changing an existing phase's scope, still requires explicit confirmation from the user first — this rule does not by itself authorize inserting phases silently.

The 30-phase plan lives in `.claude/docs/Phases.md` (derived from this file; if the two disagree, this file wins and `.claude/docs/Phases.md` is corrected). Keep its status table current, and follow its per-phase checklist (5 interactive questions → database-design confirmation → tests-as-you-go → phase-completion summary with captured evidence).

## Phase Completion Rule

After each implementation phase, explain clearly what was done.

The summary must include:

* What was implemented
* Which files were created
* Which files were updated
* Which files were removed if any
* Which database changes were added if any
* Which tests were added
* How to run the tests
* Actual captured evidence of the result (screenshots for any view/UI, real output for any API/CLI/test/migration result) per the Visual and Output Verification Rule
* Any known limitations
* Next recommended phase

Also update `.claude/Changelog.md` after each meaningful phase, and fill in that phase's row in the
`.claude/docs/Phases.md` *Status & execution tracking* table — Status, Start/End Datetime, Estimated Duration,
Actual Duration, and Tokens Used.

**After a phase is complete**, create exactly one result file `.claude/PhaseResults/PhaseNNResult.md`
(zero-padded, PascalCase) using the layout in `.claude/PhaseResults/Template.md`. A phase is **not fully
completed until its result file is completed.** It records what *actually* happened — specific
file paths, class / method / interface / endpoint / table names — and never documents planned
work as completed work. `.claude/docs/Phases.md` stays the roadmap; `.claude/PhaseResults/` is the detailed history.

- Never invent timestamps, token usage, test results, or implementation details. Unavailable
  token counts are `N/A`. Never claim tests passed unless actually run successfully.
- `Execution Summary` mirrors the `.claude/docs/Phases.md` tracking row; Actual Duration is computed from
  Start and End Datetime.
- `Database Changes` / `API Changes` say "No database changes." / "No API changes." explicitly
  when there are none.
- Do **not** silently rewrite an earlier phase's result file when a later phase changes that
  code — document the later change in the later phase's result file. Never overwrite or delete a
  prior result file.
- This applies automatically to all phases. See `.claude/PhaseResults/Readme.md`.

**End-of-phase decision check.** Before marking a phase complete, verify: (1) all required
decision questions were asked; (2) every one has a recorded answer; (3) `.claude/PhaseDecisions.md`
reflects the user's actual selections; (4) the implementation follows those selections. If the
implementation diverges from a recorded decision, stop and ask before changing the decision or
proceeding. *(.claude/Rule.md → §4.2)*

## Expected Claude Behavior

When working on this project:

1. Treat this as a greenfield build — there is no predecessor system; do not assume or invent one.
2. Design a clean Gomrok architecture from first principles.
3. Keep the system multi-client from the beginning.
4. Keep the code simple and practical.
5. Avoid overengineering.
6. Prefer clear architecture over clever architecture.
7. Use modular and hexagonal architecture.
8. Keep domain logic independent from Slim, MySQL, SDKs, and infrastructure code.
9. Explain important decisions.
10. Before each phase, explain the plan and ask 5 interactive questions.
11. Before creating or changing database schema, present the design and ask for confirmation.
12. Do not include final database schema inside `CLAUDE.md`; database design must be handled separately.
13. After each phase, explain what was done and which files changed.
14. Add relevant unit tests during implementation.
15. Update `.claude/Changelog.md` after each meaningful change.
16. Keep the package catalog inside Gomrok.
17. Resolve package price inside Gomrok and never trust a client-provided final price.
18. Support default prices with country-specific overrides.
19. Support default providers with country-specific provider and payment-method overrides.
20. Keep one-time payment, recurring payment, auto-charge, and subscription as separate purchase capabilities.
21. Never assume a payment provider also supports subscriptions.
22. Validate provider and payment-method capabilities before creating a payment or subscription.
23. Support vouchers without allowing duplicate or unsafe redemption.

## First Tasks

Start by proposing (no predecessor system to analyze — design from first principles):

1. Proposed Gomrok architecture
2. Proposed modular and hexagonal backend structure
3. Proposed folder structure
4. Proposed database concepts without creating final schema
5. Ask for confirmation before creating database migrations or schema files
6. Proposed provider adapter interface
7. Proposed admin panel structure
8. Proposed admin role-based permission model using only admin and support_agent for now
9. Proposed package catalog model
10. Proposed subscription ownership model
11. Proposed payment gateway capability model
12. Proposed pricing model with default prices and country-specific overrides
13. Proposed country-based provider, payment-method, and purchase-capability model
14. Proposed voucher model
15. First implementation phase
16. Five interactive questions before starting the first implementation phase
