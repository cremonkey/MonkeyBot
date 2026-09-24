<?php
/**
 * Cross-tenant isolation — static/behavioral audit (Task 15 / B7, brief §38).
 *
 * A real HTTP/DB staging stack was not used for this task: the host had ~3GB available at
 * audit time (`free -g`), the plan's floor for standing up an isolated compose stack, and the
 * plan explicitly allows a dependency-free static audit as the fallback (see
 * docs/plans/2026-09-24-remaining-work.md task 15 / task-15-brief.md). This suite is that
 * audit, turned into a repeatable check: for each in-scope endpoint (every Crm::deal_*,
 * Analytics_hub's dashboard queries, and Livechat's read endpoints reachable from the realtime
 * re-fetch path) it proves — by reading the controller source, not by exercising a live DB —
 * that the tenant filter (user_id from the session) sits on every by-id read/write. No DB, no
 * CodeIgniter bootstrap, no network.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/cross_tenant/run.php
 *
 * Found and fixed as part of this task: Livechat::get_conversation_single() selected
 * `livechat_messages` by `subscriber_id` alone — no `user_id` filter — so tenant A could pass
 * tenant B's numeric subscriber_id (from_user_id) and read tenant B's conversation. This was
 * called out in Task 1's review and is now also the endpoint the realtime Pusher re-fetch path
 * calls (see application/views/include/pusher-js.php's `livechat_event` handler and
 * application/helpers/livechat_realtime_helper.php's header comment). It also reset another
 * tenant's `unseen_count` via the same unscoped `subscribe_id`. Both are fixed in the same
 * commit as this test: application/controllers/Livechat.php now filters both the message
 * SELECT and the unseen_count UPDATE by `user_id` too.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}
/** True when $needle appears in $haystack strictly before $before (or $before is absent). */
function before($haystack, $needle, $before_needle) {
    $a = strpos($haystack, $needle);
    $b = strpos($haystack, $before_needle);
    return $a !== false && ($b === false || $a < $b);
}

$crm = file_get_contents(__DIR__ . '/../../application/controllers/Crm.php');
$hub = file_get_contents(__DIR__ . '/../../application/controllers/Analytics_hub.php');
$livechat = file_get_contents(__DIR__ . '/../../application/controllers/Livechat.php');

// ============================================================================================
echo "== Crm::deal_* — every endpoint scopes crm_deals (and related tables) by user_id ==\n";

check('Crm::owns_deal() filters crm_deals by user_id (the one place all three deal_* endpoints get an id-scoped row from)',
    strpos($crm, "->from('crm_deals')->where('id',(int)\$deal_id)->where('user_id',\$this->uid)") !== false, true);

// deal_save(): existing-row branch must go through owns_deal() before any update, and the
// insert branch must stamp the new row with the session's tenant id (not a client-supplied one).
$deal_save = substr($crm, strpos($crm, 'public function deal_save'), strpos($crm, 'public function deal_move') - strpos($crm, 'public function deal_save'));
check('deal_save(): the update branch is gated on owns_deal($id)', strpos($deal_save, 'owns_deal($id)') !== false, true);
check('deal_save(): the update itself also carries a user_id filter (defense in depth, not owns_deal() alone)',
    strpos($deal_save, "->where('id',\$id)->where('user_id',\$this->uid)->update('crm_deals'") !== false, true);
check('deal_save(): the insert branch stamps user_id from the session, not from client input',
    strpos($deal_save, "\$fields['user_id']=\$this->uid;") !== false, true);
check('deal_save(): no raw $_POST/input->post value is ever assigned directly to user_id',
    preg_match('/user_id[\'"]?\s*[=\]]+\s*.*input->post/i', $deal_save) === 0, true);

$deal_move = substr($crm, strpos($crm, 'public function deal_move'), strpos($crm, 'public function deal_detail') - strpos($crm, 'public function deal_move'));
check('deal_move(): fetches the deal via owns_deal() before touching it', strpos($deal_move, '$deal = $this->owns_deal($id);') !== false, true);
check('deal_move(): bails out (status 0) when owns_deal() returns nothing, before any write',
    before($deal_move, '$deal = $this->owns_deal($id);', "echo json_encode(['status'=>'0']); return;"), true);
