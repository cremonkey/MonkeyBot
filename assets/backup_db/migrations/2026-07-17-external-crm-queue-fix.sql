-- The queue-claim step sets status='processing'; the original enum lacked that value so
-- MySQL silently stored '' (non-strict mode) and claimed rows vanished. Add it.
ALTER TABLE `crm_external_log`
  MODIFY COLUMN `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending';
