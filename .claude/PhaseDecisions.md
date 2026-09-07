# Phase Decisions

The permanent record of every decision question asked during every phase — the question, the
options offered, the recommendation, and the user's final selection. Updated the moment the user
answers a question, not at end of phase. See `.claude/Rule.md` §4.2.

- `.claude/Rule.md` → how decisions are asked and recorded.
- `.claude/docs/Phases.md` → roadmap, phase status, timing.
- `.claude/PhaseDecisions.md` (this file) → all questions, options, recommendations, selections.
- `PhaseResults/PhaseNNResult.md` → what was actually implemented per phase.

---

## Phase 1 — Groundwork: architecture baseline

### Q1 — Module / folder structure

**Question:** How should `src/` be organised?

**Options:**

1. **Module-based, per-module layers** — `src/Modules/<Name>/{Domain,Application,Infrastructure,Http}`
   for each domain (Clients, Providers, Packages, Pricing, Vouchers, Payments, Subscriptions,
   Webhooks, Notifications, Admin), plus `src/Shared/` and `src/Config|Database|Jobs|Public`.
   Each domain's adapters sit next to its logic; "add a module / provider" stays local.
2. **Module-based, one shared Infrastructure layer** — modules keep `Domain/Application/Http`,
   but all infrastructure lives in a single `src/Infrastructure/<concern>` tree.
3. **Classic layer-based** — `src/{Domain,Application,Infrastructure,Http}` at the top, modules
   as sub-namespaces; scatters one domain across four trees.

**Recommended:** Option 1

**Selected:** Option 1 — Module-based, per-module layers

**Status:** Decided

**Decision Notes:** ~10 real domains with independent lifecycles; matches the structure already
sketched in `CLAUDE.md`. Recorded in `.claude/docs/Architecture.md` §3–§4.

---

### Q2 — Cross-module communication

**Question:** How should modules communicate with each other?

**Options:**

1. **Direct calls + in-process domain events** — commands/queries go through small published
   `Application/` interfaces (wired by PHP-DI); reactions (e.g. `PaymentPaid` → notification +
   subscription update + reconciliation) go through a lightweight synchronous domain-event
   dispatcher.
2. **Direct calls through published interfaces only** — no event bus; downstream effects are
   explicit chained calls from the caller.
3. **Contracts-only packages per module** — each module ships a separate `*-contracts`
   namespace; implementations never referenced across modules. Overkill for one deployable.

**Recommended:** Option 1

**Selected:** Option 1 — Direct calls + in-process domain events

**Status:** Decided

**Decision Notes:** Payments is inherently event-driven; a tiny post-commit synchronous
dispatcher now avoids a disruptive retrofit. Events stay in-process — the queue, not events,
crosses process boundaries. Initial event catalogue in `.claude/docs/Architecture.md` §5.

---

### Q3 — Identifier strategy

**Question:** What identifier strategy for database rows?

**Options:**

1. **BIGINT auto-increment PK + public ULID column** — internal PK/FK are `BIGINT` (tight InnoDB
   clustered index, fast joins); every externally-visible row also carries a unique
   `ulid CHAR(26)` used in APIs, callbacks, and admin URLs.
2. **ULID (or UUIDv7) as the primary key everywhere** — one id per row, sortable, safe to
   expose; ~2× PK/index storage and more write amplification at payment volume.
3. **UUIDv4 primary keys everywhere** — random insert order fragments the InnoDB clustered
   index; worst option for a high-write payments table.

**Recommended:** Option 1

**Selected:** Option 1 — BIGINT auto-increment PK + public ULID column

**Status:** Decided

**Decision Notes:** Numeric `id` never leaves the DB boundary; all external references use the
ULID. `.claude/docs/Architecture.md` §6.

---

### Q4 — Money representation

**Question:** How are monetary amounts represented?

**Options:**

1. **`brick/money` wrapped in our own `Money` VO** — library handles rounding modes, per-currency
   scale (JPY 0dp / USD 2dp / BHD 3dp), allocation for splits, overflow; our `Shared\Domain\Money`
   VO is the only thing the domain sees. Store `amount_minor BIGINT` + `currency CHAR(3)`.
