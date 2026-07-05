# SPEC-15: Telegram bot channel (Phase 5.1)

> READ `.specs/MASTER-PLAN.md` header first. REQUIRES SPEC-08 ('tg' enum). Mirror SPEC-10's architecture (read it) — Telegram is the simpler sibling.

## Task 1 — Module + table
HMVC addon `application/modules/telegram_bot/controllers/Telegram_bot.php` (Addon Name: Telegram Bot / Unique Name: telegram_bot / Modules {"904":{...,"module_name":"Telegram Bot"}} / Project ID: 904; no credential check). Table (activate + live DB + migrations file 2026-07-05-spec15.sql):
```sql
CREATE TABLE IF NOT EXISTS telegram_accounts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 bot_username VARCHAR(100), bot_token VARCHAR(120) NOT NULL, webhook_secret VARCHAR(64) NOT NULL,
 ai_enabled ENUM('0','1') DEFAULT '1', status ENUM('0','1') DEFAULT '1', created_at DATETIME,
 UNIQUE KEY uq_token (bot_token)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Task 2 — Library
`application/libraries/Telegram_api.php`: `send_message($token,$chat_id,$text)` → POST `https://api.telegram.org/bot{token}/sendMessage` (SSL verify TRUE); `set_webhook($token,$url,$secret)` → setWebhook with secret_token param; `get_me($token)` for validation.

## Task 3 — Admin UI
Accounts list + add form (paste bot token from @BotFather → validate via get_me, auto-fill username, auto-set webhook to `<base_url>telegram_bot/webhook/<account_id>` with generated secret). Token stored via secret_encrypt if helper exists. AI toggle.

## Task 4 — Webhook (public, no session — same auth-bypass seam SPEC-10 used; add `telegram_bot/.+` to csrf_exclude_uris both config copies)
`webhook($account_id)`: verify `X-Telegram-Bot-Api-Secret-Token` header matches; parse message.text; find-or-create subscriber (social_media='tg', subscribe_id=chat_id, name from message.from); log livechat_messages platform='tg' sender='user'; respect bot_paused_until; AI reply via get_ai_reply_open_ai(...,'tg') → send_message + log sender='bot'. Always 200, try/catch everything.

## Task 5 — Live chat
platform='tg' conversations visible; agent send path branch → Telegram_api::send_message; refresh bot_paused_until on agent reply.

## Verify
Lint; smoke; webhook curl simulation with fake payload + test account row (then clean up). Commit: `feat: telegram bot channel`.
