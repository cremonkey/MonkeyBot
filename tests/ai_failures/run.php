<?php
/**
 * Remaining-work Task 4 — AI provider and tool failure recording. DB-free unit tests: no
 * CodeIgniter bootstrap, no database, no network. get_instance() returns a tiny fake whose
 * ->db is an in-memory stand-in, mirroring tests/ai_safety/run.php's style.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ai_failures/run.php
 *
 * Covers:
 *  1. Provider error (missing key, upstream {"error":...}, every tool-loop round) -> exactly
 *     one ai_failure_log row (kind=provider) + one ai.failed event; the raw reply returned to
 *     the caller is untouched either way.
 *  2. Tool exception -> exactly one ai_failure_log row (kind=tool), no event, and the model
 *     still gets its safe fallback string (customer-facing behaviour unchanged).
 *  3. Reply path unchanged: a successful provider call records nothing.
 *  4. Pre-migration: ai_failure_log missing -> both paths are no-ops, never throw.
 *  5. Secrets are scrubbed out of error_message before it is stored.
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
    /** Set to a table name to make get() throw for that table (simulates a real DB error). */
    public $throwOnTable = null;
    private $auto = array();
    private $lastId = 0;
    private $t;

    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($f, $t) { return true; }
    public function select($c) { return $this; }
    public function from($t) { $this->t = $t; return $this; }
    public function where($k, $v = null) { return $this; }
    public function or_where($k, $v = null) { return $this; }
    public function like($k, $v = null) { return $this; }
    public function or_like($k, $v = null) { return $this; }
    public function group_start() { return $this; }
    public function group_end() { return $this; }
    public function order_by($k, $d = 'ASC') { return $this; }
    public function limit($n) { return $this; }
    public function count_all_results() { return count($this->tables[$this->t] ?? array()); }
    public function get() {
        if ($this->throwOnTable !== null && $this->t === $this->throwOnTable) throw new Exception('DB unavailable: ' . $this->t);
        return new FakeResult(array_values($this->tables[$this->t] ?? array()));
    }
    public function insert($t, $data) {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data;
        $this->lastId = $id;
        return true;
    }
    public function insert_id() { return $this->lastId; }
}

class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? null; } }
class FakeLoader
{
    public $loaded = array();
    public function helper($h) { foreach ((array) $h as $n) { $this->loaded[] = $n; require_once APPPATH . 'helpers/' . $n . '_helper.php'; } }
    public function library($l) {
        global $CI;
        $prop = strtolower($l);
        if (isset($CI->$prop)) return;
        if ($l === 'Ai_provider') { require_once APPPATH . 'libraries/Ai_provider.php'; $CI->ai_provider = new Ai_provider(); return; }
        if ($l === 'Ai_tools') { require_once APPPATH . 'libraries/Ai_tools.php'; $CI->ai_tools = new Ai_tools(); return; }
        throw new Exception("FakeLoader: library $l not stubbed");
    }
}
class FakeCI { public $db; public $load; public $config; }

/** Records every call; answers from a script (one entry per call, last one repeats). */
class StubModel
{
    public $calls = array(); public $script = array();
    public function __construct($script) { $this->script = $script; }
    private function next() { $r = count($this->script) > 1 ? array_shift($this->script) : $this->script[0]; return is_string($r) ? $r : json_encode($r); }
    public function anthropic_completion($key, $messages, $model, $max_tokens, $system = '', $temperature = 0.7, $tools = null, $timeout = 60) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'system', 'temperature', 'tools'); return $this->next();
    }
    public function open_ai_completion($key, $messages, $model, $max_tokens, $instruction = '', $description = '', $human = '', $temperature = 0.4, $tools = null) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'temperature', 'tools'); return $this->next();
    }
}

$CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig();
function &get_instance() { global $CI; return $CI; }

function fresh_ci($with_failure_table = true, $with_events_table = true)
{
    global $CI;
    $CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig();
    $CI->db = new FakeDb();
    $CI->db->tables = array('open_ai_config' => array(), 'ai_usage_log' => array());
    if ($with_failure_table) $CI->db->tables['ai_failure_log'] = array();
    if ($with_events_table) $CI->db->tables['events'] = array();
    $CI->ai_provider = new Ai_provider();
    $CI->ai_tools = new Ai_tools();
    return $CI;
}

