# Phases.md — Gomrok implementation plan

## Status & execution tracking

Filled in as phases run (see *How each phase runs* → step 6). Blank fields are `—`.

**Column meanings**

- **Status** — ☐ not started · ◐ in progress · ☑ done
- **Start / End Datetime** — ISO 8601 local, `YYYY-MM-DD HH:MM`. Start = when the phase's work
  begins (step 1); End = when the completion summary is delivered (step 4).
- **Est. Duration** — estimated hands-on time (Claude working + user review), *not* wall-clock
  across calendar days. Set now as a rough range; refined during the phase's 5 questions.
- **Actual Duration** — real hands-on time, summed across working sessions if the phase spans
  more than one.
- **Tokens Used** — model tokens consumed across the phase, read from session usage at
  completion.

| # | Phase | Status | Start Datetime | End Datetime | Est. Duration | Actual Duration | Tokens Used |
| --- | --- | :---: | --- | --- | --- | --- | --- |
| 1 | Groundwork: architecture baseline | ☑ | 2026-09-06 17:42 | 2026-09-06 17:54 | 2–4h | 12m | N/A |
| 2 | Project scaffold & toolchain | ☑ | 2026-09-06 18:20 | 2026-09-06 18:50 | 3–5h | 30m | N/A |
| 3 | Shared kernel | ☑ | 2026-09-08 11:34 | 2026-09-08 12:22 | 3–5h | 48m | N/A |
| 4 | Database foundations: base & reference tables only | ☑ | 2026-09-08 12:28 | 2026-09-08 13:30 | 2–4h | 62m | N/A |
| 5 | Migration workflow & cross-cutting tables | ☑ | 2026-09-08 13:35 | 2026-09-08 15:04 | 2–4h | 1h 29m | N/A |
| 6 | Clients module: domain & persistence | ☑ | 2026-09-08 15:14 | 2026-09-08 16:33 | 3–5h | 1h 19m | N/A |
| 7 | Client API authentication & scoping | ☑ | 2026-09-08 16:56 | 2026-09-08 17:49 | 3–5h | 53m | N/A |
| 8 | Providers module: types & capability model | ☑ | 2026-09-09 17:11 | 2026-09-09 17:53 | 4–6h | 42m | N/A |
| 9 | Provider accounts (per client) | ☑ | 2026-09-09 19:21 | 2026-09-09 20:16 | 4–6h | 55m | N/A |
| 10 | Country provider configuration & routing resolution | ☑ | 2026-09-09 20:25 | 2026-09-09 23:37 | 5–8h | ~3h 10m | N/A |
| 11 | Packages module: catalog & availability | ☑ | 2026-09-09 23:51 | 2026-09-10 00:43 | 4–6h | ~52m | N/A |
| 12 | Package purchase capabilities & provider definitions | ☑ | 2026-09-10 10:16 | 2026-09-10 13:16 | 4–6h | ~3h | N/A |
| 13 | Pricing module: default prices & pricing groups | ☑ | 2026-09-10 13:23 | 2026-09-10 13:58 | 4–6h | ~35m | N/A |
| 14 | Pricing overrides & resolution engine | ☑ | 2026-09-10 14:04 | 2026-09-10 16:05 | 6–9h | 2h 01m | N/A |
| 15 | Price lists (A/B) | ☑ | 2026-09-10 14:57 | 2026-09-10 16:04 | 3–5h | 1h 07m | N/A |
| 16 | Vouchers module: definitions & eligibility | ☑ | 2026-09-10 16:08 | 2026-09-10 20:01 | 4–6h | 3h 53m | N/A |
| 17 | Voucher validation, discount calc & redemption lifecycle | ☑ | 2026-09-11 12:52 | 2026-09-11 14:03 | 5–8h | 1h 11m | N/A |
| 18 | Decision snapshots | ☑ | 2026-09-11 14:19 | 2026-09-11 15:44 | 2–4h | 1h 25m | N/A |
| 19 | Resolution API endpoints | ☐ | — | — | 3–5h | — | — |
| 20 | Payments module: aggregate & lifecycle | ☐ | — | — | 4–6h | — | — |
| 21 | Provider adapter port & Stripe adapter | ☐ | — | — | 6–9h | — | — |
| 22 | Mollie & PayPal adapters | ☐ | — | — | 6–9h | — | — |
| 23 | Ziraat adapter | ☐ | — | — | 4–7h | — | — |
| 24 | Payment creation flow | ☐ | — | — | 5–8h | — | — |
| 25 | Webhooks module | ☐ | — | — | 5–8h | — | — |
| 26 | Subscriptions module | ☐ | — | — | 6–9h | — | — |
| 27 | **Admin Module Views and Panels** | ☐ | — | — | 12–20h | — | — |
| 28 | Client callbacks / outbound notifications | ☐ | — | — | 4–6h | — | — |
| 29 | Background jobs, reconciliation & observability | ☐ | — | — | 6–9h | — | — |
| 30 | Hardening, docs & first-client go-live | ☐ | — | — | 6–10h | — | — |
| | **Total** | | | | **~130–210h** | **—** | **—** |

---

This is the **30-phase plan** referenced by `CLAUDE.md`. It was derived from `CLAUDE.md`
(the project spec); where the two disagree, `CLAUDE.md` wins and this file is corrected to match.

The phase count and the meaning of **Phase 27 — Admin Module Views and Panels** are fixed by
`CLAUDE.md`'s *Visual and Output Verification Rule*. Adding a brand-new phase, or materially
changing an existing phase's scope, requires explicit confirmation from the user first.

## How each phase runs

Every phase, without exception:

1. **Before any code:** explain the phase, then ask the phase's decision questions **one at a
   time** — Q1, wait for the answer, record it in `.claude/PhaseResults/PhaseDecisions.md`, then Q2, … (*Interactive
   Phase Rule* / `.claude/Rule.md` §4.2). Show phase #, `QN of M`, options + explanations + a
   recommendation; never auto-select it. Don't start a decision-dependent part until its
   questions are answered. Never re-ask an already-answered question.
2. **Before any schema change:** present the database design for that phase's tables and get
   explicit confirmation (*Database Design Confirmation Rule*). No migrations before confirmation.
