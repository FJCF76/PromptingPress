<?php
/**
 * tests/ContentTableDriftTest.php
 *
 * The drift pins of Layer 3A (#1242 T3a; LAYER-3-CONTRACT.md §3.1 / M-4 / T-1, P-16, P-17).
 *
 *   T-1   the PP-owned table equals derive(core `post` on WordPress 7.0) − §4 + Δ, computed
 *         here from the stored core snapshot with this test's OWN copy of the §4 lists —
 *         never the table compared to itself. The other half of T-1 (stored snapshot ==
 *         live core) needs a running WordPress and lives in tests/e2e/content-predicate.spec.ts.
 *   P-16  the parser-bail set, measured on the vendored WordPress 7.0 parser, equals the
 *         stored snapshot; the E2E suite re-measures it on live core, so a WordPress upgrade
 *         that moves the boundary fails a test and is read, never absorbed.
 *   The vendored HTML API is the version .wp-env.json pins.
 */

use PHPUnit\Framework\TestCase;

class ContentTableDriftTest extends TestCase
{
    /** §4 as drafted, restated here so the derivation is independent of lib/content.php. */
    private const E3_E4 = ['iframe', 'frame', 'frameset', 'embed', 'applet', 'portal', 'fencedframe',
        'script', 'style', 'noscript', 'template', 'base', 'meta', 'link', 'title', 'html', 'head', 'body',
        'slot', 'xmp', 'noembed', 'noframes', 'plaintext', 'listing',
        // Δ5, descoped by the owner 2026-10-05: forms are refused (D5) until the forms contract.
        'form', 'input', 'select', 'option', 'optgroup', 'selectedcontent', 'datalist', 'textarea', 'output',
        'fieldset', 'legend'];
    private const E9 = ['formaction', 'formtarget', 'formmethod', 'formenctype', 'ping', 'http-equiv', 'form', 'srcdoc'];

