<?php
/**
 * tests/PostApplyValidateTest.php — PHPUnit tests for post-apply rendered HTML validation.
 *
 * Covers: composition read-back, component render (empty/exception), DOM inspection
 * for <img>, background-image:url(), <a>, composition-level checks (count mismatch,
 * duplicate IDs), and batch media library verification.
 *
 * Uses temp component directories with fixture PHP files for rendering.
 */

use PHPUnit\Framework\TestCase;

class PostApplyValidateTest extends TestCase
{
    private string $tempDir;
    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temp directory structure mirroring theme layout.
        $this->tempDir = sys_get_temp_dir() . '/pp-validate-test-' . getmypid() . '-' . mt_rand();
        mkdir($this->tempDir . '/components', 0755, true);

        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;

        // Clear test store.
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts'] = [];

        // Create a test post.
        $this->postId = 42;
        $GLOBALS['_pp_test_store']['posts'][$this->postId] = [
            'post_type' => 'page',
            'post_title' => 'Test Page',
            'post_status' => 'publish',
        ];
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        unset($GLOBALS['_pp_test_template_dir']);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Creates a test component that outputs the given HTML when rendered.
     */
    private function createTestComponent(string $name, string $html): void
    {
        $dir = $this->tempDir . '/components/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $name . '.php', '<?php echo ' . var_export($html, true) . ';');
    }

