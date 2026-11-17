# Phase 8 — SYMTONY as a real MCP server

Use `symfony/mcp-bundle` + `mcp/sdk` exactly as recorded in CLAUDE.md §8. No hand-rolled JSON-RPC.

## Tools (typed args, validated, structured results, clear descriptions written for an AI caller)

| Tool | Scope | Notes |
|---|---|---|
| `list_applications` | read | name, id, status, last event |
| `get_application_health` | read | errors/rate per service in a time range |
| `list_incidents` | read | filters: app, status, severity, since; paginated |
| `get_incident` | read | summary, counts, fingerprint, latest investigation id |
| `search_events` | read | text, app, severity, trace_id, request_id, time range; max 100, cursor |
| `get_event_details` | read | full redacted event |
| `get_incident_timeline` | read | lanes, events, links with `proven` flag |
| `list_related_incidents` | read | with the reason for each relation |
| `get_investigation_report` | read | findings with citations + grounding status |
| `get_regression_test` | read | code, status, last execution result |
| `start_investigation` | **write** | requires write-scoped token; returns investigation id; async |

## Resources

`symtony://applications/{id}`, `symtony://incidents/{id}`, `symtony://investigations/{id}/report`
(Markdown), `symtony://incidents/{id}/timeline`, `symtony://health`.

## Prompts

`investigate_incident(incident_id)`, `explain_stack_trace(event_id)`,
`reconstruct_failure(trace_id)`, `compare_incidents(a, b)`, `review_regression_test(test_id)`.

## Security

- `McpToken` entity: workspace, label, scopes (`read`, `write`), hash, last used, revoked. Managed on
  the MCP Integrations page (shown once).
- HTTP transport: bearer token → workspace; STDIO: token from env `SYMTONY_MCP_TOKEN`.
- Every tool resolves data **only** inside the token's workspace (repository-level scoping; test it).
- Rate limit per token. Audit log row for every call (tool, args summary, result size, duration).
- Errors returned as MCP tool errors with safe messages — no stack traces.

## MCP Integrations page

- Status of both transports, live list of registered tools/resources/prompts (read from the server's
  registry, not a hardcoded list), token management, copyable configs:
  - Claude Code: `claude mcp add --transport http symtony https://localhost/_mcp --header "Authorization: Bearer <token>"`
    (verify the exact flag syntax against current Claude Code docs) and the STDIO variant via
    `docker compose exec -T app php bin/console <cmd>`.
  - Claude Desktop / generic JSON config.
- Recent MCP calls table from the audit log.

## Tests & verification

Integration tests invoking tools through the SDK's server/client test utilities (or a real STDIO
session): tool discovery, each read tool on demo data, cross-workspace access denied, read token
cannot call `start_investigation`, invalid args → validation error. Manually verify with MCP Inspector
and with Claude Code asking "Why did checkout fail in the demo shop?" — record the transcript summary
in the PLAN.md phase log.

Commit: `feat: MCP server with tools, resources and prompts`.
