# Phase 27 — Admin Module Views and Panels

## Execution Summary

- Phase: 27 — Admin Module Views and Panels
- Start Datetime: 2026-09-14 15:00
- End Datetime: 2026-09-16 00:40
- Estimated Duration: 12–20h (per `.claude/docs/Phases.md`)
- Actual Duration: wall-clock span of ~1 day 9.5h across multiple work sessions (not a
  continuous stretch); no reliable continuous-effort timer was kept, so this figure is the
  calendar span between the first and last recorded activity, not hours of active work.
- Tokens Used: N/A (not tracked)
- Final Status: Complete — every screen in Phase 27's scope is built (or, for Settings,
  deliberately left as an honest placeholder) and independently validated with real DB writes,
  RBAC enforcement, automated tests, and Playwright screenshots. Nothing in this phase is
  committed to git yet, per the standing "no commits yet" instruction repeated throughout the
  phase.

## Work Completed

Built the entire admin panel: authentication, session management, login-attempt lockout,
role-based access control, the server-rendered shell (Twig + Alpine.js + hand-written CSS), and
eleven screens reachable from the sidebar — Home, Sales, Customers, Packaging & Pricing (two
tabs, built in two explicit increments), Providers (two tabs), Vouchers, Clients, Admin Users,
Audit Logs, Error Logs, and Settings (a deliberate, disclosed placeholder — see below). Also
introduced the project's headless-browser screenshot tooling (Playwright via Node), used to
capture real evidence for every screen per CLAUDE.md's Visual and Output Verification Rule.

Every write-capable screen was built with full CRUD/lifecycle functionality (not read-only stubs)
per the user's explicit standing instruction, validated against a real local MySQL database, RBAC
tested with a temporary `support_agent` account proving both frontend hiding and backend 403
enforcement, covered by new PHPUnit tests, and screenshotted with Playwright before being called
done. Two screens were deliberately *not* given a write action, and this was disclosed rather
than silently decided or worked around:

- **Audit Logs** — read-only by design: CLAUDE.md only ever says "viewing" audit logs, and an
  audit trail editable through the very panel it audits would defeat its purpose as a
  tamper-evident record.
- **Settings** — a neutral titled placeholder, not a fabricated feature. Investigated per an
  explicit user instruction to check for a real backing domain before building anything; none
  exists (see Technical Decisions). The user was asked how to resolve the gap and chose the
  placeholder option, which is also `.claude/docs/Phases.md`'s own stated fallback for
  "undesigned screens."

Vouchers' redemption-history section is likewise read-only within an otherwise fully-writable
screen, for the same category of reason (see Technical Decisions).

Seven genuine production bugs — unrelated to this phase's own new code paths in most cases — were
found by actually exercising the running application (not by unit tests) and fixed in place; see
Problems Encountered / Resolutions.

## Files Created

**Database migrations** (3, additive only):
- `src/Database/Migrations/20260914150001_create_admin_users_table.php`
- `src/Database/Migrations/20260914150002_create_admin_sessions_table.php`
- `src/Database/Migrations/20260914150003_create_admin_login_attempts_table.php`

**Admin auth / RBAC / shell:**
- `src/Modules/Admin/Domain/{AdminUser,AdminUserRepository,AdminUserStatus,AdminRole,AdminSession,AdminSessionRepository,AdminLoginAttempt,AdminLoginAttemptRepository}.php`
- `src/Modules/Admin/Infrastructure/{PdoAdminUserRepository,PdoAdminSessionRepository,PdoAdminLoginAttemptRepository,definitions}.php`
- `src/Modules/Admin/Application/{AdminPermission,AdminPermissions,SessionAdminAuthenticator,LogoutAdminHandler,PaymentProviderResolver}.php`
- `src/Modules/Admin/Application/AuthenticateAdmin/{AuthenticateAdminCommand,AuthenticateAdminHandler,AuthenticateAdminResult}.php`
- `src/Shared/Http/{AuthenticatedAdmin,AdminAuthResult,AdminAuthenticator,AdminContext,AdminAuthenticationMiddleware,AdminPermissionGuard,ViewRenderer}.php`
- `src/Http/Admin/{AdminLoginShowAction,AdminLoginSubmitAction,AdminLogoutAction,AdminActiveClientAction,AdminActiveClientCookie,AdminForm}.php`
- `src/Modules/Admin/Views/{login,layouts/base,layouts/shell}.html.twig`

**Home / Sales / Customers:**
- `src/Modules/Admin/Application/Dashboard/{HomeDashboardHandler,HomeDashboardResult,HomeOverviewStat,RecentPaymentRow}.php`
- `src/Modules/Admin/Application/Sales/{SalesListHandler,SalesListResult,SalesPaymentRow,SalesStatusTab,SalesTimelineEntry}.php`
- `src/Modules/Admin/Application/Customers/{CustomersListHandler,CustomersListResult,CustomerRow,CustomerProviderRef,CustomerSubscriptionRow}.php`
- `src/Http/Admin/{AdminHomeAction,AdminSalesAction,AdminCustomersAction}.php`
- `src/Modules/Admin/Views/{home,sales,customers}.html.twig`

