# Phase 1 — Groundwork: architecture baseline

## Execution Summary

- Phase: 01 — Groundwork: architecture baseline
- Start Datetime: 2026-09-06 17:42 PDT
- End Datetime: 2026-09-06 17:54 PDT
- Estimated Duration: 2–4h
- Actual Duration: 12m (End − Start; single continuous session)
- Tokens Used: N/A (per-phase token metering is not available in this environment)
- Final Status: ☑ Done

## Work Completed

- Ran the Interactive Phase Rule: presented the phase scope and asked 5 decision questions
  one-by-one (full record with options and reasoning in `.claude/PhaseDecisions.md` → Phase 1). All five
  were answered with the recommended option:
  1. **Module / folder structure** → Module-based hexagonal with **per-module**
     `Domain / Application / Infrastructure / Http` layers.
  2. **Cross-module communication** → Direct calls through published `Application/` interfaces
     (wired by PHP-DI) **plus** an in-process synchronous domain-event dispatcher for reactions.
  3. **Identifier strategy** → `BIGINT UNSIGNED AUTO_INCREMENT` primary/foreign keys + a public
     `ulid CHAR(26)` column on every externally-visible row; APIs/callbacks/admin use the ULID.
  4. **Money representation** → `brick/money` wrapped in a `Shared\Domain\Money` value object;
     stored as `amount_minor BIGINT` + `currency CHAR(3)`.
  5. **Provider-adapter interface** → Required core `PaymentProviderPort` + optional capability
     interfaces (`SupportsSubscriptions`, `SupportsRefunds`, `SupportsAuthCapture`,
     `SupportsCustomerPortal`, `SupportsManualPolling`) + a runtime `ProviderCapabilities`
     descriptor for per-client/per-country gating.
- Authored `.claude/docs/Architecture.md` capturing those decisions plus: the dependency rule and an
  abbreviated create-payment request flow; the module map (11 modules incl. `Shared`) with
  boundary rules; the `src/` folder layout; the domain-event catalogue (initial 6 events); the
  identifier, money, and provider/capability models; three resolution-pipeline sketches
  (package list, price, provider); the internal payment status set; a cross-cutting table
  (DI, config, logging, idempotency, errors, jobs, security); the testing approach; and an
  explicit deferred-work list.
- Seeded `.claude/Changelog.md` (with the Phase 1 entry) and `.claude/knowledge/Knowledge.md` (provider facts, money
  scale gotchas, pricing/voucher rules, identifier boundary, webhook gotchas). *(Both were
  first created as `CHANGELOG.md` / `KNOWLEDGE.md` and renamed to PascalCase per `.claude/Rule.md` §3.1 —
  see `.claude/Changelog.md` 2026-09-06 naming-compliance entry.)*
- Updated the `.claude/docs/Phases.md` *Status & execution tracking* row for Phase 1.

## Files Created

> **Note (post-phase):** the doc files below were created at the project root during Phase 1,
> then relocated by later reorgs — first to `Documents/`, then into `.claude/` (see
> `.claude/Changelog.md`). Paths here reflect the current location.

- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/Architecture.md` — the architecture baseline (target
  architecture, module map, folder layout, cross-module comms, IDs, money, provider model,
  resolution sketches, cross-cutting, testing, deferred items).
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/Changelog.md` — project changelog, seeded with the
  Phase 1 entry. (Created as `CHANGELOG.md`, renamed to PascalCase.)
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/knowledge/Knowledge.md` — durable domain knowledge / gotchas.
  (Created as `KNOWLEDGE.md`, renamed to PascalCase.)
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/PhaseResults/Phase01Result.md` — this file.

## Files Modified

- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/Phases.md` — Phase 1 row in the tracking table set to
  ☑ with Start `2026-09-06 17:42`, End `2026-09-06 17:54`, Actual `12m`, Tokens `N/A`. (During
  the phase the row was first set to ◐ with the Start datetime.)

## Implementation Details

No application code was written (Phase 1 is documentation-only). The architecture that later
phases will implement, as fixed in `.claude/docs/Architecture.md`:

- **Layers / ports & adapters.** Domain depends on nothing; Application depends on Domain and
  owns the port interfaces; Infrastructure and entry points (Slim HTTP, `src/Jobs` worker,
  Admin) depend inward and are wired by PHP-DI (`src/Config/container.php`). Provider SDKs only
  inside that provider's adapter; SQL only inside repositories.
- **Modules** (`src/Modules/<Name>/{Domain,Application,Infrastructure,Http,Tests}`, Admin also
  `Views/`): Clients, Providers, Packages, Pricing, Vouchers, Payments, Subscriptions, Webhooks,
  Notifications, Admin, plus `src/Shared`. A module depends only on another module's
  `Application/` interface, never its `Domain/` or `Infrastructure/`.
- **Namespace:** `Gomrok\` → `src/` (PSR-4), file name == class name.
- **Domain events:** `Shared\Application\DomainEventDispatcher` (impl in
  `Shared\Infrastructure`), dispatched after commit. Initial events: `PaymentCreated`,
  `PaymentStatusChanged`, `RefundRecorded`, `SubscriptionStatusChanged`,
  `WebhookReceived` / `WebhookProcessed`, `ClientNotificationFailed`.
- **Identifiers:** `Shared\Domain\Ulid` VO + `Shared\Infrastructure\UlidGenerator` (uses the
  injected `Clock`); numeric `id` never leaves the DB boundary.
- **Money:** `Shared\Domain\Money` wrapping `Brick\Money\Money`; operations `fromMinor`, `plus`,
  `minus`, `multipliedBy`, `allocate`, `toMinor`, `currency`.
- **Provider port:** `PaymentProviderPort` methods `createPayment`, `getPaymentStatus`,
  `verifyWebhookSignature`, `parseWebhook`, `mapProviderStatusToInternalStatus`,
  `getCapabilities`; optional interfaces as above; `ProviderCapabilities` VO = intersection of
  provider-type caps ∩ account config ∩ client/country config. `ProviderRouter` produces an
  ordered candidate list and rejects (never downgrades) when no candidate qualifies.
- **Payment statuses:** `created, pending, requires_action, authorized, paid, failed, canceled,
  expired, refunded, partially_refunded, disputed, chargeback`.

## Database Changes

No database changes. (Phase 1 produces no schema; the first tables land in Phase 4.)

## API Changes

No API changes. (No endpoints exist yet; the API surface is sketched in `.claude/docs/Architecture.md` §2
and built from Phase 19 / Phase 24 onward.)

## Tests and Validation

- Tests created: none (documentation-only phase).
- Tests modified: none.
- Tests executed: none — there is no code or test harness yet (that is Phase 2).
- Result: N/A. No test claims are made for this phase.

## Technical Decisions

1. **Per-module infrastructure over a shared infrastructure layer** — keeps each domain's
   adapters next to its logic; "add a provider" / "add a module" stays a local change across
   ~10 independent domains.
2. **Domain events now, not later** — payments are inherently event-driven (a webhook mutates a
   payment, which must fan out to notifications, subscriptions, reconciliation). A tiny
   synchronous dispatcher avoids a disruptive retrofit; synchronous, post-commit, in-process
   only — the queue, not events, crosses process boundaries.
3. **BIGINT PK + public ULID** — monotonic clustered PK keeps InnoDB indexes tight and joins
   cheap; the ULID gives a non-enumerable, sortable external reference. Rejected ULID-as-PK
   (index bloat / write amplification at payment volume) and UUIDv4-as-PK (random insert order
   fragments the clustered index).
4. **`brick/money` wrapped in our own VO** — correct rounding modes, per-currency scale
   (JPY 0dp / USD 2dp / BHD 3dp), and `allocate()` for splits, without leaking the library into
   the domain. Rejected a hand-rolled bcmath VO (easy to get scale/rounding subtly wrong) and
   DECIMAL/string arithmetic (precision drift, scattered formatting).
5. **Hybrid adapter interface** — every provider genuinely does the core four operations, so
   requiring them is safe; optional interfaces make capability support a type-level fact
   (`ZiraatAdapter` simply doesn't implement `SupportsSubscriptions`) instead of a runtime
   `throw`. Avoids both the "fat port that throws" landmines and the interface explosion of
   fully-segregated ports.

## Problems Encountered

- The pasted phase-result prompt and earlier instructions used lowercase names (`RULES.md`,
  `phase-results/`, `PHASES.md`) that conflict with the PascalCase rule established earlier in
  the project.
- `Estimated Duration` (2–4h) turned out far larger than the actual 12m.

## Resolutions

- Naming conflict: confirmed with the user (they chose PascalCase); applied everything to the
  existing `.claude/Rule.md` / `.claude/PhaseResults/` / `.claude/docs/Phases.md` and did not create lowercase duplicates.
- Estimate variance: recorded honestly (Est. 2–4h vs Actual 12m). The estimate assumed the
  architecture doc would need more iteration; because the 5 questions were pre-framed and all
  answers took the recommended option, the write-up was quick. Later phases' estimates left
  unchanged for now — will recalibrate once a few coding phases give real data.

## Deferred Work

- Concrete database schema — designed incrementally from **Phase 4** onward, each slice gated by
  explicit confirmation.
- Exact provider-adapter method signatures / DTOs — **Phase 21** (port), then per provider
  (Phases 22–23).
- Full domain-event list and per-module handler wiring — grows in each module's phase.
- Admin panel structure + RBAC schema — **Phase 27**.
- Precise pricing / voucher / provider-routing resolution ordering and edge cases —
  **Phases 10, 14–17**.
- Queue technology choice (DB-backed vs Redis vs …) — **Phase 29**; a `Jobs` port is introduced
  earlier when first needed.
- DB-docs location convention (repo-root PascalCase vs the spec's `.claude/docs/…`) and the
  `mkdocs` site — **Phase 4**.
- Project scaffold, `composer.json`, PHP-DI bootstrap, Slim skeleton, migration runner, test
  harness, static analysis, code style — **Phase 2**.

## Final Result

The repository now contains the agreed architecture baseline. `.claude/docs/Architecture.md` records the
hexagonal + modular structure, the 11-module map with boundary rules, the `src/` layout, the
cross-module communication model (published interfaces + post-commit synchronous domain events),
and the four cross-cutting technical decisions (per-module layers, BIGINT+ULID identifiers,
`brick/money`-backed `Money`, hybrid provider-adapter interface). `.claude/Changelog.md` and
`.claude/knowledge/Knowledge.md` are seeded. `.claude/docs/Phases.md` shows Phase 1 ☑. No code, no dependencies, no database yet
— the project is ready for Phase 2 (project scaffold & toolchain).
