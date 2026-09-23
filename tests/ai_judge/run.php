<?php
/**
 * Price-grounding judge follows the tenant's provider + the embedding backfill CLI command.
 * DB-free unit tests: no CodeIgniter bootstrap, no database, no network. get_instance()
 * returns a small fake whose ->db is an in-memory stand-in, and the model drivers
 * (Anthropic_api / Openai_api) are stubs that record every call. The real Ai_provider,
 * ai_knowledge_helper, ai_usage_helper and secret_helper run unchanged.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ai_judge/run.php
 *
 * Covers:
 *  1. Judge routing: an Anthropic tenant is judged by Anthropic through Ai_provider (decrypted
 *     key, claude-haiku-4-5, temperature 0, 60 tokens, 8s timeout, no tools, usage purpose
 *     'price_judge'); the same prompt as the OpenAI judge; fails open to 'unknown'.
 *  2. The OpenAI judge request is byte-identical to release/9.x-integration (pinned hashes),
 *     and an OpenAI tenant never reaches Ai_provider.
 *  3. The verdict contract (ai_price_judge_verdict), identical for both providers.
 *  4. Embedding backfill: dry run, batching, enc:: keys, skipped tenants, 429/5xx back-off,
 *     401 abandon, 400 no-retry, idempotent re-run, --user filter; CLI-only wiring.
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
function is_cli() { return true; }

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
    public $updates = 0;
    private $auto = array();
    private $t; private $w = array(); private $lim = null;

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
    public function update($t, $data) {
        foreach ($this->tables[$t] as $id => $r) {
            $hit = true;
            foreach ($this->w as $c) if ((string) ($r[$c[0]] ?? '') !== (string) $c[1]) $hit = false;
            if ($hit) { $this->tables[$t][$id] = array_merge($r, $data); $this->updates++; }
        }
        $this->reset();
        return true;
    }

    private function pending_rows($model) {
        $out = array();
        foreach ($this->tables['ai_knowledge_chunks'] as $c) {
            $src = $this->tables['ai_knowledge_sources'][$c['source_id']] ?? null;
            if ($src === null) continue;
            if ($c['embedding'] === null || $c['embedding_model'] === null || $c['embedding_model'] !== $model) {
                $c['user_id'] = $src['user_id'];
                $out[] = $c;
            }
        }
        usort($out, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return $out;
    }

    /** Only the two statements ai_embedding_backfill() issues. */
    public function query($sql, $b = array()) {
        $s = preg_replace('/\s+/', ' ', trim($sql));
        $this->sql[] = $s;
        $pend = '(c.embedding IS NULL OR c.embedding_model IS NULL OR c.embedding_model <> ?)';
        if (strpos($s, 'SELECT s.user_id, COUNT(*) AS n FROM ai_knowledge_chunks c INNER JOIN ai_knowledge_sources s ON s.id = c.source_id WHERE ' . $pend) === 0) {
            $only = strpos($s, 'AND s.user_id = ?') !== false ? (int) $b[1] : 0;
            $n = array();
            foreach ($this->pending_rows($b[0]) as $r) {
                if ($only && (int) $r['user_id'] !== $only) continue;
                $n[$r['user_id']] = ($n[$r['user_id']] ?? 0) + 1;
            }
            ksort($n);
            $rows = array();
            foreach ($n as $u => $c) $rows[] = array('user_id' => (string) $u, 'n' => (string) $c);
            return new FakeResult($rows);
        }
        if (preg_match('/^SELECT c\.id, c\.chunk_text FROM ai_knowledge_chunks c INNER JOIN ai_knowledge_sources s ON s\.id = c\.source_id WHERE s\.user_id = \? AND c\.id > \? AND ' . preg_quote($pend, '/') . ' ORDER BY c\.id LIMIT (\d+)$/', $s, $m)) {
            $rows = array();
            foreach ($this->pending_rows($b[2]) as $r) {
                if ((int) $r['user_id'] !== (int) $b[0] || (int) $r['id'] <= (int) $b[1]) continue;
                $rows[] = array('id' => (string) $r['id'], 'chunk_text' => $r['chunk_text']);
                if (count($rows) >= (int) $m[1]) break;
            }
            return new FakeResult($rows);
        }
        throw new Exception('FakeDb: unexpected SQL: ' . $s);
    }
}

