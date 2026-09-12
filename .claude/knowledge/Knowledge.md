# Knowledge

Domain knowledge about payments, providers, and edge cases learned while building Gomrok.
Not a plan and not a spec — durable facts and gotchas worth keeping.

## Providers

- **Ziraat Bank (Turkey)** is charge-only: bank-hosted payment page / 3D Secure redirect,
  return-URL + webhook/callback, and manual status polling. **No subscriptions, no auto-charge.**
  It exposes no product/registration API (`requires_registration = false`, `api_capable = false`).
- **Stripe / Mollie / PayPal** require a product/price object on their side before a package can
  be sold, and expose an API to create it. Gomrok tracks this per package per provider account
  as a sync state: `synced | not_created | drift | not_needed`.
- **Mollie** capabilities are payment-method-dependent — e.g. recurring may work for card but not
  for a given alternative method. Resolve allowed purchase types per (provider, method) pair, not
  per provider.
- Provider-specific statuses must be mapped to Gomrok's internal statuses; unknown ones are
  stored raw and flagged, never dropped.

## Money

- Currency scale varies: JPY = 0 decimals, USD/EUR = 2, BHD/KWD = 3. Never assume "×100".
  `brick/money` knows the scales; our `Money` VO delegates to it.
- Store integer minor units + ISO 4217 `CHAR(3)`. Never float.

## Pricing / vouchers

- A client never sends a price. Gomrok resolves package availability, price, provider, method,
  purchase type, and voucher validity server-side, then snapshots the decision on the
  payment/subscription so later rule changes don't rewrite history.
- Same package can legitimately cost different amounts by country, currency, provider, and
  method (gateway fees, taxes, market decisions) — e.g. cheaper in Turkey than the EU/US.
- Voucher redemption must be concurrency-safe and idempotent: a duplicate payment request or
  webhook retry must not redeem a voucher twice. Reserve → finalise on paid → release on
  failed/cancelled/expired.

## Identifiers

- Plain `INT UNSIGNED AUTO_INCREMENT` primary keys (from 1); plain `INT` foreign keys.
  **No ULID / UUID / typed-ID classes.** The same `int` is used in the DB, in PHP, and in
  API paths / callback URLs / admin routes. (Decision changed 2026-09-08 — see
  `Architecture.md` §6, `DatabaseAgent.md`, `PhaseResults/PhaseDecisions.md` Phase 1 Q3.)
- Because sequential ints are guessable, cross-tenant safety comes from **authorization** — every
  query is scoped to the authenticated client (`TenantIsolation.md`), never from unguessable ids.
- `BIGINT` is still fine for non-key columns (e.g. `amount_minor`); the "no BIGINT" rule is
  keys-only.

## Reference data

- `brick/money`'s ISO provider has **166** currencies. `currencies` is seeded from it verbatim.
- Money `currency CHAR(3)` columns are **not** DB-FK'd to `currencies` — `Currency::of()`
  validates against the same list in code. `countries.default_currency` *is* a real FK. (Phase 4)
- `countries` holds only the markets Gomrok has decided to operate in (18 to start), not the full
  ISO list — new markets are a follow-up migration.

## Idempotency / audit / error logs (Phase 5)

- `idempotency_keys` stores a **lock + a pointer** (`target_type`/`target_id`), never response
  bodies. A replayed `done` key returns the referenced entity's *current* state, so a status
  change between the first call and the retry is reflected. `processing` → 409, same key +
  different request fingerprint → 422. `expires_at = created_at + 24h`; an expired row is ignored
  on lookup (correctness never depends on the purge job).
- `IdempotencyMiddleware` is **not on the global stack** — the client-API layer registers it
  per write route in Phase 7. It needs a `client_id` request attribute (`authClientId`) that the
  Phase 7 auth middleware will set; with no client resolved it passes straight through.
- `audit_logs.before` / `after` store the **whole** target row as JSON (decision Phase 5 Q2),
  not a changed-columns diff. `SecretRedactor` masks secret-ish keys before any write to
  `audit_logs` or `error_logs.context` — defence in depth; secrets should not be in those arrays
  to begin with.
- `error_logs` is written **only** by the explicit `ErrorLogWriter`, never a Monolog handler
  (decision Phase 5 Q3). `PdoErrorLogWriter` swallows its own failures (logs to stderr) — logging
  an error must not throw over the top of it. Phase 5 wires one call site: `JsonErrorHandler`.
- CI (`.github/workflows/Ci.yml`) is the first place migrations run against real MySQL — there is
  no Docker daemon or usable local DB in this dev environment.

## Clients / API keys (Phase 6)

- A client's **`slug`** is the immutable public handle — cross-module fixtures, config, and CLI
  refer to a client by slug; runtime FKs use the integer `id`.
- API keys: the token `gk_<live|test>_<key_id>.<secret>` is shown **once**. Stored: `key_id`
  (unique, public, safe to log), `sha256(secret)`, `prefix`, `last_four`. Auth (Phase 7) =
  parse → point-read by `key_id` → `hash_equals(sha256(presented), stored)`. No slow KDF — the
  secret is already 192-bit random.
- **Disabling a client is soft and reversible and does NOT revoke its keys** (Phase 6 Q4). The
  Phase 7 auth middleware is the single chokepoint that rejects a disabled client's requests.
- Domain events (`ClientCreated`, …) are **defined but not dispatched** — no subscriber yet. Use
  cases return the event in their result and write an `audit_logs` row directly (actor =
  `system`; a real admin actor comes with the Phase 26 admin panel).
