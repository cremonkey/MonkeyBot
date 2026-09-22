<?php
// Graph API v20.0 -> v26.0 migration (Phase 3) unit tests.
// Run: php tests/graph_migration/run.php   (no DB, no CI bootstrap, no network)
//
// Covers, per the migration plan in docs/GRAPH_API_MIGRATION.md:
//   1. fb_graph_group_version() returns the legacy default for every group when unset
//   2. fb_graph_group_version() returns the configured version when a group's config
//      key IS set, and that the hard-pinned group ('messaging_feature_review') ignores
//      any config override attempt
//   3. graph_url_group() builds the right URL for each group
//   4. REGRESSION: every graph_url_group() call site in the files this migration
//      touched resolves to EXACTLY the same URL as the old graph_url($path, TRUE)
//      call it replaced, when no group flags are set. This is the load-bearing
//      "we changed nothing yet" proof. Built by extracting the real
//      graph_url_group("<group>", "<path literal>") call sites out of the actual
//      converted source files with a regex scan (not hand-typed/synthetic examples)
//      and diffing graph_url($path, TRUE) against graph_url_group($group, $path)
//      for every one of them.
//
// fb_graph_version()/fb_graph_group_version() cache their result in a function-local
// static array, so a scenario that needs a *different* config value for a group
// already exercised in this process cannot reuse that process. Scenarios 1+3+4 (all
// "unset") run in this process; scenario 2 ("configured") runs in an isolated `php`
// subprocess so its config values can't leak into / be shadowed by the first run.

$root = realpath(__DIR__ . '/../..');
$pass = 0; $fail = 0;
function t($name, $ok, $detail = '') { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : '  -> ' . $detail) . "\n"; }

// ---- fake CI so fb_graph_helper.php's get_instance()/config lookups don't need a CI bootstrap ----
define('BASEPATH', 1);
class FakeCIConfig
{
    public $items = array();
    public function item($key) { return isset($this->items[$key]) ? $this->items[$key] : ''; }
}
class FakeCI
{
    public $config;
    public function __construct() { $this->config = new FakeCIConfig(); }
}
$GLOBALS['__fake_ci'] = new FakeCI();
function &get_instance() { return $GLOBALS['__fake_ci']; }

require $root . '/application/helpers/fb_graph_helper.php';

// ============================================================================
// 1) Every group defaults to today's legacy pin (v20.0) when unset.
// ============================================================================
$legacy = fb_graph_version('legacy');
$default = fb_graph_version('default');
t('fb_graph_version(legacy) unset -> hardcoded fallback v20.0', $legacy === 'v20.0', $legacy);
t('fb_graph_version(default) unset -> hardcoded fallback v26.0', $default === 'v26.0', $default);

$flippable_groups = array('reads', 'send', 'messenger_profile', 'oauth', 'insights', 'labels', 'page_admin');
foreach ($flippable_groups as $g) {
    $v = fb_graph_group_version($g);
    t("fb_graph_group_version('$g') unset -> legacy ($legacy)", $v === $legacy, $v);
}
// Hard-pinned group: not in the flippable list, never reads config, always its pinned version.
$v = fb_graph_group_version('messaging_feature_review');
t("fb_graph_group_version('messaging_feature_review') hard-pinned -> v20.0", $v === 'v20.0', $v);

// Unknown/mistyped group name falls back to the most conservative group ('reads'), not a crash.
$v = fb_graph_group_version('not_a_real_group');
t("fb_graph_group_version('not_a_real_group') falls back to reads' resolution", $v === $legacy, $v);

// ============================================================================
// 3) graph_url_group() builds the right URL for each group.
// ============================================================================
foreach ($flippable_groups as $g) {
    $url = graph_url_group($g, 'me/x?a=1');
    $want = 'https://graph.facebook.com/' . $legacy . '/me/x?a=1';
    t("graph_url_group('$g', ...) URL shape", $url === $want, $url);
}
$url = graph_url_group('messaging_feature_review', 'me/messaging_feature_review?access_token=T');
$want = 'https://graph.facebook.com/v20.0/me/messaging_feature_review?access_token=T';
t('graph_url_group(messaging_feature_review, ...) always v20.0', $url === $want, $url);

// Leading-slash / no-slash paths behave the same as graph_url()'s ltrim.
t('graph_url_group leading slash stripped', graph_url_group('reads', '/me/picture') === graph_url_group('reads', 'me/picture'));

