# FileIndex.md — Gomrok

A map of the files worth knowing about. Keep in sync when files are added / moved / removed
(`.claude/Rule.md` §7).

## Governance & docs (`.claude/`)

| Path | What |
|---|---|
| `CLAUDE.md` (project root) | Entry-point instructions / detailed project spec. |
| `.claude/Rule.md` | Consolidated standing-rules catalogue. |
| `.claude/Changelog.md` | Chronological change log (newest first). |
| `.claude/PhaseDecisions.md` | Every phase decision question, options, and selection. |
| `.claude/FileIndex.md` | This file. |
| `.claude/docs/Architecture.md` | Phase-1 architecture baseline. |
| `.claude/docs/Phases.md` | 30-phase plan + Status & execution tracking table (at the top). |
| `.claude/docs/Commands.md` | Everyday commands. |
| `.claude/docs/LastAiAnswer.md` | Single-slot buffer: most recent substantive answer. |
| `.claude/docs/ClaudeOld.md` | Superseded spec archive — do not follow. |
| `.claude/knowledge/Knowledge.md` | Durable domain knowledge / gotchas. |
| `.claude/agents/`, `.claude/commands/`, `.claude/skills/` | Empty skeletons (see each `Readme.md`). |
| `PhaseResults/PhaseNNResult.md` | Per-phase record of what actually happened. |
| `PhaseResults/{Readme,Template}.md` | Phase-result convention + template. |
| `Design/` | Mirrored Claude Design admin-panel exports (`Design/Readme.md`). |

## Source (`src/`, PSR-4 `Gomrok\`)

| Path | What |
|---|---|
| `src/Public/index.php` | Front controller (web root). |
| `src/Bootstrap/AppFactory.php` | Composition root — builds the DI container + Slim app. |
| `src/Config/Settings.php`, `DatabaseSettings.php` | Env-driven immutable settings. |
| `src/Config/container.php` | PHP-DI definitions. |
| `src/Config/routes.php` | Route registration. |
| `src/Http/HealthAction.php` | `GET /health` (app-level, no module). |
| `src/Database/Migrations/`, `src/Database/Seeds/` | Phinx migrations / seeders (empty until Phase 4). |
| `src/Modules/<Name>/` | Business modules (created per phase from Phase 6 on). |
| `src/Shared/` | Domain kernel — Money, Ulid, Clock, Result, logger, events (Phase 3). |
| `src/Jobs/` | Queue worker (Phase 29). |

## Tests (`tests/`, PSR-4 `Gomrok\Tests\`)

| Path | What |
|---|---|
| `tests/Unit/` | Domain + application tests, no DB / network. |
| `tests/Integration/` | Adapter / repository / provider-sandbox tests (need MySQL). |

## Config & toolchain (project root)

| Path | What |
|---|---|
| `composer.json` / `composer.lock` | Dependencies + scripts (`test`, `stan`, `cs`, `ci`, `migrate`, …). |
| `phpunit.xml` | Test suites (`unit`, `integration`). |
| `phpstan.neon` | Static analysis — level `max` + strict rules. |
| `.php-cs-fixer.dist.php` | Code style — `@PSR12` + extras. |
| `phinx.php` | Migration runner config. |
| `Dockerfile`, `docker-compose.yml` | PHP 8.4 + MySQL 8.4 dev stack. |
| `.env.example` | Copy to `.env`. |