class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? null; } }
class FakeLoader
{
    public $libraries = array();
    public function helper($h) { foreach ((array) $h as $n) require_once APPPATH . 'helpers/' . $n . '_helper.php'; }
    public function library($l) {
        global $CI;
        $this->libraries[] = $l;
        $prop = strtolower($l);
        if (isset($CI->$prop)) return;
        if ($l === 'Ai_provider') { require_once APPPATH . 'libraries/Ai_provider.php'; $CI->ai_provider = new Ai_provider(); return; }
        throw new Exception("FakeLoader: library $l not stubbed");
    }
}
/** 'enc::' + strrev(plain); anything that does not start with 'ok:' fails to decrypt. */
class FakeEncryption
{
    public function decrypt($c) { $p = strrev($c); return strpos($p, 'ok:') === 0 ? substr($p, 3) : false; }
}
function fake_enc($plain) { return 'enc::' . strrev('ok:' . $plain); }
class FakeCI { public $db; public $load; public $config; public $encryption; }

/** Records every call; answers from a script (one entry per call, last one repeats). */
class StubModel
{
    public $calls = array(); public $script = array();
    public function __construct($script) { $this->script = $script; }
    private function next() { $r = count($this->script) > 1 ? array_shift($this->script) : $this->script[0]; return is_string($r) ? $r : json_encode($r); }
    public function anthropic_completion($key, $messages, $model, $max_tokens, $system = '', $temperature = 0.7, $tools = null, $timeout = 60) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'system', 'temperature', 'tools', 'timeout'); return $this->next();
    }
    public function open_ai_completion($key, $messages, $model, $max_tokens, $instruction = '', $description = '', $human = '', $temperature = 0.4, $tools = null) {
        $this->calls[] = compact('key', 'messages', 'model', 'max_tokens', 'temperature', 'tools'); return $this->next();
    }
}

