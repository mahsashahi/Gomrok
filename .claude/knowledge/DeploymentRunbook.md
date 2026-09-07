# DeploymentRunbook.md

**Purpose.** Step-by-step runbook for deploying and rolling back Gomrok.

**Status:** not yet written — no deployment target chosen. Do not invent hosts, credentials, or
steps. Populate during `.claude/docs/Phases.md` → Phase 30.

## To contain (when defined)

1. Pre-deploy checks (tests green, migrations reviewed, changelog updated).
2. Deploy steps (exact commands, in order).
3. Run database migrations (`composer migrate`).
4. Post-deploy smoke checks (`GET /health`, a test payment in test mode).
5. Rollback steps.
6. Who to contact / where alerts go.

See also `.claude/docs/Deployment.md`.
