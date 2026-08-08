<?php
/**
 * tests/MeasureSurfaceTest.php
 *
 * The measure surface and the two severed cross-component slot leaks (issue #578).
 *
 * WHAT A MEASURE IS HERE: the max-width of a heading, a prose column, or a content
 * column. Before this gate the surface was three-quarters missing and the quarter
 * that existed leaked across components:
 *
 *   BEFORE                                      AFTER
 *   ──────                                      ─────
 *   .table-section__heading ┐                   .table-section__heading -> --table-heading-measure
 *   .faq__heading           │                   .faq__heading           -> --faq-heading-measure
 *   .logos__heading         ├─ ONE rule, in     .logos__heading         -> --logos-heading-measure
 *   .embed__heading         │  the SECTION      .embed__heading         -> --embed-heading-measure
 *   .cta__title             │  block, reading   .cta__title             -> --cta-heading-measure
 *   .stats__heading         ┘  --cta-heading-   .stats__heading         -> --stats-heading-measure
 *                              measure               (all six default var(--measure-heading))
 *
 *   main > .grid .grid__item-text ┐  ONE rule    grid + faq -> the 1rem literal
 *   main > .faq  .faq__answer     ├─ reading     cta        -> --cta-body-size (the slot cta owns)
 *   main > .cta  .cta__body       ┘  --cta-body-size
 *
 * Five of those six components could neither SET the cta slot (the write path rejects
 * a foreign slot as invalid_style_slot) nor have it RESOLVE (inline slot properties
 * land on the owning component's root, never on a sibling band), so the "slot" was a
 * literal wearing a var() costume. These tests pin the severance from BOTH sides:
 * each component's own slot reaches its own element, and the cta slot no longer does.
 *
 * SCOPE OF THIS FILE vs its neighbours. Static CSS text checks live in
 * tests/js/css-lint.test.js; whole-stylesheet contract guards live in
 * StyleSlotContractTest; the RENDERED proof (a browser resolving the cascade) lives
 * in tests/e2e/style-render.spec.ts. This file owns the authoring contract: what the
 * write path accepts, what it rejects, what the advisory channel says about it, and
 * that the declaration surface and the token registry agree.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MeasureSurfaceTest extends TestCase
{
    /** The eight band components whose heading measure routes the shared token. */
    private const ROUTED = ['cta', 'grid', 'testimonials', 'faq', 'stats', 'table', 'embed', 'logos'];

    /** The two exempt from it, both uncapped by default and for different reasons. */
    private const EXEMPT = ['hero', 'section'];

    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    private function css(): string
    {
        return file_get_contents($this->themeRoot . '/assets/css/components.css');
    }

    private function slots(string $component): array
    {
        $schema = json_decode(
            file_get_contents($this->themeRoot . "/components/{$component}/schema.json"),
            true
        );
        return $schema['styling']['style_slots'] ?? [];
    }

    /** Renders a stored composition through the real read+render path. */
    private function renderStored(int $post_id): string
    {
        ob_start();
        foreach (pp_get_composition($post_id) as $item) {
            if (!isset($item['component'])) {
                continue;
            }
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            $style = isset($item['style']) && is_array($item['style']) ? $item['style'] : [];
            if ($style) {
                $props['__pp_style'] = $style;
            }
            pp_get_component((string) $item['component'], $props);
        }
        return (string) ob_get_clean();
    }

    /** A minimal renderable props array per component, so every band actually emits. */
    private function propsFor(string $component): array
    {
        $byComponent = [
            'hero'         => ['title' => 'T'],
            'section'      => ['title' => 'T', 'body' => '<p>B</p>'],
            'grid'         => ['items' => [['title' => 'A', 'text' => 'a']]],
            'cta'          => ['title' => 'T', 'body' => 'B', 'button_text' => 'Go', 'button_url' => '/go'],
            'faq'          => ['items' => [['question' => 'Q', 'answer' => 'A']]],
            'stats'        => ['items' => [['number' => '9', 'label' => 'L']]],
            'table'        => ['headers' => ['H'], 'rows' => [['r']]],
            'logos'        => ['items' => [['image_url' => 'https://e.test/a.png', 'alt' => 'a']]],
            'embed'        => ['content' => '<p>E</p>'],
            'testimonials' => ['items' => [['quote' => 'Q', 'author' => 'A']]],
        ];
        return $byComponent[$component];
    }

    // ── A-39: the token ──────────────────────────────────────────────────────

    /**
     * The token has to be REGISTERED, not merely present as text: pp_design_tokens()
     * discovers tokens by regex over the `:root {}` block and needs the trailing
     * `/* <type>: <description> *\/` comment to type them. A token that parses without
     * a type is not writable through update_design_token, which is the entire reason
     * A-39 exists ("so the site-builder AI can retune band heading measure globally
     * with one write"). Asserting the VALUE too, because byte-identity depends on it:
     * every heading cap that rendered before this gate rendered 40rem.
     */
    public function testMeasureHeadingTokenIsRegisteredTypedAndFortyRem(): void
    {
        $tokens = pp_design_tokens();

        $this->assertArrayHasKey(
            '--measure-heading',
            $tokens,
            '--measure-heading must be discoverable in base.css :root — pp_design_tokens() '
            . 'parses that block, and an undiscovered token cannot be retuned by the AI.'
        );
        $this->assertSame('40rem', $tokens['--measure-heading']['value']);
        $this->assertSame(
            'length',
            $tokens['--measure-heading']['type'],
            'The token needs its `/* length: … */` type comment or update_design_token '
            . 'cannot validate a new value for it.'
        );
    }

    /** It belongs to the --measure-* family, beside the three prose measures. */
    public function testMeasureHeadingJoinsTheMeasureTokenFamily(): void
    {
        $tokens = pp_design_tokens();
        foreach (['--measure-body', '--measure-body-wide', '--measure-centered', '--measure-heading'] as $t) {
            $this->assertArrayHasKey($t, $tokens, "the --measure-* family lost {$t}");
            $this->assertSame('length', $tokens[$t]['type'], "{$t} must stay length-typed");
        }
    }

    /**
     * MAINTAINER-RESERVED (issue #578, ruling 7). A display heading's ideal measure is
     * a `ch` quantity, because it tracks the type size — so 40rem is very likely not the
     * right long-run value. The retune is a maintainer VISUAL decision, not implementation
     * discretion, and this pin makes an implementer who "improves" it say so out loud.
     */
    public function testMeasureHeadingIsNotSilentlyRetunedToACharacterUnit(): void
    {
        // Not a restatement of the 40rem pin above: that one fails on ANY retune, including
        // a legitimate maintainer one, and would simply be updated. This one survives that
        // update and still refuses a `ch` value — here and on every routed slot default, so
        // the reservation cannot be side-stepped by putting the ch on a component instead.
        $this->assertDoesNotMatchRegularExpression(
            '/\d\s*ch\b/',
            pp_design_tokens()['--measure-heading']['value'],
            'The ch-based retune of --measure-heading is reserved for the maintainer. '
            . 'Route the value back rather than picking one here.'
        );
        foreach (self::ROUTED as $component) {
            $this->assertDoesNotMatchRegularExpression(
                '/\d\s*ch\b/',
                (string) $this->slots($component)["--{$component}-heading-measure"]['default'],
                "{$component} must not carry a ch heading measure either — same reservation."
            );
        }
    }

    // ── A-6: the declaration surface ─────────────────────────────────────────

    /** All ten band components declare a heading measure. */
    public function testEveryBandComponentDeclaresAHeadingMeasure(): void
    {
        foreach (array_merge(self::ROUTED, self::EXEMPT) as $component) {
            $slot = "--{$component}-heading-measure";
            $this->assertArrayHasKey(
                $slot,
                $this->slots($component),
                "{$component} must declare {$slot} — the measure surface covers all ten bands."
            );
        }
    }

    /** The four prose components declare a body measure; testimonials deliberately does not. */
    public function testTheFourProseComponentsDeclareABodyMeasure(): void
    {
        foreach (['section', 'cta', 'faq', 'embed'] as $component) {
            $this->assertArrayHasKey(
                "--{$component}-body-measure",
                $this->slots($component),
                "{$component} must declare a body measure."
            );
        }
        $this->assertArrayNotHasKey(
            '--testimonials-body-measure',
            $this->slots('testimonials'),
            'testimonials is NOT among the four body-measure components in this pass — its '
            . 'stack layout keeps a 42rem literal by ruling. Do not add it without a decision.'
        );
    }

    /**
     * The eight routed components state the token as their default; the two exempt ones
     * state `none`. The schema `default` is the agent-facing effective default, so this
     * is the surface an authoring AI reads to decide whether a global retune will reach
     * this band.
     */
    public function testRoutedComponentsDefaultToTheTokenAndExemptOnesToNone(): void
    {
        foreach (self::ROUTED as $component) {
            $this->assertSame(
                'var(--measure-heading)',
                $this->slots($component)["--{$component}-heading-measure"]['default'],
                "{$component} must route the shared token so one update_design_token write reaches it."
            );
        }
        foreach (self::EXEMPT as $component) {
            $this->assertSame(
                'none',
                $this->slots($component)["--{$component}-heading-measure"]['default'],
                "{$component} is exempt from --measure-heading and must default to none."
            );
        }
    }

    /**
     * Exactly eight route it — no more. Hero's container already IS its measure and `ch`
     * is viewport-local while the container is not; section is the most-used band and its
     * title has never carried a cap. Both exemptions are intentional differences, and a
     * later "consistency" pass that folds either one in must fail here first.
     */
    public function testExactlyEightComponentsRouteTheSharedToken(): void
    {
        $routing = [];
        foreach (array_merge(self::ROUTED, self::EXEMPT) as $component) {
            if ($this->slots($component)["--{$component}-heading-measure"]['default'] === 'var(--measure-heading)') {
                $routing[] = $component;
            }
        }
        sort($routing);
        $expected = self::ROUTED;
        sort($expected);
        $this->assertSame($expected, $routing);
    }

    /**
     * A slot whose declared default is `none` must be able to ACCEPT `none`, or the
     * default is one no author can restore — the exact third-state defect #579/A-30
     * introduced `length-or-none` to close for --stats-max-width. Every other measure
     * slot keeps the plain `length` grammar: they have a real length default and no
     * third state, and widening them would contradict what lib/ai-context.php and
     * ai-instructions/style-component.md tell the authoring AI.
     */
    public function testOnlyTheNoneDefaultedMeasureSlotsCarryTheNoneGrammar(): void
    {
        $noneDefaulted = [];
        $lengthTyped   = [];
        foreach (array_merge(self::ROUTED, self::EXEMPT) as $component) {
            foreach ($this->slots($component) as $name => $def) {
                if (($def['role'] ?? null) !== 'measure') {
                    continue;
                }
                if (($def['default'] ?? null) === 'none') {
                    $noneDefaulted[$name] = $def['type'];
                } else {
                    $lengthTyped[$name] = $def['type'];
                }
            }
        }

        foreach ($noneDefaulted as $name => $type) {
            $this->assertSame(
                'length-or-none',
                $type,
                "{$name} declares default `none`, so its grammar must accept `none` — "
                . 'otherwise the declared default is unauthorable.'
            );
        }
        foreach ($lengthTyped as $name => $type) {
            $this->assertSame(
                'length',
                $type,
                "{$name} has a real length default and no third state; it must keep the "
                . 'plain `length` grammar rather than silently widening to accept `none`.'
            );
        }
        $this->assertSame(
            ['--cta-body-measure', '--faq-body-measure', '--hero-heading-measure', '--section-heading-measure'],
            $this->sortedKeys($noneDefaulted),
            'The set of uncapped-by-default measure slots changed. That is a render decision, '
            . 'not a refactor — update this pin deliberately.'
        );
    }

    private function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);
        return $keys;
    }

    /** Every measure slot carries the declared role marker the advisory engine reads. */
    public function testEveryMeasureSlotDeclaresTheMeasureRole(): void
    {
        $expected = [];
        foreach (array_merge(self::ROUTED, self::EXEMPT) as $component) {
            $expected[] = "--{$component}-heading-measure";
        }
        foreach (['section', 'cta', 'faq', 'embed'] as $component) {
            $expected[] = "--{$component}-body-measure";
        }
        // hero's measure is spelled --hero-content-width, which is exactly why the engine
        // reads a declared role rather than a `-measure` name suffix.
        $expected[] = '--hero-content-width';
        // DELIBERATELY ABSENT: --stats-max-width. It caps the stats BAND's own box (a
        // contained, centered card — issue 383), not a run of text, so it is band geometry
        // rather than a text measure and carries no --measure-* default to fall out of step
        // with. Asserted below by the exact-set comparison; recorded here so a future reader
        // can tell an intentional boundary from an oversight.
        $this->assertArrayNotHasKey(
            'role',
            $this->slots('stats')['--stats-max-width'],
            '--stats-max-width is band geometry, not a text measure — adding the measure role '
            . 'would point the advisory at a slot that has no token to route.'
        );
        sort($expected);

        $found = [];
        foreach (array_merge(self::ROUTED, self::EXEMPT) as $component) {
            foreach ($this->slots($component) as $name => $def) {
                if (($def['role'] ?? null) === 'measure') {
                    $found[] = $name;
                }
            }
        }
        sort($found);

        $this->assertSame($expected, $found);
    }

    // ── A-5: the severance, from both sides ──────────────────────────────────

    /**
     * The positive half: each of the six formerly-shared headings reads its OWN slot,
     * inside its OWN component block. Block scoping is what makes the slot reachable —
     * the old rule sat in the section block, which is why no per-component audit ever
     * saw it.
     */
    public function testEachSeveredHeadingReadsItsOwnSlotInItsOwnBlock(): void
    {
        $subjects = [
            'table' => '.table-section__heading',
            'faq'   => '.faq__heading',
            'logos' => '.logos__heading',
            'embed' => '.embed__heading',
            'cta'   => '.cta__title',
            'stats' => '.stats__heading',
        ];

        foreach ($subjects as $component => $selector) {
            $block = $this->componentBlock($component);
            $this->assertMatchesRegularExpression(
                '/max-width:\s*var\(\s*--' . $component . '-heading-measure\b/',
                $block,
                "{$selector} must cap through --{$component}-heading-measure inside the "
                . "COMPONENT: {$component} block."
            );
        }
    }

    /**
     * The negative half, and the one that actually proves the leak is gone: no element
     * outside cta may name a cta slot. A severance that left --cta-heading-measure in
     * the chain as an intermediate fallback would keep every rendered number identical
     * and keep the defect, so the positive half above cannot catch it alone.
     */
    public function testNoForeignComponentStillReadsACtaSlot(): void
    {
        $leaked = [];
        foreach (['table', 'faq', 'logos', 'embed', 'stats', 'grid', 'section', 'testimonials', 'hero'] as $component) {
            $block = $this->stripComments($this->componentBlock($component));
            foreach (['--cta-heading-measure', '--cta-body-size', '--cta-content-width'] as $ctaSlot) {
                if (strpos($block, $ctaSlot) !== false) {
                    $leaked[] = "{$component} block still reads {$ctaSlot}";
                }
            }
        }
        $this->assertSame([], $leaked);
    }

    /**
     * The --cta-body-size severance in the shared MOBILE rule, which lives outside every
     * component block and so is invisible to the per-block scan above. grid and faq take
     * the literal; cta keeps the slot it owns.
     */
    public function testMobileBodySizeRuleNoLongerPointsGridOrFaqAtACtaSlot(): void
    {
        $css = $this->stripComments($this->css());
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $offenders = [];
        foreach ($rules as [$whole, $selector, $body]) {
            if (!preg_match('/--cta-body-size/', $body)) {
                continue;
            }
            // Every remaining consumption must sit on a cta-owned subject.
            foreach (explode(',', $selector) as $part) {
                $part = trim($part);
                if ($part === '' || strpos($part, '.cta') !== false) {
                    continue;
                }
                $offenders[] = trim(preg_replace('/\s+/', ' ', $part));
            }
        }
        $this->assertSame(
            [],
            $offenders,
            '--cta-body-size is a CTA authoring surface; these non-cta selectors still read it.'
        );
    }

    /** .section__title gains NO cap — the ruling that was deleted from an earlier draft. */
    public function testSectionTitleStaysUncapped(): void
    {
        $css = $this->stripComments($this->css());
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $caps = [];
        foreach ($rules as [$whole, $selector, $body]) {
            foreach (explode(',', $selector) as $part) {
                if (!preg_match('/\.section__title(?![-\w])\s*$/', trim($part))) {
                    continue;
                }
                foreach ((array) (preg_match_all('/(?<![-a-z])max-width\s*:\s*([^;}]+)/i', $body, $m) ? $m[1] : []) as $v) {
                    $caps[] = trim($v);
                }
            }
        }
        // Non-vacuous: if the declaration vanished entirely the foreach below would pass
        // with zero assertions, which is exactly the reversion this test exists to catch.
        $this->assertNotEmpty(
            $caps,
            'The .section__title measure declaration disappeared — the slot is no longer consumed.'
        );
        // The only max-width allowed on the section title is the slot, defaulting to none.
        foreach ($caps as $value) {
            $this->assertMatchesRegularExpression(
                '/^var\(\s*--section-heading-measure\s*,\s*none\s*\)$/',
                $value,
                'The section title must stay uncapped: section is the most-used band in the '
                . 'product and a 40rem cap would re-wrap every stored section heading.'
            );
        }
    }

    /**
     * --section-body-measure keeps ALL FOUR branch fallbacks. They are a layout x viewport
     * measure system that renders correctly today, not a defect to be tidied away —
     * collapsing them would change the rendered line length of the product's most-used
     * prose surface on at least three of four branches.
     */
    public function testSectionBodyMeasureKeepsAllFourBranchFallbacks(): void
    {
        $css = $this->stripComments($this->css());
        // Balanced capture: match to the end of the declaration and strip the ONE closing
        // paren that belongs to the outer var(), so a nested var() fallback is pinned as the
        // CSS actually spells it rather than as a regex artifact.
        preg_match_all('/max-width:\s*var\(\s*--section-body-measure\s*,\s*([^;]+?)\)\s*;/', $css, $m);
        $fallbacks = array_map('trim', $m[1]);
        sort($fallbacks);

        // FIVE consumptions, FOUR distinct measures: the centered and text-only branches
        // share var(--measure-centered), which is why it appears twice.
        $this->assertSame(
            ['40rem', '42rem', '49rem', 'var(--measure-centered)', 'var(--measure-centered)'],
            $fallbacks,
            'The four distinct --section-body-measure branch fallbacks (40rem outer, 42rem '
            . 'inner, --measure-centered on centered/text-only, 49rem mobile) must all survive.'
        );
    }

    /** --hero-content-width keeps its three fallbacks for the same reason. */
    public function testHeroContentWidthKeepsItsThreeBranchFallbacks(): void
    {
        $css = $this->stripComments($this->css());
        preg_match_all('/max-width:\s*var\(\s*--hero-content-width\s*,\s*([^;}]+)\)/', $css, $m);
        $fallbacks = array_map('trim', $m[1]);
        sort($fallbacks);

        $this->assertSame(
            ['40rem', 'none', 'var(--measure-centered)'],
            $fallbacks,
            'hero-content-width has the identical layout x viewport shape as the section '
            . 'body measure and takes the same answer: declare, document, do not collapse.'
        );
    }

    // ── A-6 hero: the one deliberate render change ───────────────────────────

    /**
     * The 12ch cap is GONE. .hero__content is a flex item that shrink-wraps to its widest
     * child, so that cap narrowed the whole column — title, subtitle AND buttons — to 468px
     * of a 1088px inner at 1280. The rendered proof is in style-render.spec.ts; this pin
     * owns the text, so the rule cannot come back through a merge.
     */
    public function testHeroTitleNoLongerCarriesTheCharacterCap(): void
    {
        // Comments stripped first: the rule's own tombstone comment explains what 12ch was
        // and why it went, and a prose mention is not a declaration.
        $this->assertStringNotContainsString(
            '12ch',
            $this->stripComments($this->css()),
            'The hero 12ch title cap is deleted by ruling — it is the gate\'s one deliberate '
            . 'render change and must not return.'
        );
    }

    /**
     * The slot reaches all four hero layouts, not just the two that carried 12ch. Declaring
     * it on `.hero--left, .hero--split` would leave centered and cover with a declared slot
     * that silently does nothing — the defeated-slot class this milestone exists to close.
     */
    public function testHeroHeadingMeasureIsDeclaredOnTheUnscopedTitleRule(): void
    {
        $css = $this->stripComments($this->css());
        $this->assertMatchesRegularExpression(
            '/(?:^|})\s*\.hero__title\s*\{[^}]*max-width:\s*var\(\s*--hero-heading-measure\s*,\s*none\s*\)/s',
            $css,
            'The hero heading measure must sit on the unscoped .hero__title rule so it reaches '
            . 'centered and cover too; unset it resolves to `none` on every layout, which is '
            . 'max-width\'s initial value and therefore byte-identical.'
        );
    }

    /**
     * The hero subtitle's 40ch cap is a RATIFIED KEEP: a hero subtitle is a lede, and `ch`
     * is the correct unit there because the cap tracks the subtitle's own type size. It is
     * pinned because the 12ch deletion makes it the new column binder on a short-title left
     * hero, which is exactly the kind of adjacency a later cleanup would "tidy".
     */
    public function testHeroSubtitleKeepsItsRatifiedCharacterCap(): void
    {
        $css = $this->stripComments($this->css());
        $this->assertMatchesRegularExpression(
            '/\.hero__subtitle\s*\{[^}]*max-width:\s*40ch/s',
            $css,
            'The hero subtitle 40ch cap is a ratified keep — do not change it.'
        );
    }

    // ── A-15: hero-only spacing/width ────────────────────────────────────────

    /**
     * The premise A-15 rests on, enforced rather than asserted in a comment: hero is the
     * only emitter of these attributes. If a second component ever starts emitting them,
     * the now-.hero-scoped CSS silently stops applying to it — so this fails first.
     */
    public function testOnlyHeroEmitsTheSpacingAndWidthAttributes(): void
    {
        // Scans the whole theme, not just components/: an emitter added in lib/, in a
        // template, or in a nested partial is exactly the silent widening this guard exists
        // to catch, and a components/*/*.php glob would not see any of them.
        $emitters = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->themeRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            foreach (['/tests/', '/vendor/', '/node_modules/'] as $skip) {
                if (strpos($path, $skip) !== false) {
                    continue 2;
                }
            }
            if (preg_match('/data-pp-(spacing|width)/', (string) file_get_contents($path))) {
                $emitters[] = str_replace($this->themeRoot . '/', '', $path);
            }
        }
        sort($emitters);

        $this->assertSame(
            ['components/hero/hero.php'],
            $emitters,
            'The [data-pp-spacing] / [data-pp-width] CSS is scoped to .hero because only '
            . 'hero.php emits those attributes, and by ruling no blanket non-hero width or '
            . 'spacing controls exist. A new emitter needs its own decision, not a silent '
            . 'widening of the selector.'
        );
    }

    /** The generic selectors are gone; nothing can match a non-hero band any more. */
    public function testSpacingAndWidthSelectorsAreScopedToHero(): void
    {
        $css = $this->stripComments($this->css());
        $this->assertStringNotContainsString(
            '[data-pp-component][data-pp-spacing=',
            $css,
            'The spacing overrides must be scoped to .hero, so the restriction is enforced '
            . 'by the selector rather than by a comment beside a generic one.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|[},])\s*\[data-pp-width="/m',
            $css,
            'The width overrides must be scoped to .hero for the same reason.'
        );
    }

    /**
     * `width: narrow` duplicated --measure-centered's exact shipped value, so a site that
     * retuned its centered measure moved every centered body EXCEPT a narrow hero.
     */
    public function testNarrowWidthRoutesTheCenteredMeasureTokenInsteadOfDuplicatingIt(): void
    {
        $css = $this->stripComments($this->css());
        $this->assertMatchesRegularExpression(
            '/\.hero\[data-pp-width="narrow"\]\s+\.container\s*\{[^}]*max-width:\s*var\(\s*--measure-centered\s*\)/s',
            $css,
            'width: narrow must reference --measure-centered rather than restating its 56rem.'
        );
    }

    // ── Authoring path (Section 14.1): the REAL write surface ────────────────

    /**
     * Every new measure slot is written through pp_execute_action('style_component'),
     * not a raw _pp_composition meta write — raw seeding bypasses pp_validate_composition
     * entirely, so it proves nothing about whether the slot is authorable. Each is then
     * read back from storage AND from the rendered markup, so a value accepted at write
     * and dropped at the render boundary fails too.
     *
     * @dataProvider newMeasureSlots
     */
    public function testEveryNewMeasureSlotIsAuthorableThroughTheActionLayer(
        string $component,
        string $slot,
        string $value
    ): void {
        $id = pp_create_page("Authoring {$slot}", 'draft');
        pp_update_composition($id, [['component' => $component, 'props' => $this->propsFor($component)]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => [$slot => $value],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? "{$slot} must be authorable");
        $this->assertSame($value, pp_get_composition($id)[0]['style'][$slot]);
        $this->assertStringContainsString("{$slot}: {$value}", $this->renderStored($id));
    }

    public static function newMeasureSlots(): array
    {
        $cases = [];
        foreach (self::ROUTED as $component) {
            $cases["{$component} heading"] = [$component, "--{$component}-heading-measure", '30rem'];
        }
        foreach (self::EXEMPT as $component) {
            $cases["{$component} heading"] = [$component, "--{$component}-heading-measure", '30rem'];
        }
        foreach (['section', 'cta', 'faq', 'embed'] as $component) {
            $cases["{$component} body"] = [$component, "--{$component}-body-measure", '34rem'];
        }
        return $cases;
    }

    /**
     * The declared default must be authorable — the third-state defect A-30 closed. An
     * operator who narrows an uncapped heading must be able to put it back.
     *
     * @dataProvider noneDefaultedMeasureSlots
     */
    public function testTheUncappedDefaultIsAuthorable(string $component, string $slot): void
    {
        $id = pp_create_page("None {$slot}", 'draft');
        pp_update_composition($id, [['component' => $component, 'props' => $this->propsFor($component)]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => [$slot => 'none'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? "{$slot} must accept its own default");
        $this->assertStringContainsString("{$slot}: none", $this->renderStored($id));
    }

    public static function noneDefaultedMeasureSlots(): array
    {
        return [
            'hero heading'    => ['hero', '--hero-heading-measure'],
            'section heading' => ['section', '--section-heading-measure'],
            'cta body'        => ['cta', '--cta-body-measure'],
            'faq body'        => ['faq', '--faq-body-measure'],
        ];
    }

    /**
     * NOT a global widening. A measure slot with a real length default keeps the plain
     * `length` grammar and keeps rejecting `none`, which is what stops the A-30 fix from
     * re-opening the accepted-but-dead class it closed.
     */
    public function testARoutedMeasureSlotStillRejectsNone(): void
    {
        $id = pp_create_page('None on a routed measure', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => $this->propsFor('grid')]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--grid-heading-measure' => 'none'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('--grid-heading-measure', $result['error']);
    }

    /**
     * The leak, proved from the authoring side rather than from the CSS text: a foreign
     * component cannot even NAME the cta slot. This is half of why the shared rule was a
     * defect — the five non-cta components could not set the slot their heading read.
     */
    public function testAForeignComponentCannotAuthorTheCtaMeasureSlot(): void
    {
        $id = pp_create_page('Foreign slot', 'draft');
        pp_update_composition($id, [['component' => 'table', 'props' => $this->propsFor('table')]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--cta-heading-measure' => '30rem'],
        ]);

        $this->assertFalse(
            $result['ok'],
            'A table could never set --cta-heading-measure, which is exactly why capping its '
            . 'heading through that slot made the cap unauthorable.'
        );
        // Assert the REASON, not just the failure: without this the test passes on a broken
        // fixture, a missing page, or any unrelated validation error.
        $this->assertStringContainsString('--cta-heading-measure', $result['error']);
    }

    // ── Ruling 1: the advisory is DEFERRED, and the marker is not ────────────

    /**
     * The `role: "measure"` marker ships; its advisory consumer does NOT (see the PR body
     * and issue #610). Ruling 1 asks for a non-blocking warning when an author writes a
     * "non-token length" into a measure slot, and for a token reference to be silent — but
     * `_pp_validate_length()` (lib/apply.php) REJECTS every `var()` form, so on the eight
     * routed slots there is no writable value that a warning could be silenced with. The
     * smells channel is also a hard gate: `wp pp validate site` sets $pass = false on ANY
     * smell and halts(1) (lib/cli.php), and the theme's own shipped starter homepage sets
     * five literal measures — so the advisory as ruled would make `validate site` exit 1 on
     * a fresh install with no way to fix it. Landing the grammar that makes it satisfiable
     * is a public-surface decision, so it is routed back rather than taken here.
     *
     * This test pins the two halves of that reasoning so the follow-up starts from facts,
     * not from a re-derivation.
     */
    public function testTheTokenReferenceFormIsNotWritableYet(): void
    {
        $err = _pp_validate_token_value('var(--measure-heading)', 'length', null);
        $this->assertTrue(
            is_wp_error($err),
            'If length slots start accepting a bare token reference, the measure advisory '
            . 'becomes satisfiable and issue #610 can land it. Update this pin then.'
        );
        $this->assertSame('invalid_length', $err->get_error_code());
    }

    /** No advisory ships in this gate, so nothing new can red `wp pp validate site`. */
    public function testTheShippedStarterHomepageEmitsNoNewSmells(): void
    {
        $types = array_column(pp_validate_composition_smells(pp_default_homepage_composition()), 'type');

        $this->assertNotContains(
            'literal_measure',
            $types,
            'The starter seed sets five literal measures. Any advisory that fires on them '
            . 'makes `wp pp validate site` exit 1 on a fresh install (lib/cli.php halts on '
            . 'ANY smell), which is why the consumer is deferred to issue #610.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The component's own CSS block, bounded by the next TOP-LEVEL banner of any kind —
     * COMPONENT: or SHARED:. Bounding on `COMPONENT:` alone would make the LAST component
     * block swallow every shared section that follows it to EOF, and those sections
     * legitimately mention other components' slots.
     */
    private function componentBlock(string $component): string
    {
        $pattern = '/COMPONENT:\s*' . preg_quote($component, '/') . '\b(.*?)'
                 . '(?=\/\*\s*={5,}\s*(?:COMPONENT|SHARED):|\z)/s';
        $this->assertMatchesRegularExpression($pattern, $this->css(), "No COMPONENT: {$component} block.");
        preg_match($pattern, $this->css(), $m);
        return $m[1];
    }

    private function stripComments(string $css): string
    {
        return preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    }
}
