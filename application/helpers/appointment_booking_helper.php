<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared insert logic for ab_appointments, extracted from
 * Appointment_booking::submit_booking() (Task 27 / C12) so the AI booking tool
 * (Ai_tools::t_create_booking) reuses the exact same validation and clash check instead
 * of duplicating it. Appointment_booking::submit_booking() now calls this helper too —
 * behaviour there is unchanged.
 */

if (!function_exists('ab_create_booking')) {
    /**
     * Validate and insert one appointment. Tenant-scoped throughout: the service lookup is
     * filtered by $user_id, so a service id belonging to another tenant is rejected exactly
     * like an unknown id.
     *
     * @return array{status:string, message:string, appointment_id:int, starts_at:string, booking_key:string}
     */
    function ab_create_booking($user_id, $service_id, $date, $time, $name, $phone = '', $email = '', $source = 'web')
    {
        $CI =& get_instance();
        $user_id = (int) $user_id;
        $service_id = (int) $service_id;
        $date = trim((string) $date);
        $time = trim((string) $time);
        $name = strip_tags(trim((string) $name));
        $phone = strip_tags(trim((string) $phone));
        $email = strip_tags(trim((string) $email));

        if ($user_id <= 0 || $service_id <= 0 || $date === '' || $time === '' || $name === '') {
            return array('status' => '0', 'message' => 'Missing fields', 'appointment_id' => 0, 'starts_at' => '', 'booking_key' => '');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return array('status' => '0', 'message' => 'Invalid date/time', 'appointment_id' => 0, 'starts_at' => '', 'booking_key' => '');
        }

        // Tenant-scoped: a service_id from a different account is treated as unknown.
        $svc = $CI->db->from('ab_services')->where('id', $service_id)->where('user_id', $user_id)->get()->row_array();
        if (empty($svc)) {
            return array('status' => '0', 'message' => 'Invalid service', 'appointment_id' => 0, 'starts_at' => '', 'booking_key' => '');
        }

        $starts_ts = strtotime($date . ' ' . $time);
        if ($starts_ts === false) {
            return array('status' => '0', 'message' => 'Invalid date/time', 'appointment_id' => 0, 'starts_at' => '', 'booking_key' => '');
        }
        $starts = date('Y-m-d H:i:s', $starts_ts);
        $ends = date('Y-m-d H:i:s', strtotime($starts . ' +' . (int) $svc['duration_min'] . ' minutes'));

        // Same interval-overlap clash check as the web widget: the slot must be free.
        $clash = $CI->db->from('ab_appointments')->where('user_id', $user_id)
            ->where('starts_at <', $ends)->where('ends_at >', $starts)
            ->where_in('status', array('pending', 'confirmed'))->count_all_results();
        if ($clash) {
            return array('status' => '0', 'message' => 'That slot is already booked.', 'appointment_id' => 0, 'starts_at' => $starts, 'booking_key' => '');
        }

        $key = substr(md5(uniqid('bk', true)), 0, 16);
        $CI->load->library('basic');
        $CI->basic->insert_data('ab_appointments', array(
            'user_id' => $user_id, 'service_id' => $service_id, 'customer_name' => $name,
            'customer_phone' => $phone, 'customer_email' => $email, 'starts_at' => $starts,
            'ends_at' => $ends, 'status' => 'pending', 'booking_key' => $key,
            'source' => in_array($source, array('web', 'ai'), true) ? $source : 'web',
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $appointment_id = (int) $CI->db->insert_id();

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

        return array('status' => '1', 'message' => 'Booked for ' . $starts . '. Reference: ' . $key, 'appointment_id' => $appointment_id, 'starts_at' => $starts, 'booking_key' => $key);
    }
}
