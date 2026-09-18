# Q: Pre-Phase-29 gap review — what's incomplete, deferred, or partially implemented from earlier phases

I read `Phases.md`, all 27 `PhaseNNResult.md` files' Deferred Work sections, `PhaseDecisions.md`, `Architecture.md` §13 ("Deferred to later phases" — the project's own rolling tracker), `Changelog.md`, and grepped the codebase for TODO/FIXME/stub markers (found exactly one, on Ziraat — the codebase is otherwise clean of inline markers; everything else is tracked in docs). Here's the complete picture.

## Pre-Phase-29 Gap Review

### 1. Ziraat adapter
- **Phase:** 23 (still ☐, not started)
- **Missing:** the entire adapter — `DefaultProviderAdapterFactory` explicitly throws `UnsupportedProviderType` for `ziraat`.
- **Why deferred:** no verified sandbox credentials or official docs were available (Phase 23 Q1, user-confirmed).
- **Before Phase 29?** No — nothing in Phase 29's scope needs Ziraat.
- **Is Phase 29 the right phase?** No — it's explicitly its own phase, still waiting on external credentials.
- **Recommendation:** Leave deferred. Not a blocker.

### 2. Background sweep jobs — voucher reservations & checkout abandonment
- **Phase:** originated in 17 & 18, explicitly assigned to 29 both in `PhaseDecisions.md` and `Architecture.md` §13.
- **Missing:** a job that expires stale `reserved` voucher redemptions and marks stuck `checkout_attempts` `abandoned`/`expired`. The columns/state machine exist; nothing writes them on a schedule.
- **Why deferred:** named as background-jobs-phase work from the moment the columns were designed (Phase 17 Q2, Phase 18).
- **Before Phase 29?** No.
- **Is Phase 29 the right phase?** **Yes — this is core Phase 29 scope**, named explicitly in CLAUDE.md's Background Jobs list.
- **Recommendation:** Build in Phase 29.