    /**
     * Creates a test component that throws an exception when rendered.
     */
    private function createThrowingComponent(string $name, string $message): void
    {
        $dir = $this->tempDir . '/components/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/' . $name . '.php',
            '<?php throw new RuntimeException(' . var_export($message, true) . ');'
        );
    }

    /**
     * Creates a test component that outputs nothing.
     */
    private function createEmptyComponent(string $name): void
    {
        $dir = $this->tempDir . '/components/' . $name;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $name . '.php', '<?php // empty output');
    }

    private function setComposition(array $composition): void
    {
        // pp_get_composition() calls json_decode() on the raw meta value.
        update_post_meta($this->postId, '_pp_composition', json_encode($composition));
    }

    // ── Composition read-back ────────────────────────────────────────────

    public function testEmptyCompositionReturnsError(): void
    {
        // No composition set → empty.
        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals('composition_readback', $result['errors'][0]['check']);
    }

    public function testValidCompositionPasses(): void
    {
        $this->createTestComponent('hero', '<section class="hero"><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    // ── Empty component name ─────────────────────────────────────────────

    public function testEmptyComponentNameIsError(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => '', 'props' => [], 'style' => []],
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'empty_component_name');
        $this->assertCount(1, $errors);
    }

    // ── Component render: empty output ───────────────────────────────────

    public function testEmptyRenderOutputIsError(): void
    {
        $this->createEmptyComponent('blank');
        $this->setComposition([
            ['component' => 'blank', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'empty_render');
        $this->assertCount(1, $errors);
    }

    // ── Component render: throws exception ───────────────────────────────

    public function testRenderExceptionIsError(): void
    {
        $this->createThrowingComponent('broken', 'Component crashed');
        $this->setComposition([
            ['component' => 'broken', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'render_exception');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Component crashed', $errors[array_key_first($errors)]['message']);
    }

    // ── DOM inspection: <img> ────────────────────────────────────────────

    public function testEmptyImgSrcIsError(): void
    {
        $this->createTestComponent('gallery', '<div><img src="" alt="broken"></div>');
        $this->setComposition([
            ['component' => 'gallery', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'empty_img_src');
        $this->assertCount(1, $errors);
    }

    public function testValidLocalImgInMediaLibraryPasses(): void
    {
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/photo.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="photo"></div>');
        $this->setComposition([
            ['component' => 'card', 'props' => [], 'style' => []],
        ]);

        // Register the attachment in the test store.
        $attachmentId = 200;
        $GLOBALS['_pp_test_store']['posts'][$attachmentId] = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$attachmentId] = [
            '_wp_attached_file' => '2026/06/photo.jpg',
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidLocalImgWithMultibyteFilenamePasses(): void
    {
        // Regression (#128): DOMDocument::loadHTML() without an encoding
        // hint assumes ISO-8859-1 and mis-decodes UTF-8, so
        // "logotipo-diseño.png" would read back mangled and never match
        // the real (correctly UTF-8) _wp_attached_file value.
        $filename = 'logotipo-diseño.png';
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/' . $filename;
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="logo"></div>');
        $this->setComposition([
            ['component' => 'card', 'props' => [], 'style' => []],
        ]);

        $attachmentId = 201;
        $GLOBALS['_pp_test_store']['posts'][$attachmentId] = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$attachmentId] = [
            '_wp_attached_file' => '2026/06/' . $filename,
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function testMultibyteFilenamePassesEvenWithEmbeddedMetaCharsetOverride(): void
    {
        // Regression (#128 adversarial review): libxml's HTML encoding
        // sniffing prioritizes an in-content <meta charset="..."> over the
        // document-level XML encoding hint. If a component's rendered
        // output happens to carry one (e.g. embed's shortcode-rendered
        // content), it could silently defeat the fix above and
        // reintroduce the mis-decode. Confirm the validator strips it.
        $filename = 'logotipo-diseño.png';
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/' . $filename;
        $this->createTestComponent(
            'embed',
            '<meta charset="ISO-8859-1"><div><img src="' . $imgUrl . '" alt="logo"></div>'
        );
        $this->setComposition([
            ['component' => 'embed', 'props' => [], 'style' => []],
        ]);

        $attachmentId = 202;
        $GLOBALS['_pp_test_store']['posts'][$attachmentId] = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$attachmentId] = [
            '_wp_attached_file' => '2026/06/' . $filename,
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function testLocalImgNotInMediaLibraryIsError(): void
    {
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/missing.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="missing"></div>');
        $this->setComposition([
            ['component' => 'card', 'props' => [], 'style' => []],
        ]);

        // No attachment registered for this URL.

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('2026/06/missing.jpg', $errors[array_key_first($errors)]['message']);
    }

    public function testExternalImgUrlIsSkipped(): void
    {
        $this->createTestComponent('card', '<div><img src="https://cdn.example.org/photo.jpg" alt="external"></div>');
        $this->setComposition([
            ['component' => 'card', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    // ── #83: same-site media classification (any URL shape) ──────────────
    // Default test uploads baseurl is https://example.com/wp-content/uploads.

    private function registerAttachment(int $id, string $relative): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id] = ['_wp_attached_file' => $relative];
    }

    /** WP 7.0 regression: http URL vs https baseurl no longer misclassified as external. */
    public function testSchemeMismatchedSameSiteImgMissingIsError(): void
    {
        $imgUrl = 'http://example.com/wp-content/uploads/2026/06/nope.jpg'; // http, baseurl is https
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('2026/06/nope.jpg', $errors[array_key_first($errors)]['message']);
    }

    /** Scheme-mismatched URL that IS in the library still passes (relative resolves). */
    public function testSchemeMismatchedSameSiteImgInLibraryPasses(): void
    {
        $imgUrl = 'http://example.com/wp-content/uploads/2026/06/ok.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);
        $this->registerAttachment(210, '2026/06/ok.jpg');

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    /** Site-relative uploads path is same-site and gets verified. */
    public function testSiteRelativeSameSiteImgMissingIsError(): void
    {
        $imgUrl = '/wp-content/uploads/2026/06/rel.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('2026/06/rel.jpg', $errors[array_key_first($errors)]['message']);
    }

    /** Protocol-relative same-site URL is verified. */
    public function testProtocolRelativeSameSiteImgMissingIsError(): void
    {
        $imgUrl = '//example.com/wp-content/uploads/2026/06/proto.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
    }

    /** The background-image scan uses the same classifier (both scan sites fixed). */
    public function testSchemeMismatchedSameSiteBackgroundMissingIsError(): void
    {
        $bgUrl = 'http://example.com/wp-content/uploads/2026/06/bgnope.jpg';
        $this->createTestComponent('hero', '<section style="background-image:url(' . $bgUrl . ')"><h1>Hi</h1></section>');
        $this->setComposition([['component' => 'hero', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
    }

    /** A ?ver= cachebuster on a valid local image no longer reports it missing. */
    public function testSameSiteImgWithQueryStringResolves(): void
    {
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/qs.jpg?ver=5';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);
        $this->registerAttachment(211, '2026/06/qs.jpg');

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    /** Hostile near-match host (example.com.evil) is a different origin → skipped, not flagged. */
    public function testHostileExternalNearMatchIsSkipped(): void
    {
        $imgUrl = 'https://example.com.evil/wp-content/uploads/2026/06/evil.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    /** Same origin but OUTSIDE the uploads path (theme asset) is not a media lookup. */
    public function testSameOriginOutsideUploadsIsSkipped(): void
    {
        $imgUrl = 'https://example.com/wp-content/themes/pp/assets/logo.png';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Regression (#83 codex adversarial): the classifier boundary-checks the
     * decoded path but returns the encoded one, so deriving the relative path
     * by byte-stripping the encoded form corrupted a percent-encoded uploads
     * segment. A `%75ploads` (= "uploads") URL for a real file must still
     * resolve to the stored relative path and PASS, not false-flag as missing.
     */
    public function testEncodedUploadsSegmentResolvesToStoredPath(): void
    {
        $imgUrl = 'https://example.com/wp-content/%75ploads/2026/06/enc.jpg';
        $this->createTestComponent('card', '<div><img src="' . $imgUrl . '" alt="x"></div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);
        $this->registerAttachment(212, '2026/06/enc.jpg');

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    // ── DOM inspection: background-image:url() ───────────────────────────

    public function testEmptyBackgroundImageUrlIsError(): void
    {
        $this->createTestComponent('hero', '<section style="background-image:url()"><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'empty_background_image');
        $this->assertCount(1, $errors);
    }

    public function testValidLocalBackgroundImagePasses(): void
    {
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/bg.jpg';
        $this->createTestComponent('hero', '<section style="background-image:url(' . $imgUrl . ')"><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $attachmentId = 201;
        $GLOBALS['_pp_test_store']['posts'][$attachmentId] = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$attachmentId] = [
            '_wp_attached_file' => '2026/06/bg.jpg',
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
    }

    public function testMissingLocalBackgroundImageIsError(): void
    {
        $imgUrl = 'https://example.com/wp-content/uploads/2026/06/gone.jpg';
        $this->createTestComponent('hero', '<section style="background-image:url(' . $imgUrl . ')"><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
    }

    // ── DOM inspection: <a> href ─────────────────────────────────────────

    public function testEmptyLinkHrefIsWarning(): void
    {
        $this->createTestComponent('cta', '<div><a href="">Click</a></div>');
        $this->setComposition([
            ['component' => 'cta', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']); // Warnings don't block.
        $this->assertCount(1, $result['warnings']);
        $this->assertEquals('empty_link_href', $result['warnings'][0]['check']);
        $this->assertStringContainsString('empty href', $result['warnings'][0]['message']);
    }

    public function testBareHashHrefIsWarning(): void
    {
        $this->createTestComponent('nav', '<div><a href="#">Link</a></div>');
        $this->setComposition([
            ['component' => 'nav', 'props' => [], 'style' => []],
        ]);
        // Make the referenced nav location ready so the only warning under test is
        // the bare-# href (otherwise nav_readiness adds a "no menu assigned" warning).
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 1];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [1 => [['id' => 1]]];

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('bare # href', $result['warnings'][0]['message']);
    }

    public function testValidLinkHrefPasses(): void
    {
        $this->createTestComponent('cta', '<div><a href="https://example.com">Click</a></div>');
        $this->setComposition([
            ['component' => 'cta', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['warnings']);
    }

    // ── Composition-level: count mismatch ────────────────────────────────

    public function testComponentCountMatchPasses(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->createTestComponent('footer', '<footer>Footer</footer>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
            ['component' => 'footer', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
    }

    public function testComponentCountMismatchIsError(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->createEmptyComponent('blank');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
            ['component' => 'blank', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'component_count_mismatch');
        $this->assertCount(1, $errors);
    }

    // ── Composition-level: duplicate component IDs ───────────────────────

    public function testNoDuplicateIdsPasses(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->createTestComponent('cta', '<div>CTA</div>');
        $this->setComposition([
            ['component' => 'hero', 'props' => ['id' => 'hero-1'], 'style' => []],
            ['component' => 'cta', 'props' => ['id' => 'cta-1'], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['warnings']);
    }

    public function testDuplicateComponentIdsIsWarning(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->createTestComponent('cta', '<div>CTA</div>');
        $this->setComposition([
            ['component' => 'hero', 'props' => ['id' => 'same-id'], 'style' => []],
            ['component' => 'cta', 'props' => ['id' => 'same-id'], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertTrue($result['ok']); // Warnings don't block.
        $warnings = array_filter($result['warnings'], fn($w) => $w['check'] === 'duplicate_component_id');
        $this->assertCount(1, $warnings);
    }

    // ── Batch media query: multiple local URLs ───────────────────────────

    public function testBatchMediaQueryMixedValidAndInvalid(): void
    {
        $validUrl = 'https://example.com/wp-content/uploads/2026/06/valid.jpg';
        $missingUrl = 'https://example.com/wp-content/uploads/2026/06/missing.jpg';

        $this->createTestComponent('gallery', '<div><img src="' . $validUrl . '" alt="valid"><img src="' . $missingUrl . '" alt="missing"></div>');
        $this->setComposition([
            ['component' => 'gallery', 'props' => [], 'style' => []],
        ]);

        // Only register the valid one.
        $attachmentId = 202;
        $GLOBALS['_pp_test_store']['posts'][$attachmentId] = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$attachmentId] = [
            '_wp_attached_file' => '2026/06/valid.jpg',
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertFalse($result['ok']);
        $errors = array_filter($result['errors'], fn($e) => $e['check'] === 'missing_local_media');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('missing.jpg', $errors[array_key_first($errors)]['message']);
    }

    // ── Result shape ─────────────────────────────────────────────────────

    public function testResultShapeAlwaysComplete(): void
    {
        $this->createTestComponent('hero', '<section><h1>Hello</h1></section>');
        $this->setComposition([
            ['component' => 'hero', 'props' => [], 'style' => []],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsArray($result['warnings']);
        $this->assertIsArray($result['errors']);
    }
}