    private function core(): array
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__) . '/lib/content-tables/core-post-wp-7.0.json'), true);
        return $raw['tags'];
    }

    public function testTheCoreSnapshotIsWordPress70Post(): void
    {
        $core = $this->core();
        $this->assertCount(124, $core, 'probe-00: core `post` on WordPress 7.0 has 124 elements');
        $this->assertArrayHasKey('object', $core);
        $this->assertArrayNotHasKey('svg', $core);
        $this->assertArrayNotHasKey('form', $core, '5.0.1 removed <form> from core post');
    }

    public function testT1TheTableIsCoreMinusSection4PlusTheDeltaRows(): void
    {
        $table   = pp_content_html_table();
        $globals = pp_content_html_global_attributes() + ['data-*' => true];
        $adds    = pp_content_html_element_additions();

        // Every core element is admitted unless §4 excludes it (object survives as P-11's row).
        foreach ($this->core() as $tag => $attrs) {
            if (in_array($tag, self::E3_E4, true)) {
                $this->assertArrayNotHasKey($tag, $table, "§4 excludes <{$tag}>");
                continue;
            }
            $this->assertArrayHasKey($tag, $table, "core admits <{$tag}> and §4 does not exclude it");
            foreach ($attrs as $attr => $_) {
                if (in_array($attr, self::E9, true)) {
                    $this->assertArrayNotHasKey($attr, $table[$tag], "E9 excludes {$attr}");
                    continue;
                }
                $this->assertTrue(isset($table[$tag][$attr]) || isset($globals[$attr]),
                    "core admits {$attr} on <{$tag}>; the table lost it");
            }
        }
        // Every table entry is core's or a named addition (no silent widening).
        foreach ($table as $tag => $attrs) {
            foreach ($attrs as $attr => $_) {
                $from_core = isset($this->core()[$tag][$attr]);
                $from_adds = in_array($attr, $adds[$tag] ?? [], true);
                $this->assertTrue($from_core || $from_adds, "{$attr} on <{$tag}> is neither core nor a named addition");
                $this->assertNotContains($attr, self::E9);
            }
        }
        // Every core global survives (P-17 widens; it never narrows core).
        $core_globals = array_intersect_key(...array_values(array_map(static fn ($a) => (array) $a, $this->core())));
        $this->assertCount(19, $core_globals, '_wp_add_global_attributes() adds 19');
        foreach ($core_globals as $attr => $_) {
            $this->assertArrayHasKey($attr, $globals, "core global {$attr}");
        }
        $this->assertArrayNotHasKey('nonce', $globals, 'P-17 argued: nonce refused');
        foreach (['autofocus', 'contenteditable', 'is'] as $argued) {
            $this->assertArrayHasKey($argued, $globals, "P-17 argued: {$argued} admitted");
        }
    }

    public function testTheVendoredHtmlApiIsTheVersionWpEnvPins(): void
    {
        $env = json_decode((string) file_get_contents(dirname(__DIR__) . '/.wp-env.json'), true);
        $this->assertSame('WordPress/WordPress#7.0', $env['core']);
        $this->assertDirectoryExists(__DIR__ . '/fixtures/wp-html-api-7.0');
        $this->assertStringContainsString('7.0', (string) file_get_contents(__DIR__ . '/fixtures/wp-html-api-7.0/README.md'));
        $this->assertStringContainsString("\$wp_version = '7.1.2'", (string) file_get_contents(__DIR__ . '/fixtures/wp-html-api-7.1.2/README.md'));
    }

    /**
     * P-16 against BOTH truths (2026-10-05 ruling on T3a 7A question 3): the version CI pins
     * (7.0) and the version production runs (7.1.2). Each is measured in its own process,
     * since two versions of one core class cannot share a PHP process. Where they differ
     * (7.1's tree builder keeps what WordPress 7.0 drops inside <select>), the refusal
     * follows the runtime parser, and the two snapshots record both.
     */
    public static function htmlApiVersions(): array
    {
        return ['WordPress 7.0 (CI pin)' => ['7.0'], 'WordPress 7.1.2 (production)' => ['7.1.2']];
    }

    /** @dataProvider htmlApiVersions */
    public function testP16TheBailSetMatchesTheSnapshotOfEachVersion(string $version): void
    {
        $snapshot = __DIR__ . "/fixtures/content/p16-bail-set-wp-{$version}.json";
        $this->assertFileExists($snapshot);
        $expected = json_decode((string) file_get_contents($snapshot), true);
        $this->assertSame($version, $expected['wordpress']);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/content/bail-set-runner.php')
            . ' ' . escapeshellarg($version) . ' ' . escapeshellarg($snapshot);
        $measured = json_decode((string) shell_exec($cmd), true);
        $this->assertIsArray($measured, 'the runner printed no JSON for ' . $version);
        $this->assertGreaterThan(30, count($measured['cases']));
        foreach ($expected['cases'] as $n => $case) {
            $this->assertSame($case['bails'], $measured['cases'][$n]['bails'], "WordPress {$version} bail-set drift on: " . $case['input']);
        }
    }

    public function testTheTwoVersionsDifferExactlyWhereRecorded(): void
    {
        $a = json_decode((string) file_get_contents(__DIR__ . '/fixtures/content/p16-bail-set-wp-7.0.json'), true)['cases'];
        $b = json_decode((string) file_get_contents(__DIR__ . '/fixtures/content/p16-bail-set-wp-7.1.2.json'), true)['cases'];
        $differ = [];
        foreach ($a as $n => $case) {
            $this->assertSame($case['input'], $b[$n]['input']);
            if ($case['bails'] !== $b[$n]['bails']) {
                $differ[] = $case['input'];
            }
        }
        $this->assertSame([
            '<select><option>a</option><div>b</div></select>',
            '<select><img alt="" src="/x.png"></select>',
            '<select><button>b</button></select>',
        ], $differ);
    }

    /** T-1 for the deployed core: everything WordPress 7.1.2's `post` list admits, the table admits (P-17). */
    public function testT1TheTableAdmitsEverythingTheDeployedCoreAdmits(): void
    {
        $core712 = json_decode((string) file_get_contents(__DIR__ . '/fixtures/content/core-post-wp-7.1.2.json'), true)['tags'];
        $table   = pp_content_html_table();
        $globals = pp_content_html_global_attributes() + ['data-*' => true];
        foreach ($core712 as $tag => $attrs) {
            if (in_array($tag, self::E3_E4, true)) {
                continue;
            }
            $this->assertArrayHasKey($tag, $table, "7.1.2 admits <{$tag}>");
            foreach ($attrs as $attr => $_) {
                if (in_array($attr, self::E9, true)) {
                    continue;
                }
                $this->assertTrue(isset($table[$tag][$attr]) || isset($globals[$attr]), "7.1.2 admits {$attr} on <{$tag}>");
            }
        }
    }

    public function testE11TheProbeSnapshotIsLoadedAndRecordsTheWindowResult(): void
    {
        $raw = json_decode((string) file_get_contents(dirname(__DIR__) . '/lib/content-tables/dom-clobber-names.json'), true);
        $this->assertStringContainsString('Chromium', $raw['source']);
        $this->assertCount(count($raw['document_builtins']), pp_content_clobber_table()['document']);
        $probe = json_decode((string) file_get_contents(dirname(__DIR__) . '/lib/content-tables/dom-clobber-probe.json'), true);
        $this->assertSame([], $probe['window_id_clobbered'], 'an id shadows no window property: window names stay admitted');
        $this->assertGreaterThan(500, $probe['window_chain_names_tested']);
        foreach (['top', 'location', 'window', 'document', 'status', 'name'] as $n) {
            $this->assertFalse($probe['examples'][$n]['div_id_clobbers_window'], $n);
        }
        $this->assertTrue($probe['examples']['forms']['img_name_clobbers_document']);
    }
}
