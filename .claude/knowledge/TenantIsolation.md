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
- Payments, subscriptions, webhooks, provider accounts, pricing, vouchers, logs, audit entries —
  all linked to the owning client.
- No single client's behaviour is hardcoded in the core; client-specific behaviour is
  configuration (`CLAUDE.md` → *Naming Rules*, *Multi-Client Requirement*).
