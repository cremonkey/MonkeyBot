# MonkeyBot Admin — Design System Plan (Phase 0, before the remaining-work plan)

**Written:** 2026-09-24. **Base branch:** `release/9.x-integration`.
**Runs before:** `docs/plans/2026-09-24-remaining-work.md`. Task C7 there (admin navigation
regroup) and D2 (automation modes UI) build on the tokens this plan creates — do this plan first.

## Why this plan exists

The admin panel is a Bootstrap 4 admin theme (Gull/Sing-App family) loaded stylesheet-by-stylesheet
in `application/views/include/css_include_back.php`: 30+ vendor CSS files, then
`assets/css/style.css` + `components.css` (theme base), then `assets/css/custom.css` (empty — the
intended customization point), then `assets/css/rtl.css` (flips layout for Arabic tenants). Every
feature built in the last two weeks (Inbox, Setup Guide/Onboarding, Pricing, Sequences, Agency
Control Center) is plain unstyled Bootstrap: raw `.card`, `.badge`, `.table-sm`, ad hoc inline
`style="..."`, no shared visual language, no dark-mode awareness, inconsistent spacing. This plan
gives the whole admin surface one coherent, token-driven design system without rewriting any vendor
file or changing any URL, form field name, or JS behavior.

## Scope and non-scope

**In scope:** `assets/css/custom.css` (the real customization point) plus new files loaded right
after it and before `rtl.css`; class-level changes to view templates (`application/views/admin/**`)
to adopt the new classes; no structural HTML rewrites beyond adding/renaming classes and wrapper
divs where a component needs one.

**Out of scope (do not touch):** vendor files under `assets/modules/`, `assets/css/style.css`,
`assets/css/components.css`, `assets/css/reverse.css`; the public marketing site theme
(`site_new`, `site/modern`) — that is a separate brand and a separate plan; any PHP business logic,
controller behavior, database schema, or JS event handlers (only their trigger classes may change,
never their logic).

## Global Constraints (bind every task below)

1. **Never touch production** (`/etc/dokploy`, `monkeybot-app-ojzrhf-*` containers) or the shared
   staging stack (`monkeybot-staging-*`, port 8089). Build your own isolated stack per §3.4 of the
   sibling plan's workflow (same rules: `free -g` ≥ 3GB before starting, `down -v` after, restore
   `application/config/database.php` and file ownership).
2. **CSS only through tokens.** No new hardcoded hex/px/rem value in any file this plan adds or
   touches once `design-tokens.css` exists (Task 1 first, everything else after). Reference:
   `var(--color-primary)`, not `#2563EB`. Run `node
   /root/.claude/skills/design-system/scripts/validate-tokens.cjs --dir application/views/admin`
   if useful, but hand-review is required regardless — this codebase is not fully migrated.
3. **RTL and dark mode are not optional.** Every visual change is checked in: LTR, RTL
   (`assets/css/rtl.css` stays loaded after your files — never depend on load order the other way),
   light skin, and the existing dark skin under `assets/css/skins/` (grep `skin-dark` or similar in
   `assets/js` for how it's toggled). A change that looks right in LTR-light and wrong anywhere else
   is not done.
4. **No behavior change.** Every `id`, `name`, `data-*` attribute a script selects on stays exactly
   as it is. If a component needs a wrapper div for the new look, add it without removing or
   renaming what JS already selects.
5. **Visual proof, not a description.** Every task's report includes actual screenshots (see
   Screenshot method below) of the before and after state for at least the pages it touched, in
   both LTR and RTL, light and dark.
6. **Existing PHP/JS tests must still pass** — these are view/CSS changes, so `tests/*/run.php`
   should be unaffected; run them anyway (§3.2 of the sibling plan) as a behavior-safety net. Any
   suite failure is a signal you broke a selector or a structural assumption — stop and fix it,
   don't explain it away.
7. **Screenshot method** (no external service, no login secrets committed): on the isolated stack,
   use a headless Chromium already available in the app image or `docker run --rm
   ghcr.io/puppeteer/puppeteer` style container if needed; log in through the seeded staging user
   (`docker/scripts/staging_seed.sh` credentials, never real ones); save PNGs under the task's SDD
   workspace, never commit screenshots to git.
