-- Task 27 (C12) — AI Employee Builder: structured fields on the agent profile table.
--
-- Adds nullable role/goal/tone plus capabilities_json (per-tool enablement list) and
-- restrictions_json (allowed_link_domains, max_discount, forbidden_topics, escalation_rules)
-- to ai_agent_profiles. All five columns are nullable with no default: a profile saved before
-- this migration (or one where the owner never touched the builder fields) has every column
-- NULL, and the code must behave exactly as before that state — see ai_agent_helper.php /
-- Ai_tools.php, which only branch on these fields when they are non-NULL.
--
-- Code works before this migration runs too: every reader (Ai_tools::catalog(),
-- application/controllers/Home.php get_ai_reply_open_ai()) caches a static
-- field_exists('ai_agent_profiles', 'capabilities_json') gate and no-ops when it is false.
--
-- Idempotent (ADD COLUMN IF NOT EXISTS, supported on MariaDB 10.11). No semicolons in
-- comments: the runner splits on them.
DO 0;

ALTER TABLE `ai_agent_profiles`
  ADD COLUMN IF NOT EXISTS `role` VARCHAR(120) NULL DEFAULT NULL
    COMMENT 'C12 builder: short job title/role token, e.g. Sales Assistant',
  ADD COLUMN IF NOT EXISTS `goal` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'C12 builder: the agent primary objective, free text token',
  ADD COLUMN IF NOT EXISTS `tone` VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'C12 builder: tone token, e.g. friendly, formal, playful',
  ADD COLUMN IF NOT EXISTS `capabilities_json` TEXT NULL DEFAULT NULL
    COMMENT 'C12 builder: JSON array of enabled tool names; NULL = today''s default tool set unchanged',
  ADD COLUMN IF NOT EXISTS `restrictions_json` TEXT NULL DEFAULT NULL
    COMMENT 'C12 builder: JSON object {allowed_link_domains,max_discount,forbidden_topics,escalation_rules}, enforced only per-key when set';
