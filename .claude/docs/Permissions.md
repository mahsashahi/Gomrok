# Permissions.md

**Purpose.** The admin panel's RBAC / permission model.

**Reference, don't duplicate.** Authoritative text:

- `CLAUDE.md` → *Admin Panel Role-Based Permission Requirement* — roles (`admin`,
  `support_agent`), enforcement at UI **and** backend, permission keys, security requirements.
- `.claude/knowledge/RolePermissionModel.md` — the stable policy statement.

## Status

Two roles only for now: `admin`, `support_agent`. The permission database schema is **not yet
designed** — it will be proposed and confirmed before the admin panel is built (see
`.claude/docs/Phases.md` → Phase 27).

## To document (Phase 27)

- Final permission key list.
- Role → permission matrix.
- Client-scope / resource-ownership rules.
- Where each check happens (middleware, action, view).
