# ProjectDescription.md

**Purpose.** A short, plain-language description of what Gomrok is and does — the orientation a
newcomer reads first.

**Do not duplicate the spec.** The authoritative product description is the project-root
`CLAUDE.md` (→ *Project Overview*, *Main Responsibilities*). Keep this file to a paragraph or two
and link there.

## Summary

Gomrok is a standalone, multi-client **payment orchestration service**. Client applications never
talk to payment providers directly — Gomrok receives payment/subscription requests, routes them
to the right provider (Stripe, PayPal, Mollie, Ziraat Bank Turkey, …), handles webhooks, and
normalises everything into its own internal statuses.

See:

- `CLAUDE.md` → *Project Overview*, *Main Responsibilities* — the full spec.
- `.claude/docs/Architecture.md` — how it is built.
- `.claude/docs/Phases.md` — the build plan.
