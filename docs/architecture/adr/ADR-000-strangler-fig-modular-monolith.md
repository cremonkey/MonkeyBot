# ADR-000: Strangler Fig migration towards a modular monolith

- **Status:** Proposed
- **Date:** 2026-09-22
- **References:** brief §4 Rules 1, 5 and 7; brief §35, §36, §47 and §48; baseline sections B and H

## Context

Monkey Bot 8.5.4 is XeroChat on CodeIgniter 3.1.5 + HMVC. The core logic is concentrated in a few very large files:

- `Home.php` is 8,245 lines. It is the base controller and also holds the whole AI reply engine (`get_ai_reply_open_ai`).
- `Messenger_bot.php` is 14,840 lines. It holds the FB/IG DM engine.
- `Openwa_bot` **extends** `Messenger_bot`, so WhatsApp (OpenWA) inherits all of it.

Other structural facts:

- The models are only `Basic.php` (generic CRUD) and Grocery CRUD.
- There are no foreign keys.
- Every channel has its own tables.

This is also a revenue-bearing production system: 8 FB pages, 8,977 subscribers and live AI sales. It is deployed by live-editing a bind-mounted tree, and has no staging.

Custom work since SPEC-00 has already added bounded pieces alongside the legacy code: `Ai_provider`, `Ai_tools`, `channel_send_helper`, `fb_graph_helper`, `Cron_hub`, the knowledge base, CRM and Re-engage. These show that new capability can be introduced next to the legacy code without rewriting it.

## Decision

Evolve the system with the **Strangler Fig** pattern into a **modular monolith**, staying inside the existing PHP/CodeIgniter runtime for now.

1. **No big-bang rewrite.** New capabilities go into bounded modules: libraries, helpers or HMVC modules with a narrow public interface. Legacy controllers call those modules. The modules never call back into controller internals.
2. **Each extraction follows the same steps:**
   1. Put an interface in front of the legacy behaviour.
   2. Route callers through the interface.
   3. Write a new implementation behind a feature flag, starting in `log` or shadow mode.
   4. Verify it.
   5. Switch over.
   6. Delete the legacy path only after a deprecation window (brief §35).
3. **Planned first seams:**
   - credentials (`ai_openai_key` / `secret_decrypt`);
   - webhook ingress (`webhook_security_helper`);
   - outbound sending (`channel_send_helper`, which becomes the adapter in ADR-002);
   - conversation storage (ADR-001);
   - the AI orchestrator, extracted out of `Home.php`;
   - scheduler jobs (`Cron_hub`).
4. **Schema changes** go only through migration files (`assets/backup_db/migrations/` → `schema_migrations`). They are additive first. Destructive changes happen only after the data has been verified (brief §49).
5. **Framework choice deferred.** A modern core framework (brief §36, Laravel or NestJS) is decided in a later ADR (ADR-005). It happens only after the module seams exist, so a module could be moved behind an API without touching its callers.

## Alternatives

| Option | Why not now |
|---|---|
| Full rewrite on a new framework | Very high risk to live channels. It would need to reach parity with about 40 stock modules. The brief explicitly forbids it (Rule 1). |
| Keep patching in place | Makes the god controllers worse. Unified conversations and channel adapters cannot be reached this way. |
| Microservices split | Operational overhead the team and hosting cannot absorb. The data is coupled through shared tables. |

## Consequences

**Positive:**

- Production stays up throughout.
- Each step can be rolled back.
- Value is delivered step by step.
- The seams make contract tests possible (brief §44).

**Negative:**

- For a period there are two paths (legacy and new), which need flags and some duplicated logic.
- CodeIgniter 3 limits things like dependency injection, testing tools and PHP 8.x idioms until ADR-005.
- A deprecation discipline is needed, or the legacy paths will never be removed.

**Preconditions:**

- A pushed git baseline.
- Automated backups.
- Staging (baseline section I).
