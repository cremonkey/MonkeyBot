<?php
/**
 * TikTok Bot cron endpoints.
 * Called by external cron every 5 minutes:
 *   curl https://bot.cremonkey.com/tiktok_bot/tiktok_cron/poll_comments >/dev/null 2>&1
 */
require_once(APPPATH . "controllers/Home.php");

class Tiktok_cron extends Home
{
    public function __construct()
    {
        parent::__construct();
        set_time_limit(0);
        $this->load->config('tiktok');
        $this->load->library('Tiktok_api');
        $this->load->helper(array('ai_knowledge', 'my_helper'));
    }

    /**
     * Entry point: poll comments for all active campaigns.
     */
    public function poll_comments()
    {
        $campaigns = $this->basic->get_data('tiktok_campaigns', array(
            'where' => array(
                'campaign_type' => 'comment',
                'status'        => 'active'
            )
        ));

        if (empty($campaigns)) {
            echo "No active comment campaigns.\n";
            return;
        }

        foreach ($campaigns as $campaign) {
            $this->_process_comment_campaign($campaign);
        }
        echo "Comment polling completed.\n";
    }

    /**
     * Poll DMs (feature-flagged, currently returns early).
     */
    public function poll_dms()
    {
        if (!$this->config->item('tiktok_dm_enabled')) {
            echo "DM polling is disabled.\n";
            return;
        }
        $campaigns = $this->basic->get_data('tiktok_campaigns', array(
            'where' => array(
                'campaign_type' => 'dm',
                'status'        => 'active'
            )
        ));
        if (empty($campaigns)) {
            echo "No active DM campaigns.\n";
            return;
        }
        foreach ($campaigns as $campaign) {
            $this->_report($campaign, '', '', 'TikTok Messaging API requires partner approval.', 'failed');
        }
        echo "DM polling completed.\n";
    }

    /**
     * Process a single comment campaign.
     */
    protected function _process_comment_campaign($campaign)
    {
        $account = $this->basic->get_data('tiktok_accounts', array('where' => array('id' => $campaign['account_id'])), array('*'));
        if (empty($account)) {
            return;
        }
        $account = $account[0];

        // Refresh token if needed
        $account = $this->_ensure_valid_token($account);
        if (empty($account) || empty($account['access_token'])) {
            return;
        }

        // Fetch recent videos. Display API v2 and Business API both return
        // data.videos; keep data.list as a fallback for older payloads.
        $videos_result = $this->tiktok_api->get_videos($account['access_token'], $account['open_id'], 0, 20);
        if ($videos_result['status'] != '1') {
            // surface the API error on the reports page, but at most once/hour
            $recent = $this->basic->get_data('tiktok_reply_reports', array('where' => array(
                'campaign_id' => $campaign['id'], 'status' => 'failed',
                'created_at >' => date('Y-m-d H:i:s', time() - 3600)
            )), array('id'), '', 1);
            if (empty($recent)) {
                $this->_report($campaign, '', '', 'video list: ' . $videos_result['message'], 'failed');
            }
            return;
        }
        $videos = array();
        if (!empty($videos_result['data']['videos'])) $videos = $videos_result['data']['videos'];
        elseif (!empty($videos_result['data']['list'])) $videos = $videos_result['data']['list'];
        if (empty($videos)) {
            return;
        }

        foreach ($videos as $video) {
            // display v2 uses id, business uses item_id
            $video_id = isset($video['id']) ? $video['id'] : (isset($video['item_id']) ? $video['item_id'] : (isset($video['video_id']) ? $video['video_id'] : ''));
            if (empty($video_id)) {
                continue;
            }
            $this->_process_video_comments($campaign, $account, $video_id);
        }
    }

