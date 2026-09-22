# ADR-001: Unified Conversation Model

- **Status:** Accepted (Phase 9.5 data foundation, 2026-09-22)
- **Date:** 2026-09-22 (proposed), 2026-09-22 (accepted)
- **References:** brief §11, §12, §14, §16, §47–§50; baseline sections C and H-02; ADR-000; ADR-002
- **Implementation:** `assets/backup_db/migrations/2026-09-23-conversation-core.sql`,
  `2026-09-23b-conversation-link-trigger.sql`, `application/helpers/conversation_helper.php`,
  `application/helpers/conversation_backfill_helper.php`, `Cron_hub::backfill_conversations`,
  `tests/conversations/`. Runbook: `docs/database/conversation-core.md`.

## Context

There is no conversation entity today. What exists in practice:

- **Messages: `livechat_messages`.** About 42k rows, with a `platform` column. It is the closest thing to a unified message store. It has seven writers:
  - `Home::insert_livechat_data` (Messenger_bot goes through it);
  - `Home::system_message_insert_into_conversation`, which writes rows **without `user_id`**;
  - `Openwa_bot::log_msg`, `Whatsapp_bot::log_msg`, `Telegram_bot::log_msg`, `Webchat::log_msg`;
  - `channel_log_message()`, which writes fb/ig bot rows with **`page_table_id = 0`** and only the Facebook page id.

  The Livechat inbox (module 82) shows only FB and IG. Retention deletes rows older than 120 days.
- **Customer: `messenger_bot_subscriber`.** It holds `lead_score`, `bot_paused_until` (the 6-hour human handoff) and the assignment. WA Cloud, Telegram and web visitors usually have **no** subscriber row.
- **Handoff** is only a timer: `bot_paused_until`.
  - Written by `Livechat::toggle_bot_pause`, `Home::assign_conversation_to_team_member` and `Ai_tools::t_handoff`.
  - Read by Messenger_bot, Openwa_bot, Whatsapp_bot, Telegram_bot, Cron_hub and Reengage_*.
  - Each reader uses a slightly different WHERE clause, and some use `subscribe_id` only, with no tenant filter.
- **AI state** (`ai_conversation_history`, `ai_reply_audit`, `ai_price_guard_log`) is not keyed to any conversation.

## Decision

### 1. Expand: new tables, no message copy

`livechat_messages` stays the message store. It gains a nullable `conversation_id` column (added instantly on MariaDB 10.11) and the index `(conversation_id, id)`. Its content is never copied. The normalised `conversation_messages` table from the proposal is **deferred** until ADR-002 adapters produce normalised inbound messages. Copying 42k legacy rows now would create a second source of truth with nothing reading it.

New tables:

- **`conversations`:** one row per `(user_id, platform, channel_account_id, external_thread_id)`, which is UNIQUE.
  - `channel_account_id` is the page table id for fb/ig and the account id for wa/tg. It is 0 for web.
  - `external_thread_id` is `livechat_messages.subscriber_id`.
  - It carries `status` (open/resolved), `handoff_state`, `assigned_user_id` and the cached `last_message_at`, `last_inbound_at`, `unread_count` and `message_count`.
- **`conversation_events`:** an append-only audit of every state change (from, to, actor type and id, note).
- **`customers`, `customer_identities`, `customer_merge_log`:** the Customer 360 foundation (brief §16). This replaces the earlier plan to point conversations at `messenger_bot_subscriber`, because WA Cloud, Telegram and web threads have no subscriber row.
  - Exactly one customer per identity.
  - **No automatic merging.** `customer_merge_log` exists for future verified or manual merges.
  - `customers.legacy_subscriber_id` links back to the subscriber the customer was created from.

### 2. Linking new messages: a BEFORE INSERT trigger (chosen) rather than a PHP helper in each writer

`trg_livechat_messages_link_conversation` resolves the tenant and the channel account and then does the following:

