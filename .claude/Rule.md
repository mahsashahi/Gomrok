# Rule.md — project rules

Standing, binding rules for this repository, distilled from `CLAUDE.md` and from decisions the
user has made in conversation. `CLAUDE.md` remains the detailed spec — where it gives more detail,
follow it; where this file and `CLAUDE.md` disagree on a rule, raise it rather than guessing.

Every item here is a **must**. Section references like *(CLAUDE.md → Pricing Requirement)* point
at the fuller text.

---

## 1. Working agreement

- **Language.** When the user writes in Persian, reply in **English**. Only reply in Persian when
  the user explicitly asks for a Persian reply. *(CLAUDE.md → Language Rule)*
- **LastAiAnswer.md.** After every *substantive* response (design analysis, technical
  recommendation, code plan, architecture explanation, phase summary), overwrite
  `.claude/docs/LastAiAnswer.md` with just that answer — single-slot buffer, use `Write`
  (never `Edit` / append), start with `# Q: <one-line topic>` then the response body. Skip it for
  short confirmations, tool-result echoes, one-line questions, and meta-talk about the rule
  itself. Standing rule — do not ask permission each time. *(CLAUDE.md → LastAiAnswer.md Response
  Log Rule)*
- **Explain the important decisions**; keep code and architecture simple and practical; avoid
  overengineering; prefer clear over clever. *(CLAUDE.md → Expected Claude Behavior)*
- **Act on established facts** — don't re-ask a question the user has already answered, in this
  session or recorded in the project files.

## 2. Project framing

- **Greenfield.** Gomrok has **no** predecessor, no legacy system, no "Dexter", nothing to
  analyze / reuse / migrate from. Never reintroduce legacy / rewrite / migration-from framing.
  *(CLAUDE.md → Project Overview)*
- **Multi-client from day one.** Never hardcode one client's behaviour into the core. Televika is
  only *the first client*; client-specific behaviour lives in client configuration / settings /
  adapters / DB. *(CLAUDE.md → Multi-Client Requirement)*
- **Gomrok owns all provider communication.** Client applications never talk to a payment
  provider directly.
- **Never trust a client-supplied price or package.** Gomrok resolves package availability,
  price, provider, payment method, purchase type, and voucher validity itself, server-side.
  *(CLAUDE.md → Security Rules, API Design)*
- **Keep provider-specific logic out of the core.** Provider SDKs are used only inside provider
  infrastructure adapters; adding a provider = a new adapter, never a change to the core flow.
  *(CLAUDE.md → Provider Adapter Pattern)*

## 3. File & naming conventions

### 3.1 File naming: PascalCase

Every file **and directory** in this repo is named in `PascalCase`:

- Words joined with no separator, each capitalised: `Phases.md`, `LastAiAnswer.md`, `Rule.md`,
  `DatabaseDesign.md`, `ProviderAdapter.php`, `Design/`, `Src/Modules/Payments/`.
  (Bare filenames are used here as naming examples — for their actual location see §3.4.)
- No `snake_case`, `kebab-case`, `SCREAMING_CASE`, or spaces.
- **Inside `.claude/`**, directory names follow Claude Code's own lowercase convention —
  `.claude/agents/`, `.claude/commands/`, `.claude/skills/` are tool-recognised and *must* be
  lowercase; `.claude/docs/` and `.claude/knowledge/` match them for consistency. **Files inside
  `.claude/` are still PascalCase** (`.claude/Rule.md`, `.claude/docs/Architecture.md`).
- **All-caps community filenames are still PascalCased**: `Changelog.md` (not `CHANGELOG.md`),
  `Knowledge.md` (not `KNOWLEDGE.md`), `Readme.md` (not `README.md`), `Todo.md` (not `TODO.md`),
  `License.md` (not `LICENSE`). These are conventions, not tool-mandated names — see exceptions.
- Acronyms are treated as words: `LastAiAnswer.md` not `LastAIAnswer.md`; `HttpClient.php` not
  `HTTPClient.php`.
- The extension stays lowercase (`.md`, `.php`, `.html`, `.dc.html`).
- Third-party / generated artefacts (e.g. the Claude Design exports under `Design/`) are renamed
  to PascalCase too. If a rename breaks an internal reference — as `support.js` → `Support.js`
  did for the `<script src>` in the `.dc.html` files — fix the reference in the same change.
- When a rule elsewhere, or the user, names a file in lowercase, apply PascalCase anyway and note
  it, unless it's an exception below.

**Exceptions (keep exact name):**

