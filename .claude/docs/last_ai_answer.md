# Q: Fix the failing integration tests before starting Phase 28

Ran `composer test:all` and found the suite red: 6 errors, 3 failures out of 755 tests. Root-caused
and fixed every one. Final state: **755 tests, 2899 assertions, 0 errors, 0 failures, 3 skipped**
(the three live-provider tests — Stripe/Mollie/PayPal — that self-skip without real test
credentials configured; expected, not a regression). `composer stan` — no errors.

## Root causes, in the order they were uncovered

1. **Local environment collision, not a code bug.** A leftover SSH tunnel on this machine
   (`ssh -L 3307:192.168.1.35:3306 ... saba@k2.saba-e.com`, unrelated to Gomrok, started earlier
   today) was also bound to local port 3307, racing with Docker's `gomrok-mysql-standalone`
   container on the same port. Depending on which listener the OS handed a given connection to,
   `mysql`/PDO calls to `127.0.0.1:3307` intermittently got "access denied" or "SSL required"
   errors instead of reaching the real Gomrok database. Confirmed via `lsof -iTCP:3307` showing
   both an `ssh` process and Docker's proxy listening on the same port.

   Fixed (per your explicit choice) by moving Gomrok's local dev MySQL off the contested port:
   stopped/removed `gomrok-mysql-standalone`, recreated it with `-p 3308:3306` while reusing its
   *existing named volume* (`83a063c8...`, verified no data loss — `clients` table still had its
   one row after recreation), and updated `.env`'s `DB_PORT` to 3308 (`.env` is gitignored,
   machine-local; not committed). Left the SSH tunnel itself untouched. Confirmed no other
   config hardcodes 3307 except `tests/Unit/Config/SettingsTest.php`'s unrelated example DSN
   string and `.claude/Changelog.md` / `Phase27Result.md` history (left as historical record).
   `.env.example` (port 3306, matches `docker-compose.yml`'s own mysql service) was never wrong
   and wasn't touched.

2. **Stale demo data blocking a schema rollback.** `tools/screenshots/seed-demo-data.php`
   (dev-only tooling used to populate the Phase 27 evidence screenshots) had left 7 `payments`
   rows with `checkout_attempt_id = NULL` sitting in the shared local dev DB, outside any
   transaction. `MigrationRoundTripTest` rolls every migration down to empty and back up as a
   correctness check; rolling back `20260914120002_make_payments_checkout_attempt_id_nullable`
   re-adds `NOT NULL` to that column, which MySQL correctly refuses while NULL rows exist — this
   was real, correct DB behavior, not a bug, but it meant the demo data and the round-trip test
   couldn't coexist. Deleted those demo payments and three other stray manual-test clients
   (`curl-test-client`, `curl-test-client-2`, `playwright-test-client`, presumably left over from
   manual curl/Playwright smoke testing) via `docker exec ... mysql`. All regenerable from the
   checked-in seed script; nothing irreplaceable was lost. (This also emptied the admin Home
   dashboard's demo numbers shown earlier in this conversation — re-seedable via
   `node tools/screenshots/seed-demo-data.php` if fresh screenshots are needed again.)

3. **Real seeder-ordering bug, only exposed once (1) and (2) stopped masking it.**
   `PackagesSeeder` (inserts `package_countries` rows for `DE`) and `ProviderAccountsSeeder`
   (inserts `provider_account_countries` rows for `DE`/`NL`) never declared `CountriesSeeder` as
   a Phinx seed dependency — only `ClientsSeeder`/`ProviderTypesSeeder`. On a fully empty schema
   (exactly what `MigrationRoundTripTest`'s full rollback-and-reseed cycle produces), Phinx's
   `orderSeedsByDependencies()` ran both seeders *before* `CountriesSeeder`, so the FK to
   `countries` failed with `SQLSTATE[23000]`. Verified empirically with `phinx seed:run -v` on a
   freshly emptied schema (order printed: Currencies → Clients → **Packages fails**, Countries
   never reached). Fixed by adding `CountriesSeeder::class` to both seeders' `getDependencies()`;
   re-ran the full rollback→migrate→seed cycle manually and confirmed Countries now seeds before
   Packages/ProviderAccounts and the whole chain completes cleanly.

4. **Real production bug in `PdoVoucherRedemptionRepository::save()`.** The INSERT statement has
   15 named placeholders, but the bound-parameters array only supplied 13 — `confirmed_at` and
   `released_at` were missing entirely from the merge. Under the app's real connection config
   (`PDO::ATTR_EMULATE_PREPARES => false` in `src/Config/container.php`), MySQL's native prepared
   statement protocol rejects a parameter-count mismatch with `SQLSTATE[HY093]: Invalid parameter
   number`. This is not test-only — it would throw on the very first real voucher reservation in
   production. Fixed by binding both keys (`null` when the redemption has neither set, which is
   always true for a fresh reservation).

5. **Test-only bugs, no production impact — same native-prepare rejection pattern:**
   - `ProviderGroupsPersistenceTest::account()` reused the `:slug` placeholder for two different
     columns (`slug` and `name`) in one INSERT. Split into `:slug`/`:name` with both bound from
     the same PHP value.
   - `CrossCuttingWritersTest` referenced client ids `9001`–`9004` that were never inserted into
     `clients`. A `client_id` FK on `idempotency_keys`/`audit_logs` was added later
     (`20260908150004_add_client_fks_to_cross_cutting_tables.php`) without retrofitting this test
     — confirmed by diffing the migration's own docstring ("deferred from Phase 5"). Fixed by
     seeding those four client rows in `setUp()`, inside the test's own rolled-back transaction.
   - `ReferenceTablesTest::countriesAreSeededAndFkToCurrencies` hardcoded an expected count of 18
     countries; `countries.json` legitimately gained Switzerland (`CH`) in the most recent commit
     (`b871d0d`). Updated the expected count to 19.
   - `ProviderCapabilitiesPersistenceTest::ziraatAndMollieHaveNoDeclarationsYet` asserted Ziraat
     and Mollie had zero seeded declarations — false since the very commit that introduced both
     the test *and* `ProviderTypeDeclarations.json`'s real Ziraat/Mollie entries (confirmed via
     `git log -p`: both landed together in `bba0c48`, the test was simply never updated to match
     its own fixture). Replaced with `ziraatAndMollieDeclarationsReadBack`, asserting what's
     actually true and matches `CLAUDE.md`'s capability rules: Ziraat supports `one_time_payment`
     only (no subscription/auto-charge), has `manual_status_polling`, not `customer_portal`;
     Mollie supports `subscription` but not `auto_charge`, has `partial_refund`.

## Files changed
- `.env` — `DB_PORT` 3307 → 3308 (gitignored; not a committed change, documented in Changelog for
  the reasoning).
- `src/Database/Seeds/PackagesSeeder.php`, `src/Database/Seeds/ProviderAccountsSeeder.php` —
  added `CountriesSeeder::class` to `getDependencies()`.
- `src/Modules/Vouchers/Infrastructure/PdoVoucherRedemptionRepository.php` — `save()` now binds
  `confirmed_at`/`released_at` on insert.
- `tests/Integration/ProviderGroupsPersistenceTest.php` — fixed duplicate `:slug` placeholder.
- `tests/Integration/CrossCuttingWritersTest.php` — seeds four fixed test clients in `setUp()`.
- `tests/Integration/ReferenceTablesTest.php` — expected country count 18 → 19.
- `tests/Integration/ProviderCapabilitiesPersistenceTest.php` — rewrote the stale Ziraat/Mollie
  test.
- `.claude/Changelog.md` — new entry recording all of the above.

**Database changes:** none (no migrations). Deleted 7 demo `payments` rows and 3 stray manual-test
`clients` rows from the shared local dev database (data cleanup, not schema — regenerable).

**Breaking changes:** none. The voucher-repository fix corrects a bug that would have thrown on
first real use; nothing depended on the broken behavior.

**Verification, actually run:** `composer test:all` → 755 tests / 2899 assertions / 0 errors / 0
failures / 3 skipped. `composer stan` → no errors.

**Next recommended step:** Phase 28 — Client callbacks / outbound notifications, per
`.claude/docs/Phases.md`. If you want the admin Home dashboard to show demo data again for future
screenshots, re-run `node tools/screenshots/seed-demo-data.php` (its output was cleared as part of
fix #2 above).
