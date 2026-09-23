# MONKEY BOT TRANSFORMATION BASELINE REPORT

- **Date:** 2026-09-22
- **System:** monkey bot 8.5.4 (rebranded XeroChat), live at `bot.cremonkey.com`
- **Scope:** a read-only discovery audit (brief §67), followed by the first Phase 0 stabilisation work done on the same day.
- **Evidence base:** the audit fact sheet (`/root/MONKEYBOT_EXPLAINER_PROMPT.md`, "FACT SHEET"), a code read of this tree, and host state (`/etc/cron.d/monkeybot`, `/var/log/monkeybot-cron.log`).
- **Secrets:** no secret values appear in this document.

## How to read the status fields

There are two independent scales.

**Component status (brief §59).** Used in section A.

| Label | Meaning |
|---|---|
| Verified Live | Implemented, working, and we have evidence. |
| Implemented / awaiting activation | Built, but not switched on or not yet in use. |
| Partial | Important functionality is still missing. |
| Broken / Risky | A known operational or security risk. |
| Legacy / Stock | Inherited XeroChat code that has not been modernised. |

**Finding status (brief §58).** Used for every finding.

| Label | Meaning |
|---|---|
| Open | Not addressed. |
| Fixed today: Verified | Changed today, with evidence that it works. |
| Fixed today: Implemented but not tested | Changed today (the code is live because of the bind mount), but not exercised against the live system. |
| In progress | Work has started but is not complete. |
| Blocked | Waiting on the owner or on access. |

**Severity:** P0 is critical and needs action now. P1 is high. P2 is medium. P3 is low.

---

## A — Production Status

### Runtime

| Item | Value |
|---|---|
| Framework | CodeIgniter 3.1.5 + HMVC (MX) |
| PHP | 8.1.33 FPM |
| Web server | nginx 1.27-alpine |
| Database | MariaDB 10.11 |
| Hosting | Dokploy on `server.cremonkey.com`, behind Traefik with Let's Encrypt TLS |
| Compose file | `docker-compose.dokploy.yml` |
| Containers | `monkeybot-app-ojzrhf-{app,web,db}-1`, all `restart: unless-stopped` |
| Code deployment | Repo is bind-mounted `./:/var/www/html` (read-write in `app`, read-only in `web`). No build step: every file edit is live immediately. |
| Volumes | External volumes `monkeybot_*` shadow these paths: `application/config`, `application/cache`, `application/logs`, `upload`, `upload_caster`, `download`. The database lives in `monkeybot_db_data`. |
| Secrets | `/root/monkeybot/.env`, read through `env_file`. Keys: APP_NAME, MYSQL_*, APP_BASE_URL, TZ. |

### Data snapshot

| Measure | Value |
|---|---|
| Database size | 172 tables, about 97 MB |
| Users | 2 (1 active admin, 1 inactive member) |
| Packages | Trial (7 days, default) and Monthly |
| Facebook pages | 8 connected, 5 with the bot on |
| Subscribers | 8,977 (FB 8,908, WA 59, IG 10) |
| `livechat_messages` | 41,926 rows, latest 2026-09-19 |
| AI conversation history | 17,757 rows before retention ran (see F-02) |
| `ai_reply_audit` | 18,203 rows |
| Price-guard blocks | 530 |
| CRM deals | 304 |
| External CRM pushes | 324 |
| Channel accounts | OpenWA 1, web widgets 2, WhatsApp Cloud 0, Telegram 0, TikTok 0 |

### Component status (§59)

| Component | Status | Note |
|---|---|---|
| FB Messenger and IG DM bot engine | Verified Live | Live traffic, 187 bot replies, 77 flows |
| FB and IG comment automation | Verified Live | Stock engine plus AI |
| OpenWA (WhatsApp Web) | Verified Live | 1 account, 163 logged messages, no inbox |
| WhatsApp Cloud API | Implemented / awaiting activation | 0 accounts; menu item still visible |
| Telegram | Implemented / awaiting activation | 0 accounts |
| Web widget | Partial | 2 widgets, 466 messages; no agent reply screen |
| TikTok | Broken / Risky | Default API mode `display` cannot reply; DMs are stubbed |
| AI reply engine (SPEC-02…31) | Verified Live | Provider is `openai` |
| Scheduler (`cron_hub`) | Verified Live since 2026-09-22 15:10 | See F-01 |
| Legacy `cron_job/*` jobs | Broken / Risky | Not scheduled; see F-05 |
| Appointment booking | Partial | Reminders do not send; not linked to the AI or chat |
| E-commerce, payments, SMS/email, posting | Legacy / Stock | E-commerce tables are empty |
| Staff hours | Implemented / awaiting activation | Enabled on 0 pages; enforced inconsistently across channels |
| Health endpoint and System health page | In progress | `Health.php` and `System_health.php` exist but are uncommitted and not verified |

---

## B — Architecture Map

```
Meta ──> /home/central_webhook_callback (Home.php)
           │  verify-token handshake; X-Hub-Signature-256 gate (log mode)
           │  re-POSTs response_raw + X-MB-Internal HMAC to:
           ├─> messenger_bot/webhook_callback_main      FB and IG DMs
           ├─> comment_automation/webhook_callback_main FB feed and comments
           └─> instagram_reply/webhook_callback         IG comments and mentions
OpenWA  ──> /openwa_bot/webhook/{id}   (X-OpenWA-Signature)  -> Openwa_bot extends Messenger_bot
WA Cloud──> Whatsapp_bot  (per-account HMAC, fails closed)   -> AI only
Telegram──> Telegram_bot  (secret-token header)             -> AI only
Web     ──> widget send/poll (CORS, rate-limited)            -> AI only
TikTok  <── tiktok_cron poll_comments (host cron)            -> fixed text or AI

All AI replies ──> Home::get_ai_reply_open_ai ──> Ai_provider (OpenAI | Anthropic)
                                               ├─ Ai_tools (7 tools)
                                               ├─ hybrid RAG (KB)
                                               └─ guards: price judge, booking, links, audit
Outbound (cron, AI tools, Re-engage) ──> channel_send_helper (fb/ig Graph, wa OpenWA→Cloud, tg, web)
Graph calls ──> fb_graph_helper.php (versions: v26.0 default, v20.0 legacy; ~70 call sites still on v20.0)
Scheduler: host /etc/cron.d/monkeybot ──docker exec──> php index.php cron_hub run | tiktok_bot/tiktok_cron poll_comments
```

Code shape:

- `Home.php` (8,245 lines) is the base controller and also holds the AI engine.
- `Messenger_bot.php` is 14,840 lines.
- Models are `Basic.php` (generic CRUD) and Grocery CRUD only.
- Custom specs live in `.specs/` (SPEC-00…31).
- Schema changes are SQL files in `assets/backup_db/migrations/` (32 files), applied by `php index.php cron_hub migrate` and tracked in `schema_migrations`.

---

## C — Data Model Map

