-- Multi-widget web chat: one widget per website, each bound to its own AI agent.
-- The UNIQUE(user_id) index forced exactly one widget per account and blocked the feature.
ALTER TABLE `webchat_settings` DROP INDEX `user_id`, ADD INDEX `user_id` (`user_id`);