- `CLAUDE.md` — reserved filename the Claude Code harness auto-loads.
- `.claude/` and `.idea/` — tool-owned directories.
- Ecosystem-mandated names: `composer.json`, `.env`, `mkdocs.yml`, `docker-compose.yml`,
  `Dockerfile`, `phpunit.xml`, `phpstan.neon`, `phinx.php`, `.php-cs-fixer.dist.php`,
  `.gitignore`, and similar tool-owned files.
- PSR-4 requires file name == class name; class names are already PascalCase, so this is
  automatically satisfied.
- **Non-class PHP config files that return a value** (`src/Config/container.php`,
  `src/Config/routes.php`, and future `settings.php` etc.) keep the conventional lowercase name —
  they are `require`-d, not autoloaded, and this matches `.claude/docs/Architecture.md` §4.

### 3.2 Domain naming

Use generic names everywhere — `client`, `provider account`, `provider group`, `pricing group`,
`price list`, `client callback`, `provider webhook`, `customer`, `package`, `voucher`,
`purchase type`. Never bake a specific client's name into architecture, APIs, DB tables, or
services. *(CLAUDE.md → Naming Rules)*

### 3.3 The `.claude/` directory

All project documentation lives under **`.claude/`** (the Claude Code project directory), never
loose in the project root. Layout:

```
.claude/
├── Rule.md              this file — the standing-rules catalogue
├── Changelog.md         chronological change log
├── PhaseDecisions.md    every phase decision Q/options/selection  (the "orders" record)
├── FileIndex.md         map of the key files in the whole repo
├── agents/              custom subagent definitions      (empty skeleton for now)
├── commands/            custom slash-command definitions (empty skeleton for now)
├── skills/              reusable implementation guides    (empty skeleton for now)
├── knowledge/
│   └── Knowledge.md     durable domain knowledge / gotchas
└── docs/
    ├── Architecture.md  the Phase-1 architecture baseline
    ├── Phases.md        the 30-phase plan + Status & execution tracking table
    ├── Commands.md      everyday command reference
    ├── LastAiAnswer.md  single-slot buffer: the most recent substantive answer
    └── ClaudeOld.md     superseded spec archive (do not follow)
```

- **Any future documentation file goes under `.claude/`** — a policy under `knowledge/`, a
  reference doc under `docs/`, an agent/command/skill in its folder — unless an explicit
  technical reason requires elsewhere.
- The §3.1 filename convention applies to every file inside `.claude/` (PascalCase files;
  `agents/` `commands/` `skills/` `docs/` `knowledge/` dirs stay lowercase — Claude Code needs
  the first three lowercase).
- When a doc is created / renamed / moved / removed: update **every reference in the same
  change** (Markdown links, path references, `CLAUDE.md`, these rules, phase files, scripts,
  code) and the **## Project Documents** table (§3.5). No stale references, no duplicate copy.

**Intentionally outside `.claude/`:**

- `CLAUDE.md` — sits at the **project root**; the harness auto-loads `./CLAUDE.md`, and it is not
  confirmed to auto-load `.claude/CLAUDE.md`. It is the entry point and points into `.claude/`.
- `PhaseResults/` (project root) — per-phase completion records; a special-purpose append-only
  store, not general documentation. `Design/` (project root) — design assets, not documentation.
- The Phase-4 database docs (`DatabaseDesign.md`, the ER diagram, the per-table guide,
  `mkdocs.yml`) will live under `.claude/docs/` too; their exact names are a Phase 4 decision
  (the inherited spec writes them kebab-case).

### 3.4 Where things live

- The final database schema does **not** go in `CLAUDE.md`. It lives in the DB docs (§5).
- The 30-phase plan: `.claude/docs/Phases.md`.
- Every phase decision question, its options, the recommendation, and the user's final selection:
  `.claude/PhaseDecisions.md` — see §4.2.
- Per-phase completion records: `PhaseResults/` (project root) — one `PhaseNNResult.md` per
  completed phase (see `PhaseResults/Readme.md`).
- The map of key files across the whole repo: `.claude/FileIndex.md`.
- Cross-cutting conventions: this file, `.claude/Rule.md`.
- The admin-panel design is mirrored under `Design/` (project root; see `Design/Readme.md`).
- Do not put the full decision questionnaire inside `.claude/docs/Phases.md`; it links to
  `.claude/PhaseDecisions.md`.

### 3.5 Project-document registry

Every documentation file is registered in the **## Project Documents** section below (file name,
path, purpose). Whenever a documentation file is **created, renamed, moved, or removed**, update
that section in the **same change** — automatically, without being asked. A new doc file is not
"done" until it has a row there.

