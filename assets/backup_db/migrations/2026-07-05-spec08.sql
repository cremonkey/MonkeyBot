ALTER TABLE `user_input_custom_fields` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_reply_error_log` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `visual_flow_builder_campaign` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_broadcast_serial` MODIFY `social_media` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `livechat_messages` MODIFY `platform` enum('fb','ig','wa','web','tg') NULL DEFAULT 'fb';
ALTER TABLE `canned_response` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `ai_conversation_history` MODIFY `social_media` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_subscriber` MODIFY `social_media` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_broadcast_contact_group` MODIFY `social_media` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_drip_campaign` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `otn_postback` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_saved_templates` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_persistent_menu` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';
ALTER TABLE `messenger_bot_postback` MODIFY `media_type` enum('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb';

-- NOTE: Code binary-channel assumptions to handle in channel specs (10/11/15):
-- Subscriber_manager.php ~802: `if social_media=='ig' {..} else != 'ig'` — treats non-ig as fb bucket.
-- Livechat/Home render icons per fb/ig; new channels (wa/web/tg) need their own branches/icons.
