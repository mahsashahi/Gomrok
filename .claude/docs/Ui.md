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

### Multi-country chip picker (added 2026-09-24)

**Rule.** Multi-country fields in the Gomrok admin UI must use a controlled country picker with
one-by-one selection and removable selected-country chips. Users must not manually enter country
names or country codes, and must not pick from a plain multi-row `<select multiple>` list either
— that's still "manual list-style" UX, not the approved design's chip picker. A country already
selected must not be selectable again (the dropdown only ever lists countries not yet chosen), and
removing a chip must make that country selectable again immediately.

This restores behaviour already specified by the approved design
(`.claude/docs/Design/GomrokAdminPanelV4.dc.html`'s `countryPickerOpen`/`addCountry`/
`removeCountry` state and its "New pricing group" modal markup) — a frontend/design consistency
rule, not a change to any domain model, database table, or API contract.

**Component.** `partials/country-select.html.twig`'s new `chips(field_name, model, countries)`
macro — a toggle ("Add a country…") opens a searchable dropdown (filtered client-side by name or
ISO code) of every country not already selected; clicking one adds it as a chip and closes the
dropdown; each chip has its own remove control. Same "plain comma-joined string" field contract as
the older `multiselect(field_name, model, countries)` macro it's meant to replace going forward —
the hidden `<input type="hidden" name="{{ field_name }}">` is a drop-in replacement, so no backend
change is ever needed to adopt it (`ReferenceCatalog::listCountries()` remains the one source, and
backend validation — e.g. `SetPricingGroupCountriesHandler`'s `ReferenceCatalog::countryExists()`
loop — is untouched and still authoritative; the picker's own de-duplication is a UX convenience,
not a substitute for that check).

**Where it applies today:** `packaging.html.twig`'s Create/Edit Pricing Group `countries` field (2
fields). `multiselect()` is unchanged and still used by `providers.html.twig` (3 fields) and
`vouchers.html.twig` (1 field) — migrating those to `chips()` is a natural follow-up but out of
scope for the pricing-group fix that introduced it; do it screen-by-screen rather than assuming
`multiselect()` is deprecated everywhere.

**Gotcha (hit once, worth remembering):** embedding `countries|json_encode` directly into an
`x-data="..."` attribute breaks — JSON's own `"` characters terminate the attribute early and the
JS source leaks onto the page as literal text. Always run it through `|e('html_attr')` (not
`|raw`): `chipCountries: {{ countries|json_encode|e('html_attr') }}`.

## Validation-preserving forms (added 2026-09-24)

**Rule.** On validation failure, Gomrok admin forms must remain open and preserve all
user-entered state. Validation errors must be shown in-place without clearing or closing the
form. Concretely:

- A validation failure never closes the create/edit modal, never resets the form, and never
  redirects to a fresh/empty screen. The exact same modal reopens with every value the user typed
  — text fields, textareas, checkboxes, selects, chips, priorities — still in place.
- Every modal shows a dismissible general-error banner (dismissing it clears only the error, never
  the form). Field-specific messages are added where cheap and unambiguous; the general banner is
  the one guaranteed, always-shown feedback mechanism.
- An edit form that fails preserves the user's *attempted* edit, not the original stored entity —
  it never silently reloads the database row and overwrites what the user was correcting.
- This applies to every real create/edit form (one with actual user-entered fields). A pure
  single-button action — status toggle, revoke, remove-row, enable/disable, machine-generated
  drag-reorder — has no free-typed state to lose and is out of scope; there is nothing to preserve
  and nothing was ever at risk.
- Successful-submit behavior is unchanged: a successful create/update still redirects and/or
  closes the modal exactly as before. Only the failure path changed.

