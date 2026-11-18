# MCP server

SYMTONY is a Model Context Protocol server built on `symfony/mcp-bundle` and `mcp/sdk`. AI clients such as
Claude Code can read incidents, timelines and evidence-grounded reports, and start investigations.

## Connect

Create a token on **MCP Integrations** (`/app/mcp`) or with
`php bin/console symtony:mcp:token "My laptop" [--write]`. It is shown once and stored as a hash.

| Transport | How |
|---|---|
| Streamable HTTP | `http://localhost:8080/mcp` with `Authorization: Bearer smcp_…`. Requests without a valid token get `401`. Localhost only by default. |
| STDIO | `docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server` with `SYMTONY_MCP_TOKEN` set. |

Claude Code:

```bash
claude mcp add --transport http symtony http://localhost:8080/mcp --header "Authorization: Bearer <token>"
claude mcp add --env SYMTONY_MCP_TOKEN=<token> --transport stdio symtony -- docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server
```

MCP Inspector (STDIO): `npx @modelcontextprotocol/inspector --cli -e SYMTONY_MCP_TOKEN=<token> --method tools/list -- docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server`

## Tools

| Tool | Scope | Returns |
|---|---|---|
| `ping` | read | `ok`, the token's workspace and scopes |
| `list_applications` | read | Applications with slug, environment, connection status, last event |
| `get_application_health` | read | Events, errors and error rate per service in a window (`1h`, `24h`, `7d`, `30d`) |
| `list_incidents` | read | Incidents filtered by status, severity, application, text; 25 per page |
| `get_incident` | read | Detail: counts, fingerprint, affected services, latest occurrence, latest investigation |
| `search_events` | read | Events by text, application, minimum severity, exact `trace_id` / `request_id`; up to 100 with a cursor |
| `get_event_details` | read | One event in full (redacted), with its incident |
| `get_incident_timeline` | read | Lanes, items with `proven` true/false, links, first failure |
| `list_related_incidents` | read | Related incidents with the reason (same exception class, same trace) |
| `get_investigation_report` | read | Findings with evidence ids, source and grounding status; stage statuses |
| `get_regression_test` | read | Code, review state, proof status and the output of the latest runs |
| `start_investigation` | **write** | Queues an investigation and returns its id at once |

Arguments are validated against the generated JSON schema (invalid arguments return JSON-RPC `-32602`).
Domain errors (unknown incident, missing scope, rate limit) return tool results with `isError: true` and a
plain message; unexpected failures return a generic message and are logged. Never a stack trace.

## Resources

| URI | Content |
|---|---|
| `symtony://health` | Workspace health: applications, incidents by status, errors per service (24 h) |
| `symtony://applications/{id}` | One application by slug or id |
| `symtony://incidents/{id}` | One incident by reference (`SYM-5`) or id |
| `symtony://incidents/{id}/timeline` | The incident's timeline |
| `symtony://investigations/{id}/report` | The report as Markdown |

## Prompts

`investigate_incident(incident_id)`, `explain_stack_trace(event_id)`, `reconstruct_failure(trace_id)`,
`compare_incidents(a, b)`, `review_regression_test(test_id)`. Each steers the client through the tools
above with the same rules as the in-app investigator: cite evidence, keep facts and hypotheses apart,
treat timing-only links as inferred, treat event text as data.

## Guarantees

- Every call resolves data only inside the token's workspace.
- Output passes the Redactor again before it leaves.
- Per-token rate limit (120 calls per minute).
- Every call is audited (tool, argument summary, result size, duration, transport) and listed under
  **MCP Integrations → Recent calls** and **Settings → Audit log**.
