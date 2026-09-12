# Phase 20 — Payments module: aggregate & lifecycle

## Execution Summary

- Phase: 20 — Payments module: aggregate & lifecycle
- Start Datetime: 2026-09-11 16:50
- End Datetime: 2026-09-11 18:34
- Estimated Duration: 4–6h
- Actual Duration: 1h 44m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

A new `Payments` module was created: the payment record and its internal status state machine,
built with no real provider adapter yet (Phase 21+) and no HTTP endpoint yet (Phase 24) — purely
the aggregate, schema, and lifecycle this phase.

`payments` is created from exactly one confirmed `checkout_attempts` row (Q1) — realizing the
hand-off Phase 18's `commercialSnapshot()`/`converted_to_payment` were built for.
`checkout_attempt_id` is a required, `UNIQUE` FK; `amount_minor` is frozen at creation from the
checkout attempt's pricing/voucher decision snapshots (the voucher's `payable_minor` when one was
reserved, else the pricing snapshot's `amount_minor`) and never re-derived. `PaymentStatus` (Q2)
is an explicit allowed-next-statuses graph per status, not a single rank, because a payment's
lifecycle genuinely branches (`paid` → `refunded`/`partially_refunded`/`disputed`; `disputed` →
`chargeback` or back to `paid`). `payment_attempts` → `provider_transactions` is a deliberate
three-tier model (Q3) matching CLAUDE.md's three distinct Required Database Concepts — one row
per distinct "try" against a provider, one immutable row per raw call/response under that try.
`provider_customers` and `gateway_references` (Q4) round out the schema: a durable customer
identity vs. a generic, provider-agnostic reverse-lookup table. Four handlers (Q5) —
`CreatePaymentHandler`, `RecordProviderTransactionHandler`, `ChangePaymentStatusHandler`,
`LinkProviderCustomerHandler` — implement the lifecycle, matching every prior module's
one-handler-per-step pattern.

**A real mid-phase correction is worth recording explicitly:** when Q2 was first answered, the
option selected ("no rule engine yet — `transitionTo()` accepts anything") was flagged
immediately as conflicting with this same phase's own exit criterion ("rejection of illegal
transitions tested") — with no rule at all, there is nothing to reject. This was raised back to
the user via a clarifying question before any code was written; the explicit allowed-transitions
graph was chosen instead, and the change (with the reason) is recorded in
`.claude/PhaseResults/PhaseDecisions.md`.

## Files Created

- `src/Database/Migrations/20260911180001_create_payment_tables.php` →
  `Gomrok\Database\Migrations\CreatePaymentTables` (5 tables).
- **Payments module** (`src/Modules/Payments/`):
  - `Domain/{PaymentStatus,Payment,PaymentRepository}.php`.
  - `Domain/{PaymentAttemptStatus,PaymentAttempt,PaymentAttemptRepository}.php`.
  - `Domain/{ProviderTransaction,ProviderTransactionRepository}.php`.
  - `Domain/{ProviderCustomer,ProviderCustomerRepository}.php`.
  - `Domain/{GatewayReferenceType,GatewayReference,GatewayReferenceRepository}.php`.
  - `Application/{PaymentAuditSnapshot,PaymentSummary,PaymentDirectory}.php`.
  - `Application/CreatePayment/{CreatePaymentCommand,CreatePaymentResult,CreatePaymentHandler}.php`.
  - `Application/RecordProviderTransaction/{RecordProviderTransactionCommand,RecordProviderTransactionResult,RecordProviderTransactionHandler}.php`.
  - `Application/ChangePaymentStatus/ChangePaymentStatusHandler.php`.
  - `Application/LinkProviderCustomer/{LinkProviderCustomerCommand,LinkProviderCustomerResult,LinkProviderCustomerHandler}.php`.
  - `Infrastructure/{PdoPaymentRepository,PdoPaymentAttemptRepository,PdoProviderTransactionRepository,
    PdoProviderCustomerRepository,PdoGatewayReferenceRepository,PdoPaymentDirectory,definitions}.php`.
- CLI: `bin/{CreatePayment,RecordProviderTransaction,SetPaymentStatus,LinkProviderCustomer,
  ListPayments}.php`.
