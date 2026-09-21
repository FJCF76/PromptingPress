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
 *                                                    as it stood at the time — grid's
 *                                                    rebuild at #1101 took the LAST measure
 *                                                    slot in the theme, so every band
 *                                                    component is on a role now and the
 *                                                    right-hand column reads
 *                                                    `-> its heading role` throughout)
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
 *
 * REPRICED, NOT RETIRED, AT #1101 — and the distinction is the whole reason the file
 * survived its own roster emptying. What it audits is a CAPABILITY: that ONE
 * `update_design_token` write to `--measure-heading` re-flows every band heading in the
 * theme, so a site tightens its measure once instead of nine times. The v1 spelling was a
 * slot defaulting to `var(--measure-heading)`; the v2 spelling is a `heading` role
 * defaulting `sizing.max-width: @measure-heading`. grid's rebuild took the last slot, so
 * every sweep here is now derived from the ROLE address and covers nine components rather
 * than one. Five methods whose subject was the slot MECHANISM retired into the replacement
 * that carries their claim; each retirement is recorded in full on the method that
 * absorbed it, never deleted in silence.
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
    //
    // GRID REBUILT AT #1101, AND THE ROSTER EMPTIED — the event this constant's note has
    // been predicting since #1066 PR2. Grid was the last component in the theme declaring
    // `styling.style_slots` at all, so this is not "one more component moved": it is the
    // end of the slot mechanism, and nothing can join this roster again without a schema
    // declaring a style slot for the first time since #1101.
    //
    // THE CONSTANT STAYS, EMPTY, FOR THE REASON EXEMPT DOES: the ROUTED-vs-EXEMPT
    // distinction keeps a name, the loops that read it keep a subject to be inert ABOUT,
    // and the emptiness is asserted out loud in
    // testTheSlotRostersAreEmptyAndTheirLoopsAreThereforeInert() below rather than being
    // left to a silent `foreach` over []. grid's heading measure moved where every other
    // band's already had: the `heading` role's `sizing.max-width`, still `@measure-heading`,
    // so the capability this file audits is unchanged and now covers NINE bands with no
    // slot-shaped member at all.
    private const ROUTED = [];

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
     * THE GUARD THAT STOPS BOTH ROSTERS' EMPTINESS READING AS "ALL CLEAR" (#1023, widened
     * to ROUTED at #1101), AND THE RECORD OF THE TWO TESTS THAT RETIRED WITH THE MECHANISM.
     *
     * Every loop in this file over ROUTED or EXEMPT is a `foreach` over [], which asserts
     * nothing while reporting green. That is the vacuous-pass class, so the emptiness is
     * stated OUT LOUD here: adding a component to either roster fails this test and sends
     * the reader to the loops that then start doing work again.
     *
     * TWO TESTS HAD NOTHING LEFT BUT THEIR LOOPS AND RETIRED INTO THIS ONE.
     *
     *   `testRoutedComponentsDefaultToTheTokenAndExemptOnesToNone` — a routed component's
     *       schema `default` had to READ `var(--measure-heading)` and an exempt one `none`.
     *       The `default` field is the agent-facing effective default: it is the surface an
     *       authoring AI reads to decide whether a global retune will reach this band, so a
     *       stale one teaches wrong geometry (the #446 defect class, on the measure family).
     *       ITS CLAIM IS testOneDesignTokenWriteStillReachesEveryBandHeading, which asserts
     *       the identical thing at the v2 address — `heading` -> `sizing.max-width` must be
     *       the shared `@measure-heading` token and not a literal — over a DERIVED roster of
     *       nine rather than a listed roster of one.
     *
     *   `testExactlyEightComponentsRouteTheSharedToken` — a cardinality pin: exactly the
     *       listed components route the token, no more, so a "consistency" pass folding in
     *       hero or section had to fail here first. ITS CLAIM is the exact-membership
     *       assertion at the end of that same v2 sweep, which fails on a shrink AND on a
     *       growth for the same reason and over a roster nobody maintains by hand.
     *
     * Neither was deleted for being red: both would have PASSED, vacuously, over their
     * empty rosters. That is why they had to go rather than be narrowed.
     */
    public function testTheSlotRostersAreEmptyAndTheirLoopsAreThereforeInert(): void
    {
        $this->assertSame([], self::EXEMPT,
            'EXEMPT is empty since hero (#986) and section (#1023) left the slot surface. '
            . 'If you add a component here, the loops over EXEMPT below stop being '
            . 'inert — read them before trusting a green run.');

        $this->assertSame([], self::ROUTED,
            'ROUTED is empty since grid (#1101), which was the last component in the theme '
            . 'declaring a style slot of any kind. If you add a component here, the loops '
            . 'over ROUTED below stop being inert — and the two tests recorded in this '
            . 'docblock should come back with the mechanism they audited.');

        // AND THE EMPTINESS IS A FACT ABOUT THE SCHEMAS, NOT ABOUT THESE TWO CONSTANTS.
        // A roster can be emptied by hand; what makes that honest is that no schema
        // declares a slot to put back in it. Derived, so a new declarer fails here on the
        // day it lands rather than when someone remembers this file.
        $declarers = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($schema, basename(dirname($file)) . '/schema.json is not valid JSON');
            if (($schema['styling']['style_slots'] ?? []) !== []) {
                $declarers[] = basename(dirname($file));
            }
        }
        $this->assertSame([], $declarers,
            'a component declares `styling.style_slots` again — the measure SLOT surface '
            . 'has a member for the first time since #1101, and the rosters above are stale');
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

    // renderStored() lived here until #1101. It rendered a stored composition through the
    // real read+render path and re-seeded each band's `style` map into `__pp_style`, so the
    // authoring sweep could assert that an accepted slot value reached the MARKUP and not
    // just storage. No component declares a slot and no component emits an inline style
    // attribute, so its only caller's replacement asserts the emitted band CSS instead
    // (pp_udc_band_css), which is where a v2 value actually lands.

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
            // `image_alt`, not `alt`. The stale spelling survived here because this fixture
            // only ever reached pp_update_composition(), which does not validate; the #1101
            // rewrite routes it through create_page, which does — and named it immediately.
            'logos'        => ['items' => [['image_url' => 'https://e.test/a.png', 'image_alt' => 'a']]],
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
        // update and still refuses a `ch` value — here and on every per-component heading
        // measure, so the reservation cannot be side-stepped by putting the ch on a
        // component instead.
        $this->assertDoesNotMatchRegularExpression(
            '/\d\s*ch\b/',
            pp_design_tokens()['--measure-heading']['value'],
            'The ch-based retune of --measure-heading is reserved for the maintainer. '
            . 'Route the value back rather than picking one here.'
        );

        // THE SIDE-STEP GUARD, RE-POINTED AT THE ROLE ADDRESS (#1101). It used to walk the
        // ROUTED slot defaults; that roster is empty, and a `foreach` over [] would have
        // silently deleted the half of this reservation that actually matters — a `ch`
        // picked on ONE component is exactly the quiet version of the retune. Derived over
        // every `heading` role there is, so it covers a component rebuilt next sprint too.
        $checked = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            $measure   = $schema['roles']['heading']['defaults']['sizing']['max-width'] ?? null;
            if ($measure === null) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\d\s*ch\b/',
                (string) $measure,
                "{$component} must not carry a ch heading measure either — same reservation. "
                . 'A display heading measured in `ch` tracks its type size, which is very '
                . 'likely right and is a maintainer VISUAL decision, not implementation '
                . 'discretion. Route it back.'
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(
            8,
            $checked,
            'the heading-measure sweep found almost no roles — the schema glob or the role '
            . 'shape changed, and this reservation has lost its reach'
        );
    }

    // ── A-6: the declaration surface ─────────────────────────────────────────

    /**
     * A-6's DECLARATION SURFACE, RE-FOUNDED ON THE ROLE ADDRESS (#1101).
     *
     * This asserted that every band component STILL ON SLOTS declared
     * `--<name>-heading-measure`, which is the check that made the surface a SURFACE
     * rather than a handful of components that happened to have one — before #578 it was
     * three-quarters missing, and the quarter that existed leaked across components.
     *
     * Its last member left with grid, so the slot form of the question cannot be asked.
     * The question itself is unchanged and is asked here of the address every band uses
     * now: EVERY band component caps its heading, through a role, with a value a site can
     * reach. Derived rather than listed, so a component rebuilt in a later sprint is
     * covered the day it lands.
     *
     * hero is the one member whose cap is NOT on a `heading` role — it has none. Its
     * heading is capped by the `content` column it sits in (`@measure-centered`), which is
     * why the lookup takes whichever of three candidate roles actually CARRIES the
     * default rather than assuming a name. That is the same lesson the advisory engine
     * learned from hero once already: it reads a declared `role` marker rather than a
     * `-measure` name suffix, because hero's slot was spelled `--hero-content-width`.
     */
    public function testEveryBandComponentDeclaresAHeadingMeasure(): void
    {
        // nav and footer are CHROME: template-owned, not composable bands, and a footer
        // column label is not a band heading. They were never part of this surface.
        $chrome = pp_udc_chrome_names();

        $capped = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            if (in_array($component, $chrome, true)) {
                continue;
            }
            $roles = json_decode((string) file_get_contents($file), true)['roles'] ?? [];

            // The role that actually declares the cap, not the one whose name suggests it.
            $role = null;
            foreach (['heading', 'title', 'content'] as $candidate) {
                if (isset($roles[$candidate]['defaults']['sizing']['max-width'])) {
                    $role = $candidate;
                    break;
                }
            }
            $this->assertNotNull(
                $role,
                "{$component} declares no heading measure on any of `heading`, `title` or "
                . '`content`. The measure surface covers every band: a band with no declared '
                . 'cap renders its heading at the container width, which is the missing '
                . 'three-quarters #578 found.'
            );
            $this->assertContains(
                'sizing',
                $roles[$role]['groups'] ?? [],
                "{$component}.{$role} declares a max-width default it does not permit an "
                . 'author to change — a declared default that is unauthorable is the exact '
                . 'third-state defect A-30 closed, one role along'
            );
            $capped[] = $component;
        }

        sort($capped);
        // Fail-closed AND exact. hero is a member HERE and excluded from the token-routing
        // sweep below — two sweeps asking two different questions of one roster: hero caps
        // its title (at @measure-centered, its own content measure) but deliberately does
        // not route the shared heading token.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'hero', 'logos', 'section', 'stats', 'table', 'testimonials'],
            $capped,
            'every band component, each declaring a heading measure on a role'
        );
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
        //
        // GRID JOINED THIS ROSTER AT #1101 — it did not leave a surface, it CHANGED ADDRESS.
        // Its slot defaulted to `var(--measure-heading)` and its `heading` role now defaults
        // `sizing.max-width: @measure-heading`, which is the same token through the engine
        // instead of through an inline custom property. That is the whole point of the
        // re-founding this constant's note asked for at #1066 PR2: the capability audited
        // here never moved, so grid's rebuild grows this list rather than shrinking it.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'logos', 'stats', 'table', 'testimonials'],
            $checked,
            'the v2 band headings that route the shared measure token'
        );
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
        // DERIVED OVER EVERY COMPONENT SINCE #1101, not over the ROUTED/EXEMPT rosters.
        // Those are empty, so iterating them would make the pin at the foot of this method
        // a statement about two hand-maintained constants rather than about the schemas —
        // true either way, but only one of the two is a fact a new slot could break.
        $noneDefaulted = [];
        $lengthTyped   = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
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
        // BOTH SETS ARE EMPTY, AND EMPTY IS AN ASSERTION HERE RATHER THAN AN ABSENCE. The
        // loops above run over EVERY component in the theme, so these two pins say "no
        // measure slot is declared anywhere, with either grammar" — which is a fact a new
        // slot would break, rather than a restatement of an empty roster. grid was the last
        // declarer and left at #1101. The capability the method guards — A-30's "a declared
        // default must be authorable" — did not leave with the last slot: on the v2 side
        // `length-or-none` is the declared PARAM type, so `none` is writable through the
        // udc map by construction, and no per-component pin can drift away from it
        // (asserted in testTheUncappedDefaultIsAuthorable, on the EMITTED declaration).
        $this->assertSame(
            [],
            $this->sortedKeys($noneDefaulted),
            'The set of uncapped-by-default measure slots changed. That is a render decision, '
            . 'not a refactor — update this pin deliberately.'
        );
        $this->assertSame(
            [],
            $this->sortedKeys($lengthTyped),
            'A length-typed measure slot is declared again — the first since #1101. The loop '
            . 'above starts enforcing the plain `length` grammar on it, and the A-30 '
            . 'third-state boundary needs re-reading before that is trusted.'
        );
    }

    private function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);
        return $keys;
    }

    /**
     * THE `role: "measure"` MARKER HAS NO DECLARER LEFT (#1101), AND THE BOUNDARY IT
     * SURROUNDED — band geometry is not a text measure — IS STILL ASSERTED.
     *
     * This required every measure slot to carry the declared `role: "measure"` marker the
     * advisory engine reads, and pinned the roster of markers exactly in both directions.
     * The marker exists because a `-measure` NAME suffix is not a reliable signal: hero's
     * measure was spelled `--hero-content-width`, which is the lesson that produced the
     * declared marker in the first place and is why the note survives its slot.
     *
     * Grid was the last slot-bearing component, so both sides of the old comparison are
     * empty and comparing them would be the vacuous pass this file keeps refusing. What is
     * asserted instead: no slot declares the marker anywhere, DERIVED over every schema so
     * a re-added measure slot fails here rather than joining an unmaintained roster — and
     * the stats band-cap boundary below, which was always the substantive content of this
     * method and is entirely v2 already.
     */
    public function testNoSlotDeclaresTheMeasureMarkerAndTheStatsBandCapIsStillNotAMeasure(): void
    {
        $markers = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            foreach (($schema['styling']['style_slots'] ?? []) as $name => $def) {
                if (($def['role'] ?? null) === 'measure') {
                    $markers[] = "{$component} {$name}";
                }
            }
        }
        sort($markers);
        $this->assertSame(
            [],
            $markers,
            'a style slot declares `role: "measure"` again. The advisory consumer is still '
            . 'deferred (#610, see the two pins at the foot of this file), so a marker with '
            . 'a declarer means the surface reopened without the grammar that makes its '
            . 'advisory satisfiable.'
        );

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
        // NINE SINCE #1101: grid's measure is its `heading` role's `sizing.max-width` now,
        // emitted at a band-scoped selector like every other member, so the #578 severance
        // covers it structurally rather than by the slot-ownership rule it used to rely on.
        // EIGHT, and section is in this list while being EXCLUDED from the token-routing
        // sweep above — two sweeps asking two different questions of the same roster.
        // Measured: section's `heading` caps at the LITERAL `40rem`, not at
        // `@measure-heading`. So it is severed (its measure is its own role's, reachable by
        // no other component) but not routed (a site-wide measure retune does not move it).
        // That literal is section's own recorded decision, pinned in its emit test; it is
        // named here so this roster's membership reads as deliberate rather than accidental.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'logos', 'section', 'stats', 'table', 'testimonials'],
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

    /**
     * NO HEADING CAP IS DECLARED IN THE STYLESHEET AT ALL (#1101) — the strongest form of
     * the claim `testSlottedTitleCapsStillRouteTheirSlot` was making.
     *
     * That test scanned `.grid__heading`, `.cta__title` and `.faq__heading` for a
     * `max-width` and required each one it found to route its OWN `--<name>-heading-measure`
     * slot. The value of that was the routing: a literal cap there is a band opted out of a
     * site-wide measure retune, silently, and a cap routing a FOREIGN slot is the #578 leak
     * itself. cta left at #1026 and faq at #1046, so grid was the last subject, and #1101
     * left the scan with nothing to find — at which point its own fail-closed floor fired,
     * which is the guard working and the reason this is a rewrite rather than a deletion.
     *
     * A v2 component declares no max-width on a text element in CSS at all: the cap is the
     * role's `sizing.max-width`, emitted at a band-scoped selector by the engine. So the
     * repriced claim is the ABSENCE, and it is strictly stronger — the old test permitted a
     * cap as long as it routed, this permits none. The routing half did not go unguarded:
     * it is testOneDesignTokenWriteStillReachesEveryBandHeading, asserted on the role
     * defaults over nine components.
     *
     * A SCAN THAT PROVES ONLY AN ABSENCE HAS TO PROVE ITSELF, so the same matcher is run
     * against the shipped-and-removed spelling. Without that, deleting the regex would pass.
     */
    public function testNoHeadingCapIsDeclaredInTheStylesheet(): void
    {
        $headingSelector = '/\.(?:[a-z-]+__(?:heading|title)|[a-z-]+__item-title)(?![-\w])\s*$/';

        $caps = [];
        foreach ([$this->stripComments($this->css()) => true] as $css => $ignored) {
            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as [$whole, $selector, $body]) {
                foreach (explode(',', $selector) as $part) {
                    if (!preg_match($headingSelector, trim($part))) {
                        continue;
                    }
                    foreach ((array) (preg_match_all('/(?<![-a-z])max-width\s*:\s*([^;}]+)/i', $body, $m) ? $m[1] : []) as $v) {
                        $caps[] = trim($part) . ' { max-width: ' . trim($v) . ' }';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $caps,
            'a heading cap is declared in components.css. On v2 the cap is the role\'s '
            . '`sizing.max-width`, emitted band-scoped by the engine; a stylesheet literal '
            . 'opts that band out of a site-wide measure retune silently, and a stylesheet '
            . 'cap routing another component\'s name is the #578 leak itself.'
        );

        // DETECTION PROOF. The shipped-and-removed spelling must still be caught, or this
        // absence is a statement about a broken regex.
        preg_match_all(
            '/([^{}]+)\{([^{}]*)\}/s',
            '.grid__heading { max-width: var(--grid-heading-measure, 40rem); }',
            $proof,
            PREG_SET_ORDER
        );
        $this->assertCount(1, $proof, 'the rule splitter no longer parses a simple rule');
        $this->assertMatchesRegularExpression($headingSelector, trim($proof[0][1]),
            'the heading-selector matcher no longer recognises .grid__heading');
        $this->assertMatchesRegularExpression('/(?<![-a-z])max-width\s*:\s*([^;}]+)/i', $proof[0][2],
            'the max-width matcher no longer recognises a slotted cap');
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
     * SECTION 14.1's AUTHORING PATH, AT THE ADDRESS A MEASURE IS WRITTEN AT NOW (#1101).
     *
     * `testEveryNewMeasureSlotIsAuthorableThroughTheActionLayer` was a `@dataProvider`
     * sweep over the ROUTED and EXEMPT rosters: each measure slot had to be writable
     * through `pp_execute_action('style_component')` — never a raw `_pp_composition` meta
     * write, because raw seeding bypasses `pp_validate_composition` entirely and so proves
     * nothing about authorability — then read back from STORAGE and from the RENDERED
     * markup, so a value accepted at write and dropped at the render boundary failed too.
     *
     * Its provider derived from those rosters, and an empty data provider is a PHPUnit
     * ERROR rather than a silent skip, so the retirement could not have been quiet even if
     * someone had wanted it to be. `style_component` itself is not the route any more:
     * grid was the last component with a slot for it to patch, and it now answers
     * `no_style_slots` naming the `udc` map (asserted directly below).
     *
     * THE CLAIM IS UNCHANGED AND IS ASSERTED THE SAME WAY: a measure is authorable through
     * the real action layer, stored as authored, and REACHES THE PAGE. The three-layer
     * shape is kept exactly — validate-then-execute, read back from storage, then assert
     * the emitted declaration rather than the validator's verdict — because an engine that
     * accepted a value and dropped it at emission would leave a validation-only test green
     * while the capability was gone.
     *
     * Derived over every band that routes the token, so the sweep covers nine components
     * rather than the one the provider was down to.
     */
    public function testEveryBandsHeadingMeasureIsAuthorableThroughTheActionLayer(): void
    {
        $checked = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            if (($schema['roles']['heading']['defaults']['sizing']['max-width'] ?? null) !== '@measure-heading') {
                continue;
            }

            $result = pp_execute_action('create_page', [
                'title'       => "Authoring the {$component} heading measure",
                'composition' => [[
                    'component' => $component,
                    'props'     => $this->propsFor($component),
                    'udc'       => ['heading' => ['sizing' => ['max-width' => '30rem']]],
                ]],
            ]);
            $this->assertTrue($result['ok'], $result['error'] ?? "{$component}'s heading measure must be authorable");

            $stored = pp_get_composition((int) $result['target']['post_id']);
            $this->assertSame(
                '30rem',
                $stored[0]['udc']['heading']['sizing']['max-width'] ?? null,
                "{$component}'s authored measure must be stored as authored"
            );

            $this->assertStringContainsString(
                'max-width:30rem;',
                pp_udc_band_css($stored[0]),
                "{$component}'s authored measure must REACH THE PAGE, not merely pass validation"
            );
            $checked[] = $component;
        }

        sort($checked);
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'logos', 'stats', 'table', 'testimonials'],
            $checked,
            'every band that routes the shared measure token must also be able to override '
            . 'it per band — a routed default nobody can narrow is half a surface'
        );
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
     * A MEASURE SLOT NAME IS REFUSED RATHER THAN ACCEPTED-AND-DEAD, and the refusal names
     * the route (#1101).
     *
     * TWO TESTS RETIRE INTO THIS ONE, and both were hosted on grid because grid was the
     * last component with a slot to host them.
     *
     *   `testARoutedMeasureSlotStillRejectsNone` — A-30 widened `--stats-max-width` to
     *       `length-or-none` so a slot DEFAULTING to `none` could be written back to
     *       `none`, and this pinned that the widening was not global: a measure slot with
     *       a real length default kept the plain `length` grammar and kept refusing
     *       `none`, which is what stopped A-30 from re-opening the accepted-but-dead class
     *       it closed. THE CLAIM MOVED TO THE ENGINE at #1046 and is asserted in
     *       testTheUncappedDefaultIsAuthorable(): `length-or-none` is the declared PARAM
     *       type in the taxonomy, so it cannot be true for one component and false for
     *       another, and there is no per-slot grammar left to widen by accident.
     *
     *   `testAForeignComponentCannotAuthorTheCtaMeasureSlot` — the #578 leak proved from
     *       the authoring side: five components' headings were capped from a rule reading
     *       `--cta-heading-measure`, a slot they could neither SET (the write path refuses
     *       a foreign slot) nor RESOLVE (a slot custom property is emitted on its owner's
     *       root). Its host moved table -> logos -> grid as each became v2, and the method's
     *       own note predicted this: "grid is the last v1 component in the theme: when it
     *       rebuilds, this test has no host left and the claim retires with the mechanism."
     *       THE CLAIM IS STRUCTURAL ON v2 and is asserted in
     *       testNoComponentsHeadingMeasureCanBeReachedByAnother(): a measure is a role's
     *       own `sizing.max-width`, emitted at a selector scoped to that band's minted id,
     *       so no sibling band can read it even if it wanted to.
     *
     * WHAT IS PINNED HERE is the half neither replacement covers: what happens to an
     * author who WRITES the old name. Not silence, and not a dead end — a refusal that
     * names the v2 route. An author migrating an aged page hits this, and "no style slots"
     * on its own reads as "this component cannot be styled", which is the opposite of true
     * (#1007). Both retired names are used as the fixtures, so the answer is pinned for the
     * exact strings the old tests wrote.
     */
    public function testAMeasureSlotNameIsRefusedWithTheRouteThatReplacedIt(): void
    {
        $id = pp_create_page('Retired measure slot names', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => $this->propsFor('grid')]]);

        foreach ([
            '--grid-heading-measure' => 'its own retired slot',
            '--cta-heading-measure'  => "another component's retired slot (#578's leak)",
            '--grid-heading-measure|none' => 'the A-30 third state on its own retired slot',
        ] as $spec => $what) {
            [$slot, $value] = array_pad(explode('|', $spec, 2), 2, '30rem');

            $result = pp_execute_action('style_component', [
                'post_id'         => $id,
                'component_index' => 0,
                'style'           => [$slot => $value],
            ]);

            $this->assertFalse($result['ok'], "grid accepted {$what}: {$slot}");
            $this->assertSame(
                'no_style_slots',
                $result['error_code'] ?? null,
                "the refusal for {$what} must be the v2 one, not a slot-level rejection"
            );
            // Assert the REASON and the ROUTE, not just the failure: without this the test
            // passes on a broken fixture, a missing page, or any unrelated validation error.
            $this->assertStringContainsString('grid', $result['error'], 'the refusal names the component');
            $this->assertStringNotContainsString(
                '(none)',
                $result['error'],
                'the refusal reads as "this component cannot be styled", which is the '
                . 'opposite of the truth for a UDC component (#1007)'
            );
            $this->assertStringContainsString('`udc` map', $result['error'], 'the refusal must name the route');
        }
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
