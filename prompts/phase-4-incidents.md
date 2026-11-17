# Phase 4 — Incident detection, Event Explorer, Overview

## A. Grouping engine (deterministic — no LLM)

- `Fingerprinter`: sha256 of (application, service, exception_class, normalized_message,
  top N in-app stack frames as `class::function` without line numbers). Configurable N.
  Non-exception error logs fingerprint on (service, normalized_message).
- `IncidentGrouper` (Messenger handler for `EventsIngested`): find open incident by fingerprint;
  if found → link event, bump count/last_seen; if resolved and new occurrence → **Reopened** (+ audit
  + notification later); else create. Use a unique index on (application, fingerprint, open-flag)
  and `INSERT ... ON CONFLICT` or a lock so concurrent workers can't duplicate. Handler is idempotent
  (re-delivered message doesn't double count).
- `Incident`: public short id (`SYM-142`), title (exception class + normalized message, editable),
  severity (derived: unhandled 5xx exception = high; configurable rules), status (Workflow component:
  new → investigating → identified → resolved → reopened), fingerprint, first/last seen, count,
  affected services (computed), assignee, notes (`IncidentNote` entity).
- `IncidentEvent` join with role (`occurrence`, `context`).

## B. Incidents UI

- List: DataTable with search, severity/status/app filters, sort, pagination, investigation status
  column. Filters live in the URL (shareable).
- Detail: header (title, badges, app, first/last seen, count sparkline from real counts), tabs:
  **Timeline** (Phase 5), **Events**, **Investigations**, **Notes**, **Related** (same exception class or
  shared trace — label why it's related). Actions: assign, change status (only valid transitions
  enabled), add note. ("Start investigation" is added in Phase 6 — don't render it before it works.)

## C. Event Explorer

- Split pane: left list (virtualized or paged, cursor = (occurred_at, id)), right detail.
- Filters: text, time range (from topbar), severity, service, application, type, request_id, trace_id.
- Detail: KeyValueList, pretty stack trace (in-app frames emphasized, vendor collapsed), raw JSON
  (highlighted, copy), related events (same trace/request), copy event/request/trace ID,
  "Open in timeline". Deep-linkable `?event=<id>`.

## D. Overview

- Metrics only from queries: connected apps, open incidents, critical incidents, events in range,
  investigations completed, median investigation duration (shown as "—" when there are none).
- Incident activity chart for 1h / 24h / 7d / 30d (bucketed SQL, `date_bin`).
- Service health table (errors in range, error rate, last event).
- Recent incidents (top 8), active investigations, activity stream (from AuditLog + domain events).
- No apps → full onboarding empty state with "Connect application" + "Try the demo".

## Performance

Add a dev command that bulk-loads 100k synthetic events **into a separate perf workspace only**
(never shown in the demo workspace). Assert in functional tests: query count on incident list,
event explorer and overview ≤ fixed budget. Measure p95 and record results in PLAN.md phase log.

## Tests

Fingerprint stability (line-number changes don't change fingerprint; different exception does),
grouping (1000 → 1), concurrency (two workers), reopen on new occurrence, status workflow guards,
workspace isolation on every list.

Commit: `feat: incident grouping, event explorer and overview`.
