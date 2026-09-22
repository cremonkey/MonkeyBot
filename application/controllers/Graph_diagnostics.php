<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");
require_once(APPPATH . 'libraries/Channels/Http.php');

use MonkeyBot\Channels\HttpRequest;
use MonkeyBot\Channels\HttpTransport;
use MonkeyBot\Channels\CurlTransport;

/**
 * Graph API v20.0 -> v26.0 migration (Phase 3) -- diagnostic tool.
 *
 * The safe, real-data substitute for a live test this sandbox cannot run: for
 * ONE connected page the current tenant owns, make a small set of read-only,
 * side-effect-free Graph calls on BOTH today's pinned legacy version
 * (fb_graph_version('legacy'), v20.0) and the candidate migration target
 * (fb_graph_version('default'), v26.0), and show the two raw responses plus a
 * diff. An owner runs this once per group, on one real page, before flipping
 * that group's fb_graph_group_<group>_version config key -- see
 * docs/GRAPH_API_MIGRATION.md Phase 3 for the full runbook.
 *
 * Deliberately does NOT call the group's real endpoint (e.g. it never posts
 * to me/messages to "test" the send group, never touches me/messenger_profile
 * to "test" that group). It only ever calls two universally-safe read
 * endpoints -- /{page_id}?fields=id,name and /me/picture?redirect=false -- so
 * running this tool can never send a message, change a page setting, or have
 * any other side effect, regardless of which group is being previewed. The
 * chosen group only labels the report and names the config key it maps to.
 *
 * Real network calls happen ONLY inside run(), and ONLY when an admin POSTs
 * an explicit page_row_id + group. index() never calls the network. Both
 * actions are gated on user_type === 'Admin' (same pattern as Agency.php:
 * comparing raw API output is an ops/debugging action, not a tenant
 * feature). Tests inject a fake HttpTransport via setHttpTransport() (the
 * same seam application/libraries/Channels/Http.php already uses for the
 * channel adapters) so no test ever reaches graph.facebook.com.
 */
class Graph_diagnostics extends Home
{
    /** @var HttpTransport */
    protected $http_transport;

