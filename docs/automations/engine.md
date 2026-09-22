# Event Engine and Automation Engine

**Phase 10.5** — brief §22 (Event Engine), §23 (Trigger → Conditions → Actions), §26 (Channel
Policy), §40 (Audit), §46 (owner language), §50 (feature flags), §51 (no silent failure).
Decision record: `docs/architecture/adr/ADR-003-event-engine.md`.

---

## 1. What it is

Two pieces that only touch each other through one table.

```
something happens in the app
        │  event_emit()            application/helpers/event_helper.php
        ▼
   events  (append-only, per tenant, processed_at = NULL)
        │  automation_worker run   application/controllers/Automation_worker.php  (CLI, every minute)
        ▼
 Automation_engine                 application/libraries/Automation_engine.php     (decisions)
        │  Automation_gateway      application/libraries/Automation_gateway.php    (side effects)
        ▼
 automation_runs  +  automation_action_log
```

The emitter never knows who is listening, and the engine never calls back into the feature
that emitted. Adding a listener is a row in `automation_rules`, not a code change.

**Emitting is always on.** `event_emit()` is one INSERT, it never throws, and it is not behind
the feature flag — so the history is already there on the day the automations are switched on.
Only the *worker* is flagged.

---

## 2. Tables

| Table | What it holds |
|---|---|
| `events` | one row per business fact: `user_id`, `event_type`, `subject_type`, `subject_id`, JSON `payload`, `correlation_id`, `created_at`, `processed_at` |
| `automation_rules` | the owner's rules: `trigger_event`, `conditions_json`, `actions_json`, `status` (draft / active / paused) |
| `automation_runs` | one row per **(rule, event)** — `UNIQUE(rule_id, event_id)` is what makes a replay a no-op |
| `automation_action_log` | one row per action attempt: type, status, error, `retry_count` |

Created by `assets/backup_db/migrations/2026-09-24-event-automation.sql`, which also adds
module **409 'Automations'**, grants it to every non-deleted package, adds the sidebar row and
pairs with `'automations' => 409` in `application/helpers/entitlement_helper.php`.

---

## 3. The event catalogue

Full list with payload keys: the header comment of `application/helpers/event_helper.php`.
Owner-language labels: `event_catalogue()` — that function is the trigger dropdown.

```
message.received   message.sent
conversation.started  conversation.assigned  conversation.resolved
customer.created
lead.created   lead.qualified   lead.stage_changed
price.requested
order.created  checkout.started  checkout.abandoned
appointment.created
human.requested  human.takeover
ai.failed  question.unanswered
```

### Wiring status — what actually fires today

**Emitted for real (verified on the 8091 test stack):**

| Event | Where | Notes |
|---|---|---|
| `lead.created` | `application/helpers/crm_helper.php` (end of `crm_auto_deal`) | Only on a genuine insert, so the helper's idempotent early-returns never produce a duplicate event. Covers the e-commerce checkout path, which calls `crm_auto_deal` from `application/controllers/Ecommerce.php:5462`. |
| `lead.stage_changed` | `application/controllers/Crm.php` (`deal_move`) | A person dragging a deal on the pipeline board. |
| `lead.stage_changed` | `application/libraries/Automation_gateway.php` (`move_crm_stage`) | An automation moving a deal. Events chain. |
| `appointment.created` | `application/controllers/Appointment_booking.php` (`submit_booking`) | The public booking page. |

Every one of those is wrapped in its own `try/catch`: a failure to record the event can never
roll back or break the thing that happened.

**Not emitted yet — wiring TODO.** Each needs an edit in a file another engineer currently
owns, or a hot path that deserves its own review:

| Event | Where it belongs | Why not yet |
|---|---|---|
| `question.unanswered` | `application/controllers/Home.php:7472` (the `ai_unanswered_questions` insert) | The only insert site is inside Home.php, which is being changed by another engineer. |
| `ai.failed` | `application/libraries/Ai_provider.php:71` | Inside the provider's swallow-everything catch; needs its own review of what counts as a failure. |
| `price.requested` | `application/libraries/Ai_tools.php:341` (`t_calculate_price`) | On the AI reply hot path. |
| `message.received` / `message.sent` | `application/helpers/channel_send_helper.php:189` (`channel_log_message`) for outbound; `Messenger_bot.php` / `Openwa_bot.php` inbound | Highest-volume events in the system (~42k messages). Needs a volume and retention decision before it is switched on. |
| `human.requested` / `human.takeover` | `application/controllers/Livechat.php:38` (`bot_paused_until`) and `application/controllers/Home.php:320` (`lead_add_score(..., 'human_handoff')`) | Home.php is off limits this branch; Livechat.php is a one-line add once the volume question above is settled. |
| `conversation.*` | Needs the unified conversation model (ADR-001) to land first | There is no single place that owns conversation state yet. |
| `customer.created` | `application/controllers/Openwa_bot.php:706`, `application/libraries/Reengage_import.php:279` and the Messenger_bot subscriber path | Several insert sites; belongs in a subscriber-creation helper that does not exist yet. |
| `order.created` / `checkout.started` / `checkout.abandoned` | `application/controllers/Ecommerce.php` and `application/controllers/Cron_job.php:3940` | `checkout.started` is partly covered today through `lead.created` (the cart path creates a deal). |
| `lead.qualified` | `application/helpers/lead_scoring_helper.php` (`lead_add_score`, on a band crossing) | Needs a definition of "qualified" the owner agrees with. |