| Concept | De-facto table(s) today | Notes |
|---|---|---|
| Tenant / account | `users`, packages | Module access is built at login from the package. |
| Channel account | `facebook_rx_fb_page_info` (FB/IG), OpenWA, WA Cloud, Telegram, widget and TikTok tables | One table per channel. |
| Customer | `messenger_bot_subscriber` | Holds FB, IG and WA subscribers. It also carries `lead_score`, `bot_paused_until` and interaction times. Telegram, WA Cloud and web visitors have no subscriber row. |
| Message | `livechat_messages` | Has a `platform` column; 41,926 rows. It is the nearest thing to a unified message store (ADR-001). |
| Bot rules | `messenger_bot`, postback tables | 187 rows (fb/ig/wa via `media_type`); flows compile into these rows. |
| AI memory | `ai_conversation_history` | Retention is 60 days. |
| AI audit | `ai_reply_audit`, `ai_price_guard_log`, `ai_usage_log` | Retention is 120–180 days. |
| AI configuration | `open_ai_config`, `ai_agent_*`, `ai_agent_assignments`, `ai_faq`, `pricing_config` | Keys are stored encrypted (`enc::`). There is no UI for `pricing_config`. |
| Knowledge base | KB source and chunk tables | 9 chunks. |
| CRM | CRM deal, task and contact tables; `crm_external_log` (8xCRM queue) | 304 deals. |
| Migrations | `schema_migrations` | The `openwa_channel` migration is not recorded there. The `hours_*` columns were added by hand with no migration. |

**Foreign-key risk.** The schema is XeroChat-style: there are no FK constraints and IDs are shared across channels. This gives an inferred ID-collision risk between OpenWA account IDs and FB page IDs in the flow builder.

**Large tables:** `livechat_messages`, `messenger_bot_subscriber`, `ai_reply_audit`, `ai_conversation_history`.

---

## D — Channel Capability Matrix

See `docs/channels/capability-matrix.md`.

In summary: the full bot engine exists only for FB, IG and OpenWA. WA Cloud, Telegram and the web widget are AI-only text channels with no inbox. TikTok is not functional in the default mode.

---

## E — Security Findings

### S-01 Cron endpoints publicly executable

| Field | Detail |
|---|---|
| Evidence | `cron_secret` is unset, and `Cron_hub::run` used to accept any request. `Cron_job::api_key_check()` had `return TRUE;` as its first line. On 2026-09-22 a single GET to `/cron_hub/run` ran a real tick (see F-02). |
| Severity | P0 |
| Business impact | Anyone on the internet could trigger customer sends (follow-ups, digests, re-engage, CRM pushes) and retention deletes. |
| Technical impact | Uncontrolled job execution and duplicate sends. |
| Recommended fix | Allow the CLI only. Allow HTTP only with a configured `cron_secret`, and refuse it when the secret is unset. Remove the bypass in `api_key_check`. |
| Done today | `Cron_hub::_authorized` now always allows the CLI; over HTTP it requires `cron_secret` and `hash_equals` on `?key=`, and refuses when the secret is unset (it is unset today). `Tiktok_cron` has the same gate. In `api_key_check`, the bypass is removed: the CLI is still allowed, and HTTP needs a valid `native_api` key belonging to an active Admin. Sales_automation "Run now" now runs the tick through the PHP CLI (`shell_exec`). |
| Risk of fix | Low. Any external HTTP caller of these endpoints now gets 403 or an error; none is known. |
| Verification method | Send HTTP GET to `/cron_hub/run`, `/tiktok_bot/tiktok_cron/poll_comments` and `/cron_job/lead_score_decay` with no key. Each must be refused and must not create a new `ops_health.json` entry. Also confirm that "Run now" returns the tick JSON. |
| Status | Fixed today: Implemented but not tested. The code is lint-clean. It has not been verified with a live HTTP request, because tooling policy blocked live calls. |

### S-02 Meta webhooks not signature-verified; internal handlers accept forged POSTs

| Field | Detail |
|---|---|
| Evidence | `central_webhook_callback` did not check `X-Hub-Signature-256`. The three internal handlers are public URLs that accept a `response_raw` POST. |
| Severity | P0 |
| Business impact | A forged payload could make the bot send messages, run flows, create CRM deals or spend AI budget for any connected page. |
| Technical impact | Arbitrary input reaches the largest controllers in the codebase. |
| Recommended fix | Verify HMAC-SHA256 against the app secret. Sign internal forwards. Roll out as log first, then enforce. |
| Done today | New `application/helpers/webhook_security_helper.php`. The central callback verifies against `facebook_rx_config.api_secret` (decrypted). Forwards carry `X-MB-Internal`. The three handlers check it through `mb_internal_gate`, and the `Webview_builder` forwarder signs too. Both modes default to `log`: outcomes are counted in `application/cache/webhook_security.json` and nothing is rejected. |
| Risk of fix | High if enforced blind: a wrong or missing app secret would stop every FB/IG reply. This is why log mode comes first. |
| Verification method | Watch the counters in `webhook_security.json` and on the System health page. See `docs/security/webhook-signatures.md` for the criteria. |
| Status | In progress. Implemented / awaiting activation (log mode). The code is not yet committed. |

### S-03 Repo root is web-served

| Field | Detail |
|---|---|
| Evidence | These could be downloaded: `vendor/vendor.zip` (32 MB), `composer.lock`, the compose files, `DOKPLOY_SETUP.md`, `SECURITY_DEPLOYMENT_REPORT.md`, and `docs/` (sales prompts, client pricing JSON). |
| Severity | P0 |
| Business impact | Leaks client pricing, the proprietary sales prompts and the infrastructure layout. |
| Technical impact | Attacker reconnaissance and dependency fingerprinting. |
| Recommended fix | Add nginx deny rules now. The long-term fix is a dedicated public web root. |
| Done today | Deny rules in `docker/nginx/default.conf` for `/vendor`, `/docs`, `composer.*`, `docker-compose*.yml`, root `*.md`, `*.sql`/`*.py`/`*.sh`/`*.bak`, and `*.bak-*`. These add to the existing blocks on `/application`, `/system`, `/ci`, `/docker`, `/assets/backup_db`, dotfiles and PHP inside upload folders. |
| Risk of fix | Low. Public paths were re-checked after the change. |
| Verification method | Probe each path and check the response code. |
| Status | Fixed today: Verified. 403 on `vendor.zip`, `composer.lock`, `docker-compose.yml`, `DOKPLOY_SETUP.md`, `docs/*` and `SECURITY_DEPLOYMENT_REPORT.md`. 200 still on `/`, `/home/login_page`, `/documentation/`, the TikTok verification files and site assets. |

### S-04 OpenAI key used without decryption; price guard fails open

| Field | Detail |
|---|---|
| Evidence | The embeddings, chunking, price judge, Whisper and agent preview paths read the stored `enc::` value as the raw API key. |
| Severity | P1 |
| Business impact | The price-grounding judge fails, and the guard then **fails open**: a price hallucination can reach a customer. Voice notes and KB embeddings silently fail. |
| Technical impact | Several credential paths, and failures that are hidden. |
| Recommended fix | Make one credential service (`ai_openai_key()` through `secret_decrypt`) and route every AI call through it. Make the price guard fail closed, or hand off to a human, when the judge is unavailable. |
| Done today | `ai_openai_key()` now decrypts through `secret_decrypt`. `ai_voice_helper` and `Ai_agents::preview` use it. |
| Risk of fix | Low. |
| Verification method | Send a voice note on a test page and confirm the transcript. Run a KB re-chunk (`cron_hub rechunk_kb <id>`) and confirm embeddings. Trigger a price question and confirm a judge verdict row. |
| Status | Partial. Fixed today: Implemented but not tested (lint-clean). Still open: `Ai_agents::preview` always calls OpenAI even when the provider is Anthropic, and the fail-open behaviour of the price guard is unchanged. |