- `Transactions` is the port use cases depend on; `TransactionRunner` (PDO) implements it, tests
  use `SynchronousTransactions`. `Row` coerces `PDOStatement::fetch()` mixed values at the
  adapter boundary.
- Each module ships `Infrastructure/definitions.php` (PHP-DI array); `ContainerFactory` merges
  them — add a row to its `MODULE_DEFINITIONS` list per new module.
- `ClientsSeeder` creates `local-dev` + a **fixed** dev key, and is a **no-op unless
  `APP_ENV ∈ {local, testing}`** — never a known secret in production.

## API authentication & scoping (Phase 7)

- Auth is **`Authorization: Bearer gk_<mode>_<key_id>.<secret>`** only. `/api/v1/*` all require
  it; `/health` is public (outside the group), does no I/O, returns only
  `{"status":"ok","service":"gomrok"}`.
- Failure responses are deliberately opaque: **`401 unauthorized`** for *any* credential fault
  (missing header, bad scheme, unparseable token, unknown key, wrong secret, expired, revoked) +
  `WWW-Authenticate: Bearer realm="gomrok"`; **`403 client_disabled`** only for a valid key on a
  disabled client. The real reason is in `client_auth_attempts.reason`, never the response body.
- `ApiKeyAuthenticator` (Clients) implements the `Shared\Http\ClientAuthenticator` port — the
  middleware never depends on the Clients module. It also records the attempt and throttles
  `last_used_at` (≤ 1 write per key per 5 min).
- The authenticated client rides in **`ClientContext`** (per-request DI singleton, like
  `CorrelationId`) plus request attributes `authClient` / `authClientId` / `authKeyMode`.
  `authClientId` is what `IdempotencyMiddleware` reads. Later modules' repositories take
  `ClientContext` and apply `WHERE client_id = :ctx` themselves.
- `Idempotency-Key` is **required on every `/api/v1` write** — a keyless write gets
  `400 idempotency_key_required` (`IdempotencyMiddleware(requireKeyOnWrites: true)`).
- **No rate limiting yet.** `client_auth_attempts` (success + failure rows) is the groundwork; a
  proper limiter (`429` + `Retry-After`, per-plan limits, admin override) is its own future
  concern.
- `AppFactory::create(?ContainerInterface)` accepts an override container so functional tests
  boot the real Slim stack with the DB adapters stubbed.

## Provider capabilities (Phase 8)

- **Two vocabularies, kept separate** (Phase 8 Q2): `PurchaseType` (`one_time_payment`,
  `recurring_payment`, `auto_charge`, `subscription`) is the routing-level concept — country
  config, package availability, the payment flow all speak it. `Capability` (19 flags:
  `hosted_checkout`, `partial_refund`, `subscription_cancel`, `three_d_secure`,
  `manual_status_polling`, …) is the finer "can it do this action" layer. `subscription` is a
  purchase type, **not** a capability flag.
- `Capability` enum is the source of truth; `provider_capabilities` is a seeded mirror for FK
  integrity + admin display. An integration test keeps them in lock-step (like `currencies`).
- Per-type declarations (`provider_type_capabilities` / `provider_type_purchase_types`) are
  seeded for **stripe + paypal only**. Ziraat & Mollie rows land in their adapter phases (23/22).
  When an adapter lands, its `getCapabilities()` must be a **subset** of what the type declares
  in the DB — the DB is the ceiling.
- Payment methods are **not** a table yet (Phase 8 Q4). Method-level nuance ("Mollie via PayPal
  can't do subscriptions / recurring") is `MethodCapabilityRules`, an in-code placeholder that
  `provider_type_method_capabilities` replaces in Phase 9/12. The `ProviderCapabilityResolver`
  signature already carries `?PaymentMethod` so that's an additive change, not a refactor.
- `ProviderCapabilityResolver` resolves: type declaration − method exclusions. Account (Phase 9)
  and client/country (Phase 10) narrowing wrap it. Unknown provider → `null` / `false`, never a
  silent "yes".

## Provider accounts & secrets (Phase 9)

- Provider **secret keys** are encrypted at rest: `Shared\Application\SecretCipher` port +
  `SodiumSecretCipher` (libsodium `crypto_secretbox`, key from base64 `APP_ENCRYPTION_KEY`).
  Missing key → the cipher throws when first resolved (never a silent fallback). CI + `phpunit.xml`
  set a throwaway key; `.env.example` ships it blank.
- Stored: `secret_ciphertext` + `secret_last_four`. The plaintext is only ever in memory during
  create / rotate (encrypt) and inside a provider adapter (decrypt via `ProviderAccountCredentials`
  — the one decrypt path, kept off `ProviderAccountDirectory`). `public_key` is not secret →
  plaintext.
- `provider_accounts.mode` (`live` / `test`) maps 1:1 to the API key's `gk_live` / `gk_test`
  prefix. **Enforcement is Phase 10 routing** — a test request must never resolve a live account.
- `provider_account_endpoints`: one active per `kind` (webhook/callback/return), enforced in the
  aggregate (`addEndpoint` deactivates the prior active). Rows are never deleted → rotated tokens
  keep history. `token` (unique, `whk_…`) is the URL segment consumed by the Webhooks module
  (Phase 25).
- Account-level capability narrowing is **not** modelled (Phase 9 Q4) — an account inherits its
  provider type's `ProviderCapabilities`; `provider_account_countries` / `_methods` are the only
  account-level filters (used by the Phase 10 router).
- Secret rotation, endpoint add, disable/enable are all **audited** (`provider_account.*` actions);
  `SecretRedactor` + `ProviderAccountAuditSnapshot` keep plaintext/ciphertext out of the audit JSON.

## Provider routing (Phase 10)

- **Provider groups are the only country→provider mechanism** (Phase 10 Q1). There is no
  `country_provider_configs` / `country_provider_priorities` / `country_payment_methods` /
  `country_purchase_capabilities` table — `Phases.md` originally listed them; the Q1 decision
  superseded that.
- A `provider_group` = countries + optional `device_type` + optional `currency_code` + ordered
  `provider_group_accounts` + `provider_group_purchase_types` / `_methods`. `is_default = true`
  is the fallback group (no country rows), one per `(client_id, device_type)`.
- Three invariants are **app-enforced, not DB constraints** (MySQL has no cross-table check):
  one default per scope; a country in ≤1 non-default group per `(client, device_type)`
  (`ConfigureProviderGroup` rejects overlap); a `provider_group_accounts` link's account belongs
  to the group's client (`SetProviderGroupAccounts` rejects otherwise).
- `ProviderRouter::route()` returns `Result<RoutingDecision>`. Purchase-type gate = group set
  **∩** the account's `ProviderTypeDeclaration` (so Turkey `one_time_payment`-only rejects a
  subscription even though Stripe supports it). Empty group purchase-type set = fail closed;
  empty method set = fail open.
