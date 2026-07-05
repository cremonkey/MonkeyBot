# SPEC-02: Multi-provider AI (OpenAI + Anthropic Claude) (Phase 1.2)

> READ `.specs/MASTER-PLAN.md` header first. Fresh mysqldump before ALTER. Commit per task.

## Current state (verified)
- `application/libraries/Openai_api.php`: `open_ai_completion($api_key,$prompt_or_messages,$model,$max_tokens,$instruction,$description,$human,$temperature)`; legacy models → /v1/completions, chat models (incl gpt-4o, gpt-4o-mini) → /v1/chat/completions; normalizes chat responses to `choices[0]['text']` (lines ~87-93).
- Caller: `Home.php::get_ai_reply_open_ai()` ~6999 builds messages array (system + history + user), reads `$response['choices'][0]['text']`.
- Config table `open_ai_config`: user_id, open_ai_secret_key, instruction_to_ai, models, maximum_token, sales_mode_enabled, sales_system_prompt, max_history_messages, temperature, memory_ttl_hours. Key may carry `enc::` prefix if SPEC-00 Task E ran — use `secret_decrypt()` from `application/helpers/secret_helper.php` if it exists.
- Admin UI: `Integration.php::open_ai_api_credentials(_action)` (~277/291) + view `application/views/admin/openAI/api_credentials.php`.

## Task 1 — DB migration
```sql
ALTER TABLE open_ai_config
  ADD COLUMN ai_provider ENUM('openai','anthropic') NOT NULL DEFAULT 'openai',
  ADD COLUMN anthropic_secret_key VARCHAR(255) NULL,
  ADD COLUMN anthropic_model VARCHAR(100) NOT NULL DEFAULT 'claude-haiku-4-5';
```
Run on live DB (see MASTER-PLAN). Verify with SHOW CREATE TABLE. Save the SQL to `assets/backup_db/migrations/2026-07-05-spec02.sql` (create migrations dir — later specs append their own files there as the schema changelog).

## Task 2 — Anthropic driver
Create `application/libraries/Anthropic_api.php` (CI library, cURL, SSL verify TRUE):
- `public function anthropic_completion($api_key, $messages, $model='claude-haiku-4-5', $max_tokens=1024, $system='', $temperature=0.7)`
- POST `https://api.anthropic.com/v1/messages`; headers `x-api-key: <key>`, `anthropic-version: 2023-06-01`, `content-type: application/json`.
- Body `{model, max_tokens, temperature, system, messages}`; messages roles only user/assistant. Strip system-role entries from the incoming array into the `system` param; merge consecutive same-role messages with "\n" (Anthropic requires alternation); ensure first message is role user (prepend "..." if needed).
- Normalize response to callers' shape: `array('choices'=>array(array('text'=>$json['content'][0]['text'])), 'usage'=>$json['usage'] ?? null)`. Errors → `array('error'=>array('message'=>...))` (OpenAI error shape).
- Supported model values: `claude-sonnet-4-5`, `claude-haiku-4-5`.

## Task 3 — Provider router
Create `application/libraries/Ai_provider.php`:
- `public function completion($config_row, $messages, $overrides=array())` — reads `ai_provider` from the open_ai_config row; dispatches to Openai_api or Anthropic_api with the right key/model/max_tokens/temperature (secret_decrypt keys); returns the normalized `choices[0]['text']` shape either way. `$overrides` may set model/max_tokens/temperature/system.
- Lazy-load via `$CI =& get_instance();`.
In `Home.php::get_ai_reply_open_ai()`: replace the direct `$this->openai_api->open_ai_completion(...)` call with the router. DO NOT change the function signature, return shape, or memory logic — all existing call sites keep working unchanged.

## Task 4 — Admin UI
`application/views/admin/openAI/api_credentials.php` + `Integration.php::open_ai_api_credentials_action()`:
- Provider select (OpenAI / Anthropic Claude) toggling provider-specific fields (small inline JS show/hide).
- Anthropic key field (masked display, keep-old-if-unchanged) + Anthropic model select.
- Prune retired OpenAI models from dropdown (text-davinci-* etc.); keep/add: gpt-4o, gpt-4o-mini, gpt-4.1, gpt-4.1-mini.
- Match the view's existing style (lang lines, form markup, validation in controller with strip_tags).

## Task 5 — Verify
- Lint all changed files; smoke test.
- Add session-auth'd JSON endpoint `Integration::ai_provider_ping()` calling `Ai_provider->completion()` with the user's saved config and a 1-token test message; returns {status, provider, error?}. (Gives the user a real connectivity test button-less via URL; optionally add a small "Test" button in the view.)
- Report what was actually verified vs lint-only. Commit.
