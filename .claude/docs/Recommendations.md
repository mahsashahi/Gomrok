# Recommendations.md — post-phase recommendations log

**Purpose.** After each phase, record the recommendations, follow-ups, and "next time" notes that
came out of it — things worth doing that were **not** part of that phase's scope.

**Relationship.** `.claude/PhaseResults/PhaseNNResult.md` records what *happened* (incl. a
*Deferred Work* section). This file is the cross-phase rollup of open recommendations so they
don't get lost. Newest first.

## Phase 2 — Project scaffold & toolchain

- Run `DatabaseConnectionTest` against a real MySQL once Docker Desktop is available
  (`docker compose up -d mysql && composer test:integration`).
- Add a CI pipeline file (GitHub Actions or similar) wrapping `composer ci` / `ci:full` once a
  remote exists.
- Consider nginx + php-fpm for a production-like `app` service (currently `php -S`).

## Phase 1 — Groundwork: architecture baseline

- Revisit phase duration estimates after a few coding phases give real data.
- Decide the DB-docs file naming (`DatabaseDesign.md` vs the inherited spec's kebab-case) in
  Phase 4.

_(Add a section per phase as phases complete. Source: each `PhaseNNResult.md` → Deferred Work.)_
