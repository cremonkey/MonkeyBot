<?php
/**
 * AI safety follow-ups (baseline items 17 and 18). DB-free unit tests: no CodeIgniter
 * bootstrap, no database, no network. get_instance() returns a tiny fake whose ->db is an
 * in-memory stand-in (query builder + the handful of raw SQL statements the code under
 * test issues), and whose model libraries are stubs that record what they were asked.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ai_safety/run.php
 *
 * Covers:
 *  1. Preview follows the provider: ai_agent_preview_reply() goes through the real
 *     Ai_provider, so an Anthropic tenant is answered by Anthropic with the Anthropic key;
 *     no key / no row / provider error -> status '0' + reason, never a made-up reply.
 *  2. Price guard fail-closed: figure extraction, the action matrix for flag off (default,
 *     'would_block', reply untouched) and on ('block'), the hand-off text, and the handoff
 *     flag through the conversations state machine (with the Ai_tools fallback).
 *  3. Cost accounting: preview, both tool loops (every round), and a static sweep that every
 *     direct model HTTP call in application/ records usage.
 *  4. KB ownership: a static sweep of the KB / FAQ / missed-question controllers that every
 *     statement addressing a row by id also filters by the tenant.
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
$LOG = array();
function log_message($l, $m) { global $LOG; $LOG[] = array($l, $m); }

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
    public $tables = array();
    public $fields = array();
    public $sql = array();
    private $auto = array();
    private $t; private $w = array(); private $lim = null;
    private $affected = 0; private $in_tx = false;

    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($f, $t) { return in_array($f, $this->fields[$t] ?? array(), true); }
    public function select($c) { return $this; }
    public function from($t) { $this->t = $t; return $this; }
    public function where($k, $v = null) { $this->w[] = array($k, $v); return $this; }
    public function limit($n) { $this->lim = $n; return $this; }
    private function reset() { $this->w = array(); $this->lim = null; }
    private function filter($rows, $conds) {
        foreach ($conds as $c) {
            list($k, $v) = $c;
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) { return array_key_exists($k, $r) && (string) $r[$k] === (string) $v; }));
        }
        return $rows;
    }
    public function get() {
        $rows = $this->filter(array_values($this->tables[$this->t] ?? array()), $this->w);
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        $this->reset();
        return new FakeResult($rows);
    }
    public function insert($t, $data) {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data; $this->reset();
        return true;
    }
    public function affected_rows() { return $this->affected; }
    public function trans_begin() { $this->in_tx = true; }
    public function trans_commit() { $this->in_tx = false; }
    public function trans_rollback() { $this->in_tx = false; }
    public function trans_status() { return true; }
    public function error() { return array('message' => ''); }

    /** The raw statements conversation_helper / ai_safety_helper issue, and nothing else. */
    public function query($sql, $b = array()) {
        $s = preg_replace('/\s+/', ' ', trim($sql));
        $this->sql[] = $s;
        if (strpos($s, 'SELECT id, channel_account_id, handoff_state FROM conversations WHERE user_id = ? AND platform = ? AND external_thread_id = ?') === 0) {
            return new FakeResult($this->filter(array_values($this->tables['conversations']), array(array('user_id', $b[0]), array('platform', $b[1]), array('external_thread_id', $b[2]))));
        }
        if (strpos($s, 'SELECT * FROM conversations WHERE id = ?') === 0) {
            $c = array(array('id', $b[0]));
            if (strpos($s, 'AND user_id = ?') !== false) $c[] = array('user_id', $b[1]);
            return new FakeResult($this->filter(array_values($this->tables['conversations']), $c));
        }
        if (strpos($s, 'UPDATE conversations SET handoff_state = ?, status = ?, updated_at = ? WHERE id = ?') === 0) {
            $this->tables['conversations'][$b[3]]['handoff_state'] = $b[0];
            $this->tables['conversations'][$b[3]]['status'] = $b[1];
            return true;
        }
        if (strpos($s, 'UPDATE messenger_bot_subscriber SET bot_paused_until = ? WHERE user_id = ? AND social_media = ? AND page_table_id = ? AND subscribe_id = ?') === 0) {
            $this->affected = 0;
            foreach ($this->tables['messenger_bot_subscriber'] as $id => $r) {
                if ((string) $r['user_id'] === (string) $b[1] && $r['social_media'] === $b[2] && (string) $r['page_table_id'] === (string) $b[3] && (string) $r['subscribe_id'] === (string) $b[4]) {
                    $this->tables['messenger_bot_subscriber'][$id]['bot_paused_until'] = $b[0];
                    $this->affected++;
                }
            }
            return true;
        }
        if (strpos($s, 'SELECT id FROM facebook_rx_fb_page_info WHERE user_id = ? AND page_id = ?') === 0) {
            return new FakeResult($this->filter(array_values($this->tables['facebook_rx_fb_page_info']), array(array('user_id', $b[0]), array('page_id', $b[1]))));
        }
        throw new Exception('FakeDb: unexpected SQL: ' . $s);
    }
}

