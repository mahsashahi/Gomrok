# Orders.md — requirements & decisions register

**Purpose.** A single register of every requirement, decision, and business rule for Gomrok, with
date, source, status, and the related phase — the "why" behind the build.

**Relationship to other files.** The detailed phase-by-phase decision Q&A lives in
`.claude/PhaseDecisions.md` (options, recommendation, selection). This file is the higher-level
index: one row per requirement/decision, pointing at where it is specified. Do not duplicate the
full text — link to `CLAUDE.md`, `.claude/Rule.md`, `.claude/docs/Architecture.md`, or
`.claude/PhaseDecisions.md`.

## How to use

- Add a row whenever a requirement or decision is agreed (in conversation, a phase, or a spec).
- `Status`: proposed · accepted · implemented · superseded.
- Keep newest at the top of each section.

## Product & business requirements

| # | Requirement | Date | Source | Status | Phase | Reference |
|---|---|---|---|---|---|---|
| _(none recorded here yet — see `CLAUDE.md` for the product spec)_ | | | | | | |

## Architecture & technical decisions

| # | Decision | Date | Source | Status | Phase | Reference |
|---|---|---|---|---|---|---|
| D1 | Module-based hexagonal, per-module layers | 2026-09-06 | Phase 1 Q1 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D2 | Direct interface calls + in-process domain events | 2026-09-06 | Phase 1 Q2 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D3 | ~~BIGINT PK + public ULID~~ → **plain `INT AUTO_INCREMENT` IDs, no ULID/UUID/typed-ID classes** | 2026-09-06 → **changed 2026-09-08** | Phase 1 Q3 (user) | superseded → accepted | 1 / 3 | `.claude/PhaseDecisions.md`, `Architecture.md` §6 |
| D4 | Money via brick/money wrapped in a `Money` VO | 2026-09-06 | Phase 1 Q4 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D5 | Provider adapters: core port + optional capability interfaces | 2026-09-06 | Phase 1 Q5 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D6 | Docker Compose · PHP 8.4 · Phinx · PHPUnit 11 · PHPStan max | 2026-09-06 | Phase 2 Q1–Q5 | implemented | 2 | `.claude/PhaseDecisions.md` |
| D7 | `src/Bootstrap/` + `src/Http/` kept as app-level dirs | 2026-09-06 | Phase 2 Q6 | implemented | 2 | `.claude/PhaseDecisions.md` |
| D8 | Idempotency = lock + entity mapping (no stored response bodies); audit = full before/after row snapshots; error log = explicit writer only; idempotency TTL 24h + purge job; migration CI via GitHub Actions | 2026-09-08 | Phase 5 Q1–Q5 | implemented | 5 | `.claude/PhaseDecisions.md`, `Architecture.md` §11 |
| D9 | API key = prefixed token + `sha256(secret)` looked up by public `key_id`; client settings = typed columns + `client_endpoints` table; required immutable `slug`; soft reversible client disable (keys untouched); onboarding via CLI + `APP_ENV`-gated dev seeder | 2026-09-08 | Phase 6 Q1–Q5 | implemented | 6 | `.claude/PhaseDecisions.md` |
| D10 | Auth = `Authorization: Bearer` only; authenticated client in a `ClientContext` holder + request attributes; `last_used_at` written throttled (≤1/key/5min); `401` for any credential fault + `403 client_disabled`, generic bodies; `Idempotency-Key` required on `/api/v1` writes; failed/successful auth logged to `client_auth_attempts`; **no rate limiting yet** | 2026-09-08 | Phase 7 Q1–Q5 | implemented | 7 | `.claude/PhaseDecisions.md`, `Architecture.md` §11 |

## Workflow & process rules

| # | Rule | Date | Source | Status | Reference |
|---|---|---|---|---|---|
| P1 | PascalCase file/dir naming | 2026-09-06 | user | accepted | `.claude/Rule.md` §3.1 |
| P2 | All docs under `.claude/` | 2026-09-07 | user | accepted | `.claude/Rule.md` §3.3 |
| P3 | One-at-a-time decision questions → `PhaseDecisions.md` | 2026-09-06 | user | accepted | `.claude/Rule.md` §4.2 |
| P4 | Per-phase result file in `.claude/PhaseResults/` | 2026-09-06 | user | accepted | `.claude/Rule.md` §4.1 |
| P5 | `LastAiAnswer.md` single-slot response log | 2026-09-06 | user | accepted | `.claude/Rule.md` §1 |