### S-05 Knowledge-base IDOR

| Field | Detail |
|---|---|
| Evidence | `preview_chunks_action` returned the chunks for any source ID it was given. |
| Severity | P1 |
| Business impact | One tenant could read another tenant's knowledge base. |
| Technical impact | Cross-tenant data exposure. |
| Recommended fix | Check source owner == session user (or admin) on every KB action. |
| Done today | The ownership check was added to `preview_chunks_action`. |
| Risk of fix | Low. |
| Verification method | As user A, request user B's source ID. The expected result is refused or empty. The user's own source must still work. |
| Status | Fixed today: Implemented but not tested. The other KB actions have not yet been audited against the same rule. |

### S-06 Custom modules not package-gated

| Field | Detail |
|---|---|
| Evidence | CRM, AI Agents, Sales Automation, Content Writer, Booking and Analytics Hub are visible to every member, whatever their package. |
| Severity | P1 |
| Business impact | Paid features are given away. Packages cannot be sold as tiers. |
| Technical impact | Permission logic is missing, or scattered across UI templates. |
| Recommended fix | A central entitlement service behind a flag, run in log mode first. |
| Done today | Another engineer is implementing it behind `entitlement_enforce_custom_modules` (default off) together with a migration file, which is not yet applied. |
| Risk of fix | Medium. Enforcing it could lock the active admin or member out of features they use. |
| Verification method | Log-only first. Compare a member on the Trial package with an admin on each module URL and in the sidebar. |
| Status | In progress. |

### S-07 CSRF protection off globally

| Field | Detail |
|---|---|
| Evidence | CI3 `csrf_protection` is off. The exclusion list in `config.php` already names the webhook URIs. |
| Severity | P1 |
| Business impact | A logged-in admin could be tricked into changing settings or sending messages. |
| Technical impact | Every state-changing POST is exposed. |
| Recommended fix | Turn CSRF on with an audited exclusion list (webhooks, public widget, booking). Check the AJAX forms carry the token. |
| Risk of fix | Medium to high: legacy AJAX forms may break. |
| Verification method | A staging smoke test of every admin form. This needs staging (G-04). |
| Status | Open (deferred). |

### S-08 Legacy code hazards

| Field | Detail |
|---|---|
| Evidence | Old bundled libraries, cURL SSL verification turned off in legacy code, and JS `eval`. |
| Severity | P2 |
| Business impact | Low while these stay unexploited. |
| Technical impact | Man-in-the-middle exposure on outbound calls; XSS amplification. |
| Recommended fix | Inventory the libraries. Turn SSL verification on per call site. Remove `eval`. |
| Risk of fix | Medium: SSL verification may fail against misconfigured third parties. |
| Verification method | grep inventory, then staging tests. |
| Status | Open. |

### S-09 Git remote URL embeds a credential

| Field | Detail |
|---|---|
| Evidence | The `origin` URL in `.git/config` (github.com/cremonkey/MonkeyBot) contains an embedded access token. The value was not printed. |
| Severity | P1 |
| Business impact | Anyone who reads the server's `.git/config` gets push or read access to the source repository. |
| Technical impact | Credential exposure. `.git` is protected from the web by the dotfile deny rule, but not from host or container access. |
| Recommended fix | Revoke and rotate the token. Set the remote to a token-free URL, and use a deploy key or a credential helper. |
| Risk of fix | Low. |
| Verification method | `git remote get-url origin` contains no credentials, and the old token is revoked in GitHub. |
| Status | Open. The owner must do this, because it needs GitHub access. |

### S-10 Encryption key doubles as the internal HMAC key

| Field | Detail |
|---|---|
| Evidence | `mb_internal_sign` uses `config encryption_key`. The real key was deliberately kept out of git (a placeholder is committed). |
| Severity | P3 |
| Business impact | None today. |
| Technical impact | One key serves two purposes. Rotating it would invalidate both the stored `enc::` values and the internal signatures. |
| Recommended fix | Before enforcing, use a separate `internal_webhook_secret`, derived from or independent of the encryption key. |
| Risk of fix | Low. |
| Verification method | Config review. |
| Status | Open (recommendation). |

### S-11 Telegram delete leaves the webhook registered

| Field | Detail |
|---|---|
| Evidence | Fact sheet section C. |
| Severity | P3 |
| Business impact | A deleted bot keeps delivering to our endpoint. |
| Technical impact | Orphan traffic. |
| Recommended fix | Call `deleteWebhook` when an account is deleted. |
| Risk of fix | Low. |
| Verification method | Telegram `getWebhookInfo` after a delete. |
| Status | Open (0 accounts today). |

---

## F — Scheduler / Automation Health

### F-01 No scheduler was installed

| Field | Detail |
|---|---|
| Evidence | The old cron file targeted a container that does not exist, and was never installed. Nothing in Dokploy scheduled the jobs. Follow-ups, the digest, re-engage, CRM push, alerts, retention and TikTok polling never ran on their own. |
| Severity | P0 |
| Business impact | Silent leads were not followed up, the 8xCRM queue did not drain and digests were not sent. |
| Technical impact | Queues grew; retention never ran. |
| Recommended fix | A host cron that uses `docker exec` as `www-data`, with `flock`, `timeout` and the existing DB `GET_LOCK`. |
| Done today | `/etc/cron.d/monkeybot` installed: every 5 minutes it runs `cron_hub run` and `tiktok_bot/tiktok_cron poll_comments` in `monkeybot-app-ojzrhf-app-1`, logging to `/var/log/monkeybot-cron.log`. See `docs/runbooks/scheduler.md`. |
| Risk of fix | Medium: the first ticks send any backlog. At activation the backlog was 0 sends. |
| Verification method | The log shows tick JSON every 5 minutes; `ops_health.json` shows `cron_hub` updating. |
| Status | Fixed today: Verified. The first scheduled tick ran at 15:10 on 2026-09-22 and printed its JSON (0 sends). |

### F-02 Incident: the audit GET ran a real tick, and retention deleted AI history

| Field | Detail |
|---|---|
| Evidence | One GET to `/cron_hub/run` during the audit (before S-01 was fixed) ran a full tick. Retention (`ai_conversation_history` older than 60 days, 5,000 rows per tick) has since deleted about 10,000 rows. |
| Severity | P1 (data loss; the loss is by design of SPEC retention) |
| Business impact | AI memory and history older than 60 days are gone. Replies do not depend on it, because the memory TTL is shorter. |
| Technical impact | The first 5,000 deleted rows (from the audit tick) **predate the backup** and cannot be recovered. Later deletions are in the backup. |
| Recommended fix | Owner decision: keep the SPEC windows, or lengthen them. Future changes to retention need an explicit sign-off. |
| Done today | A DB and config-volume backup was taken at `/root/backups/monkeybot-cron-20260922-145956/` before the scheduler was enabled. |
| Risk of fix | Not applicable. |
| Verification method | Row counts and the dates of the oldest rows. |
| Status | Open for an owner decision. Documented. |

