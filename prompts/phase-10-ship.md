# Phase 10 — Landing page, docs, quality gate, demo

## A. Landing page (`/`, public)

- Hero: **SYMTONY** · "Every error has a story." · "Turn scattered application signals into
  evidence-backed explanations." CTA: "Run the demo" (login as demo user) + "Read the docs".
- Hero visual = the **real timeline component** rendering the demo trace (read-only, static data
  exported from the demo seed and labeled "Demo data"), not a screenshot mock.
- Sections: how it works (instrument → group → reconstruct → investigate → prove), the trust model
  (facts vs hypotheses, grounding validator, red→green), Symfony AI inside (which components and why),
  MCP (tool list + Claude Code snippet), privacy & security (redaction, no secrets to LLMs, isolated
  runner). No logos, testimonials, user counts or invented stats.

## B. Documentation

- `README.md`: what/why (3 lines + screenshot), 5-minute quickstart (Docker Desktop on Windows/macOS/
  Linux), `make demo`, demo login, optional AI key setup, MCP connection, ingestion API example,
  running tests, troubleshooting (ports, Docker memory, certificates, Windows line endings).
- `ARCHITECTURE.md`: diagram of contexts and data flow, grouping algorithm, correlation rules,
  investigation pipeline, runner isolation, MCP layer, key decisions.
- `SECURITY.md`: threat model, token handling, redaction, LLM data boundary, runner sandbox, reporting.
- `CONTRIBUTING.md`, `docs/event-schema.json`, `docs/ingestion-api.md`, `docs/mcp.md`.

## C. Quality gate (run everything; fix every high-priority defect)

1. Fresh clone into a new folder → `make demo` → time it → full spine works **without an AI key**
   (heuristic engine), then again **with** a key.
2. Every sidebar page at 1440 / 1024 / 390: no overflow, no console errors, no dead buttons or links
   (write a small crawler test that visits every route as the demo user and asserts 200 + no 5xx).
3. Both themes reviewed. Keyboard-only pass of the spine. Reduced-motion pass.
4. Trust audit: grep templates for hardcoded numbers; every AI-generated element has the violet AI
   badge and a source; every test status comes from a real execution row.
5. Security pass: run the `security-review` skill; confirm no secrets in repo/logs; token shown once.
6. `make check` green; record test count and duration.

## D. Demo

`DEMO.md` with a **90-second script** (timestamps + exact clicks + what to say):

0:00 problem (scattered logs) → 0:10 Run failure scenario in demo shop → 0:20 incident appears,
grouped (count rising) → 0:30 timeline: proven vs inferred links, first-failure pin, deploy marker →
0:45 investigation: stages complete, hypothesis with citations, "N of M claims verified" →
1:00 Test Lab "Prove it": red on buggy, green on fix → 1:15 Claude Code asks over MCP "why did
checkout fail?" and cites the same evidence → 1:25 close: "Every error has a story."

Also: a backup plan (pre-recorded video, heuristic-only mode if the network/AI provider fails).

## E. Final report to the user

Repository structure, features shipped vs cut, architecture summary, Symfony AI components used, MCP
capabilities, entities, install commands, env vars, test results (real numbers), demo instructions,
known limitations, next improvements.

Commit: `docs: landing, documentation and demo script` + tag `v1.0.0-hackathon`.
