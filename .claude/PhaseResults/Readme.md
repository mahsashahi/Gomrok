# .claude/PhaseResults/

The detailed **historical record of actual work**. `.claude/docs/Phases.md` stays the roadmap and high-level
progress tracker; this folder holds one file per completed phase describing what really happened.

It also holds **`PhaseDecisions.md`** — the permanent record of every phase decision question,
its options, the recommendation, and the user's selection (moved here from `.claude/` root on
2026-09-08; `.claude/Rule.md` §4.2). That file is **newest-first** (current phase at the top,
Phase 1 at the bottom, questions descending Q5…Q1; new entries prepended, never appended), and
it is *not* a per-phase result file — the "one result file per phase" rules below do not apply
to it.

## Naming

```
.claude/PhaseResults/
├── Readme.md            this file
├── Template.md          ← copy this; not a result file
├── PhaseDecisions.md    every phase decision Q/options/recommendation/selection (newest-first)
├── Phase01Result.md
├── Phase02Result.md
├── Phase03Result.md
...
└── Phase30Result.md
```

`PhaseNNResult.md` — PascalCase per `.claude/Rule.md`, `NN` zero-padded (`Phase01Result.md`, not
`Phase1Result.md`) so the files sort in order. Title: `# Phase XX — [Phase Name]`, name matching
`.claude/docs/Phases.md`.

## Structure

Every result file uses the exact section layout in `Template.md`:

`## Execution Summary` (Phase number+name, Start Datetime, End Datetime, Estimated Duration,
Actual Duration, Tokens Used, Final Status) → `## Work Completed` → `## Files Created` →
`## Files Modified` → `## Implementation Details` → `## Database Changes` → `## API Changes` →
`## Tests and Validation` → `## Technical Decisions` → `## Problems Encountered` →
`## Resolutions` → `## Deferred Work` → `## Final Result`.

`Database Changes` and `API Changes` must say **"No database changes."** / **"No API changes."**
explicitly when there are none — never leave them blank.

## Rules

1. `.claude/docs/Phases.md` = roadmap + status/timing. `.claude/PhaseResults/PhaseDecisions.md` = every decision question, its
   options, and the user's selection. `.claude/PhaseResults/` = the detailed historical record of what
   was actually implemented.
2. Create the result file only **after** the phase is complete — and **a phase is not fully
   completed until its result file is completed**.
3. Exactly **one** result file per phase.
4. **Never document planned work as completed work.** Describe what actually happened; do not
   copy the plan text from `.claude/docs/Phases.md`.
5. **Never invent** timestamps, token usage, test results, or implementation details. If exact
   Tokens Used is unavailable, write `N/A`.
6. Never claim tests passed unless they were actually run successfully — record the commands and
   the real captured output.
7. **Do not silently rewrite a previous phase's result file** when a later phase changes that
   code. Document the later change in the later phase's result file.
8. Be specific: exact file paths, class names, method names, interface/port names, endpoint
   paths, table names.
9. `Execution Summary` mirrors that phase's row in the `.claude/docs/Phases.md` *Status & execution tracking*
   table — keep the two consistent. **Actual Duration is calculated from the recorded Start and
   End Datetime** (hands-on time, summed across sessions if the phase spanned more than one).
10. This applies automatically to **all** phases — no need to be reminded per phase.
