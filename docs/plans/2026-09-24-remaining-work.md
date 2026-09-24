# MonkeyBot — Remaining Work Plan (execution guide for coding agents)

**Written:** 2026-09-24. **Base branch:** `release/9.x-integration` (HEAD `84428a7a` or later).
**Source brief:** `/root/MonkeyBot Prompt.md` (sections referenced as §N).
**Status source:** `docs/baseline/2026-09-22-transformation-baseline.md` (latest sections at the bottom).

This plan lists everything the brief asks for that is **not built yet or only partly built**, based on
a section-by-section audit of §7–§51 against the merged code on 2026-09-24. Each task has a card below
with files, steps, acceptance criteria and tests. Work the tasks **in the order of the todo list**,
one task per branch.

---

## 0. How to use this plan

1. Read sections 1–3 fully before your first task. They hold the rules that keep production safe.
2. Pick the **first unchecked task** in section 4 whose dependencies are all checked.
3. Do that one task only, on its own branch, following the workflow in section 3.
4. Finish with the report in section 3.6. Do **not** merge, push or deploy. A reviewer merges.
5. Tasks marked **⚠ REVIEW GATE** change how live customer conversations behave. For those, you
   build the code **disabled behind a flag, default OFF**, prove "flag off = byte-identical to today",
   and stop. A stronger model or the owner reviews before anything is enabled.
6. Tasks marked **OWNER** are not coding tasks. Never do them; they are listed so you know they exist.

If a task card and the code disagree (a file moved, a function was renamed), trust the code, and say
so in your report.

---

## 1. Ground truth

| Thing | Value |
|---|---|
| Stack | CodeIgniter 3.1.5 + HMVC, PHP 8.1, MariaDB 10.11, Docker |
| Integration worktree (read-only for you) | `/root/worktrees/mb-integration` on `release/9.x-integration` |
| Production code | `/etc/dokploy/compose/monkeybot-app-ojzrhf/code` — **never touch** |
| Production containers | `monkeybot-app-ojzrhf-{app,web,db}-1` — **never exec writes, never restart** |
| Shared staging | `/root/worktrees/monkeybot-staging`, port 8089, containers `monkeybot-staging-*` — do not use it; build your own isolated stack (3.4) |
| Live config | `application/config` is a **Docker volume** in production. Files you add under `application/config/` never reach production. A boundary test (`tests/onboarding/run.php`) fails if you add one. Data files go under `application/data/`. |
| Entitlements | `application/helpers/entitlement_helper.php`. Used module ids: 400–412. **Next free id: 413.** A new module needs a `modules` row + package grant in a migration (copy `2026-09-26-sequences.sql`). |
| Migrations | `assets/backup_db/migrations/YYYY-MM-DD[letter]-name.sql`, run by `php index.php cron_hub migrate`. Latest: `2026-10-01-inbound-media.sql`. |
| Feature flags | Read with `$this->config->item('flag_name')`. Unset must mean OFF/today's behaviour. |
| Line endings | Many legacy files are **CRLF** (e.g. `Livechat.php`, `Social_accounts.php`, `Dashboard.php`, `bot_helper.php`). Keep each file's existing line endings. Check with `grep -c $'\r' file`. |
| Huge shared files | `Home.php` (~8k lines, AI pipeline at `get_ai_reply_open_ai`), `Messenger_bot.php`, `Comment_automation.php`. Change only the lines you need. Prefer new helpers/libraries. |

Key existing building blocks (reuse, do not duplicate):

- Conversations: `conversation_helper.php` (`conversation_get`, `conversation_transition`,
  `conversation_is_bot_allowed`), tables `conversations`, `conversation_events`, `customers`,
  `customer_identities`, trigger `trg_livechat_messages_link_conversation` on `livechat_messages`.
- Inbox: `controllers/Inbox.php`, `helpers/inbox_helper.php`, `helpers/inbox_realtime_helper.php`.
- Channels: `libraries/Channels/*` (adapters, `OutboundMessage`, `InboundMessage`),
  `channel_adapter_helper.php`, `channel_send_helper.php`, `channel_policy_helper.php`.
- AI: `libraries/Ai_provider.php`, `libraries/Ai_tools.php`, `ai_knowledge_helper.php`,
  `ai_safety_helper.php`, `ai_usage_helper.php` (cost log), `ai_agent_helper.php`.
- Events/automation: `event_helper.php` (`event_emit`), `libraries/Automation_engine.php`,
  `Automation_gateway`, `Automation_worker.php`, `Sequences.php`, `sequence_helper.php`, `Sequence_worker.php`.
- Pricing: `pricing_helper.php`, `controllers/Pricing.php`.
- Ops: `ops_health_helper.php`, `controllers/System_health.php`, `controllers/Health.php`.

---

## 2. Non-negotiable rules

1. **Never touch production.** No `docker exec` into `monkeybot-app-ojzrhf-*` except read-only
   inspection if a task says so. No edits under `/etc/dokploy`. No `cron_hub migrate` on production.
   No restarts. No deploys.
2. **Never print or commit secrets** (tokens, API keys, passwords, `.env*`). Test credentials are
   throwaway and stay out of git.
