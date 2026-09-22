-- Phase 11 (brief §31, §32, §40, §51) — scalable client provisioning from the Agency
-- Control Center. Creative Monkey can already VIEW every client there (Agency.php); this
-- closes the gap of CREATING one without leaving the admin panel or touching the DB by hand.
--
-- No new tables for the client account itself — a provisioned client is a normal `users` row
-- (user_type='Member') and, when a starter template was picked, a normal `onboarding_state`
-- row, both written by application/helpers/provisioning_helper.php through the SAME tables
-- Agency.php already reads and Onboarding.php already owns.
--
-- The one real gap: nothing recorded WHICH admin created an account, or when, for accounts
-- created outside the public signup form. `provisioned_by` / `provisioned_at` are NULL for
-- every existing row (public signup, legacy imports) and are set only by
-- provisioning_create_client(). This is the audit trail brief §40 asks for; see also the
-- log_message('audit: ...') line in Agency_provision::provision_client().
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: ADD COLUMN IF NOT EXISTS / ADD KEY IF NOT EXISTS — a re-run changes nothing.
DO 0;

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `provisioned_by` INT(11) DEFAULT NULL
  COMMENT 'admin users.id who created this account from the Agency Control Center; NULL for public signup / legacy rows';
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `provisioned_at` DATETIME DEFAULT NULL;
ALTER TABLE `users` ADD KEY IF NOT EXISTS `idx_provisioned_by` (`provisioned_by`);
