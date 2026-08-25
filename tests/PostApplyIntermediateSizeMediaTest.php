<?php
/**
 * tests/PostApplyIntermediateSizeMediaTest.php — #686.
 *
 * `wp pp validate page` re-renders a page and flags images that point at media
 * the library does not have (`missing_local_media`). Only the ORIGINAL upload is
 * an attachment row; every WordPress-generated intermediate size lives as an
 * entry in that row's metadata. The theme's own responsive-image path renders a
 * generated size as the `src` whenever `image_id` resolves, so the check used to
 * fail on exactly the pages that used images correctly.
 *
 * These tests pin BOTH directions of the resolution:
 *
 *   rendered path                                      verdict
 *   ─────────────────────────────────────────────────  ────────────────────────
 *   2026/07/care-t-860x1024.png  (in the attachment's
 *                                 sizes metadata)       clean
 *   2026/07/care-t-999x999.png   (real base, size the
 *                                 site never generated) missing_local_media
 *   2026/07/ghost-300x200.png    (no such base)         missing_local_media
 *
 * The middle row is the whole point: nothing is whitelisted by filename shape.
 */

use PHPUnit\Framework\TestCase;

class PostApplyIntermediateSizeMediaTest extends TestCase
{
    private string $tempDir;
    private int $postId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/pp-intermediate-media-' . getmypid() . '-' . mt_rand();
        mkdir($this->tempDir . '/components', 0755, true);

        $GLOBALS['_pp_test_store'] = [
            'post_meta'            => [],
            'posts'                => [],
            'options'              => ['siteurl' => 'https://example.com'],
            'next_id'              => 100,
            'attachment_urls'      => [],
            'attachment_is_image'  => [],
            'attachment_metadata'  => [],
            // Seed site chrome READY so the only findings a test sees are its own.
            'registered_nav_menus' => ['primary' => 'Primary', 'footer' => 'Footer'],
            'nav_menu_locations'   => ['primary' => 1, 'footer' => 2],
            'nav_menu_items'       => [1 => [['id' => 1]], 2 => [['id' => 2]]],
        ];

