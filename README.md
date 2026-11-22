# SYMTONY — every error has a story

SYMTONY is application forensics for Symfony. It ingests what your app does, groups failures into
incidents, reconstructs what happened as a timeline, investigates with evidence-cited AI, and proves
that a regression test reproduces the bug (red on the buggy build, green with the fix).

It ships with a small demo shop that has a real bug, so the whole story runs on your machine in minutes.

## Quickstart (5 minutes)

You need **Docker Desktop** (Windows, macOS) or Docker Engine with Compose v2 (Linux), about 4 GB of free
memory for Docker, and ports 8080, 8081 and 5433 free. No PHP or Node on the host.

```bash
git clone https://github.com/farihajabeen6188-collab/symtony.git
cd symtony
make up      # build and start the app, database, worker and isolated test runner; migrate
make demo    # also start the demo shop, create the demo app + token, replay seeded history
```

On Windows without `make`, use the PowerShell twin of every target:

```powershell
./dev.ps1 up
./dev.ps1 demo
```

Open http://localhost:8080 and choose **Run the demo**. The sign-in page shows the local demo account
(`demo@symtony.local` / `symtony-demo`, from `.env`; change it in `.env.local` for anything shared).
In production the account is hidden unless `SYMTONY_PUBLIC_DEMO=1` is set; the public Codespace demo sets it, so
the sign-in page offers **Sign in as the demo account**.

### The demo story

1. **Applications → Acme Shop → Run failure scenario.** The demo shop deploys release 1.4.0 and sends real
   checkouts. A stale cached stock level lets checkout authorize a payment, then the stock write violates a
   database constraint and rolls back. Two checkouts fail with HTTP 500.
2. **Incidents → SYM-5.** The failures are grouped into one incident. Open its **Timeline**: spans linked by
   trace id are solid; the warehouse sync that changed the stock ran in another trace and is drawn dashed,
   labelled *inferred*.
3. **Investigate.** Seven stages run in the worker and update as they finish. The report separates facts,
   inferences and hypotheses, cites evidence ids (EV-n) for every claim, and shows how many claims the
   grounding validator verified.
4. **Generate regression test → review → Prove it.** The test runs in an isolated container: red on the
   buggy build, green with the reference fix. Only then is it a *verified reproduction*.
5. **MCP Integrations.** Create a token and ask Claude Code why checkout failed.

## AI provider (optional)

Without a key everything works on deterministic analyzers, and every screen says no model was used. To add a
model, choose **one** provider and put its key in `.env.local` (never committed). All providers go through
Symfony AI platform bridges; the model must support tool calling.

| `SYMTONY_AI_PROVIDER` | Key in `.env.local` | Default model (override with `SYMTONY_AI_MODEL`) |
|---|---|---|
| `huggingface` (default) | `HUGGINGFACE_API_KEY` (+ optional `HUGGINGFACE_PROVIDER`) | `Qwen/Qwen3.5-35B-A3B` |
| `openai` | `OPENAI_API_KEY` | `gpt-5.4-mini` |
| `anthropic` (Claude) | `ANTHROPIC_API_KEY` | `claude-sonnet-5-5` |
| `gemini` | `GEMINI_API_KEY` | `gemini-3.5-flash` |
| `openrouter` | `OPENROUTER_API_KEY` | `openai/gpt-5-mini` |
| `mistral` | `MISTRAL_API_KEY` | `mistral-medium-latest` |
| `deepseek` | `DEEPSEEK_API_KEY` | `deepseek-v4-flash` |
| `generic` (Groq, Together, Fireworks, Ollama, LM Studio, vLLM...) | `GENERIC_BASE_URL` (+ `GENERIC_API_KEY` if needed) | none: set `SYMTONY_AI_MODEL` |

```dotenv
SYMTONY_AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
# SYMTONY_AI_MODEL=claude-opus-5-5   # optional
```

Restart with `docker compose restart app worker`, then `make spike-ai` (`./dev.ps1 spike-ai`) checks tool
calling and structured output against the real API. **Settings → AI provider** shows the provider, model and
whether a key is set (never the key).

## Connect an AI client over MCP

Create a token on **MCP Integrations** (it is shown once), then:

```bash
claude mcp add --transport http symtony http://localhost:8080/mcp --header "Authorization: Bearer <token>"
```

STDIO works too: `claude mcp add --env SYMTONY_MCP_TOKEN=<token> --transport stdio symtony -- docker compose exec -T -e SYMTONY_MCP_TOKEN app php bin/console mcp:server`
(run from this folder). Tools, resources and prompts are listed in [docs/mcp.md](docs/mcp.md).

## Send events from your own app

Install the bundle from [packages/symtony-bundle](packages/symtony-bundle/README.md), or post JSON yourself:

```bash
curl -X POST http://localhost:8080/api/v1/events \
  -H "Authorization: Bearer sym_live_..." -H "Content-Type: application/json" \
  -d '{"timestamp":"2026-11-18T10:00:00Z","severity":"error","service":"shop","message":"Payment declined","trace_id":"4bf92f3577b34da6a3ce929d0e0e4736"}'
```

Get a token by connecting an application (**Applications → Connect application**). Details:
[docs/ingestion-api.md](docs/ingestion-api.md) and the [event schema](docs/event-schema.json).

## Commands

| Make | PowerShell | What it does |
|---|---|---|
| `make up` | `./dev.ps1 up` | Build and start app, database, worker and runner; migrate |
| `make demo` | `./dev.ps1 demo` | Start the demo shop and seed demo data |
| `make test` | `./dev.ps1 test` | PHPUnit (app + bundle) |
| `make check` | `./dev.ps1 check` | Lint container/Twig/YAML, design-token guard, PHPStan level 6, tests |
| `make shell` | `./dev.ps1 shell` | Shell in the app container |
| `make console c="..."` | `./dev.ps1 console "..."` | Run a console command |

Useful console commands: `symtony:mcp:token "name" --write`, `symtony:retention:purge --dry-run`,
`symtony:applications:check-silent`, `symtony:incidents:refingerprint`, `debug:mcp`.

## Troubleshooting

- **Port already in use**: set `SYMTONY_HTTP_PORT`, `SYMTONY_DB_PORT` in `.env.local`, then `make up`.
- **Pages take seconds on Windows**: make sure you did not bind-mount `vendor/`; it lives in a Docker volume.
  Composer must run inside the container (`make composer c="..."`).
- **Docker runs out of memory** while building: give Docker Desktop at least 4 GB.
- **Line endings**: the repository uses LF. If Git converted files to CRLF, shell scripts in containers fail;
  run `git config core.autocrlf input` and re-clone.
- **Test Lab says the runner is offline**: `docker compose up -d runner`, then reload.
- **No HTTPS**: the dev URL is plain HTTP on purpose, to avoid local certificate problems.

## More

- [ARCHITECTURE.md](ARCHITECTURE.md): contexts, data flow, grouping, correlation, investigation pipeline, runner, MCP.
- [SECURITY.md](SECURITY.md): threat model, tokens, redaction, the LLM boundary, the sandbox.
- [CONTRIBUTING.md](CONTRIBUTING.md): how to work on SYMTONY.
- [DEMO.md](DEMO.md): the 90-second demo script.