### 3. Webhook-driven subscription renewal automation (incl. Mollie's renewal scheduler)
- **Phase:** originated in 25/26, explicitly assigned to 29 (`PhaseDecisions.md` Q3, `Architecture.md` §13).
- **Missing:** `ProcessWebhookEventHandler` doesn't resolve `Subscription`-typed gateway references or call `RecordSubscriptionPaymentHandler` automatically. The handler itself is built and fully tested — just not triggered. Also `mapProviderSubscriptionStatusToInternalStatus()` is still a provisional `string` pass-through, deliberately left unformalized until this automation is real.
- **Why deferred:** needs an adapter-layer change (`ParsedWebhookEvent` doesn't carry a subscription reference yet) and was judged premature to build on a provisional status mapping.
- **Before Phase 29?** No.
- **Is Phase 29 the right phase?** **Yes**, explicitly.
- **Recommendation:** Build in Phase 29 — this is arguably the single most important item on this list, since subscriptions can't actually self-renew without it.

### 4. Cancel/refund/capture on a renewal-originated payment
- **Phase:** surfaced in 26, no phase assigned yet (confirmed still true — I just checked `ResolvePaymentActionContext::forPayment()`, it still returns `null` when `checkoutAttemptId() === null`).
- **Missing:** a renewal-created `Payment` (no checkout attempt) can't be canceled/refunded/captured through the existing action-context resolver.
- **Why deferred:** resolving provider context via `subscription_payment_links` instead of a checkout attempt wasn't built at Phase 26.
- **Before Phase 29?** Not required, but worth deciding now since it's unassigned.
- **Is Phase 29 the right phase?** Arguably yes — it's adjacent to the renewal-automation work above and would be touched by the same code path.
- **Recommendation:** Fold into Phase 29 rather than leave unassigned indefinitely.

### 5. Dedicated `refunds` table
- **Phase:** flagged since 24, still unassigned.
- **Missing:** a refund's own provider-issued reference lives only in `provider_transactions.response_payload`, not its own row — `refunds` is on CLAUDE.md's Required Database Concepts list but was never proposed.
- **Why deferred:** needs its own DB-design confirmation; out of scope for every phase that's touched it so far.
- **Before Phase 29?** No — not blocking.
- **Is Phase 29 the right phase?** No — Phase 29 is jobs/reconciliation, not a new aggregate. This is really a small Payments-module addition.
- **Recommendation:** Decide separately (small phase or a Phase 30 hardening item), not Phase 29.

### 6. Notification delivery/retry
- **Phase:** 28 — **just completed.**
- **Status:** the pipeline itself is done (enqueue → sign → deliver → backoff → dead-letter → admin retry), verified end-to-end. Two residuals: (a) still cron+PHP, not a real queue — explicitly deferred to 29 in `Phase28Result.md`; (b) no admin UI to manage `provider_account_notification_overrides` (backend complete, untested only by absence of a screen).
- **Before Phase 29?** No.
- **Is Phase 29 the right phase?** (a) yes, exactly. (b) no — that's an admin-panel screen, not a jobs concern.
- **Recommendation:** (a) into Phase 29. (b) low priority, add opportunistically or note as a standing gap.

### 7. Admin panel placeholders/gaps (all from Phase 27, still true)
- **Settings screen** — deliberate neutral placeholder, no backing domain. Needs its own DB-design proposal if ever built. *Not Phase 29's job.*
- **Provider "create product via API"** — manual registration only, no adapter product-creation capability. *Not Phase 29.*
- **Price-list deletion**, **list-price edit-modal prefill** — minor UX gaps. *Not Phase 29.*
- **Provider endpoint (webhook URL) management UI** — belongs to Webhooks (25), not built. *Not Phase 29.*
- **`AdminUserStatus::Locked`** — unlock action exists, nothing ever sets locked. *Not Phase 29.*
- **Admin-attributed audit for provider-account creation** — always logs `forSystem`; handler has no `actorId` param. *Not Phase 29 — small Providers-module fix.*
- **Client callback-endpoint management UI** — `SetClientEndpointHandler`/`RemoveClientEndpointHandler` exist, no screen. Now more relevant given Phase 28 depends on these endpoints. *Worth considering, not core Phase 29.*
- **Recommendation:** None of these block Phase 29. The callback-endpoint UI is the one I'd bump in priority given Phase 28 just made those URLs operationally important — but it's still an admin-screen task, not jobs/reconciliation.

### 8. Admin "Jobs" screen
- **Phase:** implied by CLAUDE.md's Admin Panel Requirement ("Retrying failed jobs where safe") and Background Jobs section.
- **Missing:** `AdminPermission::JobsView`/`JobsRetry` exist in the enum (seeded since Phase 27) but **no screen or action implements them** — I checked, zero references outside the enum.
- **Why deferred:** there's been nothing to view/retry — no real job/queue system exists yet.
- **Before Phase 29?** No — can't build a jobs screen before jobs exist.
- **Is Phase 29 the right phase?** **Yes** — this is the natural companion UI once Phase 29 builds real jobs.
- **Recommendation:** Include in Phase 29's scope (or immediately after) — CLAUDE.md ties "background jobs" and "admin retry tools" together explicitly.

### 9. Rate limiting / failed-auth lockout
- **Phase:** flagged since 7, unassigned.
- **Missing:** `client_auth_attempts` is logged but nothing rate-limits or locks out based on it.
- **Why deferred:** explicitly "once there is real traffic and an admin view" (Phase 7 Q-decision).
- **Before Phase 29?** No.
- **Recommendation:** Phase 30 hardening territory, not Phase 29.

### 10. PayPal subscriptions (Billing Plans) & multi-partial-capture
- **Phase:** 22, unassigned follow-up.
- **Missing:** `PayPalAdapter` doesn't implement `SupportsSubscriptions` at all; multi-partial-capture against one authorization isn't implemented.
- **Before Phase 29?** No.
- **Recommendation:** Not Phase 29 — a provider-adapter deepening task, pick up whenever PayPal subscriptions become a real requirement.

### 11. `visitor_ref` not wired into `POST /api/v1/payments`
- **Phase:** 24, deliberately scoped out (Q7 named exactly two endpoints).
- **Before Phase 29?** No. **Recommendation:** leave as-is until a real client needs it.

### 12. Documentation hygiene: `Architecture.md` §13 is stale
- **Finding (mine, not previously flagged anywhere):** §13 "Deferred to later phases" was last updated through Phase 26 — it has no entry reflecting Phase 27's or Phase 28's own deferred items (the admin-panel gaps, the notification-pipeline residuals). This is the project's canonical rolling deferred-items tracker per its own description, and it's out of sync with `PhaseResults/`.
- **Before Phase 29?** Cosmetic, not functional — your call, but cheap to fix and worth doing before it drifts further.
- **Recommendation:** I can update it in a few minutes if you want it current before Phase 29 starts.

### 13. Dev-only implementations that shouldn't reach production
- Cron via `bin/*.php` scripts instead of a real queue (Webhooks, and now Notifications) — **this is exactly Phase 29's queue-technology decision**, already tracked.
- `php -S` dev server / no nginx+php-fpm — flagged since Phase 2, explicitly Phase 30 (deploy target).
- Fixed `local-dev` client + dev API token, `tools/screenshots/seed-demo-data.php` — these are env-gated (`APP_ENV` local/testing only) by design, not a production risk as-is, but worth a Phase 30 checklist item to confirm they're excluded from any deploy.
- **Recommendation:** all already correctly tracked to Phase 29 (queue) or Phase 30 (deploy hardening) — no new action needed.

---

## A. Must finish before Phase 29
**None.** Everything I found is either already correctly scoped to Phase 29 itself, or genuinely belongs to a different phase (27, 30, or unassigned-but-not-blocking). I found nothing that Phase 29 depends on that isn't already done.

## B. Belongs inside Phase 29
- Voucher stale-reservation sweep + checkout-attempt abandonment/expiry sweep
- Webhook-driven subscription renewal automation (incl. formalizing `mapProviderSubscriptionStatusToInternalStatus`, Mollie's renewal scheduler)
- Real queue/worker technology decision (retiring the `bin/*.php` cron pattern used by Webhooks and Notifications)
- Admin "Jobs" screen (the permissions already exist, waiting for something to view/retry)
- Cancel/refund/capture on a renewal-originated payment (adjacent to the renewal-automation work; currently unassigned — I'd fold it in here rather than leave it homeless)

## C. Intentionally deferred beyond Phase 29
- Ziraat adapter (Phase 23, blocked on external credentials)
- Dedicated `refunds` table (needs its own DB-design confirmation)
- Rate limiting / failed-auth lockout (Phase 30)
- PayPal subscriptions + multi-partial-capture
- `visitor_ref` in payment creation
- All the Phase 27 admin-panel gaps (Settings domain, provider product-creation, price-list deletion/edit-prefill, provider endpoint UI, admin-attributed provider-account audit, client callback-endpoint UI)
- Notifications override-URL admin UI
- nginx/php-fpm production entrypoint, encryption-key rotation, CI hardening (Phase 30)

## D. No action needed / already complete
- Everything else on the original 30-phase list through Phase 28 — the module-by-module build (Clients through Notifications) is complete and tested, with no undocumented gaps beyond what's listed above.
- `Architecture.md` §13 staleness — flagged for your awareness, not a functional gap; I'll fix it now if you'd like, or leave it for whenever's convenient.

**My recommendation:** proceed to Phase 29 as planned. Nothing found here should block it — the items in category B are, appropriately, exactly what Phase 29 already exists to do; I'd just make sure its scope explicitly includes the renewal-automation and cancel/refund/capture-on-renewal work (currently a little scattered across Phase 26/29 notes) and the admin Jobs screen, rather than only the two sweep jobs CLAUDE.md names outright.

Want me to fold "cancel/refund/capture on renewal-originated payments" and the admin Jobs screen into Phase 29's scope explicitly, and/or refresh `Architecture.md` §13, before we start the phase's 5 questions?
