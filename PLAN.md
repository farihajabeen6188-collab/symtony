# SYMTONY — Build Plan

## The demo spine (nothing else matters until this works end to end)

> A **real** Symfony shop breaks live → SYMTONY ingests its events → groups them into one incident →
> reconstructs the timeline in swimlanes → investigates (heuristics + LLM, every claim cited and
> checked) → finds a similar incident from 3 weeks ago → generates a regression test that
> **fails on the buggy code and passes on the fixed code** in an isolated runner →
> Claude Code asks SYMTONY "why did checkout fail?" over MCP and gets the same answer.

## Hackathon context (fill in before Phase 0)

- Event / judging criteria: `TODO — paste the official criteria here`
- Required technologies: `TODO (e.g. Symfony AI, MCP SDK, Hugging Face?)`
- Deadline: `TODO` · Submission format (video length, repo, live URL): `TODO`

## The demo scenario (one bug, one story)

**Bug:** a release added a stock cache to `InventoryService`. On checkout it reads stock from the
cache (stale), authorizes payment through the external payment API, then decrements stock in the DB.
The DB `CHECK (stock >= 0)` constraint fails → exception → transaction rollback → HTTP 500.
**Impact:** the customer's card was authorized but no order exists (orphaned authorization).

**Evidence trail the real app emits:** deploy event `release 1.4.0` → `POST /checkout` request →
auth → `CheckoutController` → `InventoryService` cache **hit** (age 612 s) → HTTP call to
payment API (200, authorized) → DBAL `UPDATE product SET stock = ...` → `CheckViolationException` →
rollback → 500 response. Everything shares one `trace_id` with parent/child spans.

**History seeded for the knowledge base:** a *resolved* incident from 3 weeks earlier — stale
`PricingService` cache produced wrong totals; resolution note: "invalidate cache on write; read
authoritative value inside the transaction". Plus healthy background traffic, one flapping
low-severity incident and one reopened incident.

**Reference fix:** `demo-shop/fixes/stale-stock-cache.patch`, written by a human (reads stock with
`SELECT ... FOR UPDATE` inside the transaction, validates before authorizing payment). The AI's
recommendation is shown next to it; only the reviewed patch is ever applied.

---

## Milestones

Tick a box only after running the check. Each phase ends with a commit.

### Phase 0 — Foundation & API spike → `prompts/phase-0-foundation.md`
- [x] `git init`, `.gitignore`, `.env.example` (no real secrets)
- [x] `docker compose up` brings up app (FrankenPHP) and Postgres+pgvector; app answers on http://localhost:8080 (worker is behind the `worker` profile until Phase 2 adds messages)
- [x] Symfony + `symfony/ai-bundle` + `symfony/mcp-bundle` installed; versions written to CLAUDE.md §8
- [ ] Spike: one HF model answers a prompt **with a tool call** and **structured output** via Symfony AI (or a documented fallback provider if not) — result in §8 — **blocked: no API key yet**
- [x] Spike: MCP hello tool reachable from `npx @modelcontextprotocol/inspector` over STDIO (and over HTTP)
- [x] `make check` passes (run as `./dev.ps1 check`; `make` itself is not installed on this machine)

### Phase 1 — Design system & app shell → `prompts/phase-1-design-system.md`
- [x] Tokens (dark + light), self-hosted fonts, logo (vectorised investigator mark: full, compact, favicon, scanning loader)
- [x] `/styleguide` shows every component in both themes
- [x] Shell: sidebar (collapsible + mobile drawer), topbar, breadcrumb, time range, working ⌘K palette (navigation). Environment selector deferred to Phase 2 (needs applications)
- [x] Login with seeded user + single workspace; CSRF; workspace voter
- [x] Browser review at desktop and 375px: no overflow, no console errors

### Phase 2 — Data model & ingestion → `prompts/phase-2-ingestion.md`
- [x] Entities + migrations: Workspace, User, Membership, Application, IngestionCredential, Event, IngestionFailure, AuditLog (Incident + IncidentEvent arrive with grouping in Phase 4)
- [x] `POST /api/v1/events` (single + batch): token auth, rate limit, size limit, validation, redaction, normalization
- [x] Malformed payloads return clear 4xx and are recorded as ingestion failures
- [x] Token shown once (`sym_...`), stored hashed, rotation works
- [x] Connect-application wizard (6 steps) with copy buttons; step 5 waits for a real first event
- [x] Tests: normalization, redaction, auth, rate limit, payload limits

