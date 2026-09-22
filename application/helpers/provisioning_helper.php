<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Client provisioning (Phase 11 / brief §31, §32, §40, §51) — create a brand-new client
 * account end-to-end from the Agency Control Center, without the stock public sign-up form
 * and without a raw DB insert.
 *
 * Every DB-touching function here takes an injectable $db (a CodeIgniter query builder, or
 * the FakeDb in tests/provisioning/run.php) — same pattern as pricing_helper.php's
 * pricing_save_config() and sequence_helper.php's sequence_enroll(). This is what makes the
 * validation / duplicate-email / template-key / transaction-rollback logic testable without a
 * database (see tests/provisioning/run.php); the HTTP + DB end-to-end path is proven by
 * docker/scripts/staging_provisioning_tests.sh.
 *
 * FIELD SET AND CONVENTIONS — matched to the stock flows on purpose, not reinvented:
 *   - password hashing: pw_hash() from password_helper.php (SPEC-00 Task A), the same
 *     function Home::sign_up_action() and Admin::add_user_action() both call.
 *   - expired_date: date('Y-m-d', strtotime('+'.$validity.' day', strtotime(today))), the
 *     exact formula Home::sign_up_action() uses for the default package.
 *   - status='1', deleted='0', user_type='Member' — see Home::sign_up_action() (status='1'
 *     is what it sets when signup activation is disabled; a client an admin is provisioning
 *     by hand needs no activation email, so this flow always goes straight to '1').
 *   - onboarding_state row shape (user_id, current_step=1, template_key, data_json='{}',
 *     updated_at) matches exactly what Onboarding::state() creates the first time a tenant
 *     opens the Setup Guide — see application/controllers/Onboarding.php. A plain insert()
 *     is enough here (not Onboarding::state()'s INSERT ... ON DUPLICATE KEY UPDATE) because
 *     the user_id was JUST created by this same function: it cannot already have a row.
 *
 * NO TENANT-FACING ENTITLEMENT KEY: this is an admin-only agency action (brief says so
 * explicitly), gated in Agency_provision's constructor on user_type=='Admin' the same way
 * Agency.php gates its own dashboard — not through entitlement_helper.php.
 */

if (!function_exists('provisioning_generate_password')) {
    /**
     * A strong, unique temporary password for a freshly provisioned client. Never logged,
     * never stored anywhere but the one-time API response and the password_hash() in `users`.
     * Guarantees at least one lowercase, one uppercase, one digit and one symbol so it always
     * clears typical "strong password" checks, then fills the rest from a wide alphabet and
     * shuffles so the guaranteed characters are not always in the same positions.
     */
    function provisioning_generate_password($length = 16)
    {
        $length = max(12, (int) $length);
        $sets = array(
            'abcdefghijkmnpqrstuvwxyz',    // no l/o — avoid look-alikes
            'ABCDEFGHJKLMNPQRSTUVWXYZ',    // no I/O
            '23456789',                    // no 0/1
            '!@#$%^&*-_=+?',
        );
        $pw = array();
        foreach ($sets as $set) $pw[] = $set[random_int(0, strlen($set) - 1)];
        $all = implode('', $sets);
        while (count($pw) < $length) $pw[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($pw) - 1; $i > 0; $i--) {          // Fisher–Yates
            $j = random_int(0, $i);
            $tmp = $pw[$i]; $pw[$i] = $pw[$j]; $pw[$j] = $tmp;
        }
        return implode('', $pw);
    }
}

if (!function_exists('provisioning_prepare_client')) {
    /**
     * Validate the four inputs and resolve the package/template rows needed to create the
     * account. Never writes anything. @return array [errors[], ctx|null]
     */
    function provisioning_prepare_client($db, $input)
    {
        $errors = array();

        $business_name = trim(strip_tags((string) ($input['business_name'] ?? '')));
        if ($business_name === '') $errors[] = 'Business name is required.';

        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '') {
            $errors[] = 'Contact email is required.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        } else {
            $dupe = $db->select('id')->from('users')->where('email', $email)->limit(1)->get()->row_array();
            if (!empty($dupe)) $errors[] = 'A client with this email already exists.';
        }

        $package_id = (int) ($input['package_id'] ?? 0);
        $package = null;
        if ($package_id <= 0) {
            $errors[] = 'Choose a package.';
        } else {
            $package = $db->select('id, package_name, validity')->from('package')
                ->where('id', $package_id)->where('deleted', '0')->limit(1)->get()->row_array();
            if (empty($package)) $errors[] = 'Unknown package. Pick one from the list.';
        }

        $template_key = trim((string) ($input['template_key'] ?? ''));
        $tpl = null;
        if ($template_key !== '') {
            $tpl = function_exists('industry_template_load') ? industry_template_load($template_key) : null;
            if ($tpl === null) {
                $errors[] = 'Unknown business type.';
            } elseif (function_exists('industry_template_validate') && !empty(industry_template_validate($tpl))) {
                $errors[] = 'This business type is not configured correctly.';
            }
        }

        if (!empty($errors)) return array($errors, null);

        return array(array(), array(
            'business_name' => $business_name,
            'email' => $email,
            'package' => $package,
            'template_key' => $template_key !== '' ? $tpl['key'] : null,
        ));
    }
}