class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? null; } }
class FakeLoader
{
    public function helper($h) { foreach ((array) $h as $n) require_once APPPATH . 'helpers/' . $n . '_helper.php'; }
    public function library($l) {
        global $CI;
        $prop = strtolower($l);
        if (isset($CI->$prop)) return;
        if ($l === 'Ai_provider') { require_once APPPATH . 'libraries/Ai_provider.php'; $CI->ai_provider = new Ai_provider(); return; }
        throw new Exception("FakeLoader: library $l not stubbed");
    }
}
class FakeCI { public $db; public $load; public $config; public $ai_price_facts = array(); }

/** Records every call; answers from a script (one entry per call, last one repeats). */
class StubModel
{
    public $calls = array(); public $script = array();
    public function __construct($script) { $this->script = $script; }
    private function next() { $r = count($this->script) > 1 ? array_shift($this->script) : $this->script[0]; return is_string($r) ? $r : json_encode($r); }
    public function anthropic_completion($key, $messages, $model, $max_tokens, $system = '', $temperature = 0.7, $tools = null) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'system', 'temperature', 'tools'); return $this->next();
    }
    public function open_ai_completion($key, $messages, $model, $max_tokens, $instruction = '', $description = '', $human = '', $temperature = 0.4, $tools = null) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'temperature', 'tools'); return $this->next();
    }
}
class StubTools
{
    public $calls = array();
    public function openai_tools($ctx) { return array(array('type' => 'function', 'function' => array('name' => 'calculate_price'))); }
    public function anthropic_tools($ctx) { return array(array('name' => 'calculate_price')); }
    public function execute($name, $args, $ctx) { $this->calls[] = array($name, $args, $ctx); return 'tool-result'; }
}

$CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig();
function &get_instance() { global $CI; return $CI; }

function fresh_ci()
{
    global $CI;
    $CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig();
    $CI->db = new FakeDb();
    $CI->db->tables = array('open_ai_config' => array(), 'ai_usage_log' => array(), 'conversations' => array(),
        'conversation_events' => array(), 'messenger_bot_subscriber' => array(), 'facebook_rx_fb_page_info' => array());
    $CI->db->fields['ai_usage_log'] = array('id', 'user_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'purpose', 'created_at', 'units', 'estimated_cost_usd');
    return $CI;
}

require APPPATH . 'helpers/secret_helper.php';
require APPPATH . 'helpers/ai_usage_helper.php';
require APPPATH . 'helpers/ai_agent_helper.php';
require APPPATH . 'helpers/ai_safety_helper.php';
require APPPATH . 'helpers/conversation_helper.php';
require APPPATH . 'libraries/Ai_provider.php';

$ANTHROPIC_OK = array('choices' => array(array('text' => 'أهلاً! الغرفة بتبدأ من 4,500 جنيه لليلة. تحب تحجز إمتى؟')), 'content' => array(), 'stop_reason' => 'end_turn',
    'usage' => array('input_tokens' => 120, 'output_tokens' => 30), 'model' => 'claude-haiku-4-5');
$OPENAI_OK = array('choices' => array(array('message' => array('role' => 'assistant', 'content' => 'Hi!'), 'text' => 'Hi!')),
    'usage' => array('prompt_tokens' => 80, 'completion_tokens' => 5));

// =================================================================== 1. preview
echo "== 1. preview follows the provider ==\n";

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'sk-ant-7', 'anthropic_model' => 'claude-sonnet-4-5',
    'open_ai_secret_key' => '', 'models' => 'gpt-4o-mini', 'sales_system_prompt' => 'MASTER: never invent prices', 'maximum_token' => '', 'temperature' => ''));
