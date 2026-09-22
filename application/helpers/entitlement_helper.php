<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Package entitlements for the Creative Monkey custom controllers.
 *
 * Stock features gate on `user_type == 'Admin' || in_array(<module id>, $this->module_access)`,
 * where module_access is built in Home::__construct from package.module_ids (members) or the
 * team role's module_access keys (is_manager == 1). The custom controllers below only checked
 * logged_in; this helper gives them the same package gate from ONE central map.
 *
 * Module id block 400-409 is reserved for these features (stock/add-on ids in use top out at
 * 353). The matching `modules` rows are created by
 * assets/backup_db/migrations/2026-09-22-custom-module-entitlements.sql (400-407),
 * 2026-09-23-pricing-admin.sql (408) and 2026-09-24-event-automation.sql (409).
 *
 * SAFETY SWITCH: enforcement only happens when config item
 * 'entitlement_enforce_custom_modules' is TRUE. Unset/FALSE = log a would-deny and allow.
 * Admin is never blocked.
 */

if (!function_exists('entitlement_map')) {
    /** @return array feature_key => module_id */
    function entitlement_map()
    {
        return array(
            'ai_agents'           => 400, // AI Agents (profiles / brand voice per channel)
            'crm'                 => 401, // CRM pipeline
            'sales_automation'    => 402, // Sales Automation (AI follow-ups)
            'ai_content_writer'   => 403, // AI Content Writer
            'appointment_booking' => 404, // Appointments (public booking pages stay open)
            'analytics_hub'       => 405, // Analytics Hub
            'reengage'            => 406, // Re-engagement campaigns
            'missed_questions'    => 407, // Missed Questions (AI unanswered)
            'pricing'             => 408, // Products & Pricing (pricing_config editor)
            'automations'         => 409, // Automations (event-triggered rules)
        );
    }
}

if (!function_exists('entitlement_module_id')) {
    function entitlement_module_id($feature_key)
    {
        $map = entitlement_map();
        return isset($map[$feature_key]) ? (int) $map[$feature_key] : 0;
    }
}

if (!function_exists('entitlement_allows')) {
    /**
     * TRUE when the current session's package (or team role) includes the feature's module.
     * Does not consider the enforcement switch — see entitlement_require().
     */
    function entitlement_allows($feature_key)
    {
        $CI =& get_instance();
        if ($CI->session->userdata('user_type') == 'Admin') return true;

        $module_id = entitlement_module_id($feature_key);
        if ($module_id <= 0) {
            log_message('error', 'entitlement: unknown feature key ' . $feature_key);
            return false;
        }

        // Home::__construct already resolved member package vs team-manager role into this.
        $access = isset($CI->module_access) ? $CI->module_access : $CI->session->userdata('module_access');
        $access = array_map('intval', (array) $access);
        return in_array($module_id, $access, true);
    }
}

if (!function_exists('entitlement_enforced')) {
    function entitlement_enforced()
    {
        $CI =& get_instance();
        return $CI->config->item('entitlement_enforce_custom_modules') === true;
    }
}

if (!function_exists('entitlement_require')) {
    /**
     * Gate a request on the feature. Denies with the app's standard behaviour:
     * AJAX -> JSON {status:'0', message:'Access Forbidden'}; otherwise -> home/access_forbidden.
     * While enforcement is off it only logs the would-deny.
     */
    function entitlement_require($feature_key)
    {
        if (entitlement_allows($feature_key)) return true;

        $CI =& get_instance();
        $uid = $CI->session->userdata('user_id');
        $where = $CI->router->fetch_class() . '/' . $CI->router->fetch_method();

        if (!entitlement_enforced()) {
            // 'error' level on purpose: live log_threshold is 1, and this line is the evidence
            // needed before enforcement can be switched on.
            log_message('error', "entitlement: would deny '{$feature_key}' (module " . entitlement_module_id($feature_key) . ") to user {$uid} at {$where} [enforcement off]");
            return true;
        }

        log_message('info', "entitlement: denied '{$feature_key}' to user {$uid} at {$where}");
        if ($CI->input->is_ajax_request()) {
            $msg = $CI->lang->line('Access Forbidden');
            if (!$msg) $msg = 'Access Forbidden';
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(array('status' => '0', 'message' => $msg));
            exit();
        }
        redirect('home/access_forbidden', 'location');
        exit();
    }
}
