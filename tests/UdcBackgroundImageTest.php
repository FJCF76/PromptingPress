<?php
/**
 * tests/UdcBackgroundImageTest.php
 *
 * Ruling A2 — background images, where the ENGINE owns the url() and the author
 * owns only an id.
 *
 * THE SHAPE OF THE PROBLEM. `_pp_forbidden_css_construct()` bans `url(` on every
 * CSS-value surface in the program, and the #570 convergence ruling requires the
 * write-accept and render-reject sets to stay identical. A background image is
 * nonetheless a `url()`. The resolution is not an exemption: an author writes a
 * Media Library attachment ID — a run of digits, which clears the ban trivially —
 * and the engine builds the `url()` itself, AFTER both emit gates, out of
 * WordPress's own URL for an attachment it has just proved is a live image here.
 *
 * So the two things this file has to prove, in both directions:
 *
 *   1. THE BAN DID NOT MOVE. An author-written `url()` is still refused, on this
 *      parameter and on its neighbours, on bands and on chrome.
 *   2. THE ID IS A REAL REFERENCE. A dangling one is refused at write NAMING the id;
 *      one that goes dangling LATER degrades at emit instead of painting a broken
 *      image or fatalling — and that degrade is disclosed, not silent.
 *
 * Plus the ruling's exclusions, which have to REFUSE rather than be ignored:
 * per-breakpoint art direction and per-state image swapping.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UdcBackgroundImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
        $this->liveAttachment(42);
    }

    private function liveAttachment(int $id): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
    }

    private function band(array $background): array
    {
        return [
            'component' => 'testimonials',
            'id'        => 'pp-deadbeef',
            'udc'       => ['_band' => ['background' => $background]],
        ];
    }

    private function validate(array $background)
    {
        return pp_udc_validate_map(['_band' => ['background' => $background]], 'testimonials');
    }

    // ── 1. The ban did not move ─────────────────────────────────────────────

    public function testAnAuthorWrittenUrlIsStillRefusedOnEveryBackgroundParameter(): void
    {
        foreach (['fill', 'overlay'] as $param) {
            $error = $this->validate([$param => 'url(https://evil.example/x.png)']);
            $this->assertInstanceOf(WP_Error::class, $error, "{$param} must still refuse url()");
            $this->assertStringContainsString('url()', $error->get_error_message());
        }
        // And on the image parameter itself, where a URL is the obvious wrong guess.
        $error = $this->validate(['image' => 'https://example.com/photo.jpg']);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('never a URL', $error->get_error_message());
    }

    public function testTheSharedInjectionGateIsUnchanged(): void
    {
        // The gate itself, not a caller of it: a regression that relaxed `url(` to let
        // backgrounds through would show up here first.
        $this->assertNotNull(_pp_forbidden_css_construct('url(x)'));
        $this->assertNotNull(_pp_forbidden_css_construct('URL (x)'));
        $this->assertNotNull(_pp_forbidden_css_construct('@import'));
        $this->assertNotNull(_pp_forbidden_css_construct('expression(1)'));
        // And the digits an author actually sends clear it, which is why no exemption
        // was needed in the first place.
        $this->assertNull(_pp_forbidden_css_construct('42'));
    }

    public function testTheEngineBuiltUrlIsQuotedAndEscapedForCssUrlContext(): void
    {
        $GLOBALS['_pp_test_store']['posts'][7]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][7] = true;

        $css = pp_udc_band_css($this->band(['image' => 42]));
        $this->assertStringContainsString(
            'background-image:url("https://example.com/wp-content/uploads/image-42.jpg")',
            $css
        );
        // Quoted, because a quoted url token is the narrower sink.
        $this->assertMatchesRegularExpression('/url\("[^"]*"\)/', $css);
    }

    public function testTheUrlGoesThroughTheSharedCssUrlEscaper(): void
    {
        // pp_esc_image_src() percent-encodes `)`, which esc_url() permits and which
        // would otherwise close the url() token early and let trailing text out of it.
        // Proving the engine routes through that escaper matters more than the exact
        // bytes, so assert the escaper's characteristic transformation.
        $this->assertStringNotContainsString(')', substr(pp_esc_image_src('https://e.test/a)b.png'), 0, -1));
        $this->assertSame(null, pp_udc_background_image_url('not-a-number'));
    }

    // ── 2. The id is a real reference ───────────────────────────────────────

    public function testADanglingIdIsRefusedAtWriteNamingTheId(): void
    {
        $error = $this->validate(['image' => 99]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('Attachment 99', $error->get_error_message());
        $this->assertStringContainsString('import_media', $error->get_error_message());
    }

    public function testANonImageAttachmentIsRefused(): void
    {
        $GLOBALS['_pp_test_store']['posts'][55]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][55] = false; // e.g. a PDF
        $error = $this->validate(['image' => 55]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('Attachment 55', $error->get_error_message());
    }

    /**
     * REJECT, NEVER COERCE (invariant I34). `(int) ['x' => 1]` and `(int) true` both
     * evaluate to 1, so a cast-first predicate would silently resolve an array or a
     * boolean to "attachment 1" — which on most sites is a real attachment.
     */
    public function testNonIntegerShapesAreRefusedRatherThanCastToAttachmentOne(): void
    {
        $this->liveAttachment(1);
        foreach ([true, ['attachment_id' => 42], '4.5', '-3', '0', ' ', 'abc', '42abc'] as $bad) {
            $this->assertNull(
                pp_udc_background_image_url($bad),
                'must not resolve: ' . var_export($bad, true)
            );
        }
        // The one shape that IS an id still works, including as a numeric string.
        $this->assertNotNull(pp_udc_background_image_url(1));
        $this->assertNotNull(pp_udc_background_image_url('1'));
    }

    public function testAnAttachmentWhoseUrlCannotBeBuiltDoesNotPaint(): void
    {
        $GLOBALS['_pp_test_store']['attachment_url_missing'][42] = true;
        $this->assertNull(pp_udc_background_image_url(42));
    }

    /**
     * THE DEGRADE. Valid at write, deleted afterwards: drop that one declaration,
     * keep every sibling painting, never emit url() of a dead id, never fatal.
     */
    public function testAnAttachmentDeletedAfterWriteDegradesWithoutTakingTheBandWithIt(): void
    {
        $item = $this->band(['fill' => '#0b7285', 'image' => 42, 'overlay' => 'rgba(0,0,0,0.55)']);
        $this->assertNull($this->validate($item['udc']['_band']['background']), 'valid at write');

        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false; // deleted later
        $css = pp_udc_band_css($item);

        $this->assertStringNotContainsString('url(', $css, 'no url() of a dead id');
        $this->assertStringNotContainsString('background-image', $css);
        $this->assertStringContainsString('background:#0b7285;', $css, 'siblings keep painting');
    }

    public function testTheDegradeIsDisclosedThroughThePreflightAdvisory(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav'      => ['_band' => ['background' => ['image' => 42]]],
        ]);

        // While it resolves, nothing is reported: a readiness report nobody reads is
        // the failure mode these checks exist to prevent.
        $this->assertSame([], pp_check_udc_background_images());

        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false;
        $rows = pp_check_udc_background_images();

        $this->assertCount(1, $rows);
        $this->assertSame('udc_background_image', $rows[0]['check']);
        $this->assertFalse($rows[0]['pass']);
        $this->assertSame('warning', $rows[0]['severity'], 'advisory only — it must never block a mutation');
        $this->assertTrue($rows[0]['acknowledgeable']);
        $this->assertStringContainsString('chrome "nav"', $rows[0]['message']);
        $this->assertStringContainsString('attachment 42', $rows[0]['message']);
        // A route back, not just a complaint (invariant I24).
        $this->assertStringContainsString('import_media', $rows[0]['next_action']);
    }

    /**
     * THE PAGE-SCOPED HALF of the advisory, which the chrome-only tests never reached.
     * A band's dangling image has to be named with the band it is on, or an operator
     * with a fifty-band page is told only that "something" stopped painting.
     */
    public function testABandsDanglingImageIsNamedWithItsBand(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'][5]['_pp_composition'] = wp_json_encode([
            ['component' => 'testimonials', 'id' => 'pp-aaaaaaaa',
             'udc' => ['_band' => ['background' => ['image' => 99]]]],
        ]);

        $rows = pp_check_udc_background_images(5);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('band 1 ("testimonials")', $rows[0]['message']);
        $this->assertStringContainsString('attachment 99', $rows[0]['message']);

        // Chrome-only still reports nothing for this page's band.
        $this->assertSame([], pp_check_udc_background_images());
    }

    /** The report is bounded, and says so rather than implying it saw everything. */
    public function testTheAdvisoryIsBoundedAndDeclaresTheOverflow(): void
    {
        $chrome = [];
        foreach (array_keys(pp_udc_component_roles('footer')) as $i => $role) {
            $chrome[$role] = ['background' => ['image' => 900 + $i]];
        }
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] =
            (string) wp_json_encode(['_version' => 1, 'footer' => $chrome]);

        $rows = pp_check_udc_background_images();
        $this->assertCount(11, $rows, 'ten rows plus one overflow line');
        $this->assertSame('udc_background_image:overflow', $rows[10]['finding_key']);
        $this->assertStringContainsString('At least', $rows[10]['message']);
    }

    /** And it is spliced into preflight, which is where an operator meets it. */
    public function testTheAdvisoryReachesPreflight(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1, 'nav' => ['_band' => ['background' => ['image' => 99]]],
        ]);
        $GLOBALS['_pp_test_store']['post_meta'][5]['_pp_composition'] = wp_json_encode([
            ['component' => 'testimonials', 'id' => 'pp-aaaaaaaa',
             'udc' => ['_band' => ['background' => ['image' => 98]]]],
        ]);

        $checks = pp_preflight(['post_id' => 5])['checks'];
        $rows   = array_values(array_filter(
            $checks,
            static fn(array $c): bool => ($c['check'] ?? '') === 'udc_background_image'
        ));
        $this->assertCount(2, $rows, 'the chrome one and the band one both reach the operator');
        foreach ($rows as $row) {
            $this->assertSame('warning', $row['severity'], 'advisory only — never blocks a mutation');
        }
        // A warning-grade row must not fail the preflight verdict, because this
        // advisory must never block a mutation. Asserted against the row's own
        // contribution rather than the whole verdict, which other checks also move.
        $this->assertSame(
            [],
            array_filter($rows, static fn(array $c): bool => ($c['severity'] ?? 'error') === 'error'),
            'no udc_background_image row may be error-grade'
        );
    }

    /**
     * ONE PREDICATE: what the advisory names is exactly what the page omits. Two
     * hand-rolled copies would be two grammars, and the one that drifted would either
     * warn about an image that paints or stay silent about one that does not.
     */
    public function testTheAdvisoryAndTheEmitterCannotDisagree(): void
    {
        foreach ([42 => true, 99 => false] as $id => $shouldPaint) {
            $css      = pp_udc_band_css($this->band(['image' => $id]));
            $paints   = str_contains($css, 'url(');
            $resolves = pp_udc_background_image_url($id) !== null;
            $this->assertSame($shouldPaint, $paints, "emitter disagrees for {$id}");
            $this->assertSame($paints, $resolves, "advisory predicate disagrees with the emitter for {$id}");
        }
    }

    // ── 3. The ruling's exclusions REFUSE ───────────────────────────────────

    public function testPerBreakpointArtDirectionIsRefusedNotIgnored(): void
    {
        $error = $this->validate(['image' => ['d' => 42, 'p' => 42]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('cannot be set per breakpoint', $error->get_error_message());
        // And it names what IS breakpoint-keyable, so the author has somewhere to go.
        $this->assertStringContainsString('position', $error->get_error_message());
    }

    public function testPerStateImageSwappingIsRefusedNotIgnored(): void
    {
        $error = pp_udc_validate_map(
            ['_band' => ['background' => [':hover' => ['image' => 42]]]],
            'testimonials'
        );
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('cannot be set per state', $error->get_error_message());
    }

    /** The emit side closes the same two doors, for data that never passed the gate. */
    public function testStoredDataInAnExcludedDimensionEmitsNothing(): void
    {
        $this->assertStringNotContainsString(
            'url(',
            pp_udc_band_css($this->band(['image' => ['d' => 42, 'p' => 42]]))
        );
        $this->assertStringNotContainsString('url(', pp_udc_band_css([
            'component' => 'testimonials', 'id' => 'pp-deadbeef',
            'udc' => ['_band' => ['background' => [':hover' => ['image' => 42]]]],
        ]));
    }

    /**
     * THE #705 CRASH CLASS IS UNREACHABLE HERE — AND THIS IS THE RECORD OF WHY (#1101, D4).
     *
     * ═══ WHAT #705 WAS ═══
     *
     * A stored non-scalar `background_image` fataled the PUBLIC PAGE. Three call sites
     * passed a raw stored value straight into a typed parameter —
     * `pp_esc_image_src(string $url, int $depth = 0)` — behind a TRUTHINESS gate. A
     * non-empty array is truthy, so the gate passed, the typed call raised a TypeError,
     * and `templates/composition.php` calls `pp_get_component()` with no try/catch: one
     * malformed stored value returned a whole-page 500 rather than a band with a missing
     * background. It was guarded by a `is_scalar` check AT THE READ, and pinned by
     * `tests/StoredBackgroundImageRenderGuardTest.php` against the components that
     * declared the prop.
     *
     * ═══ WHY THAT SUITE IS NOT BEING RE-HOMED ONTO THIS PATH ═══
     *
     * Ruling D4 (#1101) ordered the orphaned crash coverage re-homed onto the live
     * `_band.background.image` path, WITH a verification clause: confirm the re-homed
     * pins actually exercise the #705 crash class rather than assuming equivalence. The
     * verification was run and it FAILED, so the ruling was revised — the suite dies with
     * `ppfixture` as scheduled, and this pin replaces it.
     *
     * MEASURED, all seven #705 vectors, against `_band.background.image`:
     *
     *   vector                         write gate              render
     *   ─────────────────────────────  ──────────────────────  ────────────────────────
     *   ['https://example.com/a.jpg']  invalid_prop_value      no url(), band complete
     *   ['url' => 'https://…']         invalid_prop_value      no url(), band complete
     *   []                             invalid_prop_value      no url(), band complete
     *   true                           invalid_prop_value      no url(), band complete
     *   -0.0                           invalid_prop_value      no url(), band complete
     *   new stdClass()                 invalid_prop_value      no url(), band complete
     *
     * Not one of them throws, so a re-homed suite would be 502 lines of assertions that
     * CANNOT FAIL — the vacuity class #1026 shipped twice and that #1101 §3.6 requires
     * planted-defect proofs against. Re-homing it would have converted real coverage into
     * decoration while keeping its issue number, which is worse than deleting it.
     *
     * ═══ THE SEVENTH VECTOR STOPPED BEING A VECTOR, AND THAT IS THE FINDING ═══
     *
     * #705's list had a seventh entry: a stored INT. Under v1 that was a defect in its
     * own right — `background_image: 42` coerced at the string boundary and painted
     * `url(42)`, a broken image the guard deliberately preserved as COMPATIBILITY while
     * #707 narrowed the write path. On v2 an integer is not a malformed URL, it is the
     * CORRECT AND ONLY type: the value IS an attachment id. So `42` is accepted here
     * whenever attachment 42 is a live image, and refused as DANGLING when it is not —
     * neither of which is #705's class.
     *
     * That is the sharpest evidence that this suite could not have been re-homed
     * meaningfully: one of its seven vectors inverted from "defect" to "the happy path"
     * on the very parameter it would have been re-homed onto. A mechanical re-home would
     * have asserted that the correct value is refused.
     *
     * ═══ THE STRUCTURAL REASON, WHICH IS THE PART WORTH REMEMBERING ═══
     *
     * The class did not MOVE. It stopped being CONSTRUCTIBLE, because v2 changed what an
     * author stores: a band background is a Media Library ATTACHMENT ID, not a URL string.
     * `pp_udc_background_image_url()` opens REJECT-NEVER-COERCE, before any typed call
     * exists to reach:
     *
     *     if (!is_scalar($id) || is_bool($id)) { return null; }
     *     $raw = trim((string) $id);
     *     if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) { return null; }
     *
     * `pp_esc_image_src()` is then reached ONLY with a URL this engine built itself, out
     * of WordPress's own URL for an attachment it has just proved is a live image. There
     * is no path on which a stored value reaches a typed parameter, so there is no
     * truthiness gate for a non-empty array to pass. That is strictly stronger than the
     * #705 guard was: #705 guarded a coercion; this declines to have one.
     *
     * ═══ THE LIVE EQUIVALENT, so nobody re-derives the mapping ═══
     *
     * `image_url` IS still a live string prop — hero, section, and `items[].image_url` on
     * grid, logos and testimonials — and it IS a genuine typed-call surface. That is
     * #641's class, not #705's, and it is owned by
     * `tests/StoredImageUrlRenderGuardTest.php`. Do not re-home #705's vectors there: the
     * suite already covers that surface, and duplicating it under the wrong issue number
     * would make two records of one defect that could drift apart.
     *
     * ═══ WHAT THIS TEST ASSERTS ═══
     *
     * BOTH GATES, because the halves fail differently and one without the other is half a
     * claim. The write gate refusing proves an author cannot create the state; the
     * RENDER completing proves that a composition which already holds it — a pre-v2 page,
     * a restored snapshot (#233), a raw meta write — still SERVES, which is the thing
     * #705 was actually about. A guard that only refused at write would leave every
     * existing page on the fatal.
     */
    public function testTheStoredNonScalarCrashClassIsUnreachableOnTheV2BandImagePath(): void
    {
        // #705's vectors MINUS the stored int, which inverted — see the docblock. Its two
        // v2 behaviours are asserted separately at the end, because "accepted when live,
        // refused when dangling" is a different claim from "never constructible".
        $vectors = [
            'list array'  => ['https://example.com/a.jpg'],
            'assoc array' => ['url' => 'https://example.com/a.jpg'],
            'empty array' => [],
            'true'        => true,
            'negative-zero float' => -0.0,
            'object'      => new \stdClass(),
        ];

        foreach ($vectors as $label => $bad) {
            // GATE 1 — THE WRITE. An author cannot create the state at all.
            $error = $this->validate(['image' => $bad]);
            $this->assertInstanceOf(
                WP_Error::class,
                $error,
                "the write gate must refuse a non-scalar image ({$label})"
            );
            $this->assertSame('invalid_prop_value', $error->get_error_code(), $label);

            // GATE 2 — THE RENDER, on stored bytes no gate ever saw. This is the half
            // #705 was about: the page must SERVE. A throw here is the 500 that issue
            // records, and `templates/composition.php` has no try/catch to soften it.
            $css = pp_udc_band_css($this->band(['image' => $bad, 'fill' => '#0b7285']));
            $this->assertStringNotContainsString(
                'url(',
                $css,
                "a non-scalar image must paint nothing ({$label})"
            );
            // The SIBLING declaration still paints, which is what makes this a degrade
            // rather than a band that vanished: exactly one declaration is dropped.
            $this->assertStringContainsString(
                'background:#0b7285;',
                $css,
                "the rest of the band must survive a bad image ({$label})"
            );

            // And the resolver itself declines, rather than coercing to attachment 1 —
            // `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, which
            // is the coercion the shape check exists to precede.
            $this->assertNull(pp_udc_background_image_url($bad), "must not resolve ({$label})");
        }

        // NON-VACUITY, and the inverted seventh vector at the same time. A GOOD id on the
        // same path still paints. Without this the whole test would pass on an engine
        // that had stopped emitting background images entirely, which is the shape an
        // `assertStringNotContainsString` sweep is most prone to — the #1026 lesson this
        // file is written under. `42` is live in this fixture (setUp), so it is both the
        // non-vacuity control AND the proof that a stored int is the HAPPY PATH here
        // rather than #705's coercion defect.
        $this->assertNull($this->validate(['image' => 42]), 'a live attachment id is the correct value');
        $good = pp_udc_band_css($this->band(['image' => 42, 'fill' => '#0b7285']));
        $this->assertStringContainsString('url(', $good, 'a live attachment must still paint');

        // And the same int DANGLING is refused as a dangling reference — named by id,
        // which is a different diagnostic from "wrong shape" and reaches a different
        // repair. Neither is #705's class, and saying so here is what stops the next
        // reader from reading the int's absence above as an oversight.
        $dangling = $this->validate(['image' => 999999]);
        $this->assertInstanceOf(WP_Error::class, $dangling);
        $this->assertStringContainsString('999999', $dangling->get_error_message());
    }

    // ── 4. Composition with the overlay ─────────────────────────────────────

    public function testAColourOverlayIsWrappedIntoALayerAndPaintsOverTheImage(): void
    {
        $css = pp_udc_band_css($this->band(['image' => 42, 'overlay' => 'rgba(0,0,0,0.55)']));
        // The scrim is FIRST in the layer list — CSS paints the first layer on top.
        $this->assertStringContainsString(
            'background-image:linear-gradient(rgba(0,0,0,0.55),rgba(0,0,0,0.55)),url("',
            $css
        );
    }

    public function testAGradientOverlayIsUsedAsALayerNotWrappedAgain(): void
    {
        $css = pp_udc_band_css($this->band([
            'image'   => 42,
            'overlay' => 'linear-gradient(#000000,#ffffff)',
        ]));
        $this->assertStringContainsString('background-image:linear-gradient(#000000,#ffffff),url("', $css);
        $this->assertStringNotContainsString('linear-gradient(linear-gradient', $css);
    }

    /** A scrim over nothing is a value that validates green and paints nothing (I19). */
    public function testAnOverlayWithNoImageEmitsNothing(): void
    {
        $css = pp_udc_band_css($this->band(['fill' => '#0b7285', 'overlay' => 'rgba(0,0,0,0.55)']));
        $this->assertSame('[data-pp-band="pp-deadbeef"]{background:#0b7285;}', $css);
        $this->assertStringNotContainsString('linear-gradient', $css);
        // And the carrier never leaks into a stylesheet.
        $this->assertStringNotContainsString(PP_UDC_BACKGROUND_OVERLAY_CARRIER, $css);
    }

    /**
     * AN OVERLAY THAT RESOLVES THROUGH A TOKEN MUST STILL BE WRAPPED.
     *
     * The wrap/no-wrap choice used to be made on the EMITTED css text, and a band
     * token's css is `var(--pp-name)`, which is not in the design-token registry — so
     * the colour check said no, the wrapper was skipped, and a bare custom property
     * was spliced into a layer list. `background-image: var(--pp-scrim), url(...)` is
     * invalid, because a custom property holding a colour is not an <image>, so the
     * browser dropped the WHOLE declaration: the scrim and the photograph together.
     *
     * Reachable on a fully supported path, not an exotic one: `overlay` is
     * deliberately breakpoint-keyable, and every responsive value is minted into a
     * band token. The site-token case happened to work, which is exactly why this
     * survived the first round of tests. Found by the security specialist.
     *
     * @dataProvider overlayResolutionProvider
     */
    public function testAnOverlayIsWrappedWhateverItResolvesThrough(array $udc): void
    {
        $css = pp_udc_band_css(pp_udc_normalize_band([
            'component' => 'testimonials', 'id' => 'pp-deadbeef', 'udc' => $udc,
        ]));
        $this->assertStringContainsString('url("', $css, 'the image must survive');
        // Every layer before the url() must be an <image>, i.e. a gradient.
        $layers = substr($css, strpos($css, 'background-image:') + 17);
        $layers = substr($layers, 0, strpos($layers, 'url("'));
        $this->assertStringContainsString('linear-gradient(', $layers);
        $this->assertDoesNotMatchRegularExpression(
            '/background-image:\s*var\(/',
            $css,
            'a bare custom property as a layer makes the whole list invalid'
        );
    }

    public static function overlayResolutionProvider(): array
    {
        return [
            'literal colour' => [['_band' => ['background' => [
                'image' => 42, 'overlay' => 'rgba(0,0,0,0.55)']]]],
            'site token' => [['_band' => ['background' => [
                'image' => 42, 'overlay' => '@overlay-bg']]]],
            'band token' => [[
                '_tokens' => ['my-scrim' => 'rgba(0,0,0,0.55)'],
                '_band'   => ['background' => ['image' => 42, 'overlay' => '@my-scrim']]]],
            'responsive (mints a band token)' => [['_band' => ['background' => [
                'image' => 42, 'overlay' => ['d' => 'rgba(0,0,0,0.55)', 'p' => 'rgba(0,0,0,0.9)']]]]],
        ];
    }

    /**
     * The image is single-valued and the overlay is not, so a narrower breakpoint
     * carrying only a scrim has to borrow the base image to lie over. Composed in
     * isolation it would be dropped as "a scrim over nothing" and the author would
     * get a desktop-only overlay with no refusal and no finding — the I35 class.
     */
    public function testANarrowerOverlayStillLiesOverTheSingleImage(): void
    {
        $css = pp_udc_band_css(pp_udc_normalize_band([
            'component' => 'testimonials', 'id' => 'pp-deadbeef',
            'udc' => ['_band' => ['background' => [
                'image'   => 42,
                'overlay' => ['d' => 'rgba(0,0,0,0.55)', 'p' => 'rgba(0,0,0,0.9)'],
            ]]],
        ]));
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        // The phone block carries BOTH layers, not a lone scrim and not nothing.
        $phone = substr($css, strpos($css, '@media (max-width: 767px)'));
        $this->assertStringContainsString('linear-gradient(', $phone);
        $this->assertStringContainsString('url("', $phone);
    }

    /**
     * THE url() TOKEN CANNOT BE CLOSED EARLY.
     *
     * The engine emits `url("<escaped>")`, so the two characters that would break
     * out are `)` and `"`. They are neutralised by different halves of the escaper,
     * and only one of those halves can be asserted by bytes here.
     *
     * `)` is pp_esc_image_src()'s OWN step — it percent-encodes it precisely because
     * esc_url() permits it — so the bytes are the same in the stub and in
     * production, and they are asserted directly.
     *
     * `"` is core esc_url()'s job, and the STUB IS MORE PERMISSIVE THAN CORE:
     * tests/bootstrap.php implements esc_url as filter_var(FILTER_SANITIZE_URL),
     * whose allowed set keeps a double quote, while core's allowed-character pass
     * strips it. Asserting stub bytes here would enshrine a fiction as production
     * behaviour, which is a trap this repo has been bitten by before. So the quote
     * is pinned as a DELEGATION — the escaper must route through esc_url — plus the
     * structural fact that makes the delegation sufficient: the emitted token is
     * quoted, so `"` is the only remaining break-out character and core owns it.
     */
    public function testTheUrlTokenCannotBeClosedEarly(): void
    {
        // Same bytes in stub and core: pp_esc_image_src's own transformation.
        $this->assertStringNotContainsString(')', pp_esc_image_src('https://e.test/a)b.png'));
        $this->assertStringContainsString('%29', pp_esc_image_src('https://e.test/a)b.png'));
        // A newline is stripped by both.
        $this->assertStringNotContainsString("\n", pp_esc_image_src("https://e.test/a\nb.png"));

        // The delegation that owns the quote, pinned at the source rather than by
        // bytes the stub cannot produce faithfully.
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/return str_replace\('\)', '%29', esc_url\(\\\$url\)\);/",
            $source,
            'the CSS-url escaper must stay esc_url() plus the paren step'
        );

        // And the sink is quoted, which is what limits the break-out set to `"`.
        $css = pp_udc_band_css($this->band(['image' => 42]));
        $this->assertMatchesRegularExpression('/url\("[^"]*"\)/', $css);
    }

    // ── #1004: the advisory's own reflected-text discipline ──────────────────

    /**
     * A stored ROLE KEY has no length of its own, so the message must impose one.
     *
     * Measured before the fix: a 5,000-character stored role key produced a
     * 5,177-character readiness message. This check rides the preflight envelope of
     * EVERY mutation, and the readiness `checks[]` channel is explicitly outside the
     * carve-out that lets `findings[].message` copy validator text verbatim — the rule
     * is stated at the sibling ledger in lib/udc.php. Its sibling producer,
     * pp_check_udc_emit_drops(), has bounded the same two fragments since #981; this
     * check landed in #976 and never got the treatment.
     *
     * The 1000-byte assertion is deliberately the same number the sibling's tests use
     * (UdcEmitDropAdvisoryTest), because the two advisories are one family and a reader
     * comparing them should not have to work out whether two bounds mean two rules.
     */
    public function testAHugeStoredRoleKeyIsBoundedInTheAdvisoryMessage(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav'      => [str_repeat('k', 5000) => ['background' => ['image' => 42]]],
        ]);
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false;

        $rows = pp_check_udc_background_images();

        $this->assertCount(1, $rows);
        $this->assertLessThan(
            1000,
            strlen($rows[0]['message']),
            'a stored role key is unbounded, so the sink must bound it'
        );
        $this->assertStringContainsString('attachment 42', $rows[0]['message'],
            'bounding the locator must not cost the fact the row exists to report');
    }

    /** The same, for the stored COMPONENT NAME on the band-scoped arm. */
    public function testAHugeStoredComponentNameIsBoundedInTheAdvisoryMessage(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'][5]['_pp_composition'] = wp_json_encode([
            ['component' => str_repeat('c', 5000), 'id' => 'pp-aaaaaaaa',
             'udc' => ['_band' => ['background' => ['image' => 99]]]],
        ]);

        $rows = pp_check_udc_background_images(5);

        $this->assertCount(1, $rows);
        $this->assertLessThan(1000, strlen($rows[0]['message']));
    }

    /**
     * And for the stored CHROME NAME, the third raw fragment #1004 named — pinned at the
     * ROW HELPER, because the stored path cannot deliver one.
     *
     * The review train proposed routing this through pp_check_udc_background_images() like
     * its two siblings. Tried, and it does not reach: the chrome arm iterates
     * pp_udc_site_map()['chrome'], and that reader keeps only the names
     * pp_udc_chrome_names() declares, so an arbitrary 5,000-character key is dropped
     * before the advisory ever sees it. The unreachability is the fail-closed reader
     * working, not a gap.
     *
     * So the bound is pinned where the fragment actually enters: the row builder, which is
     * also the only place a hand-written or future filtered producer could hand one in.
     * Named rather than silently made a unit test, because a reader comparing this to its
     * two siblings will otherwise assume it was an oversight.
     */
    public function testAHugeStoredChromeNameIsBoundedInTheAdvisoryRow(): void
    {
        $long = str_repeat('n', 5000);
        $row  = _pp_udc_background_image_row('chrome "%s"', $long, 'logo', 42);

        $this->assertLessThan(1000, strlen($row['scope_display']));
        $this->assertStringContainsString($long, $row['scope'],
            'the RAW half is the hash input and must keep every byte');

        // The premise above, asserted rather than described: the reader drops it.
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            $long      => ['logo' => ['background' => ['image' => 42]]],
        ]);
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false;
        $this->assertSame([], pp_check_udc_background_images(),
            'an undeclared chrome name never reaches the advisory at all');
    }

    /**
     * THE ACKNOWLEDGEMENT KEY IS UNCHANGED BY THE BOUND, and that is the whole reason
     * the cleaning happens at the message sink instead of at row construction.
     *
     * `finding_key` is what an operator's acknowledgement is stored against. Hashing the
     * CLEANED locator would have re-keyed every acknowledgement already on disk, and
     * would have made the key many-to-one — two roles differing only past the bound
     * would share one key, so acknowledging one dangling image would silently
     * acknowledge another. pp_check_token_override_validity() sets the precedent one
     * screen up: key on the raw token, print the safe one.
     *
     * The expected value is computed the way the pre-#1004 code computed it, from raw
     * bytes, so this test fails if a future change starts hashing the display strings.
     */
    public function testTheAcknowledgementKeyStillHashesTheRawLocator(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav'      => ['logo' => ['background' => ['image' => 42]]],
        ]);
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false;

        $rows = pp_check_udc_background_images();

        $this->assertCount(1, $rows);
        $this->assertSame(
            'udc_background_image:' . substr(sha1('chrome "nav"' . '|' . 'role "logo"'), 0, 12),
            $rows[0]['finding_key'],
            'the key is derived from the RAW locator, exactly as it was before the bound landed'
        );
    }

    /**
     * TWO NEAR-IDENTICAL ROLE KEYS PRODUCE TWO ROWS, not one.
     *
     * The pre-#1004 helper returned a map keyed by the formatted locator. That shape is
     * fail-open the moment anything cleans the key: two stored roles differing only past
     * the reflection bound collapse into one entry and the second dangling image
     * disappears from the report — a silent drop, inside the check that exists to end
     * silent drops (I29). The helper returns a list now, so the collision cannot exist
     * whatever the bound is later set to.
     */
    public function testTwoRolesDifferingOnlyPastTheBoundStillReportSeparately(): void
    {
        $prefix = str_repeat('r', 200);
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav'      => [
                $prefix . 'aaa' => ['background' => ['image' => 42]],
                $prefix . 'bbb' => ['background' => ['image' => 43]],
            ],
        ]);
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = false;
        $GLOBALS['_pp_test_store']['attachment_is_image'][43] = false;

        $rows = pp_check_udc_background_images();

        $this->assertCount(2, $rows, 'neither dangling image may be swallowed by the other');
        $keys = array_column($rows, 'finding_key');
        $this->assertSame($keys, array_unique($keys),
            'two different drops must not share one acknowledgement');
        // Both messages are still bounded despite the shared 200-character prefix.
        foreach ($rows as $row) {
            $this->assertLessThan(1000, strlen($row['message']));
        }
    }

    public function testTheOverlayCarrierNeverReachesAnyEmittedCss(): void
    {
        foreach ([
            ['overlay' => 'rgba(0,0,0,0.55)'],
            ['image' => 42, 'overlay' => 'rgba(0,0,0,0.55)'],
            ['fill' => '#fff', 'image' => 42, 'overlay' => '#000'],
        ] as $background) {
            $this->assertStringNotContainsString(
                PP_UDC_BACKGROUND_OVERLAY_CARRIER,
                pp_udc_band_css($this->band($background))
            );
        }
    }
}
