<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Business-first analytics KPIs (remaining-work Task 21 / C6, brief SPEC-13/29/41).
 *
 * Every function below is a PURE function: plain numbers in, a number (or null when the
 * denominator is zero / undefined) out. No DB, no CI. That is deliberate - it is what makes
 * them unit-testable on fixtures (tests/analytics/run.php) and safe to reuse from both
 * Cron_hub.php (writing analytics_daily) and Analytics_hub.php (reading it back).
 *
 * The metric names below are the `analytics_daily.metric` values the aggregator writes.
 * Analytics_hub.php sums the metric across the requested day range and channel, then calls the
 * matching *_from_totals() helper here.
 */

// analytics_daily.metric names the aggregator (analytics_aggregate_helper.php) writes per
// (user_id, day, channel). Kept as constants so the aggregator and the reader never drift.
const ANALYTICS_METRIC_CHATS = 'chats';
const ANALYTICS_METRIC_QUALIFIED_LEADS = 'qualified_leads';
const ANALYTICS_METRIC_BOOKINGS = 'bookings';
const ANALYTICS_METRIC_HANDOFFS = 'handoffs';
const ANALYTICS_METRIC_AI_RESOLVED = 'ai_resolved';
const ANALYTICS_METRIC_RESOLVED_COUNT = 'resolved_count';
const ANALYTICS_METRIC_RESOLUTION_SECONDS_SUM = 'resolution_seconds_sum';
const ANALYTICS_METRIC_FIRST_RESPONSE_SECONDS_SUM = 'first_response_seconds_sum';
const ANALYTICS_METRIC_FIRST_RESPONSE_COUNT = 'first_response_count';
const ANALYTICS_METRIC_FOLLOWUPS_SENT = 'followups_sent';
const ANALYTICS_METRIC_FOLLOWUPS_SUCCEEDED = 'followups_succeeded';
const ANALYTICS_METRIC_REENGAGE_SENT = 'reengage_sent';
const ANALYTICS_METRIC_REENGAGE_SUCCEEDED = 'reengage_succeeded';
const ANALYTICS_METRIC_AI_REPLIES = 'ai_replies';
const ANALYTICS_METRIC_UNANSWERED = 'unanswered';
const ANALYTICS_METRIC_AI_COST_USD = 'ai_cost_usd';
const ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE = 'stalled_after_price_quote';

/** All metric names, for the aggregator to zero-fill and the reader to sum(). */
function analytics_kpi_metric_names()
{
    return array(
        ANALYTICS_METRIC_CHATS, ANALYTICS_METRIC_QUALIFIED_LEADS, ANALYTICS_METRIC_BOOKINGS,
        ANALYTICS_METRIC_HANDOFFS, ANALYTICS_METRIC_AI_RESOLVED, ANALYTICS_METRIC_RESOLVED_COUNT,
        ANALYTICS_METRIC_RESOLUTION_SECONDS_SUM, ANALYTICS_METRIC_FIRST_RESPONSE_SECONDS_SUM,
        ANALYTICS_METRIC_FIRST_RESPONSE_COUNT, ANALYTICS_METRIC_FOLLOWUPS_SENT,
        ANALYTICS_METRIC_FOLLOWUPS_SUCCEEDED, ANALYTICS_METRIC_REENGAGE_SENT,
        ANALYTICS_METRIC_REENGAGE_SUCCEEDED, ANALYTICS_METRIC_AI_REPLIES,
        ANALYTICS_METRIC_UNANSWERED, ANALYTICS_METRIC_AI_COST_USD,
        ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE,
    );
}

/** Guarded percentage: null when there is nothing to divide by (never a fake 0%). */
function analytics_kpi_rate($numerator, $denominator, $decimals = 1)
{
    $numerator = (float) $numerator;
    $denominator = (float) $denominator;
    if ($denominator <= 0) return null;
    return round($numerator / $denominator * 100, $decimals);
}

/** Guarded average (e.g. seconds-sum / count). Null when count is zero. */
function analytics_kpi_avg($sum, $count, $decimals = 1)
{
    $count = (float) $count;
    if ($count <= 0) return null;
    return round((float) $sum / $count, $decimals);
}

/** Chats that turned into a qualified lead (crm_deals created). */
function analytics_kpi_qualified_lead_rate($chats, $qualified_leads)
{
    return analytics_kpi_rate($qualified_leads, $chats);
}

/** Qualified leads that turned into a booking/won deal. */
function analytics_kpi_booking_rate($qualified_leads, $bookings)
{
    return analytics_kpi_rate($bookings, $qualified_leads);
}

/** Share of conversations that needed a human (handoff_state left AI_ACTIVE for a human one). */
function analytics_kpi_human_takeover_rate($chats, $handoffs)
{
    return analytics_kpi_rate($handoffs, $chats);
}

/** Share of conversations the AI resolved on its own, no human touch. */
function analytics_kpi_ai_resolution_rate($chats, $ai_resolved)
{
    return analytics_kpi_rate($ai_resolved, $chats);
}

