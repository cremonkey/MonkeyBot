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
 * Inbox.php's labels_ready()/media_column_ready(). Everything above the "merge" section below
 * is read-only; customer_merge()/customer_unmerge() (Task 29 / C14) are the only functions
 * in this file that write.
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
        // Review round 1: a merged-away customer is retired (see customer_merge(), below) — it
        // should not appear as its own row in the list/search results any more, only under the
        // survivor it was merged into. Always applied, not opt-in: there is no current caller
        // that wants a retired customer back in a list. idx_customers_merged_into
        // (2026-10-05c-customer-merge.sql) covers this filter.
        $db->where('c.merged_into_customer_id IS NULL', null, false);

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
// button, gated on ci.verified = 1 — nothing in this codebase sets that column yet, so today
// this list is empty in production; it exists so the moment something DOES verify a phone or
// email, suggestions start appearing without further code changes). The only way
// `customers`/`customer_identities`/`conversations`/`crm_deals`/`sequence_enrollments` rows
// actually move is an explicit customer_merge() call from Customers::merge(), itself CSRF +
// ajax + tenant-owned gated. Every merge is reversible: customer_merge() records the exact row
// ids it moved in customer_merge_log (2026-10-05c-customer-merge.sql) and customer_unmerge()
// moves back only those same ids, nothing inferred at unmerge time — and only claims success
// (stamps unmerged_at, clears merged_into_customer_id) once every recorded id actually moved
// back; a partial restore is reported honestly instead (review round 1).
//
// Unlike the read-side functions above, these take the real $db (write access) but keep the
// same "accept $db so a test can inject a fake" convention — no get_instance() in here, exactly
// like provisioning_helper.php's account creation.

if (!function_exists('customer_merge_log_ready')) {
    /** Cached table/column guard for the four customer_merge_log columns this task adds
     *  (conversations_moved, deals_moved, enrollments_moved, unmerged_at — identities_moved and
     *  customers.merged_into_customer_id already existed). Code must work before the migration
     *  runs: merge and unmerge simply refuse (no partial write) until all four columns exist. */
    function customer_merge_log_ready($db, array &$cache)
    {
        if (!array_key_exists('customer_merge_log', $cache)) {
            $ok = is_object($db) && method_exists($db, 'table_exists') && $db->table_exists('customer_merge_log')
                && method_exists($db, 'field_exists');
            if ($ok) {
                foreach (array('conversations_moved', 'deals_moved', 'enrollments_moved', 'unmerged_at') as $f) {
                    if (!$db->field_exists($f, 'customer_merge_log')) { $ok = false; break; }
                }
            }
            $cache['customer_merge_log'] = $ok;
        }
        return $cache['customer_merge_log'];
    }
}

if (!function_exists('customer_merge_child_table_ready')) {
    /** Cached guard for the two optional child tables customer_merge() also moves rows in
     *  (crm_deals.customer_id — migration 2026-10-04c — and sequence_enrollments.customer_id —
     *  migration 2026-09-26). An install that predates either migration simply moves nothing in
     *  that table instead of erroring, same convention as customer_optional_table_ready(). */
    function customer_merge_child_table_ready($db, $table, array &$cache)
    {
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = is_object($db) && method_exists($db, 'table_exists') && $db->table_exists($table)
                && method_exists($db, 'field_exists')
                && $db->field_exists('customer_id', $table) && $db->field_exists('user_id', $table);
        }
        return $cache[$table];
    }
}

if (!function_exists('customer_merge_lock_row')) {
    /** Row-locked read of one "id + user_id"-keyed row (SELECT $select FROM $table WHERE id = ?
     *  AND user_id = ? FOR UPDATE), same pattern conversation_transition() uses — must be
     *  called inside an open transaction so the lock is held until trans_complete()/
     *  trans_rollback(). Returns array() when the row does not exist or does not belong to
     *  $user_id. Shared by customer_merge_lock_customer() and customer_merge_lock_log(). */
    function customer_merge_lock_row($db, $table, $select, $id, $user_id)
    {
        $q = $db->query(
            "SELECT {$select} FROM {$table} WHERE id = ? AND user_id = ? FOR UPDATE",
            array((int) $id, (int) $user_id)
        );
        return $q ? $q->row_array() : array();
    }
}

