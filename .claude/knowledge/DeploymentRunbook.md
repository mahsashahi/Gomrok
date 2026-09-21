# DeploymentRunbook.md

**Purpose.** Step-by-step runbook for deploying and rolling back Gomrok.

**Status (Phase 30B).** This is a **reference / illustrative deployment example** — a single
Linux VPS running nginx + php-fpm + MySQL + systemd, chosen because it is the simplest concrete
shape that exercises every real requirement (a long-lived PHP process serving HTTP, a real
database, and `bin/Worker.php` running as a supervised background daemon per Phase 29 Q5 /
Phase 30A Q3). **It is not a permanent architectural commitment.** No real production host,
domain, or credentials exist yet — nothing in this document has been executed against a real
environment. The real production hosting target may turn out to be different (a managed
platform, Kubernetes, a different VPS provider, etc.); see *Portability* at the end for what
changes if so. See also `.claude/docs/Deployment.md` for the generic requirements this
illustrates, and `.claude/docs/GoLiveChecklist.md` for the Televika-specific onboarding steps
that come after a target like this exists.

Every command below is real and taken from this repository's actual `composer.json` scripts,
`Dockerfile`, `.env.example`, and `src/Config/routes.php` — not invented syntax — but the
*sequence of running them against a real internet-facing host* has not itself been executed.

---

## 1. Preparing the host

Example target: Ubuntu 24.04 LTS, a non-root deploy user with `sudo`, a firewall allowing
22 (SSH), 80/443 (HTTP/HTTPS) only.

```bash
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y nginx mysql-server git unzip curl software-properties-common
sudo adduser --system --group --home /var/www/gomrok gomrok   # dedicated service user, no login shell
```

## 2. Required PHP extensions

Gomrok needs PHP `~8.4.0` (`composer.json`) with: `bcmath`, `intl`, `pdo`, `pdo_mysql`, `sodium`.
`sodium` ships built into PHP core since 7.2 — no separate package. Matching the project's own
`Dockerfile` (`php:8.4-cli` + `docker-php-ext-install bcmath intl pdo_mysql`):

```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt-get update
sudo apt-get install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-bcmath php8.4-intl \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip
php -v                                    # confirm 8.4.x
php -m | grep -E 'bcmath|intl|pdo_mysql|sodium'   # confirm all four are loaded
```

## 3. Composer install

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo -u gomrok git clone <repository-url> /var/www/gomrok/app   # or unpack a release tarball
cd /var/www/gomrok/app
sudo -u gomrok composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` excludes PHPUnit/PHPStan/php-cs-fixer — this repo's dev tooling is not needed at
runtime. `--optimize-autoloader` builds a classmap instead of PSR-4 lookups (meaningfully faster
under real traffic than the plain autoloader local dev uses).

## 4. Environment configuration

Copy and fill in `.env` from the real template (`.env.example` in this repo) — **never commit
this file**:

```bash
sudo -u gomrok cp .env.example /var/www/gomrok/app/.env
sudo -u gomrok php -r 'echo "APP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\n";'
# paste the output's value into APP_ENCRYPTION_KEY= in .env
sudo chmod 600 /var/www/gomrok/app/.env
```

Set for a real environment:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_BASE_URL=https://gomrok.example.com
APP_ENCRYPTION_KEY=<the generated key from above>
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=gomrok
DB_USER=gomrok
DB_PASSWORD=<a real, strong, generated password>
DB_CHARSET=utf8mb4
```

**This is exactly what `ProductionSafetyGuard` (Phase 30A Q5) checks at every boot** — if
`APP_DEBUG` is left `true`, or `APP_ENCRYPTION_KEY` is left blank, or the checkout-return-token
secret is still the hardcoded development default, the application refuses to start rather than
run unsafely. A failed boot here is the guard doing its job, not a bug — see its error output for
which specific check failed.

## 5. Database configuration

```bash
sudo mysql -u root -e "
  CREATE DATABASE gomrok CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'gomrok'@'localhost' IDENTIFIED BY '<the same strong password as DB_PASSWORD>';
  GRANT ALL PRIVILEGES ON gomrok.* TO 'gomrok'@'localhost';
  FLUSH PRIVILEGES;
"
```

Use a dedicated database user scoped to only the `gomrok` schema — never the MySQL root account
in `.env`. If the database runs on a separate host from the app, use its address for `DB_HOST`
and restrict inbound MySQL (3306) to only the app host's IP at the firewall/security-group level.

## 6. Running migrations and seeding reference data

```bash
cd /var/www/gomrok/app
sudo -u gomrok vendor/bin/phinx migrate   # = `composer migrate` (phinx is a regular `require`
                                           # dependency, not `require-dev` — --no-dev never drops it)
sudo -u gomrok vendor/bin/phinx status    # confirm every migration shows "up"
sudo -u gomrok vendor/bin/phinx seed:run  # = `composer seed` — see below for why this is safe here
```

