# RolePermissionModel.md — admin roles & permissions policy

**Purpose.** The stable statement of Gomrok's admin role/permission model.

**Authoritative source:** `CLAUDE.md` → *Admin Panel Role-Based Permission Requirement*. Also
`.claude/docs/Permissions.md`.

## Policy

- **Two roles only** for now: `admin` and `support_agent`. Do not add roles without an explicit
  request.
  - `admin`: manages operational settings, clients, packages, pricing, vouchers, notifications,
    provider configs, admin access, retries.
  - `support_agent`: view-only (clients, users, payments, subscriptions, packages, vouchers,
    webhook/notification status); cannot touch provider credentials, secrets, roles, permissions,
    or system config.
- Enforced at **both** UI and backend/API level.
- Permission keys are namespaced (`clients.view`, `payments.refund`, `webhooks.replay`, …) — full
  list in `CLAUDE.md`.
- The permission **database schema is not yet designed** — proposed and confirmed before Phase 27.