### F-03 Retention runs on every tick, not hourly

| Field | Detail |
|---|---|
| Evidence | `Cron_hub::_run_retention` says "hourly" in its comment, but `run()` calls it on every 5-minute tick. Each tick deletes up to 5,000 rows per table. |
| Severity | P3 |
| Business impact | Deletion is faster than documented. Up to 60,000 rows per table per hour can go. |
| Technical impact | None beyond speed. The windows are what matter. |
| Recommended fix | Gate retention to once an hour, or correct the comment. |
| Risk of fix | Low. |
| Verification method | Code review; count the retention entries in the log. |
| Status | Open. |

### F-04 No scheduler monitoring

| Field | Detail |
|---|---|
| Evidence | Before today there was no record of runs. |
| Severity | P1 |
| Business impact | A dead scheduler goes unnoticed. That is the reason F-01 lasted. |
| Technical impact | Failures are silent. |
| Recommended fix | Health record, a public `/health` endpoint, an admin page and external uptime monitoring. |
| Done today | `ops_health_helper.php` writes `application/cache/ops_health.json` on every `cron_hub` run (last run, duration, result, failure). |
| In progress | `Health.php` (`/health`: 200 `ok`, 200 `degraded` when the scheduler is more than 15 minutes stale, 503 `down` when the DB is down) and `System_health.php` (jobs, webhook counters, switches). |
| Risk of fix | Low. |
| Verification method | `/health` returns `ok` within 5 minutes of a tick and `degraded` after a pause. |
| Status | In progress. |

### F-05 Legacy `cron_job/*` jobs are not scheduled

| Field | Detail |
|---|---|
| Evidence | `Cron_job` defines these jobs, and none is in `/etc/cron.d/monkeybot`: `sequence_message_broadcast_hourly/daily` (drip sequences), `subscriber_broadcaster`/`braodcast_message`, `lead_score_decay`, `ecommerce_abandoned_cart_reminder`, `woocommerce_abandoned_cart_reminder`, `membership_alert_delete_junk_data`, `publish_post`/`rss_auto_post`, `auto_comment_on_post`, `auto_like_on_post`, `auto_share_on_post`, `sms_sending_command`, `email_sending_command`, `expired_users_disable_bot`, `background_scanning_update_subscriber_info`. |
| Severity | P1 |
| Business impact | Drip sequences, broadcasts, lead-score decay (brief §7.1), abandoned-cart reminders and membership expiry do not run. |
| Technical impact | Queued sequence rows may have built up a backlog. Enabling them blind could mass-send stale messages. |
| Recommended fix | Inventory the pending rows for each job. Then add them to cron one at a time through the CLI (`php index.php cron_job <method>`), starting with `lead_score_decay` (no sends). |
| Risk of fix | Medium to high for any job that sends. |
| Verification method | A backlog query before each job is enabled; the log output after it runs. |
| Status | Open. |

### F-06 Other automation gaps

- **TikTok.** `poll_comments` is scheduled but has 0 accounts, and the default `display` mode cannot reply. `poll_dms` is stubbed and not scheduled. Status: Open.
- **Appointment booking.** Reminders do not send. Status: Open.
- **AI cost.** The cost figure undercounts, because embeddings, Whisper, the judge and preview calls are not logged. Status: Open.

---

## G — Git / Deployment Risk

### G-01 Live work not in git

| Field | Detail |
|---|---|
| Evidence | The repo had 1 commit plus 51 uncommitted changes, and 23 `.bak-*` files. |
| Severity | P0 |
| Business impact | A host loss or a bad edit would lose weeks of custom work, and it could not be rolled back. |
| Technical impact | There is no history, no diff review and no release. |
| Recommended fix | Commit the legitimate work in logical commits, ignore backups, tag releases, and push. |
| Done today | Branch `chore/commit-live-work-20260922` has 4 commits: Graph version pinning, staff hours, the cron fix, and nginx alias / TikTok file / `.gitignore` for `*.bak`. The real `encryption_key` is kept out of git (a placeholder is committed). |
| Risk of fix | Low. |
| Verification method | `git log`; `git status` shows only the intended pending work. |
| Status | Partial. The branch is **not pushed**. The work done after these commits is still uncommitted: the webhook security helper, `ops_health`, `Health`, `System_health`, and the edits to `Home`, `Messenger_bot`, `Comment_automation`, `Instagram_reply`, `Webview_builder`, `Ai_agents`, `ai_knowledge_helper`, `ai_voice_helper`, `Ai_knowledge_base` and nginx. The `.bak` clutter in the config volume has not been cleaned. |

### G-02 Live-edit deployment

| Field | Detail |
|---|---|
| Evidence | The code tree is bind-mounted, so every save is production. |
| Severity | P1 |
| Business impact | A syntax error takes the site down instantly. |
| Technical impact | There is no gate, no staging and no rollback. |
| Recommended fix | See `docs/runbooks/deployment.md`. |
| Risk of fix | Low if the change is incremental. |
| Verification method | Not applicable. |
| Status | Open. The checklist is documented. |

### G-03 Config volume shadows the repo config

| Field | Detail |
|---|---|
| Evidence | `application/config` is the volume `monkeybot_app_config`. Editing `config.php` in the repo has no effect on the live app. |
| Severity | P2 |
| Business impact | Config changes appear to succeed but do not apply. |
| Technical impact | Config drifts between the repo and the volume. |
| Recommended fix | Make every config change in the volume, and mirror it in the repo (with secrets as placeholders). |
| Risk of fix | Not applicable. |
| Verification method | Diff the volume against the repo. |
| Status | Open. Documented. |

### G-04 No staging environment

| Field | Detail |
|---|---|
| Evidence | There is only production. |
| Severity | P1 |
| Business impact | Risky changes (CSRF, enforce modes, Graph v26) cannot be tested safely. |
| Technical impact | Not applicable. |
| Recommended fix | A second Dokploy compose with a sanitised DB copy and test pages. |
| Risk of fix | Low. |
| Verification method | Not applicable. |
| Status | Blocked (owner). |

### G-05 No automated backup

| Field | Detail |
|---|---|
| Evidence | The only backup is the manual one of 2026-09-22. |
| Severity | P1 |
| Business impact | Data loss is unbounded. |
| Technical impact | Not applicable. |
| Recommended fix | See `docs/runbooks/backup-and-restore.md`. |
| Risk of fix | Low. |
| Verification method | A restore test. |
| Status | Open. |

### G-06 nginx single-file bind mount

| Field | Detail |
|---|---|
| Evidence | `./docker/nginx/default.conf` is mounted as a single file. Editors replace the file's inode, so the container keeps the old file. `nginx -s reload` is not enough. |
| Severity | P3 |
| Business impact | Security rules that were edited may silently not be live. |
| Technical impact | Config drift between the host file and the container. |
| Recommended fix | Restart the `web` container after each edit, or mount the directory instead. |
| Risk of fix | Low: a brief restart of `web`. |
| Verification method | `docker exec … cat /etc/nginx/conf.d/default.conf`, then probe the paths. |
| Status | Open. Documented. |