if (!function_exists('customer_merge_lock_customer')) {
    /** Row-locked read of one customer (id, merged_into_customer_id). */
    function customer_merge_lock_customer($db, $id, $user_id)
    {
        return customer_merge_lock_row($db, 'customers', 'id, merged_into_customer_id', $id, $user_id);
    }
}

if (!function_exists('customer_merge_lock_log')) {
    /**
     * Row-locked read of one customer_merge_log row (review round 2: customer_unmerge() used a
     * plain, unlocked SELECT before; a concurrent unmerge retry on the same log row could then
     * race). Locked for the same reason as customer_merge_lock_customer(): held until
     * trans_complete()/trans_rollback() so nothing else can act on this log row mid-unmerge.
     */
    function customer_merge_lock_log($db, $log_id, $user_id)
    {
        return customer_merge_lock_row(
            $db, 'customer_merge_log',
            'id, source_customer_id, target_customer_id, identities_moved, conversations_moved, deals_moved, enrollments_moved, unmerged_at',
            $log_id, $user_id
        );
    }
}

if (!function_exists('customer_merge_decode_ids')) {
    /** customer_merge_log.*_moved columns are JSON arrays of ints; always returns a plain int
     *  array, never null/false, so callers can count()/foreach it unconditionally. */
    function customer_merge_decode_ids($json)
    {
        $ids = json_decode((string) $json, true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : array();
    }
}

if (!function_exists('customer_merge_move_identities')) {
    /**
     * Moves every customer_identities row from $from_id to $to_id (tenant $user_id), one row at
     * a time, checking affected_rows() so the returned list is exactly what actually moved.
     *
     * Defensive-only skip: customer_identities' unique key is (user_id, platform,
     * channel_account_id, external_id) — customer_id is NOT part of it, so two rows sharing
     * that key could never coexist under two different customers in the first place; a real
     * "collision" here is unreachable with today's schema. The skip stays as a second line of
     * defense (never overwrite one identity with another) in case that constraint is ever
     * relaxed, not because it can happen now.
     */
    function customer_merge_move_identities($db, $user_id, $from_id, $to_id)
    {
        $keep_idents = $db->select('platform, channel_account_id, external_id')->from('customer_identities')
            ->where('customer_id', $to_id)->where('user_id', $user_id)->get()->result_array();
        $keep_keys = array();
        foreach ($keep_idents as $ki) {
            $keep_keys[$ki['platform'] . '|' . $ki['channel_account_id'] . '|' . $ki['external_id']] = true;
        }

        $rows = $db->select('id, platform, channel_account_id, external_id')->from('customer_identities')
            ->where('customer_id', $from_id)->where('user_id', $user_id)->get()->result_array();
        $moved = array();
        foreach ($rows as $r) {
            $key = $r['platform'] . '|' . $r['channel_account_id'] . '|' . $r['external_id'];
            if (isset($keep_keys[$key])) continue; // unreachable today — see docblock above
            $db->where('id', (int) $r['id'])->where('customer_id', $from_id)->where('user_id', $user_id)
                ->update('customer_identities', array('customer_id' => $to_id));
            if ((int) $db->affected_rows() > 0) $moved[] = (int) $r['id'];
        }
        return $moved;
    }
}

if (!function_exists('customer_merge_move_rows')) {
    /** Generic per-row mover for the tables with no collision risk (conversations, crm_deals,
     *  sequence_enrollments): every row on $from_id moves to $to_id, one at a time, and the
     *  returned list is exactly the ids affected_rows() confirmed actually moved. */
    function customer_merge_move_rows($db, $table, $user_id, $from_id, $to_id)
    {
        $rows = $db->select('id')->from($table)->where('customer_id', $from_id)->where('user_id', $user_id)
            ->get()->result_array();
        $moved = array();
        foreach ($rows as $r) {
            $db->where('id', (int) $r['id'])->where('customer_id', $from_id)->where('user_id', $user_id)
                ->update($table, array('customer_id' => $to_id));
            if ((int) $db->affected_rows() > 0) $moved[] = (int) $r['id'];
        }
        return $moved;
    }
}

if (!function_exists('customer_merge_restore_rows')) {
    /**
     * The unmerge-direction mirror of customer_merge_move_rows(): moves exactly the given $ids
     * back from $from_id (the target customer, where the merge put them) to $to_id (the retired
     * source customer), one row at a time.
     *
     * An id counts as restored in either of two cases: affected_rows() confirms the row still
     * had customer_id = $from_id and the update just moved it, OR (review round 2) the row is
     * found to already have customer_id = $to_id — i.e. an earlier, partial customer_unmerge()
     * call already moved it back, and this call is a retry. Without that second check, a
     * partial restore could never become a full one on retry: every already-restored row would
     * report affected_rows() = 0 forever (its customer_id no longer equals $from_id, since it
     * already moved), fully_restored() would never see restored-count == recorded-count again,
     * and customer_unmerge() would refuse to stamp unmerged_at even after the one row that
     * actually blocked the first attempt got fixed — a dead end, with the source customer
     * permanently hidden from lists (customer_scope_list() excludes it) while still owning
     * some of its own rows. A row that moved somewhere else entirely (customer_id is neither
     * $from_id nor $to_id — e.g. a second, unrelated merge) is still honestly left out.
     */
    function customer_merge_restore_rows($db, $table, $user_id, array $ids, $from_id, $to_id)
    {
        $restored = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            $db->where('id', $id)->where('customer_id', $from_id)->where('user_id', $user_id)
                ->update($table, array('customer_id' => $to_id));
            if ((int) $db->affected_rows() > 0) {
                $restored[] = $id;
                continue;
            }
            // Not moved by this call — check whether it was already restored by an earlier,
            // partial attempt (already sitting on $to_id), rather than assuming it wasn't.
            $row = $db->select('customer_id')->from($table)
                ->where('id', $id)->where('user_id', $user_id)->get()->row_array();
            if (!empty($row) && (int) $row['customer_id'] === (int) $to_id) $restored[] = $id;
        }
        return $restored;
    }
}