### Phase 3 — demo-shop + symtony-bundle → `prompts/phase-3-demo-shop.md`
- [x] `packages/symtony-bundle`: Monolog handler, kernel subscriber, DBAL middleware, HttpClient decorator, Messenger middleware, cache decorator, trace/span propagation (W3C), batched sender after response
- [x] `demo-shop` runs in compose, instrumented by the bundle via `composer require` (path repo)
- [x] "Run failure scenario" triggers the real bug; the full evidence trail arrives in SYMTONY (verified in DB: one trace, parent/child spans, cross-service payment-api hop)
- [x] Reference fix patch exists; demo-shop suite passes on buggy and patched code; a probe regression test is red on buggy and green on patched

### Phase 4 — Incidents, Event Explorer, Overview → `prompts/phase-4-incidents.md`
- [ ] Deterministic fingerprinting; 1,000 identical exceptions ⇒ exactly 1 incident, count 1000 (test)
- [ ] Concurrent ingestion does not create duplicate incidents (unique constraint + test)
- [ ] Incident list (search/filter/sort/pagination) + detail (status workflow, assign, notes, reopen)
- [ ] Event Explorer split pane, cursor pagination, JSON + stack trace highlighting
- [ ] Overview: only real metrics; onboarding empty state when no apps
- [ ] With 100k seeded events: list pages p95 < 300 ms, query count asserted

### Phase 5 — Time-travel reconstruction → `prompts/phase-5-timeline.md`
- [ ] `TimelineBuilder` + `Correlator` (trace → request → parent span → app → time proximity [inferred])
- [ ] Swimlane waterfall: zoom, service/type filters, error highlight, solid vs dashed links, first-failure pin
- [ ] Event detail drawer; prev/next related event navigation
- [ ] Usable at 390px (vertical list mode)
- [ ] Unit tests for correlation rules, including "time proximity is never marked proven"

### Phase 6 — Investigation engine → `prompts/phase-6-investigation.md`
- [ ] Evidence package builder (redacted, size-bounded, every item has an ID)
- [ ] Heuristic analyzers: deploy correlation, constraint violation + rollback, external call before failed write, stale cache read, repeated failure burst
- [ ] Symfony AI agent with read-only tools; structured output → typed DTOs
- [ ] `GroundingValidator` rejects uncited/invalid claims; UI shows "N of M claims verified"
- [ ] Stages run in Messenger and update the UI as they really complete; failures shown
- [ ] Works fully with no API key (heuristic engine, labeled as such)
- [ ] Investigation workspace page + Markdown export
- [ ] Tests with fake platform: parsing, grounding, stage failure handling

### Phase 7 — Regression Test Lab (red → green) → `prompts/phase-7-test-lab.md`
- [ ] Test proposal generated from findings (code + metadata + cited evidence)
- [ ] Isolated runner container (`network_mode: none`, read-only source, CPU/mem/time limits) via a job directory
- [ ] Run against buggy demo-shop ⇒ **red**; against reference fix ⇒ **green**; real output stored
- [ ] UI states: Unverified proposal / Verified reproduction / Does not reproduce / Runner error
- [ ] Download test; execution history

### Phase 8 — MCP server → `prompts/phase-8-mcp.md`
- [ ] Tools: list_applications, get_application_health, list_incidents, get_incident, search_events, get_event_details, get_incident_timeline, start_investigation, get_investigation_report, list_related_incidents, get_regression_test
- [ ] Resources + prompts as listed in the phase file
- [ ] STDIO (`bin/console` command) and Streamable HTTP with bearer token per workspace
- [ ] Workspace scoping, rate limit, audit log for every call; `start_investigation` requires a write-scoped token
- [ ] Verified from MCP Inspector **and** `claude mcp add`; integration tests call tools through the SDK
- [ ] In-app "MCP Integrations" page shows live tool list and copyable client configs

### Phase 9 — Knowledge, assistant, palette, notifications (P1) → `prompts/phase-9-p1.md`
- [ ] Knowledge documents + embeddings in pgvector via Symfony AI Store; keyword fallback; real scores only
- [ ] Seeded prior incident is found during the demo investigation
- [ ] Context-aware assistant panel using the same tools, tool activity visible, answers link to evidence
- [ ] ⌘K palette: navigation + contextual actions, full keyboard support
- [ ] Notifications (DB-backed, read/unread, deep links) — no "real-time" claims unless implemented