---

## H — Technical Debt

| ID | Debt | Severity |
|---|---|---|
| H-01 | God controllers: `Home.php` (8,245 lines, holds the AI engine) and `Messenger_bot.php` (14,840 lines). `Openwa_bot` inherits the entire Messenger engine. | P2 |
| H-02 | No unified conversation store or inbox for WA, Telegram and the web widget (see ADR-001). | P1 |
| H-03 | Staff hours are enforced on FB/IG DMs, IG comments and OpenWA, but not on FB comments, Telegram, WA Cloud, the widget or TikTok. `hours_message` and `hours_offline_sent_at` are unused. | P2 |
| H-04 | Graph API: all ~70 call sites are still on v20.0 (phase 3 not started). WA Cloud is hardcoded to v21.0. | P1 |
| H-05 | OTN broadcast is deprecated by Meta (inferred). Some `page_messages_*` insight metrics are likely retired (inferred). | P2 |
| H-06 | No UI for `pricing_config`; it is seeded by hand. | P2 |
| H-07 | Broken "Flow Builder" sidebar link. Dead WhatsApp-SaaS code in Livechat. | P3 |
| H-08 | SPEC-14 broadcast A/B testing exists only as a table. | P3 |
| H-09 | Schema drift: `openwa_channel` is not in `schema_migrations`, and the `hours_*` columns were added by hand. | P1 |
| H-10 | The AI product carousel builder is built but not wired in. | P3 |
| H-11 | AI cost accounting uses a hardcoded price table and undercounts. | P2 |

---

## I — Migration Dependencies

1. **A baseline in git and pushed** (G-01) before any refactor. Every later step needs a rollback point.
2. **Automated backup plus a restore test** (G-05) before any schema migration.
3. **Record the schema drift** (H-09) before new migrations. Write idempotent migrations for `hours_*` and the `openwa_channel` record, so that staging can be rebuilt from migrations.
4. **Staging** (G-04) before CSRF (S-07), webhook enforce (S-02), entitlement enforce (S-06) and Graph v26 phase 3 (H-04).
5. **Webhook signatures enforced** before the channel adapter refactor (ADR-002). The adapter must inherit a verified ingress.
6. **The unified conversation model** (ADR-001) depends on the channel adapter's normalised inbound message (ADR-002). It needs a backfill from `livechat_messages` and an identity map to `messenger_bot_subscriber`.
7. **The legacy cron jobs** (F-05) depend on the backlog inventory and on the scheduler monitoring (F-04).

---

## J — Phase 0 Exact Execution Plan

### Done (2026-09-22)

| # | Item | Status |
|---|---|---|
| 1 | Backup of DB and config volume at `/root/backups/monkeybot-cron-20260922-145956/` | Done |
| 2 | Scheduler installed: `/etc/cron.d/monkeybot` (7.1) | Verified |
| 3 | Cron endpoints closed: `Cron_hub`, `Tiktok_cron`, `Cron_job::api_key_check`; "Run now" goes through the CLI (7.2) | Implemented but not tested |
| 4 | nginx exposure deny rules (7.4) | Verified |
| 5 | OpenAI key decryption through `ai_openai_key()` (7.5) | Implemented but not tested |
| 6 | KB `preview_chunks_action` owner check (7.6) | Implemented but not tested |
| 7 | Meta signature and internal HMAC, log mode (7.3) | Implemented / awaiting activation |
| 8 | Git branch with 4 commits (7.8) | Partial: not pushed, and later work uncommitted |
| 9 | `ops_health.json` recording (7.1 monitoring) | Implemented; recording confirmed by the scheduled ticks |

### In progress

| # | Item |
|---|---|
| 10 | `/health` endpoint and admin System health page (`Health.php`, `System_health.php`) |
| 11 | Package entitlements (7.7) behind `entitlement_enforce_custom_modules`, with a migration that is not yet applied |

### Remaining, in order

| # | Action |
|---|---|
| 12 | Verify item 3 with live HTTP probes (403, and no new `ops_health` entry). Verify items 5 and 6 on a test page or user. |
| 13 | Commit the post-branch work in logical commits. Owner: rotate the embedded GitHub token (S-09), set a token-free remote, and push. Tag `v8.5.4-phase0`. |
| 14 | Automated nightly backup, plus one restore test into a disposable DB (G-05). |
| 15 | After 7 days of counters that meet the criteria in `docs/security/webhook-signatures.md`, switch `internal_webhook_mode` and then `meta_webhook_signature_mode` to `enforce`. |
| 16 | Legacy cron inventory (F-05). Enable `lead_score_decay` first, then the sending jobs one by one after backlog checks. |
| 17 | Remaining AI credential paths: `Ai_agents::preview` should follow the provider. Price guard: fail closed or hand off. Log all AI cost calls. |
| 18 | Audit the other KB actions for ownership (S-05 follow-up). |
| 19 | Entitlements: run in log mode, then enforce after the admin and member walk-through. |
| 20 | Owner, blocked items: a migration for the hand-added `hours_*` columns (schema read was blocked); set `cron_secret` only if HTTP triggering is needed; build staging; then Graph v26 phase 3 and CSRF. |
| 21 | External uptime monitor on `/health` (alert on `degraded` or `down`). |

### Blocked / pending the owner

- The `hours_*` migration (the schema read was blocked).
- `cron_secret` (only needed for HTTP triggering).
- Graph v26 phase 3.
- CSRF.
- The staging environment.
- GitHub token rotation.
- The retention-window decision (F-02).

---

## Addendum — end of day 2026-09-22 (supersedes the "uncommitted" notes above)

### Now committed (branch `chore/commit-live-work-20260922`, not pushed)
Every Phase 0 change listed above is committed; `git status` is clean except the working-tree
`config.php`, which deliberately holds the real `encryption_key` that is kept out of git.

| Commit | Change |
|---|---|
| `29bacfa3` | nginx deny rules (Verified live) |
| `a19612e1` | decrypted OpenAI key everywhere + KB preview owner check |
| `e73290d6` | Meta signature + internal HMAC, log-only |
| `94690e91` | scheduler health, `/health`, `/system_health` |
| `0a919aa5` | staff-hours migration (idempotent, not applied) |
| `00138f4f` | migration runner no longer drops a file's first statement |
| `97142bc9` | package entitlements, log-only; migration `2026-09-22-custom-module-entitlements.sql` (not applied) |
| `0c61e8f5` | Cron_job constructor gate + daily `lead_score_decay` |

### New findings after the report was drafted
**S-12 — Legacy `cron_job/*` jobs were publicly executable (P0) — FIXED, Verified.**
Evidence: nearly every job had `// $this->api_key_check($api_key);` commented out, including
`delete_junk_data` (deletes livechat history), broadcasts, SMS/email sends and posting.
Fix: one gate in `Cron_job::__construct` (CLI or valid native API key). Verification: live GETs
to `delete_junk_data`, `lead_score_decay`, `expired_users_disable_bot` return
"API Key is required." without running.

