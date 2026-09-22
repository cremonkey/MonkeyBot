<?php
/**
 * Dependency-free test runner (no PHPUnit, no CodeIgniter bootstrap, no DB, no network).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/run.php [filter]
 *
 * Loads tests/channels/*Test.php; each file registers cases with t('name', fn).
 * Exit code 0 only when every case passed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';

$filter = isset($argv[1]) ? $argv[1] : '';
foreach (glob(__DIR__ . '/channels/*Test.php') as $file) require $file;

$passed = 0; $failed = 0; $skipped = 0; $failures = array();
foreach (TestRegistry::$cases as $name => $fn) {
    if ($filter !== '' && stripos($name, $filter) === false) continue;
    TestRegistry::reset();
    try {
        $fn();
        $passed++;
        echo "  ok    $name\n";
    } catch (SkipTest $e) {
        $skipped++;
        echo "  skip  $name (" . $e->getMessage() . ")\n";
    } catch (\Throwable $e) {
        $failed++;
        $failures[] = $name;
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        if (!($e instanceof AssertionFailed)) echo '        at ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

echo "\n$passed passed, $failed failed, $skipped skipped\n";
exit($failed === 0 && $passed > 0 ? 0 : 1);
