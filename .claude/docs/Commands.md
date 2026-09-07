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
curl -s http://127.0.0.1:8080/health      # {"status":"ok","service":"gomrok"}
```

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

## Database migrations (Phinx)

No migrations exist yet — the first land in Phase 4. Once they do:

```bash
composer migrate              # apply pending migrations   (vendor/bin/phinx migrate)
composer rollback             # roll back the last one
vendor/bin/phinx status
vendor/bin/phinx create MyMigrationName
vendor/bin/phinx seed:run
```

Phinx reads `phinx.php`, which pulls DB settings from the environment (`.env` locally).

## Full local CI

```bash
composer ci                   # cs + stan + test:all
```