**S-13 — Migration runner dropped the first statement of commented files (P1) — FIXED.**
Files that begin with `--` lost their first statement (`2026-07-05-spec17.sql` affected). Runner now
strips comment lines. Dry-run verified. Existing live tables were not re-checked (DB reads blocked).

**F-07 — Meta webhook traffic has almost stopped (P0, OPEN — owner action).**
Evidence: nginx shows only 2 `POST /home/central_webhook_callback` in the last 96 h (19 Sep 11:13,
21 Sep 05:26); last `livechat_messages` row is 2026-09-19, against a historical ~540 rows/day.
Checked: Meta app `Monkeybot` (1293868009403474, the app in `facebook_rx_config`) is live, and its
`page` + `instagram` subscriptions are enabled with the right fields and callback host.
Not yet checked (needs page tokens / owner approval): each page's `/{page-id}/subscribed_apps`,
page-token validity, and a Meta test delivery. Most likely cause: page-level subscription dropped or
page tokens invalidated. The separate `Creative Monkey` app (845279212815745) is API-deactivated;
confirm no page is still bound to it.
Business impact: FB/IG customers get no bot replies and no leads are captured.
Recommended fix: in Monkey Bot re-connect / re-enable the bot on each page (re-subscribes the page),
then confirm deliveries in `/system_health` webhook counters. Add an hourly self-heal check.

**Ops — logrotate** `/etc/logrotate.d/monkeybot-cron` (weekly, 8 kept, `su root syslog`).

### Scheduler (F-01) — Verified
`/health` → `{"status":"ok","scheduler":{"last_success_age_s":43}}` and cron log shows 5-minute ticks.

### Staging + verification (added later on 2026-09-22)
A staging stack now exists (`docker-compose.staging.yml`, runbook `docs/runbooks/staging.md`).
Building it from `initial_db.sql` + migrations exposed **G-07: 18 production tables had no
migration** (5 of them Creative Monkey's own: `ai_followups`, `ai_coupon_reminders`,
`ai_unanswered_questions`, `crm_sheet_config`, `sales_automation_settings`), so a fresh
install failed at the first cron tick. Fixed by `2026-09-22-baseline-untracked-tables.sql`
(structure only, IF NOT EXISTS). Also **G-08: `migrate` recorded partly failed migrations**
— fixed; verified on staging.

Status changes, all **Verified on staging** by `docker/scripts/staging_phase0_tests.sh`
(18/18 in log mode, 19/19 in enforce mode) plus CLI probes:
S-02/S-12 cron gates · S-04 key decryption · S-05 KB IDOR · S-06 Meta signature and
internal HMAC (accept valid, reject forged, in enforce) · S-07 entitlements (member denied,
admin allowed) · F-01 health/failed-tick recording · S-03 file exposure.
Production still runs every gate in **log** mode by design.

### Correction and schema reconciliation (later on 2026-09-22)
**F-07 downgraded to INFO — not an incident.** The owner confirmed only one page
(the electric-motors supply company) is currently active and it receives few messages, so
2 Meta deliveries in 96 h is expected volume. Meta app `Monkeybot` subscriptions are healthy.
Consequence for webhook-signature enforcement: at this volume the "≥200 events" criterion in
`docs/security/webhook-signatures.md` will take long; the 7-day window + zero `invalid`
+ all event types seen `valid` is the practical bar.

**Production migrations applied (Verified).** Backups before each step:
`/root/backups/monkeybot-premigrate-20260922-155357/`, `/root/backups/monkeybot-premigrate2-20260922-*`.
- recorded the hand-applied `2026-08-05-openwa-channel.sql`
- applied `2026-07-04-baseline-untracked-tables.sql` (18 untracked tables + KB tables; renamed from
  the 2026-09-22 name so it runs before the SPEC migrations on fresh installs; record renamed on prod)
- applied `2026-09-22-custom-module-entitlements.sql` (modules 400–407; ids confirmed free)
- applied `2026-09-22-staff-working-hours.sql` (no-op on prod)
- applied `2026-09-22b-schema-drift-reconcile.sql` (KB `page_id` + index, price-guard verdict enum,
  hours columns as TIME/varchar(32) — identical to prod, no data change)
- `spec20` and `deflection-alert` made idempotent (`ADD COLUMN IF NOT EXISTS`); already recorded
  on prod, so they do not re-run there.
`/health` stayed `ok` throughout.

**G-07 closed:** a fresh install from `initial_db.sql` + migrations now equals the production
schema (36 migrations, 0 errors, 172 tables, 0 column/index/default differences).

**Config volume vs git:** only `database.php` (credentials) and `tiktok.php` (live file holds
literal credentials; repo reads them from env — repo version is the intended one) differ.
No non-secret configuration is missing from git. The volume also holds 6 `*.bak*` copies
(not served; nginx denies `/application`).

### Owner decisions (2026-09-22, end of day)
- **Entitlements: ENFORCED.** Owner chose "all features for all current packages": modules 400–407
  added to Trial and Monthly, sidebar items gated (`2026-09-22c-menu-module-access.sql`), config
  `entitlement_enforce_custom_modules = TRUE` in the live volume. Admin never blocked. Verified on
  staging; production `/health` ok after the switch.
- **Push: deferred by owner.** The remote token in `.git/config` is expired and no MonkeyBot
  credential exists on the server. Protection until then: verified `git bundle --all` in
  `/root/backups/monkeybot-git-*.bundle` (restore: `git clone <bundle>`).
- **Webhook signatures: stay in `log`** until the rollout criteria in
  `docs/security/webhook-signatures.md` are met on live traffic (time-gated; low volume).

### New finding, unrelated to Phase 9/10/11 work (2026-09-22, later)
**S-14 — DB connection charset is utf8 (3-byte) while every table is utf8mb4 — CONFIRMED live, NOT fixed on production.**
- Evidence: `docker exec monkeybot-app-ojzrhf-app-1 grep char_set .../database.php` → `'utf8'` /
  `dbcollat` → `'utf8_general_ci'`. `docker/php/entrypoint.sh` generated it that way on first boot.
  `assets/backup_db/initial_db.sql` and every migration create tables as `utf8mb4`.
- Impact: a mysqli connection's charset caps what can be transmitted regardless of the column's
  charset. Any 4-byte UTF-8 character sent from PHP — emoji, some Arabic presentation forms —
  is silently replaced (typically with `?`) on INSERT. This has likely been happening since
  install, in `livechat_messages`, `ai_conversation_history`, subscriber names, and anywhere else
  user text is stored. No error is raised (S-51 "no silent failure" applies).
- Fix (code): `docker/php/entrypoint.sh` now writes `utf8mb4` / `utf8mb4_unicode_ci` — done, only
  affects fresh installs.
- Fix (production, not applied): edit `char_set`/`dbcollat` in the live `application/config/database.php`
  (Docker volume) and restart `monkeybot-app-ojzrhf-app-1` (new mysqli connections only pick up the
  change on reconnect — a restart is the reliable way). Already-corrupted characters cannot be
  recovered by this fix; only new writes are protected.
