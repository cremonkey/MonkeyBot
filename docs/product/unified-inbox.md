# Unified Inbox (Phase 9.5)

- **Brief:** §13 (Universal Inbox), §14 (handoff state machine), §46 (owner-language UX)
- **Built on:** ADR-001 (`docs/architecture/adr/ADR-001-unified-conversation-model.md`),
  `docs/database/conversation-core.md`, `docs/channels/adapters.md`
- **Code:** `application/controllers/Inbox.php`, `application/helpers/inbox_helper.php`,
  `application/views/admin/inbox/index.php`; AI summary (brief §13):
  `application/libraries/Conversation_summarizer.php`, `application/helpers/conversation_summary_helper.php`
- **Migration:** `assets/backup_db/migrations/2026-09-25-unified-inbox.sql`,
  `assets/backup_db/migrations/2026-09-28-conversation-summary.sql` (adds `conversations.last_summary`/`last_summary_at`),
  follow-up: `2026-09-30-inbox-labels.sql` (labels), `2026-09-30b-canned-response-channels.sql`
  (`canned_response.channels`), `2026-09-30c-ai-history-conversation-id.sql`
  (`ai_conversation_history.conversation_id`)
- **Tests:** `tests/inbox/run.php` (unit, no DB), `docker/scripts/staging_inbox_tests.sh` (HTTP+DB),
  `tests/inbox/ai_link_db.php` (real DB, run by the staging script), `tests/channels/AdapterContractTest.php`
  (`send_image`, fake transport); isolated stack for the follow-up: `docker-compose.inbox2-test.yml`
  (port 8102, with the fake OpenWA gateway `tests/inbox/fake_gateway.php`);
  AI summary: `tests/summary_billing/run.php` (unit, no DB), `docker/scripts/staging_summary_billing_tests.sh` (HTTP+DB)

## What this is

The first UI surface built directly on the conversation core: one list, for every platform
(fb, ig, wa, tg, web), backed by `conversations` + `customers` + `customer_identities`, with
explicit handoff states (AI_ACTIVE / WAITING_FOR_HUMAN / HUMAN_ACTIVE / AI_ASSIST / RESOLVED),
assignment, internal notes and a Customer 360 rail (name, phone, email, channel identity).

It is a **read+reply surface only**. It does not touch how messages are received, how the AI
decides to reply, or how `bot_paused_until` is honoured per channel — those stay exactly as
implemented in `Messenger_bot`, `Openwa_bot`, `Whatsapp_bot`, `Telegram_bot`, `Webchat` and
`Home.php`, none of which this phase modifies. The follow-up changes one thing there: two lines
in `Home::get_ai_reply_open_ai()` add `conversation_id` to new `ai_conversation_history` rows (see
"AI suggested reply"). The reply the AI sends is unchanged.

## Coexistence with Livechat.php (module 82)

**Decision: both run side by side. Livechat.php was left untouched, not redirected.**

Why not redirect `Livechat::load_livechat` to the new inbox:

- Livechat.php is fb/ig-only and has real, working functionality this phase does not
  reimplement 1:1: DataTables-driven pagination (`get_conversation_list`/`search_subscriber`),
  file uploads (`livechat_upload_file`, images/video/audio/docs with type-specific size
  limits), Pusher realtime (`ci_pusher`, `livechat_channel`/`livechat_event`), postback
  buttons, and API-driven sends (`reply_to_conversation` also serves external API callers, not
  just the browser UI).
- Verifying "no functionality is lost" for all of the above (brief's own bar for redirecting)
  is a larger effort than Phase 9.5's scope. Buttons and realtime are still **deferred** (see
  below). Attachments are now partly covered: stored media is shown, and image replies work on
  channels whose adapter supports them. Video, audio and file sends stay Livechat-only.
- The brief itself (§35) requires classifying before removing, not redirecting speculatively:
  Livechat.php is classified **KEEP** for this phase, candidate **MODERNISE/REPLACE** once
  attachments + realtime + postbacks land on the unified inbox.

