# Q: Phase 20 — Payments module: aggregate & lifecycle (completion summary)

Phase 20 is complete. Here's what was built, verified, and documented.

## What was implemented

A new `Payments` module: the payment record and its internal status state machine, with no real
provider adapter yet (Phase 21+) and no HTTP endpoint yet (Phase 24) — purely the aggregate,
schema, and lifecycle.

`payments` is created from exactly one confirmed `checkout_attempts` row (Q1), realizing the
hand-off Phase 18 was built for. `checkout_attempt_id` is a required, `UNIQUE` FK;
`amount_minor` is frozen at creation from the checkout attempt's pricing/voucher decision
snapshots and never re-derived. `PaymentStatus` (Q2) is an explicit allowed-next-statuses graph
per status — `paid` branches to `refunded`/`partially_refunded`/`disputed`, `disputed` resolves
to `chargeback` or back to `paid`. `payment_attempts` → `provider_transactions` is a three-tier
model (Q3): one row per distinct "try" against a provider, one immutable row per raw call/response
under that try. `provider_customers` and `gateway_references` (Q4) round out the schema — a
durable customer identity vs. a generic, provider-agnostic reverse-lookup table. Four handlers
(Q5) — `CreatePaymentHandler`, `RecordProviderTransactionHandler`, `ChangePaymentStatusHandler`,
`LinkProviderCustomerHandler` — implement the lifecycle.

**A real mid-phase correction:** Q2's first answer ("no rule engine yet") was flagged
immediately as conflicting with this phase's own exit criterion ("rejection of illegal
transitions tested") — with no rule, there's nothing to reject. This was raised back to you
before any code was written; you switched to the explicit allowed-transitions graph, which is
what got implemented. The full back-and-forth is recorded in `PhaseDecisions.md`.

## Files created

- Migration `20260911180001_create_payment_tables.php` (5 tables).
- New `Payments` module: `Domain/{PaymentStatus,Payment,PaymentRepository,PaymentAttemptStatus,
  PaymentAttempt,PaymentAttemptRepository,ProviderTransaction,ProviderTransactionRepository,
  ProviderCustomer,ProviderCustomerRepository,GatewayReferenceType,GatewayReference,
  GatewayReferenceRepository}.php`; `Application/{PaymentAuditSnapshot,PaymentSummary,
  PaymentDirectory}.php` + 4 use-case folders; `Infrastructure/` (6 `Pdo*` adapters).
- CLI: `bin/{CreatePayment,RecordProviderTransaction,SetPaymentStatus,LinkProviderCustomer,
  ListPayments}.php`.
- Tests: 5 new in-memory doubles, 3 domain test files, 4 application test files, 1 integration
  test (`PaymentPersistenceTest`, CI-only) — 34 new tests total.
- `.claude/PhaseResults/Phase20Result.md`.

## Files updated

`ContainerFactory` wired the new module; `composer.json` gained the `payment:*` scripts;
`MigrationRoundTripTest` extended. Every governance doc updated in lock-step: `database-design.md`
(51 tables + new section), `database-diagram.md`/`.html` (17/17 mermaid blocks), `db_explain.md`
(including the Q2 back-and-forth), `Architecture.md` (§3/§8/§9/§13), `Phases.md` (row 20 ☑),
`Changelog.md`, `FileIndex.md`, `Knowledge.md`, `Commands.md`, `Orders.md` (D23). Also fixed a
pre-existing doc-accuracy slip noticed in passing: `checkout:*` CLI names were mis-documented as
`checkout:create`/`checkout:list-attempts` in several live docs (actual names: `checkout:start`/
`checkout:list`) — corrected in `FileIndex.md`, `Commands.md`, `Phases.md`; historical entries
(Changelog's Phase 18 entry, `Phase18Result.md`) left untouched per the no-rewrite rule.

## Database changes

5 new tables, all additive, no existing-table changes: `payments`, `payment_attempts`,
`provider_transactions`, `provider_customers`, `gateway_references`. Full detail in
`.claude/docs/database-design.md` → "Payments — aggregate & lifecycle (Phase 20)".

## Tests — how to run and real results

```
composer ci
```
→ PHPStan: 652 files, **0 errors**. PHPUnit: **376 tests, 1507 assertions, OK** (+34 new).

```
composer test:integration
```
→ **36 tests, 36 skipped** (no local MySQL; the new `PaymentPersistenceTest` self-skips too —
runs for real in GitHub Actions CI).

`php -l` clean on every new file. `composer validate` → valid; `composer update --lock
--no-install` → nothing to modify.

## Captured evidence

CLI usage strings captured for all 5 new scripts. A standalone scratchpad script drove two full
payment lifecycles through the real handlers (in-memory repositories); real captured output:

```
create payment from confirmed checkout attempt              -> #1 [created] amount=2900 EUR
create payment again, same attempt (idempotent)              -> OK
record: authorize call -> pending                            -> OK
record: authorize succeeds -> authorized                     -> OK (attempt #1, same attempt reused)
record: capture call -> paid                                 -> OK
invalid: paid -> pending (backward)                           -> ERROR  payment.invalid_transition
valid: paid -> refunded                                       -> OK
invalid: any further move once terminal                       -> ERROR  payment.terminal
final payment status                                          -> refunded

=== Second payment: dispute resolves back to paid ===
paid -> disputed                                              -> OK
disputed -> paid (resolved in merchant favor)                 -> OK
payment #2 final status                                       -> paid
```

This is a domain/CLI phase with no admin UI — the real captured output above and in
`Phase20Result.md` is the evidence for this phase, per the Visual and Output Verification Rule.

## Known limitations

- No real `PaymentProviderPort` or status mapping yet — every transition this phase is
  caller-supplied (Phase 21+).
- No HTTP endpoint yet (Phase 24).
- `gateway_references` has no real writer yet (schema + repository exist ahead of a caller,
  same pattern as `checkout_attempts.abandoned_at` in Phase 18); `subscription_id` column
  deferred to Phase 26; a dedicated `refunds` table deferred to Phase 24.

## Next recommended phase

**Phase 21 — Provider adapter port & Stripe adapter.**
