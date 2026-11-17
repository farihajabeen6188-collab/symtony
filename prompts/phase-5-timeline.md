# Phase 5 — Time-travel incident reconstruction (signature feature)

This must be the most impressive screen in the product **and** the most honest one.

## Backend

- `TimelineBuilder::forIncident(Incident, window)` and `::forTrace(traceId)`:
  1. Seed events = the incident's occurrence events (pick the most recent occurrence by default;
     allow choosing another occurrence).
  2. Expand through `Correlator` rules, each producing a `Link {from, to, kind, proven: bool, reason}`:
     - same `trace_id` → proven
     - `parent_span_id` → `span_id` → proven (parent/child)
     - same `request_id` → proven
     - same application within ±N seconds, no shared ID → **inferred** ("time proximity")
     - deploy event of the same application before first occurrence → **inferred** ("preceded by deploy")
  3. Output a `Timeline` DTO: lanes (by service), spans (start, end, duration), point events, links,
     first-failure marker (earliest error-severity event in the causal chain), time bounds.
- Hard cap on events per timeline (e.g. 2,000) with an explicit "truncated" flag shown in the UI.
- JSON endpoint for the Stimulus controller + server-rendered fallback list.

## UI (Stimulus + SVG or Canvas; no framework)

- **Swimlanes** by service on Y; time on X with an adaptive tick axis (ms/s/min).
- Spans as bars (duration), point events as markers shaped by type (request, db, cache, external,
  exception, rollback, deploy, log). Exceptions in error color with a glow-free, solid marker.
- **Links**: solid for proven, **dashed + "inferred" label on hover** for inferred. A legend explains this.
- **First failure pin** and a "Jump to first failure" button.
- Zoom (wheel/pinch + range brush on a mini-map), pan, reset. Filters: service, type, "errors only".
- Click → detail drawer (all fields, stack trace, raw JSON, prev/next in chain, "open in Explorer").
- Hover two events → shows Δt between them (compare timestamps).
- Request context panel: method, path, status, duration, user agent, request_id (redacted fields stay redacted).
- Keyboard: ←/→ moves along the chain, Enter opens detail, `E` toggles errors-only.
- **390px**: switches to a vertical list grouped by service with the same markers and link labels.
- Reduced motion: no animated transitions, instant zoom.

## Tests

Correlator unit tests (each rule; **time proximity is never `proven`**; links don't cross workspaces),
TimelineBuilder on the demo trace (asserts exact ordering and the first-failure event), truncation
flag, endpoint access control. Browser check at 1440 and 390 on the real demo incident.

Commit: `feat: time-travel incident reconstruction`.
