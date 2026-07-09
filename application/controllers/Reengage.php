<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-19 — Re-engagement broadcasts.
 *
 * Every campaign is a draft until the operator has seen the reach counter and
 * explicitly started it. The counter is the whole point of this screen: on a
 * page whose conversations are older than 24 hours, most campaigns can
 * legitimately reach almost nobody, and the operator must see that number
 * BEFORE pressing send rather than discover it in the report afterwards.
 *
 * All data scoped to the logged-in account; every write validates ownership.
 */
class Reengage extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->helper('reengage');
    }

    /** @return array|null the campaign row, or null when it is not this user's */
    private function owns($campaign_id)
    {
        $row = $this->db->from('reengage_campaign')
            ->where('id', (int) $campaign_id)->where('user_id', $this->uid)
            ->get()->row_array();
        return $row ?: null;
    }

    public function index()
    {
        $data['campaigns'] = $this->db->select('c.*,
                (SELECT COUNT(*) FROM reengage_recipient r WHERE r.campaign_id=c.id) AS total,
                (SELECT COUNT(*) FROM reengage_recipient r WHERE r.campaign_id=c.id AND r.state="sent") AS sent,
                (SELECT COUNT(*) FROM reengage_recipient r WHERE r.campaign_id=c.id AND r.state="waiting_reentry") AS queued', false)
            ->from('reengage_campaign c')
            ->where('c.user_id', $this->uid)
            ->order_by('c.id', 'DESC')
            ->get()->result_array();

        $data['pages'] = $this->pages();
        $data['body'] = 'admin/reengage/index';
        $data['page_title'] = 'Re-engagement';
        $this->_viewcontroller($data);
    }

    private function pages()
    {
        return $this->db->select('id, page_id, page_name, has_instagram, instagram_business_account_id')
            ->from('facebook_rx_fb_page_info')
            ->where('user_id', $this->uid)->where('deleted', '0')
            ->get()->result_array();
    }

    /**
     * Live reach counter. Writes nothing, sends nothing.
     * The numbers here are produced by the same query build() uses.
     */
    public function preview()
    {
        $this->csrf_token_check();
        $this->load->library('reengage_audience');

        $counts = $this->reengage_audience->preview($this->uid, $this->filters_from_post());

        echo json_encode(array('status' => 'ok', 'counts' => $counts));
    }

    private function filters_from_post()
    {
        $filters = array(
            'social_media'  => $this->input->post('social_media'),
            'page_table_id' => (int) $this->input->post('page_table_id'),
            'crm_mode'      => $this->input->post('crm_mode') ?: 'any',
        );

        foreach (array('quiet_for_days', 'active_within_days', 'lead_score_min', 'lead_score_max') as $k) {
            $v = $this->input->post($k);
            if ($v !== null && $v !== '') $filters[$k] = (int) $v;
        }
        foreach (array('crm_stage_ids', 'label_ids', 'excluded_label_ids') as $k) {
            $v = $this->input->post($k);
            if (!empty($v)) $filters[$k] = is_array($v) ? $v : explode(',', $v);
        }

        return $filters;
    }

    /** Create or update a campaign. Always lands in draft. */
    public function save()
    {
        $this->csrf_token_check();

        $id = (int) $this->input->post('id');
        $text = trim((string) $this->input->post('message_text'));
        if ($text === '') {
            echo json_encode(array('status' => 'error', 'message' => 'Message text is required'));
            return;
        }

        $row = array(
            'user_id'       => $this->uid,
            'name'          => trim((string) $this->input->post('name')) ?: 'Untitled',
            'social_media'  => in_array($this->input->post('social_media'), array('fb', 'ig', 'web'), true)
                                 ? $this->input->post('social_media') : 'fb',
            'page_table_id' => (int) $this->input->post('page_table_id'),
            'message_json'  => json_encode($this->message_payload($text, $this->input->post('buttons'))),
            'filters_json'  => json_encode($this->filters_from_post()),
            'messages_per_hour'    => max(1, (int) $this->input->post('messages_per_hour')),
            'jitter_min_sec'       => max(0, (int) $this->input->post('jitter_min_sec')),
            'jitter_max_sec'       => max(0, (int) $this->input->post('jitter_max_sec')),
            'daily_cap'            => max(0, (int) $this->input->post('daily_cap')),
            'quiet_start'          => $this->input->post('quiet_start') ?: '22:00:00',
            'quiet_end'            => $this->input->post('quiet_end') ?: '08:00:00',
            'timezone'             => $this->input->post('timezone') ?: 'Africa/Cairo',
            'queue_ttl_days'       => max(1, (int) $this->input->post('queue_ttl_days')),
            'reentry_idle_minutes' => max(0, (int) $this->input->post('reentry_idle_minutes')),
            'schedule_time'        => $this->input->post('schedule_time') ?: null,
        );

        $variant_b = trim((string) $this->input->post('variant_b_text'));
        $row['variant_b_json'] = ($variant_b !== '') ? json_encode(array('text' => $variant_b)) : null;

        $page = $this->db->from('facebook_rx_fb_page_info')
            ->where('id', $row['page_table_id'])->where('user_id', $this->uid)->get()->row_array();
        if ($page) $row['fb_page_id'] = $page['page_id'];
        elseif ($row['social_media'] !== 'web') {
            echo json_encode(array('status' => 'error', 'message' => 'Select a page you own'));
            return;
        }

        if ($id > 0) {
            if (!$this->owns($id)) { echo json_encode(array('status' => 'error', 'message' => 'Not found')); return; }
            // Editing the message of a running campaign would mean some
            // recipients got one thing and some another. Force it back to draft.
            $row['status'] = 'draft';
            $this->db->where('id', $id)->where('user_id', $this->uid)->update('reengage_campaign', $row);
        } else {
            $row['status'] = 'draft';
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $this->db->insert('reengage_campaign', $row);
            $id = $this->db->insert_id();
        }

        echo json_encode(array('status' => 'ok', 'id' => $id));
    }

    private function message_payload($text, $buttons_raw)
    {
        $buttons = json_decode((string) $buttons_raw, true);
        if (!is_array($buttons) || empty($buttons)) return array('text' => $text);

        $clean = array();
        foreach ($buttons as $b) {
            if (empty($b['title'])) continue;
            if (!empty($b['url'])) {
                $clean[] = array('type' => 'web_url', 'url' => $b['url'], 'title' => mb_substr($b['title'], 0, 20));
            } else {
                $clean[] = array('type' => 'postback', 'title' => mb_substr($b['title'], 0, 20),
                                 'payload' => !empty($b['payload']) ? $b['payload'] : 'REENGAGE_' . count($clean));
            }
            if (count($clean) === 3) break; // Messenger caps button templates at 3
        }

        if (empty($clean)) return array('text' => $text);

        return array('attachment' => array(
            'type' => 'template',
            'payload' => array('template_type' => 'button', 'text' => $text, 'buttons' => $clean),
        ));
    }

    /**
     * Dry run: materialise the recipient list and report the buckets.
     * Nothing is sent. The campaign stays in draft.
     */
    public function build_audience()
    {
        $this->csrf_token_check();

        $campaign = $this->owns($this->input->post('id'));
        if (!$campaign) { echo json_encode(array('status' => 'error', 'message' => 'Not found')); return; }

        if (in_array($campaign['status'], array('running', 'done'), true)) {
            echo json_encode(array('status' => 'error', 'message' => 'Pause the campaign before rebuilding its audience'));
            return;
        }

        $this->load->library('reengage_audience');
        $counts = $this->reengage_audience->build($campaign);

        echo json_encode(array('status' => 'ok', 'counts' => $counts));
    }

    /**
     * Arm the campaign. Deliberately separate from build_audience(): the
     * operator has to look at the reach counter and act again.
     */
    public function start()
    {
        $this->csrf_token_check();

        $campaign = $this->owns($this->input->post('id'));
        if (!$campaign) { echo json_encode(array('status' => 'error', 'message' => 'Not found')); return; }

        $queued = $this->db->from('reengage_recipient')
            ->where('campaign_id', $campaign['id'])
            ->where_in('state', array('pending', 'waiting_reentry', 'reentered'))
            ->count_all_results();

        if ($queued === 0) {
            echo json_encode(array('status' => 'error', 'message' => 'Build the audience first — nothing is queued'));
            return;
        }

        $this->db->where('id', $campaign['id'])->where('user_id', $this->uid)
            ->update('reengage_campaign', array('status' => 'running', 'halt_reason' => '', 'consecutive_errors' => 0));

        echo json_encode(array('status' => 'ok'));
    }

    /** Kill switch. The cron reads this every tick; worst case 5 minutes. */
    public function pause()
    {
        $this->csrf_token_check();

        $campaign = $this->owns($this->input->post('id'));
        if (!$campaign) { echo json_encode(array('status' => 'error', 'message' => 'Not found')); return; }

        $this->db->where('id', $campaign['id'])->where('user_id', $this->uid)
            ->update('reengage_campaign', array('status' => 'paused'));

        echo json_encode(array('status' => 'ok'));
    }

    /** Pull historical contacts from Graph for one page + platform. */
    public function import()
    {
        $this->csrf_token_check();

        $page_table_id = (int) $this->input->post('page_table_id');
        $social_media = ($this->input->post('social_media') === 'ig') ? 'ig' : 'fb';

        $page = $this->db->from('facebook_rx_fb_page_info')
            ->where('id', $page_table_id)->where('user_id', $this->uid)->get()->row_array();
        if (!$page) { echo json_encode(array('status' => 'error', 'message' => 'Select a page you own')); return; }

        $this->db->insert('reengage_import_run', array(
            'user_id' => $this->uid, 'page_table_id' => $page_table_id,
            'social_media' => $social_media, 'status' => 'running',
            'started_at' => gmdate('Y-m-d H:i:s'),
        ));
        $run_id = $this->db->insert_id();

        $this->load->library('reengage_import');
        $result = $this->reengage_import->import_page($this->uid, $page_table_id, $social_media, $run_id);

        $this->db->where('id', $run_id)->update('reengage_import_run', array(
            'status' => $result['ok'] ? 'done' : 'failed',
            'error_message' => $result['error'],
            'thread_count' => $result['threads'],
            'imported_count' => $result['imported'],
            'updated_count' => $result['updated'],
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ));

        echo json_encode(array('status' => $result['ok'] ? 'ok' : 'error', 'result' => $result));
    }

    public function report($id = 0)
    {
        $campaign = $this->owns($id);
        if (!$campaign) show_404();

        $data['campaign'] = $campaign;

        $data['buckets'] = $this->db->select('state, COUNT(*) AS c', false)
            ->from('reengage_recipient')->where('campaign_id', $campaign['id'])
            ->group_by('state')->get()->result_array();

        $data['variants'] = $this->db->select('ab_variant, COUNT(*) AS total,
                SUM(state="sent") AS sent', false)
            ->from('reengage_recipient')->where('campaign_id', $campaign['id'])
            ->group_by('ab_variant')->get()->result_array();

        $data['errors'] = $this->db->select('error_code, error_message, COUNT(*) AS c', false)
            ->from('reengage_recipient')->where('campaign_id', $campaign['id'])
            ->where('error_code !=', '')->group_by('error_code, error_message')
            ->order_by('c', 'DESC')->limit(20)->get()->result_array();

        // Contacts a human must answer by hand; the engine will never send here.
        $data['needs_human'] = $this->db->select('r.subscribe_id,
                TRIM(CONCAT(COALESCE(s.first_name,"")," ",COALESCE(s.last_name,""))) AS name,
                s.last_subscriber_interaction_time AS last_inbound', false)
            ->from('reengage_recipient r')
            ->join('messenger_bot_subscriber s', 's.id = r.subscriber_auto_id', 'left')
            ->where('r.campaign_id', $campaign['id'])
            ->where('r.skip_reason', 'needs_human_reply')
            ->limit(50)->get()->result_array();

        $data['body'] = 'admin/reengage/report';
        $data['page_title'] = 'Re-engagement report';
        $this->_viewcontroller($data);
    }
}