### Phase 10 — Landing, docs, QA gate, demo → `prompts/phase-10-ship.md`
- [ ] Landing page from real UI components (no fake logos/testimonials/stats)
- [ ] README (5-minute start), ARCHITECTURE, SECURITY, CONTRIBUTING, `.env.example`
- [ ] Fresh-clone test: `git clone` → `make demo` → full spine works
- [ ] Full quality gate passed (see phase file); all high-priority defects fixed
- [ ] `DEMO.md`: 90-second script + backup plan if the network fails

---

## Cut list (do not build unless every phase above is done)

Workspace invitations & roles UI · avatars · password reset · session management UI · PDF export ·
outbound MCP client · service dependency map · monitoring rules · extra ingestion adapters ·
advanced analytics.

## Phase log

<!-- One entry per completed phase: date, what shipped, test count, known issues. -->

**2026-11-18 — Phase 0 (foundation), all but the AI spike done.** Docker (FrankenPHP PHP 8.4 + pgvector
Postgres 16), Symfony 7.4.23, Symfony AI + MCP bundle 1.0.0-BETA1, `mcp/sdk` 0.9.0. MCP `ping` tool
verified over STDIO (Inspector CLI) and HTTP (JSON-RPC handshake). `./dev.ps1 check` green: lint, PHPStan L6,
2 tests / 10 assertions. Perf fix: `vendor/` + `var/` in Docker volumes (request time 11 s → 0.3 s).
Spike code lives in `src/Spike/` until the AI spike passes, then it is deleted.

**2026-11-18 — Phase 1 (design system & shell).** Tokens (dark + tuned light, Tailwind v4 with the default
palette removed), Geist + JetBrains Mono self-hosted, brand mark vectorised from the user's investigator
artwork (hat + glasses, cyan lens glints; hover tip, scanning loader, reduced-motion safe). 13 Twig
components, 12 Stimulus controllers, uPlot chart, highlight.js. Auth: User/Workspace/Membership entities,
form login with CSRF + throttling, `symtony:setup` demo account. Check green: 20 tests / 79 assertions,
PHPStan L6, template token guard.

**2026-11-18 — Phase 2 (data model & ingestion).** Application, IngestionCredential (sha256, shown once,
rotate/revoke, audited), Event (UUIDv7, jsonb context, 8 indexes, idempotent client ids), IngestionFailure,
AuditLog. `POST /api/v1/events`: bearer auth, per-token token-bucket rate limit, 1 MiB / 500-event limits,
per-field validation, redaction (keys + secret-shaped values + Luhn cards + emails), message normalization,
one transaction per batch, `EventsIngested` to the async queue. 6-step connect wizard with live verification
and a real test event through the public API. Verified in the browser + DB. 53 tests / 201 assertions.

**2026-11-18 — Phase 3 (bundle + demo shop).** `symtony/symfony-bundle`: Tracer API, W3C trace context, kernel /
DBAL / HttpClient / cache / Messenger / Monolog integrations, delivery after the response (no added latency),
per-route service names and ignore. `demo-shop/`: Symfony 7.4 + SQLite shop with the release-1.4.0 stale stock
cache bug; mock payment API traced as service `payment-api`; `/_demo/scenario` reproduces the incident with real
requests (1 × 201, 2 × 500). SYMTONY: `symtony:demo:install` (demo app, token → demo-shop/.env.local, seeded
history replayed through the public API, tagged `seeded`), "Run failure scenario" button. Fixed: event timestamps
now keep microseconds (custom `datetimetz_micro` type). Reference fix `fixes/stale-stock-cache.patch` verified:
probe test red on buggy, green on patched. 64 + 8 tests green.
**Lesson for Phase 7:** a symlinked `vendor/` makes Composer autoload the *original* `src/` — the runner must copy
vendor (`cp -aL`) and `composer dump-autoload` inside the isolated copy, or red/green results are meaningless.

## Blockers

<!-- Anything that stopped progress, with what was tried. -->

- **AI spike (Phase 0):** needs `HUGGINGFACE_API_KEY` in `.env.local`. Then run `./dev.ps1 spike-ai`.
- ~~**First commit:**~~ resolved 2026-11-18: repo-local identity set (name "Symtony"). No remote configured yet.
