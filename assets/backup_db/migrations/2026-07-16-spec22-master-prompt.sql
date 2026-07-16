-- SPEC-22 — master rules always apply, agent profiles carry the business details.
--
-- agent_name was varchar(100) but the AI Agents form invites a persona sentence into
-- it ("You are Sarah, the official sales and reservations assistant for..."), which was
-- silently truncated mid-sentence at 100 chars. It is now sent to the model as the
-- agent's identity line, so it must hold real text.
ALTER TABLE `ai_agent_profiles` MODIFY COLUMN `agent_name` TEXT NULL;
