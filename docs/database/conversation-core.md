# Conversation core (Phase 9.5)

This document covers the unified conversation core, the customer identity foundation and the human-handoff state machine. The design and its reasoning are in [ADR-001](../architecture/adr/ADR-001-unified-conversation-model.md). This page is the operator's runbook.

This is the **Expand → Migrate → Verify** step only. Nothing reads the new tables yet, and no legacy reader or writer changed.

## Schema

The schema comes from two migrations, `2026-09-23-conversation-core.sql` and `2026-09-23b-conversation-link-trigger.sql`.

| Object | Purpose |
|---|---|
| `conversations` | One row per `(user_id, platform, channel_account_id, external_thread_id)`, which is UNIQUE. It holds `status` (open/resolved), `handoff_state` (AI_ACTIVE, WAITING_FOR_HUMAN, HUMAN_ACTIVE, AI_ASSIST, RESOLVED), `assigned_user_id`, `customer_id` and the cached `last_message_at`, `last_inbound_at`, `unread_count` and `message_count`. Indexes: `(user_id, last_message_at)`, `(user_id, handoff_state)` and `(customer_id)`. |
| `conversation_events` | Append-only audit: `event_type` (`state_change`, `backfill_state`), `from_state`, `to_state`, `actor_type` (system/ai/agent/customer), `actor_id`, `note`. |
| `customers` | Tenant-scoped person record: `display_name`, `phone`, `email`, and `lead_score`, which is a snapshot (lead scoring still writes the subscriber). Also `source` (subscriber/thread/manual), `legacy_subscriber_id` (UNIQUE) and `merged_into_customer_id`. |
| `customer_identities` | One channel identity. The key `(user_id, platform, channel_account_id, external_id)` is UNIQUE. `verified` is reserved for verified phone and email. |
| `customer_merge_log` | Audit for future merges, which must be verified or manual. **Nothing merges automatically.** |
| `livechat_messages.conversation_id` | A nullable BIGINT with the index `idx_lm_conversation (conversation_id, id)`. |
| `trg_livechat_messages_link_conversation` | A BEFORE INSERT trigger on `livechat_messages` that links every new row. See the next section. |

Key mapping from `livechat_messages`:

| Conversation field | Source |
|---|---|
| `user_id` | `user_id`. If NULL (fb/ig system rows), the owner of the page `page_table_id`. |
| `platform` | `platform` |
| `channel_account_id` | `page_table_id`. For fb/ig rows with 0 (the `channel_log_message()` bot rows), the tenant's page row whose `page_id = fb_page_id`. |
| `external_thread_id` | `subscriber_id`. Rows with an empty `subscriber_id` are not linkable. |

A customer identity uses the same key. It matches `messenger_bot_subscriber` on `(user_id, social_media, page_table_id, subscribe_id)`.

All new tables use utf8mb4 / utf8mb4_unicode_ci, the same collation as `livechat_messages`.

## The link trigger

For each new `livechat_messages` row, the trigger:

1. resolves the tenant and the channel account;
2. finds or creates the customer and its identity;
3. upserts the conversation and bumps its counters;
4. reopens a RESOLVED conversation when a customer message arrives, and audits it;
5. sets `NEW.conversation_id`.

Things to know:

- **SQL errors.** Any catchable SQL error inside the trigger leaves the message inserted with `conversation_id` NULL. The backfill links it later.
- **Lock waits cannot be caught.** A lock-wait timeout or a deadlock inside a trigger fails the INSERT. Never hold a `conversations` row lock for long, and never across HTTP or API calls. See ADR-001.
- **Kill switch.** `DROP TRIGGER IF EXISTS trg_livechat_messages_link_conversation;` works instantly. Legacy behaviour is unchanged.
- **Migration file format.** `Cron_hub::migrate` splits statements on a semicolon followed by a line break, so every semicolon inside the trigger body is followed by an inline `-- stmt` comment on the same line. Keep that convention when editing the file. To apply it by hand, use the mariadb client with `DELIMITER $$`.

## Helpers (`application/helpers/conversation_helper.php`)

| Function | Notes |
|---|---|
| `conversation_find_or_create($user_id, $platform, $channel_account_id, $external_thread_id)` | Returns the conversation id, or 0 on invalid input or a DB error. Creates one customer with one identity, enriched from the subscriber row. |
| `conversation_transition($id, $to, $actor_type, $actor_id = null, $note = '', $tenant_guard = null)` | Enforces the brief §14 edges plus the agent's "return to bot" (any state → AI_ACTIVE). It writes a `conversation_events` row and mirrors `bot_paused_until` (+6 h for WAITING_FOR_HUMAN, HUMAN_ACTIVE and AI_ASSIST, cleared for AI_ACTIVE and RESOLVED), all in one transaction. With a tenant guard, another tenant's id comes back as "not found". |
| `conversation_is_bot_allowed($user_id, $platform, $subscribe_id, $page_table_id = null)` | Returns today's answer from `bot_paused_until`, tenant-scoped. No subscriber row means allowed. |

