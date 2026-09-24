<?php
/**
 * Generic job queue (remaining-work Task 11 / B3, brief §42). DB-free unit tests: no
 * CodeIgniter bootstrap, no database, no network. get_instance() returns a small fake whose
 * ->db is an in-memory stand-in, mirroring tests/ai_usage_ids/run.php's FakeDb/FakeCI style.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/jobs/run.php
 *
 * Covers:
 *  1. job_enqueue(): writes a queued row with encoded payload, default run_after/max_attempts,
 *     honours overrides, no-ops (returns 0) when the jobs table is missing.
 *  2. job_claim(): atomic claim — only queued+due rows, respects limit and ordering, two
 *     concurrent claim tokens never see overlapping rows (SKIP LOCKED-equivalent semantics).
 *  3. job_complete(): marks a claimed row done.
 *  4. job_retry_or_dead_letter(): exponential backoff re-queue under max_attempts; dead-letter
 *     (status='dead') once attempts has reached max_attempts.
 *  5. job_handlers(): registry has kb_chunk_embed + conversation_summary mapped to callables
 *     that exist.
 *  6. job_handle_kb_chunk_embed(): chunks + writes total_chunks, calls the embed step.
 *  7. Wiring / flag-off = today: Ai_knowledge_base.php and Inbox.php read config 'jobs_enabled'
 *     and only take the job_enqueue path when it is TRUE; static source checks that the OLD
 *     inline calls (chunk/embed, Conversation_summarizer::summarize) are still present for the
 *     flag-off branch.
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
    private $auto = array();
    private $lastId = 0;
    private $t;
    private $wheres = array();
    private $order = null;
    private $lim = null;

    public function table_exists($t) { return isset($this->tables[$t]); }

    public function select($c) { return $this; }
    public function from($t) { $this->t = $t; $this->wheres = array(); $this->order = null; $this->lim = null; return $this; }
    public function where($k, $v = null) { $this->wheres[] = array($k, $v); return $this; }
    public function where_in($k, $vals) { $this->wheres[] = array($k, $vals, 'in'); return $this; }
    public function order_by($k, $d = 'ASC') { $this->order = array($k, $d); return $this; }
    public function limit($n) { $this->lim = (int) $n; return $this; }

    private function matches($row)
    {
        foreach ($this->wheres as $w) {
            $k = $w[0]; $v = $w[1];
            if (isset($w[2]) && $w[2] === 'in') {
                if (!in_array($row[$k] ?? null, $v, true)) return false;
                continue;
            }
            if (($row[$k] ?? null) !== $v) return false;
        }
        return true;
    }

    public function get()
    {
        $rows = array_values(array_filter($this->tables[$this->t] ?? array(), array($this, 'matches')));
        if ($this->order) {
            $k = $this->order[0]; $dir = strtoupper($this->order[1]) === 'DESC' ? -1 : 1;
            usort($rows, function ($a, $b) use ($k, $dir) { return $dir * strcmp((string) $a[$k], (string) $b[$k]); });
        }
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        return new FakeResult($rows);
    }

    public function insert($t, $data)
    {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data;
        $this->lastId = $id;
        return true;
    }
    public function insert_id() { return $this->lastId; }

    public function insert_batch($t, $rows)
    {
        foreach ($rows as $r) $this->insert($t, $r);
        return true;
    }

    public function update($t, $data)
    {
        foreach (($this->tables[$t] ?? array()) as $id => $row) {
            if ($this->matches($row)) $this->tables[$t][$id] = array_merge($row, $data);
        }
        $this->wheres = array();
        return true;
    }

    /** Only the exact claim UPDATE this suite's job_helper.php issues. */
    public function query($sql, $binds = array())
    {
        if (strpos($sql, "UPDATE jobs SET status='running'") === 0) {
            list($token, $now1, $now2, $due) = $binds;
            preg_match('/LIMIT\s+(\d+)/', $sql, $m);
            $limit = isset($m[1]) ? (int) $m[1] : 10000;
            $rows = $this->tables['jobs'] ?? array();
            $eligible = array_filter($rows, function ($r) use ($due) { return $r['status'] === 'queued' && $r['run_after'] <= $due; });
            uasort($eligible, function ($a, $b) {
                $c = strcmp($a['run_after'], $b['run_after']);
                return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
            });
            $eligible = array_slice($eligible, 0, $limit, true);
            foreach ($eligible as $id => $row) {
                $this->tables['jobs'][$id] = array_merge($row, array(
                    'status' => 'running', 'locked_by' => $token, 'locked_at' => $now1,
                    'attempts' => (int) $row['attempts'] + 1, 'updated_at' => $now2,
                ));
            }
            return new FakeResult(array());
        }
        return new FakeResult(array());
    }
}

