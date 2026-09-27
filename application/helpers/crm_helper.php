<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-12 — CRM helpers: default pipeline seeding + idempotent auto-deal creation.
 | All functions are safe to call from hot paths (try/catch, silent).
 |
 | Task 18 (C3, brief §27) additions: the default stage set seeded for a BRAND NEW pipeline
 | changed from New Lead/Interested/Negotiation/Abandoned Cart/Won/Lost to
 | New/Qualified/Follow-up/Proposal/Won/Lost. crm_ensure_pipeline() only ever inserts stages
 | when the user has NO pipeline row yet (the `if (!empty($p)) return` above), so this change
 | is inert for every existing pipeline/stage/deal — they already have their own row and this
 | function returns before touching stages. crm_auto_deal()'s name lookups (crm_stage_id) fall
 | through to the position-based "first stage" fallback exactly as before when a name doesn't
 | match, so it keeps working unchanged against either stage set.
 */

if (!function_exists('crm_ensure_pipeline')) {
    function crm_ensure_pipeline($user_id)
    {
        $CI =& get_instance();
        $p = $CI->db->from('crm_pipelines')->where('user_id', $user_id)->order_by('id','ASC')->limit(1)->get()->row_array();
        if (!empty($p)) return $p['id'];
        $CI->db->insert('crm_pipelines', array('user_id'=>$user_id,'name'=>'Sales Pipeline','is_default'=>'1','status'=>'1','created_at'=>date('Y-m-d H:i:s')));
        $pid = $CI->db->insert_id();
        crm_seed_default_stages($pid);
        return $pid;
    }
}

if (!function_exists('crm_seed_default_stages')) {
    /**
     * The default stage set for a brand-new pipeline (Task 18 / brief §27). Only ever called
     * right after a pipeline INSERT (crm_ensure_pipeline, Crm::pipeline_create if added later),
     * never against an existing pipeline_id, so it can never touch an existing pipeline's stages.
     */
    function crm_seed_default_stages($pipeline_id)
    {
        $CI =& get_instance();
        $stages = array(
            array('New','#6777ef','open',1), array('Qualified','#3abaf4','open',2),
            array('Follow-up','#ffa426','open',3), array('Proposal','#8950fc','open',4),
            array('Won','#63ed7a','won',5), array('Lost','#cdd3d8','lost',6),
        );
        foreach ($stages as $s) $CI->db->insert('crm_stages', array('pipeline_id'=>$pipeline_id,'name'=>$s[0],'color'=>$s[1],'stage_type'=>$s[2],'position'=>$s[3]));
    }
}

if (!function_exists('crm_stage_id')) {
    function crm_stage_id($pipeline_id, $name)
    {
        $CI =& get_instance();
        $s = $CI->db->from('crm_stages')->where('pipeline_id',$pipeline_id)->where('name',$name)->limit(1)->get()->row_array();
        return empty($s) ? null : $s['id'];
    }
}