## Backfill runbook

```sh
# inside the app container, as www-data
php index.php cron_hub migrate                         # applies the two migrations
php index.php cron_hub backfill_conversations 2000     # batch size; prints progress + verification report
php index.php cron_hub backfill_conversations 2000     # second run: must do no work
php index.php cron_hub backfill_conversations report   # verification only, no writes
```

How it behaves:

- **Resumable.** An interrupted run (Ctrl-C, or `max_batches` as the 2nd argument) continues where it stopped.
- **Idempotent.** A second run reports `+0 created, 0 linked, 0 corrected`.
- **Safe to run with live traffic.** It holds `GET_LOCK('mb_conversation_backfill')`, runs in READ COMMITTED, and works in bounded batches.
- **Content is never read.** It never reads or prints message content. It compares only character lengths and bytes of the copied identifiers and names.
- **Exit code:** 0 when every check passes, 1 otherwise.

Report lines:

| Line | Meaning |
|---|---|
| per platform `source_threads` = `with_msgs` | Every distinct linkable thread has a conversation. |
| `messages_linked` = `count_sum` | The counters match the rows. |
| `orphans: empty subscriber_id` / `no resolvable tenant` | Informational. These rows cannot be linked by design. |
| `orphans: linkable but not linked` | Must be 0. After go-live, a non-zero value between runs means the trigger failed for those rows. |
| tenant mismatch (four checks), duplicates (three), customers without identity, conversations without customer | Must be 0. |
| `paused subscribers whose conversation is not in a human state` | 0 right after a run. It grows afterwards because legacy writers still only set `bot_paused_until` (ADR-001). |
| `utf8mb4:` checks | Copied names and ids are byte-identical to the source, and the new columns are utf8mb4. |
| `wa account-id collisions` | The number of tenants that own an OpenWA account and a WA Cloud account with the same numeric id. If it is non-zero, investigate before any reader uses `wa` conversations (ADR-001). |

## Verification queries (manual spot checks)

```sql
-- every linkable message linked (expect 0)
SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NULL AND subscriber_id <> '';

-- one conversation per thread per platform (compare with SELECT platform, COUNT(*) FROM conversations GROUP BY platform)
SELECT platform, COUNT(DISTINCT user_id, page_table_id, subscriber_id) FROM livechat_messages
 WHERE subscriber_id <> '' GROUP BY platform;   -- approximate: ignores the fb/ig page_table_id=0 remap

-- tenant isolation (expect 0)
SELECT COUNT(*) FROM livechat_messages m JOIN conversations c ON c.id = m.conversation_id
 WHERE m.user_id IS NOT NULL AND m.user_id <> 0 AND m.user_id <> c.user_id;

-- no automatic merging (expect 0)
SELECT COUNT(*) FROM (SELECT customer_id FROM customer_identities GROUP BY customer_id HAVING COUNT(*) > 1) d;

-- handoff mirrors the legacy pause (use the PHP clock, i.e. UTC unless config time_zone is set)
SELECT c.handoff_state, COUNT(*) FROM conversations c JOIN messenger_bot_subscriber s
    ON s.user_id = c.user_id AND s.social_media = c.platform AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
 WHERE s.bot_paused_until > UTC_TIMESTAMP() GROUP BY c.handoff_state;

-- audit trail of one conversation
SELECT created_at, event_type, from_state, to_state, actor_type, actor_id, note
  FROM conversation_events WHERE conversation_id = ? ORDER BY id;
```

## Rollback

Rollback is safe because nothing reads the new objects yet. **Drop the trigger first.** While it exists, every `livechat_messages` INSERT needs the new tables: a missing table fails at statement open time, which the handler cannot catch.

```sql
DROP TRIGGER IF EXISTS trg_livechat_messages_link_conversation;   -- step 1, always; stops all new writes
-- optional full removal:
ALTER TABLE livechat_messages DROP INDEX IF EXISTS idx_lm_conversation;
ALTER TABLE livechat_messages DROP COLUMN IF EXISTS conversation_id;
DROP TABLE IF EXISTS conversation_events, conversations, customer_merge_log, customer_identities, customers;
DELETE FROM schema_migrations WHERE filename IN ('2026-09-23-conversation-core.sql', '2026-09-23b-conversation-link-trigger.sql');
```