So: **Livechat.php keeps working unchanged for fb/ig**, reachable exactly as before (module 82,
`/livechat`). **Inbox.php is the new, separate, primary surface** (module 411, `/inbox`,
brief §34 nav — "Inbox" first) covering fb/ig/wa/tg/web for text reply, handoff and assignment.
Both write the same `livechat_messages` table and both are auto-linked into the same
`conversations` by the trigger, so a conversation opened in one is fully consistent in the
other (unread counts, message history) — nothing about running both is divergent at the data
layer. The two screens simply don't share UI code.

## The policy-denial UX decision

Brief requirement: a human reply MUST go through `channel_policy_decide(sender='human')`
first; on deny, the UI must show **why** and must **not** silently drop the message or claim it
was sent.

Implementation (`Inbox::reply()`):

1. Resolve the channel account + adapter for the conversation (fb/ig/tg/wa/web).
2. `inbox_reply_decision()` (`application/helpers/inbox_helper.php`) wraps
   `channel_policy_decide()` + `channel_policy_explain()` into one call.
3. **Denied:** no adapter call is made, no `livechat_messages` row is written. The response
   carries `denied: true`, the raw `reason`, and the owner-language `explain` string. The
   composer shows that explanation and offers **"Save as internal note"** — the agent's typed
   text becomes a private note (`note_save`) instead of a customer message that never went out.
   Verified with a real HTTP round-trip in `staging_inbox_tests.sh` (wa_cloud, 30h since the
   customer's last message): `status=0`, `reason=template_required_outside_24h`, zero new
   `livechat_messages` rows.
4. **Allowed:** the adapter sends; only on `SendResult::ok` is the `livechat_messages` row
   written (sender=`bot`, since the schema's `sender` enum has no `agent` value — `agent_name`
   carries the real user's name, exactly the convention `Livechat.php`'s own
   `insert_livechat_data()` already uses for bot-sent rows). A failed send (network/adapter
   error) is reported and, per brief §51 (no silent failure), nothing is logged as sent.

## Handoff actions and the state machine

The brief's state diagram (§14) has **no direct `AI_ACTIVE -> HUMAN_ACTIVE` edge** — only
`AI_ACTIVE -> WAITING_FOR_HUMAN -> HUMAN_ACTIVE`. A "Take over from AI" button that called
`conversation_transition()` once would fail from the single most common starting state. Instead
`inbox_takeover_path()` / `inbox_resolve_path()` (`inbox_helper.php`) compute the shortest
sequence of valid hops to `HUMAN_ACTIVE` / `RESOLVED` from wherever the conversation currently
is, and `Inbox::walk_transition_path()` drives `conversation_transition()` through them,
stopping (and reporting the real error) on the first disallowed hop. Every hop is its own
audited `conversation_events` row — a "takeover" from `AI_ACTIVE` therefore writes 2 rows, both
`actor_type='agent'`. `tests/inbox/run.php` pins every hop of every path against
`conversation_transition_allowed()` so the two maps can't silently drift apart.
`bot_paused_until` mirroring is entirely `conversation_transition()`'s existing responsibility
(ADR-001) — the inbox never touches it directly.

## Owner-language labels (brief §46)

`inbox_handoff_label()` maps the five internal states to the exact wording the brief specifies:
"AI is replying", "Waiting for a teammate", "You're handling this", "AI is helping you",
"Closed". No `bot_paused_until`, `handoff_state` or other internal name reaches the UI.

## IDOR / tenant isolation

Every query that touches `conversations`, `livechat_messages` or `internal_notes` goes through
one of two guards, both centralised in `application/helpers/inbox_helper.php` so the controller
cannot forget one:

- `conversation_get($id, $this->uid)` / `conversation_transition($id, ..., $this->uid)` — the
  existing conversation-core helpers, which already return "not found" for another tenant's id.
- `inbox_scope_conversations()` / `inbox_scope_conversation_owner()` — apply `user_id` as the
  **first** query-builder condition, unconditionally, before any caller-supplied filter.

`tests/inbox/run.php` asserts this with a recording fake query builder (no DB): the tenant
guard is always call #1, and no filter — including a crafted `filters['user_id']` key that
isn't even a recognised filter — can appear before or instead of it.
`staging_inbox_tests.sh` proves it on the wire: tenant A's list never contains tenant B's
thread id, and `conversation_thread` with tenant B's real conversation id (as tenant A) returns
`status=0`, not tenant B's messages.

## Assignment and `team_members`

`assigned_user_id` references `team_members.id`, matching the existing convention in
`Home::assign_conversation_to_team_member`. `team_members` is provided by an optional addon and
is not part of this branch's own migrations — `Inbox::index()` and `Inbox::assign()` both
`table_exists()`-guard it, so the inbox degrades to "no team dropdown" / "team accounts not
enabled" rather than erroring when the addon isn't installed. `staging_inbox_tests.sh` creates
a minimal stand-in table (documented inline) purely to exercise the feature end to end.

## AI conversation summary (brief §13, §51)

`Inbox::summarize($conversation_id)` (AJAX, `entitlement_require('inbox')` from the controller
constructor, `conversation_get($id, $this->uid)` for the tenant guard) generates a short,
factual summary of the thread — what the customer wants, key facts stated, current status, open
questions — from the **last 50 `livechat_messages` rows for that `conversation_id`** (already
linked via the Phase 9.5 trigger, ADR-001), never from `ai_conversation_history` (see
"Deferred" below for why not).

- **Caching / regeneration.** The summary is cached on `conversations.last_summary` /
  `last_summary_at`. Reopening the same conversation returns the cached text with **no AI
  call** unless `conversation_summary_should_regenerate()` (`application/helpers/conversation_summary_helper.php`)
  says a message has arrived since the cache was written (`last_message_at > last_summary_at`),
  or the agent clicks "Regenerate" (`refresh=1`, always bypasses the cache check).
- **Never fabricates.** No `open_ai_config` row for the tenant -> `{status:'0', message:'No AI
  credentials configured'}`, the exact wording `ai_agent_helper.php`'s existing preview feature
  already uses for the same case. An AI/provider failure or an empty response is surfaced as a
  failure, never a placeholder summary. The system prompt (`conversation_summary_system_prompt()`)
  explicitly forbids inventing facts not in the transcript.
- **UI.** A "Summary" card in the Customer 360 rail (`application/views/admin/inbox/index.php`)
  shows the cached summary (or a loading/error state), a "Regenerate" button, and a fixed
  caption — "AI-generated — verify before acting on it" — per brief §51.
- **AI seam for tests.** `Conversation_summarizer`'s constructor takes an injectable `ai_caller`
  (mirrors `Flow_prompt_generator`), used directly by `tests/summary_billing/run.php` (no
  CodeIgniter, no DB, no network). For the staging HTTP+DB suite, the same seam is reached
  through the config flag `conversation_summary_test_fixture` (`application/config/config.php`,
  default `FALSE`, never set in production) — identical pattern to
  `prompt_to_flow_test_fixture` / `flow_prompt_test_fixture_helper.php`.

## Labels (follow-up)

Labels are keyed to **conversations**, not to `messenger_bot_subscriber`. One label therefore
works on fb, ig, wa (Cloud and OpenWA), tg and web.

- **Tables** (`2026-09-30-inbox-labels.sql`):
  - `inbox_labels (id, user_id, name, color, created_at, updated_at)`, with a UNIQUE name per tenant.
  - `conversation_labels (conversation_id, label_id, user_id, created_by, created_at)`, with
    PK `(conversation_id, label_id)`. `user_id` is repeated on every row, the same rule as
    `internal_notes`.
  - No foreign keys, like the rest of the conversation core.
- **Endpoints**: `labels_list`, `label_save` (create / rename / recolour), `label_delete`,
  `label_attach`, `label_detach`.
  - Every mutation checks CSRF.
  - Every mutation resolves the conversation through `conversation_get($id, $uid)` and the label
    through `inbox_scope_labels()` (tenant first) before it writes. Another tenant's id answers
    "not found".
  - `label_delete` removes the label and all of its `conversation_labels` rows in one transaction.
  - Names go through `inbox_label_normalize_name()`: tags stripped, whitespace collapsed,
    60 characters at most. Colours must be `#rrggbb`, otherwise NULL.
  - A tenant can have at most 200 labels.
- **Filter**: `conversations_data` takes `label_id`. `inbox_scope_conversations()` adds an `EXISTS`
  on `conversation_labels`, after the tenant guard. The subquery is scoped to the same tenant and
  both ids are cast to int, so another tenant's label id matches nothing. List rows and the
  thread return the conversation's labels in one extra query per page.
- **UI**:
  - a label filter and a "Labels" manager (create, rename, recolour, delete) above the list;
  - label badges on each row;
  - a Labels card in the right rail, with add (dropdown) and remove (×).
- **Not built:** labels on a *customer* (across all of a customer's conversations), and any sync
  with the fb-only `messenger_bot_subscribers_label`.

## Canned responses by channel (follow-up)

`canned_response.media_type` is Livechat's fb/ig page category and is **not changed**. Livechat
still filters on `page_id + media_type`, exactly as before.

- A new nullable `canned_response.channels` column (`2026-09-30b-canned-response-channels.sql`)
  lists the conversation platforms a response is offered on, for example `wa,tg`.
- `NULL` means "every channel". Every existing row is NULL, so **existing responses keep showing
  everywhere they showed**. Choosing all five platforms, or none, is also stored as NULL, so
  "all" has one spelling (`inbox_canned_channels_normalize()`).
- `canned_responses` takes an optional `conversation_id`. It is tenant-checked, and the
  conversation's platform filters the rows through `inbox_scope_canned()`: tenant first, then
  `channels IS NULL OR FIND_IN_SET(platform, channels)`. Without it, every row of the tenant
  comes back, which is the old behaviour. Before the migration runs, the column is missing and
  nothing is filtered.
- `canned_save` / `canned_delete` (CSRF, tenant-scoped) let the inbox create, edit and delete
  responses, including their channels.
  - Rows created in the inbox get `page_id = 0`, so Livechat's per-page list never shows them.
  - Editing a Livechat row leaves its `page_id` and `media_type` untouched.
- The composer's "Canned" menu reloads for each conversation, and "Manage…" opens the editor.

## Attachments (follow-up)

**Incoming / stored media.** `inbox_message_render()` reads `livechat_messages.message_content`
and never changes it.

- fb/ig inbound rows are the raw Meta webhook body. fb/ig media sends (Livechat, and the image
  replies below) are Send API bodies. The `http(s)` attachment URLs in both are shown as an
  image, video, audio or file link. Any other scheme (`javascript:`, `data:`,
  scheme-relative) is dropped, and the browser escapes the URL again.
- WhatsApp Cloud, Telegram and OpenWA only ever log `[non-text message]` for media. Those
  channels deliver a media *id*, never a URL (see `docs/channels/adapters.md`), so the bubble says
  the file is not stored instead of inventing one.
- The list preview uses the same parser, so it no longer shows raw webhook JSON.

**Image replies.** `reply_image` (multipart, CSRF, module 411) runs these checks in order:

1. The tenant guard, then the channel account (`resolve_send_account`).
2. `channel_policy_decide(sender='human')`, the same as `reply()`. A denial writes nothing and
   stores nothing.
3. The adapter must declare `send_image` (`docs/channels/adapters.md`): fb, ig, wa_cloud,
   wa_openwa and tg. Web answers "not supported". For fb/ig/wa_cloud/tg the adapter support was
   added in this follow-up, each by its provider's documented API and pinned by the contract
   suite with a fake transport.
4. The upload is validated from its **bytes**:
   - JPEG or PNG only, at most 5 MB, which is the lowest provider limit;
   - `finfo` and `getimagesize` must agree;
   - PHP or HTML markup is refused.
5. The file is stored as `upload/inbox/YYYY/MM/inbox{uid}_{random}.{jpg|png}`. This is the
   `app_upload` volume, where Livechat's files live too, and nginx never executes anything there.
   The extension comes from the detected type.
6. The provider fetches the image by its public URL.
7. The `livechat_messages` row is written only after a successful send, in the Send API image
   shape. Livechat already renders that shape for fb/ig. A failed send deletes the file and writes
   nothing (brief §51).

The attach button appears only when the thread says `can_send_image`. Captions, and video,
audio and file sends, are not built.

## AI suggested reply (follow-up)

`ai_conversation_history` now carries a nullable, indexed `conversation_id`
(`2026-09-30c-ai-history-conversation-id.sql`).

- **Write.** `Home::get_ai_reply_open_ai()` adds it to **new** rows through
  `conversation_ai_history_link()` (`conversation_helper.php`). The Home.php diff is two lines.
  The helper returns `array()` when the column is missing or nothing resolves, so the insert is
  then byte-for-byte what it was. Resolution follows what each channel's AI caller passes as
  `page_id`:
  - fb/ig: the Facebook page id (`conversation_resolve_account`);
  - tg and WA Cloud: the account id;
  - OpenWA: the session id (`openwa_accounts.session_id`);
  - web: the widget key (web conversations are always account 0).

  When the account cannot be resolved, the row is linked only if exactly **one** conversation of
  that tenant and platform has that thread id, so an ambiguous match links nothing. The helper
  never creates a conversation and never throws. An IG comment reply from someone who also DMs
  the account links to that DM conversation, because it shares the AI memory keys already.
- **No backfill.** The table prunes itself at the AI memory TTL (and Cron_hub keeps 60 days at
  most), and only the latest turn is shown. Every existing reader keeps its
  `(user_id, page_id, subscribe_id, social_media)` lookup.
- **Read.** `conversation_thread` returns `ai_draft`: the latest row for
  `user_id = tenant AND conversation_id = this conversation`. It is shown **read-only** in an
  "AI suggested reply" card, with the question it answered and its time. "Use" copies it into
  the composer and sends nothing. The composer is cleared when the agent switches conversation.
- **No new model call.** This is what the AI already produced. In AI_ACTIVE the bot has usually
  sent that reply already, and the card makes it reusable after a takeover.

## Realtime (Pusher) (follow-up)

The v1 page did **not** actually poll: the list and thread only reloaded on an agent action.
This follow-up adds live updates plus an auto-refresh fallback.

- **Event.** After every `livechat_messages` insert (the BEFORE INSERT trigger has already set
  `conversation_id`), `inbox_realtime_notify()` (`application/helpers/inbox_realtime_helper.php`)
  publishes `{conversation_id, message_id, sender}` — ids only, never message content — as event
  `message` on the tenant's PRIVATE channel `private-inbox-<ns>-<tenant user_id>`. `<ns>` is
  derived from the install's `encryption_key`, so two installs sharing one Pusher app never share
  a channel. One event per conversation per request (bursts coalesce).
- **Where it is called.** All eight writers, one line each: `Home::insert_livechat_data` (fb/ig
  in and out, Livechat), `Home::system_message_insert_into_conversation`, `Telegram_bot`,
  `Openwa_bot`, `Whatsapp_bot` (Cloud) and `Webchat` `log_msg()`, `channel_log_message()`
  (`channel_send_helper.php`, bot/sequence/automation sends) and `Inbox::write_agent_message`.
  `tests/inbox/run.php` fails if a new `livechat_messages` insert appears without it.
- **Never breaks message handling.** Web requests queue events and send them once at shutdown,
  after the handler's own work; CLI (cron) sends immediately. The HTTP call is a signed Pusher
  REST request made with curl (`inbox_realtime_batch_request()`, same body and signature as
  `vendor/pusher`, which this app never autoloads) with a 1 s connect and 2 s total timeout
  (`inbox_realtime_timeout`, max 5 s). Every error is caught and logged once
  (`inbox_realtime: publish failed`); after one failure the request stops trying. The lookup query
  runs with `db_debug` off and does not touch the caller's query builder.
- **Channel auth.** `POST inbox/realtime_auth` (Pusher JS `authEndpoint`): needs a session,
  module 411 (constructor) and the CSRF token, and signs **only** the caller's own tenant channel
  (`inbox_realtime_authorize()`, exact match). Anything else is 403. Livechat's
  `livechat_channel` is public and unauthenticated; it is not changed here.
- **Browser.** The page subscribes when realtime is configured; an event for the open
  conversation refreshes the thread, any event refreshes the list (only while no "Load more"
  page is open). Auto-refresh runs every 20 s without realtime and every 90 s while subscribed,
  only when the tab is visible, plus once when the tab becomes visible again.
- **Enabling.** Fill App ID, Key, Secret and Cluster in Settings → Pusher (the existing
  `application/config/pusher.php`). With any of the three empty the page gets `RT = null`, the
  auth endpoint answers 403 and nothing is published.
- **Flags** (config items, all optional): `inbox_realtime_enabled` (FALSE = off even with Pusher
  configured), `inbox_realtime_timeout` (seconds, default 2), `pusher_host` / `pusher_port` /
  `pusher_scheme` (self-hosted or test endpoint), `inbox_poll_seconds` (default 20),
  `inbox_poll_seconds_realtime` (default 90), `inbox_poll_enabled` (FALSE = no auto-refresh).
- **Tests.** `tests/inbox/run.php` (auth tenant isolation, signing vectors, payload has no
  content, batch routing, failure containment, wiring) and
  `docker/scripts/staging_inbox_realtime_tests.sh` on `docker-compose.rt-test.yml` (port 8107,
  fake Pusher API `tests/inbox/fake_pusher.php`).

## Deferred (not built in this phase, and why)

- **Still not built after the follow-up** (see each section above):
  - video, audio and file replies, image captions, and image replies on web;
  - showing wa/tg/OpenWA incoming media, which needs a media-download-by-id step those webhooks
    do not do today;
  - a backfill of `ai_conversation_history.conversation_id`;
  - customer-level labels;
  - Livechat's postback buttons and API-driven sends.
- **Earlier deferrals, now built** (kept here so the history is clear):
  - AI suggested reply: needed `ai_conversation_history` keyed to conversations, which is done.
  - Labels: needed a table keyed to conversations, which is done.
  - Image attachments: needed adapter `send_image` beyond OpenWA, which is done for fb/ig/wa_cloud/tg.
  - Canned responses by channel: needed a platform list outside the fb/ig `media_type` enum. The
    `channels` column provides it.

  The on-demand thread summary (`Inbox::summarize()`, above) is still a separate feature from the
  suggested reply. It summarizes what happened and never proposes what to say.

## Entitlement

Module 411 ("Unified Inbox"), `feature_key = 'inbox'` in `entitlement_map()`
(`application/helpers/entitlement_helper.php`). Every existing non-deleted package gets it by
default (same policy as 408/409/410). Gated by `entitlement_require('inbox')` in
`Inbox::__construct()`; Admin is never blocked; enforcement is the existing global
`entitlement_enforce_custom_modules` switch, unchanged by this phase. The follow-up endpoints
(labels, canned-response management, `reply_image`) are methods on the same controller, so the
same constructor gate covers them. `staging_inbox_tests.sh` checks a member without 411 is
redirected.
