-- Phase 10.5 (brief §25) — Sequences: a named sequence of timed steps per enrolled
-- subscriber, unifying today's scattered Sales_automation follow-ups, Reengage campaigns
-- and Visual Flow Builder drip into one product concept, built ON TOP OF the Event/
-- Automation Engine (2026-09-24-event-automation.sql) and Channel Policy Engine.
--
--   sequences            owner-authored: name, what starts it, its steps
--   sequence_steps        ordered steps: T+<delay_seconds> from enrollment, do <action_type>
--   sequence_enrollments   one subscriber's progress through one sequence
--   sequence_step_log      one row per (enrollment, step) attempt — brief §51, no silent failure
--
-- delay_seconds on a step is an ABSOLUTE offset from enrollment (matches the brief's own
-- "T +10 minutes / T +6 hours / T +1 day / T +3 days" example, which reads as offsets from
-- the moment the sequence started, not compounding gaps between steps). See
-- docs/automations/sequences.md §"Timing model".
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE / guarded UPDATE + DELETE-then-INSERT.
DO 0;

CREATE TABLE IF NOT EXISTS `sequences` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `trigger_event` VARCHAR(64) NOT NULL DEFAULT 'manual' COMMENT 'an event_catalogue() key, or literal "manual" for owner/automation-only enrollment',
  `enroll_conditions_json` MEDIUMTEXT NULL COMMENT 'Automation_engine condition-row format, evaluated against the triggering event before auto-enrolling',
  `status` ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_trigger` (`trigger_event`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sequence_steps` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sequence_id` INT NOT NULL,
  `position` INT NOT NULL DEFAULT 0 COMMENT '0-based order; also the offset into delay_seconds from enrollment',
  `delay_seconds` INT NOT NULL DEFAULT 0 COMMENT 'ABSOLUTE offset from sequence_enrollments.enrolled_at (see file header)',
  `action_type` VARCHAR(48) NOT NULL COMMENT 'Automation_engine::action_catalogue() vocabulary (send_message, add_label, update_lead_score, create_crm_deal, notify_team, start_sequence, ...) plus create_task',
  `action_params_json` MEDIUMTEXT NULL,
  `stop_condition_json` MEDIUMTEXT NULL COMMENT 'Automation_engine condition-row format; TRUE halts the whole enrollment before this step runs',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sequence_position` (`sequence_id`, `position`),
  KEY `idx_sequence` (`sequence_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sequence_enrollments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `sequence_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL COMMENT 'customers.id (Phase 9.5) when known; not all subscribers are backfilled yet',
  `subscriber_id` INT NOT NULL DEFAULT 0 COMMENT 'messenger_bot_subscriber.id — same identity Automation_gateway::context() resolves',
  `platform` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'messenger_bot_subscriber.social_media (fb ig wa tg web)',
  `subscribe_id` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'external id (PSID/IGSID/phone/chat id), what Automation_gateway::send_text() sends to',
  `channel_account_id` INT NOT NULL DEFAULT 0 COMMENT 'page_table_id',
  `current_step` INT NOT NULL DEFAULT 0 COMMENT 'position of the NEXT step to run',
  `status` ENUM('active','completed','stopped','failed') NOT NULL DEFAULT 'active',
  `enrolled_at` DATETIME NOT NULL,
  `next_run_at` DATETIME NULL COMMENT 'when the worker should next look at this enrollment; NULL once completed/stopped',
  `stopped_reason` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sequence_subscriber` (`sequence_id`, `subscriber_id`, `platform`),
  KEY `idx_next_run` (`status`, `next_run_at`),
  KEY `idx_user` (`user_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sequence_step_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `enrollment_id` BIGINT NOT NULL,
  `step_id` INT NOT NULL,
  `status` ENUM('success','skipped','skipped_policy','skipped_stop_condition','failed') NOT NULL DEFAULT 'failed',
  `detail` TEXT NULL,
  `run_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enrollment_step` (`enrollment_id`, `step_id`) COMMENT 'the claim: a step only ever runs once per enrollment, replay-safe like automation_runs',
  KEY `idx_enrollment` (`enrollment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 412 continues the Creative Monkey block (400-411, see 2026-09-22-custom-module-
-- entitlements.sql, 2026-09-23-pricing-admin.sql, 2026-09-24-event-automation.sql,
-- 2026-09-24-onboarding.sql, 2026-09-25-unified-inbox.sql). The id map lives in
-- application/helpers/entitlement_helper.php ('sequences' => 412) and MUST match.
INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (412, 'Sequences', 0, '', '0', '0', '0');

-- Owner decision (same as every prior Creative Monkey module): every existing package
-- includes it.
UPDATE package SET module_ids = CONCAT_WS(',', NULLIF(module_ids, ''), '412')
  WHERE deleted = '0' AND FIND_IN_SET('412', module_ids) = 0;

DELETE FROM menu WHERE url = 'sequences';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Sequences','fas fa-stream','#fc544b','sequences',10,'412',0,0,0,0,0,'',0,0);
