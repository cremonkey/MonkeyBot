<?php
/**
 * Teams + team inbox -- Task 17 (C2, brief section 13). No DB, no CodeIgniter bootstrap, no network.
 *
 * Two kinds of coverage:
 *  1. Pure logic in application/helpers/team_helper.php (also exercised, for the filter-query
 *     shape it feeds, from tests/inbox/run.php).
 *  2. A static/behavioral audit of application/controllers/Inbox.php's new team_* / assign_team
 *     endpoints, same technique as tests/cross_tenant/run.php: read the controller source and
 *     prove every by-id read/write is guarded by user_id (own_team()) and that assign_team()
 *     never touches assigned_user_id (the existing per-agent assign stays additive-only).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/teams/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('team_helper.php must not need a CI instance for these functions'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/team_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

class FakeQB
{
    public $calls = array();
    public function where($a, $b = null, $escape = null) { $this->calls[] = array('where', $a, $b); return $this; }
}

echo "== team_scope_list() / team_scope_owner(): tenant guard shape ==\n";

$q = new FakeQB();
team_scope_list($q, 4321);
check('team_scope_list(): scopes by user_id, nothing else', $q->calls, array(array('where', 'user_id', 4321)));

$q = new FakeQB();
team_scope_owner($q, 9, 4321);
check('team_scope_owner(): id AND user_id both applied, id first', $q->calls, array(array('where', 'id', 9), array('where', 'user_id', 4321)));

echo "\n== team_ids_for_member() / team_normalize_name() ==\n";

check('team_ids_for_member(): only rows already scoped to this tenants teams count',
    team_ids_for_member(array(
        array('team_id' => 1, 'team_member_id' => 55),
        array('team_id' => 2, 'team_member_id' => 56),
    ), 55), array(1));
check('team_ids_for_member(): empty membership rows -> empty array', team_ids_for_member(array(), 55), array());

check('team_normalize_name(): tag-only input -> null', team_normalize_name('<i></i>'), null);
check('team_normalize_name(): tags stripped but visible text kept', team_normalize_name('<b>Support</b>'), 'Support');

echo "\n== static audit: Inbox.php team_* / assign_team endpoints ==\n";

$src = file_get_contents(__DIR__ . '/../../application/controllers/Inbox.php');

function method_body($src, $name, $next_markers) {
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) return null;
    $end = strlen($src);
    foreach ($next_markers as $m) {
        $p = strpos($src, $m, $start + 10);
        if ($p !== false && $p < $end) $end = $p;
    }
    return substr($src, $start, $end - $start);
}

check('Inbox.php declares team_save()', strpos($src, 'function team_save()') !== false, true);
check('Inbox.php declares team_delete()', strpos($src, 'function team_delete()') !== false, true);
check('Inbox.php declares team_membership_set()', strpos($src, 'function team_membership_set()') !== false, true);
check('Inbox.php declares teams_list()', strpos($src, 'function teams_list()') !== false, true);
check('Inbox.php declares assign_team()', strpos($src, 'function assign_team()') !== false, true);

$own_team = method_body($src, 'own_team', array("\n    private function", "\n    public function"));
check('own_team(): scoped through team_scope_owner($this->uid) -- not just an id lookup',
    $own_team !== null && strpos($own_team, 'team_scope_owner(') !== false && strpos($own_team, '$this->uid') !== false, true);

foreach (array('team_save', 'team_delete', 'team_membership_set', 'assign_team') as $fn) {
    $body = method_body($src, $fn, array("\n    private function", "\n    public function"));
    check("$fn(): calls ajax_check()", $body !== null && strpos($body, '$this->ajax_check()') !== false, true);
    check("$fn(): calls csrf_token_check()", $body !== null && strpos($body, '$this->csrf_token_check()') !== false, true);
}

foreach (array('team_delete', 'team_membership_set') as $fn) {
    $body = method_body($src, $fn, array("\n    private function", "\n    public function"));
    check("$fn(): looks the team up through own_team() before writing",
        $body !== null && strpos($body, "own_team((int) \$this->input->post('team_id'))") !== false, true);
}

$tms = method_body($src, 'team_membership_set', array("\n    private function", "\n    public function"));
check('team_membership_set(): re-scopes team_member_ids to team_members WHERE user_id = $this->uid',
    $tms !== null && strpos($tms, "->from('team_members')->where('user_id', \$this->uid)") !== false, true);

$assign_team = method_body($src, 'assign_team', array("\n    private function", "\n    public function"));
check('assign_team(): tenant-scoped conversation lookup via conversation_get($id, $this->uid)',
    $assign_team !== null && strpos($assign_team, 'conversation_get($conversation_id, $this->uid)') !== false, true);
check('assign_team(): the UPDATE also carries a user_id filter (defense in depth)',
    $assign_team !== null && strpos($assign_team, "->where('id', \$conv['id'])->where('user_id', \$this->uid)\n            ->update('conversations'") !== false, true);
check('assign_team(): never writes assigned_user_id -- additive only, existing assign() untouched',
    $assign_team !== null && strpos($assign_team, 'assigned_user_id') === false, true);
check('assign_team(): audits via audit_record() (B1 helper, brief section 40)',
    $assign_team !== null && strpos($assign_team, "audit_record(\$this->uid, 'inbox.assign_team'") !== false, true);

check('team_save(): audits team creation via audit_record()',
    strpos($src, "audit_record(\$this->uid, 'team.create'") !== false, true);

$assign = method_body($src, 'assign', array("\n    // ------", "\n    public function"));
check('assign() (individual agent, pre-existing): still updates assigned_user_id',
    $assign !== null && strpos($assign, "'assigned_user_id' => \$team_member_id") !== false, true);
check('assign() (individual agent, pre-existing): does not mention assigned_team_id at all',
    $assign !== null && strpos($assign, 'assigned_team_id') === false, true);
check('assign() (individual agent, pre-existing): still audits inbox.assign (unchanged action name)',
    $assign !== null && strpos($assign, "audit_record(\$this->uid, 'inbox.assign'") !== false, true);

$conv_data = method_body($src, 'conversations_data', array("\n    // ------", "\n    public function"));
check('conversations_data(): assigned_team_id filter is gated on $team_ready',
    $conv_data !== null && strpos($conv_data, "'assigned_team_id'  => \$team_ready") !== false, true);
check('conversations_data(): my_team is gated on $team_ready too',
    $conv_data !== null && strpos($conv_data, "if (\$team_ready && (bool) \$this->input->post('my_team'))") !== false, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