$CI = null;
function &get_instance() { global $CI; return $CI; }
function fresh_ci()
{
    global $CI, $LOG;
    $LOG = array();
    $CI = new FakeCI(); $CI->load = new FakeLoader(); $CI->config = new FakeConfig(); $CI->encryption = new FakeEncryption();
    $CI->db = new FakeDb();
    $CI->db->tables = array('open_ai_config' => array(), 'ai_usage_log' => array(), 'ai_knowledge_sources' => array(), 'ai_knowledge_chunks' => array());
    $CI->db->fields['ai_usage_log'] = array('id', 'user_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'purpose', 'created_at', 'units', 'estimated_cost_usd');
    $CI->db->fields['ai_knowledge_chunks'] = array('id', 'source_id', 'chunk_text', 'chunk_order', 'embedding', 'embedding_model');
    $CI->anthropic_api = new StubModel(array(array('error' => array('message' => 'no script'))));
    $CI->openai_api = new StubModel(array(array('error' => array('message' => 'no script'))));
    return $CI;
}
fresh_ci();

require APPPATH . 'helpers/secret_helper.php';
require APPPATH . 'helpers/ai_usage_helper.php';
require APPPATH . 'helpers/ai_knowledge_helper.php';
require APPPATH . 'helpers/ai_safety_helper.php';

function anth($text, $in = 900, $out = 6) {
    return array('choices' => array(array('text' => $text)), 'content' => array(array('type' => 'text', 'text' => $text)), 'stop_reason' => 'end_turn',
        'usage' => array('input_tokens' => $in, 'output_tokens' => $out), 'model' => 'claude-haiku-4-5');
}
function anth_tenant($uid, $key, $extra = array()) {
    global $CI;
    $CI->db->insert('open_ai_config', array_merge(array('user_id' => $uid, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => $key,
        'anthropic_model' => 'claude-opus-4-1', 'open_ai_secret_key' => '', 'models' => 'gpt-4o-mini', 'maximum_token' => '900', 'temperature' => '0.9'), $extra));
}

$SOURCE = "Double room from EGP 4,500/night\nDay use 900 EGP\nCoffee break 350 EGP";
$REPLY = 'الدبل بيبدأ من 4,500 جنيه لليلة';

// =================================================================== 1. routing
echo "== 1. the judge follows the tenant's provider ==\n";

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: 4,500 is the Double room price')));
$v = ai_verify_price_grounding(7, 'بكام الدبل؟', $REPLY, $SOURCE, "العميل: عندكم غرف؟\nالبوت: عندنا دبل وسويت");
same('anthropic tenant: verdict grounded (was always unknown)', 'grounded', $v);
same('anthropic tenant: exactly one Anthropic call', 1, count($CI->anthropic_api->calls));
same('anthropic tenant: OpenAI driver never called', 0, count($CI->openai_api->calls));
$c = $CI->anthropic_api->calls[0];
same('anthropic judge: tenant\'s Anthropic key', 'sk-ant-7', $c['key']);
same('anthropic judge: cheap judge model, not the tenant\'s reply model', 'claude-haiku-4-5', $c['model']);
same('anthropic judge: temperature 0 (tenant temperature 0.9 ignored)', 0.0, $c['temperature']);
same('anthropic judge: 60 tokens (tenant maximum_token ignored)', 60, $c['max_tokens']);
same('anthropic judge: short timeout (8s)', 8, $c['timeout']);
same('anthropic judge: no tools', null, $c['tools']);
check('anthropic judge: same system prompt as the OpenAI judge', strpos($c['system'], "You audit a sales bot's reply for price grounding.") === 0
    && strpos($c['system'], 'JUDGE NUMERIC GROUNDING ONLY') !== false && substr($c['system'], -strlen("then ': ' and a short reason.")) === "then ': ' and a short reason.");
same('anthropic judge: one user turn', array('user'), array_column($c['messages'], 'role'));
same('anthropic judge: user turn = SOURCE + CONVERSATION + QUESTION + REPLY', "SOURCE:\n" . $SOURCE . "\n\nCONVERSATION SO FAR:\nالعميل: عندكم غرف؟\nالبوت: عندنا دبل وسويت\n\nCUSTOMER ASKED: بكام الدبل؟\n\nBOT REPLY: " . $REPLY, $c['messages'][0]['content']);
$u = array_values($CI->db->tables['ai_usage_log']);
same('anthropic judge: one usage row', 1, count($u));
same('anthropic judge: usage anthropic / haiku / price_judge / tenant / tokens', array('anthropic', 'claude-haiku-4-5', 'price_judge', 7, 900, 6),
    array($u[0]['provider'] ?? null, $u[0]['model'] ?? null, $u[0]['purpose'] ?? null, $u[0]['user_id'] ?? null, $u[0]['input_tokens'] ?? null, $u[0]['output_tokens'] ?? null));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: ok')));
ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE);
check('no context: user turn has no CONVERSATION block', strpos($CI->anthropic_api->calls[0]['messages'][0]['content'], 'CONVERSATION SO FAR') === false);

fresh_ci();
anth_tenant(7, fake_enc('sk-ant-secret'));
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: ok')));
same('enc:: Anthropic key: decrypted before the call', array('grounded', 'sk-ant-secret'), array(ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE), $CI->anthropic_api->calls[0]['key'] ?? null));

