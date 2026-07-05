<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-12 — CRM helpers: default pipeline seeding + idempotent auto-deal creation.
 | All functions are safe to call from hot paths (try/catch, silent).
 */

if (!function_exists('crm_ensure_pipeline')) {
    function crm_ensure_pipeline($user_id)
    {
        $CI =& get_instance();
        $p = $CI->db->from('crm_pipelines')->where('user_id', $user_id)->order_by('id','ASC')->limit(1)->get()->row_array();
        if (!empty($p)) return $p['id'];
        $CI->db->insert('crm_pipelines', array('user_id'=>$user_id,'name'=>'Sales Pipeline','is_default'=>'1','status'=>'1','created_at'=>date('Y-m-d H:i:s')));
        $pid = $CI->db->insert_id();
        $stages = array(
            array('New Lead','#6777ef','open',1), array('Interested','#3abaf4','open',2),
            array('Negotiation','#ffa426','open',3), array('Abandoned Cart','#fc544b','open',4),
            array('Won','#63ed7a','won',5), array('Lost','#cdd3d8','lost',6),
        );
        foreach ($stages as $s) $CI->db->insert('crm_stages', array('pipeline_id'=>$pid,'name'=>$s[0],'color'=>$s[1],'stage_type'=>$s[2],'position'=>$s[3]));
        return $pid;
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
     * @param string $trigger 'purchase'|'abandoned_cart'|'new_lead'
     */
    function crm_auto_deal($user_id, $subscriber_row_id, $trigger, $data = array())
    {
        try {
            $CI =& get_instance();
            $user_id = (int)$user_id;
            if ($user_id <= 0) return;
            $pid = crm_ensure_pipeline($user_id);
            $stage_name = $trigger === 'purchase' ? 'Won' : ($trigger === 'abandoned_cart' ? 'Abandoned Cart' : 'New Lead');
            $stage_id = crm_stage_id($pid, $stage_name);
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
            $CI->db->insert('crm_deals', array(
                'user_id'=>$user_id,'pipeline_id'=>$pid,'stage_id'=>$stage_id,
                'title'=>$data['title'] ?? ($stage_name.' deal'),
                'value'=>$data['value'] ?? 0,'currency'=>$data['currency'] ?? 'USD',
                'subscriber_id'=>$subscriber_row_id ? (int)$subscriber_row_id : null,
                'contact_name'=>$data['contact_name'] ?? '','contact_email'=>$data['contact_email'] ?? '','contact_phone'=>$data['contact_phone'] ?? '',
                'source'=>$data['source'] ?? 'manual','ecommerce_cart_id'=>$data['cart_id'] ?? null,
                'status'=>$trigger === 'purchase' ? 'won' : 'open','won_at'=>$trigger === 'purchase' ? $now : null,
                'created_at'=>$now,'updated_at'=>$now,
            ));
            $did = $CI->db->insert_id();
            $CI->db->insert('crm_deal_timeline', array('deal_id'=>$did,'user_id'=>$user_id,'action'=>'created ('.$trigger.')','created_at'=>$now));
        } catch (Exception $e) { log_message('error','crm_auto_deal: '.$e->getMessage()); }
    }
}
