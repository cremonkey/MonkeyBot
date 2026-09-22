<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backfill and verification for the conversation core (Phase 9.5, ADR-001, brief section 49).
 * Entry point: php index.php cron_hub backfill_conversations [batch] [max_batches]
 *
 * Resumable and idempotent. Every step either selects only rows that still need work
 * (conversation_id IS NULL, subscribers without an identity) or recomputes a value from
 * source rows, so a second run changes nothing and an interrupted run continues where it
 * stopped. livechat_messages content is never copied or read, only its keys.
 *
 * Steps
 *  1. customers + customer_identities from messenger_bot_subscriber (one customer per
 *     subscriber identity, never merged), then enrich customers the link trigger created
 *  2. conversations from distinct livechat_messages threads, set livechat_messages.conversation_id
 *  3. recompute message_count, last_message_at, last_inbound_at, unread_count
 *  4. fill missing conversations.customer_id
 *  5. handoff_state = HUMAN_ACTIVE where the legacy bot_paused_until is in the future
 *  6. verification report
 */

require_once __DIR__ . '/conversation_helper.php';

if (!function_exists('conversation_backfill_tenant_sql')) {
    /** SQL for the tenant of livechat row alias m: user_id, or the page owner for fb/ig rows
     *  written without one. Identical to the link trigger. */
    function conversation_backfill_tenant_sql($m = 'm')
    {
        return "COALESCE(NULLIF({$m}.user_id, 0), IF({$m}.platform IN ('fb','ig') AND IFNULL({$m}.page_table_id, 0) > 0,
                  (SELECT p.user_id FROM facebook_rx_fb_page_info p WHERE p.id = {$m}.page_table_id LIMIT 1), NULL))";
    }
}

if (!function_exists('conversation_backfill_account_sql')) {
    /** SQL for the channel account of livechat row alias m whose tenant is in column $uid. */
    function conversation_backfill_account_sql($m = 'm', $uid = 'x.uid')
    {
        return "CASE WHEN IFNULL({$m}.page_table_id, 0) = 0 AND {$m}.platform IN ('fb','ig') AND IFNULL({$m}.fb_page_id, '') <> ''
                     THEN IFNULL((SELECT p.id FROM facebook_rx_fb_page_info p WHERE p.user_id = {$uid} AND p.page_id = {$m}.fb_page_id ORDER BY p.id LIMIT 1), 0)
                     ELSE IFNULL({$m}.page_table_id, 0) END";
    }
}

