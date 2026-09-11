# Voucher.md — the source of truth for voucher behaviour

Every voucher-related rule, decision, assumption, constraint, schema behaviour, eligibility
rule, discount rule, usage-limit rule, and implementation note for Gomrok lives here. If it is
about vouchers and it matters, it is written here — **before or alongside** the code/migration
that implements it (`CLAUDE.md` → *Voucher Rules File* rule).

Related files: `CLAUDE.md` → *Voucher Requirement*; `.claude/docs/database-design.md` /
`database-diagram.md` / `db_explain.md` (voucher tables); `.claude/PhaseResults/PhaseDecisions.md`
(Phase 16 Q1–Q5, Phase 17); `.claude/Orders.md` (D19+); `.claude/docs/Phases.md` (Phases 16–18).

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

> Phase 16 builds `vouchers`, `voucher_eligibility_rules`, `voucher_currency_discounts`.
> `voucher_redemptions` is **Phase 17**. Voucher decision snapshots are **Phase 18**.

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

The **subtraction itself** (applying the % / amount, honouring `max_discount_minor`, clamping to
the price, rounding HALF_EVEN via `Money`) is **Phase 17**. Phase 16 only decides *whether* a
discount exists for `X`.

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
| First purchase only | `first_purchase_only = 1` and the context says this is not the client user's first purchase | `voucher.not_first_purchase` |
| Global usage | `max_total_redemptions` set and `redeemed_count >= max_total_redemptions` | `voucher.exhausted` |

**Deferred to Phase 17** (need `voucher_redemptions`): per-user cap (`max_per_user`), per-client
cap (`max_per_client`), and making the global check concurrency-safe at redemption time. The
evaluator exposes a seam (`VoucherUsagePort`, no implementation in Phase 16) so Phase 17 can add
the per-user / per-client checks without reworking it.

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

`redeemed_count` (global tally) is a `vouchers` column, default `0`, incremented **only by Phase
17** inside the redemption transaction. Per-user / per-client actual counts are `COUNT(*)` over
`voucher_redemptions` (Phase 17), grouped by `client_user_ref` / `client_id`.

## 7. Redemption lifecycle (Phase 17 — recorded here for completeness)

- Redemption is **idempotent and concurrency-safe**: a duplicate payment request, webhook
  retry, or client retry must never redeem a voucher twice (keyed by the payment's idempotency
  key / a `voucher_redemptions` unique constraint — to be decided in Phase 17).
- Redemption becomes **final only after a successful payment**. A failed / canceled / expired
  payment attempt must not permanently consume usage. Phase 17 decides whether this uses a
  reservation (pending → confirmed/released) or a "count only on success" model.
- `redeemed_count` and any per-user/per-client tallies move together with the redemption record
  inside one transaction.

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

## 10. Implementation pointers (Phase 16 — as built)

`src/Modules/Vouchers/{Domain,Application,Infrastructure}`:

- Domain: `Voucher` (aggregate), `VoucherCurrencyDiscount` (VO + `validate()`),
  `VoucherEligibilityRule` (VO), `VoucherStatus` / `DefaultDiscountType` / `DiscountType` /
  `VoucherEligibilityDimension` enums, `VoucherRepository` / `VoucherEligibilityRuleRepository` /
  `VoucherCurrencyDiscountRepository` ports.
- Application: `VoucherContext`, `VoucherEligibility`, `VoucherUsagePort` (declared, no
  implementation), `VoucherEligibilityEvaluator`, `VoucherAuditSnapshot`, `VoucherSummary` +
  `VoucherDirectory`; use cases `CreateVoucher`, `UpdateVoucher`, `SetVoucherEligibility`,
  `SetVoucherCurrencyDiscount`, `RemoveVoucherCurrencyDiscount`, `SetVoucherUsageLimits`,
  `ChangeVoucherStatus`.
- Infrastructure: `PdoVoucherRepository`, `PdoVoucherEligibilityRuleRepository`,
  `PdoVoucherCurrencyDiscountRepository`, `PdoVoucherDirectory`, `definitions.php`.
- CLI: `bin/{CreateVoucher,UpdateVoucher,SetVoucherEligibility,SetVoucherCurrencyDiscount,
  RemoveVoucherCurrencyDiscount,SetVoucherUsageLimits,SetVoucherStatus,ListVouchers}.php` →
  `composer voucher:*`.
- Seeder: `src/Database/Seeds/VouchersSeeder.php` — `WELCOME10` (10%, once per user) and `EU5`
  (`none` default, EUR/USD/GBP fixed overrides, `pro`-only).
- Tests: `tests/Unit/Modules/Vouchers/{Domain,Application}/*` — `VoucherTest`,
  `VoucherCurrencyDiscountTest`, `VoucherEligibilityEvaluatorTest` (the exit criterion —
  precedence + every dimension), `VoucherHandlersTest`.

## 11. Open questions / future work

- Phase 17: discount calculation, `voucher_redemptions`, redemption lifecycle, per-user /
  per-client enforcement (implement `VoucherUsagePort`), concurrency.
- Phase 18: voucher decision snapshot on the payment/subscription record.
- Phase 19: `POST /api/v1/vouchers/validate` endpoint.
- Not yet modelled: stacking / combinability with other vouchers (assume **one voucher per
  payment** until a phase says otherwise), auto-apply vs. code-entry, referral vouchers.