- Owner decision needed: when to apply the production fix (brief restart required).

### Phase 9-11 foundations merged into release/9.x-integration (2026-09-22, later)
All built on isolated worktrees/stacks, never touching production, then merged with one real
conflict (entitlement_helper.php: both branches added a distinct module id — kept both).

| Phase | Feature | Status |
|---|---|---|
| 9.0 | Channel adapter layer + normalized message model | Verified — 117/0/11 |
| 9.5 | Unified conversation core (BEFORE INSERT trigger link, customer identity, handoff state machine) | Verified — 486/0, scale-tested at ~48k messages / 3k concurrent inserts |
| 10 | AI cost accounting (every call, editable price table) | Verified — exact totals |
| 10 | Products & Pricing admin page | Verified — 16/0 |
| 10 | AI Improvement Center (Arabic-aware gap grouping) | Verified — 12/0 |
| 10.5 | Channel Policy Engine | Verified — 20/0 |
| 10.5 | Event Engine + Automation Engine (Trigger/Conditions/Actions) | Verified — 65+44/0 |
| 11 | Agency Control Center | Verified manually |
| 11 | Onboarding wizard + 4 industry templates | Verified — 51+96/0 |

**Full regression after every merge, fresh DB (45 migrations, 0 errors, 184 tables):**
staging_phase0 19/0, staging_pricing 16/0, staging_ai_improvement 12/0, staging_automation 44/0,
staging_onboarding 96/0. Production `/health` stayed `ok` throughout (no prod changes made).

**S-14 (utf8mb4 connection charset) fixed in code** for fresh installs
(`docker/php/entrypoint.sh`, `application/config/database.php` seed); **production's live
connection is still `utf8`** — needs the config volume edited + an app restart, timing is an
owner decision.

**automation_engine_enabled** defaults FALSE/unset everywhere, including production once this
branch ships — the worker will not run until explicitly enabled (brief §50 feature flags).

**Not yet done:** none of this is pushed or deployed. The Unified Inbox (brief §13) is the next
piece that depends on both 9.0 and 9.5, now both merged and available to build on.

### Unified Inbox merged (2026-09-25, autonomous continuation)
| Phase | Feature | Status |
|---|---|---|
| 9.5 | Unified Inbox (brief §13/§14/§46) | Verified — 56/0 unit, 39/0 staging |

Clean merge into `release/9.x-integration` (entitlement_helper.php map extended to `inbox => 411`,
no conflict this time). Livechat.php (fb/ig, module 82) is kept running as-is, not redirected —
both write the same trigger-linked `livechat_messages`/`conversations` data, so the two screens
stay consistent; redirecting it was judged out of scope without a full parity check. Deferred:
Pusher realtime (polling v1), AI-suggested-reply display (needs `ai_conversation_history` keyed
to `conversation_id`, an ADR-001 gap), labels (existing table keys off `messenger_bot_subscriber.id`,
excludes 3 of 5 channels), attachments (text only this phase).

**Full regression after this merge, fresh DB (46 migrations, 0 errors, 185 tables):**
unit suites 117+20+65+51+56+486 = 795 assertions; staging HTTP suites
19+16+12+44+96+39 = 226 assertions. All 1,021 assertions passed, 0 failed. Production `/health`
stayed `ok` throughout (no prod changes made). Nothing pushed.

**Still pending owner decisions, unchanged:** the S-14 production charset fix (needs a restart,
timing is the owner's call) and pushing this work to GitHub.

### Full roadmap closed except one deliberate exception (2026-09-22, final consolidation)
Every remaining phase-work item was built, merged, and verified together on `release/9.x-integration`:

| Phase | Feature | Status |
|---|---|---|
| 10.5 | Sequences safety parity (rate limits, quiet hours, jitter, opt-out) | Verified — 90+23/0 |
| 10.5 | Prompt-to-Flow (AI drafts a Visual Flow Builder flow, human reviews/saves) | Verified — 37+14/0 |
| 11 | Client provisioning from Agency Control Center | Verified — 35+33/0 |
| 9.5 | AI conversation summary (Inbox) | Verified — part of 71+26/0 below |
| 11 | Usage/billing readiness (Agency Control Center) | Verified — part of 71+26/0 below |

**Final full regression, fresh DB (50 migrations, 0 errors, 189 tables):**
unit suites 117+20+65+51+56+90+37+35+71+486 = 1,028 assertions;
staging HTTP suites 19+16+12+44+96+39+47+23+14+33+26 = 369 assertions.
**All 1,397 assertions passed, 0 failed.** Production `/health` stayed `ok` throughout
(no prod code changes made in this consolidation pass — everything ran on staging).

### Roadmap status vs. the master brief (§52-53) — final
Every item is done except one, held back deliberately:

- **Graph API v26 migration (phase 3), brief §9/§0 — NOT DONE, by design.** Every Graph call
  is pinned to v20.0 today (`fb_graph_helper.php`); the config target v26.0 exists but no call
  site uses it. This cannot be closed by more code alone: it changes behavior against Meta's
  live API for every connected client Facebook/Instagram page, and the only way to verify it
  is genuinely safe is testing against real Graph API responses with real page tokens — nothing
  a sandbox can substitute for. This is an owner decision on timing and rollout (e.g. a single
  canary page first), not a remaining implementation task.
- Everything else in §52's four phases (9.0 Stable Core, 9.5 Unified Conversations, 10 AI Sales
  Platform, 10.5 Automation OS, 11 Agency SaaS) is built and verified on staging.

**Still pending, unrelated to feature completeness:** pushing `release/9.x-integration` to GitHub
(credential issue, flagged earlier) and applying this final consolidation's migrations to
production (the earlier 9-phase batch is already live; this last batch — sequences-safety,
prompt-to-flow, provisioning, summary/billing — is staged and tested but not yet deployed).

### Graph API v26 migration — Phase 3 engineering complete (2026-09-22, final)
Closes the one item previously held back. Built on real Meta changelog research
(developers.facebook.com/docs/graph-api/changelog, v21.0-v26.0), not guesswork:

- 67 of 69 call sites converted to per-group version resolution
  (`fb_graph_group_version($group)` / `graph_url_group($group, $path)`), grouped as
  reads, send, messenger_profile, oauth, insights, labels, page_admin. 2 sites were
  confirmed-dead code (`me/broadcast_reach_estimations`) and removed instead.
- **Every group still defaults to today's v20.0 behavior** — proven byte-identical
  for all 66 real call sites by a regression test, not asserted. Nothing changes
  until an owner explicitly sets a group's config key.