- Tests: `tests/Support/{InMemoryPaymentRepository,InMemoryPaymentAttemptRepository,
  InMemoryProviderTransactionRepository,InMemoryProviderCustomerRepository,
  InMemoryGatewayReferenceRepository}.php`;
  `tests/Unit/Modules/Payments/Domain/{PaymentStatusTest,PaymentTest,PaymentAttemptTest}.php`;
  `tests/Unit/Modules/Payments/Application/{CreatePaymentHandlerTest,
  RecordProviderTransactionHandlerTest,ChangePaymentStatusHandlerTest,
  LinkProviderCustomerHandlerTest}.php`; `tests/Integration/PaymentPersistenceTest.php`
  (CI-only, real-MySQL round trip).
- `.claude/PhaseResults/Phase20Result.md` (this file).

## Files Modified

- `src/Bootstrap/ContainerFactory.php` — `MODULE_DEFINITIONS` gained
  `'src/Modules/Payments/Infrastructure/definitions.php'`.
- `composer.json` — added `payment:create|record-transaction|set-status|link-customer|list`
  scripts + descriptions; `composer.lock` refreshed.
- `tests/Integration/MigrationRoundTripTest.php` — the 5 new tables added to the expected-table
  list.
- Documentation kept in lock-step (per `.claude/Rule.md` §5 and the standing doc rules):
  `.claude/docs/database-design.md` (new "Payments — aggregate & lifecycle (Phase 20)" section,
  51 tables total, new migration row), `.claude/docs/database-diagram.md` + `.html` (new module-map
  node, new ER diagram section — 17/17 mermaid blocks in both files), `.claude/docs/db_explain.md`
  (new Phase 20 narrative section, including the Q2 back-and-forth), `.claude/docs/Architecture.md`
  (§3, new §8 Payments subsection, §9 pipeline, §13 deferred work), `.claude/docs/Phases.md` (row
  20 → ☑, as-built scope), `.claude/Changelog.md` (new dated entry), `.claude/FileIndex.md` (new
  Payments module row, new bin/test-support rows), `.claude/knowledge/Knowledge.md` (new
  "Payments — aggregate & lifecycle (Phase 20)" section), `.claude/docs/Commands.md` (new
  `payment:*` CLI documentation), `.claude/Orders.md` (new D23 row).
- **Minor doc-accuracy fix noticed in passing, unrelated to this phase's own scope:** while
  editing `.claude/docs/Commands.md` for the new CLI section, found that the Checkout module's
  `composer` script names had been mis-documented in several "live" docs as `checkout:create` /
  `checkout:list-attempts` when the actual `composer.json` names (set in Phase 18) are
  `checkout:start` / `checkout:list`. Corrected `.claude/FileIndex.md`, `.claude/docs/Commands.md`,
  and `.claude/docs/Phases.md` to match the real script names. Also removed a stale
  `.claude/docs/Commands.md` line claiming `GET /api/v1/vouchers/validate` "does not exist yet
  (Phase 19)" — left over from before Phase 19 actually shipped it. Per the no-rewrite rule,
  `.claude/Changelog.md`'s Phase 18 entry and `.claude/PhaseResults/Phase18Result.md` were left
  untouched as historical records of what was written at the time.

## Implementation Details

- **`Payment` aggregate** (`src/Modules/Payments/Domain/Payment.php`): `create()` copies every
  field from a confirmed `CheckoutAttempt` plus the computed `amountMinor`; `transitionTo(PaymentStatus
  $new, DateTimeImmutable $now, ?string $errorCode = null, ?string $errorMessage = null): ?DomainError`
  checks terminal *before* same-status (mirroring `CheckoutAttempt::transitionTo`'s exact order
  from Phase 18) — a repeat call of the current terminal status is rejected
  (`payment.terminal`), not treated as a no-op; otherwise `$new === $this->status` is an
  idempotent no-op, and any `$new` not in `$this->status->allowedNextStatuses()` is
  `payment.invalid_transition`.
- **`PaymentStatus`**: `allowedNextStatuses(): array` returns the Q2 graph per case;
  `isTerminal(): bool` = `allowedNextStatuses() === []`.
- **`PaymentAttempt`**: `start()` factory; `succeed()`/`fail()` both route through a private
  `complete()` that guards "must currently be `Started`" (`payment_attempt.already_completed`
  otherwise) — an attempt can only be completed once.
