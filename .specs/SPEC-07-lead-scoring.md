# SPEC-07: Lead scoring engine (Phase 2.3)

> READ `.specs/MASTER-PLAN.md` header first. Hooks touch Messenger_bot.php/Ecommerce.php/Home.php — no concurrent specs on those files.

## Task 1 — Migration (live DB + `assets/backup_db/migrations/2026-07-05-spec07.sql`)
```sql
ALTER TABLE messenger_bot_subscriber ADD COLUMN lead_score INT NOT NULL DEFAULT 0, ADD INDEX idx_lead_score (lead_score);
CREATE TABLE IF NOT EXISTS lead_scoring_rules (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL DEFAULT 0,
  event_type VARCHAR(50) NOT NULL, points INT NOT NULL, status ENUM('0','1') NOT NULL DEFAULT '1',
  UNIQUE KEY uq_user_event (user_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS lead_scoring_events (
  id INT AUTO_INCREMENT PRIMARY KEY, subscriber_id INT NOT NULL, user_id INT NOT NULL,
  event_type VARCHAR(50) NOT NULL, points INT NOT NULL, event_data VARCHAR(255) NULL,
  created_at DATETIME NOT NULL, KEY idx_sub (subscriber_id), KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
INSERT IGNORE INTO lead_scoring_rules (user_id,event_type,points) VALUES
 (0,'message_received',1),(0,'postback_click',3),(0,'price_question',5),
 (0,'add_to_cart',8),(0,'purchase',15),(0,'human_handoff',10);
```
user_id=0 rows are global defaults; a per-user row overrides the same event_type.

## Task 2 — Helper
`application/helpers/lead_scoring_helper.php`:
- `lead_add_score($subscriber_row_id, $event_type, $event_data=null)` — resolve subscriber → user_id; rule lookup (user row first, else user_id=0; status 1); insert event + `UPDATE messenger_bot_subscriber SET lead_score = lead_score + points`. Silent no-op on missing rule/subscriber; NEVER throw (called from webhooks). Anti-spam: `message_received` max once per subscriber per 10 minutes (check last event of that type).
- `lead_band($score)` → 'hot' (≥50) / 'warm' (20-49) / 'cold'.

## Task 3 — Hook points (one guarded line each; load helper where needed)
1. Messenger webhook incoming message (Messenger_bot.php — near where subscriber row is resolved) → `message_received`; if message text matches `/price|سعر|بكام|كم سعر|cost|how much/i` → also `price_question`.
2. Messenger webhook postback path → `postback_click`.
3. Add-to-cart: Ecommerce.php store add-to-cart action AND SPEC-05's ECOM_ADDCART_ handler if present → `add_to_cart` (only when a subscriber link exists; store-front web carts without subscriber → skip).
4. Checkout complete (Ecommerce.php order-success path — find where cart status flips to completed/paid) → `purchase`.
5. Agent assignment (Home.php ~273 `assigned_used_id` update) → `human_handoff`.

## Task 4 — Subscriber Manager UI
In `Subscriber_manager.php` + its list view: add Lead Score column (badge colored by band: hot=red/danger, warm=warning, cold=secondary) to the subscriber DataTable, sortable; add band filter dropdown if the page has a filter bar (match existing filter style; if adding a filter is invasive, sortable column alone is acceptable — note it).

## Task 5 — Decay cron
New endpoint in Cron_job.php following the existing pattern (`api_key_check($api_key)` guard, see e.g. line 3051): `lead_score_decay($api_key='')` — `UPDATE messenger_bot_subscriber SET lead_score = GREATEST(0, lead_score-1) WHERE last_interaction_time < NOW() - INTERVAL 7 DAY AND lead_score > 0` (verify last_interaction_time column name/type first). Document the cron URL in the final report.

## Task 6 — Verify
Lint; smoke; insert a fake scoring event via helper logic SQL to confirm schema; check Subscriber Manager page renders (curl 200/302). Commit: `feat: lead scoring engine with webhook hooks and UI`.