3. **During:** add the relevant tests as code is written, not afterwards. Keep domain logic free
   of Slim / MySQL / provider SDKs.
4. **After:** produce the phase-completion summary — what was implemented, files
   created/updated/removed, DB changes, tests added + how to run them, **real captured evidence**
   (screenshots for any view, real output for any API/CLI/test/migration run — from Phase 27 on
   this is enforceable for the panel), known limitations, next phase.
5. **Docs:** update `.claude/Changelog.md` every meaningful change; keep `database-design.md`,
   `database-diagram.md` (+ `.html`) and `db_explain.md` in lock-step with the schema.
6. **Tracking:** in the *Status & execution tracking* table (top of file) — at step 1 set **Status** to ◐ and record **Start
   Datetime** (and confirm/refine **Estimated Duration** during the 5 questions); at step 4 set
   **Status** to ☑ and record **End Datetime**, **Actual Duration**, and **Tokens Used**. If a
   phase spans several working sessions, keep the first Start, update End each session, and let
   Actual Duration be the sum of the sessions' hands-on time (not the wall-clock span).
7. **Result file:** write exactly one `.claude/PhaseResults/PhaseNNResult.md` (zero-padded) from
   `.claude/PhaseResults/Template.md`, recording what *actually* happened — specific paths and names, not
   this plan text; never planned work as completed work. **The phase is not fully complete until
   this file is complete.** Never overwrite or delete an earlier phase's result file; if a later
   phase changes earlier code, document that in the later phase's file. See
   `.claude/PhaseResults/Readme.md`.
8. **Decision check:** before marking the phase complete, verify all decision questions were
   asked, all are answered, `.claude/PhaseResults/PhaseDecisions.md` matches the user's selections, and the
   implementation follows them. If implementation diverges from a recorded decision, stop and
   ask. (`.claude/Rule.md` §4.2)

## Database strategy — incremental, never upfront

The schema is **not** designed in one big pass. There is no whole-system schema-design phase.

- **Phase 4** creates only the **base / reference tables** — the stable, low-churn foundations
  that don't depend on business decisions still being made: `countries`, `currencies`,
  `provider_types`, the capability catalogue, and similar lookup data.
- **Every other table is designed and created inside the phase that first needs it.** When a
  phase needs tables, it proposes just that slice (tables, fields, indexes, FKs, constraints,
  nullable/JSON fields, security-sensitive fields, alternatives), gets explicit confirmation,
  then writes the migration — and updates `database-design.md` / `database-diagram.md` (+ `.html`)
  / `db_explain.md` in the same change.
- A later phase may add columns or tables to an earlier module's schema as the "who connects to
  what" picture firms up. That's expected — same propose → confirm → migrate → update-docs loop,
  as an additive migration.
- `CLAUDE.md`'s "Required Database Concepts" list is the **catalogue of what will eventually
  exist**, not a blueprint to build on day one.

---

## Phase 1 — Groundwork: architecture baseline

**Goal:** decide the target shape and write it down. Greenfield — there is no predecessor system
to analyze. No application code.

**Scope:**
- Produce, from first principles: Gomrok architecture overview, module map, folder layout,
  provider-adapter interface sketch, capability model sketch, pricing/voucher/routing resolution
  model sketch.
- Seed the docs: `.claude/Changelog.md`, `.claude/knowledge/Knowledge.md` (payments domain notes), keep this `.claude/docs/Phases.md`
  status table current.

**DB:** none.

**Exit:** the architecture documents exist and the user has confirmed the direction.

## Phase 2 — Project scaffold & toolchain

**Goal:** a bootable, testable, empty Slim 4 app.

**Scope:**
- `composer.json` with PSR-4 autoload, latest stable PHP, strict types baseline.
- Slim 4 skeleton, PHP-DI container (`src/Config/container.php`), env-based config (`.env`,
  no secrets committed), routing skeleton.
- MySQL connection wiring; local dev setup (documented, or `docker-compose`); a migration runner.
- PHPUnit harness split into `unit` / `integration`; PHPStan/Psalm; php-cs-fixer.
- `GET /health` endpoint. Record all commands in `.claude/Changelog.md` / a `Commands.md` note.

**DB:** connection only; no business tables.

**Exit:** `composer test` passes a trivial test; the app boots; `/health` returns 200.

## Phase 3 — Shared kernel

**Goal:** the primitives every module depends on.

**Scope:**
- `Shared/Domain`: `Money`, `Currency`, `CountryCode`, `Result` / `DomainError`, `Clock`.
  **No ID types** — identifiers are plain `int` (Phase 1 Q3, changed 2026-09-08; Phase 3 Q2).
- `Shared/Infrastructure`: structured JSON logger carrying a `correlation_id` (plain random hex
  string, not a ULID); request-id middleware; PDO helpers; a transaction helper.
- `Shared/Http`: base action, JSON response helper, error handler mapping `DomainError` → HTTP
  status.

**DB:** none.

**Exit:** value objects and logger covered by unit tests; error handler mapping tested.

## Phase 4 — Database foundations: base & reference tables only

**Goal:** the migration runner plus only the stable, business-decision-free lookup tables.
**No whole-system schema design.** Every module's own tables are designed later, in that module's
phase (see *Database strategy* above).

**Scope:**
- Migration runner + workflow (`up` / `down`, repeatable, runs in CI). Namespaced Phinx
  migrations, `up()`/`down()` (Phase 4 Q2).
- Exactly three reference tables — the low-churn foundations other tables will point at:
  `countries`, `currencies`, `provider_types` (`requires_registration`, `api_capable`). Seed
  them: currencies from `brick/money`'s ISO provider, countries from a bundled JSON (Q3).
  *(The provider-capability catalogue is deferred to Phase 8 — Q4.)*
- Start the DB docs as living skeletons that grow per phase: `.claude/docs/database-design.md`
  (canonical spec), `.claude/docs/database-diagram.md` + `.claude/docs/database-diagram.html`
  (Mermaid ER per module + module map), `.claude/docs/db_explain.md` (per-table guide), root
  `mkdocs.yml` (Material theme, Mermaid via `pymdownx.superfences`). At this phase they describe
  only the reference tables.

**DB:** reference / lookup tables only. Propose this small slice (fields, keys, seed data), get
confirmation, then migrate.

