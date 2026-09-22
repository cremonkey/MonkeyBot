-- AI conversation summary cache (brief §13, §51). Adds two nullable columns to the existing
-- `conversations` table (ADR-001) so an on-demand summary can be cached and only regenerated
-- when new messages have arrived since the last one, instead of re-calling the AI on every
-- thread open. Nullable columns added at the end apply instantly on MariaDB 10.11, without a
-- table rebuild — same pattern as `livechat_messages.conversation_id` in
-- 2026-09-23-conversation-core.sql. Idempotent (IF NOT EXISTS): safe to re-run, and a partial
-- failure leaves the file unrecorded so Cron_hub::migrate retries it (see that file's docblock).
--
-- This does NOT retrofit `ai_conversation_history` with a `conversation_id` column — that is a
-- bigger, separate ADR-001-scope change (linking the per-subscriber AI chat log to the new
-- conversation model) and stays deferred; see docs/product/unified-inbox.md. The summary here
-- is generated on demand from `livechat_messages` (already linked via the Phase 9.5 trigger),
-- not from `ai_conversation_history`.
ALTER TABLE `conversations`
  ADD COLUMN IF NOT EXISTS `last_summary` TEXT NULL DEFAULT NULL
    COMMENT 'Cached AI-generated conversation summary (brief §13). Never authoritative — always shown with a verify-before-acting caption (brief §51).',
  ADD COLUMN IF NOT EXISTS `last_summary_at` DATETIME NULL DEFAULT NULL
    COMMENT 'When last_summary was generated. Regenerate when last_message_at is newer than this, or on explicit refresh.';