if (!function_exists('crm_auto_deal')) {
    /**
     * Idempotent: won't duplicate an open deal for the same subscriber+trigger.
     * @param string $trigger 'purchase'(=Won) | 'abandoned_cart' | 'checkout'(=Negotiation, open) | 'new_lead'
     */
    function crm_auto_deal($user_id, $subscriber_row_id, $trigger, $data = array())
    {
        try {
            $CI =& get_instance();
            $user_id = (int)$user_id;
            if ($user_id <= 0) return;
            $pid = crm_ensure_pipeline($user_id);
            $stage_name = $trigger === 'purchase' ? 'Won' : ($trigger === 'abandoned_cart' ? 'Abandoned Cart' : ($trigger === 'checkout' ? 'Negotiation' : 'New Lead'));
            $stage_id = crm_stage_id($pid, $stage_name);
            if (!$stage_id) $stage_id = crm_stage_id($pid, 'New Lead'); // fallback if stage renamed/removed
            if (!$stage_id) { $first = $CI->db->from('crm_stages')->where('pipeline_id',$pid)->order_by('position','ASC')->limit(1)->get()->row_array(); $stage_id = $first['id'] ?? null; }
            if (!$stage_id) return;

            // idempotency: existing non-lost deal for this subscriber
            if ($subscriber_row_id) {
                $existing = $CI->db->from('crm_deals')->where('user_id',$user_id)->where('subscriber_id',(int)$subscriber_row_id)
                    ->where_in('status', array('open','won'))->limit(1)->get()->row_array();
                if (!empty($existing)) {
                    if ($trigger === 'purchase' && $existing['status'] !== 'won') {
                        $CI->db->where('id',$existing['id'])->update('crm_deals', array('stage_id'=>$stage_id,'status'=>'won','won_at'=>date('Y-m-d H:i:s'),'value'=>$data['value'] ?? $existing['value'],'updated_at'=>date('Y-m-d H:i:s')));
                        $CI->db->insert('crm_deal_timeline', array('deal_id'=>$existing['id'],'user_id'=>$user_id,'action'=>'won','created_at'=>date('Y-m-d H:i:s')));
                    }
                    return;
                }
            }

            $now = date('Y-m-d H:i:s');
            $insert = array(
                'user_id'=>$user_id,'pipeline_id'=>$pid,'stage_id'=>$stage_id,
                'title'=>$data['title'] ?? ($stage_name.' deal'),
                'value'=>$data['value'] ?? 0,'currency'=>$data['currency'] ?? 'USD',
                'subscriber_id'=>$subscriber_row_id ? (int)$subscriber_row_id : null,
                'contact_name'=>$data['contact_name'] ?? '','contact_email'=>$data['contact_email'] ?? '','contact_phone'=>$data['contact_phone'] ?? '',
                'source'=>$data['source'] ?? 'manual','ecommerce_cart_id'=>$data['cart_id'] ?? null,
                'status'=>$trigger === 'purchase' ? 'won' : 'open','won_at'=>$trigger === 'purchase' ? $now : null,
                'created_at'=>$now,'updated_at'=>$now,
            );
            // Task 18 (C3, brief §27): fill customer_id/conversation_id where resolvable. Only
            // once the migration has run (crm_deals_conversational_ready(), cached, mirrors
            // Inbox.php's media_column_ready() pattern) — before that the columns don't exist
            // and the code must still work, so they're simply omitted from the INSERT.
            if ($subscriber_row_id && crm_deals_conversational_ready()) {
                $sub_row = $CI->db->select('subscribe_id')->from('messenger_bot_subscriber')->where('id', (int) $subscriber_row_id)->get()->row_array();
                if (!empty($sub_row['subscribe_id'])) {
                    list($cust_id, $conv_id) = crm_resolve_conversation_for_subscriber($user_id, $sub_row['subscribe_id']);
                    if ($cust_id) $insert['customer_id'] = $cust_id;
                    if ($conv_id) $insert['conversation_id'] = $conv_id;
                }
            }
            $CI->db->insert('crm_deals', $insert);
            $did = $CI->db->insert_id();
            $CI->db->insert('crm_deal_timeline', array('deal_id'=>$did,'user_id'=>$user_id,'action'=>'created ('.$trigger.')','created_at'=>$now));

            // Event Engine (brief 22): a new deal IS the "lead.created" fact. Emitted only on
            // a real insert, so the idempotent early-returns above never produce a duplicate
            // event. Wrapped separately: a failure here must not roll back the deal.
            try {
                $CI->load->helper('event');
                event_emit($user_id, 'lead.created', 'deal', $did, array(
                    'deal_id'       => $did,
                    'subscriber_id' => $subscriber_row_id ? (int) $subscriber_row_id : null,
                    'stage'         => $stage_name,
                    'trigger'       => $trigger,
                    'value'         => $data['value'] ?? 0,
                    'currency'      => $data['currency'] ?? 'USD',
                    'source'        => $data['source'] ?? 'manual',
                    'title'         => $data['title'] ?? ($stage_name.' deal'),
                ));
            } catch (Throwable $et) { log_message('error','crm_auto_deal event_emit: '.$et->getMessage()); }
        } catch (Exception $e) { log_message('error','crm_auto_deal: '.$e->getMessage()); }
    }
}

/* ------------------------------------------------------------------------------------------
 | Stage editor (Task 18 / brief §27): add/rename/reorder/delete-empty-only. Every function
 | takes $user_id and re-derives ownership itself (pipeline.user_id = $user_id), so the
 | controller only needs csrf_token_check()+ajax before calling these — no separate IDOR check
 | is needed on top, but Crm.php still resolves via owns_pipeline()/owns_stage() for clarity
 | and so a cross-tenant call gets a clean false instead of a partial write.
 | ------------------------------------------------------------------------------------------ */

if (!function_exists('crm_owns_pipeline')) {
    function crm_owns_pipeline($user_id, $pipeline_id)
    {
        $CI =& get_instance();
        $p = $CI->db->from('crm_pipelines')->where('id', (int) $pipeline_id)->where('user_id', (int) $user_id)->get()->row_array();
        return $p ?: null;
    }
}

