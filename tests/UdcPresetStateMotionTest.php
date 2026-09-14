<?php
/**
 * tests/UdcPresetStateMotionTest.php
 *
 * RULING A3, as executable evidence: presets, state dimensions, the motion group.
 *
 * Three capabilities land together because they land on one seam. A preset is a
 * named `udc` fragment that resolves through the SAME compile path an inline map
 * does; a state is a value dimension inside a group, exactly where `:hover`
 * already lived; and motion is one more group whose two params go through the one
 * shared grammar. The tests are grouped the same way, and the two REGRESSION pins
 * come first because they are the two ways this change could quietly break data
 * that already exists.
 */

use PHPUnit\Framework\TestCase;

final class UdcPresetStateMotionTest extends TestCase
{
    private function band(array $udc = [], string $id = 'pp-a1b2c3d4'): array
    {
        return [
            'component' => 'testimonials',
            'id'        => $id,
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => $udc,
        ];
    }

    private function assertAccepted(array $udc, string $why = ''): void
    {
        $error = pp_udc_validate_map($udc, 'testimonials');
        $this->assertNull(
            $error,
            $why . ($error instanceof WP_Error ? ' — refused with: ' . $error->get_error_message() : '')
        );
    }

    private function assertRefused(array $udc, string $needle, string $why = ''): WP_Error
    {
        $error = pp_udc_validate_map($udc, 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error, $why ?: 'expected a refusal');
        $this->assertStringContainsString($needle, $error->get_error_message(), $why);
        return $error;
    }

    // ── REGRESSION 1 (CRITICAL) ──────────────────────────────────────────────
    //
    // Mint names now carry a STATE segment, and `focus-visible` is TWO hyphen
    // segments where `hover` was one. Two functions decide together whether a
    // stored token name is the engine's own: _pp_udc_is_mint_shaped_name() and
    // _pp_udc_name_is_the_engines_own_mint(). If either pops one segment where it
    // should pop two, the engine stops recognising names IT MINTED — and because
    // pp_validate_composition* runs over STORED compositions (the post-write
    // envelope, restore_composition, `wp pp check page`), every band already
    // holding such a token would report a permanent, unfixable refusal.
    //
    // The trap this pins against is a FALSE GREEN: if is_mint_shaped() simply
    // returned false for the new names, the squat check would never fire and a
    // naive "does the write succeed" test would pass while the guard was dead.
    // So both directions are asserted, and the shape check is asserted directly.

    public function testAMintedTwoSegmentStateNameIsRecognisedAsMintShaped(): void
    {
        $this->assertTrue(
            _pp_udc_is_mint_shaped_name('quote-typography-size-focus-visible-d'),
            'the two-segment state name the engine mints must read as mint-shaped'
        );
        $this->assertTrue(_pp_udc_is_mint_shaped_name('quote-typography-size-hover-d'));
        $this->assertTrue(_pp_udc_is_mint_shaped_name('quote-typography-size-active-d'));
        $this->assertTrue(_pp_udc_is_mint_shaped_name('card-motion-timing-function-hover-p'));
        // The base state still works, and an ordinary author name still does not.
        $this->assertTrue(_pp_udc_is_mint_shaped_name('quote-typography-size-d'));
        $this->assertFalse(_pp_udc_is_mint_shaped_name('my-own-token'));
    }

    public function testTheEnginesOwnFocusVisibleMintSurvivesRevalidationOfStoredData(): void
    {
        $item = pp_udc_normalize_band($this->band([
            'quote' => ['typography' => [
                ':focus-visible' => ['size' => ['d' => '20px', 'p' => '18px']],
            ]],
        ]));

        $this->assertSame(
            ['quote-typography-size-focus-visible-d', 'quote-typography-size-focus-visible-p'],
            array_keys($item['udc']['_tokens']),
            'the state contributes its mint segment, hyphens and all'
        );

        // THE PIN. Re-validating what STORAGE now holds must be clean; a guard
        // that misreads the name refuses here and every already-written band with
        // a focus-visible value becomes permanently invalid.
        $this->assertAccepted($item['udc'], 'the engine must accept the names it minted itself');
    }

    public function testAnAuthorStillCannotSquatAStateMintName(): void
    {
        // The other direction of the same guard: recognising the name must not
        // degrade into accepting anything that looks like one. Here the token
        // exists but NOTHING references it through the parameter its name
        // encodes, which is what separates the engine's own output from a squat.
        $this->assertRefused(
            [
                '_tokens' => ['quote-typography-size-focus-visible-d' => '20px'],
                'quote'   => ['typography' => ['size' => '1rem']],
            ],
            'uses a name the engine mints for itself',
            'an unreferenced mint-shaped name is still a squat'
        );
    }

    public function testTheMintNameCollisionMessageNamesEveryStateThatExists(): void
    {
        $error = $this->assertRefused(
            ['_tokens' => ['quote-typography-size-hover-d' => '20px'], 'quote' => ['typography' => ['size' => '1rem']]],
            'the engine mints for itself'
        );
        foreach (['hover', 'focus-visible', 'active'] as $mint) {
            $this->assertStringContainsString($mint, $error->get_error_message());
        }
    }

    // ── REGRESSION 2 ─────────────────────────────────────────────────────────

    public function testABandThatReferencesNoPresetCompilesExactlyAsBefore(): void
    {
        // Role defaults participate in the AUTHORED layer's resolution table
        // purely to RANK the preset tier under them, so they join it only when a
        // preset is actually in play. For a band with no preset the compile must
        // be BYTE-identical to what it was before this tier existed.
        //
        // The literal below was captured from an unmodified tree at ed35b1e, not
        // written by hand, and that matters: an earlier draft ranked defaults in
        // unconditionally, which left the output correct but reordered the
        // declarations inside the rule (defaults seeded the key order, the band's
        // values overwrote in place). Same pixels, different bytes — and the only
        // thing that caught it was comparing against the real prior output.
        $item = $this->band([
            'quote' => ['typography' => ['size' => '19px', 'style' => 'italic']],
            '_band' => ['spacing' => ['padding-top' => '18px']],
        ]);

        $css = pp_udc_band_css($item);
        $this->assertSame(
            '[data-pp-band="pp-a1b2c3d4"]{padding-top:18px;}'
            . '[data-pp-band="pp-a1b2c3d4"] .testimonials__quote{font-size:19px;font-style:italic;}',
            $css,
            'the authored layer emits the band\'s own declarations, in the author\'s own order, and nothing else'
        );
    }

