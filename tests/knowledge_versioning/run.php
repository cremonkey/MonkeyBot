<?php
/**
 * Knowledge base versioning / agent scope / citations (remaining-work Task 19 / C4, brief §19).
 * DB-free unit tests: no CodeIgniter bootstrap, no database, no network. Only pure PHP helper
 * functions from ai_knowledge_helper.php are exercised directly, plus static source checks of
 * Ai_knowledge_base.php and Home.php (same style as tests/jobs/run.php item 7).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/knowledge_versioning/run.php
 *
 * Covers:
 *  1. ai_kb_content_hash(): stable, content-sensitive, empty-safe.
 *  2. ai_kb_content_changed() / ai_kb_next_version(): version increments ONLY on changed
 *     content; a NULL/empty stored hash counts as changed (first update starts tracking).
 *  3. ai_kb_agent_scope_sql() / ai_kb_agent_scope_matches(): agent scope filter, and NULL
 *     scope (agent_id = 0, or every source's agent_id NULL) leaves retrieval unchanged.
 *  4. ai_kb_unique_source_ids(): dedupes/orders citation ids, skips rows without one.
 *  5. Wiring: update_source_action()/reindex_action() have CSRF + ajax + tenant ownership,
 *     reuse the exact jobs_enabled-gated kb_chunk_embed path (Task 11), and re-chunk
 *     delete-before-insert (idempotent reindex).
 *  6. Wiring: citations are guarded by field_exists('kb_sources', 'ai_reply_audit') in Home.php,
 *     and the agent id passed into ai_get_knowledge_context() comes from ai_resolve_profile().
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

require APPPATH . 'helpers/ai_knowledge_helper.php';

echo "== ai_kb_content_hash() ==\n";
{
    $h1 = ai_kb_content_hash("Hello world");
    $h2 = ai_kb_content_hash("Hello world");
    $h3 = ai_kb_content_hash("Hello WORLD");
    same('same text -> same hash', $h1, $h2);
    check('different text -> different hash', $h1 !== $h3);
    same('hash length is 40 (sha1, matches CHAR(40) column)', 40, strlen($h1));
    same('empty text -> empty hash', '', ai_kb_content_hash('   '));
    same('empty text -> empty hash (null-ish)', '', ai_kb_content_hash(''));
}

echo "== ai_kb_content_changed() / ai_kb_next_version() ==\n";
{
    $hash_a = ai_kb_content_hash('content A');
    $hash_b = ai_kb_content_hash('content B');

    check('same hash -> not changed', ai_kb_content_changed($hash_a, $hash_a) === false);
    check('different hash -> changed', ai_kb_content_changed($hash_a, $hash_b) === true);
    check('NULL stored hash -> changed (first update starts tracking)', ai_kb_content_changed(null, $hash_a) === true);
    check('empty stored hash -> changed', ai_kb_content_changed('', $hash_a) === true);

    same('unchanged content keeps version', 3, ai_kb_next_version(3, false));
    same('changed content bumps version by 1', 4, ai_kb_next_version(3, true));
    same('version floors at 1 even from a bad stored value', 2, ai_kb_next_version(0, true));
}

echo "== ai_kb_agent_scope_sql() ==\n";
{
    same('no agent resolved -> only global (NULL) sources', '(s.agent_id IS NULL)', ai_kb_agent_scope_sql(0));
    same('no agent resolved (negative) -> only global sources', '(s.agent_id IS NULL)', ai_kb_agent_scope_sql(-1));
    same('agent resolved -> global OR that agent', '(s.agent_id IS NULL OR s.agent_id = 5)', ai_kb_agent_scope_sql(5));
}

echo "== ai_kb_agent_scope_matches() ==\n";
{
    check('global source (NULL) matches any requested agent', ai_kb_agent_scope_matches(null, 0) && ai_kb_agent_scope_matches(null, 7));
    check('global source (0) matches any requested agent', ai_kb_agent_scope_matches(0, 0) && ai_kb_agent_scope_matches(0, 7));
    check('scoped source matches its own agent', ai_kb_agent_scope_matches(5, 5) === true);
    check('scoped source does not match a different agent', ai_kb_agent_scope_matches(5, 7) === false);
    check('scoped source does not match "no agent resolved"', ai_kb_agent_scope_matches(5, 0) === false);

    // NULL scope = unchanged results: when every source in the account has agent_id NULL
    // (true for every source today, and every source added before an owner opts one into an
    // agent), filtering with agent_id=0 keeps ALL of them -- identical to no filter at all.
    $sources = array(
        array('id' => 1, 'agent_id' => null),
        array('id' => 2, 'agent_id' => null),
        array('id' => 3, 'agent_id' => null),
    );
    $filtered_no_agent = array_values(array_filter($sources, function ($s) { return ai_kb_agent_scope_matches($s['agent_id'], 0); }));
    $filtered_with_agent = array_values(array_filter($sources, function ($s) { return ai_kb_agent_scope_matches($s['agent_id'], 9); }));
    same('all-NULL sources: filter(0) keeps every source', array(1, 2, 3), array_column($filtered_no_agent, 'id'));
    same('all-NULL sources: filter(any agent) still keeps every source', array(1, 2, 3), array_column($filtered_with_agent, 'id'));

    // Once a source IS scoped, "no agent resolved" (0) correctly excludes it, and a
    // different agent's reply correctly excludes it, but its own agent still sees it.
    $mixed = array(
        array('id' => 1, 'agent_id' => null),
        array('id' => 2, 'agent_id' => 9),
        array('id' => 3, 'agent_id' => null),
    );
    $for_no_agent = array_values(array_filter($mixed, function ($s) { return ai_kb_agent_scope_matches($s['agent_id'], 0); }));
    $for_agent_9  = array_values(array_filter($mixed, function ($s) { return ai_kb_agent_scope_matches($s['agent_id'], 9); }));
    $for_agent_2  = array_values(array_filter($mixed, function ($s) { return ai_kb_agent_scope_matches($s['agent_id'], 2); }));
    same('mixed sources: no agent resolved sees only the two global ones', array(1, 3), array_column($for_no_agent, 'id'));
    same('mixed sources: agent 9 sees its own source plus the global ones', array(1, 2, 3), array_column($for_agent_9, 'id'));
    same('mixed sources: a different agent never sees agent 9\'s source', array(1, 3), array_column($for_agent_2, 'id'));
}

echo "== ai_kb_unique_source_ids() ==\n";
{
    $rows = array(
        array('source_id' => 3, 'chunk_text' => 'a'),
        array('source_id' => 1, 'chunk_text' => 'b'),
        array('source_id' => 3, 'chunk_text' => 'c'), // dup, kept once, first position
        array('chunk_text' => 'd'),                    // no source_id -> skipped
        array('source_id' => 0, 'chunk_text' => 'e'),  // 0 -> skipped
    );
    same('unique, ordered, dedup source ids', array(3, 1), ai_kb_unique_source_ids($rows));
    same('empty input -> empty output', array(), ai_kb_unique_source_ids(array()));
}

echo "== Wiring: Ai_knowledge_base.php controller ==\n";
{
    $src = file_get_contents(APPPATH . 'modules/ai_knowledge_base/controllers/Ai_knowledge_base.php');

    check('update_source_action() exists', (bool) preg_match('/function\s+update_source_action\s*\(/', $src));
    check('reindex_action() exists', (bool) preg_match('/function\s+reindex_action\s*\(/', $src));

    foreach (array('update_source_action', 'reindex_action', 'set_agent_scope_action') as $fn) {
        if (!preg_match('/function\s+' . $fn . '\s*\(\)\s*\{(.*?)\n    \}/s', $src, $m)) {
            bad("$fn: located in source", 'could not isolate function body');
            continue;
        }
        $body = $m[1];
        check("$fn: calls csrf_token_check()", strpos($body, '$this->csrf_token_check()') !== false);
        check("$fn: calls ajax_check()", strpos($body, '$this->ajax_check()') !== false);
        check("$fn: scopes its lookup by user_id (tenant ownership)", strpos($body, "'user_id' => \$this->user_id") !== false);
    }

    // Both actions must reuse Task 11's exact jobs_enabled gate + kb_chunk_embed job type,
    // via the shared _kb_rechunk() helper, not a second copy of the chunk/embed logic.
    if (preg_match('/private function _kb_rechunk\s*\(.*?\)\s*\{(.*?)\n    \}/s', $src, $m)) {
        $body = $m[1];
        check('_kb_rechunk(): reads jobs_enabled flag', strpos($body, "\$this->config->item('jobs_enabled')") !== false);
        check('_kb_rechunk(): enqueues kb_chunk_embed (same job type as Task 11)', strpos($body, "'kb_chunk_embed'") !== false);
        check('_kb_rechunk(): deletes existing chunks before re-inserting (idempotent reindex)', strpos($body, 'ai_delete_knowledge_chunks($source_id)') !== false);
        check('_kb_rechunk(): falls back to inline chunk+embed when enqueue fails', strpos($body, 'ai_chunk_text_ai') !== false && strpos($body, 'ai_embed_source_chunks') !== false);
    } else {
        bad('_kb_rechunk(): located in source', 'helper not found');
    }

    check('update_source_action(): guards version columns with field_exists', strpos($src, "field_exists('content_hash', 'ai_knowledge_sources')") !== false);
    check('update_source_action(): version only bumped on changed content', strpos($src, 'ai_kb_content_changed(') !== false && strpos($src, 'ai_kb_next_version(') !== false);
    check('set_agent_scope_action(): guards agent_id column with field_exists', strpos($src, "field_exists('agent_id', 'ai_knowledge_sources')") !== false);
}

echo "== Wiring: Home.php retrieval + citations ==\n";
{
    $src = file_get_contents(APPPATH . 'controllers/Home.php');

    check('agent id passed into ai_get_knowledge_context() comes from ai_resolve_profile()', (bool) preg_match('/\$kb_agent_id\s*=.*ai_profile\[.id.\].*\n.*ai_get_knowledge_context\(\$user_id, \$human, \$kb_page_id, 5, \$kb_agent_id, \$this->ai_kb_source_ids\)/', $src));
    check('kb_sources column write is guarded by field_exists()', strpos($src, "field_exists('kb_sources', 'ai_reply_audit')") !== false);
    check('ai_kb_source_ids reset at the top of the reply (no leakage across replies)', strpos($src, '$this->ai_kb_source_ids = array();') !== false);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