`DROP COLUMN` rebuilds `livechat_messages`, which is seconds at 42k rows. Take a backup first (`docs/runbooks/backup-and-restore.md`).

## Tests

The tests live in `tests/conversations/`. They run against a disposable database, never `monkeybot_staging`, and use synthetic data only.

```sh
# one-time: scratch DB + user with ALL on it (mirrors the production app user)
docker exec monkeybot-staging-db-1 sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" -e "
  CREATE DATABASE mb_conv_test CHARACTER SET utf8 COLLATE utf8_general_ci;
  CREATE USER mbconv@\"%\" IDENTIFIED BY \"<random>\"; GRANT ALL ON mb_conv_test.* TO mbconv@\"%\";"'
docker exec -i monkeybot-staging-db-1 sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" mb_conv_test' < assets/backup_db/initial_db.sql
# run migrations up to 2026-09-22c with a CI config pointing at mb_conv_test (mount it over
# application/config/database.php - never commit it), then seed, then the two new migrations:
php tests/conversations/seed_synthetic.php | docker exec -i monkeybot-staging-db-1 sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 mb_conv_test'
php index.php cron_hub migrate && php index.php cron_hub backfill_conversations
# the suite (CodeIgniter DB layer bootstrapped standalone, production connection settings)
MBCONV_PW=<random> php tests/conversations/run.php
```

The suite covers:

- all 100 state × state × actor combinations, and the audit rows;
- `bot_paused_until` mirroring, checked against each legacy reader's WHERE shape;
- `conversation_is_bot_allowed` equal to the legacy formula for every subscriber;
- tenant isolation in both the trigger and the transitions;
- a new insert linked for every platform and every writer shape, with `insert_id()` preserved;
- trigger failure handling;
- backfill resumability and idempotency, by re-backfilling a seed tenant from scratch.

### Results (2026-09-22, synthetic data)

**Seed:** 3 tenants, 383 threads on fb, ig, wa, tg and web, 259 subscribers, 6,004 messages. The messages include Arabic, 4-byte emoji and ~3k-character texts. 27 subscribers had a pause in the future.

**Backfill:**

- 259 customers and identities, 383 conversations, 5,979 of 6,004 messages linked.
- The 25 unlinked messages are unlinkable by design: 20 have an empty `subscriber_id` and 5 have no resolvable tenant.
- 24 to 27 conversations set to HUMAN_ACTIVE, depending on how many of the seeded pauses were still in the future at run time.
- Every check was OK except the one wa account-id collision planted in the seed.
- Runtime: about 3 s for the backfill and 0.15 s for verification. The second run did no work.

**Tests:** 486 assertions passed, 0 failed, in 14 test groups. Two consecutive runs gave the same result.

**Scale run:** the same seed amplified to 48,032 messages and 8,029 subscribers (production is about 42k and 9k).

- Backfill: 25 s, verification: 1 s.
- Repeated while 3,000 inserts ran concurrently: 0 insert failures, maximum insert latency 40 ms, verification clean.
- That run also exposed a race between the trigger and the backfill that left 4 customers without an identity. It is fixed (backfill orphan cleanup plus a transactional helper) and covered by the rerun.

## Production rollout

1. Take a backup (`docs/runbooks/backup-and-restore.md`) and confirm the restore path.
2. Confirm on the production DB that `SELECT @@log_bin` returns 0 and that the app user has `TRIGGER` (it has `ALL` on its DB).
   - If `@@log_bin` is 1, set `log_bin_trust_function_creators=1` first, or apply `2026-09-23b-...sql` as root.
3. Deploy the code. It is inert on its own: the helpers are not loaded by any request path and the backfill is CLI-only.
4. Run `php index.php cron_hub migrate`. Expect `applied 2026-09-23-conversation-core.sql` and `applied 2026-09-23b-conversation-link-trigger.sql`. From this moment new messages are linked.
5. Off-peak, run `php index.php cron_hub backfill_conversations 2000`. Keep the report.
   - Expect all checks OK.
   - `wa account-id collisions` must be understood before any reader uses wa conversations.
6. Run it again. It must report no work and the same numbers.
7. Watch for 24 h:
   - the application error log for `livechat_messages` insert failures (1205 or deadlock);
   - `backfill_conversations report`, where `orphans: linkable but not linked` should stay 0.
8. Optional: schedule `backfill_conversations report` (or a full run, to recompute counters after retention) nightly in `docker/cron/monkeybot`.

If anything goes wrong, drop the trigger. See Rollback.