**Exit:** migrations run up and down cleanly; reference tables seeded; the four DB docs exist and
match what was created.

## Phase 5 — Migration workflow & cross-cutting tables

**Goal:** the tables that nearly every later phase writes to, so they exist before the modules do.

**Scope:**
- `idempotency_keys` table + `IdempotencyStore` port / `PdoIdempotencyStore` adapter +
  `IdempotencyMiddleware` (lock + entity mapping; wired to routes in Phase 7).
- `audit_logs` and `error_logs` tables + `AuditLogWriter` / `ErrorLogWriter` ports and PDO
  adapters (`error_logs` backs the admin Error Logs screen in Phase 27; explicit writer only).
- `PurgeExpiredIdempotencyKeys` job + `bin/PurgeIdempotencyKeys.php` + `composer idempotency:purge`.
- Harden the migration workflow: `composer db:reset` / `db:fresh`, a migration round-trip
  integration test, and a GitHub Actions CI workflow (`.github/workflows/Ci.yml`, `mysql:8.4`
  service) — the first place migrations actually execute.

**DB:** `idempotency_keys`, `audit_logs`, `error_logs` (schema confirmed by the user). `client_id`
columns carry no FK yet — Phase 6 adds them.

**Decisions:** `PhaseResults/PhaseDecisions.md` Phase 5 Q1–Q5.

**Exit:** ☑ code + docs complete, `composer ci` green (63 unit tests), PHPStan `max` clean, CI
workflow authored. Migration up/down + writer integration tests self-skip locally (no Docker);
they execute in GitHub Actions.

## Phase 6 — Clients module: domain & persistence

**Goal:** the tenant model.

First `src/Modules/<Name>/` module — sets the `Domain` / `Application` / `Infrastructure` /
`Http` layout and a per-module `Infrastructure/definitions.php` merged by `ContainerFactory`.

**Scope (done):**
- Tables: `clients`, `client_api_keys` (secret stored `sha256`), `client_endpoints`. Plus the
  additive `AddClientFksToCrossCuttingTables` migration (the Phase 5 FKs).
- `Client` aggregate + `ClientEndpoint` / `ClientApiKey`; `ClientSlug` VO; `ClientRepository` /
  `ClientApiKeyRepository` ports + PDO adapters; `ClientDirectory` published read port +
  `ClientSnapshot` DTO.
- Use cases: `CreateClient`, `UpdateClient`, `DisableClient`, `EnableClient`,
  `SetClientEndpoint`, `RemoveClientEndpoint`, `IssueApiKey`, `RevokeApiKey` — each returns
  `Result` / `DomainError` and writes an `audit_logs` row (actor = system).
- Shared: `TokenGenerator` port + `RandomTokenGenerator`; `ReferenceCatalog` port +
  `PdoReferenceCatalog`; `Transactions` port (`TransactionRunner` implements it); `Row`
  coercion helper; `DomainEvent` marker + `Domain/Events/*` (defined, not dispatched).
- CLI: `bin/{CreateClient,IssueClientApiKey,RevokeClientApiKey,ListClients}.php`
  (`composer client:*`). `ClientsSeeder` — one `local-dev` client + fixed dev key, gated to
  `APP_ENV ∈ {local, testing}`.

**Decisions:** `PhaseResults/PhaseDecisions.md` Phase 6 Q1–Q5 (SHA-256 + `key_id` lookup · typed columns +
`client_endpoints` · required immutable `slug` · soft reversible disable, keys untouched · CLI +
env-gated dev seeder).

**Exit:** ☑ client lifecycle, API-key hashing/verification, uniqueness + FK constraints tested —
104 unit tests; integration tests (`ClientsPersistenceTest`, round-trip) self-skip locally,
run in CI. Domain events not dispatched (no subscriber yet); admin-actor audit propagation
deferred to Phase 26.

## Phase 7 — Client API authentication & scoping

**Goal:** every API request is authenticated and locked to one client.

Gomrok's first real API surface — `/api/v1` group + `GET /api/v1/me`. `/health` stays public.

**Scope (done):**
- `ClientAuthenticator` port (Shared\Http) + `ApiKeyAuthenticator` (Clients) — `Authorization:
  Bearer gk_<mode>_<key_id>.<secret>` → point-read by `key_id` → constant-time compare → key
  status/expiry → client status → throttled `last_used_at` → record the attempt.
- `AuthenticationMiddleware` (Shared\Http) on the `/api/v1` group — populates `ClientContext`
  (per-request holder) + `authClient` / `authClientId` / `authKeyMode` request attributes;
  `401 unauthorized` (any credential fault) / `403 client_disabled` (Q4).
- `IdempotencyMiddleware` attached to the group with `requireKeyOnWrites` — a keyless write →
  `400 idempotency_key_required` (Q5).
- Table `client_auth_attempts` + `AuthAttemptLog` port + `PdoAuthAttemptLog`.
- `ClientApiKeyRepository::touchLastUsed()`; `AppFactory::create()` takes an optional container
  for functional tests.

**Decisions:** `PhaseResults/PhaseDecisions.md` Phase 7 Q1–Q5 (Bearer only · `ClientContext` holder + attrs ·
throttled `last_used_at` · 401/403 generic bodies · Idempotency-Key required on writes,
failed-attempt logging, no rate limiting yet).

**DB:** `client_auth_attempts` (schema confirmed). No changes to `client_api_keys`.

**Exit:** ☑ auth pass/fail, 401/403 shape, `ClientContext` population, keyless-write 400, and
the public/authenticated route split tested — 124 unit tests + a boot-the-real-app routing test;
persistence + round-trip integration tests self-skip locally, run in CI. Rate limiting deferred
to its own concern.

## Phase 8 — Providers module: types & capability model

**Goal:** model what each provider *can* do before wiring any SDK.

Second `src/Modules/` module (`Providers`). Models capability, not integration — no SDKs.

**Scope (done):**
- `Capability` enum (19 flags) + seeded `provider_capabilities` mirror table (Q1). Separate
  `PurchaseType` enum — one_time_payment / recurring_payment / auto_charge / subscription — kept
  distinct from capabilities (Q2).
- `provider_type_capabilities` + `provider_type_purchase_types` join tables (Q3), seeded for
  **stripe + paypal** (Q5); ziraat/mollie deferred to their adapter phases.
