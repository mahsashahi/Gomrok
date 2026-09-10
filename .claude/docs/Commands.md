# Commands.md — Gomrok

Everyday commands. Kept current as the toolchain evolves.

## One-time setup

```bash
cp .env.example .env
php -r 'echo "APP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\n";' >> .env   # provider-secret encryption key
composer install
```

Requires PHP 8.4 (with `bcmath`, `intl`, `pdo_mysql`, `sodium`) and Composer 2. Or use Docker
(below) and run nothing locally.

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

## Clients & provider accounts (no admin UI yet)

```bash
composer client:create -- --slug=televika --name="Televika" --currency=EUR [--country=DE] [--test]
composer client:issue-key -- --client=televika [--label=ci] [--test]
composer client:revoke-key -- --key-id=<key_id>
composer client:list

composer provider-account:create -- --client=televika --provider-type=stripe --mode=live \
    --name="Televika Stripe" --secret-key=sk_live_... [--public-key=pk_live_...] [--country=DE ...] [--method=card ...]
composer provider-account:rotate-secret -- --client=televika --account=stripe-live   # secret via flag or stdin
composer provider-account:add-endpoint -- --client=televika --account=stripe-live --kind=webhook [--signing-secret=whsec_...]
composer provider-account:list -- [--client=televika]

# Country -> provider routing (Phase 10)
composer provider-group:create -- --client=televika --name="Turkey" [--slug=turkey] [--default] [--device=web|ios|android] [--currency=TRY]
composer provider-group:configure -- --client=televika --group=turkey --country=TR --purchase-type=one_time_payment [--method=bank_hosted_card] [--currency=TRY | --clear-currency]
composer provider-group:set-accounts -- --client=televika --group=turkey --account=ziraat-test [--account=stripe-test]
```

Routing model: a client's `provider_group` binds a set of countries (+ optional device type) to
an ordered list of its provider accounts, plus the purchase types / methods the market sells.
The `is_default` group (created with `--default`, no countries) is the fallback. `ProviderRouter`
resolves the group, filters the accounts by mode / status / served country / purchase type
(group set ∩ provider-type capability) / method, and **rejects** an unsupported combination
(e.g. a subscription request in a one-time-only market) rather than downgrading it.

```bash
# Package catalogue (Phase 11)
composer package:create -- --client=televika --code=pro --name="Pro" [--description="..."]
composer package:set-availability -- --client=televika --package=pro --country=DE --currency=EUR [--method=card] [--provider-account=stripe-live]
composer package:update -- --client=televika --package=pro [--name="Pro Plus"] [--disable | --enable]
composer package:list -- --client=televika
```

`code` is unique **per client**. A new package is available everywhere until restricted — each of
country / currency / method / provider is fail-open (empty = all). `status=disabled` hides it.
`PackageCatalog::resolve()` returns the market-filtered list (no price / purchase types yet);
`GET /api/v1/packages` lands in Phase 13.

Provider secrets are encrypted with `APP_ENCRYPTION_KEY` — set it before creating accounts
(`php -r 'echo base64_encode(random_bytes(32));'`). Secrets are printed once at most, never by
`:list`.

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
