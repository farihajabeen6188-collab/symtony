# Phase 3 — symtony-bundle + demo-shop (the real failure)

This phase is what makes the demo genuine: events come from a real running app with a real bug.
Read the scenario in `PLAN.md` ("The demo scenario") — implement exactly that story.

## A. `packages/symtony-bundle` (installable via Composer path repository; later publishable)

- Config `symtony: { dsn: '%env(SYMTONY_DSN)%', service: 'shop', release: '%env(APP_RELEASE)%', redact: [...] , sample_rate: 1.0 }`
  where DSN = `https://sym_..@host/app-id` or endpoint + token — pick one, document it.
- **Trace context**: generate `trace_id`/`span_id` per request (honor incoming `traceparent` header
  if present — W3C format), keep a span stack; `request_id` from `X-Request-Id` or generated.
- **Collectors**:
  - Kernel subscriber: request received, controller resolved (with controller name), response sent
    (status, duration), exception (class, message, frames).
  - DBAL middleware: each query as a child span (SQL with parameters **stripped**, duration, rows
    affected if available), transaction begin/commit/rollback events.
  - HttpClient decorator: external call spans (method, host, path, status, duration); propagates `traceparent`.
  - Cache: decorator for the app's cache pool emitting hit/miss + item age when available.
  - Messenger middleware: dispatch/handle spans, propagate trace via stamp.
  - Monolog handler: log records as `log` events linked to the current span.
- **Transport**: buffer events in memory, flush on `kernel.terminate` (and console terminate) as one
  batch to `/api/v1/events`. Short timeout; never throw into the host app; on failure write a single
  warning to the host app's log. The bundle must not slow down or break the host app.
- Deploy marker: console command `symtony:release` sends a `deploy` event (release, commit SHA, author).
- Unit tests for trace propagation, SQL parameter stripping, transport failure isolation.

## B. `demo-shop/` (separate small Symfony app, own compose service, SQLite or its own Postgres schema)

- Products, cart, `POST /checkout`, simple token auth, a mock **payment API** route (separate service
  or separate controller under `/payment-api` reached via HttpClient — must be a real HTTP call).
- `InventoryService` with the release-1.4.0 stock cache (the bug), `CHECK (stock >= 0)` on the table.
- Checkout order: auth → load cart → read stock (cache, stale) → authorize payment (HTTP) →
  begin transaction → decrement stock (fails) → rollback → 500.
- Installs `symtony/symfony-bundle` through a Composer path repository. Its `.env` points to the
  SYMTONY app inside the compose network.
- `demo-shop/fixes/stale-stock-cache.patch` — the human-written reference fix.
- demo-shop's own PHPUnit suite; one test documents correct checkout behavior.

## C. "Break checkout" control

- In SYMTONY, on the Demo application page: a **"Run failure scenario"** button. It calls a demo-shop
  admin endpoint (internal network, guarded by a shared secret from env) that: sends a deploy event
  for release 1.4.0, warms the cache, reduces real stock, then fires N real checkout requests
  (some succeed, the stale ones fail). The UI says plainly what it does: "Sends real requests to the
  demo shop. Events you see next are what the shop actually emitted."
- `make demo` also seeds the *history* (prior resolved PricingService incident from 3 weeks ago,
  background traffic, a flapping and a reopened incident) by **replaying recorded payloads through
  the public ingestion API with backdated timestamps** — not by inserting rows directly. Label seeded
  history in the UI with a small "seeded" tag.

## Acceptance

- Clicking "Run failure scenario" produces, in SYMTONY's DB, the full evidence trail from PLAN.md
  sharing one trace_id with correct parent/child spans (integration test asserts this).
- Stopping SYMTONY does not break demo-shop checkout (bundle isolation test).
- Applying the patch makes demo-shop's suite green.
- Update the wizard instructions (Phase 2 step 4) to match the real bundle config.
- Commit: `feat: symtony bundle and instrumented demo shop`.
