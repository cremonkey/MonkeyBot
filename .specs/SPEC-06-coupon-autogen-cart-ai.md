# SPEC-06: Auto-generated coupons + AI abandoned-cart messages (Phase 2.2)

> READ `.specs/MASTER-PLAN.md` header first. Touches Ecommerce.php + Cron_job.php — no concurrent specs on those files.

## Verified current state
- Abandoned-cart engine EXISTS: `Cron_job.php::ecommerce_abandoned_cart_reminder()` (~4309); buckets reminders per hour into messenger/sms/email (~4385-4397); messenger sender `ecommerce_send_messenger_reminder()` (~4279); up to 3 reminders; templates with `{{first_name}}`/`{{checkout_url}}`/`{{store_url}}` variables (defaults in `Ecommerce.php::reminder_default()` ~11690-11746, settings UI `reminder_settings(_action)` ~11756/11806, report table `ecommerce_reminder_report`).
- Coupons EXIST but codes are manual-only (`Ecommerce.php::add_coupon_action()` ~16732; validation `get_coupon_data()` ~18872; `used` counter ~10107).

## Task 1 — Coupon generator helper
`SHOW CREATE TABLE ecommerce_coupon` first. Create `application/helpers/coupon_helper.php`:
`generate_coupon($user_id, $store_id, $percent, $days_valid=7, $prefix='SAVE')` → unique 8-10 char uppercase code, insert row matching live columns (discount type percentage, expiry, active), return code string. Retry on code collision (unique check like `check_coupon` callback does).

## Task 2 — Reminder settings additions
Find where reminder settings persist (inspect `reminder_settings_action()` ~11806 — likely serialized into `ecommerce_config` or its own table). Add two options to settings + UI view (match existing form style):
- `ai_reminder_enabled` ('0'/'1') — "Generate reminder text with AI"
- `auto_coupon_percent` (0=off, else 5-30) — "Attach auto-generated coupon from reminder #2"
Persist wherever siblings persist; migration file `assets/backup_db/migrations/2026-07-05-spec06.sql` if a column is needed.

## Task 3 — Cron integration
In `ecommerce_abandoned_cart_reminder()`:
- When `ai_reminder_enabled`: build a one-shot prompt (customer name, cart items with names/prices — join `ecommerce_cart_item`→`ecommerce_product`, store name, checkout url) and call `Ai_provider->completion()` DIRECTLY (no memory; the user's open_ai_config row; short max_tokens ~200): "Write a short friendly cart-recovery message in the customer's language...". On ANY AI failure → fall back to the existing template. Keep the existing variable replacement as fallback path untouched.
- When `auto_coupon_percent > 0` AND reminder number ≥ 2: `generate_coupon(...)` and append "Use code {code} for {percent}% off" (or feed it into the AI prompt when AI enabled).
- Log AI/coupon usage into the existing `ecommerce_reminder_report` flow (reuse existing columns; do not ALTER unless required).

## Task 4 — Recovery metric
In the reminder report view (rendered around `Ecommerce.php` ~3379-3453): add a "Recovered" stat: carts that got a reminder and later reached completed/checkout status (join `ecommerce_reminder_report` with `ecommerce_cart` status — inspect status values first). One summary card/badge, no new page.

## Task 5 — Verify
Lint; smoke; run coupon generator once against live DB via a temp CLI-safe path or verify SQL by hand-executing the INSERT it would produce (then DELETE the test row). Commit: `feat: AI cart-recovery messages + auto coupons + recovery metric`.
