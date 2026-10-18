-- Instagram comment/mention auto-reply: atomic per-comment claim.
-- The webhook used to dedupe by reading instagram_autoreply_report first and inserting later,
-- so two close Meta deliveries of the same comment could both send a private reply.
-- application/helpers/ig_comment_guard_helper.php does INSERT IGNORE here before any send;
-- only the request that inserts the row continues. Additive only.
--
-- Apply with:  php index.php cron_hub migrate
DO 0;

CREATE TABLE IF NOT EXISTS `ig_comment_claims` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `autoreply_table_id` int NOT NULL,
  `comment_id` varchar(100) NOT NULL,
  `claimed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_comment` (`autoreply_table_id`, `comment_id`),
  KEY `idx_claimed_at` (`claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
