# Architecture

SYMTONY is one Symfony 7.4 application (PHP 8.4, FrankenPHP) plus three supporting pieces: an installable
bridge bundle, a demo shop with a real bug, and an isolated test runner. PostgreSQL 16 (with pgvector
available) stores everything; Symfony Messenger with the Doctrine transport does the background work.

```
 your Symfony app                         SYMTONY (app container)                         worker container
 ┌──────────────────┐  POST /api/v1/events ┌────────────────────────┐  EventsIngested   ┌──────────────────────┐
 │ symtony-bundle   │ ───────────────────► │ Ingestion              │ ────────────────► │ IncidentGrouper      │
 │ kernel, DBAL,    │  Bearer sym_…        │ validate → redact →    │  (async)          │ fingerprint, lock,   │
 │ HttpClient,      │                      │ normalize → persist    │                   │ link, reopen, notify │
 │ cache, Messenger,│                      └────────────────────────┘                   │                      │
 │ Monolog          │                      ┌────────────────────────┐  RunInvestigation │ InvestigationRunner  │
 └──────────────────┘                      │ UI (Twig, Turbo,       │ ────────────────► │ 7 stages, committed  │
                                           │ Stimulus), MCP server  │  (investigations) │ as they complete     │
 Claude Code / MCP client ───────────────► │ /mcp (Streamable HTTP) │                   │                      │
   Bearer smcp_…  or STDIO                 │ bin/console mcp:server │  CollectTestResults│ TestLab.collect      │
                                           └──────────┬─────────────┘ ────────────────► │ scheduler: silent    │
                                                      │ job dir (volume /jobs)          │ apps, retention      │
                                                      ▼                                 └──────────────────────┘
                                           ┌────────────────────────┐
                                           │ runner: network none,  │  copies demo-shop, applies the reference
                                           │ read-only, uid 65534,  │  patch for the "fixed" variant, runs PHPUnit,
                                           │ 1 CPU, 768 MB, timeout │  writes result.json
                                           └────────────────────────┘
```

## Contexts (`src/`)

| Layer | Contents |
|---|---|
| `Domain/` | Entities and enums per context: Workspace, Application, Event, Incident, Investigation (steps, evidence items, findings), TestLab (regression tests, executions), Mcp (tokens), Assistant, Notification, Audit. |
| `Application/` | Use cases and queries: ingestion handlers, grouping, timeline, investigation pipeline and analyzers, knowledge retrieval, Test Lab, notifications, retention, settings, palette search. |
| `Infrastructure/` | Ingestion (parser, redactor, tokens), AI (settings, agents, evidence tools), MCP (gate, auth, presenter, tokens), Security (workspace context, voter), Demo, Persistence. |
| `Mcp/` | The MCP tools, resources and prompts (the only namespace the MCP registry scans). |
| `Controller/` | Thin controllers; every list query is workspace-scoped and paginated. |

Every query starts from `WorkspaceContext::workspace()` (UI) or the token's workspace (MCP, ingestion).
Functional tests prove workspace A cannot read workspace B through the UI, the API or MCP.

## Ingestion

`POST /api/v1/events` with a per-application token (`sym_<env>_<32 chars>`, stored as SHA-256). One object
or up to 500 events per request; 1 MB body limit; per-token token-bucket rate limit; RFC 9457 errors.
Each event is validated, **redacted** (sensitive keys and value patterns such as card numbers and bearer
tokens, plus the workspace's own keys), normalized (message templates such as `{n}` for numbers, so
grouping is stable) and stored with its trace, span, parent span and request ids. Client ids make
re-sends idempotent. Then `EventsIngested` is queued.

## Grouping

`Fingerprinter`: SHA-256 of application, service, exception class, normalized message and the top three
in-app frames (instrumentation frames ignored, closure line numbers stripped). `IncidentGrouper` takes a
Postgres advisory lock per fingerprint, creates the incident under a unique `(application, fingerprint)`
constraint, and links events with `INSERT … ON CONFLICT DO NOTHING`, counting only rows really inserted.
A test races two real worker processes over the same backlog: exactly one incident, every event counted
once. A new occurrence after resolution reopens the incident (Symfony Workflow).