3. **Additive, reversible changes.** Migrations: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT
   EXISTS`, `ADD INDEX IF NOT EXISTS`. No `DROP`, no destructive `MODIFY`, no data deletes. New
   tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
4. **Code must work before its migration runs.** Guard new tables/columns with
   `$this->db->table_exists()` / `field_exists()` (cache the result in a static), exactly like
   `Inbox.php` does for labels.
5. **Flag-off = today.** Anything that changes live replies, sends, pauses or schedules goes behind a
   config flag that defaults OFF, with a test proving the OFF path is unchanged.
6. **Tenant isolation everywhere.** Every query on tenant data filters `user_id` first. Every write
   endpoint: `$this->ajax_check()` where AJAX, `$this->csrf_token_check()`, ownership check of every
   id received. Add a cross-tenant test for every new endpoint.
7. **No silent failure (§51).** Catch errors at boundaries, log at `error` level with a fixed prefix,
   store failures where an admin can see them. Never let a new feature throw into a webhook.
8. **Don't break channels.** Webhook handlers must still answer 200 fast. Network calls you add get
   short timeouts (≤ 8 s) and run after the essential work.
9. **Memory on this host is shared with other clients' production.** Before starting any Docker test
   stack run `free -g` and require **≥ 3 GB available**. If lower, wait and re-check. Never stop
   containers you did not start. Only one of your stacks up at a time.
10. **No arbitrary sleeps in tests**; poll for a condition with a timeout.

---

## 3. Standard workflow for every task

### 3.1 Branch and worktree
```sh
T=<task-id-lowercase>            # e.g. a1
git -C /root/worktrees/mb-integration worktree add /root/worktrees/mb-$T -b task/$T-<slug> release/9.x-integration
cd /root/worktrees/mb-$T
```
Work only inside your worktree. Never `git stash` (the stash is shared with other sessions).

### 3.2 Unit tests (no DB, no network)
Dependency-free suites live in `tests/<area>/run.php` (look at `tests/inbox/run.php` or
`tests/policy/run.php` for the style: small `check()` helpers, fake DB/transport objects).
```sh
docker run --rm -v $PWD:/app -w /app monkeybot-app-ojzrhf-app:latest php tests/<area>/run.php
```
Run **all** suites before you finish (skip `tests/conversations`, it needs credentials):
```sh
for t in tests/run.php tests/*/run.php; do case $t in *conversations*) continue;; esac
  echo "$t: $(docker run --rm -v $PWD:/app -w /app monkeybot-app-ojzrhf-app:latest php $t 2>&1 | tail -1)"; done
```
Lint every PHP file you touched: `docker run --rm -v $PWD:/app -w /app monkeybot-app-ojzrhf-app:latest php -l <file>`.

### 3.3 What to test
- The new behaviour (happy path + at least 2 failure paths).
- Flag OFF / before-migration: unchanged behaviour.
- Cross-tenant: tenant A can't read or change tenant B's rows through your endpoints.
- For wiring: a static check that the new call is present where it must be (see the
  "every livechat_messages insert is followed by inbox_realtime_notify()" check in `tests/inbox/run.php`).

### 3.4 HTTP/DB tests on an isolated stack
Copy the closest existing compose file (`docker-compose.inbox2-test.yml` is a good base) to
`docker-compose.<task>-test.yml`, set a unique project name and a free port **8110–8130**.
```sh
free -g                                                   # need >= 3 GB available
docker compose -p mb-<task>-test -f docker-compose.<task>-test.yml up -d
docker exec -i mb-<task>-test-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
docker exec -u www-data mb-<task>-test-app-1 php /var/www/html/index.php cron_hub migrate
STAGING_URL=http://127.0.0.1:<port> STAGING_DB=mb-<task>-test-db-1 STAGING_APP=mb-<task>-test-app-1 sh docker/scripts/staging_seed.sh
# run the existing suites that touch your area + your new docker/scripts/staging_<area>_tests.sh
docker compose -p mb-<task>-test -f docker-compose.<task>-test.yml down -v
git checkout -- application/config/database.php          # the container entrypoint rewrites it
git status --short                                        # must show only your intended changes
```
Staging suites take `STAGING_URL`, `STAGING_DB`, `STAGING_APP`. The automation and sequence suites
need `$config['automation_engine_enabled'] = TRUE;` appended to the stack's `application/config/config.php`
for the run (revert afterwards). Remove any `upload/`, `download/`, `application/cache` dirs and log
files the container created, and restore root ownership (`chown -R root:root application/config application/logs`).

### 3.5 Commit
```sh
git add <specific files>        # never `git add -A`
git -c user.name=cremonkey -c user.email=at.momtaz@gmail.com commit -m "<type>(<area>): <what>

<why, what changed, flags, migration>

Co-Authored-By: <your model name> <noreply@anthropic.com>"
```
Types: `feat`, `fix`, `test`, `docs`, `chore`. Small logical commits. Do not push. Do not merge.

### 3.6 Final report (your last message)
1. Task id + one-sentence outcome.
2. Branch, commits.
3. Behaviour change (flag names + defaults; what happens with the flag off).
4. Migration file(s) and whether code works before they run.
5. Files changed.
6. Test results: every suite with passed/failed counts; your stack suite counts.
7. **Not verified** (be explicit: real Meta/Telegram/OpenAI never called, browser not clicked, etc.).
8. Anything in this plan that was wrong.

---

## 4. Todo list

Legend — Size: S ≤ half day, M 1–2 days, L 3+ days. Risk = risk to live customer conversations.

### Phase A — Quick, low-risk fixes
- [ ] **A1** Livechat Pusher channels → private per-tenant, ids only (§13 security) — S, med — deps: none
- [ ] **A2** Health records for TikTok poll + lead decay; job counters (§7, §39) — S, low
- [ ] **A3** `agent_id` + `conversation_id` on AI cost log (§30) — S, low
- [ ] **A4** Record AI provider failures + tool failures (§20, §51) — S, low
- [ ] **A5** Agency Control Center: real "waiting" + integration health (§31) — S, low — deps: none
- [ ] **A6** Sequences/automations show "worker not running" instead of silently queueing (§51) — S, low
- [ ] **A7** `markSeen()` / `typingIndicator()` on the adapter interface (§10) — S, low
- [ ] **A8** Docs: legacy register (§35), ADR-005 framework decision draft (§36), deployment runbook refresh (§8) — S, none

### Phase B — Foundations
- [ ] **B1** General audit log (§40) — M, low
- [ ] **B2** Per-tenant feature flags (§50) — S/M, low
- [ ] **B3** Generic job queue + worker; move KB embedding and summaries onto it (§42) — M, low/med
- [ ] **B4** Pre-deploy check script (§8, §43) — S/M, none
- [ ] **B5** Correlation id webhook → AI → send; failure counters on System Health (§39, §51) — M, med — deps: A4
- [ ] **B6** Offline adversarial AI-safety suite (§45) — M, none
- [ ] **B7** Cross-tenant tests for CRM, analytics, uploads (§38) — M, none

### Phase C — Product features
- [ ] **C1** Customer 360 page + Inbox profile panel (read side) (§16, §13) — L, low
- [ ] **C2** Teams + team inbox (§13) — M, low — deps: B1
- [ ] **C3** Conversational CRM upgrades (§27) — M, low — deps: C1 helpful
- [ ] **C4** Knowledge system: update/version, re-index from UI, agent scope, citations (§19) — M, low — deps: B3
- [ ] **C5** AI Improvement Center new signals (§20) — M, low — deps: A4
- [ ] **C6** Business-first analytics KPIs + daily aggregates + insight sentences (§29, §41) — L, low — deps: B3
- [ ] **C7** ⚠ REVIEW GATE — Admin navigation regroup (§34) — M, low (touches every tenant's menu)
- [ ] **C8** Industry templates: sequences, automations, media, appointments (§33) — M, low
- [ ] **C9** Emit the missing events + new automation triggers/actions (§22, §23) — M/L, med — deps: B3
- [ ] **C10** CRM connector interface + unified outbox view (§28) — M, med
- [ ] **C11** ⚠ REVIEW GATE — Pricing extensions: seasons, weekday/weekend, min stay, packages, quantity discounts, coupons (§21) — L, med
- [ ] **C12** ⚠ REVIEW GATE — AI Employee Builder: structured fields, per-tool toggles, deterministic restrictions, booking tool (§17) — L, med
- [ ] **C13** API v2, read-only first (§37) — L, low — deps: C1, B2
- [ ] **C14** ⚠ REVIEW GATE — Customer merge + verified identity linking (§16) — M, med — deps: C1, B1

### Phase D — Core behaviour changes (all ⚠ REVIEW GATE, build flag-off only)
- [ ] **D1** Handoff paths through the state machine; bot gates read `conversation_is_bot_allowed` (§14) — M, high
- [ ] **D2** Automation modes replace staff hours + tenant timezone (§15) — L, high — deps: D1
- [ ] **D3** Legacy senders through the channel policy engine (§26) — M, high
- [ ] **D4** Inbound webhooks through `normalizeInbound()`, one channel at a time (§10, §11, §44) — L, high
- [ ] **D5** AI Orchestrator extraction with shadow compare (§18) — L, high — deps: A4, B5
- [ ] **D6** Follow-ups / re-engage as sequence templates (§25) — L, high — deps: D3
- [ ] **D7** Conversation core additions: `sender_type`, `ai_generated`, tiktok platform, OpenWA vs WA Cloud split (§12) — M, med/high
- [ ] **D8** S-14 charset conversion — migration prepared and rehearsed on a restored backup only (§51) — M, high
- [ ] **D9** Legacy `cron_job/*` jobs: readiness report + per-job enable plan (§7) — M, high

### OWNER / OPS (not for coding agents)
- [ ] Run `cron_hub migrate` on production for `2026-10-01-inbound-media.sql` (inbound media stays off until then).
- [ ] Approve S-14 plan (D8) and pick a maintenance window.
- [ ] Rotate the GitHub token (S-09), set a token-free remote, push `release/9.x-integration`.
- [ ] Decide on CSRF (S-07, globally off in `config.php`) — then a coding task to enable it per route.
- [ ] Webhook signature `enforce` once the counters meet `docs/security/webhook-signatures.md`.
- [ ] Graph v26: run `/graph_diagnostics` on one real page, then flip each group key.
- [ ] External uptime monitor on `https://bot.cremonkey.com/health`.
- [ ] Configure Pusher **only after A1 is deployed**.
- [ ] `automation_engine_enabled` + add the `automation_worker` / `sequence_worker` lines from
      `docker/cron/monkeybot` to the host's `/etc/cron.d/monkeybot` (they are missing there).
- [ ] OpenWA inbound media needs a file-fetch route on the separate gateway (`wa.cremonkey.com`).

---

## 5. Task cards

### A1 — Livechat Pusher channels: private, per tenant, ids only
- **Why:** `Home.php` publishes on `livechat_channel` (~line 7974) and `livechat_assigned_agent_channel`
  (~line 375). Both are public and install-wide, and events include message content: any tenant's
  browser could receive another tenant's messages once Pusher is configured. Today Pusher is empty in
  production, so this is dormant; it must be fixed before anyone configures Pusher.
- **Do:** Reuse `inbox_realtime_helper.php` (private channel naming with the install namespace,
  signing, queue-and-flush, timeouts). Publish Livechat events on the same private per-tenant channel
  (or a sibling `private-livechat-<ns>-<uid>`), with ids only. Add a channel-auth endpoint in
  `Livechat.php` that signs only the caller's own tenant channel (copy `Inbox::realtime_auth`). Change
  the Livechat JS to subscribe to the private channel and fetch the message by id through an existing
  endpoint. Keep behaviour identical when Pusher is unconfigured.
- **Also:** `Ci_pusher` depends on Composer's autoloader, which the app never loads. Either load the
  vendor autoloader only inside that library, or switch Livechat to the curl publisher in
  `inbox_realtime_helper.php`.
- **Accept:** No `livechat_channel` public subscribe left in views. Auth refuses other tenants'
  channels (test crafted names). Event payloads contain no message text (test). Pusher unset → no
  publish, no JS errors.
- **Files:** `Home.php` (2 publish sites only), `Livechat.php` (CRLF!), Livechat view JS, `inbox_realtime_helper.php`.

### A2 — Health records + job counters
- **Do:** Call `ops_health_record()` at the end of `Tiktok_cron::poll_comments` and
  `Cron_job::lead_score_decay` (success and failure). Add per-run counters (items processed,
  failed) to the record payload for `cron_hub run`, `automation_worker`, `sequence_worker`. Show them on
  `System_health`.
- **Accept:** System Health lists all five jobs with last success, last failure and counts. Unit test
  for the counter shape.

### A3 — AI cost log gets `agent_id` and `conversation_id`
- **Do:** Migration adding nullable `agent_id INT UNSIGNED`, `conversation_id BIGINT UNSIGNED` + index
  `(user_id, conversation_id)` to `ai_usage_log`. Extend `ai_usage_record()` with optional args (keep
  the old signature working). Pass them from `Ai_provider` (profile id from `ai_resolve_profile`,
  conversation via `conversation_ai_history_link`-style lookup) and the summarizer.
- **Accept:** Rows written by a reply carry both ids when resolvable; NULL otherwise; code works
  before the migration (field_exists guard). Analytics hub can later sum cost per conversation.

### A4 — Record AI provider and tool failures
- **Do:** `Ai_provider` has a catch-all that swallows errors. Record each failure (tenant, provider,
  model, purpose, error class/message truncated, http status) into a new table `ai_failure_log`
  (migration) and emit `ai.failed` via `event_emit` when the events table exists. In
  `Ai_tools::execute`, record tool errors/exceptions (tool name, args hash, error) into the same table
  with `kind='tool'`. Never change what the customer receives.
- **Accept:** Fake-provider unit tests: provider error → one row + event; tool exception → one row;
  reply path unchanged.

### A5 — Agency Control Center accuracy
- **Do:** In `Agency::index`, "waiting" = count of `conversations` with
  `handoff_state='WAITING_FOR_HUMAN'` (keep the old `bot_paused_until` number as a second column until
  D1 lands). Add per-client integration health: last `crm_external_log` failure / pending count,
  Sheets queue failures, last webhook seen per channel (max `livechat_messages.conversation_time` per
  platform where sender='user'), expired page tokens if a column records them.
- **Accept:** Admin-only; numbers match direct SQL in a stack test.

### A6 — No silent queueing when workers are off
- **Do:** When `automation_engine_enabled` is not TRUE, or the worker's last `ops_health` success is
  older than 10 minutes, show a clear banner on the Automations and Sequences pages ("Automations are
  paused on this server — nothing will be sent"), and make enroll/publish endpoints return that
  message in their JSON. Do not block saving drafts.
- **Accept:** Stack test with flag off shows the banner and the enroll response carries it.

### A7 — Adapter interface completeness
- **Do:** Add `markSeen(OutboundMessage|string $recipient, array $account)` and
  `typingIndicator(...)` to `ChannelAdapterInterface` with default "unsupported" results in
  `AbstractChannelAdapter`; implement for fb/ig (Send API `sender_action`) and WA Cloud (mark as read)
  only. Nothing calls them yet.
- **Accept:** Contract tests in `tests/channels/AdapterContractTest.php` with the fake transport;
  existing contract tests unchanged.

### A8 — Docs
- `docs/architecture/legacy-register.md`: every legacy module (controllers/modules list) classified
  KEEP / MODERNISE / REPLACE / DEPRECATE / REMOVE with one-line reason (§35). No code changes.
- `docs/architecture/adr/ADR-005-framework-direction.md`: status **Proposed**, options, trade-offs,
  recommendation (§36). Owner decides.
- `docs/runbooks/deployment.md`: replace the outdated "not pushed / no staging" text with the actual
  procedure used today (merge integration into the production branch in the Dokploy checkout, keep
  the local `config.php` diff, migrations first when code needs them, `/health` version check,
  rollback = previous commit). Mark it as owner-run.

### B1 — General audit log (§40)
- **Do:** Migration: `audit_log(id, user_id, actor_id, actor_type ENUM('user','team_member','admin','system','ai'), action VARCHAR(64), entity_type VARCHAR(40), entity_id VARCHAR(64), before_json MEDIUMTEXT NULL, after_json MEDIUMTEXT NULL, ip VARCHAR(45), created_at)` + indexes `(user_id, created_at)`, `(entity_type, entity_id)`.
  `audit_helper.php` with `audit_record($action, $entity_type, $entity_id, $before = null, $after = null)`
  (never throws, strips secrets: any key matching `/token|secret|password|key/i` is replaced with `***`).
- **Wire (one line each):** channel connect/disconnect (`Social_accounts`, `Whatsapp_bot`,
  `Openwa_bot`, `Telegram_bot`, `Webchat` account save/delete), `Ai_agents::save_profile` and
  assignments, `Automations` publish/disable, `Sequences` publish, team member invite/role change,
  exports, `Crm::deal_move`, `Inbox` takeover/assign, `Agency_provision` (replace its `log_message` audit).
- **UI:** read-only list for the tenant (own rows) and for admin (all), filter by entity/action/date.
- **Accept:** Static wiring test (every listed call site has `audit_record(`); secret-stripping test;
  cross-tenant test on the list endpoint.

### B2 — Per-tenant feature flags (§50)
- **Do:** Migration `feature_flags(user_id INT, flag VARCHAR(64), enabled TINYINT, updated_at, PRIMARY KEY(user_id, flag))`.
  `feature_flag_helper.php`: `feature_enabled($flag, $uid)` = tenant row if present, else global config
  item, else FALSE. Admin UI (Agency Control Center tab) to toggle per client. Seed nothing.
- **Accept:** Unit tests for precedence. Existing global flags keep working unchanged.

### B3 — Job queue (§42)
- **Do:** Migration `jobs(id, user_id, type, payload_json, status ENUM('queued','running','done','failed','dead'), attempts, max_attempts, run_after, locked_at, locked_by, last_error, created_at, updated_at)`.
  `job_helper.php` (`job_enqueue`, `job_claim` with `SELECT ... FOR UPDATE SKIP LOCKED` or an atomic
  UPDATE claim), `controllers/Job_worker.php` CLI (`is_cli()` gate, `GET_LOCK`, batch, exponential
  backoff, dead-letter after max attempts, `ops_health_record`). Handlers registry by `type`.
- **Move onto it (behind flag `jobs_enabled`, default OFF = today's inline behaviour):** KB chunk +
  embed after source add (`Ai_knowledge_base.php` ~252, ~293), `Inbox::summarize` regeneration.
- Add the cron line to `docker/cron/monkeybot` (host install is an OWNER step).
- **Accept:** Unit tests for claim/retry/dead-letter; stack test enqueues and processes a KB source.

### B4 — Pre-deploy check script (§8, §43)
- **Do:** `docker/scripts/predeploy_check.sh <ref>`: creates a temp worktree at `<ref>`, runs `php -l`
  on changed PHP files, all unit suites, the migration files' idempotency check (run twice on a
  throwaway DB), prints a pass/fail summary, cleans up. No deploy steps.
- **Accept:** Runs green on current integration; fails if a suite fails (prove with a temp broken commit, then drop it).

### B5 — Correlation id + failure dashboard (§39, §51)
- **Do:** Generate a request id at each webhook entry (`Home::central_webhook_callback`, `Openwa_bot`,
  `Whatsapp_bot`, `Telegram_bot`, `Webchat`), store in a CI global (`$CI->mb_request_id`), forward it
  in `mb_internal_headers` for internal forwards, and write it into `events.correlation_id`,
  `ai_usage_log`, `ai_failure_log`, `automation_action_log` (add nullable `request_id` columns where
  missing). System Health: "Failures (24h)" panel — AI failures, tool failures, send failures
  (`messenger_bot_reply_error_log`, channel send logs), CRM push failures, job failures — each with a
  link to details.
- **Accept:** Stack test: one web-chat message produces rows sharing one request id.

### B6 — Offline adversarial AI-safety suite (§45)
- **Do:** `tests/ai_safety/adversarial.php` using the fake provider: prompt-injection in the customer
  message, "ignore your rules", attempts to change system rules, malicious URL request, asking for
  another client's data, fake availability claims, malformed tool output, price below list. Assert
  that the deterministic layers win (price guard, link gate, tenant scoping, tool validation).
  Convert the live cases in `docs/danger-suite-v5.py` into offline fixtures where possible.
- **Accept:** Suite green; each case documents which layer blocks it. Cases that no layer blocks are
  reported as findings, not "fixed" with prompt text.

### B7 — Cross-tenant tests (§38)
- **Do:** Staging-style HTTP suite: tenant A tries every `Crm::deal_*` endpoint, `Analytics_hub`
  endpoints and direct `upload/...` paths of tenant B. Fix any leak found (minimal, separate commit).
- **Accept:** Suite green; list of endpoints covered in the report.

### C1 — Customer 360 (read side) + Inbox profile panel (§16, §13)
- **Do:** `controllers/Customers.php` (module: new id 413 "Customers", migration for `modules` row +
  package grant like `2026-09-26-sequences.sql`). List (search, channel filter, paginated) and profile page:
  identities per channel, conversations (with state), CRM deals (via `subscriber_id` today), labels,
  lead score, orders (`ecommerce_cart`), appointments, AI summary (`conversations.last_summary`),
  internal notes, recent events. Read-only. Tenant-scoped.
- Inbox right rail: compact version of the same (score, stage, tags, other conversations, orders) via
  one new endpoint `Inbox::customer_panel`.
- **Accept:** Stack test with seeded data across 2 channels for one customer; cross-tenant test.

### C2 — Teams + team inbox (§13)
- **Do:** Migration `teams(id, user_id, name, created_at)`, `team_memberships(team_id, team_member_id)`,
  nullable `conversations.assigned_team_id`. Inbox: assign to team, filter "My team", round-robin
  optional (off). Audit via B1.
- **Accept:** Stack tests for assign/filter and cross-tenant.

### C3 — Conversational CRM (§27)
- **Do:** Migration on `crm_deals`: nullable `customer_id`, `conversation_id`, `intent VARCHAR(40)`,
  `next_action VARCHAR(255)`, `next_action_at DATETIME`. Fill on create where resolvable. Stage editor
  UI in `Crm` (add/rename/reorder/delete-empty). New pipelines (not existing ones) seed
  New / Qualified / Follow-up / Proposal / Won / Lost. A "qualified" rule in `lead_scoring_helper.php`
  (score threshold config) that emits `lead.qualified` once.
- **Accept:** Existing pipelines untouched (test); stage editor tenant-scoped.

### C4 — Knowledge system (§19)
- **Do:** Migration on `ai_knowledge_sources`: `version INT DEFAULT 1`, `content_hash CHAR(40)`,
  nullable `agent_id`. `update_source_action` (new content → version+1, re-chunk via B3 job),
  `reindex_action` button, optional agent scope used by `ai_get_knowledge_context` (NULL = all agents).
  Log the chunk/source ids used into `ai_reply_audit` (new nullable column) for citations.
- **Accept:** Unit tests for versioning and scope; reply path unchanged when no agent scope set.

### C5 — AI Improvement Center signals (§20)
- **Do:** Add tabs/cards: tool failures (A4 table), repeated escalations (`conversation_events` to
  `WAITING_FOR_HUMAN` grouped by first customer message topic), human corrections (Inbox agent reply
  sent within N minutes after an AI reply in the same conversation), low-confidence (price guard
  'unknown' verdicts). Same grouping helper as today.
- **Accept:** Stack test with seeded rows shows each card.

### C6 — Analytics (§29, §41)
- **Do:** Daily aggregate table `analytics_daily(user_id, day, channel, metric, value)` filled by a
  job (B3) or `cron_hub` step for the previous day + today incremental. KPIs: qualified leads,
  bookings, human takeover rate, AI resolution rate, avg resolution time, human first-response time,
  follow-up success, re-engagement success, unanswered-question rate, cost per conversation, cost per
  qualified lead. Insight sentences generated by rules (not AI), e.g. "12 leads stopped replying after
  a price quote this week". Keep the current `Analytics_hub` numbers; move heavy queries to the aggregate.
- **Accept:** Unit tests for each KPI formula on fixtures; page loads with aggregates only.

### C7 — ⚠ Admin navigation regroup (§34)
- **Do:** Migration that updates only `serial`, `header_text`, `have_child`, parent links in `menu`
  to the brief's groups (Inbox / AI Sales / Automations / Customers / Insights / Settings) and moves
  legacy items under "Advanced / Legacy". Save the previous values in `menu_backup_20260924` (created by the
  migration) so it can be reverted. No item removed, no URL changed.
- **Gate:** screenshot/HTML of the sidebar before/after for admin and a member, attached to the
  report; owner approves before merge.

### C8 — Industry templates (§33)
- **Do:** Extend the template JSON schema (`application/data/industry_templates/*.json`) with optional
  `sequences`, `automation_rules`, `media` (placeholders), `appointments` settings. The Setup Guide
  offers them on a new optional step, created **disabled** so the owner switches them on.
- **Accept:** Old templates still load; unit tests for the apply plan (like `industry_template_faq_plan`).

### C9 — Events + automation triggers/actions (§22, §23)
- **Do:** Emit (guarded, never throws): `conversation.state_changed` in `conversation_transition`,
  `human.assigned` in `Inbox::assign`, `price.requested` in `Ai_tools::t_calculate_price`,
  `question.unanswered` where `ai_unanswered_questions` is inserted, `customer.created` in the
  customer upsert, `order.created`/`checkout.*` in `Ecommerce`. Follow the list in
  `docs/automations/engine.md` §3. Skip `message.*` (needs a volume decision — report it).
  Automation: new trigger types keyword/tag/inactivity/score-threshold via a scanner in
  `Automation_worker`; new actions assign_agent, update_field, notify_customer_email (reuse existing mailer).
- **Accept:** Unit tests per emit (static wiring + payload shape); engine tests for new triggers/actions.

### C10 — CRM connectors (§28)
- **Do:** `libraries/Crm_connectors/CrmConnectorInterface.php` (`pushLead`, `pushDealUpdate`,
  `testConnection`), adapters wrapping the existing 8xCRM (`external_crm_helper`) and Sheets
  (`crm_sheet_helper`) code without changing their behaviour; registry; one admin view listing
  outbox rows (both sources) with status and a retry button.
- **Accept:** Existing 8xCRM/Sheets tests unchanged; retry endpoint tenant-scoped.

### C11 — ⚠ Pricing extensions (§21)
- **Do:** Extend `pricing_config` JSON + `pricing_validate_config`: seasons (date ranges with price
  overrides), weekday/weekend rates, minimum stay, packages (bundle of items with a price), quantity
  discounts, coupons (code, %, fixed, validity). Calculators return an itemised breakdown that is
  added to `ai_price_facts` (so the price guard sees it). Pricing page UI for each.
- **Gate:** existing configs must produce byte-identical results (golden tests on the current Byoot
  config `docs/byoot-pricing-config.json`). Owner reviews before merge.

### C12 — ⚠ AI Employee Builder (§17)
- **Do:** Migration adding structured columns to the agent profile table (role, goal, tone,
  capabilities_json, restrictions_json: max_discount, allowed_link_domains, forbidden_topics,
  escalation_rules). `Ai_agents` UI to edit them; per-tool enablement read by `Ai_tools` (a tool not
  enabled is not offered). Restrictions enforced in code after generation (link domain filter,
  discount ceiling, forbidden topic → handoff). New `t_create_booking` tool using the appointments module.
- **Gate:** profiles with no structured fields behave exactly as today (test). Owner reviews.

### C13 — API v2 read-only (§37)
- **Do:** `controllers/api/v2/` with Conversations, Customers, Crm (deals), Analytics — GET only.
  Token auth: new `api_tokens(user_id, token_hash, name, scopes, last_used_at, revoked_at)` table,
  tokens shown once, stored hashed. Tenant scope from the token. JSON envelope, pagination, rate limit
  per token. Versioned routes in `routes.php` — **routes.php is in the config volume**: do not add
  routes there; use the controller path `api/v2/...` that CI resolves without custom routes.
- **Accept:** HTTP tests incl. cross-tenant and revoked token.

### C14 — ⚠ Customer merge + verified identities (§16)
- **Do:** `customer_merge($keep_id, $merge_id, $actor)` in a helper: moves identities and
  conversations, writes `customer_merge_log` and B1 audit, reversible (store the moved ids). Suggest
  merges when the same verified phone/email appears on two customers; never auto-merge.
- **Gate:** owner reviews merge rules.

### D1 — ⚠ Handoff through the state machine (§14)
- **Do:** `Ai_tools::t_handoff` and the Livechat pause toggle call `conversation_transition()` (which
  already mirrors `bot_paused_until`). Then, behind flag `bot_gate_uses_conversation_state` (OFF), make
  the bot gates in Messenger_bot / Instagram_reply / Openwa_bot / Whatsapp_bot / Telegram_bot / Webchat
  call `conversation_is_bot_allowed()` instead of reading `bot_paused_until`.
- **Accept:** flag OFF: every gate decision identical to today (table-driven test over states and
  pause times). flag ON: WA Cloud / TG / Web can now be paused. Transition audit rows present.

### D2 — ⚠ Automation modes (§15)
- **Do:** Modes: 24/7 AI, AI outside office hours, Human first + AI fallback after N minutes, AI first
  + human escalation, Manual only. Per tenant with per-channel override; tenant timezone (remove the
  hardcoded `Africa/Cairo` in `working_hours_helper.php:36` — default to Africa/Cairo only when the
  tenant has none); multiple windows per day; holidays. Map existing `hours_*` settings:
  enabled → "AI outside office hours", disabled → "24/7 AI". Human-first fallback via a delayed job
  (B3). Behind flag `automation_modes_enabled` (OFF).
- **Accept:** flag OFF identical; mapping tests; timezone tests incl. DST-free zones.

### D3 — ⚠ Legacy senders through the policy engine (§26)
- **Do:** `Cron_hub` follow-ups (~lines 408, 479), `reengage_helper`, `Livechat` sends,
  `Messenger_bot_broadcast`: ask `channel_policy_decide()` before sending, behind flag
  `legacy_senders_use_policy` (OFF). Move rate limits from `sequence_helper` into the policy helper
  (same numbers). Log would-block decisions while OFF (`policy: would block`).
- **Accept:** OFF identical + would-block logging; ON blocks exactly what the policy says (tests).

### D4 — ⚠ Inbound through adapters (§10, §11, §44)
- **Do:** For one channel at a time (start with Webchat, then Telegram, WA Cloud, OpenWA, fb/ig),
  behind `inbound_adapter_<channel>` flags (OFF): parse with `normalizeInbound()`, then feed the same
  downstream code. Shadow mode first: when OFF, run `normalizeInbound()` in addition and log any
  difference from the legacy parse (`inbound_shadow: diff`). Add the missing contract cases (invalid
  signature, unknown account, blocked user, opt-out, AI disabled, human takeover).
- **Accept:** Shadow diff zero on the fixture corpus; OFF path unchanged.

### D5 — ⚠ AI Orchestrator (§18)
- **Do:** `libraries/Ai_orchestrator.php` with the brief's stages as separate methods, each unit
  testable. `Home::get_ai_reply_open_ai` delegates only when flag `new_ai_orchestrator` is ON. Shadow
  mode: with the flag OFF but `ai_orchestrator_shadow` ON, run the orchestrator's prompt assembly
  (no model call) and log differences in the assembled messages/tools vs legacy.
- **Accept:** Shadow diff zero on recorded fixtures for every channel; flag OFF = legacy path untouched.

### D6 — ⚠ Follow-ups and re-engage as sequences (§25)
- **Do:** Adapters so Sales_automation follow-ups and Reengage campaigns can be represented as
  sequence templates; a migration tool that converts a tenant's settings into a disabled sequence for
  review. Auto-enroll on events (C9). Nothing switches over automatically.
- **Accept:** Converted sequences reproduce the legacy schedule on fixtures.

### D7 — ⚠ Conversation core additions (§12)
- **Do:** `livechat_messages`: nullable `sender_type ENUM('customer','ai','human','system','automation')`,
  `ai_generated TINYINT` — filled by writers going forward (no backfill). `conversations`: platform
  value for tiktok, and a `channel_kind` column separating `wa_cloud` from `openwa` (the shared `wa`
  value collides). Hot table: `ADD COLUMN` must be instant (nullable, at end); measure on a restored backup.
- **Accept:** Trigger still works; writers set the new columns; old readers unaffected.

### D8 — ⚠ S-14 charset (prepare + rehearse only)
- **Findings so far (2026-09-23):** 105 tables are `utf8mb3_general_ci`, 29 `utf8mb4_general_ci`,
  57 `utf8mb4_unicode_ci`, plus some `latin1` columns. Switching only the connection to utf8mb4 makes
  queries with 4-byte characters against utf8mb3 columns fail ("Illegal mix of collations").
  Converting only mb3→`utf8mb4_general_ci` then breaks comparisons between `utf8mb4_general_ci` and
  `utf8mb4_unicode_ci` columns (reproduced in `cron_hub run` and `backfill_conversations`).
- **Do:** Generate a migration that converts **every** `utf8mb3_general_ci` and
  `utf8mb4_general_ci` text column to `utf8mb4_unicode_ci` **per column** (`MODIFY` with the full
  column definition from `information_schema`: type, nullability, default, `on update`, comment),
  table defaults to `utf8mb4_unicode_ci`, `latin1` columns untouched. Generate it from a **restored
  copy of the nightly backup** (`/root/backups/monkeybot-nightly/<latest>/db.sql.gz`) loaded into a
  throwaway `mariadb:10.11` container on tmpfs — never from the production container. Check unique
  indexes for new collisions under unicode collation before converting. Rehearse on that copy, time it,
  then run every staging suite against a stack whose connection is `utf8mb4` / `utf8mb4_unicode_ci`.
- **Accept:** Report with timing, collision check result, suite results. **Do not** apply anywhere
  else. The owner schedules production.

### D9 — ⚠ Legacy cron jobs (§7)
- **Do:** For each unscheduled job (`subscriber_broadcaster`, `sequence_message_broadcast_hourly/daily`,
  `sms_sending_command`, `email_sending_command`, `publish_post`, `auto_like/share/comment`,
  `woo/ecommerce_abandoned_cart_reminder`, `membership_alert_delete_junk_data`,
  `expired_users_disable_bot`): read-only backlog query (what would it send right now, how old),
  risk notes, and a per-job enable plan. Until a job is enabled, its UI shows "not running on this
  server". Write `docs/runbooks/legacy-cron-enablement.md`. Do not add any cron line.
- **Accept:** Doc + UI notices + backlog numbers (from a restored backup, not production).

---

## 6. Reference: facts a coding agent often gets wrong here
- Runtime page ids per channel (what the AI pipeline gets as `page_id`): fb/ig = external Facebook
  page id string; OpenWA = `session_id`; WA Cloud = account id; Telegram = account id; web = widget_key.
- `ai_faq` rows apply when `page_id` matches, or when `page_id` is NULL/''.
- Onboarding target keys: `fb:<page table id>`, `wa:<cloud id>`, `openwa:<id>`, `tg:<id>`, `web:<widget_key>`.
- Pricing scopes: `fb`, `wa_cloud`, `openwa`, `tg`, `web`; `('', '')` is the account default list.
- CodeIgniter rejects `=` in CLI URI segments: use `--user 12` or `--user:12`, not `--user=12`.
- The migration runner splits on semicolons: no `;` inside SQL comments; start files with `DO 0;`.
- Test-stack containers rewrite `application/config/database.php`; always `git checkout` it before committing.
- `messenger_bot_subscriber` is utf8mb3; emoji in names are lost until D8 ships.
