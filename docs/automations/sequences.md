# Sequences

**Phase 10.5** — brief §25 (Sequences), built on §22 (Event Engine), §23 (Automation Engine),
§26 (Channel Policy), §46 (owner language), §50 (feature flags), §51 (no silent failure).
Cross-reference: `docs/automations/engine.md`, `docs/architecture/adr/ADR-003-event-engine.md`.

---

## 1. What it is

A **sequence** is a named, ordered list of timed steps. A customer gets **enrolled** in a
sequence (by hand, or by an Automation Rule's `start_sequence` action); from that moment, each
step fires at its own offset from enrollment:

```
Hotel Lead Follow-up
  T +10 minutes   Help message
  T +6 hours      Offer reminder
  T +1 day        Create a follow-up task for the team
  T +3 days       Re-engage — if the channel allows it
```

This is the brief's own example (§25), and it is deliberately the product's ONE concept for
"do a few timed things after X happens" — replacing the need to separately understand
Sales Automation follow-ups, Reengage campaigns and Visual Flow Builder drip as three
different mental models. **This phase does not touch or migrate any of those three** — see
§6 below for why, and the step-by-step guide for recreating one of them as a Sequence.

## 2. How it is built

Nothing here re-implements what an Automation Rule already does. A Sequence step runs through
the exact same dispatcher:

```
sequences / sequence_steps          owner-authored: name, steps, timing
        │
sequence_enroll()                   application/helpers/sequence_helper.php
        ▼
sequence_enrollments                one row per (sequence, customer); next_run_at is the clock
        │  sequence_worker run      application/controllers/Sequence_worker.php  (CLI, every minute)
        ▼
sequence_advance()                  application/helpers/sequence_helper.php
        │  Automation_engine::execute_action()   <- the SAME dispatcher an Automation Rule uses
        │      │  channel_policy_decide()         <- the SAME policy layer, sender='automation'
        │      ▼
        │  Automation_gateway                     <- the SAME side-effect implementations
        ▼
sequence_step_log                   one row per (enrollment, step) attempt
```

Because `sequence_advance()` calls `Automation_engine::execute_action()` directly, every
action type in `docs/automations/engine.md` §4 works in a Sequence step exactly as it works in
a Rule — including `send_message`'s channel-policy gate, which cannot be bypassed from either
side. The two pieces genuinely share one implementation; nothing was copy-pasted.

**What is new for Sequences, specifically:**

- `create_task` — a follow-up task in `crm_activities` (SPEC-12), added to
  `Automation_gateway`/`Automation_engine` so it is usable from a Rule too. This is brief
  §25's "T +1 day: create human follow-up task".
- `start_sequence` — an Automation Rule action that enrolls the customer into a Sequence
  (`Automation_gateway::start_sequence()` → `sequence_enroll()`). This is how the two features
  **compose**: `Automation Rule (trigger → conditions) → start_sequence → Sequence (steps over
  time) → channel_policy_decide (every send)`. Example, brief §23's own: `TRIGGER lead.qualified
  → THEN start_sequence`.

## 3. Timing model — read this before writing a sequence

`sequence_steps.delay_seconds` is an **absolute offset from `sequence_enrollments.enrolled_at`**,
not from the previous step. Step "T +6 hours" fires 6 hours after enrollment — matching the
brief's own example verbatim, and matching what an owner means when they say "6 hours after
this starts." It is **not** "6 hours after the T +10 minute step ran."

Consequences:

- The "test with sample data" schedule preview needs no arithmetic — each step's
  `delay_seconds` already IS its T+ label (`sequence_human_offset()`).
- A worker outage does not compound delay across steps. If the worker is down for an hour,
  every step that was due in that hour fires (once each, at the next tick) still measured from
  the original enrollment time, not pushed back by the outage.
- Step order still matters (steps run in `position` order); it is the operator's job to keep
  `delay_seconds` non-decreasing across positions. Nothing enforces that server-side today —
  see §7, "Known gaps."

## 4. Stop conditions

Each step may carry a `stop_condition` — the same `{field, op, value}` row format as an
Automation Rule's conditions (`docs/automations/engine.md` §4), evaluated **before** that
step's action runs. If it matches, the **whole enrollment** stops there — no later step ever
runs — and the stop is logged (`sequence_step_log.status = 'skipped_stop_condition'`) with a
reason on `sequence_enrollments.stopped_reason`. This is never silent (brief §51).

The evaluation data is the same flat namespace a Rule's conditions see
(`subscriber.*`, `deal.*`, promoted fields — see `engine.md` §4), plus one field made for this:

- **`replied_since_enrollment`** (bool) — true when the customer's last inbound message is
  newer than `enrolled_at`. This is what the brief's "stop if customer replied" means in
  practice: `{"field": "replied_since_enrollment", "op": "eq", "value": true}`.

"Stop if deal stage changed" is expressible with the existing `deal.*` fields (e.g.
`{"field": "deal.status", "op": "neq", "value": "open"}`) — there is no dedicated
"stage at enrollment time" snapshot field yet; see §7.

## 5. Enrollment

`sequence_enroll($user_id, $sequence_id, $subscriber_id, $platform, $subscribe_id,
$channel_account_id, $customer_id, $now, $db)` is **idempotent**:
`sequence_enrollments` has `UNIQUE(sequence_id, subscriber_id, platform)`, so enrolling the same
customer twice returns the existing enrollment id and does nothing else. Ways a customer gets
enrolled:

1. **By hand** — the "Enroll a customer by hand" box on a sequence's enrollment page
   (`Sequences::enroll_manual`).
2. **By an Automation Rule** — a rule's `start_sequence` action (§2 above).
3. **By a trigger event**, when the sequence's own `trigger_event` is set to something other
   than "Only when I add someone myself" (`manual`) — the sequence carries its own
   `enroll_conditions_json` (same format as a Rule's conditions). Wiring an event trigger
   straight to `sequence_enroll()` (the way `Automation_worker` wires `automation_rules` to
   events) is listed as a known gap in §7 — today the trigger dropdown and conditions editor
   exist and save correctly, but only `start_sequence` and manual enrollment actually enroll
   anyone. **Use `start_sequence` from an Automation Rule for an event-driven sequence today.**

## 6. What this phase deliberately does NOT do: no auto-migration

`Sales_automation.php`/`sales_automation_settings`/`ai_followups`, `Reengage.php`/
`Reengage_audience.php`/`Reengage_sender.php`/`reengage_campaign`/`reengage_recipient`, and
Visual Flow Builder's `messenger_bot_drip_campaign` type sequences **keep running exactly as
they do today.** Nothing in this phase reads, writes or changes their tables or controllers.
Automatically converting a live client's follow-up delay or re-engage campaign into a Sequence
row would silently change what that client's customers receive — brief §48 (expand → migrate →
verify → contract) and §51 (no silent failure) both rule that out for something this
customer-facing. Migration is a **deliberate, manual, opt-in** action an operator takes per
campaign, using the guide below.

### Manual migration guide

#### A. Recreate a Sales Automation follow-up as a Sequence

Source: `sales_automation_settings` (one row per tenant) — `followup_enabled`,
`followup_delay_minutes`, `followup_message_ar` / `followup_message_en`.

1. Read the tenant's current settings: `Sales_automation::index()` shows them, or read the row
   directly (`sales_automation_settings WHERE user_id = ?`).
2. Create a new Sequence. Trigger: "Only when I add someone myself" for now (event-driven
   auto-enroll on `lead.created` needs the gap in §5.3 closed first — or wrap it in an
   Automation Rule with `TRIGGER lead.created → THEN start_sequence`, which works today).
3. One step: delay = `followup_delay_minutes` minutes (convert to seconds), action =
   `send_message`, text = `followup_message_en` (or build the Arabic/English choice into the
   template the way the original feature does — Sequences has no per-language variant today,
   see §7).
4. **Do not disable `sales_automation_settings.followup_enabled` until the Sequence has been
   verified on a copy of production data (brief §47).** Running both at once double-messages
   customers — turn the old one off the moment the new one is confirmed working, not before.

#### B. Recreate a Reengage campaign as a Sequence

Source: `reengage_campaign` (rate limits, quiet hours, A/B, schedule) and `reengage_recipient`
(who is in it, their state).

1. Read the campaign's settings (`Reengage::edit` or the row directly): `message_json`,
   `variant_b_json`, `filters_json`, `quiet_start`/`quiet_end`, `daily_cap`,
   `messages_per_hour`.
2. Create a Sequence with one `send_message` step at delay 0 (or whatever offset matches the
   campaign's intent).
3. **Important gap, read before migrating a real campaign:** Sequences today has **no**
   rate-limit budget, quiet-hours window, daily cap or A/B split of its own — the
   `reengage_rate_budget()` / `reengage_in_quiet_hours()` / `reengage_jitter_seconds()` helpers
   `reengage_helper.php` provides are not wired into `sequence_advance()` or the worker. A
   Sequence step's only real-time gate is `channel_policy_decide()` (the 24h/7-day messaging
   window and opt-out check) — the same one every automated send in this app goes through, but
   it says nothing about *how many* messages per hour or *what time of day*. **Do not migrate a
   large or rate-sensitive Reengage campaign to a Sequence until this is closed** (tracked in
   §7). A small, one-customer-at-a-time manual re-engagement fits today's Sequences fine.
4. Enroll the eligible recipients (or wire a Rule's `start_sequence` to whatever event puts
   someone in the old campaign's audience) and confirm the old `reengage_campaign` row is
   paused before double-sending.

#### C. Recreate a Visual Flow Builder drip campaign as a Sequence

Source: `messenger_bot_drip_campaign` / `messenger_bot_drip_campaign_assign` (module
`visual_flow_builder`, see `Messenger_bot.php`'s drip methods) — hourly or daily drip steps
keyed to `messenger_bot_drip_last_completed_day`/`_hour`.

1. Read the drip campaign's `message_content` (and `message_content_hourly` for the hourly
   variant) and its cadence.
2. Create one Sequence step per drip message, with `delay_seconds` set to that message's
   position in the cadence (day 1 = 86400, day 2 = 172800, hour 1 = 3600, …).
3. Visual Flow Builder's drip supports branching/engagement-triggered paths
   (`messenger_bot_engagement_*` drip types) that Sequences does not have — a linear timed list
   only. Only migrate a drip campaign that is genuinely linear; a branching one needs to stay on
   Visual Flow Builder, or be redesigned, not "migrated" as-is.
4. Stop new assignments into `messenger_bot_drip_campaign_assign` for that campaign once the
   Sequence is confirmed working, so a customer is never in both.

## 7. Known gaps (honest, not hidden — brief §51)

- **No event → auto-enroll wiring.** A sequence's `trigger_event` + `enroll_conditions_json`
  save correctly and appear in the UI, but nothing currently listens for that event and calls
  `sequence_enroll()` for it (the way `Automation_worker` does for `automation_rules`). Today,
  event-driven enrollment must go through an Automation Rule's `start_sequence` action, which
  fully works. Closing this gap is a small, well-scoped follow-up: a new branch in
  `Sequence_worker` (or a dedicated listener) that drains `events` for sequences with a
  non-`manual` `trigger_event`, exactly mirroring `Automation_engine::process_event()`.
- **No rate limiting, quiet hours or daily cap.** See §6.B. `reengage_helper.php`'s budget/quiet-
  hours/jitter functions exist and are proven in Reengage; wiring them into `sequence_advance()`
  for `send_message` steps (in addition to the always-on `channel_policy_decide()` check) is the
  next piece of work before Sequences is safe for large-audience re-engagement.
- **No per-step retry.** `Automation_engine::execute_action()`'s retry/backoff loop (3 attempts,
  1s/3s backoff) IS in effect for a Sequence step's action, since it is the same dispatcher —
  but a step that still fails after retries simply advances to the next step; it is logged
  (`sequence_step_log.status = 'failed'`), never retried on a later worker tick.
- **No stage-at-enrollment snapshot** for "stop if deal stage changed" — see §4.
- **No language/A-B variant** on a step's message — one `text` per step.
- **`delay_seconds` ordering is not validated** — see §3.

## 8. Enabling it

Same switch as the Automation Engine (brief §50 — deliberately, not a separate flag; a
Sequence step sending a message has identical blast radius to an Automation Rule action):

```php
$config['automation_engine_enabled'] = TRUE;
```

Cron line (append-only, `docker/cron/monkeybot`, **not installed on the host by this branch**):

```sh
* * * * * root flock -n /run/monkeybot-sequence.lock docker exec -u www-data monkeybot-app-ojzrhf-app-1 timeout 55 php /var/www/html/index.php sequence_worker run >> /var/log/monkeybot-cron.log 2>&1
```

Module gate: **412 'Sequences'** (`entitlement_helper.php`), granted to every existing package
by `assets/backup_db/migrations/2026-09-26-sequences.sql`, enforced only when
`entitlement_enforce_custom_modules` is TRUE (same safety switch as every other Creative Monkey
module — admin is never blocked either way).

## 9. Runbook

```sh
# what is due, soonest first
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php sequence_worker pending

# one tick by hand (default 200 enrollments)
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php sequence_worker run 50
```

- Holds its own MariaDB advisory lock `monkeybot_sequence_worker` (separate from the
  Automation worker's `monkeybot_automation_worker`), so the two schedules never block each
  other and two `sequence_worker` ticks never overlap.
- Records `sequence_worker` in `application/cache/ops_health.json` — shows up in
  `/system_health` and `/health` like every other scheduled job.
- Over HTTP every method 404s. No secret, no web surface.

### "A sequence did not do anything"

1. **A sequence's enrollment page** shows every enrolled customer's current step and next
   run time.
2. `skipped_policy` on a step — the channel refused an automated message at that moment
   (24h/7-day window, opt-out). Expected, not a bug.
3. `skipped_stop_condition` — the sequence stopped itself; `stopped_reason` says why.
4. `skipped` with "sequence is paused" — turn the sequence back on; already-running
   enrollments resume from where they were, they do not restart.
5. No progress at all → check `automation_engine_enabled` and `/health`.

## 10. Tests

| Suite | What it covers |
|---|---|
| `php tests/sequences/run.php` | Unit, no CodeIgniter/DB/network: enrollment idempotency, step-advancement math (absolute-offset `next_run_at`, completion, pause blocks advancing without losing position), stop-condition evaluation, the `replied_since_enrollment` field, a policy-denied step logged `skipped_policy` with nothing sent, replay never re-firing a claimed step, action dispatch (including `create_task`/`start_sequence`) reusing `Automation_engine`/`Automation_gateway`. Uses an injectable `db`/`engine` (`sequence_advance()`'s `$deps` parameter, mirroring `Automation_engine`'s own `gateway` injection) with a small in-memory `FakeDb` — see the file header. |
| `docker/scripts/staging_sequences_tests.sh` | HTTP + DB on a live stack: a 3-step sequence created through the real `Sequences` endpoints, two customers enrolled (idempotently), the CLI worker advancing each step at its backdated `next_run_at`, `add_label` unaffected by channel policy, `send_message` denied for an out-of-window customer and allowed for an in-window one, a `stop_condition` halting a sequence with the reason recorded, replay proven not to re-fire a claimed step, module 412 gating a member (never an admin), `sequence_worker` 404 over HTTP, the flag-off refusal, and a no-regression check that `/automations` still works with the two new action types offered. |
| `docker/scripts/staging_phase0_tests.sh`, `docker/scripts/staging_automation_tests.sh` | Re-run against the same stack for no regression. |

### Results (2026-09-22, this stack: mb-sequences-test, 127.0.0.1:8094)

- `php tests/sequences/run.php`: **56 passed, 0 failed**.
- `docker/scripts/staging_sequences_tests.sh`: **47 passed, 0 failed**.
- No-regression re-runs on the same stack: `docker/scripts/staging_automation_tests.sh`
  **44 passed, 0 failed**; `docker/scripts/staging_phase0_tests.sh` **19 passed, 0 failed**.
