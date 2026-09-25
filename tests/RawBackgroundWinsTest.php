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
        // The reason names the raw background, never "Set background.image" to an author who set one (red team RT2).
        foreach ([['p' => 'rgba(0,0,0,0.7)'], ['d' => 'rgba(0,0,0,0.7)', 'p' => 'rgba(0,0,0,0.6)']] as $overlay) {
            $drops = [];
            pp_udc_compile_band($this->band(['background' => ['image' => 9001, 'overlay' => $overlay], PP_UDC_CSS_KEY => ['background' => '#ffffff']]), 'authored', $drops);
            $rows = array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'));
            $this->assertNotSame([], $rows, json_encode($overlay));
            foreach ($rows as $row) {
                $this->assertStringNotContainsString('Set background.image', $row['reason'], json_encode($overlay));
                $this->assertStringContainsString('removed the image, so background.image and', $row['reason'], json_encode($overlay));
            }
        }
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

    /**
     * THE SCRIM DROPPED UNDER A RAW BACKGROUND SAYS WHY (PR-2 review, api-contract): the reused "no usable
     * background.image ... Set background.image" reason told an author who DID set the image to set it again, which
     * changes nothing while the raw background wins. Both the same-width and the narrower-tier case.
     */
    public function testTheScrimDroppedUnderARawBackgroundSaysTheRawBackgroundIsWhy(): void
    {
        foreach (['same width' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']],
            'narrower tier' => ['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.7)', 'p' => 'rgba(0,0,0,0.5)']], PP_UDC_CSS_KEY => ['background' => ['p' => '#ffffff']]]] as $label => $band) {
            $drops = [];
            pp_udc_compile_band($this->band($band), 'authored', $drops);
            $rows = array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'));
            $this->assertCount(1, $rows, $label);
            $this->assertStringNotContainsString('Set background.image', $rows[0]['reason'], $label);
            $this->assertStringContainsString('removed the image, so background.image and this scrim do not paint at this width', $rows[0]['reason'], $label);
            $this->assertStringNotContainsString('put the whole treatment in it', $rows[0]['reason'], 'a raw background cannot carry an image (design review)');
            $this->assertStringContainsString('a raw background cannot carry an image', $rows[0]['reason'], $label);
            // Only where no width paints a scrim: in the narrower case the desktop scrim still paints and the band
            // stays marked, so the earlier pin here asserted a false sentence (corrected, PR-2 review cycle 2, design).
            $this->assertStringContainsString($label === 'same width' ? 'the accent roles it re-lit (those whose colour you have not set) go back to their own colours'
                : 'The scrim still paints at the desktop and tablet widths, so the band stays marked', $rows[0]['reason'], $label);
        }
        $drops = [];
        pp_udc_compile_band($this->band(['background' => ['fill' => '#101828', 'overlay' => 'rgba(0,0,0,0.7)']]), 'authored', $drops);
        $this->assertStringContainsString('Set background.image', array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'))[0]['reason'],
            'control: a fill with no image keeps its own advice');
    }

    /**
     * An item with no usable id is not compiled for the collision message, so it keeps the collision wording and
     * never claims the raw value "cannot be emitted" (a valid '#ffffff' can be) (PR-2 review cycle 2, testing).
     */
    public function testAnItemWithNoIdKeepsTheCollisionWordingForBothArms(): void
    {
        foreach ([
            'shorthand' => ['_band' => ['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background' => '#ffffff']]],
            'longhand'  => ['text' => ['background' => ['image' => 9001], PP_UDC_CSS_KEY => ['background-image' => 'linear-gradient(#000,#111)']]],
        ] as $arm => $udc) {
            $found = $this->collision(['component' => 'cta', 'props' => ['title' => 'C'], 'udc' => $udc]);
            $this->assertCount(1, $found, $arm);
            $this->assertStringNotContainsString('cannot be emitted', $found[0]['message'], $arm);
        }
    }

    /** The drop rows for a scrim under a winning raw background, for one band map. */
    private function rawWonReasons(array $band, string $component = 'cta'): array
    {
        $drops = [];
        pp_udc_compile_band(['component' => $component, 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => ['_band' => $band]], 'authored', $drops);
        return array_values(array_map(static fn (array $r): string => $r['reason'],
            array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image')));
    }

    /**
     * WHETHER THE BAND STAYS MARKED IS READ, NOT ASSUMED (PR-2 review cycle 2, design). A raw background at a narrower
     * width while the desktop scrim still paints leaves the band marked and its accents re-lit on that background;
     * the reason says so, names where the scrim paints, and does not advise removing the image everywhere.
     */
    public function testANarrowerRawBackgroundWhileTheDesktopScrimPaintsKeepsTheBandMarked(): void
    {
        $band = ['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.7)', 't' => 'rgba(0,0,0,0.6)']], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]];
        $this->assertTrue(pp_udc_band_has_overlay($this->band($band)));
        $reasons = $this->rawWonReasons($band, 'stats');
        $this->assertCount(1, $reasons);
        $this->assertStringNotContainsString('the band is not marked', $reasons[0]);
        $this->assertStringNotContainsString('remove background.image and the overlay', $reasons[0]);
        $this->assertStringContainsString('The scrim still paints at the desktop and phone widths, so the band stays marked', $reasons[0]);
        // Where no width paints a scrim, the unmarked wording stands.
        $alone = $this->rawWonReasons(['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']]);
        $this->assertNotSame([], $alone);
        $this->assertStringContainsString('the band is not marked', $alone[0]);
    }

    /** A narrower fill does not hide the cause: the raw desktop background still cancelled the image (cycle 2, design). */
    public function testANarrowerFillUnderARawDesktopBackgroundStillNamesTheRawBackground(): void
    {
        $reasons = $this->rawWonReasons(['background' => ['image' => 9001, 'overlay' => ['p' => 'rgba(0,0,0,0.7)'], 'fill' => ['p' => '#222222']],
            PP_UDC_CSS_KEY => ['background' => '#ffffff']]);
        $this->assertNotSame([], $reasons);
        foreach ($reasons as $reason) {
            $this->assertStringNotContainsString('Set background.image', $reason);
            $this->assertStringContainsString('removed the image, so background.image and', $reason);
        }
    }

    /** A raw image beside the raw background keeps its scrim painting, so it is not counted as dropped (red team cycle 2 A). */
    public function testARawImageBesideARawBackgroundKeepsTheBandMarkedInTheDropReason(): void
    {
        $o    = 'rgba(6,10,28,0.72)';
        $band = ['background' => ['overlay' => ['d' => $o, 't' => $o]], PP_UDC_CSS_KEY => ['background' => ['d' => '#0a0a12', 't' => '#ffffff'], 'background-image' => 9002]];
        $this->assertTrue(pp_udc_band_has_overlay($this->band($band)));
        $reasons = $this->rawWonReasons($band);
        $this->assertCount(1, $reasons);
        $this->assertStringNotContainsString('the band is not marked', $reasons[0]);
        $this->assertStringNotContainsString('remove background.image', $reasons[0]);
        $this->assertStringContainsString('The scrim still paints at the desktop and phone widths', $reasons[0]);
    }

    /**
     * The reason names no other finding (a message pointing at another finding lies the next time a gate changes),
     * and speaks of accent roles only on a component that has overlay-tier roles (red team cycle 2 D).
     */
    public function testTheDropReasonNamesNoOtherFindingAndNoAccentsTheComponentLacks(): void
    {
        $o    = 'rgba(0,0,0,0.7)';
        $band = ['background' => ['image' => 9001, 'overlay' => ['d' => $o, 't' => $o]], PP_UDC_CSS_KEY => ['background' => ['t' => '#0a0a12']]];
        $cta  = $this->rawWonReasons($band);
        $this->assertCount(1, $cta);
        $this->assertStringNotContainsString('finding', $cta[0]);
        $this->assertStringContainsString('accent', $cta[0], 'cta has overlay-tier roles');
        // FACTS ONLY (ruling A, terminal form): the one advice line is source-independent.
        $this->assertStringContainsString('Write the whole treatment in one place: a raw background cannot carry an image', $cta[0]);
        foreach ([$band, ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']]] as $map) {
            $section = $this->rawWonReasons($map, 'section');
            $this->assertCount(1, $section);
            $this->assertStringNotContainsString('accent', $section[0], 'section has no overlay-tier roles');
            $this->assertStringContainsString('removed the image, so background.image and', $section[0]);
        }
    }

    /**
     * TRUE WHETHER OR NOT THE AUTHOR INKED THE ACCENT (red team cycle 3, message-only): the drop reason is written while
     * the band compiles, before the accents' own inks are known, so it scopes its claim to the accents whose colour
     * the author has not set rather than asserting they re-light.
     */
    public function testTheDropReasonScopesItsAccentClaimToAccentsNotInked(): void
    {
        $o = 'rgba(6,10,28,0.72)';
        foreach ([
            'stays marked' => [['background' => ['image' => 9001, 'overlay' => ['d' => $o, 't' => $o]], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]],
                "accent roles it re-lights (those whose colour you have not set) stay near-white on this background"],
            'not marked'   => [['background' => ['image' => 9001, 'overlay' => $o], PP_UDC_CSS_KEY => ['background' => '#ffffff']],
                'the accent roles it re-lit (those whose colour you have not set) go back to their own colours'],
        ] as $label => [$band, $claim]) {
            $drops = [];
            pp_udc_compile_band(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [],
                'udc' => ['_band' => $band, 'heading-accent' => ['typography' => ['color' => '#111111']]]], 'authored', $drops);
            $reason = implode(' ', array_column(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'), 'reason'));
            $this->assertStringContainsString($claim, $reason, $label);
        }
    }

    /**
     * RAW WINS WITH NO IMAGE TOO (contract §2'.3, ruling D1 = A; Step 5.7 adversarial): a raw `background` cancels the
     * group's background longhands at its coordinate whether or not an image is set, so the page matches what
     * udc_css_overrides_group_value tells the author. This is a visible change from main for a raw gradient that relied
     * on background.size / background.repeat (a CHANGELOG BREAKING line).
     */
    public function testARawBackgroundWithNoImageCancelsGroupBackgroundLonghands(): void
    {
        $this->assertSame('[data-pp-band="pp-a1b2c3d4"]{background:linear-gradient(#000,#fff);}',
            pp_udc_band_css($this->band([PP_UDC_CSS_KEY => ['background' => 'linear-gradient(#000,#fff)'], 'background' => ['size' => '20px 20px', 'repeat' => 'repeat']])));
        $this->assertSame('[data-pp-band="pp-a1b2c3d4"]{background:#ffffff;}',
            pp_udc_band_css($this->band([PP_UDC_CSS_KEY => ['background' => '#ffffff'], 'background' => ['position' => 'center']])));
        $found = $this->collision($this->band([PP_UDC_CSS_KEY => ['background' => 'linear-gradient(#000,#fff)'], 'background' => ['size' => '20px 20px']]));
        $this->assertStringContainsString('so the background.size you also set does not paint', $found[0]['message'], 'the message and the page agree');
    }

    /**
     * ONLY A SCRIM WHOSE IMAGE THE RAW BACKGROUND REMOVED GETS THE RAW-WON REASON (/ship red team): an overlay with no
     * image at all beside a raw background gets a no-image reason, never "resets the background here" or a re-lit claim.
     */
    public function testAScrimWithNoImageBesideARawBackgroundIsNotGivenTheRawWonReason(): void
    {
        foreach (['cta', 'section'] as $component) {
            $reasons = $this->rawWonReasons(['background' => ['overlay' => 'rgba(0,0,0,0.6)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']], $component);
            $this->assertCount(1, $reasons, $component);
            $this->assertStringContainsString('no usable background.image', $reasons[0], $component);
            $this->assertStringNotContainsString('removed the image', $reasons[0], $component);
            $this->assertStringNotContainsString('re-lit', $reasons[0], $component);
        }
    }

    /**
     * A SCRIM WITH NO IMAGE UNDER A RAW BACKGROUND IS NOT TOLD TO SET AN IMAGE THE RAW BACKGROUND WOULD RESET (/ship pass 2
     * red team): at the same width, at a narrower width, and under a raw desktop background. It keeps "no usable
     * background.image" and names the raw background as what would reset one; the advice is followable.
     */
    public function testANoImageScrimUnderARawBackgroundIsNotAdvisedToSetAnImageTheRawBackgroundWouldReset(): void
    {
        $o = 'rgba(0,0,0,0.6)';
        foreach ([
            'same width'      => ['background' => ['overlay' => $o], PP_UDC_CSS_KEY => ['background' => '#ffffff']],
            'narrower'        => ['background' => ['overlay' => ['t' => $o]], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]],
            'desktop raw'     => ['background' => ['overlay' => ['t' => $o]], PP_UDC_CSS_KEY => ['background' => '#ffffff']],
        ] as $label => $band) {
            foreach (['cta', 'section'] as $component) {
                $reasons = $this->rawWonReasons($band, $component);
                $this->assertCount(1, $reasons, "{$label} {$component}");
                $this->assertStringNotContainsString('Set background.image (an attachment id)', $reasons[0], "{$label} {$component}");
                $this->assertStringContainsString('no usable background.image here; the raw background in _css at the', $reasons[0], "{$label} {$component}");
                $this->assertStringContainsString('would reset one at this width, so this scrim was dropped. Write the whole treatment in one place', $reasons[0], "{$label} {$component}");
            }
        }
        // A narrower width with its own fill under a raw DESKTOP background: the fill paints there, not the raw background, so
        // the tint goes in that fill (/ship pass 3 design).
        $fill = $this->rawWonReasons(['background' => ['overlay' => ['t' => $o], 'fill' => ['t' => '#222222']], PP_UDC_CSS_KEY => ['background' => '#ffffff']]);
        $this->assertCount(1, $fill);
        $this->assertStringNotContainsString('would reset one at this width', $fill[0]);
        $this->assertStringContainsString('at the desktop width would reset one before it reached this width, so this scrim was dropped', $fill[0]);
        // A role other than `_band` gets the same reason (/ship pass 3 testing).
        $drops = [];
        pp_udc_compile_band(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => ['text' => ['background' => ['overlay' => $o],
            PP_UDC_CSS_KEY => ['background' => '#ffffff']]]], 'authored', $drops);
        $text = implode(' ', array_column(array_filter($drops, static fn (array $d): bool => ($d['code'] ?? '') === 'overlay_without_image'), 'reason'));
        $this->assertStringContainsString('the raw background in _css at the desktop width would reset one at this width', $text);
        $this->assertStringNotContainsString('Set background.image (an attachment id)', $text);
        // Control: a narrower fill that is NOT under a raw background keeps the plain no-image advice (an image set there paints).
        $plain = $this->rawWonReasons(['background' => ['overlay' => ['t' => $o], 'fill' => ['t' => '#222222']]]);
        $this->assertStringContainsString('Set background.image (an attachment id)', $plain[0]);
    }

    /**
     * A ROLE OTHER THAN `_band` WHOSE SCRIM STILL PAINTS AT OTHER WIDTHS is not told to remove the image everywhere
     * (/ship pass 2 api-contract).
     */
    public function testANonBandRoleIsAdvisedToRemoveTheOverlayAtThisWidthOnly(): void
    {
        $drops = [];
        pp_udc_compile_band(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => ['text' => ['background' => ['image' => 9001,
            'overlay' => ['d' => 'rgba(0,0,0,0.7)', 't' => 'rgba(0,0,0,0.6)']], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]]]], 'authored', $drops);
        $reason = implode(' ', array_column(array_filter($drops, static fn (array $d): bool => ($d['code'] ?? '') === 'overlay_without_image'), 'reason'));
        $this->assertStringContainsString('removed the image, so background.image and', $reason);
        $this->assertStringNotContainsString('remove background.image and the overlay', $reason);
        $this->assertStringContainsString('Write the whole treatment in one place', $reason);
    }

    /**
     * THE MARKER IS NOT CLAIMED WHERE NO TEMPLATE PRINTS IT (/ship red team): only the accent claim, on a component whose
     * roles re-light, speaks of the marked band; section says only where the scrim still paints. And the unmarked
     * arm's advice tail is pinned for both (/ship testing).
     */
    public function testTheDropReasonClaimsTheMarkerOnlyWhereAccentsRelightAndPinsItsAdvice(): void
    {
        $o     = 'rgba(0,0,0,0.7)';
        $split = ['background' => ['image' => 9001, 'overlay' => ['d' => $o, 't' => $o]], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]];
        $section = $this->rawWonReasons($split, 'section');
        $this->assertStringContainsString('The scrim still paints at the desktop and phone widths', $section[0]);
        $this->assertStringNotContainsString('marked', $section[0]);
        $cta = $this->rawWonReasons($split);
        $this->assertStringContainsString('so the band stays marked and the accent roles it re-lights', $cta[0]);
        $alone = ['background' => ['image' => 9001, 'overlay' => $o], PP_UDC_CSS_KEY => ['background' => '#ffffff']];
        $cta = $this->rawWonReasons($alone);
        // ONE ALWAYS-TRUE ADVICE (ruling A): never "remove background.image and the overlay", which would also remove an image
        // that paints without a scrim at another width.
        $this->assertStringNotContainsString('remove background.image and the overlay', $cta[0]);
        $this->assertStringContainsString('the accent roles it re-lit (those whose colour you have not set) go back to their own colours. Write the whole treatment in one place', $cta[0]);
        $section = $this->rawWonReasons($alone, 'section');
        $this->assertStringContainsString('Write the whole treatment in one place: a raw background cannot carry an image', $section[0]);
        $this->assertStringNotContainsString('typography.color', $section[0]);
        $this->assertStringNotContainsString('marked', $section[0]);
    }

    /**
     * FACTS ONLY, THE TERMINAL FORM (ruling A, /ship scoped verification). The reason states which raw background, at
     * which declared width, removed the image; that the scrim (with its named source) does not paint there; where it
     * still paints; the accent fact where roles re-light. The one advice line is source-independent, so no preset,
     * card, inheritance or default can falsify it.
     */
    public function testTheRawBackgroundReasonStatesFactsOnly(): void
    {
        $o = 'rgba(0,0,0,0.6)';
        $cases = [
            'same width'        => [['background' => ['image' => 9001, 'overlay' => $o], PP_UDC_CSS_KEY => ['background' => '#ffffff']], 'at the desktop width removed the image'],
            'raw at tablet'     => [['background' => ['image' => 9001, 'overlay' => ['d' => $o, 't' => $o]], PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]], 'at the tablet width removed the image'],
            // Inherited from desktop, the phone width setting its own fill (scoped design, case c): the declared width is named.
            'inherited + fill'  => [['background' => ['image' => 9001, 'overlay' => ['p' => $o], 'fill' => ['p' => '#222222']], PP_UDC_CSS_KEY => ['background' => '#ffffff']], 'at the desktop width removed the image'],
        ];
        foreach ($cases as $label => [$band, $fact]) {
            $reasons = $this->rawWonReasons($band);
            $this->assertNotSame([], $reasons, $label);
            foreach ($reasons as $reason) {
                $this->assertStringContainsString('the raw background in _css ' . $fact, $reason, $label);
                foreach (['Remove the raw background', 'remove the overlay', 'Put the tint', 'Set background.image', 'replace the raw background', 'move the desktop'] as $advice) {
                    $this->assertStringNotContainsString($advice, $reason, "{$label}: no per-case advice ({$advice})");
                }
                $this->assertStringContainsString('Write the whole treatment in one place', $reason, $label);
            }
        }
    }

    /** The scrim's source is named when a preset supplies it (scoped design, case a). */
    public function testThePresetSourceOfTheScrimIsNamed(): void
    {
        $saved = pp_execute_action('save_preset', ['name' => 'ps', 'grain' => 'role', 'udc' => ['background' =>
            ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.6)', 't' => 'rgba(0,0,0,0.6)']]]]);
        $this->assertTrue($saved['ok'], 'premise: preset saved: ' . ($saved['error'] ?? ''));
        $reasons = $this->rawWonReasons([PP_UDC_PRESET_KEY => 'ps', PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]]);
        $this->assertNotSame([], $reasons);
        $this->assertStringContainsString('so background.image and the scrim from preset "ps" do not paint at this width', $reasons[0]);
    }

    /** FACT BUG 1 (scoped design, case b): a raw background that removed a band image is named even where cards set their own image. */
    public function testTheRawFlagIsReadBeforeTheCardImageBranch(): void
    {
        $drops = [];
        pp_udc_compile_band(['component' => 'grid', 'id' => 'pp-a1b2c3d4', 'props' => ['items' => [
            ['id' => 'it-0000000a', 'title' => 'A', 'udc' => ['card' => ['background' => ['image' => 9002]]]], ['id' => 'it-0000000b', 'title' => 'B']]],
            'udc' => ['card' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.6)'], PP_UDC_CSS_KEY => ['background' => '#ffffff']]]], 'authored', $drops);
        $rows = array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image' && str_contains((string) ($r['where'] ?? ''), 'card')));
        $this->assertNotSame([], $rows);
        $this->assertStringContainsString('the raw background in _css at the desktop width removed the image', $rows[0]['reason']);
        $this->assertStringNotContainsString('none is set', $rows[0]['reason']);
    }

    /**
     * FACT BUG 2 (scoped red team, case 4): a role DEFAULT is not the author's background. The same nav `menu` map gives
     * the same reason with or without an unrelated preset that pulls the defaults into the authored buckets.
     */
    public function testRoleDefaultsDoNotCountAsTheAuthorsBackground(): void
    {
        $map    = ['background' => ['overlay' => ['p' => 'rgba(0,0,0,0.5)']], PP_UDC_CSS_KEY => ['background' => '#123456']];
        $reason = static function (array $m): string {
            $drops = [];
            pp_udc_compile_band(['component' => 'nav', 'id' => 'nav', 'udc' => ['menu' => $m]], 'authored', $drops);
            return implode(' ', array_column(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'), 'reason'));
        };
        $plain  = $reason($map);
        $preset = $reason($map + [PP_UDC_PRESET_KEY => 'link']); // the shipped typography-only system preset
        $this->assertStringContainsString('would reset one at this width', $plain);
        $this->assertSame($plain, $preset, 'an unrelated preset does not change the facts');
    }
}
