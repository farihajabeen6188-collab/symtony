# SYMTONY — Engineering Rules

> **Every error has a story.** SYMTONY is an AI-powered application forensics platform for Symfony apps:
> it ingests events, groups failures into incidents, reconstructs what happened, investigates with
> evidence-cited AI, and proves its regression tests reproduce the bug.

This file holds the **permanent rules**. They apply to every phase. The plan and progress live in
`PLAN.md`; the per-phase work orders live in `prompts/`.

---

## 1. Trust rules (never broken)

1. **Never fabricate** logs, events, traces, metrics, similarity scores, test results or AI output.
   A UI number must come from a database query. If there is no data, show an empty state.
2. **Facts vs. inferences vs. hypotheses** are separate types in code (`ObservedFact`, `Inference`,
   `Hypothesis`) and separate visual styles in the UI. Never mix them in one string.
3. **Every finding cites event IDs.** The `GroundingValidator` rejects any finding whose cited IDs do
   not exist in the investigation's evidence package. Rejected claims are stored and shown as rejected.
4. **Correlation ≠ causation.** Links from trace/span/request IDs are *proven* (solid lines). Links
   from time proximity are *inferred* (dashed lines, labeled "inferred").
5. **Heuristic output is never labeled as LLM output.** Every finding records its `source`:
   `heuristic:<analyzer>` or `llm:<provider>/<model>`.
6. **A test is "Verified reproduction" only when** the isolated runner executed it, it **failed** on the
   buggy code, and that run's real output is stored. Otherwise it is "Unverified proposal".
7. **Failure is shown, not hidden.** A failed investigation stage displays the error. No fake progress
   bars; stages update only when backend work actually completes.
8. **No secrets reach an LLM.** Evidence passes through `Redactor` before leaving the process.
   Never log provider API keys or ingestion tokens.
9. **The model has no shell, no filesystem, no raw SQL.** It only calls registered, read-only,
   workspace-scoped tools. AI-generated code is never executed in the app process.

## 2. Pinned stack (do not swap without updating this file)

| Concern | Choice |
|---|---|
| Runtime | PHP 8.4, FrankenPHP in Docker |
| Framework | Symfony 7.4 LTS (confirmed compatible with `symfony/ai-*` and `symfony/mcp-bundle` 1.0.0-BETA1 — see §8) |
| URL (dev) | http://localhost:8080 (plain HTTP to avoid local certificate issues; port via `SYMTONY_HTTP_PORT`) |
| DB | PostgreSQL 16 + pgvector |
| ORM | Doctrine ORM + migrations |
| Async | Symfony Messenger, Doctrine transport (`async`, `investigations`, `failed`) |
| AI | `symfony/ai-bundle` (Platform, Agent, Store). Provider #1: Hugging Face Inference Providers. Provider #2 (fallback): configurable (OpenAI / Anthropic / Ollama) behind the same config |
| MCP | `symfony/mcp-bundle` on the official `mcp/sdk`. Transports: STDIO + Streamable HTTP |
| Frontend | Twig + AssetMapper + Stimulus + Turbo + `symfonycasts/tailwind-bundle`. **No Node build, no React.** |
| Icons | Lucide (SVG via `symfony/ux-icons`) |
| Charts | One library only, chosen in Phase 1 and recorded in §8 |
| Fonts | Geist Sans + JetBrains Mono, self-hosted under `assets/fonts/` |
| Tests | PHPUnit, Symfony WebTestCase, Zenstruck Foundry for factories |

