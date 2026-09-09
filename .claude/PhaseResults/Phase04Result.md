# Phase 4 — Database foundations: base & reference tables only

## Execution Summary

- Phase: 04 — Database foundations: base & reference tables only
- Start Datetime: 2026-09-08 12:28 PDT
- End Datetime: 2026-09-08 13:30 PDT
- Estimated Duration: 2–4h
- Actual Duration: 62m
- Tokens Used: N/A
- Final Status: ☑ Done — *code/docs complete and statically verified; migration **execution**
  against MySQL is pending (no DB in this environment — see Tests and Validation)*

## Work Completed

The Phinx migration workflow plus exactly three reference tables. All five decisions were made
first (`PhaseDecisions.md` Phase 4 Q1–Q5), and **the schema was presented and confirmed by the
user** before any migration was written (`Rule.md` §5).

- **Migration config.** `phinx.php` rewritten for namespaced migrations —
  `paths.migrations` maps `Gomrok\Database\Migrations` → `src/Database/Migrations`, seeds
  `Gomrok\Database\Seeds` → `src/Database/Seeds`; added `collation: utf8mb4_0900_ai_ci`.
- **Migrations** (namespaced classes, `up()`/`down()`, `id INT UNSIGNED AUTO_INCREMENT`, InnoDB,
  utf8mb4, named indexes/FKs — the `DatabaseAgent.md` conventions applied by hand):
  - `CreateCurrenciesTable` — `currencies (id, code CHAR(3), numeric_code SMALLINT UNSIGNED,
    name VARCHAR(64), minor_unit_scale TINYINT UNSIGNED)`; `uniq_currencies_code`,
    `uniq_currencies_numeric_code`.
  - `CreateCountriesTable` — `countries (id, code CHAR(2), name VARCHAR(80), default_currency
    CHAR(3))`; `uniq_countries_code`, `idx_countries_default_currency`;
    FK `fk_countries_default_currency (default_currency) → currencies(code)` RESTRICT/CASCADE.
  - `CreateProviderTypesTable` — `provider_types (id, code VARCHAR(32), name VARCHAR(64),
    requires_registration TINYINT(1), api_capable TINYINT(1))`; `uniq_provider_types_code`.
- **Seeders** (namespaced, idempotent — `INSERT … ON DUPLICATE KEY UPDATE` on `code`):
  - `CurrenciesSeeder` — iterates `Brick\Money\ISOCurrencyProvider::getInstance()
    ->getAvailableCurrencies()` (166 rows).
  - `CountriesSeeder` — reads `src/Database/Seeds/data/countries.json` (18 rows);
    `getDependencies()` → `[CurrenciesSeeder::class]` so it runs after currencies (FK).
  - `ProviderTypesSeeder` — the 4 types inline (stripe/mollie/paypal = registration+API;
    ziraat = neither).
- **`src/Database/Seeds/data/countries.json`** — 18 rows: TR, US, GB, DE, FR, NL, IT, ES, IE,
  BE, AT, PT, SE, DK, FI, PL, AE, SA, each with `name` + `default_currency`.
- **DB docs** (Phase 4 Q1 — spec's verbatim kebab-case names, kept in lock-step per `Rule.md`
  §5):
  - `.claude/docs/database-design.md` — canonical spec: conventions, table count, full
    definitions of the 3 tables, migration/seeder index.
  - `.claude/docs/database-diagram.md` — Mermaid module map + reference-data ER diagram.
  - `.claude/docs/database-diagram.html` — standalone page; fetches the Mermaid blocks live from
    the `.md` over HTTP, embedded snapshot for `file://`.
  - `.claude/docs/db_explain.md` — per-table plain-language guide.
  - root `mkdocs.yml` — Material theme, `pymdownx.superfences` mermaid fence, `docs_dir:
    .claude/docs`.
- **Scope trim (Phase 4 Q4).** The provider-capability catalogue was deferred to Phase 8;
  `Phases.md` Phase 4 and Phase 8 scopes updated accordingly.

