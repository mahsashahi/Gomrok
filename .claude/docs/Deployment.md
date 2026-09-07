# Deployment.md

**Purpose.** How Gomrok is deployed and released.

**Status:** not yet defined. No deployment target, hosting provider, or pipeline has been chosen.
Do not invent one here — fill this in during the phase that addresses deployment (see
`.claude/docs/Phases.md` → Phase 30, *Hardening, docs & first-client go-live*).

## To document (when decided)

- Target environment(s) and how they are provisioned.
- Build & release steps (from `composer ci` / `ci:full` to a running deployment).
- Configuration & secrets handling per environment (never commit secrets).
- Database migration step in the release (`composer migrate` / Phinx).
- Rollback procedure.
- Smoke checks after deploy (at minimum `GET /health`).

## Current local setup

Local dev only, via `docker-compose.yml` (PHP 8.4 + MySQL 8.4). See `.claude/docs/Commands.md`.
