<?php
/**
 * tests/UdcPresetSplitSeamTest.php
 *
 * #974 — the branches the preset registry's shape left unprovable.
 *
 * WHAT THE ISSUE IS ABOUT. `pp_udc_presets()` is a hardcoded static with three
 * theme-shipped entries and no injection point, and both halves of the T2
 * write/emit split resolve a preset BY NAME. So a test cannot construct a preset
 * that exercises the branches the shipped three never reach, and the file that
 * carries the gap says so in its own words: giving the write gate a narrower
 * second copy of the split leaves the suite green.
 *
 * THE SEAM NOW EXISTS (#1016), and section 3 is what it bought. Custom presets are
 * site-stored and merge in pp_udc_presets(), so a test can put a preset in the
 * registry by writing the store — no `apply_filters()` hook, no public extension
 * surface, and no shape invented for the convenience of a test. Sections 1 and 2
 * are kept exactly as they were: the direct calls still pin the array-taking
 * branches at their own level, and the source invariant is still the tripwire that
 * catches a SECOND copy of the split appearing. Section 3 is the behavioural proof
 * that copy would be wrong.
 *
 * Read together they cover the branch three ways: the predicate has one definition
 * (section 2), both halves call it (section 2), and a preset the two halves would
 * disagree about is refused by the gate (section 3). The first two go red when
 * someone writes a second copy; the third goes red when the copy is narrower,
 * which is the mutation #974 measured.
 *
 *   1. DIRECT CALLS. Two of the branches take an ARRAY, not a name —
 *      _pp_udc_preset_fragment() at group grain, and _pp_udc_validate_group_map()'s
 *      nested-preset refusal, which takes $allow_preset as a parameter.
 *
 *   2. A SOURCE INVARIANT: the intersect predicate has exactly ONE definition and
 *      all three halves call it — clause 4 of the A3 sub-ruling, "the write gate
 *      and the emitter intersect through one predicate, so what the envelope
 *      reports as skipped is what the page omits."
 *
 *   3. BEHAVIOUR THROUGH THE REGISTRY, reachable only since the store existed:
 *      a nested preset refused by NAME at both grains, a group-grain preset
 *      DEFINITION applied through a group-grain reference, and the gate's own half
 *      of the split made observable by a preset carrying a value that fails its
 *      parameter's grammar in a group only SOME roles permit.
 */

use PHPUnit\Framework\TestCase;

class UdcPresetSplitSeamTest extends TestCase
{
    // ── 1. The branches that take an array, not a name ───────────────────────

    /** A group-grain preset yields its `udc` fragment directly. */
    public function testAGroupGrainPresetFragmentIsReturnedAtItsOwnGrain(): void
    {
        $preset = ['grain' => 'shadow', 'udc' => ['shadow' => ['box' => '0 1px 2px #0003']]];

        $this->assertSame(
            ['shadow' => ['box' => '0 1px 2px #0003']],
            _pp_udc_preset_fragment($preset, 'group')
        );
    }

    /** And a grain mismatch yields nothing, rather than the wrong shape. */
    public function testAGrainMismatchYieldsNoFragment(): void
    {
        $preset = ['grain' => 'shadow', 'udc' => ['shadow' => ['box' => '0 1px 2px #0003']]];

        $this->assertNull(_pp_udc_preset_fragment($preset, 'role'));
    }

    /**
     * A preset referenced INSIDE a group map is refused: presets resolve one level
     * only, and the refusal reads the same at every grain.
     */
    public function testANestedPresetInsideAGroupMapIsRefused(): void
    {
        $error = _pp_udc_validate_group_map(
            'testimonials',
            'quote',
            'typography',
            [PP_UDC_PRESET_KEY => 'button', 'size' => '19px'],
            ['typography'],
            [],
            '',
            false
        );

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('one level only', $error->get_error_message());
    }

    // ── 2. The intersect predicate has ONE definition ────────────────────────

