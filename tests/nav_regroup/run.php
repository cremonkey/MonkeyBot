<?php
/**
 * Task 22 (C7, brief §34) — dependency-free tests for the admin navigation regroup
 * migration. No PHPUnit, no CodeIgniter bootstrap, no DB, no network: this parses the
 * SQL files as text and asserts the safety properties the review gate cares about.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/nav_regroup/run.php
 *
 * What it proves:
 *   - the migration contains no DELETE statement anywhere
 *   - the migration never assigns `url` or `module_access` (only serial/header_text/
 *     have_child are ever the target of a SET/VALUES column list on `menu`)
 *   - the backup table (menu_backup_20261005) is created before any UPDATE touches `menu`
 *   - the backup is created with CREATE TABLE IF NOT EXISTS (so a second run can't stomp it)
 *   - the revert script's SET list is exactly {serial, header_text, have_child} — the same
 *     columns the migration touches, no more, no less
 *   - every UPDATE in the migration is guarded by a WHERE clause (never a blanket UPDATE)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }

$root = dirname(__DIR__, 2);
$migration_path = $root . '/assets/backup_db/migrations/2026-10-05a-nav-regroup.sql';
$revert_path = $root . '/docker/scripts/nav_regroup_revert.sql';

check('migration file exists', file_exists($migration_path), $migration_path);
check('revert file exists', file_exists($revert_path), $revert_path);
if (!file_exists($migration_path) || !file_exists($revert_path)) {
    echo "\n$PASS passed, $FAIL failed\n";
    exit(1);
}

$sql = file_get_contents($migration_path);
$revert_sql = file_get_contents($revert_path);

// Strip SQL line comments (-- ...) before scanning for statements, exactly like
// docker/scripts/predeploy_check.sh does, so prose mentioning "DELETE" in a comment
// doesn't trip the check.
function strip_sql_comments($text) {
    $lines = explode("\n", $text);
    $out = array();
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $out[] = $line;
    }
    return implode("\n", $out);
}

$code = strip_sql_comments($sql);
$revert_code = strip_sql_comments($revert_sql);

echo "== no DELETE anywhere ==\n";
check(
    'migration has zero DELETE statements',
    !preg_match('/\bDELETE\s+FROM\b/i', $code),
    'found a DELETE FROM in the (comment-stripped) migration body'
);
check(
    'revert script has zero DELETE statements',
    !preg_match('/\bDELETE\s+FROM\b/i', $revert_code),
    'found a DELETE FROM in the (comment-stripped) revert script'
);

echo "\n== no row removed / no INSERT (this migration only regroups existing rows) ==\n";
check(
    'migration contains no INSERT INTO `menu`',
    !preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?menu`?/i', $code),
    'this migration must only UPDATE existing rows, never insert new ones'
);
check(
    'migration contains no DROP / TRUNCATE',
    !preg_match('/\b(DROP|TRUNCATE)\b/i', $code),
    'found a DROP/TRUNCATE'
);

echo "\n== url / module_access are never touched ==\n";
preg_match_all('/UPDATE\s+`?menu`?\s+SET\s+(.+?)\s+WHERE\s+(.+?);/is', $code, $update_matches, PREG_SET_ORDER);
check('migration contains UPDATE statements', count($update_matches) > 0, 'expected at least one UPDATE `menu` ... SET ...');

$touched_columns = array();
$all_where_guarded = true;
foreach ($update_matches as $m) {
    $set_clause = $m[1];
    $where_clause = trim($m[2]);
    if ($where_clause === '' || strtolower($where_clause) === '1') $all_where_guarded = false;
    // split SET assignments on commas that are not inside a quoted string
    $parts = preg_split("/,(?=(?:[^']*'[^']*')*[^']*$)/", $set_clause);
    foreach ($parts as $part) {
        if (!preg_match('/^\s*`?(\w+)`?\s*=/', $part, $cm)) continue;
        $touched_columns[$cm[1]] = true;
    }
}
$touched_columns = array_keys($touched_columns);
sort($touched_columns);

check(
    'every UPDATE has a non-empty WHERE clause',
    $all_where_guarded,
    'found an UPDATE without a specific WHERE guard'
);
check(
    'url is never assigned',
    !in_array('url', $touched_columns, true),
    'touched columns: ' . implode(',', $touched_columns)
);
check(
    'module_access is never assigned',
    !in_array('module_access', $touched_columns, true),
    'touched columns: ' . implode(',', $touched_columns)
);
check(
    'only serial / header_text / have_child are ever assigned',
    empty(array_diff($touched_columns, array('serial', 'header_text', 'have_child'))),
    'unexpected columns touched: ' . implode(',', array_diff($touched_columns, array('serial', 'header_text', 'have_child')))
);

echo "\n== backup-first ordering ==\n";
$backup_pos = strpos($code, 'menu_backup_20261005');
check('migration references menu_backup_20261005', $backup_pos !== false, 'backup table name not found');

$first_update_pos = PHP_INT_MAX;
foreach ($update_matches as $m) {
    $pos = strpos($code, $m[0]);
    if ($pos !== false && $pos < $first_update_pos) $first_update_pos = $pos;
}
check(
    'the backup table is created before the first UPDATE `menu`',
    $backup_pos !== false && $first_update_pos !== PHP_INT_MAX && $backup_pos < $first_update_pos,
    "backup at offset $backup_pos, first UPDATE at offset $first_update_pos"
);

check(
    'backup uses CREATE TABLE IF NOT EXISTS ... AS SELECT (idempotent, never overwrites)',
    (bool) preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?menu_backup_20261005`?\s+AS\s+SELECT\s+\*\s+FROM\s+`?menu`?/i', $code),
    'expected exactly CREATE TABLE IF NOT EXISTS menu_backup_20261005 AS SELECT * FROM menu'
);

echo "\n== revert restores exactly the columns the migration touches ==\n";
preg_match('/SET\s+(.+?);/is', $revert_code, $revert_set_match);
check('revert contains a SET clause', isset($revert_set_match[1]), 'no SET found in revert script');
if (isset($revert_set_match[1])) {
    $revert_parts = preg_split("/,(?=(?:[^']*'[^']*')*[^']*$)/", $revert_set_match[1]);
    $revert_columns = array();
    foreach ($revert_parts as $part) {
        if (preg_match('/`?\w+`?\.`?(\w+)`?\s*=/', $part, $cm)) $revert_columns[] = $cm[1];
    }
    sort($revert_columns);
    check(
        'revert SET list is exactly {header_text, have_child, serial}',
        $revert_columns === array('have_child', 'header_text', 'serial'),
        'got: ' . implode(',', $revert_columns)
    );
}
check(
    'revert joins on menu_backup_20261005 by id (row identity, not url)',
    (bool) preg_match('/JOIN\s+`?menu_backup_20261005`?\s+(?:AS\s+)?\w*\s+ON\s+.*`?id`?\s*=\s*.*`?id`?/i', $revert_code)
        || (bool) preg_match('/JOIN\s+`?menu_backup_20261005`?.*\bid\b.*=.*\bid\b/is', $revert_code),
    'expected a JOIN ... ON b.id = m.id'
);
check(
    'revert never touches url / module_access',
    !preg_match('/\b(url|module_access)\s*=/i', $revert_set_match[1] ?? ''),
    'revert SET clause must not touch url/module_access'
);

echo "\n== migration starts with DO 0; like every other migration in this repo ==\n";
check('migration starts with DO 0;', (bool) preg_match('/^\s*(?:--.*\n)*\s*DO\s+0;/', $sql), 'first executable statement must be DO 0;');

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
