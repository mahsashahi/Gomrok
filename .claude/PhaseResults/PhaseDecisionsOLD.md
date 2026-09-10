# Phase Decisions

The permanent record of every decision question asked during every phase — the question, the
options offered, the recommendation, and the user's final selection. Updated the moment the user
answers a question, not at end of phase. See `.claude/Rule.md` §4.2.

**Order: chronological, oldest first.** Phase 1 at the top, the newest phase at the bottom;
within a phase, Q1 → Qn in order. **New phase decisions are always appended to the end of this
file — never prepended.** (`.claude/Rule.md` §4.2.)

- `.claude/Rule.md` → how decisions are asked and recorded.
- `.claude/docs/Phases.md` → roadmap, phase status, timing.
- `.claude/PhaseResults/PhaseDecisions.md` (this file) → all questions, options, recommendations, selections.
- `.claude/PhaseResults/PhaseNNResult.md` → what was actually implemented per phase.

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

**Previously Selected:** Option 1 — BIGINT auto-increment PK + public ULID column
**Current Selection:** **Simple `INT AUTO_INCREMENT` primary keys, no ULID, no typed IDs.**
**Changed:** 2026-09-08
**Reason:** User wants plain numeric IDs everywhere — "I do not want unnecessarily complex ID
abstractions." No ULID / UUID / typed-ID value objects / entity-specific ID classes. PK is
`INT AUTO_INCREMENT` (from 1), **not** `BIGINT`. Same plain `int` in DB and PHP.

**Status:** Decided (changed)

**Decision Notes (current):**
- Every table: `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`. Foreign keys are plain
  `INT UNSIGNED`.
- No `ulid` column, no public-reference column. The `id` is used directly in the DB, PHP, and
  (per "consistently … in the database and PHP code") in API paths / callbacks / admin URLs.
- Trade-off accepted: sequential integer IDs are guessable, so tenant isolation is enforced by
  **authorization** (every query scoped to the authenticated client — `Rule.md` §8,
  `TenantIsolation.md`), not by unguessable IDs.
- `INT UNSIGNED` max is ~4.29 billion rows per table — ample; revisit only if a single table
  realistically approaches that.
- "Do not use BIGINT" applies to **primary/foreign keys**. Money is still stored as
  `amount_minor BIGINT` (see Q4 note) because currency minor-unit totals can exceed `INT`.
- Recorded in `.claude/docs/Architecture.md` §6 and `.claude/agents/DatabaseAgent.md`.

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

## Phase 3 — Shared kernel

### Q1 — Money value object API

**Question:** What surface does `Shared\Domain\Money` expose (wrapping `brick/money`)?

**Options:**

1. **Minimal, opinionated** — `fromMinor(int, Currency)`, `toMinor()`, `zero(Currency)`,
   `plus`/`minus`/`multipliedBy`/`allocate`, `equals`, `isZero`/`isPositive`/`isNegative`,
   `currency()`. Rounding mode fixed internally (`HALF_EVEN` / banker's). No formatting, no FX.
2. **Richer** — everything in (1) plus `percentage()`, `ratioOf()`, `format(locale)`,
   `convertTo(Currency, rate)`.
3. **Thin alias** — `Money` is just a type alias / trivial subclass of `Brick\Money\Money`;
   the library API is the domain API.

**Recommended:** Option 1

**Selected:** Option 2 — richer API

**Status:** Decided

**Decision Notes:** Beyond the minimal set: `percentage()`, `ratioOf()`, `format(locale)`,
`convertTo(Currency, rate)`. `convertTo` takes an explicit rate — the caller / Pricing module
supplies it; `Money` never fetches rates. `format()` uses `ext-intl`. Rounding fixed to
`HALF_EVEN` internally.

---

### Q2 — Typed entity IDs

**Question:** How are entity identifiers modelled in the domain layer?

**Options:**

1. **Generic `Ulid` only** — `Shared\Domain\Ulid` value object; every entity just holds a
   `Ulid`. No per-entity ID type. Simplest; `PaymentId` and `ClientId` are interchangeable at
   the type level.
2. **`Shared` ships an abstract `EntityId` base; modules subclass it** — `Ulid` +
   `abstract EntityId` (wraps a `Ulid`) in Phase 3; each module adds
   `final class PaymentId extends EntityId {}` in its own phase. Compile-time safety
   (`PaymentId` ≠ `ClientId`), one line per ID type.
3. **Per-entity ID classes with no shared base** — each module hand-rolls its ID VO. Most
   boilerplate, no shared behaviour.

**Recommended:** Option 2

**Selected:** **None of the above — no ID abstraction at all.** Entity identifiers are plain
`int` in PHP, matching the `INT AUTO_INCREMENT` primary keys (see Phase 1 Q3, changed 2026-09-08).

**Status:** Decided

**Decision Notes:** User directive: "Do not use ULID, UUID, typed ID value objects, or
entity-specific ID classes … I do not want unnecessarily complex ID abstractions." So:
- No `Shared\Domain\Ulid`, no `EntityId` base, no `PaymentId` / `ClientId` classes.
- Domain entities and repositories take/return `int` for identity.
- Phase 3's Shared kernel therefore ships **no** identifier code (dropped `Ulid` + `UlidGenerator`
  from the plan). The per-request **correlation id** for logging is unrelated — it stays, as a
  plain random hex string, not a ULID.

---

### Q3 — How use cases signal failure

**Question:** How do application use cases report an *expected* failure (validation error,
"package not available in this country", "voucher expired", …) versus a genuine bug?

**Options:**

1. **`Result` type** — `Result::ok($value)` / `Result::err(DomainError)`. Use cases return a
   `Result`; expected failures are `DomainError` values, never thrown. Exceptions are reserved
   for bugs / unrecoverable infra faults. (This is what `Architecture.md` §11 already says.)
2. **Typed domain exceptions** — `throw new PackageNotAvailable(...)`; a single handler at the
   HTTP boundary maps exception → response. Familiar; control flow via exceptions.
3. **Hybrid** — `Result` for validation / business-rule failures, exceptions for
   infra/unexpected.

**Recommended:** Option 1

**Selected:** Option 3 — Hybrid

**Status:** Decided

**Decision Notes:** The boundary (so "when is it hybrid?" is not a per-call judgment):

- **Return `Result<T>` (never throw)** for anything a well-behaved caller must branch on:
  input validation, business-rule violations, eligibility / capability rejections,
  "not found" for a resource the caller named, voucher invalid/expired/exhausted, unsupported
  provider·country·method combination, idempotency conflict resolved to a prior result.
  Carried as `DomainError` (a code + message + context).
