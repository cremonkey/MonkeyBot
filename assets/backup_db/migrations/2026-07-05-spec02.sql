-- SPEC-02: multi-provider AI (OpenAI + Anthropic)
ALTER TABLE open_ai_config
  ADD COLUMN ai_provider ENUM('openai','anthropic') NOT NULL DEFAULT 'openai',
  ADD COLUMN anthropic_secret_key VARCHAR(255) NULL,
  ADD COLUMN anthropic_model VARCHAR(100) NOT NULL DEFAULT 'claude-haiku-4-5';
