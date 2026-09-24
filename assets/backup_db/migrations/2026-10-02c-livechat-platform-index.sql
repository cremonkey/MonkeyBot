-- Task A5 review fix: Agency::index() (via agency_health_last_inbound_by_platform() in
-- agency_health_helper.php) runs, on every Agency Control Center page load:
--   SELECT user_id, platform, MAX(conversation_time) FROM livechat_messages
--   WHERE sender='user' GROUP BY user_id, platform
-- Neither existing composite index covers it: `user_sender_time` (user_id, sender,
-- conversation_time) leads with user_id so it cannot be used for a query with no user_id
-- predicate, and `user_sub_platform` (user_id, subscriber_id, platform, sender) has the
-- same problem plus the wrong trailing columns. Without a matching index this is a full
-- scan of livechat_messages, which (per the schema comments elsewhere in this repo and
-- the hot-indexes migration it already needed one pass for) is one of the largest tables
-- in the app — this ALTER should be run in a quiet hour on production, not during
-- business traffic.
--
-- Column order (sender, user_id, platform, conversation_time): `sender` first because it
-- is the only predicate (WHERE sender='user'), then `user_id, platform` in the exact
-- order the controller's GROUP BY uses, then `conversation_time` last. That ordering lets
-- MariaDB use a loose index scan ("Using index for group-by") to jump straight to the max
-- conversation_time within each (user_id, platform) group, entirely from the index —
-- it never touches the row data.
--
-- The code already works before this runs (agency_health_table_ready() only checks
-- table_exists()/field_exists(), not the index), it just scans the table until this lands.
--
-- Idempotent (IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `livechat_messages`
  ADD INDEX IF NOT EXISTS `idx_lm_sender_user_platform_time` (`sender`, `user_id`, `platform`, `conversation_time`);
