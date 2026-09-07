# Server.md

**Purpose.** Server / infrastructure details for Gomrok's environments.

**Status:** not yet defined. No servers exist. Do not record hostnames, IPs, credentials, or
provider accounts here until real infrastructure is provisioned — and keep secrets out of the
repo entirely.

## To document (when infrastructure exists)

- Environments (names, purpose, URLs).
- Runtime: PHP version & extensions, web server, process manager, queue worker host.
- Database: engine/version, sizing, backup schedule.
- Networking: domains, TLS, inbound/outbound rules, webhook endpoints exposed to providers.
- Observability: where logs / metrics / alerts go.

## Current

Local only — `docker-compose.yml` (see `.claude/docs/Commands.md`).
