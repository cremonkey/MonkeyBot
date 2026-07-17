-- SPEC-27 — alert the owner when the bot repeatedly can't answer the same thing.
ALTER TABLE `sales_automation_settings`
  ADD COLUMN `deflect_alert_enabled` enum('0','1') NOT NULL DEFAULT '0' AFTER `digest_hour`,
  ADD COLUMN `deflect_alert_threshold` int(11) NOT NULL DEFAULT 3 AFTER `deflect_alert_enabled`,
  ADD COLUMN `deflect_alert_email` varchar(190) DEFAULT NULL AFTER `deflect_alert_threshold`,
  ADD COLUMN `deflect_alert_whatsapp` varchar(30) DEFAULT NULL AFTER `deflect_alert_email`;

-- one row per (user, question-topic) already alerted, so we never nag twice for the same gap
CREATE TABLE IF NOT EXISTS `ai_deflect_alert_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `topic_key` varchar(191) NOT NULL,
  `hits` int(11) NOT NULL DEFAULT 0,
  `alerted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_topic` (`user_id`, `topic_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