    /**
     * Fetch and reply to comments for a specific video.
     */
    protected function _process_video_comments($campaign, $account, $video_id)
    {
        $comments_result = $this->tiktok_api->get_business_comments($account['access_token'], $account['open_id'], $video_id, 0, 50);
        if ($comments_result['status'] != '1' || empty($comments_result['data']['comments'])) {
            return;
        }

        foreach ($comments_result['data']['comments'] as $comment) {
            $comment_id = isset($comment['comment_id']) ? $comment['comment_id'] : '';
            $comment_text = isset($comment['text']) ? $comment['text'] : '';
            if (empty($comment_id) || empty($comment_text)) {
                continue;
            }
            // never reply to the account's own comments
            if (isset($comment['user_id']) && (string)$comment['user_id'] === (string)$account['open_id']) {
                continue;
            }
            // dedup: skip comments we already replied to successfully
            $already = $this->basic->get_data('tiktok_reply_reports', array('where' => array('comment_id' => $comment_id, 'status' => 'success')), array('id'), '', 1);
            if (!empty($already)) {
                continue;
            }

            $reply_text = $this->_build_reply($campaign, $comment_text, $account);
            if (empty($reply_text)) {
                continue;
            }

            $result = $this->tiktok_api->reply_to_comment($account['access_token'], $account['open_id'], $comment_id, $reply_text, $video_id);
            if ($result['status'] == '1') {
                $this->_report($campaign, $comment_text, $reply_text, json_encode($result['data']), 'success', $comment_id);
            } else {
                $this->_report($campaign, $comment_text, $reply_text, $result['message'], 'failed', $comment_id);
            }

            // Avoid hitting rate limits; sleep briefly between replies
            sleep(1);
        }
    }

    /**
     * Build reply text based on campaign type.
     */
    protected function _build_reply($campaign, $trigger_text, $account)
    {
        if ($campaign['reply_type'] == 'text') {
            return $campaign['auto_reply_text'];
        }

        // AI reply
        $description = !empty($campaign['ai_training_data']) ? $campaign['ai_training_data'] : 'You are a helpful assistant.';
        $response = $this->get_ai_reply_open_ai($description, "Human: " . $trigger_text, $campaign['user_id'], '', '', 'tiktok');
        if (is_array($response) && isset($response['choices'][0]['text'])) {
            return trim($response['choices'][0]['text']);
        }
        if (is_array($response) && isset($response['message'])) {
            return '';
        }
        return is_string($response) ? $response : '';
    }

    /**
     * Ensure access token is valid; refresh if close to expiry.
     */
    protected function _ensure_valid_token($account)
    {
        if (empty($account['refresh_token'])) {
            return $account;
        }
        $expires_at = strtotime($account['expires_at']);
        if ($expires_at && ($expires_at - time()) < 600) {
            $result = $this->tiktok_api->refresh_access_token($account['refresh_token']);
            if ($result['status'] == '1') {
                $data = $result['data'];
                $update = array(
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'expires_at'    => date('Y-m-d H:i:s', time() + (int)$data['expires_in']),
                    'updated_at'    => date('Y-m-d H:i:s')
                );
                $this->basic->update_data('tiktok_accounts', array('id' => $account['id']), $update);
                $account = array_merge($account, $update);
            } else {
                $this->basic->update_data('tiktok_accounts', array('id' => $account['id']), array('updated_at' => date('Y-m-d H:i:s')));
                return false;
            }
        }
        return $account;
    }

    /**
     * Log a report row.
     */
    protected function _report($campaign, $trigger_text, $reply_text, $api_response, $status, $comment_id = null)
    {
        $this->basic->insert_data('tiktok_reply_reports', array(
            'user_id'      => $campaign['user_id'],
            'campaign_id'  => $campaign['id'],
            'account_id'   => $campaign['account_id'],
            'comment_id'   => $comment_id,
            'trigger_text' => $trigger_text,
            'reply_text'   => $reply_text,
            'api_response' => $api_response,
            'status'       => $status,
            'created_at'   => date('Y-m-d H:i:s')
        ));
    }
}