8. **Small, reviewable diffs.** One task = one coherent visual layer. Do not let Task 2 (nav) creep
   into touching Inbox page markup — that's Task 4.

## Token architecture (Task 1 builds this; every later task consumes it, never invents new tokens)

Three layers, per `design-system` skill convention:

```
Primitive   --mb-blue-600: #2563eb;              (raw values, never used directly in components)
Semantic    --color-primary: var(--mb-blue-600);  (purpose, theme-switchable)
Component   --card-radius: var(--radius-md);      (component-specific)
```

- Primitives: color ramps (grays, brand blue, success/warning/danger/info, at least 5 steps each),
  spacing scale (4px base: 4/8/12/16/24/32/48/64), radius scale (sm/md/lg/full), shadow scale
  (sm/md/lg), type scale (xs/sm/base/lg/xl/2xl, one font stack matching what the theme already
  loads — check `style.css` for the current `font-family` and keep it, don't add a new webfont).
- Semantic: `--color-primary`, `--color-primary-hover`, `--color-surface`, `--color-surface-alt`,
  `--color-border`, `--color-text`, `--color-text-muted`, `--color-danger`, `--color-success`,
  `--color-warning`, `--space-*`, `--radius-*`, `--shadow-*`.
- Dark mode: redefine the semantic layer under whatever selector the existing dark skin toggle uses
  (find it — do not invent a new dark-mode mechanism alongside an existing one).
- Component tokens as needed per component in Tasks 3–5 (`--btn-*`, `--card-*`, `--badge-*`,
  `--table-*`).

File: `assets/css/design-tokens.css`, loaded in `css_include_back.php` immediately after
`assets/css/custom.css` and before `assets/css/rtl.css`.

## Todo list

- [ ] **Task 1** Design tokens (primitive + semantic + component scaffolding) + load order — S, low
- [ ] **Task 2** Sidebar, header/topbar, user menu visual refresh — S/M, low — deps: Task 1
- [ ] **Task 3** Core component overlay: buttons, cards, badges, alerts, forms, tables — M, low — deps: Task 1
- [ ] **Task 4** Apply the system to the newest feature pages: Inbox, Setup Guide, Pricing, Sequences, Agency Control Center — M, low — deps: Task 2, Task 3
- [ ] **Task 5** Dashboard + Analytics Hub visual refresh (stat cards, chart containers) — S/M, low — deps: Task 3
- [ ] **Task 6** Accessibility + responsive pass across everything the above touched — M, low — deps: Task 2, 3, 4, 5

## Task cards

### Task 1 — Design tokens
- **Do:** Create `assets/css/design-tokens.css` with the three layers above. Read
  `assets/css/style.css` and `components.css` first to extract the theme's *current* primary color,
  font stack and base radius/shadow so the semantic layer starts as a **faithful restatement of
  today's look** (tokens should not shift the visual baseline by themselves — that happens in Tasks
  2–5 when components adopt them). Add the `<link>` in `application/views/include/css_include_back.php`
  right after the `custom.css` line. Find the dark-skin toggle mechanism (grep for `skin-dark`,
  `data-theme`, or similar in `assets/js/*.js` and `application/views/admin/theme/*.php`) and add
  the dark semantic block under that exact selector.
- **Accept:** Page renders pixel-identical to before (tokens exist but nothing consumes them yet).
  Screenshot proof: one page, LTR light, before/after, identical. Dark-skin toggle still works.
- **Files:** new `assets/css/design-tokens.css`; one line in `application/views/include/css_include_back.php`.

### Task 2 — Sidebar, topbar, user menu
- **Do:** Restyle `application/views/admin/theme/sidebar.php`, `header.php`, `usermenu.php` (all via
  classes + the new component tokens, not inline styles) for: active/hover states, spacing rhythm,
  icon alignment, badge counts (e.g. unread Inbox count if present), collapsed-sidebar state. Keep
  every existing class that JS selects (search `assets/js` for `.sidebar`, `#sidebar`,
  `.usermenu`-type selectors first and preserve them).
- **Accept:** Nav works identically (collapse, active-link highlight, submenu open/close). Screenshot
  proof: LTR/RTL × light/dark, four images, sidebar+topbar visible.
