-- Composite indexes for the hot query paths flagged in the audit.
-- Digest / follow-up scans: WHERE user_id=? AND sender='user' AND conversation_time>=?
ALTER TABLE `livechat_messages` ADD INDEX `user_sender_time` (`user_id`, `sender`, `conversation_time`);
-- Follow-up DM-existence + reply-audit lookups by subscriber
ALTER TABLE `livechat_messages` ADD INDEX `user_sub_platform` (`user_id`, `subscriber_id`, `platform`, `sender`);
