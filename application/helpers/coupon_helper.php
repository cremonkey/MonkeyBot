<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-06 — programmatic coupon generation for ecommerce_coupon.
 | Columns (verified): user_id, store_id, coupon_type enum('percent','fixed'), coupon_code,
 | coupon_amount, free_shipping_enabled, expiry_date, updated_at, product_ids, max_usage_limit, used, status.
 */

if (!function_exists('generate_coupon')) {
    /**
     * @param int    $user_id
     * @param int    $store_id
     * @param int    $percent       discount percentage (1-90)
     * @param int    $days_valid
     * @param string $prefix
     * @return string|false  the coupon code, or false on failure
     */
    function generate_coupon($user_id, $store_id, $percent, $days_valid = 7, $prefix = 'SAVE')
    {
        try {
            $user_id = (int) $user_id; $store_id = (int) $store_id; $percent = (int) $percent;
            if ($user_id <= 0 || $store_id <= 0) return false;
            if ($percent < 1) $percent = 5;
            if ($percent > 90) $percent = 90;
            $CI =& get_instance();
            $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code = $prefix . strtoupper(substr(md5(uniqid('', true) . $i), 0, 8));
                $exists = $CI->db->from('ecommerce_coupon')->where('coupon_code', $code)->count_all_results();
                if (!$exists) break;
            }
            $CI->db->insert('ecommerce_coupon', array(
                'user_id' => $user_id,
                'store_id' => $store_id,
                'coupon_type' => 'percent',
                'coupon_code' => $code,
                'coupon_amount' => $percent,
                'free_shipping_enabled' => '0',
                'expiry_date' => date('Y-m-d H:i:s', strtotime('+' . (int) $days_valid . ' days')),
                'updated_at' => date('Y-m-d H:i:s'),
                'product_ids' => '',
                'max_usage_limit' => 1,
                'used' => 0,
                'status' => '1',
            ));
            return $CI->db->affected_rows() > 0 ? $code : false;
        } catch (Exception $e) {
            log_message('error', 'generate_coupon failed: ' . $e->getMessage());
            return false;
        }
    }
}
