<?php
/**
 * AI cost log agent_id + conversation_id (remaining-work plan, task A3). DB-free unit tests:
 * no CodeIgniter bootstrap, no database, no network. get_instance() returns a small fake whose
 * ->db is an in-memory stand-in, exactly like tests/ai_judge/run.php's FakeDb/FakeCI.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ai_usage_ids/run.php
 *
 * Covers:
 *  1. ai_usage_helper::ai_usage_record() — old signature (no ids) keeps working; ids recorded
 *     when passed and the columns exist; ids stay NULL when not resolvable; the pre-migration
 *     guard (columns missing) writes the exact old row shape, no agent_id/conversation_id keys
 *     at all.
 *  2. Ai_provider::completion() — 'agent_id'/'conversation_id' overrides reach the logged row,
 *     for a plain call and for every round of the tool loop; omitted overrides log NULL ids,
 *     byte-identical to before this task.
 *  3. Conversation_summarizer::summarize() — an optional $conversation_id argument is threaded
 *     into the 'conversation_id' override handed to the AI caller; omitting it keeps working.
 *  4. Static wiring checks: Home.php's get_ai_reply_open_ai() passes 'agent_id' from the
 *     resolved $ai_profile and 'conversation_id' from conversation_ai_history_link() into
 *     $completion_overrides; Inbox.php's summarize() passes $conv['id'] into
 *     Conversation_summarizer::summarize().
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

// ------------------------------------------------------------ fake CI (mirrors tests/ai_judge/run.php)

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
    private $auto = array();
    private $t; private $w = array(); private $lim = null;

    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($f, $t) { return in_array($f, $this->fields[$t] ?? array(), true); }
    public function select($c) { return $this; }
    public function from($t) { $this->t = $t; return $this; }
    public function where($k, $v = null) { $this->w[] = array($k, $v); return $this; }
    public function limit($n) { $this->lim = $n; return $this; }
    private function reset() { $this->w = array(); $this->lim = null; }
    public function get() {
        $rows = array_values($this->tables[$this->t] ?? array());
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        $this->reset();
        return new FakeResult($rows);
    }
    public function insert($t, $data) {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data; $this->reset();
        return true;
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
class FakeEncryption { public function decrypt($c) { return $c; } }
class FakeCI { public $db; public $load; public $config; public $encryption; }

/** Records every call; answers from a script (one entry per call, last one repeats). */
class StubModel
{
    public $calls = array(); public $script = array();
    public function __construct($script) { $this->script = $script; }
    private function next() { $r = count($this->script) > 1 ? array_shift($this->script) : $this->script[0]; return is_string($r) ? $r : json_encode($r); }
    public function open_ai_completion($key, $messages, $model, $max_tokens, $instruction = '', $description = '', $human = '', $temperature = 0.4, $tools = null) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'tools'); return $this->next();
    }
}