fresh_ci();
anth_tenant(7, 'sk-ant-7', array('ai_tools_enabled' => '1'));
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: ok')));
ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE);
same('tools enabled for the tenant: the judge still gets one plain call, no tools', array(1, null), array(count($CI->anthropic_api->calls), $CI->anthropic_api->calls[0]['tools']));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('UNGROUNDED: 350 is not in the source for day use')));
same('anthropic: UNGROUNDED with a grounding reason -> ungrounded', 'ungrounded', ai_verify_price_grounding(7, 'الداي يوز بـ350؟', 'أيوه الداي يوز بـ350 جنيه', $SOURCE));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(array('error' => array('message' => 'overloaded_error'))));
same('anthropic error: fails open to unknown', 'unknown', ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE));
same('anthropic error: nothing billed', 0, count($CI->db->tables['ai_usage_log']));
check('anthropic error: logged with the provider reason', (bool) array_filter($LOG, function ($l) { return strpos($l[1], 'anthropic error: overloaded_error') !== false; }));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array('not json'));
same('anthropic unreadable response: unknown', 'unknown', ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('')));
same('anthropic empty text: unknown', 'unknown', ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE));

fresh_ci();
anth_tenant(7, '', array('open_ai_secret_key' => 'sk-openai-7'));
same('anthropic tenant without an Anthropic key: unknown', 'unknown', ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE));
same('... and no model call at all (never falls back to the OpenAI key)', array(0, 0), array(count($CI->anthropic_api->calls), count($CI->openai_api->calls)));

fresh_ci();
anth_tenant(7, fake_enc('broken'), array());
$CI->db->tables['open_ai_config'][1]['anthropic_secret_key'] = 'enc::garbage';
same('anthropic key that cannot be decrypted: unknown, no call', array('unknown', 0), array(ai_verify_price_grounding(7, 'q', $REPLY, $SOURCE), count($CI->anthropic_api->calls)));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('GROUNDED')));
same('headcount gate still runs first, without any model call', array('ungrounded', 0),
    array(ai_verify_price_grounding(7, 'q', 'الدبل بـ 4,500 جنيه للشخص', $SOURCE), count($CI->anthropic_api->calls)));
same('empty source: unknown before any lookup', array('unknown', 0), array(ai_verify_price_grounding(7, 'q', $REPLY, '  '), count($CI->anthropic_api->calls)));

fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: ok')));
$CI->load->library('Ai_provider');
$CI->ai_provider->completion(array('user_id' => 7, 'ai_provider' => 'anthropic', 'anthropic_secret_key' => 'k'), array(array('role' => 'user', 'content' => 'x')));
same('other Anthropic callers keep the 60s driver timeout', 60, $CI->anthropic_api->calls[0]['timeout']);

// fail-closed flag interplay: a judged Anthropic reply is no longer an 'unknown'
fresh_ci();
anth_tenant(7, 'sk-ant-7');
$CI->anthropic_api = new StubModel(array(anth('GROUNDED: ok')));
$v = ai_verify_price_grounding(7, 'q', 'الكوفي بريك 350 جنيه', 'Coffee break EGP 1,350');
same('fail-closed ON: a reply Anthropic judged grounded is sent', 'send', ai_price_guard_action($v, 'الكوفي بريك 350 جنيه', 'Coffee break EGP 1,350', true)['action']);
$CI->anthropic_api = new StubModel(array(array('error' => array('message' => 'timeout'))));
$v = ai_verify_price_grounding(7, 'q', 'الكوفي بريك 350 جنيه', 'Coffee break EGP 1,350');
same('fail-closed ON: Anthropic outage + unwritten figure -> block', array('unknown', 'block'), array($v, ai_price_guard_action($v, 'الكوفي بريك 350 جنيه', 'Coffee break EGP 1,350', true)['action']));

// =================================================================== 2. OpenAI unchanged
echo "== 2. OpenAI tenants: request unchanged ==\n";

fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 8, 'ai_provider' => 'openai', 'open_ai_secret_key' => '', 'anthropic_secret_key' => 'sk-ant-unused'));
same('openai tenant without an OpenAI key: unknown', 'unknown', ai_verify_price_grounding(8, 'q', $REPLY, $SOURCE));
same('... never routed to Anthropic, Ai_provider never loaded', array(0, 0, array()), array(count($CI->anthropic_api->calls), count($CI->openai_api->calls), $CI->load->libraries));
fresh_ci();
$CI->db->insert('open_ai_config', array('user_id' => 9, 'open_ai_secret_key' => ''));   // legacy row, no ai_provider column
same('legacy row (no ai_provider): OpenAI path', array('unknown', 0), array(ai_verify_price_grounding(9, 'q', $REPLY, $SOURCE), count($CI->anthropic_api->calls)));
same('no config row: OpenAI path, unknown', 'unknown', ai_verify_price_grounding(99, 'q', $REPLY, $SOURCE));
same('provider: gemini/unknown value -> openai (as Ai_provider)', 'openai', ai_price_judge_provider(array('ai_provider' => 'gemini')));
same('provider: anthropic', 'anthropic', ai_price_judge_provider(array('ai_provider' => 'anthropic')));

// The OpenAI request must be byte-identical to release/9.x-integration (ba600db1). Pinned
// hashes of the prompt construction, the request block and the verdict parser.
$src = file_get_contents(APPPATH . 'helpers/ai_knowledge_helper.php');
$fn = substr($src, strpos($src, 'function ai_verify_price_grounding('));
$i = strpos($fn, '$system = "You audit'); $j = strpos($fn, 'BOT REPLY: " . $reply;', $i) + strlen('BOT REPLY: " . $reply;');
same('OpenAI judge: system prompt + user turn unchanged (sha1 pinned)', '59bd45fd10acd28669ef06c1e8d6c63360093371', sha1(substr($fn, $i, $j - $i)));
$k = strpos($fn, "        \$payload = json_encode(array(\n            'model' => AI_GUARD_MODEL,"); $l = strpos($fn, 'curl_close($ch);', $k) + strlen('curl_close($ch);');
same('OpenAI judge: model/params/URL/timeouts/headers unchanged (sha1 pinned)', 'b80341827211a22400a48703430e640c689b07b8', sha1(substr($fn, $k, $l - $k)));
$vfn = substr($src, strpos($src, 'function ai_price_judge_verdict('));
$p = strpos($vfn, "        if (\$verdict === '') {\n            return 'unknown';"); $q = strpos($vfn, "        return 'unknown';\n    }\n}", $p);
same('verdict parser moved verbatim (sha1 pinned)', 'c625854613b375a61d48bf70db819caf26d55525', sha1(substr($vfn, $p, $q - $p)));
check('OpenAI judge: usage still recorded as price_judge after the call', strpos($fn, "ai_usage_record_openai_response(\$user_id, AI_GUARD_MODEL, 'price_judge', \$decoded);") !== false);
check('OpenAI judge: key still from ai_openai_key() (decrypts enc::)', strpos(substr($fn, 0, $k), '$api_key = ai_openai_key($user_id);') !== false);
check('Anthropic branch returns before the OpenAI request is built', strpos($fn, "if (\$provider === 'anthropic') {") < $k);

// =================================================================== 3. verdict contract
echo "== 3. verdict contract ==\n";
foreach (array(
    array('GROUNDED: matches', 'grounded'),
    array("  GROUNDED\n", 'grounded'),
    array('grounded: lower case', 'grounded'),
    array('UNGROUNDED: 350 belongs to a different item', 'ungrounded'),
    array('UNGROUNDED: the total is computed, not in the source', 'ungrounded'),
    array('UNGROUNDED: السعر مش موجود في المصدر', 'ungrounded'),
    array('UNGROUNDED: lists several prices, against the rules', 'grounded'),
    array('Sure! GROUNDED', 'unknown'),
    array('**GROUNDED**', 'unknown'),
    array('', 'unknown'),
    array(null, 'unknown'),
) as $case) {
    same('verdict ' . json_encode($case[0], JSON_UNESCAPED_UNICODE) . ' -> ' . $case[1], $case[1], ai_price_judge_verdict($case[0]));
}

