# How to use these prompts

The build is split into 11 phases so Claude Code never loses detail when its context gets compacted.

| File | Role |
|---|---|
| `../CLAUDE.md` | Permanent rules. Claude Code loads it automatically in every session. |
| `../PLAN.md` | The demo spine, scenario, milestone checklist, cut list, phase log. The agent ticks boxes here. |
| `phase-N-*.md` | The work order for one phase. |

## Before you start

1. Fill in **"Hackathon context"** at the top of `PLAN.md` (judging criteria, required tech, deadline).
2. Make sure Docker Desktop is running.
3. Have an AI key ready (Hugging Face token; optionally a fallback provider key). Put it in `.env.local`
   when Phase 0 asks — never paste keys into chat.

## Run each phase

Start a fresh session (or `/clear`) per phase and send the **kickoff message** below with the phase
number changed. One phase per session keeps context clean.

```
Read CLAUDE.md and PLAN.md, then execute prompts/phase-0-foundation.md.
Work autonomously through every task in that file. After each step run `make check`
(once it exists). For UI work, verify in the browser at 1440px and 390px.
Tick acceptance boxes in PLAN.md only for checks you actually ran, add a phase-log entry,
and commit. If something blocks you, record it under Blockers and tell me — never fake a result.
When the phase is done, stop and report: what shipped, test results, open issues.
```

## Between phases (your 5-minute review)

- Open the app and click through what was built.
- Read the PLAN.md phase log and Blockers.
- `git log --oneline` to see commits.
- If something is wrong, tell the agent in the same session before moving on.

## If you are short on time

Phases 0–8 are the demo spine. Phase 9 is nice to have. Phase 10 (docs + demo script) is **not**
optional — reserve the last day for it.
