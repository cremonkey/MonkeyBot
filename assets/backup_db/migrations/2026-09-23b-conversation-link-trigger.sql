-- Phase 9.5 - links every new livechat_messages row to its conversation (ADR-001).
--
-- Runner compatibility: Cron_hub::migrate strips whole-line comments and splits statements
-- on a semicolon followed by a line break. Every semicolon INSIDE the trigger body is
-- therefore followed by an inline comment on the same line, so the body stays one statement.
-- Keep that convention when editing. By hand: the mariadb client with DELIMITER $$ also works.
--
-- Why a trigger: livechat_messages has seven writers (Home insert_livechat_data and the
-- system-message insert, Messenger_bot through Home, Openwa_bot, Whatsapp_bot, Telegram_bot,
-- Webchat and channel_log_message). A BEFORE INSERT trigger covers all of them, and any
-- future writer, without touching the hot webhook paths.
--
-- Safety: a CONTINUE HANDLER catches every catchable SQL error inside the body. The message
-- is then still inserted, with conversation_id NULL, and the next backfill run links it.
-- Lock-wait timeouts and deadlocks cannot be caught inside a trigger (see ADR-001), so no
-- code may hold a conversations row lock for long.
--
-- Lock order matches conversation_find_or_create(): customer_identities, customers,
-- conversations. The trigger never touches messenger_bot_subscriber.
--
-- Kill switch: DROP TRIGGER IF EXISTS trg_livechat_messages_link_conversation

DROP TRIGGER IF EXISTS `trg_livechat_messages_link_conversation`;

