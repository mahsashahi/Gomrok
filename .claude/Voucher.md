# Voucher.md — the source of truth for voucher behaviour

Every voucher-related rule, decision, assumption, constraint, schema behaviour, eligibility
rule, discount rule, usage-limit rule, and implementation note for Gomrok lives here. If it is
about vouchers and it matters, it is written here — **before or alongside** the code/migration
that implements it (`CLAUDE.md` → *Voucher Rules File* rule).

Related files: `CLAUDE.md` → *Voucher Requirement*; `.claude/docs/database-design.md` /
`database-diagram.md` / `db_explain.md` (voucher tables); `.claude/PhaseResults/PhaseDecisions.md`
(Phase 16 Q1–Q5, Phase 17 Q1–Q5); `.claude/Orders.md` (D19, D20); `.claude/docs/Phases.md`
(Phases 16–18).

---

## 1. What a voucher is

A **client-scoped** discount that Gomrok validates internally before creating a provider payment
or subscription. The provider only ever receives the final resolved amount (or a
provider-supported discount representation) — never the voucher itself.

- A voucher belongs to exactly one client (`vouchers.client_id`).
- `code` is unique **per client** (`UNIQUE (client_id, code)`), stored upper-case, compared
  case-insensitively. The same code may recur across different clients.
- A voucher has a lifecycle state (`active` / `disabled`) and a validity window.

## 2. Module layout (Phase 16+)

`src/Modules/Vouchers/{Domain,Application,Infrastructure,Http}` — hexagonal + modular, same as
every other module. Handlers return `Result`, use the `Transactions` port, audit sensitive
writes, and are registered via `src/Modules/Vouchers/Infrastructure/definitions.php`.

## 3. Schema (as built — keep in lock-step with `database-design.md`)

> Phase 16 built `vouchers`, `voucher_eligibility_rules`, `voucher_currency_discounts`.
> Phase 17 added `voucher_redemptions`. Voucher decision snapshots are **Phase 18**.

### `vouchers`

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE |
| `code` | VARCHAR(64) | no | upper-case; `UNIQUE (client_id, code)` |
| `name` | VARCHAR(150) | no | admin-facing label |
| `description` | VARCHAR(500) | yes | |
| `status` | VARCHAR(20) | no | `active` / `disabled`; default `active` |
| `valid_from` | DATETIME | yes | `NULL` = no lower bound |
| `valid_until` | DATETIME | yes | `NULL` = no upper bound |
| `first_purchase_only` | TINYINT(1) | no | default `0` |
| `min_purchase_minor` | BIGINT UNSIGNED | yes | minimum pre-discount amount; `NULL` = none |
| `min_purchase_currency` | CHAR(3) | yes | FK → `currencies(code)` RESTRICT; required iff `min_purchase_minor` set |
| `default_discount_type` | VARCHAR(20) | no | `none` / `percentage` / `full` (**never `fixed`**) |
| `default_percent_bp` | SMALLINT UNSIGNED | yes | basis points 1..10000; required iff `default_discount_type = percentage` |
| `max_total_redemptions` | INT UNSIGNED | yes | **`NULL` = unlimited globally** |
| `max_per_user` | INT UNSIGNED | yes | **`NULL` = unlimited per client user** |
| `max_per_client` | INT UNSIGNED | yes | **`NULL` = unlimited per client** |
| `redeemed_count` | INT UNSIGNED | no | default `0`; global tally, incremented atomically by **Phase 17** only |
| `created_at` / `updated_at` | DATETIME | no / yes | |

Indexes: `UNIQUE (client_id, code)` = `uniq_vouchers_client_code`;
`INDEX (client_id, status)` = `idx_vouchers_client_status`.

### `voucher_eligibility_rules` (Phase 16 Q1 — Option 1)

One table, one row per `(voucher, dimension, value)`.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `dimension` | VARCHAR(30) | no | `country` / `currency` / `package` / `provider_account` / `payment_method` / `purchase_type` / `subscription_interval` |
| `value` | VARCHAR(64) | no | country code, currency code, package id (as string), provider-account id (as string), or the enum value |
| `created_at` | DATETIME | no | |

