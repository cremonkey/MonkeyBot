-- Unified Inbox follow-up: key AI state to conversations (ADR-001 "AI state ... is not keyed to
-- any conversation", docs/product/unified-inbox.md).
--
-- Adds a nullable ai_conversation_history.conversation_id plus an index for "latest AI turn of
-- this conversation". Home::get_ai_reply_open_ai() fills it on NEW rows when the conversation
-- can be resolved (conversation_ai_history_link() in conversation_helper.php) and leaves it NULL
-- otherwise. Existing rows are not backfilled: this table self-prunes at the AI memory TTL and
-- Cron_hub keeps 60 days at most, so it turns over on its own, and the inbox only shows the
-- latest turn.
--
-- Every existing reader keeps its (user_id, page_id, subscribe_id, social_media) lookup, which is
-- unchanged. The nullable column is added at the end, instantly, with no rebuild on MariaDB 10.11.
-- The index is built online (the table is small because of the pruning above).
-- Idempotent (IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `ai_conversation_history`
  ADD COLUMN IF NOT EXISTS `conversation_id` bigint unsigned NULL DEFAULT NULL
    COMMENT 'conversations.id when resolvable at write time (ADR-001), else NULL';

ALTER TABLE `ai_conversation_history`
  ADD INDEX IF NOT EXISTS `idx_aich_conversation` (`conversation_id`, `id`);
