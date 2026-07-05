# SPEC-11: Standalone website chat widget (Phase 3.2)

> READ `.specs/MASTER-PLAN.md` header first. REQUIRES SPEC-08 ('web' enum). Commit per task.

## Design
Embeddable JS widget (no iframe of FB), talking to public endpoints; AI replies synchronously; agent view via existing Live Chat (platform='web'); realtime via short-polling (Pusher keys are blank by default — polling is the dependable path; if Pusher configured, it enhances later).

## Task 1 — Module + tables
HMVC addon `application/modules/webchat/controllers/Webchat.php` (header: Addon Name: Web Chat Widget / Unique Name: webchat / Modules {"902":{...,"module_name":"Web Chat"}} / Project ID: 902; no addon_credential_check). Tables (activate + live DB + `assets/backup_db/migrations/2026-07-05-spec11.sql`):
```sql
CREATE TABLE IF NOT EXISTS webchat_settings (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, widget_key VARCHAR(32) NOT NULL,
  title VARCHAR(100) DEFAULT 'Chat with us', color VARCHAR(7) DEFAULT '#0084ff',
  greeting VARCHAR(255) DEFAULT 'Hi! How can we help?', ai_enabled ENUM('0','1') DEFAULT '1',
  status ENUM('0','1') DEFAULT '1', UNIQUE KEY uq_key (widget_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS webchat_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, session_key VARCHAR(40) NOT NULL,
  subscriber_id INT NULL, visitor_name VARCHAR(100) NULL, page_url VARCHAR(255),
  created_at DATETIME, last_activity DATETIME, UNIQUE KEY uq_sess (session_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Task 2 — Public API endpoints (no session auth for widget routes; rate-limit by IP+session_key: max 20 msg/min — simple COUNT on livechat_messages in last minute)
- `GET webchat/widget/<widget_key>` → serves the widget bootstrap JS (Content-Type application/javascript) rendering a floating button + chat panel (vanilla JS, no deps, self-contained CSS, uses settings colors/greeting). Panel: messages list + input; stores session_key in localStorage.
- `POST webchat/send` {widget_key, session_key?, message} → create session+subscriber if new (messenger_bot_subscriber: social_media='web', subscribe_id='web_'.session_key, first_name='Web Visitor'); insert user msg into livechat_messages (platform='web'); respect bot_paused_until; if ai_enabled → get_ai_reply_open_ai(...,'web') → insert bot reply; return JSON {messages:[...]}.
- `GET webchat/poll` {widget_key, session_key, since_id} → new messages (agent replies included) as JSON. Widget polls every 4s while open.
- CSRF: add `webchat/.+` to exclude list (both config copies) if enabled.

## Task 3 — Admin UI
Settings page (module view): title/color/greeting/AI toggle + copy-paste embed snippet:
`<script src="<base_url>webchat/widget/<widget_key>" async></script>`
Sidebar entry via activate(). Match admin theme style.

## Task 4 — Live chat agent side
Verify platform='web' conversations appear in Livechat UI (after SPEC-08/10 platform handling); agent replies from livechat must write livechat_messages rows the poll endpoint returns (agent send path may assume FB Graph send — add a 'web' branch that ONLY stores the message locally, no external API). Refresh bot_paused_until on agent reply if SPEC-09 done.

## Task 5 — Verify
Lint; smoke; end-to-end curl test: POST webchat/send with a real widget_key (create settings row for user 1), poll returns the message (AI reply appears only if user has AI configured — report either way); check livechat page renders. Commit: `feat: standalone website chat widget with AI + livechat integration`.
