<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Shared staff-shift / bot-speak checks for Facebook / Instagram / WhatsApp.
 * Timezone is always Africa/Cairo regardless of PHP default (container is UTC).
 *
 * hours_enabled window = STAFF SHIFT hours, not bot hours.
 * working_hours_is_open() means "bot is allowed to speak".
 */

if (!function_exists('working_hours_normalize_time')) {
    function working_hours_normalize_time($value, $fallback = '09:00:00')
    {
        $value = trim((string)$value);
        if ($value === '') return $fallback;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            $h = (int)$m[1];
            $i = (int)$m[2];
            $s = isset($m[3]) ? (int)$m[3] : 0;
            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59 && $s >= 0 && $s <= 59) {
                return sprintf('%02d:%02d:%02d', $h, $i, $s);
            }
        }
        return $fallback;
    }
}

if (!function_exists('working_hours_is_open')) {
    function working_hours_is_open($row)
    {
        // true = bot may speak. Window is staff shift: silent while staff are on.
        if (!is_array($row)) return true;
        if (($row['hours_enabled'] ?? '0') !== '1') return true;

        $tz = new DateTimeZone('Africa/Cairo');
        $now = new DateTime('now', $tz);
        $day_n = $now->format('N'); // 1=Mon .. 7=Sun
        $days = array_filter(array_map('trim', explode(',', (string)($row['hours_days'] ?? ''))));
        if (!in_array($day_n, $days, false)) return true; // staff off that day, bot speaks

        $now_t = $now->format('H:i:s');
        $start = working_hours_normalize_time($row['hours_start'] ?? '09:00:00', '09:00:00');
        $end = working_hours_normalize_time($row['hours_end'] ?? '22:00:00', '22:00:00');

        if ($start < $end) {
            $staff_on = ($now_t >= $start && $now_t < $end);
        } else {
            // Overnight window (start >= end): staff on if now >= start OR now < end
            $staff_on = ($now_t >= $start || $now_t < $end);
        }
        return !$staff_on; // bot speaks when staff are OFF
    }
}

if (!function_exists('working_hours_message')) {
    function working_hours_message($row)
    {
        $default = 'نعتذر، خدمة الرد الآلي متاحة من {start} إلى {end}. سنرد عليك خلال ساعات العمل.';
        if (!is_array($row)) $row = array();
        $msg = trim((string)($row['hours_message'] ?? ''));
        if ($msg === '') $msg = $default;
        $start_hi = substr(working_hours_normalize_time($row['hours_start'] ?? '09:00:00', '09:00:00'), 0, 5);
        $end_hi = substr(working_hours_normalize_time($row['hours_end'] ?? '22:00:00', '22:00:00'), 0, 5);
        return str_replace(array('{start}', '{end}'), array($start_hi, $end_hi), $msg);
    }
}

if (!function_exists('working_hours_notice_due')) {
    function working_hours_notice_due($subscriber_row)
    {
        if (!is_array($subscriber_row)) $subscriber_row = array();
        $last = $subscriber_row['hours_offline_sent_at'] ?? null;
        if ($last === null || $last === '' || $last === '0000-00-00 00:00:00') return true;
        $sent_ts = strtotime($last);
        if (!$sent_ts) return true;
        return (time() - $sent_ts) >= 12 * 3600;
    }
}