2. **Custom `Money` VO + bcmath, no dependency** — reimplement rounding/scale/allocation
   ourselves; easy to get subtly wrong.
3. **DECIMAL columns + decimal strings** — no Money type; invites precision drift and scattered
   formatting.

**Recommended:** Option 1

**Selected:** Option 1 — `brick/money` wrapped in our own `Money` VO

**Status:** Decided

**Decision Notes:** Domain depends on our `Money`, never on `brick`. `.claude/docs/Architecture.md` §7.

---

### Q5 — Provider-adapter interface shape

**Question:** What shape for the provider-adapter interface?

**Options:**

1. **Hybrid: required core port + optional capability interfaces** — every adapter implements a
   core `PaymentProviderPort` (create payment, get status, verify/parse webhook,
   `getCapabilities`); extra abilities are separate interfaces (`SupportsSubscriptions`,
   `SupportsRefunds`, `SupportsAuthCapture`, `SupportsCustomerPortal`, `SupportsManualPolling`)
   implemented only where real. A `ProviderCapabilities` descriptor drives runtime gating.
2. **One fat `PaymentProviderPort`, throws for unsupported** — every method on one interface;
   unsupported operations throw `UnsupportedCapabilityException`. Runtime landmine, not a
   type-level fact.
3. **Fully segregated ports (ISP)** — no required core; `OneTimePaymentPort`, `SubscriptionPort`,
   `RefundPort`, `WebhookVerifierPort`, … each implemented selectively. Most interfaces / DI
   wiring; "every provider does X" still enforced by convention.

**Recommended:** Option 1

**Selected:** Option 1 — Hybrid: required core port + optional capability interfaces

**Status:** Decided

**Decision Notes:** `ZiraatAdapter` simply doesn't implement `SupportsSubscriptions`, so
"subscribe via Ziraat" is impossible at the type level. `.claude/docs/Architecture.md` §8.

---

## Phase 2 — Project scaffold & toolchain

### Q1 — Local development environment

**Question:** How is the local dev environment provided?

**Options:**

1. **Docker Compose** — `docker-compose.yml` with `php` (8.x CLI/FPM), `mysql:8`, and a web
   entry (nginx or `php -S`). One `docker compose up` gets a new dev/CI running identically.
2. **Documented bare-metal** — no containers; a `.claude/docs/Commands.md` documents the required
   PHP extensions, a local MySQL, and the setup steps. Lightest, but "works on my machine" risk.
3. **DDEV** — opinionated Docker wrapper for PHP apps; `ddev config` + `ddev start`. Least
   boilerplate, adds a tool dependency.

**Recommended:** Option 1

**Selected:** Option 1 — Docker Compose

**Status:** Decided

**Decision Notes:** `docker-compose.yml` will pin PHP 8.x + required extensions (bcmath/gmp,
pdo_mysql, intl) and `mysql:8`, so clone-and-run and CI are deterministic and the Phase 4+
migration/integration tests get a real MySQL.

---

### Q2 — PHP version target

**Question:** Which PHP version does the project target?

**Options:**

1. **PHP 8.4** (current stable) — newest language features (property hooks, asymmetric
   visibility, `new` in initializers everywhere), longest support runway.
2. **PHP 8.3** — one version back; broadest hosting/CI/library compatibility, still supported,
   fewer "too new" surprises.
3. **PHP 8.2** — conservative; supported until end of 2026 only.

**Recommended:** Option 1

**Selected:** Option 1 — PHP 8.4

**Status:** Decided

**Decision Notes:** Greenfield, runtime controlled via Docker; Slim 4 / PHP-DI / brick/money /
PHPUnit 11 all support 8.4. `composer.json` `"require": {"php": "~8.4.0"}`, Docker base
`php:8.4`.

---

### Q3 — Database migration tool

**Question:** What runs and tracks schema migrations?

**Options:**

1. **robmorgan/phinx** — standalone, framework-agnostic; PHP migration classes with
   `up()`/`down()` or the change API, a versions table, `phinx migrate` / `rollback` / `status`,
   plus seeders. Mature, widely used.
