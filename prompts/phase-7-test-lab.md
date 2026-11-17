# Phase 7 — Regression Test Lab (red → green proof)

The claim we want to make honestly: "SYMTONY wrote a test that reproduces the bug, and we proved it."

## A. Test proposal generation

- `RegressionTestGenerator`: from a verified hypothesis + evidence (exception class, request data,
  service names, relevant demo-shop class names from an **allow-listed source context** — only files
  configured in `symtony.source_context.paths`, and the UI lists exactly which files were shared).
- LLM path: agent produces `{title, purpose, preconditions, scenario, expected_behavior, php_code,
  cited_evidence}`. Heuristic path (no provider): a template-based generator for known analyzer
  findings (e.g. stale-cache constraint violation), clearly labeled "Template-generated".
- Static checks before saving: `php -l` inside the runner, must contain exactly one test class
  extending the expected base class, no `exec/shell_exec/system/proc_open/passthru/eval/`backticks`,
  no network functions. Failing checks → proposal marked "Rejected by static checks" with reasons.

## B. Isolated runner (`runner/` + compose service)

- Image: PHP 8.4 CLI + demo-shop's vendor installed at build time.
- `network_mode: none`, read-only root FS, tmpfs workdir, CPU/memory limits, non-root user,
  hard timeout (e.g. 60 s).
- Communication only through a mounted **jobs directory**: the app writes `jobs/<id>/job.json` +
  `Test.php`; the runner polls, copies demo-shop source into tmpfs, optionally applies
  `fixes/stale-stock-cache.patch` (`variant: buggy | fixed`), runs PHPUnit on that one file with
  JUnit output, writes `result.json` (exit code, duration, junit xml, truncated stdout/stderr).
- The app's worker picks up results and stores `TestExecution` rows. The model never chooses the
  command — the runner's command is fixed.
- If the runner isn't running: UI shows "Runner offline" — never a fake result.

## C. Test Lab UI

- List of proposals (status badge, incident, created, last run).
- Detail: code (highlighted, copy, download `.php`), metadata, cited evidence chips, shared source files,
  review checkbox "I reviewed this code" (required before running — human approval, audited).
- **"Prove it"** action: runs `buggy` then `fixed`, shows two result cards side by side:
  red (expected) → green (expected). Resulting status:
  - **Verified reproduction** — fails on buggy, passes on fixed
  - **Does not reproduce** — passes on buggy
  - **Fix not confirmed** — fails on both
  - **Runner error** — infra error, with output
- Execution history with real output, duration and timestamps.

## Tests

Static check rejects dangerous code; job/result protocol (simulated runner in tests); status
derivation matrix; review required before run (403 otherwise); audit log entry for each run.
Real end-to-end once in Docker on the demo incident — record the result in the PLAN.md phase log.

Commit: `feat: regression test lab with isolated red/green runner`.