## Correlation and the timeline

`Correlator` decides what belongs to an occurrence:

- **Proven** (solid lines): same trace id or request id; parent-span links; cross-service spans that
  share the trace (W3C `traceparent` propagated by the bundle).
- **Inferred** (dashed, labelled): events from *other* traces of the same application in a window around
  the failure (writes, logs) and the latest deploy within 30 minutes. They carry the reason
  ("same application, 3.0 s before the request, no shared trace or request id").

`TimelineBuilder` orders items into lanes (`service/component`), marks the first failure (the earliest
failing leaf span; the request root is skipped when inner failures exist) and caps at 2,000 events with
a visible truncation notice. The SVG swimlanes (`timeline_controller.js`) support zoom, pan, minimap,
errors-only, service filters and keyboard navigation; small screens get an ordered list.

## Investigation pipeline

`RunInvestigation` runs in the `investigations` queue. Each stage is committed when it really finishes,
so the progress UI never fakes progress; a failed stage shows its error.

1. **Collect evidence**: the incident and its latest occurrence.
2. **Reconstruct timeline** → numbered evidence items `EV-n` (inferred context capped at 12 + latest deploy).
3. **Analyze**: deterministic analyzers (`symtony.analyzer` tag): constraint + rollback, side effect before
   failure (e.g. payment authorized, then rollback), stale cache vs. a later write, deploy proximity,
   occurrence pattern. Source label `heuristic:<analyzer>`.
4. **Review history**: resolved incidents with similar terms (Postgres full-text, `ts_rank`), cited as
   `history` evidence.
5. **Generate hypotheses** with the `investigator` agent (Symfony AI, Hugging Face). The model reads
   evidence only through read-only tools (`evidence_list`, `evidence_get`, `similar_incidents`) and must
   answer with JSON. Skipped, and labelled skipped, without a key. Source `llm:<platform>/<model>`.
6. **Validate**: `GroundingValidator` rejects findings citing unknown refs, findings without citations,
   facts supported only by inferred context, and quoted durations that do not match a cited item.
   Rejected findings are stored and shown as rejected; they never reach the summary.
7. **Report**: composed from verified findings only; Markdown export.

## Test Lab

A proposal comes from the model (`test_writer` agent; it can read allow-listed source files) or from a
template for known failure shapes (labelled `template:…`, never as model output). `StaticChecker` parses
the code (never executes it) and rejects forbidden calls (shell, eval, network, file writes, dynamic
calls), wrong namespace/class/base class. A human must review it. *Prove it* writes two jobs (buggy,
fixed) to the shared `/jobs` volume; the runner claims each, copies the shop into a tmpfs, applies the
reference patch for the fixed variant, runs PHPUnit with JUnit output and writes `result.json`. The
verdict comes only from the latest batch's real runs: verified reproduction (red, then green), does not
reproduce, fix not confirmed, runner error.

## MCP layer

`symfony/mcp-bundle` on `mcp/sdk`, Streamable HTTP at `/mcp` and STDIO via `bin/console mcp:server`.
`McpAuthSubscriber` requires a bearer token on `/mcp`; STDIO reads `SYMTONY_MCP_TOKEN`. Every tool,
resource and prompt runs through `McpGate`: resolve the token, check scope (`read`, `write`), rate-limit
per token, run inside the token's workspace, re-redact the output, audit the call, convert failures to
safe MCP errors. See [docs/mcp.md](docs/mcp.md).

## Frontend

Twig components, AssetMapper, Stimulus and Turbo; Tailwind 4 with only the design tokens from
`assets/styles/tokens.css` (a CI guard rejects raw hex and px values in templates). uPlot for charts,
highlight.js for code, Lucide icons. No Node build.

## Key decisions

- **Evidence before AI.** Deterministic analyzers produce a complete report without a model; the model
  adds hypotheses that must pass the same validator.
- **Honest status everywhere.** No number on screen without a query behind it; skipped and failed stages
  are shown as such; a test is proof only after a real red and green run.
- **One process per concern.** The web app never runs AI-written code; the runner has no network.
- **Postgres for everything** (events, queues, locks, full-text search) to keep the demo one `make up`.
