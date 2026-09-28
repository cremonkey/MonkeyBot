<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Unified outbox view over the CRM connector queue (Task 25 / C10, brief §28).
 *
 * Both wrapped destinations already share one queue table — crm_external_log,
 * one row per queued/sent/failed push, `provider` distinguishing '8xcrm' from
 * 'gsheet' (see external_crm_helper.php / crm_sheet_helper.php). This helper
 * does not change that table or its queries; it only reads a normalized,
 * tenant-scoped view of it (merging the two providers explicitly, so the
 * shape holds even if a provider ever moves to its own table) and offers a
 * safe, ownership-checked retry.
 */

if (!function_exists('crm_outbox_rows')) {
    /**
     * Tenant-scoped outbox rows across both connectors, newest first.
     * $filters: status (string|null), provider (string|null).
     */
    function crm_outbox_rows($user_id, $filters = array(), $limit = 200)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('crm_external_log')) return array();

        $normalize = function ($builder) use ($user_id, $filters) {
            $builder->where('user_id', (int) $user_id);
            if (!empty($filters['status']))   $builder->where('status', (string) $filters['status']);
            if (!empty($filters['provider'])) $builder->where('provider', (string) $filters['provider']);
            return $builder;
        };

        // Two normalized selects, one per known provider, then merged in PHP.
        // (A UNION in SQL would work too, but CI's query builder chain is
        // easier to keep tenant-scoped and testable this way, and the table
        // is small per tenant.)
        $rows = array();
        foreach (array('8xcrm', 'gsheet') as $provider) {
            $b = $ci->db->from('crm_external_log');
            $normalize($b)->where('provider', $provider);
            foreach ($b->order_by('id', 'DESC')->limit((int) $limit)->get()->result_array() as $r) {
                $rows[] = crm_outbox_normalize_row($r);
            }
        }

        usort($rows, function ($a, $b) { return $b['id'] <=> $a['id']; });
        if (count($rows) > $limit) $rows = array_slice($rows, 0, $limit);
        return $rows;
    }
}

if (!function_exists('crm_outbox_normalize_row')) {
    /** One crm_external_log row -> the shape the outbox view renders. */
    function crm_outbox_normalize_row($r)
    {
        return array(
            'id'         => (int) $r['id'],
            'provider'   => (string) $r['provider'],
            'provider_label' => ($r['provider'] === 'gsheet') ? 'Google Sheet' : '8xCRM',
            'deal_id'    => isset($r['deal_id']) ? (int) $r['deal_id'] : null,
            'phone'      => (string) ($r['phone'] ?? ''),
            'status'     => (string) $r['status'],
            'attempts'   => (int) ($r['attempts'] ?? 0),
            'http_code'  => isset($r['http_code']) ? (int) $r['http_code'] : null,
            'error'      => $r['error'] ?? null,
            'remote_id'  => $r['remote_id'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'next_attempt_at' => $r['next_attempt_at'] ?? null,
        );
    }
}

if (!function_exists('crm_outbox_counts')) {
    /** Tenant-scoped status counts, for the summary strip. */
    function crm_outbox_counts($user_id)
    {
        $ci = &get_instance();
        $out = array('pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0);
        if (!$ci->db->table_exists('crm_external_log')) return $out;
        foreach ($ci->db->select('status, COUNT(*) c')->from('crm_external_log')
            ->where('user_id', (int) $user_id)->group_by('status')->get()->result_array() as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }
}

if (!function_exists('crm_outbox_retry')) {
    /**
     * Retry one row: tenant-owned only, and only a row that is not currently
     * mid-flight ('processing'). Resets it to 'pending' with next_attempt_at
     * now so the next Cron_hub drain picks it straight up; attempts is left
     * untouched (xcrm_defer's 5-attempt give-up counter still applies), except
     * a 'failed' row (already gave up) gets its attempts cleared so it gets
     * the full backoff sequence again on a deliberate manual retry.
     *
     * @return array{ok: bool, message: string}
     */
    function crm_outbox_retry($user_id, $log_id)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('crm_external_log')) return array('ok' => false, 'message' => 'not available');

        $row = $ci->db->from('crm_external_log')
            ->where('id', (int) $log_id)->where('user_id', (int) $user_id)->get()->row_array();
        if (!$row) return array('ok' => false, 'message' => 'not found');
        if ($row['status'] === 'processing') return array('ok' => false, 'message' => 'currently sending, try again shortly');
        if ($row['status'] === 'sent') return array('ok' => false, 'message' => 'already sent');

        $update = array('status' => 'pending', 'next_attempt_at' => date('Y-m-d H:i:s'), 'error' => null);
        if ($row['status'] === 'failed') $update['attempts'] = 0;

        $ci->db->where('id', (int) $log_id)->where('user_id', (int) $user_id)->update('crm_external_log', $update);
        return array('ok' => true, 'message' => 'queued for retry');
    }
}
