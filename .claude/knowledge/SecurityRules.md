# SecurityRules.md — security policy

**Purpose.** The stable security rules Gomrok must always follow. Policy statement — rarely
changes.

**Authoritative source:** `CLAUDE.md` → *Security Rules*, *Idempotency Rules*, *Admin Panel
Role-Based Permission Requirement*. This file summarises and pins them; if it and `CLAUDE.md`
disagree, `CLAUDE.md` wins.

## Rules

- Provider credentials / API secrets: never hardcoded, never logged; env vars or a secret store
  only. Masked in the admin UI (reveal-on-demand).
- Passwords and (persisted) session tokens: store hashes only. Log login attempts; track account
  status (active / disabled / locked).
- Admin RBAC (`admin`, `support_agent`) enforced at **UI and backend** — hiding a button is not
  enough. Checks consider role, permission key, client scope, action type, resource ownership.
- Idempotency keys on client writes; dedupe provider webhook event IDs. Duplicate webhooks /
  retries never double-apply.
- Webhooks: store the raw event before processing, verify the signature, never block the response
  on processing.
- Strict client isolation — a client can never read another client's data.
- Never trust a client-supplied price/package — Gomrok resolves everything server-side.
- Reject unsupported provider/country/method combinations explicitly; never silently downgrade.
