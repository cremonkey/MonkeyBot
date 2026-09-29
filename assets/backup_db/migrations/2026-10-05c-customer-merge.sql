-- Task 29 (C14, brief §16) — customer merge + verified identity linking. `customers.
-- merged_into_customer_id` and `customer_merge_log` already exist (2026-09-23-conversation-
-- core.sql) but nothing has ever written to customer_merge_log. This migration adds what was
-- missing to make a merge reversible (every table it can touch) and cheap to query:
--   * customer_merge_log.conversations_moved / deals_moved / enrollments_moved —
--     identities_moved already existed, but conversations, crm_deals and sequence_enrollments
--     rows were never recorded, so an unmerge could not know which of them to move back.
--   * customer_merge_log.unmerged_at — idempotency guard: customer_unmerge() refuses to run
--     twice on the same log row, and (review round 1) is only stamped once every recorded row
--     was actually restored — a partial restore leaves it NULL so a retry is safe.
--   * an index on customers.merged_into_customer_id — customer_scope_list() (every Customer
--     360 list query) now excludes merged-away customers by default, and the merge-suggestions
--     query already excluded them; this is the index both filters use.
--
-- Code works before this migration runs: application/helpers/customer_helper.php's
-- customer_merge()/customer_unmerge() check field_exists('conversations_moved',
-- 'customer_merge_log'), field_exists('deals_moved', ...), field_exists('enrollments_moved',
-- ...) and field_exists('unmerged_at', ...) and refuse to run (returning an error, writing
-- nothing) until all four columns exist, exactly like Inbox.php's table_exists()/
-- field_exists() guards for labels. crm_deals.customer_id (2026-10-04c) and
-- sequence_enrollments.customer_id (2026-09-26) are themselves guarded the same way before
-- customer_merge() ever touches them, so an install that predates either migration simply
-- moves nothing in that table instead of erroring.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS, MariaDB 10.11). No
-- semicolons in comments: the migration runner splits statements on a semicolon at end of line.
DO 0;

ALTER TABLE `customer_merge_log`
  ADD COLUMN IF NOT EXISTS `conversations_moved` text DEFAULT NULL
    COMMENT 'JSON list of conversations.id moved from source to target, for customer_unmerge()' AFTER `identities_moved`,
  ADD COLUMN IF NOT EXISTS `deals_moved` text DEFAULT NULL
    COMMENT 'JSON list of crm_deals.id moved from source to target, for customer_unmerge()' AFTER `conversations_moved`,
  ADD COLUMN IF NOT EXISTS `enrollments_moved` text DEFAULT NULL
    COMMENT 'JSON list of sequence_enrollments.id moved from source to target, for customer_unmerge()' AFTER `deals_moved`,
  ADD COLUMN IF NOT EXISTS `unmerged_at` datetime DEFAULT NULL
    COMMENT 'set once customer_unmerge() has restored every recorded row for this log entry; a partial restore leaves this NULL';

ALTER TABLE `customers`
  ADD INDEX IF NOT EXISTS `idx_customers_merged_into` (`user_id`, `merged_into_customer_id`);