---

## 4. Rule JSON schema

### Conditions

An AND list. Empty list = "whenever this happens".

```json
[
  {"field": "lead_score", "op": "gte", "value": 70},
  {"field": "platform",   "op": "in",  "value": "fb,ig"}
]
```

`{"match": "all", "conditions": [...]}` is accepted too and means the same thing.

**Operators** (`Automation_engine::operators()`): `eq`, `neq`, `gt`, `gte`, `lt`, `lte`,
`contains`, `in`, `exists`.

- String comparison is **case-insensitive**; `contains` also works on a list.
- Numeric operators on a non-numeric value are **false**, never a PHP cast surprise.
- A field that is missing, null or empty is **false** for every operator except `exists`
  (including `neq`). Same safe default as the channel policy layer: we do not act on what we
  cannot verify.
- `{"op": "exists", "value": false}` asserts the field is *absent*.

**Fields** are a flat namespace, built in `Automation_engine::evaluation_data()`:

| Source | Examples |
|---|---|
| the event itself | `event_type`, `subject_type`, `subject_id`, `correlation_id` |
| the event payload | whatever the emitter put there — `lead_score`, `platform`, `text`, `value`, `stage`, `source` … |
| context, tenant-scoped | `subscriber.*` and `deal.*` (full rows) |
| promoted context | `lead_score`, `platform`, `full_name`, `email`, `phone`, `subscriber_id`, `last_inbound_at`, `opted_out` — used only where the payload is silent |

Dotted paths work: `subscriber.lead_score`, `deal.status`.

### Actions

A list, run in order. Every action is logged whatever happens.

```json
[
  {"type": "add_label",          "label": "Hot lead"},
  {"type": "update_lead_score",  "delta": 10},
  {"type": "create_crm_deal",    "title": "{{full_name}} — new lead", "value": 0, "currency": "EGP"},
  {"type": "move_crm_stage",     "stage": "Interested"},
  {"type": "notify_team",        "channel": "whatsapp", "to": "201000000000", "text": "New hot lead: {{full_name}}"},
  {"type": "send_message",       "text": "Thanks {{full_name}}!"},
  {"type": "call_webhook",       "url": "https://example.com/hook"},
  {"type": "write_google_sheet", "note": "from automation"}
]
```

Any string parameter may interpolate a field with `{{field}}` (dotted paths allowed). An
unknown field renders empty.

| Action | Does | Notes |
|---|---|---|
| `add_label` | tags the customer | Creates the tag if it does not exist. Same objects Broadcasting filters on. |
| `update_lead_score` | adds `delta` (may be negative) | Floors at 0. |
| `create_crm_deal` | `crm_auto_deal()` | Idempotent: an existing open deal counts as done, not as a failure. |
| `move_crm_stage` | moves the deal, writes the timeline row | Also emits `lead.stage_changed`. |
| `notify_team` | WhatsApp via `channel_send_text()`, or email via `Home::_email_send_function` | Internal recipient — no channel policy applies. |
| `send_message` | messages **the customer** | **Always** goes through `channel_policy_decide()` first. See below. |
| `call_webhook` | POSTs JSON | `https://` only, 5s timeout, certificate verification on, no redirects. |
| `write_google_sheet` | appends a row via `crm_sheet_helper` | Needs the tenant's CRM Google Sheet connected. |

An **unknown action type** fails that action and is recorded as such; it does not abort the run.

### Action statuses

| Status | Meaning |
|---|---|
| `success` | it worked |
| `failed` | it did not work; `error` says why, `retry_count` says how many extra attempts were made |
| `skipped` | there was nothing to do (no customer on the event, delta of zero) |
| `skipped_policy` | the channel would not allow an automated message right now. **Nothing was sent.** |

---

## 5. Channel policy — the rule that cannot be bypassed

`send_message` always calls `channel_policy_decide($platform, ['sender' => 'automation', ...])`.
`sender` is hard-coded to `automation`: an automation is never a human agent, so it can never
borrow the Meta `HUMAN_AGENT` tag. A denial is recorded as `skipped_policy` with the policy's
own reason and is **never retried** — retrying cannot change a messaging-window verdict.