## Files Created

- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Migrations/20260908130001_create_currencies_table.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Migrations/20260908130002_create_countries_table.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Migrations/20260908130003_create_provider_types_table.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Seeds/CurrenciesSeeder.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Seeds/CountriesSeeder.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Seeds/ProviderTypesSeeder.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Seeds/data/countries.json`
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/database-design.md`
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/database-diagram.md`
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/database-diagram.html`
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/db_explain.md`
- `/Users/mahsa/PhpstormProjects/Gomrok/mkdocs.yml`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Integration/ReferenceTablesTest.php`

## Files Modified

- `phinx.php` — namespaced `paths`, collation.
- `composer.json` — `+ "seed"`, `"db:setup"` scripts + descriptions; `autoload.exclude-from-classmap`
  for `/src/Database/Migrations/` (stops the composer PSR-4 warning on timestamped files).
- Removed `src/Database/Migrations/.gitkeep`, `src/Database/Seeds/.gitkeep`.
- `.claude/Rule.md` — §3.1 exceptions (DB docs kebab-case; Phinx migration filenames); §3.3
  database-docs note resolved; ## Project Documents row.
- `.claude/docs/Architecture.md` §4 folder layout; `.claude/docs/Commands.md` migrations section;
  `.claude/docs/Phases.md` (Phase 4 scope + row → ☑; Phase 8 scope); `.claude/FileIndex.md`;
  `.claude/knowledge/Knowledge.md` (reference-data notes).

## Implementation Details

