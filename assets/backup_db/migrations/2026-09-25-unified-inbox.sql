-- Phase 9.5 / brief §13, §34, §46: Unified Inbox — the primary read+reply surface over the
-- existing conversation core (conversations, customers, customer_identities) and
-- livechat_messages. This is a NEW screen only: it adds internal_notes (private team notes,
-- distinct from customer-visible messages), one composite index the list query needs, the
-- module/package/menu entitlement rows, and nothing else. No message or conversation table
-- is altered in a way that changes existing readers.
--
-- Idempotent: every statement is CREATE/ADD ... IF NOT EXISTS, INSERT IGNORE, or a guarded
-- UPDATE, so a re-run (or a retry after a partly failed run) changes nothing further.
-- No semicolons in comments: the migration runner splits statements on a semicolon at end of line.
DO 0;

CREATE TABLE IF NOT EXISTS `internal_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL COMMENT 'tenant',
  `author_id` int NOT NULL COMMENT 'team_members.id (or users.id when the account owner writes it)',
  `author_name` varchar(150) DEFAULT NULL COMMENT 'display snapshot, author may be removed later',
  `note` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notes_conv` (`conversation_id`, `id`),
  KEY `idx_notes_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- List query filters by assignment as well as by state (brief §13: "assignment", "team
-- ownership"). idx_conv_user_last and idx_conv_user_state already exist from the
-- conversation-core migration; this adds the assignment path.
ALTER TABLE `conversations` ADD INDEX IF NOT EXISTS `idx_conv_user_assigned` (`user_id`, `assigned_user_id`);

INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (411, 'Unified Inbox', 0, '', '0', '0', '0');

-- Owner decision 2026-09-22 (carried forward from 408/409/410): every existing package
-- includes all Creative Monkey modules. Guarded by FIND_IN_SET so a re-run cannot append twice.
UPDATE `package` SET `module_ids` = CONCAT_WS(',', NULLIF(`module_ids`, ''), '411')
  WHERE `deleted` = '0' AND FIND_IN_SET('411', `module_ids`) = 0;

-- Primary surface (brief §34 nav redesign puts Inbox first) — replace any earlier row.
DELETE FROM `menu` WHERE `url` = 'inbox';
INSERT INTO `menu` (`name`,`icon`,`color`,`url`,`serial`,`module_access`,`have_child`,`only_admin`,`only_member`,`add_ons_id`,`is_external`,`header_text`,`is_menu_manager`,`custom_page_id`)
  VALUES ('Inbox','fas fa-inbox','#2f8fd6','inbox',0,'411',0,0,0,0,0,'',0,0);
