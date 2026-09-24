-- Request/correlation ids (remaining-work Task 13, brief 39/51): one id generated once per
-- webhook entry (Home::central_webhook_callback, Openwa_bot::webhook, Whatsapp_bot::webhook,
-- Telegram_bot::webhook, Webchat::send/poll), stored on the CI superobject as
-- $CI->mb_request_id via request_id_helper.php, forwarded to internal handlers through
-- mb_internal_headers()'s new X-MB-Request-Id header, and written into events.correlation_id
-- (already exists, 2026-09-24-event-automation.sql), ai_usage_log.request_id,
-- ai_failure_log.request_id and automation_action_log.request_id, so one webhook message's
-- webhook -> AI -> send/automation rows can be found with a single id.
--
-- Code works before this migration runs: every writer checks field_exists('request_id', ...)
-- fresh/cached exactly like Inbox.php's own field_exists caching, and omits the column from the
-- INSERT until it exists. request_id_helper.php itself needs no schema at all.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS, MariaDB 10.11). No semicolons
-- in comments: the runner splits on them.
DO 0;

ALTER TABLE `ai_usage_log`
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'webhook-entry correlation id (request_id_helper.php), NULL for rows written before this migration';

ALTER TABLE `ai_usage_log`
  ADD INDEX IF NOT EXISTS `idx_request_id` (`request_id`);

ALTER TABLE `ai_failure_log`
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'webhook-entry correlation id (request_id_helper.php), NULL for rows written before this migration';

ALTER TABLE `ai_failure_log`
  ADD INDEX IF NOT EXISTS `idx_request_id` (`request_id`);

-- System Health Failures (24h) panel counts ai_failure_log by created_at with no user_id predicate
ALTER TABLE `ai_failure_log`
  ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`);

ALTER TABLE `automation_action_log`
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(36) NULL DEFAULT NULL
    COMMENT 'webhook-entry correlation id (request_id_helper.php), NULL for rows written before this migration';

ALTER TABLE `automation_action_log`
  ADD INDEX IF NOT EXISTS `idx_request_id` (`request_id`);