class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? null; } }
class FakeLoader
{
    public $loaded = array();
    public function helper($h) { foreach ((array) $h as $n) { $this->loaded[] = $n; if ($n !== 'ai_knowledge' && $n !== 'conversation') require_once APPPATH . 'helpers/' . $n . '_helper.php'; } }
    public function library($l) { /* not needed for these tests */ }
}
class FakeCI
{
    public $db; public $config; public $load;
    public function __construct() { $this->db = new FakeDb(); $this->config = new FakeConfig(); $this->load = new FakeLoader(); }
}
$GLOBALS['__CI'] = new FakeCI();
function &get_instance() { return $GLOBALS['__CI']; }

require __DIR__ . '/../../application/helpers/job_helper.php';

function fresh_ci() { $GLOBALS['__CI'] = new FakeCI(); return $GLOBALS['__CI']; }

// ------------------------------------------------------------ 1. job_enqueue

$CI = fresh_ci();
$CI->db->tables['jobs'] = array();
$id = job_enqueue(7, 'kb_chunk_embed', array('source_id' => 3), array());
check('enqueue: returns positive id', $id > 0);
$row = $CI->db->tables['jobs'][$id];
same('enqueue: status queued', 'queued', $row['status']);
same('enqueue: attempts 0', 0, $row['attempts']);
same('enqueue: default max_attempts', 5, $row['max_attempts']);
same('enqueue: payload encoded', array('source_id' => 3), json_decode($row['payload_json'], true));
check('enqueue: run_after defaults to now-ish', abs(strtotime($row['run_after']) - time()) < 5);

$id2 = job_enqueue(7, 'kb_chunk_embed', array(), array('run_after' => time() + 3600, 'max_attempts' => 2));
$row2 = $CI->db->tables['jobs'][$id2];
check('enqueue: run_after override honoured', strtotime($row2['run_after']) > time() + 3500);
same('enqueue: max_attempts override honoured', 2, $row2['max_attempts']);

same('enqueue: 0 when user_id missing', 0, job_enqueue(0, 'x', array()));
same('enqueue: 0 when type empty', 0, job_enqueue(1, '', array()));

$CI2 = fresh_ci(); // jobs table missing entirely
same('enqueue: 0 when jobs table missing (pre-migration)', 0, job_enqueue(1, 'x', array()));

// ------------------------------------------------------------ 2. job_claim

$CI = fresh_ci();
$CI->db->tables['jobs'] = array();
$now = date('Y-m-d H:i:s');
$future = date('Y-m-d H:i:s', time() + 3600);
foreach (array(
    1 => array('id' => 1, 'user_id' => 1, 'type' => 'a', 'payload_json' => '{}', 'status' => 'queued', 'attempts' => 0, 'max_attempts' => 5, 'run_after' => $now, 'locked_by' => null),
    2 => array('id' => 2, 'user_id' => 1, 'type' => 'a', 'payload_json' => '{}', 'status' => 'queued', 'attempts' => 0, 'max_attempts' => 5, 'run_after' => $now, 'locked_by' => null),
    3 => array('id' => 3, 'user_id' => 1, 'type' => 'a', 'payload_json' => '{}', 'status' => 'queued', 'attempts' => 0, 'max_attempts' => 5, 'run_after' => $future, 'locked_by' => null),
    4 => array('id' => 4, 'user_id' => 1, 'type' => 'a', 'payload_json' => '{}', 'status' => 'done', 'attempts' => 1, 'max_attempts' => 5, 'run_after' => $now, 'locked_by' => null),
) as $id => $row) $CI->db->tables['jobs'][$id] = $row;

