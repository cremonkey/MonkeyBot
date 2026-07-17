-- SPEC-28 — durable audit of what the bot actually told customers, independent of the
-- conversation-memory TTL (ai_conversation_history self-prunes at 24h). For dispute
-- resolution and compliance: keeps the question, the sent reply, whether a guard fired,
-- and which tools ran.
CREATE TABLE IF NOT EXISTS `ai_reply_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` varchar(64) DEFAULT NULL,
  `social_media` varchar(12) DEFAULT NULL,
  `subscribe_id` varchar(80) DEFAULT NULL,
  `question` text DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `guard` varchar(20) DEFAULT NULL,
  `tools` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_created` (`user_id`, `created_at`),
  KEY `sub` (`subscribe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
