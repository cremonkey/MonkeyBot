<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer 360 (read side) — Task 16 (C1, brief §16/§13). Pure / DB-shape logic factored out
 * of Customers.php and Inbox::customer_panel() so it is unit-testable without a full
 * CodeIgniter bootstrap (tests/customers/run.php). Both callers use these same functions: a
 * passing test proves the real code path, not a parallel reimplementation.
 *
 * Read-only. Every scope function takes the tenant id ($user_id, the same "$this->uid"
 * convention as Inbox.php: session real_user_id falling back to user_id) and applies it
 * first, mirroring inbox_helper.php's inbox_scope_conversations()/inbox_scope_conversation_owner().
 *
 * Optional tables (crm_deals, ab_appointments, ecommerce_cart, conversation_labels,
 * internal_notes) are guarded by a cached table_exists() check per request, exactly like
 * Inbox.php's labels_ready()/media_column_ready(). Nothing here writes to the database.
 */

if (!function_exists('customer_scope_list')) {
    /**
     * Applies the tenant guard plus the list filters (search, channel) to a query builder
     * already selecting from `customers c`. Mirrors inbox_scope_conversations(): the tenant
     * guard is always the first where() call, filters are appended in a fixed order so a
     * caller (or a test) can assert on $db->calls[0].
     */
    function customer_scope_list($db, $user_id, array $filters = array())
    {
        $db->where('c.user_id', (int) $user_id);

        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $db->group_start();
            $db->like('c.display_name', $s);
            $db->or_like('c.phone', $s);
            $db->or_like('c.email', $s);
            $db->group_end();
        }

        if (!empty($filters['channel'])) {
            // Channel filter matches customers who have at least one identity on that
            // platform. Tenant-scoped twice (c.user_id above, ci.user_id here) so another
            // tenant's identity rows never leak a match.
            $platform = (string) $filters['channel'];
            $db->where(
                "EXISTS (SELECT 1 FROM customer_identities ci WHERE ci.customer_id = c.id " .
                    "AND ci.user_id = " . (int) $user_id . " AND ci.platform = " . customer_helper_quote($db, $platform) . ")",
                null, false
            );
        }

        return $db;
    }
}

if (!function_exists('customer_helper_quote')) {
    /** Escapes a value for the raw EXISTS() fragments above, using the DB driver when one is
     *  available (real CI), or a safe fallback for the fake DB used by unit tests. */
    function customer_helper_quote($db, $value)
    {
        if (is_object($db) && method_exists($db, 'escape')) return $db->escape($value);
        return "'" . addcslashes((string) $value, "'\\") . "'";
    }
}

if (!function_exists('customer_scope_owner')) {
    /** Tenant + id guard for a single customer (profile page, panel). */
    function customer_scope_owner($db, $customer_id, $user_id)
    {
        return $db->where('id', (int) $customer_id)->where('user_id', (int) $user_id);
    }
}

if (!function_exists('customer_pagination_bounds')) {
    /**
     * Clamps the DataTables-style (start, length) pair the same way Inbox.php's
     * conversations_data() does: start never negative, length defaults to $default and is
     * capped at $max.
     */
    function customer_pagination_bounds($start, $length, $default = 20, $max = 100)
    {
        $start = max(0, (int) $start);
        $length = (int) $length;
        if ($length <= 0 || $length > $max) $length = $default;
        return array($start, $length);
    }
}

if (!function_exists('customer_identities_by_channel')) {
    /**
     * Groups a flat list of customer_identities rows (platform, external_id,
     * channel_account_id) by platform, so the profile page can show "2 channels" for a
     * customer with e.g. one fb identity and one wa identity, even though they are separate
     * rows in the table. This is the exact aggregation the Task 16 brief's stack test seeds:
     * one customer, two channels.
     */
    function customer_identities_by_channel(array $identity_rows)
    {
        $by_channel = array();
        foreach ($identity_rows as $row) {
            $platform = isset($row['platform']) ? (string) $row['platform'] : '';
            if ($platform === '') continue;
            if (!isset($by_channel[$platform])) $by_channel[$platform] = array();
            $by_channel[$platform][] = $row;
        }
        return $by_channel;
    }
}

