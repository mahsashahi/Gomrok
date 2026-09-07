# FeatureTemplate.md

**Purpose.** The template for a per-feature doc (`.claude/struct.md`'s `docs/[feature].md`). Copy
to `docs/<FeatureName>.md` when a feature area needs its own reference doc (e.g. `Webhooks.md`,
`PricingResolution.md`, `Payouts.md`).

Keep feature docs pointed at the spec and the code — don't restate `CLAUDE.md`.

## Sections

```
# <FeatureName>.md

## Purpose
What this feature is, one paragraph. Link CLAUDE.md section(s).

## Scope
In / out of scope. Which module(s) own it (`.claude/docs/Architecture.md` §3).

## Model
Key domain objects, statuses, invariants.

## Flow
Step-by-step, with the endpoints / jobs / events involved.

## Data
Tables involved (link the DB docs once they exist).

## Edge cases & rules
Reference `.claude/knowledge/Knowledge.md`.

## Tests
Where the coverage lives.

## Open questions
```