    /** Mirrors fb_graph_group_version()'s flippable group list (fb_graph_helper.php). */
    const KNOWN_GROUPS = array('reads', 'send', 'messenger_profile', 'oauth', 'insights', 'labels', 'page_admin');

    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');

        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->http_transport = new CurlTransport();
    }

    /**
     * Test seam. Production never calls this; the constructor already wired
     * a real CurlTransport. Tests inject a fake here so run() cannot reach
     * the real network no matter what it is asked to compare.
     */
    public function setHttpTransport(HttpTransport $transport)
    {
        $this->http_transport = $transport;
    }

    /** GET: pick a connected page and a group. No network call. */
    public function index()
    {
        $data['pages'] = $this->_connected_pages();
        $data['groups'] = self::KNOWN_GROUPS;
        $data['legacy_version'] = fb_graph_version('legacy');
        $data['candidate_version'] = fb_graph_version('default');
        $data['page_title'] = 'Graph API diagnostics';
        $data['body'] = 'admin/graph_diagnostics/index';
        $this->_viewcontroller($data);
    }

    /**
     * POST only: runs the side-effect-free comparison for ONE explicitly
     * chosen page + group. Refuses (no network call made) if the request
     * is not a POST, or no page was chosen, or the page does not belong to
     * this tenant, or the group name is not recognised.
     */
    public function run()
    {
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST')
        {
            show_error('Graph diagnostics only runs on an explicit POST. No page was chosen.', 400);
            return;
        }

        $page_row_id = (int) $this->input->post('page_row_id');
        $group       = (string) $this->input->post('group');

        if ($page_row_id <= 0)
        {
            show_error('Choose a connected page before running Graph diagnostics.', 400);
            return;
        }
        if (!in_array($group, self::KNOWN_GROUPS, TRUE))
        {
            show_error('Unknown diagnostics group: ' . $group, 400);
            return;
        }

        $page = $this->_connected_page($page_row_id);
        if ($page === null)
        {
            show_error('That page is not connected to your account.', 404);
            return;
        }

        $legacy_version    = fb_graph_version('legacy');
        $candidate_version = fb_graph_version('default');

        // Universally-safe, side-effect-free probes. Same two for every group;
        // the group never changes which endpoint is called, only the report label.
        $token = (string) $page['page_access_token'];
        $probes = array(
            'page_fields' => rawurlencode((string) $page['page_id']) . '?fields=id,name&access_token=' . rawurlencode($token),
            'me_picture'  => 'me/picture?redirect=false&access_token=' . rawurlencode($token),
        );

        $results = array();
        foreach ($probes as $label => $path)
        {
            $results[$label] = array(
                'path_redacted' => preg_replace('/access_token=[^&]+/', 'access_token=REDACTED', $path),
                'legacy'        => $this->_probe($legacy_version, $path),
                'candidate'     => $this->_probe($candidate_version, $path),
            );
            $results[$label]['diff'] = $this->_diff($results[$label]['legacy'], $results[$label]['candidate']);
        }

        $data['page']              = array('id' => $page['page_id'], 'name' => $page['page_name']);
        $data['group']             = $group;
        $data['config_key']        = 'fb_graph_group_' . $group . '_version';
        $data['legacy_version']    = $legacy_version;
        $data['candidate_version'] = $candidate_version;
        $data['results']           = $results;
        $data['page_title']        = 'Graph API diagnostics -- result';
        $data['body']              = 'admin/graph_diagnostics/result';
        $this->_viewcontroller($data);
    }

    /** One GET request against a specific Graph version. Never throws. */
    protected function _probe($version, $path)
    {
        $url = 'https://graph.facebook.com/' . $version . '/' . ltrim((string) $path, '/');
        $req = new HttpRequest('GET', $url, array('Content-type: application/json'), null, 20, null, true, null);
        $res = $this->http_transport->send($req);
        return array(
            'status' => $res->status,
            'error'  => $res->error,
            'body'   => $res->json(),
        );
    }

    /** Shallow key-by-key diff of two probe results' decoded bodies, plus HTTP status. */
    protected function _diff($legacy, $candidate)
    {
        $lb = is_array($legacy['body']) ? $legacy['body'] : array();
        $cb = is_array($candidate['body']) ? $candidate['body'] : array();
        $keys = array_unique(array_merge(array_keys($lb), array_keys($cb)));
        $diff = array();
        foreach ($keys as $k)
        {
            $lv = array_key_exists($k, $lb) ? $lb[$k] : null;
            $cv = array_key_exists($k, $cb) ? $cb[$k] : null;
            if ($lv !== $cv) $diff[$k] = array('legacy' => $lv, 'candidate' => $cv);
        }
        if ($legacy['status'] !== $candidate['status'])
        {
            $diff['__http_status'] = array('legacy' => $legacy['status'], 'candidate' => $candidate['status']);
        }
        return $diff;
    }

    /** Connected FB/IG pages for the CURRENT tenant only (never cross-tenant). */
    protected function _connected_pages()
    {
        if (!$this->db->table_exists('facebook_rx_fb_page_info')) return array();
        return $this->db->select('id, page_id, page_name')
            ->from('facebook_rx_fb_page_info')
            ->where('user_id', $this->uid)
            ->where('deleted', '0')
            ->order_by('page_name', 'ASC')
            ->get()->result_array();
    }

    /** One connected page, scoped to the current tenant, or NULL. */
    protected function _connected_page($row_id)
    {
        if (!$this->db->table_exists('facebook_rx_fb_page_info')) return null;
        $row = $this->db->select('id, page_id, page_name, page_access_token')
            ->from('facebook_rx_fb_page_info')
            ->where('id', $row_id)
            ->where('user_id', $this->uid)
            ->where('deleted', '0')
            ->get()->row_array();
        return $row ?: null;
    }
}

/* End of file Graph_diagnostics.php */
/* Location: ./application/controllers/Graph_diagnostics.php */
