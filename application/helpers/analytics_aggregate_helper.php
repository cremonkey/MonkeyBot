<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Fills `analytics_daily` (remaining-work Task 21 / C6, brief SPEC-13/29/41, migration
 * 2026-10-04f-analytics-daily.sql). Called from Cron_hub.php's analytics_daily step
 * (_run_analytics_daily(), registered in run()), so it runs on every cron tick and works
 * without the job queue (jobs_enabled) being on.
 *
 * Each tick: recompute YESTERDAY in full (the day is closed, so its numbers are final) plus
 * TODAY incrementally (so the page has same-day numbers without waiting for midnight).
 * Everything is bounded per run - one query set per tenant per day, LIMITed subqueries where a
 * table could otherwise be scanned unbounded (see analytics_aggregate_stalled_after_price_quote).
 *
 * All functions here take a CI-style $db (->query($sql, $binds), ->table_exists()) so they work
 * against both the real CodeIgniter DB driver and tests/analytics/run.php's FakeDb.
 */

require_once __DIR__ . '/analytics_kpi_helper.php';

/** Tables this aggregator reads. A missing table just means that metric stays 0 for now. */
function analytics_aggregate_required_tables()
{
    return array(
        'conversations', 'conversation_events', 'livechat_messages', 'crm_deals',
        'sequence_step_log', 'reengage_recipient', 'ai_usage_log', 'ai_unanswered_questions',
        'ai_price_guard_log',
    );
}

/**
 * Every user_id with any conversation activity, so we don't loop every tenant in the system
 * on every tick. Bounded by $limit (a single cron tick processes at most this many tenants;
 * the rest catch up on the next tick).
 */
function analytics_aggregate_active_tenants($db, $limit = 200)
{
    if (!$db->table_exists('conversations')) return array();
    $rows = $db->query(
        "SELECT DISTINCT user_id FROM conversations WHERE last_message_at >= ? ORDER BY user_id LIMIT ?",
        array(date('Y-m-d 00:00:00', strtotime('-2 days')), (int) $limit)
    )->result_array();
    return array_map(function ($r) { return (int) $r['user_id']; }, $rows);
}

/**
 * Compute one tenant's metrics for one calendar day, broken down by channel (platform) plus
 * an 'all' total. Returns array: channel => [metric => value].
 */
