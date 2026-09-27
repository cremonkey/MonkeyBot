<?php
require_once("Home.php");

/**
 * AI Improvement Center (Phase 10 / brief 20).
 *
 * Turns "questions the bot missed" into a short list of gaps: similar questions are grouped
 * (Arabic-aware, deterministic), counted, and closed with ONE answer that becomes an ai_faq
 * row the AI uses immediately. Also surfaces price-guard blocks (the bot tried to state a
 * price it could not ground) and shows, for every answer, how often the gap came back.
 */
class Ai_improvement extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper(array('entitlement', 'ai_improvement'));
        entitlement_require('missed_questions');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $since = date('Y-m-d H:i:s', time() - 86400 * 90);
        $open = $this->db->select('id, question, social_media, page_id, created_at')->from('ai_unanswered_questions')
            ->where('user_id', $this->uid)->where('status', 'new')->order_by('id', 'DESC')->limit(2000)->get()->result_array();
        $data['gaps'] = aii_group($open);

        $data['guard'] = array();
        $data['low_confidence'] = array();
        if ($this->db->table_exists('ai_price_guard_log')) {
            $g = $this->db->select('id, question, social_media, verdict, created_at')->from('ai_price_guard_log')
                ->where('user_id', $this->uid)->where('created_at >=', $since)->order_by('id', 'DESC')->limit(2000)->get()->result_array();
            $data['guard'] = array_slice(aii_group($g), 0, 30);
            $verdicts = array();
            foreach ($g as $r) $verdicts[$r['verdict']] = ($verdicts[$r['verdict']] ?? 0) + 1;
            $data['guard_verdicts'] = $verdicts;

            // Low-confidence: the guard could not tell (verdict='unknown'), as opposed to a
            // clear block (ungrounded/availability/media). Same grouping, filtered subset.
            $unknown = array_values(array_filter($g, function ($r) { return ($r['verdict'] ?? '') === 'unknown'; }));
            $data['low_confidence'] = array_slice(aii_group($unknown), 0, 30);
        }

        // Tool failures (Task 4's ai_failure_log, kind='tool'): grouped by tool + error class,
        // not by question text (these are categorical, not free-text customer questions).
        $data['tool_failures'] = array();
        if ($this->db->table_exists('ai_failure_log')) {
            $tf = $this->db->select('tool_name, error_class, error_message, created_at')->from('ai_failure_log')
                ->where('user_id', $this->uid)->where('kind', 'tool')->where('created_at >=', $since)
                ->order_by('id', 'DESC')->limit(2000)->get()->result_array();
            $data['tool_failures'] = array_slice(aii_group_tool_failures($tf), 0, 30);
        }

        // Repeated escalations: a conversation that flipped to WAITING_FOR_HUMAN more than
        // once, grouped by the topic of its first customer message (same grouping helper).
        $data['escalations'] = array();
        if ($this->db->table_exists('conversation_events') && $this->db->table_exists('conversations')
            && $this->db->field_exists('conversation_id', 'livechat_messages')) {
            $events = $this->db->select('conversation_id, created_at')->from('conversation_events')
                ->where('user_id', $this->uid)->where('to_state', 'WAITING_FOR_HUMAN')->where('created_at >=', $since)
                ->order_by('id', 'ASC')->limit(5000)->get()->result_array();
            $conv_ids = array_values(array_unique(array_map(function ($e) { return (int) $e['conversation_id']; }, $events)));
            $first_by_conv = array();
            if (!empty($conv_ids)) {
                $convs = $this->db->select('id, platform')->from('conversations')
                    ->where('user_id', $this->uid)->where_in('id', $conv_ids)->get()->result_array();
                $platform_by_conv = array();
                foreach ($convs as $c) $platform_by_conv[(int) $c['id']] = $c['platform'];
                $firsts = $this->db->select('conversation_id, MIN(id) AS first_id')->from('livechat_messages')
                    ->where('user_id', $this->uid)->where('sender', 'user')->where_in('conversation_id', $conv_ids)
                    ->group_by('conversation_id')->get()->result_array();
                $first_ids = array_map(function ($r) { return (int) $r['first_id']; }, $firsts);
                if (!empty($first_ids)) {
                    $msgs = $this->db->select('id, conversation_id, message_content')->from('livechat_messages')
                        ->where('user_id', $this->uid)->where_in('id', $first_ids)->get()->result_array();
                    foreach ($msgs as $m) {
                        $cid = (int) $m['conversation_id'];
                        $first_by_conv[$cid] = array('question' => $m['message_content'], 'social_media' => $platform_by_conv[$cid] ?? '?');
                    }
                }
            }
            $esc_rows = aii_escalation_rows($events, $first_by_conv, 2);
            $data['escalations'] = array_slice(aii_group($esc_rows), 0, 30);
        }

        // Human corrections: an Inbox agent reply sent within N minutes after an AI reply in
        // the same conversation (both stored as sender='bot'; told apart by agent_name).
        $data['corrections'] = array();
        $data['corrections_window_minutes'] = 10;
        if ($this->db->field_exists('conversation_id', 'livechat_messages')) {
            $bot_rows = $this->db->select('id, conversation_id, agent_name, message_content, conversation_time')->from('livechat_messages')
                ->where('user_id', $this->uid)->where('sender', 'bot')->where('conversation_time >=', $since)
                ->where('conversation_id IS NOT NULL', null, false)->order_by('id', 'ASC')->limit(5000)->get()->result_array();
            $data['corrections'] = array_slice(aii_find_corrections($bot_rows, $data['corrections_window_minutes']), 0, 30);
        }

        // answers and their effect: similar questions that still came in after the answer
        $faq_cols = $this->db->field_exists('source_group', 'ai_faq');
        $faqs = $this->db->select('f.id, f.question, f.answer, f.created_at' . ($faq_cols ? ', f.questions_before, f.created_by, u.name AS by_name' : ''))
            ->from('ai_faq f');
        if ($faq_cols) $faqs->join('users u', 'u.id = f.created_by', 'left');
        $faqs = $faqs->where('f.user_id', $this->uid)->where('f.status', '1')->order_by('f.id', 'DESC')->limit(50)->get()->result_array();
        $all_q = $this->db->select('question, created_at')->from('ai_unanswered_questions')
            ->where('user_id', $this->uid)->where('created_at >=', $since)->get()->result_array();
        foreach ($faqs as &$f) {
            $after = 0;
            foreach ($all_q as $q) if ($q['created_at'] > $f['created_at'] && aii_similar($q['question'], $f['question'])) $after++;
            $f['asked_after'] = $after;
        }
        unset($f);
        $data['faqs'] = $faqs;
        $data['open_total'] = count($open);
        $data['page_title'] = 'AI Improvement Center';
        $data['body'] = 'admin/ai_improvement/index';
        $this->_viewcontroller($data);
    }

    /** Close a whole gap with one answer: one ai_faq row, every question in the group resolved. */
    public function answer_group()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $ids = array_values(array_filter(array_map('intval', (array) $this->input->post('ids'))));
        $answer = trim((string) $this->input->post('answer', true));
        $question = trim((string) $this->input->post('question', true));
        if (empty($ids) || $answer === '') { echo json_encode(array('status' => '0', 'message' => 'Write the answer first.')); return; }

        // only this tenant's open questions count; anything else in ids is ignored
        $rows = $this->db->select('id, page_id, question')->from('ai_unanswered_questions')
            ->where('user_id', $this->uid)->where_in('id', $ids)->get()->result_array();
        if (empty($rows)) { echo json_encode(array('status' => '0', 'message' => 'Questions not found.')); return; }
        $pages = array_unique(array_map(function ($r) { $p = trim((string) $r['page_id']); return ($p === '' || $p === 'webchat') ? '' : $p; }, $rows));
        $page = count($pages) === 1 ? reset($pages) : '';   // mixed pages -> answer applies to all pages
        if ($question === '') $question = $rows[0]['question'];

        $faq = array('user_id' => $this->uid, 'page_id' => $page !== '' ? $page : null, 'question' => $question,
            'answer' => $answer, 'status' => '1', 'created_at' => date('Y-m-d H:i:s'));
        if ($this->db->field_exists('source_group', 'ai_faq')) {
            $faq['created_by'] = (int) $this->session->userdata('user_id');
            $faq['source_group'] = mb_substr(aii_normalize($question), 0, 190);
            $faq['questions_before'] = count($rows);
        }
        $this->db->trans_start();
        $this->db->insert('ai_faq', $faq);
        $this->db->where('user_id', $this->uid)->where_in('id', array_column($rows, 'id'))
            ->update('ai_unanswered_questions', array('status' => 'resolved'));
        $this->db->trans_complete();
        echo json_encode($this->db->trans_status()
            ? array('status' => '1', 'message' => 'Answer added. The bot uses it from the next message; ' . count($rows) . ' question(s) closed.')
            : array('status' => '0', 'message' => 'Could not save.'));
    }

    /** Dismiss a gap without an answer (e.g. spam, out of scope). */
    public function dismiss_group()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $ids = array_values(array_filter(array_map('intval', (array) $this->input->post('ids'))));
        if ($ids) $this->db->where('user_id', $this->uid)->where_in('id', $ids)->update('ai_unanswered_questions', array('status' => 'resolved'));
        echo json_encode(array('status' => '1'));
    }

    /** Edit an existing answer (keeps the row, stamps updated_at). */
    public function update_answer()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        $answer = trim((string) $this->input->post('answer', true));
        if ($answer === '') { echo json_encode(array('status' => '0', 'message' => 'Answer is empty.')); return; }
        $upd = array('answer' => $answer);
        if ($this->db->field_exists('updated_at', 'ai_faq')) $upd['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id)->where('user_id', $this->uid)->update('ai_faq', $upd);
        echo json_encode(array('status' => $this->db->affected_rows() ? '1' : '0'));
    }
}