    public function testTheAuthoredLayerNeverEmitsADeclarationAComponentDefaultWon(): void
    {
        // The invariant the "rank then drop" mechanism rests on, asserted
        // directly rather than inferred from the emitted string: a declaration
        // whose winner is a role default belongs to the DEFAULTS layer, which
        // emits it once per component at its designed weight.
        $compiled = pp_udc_compile_band(
            $this->band(['card' => ['_preset' => 'button']]),
            'authored'
        );
        $this->assertNotSame([], $compiled['blocks'], 'the preset must contribute something');
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $property => $entry) {
                $this->assertNotSame(
                    'defaults',
                    $entry['source'],
                    "the authored layer emitted {$property} from the defaults tier"
                );
            }
        }

        // And the defaults layer is where they do emit.
        $defaults = pp_udc_compile_band(['component' => 'testimonials'], 'defaults');
        $sources  = [];
        foreach ($defaults['blocks'] as $block) {
            foreach ($block['decls'] as $entry) {
                $sources[$entry['source']] = true;
            }
        }
        $this->assertSame(['defaults' => true], $sources);
    }

    // ── The cascade rung, both directions ────────────────────────────────────

    public function testBandUdcBeatsAPreset(): void
    {
        $css = pp_udc_band_css($this->band([
            'card' => ['_preset' => 'button', 'border' => ['radius' => '12px']],
        ]));
        $this->assertStringContainsString('border-radius:12px;', $css);
        $this->assertStringNotContainsString('--btn-radius', $css, 'the preset\'s radius must have lost');
    }

    public function testARoleDefaultBeatsAPreset(): void
    {
        // The ruled rung: presets sit UNDER component role defaults. `card`
        // defaults its own background, so the button preset's accent fill loses.
        $css = pp_udc_band_css($this->band(['card' => ['_preset' => 'button']]));
        $this->assertStringNotContainsString(
            'background:var(--color-accent)',
            $css,
            'the card role\'s own background default outranks the preset'
        );
        // …while a property the role leaves alone does come through.
        $this->assertStringContainsString('min-height:44px;', $css);

        // AND POSITIVELY: the role default is what WON the contested property,
        // not merely that the preset's value is missing. An absence assertion
        // alone would also pass if the property vanished entirely.
        $compiled = pp_udc_compile_band($this->band(['card' => ['_preset' => 'button']]), 'all');
        foreach ($compiled['blocks'] as $block) {
            if ($block['role'] === 'card' && $block['state'] === '' && isset($block['decls']['background'])) {
                $this->assertSame('defaults', $block['decls']['background']['source']);
                $this->assertSame('var(--color-surface)', $block['decls']['background']['css']);
                return;
            }
        }
        $this->fail('the card role never resolved a background');
    }

    public function testAPresetResolvesThroughSiteTokensWhichPutsThemUnderIt(): void
    {
        // "site tokens → presets" is literal: a preset's own values are `@refs`
        // resolved through the site registry, so a retheme moves the preset.
        $css = pp_udc_band_css($this->band(['quote' => ['typography' => ['_preset' => 'link']]]));
        $this->assertStringContainsString(':hover{color:var(--color-accent-hover);}', $css);
    }

    public function testAPresetSourcedDeclarationSurvivesIntoTheCompiledTable(): void
    {
        // NAMED FOR WHAT IT ACTUALLY PROVES. It used to be called
        // testABandsOwnPresetOutranksOneNamedByARoleDefault, which it never
        // tested: no schema can put `_preset` in a role's `defaults` (the schema
        // suite walks defaults as group names and `_preset` is not a group), so
        // the two-preset ranking has no reachable fixture and the assertion below
        // only ever saw one preset row. The ranking code stays — it is ruled, and
        // Sprint 2 needs it — but the claim does not belong on a test that cannot
        // check it. See the filed follow-up.
        $compiled = pp_udc_compile_band($this->band([
            'quote' => ['typography' => ['_preset' => 'button']],
        ]), 'all');
        $found = false;
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $entry) {
                if (strpos($entry['source'], 'preset:') === 0) {
                    $found = true;
                }
            }
        }
        $this->assertTrue($found, 'a preset-sourced declaration must survive into the compiled table');
    }

    // ── Provenance (I35 / I36) ───────────────────────────────────────────────

    public function testProvenanceNamesTheSpecificPresetAValueCameFrom(): void
    {
        // A bare 'preset' source would satisfy the type and defeat the purpose:
        // I35/I36 need a reader to see WHICH preset supplied a value.
        $compiled = pp_udc_compile_band($this->band(['card' => ['_preset' => 'button']]), 'authored');
        $sources  = [];
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $entry) {
                $sources[$entry['source']] = true;
            }
        }
        $this->assertArrayHasKey('preset:button', $sources);
        $this->assertArrayNotHasKey('preset', $sources, 'the name is the point');
    }

    public function testProvenanceShowsABandValueOverridingAPresetValue(): void
    {
        $compiled = pp_udc_compile_band($this->band([
            'card' => ['_preset' => 'button', 'sizing' => ['min-height' => '60px']],
        ]), 'authored');

        foreach ($compiled['blocks'] as $block) {
            if (isset($block['decls']['min-height'])) {
                $this->assertSame('udc', $block['decls']['min-height']['source']);
                $this->assertSame('60px', $block['decls']['min-height']['literal']);
                return;
            }
        }
        $this->fail('min-height was never emitted');
    }

    // ── Preset grains ────────────────────────────────────────────────────────

    public function testARoleGrainPresetAppliesEveryGroupItDeclares(): void
    {
        $css = pp_udc_band_css($this->band(['card' => ['_preset' => 'button']]));
        $this->assertStringContainsString('font-weight:600;', $css);
        $this->assertStringContainsString('padding-left:var(--space-lg);', $css);
        $this->assertStringContainsString('transition-duration:150ms;', $css);
    }

    public function testAGroupGrainPresetAppliesOnlyThatGroup(): void
    {
        $css = pp_udc_band_css($this->band([
            'quote' => ['typography' => ['_preset' => 'button']],
        ]));
        $this->assertStringContainsString('font-weight:600;', $css, 'typography comes through');
        $this->assertStringNotContainsString('padding-left', $css, 'and nothing else does');
    }

    public function testAGroupGrainPresetComposesWithARoleGrainOneOnTheSameRole(): void
    {
        // Ordered inside the preset tier: role grain first, group grain over it.
        $css = pp_udc_band_css($this->band([
            'card' => ['_preset' => 'button', 'typography' => ['_preset' => 'link']],
        ]));
        $this->assertStringContainsString('text-decoration-line:underline;', $css, 'link typography wins its group');
        $this->assertStringContainsString('min-height:44px;', $css, 'the role-grain preset keeps its other groups');
    }

    public function testEveryValueInEverySystemPresetSatisfiesTheOneGrammar(): void
    {
        // The explicit guarantee that a preset cannot smuggle a value an author
        // could not write — including through a token reference that no longer
        // resolves, which would otherwise be invisible until something painted.
        $groups = pp_udc_groups();
        $states = pp_udc_states();
        $seen   = 0;

        foreach (pp_udc_presets() as $name => $preset) {
            $this->assertContains(
                $preset['grain'],
                array_merge(['role'], array_keys($groups)),
                "preset {$name} declares a legal grain"
            );
            $this->assertTrue(pp_udc_valid_preset_name($name), "preset name {$name} is well-formed");

            foreach ($preset['udc'] as $group => $group_map) {
                $this->assertArrayHasKey($group, $groups, "preset {$name} names a real group");
                $params = $groups[$group]['params'];

                $check = function (array $map, string $where) use (&$seen, $params, $name, $group) {
                    foreach ($map as $param => $value) {
                        $this->assertArrayHasKey($param, $params, "preset {$name} {$where} names a real param");
                        $literal = $value;
                        $ref     = pp_udc_parse_reference((string) $value);
                        if ($ref !== null) {
                            $resolved = pp_udc_resolve_reference($ref, []);
                            $this->assertNotNull($resolved, "preset {$name} references @{$ref}, which must resolve");
                            $literal = $resolved['value'];
                        }
                        $this->assertTrue(
                            pp_udc_validate_value((string) $literal, $params[$param]) === true,
                            "preset {$name} {$group}.{$param} = {$literal} must satisfy its parameter's grammar"
                        );
                        $seen++;
                    }
                };

                foreach ($group_map as $key => $value) {
                    if (isset($states[$key])) {
                        $check($value, 'state ' . $key);
                        continue;
                    }
                    $check([$key => $value], 'base');
                }
            }
        }
        $this->assertGreaterThan(30, $seen, 'the three presets carry real content');
    }

    public function testThePresetLiteralsStillMatchTheThemeTheyWereDerivedFrom(): void
    {
        // THE SAME ANTI-DRIFT ARGUMENT THE MOTION DEFAULTS GET, applied to the
        // rest of the copy. `@`-referenced values follow a retheme for free; the
        // bare literals do not, and "derived from .btn" quietly stops being true
        // the first time someone retunes the button. Pin the ones that carry
        // meaning, against the rule they were read off.
        $components = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertSame(1, preg_match('/\n\.btn \{(.*?)\n\}/s', $components, $m), '.btn must still exist');
        $btn = $m[1];

        foreach ([
            'font-weight: 600;'   => ['button', 'typography', 'weight',      '600'],
            'font-size: 1rem;'    => ['button', 'typography', 'size',        '1rem'],
            'line-height: 1.4;'   => ['button', 'typography', 'line-height', '1.4'],
            'min-height: 44px;'   => ['button', 'sizing',     'min-height',  '44px'],
        ] as $declaration => [$preset, $group, $param, $expected]) {
            $this->assertStringContainsString(
                $declaration,
                $btn,
                "the .btn rule this preset copied `{$declaration}` from has changed"
            );
            $this->assertSame(
                $expected,
                pp_udc_presets()[$preset]['udc'][$group][$param],
                "preset {$preset}.{$group}.{$param} drifted from .btn"
            );
        }

        // The border width is the one that reads as arbitrary out of context.
        $this->assertStringContainsString('border: 2px solid', $btn);
        $this->assertSame('2px', pp_udc_presets()['button']['udc']['border']['width']);
    }

    public function testEveryMintableNameFitsTheCustomPropertyCharsetBudget(): void
    {
        // Minting runs AFTER validation, so an over-budget name is never seen by
        // the `_tokens` charset gate: it is stored, then silently dropped at both
        // emit gates, and nothing reports it — a write that says success and
        // paints nothing. There is real headroom today; this fails the day a
        // longer role, param or state name closes it.
        $longest = '';
        foreach (pp_udc_component_roles('testimonials') as $role => $definition) {
            foreach ($definition['groups'] as $group) {
                foreach (array_keys(pp_udc_groups()[$group]['params']) as $param) {
                    foreach (array_merge([''], array_keys(pp_udc_states())) as $state) {
                        foreach (array_keys(pp_udc_breakpoints()) as $bp) {
                            $name = pp_udc_mint_name($role, $group, $param, $state, $bp);
                            if (strlen($name) > strlen($longest)) {
                                $longest = $name;
                            }
                        }
                    }
                }
            }
        }
        $this->assertNotSame('', $longest);
        $this->assertLessThanOrEqual(
            64,
            strlen($longest),
            "the longest mintable name is \"{$longest}\" (" . strlen($longest) . " chars); "
            . 'the --pp-<name> charset gate caps it at 64'
        );
    }

    public function testAPresetKeyInTheWrongPlaceIsRefused(): void
    {
        // `_preset` is legal at exactly two grains. Written anywhere else it used
        // to fall through to the generic param or role check and answer with the
        // wrong list entirely — the same misdirection the unknown-state branch
        // exists to avoid.
        $this->assertRefused(
            ['card' => ['background' => [':hover' => ['_preset' => 'button']]]],
            'has no parameter',
            'inside a state map'
        );
        $this->assertRefused(
            ['_preset' => 'button'],
            'has no UDC role',
            'at band grain, beside the roles'
        );
    }

    public function testTheThreeRuledSystemPresetsExist(): void
    {
        $this->assertSame(
            ['button', 'button-secondary', 'link'],
            array_keys(pp_udc_presets()),
            'ruling A3 names exactly these three for Sprint 1'
        );
        $this->assertNull(pp_udc_resolve_preset('nope'), 'a failed lookup is null, never an empty fragment');
    }

    // ── Preset refusals ──────────────────────────────────────────────────────

    public function testADanglingPresetReferenceIsRefusedAndNamesTheReference(): void
    {
        $error = $this->assertRefused(['card' => ['_preset' => 'buton']], 'buton');
        $this->assertStringContainsString('does not exist', $error->get_error_message());
        $this->assertStringContainsString('button', $error->get_error_message(), 'and lists what does exist');
    }

    public function testADanglingGroupGrainPresetReferenceIsAlsoRefused(): void
    {
        $this->assertRefused(['quote' => ['typography' => ['_preset' => 'nope']]], 'nope');
    }

    public function testAPresetNameMustBeAStringOfTheDeclaredCharset(): void
    {
        $this->assertRefused(['card' => ['_preset' => ['button']]], 'must be a preset name');
        $this->assertRefused(['card' => ['_preset' => 'a b']], 'must be a preset name');
        $this->assertRefused(['card' => ['_preset' => str_repeat('a', 65)]], 'must be a preset name');
        $this->assertFalse(pp_udc_valid_preset_name(''));
    }

    // ── INTERSECT SEMANTICS (orchestrator ruling, T2) ────────────────────────
    //
    // A role-grain preset applies the groups the target role permits and skips
    // the rest, rather than being refused whole. Two things keep that honest, and
    // both are pinned here: the skip is DISCLOSED on the write envelope, and an
    // EMPTY intersection refuses rather than degrading into a silent no-op.

    public function testAPresetAppliesTheGroupsTheRolePermitsAndSkipsTheRest(): void
    {
        // `avatar` permits no typography. The button preset's other five groups
        // still apply; typography is skipped.
        $this->assertAccepted(['avatar' => ['_preset' => 'button']]);

        $css = pp_udc_band_css($this->band(['avatar' => ['_preset' => 'button']]));
        $this->assertStringContainsString('border-width:2px;', $css, 'the permitted groups apply');
        $this->assertStringContainsString('min-height:44px;', $css);
        $this->assertStringNotContainsString('font-weight', $css, 'and the skipped group does not');
        $this->assertStringNotContainsString('text-decoration-line', $css);
    }

    public function testTheSkippedGroupsAreDisclosedOnTheWriteEnvelope(): void
    {
        // A partial apply is fine; a SILENT partial apply is not. The author
        // asked for a bundle and got part of one, and nothing else on any surface
        // would tell them which part — so the skip rides the same findings channel
        // the minting disclosure uses.
        //
        // This test is what a mutation dropping the finding (while keeping the
        // apply) has to fail. Asserting the type alone would not be enough: the
        // message has to name the skipped group, or the disclosure discloses
        // nothing actionable.
        $findings = pp_udc_composition_findings([$this->band(['avatar' => ['_preset' => 'button']])]);

        $skipped = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_preset_groups_skipped'
        ));
        $this->assertCount(1, $skipped, 'exactly one disclosure for one partially-applied preset');
        $this->assertStringContainsString('avatar', $skipped[0]['message'], 'names the role');
        $this->assertStringContainsString('button', $skipped[0]['message'], 'names the preset');
        $this->assertStringContainsString('typography', $skipped[0]['message'], 'names what was SKIPPED');
        $this->assertStringContainsString('Applied: spacing', $skipped[0]['message'], 'and what was applied');
        $this->assertSame(0, $skipped[0]['index'], 'and which band');
    }

    public function testNoDisclosureWhenNothingWasSkipped(): void
    {
        // The disclosure must not cry wolf: a preset that fits the role entirely
        // is not a partial apply and produces no finding.
        $findings = pp_udc_composition_findings([$this->band(['card' => ['_preset' => 'button']])]);
        $this->assertSame(
            [],
            array_values(array_filter(
                $findings,
                static fn(array $f): bool => $f['type'] === 'udc_preset_groups_skipped'
            )),
            'the card role permits every group the button preset declares'
        );
    }

    public function testAPresetThatFitsNoGroupOfTheRoleIsRefused(): void
    {
        // THE DEGENERATE CASE. Intersect semantics must never collapse into a
        // fully silent no-op — a reference that contributes nothing is a declared
        // authoring input with no effect, the exact shape invariant I35 forbids
        // accepting quietly. Same posture as a dangling reference.
        //
        // Driven through the validator directly because no shipped role has an
        // empty intersection with a shipped preset: the guard has to be provable
        // before the data that would trip it exists.
        $error = _pp_udc_validate_preset_reference(
            'testimonials',
            'shadow-only',
            null,
            'link',
            'role',
            ['shadow'],
            []
        );
        $this->assertInstanceOf(WP_Error::class, $error);
        $message = $error->get_error_message();
        $this->assertStringContainsString('declares no group role "shadow-only" permits', $message);
        $this->assertStringContainsString('link', $message, 'names the preset');
        $this->assertStringContainsString('typography, motion', $message, 'names what it declares');
        $this->assertStringContainsString('shadow', $message, 'and what the role permits');
    }

    public function testWhatIsEmittedIsExactlyWhatTheRolePermits(): void
    {
        // WHAT THIS DOES AND DOES NOT COVER, stated plainly because an earlier
        // version of it claimed more than it delivered.
        //
        // It covers the EMIT side completely: every permitted group's properties
        // appear, every skipped group's do not, and the accept/refuse boundary at
        // the write gate is exercised for all twelve roles. Removing the
        // intersection from the emitter turns it red.
        //
        // It does NOT detect a write gate that keeps the emitter's intersection
        // but validates a DIFFERENT set — the gate's split has no observable
        // consequence unless a preset carries a value that would fail grammar, and
        // the preset registry is a hardcoded static with no injection point, so no
        // such preset can be constructed. Verified: giving the gate alone a
        // narrower second copy of the split leaves this suite green. Closing it
        // needs a registry seam, which also unblocks the nested-preset and
        // group-grain-definition branches; filed as a follow-up rather than bolted
        // on here.
        $preset = pp_udc_presets()['button']['udc'];

        foreach (pp_udc_component_roles('testimonials') as $role => $definition) {
            $permitted = $definition['groups'];
            $expected  = array_values(array_intersect(array_keys($preset), $permitted));
            $skipped   = array_values(array_diff(array_keys($preset), $permitted));

            // EMIT SIDE: the CSS carries every permitted group's properties and
            // none of a skipped group's. Read off the registry, so a change to
            // either the preset or the role's permitted list is covered.
            //
            // ONE EXCEPTION, and it is the ruled cascade rather than a hole: a
            // param the ROLE DEFAULTS also declare is won by the default and
            // dropped from the authored layer, because it already emits in the
            // defaults layer. `_band` defaults `spacing.padding-top`, so the
            // preset's padding-top correctly does not appear.
            $defaults = $definition['defaults'] ?? [];
            $css      = pp_udc_band_css($this->band([$role => ['_preset' => 'button']]));
            foreach ($expected as $group) {
                foreach (array_keys($preset[$group]) as $param) {
                    if ($param[0] === ':' || isset($defaults[$group][$param])) {
                        continue;
                    }
                    $property = pp_udc_groups()[$group]['params'][$param]['property'];
                    $this->assertMatchesRegularExpression(
                        '/(^|[{;])' . preg_quote($property, '/') . ':/',
                        $css,
                        "role {$role}: permitted group {$group} must emit {$property}"
                    );
                }
            }
            foreach ($skipped as $group) {
                foreach (array_keys($preset[$group]) as $param) {
                    if ($param[0] === ':') {
                        continue;
                    }
                    $property = pp_udc_groups()[$group]['params'][$param]['property'];
                    $this->assertDoesNotMatchRegularExpression(
                        '/(^|[{;])' . preg_quote($property, '/') . ':/',
                        $css,
                        "role {$role}: skipped group {$group} must NOT emit {$property}"
                    );
                }
            }

            // WRITE SIDE: the gate accepts the reference exactly when the
            // intersection is non-empty, and independently refuses each skipped
            // group written inline — which is what makes "skipped" mean "this
            // role genuinely does not take it" rather than "we chose not to".
            $this->assertAccepted([$role => ['_preset' => 'button']], "role {$role}");
            foreach ($skipped as $group) {
                $this->assertRefused(
                    [$role => [$group => $preset[$group]]],
                    'does not permit the UDC group',
                    "role {$role} must refuse {$group} written inline"
                );
            }
        }
    }

    public function testTheButtonPresetsDeclareNoInertShadowGroup(): void
    {
        // `.btn` sets `box-shadow: var(--btn-shadow, none)`, so a `shadow` entry
        // would paint nothing — and `shadow` is the narrowest group in the
        // taxonomy, so carrying it would make the skipped-groups disclosure fire
        // on nine of twelve roles to report that a no-op was not applied.
        foreach (['button', 'button-secondary'] as $name) {
            $this->assertArrayNotHasKey('shadow', pp_udc_presets()[$name]['udc'], $name);
        }

        // Which is what lets the shipped presets reach every role.
        foreach (array_keys(pp_udc_presets()) as $name) {
            foreach (array_keys(pp_udc_component_roles('testimonials')) as $role) {
                $this->assertAccepted([$role => ['_preset' => $name]], "{$name} on {$role}");
            }
        }
    }

    public function testAGroupGrainReferenceToAPresetThatSaysNothingThereIsRefused(): void
    {
        $this->assertRefused(
            ['card' => ['shadow' => ['_preset' => 'link']]],
            'declares nothing for',
            'the link preset has no shadow group'
        );
    }

    // ── States ───────────────────────────────────────────────────────────────

    public function testAllThreeStatesValidateAndEmitInTheRuledOrder(): void
    {
        $udc = ['card' => ['background' => [
            'fill'            => '#ffffff',
            ':hover'          => ['fill' => '#f4f7fb'],
            ':focus-visible'  => ['fill' => '#eef2ff'],
            ':active'         => ['fill' => '#e4e9f7'],
        ]]];
        $this->assertAccepted($udc);

        $css = pp_udc_band_css($this->band($udc));
        $hover  = strpos($css, ':hover{');
        $focus  = strpos($css, ':focus-visible{');
        $active = strpos($css, ':active{');

        $this->assertNotFalse($hover);
        $this->assertNotFalse($focus);
        $this->assertNotFalse($active);
        // Identical specificity by construction, so ORDER is the ranking: a
        // pressed element must show :active, not :hover.
        $this->assertLessThan($focus, $hover, 'hover prints before focus-visible');
        $this->assertLessThan($active, $focus, 'focus-visible prints before active');
    }

    public function testAStateValueMayItselfBeBreakpointKeyed(): void
    {
        $udc = ['quote' => ['typography' => [':hover' => ['size' => ['d' => '20px', 'p' => '18px']]]]];
        $this->assertAccepted($udc);

        $css = pp_udc_band_css(pp_udc_normalize_band($this->band($udc)));
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString(':hover{font-size:var(--pp-quote-typography-size-hover-p);}', $css);
    }

    public function testAnUnknownStateKeyIsRefusedAndNamesTheThreeThatExist(): void
    {
        foreach ([':disabled', ':focus', ':visited', '::before'] as $key) {
            $error = $this->assertRefused(
                ['card' => ['background' => [$key => ['fill' => '#ffffff']]]],
                'which does not exist',
                "the deferred state {$key} must refuse"
            );
            $message = $error->get_error_message();
            foreach ([':hover', ':focus-visible', ':active'] as $known) {
                $this->assertStringContainsString($known, $message);
            }
            $this->assertStringContainsString('not supported', $message);
        }
    }

    public function testStatesDoNotNest(): void
    {
        $this->assertRefused(
            ['card' => ['background' => [':hover' => [':active' => ['fill' => '#fff']]]]],
            'States do not nest'
        );
    }

    public function testAStateMapMustBeAnObject(): void
    {
        $this->assertRefused(
            ['card' => ['background' => [':hover' => 'red']]],
            'must be an object of parameters'
        );
    }

    public function testAStateParamStillObeysItsParametersGrammar(): void
    {
        $error = $this->assertRefused(
            ['card' => ['background' => [':active' => ['fill' => 'chartreuse']]]],
            'valid CSS color'
        );
        $this->assertStringContainsString(':active', $error->get_error_message(), 'the refusal names the state');
    }

    public function testTheStateRegistryIsDataAndCarriesItsMintSegments(): void
    {
        $states = pp_udc_states();
        $this->assertSame([':hover', ':focus-visible', ':active'], array_keys($states));
        $this->assertSame('focus-visible', $states[':focus-visible']['mint']);
        $this->assertSame(
            ['', ':hover', ':focus-visible', ':active'],
            pp_udc_states_in_emit_order(),
            'the base state leads, then the ruled order'
        );
    }

    // ── Motion ───────────────────────────────────────────────────────────────

    public function testTheMotionDefaultsStillEqualTheThemesTransitionToken(): void
    {
        // THE ANTI-DRIFT PIN. The two motion defaults are LITERALS copied out of
        // `--transition`, because that token holds `150ms ease` — a value neither
        // the `duration` grammar nor the `timing-function` grammar accepts, so
        // neither param can reference it. A copy silently diverges the day
        // someone retunes the token; this turns that into a red test.
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/base.css');
        $this->assertSame(
            1,
            preg_match('/--transition:\s*([^;]+);/', $css, $m),
            'base.css must still declare --transition'
        );

        $parts = preg_split('/\s+/', trim($m[1]));
        $this->assertCount(2, $parts, '--transition is a duration and a timing function');

        $defaults = pp_udc_motion_defaults();
        $this->assertSame($parts[0], $defaults['transition-duration'], 'duration drifted from --transition');
        $this->assertSame($parts[1], $defaults['timing-function'], 'timing function drifted from --transition');
    }

    public function testTheMotionGroupEmitsBothCssProperties(): void
    {
        $css = pp_udc_band_css($this->band([
            'card' => ['motion' => ['transition-duration' => '240ms', 'timing-function' => 'ease-in-out']],
        ]));
        $this->assertStringContainsString('transition-duration:240ms;', $css);
        $this->assertStringContainsString('transition-timing-function:ease-in-out;', $css);
    }

    public function testTheEngineEmitsItsOwnReducedMotionGuardForItsOwnMotionValues(): void
    {
        $css = pp_udc_band_css($this->band([
            'card' => ['motion' => ['transition-duration' => '900ms']],
        ]));

        $guard = strpos($css, '@media (prefers-reduced-motion: reduce)');
        $this->assertNotFalse($guard, 'the engine emits the guard itself; it is not authorable');
        $this->assertStringContainsString(
            '[data-pp-band="pp-a1b2c3d4"] .testimonials__item{transition-duration:0.01ms;}',
            $css
        );
        // It must print AFTER the declarations it neutralizes: both sit at the
        // same specificity by construction, so source order is the whole
        // mechanism. And never `!important` — §3.4 forbids the engine emitting one.
        $this->assertLessThan($guard, strpos($css, 'transition-duration:900ms;'));
        $this->assertStringNotContainsString('!important', $css);
    }

    public function testTheReducedMotionGuardReachesStateScopedMotionToo(): void
    {
        // THE SPECIFICITY TRAP. Motion declared inside a state emits at
        // `[data-pp-band] .role:hover` [0,3,0]. A guard emitted at the bare
        // `[data-pp-band] .role` [0,2,0] loses on specificity, so a reduced-motion
        // user hovering still gets the full transition — the guard exists for
        // exactly those users and would have silently missed them.
        foreach (pp_udc_states() as $state => $meta) {
            $css = pp_udc_band_css($this->band([
                'card' => ['motion' => [$state => ['transition-duration' => '900ms']]],
            ]));
            $this->assertStringContainsString(
                '[data-pp-band="pp-a1b2c3d4"] .testimonials__item' . $state . '{transition-duration:0.01ms;}',
                $css,
                "the guard must carry {$state}, matching the specificity of the rule it neutralizes"
            );
            $this->assertLessThan(
                strpos($css, '@media (prefers-reduced-motion: reduce)'),
                strpos($css, 'transition-duration:900ms;'),
                'and still print after it'
            );
        }
    }

    public function testTheReducedMotionGuardCoversBaseAndStateTogether(): void
    {
        $css = pp_udc_band_css($this->band([
            'card' => ['motion' => ['transition-duration' => '200ms', ':hover' => ['transition-duration' => '900ms']]],
        ]));
        $guard = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'));
        $this->assertStringContainsString('.testimonials__item,', $guard, 'the base rule is covered');
        $this->assertStringContainsString('.testimonials__item:hover{', $guard, 'and so is the hover rule');
    }

    public function testNoReducedMotionGuardIsEmittedForABandWithNoMotion(): void
    {
        $css = pp_udc_band_css($this->band(['quote' => ['typography' => ['size' => '19px']]]));
        $this->assertStringNotContainsString('prefers-reduced-motion', $css, 'a band without motion pays nothing');
    }

    public function testReducedMotionIsNotAuthorable(): void
    {
        // It is a structural affordance, so there is no parameter for it and no
        // way to switch it off from a band.
        $this->assertArrayNotHasKey('prefers-reduced-motion', pp_udc_groups()['motion']['params']);
        $this->assertRefused(
            ['card' => ['motion' => ['prefers-reduced-motion' => 'no-preference']]],
            'has no parameter'
        );
    }

    // ── The timing-function grammar, on the ONE dispatcher ───────────────────

    public function testTimingFunctionAcceptsEveryKeywordCssDefines(): void
    {
        foreach (['linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end'] as $keyword) {
            $this->assertAccepted(
                ['card' => ['motion' => ['timing-function' => $keyword]]],
                "the keyword {$keyword}"
            );
        }
    }

    public function testCubicBezierAcceptsNegativeYValuesWhichIsWhatOvershootIs(): void
    {
        // The shared number body is UNSIGNED, so this is the case a naive reuse
        // of it silently rejects — and it is the case people actually reach for.
        $this->assertAccepted(['card' => ['motion' => ['timing-function' => 'cubic-bezier(.34,1.56,.64,1)']]]);
        $this->assertAccepted(['card' => ['motion' => ['timing-function' => 'cubic-bezier(0.5, -0.6, 0.5, 1.6)']]]);
    }

    public function testCubicBezierRefusesAnXOutsideZeroToOne(): void
    {
        // CSS constrains the 1st and 3rd numbers; a value outside makes the whole
        // declaration invalid, so accepting it would store something inert.
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'cubic-bezier(2,0,1,1)']]], 'timing function');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'cubic-bezier(0,0,-1,1)']]], 'timing function');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'cubic-bezier(0,0,1)']]], 'timing function');
    }

    public function testStepsAcceptsAllSixJumpKeywords(): void
    {
        foreach (['jump-start', 'jump-end', 'jump-none', 'jump-both', 'start', 'end'] as $jump) {
            $this->assertAccepted(
                ['card' => ['motion' => ['timing-function' => "steps(4, {$jump})"]]],
                "the jump keyword {$jump}"
            );
        }
        $this->assertAccepted(['card' => ['motion' => ['timing-function' => 'steps(3)']]]);
    }

    public function testStepsRefusesAnUnboundedCount(): void
    {
        // `\d+` has no upper bound, and the value is emitted VERBATIM — the
        // author's digits, not the integer we parsed — so without a cap one
        // stored value carries arbitrary length into every render. The cast
        // saturates to PHP_INT_MAX, which is what makes a numeric bound enough.
        $this->assertRefused(
            ['card' => ['motion' => ['timing-function' => 'steps(' . str_repeat('9', 40) . ')']]],
            'timing function'
        );
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'steps(1001)']]], 'timing function');
        $this->assertAccepted(['card' => ['motion' => ['timing-function' => 'steps(1000)']]], 'the bound itself');
    }

    public function testStateMintDecodingIsCorrectForEveryRegisteredState(): void
    {
        // The plain round trip, per state, derived from the registry so a new
        // state is covered the day it is added.
        foreach (pp_udc_states() as $key => $meta) {
            $parts = array_merge(['quote', 'typography', 'size'], explode('-', $meta['mint']));
            [$state, $rest] = _pp_udc_state_from_mint($parts);
            $this->assertSame($key, $state, "decoding the {$key} mint segment");
            $this->assertSame(['quote', 'typography', 'size'], $rest);
        }

        [$plain, $all] = _pp_udc_state_from_mint(['quote', 'typography', 'size']);
        $this->assertSame('', $plain, 'no state segment means the base state');
        $this->assertSame(['quote', 'typography', 'size'], $all);
    }

    public function testTheMintMatcherIsDrivenByLengthNotRegistryOrder(): void
    {
        // THIS TEST REPLACES A VACUOUS ONE, and the reason is worth recording.
        // The first version asserted two lookups against the CURRENT registry,
        // where `:focus-visible` happens to be declared before any one-segment
        // mint — so registry order and length order coincide and deleting the
        // longest-first sort left every UDC test green. A review specialist
        // proved that by mutation on a copy of the tree.
        //
        // So pin the two facts the sort actually protects, both of which go red
        // without it:
        //
        //   1. no mint may be a SUFFIX of another, and
        //   2. the matcher must try candidates longest-first anyway, so that a
        //      future violation of (1) degrades into a failing test rather than
        //      a silently mis-decoded stored token name.
        $mints = array_column(pp_udc_states(), 'mint');
        foreach ($mints as $a) {
            foreach ($mints as $b) {
                if ($a === $b) {
                    continue;
                }
                $a_segments = explode('-', $a);
                $b_segments = explode('-', $b);
                $this->assertNotSame(
                    $b_segments,
                    array_slice($a_segments, -count($b_segments)),
                    "the mint \"{$b}\" is a suffix of \"{$a}\"; decoding would be ambiguous"
                );
            }
        }

        // (2) asserted against a SYNTHETIC registry whose declaration order
        // disagrees with length order. This is the only shape that can tell the
        // sort apart from registry order: against the real registry the two
        // coincide, so a test built on it stays green with the sort deleted —
        // which is exactly how the first two versions of this test were vacuous.
        $synthetic = [
            ':short' => ['mint' => 'visible',       'emit_order' => 0],
            ':long'  => ['mint' => 'focus-visible', 'emit_order' => 1],
        ];
        $this->assertSame(
            [':long', ':short'],
            array_keys(_pp_udc_state_mints_longest_first($synthetic)),
            'the two-segment mint must be offered first even though it is declared second'
        );

        // And on the REAL registry the sort does reorder — `:hover` is declared
        // first but is one segment, `:focus-visible` is two — which is only
        // harmless because assertion (1) above holds. That is the whole shape of
        // this guard: the sort is what keeps (1) from being load-bearing.
        $real = array_keys(_pp_udc_state_mints_longest_first());
        $this->assertSame(':focus-visible', $real[0], 'the two-segment mint is offered first');
        $this->assertSame(
            count(pp_udc_states()),
            count($real),
            'every registered state is offered exactly once'
        );
    }

    public function testStepsRefusesCountsCssItselfRefuses(): void
    {
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'steps(0)']]], 'timing function');
        // jump-none needs an interior jump to omit, so it needs two or more.
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'steps(1, jump-none)']]], 'timing function');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'steps(2, sideways)']]], 'timing function');
    }

    public function testTheNewSurfaceCannotBreakOutOfTheStyleBlock(): void
    {
        // The shared reject set, the balanced-delimiter guard and the emit-time
        // re-validation all apply to `timing-function` because it routes through
        // pp_udc_validate_value() like every other param. Pinned on the NEW
        // surface, because that is what was not covered before.
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'cubic-bezier(']]], 'unbalanced');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'steps(1;}']]], 'must not contain');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'ease;color:red']]], 'must not contain');
        $this->assertRefused(['card' => ['motion' => ['transition-duration' => '150']]], 'time unit');
    }

    public function testBothMotionParamsAgreeThatValuesAreCaseSensitive(): void
    {
        // The two params sit side by side in one group, so they must not disagree
        // about whether the value is folded. Every grammar in lib/apply.php is
        // case-sensitive; an earlier draft made `timing-function` the one
        // exception, which meant `EASE` was accepted while `150MS` beside it was
        // refused.
        $this->assertAccepted(['card' => ['motion' => ['timing-function' => 'ease-in-out']]]);
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'EASE']]], 'case-sensitive');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'Cubic-Bezier(0,0,1,1)']]], 'timing function');
        $this->assertRefused(['card' => ['motion' => ['timing-function' => 'STEPS(4, JUMP-END)']]], 'timing function');

        $this->assertAccepted(['card' => ['motion' => ['transition-duration' => '150ms']]]);
        $this->assertRefused(['card' => ['motion' => ['transition-duration' => '150MS']]], 'time unit');
    }

    public function testTheTimingFunctionRefusalStatesTheRulesThatActuallyRefuse(): void
    {
        // A refusal that restates what the author already wrote teaches nothing.
        // These three rules are the ones a model cannot guess from the value.
        $message = $this->assertRefused(
            ['card' => ['motion' => ['timing-function' => 'steps(1, jump-none)']]],
            'timing function'
        )->get_error_message();

        $this->assertStringContainsString('jump-none additionally needs two or more steps', $message);
        $this->assertStringContainsString('at most 1000', $message);
        $this->assertStringContainsString('case-sensitive', $message);
    }

    public function testTimingFunctionIsDeclaredOnTheSharedDispatcherNotASecondValidator(): void
    {
        // The one-predicate rule: the render boundary and every other surface
        // inherit the type because they all delegate to this function.
        $this->assertTrue(_pp_validate_token_value('ease-in-out', 'timing-function'));
        $this->assertInstanceOf(WP_Error::class, _pp_validate_token_value('wobble', 'timing-function'));
    }

    // ── The schema data that makes motion reachable ──────────────────────────

    public function testEveryTestimonialsRolePermitsTheMotionGroup(): void
    {
        foreach (pp_udc_component_roles('testimonials') as $name => $role) {
            $this->assertContains('motion', $role['groups'], "role {$name} must be able to move");
        }
    }

    // ── The model is told all three capabilities exist ───────────────────────

    public function testTheRuntimePromptTeachesPresetsStatesAndMotion(): void
    {
        // A capability the authoring model is still instructed to refuse is an
        // incomplete implementation, so the prompt is part of the contract.
        $prompt = pp_ai_system_prompt();

        $this->assertStringContainsString(':focus-visible', $prompt);
        $this->assertStringContainsString(':active', $prompt);
        $this->assertStringContainsString('do not nest', $prompt);

        $this->assertStringContainsString('transition-duration', $prompt);
        $this->assertStringContainsString('timing-function', $prompt);
        $this->assertStringContainsString('prefers-reduced-motion', $prompt);

        // ASSERT ON TEXT ONLY THE PRESET PARAGRAPH PRODUCES. An earlier version
        // checked for 'button', 'link' and 'bare' — which occur 78, 26 and 8
        // times elsewhere in the prompt, so they held with the entire preset
        // paragraph deleted. A review specialist caught that by stripping it.
        $this->assertStringContainsString('_preset', $prompt);
        $this->assertStringContainsString(
            'The presets that exist today are ' . implode(', ', array_keys(pp_udc_presets())),
            $prompt,
            'the live registry is rendered into the prompt, not a frozen copy'
        );
        // The two things a model would otherwise get wrong: the missing sigil,
        // and expecting a preset to beat the role's own defaults.
        $this->assertStringContainsString('Write the name BARE', $prompt);
        $this->assertStringContainsString('role defaults outrank presets', $prompt);
        // The intersect semantics and, critically, the fact that the envelope
        // reports the skip: a model that does not know to read it back will
        // assume the whole bundle landed.
        $this->assertStringContainsString('applies only the groups that role PERMITS', $prompt);
        $this->assertStringContainsString('write envelope tells you exactly which groups were skipped', $prompt);
    }
}
