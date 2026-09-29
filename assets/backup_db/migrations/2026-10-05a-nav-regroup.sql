-- Task 22 (C7, brief §34) — Admin navigation regroup. Collapses the flat, ~34-row
-- top-level admin sidebar into six headed sections (Inbox / AI Sales / Automations /
-- Customers / Insights / Settings), matching the brief's "Reduce complexity" target
-- structure, and parks everything else under an "Advanced / Legacy" header so nothing
-- is removed or hidden from the users who can see it today.
--
-- Scope: this migration touches ONLY `menu`.`serial`, `menu`.`header_text` and
-- `menu`.`have_child`. It never touches `url`, `module_access`, `only_admin`,
-- `only_member`, `add_ons_id`, `is_external`, `custom_page_id`, or any row in
-- `menu_child_1` / `menu_child_2` (no top-level item is being re-parented into a
-- child menu, so no parent-link column needs to change). No row is inserted or
-- deleted; `sidebar.php` renders the `menu-header` label from `header_text` right
-- before the row that carries it, and renders rows in `serial` order, so regrouping
-- is entirely a matter of header_text + serial.
--
-- Backup: the *first* run of this migration snapshots the pre-regroup `menu` table
-- into `menu_backup_20261005` with `CREATE TABLE IF NOT EXISTS ... AS SELECT`, which
-- MariaDB only populates when the table does not yet exist — so re-running this
-- migration (or any later migration) never overwrites that backup. The paired revert
-- script is docker/scripts/nav_regroup_revert.sql.
--
-- Visibility unchanged: 'Customers' (module 413), 'Inbox', 'Products & Pricing',
-- 'Sequences', 'Setup Guide', 'Agency Control Center', 'AI Improvement Center',
-- 'Automations' and 'Analytics Hub' keep their existing only_admin/only_member/
-- module_access, so they stay visible to exactly the same users as today — they
-- just move under their new section header instead of sitting at the old flat serials.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: CREATE TABLE IF NOT EXISTS ... AS SELECT (backup, first-run-only) +
-- UPDATE ... WHERE url = '<literal>' (re-running sets the same values again).
DO 0;

-- 1) Backup-first: snapshot menu exactly as it stands before any UPDATE below.
--    IF NOT EXISTS makes this a no-op (data untouched) on every run after the first.
CREATE TABLE IF NOT EXISTS `menu_backup_20261005` AS SELECT * FROM `menu`;

-- 2) Regroup. Dashboard stays header-less at the very top (serial 1, unchanged).

-- ---- Inbox ----------------------------------------------------------------
UPDATE `menu` SET `serial` = 10, `header_text` = 'Inbox'      WHERE `url` = 'inbox';

-- ---- AI Sales ---------------------------------------------------------------
UPDATE `menu` SET `serial` = 20, `header_text` = 'AI Sales'   WHERE `url` = 'ai_improvement';
UPDATE `menu` SET `serial` = 21, `header_text` = ''           WHERE `url` = 'pricing';

-- ---- Automations ------------------------------------------------------------
UPDATE `menu` SET `serial` = 30, `header_text` = 'Automations' WHERE `url` = 'automations';
UPDATE `menu` SET `serial` = 31, `header_text` = ''            WHERE `url` = 'sequences';

-- ---- Customers ----------------------------------------------------------------
UPDATE `menu` SET `serial` = 40, `header_text` = 'Customers'  WHERE `url` = 'customers';

-- ---- Insights -----------------------------------------------------------------
UPDATE `menu` SET `serial` = 50, `header_text` = 'Insights'   WHERE `url` = 'analytics_hub';

-- ---- Settings -----------------------------------------------------------------
-- The header carrier must be a row visible to both Admin and Member (sidebar.php
-- prints header_text unconditionally on that specific row, so a header attached to
-- an only_admin='1' row like 'agency' would silently vanish for Member accounts).
-- 'onboarding' (Setup Guide) has only_admin=0/only_member=0, so it carries the header.
UPDATE `menu` SET `serial` = 60, `header_text` = 'Settings'   WHERE `url` = 'onboarding';
UPDATE `menu` SET `serial` = 61, `header_text` = ''           WHERE `url` = 'agency';
UPDATE `menu` SET `serial` = 62, `header_text` = ''           WHERE `url` = 'social_accounts/index';
UPDATE `menu` SET `serial` = 63, `header_text` = '', `have_child` = '1'
  WHERE `url` = '' AND `name` = 'System';
UPDATE `menu` SET `serial` = 64, `header_text` = '', `have_child` = '1'
  WHERE `url` = '' AND `name` = 'Subscription';

-- ---- Advanced / Legacy --------------------------------------------------------
-- Per-channel and superseded screens: still fully reachable, just no longer part
-- of the primary six sections. First row in the block carries the header.
UPDATE `menu` SET `serial` = 100, `header_text` = 'Advanced / Legacy' WHERE `url` = 'integration';
UPDATE `menu` SET `serial` = 101, `header_text` = '' WHERE `url` = 'comment_automation/comment_growth_tools/fb';
UPDATE `menu` SET `serial` = 102, `header_text` = '' WHERE `url` = 'subscriber_manager/bot_subscribers?media_type=fb';
UPDATE `menu` SET `serial` = 103, `header_text` = '' WHERE `url` = 'livechat/load_livechat?media_type=fb';
UPDATE `menu` SET `serial` = 104, `header_text` = '' WHERE `url` = 'messenger_bot/bot_list?media_type=fb';
UPDATE `menu` SET `serial` = 105, `header_text` = '' WHERE `url` = 'messenger_bot_broadcast?media_type=fb';
UPDATE `menu` SET `serial` = 106, `header_text` = '' WHERE `url` = 'ecommerce';
UPDATE `menu` SET `serial` = 107, `header_text` = '' WHERE `url` = 'ultrapost';
UPDATE `menu` SET `serial` = 108, `header_text` = '' WHERE `url` = 'subscriber_manager/bot_subscribers?media_type=ig';
UPDATE `menu` SET `serial` = 109, `header_text` = '' WHERE `url` = 'livechat/load_livechat?media_type=ig';
UPDATE `menu` SET `serial` = 110, `header_text` = '' WHERE `url` = 'messenger_bot/bot_list?media_type=ig';
UPDATE `menu` SET `serial` = 111, `header_text` = '' WHERE `url` = 'comment_automation/comment_growth_tools/ig';
UPDATE `menu` SET `serial` = 112, `header_text` = '' WHERE `url` = 'whatsapp_bot';
UPDATE `menu` SET `serial` = 113, `header_text` = '' WHERE `url` = 'webchat';
UPDATE `menu` SET `serial` = 114, `header_text` = '' WHERE `url` = 'telegram_bot';
UPDATE `menu` SET `serial` = 115, `header_text` = '' WHERE `url` = 'crm';
UPDATE `menu` SET `serial` = 116, `header_text` = '' WHERE `url` = 'appointment_booking';
UPDATE `menu` SET `serial` = 117, `header_text` = '' WHERE `url` = 'ai_content_writer';
UPDATE `menu` SET `serial` = 118, `header_text` = '' WHERE `url` = 'ai_agents';
UPDATE `menu` SET `serial` = 119, `header_text` = '' WHERE `url` = 'openwa_bot';
UPDATE `menu` SET `serial` = 120, `header_text` = '' WHERE `url` = 'reengage';
