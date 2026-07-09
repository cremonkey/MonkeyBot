<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-19: import historical FB/IG conversations into messenger_bot_subscriber
 * so past customers can be targeted by a re-engagement campaign.
 *
 * Why this is not just a wrapper around the existing conversation fetch:
 *
 * 1. `updated_time` on a thread moves when EITHER side posts. A thread the page
 *    replied to yesterday, for a customer who last wrote three months ago, has
 *    updated_time = yesterday. Storing that as last_subscriber_interaction_time
 *    makes the classifier say in_window, and the send is then rejected by Meta
 *    as an out-of-window message -- exactly the violation the whole design
 *    exists to prevent. So we request the messages themselves and take the last
 *    one whose sender is not us.
 *
 * 2. Identifying "us" differs per platform. On Instagram the page participant is
 *    the IG business account id, not the FB page id, so the existing
 *    get_conversations() comparison against the FB page id treats our own
 *    account as a customer. We exclude both ids.
 *
 * If no inbound message can be established, the contact is imported with a null
 * interaction time, which the classifier reads as out_of_window. Conservative on
 * purpose: an unreachable contact costs nothing, a wrongly-reachable one costs
 * the page.
 */
class Reengage_import
{
    /** Messages fetched per thread when hunting for the last inbound one. */
    const MESSAGE_LOOKBACK = 25;

