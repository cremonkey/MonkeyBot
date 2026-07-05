-- SPEC-03: AI function calling / tool use
ALTER TABLE open_ai_config ADD COLUMN ai_tools_enabled ENUM('0','1') NOT NULL DEFAULT '0';
