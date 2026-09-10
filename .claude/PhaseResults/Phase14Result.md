# Phase 14 — Pricing overrides & resolution engine

## Execution Summary

- Phase: 14 — Pricing overrides & resolution engine
- Start Datetime: 2026-09-10 14:04
- End Datetime: 2026-09-10 16:05
- Estimated Duration: 6–9h
- Actual Duration: 2h 01m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- Added a single `price_rules` table — `(client, package)` price / availability overrides keyed
  by **7 nullable dimensions** (`pricing_group_id`, `country_code`, `provider_account_id`,
  `payment_method`, `purchase_type`, `subscription_interval`, `currency_code`). A null dimension
  is a wildcard.
- Introduced the `SubscriptionInterval` domain enum (`monthly` / `quarterly` / `yearly`) — a new
  price-rule dimension, reused later by the Subscriptions module.
- Built the `PriceRule` aggregate with the matching + precedence primitives (`matches`,
  `pinnedDimensions`, `specificity`, `tieBreak`) and the structural guard `PriceRule::validate`.
- Built `PriceRuleResolver` — filters a `(client, package)`'s rules to those whose every pinned
  dimension equals the `PriceRuleContext`, then ranks: most matched dimensions → the fixed
  dimension priority order (`PriceRule::DIMENSIONS`) → highest `id`.
- Wired the resolver into `PriceResolver`: `resolve()` gained `?PaymentMethod`,
  `?PurchaseType`, `?SubscriptionInterval` and `?int $providerAccountId` parameters and a new
  `applyRules()` step that runs **after** the Phase 13 base price. An available winning rule
  replaces the amount (`ResolvedPrice.source = dimension_override`); an unavailable winner
  returns `DomainError::unsupported('pricing.combination_unavailable')` with **no fallback**.
- Added `SetPriceRule` and `DeletePriceRule` audited use cases, `PriceRuleDirectory` (list
  projection), PDO adapters, container wiring, three `bin/` scripts + `composer pricing:*rule*`
  entries, and two seeded `pro` rules in `PricingSeeder`.
- Extended `GET /api/v1/pricing/resolve` with `method` / `purchase_type` / `interval` query
  params and `applied_rule_id` / `applied_dimensions` in the response.
- Tests: 3 new test classes + 2 new `PriceResolverTest` cases + 5 existing tests updated for the
  new `PriceResolver` constructor arg. Unit suite 242 → 256, `composer ci` green.

## Files Created

- `src/Database/Migrations/20260910150001_create_price_rules_table.php` —
  `Gomrok\Database\Migrations\CreatePriceRulesTable`.
- `src/Modules/Pricing/Domain/SubscriptionInterval.php` — the new cadence enum.
- `src/Modules/Pricing/Domain/PriceRule.php` — the override aggregate (`create`, `fromStorage`,
  `assignId`, `change`, `validate`, `matches`, `pinnedDimensions`, `specificity`, `tieBreak`,
  `const DIMENSIONS`).
- `src/Modules/Pricing/Domain/PriceRuleRepository.php` — port (`save`, `findById`, `delete`,
  `forClientPackage`, `findByDimensions`).
- `src/Modules/Pricing/Application/PriceRuleContext.php` — readonly resolve-time request VO.
- `src/Modules/Pricing/Application/PriceRuleResolver.php` — the winner-picking service.
- `src/Modules/Pricing/Application/PriceRuleAuditSnapshot.php` — `static of(PriceRule): array`.
- `src/Modules/Pricing/Application/PriceRuleSummary.php` — list projection VO (carries a computed
  `specificity`).
- `src/Modules/Pricing/Application/PriceRuleDirectory.php` — read port (`forClientPackage`).
- `src/Modules/Pricing/Application/SetPriceRule/SetPriceRuleCommand.php`,
  `SetPriceRuleResult.php`, `SetPriceRuleHandler.php` — the upsert-by-dimension-tuple use case.
- `src/Modules/Pricing/Application/DeletePriceRule/DeletePriceRuleHandler.php` — client-scoped
  delete-by-id.
- `src/Modules/Pricing/Infrastructure/PdoPriceRuleRepository.php` — persistence; upserts via a
  null-safe (`<=>`) `findByDimensions` lookup because the 8-column unique index is NULL-distinct.