2. **doctrine/migrations** — powerful, diff-generation if paired with the Doctrine schema tools;
   heavier, pulls in more Doctrine packages, oriented toward Doctrine DBAL.
3. **A thin custom runner** — a small `Database/` script that applies ordered `*.sql` (or PHP)
   files and records them in a `migrations` table. Zero dependency, but we maintain it.
4. **laravel/framework's migrator via a bridge** — familiar API, but drags Illuminate packages
   into a Slim app.

**Recommended:** Option 1

**Selected:** Option 1 — robmorgan/phinx

**Status:** Decided

**Decision Notes:** Matches the plain-PDO / own-repositories stack (no ORM), manages DDL + a
versions table without imposing a schema abstraction, and its seeders cover the Phase 4/5
reference data (`countries`, `currencies`, capability catalogue).

---

### Q4 — Test framework

**Question:** Which test framework?

**Options:**

1. **PHPUnit 11** — the standard; xUnit-style classes, data providers, `#[Test]` attributes.
   Every static-analysis tool, IDE, and CI integration targets it first. `Unit/` vs
   `Integration/` split via test suites in `phpunit.xml`.
2. **Pest 3** — expressive closure-based syntax on top of PHPUnit; nice DX, arch-testing plugin,
   parallel by default. Adds a layer; some tooling/stack-traces are less direct.

**Recommended:** Option 1

**Selected:** Option 1 — PHPUnit 11

**Status:** Decided

**Decision Notes:** `Unit` vs `Integration` split via `<testsuite>`s in `phpunit.xml`; first-class
support in PHPStan, IDEs, Phinx, PHP-DI.

---

### Q5 — Static analysis + code style

**Question:** What static-analysis and code-style tooling, and how strict?

**Options:**

1. **PHPStan (max) + php-cs-fixer (PSR-12)** — PHPStan level `max` with
   `phpstan/phpstan-strict-rules` and a phpunit extension; php-cs-fixer with a `@PSR12` +
   light extras ruleset. Both run in `composer` scripts and CI.
2. **Psalm (level 1) + php-cs-fixer** — Psalm instead of PHPStan; strong type-flow analysis,
   taint analysis available, but a smaller plugin ecosystem and slower-moving lately.
3. **Both PHPStan and Psalm + php-cs-fixer** — maximum coverage, double the config and CI time,
   occasional contradictory findings.
4. **PHPStan (level 6, ratcheting up) + php-cs-fixer** — start mid-level with a baseline, raise
   the level as the codebase grows.

**Recommended:** Option 1

**Selected:** Option 1 — PHPStan (max) + strict-rules + php-cs-fixer (PSR-12)

**Status:** Decided

**Decision Notes:** PHPStan level `max` from the first commit with `phpstan/phpstan-strict-rules`
and `phpstan/phpstan-phpunit`; php-cs-fixer `@PSR12` + light extras. Both in `composer` scripts
and CI. No baseline — new code must pass clean.

---

### Q6 — Home for app-level (non-module) code

**Question:** `src/Bootstrap/` (composition root) and `src/Http/` (endpoints owned by no module,
e.g. `/health`) were created during scaffolding but are not in the `.claude/docs/Architecture.md`
§4 folder sketch. Where should they live? *(Raised mid-phase per Rule.md §4.2.)*

**Options:**

1. **Keep them as top-level app dirs** — `src/Bootstrap/` and `src/Http/` sit alongside
   `Modules/`, `Shared/`, `Config/`, …; `Architecture.md` §4 is updated to list them.
2. **Fold into `src/Shared/`** — `AppFactory` → `src/Shared/Bootstrap/`,
   `HealthAction` → `src/Shared/Http/`; keeps the top-level `src/` list exactly as sketched.

**Recommended:** Option 1

**Selected:** Option 1 — keep `src/Bootstrap/` and `src/Http/` as top-level app dirs

**Status:** Decided

**Decision Notes:** The composition root and non-module endpoints must live somewhere; `Shared/`
is reserved for the Phase 3 domain kernel. `Architecture.md` §4 updated to list both, with a
one-line rationale.

---
