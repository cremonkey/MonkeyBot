-- Runs FIRST (dated before the SPEC migrations, which ALTER some of these tables).
-- Tables that exist on production but were never created by any migration (created by hand,
-- lazily by add-on activate() methods, or by runtime code). Found by building staging from
-- initial_db.sql + migrations on 2026-09-22 and diffing against the production schema.
-- Structure only (no data), taken from the 2026-09-22 production dump. IF NOT EXISTS makes it
-- a no-op on production.
DO 0;

-- AI Knowledge Base (was only created by the add-on activate(); spec20 alters it)
CREATE TABLE IF NOT EXISTS `ai_knowledge_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` int(11) DEFAULT NULL COMMENT 'Optional: scope source to a specific page',
  `source_type` enum('pdf','url','md','text') NOT NULL DEFAULT 'url',
  `source_name` varchar(255) NOT NULL,
  `source_url` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `total_chunks` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_status` (`user_id`,`status`),
  KEY `source_type` (`source_type`),
  KEY `page_id_status` (`page_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_chunks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL,
  `chunk_text` longtext NOT NULL,
  `embedding` blob DEFAULT NULL,
  `embedding_model` varchar(64) DEFAULT NULL,
  `chunk_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `source_id_order` (`source_id`,`chunk_order`),
  FULLTEXT KEY `chunk_text_fulltext` (`chunk_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_coupon_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `coupon_code` varchar(64) NOT NULL,
  `social_media` varchar(16) NOT NULL DEFAULT '',
  `subscribe_id` varchar(128) NOT NULL DEFAULT '',
  `status` enum('sent','failed','no_recipient') NOT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupon` (`coupon_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `social_media` varchar(16) NOT NULL,
  `page_id` varchar(64) NOT NULL DEFAULT '',
  `subscribe_id` varchar(128) NOT NULL,
  `last_interaction` datetime NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `once` (`user_id`,`social_media`,`page_id`,`subscribe_id`,`last_interaction`),
  KEY `user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_unanswered_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` varchar(64) DEFAULT NULL,
  `social_media` varchar(16) NOT NULL DEFAULT 'fb',
  `subscribe_id` varchar(128) DEFAULT NULL,
  `question` text DEFAULT NULL,
  `ai_reply` text DEFAULT NULL,
  `status` enum('new','resolved') NOT NULL DEFAULT 'new',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_status` (`user_id`,`status`),
  KEY `page` (`page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_sheet_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `service_account_json` longtext DEFAULT NULL,
  `spreadsheet_id` varchar(128) NOT NULL DEFAULT '',
  `sheet_tab` varchar(100) NOT NULL DEFAULT 'Sheet1',
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `access_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `access_token` longtext DEFAULT NULL,
  `refresh_token` longtext DEFAULT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `google_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `google_account_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `sheet_id` varchar(255) NOT NULL,
  `sheet_names` longtext DEFAULT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_status` (`user_id`,`status`),
  KEY `google_account_id` (`google_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_engagement_2way_chat_plugin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_auto_id` int(11) NOT NULL,
  `facebook_rx_fb_user_info_id` int(11) NOT NULL,
  `domain_name` varchar(250) NOT NULL,
  `language` varchar(255) NOT NULL,
  `minimized` enum('hide','show','fade','default') DEFAULT 'default',
  `logged_in` varchar(80) NOT NULL,
  `logged_out` varchar(80) NOT NULL,
  `color` varchar(7) NOT NULL,
  `domain_code` varchar(200) NOT NULL,
  `label_ids` varchar(250) NOT NULL,
  `template_id` int(11) NOT NULL,
  `reference` varchar(200) NOT NULL,
  `delay` int(11) NOT NULL DEFAULT 0,
  `donot_show_if_not_login` enum('0','1') NOT NULL DEFAULT '0',
  `add_date` datetime NOT NULL,
  `visual_flow_campaign_id` int(11) NOT NULL DEFAULT 0,
  `visual_flow_type` enum('flow','general') NOT NULL DEFAULT 'general',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_code` (`domain_code`),
  KEY `page_auto_id` (`page_auto_id`,`facebook_rx_fb_user_info_id`),
  KEY `reference` (`reference`),
  KEY `visual_flow_campaign_id` (`visual_flow_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_engagement_messenger_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` int(11) NOT NULL COMMENT 'auto id',
  `reference` varchar(250) NOT NULL,
  `label_ids` varchar(250) NOT NULL,
  `template_id` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`page_id`),
  KEY `reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_engagement_mme` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_code` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `page_id` int(11) NOT NULL COMMENT 'auto id',
  `btn_size` enum('small','medium','large','xlarge') NOT NULL DEFAULT 'medium',
  `new_button_display` varchar(250) NOT NULL,
  `new_button_bg_color` varchar(10) NOT NULL DEFAULT '#0084FF',
  `new_button_bg_color_hover` varchar(10) NOT NULL DEFAULT '#367FA9',
  `new_button_color` varchar(10) NOT NULL DEFAULT '#FFFFFF',
  `new_button_color_hover` varchar(50) NOT NULL DEFAULT '#FFFDDD',
  `label_ids` varchar(250) NOT NULL COMMENT 'comma seperated,messenger_bot_broadcast_contact_group.id',
  `reference` varchar(250) NOT NULL,
  `template_id` int(11) NOT NULL COMMENT 'messenger_bot_postback.id',
  `created_at` datetime NOT NULL,
  `visual_flow_campaign_id` int(11) NOT NULL DEFAULT 0,
  `visual_flow_type` enum('flow','general') NOT NULL DEFAULT 'general',
  PRIMARY KEY (`id`),
  UNIQUE KEY `link_code` (`link_code`),
  KEY `user_id` (`user_id`,`page_id`),
  KEY `reference` (`reference`),
  KEY `visual_flow_campaign_id` (`visual_flow_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_engagement_send_to_msg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_code` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  `page_id` int(11) NOT NULL COMMENT 'auto id',
  `domain_name` varchar(255) NOT NULL,
  `btn_size` enum('standard','large','xlarge') NOT NULL DEFAULT 'standard',
  `skin` enum('blue','white') NOT NULL DEFAULT 'blue',
  `reference` varchar(250) NOT NULL,
  `button_click_success_message` tinytext NOT NULL,
  `label_ids` varchar(250) NOT NULL COMMENT 'comma seperated,messenger_bot_broadcast_contact_group.id',
  `cta_text_option` varchar(250) NOT NULL,
  `template_id` int(11) NOT NULL COMMENT 'messenger_bot_postback.id',
  `language` varchar(200) NOT NULL DEFAULT 'en_US',
  `created_at` datetime NOT NULL,
  `redirect` enum('0','1') NOT NULL DEFAULT '0',
  `add_button_with_message` enum('0','1') NOT NULL DEFAULT '0',
  `button_with_message_content` tinytext NOT NULL COMMENT 'json',
  `success_redirect_url` tinytext NOT NULL,
  `visual_flow_campaign_id` int(11) NOT NULL DEFAULT 0,
  `visual_flow_type` enum('flow','general') NOT NULL DEFAULT 'general',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_code` (`domain_code`),
  KEY `user_id` (`user_id`,`page_id`),
  KEY `reference` (`reference`),
  KEY `visual_flow_campaign_id` (`visual_flow_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_thirdparty_webhook` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_id` varchar(50) NOT NULL COMMENT 'facebook page id',
  `page_name` varchar(255) NOT NULL,
  `visual_flow_campaign_id` int(11) NOT NULL,
  `added_date` datetime NOT NULL,
  `webhook_url` text NOT NULL,
  `variable_post` text NOT NULL,
  `last_trigger_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `page_id` (`page_id`),
  KEY `visual_flow_campaign_id` (`visual_flow_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_thirdparty_webhook_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) NOT NULL,
  `http_code` varchar(10) NOT NULL,
  `curl_error` text NOT NULL,
  `post_data` longtext NOT NULL,
  `post_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `webhook_id` (`webhook_id`),
  KEY `post_time` (`post_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `messenger_bot_thirdparty_webhook_trigger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) NOT NULL,
  `trigger_option` varchar(255) NOT NULL COMMENT 'e.g. trigger_postback_xxx, trigger_keyword_xxx, trigger_userinput_xxx, trigger_webview_xxx',
  PRIMARY KEY (`id`),
  KEY `webhook_id` (`webhook_id`),
  KEY `trigger_option` (`trigger_option`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `sales_automation_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `followup_enabled` enum('0','1') NOT NULL DEFAULT '0',
  `followup_delay_minutes` int(11) NOT NULL DEFAULT 180,
  `followup_message_ar` text DEFAULT NULL,
  `followup_message_en` text DEFAULT NULL,
  `digest_enabled` enum('0','1') NOT NULL DEFAULT '0',
  `digest_email` varchar(255) NOT NULL DEFAULT '',
  `digest_whatsapp` varchar(32) NOT NULL DEFAULT '',
  `digest_hour` int(11) NOT NULL DEFAULT 8,
  `deflect_alert_enabled` enum('0','1') NOT NULL DEFAULT '0',
  `deflect_alert_threshold` int(11) NOT NULL DEFAULT 3,
  `deflect_alert_email` varchar(190) DEFAULT NULL,
  `deflect_alert_whatsapp` varchar(30) DEFAULT NULL,
  `last_digest_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tiktok_accounts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `open_id` varchar(255) NOT NULL,
  `union_id` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `profile_picture` varchar(500) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_open_id` (`user_id`,`open_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tiktok_campaigns` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `account_id` int(11) unsigned NOT NULL,
  `campaign_type` enum('comment','dm') NOT NULL DEFAULT 'comment',
  `reply_type` enum('text','ai') NOT NULL DEFAULT 'text',
  `auto_reply_text` text DEFAULT NULL,
  `ai_training_data` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `account_status` (`account_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tiktok_reply_reports` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `campaign_id` int(11) unsigned NOT NULL,
  `account_id` int(11) unsigned NOT NULL,
  `comment_id` varchar(128) DEFAULT NULL,
  `trigger_text` text DEFAULT NULL,
  `reply_text` text DEFAULT NULL,
  `api_response` text DEFAULT NULL,
  `status` enum('success','failed') NOT NULL DEFAULT 'failed',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `comment_id` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `vidcaster_facebook_rx_live_scheduler` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `facebook_rx_fb_user_info_id` int(11) NOT NULL,
  `scheduler_name` varchar(200) NOT NULL,
  `page_group_user_id` int(11) NOT NULL,
  `page_or_group_or_user` enum('page','group','user') NOT NULL,
  `page_or_group_or_user_name` varchar(200) NOT NULL,
  `message` mediumtext NOT NULL,
  `image_url` mediumtext NOT NULL,
  `use_system_video` enum('yes','no') NOT NULL DEFAULT 'yes',
  `share_or_cross` varchar(20) NOT NULL,
  `crosspost_enable_disable` enum('0','1') NOT NULL DEFAULT '0',
  `crosspost_this_post_by_pages` mediumtext NOT NULL,
  `auto_share_post` enum('0','1') NOT NULL DEFAULT '0',
  `auto_share_this_post_by_pages` mediumtext NOT NULL,
  `auto_share_to_profile` enum('0','1') NOT NULL DEFAULT '0',
  `auto_like_post` enum('0','1') NOT NULL DEFAULT '0',
  `auto_private_reply` enum('0','1') NOT NULL DEFAULT '0',
  `auto_private_reply_text` mediumtext NOT NULL,
  `auto_private_reply_status` enum('0','1') NOT NULL DEFAULT '0' COMMENT 'taken by cronjob or not',
  `auto_private_reply_count` int(11) NOT NULL,
  `auto_private_reply_done_ids` mediumtext NOT NULL,
  `auto_comment` enum('0','1') NOT NULL DEFAULT '0',
  `auto_comment_text` varchar(200) NOT NULL,
  `posting_status` enum('0','1','2') NOT NULL DEFAULT '2',
  `post_id` varchar(200) NOT NULL COMMENT 'fb post id',
  `post_url` mediumtext NOT NULL,
  `stream_url` mediumtext NOT NULL,
  `scheduled_video_url` mediumtext NOT NULL,
  `secure_stream_url` mediumtext NOT NULL,
  `create_event` enum('0','1') NOT NULL DEFAULT '1',
  `schedule_type` enum('now','later') NOT NULL DEFAULT 'later',
  `schedule_time` datetime NOT NULL,
  `time_zone` varchar(200) NOT NULL,
  `last_updated_at` datetime NOT NULL,
  `is_live` enum('0','1') NOT NULL DEFAULT '0',
  `stream_started` datetime NOT NULL,
  `stream_completed` datetime NOT NULL,
  `video_width` int(11) NOT NULL DEFAULT 1280,
  `video_height` int(11) NOT NULL DEFAULT 720,
  `ffmpeg_log` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`facebook_rx_fb_user_info_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