**Corrected 2026-09-21 — the previous version of this section was wrong and does not work.** It
told operators to skip the full seed step and instead run three individually-named seeders with
`phinx seed:run -s CurrenciesSeeder` (etc.). That exact command **fails** —
`The seed class "CurrenciesSeeder" does not exist` — because Phinx's `-s` filter needs the fully
qualified class name (`-s 'Gomrok\Database\Seeds\CurrenciesSeeder'`), not the short name. It also
assumed `phinx` is a `require-dev`-only binary excluded by `--no-dev`, which is false —
`robmorgan/phinx` is a plain `require` dependency (`composer.json`), present in every install. If
this section was followed literally on a real deploy, the three commands above errored out and
`currencies`/`countries`/`provider_types` were silently left empty — which is exactly what makes
the admin UI's currency/country dropdowns (client create/edit, provider accounts, pricing groups,
routing groups, voucher eligibility) render with no options. This was the root cause of a real
bug — see `.claude/Changelog.md`'s 2026-09-21 entry.

The fix is simpler than the old advice: **run the plain, unfiltered `phinx seed:run` /
`composer seed` in every environment, including production.** This is safe because every seeder
that creates demo/test data (`ClientsSeeder`, `PackagesSeeder`, `PricingSeeder`,
`ProviderAccountsSeeder`, `ProviderGroupsSeeder`, `VouchersSeeder`) is individually gated to a
no-op unless `APP_ENV` is `local`/`testing` — confirmed by reading every seeder in
`src/Database/Seeds/`. Only the reference/lookup seeders actually run in production
(`CurrenciesSeeder`, `CountriesSeeder`, `ProviderTypesSeeder`, `ProviderCapabilitiesSeeder`,
`ProviderTypeDeclarationsSeeder`), and all of them are idempotent
(`INSERT ... ON DUPLICATE KEY UPDATE` on a natural key) — safe to re-run on every deploy, and
verified (`tests/Integration/ProductionSeedSafetyTest.php`) to leave `clients` and `admin_users`
row counts unchanged.

Run `phinx seed:run` right after every `phinx migrate` — on the very first deploy and on every
subsequent one — the same way `composer db:setup` already chains `migrate` + `seed` for local
dev. There is no separate "production seed" command to remember.

## 7. Filesystem permissions

```bash
sudo chown -R gomrok:gomrok /var/www/gomrok/app
sudo find /var/www/gomrok/app -type d -exec chmod 750 {} \;
sudo find /var/www/gomrok/app -type f -exec chmod 640 {} \;
sudo chmod 600 /var/www/gomrok/app/.env
```

Gomrok has no runtime-writable directory of its own today (no file uploads, no local cache
directory) — everything durable goes to MySQL. If that changes later, add the specific writable
path here rather than widening permissions broadly.

## 8. nginx configuration

```nginx
# /etc/nginx/sites-available/gomrok
server {
    listen 80;
    server_name gomrok.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name gomrok.example.com;

    ssl_certificate     /etc/letsencrypt/live/gomrok.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gomrok.example.com/privkey.pem;

    root /var/www/gomrok/app/src/Public;
    index index.php;

    client_max_body_size 2m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm-gomrok.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/gomrok /etc/nginx/sites-enabled/gomrok
sudo nginx -t                      # validate config before reloading
sudo systemctl reload nginx
```

TLS: provision a real certificate (e.g. `sudo certbot --nginx -d gomrok.example.com`) before
routing real webhook traffic here — every payment provider requires HTTPS for webhook endpoints.

## 9. php-fpm configuration

A dedicated pool, not the distro default pool, so Gomrok's process limits/logs are isolated:

```ini
; /etc/php/8.4/fpm/pool.d/gomrok.conf
[gomrok]
user = gomrok
group = gomrok
listen = /run/php/php8.4-fpm-gomrok.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 6
php_admin_value[error_log] = /var/log/php8.4-fpm-gomrok.log
php_admin_flag[log_errors] = on
```

```bash
sudo systemctl restart php8.4-fpm
sudo systemctl status php8.4-fpm
```

Size `pm.max_children` to the host's real memory (each PHP-FPM child loads the full framework —
measure actual RSS per worker under real load rather than guessing).

## 10. systemd worker installation

`bin/Worker.php` (Phase 29 Q5) is a **persistent daemon**, not a cron job — it must be started
once and kept running, with automatic restart on crash (see `.claude/docs/Deployment.md`'s
"Background worker requirement" for the generic contract this satisfies):