- `PaymentMethod` enum + `MethodCapabilityRules` in-code placeholder (Q4 — no method tables yet).
- `ProviderTypeDeclaration` VO + `ProviderCapabilities` VO + `ProviderTypeDeclarations` port
  (`PdoProviderTypeDeclarations`). `ProviderCapabilityResolver` (type declaration − method
  exclusions; account/client-country narrowing seams for Phases 9–10). Published `ProviderCatalog`
  + `ProviderTypeSummary`.

**Decisions:** `PhaseResults/PhaseDecisions.md` Phase 8 Q1–Q5.

**DB:** `provider_capabilities`, `provider_type_capabilities`, `provider_type_purchase_types`
(schema confirmed). `provider_types` unchanged. Payment-method tables deferred to Phase 9/12.

**Exit:** ☑ capability-resolution matrix tested — Stripe (full) vs Ziraat (charge-only) and
Mollie card vs Mollie PayPal, via `ProviderCapabilityResolverTest` (Ziraat/Mollie as in-code
fixtures since only stripe/paypal are persisted). 141 unit tests; persistence + round-trip
integration tests self-skip locally, run in CI.

## Phase 9 — Provider accounts (per client)

**Goal:** a client can connect one or more accounts per provider type.

Extends the `Providers` module.

**Scope (done):**
- `provider_accounts` (client-scoped slug, provider type, `mode` live/test, status, public key,
  `SecretCipher`-encrypted secret + `secret_last_four`), `provider_account_endpoints`
  (webhook / callback / return — token + encrypted signing secret, Q3), `provider_account_countries`
  + `provider_account_methods` (Q4). Account-level capability narrowing inherited from the type.
- `Shared\Application\SecretCipher` port + `SodiumSecretCipher` (libsodium, key from
  `APP_ENCRYPTION_KEY` — Q1).
- `ProviderAccount` aggregate + `ProviderAccountRepository`; `ProviderAccountDirectory` (published
  read, no secrets) + `ProviderAccountSummary`; `ProviderAccountCredentials` (the decrypt path,
  adapters only).
- Use cases: `CreateProviderAccount`, `SetProviderAccountMarkets`, `RotateProviderAccountSecret`,
  `AddProviderAccountEndpoint`, `ChangeProviderAccountStatus` (disable/enable) — `Result` +
  audit; secret rotation / endpoint changes are sensitive audited actions.
- CLI: `bin/{CreateProviderAccount,RotateProviderAccountSecret,AddProviderAccountEndpoint,ListProviderAccounts}.php`
  (`composer provider-account:*`). `ProviderAccountsSeeder` — env-gated `local-dev` test Stripe
  account + webhook endpoint.

**Decisions:** `PhaseResults/PhaseDecisions.md` Phase 9 Q1–Q5.

**DB:** 4 tables (schema confirmed). Non-schema: `APP_ENCRYPTION_KEY` added to `Settings` /
`.env.example` / CI. Payment-method tables still deferred (Q4).

**Exit:** ☑ multiple accounts of one type per client, secret encrypt/decrypt round-trip, and
`secret_last_four` masking tested — 157 unit tests; persistence + round-trip integration tests
self-skip locally, run in CI.

## Phase 10 — Country provider configuration & routing resolution

**Goal:** deterministic "which provider for this purchase" with no silent downgrades.

**Scope (as built — Q1 chose provider groups as the *single* mechanism; no
`country_provider_configs` / `_priorities` / `country_payment_methods` /
`country_purchase_capabilities` tables):**
- `provider_groups` (named country group + optional `device_type` + optional `currency_code` +
  `is_default` fallback) with child tables `provider_group_countries` / `_accounts` (ordered
  `priority`) / `_purchase_types` / `_methods`.
- `ProviderRouter` resolution engine: resolve group for country/device → intersect the group's
  purchase-type set with each account's provider-type declaration → filter by mode / status /
  served country / method → ordered candidate list, chosen = first. Returns an in-memory
  `RoutingDecision` VO (candidates + rejection reasons) with a `toArray()` / `fromArray()`
  snapshot contract; **no routing-decision table this phase** (Q4).
- Unsupported combinations rejected explicitly (`provider_routing.*` `DomainError`s) — never
  downgraded.
- Ziraat + Mollie provider-type capability declarations seeded (Q5); env-gated
  `ProviderGroupsSeeder` wires the `local-dev` demo.

**DB:** `provider_groups` + 4 child tables (**5**). Total 22 tables.

**Exit:** ☑ Turkey/Ziraat one-time-only, Germany Mollie card+PayPal, NL PayPal-only, and
subscription-in-Turkey rejection — all covered by `ProviderRouterTest` + captured
`RoutingEvidence` output.

## Phase 11 — Packages module: catalog & availability

**Goal:** the client-owned package catalogue.

**Scope (as built):**
- `packages` (client-scoped, `UNIQUE (client_id, code)`, `status` = active/disabled, `metadata`
  JSON) + 4 dedicated availability join tables (`package_countries` / `_currencies` /
  `_payment_methods` / `_provider_accounts`, Q1). Each dimension **fails open** — empty = every
  value (Q2).
- `Package` aggregate + `PackageRepository` + `PdoPackageRepository`; use cases `CreatePackage`,
  `UpdatePackage`, `ChangePackageStatus`, `SetPackageAvailability` (full-replace, audited).
- `PackageCatalog::resolve(client, country, currency, ?method)` → `list<ResolvedPackage>`
  (no price / purchase types yet — Phases 13 / 12). `PackageDirectory` read port.
- CLI `composer package:*` + env-gated `PackagesSeeder`. **`GET /api/v1/packages` deferred to
  Phase 13** (Q3) — mounted once price + purchase types exist.

**DB:** `packages` + 4 availability tables (**5**). Total 27 tables.

**Exit:** ☑ per-client code uniqueness (`PackageHandlersTest`), availability filtering + narrowed
resolved-list output (`PackageCatalogTest` + captured `CatalogEvidence` output).

## Phase 12 — Package purchase capabilities & provider definitions

**Goal:** what a package can be sold *as*, and where it exists on the provider side.