## Project Documents

The complete list of the project's documentation files. Keep this table in sync with reality
(§3.5) — one row per documentation file (or file family).

| Document | Path | Purpose |
|---|---|---|
| CLAUDE.md | `CLAUDE.md` (project root) | Entry-point instructions for Claude Code; the detailed project spec. Stays at the root because the harness auto-loads `./CLAUDE.md`; points into `.claude/` for everything else. |
| Rule.md | `.claude/Rule.md` | Consolidated catalogue of every standing project rule (working agreement, framing, naming, phase workflow, database, evidence, docs, security) with pointers into `CLAUDE.md`. |
| FileIndex.md | `.claude/FileIndex.md` | Map of the key files across the whole repo (docs, source entry points, config, tests) so they can be found fast. |
| Phases.md | `.claude/docs/Phases.md` | The fixed 30-phase implementation plan: per-phase goal/scope/DB/exit, the *Status & execution tracking* table (status, start/end datetime, estimated & actual duration, tokens), and *How each phase runs*. |
| PhaseDecisions.md | `.claude/PhaseDecisions.md` | The permanent record of every phase decision question — the question, all options, the recommendation, the user's selection, status, and any later change. |
| Architecture.md | `.claude/docs/Architecture.md` | The target architecture baseline decided in Phase 1: dependency rule, module map, folder layout, cross-module communication, identifiers, money, provider-adapter model, resolution-pipeline sketches, payment lifecycle, cross-cutting concerns, deferred items. |
| Changelog.md | `.claude/Changelog.md` | Chronological (newest-first) record of every meaningful change: date, summary, files changed, reason, migration notes, breaking changes. |
| Knowledge.md | `.claude/knowledge/Knowledge.md` | Durable domain knowledge and gotchas learned while building Gomrok — provider quirks, money-scale rules, pricing/voucher edge cases, identifier boundary, webhook rules. |
| Commands.md | `.claude/docs/Commands.md` | Everyday commands: setup, Docker, running the app, tests (incl. a single test), static analysis, code style, Phinx migrations, full local CI. |
| LastAiAnswer.md | `.claude/docs/LastAiAnswer.md` | Single-slot buffer holding only the most recent substantive assistant response (overwritten each time; see §1). |
| ClaudeOld.md | `.claude/docs/ClaudeOld.md` | Superseded archive of an early spec draft. Kept for history only — carries a SUPERSEDED banner; do not follow it. |
| PhaseResults/Readme.md | `PhaseResults/Readme.md` | Explains the phase-result convention: naming, structure, and the rules for `PhaseNNResult.md` files. |
| PhaseResults/Template.md | `PhaseResults/Template.md` | The section layout to copy when creating a phase result file. |
| PhaseNNResult.md | `PhaseResults/PhaseNNResult.md` | One per completed phase (zero-padded, e.g. `Phase01Result.md`): the detailed record of what was *actually* done that phase. Append-only. |
| agents/commands/skills Readme.md | `.claude/{agents,commands,skills}/Readme.md` | Placeholder notes explaining what each empty skeleton folder is for and candidate contents. |
| Design/Readme.md | `Design/Readme.md` | Describes the mirrored Claude Design admin-panel export files under `Design/` and how to open them. |

`PhaseResults/` and `Design/` sit at the project root, not under `.claude/` (§3.3–§3.4), but
their documentation files are still registered here.

## 4. Phase workflow

- Work follows the **30-phase plan in `.claude/docs/Phases.md`**. Keep its status table current.
- The phase count (**30**) and **Phase 27 = "Admin Module Views and Panels"** are fixed. Adding a
  new phase or materially rescoping an existing one requires explicit user confirmation first.
  *(CLAUDE.md → Visual and Output Verification Rule)*
- **Before any code in a phase:** explain what the phase will do, then ask the phase's decision
  questions **one at a time** (§4.2). Do not start a decision-dependent part of the phase until
  its questions are answered. Never re-ask an answered question. *(CLAUDE.md → Interactive Phase
  Rule)*
- **During:** add the relevant tests as the code is written, not afterwards. Keep domain logic
  independent of Slim, MySQL, and provider SDKs. *(CLAUDE.md → Backend Architecture Requirement,
  Testing Requirements)*
- **After each phase:** give the completion summary — what was implemented; files
  created / updated / removed; DB changes; tests added + how to run them; **real captured
  evidence** (§6); known limitations; next recommended phase. Then update `.claude/Changelog.md`.
  *(CLAUDE.md → Phase Completion Rule)*
