# Q: Start Phase 24's A/B price-list re-ask

Re-asked Phase 15's Q4 and Q5 with their full original option lists (per the user's own standing
instruction not to assume the earlier recommendation), recorded as Phase 24 Q6/Q7, confirmed the
database design for the one new table needed, and built the whole visitor→price-list assignment
mechanism. This completes Phase 24.

## Decisions (Q6, Q7)

- **Q6** (re-ask of Phase 15 Q4): **persisted** `price_list_assignments`, not stateless recompute.
  First visit computes the bucket by deterministic hash and persists it; later visits read the
  stored row; a since-disabled bucket reassigns to control on that read.
- **Q7** (re-ask of Phase 15 Q5): **both** `GET /api/v1/packages` and `GET /api/v1/pricing/resolve`
  accept a `visitor_ref` and persist the assignment on first sight — diverging from the original
  recommendation of `/pricing/resolve`-only, in favour of symmetry between the two read endpoints.

## Database design confirmed

One new table: `price_list_assignments` — `client_id`, `pricing_group_id`, `visitor_ref_hash`
(SHA-256, no raw ref stored), `price_list_id`, `assigned_at`/`reassigned_at`,
`created_at`/`updated_at`; `UNIQUE (pricing_group_id, visitor_ref_hash)`; FKs to
`clients`/`pricing_groups`/`price_lists`. Purely additive — confirmed before migrating.

## What was built

- `src/Modules/Pricing/Domain/PriceListAssignment.php` + `PriceListAssignmentRepository.php`,
  `Infrastructure/PdoPriceListAssignmentRepository.php` (the race-safe
  `insertOrGetExisting()` using MySQL's `INSERT ... ON DUPLICATE KEY UPDATE id =
  LAST_INSERT_ID(id)` idiom).
- `src/Modules/Pricing/Application/ResolveVisitorPriceListAssignment.php` — the bucket-assignment
  service: hashes `pricing_group_id . ':' . visitor_ref`, buckets by `hexdec(hash) %
  count(enabledLists)` over the group's enabled lists (control first, then by id), persists on
  first sight, reassigns to control on read if the bucket was later disabled, and degrades to
  `null` (never throws) for a group with no price list at all.
- `PriceResolver::resolve()` and `PriceCatalog::resolve()` both gained an optional `?string
  $visitorRef` param — `PriceCatalog` resolves the visitor's bucket **once** per catalogue request
  and applies it to every item, which is also the first time `/packages`' displayed price can
  reflect an A/B experiment (previously it never called `PriceListResolver` at all).
- `GET /api/v1/packages`, `GET /api/v1/packages/{packageId}`, `GET /api/v1/pricing/resolve` all
  accept an optional `visitor_ref` query parameter.

## Two things fixed along the way

1. The bucket-resolution service originally asserted a pricing group's control list always
   exists — but several existing unit tests build `PricingGroup` directly (bypassing
   `CreatePricingGroupHandler`'s auto-created control row), which would have crashed. Fixed by
   making the whole resolution chain return `?int` and degrade gracefully to "no list, use base
   price" instead of asserting.
2. `tests/Unit/Http/PackagesApiTest.php` (a functional test that boots the real DI container)
   started throwing a real `PDOException` (`Access denied for user 'gomrok'@'localhost'`) once
   `PriceCatalog`/`PriceResolver` gained the new repository dependency, because that test hadn't
   swapped it for an in-memory double the way it already does for every other pricing repository.
   Fixed by adding the missing container swap.

## Tests

New coverage across `PriceCatalogTest`, `PriceResolverTest` (stability across calls,
disable-fallback reassignment, no-list-at-all degradation), and `PackagesApiTest` (end-to-end
`visitor_ref` acceptance on both endpoints). Full suite: **561 tests, 1870 assertions**;
`composer ci` (CS + PHPStan + tests) clean. `tests/Integration/*` continues to self-skip (39
tests) — this local environment's MariaDB is running but the `gomrok` user's credentials
currently don't authenticate, a pre-existing environment condition unrelated to this work
(verified directly against the running server).

## Documentation

`.claude/docs/Architecture.md`, `database-design.md`, `database-diagram.md`/`.html`,
`db_explain.md`, `.claude/Changelog.md`, `.claude/FileIndex.md`, `.claude/knowledge/Knowledge.md`,
`.claude/docs/Phases.md` (Phase 24 now marked ☑ complete), `.claude/PhaseResults/PhaseDecisions.md`
(Q6/Q7 recorded), and — since the whole phase is now done — `.claude/PhaseResults/Phase24Result.md`
was created recording the full phase (all three increments: creation/return flow,
cancel/refund/capture, and this A/B visitor-assignment piece).

## What's left

Nothing outstanding for Phase 24 itself. No git commit has been made yet for this A/B-assignment
increment (increments 1 and 2 were committed earlier as `1d19ac4`). Two things intentionally
deferred beyond this phase, noted in `Phase24Result.md`: `visitor_ref` is not wired into
`POST /api/v1/payments` or the checkout pricing pipeline (Q7 named exactly two endpoints), and a
refund's own provider-issued reference isn't stored as its own `GatewayReference` row (needs a
dedicated `refunds` table, which needs its own database-design confirmation first).
