# SPEC-19 — Re-engagement Broadcast

**Status:** approved, in execution
**Date:** 2026-07-09
**Owner:** at.momtaz@gmail.com

Re-message customers who previously contacted the page on Facebook or Instagram.
Target a selected subset, schedule the send, and pace it so the page is not harmed.

---

## 1. Why this is not a simple broadcast

Meta's Messenger / IG Messaging policy allows a business to send freely only inside
the **24-hour window** that opens when a customer sends a message. Outside it:

- `HUMAN_AGENT` extends to 7 days, but **only for human-composed replies**.
  Automated sends under this tag are an explicit policy violation.
- `CONFIRMED_EVENT_UPDATE`, `ACCOUNT_UPDATE`, `POST_PURCHASE_UPDATE` were
  deprecated and return error 100 as of 2026-04-27. Any code using them is dead.
- The legitimate paths beyond 24h are Marketing Messages (opt-in) and Utility
  Templates. Neither is in scope here.

Consequence: a pause interval does **not** protect the page. Rate limiting guards
Graph API quotas and reply-handling capacity. What gets a page restricted is sending
outside the window at all — detected on the first message, regardless of pace.

Since the target audience is by definition "everyone who ever messaged us", nearly
all of them are out-of-window. The engine therefore **gates by eligibility** and
queues the rest.

Losing page messaging would take `pages_messaging` and `instagram_manage_messages`
with it, which disables the AI sales bot, IG DM replies, and CRM lead capture.
This constraint is load-bearing, not decorative.

## 2. Decisions

| Decision | Choice |
|---|---|
| Compliance | Eligibility gate. Only `in_window` sends automatically. |
| Audience | Import all prior FB/IG contacts. Out-of-window contacts queue. |
| Targeting | Channel + page, last-interaction recency, lead score / CRM stage, labels. Exclusions mandatory. |
| Pacing | Self-correcting hourly rate budget + jitter + quiet hours + daily cap. |
| Content | Text + buttons, or product carousel. Optional A/B. |
| Architecture | New standalone module `reengage_*`. Existing broadcast engine untouched. |
| Re-entry | Wait for conversation to go idle (default 30 min), then send inside the window. |

Rejected: extending `messenger_bot_broadcast_serial` (carries dead OTN/RCN columns,
its cron function is shared by 6 other files, 0 rows so nothing to migrate).

## 3. Blocker

**Host cron is not installed.** `/etc/cron.d/` contains only `e2scrub_all`,
`staticroute`, `sysstat`. `docker/cron/monkeybot` is ready and requires:

```
sudo cp docker/cron/monkeybot /etc/cron.d/ && sudo chmod 644 /etc/cron.d/monkeybot
```

Until then this feature sends nothing — and SPEC-06 follow-ups / daily digest are
also inert. Claude cannot install this (classifier-blocked).

## 4. Data model

### `reengage_campaign`
`id`, `user_id`, `name`, `social_media` enum(fb,ig), `page_table_id`, `fb_page_id`,
`message_json` mediumtext, `variant_b_json` mediumtext NULL, `filters_json` text,
`messages_per_hour` int default 60, `jitter_min_sec` int default 2,
`jitter_max_sec` int default 8, `quiet_start` time, `quiet_end` time,
`timezone` varchar(64), `daily_cap` int default 500, `schedule_time` datetime,
`queue_ttl_days` int default 30, `reentry_idle_minutes` int default 30,
`status` enum(draft,scheduled,running,paused,done,halted) default draft,
`halt_reason` text, `consecutive_errors` int default 0, `created_at`, `completed_at`.

### `reengage_recipient`
`id`, `campaign_id`, `user_id`, `subscriber_auto_id`, `subscribe_id`,
`page_table_id`, `eligibility` enum(in_window,human_agent,out_of_window),
`state` enum(pending,sending,waiting_reentry,reentered,sent,skipped,failed,expired,fulfilled),
`ab_variant` char(1) NULL, `queued_at`, `reentered_at`, `sent_at`,
`expires_at`, `error_code` varchar(20), `error_message` tinytext,
`message_sent_id` varchar(200).
Unique key `(campaign_id, subscriber_auto_id)`. Index `(campaign_id, state)`.