**Scope (as built):**
- `package_purchase_capabilities` (`PurchaseType` value + `has_trial` / `trial_days` /
  `duration_months` per row, Q1 — **fail closed**: no rows = not sellable);
  `package_country_purchase_capabilities` (per-country override that **replaces** the global set,
  Q2). `badge` / `highlighted` / `client_package_id` added to `packages` (additive migration).
- `PackagePurchaseCapabilityResolver::for(package, ?country)` → country-effective set; the
  market/provider ∩ is the payment flow's job (Phase 17). `ResolvedPackage` / `PackageCatalog`
  extended; a package with no country-effective caps is dropped from the catalogue.
- `package_provider_definitions` (one row per `(package, provider account)`, lazily created,
  `sync_state` 4-state machine, Q3). Manual `remote_id` accepted; provider-API creation deferred
  to Phases 21–23. Editing a package flips `synced` definitions → `drift` in-transaction (Q5).
- Use cases `SetPackagePurchaseCapabilities` / `SetPackageCountryPurchaseCapabilities` /
  `LinkPackageProvider` / `ChangePackageProviderSyncState`; `UpdatePackage` extended.
  `composer package:*` CLI + `PackagesSeeder`.

**DB:** 3 new tables + `ALTER packages` (3 cols). Total 30 tables.

**Exit:** ☑ purchase-type gating (global + country override, fail-closed drop) —
`PackagePurchaseCapabilityTest` / `PackageCatalogTest` / `PackageCapabilityHandlersTest`;
provider-definition state transitions (`not_created → synced → drift → not_needed` + edit sweep)
— `PackageProviderDefinitionTest` / `PackageCapabilityHandlersTest` + captured `CapabilityEvidence`.

## Phase 13 — Pricing module: default prices & pricing groups

**Goal:** baseline prices and country grouping.

**Scope (as built):**
- `pricing_groups` (priority-ordered, **overlapping** country membership, optional `device_type`,
  one currency, one `is_default` fallback pinned last — Q1) + `pricing_group_countries`.
- `default_package_prices` (one baseline per package) + `client_exchange_rates` (client-configured
  effective-dated FX — Q2). `status=default` cross-currency resolves convert via the client rate.
- `pricing_group_packages` (per pair: `status` default/override/disabled + amount/currency +
  name/badge/highlight overrides + `display_order`; no row = implicit default — Q3).