**Packaging & Pricing (Increment A read-only + Increment B write):**
- `src/Modules/Admin/Application/Packaging/{PackagesTabHandler,PackagesTabResult,PackageListItem,PackageDetail,GroupsTabHandler,GroupsTabResult,GroupListItem,GroupDetail,GroupPackageRow,PackagingByGroupRow,PriceListItem,ProviderSetupRow}.php`
- `src/Modules/Admin/Application/Packaging/CreatePackageForAdmin/{CreatePackageForAdminCommand,CreatePackageForAdminHandler,CreatePackageForAdminResult}.php`
- `src/Modules/Admin/Application/Packaging/UpdatePackageForAdmin/{UpdatePackageForAdminCommand,UpdatePackageForAdminHandler}.php`
- `src/Modules/Admin/Application/Packaging/SetGroupPackagePriceForAdmin/{SetGroupPackagePriceForAdminCommand,SetGroupPackagePriceForAdminHandler}.php`
- `src/Modules/Admin/Application/Packaging/ReorderPricingGroupPackages/{ReorderPricingGroupPackagesCommand,ReorderPricingGroupPackagesHandler}.php`
- `src/Http/Admin/{AdminPackagingAction,AdminPackagesCreateAction,AdminPackagesUpdateAction,AdminPackageProviderLinkAction,AdminGroupsCreateAction,AdminGroupsUpdateAction,AdminGroupPackagePriceAction,AdminGroupReorderAction,AdminPriceListsCreateAction,AdminPriceListsStatusAction,AdminPriceListPackagePriceAction,RedirectsToPackaging}.php`
- `src/Modules/Admin/Views/packaging.html.twig`, `src/Public/packaging.js`

**Providers (Accounts + By-groups):**
- `src/Modules/Admin/Application/Providers/{AccountListItem,AccountDetail,AccountGroupMembership,AccountsTabResult,AccountsTabHandler,GroupListItem,GroupAccountRow,GroupDetail,GroupsTabResult,GroupsTabHandler}.php`
- `src/Modules/Admin/Application/Providers/UpdateProviderAccountForAdmin/{UpdateProviderAccountForAdminCommand,UpdateProviderAccountForAdminHandler}.php`
- `src/Modules/Admin/Application/Providers/UpdateProviderGroupForAdmin/{UpdateProviderGroupForAdminCommand,UpdateProviderGroupForAdminHandler}.php`
- `src/Modules/Admin/Application/Providers/ManageProviderGroupAccountsForAdmin/ManageProviderGroupAccountsForAdminHandler.php`
- `src/Http/Admin/{AdminProvidersAction,AdminProviderAccountsCreateAction,AdminProviderAccountsUpdateAction,AdminProviderAccountRotateSecretAction,AdminProviderGroupsCreateAction,AdminProviderGroupsUpdateAction,AdminProviderGroupAccountAddAction,AdminProviderGroupAccountRemoveAction,AdminProviderGroupAccountToggleAction,AdminProviderGroupReorderAction,RedirectsToProviders}.php`
- `src/Modules/Admin/Views/providers.html.twig`, `src/Public/providers.js`

**Vouchers:**
- `src/Modules/Admin/Application/Vouchers/{VoucherListItem,VoucherDetail,CurrencyDiscountRow,EligibilityRuleGroup,RedemptionRow,VouchersScreenResult,VouchersScreenHandler}.php`
- `src/Modules/Admin/Application/Vouchers/UpdateVoucherForAdmin/{UpdateVoucherForAdminCommand,UpdateVoucherForAdminHandler}.php`
- `src/Http/Admin/{AdminVouchersAction,AdminVouchersCreateAction,AdminVouchersUpdateAction,AdminVoucherStatusAction,AdminVoucherEligibilityAction,AdminVoucherUsageLimitsAction,AdminVoucherCurrencyDiscountAction,AdminVoucherCurrencyDiscountRemoveAction,RedirectsToVouchers,AdminMoneyInput}.php`
- `src/Modules/Admin/Views/vouchers.html.twig`, `src/Public/vouchers.js`

**Clients (stat tabs + New client modal):**
- `src/Modules/Admin/Application/Clients/{ClientRow,ApiKeyRow,ClientDetail,ClientStats,ClientsScreenResult,ClientsScreenHandler}.php`
- `src/Http/Admin/{AdminClientsAction,AdminClientsCreateAction,AdminClientsUpdateAction,AdminClientsStatusAction,AdminClientApiKeyIssueAction,AdminClientApiKeyRevokeAction,RedirectsToClients,BuildsClientsScreenContext}.php`
- `src/Modules/Admin/Views/clients.html.twig`, `src/Public/clients.js`