$claimed = job_claim(10, 'tok-A');
same('claim: only due+queued rows claimed', array(1, 2), array_map(function ($r) { return (int) $r['id']; }, $claimed));
same('claim: attempts incremented', 1, $CI->db->tables['jobs'][1]['attempts']);
same('claim: status running', 'running', $CI->db->tables['jobs'][1]['status']);
same('claim: locked_by set to token', 'tok-A', $CI->db->tables['jobs'][1]['locked_by']);
check('claim: future run_after not claimed', $CI->db->tables['jobs'][3]['status'] === 'queued');
check('claim: already-done row untouched', $CI->db->tables['jobs'][4]['status'] === 'done');

// limit respected
$CI = fresh_ci();
$CI->db->tables['jobs'] = array();
for ($i = 1; $i <= 5; $i++) $CI->db->tables['jobs'][$i] = array('id' => $i, 'user_id' => 1, 'type' => 'a', 'payload_json' => '{}', 'status' => 'queued', 'attempts' => 0, 'max_attempts' => 5, 'run_after' => $now, 'locked_by' => null);
$c1 = job_claim(2, 'tok-1');
same('claim: limit honoured', 2, count($c1));
$c2 = job_claim(2, 'tok-2');
$ids1 = array_map(function ($r) { return (int) $r['id']; }, $c1);
$ids2 = array_map(function ($r) { return (int) $r['id']; }, $c2);
same('claim: no overlap between two consecutive claims (SKIP LOCKED equivalent)', array(), array_intersect($ids1, $ids2));

// ------------------------------------------------------------ 3. job_complete

$CI = fresh_ci();
$CI->db->tables['jobs'] = array(1 => array('id' => 1, 'user_id' => 1, 'type' => 'a', 'status' => 'running', 'attempts' => 1, 'max_attempts' => 5, 'locked_by' => 'tok', 'last_error' => 'x'));
job_complete(1);
same('complete: status done', 'done', $CI->db->tables['jobs'][1]['status']);
same('complete: locked_by cleared', null, $CI->db->tables['jobs'][1]['locked_by']);
same('complete: last_error cleared', null, $CI->db->tables['jobs'][1]['last_error']);

// ------------------------------------------------------------ 4. retry / dead-letter

$CI = fresh_ci();
$CI->db->tables['jobs'] = array(1 => array('id' => 1, 'user_id' => 1, 'type' => 'a', 'status' => 'running', 'attempts' => 1, 'max_attempts' => 3, 'locked_by' => 'tok'));
$outcome = job_retry_or_dead_letter($CI->db->tables['jobs'][1], 'boom');
same('retry: outcome retry when attempts < max', 'retry', $outcome);
same('retry: status back to queued', 'queued', $CI->db->tables['jobs'][1]['status']);
same('retry: last_error stored', 'boom', $CI->db->tables['jobs'][1]['last_error']);
check('retry: run_after pushed into the future (backoff)', strtotime($CI->db->tables['jobs'][1]['run_after']) > time());
check('retry: locked_by cleared so job_claim can pick it up again', $CI->db->tables['jobs'][1]['locked_by'] === null);

$CI = fresh_ci();
$CI->db->tables['jobs'] = array(1 => array('id' => 1, 'user_id' => 1, 'type' => 'a', 'status' => 'running', 'attempts' => 3, 'max_attempts' => 3, 'locked_by' => 'tok'));
$outcome = job_retry_or_dead_letter($CI->db->tables['jobs'][1], 'still failing');
same('dead-letter: outcome dead when attempts >= max', 'dead', $outcome);
same('dead-letter: status dead', 'dead', $CI->db->tables['jobs'][1]['status']);
same('dead-letter: last_error stored', 'still failing', $CI->db->tables['jobs'][1]['last_error']);

