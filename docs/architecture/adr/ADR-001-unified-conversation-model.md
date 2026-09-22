# ADR-001: Unified Conversation Model

- **Status:** Proposed
- **Date:** 2026-09-22
- **References:** brief §11, §12, §13, §16 and §49; baseline sections C and H-02; ADR-000; ADR-002

## Context

There is no conversation entity today. What exists in practice:

- **Messages: `livechat_messages`.** It has 41,926 rows and a `platform` column. This is the closest thing to a unified message store. Analytics already group by `platform`. The fb/ig/wa/tg/web paths write into it, but the Livechat inbox (module 82) shows **only FB and IG**. WhatsApp, Telegram and web-widget conversations are logged but cannot be viewed. Retention deletes rows older than 120 days.
- **Customer: `messenger_bot_subscriber`.** It holds 8,977 rows: FB, IG and WA via `media_type='wa'`. It carries `lead_score`, `bot_paused_until` (the 6-hour human handoff), interaction times, labels and custom fields. WA Cloud, Telegram and web visitors have **no** subscriber row, so they have no lead score, no CRM link and no pause state.
- **AI state is kept separately:**
  - `ai_conversation_history`: model memory, 60-day retention;
  - `ai_reply_audit`;
  - `ai_price_guard_log`.

  None of them is keyed to a conversation ID.
- **Handoff and hours state** are columns on the subscriber (`bot_paused_until`) or on the page/account (`hours_*`). Staff hours are enforced inconsistently across channels.
- **CRM deals** link to subscribers and orders, not to conversations.

As a result, the brief's Universal Inbox, handoff state machine, Customer 360 and analytics cannot be built consistently. Every channel re-implements its own state.

## Decision

Introduce two new tables, created by migration and additive only:

- `conversations`: one row per (tenant, channel account, external customer thread). It holds status (open, pending, closed), assignee, handoff state, automation mode, last message time and a `customer_id`.
- `conversation_messages`: the normalised message shape from brief §11. Fields: direction, sender type (customer, bot, AI, human), content type, text, attachments JSON, the external message ID (unique per channel, for idempotency), delivery status and an AI audit reference.

Rules:

1. **Customer identity.** Keep `messenger_bot_subscriber` as the de-facto customer record for now. `conversations.customer_id` points to it. Create subscriber rows for channels that lack them (WA Cloud, Telegram, web), so that every conversation has a customer. A separate customer and identity table that merges one person across channels is deferred to ADR-006.
2. **Dual write, behind a flag.** The inbound path (ADR-002 adapters) and `channel_send_helper` write to the new tables **and** keep writing `livechat_messages`. Legacy screens keep working unchanged.
3. **Backfill.** Build conversations from `livechat_messages`, grouped by user, platform, page/account and subscriber. Verify the counts per platform (brief §49) before any reader switches over.
4. **Readers switch one at a time:**
   1. Universal Inbox (new);
   2. Livechat;
   3. analytics;
   4. AI history.
   
   `livechat_messages` is retired only after the readers have moved and a deprecation window has passed.
5. **Retention** for the new tables is decided explicitly with the owner. It does not simply inherit the 120-day window.

## Alternatives

| Option | Why not |
|---|---|
| Extend `livechat_messages` in place (add `conversation_id`, status columns) | It has no conversation entity, its columns are legacy and FB-shaped, and its retention is already destructive. It would still need a conversations table. |
| Per-channel inbox screens (add WA, TG and web views to Livechat) | Faster, but multiplies the per-channel divergence the brief is trying to remove. Handoff and hours would stay inconsistent. |
| A new customer table first | Merging identity is hard (the same person on FB and on WA). Blocking conversations on it delays the inbox. It is deferred to ADR-006. |

## Consequences

**Positive:**

- One inbox for all channels.
- One handoff state machine and one automation-mode switch (brief §14, §15).
- Consistent analytics.
- Idempotent inbound processing through a unique external message ID.

**Negative:**

- The dual-write period doubles the write volume on the busiest path.
- The backfill has to deal with FB-page-ID and OpenWA-account-ID collisions, so the account type must be part of every key.
- A migration is needed for the new tables, and subscriber rows must be created for new channels.

**Dependencies:**

- ADR-002, which produces the normalised inbound message.
- An automated backup and staging before the backfill.
