# SPEC-16: AI Content Writer (Phase 5.2)

> READ `.specs/MASTER-PLAN.md` header first. REQUIRES SPEC-02 (Ai_provider).

## Task 1 — Module + table
HMVC addon `application/modules/ai_content_writer/controllers/Ai_content_writer.php` (Addon Name: AI Content Writer / Unique Name: ai_content_writer / Modules {"905":{...,"module_name":"AI Content Writer"}} / Project ID: 905; no credential check). Table (activate + live + migrations 2026-07-05-spec16.sql):
```sql
CREATE TABLE IF NOT EXISTS ai_content_history (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 content_type VARCHAR(30), prompt_input TEXT, generated_content LONGTEXT, language VARCHAR(30),
 created_at DATETIME, KEY idx_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

## Task 2 — UI (single page, module view, admin theme style)
Form: content type select (Social post / Ad copy / Product description / Email campaign / Comment reply), topic/brief textarea, tone select (professional/friendly/funny/urgent), language select (Auto/Arabic/English + free text), length (short/medium/long), count (1-3 variants). Generate button (AJAX POST) → results cards with Copy button + auto-save to history. History tab: last 50 generations (DataTable), view/copy/delete.

## Task 3 — Backend
`generate()` (POST, session auth): builds a purpose-specific system prompt per content type (write real prompts for all 5 types — marketing-copywriter persona, platform conventions, hashtags for social, AIDA for ads, SEO-aware product descriptions, subject+body for emails), calls `Ai_provider->completion()` with the user's open_ai_config row (purpose override 'content_writer', max_tokens by length: 300/700/1400). Requires the user to have AI configured — friendly error otherwise. Log to ai_usage_log if that table exists (purpose='content_writer').

## Verify
Lint; smoke; page renders (302/200); history table SQL verified. Commit: `feat: AI content writer module`.