**Beta APIs:** Symfony AI and the MCP bundle move fast. Before using any class, attribute or config key,
read it in `vendor/` (or the package's official docs). Never guess a signature. Record verified API
notes in §8.

## 3. Repository layout

```
/                     SYMTONY (Symfony app)
  src/
    Domain/           Entities, enums, value objects per context (Event, Incident, Investigation, ...)
    Application/      Use-case handlers, queries, DTOs
    Infrastructure/   AI/, MCP/, Ingestion/, Persistence/, Security/, TestRunner/
    Controller/       Thin controllers only
  templates/components/   Twig components (the design system)
  tests/Unit | Integration | Functional
packages/symtony-bundle/  Installable bridge bundle (Monolog, kernel, DBAL, HttpClient, Messenger)
demo-shop/                Separate tiny Symfony app with a REAL bug, instrumented by the bundle
runner/                   Isolated PHPUnit runner image (network_mode: none)
prompts/                  Phase work orders
```

Adapt only if Symfony conventions demand it. Do not create empty layers for appearance.

## 4. Code conventions

- `declare(strict_types=1);` everywhere. Typed properties, enums for states/severities, `readonly` DTOs.
- Controllers ≤ ~40 lines; logic lives in handlers/services. No business logic in Twig or Stimulus.
- No silent `catch`. Log + rethrow or convert to a domain error that the UI shows.
- Every query that lists data is paginated (cursor-based for events) and indexed. No N+1:
  functional tests assert query counts on list pages.
- Workspace isolation: every repository query is scoped by workspace. There is a test that proves
  workspace A cannot read workspace B through UI, API or MCP.
- All external AI calls go through `Symtony\Infrastructure\AI\` interfaces so tests can swap a fake
  platform. **Tests never call paid APIs.**

## 5. Design system rules

- All colors, spacing, radii and type come from tokens in `assets/styles/tokens.css`.
  Never write a raw hex value or arbitrary px value in a template.
- Palette: bg `#0D1424`, deep `#0B1020`, surface `#141E30`, surface-2 `#19263A`, cyan `#66D9FF`
  (primary/interactive only), blue `#7CA9FF`, violet `#A78BFA` (AI-generated content only),
  success `#36C99A`, warning `#F2B966`, error `#FF6B79`, text `#EDF3FC`, text-2 `#9BAAC0`.
- **Color has meaning:** violet = AI, cyan = interactive, dashed = inferred. Don't use accents decoratively.
- 4px spacing grid. Dark mode is the primary design; light mode is a separate token set, not an inversion.
- Density and tone: Linear-level density, Sentry-like issue layout, Honeycomb/Jaeger-like span waterfall —
  as inspiration, never copies. No glassmorphism, no gradient blobs, no emoji as icons.
- Every new component appears on `/styleguide` first.
- Motion ≤ 200ms, respects `prefers-reduced-motion`. Visible keyboard focus everywhere.
- Every button works. Every link leads somewhere real. If a feature is cut, its control is removed.

## 6. Commands

```bash
make up          # docker compose up -d (app, db, worker, runner)
make demo        # reset DB, migrate, seed demo data, boot demo-shop
make test        # full PHPUnit suite
make check       # lint:container, lint:twig, lint:yaml, debug:router sanity, phpstan, tests
make shell       # shell into the app container
```

The user develops on **Windows with Docker Desktop** and has no `make` installed: `./dev.ps1 <target>`
(PowerShell) mirrors every Makefile target. Keep both in sync. Also: `make spike-ai`, `make mcp-stdio`,
`make console c="..."`, `make composer c="..."`.

## 7. How to work

1. Read `PLAN.md`, find the current phase, read its file in `prompts/`.
2. Implement in small steps. After each step: `make check`.
3. For UI work, open the page in the browser tool at 1440px and 390px, check the console for errors, fix issues.
4. When every acceptance check of the phase passes: tick it in `PLAN.md`, add a short entry to the
   phase log, commit (`git commit`) with a clear message.
5. **Never mark a check done that you did not run.** If something is blocked, write it under
   "Blockers" in `PLAN.md` and say so plainly.
6. Prefer a simpler feature that works perfectly over a sophisticated one that barely works.
7. If a decision changes the stack or a rule here, update this file in the same commit.

## 8. Verified API notes (filled in during development)

Verified 2026-11-18 by reading `vendor/` and running the code.

**Versions:** PHP 8.4.27 (FrankenPHP), Symfony 7.4.23, Doctrine ORM 3.8, PHPUnit 13.4, PHPStan 2.x (level 6).
`symfony/ai-bundle|ai-platform|ai-agent|ai-store|ai-hugging-face-platform|ai-generic-platform` **v1.0.0-BETA1**,
`symfony/mcp-bundle` **v1.0.0-BETA1**, `mcp/sdk` **v0.9.0**. `nyholm/psr7` is required (the MCP HTTP
controller needs a PSR-17 factory).

**Symfony AI**
- Platform config: `ai.platform.huggingface: { api_key, provider }` → service `ai.platform.huggingface`.
  `provider` pins a HF inference provider (sent as `model:provider`); null lets HF route.
  Fallback for any OpenAI-compatible API: `ai.platform.generic.<name>: { base_url, api_key }`.
- Model capabilities come from the bridge's `ModelCatalog` (`Capability::TOOL_CALLING`,
  `Capability::OUTPUT_STRUCTURED`). Chat models flagged with both include `Qwen/Qwen3.5-*`, `Qwen/Qwen3-*`,
  `deepseek-ai/DeepSeek-V4-*`, `google/gemma-4-*`. CLI helpers: `ai:huggingface:model-list`/`model-info`
  (need registering), `ai:platform:invoke`, `ai:agent:call`.
