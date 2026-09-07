# Changelog

All meaningful changes to Gomrok. Newest first. Each entry: date, summary, files changed,
reason, migration notes (if any), breaking changes (if any).

(Doc file locations have moved twice. Original: project root. 2026-09-06: `Documents/`.
2026-09-07: `.claude/` (this file is now `.claude/Changelog.md`). Older entries name the paths
that were correct when written.)

## 2026-09-07 — `.claude/` structure completed from `struct.md`

**Summary.** Built out the full `.claude/` tree described in `.claude/struct.md`, keeping every
existing file and its content untouched. `struct.md`'s `SCREAMING_CASE`/`kebab-case` names mapped
to PascalCase per `.claude/Rule.md` §3.1.

**Files created (51)**
- `.claude/CLAUDE.md` (pointer stub), `.claude/Orders.md` (requirements/decisions register).
- `.claude/agents/` — 10 `<Role>Agent.md` templates (Backend, Database, Deployment, Discovery,
  Docs, Frontend, Qa, Review, Security, Testing).
- `.claude/commands/` — `Implement.md Plan.md Refactor.md Review.md Spec.md`; `workflow/` (same 5,
  multi-agent variants); `phases/` (`Phase00Foundation`, `Phase01ProjectDiscovery`,
  `Phase02RepositoryBootstrap`, `PhaseTemplate`, `Readme`).
- `.claude/docs/` — `ProjectDescription Domain Permissions Ui Recommendations Deployment Server
  FeatureTemplate`.
- `.claude/knowledge/` — `SecurityRules TenantIsolation RolePermissionModel DeploymentRunbook
  DnsRecords LocalAssets MediaStorage PolicyTemplate`.
- `.claude/skills/` — `BackendSkill DatabaseSkill DeploymentSkill FrontendSkill GitSkill
  SecuritySkill TestingSkill SkillTemplate`.

**Files modified (additive only)**
- `.claude/Rule.md` — §3.3 tree + naming notes; ## Project Documents rows for the new families.
- `.claude/FileIndex.md` — new entries.

**Files preserved (unchanged):** every pre-existing `.claude/` file — `Rule.md` content,
`Changelog.md`, `PhaseDecisions.md`, `FileIndex.md`, `docs/*`, `knowledge/Knowledge.md`,
`{agents,commands,skills}/Readme.md`, `PhaseResults/*`, and the project-root `CLAUDE.md`.

**Notes.** The agent files carry valid frontmatter and the command files carry `description`
frontmatter, so Claude Code will now surface ~10 subagents and ~15 slash commands — **all marked
TEMPLATE**. Deployment/Server/DNS/media/runbook files are deliberate empty placeholders (no
infrastructure decided). Nothing outside `.claude/` was touched (no source, tests, DB, Docker,
Composer).

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-07 — `PhaseResults/` moved into `.claude/`

**Summary.** `PhaseResults/` (project root) → `.claude/PhaseResults/`. Name stays PascalCase
(our own dir, not a Claude Code tool folder). Contents unchanged.