`UNIQUE (voucher_id, dimension, value)` = `uniq_voucher_eligibility`;
`INDEX (voucher_id, dimension)` = `idx_voucher_eligibility_dim`.

**Semantics:** multiple values for one dimension = **OR** (any match passes that dimension);
across dimensions = **AND** (every restricted dimension must pass); **no rows for a dimension =
unrestricted**. FK-able values (`package`, `provider_account`) are validated to belong to the
voucher's client at write time (no DB FK on `value`).

### `voucher_currency_discounts` (Phase 16 Q2 — Option 2, extended)

Per-currency **override** of the voucher's default discount. A currency that uses the default
has **no row here**.

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `discount_type` | VARCHAR(20) | no | `fixed` / `percentage` / `full` |
| `percent_bp` | SMALLINT UNSIGNED | yes | basis points 1..10000; set iff `percentage` |
| `amount_minor` | BIGINT UNSIGNED | yes | this currency's minor units; set iff `fixed`, `> 0` |
| `max_discount_minor` | BIGINT UNSIGNED | yes | optional cap in this currency's minor units; only with `percentage` |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (voucher_id, currency_code)` = `uniq_voucher_currency_discounts`.

### `voucher_redemptions` (Phase 17 Q1/Q2)

The reserve → confirm/release lifecycle. Identified by a caller-supplied `attempt_reference`
(Phase 17 Q1 — Phase 20 passes the payment id once payments exist).

