-- Instagram comment/mention campaigns: follow gate (ported from manychat-lite).
-- With follow_gate = 1 the private reply is replaced by a short message with one button.
-- The real reply is parked in ig_follow_gate_pending and delivered from the Messenger webhook
-- only after the tap AND is_user_follow_business = true for that user. Additive only.
--
-- Apply with:  php index.php cron_hub migrate
DO 0;

ALTER TABLE `instagram_reply_autoreply`
  ADD COLUMN IF NOT EXISTS `follow_gate` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `follow_gate_text` text NULL,
  ADD COLUMN IF NOT EXISTS `follow_gate_button` varchar(40) NULL,
  ADD COLUMN IF NOT EXISTS `follow_gate_retry_text` text NULL;

CREATE TABLE IF NOT EXISTS `ig_follow_gate_pending` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `autoreply_table_id` int NOT NULL,
  `page_table_id` int NOT NULL,
  `comment_id` varchar(100) NOT NULL,
  `messages_json` mediumtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `igsid` varchar(64) NULL,
  `attempts` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `delivered_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign` (`autoreply_table_id`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
