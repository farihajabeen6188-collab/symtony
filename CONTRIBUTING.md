# Contributing

Thanks for helping. The rules that keep SYMTONY trustworthy are in [CLAUDE.md](CLAUDE.md) (they apply to
humans too); the plan and the history of decisions are in [PLAN.md](PLAN.md).

## Set up

```bash
make up && make demo      # or ./dev.ps1 up ; ./dev.ps1 demo on Windows
```

Run Composer inside the container (`make composer c="require …"`); `vendor/` lives in a Docker volume.
Rebuild CSS after template changes: `make console c="tailwind:build"` (or `tailwind:build --watch`).

## Before you open a pull request

```bash
make check    # container/Twig/YAML lint, design-token guard, PHPStan level 6, PHPUnit (app, bundle, demo shop)
```

- `declare(strict_types=1);`, typed properties, enums for states, `readonly` DTOs.
- Controllers stay thin; logic lives in `Application/` services and handlers.
- Every list query is workspace-scoped, paginated and indexed; list pages assert their query count.
- No silent `catch`: log and rethrow, or convert to a domain error the UI shows.
- Never fabricate data. A number on screen comes from a query; an empty result shows an empty state.
- Facts, inferences and hypotheses stay separate types. AI output is violet and labelled with its source.
- Tests never call paid AI APIs: use `Symfony\AI\Agent\MockAgent` or the mock platform.
- Templates use design tokens only (no raw hex or px); new components go on `/styleguide` first.
- UI changes: check the page at 1440 px and 390 px with no horizontal scroll and no console errors.

## Commits

Small, focused commits with a clear message. If a change alters the stack or a rule, update
`CLAUDE.md` in the same commit.
