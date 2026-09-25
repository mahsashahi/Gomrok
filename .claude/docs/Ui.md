# Ui.md — design system & UI guidelines

**Purpose.** The visual/interaction guidelines for Gomrok's admin panel.

**Reference, don't duplicate.**

- `CLAUDE.md` → *Frontend Stack*, *Admin Panel Requirement* — the stack (Alpine.js + CSS +
  server-rendered PHP views) and screen list.
- `.claude/docs/Design/` (project root) — the mirrored Claude Design admin-panel mockups (`.claude/docs/Design/Readme.md`);
  the current design is `.claude/docs/Design/GomrokAdminPanelV4.dc.html`.

## Status

No UI built yet. The panel is Phase 27.

## Currency input (added 2026-09-19)

Every currency field in the admin UI is a controlled combo/select, never free text — see
`CLAUDE.md` → *Frontend Stack* → *Currency Input Rule* and `.claude/Rule.md` §9. Shared component:
`src/Modules/Admin/Views/partials/currency-select.html.twig` — macro `select(field_name, model,
currencies, required, blank_label)` for a single value, `multiselect(field_name, model,
currencies)` for a comma-joined multi-value field (e.g. voucher eligibility's per-dimension OR
list). Both are populated from `ReferenceCatalog::listCurrencies()` (the `currencies` reference
table, the same source every currency field's backend validation already checks against) — no
screen maintains its own hardcoded currency list. Import with
`{% import 'partials/currency-select.html.twig' as currency %}` at the top of any screen that
needs it, and inject `currencies` into that screen's render context (every current screen that
needs it already does).

## Country input (added 2026-09-20)

Every country field in the admin UI is a controlled combo/select (or, for a comma-joined
multi-value field, a controlled multi-select), never free text — see `CLAUDE.md` → *Frontend
Stack* → *Country and Currency Input Rule* and `.claude/Rule.md` §9. Shared component:
`src/Modules/Admin/Views/partials/country-select.html.twig` — macro `select(field_name, model,
countries, required, blank_label)` for a single value (e.g. a client's default country),
`multiselect(field_name, model, countries)` for a comma-joined multi-value field (pricing-group
countries, provider account/routing-group countries, voucher eligibility countries). Both are
populated from `ReferenceCatalog::listCountries()` (the `countries` reference table, the same
source every country field's backend validation already checks against via
`countryExists()`/`CountryCode::of()`) — no screen maintains its own hardcoded country list.
Import with `{% import 'partials/country-select.html.twig' as country %}` at the top of any
screen that needs it, and inject `countries` into that screen's render context (every current
screen that needs it already does).

Converted fields (2026-09-20): `clients.html.twig` — New/Edit client `default_country` (2, single
select); `packaging.html.twig` — Create/Edit pricing group `countries` (2, multiselect);
`providers.html.twig` — Connect/Edit account `countries` (2) and Edit routing group `countries`
(1) (3, multiselect); `vouchers.html.twig` — eligibility `country` (1, multiselect). 8 fields
across 4 screens, mirroring the currency conversion's file list.

## Package price monthly-equivalent display (added 2026-09-24)

**Rule.** For packages longer than one month, every package-price display in the admin UI must
show the derived effective monthly price directly below the total price (for example, `€22.00`
with `€7.33/mo` underneath). The monthly value is calculated as `total price / duration in
months` from the currently resolved/displayed price (default price, per-group price, or
per-currency-converted price) and the package's duration — it is **never** stored independently;
it is recomputed on every render so it always tracks the live price. A 1-month package (or a
package with no fixed duration) shows no `/mo` line — never a redundant repeat of the same
number.

This restores behaviour already specified by the approved design
(`.claude/docs/Design/GomrokAdminPanelV4.dc.html`'s `monthlyEquivalent()` helper, used on its
"Pricing by country" and pricing-group "Package prices" tables) — it is a frontend/design
consistency rule, not a new pricing-domain concept; nothing in `.claude/docs/database-design.md`
or `.claude/Voucher.md` changes.

**Where it applies today** (Packaging & Pricing screen, `packaging.html.twig`):

- Packages tab detail → *Pricing by group* table: `row.price` / `row.priceMonthly` and
  `row.defaultCurrencyPrice` / `row.defaultCurrencyPriceMonthly`
  (`PackagingByGroupRow`, built by `PackagesTabHandler::rowForGroup()`).
- Pricing groups tab detail → *Package prices* table: `row.groupPrice` / `row.groupPriceMonthly`
  and `row.defaultCurrencyPrice` / `row.defaultCurrencyPriceMonthly`
  (`GroupPackageRow`, built by `GroupsTabHandler::rowForPackage()`).

Matching the approved design exactly, the monthly line is **not** shown next to the Packages
tab's master-row/list price or its "Default price" info-card tile, nor next to a group-table
row's small "default {{ price }}" sub-line — those stay as plain totals in the design too.

**Implementation.** `Money::perMonth(int $months): self` (`src/Shared/Domain/Money.php`) divides
the amount by the duration using the same `HALF_EVEN` rounding as `multipliedBy()` /
`percentage()`. Each handler resolves the package's duration via
`PackagePurchaseCapabilityResolver` and formats `"{$money->perMonth($months)->format($locale)}/mo"`
only when `$months > 1`; `null` (hidden in the template) otherwise. Any future package-price
display that adds a "resolved" or "effective" price column should follow the same pattern rather
than re-deriving the math inline.

## To document (Phase 27)

- Layout shell (sidebar order, top bar, active-client switcher, Test-mode toggle).
- Component patterns (tables, expandable rows, stat-card tabs, master–detail, reveal/hide secrets).
- Spacing / colour / type tokens extracted from the `.claude/docs/Design/` files.
- Accessibility baseline.
- How undesigned screens render (neutral titled placeholder).
