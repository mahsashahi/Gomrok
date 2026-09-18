# DeploymentSkill

**Status:** step-by-step content now lives in `.claude/knowledge/DeploymentRunbook.md` (Phase 30B)
and `.claude/docs/GoLiveChecklist.md` — this file stays a short pointer/checklist rather than
duplicating them.

> `.claude/struct.md` models skills as flat how-to guides. If you later want any of these to be an
> *invocable* Claude Code skill, it needs the folder form `skills/<name>/SKILL.md` instead.

## When this applies

- Deploying an application-code change to a running Gomrok host.
- Onboarding a new client to real production (Televika first — see `GoLiveChecklist.md`).

## Rules / constraints

- Follow `CLAUDE.md`, `.claude/Rule.md`, and `.claude/docs/Architecture.md`.
- Never commit real secrets; `.env` stays host-local and `chmod 600`.
- `ProductionSafetyGuard` (Phase 30A Q5) refuses to boot outside `local`/`testing` with debug mode
  on, the default checkout-return-token secret, or a missing encryption key — a boot failure here
  is the guard working, not a bug to route around.

## Steps / pattern

1. Follow `.claude/knowledge/DeploymentRunbook.md` §1–§11 (host prep through starting services).
2. Verify with §12–§17 (health/application/worker verification, deployment verification).
3. Watch per `.claude/docs/MonitoringChecklist.md` for the post-deploy window (§18 of the runbook).
4. Roll back via the runbook's §16 if verification fails.
5. For onboarding a new client end-to-end (not just a code deploy), use
   `.claude/docs/GoLiveChecklist.md` instead — client/provider/callback configuration, a controlled
   test-mode transaction, then real cutover.

## Checklist

- [ ] Deployment runbook's §17 "Deployment verification" checklist passes.
- [ ] No new unresolved `/admin/error-logs` entries attributable to this deploy.
- [ ] No new "alerting" badge on `/admin/jobs` attributable to this deploy.

## References

- `.claude/knowledge/DeploymentRunbook.md` — the full step-by-step runbook.
- `.claude/docs/ApiReference.md` — client-facing API surface.
- `.claude/docs/GoLiveChecklist.md` — Televika onboarding to real production.
- `.claude/docs/MonitoringChecklist.md` — ongoing observability.
- `.claude/PhaseResults/Phase30AResult.md` / `Phase30BResult.md`.
