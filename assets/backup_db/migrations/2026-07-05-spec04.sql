-- SPEC-04: sentiment + auto language
ALTER TABLE open_ai_config
  ADD COLUMN auto_language ENUM('0','1') NOT NULL DEFAULT '1',
  ADD COLUMN sentiment_enabled ENUM('0','1') NOT NULL DEFAULT '0';
ALTER TABLE ai_conversation_history ADD COLUMN sentiment VARCHAR(10) NULL;