/** Average time (minutes) a conversation stayed open before reaching RESOLVED. */
function analytics_kpi_avg_resolution_minutes($resolution_seconds_sum, $resolved_count)
{
    $avg_seconds = analytics_kpi_avg($resolution_seconds_sum, $resolved_count, 4);
    return $avg_seconds === null ? null : round($avg_seconds / 60, 1);
}

/** Average time (seconds) from handoff to the first human reply. */
function analytics_kpi_human_first_response_seconds($first_response_seconds_sum, $first_response_count)
{
    return analytics_kpi_avg($first_response_seconds_sum, $first_response_count, 0);
}

/** Share of sequence follow-up steps that produced a reply/engagement, not just a send. */
function analytics_kpi_followup_success_rate($followups_sent, $followups_succeeded)
{
    return analytics_kpi_rate($followups_succeeded, $followups_sent);
}

/** Share of re-engagement sends that got the customer talking again. */
function analytics_kpi_reengage_success_rate($reengage_sent, $reengage_succeeded)
{
    return analytics_kpi_rate($reengage_succeeded, $reengage_sent);
}

/** Share of AI replies that were a "gap" - the bot didn't have a real answer. */
function analytics_kpi_unanswered_rate($ai_replies, $unanswered)
{
    return analytics_kpi_rate($unanswered, $ai_replies);
}

/** Estimated AI $ per conversation. */
function analytics_kpi_cost_per_conversation($ai_cost_usd, $chats)
{
    $chats = (float) $chats;
    if ($chats <= 0) return null;
    return round((float) $ai_cost_usd / $chats, 4);
}

/** Estimated AI $ per qualified lead - the number that answers "is the bot worth it". */
function analytics_kpi_cost_per_qualified_lead($ai_cost_usd, $qualified_leads)
{
    $qualified_leads = (float) $qualified_leads;
    if ($qualified_leads <= 0) return null;
    return round((float) $ai_cost_usd / $qualified_leads, 4);
}

/**
 * Compute every KPI from a totals map (metric name => summed value over the requested range).
 * Missing keys are treated as 0. Returns an assoc array of kpi_name => value|null.
 */
function analytics_kpi_from_totals(array $totals)
{
    $g = function ($k) use ($totals) { return isset($totals[$k]) ? (float) $totals[$k] : 0.0; };
    return array(
        'qualified_lead_rate'       => analytics_kpi_qualified_lead_rate($g(ANALYTICS_METRIC_CHATS), $g(ANALYTICS_METRIC_QUALIFIED_LEADS)),
        'booking_rate'              => analytics_kpi_booking_rate($g(ANALYTICS_METRIC_QUALIFIED_LEADS), $g(ANALYTICS_METRIC_BOOKINGS)),
        'human_takeover_rate'       => analytics_kpi_human_takeover_rate($g(ANALYTICS_METRIC_CHATS), $g(ANALYTICS_METRIC_HANDOFFS)),
        'ai_resolution_rate'        => analytics_kpi_ai_resolution_rate($g(ANALYTICS_METRIC_CHATS), $g(ANALYTICS_METRIC_AI_RESOLVED)),
        'avg_resolution_minutes'    => analytics_kpi_avg_resolution_minutes($g(ANALYTICS_METRIC_RESOLUTION_SECONDS_SUM), $g(ANALYTICS_METRIC_RESOLVED_COUNT)),
        'human_first_response_secs' => analytics_kpi_human_first_response_seconds($g(ANALYTICS_METRIC_FIRST_RESPONSE_SECONDS_SUM), $g(ANALYTICS_METRIC_FIRST_RESPONSE_COUNT)),
        'followup_success_rate'     => analytics_kpi_followup_success_rate($g(ANALYTICS_METRIC_FOLLOWUPS_SENT), $g(ANALYTICS_METRIC_FOLLOWUPS_SUCCEEDED)),
        'reengage_success_rate'     => analytics_kpi_reengage_success_rate($g(ANALYTICS_METRIC_REENGAGE_SENT), $g(ANALYTICS_METRIC_REENGAGE_SUCCEEDED)),
        'unanswered_rate'           => analytics_kpi_unanswered_rate($g(ANALYTICS_METRIC_AI_REPLIES), $g(ANALYTICS_METRIC_UNANSWERED)),
        'cost_per_conversation'     => analytics_kpi_cost_per_conversation($g(ANALYTICS_METRIC_AI_COST_USD), $g(ANALYTICS_METRIC_CHATS)),
        'cost_per_qualified_lead'   => analytics_kpi_cost_per_qualified_lead($g(ANALYTICS_METRIC_AI_COST_USD), $g(ANALYTICS_METRIC_QUALIFIED_LEADS)),
        'stalled_after_price_quote' => (int) $g(ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE),
    );
}

