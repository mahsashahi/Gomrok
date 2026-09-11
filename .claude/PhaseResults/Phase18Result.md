# Phase 18 — Decision snapshots

## Execution Summary

- Phase: 18 — Decision snapshots
- Start Datetime: 2026-09-11 14:19
- End Datetime: 2026-09-11 15:44
- Estimated Duration: 2–4h
- Actual Duration: 1h 25m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

A new `Checkout` module was created, anchored by `checkout_attempts` — the parent record for the
whole pre-payment lifecycle (Phase 18 Q1, a substantial user-directed expansion of the originally
proposed design). Three write-once decision-snapshot tables sit beside it, one per owning module
(Q4): `pricing_decision_snapshots` (Pricing), `voucher_decision_snapshots` (Vouchers, thin — no
amounts), `provider_routing_decision_snapshots` (Providers). A new `CheckoutAttemptStatus` enum
(Q3, fully dictated by the user) implements a monotonic-rank state machine — 9 ranked happy-path
statuses plus 4 unranked exit statuses reachable from any non-terminal status, skipping ranks
allowed, terminal once reached. Five handlers (Q5) — `CreateCheckoutAttemptHandler`,
`ResolveCheckoutPricingHandler`, `ReserveCheckoutVoucherHandler`, `SelectCheckoutProviderHandler`,
`ChangeCheckoutAttemptStatusHandler` — each advance the attempt's status and (where applicable)
write the owning module's decision snapshot inside one `Transactions::run()` call.
`CheckoutAttempt::commercialSnapshot()` returns the attempt's own immutable commercial context,
the shape a future `payments` row will copy at conversion time.

`ResolveCheckoutPricingHandler` calls into Pricing's `PriceResolver`, `ReserveCheckoutVoucherHandler`
calls into Vouchers' `ReserveVoucherRedemptionHandler` (reusing the checkout attempt's own
`attempt_reference` as the voucher redemption's), and `SelectCheckoutProviderHandler` calls into
Providers' `ProviderRouter` — all through each module's published `Application/` port, never
through `Domain/` or `Infrastructure/` directly, keeping the hexagonal dependency rule intact.

## Files Created

- `src/Database/Migrations/20260911150001_create_checkout_and_decision_snapshot_tables.php` →
  `Gomrok\Database\Migrations\CreateCheckoutAndDecisionSnapshotTables` (4 tables).
- **Checkout module** (`src/Modules/Checkout/`):
  - `Domain/CheckoutAttemptStatus.php` — the 13-case status enum (`rank()`, `isExit()`,
    `isTerminal()`).
  - `Domain/CheckoutAttempt.php` — aggregate (`start()`, `fromStorage()`, `assignId()`,
    `transitionTo()`, `commercialSnapshot()`).
  - `Domain/CheckoutAttemptRepository.php` — port.
  - `Application/CheckoutAuditSnapshot.php`, `Application/CheckoutAttemptSummary.php`,
    `Application/CheckoutAttemptDirectory.php`.
  - `Application/CreateCheckoutAttempt/{CreateCheckoutAttemptCommand,CreateCheckoutAttemptResult,CreateCheckoutAttemptHandler}.php`.
  - `Application/ChangeCheckoutAttemptStatus/ChangeCheckoutAttemptStatusHandler.php`.
  - `Application/ResolveCheckoutPricing/{ResolveCheckoutPricingCommand,ResolveCheckoutPricingResult,ResolveCheckoutPricingHandler}.php`.
  - `Application/ReserveCheckoutVoucher/{ReserveCheckoutVoucherCommand,ReserveCheckoutVoucherResult,ReserveCheckoutVoucherHandler}.php`.
  - `Application/SelectCheckoutProvider/{SelectCheckoutProviderCommand,SelectCheckoutProviderResult,SelectCheckoutProviderHandler}.php`.
  - `Infrastructure/PdoCheckoutAttemptRepository.php`, `Infrastructure/PdoCheckoutAttemptDirectory.php`,
    `Infrastructure/definitions.php`.