if (!function_exists('provisioning_create_client')) {
    /**
     * Create the client's `users` row (and, with a template, its `onboarding_state` row) in
     * one transaction. Zero rows are left in either table on any failure — validated fields
     * fail before any write; a template that stops being valid between the check above and
     * the write below (race/corruption) rolls back the users row too, never leaving a half
     * -provisioned account (brief §51, no silent failure — and no silent partial success).
     *
     * @return array [ok(bool), errors(string[]), result(array|null)]
     *   result: user_id, name, email, package_id, package_name, expired_date, template_key,
     *   temp_password (plaintext — caller returns it to the browser ONCE and never logs it).
     */
    function provisioning_create_client($db, $admin_id, $input)
    {
        list($errors, $ctx) = provisioning_prepare_client($db, $input);
        if (!empty($errors)) return array(false, $errors, null);

        if (!function_exists('pw_hash')) require_once dirname(__DIR__) . '/helpers/password_helper.php';

        $plain_password = provisioning_generate_password();
        $to_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+' . (int) $ctx['package']['validity'] . ' day', strtotime($to_date)));

        $user_data = array(
            'name'           => $ctx['business_name'],
            'email'          => $ctx['email'],
            'password'       => pw_hash($plain_password),
            'user_type'      => 'Member',
            'status'         => '1',
            'deleted'        => '0',
            'expired_date'   => $expiry_date,
            'package_id'     => (int) $ctx['package']['id'],
            'affiliate_id'   => 0,
            'provisioned_by' => (int) $admin_id,
            'provisioned_at' => date('Y-m-d H:i:s'),
        );

        $db->trans_start();
        $db->insert('users', $user_data);
        $uid = (int) $db->insert_id();

        $applied_template = null;
        if ($uid > 0 && $ctx['template_key'] !== null) {
            // Defense-in-depth (see file docblock): reload + revalidate inside the
            // transaction rather than trusting the pre-check above.
            $tpl = function_exists('industry_template_load') ? industry_template_load($ctx['template_key']) : null;
            $tpl_errors = ($tpl !== null && function_exists('industry_template_validate')) ? industry_template_validate($tpl) : array('unavailable');
            if ($tpl === null || !empty($tpl_errors)) {
                $db->trans_rollback();
                return array(false, array('This business type is no longer available. Nothing was saved.'), null);
            }
            $db->insert('onboarding_state', array(
                'user_id'      => $uid,
                'current_step' => 1,
                'template_key' => $tpl['key'],
                'data_json'    => '{}',
                'updated_at'   => date('Y-m-d H:i:s'),
            ));
            $applied_template = $tpl['key'];
        }

        $db->trans_complete();
        if ($db->trans_status() === false || $uid <= 0) {
            return array(false, array('Could not create the client account. Nothing was saved.'), null);
        }

        return array(true, array(), array(
            'user_id'       => $uid,
            'name'          => $ctx['business_name'],
            'email'         => $ctx['email'],
            'package_id'    => (int) $ctx['package']['id'],
            'package_name'  => $ctx['package']['package_name'],
            'expired_date'  => $expiry_date,
            'template_key'  => $applied_template,
            'temp_password' => $plain_password,
        ));
    }
}
