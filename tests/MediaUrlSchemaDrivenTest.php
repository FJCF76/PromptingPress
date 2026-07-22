<?php
/**
 * tests/MediaUrlSchemaDrivenTest.php — #154 authoring-path proof.
 *
 * Section 14.1 (authoring-path mandate): exercises the schema-driven image-URL
 * allowlist through the REAL validation surface (pp_execute_action), not raw
 * _pp_composition meta writes.
 *
 * The proof: register a synthetic component whose schema declares a NEW image
 * prop NAME the old hardcoded ['image_url','background_image'] list never
 * contained (`poster_url`, with "format":"image_url"). Author a component write
 * carrying that prop pointing at a non-image attachment. It must be rejected by
 * the media gate — which is only possible if _pp_extract_urls_from_params()
 * picked `poster_url` up purely from the schema annotation, with NO edit to
 * lib/actions.php. That is exactly the regression #154 makes impossible.
 */

use PHPUnit\Framework\TestCase;

class MediaUrlSchemaDrivenTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'          => [],
            'posts'              => [],
            'options'            => ['siteurl' => 'https://example.com'],
            'next_id'            => 100,
            'attachment_urls'    => [],
            'attachment_is_image' => [],
        ];

        $this->tempDir = sys_get_temp_dir() . '/pp-media-schema-' . uniqid();
        mkdir($this->tempDir . '/components', 0755, true);

        // Mirror the real components so a normal hero page is composable here.
        $realComponents = dirname(__DIR__) . '/components';
        foreach (scandir($realComponents) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $realComponents . '/' . $name;
            if (!is_dir($src)) {
                continue;
            }
            $dst = $this->tempDir . '/components/' . $name;
            mkdir($dst, 0755, true);
            foreach (["$name.php", 'schema.json'] as $file) {
                if (file_exists("$src/$file")) {
                    copy("$src/$file", "$dst/$file");
                }
            }
        }

        // Synthetic component with a NEW image prop name the old list never had.
        $mt = $this->tempDir . '/components/mediatest';
        mkdir($mt, 0755, true);
        file_put_contents($mt . '/mediatest.php', "<?php // test-only stub renderer\n");
        file_put_contents($mt . '/schema.json', json_encode([
            'component'   => 'mediatest',
            'description' => 'Test-only component with a novel image prop.',
            'props'       => [
                'id'         => ['type' => 'string', 'required' => false, 'default' => ''],
                'poster_url' => ['type' => 'string', 'required' => false, 'format' => 'image_url', 'description' => 'A poster image URL.'],
            ],
        ], JSON_PRETTY_PRINT));

        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        unset($GLOBALS['_pp_test_template_dir']);
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Sanity: the schema annotation alone puts the novel prop in the derived set.
     */
    public function testNovelImagePropEntersDerivedSetFromSchemaAlone(): void
    {
        $this->assertContains('poster_url', _pp_schema_image_url_props(),
            'poster_url should be media-validated purely because its schema declares format:image_url.');
    }

    /**
     * The authoring-path proof (Section 14.1). A component write through
     * pp_execute_action() carrying the novel poster_url prop, pointing at a
     * non-image attachment, is rejected by the media gate — proving the
     * schema-driven extraction covers a prop the old hardcoded list never named.
     */
    public function testAuthoringWithNovelImagePropRejectsNonImageViaRealSurface(): void
    {
        $id = pp_create_page('Schema-driven media test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        // A real uploads attachment that is NOT an image.
        $GLOBALS['_pp_test_store']['attachment_urls'][77] = 'https://example.com/wp-content/uploads/doc.pdf';
        $GLOBALS['_pp_test_store']['attachment_is_image'][77] = false;

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['poster_url' => 'https://example.com/wp-content/uploads/doc.pdf'],
        ]);

        $this->assertFalse($result['ok'], 'A novel schema-declared image prop must be media-validated.');
        $this->assertStringContainsString('does not point to an image', $result['error']);

        // Nothing was written.
        $comp = pp_get_composition($id);
        $this->assertArrayNotHasKey('poster_url', $comp[0]['props']);
    }

    /**
     * Control: the same novel prop pointing at a real IMAGE attachment passes the
     * media gate — the rejection above is the image-type check, not a blanket ban.
     */
    public function testAuthoringWithNovelImagePropAcceptsRealImageViaRealSurface(): void
    {
        $id = pp_create_page('Schema-driven media test ok', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $GLOBALS['_pp_test_store']['attachment_urls'][78] = 'https://example.com/wp-content/uploads/pic.jpg';
        $GLOBALS['_pp_test_store']['attachment_is_image'][78] = true;

        $error = _pp_validate_media_urls_in_params([
            'props' => ['poster_url' => 'https://example.com/wp-content/uploads/pic.jpg'],
        ]);
        $this->assertTrue($error, 'A novel schema-declared image prop pointing at a real image must pass the media gate.');
    }

    /**
     * Fail-closed floor (#154, orchestrator decision 2026-07-22). If the component
     * registry is transiently empty (broken/missing components/), the schema walk
     * yields nothing — but the media gate must NOT fall open. The two historically
     * covered prop names are still extracted so coverage can never regress below
     * the pre-#154 baseline. Also proves _pp_schema_image_url_props() itself stays
     * purely schema-derived (returns [] here), keeping the drift-catcher pin honest.
     */
    public function testFailClosedFloorHoldsWhenRegistryIsEmpty(): void
    {
        $emptyDir = sys_get_temp_dir() . '/pp-empty-registry-' . uniqid();
        mkdir($emptyDir, 0755, true); // no components/ subdir → empty registry
        $GLOBALS['_pp_test_template_dir'] = $emptyDir;
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        // Purely schema-derived helper sees no components.
        $this->assertSame([], _pp_schema_image_url_props());

        // The consumer's floor still extracts the canonical props — gate stays closed.
        $urls = _pp_extract_urls_from_params([
            'props' => ['image_url' => 'https://x/a.jpg', 'background_image' => 'https://x/b.jpg'],
        ]);
        sort($urls);
        $this->assertSame(['https://x/a.jpg', 'https://x/b.jpg'], $urls);

        rmdir($emptyDir);
    }
}