| Column | Type | Null | Notes |
| --- | --- | --- | --- |
| `id` | INT UNSIGNED AI | no | PK |
| `voucher_id` | INT UNSIGNED | no | FK → `vouchers(id)` CASCADE |
| `client_id` | INT UNSIGNED | no | FK → `clients(id)` CASCADE (= the voucher's client) |
| `client_user_ref` | VARCHAR(120) | yes | required at reserve time iff the voucher's `max_per_user` is set |
| `attempt_reference` | VARCHAR(191) | no | opaque, caller-supplied |
| `status` | VARCHAR(20) | no | `reserved` / `confirmed` / `released`, default `reserved` |
| `currency_code` | CHAR(3) | no | FK → `currencies(code)` RESTRICT |
| `price_minor` | BIGINT UNSIGNED | no | pre-discount price at reservation time |
| `nominal_discount_minor` | BIGINT UNSIGNED | no | what the discount rule says, before any clamping |
| `applied_discount_minor` | BIGINT UNSIGNED | no | after the configured cap and the price-floor clamp |
| `payable_minor` | BIGINT UNSIGNED | no | `price_minor - applied_discount_minor` |
| `reserved_at` | DATETIME | no | |
| `confirmed_at` / `released_at` | DATETIME | yes / yes | set on the matching transition |
| `created_at` / `updated_at` | DATETIME | no / yes | |

`UNIQUE (voucher_id, attempt_reference)` = `uniq_voucher_redemptions_attempt` (the idempotency
key); `INDEX (voucher_id, client_user_ref, status)` = `idx_voucher_redemptions_user` (per-user
cap); `INDEX (voucher_id, client_id, status)` = `idx_voucher_redemptions_client` (per-client
cap); `INDEX (status)` = `idx_voucher_redemptions_status` (for the Phase 29 stale-reservation
sweep). No schema change to `vouchers` — `redeemed_count` still means "confirmed, globally"; the
live "reserved" count comes from this table.

## 4. Discount rules

- **`fixed`** — a flat amount in a currency's minor units. **Only ever a per-currency override
  row** — there is no default fixed discount (a fixed amount is inherently currency-bound).
- **`percentage`** — `percent_bp` basis points (500 = 5.00%). May carry `max_discount_minor`
  (a cap, in the resolved currency's minor units). A percentage default has **no cap**; to cap a
  percentage in a currency, add a `voucher_currency_discounts` override row for that currency.
- **`full`** — 100% off (the payable amount becomes zero). Choosing `full` is itself the
  "explicitly allowed" acknowledgement (CLAUDE.md: "a full discount when explicitly allowed").
- **`none`** (default only) — the voucher has no currency-agnostic default; it discounts **only**
  in currencies that have an override row. Use this for a pure multi-currency fixed voucher
  (e.g. €5 / $6 / £4 off).

### Resolution for a checkout in currency `X`

```
1. voucher_currency_discounts row for (voucher, X)   → use that row's type/value/cap
2. else default_discount_type = percentage           → default_percent_bp %, no cap
3. else default_discount_type = full                 → 100%
4. else default_discount_type = none                 → voucher NOT applicable in X
```

The **subtraction itself** (`VoucherDiscountCalculator`, Phase 17 Q4) applies the % / amount,
rounds HALF_EVEN via `Money`, then clamps in two stages: the merchant-configured
`max_discount_minor` cap (percentage only), then the hard price-floor (a discount can never
exceed the price). The result carries **both** figures — `nominalDiscountMinor` (the raw rule
value, before either clamp) and `appliedDiscountMinor` (after both) — so clamping is visible for
audit/snapshot use rather than silently invisible. Example: a 50% voucher capped at €5.00 on a
€29.00 item → `nominal = €14.50`, `applied = €5.00` (the configured cap bites, not the price
floor). A €50.00 fixed override on a €30.00 item → `nominal = €50.00`, `applied = €30.00` (the
price floor bites). `payableMinor = priceMinor - appliedDiscountMinor`, never negative.

### Domain guards (`VoucherDiscount` / handlers)

- `percentage` ⇒ `percent_bp` in 1..10000, `amount_minor` null.
- `fixed` ⇒ `amount_minor` > 0, `percent_bp` null, `max_discount_minor` null.
- `full` ⇒ `percent_bp`, `amount_minor`, `max_discount_minor` all null.
- `max_discount_minor` only permitted with `percentage`.
- `default_discount_type` ∈ {`none`, `percentage`, `full`} — **`fixed` is rejected on the
  voucher row.**
- A `voucher_currency_discounts.currency_code` must be a configured currency.

## 5. Eligibility rules

The `VoucherEligibilityEvaluator` (Phase 16) takes a `VoucherContext` and reports **every**
unmet condition (not fail-fast). Checks, in no particular order:

| Check | Fails when | Reason code |
| --- | --- | --- |
| Status | `status = disabled` | `voucher.disabled` |
| Window (lower) | `valid_from` set and `now < valid_from` | `voucher.not_yet_valid` |
| Window (upper) | `valid_until` set and `now > valid_until` | `voucher.expired` |
| Client scope | context client ≠ `voucher.client_id` | `voucher.wrong_client` |
| Country | `country` rules exist and none match the context country | `voucher.country_not_eligible` |
| Currency | `currency` rules exist and none match the context currency | `voucher.currency_not_eligible` |
| Package | `package` rules exist and none match the context package | `voucher.package_not_eligible` |
| Provider account | `provider_account` rules exist and none match | `voucher.provider_not_eligible` |
| Payment method | `payment_method` rules exist and none match | `voucher.method_not_eligible` |
| Purchase type | `purchase_type` rules exist and none match | `voucher.purchase_type_not_eligible` |
| Subscription interval | `subscription_interval` rules exist and none match | `voucher.interval_not_eligible` |
| Discount applicability | `default_discount_type = none` **and** no `voucher_currency_discounts` row for the context currency | `voucher.no_discount_for_currency` |
| Minimum purchase | `min_purchase_minor` set, context amount known, same currency, and amount `< min_purchase_minor` | `voucher.below_minimum` |
| First purchase — unknown | `first_purchase_only = 1` and the context doesn't say whether this is the first purchase | `voucher.first_purchase_unknown` |
| First purchase — known false | `first_purchase_only = 1` and the context says this is not the first purchase | `voucher.not_first_purchase` |
| Global usage (Phase 17) | `max_total_redemptions` set and `redeemed_count` (confirmed) + live `reserved` count `>= max_total_redemptions` | `voucher.exhausted` |
| Per-user cap — ref missing (Phase 17) | `max_per_user` set and the context has no `clientUserRef` | `voucher.client_user_required` |
| Per-user cap — reached (Phase 17) | `max_per_user` set, a `clientUserRef` given, and that user's active (`reserved`+`confirmed`) redemptions `>= max_per_user` | `voucher.user_limit_reached` |
| Per-client cap (Phase 17) | `max_per_client` set and the client's active (`reserved`+`confirmed`) redemptions `>= max_per_client` | `voucher.client_limit_reached` |

**Implemented in Phase 17** via `VoucherUsagePort` (declared in Phase 16, implemented by
`PdoVoucherRedemptionRepository` against `voucher_redemptions`): the global check now includes
live reservations (not just confirmed ones), and the per-user / per-client checks run for real.
The evaluator is called both as a best-effort pre-check (no lock) and as the **authoritative**
gate inside `ReserveVoucherRedemptionHandler`'s locked transaction (Phase 17 Q3) — in the
authoritative call, the usage-port queries run on the same connection/transaction that holds the
`vouchers` row lock, so they see a consistent snapshot.

### `VoucherContext` (evaluator input)

`clientId` (required), `now` (required), `country`, `currency` (required for a meaningful
result), `?packageId`, `?providerAccountId`, `?PaymentMethod`, `?PurchaseType`,
`?SubscriptionInterval`, `?amountMinor` + `?amountCurrency` (pre-discount price, for the
minimum-purchase check), `?clientUserRef`, `?isFirstPurchase` (the caller supplies this — Gomrok
does not know the client's purchase history in Phase 16).

Gomrok never trusts a client-supplied discount or final price. The client supplies the *code*
and the *context*; Gomrok resolves everything else.

## 6. Usage-limit rules (Phase 16 Q3 — Option 2)

The three caps are **columns on `vouchers`**, each **nullable = unlimited** for that dimension.
No per-scope child table.

| Field | `NULL` means | Example |
| --- | --- | --- |
| `max_total_redemptions` | unlimited globally | `NULL` |
| `max_per_user` | unlimited per client user | `1` = once per user |
| `max_per_client` | unlimited per client | `NULL` |

**Canonical example — "valid for everyone, once per user":**
`max_total_redemptions = NULL`, `max_per_user = 1`, `max_per_client = NULL`.

`redeemed_count` (global tally) is a `vouchers` column, default `0`, incremented **only by
`ConfirmVoucherRedemptionHandler`** (`VoucherRepository::incrementRedeemedCount`, a plain atomic
`UPDATE ... SET redeemed_count = redeemed_count + 1`), and only the first time a given redemption
is confirmed (checked via its status before the transition). Per-user / per-client actual counts
are `COUNT(*)` over `voucher_redemptions` (status `reserved` or `confirmed`), via
`VoucherRedemptionRepository::countForVoucherAndUser` / `countForVoucherAndClient` — the same
queries back `VoucherUsagePort` for the eligibility evaluator.

## 7. Redemption lifecycle (Phase 17 — implemented)

Three states — `reserved` → `confirmed` (terminal, permanent) or `reserved` → `released`
(terminal, frees the reservation) — identified by `(voucher_id, attempt_reference)`
(Phase 17 Q1). See `RedemptionStatus`, `VoucherRedemption` (Domain).

- **Reserve** (`ReserveVoucherRedemptionHandler`) — locks the `vouchers` row (`FOR UPDATE`),
  looks up any existing redemption for this `attempt_reference` and returns it unchanged if
  found (idempotent replay, regardless of its current status), otherwise re-runs
  `VoucherEligibilityEvaluator` (now including the usage checks) and `VoucherDiscountCalculator`,
  then inserts a `reserved` row. A `reserved` row **counts toward every cap immediately** — global,
  per-user, per-client (Phase 17 Q2).
- **Confirm** (`ConfirmVoucherRedemptionHandler`) — locks the `vouchers` row, transitions
  `reserved → confirmed`, increments `redeemed_count` once. Idempotent (confirming an
  already-confirmed redemption is a no-op); a `released` redemption can never be confirmed
  (`voucher_redemption.already_released`).
- **Release** (`ReleaseVoucherRedemptionHandler`) — locks the `vouchers` row, transitions
  `reserved → released`. Idempotent; a `confirmed` redemption can never be released
  (`voucher_redemption.already_confirmed` — that needs a refund flow, not this). No counter to
  decrement: a released row simply stops matching the `reserved`/`confirmed` status filter that
  every cap check uses.
- **Concurrency safety** (Phase 17 Q3): every one of the three handlers acquires the same
  `SELECT ... FOR UPDATE` lock on the `vouchers` row before touching `voucher_redemptions` for
  that voucher — the `vouchers` row is the de facto per-voucher mutex. All cap re-checks happen
  after acquiring that lock and before any write, closing the race for a checkout that would
  otherwise oversell a limited voucher.
- **No automatic expiry** (Phase 17 Q2): an abandoned `reserved` row (e.g. a crashed checkout)
  stays reserved — and keeps counting — until something releases it. A stale-reservation sweep
  is explicitly a **Phase 29** background job; `reserved_at` is stored for it to use.
- `attempt_reference` is caller-supplied and opaque to this module (Phase 17 Q1); Phase 20 will
  pass the real payment id once Payments exists — no schema or handler change needed then.

## 8. Decision snapshot (Phase 18 — recorded here for completeness)

Every payment/subscription that used a voucher preserves a **voucher decision snapshot**: the
voucher id + code, the resolved discount (type, value, currency, cap), the discount amount
applied, and the eligibility inputs — so later voucher edits never change historical
transactions.

## 9. Decisions log

| Phase / Q | Decision | Date |
| --- | --- | --- |
| 16 Q1 | Scoping via a single `voucher_eligibility_rules` table `(voucher_id, dimension, value)`; OR within a dimension, AND across; no rows = unrestricted. | 2026-09-10 |
| 16 Q2 | Discount = a **default** on `vouchers` (`default_discount_type` `none`/`percentage`/`full` + `default_percent_bp`) **plus per-currency override rows** (`voucher_currency_discounts`, any type + optional cap). Resolution: override → default. No default fixed amount, no default cap. | 2026-09-10 (revised — user extended Option 2) |
| 16 Q3 | Usage limits are **nullable columns on `vouchers`** (`max_total_redemptions` / `max_per_user` / `max_per_client`, `NULL` = unlimited) + `redeemed_count`. No per-scope child table. | 2026-09-10 (user chose Option 2) |
| 16 Q4 | Eligibility evaluator returns a `VoucherEligibility` VO listing **every** failing reason code (not fail-fast). Checks state / window / client scope / all eligibility-rule dimensions / discount applicability / minimum purchase / first purchase / **global** usage cap. Per-user + per-client caps deferred to Phase 17 behind a declared-not-implemented `VoucherUsagePort` seam. | 2026-09-10 |
| 16 Q5 | Granular audited handlers (`CreateVoucher`, `UpdateVoucher`, `SetVoucherEligibility` full-replace, `SetVoucherCurrencyDiscount` + `RemoveVoucherCurrencyDiscount`, `SetVoucherUsageLimits`, `ChangeVoucherStatus`) + `VoucherDirectory` + `voucher:*` CLI + env-gated `VouchersSeeder`. `code` = `^[A-Z0-9][A-Z0-9_-]{2,63}$`, stored upper-case, `UNIQUE (client_id, code)`. | 2026-09-10 |
| 17 Q1 | A redemption attempt is identified by a caller-supplied opaque `attempt_reference` string, `UNIQUE (voucher_id, attempt_reference)`. Phase 20 passes the payment id as this string once Payments exists. | 2026-09-11 |
| 17 Q2 | Three states — `reserved` / `confirmed` / `released`. A `reserved` row counts toward every cap immediately and keeps counting until released; **no automatic expiry** in Phase 17 — a stale-reservation sweep is deferred to **Phase 29** (background jobs). | 2026-09-11 |
| 17 Q3 | Concurrency safety = `SELECT ... FOR UPDATE` on the `vouchers` row inside every reserve/confirm/release transaction; all cap re-checks happen under that lock before any write. | 2026-09-11 |
| 17 Q4 | `VoucherDiscountCalculator` always clamps to `[0, price]` (configured cap, then price floor); the result carries both `nominalDiscountMinor` (pre-clamp) and `appliedDiscountMinor` (post-clamp) rather than rejecting or hiding the clamp. | 2026-09-11 |
| 17 Q5 | Three lifecycle handlers (`ReserveVoucherRedemption`, `ConfirmVoucherRedemption`, `ReleaseVoucherRedemption`) + `VoucherDiscountCalculator` + `PdoVoucherRedemptionRepository` implementing the Phase 16 `VoucherUsagePort` + `voucher:reserve|confirm|release|list-redemptions` CLI. | 2026-09-11 |

## 10. Implementation pointers (Phase 16–17 — as built)

`src/Modules/Vouchers/{Domain,Application,Infrastructure}`:

- Domain: `Voucher` (aggregate), `VoucherCurrencyDiscount` (VO + `validate()`),
  `VoucherEligibilityRule` (VO), `VoucherStatus` / `DefaultDiscountType` / `DiscountType` /
  `VoucherEligibilityDimension` enums, `VoucherRepository` / `VoucherEligibilityRuleRepository` /
  `VoucherCurrencyDiscountRepository` ports.
- Application: `VoucherContext`, `VoucherEligibility`, `VoucherUsagePort` (Phase 16 declared /
  **Phase 17 implemented**), `VoucherEligibilityEvaluator`, `VoucherDiscountResult` +
  `VoucherDiscountCalculator` (Phase 17), `VoucherAuditSnapshot`, `VoucherSummary` +
  `VoucherDirectory`, `VoucherRedemptionSummary` + `VoucherRedemptionDirectory` (Phase 17); use
  cases `CreateVoucher`, `UpdateVoucher`, `SetVoucherEligibility`, `SetVoucherCurrencyDiscount`,
  `RemoveVoucherCurrencyDiscount`, `SetVoucherUsageLimits`, `ChangeVoucherStatus`,
  `ReserveVoucherRedemption`, `ConfirmVoucherRedemption`, `ReleaseVoucherRedemption`
  (Phase 17).
- Domain (Phase 17 additions): `RedemptionStatus` enum, `VoucherRedemption` aggregate,
  `VoucherRedemptionRepository` port; `VoucherRepository` gained `findByIdForUpdate` +
  `incrementRedeemedCount`.
- Infrastructure: `PdoVoucherRepository`, `PdoVoucherEligibilityRuleRepository`,
  `PdoVoucherCurrencyDiscountRepository`, `PdoVoucherDirectory`,
  `PdoVoucherRedemptionRepository` (implements both `VoucherRedemptionRepository` and
  `VoucherUsagePort`), `PdoVoucherRedemptionDirectory`, `definitions.php`.
- CLI: `bin/{CreateVoucher,UpdateVoucher,SetVoucherEligibility,SetVoucherCurrencyDiscount,
  RemoveVoucherCurrencyDiscount,SetVoucherUsageLimits,SetVoucherStatus,ListVouchers,
  ReserveVoucherRedemption,ConfirmVoucherRedemption,ReleaseVoucherRedemption,
  ListVoucherRedemptions}.php` → `composer voucher:*`.
- Seeder: `src/Database/Seeds/VouchersSeeder.php` — `WELCOME10` (10%, once per user) and `EU5`
  (`none` default, EUR/USD/GBP fixed overrides, `pro`-only).
- Tests: `tests/Unit/Modules/Vouchers/{Domain,Application}/*` — `VoucherTest`,
  `VoucherCurrencyDiscountTest`, `VoucherEligibilityEvaluatorTest` (the exit criterion — every
  dimension + the Phase 17 usage checks), `VoucherHandlersTest`, `VoucherRedemptionTest`,
  `VoucherDiscountCalculatorTest`, `VoucherRedemptionHandlersTest` (reserve/confirm/release,
  idempotency, cap exhaustion); `tests/Integration/VoucherRedemptionPersistenceTest.php`
  (real-MySQL round trip, CI-only).

## 11. Open questions / future work

- Phase 18: voucher decision snapshot on the payment/subscription record (will use
  `VoucherDiscountResult` / the confirmed `VoucherRedemption` row as its source).
- Phase 19: `POST /api/v1/vouchers/validate` endpoint (a non-locking pre-check via the same
  `VoucherEligibilityEvaluator`, without reserving).
- Phase 29: stale-`reserved`-row sweep (background job) — deferred by Phase 17 Q2.
- Not yet modelled: stacking / combinability with other vouchers (assume **one voucher per
  payment** until a phase says otherwise), auto-apply vs. code-entry, referral vouchers.
