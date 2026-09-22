<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Agency Control Center — usage/billing readiness (brief §11, §31). Pure / DB-free math
 * factored out of Agency.php so it is unit-testable without a CodeIgniter bootstrap
 * (tests/summary_billing/run.php). This is READINESS/visibility only — not a billing or
 * invoicing system (that already exists via the stock Payment.php gateways).
 *
 * `package.monthly_limit` is a JSON object keyed by `modules.id` (string) -> limit (string
 * numeric), where 0 (or the key missing) means unlimited. This is the SAME shape/semantics
 * already used by `Home::_check_usage()` and every admin package screen
 * (application/views/admin/payment/*.php, application/views/member/usage_log.php) — nothing
 * new is invented here.
 *
 * `modules.extra_text` decides whether a module's usage is month-scoped (non-empty, e.g.
 * 'month' — `usage_log` filtered to the current usage_month/usage_year) or cumulative
 * (empty string — every usage_log row ever, no month filter), matching
 * `Home::_check_usage()`'s own branch exactly.
 */

if (!function_exists('agency_billing_days_until_expiry')) {
    /**
     * Whole days until `$expired_date` (negative when already expired). NULL when there is no
     * expiry date to compare (e.g. Admin/agency accounts).
     */
    function agency_billing_days_until_expiry($expired_date, $now = null)
    {
        if (empty($expired_date) || $expired_date === '0000-00-00' || $expired_date === '0000-00-00 00:00:00') return null;
        $now_ts = $now !== null ? (is_int($now) ? $now : strtotime((string) $now)) : time();
        $exp_ts = strtotime((string) $expired_date);
        if ($exp_ts === false || $now_ts === false) return null;
        return (int) floor(($exp_ts - $now_ts) / 86400);
    }
}

if (!function_exists('agency_billing_expiry_status')) {
    /**
     * @param ?int $days from agency_billing_days_until_expiry(), or NULL (no expiry to judge)
     * @param int  $due_soon_days threshold — brief: "flag red if <7 days or already expired"
     * @return array{status:string, flag:?string, label:string} status: none|expired|due_soon|ok
     */
    function agency_billing_expiry_status($days, $due_soon_days = 7)
    {
        if ($days === null) return array('status' => 'none', 'flag' => null, 'label' => '—');
        if ($days < 0) return array('status' => 'expired', 'flag' => 'danger', 'label' => 'Expired ' . abs($days) . 'd ago');
        if ($days < $due_soon_days) return array('status' => 'due_soon', 'flag' => 'danger', 'label' => 'Renews in ' . $days . 'd');
        return array('status' => 'ok', 'flag' => null, 'label' => 'Renews in ' . $days . 'd');
    }
}

if (!function_exists('agency_billing_is_renewal_due_soon')) {
    /** Convenience boolean for the "renewals due soon" totals-row count (brief §11). */
    function agency_billing_is_renewal_due_soon($days, $due_soon_days = 7)
    {
        return $days !== null && $days < $due_soon_days;
    }
}

if (!function_exists('agency_billing_is_module_monthly')) {
    /** Mirrors Home::_check_usage()'s own branch: extra_text === '' -> cumulative, not monthly. */
    function agency_billing_is_module_monthly($extra_text)
    {
        return $extra_text !== null && (string) $extra_text !== '';
    }
}

if (!function_exists('agency_billing_usage_percent')) {
    /**
     * @param mixed $limit_raw  package.monthly_limit[$module_id] as decoded from JSON (string|int|null)
     * @param int   $usage      actual usage_log count for the same module/scope
     * @return array{limit:int, usage:int, unlimited:bool, percent:?int} percent NULL when unlimited
     *         or the module isn't in this package at all (limit key absent -> treated as unlimited,
     *         matching Home::_check_usage(): a missing/zero limit never blocks).
     */
    function agency_billing_usage_percent($limit_raw, $usage)
    {
        $limit = $limit_raw === null ? 0 : (int) $limit_raw;
        $usage = max(0, (int) $usage);
        if ($limit <= 0) return array('limit' => 0, 'usage' => $usage, 'unlimited' => true, 'percent' => null);
        $percent = (int) round(min(100, $usage / $limit * 100));
        return array('limit' => $limit, 'usage' => $usage, 'unlimited' => false, 'percent' => $percent);
    }
}

if (!function_exists('agency_billing_decode_monthly_limit')) {
    /** Defensive JSON decode — a malformed/empty monthly_limit must read as "no limits set", never crash. */
    function agency_billing_decode_monthly_limit($monthly_limit_json)
    {
        if (empty($monthly_limit_json)) return array();
        $dec = json_decode((string) $monthly_limit_json, true);
        return is_array($dec) ? $dec : array();
    }
}