- `RoutingDecision` is an **in-memory VO** — no table this phase (Q4). It has `toArray()` /
  `fromArray()` (version-tagged) for Phase 17 to snapshot on the payment. `chosen()` = first
  candidate; the rest are the fallback chain.
- `mode` (live/test) enforcement lives here: `RoutingRequest->mode` comes from the API key
  prefix; a `test` request rejecting a `live` account shows up as `RejectionReason::ModeMismatch`
  (and `provider_routing.no_provider_for_market` if that leaves no candidate).
- Ziraat + Mollie provider-type declarations are now seeded (`data/ProviderTypeDeclarations.json`);
  Ziraat has **no** subscription/auto_charge/recurring purchase types — keep it that way.

## Packages (Phase 11)

- One `packages` table with `client_id`; `code` unique **per client** (`UNIQUE (client_id,
  code)`). **No** global catalogue, **no** `client_packages` junction, **no**
  `packages.enable_for_client` permission — `status = disabled` is the per-client hide switch.
- Availability = 4 dedicated join tables (`package_countries` / `_currencies` /
  `_payment_methods` / `_provider_accounts`), all **fail open**: an empty set for a dimension =
  available everywhere for it (Phase 11 Q2). Same fail-open direction as `provider_group_methods`.
- `PackageCatalog::resolve()` is a **concrete** Application class (like `ProviderRouter`), not a
  port — depends on `PackageRepository` + `ProviderAccountDirectory`. It narrows each package's
  provider-account set to the client's **active** accounts. `PackageDirectory` is the raw read
  port (`Pdo` adapter, `GROUP_CONCAT` projection).
- `ResolvedPackage` deliberately has **no `price`** (Phase 13) and **no `purchaseTypes`** (Phase
  12). `GET /api/v1/packages` is **not** mounted yet — Phase 13 wires it once those exist (Q3).
- `packages.metadata` is a pass-through JSON object — validated as an object at the use-case
  boundary, `json_encode`/`json_decode` at the persistence boundary, never queried.
- Phase 12 adds trial config / `durationMonths` / `badge` / `highlighted` / `clientPackageId` /
  purchase capabilities / package-provider definitions by **additive migration** — the Phase 11
  `packages` table is deliberately lean (Q4).
- `SetPackageAvailability` validates every country/currency (`ReferenceCatalog`), method
  (`PaymentMethod`), and provider-account id (must be in
  `ProviderAccountDirectory::forClient(package.clientId)` — cross-client guard, app-enforced).

## Package capabilities & provider definitions (Phase 12)

- **Purchase capabilities are fail *closed*** — `package_purchase_capabilities` empty = the
  package is **not sellable** and `PackageCatalog::resolve()` drops it. This is the opposite of
  the Phase 11 availability dimensions (fail open). `Package::isSellable()` = active AND ≥1
  global capability.
- Trial config lives on the capability **row** (per purchase type), not on `packages`. Domain
  guard: `has_trial` ⇒ `trial_days` set AND type ∈ {subscription, recurring_payment};
  `PackagePurchaseCapability::of()` throws, `::validate()` returns a `DomainError` for handlers.
- `package_country_purchase_capabilities` **replaces** (not intersects) the global set for a
  country. `Package::effectiveCapabilities(?country)`: no override rows for that country → global
  set; else global ∩ overridden types. The market/provider ∩ (provider group + provider-type
  declaration) is the **payment flow's** job (Phase 17), not the resolver's.
- `PackagePurchaseCapabilityResolver` is a **concrete** Application class (like `ProviderRouter`
  / `PackageCatalog`). `PackageCatalog` now takes it as a 3rd constructor arg.
