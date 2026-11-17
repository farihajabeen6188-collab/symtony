# Phase 9 — Knowledge base, assistant, command palette, notifications (P1)

Only start when Phases 0–8 are all ticked in PLAN.md.

## A. Knowledge base & historical memory

- `KnowledgeDocument` per resolved incident: title, exception pattern, services, root cause, resolution
  notes, test description, source incident. Created/updated when an incident is resolved (Messenger).
- Embeddings via Symfony AI Store into pgvector (embedding model recorded in CLAUDE.md §8).
  If no embedding provider is configured: Postgres full-text search fallback. The UI shows which
  method produced the results and shows a score **only** when it was actually computed
  (cosine similarity or ts_rank — labeled accordingly).
- Knowledge Base page: search, results with shared exception pattern, services, previous resolution,
  link to the old investigation.
- Investigation stage 4 now uses retrieval; matches become evidence items (`EV-n`, kind `history`).
- Acceptance: the demo investigation surfaces the seeded PricingService incident and cites it.

## B. Investigation assistant (not a generic chatbot)

- Side panel on incident and investigation pages; context is injected server-side (incident id,
  investigation id) — the user never pastes context.
- Uses the same read-only tools as the investigator. Each answer shows tool activity ("Called
  get_timeline_slice(EV-4..EV-9)") and evidence chips. Conversation persisted per incident.
- Suggested questions from the brief (why did this happen, sequence, which service failed first,
  supporting evidence, seen before?, blast radius, generate a regression test → routes to Phase 7).
- No provider configured → the panel says so and links to settings. No canned answers.

## C. Command palette (⌘/Ctrl+K)

- Server-backed search across incidents, applications, investigations, events (by id), settings pages.
- Contextual actions: start investigation (current incident), connect application, search events,
  run failure scenario (demo app only). Full keyboard navigation, recent items, `?` shows shortcuts.

## D. Notifications

- `Notification` entity, created by Messenger handlers for: critical incident, incident reopened,
  investigation completed/failed, test verified, application silent (no events for X minutes — a
  scheduled check via Symfony Scheduler).
- Bell dropdown + page, read/unread, mark all read, deep links. Updates on navigation/polling;
  do not describe it as real-time.

## E. Settings (keep it small)

Profile (name, email, theme), AI provider & model (shows whether a key is configured — never the key),
ingestion redaction list, data retention days (with a scheduled purge command), MCP tokens, audit log
viewer. Anything else stays on the cut list.

Commit per sub-feature.
