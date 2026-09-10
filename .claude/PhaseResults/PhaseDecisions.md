# Phase Decisions

The permanent record of every decision question asked during every phase — the question, the
options offered, the recommendation, and the user's final selection. Updated the moment the user
answers a question, not at end of phase. See `.claude/Rule.md` §4.2.

**Order: newest first.** The most recent phase is at the top, Phase 1 at the bottom; within a
phase, questions run in descending order (Q5, Q4, … Q1). **Every new phase's decisions — and
each new question within a phase — is prepended to the top of this file, never appended to the
end.** (`.claude/Rule.md` §4.2.)

- `.claude/Rule.md` → how decisions are asked and recorded.
- `.claude/docs/Phases.md` → roadmap, phase status, timing.
- `.claude/PhaseResults/PhaseDecisions.md` (this file) → all questions, options, recommendations, selections.
- `.claude/PhaseResults/PhaseNNResult.md` → what was actually implemented per phase.

---

## Phase 13 — Pricing module: default prices & pricing groups

### Q5 — CRUD surface

**Question:** How are pricing groups, default prices, exchange rates and group-package rows
managed?

**Options:**

1. **Dedicated handlers + `pricing:*` CLI + seeder.** `CreatePricingGroup`,
   `SetPricingGroupCountries`, `ReorderPricingGroups`, `ChangePricingGroupStatus`,
   `SetDefaultPackagePrice`, `SetClientExchangeRate`, `SetPricingGroupPackage`. ~7 handlers,
   each audited.
2. **Fewer, fatter handlers** — one `ConfigurePricingGroup` doing everything. Awkward partial
   edits.
3. **CLI only, no handlers.** Bypasses `Result` / audit / validation. Rejected.

**Recommended:** Option 1

**Selected:** Option 1 — one audited handler per operation + `pricing:*` CLI + `PricingSeeder`

**Status:** Decided

**Decision Notes:**
- Handlers (all `Result` + audited `pricing.*`): `CreatePricingGroup` (client + slug + name +
  currency + `device_type` + `is_default`; validates one default per client, priority appended
  or forced-last for default), `SetPricingGroupCountries` (full replace; default group rejects
  countries), `ReorderPricingGroups` (ordered slug list → `priority` 1..n; the `is_default`
  group is always last regardless of position passed), `ChangePricingGroupStatus`
  (disable/enable), `SetDefaultPackagePrice` (package + amount + currency; upsert),
  `SetClientExchangeRate` (base + quote + rate + effective_from; upsert on the unique key),
  `SetPricingGroupPackage` (group + package + status + amount/currency + name/badge/highlight
  overrides + display_order; validates override-currency = group currency).
- Audit actions: `pricing_group.created` / `.countries_updated` / `.reordered` / `.disabled` /
  `.enabled`, `default_package_price.set`, `client_exchange_rate.set`, `pricing_group_package.set`.
- CLI: `bin/{CreatePricingGroup,SetPricingGroupCountries,ReorderPricingGroups,SetDefaultPackagePrice,SetClientExchangeRate,SetPricingGroupPackage,ListPricing}.php`
  (`composer pricing:*`). `ListPricing` shows groups (priority, currency, countries) + a
  package × group price matrix.
- `PricingSeeder` (env-gated `local`/`testing`): `local-dev` gets an `is_default` `EUR` group
  (no countries) + a `dach` group (DE/AT/CH, EUR, priority 1) + baseline prices for `starter`
  (€9.00) and `pro` (€29.00) + an `EUR→USD` rate (1.08) + a `pro` override in `dach` (€24.00).
- New `Money` helpers if needed (`Shared\Domain\Money` already wraps brick/money); rounding via
  brick's `RoundingMode::HALF_UP`.

### Q4 — Does the packages API mount now?

**Question:** `GET /api/v1/packages` was deferred through Phases 11–12 "until price exists".
Price exists after this phase.

**Options:**

1. **Mount `GET /api/v1/packages` + `POST /api/v1/pricing/resolve` now,** with the Phase 13
   resolved price. Phases 14–15 refine the number / add a `priceList` field without changing the
   shape.
2. **Wait until Phase 15** (after A/B price lists). Three more phases with no packages API.
3. **Mount behind a `?preview` flag.** Confusing contract.

**Recommended:** Option 1

**Selected:** Option 1 — mount `GET /api/v1/packages` and `POST /api/v1/pricing/resolve` this
phase with the Phase 13 resolved price

**Status:** Decided

**Decision Notes:**
- `PriceResolver::resolve(int clientId, int packageId, string country, string currency, ?string
  deviceType): Result<ResolvedPrice>` — group match (Q1) → group-package row (Q3) → baseline /
  convert (Q2) / override. `currency` is the request/market currency; if it differs from the
  resolved group currency the resolver errors (`pricing.currency_mismatch`) — the caller passes
  the group currency it already resolved, or omits and takes the group's.
- `PriceCatalog::resolve(int clientId, string country, string currency, ?PaymentMethod method,
  ?string deviceType): list<ResolvedCatalogPackage>` — wraps `PackageCatalog::resolve()` (Phase
  11/12) and attaches each package's `ResolvedPrice`; drops packages priced-`disabled` in the
  resolved group.
- **HTTP (client-authenticated, Phase 7 middleware):**
  - `GET /api/v1/packages?country=DE[&method=card][&device=ios]` → resolved catalogue. Currency
    is the matched pricing group's.
  - `GET /api/v1/pricing/resolve?package=<code>&country=DE[&device=ios]` → one `ResolvedPrice`.
    (CLAUDE.md suggests `POST`; kept as `GET` — it is a pure read with no side effects, and the
    `/api/v1` write-idempotency middleware would otherwise demand an `Idempotency-Key`.)
  - Thin actions in `src/Http/Api`; no price is trusted from the client.
- Response `ResolvedPrice`: `amountMinor`, `amountDecimal` (string), `currency`, `source`
  (`baseline` / `converted` / `group_override`), `pricingGroup` (slug), and the effective
  `name` / `badge` / `highlighted`.

### Q3 — The `pricing_group_packages` row

**Question:** Per `(pricing group, package)`: `status` + optional display overrides + display
order. What does the row carry and what does each `status` mean?

**Options:**

1. **One row per (group, package): `status` + amount + display overrides + `display_order`;
   no row = `status=default`.** `status=default` → baseline (converted if needed); `override` →
   this row's `amount_minor`/`currency_code`; `disabled` → not sold in this group. Package with
   no row defaults in at the end.
2. **Explicit rows required; no row = not in the group** (fail closed). Predictable list, but a
   row per package per group including the default group.
3. **Split price row + display-override row.** Over-normalised 1:1.

**Recommended:** Option 1

**Selected:** Option 1 — single `(pricing_group_id, package_id)` row with `status`
(`default`/`override`/`disabled`) + nullable amount/display overrides + `display_order`; a
package with no row is implicitly `default`

**Status:** Decided

**Decision Notes:**
- `pricing_group_packages (id, pricing_group_id → CASCADE, package_id → packages.id CASCADE,
  status VARCHAR(20) [default|override|disabled] DEFAULT 'default', amount_minor BIGINT UNSIGNED
  NULL, currency_code CHAR(3) NULL → currencies.code RESTRICT, name_override VARCHAR(150) NULL,
  badge_override VARCHAR(40) NULL, highlighted_override TINYINT(1) NULL, display_order SMALLINT
  UNSIGNED NOT NULL DEFAULT 0, created_at, updated_at)`. `UNIQUE (pricing_group_id, package_id)`,
  `INDEX (pricing_group_id, display_order)`.
- Domain guards: `status=override` ⇒ `amount_minor` + `currency_code` set and `currency_code` =
  the group's currency; `status ≠ override` ⇒ both NULL. `highlighted_override` NULL = inherit
  `packages.highlighted`.
- Resolution list for a group: explicit rows ordered by `display_order` then `package_id`, then
  packages with no row (implicit `default`) ordered by `packages.code`, all filtered to
  `status ≠ disabled` and to the package being active + sellable (Phase 12).
- `ResolvedPrice` carries: `amount_minor`, `currency_code`, `source` (`baseline` / `converted` /
  `group_override`), the effective `name` / `badge` / `highlighted` after overrides.

### Q2 — Baseline prices and cross-currency

**Question:** `default_package_prices` is the per-package baseline; a pricing group has its own
currency. What happens when they differ?

**Options:**

1. **Single baseline per package + client-configured exchange rates.**
   `default_package_prices (package_id, amount_minor, currency_code)` (one row) +
   `client_exchange_rates (client_id, base_currency, quote_currency, rate, effective_from)`. A
   `status=default` group-package in a different currency converts via the client's rate.
   Deterministic, no external service.
2. **Per-(package, currency) baselines, no FX.** One `default_package_prices` row per currency;
   the client hand-prices every package in every currency.
3. **Single baseline + force an explicit override on any currency mismatch.** Simplest schema,
   but a "sell everything in USD" group means hand-pricing every package and future packages
   silently break the group.

**Recommended:** Option 1

**Selected:** Option 1 — single `default_package_prices` row per package + a
`client_exchange_rates` table; `status=default` cross-currency resolution converts via the
client's configured rate, `status=override` rows bypass it

**Status:** Decided

**Decision Notes:**
- `default_package_prices (id, package_id → packages.id CASCADE [UNIQUE], amount_minor BIGINT
  UNSIGNED, currency_code CHAR(3) → currencies.code RESTRICT, created_at, updated_at)`. One row
  per package; `amount_minor` in the currency's minor unit (`Shared\Domain\Money` / brick).
- `client_exchange_rates (id, client_id → clients.id CASCADE, base_currency CHAR(3), quote_currency
  CHAR(3), rate DECIMAL(18,8), effective_from DATETIME, created_at)`, `UNIQUE (client_id,
  base_currency, quote_currency, effective_from)`. The most recent row with `effective_from <= now`
  wins. Both currency codes FK `currencies.code` RESTRICT.
- `PriceResolver` conversion: `quote_minor = round(base_minor * rate * 10^(quoteScale - baseScale))`
  to the quote currency's minor unit (half-up). No rate for the pair + `status=default` +
  currency mismatch → hard error `pricing.no_exchange_rate`.
- `status=override` group-package rows carry their own `amount_minor` + `currency_code` (must
  match the group currency) — never converted.
- BIGINT allowed for money per the keys-only "no BIGINT" rule.

### Q1 — How do pricing groups match a buyer?

**Question:** The exit criterion says "a `Global iOS` group ordered before `DACH` wins for a
German iOS buyer" — so a country can be in several groups and order matters. How is matching
modelled?

**Options:**

1. **Priority-ordered, overlapping country membership, optional `device_type`, `is_default`
   pinned last.** `pricing_groups (client_id, slug, name, priority, device_type NULL, currency,
   is_default, status)` + `pricing_group_countries`. Resolution: walk the client's `active`
   groups by ascending `priority`; the first whose country set contains the buyer's country
   **and** whose `device_type` matches (or is NULL) wins; else the `is_default` group (priority
   forced last, no country rows). Matches the exit criterion exactly.
2. **Exclusive membership, like provider groups (Phase 10).** A country sits in ≤1 non-default
   group; no priority. Simpler, but cannot express "Global iOS before DACH" for a German iOS
   buyer — contradicts the exit criterion.
3. **Hybrid** — exclusive by country but a separate device-type layer on top.

**Recommended:** Option 1

**Selected:** Option 1 — priority-ordered, overlapping country membership, optional `device_type`
filter, `is_default` group pinned last and non-reorderable

**Status:** Decided

**Decision Notes:**
- `pricing_groups (id, client_id → clients.id CASCADE, slug, name, priority SMALLINT UNSIGNED,
  device_type VARCHAR(10) NULL [web|ios|android], currency_code CHAR(3) → currencies.code
  RESTRICT, is_default TINYINT(1), status VARCHAR(20) [active|disabled], created_at, updated_at)`.
  `UNIQUE (client_id, slug)`; app-enforced `UNIQUE (client_id, priority)` and one `is_default = 1`
  per client.
- `pricing_group_countries (id, pricing_group_id → CASCADE, country_code CHAR(2) → countries.code
  RESTRICT, created_at)`, `UNIQUE (pricing_group_id, country_code)`. The default group has none.
- Resolution (`PriceResolver`): the client's active groups sorted `priority ASC` (default forced
  last regardless of stored priority); first whose `pricing_group_countries` contains the
  request country **and** `device_type` matches (NULL = any) wins; else the `is_default` group;
  else a hard error (client has no default pricing group).
- Distinct from Phase 10 provider groups (which are exclusive-by-country routing) — pricing
  groups deliberately overlap so a "Global iOS" overlay can shadow a regional group for one
  device.

---

## Phase 12 — Package purchase capabilities & provider definitions

### Q5 — CRUD surface + how `synced → drift` is triggered

**Question:** Which handlers / CLI, and how does editing a package mark its provider definitions
stale?

**Options:**

1. **Dedicated handlers + CLI + seeder; drift via an explicit sweep inside the editing
   handlers.** `SetPackagePurchaseCapabilities`, `SetPackageCountryPurchaseCapabilities`,
   `UpdatePackage` (extended), `LinkPackageProvider`, `MarkPackageProviderSynced`,
   `MarkPackageProviderNotNeeded`. Editing handlers run `UPDATE … WHERE package_id = ? AND
   sync_state = 'synced'` → `drift` in the same transaction.
2. **Drift via a domain event + listener.** Cleaner, but no dispatcher wired yet — first real
   listener, extra machinery for one effect.
3. **No automatic drift; a reconcile job detects it later.** Least code, but leaves the state
   machine half-built vs the exit criterion.

**Recommended:** Option 1

**Selected:** Option 1 — dedicated handlers + `package:*` CLI + seeder; `synced → drift` swept
explicitly inside the editing handlers, in-transaction

**Status:** Decided

**Decision Notes:**
- Use cases (all `Result` + audited `package.*`):
  - `SetPackagePurchaseCapabilities` — full-replace of `package_purchase_capabilities`
    (`list<{purchaseType, hasTrial, trialDays, durationMonths}>`); validates trial rules (Q1).
  - `SetPackageCountryPurchaseCapabilities` — full-replace of the `(package, country)` override
    rows; every listed type must be in the package's global set.
  - `UpdatePackage` — extended with `badge` / `highlighted` / `clientPackageId` (+ their clear
    flags).
  - `LinkPackageProvider` — create/update a `package_provider_definitions` row
    (`provider_side_name`, optional `remote_id`); account-ownership checked.
  - `MarkPackageProviderSynced(remoteId)` / `MarkPackageProviderNotNeeded` — explicit state
    transitions.
- **Drift sweep:** a shared private step (`PackageDefinitionDrift::markStale($pdo, $packageId,
  $now)` or a repo method) called at the end of `UpdatePackage`,
  `SetPackagePurchaseCapabilities`, `SetPackageCountryPurchaseCapabilities`,
  `SetPackageAvailability` — sets every `sync_state = 'synced'` definition of that package to
  `drift`. Audited as part of the parent action (context note), not its own row.
- Audit actions: `package.capabilities_updated`, `package.country_capabilities_updated`,
  `package.provider_linked`, `package.provider_synced`, `package.provider_not_needed`
  (+ existing `package.updated` / `package.availability_updated`).
- CLI: `bin/{SetPackageCapabilities,SetPackageCountryCapabilities,LinkPackageProvider}.php`
  (`composer package:set-capabilities` / `:set-country-capabilities` / `:link-provider`);
  `bin/ListPackages.php` extended to show capability + definition counts.
- `PackagesSeeder`: `pro` gains `subscription` (7-day trial, 1-month duration) + `one_time_payment`;
  `starter` gains `one_time_payment`; a `not_needed` definition example if a Ziraat account
  exists.

### Q4 — How does purchase-type resolution surface, and what does `ResolvedPackage` gain?

**Question:** Phase 11's `ResolvedPackage` has no purchase types. How is the Phase 12 data
exposed?

**Options:**

1. **Extend `ResolvedPackage` + a dedicated `PackagePurchaseCapabilityResolver`.** Standalone
   resolver (`for(packageId, ?country)` → country-effective set + per-type trial/duration);
   `PackageCatalog::resolve()` calls it and fills `ResolvedPackage.purchaseTypes` / `badge` /
   `highlighted` / `clientPackageId`. Package with no caps ⇒ dropped from the list.
