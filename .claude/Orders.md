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
| D3 | BIGINT PK + public ULID | 2026-09-06 | Phase 1 Q3 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D4 | Money via brick/money wrapped in a `Money` VO | 2026-09-06 | Phase 1 Q4 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D5 | Provider adapters: core port + optional capability interfaces | 2026-09-06 | Phase 1 Q5 | accepted | 1 | `.claude/PhaseDecisions.md` |
| D6 | Docker Compose · PHP 8.4 · Phinx · PHPUnit 11 · PHPStan max | 2026-09-06 | Phase 2 Q1–Q5 | implemented | 2 | `.claude/PhaseDecisions.md` |
| D7 | `src/Bootstrap/` + `src/Http/` kept as app-level dirs | 2026-09-06 | Phase 2 Q6 | implemented | 2 | `.claude/PhaseDecisions.md` |

## Workflow & process rules

| # | Rule | Date | Source | Status | Reference |
|---|---|---|---|---|---|
| P1 | PascalCase file/dir naming | 2026-09-06 | user | accepted | `.claude/Rule.md` §3.1 |
| P2 | All docs under `.claude/` | 2026-09-07 | user | accepted | `.claude/Rule.md` §3.3 |
| P3 | One-at-a-time decision questions → `PhaseDecisions.md` | 2026-09-06 | user | accepted | `.claude/Rule.md` §4.2 |
| P4 | Per-phase result file in `.claude/PhaseResults/` | 2026-09-06 | user | accepted | `.claude/Rule.md` §4.1 |
| P5 | `LastAiAnswer.md` single-slot response log | 2026-09-06 | user | accepted | `.claude/Rule.md` §1 |
