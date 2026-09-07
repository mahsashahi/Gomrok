# Domain.md

**Purpose.** The Gomrok domain model — the core concepts and how they relate.

**Reference, don't duplicate.** The domain vocabulary and model are defined in:

- `CLAUDE.md` → *Terminology*, *Package Catalog / Pricing / Voucher / Provider / Subscription*
  requirements — the authoritative definitions.
- `.claude/docs/Architecture.md` §3 (module map), §8 (providers & capabilities), §9 (resolution
  pipelines), §10 (payment lifecycle).
- `.claude/knowledge/Knowledge.md` — durable domain facts and gotchas.

## Core concepts (index)

Client · Provider type · Provider account · Provider group · Package · Pricing group · Price list
· Voucher · Payment · Payment attempt · Provider transaction · Customer (unified identity) ·
Subscription · Gateway reference · Webhook event.

Each is specified in `CLAUDE.md`; this file is an index and a place for diagrams / worked
examples once modules are built (Phase 6 onward).