$CI = null;
function &get_instance() { global $CI; return $CI; }
function fresh_ci($ids_migrated = true)
{
    global $CI, $LOG;
    $LOG = array();
    $CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig(); $CI->encryption = new FakeEncryption();
    $CI->db = new FakeDb();
    $CI->db->tables = array('open_ai_config' => array(), 'ai_usage_log' => array());
    $fields = array('id', 'user_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'purpose', 'created_at', 'units', 'estimated_cost_usd');
    if ($ids_migrated) $fields = array_merge($fields, array('agent_id', 'conversation_id'));
    $CI->db->fields['ai_usage_log'] = $fields;
    $CI->openai_api = new StubModel(array(array('error' => array('message' => 'no script'))));
    return $CI;
}
fresh_ci();

require APPPATH . 'helpers/secret_helper.php';
require APPPATH . 'helpers/ai_usage_helper.php';

function oai($text, $in = 100, $out = 20) {
    return array('choices' => array(array('message' => array('role' => 'assistant', 'content' => $text), 'text' => $text)),
        'usage' => array('prompt_tokens' => $in, 'completion_tokens' => $out), 'model' => 'gpt-4o-mini');
}

// ============================================================================================
echo "== 1. ai_usage_record(): old signature, ids, NULL, pre-migration guard ==\n";

// ai_usage_record() caches its field_exists() check in a `static` local (matches
// conversation_ai_history_link()'s $has_column pattern — one check per PHP-FPM request in
// production, where the schema never changes mid-request). That cache is process-wide, so the
// pre-migration scenario is run in its own child PHP process instead of racing the cache
// against the rest of this suite.
$helper_path = APPPATH . 'helpers/ai_usage_helper.php';
$pre_migration_script = <<<PHP
<?php
define('BASEPATH', 1);
require '{$helper_path}';
class PreMigFakeDb {
    public \$rows = array();
    public function table_exists(\$t) { return \$t === 'ai_usage_log'; }
    public function field_exists(\$f, \$t) { return false; } // columns not added yet
    public function insert(\$t, \$data) { \$this->rows[] = \$data; return true; }
}
class PreMigFakeCI { public \$db; }
\$GLOBALS['__ci'] = new PreMigFakeCI(); \$GLOBALS['__ci']->db = new PreMigFakeDb();
function &get_instance() { return \$GLOBALS['__ci']; }
ai_usage_record(9, 'openai', 'gpt-4o-mini', 'chat_reply', 100, 20, 0, 42, 777);
echo json_encode(\$GLOBALS['__ci']->db->rows[0]);
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'premig_') . '.php';
file_put_contents($tmp, $pre_migration_script);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
unlink($tmp);
$row = json_decode((string) $out, true);
check('pre-migration guard: row still inserted', is_array($row), 'subprocess output: ' . $out);
if (is_array($row)) {
    check('pre-migration guard: no agent_id key at all (old row shape)', !array_key_exists('agent_id', $row));
    check('pre-migration guard: no conversation_id key at all (old row shape)', !array_key_exists('conversation_id', $row));
    same('pre-migration guard: the rest of the row is unchanged', array(9, 'openai', 'gpt-4o-mini', 'chat_reply', 100, 20),
        array($row['user_id'], $row['provider'], $row['model'], $row['purpose'], $row['input_tokens'], $row['output_tokens']));
}

fresh_ci();
ai_usage_record(9, 'openai', 'gpt-4o-mini', 'chat_reply', 100, 20);
$rows = array_values($CI->db->tables['ai_usage_log']);
same('old signature (no ids passed) still inserts one row', 1, count($rows));
check('old signature: agent_id key present, NULL (columns exist, no id given)', array_key_exists('agent_id', $rows[0]) && $rows[0]['agent_id'] === null);
check('old signature: conversation_id key present, NULL', array_key_exists('conversation_id', $rows[0]) && $rows[0]['conversation_id'] === null);

fresh_ci();
ai_usage_record(9, 'openai', 'gpt-4o-mini', 'chat_reply', 100, 20, 0, 42, 777);
$rows = array_values($CI->db->tables['ai_usage_log']);
same('ids passed through when the columns exist: agent_id', 42, $rows[0]['agent_id']);
same('ids passed through when the columns exist: conversation_id', 777, $rows[0]['conversation_id']);

fresh_ci();
ai_usage_record(9, 'openai', 'gpt-4o-mini', 'chat_reply', 100, 20, 0, '', null);
$rows = array_values($CI->db->tables['ai_usage_log']);
same('empty-string agent_id normalizes to NULL, not 0', null, $rows[0]['agent_id']);

// ============================================================================================
echo "\n== 2. Ai_provider::completion(): agent_id/conversation_id overrides reach the logged row ==\n";

function oai_tenant($uid, $extra = array()) {
    global $CI;
    $CI->db->insert('open_ai_config', array_merge(array('user_id' => $uid, 'ai_provider' => 'openai', 'open_ai_secret_key' => 'sk-1',
        'models' => 'gpt-4o-mini', 'maximum_token' => '800', 'temperature' => '0.7'), $extra));
}

fresh_ci();
oai_tenant(9);
$CI->openai_api = new StubModel(array(oai('hello')));
$CI->load->library('Ai_provider');
$CI->ai_provider->completion($CI->db->tables['open_ai_config'][1], array(array('role' => 'user', 'content' => 'hi')),
    array('agent_id' => 5, 'conversation_id' => 123, 'purpose' => 'chat_reply'));
$rows = array_values($CI->db->tables['ai_usage_log']);
same('plain completion: agent_id logged', 5, $rows[0]['agent_id']);
same('plain completion: conversation_id logged', 123, $rows[0]['conversation_id']);

fresh_ci();
oai_tenant(9);
$CI->openai_api = new StubModel(array(oai('hello')));
$CI->load->library('Ai_provider');
$CI->ai_provider->completion($CI->db->tables['open_ai_config'][1], array(array('role' => 'user', 'content' => 'hi')), array('purpose' => 'chat_reply'));
$rows = array_values($CI->db->tables['ai_usage_log']);
same('no overrides given: agent_id NULL (unchanged from before this task)', null, $rows[0]['agent_id']);
same('no overrides given: conversation_id NULL', null, $rows[0]['conversation_id']);