// ------------------------------------------------------------------------------------------
// Insight sentences - rule-based (no AI), bilingual EN + Egyptian Arabic. Each rule looks at
// this period's totals next to the previous period's (same length, immediately before) and
// only fires above a noise floor, so a quiet tenant with 1-2 events doesn't get spammed.
// ------------------------------------------------------------------------------------------

/**
 * @param array $current  totals map for the period being reported (see analytics_kpi_from_totals)
 * @param array $previous  totals map for the equal-length prior period, for trend comparisons
 * @return array list of ['en' => ..., 'ar' => ..., 'severity' => 'info'|'warn']
 */
function analytics_insight_sentences(array $current, array $previous = array())
{
    $out = array();
    $g = function ($totals, $k) { return isset($totals[$k]) ? (float) $totals[$k] : 0.0; };

    // 1. Leads stalling right after a price quote - the sharpest, most actionable signal.
    $stalled = (int) $g($current, ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE);
    if ($stalled >= 3) {
        $out[] = array(
            'en' => "{$stalled} leads stopped replying after a price quote this week.",
            'ar' => "{$stalled} عميل بطل يرد بعد ما استلم عرض السعر الأسبوع ده.",
            'severity' => 'warn',
        );
    }

    // 2. Unanswered-question rate rising - the KB has a gap.
    $kpis_now = analytics_kpi_from_totals($current);
    $kpis_prev = empty($previous) ? null : analytics_kpi_from_totals($previous);
    if ($kpis_now['unanswered_rate'] !== null && $kpis_now['unanswered_rate'] >= 15) {
        $out[] = array(
            'en' => "The bot couldn't answer {$kpis_now['unanswered_rate']}% of questions this period - worth reviewing the knowledge base.",
            'ar' => "البوت معرفش يجاوب على {$kpis_now['unanswered_rate']}% من الأسئلة الفترة دي - يستاهل تراجع قاعدة المعرفة.",
            'severity' => 'warn',
        );
    }

    // 3. Human takeover rate trending up.
    if ($kpis_now['human_takeover_rate'] !== null && $kpis_prev !== null && $kpis_prev['human_takeover_rate'] !== null) {
        $delta = $kpis_now['human_takeover_rate'] - $kpis_prev['human_takeover_rate'];
        if ($delta >= 10) {
            $out[] = array(
                'en' => "Human takeover rate is up {$delta} points vs the previous period ({$kpis_now['human_takeover_rate']}% now).",
                'ar' => "نسبة تدخل الموظف زادت {$delta} نقطة عن الفترة اللي فاتت (دلوقتي {$kpis_now['human_takeover_rate']}%).",
                'severity' => 'warn',
            );
        }
    }

    // 4. Cost per qualified lead trending up - the bot is getting more expensive per result.
    if ($kpis_now['cost_per_qualified_lead'] !== null && $kpis_prev !== null && $kpis_prev['cost_per_qualified_lead'] !== null
        && $kpis_prev['cost_per_qualified_lead'] > 0) {
        $pct = round(($kpis_now['cost_per_qualified_lead'] - $kpis_prev['cost_per_qualified_lead']) / $kpis_prev['cost_per_qualified_lead'] * 100, 0);
        if ($pct >= 25) {
            $out[] = array(
                'en' => "Cost per qualified lead rose {$pct}% vs the previous period (now \${$kpis_now['cost_per_qualified_lead']}).",
                'ar' => "تكلفة العميل المؤهل زادت {$pct}% عن الفترة اللي فاتت (دلوقتي \${$kpis_now['cost_per_qualified_lead']}).",
                'severity' => 'warn',
            );
        }
    }

    // 5. Follow-up success improving - a positive signal worth surfacing too.
    if ($kpis_now['followup_success_rate'] !== null && $kpis_prev !== null && $kpis_prev['followup_success_rate'] !== null) {
        $delta = $kpis_now['followup_success_rate'] - $kpis_prev['followup_success_rate'];
        if ($delta >= 10) {
            $out[] = array(
                'en' => "Follow-up success rate improved {$delta} points vs the previous period ({$kpis_now['followup_success_rate']}% now).",
                'ar' => "نسبة نجاح المتابعة تحسنت {$delta} نقطة عن الفترة اللي فاتت (دلوقتي {$kpis_now['followup_success_rate']}%).",
                'severity' => 'info',
            );
        }
    }

    // 6. Re-engagement working well.
    $reengage_sent = (int) $g($current, ANALYTICS_METRIC_REENGAGE_SENT);
    if ($reengage_sent >= 5 && $kpis_now['reengage_success_rate'] !== null && $kpis_now['reengage_success_rate'] >= 20) {
        $out[] = array(
            'en' => "Re-engagement is working: {$kpis_now['reengage_success_rate']}% of silent leads replied after a nudge this period.",
            'ar' => "المتابعة بتنجح: {$kpis_now['reengage_success_rate']}% من العملاء الساكتين ردوا بعد رسالة تذكير الفترة دي.",
            'severity' => 'info',
        );
    }

    return $out;
}
