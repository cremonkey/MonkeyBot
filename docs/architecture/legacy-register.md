# Legacy Register — controllers and modules

**Written:** 2026-09-25, for Task 8 of `docs/plans/2026-09-24-remaining-work.md` (brief §35).
**Scope:** every file in `application/controllers/` and every directory in `application/modules/`
on `release/9.x-integration`, classified KEEP / MODERNISE / REPLACE / DEPRECATE / REMOVE.

## How this was classified

- **KEEP** — still the live implementation of a feature, or infrastructure everything else depends
  on (auth, base controller, health, cron dispatch). No planned replacement.
- **MODERNISE** — real, used feature, but built the old way (mega-controller, no interface) and a
  named seam in ADR-000 targets it for extraction into a library/helper behind an interface. Not a
  rewrite; behaviour stays, structure changes.
- **REPLACE** — a newer, ADR-backed mechanism already exists and is meant to take over this
  controller's job (e.g. Channel adapters over per-channel bot controllers, Automation engine over
  ad hoc triggers, Inbox over per-channel chat UIs). The old controller is not dead yet — some of
  its code paths (webhook receivers, sending, admin config) are still called by the new mechanism
  or by legacy screens the new mechanism has not covered — so it is not DEPRECATE/REMOVE today.
- **DEPRECATE** — superseded and believed unused for real (non-test) tenants, but not verified with
  usage evidence (no access logs / feature-flag telemetry were available to this pass). Marked
  uncertain; do not remove without confirming zero traffic first.
- **REMOVE** — dead code: confirmed by grep to have no live callers, no route, no menu entry, no
  cron reference, and no entitlement/module id.

This pass used static evidence only: `grep` for cross-references (routes, menu views, cron_hub,
entitlement ids in `application/helpers/entitlement_helper.php`), file size/shape, and the ADRs.
It did **not** have runtime logs or DB telemetry, so anything not backed by a grep hit is marked
**uncertain** rather than guessed dead. Module ids 400–412 in `entitlement_helper.php` are the
authoritative list of controllers built in the current transformation (Phase 9–11 custom work,
ADR-000/001/002/003); everything with an id there is unambiguously live and current-generation.

---

## Controllers (`application/controllers/`)

### Current-generation, entitlement-gated (module ids 400–412) — all KEEP

| File | Module id | Reason |
|---|---|---|
| `Ai_agents.php` | 400 | AI agent profiles/brand voice; live feature, actively extended (preview/price-guard work, baseline 17). |
| `Crm.php` | 401 | CRM pipeline; core of Phase 10 AI Sales Platform. |
| `Sales_automation.php` | 402 | AI follow-ups; core of Phase 10. |
| `Ai_content_writer.php` | 403 | AI content writer; entitled feature, no successor. |
| `Appointment_booking.php` | 404 | Public booking pages stay open by design (booking must work without login); entitled admin side. |
| `Analytics_hub.php` | 405 | Analytics Hub; entitled, current dashboard. |
| `Reengage.php` | 406 | Re-engagement campaigns; entitled, current. |
| `Missed_questions.php` | 407 | AI unanswered-question review; entitled, tenant-scoped per baseline item 18. |
| `Pricing.php` | 408 | Products & Pricing editor (`pricing_helper.php`); entitled, current. |
| `Automations.php` | 409 | Event-triggered rules UI over `Automation_engine`/`Automation_gateway` (ADR-003); entitled, current. |
| `Onboarding.php` | 410 | Setup Guide wizard (Phase 11); entitled, current, actively extended (scoped pages, price lists). |
| `Inbox.php` | 411 | Unified Inbox (Phase 9.5, ADR-001); entitled, current, actively extended (labels, canned responses, realtime). |
| `Sequences.php` | 412 | Timed step sequences (Phase 10.5); entitled, current, works with `Sequence_worker.php`. |

### Core infrastructure — KEEP

