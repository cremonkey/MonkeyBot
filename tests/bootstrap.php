<?php
/**
 * Test bootstrap: the smallest stand-in for CodeIgniter the channel layer needs.
 * Stubs get_instance() (config only, plus whatever a test puts on it),
 * log_message() and secret_decrypt(); loads the REAL fb_graph_helper so
 * graph_url() builds URLs exactly as production does.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    // warnings/notices/deprecations fail the test that caused them
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('BASEPATH', dirname(__DIR__) . '/system/');
define('APPPATH', dirname(__DIR__) . '/application/');
date_default_timezone_set('UTC');

// ---- CodeIgniter stubs -------------------------------------------------------

final class StubConfig
{
    public $items = array(
        // same values as application/config/config.php
        'fb_graph_version'        => 'v26.0',
        'fb_graph_version_legacy' => 'v20.0',
    );
    public function item($key) { return isset($this->items[$key]) ? $this->items[$key] : null; }
}

final class StubCI
{
    public $config;
    public function __construct() { $this->config = new StubConfig(); }
}

$GLOBALS['__stub_ci'] = new StubCI();
$GLOBALS['__log'] = array();

function &get_instance()
{
    return $GLOBALS['__stub_ci'];
}

function log_message($level, $message)
{
    $GLOBALS['__log'][] = array($level, $message);
}

/** Visible transform so tests can prove the facade decrypts before sending. */
function secret_decrypt($stored)
{
    return 'dec(' . $stored . ')';
}

require APPPATH . 'helpers/fb_graph_helper.php';
require APPPATH . 'libraries/Channels/bootstrap.php';

// ---- tiny assertion kit -------------------------------------------------------

final class AssertionFailed extends \Exception {}
final class SkipTest extends \Exception {}

final class TestRegistry
{
    public static $cases = array();

    /** per-case global state reset */
    public static function reset()
    {
        $GLOBALS['__stub_ci'] = new StubCI();
        $GLOBALS['__log'] = array();
        \MonkeyBot\Channels\ChannelRegistry::useTransport(null);
    }
}

function t($name, callable $fn)
{
    if (isset(TestRegistry::$cases[$name])) throw new \LogicException('duplicate test name: ' . $name);
    TestRegistry::$cases[$name] = $fn;
}

function skip($why) { throw new SkipTest($why); }

function export($v) { return var_export($v, true); }

function assert_same($expected, $actual, $msg = '')
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg !== '' ? $msg . "\n" : '') . 'expected ' . export($expected) . "\n but got " . export($actual));
    }
}

function assert_true($cond, $msg = 'expected true')
{
    if ($cond !== true) throw new AssertionFailed($msg);
}

function assert_false($cond, $msg = 'expected false')
{
    if ($cond !== false) throw new AssertionFailed($msg);
}

function assert_null($v, $msg = '')
{
    if ($v !== null) throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . 'expected null, got ' . export($v));
}

function assert_count($n, $arr, $msg = '')
{
    if (count($arr) !== $n) throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . "expected $n items, got " . count($arr));
}

function fixture($name)
{
    $raw = file_get_contents(__DIR__ . '/channels/fixtures/' . $name . '.json');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new \RuntimeException('bad fixture ' . $name);
    return $data;
}

require __DIR__ . '/channels/support/FakeTransport.php';
