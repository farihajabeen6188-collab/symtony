# Phase 6 — Investigation engine (heuristics + Symfony AI + grounding)

Use only APIs recorded in CLAUDE.md §8. Re-read the vendor code if anything is unclear.

## A. Domain

`Investigation` (incident, status, trigger user, provider/model or "heuristic-only", started/finished,
token usage if the platform reports it, error), `InvestigationStep` (stage enum, status, started/finished,
summary, error), `EvidenceItem` (id `EV-n`, kind, event id(s), excerpt — what the model actually saw),
`Finding` (kind: fact/inference/hypothesis, statement, cited evidence ids, contradicting evidence ids,
confidence enum low/medium/high + rationale, source `heuristic:<name>` | `llm:<provider>/<model>`,
grounding status verified/rejected + reason), `Recommendation`, `ImpactAssessment`.

## B. Pipeline (Messenger `investigations` transport — one message per stage, idempotent)

Stages, each persisting its step **when it really completes**:
1. Collecting evidence → `EvidencePackageBuilder` (incident, occurrence events, timeline, stack traces,
   related requests/services, deploys, history candidates from Phase 9 if available). Redacted,
   size-bounded (token budget), every item gets an `EV-n` id.
2. Reconstructing timeline → reuse Phase 5.
3. Running heuristic analyzers → each implements `Analyzer` and returns Findings with citations:
   `DeployCorrelationAnalyzer`, `ConstraintViolationRollbackAnalyzer`, `ExternalCallBeforeFailedWriteAnalyzer`
   (→ impact: orphaned payment authorization), `StaleCacheReadAnalyzer` (cache hit with age > threshold
   followed by write failure), `FailureBurstAnalyzer`.
4. Reviewing historical incidents (Phase 9 retrieval; skipped with an explicit "not available" step until then).
5. Generating hypotheses → Symfony AI **agent** with read-only tools (`get_evidence`, `get_event`,
   `get_timeline_slice`, `search_similar_incidents`) and a structured-output schema mapped to DTOs.
   System prompt in `config/prompts/investigator.md` (versioned) states the trust rules: cite `EV-` ids,
   never invent, separate facts/hypotheses, say when evidence is insufficient.
   **Skipped (not faked) when no provider is configured** — step shows "Skipped: no AI provider configured".
6. Validating → `GroundingValidator`: every cited id must exist in this package; facts must cite at
   least one evidence item; numeric claims (durations, counts) are checked against evidence where
   possible. Rejected findings are kept with the reason.
7. Producing report → assemble; incident status → `identified` if at least one verified hypothesis.

Retries: transient provider errors retried with backoff (max 2); timeouts configured; final failure
marks the step failed and the investigation `failed` with the message shown in the UI.

## C. Investigation workspace page (flagship screen)

- Top: incident title, badges, app, time range, actions (re-run, export Markdown, open timeline).
- Left: compact timeline + evidence list (`EV-n`, kind, excerpt) — clicking a citation anywhere
  highlights and scrolls to its evidence and opens the event drawer.
- Center: executive summary, what happened, ranked hypotheses (each with cited evidence chips,
  contradicting evidence, confidence + rationale, source badge — **violet for AI, neutral for heuristic**),
  recommended fix, limitations ("What we couldn't see").
- Right: grounding badge "**N of M claims verified against evidence**", confidence, affected services,
  impact assessment, next steps.
- Bottom tabs: stages (real timestamps, durations, errors), AI execution details (provider, model,
  tokens, latency, prompt version — never the API key), generated tests (Phase 7), history of runs.
- Live stage updates via Turbo polling (or Mercure if configured — don't claim real-time otherwise).
- Markdown export containing every section and citation.

## Tests

Fake platform (Symfony AI's in-memory/test platform if the installed version has one, else a mock of
the platform interface): structured output parsing; malformed model output → step failure, not crash;
GroundingValidator (invalid id rejected, fact without citation rejected); each analyzer on the demo
evidence; heuristic-only path end to end; stage failure path; redaction applied to the package
(assert no token/password string in what the platform receives).

Commit: `feat: evidence-grounded investigation engine`.