if (!function_exists('customer_channel_count')) {
    /** Distinct channel count from grouped identities, used for the list/panel summary. */
    function customer_channel_count(array $identity_rows)
    {
        return count(customer_identities_by_channel($identity_rows));
    }
}

if (!function_exists('customer_optional_table_ready')) {
    /**
     * Cached table_exists() check for one of the optional tables this feature reads
     * (crm_deals, ab_appointments, ecommerce_cart, conversation_labels + inbox_labels,
     * internal_notes). $cache is a reference to a static array owned by the caller (one per
     * request), exactly like Inbox.php's `static $has = null` pattern but keyed so one
     * function serves every optional table.
     */
    function customer_optional_table_ready($db, $table, array &$cache)
    {
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = (bool) (is_object($db) && method_exists($db, 'table_exists') && $db->table_exists($table));
        }
        return $cache[$table];
    }
}

if (!function_exists('customer_lead_stage_summary')) {
    /**
     * Reduces a customer's crm_deals rows (open/won/lost) into the compact summary the Inbox
     * right rail shows: the single most relevant open deal (soonest expected_close_date, or
     * most recently created when none has a close date) plus counts. NULL stage when the
     * customer has no deals or the crm_deals table does not exist yet.
     */
    function customer_lead_stage_summary(array $deal_rows)
    {
        if (empty($deal_rows)) return array('open_deal' => null, 'open_count' => 0, 'won_count' => 0, 'lost_count' => 0);

        $open = array_values(array_filter($deal_rows, function ($d) { return ($d['status'] ?? '') === 'open'; }));
        usort($open, function ($a, $b) {
            $ad = $a['expected_close_date'] ?? null;
            $bd = $b['expected_close_date'] ?? null;
            if ($ad && $bd) return strcmp($ad, $bd);
            if ($ad) return -1;
            if ($bd) return 1;
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return array(
            'open_deal'  => $open[0] ?? null,
            'open_count' => count($open),
            'won_count'  => count(array_filter($deal_rows, function ($d) { return ($d['status'] ?? '') === 'won'; })),
            'lost_count' => count(array_filter($deal_rows, function ($d) { return ($d['status'] ?? '') === 'lost'; })),
        );
    }
}

// ------------------------------------------------------------------ shared DB aggregation
//
// These take the CI DB object and tenant id explicitly so both Customers.php (full profile)
// and Inbox::customer_panel() (compact panel) call the SAME code — no parallel reimplementation.
// Every one of them is guarded by customer_optional_table_ready() before it touches an
// optional table, and every query filters $user_id.

if (!function_exists('customer_deals_for')) {
    /** crm_deals is keyed to messenger_bot_subscriber.id via customers.legacy_subscriber_id
     *  (same join sequence_enrollments and ab_appointments use). No rows for a customer that
     *  was never a subscriber, or when the table does not exist yet. */
    function customer_deals_for($db, $user_id, array $customer, array &$cache)
    {
        if (!customer_optional_table_ready($db, 'crm_deals', $cache)) return array();
        $legacy_id = (int) ($customer['legacy_subscriber_id'] ?? 0);
        if ($legacy_id <= 0) return array();
        return $db->select('id, pipeline_id, stage_id, title, value, currency, status, expected_close_date, lost_reason, won_at, created_at')
            ->from('crm_deals')->where('user_id', (int) $user_id)->where('subscriber_id', $legacy_id)
            ->order_by('id', 'DESC')->limit(50)->get()->result_array();
    }
}

if (!function_exists('customer_appointments_for')) {
    function customer_appointments_for($db, $user_id, array $customer, array &$cache)
    {
        if (!customer_optional_table_ready($db, 'ab_appointments', $cache)) return array();
        $legacy_id = (int) ($customer['legacy_subscriber_id'] ?? 0);
        if ($legacy_id <= 0) return array();
        return $db->select('id, service_id, starts_at, ends_at, status, source, created_at')
            ->from('ab_appointments')->where('user_id', (int) $user_id)->where('subscriber_id', $legacy_id)
            ->order_by('starts_at', 'DESC')->limit(20)->get()->result_array();
    }
}

if (!function_exists('customer_orders_for')) {
    /** ecommerce_cart.subscriber_id stores the external subscribe_id (page/chat id), the same
     *  value customer_identities.external_id holds per platform — no ecommerce_cart.customer_id
     *  column exists yet, so the join goes through every identity this customer has. */
    function customer_orders_for($db, $user_id, array $identity_rows, array &$cache)
    {
        if (!customer_optional_table_ready($db, 'ecommerce_cart', $cache)) return array();
        $external_ids = array_values(array_unique(array_filter(array_column($identity_rows, 'external_id'))));
        if (empty($external_ids)) return array();
        return $db->select('id, subtotal, payment_amount, currency, ordered_at, buyer_first_name, buyer_last_name')
            ->from('ecommerce_cart')->where('user_id', (int) $user_id)->where_in('subscriber_id', $external_ids)
            ->order_by('ordered_at', 'DESC')->limit(20)->get()->result_array();
    }
}

if (!function_exists('customer_labels_for')) {
    function customer_labels_for($db, $user_id, $customer_id, array &$cache)
    {
        if (!customer_optional_table_ready($db, 'conversation_labels', $cache)
            || !customer_optional_table_ready($db, 'inbox_labels', $cache)) return array();
        return $db->select('DISTINCT il.id, il.name, il.color')
            ->from('conversation_labels cl')
            ->join('conversations c', 'c.id = cl.conversation_id')
            ->join('inbox_labels il', 'il.id = cl.label_id')
            ->where('cl.user_id', (int) $user_id)->where('c.customer_id', (int) $customer_id)
            ->get()->result_array();
    }
}

if (!function_exists('customer_notes_for')) {
    function customer_notes_for($db, $user_id, $customer_id, array &$cache)
    {
        if (!customer_optional_table_ready($db, 'internal_notes', $cache)
            || !customer_optional_table_ready($db, 'conversations', $cache)) return array();
        return $db->select('n.id, n.note, n.author_name, n.created_at')
            ->from('internal_notes n')
            ->join('conversations c', 'c.id = n.conversation_id')
            ->where('n.user_id', (int) $user_id)->where('c.customer_id', (int) $customer_id)
            ->order_by('n.id', 'DESC')->limit(20)->get()->result_array();
    }
}

if (!function_exists('customer_conversations_for')) {
    function customer_conversations_for($db, $user_id, $customer_id, $limit = 20)
    {
        if (!(is_object($db) && method_exists($db, 'table_exists') && $db->table_exists('conversations'))) return array();
        return $db->select('id, platform, status, handoff_state, last_message_at, unread_count, message_count')
            ->from('conversations')->where('customer_id', (int) $customer_id)->where('user_id', (int) $user_id)
            ->order_by('last_message_at', 'DESC')->limit((int) $limit)->get()->result_array();
    }
}

if (!function_exists('customer_display_name')) {
    /** Same fallback chain Inbox.php's conversations_data() uses for a name to show. */
    function customer_display_name(array $customer_row)
    {
        $name = isset($customer_row['display_name']) ? trim((string) $customer_row['display_name']) : '';
        if ($name !== '') return $name;
        if (!empty($customer_row['phone'])) return (string) $customer_row['phone'];
        if (!empty($customer_row['email'])) return (string) $customer_row['email'];
        return 'Customer #' . (int) ($customer_row['id'] ?? 0);
    }
}