- **Files:** the three theme views above, `assets/css/design-tokens.css` (component tokens for nav only).

### Task 3 — Core component overlay
- **Do:** New `assets/css/design-system.css` (loaded right after `design-tokens.css`) restyling
  Bootstrap's `.btn`, `.card`, `.badge`, `.alert`, `.form-control`/`.form-group`, `.table` using only
  tokens — consistent radius, shadow, focus ring, spacing. This is a global overlay: it changes how
  every existing page looks without changing any page's markup.
- **Accept:** Spot-check 5 existing pages (pick from Dashboard, CRM, Automations, Missed Questions,
  System Health) that this plan does not separately restyle — they should look more consistent
  without anything breaking (forms still submit, tables still sort/paginate if they use DataTables —
  don't fight `datatables.min.css`, layer on top of it). Screenshot proof for those 5 pages, LTR/RTL.
- **Files:** new `assets/css/design-system.css`, one `<link>` line, token additions in `design-tokens.css`.

### Task 4 — Newest feature pages
- **Do:** Apply the design system to `application/views/admin/inbox/index.php`,
  `application/views/admin/onboarding/step*.php`, `application/views/admin/pricing/*.php`,
  `application/views/admin/sequences/*.php` (or wherever they live — `grep -rl` from `Sequences.php`),
  and the Agency Control Center view. These pages currently mix raw Bootstrap with inline styles
  (e.g. `style="cursor:pointer"` in onboarding step2, ad hoc `.badge-*` combos). Replace inline
  styles with classes from Task 3's overlay wherever equivalent; keep any inline style that encodes
  actual data (e.g. a dynamic color) as inline, everything else becomes a class.
- **Accept:** Every JS handler in these views still fires (the `$('.ob-target').on('change', ...)`
  style selectors are untouched). Screenshot proof per page, LTR/RTL, light/dark, plus the existing
  `tests/onboarding/run.php`, `tests/inbox/run.php`, `tests/pricing/run.php`, `tests/sequences/run.php`
  green.
- **Files:** the view files listed above; no new CSS files (consumes Task 1 + 3 tokens/classes).

### Task 5 — Dashboard + Analytics
- **Do:** Restyle the stat-card grid and chart containers in `application/views/admin/dashboard*.php`
  (or wherever `Dashboard.php` renders) and `Analytics_hub`'s view — consistent stat-card component
  (value, label, trend indicator, icon) using Task 3's card tokens; chart canvases get a consistent
  container (padding, border, title bar) without touching the charting library's own config/JS.
- **Accept:** Charts still render with the same data bindings. Screenshot proof, LTR/RTL, light/dark.
- **Files:** dashboard + analytics view files.

### Task 6 — Accessibility + responsive pass
- **Do:** Across everything Tasks 2–5 touched: check color contrast of text-on-surface token pairs
  meets WCAG AA (4.5:1 body text, 3:1 large text/icons) — adjust the *semantic* token values in
  `design-tokens.css` if a pair fails, not per-component overrides. Add visible `:focus-visible`
  states for every interactive element restyled (buttons, nav links, form fields) — the theme's
  default focus ring is often suppressed by Bootstrap resets; restore or replace it. Verify the
  sidebar/topbar and the Inbox two-pane layout collapse sensibly at a phone width (375px) and a
  tablet width (768px) — this may already be broken pre-existing; only fix what your own changes
  made worse or what is trivially fixable through the tokens, and note pre-existing gaps in the
  report rather than scope-creeping into a full responsive rebuild.
- **Accept:** A contrast table (pair → ratio → pass/fail) in the report for every semantic
  text/surface combination. Screenshot proof at 375px and 768px for Sidebar+Dashboard and Inbox.
- **Files:** `design-tokens.css` (value tweaks only), targeted responsive rules added to
  `design-system.css` or the page-specific view files from Task 4/5 if a fix is page-specific.

## After this plan

Deploy the accumulated design-system commits together with (or ahead of) the remaining-work plan's
first deployed batch, following the same production procedure used throughout this project (merge
into the production branch's checkout, keep the local `config.php` diff, verify `/health` and a
handful of pages, screenshot the live site once). This plan produces no migration and needs no
`cron_hub migrate`.