```ini
# /etc/systemd/system/gomrok-worker.service
[Unit]
Description=Gomrok background job worker
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=gomrok
Group=gomrok
WorkingDirectory=/var/www/gomrok/app
ExecStart=/usr/bin/php /var/www/gomrok/app/bin/Worker.php
Restart=always
RestartSec=5
Environment=JOBS_WORKER_BATCH_SIZE=50
Environment=JOBS_WORKER_POLL_SECONDS=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=gomrok-worker

[Install]
WantedBy=multi-user.target
```

`Restart=always` is exactly the "keep it running, restart on crash" requirement
`.claude/docs/Deployment.md` names as non-negotiable for this daemon — without it, once the
process exits for any reason, background jobs (webhook retries, notifications, reconciliation
scans) silently stop with no automatic recovery.

```bash
sudo systemctl daemon-reload
sudo systemctl enable gomrok-worker
sudo systemctl start gomrok-worker
```

## 11. Starting / restarting services

```bash
sudo systemctl restart php8.4-fpm gomrok-worker
sudo systemctl reload nginx        # reload, not restart — no dropped connections
```

Order matters on a first bring-up: MySQL → run migrations (§6) → php-fpm → nginx → the worker
(§10) — the worker's `ContainerFactory::create()` boot (via `ProductionSafetyGuard`) will fail
fast if the database or `.env` isn't ready yet, which is the desired behavior, not a bug to work
around.

## 12. Health checks

```bash
curl -sf https://gomrok.example.com/health
# expected: {"status":"ok","service":"gomrok"}
```

`GET /health` is the only unauthenticated route in the app (`src/Config/routes.php`) — safe to
point an external uptime monitor or a load-balancer health check at it directly.

## 13. Application verification

```bash
curl -s https://gomrok.example.com/api/v1/me
# expected: 401 {"code":"unauthorized",...} — confirms routing + auth middleware are live,
# without needing a real API key yet.

sudo systemctl status php8.4-fpm nginx --no-pager
sudo tail -50 /var/log/php8.4-fpm-gomrok.log
```

A real end-to-end check (a real client, a real package, a real checkout) is a `GoLiveChecklist.md`
step, not a bare deploy-verification step — it needs a real client configured first.

## 14. Worker verification

```bash
sudo systemctl status gomrok-worker --no-pager
sudo journalctl -u gomrok-worker -n 50 --no-pager
```

Expect a startup line (`... starting (batch_size=50 poll_seconds=5)`) followed, within one poll
cycle, by an `attempted=7 succeeded=7 ...` line as every job type self-bootstraps on first-ever
run (Phase 29) — if `attempted` is ever non-zero with `failed > 0` on a *fresh* deploy, check
`/admin/error-logs` and `/admin/jobs` (the "N alerting" badge, Phase 29 Q5 revision) before
assuming it's expected.

## 15. Logging

- **Web (php-fpm):** `php_admin_value[error_log]` above → `/var/log/php8.4-fpm-gomrok.log`.
  Application-level errors additionally go to Gomrok's own `error_logs` table (structured,
  admin-visible at `/admin/error-logs`) — the two are complementary: the file catches PHP-level
  fatals before the app framework can even run; `error_logs` catches everything the app itself
  caught and classified.
- **Worker:** `journalctl -u gomrok-worker` (systemd's `StandardOutput=journal`/`StandardError=journal`
  above) — the same `[timestamp] worker:<host>:<pid> ...` lines already seen in local dev.