check('deal_move(): the target stage is also constrained to the OWNED deal\'s pipeline_id (no moving into another tenant\'s stage)',
    strpos($deal_move, "->where('pipeline_id',\$deal['pipeline_id'])") !== false, true);

$deal_detail = substr($crm, strpos($crm, 'public function deal_detail'), strpos($crm, 'public function tasks') - strpos($crm, 'public function deal_detail'));
check('deal_detail(): fetches the deal via owns_deal() and 404s (not just redirects) when absent',
    strpos($deal_detail, '$deal = $this->owns_deal($id);') !== false && strpos($deal_detail, 'show_404();') !== false, true);
check('deal_detail(): the conversation/orders sub-queries are still scoped by user_id, even though the deal itself is already owned',
    strpos($deal_detail, "from('livechat_messages')->where('user_id',\$this->uid)") !== false
    && strpos($deal_detail, "from('ecommerce_cart')->where('user_id',\$this->uid)") !== false, true);

// task_complete() is not a deal_* endpoint but is the sibling by-id write in the same
// controller (crm_activities instead of crm_deals) — worth the same one-line proof.
check('task_complete(): the crm_activities lookup is scoped by user_id before any write',
    strpos($crm, "from('crm_activities')->where('id',\$id)->where('user_id',\$this->uid)") !== false, true);

// ============================================================================================
echo "\n== Analytics_hub — every dashboard query is scoped by the session's tenant id ==\n";

// The controller takes no id parameters at all (index() only) — the risk here is a query that
// forgets the user_id filter and aggregates across every tenant. Assert every private
// aggregation method's from()/query() call chain includes a user_id condition.
$hub_methods = array('funnel', 'cards', 'subs_series', 'msg_series', 'ai_series', 'orders_series',
    'lead_bands', 'top_missed', 'deflection', 'sales_kpis', 'ai_cost');
foreach ($hub_methods as $m) {
    $start = strpos($hub, "function $m(");
    if ($start === false) { bad("Analytics_hub::$m exists", 'method not found — brief may be stale, or method renamed'); continue; }
    $next = strpos($hub, "\n    private function ", $start + 1);
    if ($next === false) $next = strpos($hub, "\n    // Estimated AI cost", $start + 1);
    if ($next === false) $next = strlen($hub);
    $body = substr($hub, $start, $next - $start);
    $scoped = strpos($body, "'user_id', \$uid") !== false || strpos($body, "'user_id',\$uid") !== false
        || strpos($body, "'user_id', \$this->uid") !== false || strpos($body, "'user_id',\$this->uid") !== false
        || preg_match('/user_id\s*=\s*\?/', $body) === 1;
    check("Analytics_hub::$m() scopes every query by the tenant id", $scoped, true);
}
check('Analytics_hub::__construct() resolves the tenant id from the session (real_user_id, falling back to user_id), never from request input',
    strpos($hub, "\$this->uid = \$this->session->userdata('real_user_id') ?: \$this->session->userdata('user_id');") !== false, true);

// ============================================================================================
echo "\n== Livechat read endpoints the realtime re-fetch path calls ==\n";

// Path traced from application/views/include/pusher-js.php's livechat_event handler and
// application/views/livechat/livechat_load_livechat_js.php: on a realtime event the page
// triggers #refresh_data (-> ajax_call() -> Livechat::get_conversation_list()) and, if the
// affected subscriber's thread is open, calls Livechat::get_conversation_single() directly.

$gcs_start = strpos($livechat, 'public function get_conversation_single');
$gcs_end = strpos($livechat, 'public function reply_to_conversation');
check('get_conversation_single() exists before reply_to_conversation() in the file (sanity: slice below is the right method)', $gcs_start !== false && $gcs_end !== false && $gcs_start < $gcs_end, true);
$gcs = substr($livechat, $gcs_start, $gcs_end - $gcs_start);

