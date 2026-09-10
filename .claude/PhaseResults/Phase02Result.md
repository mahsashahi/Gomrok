# Phase 2 — Project scaffold & toolchain

## Execution Summary

- Phase: 02 — Project scaffold & toolchain
- Start Datetime: 2026-09-06 18:20 PDT
- End Datetime: 2026-09-06 18:50 PDT
- Estimated Duration: 3–5h
- Actual Duration: 30m (End − Start; single continuous session)
- Tokens Used: N/A (per-phase token metering is not available in this environment)
- Final Status: ☑ Done

## Work Completed

A bootable, testable, empty Slim 4 application. No business logic, no business/reference tables.

- **Composer project** — `composer.json`: PSR-4 `Gomrok\` → `src/`, `Gomrok\Tests\` → `tests/`;
  `"php": "~8.4.0"` + `ext-bcmath`, `ext-intl`, `ext-pdo`, `ext-pdo_mysql`. Runtime deps:
  `slim/slim ^4.14`, `slim/psr7 ^1.7`, `php-di/php-di ^7.0`, `vlucas/phpdotenv ^5.6`,
  `robmorgan/phinx ^0.16.6`. Dev: `phpunit/phpunit ^11.4`, `phpstan/phpstan ^2.0` +
  `phpstan-strict-rules` + `phpstan-phpunit`, `friendsofphp/php-cs-fixer ^3.64`.
  `composer install` resolved 93 packages (lockfile committed).
- **Composer scripts** — `test` (unit), `test:integration`, `test:all`, `stan`, `cs`, `cs:fix`,
  `migrate`, `rollback`, `ci` (= cs + stan + unit), `ci:full` (= ci + integration).
- **Docker** — `Dockerfile` (`php:8.4-cli` + `bcmath`/`intl`/`pdo_mysql` via
  `docker-php-ext-install`, Composer copied in); `docker-compose.yml` with `app`
  (`php -S 0.0.0.0:8080 -t src/Public`) and `mysql` (`mysql:8.4`, healthcheck, named volume).
- **Config** — `.env.example` (+ local `.env`, git-ignored); `Gomrok\Config\Settings` (immutable,
  `fromEnvironment()`, safe defaults, `.env` loaded only when present) and
  `Gomrok\Config\DatabaseSettings` (with `dsn()`).
- **DI + HTTP wiring** — `src/Config/container.php` (PHP-DI `factory()` definitions, autowired by
  param type — no manual `$container->get()`); `Gomrok\Bootstrap\AppFactory::create()` builds
  the container + Slim app, registers routes, adds routing/body-parsing/error middleware;
  `src/Config/routes.php` maps `GET /health`; `Gomrok\Http\HealthAction` returns
  `{"status":"ok","service":"gomrok"}`; `src/Public/index.php` front controller.
- **Migrations** — `phinx.php` (reads DB settings from env, migrations at
  `src/Database/Migrations`, seeds at `src/Database/Seeds`, `up_down` template,
  `phinx_migrations` table). Both dirs created with `.gitkeep`; no migrations yet (Phase 4).
- **Quality gates** — `phpunit.xml` (`unit` / `integration` suites, strict flags),
  `phpstan.neon` (`level: max` + strict-rules + phpunit extension, paths `src` + `tests`),
  `.php-cs-fixer.dist.php` (`@PSR12` + `declare_strict_types`, short arrays, ordered/no-unused
  imports, single quotes, trailing commas, `native_function_invocation`).
- **Docs** — `.claude/docs/Commands.md` created and registered in `.claude/Rule.md` →
  ## Project Documents. `.claude/docs/Architecture.md` §4 and `.claude/Rule.md` §3.1 updated
  (see *Technical Decisions*).

## Files Created

- `/Users/mahsa/PhpstormProjects/Gomrok/composer.json`
- `/Users/mahsa/PhpstormProjects/Gomrok/composer.lock`
- `/Users/mahsa/PhpstormProjects/Gomrok/.gitignore`
- `/Users/mahsa/PhpstormProjects/Gomrok/.env.example`
- `/Users/mahsa/PhpstormProjects/Gomrok/Dockerfile`
- `/Users/mahsa/PhpstormProjects/Gomrok/docker-compose.yml`
- `/Users/mahsa/PhpstormProjects/Gomrok/phpunit.xml`
- `/Users/mahsa/PhpstormProjects/Gomrok/phpstan.neon`
- `/Users/mahsa/PhpstormProjects/Gomrok/.php-cs-fixer.dist.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/phinx.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Config/Settings.php` — `Gomrok\Config\Settings`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Config/DatabaseSettings.php` — `Gomrok\Config\DatabaseSettings`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Config/container.php` — PHP-DI definitions (returns array)
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Config/routes.php` — route registration (returns Closure)
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Bootstrap/AppFactory.php` — `Gomrok\Bootstrap\AppFactory`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Http/HealthAction.php` — `Gomrok\Http\HealthAction`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Public/index.php` — front controller
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Migrations/.gitkeep`
- `/Users/mahsa/PhpstormProjects/Gomrok/src/Database/Seeds/.gitkeep`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Unit/SmokeTest.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Unit/Config/SettingsTest.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Unit/Http/HealthActionTest.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/tests/Integration/DatabaseConnectionTest.php`
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/Commands.md`