// =================================================================== 4. backfill
echo "== 4. embedding backfill (cron_hub backfill_embeddings) ==\n";

function seed_kb($uid, $n, $key = null, $pre = array()) {
    global $CI;
    if ($key !== null) $CI->db->insert('open_ai_config', array('user_id' => $uid, 'open_ai_secret_key' => $key));
    $CI->db->insert('ai_knowledge_sources', array('user_id' => $uid, 'status' => 'active'));
    $sid = count($CI->db->tables['ai_knowledge_sources']);
    for ($i = 0; $i < $n; $i++) {
        $row = array('source_id' => $sid, 'chunk_text' => "u{$uid} chunk {$i}", 'chunk_order' => $i, 'embedding' => null, 'embedding_model' => null);
        if (isset($pre[$i])) $row = array_merge($row, $pre[$i]);
        $CI->db->insert('ai_knowledge_chunks', $row);
    }
}
/** Scripted embeddings driver: each entry is 'ok' | 'partial' | an HTTP status (int). Last repeats. */
class FakeEmbed
{
    public $calls = array(); public $script;
    public function __construct($script = array('ok')) { $this->script = $script; }
    public function __invoke($texts, $uid, $key, &$status) {
        $this->calls[] = array('n' => count($texts), 'uid' => $uid, 'key' => $key, 'first' => $texts[0]);
        $r = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
        if (is_int($r)) { $status = $r; return false; }
        $status = 200;
        $v = array();
        foreach ($texts as $i => $t) $v[] = array(0.25, (float) $i, 1.0);
        if ($r === 'partial') unset($v[0]);
        return $r === 'partial' ? array_combine(range(1, count($v)), $v) : $v;
    }
}
function run_backfill($opts, $embed, &$lines = null, &$sleeps = null) {
    $lines = array(); $sleeps = array();
    $opts['embed'] = $embed;
    $opts['sleep'] = function ($s) use (&$sleeps) { $sleeps[] = $s; };
    return ai_embedding_backfill($opts, function ($l) use (&$lines) { $lines[] = $l; });
}
function embedded_count() { global $CI; return count(array_filter($CI->db->tables['ai_knowledge_chunks'], function ($c) { return $c['embedding'] !== null && $c['embedding_model'] === AI_EMBEDDING_MODEL; })); }

fresh_ci();
seed_kb(1, 150, 'sk-1', array(0 => array('embedding' => 'x', 'embedding_model' => AI_EMBEDDING_MODEL), 1 => array('embedding' => 'x', 'embedding_model' => 'text-embedding-ada-002')));
seed_kb(2, 3, fake_enc('sk-two'));
$e = new FakeEmbed();
$t = run_backfill(array('dry_run' => true), $e, $lines);
same('dry run: counts pending per tenant (current-model chunk excluded, old-model chunk included)', array('user 1: 149 chunk(s) need embedding', 'user 2: 3 chunk(s) need embedding'), $lines);
same('dry run: no embeddings call, no writes', array(0, 0, 149 + 3), array(count($e->calls), $CI->db->updates, $t['pending']));

$e = new FakeEmbed();
$t = run_backfill(array(), $e, $lines, $sleeps);
same('run: batches of 96 per tenant', array(96, 53, 3), array_column($e->calls, 'n'));
same('run: every pending chunk embedded', array(152, 0, 152), array($t['embedded'], $t['failed'], embedded_count() - 1));
same('run: plain key used as is', 'sk-1', $e->calls[0]['key']);
same('run: enc:: key decrypted via ai_openai_key()', 'sk-two', $e->calls[2]['key']);
same('run: calls billed to the right tenant', array(1, 1, 2), array_column($e->calls, 'uid'));
$c1 = $CI->db->tables['ai_knowledge_chunks'][2];
same('run: old-model chunk re-embedded with ai_pack_vector + current model', array(ai_pack_vector(array(0.25, 0.0, 1.0)), AI_EMBEDDING_MODEL), array($c1['embedding'], $c1['embedding_model']));
same('run: chunk already on the current model untouched', 'x', $CI->db->tables['ai_knowledge_chunks'][1]['embedding']);
same('run: paced after full batches only', array(0.25), $sleeps);
check('run: output never contains chunk text or keys', !preg_grep('/chunk \d|sk-/', $lines), implode(' | ', $lines));

