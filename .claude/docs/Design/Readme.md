# Gomrok admin panel — design files

Pulled from the Claude Design project `Gomrok_design`
(`https://claude.ai/design/p/4eaf32aa-ad31-40be-bad4-03bd09f34ecb`) on 2026-09-06.

Files were renamed to PascalCase per `.claude/Rule.md`, and each canvas file's
`<script src>` was repointed from `./support.js` to `./Support.js`. Content is otherwise
unmodified from the export.

Only **v4** is kept — the earlier iterations (v1, v2, v3) were deleted 2026-09-08 as no longer
relevant (recoverable from git history if ever needed). This folder was moved from the project
root to `.claude/docs/Design/` the same day.

## Files

| File | What it is |
| --- | --- |
| `GomrokAdminPanelV4.dc.html` | **The design.** Canvas source (`<x-dc>` template + `{{ }}` bindings). |
| `GomrokAdminPanelV4Export.dc.html` | v4 with an embedded thumbnail `<template>` — same UI as v4 (redundant; safe to delete). |
| `Support.js` | Claude Design canvas runtime. Generated — do not edit. The `.dc.html` files load it via `<script src="./Support.js">`. |

The authoritative project spec (`uploads/CLAUDE.md` in the design project) is the
repo-root `CLAUDE.md` — not duplicated here.

## Opening a `.dc.html` locally

These are Claude Design canvas documents, not standalone pages. `Support.js` expects
`window.React` / `window.ReactDOM` on the page. To view one, either open it inside the
Claude Design canvas, or wrap it in a host page that loads React 18 + ReactDOM 18 UMD
builds before `Support.js`.

## Not pulled: reference screenshots

The design project also holds 24 screenshots the user pasted into the design chat
(`uploads/pasted-*.png`). They each exceed the 256 KB per-file fetch limit of the
sync tool and come back truncated/corrupt, so they were not copied. Export them from
the Claude Design project directly if they're needed here.
