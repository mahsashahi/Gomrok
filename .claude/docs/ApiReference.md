# ApiReference.md

**Purpose.** Hand-written reference for every client-facing `/api/v1/*` endpoint (Phase 30B Q2 —
Markdown, matching this project's documentation style; no OpenAPI/Swagger). Written directly
from the current route table (`src/Config/routes.php`) and the real action classes under
`src/Http/Api/` — not from memory. If this document and the code ever disagree, the code wins;
flag the drift so this file gets corrected.

Not covered here: `/admin/*` (session-cookie-authenticated admin panel, not a client-facing API)
and `/health` (see `.claude/docs/Commands.md`).

---

## 1. Conventions

### Base URL

There is no fixed public base URL yet — no production host has been chosen (Phase 30B Q1). Every
example below uses `https://gomrok.example.com` as a placeholder.

### Authentication

Every `/api/v1/*` route requires an `Authorization: Bearer <api-key>` header. A Gomrok API key has
the shape:

```text
gk_<mode>_<key_id>.<secret>
```

- `<mode>` is `test` or `live` — the two are fully separate key spaces (Phase 7); a test-mode key
  never touches production-mode data or triggers real provider charges.
- `<key_id>` identifies the key row (used for rate-limiting and lookup); `<secret>` is the bearer
  credential, never logged or displayed again after issuance (`composer client:issue-key`, see
  `.claude/docs/Commands.md`).

A missing/malformed/unknown/revoked key returns `401` with `WWW-Authenticate: Bearer
realm="gomrok"`. A disabled client returns `403`. A key that has failed authentication 5 times
within a 15-minute window is locked out and returns `429` with a `Retry-After` header in seconds
(Phase 30A Q1) — this is deliberately indistinguishable from a wrong-secret `401` in its timing,
so a locked-out response never confirms whether the underlying credential itself was ever valid.

### Idempotency

Every write (`POST`) under `/api/v1` requires an `Idempotency-Key` header (Phase 7 Q5) — a missing
one is rejected with `400 idempotency_key_required`. The key must be 1–255 printable ASCII
characters.

- First use of a key: the request runs normally.
- Same key, same request body, retried: the original response is replayed (with an added
  `Idempotent-Replayed: true` header) — the operation is **not** repeated.
- Same key, a **different** request body: rejected with `422 idempotency_key_reuse`.
- Same key, while the original request is still being processed: rejected with
  `409 idempotent_request_in_progress`.

Keys are honored for 24 hours from first use. Read (`GET`) requests ignore this header entirely —
reads have no side effects to deduplicate.

Generate a fresh, unique `Idempotency-Key` per logical operation (e.g. a UUID) — reusing one
across genuinely different requests is a client bug, not something Gomrok can detect.

### Errors

Every error response is an [RFC 9457 Problem Details](https://www.rfc-editor.org/rfc/rfc9457)-shaped
JSON body:

```json
{
  "type": "about:blank",
  "title": "Package 'pkg_missing' was not found.",
  "status": 404,
  "code": "package.not_found"
}
```

`code` is the stable, machine-readable identifier — match on `code`, not `title` (title text may
be refined over time). Common codes: `*.not_found` (404), `*.validation`/`*_required` (400/422),
`unauthorized`/`invalid_credentials` (401), `forbidden` (403), `rate_limited` (429).

### Amounts

Every amount is expressed in the currency's minor unit (e.g. cents) as `amount_minor` (integer),
with a human-readable `amount` (decimal string) alongside where present. **Gomrok always resolves
the price itself** — nothing a client sends is ever treated as the final payable amount
(CLAUDE.md "Security Rules"); price-shaped body fields, if sent, are ignored.

### `{id}` in payment/subscription routes

`GET/POST /api/v1/payments/{id}*` and `GET/POST /api/v1/subscriptions/{id}*` all address the
**checkout attempt id** returned by `POST /api/v1/payments` / `POST /api/v1/subscriptions` — the
same id is used before and after the underlying `payments`/`subscriptions` row exists, so a client
only ever needs to remember one id per checkout.

---

## 2. Client identity

### `GET /api/v1/me`

Confirms a key is valid and shows the resolved client context. No secrets in the response.

**Response `200`:**

```json
{
  "id": 3,
  "slug": "televika",
  "name": "Televika",
  "status": "active",
  "default_currency": "USD",
  "default_country": "US",
  "timezone": "UTC",
  "key_mode": "test"
}
```

---

## 3. Packages

### `GET /api/v1/packages?country=<ISO2>[&method=<payment_method>][&device=<string>][&visitor_ref=<string>]`

Returns the resolved package catalogue for the requested market context — only packages actually
available/sellable there, each carrying its Gomrok-resolved price. `country` is required.

**Response `200`:**

```json
{
  "country": "DE",
  "currency": "EUR",
  "packages": [
    {
      "package": "pro_monthly",
      "name": "Pro (Monthly)",
      "badge": "Popular",
      "highlighted": true,
      "price": {
        "amount_minor": 2900,
        "amount": "29.00",
        "currency": "EUR",
        "source": "country_override",
        "pricing_group": "eu-standard",
        "applied_rule_id": 42,
        "applied_dimensions": {"country": "DE"}
      }
    }
  ]
}
```

### `GET /api/v1/packages/{packageId}?country=<ISO2>[&method=][&device=][&visitor_ref=]`

One package from the same resolved catalogue above — never a different pricing result than the
list endpoint would give for that package. `{packageId}` accepts either the numeric package id or
its `code`. A package that exists but isn't available in the requested context reports the same
`404 package.not_found_in_context` as one that doesn't exist at all.

**Response `200`:** the single package object shown inside `packages[]` above (same shape).

---

## 4. Pricing

### `GET /api/v1/pricing/resolve?package=<code>&country=<ISO2>[&device=][&method=][&purchase_type=][&interval=][&visitor_ref=]`

Resolves the price for one package in a full checkout context, without creating anything. A pure
read — never treats a client-supplied amount as authoritative.

Valid `purchase_type` values: `one_time_payment`, `recurring_payment`, `auto_charge`,
`subscription`. Valid `interval` values: `monthly`, `quarterly`, `yearly`. Valid `method` values:
`card`, `paypal`, `ideal`, `bancontact`, `sepa_direct_debit`, `bank_hosted_card`.

**Response `200`:**

```json
{
  "package": "pro_monthly",
  "name": "Pro (Monthly)",
  "badge": null,
  "highlighted": false,
  "price": {
    "amount_minor": 2900,
    "amount": "29.00",
    "currency": "EUR",
    "source": "default",
    "pricing_group": "eu-standard",
    "applied_rule_id": 17,
    "applied_dimensions": {"country": "DE", "purchase_type": "one_time_payment"}
  }
}
```

---

## 5. Vouchers

### `GET /api/v1/vouchers/validate?package=<code>&country=<ISO2>&code=<voucherCode>[&device=][&method=][&purchase_type=][&interval=][&client_user_ref=][&first_purchase=true]`

A non-locking eligibility + discount **preview** — validating a voucher here never reserves or
redeems it (redemption only becomes final on successful payment). Safe to call repeatedly while a
customer is entering a code.

**Response `200` (eligible):**

```json
{
  "eligible": true,
  "reasons": [],
  "voucher": {"code": "WELCOME10", "name": "Welcome 10%"},
  "price": {"amount_minor": 2900, "amount": "29.00", "currency": "EUR"},
  "discount": {"nominal_minor": 290, "applied_minor": 290, "payable_minor": 2610}
}
```

**Response `200` (not eligible)** — `discount` is omitted entirely, `reasons` explains why:

```json
{
  "eligible": false,
  "reasons": ["voucher.expired"],
  "voucher": {"code": "WELCOME10", "name": "Welcome 10%"},
  "price": {"amount_minor": 2900, "amount": "29.00", "currency": "EUR"}
}
```

---

## 6. Payments

### `POST /api/v1/payments`

Creates a checkout attempt end-to-end: resolves package/price/voucher, resolves provider/method,
creates the real provider-hosted checkout session, and returns its redirect URL. Requires
`Idempotency-Key`.

**Request body (JSON or form-encoded):**

| Field | Required | Notes |
|---|---|---|
| `attempt_reference` | yes | Client-chosen idempotency-adjacent reference for this checkout attempt (domain-level, independent of the `Idempotency-Key` header). |
| `package` | yes | Numeric package id or `code`. |
| `country` | yes | ISO 3166-1 alpha-2. |
| `currency` | yes | ISO 4217 alpha-3. |
| `client_user_ref` | no | The client's own user identifier — needed to later answer "which user owns this." |
| `purchase_type` | no | One of §4's values; defaults to what the resolved provider/context implies. |
| `payment_method` | no | One of §4's values. |
| `subscription_interval` | no | Only meaningful with `purchase_type=recurring_payment`. |
| `device` | no | Free-form device hint (used by provider/method resolution). |
| `voucher_code` | no | Validated and applied server-side; never trust a client-computed discount. |
| `first_purchase` | no | Boolean, informs voucher eligibility. |
| `customer_email` | no | Passed to the provider's hosted checkout where supported. |

**Response `201`:**

```json
{
  "checkout_attempt_id": 5001,
  "status": "pending_redirect",
  "redirect_url": "https://checkout.stripe.com/c/pay/cs_test_...",
  "provider_reference": "cs_test_a1b2c3"
}
```

Send the customer's browser to `redirect_url`. After they finish, the provider returns them to
Gomrok's own public `GET /payments/return` endpoint (§9), which then redirects onward to the
client's configured callback URL.

### `GET /api/v1/payments/{id}`

Plain read of stored state — before the checkout completes, reports the checkout attempt's own
status; once conversion happens, reports the real payment.

**Response `200` (before conversion):**

```json
{
  "checkout_attempt_id": 5001,
  "payment_id": null,
  "status": "pending_redirect",
  "country": "DE",
  "currency": "EUR",
  "amount_minor": null,
  "purchase_type": "one_time_payment",
  "payment_method": "card",
  "subscription_interval": null,
  "error_code": null,
  "error_message": null,
  "created_at": "2026-09-18T10:00:00+00:00",
  "updated_at": "2026-09-18T10:00:00+00:00"
}
```

**Response `200` (after conversion):**

```json
{
  "checkout_attempt_id": 5001,
  "payment_id": 8842,
  "status": "paid",
  "country": "DE",
  "currency": "EUR",
  "amount_minor": 2900,
  "purchase_type": "one_time_payment",
  "payment_method": "card",
  "subscription_interval": null,
  "error_code": null,
  "error_message": null,
  "created_at": "2026-09-18T10:00:00+00:00",
  "updated_at": "2026-09-18T10:04:12+00:00"
}
```

`status` is one of Gomrok's normalized internal payment statuses (CLAUDE.md "Payment Lifecycle"):
`created`, `pending`, `requires_action`, `authorized`, `paid`, `failed`, `canceled`, `expired`,
`refunded`, `partially_refunded`, `disputed`, `chargeback` — never a raw provider status.

### `GET /api/v1/payments/{id}/status`

Same shape as the relevant subset of `GET /api/v1/payments/{id}`, but for a non-terminal attempt
it **actively re-checks the real status with the provider first** rather than only reading what
Gomrok has stored — use this when you need the freshest possible answer (e.g. right after the
customer returns from checkout); use the plain `GET /api/v1/payments/{id}` when stored state is
good enough.

**Response `200`:**

```json
{"checkout_attempt_id": 5001, "status": "paid"}
```

### `POST /api/v1/payments/{id}/cancel`

Cancels a payment that hasn't settled yet. Requires `Idempotency-Key`. No request body.

**Response `200`:**

```json
{"checkout_attempt_id": 5001, "payment_id": 8842, "status": "canceled"}
```

### `POST /api/v1/payments/{id}/refund`

Refunds a paid payment, in full or partially. Requires `Idempotency-Key`.

**Request body:** `{"amount_minor": 1000}` — omit or send `null` for a full refund of the
remaining amount.

**Response `200`:**

```json
{
  "checkout_attempt_id": 5001,
  "payment_id": 8842,
  "status": "partially_refunded",
  "provider_reference": "re_1AbCdE",
  "refunded_minor": 1000
}
```

### `POST /api/v1/payments/{id}/capture`

Captures a previously authorized (not yet captured) payment, in full or partially. Requires
`Idempotency-Key`.

**Request body:** `{"amount_minor": 2900}` — omit or send `null` to capture the full authorized
amount.

**Response `200`:**

```json
{
  "checkout_attempt_id": 5001,
  "payment_id": 8842,
  "status": "paid",
  "provider_reference": "pi_1AbCdE"
}
```

---

## 7. Subscriptions

### `POST /api/v1/subscriptions`

The subscription counterpart to `POST /api/v1/payments`. Requires `Idempotency-Key`.

**Request body:** same fields as `POST /api/v1/payments`, plus **`client_user_ref` and
`subscription_interval` are both required** (not optional, unlike the one-time-payment endpoint —
a subscription is never created client-user-less or interval-less).

**Response `201`:** identical shape to `POST /api/v1/payments`'s `201` response.

### `GET /api/v1/subscriptions/{id}`

Mirrors `GET /api/v1/payments/{id}` exactly, before/after conversion.

**Response `200` (after conversion):**

```json
{
  "checkout_attempt_id": 6001,
  "subscription_id": 421,
  "client_user_ref": "user_789",
  "status": "active",
  "currency": "EUR",
  "amount_minor": 2900,
  "payment_method": "card",
  "subscription_interval": "monthly",
  "trial_ends_at": null,
  "current_period_start": "2026-09-18T10:04:12+00:00",
  "current_period_end": "2026-10-18T10:04:12+00:00",
  "error_code": null,
  "error_message": null,
  "created_at": "2026-09-18T10:00:00+00:00",
  "updated_at": "2026-09-18T10:04:12+00:00"
}
```

### `POST /api/v1/subscriptions/{id}/cancel`

Cancels an active subscription. Requires `Idempotency-Key`. No request body.

**Response `200`:**

```json
{"checkout_attempt_id": 6001, "subscription_id": 421, "status": "canceled"}
```

---

## 8. Webhooks (provider → Gomrok, not client-facing)

### `POST /api/v1/webhooks/{provider}/{token}`

**Public** — never called by a client application. Each provider account has its own opaque
`{token}` (issued via `composer provider-account:add-endpoint`); the provider's own request
signature is the real authentication, verified per-provider inside `IngestWebhookEventHandler`.
Always `200` once the event is stored and its signature verified — a downstream processing failure
is never surfaced to the provider (Gomrok's own `webhook:retry-pending` job handles retries).

**Response `200`:**

```json
{"webhook_event_id": 91234, "outcome": "processed"}
```

---

## 9. Customer-browser return (public, not an API call)

### `GET /payments/return?return_token=<id>_<hash>[&outcome=success|cancel]`

Not part of `/api/v1` and never called by client server code — this is the URL the customer's own
browser is redirected to by the payment provider after they leave the hosted checkout page. No API
key exists at this point. Gomrok re-verifies the real status with the provider (the `outcome` query
param is only a hint, never trusted alone), then 302-redirects the browser onward to the client's
configured callback URL. If no callback URL is configured, or the outcome is still genuinely
undetermined, it returns a small JSON status body instead of redirecting.

---

## 10. Worked example — a full one-time payment

```bash
# 1. See what's available in Germany
curl -s "https://gomrok.example.com/api/v1/packages?country=DE" \
  -H "Authorization: Bearer gk_test_abc123.secret..."

# 2. Optionally preview a voucher
curl -s "https://gomrok.example.com/api/v1/vouchers/validate?package=pro_monthly&country=DE&code=WELCOME10" \
  -H "Authorization: Bearer gk_test_abc123.secret..."

# 3. Create the checkout
curl -s -X POST "https://gomrok.example.com/api/v1/payments" \
  -H "Authorization: Bearer gk_test_abc123.secret..." \
  -H "Idempotency-Key: 5c1e2f0a-1c3b-4a2e-9c3d-8f1a2b3c4d5e" \
  -H "Content-Type: application/json" \
  -d '{"attempt_reference":"order-9001","package":"pro_monthly","country":"DE","currency":"EUR","voucher_code":"WELCOME10"}'
# -> { "checkout_attempt_id": 5001, "redirect_url": "https://checkout.stripe.com/...", ... }

# 4. Redirect the customer's browser to redirect_url. They complete payment there and land on
#    Gomrok's public /payments/return, which redirects them to the client's own callback URL.

# 5. Poll (or trust the client-notification webhook Gomrok sends — see below) for the final status
curl -s "https://gomrok.example.com/api/v1/payments/5001/status" \
  -H "Authorization: Bearer gk_test_abc123.secret..."
# -> { "checkout_attempt_id": 5001, "status": "paid" }
```

Gomrok also proactively notifies the client's own configured callback endpoint when a payment's
status changes (CLAUDE.md "Notify the correct client application when payment status changes") —
polling `GET /api/v1/payments/{id}/status` is a fallback, not the primary integration path. Client
notification delivery/retry configuration is set up per client via the admin panel or the
`provider-account`/client CLI tools (see `.claude/docs/Commands.md`); its retry semantics are
documented under `.claude/docs/Deployment.md` / `.claude/PhaseResults/Phase28Result.md`.
