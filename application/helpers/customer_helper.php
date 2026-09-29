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

// ------------------------------------------------------------------ merge (Task 29 / C14, brief §16)
//
// REVIEW GATE: nothing here ever merges automatically. customer_merge_suggestions_from_rows()
// only ever returns candidates for an owner to look at on the Customer 360 profile (a "Merge"
// button); the only way `customers`/`customer_identities`/`conversations` rows actually move is
// an explicit customer_merge() call from Customers::merge(), itself CSRF + ajax + tenant-owned
// gated. Every merge is reversible: customer_merge() records the exact row ids it moved in
// customer_merge_log (identities_moved, conversations_moved — 2026-10-05c-customer-merge.sql)
// and customer_unmerge() moves back only those same ids, nothing inferred at unmerge time.
//
// Unlike the read-side functions above, these take the real $db (write access) but keep the
// same "accept $db so a test can inject a fake" convention — no get_instance() in here, exactly
// like provisioning_helper.php's account creation.

if (!function_exists('customer_merge_log_ready')) {
    /** Cached table/column guard for the two new customer_merge_log columns this task adds
     *  (conversations_moved, unmerged_at). Code must work before the migration runs: merge and
     *  unmerge simply refuse (no partial write) until both columns exist. */
    function customer_merge_log_ready($db, array &$cache)
    {
        if (!array_key_exists('customer_merge_log', $cache)) {
            $cache['customer_merge_log'] =
                is_object($db) && method_exists($db, 'table_exists') && $db->table_exists('customer_merge_log')
                && method_exists($db, 'field_exists')
                && $db->field_exists('conversations_moved', 'customer_merge_log')
                && $db->field_exists('unmerged_at', 'customer_merge_log');
        }
        return $cache['customer_merge_log'];
    }
}

if (!function_exists('customer_merge')) {
    /**
     * Moves every customer_identities and conversations row from $merge_id to $keep_id for
     * tenant $user_id, in one transaction, then marks $merge_id retired
     * (customers.merged_into_customer_id) and writes one customer_merge_log row recording the
     * exact ids moved so customer_unmerge() can put them back.
     *
     * Both customers must already belong to $user_id (cross-tenant refused, same id-then-
     * user_id guard as customer_scope_owner()). Self-merge ($keep_id === $merge_id) refused.
     * A customer already merged into something else (either side) refused — merge chains are
     * not supported, the owner re-picks a keep id instead.
     *
     * An identity on the merged customer that would collide with an identity the keep customer
     * already has (same platform + channel_account_id + external_id — the table's unique key)
     * is left where it is rather than moved: two rows can never legitimately claim the same
     * identity, and silently overwriting one would not be reversible.
     *
     * Returns array(bool $ok, array $errors, array|null $result). $result on success:
     * array('log_id', 'keep_id', 'merge_id', 'identities_moved', 'conversations_moved').
     */
    function customer_merge($db, $keep_id, $merge_id, $actor_id, $user_id)
    {
        $keep_id = (int) $keep_id;
        $merge_id = (int) $merge_id;
        $user_id = (int) $user_id;
        $actor_id = $actor_id !== null ? (int) $actor_id : null;

        if ($keep_id <= 0 || $merge_id <= 0 || $user_id <= 0) {
            return array(false, array('Invalid customer.'), null);
        }
        if ($keep_id === $merge_id) {
            return array(false, array('Cannot merge a customer into itself.'), null);
        }

        $ready_cache = array();
        if (!customer_merge_log_ready($db, $ready_cache)) {
            return array(false, array('Merge is not available yet on this install (pending migration).'), null);
        }

        $keep = $db->select('id, merged_into_customer_id')->from('customers')
            ->where('id', $keep_id)->where('user_id', $user_id)->get()->row_array();
        $merge = $db->select('id, merged_into_customer_id')->from('customers')
            ->where('id', $merge_id)->where('user_id', $user_id)->get()->row_array();

        if (empty($keep) || empty($merge)) {
            return array(false, array('Both customers must belong to this account.'), null);
        }
        if (!empty($keep['merged_into_customer_id'])) {
            return array(false, array('The customer you are keeping was already merged elsewhere. Pick its surviving record instead.'), null);
        }
        if (!empty($merge['merged_into_customer_id'])) {
            return array(false, array('That customer was already merged.'), null);
        }

        $db->trans_start();

        // ---- identities: skip anything that would collide with an identity the keep
        // customer already has (same unique key), everything else moves.
        $keep_idents = $db->select('platform, channel_account_id, external_id')->from('customer_identities')
            ->where('customer_id', $keep_id)->where('user_id', $user_id)->get()->result_array();
        $keep_keys = array();
        foreach ($keep_idents as $ki) {
            $keep_keys[$ki['platform'] . '|' . $ki['channel_account_id'] . '|' . $ki['external_id']] = true;
        }

        $merge_idents = $db->select('id, platform, channel_account_id, external_id')->from('customer_identities')
            ->where('customer_id', $merge_id)->where('user_id', $user_id)->get()->result_array();
        $identities_moved = array();
        foreach ($merge_idents as $mi) {
            $key = $mi['platform'] . '|' . $mi['channel_account_id'] . '|' . $mi['external_id'];
            if (isset($keep_keys[$key])) continue; // would collide with an existing identity on $keep_id — leave it
            $db->where('id', (int) $mi['id'])->where('customer_id', $merge_id)->where('user_id', $user_id)
                ->update('customer_identities', array('customer_id' => $keep_id));
            $identities_moved[] = (int) $mi['id'];
        }

        // ---- conversations: no uniqueness constraint to worry about, every row moves.
        $merge_convs = $db->select('id')->from('conversations')
            ->where('customer_id', $merge_id)->where('user_id', $user_id)->get()->result_array();
        $conversations_moved = array();
        foreach ($merge_convs as $mc) {
            $db->where('id', (int) $mc['id'])->where('customer_id', $merge_id)->where('user_id', $user_id)
                ->update('conversations', array('customer_id' => $keep_id));
            $conversations_moved[] = (int) $mc['id'];
        }

        $db->where('id', $merge_id)->where('user_id', $user_id)
            ->update('customers', array('merged_into_customer_id' => $keep_id, 'updated_at' => date('Y-m-d H:i:s')));

        $db->insert('customer_merge_log', array(
            'user_id'             => $user_id,
            'source_customer_id'  => $merge_id,
            'target_customer_id'  => $keep_id,
            'method'              => 'manual',
            'actor_type'          => 'agent',
            'actor_id'            => $actor_id,
            'identities_moved'    => json_encode($identities_moved),
            'conversations_moved' => json_encode($conversations_moved),
            'note'                => null,
            'created_at'          => date('Y-m-d H:i:s'),
        ));
        $log_id = (int) $db->insert_id();

        $db->trans_complete();
        if ($db->trans_status() === false) {
            return array(false, array('Database error while merging. Nothing was changed.'), null);
        }

        if (function_exists('audit_record')) {
            audit_record(
                $user_id, 'customer.merge', 'customer', (string) $merge_id,
                array('merge_id' => $merge_id),
                array('keep_id' => $keep_id, 'log_id' => $log_id, 'identities_moved' => $identities_moved, 'conversations_moved' => $conversations_moved),
                $actor_id, 'user'
            );
        }

        return array(true, array(), array(
            'log_id'              => $log_id,
            'keep_id'             => $keep_id,
            'merge_id'            => $merge_id,
            'identities_moved'    => $identities_moved,
            'conversations_moved' => $conversations_moved,
        ));
    }
}

