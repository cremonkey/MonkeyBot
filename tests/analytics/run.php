<?php
/**
 * Business-first analytics KPIs (remaining-work Task 21 / C6, brief SPEC-13/29/41). DB-free
 * unit tests: no CodeIgniter bootstrap, no database, no network.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/analytics/run.php
 *
 * Covers:
 *  1. Each KPI formula (analytics_kpi_helper.php) on fixtures: normal, zero-denominator (null,
 *     never a fake 0%), and analytics_kpi_from_totals() end-to-end.
 *  2. Insight rules (analytics_insight_sentences): fires above threshold, stays quiet below it,
 *     each sentence carries both an EN and an Egyptian Arabic string.
 *  3. Upsert idempotency (analytics_aggregate_upsert_day): re-running the same tenant/day
 *     overwrites the same rows rather than duplicating them; no-ops before the migration
 *     (table missing).
 *  4. Tenant scoping: upserting two tenants for the same day never lets one tenant's rows
 *     collide with or overwrite the other's.
 *  5. Wiring: Cron_hub.php's run() calls _run_analytics_daily(); Analytics_hub.php guards its
 *     new reads with table_exists('analytics_daily') so a pre-migration page load is unchanged.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
date_default_timezone_set('UTC');

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }

require __DIR__ . '/../../application/helpers/analytics_kpi_helper.php';

// ============================================================ 1. KPI formulas on fixtures

same('rate: normal', 25.0, analytics_kpi_rate(25, 100));
same('rate: zero denominator -> null (never a fake 0%)', null, analytics_kpi_rate(5, 0));
same('rate: zero numerator, real denominator -> 0.0', 0.0, analytics_kpi_rate(0, 40));
same('avg: normal', 12.5, analytics_kpi_avg(125, 10));
same('avg: zero count -> null', null, analytics_kpi_avg(100, 0));

same('qualified_lead_rate', 40.0, analytics_kpi_qualified_lead_rate(10, 4));
same('booking_rate', 50.0, analytics_kpi_booking_rate(4, 2));
same('human_takeover_rate', 30.0, analytics_kpi_human_takeover_rate(10, 3));
same('ai_resolution_rate', 70.0, analytics_kpi_ai_resolution_rate(10, 7));
same('avg_resolution_minutes: 7200s/2 -> 60m', 60.0, analytics_kpi_avg_resolution_minutes(3600 * 2, 2));
same('avg_resolution_minutes: none resolved -> null', null, analytics_kpi_avg_resolution_minutes(0, 0));
same('human_first_response_seconds', 90.0, analytics_kpi_human_first_response_seconds(180, 2));
same('followup_success_rate', 60.0, analytics_kpi_followup_success_rate(10, 6));
same('reengage_success_rate', 20.0, analytics_kpi_reengage_success_rate(20, 4));
same('unanswered_rate', 12.5, analytics_kpi_unanswered_rate(80, 10));
same('cost_per_conversation', 0.5, analytics_kpi_cost_per_conversation(50, 100));
same('cost_per_conversation: no chats -> null', null, analytics_kpi_cost_per_conversation(50, 0));
same('cost_per_qualified_lead', 5.0, analytics_kpi_cost_per_qualified_lead(50, 10));
same('cost_per_qualified_lead: no leads -> null', null, analytics_kpi_cost_per_qualified_lead(50, 0));

$totals = array(
    ANALYTICS_METRIC_CHATS => 100, ANALYTICS_METRIC_QUALIFIED_LEADS => 20, ANALYTICS_METRIC_BOOKINGS => 5,
    ANALYTICS_METRIC_HANDOFFS => 15, ANALYTICS_METRIC_AI_RESOLVED => 70, ANALYTICS_METRIC_RESOLVED_COUNT => 90,
    ANALYTICS_METRIC_RESOLUTION_SECONDS_SUM => 90 * 600, ANALYTICS_METRIC_FIRST_RESPONSE_SECONDS_SUM => 15 * 120,
    ANALYTICS_METRIC_FIRST_RESPONSE_COUNT => 15, ANALYTICS_METRIC_FOLLOWUPS_SENT => 40, ANALYTICS_METRIC_FOLLOWUPS_SUCCEEDED => 12,
    ANALYTICS_METRIC_REENGAGE_SENT => 20, ANALYTICS_METRIC_REENGAGE_SUCCEEDED => 5, ANALYTICS_METRIC_AI_REPLIES => 300,
    ANALYTICS_METRIC_UNANSWERED => 30, ANALYTICS_METRIC_AI_COST_USD => 12.0, ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE => 4,
);
$kpis = analytics_kpi_from_totals($totals);
same('from_totals: qualified_lead_rate', 20.0, $kpis['qualified_lead_rate']);
same('from_totals: booking_rate', 25.0, $kpis['booking_rate']);
same('from_totals: human_takeover_rate', 15.0, $kpis['human_takeover_rate']);
same('from_totals: ai_resolution_rate', 70.0, $kpis['ai_resolution_rate']);
same('from_totals: avg_resolution_minutes', 10.0, $kpis['avg_resolution_minutes']);
same('from_totals: human_first_response_secs', 120.0, $kpis['human_first_response_secs']);
same('from_totals: followup_success_rate', 30.0, $kpis['followup_success_rate']);
same('from_totals: reengage_success_rate', 25.0, $kpis['reengage_success_rate']);
same('from_totals: unanswered_rate', 10.0, $kpis['unanswered_rate']);
same('from_totals: cost_per_conversation', 0.12, $kpis['cost_per_conversation']);
same('from_totals: cost_per_qualified_lead', 0.6, $kpis['cost_per_qualified_lead']);
same('from_totals: stalled_after_price_quote passthrough', 4, $kpis['stalled_after_price_quote']);

// missing keys treated as 0, no warnings/exceptions
$empty_kpis = analytics_kpi_from_totals(array());
foreach (array('qualified_lead_rate','booking_rate','human_takeover_rate','ai_resolution_rate',
    'avg_resolution_minutes','human_first_response_secs','followup_success_rate',
    'reengage_success_rate','unanswered_rate','cost_per_conversation','cost_per_qualified_lead') as $k) {
    same("from_totals(empty): $k is null", null, $empty_kpis[$k]);
}
same('from_totals(empty): stalled_after_price_quote is 0', 0, $empty_kpis['stalled_after_price_quote']);

// ============================================================ 2. insight rules

$quiet = array(ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE => 1, ANALYTICS_METRIC_AI_REPLIES => 100, ANALYTICS_METRIC_UNANSWERED => 2);
same('insights: quiet totals produce nothing', array(), analytics_insight_sentences($quiet));

$stalled_totals = array(ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE => 12, ANALYTICS_METRIC_AI_REPLIES => 100, ANALYTICS_METRIC_UNANSWERED => 2);
$ins = analytics_insight_sentences($stalled_totals);
check('insights: stalled-after-price-quote fires at 12', count($ins) === 1);
check('insights: EN sentence mentions the count', strpos($ins[0]['en'], '12') !== false);
check('insights: AR sentence is non-empty (bilingual)', isset($ins[0]['ar']) && $ins[0]['ar'] !== '');
same('insights: severity warn', 'warn', $ins[0]['severity']);

$high_unanswered = array(ANALYTICS_METRIC_AI_REPLIES => 200, ANALYTICS_METRIC_UNANSWERED => 40, ANALYTICS_METRIC_STALLED_AFTER_PRICE_QUOTE => 0);
$ins2 = analytics_insight_sentences($high_unanswered);
check('insights: high unanswered-rate fires', count($ins2) === 1 && strpos($ins2[0]['en'], 'unanswered') === false && strpos($ins2[0]['en'], "couldn't answer") !== false);

$prev = array(ANALYTICS_METRIC_CHATS => 100, ANALYTICS_METRIC_HANDOFFS => 5); // 5%
$cur  = array(ANALYTICS_METRIC_CHATS => 100, ANALYTICS_METRIC_HANDOFFS => 20); // 20%, +15pt
$ins3 = analytics_insight_sentences($cur, $prev);
$has_takeover = false;
foreach ($ins3 as $i) if (strpos($i['en'], 'takeover') !== false) $has_takeover = true;
check('insights: rising human-takeover rate fires vs previous period', $has_takeover);

$prev_flat = array(ANALYTICS_METRIC_CHATS => 100, ANALYTICS_METRIC_HANDOFFS => 18);
$cur_flat  = array(ANALYTICS_METRIC_CHATS => 100, ANALYTICS_METRIC_HANDOFFS => 20); // +2pt, below threshold
$ins4 = analytics_insight_sentences($cur_flat, $prev_flat);
$has_takeover2 = false;
foreach ($ins4 as $i) if (strpos($i['en'], 'takeover') !== false) $has_takeover2 = true;
check('insights: small takeover delta stays quiet (no noise below threshold)', !$has_takeover2);

// ============================================================ 3+4. upsert idempotency + tenant scoping

class FakeAnalyticsDb
{
    public $tables = array();
    private $exists = array('analytics_daily' => true);
    public function table_exists($t) { return !empty($this->exists[$t]); }
    public function field_exists($c, $t) { return true; }
    public function query($sql, $binds = array())
    {
        if (strpos($sql, 'INSERT INTO analytics_daily') === 0) {
            list($uid, $day, $channel, $metric, $value, $now) = $binds;
            $key = "$uid|$day|$channel|$metric";
            $this->tables['analytics_daily'][$key] = array(
                'user_id' => $uid, 'day' => $day, 'channel' => $channel, 'metric' => $metric,
                'value' => $value, 'updated_at' => $now,
            );
        }
        return new class { public function result_array() { return array(); } public function row_array() { return null; } };
    }
}

require __DIR__ . '/../../application/helpers/analytics_aggregate_helper.php';

$db = new FakeAnalyticsDb();
$written1 = analytics_aggregate_upsert_day($db, 7, '2026-10-10', array(
    'all' => array('chats' => 10, 'qualified_leads' => 0),
    'fb'  => array('chats' => 10, 'qualified_leads' => 0),
));
same('upsert: writes all-channel rows even when 0', 10.0, $db->tables['analytics_daily']['7|2026-10-10|all|chats']['value']);
check('upsert: zero-value non-all channel rows are skipped (no noise)', !isset($db->tables['analytics_daily']['7|2026-10-10|fb|qualified_leads']));
$count_after_first = count($db->tables['analytics_daily']);

// re-run with a changed value for the same tenant/day/channel/metric key
analytics_aggregate_upsert_day($db, 7, '2026-10-10', array('all' => array('chats' => 25)));
same('upsert: idempotent key overwrites, does not duplicate', $count_after_first, count($db->tables['analytics_daily']));
same('upsert: overwritten value reflects the latest run', 25.0, $db->tables['analytics_daily']['7|2026-10-10|all|chats']['value']);

// tenant scoping: a second tenant's upsert never touches tenant 7's row for the same day/metric
analytics_aggregate_upsert_day($db, 9, '2026-10-10', array('all' => array('chats' => 999)));
same('tenant scoping: tenant 7 unaffected by tenant 9 writing the same day/metric', 25.0, $db->tables['analytics_daily']['7|2026-10-10|all|chats']['value']);
same('tenant scoping: tenant 9 got its own row', 999.0, $db->tables['analytics_daily']['9|2026-10-10|all|chats']['value']);

// before the migration: table missing -> no-op, no error
$db2 = new FakeAnalyticsDb();
$db2Reflect = new ReflectionClass($db2);
$prop = $db2Reflect->getProperty('exists'); $prop->setAccessible(true); $prop->setValue($db2, array());
same('upsert: no-ops before analytics_daily exists (pre-migration)', 0, analytics_aggregate_upsert_day($db2, 1, '2026-10-10', array('all' => array('chats' => 5))));

// ============================================================ 5. wiring

$cron_src = file_get_contents(__DIR__ . '/../../application/controllers/Cron_hub.php');
check('wiring: run() calls _run_analytics_daily()', strpos($cron_src, "_run_analytics_daily()") !== false
    && preg_match('/out\[.analytics_daily.\]\s*=\s*\$this->_run_analytics_daily\(\)/', $cron_src) === 1);
check('wiring: analytics_daily step guards with table_exists before touching analytics_daily', strpos($cron_src, "table_exists('analytics_daily')") !== false);

$hub_src = file_get_contents(__DIR__ . '/../../application/controllers/Analytics_hub.php');
check('wiring: Analytics_hub business_kpis() guards with table_exists (collecting-data fallback)', strpos($hub_src, "table_exists('analytics_daily')") !== false && strpos($hub_src, "'collecting' => true") !== false);
check('wiring: Analytics_hub totals query scopes by user_id (tenant isolation)', strpos($hub_src, 'WHERE user_id=? AND channel=') !== false || strpos($hub_src, "user_id=?") !== false);

echo "\n" . ($FAIL === 0 ? "PASS" : "FAIL") . " ($PASS ok, $FAIL failed)\n";
exit($FAIL === 0 ? 0 : 1);
