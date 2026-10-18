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

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