- **`CreatePaymentHandler`**: cross-module dependencies on `Checkout\Domain\CheckoutAttemptRepository`
  (to load and mutate the real aggregate — needed because `transitionTo()` is a Domain method),
  `Pricing\Application\PricingDecisionSnapshotRepository`, and
  `Vouchers\Domain\{VoucherDecisionSnapshotRepository,VoucherRedemptionRepository}` — the same
  "Application handler depends on another module's Domain port when it needs to load and mutate
  that module's aggregate" pattern the Phase 18 Checkout handlers already established for
  Vouchers. Idempotent by `checkout_attempt_id`: a repeat call returns the existing payment
  unchanged rather than erroring or duplicating.
- **`RecordProviderTransactionHandler`**: reuses `PaymentAttemptRepository::findLatestForPayment()`
  when its status is still `Started`, else opens `attempt_number = count + 1`; records one
  `ProviderTransaction` row under that attempt; applies `attemptOutcome`
  (`succeeded`/`failed`, explicit, never inferred from `newStatus`) only when provided; the
  `Payment::transitionTo()` call happens outside the `Transactions::run()` block (validated
  first, mutated inside), matching the Checkout/Vouchers precedent.
- **`ChangePaymentStatusHandler`**: a near-verbatim mirror of
  `ChangeCheckoutAttemptStatusHandler` (Phase 18) — the escape hatch for any transition not
  driven by `RecordProviderTransactionHandler`.
- **`LinkProviderCustomerHandler`**: idempotent by `(provider_account_id, provider_customer_id)`
  — a repeat call for the same client returns the existing row unchanged; a different client
  claiming the same provider customer id is `provider_customer.already_linked_to_another_client`.
- **`GatewayReferenceType`**: a small, generic enum (`checkout_session`/`payment_intent`/`order`/
  `transaction`/`subscription`/`customer`/`other`) — deliberately not one case per provider's id
  kind, so a new provider or reference shape (Phase 22/23) needs no enum or schema change.

## Database Changes

New migration `20260911180001_create_payment_tables.php` → `CreatePaymentTables`, creating 5
tables (dropped in reverse order on `down()`):

- **`payments`** — `id` PK; `client_id` FK `clients` CASCADE; `checkout_attempt_id` FK
  `checkout_attempts` CASCADE, `UNIQUE`; `client_user_ref` nullable; `package_id` FK `packages`
  CASCADE; `country` FK `countries(code)` RESTRICT; `currency_code` FK `currencies(code)`
  RESTRICT; `amount_minor`; `purchase_type`; `payment_method`/`subscription_interval` nullable;
  `status` (default `created`); `error_code`/`error_message` nullable; timestamps.
  `INDEX (client_id, status)`; `INDEX (package_id)`.
- **`payment_attempts`** — `id` PK; `payment_id` FK `payments` CASCADE; `provider_account_id` FK
  `provider_accounts` CASCADE; `attempt_number` (`UNIQUE (payment_id, attempt_number)`); `status`
  (default `started`); `payment_method` nullable; `error_code`/`error_message` nullable;
  timestamps. `INDEX (provider_account_id)`.
- **`provider_transactions`** — `id` PK; `payment_attempt_id` FK `payment_attempts` CASCADE;
  `kind`; `request_payload`/`response_payload` JSON nullable; `provider_status_raw`;
  `created_at` only (write-once). `INDEX (payment_attempt_id)`.
- **`provider_customers`** — `id` PK; `client_id` FK `clients` CASCADE; `provider_account_id` FK
  `provider_accounts` CASCADE; `client_user_ref`; `provider_customer_id`
  (`UNIQUE (provider_account_id, provider_customer_id)`); timestamps.
  `INDEX (client_id, client_user_ref)`.
- **`gateway_references`** — `id` PK; `client_id` FK `clients` CASCADE; `provider_account_id` FK
  `provider_accounts` CASCADE; `reference_type`; `reference_value`
  (`UNIQUE (provider_account_id, reference_type, reference_value)`); `payment_id` FK `payments`
  CASCADE, nullable; `created_at` only (write-once). `INDEX (payment_id)`.

No changes to any existing table. Full design detail: `.claude/docs/database-design.md` →
"Payments — aggregate & lifecycle (Phase 20)".

## API Changes

**No API changes.** All access is via the new `payment:*` CLI scripts. No HTTP endpoint mounts
this phase — deferred to Phase 24 (the payment-creation flow).

## Tests and Validation

