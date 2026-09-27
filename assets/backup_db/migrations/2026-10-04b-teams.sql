-- Task 17 (C2, brief §13) — Teams + team inbox. Groups of the existing team_members
-- (2026-09-25-unified-inbox.sql) into named teams, so a conversation can be assigned to a
-- whole team in addition to the existing per-agent assignment
-- (conversations.assigned_user_id -> team_members.id, unchanged). Adds:
--   teams              (id, user_id, name, created_at)            — tenant-scoped like team_members
--   team_memberships   (id, team_id, team_member_id)               — team_member_id -> team_members.id
--   conversations.assigned_team_id  nullable, indexed with user_id (mirrors idx_conv_user_assigned)
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS —
-- a re-run changes nothing. No DROP, no destructive MODIFY, no data deletes.
DO 0;

CREATE TABLE IF NOT EXISTS `teams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'tenant (users.id); same scoping as team_members.user_id',
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teams_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_memberships` (
  `id` int NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL COMMENT 'teams.id',
  `team_member_id` int NOT NULL COMMENT 'team_members.id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_memberships_pair` (`team_id`, `team_member_id`),
  KEY `idx_team_memberships_member` (`team_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `conversations` ADD COLUMN IF NOT EXISTS `assigned_team_id` INT(11) DEFAULT NULL
  COMMENT 'teams.id; independent of assigned_user_id (an individual agent assignment can coexist with a team assignment)';
ALTER TABLE `conversations` ADD INDEX IF NOT EXISTS `idx_conv_user_assigned_team` (`user_id`, `assigned_team_id`);