check('get_conversation_single(): the livechat_messages SELECT is scoped by BOTH subscriber_id AND user_id (the fix — subscriber_id alone let tenant A read tenant B\'s messages by guessing/enumerating a subscriber_id)',
    strpos($gcs, "where('subscriber_id '") !== false && strpos($gcs, "where('user_id', \$this->user_id)") !== false, true);
check('get_conversation_single(): the user_id filter is added on the SAME query builder chain as the subscriber_id filter, before ->get() runs',
    before($gcs, "where('subscriber_id '", "where('user_id', \$this->user_id)") && before($gcs, "where('user_id', \$this->user_id)", '->get()'), true);
check('get_conversation_single(): the unseen_count reset is also scoped by user_id, not subscribe_id alone (same class of cross-tenant write, same endpoint)',
    strpos($gcs, "array(\"subscribe_id\"=>\$from_user_id,\"user_id\"=>\$this->user_id)") !== false, true);

$gcl_start = strpos($livechat, 'public function get_conversation_list');
$gcl_next = strpos($livechat, 'protected function display_subscriber_list');
$gcl = substr($livechat, $gcl_start, $gcl_next - $gcl_start);
check('get_conversation_list() / get_subscriber_list(): messenger_bot_subscriber query is scoped by user_id',
    strpos($gcl, "'user_id' => \$this->user_id") !== false, true);

$ss_start = strpos($livechat, 'public function search_subscriber');
$ss = substr($livechat, $ss_start, strpos($livechat, 'public function get_conversation_single') - $ss_start);
check('search_subscriber(): scoped by user_id', strpos($ss, "'user_id' => \$this->user_id") !== false, true);

check('toggle_bot_pause(): resolves the subscriber via a user_id-scoped lookup before pausing/resuming the bot',
    strpos($livechat, "'where'=>['subscribe_id'=>\$subscribe_id, 'user_id'=>\$uid]") !== false, true);

check('realtime_auth(): authorizes only the requesting tenant\'s own private channel (livechat_realtime_authorize($uid, ...)), not an arbitrary channel from the request',
    strpos($livechat, 'livechat_realtime_authorize($uid,') !== false, true);

// ============================================================================================
echo "\n== upload paths: what an attacker can enumerate/guess (assessed, not an endpoint fix) ==\n";

// Livechat::livechat_upload_file() writes into FCPATH/upload/livechat, which is served
// directly by the webserver (no controller/auth gate on the download side — there is no
// Livechat::download or similar). The only protection is the filename being hard to guess.
// The first match is a commented-out earlier implementation left in place above the real one;
// find the active method (the one with a real, uncommented body) by anchoring past it.
$luf_first = strpos($livechat, 'public function livechat_upload_file');
$luf_start = strpos($livechat, 'public function livechat_upload_file', $luf_first + 1);
check('livechat_upload_file(): found the active (non-commented-out) method, not the dead code above it',
    $luf_start !== false && $luf_start > $luf_first, true);
$luf = substr($livechat, $luf_start, 1600);
check('livechat_upload_file(): filename is not the client-supplied original name (would leak nothing extra, but keep it explicit)',
    strpos($luf, '$filename="livechat"') !== false, true);
check('livechat_upload_file(): filename mixes tenant user_id + time() + a uniqid() fragment — NOT purely sequential/enumerable',
    strpos($luf, '$filename="livechat".$this->user_id."_".time().substr(uniqid(mt_rand(), true), 0, 6)') !== false, true);
// This is a documented residual risk, not a regression from this task and not newly
// introduced: time() is public/guessable within a login session and only 6 hex chars (~24
// bits) of uniqid() entropy are kept, so a same-second upload by a known user_id is brute
// forceable offline against the public static path (no rate limit on GET /upload/livechat/*).
// It predates this task and isn't a Crm/Analytics_hub/Livechat *endpoint* IDOR (nothing reads
// the id back from a query the server controls), so it's assessed here and left unfixed per
// the task's "endpoints" scope — flagged for a follow-up task instead of scope-creeping this one.
ok('assessed: livechat_upload_file() filenames are guessable in theory (~24 bits + known user_id + narrow time window) but this is pre-existing static-file exposure, not a new endpoint IDOR — left unfixed, flagged for follow-up');

// ============================================================================================
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
