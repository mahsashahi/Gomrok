# Phase 30A — Production hardening

## Execution Summary

- Phase: 30A — Production hardening (first of Phase 30's two sub-phases; see Phase 30 Q1 in
  `.claude/PhaseResults/PhaseDecisions.md` for the split)
- Start Datetime: 2026-09-18 13:05
- End Datetime: 2026-09-18 14:03
- Estimated Duration: 3–5h
- Actual Duration: 58m
- Tokens Used: N/A
- Final Status: Complete

## Work Completed

- **Q1 — Client API rate limiting / failed-auth lockout by `key_id`.** Mirrors admin login's
  existing fixed-window lockout exactly (5 failed attempts / 15 minutes). A locked-out `key_id`
  is rejected with `429 Too Many Requests` + `Retry-After: 900`, deliberately distinct from `401`
  so the response never confirms or denies the credential's own validity. `client_auth_attempts`
  already had everything needed (it was explicitly built "for any future rate limiter") — no new
  migration.
- **Q5 — Automated production-safety boot-time guard.** `ProductionSafetyGuard::check()` runs
  once from `ContainerFactory::create()` — the one bootstrap path the web app, `bin/Worker.php`,
  and every other `bin/*.php` script all already share — and refuses to boot outside
  `local`/`testing` if `APP_DEBUG` is on, the checkout-return-token secret is still the hardcoded
  development default, or `APP_ENCRYPTION_KEY` is unset.
- **Q4 — Manual security pass with targeted tests**, across all six CLAUDE.md-named areas:
  webhook signatures (verified already covered end-to-end, no gap), replay protection (webhook
  dedup verified covered; idempotency-under-concurrency proven for real — see Q2), price/package
  manipulation (2 new tests), multi-client isolation (15 new tests closing a real gap across 11
  files, via a delegated audit — see Problems/Resolutions), secret storage (`SecretRedactor`
  gained 4 new tests alongside its pre-existing 3; admin UI's secret-masking verified structurally
  never carries the full secret past `secretLastFour`).
- **Q2 — Lightweight PHP concurrent-request load-test scripts**, run for real against the local
  dev MySQL (not mocks, not an in-memory double): `tools/loadtest/ConcurrentIdempotencyClaim.php`
  and `tools/loadtest/ConcurrentVoucherRedemption.php`, both built on a shared
  `tools/loadtest/ConcurrencyWorker.php` CLI worker spawned via `proc_open` so each attempt is a
  genuinely separate process/PDO connection. **The idempotency load test found a real,
  previously-unknown production bug** (see Problems/Resolutions) before this phase existed to
  catch it.
- **Q3 — Documented the `bin/Worker.php` process-supervisor requirement generically** in
  `.claude/docs/Deployment.md` (auto-restart, environment parity, safe multi-instance,
  stdout/stderr logging) without committing to systemd/Docker/anything else, since no real
  deployment target has been chosen yet.
