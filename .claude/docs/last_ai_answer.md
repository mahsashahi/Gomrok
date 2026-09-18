# Q: Start Phase 28 — Client callbacks / outbound notifications

Phase 28 is complete: Gomrok now tells clients when their payments or subscriptions change status
— reliably, signed, retried, and visible/retryable from the admin panel.

## What was built

**Domain-event dispatcher, finally wired.** `Architecture.md` §5 sketched
`Shared\Application\DomainEventDispatcher` back at Phase 6 and explicitly left it unbuilt "until
the first subscriber." Notifications is that subscriber. New `PaymentStatusChanged` /
`SubscriptionStatusChanged` events are raised — only on a genuine status change, never a
same-status no-op — by the four handlers that transition status
(`RecordProviderTransactionHandler`, `ChangePaymentStatusHandler`, `CancelSubscriptionHandler`,
`RecordSubscriptionPaymentHandler`), dispatched synchronously after each one's transaction
commits.

**The Notifications module** (`src/Modules/Notifications/`): a `ClientNotification` aggregate,
curated notify-worthy status lists, exponential backoff, endpoint resolution (client default +
provider-account override), HMAC signing, an enqueue path that does no network I/O, a separate
delivery handler that does, and a manual-retry handler.

**New tables:** `client_notification_logs` (the delivery record) and
`provider_account_notification_overrides` (the per-account override). Documented in
`database-design.md` / `database-diagram.md` (+ `.html`) / `db_explain.md`.

**New job:** `RetryPendingClientNotifications` (cron-invokable, same shape as Phase 25's webhook
retry, deferring a real queue to Phase 29).

**New admin screen:** `/admin/notifications` — the Phase 27 sidebar link and permissions existed
already but pointed nowhere until now.

## The 5 decisions (all recorded in PhaseDecisions.md)

- **Q1 — callback model:** user-specified hybrid — client-scoped default (`client_endpoints`,
  Phase 6) + an optional per-provider-account override, not either of the two options offered.
- **Q2 — trigger:** wire the domain-event dispatcher (recommended option).
- **Q3 — signing:** timestamped HMAC-SHA256, Stripe-style (recommended option).
- **Q4 — backoff:** exponential, 8 attempts, 1m→24h (recommended option).
- **Q5 — notify-worthy statuses:** user-specified curated lists (payments: paid/failed/canceled/
  expired/refunded/partially_refunded/disputed/chargeback; subscriptions: active/past_due/
  cancelled) — overriding the simpler "notify on everything" default that was recommended, plus
  explicit idempotency/framing constraints that shaped the whole implementation.

## Verification — real, not just unit tests

A one-off script drove a real payment through the real container-wired handler to `paid`,
confirmed a `client_notification_logs` row was enqueued, ran the real cron job against a local
HTTP receiver, and the receiver captured a correctly-signed request — the HMAC was recomputed by
hand and matched exactly. The admin screen was screenshotted showing `sent`/`dead_lettered`/
`pending` rows; a real `POST /admin/notifications/{id}/retry` against the dead-lettered row was
executed for real and re-screenshotted showing it moved to `pending` with a fresh backoff
schedule (attempt count reset, not incremented).

`composer test:all` → 794 tests, 3039 assertions, 0 errors, 0 failures, 3 expected skips (up from
755 before this phase). `composer stan` → no errors.

## Known limitations, deferred

- Still cron + plain PHP, not a real queue — Phase 29's explicit job.
- No admin UI to manage `provider_account_notification_overrides` (the domain/repository layer is
  complete and tested; just no screen yet).
- Phase 27's `admin_users`/`admin_sessions`/`admin_login_attempts` tables were discovered to have
  never been added to the DB docs — flagged, not fixed (not this phase's scope).

Full detail: `.claude/PhaseResults/Phase28Result.md`.

**Next recommended phase:** Phase 29 — Background jobs, reconciliation & observability (the real
queue/worker system this phase's cron jobs, and Phase 25's, are both waiting for).
