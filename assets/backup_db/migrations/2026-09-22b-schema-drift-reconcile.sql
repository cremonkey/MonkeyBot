-- Reconcile the migration-built schema with production (diffed on 2026-09-22: production
-- table structures vs a staging DB built from initial_db.sql + every migration).
-- Every statement matches the production definition exactly, so on production it changes
-- nothing; on a fresh install it produces the production schema.
-- Named 2026-09-22b so it sorts after every 2026-09-22-* file (needs the hours columns).
DO 0;

-- (KB tables themselves are created in 2026-07-04-baseline-untracked-tables.sql)


-- Installs where the add-on created the tables before these columns existed
ALTER TABLE `ai_knowledge_sources`
  ADD COLUMN IF NOT EXISTS `page_id` int(11) DEFAULT NULL COMMENT 'Optional: scope source to a specific page' AFTER `user_id`,
  ADD INDEX IF NOT EXISTS `page_id_status` (`page_id`,`status`);

ALTER TABLE `ai_knowledge_chunks`
  ADD COLUMN IF NOT EXISTS `embedding` blob DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `embedding_model` varchar(64) DEFAULT NULL;

-- Price guard verdicts added after SPEC-21 (availability gate, media/link gate)
ALTER TABLE `ai_price_guard_log`
  MODIFY COLUMN `verdict` enum('ungrounded','availability','unknown','media') NOT NULL DEFAULT 'ungrounded';

-- Staff working hours: production uses TIME and varchar(32)
-- (2026-09-22-staff-working-hours.sql reconstructed them as varchar before this diff existed)
ALTER TABLE `facebook_rx_fb_page_info`
  MODIFY COLUMN `hours_start` time NOT NULL DEFAULT '09:00:00',
  MODIFY COLUMN `hours_end` time NOT NULL DEFAULT '22:00:00',
  MODIFY COLUMN `hours_days` varchar(32) NOT NULL DEFAULT '1,2,3,4,5,6,7';

ALTER TABLE `openwa_accounts`
  MODIFY COLUMN `hours_start` time NOT NULL DEFAULT '09:00:00',
  MODIFY COLUMN `hours_end` time NOT NULL DEFAULT '22:00:00',
  MODIFY COLUMN `hours_days` varchar(32) NOT NULL DEFAULT '1,2,3,4,5,6,7';
