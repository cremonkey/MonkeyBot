-- Products & Pricing per page (owner request 2026-09-23): a price list can belong to ONE
-- connected page / number / widget instead of the whole account.
--
-- Scope = (channel_type, target_id). ('', '') is the account-wide DEFAULT list, so every
-- existing row becomes the default list and production behaviour stays identical until the
-- owner gives a page its own list. Channel types are DISTINCT per account table:
--   fb       facebook_rx_fb_page_info.id  (the page, covers Messenger + its Instagram)
--   openwa   openwa_accounts.id           (personal WhatsApp number)
--   wa_cloud whatsapp_accounts.id         (WhatsApp Cloud API number)
--   tg       telegram_accounts.id
--   web      webchat_settings.widget_key
-- openwa and wa_cloud are separate on purpose: both tables use small numeric ids, and
-- ai_agent_assignments' shared 'wa' channel cannot tell openwa #5 from cloud #5.
--
-- Idempotent (IF [NOT] EXISTS everywhere). A failed statement leaves the file unrecorded
-- and Cron_hub::migrate retries the whole file. The new unique key is created BEFORE the old
-- one is dropped, so there is never a moment without uniqueness on user_id.
ALTER TABLE `pricing_config`
  ADD COLUMN IF NOT EXISTS `channel_type` VARCHAR(16) NOT NULL DEFAULT '' AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `target_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `channel_type`;

ALTER TABLE `pricing_config` ADD UNIQUE INDEX IF NOT EXISTS `uq_user_scope` (`user_id`, `channel_type`, `target_id`);

-- the original key from 2026-07-17-pricing-tool.sql: UNIQUE KEY `user_id` (`user_id`)
ALTER TABLE `pricing_config` DROP INDEX IF EXISTS `user_id`;

ALTER TABLE `pricing_config_history`
  ADD COLUMN IF NOT EXISTS `channel_type` VARCHAR(16) NOT NULL DEFAULT '' AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `target_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `channel_type`;
