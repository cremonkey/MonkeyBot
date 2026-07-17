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

    /**
     * SPEC-26: live preview. Assembles the same master + bot-specific + guardrail layers
     * that a real reply uses, from the CURRENTLY TYPED prompt fields (not the saved row),
     * runs one model call, and returns the reply — so the owner sees the effect of an edit
     * before saving. No history, no KB, no tools: a faithful preview of the prompt text's
     * behaviour, labelled as such in the UI.
     */
    public function preview()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $msg = trim((string)$this->input->post('message', true));
        if ($msg === '') { echo json_encode(['status'=>'0','message'=>'Type a test message']); return; }

        $acc = $this->basic->get_data('open_ai_config', ['where'=>['user_id'=>$this->uid]], '', '', 1);
        if (empty($acc)) { echo json_encode(['status'=>'0','message'=>'No AI credentials configured']); return; }
        $acc = $acc[0];
        $key = $acc['open_ai_secret_key'] ?? '';
        if ($key === '') { echo json_encode(['status'=>'0','message'=>'No OpenAI key']); return; }

        $agent_name  = trim((string)$this->input->post('agent_name', true));
        $instruction = trim((string)$this->input->post('instruction_to_ai', true));
        $agent_sales = trim((string)$this->input->post('sales_system_prompt', true));

        $layers = array();
        if (!empty($acc['sales_system_prompt'])) $layers[] = "--- MASTER RULES (apply to every page and every agent; never overridden) ---\n".trim($acc['sales_system_prompt']);
        $bot = array();
        if ($agent_name !== '')  $bot[] = 'AGENT IDENTITY: '.$agent_name;
        if ($instruction !== '') $bot[] = $instruction;
        if ($agent_sales !== '') $bot[] = $agent_sales;
        if (!empty($bot)) $layers[] = "--- BOT-SPECIFIC INSTRUCTIONS (this business's identity, offers, prices, and rules) ---\n".implode("\n\n", $bot);
        $sp = implode("\n\n", $layers);
        // condensed guardrails so the preview behaves like production on scope/price/format
        $sp .= "\n\nSTRICT SCOPE RULES: Only use the identity, products, services and prices written above. Never invent a price or a fact. If a price is not written, say the team will confirm and ask for a WhatsApp number. Every reply is very short: one direct answer + one question, no lists, no markdown. Mirror the customer's language; Arabic -> Egyptian colloquial.";

        $model = !empty($acc['models']) ? $acc['models'] : 'gpt-4o-mini';
        $payload = json_encode(array(
            'model'=>$model, 'temperature'=>0.4,
            'messages'=>array(array('role'=>'system','content'=>$sp), array('role'=>'user','content'=>$msg)),
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, array(
            CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>1,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>array('Content-Type: application/json','Authorization: Bearer '.$key),
        ));
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode($raw, true);
        $reply = $d['choices'][0]['message']['content'] ?? '';
        if ($reply === '') { echo json_encode(['status'=>'0','message'=>'Preview failed (HTTP '.$code.')']); return; }
        echo json_encode(['status'=>'1','reply'=>$reply, 'chars'=>mb_strlen($sp)]);
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
            $id = $this->basic->insert_data('ai_agent_profiles', $fields);
        }
        // Keep the per-channel prompt copies in step. Editing only the profile would leave
        // Messenger/IG on a stale flow copy (the copy is the CAMPAIGN layer, so it wins).
        // Done in PHP through the query builder — none of the CRLF/CR-stripping traps the
        // shell sync script has, because there is no piping or text-mode newline rewrite.
        $synced = $this->sync_profile_to_channels((int)$id);
        $this->session->set_flashdata('success', 'Agent profile saved'.($synced ? " (synced to {$synced} channel template".($synced>1?'s':'').')' : ''));
        redirect('ai_agents');
    }

    /**
     * Push a profile's sales_system_prompt into the runtime + Flow Builder copies of every
     * fb/ig page it is assigned to. Returns the number of templates updated.
     */
    private function sync_profile_to_channels($profile_id)
    {
        $prof = $this->db->from('ai_agent_profiles')->where('id',$profile_id)->where('user_id',$this->uid)->get()->row_array();
        if (empty($prof)) return 0;
        $text = (string) $prof['sales_system_prompt'];
        if (trim($text) === '') return 0;   // nothing to propagate

        // pages this profile drives (fb/ig assignments target the page_table_id)
        $pages = array();
        foreach ($this->db->from('ai_agent_assignments')->where('user_id',$this->uid)->where('profile_id',$profile_id)
                     ->where_in('channel_type', array('fb','ig'))->get()->result_array() as $a) {
            $pages[(string)$a['target_id']] = true;
        }
        if (empty($pages)) return 0;
        $count = 0;

        foreach (array_keys($pages) as $pt) {
            // runtime no-match templates (messenger_bot.message: replace the AI node text)
            foreach ($this->db->from('messenger_bot')->where('user_id',$this->uid)->where('page_id',$pt)
                         ->where('keyword_type','no match')->get()->result_array() as $mb) {
                $j = json_decode($mb['message'], true);
                if (isset($j['out'][0]['reply']['webhook_response'][0]['message'])
                    && (($j['out'][0]['reply']['webhook_response'][0]['message']['text_from'] ?? '') === 'AI')) {
                    $j['out'][0]['reply']['webhook_response'][0]['message']['text'] = $text;
                    $this->db->where('id',$mb['id'])->update('messenger_bot', array('message'=>json_encode($j, JSON_UNESCAPED_UNICODE)));
                    $count++;
                }
            }
            // Flow Builder copies (visual_flow_builder_campaign.json_data: the Open AI node)
            foreach ($this->db->from('visual_flow_builder_campaign')->where('user_id',$this->uid)->where('page_id',$pt)->get()->result_array() as $fc) {
                $j = json_decode($fc['json_data'], true);
                $changed = false;
                if (!empty($j['nodes']) && is_array($j['nodes'])) {
                    foreach ($j['nodes'] as $k=>$node) {
                        if (($node['name'] ?? '') === 'Open AI' && isset($node['data'])) {
                            $j['nodes'][$k]['data']['textMessage'] = $text; $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $this->db->where('id',$fc['id'])->update('visual_flow_builder_campaign', array('json_data'=>json_encode($j, JSON_UNESCAPED_UNICODE)));
                    $count++;
                }
            }
        }
        return $count;
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