**Tests created:** 34 new tests total —
`tests/Unit/Modules/Payments/Domain/PaymentStatusTest.php` (3 — the full graph, terminal set,
non-terminal set), `PaymentTest.php` (7 — created state, happy path to refunded, dispute
resolving back to paid, same-status no-op, unreachable-status rejection, terminal-lock including
a repeat of the current terminal status, failed captures error fields), `PaymentAttemptTest.php`
(4 — started state, succeed once, fail once with error fields, cannot complete twice);
`tests/Unit/Modules/Payments/Application/CreatePaymentHandlerTest.php` (5 — creates from a
confirmed attempt and converts it, voucher-adjusted amount, idempotent replay, rejects an
unconfirmed attempt, unknown attempt is not-found), `RecordProviderTransactionHandlerTest.php`
(5 — starts a new attempt, reuses the started attempt for a second transaction, starts a new
attempt number after the previous one completed, illegal transition persists nothing, unknown
status is a validation error), `ChangePaymentStatusHandlerTest.php` (3), and
`LinkProviderCustomerHandlerTest.php` (3); `tests/Integration/PaymentPersistenceTest.php` (1,
CI-only).

**Tests modified:** `tests/Integration/MigrationRoundTripTest.php` — added `payments`,
`payment_attempts`, `provider_transactions`, `provider_customers`, `gateway_references` to the
expected-table list.

**Commands actually run, this session:**

```
$ composer ci
```
```
PHP CS Fixer 3.95.24 ... Found 0 of 653 files that can be fixed
Note: Using configuration file /Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon.
 [OK] No errors
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...............................................................  63 / 376 ( 16%)
............................................................... 126 / 376 ( 33%)
............................................................... 189 / 376 ( 50%)
............................................................... 252 / 376 ( 67%)
............................................................... 315 / 376 ( 83%)
.............................................................   376 / 376 (100%)
Time: 00:00.185, Memory: 20.00 MB
OK (376 tests, 1507 assertions)
```

```
$ composer test:integration
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
SSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSSS                              36 / 36 (100%)
Time: 00:00.105, Memory: 10.00 MB
OK, but some tests were skipped!
Tests: 36, Assertions: 0, Skipped: 36.
```
(No local MySQL — every integration test, including the new `PaymentPersistenceTest`, self-skips
via the `try { $pdo->query(...) } catch (PDOException)` guard in `setUp()`. These run for real in
GitHub Actions CI.)

`php -l` was run against every new source file, the migration, and every new test file — all
reported "No syntax errors detected". `composer validate --no-check-publish` → valid;
`composer update --lock --no-install` → "Nothing to modify in lock file".

**CLI evidence (real captured output, each script invoked with no arguments):**

```
$ php bin/CreatePayment.php
usage: php bin/CreatePayment.php --client=<slug|id> --attempt=<ref|id>

$ php bin/RecordProviderTransaction.php
usage: php bin/RecordProviderTransaction.php --client=<slug|id> --payment=<id> --provider-account=<id> --kind=<kind> --status-raw=<raw> --new-status=<status> [options]

$ php bin/SetPaymentStatus.php
usage: php bin/SetPaymentStatus.php --client=<slug|id> --payment=<id> --status=<status> [--error-code=] [--error-message=]

$ php bin/LinkProviderCustomer.php
usage: php bin/LinkProviderCustomer.php --client=<slug|id> --provider-account=<id> --client-user=<ref> --provider-customer-id=<id>

$ php bin/ListPayments.php
usage: php bin/ListPayments.php --client=<slug|id>
```

**End-to-end evidence (real captured output)** — a standalone script wiring real handlers against
in-memory repositories, driving two full payment lifecycles:

```
=== Phase 20 — payment lifecycle ===

create payment from confirmed checkout attempt              -> #1 [created] amount=2900 EUR
create payment again, same attempt (idempotent)             -> OK
record: authorize call -> pending                           -> OK
record: authorize succeeds -> authorized                    -> OK (attempt #1, same attempt reused)
record: capture call -> paid                                -> OK
invalid: paid -> pending (backward)                          -> ERROR  payment.invalid_transition
valid: paid -> refunded                                      -> OK
invalid: any further move once terminal                      -> ERROR  payment.terminal
final payment status                                         -> refunded
link provider customer                                       -> row #1
link same provider customer again (idempotent)               -> OK

=== Second payment: dispute resolves back to paid ===

paid -> disputed                                             -> OK
disputed -> paid (resolved in merchant favor)                -> OK
payment #2 final status                                      -> paid
```

## Technical Decisions

