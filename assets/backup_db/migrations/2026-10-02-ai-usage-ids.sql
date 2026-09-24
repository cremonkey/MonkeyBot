-- AI cost log gets agent_id and conversation_id (remaining-work plan, task A3).
--
-- Adds two nullable columns to ai_usage_log so a row can be attributed to the AI agent
-- profile that produced the reply and to the conversation it belongs to, plus an index to
-- let the analytics hub sum cost per conversation. Both ids are filled only when they can
-- be resolved (an assigned agent profile, a conversation matched by
-- conversation_ai_history_link()-style lookup); every other row keeps them NULL, exactly
-- like today.
--
-- Before this runs, ai_usage_record() checks field_exists() (cached) and writes the old
-- row shape unchanged.
--
-- Idempotent (IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `ai_usage_log`
  ADD COLUMN IF NOT EXISTS `agent_id` int(10) unsigned NULL DEFAULT NULL
    COMMENT 'ai_agent_profiles.id that produced this call, NULL when no agent profile is assigned',
  ADD COLUMN IF NOT EXISTS `conversation_id` bigint(20) unsigned NULL DEFAULT NULL
    COMMENT 'conversations.id this call belongs to, NULL when it could not be resolved';

ALTER TABLE `ai_usage_log`
  ADD INDEX IF NOT EXISTS `idx_user_conversation` (`user_id`, `conversation_id`);