$CI->anthropic_api = new StubModel(array($ANTHROPIC_OK));
$CI->openai_api = new StubModel(array($OPENAI_OK));
$r = ai_agent_preview_reply(7, 'بكام الغرفة؟', array('agent_name' => 'Nour', 'instruction_to_ai' => 'You sell rooms', 'sales_system_prompt' => 'Room from 4,500'));
same('anthropic tenant: status 1', '1', $r['status']);
same('anthropic tenant: reply is the model text', $ANTHROPIC_OK['choices'][0]['text'], $r['reply'] ?? null);
same('anthropic tenant: exactly one Anthropic call', 1, count($CI->anthropic_api->calls));
same('anthropic tenant: OpenAI never called', 0, count($CI->openai_api->calls));
same('anthropic tenant: the Anthropic key is used', 'sk-ant-7', $CI->anthropic_api->calls[0]['key']);
same('anthropic tenant: the tenant\'s Anthropic model', 'claude-sonnet-4-5', $CI->anthropic_api->calls[0]['model']);
same('anthropic tenant: preview temperature kept (0.4)', 0.4, $CI->anthropic_api->calls[0]['temperature']);
$sys = $CI->anthropic_api->calls[0]['messages'][0];
check('anthropic tenant: master + bot layers + guardrails in the system turn', $sys['role'] === 'system'
    && strpos($sys['content'], 'MASTER: never invent prices') !== false && strpos($sys['content'], 'AGENT IDENTITY: Nour') !== false
    && strpos($sys['content'], 'STRICT SCOPE RULES') !== false, json_encode($sys, JSON_UNESCAPED_UNICODE));
same('anthropic tenant: no tools offered in preview', null, $CI->anthropic_api->calls[0]['tools']);
$u = array_values($CI->db->tables['ai_usage_log']);
same('accounting: one usage row for the preview', 1, count($u));
same('accounting: provider anthropic', 'anthropic', $u[0]['provider'] ?? null);
same('accounting: purpose agent_preview', 'agent_preview', $u[0]['purpose'] ?? null);
same('accounting: tokens from the Anthropic usage block', array(120, 30), array($u[0]['input_tokens'] ?? null, $u[0]['output_tokens'] ?? null));
same('accounting: tenant id', 7, $u[0]['user_id'] ?? null);
check('accounting: estimated cost stored', isset($u[0]['estimated_cost_usd']) && $u[0]['estimated_cost_usd'] > 0);

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => '', 'open_ai_secret_key' => 'sk-openai-7', 'models' => 'gpt-4o-mini'));
$CI->anthropic_api = new StubModel(array($ANTHROPIC_OK)); $CI->openai_api = new StubModel(array($OPENAI_OK));
$r = ai_agent_preview_reply(7, 'hi');
same('anthropic tenant without an Anthropic key: status 0', '0', $r['status']);
same('anthropic tenant without an Anthropic key: says so', 'No Anthropic key', $r['message'] ?? null);
check('anthropic tenant without an Anthropic key: never falls back to the OpenAI key', count($CI->openai_api->calls) === 0 && count($CI->anthropic_api->calls) === 0);
check('no key: no reply field at all (never fabricated)', !isset($r['reply']));

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 8, 'ai_provider' => 'openai', 'open_ai_secret_key' => 'sk-openai-8', 'models' => 'gpt-4.1-mini', 'anthropic_secret_key' => 'sk-ant-unused'));
$CI->anthropic_api = new StubModel(array($ANTHROPIC_OK)); $CI->openai_api = new StubModel(array($OPENAI_OK));
$r = ai_agent_preview_reply(8, 'hello');
same('openai tenant: status 1, OpenAI reply', array('1', 'Hi!'), array($r['status'], $r['reply'] ?? null));
same('openai tenant: OpenAI key + model', array('sk-openai-8', 'gpt-4.1-mini'), array($CI->openai_api->calls[0]['key'] ?? null, $CI->openai_api->calls[0]['model'] ?? null));
same('openai tenant: Anthropic never called', 0, count($CI->anthropic_api->calls));
$u = array_values($CI->db->tables['ai_usage_log']);
same('openai tenant: usage row openai/agent_preview 80/5', array('openai', 'agent_preview', 80, 5), array($u[0]['provider'] ?? null, $u[0]['purpose'] ?? null, $u[0]['input_tokens'] ?? null, $u[0]['output_tokens'] ?? null));

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 9, 'open_ai_secret_key' => '', 'models' => ''));
$CI->openai_api = new StubModel(array($OPENAI_OK));
same('legacy row (no ai_provider) without key: No OpenAI key', array('0', 'No OpenAI key'), array_values(ai_agent_preview_reply(9, 'x')));
same('no config row: No AI credentials configured', array('0', 'No AI credentials configured'), array_values(ai_agent_preview_reply(99, 'x')));
same('empty message: rejected before any lookup', array('0', 'Type a test message'), array_values(ai_agent_preview_reply(9, '   ')));

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'sk-ant-7'));
$CI->anthropic_api = new StubModel(array(array('error' => array('message' => 'overloaded'))));
$r = ai_agent_preview_reply(7, 'hi');
same('provider error: status 0 with the provider reason', array('0', 'Preview failed (anthropic: overloaded)'), array($r['status'], $r['message'] ?? null));
same('provider error: no usage row (nothing billed)', 0, count($CI->db->tables['ai_usage_log']));
$CI->anthropic_api = new StubModel(array(array('choices' => array(array('text' => '   ')), 'usage' => array('input_tokens' => 5, 'output_tokens' => 0))));
$r = ai_agent_preview_reply(7, 'hi');
same('empty model text: status 0, not an empty "reply"', array('0', false), array($r['status'], isset($r['reply'])));

