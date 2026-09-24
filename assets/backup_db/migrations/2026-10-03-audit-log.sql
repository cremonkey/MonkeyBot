-- Phase 10.5 (brief 40) — General audit log.
--
--   audit_log   one row per audited action: who (actor) did what (action) to which
--               entity, for which tenant (user_id), with before/after JSON snapshots.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: CREATE TABLE IF NOT EXISTS.
DO 0;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `actor_id` INT NOT NULL DEFAULT 0,
  `actor_type` ENUM('user','team_member','admin','system','ai') NOT NULL DEFAULT 'system',
  `action` VARCHAR(64) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL DEFAULT '',
  `entity_id` VARCHAR(64) NOT NULL DEFAULT '',
  `before_json` MEDIUMTEXT NULL,
  `after_json` MEDIUMTEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
