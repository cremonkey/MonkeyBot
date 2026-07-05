# SPEC-13: Analytics Hub + AI usage/cost tracking (Phase 4.2)

> READ `.specs/MASTER-PLAN.md` header first. Can run parallel with SPEC-14 (disjoint files) — but NOT with anything touching Home.php (Task 1 touches Ai_provider only, verify).

## Task 1 — AI usage log
Migration (live + `assets/backup_db/migrations/2026-07-05-spec13.sql`):
```sql
CREATE TABLE IF NOT EXISTS ai_usage_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 provider VARCHAR(20), model VARCHAR(100), input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0,
 purpose VARCHAR(40) DEFAULT 'chat_reply', created_at DATETIME, KEY idx_user_time (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```
In `application/libraries/Ai_provider.php::completion()`: after every successful call, insert a row (usage fields from the provider response: OpenAI usage.prompt_tokens/completion_tokens; Anthropic usage.input_tokens/output_tokens; 0 if absent). Never fail the reply on logging error.

## Task 2 — Analytics controller + view
`application/controllers/Analytics_hub.php` (session-auth like Dashboard.php — copy its constructor pattern & module-access style) + view `application/views/admin/analytics_hub/index.php`. Sidebar: inspect how Dashboard/other core (non-addon) items get into the sidebar (`application/views/admin/theme/sidebar.php`) and add an entry "Analytics" guarded by login.
Metrics (all scoped to logged-in user's data; date-range picker default 30 days; Chart.js):
1. Subscribers: new per day (line), split by social_media (fb/ig/wa/web/tg aware).
2. Conversations: livechat_messages per day by sender (user vs bot vs agent) — response ratio.
3. AI: replies per day, tokens per day, estimated cost (rough map: gpt-4o-mini $0.15/$0.60 per 1M, gpt-4o $2.50/$10, gpt-4.1 $2/$8, claude-haiku-4-5 $1/$5, claude-sonnet-4-5 $3/$15 — put map in one const, comment "estimates").
4. Sales: ecommerce orders count+value per day (`ecommerce_cart` completed statuses — inspect), abandoned vs recovered.
5. Broadcasts: messenger_bot_broadcast_serial_send success/fail counts.
6. Lead scoring: band distribution pie (if lead_score exists).
Layout: stat cards row + 2-col charts grid. Keep queries indexed/aggregated (GROUP BY DATE) with LIMIT windows; cache 5 min.

## Task 3 — Verify
Lint; smoke; curl the page (expect login redirect 302 = OK routing); run each aggregate SQL manually once against live DB to prove column names. Commit: `feat: analytics hub + AI usage and cost tracking`.
