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
composer package:update -- --client=televika --package=pro [--name="Pro Plus"] [--badge="Best value"] [--highlight | --unhighlight] [--client-package-id=X] [--disable | --enable]
composer package:list -- --client=televika

# Package purchase capabilities & provider definitions (Phase 12)
composer package:set-capabilities -- --client=televika --package=pro --capability=one_time_payment --capability=subscription [--trial-days=14] [--duration-months=1]
composer package:set-country-capabilities -- --client=televika --package=pro --override=TR:one_time_payment --override=DE:one_time_payment,subscription
composer package:link-provider -- --client=televika --package=pro --account=stripe-live [--name="Pro (Stripe)"] [--remote-id=prod_ABC] [--not-needed]
```

`code` is unique **per client**. Availability (country / currency / method / provider) is
**fail-open** (empty = all). **Purchase types are fail-closed** — a package with no
`package:set-capabilities` row is not sellable and never appears in the catalogue. A
per-country `--override` *replaces* the global purchase-type set for that country. `status=disabled`
hides a package. Editing a package flips its `synced` provider definitions to `drift`.

```bash
# Pricing (Phase 13 — baseline)
composer pricing:create-group -- --client=televika --name="DACH" --currency=EUR [--slug=dach --priority=1 --device=ios --default]
composer pricing:set-default-price -- --client=televika --package=pro --amount-minor=2900 --currency=EUR
composer pricing:set-rate -- --client=televika --base=EUR --quote=USD --rate=1.08 [--from="2026-01-01T00:00:00Z"]
composer pricing:set-group-package -- --client=televika --group=dach --package=pro --status=override --amount-minor=2400 --currency=EUR [--name="Pro (DACH)" --order=1]
composer pricing:list -- --client=televika

# Price rules — dimension overrides (Phase 14)
composer pricing:set-rule -- --client=televika --package=pro --method=card --currency=EUR --amount-minor=2500
composer pricing:set-rule -- --client=televika --package=pro --group=us --purchase-type=subscription --interval=yearly --unavailable
composer pricing:list-rules -- --client=televika --package=pro
composer pricing:delete-rule -- --client=televika --rule=42

# Price lists — A/B experiments (Phase 15)
composer pricing:create-list -- --client=televika --group=dach --name="List B · -10%" --factor=0.9000
composer pricing:set-list-price -- --client=televika --list=42 --package=pro --amount-minor=2100 --currency=EUR
composer pricing:set-list-factor -- --client=televika --list=42 --factor=0.8500
composer pricing:set-list-status -- --client=televika --list=42 --disable   # or --enable
composer pricing:list-lists -- --client=televika --group=dach
```

A **price rule** is a `(client, package)` override keyed by any subset of 7 dimensions
(`--group`, `--country`, `--provider-account`, `--method`, `--purchase-type`,
`--interval`, `--currency`); an unset dimension is a wildcard. `--unavailable` marks the
combination not for sale (omit `--amount-minor`). At resolve time the **most-specific** matching
rule wins (most pinned dimensions → fixed dimension priority → newest); an unavailable winner
returns `pricing.combination_unavailable` with no fallback. An available rule needs `--group` or
`--currency` pinned. Rules apply on `/pricing/resolve`, not on the `/packages` browse list.

A **price list** is an A/B experiment inside one pricing group. Every group has an undeletable
**control** list (`List A · control`, factor `1.0000`) created with the group. A non-control
list shifts the resolved base price by `--factor`, or you pin an exact per-package amount with
`pricing:set-list-price`. In the resolution pipeline the list applies **after** the base price
and **before** the Phase 14 dimension rules. **Visitor→list assignment isn't wired yet** (it's
deferred to the payment/checkout phase), so today every resolve uses the control list — these
commands set up the experiments in advance.

Pricing groups are **priority-ordered and overlapping** — lowest `priority` wins, `is_default`
(created with `--default`, no countries) is always last. A `status=default` group in a currency
different from the package's baseline converts via the client FX rate; `status=override` carries
its own amount in the group currency; `status=disabled` hides the package in that group.

```bash
# Client-facing catalogue (Phase 13)
curl -s '.../api/v1/packages?country=DE&device=ios' -H 'Authorization: Bearer gk_...'
curl -s '.../api/v1/pricing/resolve?package=pro&country=DE&method=card&purchase_type=subscription&interval=yearly' -H 'Authorization: Bearer gk_...'