require APPPATH . 'helpers/secret_helper.php';
require APPPATH . 'helpers/ai_usage_helper.php';
require APPPATH . 'helpers/ai_failure_helper.php';
require APPPATH . 'helpers/event_helper.php';
require APPPATH . 'libraries/Ai_provider.php';
require APPPATH . 'libraries/Ai_tools.php';

$ANTHROPIC_OK = array('choices' => array(array('text' => 'Hi!')), 'content' => array(), 'stop_reason' => 'end_turn',
    'usage' => array('input_tokens' => 10, 'output_tokens' => 2), 'model' => 'claude-haiku-4-5');
$ANTHROPIC_ERR = array('error' => array('message' => 'overloaded, key sk-ant-abcdefghijklmnopqrstuvwxyz012345'));
$OPENAI_OK = array('choices' => array(array('message' => array('role' => 'assistant', 'content' => 'Hi!'), 'text' => 'Hi!')),
    'usage' => array('prompt_tokens' => 8, 'completion_tokens' => 1));
$OPENAI_ERR = array('error' => array('message' => 'Bearer sk-openai-super-secret-1234567890 rejected: invalid_api_key'));

function failure_rows($kind = null)
{
    global $CI;
    $rows = array_values($CI->db->tables['ai_failure_log'] ?? array());
    if ($kind === null) return $rows;
    return array_values(array_filter($rows, function ($r) use ($kind) { return $r['kind'] === $kind; }));
}
function event_rows($type = null)
{
    global $CI;
    $rows = array_values($CI->db->tables['events'] ?? array());
    if ($type === null) return $rows;
    return array_values(array_filter($rows, function ($r) use ($type) { return $r['event_type'] === $type; }));
}

// =================================================================== 4. pre-migration no-op
echo "== 4. pre-migration (table missing) -> no-op, never throws ==\n";