    /** Safety valve so a runaway pagination loop cannot hang a request. */
    const MAX_PAGES = 200;

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->helper('reengage');
    }

    /**
     * Import every conversation for one page/platform.
     *
     * @param int    $user_id
     * @param int    $page_table_id  facebook_rx_fb_page_info.id
     * @param string $social_media   'fb' | 'ig'
     * @param int    $run_id         reengage_import_run.id, for cursor persistence
     * @return array  ['ok'=>bool,'imported'=>int,'updated'=>int,'threads'=>int,'error'=>string]
     */
    public function import_page($user_id, $page_table_id, $social_media, $run_id = 0)
    {
        $result = array('ok' => false, 'imported' => 0, 'updated' => 0, 'threads' => 0, 'error' => '');

        $user_id = (int) $user_id;
        $page_table_id = (int) $page_table_id;
        $social_media = ($social_media === 'ig') ? 'ig' : 'fb';

        $rows = $this->ci->basic->get_data(
            'facebook_rx_fb_page_info',
            array('where' => array('id' => $page_table_id, 'user_id' => $user_id))
        );
        if (empty($rows)) {
            $result['error'] = 'page not found for this user';
            return $result;
        }
        $page = $rows[0];

        $token = $page['page_access_token'];
        $fb_page_id = (string) $page['page_id'];
        $ig_account_id = isset($page['instagram_business_account_id']) ? (string) $page['instagram_business_account_id'] : '';

        if ($token === '') {
            $result['error'] = 'page has no access token';
            return $result;
        }
        if ($social_media === 'ig' && $ig_account_id === '') {
            $result['error'] = 'page has no linked instagram account';
            return $result;
        }

        // Every id that means "the business", not "the customer".
        $own_ids = array($fb_page_id);
        if ($ig_account_id !== '') $own_ids[] = $ig_account_id;

        $this->ci->load->library('fb_rx_login');

        $url = $this->build_url($fb_page_id, $token, $social_media, $run_id);
        $pages_walked = 0;

        while ($url !== '' && $pages_walked < self::MAX_PAGES) {
            $pages_walked++;

            $raw = $this->ci->fb_rx_login->run_curl_for_fb($url);
            $response = json_decode($raw, true);

            if (!is_array($response)) {
                $result['error'] = 'unreadable Graph response';
                return $result;
            }
            if (isset($response['error'])) {
                $result['error'] = isset($response['error']['message'])
                    ? $response['error']['message']
                    : 'Graph error';
                return $result;
            }
            if (!isset($response['data']) || !is_array($response['data'])) break;

            foreach ($response['data'] as $thread) {
                $result['threads']++;

                $contact = $this->extract_contact($thread, $own_ids, $social_media);
                if ($contact === null) continue;

                $last_inbound = $this->last_inbound_time($thread, $own_ids);

                $written = $this->upsert_subscriber($user_id, $page_table_id, $fb_page_id, $social_media, $contact, $last_inbound);
                if ($written === 'inserted') $result['imported']++;
                elseif ($written === 'updated') $result['updated']++;
            }

            $next = isset($response['paging']['next']) ? $response['paging']['next'] : '';
            $url = is_string($next) ? $next : '';

            if ($run_id > 0) {
                $this->ci->basic->update_data('reengage_import_run', array('id' => $run_id), array(
                    'cursor_after'   => $url,
                    'thread_count'   => $result['threads'],
                    'imported_count' => $result['imported'],
                    'updated_count'  => $result['updated'],
                ));
            }
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * Resume from a stored cursor if the previous run was interrupted, otherwise
     * start a fresh walk.
     */
    private function build_url($fb_page_id, $token, $social_media, $run_id)
    {
        if ($run_id > 0) {
            $runs = $this->ci->basic->get_data('reengage_import_run', array('where' => array('id' => $run_id)));
            if (!empty($runs) && !empty($runs[0]['cursor_after'])) return $runs[0]['cursor_after'];
        }

        $fields = 'participants,updated_time,id,messages.limit(' . self::MESSAGE_LOOKBACK . '){from,created_time}';

        $url = 'https://graph.facebook.com/v19.0/' . $fb_page_id . '/conversations'
             . '?access_token=' . urlencode($token)
             . '&limit=100'
             . '&fields=' . $fields;

        if ($social_media === 'ig') $url .= '&platform=instagram';

        return $url;
    }

    /**
     * The participant who is not us. Returns null when the thread has no such
     * participant (group threads, or our own echo).
     *
     * @return array|null ['id'=>string,'name'=>string]
     */
    private function extract_contact($thread, $own_ids, $social_media)
    {
        if (!isset($thread['participants']['data']) || !is_array($thread['participants']['data'])) return null;

        foreach ($thread['participants']['data'] as $participant) {
            if (!isset($participant['id'])) continue;
            $pid = (string) $participant['id'];
            if (in_array($pid, $own_ids, true)) continue;

            $name = '';
            if ($social_media === 'ig') {
                if (isset($participant['username'])) $name = $participant['username'];
            } elseif (isset($participant['name'])) {
                $name = $participant['name'];
            }

            return array('id' => $pid, 'name' => (string) $name);
        }

        return null;
    }

    /**
     * Timestamp of the customer's most recent message to us. This -- not
     * updated_time -- is what Meta's 24h window is measured from.
     *
     * @return string|null 'Y-m-d H:i:s' (UTC), or null if none found
     */
    private function last_inbound_time($thread, $own_ids)
    {
        if (!isset($thread['messages']['data']) || !is_array($thread['messages']['data'])) return null;

        $newest = null;

        foreach ($thread['messages']['data'] as $message) {
            if (!isset($message['from']['id']) || !isset($message['created_time'])) continue;

            $from = (string) $message['from']['id'];
            if (in_array($from, $own_ids, true)) continue; // outbound, ignore

            $ts = strtotime($message['created_time']);
            if ($ts === false || $ts <= 0) continue;

            if ($newest === null || $ts > $newest) $newest = $ts;
        }

        if ($newest === null) return null;

        return gmdate('Y-m-d H:i:s', $newest);
    }

    /**
     * Insert or refresh the subscriber row.
     *
     * An existing subscriber's last_subscriber_interaction_time is only moved
     * FORWARD. The webhook is the authority on live conversations; an import
     * must never drag a fresh contact backwards into an older bucket, and must
     * never push a stale one forward.
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    private function upsert_subscriber($user_id, $page_table_id, $fb_page_id, $social_media, $contact, $last_inbound)
    {
        $existing = $this->ci->basic->get_data('messenger_bot_subscriber', array(
            'where' => array(
                'user_id'       => $user_id,
                'page_table_id' => $page_table_id,
                'subscribe_id'  => $contact['id'],
                'social_media'  => $social_media,
            ),
        ), array('id', 'last_subscriber_interaction_time'));

        $name_parts = $this->split_name($contact['name']);

        if (!empty($existing)) {
            $row = $existing[0];
            $update = array();

            $known = reengage_to_timestamp($row['last_subscriber_interaction_time']);
            $found = reengage_to_timestamp($last_inbound);

            if ($found !== null && ($known === null || $found > $known)) {
                $update['last_subscriber_interaction_time'] = $last_inbound;
            }

            if (empty($update)) return 'skipped';

            $this->ci->basic->update_data('messenger_bot_subscriber', array('id' => $row['id']), $update);
            return 'updated';
        }

        $insert = array(
            'user_id'       => $user_id,
            'page_table_id' => $page_table_id,
            'page_id'       => $fb_page_id,
            'subscribe_id'  => $contact['id'],
            'first_name'    => $name_parts[0],
            'last_name'     => $name_parts[1],
            'full_name'     => $contact['name'],
            'social_media'  => $social_media,
            'is_imported'   => '1',
            'is_bot_subscriber' => '0',
            'status'        => '1',
            'subscribed_at' => date('Y-m-d H:i:s'),
        );

        // Left NULL when unknown; the classifier reads that as out_of_window.
        if ($last_inbound !== null) $insert['last_subscriber_interaction_time'] = $last_inbound;

        $this->ci->basic->insert_data('messenger_bot_subscriber', $insert);
        return 'inserted';
    }

    /** @return array [first, last] */
    private function split_name($full)
    {
        $full = trim((string) $full);
        if ($full === '') return array('', '');

        $bits = preg_split('/\s+/', $full, 2);
        $first = isset($bits[0]) ? $bits[0] : '';
        $last = isset($bits[1]) ? $bits[1] : '';
        return array($first, $last);
    }
}
