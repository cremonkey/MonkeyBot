-- Knowledge base versioning, agent scope, and reply citations (remaining-work Task 19 / C4,
-- brief §19).
--
-- ai_knowledge_sources gains:
--   version      -- bumped by Ai_knowledge_base::update_source_action() whenever the extracted
--                    content actually changed (content_hash differs). reindex_action() re-chunks
--                    without bumping this, since the content itself did not change.
--   content_hash -- sha1() of the last-indexed text. NULL until a source is updated/reindexed
--                    through the new actions; add_source_action() does not set it, so an
--                    unmodified source simply has no hash yet and update_source_action() treats
--                    that the same as "changed" (re-chunks once, then starts tracking).
--   agent_id     -- optional scope to a single ai_agent_profiles.id. NULL (default) means the
--                    source is visible to every agent, which is every source today, so retrieval
--                    is byte-for-byte unchanged until an owner deliberately scopes a source.
--
-- ai_reply_audit gains a nullable kb_sources (comma-separated ai_knowledge_sources.id list used
-- to build the reply's context), written only when the column exists (field_exists guard in
-- Home.php), so the insert keeps working before this migration runs.
--
-- Before this runs: Ai_knowledge_base.php and ai_knowledge_helper.php field_exists()-guard every
-- read/write of these columns and behave exactly as before. Idempotent (IF NOT EXISTS). No
-- semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `ai_knowledge_sources`
  ADD COLUMN IF NOT EXISTS `version` int(11) NOT NULL DEFAULT 1
    COMMENT 'bumped on content change via update_source_action()',
  ADD COLUMN IF NOT EXISTS `content_hash` char(40) NULL DEFAULT NULL
    COMMENT 'sha1 of last-indexed text, NULL until first update/reindex',
  ADD COLUMN IF NOT EXISTS `agent_id` int(10) unsigned NULL DEFAULT NULL
    COMMENT 'ai_agent_profiles.id scope, NULL = visible to all agents';

ALTER TABLE `ai_knowledge_sources`
  ADD INDEX IF NOT EXISTS `agent_id` (`agent_id`);

ALTER TABLE `ai_reply_audit`
  ADD COLUMN IF NOT EXISTS `kb_sources` varchar(255) NULL DEFAULT NULL
    COMMENT 'comma-separated ai_knowledge_sources.id used for this reply (citations)';
