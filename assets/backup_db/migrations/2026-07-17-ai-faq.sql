-- SPEC-25 — team-added answers to questions the bot could not answer.
-- Injected into the system prompt at reply time (one store, read live by every channel),
-- so it sidesteps the multi-copy prompt sync entirely. page_id NULL = applies to all pages.
CREATE TABLE IF NOT EXISTS `ai_faq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` varchar(64) DEFAULT NULL,
  `question` text DEFAULT NULL,
  `answer` text DEFAULT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_page` (`user_id`, `page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
