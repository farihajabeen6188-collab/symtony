# Security

SYMTONY stores production telemetry and lets AI models read it, so the design starts from what must never
leak or run. This document lists the threats we design for and how each is handled.

## Threat model

| Threat | Mitigation |
|---|---|
| Secrets or personal data in events reach the database, a model or an MCP client | `Redactor` runs at ingestion (sensitive key suffixes such as password, token, authorization, cookie, session, card number, IBAN, plus workspace-defined keys; value patterns such as card numbers, bearer tokens, JWTs and emails). Everything sent to a model or returned over MCP passes the Redactor again. The demo includes a `Bearer` secret; tests assert it never reaches the model or MCP output. |
| A model invents evidence | Findings must cite evidence ids; `GroundingValidator` rejects unknown refs, uncited claims, facts resting only on inferred context and mismatched durations. Rejected claims are shown as rejected and never used in the summary. |
| Prompt injection through event text | Models get evidence only through read-only, investigation-scoped tools; system prompts and MCP tool descriptions state that event text is untrusted data. The model has no shell, filesystem or SQL access, and nothing it writes runs in the application process. |
| AI-written test code is malicious | `StaticChecker` parses (never executes) the code and rejects shell, eval, include, network, file-write and dynamic calls, and wrong namespace, class or base class. A human must review it. It runs only in the isolated runner. |
| Escape from the test runner | The runner container has `network_mode: none`, a read-only root filesystem, runs as uid 65534, has 1 CPU, 768 MB memory and a per-test timeout, works in a tmpfs copy, and only reads job files. The command it runs is fixed; the test cannot change it. |
| Cross-workspace data access | Every repository query is scoped by workspace; `WorkspaceContext` resolves it per request and per user (reset between requests in long-running processes). Functional tests prove isolation through the UI, the ingestion API and MCP. |
| Token theft or misuse | Ingestion tokens (`sym_…`) and MCP tokens (`smcp_…`) have about 190 and 238 bits of entropy, are shown once and stored only as SHA-256. Tokens can be revoked; revoked tokens are refused immediately. MCP tokens are scoped (`read`, `write`); `start_investigation` needs `write`. Both APIs are rate-limited per token. |
| Abuse of the MCP endpoint | `/mcp` requires a bearer token (401 otherwise) and accepts localhost by default. Every call is audited with tool, argument summary, result size, duration and transport. Errors return safe messages, never stack traces. |
| CSRF, session fixation, XSS | Symfony form login with CSRF; every state-changing form posts a CSRF token; Twig auto-escaping; no inline user HTML. |
| Secrets in the repository | AI keys and local overrides live in `.env.local` (git-ignored). Provider keys and tokens are never logged; the settings page shows only whether a key is set. |

## Data retention

Each workspace sets how long events are kept (default 30 days). A daily job deletes expired events,
except those that belong to an incident or are cited as investigation evidence. Run it by hand with
`php bin/console symtony:retention:purge --dry-run`.

## Audit log

Append-only rows for token creation and revocation, MCP calls, investigations, test runs, incident
transitions and settings changes. Owners read it under **Settings → Audit log**.

## Known limitations

- The local demo account's password is in `.env` so the demo works out of the box. Change it in
  `.env.local` (`SYMTONY_DEMO_PASSWORD`) before exposing an instance to anyone else.
- The dev server speaks plain HTTP on localhost. Put a TLS proxy in front of any shared deployment.
- Redaction is pattern-based; it reduces risk but cannot guarantee that free text contains no personal data.

## Reporting a vulnerability

Please open a private security advisory on the GitHub repository (Security → Report a vulnerability)
rather than a public issue. Include steps to reproduce and the affected version or commit.