if (!function_exists('customer_unmerge')) {
    /**
     * Reverses exactly one customer_merge_log row: moves the identities and conversations it
     * recorded back to the retired (source) customer, clears customers.merged_into_customer_id,
     * and stamps the log row unmerged_at so a second call on the same $log_id is refused —
     * nothing is inferred from current state, only the ids this exact merge moved.
     *
     * $log_id must belong to tenant $user_id. Returns array(bool $ok, array $errors,
     * array|null $result). $result on success: array('source_customer_id',
     * 'target_customer_id', 'identities_restored', 'conversations_restored').
     */
    function customer_unmerge($db, $log_id, $actor_id, $user_id)
    {
        $log_id = (int) $log_id;
        $user_id = (int) $user_id;
        $actor_id = $actor_id !== null ? (int) $actor_id : null;

        if ($log_id <= 0 || $user_id <= 0) {
            return array(false, array('Invalid merge record.'), null);
        }

        $ready_cache = array();
        if (!customer_merge_log_ready($db, $ready_cache)) {
            return array(false, array('Unmerge is not available yet on this install (pending migration).'), null);
        }

        $log = $db->select('id, source_customer_id, target_customer_id, identities_moved, conversations_moved, unmerged_at')
            ->from('customer_merge_log')->where('id', $log_id)->where('user_id', $user_id)->get()->row_array();
        if (empty($log)) {
            return array(false, array('Merge record not found for this account.'), null);
        }
        if (!empty($log['unmerged_at'])) {
            return array(false, array('This merge was already undone.'), null);
        }

        $source_id = (int) $log['source_customer_id'];
        $target_id = (int) $log['target_customer_id'];
        $identity_ids = json_decode((string) $log['identities_moved'], true);
        $identity_ids = is_array($identity_ids) ? array_map('intval', $identity_ids) : array();
        $conversation_ids = json_decode((string) $log['conversations_moved'], true);
        $conversation_ids = is_array($conversation_ids) ? array_map('intval', $conversation_ids) : array();

        $db->trans_start();

        if (!empty($identity_ids)) {
            $db->where_in('id', $identity_ids)->where('user_id', $user_id)->where('customer_id', $target_id)
                ->update('customer_identities', array('customer_id' => $source_id));
        }
        if (!empty($conversation_ids)) {
            $db->where_in('id', $conversation_ids)->where('user_id', $user_id)->where('customer_id', $target_id)
                ->update('conversations', array('customer_id' => $source_id));
        }
        $db->where('id', $source_id)->where('user_id', $user_id)
            ->update('customers', array('merged_into_customer_id' => null, 'updated_at' => date('Y-m-d H:i:s')));
        $db->where('id', $log_id)->where('user_id', $user_id)
            ->update('customer_merge_log', array('unmerged_at' => date('Y-m-d H:i:s')));

        $db->trans_complete();
        if ($db->trans_status() === false) {
            return array(false, array('Database error while undoing the merge. Nothing was changed.'), null);
        }

        if (function_exists('audit_record')) {
            audit_record(
                $user_id, 'customer.unmerge', 'customer', (string) $source_id,
                array('log_id' => $log_id),
                array('target_id' => $target_id, 'identities_restored' => $identity_ids, 'conversations_restored' => $conversation_ids),
                $actor_id, 'user'
            );
        }

        return array(true, array(), array(
            'source_customer_id'    => $source_id,
            'target_customer_id'    => $target_id,
            'identities_restored'   => $identity_ids,
            'conversations_restored'=> $conversation_ids,
        ));
    }
}

