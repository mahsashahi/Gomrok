# FileIndex.md — Gomrok

A map of the files worth knowing about. Keep in sync when files are added / moved / removed
(`.claude/Rule.md` §7).

## Governance & docs (`.claude/`)

| Path | What |
|---|---|
| `CLAUDE.md` (project root) | Entry-point instructions / detailed project spec. |
| `.claude/Rule.md` | Consolidated standing-rules catalogue. |
| `.claude/Voucher.md` | **Source of truth for all voucher behaviour** (Phase 16+): schema, eligibility, discount (default + per-currency overrides), usage limits, lifecycle, decisions. Every voucher change is logged here (`CLAUDE.md` → *Voucher Rules File*). |
| `.claude/Changelog.md` | Chronological change log (newest first). |
| `.claude/PhaseResults/PhaseDecisions.md` | Every phase decision question, options, recommendation, and selection — append-only, chronological (moved here from `.claude/` root 2026-09-08). |
| `.claude/FileIndex.md` | This file. |
| `.claude/docs/Architecture.md` | Phase-1 architecture baseline. |
| `.claude/docs/Phases.md` | 30-phase plan + Status & execution tracking table (at the top). |
| `.claude/docs/Commands.md` | Everyday commands. |
| `.claude/docs/last_ai_answer.md` | Single-slot buffer: most recent substantive answer. |
| `.claude/docs/ClaudeOld.md` | Superseded spec archive — do not follow. |
| `.claude/Orders.md` | Requirements & decisions register (index over `PhaseResults/PhaseDecisions.md`). |
| `.claude/struct.md` | The user's target `.claude/` structure spec (do not edit). |
| `.claude/PhaseResults/PhaseNNResult.md` `+ {Readme,Template}.md` | Per-phase records + convention/template. |
| `.claude/knowledge/Knowledge.md` | Durable domain knowledge / gotchas. |
| `.claude/knowledge/{SecurityRules,TenantIsolation,RolePermissionModel}.md` | Policy statements pinning the matching `CLAUDE.md` sections. |
| `.claude/knowledge/{DeploymentRunbook,DnsRecords,LocalAssets,MediaStorage}.md` | Placeholders — filled when real infra exists. |
| `.claude/docs/{ProjectDescription,Domain,Permissions,Ui}.md` | Thin reference docs pointing at `CLAUDE.md` / `Architecture.md` / `.claude/docs/Design/`. |
| `.claude/docs/{Deployment,Server}.md` | Placeholders — no infra chosen yet (Phase 30). |
| `.claude/docs/Recommendations.md` | Cross-phase rollup of open follow-ups. |
| `.claude/agents/<Role>Agent.md` | 10 subagent definition templates (`BackendAgent`, `ReviewAgent`, …). |
| `.claude/commands/<Command>.md` (+ `phases/`, `workflow/`) | Slash-command templates: `/Implement /Plan /Refactor /Review /Spec`, `/workflow:*`, `/phases:*`. |
| `.claude/skills/<Topic>Skill.md` | How-to guide templates (`BackendSkill`, `TestingSkill`, …). |
| `.claude/**/{FeatureTemplate,PolicyTemplate,SkillTemplate,PhaseTemplate}.md` | Copy-me templates for `struct.md`'s `[…]` placeholders. |
| `.claude/{agents,commands,skills,commands/phases}/Readme.md` | What each folder is for. |
| `.claude/docs/Design/` | Admin-panel design export — `GomrokAdminPanelV4.dc.html` (+ export variant, `Support.js`); see `.claude/docs/Design/Readme.md`. |

## Source (`src/`, PSR-4 `Gomrok\`)