- **Track every phase** in the `.claude/docs/Phases.md` *Status & execution tracking* table: at start, set
  Status ◐ and record Start Datetime (confirm/refine Estimated Duration in the 5 questions); at
  completion, set Status ☑ and record End Datetime, Actual Duration (hands-on time, summed across
  sessions), and Tokens Used.
### 4.1 Phase result files

`.claude/docs/Phases.md` is the roadmap and high-level tracker; `PhaseResults/` is the detailed historical
record of actual work. After a phase is complete, write exactly one
`PhaseResults/PhaseNNResult.md` (zero-padded, from `PhaseResults/Template.md`). Full detail in
`PhaseResults/Readme.md`; the binding points:

- **A phase is not fully completed until its result file is completed.**
- One result file per phase. Applies automatically to **all** phases.
- Record what *actually* happened — **never document planned work as completed work**, never copy
  the plan text from `.claude/docs/Phases.md`.
- **Never invent** timestamps, token usage, test results, or implementation details. Unavailable
  token counts are `N/A`. Never claim tests passed unless they were actually run successfully
  (record the commands + real output).
- **Actual Duration is computed from the recorded Start and End Datetime.**
- **Do not silently rewrite an earlier phase's result file** when a later phase changes that
  code — document the later change in the later phase's result file. Result files are
  append-only history; don't overwrite or delete them.
- `Execution Summary` mirrors the `.claude/docs/Phases.md` tracking row. `Database Changes` / `API Changes`
  sections state "No database changes." / "No API changes." explicitly when there are none.
- Be specific: exact file paths, class names, method names, port/interface names, endpoint
  paths, table names.

### 4.2 Phase decision questions & tracking

For every phase that needs architectural, technical, implementation, or product decisions:

**Ask one at a time.**

- Ask decision questions **one at a time**. Never present all of a phase's questions at once.
- Wait for the answer, record it in `.claude/PhaseDecisions.md`, then ask the next.
- Don't start implementing a decision-dependent part of the phase until its questions are
  answered.

**Every question shows:** phase number · question number (`Q2 of 5`) · the question · all
options · a short explanation of each option · the recommended option (when there is one) · why.
**Never auto-select the recommendation** — the user makes the final choice. Never guess a
selection.

**Persist immediately.** Maintain `.claude/PhaseDecisions.md`. The moment the user answers a
question, update that file — do not wait for the end of the phase. Format per phase:

```
## Phase N — [Phase Name]

### Q1 — [Short Decision Title]

**Question:** …

**Options:**
1. [Name] — description…
2. [Name] — description…

**Recommended:** Option X

**Selected:** Option X — [Name]        (or, while unanswered: —)

**Status:** Pending → Decided

**Decision Notes:** clarification / reasoning from the discussion.

---
```

**Status.** `Status: Pending` once asked; `Status: Decided` after the user chooses.

**Changing a decision.** If the user later changes their mind, don't silently replace the old
choice — keep enough history to see what changed:

```
**Previously Selected:** Option 2
**Current Selection:** Option 1
**Changed:** 2026-09-06
**Reason:** … (if given)
```

**End-of-phase decision check** — before marking a phase complete, verify: (1) all required
questions were asked; (2) every one has a recorded answer; (3) `.claude/PhaseDecisions.md` reflects the
user's actual selections; (4) the implementation follows those selections. If the implementation
diverges from a recorded decision, **stop and ask** before changing the decision or proceeding.

Applies automatically to all current and future phases.

## 5. Database rules

- **Incremental, never upfront.** There is no whole-system schema-design phase. Only the stable
  base / reference tables (`countries`, `currencies`, `provider_types`, capability catalogue,
  similar lookups) are built early (Phase 4). Every other table is designed and created in the
  phase that first needs it; later phases may extend earlier tables via additive migrations.
  *(CLAUDE.md → Database Design Confirmation Rule; .claude/docs/Phases.md → Database strategy)*
- **Confirmation gate.** Before creating, changing, or finalizing any part of the schema, present
  the design — tables, key fields, relationships, indexes, unique constraints, foreign keys,
  nullable fields, JSON fields, security-sensitive fields, migration risks, alternatives — then
  ask, verbatim: *"Please confirm the database design before I create migrations or schema
  files."* No migrations before confirmation. Re-confirm if the design changes.
- **Client-scoped.** Every business table carries `client_id`; composite indexes and foreign keys
  lead with `client_id`. Packages are client-scoped: `UNIQUE (client_id, code)`, no global
  catalogue, no `client_packages` junction. *(CLAUDE.md → Package Ownership Rule)*