fresh_ci(false, false);
$CI->db->insert('open_ai_config', array('user_id' => 5, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'sk-ant-5', 'anthropic_model' => 'claude-haiku-4-5'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->anthropic_api = new StubModel(array($ANTHROPIC_ERR));
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')));
same('no ai_failure_log table: reply is still the raw error JSON', json_encode($ANTHROPIC_ERR), $raw);
check('no ai_failure_log table: nothing thrown, no row anywhere', !isset($CI->db->tables['ai_failure_log']));

fresh_ci(false, false);
$CI->db->throwOnTable = 'ecommerce_cart';
$tools = new Ai_tools();
$result = $tools->execute('get_order_status', array('email_or_phone' => 'x@example.com'), array('user_id' => 42));
same('no ai_failure_log table: tool still returns its safe fallback', 'The action could not be completed right now.', $result);


// =================================================================== 1. provider error
echo "== 1. provider error -> one row + event, reply unchanged ==\n";

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 5, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'sk-ant-5', 'anthropic_model' => 'claude-haiku-4-5'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->anthropic_api = new StubModel(array($ANTHROPIC_ERR));
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')), array('purpose' => 'chat_reply'));
same('reply is exactly the driver\'s raw error JSON (unchanged)', json_encode($ANTHROPIC_ERR), $raw);
same('one failure row', 1, count(failure_rows('provider')));
$f = failure_rows('provider')[0];
same('failure row: provider/model/purpose', array('anthropic', 'claude-haiku-4-5', 'chat_reply'), array($f['provider'], $f['model'], $f['purpose']));
same('failure row: user_id', 5, $f['user_id']);
same('one ai.failed event', 1, count(event_rows('ai.failed')));
same('event: same user_id', 5, event_rows('ai.failed')[0]['user_id']);
same('no usage row (nothing billed on error)', 0, count($CI->db->tables['ai_usage_log']));

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 6, 'ai_provider' => 'openai', 'open_ai_secret_key' => '', 'models' => 'gpt-4o-mini'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->openai_api = new StubModel(array($OPENAI_OK));
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')));
check('missing key: reply says so (unchanged shape)', strpos($raw, 'OpenAI API key is not configured.') !== false);
same('missing key: one failure row, config_error', 1, count(failure_rows('provider')));
same('missing key: error_class', 'config_error', failure_rows('provider')[0]['error_class']);
same('missing key: provider never called', 0, count($CI->openai_api->calls));
same('missing key: one ai.failed event', 1, count(event_rows('ai.failed')));

// tool-loop rounds: an error on round 1 must still be recorded and returned untouched
fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 9, 'ai_provider' => 'openai', 'open_ai_secret_key' => 'sk-openai-9', 'models' => 'gpt-4o-mini', 'ai_tools_enabled' => '1'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->openai_api = new StubModel(array($OPENAI_ERR));
$CI->ai_tools = new class {
    public function openai_tools($ctx) { return array(); }
    public function execute($n, $a, $c) { return 'unused'; }
};
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')), array('tools_context' => array('user_id' => 9), 'purpose' => 'chat_reply'));
same('tool-loop provider error: reply is the raw error JSON', json_encode($OPENAI_ERR), $raw);
same('tool-loop provider error: one failure row', 1, count(failure_rows('provider')));

// =================================================================== 2. tool exception
echo "== 2. tool exception -> one row, kind=tool, no event, safe fallback returned ==\n";

fresh_ci();
$tools = new Ai_tools();
$result = $tools->execute('search_products', array('query' => 'x'), array('user_id' => 0)); // user_id<=0 short-circuits before try
same('no account context: safe string, no crash', 'Error: no account context.', $result);
same('no account context: nothing recorded (no user to attribute it to)', 0, count(failure_rows('tool')));

fresh_ci();
$CI->db->throwOnTable = 'ecommerce_cart'; // simulates a real DB error inside t_order_status
$tools = new Ai_tools();
$result = $tools->execute('get_order_status', array('email_or_phone' => 'x@example.com'), array('user_id' => 42));
same('DB failure inside a tool: safe fallback string (customer-facing text unchanged)', 'The action could not be completed right now.', $result);
same('DB failure inside a tool: one failure row, kind=tool', 1, count(failure_rows('tool')));
$t = failure_rows('tool')[0];
same('tool failure row: tool_name + user_id', array('get_order_status', 42), array($t['tool_name'], $t['user_id']));
check('tool failure row: args_hash is a short fingerprint, not the raw args', strlen($t['args_hash']) === 16 && strpos($t['args_hash'], 'x@example.com') === false);
same('tool exception: no ai.failed event (only provider failures emit one)', 0, count(event_rows('ai.failed')));

fresh_ci();
$tools = new Ai_tools();
$result = $tools->execute('not_a_real_tool', array(), array('user_id' => 3));
same('unknown tool name: safe string, no exception, no failure row (not an error path)', 'Error: unknown tool.', $result);
same('unknown tool: nothing recorded', 0, count(failure_rows('tool')));

// =================================================================== 3. success path unchanged
echo "== 3. success path unchanged ==\n";

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'sk-ant-7', 'anthropic_model' => 'claude-haiku-4-5'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->anthropic_api = new StubModel(array($ANTHROPIC_OK));
$raw = $CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')));
same('success: reply is the raw OK JSON', json_encode($ANTHROPIC_OK), $raw);
same('success: no failure row', 0, count(failure_rows('provider')));
same('success: no ai.failed event', 0, count(event_rows('ai.failed')));
same('success: usage still recorded (unaffected by this task)', 1, count($CI->db->tables['ai_usage_log']));

// =================================================================== 5. secrets scrubbed
echo "== 5. secrets never stored ==\n";

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 5, 'ai_provider' => 'openai', 'open_ai_secret_key' => 'sk-openai-5', 'models' => 'gpt-4o-mini'));
$cfg = array_values($CI->db->tables['open_ai_config'])[0];
$CI->openai_api = new StubModel(array($OPENAI_ERR));
$CI->ai_provider->completion($cfg, array(array('role' => 'user', 'content' => 'hi')));
$msg = failure_rows('provider')[0]['error_message'];
check('no bearer token in stored message', stripos($msg, 'sk-openai-super-secret-1234567890') === false, $msg);
check('redaction marker present', strpos($msg, '[REDACTED]') !== false, $msg);

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