same('provider plan: anthropic default model', 'claude-haiku-4-5', ai_agent_preview_provider(array('ai_provider' => 'anthropic', 'anthropic_secret_key' => 'k'))['model']);
same('provider plan: unknown provider value -> openai (as Ai_provider)', 'openai', ai_agent_preview_provider(array('ai_provider' => 'gemini', 'open_ai_secret_key' => 'k'))['provider']);

$src = file_get_contents(APPPATH . 'helpers/ai_agent_helper.php');
check('preview no longer makes its own HTTP call', strpos($src, 'api.openai.com') === false && strpos($src, 'curl_init(') === false);

// =================================================================== 2. price guard
echo "== 2. price guard fail-closed ==\n";

same('figures: grouped + currency, Arabic', array('4500', '900'), ai_price_figures('الغرفة بـ 4,500 والداي يوز 900 جنيه'));
same('figures: Arabic-Indic digits', array('5500'), ai_price_figures('السعر ٥٬٥٠٠ جنيه'));
same('figures: EGP before, $ after, decimals', array('350', '12.5'), ai_price_figures('EGP 350 or 12.50$'));
same('figures: bare small numbers are not prices', array(), ai_price_figures('ممكن 2 ليالي أو 24 ساعة؟ كلمنا على 01001234567'));
same('figures: no digits', array(), ai_price_figures('أهلاً بيك'));

$facts = "Double room from EGP 4,500/night\nDay use 900\n\nComputed totals (authoritative, from the pricing tool):\n- Double x 3 nights = 13500 EGP";
same('unverified: all written in facts', array(), ai_price_unverified_figures('الدبل بـ4500 لليلة، و3 ليالي 13,500 جنيه', $facts));
same('unverified: invented figure caught', array('350'), ai_price_unverified_figures('الداي يوز بـ350 جنيه', $facts));
same('unverified: a calculate_price total (ai_price_facts array) counts as written', array(), ai_price_unverified_figures('الإجمالي 9,000 جنيه', array('Double x 2 nights = 9000 EGP')));
same('unverified: empty facts -> every figure unverified', array('900'), ai_price_unverified_figures('900 جنيه', ''));

