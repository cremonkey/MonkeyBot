# SPEC-10: WhatsApp Cloud API channel (Phase 3.1)

> READ `.specs/MASTER-PLAN.md` header first. REQUIRES SPEC-08 (enum 'wa') DONE. Largest new-channel build — commit per task.

## Verified context
- No WABA integration exists. Livechat.php has DEAD stub code: `get_bot_whatsapp_by_phone_number_id()` ~740 returns []; commented Laravel-style `DB::table('whatsapp_bots'...)` calls at ~459, ~715, ~746. CLEAN THESE UP in Task 5.
- Subscribers: reuse `messenger_bot_subscriber` with `social_media='wa'`, `subscribe_id` = the WhatsApp phone number (wa_id), `page_table_id` = whatsapp_accounts.id (document this mapping in code comments).
- AI: `get_ai_reply_open_ai($desc,$human,$user_id,$page_id,$subscribe_id,'wa')` works once ai_conversation_history enum includes 'wa' (SPEC-08).
- CSRF: if enabled (SPEC-00), webhook URIs must be in csrf_exclude_uris (check both config copies — repo + docker volume).

## Task 1 — Module skeleton + tables
HMVC addon `application/modules/whatsapp_bot/controllers/Whatsapp_bot.php` — comment header: Addon Name: WhatsApp Bot / Unique Name: whatsapp_bot / Modules: {"901":{"bulk_limit_enabled":"0","limit_enabled":"0","extra_text":"","module_name":"WhatsApp Bot"}} / Project ID: 901. Extends Home (require_once pattern from modules/ai_reply). activate() registers with sidebar entry ("WhatsApp Bot" under a suitable icon) and $sql array:
```sql
CREATE TABLE IF NOT EXISTS whatsapp_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  label VARCHAR(100), waba_id VARCHAR(50), phone_number_id VARCHAR(50) NOT NULL,
  display_phone VARCHAR(30), access_token TEXT NOT NULL, verify_token VARCHAR(64) NOT NULL,
  ai_enabled ENUM('0','1') DEFAULT '1', status ENUM('0','1') DEFAULT '1', created_at DATETIME,
  UNIQUE KEY uq_pnid (phone_number_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, account_id INT NOT NULL,
  template_name VARCHAR(120), language VARCHAR(10) DEFAULT 'en', category VARCHAR(30),
  body TEXT, meta_status VARCHAR(20) DEFAULT 'draft', created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Do NOT call addon_credential_check() (internal addon) — short-circuit activate() straight to register_addon(). Mirror SQL to `assets/backup_db/migrations/2026-07-05-spec10.sql` and run on live DB too (activate may not be run immediately).

## Task 2 — Account management UI
Views under `application/modules/whatsapp_bot/views/`: accounts list + add/edit form (label, WABA id, phone_number_id, access token (store via secret_encrypt if helper exists), auto-generated verify_token readonly, AI toggle). Show the webhook callback URL to paste into Meta App dashboard: `<base_url>whatsapp_bot/webhook/<account_id>`. Match Stisla admin styling of sibling views (copy the layout scaffolding another module view uses — check how module views load the admin theme).

## Task 3 — Webhook (PUBLIC endpoint — no session)
`public function webhook($account_id)`: constructor currently calls member_validity() via parent — webhook must BYPASS auth: check how public endpoints in Home-extending controllers handle this (e.g. Messenger_bot webhook — likely a separate non-auth path or exclusion in the constructor). Options: skip parent auth when `$this->uri->segment(2)=='webhook'` in __construct BEFORE parent::__construct auth (inspect Home::__construct to find the right seam — member_validity() is called by the addon controller itself in ai_reply's pattern, so simply DON'T call it for webhook route; verify what parent::__construct itself enforces).
- GET: hub.mode=subscribe & hub.verify_token matches account row → echo hub.challenge.
- POST: parse `entry[].changes[].value.messages[]` (text type first; mark others "[media message]"); find-or-create subscriber (social_media='wa', last_name='' first_name=profile name from contacts[]); insert into livechat_messages (platform='wa', sender='user'); respect `bot_paused_until` if column exists; if account.ai_enabled → get_ai_reply_open_ai(..., 'wa') → send reply via Graph API + log livechat_messages (sender='bot').
- Always 200 fast; wrap everything in try/catch, log errors to CI log.

## Task 4 — Sender library
`application/libraries/Whatsapp_api.php`: `send_text($access_token,$phone_number_id,$to,$text)`, `send_template($access_token,$phone_number_id,$to,$template_name,$lang,$components=[])` → POST `https://graph.facebook.com/v21.0/{phone_number_id}/messages`, SSL verify TRUE, return decoded response. 24-hour rule: free-form text only replies to a user message <24h old — check last inbound livechat_messages timestamp before send_text; if stale, log + skip (report needs template).

## Task 5 — Live chat integration + stub cleanup
- Remove/neutralize dead stubs in Livechat.php (~459, ~715, ~740-749) — replace `get_bot_whatsapp_by_phone_number_id` internals with a real query on whatsapp_accounts.
- Make live chat list/render include platform='wa' conversations; sending from live chat routes to Whatsapp_api (find the platform switch in Livechat send paths ~319/392/590/661). Agent reply also refreshes bot_paused_until if SPEC-09 done.
- Templates UI: simple CRUD list (local storage; meta_status manual field; submission to Meta API optional — if trivial via POST /{waba_id}/message_templates, implement; else leave "draft/approved" manual and note it).

## Task 6 — CSRF + verify + commit
Add `whatsapp_bot/webhook/.+` to csrf_exclude_uris in BOTH config copies if CSRF enabled. Lint all; smoke; simulate webhook: `docker exec monkeybot-web-1 curl -s "http://127.0.0.1/whatsapp_bot/webhook/1?hub.mode=subscribe&hub.verify_token=x&hub.challenge=123"` (expect non-500; correct token path tested after creating a test account row — create one, test, delete). Report exactly what was verified.
