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
 *   .logos__heading         ├─ ONE rule, in     .logos__heading         -> its `heading` role
 *   .embed__heading         │  the SECTION      .embed__heading         -> --embed-heading-measure
 *   .cta__title             │  block, reading   .cta__title             -> --cta-heading-measure
 *   .stats__heading         ┘  --cta-heading-   .stats__heading         -> its `heading` role
 *                              measure               (all six reach var(--measure-heading);
 *                                                    the AFTER column is the #578 severance
 *                                                    as it stands TODAY — eight of the nine
 *                                                    band components are on roles, and only
 *                                                    grid still owns a measure SLOT)
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
    // The components that route the shared --measure-heading token through a
    // style slot. This is the v1 STYLE-SLOT roster; testimonials left it when it was rebuilt on the Universal Design Contract (v2) and now declares roles instead of slots: its heading cap is the `heading` role's
    // `sizing.max-width` default, which still resolves @measure-heading, so the
    // shared token still governs it — through the engine rather than through a slot.
    // cta left this roster at #1026 and, unlike testimonials, it still ROUTES the token:
    // its `heading` role and its `text` role both default `sizing.max-width` to
    // `@measure-heading`, so one `update_design_token` write still reaches the band. What
    // changed is the address, not the routing — which is why the roster below is named for
    // the slot mechanism rather than for the capability.
    // faq left at #1046. Its heading measure is the `heading` role's
    // `sizing.max-width`, still defaulting to `@measure-heading`, so the CAPABILITY this
    // roster is about — one design-token write reaching every band heading — is
    // unchanged; only the address moved. The v2 half is pinned in
    // FaqRoleDefaultsEmitTest, against the EMITTED declaration rather than schema text.
    // table left at #1066, on the identical footing: its `heading` role's
    // `sizing.max-width` still defaults to `@measure-heading`, so ONE update_design_token
    // write still reaches it, and TableRoleDefaultsEmitTest asserts that emitted
    // `max-width:var(--measure-heading)` directly.
    //
    // RE-FOUNDED AT #1066 PR2, WHICH IS THE DECISION THIS CONSTANT'S OWN NOTE DEMANDED.
    // That note read: "this roster is named for the SLOT MECHANISM, and the slot mechanism
    // is ending: stats and logos leave in #1066's second half, which leaves
    // ROUTED = ['grid'] and, once grid's own rebuild lands, empty. A one-element roster is
    // not a surface audit, it is a single component's test wearing one. Decide this FILE's
    // fate in the PR that removes stats and logos — retire it in favour of the
    // per-component emit tests, or re-found it on the ROLE address so it keeps auditing
    // the capability rather than the mechanism — rather than discovering it one rebuild
    // later." Both left; this is the re-founding it asked for.
    //
    // THE CAPABILITY IS WORTH KEEPING AND THE SLOT SHAPE IS NOT: what this file exists to
    // protect is that ONE `update_design_token` write to `--measure-heading` reaches every
    // band heading in the theme. On v1 that was "every heading-measure slot defaults to
    // var(--measure-heading)". On v2 it is "every band component's `heading` role defaults
    // `sizing.max-width: @measure-heading`" — the same claim, one vocabulary later, and it
    // now covers NINE components instead of three. The v2 arm is
    // testOneDesignTokenWriteStillReachesEveryBandHeading below; this roster keeps only the
    // components still on slots, and empties when grid rebuilds.
    private const ROUTED = ['grid'];

    /**
     * EMPTY SINCE #1023, and kept rather than deleted because the emptiness is the fact.
     *
     * This held the components that declared a heading-measure slot but deliberately did
     * NOT route the shared --measure-heading token — an intentional difference a later
     * "consistency" pass must not quietly fold in. hero left the whole surface at #986,
     * section at #1023, and they were the only two entries. Both now cap their heading
     * through a role's `sizing.max-width` instead of a slot: hero's `title`, section's
     * `heading` (declared `none`, the uncapped value section's slot defaulted to).
     *
     * The constant stays so the ROUTED-vs-EXEMPT distinction keeps a name, and so a
     * component that needs the exemption again has somewhere to be declared. Every loop
     * over it below is guarded by the emptiness pin directly under this comment, so an
     * empty set cannot make a test pass vacuously.
     */
    private const EXEMPT = [];

    /**
     * The guard that stops EXEMPT's emptiness reading as "all clear" (#1023).
     *
     * Three tests below loop over EXEMPT, and a foreach over [] asserts nothing while
     * reporting green. That is the vacuous-pass class, so the emptiness is asserted
     * OUT LOUD here: if a component is ever added back, this fails and the reader is
     * sent to the loops that then start doing work again.
     */
    public function testTheExemptRosterIsEmptyAndItsLoopsAreThereforeInert(): void
    {
        $this->assertSame([], self::EXEMPT,
            'EXEMPT is empty since hero (#986) and section (#1023) left the slot surface. '
            . 'If you add a component here, the three loops over EXEMPT below stop being '
            . 'inert — read them before trusting a green run.');
    }

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

    /** Every band component STILL ON SLOTS declares a heading measure (hero and testimonials are on roles). */
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

    /**
     * THE BODY MEASURE, NOW ASSERTED ON THE v2 SIDE — because the slot side is EMPTY.
     *
     * section left the slot roster at #1023, cta at #1026, faq at #1046 and embed at
     * #1066. embed was the LAST declarer, and an empty `foreach` is a test that cannot
     * fail: the #1038 shape exactly, and the reason this method was rewritten rather than
     * narrowed to nothing or deleted.
     *
     * THE CLAIM SURVIVES INTACT AND IS WHAT IS ASSERTED HERE: a component that renders a
     * prose body can cap that body's measure, INDEPENDENTLY of the band heading measure.
     * #578 separated those two surfaces deliberately — they resolve to the same 640px
     * today and that is a coincidence, so folding one into the other would silently
     * re-flow embedded content on the next heading-scale retune.
     *
     * The map below is WRITTEN OUT, not derived, and the distinction is worth stating
     * because an earlier draft of this sentence claimed the opposite: "which role renders
     * a component's prose body" is a judgement about markup that no registry field
     * records, so it cannot be derived. What that costs is real — a later rebuild has to
     * come here and add its row — and the `assertGreaterThanOrEqual(4, $checked)` below is
     * what stops the map silently shrinking instead. faq is the deliberate exception and
     * is asserted as one: v1
     * declared `max-width: var(--faq-body-measure, none)` and RENDERED `none` at every
     * tier, so its `answer` role declares no measure and that is the faithful port.
     */
    public function testEveryProseBodyRoleCanStillCapItsOwnMeasure(): void
    {
        // component => the role that renders its prose body.
        $bodyRoles = ['section' => 'body', 'cta' => 'body', 'embed' => 'content', 'faq' => 'answer'];
        $checked   = 0;

        foreach ($bodyRoles as $component => $role) {
            $roles = pp_udc_component_roles($component);
            $this->assertArrayHasKey($role, $roles, "{$component} must still declare a `{$role}` role");
            $this->assertContains(
                'sizing',
                $roles[$role]['groups'],
                "{$component}.{$role} must permit `sizing`, or its body measure is unauthorable"
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(4, $checked, 'the roster went empty — this test would prove nothing');

        // embed keeps a real DEFAULT, and it is its own literal rather than the shared
        // heading token. That is the #578 separation, pinned where it can be seen.
        $this->assertSame(
            '40rem',
            pp_udc_component_roles('embed')['content']['defaults']['sizing']['max-width'] ?? null,
            'embed\'s body measure must keep its own literal, not route @measure-heading'
        );

        // faq's absence is the deliberate one.
        $this->assertArrayNotHasKey(
            'sizing',
            pp_udc_component_roles('faq')['answer']['defaults'] ?? [],
            'faq\'s answer renders `none` on v1 and must declare no measure — silence is the port'
        );
        // THE NEGATIVE CONTROL, RE-POINTED AT THE v2 ADDRESS (#1066 review).
        //
        // It used to read `assertArrayNotHasKey('--testimonials-body-measure',
        // $this->slots('testimonials'))`. testimonials has been v2 since #958, so
        // `slots()` returned `[]` and the assertion could not fail — vacuous, inside a
        // method whose whole docblock is about not shipping assertions that cannot fail.
        // Caught by substituting an invented name, which also passed.
        //
        // The CLAIM it was making is still worth holding: testimonials renders quotes,
        // not prose, and deliberately carries no body measure on either system. Asserted
        // where that is now decidable — on the role's defaults.
        // THE ROLE'S EXISTENCE FIRST. Without this the `?? []` below makes the assertion
        // pass on an empty array if `quote` is ever renamed or dropped — which would
        // reproduce, in the replacement, the exact vacuity the replacement was written to
        // fix. Caught on the #1066 adversarial pass.
        $testimonials = pp_udc_component_roles('testimonials');
        $this->assertArrayHasKey('quote', $testimonials, 'testimonials must still declare a `quote` role');
        $this->assertArrayNotHasKey(
            'sizing',
            $testimonials['quote']['defaults'] ?? [],
            'testimonials never declared a body measure on either system, and the quote '
            . 'role must not acquire one by drift'
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
    /**
     * THE v2 ARM, AND THE REASON THIS FILE SURVIVED ITS OWN ROSTER EMPTYING.
     *
     * The capability under audit has never been "a slot exists". It is that ONE
     * `update_design_token` write to `--measure-heading` re-flows every band heading in the
     * theme, so a site can tighten its measure once instead of nine times. The v1 spelling
     * of that was a slot defaulting to `var(--measure-heading)`; the v2 spelling is the
     * `heading` role defaulting `sizing.max-width: @measure-heading`.
     *
     * DERIVED, NOT LISTED, so a component rebuilt in a later sprint is covered the moment
     * it lands rather than when someone remembers this file. The one deliberate exception
     * is recorded inline: hero caps its title through a different role and section declares
     * `none` outright, and both are v2 components whose own emit tests pin those values.
     */
    public function testOneDesignTokenWriteStillReachesEveryBandHeading(): void
    {
        // hero's cap lives on `title`, and section's `heading` declares `none` deliberately
        // (the uncapped value its slot defaulted to). Both are pinned in their own emit
        // tests; naming them here keeps this sweep honest rather than silently skipping.
        // nav and footer are CHROME: template-owned, not composable bands, and their
        // headings are a footer column label rather than a band heading. They are not part
        // of the measure surface and never were.
        $exceptions = ['hero', 'section', 'nav', 'footer'];

        $checked = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode(file_get_contents($file), true);
            $heading   = $schema['roles']['heading'] ?? null;
            if ($heading === null || in_array($component, $exceptions, true)) {
                continue;
            }
            $this->assertSame(
                '@measure-heading',
                $heading['defaults']['sizing']['max-width'] ?? null,
                "{$component}'s `heading` role must cap through the SHARED @measure-heading "
                . 'token — a literal here opts that band out of a site-wide measure retune, '
                . 'silently, which is the whole defect this surface audit exists to catch'
            );
            $checked[] = $component;
        }

        sort($checked);
        // Fail-closed AND exact: a shrinking sweep means a component stopped routing the
        // token (or the schema glob broke), and a growing one is a new band that should be
        // reviewed here rather than assumed compliant.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'logos', 'stats', 'table', 'testimonials'],
            $checked,
            'the v2 band headings that route the shared measure token'
        );
    }

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
        // --section-heading-measure left this set at #1023, --cta-body-measure at #1026 and
        // --faq-body-measure at #1046. All three are the v2 side now, and all three stay
        // uncapped: section's `heading` declares `none` explicitly, while cta's `body` and
        // faq's `answer` declare NO max-width at all — the faithful port, because v1
        // declared `none`, rendered `none`, and `none` IS the initial value. An absent
        // default and an explicit `none` render identically; the difference is that those
        // two say nothing rather than saying the initial value.
        //
        // THE SET IS EMPTY, AND EMPTY IS AN ASSERTION HERE RATHER THAN AN ABSENCE. The
        // loops above still run over every slot-bearing component, so this pin says "no
        // slot declares an uncapped default any more", which is a fact a new slot would
        // break. The capability it guards — A-30's "a declared default must be
        // authorable" — did not leave with the last slot: on the v2 side `length-or-none`
        // is the declared PARAM type, so `none` is writable through the udc map by
        // construction, and no per-component pin can drift away from it.
        $this->assertSame(
            [],
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
        // section's body measure went with its slot map at #1023 (the `body` role's
        // `sizing.max-width`), the way hero's content measure went at #986, and embed's
        // — the LAST body-measure slot in the theme — at #1066. The v2 half of that
        // claim is testEveryProseBodyRoleCanStillCapItsOwnMeasure above, which is
        // registry-derived and asserts the capability rather than the slot name.
        // hero's measure used to be spelled --hero-content-width — the reason the engine
        // reads a declared role rather than a `-measure` name suffix. It left this surface
        // in #986: hero is a v2 component and its measure is the `content` role's
        // `sizing.max-width`. The naming lesson still holds for whoever adds the next
        // oddly-spelled measure slot, which is why the note stays.
        // DELIBERATELY ABSENT, AND STILL ABSENT ON THE v2 SIDE: the stats band's own cap.
        // It was `--stats-max-width` and is the `_band` role's `sizing.max-width` now. It
        // caps the BAND's box (a contained, centered card — issue 383), not a run of text,
        // so it is band geometry rather than a text measure and must NOT route
        // `@measure-heading`. The boundary is recorded so a future reader can tell it from
        // an oversight, and asserted on the address it lives at now: `_band` permits the
        // cap but does not default one, so an unset band stays full-bleed.
        $band = pp_udc_component_roles('stats')['_band'];
        $this->assertContains('sizing', $band['groups'], 'the band cap must still be authorable');
        $this->assertArrayNotHasKey(
            'max-width',
            $band['defaults']['sizing'] ?? [],
            'the stats band cap is geometry, not a text measure: defaulting it would both '
            . 'un-full-bleed every band and drag band geometry into a text-measure retune'
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
    /**
     * #578's SEVERANCE, ASSERTED AT THE ADDRESS IT LIVES AT NOW.
     *
     * The defect #578 recorded: six band headings were capped from ONE shared selector list
     * that read `var(--cta-heading-measure, …)`, so five of them were reading a slot they
     * could neither SET (the write path refuses a foreign slot) nor RESOLVE (a slot custom
     * property is emitted on its owner's root). It rendered as a literal wearing a `var()`
     * costume. The fix gave each component its own slot, read inside its own block.
     *
     * Every member of that roster is now a v2 component — faq (#1046), table and embed
     * (#1066), and logos and stats in #1066's second half — so there is no block-scoped
     * slot read left anywhere to check. THE SEVERANCE SURVIVES AS A STRONGER FACT: a
     * measure is a role's own `sizing.max-width`, emitted at a band-scoped selector, and no
     * component can address another's role at all. That is asserted here rather than
     * inferred, because "stronger by construction" is exactly the claim a rebuild is most
     * tempted to assert without checking.
     */
    public function testNoComponentsHeadingMeasureCanBeReachedByAnother(): void
    {
        $withHeading = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode(file_get_contents($file), true);
            if (!isset($schema['roles']['heading']['defaults']['sizing']['max-width'])) {
                continue;
            }
            $withHeading[] = $component;

            // The emitted selector is scoped to THIS band's id and THIS component, so the
            // declaration cannot be read by a sibling band even if it wanted to.
            $css = pp_udc_band_css([
                'component' => $component,
                'id'        => 'pp-1a2b3c4d',
                'props'     => [],
                'udc'       => ['heading' => ['sizing' => ['max-width' => '31rem']]],
            ]);
            $this->assertStringContainsString('31rem', $css, "{$component}'s authored measure must emit");
            $this->assertStringContainsString(
                '[data-pp-band="pp-1a2b3c4d"]',
                $css,
                "{$component}'s measure must be scoped to its own band, which is what makes "
                . 'the #578 severance structural rather than conventional'
            );
        }

        sort($withHeading);
        // EIGHT, and section is in this list while being EXCLUDED from the token-routing
        // sweep above — two sweeps asking two different questions of the same roster.
        // Measured: section's `heading` caps at the LITERAL `40rem`, not at
        // `@measure-heading`. So it is severed (its measure is its own role's, reachable by
        // no other component) but not routed (a site-wide measure retune does not move it).
        // That literal is section's own recorded decision, pinned in its emit test; it is
        // named here so this roster's membership reads as deliberate rather than accidental.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'logos', 'section', 'stats', 'table', 'testimonials'],
            $withHeading,
            'the components whose heading carries a measure — a shrink means one stopped '
            . 'capping its heading, a growth means a new band to review here'
        );
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
        foreach (['table', 'faq', 'logos', 'embed', 'stats', 'grid', 'section', 'testimonials'] as $component) {
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

    /**
     * .section__title CARRIES THE MEASURE IT RENDERED AT, and the stylesheet carries none
     * (#1023, corrected at review).
     *
     * THIS TEST PINNED THE WRONG VALUE WITH A BACKWARDS REASON, and that is worth keeping
     * on the record rather than quietly swapping. It asserted `none` because "a 40rem cap
     * would re-wrap every stored section heading". The opposite is true: v1 capped
     * `.section__body` — the wrapper holding the header block, the prose and the trust
     * strip — at `var(--section-body-measure, 40rem)`, and that rule's own comment said
     * "The remaining headings keep 40rem". Every stored section heading was ALREADY
     * wrapping at 640px, so `none` is what re-wraps them, on the most-used band in the
     * product, at every width above 640px.
     *
     * It is the same ancestor fact that produced the `body` role's 40rem, checked for one
     * child of that wrapper and asserted away for another — a winning-rule reading where a
     * rendered-geometry reading was required. The stylesheet half of the pin was always
     * right and is unchanged: a v2 component declares no max-width in CSS at all.
     */
    public function testSectionTitleCarriesTheMeasureItAlwaysRenderedAt(): void
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
        $this->assertSame([], $caps,
            'a v2 component declares no max-width in the stylesheet — the cap belongs to the role.');

        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);

        // Both children of the v1 wrapper, pinned together, because splitting them is how
        // the first draft capped one and freed the other.
        foreach (['heading', 'subheading'] as $role) {
            $this->assertSame(
                '40rem',
                $schema['roles'][$role]['defaults']['sizing']['max-width'],
                "The section {$role} renders at the measure v1's .section__body gave it. "
                . 'Freeing it re-wraps every stored section heading above 640px.'
            );
        }

        // And it agrees with the token the other eight band components route through, so
        // section is consistent rather than the one band whose heading runs the container.
        $tokens = pp_design_tokens();
        $this->assertSame(
            '40rem',
            $tokens['--measure-heading']['value'] ?? $tokens['--measure-heading'] ?? null,
            'the section heading measure and --measure-heading must not drift apart silently'
        );
    }

    /** Kept for the components still on slots: their title cap must route the slot. */
    public function testSlottedTitleCapsStillRouteTheirSlot(): void
    {
        $css = $this->stripComments($this->css());
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $caps = [];
        foreach ($rules as [$whole, $selector, $body]) {
            foreach (explode(',', $selector) as $part) {
                if (!preg_match('/\.(grid__heading|cta__title|faq__heading)(?![-\w])\s*$/', trim($part))) {
                    continue;
                }
                foreach ((array) (preg_match_all('/(?<![-a-z])max-width\s*:\s*([^;}]+)/i', $body, $m) ? $m[1] : []) as $v) {
                    $caps[] = trim($v);
                }
            }
        }
        $this->assertNotEmpty($caps,
            'the slotted components still declare a title cap — if none is found this scan has gone blind.');
        foreach ($caps as $value) {
            $this->assertMatchesRegularExpression(
                '/^var\(\s*--(?:grid|cta|faq)-heading-measure\s*,/',
                $value,
                'a slotted title cap must route its own measure slot.'
            );
        }
    }



    /**
     * RENAMED AND CORRECTED (#1023). The previous name — "collapsed to the branch that
     * actually won" — described the wrong analysis and asserted the wrong number.
     *
     * The branch that won among the rules TARGETING `.section__content` was the desktop
     * `main > .section--text-only` override at 49rem. But that is not what the element
     * rendered: `.section__content` sits inside `.section__body`, which capped at 40rem,
     * so the 49rem literal never bound. Measured in a browser, v1 rendered 640px on a
     * text-only band and 672px on a centered one.
     *
     * A winning rule is not a rendered value. The general form of that is recorded as the
     * threefold-condition lesson (ancestors, media scope, inherit-vs-literal) in #1023.
     */
    public function testTheSectionBodyMeasureIsTheOneThatActuallyRendered(): void
    {
        $css = $this->stripComments($this->css());
        preg_match_all('/max-width:\s*var\(\s*--section-body-measure\s*,\s*([^;]+?)\)\s*;/', $css, $m);
        $this->assertSame([], array_map('trim', $m[1]),
            'the retired slot must have no consumption left in the stylesheet.');

        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertSame(
            '40rem',
            $schema['roles']['body']['defaults']['sizing']['max-width'],
            'The body measure must be 40rem — the width a v1 text-only band actually '
            . 'RENDERED (the .section__body wrapper capped it there), not the 49rem rule '
            . 'that won among the rules targeting .section__content but never bound.'
        );
        // The strip shared that wrapper, so it shared the cap. Pinned together because
        // they are one fact: `max-width: 100%` on the row meant 100% OF THIS.
        $this->assertSame(
            '40rem',
            $schema['roles']['inline-items']['defaults']['sizing']['max-width'],
            'the inline-items row must keep the cap its shared wrapper used to give it.'
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
        // The body-measure row left this provider at #1066 with embed's slot map — it was
        // the last one. Its claim (a body measure is authorable independently of the
        // heading measure) is asserted on the v2 side in
        // testEveryProseBodyRoleCanStillCapItsOwnMeasure, which is registry-derived.
        return $cases;
    }

    /**
     * A-30's SUBJECT, RE-HOMED TO THE SURFACE THAT STILL HAS IT (#1046).
     *
     * This used to author `none` through `style_component` on the one slot that declared
     * it. 'section heading' left the provider at #1023, 'faq body' at #1046, and an empty
     * data provider is a PHPUnit ERROR — so retiring the slot half silently was never an
     * option, and neither was deleting the claim.
     *
     * THE CLAIM SURVIVES THE SLOT: "a declared default must be authorable" is what A-30
     * closed, and on the v2 side it holds by construction rather than by convention —
     * `length-or-none` is the declared PARAM TYPE in the engine's own taxonomy, so every
     * role that can default to `none` can also be written back to `none`, with no
     * per-component pin to drift. Asserted at the engine rather than per slot, which is
     * the stronger place for it: it cannot be true for one component and false for
     * another.
     */
    public function testTheUncappedDefaultIsAuthorable(): void
    {
        $sizing = pp_udc_groups()['sizing']['params'];
        foreach (['max-width', 'max-height'] as $param) {
            $this->assertSame(
                'length-or-none',
                $sizing[$param]['type'] ?? null,
                "sizing.{$param} must keep the grammar that lets an uncapped default be "
                . 'restored — A-30 is a property of the type, not of any one component.'
            );
        }

        // And the round trip, ASSERTED ON THE EMITTED CSS rather than on the validator's
        // verdict. Acceptance is one layer above the thing A-30 is about: an engine change
        // that accepted `none` and then dropped the declaration at emission would leave a
        // validation-only test green while the capability was gone — the repo's own
        // assert-the-emission rule. faq's `answer` carries no measure by default, which
        // makes it the honest subject: narrowing it is the only way to get a cap there.
        foreach (['34rem', 'none'] as $value) {
            $this->assertNull(
                pp_udc_validate_map(['answer' => ['sizing' => ['max-width' => $value]]], 'faq'),
                "the answer measure must accept {$value}"
            );
            $this->assertStringContainsString(
                '.faq__answer{max-width:' . $value . ';}',
                pp_udc_band_css([
                    'component' => 'faq',
                    'id'        => 'pp-1a2b3c4d',
                    'props'     => [],
                    'udc'       => ['answer' => ['sizing' => ['max-width' => $value]]],
                ]),
                "{$value} must REACH THE PAGE, not merely pass validation"
            );
        }
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
        // THE HOST MOVED TWICE, table -> logos -> grid, and the claim never moved at all:
        // ONE component cannot author ANOTHER's slot, which is why #578 had to sever the
        // shared six-selector rule. Each move happened for the same reason — the host
        // became a v2 component, and a v2 component's refusal names its ROLES
        // (`no_style_slots`) rather than the foreign slot, which is a different and better
        // message but not the one under test. table left at #1066's first half, logos at
        // its second, and grid is the last v1 component in the theme: when it rebuilds,
        // this test has no host left and the claim retires with the mechanism.
        $id = pp_create_page('Foreign slot', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => $this->propsFor('grid')]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--cta-heading-measure' => '30rem'],
        ]);

        $this->assertFalse(
            $result['ok'],
            'A grid band could never set --cta-heading-measure, which is exactly why capping its '
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
