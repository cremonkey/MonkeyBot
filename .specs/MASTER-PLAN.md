# MonkeyBot Enhancement — Master Plan & Status

> Orchestrator state file. Update STATUS after each spec completes.
> Each SPEC-*.md is self-contained: a fresh subagent reads ONLY its spec + this header.

## CRITICAL DEPLOYMENT FACTS (read before touching anything)

- `/tmp/MonkeyBot` is a **live production bind-mount** → `/var/www/html` inside container `monkeybot-app-1` (site: bot.cremonkey.com behind Traefik+nginx). Edits are live instantly.
- **EXCEPTION**: `application/config/`, `application/cache/`, `application/logs/`, `upload/`, `upload_caster/`, `download/` are **Docker named volumes** inside the container. The live config is NOT the repo copy. To change live config:
  `docker exec monkeybot-app-1 <edit /var/www/html/application/config/...>` AND mirror the change to the repo copy `/tmp/MonkeyBot/application/config/...` (keep both in sync).
- Lint every changed PHP file: `docker exec monkeybot-app-1 php -l /var/www/html/<path>` (PHP 8.1.33).
- Smoke test after changes: `docker exec monkeybot-web-1 curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/home/login` → expect 200.
- DB: MariaDB 10.11, container `monkeybot-db-1`, db name `monkeybot`.
  Query: `docker exec monkeybot-db-1 sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" monkeybot -e "<SQL>"'`
  Backups exist at /root/monkeybot-backups/ (latest: monkeybot-20260705-1304.sql.gz). Take a fresh dump before any ALTER/migration.
- Stack: CodeIgniter 3.1.5 + HMVC (application/modules/). Product = XeroChat 8.5.4 white-labeled "monkey bot".
- Git: repo initialized at /tmp/MonkeyBot, branch main, baseline commit e2014e9. Commit after each task:
  `git -c user.email=claude@anthropic.com -c user.name=Claude commit -m "..."`
- Reference schema (original): `assets/backup_db/initial_db.sql`. LIVE schema may differ — always check live DB with SHOW CREATE TABLE before ALTER.
- Addon pattern: new features = HMVC module in `application/modules/<name>/controllers/<Name>.php` with comment header (Addon Name / Unique Name / Modules JSON / Project ID), extends Home, activate() calls `$this->register_addon($name,$sidebar,$sql,$purchase_code)` (Home.php:4047). Internal addons must NOT call `addon_credential_check()`.
- AI entry point: `Home.php::get_ai_reply_open_ai($description,$human,$user_id,$page_id,$subscribe_id,$social_media)` line ~6999; lib `application/libraries/Openai_api.php`; config table `open_ai_config`; memory table `ai_conversation_history`.
- The app has its OWN CSRF: `Home::csrf_token_check()` validates session `csrf_token` on POST forms (login form field name `csrf_token`, value `session->csrf_token_session`). CI global csrf_protection stays FALSE (see docs/SECURITY-TASK-D.md). Login POST = `home/login`, fields `username`,`password`,`csrf_token`.
- base_url is set to `https://bot.cremonkey.com/` (config volume + repo). MUST stay https or assets break as mixed-content behind Traefik. To test login end-to-end: GET home/login, scrape csrf_token, POST home/login.
- Password auth now uses `application/helpers/password_helper.php` (pw_hash/pw_verify/pw_needs_rehash) — md5 legacy accepted + auto-upgraded on login. New password writes MUST use pw_hash().
- Secrets: `application/helpers/secret_helper.php` (secret_encrypt/decrypt/mask) — encryption INERT until a strong encryption_key is set (still '12345'; see docs/SECURITY-TASK-E.md). secret_decrypt is always safe to wrap reads with.

## EXECUTION ORDER & STATUS

| # | Spec | Phase | Status |
|---|------|-------|--------|
| 00 | SPEC-00-security-hardening.md | 0 | DONE (A,B,C,F,G done+verified; D deferred, E blocked-on-key) + CSS mixed-content fix |
| 01 | SPEC-01-ai-memory-fix.md | 1.1 | DONE (9 call sites: 3 IG + 6 FB comments) |
| 02 | SPEC-02-ai-provider-abstraction.md | 1.2 | DONE (verified: OpenAI path live, router, UI, keep-if-blank) |
| 03 | SPEC-03-ai-function-calling.md | 1.3 | DONE (lint+SQL verified; live tool-loop needs store+key) |
| 04 | SPEC-04-ai-sentiment-multilang.md | 1.4 | DONE (verified: marker extraction, UI, migration) |
| 05 | SPEC-05-product-catalog-chat.md | 2.1 | PENDING |
| 06 | SPEC-06-coupon-autogen-cart-ai.md | 2.2 | PARTIAL (coupon_helper done+wired to AI; AI-reminder-text/recovery-metric deferred - critical cron path) |
| 07 | SPEC-07-lead-scoring.md | 2.3 | DONE (schema+helper+2 hooks+decay+badge; msg/postback hooks deferred) |
| 08 | SPEC-08-channel-enum-migration.md | 3.0 | DONE (15 enums extended, row counts intact) |
| 09 | SPEC-09-human-handoff-pause.md | 3.3 | DONE (messenger gate + agent-assign pause + toggle endpoint; IG DM gate deferred) |
| 10 | SPEC-10-whatsapp-cloud-api.md | 3.1 | DONE (verified: webhook handshake+403, admin auth, AI via wa enum; live-chat send integration minimal) |
| 11 | SPEC-11-webchat-widget.md | 3.2 | PENDING |
| 12 | SPEC-12-crm-module.md | 4.1 | PENDING |
| 13 | SPEC-13-analytics-hub.md | 4.2 | DONE (verified: page 200, all charts, usage logging wired) |
| 14 | SPEC-14-ab-testing.md | 4.3 | PENDING |
| 15 | SPEC-15-telegram.md | 5.1 | DONE (verified: public webhook, admin auth, AI reply via tg enum) |
| 16 | SPEC-16-ai-content-writer.md | 5.2 | DONE (verified: page renders, sidebar link, generate graceful) |
| 17 | SPEC-17-appointments.md | 5.3 | PENDING |

Statuses: PENDING → IN-PROGRESS → REVIEW → DONE (or BLOCKED:<reason>)

## PARALLELIZATION RULES
- Run specs sequentially unless file sets are disjoint. Safe parallel pairs: (05,07), (13,14), (15,16,17).
- 08 (enum migration) MUST complete before 10, 11, 15.
- 02 MUST complete before 03, 04, 16.
- Never run two specs that both touch Home.php, Messenger_bot.php, or Ecommerce.php concurrently.
