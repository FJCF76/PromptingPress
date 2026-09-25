<?php
/**
 * tests/UdcEffectiveBackgroundTest.php — what paints at tier X (#1010 review, the one-owner
 * ruling).
 *
 * pp_udc_band_effective_background() is the RENDERER's answer to "is this band scrimmed at
 * this width, or in this state", read off the compiled band. The overlay marker
 * (pp_udc_band_paints_scrim) and the off-scrim finding both read it, so these tests pin it
 * against what pp_udc_band_css() actually emits: each case asserts the emitted CSS as its
 * premise, then the accessor's answer for that CSS.
 */

use PHPUnit\Framework\TestCase;

final class UdcEffectiveBackgroundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function item(array $background, array $extra = []): array
    {
        return ['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => ['_band' => ['background' => $background] + $extra]];
    }

    /** [effective, emitted css] for a band. */
    private function read(array $item): array
    {
        return [pp_udc_band_effective_background(pp_udc_compile_band($item, 'authored')), pp_udc_band_css($item)];
    }

    public function testAScrimAtTheBaseTierCoversEveryWidth(): void
    {
        [$eff, $css] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']));
        $this->assertStringContainsString('linear-gradient(rgba(6,10,28,0.72),rgba(6,10,28,0.72)),url(', $css);
        foreach (['d', 't', 'p'] as $bp) {
            $this->assertTrue($eff['tiers'][$bp]['image'], $bp);
            $this->assertSame('linear-gradient(rgba(6,10,28,0.72),rgba(6,10,28,0.72))', $eff['tiers'][$bp]['scrim'], $bp);
        }
        $this->assertSame([], $eff['states']);
        $this->assertTrue(pp_udc_band_paints_scrim(pp_udc_compile_band($this->item(['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']), 'authored')));
    }

    public function testAnImageWithNoScrimIsNoScrim(): void
    {
        $item = $this->item(['image' => 9001]);
        [$eff] = $this->read($item);
        $this->assertTrue($eff['tiers']['d']['image']);
        $this->assertSame('', $eff['tiers']['d']['scrim']);
        $this->assertFalse(pp_udc_band_paints_scrim(pp_udc_compile_band($item, 'authored')));
    }

    public function testADeletedImagePaintsNothing(): void
    {
        $item = $this->item(['image' => 9002, 'overlay' => 'rgba(0,0,0,0.7)']);
        [$eff, $css] = $this->read($item);
        $this->assertStringNotContainsString('url(', $css, 'premise: the emitter drops the image and its scrim');
        $this->assertFalse($eff['tiers']['d']['image']);
        $this->assertFalse(pp_udc_band_paints_scrim(pp_udc_compile_band($item, 'authored')));
    }

    public function testATierThatReplacesTheBackgroundIsUnscrimmedThere(): void
    {
        [$eff, $css] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)', 'fill' => ['p' => '#ffffff']]));
        $this->assertStringContainsString('@media (max-width: 767px){[data-pp-band="pp-a1b2c3d4"]{background:#ffffff;}', $css);
        $this->assertTrue($eff['tiers']['d']['image']);
        $this->assertTrue($eff['tiers']['t']['image'], 'tablet inherits the base tier');
        $this->assertFalse($eff['tiers']['p']['image'], 'the phone tier replaces the image');
        $this->assertSame('', $eff['tiers']['p']['scrim']);
    }

    /** A fill and an image in the same tier: the emitter prints the fill first, so the image (and its scrim) paints. */
    public function testAFillBesideTheImageDoesNotEraseIt(): void
    {
        $item = $this->item(['fill' => '#101828', 'image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)']);
        [$eff, $css] = $this->read($item);
        $this->assertMatchesRegularExpression('/\{background:#101828;background-image:linear-gradient/', $css, 'premise: the fill prints before the image');
        $this->assertTrue($eff['tiers']['d']['image']);
        $this->assertSame('linear-gradient(rgba(0,0,0,0.7),rgba(0,0,0,0.7))', $eff['tiers']['d']['scrim']);
        $this->assertTrue(pp_udc_band_paints_scrim(pp_udc_compile_band($item, 'authored')));
        $this->assertTrue(pp_udc_band_has_overlay($item), 'the marker is set on the common fill + image + scrim band');
    }

    public function testARawCssShorthandAtOneWidthReplacesTheImageThere(): void
    {
        [$eff] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], ['_css' => ['background' => ['p' => '#ffffff']]]));
        $this->assertFalse($eff['tiers']['p']['image']);
        $this->assertTrue($eff['tiers']['d']['image']);
    }

    public function testAScrimAtOneWidthOnlyLeavesTheOthersUnscrimmed(): void
    {
        [$eff] = $this->read($this->item(['image' => 9001, 'overlay' => ['p' => 'rgba(0,0,0,0.7)']]));
        $this->assertSame('', $eff['tiers']['d']['scrim']);
        $this->assertSame('', $eff['tiers']['t']['scrim']);
        $this->assertNotSame('', $eff['tiers']['p']['scrim']);
    }

    public function testAStateThatRepaintsTheBandIsListed(): void
    {
        [$eff, $css] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)', ':hover' => ['fill' => '#ffffff']]));
        $this->assertStringContainsString('[data-pp-band="pp-a1b2c3d4"]:hover{background:#ffffff;}', $css);
        $this->assertSame([':hover'], $eff['states']);
    }

    public function testAnOverlayOnlyInsideAStateIsDroppedAndPaintsNoScrim(): void
    {
        $item = $this->item(['image' => 9001, ':hover' => ['overlay' => 'rgba(0,0,0,0.7)']]);
        [$eff, $css] = $this->read($item);
        $this->assertStringNotContainsString('linear-gradient', $css, 'premise: the emitter drops a state overlay');
        $this->assertFalse(pp_udc_band_paints_scrim(pp_udc_compile_band($item, 'authored')));
    }

    public function testAPresetTierTheAuthorDidNotOverrideStillPaints(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'tabwash', 'grain' => 'background',
            'udc' => ['image' => 9001, 'overlay' => ['t' => 'rgba(255,255,255,0.85)']]])['ok']);
        [$eff, $css] = $this->read($this->item(['_preset' => 'tabwash', 'overlay' => 'rgba(0,0,0,0.7)']));
        $this->assertStringContainsString('linear-gradient(rgba(255,255,255,0.85),rgba(255,255,255,0.85))', $css);
        $this->assertSame('linear-gradient(rgba(255,255,255,0.85),rgba(255,255,255,0.85))', $eff['tiers']['t']['scrim']);
        $this->assertSame('preset:tabwash', $eff['tiers']['t']['source']);
        $this->assertSame('linear-gradient(rgba(0,0,0,0.7),rgba(0,0,0,0.7))', $eff['tiers']['d']['scrim']);
    }

    public function testNoBlocksPaintNothing(): void
    {
        $eff = pp_udc_band_effective_background(['id' => '', 'tokens' => [], 'blocks' => []]);
        foreach ($eff['tiers'] as $tier) {
            $this->assertFalse($tier['image']);
        }
        $this->assertSame([], $eff['states']);
    }

    /**
     * A SCRIM THAT COVERS ONLY PART OF THE BAND IS PARTIAL (#1142 item 1). `background-size` applies to every layer, so a
     * 200px no-repeat image paints its scrim on a 200px square and the rest of the band shows its own background. The
     * accessor reads the effective size and repeat per tier (inheriting from `d` per property); a scrimmed tier is
     * partial when some axis is a length or a percentage under 100 and that axis does not tile (per axis, from the
     * scrim layer: contain, cover, auto and 100% or more cover it; see the next test). The marker is unchanged (R4).
     */
    public function testAScrimSizedWithoutTilingIsPartial(): void
    {
        $scrim = ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)'];
        [$fx] = $this->read($this->item($scrim + ['size' => '200px 200px', 'repeat' => 'no-repeat']));
        $this->assertTrue($fx['tiers']['d']['partial']);
        $this->assertSame('200px 200px', $fx['tiers']['d']['size']);
        [$fx] = $this->read($this->item($scrim + ['size' => '50%', 'repeat' => 'space']));
        $this->assertTrue($fx['tiers']['p']['partial'], 'space leaves gaps too; inherited by narrower tiers');
        [$fx] = $this->read($this->item($scrim + ['size' => ['p' => '100px']]));
        $this->assertFalse($fx['tiers']['d']['partial'], 'cover at the base tier');
        $this->assertTrue($fx['tiers']['p']['partial'], 'a phone-only size with the engine\'s no-repeat companion');
        [$fx] = $this->read($this->item($scrim + ['size' => '200px', 'repeat' => 'repeat']));
        $this->assertFalse($fx['tiers']['d']['partial'], 'a tiling scrim covers the box');
        [$fx] = $this->read($this->item($scrim));
        $this->assertFalse($fx['tiers']['d']['partial'], 'the default cover');
        $this->assertTrue(pp_udc_band_has_overlay($this->item($scrim + ['size' => '200px 200px', 'repeat' => 'no-repeat'])), 'the marker is unchanged (R4)');
    }

    /**
     * PARTIAL IS DECIDED PER AXIS FROM THE SCRIM LAYER (ruling A, PR-2 review): a gradient has no natural size, so under
     * contain, cover, auto or 100%-or-more it fills the band on that axis (Chromium corner pixel: scrim). A length, or a
     * percentage under 100%, on an axis that does not tile leaves part of the band unscrimmed.
     */
    public function testPartialIsDecidedPerAxisFromTheScrimLayer(): void
    {
        $scrim = ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'repeat' => 'no-repeat'];
        foreach (['contain', '100% 100%', '100%', 'auto', 'cover', 'auto 100%', '120%'] as $size) {
            [$fx] = $this->read($this->item(['size' => $size] + $scrim));
            $this->assertFalse($fx['tiers']['d']['partial'], "{$size}: the scrim fills the band");
        }
        foreach (['auto 50%', '200px 200px', '200px', '50%', '100% 30rem'] as $size) {
            [$fx] = $this->read($this->item(['size' => $size] + $scrim));
            $this->assertTrue($fx['tiers']['d']['partial'], "{$size}: an axis the scrim does not cover and does not tile");
        }
        // Tiling is per axis too: repeat-x tiles the horizontal axis only.
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '200px 100%', 'repeat' => 'repeat-x']));
        $this->assertFalse($fx['tiers']['d']['partial'], 'the short axis tiles, the other is 100%');
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '100% 200px', 'repeat' => 'repeat-x']));
        $this->assertTrue($fx['tiers']['d']['partial'], 'the short axis is the one that does not tile');
        // repeat-y tiles the vertical axis only, and round tiles both (PR-2 review cycle 2, testing).
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '200px 100%', 'repeat' => 'repeat-y']));
        $this->assertTrue($fx['tiers']['d']['partial'], 'repeat-y leaves the short horizontal axis untiled');
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '100% 200px', 'repeat' => 'repeat-y']));
        $this->assertFalse($fx['tiers']['d']['partial'], 'repeat-y tiles the short vertical axis');
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '200px', 'repeat' => 'round']));
        $this->assertFalse($fx['tiers']['d']['partial'], 'round tiles both axes');
    }

    /** Repeat inherits per tier like size (PR-2 review, testing). */
    public function testRepeatInheritsPerTier(): void
    {
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '200px', 'repeat' => ['d' => 'repeat', 'p' => 'no-repeat']]));
        $this->assertFalse($fx['tiers']['d']['partial'], 'tiles at the base width');
        $this->assertTrue($fx['tiers']['p']['partial'], 'the phone width stops tiling');
    }

    /**
     * A SIZE SET ONLY IN A STATE does not make the resting tier partial, and the state is not named: CONSERVATIVE BY
     * CHOICE (orchestrator, PR-2 review), as the state repaint of a scrimmed image was left in #1142 item 3. Naming a
     * scrim that turns partial only on hover is a decision, not a cleanup.
     */
    public function testAStateOnlySizeLeavesTheRestingTierAlone(): void
    {
        [$fx] = $this->read($this->item(['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', ':hover' => ['size' => '200px', 'repeat' => 'no-repeat']]));
        $this->assertFalse($fx['tiers']['d']['partial']);
    }

    /** Every spelling, one answer (ruling A, PR-2 review): a band-token repeat that tiles is not partial; a band-token size stays read. */
    public function testBandTokenRepeatAndSizeAreResolved(): void
    {
        $base = ['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => []];
        $fx = pp_udc_band_effective_background(pp_udc_compile_band($base + ['udc' => ['_tokens' => ['rp' => 'repeat'],
            '_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '200px', 'repeat' => '@rp']]]], 'authored'));
        $this->assertFalse($fx['tiers']['d']['partial'], 'a band-token repeat that tiles');
        $fx = pp_udc_band_effective_background(pp_udc_compile_band($base + ['udc' => ['_tokens' => ['sz' => '200px'],
            '_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.8)', 'size' => '@sz', 'repeat' => 'no-repeat']]]], 'authored'));
        $this->assertTrue($fx['tiers']['d']['partial'], 'a band-token size is read through the token');
    }
}
