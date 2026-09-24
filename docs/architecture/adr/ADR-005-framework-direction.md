# ADR-005: Framework direction

- **Status:** Proposed
- **Date:** 2026-09-25
- **References:** brief §36; ADR-000 (strangler fig, "framework choice deferred" §4 point 5);
  ADR-001 (unified conversation model); ADR-002 (channel adapters); ADR-003 (event/automation
  engine); `docs/architecture/legacy-register.md`; `docs/baseline/2026-09-22-transformation-baseline.md`

## Context

ADR-000 deferred the framework question until the module seams named there existed, so a module
could be moved behind an API without touching its callers. Those seams now exist and are load-bearing
in production: `libraries/Channels/*` (ADR-002), the conversation model (ADR-001), and the event/
automation engine (ADR-003) all sit in front of the legacy controllers rather than being called
directly from them. That was the precondition for this ADR to be meaningful, so it is written now.

Current stack, unchanged since ADR-000:

- CodeIgniter 3.1.5 + HMVC, PHP 8.1, MariaDB 10.11, Docker.
- No dependency injection, no autoloading beyond CI3's conventions, no first-class async jobs
  (scheduling goes through cron + `Cron_hub`), and CI3 has been end-of-life since April 2022 — it
  gets no security patches from upstream. PHP 8.1 itself leaves active support in December 2026 and
  security support in December 2027.
- Two of the largest files in the codebase are legacy: `Ecommerce.php` (20,217 lines) and
  `Sms_email_manager.php` (14,621 lines), alongside the well-documented `Messenger_bot.php`
  (14,840) and `Home.php` (8,245, holding the AI reply engine). `docs/architecture/legacy-register.md`
  classifies every controller; the REPLACE/MODERNISE rows there are candidates for whichever
  direction this ADR picks.