- **Q1 — payment requires a confirmed checkout attempt:** the natural completion of the Phase 18
  design's stated purpose; keeps `payments.checkout_attempt_id` a clean, always-populated,
  `UNIQUE` FK rather than an ambiguous nullable "preferred" relationship.
- **Q2 — explicit allowed-transitions graph, adopted after a flagged conflict:** the first
  answer ("no rule engine yet") would have made this phase's own exit criterion unsatisfiable;
  raised back to the user immediately, resolved by switching to the recommended explicit-graph
  design before any code was written.
- **Q3 — three-tier `payments`/`payment_attempts`/`provider_transactions`:** matches CLAUDE.md's
  three distinct named concepts and gives retries (new attempts) a home separate from individual
  provider round-trips (transactions under one attempt).
- **Q4 — generic `gateway_references` + separate `provider_customers`:** the mechanism CLAUDE.md's
  Gateway Reference Lookup Rule calls for, and keeps a durable customer identity (reused across
  many payments) conceptually distinct from a one-off transaction reference.
- **Q5 — one handler per lifecycle step:** consistent with every module built so far, and what
  makes the CLI-driven captured evidence for this phase's completion possible.

## Problems Encountered

- Q2's first recorded answer ("no rule engine yet") directly conflicted with this phase's own
  stated exit criterion.
- `composer stan` flagged two `hydrate()` calls in `PdoGatewayReferenceRepository`/
  `PdoProviderTransactionRepository`'s list methods passing `mixed` (from `PDOStatement::fetch()`)
  where `array<mixed>` was expected — the loops were missing the `is_array($row)` guard every
  other list method in the codebase already uses.
- One nullsafe-on-already-narrowed-type PHPStan hit in `RecordProviderTransactionHandlerTest`
  after a prior `assertSame` had already narrowed the expression (the same class of issue noted
  in this project's memory from Phase 16).

## Resolutions

- The conflict was raised back to the user via a clarifying question before implementing
  anything; the explicit allowed-transitions graph (the originally recommended option) was
  adopted, and the full back-and-forth is recorded verbatim in `PhaseDecisions.md` and
  summarized in `Architecture.md` / `db_explain.md` / `Knowledge.md` for future reference.
- Added the missing `is_array($row)` guard inside both `while` loops before calling `hydrate()`.
- Replaced the redundant nullsafe (`$attempt?->attemptNumber()`) with a direct `->` access,
  matching the established fix pattern from earlier phases rather than adding a redundant
  `assertNotNull()` (which PHPStan also flagged as always-true).

## Deferred Work

- A real `PaymentProviderPort` and per-provider status mapping — Phase 21 (the port), then
  Phases 21–23 (Stripe, Mollie/PayPal, Ziraat adapters). Every status transition in this phase is
  caller-supplied, not derived from an actual provider response.
- Any HTTP endpoint for payments (`POST /api/v1/payments`, `GET .../status`, `.../cancel`,
  `.../refund`, `.../capture`) — Phase 24, the payment-creation flow.
- A `gateway_references` writer wired to a real caller — the repository and schema exist ahead of
  a real caller (the same "ahead of a real caller" pattern already used for
  `checkout_attempts.abandoned_at`/`expired_at` in Phase 18); expected to be populated by
  `RecordProviderTransactionHandler` once Phase 21+ adapters return reference ids to record.
- `gateway_references.subscription_id` — Phase 26, once `subscriptions` exists, as an additive
  nullable column.
- A dedicated `refunds` table for actual capture/refund action records — Phase 24.
- `provider_checkout_created` / `redirected_to_provider` / `returned_from_provider` / `confirmed`
  transitions on `checkout_attempts` remain modelled but undriven until the provider adapters
  exist (Phase 21+) — unaffected by this phase.

## Final Result

Gomrok now has a `Payments` module with a complete, tested internal lifecycle: a payment is
created from exactly one confirmed checkout attempt with its amount frozen at that moment,
advances through an explicit branching status graph as raw provider transactions are recorded
against retry-aware attempts, and durable provider customer identities and generic gateway
references round out the schema for later provider integration. `composer ci` is green (652 files analyzed by PHPStan with 0 errors, 376 unit tests / 1507
assertions passing, up from 346 tests / 1407 assertions before this phase — 34 new tests); the
CI-only integration suite (36 tests, up from 35) is unaffected in kind and continues to self-skip
locally. All required documentation is in
lock-step with the code as built, including a mid-phase design correction recorded in full.

**Next recommended phase:** Phase 21 — Provider adapter port & Stripe adapter.