// ------------------------------------------------------------ 5. handler registry

$handlers = job_handlers();
check('registry: kb_chunk_embed registered', isset($handlers['kb_chunk_embed']) && function_exists($handlers['kb_chunk_embed']));
check('registry: conversation_summary registered', isset($handlers['conversation_summary']) && function_exists($handlers['conversation_summary']));

// ------------------------------------------------------------ 6. job_handle_kb_chunk_embed

// Stub the network-touching helpers BEFORE the real helper is required, so the
// if(!function_exists()) guards in ai_knowledge_helper.php keep these stand-ins.
$GLOBALS['__embed_calls'] = array();
function ai_embed_source_chunks($source_id, $user_id) { $GLOBALS['__embed_calls'][] = array($source_id, $user_id); }
require_once APPPATH . 'helpers/ai_knowledge_helper.php'; // provides real ai_chunk_text (pure, no network); ai_chunk_text_ai deliberately left undefined

$CI = fresh_ci();
$CI->db->tables['ai_knowledge_sources'] = array(9 => array('id' => 9, 'user_id' => 4, 'total_chunks' => 0));
$CI->db->tables['ai_knowledge_chunks'] = array();
$long_text = str_repeat('MonkeyBot handles knowledge chunking for the AI assistant. ', 60);
job_handle_kb_chunk_embed(array('source_id' => 9, 'user_id' => 4, 'text' => $long_text), array('id' => 100));
check('kb handler: at least one chunk written', count($CI->db->tables['ai_knowledge_chunks']) > 0);
check('kb handler: total_chunks updated on the source row', $CI->db->tables['ai_knowledge_sources'][9]['total_chunks'] === count($CI->db->tables['ai_knowledge_chunks']));
same('kb handler: embed step called once with source/user', array(array(9, 4)), $GLOBALS['__embed_calls']);

try {
    job_handle_kb_chunk_embed(array('source_id' => 0, 'user_id' => 0, 'text' => 'x'), array('id' => 101));
    bad('kb handler: throws on missing ids', 'did not throw');
} catch (Exception $e) {
    ok('kb handler: throws on missing ids');
}

// ------------------------------------------------------------ 7. flag wiring / flag-off = today

$kb_src = file_get_contents(APPPATH . 'modules/ai_knowledge_base/controllers/Ai_knowledge_base.php');
check('kb controller: checks jobs_enabled flag', strpos($kb_src, "config->item('jobs_enabled')") !== false);
check('kb controller: still has the inline chunk loop for the flag-off path', strpos($kb_src, "ai_chunk_text_ai(\$text, \$this->user_id) : ai_chunk_text(\$text, 1000, 200)") !== false);
check('kb controller: still calls ai_embed_source_chunks inline for the flag-off path', substr_count($kb_src, 'ai_embed_source_chunks($source_id, $this->user_id)') === 1);
check('kb controller: enqueues kb_chunk_embed when flag is on', strpos($kb_src, "job_enqueue(\$this->user_id, 'kb_chunk_embed'") !== false);

$inbox_src = file_get_contents(APPPATH . 'controllers/Inbox.php');
check('inbox: checks jobs_enabled flag in summarize()', strpos($inbox_src, "config->item('jobs_enabled')") !== false);
check('inbox: still calls Conversation_summarizer::summarize() inline for the flag-off path', strpos($inbox_src, '$this->conversation_summarizer->summarize($ai_cfg, $messages, $conv[\'id\'])') !== false);
check('inbox: enqueues conversation_summary when flag is on', strpos($inbox_src, "job_enqueue(\$this->uid, 'conversation_summary'") !== false);

$job_worker_src = file_get_contents(APPPATH . 'controllers/Job_worker.php');
check('job_worker: CLI-gated (is_cli check present)', substr_count($job_worker_src, 'is_cli()') >= 1);
check('job_worker: uses GET_LOCK', strpos($job_worker_src, 'GET_LOCK') !== false);
check('job_worker: records ops health', strpos($job_worker_src, "ops_health_record('job_worker'") !== false);

echo "== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