- **Store snapshots.** Persist a price / voucher / provider-routing decision snapshot on the
  payment or subscription record so later rule changes never alter historical transactions.
  *(CLAUDE.md → Pricing Requirement, Voucher Requirement)*
- **DB docs are living documents.** Any table / column / index / constraint / FK that is added,
  removed, or renamed updates `.claude/docs/database-design.md` (canonical),
  `.claude/docs/database-diagram.md` (+ `.html`), and `.claude/docs/db_explain.md` **in the same
  change**. Keep the table count and every table's shape identical across all three. The diagram
  is part of "done" for any schema change. *(CLAUDE.md → Database Diagram Maintenance Rule)*

## 6. Evidence & verification

- **Show real evidence, not descriptions.** For any rendered view (admin page, screen), capture
  and show an actual screenshot. For any API response, CLI/command output, test run, or
  migration run, show the real captured output — not a paraphrase or a "should return…".
- Every phase-completion summary includes that evidence for whatever the phase produced.
- If the tooling can't capture a screenshot or an output yet, **say so explicitly** — do not
  present an unverified result as verified — and propose how to add the capability.
  *(CLAUDE.md → Visual and Output Verification Rule)*

## 7. Documents kept current

| File | When to update |
| --- | --- |
| `.claude/Changelog.md` | Check before changes; update after every meaningful change — date, summary, files changed, reason, migration notes, breaking changes. |
| `.claude/docs/LastAiAnswer.md` | After every substantive response (§1). |
| `.claude/docs/Phases.md` | The *Status & execution tracking* row for each phase (Status, Start/End Datetime, Est./Actual Duration, Tokens Used) as it runs; contents when scope is confirmed to change. |
| `.claude/PhaseDecisions.md` | Immediately after the user answers each phase decision question — options, recommendation, selection, status, and any later change (§4.2). |
| `.claude/docs/database-design.md`, `database-diagram.md` (+ `.html`), `db_explain.md` | Every schema change, same change (§5). |
| `PhaseResults/PhaseNNResult.md` | Created once, right after phase NN completes; never edited afterwards except to fix an error. |
| `.claude/Rule.md` | Whenever a new standing rule or convention is agreed; and its **## Project Documents** table whenever any documentation file is created / renamed / moved / removed (§3.5). |
| `.claude/docs/Architecture.md` | When an architecture-level decision (module boundaries, IDs, money, adapter shape, events…) is made or revised. |
| `.claude/knowledge/Knowledge.md` | When a durable domain fact or gotcha (provider quirk, edge case) is learned. |
| `.claude/FileIndex.md` | When a file is added / removed / moved that a newcomer would need pointed to (docs, source entry points, config, key modules). |
| `Design/Readme.md` | When the mirrored design files change. |

## 8. Security & correctness

- Provider credentials and API secrets are never hardcoded and never logged; use env vars or
  secure secret storage. Mask secrets in the admin UI (reveal-on-demand only).
- Store password hashes and (if persisted) session-token hashes — never plaintext. Log login
  attempts; track account status (active / disabled / locked). Audit-log sensitive admin actions.
- **Admin RBAC**: roles `admin` and `support_agent` only (no more without an explicit request);
  enforce permissions at **both** UI and backend/API level — hiding a button is not enough.
  Checks consider role, permission key, client scope, action type, resource ownership.
  *(CLAUDE.md → Admin Panel Role-Based Permission Requirement)*
- **Idempotency**: idempotency keys on client write requests; store provider webhook event IDs.
  Duplicate webhooks / retries must never double-create payments, refunds, subscriptions, voucher
  redemptions, or notifications. Webhook and callback processing must be safe to retry.
  *(CLAUDE.md → Idempotency Rules)*
- **Webhooks**: store the raw event before processing; verify the signature; never block the
  webhook response on long work — process asynchronously. *(CLAUDE.md → Background Jobs)*
- **Reject unsupported combinations** explicitly (e.g. a subscription request where the provider,
  country, package, or method doesn't support subscription). Never silently downgrade a requested
  purchase type. *(CLAUDE.md → Country-Based Provider and Purchase Capability Requirement)*
- A client must never access another client's data — payments, subscriptions, webhooks, configs,
  pricing, packages, vouchers, or logs.

## 9. Frontend / admin panel

- Lightweight only: Alpine.js + CSS (+ server-rendered PHP views where needed). No heavy
  frontend framework unless explicitly requested. *(CLAUDE.md → Frontend Stack)*
- Build the panel to the design's sidebar and screens; undesigned screens render a neutral
  titled placeholder, never a broken page. *(CLAUDE.md → Admin Panel Requirement)*