if (!function_exists('customer_merge_suggestion_rows')) {
    /** Raw rows for the suggestion query: every verified identity for this tenant's
     *  not-already-merged customers, joined to the customer's display name. One query; the
     *  grouping/pairing logic lives in customer_merge_suggestions_from_rows() so it is
     *  testable without a DB. */
    function customer_merge_suggestion_rows($db, $user_id)
    {
        return $db->select('ci.customer_id AS customer_id, ci.platform AS platform, ci.external_id AS external_id, c.display_name AS display_name')
            ->from('customer_identities ci')
            ->join('customers c', 'c.id = ci.customer_id')
            ->where('ci.user_id', (int) $user_id)
            ->where('c.user_id', (int) $user_id)
            ->where('ci.verified', 1)
            ->where('c.merged_into_customer_id IS NULL', null, false)
            ->order_by('ci.external_id', 'ASC')
            ->get()->result_array();
    }
}

if (!function_exists('customer_merge_suggestions_from_rows')) {
    /**
     * Groups verified-identity rows by their external_id (the phone number or email string
     * itself — a verified identity means the tenant confirmed ownership of that exact value),
     * and turns every group spanning 2+ distinct customers into a suggestion pair. Never
     * merges anything: this is display-only data for the "Merge" button on the profile page.
     *
     * Pure function, no DB — the query lives in customer_merge_suggestion_rows() above so this
     * is unit-testable with plain arrays.
     */
    function customer_merge_suggestions_from_rows(array $rows, $limit = 20)
    {
        $groups = array();
        foreach ($rows as $r) {
            $value = isset($r['external_id']) ? (string) $r['external_id'] : '';
            if ($value === '') continue;
            $cid = (int) ($r['customer_id'] ?? 0);
            if ($cid <= 0) continue;
            if (!isset($groups[$value])) $groups[$value] = array();
            $groups[$value][$cid] = $r; // de-dupe: same customer verified twice on the same value counts once
        }

        $suggestions = array();
        $seen_pairs = array();
        foreach ($groups as $value => $by_customer) {
            if (count($by_customer) < 2) continue;
            $customer_ids = array_keys($by_customer);
            sort($customer_ids);
            for ($i = 0; $i < count($customer_ids); $i++) {
                for ($j = $i + 1; $j < count($customer_ids); $j++) {
                    $a = $customer_ids[$i];
                    $b = $customer_ids[$j];
                    $pair_key = $a . ':' . $b;
                    if (isset($seen_pairs[$pair_key])) continue;
                    $seen_pairs[$pair_key] = true;
                    $suggestions[] = array(
                        'customer_id_a'    => $a,
                        'customer_id_b'    => $b,
                        'matched_value'    => (string) $value, // PHP coerces a numeric array key (e.g. a phone number) to int; keep it as the original string
                        'matched_platform' => $by_customer[$a]['platform'],
                        'display_name_a'   => customer_display_name(array('id' => $a, 'display_name' => $by_customer[$a]['display_name'])),
                        'display_name_b'   => customer_display_name(array('id' => $b, 'display_name' => $by_customer[$b]['display_name'])),
                    );
                    if (count($suggestions) >= $limit) return $suggestions;
                }
            }
        }
        return $suggestions;
    }
}

if (!function_exists('customer_merge_suggestions')) {
    /** Convenience wrapper Customers::suggestions() and the profile page call: query + group
     *  in one step, still tenant-scoped through customer_merge_suggestion_rows(). */
    function customer_merge_suggestions($db, $user_id, $limit = 20)
    {
        return customer_merge_suggestions_from_rows(customer_merge_suggestion_rows($db, $user_id), $limit);
    }
}