- `src/Modules/Pricing/Infrastructure/PdoPriceRuleDirectory.php` — list projection.
- `bin/SetPriceRule.php`, `bin/DeletePriceRule.php`, `bin/ListPriceRules.php` — CLI.
- `tests/Support/InMemoryPriceRuleRepository.php` — test double.
- `tests/Unit/Modules/Pricing/Domain/PriceRuleTest.php` — `validate` codes, unavailable clears
  amount, `matches` respects every pinned dimension.
- `tests/Unit/Modules/Pricing/Application/PriceRuleResolverTest.php` — 6 precedence-matrix cases.
- `tests/Unit/Modules/Pricing/Application/PriceRuleHandlersTest.php` — `SetPriceRule` validation
  + upsert + `DeletePriceRule` client scoping.

## Files Modified

- `src/Modules/Pricing/Application/PriceResolver.php` — constructor gained `PriceRuleResolver`;
  `resolve()` gained 4 optional trailing params; new private `applyRules()`.
- `src/Modules/Pricing/Application/PriceSource.php` — `case DimensionOverride = 'dimension_override'`.
- `src/Modules/Pricing/Application/ResolvedPrice.php` — `?int $appliedRuleId`,
  `list<string> $appliedDimensions`, `withRule()` factory.
- `src/Modules/Pricing/Infrastructure/definitions.php` — bind `PriceRuleRepository` /
  `PriceRuleDirectory` to the PDO adapters.
- `src/Http/Api/PricingResolveAction.php` — `method` / `purchase_type` / `interval` query params
  (422 on an unknown value); `applied_rule_id` / `applied_dimensions` in the JSON.
- `src/Database/Seeds/PricingSeeder.php` — after the FX-rate insert, deletes then re-inserts two
  `pro` rules: (stripe account + EUR → available €27.00) and (US pricing group + subscription +
  yearly → unavailable). New private `upsertPriceRule()`.
- `composer.json` — `pricing:set-rule` / `pricing:delete-rule` / `pricing:list-rules` scripts +
  descriptions. `composer.lock` — re-hashed.
- `tests/Integration/MigrationRoundTripTest.php` — `'price_rules'` added to `TABLES`.
- `tests/Integration/PricingPersistenceTest.php`,
  `tests/Unit/Modules/Pricing/Application/PriceResolverTest.php`,
  `tests/Unit/Modules/Pricing/Application/PriceCatalogTest.php`,
  `tests/Unit/Http/PackagesApiTest.php` — updated for the new `PriceResolver` constructor
  argument / `PriceRuleRepository` container binding.

## Implementation Details

- **`PriceRule::DIMENSIONS`** = `['subscription_interval','purchase_type','payment_method',
  'provider_account_id','currency_code','country_code','pricing_group_id']` — the canonical
  dimension order, and the tie-break priority (earliest pinned dimension wins).
- **`PriceRule::validate(?groupId, ?PurchaseType, ?SubscriptionInterval, bool available,
  ?amountMinor, ?currency)`** codes: `price_rule.interval_needs_subscription`,
  `price_rule.amount_required`, `price_rule.needs_group_or_currency`,
  `price_rule.unavailable_has_amount`.
- **`PriceRuleResolver::resolve(clientId, packageId, PriceRuleContext)`** — `usort` by
  `[specificity, tieBreak, id]` descending; returns `?PriceRule`.
- **`PriceResolver::applyRules(ResolvedPrice $base, …)`** — builds a `PriceRuleContext` from the
  base price's resolved pricing group + currency plus the request dimensions; null winner →
  `Result::ok($base)`; unavailable winner → `Result::err(DomainError::unsupported(
  'pricing.combination_unavailable', …, ['package' => …, 'pinned' => …, 'rule_id' => …]))`;
  available winner → `$base->withRule($amountMinor, $money->amount(), $ruleId,
  $rule->pinnedDimensions())`.
