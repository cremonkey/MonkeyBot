<?php
/**
 * Helper process for tests/request_id/run.php's writer test (case 4): runs ONE branch of
 * ai_usage_record()'s field_exists('request_id', ...) check in its own process, because that
 * check is cached in a `static` for the life of the process (mirrors Inbox.php's own
 * field_exists caching) and so can't be exercised both ways in a single run.
 *
 *   php tests/request_id/_writer_case.php missing|present
 */
define('BASEPATH', 1);
function log_message($level, $msg) { /* no-op */ }

class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? ''; } }
class FakeDB
{
    public $tables = array();
    public $inserts = array();
    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($c, $t) { return isset($this->tables[$t][$c]); }
    public function insert($t, $row) { $this->inserts[$t][] = $row; return true; }
    public function insert_id() { return 1; }
    public function select($x) { return $this; }
    public function from($x) { return $this; }
    public function where($a, $b = null) { return $this; }
    public function limit($n) { return $this; }
    public function get() { return $this; }
    public function row_array() { return null; }
}
class FakeCI { public $mb_request_id; public $config; public $db; public function __construct() { $this->config = new FakeConfig(); $this->db = new FakeDB(); } }

$GLOBALS['__fake_ci'] = new FakeCI();
function &get_instance() { return $GLOBALS['__fake_ci']; }

require __DIR__ . '/../../application/helpers/request_id_helper.php';
require __DIR__ . '/../../application/helpers/ai_usage_helper.php';

$case = $argv[1] ?? '';
request_id_set('writer-test-1111-4000-8000-000000000000');
$GLOBALS['__fake_ci']->db->tables['ai_usage_log'] = ($case === 'present') ? array('request_id' => true) : array();

ai_usage_record(7, 'openai', 'gpt-4o-mini', 'chat_reply', 10, 5);
$row = $GLOBALS['__fake_ci']->db->inserts['ai_usage_log'][0];
echo array_key_exists('request_id', $row) ? (string) $row['request_id'] : 'OMITTED';