same('action: ungrounded -> deflect (existing path)', 'deflect', ai_price_guard_action('ungrounded', '350 جنيه', $facts, false)['action']);
same('action: ungrounded -> deflect with the flag on too', 'deflect', ai_price_guard_action('ungrounded', '350 جنيه', $facts, true)['action']);
same('action: grounded -> send', 'send', ai_price_guard_action('grounded', '350 جنيه', $facts, true)['action']);
same('action: unknown, figures written -> send (flag on)', 'send', ai_price_guard_action('unknown', '900 جنيه', $facts, true)['action']);
same('action: unknown, invented figure, flag OFF -> would_block', array('action' => 'would_block', 'unverified' => array('350')), ai_price_guard_action('unknown', '350 جنيه', $facts, false));
same('action: unknown, invented figure, flag ON -> block', array('action' => 'block', 'unverified' => array('350')), ai_price_guard_action('unknown', '350 جنيه', $facts, true));
same('action: judge threw (error), flag ON -> block', 'block', ai_price_guard_action('error', '350 جنيه', $facts, true)['action']);
same('action: empty source (judge said unknown), flag ON -> block', 'block', ai_price_guard_action('unknown', '4,500 جنيه', '', true)['action']);

fresh_ci();
same('flag: unset -> off (default)', false, ai_price_guard_fail_closed_enabled());
foreach (array(array(false, false), array('0', false), array('', false), array(0, false), array(true, true), array('1', true), array('TRUE', true), array(1, true)) as $c) {
    $CI->config->items['price_guard_fail_closed'] = $c[0];
    same('flag: ' . var_export($c[0], true) . ' -> ' . var_export($c[1], true), $c[1], ai_price_guard_fail_closed_enabled());
}
check('hand-off text: Egyptian Arabic for Arabic, confirms price via the team', strpos(ai_price_guard_handoff_text('بكام؟'), 'الفريق') !== false && strpos(ai_price_guard_handoff_text('بكام؟'), 'السعر') !== false);
check('hand-off text: English for English, and contains no figure', preg_match('/\d/', ai_price_guard_handoff_text('how much?')) === 0 && stripos(ai_price_guard_handoff_text('how much?'), 'team') !== false);

// Home wiring (static): the reply is only rewritten on 'deflect' (existing) or 'block'; the
// default path changes no text; the flag is read, not a file under application/config.
$home = file_get_contents(APPPATH . 'controllers/Home.php');
$i = strpos($home, '$pg_closed = ai_price_guard_fail_closed_enabled();');
$seg = substr($home, $i, strpos($home, '// Availability/booking guard', $i) - $i);
check('Home: fail-closed wiring present', $i !== false);
check('Home: would_block branch only logs, prefixed "price_guard: would block"', preg_match("/elseif \\(\\\$pg\\['action'\\] === 'would_block'\\) \\{\\s*log_message\\('error', 'price_guard: would block[^;]*;\\s*\\}/", $seg) === 1);
check('Home: block branch swaps in the hand-off text and flags a human', strpos($seg, "\$pg['action'] === 'block'") !== false
    && strpos($seg, 'ai_price_guard_handoff_text($human)') !== false && strpos($seg, 'ai_price_guard_flag_handoff(') !== false);
same('Home: exactly two places rewrite the reply inside the guard (deflect + block)', 2, substr_count($seg, "\$response['choices'][0]['text'] = "));
check('Home: judge exception re-thrown when the flag is off (default unchanged)', strpos($seg, 'if (!$pg_closed) {') !== false && strpos($seg, 'throw $pg_e;') !== false);
check('Home: unknown-verdict row not duplicated on a block', strpos($seg, "\$verdict === 'unknown' && \$pg['action'] !== 'block'") !== false);