**Files modified** (references repointed `PhaseResults/` → `.claude/PhaseResults/`)
- `CLAUDE.md` (Phase Completion Rule, Documentation-directory note).
- `.claude/Rule.md` (§3.3 layout + text, §3.4, §7, ## Project Documents, §4.1).
- `.claude/FileIndex.md`, `.claude/PhaseDecisions.md`, `.claude/docs/{Architecture,Phases}.md`,
  `.claude/PhaseResults/{Readme,Template,Phase01Result}.md`.

**Migration notes.** `PhaseResults/PhaseNNResult.md` → `.claude/PhaseResults/PhaseNNResult.md`.
**Breaking changes.** None.

## 2026-09-07 — Documentation reorganised into `.claude/`

**Summary.** Adopted the standard Claude Code project layout. `Documents/` retired; all docs now
live under `.claude/` (`Rule.md`, `Changelog.md`, `PhaseDecisions.md`, `FileIndex.md` at the
root; `docs/`, `knowledge/`, plus empty `agents/`, `commands/`, `skills/` skeletons). Folder
skeleton adopted, existing docs adapted into it, PascalCase file names kept, `CLAUDE.md` left at
the project root, phase-tracking artefacts kept.

**Files moved** (`Documents/` → `.claude/`)
- `Rule.md`, `Changelog.md`, `PhaseDecisions.md` → `.claude/`
- `Architecture.md`, `Commands.md`, `Phases.md`, `LastAiAnswer.md`, `ClaudeOld.md` → `.claude/docs/`
- `Knowledge.md` → `.claude/knowledge/`
- `Documents/` directory removed.

**Files created**
- `.claude/FileIndex.md` — repo-wide file map.
- `.claude/{agents,commands,skills}/Readme.md` — skeleton placeholders.

**Files modified** (references repointed to `.claude/…`)
- `CLAUDE.md` — "Documentation directory" note; naming-rule pointer.
- `.claude/Rule.md` — §3.1 (`.claude/` sub-dir naming), §3.3 rewritten around the `.claude/`
  layout, §3.4, §7, ## Project Documents (+ `FileIndex.md`, skeletons).
- `.claude/docs/{Architecture,Phases}.md`, `.claude/PhaseDecisions.md`, `.claude/knowledge/Knowledge.md`,
  `PhaseResults/{Readme,Phase01Result,Phase02Result}.md`, `Design/Readme.md`.

**Reason.** Match the conventional Claude Code structure (native `agents/`/`commands/`/`skills/`)
and keep the project root clean.

**Migration notes.** Doc bookmarks change: `Documents/Phases.md` → `.claude/docs/Phases.md`, etc.
**Breaking changes.** None (no code references these paths).

## 2026-09-06 — Phase 2: project scaffold & toolchain

**Summary.** A bootable, testable, empty Slim 4 app. No business logic, no database tables.
Decisions: Docker Compose · PHP 8.4 · Phinx · PHPUnit 11 · PHPStan max + strict-rules +
php-cs-fixer PSR-12 · keep `src/Bootstrap` + `src/Http` as app-level dirs
(`Documents/PhaseDecisions.md` Phase 2 Q1–Q6).

**Files created**
- Root: `composer.json` (+ `composer.lock`), `.gitignore`, `.env.example`, `Dockerfile`,
  `docker-compose.yml`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `phinx.php`.
- `src/Config/{Settings.php, DatabaseSettings.php, container.php, routes.php}`,
  `src/Bootstrap/AppFactory.php`, `src/Http/HealthAction.php`, `src/Public/index.php`,
  `src/Database/{Migrations,Seeds}/.gitkeep`.
- `tests/Unit/SmokeTest.php`, `tests/Unit/Config/SettingsTest.php`,
  `tests/Unit/Http/HealthActionTest.php`, `tests/Integration/DatabaseConnectionTest.php`.
- `Documents/Commands.md`.
- `PhaseResults/Phase02Result.md`.

**Files modified**
- `Documents/Architecture.md` — §4 folder layout (adds `src/Bootstrap/`, `src/Http/`, splits
  `Database/`, notes lowercase config files).
- `Documents/Rule.md` — §3.1 exceptions (`Dockerfile`, `phpstan.neon`, `phinx.php`,
  `.php-cs-fixer.dist.php`, non-class config files); ## Project Documents (+ `Commands.md`).
- `Documents/PhaseDecisions.md`, `Documents/Phases.md` (Phase 2 row → ☑; stray `f` typo on the
  "Column meanings" line removed).

**Verification.** `composer test` OK (4/16); `composer stan` [OK] level max; `composer cs` clean;
`GET /health` → 200 `{"status":"ok","service":"gomrok"}`; `/nope` → 404. Integration test written
but **skipped** — no Docker daemon / no `gomrok` MySQL user in this environment.

**Migration notes.** Run `cp .env.example .env && composer install`. **Breaking changes.** None.

## 2026-09-06 — Phases.md: status table moved to the top

**Summary.** In `Documents/Phases.md`, the `## Status & execution tracking` section (column
meanings + the 30-phase table) was moved to the very top, immediately after the H1 and before
the intro / *How each phase runs* / *Database strategy* / the detailed phase sections. Table
content and phase data unchanged (row-by-row verified identical).

**Files modified**
- `Documents/Phases.md` — section reordered; one consequential wording fix: step 6 of *How each
  phase runs* now says "the *Status & execution tracking* table (top of file)" instead of "the
  table below".

**Reason.** So the current phase status is visible immediately on opening the file.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Project-document registry in Rule.md

**Summary.** Added a **## Project Documents** section to `Documents/Rule.md` — a table of every
documentation file (name, path, purpose) — plus **§3.5** requiring it to be kept in sync
automatically whenever a doc file is created / renamed / moved / removed.

**Files modified**
- `Documents/Rule.md` — new `## Project Documents` table (13 rows) + `### 3.5 Project-document
  registry` rule; §7 "Documents kept current" row for `Rule.md` extended.
- `CLAUDE.md` — "Documentation directory" note now points at the registry.

**Reason.** Single place to see what every doc file is for; keeps the doc set discoverable.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Documentation moved under `Documents/`

**Summary.** All project documentation moved out of the project root into a new root-level
`Documents/` directory, and this made a permanent rule in `Documents/Rule.md` §3.3.

**Files moved** (project root → `Documents/`)
- `Architecture.md`, `Changelog.md`, `ClaudeOld.md`, `Knowledge.md`, `LastAiAnswer.md`,
  `PhaseDecisions.md`, `Phases.md`, `Rule.md`

**Left at the project root (intentional)**
- `CLAUDE.md` — the harness auto-loads `./CLAUDE.md`; moving it breaks that. It now points into
  `Documents/` for everything else.
- `PhaseResults/`, `Design/` — special-purpose directories, not documentation; unchanged.
- `.claude/docs/*` — the DB-docs location is a Phase 4 decision; unchanged for now.

**Files modified** (references updated to `Documents/…`)
- `CLAUDE.md` — every doc reference; new "Documentation directory" note; `LastAiAnswer.md` rule
  path; "Project Rules File" section.
- `Documents/Rule.md` — new **§3.3 Documentation directory** rule; §3.4 (was §3.3) paths;
  §1/§4/§7 references; §3.1 naming examples clarified.
- `Documents/Phases.md`, `Documents/PhaseDecisions.md`, `Documents/Architecture.md` — internal
  references.
- `Design/Readme.md`, `PhaseResults/Readme.md`, `PhaseResults/Phase01Result.md` — references
  (Phase01Result also carries a dated relocation note).

**Reason.** Keep the project root clean; make documentation location a permanent, enforced
convention.

**Migration notes.** Any external bookmark to a root-level doc path (e.g. `Phases.md`) is now
`Documents/Phases.md`. **Breaking changes.** None (no code depends on these paths yet).

## 2026-09-06 — Naming compliance: Changelog.md / Knowledge.md

**Summary.** Renamed two docs that had been created in all-caps to PascalCase per `Rule.md` §3.1.

**Files renamed**
- `CHANGELOG.md` → `Changelog.md`
- `KNOWLEDGE.md` → `Knowledge.md`

**Files modified** (references updated)
- `CLAUDE.md` — Changelog Rule + Phase Completion Rule + Expected Claude Behavior.
- `Rule.md` — §3.1 gained an explicit "all-caps community filenames are still PascalCased" bullet
  (`Changelog.md`, `Knowledge.md`, `Readme.md`, …); §4 and §7 references.
- `Phases.md` — *How each phase runs* step 5, Phase 1 + Phase 2 scope.
- `PhaseResults/Phase01Result.md` — recorded file paths corrected (error fix).

**Reason.** `CHANGELOG.md` / `KNOWLEDGE.md` are community conventions, not tool-mandated names, so
the PascalCase rule applies. `Rule.md` now says so explicitly to prevent recurrence.

**Migration notes.** None. **Breaking changes.** None.

## 2026-09-06 — Phase 1: architecture baseline

**Summary.** Established the target architecture from first principles (greenfield — no
predecessor system). No application code, no database.

**Files created**
- `Architecture.md` — hexagonal + modular architecture, module map, folder layout, cross-module
  communication (published interfaces + in-process domain events), identifier strategy
  (BIGINT PK + public ULID), money representation (`brick/money` + `Money` VO), provider-adapter
  model (core `PaymentProviderPort` + optional capability interfaces + `ProviderCapabilities`),
  resolution-pipeline sketches, payment lifecycle, cross-cutting concerns, testing approach,
  deferred items.
- `Changelog.md`, `Knowledge.md` — seeded (initially created as `CHANGELOG.md` / `KNOWLEDGE.md`;
  see the naming-compliance entry above).
- `PhaseResults/Phase01Result.md` — Phase 1 record.

**Files modified**
- `Phases.md` — Phase 1 tracking row filled (status ☑, start/end datetime, actual duration).

**Reason.** Lock the structural decisions every later phase depends on, via the 5 interactive
questions.

**Migration notes.** None. **Breaking changes.** None.