1. Finds or creates the customer and its identity.
2. Upserts the conversation and bumps its counters.
3. Reopens it (`RESOLVED` → `AI_ACTIVE`, audited, actor `customer`) when a customer message arrives.
4. Sets `NEW.conversation_id`.

It resolves the tenant and account as follows:

- A system row written without `user_id` belongs to the page owner.
- A `channel_log_message()` row with `page_table_id` 0 is mapped to the page row, by the tenant's `page_id`.

Why the trigger:

- **Coverage.** It covers all seven writers and any future one without editing the hot webhook controllers.
- **Nothing to forget.** A PHP helper would need seven call sites. The next writer would also need it, and a missed site links nothing without any error.
- **Atomic.** The link is part of the INSERT, so there is no window in which a message exists without its conversation.
- **Privileges.**
  - The production app user is created by the MariaDB image from `MARIADB_USER` with `ALL` on its database, which includes `TRIGGER`.
  - Binary logging is off: the production DB container runs `mariadb:10.11` with only charset and sql-mode flags, and there is no `--log-bin`. So `CREATE TRIGGER` needs neither SUPER nor `log_bin_trust_function_creators`.
  - Verified on staging: a non-root user with `ALL` on the database created the trigger through `cron_hub migrate`.
  - If binary logging is ever turned on, the migration fails without breaking anything: the runner leaves the file unrecorded and retries it next time. Fix it by setting `log_bin_trust_function_creators=1`, or by applying the file as root.
- **Installation, with the runner unchanged.** `Cron_hub::migrate` strips whole-line comments and splits statements on a semicolon followed by a line break, which would cut a trigger body apart. Rather than change the runner (it is shared with the other 9.x branches), every semicolon **inside** the trigger body is followed by an inline comment on the same line, so the body stays one statement. Verified: `php index.php cron_hub migrate` installs the trigger with the runner exactly as it is on `release/9.x-integration`. The mariadb client with `DELIMITER $$` also works, for manual application.

Safety properties, all verified by tests:

- A `CONTINUE HANDLER FOR SQLEXCEPTION` wraps the body. Any catchable error, such as a constraint violation, leaves the message **inserted** with `conversation_id` NULL. The next backfill links it.
- The trigger does not change `LAST_INSERT_ID()`, so writers' `insert_id()` still returns the message id.
- **Known limit:** a lock-wait timeout (1205) or a deadlock inside a trigger cannot be caught. The INSERT then fails.
  - The trigger takes a row lock on the conversation, so **no code may hold a `conversations` row lock across I/O or for long**.
  - Today the only lock holders are the trigger itself (autocommit, milliseconds), `conversation_transition()` (one row, milliseconds) and the backfill.
  - The backfill runs in READ COMMITTED with bounded batches, so its joins take no shared locks.
  - Measured on 48k synthetic messages: 3,000 concurrent inserts ran during a full backfill with 0 failures and a maximum insert latency of 40 ms.
- **Lock order** is always `customer_identities` → `customers` → `conversations`, in the trigger, in the helper and in the backfill. The trigger never touches `messenger_bot_subscriber`.
- **Kill switch:** `DROP TRIGGER IF EXISTS trg_livechat_messages_link_conversation`. It takes effect immediately and legacy behaviour is unchanged. The backfill can relink later.

Alternative (b), a PHP helper after each insert, would contain failures completely (try/catch), but its coverage is only as good as the call sites. It is rejected for now. If the lock-timeout risk ever becomes real, the fallback is to drop the trigger and call `conversation_find_or_create()` plus an `UPDATE livechat_messages SET conversation_id` from `channel_log_message` and the five `log_msg` methods.

### 3. Backfill (Migrate + Verify)

`php index.php cron_hub backfill_conversations [batch] [max_batches]` is CLI-only, holds a GET_LOCK and is resumable and idempotent. It never prints message content. It does the following:

1. Creates customers and identities from `messenger_bot_subscriber`.
2. Creates conversations from the distinct `livechat_messages` threads and sets `conversation_id`.
3. Recomputes the counters.
4. Sets `handoff_state = HUMAN_ACTIVE` (audited as `backfill_state`) where `bot_paused_until` is later than PHP now. The comparison uses PHP time because every writer and reader uses PHP `date()`.
5. Prints the brief §49 verification report and exits non-zero on any failed check.

