# ADR-003: Event Engine and Automation Engine

- **Status:** Accepted
- **Date:** 2026-09-22
- **Accepted:** 2026-09-22, Phase 10.5
- **References:** brief §22, §23, §25, §26, §28, §40, §46, §50, §51; ADR-000 (strangler fig),
  ADR-001 (unified conversation model), ADR-002 (channel adapters);
  `docs/automations/engine.md`; `docs/baseline/2026-09-22-transformation-baseline.md`

## Context

Features in this codebase call each other directly. When a cart is abandoned, the e-commerce
controller itself scores the lead, creates the CRM deal and decides whether to message the
customer. When a booking is made, nothing else in the system hears about it at all. Every new
"when X happens, also do Y" is therefore an edit to a file that already does five other jobs —
`Home.php` is 8,245 lines and `Messenger_bot.php` is 14,840, which is exactly why those edits
keep being deferred.

At the same time the roadmap (§52, Monkey Bot 10.5 "Automation OS") asks for something the
*owner* can author: trigger → conditions → actions, in their own words, without an engineer.

Two constraints shaped the design:

- **Nothing may endanger a customer-facing flow.** A webhook reply, an AI answer or a booking
  confirmation must not fail, slow down or roll back because a bookkeeping write failed.
- **Nothing may send a message the channel does not allow.** §26 put that verdict in one place
  (`channel_policy_decide`). An automation is the single most likely thing to violate it,
  because it acts with nobody watching.

## Decision

Split the problem in two, joined only by a table.

### 1. An append-only event log

`event_emit($user_id, $type, $subject_type, $subject_id, $payload, $correlation_id)` is a
single INSERT into `events` that **never throws**. Callers do not know or care who listens.

- Emitting is **not** behind the feature flag. It is cheap, and we want history to exist before
  the automations are switched on.
- Every emit site is individually wrapped, on top of the helper's own catch. A dropped event
  costs one missing row; a thrown exception would cost a customer their booking.

### 2. An engine whose decisions are separable from its side effects

`Automation_engine` holds only decisions — which rules match, whether conditions pass, whether
a send is allowed, what to retry, what to record. Everything that touches the world (database
writes, channel sends, webhooks, sleeps) goes through `Automation_gateway`.

A CLI worker (`Automation_worker`, its own controller, its own advisory lock, no HTTP surface)
drains the log every minute and stamps `processed_at`.

### Load-bearing choices

**Idempotency is an index, not a check.** `automation_runs` has `UNIQUE(rule_id, event_id)` and
the engine claims the pair with `INSERT IGNORE` *before* evaluating anything. A `SELECT` first
would still race two workers; the index cannot.

**Tenant isolation is a refusal, not a filter.** `run_rule()` compares `rule.user_id` to
`event.user_id` and refuses if they differ, logging it — on top of the filter in the rule query
and the `user_id` scope on every context load. A filter that is accidentally dropped in a later
refactor fails open; an explicit refusal fails closed and leaves evidence.

**A send is a policy question first.** `send_message` calls `channel_policy_decide()` with
`sender` hard-coded to `'automation'`, so an automation can never borrow the Meta `HUMAN_AGENT`
tag that a human agent may use. A denial is recorded as `skipped_policy` with the policy's own
reason and **is never retried** — a retry cannot change a messaging-window verdict, and
retrying would be the exact behaviour that gets a page restricted.

**Every outcome is a row (§51).** Every run gets an `automation_runs` row and every action
attempt an `automation_action_log` row, including the skips and the reason for each. A rule
whose conditions did not match also gets a `skipped` run, because "why didn't my automation
fire?" is the question this feature will be asked most, and it should be answerable from the
screen rather than from the PHP log.

**Failures are graded, not uniform.** The gateway returns `(ok, error, retryable)`. Only
transport-shaped failures retry (3 attempts, 1s then 3s backoff). A bad URL, a missing stage or
an HTTP 4xx is a configuration problem that another attempt will reproduce exactly.

**An unknown action fails one action, not the run.** Rules outlive deployments; a rule written
against an action a later build removes must degrade to one logged failure.

## Alternatives considered

**Call the automation engine inline from each feature.** Rejected: it puts an outbound HTTP
call with a 5s timeout on a webhook's reply path, and it makes every emit site a place that can
break the caller. The queue is what makes "never block the customer-facing flow" structural
rather than a promise (§28 says the same about external CRM calls).

**Use the existing `Cron_hub`.** Rejected on two counts. Another engineer owns that file this
phase, and the 5-minute tick is both too slow for an automation to feel immediate and already
long enough to risk overruns. A separate controller with its own lock can run every minute
without waiting behind the sales-automation jobs.

**A real queue (Redis, Beanstalk).** Rejected for now: §42 wants queues eventually, but adding
a broker to a Dokploy stack that has none is a deployment change, not a feature. A MariaDB
table with an `id` cursor and an advisory lock is adequate at this volume and keeps the whole
audit trail in the database the admin UI already reads.

**Put conditions and actions in PHP classes per rule.** Rejected: the owner has to be able to
author and change a rule. JSON in a column, rendered by a form, is the thing that can be
edited without a deploy.

**A visual flow builder.** Deferred. §24 (Prompt-to-Flow) explicitly wants the AI to author
these rather than a drag-and-drop canvas; a linear trigger/conditions/actions rule is the data
model that an AI flow builder would generate anyway.

**Events as a generic `audit_log`.** Rejected: §40's audit log answers "who changed what" and
wants before/after values; the event log answers "what happened in the business" and is a
trigger source. Merging them would make retention and indexing serve neither well.

## Consequences

**Good**

- A new automation is a row, not a deploy.
- The customer-facing paths gained four `try`-wrapped INSERTs and nothing else.
- The engine is unit-testable without CodeIgniter, a database or a network (65 cases), because
  the gateway seam exists. This is the first library in the codebase that is.
- Every automation outcome is visible to the owner in business language, including the
  deliberate non-actions.
- Events chain: an automation that moves a CRM stage emits `lead.stage_changed`, which other
  rules can listen to.

**Costs and risks**

- **`events` grows without bound.** Nothing prunes it yet, deliberately, so the first months of
  history survive. `message.received` / `message.sent` (~42k rows of history) must NOT be wired
  until a retention job exists. Tracked in `docs/automations/engine.md` §9.
- **Latency is up to a minute.** Fine for tagging, notifying and CRM work; not suitable for
  anything that must be part of the reply the customer is waiting for.
- **The catch-up trap.** Events accumulate while the flag is off, so the first enabled tick
  processes the whole backlog. The runbook says to drain or mark it processed first.
- **Most triggers are not wired yet.** Four events fire today
  (`lead.created`, `lead.stage_changed` ×2 sites, `appointment.created`); the rest are listed
  as TODOs with file and line. A trigger in the dropdown that never fires is a UX trap until
  those land — the "Wiring status" table is the answer, and the "Try it out" button will show an
  owner that nothing matched.
- **One action is only as good as its dependency.** `write_google_sheet` needs the tenant's CRM
  sheet connected; `notify_team` by email needs the tenant's email provider configured. Both
  fail with a plain-language error rather than silently.
- **The engine can write events that trigger the engine.** Chaining is intended. The obvious
  self-loop (a rule triggered by `lead.stage_changed` whose action moves the stage) is closed:
  `move_crm_stage` only emits when the stage actually changed. A ping-pong between two rules
  (A moves to X, B moves back to Y) is still possible. The per-(rule, event) claim bounds each
  hop so it cannot spin within one tick, but there is no global depth limit; that belongs with
  the retention work, before `message.*` is wired.