- **`SetPriceRuleHandler`** validates: package ownership; `PaymentMethod` / `PurchaseType` /
  `SubscriptionInterval` / `Currency` / country values; `pricing_group_id` owned by the client;
  `provider_account_id` in `ProviderAccountDirectory::forClient`; `PriceRule::validate`; and a
  pinned group + currency must agree (`price_rule.currency_mismatch`). Upserts through
  `findByDimensions` → `change()` or `create()`; audit action `price_rule.set`.
- **`DeletePriceRuleHandler`** rejects `price_rule.not_found` when the rule is missing or its
  `clientId()` differs; audit action `price_rule.deleted` with a `withChange($before, null)`.
- A currency-pinned rule only matches inside a pricing group of that currency, so an EUR rule
  never bleeds into a USD group — the `converted` base price stands there.

## Database Changes

One new table, migration `20260910150001_create_price_rules_table.php` (additive; no changes to
existing tables; no backfill):

- **`price_rules`** — `id` PK; `client_id` FK → `clients(id)` CASCADE; `package_id` FK →
  `packages(id)` CASCADE; nullable `pricing_group_id` FK → `pricing_groups(id)` CASCADE;
  nullable `country_code CHAR(2)` FK → `countries(code)` RESTRICT; nullable `provider_account_id`
  FK → `provider_accounts(id)` CASCADE; nullable `payment_method` / `purchase_type` /
  `subscription_interval VARCHAR(20)`; nullable `currency_code CHAR(3)` FK → `currencies(code)`
  RESTRICT; `is_available TINYINT(1) NOT NULL DEFAULT 1`; nullable `amount_minor BIGINT
  UNSIGNED`; `created_at` / `updated_at`.
- Indexes: `uniq_price_rules_dimensions` UNIQUE `(package_id, pricing_group_id, country_code,
  provider_account_id, payment_method, purchase_type, subscription_interval, currency_code)`;
  `idx_price_rules_client_package (client_id, package_id)`;
  `idx_price_rules_provider_account (provider_account_id)`;
  `idx_price_rules_pricing_group (pricing_group_id)`.
- Total table count: **36**.

## API Changes

- **`GET /api/v1/pricing/resolve`** — new optional query params `method`, `purchase_type`,
  `interval` (each `422` with `pricing.unknown_*` on an unrecognised value). Response `price`
  object gains `applied_rule_id` (`int|null`) and `applied_dimensions` (`string[]`, most-specific
  first). A most-specific unavailable rule now returns `422 pricing.combination_unavailable`.
- `GET /api/v1/packages` unchanged — the browse list stays on base prices only.

## Tests and Validation

- **Created:** `PriceRuleTest` (3), `PriceRuleResolverTest` (6), `PriceRuleHandlersTest` (3).
- **Modified:** `PriceResolverTest` (+2: `anAvailablePriceRuleOverridesTheBaseAmount`,
  `aMostSpecificUnavailableRuleFailsWithoutFallback`), `PriceCatalogTest`,
  `PackagesApiTest`, `PricingPersistenceTest`, `MigrationRoundTripTest` (all for the new
  constructor arg / table list).
- **Commands run:** `composer dump-autoload`, `composer cs:fix`, `composer stan`,
  `composer test`, `composer test:integration`, `composer ci`,
  `composer update --lock --no-install`, `composer validate --no-check-publish`,
  `php -l` on the migration + 3 bin scripts, and a captured-evidence script.

Captured output:

```
$ composer stan
 [OK] No errors

$ composer test
OK (256 tests, 983 assertions)

$ composer test:integration
OK, but some tests were skipped!
Tests: 33, Assertions: 0, Skipped: 33.        # no local MySQL — run in CI

$ composer ci
 [OK] No errors        # php-cs-fixer
 [OK] No errors        # phpstan
OK (256 tests, 983 assertions)

$ composer validate --no-check-publish
./composer.json is valid
```

Captured evidence — `PriceRuleEvidence.php` (in-memory resolver, precedence matrix + unavailable
combination):