    /**
     * A3 sub-ruling clause 4, enforced rather than reviewed.
     *
     * "The write gate and the emitter intersect through one predicate, so what the
     * envelope reports as skipped is what the page omits." Nothing enforced that,
     * and the mutation the issue names — give the gate its own narrower copy of the
     * split — leaves every behavioural test green.
     *
     * COUNTS THE SHAPE, VIA THE TOKENIZER, NOT A REGEX OVER SOURCE TEXT. Two earlier
     * attempts at this pin were both evaded under mutation, and the way they failed
     * is the lesson: counting the function NAME proves nothing (PHP fatals on a
     * duplicate definition, so it can never go red), and a regex for
     * `return ['applied' => ...]` is dodged by three ordinary spellings — a local
     * (`$out = [...]; return $out;`), reversed keys, or `compact()`. What a second
     * copy cannot easily avoid is BUILDING an array keyed by both words, so that is
     * what is counted — array literals and `compact()` alike — on the token stream,
     * where comments and strings cannot be mistaken for code. All three observed
     * evasions are covered; a determined rewrite could still dodge any source pin,
     * which is why the call-site assertion below is the load-bearing half and this
     * one is the tripwire.
     */
    public function testOnlyOnePlaceBuildsTheAppliedSkippedIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        // Walk the tokens and count array literals whose keys include BOTH
        // 'applied' and 'skipped'. Bracket depth tracking keeps each literal
        // separate from its neighbours.
        $tokens  = token_get_all($source);
        $stack   = [];
        $builds  = 0;
        $compact = false;
        foreach ($tokens as $token) {
            // `compact('applied', 'skipped')` builds the same array without an
            // array literal, so it opens a frame too. Found by mutation review:
            // it was the one spelling the literal-only walk let through.
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'compact') {
                $compact = true;
                continue;
            }
            if ($token === '(') {
                if ($compact) {
                    $stack[] = ['applied' => false, 'skipped' => false, 'paren' => true];
                }
                $compact = false;
                continue;
            }
            if ($token === ')') {
                if ($stack !== [] && !empty($stack[count($stack) - 1]['paren'])) {
                    $frame = array_pop($stack);
                    if ($frame['applied'] && $frame['skipped']) {
                        $builds++;
                    }
                }
                continue;
            }
            if ($token === '[') {
                $stack[] = ['applied' => false, 'skipped' => false, 'paren' => false];
                continue;
            }
            if ($token === ']') {
                $frame = array_pop($stack);
                if ($frame !== null && $frame['applied'] && $frame['skipped']) {
                    $builds++;
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $stack !== []) {
                $literal = trim($token[1], "'\"");
                if ($literal === 'applied' || $literal === 'skipped') {
                    $stack[count($stack) - 1][$literal] = true;
                }
            }
        }