// tool loop: every round is billed, so every round must carry the same ids. A tiny fake
// Ai_tools stands in (assigned before completion() loads the library, so FakeLoader's
// "already set" check skips the real one) — this test is about billing wiring, not tools.
class FakeAiTools
{
    public $saved_lead = false;
    public function openai_tools($context) { return array(array('type' => 'function', 'function' => array('name' => 'calculate_price', 'parameters' => array()))); }
    public function execute($name, $args, $context) { return 'tool result'; }
}

fresh_ci();
oai_tenant(9, array('ai_tools_enabled' => '1'));
$CI->ai_tools = new FakeAiTools();
$tool_call_msg = oai('');
$tool_call_msg['choices'][0]['message']['tool_calls'] = array(array('id' => 'c1', 'function' => array('name' => 'calculate_price', 'arguments' => '{}')));
$CI->openai_api = new StubModel(array($tool_call_msg, oai('final answer')));
$CI->load->library('Ai_provider');
$CI->ai_provider->completion($CI->db->tables['open_ai_config'][1], array(array('role' => 'user', 'content' => 'price?')),
    array('agent_id' => 8, 'conversation_id' => 55, 'purpose' => 'chat_reply', 'tools_context' => array('user_id' => 9, 'page_id' => 'p', 'subscribe_id' => 's', 'social_media' => 'fb')));
$rows = array_values($CI->db->tables['ai_usage_log']);
same('tool loop: two billed rounds', 2, count($rows));
same('tool loop: both rounds carry the agent_id', array(8, 8), array($rows[0]['agent_id'], $rows[1]['agent_id']));
same('tool loop: both rounds carry the conversation_id', array(55, 55), array($rows[0]['conversation_id'], $rows[1]['conversation_id']));

// ============================================================================================
echo "\n== 3. Conversation_summarizer::summarize(): optional \$conversation_id threads into the AI-caller override ==\n";

require_once APPPATH . 'helpers/conversation_summary_helper.php';
require_once APPPATH . 'libraries/Conversation_summarizer.php';

$seen = array();
$gen = new Conversation_summarizer(array('ai_caller' => function ($cfg, $msgs, $overrides) use (&$seen) {
    $seen[] = $overrides['conversation_id'] ?? '__absent__';
    return json_encode(array('choices' => array(array('text' => 'summary text'))));
}));
$msg = array('id' => 1, 'sender' => 'user', 'agent_name' => null, 'message_content' => 'hi', 'conversation_time' => '2026-09-22 10:00:00');
$gen->summarize(array('user_id' => 1), array($msg), 456);
same('conversation_id argument reaches the ai_caller override', 456, $seen[0]);

$gen2 = new Conversation_summarizer(array('ai_caller' => function ($cfg, $msgs, $overrides) use (&$seen) {
    $seen[] = array_key_exists('conversation_id', $overrides) ? $overrides['conversation_id'] : '__absent__';
    return json_encode(array('choices' => array(array('text' => 'summary text'))));
}));
$gen2->summarize(array('user_id' => 1), array($msg)); // old call style, no 3rd argument
same('omitting conversation_id: override present but NULL, never breaks the old call signature', null, $seen[1]);

// ============================================================================================
echo "\n== 4. static wiring: Home.php and Inbox.php pass the ids through ==\n";

$home = file_get_contents(APPPATH . 'controllers/Home.php');
check("Home.php: completion_overrides carries 'agent_id' from \$ai_profile",
    (bool) preg_match('/\'agent_id\'\s*=>\s*!empty\(\$ai_profile\[\'id\'\]\)\s*\?\s*\$ai_profile\[\'id\'\]\s*:\s*null/', $home));
check("Home.php: completion_overrides carries 'conversation_id' from conversation_ai_history_link()",
    strpos($home, "conversation_ai_history_link(\$user_id, \$social_media, \$page_id, \$subscribe_id)") !== false
    && strpos($home, "\$completion_overrides['conversation_id'] = \$conv_link['conversation_id'];") !== false);

$inbox = file_get_contents(APPPATH . 'controllers/Inbox.php');
check("Inbox.php: summarize() passes \$conv['id'] into Conversation_summarizer::summarize()",
    strpos($inbox, "\$this->conversation_summarizer->summarize(\$ai_cfg, \$messages, \$conv['id']);") !== false);

// ============================================================================================
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