function analytics_aggregate_compute_day($db, $uid, $day)
{
    $uid = (int) $uid;
    $start = $day . ' 00:00:00';
    $end = $day . ' 23:59:59';
    $out = array('all' => array_fill_keys(analytics_kpi_metric_names(), 0.0));

    $add = function ($channel, $metric, $value) use (&$out) {
        $channel = $channel !== '' ? $channel : 'other';
        if (!isset($out[$channel])) $out[$channel] = array_fill_keys(analytics_kpi_metric_names(), 0.0);
        $out[$channel][$metric] += (float) $value;
        $out['all'][$metric] += (float) $value;
    };

    // chats: distinct conversations with an inbound message that day
    if ($db->table_exists('livechat_messages')) {
        foreach ($db->query(
            "SELECT platform, COUNT(DISTINCT subscriber_id) c FROM livechat_messages
             WHERE user_id=? AND sender='user' AND conversation_time BETWEEN ? AND ? GROUP BY platform",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_CHATS, $r['c']);
        }
    }

    // qualified leads + bookings: crm_deals
    if ($db->table_exists('crm_deals')) {
        foreach ($db->query(
            "SELECT source, COUNT(*) c FROM crm_deals WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY source",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['source'], ANALYTICS_METRIC_QUALIFIED_LEADS, $r['c']);
        }
        foreach ($db->query(
            "SELECT source, COUNT(*) c FROM crm_deals WHERE user_id=? AND status='won' AND won_at BETWEEN ? AND ? GROUP BY source",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['source'], ANALYTICS_METRIC_BOOKINGS, $r['c']);
        }
    }

    // handoffs + ai-resolution + resolution time, via conversation_events joined to conversations
    if ($db->table_exists('conversation_events') && $db->table_exists('conversations')) {
        foreach ($db->query(
            "SELECT c.platform, COUNT(*) c FROM conversation_events e
             JOIN conversations c ON c.id = e.conversation_id
             WHERE e.user_id=? AND e.event_type='state_change' AND e.to_state='WAITING_FOR_HUMAN'
               AND e.created_at BETWEEN ? AND ? GROUP BY c.platform",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_HANDOFFS, $r['c']);
        }

        // resolved that day: to_state=RESOLVED events, with seconds since the conversation opened
        foreach ($db->query(
            "SELECT c.platform, COUNT(*) c, COALESCE(SUM(TIMESTAMPDIFF(SECOND, c.created_at, e.created_at)),0) secs
             FROM conversation_events e JOIN conversations c ON c.id = e.conversation_id
             WHERE e.user_id=? AND e.event_type='state_change' AND e.to_state='RESOLVED'
               AND e.created_at BETWEEN ? AND ? GROUP BY c.platform",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_RESOLVED_COUNT, $r['c']);
            $add($r['platform'], ANALYTICS_METRIC_RESOLUTION_SECONDS_SUM, $r['secs']);
        }

        // AI-resolved that day: resolved with no WAITING_FOR_HUMAN/HUMAN_ACTIVE event ever on that conversation
        foreach ($db->query(
            "SELECT c.platform, COUNT(*) c FROM conversation_events e JOIN conversations c ON c.id = e.conversation_id
             WHERE e.user_id=? AND e.event_type='state_change' AND e.to_state='RESOLVED'
               AND e.created_at BETWEEN ? AND ?
               AND NOT EXISTS (
                 SELECT 1 FROM conversation_events h WHERE h.conversation_id = e.conversation_id
                   AND h.to_state IN ('WAITING_FOR_HUMAN','HUMAN_ACTIVE'))
             GROUP BY c.platform",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_AI_RESOLVED, $r['c']);
        }

        // human first-response time: WAITING_FOR_HUMAN -> next HUMAN_ACTIVE on the same conversation
        foreach ($db->query(
            "SELECT c.platform, COUNT(*) c, COALESCE(SUM(TIMESTAMPDIFF(SECOND, e.created_at,
                (SELECT MIN(h.created_at) FROM conversation_events h WHERE h.conversation_id = e.conversation_id
                   AND h.to_state='HUMAN_ACTIVE' AND h.created_at > e.created_at))),0) secs
             FROM conversation_events e JOIN conversations c ON c.id = e.conversation_id
             WHERE e.user_id=? AND e.event_type='state_change' AND e.to_state='WAITING_FOR_HUMAN'
               AND e.created_at BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM conversation_events h WHERE h.conversation_id = e.conversation_id
                   AND h.to_state='HUMAN_ACTIVE' AND h.created_at > e.created_at)
             GROUP BY c.platform",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_FIRST_RESPONSE_COUNT, $r['c']);
            $add($r['platform'], ANALYTICS_METRIC_FIRST_RESPONSE_SECONDS_SUM, $r['secs']);
        }
    }

    // follow-up sequence steps: no channel breakdown available on sequence_step_log, all -> 'all'
    if ($db->table_exists('sequence_step_log') && $db->table_exists('sequence_enrollments')) {
        $r = $db->query(
            "SELECT COUNT(*) c, SUM(status='success') ok FROM sequence_step_log l
             JOIN sequence_enrollments en ON en.id = l.enrollment_id
             WHERE en.user_id=? AND l.run_at BETWEEN ? AND ?",
            array($uid, $start, $end))->row_array();
        if ($r) {
            $add('all', ANALYTICS_METRIC_FOLLOWUPS_SENT, $r['c'] ?? 0);
            $add('all', ANALYTICS_METRIC_FOLLOWUPS_SUCCEEDED, $r['ok'] ?? 0);
        }
    }

    // re-engagement: sent vs the customer replying (reentered)
    if ($db->table_exists('reengage_recipient')) {
        foreach ($db->query(
            "SELECT r.social_media platform, COUNT(*) c FROM reengage_recipient r
             WHERE r.user_id=? AND r.sent_at BETWEEN ? AND ?
             GROUP BY r.social_media",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_REENGAGE_SENT, $r['c']);
        }
        foreach ($db->query(
            "SELECT r.social_media platform, COUNT(*) c FROM reengage_recipient r
             WHERE r.user_id=? AND r.state='reentered' AND r.reentered_at BETWEEN ? AND ?
             GROUP BY r.social_media",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_REENGAGE_SUCCEEDED, $r['c']);
        }
    }

    // AI replies, unanswered questions, cost - no reliable channel column on ai_usage_log, 'all' only
    if ($db->table_exists('ai_usage_log')) {
        $r = $db->query("SELECT COUNT(*) c FROM ai_usage_log WHERE user_id=? AND created_at BETWEEN ? AND ?",
            array($uid, $start, $end))->row_array();
        if ($r) $add('all', ANALYTICS_METRIC_AI_REPLIES, $r['c'] ?? 0);

        if ($db->field_exists('estimated_cost_usd', 'ai_usage_log')) {
            $r = $db->query(
                "SELECT COALESCE(SUM(estimated_cost_usd),0) v FROM ai_usage_log
                 WHERE user_id=? AND created_at BETWEEN ? AND ? AND estimated_cost_usd IS NOT NULL",
                array($uid, $start, $end))->row_array();
            if ($r) $add('all', ANALYTICS_METRIC_AI_COST_USD, $r['v'] ?? 0);
        }
    }
    if ($db->table_exists('ai_unanswered_questions')) {
        foreach ($db->query(
            "SELECT social_media platform, COUNT(*) c FROM ai_unanswered_questions
             WHERE user_id=? AND created_at BETWEEN ? AND ? GROUP BY social_media",
            array($uid, $start, $end))->result_array() as $r) {
            $add($r['platform'], ANALYTICS_METRIC_UNANSWERED, $r['c']);
        }
    }

    // stalled-after-price-quote: guard-log hits from 2 days ago (48h grace period to reply),
    // still with no user message since. Bounded (LIMIT 500 rows scanned per tenant per day).
    if ($db->table_exists('ai_price_guard_log') && $db->table_exists('livechat_messages')) {
        $lookback_day = date('Y-m-d', strtotime($day . ' -2 days'));
        $stalled = analytics_aggregate_stalled_after_price_quote($db, $uid, $lookback_day);
        if ($stalled > 0) $add('all', ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE, $stalled);
    }

    return $out;
}

/**
 * Count of ai_price_guard_log rows on $day where the same subscriber never sent another
 * message afterward - a lead that asked about price and went quiet. Bounded to 500 guard-log
 * rows so a noisy tenant can't turn one aggregation tick into a full-table scan.
 */
function analytics_aggregate_stalled_after_price_quote($db, $uid, $day)
{
    $start = $day . ' 00:00:00';
    $end = $day . ' 23:59:59';
    $rows = $db->query(
        "SELECT subscribe_id, social_media, created_at FROM ai_price_guard_log
         WHERE user_id=? AND created_at BETWEEN ? AND ? LIMIT 500",
        array($uid, $start, $end))->result_array();
    $stalled = 0;
    foreach ($rows as $r) {
        $again = $db->query(
            "SELECT 1 FROM livechat_messages WHERE user_id=? AND subscriber_id=? AND platform=?
               AND sender='user' AND conversation_time > ? LIMIT 1",
            array($uid, $r['subscribe_id'], $r['social_media'], $r['created_at']))->row_array();
        if (!$again) $stalled++;
    }
    return $stalled;
}

/**
 * Upsert one tenant/day's channel=>metric=>value map into analytics_daily.
 * INSERT ... ON DUPLICATE KEY UPDATE keyed on (user_id, day, channel, metric) - idempotent,
 * re-running the same day just overwrites the same rows with the freshly computed value.
 * Skips zero-value rows for channels other than 'all' to keep the table from filling with
 * noise for channels a tenant never uses; 'all' rows are always written (even 0) so a page
 * load can tell "computed, zero" from "never aggregated" via the row's presence.
 */
function analytics_aggregate_upsert_day($db, $uid, $day, array $channel_metrics)
{
    if (!$db->table_exists('analytics_daily')) return 0;
    $now = date('Y-m-d H:i:s');
    $written = 0;
    foreach ($channel_metrics as $channel => $metrics) {
        foreach ($metrics as $metric => $value) {
            if ($channel !== 'all' && (float) $value == 0.0) continue;
            $db->query(
                "INSERT INTO analytics_daily (user_id, day, channel, metric, value, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
                array($uid, $day, $channel, $metric, round((float) $value, 4), $now));
            $written++;
        }
    }
    return $written;
}

/**
 * The cron_hub step: previous day in full + today incremental, for every tenant with recent
 * activity. Bounded: analytics_aggregate_active_tenants() caps tenants per tick, and each
 * tenant/day pair is a small, indexed query set.
 */
function analytics_aggregate_run($db, $tenant_limit = 200)
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $today = date('Y-m-d');
    $tenants = analytics_aggregate_active_tenants($db, $tenant_limit);
    $rows_written = 0;
    foreach ($tenants as $uid) {
        foreach (array($yesterday, $today) as $day) {
            $metrics = analytics_aggregate_compute_day($db, $uid, $day);
            $rows_written += analytics_aggregate_upsert_day($db, $uid, $day, $metrics);
        }
    }
    return array('tenants' => count($tenants), 'rows_written' => $rows_written, 'days' => array($yesterday, $today));
}
