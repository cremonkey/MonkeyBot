<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-19: turn a campaign's filters into a concrete recipient list.
 *
 * Two entry points over the same query:
 *   preview()  -> bucket counts, writes nothing  (the "reach counter" in the UI)
 *   build()    -> materialises reengage_recipient rows
 *
 * They share one code path deliberately. If the preview counted rows a
 * different way than the builder selected them, the number the operator
 * approves would not be the number that gets messaged.
 *
 * Exclusions are applied here AND re-checked at send time. This layer can be
 * hours stale by the time the cron reaches a row.
 */
class Reengage_audience
{
    const CHUNK = 2000;

    /** Exclusion reasons, in the order they are tested. */
    const EX_OPTED_OUT   = 'opted_out';
    const EX_UNSUBSCRIBED = 'unsubscribed';
    const EX_UNAVAILABLE = 'unavailable';
    const EX_HUMAN_HANDOFF = 'human_handoff';

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->helper('reengage');
    }

    /**
     * Bucket counts for a filter set. Sends nothing, writes nothing.
     *
     * @return array {
     *   matched, in_window, human_agent, out_of_window, excluded,
     *   excluded_breakdown: {opted_out, unsubscribed, unavailable, human_handoff}
     * }
     */
    public function preview($user_id, $filters)
    {
        $counts = array(
            'matched' => 0,
            'in_window' => 0,
            'human_agent' => 0,
            'out_of_window' => 0,
            'excluded' => 0,
            'excluded_breakdown' => array(
                self::EX_OPTED_OUT => 0,
                self::EX_UNSUBSCRIBED => 0,
                self::EX_UNAVAILABLE => 0,
                self::EX_HUMAN_HANDOFF => 0,
            ),
        );

        $now = time();
        $offset = 0;

        while (true) {
            $rows = $this->fetch_chunk($user_id, $filters, $offset, self::CHUNK);
            if (empty($rows)) break;

            foreach ($rows as $row) {
                $counts['matched']++;

                $excluded = $this->exclusion_reason($row, $now);
                if ($excluded !== '') {
                    $counts['excluded']++;
                    $counts['excluded_breakdown'][$excluded]++;
                    continue;
                }

                $bucket = reengage_classify($row['last_subscriber_interaction_time'], $now);
                $counts[$bucket]++;
            }

            if (count($rows) < self::CHUNK) break;
            $offset += self::CHUNK;
        }

        return $counts;
    }

    /**
     * Materialise the recipient list for a campaign.
     *
     * Rows land in a state that reflects what will actually happen to them:
     *   in_window     -> pending          (the cron will send)
     *   human_agent   -> skipped          (operator answers by hand; never automated)
     *   out_of_window -> waiting_reentry  (sends if they message us again)
     *   excluded      -> skipped          (kept as an audit trail, not deleted)
     *
     * Safe to re-run: the unique key (campaign_id, subscriber_auto_id) means an
     * existing row is left alone rather than duplicated.
     *
     * @return array same shape as preview(), plus 'inserted'
     */
    public function build($campaign)
    {
        $user_id = (int) $campaign['user_id'];
        $campaign_id = (int) $campaign['id'];
        $filters = $this->decode_filters($campaign['filters_json']);

        $has_variant_b = isset($campaign['variant_b_json']) && trim((string) $campaign['variant_b_json']) !== '';
        $ttl_days = (int) $campaign['queue_ttl_days'];
        if ($ttl_days < 1) $ttl_days = 30;

        $now = time();
        $now_sql = date('Y-m-d H:i:s', $now);
        $expires_sql = date('Y-m-d H:i:s', $now + ($ttl_days * 86400));

        $counts = array(
            'matched' => 0, 'in_window' => 0, 'human_agent' => 0,
            'out_of_window' => 0, 'excluded' => 0, 'inserted' => 0,
            'excluded_breakdown' => array(
                self::EX_OPTED_OUT => 0, self::EX_UNSUBSCRIBED => 0,
                self::EX_UNAVAILABLE => 0, self::EX_HUMAN_HANDOFF => 0,
            ),
        );

        $existing = $this->existing_subscriber_ids($campaign_id);
        $offset = 0;

        while (true) {
            $rows = $this->fetch_chunk($user_id, $filters, $offset, self::CHUNK);
            if (empty($rows)) break;

            $batch = array();

            foreach ($rows as $row) {
                $counts['matched']++;

                $sub_id = (int) $row['id'];
                if (isset($existing[$sub_id])) continue;

                $base = array(
                    'campaign_id' => $campaign_id,
                    'user_id' => $user_id,
                    'subscriber_auto_id' => $sub_id,
                    'subscribe_id' => $row['subscribe_id'],
                    'page_table_id' => (int) $row['page_table_id'],
                    'queued_at' => $now_sql,
                    'expires_at' => $expires_sql,
                    'ab_variant' => $has_variant_b ? (mt_rand(0, 1) ? 'A' : 'B') : 'A',
                );

                $excluded = $this->exclusion_reason($row, $now);
                if ($excluded !== '') {
                    $counts['excluded']++;
                    $counts['excluded_breakdown'][$excluded]++;
                    $batch[] = array_merge($base, array(
                        'eligibility' => REENGAGE_OUT_OF_WINDOW,
                        'state' => 'skipped',
                        'skip_reason' => $excluded,
                    ));
                    continue;
                }

                $bucket = reengage_classify($row['last_subscriber_interaction_time'], $now);
                $counts[$bucket]++;

                if ($bucket === REENGAGE_IN_WINDOW) {
                    $state = 'pending';
                    $reason = '';
                } elseif ($bucket === REENGAGE_HUMAN_AGENT) {
                    // Reachable only by a human typing in Livechat. The engine
                    // must never send under HUMAN_AGENT; that is a violation.
                    $state = 'skipped';
                    $reason = 'needs_human_reply';
                } else {
                    $state = 'waiting_reentry';
                    $reason = '';
                }

                $batch[] = array_merge($base, array(
                    'eligibility' => $bucket,
                    'state' => $state,
                    'skip_reason' => $reason,
                ));
            }

            if (!empty($batch)) {
                $this->ci->db->insert_batch('reengage_recipient', $batch);
                $counts['inserted'] += count($batch);
            }

            if (count($rows) < self::CHUNK) break;
            $offset += self::CHUNK;
        }

        return $counts;
    }

    /**
     * Which mandatory exclusion, if any, applies. Order matters only for the
     * breakdown label; any one of them is disqualifying.
     *
     * @return string '' when the contact is targetable
     */
    private function exclusion_reason($row, $now)
    {
        if (!empty($row['opted_out'])) return self::EX_OPTED_OUT;
        if ($row['status'] !== '1') return self::EX_UNSUBSCRIBED;
        if ($row['unavailable'] === '1') return self::EX_UNAVAILABLE;

        // A human agent is mid-conversation with this customer. A broadcast
        // landing on top of that is worse than not sending at all.
        $paused = reengage_to_timestamp($row['bot_paused_until']);
        if ($paused !== null && $paused > $now) return self::EX_HUMAN_HANDOFF;

        return '';
    }

    /** Subscriber ids already on this campaign, so build() is re-runnable. */
    private function existing_subscriber_ids($campaign_id)
    {
        $out = array();
        $this->ci->db->select('subscriber_auto_id');
        $this->ci->db->where('campaign_id', (int) $campaign_id);
        $query = $this->ci->db->get('reengage_recipient');
        if ($query) {
            foreach ($query->result_array() as $r) $out[(int) $r['subscriber_auto_id']] = true;
        }
        return $out;
    }

    /**
     * One chunk of filter-matching subscribers, with an opted_out flag joined in.
     * Ordered by id so the offset walk is stable.
     */
    private function fetch_chunk($user_id, $filters, $offset, $limit)
    {
        $user_id = (int) $user_id;
        $db = $this->ci->db;

        $db->select('s.id, s.subscribe_id, s.page_table_id, s.status, s.unavailable,
                     s.bot_paused_until, s.last_subscriber_interaction_time,
                     (o.id IS NOT NULL) AS opted_out', false);
        $db->from('messenger_bot_subscriber s');
        $db->join('reengage_optout o', 'o.user_id = s.user_id AND o.subscribe_id = s.subscribe_id', 'left');
        $db->where('s.user_id', $user_id);
        $db->where('s.subscriber_type', 'messenger');

        if (!empty($filters['social_media'])) {
            $db->where('s.social_media', $filters['social_media']);
        }
        if (!empty($filters['page_table_id'])) {
            $db->where('s.page_table_id', (int) $filters['page_table_id']);
        }

        // Recency, measured on the customer's last inbound message.
        // quiet_for_days: silent at least this long. active_within_days: spoke recently.
        if (!empty($filters['quiet_for_days'])) {
            $cut = date('Y-m-d H:i:s', time() - ((int) $filters['quiet_for_days'] * 86400));
            $db->where('s.last_subscriber_interaction_time <=', $cut);
        }
        if (!empty($filters['active_within_days'])) {
            $cut = date('Y-m-d H:i:s', time() - ((int) $filters['active_within_days'] * 86400));
            $db->where('s.last_subscriber_interaction_time >=', $cut);
        }

        if (isset($filters['lead_score_min']) && $filters['lead_score_min'] !== '') {
            $db->where('s.lead_score >=', (int) $filters['lead_score_min']);
        }
        if (isset($filters['lead_score_max']) && $filters['lead_score_max'] !== '') {
            $db->where('s.lead_score <=', (int) $filters['lead_score_max']);
        }

        $this->apply_crm_filter($db, $filters);
        $this->apply_label_filters($db, $filters);

        $db->order_by('s.id', 'ASC');
        $db->limit((int) $limit, (int) $offset);

        $query = $db->get();
        if (!$query) return array(); // db_debug is off; a bad query returns false

        return $query->result_array();
    }

    private function apply_crm_filter($db, $filters)
    {
        $mode = isset($filters['crm_mode']) ? $filters['crm_mode'] : 'any';

        if ($mode === 'has_open_deal') {
            $db->where('EXISTS (SELECT 1 FROM crm_deals d WHERE d.subscriber_id = s.id AND d.status = "open")', null, false);
        } elseif ($mode === 'no_deal') {
            $db->where('NOT EXISTS (SELECT 1 FROM crm_deals d WHERE d.subscriber_id = s.id)', null, false);
        } elseif ($mode === 'stage' && !empty($filters['crm_stage_ids'])) {
            $ids = $this->int_list($filters['crm_stage_ids']);
            if ($ids !== '') {
                $db->where('EXISTS (SELECT 1 FROM crm_deals d WHERE d.subscriber_id = s.id AND d.status = "open" AND d.stage_id IN (' . $ids . '))', null, false);
            }
        }
    }

    private function apply_label_filters($db, $filters)
    {
        if (!empty($filters['label_ids'])) {
            $ids = $this->int_list($filters['label_ids']);
            if ($ids !== '') {
                $db->where('EXISTS (SELECT 1 FROM messenger_bot_subscribers_label l WHERE l.subscriber_table_id = s.id AND l.contact_group_id IN (' . $ids . '))', null, false);
            }
        }

        if (!empty($filters['excluded_label_ids'])) {
            $ids = $this->int_list($filters['excluded_label_ids']);
            if ($ids !== '') {
                $db->where('NOT EXISTS (SELECT 1 FROM messenger_bot_subscribers_label l WHERE l.subscriber_table_id = s.id AND l.contact_group_id IN (' . $ids . '))', null, false);
            }
        }
    }

    /**
     * Cast to a comma-joined list of ints. These go into raw SQL fragments, so
     * nothing but digits may survive.
     */
    private function int_list($values)
    {
        if (!is_array($values)) $values = explode(',', (string) $values);

        $clean = array();
        foreach ($values as $v) {
            $v = (int) trim((string) $v);
            if ($v > 0) $clean[] = $v;
        }

        return implode(',', $clean);
    }

    public function decode_filters($json)
    {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : array();
    }
}