// ---- handoff through the conversations state machine
echo "== 2b. handoff flag ==\n";
function seed_conv($uid, $platform, $acct, $thread, $state) {
    global $CI;
    $CI->db->insert('conversations', array('user_id' => $uid, 'platform' => $platform, 'channel_account_id' => $acct, 'external_thread_id' => $thread,
        'handoff_state' => $state, 'status' => 'open', 'updated_at' => '2026-09-23 10:00:00'));
    $CI->db->insert('messenger_bot_subscriber', array('user_id' => $uid, 'social_media' => $platform, 'page_table_id' => $acct, 'subscribe_id' => $thread, 'bot_paused_until' => null));
    return count($CI->db->tables['conversations']);
}
fresh_ci();
$c1 = seed_conv(7, 'fb', 3, 'PSID1', 'AI_ACTIVE');
$c_other = seed_conv(8, 'fb', 3, 'PSID1', 'AI_ACTIVE');   // another tenant, same thread id
$h = ai_price_guard_flag_handoff(7, 'fb', 3, 'PSID1', 'price_guard: unverifiable price (350), team to confirm');
same('AI_ACTIVE: flagged via the state machine', array(true, 'conversation', $c1), array($h['ok'], $h['via'], $h['conversation_id']));
same('AI_ACTIVE -> WAITING_FOR_HUMAN', 'WAITING_FOR_HUMAN', $CI->db->tables['conversations'][$c1]['handoff_state']);
$ev = array_values($CI->db->tables['conversation_events']);
same('one audited state_change by actor ai', array(1, 'ai', 'AI_ACTIVE', 'WAITING_FOR_HUMAN'), array(count($ev), $ev[0]['actor_type'] ?? null, $ev[0]['from_state'] ?? null, $ev[0]['to_state'] ?? null));
check('audit note says why, with the figure', strpos((string) ($ev[0]['note'] ?? ''), 'price_guard') !== false && strpos((string) $ev[0]['note'], '350') !== false);
check('legacy pause mirrored on the subscriber row (bot stops for the human)', !empty($CI->db->tables['messenger_bot_subscriber'][1]['bot_paused_until']));
same('other tenant\'s conversation untouched', 'AI_ACTIVE', $CI->db->tables['conversations'][$c_other]['handoff_state']);
same('other tenant\'s subscriber untouched', null, $CI->db->tables['messenger_bot_subscriber'][2]['bot_paused_until']);

fresh_ci();
$c1 = seed_conv(7, 'wa', 5, '2010000', 'RESOLVED');
$h = ai_price_guard_flag_handoff(7, 'wa', 5, '2010000');
same('RESOLVED: reopened then queued for a human', array(true, 'WAITING_FOR_HUMAN'), array($h['ok'], $CI->db->tables['conversations'][$c1]['handoff_state']));
same('RESOLVED: two audited hops', array('RESOLVED>AI_ACTIVE', 'AI_ACTIVE>WAITING_FOR_HUMAN'),
    array_map(function ($e) { return $e['from_state'] . '>' . $e['to_state']; }, array_values($CI->db->tables['conversation_events'])));

fresh_ci();
$c1 = seed_conv(7, 'fb', 3, 'PSID1', 'HUMAN_ACTIVE');
$h = ai_price_guard_flag_handoff(7, 'fb', 3, 'PSID1');
same('HUMAN_ACTIVE: left alone, reported ok, no event', array(true, 'HUMAN_ACTIVE', 0), array($h['ok'], $CI->db->tables['conversations'][$c1]['handoff_state'], count($CI->db->tables['conversation_events'])));

fresh_ci();
$CI->db->insert('facebook_rx_fb_page_info', array('user_id' => 7, 'page_id' => '1122334455'));   // table id 1
$ca = seed_conv(7, 'ig', 9, 'IGSID', 'AI_ACTIVE');
$cb = seed_conv(7, 'ig', 1, 'IGSID', 'AI_ACTIVE');
$h = ai_price_guard_flag_handoff(7, 'ig', '1122334455', 'IGSID');
same('ig: FB page id resolved to this page\'s conversation', array($cb, 'WAITING_FOR_HUMAN', 'AI_ACTIVE'),
    array($h['conversation_id'], $CI->db->tables['conversations'][$cb]['handoff_state'], $CI->db->tables['conversations'][$ca]['handoff_state']));

fresh_ci();
$CI->ai_tools = new StubTools();
$CI->db->insert('messenger_bot_subscriber', array('user_id' => 7, 'social_media' => 'tg', 'page_table_id' => 4, 'subscribe_id' => 'TG1', 'bot_paused_until' => null));
$h = ai_price_guard_flag_handoff(7, 'tg', 4, 'TG1', 'why');
same('no conversation row: falls back to the handoff_to_human tool', array(true, 'handoff_tool'), array($h['ok'], $h['via']));
same('fallback: tool called with the subscriber row and the reason', array('handoff_to_human', array('reason' => 'why'), 1, 7),
    array($CI->ai_tools->calls[0][0], $CI->ai_tools->calls[0][1], $CI->ai_tools->calls[0][2]['subscriber_row_id'], $CI->ai_tools->calls[0][2]['user_id']));