- `package_provider_definitions` `sync_state`: `not_created` → `synced` (manual `remote_id`) →
  `drift` (local edit) → `not_needed` (opt out). The **drift sweep** is an explicit
  `markStaleForPackage()` repo call inside `UpdatePackage` / `SetPackageAvailability` /
  `SetPackagePurchaseCapabilities` / `SetPackageCountryPurchaseCapabilities` handlers — **not**
  `ChangePackageStatus` (disabling doesn't change the product shape). No event bus (Q5).
- Provider-API product creation (`createRemoteProduct`) is **not** implemented — Phases 21–23
  wire it per adapter. Phase 12 only stores state + accepts a manual id.
- `packages` gained `badge` / `highlighted` / `client_package_id` via additive migration
  `20260910130004` — `client_package_id` is the client's own reconciliation id, **not**
  unique-enforced by Gomrok.
- Editing constructors changed: `UpdatePackageHandler` and `SetPackageAvailabilityHandler` now
  take `PackageProviderDefinitionRepository` — update any test that news them up directly.

## Pricing (Phase 13 — baseline)

- **Pricing groups ≠ provider groups.** Provider groups (Phase 10) are *exclusive* by country
  (routing). Pricing groups are **priority-ordered and overlapping** — a country can be in
  several; the lowest `priority` wins, and `is_default` is forced last. This lets a "Global iOS"
  overlay shadow a regional group for one device (the exit criterion).
- `priority` unique per client, one `is_default` per client, the default group can't be disabled
  — all **app-enforced** (no partial unique in MySQL).
- `default_package_prices` = **one** baseline row per package (`UNIQUE (package_id)`).
  Cross-currency: a `status=default` group in a different currency converts via
  `client_exchange_rates` (client-configured, **effective-dated** — the latest row with
  `effective_from <= now`). `status=override` rows carry their own amount and are never
  converted. No rate → `pricing.no_exchange_rate`.
- Conversion goes through `Shared\Domain\Money::convertTo($currency, $rateString)` (brick,
  HALF_EVEN); `Money` never fetches rates. Added `Money::amount()` (decimal string).
- `pricing_group_packages`: **no row = implicit `status=default`**. `override` ⇒ amount +
  currency, currency = group currency (`PricingGroupPackage::validate`). BIGINT `amount_minor`
  is allowed (keys-only "no BIGINT" rule).
- `PriceResolver` is concrete (like `ProviderRouter` / `PackageCatalog`). `resolveGroup()` +
  `priceForGroup(group, packageId, code, name, badge, highlighted)` are public so `PriceCatalog`
  can resolve the group once. `ResolvedPrice.source` ∈ `baseline` / `converted` / `group_override`.
- **`GET /api/v1/pricing/resolve` is a `GET`, not `POST`** (CLAUDE.md suggests POST). It's a pure
  read; the `/api/v1` write-idempotency middleware would demand an `Idempotency-Key` on any
  POST. Same reasoning will apply to `vouchers/validate`.
- `GET /api/v1/packages` is finally mounted (deferred through Phases 11–12). Response shape is
  stable — Phase 14 overrides / Phase 15 A/B change the *number*, not the structure.

## Pricing overrides (Phase 14)

- **One `price_rules` table, 7 nullable dimensions** (`pricing_group_id`, `country_code`,
  `provider_account_id`, `payment_method`, `purchase_type`, `subscription_interval`,
  `currency_code`). Null = wildcard. `PriceRule::DIMENSIONS` is the canonical order and doubles
  as the tie-break priority (subscription_interval first … pricing_group_id last).
- **Winner** (`PriceRuleResolver`): most matched dimensions → dimension-priority order → highest
  `id`. Only rules where *every* pinned dimension equals the request are candidates.
- **Unavailable is a hard stop.** The most-specific matching rule with `is_available = 0` →
  `pricing.combination_unavailable`; the resolver does **not** fall back to the base price or a
  less-specific rule. This is the exit criterion — a disabled combo never yields a wrong price.
- `PriceResolver` runs rules **after** the Phase 13 base price, so the pricing group + currency
  are already fixed. The `PriceRuleContext.currency` is the *resolved group* currency — a rule
  that pins `currency_code = EUR` therefore never matches inside a USD group (the `converted`
  base price stands there). A pinned group + currency must agree at write time
  (`price_rule.currency_mismatch`).
- Available rule needs `pricing_group_id` **or** `currency_code` pinned (`PriceRule::validate`),
  so a bare "card = €25" must also pin the currency — expect `appliedDimensions` to include
  `currency_code` in that case.
- **NULL-distinct unique index.** `uniq_price_rules_dimensions` spans 8 columns with NULLs;
  MySQL treats NULLs as distinct, so `ON DUPLICATE KEY` won't fire. `PdoPriceRuleRepository`
  upserts via a null-safe (`<=>`) `findByDimensions` lookup; `PricingSeeder` deletes-then-inserts.
- `PriceCatalog` (the browse list, `GET /api/v1/packages`) still uses `priceForGroup` only — **no
  dimension rules**. Rules apply on `GET /api/v1/pricing/resolve` (which gained `method` /
  `purchase_type` / `interval` query params).
- New `SubscriptionInterval` enum: `monthly` / `quarterly` / `yearly`. A rule pinning it needs a
  subscription/recurring `purchase_type` (`price_rule.interval_needs_subscription`).

## A/B price lists (Phase 15 — narrowed)

- **Only the data model + resolver + CRUD shipped.** Visitor→list assignment (persistence,
  hashing, `visitor_ref` params) is **deferred to Phase 24** (Phase 15 Q4/Q5, user's call). See
  [[phase15-ab-assignment-deferred]]. Every `PriceResolver::resolve` currently passes
  `$priceListId = null` → the group's control list. Re-ask Phase 15 Q4 + Q5 at Phase 24.
- **Explicit control row (Q1 Option 2 — user changed the recommendation).** Every pricing group
  owns one `price_lists` row with `is_control = 1`, `factor = 1.0000`, `is_enabled = 1`. Created
  by `CreatePricingGroupHandler` in the same transaction as the group; the migration backfills
  one per pre-existing group; `PricingSeeder` upserts one per seeded group (it uses raw SQL, not
  the handler). The control row can't be renamed-to-collide, disabled, deleted, or re-factored
  (`price_list.cannot_disable_control` / `control_factor_locked`).
- **Pipeline slot: base → price list → `price_rules`** (Q3). `PriceListResolver::apply` runs in
  `PriceResolver::resolve` (not in `PriceCatalog` — the `/packages` browse list stays on the
  base price). A matching Phase 14 `price_rule` still overrides whatever the list produced.
- **Precedence within a list** (Q2): exact `price_list_packages` amount → else `base × factor`
  (HALF_EVEN, `Money::multipliedBy`) → else base unchanged. `ResolvedPrice.source` becomes
  `price_list` **only when the amount moved**; a control / factor-1 list just stamps
  `priceListId` / `priceListName` / `priceListFactor`.
- **Disable-fallback is in the resolver, not a sweep.** `PriceListResolver::resolveList` returns
  the control list whenever `$priceListId` is null, unknown, from another group, or disabled — so
  a future stored assignment pointing at a killed experiment silently drops to control.
- `price_lists.factor` is `DECIMAL(6,4)` — PDO returns it as a string (`"0.9000"`);
  `PriceList::experiment` / `changeFactor` normalise via `number_format(...,4)`.
- `price_list_packages` currency **must** equal the pricing-group currency
  (`price_list_package.currency_mismatch`); not allowed on a control list
  (`price_list.control_has_no_package_prices`).

## Vouchers — definitions & eligibility (Phase 16)

**`.claude/Voucher.md` is the source of truth for all voucher behaviour — check it first, and
add to it before/alongside any voucher change.** This section is a pointer + gotchas, not a
duplicate.

- **Default discount excludes `fixed`.** `DefaultDiscountType` = `none`/`percentage`/`full`
  only. A fixed amount is inherently currency-bound, so it only ever exists as a
  `voucher_currency_discounts` override row (`DiscountType` there does include `fixed`). Two
  separate enums, deliberately.
- **Default + per-currency override, not "amounts per currency".** The user extended the
  original Q2 recommendation: resolution for currency X is override row → else the voucher
  default → else (`default_discount_type = none`) not applicable. An override can be a
  *different type* from the default (e.g. default 5% but a TRY row is `fixed`), and it fully
  specifies its own value + optional cap — no partial inheritance from the default.
- **Usage limits are plain nullable columns on `vouchers`, not a child table** (Phase 16 Q3 —
  the user overrode the recommended `voucher_usage_limits` table). `max_total_redemptions` /
  `max_per_user` / `max_per_client`: `NULL` = unlimited on that axis. The canonical example
  ("valid for everyone, once per user") is `NULL / 1 / NULL` — test it explicitly whenever
  touching usage limits.
- **Eligibility reports every unmet reason, never fail-fast** (Phase 16 Q4). Adding a new check
  means appending to the `reasons` array in `VoucherEligibilityEvaluator::evaluate`, never
  returning early.
- **Minimum purchase only compares same-currency amounts.** If the checkout currency differs
  from `min_purchase_currency`, the check is silently skipped (not rejected) — there is no FX
  conversion inside the eligibility check in Phase 16.
- **First-purchase has two distinct negative reasons.** `isFirstPurchase === null` →
  `voucher.first_purchase_unknown` (indeterminate); `isFirstPurchase === false` →
  `voucher.not_first_purchase`. Don't collapse them — the evaluator has no purchase-history
  lookup, so "unknown" is a real, different case from "known not first".
- **`VoucherUsagePort` was declared but not called** in Phase 16 — the seam existed with no
  adapter bound. **Phase 17 implements it** (`PdoVoucherRedemptionRepository`, doubling as
  `VoucherRedemptionRepository`) and wires it into `VoucherEligibilityEvaluator`, which now
  needs a `VoucherUsagePort` constructor arg — any hand-built evaluator (only test code does
  this) must pass one.
- **`voucher_eligibility_rules.value` has no DB FK** — `package` / `provider_account` values are
  plain VARCHARs validated against the client at write time
  (`SetVoucherEligibilityHandler::validatePackage/validateProviderAccount`), not by the schema.
- **`code` must be ≥3 characters**: `^[A-Z0-9][A-Z0-9_-]{2,63}$`. Two-character test codes like
  `"V1"` fail this regex — use 3+ chars (bit us once in `VoucherHandlersTest`).

## Voucher redemption lifecycle (Phase 17)

- **The `vouchers` row is the lock, not a separate mutex.** `ReserveVoucherRedemptionHandler` /
  `ConfirmVoucherRedemptionHandler` / `ReleaseVoucherRedemptionHandler` all start with
  `VoucherRepository::findByIdForUpdate` (`SELECT ... FOR UPDATE`) before touching
  `voucher_redemptions`. Concurrency safety depends entirely on **every** writer doing this
  first — a new code path that mutates `voucher_redemptions` without locking `vouchers` first
  would silently reopen the race.
- **Reserve re-runs the full eligibility evaluator *inside* the lock**, not a hand-rolled
  duplicate of the cap logic — since the evaluator's `VoucherUsagePort` queries run on the same
  PDO connection/transaction holding the lock, they see a consistent snapshot. This is also why
  the evaluator doubles as both the best-effort pre-check (unlocked, e.g. a future
  `/vouchers/validate`) and the authoritative gate (locked, inside Reserve) — same code, two
  call sites.
- **`nominalDiscountMinor` vs. `appliedDiscountMinor`**: nominal is the discount rule's raw
  value; applied is after *both* the merchant's `max_discount_minor` cap (if any) *and* the hard
  price-floor clamp. Don't fold the cap into "nominal" — a test (`percentageDiscountRespectsTheCap`)
  caught exactly this bug: capping too early made nominal and applied indistinguishable, which
  defeats the point of carrying both.
- **Idempotency is "return what exists," not "error on repeat."** `Reserve` looks up
  `(voucher_id, attempt_reference)` **before** running eligibility/discount logic and returns the
  existing redemption's *current* status verbatim (even if it's since been confirmed or
  released) — a retry never re-validates or re-inserts. `Confirm`/`Release` are idempotent only
  on their *own* terminal state; the *other* terminal state is a hard error
  (`voucher_redemption.already_released` / `voucher_redemption.already_confirmed`), because
  silently accepting "confirm a released row" would be a real bug, not a benign retry.
- **No stale-reservation cleanup exists yet.** An abandoned `reserved` row counts against every
  cap forever until Phase 29 ships the sweep job. `reserved_at` + `idx_voucher_redemptions_status`
  are already in place for it.
- **`VoucherDiscountCalculator::basisPointsToPercent`** converts bp → a decimal string by
  integer div/mod, never float division — `percent_bp` is exact-decimal by construction (1..10000
  bp = 0.01%..100.00%), so `1000/100` as a float would risk a rounding artifact `Money::percentage`
  (which takes a `BigRational`) doesn't need.

## Checkout attempts / decision snapshots (Phase 18)

- **`attempt_reference` is reused, not re-invented.** `ReserveCheckoutVoucherHandler` passes the
  *checkout attempt's own* `attemptReference()` straight through as the voucher redemption's
  `attempt_reference` (Phase 17). One caller-supplied string threads both records — there is no
  second idempotency key to generate or keep in sync. A new checkout step that needs its own
  idempotent child record should reach for this same device before inventing a new one.
- **Three decision-snapshot repositories, one deliberate omission: no `update()`.** This is not
  an oversight — it's how "history never changes when rules change" is enforced at the type
  level, not just by convention. If a future phase needs to *correct* a snapshot (not just add
  one), that's a new decision, not a mutation: `save()` again against a fresh checkout attempt,
  never a patch to an existing row. Anyone tempted to add an `update()` method to
  `PricingDecisionSnapshotRepository` / `VoucherDecisionSnapshotRepository` /
  `ProviderRoutingDecisionSnapshotRepository` should stop and re-read this note first.
- **Why `voucher_decision_snapshots` carries no amounts.** The instinct is to snapshot "what the
  voucher did" the same way `pricing_decision_snapshots` snapshots "what the price resolved to" —
  but the amounts already live, immutably, on `voucher_redemptions` (Phase 17 never mutates them
  after creation). Duplicating them here would just be a second copy that could drift. The table
  exists only because a voucher's *name*/*code* can be renamed later (`UpdateVoucher`) and the
  decision needs to remember what it was called *at the time*.
- **`CheckoutAttemptStatus::transitionTo()` check order matters** — terminal-check first, then
  same-status no-op, then exit, then the `converted_to_payment`-requires-`confirmed` special
  case, then the general rank comparison last. Reordering these (e.g. checking rank before the
  exit case) would wrongly reject `confirmed → failed` (rank 8 → null comparison breaks) or wrongly
  allow a terminal→terminal move. `CheckoutAttemptStatusTest` pins this order with focused cases
  per branch — extend it, don't just add a new status and assume the existing order still holds.
- **`CheckoutAttempt::start()` trims but does not case-normalize `attempt_reference`** —
  deliberately different from `country`/`currency_code`, which *are* upper-cased. A caller's
  attempt reference is an opaque token (could be a UUID, an order number with mixed case, …);
  changing its case would break equality against whatever the caller stores on their side. Don't
  "fix" this to uppercase for consistency with the other fields — it was a specific test failure
  (`commercialSnapshotReflectsTheAttemptsOwnContext` initially expected the wrong case) that
  confirmed this is correct, not a bug.
- **Cross-module snapshot VOs live beside the type they depend on, not in `Checkout`.**
  `PricingDecisionSnapshot` lives in `Modules\Pricing\Application` (needs `ResolvedPrice`);
  `ProviderRoutingDecisionSnapshot` lives in `Modules\Providers\Application\Routing` (needs
  `RoutingDecision`); only `VoucherDecisionSnapshot` lives in `Domain` because it depends only on
  `Voucher`/`VoucherRedemption`, both Domain types. `Checkout`'s handlers call each module's `of()`
  factory and its `save()` port — `Checkout` never defines its own copy of any of the three
  snapshot shapes.

## Resolution API endpoints (Phase 19)

- **A pure `GET` read overrides CLAUDE.md's suggested `POST` whenever nothing is mutated.**
  `pricing/resolve` set this precedent in Phase 14; `vouchers/validate` (Phase 19 Q4) follows it
  again rather than requiring an `Idempotency-Key` for a call that reserves nothing. If a future
  endpoint is tempted to add a per-route exemption to `IdempotencyMiddleware` instead, check
  whether it's a pure read first — `GET` is almost always the simpler, already-established fix.
- **Business logic composing multiple Application-layer services never lives in an Http Action**
  — even when, on the surface, it looks like it could (compare `PricingResolveAction`, which
  *does* compose `PackageDirectory` + `PriceResolver` directly, both pure Application types with
  no further logic to hide). `VouchersValidateAction` does **not** do the equivalent for
  vouchers, because `VoucherEligibilityEvaluator::evaluate()` needs the actual Domain `Voucher`
  aggregate, not a read-only `VoucherSummary` — injecting `VoucherRepository` (a Domain port)
  into an Http Action would leak the Domain layer past Application. The fix was a new
  `Vouchers\Application\ValidateVoucher\ValidateVoucherHandler` that owns the package lookup +
  price resolve + eligibility + discount composition; the Action just maps `Command`/`Result` to
  JSON. Rule of thumb: an Http Action may compose Application-layer read services with no further
  branching, but the moment a Domain aggregate needs to be loaded and interrogated, that
  composition belongs in a Handler, not the Action.
- **`GET /api/v1/packages/{packageId}` deliberately reuses `PriceCatalog::resolve()` — the exact
  same call `PackagesAction` (the list endpoint) makes — rather than writing a second,
  single-package resolution path.** This is why the two endpoints can never disagree: a package
  missing from the list for a given context is, by construction, also a 404 from the detail
  endpoint (`package.not_found_in_context`), because both ask literally the same question. Do not
  "optimize" the detail endpoint into a leaner single-package query — it would reintroduce the
  exact class of drift this design avoids.
- **Testing a package's country restriction requires restricting the *package* (`Package::
  setAvailability(['DE'], …)`), not the pricing group.** A `PricingGroup`'s own `countryCodes`
  are only consulted when `isDefault() === false` (`PriceResolver::resolveGroup` only checks
  `coversCountry()` on non-default groups; a default group is picked as the unconditional
  fallback regardless of its own country list). A test that wants "a valid price resolves for
  country X, but this specific package still isn't available there" needs a `isDefault: true`
  group (so every country resolves *a* group) plus a package-level availability restriction —
  restricting the group's countries instead would make the *group itself* fail to resolve for the
  disallowed country, producing a `pricing.no_pricing_group` error instead of the intended
  `package.not_found_in_context`.

## Payments — aggregate & lifecycle (Phase 20)

- **A decision question can surface a real design flaw — catch it before implementing, not
  after.** When Phase 20 Q2 was first answered "no rule engine yet," the conflict with this same
  phase's own exit criterion ("rejection of illegal transitions tested") was flagged immediately,
  before any code was written, rather than implemented as answered and only discovered at test
  time. The fix: when a recorded answer would make the phase's own stated exit criterion
  impossible to satisfy, say so and ask for a resolution before proceeding — don't silently
  substitute the recommended option, and don't implement a self-contradicting decision either.
- **`PaymentStatus` uses an explicit per-status adjacency list, not a rank, because the graph
  really does branch.** `CheckoutAttemptStatus`'s single-rank-plus-exits design (Phase 18) only
  works because its happy path is genuinely linear. A payment's `paid` status has *three*
  legitimate next states (`refunded`, `partially_refunded`, `disputed`), and `disputed` itself
  can go two ways (`chargeback`, or back to `paid` when a dispute resolves in the merchant's
  favor). Forcing that onto a single rank number was never going to be cleaner than just listing
  `allowedNextStatuses()` per case — don't try to retrofit a rank scheme onto a lifecycle that
  branches; check whether the real state machine is a DAG before reaching for the linear pattern
  just because a linear pattern exists in the codebase.
- **`Payment::transitionTo()` copies Checkout's terminal-before-same-status check order
  deliberately** — a repeat call of the *current* terminal status is rejected
  (`payment.terminal`), not silently accepted as a no-op, mirroring
  `CheckoutAttemptStatus::transitionTo()`'s exact behavior from Phase 18. This is a real
  precedent to preserve, not an arbitrary choice re-derived per module: once *any* status machine
  in this codebase decides terminal-wins-over-same-status, every other one should too, so a
  caller never has to remember which module's terminal check fires first.
- **An attempt's own completion (`succeeded`/`failed`) is never inferred from the payment's new
  status** — `RecordProviderTransactionCommand::$attemptOutcome` is a separate, explicit,
  optional parameter. The temptation is to say "if the new payment status is `paid`, the attempt
  must have succeeded" — but a single attempt can see several status-advancing calls in a row
  while still legitimately "in progress" (e.g. `pending` → `authorized`, two calls, one open
  attempt), and only the caller (eventually, a real Phase 21+ adapter) actually knows when the
  provider is truly done with that specific attempt. Don't add status-based inference here even
  though it looks convenient — it would silently mark attempts complete based on the wrong
  signal.
- **`payments.amount_minor` is computed once, at `CreatePaymentHandler` time, from
  `pricing_decision_snapshots` and (if present) the linked `voucher_redemptions.payable_minor` —
  never from `CheckoutAttempt::commercialSnapshot()` itself**, because `commercialSnapshot()`
  deliberately does not carry a price (see its Phase 18 docblock — it only returns fields the
  Checkout module can "honestly state on its own"). Reaching for `commercialSnapshot()` expecting
  an amount there is a dead end; the price always comes from the sibling decision-snapshot
  tables, found via the same `checkout_attempt_id`.

## Provider adapter port & Stripe adapter (Phase 21)

- **Test a real Stripe SDK integration with a fake transport, not a mocked adapter.** Stripe's
  PHP SDK accepts a swappable `\Stripe\HttpClient\ClientInterface` (`\Stripe\ApiRequestor::
  setHttpClient()`, process-global). `tests/Support/FakeStripeHttpClient.php` implements it as a
  queued-response double, so `StripeAdapterTest` runs the **real** `StripeClient` /
  `\Stripe\Webhook::constructEvent()` / exception-mapping logic — a genuine 401 body really
  produces a real `AuthenticationException`, a genuine HMAC signature really verifies — with zero
  network and zero real credentials. This is strictly better evidence than hand-mocking
  `StripeAdapter`'s own dependencies would have been (which would only prove *our* code calls
  *our* mocks correctly, not that it correctly drives the actual SDK). Always reset
  `ApiRequestor::setHttpClient(new CurlClient())` in `tearDown()` — it's a static/global swap, so
  a test that forgets to reset it leaks the fake into every test that runs after it in the same
  process.
- **A Stripe webhook signature is pure local HMAC-SHA256 — no network needed to test it either.**
  The header format is `t=<unix ts>,v1=<hex hmac_sha256(secret, "{ts}.{payload}")>`
  (`\Stripe\WebhookSignature::verifyHeader`). Tests construct a real, valid header the same way
  Stripe itself would, rather than mocking signature verification away — this is what actually
  exercises the "does our secret-resolution wiring work" question, not just "does our code call a
  boolean-returning function correctly."
- **`getCapabilities()` reads the Phase 8 seeded declaration; it does not hardcode a second copy**
  of what Stripe supports. `StripeAdapter::getCapabilities()` calls
  `ProviderTypeDeclarations::findByCode('stripe')` — the exact same source `ProviderCapabilityResolver`
  and `ProviderRouter` already use. If Stripe's declared capabilities in `data/ProviderTypeDeclarations.json`
  ever change, `getCapabilities()` changes with them automatically; there is no adapter-side list
  to remember to keep in sync.
- **Checkout Sessions and PaymentIntents are two different Stripe status vocabularies** —
  `checkout.session.status` is `open`/`complete`/`expired`, while `payment_intent.status` is a
  much richer state machine (`requires_payment_method`, `requires_action`, `requires_capture`,
  `processing`, `succeeded`, `canceled`, …). `StripeStatusMapper` has two separate methods
  (`fromCheckoutSession()`, `fromPaymentIntent()`) rather than one that tries to guess which
  vocabulary a string belongs to — `getPaymentStatus()` prefers the PaymentIntent mapping when one
  has been expanded (more precise), falling back to the session mapping otherwise. Don't merge
  these into one `match` — the two vocabularies share some string values (`canceled` appears in
  both, meaning different things) with different semantics.
- **An unrecognised raw status always maps to `PaymentStatus::Pending`, never `Failed` or any
  terminal status.** This is a deliberate reading of CLAUDE.md's "unknown provider statuses must
  be stored safely and handled carefully" — `Pending` asserts nothing false (unlike `Paid`,
  `Failed`, or `Refunded`, which would all be claims the mapper can't back up for a string it
  doesn't recognise) and keeps the payment open for a human or a later webhook to resolve. The raw
  string is *always* preserved separately (`ProviderPaymentStatus::$rawStatus`,
  `provider_transactions.provider_status_raw`) regardless of how confident the mapping is.
- **`ProviderAdapterFactory` builds a fresh adapter instance per call; it does not cache or reuse
  one.** Given a client can have multiple accounts of the same provider type (live/test, or
  several merchant sub-accounts), and each needs its own decrypted secret, caching would risk
  serving one account's credentialed client to a request meant for another. The cost of a fresh
  `new StripeClient($secret)` per call is negligible (no I/O happens at construction — the SDK is
  lazy until a method call actually issues a request).

## Gotchas

- `brick/money 0.10.3` calls `BigDecimal::dividedBy()` without a scale internally (via
  `allocate()`); `brick/math 0.14` deprecated that. We pin `brick/math:~0.12.0`. Unpin once
  `brick/money` releases a fix. (Phase 3)
- `Money` arithmetic across currencies throws `MoneyMismatchException` — treated as a programmer
  error (500), not a `DomainError`.
- Webhooks: store the raw event **before** processing, verify signature, respond fast, process
  async. Duplicate webhook ids must be deduped.
- Never silently downgrade a requested purchase type (a subscription request that can't be
  satisfied is an error, not a one-time payment).
- Gomrok is greenfield — there is no "Dexter"/legacy system. Ignore any doc that says otherwise.
- Slim **group** middleware callbacks must NOT be `static` closures (`$this` is bound to the
  `RouteCollectorProxy`) — `routes.php` uses a non-static closure for `$app->group('/api/v1', …)`.
- Swapping a container entry with `$container->set()` after the graph resolved does **not**
  replace an already-built singleton (e.g. a middleware). Functional tests build a fresh
  container per request.
