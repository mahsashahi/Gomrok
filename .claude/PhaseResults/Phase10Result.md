# Phase 10 — Country provider configuration & routing resolution

## Execution Summary

- Phase: 10 — Country provider configuration & routing resolution
- Start Datetime: 2026-09-09 20:25
- End Datetime: 2026-09-09 23:37
- Estimated Duration: 5–8h
- Actual Duration: ~3h 10m
- Tokens Used: N/A
- Final Status: ☑ Complete (code + docs; `composer ci` green — 188 unit tests. The 5 migrations +
  `ProviderGroupsPersistenceTest` run in CI — no usable local MySQL here.)

## Work Completed

- Added **provider groups** as the single country → provider routing mechanism (Phase 10 Q1).
  No `country_provider_configs` / `country_provider_priorities` / `country_payment_methods` /
  `country_purchase_capabilities` tables — the Q1 decision superseded that part of the
  `Phases.md` scope.
- 5 migrations: `provider_groups` + `provider_group_countries` / `_accounts` / `_purchase_types`
  / `_methods`. **22 tables total.**
- `ProviderGroup` aggregate (+ `ProviderGroupAccount` entity, `ProviderGroupSlug`,
  `ProviderGroupStatus`, `DeviceType`), `ProviderGroupRepository` port + `PdoProviderGroupRepository`.
- `Modules\Providers\Application\Routing\ProviderRouter` — resolves the client's group for a
  `RoutingRequest`, intersects the group's purchase-type set with each candidate account's
  provider-type declaration, filters by mode / status / served country / method, and returns a
  `RoutingDecision` VO (ordered `candidates` + `rejections` + resolved group). Unsupported
  combinations are rejected with a `provider_routing.*` `DomainError` — never downgraded.
- `RoutingDecision::toArray()` / `::fromArray()` — a versioned snapshot contract for Phase 17 to
  persist on the payment. No routing-decision table this phase (Q4).
- Use cases: `CreateProviderGroup`, `ConfigureProviderGroup` (countries + purchase types +
  methods + name/currency, full-replace), `SetProviderGroupAccounts` (ordered, full-replace),
  `ChangeProviderGroupStatus` — each returns `Result` and writes an audited `provider_group.*`
  action.
- Seeded real **Ziraat + Mollie** provider-type capability declarations (Q5). Env-gated
  `ProviderGroupsSeeder` wires the `local-dev` client with turkey / germany / netherlands /
  default groups (+ ziraat-test / mollie-test / paypal-test accounts).
- CLI: `bin/{CreateProviderGroup,ConfigureProviderGroup,SetProviderGroupAccounts}.php`
  (`composer provider-group:*`).

## Files Created

**Migrations / seeds**
- `src/Database/Migrations/2026090922000{1..5}_*.php` → `Create{ProviderGroups,ProviderGroupCountries,ProviderGroupAccounts,ProviderGroupPurchaseTypes,ProviderGroupMethods}Table`
- `src/Database/Seeds/ProviderGroupsSeeder.php`

**Providers / Domain** — `ProviderGroup.php`, `ProviderGroupAccount.php`, `ProviderGroupSlug.php`,
`ProviderGroupStatus.php`, `DeviceType.php`, `ProviderGroupRepository.php`

**Providers / Application / Routing** — `ProviderRouter.php`, `RoutingRequest.php`,
`RoutingDecision.php`, `RoutedAccount.php`, `RejectedAccount.php`, `RejectionReason.php`

**Providers / Application** — `ProviderGroupAuditSnapshot.php`; use-case folders
`CreateProviderGroup` (`Command` / `Handler` / `Result`), `ConfigureProviderGroup`
(`Command` / `Handler`), `SetProviderGroupAccounts` (`Command` / `Handler` /
`ProviderGroupAccountInput`), `ChangeProviderGroupStatus` (`Handler`)

**Providers / Infrastructure** — `PdoProviderGroupRepository.php`

**CLI** — `bin/CreateProviderGroup.php`, `bin/ConfigureProviderGroup.php`,
`bin/SetProviderGroupAccounts.php`

