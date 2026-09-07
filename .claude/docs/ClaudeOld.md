# CLAUDE.md

> **SUPERSEDED ARCHIVE — do not follow this file.** This is an early draft of the project spec,
> kept only for history. It still contains outdated framing (e.g. a "Dexter" predecessor that
> does not exist) and pre-`Documents/` file paths. The live instructions are the project-root
> `CLAUDE.md` plus `Documents/Rule.md`.

## Project Overview

This project is a complete rewrite of the legacy payment middleware service called **Dexter**.

The new service is called **Gomrok**.

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
* Support different pricing rules per client, country, currency, gateway, payment method, or package.
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
* `Payments`: payment creation, status lifecycle, refunds, captures, cancellations
* `Providers`: provider registry, provider capabilities, provider adapters
* `Webhooks`: incoming provider webhooks, signature verification, webhook event storage
* `Notifications`: callbacks from Gomrok to client systems
* `Subscriptions`: subscription ownership, provider subscriptions, subscription events
* `Admin`: admin panel, dashboards, manual retry tools, reconciliation views
* `Pricing`: client pricing, country-based pricing, gateway-based pricing, package pricing, and currency rules if needed

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

* `admin`: can manage operational settings, clients, payments, notifications, provider configurations, admin panel access, and retry operations where allowed.
* `support_agent`: can view clients, users, payments, subscriptions, webhook status, and notification status, but cannot modify provider credentials, secrets, roles, permissions, or system-level configuration.

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
* Sensitive actions must be logged, including provider config changes, secret rotation, refund actions, retries, and permission changes.
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
* Its own package prices
* Its own country-based prices
* Its own gateway-based prices
* Its own currency-based prices
* Different prices for the same package depending on country, currency, gateway, or payment method

Example:

A package may be sold cheaper in Turkey than in Europe or the United States.

Another package may have different prices for Stripe, PayPal, Mollie, or Ziraat because of gateway fees, country rules, taxes, or business decisions.

Payment records must always belong to a specific client.

Webhook events, provider transactions, refunds, notifications, subscriptions, gateway references, pricing decisions, and audit logs must also be linked to the correct client.

Do not hardcode Televika-specific logic inside Gomrok.

Any Televika-specific behavior should be handled through:

* Client configuration
* Client settings
* Integration adapter
* Database configuration

## Pricing Requirement

Gomrok must support flexible pricing rules.

Pricing may depend on:

* Client
* Package
* Country
* Currency
* Payment provider
* Payment method
* Subscription interval
* Campaign or promotion
* Tax or fee rules
* Business rules per market

Gomrok should be able to determine the correct price before creating a payment or subscription.

The pricing logic must be generic and not hardcoded for Televika.

Do not implement the database schema for pricing yet.

Before creating pricing-related tables or migrations, propose the design and ask for confirmation.

## Naming Rules

Do not describe Gomrok as a Televika-only payment middleware.

Use generic names:

* client
* merchant
* application
* payment provider
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
* pricing rules
* package prices
* country-based prices
* gateway-based prices
* currency-based prices

Each payment must belong to one client.

Each provider configuration must belong to one client.

Each notification must belong to one client and one payment.

Each webhook event must be stored before processing to support idempotency, debugging, and replay protection.

Each subscription must belong to one client, one client user reference, one provider, and one internal Gomrok subscription record.

Admin users, roles, and permissions must be designed before implementing the admin panel.

Pricing rules must be designed before implementing package pricing, country-based pricing, gateway-based pricing, or currency-based pricing.

## Database Design Confirmation Rule

Before creating, changing, or finalizing the database schema, Claude must ask for confirmation.

Claude must not directly create migrations, tables, or final schema files without first presenting the proposed database design and getting approval.

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

Database design must include pricing rules if pricing is part of the current phase.

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

All providers must follow a shared interface.

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
* webhook
* return_url
* three_d_secure
* manual_status_polling

The database and provider adapter layer should support gateway capabilities.

Client-specific provider configuration should also be able to enable or disable capabilities per client.

Example:

Stripe may support:

* hosted_checkout
* subscription
* customer_portal
* refund
* partial_refund
* webhook

Ziraat may support:

* hosted_checkout
* redirect_payment
* three_d_secure
* webhook or callback
* manual_status_polling

Gomrok should use this capability structure when deciding what actions are possible for a provider and client.

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

* Create the payment, checkout session, subscription, or billing action through the gateway API.
* Redirect the customer to the gateway-hosted page when needed.
* Receive webhook, callback, or return-url events from the gateway.
* Verify the payment status with the gateway API.
* Normalize the result into Gomrok’s internal payment status.
* Notify the connected client system, for example Televika.
* Store gateway references, internal references, user ownership, subscription ownership, status logs, webhook logs, and error logs.

The checkout UI and sensitive payment entry should stay on the payment provider side whenever possible.

## Subscription Ownership Model

Gomrok must store enough data to identify subscription ownership across clients, users, payments, and gateways.

The system must be able to answer:

* Which client owns this subscription?
* Which client user owns this subscription?
* Which gateway created this subscription?
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
* one provider
* one Gomrok internal subscription record

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

Every write endpoint must support idempotency where needed.

Every client request must be authenticated.

Every request must be scoped to the correct client.

A client must never access another client’s payments, subscriptions, webhooks, configurations, pricing rules, or logs.

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
* Audit logs
* Multi-client access control

Provider credentials and API secrets must never be hardcoded.

Use environment variables or secure secret storage.

Sensitive values must not be logged.

## Idempotency Rules

Gomrok must prevent duplicate payment processing.

Use idempotency keys for client payment requests.

Store webhook event IDs or unique provider event references.

Duplicate webhooks must not create duplicate payments, refunds, subscriptions, or notifications.

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

Do not block provider webhook responses with long processing.

Store the webhook first, then process it safely.

## Logging and Observability

Use structured logging.

Every payment and subscription flow should have:

* correlation_id
* client_id
* client_user_id when available
* payment_id when available
* subscription_id when available
* provider
* provider_transaction_id where available
* provider_subscription_id where available

Log important events, but never log secrets.

Gomrok should support:

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

* Payment lifecycle
* Subscription lifecycle
* Provider adapters
* Provider capability detection
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
* Country-based pricing
* Gateway-based pricing
* Currency-based pricing

Tests should be added during implementation, not postponed until the end.

Each phase should include relevant tests when code is added.

The phase summary must explain which tests were added and how to run them.

## Migration from Dexter

When analyzing Dexter, identify:

* What can be reused
* What must be rewritten
* What must be removed
* What is risky
* What is tightly coupled to Televika
* What is tightly coupled to a specific payment provider
* What should move into provider adapters
* What should move into configuration
* What should become generic client-based logic

Migration should allow Televika to move first while keeping the option to run Dexter and Gomrok in parallel during transition.

The migration plan must include rollback safety.

## Changelog Rule

Create and maintain a file called:

```text
CHANGELOG.md
```

Every meaningful change must be recorded in `CHANGELOG.md`.

Each changelog entry should include:

* Date
* Short summary
* Files changed
* Reason for change
* Migration notes if needed
* Breaking changes if any

Before making code changes, check `CHANGELOG.md`.

After making code changes, update `CHANGELOG.md`.

## Language Rule

When I write instructions in Persian, respond in English.

Do not answer my Persian instructions in Persian unless I explicitly ask you to respond in Persian.

## Interactive Phase Rule

Before starting each implementation phase, explain what will be done in that phase.

Before making code changes in a phase, ask me 5 interactive questions.

Each question must include:

* Available options
* A short explanation of each option
* Your recommended option
* Why you recommend that option

The questions should help decide important architecture or implementation details.

Example format:

```text
Before starting Phase 1, I need your input on these 5 decisions:

1. Which folder structure do you prefer?

Option A: Module-based structure
- Better for domain separation.

Option B: Layer-based structure
- Better for small/simple projects.

Recommendation:
I recommend Option A because Gomrok has multiple domains such as clients, payments, providers, webhooks, notifications, subscriptions, pricing, and admin.
```

Do not continue with implementation until the phase decisions are clear.

If I already answered a question earlier, do not ask it again. Use the previous answer.

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
* Any known limitations
* Next recommended phase

Also update `CHANGELOG.md` after each meaningful phase.

## Expected Claude Behavior

When working on this project:

1. First understand the existing Dexter codebase.
2. Do not blindly copy Dexter structure.
3. Identify legacy problems.
4. Suggest clean Gomrok architecture.
5. Keep the system multi-client from the beginning.
6. Keep the code simple and practical.
7. Avoid overengineering.
8. Prefer clear architecture over clever architecture.
9. Use modular and hexagonal architecture.
10. Keep domain logic independent from Slim, MySQL, SDKs, and infrastructure code.
11. Explain important decisions.
12. Before each phase, explain the plan and ask 5 interactive questions.
13. Before creating or changing database schema, present the design and ask for confirmation.
14. Do not include final database schema inside `CLAUDE.md`; database design must be handled separately.
15. After each phase, explain what was done and which files changed.
16. Add relevant unit tests during implementation.
17. Update `CHANGELOG.md` after each meaningful change.

## First Tasks

Start by analyzing the current Dexter codebase and provide:

1. Current Dexter structure summary
2. Main problems in Dexter
3. Proposed Gomrok architecture
4. Proposed modular and hexagonal backend structure
5. Proposed folder structure
6. Proposed database concepts without creating final schema
7. Ask for confirmation before creating database migrations or schema files
8. Proposed provider adapter interface
9. Proposed admin panel structure
10. Proposed admin role-based permission model using only admin and support_agent for now
11. Proposed subscription ownership model
12. Proposed payment gateway capability model
13. Proposed pricing model concept for country, currency, gateway, and package-based pricing
14. Proposed migration steps
15. First implementation phase
16. Five interactive questions before starting the first implementation phase