**Why this exists.** Every write action used to redirect (`Location:` + a `?error=` query-string
flash) on validation failure — a plain POST/Redirect/GET, which discards the entire POST body by
construction. A modal reopened by a redirect starts from the page's fresh GET state (`modal:
null, form: {}`), so every value the user typed was silently gone the moment a single field failed
validation. This was true of Clients, Packaging & Pricing (packages, pricing groups, price lists),
Providers, Vouchers, and Admin Users equally, since they share one identical thin-Action /
Alpine-modal pattern (see "Component patterns" below).

**Mechanism** (built 2026-09-24, applied across every screen's real create/edit forms):

- **Server side.** Each screen's GET action (`AdminPackagingAction`, `AdminClientsAction`,
  `AdminProvidersAction`, `AdminVouchersAction`, `AdminAdminUsersAction`) exposes two public
  methods instead of doing everything inline in `__invoke()`:
  - `render(ServerRequestInterface $request, ResponseInterface $response, ?AdminModalReopen
    $reopen)` — the actual page-building logic (unchanged), now parameterized by an optional
    reopen payload; `__invoke()` just calls it with `null`. Response status is `422` when
    `$reopen` isn't null, `200` otherwise.
  - `reopen(ServerRequestInterface $request, ResponseInterface $response, array $queryParams,
    AdminModalReopen $reopen)` — rebuilds the request's query string from the same params a
    redirect would have used (so the right tab/detail selection still resolves), then calls
    `render()`.
  - A write action (e.g. `AdminGroupsCreateAction`, `AdminClientsUpdateAction`, …) gets its
    screen's GET action injected (plain constructor autowiring — no DI config changes needed) and,
    on every `Result::isErr()` that used to redirect with `error:`, instead calls
    `$this->screen->reopen($request, $response, [...], new AdminModalReopen('modal-name',
    $submittedValues, $errorMessage))`. The success path is untouched.
  - `AdminModalReopen` (`src/Shared/Http/AdminModalReopen.php`) is the plain carrier: `modal` (the
    Alpine modal name), `values` (shaped exactly like that modal's own `open('modal-name', {...})`
    call already uses), `error` (the general message), `fieldErrors` (best-effort, keyed by field
    name — populated only where cheap and unambiguous, never invented).
  - **Two-step writes** (e.g. create an entity, then a second step like setting its countries that
    can independently fail) reopen the *edit* modal for the entity that now exists, not the
    *create* modal again — reopening create would resubmit the create command a second time and
    duplicate the row. See `AdminGroupsCreateAction::__invoke()` for the reference case.
  - **Secrets are never round-tripped.** A field carrying a plaintext secret the user just typed
    (e.g. a new admin password on a failed reset) is never put into `$submittedValues` — it would
    land in the rendered HTML page source. Leave it blank on reopen; every non-secret field still
    preserves normally.
- **Client side.** One shared Alpine factory, `adminModalState()`
  (`src/Public/admin-modal-state.js`): `{modal, form, error, fieldErrors, open(name, data, error,
  fieldErrors), close(), dismissError()}`. Every screen's own `*Modals()` function
  (`packagingModals()`, `clientsModals()`, `providersModals()`, `vouchersModals()`,
  `adminUsersModals()`) is now a one-line wrapper returning it (screen-specific extras like drag-
  reorder wiring stay in that screen's own file, untouched). Loaded globally from
  `layouts/base.html.twig`'s `<head>`, before every screen's own script.
- **Template side.** Each screen's root `x-data="...Modals()"` element carries a conditional
  `x-init="open(...)"` built from `reopen_modal` when the server set one:
  ```twig
  <div x-data="packagingModals()"{% if reopen_modal %} x-init="open({{ reopen_modal.modal|json_encode|e('html_attr') }}, {{ reopen_modal.values|json_encode|e('html_attr') }}, {{ reopen_modal.error|json_encode|e('html_attr') }}, {{ reopen_modal.fieldErrors|json_encode|e('html_attr') }})"{% endif %}>
  ```
  Every create/edit modal imports `partials/modal-error.html.twig` and calls `{{
  modal_error.banner() }}` right under its title/subtitle, before the `<form>` — a `.modal-error-banner`
  (`src/Public/admin.css`) bound to the shared `error`/`dismissError()` state. A non-Alpine inline
  form (e.g. Packaging's "link a provider account manually" `<details>`) uses the plain
  server-rendered equivalent instead: `{% set x_reopen = reopen_modal and reopen_modal.modal ==
  'name' ? reopen_modal : null %}`, keeping `<details open>` and prefilling each field's `value=`
  directly — no Alpine needed there.
- **Gotcha, worth remembering:** `{{ value|json_encode|e('html_attr') }}` numeric-escapes almost
  every non-alphanumeric character (`:` → `&#x3A;`, `{` → `&#x7B;`, space → `&#x20;`, etc.) — this
  is correct and safe, but makes a raw-HTML substring assertion in a test fragile. Tests decode
  with `html_entity_decode($html, ENT_QUOTES | ENT_HTML5)` first (exactly what a browser does
  before Alpine evaluates the attribute) and assert on the natural JSON text.

**Where it applies today:** every real create/edit form (one with actual user-entered fields) in
Clients, Packaging & Pricing (packages, pricing groups, price lists, per-group/per-list package
prices, manual provider linking), Providers (accounts, routing groups, secret rotation, add-account-
to-group), Vouchers (vouchers, eligibility, currency discounts, usage limits), and Admin Users
(create, password reset). Pure single-button actions (status toggles, revoke, remove-row,
drag-reorder) are out of scope by design — see the "why" above. Notifications and Settings have no
create/edit forms at all (Notifications is filters + single-button retry; Settings is a
placeholder screen) — nothing to fix there.

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