- **Pricing module additions**: `Application/PricingDecisionSnapshot.php` (+ static `of()`),
  `Application/PricingDecisionSnapshotRepository.php` (port), `Infrastructure/PdoPricingDecisionSnapshotRepository.php`.
- **Vouchers module additions**: `Domain/VoucherDecisionSnapshot.php` (+ static `of()`),
  `Domain/VoucherDecisionSnapshotRepository.php` (port), `Infrastructure/PdoVoucherDecisionSnapshotRepository.php`.
- **Providers module additions**: `Application/Routing/ProviderRoutingDecisionSnapshot.php` (+
  static `of()`), `Application/Routing/ProviderRoutingDecisionSnapshotRepository.php` (port),
  `Infrastructure/PdoProviderRoutingDecisionSnapshotRepository.php`.
- CLI: `bin/CreateCheckoutAttempt.php`, `bin/ResolveCheckoutPricing.php`,
  `bin/ReserveCheckoutVoucher.php`, `bin/SelectCheckoutProvider.php`,
  `bin/SetCheckoutAttemptStatus.php`, `bin/ListCheckoutAttempts.php`.
- Tests: `tests/Support/InMemoryCheckoutAttemptRepository.php`,
  `tests/Support/InMemoryPricingDecisionSnapshotRepository.php`,
  `tests/Support/InMemoryVoucherDecisionSnapshotRepository.php`,
  `tests/Support/InMemoryProviderRoutingDecisionSnapshotRepository.php`,
  `tests/Unit/Modules/Checkout/Domain/CheckoutAttemptStatusTest.php`,
  `tests/Unit/Modules/Checkout/Domain/CheckoutAttemptTest.php`,
  `tests/Unit/Modules/Checkout/Application/CheckoutAttemptHandlersTest.php`,
  `tests/Integration/CheckoutAttemptPersistenceTest.php` (CI-only, real-MySQL round trip).
- `.claude/PhaseResults/Phase18Result.md` (this file).

## Files Modified

- `src/Modules/Pricing/Application/ResolvedPrice.php` — gained `toArray(): array` for the
  pricing-snapshot `payload` column.
- `src/Modules/Providers/Application/Routing/RoutingDecision.php` — docblock corrected to
  reference `ProviderRoutingDecisionSnapshot` (was stale Phase 17 wording); no behavior change.
- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` gained
  `'src/Modules/Checkout/Infrastructure/definitions.php'`.
- `src/Modules/Pricing/Infrastructure/definitions.php`,
  `src/Modules/Vouchers/Infrastructure/definitions.php`,
  `src/Modules/Providers/Infrastructure/definitions.php` — each wired its new snapshot
  repository.
- `composer.json` — added `checkout:create|resolve-pricing|reserve-voucher|select-provider|
  set-status|list-attempts` scripts + `composer.lock` refreshed.
- `tests/Integration/MigrationRoundTripTest.php` — the 4 new tables added to the expected-table
  list.
- Documentation kept in lock-step (per `.claude/Rule.md` §5 and the standing doc rules):
  `.claude/docs/database-design.md` (new "Checkout + decision snapshots (Phase 18)" section, 46
  tables total, new migration row), `.claude/docs/database-diagram.md` + `.html` (new module-map
  node, new ER diagram section — 16/16 mermaid blocks in both files), `.claude/docs/db_explain.md`
  (new Phase 18 narrative section), `.claude/docs/Architecture.md` (§3 module table, new §8
  Checkout subsection, §9 pipeline, §13 deferred work), `.claude/docs/Phases.md` (row 18 → ☑,
  as-built scope rewritten), `.claude/Changelog.md` (new dated entry), `.claude/FileIndex.md`
  (new Checkout module row, updated Pricing/Vouchers/Providers rows, new bin/test-support rows),
  `.claude/knowledge/Knowledge.md` (new "Checkout attempts / decision snapshots (Phase 18)"
  section), `.claude/docs/Commands.md` (new `checkout:*` CLI documentation),
  `.claude/Orders.md` (new D21 row), `.claude/Voucher.md` (§8 rewritten as-built, §9/§10/§11
  updated).

## Implementation Details

- **`CheckoutAttempt` aggregate** (`src/Modules/Checkout/Domain/CheckoutAttempt.php`): `start()`
  trims (but does not case-normalize) `attemptReference`, upper-cases `country`/`currencyCode`.
  `transitionTo(CheckoutAttemptStatus $new, DateTimeImmutable $now, ?string $errorCode = null,
  ?string $errorMessage = null): ?DomainError` implements the exact rule order the user
  specified: (1) current terminal → reject (`checkout_attempt.terminal`); (2) `$new === $current`
  → no-op success; (3) `$new->isExit()` → allowed from any non-terminal; (4)
  `$new === ConvertedToPayment` → requires current `=== Confirmed`
  (`checkout_attempt.not_confirmed` otherwise); (5) else → requires `$new->rank() > $current->rank()`
  (`checkout_attempt.invalid_transition` otherwise).
- **`CheckoutAttemptStatus`**: `rank(): ?int` returns 1–9 for the happy path, `null` for the 4
  exits; `isExit()`; `isTerminal()` = `ConvertedToPayment` or any exit.
- **Idempotent-by-external-reference reuse**: `ReserveCheckoutVoucherHandler` passes
  `$attempt->attemptReference()` straight through as the voucher redemption's own
  `attempt_reference` — the same device introduced in Phase 17, applied one layer up.
- **Insert-only decision-snapshot ports**: `PricingDecisionSnapshotRepository`,
  `VoucherDecisionSnapshotRepository`, `ProviderRoutingDecisionSnapshotRepository` each expose
  only `save(): int` and `findByCheckoutAttemptId(): ?X` — no update method, enforcing "history
  never changes when rules change" at the type level.
- **Cross-module VO placement**: `PricingDecisionSnapshot` lives in `Pricing\Application`
  (depends on `ResolvedPrice`), `ProviderRoutingDecisionSnapshot` lives in
  `Providers\Application\Routing` (depends on `RoutingDecision`), `VoucherDecisionSnapshot` lives
  in `Vouchers\Domain` (depends only on `Voucher`/`VoucherRedemption`, both Domain types) — each
  snapshot VO sits beside the type it depends on rather than crossing the dependency direction.
- **JSON payloads**: `pricing_decision_snapshots.payload` = `ResolvedPrice::toArray()` (new this
  phase); `provider_routing_decision_snapshots.payload` = `RoutingDecision::toArray()` (reused
  as-is from Phase 10). Both VO constructors type the decoded payload param as
  `array<array-key, mixed>` (not `array<string, mixed>`) to match what `json_decode(..., true)`
  actually returns — a PHPStan-driven fix.
- All five Checkout handlers use the `Transactions::run(fn(): Result => ...)` pattern established
  in Phase 17 — validation and mutation execute atomically inside one transaction, returning the
  `Result` directly from the closure.

## Database Changes

New migration `20260911150001_create_checkout_and_decision_snapshot_tables.php` →
`CreateCheckoutAndDecisionSnapshotTables`, creating 4 tables (dropped in reverse order on
`down()`):

- **`checkout_attempts`** — `id` PK; `client_id` FK `clients` CASCADE; `client_user_ref` nullable;
  `attempt_reference` (`UNIQUE (client_id, attempt_reference)` = `uniq_checkout_attempts_client_ref`);
  `package_id` FK `packages` CASCADE; `country` FK `countries(code)` RESTRICT; `currency_code` FK
  `currencies(code)` RESTRICT; `purchase_type` / `payment_method` / `subscription_interval`
  nullable; `status` (default `started`); `error_code` / `error_message` nullable;
  `created_at`/`updated_at`; `abandoned_at`/`expired_at` nullable (reserved for Phase 29).
  `INDEX (client_id, status)` = `idx_checkout_attempts_client_status`; `INDEX (package_id)` =
  `idx_checkout_attempts_package`.
- **`pricing_decision_snapshots`** — `id` PK; `checkout_attempt_id` FK `checkout_attempts`
  CASCADE, `UNIQUE`; `client_id`/`package_id` FK CASCADE; `currency_code` FK RESTRICT;
  `amount_minor`; `source`; `payload` JSON; `created_at` only (no `updated_at` — write-once).
  `INDEX (client_id, package_id)`.
- **`voucher_decision_snapshots`** — `id` PK; `checkout_attempt_id` FK `checkout_attempts`
  CASCADE, `UNIQUE`; `client_id`/`voucher_id` FK CASCADE; `voucher_redemption_id` FK
  `voucher_redemptions` CASCADE, `UNIQUE`; `voucher_code`/`voucher_name`; `created_at` only.
  `INDEX (client_id, voucher_id)`.
- **`provider_routing_decision_snapshots`** — `id` PK; `checkout_attempt_id` FK
  `checkout_attempts` CASCADE, `UNIQUE`; `client_id`/`provider_account_id` FK CASCADE;
  `payment_method` nullable; `purchase_type` not null; `payload` JSON; `created_at` only.
  `INDEX (client_id, provider_account_id)`.

No changes to any existing table. Full design detail: `.claude/docs/database-design.md` →
"Checkout + decision snapshots (Phase 18)".

## API Changes

**No API changes.** All access is via the new `checkout:*` CLI scripts (`composer checkout:create`,
`checkout:resolve-pricing`, `checkout:reserve-voucher`, `checkout:select-provider`,
`checkout:set-status`, `checkout:list-attempts`) — no HTTP endpoint mounts this phase (deferred
to the payment-creation flow, Phase 20/24).

## Tests and Validation

**Tests created**
- `tests/Unit/Modules/Checkout/Domain/CheckoutAttemptStatusTest.php` — rank ordering, `isExit()`,
  `isTerminal()`.
- `tests/Unit/Modules/Checkout/Domain/CheckoutAttemptTest.php` — start state, skipping ranks, the
  same-status no-op, backward-move rejection, `converted_to_payment` gating on `confirmed`, an
  exit reachable from any non-terminal status (with error code/message), no transition once
  terminal, `commercialSnapshot()` contents.
- `tests/Unit/Modules/Checkout/Application/CheckoutAttemptHandlersTest.php` — full cross-module
  wiring: create → resolve pricing → reserve voucher → select provider happy path, the
  no-voucher path (`pricing_resolved → provider_selected` directly), idempotent re-create,
  invalid-transition rejection, terminal-lock rejection.
- `tests/Integration/CheckoutAttemptPersistenceTest.php` — a `checkout_attempts` row and its
  `pricing_decision_snapshots` row round-trip against real MySQL (CI-only; self-skips locally).
- `tests/Support/{InMemoryCheckoutAttemptRepository,InMemoryPricingDecisionSnapshotRepository,
  InMemoryVoucherDecisionSnapshotRepository,InMemoryProviderRoutingDecisionSnapshotRepository}.php`.

**Tests modified**
- `tests/Integration/MigrationRoundTripTest.php` — added `checkout_attempts`,
  `pricing_decision_snapshots`, `voucher_decision_snapshots`, `provider_routing_decision_snapshots`
  to the expected-table list.

**Tests actually executed (commands run, this session)**

```
$ composer ci
```
```
PHP CS Fixer 3.95.24 ... Found 0 of 593 files that can be fixed
Note: Using configuration file /Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon.
 [OK] No errors
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...............................................................  63 / 332 ( 18%)
............................................................... 126 / 332 ( 37%)
............................................................... 189 / 332 ( 56%)
............................................................... 252 / 332 ( 75%)
............................................................... 315 / 332 ( 94%)
.................                                               332 / 332 (100%)
Time: 00:00.121, Memory: 18.00 MB
OK (332 tests, 1354 assertions)
```

```
$ composer test:integration
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
SSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS                               35 / 35 (100%)
Time: 00:00.044, Memory: 10.00 MB
OK, but some tests were skipped!
Tests: 35, Assertions: 0, Skipped: 35.
```
(No local MySQL — every integration test, including the new `CheckoutAttemptPersistenceTest`,
self-skips via the `try { $pdo->query(...) } catch (PDOException)` guard in `setUp()`. These run
for real in GitHub Actions CI.)

`php -l` was run against every new file in the Checkout module, the Pricing/Vouchers/Providers
snapshot additions, the 6 new `bin/*.php` scripts, the 4 new test doubles, and the 4 new test
files — all reported "No syntax errors detected".

**CLI evidence (real captured output, each script invoked with no arguments to exercise its
usage-string path):**

```
$ php bin/CreateCheckoutAttempt.php
usage: php bin/CreateCheckoutAttempt.php --client=<slug|id> --attempt=<ref> --package=<id> --country=<CC> --currency=<ISO> [options]

$ php bin/ResolveCheckoutPricing.php
usage: php bin/ResolveCheckoutPricing.php --client=<slug|id> --attempt=<ref|id> [--device=] [--provider-account=] [--price-list=]

$ php bin/ReserveCheckoutVoucher.php
usage: php bin/ReserveCheckoutVoucher.php --client=<slug|id> --attempt=<ref|id> --code=<voucher> [options]

$ php bin/SelectCheckoutProvider.php
usage: php bin/SelectCheckoutProvider.php --client=<slug|id> --attempt=<ref|id> --mode=live|test [--device=]

$ php bin/SetCheckoutAttemptStatus.php
usage: php bin/SetCheckoutAttemptStatus.php --client=<slug|id> --attempt=<ref|id> --status=<status> [--error-code=] [--error-message=]

$ php bin/ListCheckoutAttempts.php
usage: php bin/ListCheckoutAttempts.php --client=<slug|id>
```

**End-to-end evidence (real captured output)** — a standalone script
(`/private/tmp/claude-502/.../scratchpad/CheckoutAttemptEvidence.php`) wiring real
`PriceResolver` / `ProviderRouter` / `ReserveVoucherRedemptionHandler` instances against
in-memory repositories, driving the full pre-payment lifecycle end to end:

```
=== Phase 18 — checkout attempt: pre-payment lifecycle ===

start (order-1)                                      -> #1 [started]
start again, same attempt (idempotent)               -> OK
resolve pricing                                      -> OK
reserve voucher WELCOME10                            -> OK
select provider (mode=test)                          -> OK
attempt status after full happy path                 -> provider_selected
invalid: move backward to pricing_resolved           -> ERROR  checkout_attempt.invalid_transition
valid: move to failed (an exit, from any non-terminal) -> OK
invalid: any further move once terminal              -> ERROR  checkout_attempt.terminal
final state                                          -> failed (provider_declined: Card declined)

=== No-voucher path: skips voucher_reserved ===

select provider directly (pricing_resolved -> provider_selected) -> OK
attempt #2 status                                    -> provider_selected
```

Also verified clean: `composer update --lock --no-install` ("Nothing to modify in lock file"),
`composer validate --no-check-publish` (`./composer.json is valid`).

## Technical Decisions

- **Q1 — `checkout_attempts` as the pre-payment anchor** (user-authored, not chosen from the
  presented options): the user wanted a central table tracking the full pre-payment lifecycle —
  how far a customer got, whether they abandoned checkout, which decisions were made — with
  `attempt_reference` as the external idempotent key and `id` as the internal relational anchor,
  and the final payment copying only immutable commercial data from it. Implemented exactly as
  specified.
- **Q2 — new `Checkout` module** (Option 1): a dedicated module for the anchor, rather than
  folding it into Payments or Vouchers, keeps the pre-payment orchestration separable from the
  eventual Payments aggregate (Phase 20).
- **Q3 — monotonic-rank status machine with allowed skipping** (user-authored transition rules,
  not a letter choice): 9 ranked statuses + 4 unranked exits, skip-allowed, backward-rejected,
  `converted_to_payment` gated on `confirmed`, terminal-once-reached. Only the transitions the
  user named as "actually needed now" were wired to real handlers; the rest are modelled (rank +
  guard + test coverage) for Payments/provider-adapter phases to drive.
- **Q4 — thin `voucher_decision_snapshots`, three per-module tables** (Option 1): each
  decision-snapshot VO lives beside the type it depends on (`ResolvedPrice`, `RoutingDecision`)
  rather than centralizing snapshot logic in `Checkout`, keeping the dependency direction intact;
  `voucher_decision_snapshots` stores only identity (code/name) because the amounts already live
  immutably on `voucher_redemptions`.
- **Q5 — one handler per lifecycle step + `commercialSnapshot()`** (Option 1): mirrors the
  Phase 16/17 pattern of granular, single-purpose, audited handlers rather than one large
  orchestrator, keeping each step independently testable and transactional.

## Problems Encountered

- `composer stan` initially found 6 errors: 4 CLI scripts (`ResolveCheckoutPricing.php`,
  `ReserveCheckoutVoucher.php`, `SelectCheckoutProvider.php`, `SetCheckoutAttemptStatus.php`)
  passed a nullable `?int` checkout-attempt id where the handler command required a non-nullable
  `int`; 2 Pdo repositories (`PdoPricingDecisionSnapshotRepository`,
  `PdoProviderRoutingDecisionSnapshotRepository`) passed `json_decode()`'s `array<mixed>` where
  the VO constructor declared `array<string, mixed>`.
- One `Edit` call on `RoutingDecision.php`'s docblock failed once with "String to replace not
  found" on the first attempt.
- `CheckoutAttemptTest::commercialSnapshotReflectsTheAttemptsOwnContext` initially asserted the
  attempt reference as `'ORDER-1'` (upper-cased), which failed because `CheckoutAttempt::start()`
  only trims the reference, never changes its case.

## Resolutions

- Added `assert($attemptId !== null);` immediately after each CLI script's id-or-reference
  resolution block, narrowing the type for PHPStan without changing runtime behavior (the
  preceding `if`/`else` already guarantees non-null).
- Loosened the two decision-snapshot VOs' `$payload` constructor parameter type from
  `array<string, mixed>` to `array<array-key, mixed>` (matching what `json_decode(..., true)`
  actually returns), updating the corresponding docblocks.
- Retried the `RoutingDecision.php` edit with a shorter, precisely-copied fragment; it succeeded.
- Corrected the test to expect `'order-1'` (lower-case, matching `start()`'s actual trim-only
  behavior) instead of changing the domain code to uppercase — the trim-only behavior is correct
  by design (an opaque caller token must not have its case altered).

## Deferred Work

- `checkout_attempts.abandoned_at` / `.expired_at` and their automatic-detection sweep — Phase 29
  (background jobs), same sweep as the Phase 17 stale-voucher-reservation cleanup.
- The `provider_checkout_created` → `redirected_to_provider` → `returned_from_provider` →
  `confirmed` → `converted_to_payment` transitions — modelled (rank + guards + tests) but not
  driven by any real caller until Payments (Phase 20) and the provider adapters (Phase 21+)
  exist.
- `checkout_attempts → payments` conversion itself (consuming `commercialSnapshot()`) — Phase 20.
- No HTTP endpoint for the checkout flow yet — mounts with the payment-creation flow (Phase 20 /
  24).
- `POST /api/v1/vouchers/validate` — still Phase 19, unaffected by this phase.

## Final Result

Gomrok now has a `Checkout` module that makes the entire pre-payment flow — pricing resolution,
optional voucher reservation, provider selection — a persisted, auditable, idempotent-by-reference
sequence of steps anchored by `checkout_attempts`, with three write-once decision-snapshot tables
(one per owning module) that freeze exactly what was decided and why, immune to later rule
changes. `composer ci` is green (592 files analyzed by PHPStan with 0 errors, 332 unit tests /
1354 assertions passing); the CI-only integration suite (35 tests) self-skips locally for lack of
MySQL and is expected to run for real in GitHub Actions. All required documentation
(`database-design.md`, `database-diagram.md`/`.html`, `db_explain.md`, `Architecture.md`,
`Phases.md`, `Changelog.md`, `FileIndex.md`, `Knowledge.md`, `Commands.md`, `Orders.md`,
`Voucher.md`, `PhaseDecisions.md`) is in lock-step with the schema and code as built.

**Next recommended phase:** Phase 19 — Resolution API endpoints (`POST /api/v1/vouchers/validate`
and mounting the package/pricing resolution endpoints for real client consumption).
