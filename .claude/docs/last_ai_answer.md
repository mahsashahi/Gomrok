# Q: Phase 18 — Decision snapshots (completion summary)

Phase 18 is complete. Here's what was built, verified, and documented.

## What was implemented

A new **`Checkout` module** anchored by **`checkout_attempts`** — the parent record for the whole
pre-payment lifecycle (Phase 18 Q1, a substantial expansion of the originally proposed design,
fully specified by you). `attempt_reference` is the external, caller-supplied idempotent key;
`checkout_attempts.id` is the internal relational anchor. Three write-once decision-snapshot
tables sit beside it, one per owning module (Q4): `pricing_decision_snapshots` (Pricing),
`voucher_decision_snapshots` (Vouchers, thin — identity only, no amounts), and
`provider_routing_decision_snapshots` (Providers) — each `UNIQUE (checkout_attempt_id)`, and none
of the three repository ports expose an update method.

A new `CheckoutAttemptStatus` enum (Q3, fully dictated by you rather than picked from an option
letter) implements a monotonic-rank state machine: 9 ranked happy-path statuses (`started` →
`pricing_resolved` → `voucher_reserved` → `provider_selected` → `provider_checkout_created` →
`redirected_to_provider` → `returned_from_provider` → `confirmed` → `converted_to_payment`) plus
4 unranked exit statuses (`failed`/`canceled`/`expired`/`abandoned`) reachable from any
non-terminal status. Skipping ranks is allowed (no voucher used ⇒ `pricing_resolved →
provider_selected` directly); `converted_to_payment` requires `confirmed` first; terminal once
reached. Only the transitions you named as "needed now" are wired to real handlers; the rest are
modelled (rank + guards + tests) for Payments (Phase 20) and the provider adapters (Phase 21+).

Five handlers (Q5) — `CreateCheckoutAttemptHandler`, `ResolveCheckoutPricingHandler`,
`ReserveCheckoutVoucherHandler`, `SelectCheckoutProviderHandler`,
`ChangeCheckoutAttemptStatusHandler` — each advance the status and write the owning module's
snapshot inside one transaction. `CheckoutAttempt::commercialSnapshot()` returns the attempt's own
immutable commercial context, the exact shape a future `payments` row will copy at conversion.

## Files created

- Migration `20260911150001_create_checkout_and_decision_snapshot_tables.php` (4 tables).
- New `Checkout` module: `Domain/{CheckoutAttemptStatus,CheckoutAttempt,CheckoutAttemptRepository}.php`;
  `Application/{CheckoutAuditSnapshot,CheckoutAttemptSummary,CheckoutAttemptDirectory}.php`;
  `Application/CreateCheckoutAttempt/`, `Application/ChangeCheckoutAttemptStatus/`,
  `Application/ResolveCheckoutPricing/`, `Application/ReserveCheckoutVoucher/`,
  `Application/SelectCheckoutProvider/`; `Infrastructure/{PdoCheckoutAttemptRepository,
  PdoCheckoutAttemptDirectory,definitions}.php`.
- Pricing: `PricingDecisionSnapshot` (+`of()`), `PricingDecisionSnapshotRepository`,
  `PdoPricingDecisionSnapshotRepository`.
- Vouchers: `VoucherDecisionSnapshot` (+`of()`), `VoucherDecisionSnapshotRepository`,
  `PdoVoucherDecisionSnapshotRepository`.
- Providers: `ProviderRoutingDecisionSnapshot` (+`of()`), `ProviderRoutingDecisionSnapshotRepository`,
  `PdoProviderRoutingDecisionSnapshotRepository`.
- CLI: `bin/{CreateCheckoutAttempt,ResolveCheckoutPricing,ReserveCheckoutVoucher,
  SelectCheckoutProvider,SetCheckoutAttemptStatus,ListCheckoutAttempts}.php`.
- Tests: 4 new in-memory test doubles, `CheckoutAttemptStatusTest`, `CheckoutAttemptTest`,
  `CheckoutAttemptHandlersTest` (full cross-module wiring), `CheckoutAttemptPersistenceTest`
  (CI-only real-MySQL round trip).
- `.claude/PhaseResults/Phase18Result.md`.

## Files updated

`ResolvedPrice::toArray()` added; `RoutingDecision` docblock corrected; `ContainerFactory`
wired the new module; 3 modules' `Infrastructure/definitions.php` wired their new snapshot
repositories; `composer.json` gained the `checkout:*` scripts; `MigrationRoundTripTest` extended.
Every governance doc updated in lock-step: `database-design.md` (46 tables + new section),
`database-diagram.md`/`.html` (16/16 mermaid blocks, new module + ER section),
`db_explain.md`, `Architecture.md` (§3/§8/§9/§13), `Phases.md` (row 18 ☑ + as-built scope),
`Changelog.md`, `FileIndex.md`, `Knowledge.md`, `Commands.md`, `Orders.md` (D21), `Voucher.md`
(§8 as-built + §9/§10/§11).

## Database changes

4 new tables, all additive, no existing-table changes: `checkout_attempts`,
`pricing_decision_snapshots`, `voucher_decision_snapshots`, `provider_routing_decision_snapshots`.
Full column/index/FK detail in `.claude/docs/database-design.md` → "Checkout + decision snapshots
(Phase 18)".

## Tests — how to run and real results

```
composer ci
```
→ PHPStan: 592 files, **0 errors**. PHPUnit: **332 tests, 1354 assertions, OK**.

```
composer test:integration
```
→ **35 tests, 0 assertions, 35 skipped** (no local MySQL — every integration test, including the
new `CheckoutAttemptPersistenceTest`, self-skips via the `try/catch(PDOException)` guard; these
run for real in GitHub Actions CI).

`php -l` was run on every new file — all clean. All 6 new CLI scripts' usage strings were
captured by invoking each with no arguments (real output, in `Phase18Result.md`).

## Captured evidence

A standalone scratchpad script wiring real `PriceResolver` / `ProviderRouter` /
`ReserveVoucherRedemptionHandler` instances drove the full pre-payment lifecycle end-to-end; real
captured output:

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

This is a CLI/domain-logic phase with no admin panel or HTTP view — there is no screenshot to
capture; the real terminal output above and in `Phase18Result.md` is the evidence for this
phase's outputs, per the Visual and Output Verification Rule.

## Known limitations

- `abandoned_at`/`expired_at` and their automatic-detection sweep are reserved columns only —
  no handler writes them yet (Phase 29).
- `provider_checkout_created` → `converted_to_payment` transitions are modelled but have no real
  caller until Payments (Phase 20) and the provider adapters (Phase 21+) exist.
- No HTTP endpoint for checkout yet — CLI only, mounts with the payment-creation flow.

## Next recommended phase

**Phase 19 — Resolution API endpoints** (`POST /api/v1/vouchers/validate` + mounting the
package/pricing resolution endpoints for real client consumption).