- Migration class↔file mapping (Phinx): `20260908130001_create_currencies_table.php` ↔
  `CreateCurrenciesTable` (Phinx's `mapFileNameToClassName`), resolved in namespace
  `Gomrok\Database\Migrations`. Phinx `require`s the files directly (not PSR-4 autoload), which is
  why the timestamped filenames are fine.
- `up_down` template style; `version_order: creation`.
- Seeders use `$this->getAdapter()->getConnection()` (PDO) with prepared upserts.
- `CountriesSeeder::getDependencies()` enforces currencies-first ordering for `phinx seed:run`.

## Database Changes

New tables: `currencies`, `countries`, `provider_types` (design above; full spec in
`.claude/docs/database-design.md`). Reference data — no timestamps. One FK:
`countries.default_currency → currencies.code`. Seed data: 166 currencies, 18 countries, 4
provider types.

## API Changes

No API changes.

## Tests and Validation

Created `tests/Integration/ReferenceTablesTest.php` (3 tests — currency count + USD/JPY/BHD
scales; 18 countries + all `default_currency` FK-valid; `provider_types` flags for stripe/ziraat).
Self-skips when MySQL isn't set up. No tests modified.

Commands run, real output:

```
$ composer dump-autoload
  Generated autoload files            # no PSR-4 warning (exclude-from-classmap)

$ composer stan
  45/45 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
  [OK] No errors                      # includes the 6 migration/seeder classes

$ composer cs        (after cs:fix)   Found 0 of 46 files that can be fixed
$ composer ci                         OK (42 tests, 113 assertions)
$ composer test:all                   49 tests, 7 skipped (integration — no MySQL)

$ php -r '…require each migration…'   # class/data sanity
  OK  Gomrok\Database\Migrations\CreateCurrenciesTable     (extends AbstractMigration)
  OK  Gomrok\Database\Migrations\CreateCountriesTable      (extends AbstractMigration)
  OK  Gomrok\Database\Migrations\CreateProviderTypesTable  (extends AbstractMigration)
  OK  Gomrok\Database\Seeds\CurrenciesSeeder               (extends AbstractSeed)
  OK  Gomrok\Database\Seeds\CountriesSeeder                (extends AbstractSeed)
  OK  Gomrok\Database\Seeds\ProviderTypesSeeder            (extends AbstractSeed)
  countries.json: 18 rows, 0 with unknown default_currency
  currencies from brick: 166
```

**Not run:** `composer db:setup` (migrate + seed), `composer rollback`, and
`ReferenceTablesTest` — no Docker daemon in this environment and the machine's local MariaDB
rejects the `gomrok` user. `phinx status` / `migrate --dry-run` both require a live connection
and fail at connect. To finish verifying:
`docker compose up -d mysql && composer db:setup && composer test:integration && composer rollback`.

## Technical Decisions

1. **`autoload.exclude-from-classmap` for `/src/Database/Migrations/`.** Phinx's timestamped
   filenames (`20260908130001_create_currencies_table.php`) don't match PSR-4; without the
   exclude, `composer dump-autoload` warns on every one. Phinx loads them by `require`, so
   excluding from the classmap is correct.
2. **No FK on money `currency` columns.** `Currency::of()` validates against `brick`'s ISO list
   in code — the same list `currencies` is seeded from. A DB FK on every money row adds write
   cost for no extra safety. `countries.default_currency` keeps a real FK (few rows, seeded
   together). *(Alternative — FK everywhere — rejected.)*
3. **`countries` curated, not full ISO** (Q5). Each row is a market decision; new markets are a
   follow-up migration, not an edit to this one.
4. **Capability catalogue deferred to Phase 8** (Q4) — how capabilities are modelled is a
   Providers-module concern; building the table now risks the wrong shape.
5. **`mkdocs.yml` at repo root, `docs_dir: .claude/docs`** — mkdocs requires the file at the
   project root; pointing `docs_dir` at `.claude/docs` keeps the source docs where the rest live.

## Problems Encountered

1. Composer PSR-4 warnings on the timestamped migration filenames.
2. PHPStan `max` findings in `ReferenceTablesTest` — `PDO::query()` returning `PDOStatement|false`,
   `(int)` casts on `mixed` row values.
3. No way to execute migrations here — Docker daemon down; local MariaDB has no accessible root
   and rejects `gomrok`.

## Resolutions

1. `autoload.exclude-from-classmap` (decision 1) — `dump-autoload` now clean.
2. Rewrote the test with `assertNotFalse` guards, a `fetchAssoc()` helper, and `assertEquals`
   (loose) for DB-string-vs-int comparisons. PHPStan `max`: clean.
3. Documented as not-verified with the exact command sequence; everything that *can* be checked
   without a DB (class validity, base classes, data integrity, static analysis, style, unit CI)
   passes.

## Deferred Work

- **Execute `composer db:setup` + `composer rollback` + `composer test:integration`** against a
  real MySQL — needs Docker Desktop.
- **`mkdocs build`** verification — needs `pip install mkdocs-material`.
- Provider-**capability** catalogue — Phase 8.
- `brick/math` unpin — once `brick/money` fixes its `dividedBy()` call (from Phase 3).
- New-market countries — added by later migrations as markets open.
- Wire `composer db:setup` into CI once a CI service exists (Phase 30).

## Final Result

The migration workflow is in place (namespaced Phinx, `up()`/`down()`, `composer db:setup` /
`seed` / `rollback`), and three reference-table migrations + idempotent seeders + a bundled
`countries.json` are written and statically verified (PHPStan `max`, cs, `composer ci` all
green; all six classes load and extend the correct Phinx bases; `countries.json` FK-valid
against `brick`'s 166 currencies). The schema is documented across `database-design.md` /
`database-diagram.md` (+ `.html`) / `db_explain.md` + `mkdocs.yml`. **The migrate/seed/rollback
cycle and `ReferenceTablesTest` have not been executed** — that requires the user to run
`docker compose up -d mysql && composer db:setup && composer test:integration`. Assuming that
passes, Phase 4's exit criteria are met and the project is ready for **Phase 5 — Migration
workflow & cross-cutting tables** (`idempotency_keys`, `audit_logs`, `error_logs`).
