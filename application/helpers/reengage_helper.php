<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Re-engagement broadcast primitives (SPEC-19).
 *
 * Everything here is pure: no database, no network, no CI instance. That is
 * deliberate — the eligibility rules decide whether we are allowed to message a
 * customer at all, and getting them wrong risks the page's messaging permission
 * (and with it the AI bot, IG DM replies and CRM lead capture). Pure functions
 * can be exercised exhaustively without sending anything.
 *
 * Meta's rules, as of 2026-07:
 *   <= 24h since the customer's last INBOUND message : anything, incl. promos
 *   <= 7d                                            : HUMAN_AGENT tag, but only
 *                                                      for human-composed replies
 *   >  7d                                            : nothing (Marketing Messages
 *                                                      opt-in is the legit path)
 *
 * The automated engine therefore sends only to REENGAGE_IN_WINDOW.
 * HUMAN_AGENT is reported to the operator to answer by hand from Livechat; it is
 * never sent automatically, because automated sends under that tag are an
 * explicit policy violation.
 */

define('REENGAGE_IN_WINDOW', 'in_window');
define('REENGAGE_HUMAN_AGENT', 'human_agent');
define('REENGAGE_OUT_OF_WINDOW', 'out_of_window');

define('REENGAGE_WINDOW_SECONDS', 86400);       // 24 hours
define('REENGAGE_HUMAN_AGENT_SECONDS', 604800); // 7 days

if (!function_exists('reengage_classify')) {
    /**
     * Bucket a contact by how long ago they last messaged us.
     *
     * $last_inbound_time is the timestamp of the customer's last message TO the
     * page — never the thread's updated_time, which also moves when the page
     * replies and would overstate eligibility.
     *
     * Any input we cannot trust resolves to out_of_window. The safe default is
     * "not eligible": a false in_window costs a policy violation, a false
     * out_of_window costs one delayed message. The live subscriber table already
     * holds a '0000-00-00 00:00:00' row, so this path is real, not theoretical.
     *
     * @param  string|int $last_inbound_time  'Y-m-d H:i:s' or unix timestamp
     * @param  string|int $now                same, defaults to current time
     * @return string  one of the REENGAGE_* bucket constants
     */
    function reengage_classify($last_inbound_time, $now = null)
    {
        $last = reengage_to_timestamp($last_inbound_time);
        if ($last === null) return REENGAGE_OUT_OF_WINDOW;

        $now = ($now === null) ? time() : reengage_to_timestamp($now);
        if ($now === null) return REENGAGE_OUT_OF_WINDOW;

        $delta = $now - $last;

        // A future timestamp means clock skew or corrupt data, not a fresh
        // conversation. Refuse to treat it as a licence to send.
        if ($delta < 0) return REENGAGE_OUT_OF_WINDOW;

        if ($delta <= REENGAGE_WINDOW_SECONDS) return REENGAGE_IN_WINDOW;
        if ($delta <= REENGAGE_HUMAN_AGENT_SECONDS) return REENGAGE_HUMAN_AGENT;
        return REENGAGE_OUT_OF_WINDOW;
    }
}

if (!function_exists('reengage_to_timestamp')) {
    /**
     * Parse a MySQL datetime or unix timestamp into a unix timestamp.
     * Returns null for anything unusable, including MySQL's zero date.
     *
     * @return int|null
     */
    function reengage_to_timestamp($value)
    {
        if ($value === null || $value === '' || $value === false) return null;

        if (is_int($value)) return ($value > 0) ? $value : null;

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') return null;

            // MySQL zero dates. strtotime() returns false on some builds and a
            // negative timestamp on others, so reject them by shape first.
            if (strpos($value, '0000-00-00') === 0) return null;

            if (ctype_digit($value)) {
                $int = (int) $value;
                return ($int > 0) ? $int : null;
            }

            $ts = strtotime($value);
            if ($ts === false || $ts <= 0) return null;
            return $ts;
        }

        return null;
    }
}

if (!function_exists('reengage_is_sendable')) {
    /**
     * Only in_window contacts may be messaged by the automated engine.
     * Kept as its own function so call sites read as intent, not as a
     * string comparison someone might later "simplify" to include human_agent.
     */
    function reengage_is_sendable($eligibility)
    {
        return $eligibility === REENGAGE_IN_WINDOW;
    }
}

if (!function_exists('reengage_in_quiet_hours')) {
    /**
     * Quiet hours are given in the page's local time and may wrap midnight
     * (e.g. 22:00 -> 08:00). Equal start and end means "no quiet hours".
     *
     * @param  string $now_hm     'HH:MM' in page-local time
     * @param  string $quiet_start 'HH:MM'
     * @param  string $quiet_end   'HH:MM'
     */
    function reengage_in_quiet_hours($now_hm, $quiet_start, $quiet_end)
    {
        $now = reengage_hm_to_minutes($now_hm);
        $start = reengage_hm_to_minutes($quiet_start);
        $end = reengage_hm_to_minutes($quiet_end);

        if ($now === null || $start === null || $end === null) return false;
        if ($start === $end) return false;

        if ($start < $end) return ($now >= $start && $now < $end);

        // Wraps midnight: quiet if after start OR before end.
        return ($now >= $start || $now < $end);
    }
}

if (!function_exists('reengage_hm_to_minutes')) {
    /** @return int|null minutes since midnight */
    function reengage_hm_to_minutes($hm)
    {
        if (!is_string($hm) || !preg_match('/^(\d{1,2}):(\d{2})/', trim($hm), $m)) return null;
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) return null;
        return ($h * 60) + $min;
    }
}

if (!function_exists('reengage_rate_budget')) {
    /**
     * How many messages this cron tick may send.
     *
     * Derived from what was actually sent, not from a "last tick" timestamp, so
     * a cron that missed three hours cannot produce a burst of 180 messages —
     * the ceiling stays messages_per_hour either way.
     *
     * @param int $per_hour        campaign's messages_per_hour
     * @param int $sent_last_hour  rows with sent_at > now - 1h
     * @param int $daily_cap       campaign's daily_cap (0 = unlimited)
     * @param int $sent_today      rows with sent_at >= start of today, page tz
     * @return int  never negative
     */
    function reengage_rate_budget($per_hour, $sent_last_hour, $daily_cap, $sent_today)
    {
        $per_hour = (int) $per_hour;
        $daily_cap = (int) $daily_cap;

        $allowed = $per_hour - (int) $sent_last_hour;

        if ($daily_cap > 0) {
            $remaining_today = $daily_cap - (int) $sent_today;
            if ($remaining_today < $allowed) $allowed = $remaining_today;
        }

        return ($allowed > 0) ? $allowed : 0;
    }
}

if (!function_exists('reengage_jitter_seconds')) {
    /**
     * Randomised gap between two sends. Bounds are clamped and ordered so a
     * misconfigured campaign cannot sleep forever inside a cron tick.
     */
    function reengage_jitter_seconds($min, $max)
    {
        $min = (int) $min;
        $max = (int) $max;
        if ($min < 0) $min = 0;
        if ($max < $min) $max = $min;
        if ($max > 60) $max = 60; // a tick is capped at 60s of wall clock anyway
        if ($min > $max) $min = $max;
        return ($min === $max) ? $min : mt_rand($min, $max);
    }
}
