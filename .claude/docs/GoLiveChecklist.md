# GoLiveChecklist.md

**Purpose.** The staged, step-by-step checklist for taking Televika from "code is ready" to
"transacting successfully in real production" — Phase 30's overall exit criterion (CLAUDE.md:
"Televika must successfully transact in production before Phase 30 is considered complete").

**Status.** This document is **prepared but not executed** (Phase 30B Q1: "prep everything, stop
before real go-live"). No real Televika client record, real provider credentials, or real
transaction exists yet — every command below is real, taken from `.claude/docs/Commands.md`, but
none has been run against production. Per Phase 30B Q4, this checklist explicitly **replaces**
running a controlled test-mode transaction walkthrough during Phase 30B itself — the validation
happens here, later, by a human actually working through this list against a real host and real
provider credentials.

**Precondition.** Phase 30A (production hardening) must be complete and confirmed before any step
below runs against a real production host — see `.claude/PhaseResults/Phase30AResult.md`.

Each step names the exact command/action, what to verify, and who/what can supply the missing
input (a real hosting target, real provider credentials — none of which exist as of this writing).

---

## Stage 0 — Pre-flight

- [ ] A real production host exists and `.claude/knowledge/DeploymentRunbook.md`'s steps have been
      followed on it (or the equivalent for whatever the real target turns out to be — see that
      document's *Portability* section) through §17 "Deployment verification."
- [ ] `GET https://<real-host>/health` returns `200 {"status":"ok","service":"gomrok"}`.
- [ ] `gomrok-worker` (or its equivalent) is running and its most recent poll succeeded
      (`.claude/knowledge/DeploymentRunbook.md` §14).
- [ ] `.env` on the real host has `APP_ENV=production`, `APP_DEBUG=false`, a real
      `APP_ENCRYPTION_KEY`, and a real non-default checkout-return-token secret — confirmed
      indirectly by the app actually booting (`ProductionSafetyGuard`, Phase 30A Q5, refuses to
      start otherwise).
- [ ] Real base reference data is seeded (`vendor/bin/phinx seed:run` — the plain, unfiltered
      form; safe in production since every demo-data seeder is `APP_ENV`-gated to a no-op —
      `.claude/knowledge/DeploymentRunbook.md` §6).
- [ ] The specific countries/currencies Televika needs are present in `countries`/`currencies` —
      check against the curated list in `src/Database/Seeds/data/countries.json`; add any missing
      market before continuing (a new country requires its own confirmed schema/seed change,
      per the project's Database Design Confirmation Rule if the curated list needs extending).

## Stage 1 — Televika client configuration

Run once, against the real production database:

```bash
composer client:create -- --slug=televika --name="Televika" --currency=<TELEVIKA_DEFAULT_CURRENCY> --country=<TELEVIKA_DEFAULT_COUNTRY>
composer client:issue-key -- --client=televika --label="production-live"
composer client:issue-key -- --client=televika --label="production-test" --test
```

- [ ] Both keys are captured **immediately** (printed once, never retrievable again) and stored in
      the real secret manager Televika's own backend will read from — never in a chat log, ticket,
      or plaintext file.
- [ ] `composer client:list` shows the new client with the expected `slug`/`currency`/`country`,
      status `active`.
- [ ] `GET /api/v1/me` with the new live-mode key returns the expected client identity (§2 of
      `.claude/docs/ApiReference.md`).

## Stage 2 — Provider account configuration

For each real payment provider Televika will use in production (Stripe / Mollie / PayPal /
Ziraat — whichever were actually contracted):

```bash
composer provider-account:create -- --client=televika --provider-type=<stripe|mollie|paypal|ziraat> --mode=live \
    --name="Televika <Provider> Live" --secret-key=<REAL_LIVE_SECRET> [--public-key=<REAL_PUBLIC_KEY>] \
    [--country=<ISO2> ...] [--method=<method> ...]
composer provider-account:add-endpoint -- --client=televika --account=<slug> --kind=webhook [--signing-secret=<REAL_WEBHOOK_SECRET>]
```

- [ ] The real live secret key/credential came from Televika's own provider dashboard (Stripe/
      Mollie/PayPal/Ziraat), not a test/sandbox value.
- [ ] `composer provider-account:list -- --client=televika` confirms the account exists with
      `mode=live` and the secret is never displayed in plaintext (CLAUDE.md: "No sensitive
      provider credentials or API secrets should be displayed in plain text").
- [ ] The webhook endpoint's real URL (`https://<real-host>/api/v1/webhooks/<provider>/<token>`,
      §8 of `.claude/docs/ApiReference.md`) is registered inside the **provider's own dashboard**
      (Stripe/Mollie/PayPal/Ziraat each require this manually — Gomrok cannot self-register a
      webhook endpoint with a provider it doesn't already have an authenticated session with).
- [ ] A test webhook delivery from the provider's own dashboard (most providers offer a "send test
      event" button) reaches Gomrok and is visible at `/admin/notifications` /
      `webhook_events` — confirms network reachability and signature verification before any real
      money moves.
- [ ] Repeat Stage 1's provider-account steps once more with `--mode=test` accounts if Televika
      wants test-mode transactions to keep working in production infra after go-live (useful for
      Televika's own pre-release QA against the real host without real charges).

## Stage 3 — Country → provider routing

```bash
composer provider-group:create -- --client=televika --name="<Market>" [--slug=...] [--default] [--currency=...]
composer provider-group:configure -- --client=televika --group=<slug> --country=<ISO2> --purchase-type=<type> [--method=...] [--currency=...]
composer provider-group:set-accounts -- --client=televika --group=<slug> --account=<real-live-account-slug>
```

- [ ] Every market Televika actually sells in has a configured group (or is covered by the
      `is_default` group) pointing at the real `--mode=live` provider account(s) from Stage 2 —
      not a leftover test account.
- [ ] Purchase types configured match what was actually contracted with each provider — do not
      enable `subscription`/`auto_charge` for a provider/market combination that wasn't verified
      to support it (CLAUDE.md: "Gomrok must reject unsupported combinations instead of silently
      changing the requested purchase type" — this is a routing *design* correctness check, not
      something Gomrok's runtime rejection logic can catch if the operator configures it wrong).

## Stage 4 — Packages, pricing, vouchers

```bash
composer package:create / package:set-availability / package:set-capabilities / package:set-country-capabilities / package:link-provider
composer pricing:create-group / pricing:set-default-price / pricing:set-rate / pricing:set-rule
composer voucher:create / voucher:set-eligibility / voucher:set-limits   # only if Televika launches with vouchers active
```

- [ ] Every package Televika will sell exists, with `package:set-capabilities` rows (purchase
      types are fail-closed — CLAUDE.md/`Commands.md`) and real default prices in the real
      currency/currencies.
- [ ] `GET /api/v1/packages?country=<ISO2>` against the real host, using the production-live key,
      returns exactly the catalogue Televika expects — resolved price included.
- [ ] `GET /api/v1/pricing/resolve?...` spot-checked for at least one package per market against
      the price Televika's own product team actually approved.
- [ ] Any launch vouchers are configured and `GET /api/v1/vouchers/validate` previews the expected
      discount.

## Stage 5 — Client notification (callback) configuration

- [ ] Televika's real production callback endpoint URL is configured for the client (per-client
      notification/callback configuration — see `.claude/PhaseResults/Phase28Result.md` for the
      exact mechanism) and is reachable over HTTPS from the Gomrok host.
- [ ] A manual notification retry (`/admin/notifications` → retry, Phase 28) has been exercised at
      least once against a deliberately-unreachable test URL to confirm the dead-letter + retry
      path behaves as expected before relying on it for real traffic.

## Stage 6 — Controlled test-mode transaction (do this first, before any real money moves)

Using the **`production-test`** key issued in Stage 1 (test mode — no real charge, but the same
real host/database/worker as production) and a **test-mode** provider account (Stage 2's
`--mode=test` variant, or the provider's own sandbox credentials):

1. `POST /api/v1/payments` — create a real checkout attempt (§6 of `ApiReference.md`).
2. Complete the provider's hosted checkout page with a test card/account.
3. Confirm the browser lands on `/payments/return` and is redirected to Televika's real callback
   URL with the expected outcome.
4. `GET /api/v1/payments/{id}/status` confirms `paid`.
5. Confirm Televika's own backend actually received and correctly processed the client
   notification (Stage 5) for this payment.
6. Repeat for a refund (`POST /api/v1/payments/{id}/refund`) and, if Televika uses subscriptions,
   a full subscription create → cancel cycle (§7 of `ApiReference.md`).
7. Confirm `/admin/audit-logs`, `/admin/error-logs`, and `/admin/jobs` show nothing unexpected for
   this end-to-end run.

**Do not proceed to Stage 7 until every item in Stage 6 passes.**

## Stage 7 — Cutover to real transactions

- [ ] Switch Televika's own backend/frontend integration from the `production-test` key to the
      real `production-live` key issued in Stage 1.
- [ ] Confirm Televika's integration is pointed at the real production base URL, not a staging/dev
      one.
- [ ] Agree an explicit go-live time with Televika's team so both sides are watching at once.

## Stage 8 — First real transaction

- [ ] Execute one real, small, real-money transaction (ideally an internal/test purchase Televika
      itself makes, refunded immediately afterward) through the real live provider account.
- [ ] Confirm the real charge appears in the provider's own dashboard (Stripe/Mollie/PayPal/Ziraat)
      matching the amount Gomrok resolved.
- [ ] Confirm `GET /api/v1/payments/{id}/status` reports `paid`, the client notification arrived,
      and `/admin/sales` reflects the transaction.
- [ ] Refund it (`POST /api/v1/payments/{id}/refund`) and confirm the refund reflects correctly
      both in Gomrok and in the provider's dashboard.

## Stage 9 — Post-go-live validation

- [ ] Watch `/admin/error-logs`, `/admin/jobs`, and `/admin/reconciliation` closely for the first
      24–48 hours of real traffic — see `.claude/docs/MonitoringChecklist.md`.
- [ ] Confirm real webhook events are arriving and processing without manual intervention.
- [ ] Confirm client notifications to Televika are succeeding on first attempt for the large
      majority of events (a nonzero retry rate is normal; a high one is not).
- [ ] Hold a short retro with Televika's team within the first week to catch anything the
      checklist above didn't anticipate.

Once every stage above has actually been executed with a real result recorded, Phase 30 (both
30A and 30B) is complete per CLAUDE.md's stated exit criterion. **Executing Stages 1, 2, and
6–9 requires real production infrastructure and real provider credentials that do not exist at
the time this checklist was written** — they are business/operational inputs, not something to be
generated or guessed.

---

## Rollback (emergency, client-specific — see also `.claude/knowledge/DeploymentRunbook.md` §16
for an application-code rollback)

If something goes wrong with Televika's live configuration specifically (not the application
code itself):

1. **Disable the client immediately** — `/admin/clients` → set Televika's status to `disabled`
   (or `composer client:list` to find the id, then the equivalent admin action). A disabled
   client's every `/api/v1` request is rejected `403` (`AuthenticationMiddleware`) — this stops
   all further activity without touching the database or any other client.
2. **Revoke the compromised/misbehaving key** — `composer client:revoke-key -- --key-id=<key_id>`,
   or `/admin/clients` → revoke. Issue a fresh key (Stage 1) once the underlying issue is fixed.
3. If a specific provider account is the problem (wrong credentials, provider outage), remove it
   from the relevant `provider_group` (`provider-group:set-accounts` without it) rather than
   disabling the whole client — this degrades gracefully to any other configured provider instead
   of stopping all transactions.
4. Re-enable the client (`status=active`) only after the root cause is fixed and, if a real
   transaction was affected, after confirming its state (paid/refunded/failed) is correct in both
   Gomrok and the provider's own dashboard.
5. Record the incident (what broke, what was rolled back, what fixed it) — an `audit_logs`
   trail already exists for the admin actions themselves; a short written incident note is
   additionally useful for the post-go-live retro (Stage 9).
