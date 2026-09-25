<?php
/**
 * tests/RoleInkOverOwnSurfaceTest.php — the role's own surface under a new ink (#1125, ruling D5 = B).
 *
 * THE TRAP. Darken a band, recolour every text role as the instructions say, and a role that
 * ships its OWN background fill (the eyebrow pill, a panel, a card) keeps that light surface
 * under the new light ink: 1.82:1 measured on this sprint's probe page. Both values apply
 * correctly; they clash, and the write said `findings: []`.
 *
 * THE RULING (D5 = B). A finding, `udc_role_ink_over_own_surface`, when a role's authored
 * `typography.color` sits over that role's own default `background.fill` (not transparent)
 * with no fill or image authored or preset-supplied for it, AND ONLY when the band's own
 * `_band` background is authored (the author changed the band surface). No contrast maths:
 * the engine names the pairing, the author judges it.
 */

use PHPUnit\Framework\TestCase;

final class RoleInkOverOwnSurfaceTest extends TestCase
{
    private const TYPE = 'udc_role_ink_over_own_surface';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function write(array $udc, string $component = 'section', array $props = ['eyebrow' => 'SECTION', 'title' => 'Dark band', 'body' => 'Body']): array
    {
        $id = pp_create_page('ink', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            ['component' => $component, 'udc' => $udc, 'props' => $props],
        ]]);
        $this->assertTrue($result['ok'], 'premise: written: ' . ($result['error'] ?? ''));
        return [$id, array_values(array_filter($result['findings'] ?? [], static fn (array $f): bool => ($f['type'] ?? '') === self::TYPE))];
    }

    /** The issue's own shape: a dark band, the eyebrow recoloured, the pill left light. */
    public function testTheIssueShapeIsDisclosed(): void
    {
        [$id, $found] = $this->write([
            '_band'     => ['background' => ['fill' => '@color-bg-inverted']],
            'heading'   => ['typography' => ['color' => '@color-bg']],
            'eyebrow'   => ['typography' => ['color' => '@color-accent-on-inverted']],
            'body'      => ['typography' => ['color' => '@color-bg']],
            'body-link' => ['typography' => ['color' => '@color-accent-on-inverted']],
        ]);

        $this->assertCount(1, $found, 'only the eyebrow ships its own surface');
        $this->assertStringContainsString('role "eyebrow"', $found[0]['message']);
        $this->assertStringContainsString('@color-surface-accent', $found[0]['message'], 'names the surviving fill');
        $this->assertStringContainsString('background.fill', $found[0]['message']);
        $this->assertSame(0, $found[0]['index']);

        // The same fact on the stored-state channels (check page, restore): same engine.
        $stored = array_filter(pp_udc_composition_findings(pp_get_composition($id)), static fn ($f) => $f['type'] === self::TYPE);
        $this->assertCount(1, $stored);
    }

    /** D5's negative: no authored `_band` background, no finding, even with the same ink. */
    public function testNoFindingWhenTheBandBackgroundIsNotAuthored(): void
    {
        [, $found] = $this->write(['eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted']]]);
        $this->assertSame([], $found);
    }

    /** The author set the role's own fill too: nothing survives underneath. */
    public function testNoFindingWhenTheRoleFillIsAuthored(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted'], 'background' => ['fill' => 'transparent']],
        ]);
        $this->assertSame([], $found);
    }

    /** A preset that supplies the role's fill counts as covering it. */
    public function testNoFindingWhenAPresetSuppliesTheRoleFill(): void
    {
        $saved = pp_execute_action('save_preset', ['name' => 'probe-pill', 'grain' => 'role', 'udc' => ['background' => ['fill' => '#222222']]]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['_preset' => 'probe-pill', 'typography' => ['color' => '@color-accent-on-inverted']],
        ]);
        $this->assertSame([], $found);
    }

    /** A role with no surface of its own (or a transparent one) is not this trap. */
    public function testNoFindingForARoleWithoutAnOwnSurface(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'heading' => ['typography' => ['color' => '@color-bg']],
            'body'    => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertSame([], $found);
    }

    /** Item grain: a card recoloured on a darkened band keeps its own card surface. */
    public function testACardInkOverTheCardsOwnSurfaceIsDisclosedWithTheItemLocator(): void
    {
        $id = pp_create_page('ink card', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One', 'udc' => ['card' => ['typography' => ['color' => '@color-bg']]]]]],
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = array_values(array_filter($result['findings'] ?? [], static fn ($f) => $f['type'] === self::TYPE));
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $this->assertCount(1, $found);
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "card"', $found[0]['message']);
    }

    /** An image counts as the band surface being authored too. */
    public function testABandImageCountsAsAnAuthoredBandBackground(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $found] = $this->write([
            '_band'   => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.6)']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertCount(1, $found);
    }
}