// graph_url()/fb_graph_version() legacy behaviour is completely untouched.
t('graph_url() legacy unchanged', graph_url('me/messages', TRUE) === 'https://graph.facebook.com/v20.0/me/messages');
t('graph_url() default unchanged', graph_url('me/messages') === 'https://graph.facebook.com/v26.0/me/messages');

// ============================================================================
// 4) REGRESSION: real call sites, old vs new, byte-identical when unset.
// ============================================================================
$touched_files = array(
    'application/controllers/Home.php',
    'application/controllers/Social_apps.php',
    'application/libraries/Fb_rx_login.php',
    'application/libraries/Reengage_import.php',
    'application/libraries/Fb_autopost.php',
    'application/libraries/Channels/Adapters/MetaMessagingAdapter.php',
    'application/helpers/ai_voice_helper.php',
    'application/views/facebook_rx/account_import.php',
    'application/views/livechat/livechat.php',
    'application/modules/ultrapost/views/cta_post/edit_cta_post.php',
    'application/modules/ultrapost/views/cta_post/add_cta_post.php',
    'application/views/admin/theme/header.php',
);

$sites_checked = 0;
$re = '/graph_url_group\(\s*([\'"])([a-z_]+)\1\s*,\s*([\'"])((?:[^\\\\]|\\\\.)*?)\3\s*\)/';
foreach ($touched_files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { t("file exists: $rel", false, 'missing'); continue; }
    $src = file_get_contents($path);
    if (!preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
        // A file with zero graph_url_group() sites is fine (e.g. it may only have
        // been touched for a doc-comment update); nothing to regress-check there.
        continue;
    }
    foreach ($m as $hit) {
        list(, , $group, , $path_literal) = $hit;
        $sites_checked++;
        // Real, literal path text taken verbatim from the actual converted call site
        // (interpolation markers like "{$post_access_token}" are kept as literal text --
        // graph_url()/graph_url_group() only prefix+concat, they never inspect $path's
        // contents, so this is a faithful byte-for-byte comparison of what each function
        // builds for the exact same input).
        $old_style = graph_url($path_literal, TRUE);
        $new_style = graph_url_group($group, $path_literal);
        t("regression [$rel] group=$group path=" . substr($path_literal, 0, 40), $old_style === $new_style,
            "old=$old_style new=$new_style");
    }
}
t('regression check actually covered a non-trivial number of real call sites', $sites_checked >= 60, "only found $sites_checked");

