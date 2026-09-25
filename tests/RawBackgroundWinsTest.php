<?php
/**
 * tests/RawBackgroundWinsTest.php — #1141 (ruling D1 = A): a raw `_css` background outranks the
 * group's image layers at the same coordinate, as contract §2'.3 rules and
 * `udc_css_overrides_group_value` says; and that finding's message is chosen from what the
 * band actually COMPILED, not from which keys the author wrote.
 *
 * Before (main 6e21e0b, evidence-t2/before-1141-1142-probe.txt):
 *   1. `_css: {"background": "#ffffff"}` beside `background.image` + `overlay` emitted the
 *      shorthand FIRST, so the image and its scrim painted over it, while the finding said the
 *      shorthand "resets background-image, so the background.image you also set does not paint".
 *   2. A stored `_css: {"background-image": "linear-gradient(...)"}` (refused at write: raw
 *      background-image is typed as an attachment id) was dropped at emit with NO ledger row
 *      and the group's layer list painted, while the finding said "the raw value is what paints".
 */

use PHPUnit\Framework\TestCase;

final class RawBackgroundWinsTest extends TestCase
{
    private const URL = 'url("https://example.com/wp-content/uploads/image-9001.jpg")';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        $GLOBALS['_pp_test_store']['posts'][9002]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9002] = true;
    }

    private function band(array $band_map): array
    {
        return ['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => ['_band' => $band_map]];
    }

    private function collision(array $item): array
    {
        return array_values(array_filter(pp_udc_composition_findings([$item]),
            static fn (array $f): bool => $f['type'] === 'udc_css_overrides_group_value'));
    }

    /** Case 1: the raw shorthand wins; the image, its scrim and their companions do not paint, and the marker follows. */
    public function testARawBackgroundShorthandWinsOverTheBandImageAndItsScrim(): void
    {
        $item = $this->band(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']]);
        $css  = pp_udc_band_css($item);
        $this->assertStringContainsString('background:#ffffff', $css);
        $this->assertStringNotContainsString('url(', $css, 'the image does not paint under a raw shorthand that wins');
        $this->assertStringNotContainsString('background-image', $css, 'nor the scrim layer');
        $this->assertStringNotContainsString('background-size', $css, 'nor the image companions');
        $this->assertFalse(pp_udc_band_has_overlay($item), 'the overlay marker follows what paints: no scrim, no marker');
        $drops = [];
        pp_udc_compile_band($item, 'authored', $drops);
        $this->assertContains('overlay_without_image', array_column($drops, 'code'), 'the scrim that no longer paints is SAID, not dropped silently');
        $found = $this->collision($item);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('so the background.image you also set does not paint', $found[0]['message'], 'now true');
    }

    /** A raw typed value that compiles (an attachment id) wins its property, and the finding says so (control). */
    public function testARawAttachmentIdWinsAndTheFindingSaysTheRawValuePaints(): void
    {
        $item = $this->band(['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background-image' => 9002]]);
        $this->assertStringContainsString('image-9002.jpg', pp_udc_band_css($item));
        $this->assertStringNotContainsString('image-9001.jpg', pp_udc_band_css($item));
        $found = $this->collision($item);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('so the raw value is what paints', $found[0]['message']);
    }

    /**
     * Case 2: a stored raw value the grammar refuses is ledgered, not silent, and the finding does not claim it
     * paints: the group value does, and the message says that.
     */
    public function testAStoredRawBackgroundImageTheGrammarRefusesIsLedgeredAndNotClaimed(): void
    {
        $item  = $this->band(['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background-image' => 'linear-gradient(#000,#000)']]);
        $drops = [];
        pp_udc_compile_band($item, 'authored', $drops);
        $this->assertStringContainsString(self::URL, pp_udc_band_css($item), 'premise: the group image paints');
        $rows = array_values(array_filter($drops, static fn (array $d): bool => str_contains((string) $d['where'], 'background-image')
            && str_contains((string) $d['where'], PP_UDC_CSS_KEY)));
        $this->assertCount(1, $rows, 'the raw drop is ledgered (it was silent: the 8c carve-out assumed the image checker saw it)');
        $found = $this->collision($item);
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('so the raw value is what paints', $found[0]['message'], 'it does not paint');
        $this->assertStringContainsString('the stored raw value cannot be emitted, so the background.image you also set is what paints', $found[0]['message']);

        // Alone (no group value): still ledgered, no collision to report.
        $alone = [];
        pp_udc_compile_band($this->band([PP_UDC_CSS_KEY => ['background-image' => 'none']]), 'authored', $alone);
        $this->assertNotSame([], $alone, 'a raw value that paints nothing is never silent');
    }

    /**
     * The shorthand's drop reaches narrower tiers too: an overlay declared only at a narrower width borrows the base
     * image, and with the base image cancelled by the raw shorthand it has nothing to lie over, so it is dropped and SAID.
     */
    public function testANarrowerScrimOverACancelledImageIsDroppedAndSaid(): void
    {
        $item  = $this->band(['background' => ['image' => 9001, 'overlay' => ['p' => 'rgba(0,0,0,0.7)']], PP_UDC_CSS_KEY => ['background' => '#ffffff']]);
        $drops = [];
        pp_udc_compile_band($item, 'authored', $drops);
        $this->assertStringNotContainsString('url(', pp_udc_band_css($item));
        $this->assertContains('overlay_without_image', array_column($drops, 'code'));
    }

    /** No raw background: emission is byte-identical to main for an image band with a scrim. */
    public function testWithNoRawBackgroundTheEmissionIsUnchanged(): void
    {
        $this->assertSame(
            '[data-pp-band="pp-a1b2c3d4"]{background-image:linear-gradient(rgba(0,0,0,0.7),rgba(0,0,0,0.7)),' . self::URL
            . ';background-size:cover;background-repeat:no-repeat;background-position:center;}',
            pp_udc_band_css($this->band(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)']]))
        );
        $this->assertTrue(pp_udc_band_has_overlay($this->band(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)']])));
    }

    /** The shorthand's message is chosen from the compile too: a stored raw shorthand the grammar refuses does not reset anything. */
    public function testAStoredRawShorthandTheGrammarRefusesDoesNotClaimToReset(): void
    {
        $item = $this->band(['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background' => 'not a colour']]);
        $this->assertStringContainsString(self::URL, pp_udc_band_css($item), 'premise: the raw value is dropped and the image paints');
        $found = $this->collision($item);
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('does not paint', $found[0]['message']);
        $this->assertStringContainsString('would reset background-image, but the stored raw value cannot be emitted, so the background.image you also set is what paints', $found[0]['message']);
    }

    /** Raw longhands the author wrote beside the raw shorthand stay; the group's layers go (PR-2 review, testing). */
    public function testRawLonghandsBesideTheRawShorthandStay(): void
    {
        $css = pp_udc_band_css($this->band(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'],
            PP_UDC_CSS_KEY => ['background' => '#ffffff', 'background-size' => '10px', 'background-position' => 'top']]));
        $this->assertStringContainsString('background-size:10px', $css);
        $this->assertStringContainsString('background-position:top', $css);
        $this->assertStringNotContainsString('url(', $css);
    }

    /** A raw background inside a state wins that state (PR-2 review, testing). */
    public function testARawBackgroundInAStateWinsThatState(): void
    {
        $css = pp_udc_band_css($this->band(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)', ':hover' => ['size' => '200px', 'position' => 'top']],
            PP_UDC_CSS_KEY => [':hover' => ['background' => '#ffffff']]]));
        $this->assertStringContainsString(':hover{background:#ffffff;}', $css);
    }

    /** The compiled-raw lookup is per role and state: another role's compiled raw value does not vouch for this one's (PR-2 review, testing). */
    public function testTheRawLookupIsPerRoleAndState(): void
    {
        $item = ['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => [
            '_band' => ['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background-image' => 'linear-gradient(#000,#000)']],
            'text'  => ['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background-image' => 9002]],
        ]];
        $by_role = [];
        foreach ($this->collision($item) as $f) {
            $by_role[str_contains($f['message'], 'role "_band"') ? '_band' : 'text'] = $f['message'];
        }
        $this->assertStringContainsString('cannot be emitted', $by_role['_band'] ?? '', 'the band\'s refused raw value');
        $this->assertStringContainsString('so the raw value is what paints', $by_role['text'] ?? '', 'the text role\'s compiled one');
    }

    /**
     * A RAW BACKGROUND AT A NARROWER TIER WINS OVER THE BORROWED IMAGE (ruling A, PR-2 review, security). The narrower
     * bucket's own scrim made it borrow the base image back into the bucket whose `background` is raw, so at the phone
     * width the image and scrim painted over the raw value while the finding said they did not.
     */
    public function testARawBackgroundAtANarrowerTierWinsOverTheBorrowedImage(): void
    {
        $item = $this->band(['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.7)', 'p' => 'rgba(0,0,0,0.5)']],
            PP_UDC_CSS_KEY => ['background' => ['p' => '#ffffff']]]);
        $this->assertNull(pp_udc_validate_map($item['udc'], 'cta'), 'premise: the write gate accepts it');
        $css = pp_udc_band_css($item);
        $this->assertMatchesRegularExpression('/@media \\(max-width: 767px\\)\\{\\[data-pp-band="pp-a1b2c3d4"\\]\\{background:#ffffff;\\}\\}/', $css, 'phone: the raw value alone');
        $drops = [];
        pp_udc_compile_band($item, 'authored', $drops);
        $this->assertContains('overlay_without_image', array_column($drops, 'code'), 'the phone scrim is dropped and SAID');
        $fx = pp_udc_band_effective_background(pp_udc_compile_band($item, 'authored'));
        $this->assertFalse($fx['tiers']['p']['image'], 'the accessor reads no image at the phone width');
        $this->assertTrue($fx['tiers']['d']['image'], 'the base tier keeps its image and scrim');
    }

    /** Control: a narrower scrim still borrows the base image when no raw background sits there. */
    public function testANarrowerScrimStillBorrowsTheBaseImageWithoutARawBackground(): void
    {
        $css = pp_udc_band_css($this->band(['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.7)', 'p' => 'rgba(0,0,0,0.5)']]]));
        $this->assertMatchesRegularExpression('/@media \\(max-width: 767px\\)\\{[^}]*background-image:linear-gradient\\(rgba\\(0,0,0,0\\.5\\)/', $css);
    }
}