### 4. Handoff state machine (brief §14)

`conversation_transition($id, $to, $actor_type, $actor_id, $note, $tenant_guard)` allows these moves:

- AI_ACTIVE → WAITING_FOR_HUMAN
- WAITING_FOR_HUMAN → HUMAN_ACTIVE
- HUMAN_ACTIVE → AI_ASSIST
- AI_ASSIST → HUMAN_ACTIVE
- HUMAN_ACTIVE → RESOLVED
- RESOLVED → AI_ACTIVE
- any state → AI_ACTIVE when the actor is an agent ("return to bot")

A move to the same state is a no-op. Every real change writes one `conversation_events` row in the same transaction.

**Compatibility (brief §47).** In that same transaction, each state also writes `bot_paused_until` on the conversation's own subscriber row. The row is matched by tenant, platform, account and thread.

| State | `bot_paused_until` |
|---|---|
| WAITING_FOR_HUMAN, HUMAN_ACTIVE, AI_ASSIST | set to now + 6 h |
| AI_ACTIVE, RESOLVED | cleared |

AI_ASSIST keeps the pause because the AI only drafts for the agent.

`conversation_is_bot_allowed()` returns exactly today's answer, which is based on `bot_paused_until`. A thread with no subscriber row is allowed. Existing readers and writers of `bot_paused_until` are **not** changed in this phase.

### 5. Readers switch one at a time (unchanged)

The order is: Universal Inbox (new) → Livechat → analytics → AI history. Each switch is behind a per-tenant flag (brief §50, e.g. `NEW_UNIFIED_INBOX`). Retention for the new tables is to be decided with the owner.

## Consequences

**Positive:**

- Every message on all five platforms has a conversation and every conversation has a customer, for new rows immediately and for history after one CLI run.
- Handoff becomes explicit, audited and tenant-scoped.
- No legacy screen or bot behaviour changes.

**Negative / open:**

- **Handoff divergence until writers switch.** The legacy writers (Livechat toggle, Home assignment, Ai_tools handoff) still write only `bot_paused_until`. `handoff_state` therefore lags behind them until those writers call `conversation_transition()`. Re-running the backfill syncs AI_ACTIVE → HUMAN_ACTIVE, but never syncs an expired pause back. **Nothing may read `handoff_state` for bot gating until then.**
- **WA account-id collision.** `livechat_messages` has no account-type column. An OpenWA account and a WA Cloud account with the same numeric id, owned by the same tenant, share `(platform 'wa', channel_account_id)`. A customer phone that writes to both would then merge into one conversation. The verification report counts such account pairs. The fix is for the writers to record the account type (ADR-002).
- **Counters are caches.** Retention deletes are not decremented until the next backfill recompute. A recompute running concurrently with inserts can lose an increment, which the next run corrects.
- **No subscriber row, no pause.** Channels without a subscriber row (Telegram, WA Cloud, web) still cannot be paused by the legacy gate. The state machine records their state, but the bot will not obey it until the readers switch.
- **Legacy reader bug.** Messenger_bot's pause check filters on `subscribe_id` only, so the same PSID in another tenant can answer it. This is not fixed here, per scope. `conversation_is_bot_allowed()` is tenant-scoped.

## Alternatives

| Option | Why not |
|---|---|
| PHP helper called from each writer | Coverage depends on seven call sites and on every future writer remembering it. It is kept as the documented fallback. |
| Copy `livechat_messages` into `conversation_messages` now | It creates a second store with no reader, and retention (120 days) would have to be handled twice. Deferred to the ADR-002 inbound path. |
| Point `conversations.customer_id` at `messenger_bot_subscriber` | Telegram, WA Cloud and web threads have no subscriber row, and it would block Customer 360. |
| Per-channel inbox screens | They multiply the per-channel divergence the brief is trying to remove. |