- Agent config: `ai.agent.<name>: { platform, model, prompt: { text|file }, tools: { services: [...] }, max_tool_calls, fault_tolerant_toolbox }`.
  Service id `ai.agent.<name>` (`AgentInterface`); inject with `#[Autowire(service: 'ai.agent.<name>')]`.
  Tools are **opt-in**: list services under `tools.services` (or `tools: true` for every `ai.tool`-tagged service).
- Tool: class attribute `Symfony\AI\Agent\Toolbox\Attribute\AsTool(name, description, method = '__invoke')`;
  argument schema comes from the method signature + docblock.
- Call: `$agent->call(MessageBag|string, array $options): ExecutionInterface` → `asText()`, `asObject()`,
  `getMessages()` (contains `ToolCallMessage`s), `getMetadata()`.
- Structured output: option `['response_format' => Dto::class]` → `asObject()` returns the DTO.
  Throws `MissingModelSupportException` if the model lacks `OUTPUT_STRUCTURED`.
- Messages: `Message::forSystem()`, `Message::ofUser()`, `new MessageBag(...)`.
- Tests: `Symfony\AI\Platform\Test\MockPlatformFactory::createPlatform($responses)` and
  `Symfony\AI\Agent\MockAgent` (`addResponse`, `assertCalledWith`, …). Never call a paid API in tests.

**MCP (`symfony/mcp-bundle` + `mcp/sdk`)**
- Attributes (method or invokable class): `Mcp\Capability\Attribute\McpTool(name, title, description, annotations, outputSchema)`,
  `McpResource(uri, name, description, mimeType)`, `McpResourceTemplate(uriTemplate, ...)`, `McpPrompt(name, description)`.
  Autoconfigured; registry restricted to `App\Mcp\` in `config/packages/mcp.yaml`.
- Returning an array from a tool yields both `content[text]` (JSON) and `structuredContent`.
- Transports: STDIO `php bin/console mcp:server`; Streamable HTTP at `/mcp` (route `_mcp_endpoint_default`),
  localhost-only by default (`http.allowed_hosts` to widen). Sessions: file store under var/cache.
- Introspection: `php bin/console debug:mcp`.
- Verified: MCP Inspector CLI over STDIO (`tools/list`, `tools/call ping`) and raw JSON-RPC over HTTP
  (`initialize` → `Mcp-Session-Id` → `notifications/initialized` 202 → `tools/list` → `tools/call`).
- Errors: throw `Mcp\Exception\ToolCallException` (→ `isError: true` result with the message),
  `ResourceReadException`, `PromptGetException`. Any other exception becomes a generic internal error.
  Argument validation against the generated JSON schema happens before the handler (`-32602`).
  `#[Schema(enum:, minimum:, maximum:)]` on parameters; `new ToolAnnotations(readOnlyHint: ...)` in `#[McpTool]`.
