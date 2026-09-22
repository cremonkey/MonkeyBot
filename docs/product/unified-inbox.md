# Unified Inbox (Phase 9.5)

- **Brief:** §13 (Universal Inbox), §14 (handoff state machine), §46 (owner-language UX)
- **Built on:** ADR-001 (`docs/architecture/adr/ADR-001-unified-conversation-model.md`),
  `docs/database/conversation-core.md`, `docs/channels/adapters.md`
- **Code:** `application/controllers/Inbox.php`, `application/helpers/inbox_helper.php`,
  `application/views/admin/inbox/index.php`; AI summary (brief §13):
  `application/libraries/Conversation_summarizer.php`, `application/helpers/conversation_summary_helper.php`
- **Migration:** `assets/backup_db/migrations/2026-09-25-unified-inbox.sql`,
  `assets/backup_db/migrations/2026-09-28-conversation-summary.sql` (adds `conversations.last_summary`/`last_summary_at`)
- **Tests:** `tests/inbox/run.php` (unit, no DB), `docker/scripts/staging_inbox_tests.sh` (HTTP+DB);
  AI summary: `tests/summary_billing/run.php` (unit, no DB), `docker/scripts/staging_summary_billing_tests.sh` (HTTP+DB)

## What this is

The first UI surface built directly on the conversation core: one list, for every platform
(fb, ig, wa, tg, web), backed by `conversations` + `customers` + `customer_identities`, with
explicit handoff states (AI_ACTIVE / WAITING_FOR_HUMAN / HUMAN_ACTIVE / AI_ASSIST / RESOLVED),
assignment, internal notes and a Customer 360 rail (name, phone, email, channel identity).

It is a **read+reply surface only**. It does not touch how messages are received, how the AI
decides to reply, or how `bot_paused_until` is honoured per channel — those stay exactly as
implemented in `Messenger_bot`, `Openwa_bot`, `Whatsapp_bot`, `Telegram_bot`, `Webchat` and
`Home.php`, none of which this phase modifies.

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
  is a larger effort than Phase 9.5's scope. Attachments, buttons and realtime are explicitly
  **deferred** here (see below) rather than half-built into the new surface.
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

## Deferred (not built in this phase, and why)

- **Realtime (Pusher).** Livechat.php's existing `livechat_channel`/`livechat_event` wiring is
  fb/ig-shaped (keyed by `page_table_id`+`subscribe_id`). Extending it to a channel-agnostic
  `conversation_id` event for wa/tg/web is real work with its own risk (double-delivery,
  ordering). The brief explicitly allows polling as an acceptable v1. **v1 ships with polling**
  (`conversations_data` / `conversation_thread` are cheap, indexed queries); a Pusher channel
  keyed on `conversation_id` is the natural follow-up.
- **AI-suggested-reply generation.** The brief asks for a read-only display of a recent AI
  draft from `ai_conversation_history` if one exists — not new AI infrastructure. This is
  **still** not wired: `ai_conversation_history` is not keyed to `conversation_id` (ADR-001,
  "AI state... is not keyed to any conversation"), and retrofitting it with that column plus a
  backfill is a bigger, separate ADR-001-scope change, left for the pass that keys AI state to
  conversations.
  The closest safe implementation shipped instead, as a **different, narrower feature**:
  `Inbox::summarize()` (brief §13) generates a short, factual, on-demand **periodic summary**
  of the thread — what the customer wants, key stated facts, status, open questions — straight
  from `livechat_messages` (already linked via the Phase 9.5 trigger), cached on
  `conversations.last_summary`/`last_summary_at` and only regenerated when a newer message has
  arrived or the agent clicks "Regenerate". This is explicitly **not** a suggested-reply draft:
  it never proposes what to say next, only summarizes what already happened, and is always
  shown with a "AI-generated — verify before acting on it" caption (brief §51). See
  `application/libraries/Conversation_summarizer.php` and
  `application/helpers/conversation_summary_helper.php`.
- **Labels.** The only existing label table, `messenger_bot_subscribers_label`, keys off
  `messenger_bot_subscriber.id` — a row that doesn't exist for wa_cloud/tg/web customers
  (ADR-001). Reusing it would only work for fb/ig and silently exclude the other three
  channels from a channel-agnostic feature, which is worse than not having labels yet. Skipped
  per the brief's own "else skip (note as TODO)" allowance. A real fix needs a label table keyed
  to `conversations`/`customers`, not `messenger_bot_subscriber`.
- **Attachments / media replies.** Text only. `Livechat.php`'s upload pipeline
  (`livechat_upload_file`) is fb/ig-specific (`display_sent_message`-driven rendering) and
  channel adapters currently implement `send_image` for OpenWA only (`docs/channels/adapters.md`
  capability matrix) — a channel-agnostic attachment composer is follow-up work, not a
  same-phase addition.
- **Canned responses are shown unfiltered by channel.** `canned_response.media_type` is
  `enum('fb','ig')` in the schema — there is no wa/tg/web category to filter by. Rather than
  inventing one, `Inbox::canned_responses()` returns every canned response for the tenant
  regardless of platform. Documented simplification, not a bug.

## Entitlement

Module 411 ("Unified Inbox"), `feature_key = 'inbox'` in `entitlement_map()`
(`application/helpers/entitlement_helper.php`). Every existing non-deleted package gets it by
default (same policy as 408/409/410). Gated by `entitlement_require('inbox')` in
`Inbox::__construct()`; Admin is never blocked; enforcement is the existing global
`entitlement_enforce_custom_modules` switch, unchanged by this phase.
