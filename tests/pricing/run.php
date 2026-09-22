<?php
/**
 * Products & Pricing — per-page price lists + generic catalog. DB-free unit tests:
 * no CodeIgniter bootstrap, no database, no network. get_instance() returns a tiny fake
 * whose ->db is an in-memory query-builder alike (only what the code under test calls).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/pricing/run.php
 *
 * Covers: pricing_resolve_scope per channel (incl. openwa #5 vs WhatsApp Cloud #5 being
 * different lists, and a foreign tenant's page never resolving), page -> default -> null
 * fallback, the legacy 1-arg pricing_get_config (before and after the migration),
 * pricing_calc_item exactness (quantity, variants, price on request, ambiguous alias,
 * unknown item), the validator (catalog-only accepted, bad items rejected, old rules
 * kept), save/copy/move/delete + audit scope, the IDOR ownership gate, rule 10 / tool
 * definition byte-identical for lists without a catalog, and Ai_tools::execute returning
 * page A's price for page A and page B's for page B with the figure landing in
 * ai_price_facts (the price guard's authoritative source).
 * The HTTP/DB end-to-end twin is docker/scripts/staging_pricing_pages_tests.sh.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
date_default_timezone_set('UTC');

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }
function has($n, $hay, $needle) { strpos((string) $hay, $needle) !== false ? ok($n) : bad($n, "'$needle' not in " . var_export($hay, true)); }
function log_message($l, $m) {}

// ------------------------------------------------------------ fake CI

class FakeResult
{
    private $rows;
    public function __construct($rows) { $this->rows = array_values($rows); }
    public function row_array() { return $this->rows[0] ?? null; }
    public function result_array() { return $this->rows; }
}

class FakeDb
{
    public $tables = array();    // name => id => row
    public $fields = array();    // name => [col, ...]
    private $auto = array();
    private $t; private $w = array(); private $lim = null; private $ord = null;
    private $snap = null; private $failed = false;

    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($f, $t) { return in_array($f, $this->fields[$t] ?? array(), true); }
    public function select($c) { return $this; }
    public function from($t) { $this->t = $t; return $this; }
    public function where($k, $v = null) { $this->w[] = array($k, $v); return $this; }
    public function limit($n) { $this->lim = $n; return $this; }
    public function order_by($c, $d = 'ASC') { $this->ord = array($c, strtoupper($d)); return $this; }
    private function reset() { $this->w = array(); $this->lim = null; $this->ord = null; }
    private function match($t) {
        $rows = array_values($this->tables[$t] ?? array());
        foreach ($this->w as $w) {
            list($k, $v) = $w;
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) { return array_key_exists($k, $r) && (string) $r[$k] === (string) $v; }));
        }
        return $rows;
    }
    public function get() {
        $rows = $this->match($this->t);
        if ($this->ord) { $c = $this->ord[0]; $d = $this->ord[1]; usort($rows, function ($a, $b) use ($c, $d) { return $d === 'DESC' ? $b[$c] <=> $a[$c] : $a[$c] <=> $b[$c]; }); }
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        $this->reset();
        return new FakeResult($rows);
    }
    public function insert($t, $data) {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        if ($t === 'pricing_config' && $this->field_exists('channel_type', 'pricing_config')) {
            $data += array('channel_type' => '', 'target_id' => '');
            foreach ($this->tables[$t] as $r)   // UNIQUE(user_id, channel_type, target_id)
                if ($r['user_id'] == $data['user_id'] && $r['channel_type'] === $data['channel_type'] && $r['target_id'] === $data['target_id']) { $this->failed = true; $this->reset(); return false; }
        }
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data; $this->reset(); return true;
    }
    public function update($t, $data) { foreach ($this->match($t) as $r) $this->tables[$t][$r['id']] = array_merge($r, $data); $this->reset(); return true; }
    public function delete($t) { foreach ($this->match($t) as $r) unset($this->tables[$t][$r['id']]); $this->reset(); return true; }
    public function trans_start() { $this->snap = $this->tables; $this->failed = false; }
    public function trans_complete() { if ($this->failed) $this->tables = $this->snap; }
    public function trans_status() { return !$this->failed; }
}
class FakeLoader { public function helper($h) { foreach ((array) $h as $n) require_once APPPATH . 'helpers/' . $n . '_helper.php'; } public function library($l) {} }
class FakeSession { public function userdata($k) { return null; } }
class FakeCI { public $db; public $load; public $session; public $ai_price_facts = array(); public $ai_media_facts = array(); }
$CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->session = new FakeSession();
function &get_instance() { global $CI; return $CI; }

require APPPATH . 'helpers/media_helper.php';
require APPPATH . 'helpers/pricing_helper.php';
require APPPATH . 'helpers/ai_agent_helper.php';
require APPPATH . 'libraries/Ai_tools.php';

function fresh_db($migrated = true)
{
    $db = new FakeDb();
    $db->tables = array('pricing_config' => array(), 'pricing_config_history' => array(),
        'facebook_rx_fb_page_info' => array(), 'openwa_accounts' => array(), 'whatsapp_accounts' => array(),
        'telegram_accounts' => array(), 'webchat_settings' => array(), 'users' => array());
    $db->fields['pricing_config'] = $migrated ? array('id', 'user_id', 'channel_type', 'target_id', 'config_json', 'status', 'updated_at') : array('id', 'user_id', 'config_json', 'status', 'updated_at');
    $db->fields['pricing_config_history'] = $migrated ? array('id', 'user_id', 'channel_type', 'target_id') : array('id', 'user_id');
    return $db;
}
function seed_row($db, $uid, $ch, $t, $cfg, $status = '1')
{
    $r = array('user_id' => $uid, 'config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE), 'status' => $status, 'updated_at' => '2026-09-01 00:00:00');
    if ($db->field_exists('channel_type', 'pricing_config')) { $r['channel_type'] = $ch; $r['target_id'] = $t; }
    $db->insert('pricing_config', $r);
}

$BYOOT = json_decode(file_get_contents(dirname(__DIR__, 2) . '/docs/byoot-pricing-config.json'), true);
$SALON = array('currency' => 'جنيه', 'catalog' => array('items' => array(
    array('name' => 'قص شعر', 'category' => 'Hair', 'price' => 150, 'unit' => 'session', 'aliases' => array('haircut'),
          'variants' => array(array('name' => 'أطفال', 'price' => 100), array('name' => 'رجالي', 'price' => 150))),
    array('name' => 'صبغة كاملة', 'price' => 12.5, 'unit' => 'custom', 'unit_label' => 'أنبوبة'),
    array('name' => 'Keratin Short', 'price' => 900, 'unit' => 'session', 'aliases' => array('كيراتين')),
    array('name' => 'Keratin Long', 'price' => 1400, 'unit' => 'session', 'aliases' => array('كيراتين')),
    array('name' => 'Bridal package', 'unit' => 'item', 'price_on_request' => true),
    array('name' => 'T-shirt', 'unit' => 'piece', 'variants' => array(array('name' => 'Small', 'price' => 200), array('name' => 'Large', 'price' => 250))),
    array('name' => 'Coffee beans', 'price' => 99.99, 'unit' => 'kg'),
)));
$SHOP = array('currency' => 'EGP', 'catalog' => array('items' => array(
    array('name' => 'قص شعر', 'price' => 300, 'unit' => 'session'),
)));

// ------------------------------------------------------------ legacy, before the migration
echo "== legacy (before the migration: no scope columns) ==\n";
$CI->db = fresh_db(false);
seed_row($CI->db, 7, '', '', $BYOOT);
same('pre-migration: scope columns not detected', false, pricing_has_scope_columns());
same('pre-migration: 1-arg pricing_get_config returns the one list', 4500, pricing_get_config(7)['accommodation']['rooms'][0]['nightly']);
same('pre-migration: a page scope still falls back to that list', 4500, pricing_get_config(7, array('fb', '1'))['accommodation']['rooms'][0]['nightly']);
list($okp, $errp) = pricing_save_config(7, $SALON, 7, 'x', array('fb', '1'));
same('pre-migration: saving a page list is refused, not silently written to default', false, $okp);
list($okd) = pricing_save_config(7, $BYOOT, 7, 'same');
same('pre-migration: default save still works', true, $okd);

// ------------------------------------------------------------ after the migration
$CI->db = $db = fresh_db(true);
same('scope columns detected', true, pricing_has_scope_columns());
// tenant 7: two FB pages, one IG-linked, an openwa + a cloud number sharing id 5, a bot, a widget
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => '100200300400', 'page_name' => 'Byoot Resort', 'instagram_business_account_id' => '1784001'));
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => '100200300500', 'page_name' => 'Byoot Salon', 'instagram_business_account_id' => ''));
$db->insert('facebook_rx_fb_page_info', array('user_id' => 8, 'page_id' => '999000999000', 'page_name' => 'Other tenant page', 'instagram_business_account_id' => ''));
for ($i = 0; $i < 4; $i++) $db->insert('openwa_accounts', array('user_id' => 99, 'session_id' => 'pad' . $i, 'label' => 'x', 'display_phone' => ''));
$db->insert('openwa_accounts', array('user_id' => 7, 'session_id' => 'byoot-wa', 'label' => 'Byoot personal', 'display_phone' => ''));      // id 5
for ($i = 0; $i < 4; $i++) $db->insert('whatsapp_accounts', array('user_id' => 99, 'label' => 'x', 'display_phone' => ''));
$db->insert('whatsapp_accounts', array('user_id' => 7, 'label' => 'Salon Cloud', 'display_phone' => '+20100'));                             // id 5
$db->insert('telegram_accounts', array('user_id' => 7, 'bot_username' => 'byoot_bot'));
$db->insert('webchat_settings', array('user_id' => 7, 'widget_key' => 'wk_first', 'title' => 'Site A'));
$db->insert('webchat_settings', array('user_id' => 7, 'widget_key' => 'wk_second', 'title' => 'Site B'));
$db->insert('webchat_settings', array('user_id' => 8, 'widget_key' => 'wk_foreign', 'title' => 'Other'));

echo "\n== pricing_resolve_scope per channel ==\n";
same('fb: Messenger passes the FB page id string -> page row id', array('fb', '1'), pricing_resolve_scope(7, 'fb', '100200300400'));
same('fb: a page_table_id also resolves', array('fb', '2'), pricing_resolve_scope(7, 'fb', '2'));
same('ig: the FB page id -> the same page list', array('fb', '1'), pricing_resolve_scope(7, 'ig', '100200300400'));
same('ig: the Instagram business id -> the linked page', array('fb', '1'), pricing_resolve_scope(7, 'ig', '1784001'));
same('fb: another tenant\'s page never resolves (default)', array('', ''), pricing_resolve_scope(7, 'fb', '999000999000'));
same('wa: Openwa_bot passes session_id -> openwa #5', array('openwa', '5'), pricing_resolve_scope(7, 'wa', 'byoot-wa'));
same('wa: Whatsapp_bot passes account id 5 -> wa_cloud #5', array('wa_cloud', '5'), pricing_resolve_scope(7, 'wa', '5'));
check('openwa #5 and wa_cloud #5 are different scopes', pricing_resolve_scope(7, 'wa', 'byoot-wa') !== pricing_resolve_scope(7, 'wa', '5'));
$db->insert('openwa_accounts', array('user_id' => 7, 'session_id' => '5', 'label' => 'numeric session', 'display_phone' => ''));
same('wa: a numeric session name equal to a cloud id is ambiguous -> default, never a guess', array('', ''), pricing_resolve_scope(7, 'wa', '5'));
unset($db->tables['openwa_accounts'][count($db->tables['openwa_accounts'])]);
same('wa: another tenant\'s cloud number never resolves', array('', ''), pricing_resolve_scope(7, 'wa', '1'));
same('tg: telegram account id', array('tg', '1'), pricing_resolve_scope(7, 'tg', '1'));
same('tg: someone else\'s / missing bot -> default', array('', ''), pricing_resolve_scope(7, 'tg', '42'));
same('web: widget_key', array('web', 'wk_second'), pricing_resolve_scope(7, 'web', 'wk_second'));
same('web: legacy "webchat" sentinel -> first widget', array('web', 'wk_first'), pricing_resolve_scope(7, 'web', 'webchat'));
same('web: another tenant\'s widget key -> default', array('', ''), pricing_resolve_scope(7, 'web', 'wk_foreign'));
same('unknown channel (tiktok) -> default', array('', ''), pricing_resolve_scope(7, 'tiktok', ''));
same('no user -> default', array('', ''), pricing_resolve_scope(0, 'fb', '100200300400'));

echo "\n== fallback: page -> default -> null ==\n";
same('no lists at all -> null', null, pricing_get_config(7, array('fb', '1')));
seed_row($db, 7, '', '', $BYOOT);
same('page without own list -> default list', 4500, pricing_get_config(7, array('fb', '2'))['accommodation']['rooms'][0]['nightly']);
seed_row($db, 7, 'fb', '2', $SALON);
same('page with own list -> its own list', 'قص شعر', pricing_get_config(7, array('fb', '2'))['catalog']['items'][0]['name']);
same('legacy 1-arg call still returns the default list, not a page list', 4500, pricing_get_config(7)['accommodation']['rooms'][0]['nightly']);
$db->update('pricing_config', array('status' => '0')); // all off
$db->where('channel_type', 'fb')->update('pricing_config', array('status' => '1'));
same('default switched off: legacy call -> null', null, pricing_get_config(7));
same('default switched off: page with own list still priced', 'قص شعر', pricing_get_config(7, array('fb', '2'))['catalog']['items'][0]['name']);
same('default switched off: other page -> null', null, pricing_get_config(7, array('fb', '1')));
$db->update('pricing_config', array('status' => '1'));
$db->where('channel_type', 'fb')->update('pricing_config', array('status' => '0'));
same('page list switched off -> falls back to the default list', 4500, pricing_get_config(7, array('fb', '2'))['accommodation']['rooms'][0]['nightly']);
$db->update('pricing_config', array('status' => '1'));
same('tenant 8 never sees tenant 7\'s lists', null, pricing_get_config(8, array('fb', '2')));
same('scope string form "fb:2" works too', 'قص شعر', pricing_get_config(7, 'fb:2')['catalog']['items'][0]['name']);
same('invalid scope string -> default', array('', ''), pricing_normalize_scope('evil:1'));

echo "\n== pricing_calc_item exactness ==\n";
$r = pricing_calc_item($SALON, 'haircut', '', 3);
same('alias resolves + price × qty (3 × 150)', 450, $r['total']);
same('unit price reported', 150, $r['unit_price']);
$r = pricing_calc_item($SALON, 'قص شعر', 'أطفال', 2);
same('variant price overrides the base price (2 × 100)', 200, $r['total']);
same('variant named in the result', 'أطفال', $r['variant']);
$r = pricing_calc_item($SALON, 'صبغة كاملة', '', 3);
same('decimal price exact: 3 × 12.5 = 37.5', 37.5, $r['total']);
$r = pricing_calc_item($SALON, 'Coffee beans', '', 3);
same('no float drift: 3 × 99.99 = 299.97', 299.97, $r['total']);
$r = pricing_calc_item($SALON, 'Coffee beans', '', 0.5);
same('fractional quantity (0.5 kg × 99.99 rounds to 50)', 50, $r['total']);
$r = pricing_calc_item($SALON, 'Coffee beans', '', 0);
same('quantity 0 / missing defaults to 1', 99.99, $r['total']);
$r = pricing_calc_item($SALON, 'Bridal package', '', 1);
same('price on request -> no total', null, $r['total']);
same('price on request -> needs_team', true, $r['needs_team']);
$r = pricing_calc_item($SALON, 'كيراتين', '', 1);
same('ambiguous alias -> no total', null, $r['total']);
same('ambiguous alias -> needs_team', true, $r['needs_team']);
same('ambiguous alias -> both candidates', array('Keratin Short', 'Keratin Long'), $r['candidates']);
$r = pricing_calc_item($SALON, 'Keratin Long', '', 1);
same('the exact name wins over the shared alias', 1400, $r['total']);
$r = pricing_calc_item($SALON, 'massage', '', 1);
same('unknown item -> ok false', false, $r['ok']);
same('unknown item -> reason unknown', 'unknown', $r['reason']);
$r = pricing_calc_item($SALON, 'T-shirt', '', 2);
same('variants but no base price and no option named -> ask (no total)', null, $r['total']);
same('... offering the options', array('Small', 'Large'), $r['candidates']);
$r = pricing_calc_item($SALON, 'T-shirt', 'large', 2);
same('option matched case-insensitively (2 × 250)', 500, $r['total']);
$r = pricing_calc_item($SALON, 'T-shirt', 'XXL', 1);
same('unknown option -> no total', null, $r['total']);
same('no catalog -> ok false', false, pricing_calc_item($BYOOT, 'x', '', 1)['ok']);

echo "\n== validator ==\n";
same('catalog-only config is valid', array(), pricing_validate_config($SALON));
same('the Byoot rooms + day-use config is still valid', array(), pricing_validate_config($BYOOT));
same('catalog + rooms together is valid', array(), pricing_validate_config(array_merge($BYOOT, array('catalog' => $SHOP['catalog']))));
$bad = $SALON; $bad['catalog']['items'][1]['name'] = 'قص شعر';
has('duplicate product name rejected', implode('|', pricing_validate_config($bad)), 'duplicate product name');
$bad = $SALON; $bad['catalog']['items'][2]['price'] = 0;
has('price 0 rejected', implode('|', pricing_validate_config($bad)), 'price must be > 0');
$bad = $SALON; unset($bad['catalog']['items'][2]['price']);
has('missing price without price-on-request rejected', implode('|', pricing_validate_config($bad)), 'price must be > 0');
$bad = $SALON; $bad['catalog']['items'][0]['unit'] = 'litre';
has('invalid unit rejected', implode('|', pricing_validate_config($bad)), 'unit must be one of');
$bad = $SALON; unset($bad['catalog']['items'][1]['unit_label']);
has('custom unit without a name rejected', implode('|', pricing_validate_config($bad)), 'write the unit name');
$bad = $SALON; $bad['catalog']['items'][0]['variants'][1]['price'] = -5;
has('bad option price rejected', implode('|', pricing_validate_config($bad)), 'option "رجالي" price must be > 0');
$bad = $SALON; $bad['catalog']['items'][0]['variants'][1]['name'] = 'أطفال';
has('duplicate option rejected', implode('|', pricing_validate_config($bad)), 'duplicate option');
$bad = $SALON; $bad['catalog']['items'][0]['aliases'] = 'haircut';
has('aliases must be a list', implode('|', pricing_validate_config($bad)), 'other names must be a list');
has('an empty catalog alone is rejected', implode('|', pricing_validate_config(array('catalog' => array('items' => array())))), 'at least one product');
has('no sections at all rejected', implode('|', pricing_validate_config(array('currency' => 'x'))), 'at least one product');
$bad = $BYOOT; $bad['accommodation']['rooms'][0]['nightly'] = 0;
has('existing room validation kept', implode('|', pricing_validate_config($bad)), 'nightly price must be > 0');

echo "\n== save / copy / move / delete, audited per scope ==\n";
$CI->db = $db = fresh_db(true);
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => '100200300400', 'page_name' => 'Byoot Resort', 'instagram_business_account_id' => ''));
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => '100200300500', 'page_name' => 'Byoot Salon', 'instagram_business_account_id' => ''));
list($ok) = pricing_save_config(7, $BYOOT, 7, 'initial');
same('default save ok', true, $ok);
list($ok) = pricing_save_config(7, $SALON, 7, 'salon', array('fb', '2'));
same('page save ok', true, $ok);
same('two lists for the tenant', 2, count($db->tables['pricing_config']));
$h = array_values($db->tables['pricing_config_history']);
same('history row records the page it was for', array('fb', '2'), array($h[1]['channel_type'], $h[1]['target_id']));
same('default history row has the empty scope', array('', ''), array($h[0]['channel_type'], $h[0]['target_id']));
pricing_save_config(7, $SALON, 7, 'same', array('fb', '2'));
same('unchanged page save adds no history', 2, count($db->tables['pricing_config_history']));
list($ok, , $code) = pricing_copy_config(7, array('fb', '2'), array('fb', '1'), 7);
same('copy page 2 -> page 1', true, $ok);
same('copy leaves the source untouched', 'قص شعر', pricing_get_config(7, array('fb', '2'))['catalog']['items'][0]['name']);
same('copy target now has the list', 'قص شعر', pricing_get_config(7, array('fb', '1'))['catalog']['items'][0]['name']);
list($ok, , $code) = pricing_copy_config(7, array('', ''), array('fb', '1'), 7);
same('copy onto an existing list refused without overwrite', 'exists', $code);
list($ok) = pricing_delete_config(7, array('fb', '1'), 7, 'rm');
same('delete ok', true, $ok);
same('deleted page falls back to default', 4500, pricing_get_config(7, array('fb', '1'))['accommodation']['rooms'][0]['nightly']);
list($ok, , $code) = pricing_move_config(7, array('', ''), array('fb', '2'), 7);
same('move default onto a page with a list refused without overwrite', 'exists', $code);
list($ok, , $code) = pricing_move_config(7, array('', ''), array('fb', '1'), 7, false, 'moved');
same('move default -> page 1', true, $ok);
same('page 1 now has the (former default) Byoot list', 4500, pricing_get_config(7, array('fb', '1'))['accommodation']['rooms'][0]['nightly']);
same('no default list any more', null, pricing_get_config(7));
same('page 2 keeps its own list', 'قص شعر', pricing_get_config(7, array('fb', '2'))['catalog']['items'][0]['name']);
same('two rows remain (moved, not copied)', 2, count($db->tables['pricing_config']));

echo "\n== IDOR gate + connected targets ==\n";
$db->insert('facebook_rx_fb_page_info', array('user_id' => 8, 'page_id' => '999000999000', 'page_name' => 'Other tenant page', 'instagram_business_account_id' => ''));
$db->insert('whatsapp_accounts', array('user_id' => 7, 'label' => 'Cloud', 'display_phone' => ''));
$db->insert('openwa_accounts', array('user_id' => 7, 'session_id' => 's1', 'label' => 'Personal', 'display_phone' => ''));
same('own page is owned', true, pricing_target_owned(7, array('fb', '1')));
same('another tenant\'s page (fb:3) is NOT owned', false, pricing_target_owned(7, array('fb', '3')));
same('the default scope is always allowed', true, pricing_target_owned(7, array('', '')));
$scopes = array_map(function ($t) { return $t['scope']; }, pricing_connected_targets(7));
same('targets: pages, cloud and openwa numbers with distinct channel types', array('fb:1', 'fb:2', 'wa_cloud:1', 'openwa:1'), $scopes);
same('targets carry the page name, not a raw id', 'Byoot Resort', pricing_connected_targets(7)[0]['label']);

echo "\n== rule 10 + tool definition ==\n";
$LEGACY_RULE = "\n10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer gives a COUNT you can price: a number of nights, OR a number of people/adults, OR a headcount for day-use. 'داي يوز لـ5 أفراد', 'اقامة ليلتين', '4 كبار وطفلين' all mean CALL THE TOOL now with what you have - do not ask more questions first if you already have a count, and never answer such a question with 'بيبدأ من' when the customer gave you a specific number. Quote the tool's total exactly. If it returns NO FINAL TOTAL, tell the customer the team will confirm and ask for their WhatsApp - do not invent a total.";
same('no config -> no rule 10', '', pricing_prompt_rule(null));
same('rooms + day-use list -> the original rule 10, byte for byte', $LEGACY_RULE, pricing_prompt_rule($BYOOT));
$rule = pricing_prompt_rule($SALON);
has('catalog-only rule mentions items', $rule, 'product or service');
check('catalog-only rule does not mention stays or day-use', strpos($rule, 'nights') === false && strpos($rule, 'day-use') === false, $rule);
$def = pricing_tool_definition($BYOOT);
same('rooms + day-use list -> the original tool enum', array('accommodation', 'day_use'), $def['params']['properties']['type']['enum']);
has('... and the original description', $def['description'], 'Compute an EXACT price total for accommodation or day-use.');
same('no config -> the original tool definition', $def, pricing_tool_definition(null));
$def = pricing_tool_definition($SALON);
same('catalog-only tool offers only type item', array('item'), $def['params']['properties']['type']['enum']);
check('catalog-only tool has no nights/adults params', !isset($def['params']['properties']['nights']) && !isset($def['params']['properties']['adults']));
check('catalog-only tool description does not mention accommodation/day-use', stripos($def['description'], 'accommodation') === false && stripos($def['description'], 'day_use') === false, $def['description']);
$def = pricing_tool_definition(array_merge($BYOOT, array('catalog' => $SHOP['catalog'])));
same('catalog + stays tool enum', array('accommodation', 'day_use', 'item'), $def['params']['properties']['type']['enum']);

echo "\n== Ai_tools::execute is page-aware and feeds the price guard ==\n";
$CI->db = $db = fresh_db(true);
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => 'PAGE_A', 'page_name' => 'A', 'instagram_business_account_id' => ''));
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => 'PAGE_B', 'page_name' => 'B', 'instagram_business_account_id' => ''));
$db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => 'PAGE_C', 'page_name' => 'C', 'instagram_business_account_id' => ''));
seed_row($db, 7, 'fb', '1', $SALON);
seed_row($db, 7, 'fb', '2', $SHOP);
seed_row($db, 7, '', '', $BYOOT);
$tools = new Ai_tools();
$ctx = function ($p) { return array('user_id' => 7, 'page_id' => $p, 'subscribe_id' => 's', 'social_media' => 'fb'); };
$CI->ai_price_facts = array();
$a = $tools->execute('calculate_price', array('type' => 'item', 'item' => 'قص شعر', 'quantity' => 2), $ctx('PAGE_A'));
has('page A: 2 × 150 = 300 جنيه', $a, '300 جنيه');
has('page A result lands in ai_price_facts', implode('|', $CI->ai_price_facts), '300 جنيه');
$CI->ai_price_facts = array();
$b = $tools->execute('calculate_price', array('type' => 'item', 'item' => 'قص شعر', 'quantity' => 2), $ctx('PAGE_B'));
has('page B: same item, its own price 2 × 300 = 600 EGP', $b, '600 EGP');
check('page B never quotes page A\'s price', strpos($b, '300') === false || strpos($b, '600') !== false, $b);
$c = $tools->execute('calculate_price', array('type' => 'accommodation', 'room_or_package' => 'Double Garden', 'nights' => 4, 'adults' => 2), $ctx('PAGE_C'));
has('page C (no own list) -> default list: Byoot 4-night offer 11,500', $c, '11,500');
$u = $tools->execute('calculate_price', array('type' => 'item', 'item' => 'massage'), $ctx('PAGE_A'));
has('unknown item -> team confirms, no price', $u, 'UNKNOWN ITEM');
$k = $tools->execute('calculate_price', array('type' => 'item', 'item' => 'كيراتين'), $ctx('PAGE_A'));
has('ambiguous item -> ask which one', $k, 'Keratin Short / Keratin Long');
$p = $tools->execute('calculate_price', array('type' => 'item', 'item' => 'Bridal package'), $ctx('PAGE_A'));
has('price on request -> NO FINAL TOTAL', $p, 'NO FINAL TOTAL');
$d = $tools->execute('calculate_price', array('item' => 'haircut'), $ctx('PAGE_A'));
has('catalog-only page: a call without type is priced as an item', $d, '150 جنيه');
$tn = $tools->catalog($ctx('PAGE_A'));
$cp = array_values(array_filter($tn, function ($t) { return $t['name'] === 'calculate_price'; }))[0];
same('tool definition for page A is the catalog one', array('item'), $cp['params']['properties']['type']['enum']);
$tn = $tools->catalog($ctx('PAGE_C'));
$cp = array_values(array_filter($tn, function ($t) { return $t['name'] === 'calculate_price'; }))[0];
same('tool definition for page C (default Byoot list) is the original one', pricing_tool_definition(null), $cp);

echo "\n== shared resolver unchanged for rooms (media_find) ==\n";
same('media_find exact room', 'Double Pool View', media_find($BYOOT, 'Double Pool View')['name']);
same('media_find ambiguous "Pool View" -> null', null, media_find($BYOOT, 'Pool View'));
same('media_match reports the ambiguity', 3, count(media_match($BYOOT, 'Pool View')['candidates']));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