- Updated `.claude/docs/Phases.md`, `.claude/Rule.md`, `.claude/FileIndex.md`,
  `.claude/knowledge/DeploymentRunbook.md`, `.claude/skills/DeploymentSkill.md`, and
  `.claude/PhaseResults/Readme.md` for the Phase 30 → 30A/30B split (done *before* any
  implementation, per the user's explicit instruction when confirming the split).

## Files Created

**Client API lockout (Q1):** no new files — extended existing `AuthFailureReason`, `AuthResult`,
`AuthAttemptLog`, `PdoAuthAttemptLog`, `ApiKeyAuthenticator`, `AuthenticationMiddleware`.

**Production-safety guard (Q5):**
- `src/Bootstrap/ProductionSafetyGuard.php`
- `src/Bootstrap/ProductionSafetyViolation.php`
- `tests/Unit/Bootstrap/ProductionSafetyGuardTest.php`

**Load-test scripts (Q2):**
- `tools/loadtest/ConcurrencyWorker.php` (shared CLI worker, spawned by the two orchestrators)
- `tools/loadtest/ConcurrentIdempotencyClaim.php`
- `tools/loadtest/ConcurrentVoucherRedemption.php`

## Files Modified

**Q1 — client lockout:**
- `src/Modules/Clients/Domain/AuthFailureReason.php` — added `TooManyAttempts`.
- `src/Shared/Http/AuthResult.php` — added `retryAfterSeconds`, `tooManyAttempts()` factory (429).
- `src/Shared/Http/AuthenticationMiddleware.php` — sets `Retry-After` when present; docblock updated.
- `src/Modules/Clients/Application/Authenticate/AuthAttemptLog.php` — added `countFailedSince()`.
- `src/Modules/Clients/Infrastructure/PdoAuthAttemptLog.php` — implemented `countFailedSince()`.
- `src/Modules/Clients/Application/Authenticate/ApiKeyAuthenticator.php` — lockout check before
  the `key_id` lookup; `MAX_FAILED_ATTEMPTS`/`LOCKOUT_WINDOW_MINUTES` constants.
- `tests/Support/RecordingAuthAttemptLog.php` — implements `countFailedSince()` (tracks a
  parallel `recordedAt` array alongside the existing `attempts` list).
- `tests/Unit/Modules/Clients/Application/ApiKeyAuthenticatorTest.php` — 4 new tests.

**Q5 — production-safety guard:**
- `src/Bootstrap/ContainerFactory.php` — resolves `Settings` and calls
  `ProductionSafetyGuard::check()` right after building the container.

**Q4 — security pass:**
- `tests/Unit/Http/PaymentsCreateActionTest.php` — 2 new tests (client-supplied price field
  ignored; a real package owned by another client is not-found even by numeric id) + promoted
  `$pricingSnapshots` to a class property to assert on it.
- `tests/Unit/Shared/Infrastructure/SecretRedactorTest.php` — 4 new tests, merged alongside the
  3 pre-existing ones (see Problems/Resolutions — this file was accidentally overwritten and
  had to be repaired).
- 11 files closing the multi-client isolation gap (via the delegated audit — full list in
  Problems/Resolutions): `tests/Unit/Modules/Payments/Application/CreatePaymentHandlerTest.php`,
  `RecordProviderTransactionHandlerTest.php`; `tests/Unit/Modules/Subscriptions/Application/CancelSubscriptionHandlerTest.php`,
  `CreateSubscriptionHandlerTest.php`, `RecordSubscriptionPaymentHandlerTest.php`;
  `tests/Unit/Modules/Checkout/Application/CheckoutAttemptHandlersTest.php`,
  `CreateProviderCheckoutHandlerTest.php`, `CreateProviderSubscriptionHandlerTest.php`;
  `tests/Unit/Modules/Vouchers/Application/VoucherHandlersTest.php`,
  `VoucherRedemptionHandlersTest.php`; `tests/Unit/Modules/Pricing/Application/PriceResolverTest.php`.

**Q2 — the idempotency concurrency bug fix:**
- `src/Shared/Infrastructure/Persistence/PdoIdempotencyStore.php` — `isUniqueViolation()`
  renamed/broadened to `isLostInsertRace()`, now also recognizing MySQL 1213 (deadlock) and 1205
  (lock wait timeout) as "lost the race," not just 1062 (duplicate key).

**Documentation (the Phase 30 → 30A/30B split, done before implementation):**
- `.claude/docs/Phases.md`, `.claude/Rule.md`, `.claude/FileIndex.md`, `.claude/docs/Deployment.md`
  (also carries Q3's supervisor-requirement documentation), `.claude/knowledge/DeploymentRunbook.md`,
  `.claude/skills/DeploymentSkill.md`, `.claude/PhaseResults/Readme.md`,
  `.claude/PhaseResults/PhaseDecisions.md`.

## Implementation Details

**Client lockout (Q1).** `ApiKeyAuthenticator::authenticate()` parses the bearer token first
(so a malformed token still gets a generic response), then — before ever touching
`ClientApiKeyRepository` — checks `AuthAttemptLog::countFailedSince($parsed->keyId, $since)`. If
at or above 5 within the last 15 minutes, it records *this* attempt as a `TooManyAttempts`
failure too (so an attacker who keeps hitting a locked-out key stays locked out rather than the
window quietly expiring underneath them — the same self-perpetuating behavior
`AuthenticateAdminHandler`'s admin-login lockout already relies on) and returns
`AuthResult::tooManyAttempts(900)`. `AuthenticationMiddleware` sets `Retry-After: 900` whenever
`retryAfterSeconds` is non-null, generically — not special-cased to 429 only.

**Production-safety guard (Q5).** `ProductionSafetyGuard::check(Settings $settings)` is a no-op
for `local`/`testing` (`.env` and `phpunit.xml` both set these) and otherwise throws
`ProductionSafetyViolation` (collecting *every* violation found, not just the first) when
`APP_DEBUG` is on, `checkoutReturnTokenSecret === 'gomrokimo'` (the hardcoded Phase 24 Q4
placeholder — a real value was never wired to an env var), or `APP_ENCRYPTION_KEY` is unset.
Called from `ContainerFactory::create()` — the single choke point every entrypoint already shares
— rather than duplicated into `AppFactory` and every `bin/*.php` script separately.

**Load-test scripts (Q2).** `ConcurrencyWorker.php` is a tiny CLI dispatcher with two modes
(`idempotency-claim`, `voucher-reserve`); each invocation builds its own real container/PDO
connection via `ContainerFactory::create()` and performs exactly one claim/reservation attempt,
printing a single machine-readable result line. The orchestrators (`ConcurrentIdempotencyClaim.php`,
`ConcurrentVoucherRedemption.php`) spawn N of these via `proc_open()` in a tight loop (genuinely
separate OS processes, not threads or coroutines — real concurrent MySQL connections), collect
each one's stdout, and assert on the aggregate outcome (exactly one winner, zero unexpected
errors). Both were run for real against the local dev database multiple times (documented below)
rather than only described.

## Database Changes

No database changes. The one existing table this phase relies on most heavily —
`client_auth_attempts` — already had every column and index Q1's lockout needed (it was built in
Phase 7 explicitly "for any future rate limiter").

## API Changes

- `POST /api/v1/*` (any authenticated route) — a `key_id` locked out from repeated failed
  attempts now returns `429 Too Many Requests` with a `Retry-After: 900` header and a generic
  `{"code": "rate_limited", ...}` body, instead of continuing to return `401` forever. No route
  signature changed; this only affects the authentication-failure path.
- No other API changes. `bin/Worker.php`, `ContainerFactory::create()`, and every CLI script now
  refuse to run outside `local`/`testing` if the environment looks unsafe — not a route change,
  but worth noting as an operational behavior change.

## Tests and Validation

**Tests created:**
- `tests/Unit/Bootstrap/ProductionSafetyGuardTest.php` — 8 tests.
- 4 new tests in `ApiKeyAuthenticatorTest.php` (lockout after 5 failures even with the correct
  secret on the 6th attempt; lockout clears once the window passes; lockout is per-`key_id`, not
  global; being hit while locked out keeps refreshing the lockout).
- 2 new tests in `PaymentsCreateActionTest.php` (client-supplied price field ignored; a real
  package owned by another client is not-found by numeric id).
- 7 tests in `SecretRedactorTest.php` (3 original, kept; 4 new — every named sensitive key
  case-insensitively, non-sensitive keys untouched, recursion through nested arrays, no
  content-based matching).
- 15 new tests closing the multi-client isolation gap across 11 files (delegated to a fork —
  full breakdown in Problems/Resolutions).

**Tests actually executed:**
```
vendor/bin/phpstan analyse
vendor/bin/phpunit
php tools/loadtest/ConcurrentIdempotencyClaim.php 20   (and again at 30, ×3)
php tools/loadtest/ConcurrentVoucherRedemption.php --run=<id> 20   (and again at 40)
```

**Final results (real, captured output):**
- `vendor/bin/phpstan analyse` → `[OK] No errors` (1188 files analysed).
- `vendor/bin/phpunit` → `Tests: 921, Assertions: 3461, Skipped: 3.` `OK, but some tests were
  skipped!` (same 3 pre-existing Stripe/Mollie/PayPal live-credential skips, unrelated). Before
  this phase the suite stood at 905 tests (before Q1) / 918 (after the isolation-audit fork
  landed); +3 net from repairing `SecretRedactorTest.php` back to 7 total.
- `ConcurrentIdempotencyClaim.php` **before** the fix (20 processes): `won=1 lost=14 errors=5` —
  5 worker processes crashed with an uncaught `PDOException: SQLSTATE[40001]: Deadlock found`.
  **After** the fix, run 4 times at 20–30 concurrent processes each: every run
  `won=1 errors=0` (e.g. `won=1 lost=29 errors=0 elapsed=0.56s`).
- `ConcurrentVoucherRedemption.php` — 20 processes: `won=1 lost=19 errors=0`; 40 processes:
  `won=1 lost=39 errors=0`. No bug found in the voucher path (its `FOR UPDATE` row lock on an
  *existing* row never hits the insert-deadlock failure mode the idempotency path did).
- Real HTTP evidence for Q1, captured against the running local dev server with a real issued API
  key: 5 requests with the wrong secret → `401` each; the 6th, with the *correct* secret →
  `429` with `Retry-After: 900` and body
  `{"type":"about:blank","title":"Too many failed authentication attempts. Try again later.","status":429,"code":"rate_limited"}`;
  `client_auth_attempts` rows confirmed the exact expected sequence (5× `invalid_secret`, then
  `too_many_attempts`). The evidence key was revoked and its attempt rows deleted afterward.
- Real evidence for Q5: `APP_ENV=production APP_DEBUG=true php -r '...ContainerFactory::create()...'`
  threw `ProductionSafetyViolation` listing the `APP_DEBUG` and hardcoded-secret violations (the
  encryption-key violation did not fire because the real local `.env` does have one set — the
  guard correctly read the actual process environment, not a synthetic fixture). The running dev
  server (real `APP_ENV=local`) was confirmed unaffected (`GET /admin/login` still `200`).

Run with: `composer test` (unit only), `composer test:integration`, `composer test:all`. The
load-test scripts are dev-only, run manually — `php tools/loadtest/ConcurrentIdempotencyClaim.php
[concurrency]` — and double as a regression check for the deadlock fix; the voucher one needs a
throwaway voucher set up first via `bin/CreateVoucher.php` + `bin/SetVoucherUsageLimits.php`
(see the script's own docblock for the exact commands).

## Technical Decisions

Full record with all options/tradeoffs in `.claude/PhaseResults/PhaseDecisions.md`. Summary:

- **Phase 30 Q1 — split into 30A/30B** (user-specified, not the recommended "full breadth, one
  pass"). Extensively detailed by the user, including exact 30A/30B scope lists and a
  documentation-first requirement, honored before any implementation began.
- **30A Q1 — lock by `key_id`** (recommended, selected) over locking by IP or both.
- **30A Q2 — lightweight PHP scripts, no new tooling** (recommended, selected) for load/soak
  testing, over a real load-testing tool or a design-review-only pass.
- **30A Q3 — defer the concrete process-supervisor file, document generically** (user selected
  this over the recommended "assume systemd and write a real unit file"), since no real
  deployment target is chosen yet.
- **30A Q4 — manual review + targeted tests** (recommended, selected) for the security pass, over
  adding automated tooling (`composer audit`, a security-focused static-analysis ruleset) on top.
- **30A Q5 — automated boot-time guard** (recommended, selected) over a manual pre-deploy
  checklist item, for verifying dev-only helpers can't leak into production.

## Problems Encountered

- **A real, previously-unknown concurrency bug in `PdoIdempotencyStore::claim()`.** The Q2 load
  test (20 real concurrent processes racing to INSERT the same unique `(client_id,
  idempotency_key)`) crashed 5 of 20 workers with an uncaught `PDOException:
  SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction`. The
  existing `isUniqueViolation()` catch only recognized MySQL error 1062 (clean duplicate-key
  violation); under genuine concurrent load, InnoDB's gap-locking during a unique-index insert
  can *also* produce a deadlock (1213) or, separately, a lock-wait-timeout (1205) — neither was
  caught, so the exception propagated uncaught.
- **Overwrote a pre-existing test file without reading it first.** `Write`ing
  `tests/Unit/Shared/Infrastructure/SecretRedactorTest.php` (assumed new, since no dedicated
  redactor test was found in an earlier grep) silently replaced an already-existing file
  (3 tests, committed in an earlier phase) instead of erroring per the tool's own "must Read an
  existing file first" contract. Caught via `git status`/`git diff` while assembling this file
  list, not by the tool itself.
- **Two bugs in my own load-test orchestrator scripts**, both self-caught before relying on their
  output: (1) `proc_open()`'s `$pipes` output array is keyed by the *descriptor* keys I specified
  (`1`, `2` for stdout/stderr), not 0-indexed — `[$stdout, $stderr] = $pipes` threw "undefined
  array key 0"; (2) the voucher script's tear-down initially referenced a `voucher_usage_limits`
  table that doesn't exist — usage limits are plain columns on `vouchers` itself.
- **The voucher load test initially failed with `voucher.not_eligible` for every worker** — the
  throwaway voucher was created via `bin/CreateVoucher.php` without `--default-type`, which
  defaults to `'none'` ("no default discount, needs a per-currency override" — a real, if
  unrelated, eligibility rule working exactly as designed, not a bug).

## Resolutions

- Renamed `isUniqueViolation()` to `isLostInsertRace()` and broadened it to recognize 1062, 1213,
  and 1205 as all meaning "another concurrent transaction won this insert race, already
  committed, go re-read its row" — verified by re-running the exact load test that found the bug
  (4 runs, 20–30 concurrent processes each, 0 errors every time afterward).
- Read the file, then merged: restored the 3 original tests under their original names alongside
  the 4 new ones (7 total, all passing) rather than silently keeping only the replacement content.
  Filed product feedback about the Write tool not enforcing its own read-before-overwrite
  contract.
- Fixed the `proc_open()` pipe-array indexing to use the actual descriptor keys (`$pipes[1]`,
  `$pipes[2]`); fixed the tear-down SQL to delete from `voucher_decision_snapshots` /
  `voucher_redemptions` / `vouchers` directly (no separate limits table).
- Recreated the throwaway voucher with `--default-type=percentage --default-percent-bp=1000`;
  confirmed the retry succeeded.
- **Delegated the multi-client isolation audit to a subagent fork** rather than doing it
  personally: 34 handlers across the codebase have a `clientId() !== ` ownership check; most of
  their existing tests only proved "a nonexistent id is not found," not the more
  security-relevant "a *real* resource owned by a *different* client is not found." The fork
  audited all 34, found 19 already correctly tested (using a real-resource-wrong-client fixture),
  closed the gap for 15 by adding one new test each (or one extra assertion where a test file
  already bundled several handlers), found **zero production-code bugs** — every ownership check
  was already correct, just undertested — and flagged 7 Pricing-config handlers + 2 read-only
  Admin screen handlers as lower-priority and not yet touched (all already gated behind admin
  auth). 81 tests across its 11 touched files passed before landing; the full suite (921 tests)
  was re-verified green after merging its work with mine.

## Deferred Work

- **7 Pricing handlers and 2 read-only Admin screen handlers** still only test the
  "nonexistent id" case for isolation, not "real resource, wrong client" — flagged by the
  isolation-audit fork as the same gap pattern, deprioritized since they're already gated behind
  admin authentication (a lower actual risk than the client-API-reachable handlers this phase
  prioritized). A natural follow-up, not blocking.
- **The concrete process-supervisor artifact for `bin/Worker.php`** (a systemd unit file, a
  Dockerfile, or equivalent) — 30A Q3 deliberately deferred this; only the generic requirement is
  documented in `Deployment.md`. Written once a real deployment target is chosen, in 30A Q3
  revisited or in Phase 30B.
- **No client-facing route exists yet for the deadlock-fix's retry behavior to be exercised
  through the full HTTP stack under load** — the load test drives `PdoIdempotencyStore` and
  `ReserveVoucherRedemptionHandler` directly (by design, per 30A Q2's decision — this is more
  precise than going through a real Stripe/Mollie/PayPal test-mode API call, which this dev
  environment's placeholder credentials can't actually complete). A full end-to-end HTTP-level
  concurrency test remains future work if a real provider test credential is ever configured
  here.
- **The 7 Pricing/2 Admin isolation gaps** and any further security-pass depth (e.g. the
  automated-tooling option — `composer audit`, a security-focused static-analysis ruleset — that
  30A Q4 explicitly declined this pass) are candidates for a future hardening pass, not committed
  to any specific phase.

## Final Result

Client API authentication now has real brute-force protection (locked by `key_id`, mirroring
admin login's proven pattern), matching `429`/`Retry-After` semantics. Every entrypoint —
web app, daemon, every CLI script — refuses to boot with an unsafe production configuration
instead of silently running with debug mode on, a public hardcoded secret, or no encryption key.
The security pass closed 15 real (if low-severity — production code was already correct)
multi-client isolation test gaps across the codebase, added targeted tests proving price/package
manipulation is structurally impossible on the payment-creation path, and extended secret-redaction
test coverage. Most notably, the load-testing work this phase's Q2 called for **found and fixed a
real, previously-latent concurrency bug** in the idempotency-key claim path — one that would have
surfaced in production as an occasional 500 error under real simultaneous duplicate requests,
exactly the scenario idempotency keys exist to handle safely. 921 tests pass (16 net new this
phase, after repairing an accidental test-file overwrite), `phpstan analyse` is clean, and every
change is backed by real captured evidence — HTTP responses, database rows, audit-log entries, and
multiple real concurrent-process load-test runs — not just descriptions. Phase 30A's own exit
criterion ("the technical/security/load surface is production-ready and verified with captured
evidence") is met. Per the Phase 30 Q1 sequencing rule, **Phase 30B may now begin** — though the
real Televika production go-live inside 30B still requires real provider credentials and business
decisions well outside this phase's scope.
