<?php
/**
 * Minimal bootstrap for the conversation-core tests: CodeIgniter's real DB layer (query
 * builder + mysqli driver) against a disposable MariaDB database, without booting the app.
 * The helpers under test call get_instance()->db, which this file provides.
 *
 * Environment:
 *   MBCONV_HOST (default monkeybot-staging-db-1), MBCONV_USER (default mbconv),
 *   MBCONV_PW (required), MBCONV_DB (default mb_conv_test - must contain "test")
 *
 * Connection settings mirror production (char_set utf8, db_debug FALSE, stricton FALSE).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

$root = realpath(__DIR__ . '/../..') . '/';
define('BASEPATH', $root . 'system/');
define('APPPATH', $root . 'application/');
define('FCPATH', $root);
define('ENVIRONMENT', 'testing');

$dbname = getenv('MBCONV_DB') ?: 'mb_conv_test';
if (stripos($dbname, 'test') === false) {
    fwrite(STDERR, "refusing to run: database '{$dbname}' is not a test database\n");
    exit(2);
}
if (getenv('MBCONV_PW') === false) {
    fwrite(STDERR, "MBCONV_PW is not set\n");
    exit(2);
}

if (!function_exists('log_message')) { function log_message($level, $msg) {} }
if (!function_exists('show_error')) {
    function show_error($message, $status_code = 500, $heading = 'An Error Was Encountered')
    {
        throw new RuntimeException(is_array($message) ? implode('; ', $message) : $message);
    }
}
if (!function_exists('is_php')) { function is_php($v) { return version_compare(PHP_VERSION, $v, '>='); } }

require_once BASEPATH . 'database/DB.php';

$GLOBALS['__mbconv_params'] = array(
    'dsn' => '', 'hostname' => getenv('MBCONV_HOST') ?: 'monkeybot-staging-db-1',
    'username' => getenv('MBCONV_USER') ?: 'mbconv', 'password' => getenv('MBCONV_PW'),
    'database' => $dbname, 'dbdriver' => 'mysqli', 'dbprefix' => '', 'pconnect' => FALSE,
    'db_debug' => FALSE, 'cache_on' => FALSE, 'cachedir' => '', 'char_set' => 'utf8',
    'dbcollat' => 'utf8_general_ci', 'swap_pre' => '', 'stricton' => FALSE,
);
$GLOBALS['__mbconv_ci'] = new stdClass();
$GLOBALS['__mbconv_ci']->db = DB($GLOBALS['__mbconv_params'], TRUE);
$GLOBALS['__mbconv_ci']->db->initialize();

if (!function_exists('get_instance')) {
    function &get_instance() { return $GLOBALS['__mbconv_ci']; }
}

/** A second, independent connection (plain mysqli, utf8mb4) for lock and seed work. */
function mbconv_raw()
{
    static $m = null;
    if ($m === null) {
        $p = $GLOBALS['__mbconv_params'];
        $m = new mysqli($p['hostname'], $p['username'], $p['password'], $p['database']);
        $m->set_charset('utf8mb4');
    }
    return $m;
}

require_once APPPATH . 'helpers/conversation_helper.php';
require_once APPPATH . 'helpers/conversation_backfill_helper.php';

// ---- tiny test harness -------------------------------------------------------------------
$GLOBALS['__t'] = array('pass' => 0, 'fail' => 0, 'current' => '');

function t_ok($cond, $msg)
{
    if ($cond) { $GLOBALS['__t']['pass']++; return true; }
    $GLOBALS['__t']['fail']++;
    echo "    FAIL [{$GLOBALS['__t']['current']}] {$msg}\n";
    return false;
}

function t_eq($expected, $actual, $msg)
{
    return t_ok($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function t_run($name, callable $fn)
{
    $GLOBALS['__t']['current'] = $name;
    $before = $GLOBALS['__t']['fail'];
    try { $fn(); } catch (Throwable $e) { t_ok(false, 'exception: ' . $e->getMessage()); }
    echo ($GLOBALS['__t']['fail'] === $before ? 'ok      ' : 'NOT OK  ') . $name . "\n";
}

function tdb() { return get_instance()->db; }

function scalar($sql, $binds = array())
{
    $q = tdb()->query($sql, $binds);
    if ($q === FALSE) { $e = tdb()->error(); throw new RuntimeException('query failed: ' . $e['message']); }
    $row = $q->row_array();
    return $row ? reset($row) : null;
}

function rows($sql, $binds = array())
{
    $q = tdb()->query($sql, $binds);
    if ($q === FALSE) { $e = tdb()->error(); throw new RuntimeException('query failed: ' . $e['message']); }
    return $q->result_array();
}
