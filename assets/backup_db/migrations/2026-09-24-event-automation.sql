-- Phase 10.5 (brief 22, 23, 40, 51) — Event Engine + Trigger/Conditions/Actions Automation Engine.
--
--   events               append-only fact log, one row per business event, per tenant
--   automation_rules     owner-authored rules: trigger event -> conditions -> actions
--   automation_runs      one row per (rule, event); UNIQUE makes replay idempotent
--   automation_action_log one row per action attempt inside a run (brief 51: no silent failure)
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE / guarded UPDATE + DELETE-then-INSERT.
-- The runner strips '--' lines, so this header ends in a no-op statement.
DO 0;

CREATE TABLE IF NOT EXISTS `events` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `subject_type` VARCHAR(32) NOT NULL DEFAULT '',
  `subject_id` VARCHAR(64) NOT NULL DEFAULT '',
  `payload` LONGTEXT NULL,
  `correlation_id` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  `processed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_type_time` (`user_id`, `event_type`, `created_at`),
  KEY `idx_processed` (`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `trigger_event` VARCHAR(64) NOT NULL,
  `conditions_json` MEDIUMTEXT NULL,
  `actions_json` MEDIUMTEXT NULL,
  `status` ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_trigger` (`trigger_event`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `automation_runs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `rule_id` INT NOT NULL,
  `event_id` BIGINT NOT NULL,
  `status` ENUM('success','failed','skipped') NOT NULL DEFAULT 'skipped',
  `detail` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rule_event` (`rule_id`, `event_id`),
  KEY `idx_rule_time` (`rule_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `automation_action_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT NOT NULL,
  `action_index` INT NOT NULL DEFAULT 0,
  `action_type` VARCHAR(48) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `error` TEXT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`run_id`),
  KEY `idx_type_status` (`action_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 409 continues the Creative Monkey block (400-408, see 2026-09-22-custom-module-entitlements.sql).
-- The id map lives in application/helpers/entitlement_helper.php ('automations' => 409) and MUST match.
INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (409, 'Automations', 0, '', '0', '0', '0');

-- Owner decision 2026-09-22: every existing package includes all Creative Monkey modules.
UPDATE package SET module_ids = CONCAT_WS(',', NULLIF(module_ids, ''), '409')
  WHERE deleted = '0' AND FIND_IN_SET('409', module_ids) = 0;

DELETE FROM menu WHERE url = 'automations';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Automations','fas fa-bolt','#6777ef','automations',6,'409',0,0,0,0,0,'',0,0);