        $this->assertSame(
            1,
            $builds,
            'a second place building the applied/skipped intersect is how the gate and the emitter start disagreeing'
        );
    }

    /** And both halves of the split actually call it. */
    public function testBothTheWriteGateAndTheEmitterCallTheSharedIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        foreach (
            [
                '_pp_udc_validate_preset_reference' => 'the write gate',
                '_pp_udc_preset_sources'            => 'the emitter',
            ] as $function => $label
        ) {
            $start = strpos($source, 'function ' . $function . '(');
            $this->assertNotFalse($start, $function . ' must exist');

            // Bound the search at the next top-level function so a call in a LATER
            // function cannot satisfy this assertion on behalf of this one.
            $next = strpos($source, "\nfunction ", $start + 1);
            $body = substr($source, $start, $next === false ? null : $next - $start);

            $this->assertStringContainsString(
                '_pp_udc_split_preset_by_permitted(',
                $body,
                $label . ' must intersect through the shared predicate, not its own copy'
            );
        }
    }

    /**
     * The disclosure reads the same predicate too, so what the envelope names as
     * skipped is what the other two computed.
     */
    public function testTheSkippedGroupsDisclosureUsesTheSameIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        $start = strpos($source, 'function pp_udc_composition_findings(');
        $this->assertNotFalse($start);
        $next = strpos($source, "\nfunction ", $start + 1);
        $body = substr($source, $start, $next === false ? null : $next - $start);

        $this->assertStringContainsString('_pp_udc_split_preset_by_permitted(', $body);
    }

    // ── 3. The branches the registry seam made reachable (#1016) ─────────────

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
    }

    /**
     * Puts a preset in the registry by writing the STORE, not by patching a global.
     *
     * Deliberately a raw row write rather than the save verb. These tests are about
     * what the READER and the write gate do with stored data, and stored data is
     * not only what the save verb produced — `wp option update` reaches this row,
     * and so does a restore of a row written before a rule existed. Seeding through
     * the verb would test the verb's output against itself and would quietly lose
     * the one case that matters here: a preset carrying a value the current grammar
     * refuses.
     */
    private function seedPresets(array $presets): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY         => 1,
            PP_SITE_PRESETS_VERSION_KEY     => 1,
            PP_SITE_PRESETS_KEY             => $presets,
        ]);
    }

    private function band(array $udc): array
    {
        return [
            'component' => 'testimonials',
            'id'        => 'pp-a1b2c3d4',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => $udc,
        ];
    }

    /**
     * The CSS properties one compile actually emitted, as `property => css`.
     *
     * Flattened across blocks on purpose: these tests ask "did this declaration
     * reach the page at all", and which selector block carried it is a different
     * question with its own tests.
     *
     * @return array<string, string>
     */
    private function emitted(array $udc): array
    {
        $compiled = pp_udc_compile_band($this->band($udc), 'authored');
        $out      = [];
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $property => $entry) {
                $out[(string) $property] = (string) $entry['css'];
            }
        }
        return $out;
    }

    /** A stored preset joins the registry the shipped three live in. */
    public function testASiteStoredPresetIsResolvableByNameLikeAShippedOne(): void
    {
        $this->assertNull(pp_udc_resolve_preset('brand-quote'), 'no store, no preset');

        $this->seedPresets([
            'brand-quote' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
        ]);

        $resolved = pp_udc_resolve_preset('brand-quote');
        $this->assertIsArray($resolved);
        $this->assertSame('role', $resolved['grain']);
        $this->assertArrayHasKey('brand-quote', pp_udc_presets());
        // And the theme's three are still there beside it.
        $this->assertArrayHasKey('button', pp_udc_presets());
    }

    /**
     * #974 branch 1 — the nested refusal, now reachable BY NAME at role grain.
     *
     * Section 1 pins the group-grain half by calling the validator directly. This
     * is the half that needed a registry: the role-grain check reads
     * `$fragment[PP_UDC_PRESET_KEY]` off a resolved preset, and no shipped preset
     * carries one.
     *
     * AN EARLIER VERSION OF THIS TEST WAS VACUOUS, and the way it failed is worth
     * keeping. It put `_preset` at the TOP of the band map, where `_preset` is not
     * a role name — so the refusal it caught was `unknown_udc_role`, which a
     * perfectly well-formed preset produces just as readily. It passed for a reason
     * that had nothing to do with nesting, and the mutation that disables the
     * nested guard left it green. Asserting the CODE is what makes that visible:
     * the nested refusal is `invalid_prop_value`, not `unknown_udc_role`.
     */
    public function testAStoredPresetThatItselfNamesAPresetIsRefusedAtRoleGrain(): void
    {
        $this->seedPresets([
            'chained' => ['grain' => 'role', 'udc' => [
                PP_UDC_PRESET_KEY => 'button',
                'typography'      => ['size' => '19px'],
            ]],
            'clean'   => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
        ]);

        $error = pp_udc_validate_map(['quote' => [PP_UDC_PRESET_KEY => 'chained']], 'testimonials');

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('invalid_prop_value', $error->get_error_code());
        $this->assertStringContainsString('one level only', $error->get_error_message());

        // The control: the same shape with a preset that nests nothing is accepted,
        // so the refusal above is about the nesting and not about the reference.
        $this->assertNull(pp_udc_validate_map(['quote' => [PP_UDC_PRESET_KEY => 'clean']], 'testimonials'));
    }

    /** The same rule, reached through a role map, with the message it produces. */
    public function testTheNestedRefusalNamesThePresetAndTheRule(): void
    {
        $this->seedPresets([
            'chained' => ['grain' => 'role', 'udc' => [
                PP_UDC_PRESET_KEY => 'button',
                'typography'      => ['size' => '19px'],
            ]],
        ]);

        $error = pp_udc_validate_map(['quote' => [PP_UDC_PRESET_KEY => 'chained']], 'testimonials');

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('chained', $error->get_error_message());
        $this->assertStringContainsString('one level only', $error->get_error_message());
    }

    /**
     * #974 branch 2 — a GROUP-GRAIN preset DEFINITION, applied through a reference.
     *
     * All three shipped presets declare `grain: role`, so until a store existed the
     * `return $udc;` at the bottom of _pp_udc_preset_fragment() could only be
     * reached by calling it directly (section 1). This reaches it the way an author
     * does.
     */
    public function testAGroupGrainPresetDefinitionAppliesThroughAGroupGrainReference(): void
    {
        $this->seedPresets([
            'brand-type' => ['grain' => 'typography', 'udc' => [
                'size'   => '19px',
                'weight' => '600',
            ]],
        ]);

        $this->assertNull(
            pp_udc_validate_map(
                ['list' => ['typography' => [PP_UDC_PRESET_KEY => 'brand-type']]],
                'testimonials'
            ),
            'a group-grain preset naming a group the role permits must be accepted'
        );

        // `list` RATHER THAN `quote`, and the choice is the trap this comment
        // exists to mark. Presets rank UNDER role defaults, per (group, param,
        // state): `quote` defaults its own typography.size, so a preset setting
        // size there is correctly outranked and emits nothing — ruled cascade
        // behaviour that reads exactly like a broken preset. `list` declares only
        // spacing, so typography is where the preset is the only voice.
        $emitted = $this->emitted(['list' => ['typography' => [PP_UDC_PRESET_KEY => 'brand-type']]]);

        $this->assertSame('19px', $emitted['font-size'] ?? null);
        $this->assertSame('600', $emitted['font-weight'] ?? null);
    }

    /** A group-grain preset named at a group it does not describe declares nothing. */
    public function testAGroupGrainPresetIsRefusedAtAGroupItDoesNotDescribe(): void
    {
        $this->seedPresets([
            'brand-type' => ['grain' => 'typography', 'udc' => ['size' => '19px']],
        ]);

        $error = pp_udc_validate_map(
            ['quote' => [PP_UDC_PRESET_KEY => 'brand-type']],
            'testimonials'
        );

        $this->assertInstanceOf(WP_Error::class, $error, 'a group-grain preset is not a role bundle');
        $this->assertStringContainsString('brand-type', $error->get_error_message());
    }

    /**
     * #974 branch 3 — THE ONE THAT MATTERED. The write gate's own half of the split.
     *
     * The gate validates the groups the split says APPLY and leaves the skipped
     * ones alone. That distinction has no observable consequence unless a preset
     * carries a value that would FAIL validation — and no such preset could exist
     * while the registry was three well-formed constants. #974 measured the
     * consequence: give _pp_udc_validate_preset_reference() its own narrower copy
     * of the split, so the gate validates five groups while the emitter emits six,
     * and all 5012 tests stay green.
     *
     * `shadow` is the group that makes it visible, because testimonials permits it
     * on three of twelve roles. The SAME preset therefore has to behave two ways:
     *
     *     role `quote` (no shadow)  -> shadow is SKIPPED  -> accepted
     *     role `card`  (has shadow) -> shadow APPLIES     -> refused, it is invalid
     *
     * A gate whose split were narrower than the emitter's would accept the `card`
     * write and let the emitter paint an unvalidated declaration. That is the
     * divergence clause 4 forbids, and this is the test that sees it.
     */
    public function testTheWriteGateValidatesExactlyTheGroupsItsSplitApplies(): void
    {
        $this->seedPresets([
            'bad-shadow' => ['grain' => 'role', 'udc' => [
                'typography' => ['size' => '19px'],
                // Refused by the `shadow` grammar: unitless lengths are not a shadow.
                'shadow'     => ['box' => '10 20 30 #000000'],
            ]],
        ]);

        $this->assertNull(
            pp_udc_validate_map(['quote' => [PP_UDC_PRESET_KEY => 'bad-shadow']], 'testimonials'),
            'quote does not permit shadow, so the invalid group is skipped, not validated'
        );

        $error = pp_udc_validate_map(['card' => [PP_UDC_PRESET_KEY => 'bad-shadow']], 'testimonials');
        $this->assertInstanceOf(
            WP_Error::class,
            $error,
            'card permits shadow, so the gate must validate what its split applies'
        );
        $this->assertStringContainsString('bad-shadow', $error->get_error_message());
    }

    /**
     * And the emitter agrees with the envelope about the same preset.
     *
     * The other direction of clause 4: what the disclosure names as skipped is what
     * the page omits. Both read the shared predicate, so this cannot drift — but
     * "cannot drift" is the claim under test, not a reason to skip testing it.
     */
    public function testWhatTheDisclosureNamesAsSkippedIsWhatTheBandOmits(): void
    {
        $this->seedPresets([
            'wide' => ['grain' => 'role', 'udc' => [
                'typography' => ['size' => '19px'],
                'shadow'     => ['box' => '0 1px 2px #00000033'],
            ]],
        ]);

        $band     = $this->band(['list' => [PP_UDC_PRESET_KEY => 'wide']]);
        $findings = pp_udc_composition_findings([$band]);

        $skipped = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_preset_groups_skipped'
        ));
        $this->assertCount(1, $skipped, 'a partial apply must never be silent');
        $this->assertStringContainsString('shadow', $skipped[0]['message']);

        $emitted = $this->emitted(['list' => [PP_UDC_PRESET_KEY => 'wide']]);
        $this->assertSame('19px', $emitted['font-size'] ?? null, 'the applied group paints');
        $this->assertArrayNotHasKey('box-shadow', $emitted, 'the skipped group does not');
    }

    /**
     * An empty intersection still refuses when the preset is a custom one.
     *
     * `avatar` permits no typography, so a typography-only preset contributes
     * nothing there. The ruled posture is a refusal naming both sides, not a
     * silently accepted no-op.
     */
    public function testAnEmptyIntersectionRefusesForACustomPresetToo(): void
    {
        $this->seedPresets([
            'type-only' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
        ]);

        $error = pp_udc_validate_map(['avatar' => [PP_UDC_PRESET_KEY => 'type-only']], 'testimonials');

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('type-only', $error->get_error_message());
        $this->assertStringContainsString('avatar', $error->get_error_message());
    }

    /**
     * A stored preset that is not preset-SHAPED contributes nothing, and does not
     * fatal.
     *
     * The row is reachable by `wp option update`, so "the save verb validated it"
     * is never a premise the reader may hold. Each of these is a different way the
     * shape can be wrong, and every one of them must read as "no such preset"
     * rather than as a half-built fragment handed to the compiler.
     */
    public function testAMalformedStoredPresetIsIgnoredRatherThanHalfApplied(): void
    {
        $this->seedPresets([
            'no-grain'  => ['udc' => ['typography' => ['size' => '19px']]],
            'no-udc'    => ['grain' => 'role'],
            'udc-scalar'=> ['grain' => 'role', 'udc' => 'typography'],
            'not-a-map' => 'button',
            'bad name!' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
        ]);

        foreach (['no-grain', 'no-udc', 'udc-scalar', 'not-a-map', 'bad name!'] as $name) {
            $this->assertNull(
                pp_udc_resolve_preset($name),
                $name . ' is not preset-shaped and must resolve to nothing'
            );
        }
        $this->assertSame(array_keys(pp_udc_system_presets()), array_keys(pp_udc_presets()));
    }

    /**
     * An all-digit preset name survives the JSON round trip.
     *
     * `json_decode(..., true)` turns a numeric object key into an INTEGER array
     * key, so a preset stored as "7" comes back keyed by int 7 while every lookup
     * passes a string. PHP's own coercion makes that work, which is exactly why it
     * needs a pin rather than a comment: the day someone adds a strict `is_string`
     * check to the reader, this is the case that breaks, silently, for whoever
     * named a preset after a size.
     */
    public function testAnAllDigitPresetNameStillResolves(): void
    {
        $this->seedPresets([
            '7' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
        ]);

        $this->assertIsArray(pp_udc_resolve_preset('7'));
        $this->assertNull(
            pp_udc_validate_map(['quote' => [PP_UDC_PRESET_KEY => '7']], 'testimonials')
        );
    }

    /**
     * A custom preset never outranks a theme one, and the shadow is reported.
     *
     * The save verb refuses a system name, so the only way into this state is a
     * theme release shipping a name a site already used. Resolving it silently
     * would change what every band using that name paints, on upgrade, with
     * nothing anywhere saying so.
     */
    public function testATeThemePresetOutranksAStoredOneOfTheSameNameAndTheShadowIsReported(): void
    {
        $this->assertSame([], pp_udc_shadowed_presets());

        $this->seedPresets([
            'button' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '99px']]],
        ]);

        $resolved = pp_udc_resolve_preset('button');
        $this->assertIsArray($resolved);
        $this->assertNotSame(
            '99px',
            $resolved['udc']['typography']['size'] ?? null,
            'the theme preset must win'
        );
        $this->assertSame(['button'], pp_udc_shadowed_presets());
    }
}
