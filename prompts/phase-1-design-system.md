# Phase 1 — Design system & app shell

Goal: a design system so consistent that every later page looks premium automatically.
Load the `frontend-design` skill before starting.

## Tasks

1. **Tokens** — `assets/styles/tokens.css`: color (dark default + light set under
   `[data-theme=light]` and `prefers-color-scheme`), type scale, spacing (4px grid), radii, shadows,
   z-index, motion durations. Wire them into Tailwind's theme so utilities only use tokens.
   Light mode is designed: re-tune surfaces, borders and accent contrast (WCAG AA), don't invert.
2. **Typography** — self-host Geist Sans (UI, headings with tight tracking) and JetBrains Mono (IDs,
   code, timestamps). Define: display, h1, h2, h3, body, small, label (uppercase 11px tracked),
   mono-sm. Use tabular numbers for metrics and timestamps.
3. **Brand** — SVG logo: a signal waveform whose peaks are event nodes joined by thin links, with a
   small lens/crosshair motif on the failure peak. Produce: horizontal logo, compact mark (sidebar
   collapsed), favicon (SVG + 32px PNG), a loader animation (waveform drawing itself, reduced-motion
   safe), and 3 empty-state illustrations in the same line style (no apps, no incidents, no results).
4. **Twig components** (`templates/components/`), each shown on `/styleguide` in both themes:
   Button (primary/secondary/ghost/danger, loading), IconButton, Badge (severity, status, source:
   heuristic vs AI — violet), MetricCard (with "no data" state), DataTable (sortable header, empty,
   loading skeleton), FilterBar, SearchInput, Dropdown, Tabs, Modal, Drawer, ConfirmDialog, Toast,
   CodeBlock (copy button, line numbers; highlighting for PHP/JSON — choose a small highlighter that
   works with AssetMapper), KeyValueList, EmptyState, ErrorState, Skeleton, StatusDot (animated pulse
   for "connected"), Tooltip, CopyButton, Breadcrumbs, Kbd.
5. **Charts** — pick one library that works with AssetMapper/importmap without a build. Record it in
   CLAUDE.md §8. Build one themed chart component (time series) on the styleguide.
6. **Shell** — sidebar (sections from the original brief: Overview, Incidents, Investigations, Event
   Explorer, Applications, Test Lab, MCP Integrations, Knowledge Base, Settings; bottom: workspace,
   environment, docs link, user, collapse). Remove "Services" and "Reports" as separate items unless a
   later phase gives them a real page. Topbar: breadcrumb, search trigger (opens ⌘K), environment
   selector, time-range selector, notifications bell (stub count from DB = 0), avatar. Collapse state
   persists. Mobile: sidebar becomes a drawer.
7. **Auth** — `User`, `Workspace`, `WorkspaceMembership` entities (minimal), form login, a seeded
   demo user (credentials in `.env.example` and README only), CSRF, `WorkspaceVoter`.
   Every route except landing/login/ingestion API/MCP requires login.
8. **Placeholder pages** for each sidebar item use the EmptyState component with an honest message
   ("Available after you connect an application") — never fake content.

## Acceptance

- `/styleguide` renders every component in dark and light without console errors.
- Screenshots at 1440px and 390px of styleguide + shell: no overflow, no horizontal scroll, visible focus rings.
- Keyboard: Tab through the shell; Esc closes modal/drawer; focus returns to the trigger.
- No raw hex/px values in templates (grep check added to `make check`).
- Functional test: anonymous user is redirected to login; demo user can log in.
- Commit: `feat: design system and application shell`.