### `reengage_import_run`
`id`, `user_id`, `page_table_id`, `social_media`, `cursor` text, `imported_count`,
`thread_count`, `status` enum(running,done,failed), `error_message`,
`started_at`, `finished_at`.

### `reengage_optout`
`id`, `user_id`, `subscribe_id`, `social_media`, `created_at`.
Unique key `(user_id, subscribe_id)`.

`eligibility` is a fact about the customer; `state` is the row's position in the
campaign lifecycle. They are separate because eligibility is **recomputed at send
time**, not trusted from list-build time — hours can pass between the two.

## 5. Eligibility classifier

Pure function, no DB and no network, so it can be tested in isolation.
Lives in `application/helpers/reengage_helper.php`.

```
reengage_classify($last_inbound_time, $now):
    invalid / empty / '0000-00-00 00:00:00' / future  -> 'out_of_window'
    delta <= 24h  -> 'in_window'
    delta <= 7d   -> 'human_agent'
    else          -> 'out_of_window'
```

The engine sends **only** to `in_window`.
`human_agent` is surfaced in the UI as "reply by hand from Livechat" and never sent
automatically. `out_of_window` is queued.

Unsafe input resolves to `out_of_window`. The safe default is "not eligible".
Note `messenger_bot_subscriber` already contains a `0000-00-00 00:00:00` row.

## 6. Import from Graph

`Fb_rx_login` already calls `/{page_id}/conversations` for both platforms
(`&platform=instagram` for IG), used by Livechat.

**`updated_time` is the wrong field.** It reflects the last message in the thread
*from either side*. If the page replied yesterday to a customer who last wrote three
months ago, `updated_time` is yesterday — the classifier would say `in_window` and
the send would be rejected as a violation.

The importer instead requests:

```
fields=participants,updated_time,messages.limit(25){from,created_time}
```

and takes the newest message where `from.id != page_id` as
`last_subscriber_interaction_time`. If no inbound message appears in the last 25,
the contact is recorded as out-of-window (conservative).

Upserts into `messenger_bot_subscriber` with `is_imported='1'`, correct
`social_media`, matched on `(user_id, page_table_id, subscribe_id)`.
Paginates via `paging.next`, persisting the cursor in `reengage_import_run` so an
interrupted import resumes instead of restarting.

## 7. Sender cron

Registered inside `Cron_hub::run` (which the host cron already calls every 5 min).

Rate budget is derived from reality rather than from a "last tick" timestamp, so a
missed tick can never produce a burst:

```
allowed = messages_per_hour - COUNT(sent_at > now - 1 hour)
allowed = min(allowed, daily_cap - COUNT(sent_at >= today_start in page tz))
```

Per tick:

```
if now inside quiet hours              -> return
if status != 'running'                 -> return
if now < schedule_time                 -> return
allowed = rate_budget()
for each pending recipient, max `allowed`, max 60 seconds wall clock:
    claim row: UPDATE ... SET state='sending' WHERE id=? AND state='pending'
               (affected_rows == 0 -> another tick owns it, skip)
    recompute eligibility from last_subscriber_interaction_time
    if not in_window                   -> state='waiting_reentry'; continue
    if reentered and idle < reentry_idle_minutes -> release to 'reentered'; continue
    send()
    sleep(rand(jitter_min_sec, jitter_max_sec))
```

The 60-second ceiling keeps a tick from overlapping the next one (cron is every
5 min, and the cron line already carries `--max-time 280`).

Expiry: rows older than `queue_ttl_days` become `expired` and never send.

### Error handling

| Code | Meaning | Action |
|---|---|---|
| 551 | user unavailable | mark subscriber `unavailable='1'`, row `skipped` |
| 10 / subcode 2018278 | outside allowed window | row `waiting_reentry` (classifier lost a race) |
| 613, 4 | rate limited | abort tick, retry next tick, no state change |
| 100 | bad param / deprecated tag | campaign `halted` — this is a code bug |
| 200 | missing permission | campaign `halted` |