if (!function_exists('crm_owns_stage')) {
    function crm_owns_stage($user_id, $stage_id)
    {
        $CI =& get_instance();
        $s = $CI->db->select('crm_stages.*')->from('crm_stages')
            ->join('crm_pipelines', 'crm_pipelines.id = crm_stages.pipeline_id')
            ->where('crm_stages.id', (int) $stage_id)
            ->where('crm_pipelines.user_id', (int) $user_id)
            ->get()->row_array();
        return $s ?: null;
    }
}

if (!function_exists('crm_stage_add')) {
    /** @return int new stage id, or 0 on failure/ownership mismatch */
    function crm_stage_add($user_id, $pipeline_id, $name, $stage_type = 'open')
    {
        $CI =& get_instance();
        if (!crm_owns_pipeline($user_id, $pipeline_id)) return 0;
        $name = trim((string) $name);
        if ($name === '') return 0;
        if (!in_array($stage_type, array('open', 'won', 'lost'), true)) $stage_type = 'open';
        $max = $CI->db->select_max('position')->from('crm_stages')->where('pipeline_id', (int) $pipeline_id)->get()->row_array();
        $position = (int) ($max['position'] ?? 0) + 1;
        $CI->db->insert('crm_stages', array(
            'pipeline_id' => (int) $pipeline_id, 'name' => mb_substr($name, 0, 100),
            'color' => '#6777ef', 'stage_type' => $stage_type, 'position' => $position,
        ));
        return (int) $CI->db->insert_id();
    }
}

if (!function_exists('crm_stage_rename')) {
    /** @return bool */
    function crm_stage_rename($user_id, $stage_id, $new_name)
    {
        $CI =& get_instance();
        if (!crm_owns_stage($user_id, $stage_id)) return false;
        $new_name = trim((string) $new_name);
        if ($new_name === '') return false;
        $CI->db->where('id', (int) $stage_id)->update('crm_stages', array('name' => mb_substr($new_name, 0, 100)));
        return true;
    }
}

if (!function_exists('crm_stage_reorder')) {
    /**
     * @param array $ordered_stage_ids  stage ids in the new display order (all must belong to
     *                                  the same pipeline and to $user_id — any id that doesn't
     *                                  own to this tenant/pipeline aborts the whole reorder)
     * @return bool
     */
    function crm_stage_reorder($user_id, $pipeline_id, array $ordered_stage_ids)
    {
        $CI =& get_instance();
        if (!crm_owns_pipeline($user_id, $pipeline_id)) return false;
        if (empty($ordered_stage_ids)) return false;
        $ids = array_map('intval', $ordered_stage_ids);
        $owned = $CI->db->from('crm_stages')->where('pipeline_id', (int) $pipeline_id)->where_in('id', $ids)->get()->result_array();
        if (count($owned) !== count($ids)) return false; // some id isn't in this pipeline
        foreach ($ids as $i => $sid) {
            $CI->db->where('id', $sid)->where('pipeline_id', (int) $pipeline_id)->update('crm_stages', array('position' => $i + 1));
        }
        return true;
    }
}

if (!function_exists('crm_stage_delete')) {
    /**
     * Refuses to delete a stage that still has any deal in it (open/won/lost — a deal is a
     * deal), so a delete never orphans data. @return array [ok(bool), reason(string)]
     */
    function crm_stage_delete($user_id, $stage_id)
    {
        $CI =& get_instance();
        $stage = crm_owns_stage($user_id, $stage_id);
        if (!$stage) return array(false, 'not_found');
        $in_use = $CI->db->from('crm_deals')->where('stage_id', (int) $stage_id)->count_all_results();
        if ($in_use > 0) return array(false, 'not_empty');
        $CI->db->where('id', (int) $stage_id)->delete('crm_stages');
        return array(true, '');
    }
}

/* ------------------------------------------------------------------------------------------
 | Conversational linking + qualified-lead emission (Task 18 / brief §27).
 | ------------------------------------------------------------------------------------------ */

