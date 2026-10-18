<?php
/**
 * application/helpers/ig_comment_guard_helper.php — no DB, no CI, no network.
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ig_comment_guard/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', 1);
$GLOBALS['__log'] = array();
function log_message($l, $m) { $GLOBALS['__log'][] = array($l, $m); }
require __DIR__ . '/../../application/helpers/ig_comment_guard_helper.php';

$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name  -> got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"; }
}

// ---- exact matching ----
$m = 'igcg_exact_match';
check('single word', $m('price please', 'price'), true);
check('no match inside longer word', $m('priceless', 'price'), false);
check('keyword case + spaces trimmed (old bug)', $m('send me the PRICE', ' Price '), true);
check('punctuation around word', $m('price?!', 'price'), true);
check('arabic question mark/comma', $m('السعر؟', 'السعر'), true);
check('arabic hamza/alef variants', $m('أسعار', 'اسعار'), true);
check('arabic ta marbuta', $m('عايز التفاصيلة', 'التفاصيله'), true);
check('arabic diacritics + tatweel', $m('السِّعـــر', 'السعر'), true);
check('arabic not inside longer word', $m('بالسعرات', 'السعر'), false);
check('4-word keyword (old max was 3)', $m('please send me the full price list now', 'send me the full'), true);
check('multi-word must be contiguous', $m('send the me', 'send me'), false);
check('mention removed', $m('@price hi', 'price'), false);
check('empty keyword never matches', $m('anything', ' '), false);
check('digits keyword', $m('رقم 1', '1'), true);
check('emoji-only text', $m('🔥🔥', 'price'), false);

// ---- claim ----
class FakeDB {
    public $db_debug = true; public $seen = array(); public $aff = 0; public $mode = 'ok'; public $debugDuring = null;
    function query($sql, $b) {
        $this->debugDuring = $this->db_debug;
        if ($this->mode === 'throw') throw new Exception('Table ig_comment_claims doesn\'t exist');
        if ($this->mode === 'false') return false;
        $k = $b[0] . '|' . $b[1];
        $this->aff = isset($this->seen[$k]) ? 0 : 1; $this->seen[$k] = 1; return true;
    }
    function affected_rows() { return $this->aff; }
}
$db = new FakeDB();
check('first delivery claims', igcg_claim($db, 7, '1799'), true);
check('second delivery rejected', igcg_claim($db, 7, '1799'), false);
check('other campaign same comment ok', igcg_claim($db, 8, '1799'), true);
check('db_debug off during query', $db->debugDuring, false);
check('db_debug restored', $db->db_debug, true);
check('comment id 0 passes', igcg_claim($db, 7, 0), true);
$db->mode = 'throw';
check('missing table fails open', igcg_claim($db, 9, 'x'), true);
check('db_debug restored after throw', $db->db_debug, true);
$db->mode = 'false';
check('query false fails open', igcg_claim($db, 9, 'y'), true);

// ---- wiring: controller must keep calling the guards ----
$src = file_get_contents(__DIR__ . '/../../application/controllers/Instagram_reply.php');
check('wiring: claim on comments path', substr_count($src, "igcg_claim(\$this->db, \$post_column_id, \$comment_id)") >= 1, true);
check('wiring: claim on mentions path', substr_count($src, "igcg_claim(\$this->db, \$post_column_id, \$current_id)") >= 1, true);
check('wiring: exact matcher used twice', substr_count($src, 'igcg_exact_match($comment_text') >= 2, true);

// ---- test mode ----
class FakeFb { public $calls = array(); public $prop = 'x';
    function __call($n, $a) { $this->calls[] = $n; return array('real' => $n); } }
$inner = new FakeFb(); $fb = new Igcg_dry_run_fb($inner);
check('dry: auto comment no-op', $fb->instagram_auto_comment('hi', '1', 't'), array('id' => '', 'dry_run' => true));
check('dry: hide no-op', $fb->instagram_hide_comment('1', 't')['dry_run'], true);
check('dry: delete no-op', $fb->instagram_delete_comment('1', 't')['dry_run'], true);
check('dry: subscriber lookup no-op', $fb->get_private_reply_message_id_info('m', 't')['dry_run'], true);
check('dry: reads pass through', $fb->instagram_get_media_url('a', 'b', 'c'), array('real' => 'instagram_get_media_url'));
check('dry: inner saw only the read', $inner->calls, array('instagram_get_media_url'));
check('dry: property passthrough', $fb->prop, 'x');

class FakeQ { public $r; function __construct($r) { $this->r = $r; } function row_array() { return $this->r; } }
class FakeDB2 { public $db_debug = true; public $row; public $throw = false;
    function query($s, $b) { if ($this->throw) throw new Exception('Unknown column dry_run'); return new FakeQ($this->row); } }
$d = new FakeDB2();
$d->row = array('dry_run' => '1'); check('is_dry_run on', igcg_is_dry_run($d, 3), true);
$d->row = array('dry_run' => '0'); check('is_dry_run off', igcg_is_dry_run($d, 3), false);
$d->row = null;                    check('is_dry_run missing row', igcg_is_dry_run($d, 3), false);
$d->throw = true;                  check('is_dry_run column missing => live', igcg_is_dry_run($d, 3), false);
check('is_dry_run db_debug restored', $d->db_debug, true);

class FakeLang { function line($k) { return $k; } }
$d->throw = false; $d->row = array('dry_run' => '1');
$c = igcg_campaign_controls('5"x', $d, new FakeLang(), 'circle');
check('controls: test mode on => go live', strpos($c, 'to_do="off"') !== false, true);
check('controls: id int-cast', strpos($c, 'table_id="5"') !== false && strpos($c, '5"x') === false, true);
check('controls: gate button present', strpos($c, 'igfg_gate_button') !== false, true);
$d->row = array('dry_run' => '0');
check('controls dropdown: turn on', strpos(igcg_campaign_controls(5, $d, new FakeLang(), 'dropdown'), "to_do='on'") !== false, true);
check('wiring: send_reply short-circuits in test mode', strpos($src, "if (\$this->igcg_dry_run) return array('dry_run' => true);") !== false, true);
check('wiring: dry-run entered on both paths', substr_count($src, 'igcg_is_dry_run($this->db, $post_column_id)) $this->igcg_enter_dry_run();'), 2);
check('wiring: all 3 report inserts tagged', substr_count($src, "IGCG_TEST_MODE_TAG . (isset("), 3);
check('wiring: inbox log skipped in test mode', strpos($src, "if (\$this->igcg_dry_run) return;") !== false, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