2. **Only the resolver, `ResolvedPackage` unchanged.** Callers make a second call.
3. **Fold inline into `PackageCatalog::resolve()`.** No separate class; gating not unit-testable
   in isolation, payment flow can't reuse.

**Recommended:** Option 1

**Selected:** Option 1 — new `PackagePurchaseCapabilityResolver` + `ResolvedPackage` gains
`purchaseTypes` (list of `{type, hasTrial, trialDays, durationMonths}`) + `badge` /
`highlighted` / `clientPackageId`; `price` and `GET /api/v1/packages` stay in Phase 13

**Status:** Decided

**Decision Notes:**
- `PackagePurchaseCapabilityResolver::for(int packageId, ?string country): PackageCapabilitySet`
  — global `package_purchase_capabilities`, replaced by `package_country_purchase_capabilities`
  rows if any exist for that country. Returns `list<ResolvedPurchaseCapability>`
  (`purchaseType`, `hasTrial`, `trialDays`, `durationMonths`).
- `PackageCatalog::resolve()` now: drops packages whose country-effective capability set is
  empty (fail closed); fills `ResolvedPackage.purchaseTypes` + `badge` + `highlighted` +
  `clientPackageId`. Still no `price`.
- The market/provider ∩ (provider group + provider-type declaration) is **not** applied here —
  that's the payment-creation flow (Phase 17), which calls the same resolver then intersects.
- `ResolvedPurchaseCapability` and `PackageCapabilitySet` are new Application DTOs.

### Q3 — `package_provider_definitions` — shape and sync-state model

**Question:** Where a package exists on the provider side. Table shape + lifecycle?

**Options:**

1. **One row per `(package, provider account)`, lazily created, 4-state enum.**
   `sync_state` ∈ `not_created` / `synced` / `drift` / `not_needed`; `remote_id` nullable; row
   appears on first link/sync. API creation stubbed → Phases 21–23.
2. **Eager grid** — a `not_created` row per `(package, provider account in availability)`,
   auto-maintained. Complete but churny.
3. **Simpler `remote_id NULL` + `is_synced BOOL`** — loses `drift` and `not_needed`, both named
   in scope.

**Recommended:** Option 1

**Selected:** Option 1 — `package_provider_definitions`, one row per linked `(package, provider
account)`, lazily created, `sync_state` enum with 4 states; manual `remote_id` accepted now,
provider-API creation deferred

**Status:** Decided

**Decision Notes:**
- `package_provider_definitions (id, package_id → packages.id CASCADE, provider_account_id →
  provider_accounts.id CASCADE, provider_side_name VARCHAR(150) NULL, remote_id VARCHAR(191)
  NULL, sync_state VARCHAR(20) NOT NULL DEFAULT 'not_created', last_synced_at DATETIME NULL,
  last_error VARCHAR(255) NULL, created_at, updated_at)`. `UNIQUE (package_id,
  provider_account_id)`, `INDEX (provider_account_id)`, `INDEX (sync_state)`.
- App-enforced: the provider account belongs to the package's client.
- `PackageProviderSyncState` enum: `NotCreated` (no remote yet), `Synced` (`remote_id` set,
  believed current), `Drift` (local package changed since last sync — needs re-push), `NotNeeded`
  (provider has no product model, e.g. Ziraat redirect / one-time).
- Phase 12 transitions: `LinkPackageProvider` sets `provider_side_name` + optional `remote_id`
  (→ `synced` if id given, else `not_created`); `MarkPackageProviderSynced(remote_id)`;
  `MarkPackageProviderNotNeeded`; a package edit (`UpdatePackage` / capability change) flips any
  `synced` definition to `drift` (domain event or explicit sweep — decided in Q5).
- Provider-API `createRemoteProduct()` is **not** implemented — a stub raising
  `not_implemented`; wired per adapter in Phases 21–23.

### Q2 — Country-specific restrictions on a package's purchase types

**Question:** `CLAUDE.md` requires per-package + per-country purchase-type restrictions (Pro is
subscription-capable globally but one-time-only in Turkey). How?

**Options:**

1. **`package_country_purchase_capabilities` override table.** `(package_id, country_code,
   purchase_type)`; rows present for `(package, country)` ⇒ that replaces the global set for
   that country; absent ⇒ inherit `package_purchase_capabilities`. Same semantics as
   provider-group country coverage.
2. **No table — rely on the provider group.** Effective set = package ∩ group ∩ provider
   declaration. But the group is market-wide, can't restrict one package's types in a country.
3. **Denylist table** — lists purchase types removed for a country. Concise for "all except X",
   reads worse for "subscription-only".

**Recommended:** Option 1

**Selected:** Option 1 — `package_country_purchase_capabilities` allowlist override table
(rows present ⇒ replace global set for that country; absent ⇒ inherit)

**Status:** Decided

**Decision Notes:**
- `package_country_purchase_capabilities (id, package_id → packages.id CASCADE, country_code
  CHAR(2) → countries.code RESTRICT, purchase_type VARCHAR(20), created_at)`. `UNIQUE
  (package_id, country_code, purchase_type)`, `INDEX (country_code)`.
- Resolution order (payment flow, Phase 17): package global caps → replaced by the
  `(package, country)` override rows if any exist → ∩ provider-group purchase types (market,
  Phase 10) → ∩ provider-type declaration (Phase 8). **Reject unsupported, never downgrade.**
- A country override may only list purchase types the package supports globally (validated) —
  it narrows, never widens.
- `PackagePurchaseCapabilityResolver::for(packageId, ?country)` returns the country-effective
  set (global ∩/replaced by override); the market/provider ∩ is the payment flow's job.

### Q1 — Where do the purchase types and the Phase-11-deferred fields live?

**Question:** Phase 12 adds: which purchase types a package supports; trial config (`hasTrial`,
`trialDays`); `durationMonths`; and the display fields `badge` / `highlighted` /
`clientPackageId`. How are these split across tables?

**Options:**

1. **Join table for purchase types (config on the row) + display columns on `packages`.**
   `package_purchase_capabilities (package_id, purchase_type, has_trial, trial_days,
   duration_months, ...)` — one row per supported purchase type, carrying the config that only
   makes sense for *that* type (trial ⇒ subscription/recurring). `badge` VARCHAR NULL,
   `highlighted` BOOL, `client_package_id` VARCHAR NULL added to `packages`.
2. **Everything on `packages`.** Purchase types as a set column or a bare `(package_id,
   purchase_type)` table; `has_trial` / `trial_days` / `duration_months` / `badge` /
   `highlighted` / `client_package_id` all columns on `packages` (~6 new columns).
3. **A separate 1:1 `package_settings` table** for every extra field, `packages` stays lean.

**Recommended:** Option 1

**Selected:** Option 1 — `package_purchase_capabilities` join table with per-row trial/duration
config; `badge` / `highlighted` / `client_package_id` as additive columns on `packages`

**Status:** Decided

**Decision Notes:**
- `package_purchase_capabilities (id, package_id → packages.id CASCADE, purchase_type VARCHAR(20)
  [`PurchaseType` value], has_trial TINYINT(1) default 0, trial_days SMALLINT UNSIGNED NULL,
  duration_months SMALLINT UNSIGNED NULL, created_at, updated_at)`. `UNIQUE (package_id,
  purchase_type)`, `INDEX (purchase_type)`.
- Domain guards: `has_trial = 1` ⇒ `trial_days` required and `purchase_type ∈ {subscription,
  recurring_payment}`; `has_trial = 0` ⇒ `trial_days` NULL. `duration_months` allowed on any
  type (entitlement length per purchase); NULL = open-ended / provider-defined.
- Additive migration on `packages`: `badge VARCHAR(40) NULL`, `highlighted TINYINT(1) NOT NULL
  DEFAULT 0`, `client_package_id VARCHAR(64) NULL` (the client's own identifier for
  reconciliation; not unique-enforced by Gomrok).
- Empty `package_purchase_capabilities` set for a package = **not sellable** (fail closed) —
  `CreatePackage` still needs no capabilities, but the catalogue/ payment flow treats a package
  with none as unavailable. (Confirmed against Q4.)

---

## Phase 11 — Packages module: catalog & availability

### Q5 — Use-case decomposition + CRUD surface

**Question:** How are packages and their availability created/edited, and what's the management
surface?

**Options:**

1. **Separate availability handler + CLI + dev seeder.** `CreatePackage`, `UpdatePackage`,
   `ChangePackageStatus`, `SetPackageAvailability` (4 dimension lists, full-replace, own audit
   action). CLI `bin/{CreatePackage,UpdatePackage,SetPackageAvailability,ListPackages}.php`
   (`composer package:*`) + env-gated `PackagesSeeder`.
2. **Availability folded into create/update.** Fewer handlers; `UpdatePackage` rewrites
   availability on every edit, bigger commands.
3. **Per-dimension handlers** (`AddPackageCountry` / … ×4). Granular, 10+ handlers — over-modelled.

**Recommended:** Option 1

**Selected:** Option 1 — `CreatePackage` / `UpdatePackage` / `ChangePackageStatus` /
`SetPackageAvailability` (full-replace, audited `package.availability_updated`); CLI +
env-gated `PackagesSeeder`

**Status:** Decided

**Decision Notes:**
- Audit actions: `package.created`, `package.updated`, `package.disabled` / `package.enabled`,
  `package.availability_updated`.
- `SetPackageAvailability` validates each country/currency against `ReferenceCatalog`, each
  method against `PaymentMethod`, and each provider-account id against
  `ProviderAccountDirectory::forClient(package.clientId)` (rejects a foreign account).
- CLI resolves the client via `ClientDirectory` like the Phase 9/10 scripts;
  `bin/ListPackages.php` shows code / name / status / availability counts.
- `PackagesSeeder` (env-gated `local`/`testing`): `local-dev` gets ~2 demo packages
  (e.g. `starter`, `pro`), one unrestricted, one restricted to `DE` + `EUR`.

### Q4 — What columns does the `packages` table carry this phase?

**Question:** `Phases.md` parks trial config, `durationMonths`, `badge`, `highlighted`,
`clientPackageId` and purchase capabilities in Phase 12. How lean is the Phase 11 table?

**Options:**

1. **Lean — catalogue identity only.** `id`, `client_id` FK CASCADE, `code` (`UNIQUE (client_id,
   code)`), `name`, `description` TEXT null, `status` (`active`/`disabled`), `metadata` JSON
   null, `created_at`, `updated_at`. Phase 12 adds its columns additively.
2. **Lean + display fields** (`badge`, `highlighted`, `display_order`). Fewer later migrations,
   but `Phases.md` names those as Phase 12 / pricing-group scope.
3. **Everything now** — all Phase 12 fields too. One migration, but front-runs Phase 12's
   decisions.

**Recommended:** Option 1

**Selected:** Option 1 — lean `packages` table (`code`, `name`, `description`, `status`,
`metadata` JSON, timestamps); Phase 12 adds its fields with additive migrations

**Status:** Decided

**Decision Notes:**
- `packages`: `id` INT UNSIGNED AI, `client_id` INT UNSIGNED FK → `clients(id)` CASCADE,
  `code` VARCHAR(64), `name` VARCHAR(150), `description` TEXT null, `status` VARCHAR(20) default
  `active` (`PackageStatus` enum), `metadata` JSON null, `created_at` DATETIME, `updated_at`
  DATETIME null.
- Indexes: `UNIQUE (client_id, code)`, `INDEX (client_id, status)`.
- `PackageStatus` = `active | disabled` (`DisablePackage` is the per-client hide switch — no
  `packages.enable_for_client` permission, per the Package Ownership Rule).
- `metadata` JSON validated as an object on the way in; stored via the `Row`-style boundary.

### Q3 — The resolved package list: shape, and does the HTTP endpoint ship now?

**Question:** `GET /api/v1/packages` is in the API design, but this phase has no price (Phase 13)
and no purchase types (Phase 12). What do we build?

**Options:**

1. **Internal `PackageCatalog` port only; defer the HTTP endpoint to Phase 13.** `resolve()`
   returns `list<ResolvedPackage>` (id, code, name, description, metadata, market-narrowed
   providers + methods). Route mounted once price + purchase types exist.
2. **Port + a live endpoint now with `price: null` / `purchaseTypes: []`.** Early integration
   target, but the shape changes meaningfully in 12–13.
3. **Port + a minimal `{id, code, name}` endpoint now.** Minimal churn, low usefulness, still
   re-wired later.

**Recommended:** Option 1

**Selected:** Option 1 — internal `PackageCatalog` port + `ResolvedPackage` DTO this phase;
`GET /api/v1/packages` mounted in Phase 13

**Status:** Decided

**Decision Notes:**
- `PackageCatalog::resolve(int clientId, string country, string currency, ?PaymentMethod method):
  list<ResolvedPackage>` — active packages whose 4 availability dimensions all match the context
  (empty = match, per Q2).
