<?php
/**
 * tests/UdcDeclarationOrderTest.php
 *
 * The emission-order fix (#976, ruling D4), and the live bug it closed.
 *
 * THE BUG, as it shipped. pp_udc_compile_band() iterated the author's group map
 * directly and PHP preserved insertion order into the resolved declaration table, so
 * the order an author happened to type their keys in became the order the
 * declarations were emitted in. Three groups carry a CSS SHORTHAND alongside its own
 * longhands — `spacing` (`padding` / `padding-top`), `border` (`width` / `width-top`,
 * and the same for style, color and radius) and `background` (`fill`, which emits the
 * `background` shorthand, alongside `position`, `size`, `repeat`) — and a shorthand
 * RESETS every longhand in its family. So:
 *
 *     {"fill":"#fff","size":"cover"}  ->  background:#fff;background-size:cover;
 *     {"size":"cover","fill":"#fff"}  ->  background-size:cover;background:#fff;   <- size GONE
 *
 * Same intent, same grammar, same validation, two different renderings, decided by
 * key order, with nothing on any surface reporting it. That is invariant I35's
 * "declared authoring input silently cancelled by another mechanism", and it was
 * reachable on every band of every v2 component.
 *
 * THE FIX is to rank declarations by the REGISTRY's own order rather than the
 * author's, which needs no per-group special case because pp_udc_groups() already
 * lists every shorthand ahead of the longhands it resets. That property is now
 * load-bearing, so the first test here pins the REGISTRY itself: a future param
 * declared in the wrong place would reintroduce the bug silently.
 *
 * The second property the fix buys is DETERMINISM — two maps differing only in key
 * order emit byte-identical CSS — which is what makes an emission diff mean anything.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UdcDeclarationOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
    }

    private function css(array $group_map): string
    {
        return pp_udc_band_css([
            'component' => 'testimonials',
            'id'        => 'pp-deadbeef',
            'udc'       => ['_band' => $group_map],
        ]);
    }

    /**
     * THE REGISTRY IS THE FIX. Every shorthand must be declared before the longhands
     * it resets, because _pp_udc_property_rank() derives emission order from exactly
     * this list. Enumerated per family rather than inferred, so a new `padding-block`
     * or `border-inline` has to be placed deliberately.
     */
    public function testEveryShorthandIsDeclaredBeforeItsLonghands(): void
    {
        $families = [
            'spacing' => [
                'padding' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'],
                'margin'  => ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
                'gap'     => ['row-gap', 'column-gap'],
            ],
            'border' => [
                'width'  => ['width-top', 'width-right', 'width-bottom', 'width-left'],
                'style'  => ['style-top', 'style-right', 'style-bottom', 'style-left'],
                'color'  => ['color-top', 'color-right', 'color-bottom', 'color-left'],
                'radius' => ['radius-top-left', 'radius-top-right', 'radius-bottom-right', 'radius-bottom-left'],
            ],
            'background' => [
                // `fill` emits the `background` shorthand, which resets background-color,
                // background-image, background-position, background-size AND
                // background-repeat. Every other param in the group is one of those.
                'fill' => ['position', 'size', 'repeat', 'image'],
            ],
        ];

        $groups = pp_udc_groups();
        $checked = 0;
        foreach ($families as $group => $pairs) {
            $order = array_flip(array_keys($groups[$group]['params']));
            foreach ($pairs as $shorthand => $longhands) {
                $this->assertArrayHasKey($shorthand, $order, "{$group}.{$shorthand} must exist");
                foreach ($longhands as $longhand) {
                    $this->assertArrayHasKey($longhand, $order, "{$group}.{$longhand} must exist");
                    $this->assertLessThan(
                        $order[$longhand],
                        $order[$shorthand],
                        "{$group}.{$shorthand} emits a shorthand that resets {$group}.{$longhand}, so it "
                        . 'MUST be declared before it in pp_udc_groups() — emission order is derived '
                        . 'from that declaration order, and a shorthand printed second erases the longhand.'
                    );
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(20, $checked, 'the sweep must actually reach the shipped families');
    }

    /**
     * THE RED-PROOF, NOW GREEN. Each of these three emitted the shorthand LAST before
     * the fix, erasing the longhand the author had just written.
     *
     * @dataProvider silentLossProvider
     */
    public function testAShorthandNoLongerErasesALonghandWrittenBeforeIt(
        array $lossy,
        string $property,
        string $value
    ): void {
        $css = $this->css($lossy);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote($property, '/') . ':' . preg_quote($value, '/') . '[;}]/',
            $css,
            "{$property}:{$value} was silently erased by a shorthand written after it"
        );
    }

    public static function silentLossProvider(): array
    {
        return [
            'background: size written before fill' =>
                [['background' => ['size' => 'cover', 'fill' => '#ffffff']], 'background-size', 'cover'],
            'spacing: padding-top written before padding' =>
                [['spacing' => ['padding-top' => '40px', 'padding' => '4px']], 'padding-top', '40px'],
            'border: width-top written before width' =>
                [['border' => ['width-top' => '6px', 'width' => '1px']], 'border-top-width', '6px'],
            'border: radius corner written before radius' =>
                [['border' => ['radius-top-left' => '12px', 'radius' => '2px']], 'border-top-left-radius', '12px'],
        ];
    }

    /**
     * THE DETERMINISM PIN. Author key order must no longer affect emission AT ALL —
     * not just in the cases that used to lose a value. This is the property that makes
     * a CSS diff between two builds mean something.
     *
     * @dataProvider keyOrderProvider
     */
    public function testTheSameMapInTwoKeyOrdersEmitsByteIdenticalCss(array $a, array $b): void
    {
        $this->assertSame($this->css($a), $this->css($b));
    }

    public static function keyOrderProvider(): array
    {
        return [
            'background' => [
                ['background' => ['fill' => '#fff', 'position' => 'center', 'size' => 'cover', 'repeat' => 'no-repeat']],
                ['background' => ['repeat' => 'no-repeat', 'size' => 'cover', 'position' => 'center', 'fill' => '#fff']],
            ],
            'spacing' => [
                ['spacing' => ['padding' => '4px', 'padding-top' => '40px', 'gap' => '1rem']],
                ['spacing' => ['gap' => '1rem', 'padding-top' => '40px', 'padding' => '4px']],
            ],
            'across groups' => [
                ['typography' => ['color' => '#111'], 'spacing' => ['padding' => '4px'], 'border' => ['width' => '1px']],
                ['border' => ['width' => '1px'], 'typography' => ['color' => '#111'], 'spacing' => ['padding' => '4px']],
            ],
        ];
    }

    /**
     * The ordering is applied at the ONE place a block is built, so every emission
     * path inherits it — band, chrome and the component defaults tier alike. A fix
     * wired into only the band path would leave chrome carrying the original bug.
     */
    public function testChromeInheritsTheSameOrdering(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            '_version' => 1,
            'nav'      => ['_band' => ['background' => ['size' => 'cover', 'fill' => '#ffffff']]],
        ]);
        $this->assertStringContainsString(
            '{background:#ffffff;background-size:cover;}',
            pp_udc_chrome_authored_css()
        );
    }

    /**
     * A property the registry no longer declares sorts LAST and keeps a stable
     * relative order, rather than jumping ahead of a shorthand it might belong to.
     * Stored data outlives the registry, so this path is reachable.
     */
    public function testAnUnrankedPropertyDoesNotJumpAheadOfAShorthand(): void
    {
        $rank = _pp_udc_property_rank();
        $this->assertArrayHasKey('background', $rank);
        $this->assertArrayHasKey('background-size', $rank);
        $this->assertLessThan($rank['background-size'], $rank['background']);
        // Sorting is total and stable for names the registry does not know.
        $sorted = _pp_udc_sort_declarations([
            'zzz-unknown'     => ['css' => 'a', 'source' => 'udc', 'literal' => 'a'],
            'background-size' => ['css' => 'cover', 'source' => 'udc', 'literal' => 'cover'],
            'aaa-unknown'     => ['css' => 'b', 'source' => 'udc', 'literal' => 'b'],
            'background'      => ['css' => '#fff', 'source' => 'udc', 'literal' => '#fff'],
        ]);
        $this->assertSame(
            ['background', 'background-size', 'aaa-unknown', 'zzz-unknown'],
            array_keys($sorted)
        );
    }
}
