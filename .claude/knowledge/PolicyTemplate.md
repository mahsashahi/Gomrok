# PolicyTemplate.md

**Purpose.** Template for a stable policy doc (`.claude/struct.md`'s `knowledge/[policy].md`).
Copy to `knowledge/<PolicyName>.md` for a policy that rarely changes (a security rule set, an
infra decision, a data-handling rule).

## Sections

```
# <PolicyName>.md

## Purpose
One paragraph: what this policy governs and why it is stable.

## Authoritative source
Link the CLAUDE.md / Rule.md / Architecture.md section this pins, if any.

## Policy
The rules, as a list. Each rule testable / checkable.

## Rationale
Short — why these rules.

## Exceptions
Explicit, or "none".

## Review
When this policy should be revisited (e.g. "Phase 27", "if we add a second region").
```
