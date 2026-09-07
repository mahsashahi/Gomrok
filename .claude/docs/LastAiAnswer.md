# Q: Reorganise documentation into a .claude/ structure (before Phase 3)

Per your 4 answers: adopted the folder skeleton, adapted our docs into it, kept **PascalCase**
file names, kept **CLAUDE.md at the project root**, kept all phase-tracking artefacts.

## New layout

```
CLAUDE.md                     (project root — unchanged)
PhaseResults/                 (project root — unchanged)
Design/                       (project root — unchanged)
.claude/
├── Rule.md                   ← Documents/Rule.md
├── Changelog.md              ← Documents/Changelog.md
├── PhaseDecisions.md         ← Documents/PhaseDecisions.md   (fills the template's "ORDERS" role)
├── FileIndex.md              ← NEW (repo-wide file map)
├── agents/    Readme.md      ← NEW skeleton
├── commands/  Readme.md      ← NEW skeleton
├── skills/    Readme.md      ← NEW skeleton
├── knowledge/
│   └── Knowledge.md          ← Documents/Knowledge.md
└── docs/
    ├── Architecture.md       ← Documents/Architecture.md
    ├── Phases.md             ← Documents/Phases.md
    ├── Commands.md           ← Documents/Commands.md
    ├── LastAiAnswer.md       ← Documents/LastAiAnswer.md   (this file)
    └── ClaudeOld.md          ← Documents/ClaudeOld.md
```

`Documents/` removed. Sub-dir names inside `.claude/` are lowercase (Claude Code requires it for
`agents/`, `commands/`, `skills/`); **files stay PascalCase**.

## Naming-rule note

`.claude/Rule.md` §3.1 was NOT overridden — PascalCase still wins for files. Added one clause:
inside `.claude/`, directory names follow Claude Code's lowercase convention.

## References updated

`CLAUDE.md` (Documentation-directory note), `.claude/Rule.md` (§3.1, §3.3 rewritten, §3.4, §7,
## Project Documents), `.claude/PhaseDecisions.md`, `.claude/docs/{Architecture,Phases}.md`,
`.claude/knowledge/Knowledge.md`, `PhaseResults/{Readme,Phase01Result,Phase02Result}.md`,
`Design/Readme.md`, `.gitignore` (+ `.claude/settings.local.json`), memory. `.claude/Changelog.md`
got a new entry; older entries keep their historical paths.

## Verified

`composer ci` still green (cs + stan + 4 unit tests). No stale `Documents/` path references
except two deliberate historical notes in the PhaseResults files.

Ready for **Phase 3 — Shared kernel**.