- `ResolvedPackage` DTO: `id`, `code`, `name`, `description`, `metadata`, `availableProviderAccountIds`
  (the package's set ∩ what the client has), `availableMethods`. **No `price`, no `purchaseTypes`**
  this phase — added by Phases 13 / 12.
- Also a `PackageDirectory` port (`findById` / `find(clientId, code)` / `forClient`) for other
  modules + CLI.
- The payment-creation flow (Phase 17) consumes `PackageCatalog` directly; no HTTP dependency.

### Q2 — What does an empty availability set mean?

**Question:** When a package has no rows for a dimension (e.g. no `package_countries`), is it
available everywhere for that dimension, or nowhere?

**Options:**

1. **Empty = available everywhere (fail open), per dimension.** No rows → sold in every market
   the client operates in; add rows to restrict. Matches `provider_group_methods`. A package is
   hidden only by `status = disabled` or an explicit restriction.
2. **Explicit allowlist (fail closed), per dimension.** Available only where a row exists; empty
   → invisible. Safer against leaks, more ceremony on create.
3. **Per-dimension mode flag** (`all` | `allowlist` | `denylist` columns on `packages`). Most
   flexible, most moving parts; no requirement needs denylist yet.

**Recommended:** Option 1

**Selected:** Option 1 — empty set = available everywhere for that dimension (fail open),
per dimension; `status = disabled` and explicit restriction rows are the only ways to hide a
package

**Status:** Decided

**Decision Notes:**
- Resolver logic per dimension: `rows == [] → match` else `requested value ∈ rows`.
- `CreatePackage` needs only `code` + `name`; restriction is a separate audited operation
  (`SetPackageAvailability` / `UpdatePackage`).
- A denylist dimension, if ever needed, is an additive change (extra table or mode column).

### Q1 — How is package availability (country / currency / method / provider) stored?

**Question:** A `package` is available in some subset of the client's markets. How are the four
availability dimensions modelled?

**Options:**

1. **Four dedicated join tables** — `package_countries` (FK → `countries.code`),
   `package_currencies` (FK → `currencies.code`), `package_payment_methods`
   (`PaymentMethod` value), `package_provider_accounts` (FK → `provider_accounts.id`). Typed,
   FK'd, indexable; mirrors `provider_account_*` / `provider_group_*`.
2. **One generic `package_availability` table** with a `dimension` enum + `value` string. Fewer
   tables, but `value` is untyped, no FK on country/currency, awkward queries.
3. **JSON columns on `packages`** (`available_countries`, …). No extra tables; no FK, no
   index-assisted "packages available in DE" query.

**Recommended:** Option 1

**Selected:** Option 1 — four dedicated join tables (`package_countries`, `package_currencies`,
`package_payment_methods`, `package_provider_accounts`)

**Status:** Decided

**Decision Notes:**
- `package_countries (id, package_id → packages.id CASCADE, country_code CHAR(2) →
  countries.code RESTRICT, created_at)`, `UNIQUE (package_id, country_code)`, `INDEX (country_code)`.
- `package_currencies (id, package_id → CASCADE, currency_code CHAR(3) → currencies.code
  RESTRICT, created_at)`, `UNIQUE (package_id, currency_code)`.
- `package_payment_methods (id, package_id → CASCADE, payment_method VARCHAR(20) [`PaymentMethod`
  value, app-enforced], created_at)`, `UNIQUE (package_id, payment_method)`.
- `package_provider_accounts (id, package_id → CASCADE, provider_account_id → provider_accounts.id
  CASCADE, created_at)`, `UNIQUE (package_id, provider_account_id)`, `INDEX (provider_account_id)`.
  App-enforced: the account belongs to the package's client.
- Matches `provider_account_*` / `provider_group_*`. Empty/non-empty semantics settled in Q2.

---

## Phase 10 — Country provider configuration & routing resolution

### Q5 — Mollie & Ziraat capability data for the exit criteria

**Question:** Exit criteria need Turkey/Ziraat (one-time only), Germany/Mollie (card + PayPal),
NL/PayPal-only. Phase 8 seeded capability declarations for Stripe + PayPal only. How do we get
the Ziraat / Mollie data the Phase 10 tests need?

**Options:**

1. **Seed real Ziraat + Mollie declarations now.** Extend `ProviderTypeDeclarationsSeeder` / its
   JSON. Unit tests use stubs; integration + routing tests use the seeded data. The four named
   providers get real capability data that later phases need anyway.
2. **Stubs only, no seed change.** Smallest change; every later phase re-invents the fixtures and
   `composer db:setup` can't route Turkey/Germany.
3. **Seed all four + Mollie per-method nuance now.** Front-runs the Phase 12 method-capability
   tables.

**Recommended:** Option 1

**Selected:** Option 1 — seed real Ziraat + Mollie provider-type declarations this phase

**Status:** Decided

**Decision Notes:**
- `data/ProviderTypeDeclarations.json` gains `ziraat` and `mollie`:
  - **ziraat** — purchase types: `one_time_payment`. Capabilities: `hosted_checkout`,
    `redirect_payment`, `three_d_secure`, `webhook`, `return_url`, `manual_status_polling`.
    No subscription / auto_charge / recurring — must stay absent (drives the
    subscription-in-Turkey rejection test).
  - **mollie** — purchase types: `one_time_payment`, `recurring_payment`, `subscription`.
    Capabilities: `hosted_checkout`, `redirect_payment`, `three_d_secure`, `webhook`,
    `return_url`, `refund`, `partial_refund`, `subscription`, `subscription_cancel`,
    `customer_portal`, `manual_status_polling`. Methods handled at group level (Q2); per-method
    capability split (Mollie PayPal ≠ recurring) stays deferred to Phase 12.
- `ProviderTypeDeclarationsSeeder` already iterates the JSON — no code change, just data +
  update its row-count assertion / test.
- Routing/integration tests seed provider **accounts** (ziraat-tr, mollie-de, paypal-nl) +
  groups on top of the declaration data.

### Q4 — Does Phase 10 persist routing decisions?

**Question:** The `RoutingDecision` snapshot ultimately gets stored on a payment, but Phase 10
has no `payments` table yet (Phase 17). Does Phase 10 create any snapshot storage now?

**Options:**

1. **VO only, no persistence.** `ProviderRouter` returns the VO in memory; a
   `RoutingDecisionSnapshot::toArray()` / `fromArray()` pair ready for later JSON storage. No
   table. Matches the incremental-DB rule.
2. **Standalone `provider_routing_decisions` table now.** Full audit of every resolve call, but
   an orphan table for 7 phases + a later FK-backfill migration.
3. **Log-only.** Structured log line, no DB, no persistence contract — rebuilt in Phase 17
   anyway.

**Recommended:** Option 1

**Selected:** Option 1 — VO only + tested `toArray()` / `fromArray()` serialisation contract, no
table this phase

**Status:** Decided

**Decision Notes:**
- No migration for routing decisions in Phase 10. Phase 17 adds a JSON column (or
  `payment_routing_snapshots` row) on the payment and calls the serializer.
- `RoutingDecisionSnapshot` (or `RoutingDecision::toArray()`): stable array shape — version tag,
  resolved group slug, chosen account id + slug + provider-type code + mode, ordered candidate
  ids, rejection reasons. `fromArray()` reconstructs a read-only view (no repository lookup).
- Round-trip unit test (`toArray` → `fromArray` → equals) is part of this phase's tests.
- Structured "provider routing decision" logging (CLAUDE.md logging section) may be emitted
  alongside — additive, not a blocker.

### Q3 — What does the resolver return?

**Question:** When a caller asks "route this purchase (client, country, currency, purchase type,
method)", what comes back?

**Options:**

1. **Ordered candidate list + `RoutingDecision` snapshot.** A value object: ordered eligible
   provider accounts (by `priority`), the chosen one (first), the resolved group, and the reason
   each rejected account was dropped. Enables fallback without re-resolving; is exactly the
   "provider routing decision snapshot" CLAUDE.md names.
2. **Single chosen account only.** Simplest; no fallback; re-work needed in the payments phase.
3. **Ordered list, no snapshot metadata.** Fallback works; "why X" debugging means re-running
   with logging.

**Recommended:** Option 1

**Selected:** Option 1 — ordered candidate list + `RoutingDecision` snapshot VO

**Status:** Decided

**Decision Notes:**
- `RoutingDecision` (immutable VO, `Modules/Providers/Application` or a `Routing` sub-namespace):
  `clientId`, `country`, `currency`, `purchaseType`, `?paymentMethod`, `resolvedGroup`
  (id + slug + `isDefault`), `candidates: list<RoutedAccount>` ordered by priority,
  `chosen(): RoutedAccount` (= `candidates[0]`), `rejections: list<RejectedAccount>`
  (accountId + slug + `RejectionReason` enum: `account_disabled`, `mode_mismatch`,
  `group_disabled`, `purchase_type_not_in_group`, `purchase_type_unsupported_by_type`,
  `method_not_in_group`, `currency_not_supported`, `country_not_in_account`).
- `RoutedAccount`: accountId, slug, providerTypeCode, mode, priority.
- No candidates → the resolver returns a `Result::err(DomainError)` (`no_provider_for_market`
  with the country/purchase-type context), never a partial/empty decision.
- Serialisable to a JSON snapshot column/table by the payments phase (Phase 17) — Q4 settles
  whether Phase 10 persists it at all.

### Q2 — Where do purchase-type and payment-method enablement live?

**Question:** `CLAUDE.md` requires explicit *country* purchase-capability control that can be
stricter than what the provider supports (Turkey = `one_time_payment` only). Under the
provider-groups model (Q1), at what granularity is that enablement stored?

**Options:**

1. **Group-level enablement.** `provider_group_purchase_types (group_id, purchase_type)` +
   `provider_group_methods (group_id, payment_method)`. The group declares what the market sells;
   the router then keeps only accounts whose provider-type declaration also supports the
   requested purchase type. One row set per market; matches how CLAUDE.md frames it.
2. **Per (group, account) enablement.** Purchase types / methods pinned on each
   `provider_group_accounts` row. Finer control (mixed provider capabilities in one market), ~2×
   config rows, over-modelled for the stated needs.
3. **Filter-only, no explicit enablement.** Allowed set = provider-type declaration ∩ account
   country/method filters. Fewest tables but **fails the requirement** — a country can't be
   restricted below the provider's declared capabilities.

**Recommended:** Option 1

**Selected:** Option 1 — group-level `provider_group_purchase_types` + `provider_group_methods`,
intersected at resolve time with each account's provider-type capability declaration

**Status:** Decided

**Decision Notes:**
- `provider_group_purchase_types (id, provider_group_id → CASCADE, purchase_type VARCHAR(20)
  [one_time_payment | recurring_payment | auto_charge | subscription], created_at)`,
  `UNIQUE (provider_group_id, purchase_type)`. A group with no rows = no purchase type allowed
  (fail closed); seeders/CLI must populate it.
- `provider_group_methods (id, provider_group_id → CASCADE, payment_method VARCHAR(20),
  created_at)`, `UNIQUE (provider_group_id, payment_method)`. Empty = all methods the resolved
  account supports (fail open on method, since method is a refinement not a gate); revisit if a
  real "block this method in this country" case appears.
- Router keeps an account only if: account is `active`, its `mode` matches the request,
  `provider_group_accounts.is_enabled = 1`, the requested `purchase_type` ∈ group set **and** ∈
  the account's provider-type `ProviderTypeDeclaration` purchase types, and (if the group has
  method rows) the requested method ∈ group set.
- Unsupported purchase type for the market → hard error, never a downgrade (CLAUDE.md).

### Q1 — Config scope: provider groups, per-country config, or both?

**Question:** `Phases.md` names both `country_provider_configs` (per-country) and the design's
**provider groups** (named sets of countries + an ordered provider-account priority list). They
overlap — one client, one country, one authoritative "which accounts, in what order". How is the
override configured?

**Options:**

1. **Provider groups as the single mechanism.** A `provider_group` = a named set of countries
   (+ optional device-type filter) + an ordered list of the client's provider accounts + the
   purchase types / methods it enables. A country belongs to at most one non-default group per
   client. The client's **default order** is itself a group (`is_default = true`, no country
   restriction) used when no other group covers the country. One model to reason about; matches
   the admin panel design.
2. **Per-country config + groups both.** `country_provider_configs` (client × country) is the
   authoritative override; groups are a bulk-edit convenience that *writes* per-country configs.
   Resolution only ever reads per-country. More tables, but "edit all EU at once" without a
   join-time merge.
3. **Per-country config only, no groups.** Each (client, country) configured individually; the
   client default covers the rest. Simplest schema; tedious to keep 15 EU countries in sync,
   and the design explicitly calls for groups.

**Recommended:** Option 1

**Selected:** Option 1 — provider groups as the single mechanism (client default = an `is_default` group)

**Status:** Decided

**Decision Notes:**
- `provider_groups (id, client_id → clients.id CASCADE, slug, name, is_default TINYINT(1),
  device_type VARCHAR(10) NULL [web|ios|android; NULL = any], status VARCHAR(20)
  [active|disabled], created_at, updated_at)`. `UNIQUE (client_id, slug)`; a partial-unique
  guard (app-enforced) — one `is_default = 1` group per `(client_id, device_type)`.
- `provider_group_countries (id, provider_group_id → CASCADE, country_code CHAR(2) → countries.code,
  created_at)`, `UNIQUE (provider_group_id, country_code)`. The default group has **no** country
  rows (covers everything not claimed by another group). App enforces: a country is in at most
  one non-default group per `(client_id, device_type)`.
- `provider_group_accounts (id, provider_group_id → CASCADE, provider_account_id →
  provider_accounts.id CASCADE, priority SMALLINT UNSIGNED, is_enabled TINYINT(1), created_at)`,
  `UNIQUE (provider_group_id, provider_account_id)`, ordered by `priority` ASC.
- Purchase-type / method enablement: finalised in Q4.
- Resolution: find the client's group whose `provider_group_countries` contains the request
  country (and `device_type` matches or is NULL), else the client's `is_default` group. No group
  and no default → hard error (client not configured for that market).

---

## Phase 9 — Provider accounts (per client)

### Q5 — CRUD surface + dev fixture

**Question:** Phase 9 has no admin UI. How are provider accounts created / rotated, and does the
`local-dev` client get one?

**Options:**

1. **CLI commands + an `APP_ENV`-gated dev seeder** (like Phase 6). `bin/CreateProviderAccount.php`
   (`--client --provider-type --mode --name --public-key --secret-key [--country ...] [--method ...]`),
   `bin/RotateProviderAccountSecret.php`, `bin/AddProviderAccountEndpoint.php`,
   `bin/ListProviderAccounts.php`. A seeder gives `local-dev` a **test** Stripe account with a
   fake secret + a webhook endpoint, only for `local` / `testing`, so integration tests have
   something to resolve.
2. **CLI commands only, no seeder.** Real onboarding path; integration tests build their own
   account rows via a fixture helper. No committed fake credentials at all.
3. **Seeder only.** Zero-friction for tests; but no production onboarding path, and it bypasses
   the use cases.

**Recommended:** Option 1

**Selected:** Option 1 — CLI commands + `APP_ENV`-gated dev seeder

**Status:** Decided

**Decision Notes:**
- CLI (`bin/`, PascalCase, resolve from `ContainerFactory`):
  - `CreateProviderAccount.php` `--client=<slug|id> --provider-type=<code> --mode=<live|test>
    --name=<name> --public-key=<pk> --secret-key=<sk> [--slug=<slug>] [--country=DE ...]
    [--method=card ...]` → `CreateProviderAccount` use case. Secret is read from the flag or
    `STDIN`; **never echoed back**.
  - `RotateProviderAccountSecret.php` `--account=<client-slug>/<account-slug> [--secret-key=<sk>]`
    → `RotateProviderAccountSecret` (audit row).
  - `AddProviderAccountEndpoint.php` `--account=… --kind=<webhook|callback|return>
    [--signing-secret=<s>]` → generates + prints the `token` + inbound URL once.
  - `ListProviderAccounts.php` `[--client=<slug>]` — client, provider type, mode, name,
    `secret_last_four`, endpoint count. **No secrets.**
- `ProviderAccountsSeeder` (Phinx, env-gated `local` / `testing` only): for the `local-dev`
  client, one `stripe` account `mode = test`, slug `stripe-test`, an obviously-fake public key
  and secret (the string `gomrok-local-dev-fake-...` — deliberately **not** shaped like a real
  provider credential so GitHub secret scanning does not flag the fixture; encrypted via the
  same `SecretCipher` the app uses), countries `DE, NL`, methods `card`, plus one `webhook`
  endpoint with a fixed token `whk_localdev_stripe_test`. Idempotent (upsert on `(client_id,
  slug)` / `token`). No-op outside `local` / `testing`.
- Depends on `ClientsSeeder` + `ProviderTypesSeeder`.
- The seeder needs the `APP_ENCRYPTION_KEY` env var set (CI + `.env.example` provide one);
  without it the seeder logs why and skips, like it does for the wrong `APP_ENV`.

---

### Q4 — Account-level narrowing: which config does the account carry?

**Question:** The provider *type* declares capabilities + purchase types (Phase 8). An *account*
serves a subset — specific countries, specific payment methods, and possibly a narrowed set of
capabilities / purchase types. What does Phase 9 persist?

**Options:**

1. **Countries + methods join tables only; capabilities / purchase types inherited from the
   type.** `provider_account_countries (provider_account_id, country_code)` and
   `provider_account_methods (provider_account_id, payment_method)`. An account can do whatever
   its type declares (Phase 8) for the methods it offers, in the countries it serves. Narrowing
   capabilities/purchase types below the type is deferred until something needs it.
2. **All four join tables** — also `provider_account_capabilities` and
   `provider_account_purchase_types` as explicit allow-lists (default = the type's full set,
   admin can disable entries). Complete; 4 tables; most rows are just a copy of the type
   declaration.
3. **JSON columns on `provider_accounts`** — `countries`, `methods`, `capability_overrides`.
   Fewest tables; not FK-checked, not queryable, and the Phase 10 router wants to `JOIN` on
   country / method.

**Recommended:** Option 1

**Selected:** Option 1 — `provider_account_countries` + `provider_account_methods` join tables; capabilities/purchase types inherited from the type

**Status:** Decided

**Decision Notes:**
- `provider_account_countries (id, provider_account_id → provider_accounts.id ON DELETE CASCADE,
  country_code CHAR(2) → countries.code, created_at)`, `UNIQUE (provider_account_id, country_code)`.
- `provider_account_methods (id, provider_account_id → provider_accounts.id ON DELETE CASCADE,
  payment_method VARCHAR(20) [PaymentMethod enum], created_at)`,
  `UNIQUE (provider_account_id, payment_method)`.
- An account's *effective* capabilities/purchase types = its provider type's declaration
  (Phase 8) narrowed by `MethodCapabilityRules` for the chosen method. No per-account capability
  rows this phase.
- The Phase 10 router: candidate accounts = client's accounts of the right `mode`, filtered by
  `provider_account_countries` ∋ requested country, `provider_account_methods` ∋ requested
  method, then `ProviderCapabilityResolver` for the purchase-type / capability check.
- A future additive `provider_account_capabilities` (allow/deny) is possible without disruption
  if a real "disable refunds on this account" case appears.
