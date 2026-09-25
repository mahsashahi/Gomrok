# Q: Restore the per-month price display on the Packaging & Pricing admin UI

Implemented the effective monthly-price display for multi-month packages, matching the approved
design (`.claude/docs/Design/GomrokAdminPanelV4.dc.html`'s `monthlyEquivalent()` helper).

## What the design actually specifies (checked against the `.dc.html` source, not assumed)

The design only shows the `/mo` line in two places:

1. Packages tab detail → "Pricing by country" table (Gomrok's equivalent: *Pricing by group*) —
   under both the main price column and the "In {defaultCurrency}" column.
2. Pricing groups tab detail → "Package prices" table — same two columns.

It does **not** show a monthly line under the Packages tab's master-row list price, the "Default
price" info-card tile, or the small "default {{ price }}" sub-line in the group table. I matched
that exactly rather than adding it everywhere a price appears, to avoid scope creep beyond the
approved design.

The design's own `monthlyEquivalent()` JS quirk (returning the *same* price string unsuffixed for
a 1-month package, rather than nothing) looked like an unintentional mock artifact, not a
deliberate requirement, and conflicted with the request's explicit "no redundant `/mo` line for a
1-month package" instruction — so I hid the line entirely (`null`) for `durationMonths <= 1`,
per the explicit instruction, and noted the deviation in `.claude/docs/Ui.md`.

## Implementation

- **`Money::perMonth(int $months): self`** (`src/Shared/Domain/Money.php`) — divides by the
  duration using the same `HALF_EVEN` rounding as the existing `multipliedBy()`/`percentage()`.
  New test `MoneyTest::perMonthDividesEvenlyWithBankersRounding()` covers the request's own worked
  examples (€22/3 → €7.33, €38/6 → €6.33, €72/12 → €6.00, €89/12 → €7.42).
- **`PackagesTabHandler`** — extracted `durationMonths()` (reused by the existing
  `durationLabel()`) and added `monthlyPriceLabel()`; `rowForGroup()` fills two new fields on
  `PackagingByGroupRow`: `priceMonthly`, `defaultCurrencyPriceMonthly`.
- **`GroupsTabHandler`** — gained a new constructor dependency on
  `PackagePurchaseCapabilityResolver` (pure autowiring in `src/Config/container.php`, no DI
  registration needed) and the same two helper methods (duplicated rather than shared, matching
  this file pair's existing convention of duplicating `convert()`/`providersServing()`/
  `defaultPriceLabel()`); `rowForPackage()` fills `GroupPackageRow::groupPriceMonthly` /
  `defaultCurrencyPriceMonthly`.
- **`packaging.html.twig`** — renders the new fields under both tables' price cells with
  `{% if row.xMonthly %}` guards, in the design's subtle/secondary style
  (`font-size:10.5px;color:var(--text-faint)`).
- The monthly amount is **never persisted** — always recomputed from the live resolved price and
  the package's duration, per the request.

## Tests

Updated `PackagesTabHandlerTest` and `GroupsTabHandlerTest` to wire a real
`PackagePurchaseCapabilityResolver` (backed by an `InMemoryPackageRepository` holding a `Package`
domain object with real purchase capabilities) instead of an empty one, since the monthly
calculation needs an actual duration:

- `PackagesTabHandlerTest`: existing group-row test now also asserts `€9.67/mo` (29/3); new test
  `aOneMonthPackageShowsNoRedundantMonthlyLine()` builds a separate 1-month handler instance and
  asserts every row's `priceMonthly` is `null`.
- `GroupsTabHandlerTest`: added `groupPriceMonthly` assertions to the control-list test (€4.83/mo
  = 29/6) and the variant-list test (€4.35/mo = 26.10/6 exactly).

Full suite: `vendor/bin/phpunit` → 927 tests, 3514 assertions, OK (3 pre-existing unrelated
skips). `vendor/bin/phpstan analyse` → no errors. `composer cs` → no new violations (the 7
pre-existing style findings are all in unrelated files).

## Documentation

- **`.claude/docs/Ui.md`** — new "Package price monthly-equivalent display" section: the rule
  text the request asked for, where it applies today (with exact field/class names), where it
  deliberately does *not* apply, and the implementation pattern for future price displays to
  follow.
- **`.claude/Changelog.md`** — new 2026-09-24 entry with full file list, reasoning, and the
  screenshot verification summary.
- **`CLAUDE.md`** — reviewed; no change made. Per the project's own Documentation Directory rule,
  detailed frontend/UI conventions belong in `.claude/docs/Ui.md`, not the root spec file.
- No change needed to `.claude/docs/database-design.md`, `.claude/Voucher.md`, or any
  `PhaseResults/` file — no database change, no voucher behaviour, and this isn't a numbered
  phase (Phase 27, which built this screen, is closed history and per the Phase Completion Rule
  its result file is never rewritten for a later change; this fix follows the same
  ad-hoc-frontend-maintenance pattern as the 2026-09-20/21 "change front" commits, which updated
  `Changelog.md` without touching any Phase result file).

## Real evidence captured (Visual and Output Verification Rule)

Started the app locally (`php -S 127.0.0.1:8080 -t src/Public` against the existing local dev
MySQL database), created a throwaway local admin user (`qa-verify@example.test`, dev-only,
discard-safe) since none existed, and added a `quarterly` package to the `local-dev` client
(3-month duration, €22.00 default price — the request's own example numbers) since every existing
seeded package was 1-month. Logged in and screenshotted both affected screens with Claude in
Chrome:

- **Packages tab → Quarterly package detail, *Pricing by group* table**: DACH `€22.00` /
  `€7.33/mo`, United States `$23.76` / `$7.92/mo` (currency-converted, monthly derived from the
  converted amount), Default `€22.00` / `€7.33/mo`.
- **Pricing groups tab → DACH group, *Package prices* table**: `Quarterly` row shows `€22.00` /
  `€7.33/mo`; the existing 1-month `Pro` and `Starter` rows correctly show no `/mo` line.

Both match the requested example (`€22.00` / `€7.33/mo`) exactly. While exploring the pricing
groups tab I accidentally clicked "Enable" on price list B (a real state-changing action) —
noticed immediately and clicked "Disable" to restore it, confirmed back to "Disabled — no new
visitors assigned" before finishing. Left the `quarterly` test package and the throwaway admin
user in the local dev database as they don't affect any existing data and mirror the existing
`Pro`/`Starter` seed pattern; happy to remove either if you'd rather they weren't there.

## Files changed

**Backend:** `src/Shared/Domain/Money.php`,
`src/Modules/Admin/Application/Packaging/PackagesTabHandler.php`,
`src/Modules/Admin/Application/Packaging/GroupsTabHandler.php`,
`src/Modules/Admin/Application/Packaging/PackagingByGroupRow.php`,
`src/Modules/Admin/Application/Packaging/GroupPackageRow.php`.
**Frontend:** `src/Modules/Admin/Views/packaging.html.twig`.
**Tests:** `tests/Unit/Shared/Domain/MoneyTest.php`,
`tests/Unit/Modules/Admin/Application/Packaging/PackagesTabHandlerTest.php`,
`tests/Unit/Modules/Admin/Application/Packaging/GroupsTabHandlerTest.php`.
**Docs:** `.claude/docs/Ui.md`, `.claude/Changelog.md`.