same('no thread id: nothing done', array(false, 'no thread'), array(ai_price_guard_flag_handoff(7, 'fb', 3, '')['ok'], ai_price_guard_flag_handoff(7, 'fb', 3, '')['error']));

// =================================================================== 3. accounting
echo "== 3. every model call is accounted ==\n";

fresh_ci();
$CI->ai_tools = new StubTools();
$tool_turn = array('choices' => array(array('message' => array('role' => 'assistant', 'content' => null,
    'tool_calls' => array(array('id' => 'c1', 'function' => array('name' => 'calculate_price', 'arguments' => '{}')))))), 'usage' => array('prompt_tokens' => 100, 'completion_tokens' => 10));
$CI->openai_api = new StubModel(array($tool_turn, $OPENAI_OK));
$cfg = array('user_id' => 7, 'ai_provider' => 'openai', 'open_ai_secret_key' => 'k', 'models' => 'gpt-4o-mini', 'ai_tools_enabled' => '1');
$raw = $CI->ai_provider = null; $CI->load->library('Ai_provider');
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'x')), array('tools_context' => array('user_id' => 7)));
$u = array_values($CI->db->tables['ai_usage_log']);
same('openai tool loop: both rounds logged', array(2, 180, 15), array(count($u), array_sum(array_column($u, 'input_tokens')), array_sum(array_column($u, 'output_tokens'))));
same('openai tool loop: purpose chat_reply by default', array('chat_reply', 'chat_reply'), array_column($u, 'purpose'));

fresh_ci();
$CI->ai_tools = new StubTools();
$a_tool = array('choices' => array(array('text' => '')), 'content' => array(array('type' => 'tool_use', 'id' => 't1', 'name' => 'calculate_price', 'input' => array())),
    'stop_reason' => 'tool_use', 'usage' => array('input_tokens' => 200, 'output_tokens' => 20));
$CI->anthropic_api = new StubModel(array($a_tool, $a_tool, $ANTHROPIC_OK));   // 2 tool rounds, then the forced final
$cfg = array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'k', 'ai_tools_enabled' => '1');
$CI->load->library('Ai_provider');
$CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'x')), array('tools_context' => array('user_id' => 7), 'purpose' => 'chat_reply'));
$u = array_values($CI->db->tables['ai_usage_log']);
same('anthropic tool loop: every round incl. the forced final is logged', array(3, 3), array(count($CI->anthropic_api->calls), count($u)));
same('anthropic tool loop: provider anthropic', array('anthropic'), array_values(array_unique(array_column($u, 'provider'))));

fresh_ci();
$CI->openai_api = new StubModel(array($OPENAI_OK));
$CI->load->library('Ai_provider');
$CI->ai_provider->completion(array('user_id' => 7, 'open_ai_secret_key' => 'k'), array(array('role' => 'user', 'content' => 'x')), array('purpose' => 'health_check'));
same('plain completion: purpose passed through', 'health_check', array_values($CI->db->tables['ai_usage_log'])[0]['purpose'] ?? null);