**Tests** — `tests/Unit/Modules/Providers/Domain/ProviderGroupTest.php`,
`tests/Unit/Modules/Providers/Application/Routing/ProviderRouterTest.php`,
`tests/Unit/Modules/Providers/Application/Routing/RoutingDecisionTest.php`,
`tests/Unit/Modules/Providers/Application/ProviderGroupHandlersTest.php`,
`tests/Integration/ProviderGroupsPersistenceTest.php`,
`tests/Support/InMemoryProviderGroupRepository.php`,
`tests/Support/StubProviderAccountDirectory.php`

`.claude/PhaseResults/Phase10Result.md` — this file.

## Files Modified

- `src/Database/Seeds/data/ProviderTypeDeclarations.json` — added `mollie` (one-time / recurring
  / subscription; hosted_checkout, redirect_payment, three_d_secure, refund, partial_refund,
  subscription_cancel, customer_portal, webhook, return_url, manual_status_polling) and `ziraat`
  (one-time only; hosted_checkout, redirect_payment, three_d_secure, webhook, return_url,
  manual_status_polling).
- `src/Database/Seeds/ProviderTypeDeclarationsSeeder.php` — docblock only (the seeder already
  iterates the JSON).
- `src/Modules/Providers/Infrastructure/definitions.php` — binds `ProviderGroupRepository` →
  `PdoProviderGroupRepository`.
- `composer.json` / `composer.lock` — `provider-group:create` / `:configure` / `:set-accounts`
  scripts + descriptions; lock hash refreshed (no dependency change).
- `tests/Integration/MigrationRoundTripTest.php` — 5 new tables in `TABLES`.
- `tests/Unit/Database/ProviderTypeDeclarationsDataTest.php` — accepts `mollie` / `ziraat`.
- Docs: `.claude/docs/database-design.md` (→ 22 tables, Phase 10 section, migrations list),
  `.claude/docs/database-diagram.md` (Phase 10 ER diagram + module map + note),
  `.claude/docs/database-diagram.html` (snapshot ER block + module-map label),
  `.claude/docs/db_explain.md` (Phase 10 per-table guide), `.claude/docs/Architecture.md`
  (§8 Routing rewritten), `.claude/docs/Phases.md` (row 10 → ☑, scope rewritten to match Q1),
  `.claude/docs/Commands.md`, `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`,
  `.claude/Orders.md` (D13), `.claude/Changelog.md`.

## Implementation Details

- **`ProviderRouter::route(RoutingRequest)`** returns `Result` — `ok(RoutingDecision)` or
  `err(DomainError)`. Steps: resolve group (`resolveGroup()` — first active non-default group
  whose countries contain the request country and whose `device_type` matches or is NULL, else
  the active `is_default` group for that device, else `provider_routing.no_group_for_market`);
  `allowsPurchaseType` gate (`provider_routing.purchase_type_not_enabled`, `RuleViolation`);
  pinned-currency check (`provider_routing.currency_not_supported`); group-method gate
  (`provider_routing.method_not_enabled`); then per `provider_group_accounts` entry in priority
  order the `reject()` chain — `GroupLinkDisabled` → `AccountNotFound` → `AccountDisabled` →
  `ModeMismatch` → `CountryNotServed` → `PurchaseTypeUnsupportedByProvider` →
  `MethodNotSupportedByAccount`. No survivor → `provider_routing.no_provider_for_market`
  (`Unsupported`) with the rejection list JSON-encoded in the error context. `LoggerInterface`
  is injected — an `info` line on success, a `warning` on no-candidate; no secrets.
- **`ProviderRouter`** reads candidate accounts via `ProviderAccountDirectory::forClient()` once
  and indexes by id (no per-entry query); provider-type declarations via
  `ProviderTypeDeclarations::findByCode()`.
- **`ProviderGroup`** aggregate: `define()` / `fromStorage()`; `setCountries()` (upper-cases +
  dedups), `setPurchaseTypes()` / `setMethods()` (dedup by enum value), `setAccounts()`
  (re-sorts by `priority` then `providerAccountId`), `changeCurrency()`, `rename()`,
  `disable()` / `enable()` (idempotent). `servesCountry()`, `appliesToDevice()`,
  `allowsPurchaseType()` (fail closed), `allowsMethod()` (fail open) are the in-code helpers the
  router uses.
