# SPEC-03: AI Function Calling / Tool Use (Phase 1.3)

> READ `.specs/MASTER-PLAN.md` header first. Depends on SPEC-02 (Ai_provider). Commit per task.

## Goal
Turn the AI reply from text-only into an agent that can act: search real products, check order status, generate a discount code, hand off to a human. Engaged ONLY in Messenger/IG DM AI-reply paths where subscriber context exists (page_id + subscribe_id passed to `get_ai_reply_open_ai`).

## Task 1 — Config & migration
```sql
ALTER TABLE open_ai_config ADD COLUMN ai_tools_enabled ENUM('0','1') NOT NULL DEFAULT '0';
```
Live DB + `assets/backup_db/migrations/2026-07-05-spec03.sql`. Add checkbox in `application/views/admin/openAI/api_credentials.php` + save in `Integration.php::open_ai_api_credentials_action()` ("Enable AI actions (product search, order status, discount codes, human handoff)").

## Task 2 — Tools library
Create `application/libraries/Ai_tools.php`:
- `public function get_tool_definitions($user_id)` → array of tool schemas (name, description, JSON-schema input) for:
  1. `search_products` {query} — LIKE-search `ecommerce_product` (join `ecommerce_store` WHERE store belongs to $user_id, product status active) → return up to 5: name, price, currency, product URL (inspect how Ecommerce.php builds public product/store URLs and reuse), short description.
  2. `get_order_status` {email_or_phone} — search `ecommerce_cart` for this user's stores by customer email/phone (inspect live columns first: `SHOW CREATE TABLE ecommerce_cart`) → latest order status + total.
  3. `create_discount_code` {percent} — clamp percent 5–30; insert into `ecommerce_coupon` for the user's default store (inspect columns; code = strtoupper 8-char random, expiry +7 days, single-use if column supports). Return the code. If SPEC-06's `generate_coupon()` helper exists in `application/helpers/coupon_helper.php`, reuse it.
  4. `handoff_to_human` {} — mark handoff: if column `messenger_bot_subscriber.bot_paused_until` exists (SPEC-09), set NOW()+6 HOUR; always fire the existing agent-notification pattern if feasible (see Home.php ~273-327 `agent_assign_notifications`); return confirmation text.
- `public function execute($tool_name, $args, $context)` — $context = ['user_id','page_id','subscribe_id','social_media','subscriber_row_id']. All queries via CI query builder, ownership-scoped by user_id. Return string (JSON-encoded result) for the model.

## Task 3 — Tool loop in Ai_provider
Extend `application/libraries/Ai_provider.php::completion($config_row, $messages, $overrides)`:
- New optional `$overrides['tools_context']`; when set AND `$config_row->ai_tools_enabled=='1'`, pass tool definitions to the provider call and run the tool loop (max 3 iterations):
  - OpenAI chat: `tools:[{type:'function',function:{name,description,parameters}}]`; on `tool_calls` in response → execute each, append assistant msg + `role:'tool'` results, re-call.
  - Anthropic: `tools:[{name,description,input_schema}]`; on `stop_reason=='tool_use'` → for each `tool_use` block execute, append assistant content + `role:'user'` message with `tool_result` blocks, re-call.
- Final normalized return stays `choices[0]['text']`.
- OpenAI path: tools require the chat-completions endpoint — ensure gpt-4o/4o-mini/4.1 path used. Openai_api.php may need a `$tools` param added to `open_ai_completion` (add as optional trailing arg, default null — existing callers unaffected).

## Task 4 — Wire into reply flow
In `Home.php::get_ai_reply_open_ai()`: when `$page_id && $subscribe_id`, pass `tools_context` (user_id, page_id, subscribe_id, social_media, and subscriber row id — look it up once) in overrides. No signature change.

## Task 5 — Verify
- Lint everything. Smoke test.
- Unit-ish CLI test for Ai_tools SQL (no API needed): create `.specs/scratch/test_ai_tools.php` runnable via `docker exec monkeybot-app-1 php index.php ...`? CI3 CLI routing is `php index.php <controller> <method>` — instead add temporary method is messy; simplest: test `search_products`/`create_discount_code` SQL by running equivalent queries via mariadb CLI to confirm column names used exist. Report evidence.
- Commit: `feat: AI function calling (products, orders, coupons, handoff)`.
