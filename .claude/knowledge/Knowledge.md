# Knowledge

Domain knowledge about payments, providers, and edge cases learned while building Gomrok.
Not a plan and not a spec — durable facts and gotchas worth keeping.

## Providers

- **Ziraat Bank (Turkey)** is charge-only: bank-hosted payment page / 3D Secure redirect,
  return-URL + webhook/callback, and manual status polling. **No subscriptions, no auto-charge.**
  It exposes no product/registration API (`requires_registration = false`, `api_capable = false`).
- **Stripe / Mollie / PayPal** require a product/price object on their side before a package can
  be sold, and expose an API to create it. Gomrok tracks this per package per provider account
  as a sync state: `synced | not_created | drift | not_needed`.
- **Mollie** capabilities are payment-method-dependent — e.g. recurring may work for card but not
  for a given alternative method. Resolve allowed purchase types per (provider, method) pair, not
  per provider.
- Provider-specific statuses must be mapped to Gomrok's internal statuses; unknown ones are
  stored raw and flagged, never dropped.

## Money

- Currency scale varies: JPY = 0 decimals, USD/EUR = 2, BHD/KWD = 3. Never assume "×100".
  `brick/money` knows the scales; our `Money` VO delegates to it.
- Store integer minor units + ISO 4217 `CHAR(3)`. Never float.

## Pricing / vouchers

- A client never sends a price. Gomrok resolves package availability, price, provider, method,
  purchase type, and voucher validity server-side, then snapshots the decision on the
  payment/subscription so later rule changes don't rewrite history.
- Same package can legitimately cost different amounts by country, currency, provider, and
  method (gateway fees, taxes, market decisions) — e.g. cheaper in Turkey than the EU/US.
- Voucher redemption must be concurrency-safe and idempotent: a duplicate payment request or
  webhook retry must not redeem a voucher twice. Reserve → finalise on paid → release on
  failed/cancelled/expired.

## Identifiers

- Internal `id` (BIGINT) never leaves the DB boundary. Everything external (API, client
  callbacks, admin URLs) uses the row's `ulid`.

## Gotchas

- Webhooks: store the raw event **before** processing, verify signature, respond fast, process
  async. Duplicate webhook ids must be deduped.
- Never silently downgrade a requested purchase type (a subscription request that can't be
  satisfied is an error, not a one-time payment).
- Gomrok is greenfield — there is no "Dexter"/legacy system. Ignore any doc that says otherwise.
