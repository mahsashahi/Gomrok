# Q: New Client form's currency/country dropdowns are empty — investigate and fix

## Root cause

**Not a code bug.** `ReferenceCatalog::listCurrencies()`/`listCountries()`, the seeders
(`CurrenciesSeeder`, `CountriesSeeder`), and the Twig `<select>` partials all work correctly once
the `currencies`/`countries` reference tables have rows — verified by fetching the live New
Client form's raw HTML and counting 166 `<option>`s for currency and 20 for country (19 markets +
blank).

The actual defect is in the **deployment documentation**:
`.claude/knowledge/DeploymentRunbook.md` §6 (duplicated in `.claude/docs/Commands.md` and
`.claude/docs/GoLiveChecklist.md`) told operators to skip the full seed step in production and
instead run:

```bash
vendor/bin/phinx seed:run -s CurrenciesSeeder
```

This command **fails** — `The seed class "CurrenciesSeeder" does not exist` — because Phinx's
`-s` filter needs the fully qualified class name
(`-s 'Gomrok\Database\Seeds\CurrenciesSeeder'`), not the short name. The runbook's stated reason
for avoiding `composer seed` in production ("phinx is excluded by --no-dev") was also wrong:
`robmorgan/phinx` is a plain `require` dependency, never a dev-only one. On any environment where
`phinx migrate` ran without a working follow-up seed step, `currencies`/`countries`/
`provider_types` are left at zero rows — which is exactly what renders the dropdowns empty.

## Reproduction (isolated, no risk to real data)

Created a throwaway MySQL schema (`gomrok_freshtest`, dropped after), never touching the real
`gomrok` schema:

1. `phinx migrate` alone → `currencies`/`countries`/`provider_types` = 0 rows. **Reproduces the
   reported bug exactly.**
2. `phinx seed:run -s CurrenciesSeeder` → fails with the exact documented-command error above.
3. `phinx seed:run -s 'Gomrok\Database\Seeds\CurrenciesSeeder'` → works.
4. Plain `phinx seed:run` (no filter) under `APP_ENV=production` → correctly populates all three
   reference tables (166/19/4 rows) while explicitly skipping every seeder that creates
   client/demo/test data (`ClientsSeeder skipped: APP_ENV is "production"`, and five others — all
   individually gated, confirmed by reading every file in `src/Database/Seeds/`).
5. Ran step 4 again → identical counts, no duplicates, no errors (idempotent).

## Fix

Documentation-only — no schema change, no seeder logic change (both were already correct,
complete, and idempotent). Corrected three docs to recommend the plain, unfiltered
`phinx seed:run` / `composer seed` as the standard command for every environment including
production:

- `.claude/knowledge/DeploymentRunbook.md` §6 — rewritten with the corrected command and a full
  explanation of why the plain form is safe (every demo-data seeder is `APP_ENV`-gated).
- `.claude/docs/Commands.md` — corrected the same broken example command; also fixed a stale
  "18 curated markets" count to the actual 19.
- `.claude/docs/GoLiveChecklist.md` — corrected its own copy of the broken command.

## New test

`tests/Integration/ProductionSeedSafetyTest.php` — two tests, safe to run against the real shared
dev database (unlike `MigrationRoundTripTest`, it never rolls back/re-migrates, so `admin_users`
is never touched):

1. `seedingUnderProductionPopulatesReferenceDataWithoutTouchingClientOrAdminData` — forces
   `APP_ENV=production` for one `Manager::seed()` call, asserts `currencies`/`countries`/
   `provider_types` end up populated (>150 / 19 / 4) and `clients`/`admin_users` row counts are
   unchanged.
2. `seedingUnderProductionTwiceInARowIsIdempotent` — runs it twice, asserts identical row counts.

Verified against the real dev DB: `admin_users` stayed at 1, `clients` at 2, throughout.

## Verification run

- `composer test` — 876 unit tests, green.
- `vendor/bin/phpstan analyse --memory-limit=1G` — no errors.
- `vendor/bin/php-cs-fixer fix --dry-run` on the new test file — clean.
- `vendor/bin/phpunit --testsuite integration --filter "ReferenceTablesTest|ProductionSeedSafetyTest"`
  — 7 tests, green. (Full integration suite deliberately not run — `MigrationRoundTripTest` wipes
  `admin_users`.)
- Live HTML check on the running local dev server: New Client form's `default_currency` select
  has 166 `<option>`s, `default_country` has 20 — confirming the form works correctly once
  reference data exists.

## Exact commands to fix any currently-affected environment

Idempotent — safe to run repeatedly, does not touch `clients` or `admin_users`:

```bash
# Local dev
composer seed

# Any server (production or otherwise)
cd /var/www/gomrok/app   # wherever the app is deployed
sudo -u gomrok vendor/bin/phinx seed:run
```

## Requirement-by-requirement

1. Dropdown options come from `currencies`/`countries` DB tables via `ReferenceCatalog`, not
   hardcoded — already true, unchanged, reconfirmed.
2. `CountriesSeeder.php`/`CurrenciesSeeder.php` checked — both already correct and idempotent
   (`ON DUPLICATE KEY UPDATE` on `code`, a unique-indexed column).
3/4. Reference data is inserted automatically (via `phinx seed:run`, now correctly documented)
   and idempotently — verified with a real double-run against a fresh schema and against the
   real dev schema.
5. Currencies: full ISO 4217 (166, via `brick/money`). Countries: the 19 markets Gomrok currently
   operates in (`src/Database/Seeds/data/countries.json`) — deliberately curated, not the full
   ISO 3166 list, per the `countries` table's own migration docblock; unchanged by this fix since
   it was already complete and correct.
6. Verified — see "Verification run" above.
7/8. No database reset, no client/admin-user data touched at any point — verified by row-count
   snapshots before/after every operation, both on the throwaway schema and the real one.
9. Tests run and added — see above.
10. Exact commands given — see above.
11. Deployment documentation updated so a fresh installation (and every subsequent deploy)
   populates reference data with one correct, safe command right after migrations —
   `.claude/knowledge/DeploymentRunbook.md` §6.

## Next recommended step

None required — this closes out the report. `.claude/docs/Phases.md`'s next open phase remains
Phase 28 (client callbacks / outbound notifications).
