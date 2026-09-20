<?php
/**
 * tests/StatsLogosV2BandContractTest.php
 *
 * THE TWO NEWEST v2 TEMPLATES, PINNED AT THE BOUNDARY THEIR REBUILD MOVED (#1066 PR2).
 *
 * WHY A FOURTH AND FIFTH COPY OF THESE CLAIMS. `components/stats/stats.php` and
 * `components/logos/logos.php` each carry a FRESH copy of the band-id guard and the
 * overlay-flag read — not a shared helper, six lines of template code apiece — and both
 * files' comments say "Pinned behaviourally in TableEmbedLogosMarkupTest". That file's
 * parametrised guard test covers `table` and `embed` and nothing else, so until this file
 * the claim those comments make was false for the two templates making it.
 *
 * MEASURED, NOT ASSUMED, the way #1026's audit measured cta's: weakening BOTH guards to
 * `(pp_udc_valid_band_id(...) || true)` and replacing both `$overlay_attr` expressions with
 * `''` left all 5146 PHPUnit tests green. UdcEngineTest's template sweep does not notice,
 * and says so in its own docblock: it greps for the STRINGS `__pp_udc_band`,
 * `pp_udc_valid_band_id` and `data-pp-band`, which a template can contain while ignoring
 * the verdict.
 *
 * THE OTHER HALF OF THIS FILE IS THE ABSENCES THE REBUILD CREATED. `theme` and
 * `background_image` left stats, `theme` left logos, and the `__pp_style` map left both.
 * Refusing a WRITE does not empty STORAGE (#233 — restore reports without blocking, and a
 * raw `_pp_composition` meta write is not gated at all), so every page built before this
 * rebuild still holds those keys and still has to render. An absence needs a test more than
 * a presence does: nothing else in the suite can tell a variant class that is deliberately
 * gone from one that is quietly coming back.
 *
 * SCOPE. Everything here is emission or refusal, which is why it is PHPUnit and not
 * Playwright per §14.5's matrix. The rendered consequences these attributes have — the
 * on-overlay focus ring measuring 4.5:1 rather than 1.17:1, the band block actually
 * matching its band — are the e2e block's claims and are deliberately not repeated.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class StatsLogosV2BandContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
        // The #749 batch gate reads the postmeta row and fails closed without a database
        // handle (#833), so the write-path tests below would be refused before their first
        // step and prove nothing. Same reason ActionsTest::setUp() installs one.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    /** The minimum valid content props for each rebuilt band, so an absence is never just an empty render. */
    private function contentProps(string $component): array
    {
        return $component === 'stats'
            ? ['title' => 'By the numbers', 'items' => [['number' => '40+', 'label' => 'Years']]]
            : ['title' => 'Trusted by', 'items' => [['image_url' => 'https://example.com/a.png', 'image_alt' => 'Acme']]];
    }

    /** @return array<string, array{0: string}> */
    public static function rebuiltComponents(): array
    {
        return ['stats' => ['stats'], 'logos' => ['logos']];
    }

    /**
     * THE EMPTY-ATTRIBUTE CASE IS THE ONE THAT MATTERS, and it is why the template validates
     * rather than escaping and hoping: `[data-pp-band=""]` matches every other id-less band
     * on the page, so a malformed stored id does not merely fail to paint its own design — it
     * paints one band's design onto all the others. The template's answer is to emit NO
     * attribute, and that is the behaviour pinned here.
     *
     * @dataProvider rebuiltComponents
     */
    public function testAMalformedBandIdEmitsNoAttributeAtAll(string $component): void
    {
        foreach ([
            'not an id',         // spaces
            'pp-ZZZZ!!',         // outside the charset
            'quote"]',           // attribute-breaking
            str_repeat('a', 65), // over the 64-char cap
            '',                  // empty
            ['pp-1a2b3c4d'],     // non-scalar — the is_scalar() half of the guard
        ] as $bad) {
            $html  = $this->render($component, $this->contentProps($component) + ['__pp_udc_band' => $bad]);
            $shown = is_scalar($bad) ? var_export($bad, true) : gettype($bad);

            $this->assertStringNotContainsString(
                'data-pp-band=""',
                $html,
                "{$component}: an empty band id would match every other id-less band on the page ({$shown})"
            );
            $this->assertStringNotContainsString(
                'data-pp-band',
                $html,
                "{$component}: a malformed band id must emit NO attribute, not a broken one ({$shown})"
            );
            // A bad id is not a reason to lose content — the band still renders.
            $this->assertStringContainsString("data-pp-component=\"{$component}\"", $html);
        }

        // Positive control, or every assertion above passes on a template that emits nothing
        // at all — a renamed file, a loader guard change, a fatal swallowed by the buffer.
        $good = $this->render($component, $this->contentProps($component) + ['__pp_udc_band' => 'pp-1a2b3c4d']);
        $this->assertStringContainsString('data-pp-band="pp-1a2b3c4d"', $good, $component);

        // A NON-STRING SCALAR IS ACCEPTED, AND THAT IS CORRECT — carried over from cta's
        // twin because it is the assumption a reader of this guard makes backwards. `true`
        // casts to "1", which satisfies the id charset, so the attribute is emitted. It is
        // inert rather than dangerous: the engine only ever mints `pp-xxxxxxxx`, so "1" keys
        // no emitted block and the band renders on its role defaults. Pinned so a future
        // tightening is a deliberate choice rather than a surprise.
        $cast = $this->render($component, $this->contentProps($component) + ['__pp_udc_band' => true]);
        $this->assertStringContainsString('data-pp-band="1"', $cast, $component);
        $this->assertStringNotContainsString('data-pp-band=""', $cast, $component);
    }

    /**
     * `data-pp-band-overlay` is the engine's structural hook for the on-overlay focus ring
     * (#986's mechanism, #1035's defect). The stylesheet keys the on-overlay accent on it,
     * and over a dark scrim the ordinary `--color-accent` measures 1.17:1 — so a template
     * that silently stops emitting it drops an accessibility affordance with no other
     * symptom. THE ENGINE decides whether a scrim is painted, which is why the template
     * consumes a flag instead of re-deriving it.
     *
     * @dataProvider rebuiltComponents
     */
    public function testTheOverlayHookIsEmittedOnlyWhenTheEngineSaysSo(string $component): void
    {
        $props = $this->contentProps($component);

        $on = $this->render($component, $props + ['__pp_udc_overlay' => '1']);
        $this->assertStringContainsString('data-pp-band-overlay', $on, $component);

        foreach (['', '0', null, false, []] as $off) {
            $html = $this->render($component, $props + ['__pp_udc_overlay' => $off]);
            $this->assertStringNotContainsString(
                'data-pp-band-overlay',
                $html,
                "{$component}: the overlay hook must appear only when the engine sets it: " . var_export($off, true)
            );
        }
        // Absent key behaves as falsy — the ordinary state of every band with no scrim.
        $this->assertStringNotContainsString('data-pp-band-overlay', $this->render($component, $props), $component);
    }

    /**
     * A STORED `theme` REACHES NO CLASS ON EITHER TEMPLATE.
     *
     * This is the claim TableEmbedLogosMarkupTest makes for embed
     * (testEmbedEmitsNoThemeModifierNowThatTheThemePropIsRetired) and its own docblock
     * explains why asserting the ABSENCE beats deleting the test: the claim the old test
     * made is exactly the claim that must now be FALSE, and nothing else in the suite would
     * notice a variant class returning.
     *
     * A stored `theme` is not a hypothetical shape — it is the ordinary state of every stats
     * and logos band written before this rebuild. The write path refuses it; storage still
     * carries it; the renderer must ignore it.
     *
     * @dataProvider rebuiltComponents
     */
    public function testAStoredRetiredThemeReachesNoClassOnEitherTemplate(string $component): void
    {
        foreach (['inverted', 'muted', 'dark', 'default'] as $theme) {
            $html = $this->render($component, $this->contentProps($component) + ['theme' => $theme]);

            // The root class stands ALONE. Asserted as the exact attribute rather than by
            // substring, because `class="stats stats--inverted"` contains `class="stats`.
            $this->assertStringContainsString(
                'class="' . $component . '" data-pp-component="' . $component . '"',
                $html,
                "{$component}: the root class must stand alone with a stored theme=\"{$theme}\""
            );
            // pp_theme_class() is what used to translate `muted` into the legacy `--dark`
            // class (#570 DG-4). It is not called at all any more, and both spellings must
            // stay gone.
            $this->assertStringNotContainsString($component . '--', $html, "{$component}: no variant class survives the rebuild ({$theme})");
            // And the value itself is never reflected anywhere else either — not into an
            // attribute, not into a data-* hook someone might add as a "harmless" successor.
            $this->assertStringNotContainsString('inverted', $html, "{$component}: the retired value is unread, not relocated ({$theme})");

            // Positive control: the band still renders its content. Without this, a template
            // returning the empty string satisfies every assertion above.
            $this->assertStringContainsString($component === 'stats' ? '40+' : 'Acme', $html, $component);
        }
    }

    /**
     * A STORED `background_image` ON STATS PAINTS NOTHING, AND TAKES ALL THREE GATES WITH IT.
     *
     * v1 drove THREE things off this one prop — the `--has-bg-image` modifier, the inline
     * `background-image` declaration and the overlay <div> — and #705's canonical guard block
     * existed because a call-site-only guard would have left the modifier and the overlay ON
     * with nothing painting underneath. All three retire together here, so all three are
     * asserted together: a partial revival is the undesigned scrim-over-nothing state that
     * block spent sixty lines describing.
     *
     * THE SUCCESSOR IS NOT A SILENT EQUIVALENT, which is the half the schema's route makes an
     * author act on: a v2 band background is `_band` -> `background.image` (an attachment id),
     * its scrim is `background.overlay`, and a band with an image and NO overlay paints no
     * scrim at all where v1 painted one unconditionally.
     */
    public function testAStoredBackgroundImageOnStatsOpensNoneOfItsThreeOldGates(): void
    {
        foreach ([
            'https://example.com/bg.jpg',
            '/wp-content/uploads/bg.png',
            42,                            // the coercive-mode scalar #707 narrowed at write
            ['attachment_id' => 42],       // the non-scalar that used to fatal the public page
        ] as $stored) {
            $html = $this->render('stats', $this->contentProps('stats') + ['background_image' => $stored]);

            $this->assertStringNotContainsString('background-image', $html, 'gate 1: no inline declaration');
            $this->assertStringNotContainsString('stats--has-bg-image', $html, 'gate 2: no modifier class');
            $this->assertStringNotContainsString('stats__overlay', $html, 'gate 3: no scrim <div>');
            // The prop is unread, so there is no typed escaper left for it to reach and
            // nothing to coerce. `Array` is what an esc_* stub prints for a stored array,
            // and it is the string that separates DEGRADED from COERCED.
            $this->assertStringNotContainsString('Array', $html, 'the retired value is never coerced into the page');
            $this->assertStringContainsString('40+', $html, 'and the band still renders its content');
        }
    }

    /**
     * NEITHER TEMPLATE EMITS AN INLINE STYLE ATTRIBUTE ANY MORE — §3.4, and the reason the
     * band block can be authored at all.
     *
     * #708's guard left with the map it guarded: both templates used to read `__pp_style`
     * and render it through pp_render_style_vars(). An inline style attribute outranks every
     * stylesheet, so one surviving here would put the band's own design in front of the
     * engine's emitted block and strand every `udc` write an author makes.
     *
     * The stored map is exactly what a pre-rebuild page holds, and BOTH shapes are sent: the
     * array that was the contract, and the non-array that used to raise a TypeError no caller
     * catches — a whole-page 500 from one malformed stored value.
     *
     * @dataProvider rebuiltComponents
     */
    public function testNeitherRebuiltTemplateEmitsAnInlineStyleAttribute(string $component): void
    {
        foreach ([
            ['--' . $component . '-bg' => '#101014', '--' . $component . '-heading-size' => '3rem'],
            'dark',   // the scalar #708 guarded against
            null,
            [],
        ] as $stored) {
            $html = $this->render($component, $this->contentProps($component) + ['__pp_style' => $stored]);

            $this->assertStringNotContainsString(
                ' style="',
                $html,
                "{$component}: a v2 template's scoped band block is the only styling source"
            );
            $this->assertStringNotContainsString('--' . $component . '-', $html, "{$component}: no custom property is painted");
            $this->assertStringNotContainsString('#101014', $html, "{$component}: the stored declaration is dead, not renamed");
            $this->assertStringContainsString($component === 'stats' ? '40+' : 'Acme', $html, $component);
        }
    }

    /**
     * EACH OF THE THREE NEWLY RETIRED KEYS IS REFUSED WITH ITS OWN CODE AND NAMES ITS ROUTE.
     *
     * The gate is data-driven — it reads whatever `retired_props` a schema declares — so this
     * cannot re-prove the MECHANISM, which ActionsTest pins on hero and embed. What it proves
     * is the shipped MESSAGE for the three keys #1066 PR2 added, which is what an author
     * actually meets: a stats band written before the rebuild ordinarily carries `theme`, and
     * `background_image` is the one whose replacement changes SHAPE (an attachment id, not a
     * url) rather than merely changing address.
     *
     * The route is asserted by the surfaces it NAMES, not by quoting the schema's paragraph:
     * the note is prose that will be re-worded, while the role and the null clear are the two
     * things the author has to act on.
     */
    public function testEachNewlyRetiredPropIsRefusedWithItsOwnCodeAndNamesItsRoute(): void
    {
        foreach ([
            ['stats', 'theme', 'inverted'],
            ['stats', 'background_image', 'https://example.com/bg.jpg'],
            ['logos', 'theme', 'muted'],
        ] as [$component, $prop, $value]) {
            $id = pp_create_page("Aged {$component} band", 'draft');
            // The NON-validating writer, which is what a raw meta write or a restore leaves
            // behind (#233). Going through create_page would be the wrong setup: it refuses
            // this shape, which is precisely why the stored state needs its own coverage.
            pp_update_composition($id, [
                ['component' => $component, 'props' => $this->contentProps($component) + [$prop => $value]],
            ]);

            $result = pp_execute_action('update_component', [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Edited'],
            ]);

            $this->assertFalse($result['ok'], "{$component}.{$prop}: a stale retired key blocks the band until it is cleared");
            $this->assertSame(
                'retired_prop',
                $result['error_code'],
                "{$component}.{$prop}: not unknown_prop — this moved, it was not typo'd, and a caller "
                . 'must be able to tell those apart without string-matching prose'
            );
            $this->assertStringContainsString(
                "was retired when {$component} moved to the v2 styling system",
                $result['error'],
                "{$component}.{$prop}: the CAUSE"
            );
            $this->assertStringContainsString('_band', $result['error'], "{$component}.{$prop}: the ROUTE — the role that carries it now");
            $this->assertStringContainsString('{"' . $prop . '": null}', $result['error'], "{$component}.{$prop}: the CURE");
            $this->assertStringContainsString(
                'this band can be repaired on its own',
                $result['error'],
                "{$component}.{$prop}: the narrowed blast radius, or the author repairs a whole page one key at a time"
            );
        }

        // stats' `background_image` route is the one that changes the value's SHAPE, so its
        // message has to say so — an author who reads "moved to `_band`" and writes the url
        // string they already have is refused a second time for a reason the first refusal
        // could have told them.
        $id = pp_create_page('Aged stats background', 'draft');
        pp_update_composition($id, [
            ['component' => 'stats', 'props' => $this->contentProps('stats') + ['background_image' => '/bg.png']],
        ]);
        $shape = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertStringContainsString('attachment id', $shape['error'], 'the value is an id now, not a url');
        $this->assertStringContainsString('background.overlay', $shape['error'], 'and the scrim is a separate, non-automatic write');
    }

    /**
     * THE CURE THE REFUSAL PROMISES ACTUALLY WORKS, ON ALL THREE KEYS.
     *
     * `null` through update_component is a DELETE (_pp_merge_component_props), and it is the
     * ONLY route that clears a key the schema no longer declares — every other surface
     * validates the key first and refuses. The refusal quotes that cure verbatim, so if the
     * gate ever ran BEFORE the merge the message would be telling authors to attempt
     * something the writer rejects, with no way out of the loop short of a raw meta write.
     *
     * ActionsTest::testUpdateComponentNullRemovesProp pins null-as-delete on a LIVE prop,
     * which is the easy half: a live key survives the unknown-prop gate on its own. The
     * retired key is the one where the ORDER of gate and merge decides the outcome.
     */
    public function testSendingARetiredPropAsNullClearsItAndUnblocksTheBand(): void
    {
        foreach ([
            ['stats', 'theme', 'inverted'],
            ['stats', 'background_image', 'https://example.com/bg.jpg'],
            ['logos', 'theme', 'muted'],
        ] as [$component, $prop, $value]) {
            $id = pp_create_page("Clearing {$component}.{$prop}", 'draft');
            pp_update_composition($id, [
                ['component' => $component, 'props' => $this->contentProps($component) + [$prop => $value]],
            ]);

            $cleared = pp_execute_action('update_component', [
                'post_id' => $id, 'component_index' => 0, 'props' => [$prop => null],
            ]);

            $this->assertTrue(
                $cleared['ok'],
                "{$component}.{$prop}: the cure the refusal quotes must be accepted, or the author is "
                . 'told to do something the writer refuses: ' . ($cleared['error'] ?? '')
            );
            $stored = pp_get_composition($id)[0]['props'];
            $this->assertArrayNotHasKey($prop, $stored, "{$component}.{$prop}: the stored key is gone, not emptied");
            // The CONTENT survives the repair — clearing a styling key must not cost the band
            // its text, which is the thing an author is afraid of before they try it.
            $this->assertSame(
                $this->contentProps($component)['title'],
                $stored['title'] ?? null,
                "{$component}.{$prop}: content survives"
            );
        }
    }
}
