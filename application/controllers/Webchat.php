<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-11 — Standalone website chat widget.
 * Public routes: widget/<key> (JS bootstrap), send (POST), poll (GET). Admin: index/save.
 * Conversations log to livechat_messages (platform='web'); AI memory keys on (account, session, 'web').
 */
class Webchat extends Home
{
    public function __construct()
    {
        parent::__construct();
        $seg = $this->uri->segment(2);
        $public = array('widget', 'send', 'poll');
        if (!in_array($seg, $public)) {
            if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
            $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        }
    }

    // ---- Admin ----
    public function index()
    {
        $s = $this->basic->get_data('webchat_settings', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id ASC');
        if (empty($s)) {
            $this->create_widget('Web Chat Widget');
            $s = $this->basic->get_data('webchat_settings', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id ASC');
        }
        // which AI agent is assigned to each widget (its site's prompt)
        $assign = array();
        foreach ($this->basic->get_data('ai_agent_assignments', ['where'=>['user_id'=>$this->uid, 'channel_type'=>'web']]) as $a) {
            $assign[$a['target_id']] = $a['profile_id'];
        }
        $data['widgets']    = $s;
        $data['assign_map'] = $assign;
        $data['profiles']   = $this->basic->get_data('ai_agent_profiles', ['where'=>['user_id'=>$this->uid, 'status'=>'1']], '', '', '', '', 'id DESC');
        $data['base']       = base_url();
        $data['page_title'] = 'Web Chat Widgets';
        $data['body']       = 'admin/webchat/index';
        $this->_viewcontroller($data);
    }

    private function create_widget($title)
    {
        $key = substr(md5(uniqid('wc'.$this->uid, true)), 0, 24);
        $this->basic->insert_data('webchat_settings', array(
            'user_id'=>$this->uid, 'widget_key'=>$key,
            'title'=>strip_tags((string)$title) ?: 'Web Chat Widget',
            'ai_enabled'=>'1', 'status'=>'1',
        ));
        return $key;
    }

    public function add()
    {
        $this->csrf_token_check();
        $this->create_widget($this->input->post('title', true));
        $this->session->set_flashdata('success','Widget created');
        redirect('webchat');
    }

    private function owns_widget($id)
    {
        return (bool) $this->db->from('webchat_settings')->where('id',(int)$id)->where('user_id',$this->uid)->count_all_results();
    }

    public function save()
    {
        $this->csrf_token_check();
        $id = (int)$this->input->post('id', true);
        if (!$this->owns_widget($id)) { redirect('webchat'); return; }
        $this->db->where('id', $id)->where('user_id', $this->uid)->update('webchat_settings', array(
            'title'=>strip_tags((string)$this->input->post('title', true)),
            'color'=>strip_tags((string)$this->input->post('color', true)),
            'greeting'=>strip_tags((string)$this->input->post('greeting', true)),
            'ai_enabled'=>$this->input->post('ai_enabled', true)=='1'?'1':'0',
        ));

        // Bind this widget to an AI agent (its site's prompt), stored exactly like the
        // fb/ig channel assignments so get_ai_reply_open_ai resolves it the same way.
        $w = $this->db->select('widget_key')->from('webchat_settings')->where('id',$id)->get()->row_array();
        $this->db->where(['user_id'=>$this->uid,'channel_type'=>'web','target_id'=>$w['widget_key']])->delete('ai_agent_assignments');
        $pid = (int)$this->input->post('profile_id', true);
        if ($pid > 0 && (bool)$this->db->from('ai_agent_profiles')->where('id',$pid)->where('user_id',$this->uid)->count_all_results()) {
            $this->basic->insert_data('ai_agent_assignments', array('user_id'=>$this->uid,'profile_id'=>$pid,'channel_type'=>'web','target_id'=>$w['widget_key']));
        }

        $this->session->set_flashdata('success','Saved');
        redirect('webchat');
    }

    public function delete_widget($id=0)
    {
        if (!hash_equals((string)$this->session->userdata('csrf_token_session'), (string)$this->input->get('t'))) show_error('Invalid token', 403);
        if ($this->owns_widget($id)) {
            $w = $this->db->select('widget_key')->from('webchat_settings')->where('id',(int)$id)->get()->row_array();
            $this->db->where(['id'=>(int)$id,'user_id'=>$this->uid])->delete('webchat_settings');
            if (!empty($w)) $this->db->where(['user_id'=>$this->uid,'channel_type'=>'web','target_id'=>$w['widget_key']])->delete('ai_agent_assignments');
        }
        redirect('webchat');
    }

    private function settings_by_key($key)
    {
        $s = $this->basic->get_data('webchat_settings', ['where'=>['widget_key'=>$key, 'status'=>'1']]);
        return empty($s) ? null : $s[0];
    }

    // ---- Public: rate limit helper ----
    private function rate_ok($user_id)
    {
        $cnt = (int) $this->db->from('livechat_messages')->where('user_id',$user_id)->where('platform','web')
            ->where('sender','user')->where('conversation_time >=', date('Y-m-d H:i:s', strtotime('-1 minute')))->count_all_results();
        return $cnt < 20;
    }

    // ---- Public: widget bootstrap JS ----
    public function widget($key='')
    {
        $s = $this->settings_by_key($key);
        $this->output->set_content_type('application/javascript');
        if (!$s) { echo '/* invalid widget key */'; return; }
        $cfg = json_encode(array('key'=>$s['widget_key'], 'title'=>$s['title'], 'color'=>$s['color'], 'greeting'=>$s['greeting'], 'base'=>base_url()));
        echo $this->widget_js($cfg);
    }

    private function widget_js($cfg)
    {
        return <<<JS
(function(){
var C=$cfg;var open=false;var sk=localStorage.getItem('mb_wc_'+C.key)||'';var lastId=0;
var btn=document.createElement('div');btn.innerHTML='&#128172;';
btn.style.cssText='position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:'+C.color+';color:#fff;font-size:26px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);z-index:99999';
var box=document.createElement('div');
box.style.cssText='position:fixed;bottom:88px;right:20px;width:330px;max-width:92vw;height:440px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;z-index:99999;font-family:sans-serif';
box.innerHTML='<div style="background:'+C.color+';color:#fff;padding:12px;font-weight:bold">'+C.title+'</div><div id="mbwc_msgs" style="flex:1;overflow-y:auto;padding:10px;font-size:14px"></div><div style="display:flex;border-top:1px solid #eee"><input id="mbwc_in" placeholder="Type..." style="flex:1;border:0;padding:12px;outline:none"><button id="mbwc_send" style="border:0;background:'+C.color+';color:#fff;padding:0 16px;cursor:pointer">Send</button></div>';
document.body.appendChild(btn);document.body.appendChild(box);
var msgs=box.querySelector('#mbwc_msgs');
function add(t,who){var d=document.createElement('div');d.style.cssText='margin:6px 0;padding:8px 10px;border-radius:10px;max-width:80%;'+(who=='user'?'background:'+C.color+';color:#fff;margin-left:auto':'background:#f1f1f1;color:#222');d.textContent=t;msgs.appendChild(d);msgs.scrollTop=msgs.scrollHeight;}
btn.onclick=function(){open=!open;box.style.display=open?'flex':'none';if(open&&!msgs.children.length){add(C.greeting,'bot');poll();}};
function send(){var i=box.querySelector('#mbwc_in');var t=i.value.trim();if(!t)return;i.value='';add(t,'user');
var fd=new FormData();fd.append('widget_key',C.key);fd.append('session_key',sk);fd.append('message',t);
fetch(C.base+'webchat/send',{method:'POST',body:fd}).then(r=>r.json()).then(function(r){if(r.session_key){sk=r.session_key;localStorage.setItem('mb_wc_'+C.key,sk);}if(r.reply)add(r.reply,'bot');if(r.last_id)lastId=r.last_id;});}
box.querySelector('#mbwc_send').onclick=send;
box.querySelector('#mbwc_in').addEventListener('keydown',function(e){if(e.key=='Enter')send();});
function poll(){if(!sk)return;fetch(C.base+'webchat/poll?widget_key='+C.key+'&session_key='+sk+'&since_id='+lastId).then(r=>r.json()).then(function(r){(r.messages||[]).forEach(function(m){add(m.message_content,m.sender=='user'?'user':'bot');lastId=m.id;});});}
setInterval(function(){if(open)poll();},4000);
})();
JS;
    }

    // ---- Public: receive a visitor message ----
    public function send()
    {
        $this->output->set_content_type('application/json');
        $key = $this->input->post('widget_key', true);
        $s = $this->settings_by_key($key);
        if (!$s) { echo json_encode(['error'=>'invalid']); return; }
        if (!$this->rate_ok($s['user_id'])) { echo json_encode(['error'=>'rate']); return; }
        $sk = $this->input->post('session_key', true);
        $message = trim((string)$this->input->post('message', true));
        if ($message === '') { echo json_encode(['error'=>'empty']); return; }

        if (empty($sk)) {
            $sk = 'web_'.substr(md5(uniqid('', true)), 0, 24);
            $this->basic->insert_data('webchat_sessions', array('user_id'=>$s['user_id'], 'session_key'=>$sk, 'page_url'=>substr((string)$this->input->post('page_url', true),0,255), 'created_at'=>date('Y-m-d H:i:s'), 'last_activity'=>date('Y-m-d H:i:s')));
        } else {
            $this->db->where('session_key',$sk)->update('webchat_sessions', array('last_activity'=>date('Y-m-d H:i:s')));
        }

        $this->log_msg($s['user_id'], $sk, 'user', $message);

        $reply_text = '';
        if ($s['ai_enabled'] == '1') {
            // Pass the widget_key as page_id so each website widget resolves its own AI
            // agent (its site's prompt). The subscriber id stays $sk. History and RAG key
            // off page_id, so a widget_key gives every widget its own conversation memory.
            $reply = $this->get_ai_reply_open_ai('', $message, $s['user_id'], $key, $sk, 'web');
            $reply_text = $reply['choices'][0]['text'] ?? '';
            if ($reply_text !== '') $this->log_msg($s['user_id'], $sk, 'bot', $reply_text);
        }
        $last = $this->db->select('MAX(id) mid')->from('livechat_messages')->where('user_id',$s['user_id'])->where('subscriber_id',$sk)->get()->row_array();
        echo json_encode(['session_key'=>$sk, 'reply'=>$reply_text, 'last_id'=>(int)($last['mid'] ?? 0)]);
    }

    // ---- Public: poll for agent/new messages ----
    public function poll()
    {
        $this->output->set_content_type('application/json');
        $key = $this->input->get('widget_key');
        $s = $this->settings_by_key($key);
        if (!$s) { echo json_encode(['messages'=>[]]); return; }
        $sk = $this->input->get('session_key');
        $since = (int) $this->input->get('since_id');
        $rows = $this->db->select('id, sender, message_content')->from('livechat_messages')
            ->where('user_id', $s['user_id'])->where('subscriber_id', $sk)->where('platform','web')->where('id >', $since)
            ->order_by('id','ASC')->limit(50)->get()->result_array();
        echo json_encode(['messages'=>$rows]);
    }

    private function log_msg($user_id, $sk, $sender, $content)
    {
        $this->basic->insert_data('livechat_messages', array(
            'user_id'=>$user_id, 'subscriber_id'=>$sk, 'page_table_id'=>0, 'fb_page_id'=>'',
            'sender'=>$sender, 'platform'=>'web', 'agent_name'=>($sender=='bot'?'Bot':''),
            'message_content'=>$content, 'conversation_time'=>date('Y-m-d H:i:s'),
            'fb_message_id'=>'', 'message_status'=>'sent', 'from_business_suite'=>'0',
        ));
    }
}