**5 consecutive hard errors halts the campaign** and records `halt_reason`.
If our understanding of Meta policy is wrong, the campaign stops at error 5,
not error 500.

## 8. Re-entry trigger

At the point where the webhook already updates `last_subscriber_interaction_time`
(`Messenger_bot.php:12352`), also flip that subscriber's `waiting_reentry` rows to
`reentered` and stamp `reentered_at`.

No sending from the webhook — it must ACK Meta fast (this codebase previously had
duplicate replies from exactly that mistake; see `fastcgi_finish_request` in
`central_webhook_callback`). The cron does the send.

The queued message goes out only once the conversation has been quiet for
`reentry_idle_minutes` and the contact is still inside the 24h window. If the
conversation runs long enough that the window closes, the row returns to
`waiting_reentry`. This prevents a canned promo landing in the middle of a live AI
sales conversation.

## 9. Opt-out

Every campaign message appends an opt-out line (e.g. «لو مش عايز رسايل تانية ابعت
**إيقاف**»). The webhook matches the stop keyword (ar + en) and inserts into
`reengage_optout` immediately.

Mandatory, always-on exclusions applied at list-build **and** re-checked at send:
`status='0'`, `unavailable='1'`, `bot_paused_until > NOW()` (human handoff in
progress), and any `reengage_optout` row.

Customer complaints restrict a page faster than anything else. This is not optional.

## 10. UI

New `menu` row **Re-engagement**, following the established pattern
(controller extends `Home`, view, `menu` INSERT, migration in
`assets/backup_db/migrations/`).

**Campaign builder** — channel/page, filters, message (text + buttons, or catalog
carousel via the existing `search_products`), schedule + pacing.

The central element is a live **reach counter**, recomputed on every filter change:

```
427 contacts match
  ├─  12  inside 24h window   -> will send now
  ├─  31  1-7 days            -> needs a human reply (link to Livechat)
  ├─ 368  outside window      -> queued until they message you
  └─  16  excluded            -> 9 opted out, 5 with a human agent, 2 unavailable
```

Without this number a campaign "succeeds" having sent 12 messages out of 427.

**Report page** — status, sent/queued/failed, A/B performance, real Meta error rows.

## 11. Operational safety

- **Dry run is the default.** New campaigns are `draft`. "Calculate audience" builds
  the full `reengage_recipient` set and the counter above, sending nothing. Moving to
  `running` requires a second explicit action.
- **Kill switch.** Pause writes `status` to the DB; the cron reads it every tick.
  Worst-case latency 5 minutes.
- **Concurrency lock.** Rows are claimed with a conditional UPDATE checking
  `affected_rows`, so overlapping ticks cannot double-send.

## 12. Verification plan

No PHPUnit in this project. Verification follows the established pattern
(real curl, temp user, DB inspection). In order:

1. **Classifier** — script over edge cases: `23:59`, `24:01`, `6d23h`, `7d01h`,
   `0000-00-00 00:00:00`, future timestamp, empty string.
2. **Import** — against the real page. Compare Graph thread count to imported rows.
   Manually verify for one thread that `last_subscriber_interaction_time` came from
   the last inbound message, not `updated_time`.
3. **Budget + jitter** — dummy campaign, `messages_per_hour=6`, channel `web`
   (webchat touches Meta not at all and is already a working test harness).
   Confirm exactly 6 sends in an hour. **All pacing logic is proven here before a
   single message touches the real page.**
4. **Real send** — one `in_window` subscriber (the operator). Then two. Then live.
5. **Re-entry** — force a row to `waiting_reentry`, message from a test account,
   wait `reentry_idle_minutes`, confirm the queued message sent *after* the AI reply.

## 13. Constraints

- Container runs **PHP 7.4.33**. No `match`, named arguments, `?->`, or constructor
  promotion.
- `application/config` is a Docker named volume — the live config is not the repo
  copy. Edit via `docker cp` in+out and mirror to the repo.
- Lint: `docker exec monkeybot-app-1 php -l <path>`.
- Display errors are off (`ENVIRONMENT=production`), but any PHP notice still
  corrupts AJAX JSON. Check `application/logs` first when AJAX fails.
- Commit per task. Do not delegate production changes to subagents.
