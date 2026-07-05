# SPEC-09: Bot pause on human handoff (Phase 3.3 — fixes bot/agent double-reply bug)

> READ `.specs/MASTER-PLAN.md` header first.

## Verified gap
Agent assignment exists (`messenger_bot_subscriber.assigned_used_id`, set at Home.php ~273, Pusher notify ~285-327; live-chat replies use HUMAN_AGENT tag at Livechat.php ~319/392/590/661) but the bot NEVER stops auto-replying — bot and human answer simultaneously.

## Task 1 — Migration
```sql
ALTER TABLE messenger_bot_subscriber ADD COLUMN bot_paused_until DATETIME NULL, ADD INDEX idx_bot_paused (bot_paused_until);
```
Live DB + `assets/backup_db/migrations/2026-07-05-spec09.sql`.

## Task 2 — Pause triggers
1. Agent assignment (Home.php ~273 where assigned_used_id updates): also set `bot_paused_until = NOW() + INTERVAL 6 HOUR`.
2. Agent sends a manual live-chat reply (all 4 send paths in Livechat.php ~319/392/590/661 — find the shared point if one exists): refresh `bot_paused_until = NOW() + INTERVAL 6 HOUR`.
3. If `Ai_tools.php::handoff_to_human` exists (SPEC-03): make it set the column too (it may already — verify, don't duplicate).

## Task 3 — Gate the auto-reply engines
At the TOP of the auto-reply decision path (before bot template/AI replies are sent) in:
- Messenger_bot.php webhook message handler,
- Instagram_reply.php DM webhook handler,
early-return (skip ALL automated replies, but still record the incoming message wherever it's currently recorded) when the subscriber row has `bot_paused_until IS NOT NULL AND bot_paused_until > NOW()`. Find the single narrowest choke point in each file; do not scatter checks. Comment automation is NOT gated (public comments ≠ private conversation).

## Task 4 — Live Chat UI control
In the live chat conversation view (find the conversation header/actions area in views/livechat/): show bot state ("🤖 Bot paused until H:i" / "🤖 Bot active") + a toggle button → AJAX endpoint `Livechat::toggle_bot_pause()` (POST, session auth, verify the subscriber belongs to one of the user's pages like sibling endpoints do): sets bot_paused_until = NOW()+6h or NULL. Match existing view/JS style.

## Task 5 — Verify
Lint; smoke; SQL-simulate: set a test subscriber's bot_paused_until to future, trace webhook code path manually to confirm early-return placement (paste the gate snippet + surrounding 5 lines in the report). Commit: `feat: pause bot during human handoff with livechat toggle`.