- `country_code` FK to `countries.code` means an account can only serve a configured market
  (consistent with `clients.default_country`).

---

### Q3 — Webhook / callback verification config: where does it live?

**Question:** Each provider account needs verification config for incoming provider messages: a
**signing secret** (verify webhook signatures — Stripe/Mollie/PayPal) and an **inbound routing
token** (the opaque segment in the URL Gomrok exposes, e.g.
`/api/v1/webhooks/{provider}/{token}`, so a webhook maps back to the right account without
trusting the body). Ziraat uses a callback + return URL rather than signed webhooks but still
needs a shared secret / hash key.

**Options:**

1. **Columns on `provider_accounts`.** `webhook_signing_secret_ciphertext` (via `SecretCipher`),
   `webhook_token CHAR(x) UNIQUE` (random, used in the inbound URL), `callback_hash_key_ciphertext`
   (Ziraat-style). 1:1 with the account. Simple; one row is the whole picture.
2. **A separate `provider_account_endpoints` table.** `(id, provider_account_id, kind
   [webhook|callback|return], token, signing_secret_ciphertext, is_active)`. Supports a provider
   that sends to multiple endpoints, or rotating a token without losing history. More flexible,
   one more table, mostly 1:1 in practice.
3. **Derive the token from the account, store only the signing secret.** The inbound URL is
   `/api/v1/webhooks/{provider}/{accountId}`; no separate token. Fewer columns; but the account
   id is guessable and not rotatable, so a leaked URL can't be invalidated without deleting the
   account.

**Recommended:** Option 1

**Selected:** Option 2 — a separate `provider_account_endpoints` table

**Status:** Decided

**Decision Notes:**
- `provider_account_endpoints (id, provider_account_id → provider_accounts.id ON DELETE CASCADE,
  kind VARCHAR(10) [webhook | callback | return], token CHAR(x) UNIQUE NULL,
  signing_secret_ciphertext TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at,
  updated_at)`.
