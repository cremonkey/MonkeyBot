-- Phase 9.5 - unified conversation core, customer identity foundation, handoff state machine.
-- ADR-001 (Accepted). Runbook: docs/database/conversation-core.md
--
-- EXPAND step only. Additive and idempotent (IF NOT EXISTS everywhere). Nothing reads these
-- tables yet, so rollback is DROP TABLE plus DROP COLUMN (see the runbook).
-- livechat_messages is NOT copied. It stays the message store and gains a nullable
-- conversation_id that the link trigger (next migration) and the backfill fill in.
--
-- No semicolons in comments: the migration runner splits statements on a semicolon at end of line.

CREATE TABLE IF NOT EXISTS `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'tenant',
  `display_name` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `lead_score` int NOT NULL DEFAULT 0,
  `source` enum('subscriber','thread','manual') NOT NULL DEFAULT 'thread' COMMENT 'subscriber = backfilled from messenger_bot_subscriber, thread = first seen as a message thread',
  `legacy_subscriber_id` int DEFAULT NULL COMMENT 'messenger_bot_subscriber.id this customer was created or enriched from',
  `merged_into_customer_id` bigint unsigned DEFAULT NULL COMMENT 'set when a merge retires this row, see customer_merge_log',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_legacy_subscriber` (`legacy_subscriber_id`),
  KEY `idx_customers_user` (`user_id`, `id`),
  KEY `idx_customers_user_phone` (`user_id`, `phone`),
  KEY `idx_customers_user_email` (`user_id`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_identities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT 'tenant, always equal to customers.user_id',
  `platform` varchar(16) NOT NULL COMMENT 'fb ig wa tg web (livechat_messages.platform)',
  `external_id` varchar(191) NOT NULL COMMENT 'PSID, IGSID, phone, chat id or web session key',
  `channel_account_id` bigint NOT NULL DEFAULT 0 COMMENT 'page_table_id or channel account id, 0 for web',
  `verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'reserved for verified phone or email identities',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_identity` (`user_id`, `platform`, `channel_account_id`, `external_id`),
  KEY `idx_identity_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_merge_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `source_customer_id` bigint unsigned NOT NULL COMMENT 'retired customer',
  `target_customer_id` bigint unsigned NOT NULL COMMENT 'surviving customer',
  `method` enum('verified_phone','verified_email','manual','deterministic') NOT NULL,
  `actor_type` enum('system','ai','agent','customer') NOT NULL DEFAULT 'agent',
  `actor_id` int DEFAULT NULL,
  `identities_moved` text DEFAULT NULL COMMENT 'JSON list of customer_identities.id',
  `note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_merge_user` (`user_id`, `created_at`),
  KEY `idx_merge_source` (`source_customer_id`),
  KEY `idx_merge_target` (`target_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'tenant',
  `customer_id` bigint unsigned DEFAULT NULL,
  `platform` varchar(16) NOT NULL COMMENT 'fb ig wa tg web (livechat_messages.platform)',
  `channel_account_id` bigint NOT NULL DEFAULT 0 COMMENT 'page_table_id or channel account id, 0 for web',
  `external_thread_id` varchar(191) NOT NULL COMMENT 'livechat_messages.subscriber_id',
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `handoff_state` enum('AI_ACTIVE','WAITING_FOR_HUMAN','HUMAN_ACTIVE','AI_ASSIST','RESOLVED') NOT NULL DEFAULT 'AI_ACTIVE',
  `assigned_user_id` int DEFAULT NULL,
  `last_message_at` datetime DEFAULT NULL,
  `last_inbound_at` datetime DEFAULT NULL,
  `unread_count` int unsigned NOT NULL DEFAULT 0 COMMENT 'customer messages since the last bot or agent message',
  `message_count` int unsigned NOT NULL DEFAULT 0 COMMENT 'cache of linked livechat_messages rows, recomputed by the backfill',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conversation_thread` (`user_id`, `platform`, `channel_account_id`, `external_thread_id`),
  KEY `idx_conv_user_last` (`user_id`, `last_message_at`),
  KEY `idx_conv_user_state` (`user_id`, `handoff_state`),
  KEY `idx_conv_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT 'tenant',
  `event_type` varchar(32) NOT NULL COMMENT 'state_change, backfill_state',
  `from_state` varchar(24) DEFAULT NULL,
  `to_state` varchar(24) DEFAULT NULL,
  `actor_type` enum('system','ai','agent','customer') NOT NULL,
  `actor_id` int DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_conv_events_conv` (`conversation_id`, `id`),
  KEY `idx_conv_events_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nullable column at the end: MariaDB 10.11 adds it instantly, without a table rebuild.
ALTER TABLE `livechat_messages` ADD COLUMN IF NOT EXISTS `conversation_id` bigint unsigned NULL DEFAULT NULL;

ALTER TABLE `livechat_messages` ADD INDEX IF NOT EXISTS `idx_lm_conversation` (`conversation_id`, `id`);
