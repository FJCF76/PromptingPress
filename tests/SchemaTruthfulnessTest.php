<?php
/**
 * tests/SchemaTruthfulnessTest.php — issue 581.
 *
 * The gate's premise: everything an agent can read from schema.json must be TRUE,
 * because schema legibility IS the product surface — the harness agent is thin by
 * design and carries no product knowledge. This file pins the parts of that premise
 * that are checkable without re-deriving the whole cascade:
 *
 *   A-11  the specific FALSE defaults this issue removed cannot come back;
 *   A-12  the chrome pair keeps its custom properties out of the design-token array;
 *   A-16  the `id` surface is exactly the ten section-level components;
 *   A-18  the two new twin slots are AUTHORABLE (Section 14.1) and REACHABLE, and every
 *         completed pair cross-references its counterpart;
 *   A-28  the two inert emissions are gone and their live counterparts are untouched.
 *
 * Deliberately NOT here: a mechanical `default == CSS-fallback` equivalence check.
 * tests/StyleSlotContractTest.php:26-31 records that it was considered and declined,
 * because one slot is legitimately consumed with different fallbacks per theme variant.
 * That is precisely why the values have to be written truthfully by hand — and why the
 * pins below name the specific falsehoods rather than asserting a general equation.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SchemaTruthfulnessTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function allSchemas(): array
    {
        $schemas = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $file) {
            $schemas[basename(dirname($file))] = json_decode(file_get_contents($file), true);
        }
        $this->assertNotEmpty($schemas, 'no component schemas found');
        return $schemas;
    }

    private function slots(string $component): array
    {
        return $this->allSchemas()[$component]['styling']['style_slots'] ?? [];
    }

    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    private function renderStored(int $id): string
    {
        ob_start();
        foreach (pp_get_composition($id) as $item) {
            $props = $item['props'] ?? [];
            if (!empty($item['style'])) {
                $props['__pp_style'] = $item['style'];
            }
            pp_get_component((string) $item['component'], $props);
        }
        return ob_get_clean();
    }

    // ── A-11: the removed falsehoods cannot return ──────────────────────────

    /**
     * `inherit` was the declared default of --section-heading-size, --cta-heading-size
     * and --grid-heading-size while all three actually render var(--pp-band-heading-size).
     * It is not a near-miss: it is the exact value the #436 shared-scale work removed from
     * the STYLESHEET (css-lint pins that no band heading falls back to `inherit`), and
     * re-declaring it in a schema would re-document the 16px mobile collapse that #436 fixed.
     *
     * 1.875rem is worse — faq and stats declared a literal that appears NOWHERE in the CSS,
     * and css-lint pins that it must not reappear there either.
     */
    public function testNoSizeSlotDeclaresARemovedFalseDefault(): void
    {
        $offenders = [];
        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $def) {
                // EVERY `-size` slot, not just the heading family. Scoping this to
                // `-heading-size` is how --cta-body-size kept its `inherit` through the
                // first pass of this issue while its twin --section-body-size was fixed:
                // the falsehood is the VALUE, and the value does not care which family the
                // slot belongs to. (`inherit` remains legitimate on font-family and on the
                // embed body colour, where the element really does inherit — those are not
                // `-size` slots, which is why the filter is the family and the assertion is
                // the value.)
                if (!str_ends_with($slot, '-size')) {
                    continue;
                }
                $default = (string) ($def['default'] ?? '');
                if ($default === 'inherit' || $default === '1.875rem') {
                    $offenders[] = "{$component} {$slot} => {$default}";
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            "A `-size` slot re-declared a default that renders nowhere.\n"
            . "`inherit` is the 16px mobile collapse #436 removed from the stylesheet; "
            . "1.875rem is a literal that has never appeared in components.css. Both are "
            . "false as effective-default descriptions. State the real default."
        );
    }

    /**
     * The nine band components share ONE heading scale (#436), so every one of their
     * heading-size slots must default to it. hero is deliberately exempt: its H1 carries
     * its own larger clamp so it outranks the band headings below it, and the schema now
     * says so rather than leaving an agent to infer it.
     */
    public function testEveryBandHeadingSizeDefaultsToTheSharedScale(): void
    {
        $bands = ['section', 'grid', 'cta', 'faq', 'stats', 'table', 'logos', 'embed', 'testimonials'];
        foreach ($bands as $component) {
            $slot = "--{$component}-heading-size";
            $slots = $this->slots($component);
            $this->assertArrayHasKey($slot, $slots, "{$component} must declare {$slot}");
            $this->assertSame(
                'var(--pp-band-heading-size)',
                $slots[$slot]['default'],
                "{$slot} must state the shared band-heading scale as its effective default."
            );
        }

        $hero = $this->slots('hero')['--hero-heading-size'];
        $this->assertNotSame(
            'var(--pp-band-heading-size)',
            $hero['default'],
            'hero is EXEMPT from the shared band scale by design; do not fold it in here.'
        );
        $this->assertStringContainsString(
            'EXEMPT',
            $hero['description'],
            'hero must SAY it is exempt — an undocumented exception reads as an oversight.'
        );
    }

    /**
     * The "leave it alone" sentence (#436 contract + AI_RULES.md's "in design tokens or
     * component CSS, not in composition overrides"). Presenting per-band heading size and
     * colour as a floor with no such guidance points an agent at the ten-slot-write route
     * before the one-token-write route.
     */
    public function testHeadingSizeAndColourSlotsSayWhenToLeaveThemAlone(): void
    {
        $missing = [];
        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $def) {
                if (!preg_match('/-heading-(size|color)$/', $slot)) {
                    continue;
                }
                if (!str_contains((string) ($def['description'] ?? ''), 'LEAVE IT UNSET')) {
                    $missing[] = "{$component} {$slot}";
                }
            }
        }
        $this->assertSame(
            [],
            $missing,
            'Every heading size/colour slot must say when NOT to use it, naming the one '
            . 'update_design_token write that retunes the whole site at once.'
        );
    }

    /**
     * The "leave it alone" sentence is shared boilerplate, and shared boilerplate is how a
     * true sentence becomes a false one: the heading-colour variant reads
     * "the inverted variant supplies its own light default", which is a claim about a
     * theme class. `table` declares `variant_classes: []` — it has no theme classes at all —
     * and got the sentence anyway on the first pass of this issue, shipping a fresh
     * falsehood inside the gate whose whole premise is that schema text must be true.
     * Couple the claim to the class list so the paste cannot happen silently again.
     */
    public function testOnlyComponentsWithAnInvertedVariantClaimOne(): void
    {
        foreach ($this->allSchemas() as $component => $schema) {
            $variants = $schema['styling']['variant_classes'] ?? [];
            $hasInverted = (bool) preg_grep('/--inverted$/', $variants);
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $def) {
                if (!str_contains((string) ($def['description'] ?? ''), 'inverted variant supplies')) {
                    continue;
                }
                $this->assertTrue(
                    $hasInverted,
                    "{$component} {$slot} claims an inverted variant supplies a light default, but "
                    . "{$component} declares no *--inverted class in styling.variant_classes. Either "
                    . 'the claim is boilerplate that does not apply here, or the variant list is wrong.'
                );
            }
        }
    }

    /**
     * VALUE pin for every default this issue corrected. The phrase-presence tests above
     * would all still pass if someone reverted `1.65rem` back to `var(--space-lg)`, so
     * without this the corrections are a one-time snapshot rather than a contract. Each
     * pair below was read off the composed-page (`main > .`) rule in components.css at the
     * >=768px desktop tier, which is the configuration the stated convention describes.
     *
     * This is NOT the mechanical `default == CSS-fallback` check declined at
     * StyleSlotContractTest:26-31 — that one would compare EVERY slot against EVERY
     * fallback, which is wrong by design because a slot is legitimately consumed with
     * different fallbacks per theme variant. This is a hand-audited list of the specific
     * values this gate established.
     *
     * @dataProvider correctedEffectiveDefaults
     */
    public function testCorrectedDefaultsStayCorrected(string $component, string $slot, string $expected): void
    {
        $slots = $this->slots($component);
        $this->assertArrayHasKey($slot, $slots, "{$component} must declare {$slot}");
        $this->assertSame(
            $expected,
            $slots[$slot]['default'],
            "{$slot} was corrected to the effective default '{$expected}' by issue 581. If the "
            . 'RENDERED default genuinely changed, update this pin in the same change; if not, '
            . 'this is a regression back to a value that renders nowhere.'
        );
    }

    public static function correctedEffectiveDefaults(): array
    {
        return [
            ['section', '--section-heading-size', 'var(--pp-band-heading-size)'],
            ['cta', '--cta-heading-size', 'var(--pp-band-heading-size)'],
            ['grid', '--grid-heading-size', 'var(--pp-band-heading-size)'],
            ['faq', '--faq-heading-size', 'var(--pp-band-heading-size)'],
            ['stats', '--stats-heading-size', 'var(--pp-band-heading-size)'],
            ['section', '--section-body-size', '1.065rem'],
            ['section', '--section-body-weight', '430'],
            ['section', '--section-body-measure', '40rem'],
            ['section', '--section-body-color', 'var(--color-text-secondary)'],
            ['section', '--section-heading-margin-bottom', '1.65rem'],
            ['cta', '--cta-bg', 'var(--color-surface)'],
            ['cta', '--cta-border-width', '1px'],
            ['cta', '--cta-border-color', 'var(--color-border)'],
            ['cta', '--cta-body-color', 'var(--color-text-secondary)'],
            ['cta', '--cta-body-size', '1.04rem'],
            ['grid', '--grid-heading-margin-bottom', '1.65rem'],
            ['grid', '--grid-gap', '1rem'],
            ['grid', '--grid-item-bg', 'linear-gradient(180deg, var(--color-bg) 0%, var(--color-surface) 100%)'],
            ['grid', '--grid-item-shadow', '0 10px 24px rgba(15, 23, 42, 0.055)'],
            ['grid', '--grid-item-radius', '4px'],
            ['grid', '--grid-item-padding', '2rem'],
            ['grid', '--grid-item-title-size', '1.14rem'],
            ['grid', '--grid-item-text-color', 'var(--color-text-secondary)'],
            ['faq', '--faq-heading-margin-bottom', '1.65rem'],
            ['faq', '--faq-answer-color', 'var(--color-text-secondary)'],
        ];
    }

    /**
     * The convention itself has to ship where the agent reads it, or the corrected values
     * are a snapshot rather than a rule and the next slot drifts again. All THREE surfaces
     * are pinned together: the runtime prompt an agent actually receives, and the two
     * instruction files a human or agent reads when adding a slot. Pinning only one lets
     * the other two drift, which is the same mechanism that produced the false defaults.
     */
    public function testTheDefaultConventionIsStatedOnEveryAuthoringSurface(): void
    {
        $context = pp_ai_system_prompt();
        $this->assertStringContainsString('EFFECTIVE default', $context);
        $this->assertStringContainsString("the component's default configuration, at desktop", $context);
        $this->assertStringContainsString('the `description` enumerates the alternatives', $context);

        $styleDoc = file_get_contents($this->themeRoot . '/ai-instructions/style-component.md');
        $this->assertStringContainsString('**effective** default', $styleDoc);
        $this->assertStringContainsString('replaces every branch at once', $styleDoc);

        $addDoc = file_get_contents($this->themeRoot . '/ai-instructions/add-component.md');
        $this->assertStringContainsString('**effective** default', $addDoc);
        $this->assertStringContainsString('positional twin', $addDoc);
    }

    /**
     * AI_CONTEXT.md claims all ten id-bearing components carry scroll-margin-top so an
     * anchor jump clears the sticky header. Nothing checked that, and the id-prop test's
     * failure message asserted it without testing it. Derive the set from the schemas and
     * check the CSS, so an eleventh id-bearing component cannot ship with a broken anchor.
     */
    public function testEveryIdBearingComponentClearsTheStickyHeaderOnAnchorJump(): void
    {
        $css = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertTrue(
            (bool) preg_match('/((?:\s*\.[a-z-]+,\n)+\s*\.[a-z-]+)\s*\{\s*scroll-margin-top:/', $css, $m),
            'the shared scroll-margin-top rule is missing'
        );
        $selectors = $m[1];

        foreach ($this->allSchemas() as $component => $schema) {
            if (!isset($schema['props']['id'])) {
                continue;
            }
            $root = $schema['styling']['root_class'] ?? $component;
            $this->assertMatchesRegularExpression(
                '/(?<![-\w])\.' . preg_quote($root, '/') . '(?![-\w])/',
                $selectors,
                "{$component} accepts an `id` for anchor navigation, so .{$root} must be in the "
                . 'scroll-margin-top list or the sticky header covers the heading it jumps to.'
            );
        }
    }

    // ── A-12: chrome custom properties are not design tokens ────────────────

    /**
     * nav and footer render from whitelisted SITE OPTIONS, not from the design system.
     * Listing the --header-* and --footer-* properties in the same array as --color-bg
     * told an agent the two were one authoring surface. They are separated, and the separation is exact in both
     * directions so a future "tidy up" cannot merge them back silently.
     */
    public function testChromeCustomPropertiesAreSeparatedFromDesignTokens(): void
    {
        $expected = [
            'nav'    => ['--header-bg', '--header-text', '--header-link-color'],
            'footer' => ['--footer-bg', '--footer-text', '--footer-link-color'],
        ];
        foreach ($expected as $component => $properties) {
            $styling = $this->allSchemas()[$component]['styling'];
            $this->assertSame(
                $properties,
                $styling['chrome_custom_properties'] ?? null,
                "{$component} must declare its chrome custom properties in their own key."
            );
            foreach ($properties as $property) {
                $this->assertNotContains($property, $styling['tokens'], "{$property} is not a design token.");
            }
            $this->assertNotEmpty($styling['tokens'], "{$component} still consumes real design tokens.");
        }
    }

    /**
     * The split is only meaningful if it stays a CHROME-ONLY key: a section component that
     * grew one would be inventing a second styling surface behind the style-slot contract.
     */
    public function testOnlyTheChromePairDeclaresChromeCustomProperties(): void
    {
        foreach ($this->allSchemas() as $component => $schema) {
            if (in_array($component, ['nav', 'footer'], true)) {
                continue;
            }
            $this->assertArrayNotHasKey(
                'chrome_custom_properties',
                $schema['styling'],
                "{$component} is a composable component; its authoring surface is style_slots."
            );
        }
    }

    /** No schema may list a spacing token hero alone consumes — the A-12 defect. */
    public function testNoSchemaListsASpacingTokenItCannotReach(): void
    {
        $offenders = [];
        foreach ($this->allSchemas() as $component => $schema) {
            if ($component === 'hero') {
                continue; // hero owns every --space-* consumption that remains.
            }
            foreach (($schema['styling']['tokens'] ?? []) as $token) {
                if (in_array($token, ['--space-2xl', '--space-3xl'], true)) {
                    $offenders[] = "{$component} lists {$token}";
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'The [data-pp-spacing] rules that used to consume these were narrowed to '
            . '.hero, and only hero.php emits the attribute. Any other component listing '
            . 'them advertises a styling surface it cannot reach.'
        );
    }

    // ── A-16: the `id` surface is exactly ten components ────────────────────

    public function testExactlyTheTenSectionLevelComponentsDeclareAnIdProp(): void
    {
        $declaring = [];
        foreach ($this->allSchemas() as $component => $schema) {
            if (isset($schema['props']['id'])) {
                $declaring[] = $component;
            }
        }
        sort($declaring);
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'hero', 'logos', 'section', 'stats', 'table', 'testimonials'],
            $declaring,
            'table and faq were missing from the documented anchor-ID list for releases; '
            . 'nav and footer must never appear here (template-owned chrome).'
        );
    }

    public function testAiContextNamesAllTenAnchorIdComponents(): void
    {
        $doc = file_get_contents($this->themeRoot . '/AI_CONTEXT.md');
        $this->assertStringContainsString('All 10 section-level components', $doc);
        foreach (['table', 'faq'] as $component) {
            $this->assertMatchesRegularExpression(
                '/All 10 section-level components \([^)]*\b' . $component . '\b[^)]*\)/',
                $doc,
                "{$component} declares and renders `id` and is in the scroll-margin-top list."
            );
        }
    }

    /**
     * The dead read is gone. This asserts the STRONG form deliberately: even a caller that
     * passes an `id` gets no attribute, so the removal cannot be quietly undone by wiring a
     * prop back in. Chrome is rendered once by the theme and is never composed.
     */
    public function testChromeNeverEmitsAnIdEvenWhenOneIsPassed(): void
    {
        $roots = ['nav' => '/^<header[^>]*>/', 'footer' => '/^<footer[^>]*>/'];
        foreach (['nav' => 'primary', 'footer' => 'footer'] as $component => $location) {
            $html = $this->render($component, ['location' => $location, 'id' => 'injected-anchor']);
            $this->assertStringNotContainsString('injected-anchor', $html);

            // Scoped to the ROOT tag on purpose: nav legitimately emits id="pp-nav-menu"
            // on the menu it wires to aria-controls, and a whole-document ' id=' scan
            // would either fail on that or have to be loosened until it proved nothing.
            $this->assertSame(1, preg_match($roots[$component], trim($html), $m));
            $this->assertStringNotContainsString(' id=', $m[0], "{$component} root must carry no id attribute.");
        }
    }

    // ── A-18: the state twins ───────────────────────────────────────────────

    /**
     * Section 14.1 AUTHORING-PATH MANDATE. Both new slots are written through the REAL
     * surface (pp_execute_action('style_component')), not a raw _pp_composition meta write:
     * raw seeding bypasses pp_validate_composition entirely and would prove nothing about
     * whether the slot is actually authorable. Each is read back from STORAGE and from the
     * RENDERED markup, so a value accepted at write and dropped at the render boundary
     * fails here too.
     *
     * @dataProvider newStateTwins
     */
    public function testEveryNewStateTwinIsAuthorableThroughTheActionLayer(
        string $component,
        array $props,
        string $slot,
        string $value
    ): void {
        $id = pp_create_page("Authoring {$slot}", 'draft');
        pp_update_composition($id, [['component' => $component, 'props' => $props]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => [$slot => $value],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? "{$slot} must be authorable");
        $this->assertSame($value, pp_get_composition($id)[0]['style'][$slot]);
        $this->assertStringContainsString("{$slot}: {$value}", $this->renderStored($id));
    }

    public static function newStateTwins(): array
    {
        return [
            'grid card link hover' => [
                'grid',
                ['items' => [['title' => 'One', 'text' => 'a', 'link_url' => 'https://example.com', 'link_text' => 'More']]],
                '--grid-item-link-hover-color',
                '#123456',
            ],
            'cta second button elevation' => [
                'cta',
                [
                    'title'        => 'Ready?',
                    'button_text'  => 'Start',
                    'button_url'   => 'https://example.com',
                    'button2_text' => 'Docs',
                    'button2_url'  => 'https://example.com/docs',
                ],
                '--cta-button2-shadow',
                'none',
            ],
        ];
    }

    /**
     * A rest slot without its twin is a future flip bug (#564 is the recorded precedent):
     * an author sets the resting value and the state reverts to the product default under
     * the pointer. Pin the four pairs this issue completed, and pin that each half NAMES
     * the other — a twin nothing points at is a twin nobody finds.
     */
    public function testEveryCompletedTwinPairIsDeclaredAndCrossReferenced(): void
    {
        // Two DIFFERENT kinds of pair, kept apart so the terminology stays legible. The
        // repo's pre-existing term (see --section-panel-cta-hover-border, which predates
        // this issue) is POSITIONAL TWIN: the counterpart position in a STATE chain on one
        // element (rest<->hover, rest<->open) — the flip-bug class #564 records. The cta
        // pair is a different animal: same job, sibling ELEMENT (first button vs second),
        // both at rest. Both need the same cross-reference discipline (set one, find the
        // other), but calling the cta pair a positional twin would teach the next
        // contributor that a sibling element counts as a hover twin, which is exactly the
        // miss that leaves a real hover slot undeclared.
        $positionalTwins = [
            'grid'    => ['--grid-item-link-color', '--grid-item-link-hover-color'],
            'section' => ['--section-body-link-color', '--section-body-link-hover-color'],
            'faq'     => ['--faq-question-color', '--faq-question-open-color'],
        ];
        $perButtonCounterparts = [
            'cta'     => ['--cta-button-shadow', '--cta-button2-shadow'],
        ];
        $pairs = $positionalTwins + $perButtonCounterparts;
        foreach ($pairs as $component => [$rest, $twin]) {
            $slots = $this->slots($component);
            $this->assertArrayHasKey($rest, $slots, "{$component} must declare {$rest}");
            $this->assertArrayHasKey($twin, $slots, "{$component} must declare its twin {$twin}");
            $this->assertStringContainsString(
                $twin,
                $slots[$rest]['description'],
                "{$rest} must name {$twin} so an author setting one finds the other."
            );
            $this->assertStringContainsString(
                $rest,
                $slots[$twin]['description'],
                "{$twin} must name {$rest} — the cross-reference works in both directions."
            );
        }
    }

    /**
     * Byte-identical UNSET is the whole gate posture, so prove it at the render boundary
     * rather than asserting it: a component authored with no style map emits no style
     * attribute at all, which is what makes "the fallback still applies" true.
     */
    public function testTheNewTwinsEmitNothingWhenUnset(): void
    {
        $grid = $this->render('grid', ['items' => [['title' => 'One', 'text' => 'a']]]);
        $this->assertStringNotContainsString('--grid-item-link-hover-color', $grid);

        $cta = $this->render('cta', [
            'title'        => 'Ready?',
            'button_text'  => 'Start',
            'button_url'   => 'https://example.com',
            'button2_text' => 'Docs',
            'button2_url'  => 'https://example.com/docs',
        ]);
        $this->assertStringNotContainsString('--cta-button2-shadow', $cta);
    }

    /**
     * The CSS side of both twins, pinned at the declaration rather than by regexing the
     * whole sheet: the grid hover must route the slot with the ORIGINAL literal as its
     * fallback (that literal is what keeps unset output identical and what retired the
     * issue 309 waiver), and the cta isolation must re-point rather than hard-invalidate.
     */
    public function testTheNewTwinsAreRoutedWithTheOriginalLiteralsAsFallbacks(): void
    {
        $css = file_get_contents($this->themeRoot . '/assets/css/components.css');

        $this->assertStringContainsString(
            'color: var(--grid-item-link-hover-color, var(--color-accent-hover));',
            $css,
            'The card-link hover must route the twin with var(--color-accent-hover) as the '
            . 'fallback — the exact literal it carried while waived, so unset is unchanged.'
        );
        $this->assertStringNotContainsString(
            'color: var(--grid-item-link-color, var(--color-accent-hover));',
            $css,
            'Routing the HOVER through the REST slot is the mistake the issue 309 waiver '
            . 'existed to prevent: hover would render identical to rest whenever an author '
            . 'set the resting colour.'
        );
        $this->assertStringContainsString(
            '--cta-button-shadow: var(--cta-button2-shadow);',
            $css,
            'button2 elevation must enter the premium chain through the isolation rule.'
        );
    }

    /**
     * The card-scoped list in ai-instructions/composition.md is a THIRD copy of the
     * item_eligible set (schema flag, schema items[].style description, this doc), and
     * only the first two were coupled by a test. It had already drifted before this
     * issue — --grid-item-icon-size was missing — and adding --grid-item-link-hover-color
     * would have made it two behind. An agent reading composition.md would be told a slot
     * it can legitimately set per card is not accepted there.
     */
    public function testCompositionDocListsEveryCardScopedSlot(): void
    {
        $doc = file_get_contents($this->themeRoot . '/ai-instructions/composition.md');
        $line = null;
        foreach (explode("\n", $doc) as $candidate) {
            if (str_contains($candidate, 'The **card-scoped** slots accepted here')) {
                $line = $candidate;
                break;
            }
        }
        $this->assertNotNull($line, 'composition.md must keep its card-scoped slot list.');

        // Only the ACCEPTED half: the same sentence goes on to name the container-scoped
        // slots that are REJECTED per card, and those must not count as coverage.
        $accepted = strstr($line, 'Container/heading slots', true) ?: $line;

        foreach ($this->slots('grid') as $slot => $def) {
            if (empty($def['item_eligible'])) {
                continue;
            }
            $this->assertStringContainsString(
                "`{$slot}`",
                $accepted,
                "composition.md must list card-scoped slot {$slot} among the accepted set."
            );
        }
    }

    // ── A-28: the inert emissions are gone, the live ones are not ───────────

    /**
     * testimonials emitted data-pp-count and no CSS rule read it. grid emits the SAME
     * attribute and selects on it at five rules, so the pin asserts both halves: the inert
     * emission is gone AND the live one is untouched. Asserting only the removal would go
     * green if someone "cleaned up" grid's too and broke every per-count column layout.
     */
    public function testTheInertCountAttributeIsGoneAndTheLiveOneRemains(): void
    {
        $items = [
            ['quote' => 'One.', 'author' => 'A'],
            ['quote' => 'Two.', 'author' => 'B'],
        ];
        $testimonials = $this->render('testimonials', ['items' => $items]);
        $this->assertStringContainsString('testimonials__list', $testimonials);
        $this->assertStringNotContainsString('data-pp-count', $testimonials);

        $grid = $this->render('grid', [
            'items' => [['title' => 'One', 'text' => 'a'], ['title' => 'Two', 'text' => 'b']],
        ]);
        $this->assertStringContainsString('data-pp-count="2"', $grid);
    }

    /** The steps numeral joined the grid__* block; nothing may emit the old class. */
    public function testTheStepNumeralUsesTheGridBemBlock(): void
    {
        $html = $this->render('grid', [
            'layout' => 'steps',
            'items'  => [['number' => '1', 'title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringContainsString('class="grid__step-number"', $html);
        $this->assertStringNotContainsString('pp-step-number', $html);

        $css = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertStringNotContainsString('pp-step-number', $css);
        $this->assertStringContainsString('.grid--steps .grid__step-number', $css);
    }

    /**
     * The A-28 duplicate deletion, proven at the source rather than by inspection: the
     * base rule survives and the higher-specificity twin is gone. The VALUE-equivalence
     * half (no other .section__title font-size can resolve differently) is pinned
     * structurally in tests/js/css-lint.test.js, which can parse the cascade properly.
     */
    public function testTheRedundantTextOnlyTitleRuleIsGone(): void
    {
        $css = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertStringNotContainsString(
            ".section--text-only .section__title {\n  font-size:",
            $css,
            'The deleted rule re-declared the base rule verbatim at higher specificity.'
        );
        $this->assertStringContainsString(
            ".section__title {\n  font-size: var(--section-heading-size, var(--pp-band-heading-size));",
            $css,
            'The base rule is the one that now carries every text-only section title.'
        );
    }
}
