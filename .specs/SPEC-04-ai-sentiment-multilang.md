# SPEC-04: Sentiment analysis + automatic language matching (Phase 1.4)

> READ `.specs/MASTER-PLAN.md` header first. Depends on SPEC-02. Commit at end.

## Design (single-call, no extra API cost)
Augment the system prompt inside `Home.php::get_ai_reply_open_ai()` and extract sentiment from a structured tail marker in the same completion.

## Task 1 — Migration
```sql
ALTER TABLE open_ai_config
  ADD COLUMN auto_language ENUM('0','1') NOT NULL DEFAULT '1',
  ADD COLUMN sentiment_enabled ENUM('0','1') NOT NULL DEFAULT '0';
ALTER TABLE ai_conversation_history ADD COLUMN sentiment VARCHAR(10) NULL;
```
Live DB + `assets/backup_db/migrations/2026-07-05-spec04.sql`.

## Task 2 — Prompt augmentation (in get_ai_reply_open_ai, after existing system prompt selection ~7017-7022)
- If `auto_language=='1'` append to system prompt: `"Always reply in the same language as the customer's last message (e.g. Arabic → Arabic, English → English). Match their dialect and tone."`
- If `sentiment_enabled=='1'` append: `"After your reply, on a new final line output exactly [[SENTIMENT:positive]] or [[SENTIMENT:neutral]] or [[SENTIMENT:negative]] describing the customer's mood. Nothing after it."` plus `"If the customer is angry or frustrated: apologize briefly, de-escalate, and offer to connect them with a human agent."`

## Task 3 — Extraction
After the completion returns and BEFORE the reply is saved/returned:
- `preg_match('/\[\[SENTIMENT:(positive|neutral|negative)\]\]/i', $text, $m)` → strip the marker (and trailing whitespace) from the reply text; store `$m[1]` into the new `sentiment` column when saving to `ai_conversation_history` (the existing insert ~7063-7090).
- Defensive: also strip any marker occurrences mid-text. Reply returned to callers must NEVER contain the marker.

## Task 4 — UI
Two toggles in `application/views/admin/openAI/api_credentials.php` + save handling in `Integration.php` (match existing checkbox style, e.g. sales_mode_enabled).

## Task 5 — Verify
Lint, smoke, and a regex sanity check via `php -r` for the extraction pattern. Commit: `feat: AI sentiment tagging + automatic language matching`.
