<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-18 — AI Agents: multiple identity/brand-voice profiles per account, assignable per channel.
 * Credentials (provider + API key) stay in Integration → AI Credentials; profiles override
 * only identity/behavior and share the account key.
 */
class Ai_agents extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $data['profiles'] = $this->basic->get_data('ai_agent_profiles', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id DESC');
        $data['channels'] = $this->connected_channels();
        // current assignments as map channel_type|target => profile_id
        $data['assign_map'] = array();
        foreach ($this->basic->get_data('ai_agent_assignments', ['where'=>['user_id'=>$this->uid]]) as $a) {
            $data['assign_map'][$a['channel_type'].'|'.$a['target_id']] = $a['profile_id'];
        }
        $data['page_title'] = 'AI Agents';
        $data['body'] = 'admin/ai_agents/index';
        $this->_viewcontroller($data);
    }

    private function connected_channels()
    {
        $out = array();
        foreach ($this->basic->get_data('facebook_rx_fb_page_info', ['where'=>['user_id'=>$this->uid]], ['id','page_id','page_name']) as $p)
            $out[] = array('channel'=>'fb', 'target'=>$p['id'], 'label'=>($p['page_name'] ?: $p['page_id']), 'icon'=>'fab fa-facebook', 'note'=>'Messenger + Instagram');
        if ($this->db->table_exists('whatsapp_accounts'))
            foreach ($this->basic->get_data('whatsapp_accounts', ['where'=>['user_id'=>$this->uid]], ['id','label','display_phone']) as $w)
                $out[] = array('channel'=>'wa', 'target'=>$w['id'], 'label'=>($w['label'] ?: $w['display_phone'] ?: ('WA #'.$w['id'])), 'icon'=>'fab fa-whatsapp', 'note'=>'WhatsApp');
        if ($this->db->table_exists('telegram_accounts'))
            foreach ($this->basic->get_data('telegram_accounts', ['where'=>['user_id'=>$this->uid]], ['id','bot_username']) as $t)
                $out[] = array('channel'=>'tg', 'target'=>$t['id'], 'label'=>('@'.$t['bot_username']), 'icon'=>'fab fa-telegram', 'note'=>'Telegram');
        if ($this->db->table_exists('webchat_settings'))
            // target is the widget_key, matching how the webchat send path resolves the
            // agent (one widget per website, each with its own prompt).
            foreach ($this->basic->get_data('webchat_settings', ['where'=>['user_id'=>$this->uid]], ['widget_key','title']) as $wc)
                $out[] = array('channel'=>'web', 'target'=>$wc['widget_key'], 'label'=>($wc['title'] ?: 'Web Chat Widget'), 'icon'=>'fas fa-comment-dots', 'note'=>'Website');
        return $out;
    }

    public function save_profile()
    {
        $this->csrf_token_check();
        $id = (int)$this->input->post('id', true);
        $fields = array(
            'name'=>strip_tags((string)$this->input->post('name',true)) ?: 'Agent',
            'agent_name'=>strip_tags((string)$this->input->post('agent_name',true)),
            'instruction_to_ai'=>(string)$this->input->post('instruction_to_ai',true),
            'sales_mode_enabled'=>$this->input->post('sales_mode_enabled',true)=='1'?'1':'0',
            'sales_system_prompt'=>(string)$this->input->post('sales_system_prompt',true),
            'model'=>strip_tags((string)$this->input->post('model',true)),
            'max_history_messages'=>min(20,max(1,(int)$this->input->post('max_history_messages',true))),
            'temperature'=>min(2,max(0,(float)$this->input->post('temperature',true))),
            'memory_ttl_hours'=>max(1,(int)$this->input->post('memory_ttl_hours',true)),
            'auto_language'=>$this->input->post('auto_language',true)=='1'?'1':'0',
            'sentiment_enabled'=>$this->input->post('sentiment_enabled',true)=='1'?'1':'0',
            'ai_tools_enabled'=>$this->input->post('ai_tools_enabled',true)=='1'?'1':'0',
            'status'=>'1',
        );
        if ($id > 0 && $this->owns_profile($id)) {
            $this->db->where('id',$id)->where('user_id',$this->uid)->update('ai_agent_profiles', $fields);
        } else {
            $fields['user_id']=$this->uid; $fields['created_at']=date('Y-m-d H:i:s');
            $this->basic->insert_data('ai_agent_profiles', $fields);
        }
        $this->session->set_flashdata('success','Agent profile saved');
        redirect('ai_agents');
    }

    public function delete_profile($id=0)
    {
        if (!hash_equals((string)$this->session->userdata('csrf_token_session'), (string)$this->input->get('t'))) show_error('Invalid token', 403);
        if ($this->owns_profile($id)) {
            $this->db->where(['id'=>(int)$id,'user_id'=>$this->uid])->delete('ai_agent_profiles');
            $this->db->where(['profile_id'=>(int)$id,'user_id'=>$this->uid])->delete('ai_agent_assignments');
        }
        redirect('ai_agents');
    }

    private function owns_profile($id)
    {
        return (bool) $this->db->from('ai_agent_profiles')->where('id',(int)$id)->where('user_id',$this->uid)->count_all_results();
    }

    public function save_assignments()
    {
        $this->csrf_token_check();
        $assigns = $this->input->post('assign', true); // assign[channel|target] = profile_id
        if (is_array($assigns)) {
            foreach ($assigns as $key => $profile_id) {
                $parts = explode('|', $key, 2);
                if (count($parts) !== 2) continue;
                list($channel, $target) = $parts;
                if (!in_array($channel, array('fb','ig','wa','tg','web'))) continue;
                $this->db->where(['user_id'=>$this->uid,'channel_type'=>$channel,'target_id'=>$target])->delete('ai_agent_assignments');
                $pid = (int)$profile_id;
                if ($pid > 0 && $this->owns_profile($pid)) {
                    $this->basic->insert_data('ai_agent_assignments', array('user_id'=>$this->uid,'profile_id'=>$pid,'channel_type'=>$channel,'target_id'=>(string)$target));
                }
            }
        }
        $this->session->set_flashdata('success','Assignments saved');
        redirect('ai_agents');
    }
}
