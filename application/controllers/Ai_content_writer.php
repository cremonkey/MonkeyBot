<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-16 — AI Content Writer. Generates marketing copy via the configured AI provider.
 */
class Ai_content_writer extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $data['page_title'] = 'AI Content Writer';
        $data['body'] = 'admin/ai_content_writer/index';
        $this->_viewcontroller($data);
    }

    private function system_prompt_for($type, $tone, $language, $length)
    {
        $len_word = $length === 'short' ? 'about 1-2 sentences' : ($length === 'long' ? 'a detailed long-form piece' : 'a medium-length piece');
        $lang = ($language && strtolower($language) !== 'auto') ? "Write in {$language}." : "Write in the same language as the topic given by the user.";
        $base = "You are an expert marketing copywriter. Tone: {$tone}. {$lang} Produce {$len_word}.";
        switch ($type) {
            case 'social_post':
                return $base . " Write an engaging social media post with a strong hook, 1-2 relevant emojis, and 3-5 relevant hashtags at the end.";
            case 'ad_copy':
                return $base . " Write high-converting ad copy using the AIDA framework (Attention, Interest, Desire, Action) with a clear call to action.";
            case 'product_description':
                return $base . " Write a persuasive, SEO-aware product description highlighting benefits and features, ending with a subtle call to action.";
            case 'email_campaign':
                return $base . " Write a marketing email. Start with a line 'Subject: ...' then a blank line, then the email body with a clear CTA.";
            case 'comment_reply':
                return $base . " Write a friendly, professional reply to a customer comment that builds rapport and encourages engagement.";
            default:
                return $base;
        }
    }

    public function generate()
    {
        header('Content-Type: application/json');
        if ($this->input->method() !== 'post') { echo json_encode(['status'=>'0','message'=>'POST required']); return; }
        $type = $this->input->post('content_type', true);
        $topic = trim((string) $this->input->post('topic', true));
        $tone = $this->input->post('tone', true) ?: 'professional';
        $language = $this->input->post('language', true) ?: 'Auto';
        $length = $this->input->post('length', true) ?: 'medium';
        $count = (int) $this->input->post('count', true); if ($count < 1) $count = 1; if ($count > 3) $count = 3;
        if ($topic === '') { echo json_encode(['status'=>'0','message'=>'Please enter a topic or brief.']); return; }

        $cfg = $this->basic->get_data('open_ai_config', ['where'=>['user_id'=>$this->uid]]);
        if (empty($cfg)) { echo json_encode(['status'=>'0','message'=>'Configure your AI provider first (Integration → AI Credentials).']); return; }

        $max_tokens = $length === 'short' ? 300 : ($length === 'long' ? 1400 : 700);
        $system = $this->system_prompt_for($type, $tone, $language, $length);
        $this->load->library('Ai_provider');

        $results = array();
        for ($i = 0; $i < $count; $i++) {
            $messages = array(array('role'=>'user', 'content'=>"Topic / brief: ".$topic.($count>1?("\n\n(Give variation #".($i+1).", distinct from other variations.)"):"")));
            $raw = $this->ai_provider->completion($cfg[0], $messages, array('system'=>$system, 'max_tokens'=>$max_tokens, 'temperature'=>0.8, 'purpose'=>'content_writer'));
            $dec = json_decode($raw, true);
            if (isset($dec['error'])) { echo json_encode(['status'=>'0','message'=>$dec['error']['message']]); return; }
            $text = isset($dec['choices'][0]['text']) ? trim($dec['choices'][0]['text']) : '';
            if ($text !== '') {
                $results[] = $text;
                $this->basic->insert_data('ai_content_history', array(
                    'user_id'=>$this->uid, 'content_type'=>$type, 'prompt_input'=>$topic,
                    'generated_content'=>$text, 'language'=>$language, 'created_at'=>date('Y-m-d H:i:s'),
                ));
                // usage log if SPEC-13 table exists
                if ($this->db->table_exists('ai_usage_log')) { /* Ai_provider logs it */ }
            }
        }
        if (empty($results)) { echo json_encode(['status'=>'0','message'=>'The AI returned no content. Try again.']); return; }
        echo json_encode(['status'=>'1','results'=>$results]);
    }

    public function history_data()
    {
        header('Content-Type: application/json');
        $rows = $this->basic->get_data('ai_content_history', ['where'=>['user_id'=>$this->uid]], ['id','content_type','prompt_input','generated_content','created_at'], '', 50, 0, 'id DESC');
        echo json_encode(['data'=>$rows ?: []]);
    }

    public function delete_history()
    {
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id', true);
        if ($id > 0) $this->db->where(['id'=>$id, 'user_id'=>$this->uid])->delete('ai_content_history');
        echo json_encode(['status'=>'1']);
    }
}