- **Throw** for programmer errors (broken invariant, illegal state), configuration errors, and
  infrastructure/transport faults (DB unavailable, provider timeout/5xx, malformed provider
  response). Provider/network faults first go through retry-with-backoff; if still failing they
  surface as an exception.
- The HTTP boundary (Phase 3 `Shared\Http` error handler) maps: `DomainError` → 4xx problem
  response (per its code); uncaught `Throwable` → 500/502, logged with stack trace + correlation
  id.
- `Architecture.md` §11 updated from "Domain returns `Result`/`DomainError`" to this hybrid.

---

### Q4 — Logging library

**Question:** What backs the structured JSON logger (must carry `correlation_id`, `client_id`,
`payment_id`, `provider`, … on every payment/subscription-flow line — `CLAUDE.md` → *Logging and
Observability*)?

**Options:**

1. **`monolog/monolog`** (PSR-3) — a `JsonFormatter` handler + a processor that injects the
   correlation-id / request context; `StreamHandler` to `stdout`. One well-known dependency.
   The domain depends only on PSR-3 `LoggerInterface`.
2. **Hand-rolled PSR-3 logger** — implement `Psr\Log\LoggerInterface` ourselves with a tiny
   JSON writer + context merger. Zero logging dependency; ~1 small class + a formatter.
3. **Custom non-PSR logger interface** — our own `Logger` contract, our own impl.

**Recommended:** Option 1

**Selected:** Option 1 — `monolog/monolog`

**Status:** Decided

**Decision Notes:** Add `monolog/monolog ^3` to `composer.json`. `Shared\Infrastructure`:
a factory that builds a `Monolog\Logger` with `JsonFormatter` on a `StreamHandler` → `stdout`,
plus a `CorrelationIdProcessor` that reads the current request's correlation id (set by the
request-id middleware) and merges it + static context (`service`, `env`) into every record.
The container binds `Psr\Log\LoggerInterface` → that logger; app code depends only on PSR-3.

---

### Q5 — Clock abstraction

**Question:** How does the domain get "now"? (Needed for timestamps, voucher validity windows,
subscription periods, retry backoff — and must be freezable in tests.)

**Options:**

1. **PSR-20 `Psr\Clock\ClockInterface`** — add `psr/clock`; `Shared\Infrastructure\SystemClock`
   returns `new DateTimeImmutable('now')`; tests use a `FrozenClock` (in `tests/` or a shared
   test double). Standard, one tiny interface-only dependency.
2. **Custom `Shared\Domain\Clock` interface** — our own one-method interface + `SystemClock` +
   `FrozenClock`. No dependency; not the ecosystem standard.
3. **No abstraction** — call `new DateTimeImmutable()` directly in domain code. Simple but
   untestable time logic.

**Recommended:** Option 1

**Selected:** Option 1 — PSR-20 `Psr\Clock\ClockInterface`

**Status:** Decided

**Decision Notes:** Add `psr/clock ^1`. `Shared\Infrastructure\SystemClock implements
Psr\Clock\ClockInterface` (`now(): DateTimeImmutable` in UTC). `tests/Support/FrozenClock.php`
for deterministic time in tests. Domain/application code type-hints `ClockInterface`.

---

## Phase 4 — Database foundations: base & reference tables only

### Q1 — Database documentation: how many files, what format

**Question:** The inherited spec (`CLAUDE.md` → *Database Diagram Maintenance Rule*) wants
`.claude/docs/database-design.md` (canonical), `database-diagram.md` (+ `.html`), `db_explain.md`,
and a root `mkdocs.yml` — three kebab-case Markdown files kept byte-identical in shape, plus an
mkdocs site. `Rule.md` §3.3 flagged the exact names as a Phase 4 decision.

**Options:**

1. **One file: `.claude/docs/Database.md`** — canonical spec + inline Mermaid ER diagram(s) +
   per-table notes + examples, all in one PascalCase file (same style as `Architecture.md`).
   `Rule.md` §5's "keep three files identical" rule collapses to "keep one file current". Drop
   `mkdocs.yml` and the standalone `.html` until there's a reason for a rendered site.
2. **Three PascalCase files** — `DatabaseDesign.md`, `DatabaseDiagram.md` (+ `.html`),
   `DbExplain.md` under `.claude/docs/`. Matches the spec's structure, renamed to our convention.
   Still the 3-file sync burden.
3. **The spec verbatim** — kebab-case `database-design.md` / `database-diagram.md` (+ `.html`) /
   `db_explain.md` + `mkdocs.yml`. Overrides `Rule.md` §3.1 for these files.

**Recommended:** Option 1

**Selected:** Option 3 — the spec verbatim (kebab-case DB docs + `mkdocs.yml`)

**Status:** Decided

**Decision Notes:** Files: `.claude/docs/database-design.md` (canonical spec),
`.claude/docs/database-diagram.md` (Mermaid ER per module + module map),
`.claude/docs/database-diagram.html` (standalone page, Mermaid via CDN — reads live from the
`.md` when served over HTTP; embedded fallback snapshot + `#catData` JSON for `file://`), and
`.claude/docs/db_explain.md` (per-table guide). Root `mkdocs.yml` (Material theme, Mermaid via
`pymdownx.superfences`). All four must stay in lock-step per `Rule.md` §5. `Rule.md` §3.1 gains
an exception entry for these five names; §3.3 note resolved; ## Project Documents + §7 get rows.

---

### Q2 — Phinx migration structure

**Question:** How are migration classes organised? (Phinx chosen — Phase 2 Q3.)

**Options:**

1. **Phinx default** — `src/Database/Migrations/YYYYMMDDHHMMSS_snake_name.php`, no namespace,
   classes extend `Phinx\Migration\AbstractMigration`, `change()`/`up()`+`down()`.
