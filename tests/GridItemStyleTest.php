<?php
/**
 * tests/GridItemStyleTest.php
 *
 * Per-item grid card style overrides (issue 306): a single card can carry its
 * own `style` map (props.items[].style) rendered as inline CSS custom properties
 * on THAT card's .grid__item element. Because every consuming rule reads
 * var(--slot, fallback) (grid.php + the .grid__item rules in components.css) and
 * NO rule sets the custom property via a selector, an inline custom property on
 * one .grid__item wins over the grid-level value by cascade proximity.
 *
 * These are rendered-output pins:
 *   - the styled card carries its per-item custom properties inline;
 *   - sibling cards do not;
 *   - a grid-level slot on the section and a per-item override on one card
 *     coexist, with the item value on the item element (the override-wins pin
 *     the acceptance criteria call for);
 *   - values are escaped and the injection guard drops dangerous values.
 *
 * The CSS var(--slot, fallback) consumption itself is pinned by
 * StyleSlotContractTest (issue 305). Together they prove item-level overrides
 * both reach the element and are honored by the renderer's CSS.
 */

use PHPUnit\Framework\TestCase;

class GridItemStyleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    /**
     * Splits rendered grid HTML into one string per <li class="grid__item"...>,
     * so a test can assert what landed on a specific card vs its siblings.
     *
     * @return string[]  One entry per card, in document order.
     */
    private function cards(string $html): array
    {
        $parts = preg_split('/(?=<li class="grid__item)/', $html);
        return array_values(array_filter($parts, static fn ($p) => str_contains($p, 'grid__item"') || str_contains($p, 'grid__item "')));
    }

    public function testItemStyleRendersInlineOnThatCardOnly(): void
    {
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Plain'],
                ['title' => 'Dark', 'style' => ['--grid-card-bg' => '#0f172a']],
            ],
        ]);

        $cards = $this->cards($html);
        $this->assertCount(2, $cards, 'Expected exactly two rendered cards.');

        // The styled card carries its inline custom property.
        $this->assertStringContainsString('style="--grid-card-bg: #0f172a;"', $cards[1]);

        // The sibling card has no inline style at all.
        $this->assertStringNotContainsString('style=', $cards[0], 'Only the styled card should carry inline custom properties.');
    }

    public function testItemStyleWinsOverGridLevelStyle(): void
    {
        // Grid-level --grid-card-bg is set on the <section>; one card overrides it.
        // Both must be present, each on its own element — the item value on the
        // .grid__item (nearer in the cascade) is what wins for that card.
        $html = $this->render('grid', [
            '__pp_style' => ['--grid-card-bg' => 'var(--color-surface)'],
            'items'      => [
                ['title' => 'Default card'],
                ['title' => 'Panel', 'style' => ['--grid-card-bg' => '#0f172a']],
            ],
        ]);

        // Grid-level value lives on the section wrapper.
        $this->assertMatchesRegularExpression(
            '/<section[^>]*style="[^"]*--grid-card-bg: var\(--color-surface\);?"/',
            $html,
            'Grid-level slot must render on the section element.'
        );

        // Item-level override lives on the panel card's <li>.
        $cards = $this->cards($html);
        $this->assertStringContainsString('style="--grid-card-bg: #0f172a;"', $cards[1]);
        // The default card does NOT re-declare the slot inline, so it inherits the
        // grid-level value — proving the override is per-card, not global.
        $this->assertStringNotContainsString('--grid-card-bg', $cards[0]);
    }

    public function testDarkPanelCardExpressible(): void
    {
        // Page-136 case 1: a dark CTA panel beside light checklist cards.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Para equipos', 'bullets' => ['Rápido', 'Honesto']],
                ['title' => '¿Para quién es?', 'text' => 'Empezá hoy', 'style' => [
                    '--grid-card-bg'          => '#0f172a',
                    '--grid-item-title-color' => '#f8fafc',
                    '--grid-item-text-color'  => '#cbd5e1',
                ]],
            ],
        ]);

        $panel = $this->cards($html)[1];
        $this->assertStringContainsString('--grid-card-bg: #0f172a', $panel);
        $this->assertStringContainsString('--grid-item-title-color: #f8fafc', $panel);
        $this->assertStringContainsString('--grid-item-text-color: #cbd5e1', $panel);
    }

    public function testGreenTerminalCardExpressible(): void
    {
        // Page-136 case 2: a green-on-dark mono terminal card. text_role=mono adds
        // .text-mono (font only); the green comes from the per-item text color slot.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Feature'],
                ['text' => '$ deploy --now', 'text_role' => 'mono', 'style' => [
                    '--grid-card-bg'         => '#0b0f0a',
                    '--grid-item-text-color' => '#22c55e',
                ]],
            ],
        ]);

        $terminal = $this->cards($html)[1];
        $this->assertStringContainsString('--grid-card-bg: #0b0f0a', $terminal);
        $this->assertStringContainsString('--grid-item-text-color: #22c55e', $terminal);
        $this->assertStringContainsString('class="grid__item-text text-mono"', $terminal);
    }

    public function testUnknownItemSlotIsNotRenderedInline(): void
    {
        // Defense in depth: even if an unknown slot bypassed the action-layer
        // validator (e.g. raw meta write), pp_render_style_vars drops it because
        // it is not a declared grid slot.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'style' => ['--grid-card-not-a-slot' => '#000000']],
            ],
        ]);

        $this->assertStringNotContainsString('--grid-card-not-a-slot', $html);
        $this->assertStringNotContainsString('style=', $this->cards($html)[0], 'An all-unknown item style map must render no inline style attribute.');
    }

    public function testInjectionValueIsDroppedAtRender(): void
    {
        // The render-time injection guard rejects values with { } ; < >.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'style' => ['--grid-card-bg' => '#000; } body { display:none']],
            ],
        ]);

        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringNotContainsString('<li class="grid__item" style=', $html, 'A guarded value must not reach the inline style attribute.');
    }

    // ── #357 — --grid-item-text-align (align-typed content alignment) ────────

    public function testItemTextAlignRendersInlineOnThatCardOnly(): void
    {
        // Page contact-card case: center one card's content stack, leave the other
        // at the historical left default.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Left card'],
                ['title' => 'Contact', 'text' => 'hola@example.com', 'style' => ['--grid-item-text-align' => 'center']],
            ],
        ]);

        $cards = $this->cards($html);
        // The slot renders on the card, and the link/button follows via the derived
        // --pp-grid-link-align companion (#361: center -> center) so a centered card
        // is fully centered — the operator sets ONE value, both text and link align.
        $this->assertStringContainsString('style="--grid-item-text-align: center; --pp-grid-link-align: center;"', $cards[1]);
        // The sibling emits no inline style — it renders byte-identically to today.
        $this->assertStringNotContainsString('style=', $cards[0]);
    }

    public function testItemTextAlignRendersGridWideOnTheSection(): void
    {
        // item_eligible slot set at grid level aligns every card — rendered on the
        // section wrapper, consumed by .grid__item-body via var(--slot, left).
        $html = $this->render('grid', [
            '__pp_style' => ['--grid-item-text-align' => 'right'],
            'items'      => [['title' => 'A'], ['title' => 'B']],
        ]);

        $this->assertMatchesRegularExpression(
            '/<section[^>]*style="[^"]*--grid-item-text-align: right\b/',
            $html,
            'A grid-level align slot must render on the section element.'
        );
        // The link companion (#361) rides on the same section wrapper so every card's
        // link follows the grid-wide alignment (right -> flex-end).
        $this->assertMatchesRegularExpression(
            '/<section[^>]*style="[^"]*--pp-grid-link-align: flex-end\b/',
            $html,
            'A grid-level align slot must also derive the link-align companion on the section.'
        );
    }

    public function testUnsetItemTextAlignEmitsNoInlineProperty(): void
    {
        // Byte-identical unset contract (#357): an item that does not set the slot
        // emits no inline custom property, so the CSS fallback keeps it left.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Plain'],
                ['title' => 'Also plain', 'text' => 'no style'],
            ],
        ]);

        $this->assertStringNotContainsString('--grid-item-text-align', $html);
        // No slot => no link companion either, so the CSS flex-start fallback keeps
        // the link byte-identically left-pinned (#361 default parity).
        $this->assertStringNotContainsString('--pp-grid-link-align', $html);
        foreach ($this->cards($html) as $card) {
            $this->assertStringNotContainsString('style=', $card);
        }
    }

    public function testInvalidItemTextAlignIsDroppedAtRender(): void
    {
        // The #330 render boundary re-validates through the shared engine: an
        // invalid alignment keyword ('middle' is not a text-align value) is dropped,
        // and because it is the card's only slot no inline style attribute renders.
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'style' => ['--grid-item-text-align' => 'middle']],
            ],
        ]);

        $this->assertStringNotContainsString('--grid-item-text-align', $html);
        // The same render boundary gates the companion (#361), so a rejected value
        // derives no --pp-grid-link-align either — no half-applied alignment.
        $this->assertStringNotContainsString('--pp-grid-link-align', $html);
        $this->assertStringNotContainsString('style=', $this->cards($html)[0]);
    }

    /**
     * #361 — the derived link-align companion maps every accepted `align` keyword to
     * its physical `align-self` equivalent (left/start/justify -> flex-start,
     * center -> center, right/end -> flex-end). Table-driven so every branch of the
     * map in pp_grid_link_align_decl() is exercised, including the ones that map to
     * the flex-start default (which MUST still emit, so a per-card override can reset
     * an inherited grid-level companion).
     *
     * @dataProvider linkAlignKeywordProvider
     */
    public function testItemTextAlignDerivesLinkCompanion(string $keyword, string $expected): void
    {
        $html  = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'link_url' => '/x', 'style' => ['--grid-item-text-align' => $keyword]],
            ],
        ]);
        $card = $this->cards($html)[0];

        $this->assertStringContainsString('--grid-item-text-align: ' . $keyword . ';', $card);
        $this->assertStringContainsString('--pp-grid-link-align: ' . $expected . ';', $card);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function linkAlignKeywordProvider(): array
    {
        return [
            'left -> flex-start'    => ['left', 'flex-start'],
            'start -> flex-start'   => ['start', 'flex-start'],
            'justify -> flex-start' => ['justify', 'flex-start'],
            'center -> center'      => ['center', 'center'],
            'right -> flex-end'     => ['right', 'flex-end'],
            'end -> flex-end'       => ['end', 'flex-end'],
        ];
    }

    public function testPerCardAlignResetsInheritedGridCompanion(): void
    {
        // A grid-level `center` centers every card's link; one card overridden back to
        // `left` must re-pin ITS link left. Because the companion is emitted for the
        // recognized `left` value too (-> flex-start) on the .grid__item, it overrides
        // the section's --pp-grid-link-align by cascade proximity — the card is not
        // left with a centered link inherited from the grid (#361 per-card parity).
        $html = $this->render('grid', [
            '__pp_style' => ['--grid-item-text-align' => 'center'],
            'items'      => [
                ['title' => 'Inherits center', 'link_url' => '/a'],
                ['title' => 'Overridden left', 'link_url' => '/b', 'style' => ['--grid-item-text-align' => 'left']],
            ],
        ]);

        // Section carries the centered companion for the inheriting card.
        $this->assertMatchesRegularExpression(
            '/<section[^>]*style="[^"]*--pp-grid-link-align: center\b/',
            $html
        );
        // The overridden card re-pins its own link left on the .grid__item.
        $overridden = $this->cards($html)[1];
        $this->assertStringContainsString('--pp-grid-link-align: flex-start;', $overridden);
    }
}