if (!function_exists('conversation_backfill_q')) {
    /** Run a query, throw on failure (db_debug is FALSE in production, so check returns). */
    function conversation_backfill_q($sql, $binds = array())
    {
        $db = conversation_db();
        $r = $db->query($sql, $binds);
        if ($r === FALSE) {
            $e = $db->error();
            throw new RuntimeException('backfill query failed: ' . ($e['message'] ?? '') . ' :: ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 160));
        }
        return $r;
    }
}

if (!function_exists('conversation_backfill_scalar')) {
    function conversation_backfill_scalar($sql, $binds = array())
    {
        $row = conversation_backfill_q($sql, $binds)->row_array();
        return $row ? reset($row) : null;
    }
}

if (!function_exists('conversation_backfill_run')) {
    /**
     * @param int      $batch        rows per batch (subscribers, messages, conversations)
     * @param int      $max_batches  stop the message step after N batches (0 = until done), to
     *                               bound one run or to prove resumability
     * @param callable $log          function(string) for progress lines
     * @return array   counters of work done in this run
     */
    function conversation_backfill_run($batch = 5000, $max_batches = 0, $log = null)
    {
        $batch = max(100, (int) $batch);
        $log = $log ?: function ($s) {};
        $now = date('Y-m-d H:i:s'); // PHP time: bot_paused_until is written and compared in PHP time
        // READ COMMITTED: the multi-table UPDATE/INSERT ... SELECT statements then read the
        // joined tables without shared row locks, so a batch never makes the link trigger
        // (which upserts conversations inside every livechat INSERT) wait on it.
        $iso = conversation_backfill_scalar('SELECT @@SESSION.tx_isolation');
        conversation_backfill_q('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            return conversation_backfill_steps($batch, $max_batches, $log, $now);
        } finally {
            conversation_backfill_q('SET SESSION TRANSACTION ISOLATION LEVEL ' . str_replace('-', ' ', $iso ?: 'REPEATABLE-READ'));
        }
    }
}

if (!function_exists('conversation_backfill_steps')) {
    function conversation_backfill_steps($batch, $max_batches, $log, $now)
    {
        $done = array('customers_created' => 0, 'identities_created' => 0, 'customers_enriched' => 0,
                      'conversations_created' => 0, 'messages_linked' => 0, 'message_batches' => 0,
                      'conversations_recounted' => 0, 'customer_links_filled' => 0,
                      'handoff_human_active' => 0, 'assignments_copied' => 0, 'complete' => true);

        // ---- 1. customers and identities from subscribers -------------------------------
        $cursor = 0;
        while (true) {
            $hi = conversation_backfill_scalar(
                'SELECT MAX(id) FROM (SELECT id FROM messenger_bot_subscriber WHERE id > ? ORDER BY id LIMIT ' . $batch . ') b', array($cursor));
            if ($hi === null) break;
            $hi = (int) $hi;
            conversation_backfill_q(
                "INSERT INTO customers (user_id, display_name, phone, email, lead_score, source, legacy_subscriber_id, created_at, updated_at)
                 SELECT s.user_id, NULLIF(TRIM(s.full_name), ''), NULLIF(s.phone_number, ''), NULLIF(s.email, ''), s.lead_score,
                        'subscriber', s.id, ?, ?
                   FROM messenger_bot_subscriber s
                   LEFT JOIN customer_identities ci
                          ON ci.user_id = s.user_id AND ci.platform = s.social_media
                         AND ci.channel_account_id = s.page_table_id AND ci.external_id = s.subscribe_id
                   LEFT JOIN customers c ON c.legacy_subscriber_id = s.id
                  WHERE s.id > ? AND s.id <= ? AND s.subscribe_id <> '' AND ci.id IS NULL AND c.id IS NULL
                    AND NOT EXISTS (SELECT 1 FROM messenger_bot_subscriber s2
                                     WHERE s2.subscribe_id = s.subscribe_id AND s2.user_id = s.user_id
                                       AND s2.social_media = s.social_media AND s2.page_table_id = s.page_table_id AND s2.id < s.id)",
                array($now, $now, $cursor, $hi));
            $done['customers_created'] += conversation_db()->affected_rows();
            conversation_backfill_q(
                "INSERT IGNORE INTO customer_identities (customer_id, user_id, platform, external_id, channel_account_id, verified, created_at)
                 SELECT c.id, s.user_id, s.social_media, s.subscribe_id, s.page_table_id, 0, ?
                   FROM customers c JOIN messenger_bot_subscriber s ON s.id = c.legacy_subscriber_id
                  WHERE s.id > ? AND s.id <= ?",
                array($now, $cursor, $hi));
            $done['identities_created'] += conversation_db()->affected_rows();
            // Lost a race with the link trigger: it created this identity (and its own customer)
            // between our two statements, so our customer got no identity. Drop it - nothing can
            // reference a customer without an identity - and let the enrich step below attach
            // the subscriber to the trigger's customer instead.
            conversation_backfill_q(
                "DELETE c FROM customers c
                   LEFT JOIN customer_identities ci ON ci.customer_id = c.id
                   LEFT JOIN conversations v ON v.customer_id = c.id
                  WHERE c.source = 'subscriber' AND c.legacy_subscriber_id > ? AND c.legacy_subscriber_id <= ?
                    AND ci.id IS NULL AND v.id IS NULL",
                array($cursor, $hi));
            $done['customers_created'] -= conversation_db()->affected_rows();
            // Customers the link trigger created before this subscriber was backfilled: attach
            // the subscriber and fill empty profile fields. UPDATE IGNORE skips a subscriber
            // that another customer already holds.
            conversation_backfill_q(
                "UPDATE IGNORE customers c
                   JOIN customer_identities ci ON ci.customer_id = c.id
                   JOIN messenger_bot_subscriber s
                     ON s.user_id = ci.user_id AND s.social_media = ci.platform
                    AND s.page_table_id = ci.channel_account_id AND s.subscribe_id = ci.external_id
                    SET c.legacy_subscriber_id = s.id, c.source = 'subscriber',
                        c.display_name = COALESCE(c.display_name, NULLIF(TRIM(s.full_name), '')),
                        c.phone = COALESCE(c.phone, NULLIF(s.phone_number, '')),
                        c.email = COALESCE(c.email, NULLIF(s.email, '')),
                        c.lead_score = s.lead_score, c.updated_at = ?
                  WHERE c.legacy_subscriber_id IS NULL AND s.id > ? AND s.id <= ?",
                array($now, $cursor, $hi));
            $done['customers_enriched'] += conversation_db()->affected_rows();
            // lead_score is a snapshot of the legacy value (lead scoring still writes the subscriber)
            conversation_backfill_q(
                'UPDATE customers c JOIN messenger_bot_subscriber s ON s.id = c.legacy_subscriber_id
                    SET c.lead_score = s.lead_score WHERE s.id > ? AND s.id <= ? AND c.lead_score <> s.lead_score',
                array($cursor, $hi));
            $cursor = $hi;
        }
        $log("customers: +{$done['customers_created']} created, +{$done['identities_created']} identities, {$done['customers_enriched']} enriched");

        // ---- 2. conversations from message threads, link messages ------------------------
        conversation_backfill_q('DROP TEMPORARY TABLE IF EXISTS tmp_conv_backfill');
        conversation_backfill_q(
            'CREATE TEMPORARY TABLE tmp_conv_backfill (
               msg_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, uid INT NOT NULL, platform VARCHAR(16) NOT NULL,
               acct BIGINT NOT NULL, ext VARCHAR(191) NOT NULL,
               KEY k (uid, platform, acct, ext)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $cursor = 0;
        $tenant = conversation_backfill_tenant_sql('m');
        $account = conversation_backfill_account_sql('x', 'x.uid');
        while (true) {
            if ($max_batches > 0 && $done['message_batches'] >= $max_batches) { $done['complete'] = false; break; }
            $hi = conversation_backfill_scalar(
                'SELECT MAX(id) FROM (SELECT id FROM livechat_messages WHERE conversation_id IS NULL AND id > ? ORDER BY id LIMIT ' . $batch . ') b',
                array($cursor));
            if ($hi === null) break;
            $hi = (int) $hi;
            $done['message_batches']++;
            conversation_backfill_q('DELETE FROM tmp_conv_backfill');
            conversation_backfill_q(
                "INSERT INTO tmp_conv_backfill (msg_id, uid, platform, acct, ext)
                 SELECT x.id, x.uid, x.platform, {$account}, x.subscriber_id
                   FROM (SELECT m.id, m.platform, m.subscriber_id, m.page_table_id, m.fb_page_id, {$tenant} AS uid
                           FROM livechat_messages m
                          WHERE m.conversation_id IS NULL AND m.id > ? AND m.id <= ?
                            AND m.subscriber_id IS NOT NULL AND m.subscriber_id <> '' AND m.platform IS NOT NULL) x
                  WHERE x.uid IS NOT NULL",
                array($cursor, $hi));
            // new threads: create through the helper so customer creation follows one code path
            $new = conversation_backfill_q(
                'SELECT DISTINCT t.uid, t.platform, t.acct, t.ext FROM tmp_conv_backfill t
                   LEFT JOIN conversations c ON c.user_id = t.uid AND c.platform = t.platform
                        AND c.channel_account_id = t.acct AND c.external_thread_id = t.ext
                  WHERE c.id IS NULL')->result_array();
            foreach ($new as $k) {
                if (conversation_find_or_create($k['uid'], $k['platform'], $k['acct'], $k['ext']) > 0) $done['conversations_created']++;
            }
            conversation_backfill_q(
                'UPDATE livechat_messages m
                   JOIN tmp_conv_backfill t ON t.msg_id = m.id
                   JOIN conversations c ON c.user_id = t.uid AND c.platform = t.platform
                        AND c.channel_account_id = t.acct AND c.external_thread_id = t.ext
                    SET m.conversation_id = c.id
                  WHERE m.conversation_id IS NULL');
            $done['messages_linked'] += conversation_db()->affected_rows();
            $cursor = $hi;
        }
        conversation_backfill_q('DROP TEMPORARY TABLE IF EXISTS tmp_conv_backfill');
        $log("messages: {$done['messages_linked']} linked in {$done['message_batches']} batch(es), {$done['conversations_created']} conversations created"
            . ($done['complete'] ? '' : ' (stopped at max_batches, run again to continue)'));

        // ---- 3. recompute cached counters ------------------------------------------------
        $cursor = 0;
        while (true) {
            $hi = conversation_backfill_scalar(
                'SELECT MAX(id) FROM (SELECT id FROM conversations WHERE id > ? ORDER BY id LIMIT ' . $batch . ') b', array($cursor));
            if ($hi === null) break;
            $hi = (int) $hi;
            conversation_backfill_q(
                "UPDATE conversations c
                   JOIN (SELECT m.conversation_id AS cid, COUNT(*) AS n, MAX(m.conversation_time) AS last_t,
                                MAX(IF(m.sender = 'user', m.conversation_time, NULL)) AS last_in,
                                SUM(m.sender = 'user' AND m.id > IFNULL(lb.last_bot_id, 0)) AS unread
                           FROM livechat_messages m
                           JOIN (SELECT conversation_id, MAX(IF(sender = 'bot', id, NULL)) AS last_bot_id
                                   FROM livechat_messages WHERE conversation_id > ? AND conversation_id <= ?
                                  GROUP BY conversation_id) lb ON lb.conversation_id = m.conversation_id
                          WHERE m.conversation_id > ? AND m.conversation_id <= ?
                          GROUP BY m.conversation_id) a ON a.cid = c.id
                    SET c.message_count = a.n, c.last_message_at = a.last_t, c.last_inbound_at = a.last_in, c.unread_count = a.unread
                  WHERE c.message_count <> a.n OR NOT (c.last_message_at <=> a.last_t)
                     OR NOT (c.last_inbound_at <=> a.last_in) OR c.unread_count <> a.unread",
                array($cursor, $hi, $cursor, $hi));
            $done['conversations_recounted'] += conversation_db()->affected_rows();
            // threads whose messages were all removed by retention
            conversation_backfill_q(
                'UPDATE conversations c SET c.message_count = 0, c.unread_count = 0
                  WHERE c.id > ? AND c.id <= ? AND (c.message_count <> 0 OR c.unread_count <> 0)
                    AND NOT EXISTS (SELECT 1 FROM livechat_messages m WHERE m.conversation_id = c.id)',
                array($cursor, $hi));
            $done['conversations_recounted'] += conversation_db()->affected_rows();
            $cursor = $hi;
        }
        $log("counters: {$done['conversations_recounted']} conversation(s) corrected");

        // ---- 4. customer links -----------------------------------------------------------
        conversation_backfill_q(
            'UPDATE conversations c JOIN customer_identities ci
                 ON ci.user_id = c.user_id AND ci.platform = c.platform
                AND ci.channel_account_id = c.channel_account_id AND ci.external_id = c.external_thread_id
                SET c.customer_id = ci.customer_id WHERE c.customer_id IS NULL');
        $done['customer_links_filled'] = conversation_db()->affected_rows();

        // ---- 5. legacy handoff -> state ----------------------------------------------------
        $db = conversation_db();
        $db->trans_begin();
        try {
        conversation_backfill_q(
            "INSERT INTO conversation_events (conversation_id, user_id, event_type, from_state, to_state, actor_type, actor_id, note, created_at)
             SELECT DISTINCT c.id, c.user_id, 'backfill_state', c.handoff_state, 'HUMAN_ACTIVE', 'system', NULL,
                    'legacy bot_paused_until is in the future', ?
               FROM conversations c JOIN messenger_bot_subscriber s
                 ON s.user_id = c.user_id AND s.social_media = c.platform
                AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
              WHERE c.handoff_state = 'AI_ACTIVE' AND s.bot_paused_until > ?",
            array($now, $now));
        conversation_backfill_q(
            "UPDATE conversations c JOIN messenger_bot_subscriber s
                 ON s.user_id = c.user_id AND s.social_media = c.platform
                AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
                SET c.handoff_state = 'HUMAN_ACTIVE', c.status = 'open', c.updated_at = ?
              WHERE c.handoff_state = 'AI_ACTIVE' AND s.bot_paused_until > ?",
            array($now, $now));
        $done['handoff_human_active'] = $db->affected_rows();
        } catch (Throwable $e) { $db->trans_rollback(); throw $e; }
        $db->trans_commit();
        conversation_backfill_q(
            'UPDATE conversations c JOIN messenger_bot_subscriber s
                 ON s.user_id = c.user_id AND s.social_media = c.platform
                AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
                SET c.assigned_user_id = s.assigned_used_id
              WHERE c.assigned_user_id IS NULL AND s.assigned_used_id IS NOT NULL AND s.assigned_used_id > 0');
        $done['assignments_copied'] = conversation_db()->affected_rows();
        $log("handoff: {$done['handoff_human_active']} set HUMAN_ACTIVE from bot_paused_until, {$done['assignments_copied']} assignment(s) copied");

        return $done;
    }
}

if (!function_exists('conversation_backfill_report')) {
    /**
     * Verification report (brief section 49). Counts only - never message content.
     * Each entry of 'checks' is [label, value, expected] where expected is the value a
     * healthy database shows (NULL = informational).
     */
    function conversation_backfill_report()
    {
        $now = date('Y-m-d H:i:s');
        $tenant = conversation_backfill_tenant_sql('m');
        $account = conversation_backfill_account_sql('x', 'x.uid');
        $r = array('per_platform' => array(), 'checks' => array());

        $src = conversation_backfill_q(
            "SELECT y.platform, COUNT(*) AS threads FROM (
                SELECT DISTINCT x.uid, x.platform, {$account} AS acct, x.subscriber_id
                  FROM (SELECT m.platform, m.subscriber_id, m.page_table_id, m.fb_page_id, {$tenant} AS uid
                          FROM livechat_messages m
                         WHERE m.subscriber_id IS NOT NULL AND m.subscriber_id <> '' AND m.platform IS NOT NULL) x
                 WHERE x.uid IS NOT NULL) y GROUP BY y.platform")->result_array();
        $conv = conversation_backfill_q(
            'SELECT platform, COUNT(*) AS n, SUM(message_count > 0) AS with_msgs, SUM(handoff_state = \'HUMAN_ACTIVE\') AS human,
                    SUM(message_count) AS msg_sum FROM conversations GROUP BY platform')->result_array();
        $msgs = conversation_backfill_q(
            'SELECT platform, COUNT(*) AS total, SUM(conversation_id IS NOT NULL) AS linked FROM livechat_messages GROUP BY platform')->result_array();
        foreach (conversation_platforms() as $p) {
            $r['per_platform'][$p] = array('source_threads' => 0, 'conversations' => 0, 'conversations_with_messages' => 0,
                'human_active' => 0, 'messages_total' => 0, 'messages_linked' => 0, 'message_count_sum' => 0);
        }
        foreach ($src as $s) { if (isset($r['per_platform'][$s['platform']])) $r['per_platform'][$s['platform']]['source_threads'] = (int) $s['threads']; }
        foreach ($conv as $c) {
            if (!isset($r['per_platform'][$c['platform']])) continue;
            $r['per_platform'][$c['platform']]['conversations'] = (int) $c['n'];
            $r['per_platform'][$c['platform']]['conversations_with_messages'] = (int) $c['with_msgs'];
            $r['per_platform'][$c['platform']]['human_active'] = (int) $c['human'];
            $r['per_platform'][$c['platform']]['message_count_sum'] = (int) $c['msg_sum'];
        }
        foreach ($msgs as $m) {
            $k = $m['platform'] === null ? 'NULL' : $m['platform'];
            if (!isset($r['per_platform'][$k])) $r['per_platform'][$k] = array('source_threads' => 0, 'conversations' => 0,
                'conversations_with_messages' => 0, 'human_active' => 0, 'messages_total' => 0, 'messages_linked' => 0, 'message_count_sum' => 0);
            $r['per_platform'][$k]['messages_total'] = (int) $m['total'];
            $r['per_platform'][$k]['messages_linked'] = (int) $m['linked'];
        }

        $c = function ($label, $sql, $expected = 0, $binds = array()) use (&$r) {
            $r['checks'][] = array($label, (int) conversation_backfill_scalar($sql, $binds), $expected);
        };
        $c('messages total', 'SELECT COUNT(*) FROM livechat_messages', null);
        $c('messages linked', 'SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NOT NULL', null);
        $c('orphans: empty subscriber_id (unlinkable by design)',
            "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NULL AND (subscriber_id IS NULL OR subscriber_id = '')", null);
        $c('orphans: no resolvable tenant (unlinkable by design)',
            "SELECT COUNT(*) FROM livechat_messages m WHERE m.conversation_id IS NULL AND m.subscriber_id <> '' AND {$tenant} IS NULL", null);
        $c('orphans: linkable but not linked',
            "SELECT COUNT(*) FROM livechat_messages m WHERE m.conversation_id IS NULL AND m.subscriber_id <> '' AND m.platform IS NOT NULL AND {$tenant} IS NOT NULL");
        $c('messages linked to a missing conversation',
            'SELECT COUNT(*) FROM livechat_messages m LEFT JOIN conversations c ON c.id = m.conversation_id WHERE m.conversation_id IS NOT NULL AND c.id IS NULL');
        $c('messages whose key differs from their conversation (platform or thread id)',
            'SELECT COUNT(*) FROM livechat_messages m JOIN conversations c ON c.id = m.conversation_id
              WHERE NOT (c.platform <=> m.platform) OR c.external_thread_id <> m.subscriber_id');
        $c('tenant mismatch: message user_id <> conversation user_id',
            'SELECT COUNT(*) FROM livechat_messages m JOIN conversations c ON c.id = m.conversation_id
              WHERE m.user_id IS NOT NULL AND m.user_id <> 0 AND m.user_id <> c.user_id');
        $c('messages with NULL user_id linked via their page owner',
            'SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NOT NULL AND (user_id IS NULL OR user_id = 0)', null);
        $c('tenant mismatch: conversation vs customer',
            'SELECT COUNT(*) FROM conversations c JOIN customers u ON u.id = c.customer_id WHERE u.user_id <> c.user_id');
        $c('tenant mismatch: identity vs customer',
            'SELECT COUNT(*) FROM customer_identities ci JOIN customers u ON u.id = ci.customer_id WHERE u.user_id <> ci.user_id');
        $c('tenant mismatch: customer vs legacy subscriber',
            'SELECT COUNT(*) FROM customers u JOIN messenger_bot_subscriber s ON s.id = u.legacy_subscriber_id WHERE s.user_id <> u.user_id');
        $c('duplicate conversation keys',
            'SELECT COUNT(*) FROM (SELECT 1 FROM conversations GROUP BY user_id, platform, channel_account_id, external_thread_id HAVING COUNT(*) > 1) d');
        $c('duplicate identity keys',
            'SELECT COUNT(*) FROM (SELECT 1 FROM customer_identities GROUP BY user_id, platform, channel_account_id, external_id HAVING COUNT(*) > 1) d');
        $c('customers with more than one identity (no automatic merging)',
            'SELECT COUNT(*) FROM (SELECT 1 FROM customer_identities GROUP BY customer_id HAVING COUNT(*) > 1) d');
        $c('customers without an identity',
            'SELECT COUNT(*) FROM customers u LEFT JOIN customer_identities ci ON ci.customer_id = u.id WHERE ci.id IS NULL');
        $c('conversations without a customer', 'SELECT COUNT(*) FROM conversations WHERE customer_id IS NULL');
        $c('subscriber identities without a customer identity',
            "SELECT COUNT(*) FROM messenger_bot_subscriber s LEFT JOIN customer_identities ci
                ON ci.user_id = s.user_id AND ci.platform = s.social_media AND ci.channel_account_id = s.page_table_id AND ci.external_id = s.subscribe_id
              WHERE s.subscribe_id <> '' AND ci.id IS NULL");
        $c('duplicate subscriber rows for one identity (legacy data, informational)',
            'SELECT COUNT(*) FROM (SELECT 1 FROM messenger_bot_subscriber GROUP BY user_id, social_media, page_table_id, subscribe_id HAVING COUNT(*) > 1) d', null);
        $c('message_count sum <> linked messages',
            'SELECT ABS((SELECT IFNULL(SUM(message_count), 0) FROM conversations) - (SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NOT NULL))');
        $c('subscribers paused now (legacy)', 'SELECT COUNT(*) FROM messenger_bot_subscriber WHERE bot_paused_until > ?', null, array($now));
        $c('paused subscribers whose conversation is not in a human state',
            "SELECT COUNT(*) FROM conversations c JOIN messenger_bot_subscriber s
                ON s.user_id = c.user_id AND s.social_media = c.platform AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
              WHERE s.bot_paused_until > ? AND c.handoff_state NOT IN ('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE', 'AI_ASSIST')", 0, array($now));
        $c('paused subscribers with no conversation (no messages yet)',
            "SELECT COUNT(*) FROM messenger_bot_subscriber s LEFT JOIN conversations c
                ON s.user_id = c.user_id AND s.social_media = c.platform AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
              WHERE s.bot_paused_until > ? AND c.id IS NULL", null, array($now));
        // utf8mb4 / Arabic sanity: compare lengths and bytes of copied values, never print them
        $c('utf8mb4: customer display_name differs from subscriber full_name',
            "SELECT COUNT(*) FROM customers u JOIN messenger_bot_subscriber s ON s.id = u.legacy_subscriber_id
              WHERE u.display_name IS NOT NULL AND (CHAR_LENGTH(u.display_name) <> CHAR_LENGTH(TRIM(s.full_name))
                 OR CAST(u.display_name AS BINARY) <> CAST(CONVERT(TRIM(s.full_name) USING utf8mb4) AS BINARY))");
        $c('utf8mb4: identity external_id differs from subscriber subscribe_id',
            'SELECT COUNT(*) FROM customers u JOIN messenger_bot_subscriber s ON s.id = u.legacy_subscriber_id
               JOIN customer_identities ci ON ci.customer_id = u.id
              WHERE CHAR_LENGTH(ci.external_id) <> CHAR_LENGTH(s.subscribe_id)
                 OR CAST(ci.external_id AS BINARY) <> CAST(CONVERT(s.subscribe_id USING utf8mb4) AS BINARY)');
        $c('utf8mb4: conversation thread id differs from message subscriber_id',
            'SELECT COUNT(*) FROM livechat_messages m JOIN conversations c ON c.id = m.conversation_id
              WHERE CHAR_LENGTH(c.external_thread_id) <> CHAR_LENGTH(m.subscriber_id)');
        $c('utf8mb4: customers with an Arabic display_name', "SELECT COUNT(*) FROM customers WHERE display_name REGEXP '[\\\\x{0600}-\\\\x{06FF}]'", null);
        $c('utf8mb4: linked messages containing Arabic', "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NOT NULL AND message_content REGEXP '[\\\\x{0600}-\\\\x{06FF}]'", null);
        $c('utf8mb4: linked messages containing 4-byte characters (emoji)',
            "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id IS NOT NULL AND message_content REGEXP '[\\\\x{10000}-\\\\x{10FFFF}]'", null);
        $c('utf8mb4: new tables or columns not utf8mb4',
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                AND table_name IN ('conversations','conversation_events','customers','customer_identities','customer_merge_log')
                AND character_set_name IS NOT NULL AND character_set_name <> 'utf8mb4'");
        if (conversation_db()->table_exists('openwa_accounts') && conversation_db()->table_exists('whatsapp_accounts')) {
            $c('wa account-id collisions (same tenant owns OpenWA and WA Cloud accounts with one id)',
                'SELECT COUNT(*) FROM openwa_accounts o JOIN whatsapp_accounts w ON w.id = o.id AND w.user_id = o.user_id', 0);
        }
        $c('link trigger installed',
            "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_livechat_messages_link_conversation'", 1);
        return $r;
    }
}

if (!function_exists('conversation_backfill_format_report')) {
    function conversation_backfill_format_report($r)
    {
        $lines = array();
        $lines[] = sprintf('%-6s %14s %13s %10s %12s %14s %15s %12s', 'plat', 'source_threads', 'conversations', 'with_msgs', 'human_active', 'messages_total', 'messages_linked', 'count_sum');
        $fail = 0;
        foreach ($r['per_platform'] as $p => $v) {
            $ok = $v['source_threads'] === $v['conversations_with_messages'] && $v['message_count_sum'] === $v['messages_linked'];
            if (!$ok) $fail++;
            $lines[] = sprintf('%-6s %14d %13d %10d %12d %14d %15d %12d %s', $p, $v['source_threads'], $v['conversations'],
                $v['conversations_with_messages'], $v['human_active'], $v['messages_total'], $v['messages_linked'], $v['message_count_sum'], $ok ? 'OK' : 'MISMATCH');
        }
        $lines[] = '';
        foreach ($r['checks'] as $chk) {
            list($label, $val, $exp) = $chk;
            $flag = $exp === null ? 'info' : ($val === $exp ? 'OK' : 'FAIL');
            if ($flag === 'FAIL') $fail++;
            $lines[] = sprintf('  [%-4s] %-90s %d', $flag, $label, $val);
        }
        $lines[] = '';
        $lines[] = $fail === 0 ? 'VERIFICATION: PASS' : "VERIFICATION: FAIL ({$fail} problem(s))";
        return array(implode("\n", $lines), $fail);
    }
}
