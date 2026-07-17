<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Missed Questions — customer questions the AI could not answer from its
 * configured context (page prompt / knowledge base). Filling these gaps in
 * the prompt or KB is how the bot improves week over week.
 */
class Missed_questions extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $status = $this->input->get('status', true) === 'resolved' ? 'resolved' : 'new';
        $channel = trim((string) $this->input->get('channel', true));

        $this->db->select("q.*, p.page_name")
            ->from('ai_unanswered_questions q')
            ->join('facebook_rx_fb_page_info p', 'p.page_id = q.page_id AND p.user_id = q.user_id', 'left')
            ->where('q.user_id', $this->uid)->where('q.status', $status);
        if ($channel !== '') $this->db->where('q.social_media', $channel);
        $data['rows'] = $this->db->order_by('q.id', 'DESC')->limit(300)->get()->result_array();

        $data['counts'] = $this->db->query(
            "SELECT status, COUNT(*) c FROM ai_unanswered_questions WHERE user_id = ? GROUP BY status", array($this->uid)
        )->result_array();
        $data['by_channel'] = $this->db->query(
            "SELECT social_media, COUNT(*) c FROM ai_unanswered_questions WHERE user_id = ? AND status='new' GROUP BY social_media ORDER BY c DESC", array($this->uid)
        )->result_array();
        $data['status'] = $status;
        $data['channel'] = $channel;
        $data['page_title'] = 'Missed Questions';
        $data['body'] = 'admin/missed_questions/index';
        $this->_viewcontroller($data);
    }

    public function resolve()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id', true);
        $this->db->where('id', $id)->where('user_id', $this->uid)
            ->update('ai_unanswered_questions', array('status' => 'resolved'));
        echo json_encode(['status' => '1']);
    }

    public function resolve_all()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $this->db->where('user_id', $this->uid)->where('status', 'new')
            ->update('ai_unanswered_questions', array('status' => 'resolved'));
        echo json_encode(['status' => '1']);
    }

    /**
     * SPEC-25: the owner types the answer; it becomes an ai_faq row scoped to that
     * question's page and is injected into the bot's prompt live on every channel. The
     * question is marked resolved. No prompt-copy sync needed — the FAQ is read at reply
     * time, not stored in the per-channel templates.
     */
    public function answer()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id', true);
        $answer = trim((string) $this->input->post('answer', true));
        if ($answer === '') { echo json_encode(['status' => '0', 'message' => 'Answer is empty']); return; }
        if (!$this->db->table_exists('ai_faq')) { echo json_encode(['status' => '0', 'message' => 'FAQ store missing']); return; }

        $q = $this->db->from('ai_unanswered_questions')->where('id', $id)->where('user_id', $this->uid)->get()->row_array();
        if (empty($q)) { echo json_encode(['status' => '0', 'message' => 'Question not found']); return; }

        // scope to the question's page when known, else all pages (page_id NULL)
        $page = trim((string) ($q['page_id'] ?? ''));
        $this->basic->insert_data('ai_faq', array(
            'user_id'    => $this->uid,
            'page_id'    => $page !== '' && $page !== 'webchat' ? $page : null,
            'question'   => (string) $q['question'],
            'answer'     => $answer,
            'status'     => '1',
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $this->db->where('id', $id)->where('user_id', $this->uid)
            ->update('ai_unanswered_questions', array('status' => 'resolved'));
        echo json_encode(['status' => '1', 'message' => 'Answer added — the bot will use it right away.']);
    }

    public function delete()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id', true);
        $this->db->where('id', $id)->where('user_id', $this->uid)->delete('ai_unanswered_questions');
        echo json_encode(['status' => '1']);
    }
}
