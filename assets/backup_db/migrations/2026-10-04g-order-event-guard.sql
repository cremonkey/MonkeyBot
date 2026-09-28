-- Task 24 review fix (C9, brief 22): order.created must fire exactly once per paid order,
-- not on every reload/replay of the confirmation page. confirmation_message_sender() is
-- called from 14 different payment-gateway sites and is NOT itself gated on a real state
-- transition, so a plain "emit after reading the cart row" re-fires on every replay.
--
-- `order_event_emitted_at` is the atomic guard: the emit site does
--   UPDATE ecommerce_cart SET order_event_emitted_at = NOW() WHERE id = ? AND order_event_emitted_at IS NULL
-- and only emits when that UPDATE's affected_rows() > 0 -- the same "claim, don't check-then-act"
-- pattern automation_runs already uses for (rule, event) idempotency.
--
-- Code works before this migration runs: application/controllers/Ecommerce.php checks
-- field_exists('order_event_emitted_at', 'ecommerce_cart') (cached static, same pattern as
-- Inbox.php) and simply does not emit order.created until the column exists, rather than
-- risk emitting unguarded.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (ADD COLUMN IF NOT EXISTS, MariaDB 10.11). No semicolons in comments: the
-- runner splits on them.
DO 0;

ALTER TABLE `ecommerce_cart`
  ADD COLUMN IF NOT EXISTS `order_event_emitted_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Task 24: set once, atomically, the first time order.created is emitted for this cart/order row';