$e2 = new FakeEmbed();
$t = run_backfill(array(), $e2, $lines);
same('re-run is idempotent: nothing pending, no calls', array(0, 0, 0), array($t['pending'], $t['users'], count($e2->calls)));

fresh_ci();
seed_kb(1, 2, 'sk-1'); seed_kb(3, 2, ''); seed_kb(4, 2, 'enc::undecryptable'); seed_kb(5, 2, null);
$e = new FakeEmbed();
$t = run_backfill(array(), $e, $lines);
same('no key / undecryptable / no row: skipped, not failed', array(2, 6, 0), array($t['embedded'], $t['skipped_no_key'], $t['failed']));
check('undecryptable key reported as such', in_array('user 4: OpenAI key is stored encrypted and could not be decrypted (check encryption_key), skipping 2 chunk(s)', $lines, true), implode(' | ', $lines));
check('missing key reported', in_array('user 3: no OpenAI key, skipping 2 chunk(s)', $lines, true) && in_array('user 5: no OpenAI key, skipping 2 chunk(s)', $lines, true));
same('only the tenant with a key was called', array(1), array_values(array_unique(array_column($e->calls, 'uid'))));

fresh_ci();
seed_kb(1, 100, 'sk-1');
$e = new FakeEmbed(array(429, 429, 'ok'));
$t = run_backfill(array('pause_ms' => 0), $e, $lines, $sleeps);
same('429 twice: backs off 2s then 4s, then succeeds', array(array(2.0, 4.0), 2, 100, 0), array($sleeps, $t['retries'], $t['embedded'], $t['failed']));
same('429: the same batch is retried (same first chunk)', array('u1 chunk 0', 'u1 chunk 0', 'u1 chunk 0', 'u1 chunk 96'), array_column($e->calls, 'first'));

fresh_ci();
seed_kb(1, 10, 'sk-1');
$e = new FakeEmbed(array(503));
$t = run_backfill(array('max_retries' => 3, 'backoff_s' => 1, 'pause_ms' => 0), $e, $lines, $sleeps);
same('5xx that never recovers: max_retries then the batch fails', array(4, array(1.0, 2.0, 4.0), 10, 0), array(count($e->calls), $sleeps, $t['failed'], embedded_count()));

fresh_ci();
seed_kb(1, 10, 'sk-1');
$e = new FakeEmbed(array(0, 'ok'));
$t = run_backfill(array('pause_ms' => 0), $e, $lines, $sleeps);
same('transport failure (status 0) is retried', array(2, 10), array(count($e->calls), $t['embedded']));

fresh_ci();
seed_kb(1, 200, 'sk-bad'); seed_kb(2, 5, 'sk-2');
$e = new FakeEmbed(array(401, 'ok'));
$t = run_backfill(array('pause_ms' => 0), $e, $lines, $sleeps);
same('401: no retry, rest of that tenant skipped, next tenant still runs', array(2, array(1, 2), 200, 5, array()),
    array(count($e->calls), array_column($e->calls, 'uid'), $t['failed'], $t['embedded'], $sleeps));
check('401 reported', in_array('user 1: OpenAI rejected the key (HTTP 401), skipping the rest of this tenant', $lines, true));

