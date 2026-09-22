-- Phase 10 / brief 30: accurate AI cost accounting.
-- ai_usage_log previously only saw calls routed through Ai_provider. Embeddings, KB chunking,
-- the price-guard judge, Whisper and the agent preview called OpenAI directly and were never
-- logged, so Analytics Hub under-reported cost. Every call now records usage + an estimated
-- cost computed from a configurable price table (no more hardcoded prices in the controller).
DO 0;

ALTER TABLE ai_usage_log
  ADD COLUMN IF NOT EXISTS units DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'non-token units, e.g. audio seconds',
  ADD COLUMN IF NOT EXISTS estimated_cost_usd DECIMAL(12,6) NULL,
  ADD INDEX IF NOT EXISTS idx_user_purpose_time (user_id, purpose, created_at);

CREATE TABLE IF NOT EXISTS ai_model_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  model VARCHAR(100) NOT NULL,
  input_per_1m_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  output_per_1m_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  per_minute_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_provider_model (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public list prices at the time of writing (estimates; edit rows to update, no deploy needed)
INSERT IGNORE INTO ai_model_pricing (provider, model, input_per_1m_usd, output_per_1m_usd, per_minute_usd, updated_at) VALUES
  ('openai','gpt-4o-mini',0.15,0.60,0,NOW()),
  ('openai','gpt-4o',2.50,10.00,0,NOW()),
  ('openai','gpt-4.1',2.00,8.00,0,NOW()),
  ('openai','gpt-4.1-mini',0.40,1.60,0,NOW()),
  ('openai','gpt-3.5-turbo',0.50,1.50,0,NOW()),
  ('openai','text-embedding-3-small',0.02,0,0,NOW()),
  ('openai','whisper-1',0,0,0.006,NOW()),
  ('anthropic','claude-haiku-4-5',1.00,5.00,0,NOW()),
  ('anthropic','claude-sonnet-4-5',3.00,15.00,0,NOW());
