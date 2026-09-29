-- Task 29 (C14, brief §16) — customer merge + verified identity linking. `customers.
-- merged_into_customer_id` and `customer_merge_log` already exist (2026-09-23-conversation-
-- core.sql) but nothing has ever written to customer_merge_log. This migration only adds what
-- was missing to make a merge reversible and cheap to query:
--   * customer_merge_log.conversations_moved — identities_moved already existed, but
--     conversations were never recorded, so an unmerge could not know which conversation rows
--     to move back.
--   * customer_merge_log.unmerged_at — idempotency guard: customer_unmerge() refuses to run
--     twice on the same log row.
--   * an index on customers.merged_into_customer_id — the merge-suggestions query and every
--     tenant customer list now filter "not already merged" (IS NULL) on every request.
--
-- Code works before this migration runs: application/helpers/customer_helper.php's
-- customer_merge()/customer_unmerge() check field_exists('conversations_moved',
-- 'customer_merge_log') and field_exists('unmerged_at', 'customer_merge_log') (cached static)
-- and refuse to run (returning an error, writing nothing) until both columns exist, exactly
-- like Inbox.php's table_exists()/field_exists() guards for labels.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS, MariaDB 10.11). No
-- semicolons in comments: the migration runner splits statements on a semicolon at end of line.
DO 0;

ALTER TABLE `customer_merge_log`
  ADD COLUMN IF NOT EXISTS `conversations_moved` text DEFAULT NULL
    COMMENT 'JSON list of conversations.id moved from source to target, for customer_unmerge()' AFTER `identities_moved`,
  ADD COLUMN IF NOT EXISTS `unmerged_at` datetime DEFAULT NULL
    COMMENT 'set once customer_unmerge() reverses this row; a second unmerge of the same log id is refused';

ALTER TABLE `customers`
  ADD INDEX IF NOT EXISTS `idx_customers_merged_into` (`user_id`, `merged_into_customer_id`);
