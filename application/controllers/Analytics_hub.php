<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-13 — Analytics Hub. Read-only dashboards over the logged-in account's data.
 */
class Analytics_hub extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $days = 30;
        $since = date('Y-m-d 00:00:00', strtotime("-".($days-1)." days"));

        $data['cards'] = $this->cards($since);
        $data['subs_series'] = $this->subs_series($since);
        $data['msg_series'] = $this->msg_series($since);
        $data['ai_series'] = $this->ai_series($since);
        $data['orders_series'] = $this->orders_series($since);
        $data['lead_bands'] = $this->lead_bands();
        $data['ai_cost'] = $this->ai_cost($since);
        $data['funnel'] = $this->funnel($since);
        $data['top_missed'] = $this->top_missed($since);
        $data['deflection'] = $this->deflection($since);
        $data['page_title'] = 'Analytics Hub';
        $data['body'] = 'admin/analytics_hub/index';
        $this->_viewcontroller($data);
    }

    /**
     * Sales funnel (30d): conversations -> leads -> won deals, per platform.
     */
    private function funnel($since)
    {
        $uid = $this->uid;
        $conv = $this->db->query(
            "SELECT platform, COUNT(DISTINCT subscriber_id) c FROM livechat_messages
             WHERE user_id=? AND sender='user' AND conversation_time >= ? GROUP BY platform",
            array($uid, $since))->result_array();
        $leads = $this->db->query(
            "SELECT source, COUNT(*) c, SUM(status='won') won FROM crm_deals
             WHERE user_id=? AND created_at >= ? GROUP BY source",
            array($uid, $since))->result_array();

        $by = array();
        foreach ($conv as $r) {
            $p = $r['platform'] !== '' ? $r['platform'] : 'other';
            $by[$p] = array('conversations' => (int) $r['c'], 'leads' => 0, 'won' => 0);
        }
        foreach ($leads as $r) {
            $p = $r['source'] !== '' ? $r['source'] : 'manual';
            if (!isset($by[$p])) $by[$p] = array('conversations' => 0, 'leads' => 0, 'won' => 0);
            $by[$p]['leads'] = (int) $r['c'];
            $by[$p]['won'] = (int) $r['won'];
        }
        $totals = array('conversations' => 0, 'leads' => 0, 'won' => 0);
        foreach ($by as $row) {
            $totals['conversations'] += $row['conversations'];
            $totals['leads'] += $row['leads'];
            $totals['won'] += $row['won'];
        }
        return array('by_platform' => $by, 'totals' => $totals);
    }

    private function cards($since)
    {
        $uid = $this->uid;
        $subs = (int) $this->db->from('messenger_bot_subscriber')->where('user_id', $uid)->count_all_results();
        $orders = (int) $this->db->from('ecommerce_cart')->where('user_id', $uid)->where('action_type', 'checkout')->count_all_results();
        $ai = (int) $this->db->from('ai_usage_log')->where('user_id', $uid)->where('created_at >=', $since)->count_all_results();
        $hot = (int) $this->db->from('messenger_bot_subscriber')->where('user_id', $uid)->where('lead_score >=', 50)->count_all_results();
        return array('subscribers'=>$subs, 'orders'=>$orders, 'ai_replies_30d'=>$ai, 'hot_leads'=>$hot);
    }

    private function daily_map($rows, $key='d', $val='c')
    {
        $out = array();
        foreach ($rows as $r) $out[$r[$key]] = (int) $r[$val];
        return $out;
    }

    private function last_days_labels($days=30)
    {
        $labels = array();
        for ($i=$days-1; $i>=0; $i--) $labels[] = date('m-d', strtotime("-$i days"));
        return $labels;
    }

    private function subs_series($since)
    {
        $rows = $this->db->select("DATE(subscribed_at) d, COUNT(*) c")->from('messenger_bot_subscriber')
            ->where('user_id', $this->uid)->where('subscribed_at >=', $since)->group_by('DATE(subscribed_at)')->get()->result_array();
        return $this->fill_series($rows);
    }

    private function msg_series($since)
    {
        $rows = $this->db->select("DATE(conversation_time) d, sender, COUNT(*) c")->from('livechat_messages')
            ->where('user_id', $this->uid)->where('conversation_time >=', $since)->group_by(array('DATE(conversation_time)','sender'))->get()->result_array();
        $labels = $this->last_days_labels();
        $user=array(); $bot=array(); $agent=array();
        $map = array();
        foreach ($rows as $r) $map[$r['d']][$r['sender']] = (int)$r['c'];
        for ($i=29;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i days")); $user[]=$map[$d]['user']??0; $bot[]=$map[$d]['bot']??0; $agent[]=$map[$d]['system']??0; }
        return array('labels'=>$labels, 'user'=>$user, 'bot'=>$bot, 'agent'=>$agent);
    }

    private function ai_series($since)
    {
        $rows = $this->db->select("DATE(created_at) d, COUNT(*) c")->from('ai_usage_log')
            ->where('user_id', $this->uid)->where('created_at >=', $since)->group_by('DATE(created_at)')->get()->result_array();
        return $this->fill_series($rows);
    }

    private function orders_series($since)
    {
        $rows = $this->db->select("DATE(ordered_at) d, COUNT(*) c, SUM(payment_amount) v")->from('ecommerce_cart')
            ->where('user_id', $this->uid)->where('action_type', 'checkout')->where('ordered_at >=', $since)
            ->group_by('DATE(ordered_at)')->get()->result_array();
        $labels = $this->last_days_labels(); $count=array(); $value=array(); $map=array(); $vmap=array();
        foreach ($rows as $r){ $map[$r['d']]=(int)$r['c']; $vmap[$r['d']]=(float)$r['v']; }
        for ($i=29;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i days")); $count[]=$map[$d]??0; $value[]=round($vmap[$d]??0,2); }
        return array('labels'=>$labels, 'count'=>$count, 'value'=>$value);
    }

    private function fill_series($rows)
    {
        $map = $this->daily_map($rows);
        $labels = $this->last_days_labels(); $vals=array();
        for ($i=29;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i days")); $vals[]=$map[$d]??0; }
        return array('labels'=>$labels, 'values'=>$vals);
    }

    private function lead_bands()
    {
        $uid=$this->uid;
        $hot=(int)$this->db->from('messenger_bot_subscriber')->where('user_id',$uid)->where('lead_score >=',50)->count_all_results();
        $warm=(int)$this->db->from('messenger_bot_subscriber')->where('user_id',$uid)->where('lead_score >=',20)->where('lead_score <',50)->count_all_results();
        $cold=(int)$this->db->from('messenger_bot_subscriber')->where('user_id',$uid)->where('lead_score <',20)->count_all_results();
        return array('hot'=>$hot,'warm'=>$warm,'cold'=>$cold);
    }

    /**
     * The questions the bot couldn't answer, most frequent first — the gaps to fill.
     * Grouped case-insensitively on a trimmed question so rewordings cluster loosely.
     */
    private function top_missed($since)
    {
        if (!$this->db->table_exists('ai_unanswered_questions')) return array();
        return $this->db->query(
            "SELECT TRIM(question) q, social_media, COUNT(*) c, MAX(created_at) last_at
             FROM ai_unanswered_questions
             WHERE user_id=? AND status='new' AND created_at >= ?
             GROUP BY LOWER(TRIM(question)) ORDER BY c DESC, last_at DESC LIMIT 12",
            array($this->uid, $since))->result_array();
    }

    /**
     * Deflection rate (30d): how often the bot could NOT give a real answer — either it
     * flagged a gap ([[UNANSWERED]] -> ai_unanswered_questions) or the price guard blocked
     * a reply — as a share of all AI replies. High = the prompt/KB has gaps to fill.
     */
    private function deflection($since)
    {
        $uid = $this->uid;
        $replies = (int) $this->db->from('ai_conversation_history')->where('user_id', $uid)->where('created_at >=', $since)->count_all_results();
        $missed = $this->db->table_exists('ai_unanswered_questions')
            ? (int) $this->db->from('ai_unanswered_questions')->where('user_id', $uid)->where('created_at >=', $since)->count_all_results() : 0;
        $blocked = $this->db->table_exists('ai_price_guard_log')
            ? (int) $this->db->from('ai_price_guard_log')->where('user_id', $uid)->where('created_at >=', $since)->count_all_results() : 0;
        $deflected = $missed + $blocked;
        $rate = $replies > 0 ? round($deflected / max($replies, $deflected) * 100, 1) : 0.0;
        return array('replies'=>$replies, 'missed'=>$missed, 'blocked'=>$blocked, 'deflected'=>$deflected, 'rate'=>$rate);
    }

    // rough public price estimates (USD per 1M tokens: [input, output]) — estimates only
    private function ai_cost($since)
    {
        $prices = array(
            'gpt-4o-mini'=>array(0.15,0.60), 'gpt-4o'=>array(2.50,10.0), 'gpt-4.1'=>array(2.0,8.0),
            'gpt-4.1-mini'=>array(0.40,1.60), 'gpt-3.5-turbo'=>array(0.50,1.50),
            'claude-haiku-4-5'=>array(1.0,5.0), 'claude-sonnet-4-5'=>array(3.0,15.0),
        );
        $rows = $this->db->select('model, SUM(input_tokens) i, SUM(output_tokens) o')->from('ai_usage_log')
            ->where('user_id',$this->uid)->where('created_at >=',$since)->group_by('model')->get()->result_array();
        $total=0;
        foreach ($rows as $r){ $p = $prices[$r['model']] ?? array(1.0,3.0); $total += ($r['i']/1000000*$p[0]) + ($r['o']/1000000*$p[1]); }
        return round($total, 2);
    }
}