// static sweep: who talks to a model, and does each of them account for it?
$direct = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr($f, -4) !== '.php' || strpos($f, '/third_party/') !== false || strpos($f, '/views/') !== false) continue;
    $s = file_get_contents($f);
    if (preg_match('#https://api\.(openai|anthropic)\.com#', $s)) $direct[substr($f, strlen(APPPATH))] = $s;
}
ksort($direct);
same('model HTTP endpoints only in the known files', array('helpers/ai_knowledge_helper.php', 'helpers/ai_voice_helper.php', 'libraries/Anthropic_api.php', 'libraries/Openai_api.php'), array_keys($direct));
foreach (array('helpers/ai_knowledge_helper.php', 'helpers/ai_voice_helper.php') as $k) {
    $s = $direct[$k];
    same("$k: one usage record per model endpoint", preg_match_all('#https://api\.openai\.com#', $s), preg_match_all('#ai_usage_record(_openai_response)?\(\$user_id#', $s));
}
$callers = array();
foreach ($it as $f) {
    if (substr($f, -4) !== '.php' || strpos($f, '/third_party/') !== false) continue;
    if (preg_match('#->(openai_api|anthropic_api)->#', file_get_contents($f))) $callers[] = substr($f, strlen(APPPATH));
}
same('Openai_api / Anthropic_api are only driven by Ai_provider', array('libraries/Ai_provider.php'), $callers);
$ap = file_get_contents(APPPATH . 'libraries/Ai_provider.php');
same('Ai_provider: every model call is followed by log_usage', preg_match_all('#->(open_ai_completion|anthropic_completion)\(#', $ap), preg_match_all('#->(open_ai_completion|anthropic_completion)\([^\n]*\n\s*\$this->log_usage\(#', $ap));
check('backfill script loads the usage helper (was a fatal get_instance() outside CI)', strpos(file_get_contents(dirname(APPPATH) . '/docker/scripts/backfill_kb_embeddings.php'), "helpers/ai_usage_helper.php") !== false);

// =================================================================== 4. KB ownership
echo "== 4. KB / FAQ / missed-question ownership ==\n";
$files = array(
    'modules/ai_knowledge_base/controllers/Ai_knowledge_base.php' => '$this->user_id',
    'controllers/Missed_questions.php' => '$this->uid',
    'controllers/Ai_improvement.php' => '$this->uid',
    'controllers/Onboarding.php' => '$this->uid',
);
$tables = 'ai_knowledge_sources|ai_knowledge_chunks|ai_faq|ai_unanswered_questions';
foreach ($files as $rel => $tenant) {
    $src = file_get_contents(APPPATH . $rel);
    preg_match_all('/public function (\w+)\s*\([^)]*\)\s*\{/', $src, $fm, PREG_OFFSET_CAPTURE);
    $n = 0;
    foreach ($fm[1] as $k => $m) {
        $start = $m[1];
        $end = isset($fm[1][$k + 1]) ? $fm[1][$k + 1][1] : strlen($src);
        $body = substr($src, $start, $end - $start);
        if (in_array($m[0], array('activate', 'deactivate', 'delete'), true) && strpos($rel, 'Ai_knowledge_base') !== false) continue; // admin-only addon lifecycle
        foreach (preg_split('/;\s*\n/', $body) as $stmt) {
            if (!preg_match('/' . $tables . '/', $stmt)) continue;
            $by_id = preg_match("/'id'\s*=>|where\('id'|where_in\('id'|'source_id'\s*=>/", $stmt);
            if (!$by_id) continue;
            $n++;
            $scoped = strpos($stmt, "'user_id'") !== false && strpos($stmt, $tenant) !== false;
            // chunks carry no user_id: a by-source_id statement is fine only after the method
            // proved the source belongs to the tenant.
            if (!$scoped && preg_match("/'source_id'\s*=>/", $stmt)) {
                $before = substr($body, 0, strpos($body, $stmt));
                $scoped = (bool) preg_match("/ai_knowledge_sources'[^;]*'user_id'\s*=>\s*" . preg_quote($tenant, '/') . '/', $before);
            }
            check("$rel::{$m[0]} by-id statement is tenant-scoped", $scoped, trim(mb_substr($stmt, 0, 200)));
        }
    }
    check("$rel: audited at least one by-id statement", $n > 0 || $rel === 'controllers/Onboarding.php');
}
$kb = file_get_contents(APPPATH . 'modules/ai_knowledge_base/controllers/Ai_knowledge_base.php');
$tog = substr($kb, strpos($kb, 'function toggle_status_action'), 1400);
check('toggle_status_action refuses a foreign id before updating', strpos($tog, "Source not found.") !== false && strpos($tog, 'Source not found.') < strpos($tog, 'update_data('));
foreach (array('delete_source_action', 'preview_chunks_action') as $fn) {
    $b = substr($kb, strpos($kb, "function $fn"), 1400);
    check("$fn: ownership lookup before touching chunks", preg_match("/ai_knowledge_sources', array\('where' => array\('id' => \\\$id, 'user_id' => \\\$this->user_id\)\)/", $b) === 1);
}

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