(A local `.env` was created from `.env.example` for running tests; it is git-ignored.)

## Files Modified

- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/Architecture.md` — §4 folder layout now lists
  `src/Bootstrap/` and `src/Http/` as app-level dirs, splits `Database/` into `Migrations/` +
  `Seeds/`, and notes the lowercase non-class config files. (Phase 2 Q6.)
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/Rule.md` — §3.1 exceptions extended
  (`Dockerfile`, `phpstan.neon`, `phinx.php`, `.php-cs-fixer.dist.php`; and non-class PHP config
  files `container.php` / `routes.php` keep lowercase names); ## Project Documents gained a
  `Commands.md` row.
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/PhaseResults/PhaseDecisions.md` — Phase 2 Q1–Q6 recorded.
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/docs/Phases.md` — Phase 2 tracking row → ☑.
- `/Users/mahsa/PhpstormProjects/Gomrok/.claude/Changelog.md` — Phase 2 entry.

## Implementation Details

- **`Gomrok\Config\Settings`** (`final readonly`) — `fromEnvironment(string $rootDir): self`
  loads `$rootDir/.env` via `Dotenv::createImmutable(...)->safeLoad()` only if the file exists;
  private `str()` / `int()` / `bool()` helpers read `$_ENV` then `getenv()`, falling back to
  safe defaults (`APP_ENV=production`, `APP_DEBUG=false`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`,
  …). Holds a `DatabaseSettings`.
- **`Gomrok\Config\DatabaseSettings`** (`final readonly`) — `host`, `port`, `name`, `user`,
  `password`, `charset`; `dsn(): string` builds `mysql:host=…;port=…;dbname=…;charset=…`.
- **`src/Config/container.php`** — returns `array<string, mixed>` of PHP-DI `factory()`
  definitions: `Settings::class` (from env), `DatabaseSettings::class` (`fn (Settings $s) => $s->database`),
  `PDO::class` (`fn (DatabaseSettings $db) => new PDO($db->dsn(), …, [ERRMODE_EXCEPTION,
  DEFAULT_FETCH_MODE=FETCH_ASSOC, EMULATE_PREPARES=false])`). Closures autowire by parameter type.
- **`Gomrok\Bootstrap\AppFactory`** — `create(): App<ContainerInterface|null>`: builds the
  `DI\Container`, `SlimAppFactory::setContainer()` + `create()`, requires
  `src/Config/routes.php` (asserts it returns a `Closure` else `LogicException`), then
  `addRoutingMiddleware()` + `addBodyParsingMiddleware()` + `addErrorMiddleware($debug, true, true)`
  with `$debug` from `Settings::fromEnvironment()`.
- **`Gomrok\Http\HealthAction`** — `__invoke(ServerRequestInterface, ResponseInterface): ResponseInterface`;
  writes `json_encode([...], JSON_THROW_ON_ERROR)`, sets `Content-Type: application/json`. No I/O.
- **Route:** `GET /health` → `HealthAction::class` (resolved via the container).
- **`phinx.php`** — `require vendor/autoload.php`, `Settings::fromEnvironment(__DIR__)`, maps the
  `default` environment's mysql adapter from `$settings->database`.

## Database Changes

No database changes. `phinx.php` and the (empty) `src/Database/Migrations` + `src/Database/Seeds`
directories are set up; the first migration lands in Phase 4.

## API Changes

New endpoint: **`GET /health`** → `200 application/json` `{"status":"ok","service":"gomrok"}`.
Unknown routes return `404` via Slim's routing + error middleware. No authentication (none exists
yet). This is a liveness probe only — it performs no I/O and does not check the database.

## Tests and Validation

Created (no tests modified — none existed):

- `tests/Unit/SmokeTest.php` — autoloading works, PHP is 8.4.
- `tests/Unit/Config/SettingsTest.php` — `Settings::fromEnvironment()` safe defaults; reads
  `APP_ENV` / `APP_DEBUG` / `DB_*` from `$_ENV`; `DatabaseSettings::dsn()` output. (env saved &
  restored per test).
- `tests/Unit/Http/HealthActionTest.php` — builds the real app via `AppFactory::create()`,
  dispatches `GET /health` through `$app->handle()`, asserts `200`, `application/json`, and the
  JSON body.
- `tests/Integration/DatabaseConnectionTest.php` — builds the container, gets `PDO::class`, runs
  `SELECT 1`. **Skips** (does not fail) with a clear message when MySQL is unreachable.

Commands actually run and their real output:

```
$ composer install --no-interaction
  93 packages installed. Generating autoload files. OK.

$ composer test            # unit suite
  PHPUnit 11.5.56 ... Runtime: PHP 8.4.4
  ....                                                                4 / 4 (100%)
  OK (4 tests, 16 assertions)

$ composer stan            # PHPStan level max + strict-rules
  11/11 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
  [OK] No errors

$ composer cs              # php-cs-fixer dry run (after cs:fix)
  Found 0 of 12 files that can be fixed

$ composer ci              # cs + stan + unit
  ... [OK] No errors ...  OK (4 tests, 16 assertions)

$ composer test:all        # unit + integration
  ....S                                                               5 / 5 (100%)
  OK, but some tests were skipped!
  Tests: 5, Assertions: 16, Skipped: 1     # integration skipped — no MySQL

$ php -S 127.0.0.1:8124 -t src/Public   (then, from another shell)
$ curl -s -w '\n-> HTTP %{http_code}\n' http://127.0.0.1:8124/health
  {"status":"ok","service":"gomrok"}
  -> HTTP 200
$ curl -s -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1:8124/nope
  HTTP 404
```

**Not run:** the integration test against a real MySQL. The Docker daemon is not running in this
environment (`Cannot connect to the Docker daemon`), and the machine's own MySQL on
`localhost:3306` rejects the `gomrok` credentials (`SQLSTATE[HY000] [1045] Access denied`). The
test is written and self-skips; run it for real with Docker Desktop up:
`docker compose up -d mysql && composer test:integration`.

## Technical Decisions

- **`ci` excludes the integration suite; `ci:full` includes it.** The default local/pre-commit
  gate must run with zero infrastructure. `DatabaseConnectionTest` also `markTestSkipped()`s on
  a `PDOException` so `composer test:all` degrades gracefully on a machine without the DB
  (never a false pass — a skip is reported explicitly).
- **PHP-DI `factory()` with type-hinted closures instead of `$container->get()`.** PHPStan `max`
  flags `get()` (returns `mixed`); autowiring by parameter type keeps the definitions fully
  typed with no `assert()` / `@var` / ignores.
- **`slim/psr7`** as the PSR-7/PSR-17 implementation — Slim's official default; no reason to
  bring in `nyholm/psr7` for a Slim-only app.
- **`src/Bootstrap/` + `src/Http/` kept as top-level app dirs** (Phase 2 Q6, user chose A):
  the composition root and non-module endpoints need a home, and `src/Shared/` is reserved for
  the Phase 3 domain kernel. `Architecture.md` §4 updated.
- **Single root `Dockerfile`** (not `docker/php/Dockerfile`) to avoid a lowercase `docker/`
  directory under the PascalCase naming rule; `Dockerfile` is an ecosystem-mandated name.
- **`.claude/docs/Commands.md`** rather than a root `COMMANDS.md` (naming rule + docs live under
  `.claude/`; it was first created at `Documents/Commands.md`, before the `.claude/` reorg).

## Problems Encountered

1. PHPStan `max` reported 16 errors on first run — all from `$container->get()` / `require`
   returning `mixed`, plus a too-narrow `@return App<ContainerInterface>` and `PDOStatement|false`
   handling in the integration test.
2. `composer ci` (originally `cs` + `stan` + `test:all`) failed: the machine has a MySQL on
   `localhost:3306` that rejects the `gomrok` user, so `DatabaseConnectionTest` errored.
3. After adding a `catch (PDOException)` skip guard, PHPStan flagged it as a *dead catch*
   ("PDOException is never thrown in the try block") because `$container->get()` isn't typed to
   throw it.
4. The Docker daemon is not running in this environment, so the integration test could not be
   verified against a real MySQL.
5. `src/Bootstrap/` and `src/Http/` were not in the approved `Architecture.md` §4 folder sketch.

## Resolutions

1. Reworked `container.php` to PHP-DI `factory()` closures (typed autowiring), added
   `@return App<ContainerInterface|null>`, and rewrote the integration test with
   `assertNotFalse()` / `assertIsArray()`. PHPStan `max`: **no errors**.
2. Split `ci` (cs + stan + unit, no infra) from `ci:full` (adds integration), and made
   `DatabaseConnectionTest` `markTestSkipped()` on `PDOException`.
3. Moved `$pdo->query('SELECT 1')` *inside* the same `try` block as `$container->get()` — PHPStan
   knows `query()` under `ERRMODE_EXCEPTION` throws `PDOException`, so the catch is live; at
   runtime it also catches the connection failure propagated from the factory.
4. Documented as not-verified (above) with the exact command to run it once Docker is available.
   Unit + static analysis + the live `/health` check all pass with real output.
5. Raised as Phase 2 Q6 before completing the phase (Rule.md §4.2); user chose to keep them and
   `Architecture.md` §4 was updated.

## Deferred Work

- **Run `DatabaseConnectionTest` against real MySQL** — needs Docker Desktop running.
- **Nginx/FPM web entry** — `docker-compose.yml` uses `php -S` for the `app` service; a
  production-grade nginx + php-fpm setup is deferred (not needed for dev / Phase 2 exit).
- **CI pipeline file** (GitHub Actions / etc.) — `composer ci` / `ci:full` are the building
  blocks; wiring an actual CI service is deferred (Phase 30 hardening, or when a repo/remote
  exists — this project is not yet a git repo).
- **Shared kernel** (`src/Shared/**` — `Money`, `Currency`, `Clock`, `Result`, logger, event
  dispatcher, base HTTP action/responder/error-handler; no ID types) — **Phase 3**.
- **`src/Jobs/`** queue worker — Phase 29.
- A custom Phinx migration base class / namespaced migrations — deferred to Phase 4 when the
  first migration is designed.

## Final Result

`composer install` works; `composer test` is green (4 tests, 16 assertions); `composer stan`
passes at level `max` with strict rules; `composer cs` is clean; the app boots under `php -S`
and `GET /health` returns `200` `{"status":"ok","service":"gomrok"}` while unknown routes return
`404`. The project has: a pinned PHP 8.4 + MySQL 8.4 Docker stack, a PHP-DI container, an
env-driven `Settings` object, a Phinx setup with empty migration/seed directories, a PHPUnit
`unit`/`integration` split, and `.claude/docs/Commands.md`. No business modules, no database tables,
no provider code. Ready for **Phase 3 — Shared kernel**.
