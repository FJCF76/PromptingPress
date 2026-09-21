<?php
/**
 * tests/TypographyRoleTest.php
 *
 * Typography role surface (#90): mono/meta/label/kicker roles exposed as
 * design tokens, utility classes, and an optional grid-item `text_role` prop
 * reflected in rendered output.
 */

use PHPUnit\Framework\TestCase;

class TypographyRoleTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
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

    // ── Tokens ────────────────────────────────────────────────────────────

    public function testBaseCssDeclaresRoleTokens(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        foreach ([
            '--font-mono',
            '--text-meta-size', '--text-meta-color',
            '--text-label-size', '--text-label-weight', '--text-label-spacing',
            '--text-kicker-size', '--text-kicker-weight', '--text-kicker-spacing', '--text-kicker-color',
        ] as $token) {
            $this->assertStringContainsString($token, $base, "base.css must declare {$token}.");
        }
    }

    public function testCodeAndPreUseMonoToken(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/font-family:\s*var\(--font-mono\)/',
            $base,
            'code/pre should consume var(--font-mono) rather than a hard-coded stack.'
        );
    }

    // ── Heading letter-spacing token (#467) ────────────────────────────────

    public function testBaseCssDeclaresHeadingLetterSpacingToken(): void
    {
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertStringContainsString(
            '--letter-spacing-heading',
            $base,
            'base.css must declare --letter-spacing-heading so a brand can set heading tracking.'
        );
    }

    public function testHeadingRuleRoutesLetterSpacingThroughToken(): void
    {
        // The shared h1-h6 rule must consume the token, not a literal — otherwise the
        // token exists but nothing reads it. Pins that letter-spacing routes through
        // var(--letter-spacing-heading) and no bare `letter-spacing: -0.03em` remains.
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/letter-spacing:\s*var\(--letter-spacing-heading\)/',
            $base,
            'the h1-h6 rule should consume var(--letter-spacing-heading), not a literal.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/letter-spacing:\s*-0\.03em/',
            $base,
            'no heading rule should hard-code letter-spacing: -0.03em once the token exists.'
        );
    }

    // ── Utility classes ───────────────────────────────────────────────────

    public function testUtilitiesDeclareRoleClasses(): void
    {
        $util = file_get_contents($this->themeRoot . '/assets/css/utilities.css');
        foreach (['.text-mono', '.text-meta', '.text-label', '.text-kicker'] as $class) {
            $this->assertStringContainsString($class, $util, "utilities.css must declare {$class}.");
        }
    }

    // ── Grid item text_role (prop, via update_component) ──────────────────

    /**
     * `items[].text_role` IS RETIRED (#1101), AND THE CAPABILITY IS WIDER WITHOUT IT.
     *
     * WHAT THE TWO TESTS THIS REPLACES PROVED. `testGridItemTextRoleEmitsClass` pinned
     * that a stored `text_role` reached the rendered markup as a utility class —
     * `class="grid__item-text text-kicker"` — which is the only thing that made the prop
     * do anything at all. `testGridSchemaDeclaresTextRoleEnum` pinned its declaration:
     * type `enum`, values `[mono, meta, label, kicker]`, and `strict` — the last one
     * load-bearing, because #600's nested write-path gate SKIPS a field that does not
     * declare it, and without `strict` the four roles were advisory rather than enforced.
     *
     * WHY THE SUBJECT IS GONE, and it is a measurement rather than a tidy-up. grid's
     * `retired_props` records all four values measured at desktop before the prop went:
     * `mono` and `meta` rendered BYTE-IDENTICALLY to the default body text, because the
     * premium typography tier's own `.grid__item-text` rule out-ranked both presets — so
     * two of the four values had no rendered effect at any viewport above 767px, and the
     * schema's claim that they "also set a preset text color" was false there. Only
     * `label` and `kicker` changed anything. A closed four-value enum, half of it dead, is
     * the accepted-stored-ignored class the v2 engine exists to close.
     *
     * WHERE THE CLAIM WENT: the `card-text` role, addressed through that ONE item's own
     * `udc` map (Addendum B), or a named preset (#1016). Per-item typography is exactly
     * what an item map expresses, and it reaches every typography parameter rather than
     * four bundles. That is asserted here end to end — schema declaration, write, storage,
     * and the EMITTED rule — because "wider by construction" is the claim a rebuild is
     * most tempted to assert without checking.
     *
     * The two render-side tests below are untouched and still live: they pin that no
     * stored string reaches a class attribute on this element, which is a claim about the
     * renderer that a retired prop does not settle.
     */
    public function testPerItemTypographyIsTheCardTextRoleOnThatItemsOwnUdcMap(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);

        // 1. THE PROP IS GONE AND ITS ROUTE IS STATED. A retired name with no route is a
        //    dead end for the author who meets it on a page written last year.
        $this->assertArrayNotHasKey(
            'text_role',
            $schema['props']['items']['items'] ?? [],
            'grid declares `items[].text_role` again — restore the enum/strict guard this '
            . 'assertion replaced in the same commit, or the prop ships unenforced (#600)'
        );
        $route = $schema['retired_props']['items[].text_role'] ?? null;
        $this->assertIsString($route, 'grid must still say where `items[].text_role` went');
        $this->assertStringContainsString('card-text', $route, 'the route must name the role that replaced it');

        // 2. THE ROLE IS ADDRESSABLE PER ITEM. `item_roles` is what the engine reads to
        //    decide that; a band-only role here would make the route above a lie.
        $this->assertContains(
            'card-text',
            $schema['item_roles']['roles'] ?? [],
            '`card-text` must be addressable from a single items[] entry, or the retirement '
            . 'route points at a band-grain role and per-item typography is simply gone'
        );
        $this->assertContains(
            'typography',
            $schema['roles']['card-text']['groups'] ?? [],
            '`card-text` must permit `typography`, or the four retired values have no home'
        );

        // 3. THE ROUND TRIP, THROUGH THE REAL WRITE PATH. Raw meta seeding proves nothing
        //    about authorability (Section 14.1), so this goes through create_page.
        $result = pp_execute_action('create_page', [
            'title'       => 'Per-item typography',
            'composition' => [[
                'component' => 'grid',
                'props'     => ['items' => [[
                    'title' => 'A',
                    'text'  => 'Body',
                    // The `kicker` preset's two measured effects, written directly.
                    'udc'   => ['card-text' => ['typography' => [
                        'letter-spacing' => '0.08em',
                        'transform'      => 'uppercase',
                    ]]],
                ]]],
            ]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'the per-item map must be writable');

        $stored = pp_get_composition((int) $result['target']['post_id']);
        $this->assertSame(
            '0.08em',
            $stored[0]['props']['items'][0]['udc']['card-text']['typography']['letter-spacing'] ?? null,
            'stored as authored'
        );

        // 4. AND IT REACHES THE PAGE, not merely storage — the assert-the-emission rule.
        //    The item tier is addressed by the id the engine MINTED on write, which is the
        //    half that makes the design travel with the card rather than with position 2.
        $itemId = $stored[0]['props']['items'][0]['id'] ?? null;
        $this->assertIsString($itemId, 'the engine must mint an item id for an entry carrying a `udc` map');

        $css = pp_udc_band_css($stored[0]);
        $this->assertStringContainsString('letter-spacing:0.08em;', $css, 'the authored value must emit');
        $this->assertStringContainsString('text-transform:uppercase;', $css);
        $this->assertStringContainsString(
            '[data-pp-item="' . $itemId . '"]',
            $css,
            'the rule must be scoped to THIS item — an unscoped rule would restyle every card '
            . 'and would be a worse capability than the prop it replaced'
        );
    }

    /**
     * The RENDER-side coercion, which #600 narrowed the reach of without removing.
     * The write path now rejects an out-of-set role (the strict gate walks nested
     * enum fields since #600, pinned in SchemaValidationTest and ActionsTest), so
     * this value can only arrive through a NON-validating path: a raw database
     * write, or a restore_composition of an old snapshot, which by rule restores
     * verbatim and never blocks (#233). The allowlist is what keeps an arbitrary
     * stored string out of a class attribute on those paths, so it stays.
     */
    public function testGridItemInvalidTextRoleIgnored(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'A', 'text' => 'Body', 'text_role' => 'bogus']],
        ]);
        $this->assertStringContainsString('class="grid__item-text"', $html);
        $this->assertStringNotContainsString('text-bogus', $html);
    }

    public function testGridItemNoTextRoleIsPlain(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'A', 'text' => 'Body']],
        ]);
        $this->assertStringContainsString('class="grid__item-text"', $html);
    }

    /**
     * THE TOKEN AND UTILITY SURFACE OUTLIVED THE PROP, and the distinction is worth
     * stating: `items[].text_role` was one CONSUMER of the #90 roles, not the roles
     * themselves. The tokens and the four utility classes are pinned above and still
     * ship — an author writes `text-kicker` into their own markup, and base.css still
     * defines every `--text-*` the class reads. What retired at #1101 is the grid-item
     * PROP that offered four of them as a closed enum.
     *
     * Pinned as its own assertion because "the prop is gone" and "the roles are gone" are
     * indistinguishable otherwise, and the second would be a real capability loss.
     */
    public function testTheFourTypographyRolesSurviveTheRetiredProp(): void
    {
        $util = file_get_contents($this->themeRoot . '/assets/css/utilities.css');
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');

        foreach (['mono', 'meta', 'label', 'kicker'] as $role) {
            $this->assertStringContainsString(
                ".text-{$role}",
                $util,
                "the `{$role}` utility class is gone. It was one of the four values "
                . '`items[].text_role` offered, but the class is the primary surface and the '
                . 'prop was only ever a shortcut to it (#90).'
            );
        }
        // The two values the retirement record measured as HAVING an effect must still
        // have the tokens that produced it, or the migration route loses what it promised.
        $this->assertStringContainsString('--text-kicker-spacing', $base);
        $this->assertStringContainsString('--text-label-spacing', $base);
    }
}
