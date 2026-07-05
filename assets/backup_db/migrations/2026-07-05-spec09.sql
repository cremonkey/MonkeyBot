-- SPEC-09: pause bot on human handoff
ALTER TABLE messenger_bot_subscriber ADD COLUMN bot_paused_until DATETIME NULL, ADD INDEX idx_bot_paused (bot_paused_until);