if (!function_exists('customer_merge')) {
    /**
     * Moves every customer_identities, conversations, crm_deals and sequence_enrollments row
     * from $merge_id to $keep_id for tenant $user_id, in one transaction, then marks $merge_id
     * retired (customers.merged_into_customer_id) and writes one customer_merge_log row
     * recording the exact ids moved so customer_unmerge() can put them back. crm_deals/
     * sequence_enrollments are only touched when their customer_id column already exists
     * (customer_merge_child_table_ready()) — an older install simply moves nothing there.
     *
     * Both customers must already belong to $user_id (cross-tenant refused). Self-merge
     * refused. A customer already merged into something else (either side) refused — merge
     * chains are not supported, the owner re-picks a keep id instead. Both customer rows are
     * read with SELECT ... FOR UPDATE inside the transaction (customer_merge_lock_customer())
     * so a concurrent merge/unmerge touching either id cannot interleave with this one.
     *
     * Returns array(bool $ok, array $errors, array|null $result). $result on success:
     * array('log_id', 'keep_id', 'merge_id', 'identities_moved', 'conversations_moved',
     * 'deals_moved', 'enrollments_moved').
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

        $db->trans_start();

        $keep = customer_merge_lock_customer($db, $keep_id, $user_id);
        $merge = customer_merge_lock_customer($db, $merge_id, $user_id);

        if (empty($keep) || empty($merge)) {
            $db->trans_rollback();
            return array(false, array('Both customers must belong to this account.'), null);
        }
        if (!empty($keep['merged_into_customer_id'])) {
            $db->trans_rollback();
            return array(false, array('The customer you are keeping was already merged elsewhere. Pick its surviving record instead.'), null);
        }
        if (!empty($merge['merged_into_customer_id'])) {
            $db->trans_rollback();
            return array(false, array('That customer was already merged.'), null);
        }

        $identities_moved = customer_merge_move_identities($db, $user_id, $merge_id, $keep_id);
        $conversations_moved = customer_merge_move_rows($db, 'conversations', $user_id, $merge_id, $keep_id);

        $child_cache = array();
        $deals_moved = customer_merge_child_table_ready($db, 'crm_deals', $child_cache)
            ? customer_merge_move_rows($db, 'crm_deals', $user_id, $merge_id, $keep_id) : array();
        $enrollments_moved = customer_merge_child_table_ready($db, 'sequence_enrollments', $child_cache)
            ? customer_merge_move_rows($db, 'sequence_enrollments', $user_id, $merge_id, $keep_id) : array();

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
            'deals_moved'         => json_encode($deals_moved),
            'enrollments_moved'   => json_encode($enrollments_moved),
            'note'                => null,
            'created_at'          => date('Y-m-d H:i:s'),
        ));
        $log_id = (int) $db->insert_id();

        $db->trans_complete();
        if ($db->trans_status() === false) {
            return array(false, array('Database error while merging. Nothing was changed.'), null);
        }

        $result = array(
            'log_id'              => $log_id,
            'keep_id'             => $keep_id,
            'merge_id'            => $merge_id,
            'identities_moved'    => $identities_moved,
            'conversations_moved' => $conversations_moved,
            'deals_moved'         => $deals_moved,
            'enrollments_moved'   => $enrollments_moved,
        );

        if (function_exists('audit_record')) {
            audit_record(
                $user_id, 'customer.merge', 'customer', (string) $merge_id,
                array('merge_id' => $merge_id), $result, $actor_id, 'user'
            );
        }

        return array(true, array(), $result);
    }
}

if (!function_exists('customer_unmerge')) {
    /**
     * Reverses exactly one customer_merge_log row: moves the identities, conversations, deals
     * and sequence enrollments it recorded back to the retired (source) customer. Only claims
     * success — clears customers.merged_into_customer_id and stamps the log row unmerged_at —
     * once every recorded id in every category actually moved back (confirmed via
     * affected_rows(), see customer_merge_restore_rows()); a partial restore still commits
     * whatever it could move (never discards real progress) but reports it honestly: ok=false,
     * a message describing what did and didn't restore, and a $result with 'fully_restored' =>
     * false so a caller (or a retry) can tell the difference from a clean success.
     *
     * Retrying after a partial restore converges (review round 2): customer_merge_restore_rows()
     * treats a row already sitting on the source customer as restored, not just one it moves in
     * this call. Without that, a row this call DID restore would look, on the next attempt, like
     * it "failed to move" (its customer_id no longer equals the target — it already moved), so
     * fully_restored could never become true again even once whatever blocked the one still-
     * stuck row gets fixed — a dead end, with the source customer permanently hidden from every
     * list (customer_scope_list() excludes merged-away customers) while it still owned some of
     * its own rows. The log row itself is now read with SELECT ... FOR UPDATE
     * (customer_merge_lock_log()) so two concurrent unmerge attempts on the same log row cannot
     * interleave.
     *
     * $log_id must belong to tenant $user_id. The source and target customer rows are also
     * locked (SELECT ... FOR UPDATE) inside the transaction; if the target has itself since
     * been merged into a third customer, every row this log recorded already moved again when
     * that later merge happened — undoing from here would be meaningless, so this refuses
     * outright with no state change at all (not even a partial attempt).
     *
     * Returns array(bool $ok, array $errors, array|null $result). $result (present whenever a
     * restore was attempted, success or partial): array('source_customer_id',
     * 'target_customer_id', 'identities_restored', 'conversations_restored', 'deals_restored',
     * 'enrollments_restored', 'fully_restored').
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

        $db->trans_start();

        // Row-locked read (review round 2): a concurrent unmerge retry on the same log row —
        // e.g. two admins clicking Undo at once — must not interleave with this one.
        $log = customer_merge_lock_log($db, $log_id, $user_id);
        if (empty($log)) {
            $db->trans_rollback();
            return array(false, array('Merge record not found for this account.'), null);
        }
        if (!empty($log['unmerged_at'])) {
            $db->trans_rollback();
            return array(false, array('This merge was already undone.'), null);
        }

        $source_id = (int) $log['source_customer_id'];
        $target_id = (int) $log['target_customer_id'];

        $source = customer_merge_lock_customer($db, $source_id, $user_id);
        $target = customer_merge_lock_customer($db, $target_id, $user_id);
        if (empty($source) || empty($target)) {
            $db->trans_rollback();
            return array(false, array('One of the merged customers no longer belongs to this account.'), null);
        }
        if (!empty($target['merged_into_customer_id'])) {
            $db->trans_rollback();
            return array(false, array('The customer that received this merge has itself since been merged elsewhere. Undo that merge first.'), null);
        }

        $identity_ids = customer_merge_decode_ids($log['identities_moved']);
        $conversation_ids = customer_merge_decode_ids($log['conversations_moved']);
        $deal_ids = customer_merge_decode_ids($log['deals_moved']);
        $enrollment_ids = customer_merge_decode_ids($log['enrollments_moved']);

        $child_cache = array();
        $identities_restored = customer_merge_restore_rows($db, 'customer_identities', $user_id, $identity_ids, $target_id, $source_id);
        $conversations_restored = customer_merge_restore_rows($db, 'conversations', $user_id, $conversation_ids, $target_id, $source_id);
        $deals_restored = (!empty($deal_ids) && customer_merge_child_table_ready($db, 'crm_deals', $child_cache))
            ? customer_merge_restore_rows($db, 'crm_deals', $user_id, $deal_ids, $target_id, $source_id) : array();
        $enrollments_restored = (!empty($enrollment_ids) && customer_merge_child_table_ready($db, 'sequence_enrollments', $child_cache))
            ? customer_merge_restore_rows($db, 'sequence_enrollments', $user_id, $enrollment_ids, $target_id, $source_id) : array();

        $fully_restored =
            count($identities_restored) === count($identity_ids)
            && count($conversations_restored) === count($conversation_ids)
            && count($deals_restored) === count($deal_ids)
            && count($enrollments_restored) === count($enrollment_ids);

        if ($fully_restored) {
            $db->where('id', $source_id)->where('user_id', $user_id)
                ->update('customers', array('merged_into_customer_id' => null, 'updated_at' => date('Y-m-d H:i:s')));
            $db->where('id', $log_id)->where('user_id', $user_id)
                ->update('customer_merge_log', array('unmerged_at' => date('Y-m-d H:i:s')));
        }

        $db->trans_complete();
        if ($db->trans_status() === false) {
            return array(false, array('Database error while undoing the merge. Nothing was changed.'), null);
        }

        $result = array(
            'source_customer_id'     => $source_id,
            'target_customer_id'     => $target_id,
            'identities_restored'    => $identities_restored,
            'conversations_restored' => $conversations_restored,
            'deals_restored'         => $deals_restored,
            'enrollments_restored'   => $enrollments_restored,
            'fully_restored'         => $fully_restored,
        );

        if (function_exists('audit_record')) {
            audit_record(
                $user_id, 'customer.unmerge', 'customer', (string) $source_id,
                array('log_id' => $log_id), $result, $actor_id, 'user'
            );
        }

        if (!$fully_restored) {
            return array(false, array(
                'Only part of this merge could be restored — the rest may have moved again since. ' .
                'Restored: ' . count($identities_restored) . '/' . count($identity_ids) . ' identities, ' .
                count($conversations_restored) . '/' . count($conversation_ids) . ' conversations, ' .
                count($deals_restored) . '/' . count($deal_ids) . ' deals, ' .
                count($enrollments_restored) . '/' . count($enrollment_ids) . ' sequence enrollments.',
            ), $result);
        }

        return array(true, array(), $result);
    }
}

if (!function_exists('customer_active_merge_as_source')) {
    /** For the "Merged into #X" banner + Undo button on a merged-away customer's own profile:
     *  the still-active (not yet unmerged) log row where this customer was the source, if any.
     *  Null when this customer was never merged, or its merge was already undone. */
    function customer_active_merge_as_source($db, $user_id, $customer_id)
    {
        $cache = array();
        if (!customer_optional_table_ready($db, 'customer_merge_log', $cache)) return null;
        $row = $db->select('id AS log_id, target_customer_id, created_at')
            ->from('customer_merge_log')
            ->where('source_customer_id', (int) $customer_id)->where('user_id', (int) $user_id)
            ->where('unmerged_at IS NULL', null, false)
            ->order_by('id', 'DESC')->limit(1)
            ->get()->row_array();
        return $row ?: null;
    }
}