| File | Reason |
|---|---|
| `Home.php` | Base controller every other controller extends; also holds the AI reply engine (`get_ai_reply_open_ai`). Core path — the AI-orchestrator extraction named in ADR-000 targets the engine part, not the base-controller part, so this is **MODERNISE** for the AI engine and **KEEP** for the base-controller role. Listed once here as MODERNISE (see below) to avoid a duplicate row. |
| `Cron_hub.php` | Single scheduler entry point (`php index.php cron_hub ...`) for migrations, backfills, KB rechunk, health checks, secret rotation; referenced throughout the baseline as the only sanctioned way to change schema/run batch jobs. Core path. |
| `Cron_job.php` | Legacy stock cron entry point predating `Cron_hub`. Still present; `Cron_hub` is the documented current path (ADR-000 seam #6 "scheduler jobs"). Uncertain whether any external cron line still calls `Cron_job` directly vs. `Cron_hub` — **DEPRECATE**, verify the crontab before removing. |
| `Health.php` | `/health` endpoint used by the deployment runbook's verification step and the 5-minute health watcher (baseline item 21). Core path. |
| `System_health.php` | Admin-facing view over `ops_health_helper.php` data; pairs with `Health.php`. Core path. |
| `Native_api.php` | 59 lines; thin API surface. No entitlement id, small enough that its purpose is legible in the file — external/native-app auth glue. Kept as infrastructure; **uncertain** whether any client still calls it. |
| `Graph_diagnostics.php` | Admin-only diagnostic tool built for the Graph API v26 migration (baseline, 2026-09-22 final entry): compares legacy vs. candidate Graph API version per page. Actively needed until the owner finishes the v26 rollout. |
| `Change_password.php`, `Myprofile.php` | Account/session management, used by every logged-in tenant. Core path. |
| `Admin.php` | Superadmin console (users, plans, settings). Core path, no successor named in any ADR. |
| `Agency.php`, `Agency_provision.php` | Agency/reseller tier (Phase 11 "Agency SaaS" per baseline roadmap). Current, no entitlement id found but named directly in the roadmap as shipped. |

### Channel bot controllers — mostly REPLACE (ADR-002 channel adapters), some MODERNISE

ADR-002 defines `libraries/Channels/*` (`OutboundMessage`/`InboundMessage` adapters) plus
`channel_adapter_helper.php`/`channel_send_helper.php`/`channel_policy_helper.php` as the new,
single path for sending and receiving on any channel, and ADR-000 lists webhook ingress and
outbound sending as planned seams. These controllers are the pre-adapter, per-channel
implementations the adapters are meant to sit in front of / eventually replace.

| File | Reason |
|---|---|
| `Messenger_bot.php` | 14,840-line FB/IG DM engine (per ADR-000). `Openwa_bot.php` still `extends` it, so it is still the live engine, not dead. REPLACE target for ADR-002/ADR-000, but the extraction has not happened yet — still KEEP-in-practice, listed as REPLACE to record intent. |
| `Openwa_bot.php` | WhatsApp (OpenWA) bot; inherits `Messenger_bot` (see ADR-000 context). Live channel. Same REPLACE target as `Messenger_bot.php`; the inheritance itself is technical debt ADR-002's adapter interface is meant to remove. |
| `Whatsapp_bot.php` | Separate controller alongside `Openwa_bot.php` — likely the WhatsApp Cloud API path (vs. OpenWA's gateway path), given baseline mentions "WhatsApp Cloud + Telegram" media storage as a distinct integration. REPLACE target once channel adapters cover WhatsApp Cloud fully; **uncertain** on current traffic split between this and `Openwa_bot.php`. |
| `Telegram_bot.php` | 138 lines; Telegram channel, explicitly named in baseline ("Telegram voice transcription only when the bot's AI is on") as a live, recently-touched channel. REPLACE target for the channel-adapter work, still live today. |
| `Webchat.php` | Website chat widget channel. Same REPLACE target; no evidence found either way for current usage — **uncertain**. |
| `Instagram_reply.php` | 4,273 lines; Instagram comment/DM auto-reply, has its own `menu_block.php` view (found in `application/views/instagram_reply/`), so it is wired into the UI. Overlaps with `Comment_automation.php` and the newer channel adapters. REPLACE target; live today (comment auto-reply is called out as working correctly in baseline's 2026-09-23 follow-up round). |
| `Comment_automation.php` | Listed in ADR-000/plan §1 "Huge shared files" table as an active file to be careful editing — confirms it is live and large. REPLACE/MODERNISE target for the same channel-adapter seam; not dead. |
| `Messenger_bot_broadcast.php` | 3,159 lines; broadcast/blast sending over the Messenger channel, sits alongside `Messenger_bot.php`. Same REPLACE target once `channel_send_helper.php` covers broadcast sends; **uncertain** current usage level (no cron/menu grep hit), but broadcast is a marketed XeroChat feature, so treated as live rather than dead. |
| `Messenger_bot_analytics.php` | Small (244 lines) companion reporting controller to `Messenger_bot.php`. KEEP as long as `Messenger_bot.php` is live; retires together with it. |
| `Subscriber_manager.php` | 2,227 lines; subscriber/segment management for Messenger broadcast (has its own `subscriber_manager_menu_block.php` view — confirmed wired into the UI). REPLACE target if/when Unified Inbox's customer model (ADR-001 `customers`/`customer_identities`) absorbs subscriber management; today it is the live implementation. |

### AI reply engine inside `Home.php` — MODERNISE

`Home.php`'s `get_ai_reply_open_ai` is explicitly named in ADR-000 as a planned extraction ("the AI
orchestrator, extracted out of `Home.php`"). It is the core, live AI reply path for every channel
today (confirmed live and actively modified — baseline items 17a/17b/17c are all patches to this
same code path via `Ai_provider`). MODERNISE: extract behind an interface per ADR-000 step 2,
without changing behaviour.

### E-commerce / payments — KEEP, one MODERNISE

| File | Reason |
|---|---|
| `Ecommerce.php` | 20,217 lines — the single largest controller in the codebase. Stock XeroChat e-commerce/storefront feature for tenants selling through the bot. No ADR names it as a seam target and no evidence it's dead (its size argues for active, if organically-grown, use). KEEP; candidate for a future MODERNISE pass not yet scoped. |
| `Stripe_action.php` | 4,993 lines; Stripe payment handling for tenant subscriptions/billing (core revenue path — this is how MonkeyBot itself gets paid). KEEP, core path. |
| `Payment.php` | 2,361 lines; payment gateway orchestration alongside Stripe/PayPal. KEEP, core path. |
| `Paypal_ipn.php` | PayPal IPN webhook receiver; billing-critical. KEEP, core path. Webhook ingress is a named ADR-000 seam (`webhook_security_helper`), so this endpoint is a MODERNISE candidate for that seam specifically, but the controller itself stays. |

### Communication / broadcast tools — mostly MODERNISE or uncertain KEEP

| File | Reason |
|---|---|
| `Sms_email_manager.php` | 14,621 lines — second largest file in the repo. SMS/email broadcast tool. No entitlement id, no ADR mention, no grep hit confirming current use, but its sheer size argues it is a real, organically-grown live feature rather than dead code. **Uncertain** — flagged for the owner to confirm current usage before any structural work; treated as KEEP pending that confirmation, not REMOVE. |
| `Email_auto_responder_integration.php` | 2,153 lines; third-party ESP integration (Mailchimp/ActiveCampaign-style). No ADR mention, no grep hit. **Uncertain**, same reasoning as above — KEEP pending confirmation. |
| `Autoposting.php` | 969 lines; social autoposting to pages, distinct from the `instagram_poster`/`ultrapost` modules below. Overlapping functionality with those modules suggests one may be superseding the other. **Uncertain** which is current — flagged for the owner; no grep evidence to pick a REPLACE direction confidently. |
| `Social_apps.php` | 962 lines; app-level (not per-page) social integration config — Facebook/Instagram/etc. app credentials. Core path (every channel controller above depends on these credentials existing). KEEP. |
| `Social_accounts.php` | Per-tenant connected page/account management (also named in plan §1 "CRLF files to preserve" list, confirming it's actively maintained). KEEP, core path. |
| `Integration.php` | 397 lines; generic third-party integration hub. No strong evidence either way. **Uncertain** — KEEP pending confirmation. |
| `Custom_theme_manager.php` | 143 lines; small, theme customization. **Uncertain** usage — no grep hit, no ADR mention. Likely low-risk legacy feature; KEEP pending confirmation, DEPRECATE candidate. |
| `Themes.php` | 236 lines; paired with `Custom_theme_manager.php`. Same uncertain status. |
| `Multi_language.php` | 1,438 lines; UI translation management — large enough to suggest real use (many languages configured). KEEP. |
| `Webview_builder.php` | 1,035 lines; Messenger webview page builder (persistent menu / webview screens — has `persistent_menu*.php` views confirmed in `application/views/messenger_tools/`). Tied to `Messenger_bot.php`'s webview features. Same REPLACE trajectory as the Messenger controllers, live today. |
| `Flow_prompt.php` | 119 lines; small. Baseline's roadmap explicitly names "prompt-to-flow" as part of the final consolidation batch ("staged and tested but not yet deployed") — this is that current feature. KEEP. |
| `Calendar.php` | 234 lines; scheduling calendar, likely paired with `Appointment_booking.php` (404) or broadcast scheduling. **Uncertain** which; KEEP pending confirmation. |
| `Announcement.php` | 395 lines; in-app announcements to tenants (owner/admin → tenant broadcast, not customer-facing). KEEP, low-risk admin tool. |
| `Page_analytics.php` | 1,017 lines; per-page analytics, distinct from `Analytics_hub.php` (405). Possible overlap — **uncertain** whether `Analytics_hub` has superseded this. Flagged REPLACE-candidate, not confirmed. |
| `Demo_account_delete.php` | 477 lines; sandbox/demo tenant cleanup. Operationally useful (sales demos), no successor. KEEP. |
| `Update_system.php` | 626 lines; in-app self-update mechanism from the original commercial CodeCanyon product (XeroChat ships as a downloadable script with a built-in updater). This deployment is git/Docker-based (see `docs/runbooks/deployment.md`), so an in-app file-replacing self-updater is actively dangerous here (bypasses the git/backup/health-check flow). **DEPRECATE** — recommend disabling access to this controller for this deployment; do not remove outright without confirming no other code path depends on its tables. |
| `Xtra.php` | 219 lines; grab-bag of small stock utility endpoints typical of CodeCanyon scripts. **Uncertain** contents — flagged for a closer read before any action; DEPRECATE candidate. |
| `Addons.php` | Stock CodeCanyon "addon store" screen (buy/install optional modules from the vendor's marketplace). This deployment doesn't sell or install marketplace addons independently — **DEPRECATE** candidate, uncertain if fully inert. |

---

## Modules (`application/modules/`, HMVC)

| Module | Classification | Reason |
|---|---|---|
| `menu_manager` | KEEP | Builds the dynamic sidebar (`menu_manager.php`/`menu_manager_js.php`/`menu_block.php` views exist and are referenced by other modules' own `*_menu_block.php` views, e.g. `ultrapost/views/poster_menu_block.php`). Core UI infrastructure every other screen depends on. |
| `ai_knowledge_base` | KEEP | Knowledge base for AI grounding; directly referenced by `ai_knowledge_helper.php` and the price-grounding/judge work (baseline items 17b, backfill_embeddings). Core, actively extended. |
| `ai_reply` | KEEP or MODERNISE | Likely the HMVC-side counterpart/legacy predecessor of the AI reply engine now mostly living in `Home.php`'s `get_ai_reply_open_ai` and `libraries/Ai_provider.php`. **Uncertain** exact division of responsibility between this module and `Home.php` — flagged for a closer read; treat as MODERNISE target alongside the `Home.php` AI engine extraction (ADR-000) rather than guessing it's dead. |
| `instagram_bot` | REPLACE | Overlaps with `Instagram_reply.php` and the channel-adapter direction (ADR-002). Same trajectory as the other per-channel bot controllers — live, but the intended long-term home for this logic is the adapter interface. |
| `instagram_poster` | REPLACE / uncertain | Overlaps with `Autoposting.php` and `ultrapost`. Three separate places that could post to Instagram is a strong signal of accumulated, non-consolidated features. **Uncertain** which is authoritative today — flagged for the owner. |
| `tiktok_bot` | KEEP, uncertain volume | Only TikTok-specific controller/module in the codebase — no competing implementation found. Likely a smaller, less-trafficked channel. KEEP; **uncertain** how actively used given no baseline/ADR mention. |
| `ultrapost` | REPLACE / uncertain | Cross-platform "post everywhere" tool (has its own `poster_menu_block.php`, confirming UI wiring), overlapping with `Autoposting.php` and `instagram_poster`. Same "three implementations" flag as above. |
| `visual_flow_builder` | KEEP | Directly related to `Flow_prompt.php` and the baseline's "prompt-to-flow" feature (part of the final consolidation batch, staged/tested). Current, actively developed. |
| `simplesupport` | KEEP, uncertain | Support-ticket style module bundled with the base product. No ADR/baseline mention either way. **Uncertain** usage; treated as KEEP (low risk, not flagged as superseded by anything). |
| `blog` | KEEP, uncertain | Tenant-facing blog module (has `blog_sidebar.php` view, confirming it renders). Marketing/content feature unrelated to the bot core. **Uncertain** usage level — no grep evidence of being wired into the main menu; flagged for the owner rather than assumed dead. |

---

## Summary counts

| Classification | Controllers | Modules |
|---|---|---|
| KEEP (confident) | 21 | 5 |
| KEEP (uncertain, pending owner confirmation) | 8 | 2 |
| MODERNISE | 2 (`Home.php` AI engine, webhook ingress in `Paypal_ipn.php`) | 1 (`ai_reply`, jointly with the above) |
| REPLACE (ADR-002/ADR-003 target, still live) | 10 | 3 |
| DEPRECATE (superseded or unsafe here, not verified unused) | 3 (`Update_system.php`, `Xtra.php`, `Addons.php`) | 0 |
| REMOVE (confirmed dead) | 0 | 0 |

No controller or module met the REMOVE bar (a confirmed absence of routes, menu entries, cron
references and entitlement ids) in this static pass. That is a real finding, not a gap: this
codebase has no access-log or feature-flag telemetry available to distinguish "never called" from
"called rarely by an old tenant." Before deleting anything, add basic call-counting (e.g. a log line
on controller construction, reviewed over a full billing cycle) rather than relying on grep alone.
