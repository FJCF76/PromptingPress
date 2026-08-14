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
 *   A-28  the two inert emissions are gone and their live counterparts are untouched;
 *   #616  `styling.tokens` names only REGISTERED design tokens, and no schema points
 *         update_design_token at a property that action rejects.
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
        // The #616 pins read pp_design_tokens(), whose process-static cache is NOT keyed on
        // the theme root (lib/wp.php) — which is exactly why it leaks. Several other test
        // files repoint _pp_test_template_dir at a fixture theme without invalidating on the
        // way out, so without this reset these pins would read whatever registry ran last and
        // pass or fail on file ORDER. Symmetric with
        // tearDown() (the ApplyTest convention): clean on both edges, so this class neither
        // inherits another file's registry nor exports its own.
        unset($GLOBALS['_pp_test_template_dir']);
        pp_invalidate_design_tokens_cache();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_template_dir']);
        pp_invalidate_design_tokens_cache();
        parent::tearDown();
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
            'Every heading size/colour slot must say when NOT to use it. For a COLOUR slot '
            . 'that means naming the one update_design_token write that retunes the whole '
            . 'site at once. For a SIZE slot there is no such write — the shared scale is a '
            . 'fixed theme constant, not a registered token (#616) — so say that instead; '
            . 'naming it beside the action fails testNoSchemaSendsAnAgentToUpdateANonExistentDesignToken.'
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

    // ── #616: the one guarantee `styling.tokens` makes ──────────────────────

    /**
     * `styling.tokens` is hand-curated and NOT exhaustive (measured at #616: the arrays
     * name 2-7 tokens per component while those components' own rules read 9-26 registered
     * ones). Exactly one property of the array is a guarantee, and this pins it: every
     * entry is a REGISTERED design token — a member of pp_design_tokens(), which is the
     * same set update_design_token accepts.
     *
     * #616 proposed `--pp-band-padding` / `--pp-band-heading-size` as the first additions.
     * The array cannot take them, because they are not tokens:
     *
     *   assets/css/base.css
     *     :root { … --overlay-bg … --measure-heading … }      ← pp_design_tokens() reads this
     *     :root { --pp-band-padding; --pp-band-heading-size } ← and never reaches this one
     *              │                                            (preg_match, first block only)
     *              └─→ update_design_token → WP_Error unknown_token
     *
     * Listing them would advertise a write path that does not exist. That is the same
     * ruling A-12 above already made for the chrome custom properties; this pin makes it
     * mechanical instead of remembered, so the tempting fix fails closed.
     */
    public function testEveryListedTokenIsARegisteredDesignToken(): void
    {
        $registry = pp_design_tokens();
        $this->assertNotEmpty($registry, 'the design-token registry read returned nothing');

        $checked   = 0;
        $offenders = [];
        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['tokens'] ?? []) as $token) {
                $checked++;
                if (!array_key_exists($token, $registry)) {
                    $offenders[] = "{$component} lists {$token}";
                }
            }
        }

        // Fail-closed floor: without it a broken glob would pass this vacuously.
        // Measured at #616: 40 entries across 12 schemas.
        $this->assertGreaterThanOrEqual(30, $checked, 'schema token discovery collapsed');

        $this->assertSame(
            [],
            $offenders,
            'styling.tokens may only name REGISTERED design tokens (the first :root block '
            . 'of base.css — what update_design_token accepts). A custom property outside '
            . 'that registry, such as the shared band props --pp-band-padding / '
            . '--pp-band-heading-size, is not a design token: document it on the per-band '
            . 'slot that routes it, which is its only authoring surface.'
        );
    }

    /**
     * Detection proof for the pin above: the registry read must be able to tell the two
     * :root blocks apart. If pp_design_tokens() ever returned everything (or nothing),
     * the scan would go green over exactly the falsehood it exists to catch.
     */
    public function testTheTokenRegistryDistinguishesTheTwoRootBlocks(): void
    {
        $registry = pp_design_tokens();

        foreach (['--overlay-bg', '--measure-heading', '--measure-centered'] as $token) {
            $this->assertArrayHasKey($token, $registry, "{$token} is a registered design token");
        }
        foreach (['--pp-band-padding', '--pp-band-padding-adjacent-top', '--pp-band-heading-size'] as $prop) {
            $this->assertArrayNotHasKey(
                $prop,
                $registry,
                "{$prop} is declared in the second :root block and is deliberately NOT a "
                . 'design token; if it becomes one, that is a write-path decision, not a '
                . 'silent parser change.'
            );
        }
    }

    /**
     * No schema may send an agent to update_design_token with a name that action rejects.
     *
     * The #616 defect: nine `--<comp>-heading-size` descriptions read "retuning every band
     * heading at once is ONE update_design_token write on --pp-band-heading-size", and that
     * write returns unknown_token (lib/apply.php, validate closure). The sibling claims in
     * the same files are true — --color-text and --space-sm ARE registered — so the rule is
     * not "never mention the action".
     *
     * Two rules, both derived, no hand-maintained list anywhere:
     *
     *   1. In a string that mentions the action, every `--custom-property` named must be one
     *      THIS component can reach — a REGISTERED design token, one of its OWN declared
     *      style slots, or one of its OWN declared chrome custom properties. Per-component
     *      on purpose: a global union would let one schema declaring a slot named
     *      `--pp-band-heading-size` re-legalise the false sentence for all twelve.
     *   2. In a string that mentions the action, no INTERNAL property may be named at all,
     *      with or without its leading dashes. The internal set is derived by subtracting
     *      the registry from every `:root` block in base.css, which today yields exactly
     *      --pp-band-padding, --pp-band-padding-adjacent-top and --pp-band-heading-size.
     *      Rule 1 alone matched only the dashed spelling, so "one update_design_token write
     *      on pp-band-heading-size" restored the falsehood in agent-readable prose and
     *      passed.
     *
     * Deliberately word-order blind, sentence blind and case blind. Earlier drafts read only
     * text AFTER the action name and split on sentences; both narrowings were escapable by
     * ordinary house phrasing ("the shared --pp-band-heading-size scale retunes site-wide
     * with one update_design_token write" put the name first; an "e.g." split the name into
     * a different sentence). Scanning the whole string has no such seam.
     *
     * What this pin does NOT do, stated plainly so nobody trusts it further than it goes:
     *
     *   - It catches a NAME, not a CLAIM. "ONE update_design_token write, not one slot write
     *     per band" with the name deleted keeps the falsehood and passes. Detecting that is
     *     prose comprehension, not a pin — which is why the failure message below tells an
     *     author to state the reachable truth, never to delete the name and keep the promise.
     *   - It allows a component's OWN slot or chrome property beside the action, which is
     *     also wrong advice (update_design_token rejects both). The allowance is load-bearing:
     *     five live descriptions legitimately name one in a string that also names the action,
     *     so separating "named as the target" from "named as context" would need intent
     *     parsing.
     *   - It does not cover ai-instructions/*.md, and nothing else does either. That is
     *     deliberate, not an oversight: a schema description is agent-facing guidance about
     *     what to WRITE, so it must not offer a write that fails, while the prose docs are
     *     where a non-authorable internal like --pp-band-heading-size gets EXPLAINED.
     *
     * Scans every string in every schema, so a description that moves between prop, slot or
     * recipe metadata stays covered.
     */
    public function testNoSchemaSendsAnAgentToUpdateANonExistentDesignToken(): void
    {
        $internal = $this->propertiesDeclaredButNotRegistered();

        $scanned   = 0;
        $offenders = [];
        foreach ($this->allSchemas() as $component => $schema) {
            $reachable = $this->namesThisComponentCanReach($schema);
            foreach ($this->everyString($schema) as $text) {
                $scanned++;
                foreach ($this->propertiesNamedWithTheAction($text) as $name) {
                    if (!isset($reachable[$name])) {
                        $offenders[] = "{$component} points an agent at update_design_token {$name}";
                    }
                }
                foreach ($this->internalPropertiesNamedWithTheAction($text, $internal) as $name) {
                    $offenders[] = "{$component} points an agent at update_design_token {$name}";
                }
            }
        }

        // Fail-closed floor on STRINGS WALKED, not on action mentions: measured 1834 strings
        // across the twelve schemas at #616, of which 13 mention the action after this change
        // (22 before it). Counting the mentions instead would quietly require the schemas to
        // keep advertising the action — a floor must prove the walk still runs, never
        // constrain what the prose is allowed to say.
        $this->assertGreaterThanOrEqual(1000, $scanned, 'the schema string walk collapsed');

        $this->assertSame(
            [],
            array_unique($offenders),
            'A schema names a property beside update_design_token that the action rejects as '
            . 'unknown_token, so an agent following the description gets an error. Name the '
            . 'surface that actually exists: a registered design token for a site-wide retune, '
            . 'or this component\'s own slot for a one-band change. Do NOT fix this by deleting '
            . 'the name and keeping the promise — that leaves the same false claim with nothing '
            . 'to catch it. A property no action can write (--pp-band-padding, '
            . '--pp-band-heading-size) is explained in ai-instructions/, not offered here.'
        );
    }

    /**
     * Detection proof for the scan above. Both narrowings that an earlier draft shipped are
     * pinned here as rows, so a "tidy-up" that reintroduces either fails on this table
     * instead of passing silently over the live schemas.
     */
    public function testTheUpdateDesignTokenScanIsWordOrderAndSentenceBlind(): void
    {
        $this->assertSame(
            ['--color-text'],
            $this->propertiesNamedWithTheAction(
                'Retuning every band at once is ONE update_design_token write on --color-text, '
                . 'not one slot write per band.'
            )
        );
        $this->assertSame(
            ['--pp-band-heading-size'],
            $this->propertiesNamedWithTheAction(
                'The shared --pp-band-heading-size scale retunes every band at once with a '
                . 'single update_design_token write.'
            ),
            'a name written BEFORE the action must still be caught'
        );
        $this->assertSame(
            ['--pp-band-heading-size'],
            $this->propertiesNamedWithTheAction(
                'Retune every band heading with update_design_token, e.g. '
                . '--pp-band-heading-size, rather than one slot write per band.'
            ),
            'an abbreviation must not split the name away from the action'
        );
        $this->assertSame(
            ['--pp-band-heading-size'],
            $this->propertiesNamedWithTheAction(
                'Retune it with Update_Design_Token on --pp-band-heading-size.'
            ),
            'the action name is matched case-insensitively'
        );
        $this->assertSame(
            [],
            $this->propertiesNamedWithTheAction(
                'Hero is EXEMPT from the shared --pp-band-heading-size scale; leave this unset.'
            ),
            'naming a non-authorable property WITHOUT offering a write is how a slot states an '
            . 'exemption, and must stay legal'
        );
        $this->assertSame(
            [],
            $this->propertiesNamedWithTheAction('No mention of the action here: --color-bg.')
        );

        // Rule 2: the dashes are not what makes it an instruction.
        $internal = ['--pp-band-heading-size' => true, '--pp-band-padding' => true];
        $this->assertSame(
            ['--pp-band-heading-size'],
            $this->internalPropertiesNamedWithTheAction(
                'Retune every band with ONE update_design_token write on pp-band-heading-size.',
                $internal
            ),
            'dropping the leading dashes must not hide the instruction'
        );
        $this->assertSame(
            [],
            $this->internalPropertiesNamedWithTheAction(
                'Hero is EXEMPT from the shared --pp-band-heading-size scale; leave this unset.',
                $internal
            ),
            'rule 2 fires on the ACTION, not on the name alone'
        );
        $this->assertSame(
            [],
            $this->internalPropertiesNamedWithTheAction(
                'Retune the label with update_design_token on --color-text.',
                $internal
            ),
            'a true instruction naming a registered token must stay legal'
        );
    }

    /**
     * Every custom property named in a string that mentions update_design_token.
     *
     * @return string[]
     */
    private function propertiesNamedWithTheAction(string $text): array
    {
        if (stripos($text, 'update_design_token') === false) {
            return [];
        }
        preg_match_all('/(--[a-z0-9-]+)/i', $text, $m);
        return $m[1];
    }

    /**
     * Internal properties named beside the action, matched WITHOUT requiring the leading
     * dashes — "a write on pp-band-heading-size" is the same false instruction as
     * "--pp-band-heading-size" to an agent reading the description.
     *
     * @param array<string, true> $internal
     * @return string[]
     */
    private function internalPropertiesNamedWithTheAction(string $text, array $internal): array
    {
        if (stripos($text, 'update_design_token') === false) {
            return [];
        }
        $found = [];
        foreach (array_keys($internal) as $property) {
            $bare = ltrim($property, '-');
            if (preg_match('/(?<![a-z0-9-])(?:--)?' . preg_quote($bare, '/') . '(?![a-z0-9-])/i', $text)) {
                $found[] = $property;
            }
        }
        return $found;
    }

    /**
     * The names THIS component can write: the registry plus its own declared slots and chrome
     * properties. Derived from the live registry and the component's own schema, so it can
     * never drift into a hand-maintained allowlist — and scoped per component, so one schema
     * cannot legalise a name for the other eleven.
     *
     * @param array<string, mixed> $schema
     * @return array<string, true>
     */
    private function namesThisComponentCanReach(array $schema): array
    {
        $registry = pp_design_tokens();
        $this->assertNotEmpty($registry, 'the design-token registry read returned nothing');

        $reachable = [];
        foreach (array_keys($registry) as $token) {
            $reachable[$token] = true;
        }
        foreach (array_keys($schema['styling']['style_slots'] ?? []) as $slot) {
            $reachable[$slot] = true;
        }
        foreach (($schema['styling']['chrome_custom_properties'] ?? []) as $property) {
            $reachable[$property] = true;
        }
        return $reachable;
    }

    /**
     * Properties declared in base.css but absent from the registry: the theme's INTERNAL,
     * non-authorable custom properties. Derived by subtracting the first `:root` block (what
     * pp_design_tokens() reads, and exactly what update_design_token accepts) from every
     * `:root` block in the file. Today: --pp-band-padding, --pp-band-padding-adjacent-top,
     * --pp-band-heading-size. A future internal property is covered the day it is declared.
     *
     * @return array<string, true>
     */
    private function propertiesDeclaredButNotRegistered(): array
    {
        $css = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertNotEmpty($css, 'base.css read returned nothing');

        preg_match_all('/:root\s*\{([^}]*)\}/s', $css, $blocks);
        $this->assertGreaterThanOrEqual(2, count($blocks[1]), 'base.css :root block scan collapsed');

        $declared = [];
        foreach ($blocks[1] as $block) {
            preg_match_all('/(--[\w-]+)\s*:/', $block, $m);
            foreach ($m[1] as $property) {
                $declared[$property] = true;
            }
        }

        $internal = array_diff_key($declared, pp_design_tokens());
        $this->assertNotEmpty(
            $internal,
            'base.css declares no internal property outside the registry — either the second '
            . ':root block moved or the registry parser widened; both change what '
            . 'update_design_token accepts and must be a deliberate decision.'
        );
        return $internal;
    }

    /**
     * Every string value anywhere in a schema, so the scans above cannot be dodged by
     * moving prose from one metadata key to another.
     *
     * @param mixed $node
     * @return string[]
     */
    private function everyString($node): array
    {
        if (is_string($node)) {
            return [$node];
        }
        if (!is_array($node)) {
            return [];
        }
        $out = [];
        foreach ($node as $child) {
            foreach ($this->everyString($child) as $string) {
                $out[] = $string;
            }
        }
        return $out;
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

    // ── #601: the steps connector is clipped, so nothing may claim it is reachable ──

    /**
     * The stylesheets a front-end page actually loads, in cascade order.
     *
     * functions.php:96-119 enqueues exactly these three (`pp-base`, `pp-components`,
     * `pp-utilities`), and the admin preview at lib/admin.php:2934 links the same three,
     * so a rule in ANY of them reaches a grid card. Scanning components.css alone would
     * let an `overflow` reset land in base.css or utilities.css and un-clip the connector
     * with this pin still green. pp-admin-editor.css / pp-ai-chat.css are admin chrome
     * and never style a rendered card, so they stay out.
     */
    private const FRONT_END_STYLESHEETS = [
        '/assets/css/base.css',
        '/assets/css/components.css',
        '/assets/css/utilities.css',
    ];

    /**
     * Innermost CSS rules as [source, selector, body] triples, comments stripped.
     *
     * Media context is deliberately dropped: a rule inside `@media` parses as its own
     * triple, which is what these pins want — a clip does not care which breakpoint the
     * clipped rule lives at, and neither does an escape from it.
     *
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function frontEndCssRules(): array
    {
        $rules = [];
        foreach (self::FRONT_END_STYLESHEETS as $sheet) {
            $css      = file_get_contents($this->themeRoot . $sheet);
            $stripped = preg_replace('#/\*.*?\*/#s', '', $css);
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $stripped, $m, PREG_SET_ORDER);
            foreach ($m as $r) {
                foreach (explode(',', $r[1]) as $single) {
                    $selector = trim(preg_replace('/\s+/', ' ', $single));
                    if ($selector === '' || str_starts_with($selector, '@')) {
                        continue;
                    }
                    $rules[] = [$sheet, $selector, $r[2]];
                }
            }
        }
        $this->assertNotEmpty($rules, 'no front-end CSS rules parsed');
        return $rules;
    }

    /**
     * The last compound of a selector — the element the rule actually styles.
     *
     * Parenthesised groups are masked before the split so a combinator INSIDE a functional
     * pseudo-class cannot be mistaken for the real one: `.grid__list:has(> .grid__item) > li`
     * has subject `li`, not `.grid__item)`. The mask is restored on the way out, so the
     * returned subject is still the literal source text.
     */
    private function selectorSubject(string $selector): string
    {
        $masked = preg_replace_callback(
            '/\(([^()]*)\)/',
            static fn ($m) => '(' . str_repeat('\u{00b7}', mb_strlen($m[1])) . ')',
            trim($selector)
        );
        $offset = 0;
        foreach (preg_split('/\s*[ >+~]\s*/', $masked, -1, PREG_SPLIT_OFFSET_CAPTURE) as $part) {
            $offset = $part[1];
        }
        return trim(mb_substr(trim($selector), $offset));
    }

    /**
     * Could this rule style a grid CARD element itself (not a descendant, not a pseudo)?
     *
     * Three shapes qualify, and the last two are why this is not a simple `.grid__item`
     * match: the card is an `<li>`, so `.grid__list > li { overflow: visible }` un-clips it
     * without ever naming the class, and a universal reset reaches it too.
     *
     * Deliberately SELECTOR-shaped, not cascade-shaped. A bare `li` / `*` escape might in
     * fact lose the cascade to `.grid__item { overflow: hidden }` and clip anyway, so this
     * predicate over-includes on purpose: it fails closed and asks a human to re-derive the
     * clip, which is the safe direction for a pin whose whole job is to notice that the
     * connector became paintable. `subjectNamesAGridCard()` is the strict half, used where
     * the argument needs the rule to be about the card and not merely able to reach it.
     */
    private function subjectCanBeAGridCard(string $selector): bool
    {
        $subject = $this->selectorSubject($selector);
        if (str_contains($subject, '::')) {
            return false; // a pseudo-element box, not the card
        }
        // Strip pseudo-classes so `.grid__item:hover` / `li:first-child` still match.
        $bare = preg_replace('/:[a-z-]+(\([^)]*\))?/i', '', $subject);
        if (preg_match('/\.grid__item(?![-\w])/', $bare)) {
            return true;
        }
        return $bare === 'li' || $bare === '*';
    }

    /** The strict half: the rule's subject NAMES the card class. */
    private function subjectNamesAGridCard(string $selector): bool
    {
        $subject = $this->selectorSubject($selector);
        return !str_contains($subject, '::') && (bool) preg_match('/\.grid__item(?![-\w])/', $subject);
    }

    /**
     * #601 — the steps connector renders NOWHERE, and the schema said twice that it did.
     *
     * `.grid--steps .grid__item:not(:last-child)::after` is `position: absolute; left: 100%`,
     * and its containing block is the card itself (`.grid--steps .grid__item` and
     * `main > .grid .grid__item` are both `position: relative`). The card sets
     * `overflow: hidden`, so it clips its own connector away at every viewport. The box
     * still COMPUTES — verified by rendered A/B against the shipped stylesheets: at an
     * identical clip region there is no segment with `overflow: hidden` and a visible 1px
     * segment with a prototype-only `overflow: visible`, and `getComputedStyle(li, '::after')`
     * reports `width: 32px` unset, `64px` under a grid-level `--grid-gap: 4rem`. A second,
     * independent pass swept 18 rendered contexts (900-1920px, the muted / inverted / uniform
     * / icon variants, 2-4 items, hover, wrapped titles, with and without the composed-page
     * `<main>` wrapper, and both slots written at grid and card level) and measured ZERO
     * differing pixels against a build with the connector suppressed, with two positive
     * controls proving the probe was sensitive. Computing is not painting, so `--grid-gap`
     * and `--grid-item-border-color` reach nothing on the steps layout. Both slots used to
     * advertise that reach ("DUAL JOB"), which is the reported-success-without-effect class
     * this whole gate exists to remove.
     *
     * Provenance: d78a194 (#177) deleted the rescue override carrying `overflow: hidden` AND
     * the canonical `.grid--steps .grid__item { overflow: visible }` reset, while introducing
     * the line rule the reset had been protecting. The #56 guard in tests/js/css-lint.test.ts
     * pins declaration-site LOCATION only, so it stayed green through the regression.
     *
     * This pin is deliberately two-sided, and BOTH sides are load-bearing:
     *   - the three CSS facts that make the connector unpaintable (the card clips, nothing
     *     hands the clip back, and the card is the pseudo-element's containing block), so a
     *     source reader cannot re-derive a capability from the rule text alone;
     *   - the ABSENCE of any reachability claim on the surfaces an authoring agent reads.
     * If #670 changes the CSS in either direction — unclipping the connector or deleting the
     * rule — this test fails on purpose. It does not prescribe which way to go, and it is not
     * asking for the old text back: it asks that the CSS and the claim surfaces be decided
     * together in one change.
     */
    public function testNothingClaimsTheStepsConnectorIsReachableWhileTheCardClipsIt(): void
    {
        $rules = $this->frontEndCssRules();

        // ── side 1: the CSS facts that make the connector unpaintable ──────────────
        $clips          = [];
        $connectorRules = [];
        $escapes        = [];
        $positioned     = [];
        foreach ($rules as [$sheet, $selector, $body]) {
            // Any ::after painted on the card itself, however the rule spells the card.
            $subject = $this->selectorSubject($selector);
            if (str_ends_with($subject, '::after') && preg_match('/\.grid__item(?![-\w])/', $subject)) {
                $connectorRules[] = [$selector, $body];
            }

            if (!$this->subjectCanBeAGridCard($selector)) {
                continue;
            }
            // `overflow: visible` (or any non-clipping value) hands the connector its escape.
            // Resolved PER RULE, not per declaration, because the axes interact: when one of
            // overflow-x / overflow-y is `visible` and the other is not, the `visible` one
            // computes to `auto` — which still clips. So a rule that mentions ANY clipping
            // value clips, and only an all-`visible` rule is a real escape.
            preg_match_all('/(?<![-\w])overflow(?:-[xy])?\s*:\s*([^;}]+)/i', $body, $decls);
            if ($decls[1] !== []) {
                $values = array_map('trim', $decls[1]);
                $joined = implode(' / ', $values);
                if (preg_match('/hidden|clip|auto|scroll/i', $joined)) {
                    if ($this->subjectNamesAGridCard($selector)) {
                        $clips[] = $sheet . ': ' . $selector;
                    }
                } else {
                    $escapes[] = $sheet . ': ' . $selector . ' { overflow: ' . $joined . ' }'
                        . ($this->subjectNamesAGridCard($selector) ? '' : '  [reaches the card without naming it]');
                }
            }
            // The clip only reaches an absolutely positioned pseudo-element when the card is
            // its containing block, which needs a positioned card.
            if (
                $this->subjectNamesAGridCard($selector)
                && preg_match('/(?<![-\w])position\s*:\s*(relative|absolute|sticky|fixed)/i', $body)
            ) {
                $positioned[] = $selector;
            }
        }

        $this->assertNotEmpty(
            $clips,
            'No rule clips a grid card any more. That is exactly what would let the '
            . 'steps connector paint for the first time — see #670. If this is intentional, '
            . 'the connector now renders and the slot descriptions, components/grid/README.md, '
            . 'AI_CONTEXT.md and ai-instructions/composition.md all have to be revisited in '
            . 'this same change, in whichever direction #670 settled.'
        );
        $this->assertSame(
            [],
            $escapes,
            'A rule that can style a grid card declares a non-clipping overflow. The steps '
            . 'connector is `position: absolute; left: 100%` inside the card, so this is the '
            . 'switch that makes it paint — see #670. This check is selector-shaped, not '
            . 'cascade-shaped: an entry marked [reaches the card without naming it] may still '
            . 'lose to `.grid__item { overflow: hidden }` on specificity, in which case the '
            . 'connector stays clipped and this pin needs its predicate narrowed rather than '
            . 'the claim surfaces rewritten. Re-derive the cascade before believing either.'
        );
        $this->assertNotEmpty(
            $positioned,
            'No rule positions the grid card any more. A `position: static` card is not the '
            . 'containing block of its own absolutely positioned ::after, so the card\'s '
            . '`overflow: hidden` stops clipping the connector and it paints — see #670. This '
            . 'is the half of the clip argument that the overflow pins alone do not cover.'
        );

        $this->assertCount(
            1,
            $connectorRules,
            'The set of ::after rules painted on a grid card changed. The connector is '
            . 'currently a dead rule (clipped by the card), and every claim surface is written '
            . 'on the premise that it paints nothing. If #670 resolved by deleting, rebuilding '
            . 'or adding to it, update those surfaces in the same change.'
        );
        $this->assertMatchesRegularExpression(
            '/(?<![-\w])(?:left|right)\s*:\s*100%/',
            $connectorRules[0][1],
            'The connector sits outside the card box at `left: 100%`, which is WHY the card\'s '
            . 'own `overflow: hidden` erases it. A connector positioned INSIDE the card would '
            . 'paint, and every claim surface would need rewriting — see #670.'
        );

        // ── side 2: no surface an authoring agent reads claims the connector is reachable ──
        //
        // Two needles per surface. The literal one catches the exact wording #601 removed; the
        // shape one catches the same promise reworded (a "line/rule/segment drawn between the
        // badges"), which the literal needle alone let straight back in. Both stay anchored on
        // "badge" so AI_CONTEXT.md's unrelated WP 7.0 AI "connectors" prose cannot trip them.
        $shapedClaim = '/(?:connector|line|rule|segment)[^.\n]{0,80}(?:between|from|joining|linking|connecting)[^.\n]{0,40}(?:badge|step)'
            . '|(?:badge|step)[^.\n]{0,60}(?:connector|connected by|joined by|linked by)'
            . '|connectors? at desktop/i';

        // EVERY slot, not only the two that carried the claim — it could reappear on any of
        // them, and a slot-scoped check is as cheap over 37 as over 2.
        foreach ($this->slots('grid') as $slot => $def) {
            $description = $def['description'] ?? '';
            $this->assertStringNotContainsStringIgnoringCase(
                'connector',
                $description,
                "Slot {$slot} claims it reaches the steps connector. The connector computes a "
                . 'value the card then clips away, so the claim promises an author a visual '
                . 'that cannot render (#601). If #670 made the connector paint, say so there '
                . 'AND here.'
            );
            $this->assertDoesNotMatchRegularExpression($shapedClaim, $description, "Slot {$slot} (reworded claim).");
        }

        $gridSchema = $this->allSchemas()['grid'];
        $claimBearingSchemaProse = [
            'layout prop'      => $gridSchema['props']['layout']['description'] ?? '',
            'items[].style'    => $gridSchema['props']['items']['items']['style']['description'] ?? '',
        ];
        foreach ($claimBearingSchemaProse as $where => $prose) {
            $this->assertStringNotContainsStringIgnoringCase('connector', $prose, "grid schema {$where}");
            $this->assertDoesNotMatchRegularExpression($shapedClaim, $prose, "grid schema {$where} (reworded claim).");
        }

        // Whole-document scan, NOT line-scoped-to-"steps": the sentence that carried this
        // claim in composition.md lives under a `### grid.layout: "steps"` heading and does
        // not contain the word itself, so a proximity filter reads green over the exact
        // wording being removed here (caught by mutating the doc back and watching it pass).
        foreach (['/ai-instructions/composition.md', '/AI_CONTEXT.md'] as $doc) {
            $this->assertDoesNotMatchRegularExpression(
                $shapedClaim,
                file_get_contents($this->themeRoot . $doc),
                "{$doc} still promises a connector line on the steps layout; it renders "
                . 'nowhere (#601). The dead rule is recorded in components/grid/README.md '
                . 'and #670, deliberately not on an authoring surface.'
            );
        }

        // components/grid/README.md is scanned SECTION-scoped, not whole-file: its stated-
        // defaults table deliberately keeps a connector row (that row is where the dead rule
        // is recorded), so a whole-file needle would fail on the intended text. The authoring
        // prose above it must still stay clean, or the removed claim comes back on the very
        // surface it was removed from.
        $readme  = file_get_contents($this->themeRoot . '/components/grid/README.md');
        $start   = strpos($readme, '## Steps layout');
        $this->assertNotFalse($start, 'components/grid/README.md lost its `## Steps layout` section.');
        $end     = strpos($readme, "\n## ", $start + 1);
        $section = substr($readme, $start, $end === false ? null : $end - $start);
        $this->assertDoesNotMatchRegularExpression(
            $shapedClaim,
            $section,
            'The README Steps-layout prose promises a connector again; it renders nowhere '
            . '(#601). The dead rule belongs in the stated-defaults table below it, which is '
            . 'where the clip and #670 are recorded.'
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
