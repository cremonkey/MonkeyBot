<?php
/**
 * Instagram Login: application/helpers/ig_login_helper.php + fb_graph_helper routing + wiring.
 * No DB, no CI, no network (igl_http is faked).
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ig_login/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', 1);
function log_message($l, $m) {}
function secret_decrypt($s) { return $s === 'ENC' ? 'shh' : ''; }
require __DIR__ . '/../../application/helpers/fb_graph_helper.php';
require __DIR__ . '/../../application/helpers/ig_login_helper.php';

$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name  -> got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"; }
}

// ---- URL routing ----
check('FB token stays on graph.facebook.com', strpos(graph_url_group('send', 'me/messages?access_token=EAAB123'), 'https://graph.facebook.com/') === 0, true);
check('IG token goes to graph.instagram.com', graph_url_group('send', 'me/messages?access_token=IGAAx1'), 'https://graph.instagram.com/v25.0/me/messages?access_token=IGAAx1');
check('IG token in later param', strpos(graph_url_group('reads', '123?fields=a&access_token=IGQVJ9'), 'https://graph.instagram.com/') === 0, true);
check('"IG" elsewhere in path ignored', strpos(graph_url_group('reads', 'IGtag?access_token=EAAB'), 'https://graph.facebook.com/') === 0, true);
check('no token => facebook', strpos(graph_url_group('reads', 'me'), 'https://graph.facebook.com/') === 0, true);
check('token detect', array(igl_is_ig_token('IGAAb'), igl_is_ig_token('EAAb'), igl_is_ig_token('')), array(true, false, false));

// ---- config ----
class Q { public $r; function __construct($r) { $this->r = $r; } function row_array() { return is_array($this->r) && isset($this->r[0]) ? $this->r[0] : $this->r; } function result_array() { return $this->r; } }
class DB { public $db_debug = true; public $sel = array(); public $log = array(); public $id = 100; public $throw = false; public $tables = true;
    function query($sql, $b = array()) { if ($this->throw) throw new Exception('no table'); $this->log[] = array($sql, $b);
        foreach ($this->sel as $k => $v) if (stripos($sql, $k) !== false && stripos($sql, 'SELECT') === 0) return new Q($v);
        return stripos($sql, 'SELECT') === 0 ? new Q(array()) : true; }
    function insert_id() { return ++$this->id; } function table_exists($t) { return $this->tables; } }
$db = new DB(); $db->sel['ig_login_config'] = array('app_id' => '123', 'app_secret_enc' => 'ENC', 'enabled' => 1);
check('config ok', igl_config($db), array('app_id' => '123', 'app_secret' => 'shh'));
$db->sel['ig_login_config']['enabled'] = 0; check('config disabled => null', igl_config($db), null);
$db->sel['ig_login_config'] = array('app_id' => '123', 'app_secret_enc' => 'bad', 'enabled' => 1); check('undecryptable secret => null', igl_config($db), null);
$db->throw = true; check('table missing => null', igl_config($db), null); check('db_debug restored', $db->db_debug, true);

$u = igl_authorize_url('123', 'https://bot.x/ig_login/callback', 'st8');
parse_str(parse_url($u, PHP_URL_QUERY), $q);
check('authorize host', strpos($u, 'https://www.instagram.com/oauth/authorize?') === 0, true);
check('authorize params', array($q['client_id'], $q['redirect_uri'], $q['response_type'], $q['state']), array('123', 'https://bot.x/ig_login/callback', 'code', 'st8'));
check('authorize scopes', $q['scope'], 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments');

// ---- code exchange ----
$calls = array();
$GLOBALS['igl_http_fake'] = function ($m, $url, $post) use (&$calls) {
    $calls[] = array($m, $url, $post);
    if (strpos($url, 'api.instagram.com/oauth/access_token') !== false) return array('data' => array(array('access_token' => 'IGshort', 'user_id' => 5)));
    if (strpos($url, 'grant_type=ig_exchange_token') !== false) return array('access_token' => 'IGlong', 'expires_in' => 5183944);
    if (strpos($url, '/me?') !== false) return array('user_id' => '17841', 'username' => 'cm_shop', 'name' => 'CM', 'followers_count' => 900, 'media_count' => 12);
    if (strpos($url, 'subscribed_apps') !== false) return array('success' => true);
    if (strpos($url, 'refresh_access_token') !== false) return array('access_token' => 'IGnew', 'expires_in' => 5184000);
    return array('error' => array('message' => 'unexpected ' . $url));
};
$acc = igl_exchange_code(array('app_id' => '123', 'app_secret' => 'shh'), 'CODE', 'https://bot.x/cb');
check('exchange ok', $acc['ok'], true);
check('exchange values', array($acc['token'], $acc['ig_id'], $acc['username'], $acc['followers']), array('IGlong', '17841', 'cm_shop', 900));
check('exchange posts code + secret', array($calls[0][0], $calls[0][2]['code'], $calls[0][2]['client_secret'], $calls[0][2]['grant_type']), array('POST', 'CODE', 'shh', 'authorization_code'));
check('profile read on graph.instagram.com', strpos($calls[2][1], 'https://graph.instagram.com/v25.0/me?') === 0, true);
check('subscribe ok', igl_subscribe_webhooks('IGlong'), '');
check('subscribe fields', strpos(end($calls)[1], 'subscribed_fields=comments%2Cmessages%2Cmessaging_postbacks') !== false, true);
$GLOBALS['igl_http_fake'] = function () { return array('error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid code', 'error' => array('message' => 'Invalid code')); };
$bad = igl_exchange_code(array('app_id' => '1', 'app_secret' => 's'), 'X', 'r');
check('exchange error surfaced', array($bad['ok'], $bad['error']), array(false, 'Invalid code'));

// ---- refresh ----
$now = strtotime('2026-10-18 12:00:00');
check('refresh: 31 days since last', igl_needs_refresh('2026-09-17 12:00:00', '2026-11-16 12:00:00', $now), true);
check('refresh: recent, far from expiry', igl_needs_refresh('2026-10-10 12:00:00', '2026-12-09 12:00:00', $now), false);
check('refresh: <15 days to expiry', igl_needs_refresh('2026-10-17 12:00:00', '2026-10-25 12:00:00', $now), true);
check('refresh: already expired => no (reconnect)', igl_needs_refresh('2026-08-01 00:00:00', '2026-10-01 00:00:00', $now), false);
$GLOBALS['igl_http_fake'] = function ($m, $url) { return strpos($url, 'refresh_access_token') !== false ? array('access_token' => 'IGnew', 'expires_in' => 99999999999) : array(); };
$r = igl_refresh_token('IGold');
check('refresh: absurd expires_in clamped to 60d', array($r['ok'], $r['token'], strtotime($r['expires_at']) - time() <= 5184001), array(true, 'IGnew', true));

$db = new DB(); $db->sel['connection_type = \'ig_login\''] = array(
    array('id' => 7, 'facebook_rx_fb_user_info_id' => 3, 'page_access_token' => 'IGold', 'ig_token_expires_at' => date('Y-m-d H:i:s', time() + 5 * 86400), 'ig_token_refreshed_at' => date('Y-m-d H:i:s')),
    array('id' => 8, 'facebook_rx_fb_user_info_id' => 4, 'page_access_token' => 'IGok', 'ig_token_expires_at' => date('Y-m-d H:i:s', time() + 50 * 86400), 'ig_token_refreshed_at' => date('Y-m-d H:i:s')));
$out = igl_refresh_all($db);
check('refresh_all counts', array($out['checked'], $out['refreshed'], $out['failed']), array(2, 1, 0));
check('refresh_all writes both tables', count(array_filter($db->log, function ($l) { return stripos($l[0], 'UPDATE') === 0; })), 2);
$db->tables = false; check('refresh_all not installed', igl_refresh_all($db), array('skipped' => 'not installed'));

// ---- upsert ----
$db = new DB();
$res = igl_upsert_account($db, 9, array('ig_id' => '17841', 'token' => 'IGlong', 'expires_at' => '2026-12-17 00:00:00', 'username' => 'cm', 'name' => 'CM', 'picture' => '', 'followers' => 1, 'media' => 2));
check('upsert new', $res, array('id' => 102, 'new' => true));
$ins = array_values(array_filter($db->log, function ($l) { return stripos($l[0], 'INSERT INTO facebook_rx_fb_page_info') !== false; }));
check('upsert page row: ig_login, page_id = ig id, bot on', array($ins[0][1][2], $ins[0][1][7] === date('Y-m-d'), strpos($ins[0][0], "'ig_login'") !== false), array('17841', true, true));
$db = new DB(); $db->sel['instagram_business_account_id = ?'] = array('id' => 50, 'facebook_rx_fb_user_info_id' => 6, 'user_id' => 1);
check('upsert refuses other user\'s account', isset(igl_upsert_account($db, 9, array('ig_id' => '1', 'token' => 'IG', 'expires_at' => '', 'username' => '', 'name' => '', 'picture' => '', 'followers' => 0, 'media' => 0))['error']), true);
$db->sel['instagram_business_account_id = ?']['user_id'] = 9;
check('upsert existing => update', igl_upsert_account($db, 9, array('ig_id' => '1', 'token' => 'IG', 'expires_at' => '', 'username' => '', 'name' => '', 'picture' => '', 'followers' => 0, 'media' => 0)), array('id' => 50, 'new' => false));

// ---- client ----
$seen = array();
$GLOBALS['igl_http_fake'] = function ($m, $url, $post) use (&$seen) { $seen[] = array($m, $url, $post);
    if (strpos($url, '/comments?') !== false) return array('data' => array(array('id' => 'c1', 'text' => 'price')));
    if (strpos($url, 'me/media') !== false) return array('data' => array(array('id' => 'm1')));
    if (strpos($url, 'boom') !== false) return array('error' => array('message' => 'nope'));
    return array('id' => 'x', 'success' => true, 'from' => array('username' => 'ali')); };
$c = new Ig_login_client();
check('client comments list', $c->instagram_get_all_comment_of_post('m1', 'IGt'), array(array('id' => 'c1', 'text' => 'price')));
check('client username from "from"', $c->instagram_get_media_info_by_comment('c1', 'IGt')['username'], 'ali');
$c->instagram_auto_comment('شكرا', 'c1', 'IGt');
check('client public reply = POST /replies', array(end($seen)[0], strpos(end($seen)[1], 'https://graph.instagram.com/v25.0/c1/replies?') === 0, end($seen)[2]), array('POST', true, array('message' => 'شكرا')));
$c->instagram_hide_comment('c1', 'IGt'); check('client hide = POST hide=true', array(end($seen)[0], end($seen)[2]), array('POST', array('hide' => 'true')));
$c->instagram_delete_comment('c1', 'IGt'); check('client delete = DELETE', end($seen)[0], 'DELETE');
check('client post list', $c->get_postlist_from_instagram_account('1', 'IGt'), array('data' => array(array('id' => 'm1'))));
check('client mentions unsupported => empty', $c->instagram_get_all_comment_of_mention_post(1, 2, 'IGt'), array());
$threw = false; try { $c->instagram_get_media_info_by_comment('boom', 'IGt'); } catch (Exception $e) { $threw = $e->getMessage() === 'nope'; }
check('client API error throws (callers catch)', $threw, true);
$threw = false; try { $c->instagram_business_discovery_data(1, 2); } catch (Exception $e) { $threw = true; }
check('client unknown method throws', $threw, true);

// ---- wiring ----
$ir = file_get_contents(__DIR__ . '/../../application/controllers/Instagram_reply.php');
check('wiring: comment path uses client for IG tokens', strpos($ir, '$this->fb_rx_login = new Ig_login_client();') !== false, true);
check('wiring: account list includes IG Login rows', strpos($ir, 'igl_user_accounts($this->db, $this->user_id, $account_list)') !== false, true);
check('wiring: post list via client', strpos($ir, '$igl_client->get_postlist_from_instagram_account(') !== false, true);
$ch = file_get_contents(__DIR__ . '/../../application/controllers/Cron_hub.php');
check('wiring: cron_hub refreshes tokens', strpos($ch, "\$out['ig_token_refresh'] = igl_refresh_all(\$this->db);") !== false, true);
$ws = file_get_contents(__DIR__ . '/../../application/helpers/webhook_security_helper.php');
check('wiring: IG app secret checked for signatures', strpos($ws, "from('ig_login_config')") !== false, true);
$cb = file_get_contents(__DIR__ . '/../../application/controllers/Ig_login.php');
check('wiring: callback checks state', strpos($cb, 'hash_equals($expected, $state)') !== false, true);
check('wiring: callback checks package limit', strpos($cb, '_check_usage($module_id = 65') !== false, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
