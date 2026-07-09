-- SPEC-21: price grounding guard
--
-- Every reply the guard blocks is recorded here, with the verdict, so the owner
-- can see which prices the bot tried to invent and which gaps caused it.

CREATE TABLE IF NOT EXISTS `ai_price_guard_log` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `page_id` varchar(64) DEFAULT NULL,
    `social_media` varchar(20) DEFAULT NULL,
    `subscribe_id` varchar(64) DEFAULT NULL,
    `question` text COLLATE utf8mb4_unicode_ci,
    `blocked_reply` text COLLATE utf8mb4_unicode_ci,
    `sent_reply` text COLLATE utf8mb4_unicode_ci,
    `verdict` enum('ungrounded') NOT NULL DEFAULT 'ungrounded',
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
