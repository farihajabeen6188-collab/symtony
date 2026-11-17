# Phase 0 — Foundation & API spike

Read `CLAUDE.md` and `PLAN.md` first. This phase removes all uncertainty about the stack before any
product code is written. **Do not build features in this phase.**

## Tasks

1. **Environment check** — report PHP/Composer/Docker versions available on the host. The host is
   Windows + Docker Desktop; all project commands run inside containers.
2. **Repository** — `git init`, `.gitignore` (vendor, var, .env.local, runner jobs dir), `.env.example`.
3. **Docker Compose** (`compose.yaml`):
   - `app`: FrankenPHP + PHP 8.4 with `pdo_pgsql`, `intl`, `opcache`, `zip`.
   - `db`: `pgvector/pgvector` image (Postgres 16), healthcheck, named volume.
   - `worker`: same image as app, runs `messenger:consume async investigations`.
   - Leave placeholders (commented) for `demo-shop` and `runner` services — added in Phases 3 and 7.
4. **Symfony skeleton** — webapp-style minimal: twig, security, doctrine, messenger, validator,
   serializer, rate-limiter, asset-mapper, stimulus, turbo, ux-icons, ux-twig-component,
   `symfonycasts/tailwind-bundle`, monolog. Dev: phpunit, foundry, phpstan, maker, debug.
5. **Install Symfony AI and MCP** — `symfony/ai-bundle` (+ the platform bridge needed for Hugging Face
   and one fallback provider) and `symfony/mcp-bundle`. If the Symfony version from CLAUDE.md is not
   compatible, pick the newest compatible one and update CLAUDE.md §2.
6. **Read the installed code** in `vendor/symfony/ai-*` and `vendor/symfony/mcp-bundle` / `vendor/mcp/sdk`.
   Write into CLAUDE.md §8: exact versions; how to configure a platform/model; how to declare an
   agent; how to register a tool; how to request structured output; how to declare an MCP tool,
   resource and prompt; the STDIO command name; the HTTP transport route/config.
7. **AI spike** (throwaway command `app:spike:ai`, deleted at the end of the phase):
   - Call a Hugging Face model through Symfony AI with one simple tool (`get_time`) and a structured
     output schema. Try 2–3 candidate models known for tool calling. Record which works.
   - If none supports tool calling reliably, configure the fallback provider and record that.
   - If no API key is present, record "not tested — no key" and ask the user at the end of the phase.
     Never fake the result.
8. **MCP spike** — a `ping` tool; connect with `npx @modelcontextprotocol/inspector` over STDIO
   (`docker compose exec -T app php bin/console <mcp command>`). Record the exact command in §8.
   Keep the ping tool (it becomes a health check).
9. **Makefile** with `up`, `down`, `shell`, `test`, `check`, `demo` (demo can be a stub for now).
   `check` = `lint:container`, `lint:twig templates`, `lint:yaml config`, phpstan (level 6+), phpunit.
10. Commit: `chore: foundation, Symfony AI + MCP verified`.

## Acceptance (from PLAN.md)

- `make up` → https://localhost returns the Symfony page (or a placeholder route).
- §8 of CLAUDE.md contains verified versions and API notes, including the AI spike result.
- MCP Inspector lists the `ping` tool and calling it returns a result.
- `make check` is green.

## Report at the end

Versions chosen, which model worked for tool calling (or why not), anything that contradicts
CLAUDE.md, and whether an API key is needed from the user.