- **`PdoProviderGroupRepository::save()`** — insert/update the `provider_groups` row, then
  delete-and-reinsert all four child tables; `forClient()` orders `is_default ASC, slug ASC` and
  hydrates each group with its children.
- **`CreateProviderGroupHandler`** validates device type / name / slug / currency, client
  (exists + active), slug uniqueness, and one-default-per-`(client, device_type)`.
- **`ConfigureProviderGroupHandler`** rejects countries on the default group
  (`provider_group.default_takes_no_countries`) and a country already claimed by a sibling
  non-default group of the same device type (`provider_group.country_already_grouped`).
- **`SetProviderGroupAccountsHandler`** rejects a duplicate account id
  (`provider_group.duplicate_account`) and one that is not in
  `ProviderAccountDirectory::forClient(group.clientId)` (`provider_group.account_not_owned`).

## Database Changes

5 new tables (see `database-design.md` → *Providers — routing (Phase 10)*):

- `provider_groups` — FK `client_id` → `clients(id)` CASCADE, `currency_code` → `currencies(code)`
  RESTRICT (nullable). `UNIQUE (client_id, slug)`; indexes `(client_id, is_default, device_type)`,
  `(client_id, status)`.
- `provider_group_countries` — FK `provider_group_id` CASCADE, `country_code` → `countries(code)`
  RESTRICT. `UNIQUE (provider_group_id, country_code)`.
- `provider_group_accounts` — FK `provider_group_id` CASCADE, `provider_account_id` →
  `provider_accounts(id)` CASCADE. `UNIQUE (provider_group_id, provider_account_id)`; indexes
  `(provider_group_id, priority)`, `(provider_account_id)`.
- `provider_group_purchase_types` — FK `provider_group_id` CASCADE.
  `UNIQUE (provider_group_id, purchase_type)`.
- `provider_group_methods` — FK `provider_group_id` CASCADE.
  `UNIQUE (provider_group_id, payment_method)`.

No changes to existing tables. No routing-decision table (Q4). Non-schema: `mollie` + `ziraat`
rows added to `data/ProviderTypeDeclarations.json` (seeded into `provider_type_capabilities` /
`provider_type_purchase_types`).

## API Changes

No HTTP endpoints. `ProviderRouter` / `RoutingRequest` / `RoutingDecision` are internal ports —
consumed by the payments flow in Phase 17. New CLI: 3 `bin/` scripts.

## Tests and Validation

- **Tests created:** 4 unit classes (33 tests) — `ProviderGroupTest` (8), `ProviderRouterTest`
  (10, the exit matrix), `RoutingDecisionTest` (3), `ProviderGroupHandlersTest` (12); 1
  integration class (`ProviderGroupsPersistenceTest`, 1 test); 2 support doubles.
- **Tests modified:** `MigrationRoundTripTest` (table list), `ProviderTypeDeclarationsDataTest`
  (accepts mollie / ziraat).
- **Commands run:**
  - `composer cs` → clean (287 files).
  - `composer stan` → `[OK] No errors` (level `max` + strict-rules, 283 files).
  - `composer test` → **`OK (188 tests, 693 assertions)`**.
  - `composer test:integration` → `Skipped: 30` (no usable local MySQL — the `gomrok` DB user is
    rejected by the local server, same as Phases 5–9).
  - `composer ci` → green (exit 0).
- **Exit-criteria evidence** — a standalone script driving the real `ProviderRouter` against the
  seeded-style data (see *Final Result* for the captured output): Turkey/Ziraat one-time-only,
  Turkey-subscription rejected, Germany/Mollie card + PayPal, Netherlands/PayPal-only, plus
  default-group fallback and a JSON `RoutingDecision` snapshot. Covered permanently by
  `ProviderRouterTest`.

## Technical Decisions

`PhaseResults/PhaseDecisions.md` Phase 10 Q1–Q5:

1. **Provider groups as the single mechanism** — a country belongs to one group; the client
   default is an `is_default` group. No `country_provider_configs` tables.
2. **Group-level purchase-type / method enablement** (`provider_group_purchase_types` /
   `_methods`), intersected at resolve time with each account's provider-type declaration.
3. **Resolver returns an ordered candidate list + `RoutingDecision` snapshot VO** (chosen =
   first; rejections recorded) — enables fallback without re-resolving.
4. **VO only, no persistence this phase** — `toArray()` / `fromArray()` contract; Phase 17
   snapshots it on the payment.
5. **Seed real Ziraat + Mollie provider-type declarations** so `composer db:setup` can actually
   route Turkey / Germany.

## Problems Encountered

- PHPStan `nullsafe.neverNull` on `$summary?->slug ?? ''` in `ProviderRouter`.
- PHPStan `arrayValues.list` — `array_values()` on an already-`list` in `ProviderGroup::sortByPriority`
  and `InMemoryProviderGroupRepository::forClient`.
- PHPStan `method.nonObject` — `PDO::query()` returns `PDOStatement|false` in the integration
  test helper.
- Existing `ProviderTypeDeclarationsDataTest` asserted the seed file holds stripe + paypal only.
- `php-cs-fixer` flagged a double-quoted string with no interpolation.

## Resolutions

- Replaced `$summary?->slug ?? ''` with `$summary !== null ? $summary->slug : ''`.
- Dropped the redundant `array_values()` wrappers.
- Switched the test helper to a prepared statement.
- Updated the data test to accept `mollie` / `ziraat` and reworded its message.
- `composer cs:fix` normalised the quoting.

## Deferred Work

- Persisting the `RoutingDecision` snapshot on the payment / subscription — Phase 17 (adds one
  JSON column and calls `RoutingDecision::toArray()`).
- Package-availability filtering in the router (`Phases.md` lists "filter by package") — Phases
  11–12 wire packages into routing; the router currently has no package input.
- Per-method provider capability nuance ("Mollie PayPal ≠ recurring") — Phase 12
  (`provider_type_method_capabilities`).
- DB-level enforcement of the "one default per scope" / "country in one group" invariants
  (generated column + partial unique) — only if the app guards prove fragile.
- A read model / admin views for provider groups — Phase 27.
- Executing the 5 migrations + `ProviderGroupsPersistenceTest` against real MySQL — CI, or the
  user locally (`docker compose up -d mysql && composer db:reset && composer test:integration`).

## Final Result

22 tables. A client configures routing entirely through provider groups; `ProviderRouter`
resolves a deterministic ordered candidate list and rejects — never downgrades — an unsupported
purchase type / method / currency / mode. `RoutingDecision` is ready to be snapshotted by the
payments phase.

Captured `ProviderRouter` output (real, from the exit-criteria script):

```
Turkey, one-time             -> ziraat-tr via ziraat  (group: turkey)
Turkey, subscription         -> REJECTED  [provider_routing.purchase_type_not_enabled] Purchase type 'subscription' is not enabled for country 'TR'.
Germany, one-time card       -> mollie-de via mollie  (group: germany)
Germany, one-time paypal     -> mollie-de via mollie  (group: germany)
Netherlands, one-time        -> paypal-nl via paypal  (group: netherlands)
France (fallback), sub       -> stripe-default via stripe  (group: default, default)

--- snapshot (Germany card) ---
{
    "version": 1,
    "client_id": 7,
    "country": "DE",
    "currency": "EUR",
    "purchase_type": "one_time_payment",
    "payment_method": "card",
    "mode": "test",
    "device_type": null,
    "group": { "id": 2, "slug": "germany", "is_default": false },
    "chosen_account_id": 2,
    "candidates": [
        { "account_id": 2, "slug": "mollie-de", "provider_type_code": "mollie", "mode": "test", "priority": 0 }
    ],
    "rejections": []
}
```

`composer test` — `OK (188 tests, 693 assertions)`; `composer stan` — `[OK] No errors`;
`composer cs` — clean; `composer test:integration` — `Skipped: 30`.

Next recommended phase: **Phase 11 — Packages module: catalog & availability.**