**Admin Users:**
- `src/Modules/Admin/Application/{CreateAdminUser,SetAdminUserStatus,ResetAdminUserPassword}/` (Command + Handler + Result where applicable)
- `src/Modules/Admin/Application/AdminUsers/{AdminUserRow,AdminUsersScreenResult,AdminUsersScreenHandler}.php`
- `src/Http/Admin/{AdminAdminUsersAction,AdminAdminUsersCreateAction,AdminAdminUsersStatusAction,AdminAdminUserPasswordResetAction,RedirectsToAdminUsers}.php`
- `src/Modules/Admin/Views/admin-users.html.twig`, `src/Public/admin-users.js`

**Audit Logs:**
- `src/Shared/Application/Audit/{AuditLogDirectory,AuditLogEntry,AuditLogFilter}.php`
- `src/Shared/Infrastructure/Persistence/PdoAuditLogDirectory.php`
- `src/Modules/Admin/Application/AuditLogs/{AuditLogRow,AuditLogsFilterState,AuditLogsScreenResult,AuditLogsScreenHandler}.php`
- `src/Http/Admin/AdminAuditLogsAction.php`
- `src/Modules/Admin/Views/audit-logs.html.twig`

**Error Logs:**
- `src/Shared/Application/ErrorLog/{ErrorLogRecord,ErrorLogFilter,ErrorLogDirectory,ErrorLogResolver}.php`
- `src/Shared/Infrastructure/Persistence/{PdoErrorLogDirectory,PdoErrorLogResolver}.php`
- `src/Modules/Admin/Application/ErrorLogs/{ErrorLogRow,ErrorLogsFilterState,ErrorLogsScreenResult,ErrorLogsScreenHandler}.php`
- `src/Modules/Admin/Application/ErrorLogs/SetErrorLogResolution/{SetErrorLogResolutionCommand,SetErrorLogResolutionHandler}.php`
- `src/Http/Admin/{AdminErrorLogsAction,AdminErrorLogResolutionAction}.php`
- `src/Modules/Admin/Views/error-logs.html.twig`

**Settings (placeholder):**
- `src/Http/Admin/AdminSettingsAction.php`
- `src/Modules/Admin/Views/settings.html.twig`

**Shared CSS/JS:**
- `src/Public/admin.css` (shell + component styling for every screen above)

**Tooling (new, this phase):**
- `tools/screenshots/{screenshot.js,screenshot-click.js,screenshot-drag.js,seed-demo-data.php,seed-provider-customers.php,package.json}` plus its `node_modules/` (Playwright + playwright-core)

**Tests and test doubles** — see Tests and Validation for exact counts; new files:
- `tests/Unit/Modules/Admin/Application/{AdminPermissionsTest,AuthenticateAdminHandlerTest,LogoutAdminHandlerTest,SessionAdminAuthenticatorTest}.php`
- `tests/Unit/Modules/Admin/Application/Dashboard/HomeDashboardHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/Sales/SalesListHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/Customers/CustomersListHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/Packaging/{PackagesTabHandlerTest,GroupsTabHandlerTest,CreatePackageForAdmin/CreatePackageForAdminHandlerTest,UpdatePackageForAdmin/UpdatePackageForAdminHandlerTest,SetGroupPackagePriceForAdmin/SetGroupPackagePriceForAdminHandlerTest,ReorderPricingGroupPackages/ReorderPricingGroupPackagesHandlerTest}.php`
- `tests/Unit/Modules/Admin/Application/Providers/{AccountsTabHandlerTest,GroupsTabHandlerTest,UpdateProviderAccountForAdmin/UpdateProviderAccountForAdminHandlerTest,UpdateProviderGroupForAdmin/UpdateProviderGroupForAdminHandlerTest,ManageProviderGroupAccountsForAdmin/ManageProviderGroupAccountsForAdminHandlerTest}.php`
- `tests/Unit/Modules/Admin/Application/Vouchers/{VouchersScreenHandlerTest,UpdateVoucherForAdmin/UpdateVoucherForAdminHandlerTest}.php`
- `tests/Unit/Modules/Admin/Application/Clients/ClientsScreenHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/{CreateAdminUser/CreateAdminUserHandlerTest,SetAdminUserStatus/SetAdminUserStatusHandlerTest,ResetAdminUserPassword/ResetAdminUserPasswordHandlerTest,AdminUsers/AdminUsersScreenHandlerTest}.php`
- `tests/Unit/Modules/Admin/Application/AuditLogs/AuditLogsScreenHandlerTest.php`
- `tests/Unit/Modules/Admin/Application/ErrorLogs/{ErrorLogsScreenHandlerTest,SetErrorLogResolutionHandlerTest}.php`
- `tests/Unit/Shared/Http/AdminAuthenticationMiddlewareTest.php`
- `tests/Support/{InMemoryAdminUserRepository,InMemoryAdminSessionRepository,InMemoryAdminLoginAttemptRepository,StubAdminAuthenticator,InMemoryPackageDirectory,InMemoryPackageProviderDefinitionDirectory,InMemoryPriceListDirectory,StubVoucherDirectory,StubVoucherRedemptionDirectory,InMemoryAuditLogDirectory,InMemoryErrorLogDirectory,InMemoryErrorLogResolver}.php`

