# Q: Phase 14 — Pricing overrides & resolution engine (completion summary)

## What was implemented

The **override layer** above the Phase 13 base price. One new table, `price_rules`, holds
`(client, package)` overrides keyed by **7 nullable dimensions** — `pricing_group_id`,
`country_code`, `provider_account_id`, `payment_method`, `purchase_type`,
`subscription_interval`, `currency_code`. A null dimension is a wildcard. Each row either
overrides `amount_minor` (`is_available = 1`) or marks the combination **not for sale**
(`is_available = 0`).

- **`PriceRuleResolver`** picks the winning rule: most matched dimensions → fixed dimension
  priority (`subscription_interval > purchase_type > payment_method > provider_account_id >
  currency_code > country_code > pricing_group_id`) → highest `id`.
- **`PriceResolver::applyRules`** runs it *after* the base price (so the pricing group + currency
  are already fixed). Available winner → `ResolvedPrice.source = dimension_override` with
  `applied_rule_id` / `applied_dimensions`. Unavailable winner → hard
  `pricing.combination_unavailable`, **no fallback** to a less-specific rule or the base price.
- New `SubscriptionInterval` enum (`monthly` / `quarterly` / `yearly`).
- `SetPriceRule` / `DeletePriceRule` audited use cases; `PriceRuleDirectory` list projection.
- `bin/SetPriceRule.php`, `bin/DeletePriceRule.php`, `bin/ListPriceRules.php` +
  `composer pricing:set-rule` / `delete-rule` / `list-rules`.
- `GET /api/v1/pricing/resolve` gains `method` / `purchase_type` / `interval` query params and
  `applied_rule_id` / `applied_dimensions` in the response. `GET /api/v1/packages` unchanged
  (browse list stays base-price only).
- `PricingSeeder` seeds two `pro` rules (stripe+EUR → €27; US group + yearly subscription →
  unavailable).

## Files created

- `src/Database/Migrations/20260910150001_create_price_rules_table.php`
- `src/Modules/Pricing/Domain/` — `SubscriptionInterval.php`, `PriceRule.php`,
  `PriceRuleRepository.php`
- `src/Modules/Pricing/Application/` — `PriceRuleContext.php`, `PriceRuleResolver.php`,
  `PriceRuleAuditSnapshot.php`, `PriceRuleSummary.php`, `PriceRuleDirectory.php`,
  `SetPriceRule/{Command,Result,Handler}.php`, `DeletePriceRule/DeletePriceRuleHandler.php`
- `src/Modules/Pricing/Infrastructure/` — `PdoPriceRuleRepository.php`, `PdoPriceRuleDirectory.php`
- `bin/SetPriceRule.php`, `bin/DeletePriceRule.php`, `bin/ListPriceRules.php`
- `tests/Support/InMemoryPriceRuleRepository.php`
- `tests/Unit/Modules/Pricing/Domain/PriceRuleTest.php`,
  `tests/Unit/Modules/Pricing/Application/PriceRuleResolverTest.php`,
  `tests/Unit/Modules/Pricing/Application/PriceRuleHandlersTest.php`
- `.claude/PhaseResults/Phase14Result.md`

## Files updated

- `src/Modules/Pricing/Application/{PriceResolver,PriceSource,ResolvedPrice}.php`,
  `Infrastructure/definitions.php`
- `src/Http/Api/PricingResolveAction.php`
- `src/Database/Seeds/PricingSeeder.php`
- `composer.json` / `composer.lock`
- `tests/Integration/{MigrationRoundTripTest,PricingPersistenceTest}.php`,
  `tests/Unit/{Http/PackagesApiTest,Modules/Pricing/Application/PriceCatalogTest,Modules/Pricing/Application/PriceResolverTest}.php`
- Docs: `database-design.md` (36 tables), `database-diagram.md` + `.html` (12/12 mermaid),
  `db_explain.md`, `Architecture.md` (§8/§9), `Phases.md` (row 14 → ☑), `Changelog.md`,
  `FileIndex.md`, `knowledge/Knowledge.md`, `Commands.md`, `Orders.md` (D17)

## Files removed

None.

## Database changes

One additive table, `price_rules` (migration `20260910150001`). Unique index
`uniq_price_rules_dimensions` over `(package_id, pricing_group_id, country_code,
provider_account_id, payment_method, purchase_type, subscription_interval, currency_code)` —
NULL-distinct, so upserts use a null-safe (`<=>`) lookup, not `ON DUPLICATE KEY`. Plus
`idx_price_rules_client_package`, `idx_price_rules_provider_account`,
`idx_price_rules_pricing_group`. FKs: `client_id` / `package_id` / `pricing_group_id` /
`provider_account_id` CASCADE, `country_code` / `currency_code` RESTRICT. Total: **36 tables**.

## Tests added / changed

- New: `PriceRuleTest` (3), `PriceRuleResolverTest` (6), `PriceRuleHandlersTest` (3).
- Changed: `PriceResolverTest` (+2 cases + new ctor arg), `PriceCatalogTest`, `PackagesApiTest`,
  `PricingPersistenceTest`, `MigrationRoundTripTest`.
- Run with: `composer test` (unit), `composer test:integration` (CI-only — no local MySQL),
  `composer ci` (cs + stan + unit).

## Captured evidence

```
$ composer ci
 [OK] No errors        # php-cs-fixer
 [OK] No errors        # phpstan (max + strict-rules)
OK (256 tests, 983 assertions)

$ composer test:integration
OK, but some tests were skipped!
Tests: 33, Assertions: 0, Skipped: 33.

$ composer validate --no-check-publish
./composer.json is valid
```

`PriceRuleEvidence.php` (in-memory resolver — precedence matrix + unavailable combo):

```
DE, no method (base group price)               ->    2900 EUR  source=baseline           rule=- dims=[]
DE, card  (rule#2: card+DE most specific)      ->    2500 EUR  source=dimension_override rule=2 dims=[payment_method,currency_code,country_code]
FR, card  (rule#1: card wildcard country)      ->    2700 EUR  source=dimension_override rule=1 dims=[payment_method,currency_code]
DE, subscription/monthly (rule#3)              ->    2000 EUR  source=dimension_override rule=3 dims=[purchase_type,currency_code]
US, subscription/monthly (rule#3, USD conv)    ->    3132 USD  source=converted          rule=- dims=[]
US, subscription/yearly  (rule#4 UNAVAILABLE)  ->  ERROR  pricing.combination_unavailable
```

Row 5: an EUR-pinned rule correctly does not match inside the USD `us` group. Row 6 is the exit
criterion — a disabled combination resolves to `pricing.combination_unavailable`, never a wrong
price.

## Known limitations

- `providerAccountId` is a resolver dimension but `/pricing/resolve` doesn't accept it as a
  query param yet (needs a slug → account lookup).
- `price_rules` DB round-trip is asserted only in CI (local MySQL unavailable → skipped).
- Price snapshotting onto payment / subscription records is Phase 19+.

## Next recommended phase

**Phase 15 — Price lists (A/B):** `price_lists` per pricing group, deterministic visitor → list
assignment via a stable hash, package prices per list, disable-fallback.
