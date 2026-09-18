# Deployment.md

**Purpose.** How Gomrok is deployed and released.

**Status:** no real production target, hosting provider, or pipeline has been chosen yet
(Phase 30B Q1: "prep everything, stop before real go-live"). `.claude/knowledge/DeploymentRunbook.md`
is now a full, concrete, step-by-step runbook (Phase 30B) — but it documents an **illustrative
single-host Linux example** (nginx + php-fpm + MySQL + systemd), clearly labeled as a reference,
not a firm commitment. When the real target is chosen, update that runbook's concrete steps
in-place (its own *Portability* section explains what changes for Docker/managed-platform/multi-
host targets) rather than writing a second document.

## Documented elsewhere

- Step-by-step deployment runbook (host prep, extensions, migrations, nginx/php-fpm, systemd
  worker, health checks, rollback, portability): `.claude/knowledge/DeploymentRunbook.md`.
- Client-facing API reference: `.claude/docs/ApiReference.md`.
- Staged Televika go-live checklist (client/provider/callback setup, controlled test-mode
  transaction, real cutover, rollback): `.claude/docs/GoLiveChecklist.md`.
- Ongoing monitoring/alerting checklist: `.claude/docs/MonitoringChecklist.md`.

## Current local setup

Local dev only, via `docker-compose.yml` (PHP 8.4 + MySQL 8.4). See `.claude/docs/Commands.md`.

## Background worker requirement (Phase 30A Q3)

`bin/Worker.php` (Phase 29 Q5's revision) is a **persistent daemon**, not a cron-invoked script —
it must be started once and then kept running continuously, whatever the eventual deployment
target turns out to be. Since no target is chosen yet (Q3, deliberately deferred rather than
guessing systemd vs. Docker vs. something else — see `.claude/PhaseResults/PhaseDecisions.md`),
this section states the *requirement* the eventual supervisor must satisfy, generically:

- **Auto-restart on crash.** If the process exits for any reason (an unhandled exception, an OOM
  kill, a host reboot), it must be restarted automatically and promptly — equivalent to systemd's
  `Restart=always`, supervisord's `autorestart=true`, or a container orchestrator's restart
  policy. Without this, once the daemon exits, `jobs` stops being processed entirely: no more
  webhook retries, no notification delivery, no reconciliation scans, nothing — silently, since
  there is no alerting on "the worker process itself is down" (as distinct from
  `Job`/`ProductionSafetyGuard`'s own health tracking, which only covers a *running* worker's
  individual job failures, Phase 29 Q5 revision).
- **Graceful shutdown already handled by the script itself** — `bin/Worker.php` traps
  SIGTERM/SIGINT and finishes its in-flight batch before exiting (Phase 29). The supervisor only
  needs to send a normal termination signal and wait a reasonable grace period before a harder
  kill; it does not need to implement its own graceful-shutdown logic.
- **Environment parity with the web process.** The daemon goes through the same
  `ContainerFactory::create()` bootstrap as the web app (including
  `ProductionSafetyGuard`, Phase 30A Q5) and needs the same environment variables (`DB_*`,
  `APP_ENCRYPTION_KEY`, etc.) — whatever mechanism supplies them to the web process must supply
  them to the worker process too.
- **One instance is sufficient today, but more than one is safe.** `PdoJobRepository::claimDue()`
  uses `SELECT ... FOR UPDATE SKIP LOCKED` (Phase 29), so running two or more `bin/Worker.php`
  processes concurrently — deliberately for redundancy, or accidentally during a rolling
  restart — cannot double-process the same job.
- **Logs to stdout/stderr** (Phase 29) — whatever mechanism captures the web process's logs
  (journald, a container log driver, etc.) should capture the worker's the same way; no separate
  log-shipping setup is needed beyond that.

Once a real target is chosen (Phase 30A Q3 revisited, or in Phase 30B), the concrete artifact —
a systemd unit file, a Dockerfile `CMD` + restart policy, or equivalent — is written here and in
`.claude/knowledge/DeploymentRunbook.md`, replacing this generic statement.