The owner sees the sentence from `channel_policy_explain()`, not the machine reason.

---

## 6. Retries

Only failures the gateway marks *retryable* (timeout, transport, HTTP 5xx, HTTP 429) are
retried, at most `Automation_engine::MAX_ATTEMPTS` = **3 attempts total**, waiting
`Automation_engine::$backoff` = **1s then 3s**. A misconfiguration (bad URL, missing stage,
HTTP 4xx) is not retried. Policy denials are not retried.

---

## 7. Idempotency and tenant isolation

- **Idempotency.** Before doing anything, the engine claims the pair with
  `INSERT IGNORE INTO automation_runs (rule_id, event_id, ...)`. A claim that returns no
  insert id means another run already exists, and the engine stops. Replaying an event —
  which the worker will do if `processed_at` is cleared — re-runs nothing.
- **Tenant isolation.** `active_rules()` filters on `user_id`, `context()` scopes every load to
  `user_id`, and `run_rule()` refuses outright (logged, no run row) if `rule.user_id !==
  event.user_id`. The UI joins `automation_runs` through `automation_rules` so a run of another
  tenant cannot even be listed.

---

## 8. Turning it on

1. Apply the migration: `php index.php cron_hub migrate`.
2. Tick **Automations** on the packages that should have it (Admin → Package Manager). The
   migration already adds 409 to every existing non-deleted package.
3. Build a rule at **/automations**, and use **Try it out** — it evaluates the real conditions
   against a real or hand-written example and shows which steps *would* run, including what the
   channel policy would say. It executes nothing.
4. Add the config item (production `config.php` lives in the `monkeybot_config` Docker volume,
   so edit it **there**, not only in the repo):

   ```php
   $config['automation_engine_enabled'] = TRUE;
   ```

   While it is unset or FALSE the worker logs one line and exits without touching an event.
5. Install the cron line from `docker/cron/monkeybot` (every minute). Safe to install before
   step 4 — the worker exits in milliseconds while the flag is off.

**Catch-up warning.** Events emitted while the flag was off are still `processed_at IS NULL`,
so the first enabled tick will process the whole backlog and run every matching active rule
against it. Before switching on, either keep all rules in *draft* until the backlog is drained,
or mark the backlog processed:

```sql
UPDATE events SET processed_at = NOW() WHERE processed_at IS NULL;
```

---

## 9. Runbook

```sh
# what is waiting
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php automation_worker pending

# one tick by hand (default 200 events)
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php automation_worker run 50
```

- The worker holds the MariaDB advisory lock `monkeybot_automation_worker`; a second tick is a
  no-op, so the one-minute cron cannot stack.
- It records `automation_worker` in `application/cache/ops_health.json`, so it shows up in
  `/system_health` and `/health` like every other scheduled job.
- Over HTTP every method 404s. There is no secret and no web surface.

### "My automation did not do anything"

1. **/automations → Activity.** Every look at every event is there, including the times it
   decided to do nothing and why. Click a line for the per-step log.
2. `skipped` with "conditions not met: …" — the detail names the field, the comparison and the
   actual value.
3. `skipped_policy` — the channel refused an automated message at that moment. Expected, not a
   bug; the reason says which window.
4. No run row at all → the rule is not `active`, its trigger is a different event, or the
   worker is not running (check `/health` and the flag).
5. Nothing in `events` → that trigger is not wired yet. See §3, "Wiring status".

### Retention

`events` grows without bound today. Nothing prunes it yet — deliberately, so the first months
of history survive. Add it to `Cron_hub::_run_retention_cleanup` (the `ai_deflect_alert_log`
pattern) once the volume is known. `message.*` must not be wired until that exists.

---

## 10. Tests

| Suite | What it covers | Result on 2026-09-22 |
|---|---|---|
| `php tests/automation/run.php` | 65 unit cases, no DB and no network: operator matrix, AND logic, field resolution, action dispatch, unknown action, policy denial, idempotency, tenant isolation, retry/backoff, dry run | 65 passed, 0 failed |
| `docker/scripts/staging_automation_tests.sh` | 44 HTTP + DB assertions on a live stack: rules created through the UI endpoints, a real `crm/deal_move` emitting a real event, the CLI worker running it, tags and scores actually changed, policy-denied send logged with nothing sent, replay idempotent, feature flag off, module 409 gate, `automation_worker` 404 over HTTP, cross-tenant reads and writes refused | 44 passed, 0 failed |
| `docker/scripts/staging_phase0_tests.sh` | Phase 0 regression, unchanged by this branch | 19 passed, 0 failed |
| `php tests/run.php`, `php tests/policy/run.php` | channel adapters, channel policy | 117 / 20 passed, 0 failed |

The engine is testable without CodeIgniter because every side effect goes through
`Automation_gateway`; `tests/automation/run.php` substitutes a fake with the same methods.