# One package's detail (Phase 19) — {packageId} accepts either the numeric id or the code
curl -s '.../api/v1/packages/pro?country=DE' -H 'Authorization: Bearer gk_...'
curl -s '.../api/v1/packages/1?country=DE' -H 'Authorization: Bearer gk_...'

# Voucher eligibility + discount preview (Phase 19) — GET, reserves nothing
curl -s '.../api/v1/vouchers/validate?package=pro&country=DE&code=WELCOME10' -H 'Authorization: Bearer gk_...'
```

`GET /api/v1/packages` returns the availability + purchase capabilities + **resolved base price**
per package (no dimension rules). `GET /api/v1/pricing/resolve` returns one price with Phase 14
`price_rules` applied — the optional `method` / `purchase_type` / `interval` query params feed
the rule match, and the response `price` object carries `applied_rule_id` + `applied_dimensions`.
An unavailable combination returns `422 pricing.combination_unavailable`. Gomrok never trusts a
client-supplied price. (Phase 15 added `price_lists` between the base and the rules, but every
resolve uses each group's control list until visitor→list assignment lands in the checkout phase
— the API responses are unchanged.)

`GET /api/v1/packages/{packageId}` (Phase 19) returns the exact same shape as one item of
`GET /api/v1/packages` — same `country` / `method` / `device` params — for one package, found by
either its numeric id or its code. A package that exists but isn't available/sellable in the
requested context is `404 package.not_found_in_context`, same as an unknown id/code being
`404 package.not_found`.

`GET /api/v1/vouchers/validate` (Phase 19) resolves the package's price internally (same as
`pricing/resolve`, plus the optional `method` / `purchase_type` / `interval` / `device` params),
then runs the Phase 16 eligibility evaluator against `code`. Add `client_user_ref` when the
voucher has a per-user cap, and `first_purchase=true|false` for a `first_purchase_only` voucher.
The response is `{"eligible": bool, "reasons": [...], "voucher": {...}, "price": {...}}` plus a
`discount` object (`nominal_minor` / `applied_minor` / `payable_minor`) only when `eligible` is
`true`. Nothing is reserved — repeat calls are always safe, and it is deliberately `GET`, not
`POST`, so it never needs an `Idempotency-Key`.

Provider secrets are encrypted with `APP_ENCRYPTION_KEY` — set it before creating accounts
(`php -r 'echo base64_encode(random_bytes(32));'`). Secrets are printed once at most, never by
`:list`.

The API token is printed **once** by `client:create` / `client:issue-key`. `--test` issues a
`gk_test_…` key. Needs a running DB.

```bash
# Vouchers — definitions & eligibility (Phase 16)
composer voucher:create -- --client=televika --code=WELCOME10 --name="Welcome 10%" --default-type=percentage --default-percent-bp=1000
composer voucher:set-limits -- --client=televika --voucher=1 --max-per-user=1
composer voucher:set-eligibility -- --client=televika --voucher=1 --rule=country:DE --rule=country:AT
composer voucher:set-currency-discount -- --client=televika --voucher=2 --currency=TRY --type=fixed --amount-minor=5000
composer voucher:remove-currency-discount -- --client=televika --voucher=2 --currency=TRY
composer voucher:set-status -- --client=televika --voucher=1 --disable   # or --enable
composer voucher:update -- --client=televika --voucher=1 --name="Welcome 15%" --default-type=percentage --default-percent-bp=1500
composer voucher:list -- --client=televika

# Voucher redemption — reserve / confirm / release (Phase 17)
composer voucher:reserve -- --client=televika --voucher=1 --attempt=order-42 --currency=EUR --price-minor=2900 [--client-user=user-1] [--country=DE] [--package=7]
composer voucher:confirm -- --client=televika --voucher=1 --attempt=order-42
composer voucher:release -- --client=televika --voucher=1 --attempt=order-42
composer voucher:list-redemptions -- --client=televika --voucher=1

# Checkout attempts — pre-payment lifecycle (Phase 18)
composer checkout:start -- --client=televika --attempt=order-42 --package=7 --country=DE --currency=EUR [--client-user=user-1] [--purchase-type=one_time_payment] [--method=card] [--interval=yearly]
composer checkout:resolve-pricing -- --client=televika --attempt=order-42 [--device=web] [--provider-account=1] [--price-list=1]
composer checkout:reserve-voucher -- --client=televika --attempt=order-42 --code=WELCOME10 [--client-user=user-1]
composer checkout:select-provider -- --client=televika --attempt=order-42 --mode=test [--device=web]
composer checkout:set-status -- --client=televika --attempt=order-42 --status=canceled
composer checkout:set-status -- --client=televika --attempt=order-42 --status=failed --error-code=provider_declined --error-message="Card declined"
composer checkout:list -- --client=televika