2. **Namespaced** — `Gomrok\Database\Migrations\` (PSR-4, so file/class names satisfy our
   PascalCase rule), still extending `AbstractMigration` directly.
3. **Namespaced + a thin base class** — as (2), plus
   `Gomrok\Database\Migration extends AbstractMigration` with helpers that encode the
   `DatabaseAgent.md` conventions: `refTable()` (adds the `id INT UNSIGNED AUTO_INCREMENT` PK),
   `timestamps()` (`created_at`/`updated_at`), a `clientId()` column helper, InnoDB/utf8mb4
   defaults. Every migration extends it.

**Recommended:** Option 3

**Selected:** Option 2 — namespaced, no base class

**Status:** Decided

**Decision Notes:**
- `phinx.php` → `paths.migrations` maps `Gomrok\Database\Migrations` →
  `src/Database/Migrations`; seeds similarly (`Gomrok\Database\Seeds`). Autoload is already
  covered by the `Gomrok\` → `src/` PSR-4 entry.
- Migration **classes** are namespaced PascalCase (`Gomrok\Database\Migrations\CreateCountriesTable`),
  extend `Phinx\Migration\AbstractMigration`, and use `up()` + `down()` (not `change()`) so
  rollback is always explicit.
- Migration **file names** keep Phinx's required `YYYYMMDDHHMMSS_snake_name.php` form (needed for
  `version_order: creation`) — a new `Rule.md` §3.1 exception, like `phinx.php` itself.
- The `DatabaseAgent.md` conventions (`id INT UNSIGNED AUTO_INCREMENT`, InnoDB/utf8mb4, FK/index
  naming, etc.) are followed **by hand** in each migration — no shared base class. A base class
  can still be added later without disrupting existing migrations if the repetition bites.

---

### Q3 — Reference-data source for seeding

**Question:** Where do the rows for `countries` and `currencies` come from at seed time?

**Options:**

1. **Bundled data files** — small hand-maintained JSON/CSV in `src/Database/Seeds/data/`
   (`countries.json`, currencies from a file too). No runtime dependency; fully in our control;
   we curate exactly the rows we want.
2. **Libraries** — `symfony/intl` (countries + currencies + names + localisation) or
   `league/iso3166` (countries). Always-complete ISO lists, one dependency each; names come
   localised.
3. **Mixed** — currencies seeded from **`brick/money`'s `ISOCurrencyProvider`** (already a
   dependency — gives code, numeric code, name, default fraction digits); countries from a
   bundled `countries.json` (option 1) since no current dependency lists them.

**Recommended:** Option 3

**Selected:** Option 3 — currencies from `brick/money`, countries from a bundled JSON

**Status:** Decided

**Decision Notes:**
- `currencies` seeder reads `Brick\Money\ISOCurrencyProvider::getInstance()->getAvailableCurrencies()`
  → per row: `code` (CHAR 3), `numeric_code` (SMALLINT), `name`, `minor_unit_scale` (TINYINT).
  Same source `Currency`/`Money` validate against — table can't drift from code.
- `countries` seeder reads `src/Database/Seeds/data/countries.json` — a file we maintain:
  `code` (CHAR 2, ISO 3166-1 alpha-2), `name`, `default_currency` (CHAR 3, FK-ish to currencies).
- Seed scope decided in Q5.

---

### Q4 — The "capability catalogue" table: now or later

**Question:** `Phases.md` lists a "capability catalogue" among Phase 4's reference tables. This
is the set of provider-capability flags from `Architecture.md` §8 (`one_time_payment`,
`hosted_checkout`, `subscription`, `refund`, `three_d_secure`, `manual_status_polling`, …).

**Options:**

1. **Create `provider_capabilities` now** — a reference table (`id`, `code`, `label`,
   `description`) seeded with the ~20 flags. Later phases FK to it and add
   `provider_type_capabilities` / per-account overrides.
2. **Defer to Phase 8** (Providers module: types & capability model) — Phase 4 ships only
   `countries`, `currencies`, `provider_types`. Capabilities become a PHP enum
   (`Gomrok\Modules\Providers\Domain\Capability`) plus whatever tables Phase 8 designs.
3. **Enum now + table now** — the enum is the source of truth; the seeded table exists for
   admin display / FK integrity and is kept in sync with the enum by the seeder.

**Recommended:** Option 2

**Selected:** Option 2 — defer the capability catalogue to Phase 8

**Status:** Decided

**Decision Notes:** Phase 4 ships exactly three reference tables: `countries`, `currencies`,
`provider_types`. The provider-capability model (enum and/or tables) is designed in Phase 8
(Providers module). `Phases.md` Phase 4 scope updated to drop "capability catalogue"; Phase 8
scope gains it. Minor scope narrowing, confirmed by the user per `Rule.md` §4.2.

---

### Q5 — Seed scope: how many countries / currencies

**Question:** How much reference data goes in?

**Options:**

1. **Full ISO lists** — all ~180 currencies (brick/money's whole ISO set) and ~249 countries in
   `countries.json`. Complete; some rows will never be used.
2. **Curated to Gomrok's markets** — currencies and countries limited to the regions
   `CLAUDE.md` names (Turkey, the Gulf states, the EU/EEA, the UK, the US, plus a handful of
   common others). ~15–20 countries, ~15 currencies. Add rows later as markets open.
3. **Full currencies (from brick — it's free), curated countries** — every ISO currency (the
   list is authoritative and small effort since it comes from the library), but only the ~15–20
   countries we actually operate in.

**Recommended:** Option 3

**Selected:** Option 3 — full ISO currencies, curated countries

**Status:** Decided

**Decision Notes:**
- `currencies`: every currency from `Brick\Money\ISOCurrencyProvider` (~180 rows).
- `countries`: the `CLAUDE.md`-named markets — Turkey (TR), the US (US), the UK (GB), and the
  EU/EEA + a few Gulf states: DE, FR, NL, IT, ES, IE, BE, AT, PT, SE, DK, FI, PL, AE, SA (~18
  rows). New markets are added later via a small follow-up migration/seed, not by editing this
  one.
- Exact `countries.json` contents are part of the schema proposal (next).

---

## Phase 5 — Migration workflow & cross-cutting tables

### Q1 — Idempotency-key storage model

**Question:** What does `idempotency_keys` store, and how does replay work? (Client write
requests carry an `Idempotency-Key` header — `CLAUDE.md` → *Idempotency Rules*.)

**Options:**

1. **Full replay (Stripe-style)** — `(id, client_id, idempotency_key, request_fingerprint,
   response_status, response_body, locked_at, completed_at, created_at, expires_at)`. Same key +
   same request → return the stored response verbatim. Same key + different request body →
   `422`. In-flight (locked, not completed) → `409`. Stores response bodies.
2. **Entity mapping** — `(id, client_id, idempotency_key, target_type, target_id, created_at,
   expires_at)`. The key records *which entity* the request created; on replay, look the entity
   up and return its **current** state (re-serialised), not a frozen snapshot. No response
   bodies stored.
3. **Lock only** — `(id, client_id, idempotency_key, status, target_type, target_id, created_at,
   expires_at)`. Prevents concurrent double-processing; `processing` → `409`, `done` → return
   the referenced entity's current state. Essentially (2) plus an explicit in-flight lock.

**Recommended:** Option 3

**Selected:** Option 3 — lock + entity mapping

**Status:** Decided

**Decision Notes:**
- `idempotency_keys (id, client_id, idempotency_key, request_fingerprint, status
  ENUM-as-VARCHAR{processing,done,failed}, target_type, target_id, response_status, created_at,
  updated_at, expires_at)`. `UNIQUE (client_id, idempotency_key)`.
- `request_fingerprint` = hash of method + path + body; on replay with the same key but a
  different fingerprint → `422` (key reuse for a different request). Same key + `processing` →
  `409`. Same key + `done` → re-serialise `target_type`/`target_id` and return with the stored
  `response_status`. `failed` → allow a fresh attempt (row reset).
- No response **bodies** stored — replay returns the referenced entity's current state.
- Middleware: claim the key (`INSERT … status=processing`) before the handler, mark `done`
  (+ `target_type/target_id/response_status`) after a successful write, `failed` on a thrown
  error. Retention handled in Q4.

---

### Q2 — Audit-log content model

**Question:** What does a row in `audit_logs` capture? (`CLAUDE.md` → admin security: "audit
logs for sensitive admin actions … provider config changes, secret rotation, refund actions,
retries, pricing changes, voucher changes, country override changes, permission changes".)

**Options:**

1. **Event record only** — `(id, actor_type, actor_id, client_id NULL, action, target_type,
   target_id, context JSON, correlation_id, ip, user_agent, created_at)`. `action` is a verb
   string (`payment.refunded`, `provider_config.updated`). `context` holds a free-form summary.
   No before/after values.
2. **Event + value diff** — as (1) plus `old_values JSON` / `new_values JSON` capturing the
   changed columns of the target. Answers "what exactly changed" for pricing/voucher/config
   edits without re-deriving from other logs.
3. **Event + full snapshots** — as (1) plus a complete JSON snapshot of the target row before
   and after. Heaviest; most storage; rarely need the untouched fields.

**Recommended:** Option 2

**Selected:** Option 3 — event + full before/after row snapshots

**Status:** Decided

**Decision Notes:**
- `audit_logs (id, actor_type VARCHAR{admin_user,client,system}, actor_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL, action VARCHAR, target_type VARCHAR NULL, target_id INT UNSIGNED
  NULL, before JSON NULL, after JSON NULL, context JSON NULL, correlation_id VARCHAR NULL,
  ip VARCHAR(45) NULL, user_agent VARCHAR NULL, created_at DATETIME)`.
- `before` / `after` hold the **full** target row as JSON (whole row, not just changed columns).
  On create `before` is NULL; on delete `after` is NULL.
- Secret-bearing columns are redacted before serialisation (never store raw provider secrets or
  full API keys in the audit JSON — `CLAUDE.md` security rules).
- Indexes: `idx_audit_logs_client_created (client_id, created_at)`,
  `idx_audit_logs_target (target_type, target_id)`, `idx_audit_logs_action (action)`.
- Append-only from the app side; no `updated_at`.

---

### Q3 — Error-log capture mechanism

**Question:** How do rows get into `error_logs` (the table behind the Phase 27 admin *Error
Logs* screen)?

**Options:**

1. **Explicit writer only** — an `ErrorLogWriter` port called deliberately from the
   `JsonErrorHandler` (unhandled throwables) and from infra adapters at known failure points
   (provider timeout, webhook verification failure, notification delivery failure). Full control
   over what lands there; nothing incidental.
2. **Monolog DB handler** — a custom Monolog handler writes every record at/above a threshold
   (e.g. `ERROR`) to `error_logs`. Zero call sites, but couples the table to log volume and can
   flood on a noisy dependency.
3. **Both** — the Monolog handler is the default net (threshold `ERROR`), and the explicit
   writer adds structured domain context (client_id, payment_id, provider, correlation_id) for
   the failures that matter operationally.

**Recommended:** Option 1

**Selected:** Option 1 — explicit writer only

**Status:** Decided

**Decision Notes:**
- `Gomrok\Shared\Application\ErrorLog\ErrorLogWriter` port + a MySQL adapter
  (`Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogWriter`). Domain/application code never
  touches PDO directly.
- `error_logs (id, level VARCHAR{error,critical}, source VARCHAR (e.g. http, webhook, provider,
  notification, job), message TEXT, exception_class VARCHAR NULL, code VARCHAR NULL,
  client_id INT UNSIGNED NULL, correlation_id VARCHAR NULL, context JSON NULL,
  stack_trace MEDIUMTEXT NULL, created_at DATETIME, resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL)`.
- Call sites in Phase 5: `JsonErrorHandler` (unhandled throwables → 500 path). Provider /
  webhook / notification call sites are wired in their own later phases (each phase adds its
  `ErrorLogWriter` calls).
- `resolved_at` / `resolved_by` support the Phase 27 admin "mark resolved" action; nullable, set
  only from the admin panel.
- Writing an error-log row must never throw out of the error handler — the adapter swallows its
  own failures (logs to Monolog stderr) so logging can't mask the original error.
- Indexes: `idx_error_logs_created (created_at)`, `idx_error_logs_client (client_id)`,
  `idx_error_logs_unresolved (resolved_at)`.

---

### Q4 — Idempotency-key retention

**Question:** How long does an `idempotency_keys` row live, and how is it cleared?

**Options:**

1. **`expires_at` + a cleanup job** — every row gets `expires_at = created_at + TTL`
   (recommend 24h). Replay after expiry is treated as a fresh request. A background job
   (`PurgeExpiredIdempotencyKeys`) deletes expired rows on a schedule. Table stays small.
2. **`expires_at`, lazy cleanup only** — same TTL semantics, but no scheduled job; expired rows
   are deleted opportunistically when the same key is seen again, plus an occasional manual
   `DELETE`. Simpler now, table grows unbounded between hits.
3. **Keep forever** — no `expires_at`; rows are permanent, effectively a full history of every
   client write request. Simplest logic, unbounded growth, replay always honoured.

**Recommended:** Option 1

**Selected:** Option 1 — `expires_at` + cleanup job

**Status:** Decided

**Decision Notes:**
- `idempotency_keys.expires_at DATETIME NOT NULL`, set to `created_at + 24h` (TTL constant in
  the middleware, not hard-coded per row logic).
- A key whose `expires_at` is in the past is ignored on lookup (treated as absent) even before
  the purge deletes it — so correctness never depends on the job running.
- `Gomrok\Jobs\PurgeExpiredIdempotencyKeys` — a plain invokable class,
  `DELETE FROM idempotency_keys WHERE expires_at < :now`. No job-runner dependency; it's just a
  class + a `composer idempotency:purge` script (runs it via a tiny CLI entrypoint
  `src/Jobs/bin/purge-idempotency-keys.php`). Wired into the real scheduler when the job runner
  lands (later phase).
- Same pattern is reusable for later TTL purges (voucher reservations, expired payments).

---

### Q5 — Migration-workflow hardening scope

**Question:** How far does "harden the migration workflow" go in this phase?

**Options:**

1. **Round-trip test + `db:reset` only** — a `tests/Integration` test that migrates up, asserts
   the schema, rolls every migration back down, and asserts a clean database (self-skips without
   MySQL like the others). Add `composer db:reset` (rollback-all → migrate → seed) and
   `composer db:fresh`. No CI changes.
2. **Option 1 + a GitHub Actions workflow** — as (1), plus `.github/workflows/Ci.yml` that spins
   up a `mysql:8.4` service container and runs `composer ci:full` (cs + stan + unit +
   integration) and `composer db:setup` on every push / PR. This is the first thing that will
   actually *execute* the migrations, since there's no local DB here.
3. **Option 2 + a seed/schema separation refactor** — additionally split "schema migrations"
   from "reference-data seeding" more formally (e.g. a `--environment` guard so seeders never
   run in `production` unintentionally, a documented `schema-only` vs `with-seed` setup path).

**Recommended:** Option 2

**Selected:** Option 2 — round-trip test + `db:reset` + GitHub Actions CI

**Status:** Decided

**Decision Notes:**
- `tests/Integration/MigrationRoundTripTest.php` — migrate up, assert the four Phase-4/5 tables
  exist with expected columns, roll every migration down, assert zero app tables remain (only
  `phinxlog`). Self-skips without MySQL, same guard as the other integration tests.
- `composer db:reset` = `rollback -t 0` → `migrate` → `seed`; `composer db:fresh` = same without
  seed. Documented in `Commands.md`.
- `.github/workflows/Ci.yml` — triggers on push + pull_request. `mysql:8.4` service container
  (health-checked), PHP 8.4 with required extensions, `composer install`, then `composer ci`
  (cs + stan + unit) and `composer ci:full` path: `composer db:setup` + `composer
  test:integration`. Env for the DB user/password/host provided as workflow env vars matching
  `.env.example`.
- This is the first execution of the migrations against real MySQL — the "NOT verified" caveat
  from Phase 4 is discharged by CI going green, not by local runs here.
- No seed/schema separation refactor this phase (Option 3 deferred — revisit at deployment).

---

## Phase 6 — Clients module: domain & persistence

### Q1 — API-key format, hashing, and lookup

**Question:** How is a client API key generated, stored, and matched on an incoming request?
(`CLAUDE.md` → *Admin security* / *Security Rules*: "Store only password hashes", "Store session
tokens hashed if stored in database", "Do not log … full API keys".)

**Options:**

1. **Prefixed token + SHA-256 of the secret, looked up by a stored non-secret lookup id.**
   Key shown once as `gk_live_<keyId>_<secret>` (or header `Authorization: Bearer <secret>` plus
   a `X-Client-Key-Id`). Store `key_id` (public, unique, indexed), `secret_hash =
   sha256(secret)`, `prefix` (`gk_live` / `gk_test`), `last_four`, `label`, `status`,
   `created_at`, `last_used_at`, `expires_at?`, `revoked_at?`. Auth: parse `key_id` → single
   indexed row fetch → `hash_equals(sha256(presented), stored)`. Fast (one indexed lookup),
   constant-time compare, nothing reversible stored.
2. **Opaque token + SHA-256, looked up directly by the hash.** Store only `secret_hash`
   (unique-indexed) + metadata. Auth: `sha256(presented)` → indexed lookup. Simplest; no key-id
   plumbing; but the whole token is one blob, rotation/labelling still fine, and a hash index is
   effectively a lookup key anyway.
3. **Password-hash the secret (bcrypt / Argon2id), looked up by a separate `key_id`.** Like
   option 1 but `password_hash()` instead of SHA-256. Slow by design (good against offline
   cracking of a *stolen DB*), but API keys are already 256-bit random so brute-force isn't the
   threat, and every request pays the KDF cost. Needs the `key_id` because you can't index a
   bcrypt hash.

**Recommended:** Option 1

**Selected:** Option 1 — prefixed token + SHA-256, looked up by a public `key_id`

**Status:** Decided

**Decision Notes:**
- `client_api_keys (id, client_id, key_id CHAR(x) UNIQUE, secret_hash CHAR(64), prefix
  VARCHAR{gk_live,gk_test}, last_four CHAR(4), label VARCHAR, status VARCHAR{active,revoked},
  created_at, last_used_at NULL, expires_at NULL, revoked_at NULL, revoked_by NULL)`.
- Presented as `gk_live_<key_id>.<secret>` (shown **once** at creation). Auth middleware (Phase 7)
  splits on `.`, point-reads by `key_id`, then `hash_equals(hash('sha256', $presented), $stored)`.
- `key_id` is safe to log / show in the admin panel; the secret and `secret_hash` are never
  logged. `last_four` + `prefix` give the admin UI a display string.
- `secret` = 32 bytes from `random_bytes`, base62/base64url encoded. `key_id` = shorter random
  token (e.g. 16 hex / 12 base62), unique.
- Revocation is a status flip + `revoked_at`/`revoked_by`; rows are never deleted (audit trail).
- Exact column widths finalised in the schema proposal after Q5.

---

### Q2 — Client settings storage shape

**Question:** A client carries assorted configuration — callback/notification URLs, a
notification signing secret, default currency/country, a display timezone, feature toggles.
Provider credentials, pricing, package availability, and country rules are **separate later
modules** and are out of scope here. How are the Phase 6 client settings stored?

**Options:**

1. **A few typed columns on `clients` + a dedicated `client_endpoints` table for URLs.**
   `clients` gets `default_currency CHAR(3)`, `default_country CHAR(2) NULL`, `timezone`,
   `notification_signing_secret` (for signing Gomrok→client callbacks). Callback URLs go in
   `client_endpoints (id, client_id, purpose VARCHAR{payment_status,subscription_status,...},
   url, is_active)` so a client can register several. Everything is a real column — queryable,
   constrained, migration-tracked.
2. **A single `settings JSON` column on `clients`.** All of the above lives in one JSON blob.
   Fewer tables, totally flexible, but nothing is constrained or indexable, and every read/write
   goes through app-side (de)serialisation and validation.
3. **A generic `client_settings (client_id, key, value)` key–value table.** Maximum flexibility,
   no schema churn to add a setting — but values are stringly-typed, multi-setting reads need
   pivoting, and it invites putting real relationships (endpoints) in an EAV bag.

**Recommended:** Option 1

**Selected:** Option 1 — typed columns on `clients` + `client_endpoints` table

**Status:** Decided

**Decision Notes:**
- `clients` scalar settings: `default_currency CHAR(3)` (FK → `currencies.code`),
  `default_country CHAR(2) NULL` (FK → `countries.code`), `timezone VARCHAR(64)` (IANA name,
  default `UTC`), `notification_signing_secret VARCHAR` (used to HMAC-sign Gomrok→client
  callbacks; never logged, redacted in audit).
- `client_endpoints (id, client_id, purpose VARCHAR{payment_status, subscription_status,
  refund_status, ...}, url VARCHAR, is_active TINYINT(1), created_at, updated_at)`. A client may
  register multiple endpoints; `UNIQUE (client_id, purpose)` for now (one active URL per
  purpose) — revisit if fan-out is needed.
- Feature toggles: **not** in Phase 6 — add when a real toggle exists (avoid speculative
  columns).
- `client_endpoints` gets `fk_client_endpoints_client_id → clients(id) ON DELETE CASCADE`.

---

### Q3 — Client external identifier (`slug`)

**Question:** Besides the integer `id`, does a client get a human-readable stable identifier?

**Options:**

1. **Yes — a required unique `slug`** (`VARCHAR(64)`, `^[a-z0-9][a-z0-9-]*$`, e.g. `televika`).
   Set at creation, immutable. Used in admin URLs, log lines, config references, CLI
   (`bin/CreateClient.php --slug=televika`), and as the stable key other modules' seed/config
   data refers to. `id` stays the FK everywhere.
2. **No — `id` only.** Simplest. Admin URLs and CLI use the numeric id; a `name` column exists
   for display but isn't constrained unique. Config/seed data that needs to name a client refers
   to it by id (assigned at insert, so fixtures must look it up).
3. **Optional `slug`** — nullable unique. Clients created via CLI/seed get one; any created
   another way may not. Mixed convention, `slug`-or-`id` branching at call sites.

**Recommended:** Option 1

**Selected:** Option 1 — required unique immutable `slug`

**Status:** Decided

**Decision Notes:**
- `clients.slug VARCHAR(64) NOT NULL`, `UNIQUE (slug)`, validated `^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$`
  (or 1-char `^[a-z0-9]$`) in the domain (`ClientSlug` — a small validated value object, not an
  ID type; `id` stays a plain int).
- Immutable after creation — `UpdateClient` cannot change it. `name` (display) is separately
  mutable and not unique.
- Cross-module fixtures/config/tests reference a client by `slug`; runtime FKs use `id`.
- Admin routes: `/admin/clients/{id}` for actions, `slug` shown in listings and logs.

---

### Q4 — Client status lifecycle & what `DisableClient` does

**Question:** What states can a client be in, and what happens to its API keys and in-flight
work when it is disabled?

**Options:**

1. **`active` / `disabled`, disable is soft and reversible; API keys stay as-is but auth checks
   the client status.** `DisableClient` sets `status = disabled` + `disabled_at` / `disabled_by`
   / `disabled_reason`. Keys are not touched (their own `revoked` state is independent); the
   Phase 7 auth middleware rejects any request whose client is `disabled`. Re-enable = `status =
   active`. Simple, auditable, recoverable.
2. **`active` / `disabled`, disable also cascade-revokes every API key.** As option 1 but
   `DisableClient` flips all the client's keys to `revoked` in the same transaction. Re-enabling
   the client does *not* un-revoke them — new keys must be issued. More destructive, cleaner
   "disabled means no access even if status check is bypassed".
3. **`pending` / `active` / `suspended` / `disabled`.** Full lifecycle: `pending` (created, not
   yet usable), `suspended` (temporary, e.g. billing hold), `disabled` (terminal-ish). Most
   expressive; more states to handle everywhere and most aren't needed until there's an
   onboarding flow / billing.

**Recommended:** Option 1

**Selected:** Option 1 — `active` / `disabled`, soft reversible disable, keys untouched

**Status:** Decided

**Decision Notes:**
- `clients.status VARCHAR(20) NOT NULL DEFAULT 'active'` — `active` | `disabled` (app-enforced,
  no MySQL ENUM). `disabled_at DATETIME NULL`, `disabled_by INT UNSIGNED NULL` (admin user id,
  no FK yet — admin users are Phase 26), `disabled_reason VARCHAR(255) NULL`.
- `DisableClient` use case: `active → disabled`, stamps the three columns, emits a
  `ClientDisabled` domain event, writes an `audit_logs` row. Idempotent (disabling a disabled
  client is a no-op success). `EnableClient` (the reverse) also included — clears the three
  columns, `ClientEnabled` event.
- API keys are **not** modified — a key has its own independent `revoked` state. The Phase 7
  auth middleware is the single chokepoint that rejects a request whose client is `disabled`
  (`DomainError::forbidden('client.disabled', …)`).
- `Client::isActive()` gates domain operations that need a live client.

---

### Q5 — How clients are created without an admin UI

**Question:** Phase 6 has no admin panel. How does a client (and its first API key) get into the
database?

**Options:**

1. **A CLI command only** — `bin/CreateClient.php --slug=… --name=… [--currency=EUR]` that runs
   `CreateClient` + issues one API key and prints the key **once**. A matching
   `bin/IssueClientApiKey.php --client=<slug>` and `bin/RevokeClientApiKey.php --key-id=…`.
   Real, uses the same use cases the admin panel will, safe for production onboarding until the
   UI exists. No fixture data committed.
2. **A Phinx seeder only** — `ClientsSeeder` inserts one or more dev clients with
   deterministic slugs and **fixed, known** API-key secrets (dev-only, documented as insecure).
   Zero-friction local/CI setup; but committing known secrets is a footgun and seeders bypass
   the domain use cases.
3. **Both** — the CLI command for real onboarding (option 1) **plus** a seeder that creates a
   single `local-dev` client with a fixed dev key, gated to `APP_ENV in {local, testing}` so it
   never runs in production. Convenient for `composer db:setup` and integration tests, without
   the production risk.

**Recommended:** Option 3

**Selected:** Option 3 — CLI commands + an `APP_ENV`-gated dev seeder

**Status:** Decided

**Decision Notes:**
- CLI (in `bin/`, PascalCase): `CreateClient.php` (`--slug --name [--currency --country
  --timezone]` → `CreateClient` use case + issues one key, prints `gk_…` once + a warning it
  won't be shown again), `IssueClientApiKey.php` (`--client=<slug> [--label]`),
  `RevokeClientApiKey.php` (`--key-id=<key_id>`), `ListClients.php` (slug, name, status, key
  count — no secrets). All resolve services from `ContainerFactory`.
- `ClientsSeeder` (Phinx): creates exactly one client `slug = local-dev` with a **fixed** key
  `gk_test_localdev0000.localdevsecret0000000000000000` (or similar), only when
  `APP_ENV ∈ {local, testing}` — otherwise the seeder is a no-op and logs why. Idempotent
  (`ON DUPLICATE KEY UPDATE` on `slug` / `key_id`).
- The seeder inserts rows directly (documented exception — seeders don't go through use cases),
  but reuses the domain's hashing helper so the stored `secret_hash` matches what auth expects.
- `.env.example` / `Commands.md` document the dev key.

---

## Phase 7 — Client API authentication & scoping

### Q1 — How a client presents its API key

**Question:** Which HTTP header(s) carry the `gk_<mode>_<key_id>.<secret>` token, and in what
scheme? (`ApiKeyToken::parse()` from Phase 6 already validates the token string.)

**Options:**

1. **`Authorization: Bearer <token>` only.** One standard scheme. The whole token
   (`gk_live_<key_id>.<secret>`) is the bearer credential; the middleware strips `Bearer `,
   runs `ApiKeyToken::parse()`, rejects anything else. `WWW-Authenticate: Bearer` on 401.
2. **`Authorization: Bearer <token>` **or** `X-Api-Key: <token>`.** Bearer is canonical; the
   `X-Api-Key` alias helps clients whose HTTP stack reserves `Authorization` (some webhook
   senders, some no-code tools). Two code paths, documented precedence (Authorization wins).
3. **Custom split headers — `X-Client-Key-Id: <key_id>` + `X-Client-Key-Secret: <secret>`.**
   Key id and secret sent separately; no token parsing. More header plumbing for the client,
   easier to log the id by mistake, diverges from every SDK convention.

**Recommended:** Option 1

**Selected:** Option 1 — `Authorization: Bearer <token>` only

**Status:** Decided

**Decision Notes:**
- Middleware reads `Authorization`, requires the `Bearer ` scheme (case-insensitive), takes the
  rest as the token, runs `ApiKeyToken::parse()`. Missing header / wrong scheme / unparseable
  token → 401 with `WWW-Authenticate: Bearer realm="gomrok", error="invalid_token"`.
- The `X-Api-Key` alias (option 2) can be added later without breaking clients if a real need
  appears.
- **Public endpoints (user requirement, 2026-09-08):** the auth middleware is scoped to the
  `/api/v1` route group only. `GET /health` stays **outside** it — no `Authorization` required,
  returns `200 {"status":"ok","service":"gomrok"}` and nothing else (no env / DB / secret data),
  does no I/O. Every `/api/v1/*` business endpoint requires the Bearer key.

---

### Q2 — How the authenticated client is carried through the request

**Question:** Once the middleware authenticates a request, how do downstream actions, use cases,
and repositories get "the current client" so every query is scoped to it?

**Options:**

1. **Request attribute only.** The middleware sets
   `$request->withAttribute('authClient', ClientSnapshot)` (+ `authClientId`, `authKeyMode`).
   Actions read the attribute and pass the id explicitly into every command / query. No shared
   mutable state; but the client id has to be threaded through every call signature, and a
   forgotten `WHERE client_id = …` is a silent cross-tenant leak.
2. **Request attribute + a per-request `ClientContext` holder (DI singleton).** As option 1,
   plus a `Gomrok\Shared\Http\ClientContext` (like `CorrelationId`) the middleware populates.
   Repositories and query services depend on `ClientContext` and apply the scope automatically;
   actions still get the attribute for convenience. The scope is enforced in one place per
   repository, not per call site.
3. **A scoped connection / repository decorator.** The middleware swaps in a repository (or a
   PDO wrapper) pre-bound to the client id, so scoping is structurally impossible to forget.
   Strongest guarantee; heaviest wiring (every repo needs a scoped variant, and request-scoped
   service rebinding in PHP-DI is fiddly).

**Recommended:** Option 2

**Selected:** Option 2 — request attribute + per-request `ClientContext` holder

**Status:** Decided

**Decision Notes:**
- `Gomrok\Shared\Http\ClientContext` — mutable per-request holder (DI singleton, one instance per
  request like `CorrelationId`): `set(ClientSnapshot $client, ApiKeyPrefix $keyMode)`,
  `client(): ClientSnapshot` (throws if unset — programmer error), `clientId(): int`,
  `keyMode(): ApiKeyPrefix`, `isAuthenticated(): bool`.
- `AuthenticationMiddleware` populates it **and** sets request attributes `authClient`
  (`ClientSnapshot`), `authClientId` (`int`), `authKeyMode` (`string`). The `authClientId`
  attribute is what `IdempotencyMiddleware::CLIENT_ID_ATTRIBUTE` already reads (Phase 5).
- Repositories/query services from later modules take `ClientContext` and apply
  `WHERE client_id = :ctx` themselves — the scope lives in one place per repo, not per call site
  (`TenantIsolation.md`).
- `ClientContext` is only ever populated inside the `/api/v1` group; `/health` and future public
  routes never touch it.

---

### Q3 — `last_used_at` write strategy

**Question:** The auth path can stamp `client_api_keys.last_used_at` on every request. At payment
volume that is a write on the hot path. How often is it actually written?

**Options:**

1. **Throttled write (write only if stale).** On a successful auth, if `last_used_at` is null or
   older than a threshold (recommend **5 minutes**), issue a single `UPDATE … SET last_used_at =
   :now WHERE id = :id`. Otherwise skip. Bounds writes to ~1 per key per 5 min; `last_used_at`
   is "accurate to within 5 minutes", which is all an admin/audit view needs.
2. **Write every request.** Simple, exact. One extra `UPDATE` per authenticated request — real
   write amplification and row-lock contention on a busy key.
3. **Don't track `last_used_at` yet.** Leave the column null; wire it when there's a reason
   (key-rotation reminders, stale-key cleanup). Zero hot-path cost now.

**Recommended:** Option 1

**Selected:** Option 1 — throttled write (only if `last_used_at` is stale)

**Status:** Decided

**Decision Notes:**
- Threshold constant `LAST_USED_THROTTLE = 300` seconds. On successful auth, if `last_used_at`
  is `null` or `< now - 300s`, run a single `UPDATE client_api_keys SET last_used_at = :now
  WHERE id = :id`; else skip.
- Done via a `ClientApiKeyRepository::touchLastUsed(int $id, DateTimeImmutable $now)` method (new
  in this phase) — the authenticator decides whether to call it based on the in-memory key it
  already loaded.
- The write is best-effort: a failure to stamp `last_used_at` must not fail the request (log and
  continue).
- `last_used_at` is therefore accurate to ~5 minutes — fine for the admin dormant-key view
  (Phase 26).

---

### Q4 — Auth failure responses

**Question:** What does the API return when authentication or authorization fails, and how much
does the body say?

**Options:**

1. **Two statuses, minimal generic bodies.** `401` for *anything wrong with the credential*
   (missing / malformed header, unknown `key_id`, bad secret, expired/revoked key) — body
   `{"type":"about:blank","title":"Authentication required","status":401,"code":"unauthorized"}`
   + `WWW-Authenticate: Bearer realm="gomrok"`. `403` only for *a valid key whose client is
   disabled* — `{"...","status":403,"code":"client_disabled"}`. No hint which of the 401 causes
   applied (anti-enumeration). Correlation id echoed for support.
2. **Granular error codes.** As option 1 but distinct `code`s per cause
   (`missing_authorization`, `malformed_token`, `unknown_key`, `invalid_secret`, `key_expired`,
   `key_revoked`, `client_disabled`) so clients can self-diagnose. Leaks whether a given
   `key_id` exists / whether the secret was the wrong part.
3. **401 for everything, including the disabled client.** One status, one body. Simplest;
   loses the "your key is fine, your account is off" signal that saves a support round-trip.

**Recommended:** Option 1

**Selected:** Option 1 — two statuses (401 credential / 403 client_disabled), generic bodies

**Status:** Decided

**Decision Notes:**
- `401` for every credential problem — missing/malformed `Authorization`, wrong scheme,
  unparseable token, unknown `key_id`, secret mismatch, expired key, revoked key. Body:
  `{"type":"about:blank","title":"Authentication required","status":401,"code":"unauthorized"}`
  + `WWW-Authenticate: Bearer realm="gomrok"`.
- `403` only when the key authenticates but `client.status = disabled` — body
  `code":"client_disabled"`, `title":"Client is disabled"`.
- No body field distinguishes the 401 sub-causes. `X-Correlation-Id` is on every response so
  support can correlate to the server-side log.
- The real reason is recorded once per failure via the failed-attempt log (Q5) with the parsed
  `key_id` when available and a `reason` enum — never the secret.
- The middleware returns the problem response directly via `Shared\Http\JsonResponder` (does not
  throw).

---

### Q5 — Idempotency-Key enforcement + abuse-protection scope for this phase

**Question:** Two related loose ends: (a) is `Idempotency-Key` **required** on `/api/v1` writes,
and (b) how much abuse protection (rate limiting, failed-auth lockout) lands in Phase 7 vs later?

**Options:**

1. **Idempotency-Key required on all `/api/v1` writes; abuse protection = failed-attempt
   logging only.** `IdempotencyMiddleware` attached to the write group; a `POST/PUT/PATCH/DELETE`
   without the header → `400 idempotency_key_required` (the middleware already 400s on a
   malformed key). Failed auth is written to a new `client_auth_attempts` table (ts, ip,
   key_id?, reason, outcome) for the admin panel and future rate limiting. **No rate limiting or
   lockout yet** — deferred to its own concern once there is real traffic and an admin view.
2. **Idempotency-Key optional; add a simple fixed-window rate limiter now.** Writes without a
   key just aren't deduped. Add a per-`key_id` (and per-IP for failed auth) fixed-window counter
   in a `rate_limits` table or an in-process store, returning `429` with `Retry-After`.
   More surface, and a DB-backed limiter is itself hot-path write load.
3. **Idempotency-Key required on payment/subscription creation only; failed-attempt logging +
   an in-memory per-IP failed-auth throttle.** Middle ground: enforce the key only where a
   duplicate is financially harmful; throttle brute-force auth in-process (no DB), lightweight.

**Recommended:** Option 1

**Selected:** Option 1 — key required on all `/api/v1` writes; failed-attempt logging only

**Status:** Decided

**Decision Notes:**
- `IdempotencyMiddleware` attached to the `/api/v1` write routes. A write without an
  `Idempotency-Key` → `400` `{code: "idempotency_key_required"}`. (Phase 5 middleware already
  400s a malformed key and 409/422 for the concurrency/reuse cases.) Reads (`GET`) don't need it.
- New table **`client_auth_attempts`** — one row per failed OR successful auth on `/api/v1`
  (successes give the admin panel "last successful auth" and a future limiter its baseline).
  Columns proposed in the schema step: `id`, `outcome` (`success`/`failure`), `reason`
  (`missing_authorization` | `malformed_token` | `unknown_key` | `invalid_secret` |
  `key_expired` | `key_revoked` | `client_disabled` | `ok`), `key_id` (nullable — parsed token
  only, never the secret), `client_id` (nullable), `ip`, `user_agent`, `correlation_id`,
  `created_at`. Indexes on `(ip, created_at)`, `(key_id, created_at)`, `(client_id, created_at)`.
- **No rate limiting / lockout in Phase 7.** A dedicated rate-limiting concern (per-plan limits,
  `429` + `Retry-After`, admin override) comes later, reading `client_auth_attempts`.
- Writing an attempt row is best-effort (failure logged, request proceeds/fails on its own
  merits). Written via a small port so it can move async later.
- Retention: not addressed now; a purge job like `PurgeExpiredIdempotencyKeys` when it matters.
