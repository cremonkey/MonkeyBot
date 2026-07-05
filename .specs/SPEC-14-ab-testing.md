# SPEC-14: A/B testing for Messenger broadcasts (Phase 4.3)

> READ `.specs/MASTER-PLAN.md` header first. Scope: MESSENGER broadcasts only (engine: messenger_bot_broadcast_serial + _send tables, sender cron `Cron_job.php::subscriber_broadcaster`/`braodcast_message`).

## Task 0 — Study first (read-only)
Map the broadcast flow: how a campaign row in `messenger_bot_broadcast_serial` stores its message template, how `_send` rows fan out per subscriber, where the message content is rendered per recipient, and where delivery success is recorded. Write findings at top of your report.

## Task 1 — Migration (live + migrations file 2026-07-05-spec14.sql)
```sql
CREATE TABLE IF NOT EXISTS ab_test_variants (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, broadcast_serial_id INT NOT NULL,
 variant CHAR(1) NOT NULL, message LONGTEXT, sent_count INT DEFAULT 0, delivered_count INT DEFAULT 0,
 click_count INT DEFAULT 0, KEY idx_bc (broadcast_serial_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE messenger_bot_broadcast_serial_send ADD COLUMN ab_variant CHAR(1) NULL;
```
(Adjust FK names to real schema after Task 0; if serial_send is huge, ALTER may lock — check row count first, note in report.)

## Task 2 — Creation UI
In the broadcast create flow (find the compose view for messenger broadcast): add optional "A/B test" toggle revealing a second message textarea (Variant B). On save: store variant rows; mark campaign as A/B (reuse an existing free column or infer from ab_test_variants existence).

## Task 3 — Split + tracking
In the cron sender where each _send row's message is built: if campaign has variants → assign variant by `$send_row_id % 2` (deterministic), use that variant's message, stamp ab_variant, increment sent/delivered counters where success is recorded.

## Task 4 — Report view
Broadcast report page (find existing broadcast report view): when A/B, show per-variant sent/delivered (+click if any click tracking exists — if none, show sent/delivered only and note it) + simple winner banner (higher delivered rate).

## Task 5 — Verify
Lint; smoke; SQL-verify counters update path by tracing code (paste snippet). Commit: `feat: A/B testing for messenger broadcasts`.