- `PriceResolver` / `PriceCatalog` → `ResolvedPrice` (`source` baseline/converted/group_override).
- `GET /api/v1/packages` + `GET /api/v1/pricing/resolve` mounted (Q4 — `GET` not `POST` so the
  write-idempotency middleware doesn't demand a key). `pricing:*` CLI + `PricingSeeder` (Q5).

**DB:** `pricing_groups` + 4 more (**5**). Total 35 tables.

**Exit:** ☑ priority group match (`Global iOS` ordered before `DACH` wins for a German iOS buyer),
fallback group, cross-currency conversion (29.00 EUR → 31.32 USD @ 1.08) — `PriceResolverTest` +
`PricingPersistenceTest` + captured `PricingEvidence`.

## Phase 14 — Pricing overrides & resolution engine

**Goal:** one deterministic price out of many layered rules.

**As built:**
- A single `price_rules` table (Q1) keyed by **7 nullable dimensions** (Q2): `pricing_group_id`,
  `country_code`, `provider_account_id`, `payment_method`, `purchase_type`,
  `subscription_interval` (new `SubscriptionInterval` enum — monthly/quarterly/yearly),
  `currency_code`. Null = wildcard. `is_available` + `amount_minor` on the row (Q4).
- `PriceRuleResolver` (Q5, dedicated class) picks the winner (Q3): most matched dimensions →
  fixed dimension priority `subscription_interval > purchase_type > payment_method >
  provider_account_id > currency_code > country_code > pricing_group_id` → highest `id`.
- `PriceResolver` gains `?method` / `?purchaseType` / `?interval` / `?providerAccountId` and an
  `applyRules` step after the Phase 13 base price → `ResolvedPrice.source = dimension_override`
  (+ `applied_rule_id` / `applied_dimensions`), or hard `pricing.combination_unavailable` with
  **no fallback** when the most-specific matching rule is unavailable.
- `SetPriceRule` / `DeletePriceRule` use cases + `PriceRuleDirectory` (list); `bin/SetPriceRule.php`,
  `bin/DeletePriceRule.php`, `bin/ListPriceRules.php` + `composer pricing:set-rule|delete-rule|list-rules`.
- `GET /api/v1/pricing/resolve` gains `method` / `purchase_type` / `interval` query params;
  `GET /api/v1/packages` stays base-price only. `PricingSeeder` seeds two `pro` rules.

**DB:** `price_rules` (1 table, migration `20260910150001`).

**Exit:** precedence matrix tested (`PriceRuleResolverTest`, `PriceResolverTest`); a disabled
combination resolves to `pricing.combination_unavailable`, never a wrong price.

## Phase 15 — Price lists (A/B)

**Goal:** run price experiments inside a pricing group without breaking consistency for a user.

**Scope (as narrowed — decisions Phase 15 Q1–Q5):**
- `price_lists` per pricing group (`id`, `client_id`, `pricing_group_id`, `name`, `is_control`,
  `factor DECIMAL`, `is_enabled`). Every group has a **real control row** (`is_control = 1`,
  `factor 1.0000`, undeletable / cannot be disabled) — created with the group + backfilled for
  existing groups (Q1 → Option 2).
- `price_list_packages` — optional exact per-list-per-package amount that overrides the factor
  (Q2 → Option 3).
- Resolver step: after the Phase 13 base amount, before the Phase 14 `price_rules` row — apply
  the list's explicit package amount, else `base × factor` (Q3 → Option 1).
- `CreatePriceList` / `EnablePriceList` / `DisablePriceList` / `SetPriceListPackagePrice` audited
  handlers + `pricing:*` CLI + seeder.

**Deferred out of this phase (Phase 15 Q4 + Q5 — user, 2026-09-10):** the
`price_list_assignments` table, the deterministic visitor→list bucket-assignment / hashing
service, disable-fallback reassignment, and the `visitor_ref` query params on `/packages` +
`/pricing/resolve`. **Re-ask Phase 15 Q4 and Q5 (full option lists) at Phase 24 — Payment
creation flow — before implementing any of it.** Until then the resolver always uses the control
list.

**DB:** `price_lists`, `price_list_packages`. (`price_list_assignments` deferred to Phase 24.)

**Exit:** control row is auto-created + un-removable; factor math + explicit per-package override
+ precedence vs. Phase 14 `price_rules` tested. *(The original "stable assignment / even split /
disable-fallback" exit criteria move to Phase 24 with the deferred decision.)*

## Phase 16 — Vouchers module: definitions & eligibility

**Goal:** model vouchers and the rules that gate them.

**As built (decisions Phase 16 Q1–Q5; full rule set: `.claude/Voucher.md`):**
- `vouchers` — client-scoped, `code` unique per client; validity window, `first_purchase_only`,
  minimum purchase (amount + currency); a **default discount** (`default_discount_type`
  `none`/`percentage`/`full` — never `fixed`) + **usage-limit columns**
  (`max_total_redemptions` / `max_per_user` / `max_per_client`, each nullable = unlimited — Q3,
  user chose columns over a `voucher_usage_limits` child table) + `redeemed_count` (global
  tally, Phase 17-owned).
- `voucher_eligibility_rules` — one `(voucher, dimension, value)` table (Q1) across country /
  currency / package / provider account / payment method / purchase type / subscription
  interval; OR within a dimension, AND across, no rows = unrestricted.
- `voucher_currency_discounts` — a per-currency **override** of the default discount (Q2,
  extended by the user): override row → else default → else (`none`) not applicable; each
  override fully specifies its own type + optional cap.
- `VoucherEligibilityEvaluator` (Q4) reports **every** unmet condition (not fail-fast): status,
  window, client scope, every restricted dimension, discount applicability, same-currency
  minimum purchase, first-purchase (unknown vs. known-false are distinct reasons), and the
  **global** usage cap. Per-user/per-client caps deferred to Phase 17 behind a declared
  `VoucherUsagePort`.
- Audited handlers (`CreateVoucher`, `UpdateVoucher`, `SetVoucherEligibility` full-replace,
  `SetVoucherCurrencyDiscount` / `RemoveVoucherCurrencyDiscount`, `SetVoucherUsageLimits`,
  `ChangeVoucherStatus`) + `voucher:*` CLI + env-gated seeder (`WELCOME10`, `EU5`).

**DB:** `vouchers`, `voucher_eligibility_rules`, `voucher_currency_discounts` (3 tables).

**Exit:** eligibility evaluation across every dimension tested
(`VoucherEligibilityEvaluatorTest`).

## Phase 17 — Voucher validation, discount calc & redemption lifecycle

**Goal:** apply a voucher safely, exactly once.

**As built (decisions Phase 17 Q1–Q5; full rule set: `.claude/Voucher.md`):**
- `voucher_redemptions` — reserve → confirm/release, identified by a caller-supplied
  `attempt_reference` (Q1; Phase 20 will pass the payment id). Three states: `reserved` (counts
  toward every cap immediately) → terminal `confirmed` or terminal `released` (Q2); **no
  automatic expiry** of an abandoned reservation — deferred to a Phase 29 background job.
- Concurrency safety (Q3): every reserve/confirm/release handler opens a transaction and
  `SELECT ... FOR UPDATE`s the `vouchers` row first — the de facto per-voucher mutex — then
  re-checks the global/per-user/per-client caps under that lock before writing.
- `VoucherDiscountCalculator` (Q4) resolves the applicable discount (override → default →
  inapplicable), rounds HALF_EVEN, clamps to the configured `max_discount_minor` cap then to the
  price, and reports both `nominalDiscountMinor` (pre-clamp) and `appliedDiscountMinor`
  (post-clamp) — never rejects, never goes negative.
- `ReserveVoucherRedemptionHandler` (re-runs the now usage-aware `VoucherEligibilityEvaluator`
  as the authoritative gate, inside the lock) / `ConfirmVoucherRedemptionHandler` (increments
  `vouchers.redeemed_count` once) / `ReleaseVoucherRedemptionHandler` (Q5) — all idempotent.
  `VoucherUsagePort` (declared Phase 16) is implemented by `PdoVoucherRedemptionRepository`.
- `voucher:reserve|confirm|release|list-redemptions` CLI.
- The provider only ever receives the final resolved (`payable_minor`) amount — never the
  voucher itself.

**DB:** `voucher_redemptions` (1 table, migration `20260911130001`).

**Exit:** concurrent-redemption safety (row-lock design + a sequential proof in
`VoucherRedemptionHandlersTest` / `VoucherRedemptionPersistenceTest`), lifecycle transitions
(`VoucherRedemptionTest`), and "duplicate request does not double-redeem" (idempotent replay by
`attempt_reference`) tested.

## Phase 18 — Decision snapshots

**Goal:** history never changes when rules change.

**As built (decisions Phase 18 Q1–Q5 — Q1 and Q3 were user-directed expansions of the originally
proposed options, not a choice from the presented list):**
- **New `Checkout` module** (Q2) anchored by **`checkout_attempts`** (Q1, fully specified by the
  user): the pre-payment lifecycle's parent record. `attempt_reference` is the external,
  caller-supplied idempotent key (`UNIQUE (client_id, attempt_reference)`); `id` is the internal
  relational anchor every decision-snapshot table FKs to. Reuses the Phase 17
  `attempt_reference`-as-idempotency-key device one layer up — `ReserveCheckoutVoucherHandler`
  passes the checkout attempt's own reference straight through as the voucher redemption's.
- **`CheckoutAttemptStatus`** (Q3, fully dictated by the user rather than picked from an option
  letter): a monotonic-rank state machine — 9 ranked happy-path statuses (`started` →
  `pricing_resolved` → `voucher_reserved` → `provider_selected` → `provider_checkout_created` →
  `redirected_to_provider` → `returned_from_provider` → `confirmed` → `converted_to_payment`)
  plus 4 unranked exit statuses (`failed` / `canceled` / `expired` / `abandoned`) reachable from
  any non-terminal status. A transition is valid iff it repeats the current status (no-op), or
  targets an exit, or is `converted_to_payment` from exactly `confirmed`, or has a strictly
  higher rank than the current one — **skipping ranks is allowed** (no voucher used ⇒
  `pricing_resolved → provider_selected` directly). Terminal once reached; no further transition
  accepted. **Implemented for real this phase:** `started → pricing_resolved`,
  `pricing_resolved → voucher_reserved` (voucher used) or `→ provider_selected` (no voucher),
  `voucher_reserved → provider_selected`, the no-op, any non-terminal → exit. **Modelled only**
  (rank + guards + tests, no real caller yet): `provider_checkout_created` … `confirmed` /
  `converted_to_payment` — deferred to Payments (Phase 20) and the provider adapters
  (Phase 21+), per the user's explicit instruction.
- Three write-once decision-snapshot tables, one per owning module (Q4 — thin `voucher_decision_snapshots`
  chosen): **`pricing_decision_snapshots`** (Pricing, full `ResolvedPrice::toArray()` payload),
  **`voucher_decision_snapshots`** (Vouchers, identity-only — amounts stay on `voucher_redemptions`),
  **`provider_routing_decision_snapshots`** (Providers, full `RoutingDecision::toArray()`
  payload, Phase 10 VO reused as-is). Each `UNIQUE (checkout_attempt_id)`; no repository port
  exposes an update method.
- One handler per lifecycle step + a `CheckoutAttempt::commercialSnapshot()` method (Q5):
  `CreateCheckoutAttemptHandler`, `ResolveCheckoutPricingHandler`, `ReserveCheckoutVoucherHandler`,
  `SelectCheckoutProviderHandler`, `ChangeCheckoutAttemptStatusHandler` — each advances the status
  and writes its snapshot inside one `Transactions::run()` call. `commercialSnapshot()` returns
  only the attempt's own immutable commercial context, the exact shape a future `payments` row
  (Phase 20) will copy at conversion — no checkout-attempt or decision-snapshot row is ever
  deleted or mutated by that step.
- `checkout:create|resolve-pricing|reserve-voucher|select-provider|set-status|list-attempts` CLI.

**DB:** `checkout_attempts`, `pricing_decision_snapshots`, `voucher_decision_snapshots`,
`provider_routing_decision_snapshots` (4 tables, migration `20260911150001`).

**Exit:** lifecycle transition rules (`CheckoutAttemptStatusTest`, `CheckoutAttemptTest` — skip,
no-op, backward-rejection, terminal-lock, `converted_to_payment` gating), full cross-module
happy-path + no-voucher-path wiring (`CheckoutAttemptHandlersTest`), and a real-MySQL round-trip
(`CheckoutAttemptPersistenceTest`, CI-only) all tested; `composer ci` green (332 unit tests,
1354 assertions).

## Phase 19 — Resolution API endpoints

**Goal:** clients ask Gomrok for packages and prices; they never send a price.

**Scope:**
- `GET /api/v1/packages` and `GET /api/v1/packages/{packageId}` with resolved context
  (client, country, currency, payment method, purchase type, client user when needed).
- `POST /api/v1/pricing/resolve`, `POST /api/v1/vouchers/validate`.
- Package response can include resolved price, currency, available providers, payment methods,
  purchase types, and voucher eligibility when safe to expose.

**DB:** none new.

**Exit:** HTTP-level resolution tested end to end; client scoping enforced; client-supplied
prices ignored.

## Phase 20 — Payments module: aggregate & lifecycle

**Goal:** the payment record and its state machine.

**Scope:**
- `payments`, `payment_attempts`, `provider_transactions`, `provider_customers`,
  `gateway_references` (indexed for reverse lookup from any provider id).
- Internal status enum (`created`, `pending`, `requires_action`, `authorized`, `paid`, `failed`,
  `canceled`, `expired`, `refunded`, `partially_refunded`, `disputed`, `chargeback`) + transition
  rules; unknown provider statuses stored safely, never leaked into core logic.

**DB:** payment tables, gateway references.

**Exit:** the state machine and rejection of illegal transitions tested.

## Phase 21 — Provider adapter port & Stripe adapter

**Goal:** the one interface all providers implement, plus the first real provider.

**Scope:**
- `PaymentProviderPort`: `createPayment`, `createCheckoutSession`, `createSubscription`,
  `createBillingPortalSession`, `authorizePayment`, `capturePayment`, `cancelPayment`,
  `refundPayment`, `getPaymentStatus`, `getSubscriptionStatus`, `verifyWebhookSignature`,
  `parseWebhook`, `mapProviderStatusToInternalStatus`,
  `mapProviderSubscriptionStatusToInternalStatus`, `getCapabilities`. Adapters are the only place
  a provider SDK is used. An adapter is not forced to implement an unsupported capability —
  unsupported ops are rejected via capability validation.
- Stripe adapter: hosted Checkout, Billing Portal, payment + subscription status, webhook
  verify/parse, status mapping, declared capabilities.

**DB:** none new.

**Exit:** unit tests for status mapping; integration tests against Stripe test mode.

## Phase 22 — Mollie & PayPal adapters

**Goal:** two more providers on the same port.

**Scope:**
- Mollie adapter (Checkout; method-specific capabilities — card vs PayPal — resolved separately).
- PayPal adapter (Checkout / orders + subscriptions).
- Status mapping and webhook parse/verify for both.

**DB:** none new.

**Exit:** unit mapping tests + sandbox integration tests for both.

## Phase 23 — Ziraat adapter

**Goal:** the bank-hosted, payment-only provider.

**Scope:**
- Bank-hosted payment page / 3D Secure redirect, return-URL handling, manual status polling.
- Explicitly **not** a subscription or auto-charge provider — those capabilities are absent and
  requests for them are rejected.

**DB:** none new.

**Exit:** redirect flow, status polling, and subscription-capability rejection tested.

## Phase 24 — Payment creation flow

**Goal:** the end-to-end "create a payment" path.

**Scope:**
- `POST /api/v1/payments`: authenticate → resolve package / price / voucher → resolve provider /
  method / purchase type → create the provider transaction → persist snapshots + gateway
  references → return the redirect / checkout URL. Provider-hosted UI only; Gomrok never touches
  raw card data.
- `GET /api/v1/payments/{id}`, `/status`, `POST .../cancel`, `/refund`, `/capture` — each gated
  by provider + client capability.
- **A/B price-list visitor assignment (deferred from Phase 15):** re-ask Phase 15 Q4 (stateless
  vs. persisted `price_list_assignments`) and Q5 (management surface + which endpoints persist)
  with their full option lists, then build the deterministic bucket-assignment service,
  disable-fallback reassignment, and `visitor_ref` wiring so the resolved price reflects the
  visitor's list. Exit criteria: stable assignment, even split, disable-fallback.

**DB:** `price_list_assignments` (if the re-asked Phase 15 Q4 lands on the persisted option).

**Exit:** happy path per provider (SDK mocked), capability rejections, and idempotency tested;
A/B assignment stable + even + disable-fallback tested.

## Phase 25 — Webhooks module

**Goal:** ingest provider events safely and idempotently.

**Scope:**
- `POST /api/v1/webhooks/{provider}`: store the raw event first (`webhook_events`), verify the
  signature, enqueue processing, protect against duplicates / replays by provider event id.
  Respond fast; never block on processing.
- Processor: map the event to the internal record via gateway references, update payment /
  subscription status, emit domain events.

**DB:** `webhook_events`.

**Exit:** store-first, duplicate-webhook no-double-effect, signature-failure handling, and
reverse lookup tested.

## Phase 26 — Subscriptions module

**Goal:** subscriptions with unambiguous ownership.

**Scope:**
- `subscriptions` (always exactly one client, one client-user reference, one package when
  package-based, one provider, one internal record), `subscription_events`,
  `subscription_payment_links`.
- Creation guarded: package + country + provider + payment method + client configuration must all
  allow subscription.
- Status lifecycle (`active`, `trialing`, `past_due`, `cancelled`); renewal / failed-charge /
  card-update events fed from webhooks.
- `POST /api/v1/subscriptions`, `GET /api/v1/subscriptions/{id}`,
  `POST /api/v1/subscriptions/{id}/cancel`.

**DB:** subscription tables.

**Exit:** ownership queries (gateway sub id ↔ internal record; client user → active
subscriptions) and every guard rejection tested.

## Phase 27 — Admin Module Views and Panels

**Goal:** the admin panel, matching the design, with real rendered evidence.

**Scope:**
- Admin auth: password hashing, hashed session tokens, login-attempt logging, account status
  (active / disabled / locked).
- RBAC engine: roles `admin` and `support_agent` only; enforced at **both** UI and backend/API
  level; checks consider role, permission key, client scope, action type, resource ownership.
  Audit-log every sensitive action (provider-config changes, secret rotation, refunds, retries,
  pricing / voucher / country-override / permission changes).
- Server-rendered PHP + Alpine.js + CSS shell matching the design: sidebar (Home, Sales,
  Customers, Packaging & Pricing, Providers, Vouchers | **SYSTEM**: Clients, Notifications,
  Admin users, Audit logs, Settings), top bar, active-client switcher, Test-mode toggle.
  Undesigned screens render a neutral titled placeholder.
- Screens: Home (KPIs, sparkline, recent payments), Sales (filter pills, status stat-tabs,
  expandable event timelines), Customers (ID map + subscriptions), Packaging & Pricing
  (master–detail; Packages and By-groups tabs), Providers (accounts with Reveal/Hide secrets +
  By-groups with the resolved-provider readout), Clients (stat tabs + New client modal), and the
  **Error Logs** table (timestamp, client, module, provider, message/code, correlation id,
  related payment/subscription id, full detail without leaking secrets; filterable).
- **Visual verification tooling**: install a headless-browser screenshot capability. From this
  phase on, every phase-completion summary includes real screenshots for any view and real
  captured output for any API / CLI / test / migration run.

**DB:** `admin_users`, `admin_roles`, `admin_permissions`, `admin_sessions`,
`admin_login_attempts` (design confirmed before migration).

**Exit:** RBAC matrix, backend permission enforcement, secret masking, and rendered-view smoke
tests pass — with screenshots of every implemented screen attached.

## Phase 28 — Client callbacks / outbound notifications

**Goal:** tell the client what happened, reliably.

**Scope:**
- `client_notification_logs`; per-provider-account callback keys (`buy_address`,
  `buy_onetime_address`, `buy_address_subscribe`, `cancel_address`, `payment_failed`,
  `update_card`, and future keys).
- Delivery on status changes; signed / authenticated outbound calls; retry with exponential
  backoff; dead-letter for exhausted retries; the admin Notifications screen with a manual retry
  action.

**DB:** `client_notification_logs` (+ dead-letter / failed-jobs).

**Exit:** delivery, retry/backoff, dead-letter, idempotent retries, and the admin retry action
tested — with captured evidence.

## Phase 29 — Background jobs, reconciliation & observability

**Goal:** everything slow runs off the request path, and drift is caught.

**Scope:**
- Queue + worker. Jobs: webhook processing, callback delivery + retries, payment / refund /
  subscription reconciliation, expired-payment cleanup, provider status polling, voucher
  reservation expiry, package/pricing cache refresh (if caching is introduced).
- Reconciliation reports in the admin panel; metrics, alerts, dashboards; trace logs for package
  resolution, pricing decisions, voucher validation/redemption, provider routing, payments,
  subscriptions.

**DB:** job / dead-letter tables as needed.

**Exit:** job retry/backoff, DLQ behaviour, and reconciliation drift-detection tested — with
captured evidence.

## Phase 30 — Hardening, docs & first-client go-live

**Goal:** production-ready, documented, with Televika (the first client) onboarded.

**Scope:**
- Security pass: webhook signatures, replay protection, price / package manipulation attempts,
  multi-client isolation, secret storage, idempotency under concurrency. Load / soak the payment
  and webhook paths.
- Final docs: `database-design.md` / `database-diagram.md` (+ `.html`) / `db_explain.md` in
  sync; `mkdocs` build clean; API reference; an operations runbook.
- First-client onboarding: Televika client + provider accounts + packages + pricing configured,
  integration tested end to end in test mode, then a staged go-live checklist with rollback
  steps (disable the client, revoke keys) if anything misbehaves.

**DB:** none new (final reconciliation of docs vs schema).

**Exit:** full test suite green; screenshots of every admin screen; Televika transacting
successfully in production behind a documented go-live checklist.
