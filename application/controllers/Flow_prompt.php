<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Prompt-to-Flow (master brief §24) — "Describe what you want the bot to do", AI drafts a
 * flow, a human reviews and saves it in the real Visual Flow Builder editor.
 *
 * Access control deliberately mirrors application/modules/visual_flow_builder/controllers/
 * Visual_flow_builder.php's OWN legacy module-permission system (module id 315), NOT
 * application/helpers/entitlement_helper.php — Visual Flow Builder never used that system
 * (confirmed by full-codebase grep during design), and generated drafts land in the exact
 * same visual_flow_builder_campaign table a human-drawn flow does, so they must be gated
 * the same way a human-drawn flow already is (copy_builder_data(), duplicate_builder_data()).
 *
 * media_type is resolved server-side, never trusted from the client, mirroring
 * Visual_flow_builder::resolve_builder_media_type() exactly (that method is protected on a
 * different controller, so its small body is duplicated here rather than modified there —
 * Visual_flow_builder.php is out of scope for this change).
 */
class Flow_prompt extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
    }

    /** Visual_flow_builder::resolve_builder_media_type(), duplicated read-only (see class docblock). */
    protected function resolve_media_type($page_table_id, $instagram_bot_addon = 0)
    {
        if ($this->db->table_exists('openwa_accounts')) {
            $wa = $this->db->select('id')->from('openwa_accounts')
                ->where('id', (int) $page_table_id)->where('user_id', $this->user_id)->limit(1)->get()->row_array();
            if (!empty($wa)) return 'wa';
        }
        return ((int) $instagram_bot_addon === 1) ? 'ig' : 'fb';
    }

    /**
     * POST only. Generates a DRAFT flow and returns its id + the editor URL to review it in.
     * Never publishes anything: see Flow_prompt_generator's class docblock.
     */
    public function generate()
    {
        header('Content-Type: application/json');
        $this->ajax_check();
        $this->csrf_token_check(); // brief §24 build note: Visual_flow_builder's own AJAX actions
        // never call csrf_token_check() (verified by grep) — this endpoint deliberately does,
        // as a new feature should not inherit that gap.

        if (!check_module_action_access($module_id = 315, $actions = 1, 'check')) {
            echo json_encode(array('status' => 0, 'message' => $this->lang->line('You do not have access to perform this action.'), 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }

        $instruction = trim((string) $this->input->post('instruction', true));
        $page_table_id = $this->input->post('page_table_id', true);
        $instagram_bot_addon = (bool) $this->input->post('instagram_bot_addon', true);

        if ($instruction === '') {
            echo json_encode(array('status' => 0, 'message' => 'Please describe what you want the bot to do.', 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }
        if (mb_strlen($instruction) > 4000) {
            echo json_encode(array('status' => 0, 'message' => 'That description is too long. Please shorten it.', 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }
        if (empty($page_table_id)) {
            echo json_encode(array('status' => 0, 'message' => 'Please select a page or channel first.', 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }

        // tenant scoping (brief: "page_table_id must belong to $this->user_id") — the same
        // facebook_rx_fb_page_info / openwa_accounts ownership Visual_flow_builder itself checks.
        $raw_id = $page_table_id;
        if (is_string($raw_id) && strpos($raw_id, '-') !== false) {
            $parts = explode('-', $raw_id);
            $raw_id = $parts[0];
            if (isset($parts[1]) && $parts[1] === 'ig') $instagram_bot_addon = true;
        }
        $raw_id = (int) $raw_id;

        $owns_fb_page = $this->db->from('facebook_rx_fb_page_info')->where('id', $raw_id)->where('user_id', $this->user_id)->count_all_results() > 0;
        $owns_wa_page = $this->db->table_exists('openwa_accounts')
            && $this->db->from('openwa_accounts')->where('id', $raw_id)->where('user_id', $this->user_id)->count_all_results() > 0;
        if (!$owns_fb_page && !$owns_wa_page) {
            echo json_encode(array('status' => 0, 'message' => 'That page or channel does not belong to this account.', 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }

        $media_type = $this->resolve_media_type($raw_id, $instagram_bot_addon);

        $cfg = $this->basic->get_data('open_ai_config', array('where' => array('user_id' => $this->user_id)));
        if (empty($cfg)) {
            echo json_encode(array('status' => 0, 'message' => 'Configure your AI provider first (Integration → AI Credentials), then try again.', 'id' => null, 'edit_url' => null, 'substitutions' => array()));
            return;
        }

        // TEST-ONLY seam (see application/config/config.php's prompt_to_flow_test_fixture
        // docblock): always FALSE in production, flipped only by
        // docker/scripts/staging_prompt_to_flow_tests.sh so the real endpoint can be proven
        // end-to-end without a real AI provider API key.
        $fixture_name = $this->config->item('prompt_to_flow_test_fixture');
        if (!empty($fixture_name)) {
            $this->load->helper('flow_prompt_test_fixture');
            $fixture_text = flow_prompt_test_fixture($fixture_name);
            $this->load->library('Flow_prompt_generator', array('ai_caller' => function ($cfg2, $messages, $overrides) use ($fixture_text) {
                return json_encode(array('choices' => array(array('text' => $fixture_text))));
            }));
        } else {
            $this->load->library('Flow_prompt_generator');
        }
        $result = $this->flow_prompt_generator->generate($this->user_id, $raw_id, $media_type, $instruction, $cfg[0]);

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
