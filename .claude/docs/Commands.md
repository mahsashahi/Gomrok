# Commands.md — Gomrok

Everyday commands. Kept current as the toolchain evolves.

## One-time setup

```bash
cp .env.example .env          # then edit if needed
composer install
```

Requires PHP 8.4 (with `bcmath`, `intl`, `pdo_mysql`) and Composer 2. Or use Docker (below) and
run nothing locally.

## Docker

```bash
docker compose up -d              # app on http://localhost:8080, MySQL on :3306
docker compose up -d mysql        # just the database (for integration tests)
docker compose down               # stop; add -v to also drop the DB volume
docker compose exec app bash      # shell in the PHP container
```

The `app` container runs `php -S 0.0.0.0:8080 -t src/Public`.

## Run the app locally (no Docker)

```bash
php -S 127.0.0.1:8080 -t src/Public
curl -s http://127.0.0.1:8080/health                       # public: {"status":"ok","service":"gomrok"}
curl -s http://127.0.0.1:8080/api/v1/me                    # 401 — needs a key
curl -s http://127.0.0.1:8080/api/v1/me \
     -H 'Authorization: Bearer gk_test_000000000000dead.localdevsecretlocaldevsecret1234'   # dev key (local/testing seed)
```

`/health` is the only public route. Everything under `/api/v1` needs
`Authorization: Bearer gk_<live|test>_<key_id>.<secret>`; writes also need an `Idempotency-Key`
header (missing → `400 idempotency_key_required`).

## Tests

```bash
composer test                 # unit suite (no DB)
composer test:integration     # integration suite — needs `docker compose up -d mysql`
composer test:all             # every suite
vendor/bin/phpunit --filter SettingsTest      # a single test class
vendor/bin/phpunit tests/Unit/Config/SettingsTest.php::readsEnvironmentVariables   # one method
```

## Static analysis & code style

```bash
composer stan                 # PHPStan (level max + strict rules)
composer cs                   # php-cs-fixer, dry run (report only)
composer cs:fix               # php-cs-fixer, apply fixes
```

## Database migrations & seeds (Phinx)

Needs a running MySQL (`docker compose up -d mysql`).

```bash
composer db:setup             # migrate + seed — the usual "get a working DB" command
composer db:reset             # rollback everything → migrate → seed (rebuild from scratch)
composer db:fresh             # rollback everything → migrate (no seed)
composer migrate              # apply pending migrations only
composer rollback             # roll back the last migration
composer rollback:all         # roll back every migration (empty schema)
composer seed                 # (re-)run all seeders (idempotent)
vendor/bin/phinx status       # what's applied
vendor/bin/phinx seed:run -s CurrenciesSeeder   # one seeder
```

Migrations are namespaced (`Gomrok\Database\Migrations\`), files
`src/Database/Migrations/YYYYMMDDHHMMSS_*.php`, `up()`/`down()`. Seeders in
`src/Database/Seeds/` (`CurrenciesSeeder` → `CountriesSeeder` → `ProviderTypesSeeder`).
Phinx reads `phinx.php` (DB settings from the environment / `.env`).

Reference data: `currencies` (full ISO, from `brick/money`), `countries` (18 curated markets,
from `src/Database/Seeds/data/countries.json`), `provider_types` (4).

## Clients (Phase 6 — no admin UI yet)

```bash
composer client:create -- --slug=televika --name="Televika" --currency=EUR [--country=DE] [--test]
composer client:issue-key -- --client=televika [--label=ci] [--test]
composer client:revoke-key -- --key-id=<key_id>
composer client:list
```

The API token is printed **once** by `client:create` / `client:issue-key`. `--test` issues a
`gk_test_…` key. Needs a running DB.

`composer db:setup` in `local` / `testing` also seeds a `local-dev` client with a fixed token:
`gk_test_000000000000dead.localdevsecretlocaldevsecret1234` (dev only — the seeder no-ops in
production).

## Background jobs

```bash
composer idempotency:purge    # delete expired idempotency_keys rows (bin/PurgeIdempotencyKeys.php)
```

No scheduler yet — run from cron until the job runner phase. Correctness does not depend on it
(expired keys are ignored on lookup anyway).

## Full local CI

```bash
composer ci                   # cs + stan + test  (unit only, no infra)
composer ci:full              # + integration suite (needs MySQL)
```

GitHub Actions (`.github/workflows/Ci.yml`) runs `composer ci` then, against a `mysql:8.4`
service container, `composer db:setup` + `composer test:integration` on every push to `main`
and every PR — this is where the migrations actually execute.
