-- Conversational CRM upgrades (remaining-work Task 18, brief §27): link a crm_deals row back
-- to the conversation/customer it came from, and let the AI/automation side stamp an intent
-- and a next-action reminder on the deal.
--
-- crm_deals gains five nullable columns:
--   customer_id      INT       -> customers.id (conversation-core, migration 2026-09-23)
--   conversation_id  INT       -> conversations.id (same migration)
--   intent           VARCHAR(40)   free-form intent label captured for the deal
--   next_action      VARCHAR(255)  short human-readable next step
--   next_action_at   DATETIME      when that next step is due
-- All NULL for every row written before this migration and for any row a client inserts
-- before the columns exist (Crm.php / crm_helper.php guard every read/write of them with
-- $this->db->field_exists('customer_id', 'crm_deals'), cached statically, exactly like
-- Inbox.php's media_column_ready()). No existing pipeline, stage or deal is touched.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS, MariaDB 10.11). No semicolons
-- in comments: the runner splits on them.
DO 0;

ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `customer_id` INT NULL DEFAULT NULL
    COMMENT 'customers.id, filled on create where resolvable via the linked conversation; NULL otherwise';

ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `conversation_id` INT NULL DEFAULT NULL
    COMMENT 'conversations.id, filled on create where resolvable; NULL otherwise';

ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `intent` VARCHAR(40) NULL DEFAULT NULL
    COMMENT 'free-form intent label, e.g. "buy", "support", "price_question"';

ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `next_action` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'short human-readable next step for this deal';

ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `next_action_at` DATETIME NULL DEFAULT NULL
    COMMENT 'when the next_action is due';

ALTER TABLE `crm_deals`
  ADD INDEX IF NOT EXISTS `idx_customer_id` (`customer_id`);

ALTER TABLE `crm_deals`
  ADD INDEX IF NOT EXISTS `idx_conversation_id` (`conversation_id`);
