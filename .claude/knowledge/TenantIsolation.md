# TenantIsolation.md — multi-client isolation policy

**Purpose.** How Gomrok keeps clients (tenants) isolated. Stable policy.

**Authoritative source:** `CLAUDE.md` → *Multi-Client Requirement*; `.claude/docs/Architecture.md`
§3 (module boundaries), §6 (identifiers).

## Rules

- Every business row carries `client_id`. Composite indexes and foreign keys lead with `client_id`.
- Packages are client-scoped: `UNIQUE (client_id, code)`. No global catalogue, no
  `client_packages` junction.
- Every API request is authenticated and scoped to exactly one client; cross-client access is
  rejected, not filtered-and-hoped.
- **IDs are sequential integers and therefore guessable** (decision of 2026-09-08 — see
  `Architecture.md` §6). Isolation must not depend on IDs being unguessable: every read/write
  is `WHERE client_id = :authenticatedClient`, and a request for an ID the client doesn't own
  returns 404/403 — never the row.
- Payments, subscriptions, webhooks, provider accounts, pricing, vouchers, logs, audit entries —
  all linked to the owning client.
- No single client's behaviour is hardcoded in the core; client-specific behaviour is
  configuration (`CLAUDE.md` → *Naming Rules*, *Multi-Client Requirement*).
