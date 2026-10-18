-- Instagram story-reply rules (ported from manychat-lite).
-- Before the single generic "story private reply" bot runs, a reply to a story is matched
-- against these rules: a rule for that exact story first, then keyword rules (same word-run
-- matching as comment campaigns). ig_story_seen remembers recent stories so the UI can offer
-- them by preview instead of asking for a raw story ID. Additive only.
--
-- Apply with:  php index.php cron_hub migrate
DO 0;

CREATE TABLE IF NOT EXISTS `ig_story_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `page_table_id` int NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `story_id` varchar(64) NULL,
  `keywords` varchar(500) NULL,
  `reply_text` text NOT NULL,
  `button_title` varchar(20) NULL,
  `button_url` varchar(1000) NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sent_count` int NOT NULL DEFAULT 0,
  `last_sent_at` datetime NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_page` (`page_table_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ig_story_seen` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_table_id` int NOT NULL,
  `story_id` varchar(64) NOT NULL,
  `story_url` text NULL,
  `last_text` varchar(500) NULL,
  `replies` int NOT NULL DEFAULT 1,
  `first_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_story` (`page_table_id`, `story_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
