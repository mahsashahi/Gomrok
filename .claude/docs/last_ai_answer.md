# Q: Start the Ziraat adapter (Phase 23)

Did not build `ZiraatAdapter`. The user gave an explicit instruction to defer Ziraat integration
entirely, rejecting both options I'd proposed (a best-effort implementation of the publicly-known
"NestPay/estPos" bank-hosted POS pattern flagged as unverified, vs. a deliberately generic
placeholder stub). Recorded as Phase 23 Q1 in `PhaseResults/PhaseDecisions.md`.

## Why this came up

Ziraat is fundamentally different from Stripe/Mollie/PayPal: it's a Turkish bank's bank-hosted
payment page, already seeded as `api_capable: false` (Phase 8), with no modern REST/SDK API.
`Architecture.md` had already anticipated "a Ziraat stub until real credentials." I don't have
Ziraat's actual current merchant integration guide available, and guessing at real-world protocol
specifics (request field names, hash/signature algorithm, callback format) for a real bank's
proprietary gateway risked baking wrong technical claims into the codebase as if verified.

## What the user directed

- Do not build a best-effort Ziraat protocol now, and do not guess request fields,
  hash/signature format, callback format, or bank-hosted POS behavior.
- Do not create production-ready Ziraat code.
- Keep Ziraat listed as a future payment provider; add a placeholder/TODO only where needed (not
  new code files) — and it must explicitly say: "Ziraat integration is deferred until official
  documentation and credentials are available."
- The core provider adapter architecture must still support adding Ziraat later without changes.
- Must not block Stripe, PayPal, Mollie, or the generic adapter architecture (all already
  complete, Phases 21–22).
- No Ziraat-specific tests.
- Ziraat's existing capability/provider-type seed data (needed by country-routing since Phase
  8/10) stays marked planned/deferred, not implemented — not removed.

## What was actually done

No new adapter class, no protocol code, no new tests — by design. Only documentation-level changes:

- `src/Modules/Providers/Infrastructure/DefaultProviderAdapterFactory.php` — no behavior change (a
  `'ziraat'` account already fell through to `default` and threw `UnsupportedProviderType`); added
  a class-docblock note plus an inline comment on the `match` block with the required deferral
  sentence.
- `src/Database/Seeds/ProviderTypesSeeder.php` — added a matching docblock note; the seeded
  `ziraat` row is unchanged.
- `.claude/docs/Phases.md` — Phase 23 renamed "Ziraat adapter (deferred)" in the tracking table;
  its section rewritten to record the deferral, the original (unbuilt) scope, and why exit
  criteria aren't met.
- `.claude/docs/Architecture.md` §8 and §12 — the `ZiraatAdapter` forward-note and the "Ziraat
  stub" testing note both rewritten to describe the actual deferral.
- `.claude/knowledge/Knowledge.md` — new "Ziraat adapter — deferred" section.
- `.claude/Changelog.md` — new dated entry.
- `.claude/PhaseResults/PhaseDecisions.md` — new Phase 23 Q1, recording the user's direct
  instruction verbatim in intent (not a multiple-choice selection, since the user rejected the
  offered options).

## Verified

`composer ci` — CS clean, PHPStan clean, 458/458 tests still passing (unchanged, since no
behavior changed — only comments and docs).

## Not done / next

No `Phase23Result.md` was created — the phase's original goal (a working adapter) wasn't
delivered; it stays open/deferred rather than marked complete. Next candidate: Phase 24 (payment
creation flow), which does not depend on Ziraat.