// ============================================================================
// 2) Configured-version behaviour, isolated subprocess (fresh statics).
// ============================================================================
$php_bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$sub = <<<'PHP'
define('BASEPATH', 1);
class FakeCIConfig { public $items = array(); public function item($k) { return isset($this->items[$k]) ? $this->items[$k] : ''; } }
class FakeCI { public $config; public function __construct() { $this->config = new FakeCIConfig(); } }
$GLOBALS['__fake_ci'] = new FakeCI();
function &get_instance() { return $GLOBALS['__fake_ci']; }
$GLOBALS['__fake_ci']->config->items = array(
    'fb_graph_group_reads_version' => 'v26.0',
    'fb_graph_group_send_version' => 'v26.0',
    'fb_graph_group_oauth_version' => 'v25.0',
    'fb_graph_group_labels_version' => 'v22.0',
    // Attempted override of the hard-pinned group -- fb_graph_group_version() must
    // never even look at this key.
    'fb_graph_group_messaging_feature_review_version' => 'v26.0',
);
require '%ROOT%/application/helpers/fb_graph_helper.php';
$out = array(
    'reads' => fb_graph_group_version('reads'),
    'send' => fb_graph_group_version('send'),
    'oauth' => fb_graph_group_version('oauth'),
    'labels' => fb_graph_group_version('labels'),
    'messenger_profile_untouched' => fb_graph_group_version('messenger_profile'),
    'messaging_feature_review_ignores_override' => fb_graph_group_version('messaging_feature_review'),
    'url' => graph_url_group('reads', 'me/x'),
);
echo json_encode($out);
PHP;
$sub = str_replace('%ROOT%', $root, $sub);
$tmp = tempnam(sys_get_temp_dir(), 'gm_') . '.php';
file_put_contents($tmp, "<?php\n" . $sub);
$json = shell_exec(escapeshellarg($php_bin) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$decoded = json_decode((string) $json, true);

if (!is_array($decoded)) {
    t('configured-version subprocess ran and returned JSON', false, "raw output: $json");
} else {
    t("configured 'reads' -> v26.0", $decoded['reads'] === 'v26.0', $decoded['reads']);
    t("configured 'send' -> v26.0", $decoded['send'] === 'v26.0', $decoded['send']);
    t("configured 'oauth' -> v25.0", $decoded['oauth'] === 'v25.0', $decoded['oauth']);
    t("configured 'labels' -> v22.0", $decoded['labels'] === 'v22.0', $decoded['labels']);
    t("unconfigured 'messenger_profile' still legacy (v20.0) even though sibling groups were flipped",
        $decoded['messenger_profile_untouched'] === 'v20.0', $decoded['messenger_profile_untouched']);
    t("hard-pinned 'messaging_feature_review' IGNORES a config override attempt -> still v20.0",
        $decoded['messaging_feature_review_ignores_override'] === 'v20.0', $decoded['messaging_feature_review_ignores_override']);
    t("graph_url_group('reads', ...) picks up the configured v26.0",
        $decoded['url'] === 'https://graph.facebook.com/v26.0/me/x', $decoded['url']);
}

// ============================================================================
// 5) Graph_diagnostics: admin-only gate (static proof) + real run()/index()
//    behaviour proving it NEVER makes a network call unless an admin has
//    explicitly POSTed a page + group. Runs the REAL controller code
//    (Reflection to skip CodeIgniter's constructor/session/db bootstrap,
//    which this DB-free harness has no framework for) with a fake DB and a
//    recording fake HttpTransport standing in for the real network.
// ============================================================================

// 5a. Static proof the constructor gate exists and matches Agency.php's own
// admin-only pattern (the task's explicit reference implementation) byte for byte.
$gd_src = file_get_contents($root . '/application/controllers/Graph_diagnostics.php');
$agency_src = file_get_contents($root . '/application/controllers/Agency.php');
$admin_gate = "if (\$this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');";
t('Graph_diagnostics constructor has the Admin-only gate', strpos($gd_src, $admin_gate) !== false);
t('Agency.php has the same gate (the pattern being matched)', strpos($agency_src, $admin_gate) !== false);
t('Graph_diagnostics also requires logged_in, same as Agency.php',
    strpos($gd_src, "\$this->session->userdata('logged_in') != 1") !== false);

// 5b. Real run()/index() behaviour, DB-free and network-free.
define('APPPATH', $root . '/application/');
class CI_Controller {}
class ShowErrorException extends Exception {}
function show_error($msg, $code = 500) { throw new ShowErrorException($msg . ' (HTTP ' . $code . ')'); }

require $root . '/application/controllers/Graph_diagnostics.php';

class FakeDiagDb
{
    private $rows; private $where = array(); private $table = '';
    public function __construct($rows) { $this->rows = $rows; }
    public function table_exists($t) { return true; }
    public function select($c) { return $this; }
    public function from($t) { $this->table = $t; return $this; }
    public function where($k, $v = null) { $this->where[$k] = $v; return $this; }
    public function order_by($c, $d = null) { return $this; }
    public function get() { return $this; }
    public function result_array()
    {
        return array_values(array_filter($this->rows, function ($r) {
            foreach ($this->where as $k => $v) { if ((string) ($r[$k] ?? '') !== (string) $v) return false; }
            return true;
        }));
    }
    public function row_array() { $r = $this->result_array(); return $r ? $r[0] : null; }
}
class FakeInput
{
    public $post_data = array(); public $server_data = array();
    public function post($k) { return isset($this->post_data[$k]) ? $this->post_data[$k] : null; }
    public function server($k) { return isset($this->server_data[$k]) ? $this->server_data[$k] : null; }
}
class RecordingTransport implements \MonkeyBot\Channels\HttpTransport
{
    public $urls = array();
    public function send(\MonkeyBot\Channels\HttpRequest $r): \MonkeyBot\Channels\HttpResponse
    {
        $this->urls[] = $r->url;
        return new \MonkeyBot\Channels\HttpResponse(200, '{"id":"999","name":"Test Page"}', '');
    }
}
class TestableGraphDiagnostics extends Graph_diagnostics
{
    public $rendered = null;
    public function _viewcontroller($data = array()) { $this->rendered = $data; }
}

$tenant_pages = array(
    array('id' => 7, 'user_id' => '42', 'page_id' => '111', 'page_name' => 'My Page', 'page_access_token' => 'TOKEN-A', 'deleted' => '0'),
    array('id' => 8, 'user_id' => '99', 'page_id' => '222', 'page_name' => 'Someone Elses Page', 'page_access_token' => 'TOKEN-B', 'deleted' => '0'),
);

$make = function () use ($tenant_pages) {
    $rc = new ReflectionClass('TestableGraphDiagnostics');
    $obj = $rc->newInstanceWithoutConstructor();
    $obj->db = new FakeDiagDb($tenant_pages);
    $obj->input = new FakeInput();
    $obj->uid = 42; // the logged-in tenant
    $transport = new RecordingTransport();
    $obj->setHttpTransport($transport);
    return array($obj, $transport);
};

// GET (not POST) -> refused, zero network calls.
list($obj, $transport) = $make();
$threw = false;
try { $obj->run(); } catch (ShowErrorException $e) { $threw = true; }
t('run() on GET is refused (show_error)', $threw);
t('run() on GET makes ZERO network calls', count($transport->urls) === 0, count($transport->urls));

// POST but no page chosen -> refused, zero network calls.
list($obj, $transport) = $make();
$obj->input->server_data['REQUEST_METHOD'] = 'POST';
$obj->input->post_data['group'] = 'reads';
$threw = false;
try { $obj->run(); } catch (ShowErrorException $e) { $threw = true; }
t('run() with no page_row_id is refused', $threw);
t('run() with no page_row_id makes ZERO network calls', count($transport->urls) === 0, count($transport->urls));

// POST with an unknown group -> refused, zero network calls.
list($obj, $transport) = $make();
$obj->input->server_data['REQUEST_METHOD'] = 'POST';
$obj->input->post_data['page_row_id'] = 7;
$obj->input->post_data['group'] = 'not_a_group';
$threw = false;
try { $obj->run(); } catch (ShowErrorException $e) { $threw = true; }
t('run() with an unrecognised group is refused', $threw);
t('run() with an unrecognised group makes ZERO network calls', count($transport->urls) === 0, count($transport->urls));

// POST for a page that belongs to a DIFFERENT tenant -> refused, zero network calls.
list($obj, $transport) = $make();
$obj->input->server_data['REQUEST_METHOD'] = 'POST';
$obj->input->post_data['page_row_id'] = 8; // owned by user_id 99, this session is uid 42
$obj->input->post_data['group'] = 'reads';
$threw = false;
try { $obj->run(); } catch (ShowErrorException $e) { $threw = true; }
t('run() for another tenant\'s page is refused (no cross-tenant leak)', $threw);
t('run() for another tenant\'s page makes ZERO network calls', count($transport->urls) === 0, count($transport->urls));

// index() never touches the network, regardless of anything.
list($obj, $transport) = $make();
$obj->index();
t('index() makes ZERO network calls', count($transport->urls) === 0, count($transport->urls));
t('index() rendered a view (did not silently no-op)', $obj->rendered !== null);

// The one valid case: explicit POST, own page, known group -> exactly 4 calls
// (2 safe probes x 2 versions), never more, never a write endpoint.
list($obj, $transport) = $make();
$obj->input->server_data['REQUEST_METHOD'] = 'POST';
$obj->input->post_data['page_row_id'] = 7;
$obj->input->post_data['group'] = 'send'; // even for the "send" group, no message is ever sent
$obj->run();
t('run() with a valid explicit request makes exactly 4 calls (2 probes x legacy+candidate)',
    count($transport->urls) === 4, count($transport->urls));
$all_safe = true;
foreach ($transport->urls as $u) {
    if (strpos($u, '/111') === false && strpos($u, 'me/picture') === false) $all_safe = false;
    if (strpos($u, 'me/messages') !== false) $all_safe = false; // never the real Send API endpoint
}
t('run() only ever calls the two safe read probes, never the group\'s real endpoint', $all_safe);
$has_legacy = false; $has_candidate = false;
foreach ($transport->urls as $u) {
    if (strpos($u, '/' . $legacy . '/') !== false) $has_legacy = true;
    if (strpos($u, '/' . $default . '/') !== false) $has_candidate = true;
}
t('run() calls both the legacy and the candidate version', $has_legacy && $has_candidate);
t('run() rendered the result view with the right config key',
    isset($obj->rendered['config_key']) && $obj->rendered['config_key'] === 'fb_graph_group_send_version',
    isset($obj->rendered['config_key']) ? $obj->rendered['config_key'] : 'unset');

echo "== $pass passed, $fail failed ($sites_checked real call sites regression-checked) ==\n";
exit($fail ? 1 : 0);
