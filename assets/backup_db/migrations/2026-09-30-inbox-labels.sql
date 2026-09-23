-- Unified Inbox follow-up: channel-agnostic conversation labels (docs/product/unified-inbox.md).
--
-- The only older label table, messenger_bot_subscribers_label, is keyed to
-- messenger_bot_subscriber.id, a row that never exists for wa (Cloud or OpenWA), tg or web
-- customers (ADR-001). These two tables key labels to the tenant and to conversations.id
-- instead, so one label works on every channel: fb, ig, wa (Cloud and OpenWA), tg and web.
--
--  inbox_labels         one row per label, per tenant. The name is unique within a tenant.
--  conversation_labels  which label is on which conversation. user_id is repeated on every row
--                       so the list filter and every attach and detach stay tenant-scoped
--                       without a join (the same rule as internal_notes).
--
-- No foreign keys, matching internal_notes and the rest of the conversation core. Deleting a
-- label deletes its conversation_labels rows in the same transaction (Inbox::label_delete).
-- Nothing existing is altered.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only, so a re-run changes nothing.
-- No semicolons in comments: the migration runner splits statements on a semicolon at end of line.
DO 0;

CREATE TABLE IF NOT EXISTS `inbox_labels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'tenant',
  `name` varchar(60) NOT NULL,
  `color` varchar(7) DEFAULT NULL COMMENT '#rrggbb, NULL = default badge colour',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inbox_labels_user_name` (`user_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_labels` (
  `conversation_id` bigint unsigned NOT NULL,
  `label_id` int unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT 'tenant, always equal to conversations.user_id and inbox_labels.user_id',
  `created_by` int DEFAULT NULL COMMENT 'acting user or team member id',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`conversation_id`, `label_id`),
  KEY `idx_convlabels_filter` (`user_id`, `label_id`, `conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