```
=== Phase 14 — price-rule resolution (precedence + unavailable) ===

DE, no method (base group price)               ->    2900 EUR  source=baseline           rule=- dims=[]
DE, card  (rule#2: card+DE most specific)      ->    2500 EUR  source=dimension_override rule=2 dims=[payment_method,currency_code,country_code]
FR, card  (rule#1: card wildcard country)      ->    2700 EUR  source=dimension_override rule=1 dims=[payment_method,currency_code]
DE, subscription/monthly (rule#3)              ->    2000 EUR  source=dimension_override rule=3 dims=[purchase_type,currency_code]
US, subscription/monthly (rule#3, USD conv)    ->    3132 USD  source=converted          rule=- dims=[]
US, subscription/yearly  (rule#4 UNAVAILABLE)  ->  ERROR  pricing.combination_unavailable
```

(Row 5 shows an EUR-pinned rule correctly *not* matching inside the USD `us` group — the
converted base price stands. Row 6 is the exit criterion: a disabled combination resolves to
`pricing.combination_unavailable`, never a wrong price.)

## Technical Decisions

- **Q1 — one `price_rules` table with nullable dimensions** rather than a table per dimension:
  fewer joins, one deterministic ranking pass, trivially extensible.
- **Q2 — 7 dimensions incl. a new `SubscriptionInterval` enum**; `currency_code` kept as a
  niche filter (most rules pin a group, which already fixes the currency).
- **Q3 — precedence = matched-dimension count → fixed dimension priority → highest `id`.**
  Deterministic and explainable; no "specificity weight" heuristics.
- **Q4 — row-level `is_available`; a most-specific unavailable rule is a hard stop with no
  fallback** to a less-specific rule or the base price. Silent wrong prices are the failure mode
  we must never have.
- **Q5 — a dedicated `PriceRuleResolver`** (mirrors `ProviderRouter` / `PriceResolver`);
  `PriceResolver` orchestrates base-then-rules; `PriceCatalog` browse list stays base-only;
  overrides are set through audited handlers + CLI, never ad-hoc SQL.

## Problems Encountered

- PHPStan: `array_values()` on an already-list in `PriceRule::pinnedDimensions`; a redundant
  `\assert(\count(...) === \count(...))` and an unused `PriceRule` import in
  `PdoPriceRuleDirectory`; `array_filter()` without a strict callback in `bin/ListPriceRules.php`;
  three "nullsafe on non-nullable" hits in `PriceRuleResolverTest` after a `assertSame` narrowed
  `$match`.
- `PriceResolverTest::anAvailablePriceRuleOverridesTheBaseAmount` asserted
  `appliedDimensions == ['payment_method']` but a payment-method-only available rule must also
  pin a currency (`PriceRule::validate`), so the real value is
  `['payment_method', 'currency_code']`.
- `php-cs-fixer` flagged a duplicate `PriceRuleResolver` import in `PriceResolverTest` left by an
  earlier scripted edit.

## Resolutions

- Removed the redundant `array_values` / `assert` / unused import; switched the `bin/` filter to
  `array_filter(..., static fn (?string $x) => $x !== null && $x !== '')`; changed the three
  post-`assertSame` nullsafe calls to `->`.
- Corrected the test expectation to `['payment_method', 'currency_code']`.
- `composer cs:fix` de-duplicated and re-ordered the imports; re-ran `composer ci` clean.

## Deferred Work

- `providerAccountId` is a resolver dimension but `GET /api/v1/pricing/resolve` does not yet
  accept it as a query param (would need a slug → account lookup) — add when a caller needs it.
- Price snapshotting onto the payment / subscription record — Phase 19+.
- `price_rules` round-trip is covered only by a skipped integration test locally; the real
  assertion runs in GitHub Actions CI (no local MySQL).

## Final Result

Gomrok pricing now resolves in two layers: the Phase 13 base price (pricing group → group-package
row → baseline / converted / group override), then the Phase 14 `price_rules` override — the
most-specific matching rule replaces the amount or fails the resolve with
`pricing.combination_unavailable`. Overrides are managed through audited `SetPriceRule` /
`DeletePriceRule` handlers, the `pricing:set-rule` / `delete-rule` / `list-rules` CLI, and the
env-gated seeder. `GET /api/v1/pricing/resolve` exposes the full checkout context
(`method` / `purchase_type` / `interval`) and reports which rule and dimensions were applied. 36
tables; 256 unit tests + 33 CI-only integration tests; `composer ci` green.

**Next recommended phase:** Phase 15 — Price lists (A/B).
