<?php
/**
 * tests/fixtures/content/fail-closed-runner.php — runs the content predicate against a copy
 * of lib/ whose derived table <name> is missing (#1242 T3a, the fail-closed guard). Prints
 * the predicate's result as JSON. One process, because the tables are cached per process.
 *
 *     php fail-closed-runner.php <table-file-to-remove>
 */
$table = $argv[1] ?? '';
$tmp = sys_get_temp_dir() . '/pp-content-fail-closed-' . getmypid();
$src = dirname(__DIR__, 3) . '/lib';
mkdir($tmp . '/lib/content-tables', 0700, true);
foreach (glob($src . '/*.php') as $f) {
    copy($f, $tmp . '/lib/' . basename($f));
}
foreach (glob($src . '/content-tables/*.json') as $f) {
    if (basename($f) !== $table) {
        copy($f, $tmp . '/lib/content-tables/' . basename($f));
    }
}
putenv('PP_TEST_LIB_DIR=' . $tmp . '/lib');
require dirname(__DIR__, 2) . '/bootstrap.php';
$result = pp_content_sanitize('<p>ordinary</p>', 'rich');
array_map('unlink', glob($tmp . '/lib/content-tables/*') ?: []);
array_map('unlink', glob($tmp . '/lib/*.php') ?: []);
rmdir($tmp . '/lib/content-tables');
rmdir($tmp . '/lib');
rmdir($tmp);
echo json_encode($result);