| Path | What |
|---|---|
| `src/Public/index.php` | Front controller (web root). |
| `src/Bootstrap/ContainerFactory.php` | Builds the PHP-DI container (shared by HTTP + CLI). |
| `src/Bootstrap/AppFactory.php` | Composition root — builds the Slim app on `ContainerFactory`. |
| `src/Config/Settings.php`, `DatabaseSettings.php` | Env-driven immutable settings. |
| `src/Config/container.php` | PHP-DI definitions. |
| `src/Config/routes.php` | Route registration. |
| `src/Http/HealthAction.php` | `GET /health` — public, no auth, no I/O. |
| `src/Http/Api/MeAction.php` | `GET /api/v1/me` — echoes the authenticated client (Phase 7). |
| `src/Database/Migrations/` | Namespaced Phinx migrations (`Gomrok\Database\Migrations\`, timestamped files). Phase 4: currencies, countries, provider_types. Phase 5: idempotency_keys, audit_logs, error_logs. |
| `src/Database/Seeds/` | Phinx seeders + `data/*.json`. `ClientsSeeder` + `ProviderAccountsSeeder` + `ProviderGroupsSeeder` + `PackagesSeeder` + `PricingSeeder` (env-gated dev fixtures, incl. Phase 14 `pro` price rules + Phase 15 control lists / `dach` List B) + `VouchersSeeder` (Phase 16 — `WELCOME10` + `EU5`), `ProviderCapabilitiesSeeder` (from the `Capability` enum), `ProviderTypeDeclarationsSeeder` (stripe + paypal + mollie + ziraat). |
| `.claude/docs/database-design.md` etc. | The schema docs (design / diagram / +`.html` / `db_explain`) + root `mkdocs.yml`. Kebab-case (`Rule.md` §3.1 exception). |
| `src/Modules/Clients/` | **Clients module**: `Domain/` (`Client` aggregate, `ClientApiKey`, `ClientEndpoint`, `ClientSlug`, `ApiKeyToken`, `AuthFailureReason`, repository ports, `Events/`), `Application/` (8 use-case handlers, `ClientDirectory` / `ClientSnapshot`, `Authenticate/` — `ApiKeyAuthenticator` + `AuthAttempt` + `AuthAttemptLog`), `Infrastructure/` (`Pdo*` adapters incl. `PdoAuthAttemptLog`, `RandomApiKeyGenerator`, `definitions.php`). |
| `src/Modules/Providers/` | **Providers module**. Capabilities (Phase 8): `Capability`/`CapabilityGroup`/`PurchaseType`/`PaymentMethod` enums, `ProviderCapabilities`/`ProviderTypeDeclaration` VOs, `ProviderCapabilityResolver`, `ProviderCatalog`, `MethodCapabilityRules`. Accounts (Phase 9): `ProviderAccount` aggregate + `ProviderAccountEndpoint`, `ProviderAccountSlug`/`EncryptedSecret` VOs, `ProviderAccountMode`/`ProviderAccountStatus`/`EndpointKind` enums, `ProviderAccountRepository`, `ProviderAccountDirectory` + `ProviderAccountSummary`, `ProviderAccountCredentials` (decrypt path), 5 use-case handlers, `Pdo*` adapters. Routing (Phase 10): `ProviderGroup` aggregate + `ProviderGroupAccount`, `ProviderGroupSlug`/`ProviderGroupStatus`/`DeviceType`, `ProviderGroupRepository` + `PdoProviderGroupRepository`; `Application/Routing/` — `ProviderRouter`, `RoutingRequest`, `RoutingDecision` (+ `toArray`/`fromArray`), `RoutedAccount`/`RejectedAccount`/`RejectionReason`, `ProviderRoutingDecisionSnapshot` (+ `of()`, Phase 18) + `ProviderRoutingDecisionSnapshotRepository` port + `PdoProviderRoutingDecisionSnapshotRepository`; use cases `CreateProviderGroup`/`ConfigureProviderGroup`/`SetProviderGroupAccounts`/`ChangeProviderGroupStatus`. Adapters (Phase 21): `Application/Adapter/` — core `PaymentProviderPort` + optional `SupportsSubscriptions`/`SupportsRefunds`/`SupportsAuthCapture`/`SupportsCustomerPortal`/`SupportsManualPolling` (Phase 1 Q5's hybrid shape), DTOs (`CreatePaymentCommand`, `ProviderPaymentResult`, `ProviderPaymentStatus`, `CreateSubscriptionCommand`, `ProviderSubscriptionResult`, `ProviderSubscriptionStatus`, `ProviderRefundResult`, `ProviderBillingPortalSession`, `RawWebhook`, `ParsedWebhookEvent`), exceptions (`ProviderAdapterException` + 4 subclasses), `ProviderAdapterFactory` port; `Infrastructure/Adapter/Stripe/` — `StripeAdapter` (uses `stripe/stripe-php`, the only place it's used) + `StripeStatusMapper` (pure); `Infrastructure/DefaultProviderAdapterFactory.php` (the concrete factory, one `match` arm per provider type). `ProviderAccountDirectory` gained `findById()` this phase. |
| `src/Modules/Packages/` | **Packages module** (Phases 11–12). `Domain/` (`Package` aggregate, `PackageCode`, `PackageStatus`, `PackagePurchaseCapability`, `PackageCountryPurchaseCapability`, `PackageProviderDefinition` + `PackageProviderSyncState`, repository ports), `Application/` (`PackageCatalog` + `ResolvedPackage`, `PackagePurchaseCapabilityResolver` + `PackageCapabilitySet` + `ResolvedPurchaseCapability`, `PackageDirectory` + `PackageSummary`, `PackageProviderDefinitionDirectory` + `Summary`, audit snapshots, use cases `CreatePackage`/`UpdatePackage`/`ChangePackageStatus`/`SetPackageAvailability`/`SetPackagePurchaseCapabilities`/`SetPackageCountryPurchaseCapabilities`/`LinkPackageProvider`/`ChangePackageProviderSyncState`), `Infrastructure/` (`Pdo*` adapters, `definitions.php`). Four fail-open availability join tables; purchase capabilities are **fail closed**. |
| `src/Modules/Pricing/` | **Pricing module** (Phase 13–15). `Domain/` (`PricingGroup` + `PricingGroupPackage` + `PriceRule` + `PriceList` aggregates, `DefaultPackagePrice` / `ClientExchangeRate` / `PriceListPackage` VOs, `PricingGroupStatus`/`PricingRowStatus`/`PricingGroupSlug`/`SubscriptionInterval` enums, 7 repository ports), `Application/` (`PriceResolver`, `PriceCatalog`, `PriceRuleResolver` + `PriceRuleContext`, `PriceListResolver`, `ResolvedPrice`/`ResolvedCatalogPackage`/`PriceSource` (`ResolvedPrice::toArray()` — Phase 18), `PricingDecisionSnapshot` (+ `of()`, Phase 18) + `PricingDecisionSnapshotRepository` port + `PdoPricingDecisionSnapshotRepository`, `PricingGroupDirectory`/`PriceRuleDirectory`/`PriceListDirectory` + `Summary`s, `Pricing`/`PriceRule`/`PriceList`AuditSnapshot, use cases `CreatePricingGroup`/`SetPricingGroupCountries`/`ReorderPricingGroups`/`ChangePricingGroupStatus`/`SetDefaultPackagePrice`/`SetClientExchangeRate`/`SetPricingGroupPackage`/`SetPriceRule`/`DeletePriceRule`/`CreatePriceList`/`ChangePriceListStatus`/`SetPriceListFactor`/`SetPriceListPackagePrice`), `Infrastructure/` (10 `Pdo*` adapters, `definitions.php`). Priority-ordered **overlapping** pricing groups; `ResolvedPrice.source` = baseline/converted/group_override/**price_list**/**dimension_override**; `price_rules` = 7 nullable dimensions (most-specific wins, unavailable → hard `pricing.combination_unavailable`); `price_lists` = per-group A/B (one control row + factor / exact-price experiments), resolved between base and rules — **visitor→list assignment deferred to Phase 24**. |
| `src/Http/Api/{PackagesAction,PricingResolveAction}.php` | `GET /api/v1/packages` + `GET /api/v1/pricing/resolve` (Phase 13; P14 adds `method`/`purchase_type`/`interval` params + `applied_rule_id`/`applied_dimensions`). |
| `src/Http/Api/PackageDetailAction.php` | `GET /api/v1/packages/{packageId}` (Phase 19 Q1/Q2) — one package from the same `PriceCatalog::resolve` list `PackagesAction` returns; `{packageId}` = numeric id or code; unavailable-in-context → `404 package.not_found_in_context`. |
| `src/Http/Api/VouchersValidateAction.php` | `GET /api/v1/vouchers/validate` (Phase 19 Q3/Q4) — thin wrapper over `Vouchers\Application\ValidateVoucher\ValidateVoucherHandler`. |
| `src/Modules/Vouchers/` | **Vouchers module** (Phase 16 — definitions & eligibility; Phase 17 — discount calc & redemption; Phase 18 — decision snapshot; Phase 19 — validate endpoint). Source of truth for behaviour: **`.claude/Voucher.md`**. `Domain/` (`Voucher` + `VoucherRedemption` aggregates, `VoucherCurrencyDiscount` + `VoucherEligibilityRule` VOs, `VoucherDecisionSnapshot` (+ `of()`, Phase 18, thin — no amounts) + `VoucherDecisionSnapshotRepository` port, `VoucherStatus`/`DefaultDiscountType`/`DiscountType`/`VoucherEligibilityDimension`/`RedemptionStatus` enums, 5 repository ports incl. `VoucherRepository::findByIdForUpdate`/`incrementRedeemedCount`), `Application/` (`VoucherContext`, `VoucherEligibility`, `VoucherUsagePort` — implemented Phase 17 — `VoucherEligibilityEvaluator`, `VoucherDiscountResult` + `VoucherDiscountCalculator`, `VoucherAuditSnapshot`, `VoucherSummary`/`VoucherRedemptionSummary` + `VoucherDirectory`/`VoucherRedemptionDirectory`, use cases `CreateVoucher`/`UpdateVoucher`/`SetVoucherEligibility`/`SetVoucherCurrencyDiscount`/`RemoveVoucherCurrencyDiscount`/`SetVoucherUsageLimits`/`ChangeVoucherStatus`/`ReserveVoucherRedemption`/`ConfirmVoucherRedemption`/`ReleaseVoucherRedemption`/`ValidateVoucher` (Phase 19 — non-locking eligibility + discount preview, composes `Pricing\Application\PriceResolver`), `Infrastructure/` (7 `Pdo*` adapters incl. `PdoVoucherRedemptionRepository` implementing both `VoucherRedemptionRepository` and `VoucherUsagePort`, `PdoVoucherDecisionSnapshotRepository`, `definitions.php`). `vouchers` carries the default discount + nullable usage-limit columns; `voucher_currency_discounts` overrides it per currency; `voucher_eligibility_rules` scopes across 7 dimensions; `voucher_redemptions` is the reserve→confirm/release lifecycle, keyed by a caller-supplied `attempt_reference`, concurrency-safe via a `SELECT ... FOR UPDATE` lock on `vouchers`; `voucher_decision_snapshots` (Phase 18) freezes the voucher's identity per checkout attempt. |
| `src/Modules/Checkout/` | **Checkout module** (Phase 18 — new module, `checkout_attempts` anchors the pre-payment lifecycle). `Domain/` (`CheckoutAttempt` aggregate — `start()`, `transitionTo()`, `commercialSnapshot()` —, `CheckoutAttemptStatus` enum — 9 ranked happy-path statuses + 4 unranked exits, `rank()`/`isExit()`/`isTerminal()` —, `CheckoutAttemptRepository` port), `Application/` (`CheckoutAuditSnapshot`, `CheckoutAttemptSummary` + `CheckoutAttemptDirectory`, use cases `CreateCheckoutAttempt`/`ResolveCheckoutPricing`/`ReserveCheckoutVoucher`/`SelectCheckoutProvider`/`ChangeCheckoutAttemptStatus` — each Command/Result/Handler, calling into Pricing/Vouchers/Providers through their published `Application/` ports), `Infrastructure/` (`PdoCheckoutAttemptRepository`, `PdoCheckoutAttemptDirectory`, `definitions.php`). |
| `src/Modules/Payments/` | **Payments module** (Phase 20 — new module, aggregate & lifecycle; no real provider adapter or HTTP endpoint yet). `Domain/` (`Payment` aggregate — `create()`, `transitionTo()` —, `PaymentStatus` enum — explicit `allowedNextStatuses()` graph per status, not a rank, `isTerminal()` —, `PaymentRepository` port; `PaymentAttempt` entity + `PaymentAttemptStatus` (`started`/`succeeded`/`failed`) + `PaymentAttemptRepository`; `ProviderTransaction` (write-once) + `ProviderTransactionRepository`; `ProviderCustomer` + `ProviderCustomerRepository`; `GatewayReference` + `GatewayReferenceType` + `GatewayReferenceRepository`), `Application/` (`PaymentAuditSnapshot`, `PaymentSummary` + `PaymentDirectory`, use cases `CreatePayment` — requires a confirmed `checkout_attempts` row, cross-module into Checkout/Pricing/Vouchers — `RecordProviderTransaction`, `ChangePaymentStatus`, `LinkProviderCustomer`), `Infrastructure/` (6 `Pdo*` adapters, `definitions.php`). |
| `src/Modules/<Name>/` | Further business modules (per phase from Phase 9 on). |
| `src/Shared/Domain/` | `Money` (brick/money), `Currency`, `CountryCode`, `Result` + `DomainError` + `ErrorType`, `DomainEvent` marker. IDs are plain `int` — no ID types. |
| `src/Shared/Application/` | Cross-module ports: `TokenGenerator`, `ReferenceCatalog`, `Transactions`, `SecretCipher` (+ `SecretDecryptionFailed`); `Idempotency/` (`IdempotencyStore` …), `Audit/` (`AuditLogWriter`, `AuditEntry`, `AuditActor`), `ErrorLog/` (`ErrorLogWriter`, `ErrorLogEntry`, `ErrorLogLevel`). |
| `src/Shared/Infrastructure/` | `SystemClock` (PSR-20), `CorrelationId`, `SecretRedactor`, `RandomTokenGenerator`, `Crypto/SodiumSecretCipher`, `Logging/` (Monolog JSON), `Persistence/` — `TransactionRunner`, `Row`, `Pdo{IdempotencyStore,AuditLogWriter,ErrorLogWriter,ReferenceCatalog}`, `NullErrorLogWriter`. |
| `src/Shared/Http/` | `Action` base, `JsonResponder` (+ RFC-7807 `problem()`), `JsonErrorHandler`, `CorrelationIdMiddleware`, `IdempotencyMiddleware` (+ `IdempotencyContext`, `IdempotentReplayResolver`), `AuthenticationMiddleware` (+ `ClientAuthenticator` port, `AuthResult`, `AuthenticatedClient`, `AuthRequestMeta`, `ClientContext`). |
| `tests/Support/` | `FrozenClock`, `InMemoryIdempotencyStore`, `SynchronousTransactions`, `InMemoryClient{,ApiKey}Repository`, `InMemoryClientDirectory`, `FixedTokenGenerator`, `InMemoryReferenceCatalog`, `RecordingAuditLogWriter`, `StubClientAuthenticator`, `RecordingAuthAttemptLog`, `InMemoryProviderTypeDeclarations`, `InMemoryProviderAccountRepository`, `StubProviderCatalog`, `StubClientDirectory`, `InMemoryProviderGroupRepository`, `StubProviderAccountDirectory`, `InMemoryPackageRepository`, `InMemoryPackageProviderDefinitionRepository`, `StubPackageDirectory`, `InMemory{PricingGroup,PricingGroupPackage,DefaultPackagePrice,ClientExchangeRate,PriceRule,PriceList,PriceListPackage,Voucher,VoucherEligibilityRule,VoucherCurrencyDiscount,VoucherRedemption,CheckoutAttempt,PricingDecisionSnapshot,VoucherDecisionSnapshot,ProviderRoutingDecisionSnapshot,Payment,PaymentAttempt,ProviderTransaction,ProviderCustomer,GatewayReference}Repository`, `StubProviderAccountCredentials`, `FakeStripeHttpClient` (Phase 21 — a queued-response fake `\Stripe\HttpClient\ClientInterface`, swapped in via `\Stripe\ApiRequestor::setHttpClient()` so `StripeAdapter` tests exercise the real SDK's request/response/exception logic with no network). |
| `src/Jobs/` | `PurgeExpiredIdempotencyKeys` (Phase 5). Queue worker itself: Phase 29. |
| `bin/PurgeIdempotencyKeys.php` | CLI entrypoint for the purge job (`composer idempotency:purge`). |
| `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php` | Client onboarding CLI (`composer client:*`) — Phase 6. |
| `bin/{CreateProviderAccount,RotateProviderAccountSecret,AddProviderAccountEndpoint,ListProviderAccounts}.php` | Provider-account CLI (`composer provider-account:*`) — Phase 9. |
| `bin/{CreateProviderGroup,ConfigureProviderGroup,SetProviderGroupAccounts}.php` | Provider-group / routing CLI (`composer provider-group:*`) — Phase 10. |
| `bin/{CreatePackage,UpdatePackage,SetPackageAvailability,ListPackages}.php` | Package catalogue CLI (`composer package:*`) — Phase 11. |
| `bin/{SetPackageCapabilities,SetPackageCountryCapabilities,LinkPackageProvider}.php` | Package capability / provider-definition CLI — Phase 12. |
| `bin/{CreatePricingGroup,SetDefaultPackagePrice,SetClientExchangeRate,SetPricingGroupPackage,ListPricing}.php` | Pricing CLI (`composer pricing:*`) — Phase 13. |
| `bin/{SetPriceRule,DeletePriceRule,ListPriceRules}.php` | Price-rule CLI (`composer pricing:set-rule`/`delete-rule`/`list-rules`) — Phase 14. |
| `bin/{CreatePriceList,SetPriceListStatus,SetPriceListFactor,SetPriceListPackagePrice,ListPriceLists}.php` | A/B price-list CLI (`composer pricing:create-list`/`set-list-status`/`set-list-factor`/`set-list-price`/`list-lists`) — Phase 15. |
| `bin/{CreateVoucher,UpdateVoucher,SetVoucherEligibility,SetVoucherCurrencyDiscount,RemoveVoucherCurrencyDiscount,SetVoucherUsageLimits,SetVoucherStatus,ListVouchers}.php` | Voucher CLI (`composer voucher:*`) — Phase 16. |
| `bin/{ReserveVoucherRedemption,ConfirmVoucherRedemption,ReleaseVoucherRedemption,ListVoucherRedemptions}.php` | Voucher redemption CLI (`composer voucher:reserve\|confirm\|release\|list-redemptions`) — Phase 17. |
| `bin/{CreateCheckoutAttempt,ResolveCheckoutPricing,ReserveCheckoutVoucher,SelectCheckoutProvider,SetCheckoutAttemptStatus,ListCheckoutAttempts}.php` | Checkout attempt CLI (`composer checkout:start\|resolve-pricing\|reserve-voucher\|select-provider\|set-status\|list`) — Phase 18. |
| `bin/{CreatePayment,RecordProviderTransaction,SetPaymentStatus,LinkProviderCustomer,ListPayments}.php` | Payment CLI (`composer payment:create\|record-transaction\|set-status\|link-customer\|list`) — Phase 20. |
| `bin/{StripeCreateCheckoutSession,StripeGetPaymentStatus}.php` | Stripe adapter CLI (`composer stripe:create-checkout-session\|get-payment-status`) — Phase 21. |

## Tests (`tests/`, PSR-4 `Gomrok\Tests\`)

| Path | What |
|---|---|
| `tests/Unit/` | Domain + application tests, no DB / network. |
| `tests/Integration/` | Adapter / repository / provider-sandbox tests (need MySQL). |

## Config & toolchain (project root)

| Path | What |
|---|---|
| `composer.json` / `composer.lock` | Dependencies + scripts (`test`, `stan`, `cs`, `ci`, `migrate`, …). |
| `phpunit.xml` | Test suites (`unit`, `integration`). |
| `phpstan.neon` | Static analysis — level `max` + strict rules. |
| `.php-cs-fixer.dist.php` | Code style — `@PSR12` + extras. |
| `phinx.php` | Migration runner config. |
| `.github/workflows/Ci.yml` | GitHub Actions — `composer ci` + migrations/integration against a `mysql:8.4` service. |
| `Dockerfile`, `docker-compose.yml` | PHP 8.4 + MySQL 8.4 dev stack. |
| `.env.example` | Copy to `.env`. |