Settings has no test file (nothing to unit-test in a static placeholder with no permission gate
or data).

## Files Modified

- `src/Config/routes.php` — every `/admin/*` route added this phase (auth, all eleven screens'
  GET/POST endpoints).
- `src/Config/container.php` — Twig `ViewRenderer` factory; `AuditLogDirectory` →
  `PdoAuditLogDirectory`; `ErrorLogDirectory` → `PdoErrorLogDirectory`; `ErrorLogResolver` →
  `PdoErrorLogResolver`.
- `src/Bootstrap/ContainerFactory.php` — registered `src/Modules/Admin/Infrastructure/definitions.php`.
- `src/Modules/Admin/Application/AdminPermission.php` — added `ErrorLogsResolve = 'error_logs.resolve'`
  (a permission key CLAUDE.md's own suggested list omits, mirroring the `webhooks.replay` /
  `jobs.retry` / `notifications.retry` shape — see Technical Decisions).
- `src/Modules/Clients/Application/ClientDirectory.php` / `PdoClientDirectory.php` — added `all()`
  (the admin client switcher) and the additive `ClientSnapshot::$createdAt` (8th, optional
  constructor param) plus its `PdoClientDirectory` projection.
- `src/Modules/Subscriptions/Application/SubscriptionDirectory.php` / `PdoSubscriptionDirectory.php`
  — added `forClient()` (Home screen's per-client subscription count).
- `src/Modules/Payments/Domain/ProviderCustomerRepository.php` / `PdoProviderCustomerRepository.php`
  — added `forClientUser()` (Customers screen).
- `src/Shared/Domain/Money.php` — added `Money::fromDecimalInput()` (parses admin-form decimal
  amounts into minor units, rejecting invalid scale/negative/non-numeric input).
- `src/Http/Admin/AdminForm.php` — added `strArray()` (checkbox-group form fields, used by
  Vouchers' eligibility form and Providers' account-market selection).
- `src/Modules/Admin/Application/Packaging/{PackageDetail,PackagesTabHandler,GroupDetail,GroupPackageRow,GroupsTabHandler}.php`
  — extended with structured fields for Increment B's edit-modal prefill, and fixed a real
  display-order bug (see Problems Encountered #6).
- `src/Modules/Admin/Views/layouts/base.html.twig` — added an `alpine_components` block (loaded
  non-deferred, before the Alpine CDN script) and a `scripts` block.
- `src/Modules/Admin/Views/login.html.twig` — renamed `.btn-primary` usage to a dedicated
  `.login-submit` class (see Problems Encountered #7).
- `src/Modules/Clients/Domain/ApiKeyPrefix.php` / `src/Modules/Clients/Application/Authenticate/ApiKeyAuthenticator.php`
  — added `ApiKeyPrefix::mode()`; fixed a real client-API-breaking bug (see Problems Encountered #3).
- `src/Modules/Pricing/Infrastructure/PdoPricingGroupPackageRepository.php`,
  `src/Modules/Vouchers/Infrastructure/PdoVoucherRepository.php`,
  `src/Shared/Infrastructure/Persistence/PdoIdempotencyStore.php`,
  `src/Modules/Providers/Infrastructure/PdoProviderAccountRepository.php`,
  `src/Modules/Packages/Infrastructure/PdoPackageRepository.php`,
  `src/Modules/Vouchers/Infrastructure/PdoVoucherCurrencyDiscountRepository.php`,
  `src/Modules/Pricing/Infrastructure/PdoPriceListPackageRepository.php`,
  `src/Modules/Pricing/Infrastructure/PdoDefaultPackagePriceRepository.php` — real production bug
  fix, duplicate named-parameter bindings under native (non-emulated) prepared statements (see
  Problems Encountered #2 and #5).
- `src/Database/Seeds/{PricingSeeder.php,data/countries.json}` — fixed a missing Switzerland seed
  row and a missing seeder dependency declaration (see Problems Encountered #1).
- `src/Public/admin.css` — base `.status-pill` given a muted fallback background (previously
  unmapped statuses rendered as unstyled bare text — hit `confirmed`/`reserved`/`released`/
  `disabled` on the Vouchers screen); four new level/resolution pill variants added for Error Logs
  (`status-critical`, `status-error`, `status-resolved`, `status-unresolved`).
- `tests/Support/{InMemoryClientDirectory,InMemoryProviderCustomerRepository,InMemorySubscriptionDirectory,InMemorySubscriptionRepository,StubClientDirectory}.php`
  — updated to satisfy the widened `ClientDirectory`/`ProviderCustomerRepository`/`SubscriptionDirectory`
  interfaces above.
- `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php` — updated for the
  `ApiKeyPrefix::mode()` fix.

## Implementation Details

**Auth.** `AdminUser` (Domain aggregate: `name`, `email`, `role` are `readonly`; only
`setPasswordHash()`/`setStatus()` mutate it) + `PdoAdminUserRepository`. Login is a DB-backed
hashed session token (`AdminSession.token_hash`, SHA-256 of a random cookie value, mirroring
`ClientApiKey`'s existing hash-and-compare pattern — Phase 27 Q3), set as an `HttpOnly` cookie via
`AdminLoginSubmitAction` → `AuthenticateAdminHandler`, which enforces a 5-failed-attempts / 15-
minute lockout window backed by `admin_login_attempts`. `AdminAuthenticationMiddleware` resolves
the cookie into an `AdminContext` on every `/admin/*` request; `AdminLogoutAction` revokes the
session row.

**RBAC.** Code-defined, not DB-backed (Phase 27's DB-design confirmation): `AdminRole` (`admin`,
`support_agent`) and `AdminPermission` (a PHP enum covering every key CLAUDE.md's Admin Panel
Role-Based Permission Requirement suggests, plus the one addition described in Technical
Decisions). `AdminPermissions::for(AdminRole)` returns the fixed permission set per role; every
write action calls `AdminPermissionGuard::allows($context, AdminPermission::X)` before doing
anything, independently of whether the UI already hid the corresponding button — verified for
every screen with a live `support_agent` account returning 403 on every write endpoint.

**Views.** Twig (`twig/twig`, Phase 27 Q4 — a user-specified choice, not the two options
originally offered), rendered by `Shared\Http\ViewRenderer`, extending
`layouts/{base,shell}.html.twig`. Alpine.js (CDN) + hand-written CSS (`src/Public/admin.css`)
matching the design's oklch color tokens — no Tailwind, no build step. An `alpine_components`
block was added to the base layout specifically so screen-specific JS defining Alpine data
functions loads *before* Alpine's own CDN script (see Problems Encountered #8).

**Client switcher / Test-mode toggle.** UI-scoped only (Phase 27 Q5) — `admin`/`support_agent`
are global roles spanning every client; the switcher (`AdminActiveClientAction`/
`AdminActiveClientCookie`, a plain cookie) only filters what a screen displays. No permission
check is ever scoped to "the active client."

**Screenshot tooling.** Playwright via Node (Phase 27 Q2), `tools/screenshots/screenshot.js` (a
`--login` flag posts the admin login form first to get a real session cookie before navigating),
plus `screenshot-click.js` (post-interaction capture) and `screenshot-drag.js` (drag-and-drop
capture, hardened mid-phase — see Problems Encountered #5).

## Database Changes

Three additive tables, confirmed via the phase's own DB-design decision
(`.claude/PhaseResults/PhaseDecisions.md`, "Database design confirmation — admin auth + RBAC
slice") before being migrated:

- **`admin_users`** — `id`, `name`, `email` (UNIQUE), `password_hash`, `role`
  (`admin`/`support_agent`), `status` (`active`/`disabled`/`locked`), timestamps.
- **`admin_sessions`** — `id`, `admin_user_id` (FK), `token_hash` (CHAR(64), UNIQUE, sha256 of the
  cookie token — never the raw token), `ip`, `user_agent`, `expires_at`, `revoked_at`, timestamps.
- **`admin_login_attempts`** — `id`, `email`, `admin_user_id` (nullable FK), `ip`, `succeeded`,
  `created_at`, indexed on `(email, created_at)` for the lockout check.

No existing table was altered. `audit_logs` and `error_logs` (used by the Audit Logs / Error Logs
screens) were **not** created this phase — both already existed from an earlier migration
(`20260908140002_create_audit_logs_table.php`, `20260908140003_create_error_logs_table.php`),
written with explicit docblocks anticipating this phase's admin screens; this phase only added the
read/write ports needed to expose them.

## API Changes

No `/api/v1/*` changes. Every route added this phase is under `/admin/*` (server-rendered admin
panel, session-cookie authenticated via `AdminAuthenticationMiddleware`), not part of the
client-facing API. Full route list is in `src/Config/routes.php`'s `/admin` group — one `GET` per
screen plus the write endpoints enumerated per screen in Files Created above.

## Tests and Validation

**Tests created:** 27 new test files under `tests/Unit/Modules/Admin/` and
`tests/Unit/Shared/Http/AdminAuthenticationMiddlewareTest.php`, plus 12 new test doubles under
`tests/Support/`. Actually executed:

```
vendor/bin/phpunit tests/Unit/Modules/Admin tests/Unit/Shared/Http/AdminAuthenticationMiddlewareTest.php
```
Real captured result: **141 tests, 471 assertions, OK.**

**Tests modified:** `tests/Support/{InMemoryClientDirectory,InMemoryProviderCustomerRepository,InMemorySubscriptionDirectory,InMemorySubscriptionRepository,StubClientDirectory}.php`
(widened interfaces), `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php` (the
`ApiKeyPrefix::mode()` fix).

**Full project test suite**, actually executed:

```
composer test
```
Real captured result:
```
716 tests, 2535 assertions
OK (716 tests, 2535 assertions)
```

**Static analysis**, actually executed:

```
composer stan
```
Real captured result:
```
1052/1052 [============================] 100%
[OK] No errors
```

**Code style**, actually executed:

```
composer cs:fix
```
Clean on the final run (0 files needed fixing; one earlier run in this phase auto-fixed an
import-ordering issue in `src/Config/routes.php`, re-verified with `composer stan` afterward).

**Live DB validation** (every screen, against a real standalone MySQL 8.4 container,
`gomrok-mysql-standalone`, `.env`'s `DB_PORT=3307`) — summarized per screen in the corresponding
`.claude/Changelog.md` entries (2026-09-15/16, "Phase 27" sections); representative examples:
created/edited/disabled/re-enabled real clients and vouchers and admin users and verified table
rows directly via `mysql` client queries, not just HTTP redirects; issued and revoked real client
API keys; created and reordered real pricing-group/provider-group priority chains and confirmed
persistence via direct DB reads (this caught bug #5 below); resolved and reopened real
`error_logs` rows (including genuine rows produced by real runtime errors during this phase's own
testing, e.g. two authentic `SQLSTATE[HY093]` errors and one authentic invalid-API-key rejection —
not fabricated data).

**RBAC validation** (every write-capable screen): a temporary `support_agent` admin user was
created directly in the database, logged in through the real login form, every write endpoint on
that screen was curled and confirmed to return 403, the corresponding read view was confirmed to
return 200, and the rendered HTML was grepped to confirm zero reachable write triggers (only inert
`<template>` markup, if any, matched). The temporary user and its session row were deleted after
each screen's validation.

**Playwright screenshots**, all under `tools/screenshots/out/`: `login.png`, `home*.png`,
`sales*.png`, `customers*.png`, `packaging-*.png` plus `phase27-incb/` (10 images), `phase27-providers/`
(10), `phase27-vouchers/` (9), `phase27-clients/` (7), `phase27-admin-users/` (4),
`phase27-audit-logs/` (4), `phase27-error-logs/` (4), `phase27-settings/` (1) — 49 Phase-27-labeled
screenshots in total, plus the earlier Home/Sales/Customers/Packaging-Increment-A captures from
the same phase.

## Technical Decisions

All formally recorded in `.claude/PhaseResults/PhaseDecisions.md` under "Phase 27 — Admin Module
Views and Panels" (newest-first; this list is oldest-to-newest for narrative order):

1. **Sequencing (Q1):** incremental, screen by screen — build auth/RBAC/shell first, validate the
   screenshot tool on one trivial screen, then build and validate each remaining screen in turn.
2. **Screenshot tooling (Q2):** Playwright via Node.
3. **Session mechanism (Q3):** DB-backed hashed token in an `HttpOnly` cookie, not native PHP
   sessions — matches CLAUDE.md's explicit "store session tokens hashed" wording and gives
   explicit per-session revocation.
4. **View templating (Q4):** Twig — a user-specified choice outside the two options offered
   (plain PHP templates vs. `league/plates`).
5. **Client switcher / Test-mode toggle scope (Q5):** UI-scoped display filter only; RBAC and
   permission checks are never scoped to "the currently active client" — roles are global.
6. **Roles/permissions storage (DB-design confirmation):** code-defined (`AdminPermission` PHP
   enum + `AdminPermissions::for()`), not DB-backed `admin_roles`/`admin_permissions` tables — no
   admin-editable permission UI is in this phase's scope, so a DB-backed system would be
   speculative flexibility nobody asked for.
7. **Packaging & Pricing scope split (user-specified, mid-phase):** built as Increment A
   (read-only master-detail over both tabs, validated and screenshotted) then Increment B (full
   create/edit/reorder/enable-disable) in the same phase, per an explicit instruction not to defer
   Increment B to a later phase.
8. **Providers screen build scope (Q1):** full read+write in one pass, since every needed
   Application-layer handler already existed from Phases 8–10 — unlike Packaging, no new business
   logic needed inventing before the write layer could be built.
9. **Secret-reveal scope (Q2):** "Reveal" only ever shows the last 4 digits of a provider secret,
   never the full decrypted value — CLAUDE.md's "no sensitive provider credentials... displayed in
   plain text" rule is unambiguous.
10. **Screen ordering after Vouchers/Clients (judgment call, not asked — follows directly from
    objective facts):** Admin Users was built next rather than the sidebar's literal next item
    (Notifications), because `src/Modules/Notifications/` doesn't exist and building it would mean
    inventing an entire business domain out of phase order (that module is Phase 28's scope);
    Admin Users already had a complete Domain aggregate with no Application-layer CRUD yet — the
    same "orchestrate an existing domain" shape as every other screen.
11. **Audit Logs / Vouchers redemption-history read-only (judgment calls, disclosed not asked):**
    both follow directly from existing rules (CLAUDE.md's "viewing" wording for audit logs;
    `.claude/Voucher.md` §7's checkout-owned redemption lifecycle for vouchers) rather than being
    genuine either/or preferences.
12. **Error Logs write scope (this session, following the standing full-functionality
    instruction):** unlike Audit Logs, Error Logs got a real "mark resolved"/"reopen" write
    action, because the `error_logs` migration's own docblock already documented
    `resolved_at`/`resolved_by` as existing to support exactly that action — a genuine,
    schema-backed write surface, not a fabricated one.
13. **New permission key `error_logs.resolve` (this session):** added because CLAUDE.md's
    suggested permission list has `error_logs.view` but no write counterpart, unlike its own
    parallel `webhooks.view`/`.replay`, `jobs.view`/`.retry`, `notifications.view`/`.retry` pairs;
    reusing `.view` would have let `support_agent` — who CLAUDE.md says "cannot modify... system-
    level configuration" — resolve/reopen errors.
14. **Settings — investigated, not invented (this session, per explicit user instruction):** no
    real backing domain/config model exists anywhere (`src/Config/{Settings,DatabaseSettings}.php`
    are plain env-var loaders, not DB-backed or admin-editable; no migration or domain aggregate
    named `Settings` exists; CLAUDE.md never lists a settings-management capability; Phases.md's
    own Phase 27 scope lists "Settings" only as a sidebar label, and separately states "undesigned
    screens render a neutral titled placeholder"). Reported to the user with the exact gap; user
    chose the neutral-placeholder resolution, which was then built with no new domain/schema.

## Problems Encountered

All eight are genuine production bugs found by actually running the application during this
phase's live-validation work — not caught by any pre-existing unit test, since none of them
exercised real database round-trips or the real HTTP/browser path:

1. `CountriesSeeder` was missing a Switzerland row despite `PricingSeeder`'s `dach` group
   referencing it, and `PricingSeeder` itself had no declared dependency on `CountriesSeeder`
   running first.
2. `PdoIdempotencyStore` bound the same named SQL parameter (`:now`) twice in one `INSERT`/`UPDATE`
   statement — silently tolerated under MySQL's emulated prepares but throws `SQLSTATE[HY093]:
   Invalid parameter number` under native (non-emulated) prepares.
3. `ApiKeyAuthenticator` passed the raw `ApiKeyPrefix` enum value (`gk_test`/`gk_live`) as
   `AuthenticatedClient::$keyMode`, instead of the normalized `test`/`live` string
   `SelectCheckoutProviderHandler` actually compares against — silently broke every real
   `POST /api/v1/payments` and `/subscriptions` call in production with
   `checkout_attempt.unknown_mode`. Never caught before because every existing test constructs
   commands with a literal `'test'` string, bypassing the real authenticator entirely.
4. `GroupsTabHandler`'s disabled-price-list fallback mislabeled the resolved list in its own
   header (Increment A, cosmetic).
5. `PdoPricingGroupPackageRepository::save()` and `PdoVoucherRepository::save()`'s `UPDATE`
   branches both reused a shared parameter-building helper written for `INSERT`, binding columns
   (`group_id`/`package_id`, respectively `client_id`/`code`) that don't appear in the `UPDATE`
   statement at all — throwing `SQLSTATE[HY093]: Invalid parameter number` under native prepares
   on every *update* of an existing row (every reorder, every repeat price override, every voucher
   edit).
6. `GroupsTabHandler` built its package rows from `PackageDirectory::forClient()`'s natural
   iteration order and never actually sorted by `pricing_group_packages.display_order` — the
   drag-reorder write path worked, but reordering had no visible effect on the rendered screen.
7. A CSS class collision: the login page's full-width submit button and the newly introduced
   `.btn-primary` button-system class shared one name, so every new pill-sized button
   (`+ New package`, etc.) rendered as a full-width bar.
8. Alpine.js's CDN `<script>` (loaded `defer`) self-initializes as soon as it executes if
   `document.readyState` is already `interactive` (true for deferred scripts) — a *second*
   deferred script defining a screen's `xData()` function never ran in time, so every modal on
   that screen was dead (`xData is not defined`).
9. A separate silent failure discovered on the Providers screen (found via a follow-up drag test
   producing DB state inconsistent with the intended order): `packaging.js`/`providers.js`'s
   `submitOrder()` used `container.querySelector('form[data-reorder-form]')` scoped to
   `[data-reorder-list]`, but in both `packaging.html.twig` and `providers.html.twig` the hidden
   reorder `<form>` was a **sibling** of that container, not a descendant — so the lookup always
   returned `null` and the submit silently no-opped. Dragging visually reordered the DOM rows in
   the browser, but nothing was ever persisted; this was masked in the first drag screenshot test
   because it waited on `networkidle`, which resolves immediately when no navigation was ever
   triggered, so the before/after screenshots looked like a real, working reorder.

## Resolutions

1. Added the missing Switzerland row to the countries seed data and declared `PricingSeeder`'s
   dependency on `CountriesSeeder`.
2. Split the reused `:now` binding into distinct `:created_at`/`:updated_at` parameters in
   `PdoIdempotencyStore`.
3. Added `ApiKeyPrefix::mode()` (returns the normalized `live`/`test` string) and changed
   `ApiKeyAuthenticator` to pass that instead of the raw enum value.
4. Fixed the header label directly in `GroupsTabHandler`.
5. A repo-wide scan compared every shared parameter-building helper's keys against its own
   `UPDATE` statement's placeholders; `PdoPricingGroupPackageRepository` and `PdoVoucherRepository`
   were confirmed as the only two files with this exact pattern and both were fixed to bind only
   the columns their `UPDATE` branch actually sets.
6. `GroupsTabHandler` now explicitly sorts its package rows by
   `pricing_group_packages.display_order` before returning them.
7. Renamed the login page's button class to `.login-submit`, leaving `.btn-primary` exclusively
   for the new button-system.
8. Added an `alpine_components` Twig block to `layouts/base.html.twig`, placed before the Alpine
   CDN `<script>` tag and loaded non-deferred, so screen-specific Alpine data functions are always
   defined before Alpine itself initializes.
9. Moved both screens' hidden reorder `<form>` to be the last child *inside*
   `[data-reorder-list]` instead of a trailing sibling, so `querySelector` actually finds it.
   Also hardened `tools/screenshots/screenshot-drag.js` to
   `Promise.all([page.waitForNavigation(...), page.mouse.up()])` instead of a post-hoc
   `waitForLoadState('networkidle')`, so a future regression of this exact shape times out loudly
   in the screenshot script instead of silently producing a misleading "before/after" pair. Both
   screens' drag-reorder was re-verified against real database state (not just screenshots) after
   the fix.

## Deferred Work

- **Notifications** — the entire sidebar entry is unbuilt; `src/Modules/Notifications/` does not
  exist. This is explicitly Phase 28's scope ("Client callbacks / outbound notifications"), not an
  oversight of this phase.
- **Settings** — deliberately left as a neutral placeholder (see Technical Decisions #14). If a
  real operational-settings feature is wanted later (e.g. a maintenance-mode toggle or a live
  feature flag), it needs its own proposed and confirmed database design first, per CLAUDE.md's
  Database Design Confirmation Rule — not to be inferred from this placeholder's existence.
- **Provider "create product via API"** — Packaging Increment B's provider-linking action is
  manual registration only (an admin types in a remote id); no provider-adapter product-creation
  capability exists anywhere in the codebase yet.
- **Price-list deletion** — no handler/business-rule exists for deleting a price list; Increment B
  offers create + enable/disable only.
- **List-price edit-modal prefill** — the exact current amount isn't queried back into the edit
  modal (a minor UX gap, not a correctness issue; no query for it was built).
- **Provider endpoint (webhook URL) management UI** — the Providers screen shows only an active-
  endpoint count; managing `provider_account_endpoints` rows belongs to Phase 25's Webhooks
  concern, not this screen.
- **`AdminUserStatus::Locked`** — the screen supports clearing a locked status (an "Unlock"
  action) in case something sets it later, but nothing in the codebase currently sets it;
  `AuthenticateAdminHandler`'s lockout is a rolling failed-attempt count, never persisted as
  account status.
- **Admin-attributed audit entries for provider-account creation** — `CreateProviderAccountHandler`
  always audits `forSystem` (its command has no `actorId` parameter); a pre-existing gap in the
  Providers module the admin HTTP layer can't fix without changing that handler's signature.
- **Client callback-endpoint management UI** — `SetClientEndpointHandler`/`RemoveClientEndpointHandler`
  exist but managing per-purpose callback URLs was left out of the Clients screen's "stat tabs +
  New client modal" scope rather than silently bundled in.

## Final Result

The admin panel is fully functional end-to-end: an operator can log in, get locked out after
repeated failed attempts, and reach every one of eleven sidebar screens under real RBAC
enforcement (checked at both the UI and backend layer, per CLAUDE.md's explicit requirement).
Nine of those screens have complete, schema-backed CRUD/lifecycle functionality, validated against
a real database and covered by 141 new passing tests; two (Audit Logs, Settings) are deliberately
non-writable for disclosed, documented reasons rather than silently degraded. The full project
test suite (716 tests, 2535 assertions) and static analysis (1052 files, zero errors) both pass
clean. Seven unrelated production bugs surfaced by this phase's own live-testing discipline have
been fixed. Nothing from this phase has been committed to git yet, per the user's explicit
standing instruction ("no commits yet") repeated at every checkpoint.
