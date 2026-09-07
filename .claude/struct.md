# .claude/ Structure

```
.claude/
├── CLAUDE.md                          # Main project instructions for Claude
├── CHANGELOG.md                       # Phase-by-phase change log
├── ORDERS.md                          # All decisions, requirements, business rules
├── FILE_INDEX.md                      # Index of key project files
├── settings.local.json                # Local Claude Code settings (gitignored)
│
├── agents/                            # Custom subagent definitions
│   ├── backend-agent.md
│   ├── database-agent.md
│   ├── deployment-agent.md
│   ├── discovery-agent.md
│   ├── docs-agent.md
│   ├── frontend-agent.md
│   ├── qa-agent.md
│   ├── review-agent.md
│   ├── security-agent.md
│   └── testing-agent.md
│
├── commands/                          # Slash command definitions
│   ├── implement.md                   # /implement
│   ├── plan.md                        # /plan
│   ├── refactor.md                    # /refactor
│   ├── review.md                      # /review
│   ├── spec.md                        # /spec
│   │
│   ├── phases/                        # One file per development phase
│   │   ├── phase-00-foundation.md
│   │   ├── phase-01-project-discovery.md
│   │   ├── phase-02-repository-bootstrap.md
│   │   └── phase-NN-[name].md
│   │
│   └── workflow/                      # Multi-agent workflow variants
│       ├── implement.md
│       ├── plan.md
│       ├── refactor.md
│       ├── review.md
│       └── spec.md
│
├── docs/                              # Living project documentation
│   ├── project-description.md         # What the project does
│   ├── architecture.md                # Architectural decisions
│   ├── commands.md                    # Command reference
│   ├── deployment.md                  # Deployment guide
│   ├── server.md                      # Server / infra details
│   ├── domain.md                      # Domain model
│   ├── permissions.md                 # RBAC / permission model
│   ├── recommendations.md             # Post-phase recommendations log
│   ├── ui.md                          # Design system & UI guidelines
│   └── [feature].md                   # One doc per major feature area
│
├── knowledge/                         # Stable policies (rarely change)
│   ├── deployment-runbook.md
│   ├── dns-records.md
│   ├── local-assets.md
│   ├── media-storage.md
│   ├── role-permission-model.md
│   ├── security-rules.md
│   ├── tenant-isolation.md
│   └── [policy].md
│
└── skills/                            # Reusable implementation guides
    ├── backend-skill.md
    ├── database-skill.md
    ├── deployment-skill.md
    ├── frontend-skill.md
    ├── git-skill.md
    ├── security-skill.md
    ├── testing-skill.md
    └── [tech]-skill.md
```

## File Purposes

| File | Purpose |
|------|---------|
| `CLAUDE.md` | Rules Claude must follow — stack, architecture, workflow, security |
| `ORDERS.md` | Every requirement/decision with date, source, status, related phase |
| `CHANGELOG.md` | What changed per phase, for audit trail |
| `FILE_INDEX.md` | Map of important files so Claude finds things fast |
| `agents/*.md` | Specialized subagent system prompts (tools, scope, behavior) |
| `commands/*.md` | Slash command instructions (`/plan`, `/implement`, etc.) |
| `commands/phases/*.md` | Scope + checklist for each dev phase |
| `docs/*.md` | Reference docs Claude reads before implementing |
| `knowledge/*.md` | Policies that don't change — security rules, infra decisions |
| `skills/*.md` | How-to guides per technology (patterns, rules, checklists) |

## Minimal Starter (new project)

For bare-minimum setup, only these files are required:

```
.claude/
├── CLAUDE.md          # stack + architecture + workflow rules
├── ORDERS.md          # requirements tracker
├── CHANGELOG.md       # change log
└── skills/
    └── [core-skill].md
```

Add `agents/`, `docs/`, `knowledge/`, `commands/phases/` as the project grows.