fresh_ci();
seed_kb(1, 100, 'sk-1');
$e = new FakeEmbed(array(400, 'ok'));
$t = run_backfill(array('pause_ms' => 0), $e, $lines, $sleeps);
same('400: not retried, next batch continues, run ends (no infinite loop)', array(2, 96, 4, array()), array(count($e->calls), $t['failed'], $t['embedded'], $sleeps));
$e = new FakeEmbed();
$t = run_backfill(array('pause_ms' => 0), $e, $lines);
same('... and the next run picks the failed batch up', array(96, 96), array($t['pending'], $t['embedded']));

fresh_ci();
seed_kb(1, 4, 'sk-1');
$e = new FakeEmbed(array('partial'));
$t = run_backfill(array(), $e, $lines);
same('partial response: missing vectors counted as failed, rest stored', array(3, 1), array($t['embedded'], $t['failed']));

fresh_ci();
seed_kb(1, 3, 'sk-1'); seed_kb(2, 3, 'sk-2');
$e = new FakeEmbed();
$t = run_backfill(array('user_id' => 2, 'batch' => 2), $e, $lines);
same('--user N: only that tenant, --batch honoured', array(array(2, 2), array(2, 1), 3), array(array_column($e->calls, 'uid'), array_column($e->calls, 'n'), $t['embedded']));
same('batch is capped at 96', array(96), array_slice(array_column((function () { global $CI; fresh_ci(); seed_kb(1, 100, 'k'); $e = new FakeEmbed(); run_backfill(array('batch' => 500), $e); return $e->calls; })(), 'n'), 0, 1));

fresh_ci();
$CI->db->fields['ai_knowledge_chunks'] = array('id', 'source_id', 'chunk_text');
$t = run_backfill(array(), new FakeEmbed(), $lines);
same('embedding columns missing: refuses, failed = -1', array(-1, array('KB embedding columns are missing: run php index.php cron_hub migrate first')), array($t['failed'], $lines));

// static wiring
$hsrc = file_get_contents(APPPATH . 'helpers/ai_knowledge_helper.php');
$bf = substr($hsrc, strpos($hsrc, 'function ai_embedding_backfill('), 6000);
check('backfill: default driver is the real ai_embed_texts() (usage recorded as embedding)', strpos($bf, 'return ai_embed_texts($texts, $uid, $key, $status);') !== false);
check('backfill: keys from ai_openai_key()', strpos($bf, '$key = ai_openai_key($uid);') !== false);
check('ai_embed_texts: HTTP status out-parameter, existing callers unaffected', strpos($hsrc, "function ai_embed_texts(\$texts = array(), \$user_id = 0, \$api_key = '', &\$status = null)") !== false);
$cron = file_get_contents(APPPATH . 'controllers/Cron_hub.php');
$m = substr($cron, strpos($cron, 'public function backfill_embeddings('), 2600);
check('Cron_hub::backfill_embeddings exists and is CLI-only (first statement)', preg_match('/public function backfill_embeddings\(\)\s*\{\s*if \(!is_cli\(\)\) \{ show_404\(\); return; \}/', $m) === 1);
check('Cron_hub::backfill_embeddings takes an advisory lock and releases it', strpos($m, "GET_LOCK('mb_embedding_backfill', 0)") !== false && strpos($m, "RELEASE_LOCK('mb_embedding_backfill')") !== false);
check('Cron_hub::backfill_embeddings drives ai_embedding_backfill()', strpos($m, 'ai_embedding_backfill($opts,') !== false);
$old = file_get_contents(dirname(APPPATH) . '/docker/scripts/backfill_kb_embeddings.php');
check('old script prints that the CLI command is the supported path', strpos($old, 'echo "NOTE: this script is deprecated. The supported path is:') !== false && strpos($old, 'cron_hub backfill_embeddings') !== false);
check('runbook documents the command', is_file(dirname(APPPATH) . '/docs/runbooks/kb-embeddings-backfill.md')
    && strpos(file_get_contents(dirname(APPPATH) . '/docs/runbooks/kb-embeddings-backfill.md'), 'cron_hub backfill_embeddings') !== false);

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