- Registry is filled when the server is built: inject `mcp.server.default` before reading
  `mcp.server.default.registry` (`getTools()`, `getResources()`, `getResourceTemplates()`, `getPrompts()`).
- SYMTONY auth: `McpAuthSubscriber` (kernel.request on `/mcp`) requires `Authorization: Bearer smcp_…` → 401
  otherwise; STDIO reads `SYMTONY_MCP_TOKEN`. Every handler runs through `McpGate::run()` (scope, rate limit
  `limiter.mcp`, workspace, audit `mcp.call`, safe errors). MCP Inspector CLI with a token:
  `npx @modelcontextprotocol/inspector --cli -e SYMTONY_MCP_TOKEN=… --method tools/list -- docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server`.
- Claude Code syntax (docs, 2026-11): `claude mcp add --transport http <name> <url> --header "Authorization: Bearer …"`;
  STDIO `claude mcp add --env K=V --transport stdio <name> -- <command…>`.
- Long-running processes: services holding per-request state implement `ResetInterface` (e.g. `WorkspaceContext`).

**Background work and data (Phases 9–10)**
- Worker consumes `async`, `investigations` and `scheduler_default` (`src/Schedule.php`: silent-application
  check every 5 min, retention purge daily). `make up` / `./dev.ps1 up` start the worker and the runner.
- Notifications are created per workspace member by `Notifier` (deduplicated by key); the bell refreshes on
  navigation, not in real time.
- Landing hero data: `php bin/console symtony:landing:export-timeline` writes `config/landing/demo-timeline.json`
  from the demo SYM-5 run (redacted). Re-run after changing the demo.
- Tests that start `bin/console` subprocesses must run in debug mode: a `--no-debug` test container is never
  rebuilt and silently runs old code.
- Knowledge retrieval is keyword (Postgres full-text, `ts_rank`). Embeddings in pgvector are deferred until an
  embedding provider can be verified.

**AI spike result:** NOT YET TESTED — no `HUGGINGFACE_API_KEY` configured. Run `make spike-ai`
(`./dev.ps1 spike-ai`) after adding the key to `.env.local`. Default model candidate: `Qwen/Qwen3.5-35B-A3B`.

**Docker on Windows:** `vendor/` and `var/` live in named volumes (a bind-mounted `vendor/` made every
request ~11 s; with volumes ~0.3 s). Run Composer **inside** the container. The host `vendor/` folder
is not used by the app.

**Chart library:** uPlot 1.6 (importmap), wrapped by `chart_controller.js`; colors read from tokens at draw time.
**Code highlighting:** highlight.js core + php/json via `code_controller.js`.
**Tailwind:** v4.3 via symfonycasts/tailwind-bundle; tokens mapped in `assets/styles/app.css` `@theme inline`
with `--color-*: initial` (only token colors exist). Classes built dynamically in Twig must be listed in
`@source inline(...)`. Run `tailwind:build` after template changes (or `tailwind:build --watch`).
**Brand mark:** `templates/components/Logo.html.twig` — the investigator (hat + glasses) vectorised from the
user-supplied artwork. `motion="hover|scan|none"`. Use `scan` (or `<twig:Loader>`) only for real work in
progress. Standalone files: `public/favicon.svg`, `public/brand/symtony-mark.svg`.
**Auth:** demo account from `SYMTONY_DEMO_EMAIL` / `SYMTONY_DEMO_PASSWORD` via `symtony:setup`.
`WorkspaceContext::workspace()` is the entry point for every scoped query; `WorkspaceVoter` for subjects.