- Corrected the original migration doc's assumption that the Custom Labels API was
  dead (it isn't, per the current changelog) and found a real, separate, already-broken
  issue (Meta's Feb 2026 deprecation of several message tags/RCN) — documented, not
  silently fixed, since it's a business-logic problem outside this migration's scope.
- New admin-only diagnostic tool (`/graph_diagnostics`): compares two safe read-only
  calls on the legacy vs. candidate version for one real connected page, so the owner
  can verify compatibility against real Meta traffic before flipping each group -
  proven in tests to make zero network calls on its own (Reflection + fake transport).
- `oauth` is deliberately last in the rollout order (breaking it breaks login).
  `messaging_feature_review` has no config key at all — confirmed live, not dead,
  hard-pinned until someone re-verifies it.

**Final regression after this merge, fresh DB (50 migrations, 0 errors, 189 tables):**
unit suites 117+20+65+51+56+90+37+35+71+113+486 = 1,141 assertions;
staging HTTP suites 19+16+12+44+96+39+47+23+14+33+26 = 369 assertions.
**All 1,510 assertions passed, 0 failed.** Production `/health` stayed `ok` throughout.

### Roadmap status — complete
Every phase in the master brief's roadmap (§52-53) is now built and verified on
`release/9.x-integration`, including Graph API v26 Phase 3's engineering work. The
one remaining step for ANY of this work is deployment timing and, for the Graph
migration specifically, the owner running the diagnostic tool against a real page
before flipping each group's flag — an operational rollout action with real Meta
traffic, not a development task.

### Items 17 and 18 — AI safety follow-ups (branch `feat/ai-safety-followups`, 2026-09-23)

| Item | Change | Status |
|---|---|---|
| 17a preview | `ai_agent_preview_reply()` (Ai_agents::preview, Onboarding test_reply) now calls `Ai_provider::completion()` with the tenant's `open_ai_config` row: Anthropic tenants are answered by Anthropic with the decrypted Anthropic key; no key returns `status '0'` + `No Anthropic key` / `No OpenAI key`, never a reply. | Implemented, unit-tested |
| 17b price guard | Config flag `price_guard_fail_closed` (unset = FALSE). When the judge returns `unknown` or throws, every price figure in the reply must be written in the authoritative facts (prompt layers, FAQ, KB, `ai_price_facts`). FALSE: reply unchanged, `price_guard: would block ...` logged at `error`. TRUE: reply replaced with a hand-off text, `ai_price_guard_log` row (`verdict='unknown'` with `blocked_reply`), conversation moved to `WAITING_FOR_HUMAN` by actor `ai` (fallback: `handoff_to_human` tool). | Implemented, unit-tested; flag off in production |
| 17c cost | `Ai_provider` tool loops (every tool-enabled reply) were not logged at all: every round is now logged. Integration health check logged as `health_check`, not `chat_reply`. Preview accounted via `Ai_provider`. `backfill_kb_embeddings.php` no longer dies on `get_instance()` after the first batch and records its embeddings. | Implemented, unit-tested |
| 18 KB ownership | Every by-id statement in the KB module, Missed_questions, Ai_improvement and Onboarding is tenant-scoped (static sweep in `tests/ai_safety`). `toggle_status_action` now refuses a foreign id instead of answering "Status updated". | Audited, one fix |

Closed on `feat/price-judge-provider` (2026-09-23):

- The price judge follows the tenant's provider. `ai_verify_price_grounding()` sends Anthropic tenants to `claude-haiku-4-5` through `Ai_provider` (decrypted Anthropic key, 8s timeout, temperature 0, usage purpose `price_judge`) with the same prompt and the same verdict parsing (`ai_price_judge_verdict()`). The OpenAI request is unchanged. Anthropic tenants no longer get `unknown` on every priced reply.
- The embedding backfill is a CodeIgniter CLI command, `php index.php cron_hub backfill_embeddings [--dry-run] [--user N]`, which decrypts `enc::` keys, backs off on 429/5xx and records usage. `backfill_kb_embeddings.php` is deprecated and says so. See `docs/runbooks/kb-embeddings-backfill.md`.

### Deferred-items round (2026-09-23)
Built, merged into `release/9.x-integration` and verified together:

| Item | Status |
|---|---|
| Setup Guide scoped to the pages chosen for the business (steps 2-7) | Deployed 2026-09-23 (a08dcf1f) |
| Price lists per page + generic products & services catalog | Deployed 2026-09-23 (243509d0) |
| Setup Guide step 5 writes per-page lists / catalog; checklist per page | Verified, not deployed |
| Baseline 17: preview follows the provider, price guard fail-closed (`price_guard_fail_closed`, default off), every AI cost call logged | Verified, not deployed |
| Baseline 18: KB ownership audit (1 fix) + Missed_questions honest not-found | Verified, not deployed |
| Inbox: labels, canned responses per channel, media display + image replies, AI suggested reply | Verified, not deployed (migrations 2026-09-30, -30b, -30c) |
| fb/ig human replies 24h-7d now carry MESSAGE_TAG/HUMAN_AGENT (was always RESPONSE) | Verified, not deployed |
| Baseline 14: nightly backup + weekly restore test (`/usr/local/bin/monkeybot-backup.sh`, `/etc/cron.d/monkeybot-ops`) | Live on host |
| Baseline 21: 5-minute health watcher (on-host; an off-host monitor still needs an external account) | Live on host |

Regression: unit suites 152+118+65+114+149+97+20+117+37+35+90+71 = 1,165;
staging HTTP suites 12+92+180+19+65+16+14+33+26+44+47 = 548. **1,713 passed, 0 failed.**

Open, needs the owner: Facebook/Instagram inbound stopped on 2026-09-19 (app-level webhook
subscriptions are enabled; page-level `subscribed_apps` or page tokens need checking); webhook
signature enforcement cannot be evaluated until Meta traffic arrives (no counters yet); S-14
charset; GitHub push.

### Follow-up round (2026-09-23, later)
| Item | Status |
|---|---|
| Comment auto-reply private messages recorded in livechat_messages → visible in the Unified Inbox (+ internal note with the public comment/reply) | Verified |
| Inbound media stored for WhatsApp Cloud + Telegram (`livechat_messages.media_json`, migration 2026-10-01); OpenWA gateway sends no file (needs a gateway route) | Verified |
| Price-grounding judge follows the tenant's provider (Anthropic → claude-haiku-4-5; OpenAI byte-identical) | Verified |
| `cron_hub backfill_embeddings` CLI (decrypts enc:: keys) | Verified |
| Realtime Unified Inbox (private per-tenant Pusher channel, ids only) + 20s visible-tab polling fallback | Verified; Pusher is not configured in production, so production gets the polling |
| Log noise: account-switcher and package-module warnings | Verified |
| Telegram voice transcription only when the bot's AI is on | Verified |

Regression: unit 1,358; shared staging 571; isolated stacks (media 53, inbox 105, realtime 30) 188.
**2,117 passed, 0 failed.**

Open findings:
- **Livechat's Pusher channel is public and install-wide and carries message content** (pre-existing).
  Harmless today (Pusher unconfigured); must be fixed before anyone configures Pusher.
- **S-14 charset:** switching only the connection to utf8mb4 is unsafe — 105 tables are still utf8mb3
  and a utf8mb4 literal with a 4-byte char against a utf8mb3 column raises "Illegal mix of
  collations". Converting only mb3→mb4_general_ci then collides mb4_general vs mb4_unicode column
  comparisons (reproduced: cron_hub run, backfill_conversations). The safe fix is converting every
  mb3/mb4_general text column to utf8mb4_unicode_ci (per column, latin1 untouched), rehearsed on a
  restored production backup, then switching the connection. Awaiting owner approval.