if (!function_exists('crm_deals_conversational_ready')) {
    /** crm_deals.customer_id/conversation_id/intent/next_action(_at) exist (migration 2026-10-04c). */
    function crm_deals_conversational_ready()
    {
        // Cached on the CI instance, not a bare function-static: PHP-FPM/Apache workers can
        // outlive a single request and serve several, and a global function's `static` would
        // then leak the FIRST request's answer to every later one on that worker. Inbox.php's
        // media_column_ready() is safe as a bare static because it's an instance METHOD (the
        // static lives on $this, one per request); this is a plain helper function, so the
        // cache is put on $CI (also one per request) instead.
        $CI =& get_instance();
        if (!isset($CI->_crm_deals_conversational_ready)) {
            $CI->_crm_deals_conversational_ready = $CI->db->field_exists('customer_id', 'crm_deals') && $CI->db->field_exists('conversation_id', 'crm_deals');
        }
        return $CI->_crm_deals_conversational_ready;
    }
}

if (!function_exists('crm_resolve_conversation_for_subscriber')) {
    /**
     * Best-effort: the most recent conversation linked to this subscriber's inbound messages
     * (livechat_messages.conversation_id, set by trg_livechat_messages_link_conversation).
     * @return array [customer_id|null, conversation_id|null]
     */
    function crm_resolve_conversation_for_subscriber($user_id, $subscribe_id)
    {
        try {
            if (empty($subscribe_id)) return array(null, null);
            $CI =& get_instance();
            $row = $CI->db->select('conversation_id')->from('livechat_messages')
                ->where('user_id', (int) $user_id)->where('subscriber_id', $subscribe_id)
                ->where('conversation_id IS NOT NULL')->order_by('id', 'DESC')->limit(1)->get()->row_array();
            if (empty($row['conversation_id'])) return array(null, null);
            $conv = $CI->db->select('id, customer_id')->from('conversations')->where('id', (int) $row['conversation_id'])
                ->where('user_id', (int) $user_id)->get()->row_array();
            if (empty($conv)) return array(null, null);
            return array($conv['customer_id'] ? (int) $conv['customer_id'] : null, (int) $conv['id']);
        } catch (Throwable $e) {
            log_message('error', 'crm_resolve_conversation_for_subscriber: ' . $e->getMessage());
            return array(null, null);
        }
    }
}

if (!function_exists('crm_qualified_threshold')) {
    /**
     * Read from the already-loaded main config (application/config/config.php is autoloaded;
     * we never add a new file under application/config/ — that directory is a production
     * Docker volume, see docs/plans/2026-09-24-remaining-work.md §1). Unset -> default 60.
     */
    function crm_qualified_threshold()
    {
        $CI =& get_instance();
        $v = $CI->config->item('lead_qualified_threshold');
        return $v !== null && $v !== '' ? (int) $v : 60;
    }
}

if (!function_exists('crm_maybe_emit_qualified')) {
    /**
     * lead.qualified (event catalogue, event_helper.php): emitted once PER DEAL when the
     * subscriber's lead_score crosses crm_qualified_threshold(). Guard: a crm_deal_timeline
     * row action='qualified' already exists for that deal — checked before insert, so a
     * concurrent double-call still can't double-emit within one process (best-effort; the
     * timeline row is the source of truth other CRM screens already read).
     */
    function crm_maybe_emit_qualified($user_id, $subscriber_row_id, $score)
    {
        try {
            $user_id = (int) $user_id;
            $subscriber_row_id = (int) $subscriber_row_id;
            if ($user_id <= 0 || $subscriber_row_id <= 0) return;
            if ((int) $score < crm_qualified_threshold()) return;

            $CI =& get_instance();
            $deals = $CI->db->select('id')->from('crm_deals')
                ->where('user_id', $user_id)->where('subscriber_id', $subscriber_row_id)
                ->where('status', 'open')->get()->result_array();
            if (empty($deals)) return;

            foreach ($deals as $d) {
                $deal_id = (int) $d['id'];
                $already = $CI->db->from('crm_deal_timeline')->where('deal_id', $deal_id)->where('action', 'qualified')->limit(1)->count_all_results();
                if ($already > 0) continue;

                $CI->db->insert('crm_deal_timeline', array('deal_id' => $deal_id, 'user_id' => $user_id, 'action' => 'qualified', 'new_value' => (string) (int) $score, 'created_at' => date('Y-m-d H:i:s')));

                try {
                    $CI->load->helper('event');
                    event_emit($user_id, 'lead.qualified', 'subscriber', $subscriber_row_id, array(
                        'deal_id' => $deal_id, 'lead_score' => (int) $score, 'band' => function_exists('lead_band') ? lead_band($score) : null,
                    ));
                } catch (Throwable $et) { log_message('error', 'crm_maybe_emit_qualified event_emit: ' . $et->getMessage()); }
            }
        } catch (Throwable $e) {
            log_message('error', 'crm_maybe_emit_qualified: ' . $e->getMessage());
        }
    }
}
