# SPEC-08: Channel enum migration fb/ig → +wa/web/tg (Phase 3.0 — prerequisite for 10/11/15)

> READ `.specs/MASTER-PLAN.md` header first. HIGHEST-RISK DB CHANGE IN THE PROJECT. Fresh mysqldump REQUIRED before starting; verify dump file size > 0 before proceeding.

## Task 1 — Discover every fb/ig enum in the LIVE schema
```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='monkeybot' AND COLUMN_TYPE LIKE "%'fb'%" OR (TABLE_SCHEMA='monkeybot' AND COLUMN_TYPE LIKE "%'ig'%");
```
(Watch operator precedence — parenthesize properly.) Known expected: `messenger_bot_subscriber.social_media`, `ai_conversation_history.social_media`, `livechat_messages.platform`, `user_input_custom_fields.media_type`. There may be more — list ALL findings in the report.

## Task 2 — ALTER each to include the union of old values + 'wa','web','tg'
Example: `ALTER TABLE messenger_bot_subscriber MODIFY social_media ENUM('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';`
Preserve each column's original NULL-ability and DEFAULT exactly (read from SHOW CREATE TABLE first). MariaDB enum extension (appending values) is a metadata-only change — still run them one at a time and re-verify each with SHOW CREATE TABLE.

## Task 3 — Record + code scan
- Save all executed ALTERs to `assets/backup_db/migrations/2026-07-05-spec08.sql`.
- Grep code for hardcoded exhaustive channel checks that would break with new values (e.g. `social_media=='ig' ? X : Y` assumed-binary patterns): `grep -rn "social_media" application/controllers/Home.php application/controllers/Livechat.php | head -40`. Do NOT refactor — just LIST risky binary assumptions in the report for the channel specs (10/11/15) to handle.
- Row counts before/after each ALTER must match (`SELECT COUNT(*)`).

## Task 4 — Verify + commit
Smoke test; spot check live chat page and subscriber manager page return 200/302 (session redirect is fine). Commit migration file: `feat: extend channel enums for whatsapp/webchat/telegram`.