        $this->postId = 42;
        $GLOBALS['_pp_test_store']['posts'][$this->postId] = [
            'post_type'   => 'page',
            'post_title'  => 'Test Page',
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
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Fixture component that echoes fixed HTML. Setting the template dir here
     * (not in setUp) keeps the real components/ directory reachable for the
     * production-shape test at the bottom of this file.
     */
    private function createTestComponent(string $name, string $html): void
    {
        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;
        $dir = $this->tempDir . '/components/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $name . '.php', '<?php echo ' . var_export($html, true) . ';');
    }

    private function setComposition(array $composition): void
    {
        update_post_meta($this->postId, '_pp_composition', json_encode($composition));
    }

    /**
     * Registers an attachment row the way WordPress stores one: `_wp_attached_file`
     * names the file the library owns, and every generated size is metadata on
     * that same row (basename only, same directory as the attached file).
     *
     * @param array<string,array{file:string,width?:int,height?:int}> $sizes
     */
    private function registerAttachment(int $id, string $attachedFile, array $sizes = []): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id] = ['_wp_attached_file' => $attachedFile];
        $GLOBALS['_pp_test_store']['attachment_metadata'][$id] = [
            'file'   => $attachedFile,
            'width'  => 1424,
            'height' => 1696,
            'sizes'  => $sizes,
        ];
    }

    /** One page, one card component, one <img> per supplied src. */
    private function imgComposition(string ...$srcs): void
    {
        $imgs = '';
        foreach ($srcs as $src) {
            $imgs .= '<img src="' . $src . '" alt="x">';
        }
        $this->createTestComponent('card', '<div>' . $imgs . '</div>');
        $this->setComposition([['component' => 'card', 'props' => [], 'style' => []]]);
    }

    /** @return array<int,array> the missing_local_media errors only */
    private function mediaErrors(array $result): array
    {
        return array_values(array_filter(
            $result['errors'],
            fn($e) => $e['check'] === 'missing_local_media'
        ));
    }

    // ── Direction 1: a real generated size validates clean ────────────────

    public function testGeneratedIntermediateSizeInImgSrcResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'thumbnail' => ['file' => 'care-t-150x150.png', 'width' => 150, 'height' => 150],
            'medium'    => ['file' => 'care-t-252x300.png', 'width' => 252, 'height' => 300],
            'large'     => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** Both scan sites share the resolution, not just <img src>. */
    public function testGeneratedIntermediateSizeInBackgroundImageResolvesClean(): void
    {
        $bg = 'https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png';
        $this->createTestComponent('hero', '<section style="background-image:url(' . $bg . ')"><h1>Hi</h1></section>');
        $this->setComposition([['component' => 'hero', 'props' => [], 'style' => []]]);
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /**
     * WordPress promotes a `-scaled` copy to full size for an upload above the
     * big-image threshold, so the attachment row names `big-scaled.jpg` while
     * every generated size is named from the unscaled stem.
     */
    public function testScaledAttachmentIntermediateResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/big-1024x768.jpg');
        $this->registerAttachment(71, '2026/07/big-scaled.jpg', [
            'large' => ['file' => 'big-1024x768.jpg', 'width' => 1024, 'height' => 768],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** #128 stays fixed: a multibyte filename resolves through the size path too. */
    public function testNonAsciiIntermediateSizeResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/06/logotipo-diseño-300x200.png');
        $this->registerAttachment(72, '2026/06/logotipo-diseño.png', [
            'medium' => ['file' => 'logotipo-diseño-300x200.png', 'width' => 300, 'height' => 200],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** The same file referenced with a percent-encoded name resolves identically. */
    public function testPercentEncodedIntermediateSizeResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/06/logotipo-dise%C3%B1o-300x200.png');
        $this->registerAttachment(73, '2026/06/logotipo-diseño.png', [
            'medium' => ['file' => 'logotipo-diseño-300x200.png', 'width' => 300, 'height' => 200],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    // ── Direction 2: a genuinely missing file still fails ─────────────────

    /**
     * The load-bearing test. The base upload is real; the size in the URL is one
     * the site never generated. Nothing about the `-999x999` shape may excuse it.
     */
    public function testFabricatedIntermediateOfRealBaseIsStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-999x999.png');
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $errors = $this->mediaErrors($result);
        $this->assertCount(1, $errors);
        $this->assertSame('2026/07/care-t-999x999.png', $errors[0]['detail']);
        $this->assertFalse($result['ok']);
    }

    public function testIntermediateOfNonexistentBaseIsStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/ghost-300x200.png');
        // A real, unrelated attachment exists — the lookup must not drift to it.
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'medium' => ['file' => 'care-t-252x300.png', 'width' => 252, 'height' => 300],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $errors = $this->mediaErrors($result);
        $this->assertCount(1, $errors);
        $this->assertSame('2026/07/ghost-300x200.png', $errors[0]['detail']);
        $this->assertFalse($result['ok']);
    }

    public function testScaledAttachmentFabricatedIntermediateIsStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/big-4000x3000.jpg');
        $this->registerAttachment(71, '2026/07/big-scaled.jpg', [
            'large' => ['file' => 'big-1024x768.jpg', 'width' => 1024, 'height' => 768],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
        $this->assertFalse($result['ok']);
    }

    /** An attachment carrying no `sizes` metadata owns no derivatives. */
    public function testAttachmentWithoutSizesMetadataGivesNoFreePass(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(70, '2026/07/care-t.png', []);

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
        $this->assertFalse($result['ok']);
    }

    /**
     * A file genuinely UPLOADED under a size-shaped name is its own attachment.
     * The exact `_wp_attached_file` row wins over the suffix reading: a rival
     * `photo.png` attachment exists here and does NOT list `photo-300x200.png`
     * among its sizes, which is the only configuration where the two readings
     * disagree — the suffix reading alone would call this image missing.
     */
    public function testUploadedFileNamedLikeASizeResolvesByExactRow(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/photo-300x200.png');
        $this->registerAttachment(74, '2026/07/photo-300x200.png', []);
        $this->registerAttachment(75, '2026/07/photo.png', [
            'large' => ['file' => 'photo-1024x768.png', 'width' => 1024, 'height' => 768],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** An EXIF-rotated upload replaces its attached file the same way `-scaled` does. */
    public function testRotatedAttachmentIntermediateResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/turned-1024x768.jpg');
        $this->registerAttachment(76, '2026/07/turned-rotated.jpg', [
            'large' => ['file' => 'turned-1024x768.jpg', 'width' => 1024, 'height' => 768],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /**
     * Flat uploads (`uploads_use_yearmonth_folders` off): `_wp_attached_file` is
     * a bare filename and so is the rendered path. The suffix must still be read
     * off the last segment when there is no directory at all.
     */
    public function testFlatUploadsIntermediateResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/care-t-860x1024.png');
        $this->registerAttachment(77, 'care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /**
     * One page, three images through the SAME batch lookup: a real size, a
     * fabricated size of that same base, and a plain original. Exactly one
     * finding, naming the fabricated path — the per-path candidate map must not
     * let one image's resolution answer for another's.
     */
    public function testMixedImagesOnOnePageResolveIndependently(): void
    {
        $this->imgComposition(
            'https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png',
            'https://example.com/wp-content/uploads/2026/07/care-t-999x999.png',
            'https://example.com/wp-content/uploads/2026/07/plain.png'
        );
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);
        $this->registerAttachment(78, '2026/07/plain.png', []);

        $result = pp_post_apply_validate($this->postId);

        $errors = $this->mediaErrors($result);
        $this->assertCount(1, $errors);
        $this->assertSame('2026/07/care-t-999x999.png', $errors[0]['detail']);
    }

    // ── Metadata shapes WordPress and its filters can actually produce ────

    /** No metadata at all (non-image attachment, failed generation) → missing. */
    public function testAttachmentWithFalseMetadataIsStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(70, '2026/07/care-t.png', []);
        $GLOBALS['_pp_test_store']['attachment_metadata'][70] = false;

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
    }

    /** Malformed `sizes` entries are survived, not trusted, and not fatal. */
    public function testMalformedSizesEntriesAreSurvivedAndStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(70, '2026/07/care-t.png', []);
        $GLOBALS['_pp_test_store']['attachment_metadata'][70] = [
            'file'  => '2026/07/care-t.png',
            'sizes' => [
                'large'     => 'care-t-860x1024.png', // a string, not an entry
                'medium'    => ['width' => 252],      // no file key
                'thumbnail' => ['file' => 12345],     // non-string file
            ],
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
    }

    /** A `sizes` key that is not an array at all is survived too. */
    public function testNonArraySizesMetadataIsSurvivedAndStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(70, '2026/07/care-t.png', []);
        $GLOBALS['_pp_test_store']['attachment_metadata'][70] = [
            'file'  => '2026/07/care-t.png',
            'sizes' => 'care-t-860x1024.png',
        ];

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
    }

    /**
     * ACCEPTED LIMIT, pinned so it cannot drift silently (#762): a generated
     * size whose extension differs from the attachment's own file — a HEIC/HEIF
     * upload below the big-image threshold, whose sizes WordPress writes as JPEG
     * since 6.7, or any site filtering `image_editor_output_format` — is not
     * resolved, because the base candidate keeps the size's own extension. This
     * test asserts today's behaviour, not a desired one; #762 owns changing it.
     */
    public function testConvertedFormatSizeIsNotResolvedYet(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/photo-1024x768.jpg');
        $this->registerAttachment(79, '2026/07/photo.heic', [
            'large' => ['file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
    }

    /**
     * Two attachments can share one `_wp_attached_file` (a re-import, a restored
     * backup) while only one of them carries the size metadata. The answer must
     * not depend on which row the query hands back first.
     */
    public function testDuplicateAttachmentRowsResolveRegardlessOfOrder(): void
    {
        // The size-BEARING row is registered FIRST and the size-less row second,
        // which is the discriminating order: a "last row wins" implementation
        // keeps 81, finds no size, and reports the image missing. The reverse
        // order passes either way, so it would prove nothing.
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(80, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);
        $this->registerAttachment(81, '2026/07/care-t.png', []);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** …and the other order, so neither a first-wins nor a last-wins map passes. */
    public function testDuplicateAttachmentRowsResolveInTheOppositeOrderToo(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(80, '2026/07/care-t.png', []);
        $this->registerAttachment(81, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /**
     * More duplicate rows than lookup keys. The batch query asks for four keys
     * (the path plus its three base candidates) but must not cap the RESULT at
     * four rows: here the fifth row is the only one carrying the size metadata,
     * so a per-key limit truncates it away and re-reports #686 on any install
     * with re-imported media.
     */
    public function testDuplicateRowsBeyondTheKeyCountAreStillConsidered(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        foreach ([90, 91, 92, 93] as $id) {
            $this->registerAttachment($id, '2026/07/care-t.png', []);
        }
        $this->registerAttachment(94, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    /** …and neither row owning the size is still a miss. */
    public function testDuplicateAttachmentRowsWithoutTheSizeAreStillMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png');
        $this->registerAttachment(80, '2026/07/care-t.png', []);
        $this->registerAttachment(81, '2026/07/care-t.png', [
            'medium' => ['file' => 'care-t-252x300.png', 'width' => 252, 'height' => 300],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertCount(1, $this->mediaErrors($result));
        $this->assertFalse($result['ok']);
    }

    // ── Unchanged behaviour (regression guards) ───────────────────────────

    public function testOriginalUploadUrlStillResolvesClean(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/07/care-t.png');
        $this->registerAttachment(70, '2026/07/care-t.png', [
            'large' => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    public function testPlainMissingFileStillReportsMissing(): void
    {
        $this->imgComposition('https://example.com/wp-content/uploads/2026/06/missing.jpg');

        $result = pp_post_apply_validate($this->postId);

        $errors = $this->mediaErrors($result);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('2026/06/missing.jpg', $errors[0]['message']);
    }

    /** A different host stays external and is never flagged, size suffix or not. */
    public function testExternalSizedUrlIsStillSkipped(): void
    {
        $this->imgComposition('https://cdn.example.org/2026/07/care-t-860x1024.png');

        $result = pp_post_apply_validate($this->postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok']);
    }

    // ── Base-candidate derivation ─────────────────────────────────────────

    public function testBasePathsDerivesOriginalScaledAndRotatedCandidates(): void
    {
        $this->assertSame(
            ['2026/07/care-t.png', '2026/07/care-t-scaled.png', '2026/07/care-t-rotated.png'],
            _pp_intermediate_size_base_paths('2026/07/care-t-860x1024.png')
        );
    }

    /** Flat uploads: no directory to carry, candidates are bare filenames. */
    public function testBasePathsWithoutADirectory(): void
    {
        $this->assertSame(
            ['care-t.png', 'care-t-scaled.png', 'care-t-rotated.png'],
            _pp_intermediate_size_base_paths('care-t-860x1024.png')
        );
    }

    public function testBasePathsEmptyWithoutASizeSuffix(): void
    {
        $this->assertSame([], _pp_intermediate_size_base_paths('2026/07/care-t.png'));
        $this->assertSame([], _pp_intermediate_size_base_paths('care-t.png'));
    }

    /** A directory that happens to be named like a size is not a size suffix. */
    public function testBasePathsIgnoresSizeShapedDirectory(): void
    {
        $this->assertSame([], _pp_intermediate_size_base_paths('2026/07/300x200/photo.jpg'));
    }

    // ── Production shape, through the real write path ─────────────────────

    /**
     * The #686 repro end to end: a real `hero` band authored through
     * pp_update_composition() with a resolvable `image_id`, rendered by the real
     * component (pp_render_responsive_image() → wp_get_attachment_image()), whose
     * src is therefore the generated `large` size rather than the upload the
     * composition stores. This is the exact page shape that failed on the
     * reporter's production install; it must validate clean.
     */
    public function testRealHeroWithImageIdValidatesCleanThroughTheWritePath(): void
    {
        $original = 'https://example.com/wp-content/uploads/2026/07/care-t.png';

        $this->registerAttachment(70, '2026/07/care-t.png', [
            'thumbnail' => ['file' => 'care-t-150x150.png', 'width' => 150, 'height' => 150],
            'medium'    => ['file' => 'care-t-252x300.png', 'width' => 252, 'height' => 300],
            'large'     => ['file' => 'care-t-860x1024.png', 'width' => 860, 'height' => 1024],
        ]);
        $GLOBALS['_pp_test_store']['attachment_urls'][70]     = $original;
        $GLOBALS['_pp_test_store']['attachment_is_image'][70] = true;

        $postId = pp_create_page('Inicio', 'publish');
        $written = pp_update_composition($postId, [
            [
                'component' => 'hero',
                'props'     => [
                    'id'        => 'inicio-hero',
                    'title'     => 'Cuidado que acompaña',
                    'layout'    => 'split',
                    'image_url' => $original,
                    'image_id'  => 70,
                    'image_alt' => 'Equipo de cuidado',
                ],
            ],
        ]);
        $this->assertNotInstanceOf(WP_Error::class, $written, 'The hero band must author cleanly.');

        // The rendered SRC really is a generated size, not the stored image_url.
        // Asserting the attribute, not the bare filename: the filename also
        // appears in srcset, so a substring check would pass even if the src
        // regressed to the original and unpinned the whole #686 repro.
        ob_start();
        pp_get_component('hero', pp_get_composition($postId)[0]['props']);
        $html = ob_get_clean();
        $this->assertStringContainsString(
            'src="https://example.com/wp-content/uploads/2026/07/care-t-860x1024.png"',
            $html
        );
        $this->assertStringNotContainsString('src="' . $original . '"', $html);

        $result = pp_post_apply_validate($postId);

        $this->assertSame([], $this->mediaErrors($result));
        $this->assertTrue($result['ok'], 'A correct image_id page must not fail rendered validation.');
    }
}