if (!function_exists('customer_recent_merges_into')) {
    /** For the Undo list on the surviving (keep) customer's profile: every still-active merge
     *  that landed on this customer, most recent first, with the retired customer's name so the
     *  owner knows what they're undoing. */
    function customer_recent_merges_into($db, $user_id, $customer_id, $limit = 10)
    {
        $cache = array();
        if (!customer_optional_table_ready($db, 'customer_merge_log', $cache)) return array();
        return $db->select('l.id AS log_id, l.source_customer_id AS source_customer_id, l.created_at AS created_at, ' .
                'c.display_name AS source_display_name, c.phone AS source_phone, c.email AS source_email')
            ->from('customer_merge_log l')
            ->join('customers c', 'c.id = l.source_customer_id')
            ->where('l.user_id', (int) $user_id)->where('l.target_customer_id', (int) $customer_id)
            ->where('l.unmerged_at IS NULL', null, false)
            ->order_by('l.created_at', 'DESC')->limit((int) $limit)
            ->get()->result_array();
    }
}

if (!function_exists('customer_merge_suggestion_rows')) {
    /** Raw rows for the tenant-wide suggestion query (Customers::suggestions()): every verified
     *  identity for this tenant's not-already-merged customers, joined to the customer's
     *  display name. One query; the grouping/pairing logic lives in
     *  customer_merge_suggestions_from_rows() so it is testable without a DB. */
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

if (!function_exists('customer_merge_suggestion_rows_for_customer')) {
    /**
     * Suggestion rows scoped to ONE customer (the profile page), computed directly rather than
     * fetching every tenant suggestion and filtering (review round 1): first this customer's
     * own verified identities, then — per (platform, external_id) — any OTHER customer of the
     * same tenant with a verified identity on that exact value. Only verified identities ever
     * match (ci.verified = 1); nothing in this codebase sets that column yet, so this returns
     * array() until something does.
     */
    function customer_merge_suggestion_rows_for_customer($db, $user_id, $customer_id)
    {
        $customer_id = (int) $customer_id;
        $user_id = (int) $user_id;

        $self = $db->select('ci.platform AS platform, ci.external_id AS external_id, c.display_name AS display_name')
            ->from('customer_identities ci')
            ->join('customers c', 'c.id = ci.customer_id')
            ->where('ci.customer_id', $customer_id)->where('ci.user_id', $user_id)->where('c.user_id', $user_id)
            ->where('ci.verified', 1)
            ->get()->result_array();
        if (empty($self)) return array();

        $rows = array();
        foreach ($self as $s) {
            $rows[] = array('customer_id' => $customer_id, 'platform' => $s['platform'], 'external_id' => $s['external_id'], 'display_name' => $s['display_name']);

            $others = $db->select('ci.customer_id AS customer_id, ci.platform AS platform, ci.external_id AS external_id, c.display_name AS display_name')
                ->from('customer_identities ci')
                ->join('customers c', 'c.id = ci.customer_id')
                ->where('ci.user_id', $user_id)->where('c.user_id', $user_id)
                ->where('ci.verified', 1)
                ->where('c.merged_into_customer_id IS NULL', null, false)
                ->where('ci.platform', $s['platform'])
                ->where('ci.external_id', $s['external_id'])
                ->where('ci.customer_id !=', $customer_id)
                ->get()->result_array();
            $rows = array_merge($rows, $others);
        }
        return $rows;
    }
}

if (!function_exists('customer_merge_suggestions_from_rows')) {
    /**
     * Groups verified-identity rows by (platform, external_id) — the same phone/email string
     * on two DIFFERENT platforms is not treated as a match; verification only vouches for the
     * value on the channel it was verified on — and turns every group spanning 2+ distinct
     * customers into a suggestion pair. Never merges anything: this is display-only data for
     * the "Merge" button on the profile page.
     *
     * Pure function, no DB — the queries live in customer_merge_suggestion_rows()/
     * customer_merge_suggestion_rows_for_customer() above so this is unit-testable with plain
     * arrays.
     */
    function customer_merge_suggestions_from_rows(array $rows, $limit = 20)
    {
        $groups = array();
        foreach ($rows as $r) {
            $value = isset($r['external_id']) ? (string) $r['external_id'] : '';
            $platform = isset($r['platform']) ? (string) $r['platform'] : '';
            if ($value === '' || $platform === '') continue;
            $cid = (int) ($r['customer_id'] ?? 0);
            if ($cid <= 0) continue;
            $group_key = $platform . '|' . $value;
            if (!isset($groups[$group_key])) $groups[$group_key] = array('platform' => $platform, 'value' => $value, 'by_customer' => array());
            $groups[$group_key]['by_customer'][$cid] = $r; // de-dupe: same customer verified twice on the same value counts once
        }

        $suggestions = array();
        $seen_pairs = array();
        foreach ($groups as $group) {
            $by_customer = $group['by_customer'];
            if (count($by_customer) < 2) continue;
            $customer_ids = array_keys($by_customer);
            sort($customer_ids);
            for ($i = 0; $i < count($customer_ids); $i++) {
                for ($j = $i + 1; $j < count($customer_ids); $j++) {
                    $a = $customer_ids[$i];
                    $b = $customer_ids[$j];
                    $pair_key = $a . ':' . $b;
                    if (isset($seen_pairs[$pair_key])) continue; // same pair already suggested on an earlier (platform, value) match
                    $seen_pairs[$pair_key] = true;
                    $suggestions[] = array(
                        'customer_id_a'    => $a,
                        'customer_id_b'    => $b,
                        'matched_value'    => $group['value'],
                        'matched_platform' => $group['platform'],
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
    /** Tenant-wide suggestions (Customers::suggestions()): query + group in one step, still
     *  tenant-scoped through customer_merge_suggestion_rows(). Verified identities only — see
     *  the "merge" section header comment above. */
    function customer_merge_suggestions($db, $user_id, $limit = 20)
    {
        return customer_merge_suggestions_from_rows(customer_merge_suggestion_rows($db, $user_id), $limit);
    }
}

if (!function_exists('customer_merge_suggestions_for_customer')) {
    /** Profile-page suggestions for ONE customer (review round 1: computed directly, not
     *  tenant-wide-then-filtered). Every returned pair includes $customer_id, since only its
     *  own verified identities seed the query. Verified identities only. */
    function customer_merge_suggestions_for_customer($db, $user_id, $customer_id, $limit = 20)
    {
        return customer_merge_suggestions_from_rows(customer_merge_suggestion_rows_for_customer($db, $user_id, $customer_id), $limit);
    }
}
