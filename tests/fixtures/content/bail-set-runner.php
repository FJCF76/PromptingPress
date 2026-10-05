<?php
/**
 * tests/fixtures/content/bail-set-runner.php — the P-16 drift pin's measuring half (#1242 T3a).
 *
 *     php bail-set-runner.php <wordpress-version> <snapshot.json>
 *
 * Loads the test bootstrap with the vendored HTML API of <wordpress-version>
 * (tests/fixtures/wp-html-api-<version>), runs every input of <snapshot.json> through the
 * content predicate in the rich wrapper, and prints {"wordpress": .., "cases": [{input, bails}]}.
 * One process per version, because two versions of the same core classes cannot load into
 * one PHP process. With --write it prints a complete snapshot (used to regenerate one,
 * after the difference has been read and ruled).
 */
$version  = $argv[1] ?? '';
$snapshot = $argv[2] ?? '';
if (!preg_match('/^[0-9.]+\z/', $version) || !is_file($snapshot)) {
    fwrite(STDERR, "usage: php bail-set-runner.php <wordpress-version> <snapshot.json> [--write]\n");
    exit(2);
}
putenv('PP_TEST_HTML_API=' . $version);
require dirname(__DIR__, 2) . '/bootstrap.php';
$prev = json_decode((string) file_get_contents($snapshot), true);
$cases = [];
foreach ($prev['cases'] as $case) {
    $clauses = array_column(pp_content_sanitize($case['input'], 'rich')['losses'], 'clause');
    $cases[] = ['input' => $case['input'], 'bails' => in_array('P-16', $clauses, true)];
}
$out = ['wordpress' => $version, 'note' => $prev['note'] ?? '', 'cases' => $cases];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