CREATE TRIGGER `trg_livechat_messages_link_conversation`
BEFORE INSERT ON `livechat_messages`
FOR EACH ROW
trg: BEGIN
  DECLARE v_uid INT DEFAULT NULL;  -- stmt
  DECLARE v_acct BIGINT DEFAULT 0;  -- stmt
  DECLARE v_time DATETIME DEFAULT NULL;  -- stmt
  DECLARE v_cust BIGINT UNSIGNED DEFAULT NULL;  -- stmt
  DECLARE v_new_cust BIGINT UNSIGNED DEFAULT NULL;  -- stmt
  DECLARE v_conv BIGINT UNSIGNED DEFAULT NULL;  -- stmt
  DECLARE v_state VARCHAR(24) DEFAULT NULL;  -- stmt
  DECLARE v_failed TINYINT DEFAULT 0;  -- stmt
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET v_failed = 1;  -- stmt

  IF NEW.conversation_id IS NOT NULL THEN LEAVE trg; END IF;  -- stmt
  IF NEW.subscriber_id IS NULL OR NEW.subscriber_id = '' OR NEW.platform IS NULL THEN LEAVE trg; END IF;  -- stmt

  -- tenant: the row's user_id, or for fb/ig system rows written without one, the page owner
  SET v_uid = NULLIF(NEW.user_id, 0);  -- stmt
  SET v_acct = IFNULL(NEW.page_table_id, 0);  -- stmt
  IF v_uid IS NULL AND v_acct > 0 AND NEW.platform IN ('fb', 'ig') THEN
    SET v_uid = (SELECT p.user_id FROM facebook_rx_fb_page_info p WHERE p.id = v_acct LIMIT 1);  -- stmt
  END IF;  -- stmt
  IF v_uid IS NULL OR v_failed = 1 THEN LEAVE trg; END IF;  -- stmt

  -- channel account: channel_log_message() writes fb/ig bot rows with page_table_id 0 and the
  -- Facebook page id, so resolve it to the page row the inbound rows carry
  IF v_acct = 0 AND NEW.platform IN ('fb', 'ig') AND IFNULL(NEW.fb_page_id, '') <> '' THEN
    SET v_acct = IFNULL((SELECT p.id FROM facebook_rx_fb_page_info p
                         WHERE p.user_id = v_uid AND p.page_id = NEW.fb_page_id
                         ORDER BY p.id LIMIT 1), 0);  -- stmt
  END IF;  -- stmt

  SET v_time = IFNULL(NEW.conversation_time, NOW());  -- stmt

  -- customer: reuse the identity's customer, else create one customer with one identity
  SET v_cust = (SELECT ci.customer_id FROM customer_identities ci
                WHERE ci.user_id = v_uid AND ci.platform = NEW.platform
                  AND ci.channel_account_id = v_acct AND ci.external_id = NEW.subscriber_id LIMIT 1);  -- stmt
  IF v_cust IS NULL AND v_failed = 0 THEN
    INSERT INTO customers (user_id, source, created_at, updated_at) VALUES (v_uid, 'thread', NOW(), NOW());  -- stmt
    SET v_new_cust = LAST_INSERT_ID();  -- stmt
    INSERT IGNORE INTO customer_identities (customer_id, user_id, platform, external_id, channel_account_id, verified, created_at)
      VALUES (v_new_cust, v_uid, NEW.platform, NEW.subscriber_id, v_acct, 0, NOW());  -- stmt
    SET v_cust = (SELECT ci.customer_id FROM customer_identities ci
                  WHERE ci.user_id = v_uid AND ci.platform = NEW.platform
                    AND ci.channel_account_id = v_acct AND ci.external_id = NEW.subscriber_id LIMIT 1);  -- stmt
    -- lost a race with a concurrent insert: drop the unused customer row
    IF v_new_cust IS NOT NULL AND (v_cust IS NULL OR v_cust <> v_new_cust) THEN
      DELETE FROM customers WHERE id = v_new_cust;  -- stmt
    END IF;  -- stmt
  END IF;  -- stmt

  -- conversation: create, or bump the cached counters
  INSERT INTO conversations (user_id, customer_id, platform, channel_account_id, external_thread_id,
                             status, handoff_state, last_message_at, last_inbound_at,
                             unread_count, message_count, created_at, updated_at)
  VALUES (v_uid, v_cust, NEW.platform, v_acct, NEW.subscriber_id,
          'open', 'AI_ACTIVE', v_time, IF(NEW.sender = 'user', v_time, NULL),
          IF(NEW.sender = 'user', 1, 0), 1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    customer_id     = IFNULL(customer_id, VALUES(customer_id)),
    message_count   = message_count + 1,
    last_message_at = GREATEST(IFNULL(last_message_at, VALUES(last_message_at)), VALUES(last_message_at)),
    last_inbound_at = IF(NEW.sender = 'user', GREATEST(IFNULL(last_inbound_at, VALUES(last_message_at)), VALUES(last_message_at)), last_inbound_at),
    unread_count    = CASE NEW.sender WHEN 'user' THEN unread_count + 1 WHEN 'bot' THEN 0 ELSE unread_count END,
    updated_at      = NOW();  -- stmt

  SELECT c.id, c.handoff_state INTO v_conv, v_state FROM conversations c
   WHERE c.user_id = v_uid AND c.platform = NEW.platform
     AND c.channel_account_id = v_acct AND c.external_thread_id = NEW.subscriber_id LIMIT 1;  -- stmt

  -- brief section 14: a new customer message reopens a resolved conversation
  IF v_conv IS NOT NULL AND v_state = 'RESOLVED' AND NEW.sender = 'user' AND v_failed = 0 THEN
    UPDATE conversations SET handoff_state = 'AI_ACTIVE', status = 'open', updated_at = NOW() WHERE id = v_conv;  -- stmt
    INSERT INTO conversation_events (conversation_id, user_id, event_type, from_state, to_state, actor_type, actor_id, note, created_at)
      VALUES (v_conv, v_uid, 'state_change', 'RESOLVED', 'AI_ACTIVE', 'customer', NULL, 'new customer message', NOW());  -- stmt
  END IF;  -- stmt

  IF v_failed = 0 THEN
    SET NEW.conversation_id = v_conv;  -- stmt
  END IF;  -- stmt
END;