- `UNIQUE (provider_account_id, kind, is_active)` is too strict (only one active per kind — fine
  now, but the table's point is flexibility); use `UNIQUE (token)` (where non-null) +
  `INDEX (provider_account_id, kind)`. One active endpoint per kind is enforced in the
  application for now.
- `token` — random URL-safe string used in `/api/v1/webhooks/{provider}/{token}`; nullable for
  `return`-kind rows that don't need one. Rotatable without touching `provider_accounts`.
- `signing_secret_ciphertext` — via `SecretCipher` (Q1); the provider's webhook signing secret
  or Ziraat's callback hash key. Nullable (a `return` endpoint has no secret).
- `kind`: `webhook` (signed async events), `callback` (server-to-server, e.g. Ziraat),
  `return` (browser redirect back — usually just a URL/flag, no secret).
- Adding/rotating an endpoint is its own use case with an `audit_logs` row.
- This table is created in Phase 9 but the **inbound webhook route** that consumes `token` is
  Phase 25 (Webhooks module).

---

### Q2 — `mode` (live / test) and how it gates account selection

**Question:** Phase 7 gave client API keys a `gk_live` / `gk_test` prefix (`ApiKeyPrefix`). A
provider account holds real or sandbox credentials. How do the two relate?

**Options:**

1. **Account has a `mode` enum (`live` | `test`); the request's key prefix picks the pool.** A
   client typically has one live and one test account per provider type. A `gk_test` request can
   only be routed to `mode = test` accounts; `gk_live` → `mode = live`. Matches Stripe's model
   (separate keys per mode). `UNIQUE (client_id, provider_type_id, mode, slug)` or similar.
2. **One account row carries both live and test credentials.** `mode` is a property of the
   request, not the account; the row has `live_secret_ciphertext` + `test_secret_ciphertext`.
   Fewer rows; but Mollie / PayPal / Ziraat don't all have a clean "same account, two keys"
   model, and it complicates rotation and per-mode webhook config.
3. **No `mode` field; test vs live is just another account, distinguished only by name/slug.**
   Simplest schema; but nothing stops a `gk_test` request hitting a live account — the guard
   would be convention, not a constraint.

**Recommended:** Option 1

**Selected:** Option 1 — `mode` enum on the account; key prefix picks the pool

**Status:** Decided

**Decision Notes:**
- `Gomrok\Modules\Providers\Domain\ProviderAccountMode` — `live` | `test`. Maps 1:1 to
  `ApiKeyPrefix` (`gk_live` ↔ `live`, `gk_test` ↔ `test`).
- `provider_accounts.mode VARCHAR(10)` (app-enforced). Uniqueness:
  `UNIQUE (client_id, slug)` (slug is client-scoped and human — e.g. `stripe-live`,
  `stripe-test`); slug already implies the mode by convention but `mode` is the authoritative
  field.
- The Phase 10 router will filter candidate accounts by `mode = requestMode` derived from the
  authenticated key's `ApiKeyPrefix` — a `gk_test` request can **never** resolve a `live`
  account. Phase 9 just stores `mode`; the enforcement point is Phase 10 routing / the payment
  flow.
- Per-mode webhook secret + callback token live on the same row (Q3), so live and test have
  independent verification config.

---

### Q1 — How provider secret keys are stored

**Question:** A `provider_accounts` row holds the client's credentials for a provider — a public
key/id and a **secret key** (`CLAUDE.md`: "never hardcoded … environment variables or secure
secret storage … never displayed in plain text … secret rotation is an audited action"). How is
the secret stored?

**Options:**

1. **Application-encrypted column, behind a `SecretCipher` port.** `provider_accounts` stores
   `secret_ciphertext` (+ a small `secret_key_hint`/`last_four` for display). A
   `Gomrok\Shared\Application\SecretCipher` interface with a libsodium implementation
   (`sodium_crypto_secretbox`, key from env `APP_ENCRYPTION_KEY` / a keyfile) does encrypt /
   decrypt. Rotating the *provider* secret is a normal update; rotating the *encryption* key is
   a re-encrypt migration. A Vault / AWS-Secrets-Manager implementation of the port can slot in
   later without touching the schema or callers.
2. **Direct encrypted column, no port.** Same storage, but the cipher is a concrete
   `Shared\Infrastructure` service used directly — no interface. Less indirection; swapping to
   an external secret store later means a bigger change.
3. **External secret store now** — `provider_accounts` stores only a `secret_ref`; the actual
   secret lives in Vault / a KMS from day one. Most "correct" for production, but there is no
   such infrastructure in this project yet (Phase 30 picks deploy targets), so the default
   implementation would be a local file/DB store anyway — i.e. option 1 with extra ceremony.

**Recommended:** Option 1

**Selected:** Option 1 — application-encrypted column behind a `SecretCipher` port

**Status:** Decided

**Decision Notes:**
- `Gomrok\Shared\Application\SecretCipher` — `encrypt(string $plaintext): string` /
  `decrypt(string $ciphertext): string` (throws `SecretDecryptionFailed` on tamper / wrong key).
- `Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher` — `sodium_crypto_secretbox` with a
  random 24-byte nonce prepended to the ciphertext, base64-encoded for storage. Key = 32 bytes
  from env `APP_ENCRYPTION_KEY` (base64), loaded via `Settings`. A missing/short key is a
  boot-time error, not a silent fallback.
- `provider_accounts` stores `secret_ciphertext TEXT` + `secret_last_four CHAR(4)` (+ maybe
  `public_key VARCHAR` in the clear — public keys are not secret). The plaintext secret is only
  ever in memory during encrypt (create/rotate) and decrypt (when calling the provider).
- Never logged; `SecretRedactor` already masks `*secret*` keys in audit/error JSON. Secret
  rotation is a dedicated use case that writes an `audit_logs` row (`provider_account.secret_rotated`).
- `.env.example` gets `APP_ENCRYPTION_KEY=` with a note to generate one
  (`php -r 'echo base64_encode(random_bytes(32));'`). CI sets a throwaway key.
- Vault / AWS Secrets Manager = a future `SecretCipher` (or `SecretStore`) implementation;
  no schema change needed then.

---

## Phase 8 — Providers module: types & capability model

### Q5 — Seed the four known providers' capability declarations now?

**Question:** `provider_type_capabilities` / `provider_type_purchase_types` need rows for
stripe / mollie / paypal / ziraat. Seed them this phase, or leave the tables empty until each
provider's adapter phase (21–23)?

**Options:**

1. **Seed all four now**, from a bundled data file, based on `CLAUDE.md` §*Payment Gateway
   Capability Structure* + `Knowledge.md` (Stripe: hosted_checkout / one_time / subscription /
   recurring / auto_charge / refund / partial_refund / customer_portal / auth-capture / … ;
   Mollie: method-dependent, core + subscription + refund ; PayPal: core + subscription +
   refund ; Ziraat: one_time_payment + hosted_checkout + redirect_payment + three_d_secure +
   webhook + manual_status_polling, **nothing else**). Phase 10 routing + the admin panel have
   real data immediately; the adapter phases correct any row that turns out wrong.
2. **Seed Ziraat + Stripe only** (the two with the clearest, most-cited capability sets), leave
   Mollie / PayPal for their adapter phases. Less speculative data; but Phase 10 routing tests
   would only have two providers to resolve between.
3. **Seed none** — tables ship empty; each adapter phase adds its provider's rows. Zero
   speculation; but Phase 8's exit test and all of Phase 10 have no data to work with and would
   need throwaway fixtures.

**Recommended:** Option 1

**Selected:** Other — seed **Stripe + PayPal** only (a variant of Option 2, with PayPal in place
of Ziraat)

**Status:** Decided

**Decision Notes:**
- `provider_type_capabilities` / `provider_type_purchase_types` get seeded rows for **stripe**
  and **paypal** only, from `src/Database/Seeds/data/ProviderTypeCapabilities.json` (or a seeder
  constant). Ziraat and Mollie rows are added in **their** adapter phases (23 / 22).
- **Exit-criterion coverage without those rows:** the resolver operates on a
  `ProviderTypeDeclaration` value object (supported purchase types + capabilities), which tests
  construct directly — no DB needed. So the Phase 8 resolution-matrix tests still cover
  *Stripe vs Ziraat* (Ziraat = an in-code charge-only fixture) and *Mollie card vs PayPal*
  (Mollie = in-code fixture) even though only Stripe/PayPal are persisted. Consistent with Q4's
  in-code `MethodCapabilityRules`.
- `PdoProviderTypeDeclarations` (the DB-backed reader) returns declarations for stripe/paypal
  now; the two deferred providers simply aren't in the DB until their phases.
- Seeder is idempotent (upsert on the unique keys) — adjusting a flag later is one edit + re-seed.

---

### Q4 — Payment-method granularity: model it now or defer?

**Question:** `CLAUDE.md` (Knowledge.md too) stresses that capabilities are
payment-method-dependent — "a provider may support recurring card payments but not recurring
PayPal payments". Does Phase 8 introduce payment methods and method-level capabilities, or stay
at provider-type granularity?

**Options:**

1. **Model methods now** — add `payment_methods` (catalogue: `card`, `paypal`, `ideal`,
   `bank_transfer`, `bank_hosted_card`, …), `provider_type_methods` (which type offers which
   method), and `provider_type_method_capabilities` (method-level cap overrides). The
   capability resolver already accounts for method. Complete, but ~3 more tables and seed data
   for combinations that later phases (routing, packages) will actually exercise.
2. **Provider-type granularity now; methods in Phase 9/12** — Phase 8 ships `provider_types` +
   capabilities + purchase types at the **type** level only. The resolver takes an optional
   `?PaymentMethod` and, for now, ignores it (type-level answer). `payment_methods` +
   method-level capability tables are added when provider **accounts** (Phase 9) or **package
   provider/method availability** (Phase 12) first need them — the seam is there from day one.
3. **Provider-type granularity, no method seam at all** — simplest; add method as a
   cross-cutting concept later with a bigger refactor of the resolver signature.

**Recommended:** Option 2

**Selected:** Option 2 — provider-type granularity persisted now; method enum + resolver logic + tests now, method tables deferred

**Status:** Decided

**Decision Notes:**
- `Gomrok\Modules\Providers\Domain\PaymentMethod` — backed enum this phase (`card`, `paypal`,
  `ideal`, `bancontact`, `sepa_direct_debit`, `bank_hosted_card`, … — trimmed to what the four
  known providers actually use; extend later). **No `payment_methods` table yet.**
- `ProviderCapabilityResolver` signature takes `?PaymentMethod $method = null`. With no
  method-level data persisted, a non-null `$method` currently resolves to the same answer as
  the provider type (the seam is exercised, the data isn't there yet).
- **Method-level nuance is captured in code for Phase 8's tests only**: a small in-module
  `MethodCapabilityRules` map (e.g. "Mollie + `paypal` → no `subscription`") the resolver
  consults. It's explicitly a placeholder — a `// TODO(Phase 12)` — replaced by
  `provider_type_method_capabilities` rows when package/method availability (Phase 12) or
  provider accounts (Phase 9) need real persistence.
- Phase 8 exit test ("Mollie card vs PayPal") runs against that in-code rule map + the resolver.
- `payment_methods` catalogue + `provider_type_methods` + `provider_type_method_capabilities`:
  deferred to **Phase 9 or Phase 12**, whichever first needs persisted per-method data.

---

### Q3 — How a provider type's declared capabilities are stored

**Question:** Each `provider_types` row (stripe, mollie, paypal, ziraat) declares which
`Capability`s and `PurchaseType`s it can do *in principle* (before any account or country
config narrows it). How is that per-type declaration stored?

**Options:**

1. **Join tables** — `provider_type_capabilities (provider_type_id, capability_id)` and
   `provider_type_purchase_types (provider_type_id, purchase_type VARCHAR)`. Rows, real FKs,
   queryable ("which provider types support `partial_refund`"), seeded per known provider.
   Standard relational modelling; two more tables.
2. **JSON columns on `provider_types`** — `capabilities JSON`, `purchase_types JSON`. No extra
   tables; the whole declaration for a type is one row. Not FK-checked, not indexable, every
   read deserialises + validates against the enum in code.
3. **Code-only, from the adapter** — no DB at all; `StripeAdapter::getCapabilities()` etc. are
   the declaration, read at runtime. But adapters don't exist until Phases 21–23, and the
   admin panel / routing resolution (Phase 10) need the data before then.

**Recommended:** Option 1

**Selected:** Option 1 — join tables (`provider_type_capabilities`, `provider_type_purchase_types`)

**Status:** Decided

**Decision Notes:**
- `provider_type_capabilities (id, provider_type_id → provider_types.id, capability_id →
  provider_capabilities.id, created_at)`, `UNIQUE (provider_type_id, capability_id)`.
- `provider_type_purchase_types (id, provider_type_id → provider_types.id, purchase_type
  VARCHAR(20) [app-enum], created_at)`, `UNIQUE (provider_type_id, purchase_type)`.
- Both seeded per known provider (Q5) from a bundled data file / seeder constant.
- When adapters land (Phases 21–23), each adapter's `getCapabilities()` is validated to be a
  **subset** of what its provider type declares here — the DB is the ceiling.
- FK on-delete: CASCADE from `provider_types` (deleting a provider type is a whole-phase event
  anyway).
- Method-level nuance (Mollie card vs PayPal) handled in Q4.

---

### Q2 — Purchase types: a separate concept, or capability flags?

**Question:** `CLAUDE.md` repeatedly names four **purchase types** — `one_time_payment`,
`recurring_payment`, `auto_charge`, `subscription` — and says to "keep [them] as separate
purchase capabilities". They also appear in the capability-flag list. Are they modelled as their
own first-class concept, or just more entries in the `Capability` enum?

**Options:**

1. **Separate `PurchaseType` enum + its own resolution.** `Gomrok\Modules\Providers\Domain\PurchaseType`
   (`one_time_payment` | `recurring_payment` | `auto_charge` | `subscription`), distinct from
   `Capability`. A provider type / method declares which purchase types it supports *and* which
   capabilities. Payment / package / country config all speak in `PurchaseType`; capabilities
   are the finer-grained "can it refund / 3DS / customer-portal" layer. Matches how `CLAUDE.md`
   frames "country purchase capabilities" vs "gateway capabilities".
2. **One flat `Capability` enum, purchase types included.** `one_time_payment` etc. are just
   capability flags among the ~20. Simpler vocabulary; but "which purchase types does Turkey
   allow" and "does this provider support partial refunds" become the same kind of check, and
   the domain language blurs the two.
3. **`PurchaseType` enum, but derived from capabilities.** The enum exists for the API/domain
   language, but "supports subscription" is computed from the `subscription` capability flag
   rather than stored separately. Less duplication; but `auto_charge` / `recurring_payment`
   don't map 1:1 to a single capability, so the derivation gets special-cased.

**Recommended:** Option 1

**Selected:** Option 1 — separate `PurchaseType` enum, distinct from `Capability`

**Status:** Decided

**Decision Notes:**
- `Gomrok\Modules\Providers\Domain\PurchaseType` — backed enum: `one_time_payment` |
  `recurring_payment` | `auto_charge` | `subscription`.
- `Capability` (Q1) keeps the finer flags (`hosted_checkout`, `redirect_payment`,
  `embedded_payment_form`, `card_tokenization`, `authorization`, `capture`, `cancel`, `refund`,
  `partial_refund`, `subscription_cancel`, `subscription_pause`, `subscription_resume`,
  `customer_portal`, `billing_portal`, `invoice`, `webhook`, `return_url`, `three_d_secure`,
  `manual_status_polling`). Purchase types are **not** in this enum.
- A provider type declares BOTH a set of supported `PurchaseType`s and a set of `Capability`s
  (storage in Q3). Country config (Phase 10) and package config (Phase 11/12) constrain
  `PurchaseType`; the payment flow additionally checks `Capability` for the specific action.
- Purchase types are their own concept, not a reference table for now (4 fixed values, enum
  only) — revisit if the admin panel needs labels/descriptions.

---

### Q1 — How the capability catalogue is represented

**Question:** Gomrok needs a fixed vocabulary of provider capability flags (`hosted_checkout`,
`refund`, `partial_refund`, `subscription`, `three_d_secure`, `manual_status_polling`, … — ~20
from `CLAUDE.md` → *Payment Gateway Capability Structure* / `Architecture.md` §8). Where does
that vocabulary live?

**Options:**

1. **PHP enum only** — `Gomrok\Modules\Providers\Domain\Capability` (backed enum). The source of
   truth is code; no DB table. Per-type capability data (Q3) FK-references nothing — it stores
   the enum's string values, validated in the app. Simplest; the admin panel renders the enum;
   adding a capability is a code change + migration for any data that uses it.
2. **Enum + a seeded `provider_capabilities` reference table** — the enum stays the source of
   truth; a small table (`id`, `code`, `label`, `description`, `group`) is seeded from it so
   per-type / per-account capability rows can carry a real FK, and the admin panel has labels
   and grouping to display. Kept in sync by the seeder (like `provider_types`).
3. **Table only, no enum** — `provider_capabilities` is authoritative; code refers to
   capabilities by string constant or by looking them up. No compile-time safety; typos in
   capability checks aren't caught.

**Recommended:** Option 2

**Selected:** Option 2 — PHP enum (source of truth) + a seeded `provider_capabilities` reference table

**Status:** Decided

**Decision Notes:**
- `Gomrok\Modules\Providers\Domain\Capability` — backed enum, the source of truth. Every
  capability gate in code uses the enum.
- `provider_capabilities` table: `id`, `code` (unique, = enum value), `label`, `description`,
  `capability_group` (e.g. `payment`, `refund`, `subscription`, `security`, `operational`),
  `created_at`. Static reference data — seeded from the enum by `ProviderCapabilitiesSeeder`
  (idempotent upsert on `code`), like `provider_types` / `currencies`.
- Per-type / per-method / per-account capability rows (Q3, Q4, later phases) FK to
  `provider_capabilities.id`.
- A test asserts the enum and the seeded table are in lock-step (every enum case has a row, no
  orphan rows) — same guarantee `currencies` has.
- Exact column widths + the `capability_group` value set finalised in the schema proposal.

---

## Phase 7 — Client API authentication & scoping

### Q5 — Idempotency-Key enforcement + abuse-protection scope for this phase

**Question:** Two related loose ends: (a) is `Idempotency-Key` **required** on `/api/v1` writes,
and (b) how much abuse protection (rate limiting, failed-auth lockout) lands in Phase 7 vs later?

**Options:**

1. **Idempotency-Key required on all `/api/v1` writes; abuse protection = failed-attempt
   logging only.** `IdempotencyMiddleware` attached to the write group; a `POST/PUT/PATCH/DELETE`
   without the header → `400 idempotency_key_required` (the middleware already 400s on a
   malformed key). Failed auth is written to a new `client_auth_attempts` table (ts, ip,
   key_id?, reason, outcome) for the admin panel and future rate limiting. **No rate limiting or
   lockout yet** — deferred to its own concern once there is real traffic and an admin view.
2. **Idempotency-Key optional; add a simple fixed-window rate limiter now.** Writes without a
   key just aren't deduped. Add a per-`key_id` (and per-IP for failed auth) fixed-window counter
   in a `rate_limits` table or an in-process store, returning `429` with `Retry-After`.
   More surface, and a DB-backed limiter is itself hot-path write load.
3. **Idempotency-Key required on payment/subscription creation only; failed-attempt logging +
   an in-memory per-IP failed-auth throttle.** Middle ground: enforce the key only where a
   duplicate is financially harmful; throttle brute-force auth in-process (no DB), lightweight.

**Recommended:** Option 1

**Selected:** Option 1 — key required on all `/api/v1` writes; failed-attempt logging only

**Status:** Decided

**Decision Notes:**
- `IdempotencyMiddleware` attached to the `/api/v1` write routes. A write without an
  `Idempotency-Key` → `400` `{code: "idempotency_key_required"}`. (Phase 5 middleware already
  400s a malformed key and 409/422 for the concurrency/reuse cases.) Reads (`GET`) don't need it.
- New table **`client_auth_attempts`** — one row per failed OR successful auth on `/api/v1`
  (successes give the admin panel "last successful auth" and a future limiter its baseline).
  Columns proposed in the schema step: `id`, `outcome` (`success`/`failure`), `reason`
  (`missing_authorization` | `malformed_token` | `unknown_key` | `invalid_secret` |
  `key_expired` | `key_revoked` | `client_disabled` | `ok`), `key_id` (nullable — parsed token
  only, never the secret), `client_id` (nullable), `ip`, `user_agent`, `correlation_id`,
  `created_at`. Indexes on `(ip, created_at)`, `(key_id, created_at)`, `(client_id, created_at)`.
- **No rate limiting / lockout in Phase 7.** A dedicated rate-limiting concern (per-plan limits,
  `429` + `Retry-After`, admin override) comes later, reading `client_auth_attempts`.
- Writing an attempt row is best-effort (failure logged, request proceeds/fails on its own
  merits). Written via a small port so it can move async later.
- Retention: not addressed now; a purge job like `PurgeExpiredIdempotencyKeys` when it matters.

---

### Q4 — Auth failure responses

**Question:** What does the API return when authentication or authorization fails, and how much
does the body say?

**Options:**

1. **Two statuses, minimal generic bodies.** `401` for *anything wrong with the credential*
   (missing / malformed header, unknown `key_id`, bad secret, expired/revoked key) — body
   `{"type":"about:blank","title":"Authentication required","status":401,"code":"unauthorized"}`
   + `WWW-Authenticate: Bearer realm="gomrok"`. `403` only for *a valid key whose client is
   disabled* — `{"...","status":403,"code":"client_disabled"}`. No hint which of the 401 causes
   applied (anti-enumeration). Correlation id echoed for support.
2. **Granular error codes.** As option 1 but distinct `code`s per cause
   (`missing_authorization`, `malformed_token`, `unknown_key`, `invalid_secret`, `key_expired`,
   `key_revoked`, `client_disabled`) so clients can self-diagnose. Leaks whether a given
   `key_id` exists / whether the secret was the wrong part.
3. **401 for everything, including the disabled client.** One status, one body. Simplest;
   loses the "your key is fine, your account is off" signal that saves a support round-trip.

**Recommended:** Option 1

**Selected:** Option 1 — two statuses (401 credential / 403 client_disabled), generic bodies

**Status:** Decided

**Decision Notes:**
- `401` for every credential problem — missing/malformed `Authorization`, wrong scheme,
  unparseable token, unknown `key_id`, secret mismatch, expired key, revoked key. Body:
  `{"type":"about:blank","title":"Authentication required","status":401,"code":"unauthorized"}`
  + `WWW-Authenticate: Bearer realm="gomrok"`.
- `403` only when the key authenticates but `client.status = disabled` — body
  `code":"client_disabled"`, `title":"Client is disabled"`.
- No body field distinguishes the 401 sub-causes. `X-Correlation-Id` is on every response so
  support can correlate to the server-side log.
- The real reason is recorded once per failure via the failed-attempt log (Q5) with the parsed
  `key_id` when available and a `reason` enum — never the secret.
- The middleware returns the problem response directly via `Shared\Http\JsonResponder` (does not
  throw).

---

### Q3 — `last_used_at` write strategy

**Question:** The auth path can stamp `client_api_keys.last_used_at` on every request. At payment
volume that is a write on the hot path. How often is it actually written?

**Options:**

1. **Throttled write (write only if stale).** On a successful auth, if `last_used_at` is null or
   older than a threshold (recommend **5 minutes**), issue a single `UPDATE … SET last_used_at =
   :now WHERE id = :id`. Otherwise skip. Bounds writes to ~1 per key per 5 min; `last_used_at`
   is "accurate to within 5 minutes", which is all an admin/audit view needs.
2. **Write every request.** Simple, exact. One extra `UPDATE` per authenticated request — real
   write amplification and row-lock contention on a busy key.
3. **Don't track `last_used_at` yet.** Leave the column null; wire it when there's a reason
   (key-rotation reminders, stale-key cleanup). Zero hot-path cost now.

**Recommended:** Option 1

**Selected:** Option 1 — throttled write (only if `last_used_at` is stale)

**Status:** Decided

**Decision Notes:**
- Threshold constant `LAST_USED_THROTTLE = 300` seconds. On successful auth, if `last_used_at`
  is `null` or `< now - 300s`, run a single `UPDATE client_api_keys SET last_used_at = :now
  WHERE id = :id`; else skip.
- Done via a `ClientApiKeyRepository::touchLastUsed(int $id, DateTimeImmutable $now)` method (new
  in this phase) — the authenticator decides whether to call it based on the in-memory key it
  already loaded.
- The write is best-effort: a failure to stamp `last_used_at` must not fail the request (log and
  continue).
- `last_used_at` is therefore accurate to ~5 minutes — fine for the admin dormant-key view
  (Phase 26).

---

### Q2 — How the authenticated client is carried through the request

**Question:** Once the middleware authenticates a request, how do downstream actions, use cases,
and repositories get "the current client" so every query is scoped to it?

**Options:**

1. **Request attribute only.** The middleware sets
   `$request->withAttribute('authClient', ClientSnapshot)` (+ `authClientId`, `authKeyMode`).
   Actions read the attribute and pass the id explicitly into every command / query. No shared
   mutable state; but the client id has to be threaded through every call signature, and a
   forgotten `WHERE client_id = …` is a silent cross-tenant leak.
2. **Request attribute + a per-request `ClientContext` holder (DI singleton).** As option 1,
   plus a `Gomrok\Shared\Http\ClientContext` (like `CorrelationId`) the middleware populates.
   Repositories and query services depend on `ClientContext` and apply the scope automatically;
   actions still get the attribute for convenience. The scope is enforced in one place per
   repository, not per call site.
3. **A scoped connection / repository decorator.** The middleware swaps in a repository (or a
   PDO wrapper) pre-bound to the client id, so scoping is structurally impossible to forget.
   Strongest guarantee; heaviest wiring (every repo needs a scoped variant, and request-scoped
   service rebinding in PHP-DI is fiddly).

**Recommended:** Option 2

**Selected:** Option 2 — request attribute + per-request `ClientContext` holder

**Status:** Decided

**Decision Notes:**
- `Gomrok\Shared\Http\ClientContext` — mutable per-request holder (DI singleton, one instance per
  request like `CorrelationId`): `set(ClientSnapshot $client, ApiKeyPrefix $keyMode)`,
  `client(): ClientSnapshot` (throws if unset — programmer error), `clientId(): int`,
  `keyMode(): ApiKeyPrefix`, `isAuthenticated(): bool`.
- `AuthenticationMiddleware` populates it **and** sets request attributes `authClient`
  (`ClientSnapshot`), `authClientId` (`int`), `authKeyMode` (`string`). The `authClientId`
  attribute is what `IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE` already reads (Phase 5).
- Repositories/query services from later modules take `ClientContext` and apply
  `WHERE client_id = :ctx` themselves — the scope lives in one place per repo, not per call site
  (`TenantIsolation.md`).
- `ClientContext` is only ever populated inside the `/api/v1` group; `/health` and future public
  routes never touch it.

---

### Q1 — How a client presents its API key

**Question:** Which HTTP header(s) carry the `gk_<mode>_<key_id>.<secret>` token, and in what
scheme? (`ApiKeyToken::parse()` from Phase 6 already validates the token string.)

**Options:**

1. **`Authorization: Bearer <token>` only.** One standard scheme. The whole token
   (`gk_live_<key_id>.<secret>`) is the bearer credential; the middleware strips `Bearer `,
   runs `ApiKeyToken::parse()`, rejects anything else. `WWW-Authenticate: Bearer` on 401.
2. **`Authorization: Bearer <token>` **or** `X-Api-Key: <token>`.** Bearer is canonical; the
   `X-Api-Key` alias helps clients whose HTTP stack reserves `Authorization` (some webhook
   senders, some no-code tools). Two code paths, documented precedence (Authorization wins).
3. **Custom split headers — `X-Client-Key-Id: <key_id>` + `X-Client-Key-Secret: <secret>`.**
   Key id and secret sent separately; no token parsing. More header plumbing for the client,
   easier to log the id by mistake, diverges from every SDK convention.

**Recommended:** Option 1

**Selected:** Option 1 — `Authorization: Bearer <token>` only

**Status:** Decided

**Decision Notes:**
- Middleware reads `Authorization`, requires the `Bearer ` scheme (case-insensitive), takes the
  rest as the token, runs `ApiKeyToken::parse()`. Missing header / wrong scheme / unparseable
  token → 401 with `WWW-Authenticate: Bearer realm="gomrok", error="invalid_token"`.
- The `X-Api-Key` alias (option 2) can be added later without breaking clients if a real need
  appears.
- **Public endpoints (user requirement, 2026-09-08):** the auth middleware is scoped to the
  `/api/v1` route group only. `GET /health` stays **outside** it — no `Authorization` required,
  returns `200 {"status":"ok","service":"gomrok"}` and nothing else (no env / DB / secret data),
  does no I/O. Every `/api/v1/*` business endpoint requires the Bearer key.

---

## Phase 6 — Clients module: domain & persistence

### Q5 — How clients are created without an admin UI

**Question:** Phase 6 has no admin panel. How does a client (and its first API key) get into the
database?

**Options:**

1. **A CLI command only** — `bin/CreateClient.php --slug=… --name=… [--currency=EUR]` that runs
   `CreateClient` + issues one API key and prints the key **once**. A matching
   `bin/IssueClientApiKey.php --client=<slug>` and `bin/RevokeClientApiKey.php --key-id=…`.
   Real, uses the same use cases the admin panel will, safe for production onboarding until the
   UI exists. No fixture data committed.
2. **A Phinx seeder only** — `ClientsSeeder` inserts one or more dev clients with
   deterministic slugs and **fixed, known** API-key secrets (dev-only, documented as insecure).
   Zero-friction local/CI setup; but committing known secrets is a footgun and seeders bypass
   the domain use cases.
3. **Both** — the CLI command for real onboarding (option 1) **plus** a seeder that creates a
   single `local-dev` client with a fixed dev key, gated to `APP_ENV in {local, testing}` so it
   never runs in production. Convenient for `composer db:setup` and integration tests, without
   the production risk.

**Recommended:** Option 3

**Selected:** Option 3 — CLI commands + an `APP_ENV`-gated dev seeder

**Status:** Decided

**Decision Notes:**
- CLI (in `bin/`, PascalCase): `CreateClient.php` (`--slug --name [--currency --country
  --timezone]` → `CreateClient` use case + issues one key, prints `gk_…` once + a warning it
  won't be shown again), `IssueClientApiKey.php` (`--client=<slug> [--label]`),
  `RevokeClientApiKey.php` (`--key-id=<key_id>`), `ListClients.php` (slug, name, status, key
  count — no secrets). All resolve services from `ContainerFactory`.
- `ClientsSeeder` (Phinx): creates exactly one client `slug = local-dev` with a **fixed** key
  `gk_test_localdev0000.localdevsecret0000000000000000` (or similar), only when
  `APP_ENV ∈ {local, testing}` — otherwise the seeder is a no-op and logs why. Idempotent
  (`ON DUPLICATE KEY UPDATE` on `slug` / `key_id`).
- The seeder inserts rows directly (documented exception — seeders don't go through use cases),
  but reuses the domain's hashing helper so the stored `secret_hash` matches what auth expects.
- `.env.example` / `Commands.md` document the dev key.

---

### Q4 — Client status lifecycle & what `DisableClient` does

**Question:** What states can a client be in, and what happens to its API keys and in-flight
work when it is disabled?

**Options:**

1. **`active` / `disabled`, disable is soft and reversible; API keys stay as-is but auth checks
   the client status.** `DisableClient` sets `status = disabled` + `disabled_at` / `disabled_by`
   / `disabled_reason`. Keys are not touched (their own `revoked` state is independent); the
   Phase 7 auth middleware rejects any request whose client is `disabled`. Re-enable = `status =
   active`. Simple, auditable, recoverable.
2. **`active` / `disabled`, disable also cascade-revokes every API key.** As option 1 but
   `DisableClient` flips all the client's keys to `revoked` in the same transaction. Re-enabling
   the client does *not* un-revoke them — new keys must be issued. More destructive, cleaner
   "disabled means no access even if status check is bypassed".
3. **`pending` / `active` / `suspended` / `disabled`.** Full lifecycle: `pending` (created, not
   yet usable), `suspended` (temporary, e.g. billing hold), `disabled` (terminal-ish). Most
   expressive; more states to handle everywhere and most aren't needed until there's an
   onboarding flow / billing.

**Recommended:** Option 1

**Selected:** Option 1 — `active` / `disabled`, soft reversible disable, keys untouched

**Status:** Decided

**Decision Notes:**
- `clients.status VARCHAR(20) NOT NULL DEFAULT 'active'` — `active` | `disabled` (app-enforced,
  no MySQL ENUM). `disabled_at DATETIME NULL`, `disabled_by INT UNSIGNED NULL` (admin user id,
  no FK yet — admin users are Phase 26), `disabled_reason VARCHAR(255) NULL`.
- `DisableClient` use case: `active → disabled`, stamps the three columns, emits a
  `ClientDisabled` domain event, writes an `audit_logs` row. Idempotent (disabling a disabled
  client is a no-op success). `EnableClient` (the reverse) also included — clears the three
  columns, `ClientEnabled` event.
- API keys are **not** modified — a key has its own independent `revoked` state. The Phase 7
  auth middleware is the single chokepoint that rejects a request whose client is `disabled`
  (`DomainError::forbidden('client.disabled', …)`).
- `Client::isActive()` gates domain operations that need a live client.

---

### Q3 — Client external identifier (`slug`)

**Question:** Besides the integer `id`, does a client get a human-readable stable identifier?

**Options:**

1. **Yes — a required unique `slug`** (`VARCHAR(64)`, `^[a-z0-9][a-z0-9-]*$`, e.g. `televika`).
   Set at creation, immutable. Used in admin URLs, log lines, config references, CLI
   (`bin/CreateClient.php --slug=televika`), and as the stable key other modules' seed/config
   data refers to. `id` stays the FK everywhere.
2. **No — `id` only.** Simplest. Admin URLs and CLI use the numeric id; a `name` column exists
   for display but isn't constrained unique. Config/seed data that needs to name a client refers
   to it by id (assigned at insert, so fixtures must look it up).
3. **Optional `slug`** — nullable unique. Clients created via CLI/seed get one; any created
   another way may not. Mixed convention, `slug`-or-`id` branching at call sites.

**Recommended:** Option 1

**Selected:** Option 1 — required unique immutable `slug`

**Status:** Decided

**Decision Notes:**
- `clients.slug VARCHAR(64) NOT NULL`, `UNIQUE (slug)`, validated `^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$`
  (or 1-char `^[a-z0-9]$`) in the domain (`ClientSlug` — a small validated value object, not an
  ID type; `id` stays a plain int).
- Immutable after creation — `UpdateClient` cannot change it. `name` (display) is separately
  mutable and not unique.
- Cross-module fixtures/config/tests reference a client by `slug`; runtime FKs use `id`.
- Admin routes: `/admin/clients/{id}` for actions, `slug` shown in listings and logs.

---

### Q2 — Client settings storage shape

**Question:** A client carries assorted configuration — callback/notification URLs, a
notification signing secret, default currency/country, a display timezone, feature toggles.
Provider credentials, pricing, package availability, and country rules are **separate later
modules** and are out of scope here. How are the Phase 6 client settings stored?

**Options:**

1. **A few typed columns on `clients` + a dedicated `client_endpoints` table for URLs.**
   `clients` gets `default_currency CHAR(3)`, `default_country CHAR(2) NULL`, `timezone`,
   `notification_signing_secret` (for signing Gomrok→client callbacks). Callback URLs go in
   `client_endpoints (id, client_id, purpose VARCHAR{payment_status,subscription_status,...},
   url, is_active)` so a client can register several. Everything is a real column — queryable,
   constrained, migration-tracked.
2. **A single `settings JSON` column on `clients`.** All of the above lives in one JSON blob.
   Fewer tables, totally flexible, but nothing is constrained or indexable, and every read/write
   goes through app-side (de)serialisation and validation.
3. **A generic `client_settings (client_id, key, value)` key–value table.** Maximum flexibility,
   no schema churn to add a setting — but values are stringly-typed, multi-setting reads need
   pivoting, and it invites putting real relationships (endpoints) in an EAV bag.

**Recommended:** Option 1

**Selected:** Option 1 — typed columns on `clients` + `client_endpoints` table

**Status:** Decided

**Decision Notes:**
- `clients` scalar settings: `default_currency CHAR(3)` (FK → `currencies.code`),
  `default_country CHAR(2) NULL` (FK → `countries.code`), `timezone VARCHAR(64)` (IANA name,
  default `UTC`), `notification_signing_secret VARCHAR` (used to HMAC-sign Gomrok→client
  callbacks; never logged, redacted in audit).
- `client_endpoints (id, client_id, purpose VARCHAR{payment_status, subscription_status,
  refund_status, ...}, url VARCHAR, is_active TINYINT(1), created_at, updated_at)`. A client may
  register multiple endpoints; `UNIQUE (client_id, purpose)` for now (one active URL per
  purpose) — revisit if fan-out is needed.
- Feature toggles: **not** in Phase 6 — add when a real toggle exists (avoid speculative
  columns).
- `client_endpoints` gets `fk_client_endpoints_client_id → clients(id) ON DELETE CASCADE`.

---

### Q1 — API-key format, hashing, and lookup

**Question:** How is a client API key generated, stored, and matched on an incoming request?
(`CLAUDE.md` → *Admin security* / *Security Rules*: "Store only password hashes", "Store session
tokens hashed if stored in database", "Do not log … full API keys".)

**Options:**

1. **Prefixed token + SHA-256 of the secret, looked up by a stored non-secret lookup id.**
   Key shown once as `gk_live_<keyId>_<secret>` (or header `Authorization: Bearer <secret>` plus
   a `X-Client-Key-Id`). Store `key_id` (public, unique, indexed), `secret_hash =
   sha256(secret)`, `prefix` (`gk_live` / `gk_test`), `last_four`, `label`, `status`,
   `created_at`, `last_used_at`, `expires_at?`, `revoked_at?`. Auth: parse `key_id` → single
   indexed row fetch → `hash_equals(sha256(presented), stored)`. Fast (one indexed lookup),
   constant-time compare, nothing reversible stored.
2. **Opaque token + SHA-256, looked up directly by the hash.** Store only `secret_hash`
   (unique-indexed) + metadata. Auth: `sha256(presented)` → indexed lookup. Simplest; no key-id
   plumbing; but the whole token is one blob, rotation/labelling still fine, and a hash index is
   effectively a lookup key anyway.
3. **Password-hash the secret (bcrypt / Argon2id), looked up by a separate `key_id`.** Like
   option 1 but `password_hash()` instead of SHA-256. Slow by design (good against offline
   cracking of a *stolen DB*), but API keys are already 256-bit random so brute-force isn't the
   threat, and every request pays the KDF cost. Needs the `key_id` because you can't index a
   bcrypt hash.

**Recommended:** Option 1

**Selected:** Option 1 — prefixed token + SHA-256, looked up by a public `key_id`

**Status:** Decided

**Decision Notes:**
- `client_api_keys (id, client_id, key_id CHAR(x) UNIQUE, secret_hash CHAR(64), prefix
  VARCHAR{gk_live,gk_test}, last_four CHAR(4), label VARCHAR, status VARCHAR{active,revoked},
  created_at, last_used_at NULL, expires_at NULL, revoked_at NULL, revoked_by NULL)`.
- Presented as `gk_live_<key_id>.<secret>` (shown **once** at creation). Auth middleware (Phase 7)
  splits on `.`, point-reads by `key_id`, then `hash_equals(hash('sha256', $presented), $stored)`.
- `key_id` is safe to log / show in the admin panel; the secret and `secret_hash` are never
  logged. `last_four` + `prefix` give the admin UI a display string.
- `secret` = 32 bytes from `random_bytes`, base62/base64url encoded. `key_id` = shorter random
  token (e.g. 16 hex / 12 base62), unique.
- Revocation is a status flip + `revoked_at`/`revoked_by`; rows are never deleted (audit trail).
- Exact column widths finalised in the schema proposal after Q5.

---

## Phase 5 — Migration workflow & cross-cutting tables

### Q5 — Migration-workflow hardening scope

**Question:** How far does "harden the migration workflow" go in this phase?

**Options:**

1. **Round-trip test + `db:reset` only** — a `tests/Integration` test that migrates up, asserts
   the schema, rolls every migration back down, and asserts a clean database (self-skips without
   MySQL like the others). Add `composer db:reset` (rollback-all → migrate → seed) and
   `composer db:fresh`. No CI changes.
2. **Option 1 + a GitHub Actions workflow** — as (1), plus `.github/workflows/Ci.yml` that spins
   up a `mysql:8.4` service container and runs `composer ci:full` (cs + stan + unit +
   integration) and `composer db:setup` on every push / PR. This is the first thing that will
   actually *execute* the migrations, since there's no local DB here.
3. **Option 2 + a seed/schema separation refactor** — additionally split "schema migrations"
   from "reference-data seeding" more formally (e.g. a `--environment` guard so seeders never
   run in `production` unintentionally, a documented `schema-only` vs `with-seed` setup path).

**Recommended:** Option 2

**Selected:** Option 2 — round-trip test + `db:reset` + GitHub Actions CI

**Status:** Decided

**Decision Notes:**
- `tests/Integration/MigrationRoundTripTest.php` — migrate up, assert the four Phase-4/5 tables
  exist with expected columns, roll every migration down, assert zero app tables remain (only
  `phinxlog`). Self-skips without MySQL, same guard as the other integration tests.
- `composer db:reset` = `rollback -t 0` → `migrate` → `seed`; `composer db:fresh` = same without
  seed. Documented in `Commands.md`.
- `.github/workflows/Ci.yml` — triggers on push + pull_request. `mysql:8.4` service container
  (health-checked), PHP 8.4 with required extensions, `composer install`, then `composer ci`
  (cs + stan + unit) and `composer ci:full` path: `composer db:setup` + `composer
  test:integration`. Env for the DB user/password/host provided as workflow env vars matching
  `.env.example`.
- This is the first execution of the migrations against real MySQL — the "NOT verified" caveat
  from Phase 4 is discharged by CI going green, not by local runs here.
- No seed/schema separation refactor this phase (Option 3 deferred — revisit at deployment).

---

### Q4 — Idempotency-key retention

**Question:** How long does an `idempotency_keys` row live, and how is it cleared?

**Options:**

1. **`expires_at` + a cleanup job** — every row gets `expires_at = created_at + TTL`
   (recommend 24h). Replay after expiry is treated as a fresh request. A background job
   (`PurgeExpiredIdempotencyKeys`) deletes expired rows on a schedule. Table stays small.
2. **`expires_at`, lazy cleanup only** — same TTL semantics, but no scheduled job; expired rows
   are deleted opportunistically when the same key is seen again, plus an occasional manual
   `DELETE`. Simpler now, table grows unbounded between hits.
3. **Keep forever** — no `expires_at`; rows are permanent, effectively a full history of every
   client write request. Simplest logic, unbounded growth, replay always honoured.

**Recommended:** Option 1

**Selected:** Option 1 — `expires_at` + cleanup job

**Status:** Decided

**Decision Notes:**
- `idempotency_keys.expires_at DATETIME NOT NULL`, set to `created_at + 24h` (TTL constant in
  the middleware, not hard-coded per row logic).
- A key whose `expires_at` is in the past is ignored on lookup (treated as absent) even before
  the purge deletes it — so correctness never depends on the job running.
- `Gomrok\Jobs\PurgeExpiredIdempotencyKeys` — a plain invokable class,
  `DELETE FROM idempotency_keys WHERE expires_at < :now`. No job-runner dependency; it's just a
  class + a `composer idempotency:purge` script (runs it via a tiny CLI entrypoint
  `src/Jobs/bin/purge-idempotency-keys.php`). Wired into the real scheduler when the job runner
  lands (later phase).
- Same pattern is reusable for later TTL purges (voucher reservations, expired payments).

---

### Q3 — Error-log capture mechanism

**Question:** How do rows get into `error_logs` (the table behind the Phase 27 admin *Error
Logs* screen)?

**Options:**

1. **Explicit writer only** — an `ErrorLogWriter` port called deliberately from the
   `JsonErrorHandler` (unhandled throwables) and from infra adapters at known failure points
   (provider timeout, webhook verification failure, notification delivery failure). Full control
   over what lands there; nothing incidental.
2. **Monolog DB handler** — a custom Monolog handler writes every record at/above a threshold
   (e.g. `ERROR`) to `error_logs`. Zero call sites, but couples the table to log volume and can
   flood on a noisy dependency.
3. **Both** — the Monolog handler is the default net (threshold `ERROR`), and the explicit
   writer adds structured domain context (client_id, payment_id, provider, correlation_id) for
   the failures that matter operationally.

**Recommended:** Option 1

**Selected:** Option 1 — explicit writer only

**Status:** Decided

**Decision Notes:**
- `Gomrok\Shared\Application\ErrorLog\ErrorLogWriter` port + a MySQL adapter
  (`Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogWriter`). Domain/application code never
  touches PDO directly.
- `error_logs (id, level VARCHAR{error,critical}, source VARCHAR (e.g. http, webhook, provider,
  notification, job), message TEXT, exception_class VARCHAR NULL, code VARCHAR NULL,
  client_id INT UNSIGNED NULL, correlation_id VARCHAR NULL, context JSON NULL,
  stack_trace MEDIUMTEXT NULL, created_at DATETIME, resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL)`.
- Call sites in Phase 5: `JsonErrorHandler` (unhandled throwables → 500 path). Provider /
  webhook / notification call sites are wired in their own later phases (each phase adds its
  `ErrorLogWriter` calls).
- `resolved_at` / `resolved_by` support the Phase 27 admin "mark resolved" action; nullable, set
  only from the admin panel.
- Writing an error-log row must never throw out of the error handler — the adapter swallows its
  own failures (logs to Monolog stderr) so logging can't mask the original error.
- Indexes: `idx_error_logs_created (created_at)`, `idx_error_logs_client (client_id)`,
  `idx_error_logs_unresolved (resolved_at)`.

---

### Q2 — Audit-log content model

**Question:** What does a row in `audit_logs` capture? (`CLAUDE.md` → admin security: "audit
logs for sensitive admin actions … provider config changes, secret rotation, refund actions,
retries, pricing changes, voucher changes, country override changes, permission changes".)

**Options:**

1. **Event record only** — `(id, actor_type, actor_id, client_id NULL, action, target_type,
   target_id, context JSON, correlation_id, ip, user_agent, created_at)`. `action` is a verb
   string (`payment.refunded`, `provider_config.updated`). `context` holds a free-form summary.
   No before/after values.
2. **Event + value diff** — as (1) plus `old_values JSON` / `new_values JSON` capturing the
   changed columns of the target. Answers "what exactly changed" for pricing/voucher/config
   edits without re-deriving from other logs.
3. **Event + full snapshots** — as (1) plus a complete JSON snapshot of the target row before
   and after. Heaviest; most storage; rarely need the untouched fields.

**Recommended:** Option 2

**Selected:** Option 3 — event + full before/after row snapshots

**Status:** Decided

**Decision Notes:**
- `audit_logs (id, actor_type VARCHAR{admin_user,client,system}, actor_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL, action VARCHAR, target_type VARCHAR NULL, target_id INT UNSIGNED
  NULL, before JSON NULL, after JSON NULL, context JSON NULL, correlation_id VARCHAR NULL,
  ip VARCHAR(45) NULL, user_agent VARCHAR NULL, created_at DATETIME)`.
- `before` / `after` hold the **full** target row as JSON (whole row, not just changed columns).
  On create `before` is NULL; on delete `after` is NULL.
- Secret-bearing columns are redacted before serialisation (never store raw provider secrets or
  full API keys in the audit JSON — `CLAUDE.md` security rules).
- Indexes: `idx_audit_logs_client_created (client_id, created_at)`,
  `idx_audit_logs_target (target_type, target_id)`, `idx_audit_logs_action (action)`.
- Append-only from the app side; no `updated_at`.

---

### Q1 — Idempotency-key storage model

**Question:** What does `idempotency_keys` store, and how does replay work? (Client write
requests carry an `Idempotency-Key` header — `CLAUDE.md` → *Idempotency Rules*.)

**Options:**

1. **Full replay (Stripe-style)** — `(id, client_id, idempotency_key, request_fingerprint,
   response_status, response_body, locked_at, completed_at, created_at, expires_at)`. Same key +
   same request → return the stored response verbatim. Same key + different request body →
   `422`. In-flight (locked, not completed) → `409`. Stores response bodies.
2. **Entity mapping** — `(id, client_id, idempotency_key, target_type, target_id, created_at,
   expires_at)`. The key records *which entity* the request created; on replay, look the entity
   up and return its **current** state (re-serialised), not a frozen snapshot. No response
   bodies stored.
3. **Lock only** — `(id, client_id, idempotency_key, status, target_type, target_id, created_at,
   expires_at)`. Prevents concurrent double-processing; `processing` → `409`, `done` → return
   the referenced entity's current state. Essentially (2) plus an explicit in-flight lock.

**Recommended:** Option 3

**Selected:** Option 3 — lock + entity mapping

**Status:** Decided

**Decision Notes:**
- `idempotency_keys (id, client_id, idempotency_key, request_fingerprint, status
  ENUM-as-VARCHAR{processing,done,failed}, target_type, target_id, response_status, created_at,
  updated_at, expires_at)`. `UNIQUE (client_id, idempotency_key)`.
- `request_fingerprint` = hash of method + path + body; on replay with the same key but a
  different fingerprint → `422` (key reuse for a different request). Same key + `processing` →
  `409`. Same key + `done` → re-serialise `target_type`/`target_id` and return with the stored
  `response_status`. `failed` → allow a fresh attempt (row reset).
- No response **bodies** stored — replay returns the referenced entity's current state.
- Middleware: claim the key (`INSERT … status=processing`) before the handler, mark `done`
  (+ `target_type/target_id/response_status`) after a successful write, `failed` on a thrown
  error. Retention handled in Q4.

---

## Phase 4 — Database foundations: base & reference tables only

### Q5 — Seed scope: how many countries / currencies

**Question:** How much reference data goes in?

**Options:**

1. **Full ISO lists** — all ~180 currencies (brick/money's whole ISO set) and ~249 countries in
   `countries.json`. Complete; some rows will never be used.
2. **Curated to Gomrok's markets** — currencies and countries limited to the regions
   `CLAUDE.md` names (Turkey, the Gulf states, the EU/EEA, the UK, the US, plus a handful of
   common others). ~15–20 countries, ~15 currencies. Add rows later as markets open.
3. **Full currencies (from brick — it's free), curated countries** — every ISO currency (the
   list is authoritative and small effort since it comes from the library), but only the ~15–20
   countries we actually operate in.

**Recommended:** Option 3

**Selected:** Option 3 — full ISO currencies, curated countries

**Status:** Decided

**Decision Notes:**
- `currencies`: every currency from `Brick\Money\ISOCurrencyProvider` (~180 rows).
- `countries`: the `CLAUDE.md`-named markets — Turkey (TR), the US (US), the UK (GB), and the
  EU/EEA + a few Gulf states: DE, FR, NL, IT, ES, IE, BE, AT, PT, SE, DK, FI, PL, AE, SA (~18
  rows). New markets are added later via a small follow-up migration/seed, not by editing this
  one.
- Exact `countries.json` contents are part of the schema proposal (next).

---

### Q4 — The "capability catalogue" table: now or later

**Question:** `Phases.md` lists a "capability catalogue" among Phase 4's reference tables. This
is the set of provider-capability flags from `Architecture.md` §8 (`one_time_payment`,
`hosted_checkout`, `subscription`, `refund`, `three_d_secure`, `manual_status_polling`, …).

**Options:**

1. **Create `provider_capabilities` now** — a reference table (`id`, `code`, `label`,
   `description`) seeded with the ~20 flags. Later phases FK to it and add
   `provider_type_capabilities` / per-account overrides.
2. **Defer to Phase 8** (Providers module: types & capability model) — Phase 4 ships only
   `countries`, `currencies`, `provider_types`. Capabilities become a PHP enum
   (`Gomrok\Modules\Providers\Domain\Capability`) plus whatever tables Phase 8 designs.
3. **Enum now + table now** — the enum is the source of truth; the seeded table exists for
   admin display / FK integrity and is kept in sync with the enum by the seeder.

**Recommended:** Option 2

**Selected:** Option 2 — defer the capability catalogue to Phase 8

**Status:** Decided

**Decision Notes:** Phase 4 ships exactly three reference tables: `countries`, `currencies`,
`provider_types`. The provider-capability model (enum and/or tables) is designed in Phase 8
(Providers module). `Phases.md` Phase 4 scope updated to drop "capability catalogue"; Phase 8
scope gains it. Minor scope narrowing, confirmed by the user per `Rule.md` §4.2.

---

### Q3 — Reference-data source for seeding

**Question:** Where do the rows for `countries` and `currencies` come from at seed time?

**Options:**

1. **Bundled data files** — small hand-maintained JSON/CSV in `src/Database/Seeds/data/`
   (`countries.json`, currencies from a file too). No runtime dependency; fully in our control;
   we curate exactly the rows we want.
2. **Libraries** — `symfony/intl` (countries + currencies + names + localisation) or
   `league/iso3166` (countries). Always-complete ISO lists, one dependency each; names come
   localised.
3. **Mixed** — currencies seeded from **`brick/money`'s `ISOCurrencyProvider`** (already a
   dependency — gives code, numeric code, name, default fraction digits); countries from a
   bundled `countries.json` (option 1) since no current dependency lists them.

**Recommended:** Option 3

**Selected:** Option 3 — currencies from `brick/money`, countries from a bundled JSON

**Status:** Decided

**Decision Notes:**
- `currencies` seeder reads `Brick\Money\ISOCurrencyProvider::getInstance()->getAvailableCurrencies()`
  → per row: `code` (CHAR 3), `numeric_code` (SMALLINT), `name`, `minor_unit_scale` (TINYINT).
  Same source `Currency`/`Money` validate against — table can't drift from code.
- `countries` seeder reads `src/Database/Seeds/data/countries.json` — a file we maintain:
  `code` (CHAR 2, ISO 3166-1 alpha-2), `name`, `default_currency` (CHAR 3, FK-ish to currencies).
- Seed scope decided in Q5.

---

### Q2 — Phinx migration structure

**Question:** How are migration classes organised? (Phinx chosen — Phase 2 Q3.)

**Options:**

1. **Phinx default** — `src/Database/Migrations/YYYYMMDDHHMMSS_snake_name.php`, no namespace,
   classes extend `Phinx\Migration\AbstractMigration`, `change()`/`up()`+`down()`.
2. **Namespaced** — `Gomrok\Database\Migrations\` (PSR-4, so file/class names satisfy our
   PascalCase rule), still extending `AbstractMigration` directly.
3. **Namespaced + a thin base class** — as (2), plus
   `Gomrok\Database\Migration extends AbstractMigration` with helpers that encode the
   `DatabaseAgent.md` conventions: `refTable()` (adds the `id INT UNSIGNED AUTO_INCREMENT` PK),
   `timestamps()` (`created_at`/`updated_at`), a `clientId()` column helper, InnoDB/utf8mb4
   defaults. Every migration extends it.

**Recommended:** Option 3

**Selected:** Option 2 — namespaced, no base class

**Status:** Decided

**Decision Notes:**
- `phinx.php` → `paths.migrations` maps `Gomrok\Database\Migrations` →
  `src/Database/Migrations`; seeds similarly (`Gomrok\Database\Seeds`). Autoload is already
  covered by the `Gomrok\` → `src/` PSR-4 entry.
- Migration **classes** are namespaced PascalCase (`Gomrok\Database\Migrations\CreateCountriesTable`),
  extend `Phinx\Migration\AbstractMigration`, and use `up()` + `down()` (not `change()`) so
  rollback is always explicit.
- Migration **file names** keep Phinx's required `YYYYMMDDHHMMSS_snake_name.php` form (needed for
  `version_order: creation`) — a new `Rule.md` §3.1 exception, like `phinx.php` itself.
- The `DatabaseAgent.md` conventions (`id INT UNSIGNED AUTO_INCREMENT`, InnoDB/utf8mb4, FK/index
  naming, etc.) are followed **by hand** in each migration — no shared base class. A base class
  can still be added later without disrupting existing migrations if the repetition bites.

---

### Q1 — Database documentation: how many files, what format

**Question:** The inherited spec (`CLAUDE.md` → *Database Diagram Maintenance Rule*) wants
`.claude/docs/database-design.md` (canonical), `database-diagram.md` (+ `.html`), `db_explain.md`,
and a root `mkdocs.yml` — three kebab-case Markdown files kept byte-identical in shape, plus an
mkdocs site. `Rule.md` §3.3 flagged the exact names as a Phase 4 decision.

**Options:**

1. **One file: `.claude/docs/Database.md`** — canonical spec + inline Mermaid ER diagram(s) +
   per-table notes + examples, all in one PascalCase file (same style as `Architecture.md`).
   `Rule.md` §5's "keep three files identical" rule collapses to "keep one file current". Drop
   `mkdocs.yml` and the standalone `.html` until there's a reason for a rendered site.
2. **Three PascalCase files** — `DatabaseDesign.md`, `DatabaseDiagram.md` (+ `.html`),
   `DbExplain.md` under `.claude/docs/`. Matches the spec's structure, renamed to our convention.
   Still the 3-file sync burden.
3. **The spec verbatim** — kebab-case `database-design.md` / `database-diagram.md` (+ `.html`) /
   `db_explain.md` + `mkdocs.yml`. Overrides `Rule.md` §3.1 for these files.

**Recommended:** Option 1

**Selected:** Option 3 — the spec verbatim (kebab-case DB docs + `mkdocs.yml`)

**Status:** Decided

**Decision Notes:** Files: `.claude/docs/database-design.md` (canonical spec),
`.claude/docs/database-diagram.md` (Mermaid ER per module + module map),
`.claude/docs/database-diagram.html` (standalone page, Mermaid via CDN — reads live from the
`.md` when served over HTTP; embedded fallback snapshot + `#catData` JSON for `file://`), and
`.claude/docs/db_explain.md` (per-table guide). Root `mkdocs.yml` (Material theme, Mermaid via
`pymdownx.superfences`). All four must stay in lock-step per `Rule.md` §5. `Rule.md` §3.1 gains
an exception entry for these five names; §3.3 note resolved; ## Project Documents + §7 get rows.

---

## Phase 3 — Shared kernel

### Q5 — Clock abstraction

**Question:** How does the domain get "now"? (Needed for timestamps, voucher validity windows,
subscription periods, retry backoff — and must be freezable in tests.)

**Options:**

1. **PSR-20 `Psr\Clock\ClockInterface`** — add `psr/clock`; `Shared\Infrastructure\SystemClock`
   returns `new DateTimeImmutable('now')`; tests use a `FrozenClock` (in `tests/` or a shared
   test double). Standard, one tiny interface-only dependency.
2. **Custom `Shared\Domain\Clock` interface** — our own one-method interface + `SystemClock` +
   `FrozenClock`. No dependency; not the ecosystem standard.
3. **No abstraction** — call `new DateTimeImmutable()` directly in domain code. Simple but
   untestable time logic.

**Recommended:** Option 1

**Selected:** Option 1 — PSR-20 `Psr\Clock\ClockInterface`

**Status:** Decided

**Decision Notes:** Add `psr/clock ^1`. `Shared\Infrastructure\SystemClock implements
Psr\Clock\ClockInterface` (`now(): DateTimeImmutable` in UTC). `tests/Support/FrozenClock.php`
for deterministic time in tests. Domain/application code type-hints `ClockInterface`.

---

### Q4 — Logging library

**Question:** What backs the structured JSON logger (must carry `correlation_id`, `client_id`,
`payment_id`, `provider`, … on every payment/subscription-flow line — `CLAUDE.md` → *Logging and
Observability*)?

**Options:**

1. **`monolog/monolog`** (PSR-3) — a `JsonFormatter` handler + a processor that injects the
   correlation-id / request context; `StreamHandler` to `stdout`. One well-known dependency.
   The domain depends only on PSR-3 `LoggerInterface`.
2. **Hand-rolled PSR-3 logger** — implement `Psr\Log\LoggerInterface` ourselves with a tiny
   JSON writer + context merger. Zero logging dependency; ~1 small class + a formatter.
3. **Custom non-PSR logger interface** — our own `Logger` contract, our own impl.

**Recommended:** Option 1

**Selected:** Option 1 — `monolog/monolog`

**Status:** Decided

**Decision Notes:** Add `monolog/monolog ^3` to `composer.json`. `Shared\Infrastructure`:
a factory that builds a `Monolog\Logger` with `JsonFormatter` on a `StreamHandler` → `stdout`,
plus a `CorrelationIdProcessor` that reads the current request's correlation id (set by the
request-id middleware) and merges it + static context (`service`, `env`) into every record.
The container binds `Psr\Log\LoggerInterface` → that logger; app code depends only on PSR-3.

---

### Q3 — How use cases signal failure

**Question:** How do application use cases report an *expected* failure (validation error,
"package not available in this country", "voucher expired", …) versus a genuine bug?

**Options:**

1. **`Result` type** — `Result::ok($value)` / `Result::err(DomainError)`. Use cases return a
   `Result`; expected failures are `DomainError` values, never thrown. Exceptions are reserved
   for bugs / unrecoverable infra faults. (This is what `Architecture.md` §11 already says.)
2. **Typed domain exceptions** — `throw new PackageNotAvailable(...)`; a single handler at the
   HTTP boundary maps exception → response. Familiar; control flow via exceptions.
3. **Hybrid** — `Result` for validation / business-rule failures, exceptions for
   infra/unexpected.

**Recommended:** Option 1

**Selected:** Option 3 — Hybrid

**Status:** Decided

**Decision Notes:** The boundary (so "when is it hybrid?" is not a per-call judgment):

- **Return `Result<T>` (never throw)** for anything a well-behaved caller must branch on:
  input validation, business-rule violations, eligibility / capability rejections,
  "not found" for a resource the caller named, voucher invalid/expired/exhausted, unsupported
  provider·country·method combination, idempotency conflict resolved to a prior result.
  Carried as `DomainError` (a code + message + context).
- **Throw** for programmer errors (broken invariant, illegal state), configuration errors, and
  infrastructure/transport faults (DB unavailable, provider timeout/5xx, malformed provider
  response). Provider/network faults first go through retry-with-backoff; if still failing they
  surface as an exception.
- The HTTP boundary (Phase 3 `Shared\Http` error handler) maps: `DomainError` → 4xx problem
  response (per its code); uncaught `Throwable` → 500/502, logged with stack trace + correlation
  id.
- `Architecture.md` §11 updated from "Domain returns `Result`/`DomainError`" to this hybrid.

---

### Q2 — Typed entity IDs

**Question:** How are entity identifiers modelled in the domain layer?

**Options:**

1. **Generic `Ulid` only** — `Shared\Domain\Ulid` value object; every entity just holds a
   `Ulid`. No per-entity ID type. Simplest; `PaymentId` and `ClientId` are interchangeable at
   the type level.
2. **`Shared` ships an abstract `EntityId` base; modules subclass it** — `Ulid` +
   `abstract EntityId` (wraps a `Ulid`) in Phase 3; each module adds
   `final class PaymentId extends EntityId {}` in its own phase. Compile-time safety
   (`PaymentId` ≠ `ClientId`), one line per ID type.
3. **Per-entity ID classes with no shared base** — each module hand-rolls its ID VO. Most
   boilerplate, no shared behaviour.

**Recommended:** Option 2

**Selected:** **None of the above — no ID abstraction at all.** Entity identifiers are plain
`int` in PHP, matching the `INT AUTO_INCREMENT` primary keys (see Phase 1 Q3, changed 2026-09-08).

**Status:** Decided

**Decision Notes:** User directive: "Do not use ULID, UUID, typed ID value objects, or
entity-specific ID classes … I do not want unnecessarily complex ID abstractions." So:
- No `Shared\Domain\Ulid`, no `EntityId` base, no `PaymentId` / `ClientId` classes.
- Domain entities and repositories take/return `int` for identity.
- Phase 3's Shared kernel therefore ships **no** identifier code (dropped `Ulid` + `UlidGenerator`
  from the plan). The per-request **correlation id** for logging is unrelated — it stays, as a
  plain random hex string, not a ULID.

---

### Q1 — Money value object API

**Question:** What surface does `Shared\Domain\Money` expose (wrapping `brick/money`)?

**Options:**

1. **Minimal, opinionated** — `fromMinor(int, Currency)`, `toMinor()`, `zero(Currency)`,
   `plus`/`minus`/`multipliedBy`/`allocate`, `equals`, `isZero`/`isPositive`/`isNegative`,
   `currency()`. Rounding mode fixed internally (`HALF_EVEN` / banker's). No formatting, no FX.
2. **Richer** — everything in (1) plus `percentage()`, `ratioOf()`, `format(locale)`,
   `convertTo(Currency, rate)`.
3. **Thin alias** — `Money` is just a type alias / trivial subclass of `Brick\Money\Money`;
   the library API is the domain API.

**Recommended:** Option 1

**Selected:** Option 2 — richer API

**Status:** Decided

**Decision Notes:** Beyond the minimal set: `percentage()`, `ratioOf()`, `format(locale)`,
`convertTo(Currency, rate)`. `convertTo` takes an explicit rate — the caller / Pricing module
supplies it; `Money` never fetches rates. `format()` uses `ext-intl`. Rounding fixed to
`HALF_EVEN` internally.

---

## Phase 2 — Project scaffold & toolchain

### Q6 — Home for app-level (non-module) code

**Question:** `src/Bootstrap/` (composition root) and `src/Http/` (endpoints owned by no module,
e.g. `/health`) were created during scaffolding but are not in the `.claude/docs/Architecture.md`
§4 folder sketch. Where should they live? *(Raised mid-phase per Rule.md §4.2.)*

**Options:**

1. **Keep them as top-level app dirs** — `src/Bootstrap/` and `src/Http/` sit alongside
   `Modules/`, `Shared/`, `Config/`, …; `Architecture.md` §4 is updated to list them.
2. **Fold into `src/Shared/`** — `AppFactory` → `src/Shared/Bootstrap/`,
   `HealthAction` → `src/Shared/Http/`; keeps the top-level `src/` list exactly as sketched.

**Recommended:** Option 1

**Selected:** Option 1 — keep `src/Bootstrap/` and `src/Http/` as top-level app dirs

**Status:** Decided

**Decision Notes:** The composition root and non-module endpoints must live somewhere; `Shared/`
is reserved for the Phase 3 domain kernel. `Architecture.md` §4 updated to list both, with a
one-line rationale.

---

### Q5 — Static analysis + code style

**Question:** What static-analysis and code-style tooling, and how strict?

**Options:**

1. **PHPStan (max) + php-cs-fixer (PSR-12)** — PHPStan level `max` with
   `phpstan/phpstan-strict-rules` and a phpunit extension; php-cs-fixer with a `@PSR12` +
   light extras ruleset. Both run in `composer` scripts and CI.
2. **Psalm (level 1) + php-cs-fixer** — Psalm instead of PHPStan; strong type-flow analysis,
   taint analysis available, but a smaller plugin ecosystem and slower-moving lately.
3. **Both PHPStan and Psalm + php-cs-fixer** — maximum coverage, double the config and CI time,
   occasional contradictory findings.
4. **PHPStan (level 6, ratcheting up) + php-cs-fixer** — start mid-level with a baseline, raise
   the level as the codebase grows.

**Recommended:** Option 1

**Selected:** Option 1 — PHPStan (max) + strict-rules + php-cs-fixer (PSR-12)

**Status:** Decided

**Decision Notes:** PHPStan level `max` from the first commit with `phpstan/phpstan-strict-rules`
and `phpstan/phpstan-phpunit`; php-cs-fixer `@PSR12` + light extras. Both in `composer` scripts
and CI. No baseline — new code must pass clean.

---

### Q4 — Test framework

**Question:** Which test framework?

**Options:**

1. **PHPUnit 11** — the standard; xUnit-style classes, data providers, `#[Test]` attributes.
   Every static-analysis tool, IDE, and CI integration targets it first. `Unit/` vs
   `Integration/` split via test suites in `phpunit.xml`.
2. **Pest 3** — expressive closure-based syntax on top of PHPUnit; nice DX, arch-testing plugin,
   parallel by default. Adds a layer; some tooling/stack-traces are less direct.

**Recommended:** Option 1

**Selected:** Option 1 — PHPUnit 11

**Status:** Decided

**Decision Notes:** `Unit` vs `Integration` split via `<testsuite>`s in `phpunit.xml`; first-class
support in PHPStan, IDEs, Phinx, PHP-DI.

---

### Q3 — Database migration tool

**Question:** What runs and tracks schema migrations?

**Options:**

1. **robmorgan/phinx** — standalone, framework-agnostic; PHP migration classes with
   `up()`/`down()` or the change API, a versions table, `phinx migrate` / `rollback` / `status`,
   plus seeders. Mature, widely used.
2. **doctrine/migrations** — powerful, diff-generation if paired with the Doctrine schema tools;
   heavier, pulls in more Doctrine packages, oriented toward Doctrine DBAL.
3. **A thin custom runner** — a small `Database/` script that applies ordered `*.sql` (or PHP)
   files and records them in a `migrations` table. Zero dependency, but we maintain it.
4. **laravel/framework's migrator via a bridge** — familiar API, but drags Illuminate packages
   into a Slim app.

**Recommended:** Option 1

**Selected:** Option 1 — robmorgan/phinx

**Status:** Decided

**Decision Notes:** Matches the plain-PDO / own-repositories stack (no ORM), manages DDL + a
versions table without imposing a schema abstraction, and its seeders cover the Phase 4/5
reference data (`countries`, `currencies`, capability catalogue).

---

### Q2 — PHP version target

**Question:** Which PHP version does the project target?

**Options:**

1. **PHP 8.4** (current stable) — newest language features (property hooks, asymmetric
   visibility, `new` in initializers everywhere), longest support runway.
2. **PHP 8.3** — one version back; broadest hosting/CI/library compatibility, still supported,
   fewer "too new" surprises.
3. **PHP 8.2** — conservative; supported until end of 2026 only.

**Recommended:** Option 1

**Selected:** Option 1 — PHP 8.4

**Status:** Decided

**Decision Notes:** Greenfield, runtime controlled via Docker; Slim 4 / PHP-DI / brick/money /
PHPUnit 11 all support 8.4. `composer.json` `"require": {"php": "~8.4.0"}`, Docker base
`php:8.4`.

---

### Q1 — Local development environment

**Question:** How is the local dev environment provided?

**Options:**

1. **Docker Compose** — `docker-compose.yml` with `php` (8.x CLI/FPM), `mysql:8`, and a web
   entry (nginx or `php -S`). One `docker compose up` gets a new dev/CI running identically.
2. **Documented bare-metal** — no containers; a `.claude/docs/Commands.md` documents the required
   PHP extensions, a local MySQL, and the setup steps. Lightest, but "works on my machine" risk.
3. **DDEV** — opinionated Docker wrapper for PHP apps; `ddev config` + `ddev start`. Least
   boilerplate, adds a tool dependency.

**Recommended:** Option 1

**Selected:** Option 1 — Docker Compose

**Status:** Decided

**Decision Notes:** `docker-compose.yml` will pin PHP 8.x + required extensions (bcmath/gmp,
pdo_mysql, intl) and `mysql:8`, so clone-and-run and CI are deterministic and the Phase 4+
migration/integration tests get a real MySQL.

---

## Phase 1 — Groundwork: architecture baseline

### Q5 — Provider-adapter interface shape

**Question:** What shape for the provider-adapter interface?

**Options:**

1. **Hybrid: required core port + optional capability interfaces** — every adapter implements a
   core `PaymentProviderPort` (create payment, get status, verify/parse webhook,
   `getCapabilities`); extra abilities are separate interfaces (`SupportsSubscriptions`,
   `SupportsRefunds`, `SupportsAuthCapture`, `SupportsCustomerPortal`, `SupportsManualPolling`)
   implemented only where real. A `ProviderCapabilities` descriptor drives runtime gating.
2. **One fat `PaymentProviderPort`, throws for unsupported** — every method on one interface;
   unsupported operations throw `UnsupportedCapabilityException`. Runtime landmine, not a
   type-level fact.
3. **Fully segregated ports (ISP)** — no required core; `OneTimePaymentPort`, `SubscriptionPort`,
   `RefundPort`, `WebhookVerifierPort`, … each implemented selectively. Most interfaces / DI
   wiring; "every provider does X" still enforced by convention.

**Recommended:** Option 1

**Selected:** Option 1 — Hybrid: required core port + optional capability interfaces

**Status:** Decided

**Decision Notes:** `ZiraatAdapter` simply doesn't implement `SupportsSubscriptions`, so
"subscribe via Ziraat" is impossible at the type level. `.claude/docs/Architecture.md` §8.

---

### Q4 — Money representation

**Question:** How are monetary amounts represented?

**Options:**

1. **`brick/money` wrapped in our own `Money` VO** — library handles rounding modes, per-currency
   scale (JPY 0dp / USD 2dp / BHD 3dp), allocation for splits, overflow; our `Shared\Domain\Money`
   VO is the only thing the domain sees. Store `amount_minor BIGINT` + `currency CHAR(3)`.
2. **Custom `Money` VO + bcmath, no dependency** — reimplement rounding/scale/allocation
   ourselves; easy to get subtly wrong.
3. **DECIMAL columns + decimal strings** — no Money type; invites precision drift and scattered
   formatting.

**Recommended:** Option 1

**Selected:** Option 1 — `brick/money` wrapped in our own `Money` VO

**Status:** Decided

**Decision Notes:** Domain depends on our `Money`, never on `brick`. `.claude/docs/Architecture.md` §7.

---

### Q3 — Identifier strategy

**Question:** What identifier strategy for database rows?

**Options:**

1. **BIGINT auto-increment PK + public ULID column** — internal PK/FK are `BIGINT` (tight InnoDB
   clustered index, fast joins); every externally-visible row also carries a unique
   `ulid CHAR(26)` used in APIs, callbacks, and admin URLs.
2. **ULID (or UUIDv7) as the primary key everywhere** — one id per row, sortable, safe to
   expose; ~2× PK/index storage and more write amplification at payment volume.
3. **UUIDv4 primary keys everywhere** — random insert order fragments the InnoDB clustered
   index; worst option for a high-write payments table.

**Recommended:** Option 1

**Previously Selected:** Option 1 — BIGINT auto-increment PK + public ULID column
**Current Selection:** **Simple `INT AUTO_INCREMENT` primary keys, no ULID, no typed IDs.**
**Changed:** 2026-09-08
**Reason:** User wants plain numeric IDs everywhere — "I do not want unnecessarily complex ID
abstractions." No ULID / UUID / typed-ID value objects / entity-specific ID classes. PK is
`INT AUTO_INCREMENT` (from 1), **not** `BIGINT`. Same plain `int` in DB and PHP.

**Status:** Decided (changed)

**Decision Notes (current):**
- Every table: `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`. Foreign keys are plain
  `INT UNSIGNED`.
- No `ulid` column, no public-reference column. The `id` is used directly in the DB, PHP, and
  (per "consistently … in the database and PHP code") in API paths / callbacks / admin URLs.
- Trade-off accepted: sequential integer IDs are guessable, so tenant isolation is enforced by
  **authorization** (every query scoped to the authenticated client — `Rule.md` §8,
  `TenantIsolation.md`), not by unguessable IDs.
- `INT UNSIGNED` max is ~4.29 billion rows per table — ample; revisit only if a single table
  realistically approaches that.
- "Do not use BIGINT" applies to **primary/foreign keys**. Money is still stored as
  `amount_minor BIGINT` (see Q4 note) because currency minor-unit totals can exceed `INT`.
- Recorded in `.claude/docs/Architecture.md` §6 and `.claude/agents/DatabaseAgent.md`.

---

### Q2 — Cross-module communication

**Question:** How should modules communicate with each other?

**Options:**

1. **Direct calls + in-process domain events** — commands/queries go through small published
   `Application/` interfaces (wired by PHP-DI); reactions (e.g. `PaymentPaid` → notification +
   subscription update + reconciliation) go through a lightweight synchronous domain-event
   dispatcher.
2. **Direct calls through published interfaces only** — no event bus; downstream effects are
   explicit chained calls from the caller.
3. **Contracts-only packages per module** — each module ships a separate `*-contracts`
   namespace; implementations never referenced across modules. Overkill for one deployable.

**Recommended:** Option 1

**Selected:** Option 1 — Direct calls + in-process domain events

**Status:** Decided

**Decision Notes:** Payments is inherently event-driven; a tiny post-commit synchronous
dispatcher now avoids a disruptive retrofit. Events stay in-process — the queue, not events,
crosses process boundaries. Initial event catalogue in `.claude/docs/Architecture.md` §5.

---

### Q1 — Module / folder structure

**Question:** How should `src/` be organised?

**Options:**

1. **Module-based, per-module layers** — `src/Modules/<Name>/{Domain,Application,Infrastructure,Http}`
   for each domain (Clients, Providers, Packages, Pricing, Vouchers, Payments, Subscriptions,
   Webhooks, Notifications, Admin), plus `src/Shared/` and `src/Config|Database|Jobs|Public`.
   Each domain's adapters sit next to its logic; "add a module / provider" stays local.
2. **Module-based, one shared Infrastructure layer** — modules keep `Domain/Application/Http`,
   but all infrastructure lives in a single `src/Infrastructure/<concern>` tree.
3. **Classic layer-based** — `src/{Domain,Application,Infrastructure,Http}` at the top, modules
   as sub-namespaces; scatters one domain across four trees.

**Recommended:** Option 1

**Selected:** Option 1 — Module-based, per-module layers

**Status:** Decided

**Decision Notes:** ~10 real domains with independent lifecycles; matches the structure already
sketched in `CLAUDE.md`. Recorded in `.claude/docs/Architecture.md` §3–§4.
