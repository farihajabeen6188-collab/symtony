# Phase 2 — Data model & event ingestion

## Schema (Doctrine + migrations)

- `Application` — workspace, name, slug/public id, description, environment (enum), framework (enum),
  status (enum: pending/connected/silent), last_event_at, created_at.
- `IngestionCredential` — application, prefix (first 8 chars, shown in UI), `token_hash`
  (SHA-256), label, created_at, last_used_at, revoked_at. Token format `sym_<env>_<32 random base62>`.
  Shown exactly once.
- `Event` — id (UUIDv7 — time-sortable), application, workspace (denormalized for scoping),
  occurred_at (from payload, microseconds), received_at, severity (enum), service, type (enum:
  http_request, http_response, controller, service_call, db_query, cache, external_http, queue,
  exception, log, deploy, transaction), message, normalized_message, request_id, trace_id, span_id,
  parent_span_id, duration_ms, exception_class, exception_fingerprint, stack_trace (JSON array of
  frames), context (JSONB, redacted), source (sdk/monolog/http/json), release.
  Indexes: (workspace, occurred_at), (application, occurred_at), trace_id, request_id,
  exception_fingerprint, (workspace, severity, occurred_at).
- `IngestionFailure` — application (nullable), received_at, reason, payload excerpt (truncated, redacted).
- `AuditLog` — workspace, actor (user / token / mcp client), action, subject, metadata, at.

## API — `POST /api/v1/events`

- Auth: `Authorization: Bearer sym_...` → hash lookup → application. Constant-time compare.
- Accepts a single event or `{ "events": [...] }` (max 500 per batch). Body max 1 MB (configurable).
- Rate limit per credential (token bucket via RateLimiter). 429 with `Retry-After`.
- Validate with Validator + a documented JSON schema (`docs/event-schema.json`). Unknown fields go
  into `context`. Malformed → 422 with a precise per-field error list; record an `IngestionFailure`.
- **Redactor** (configurable key list + value patterns: password, token, secret, authorization,
  cookie, session, api_key, card numbers, emails optional) runs **before** persistence.
- **Normalizer**: severity mapping (Monolog levels → enum), timestamp parsing (keep original),
  message normalization (strip UUIDs, numbers, quoted values → placeholders) for grouping later.
- Persist in one transaction per batch; then dispatch `EventsIngested` to Messenger (`async`) with the
  event IDs. The handler is a no-op stub now; Phase 4 adds grouping.
- Response 202 `{accepted: n, rejected: [...]}`.

## Connect-application wizard (Applications → "Connect application")

Steps: 1 details → 2 framework & environment → 3 method (Symfony bundle / Monolog HTTP handler /
raw HTTP JSON / Demo app) → 4 instructions (generated for *this* app and token: composer command,
`config/packages/symtony.yaml`, `.env` line, cURL example) with CopyButtons → 5 "Waiting for first
event…" (Turbo polling every 2s against a real endpoint; shows received event when it arrives; has
"Send test event" which performs a real authenticated request through the public API) →
6 success with links to Event Explorer.

The bundle instructions must match what Phase 3 actually implements — revisit this step in Phase 3.

Applications page: list with status dot, last event, credential management (create, rotate =
create new + revoke old, revoke with ConfirmDialog).

## Tests

Unit: Normalizer, Redactor (nested arrays, value patterns), token generation/hash.
Functional: valid single + batch; missing/invalid/revoked token (401); oversize (413); malformed
(422 + failure recorded); rate limit (429); redaction persisted; workspace isolation (token of app A
cannot write to app B); wizard step 5 detects a first event.

## Acceptance

All tests green, wizard walkthrough screenshotted, cURL example from step 4 works when copied
verbatim. Commit: `feat: applications, credentials and event ingestion`.
