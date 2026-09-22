# Channel Capability Matrix

- **Baseline:** 2026-09-22
- **Fields:** from brief §6.
- **Source:** the audit fact sheet, plus a code read. Nothing here was re-tested live.
- "(inferred)" marks a conclusion drawn from the code or the data, not from a test.

## Status per channel (brief §59 labels)

| Channel | Status |
|---|---|
| FB Messenger | Verified Live |
| IG DM | Verified Live |
| IG Comments | Verified Live |
| FB Comments | Verified Live |
| OpenWA (WhatsApp Web) | Verified Live |
| WhatsApp Cloud | Implemented / awaiting activation |
| Telegram | Implemented / awaiting activation |
| Web Widget | Partial |
| TikTok | Broken / Risky |

## Messaging channels (DMs)

| Field | FB Messenger | IG DM | OpenWA | WhatsApp Cloud | Telegram | Web Widget |
|---|---|---|---|---|---|---|
| Connected accounts | 8 pages (5 with the bot on) | Through the connected pages; number of IG accounts not captured | 1 | 0 | 0 | 2 widgets |
| Webhook / ingress | `home/central_webhook_callback` → `messenger_bot/webhook_callback_main` | Same as FB Messenger | `/openwa_bot/webhook/{id}` | Per-account verify token | `setWebhook` done automatically at setup | `widget/send`, `widget/poll` (CORS, rate-limited) |
| Signature verification | `X-Hub-Signature-256`, **log mode**. The internal forward is signed with `X-MB-Internal` (log). | Same as FB Messenger | HMAC `X-OpenWA-Signature` | HMAC-SHA256, fails closed | Secret-token header | None (public); rate limits of 40/min per tenant, 12/min per session, 8 new sessions/min per IP |
| Webhook working | Yes (live traffic; latest livechat message 2026-09-19) | Yes (77 IG bot replies, 10 IG subscribers) | Yes (163 logged messages) | Not proven (0 accounts) | Not proven (0 accounts) | Yes (466 messages) |
| Incoming messages | Text, postbacks, quick replies, attachments; voice goes to Whisper | Same as FB Messenger | Text (and voice, inferred) | Text; voice goes to Whisper | Text | Text |
| Outgoing messages | Text, media, buttons, carousels, templates, OTN/RCN (deprecated, inferred) | Text, media, buttons, carousels | Text plus image. Carousels and buttons are flattened to text. | Text only | Text only | Text only |
| Automation (keywords, flows, sequences) | Full engine: keywords, no-match, flows, drip, labels, input flows, persistent menu | Full engine (inside `Messenger_bot`) | Full Messenger engine through `media_type='wa'` | None | None | None |
| AI replies | Yes: agent profile, RAG, tools, guards | Yes | Yes (seeded "OpenWA No Match (AI)" bot) | Yes, AI-only | Yes, AI-only | Yes (AI on/off per widget) |
| Inbox (Livechat) | Yes | Yes | **No** (logged, cannot be viewed) | No | No | **No agent reply screen** |
| Human handoff (6h pause) | Yes: Livechat toggle plus the AI `handoff_to_human` tool | Yes | Yes (pause gate in the pipeline) | No (no subscriber row, inferred) | No (no subscriber row, inferred) | No (assigned agent only, inferred) |
| Staff-hours gate | Yes | Yes | Yes | No | No | No |
| Subscriber / customer row | `messenger_bot_subscriber` (8,908) | `messenger_bot_subscriber` (10) | `messenger_bot_subscriber` (59, `media_type='wa'`) | None | None | None |
| Scheduler dependency | Follow-ups, re-engage and digest (`cron_hub`); drip sequences and broadcasts (`cron_job`, **not scheduled**) | Same as FB Messenger | Follow-ups, alerts and digest (through `channel_send_helper`, wa) | Outbound via `channel_send_helper` fallback only | `channel_send_helper` (tg) | Re-engage (web) |
| Graph / API version | v20.0 at the call sites (v26 phase 3 not started) | v20.0 | OpenWA API (self-hosted, `wa.cremonkey.com`) | Hardcoded v21.0 | Bot API | Not applicable |

## Comment channels

| Field | FB Comments | IG Comments | TikTok Comments |
|---|---|---|---|
| Connected accounts | Through the 8 pages | Through the connected pages | 0 |
| Webhook / ingress | Central callback → `comment_automation/webhook_callback_main` | Central callback → `instagram_reply/webhook_callback` | **Polling**: `tiktok_cron poll_comments` every 5 minutes |
| Signature verification | Same as the Meta DMs (log mode) | Same as the Meta DMs (log mode) | OAuth; the cron is CLI-only |
| Webhook working | Yes (live) | Yes (live) | Not proven (0 accounts) |
| Incoming | Comments, feed events | Comments, mentions | Comments (only in `business` API mode) |
| Outgoing | Public reply, private reply (DM), hide/delete, like/share | Public reply, private reply, hide/delete of offensive comments | Reply (fixed text or AI) in `business` mode only. The default `display` mode can only list videos. |
| Automation | Per-post and page-wide rules, keyword filters, random delay, auto-comment campaigns, templates, reports | Per-post and account-wide rules, mention replies, templates, reports, hashtag search | Comment-reply campaigns |
| AI replies | Yes: a short public reply plus the full answer by DM | Yes: a short public reply plus the full answer by DM | Yes (AI campaign option) |
| Inbox | No (reports only); the DM follow-up appears in Livechat | No (reports only) | No |
| Human handoff | No (inferred) | Through the DM side only (inferred) | No |
| Staff-hours gate | **No** | Yes | No |
| Scheduler dependency | Legacy `auto_comment/like/share` in `cron_job` (**not scheduled**) | None for replies | **Yes**: `poll_comments`. `poll_dms` is stubbed and not scheduled. |

## Cross-channel notes

- **Outbound adapter embryo.** `channel_send_helper` sends to fb/ig (Graph), wa (OpenWA first, then Cloud), tg and web. The cron jobs, AI tools and Re-engage use it (see ADR-002).
- **De-facto message store.** Messages are logged in `livechat_messages` with a `platform` column, but only FB and IG can be viewed (see ADR-001).
- **Human pause.** `bot_paused_until` lives on `messenger_bot_subscriber`, so channels without subscriber rows cannot be paused.
- **Staff hours.** They are enabled on 0 pages today. The semantics are "staff on shift → bot silent".
- **Known channel risks:**
  - OTN deprecation (inferred);
  - the OpenWA/FB-page ID-collision risk in flows (inferred);
  - the WhatsApp Cloud menu item is visible with 0 accounts;
  - deleting a Telegram account leaves its webhook registered;
  - the broken "Flow Builder" sidebar link.