- Production has no staging (baseline: staging remains an open precondition of ADR-000), is deployed
  by live-editing a bind-mounted tree, and is revenue-bearing today (8 FB pages, 8,977 subscribers,
  live AI sales per ADR-000's context). Any framework change inherits all of that risk unless it is
  built and proven on the side first.
- The strangler fig pattern (ADR-000) is working as designed: `Ai_provider`, `Ai_tools`,
  `channel_send_helper`, the conversation model, the event/automation engine and the Unified Inbox
  were all added next to the legacy code without a rewrite, and are the majority of what's now
  "current generation" per the legacy register.

The question this ADR answers is not "should we modernise" — that's already happening, one seam at a
time. It's "what does the code look like once there's nowhere left to strangle to" — i.e., what
runtime hosts the modules once they're extracted, and whether CI3/PHP 8.1 is still an acceptable
foundation to keep extracting *into*.

## Options considered

### Option A — Stay on CI3, keep strangling in place

Continue ADR-000 exactly as-is: new capability as libraries/helpers/HMVC modules behind interfaces,
legacy controllers as thin callers, no framework migration. Address the CI3 end-of-life risk by
forking/patching CI3 in-repo (it's stable, small, and MonkeyBot already carries local patches) rather
than upgrading it.

- **Trade-offs:** Lowest short-term risk and cost — zero migration work, the team's CI3 knowledge
  stays useful, every existing seam (ADR-001/002/003) keeps working unchanged. But it never resolves
  the structural problems that motivated ADR-000 in the first place: no DI, no PHP 8.x-native testing
  ergonomics (PHPUnit runs today, but awkwardly, per the baseline's manual regression-count style),
  and the two largest legacy files (`Ecommerce.php`, `Sms_email_manager.php`) stay CI3-shaped forever
  unless someone chooses to extract them too — which is possible but gets no easier with time, since
  CI3 itself won't gain PHP 8.x idioms (typed properties, enums, readonly, attributes) that would make
  large-file decomposition safer.

### Option B — Migrate to CodeIgniter 4

CI4 is the closest reachable upgrade: same core team's mental model, HMVC-compatible community
packages exist, PHP 8.1+ native, actively maintained (unlike CI3).

- **Trade-offs:** Smallest conceptual jump for the current team (routing, controllers, `$this->db`
  patterns are all recognisable), but CI4 is not HMVC out of the box — the whole module boundary this
  codebase is built around (`application/modules/*`) would need re-implementing on CI4's namespaced
  module system, which is a different mechanism, not a drop-in. CI4's ecosystem is smaller than
  Laravel's, so problems this codebase will hit (queueing at scale, admin panel scaffolding) have less
  prior art to draw on. It buys PHP 8.1-native code and active security support, but doesn't buy DI,
  a job queue, or first-class testing tooling beyond what's hand-rolled today — those would still need
  building.

### Option C — Migrate to Laravel

The brief (§36) names Laravel explicitly as a candidate. Laravel has DI, Eloquent, queues, a large
ecosystem, and is what most PHP hiring pools know today.

- **Trade-offs:** Biggest ecosystem and hiring pool of any PHP option, real DI and queueing solve two
  problems Option A/B don't. But it's also the largest actual migration: every model (`Basic.php`
  generic CRUD + Grocery CRUD per ADR-000's context) needs an Eloquent equivalent, every HMVC module
  boundary needs a Laravel-shaped replacement (packages/service providers), and — most importantly —
  there is no foreign-key schema underneath any of this (ADR-000's context: "there are no foreign
  keys"), so Eloquent relationships would be built on data integrity Laravel assumes but this database
  doesn't enforce. That gap has to be closed (or explicitly worked around) before or during migration,
  not after. This is a rewrite-shaped amount of work even if done gradually; ADR-000 Rule 1 (brief)
  forbids a big-bang rewrite, so this option is only viable strangled in very small pieces over a long
  window, same as Option D.

### Option D — Extract a separate modern core service, leave the rest on CI3

Instead of migrating the whole app, build new bounded contexts (candidates: the AI orchestrator
currently in `Home.php`, and/or the CRM/Sales-Automation/Sequences group) as a separate service in a
modern framework (Laravel or a Node/NestJS service — brief mentions NestJS as well), reached from CI3
through the same kind of interface ADR-000 already uses for in-process modules, just now over HTTP/a
queue instead of a PHP function call.

- **Trade-offs:** Doesn't require ever migrating `Ecommerce.php` or `Sms_email_manager.php` at all —
  the parts of the legacy register marked "uncertain, KEEP pending confirmation" can stay exactly as
  they are indefinitely. Gives the extracted pieces a real modern runtime (DI, queues, typed code,
  independent deploys/rollbacks) without touching channel webhooks, which are the most failure-
  sensitive code in the system (ADR-002, rule 8: "webhook handlers must still answer 200 fast").
  Costs: a second runtime to operate (a service, not just a monolith) on a host that already runs this
  app plus other clients' production (see memory: shared host memory pressure) — needs its own
  resourcing decision, not assumed free. Also needs a network boundary between the service and CI3
  (latency, auth, versioning) that Option A/B/C don't, and duplicates some data access until the
  service owns its own tables cleanly.

## Recommendation

**Option D, narrowly, plus keep Option A as the default for everything not extracted.**

Concretely: don't migrate the whole application to any new framework right now. Continue ADR-000's
strangler approach on CI3 (Option A) as the default for the legacy controllers the register marks
KEEP/uncertain. For the one piece that has outgrown "a helper behind an interface" — the AI
orchestrator currently in `Home.php`'s `get_ai_reply_open_ai`, which ADR-000 already names as a
planned extraction and which now has real complexity (provider abstraction, tool loops, price
judging, safety) living in `libraries/Ai_provider.php`/`Ai_tools.php` next to it — evaluate pulling it
into its own service once (not before) it is fully extracted from `Home.php` in-process first. That
in-process extraction is the actual next step either way, and it's the same first step ADR-000 already
committed to; this ADR just says "and then, if it still feels too big for CI3, that's the one piece
worth its own runtime" rather than deciding the whole application's fate today.

This is a recommendation, not a decision — the choice of *when* and *whether* to spend the operational
cost of Option D, and the choice between Laravel/NestJS if it happens, is the owner's call (see below).

## What the owner must decide

1. **Timing:** is a framework change worth doing at all in the next 6–12 months, given the system is
   revenue-bearing, has no staging yet (a precondition of ADR-000 still open), and every extraction so
   far has been done safely *without* a framework change?
2. **Scope, if yes:** whole-application migration (Option B or C) vs. one extracted service (Option D)?
   This ADR recommends D-or-nothing, but that trades a cleaner long-term shape for the ongoing cost of
   running two runtimes — the owner may weigh that differently given team size and hosting budget.
3. **If Option D:** Laravel (PHP, team already knows the language, brief §36 names it first) or NestJS
   (brief also names it; would mean hiring/skilling into a second language). No evidence collected in
   this ADR favors one over the other — it's purely a team/hiring call.
4. **What "done" strangling looks like:** at what point (if ever) does the owner want to revisit
   whether the *rest* of the legacy controllers — `Ecommerce.php`, `Sms_email_manager.php`, and the
   REPLACE-marked channel bot controllers in the legacy register — get modernised, vs. staying on CI3
   indefinitely as Option A allows? This ADR does not answer that; it only unblocks the piece that was
   already named as ready.

## Consequences

**If the recommendation is followed:**

- No new migration risk is introduced to the revenue-bearing legacy controllers.
- The AI orchestrator extraction (already planned) proceeds regardless of this ADR's outcome, so no
  work is blocked waiting on the owner's decision.
- The owner gets a real decision point once that extraction is complete, informed by how much
  complexity actually moved out of `Home.php`, rather than deciding on file-line-count estimates today.

**If a full migration (Option B or C) is chosen instead:**

- It must be done strangler-style regardless — Rule 1 (brief) forbids a big-bang rewrite — so the
  timeline is long (likely 12+ months given ~40 stock-module parity per ADR-000's rejected
  alternative), and staging (ADR-000's still-open precondition) becomes mandatory, not optional,
  before it starts.
