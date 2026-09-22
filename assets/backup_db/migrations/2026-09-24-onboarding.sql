-- Phase 11 / brief §32 + §33: Client Onboarding Wizard and Industry Templates.
--
-- Idempotent: every statement is CREATE/ADD ... IF NOT EXISTS, INSERT IGNORE, or a guarded
-- UPDATE, so a re-run (or a retry after a partly failed run) changes nothing further.
--
-- onboarding_state   one row per tenant: where the owner is in the 9 steps, which template
--                    they picked, and the per-step answers (data_json). No tenant business
--                    data lives here - the wizard writes that into the FEATURE tables
--                    (ai_agent_profiles, ai_faq, pricing_config, crm_*, hours_* columns).
-- ai_faq.source_key  lets the wizard re-apply a template's starter FAQ without duplicating:
--                    rows are keyed 'tpl:<template>:<faq key>' per user. NULL for every FAQ
--                    a human added by hand, so the unique index never blocks them
--                    (MySQL/MariaDB unique indexes ignore NULL duplicates).
DO 0;

CREATE TABLE IF NOT EXISTS `onboarding_state` (
  `user_id` INT(11) NOT NULL,
  `current_step` TINYINT(4) NOT NULL DEFAULT 1,
  `template_key` VARCHAR(40) DEFAULT NULL,
  `data_json` MEDIUMTEXT DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_completed` (`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ai_faq` ADD COLUMN IF NOT EXISTS `source_key` VARCHAR(64) DEFAULT NULL;
ALTER TABLE `ai_faq` ADD UNIQUE INDEX IF NOT EXISTS `uniq_user_source` (`user_id`, `source_key`);

INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (410, 'Setup Guide', 0, '', '0', '0', '0');

-- Owner decision 2026-09-22 (carried forward): every existing package includes all
-- Creative Monkey modules. Guarded by FIND_IN_SET so a re-run cannot append a second 410.
UPDATE `package` SET `module_ids` = CONCAT_WS(',', NULLIF(`module_ids`, ''), '410')
  WHERE `deleted` = '0' AND FIND_IN_SET('410', `module_ids`) = 0;

DELETE FROM `menu` WHERE `url` = 'onboarding';
INSERT INTO `menu` (`name`,`icon`,`color`,`url`,`serial`,`module_access`,`have_child`,`only_admin`,`only_member`,`add_ons_id`,`is_external`,`header_text`,`is_menu_manager`,`custom_page_id`)
  VALUES ('Setup Guide','fas fa-rocket','#6777ef','onboarding',1,'410',0,0,0,0,0,'',0,0);
