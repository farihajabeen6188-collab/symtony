# Demo: 90 seconds

**Before you start** (5 minutes ahead):

- `make up && make demo` (or `./dev.ps1 up` then `./dev.ps1 demo`). Wait for "Open http://localhost:8080".
- Sign in at http://localhost:8080 as `demo@symtony.local` / `symtony-demo`. Keep two tabs: **Applications → Acme Shop** and **Incidents**.
- Optional AI: `HUGGINGFACE_API_KEY` in `.env.local`, then `docker compose restart app worker`.
- Optional MCP: create a token on **MCP Integrations**, run the `claude mcp add …` command shown there, and open Claude Code in a terminal next to the browser.
- `docker compose ps` shows app, worker, runner, demo-shop and db running. Test Lab shows "Runner online".

## Script

| Time | Click | Say |
|---|---|---|
| 0:00 | Landing page (`/`), point at the timeline | "A checkout fails in production. The logs are in five places and none of them says why. SYMTONY says: every error has a story." |
| 0:10 | **Applications → Acme Shop → Run failure scenario** | "This is a real Symfony shop with a real bug. We deploy 1.4.0 and send real checkouts." |
| 0:20 | **Incidents** (toast says 2 checkouts failed); open **SYM-n** | "Two 500s, grouped into one incident by fingerprint. The count rises as more arrive." |
| 0:30 | **Timeline** tab, then **Show all**, then **First failure** | "Solid lines are proven by trace and span ids. The warehouse sync is dashed: it is in another trace, linked only by timing, and we say so. The 1.4.0 deploy marker sits just before it. Here is the first failing span." |
| 0:45 | **Investigate**; watch the stages; open the report | "Seven stages, each shown when it really finishes. The hypothesis: a stale cached stock value; checkout authorized the payment, then the write hit the constraint. Every claim cites evidence, and the panel shows how many claims passed the evidence check; a rejected claim would be shown, not used." |
| 1:00 | **Generate regression test → review checkbox → Prove it** | "The test runs in a sandbox with no network. Red on the buggy build, green with the fix. Only now does it say Verified reproduction." |
| 1:15 | Claude Code: *"Why did checkout fail in the demo shop?"* | "Claude Code asks SYMTONY over MCP and cites the same evidence ids. Same facts, same rules, audited." |
| 1:25 | Back to the landing hero | "Every error has a story. SYMTONY tells it with evidence." |

## Backup plan

- **No AI key or the provider is down:** say it out loud. The investigation runs on the deterministic
  analyzers and the report says "No language model was used". The story, citations and grounding stay the same.
- **No Claude Code on the machine:** show **MCP Integrations → Recent calls**, or run the MCP Inspector
  command from [docs/mcp.md](docs/mcp.md) with `--method tools/call --tool-name get_investigation_report --tool-arg investigation=SYM-5`.
- **Network down:** everything runs locally; only the optional AI provider needs the internet.
- **Runner offline:** `docker compose up -d runner`, reload Test Lab (the status dot turns green).
- **Something odd mid-demo:** an existing SYM-5 with its investigation and verified test is already in the
  database after a rehearsal; open it from **Incidents** and continue from the timeline.
- **Record a fallback video** of one clean run the day before.