- **nginx:** default `/var/log/nginx/access.log` / `error.log` — access log is useful for
  detecting webhook delivery patterns (a provider's real source IPs, retry timing) during
  incident review.
- **Audit trail:** every sensitive admin action (provider-secret rotation, refunds, retries,
  pricing/voucher changes, permission changes — CLAUDE.md's Security Rules) is in `audit_logs`,
  browsable at `/admin/audit-logs` — not a file, deliberately durable and queryable.

None of these should ever contain a plaintext secret — `SecretRedactor` (Phase 30A) strips
secret-bearing keys from everything written to `audit_logs`/`error_logs` context; the raw
`.env`/PHP-FPM error log is the only place a misconfigured secret could theoretically leak, which
is why `.env` is `chmod 600` (§7) and never logged directly by the application.

## 16. Rollback

**Application code:**

```bash
cd /var/www/gomrok/app
sudo -u gomrok git fetch --tags
sudo -u gomrok git checkout <previous-known-good-tag-or-commit>
sudo -u gomrok composer install --no-dev --optimize-autoloader --no-interaction
sudo systemctl restart php8.4-fpm gomrok-worker
curl -sf https://gomrok.example.com/health
```

**Database migrations** — only if the failed deploy's migration is safely reversible (check its
`down()` first; an additive-only migration, this project's overwhelming convention per
`.claude/Rule.md`'s database strategy, is always safe to leave in place even when rolling back
application code, since old code simply ignores new nullable columns):

```bash
sudo -u gomrok vendor/bin/phinx status              # find the migration to roll back to
sudo -u gomrok vendor/bin/phinx rollback -t <version>
```

**Emergency, client-specific rollback** (CLAUDE.md's go-live checklist requirement — "disable the
client, revoke keys" — distinct from an application-code rollback, for when the *code* is fine
but one *client's* configuration or credentials are the problem): see
`.claude/docs/GoLiveChecklist.md`'s rollback section — this is an admin-panel action
(`/admin/clients` → set status `disabled`; `/admin/clients` → revoke the affected API key), not a
deploy step.

## 17. Deployment verification

Checklist for "this deploy actually worked":

- [ ] `GET /health` → `200 {"status":"ok",...}` (§12).
- [ ] `GET /api/v1/me` with no auth → `401` (§13) — confirms the app is actually serving traffic
      through nginx → php-fpm → Gomrok, not an nginx default page or a stale cached response.
- [ ] `sudo systemctl is-active php8.4-fpm nginx gomrok-worker` → all three `active`.
- [ ] `vendor/bin/phinx status` → every migration `up`, none pending.
- [ ] `sudo journalctl -u gomrok-worker -n 20` shows a recent successful poll (§14).
- [ ] No new `ProductionSafetyViolation` in the php-fpm error log (§4/§15) — if one appears, the
      deploy's `.env` is missing/wrong, not a code bug.
- [ ] `/admin/error-logs` shows no new unresolved entries attributable to this deploy.
- [ ] `/admin/jobs` shows no new "alerting" badge attributable to this deploy.

## 18. Post-deployment checks

- Watch `/admin/error-logs` and `/admin/jobs` for the first 30–60 minutes after a deploy —
  the window where a real but low-frequency bug (e.g. a rare webhook shape, a rare provider
  error code) is most likely to first surface under real traffic.
- Confirm real provider webhooks are actually arriving (`/admin/notifications`,
  `webhook_events` row counts increasing) if this deploy touched provider/webhook code.
- Confirm the previous deploy's `git` tag/commit is recorded somewhere retrievable (a deploy log,
  a `CHANGELOG`/tag) so §16's rollback target is never a guess under pressure.
- See `.claude/docs/MonitoringChecklist.md` for what to keep watching on an ongoing basis, not
  just right after a deploy.

---

## Portability — if the real target isn't this one

This runbook assumes one Linux VPS running everything directly on the host (nginx + php-fpm +
systemd + MySQL). If the real production target turns out to be different:

- **Docker / containers.** §2–§4 collapse into a Dockerfile (this repo already has one for local
  dev — `Dockerfile`, `php:8.4-cli` + the same four extensions) built into an image instead of
  installed on a bare host; §8–§9 (nginx/php-fpm) become either a second `nginx`/`php-fpm`
  container or, more commonly, `php -S`/a single PHP-based web server process per container
  (matching how local dev already runs — `command: php -S 0.0.0.0:8080 -t src/Public` in
  `docker-compose.yml`); §10 (`systemd` for the worker) becomes a **second container** running
  `php bin/Worker.php` as its entrypoint, with the container orchestrator's own restart policy
  (`restart: always` in Compose; a Deployment's `restartPolicy` in Kubernetes) standing in for
  `Restart=always`; §16 (rollback) becomes "redeploy the previous image tag" instead of `git
  checkout`. §1/§5–§7/§11–§18 stay conceptually the same (a database still needs credentials and
  migrations; health checks, logging, and verification steps don't change just because the
  process is containerized).
- **A managed platform** (a PaaS, a managed container service, etc.) — §1–§3, §8–§11 are mostly
  replaced by the platform's own deploy mechanism and process-type/worker configuration (many
  platforms have a native concept of a "web" process type and a "worker" process type, which map
  directly onto Gomrok's web app and `bin/Worker.php`); §4–§7, §12–§18 stay largely the same —
  environment variables, migrations, health checks, and logging are still real requirements
  regardless of platform.
- **Multiple app hosts (horizontal scaling).** Nothing in Gomrok's own design assumes a single
  instance — `PdoIdempotencyStore`/`PdoJobRepository`'s locking (Phase 29/30A) is already
  correct under concurrent access from multiple processes, verified for real under load
  (Phase 30A Q2). Running two or more `bin/Worker.php` instances for redundancy is explicitly
  safe (`SELECT ... FOR UPDATE SKIP LOCKED`) — see `.claude/docs/Deployment.md`.

Whichever of these ends up being the real target, replace this document's concrete commands
in-place rather than keeping this illustrative version around indefinitely — see also
`.claude/docs/Deployment.md`.