# Payments — aggregate & lifecycle (Phase 20); no real provider adapter yet
composer payment:create -- --client=televika --attempt=order-42
composer payment:record-transaction -- --client=televika --payment=1 --provider-account=1 --kind=authorize --status-raw=requires_action --new-status=pending [--method=card]
composer payment:record-transaction -- --client=televika --payment=1 --provider-account=1 --kind=authorize --status-raw=succeeded --new-status=authorized --attempt-outcome=succeeded
composer payment:set-status -- --client=televika --payment=1 --status=refunded
composer payment:link-customer -- --client=televika --provider-account=1 --client-user=user-1 --provider-customer-id=cus_abc123
composer payment:list -- --client=televika
```

`code` is `^[A-Z0-9][A-Z0-9_-]{2,63}$` (≥3 chars), unique per client, stored upper-case. A
voucher's **default discount** is `none` / `percentage` / `full` (never `fixed`);
`voucher:set-currency-discount` adds a per-currency **override** (any type, incl. `fixed`) that
wins over the default for that currency — resolution is override → else default → else (`none`)
not applicable. Usage limits are three nullable columns
(`--max-total` / `--max-per-user` / `--max-per-client`) — omit a flag for "unlimited" on that
axis; `--max-per-user=1` with the other two unset is "valid for everyone, once per user."
`voucher:set-eligibility` **full-replaces** the rule set (repeat `--rule=dimension:value`; no
`--rule` at all clears every restriction). The create/update/eligibility/discount/limits/status
commands manage definitions only — `GET /api/v1/vouchers/validate` (Phase 19, above) is the
non-locking eligibility + discount preview.

`voucher:reserve` re-checks eligibility (now including the usage caps), computes the discount,
and reserves it against an `--attempt` reference — repeating the same `--attempt` for the same
`--voucher` is a no-op that returns the existing reservation, never a double-redemption.
`voucher:confirm` finalises it after a successful payment (increments the voucher's global
count once, ever); `voucher:release` frees it after a failed/canceled attempt so the usage cap
is available again. A confirmed redemption can never be released, and a released one can never
be confirmed. Full rule set: **`.claude/Voucher.md`**.

`checkout:start` starts (or idempotently replays, by `--attempt`) a `checkout_attempts` row.
`checkout:resolve-pricing` runs `PriceResolver` and writes a `pricing_decision_snapshots` row,
advancing status to `pricing_resolved`. `checkout:reserve-voucher` reserves the voucher (same
`--attempt` string reused as the voucher redemption's own reference) and writes a
`voucher_decision_snapshots` row, advancing to `voucher_reserved`; skip it entirely and go
straight to `checkout:select-provider` for a no-voucher checkout — the status machine allows
`pricing_resolved → provider_selected` directly. `checkout:select-provider` runs `ProviderRouter`
and writes a `provider_routing_decision_snapshots` row, advancing to `provider_selected`.
`checkout:set-status` drives any non-terminal → terminal exit (`failed` / `canceled` / `expired`
/ `abandoned`) directly — useful for manually closing out an attempt without going through the
rest of the pipeline. `--attempt` accepts either the caller's `attempt_reference` string or the
numeric `checkout_attempts.id` everywhere it's a parameter (resolved by trying the id first,
then falling back to a lookup by reference).

`payment:create` requires the checkout attempt's status to be `confirmed` — it copies the
checkout attempt's commercial context plus the already-resolved payable amount (the voucher's
amount if one was reserved, else the pricing snapshot's) onto a new `payments` row and converts
the attempt. Repeating it for the same `--attempt` is a no-op that returns the existing payment.
`payment:record-transaction` appends one raw provider call/response under the payment's current
attempt (opening a new one if the last is already `succeeded`/`failed`) and transitions the
payment per the `PaymentStatus` graph — `--attempt-outcome=succeeded|failed` explicitly completes
the attempt (never inferred from `--new-status`). `payment:set-status` is the escape hatch for
any other legal transition (e.g. an admin cancel). `payment:link-customer` records a durable
customer identity for later reuse; repeating the same `--provider-account`/`--provider-customer-id`
pair is a no-op. There is no real provider adapter yet (Phase 21+), so every `--status-raw` /
`--new-status` here is supplied by the caller, not derived from an actual provider response — and
no HTTP endpoint yet (Phase 24).

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
