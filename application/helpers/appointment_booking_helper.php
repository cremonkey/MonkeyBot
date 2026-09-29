<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared insert logic for ab_appointments, extracted from
 * Appointment_booking::submit_booking() (Task 27 / C12) so the AI booking tool
 * (Ai_tools::t_create_booking) reuses the exact same clash-check + insert instead of
 * duplicating it.
 *
 * Deliberately thin: it does the SAME checks submit_booking always did (empty-string
 * field check via strip_tags only, strtotime, interval-overlap clash) and nothing
 * stricter — code review on a6cfffc9 found the first version silently changed the web
 * widget's behaviour (extra preg_match date/time validation, trimmed names, a reworded
 * clash message). Any tighter validation belongs in the CALLER before it gets here:
 * t_create_booking does its own stricter checks (explicit input required — never book on
 * a vague request) and Appointment_booking::submit_booking() does none, exactly as
 * before. Returns a {code, ...} shape with NO message string, so each caller renders its
 * own wording and neither can drift from what it used to say.
 */

if (!function_exists('ab_create_booking')) {
    /**
     * @return array{code:string, appointment_id:int, starts_at:string, ends_at:string, booking_key:string, service:array|null}
     *   code: 'missing_fields' | 'invalid_service' | 'clash' | 'ok'
     */
    function ab_create_booking($user_id, $service_id, $date, $time, $name, $phone = '', $email = '', $source = 'web')
    {
        $CI =& get_instance();
        $user_id = (int) $user_id;
        $service_id = (int) $service_id;
        // Same field handling as the pre-C12 submit_booking(): strip_tags only, no trim,
        // no format regex — a whitespace-only name is NOT rejected here (it never was).
        $name = strip_tags((string) $name);
        $phone = strip_tags((string) $phone);
        $email = strip_tags((string) $email);

        if (!$user_id || !$service_id || !$date || !$time || $name === '') {
            return array('code' => 'missing_fields', 'appointment_id' => 0, 'starts_at' => '', 'ends_at' => '', 'booking_key' => '', 'service' => null);
        }

        // Tenant-scoped: a service_id from a different account is treated as unknown,
        // same as the original single where('user_id', $uid).
        $svc = $CI->db->from('ab_services')->where('id', $service_id)->where('user_id', $user_id)->get()->row_array();
        if (empty($svc)) {
            return array('code' => 'invalid_service', 'appointment_id' => 0, 'starts_at' => '', 'ends_at' => '', 'booking_key' => '', 'service' => null);
        }

        $starts = date('Y-m-d H:i:s', strtotime($date . ' ' . $time));
        $ends = date('Y-m-d H:i:s', strtotime($starts . ' +' . $svc['duration_min'] . ' minutes'));

        // Same interval-overlap clash check as the web widget: the slot must be free.
        $clash = $CI->db->from('ab_appointments')->where('user_id', $user_id)
            ->where('starts_at <', $ends)->where('ends_at >', $starts)
            ->where_in('status', array('pending', 'confirmed'))->count_all_results();
        if ($clash) {
            return array('code' => 'clash', 'appointment_id' => 0, 'starts_at' => $starts, 'ends_at' => $ends, 'booking_key' => '', 'service' => $svc);
        }

        $key = substr(md5(uniqid('bk', true)), 0, 16);
        // ab_appointments.source is ENUM('chat','web','manual') — see
        // assets/backup_db/migrations/2026-07-05-spec17.sql. An unrecognised value falls
        // back to 'web' (the column's own default) rather than failing the insert.
        $source = in_array($source, array('chat', 'web', 'manual'), true) ? $source : 'web';
        $CI->load->library('basic');
        $CI->basic->insert_data('ab_appointments', array(
            'user_id' => $user_id, 'service_id' => $service_id, 'customer_name' => $name,
            'customer_phone' => $phone, 'customer_email' => $email, 'starts_at' => $starts,
            'ends_at' => $ends, 'status' => 'pending', 'booking_key' => $key,
            'source' => $source,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $appointment_id = (int) $CI->db->insert_id();

        // Event Engine (brief 22). Wrapped: the customer has a confirmed booking either way.
        try {
            $CI->load->helper('event');
            if (function_exists('event_emit')) {
                event_emit($user_id, 'appointment.created', 'appointment', $appointment_id, array(
                    'service_id' => $service_id, 'service_name' => $svc['name'] ?? '',
                    'starts_at' => $starts, 'ends_at' => $ends, 'source' => $source,
                    'full_name' => $name, 'phone' => $phone, 'email' => $email,
                    'booking_key' => $key,
                ));
            }
        } catch (Throwable $e) {
            log_message('error', 'ab_create_booking event_emit: ' . $e->getMessage());
        }

        return array('code' => 'ok', 'appointment_id' => $appointment_id, 'starts_at' => $starts, 'ends_at' => $ends, 'booking_key' => $key, 'service' => $svc);
    }
}
