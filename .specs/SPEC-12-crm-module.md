# SPEC-12: Internal CRM addon module (Phase 4.1)

> READ `.specs/MASTER-PLAN.md` header first. Biggest module — commit per feature (A..F). REQUIRES SPEC-07 (lead_score) done.

## Verified integration surface
- Subscribers: `messenger_bot_subscriber` (first_name,last_name,email,phone_number,gender,user_location,social_media,assigned_used_id,last_interaction_time,lead_score,labels via `messenger_bot_subscribers_label`).
- Custom fields: `user_input_custom_fields` + `user_input_custom_fields_assaign` (misspelling is REAL — use as-is; unique (subscriber_id,page_id,custom_field_id)).
- Conversations: `livechat_messages` (platform,sender,agent_name,message_content,created_at) + `ai_conversation_history`.
- Orders/carts: `ecommerce_cart`(+_item, status column — inspect values), abandoned data in `ecommerce_reminder_report`.
- Drips: `messenger_bot_drip_campaign(_assign)` (campaign_type messenger/email/sms).
- Admin theme: Stisla/Bootstrap; DataTables/Select2/Chart.js bundled under assets/plugins.
- Pusher lib exists (Ci_pusher) — optional, degrade silently if keys blank.

## Task 0 — Module skeleton
`application/modules/crm/controllers/Crm.php` header: Addon Name: CRM / Unique Name: crm / Modules {"903":{"bulk_limit_enabled":"0","limit_enabled":"0","extra_text":"","module_name":"CRM"}} / Project ID: 903. Extends Home; NO addon_credential_check. activate() → register_addon with 2-level sidebar (CRM → Dashboard, Pipeline, Contacts, Activities) + $sql below. Model `application/modules/crm/models/Crm_model.php`. Views under `application/modules/crm/views/`. Also run SQL on live DB now + save `assets/backup_db/migrations/2026-07-05-spec12.sql`:
```sql
CREATE TABLE IF NOT EXISTS crm_pipelines (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(100) NOT NULL, is_default ENUM('0','1') DEFAULT '0', status ENUM('0','1') DEFAULT '1', created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_stages (id INT AUTO_INCREMENT PRIMARY KEY, pipeline_id INT NOT NULL, name VARCHAR(100) NOT NULL, position INT DEFAULT 0, color VARCHAR(7) DEFAULT '#6777ef', stage_type ENUM('open','won','lost') DEFAULT 'open', KEY idx_pipe (pipeline_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_deals (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, pipeline_id INT NOT NULL, stage_id INT NOT NULL, title VARCHAR(150) NOT NULL, value DECIMAL(12,2) DEFAULT 0, currency VARCHAR(10) DEFAULT 'USD', subscriber_id INT NULL, contact_name VARCHAR(120), contact_email VARCHAR(120), contact_phone VARCHAR(40), source ENUM('fb','ig','wa','web','tg','manual') DEFAULT 'manual', ecommerce_cart_id INT NULL, expected_close_date DATE NULL, assigned_to INT NULL, status ENUM('open','won','lost') DEFAULT 'open', lost_reason VARCHAR(255) NULL, won_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, KEY idx_u_p_s (user_id,pipeline_id,stage_id), KEY idx_sub (subscriber_id), KEY idx_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_activities (id INT AUTO_INCREMENT PRIMARY KEY, deal_id INT NULL, subscriber_id INT NULL, user_id INT NOT NULL, type ENUM('call','email','meeting','note','task','follow_up') DEFAULT 'task', subject VARCHAR(150), description TEXT, due_date DATETIME NULL, status ENUM('pending','completed') DEFAULT 'pending', completed_at DATETIME NULL, created_at DATETIME, KEY idx_deal (deal_id), KEY idx_due (user_id,due_date,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_deal_timeline (id INT AUTO_INCREMENT PRIMARY KEY, deal_id INT NOT NULL, user_id INT NOT NULL, action VARCHAR(50), old_value VARCHAR(255), new_value VARCHAR(255), created_at DATETIME, KEY idx_deal (deal_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Feature A — Pipelines & Kanban board
- On first visit auto-seed default pipeline: New Lead / Interested / Negotiation / Abandoned Cart / Won(stage_type won) / Lost(stage_type lost).
- Pipelines CRUD (name, stages add/rename/reorder/color).
- Kanban view: columns per stage, deal cards (title, value+currency, contact name, source icon, lead_score badge if subscriber linked). Drag-drop with jQuery UI sortable if bundled (check assets/plugins; else Up/Down + "Move to stage" dropdown). Move → POST `crm/move_deal` → update + timeline row.
- Deal CRUD modal (all fields; subscriber picker = Select2 AJAX over user's subscribers).

## Feature B — Deal detail page
Tabs: Overview (fields + timeline) | Conversation (livechat_messages + ai_conversation_history for the linked subscriber, merged chronologically, paginated 50) | Orders (ecommerce_cart rows for subscriber) | Activities. "Open Live Chat" link to the existing livechat page for that subscriber (inspect livechat URL format).

## Feature C — Contacts
DataTable over messenger_bot_subscriber (user's pages only — reuse the ownership join Subscriber_manager.php uses; INSPECT it first): name, channel, email/phone, lead_score badge, last_interaction, open-deals count, labels. Filters: channel, score band. Row actions: Create Deal / View / Open Live Chat. Contact detail page merges profile + custom field values + conversations + orders + deals.

## Feature D — Auto-deal creation (helper `application/helpers/crm_helper.php`)
`crm_auto_deal($user_id,$subscriber_id,$trigger,$data)` — idempotent (skip if an open deal exists for subscriber+trigger):
1. Hook in checkout-complete path (same spot SPEC-07 used for 'purchase'): create/mark deal Won with order value.
2. Hook in abandoned-cart cron (Cron_job.php reminder loop — one line): deal in "Abandoned Cart" stage with cart value.
3. Hook in lead_scoring helper: when score crosses 50 upward: deal in "New Lead".
All hooks silent-fail (try/catch, log). If the user has no pipeline yet, auto-seed first.

## Feature E — CRM Dashboard
Cards: open pipeline value, deals won this month, tasks due today, hot leads count. Charts (Chart.js): won/lost by month (6mo), deals by source, funnel (open→won conversion per stage). Cache heavy aggregates 5 min (CI cache file driver).

## Feature F — Activities
CRUD + "My Day" list (due today/overdue highlighted); complete button; optional deal link. No calendar dependency.

## Non-functional (MANDATORY)
- Query Builder only; every query scoped by user_id; every AJAX endpoint POST + session-auth + ownership check (deal.user_id === session user).
- CSRF: admin AJAX inherits the global prefilter (SPEC-00) — use standard $.post.
- No changes to existing tables. deactivate() = unregister only. delete() drops crm_* after confirm.
- docs/CRM_MODULE.md: setup, hook points list, cron notes.

## Verify
Lint all; smoke; activate the addon via direct method call is AJAX-gated — instead INSERT the add_ons/modules/menu rows the way register_addon would OR temporarily invoke activate via authenticated session; simplest reliable path: run register_addon-equivalent SQL manually, then verify sidebar renders (curl the admin page for menu HTML after login… if no test login available, verify by code-trace + table existence). Report exactly what was verified. Commit per feature.
