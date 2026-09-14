<?php
/**
 * tests/UdcEngineTest.php
 *
 * THE UDC CONTRACT, as executable evidence (v2 BUILD-SPEC §3).
 *
 * This file replaces what testimonials' style-slot and render-guard pins used to
 * cover, over the vocabulary that replaced them. Where the old pins asked "does
 * this component declare these 27 slots and paint them into an inline style
 * attribute", these ask the questions the new contract makes answerable: does a
 * role exist, is a group permitted on it, does a value satisfy its parameter's
 * grammar, is a responsive value minted deterministically and DISCLOSED, does the
 * emitted block scope to exactly one band, and does a band with no identity
 * render structurally instead of borrowing someone else's design.
 */

use PHPUnit\Framework\TestCase;

final class UdcEngineTest extends TestCase
{
    /** A band that exercises every part of the contract at once. */
    private function band(array $udc = [], string $id = 'pp-3f9a1c2e'): array
    {
        return [
            'component' => 'testimonials',
            'id'        => $id,
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => $udc,
        ];
    }

    // ── The registry is DATA ─────────────────────────────────────────────────

    /**
     * The engine is shared; the component contributes declarations. If a role
     * name ever reaches engine code, the next component's rebuild starts by
     * editing the engine — which is the coupling the whole design avoids.
     */
    public function testTheEngineNamesNoComponentAndNoRole(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        // Comments legitimately discuss testimonials as the worked example.
        $code = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $source);

        foreach (['testimonials', 'quote', 'attribution', '__item', '__quote'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                "lib/udc.php must not name {$needle}: roles and selectors are schema data"
            );
        }
    }

    public function testEveryRoleGroupResolvesToRealCssPropertiesAndTypes(): void
    {
        foreach (pp_udc_groups() as $group => $definition) {
            $this->assertNotEmpty($definition['params'], "group {$group} must declare parameters");
            foreach ($definition['params'] as $param => $spec) {
                $this->assertArrayHasKey('property', $spec, "{$group}.{$param} must emit a CSS property");
                $this->assertMatchesRegularExpression(
                    '/^[a-z-]+$/',
                    $spec['property'],
                    "{$group}.{$param}'s property must be a plain CSS property name"
                );
                // The property is looked up here and never taken from author input —
                // an author can no more influence the property text than invent a role.
                $this->assertArrayHasKey('type', $spec, "{$group}.{$param} must declare a grammar");
            }
        }
    }

    // ── Breakpoints ─────────────────────────────────────────────────────────

    /**
     * THE RANGES MUST NOT OVERLAP, and this is not cosmetic.
     *
     * Desktop is the base, so overrides are max-width. If two override ranges
     * both matched at some viewport, the one emitted later would win there and a
     * declared value would be silently cancelled — invariant I35's exact
     * prohibition. Non-overlapping ranges make that structurally impossible.
     */
    public function testBreakpointRangesAreMutuallyExclusiveAndMatchTheThemesOwnIntegers(): void
    {
        $bp = pp_udc_breakpoints();
        $this->assertNull($bp['d']['media'], 'desktop is the base and carries no media query');
        $this->assertSame('(min-width: 768px) and (max-width: 1023px)', $bp['t']['media']);
        $this->assertSame('(max-width: 767px)', $bp['p']['media']);

        // The theme's own stylesheet uses these same integers. A second breakpoint
        // semantics inside one page is the hidden divergence I36 forbids.
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/base.css')
             . file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertStringContainsString('(min-width: 768px)', $css);
        $this->assertStringContainsString('(max-width: 767px)', $css);

        // Proof by sampling, DERIVED FROM THE REGISTRY. An earlier version of this
        // block recomputed the ranges from hardcoded literals, so it could not fail
        // whatever the registry said — the mutual-exclusion property it claimed to
        // prove was carried entirely by the three string comparisons above.
        foreach ([320, 500, 767, 768, 900, 1023, 1024, 1280, 1920] as $width) {
            $matched = [];
            foreach ($bp as $key => $meta) {
                if ($meta['media'] === null) {
                    continue; // the base tier is not an override
                }
                $min = preg_match('/min-width:\s*(\d+)px/', $meta['media'], $m) ? (int) $m[1] : 0;
                $max = preg_match('/max-width:\s*(\d+)px/', $meta['media'], $m) ? (int) $m[1] : PHP_INT_MAX;
                if ($width >= $min && $width <= $max) {
                    $matched[] = $key;
                }
            }
            $this->assertLessThanOrEqual(
                1,
                count($matched),
                "viewport {$width}px is claimed by more than one override tier (" . implode(', ', $matched) . ')'
            );
        }
    }

    // ── Validation refusals ─────────────────────────────────────────────────

    public function testAnUnknownRoleIsRefusedAndTheAvailableRolesAreNamed(): void
    {
        $error = pp_udc_validate_map(['quotte' => ['typography' => ['size' => '1rem']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertStringContainsString('quotte', $error->get_error_message());
        $this->assertStringContainsString('Available roles:', $error->get_error_message());
        $this->assertStringContainsString('quote', $error->get_error_message());
    }

    public function testAGroupThatDoesNotExistAndAGroupTheRoleForbidsAreBothRefused(): void
    {
        $nonexistent = pp_udc_validate_map(['quote' => ['typografy' => ['size' => '1rem']]], 'testimonials');
        $this->assertSame('unknown_udc_group', $nonexistent->get_error_code());
        $this->assertStringContainsString('does not exist', $nonexistent->get_error_message());

        // `shadow` exists but the quote does not permit it — a box-shadow on a run
        // of text belongs on the card. The permitted set is per-role DATA, and this
        // is the assertion that proves the gate reads it.
        $forbidden = pp_udc_validate_map(['quote' => ['shadow' => ['box' => 'none']]], 'testimonials');
        $this->assertSame('unknown_udc_group', $forbidden->get_error_code());
        $this->assertStringContainsString('does not permit', $forbidden->get_error_message());
        $this->assertStringContainsString('Permitted groups:', $forbidden->get_error_message());
    }

    public function testAnUnknownParameterAndAnUnknownBreakpointAreRefusedByName(): void
    {
        $param = pp_udc_validate_map(['quote' => ['typography' => ['fontsize' => '1rem']]], 'testimonials');
        $this->assertSame('invalid_prop_value', $param->get_error_code());
        $this->assertStringContainsString('has no parameter', $param->get_error_message());
        $this->assertStringContainsString('Available parameters:', $param->get_error_message());

        $bp = pp_udc_validate_map(['quote' => ['typography' => ['size' => ['xl' => '1rem']]]], 'testimonials');
        $this->assertSame('invalid_prop_value', $bp->get_error_code());
        $this->assertStringContainsString('has no breakpoint', $bp->get_error_message());
    }

    public function testAValueIsCheckedAgainstItsParametersOwnGrammarIncludingTheSignedRule(): void
    {
        // Every refusal names band, role, group AND parameter — the locator §3.6 asks for.
        $bad = pp_udc_validate_map(['quote' => ['typography' => ['size' => 'huge']]], 'testimonials');
        $this->assertSame('invalid_prop_value', $bad->get_error_code());
        foreach (['testimonials', 'quote', 'typography', 'size'] as $fragment) {
            $this->assertStringContainsString($fragment, $bad->get_error_message());
        }

        // §3.3's "signed where the property allows", as a per-property fact:
        // letter-spacing goes negative, padding does not.
        $this->assertNull(pp_udc_validate_map(
            ['quote' => ['typography' => ['letter-spacing' => '-0.02em']]],
            'testimonials'
        ));
        $negativePadding = pp_udc_validate_map(['card' => ['spacing' => ['padding' => '-1rem']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $negativePadding);
        $this->assertStringContainsString('Negative values are not accepted', $negativePadding->get_error_message());
    }

    public function testTheShorthandFamiliesTakeOneToFourValuesAndNothingElseDoes(): void
    {
        $this->assertNull(pp_udc_validate_map(['card' => ['spacing' => ['padding' => '1rem 2rem']]], 'testimonials'));
        $this->assertNull(pp_udc_validate_map(['card' => ['spacing' => ['padding' => '1rem 2rem 3rem 4rem']]], 'testimonials'));

        $tooMany = pp_udc_validate_map(['card' => ['spacing' => ['padding' => '1rem 2rem 3rem 4rem 5rem']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $tooMany);

        // A longhand is one value, and font-size is not a shorthand at all.
        $this->assertInstanceOf(
            WP_Error::class,
            pp_udc_validate_map(['card' => ['spacing' => ['padding-top' => '1rem 2rem']]], 'testimonials')
        );
        $this->assertInstanceOf(
            WP_Error::class,
            pp_udc_validate_map(['quote' => ['typography' => ['size' => '1rem 2rem']]], 'testimonials')
        );
    }

    // ── References ──────────────────────────────────────────────────────────

    /**
     * An unresolvable reference REFUSES. It is never resolved to empty, never
     * emitted as the literal text, and never silently dropped at write —
     * invariant I9: a failed read is never mapped to a valid answer.
     */
    public function testAnUnresolvableReferenceIsRefusedRatherThanResolvedToNothing(): void
    {
        $error = pp_udc_validate_map(['quote' => ['typography' => ['family' => '@font-nonexistent']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('@font-nonexistent', $error->get_error_message());
        $this->assertStringContainsString('not a registered design token', $error->get_error_message());
    }

    public function testAReferenceIsTypeCheckedAgainstTheParameterThatUsesIt(): void
    {
        // --color-text is a colour token; a length parameter pointed at it would
        // emit guaranteed-invalid CSS the browser drops.
        $error = pp_udc_validate_map(['quote' => ['typography' => ['size' => '@color-text']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('is not valid here', $error->get_error_message());

        // The same token in a colour parameter is fine.
        $this->assertNull(pp_udc_validate_map(['quote' => ['typography' => ['color' => '@color-text']]], 'testimonials'));
    }

    public function testABandTokenMayNotReferenceAnotherTokenSoNoCycleCanExist(): void
    {
        $error = pp_udc_validate_map([
            '_tokens' => ['a' => '@b', 'b' => '1rem'],
            'quote'   => ['typography' => ['size' => '@a']],
        ], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('must be a literal value', $error->get_error_message());
    }

    public function testBandTokensResolveBeforeSiteTokens(): void
    {
        $css = pp_udc_band_css($this->band([
            '_tokens' => ['color-text' => '#ff0000'],
            'quote'   => ['typography' => ['color' => '@color-text']],
        ]));
        // The band's own definition wins, and it is emitted as a band-scoped
        // custom property rather than the site token's var().
        $this->assertStringContainsString('--pp-color-text:#ff0000', $css);
        $this->assertStringContainsString('color:var(--pp-color-text)', $css);
    }

    // ── Minting ─────────────────────────────────────────────────────────────

    public function testOnlyAResponsiveValueMintsAndTheNameIsDeterministic(): void
    {
        $disclosures = [];
        $scalar = pp_udc_normalize_band(
            $this->band(['quote' => ['typography' => ['size' => '19px']]]),
            $disclosures
        );
        $this->assertArrayNotHasKey('_tokens', $scalar['udc'], 'a plain literal mints nothing');
        $this->assertSame([], $disclosures, 'and discloses nothing, because nothing was normalised');

        $disclosures = [];
        $responsive = pp_udc_normalize_band(
            $this->band(['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]),
            $disclosures
        );
        $this->assertSame(
            ['quote-typography-size-d' => '19px', 'quote-typography-size-p' => '17px'],
            $responsive['udc']['_tokens'],
            'mint names are <role>-<group>-<param>-<bp>, deterministic and collision-free across groups'
        );
        $this->assertSame(
            ['d' => '@quote-typography-size-d', 'p' => '@quote-typography-size-p'],
            $responsive['udc']['quote']['typography']['size']
        );

        // Deterministic means a second pass over the same input produces the same
        // names — otherwise the content hash would change on every write and the
        // composition would false-conflict against itself.
        $again = [];
        $twice = pp_udc_normalize_band(
            $this->band(['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]),
            $again
        );
        $this->assertSame($responsive['udc'], $twice['udc']);
    }

    /**
     * THE NO-COERCION RULE (§3.1) AND INVARIANT I36.
     *
     * Minting rewrites what is stored. That is only acceptable because it is
     * VISIBLE: the author wrote `19px`, and the envelope says `19px`, naming the
     * reference it was stored as. A normalization that reported only its own
     * output would be a hidden alias.
     *
     * The disclosure is derived from the STORED result rather than handed out of
     * the normalizer, because the surfaces that need it — the post-write
     * envelope, restore, `check page` — all run over stored data, by which point
     * minting has already happened. That works because minting PRESERVES the
     * literal as the token's value.
     */
    public function testTheMintDisclosesTheAuthorsLiteralBesideTheMintedReference(): void
    {
        $item = pp_udc_normalize_band(
            $this->band(['quote' => ['typography' => ['size' => ['d' => '19px']]]])
        );

        $findings = array_values(array_filter(
            pp_udc_composition_findings([$item]),
            static fn (array $f): bool => $f['type'] === 'udc_token_minted'
        ));

        $this->assertCount(1, $findings);
        $message = $findings[0]['message'];
        $this->assertStringContainsString('you wrote "19px"', $message, "the AUTHOR'S literal");
        $this->assertStringContainsString('--pp-quote-typography-size-d', $message, 'and the minted reference');

        // An AUTHOR-declared token is not an engine mint and must not be reported
        // as one — the disclosure would be telling them they wrote something they
        // did not.
        $authored = $this->band([
            '_tokens' => ['brand-blue' => '#0055ff'],
            'quote'   => ['typography' => ['color' => '@brand-blue']],
        ]);
        $this->assertSame([], array_values(array_filter(
            pp_udc_composition_findings([$authored]),
            static fn (array $f): bool => $f['type'] === 'udc_token_minted'
        )));
    }

    // ── Emission ────────────────────────────────────────────────────────────

    public function testEveryRuleScopesToItsOwnBandAndSpecificityStaysFlat(): void
    {
        $css = pp_udc_band_css($this->band(['quote' => ['typography' => ['style' => 'italic']]]));

        $this->assertNotSame('', $css);
        $this->assertStringNotContainsString('!important', $css, 'flat specificity needs no escape hatch');

        // Every selector begins with this band's attribute — no rule can reach
        // another band, so two bands' rules can never contend.
        foreach (preg_split('/(?<=\})/', $css, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || $chunk === '}' || str_starts_with($chunk, '@media')) {
                continue;
            }
            $this->assertStringStartsWith('[data-pp-band="pp-3f9a1c2e"]', $chunk);
        }
    }

    public function testEmissionOrderIsBaseThenNarrowFirstMediaThenHover(): void
    {
        // Normalised first, because that is what the write path stores. (The emitter
        // also renders an UN-minted responsive value — stored-before-the-rule data
        // still paints — which testTheEmitterRendersResponsiveValuesThatWereNeverMinted
        // pins separately.)
        $disclosures = [];
        $css = pp_udc_band_css(pp_udc_normalize_band($this->band([
            'quote' => ['typography' => [
                'color' => ['d' => '#111111', 't' => '#222222', 'p' => '#333333'],
                ':hover' => ['color' => '#000000'],
            ]],
        ]), $disclosures));

        $base  = strpos($css, 'color:var(--pp-quote-typography-color-d)');
        $phone = strpos($css, '@media (max-width: 767px)');
        $tablet = strpos($css, '@media (min-width: 768px) and (max-width: 1023px)');
        $hover = strpos($css, ':hover');

        $this->assertNotFalse($base);
        $this->assertNotFalse($phone);
        $this->assertNotFalse($tablet);
        $this->assertNotFalse($hover);

        $this->assertLessThan($phone, $base, 'base declarations come first');
        $this->assertLessThan($tablet, $phone, 'then @media blocks, narrow-first (§3.4)');
        $this->assertLessThan($hover, $tablet, 'then hover rules');
    }

    /**
     * Hover is a STATE, not a viewport, so it composes WITH breakpoints rather
     * than sitting beside them. A design that modelled hover as a fourth
     * breakpoint could not express "a different hover colour on phones" at all.
     */
    public function testHoverComposesWithBreakpointsRatherThanReplacingThem(): void
    {
        $disclosures = [];
        $css = pp_udc_band_css(pp_udc_normalize_band($this->band([
            'quote' => ['typography' => [':hover' => ['color' => ['d' => '#111111', 'p' => '#222222']]]],
        ]), $disclosures));
        $this->assertStringContainsString(':hover{color:var(--pp-quote-typography-color-hover-d)', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{.*?:hover\{color:var\(--pp-quote-typography-color-hover-p\)/s',
            $css,
            'a hover value must be expressible per breakpoint'
        );
    }

    /**
     * F1 — THE CRITICAL GUARD.
     *
     * Band ids are minted on WRITE only. A band that reached storage without one
     * (a raw meta write, data written before the rule, or restore_composition,
     * which reports findings without blocking per #233) must render structurally.
     * Fabricating an id here would hand two reads of one row two different ids;
     * emitting an EMPTY one would make `[data-pp-band=""]` match every other
     * id-less band on the page and paint one band's design onto another.
     */
    public function testABandWithNoUsableIdEmitsNothingAndIsNeverGivenOne(): void
    {
        // NOT in this list: 42. A numeric id is a perfectly legal attribute-selector
        // value and the charset accepts it — the gate bounds the character set, it does
        // not require the minted `pp-<hex8>` shape, because an AUTHORED id is honoured.
        foreach ([null, '', 'has spaces', 'quote"]', 'semi;colon', str_repeat('x', 65), ['pp-1']] as $id) {
            $item = $this->band(['quote' => ['typography' => ['style' => 'italic']]]);
            if ($id === null) {
                unset($item['id']);
            } else {
                $item['id'] = $id;
            }

            $this->assertSame('', pp_udc_band_css($item), 'no id, no block');

            $compiled = pp_udc_compile_band($item);
            $this->assertSame('', $compiled['id'], 'and the read must not invent one');
            $this->assertSame([], $compiled['blocks']);
        }
    }

    /**
     * Minting is a WRITE-path normalisation, so a composition written before the
     * rule — or through a raw meta write — can hold a responsive value that was
     * never lifted into `_tokens`. The emitter renders it directly rather than
     * refusing: the band paints, and the normalisation happens on its next write.
     */
    public function testTheEmitterRendersResponsiveValuesThatWereNeverMinted(): void
    {
        $css = pp_udc_band_css($this->band([
            'quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]],
        ]));
        $this->assertStringContainsString('font-size:19px', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{.*?font-size:17px/s',
            $css
        );
    }

    public function testTwoBandsNeverShareAScopeEvenWhenOneHasNoId(): void
    {
        $withId    = $this->band(['quote' => ['typography' => ['color' => '#ff0000']]], 'pp-aaaaaaaa');
        $withoutId = $this->band(['quote' => ['typography' => ['color' => '#00ff00']]]);
        unset($withoutId['id']);

        $css = pp_udc_page_css([$withId, $withoutId]);
        $this->assertStringContainsString('#ff0000', $css);
        $this->assertStringNotContainsString('#00ff00', $css, 'the id-less band contributes nothing');
        $this->assertStringNotContainsString('[data-pp-band=""]', $css, 'and above all, no empty scope');
    }

    /**
     * The emit-time reject set is the SAME one the write engine applies. Two
     * layers, one set — the posture the #330 render boundary established for v1
     * slots, carried into v2 unchanged.
     */
    public function testTheEmitterReRejectsAnythingTheWriteGateWouldHaveRefused(): void
    {
        foreach ([
            '#fff; background:url(evil)',
            '#fff} body{display:none',
            '#fff /* x',
        ] as $payload) {
            $css = pp_udc_band_css($this->band([
                'quote' => ['typography' => ['color' => $payload]],
                'card'  => ['background' => ['fill' => '#ffffff']],
            ]));
            $this->assertStringNotContainsString('url(evil)', $css);
            $this->assertStringNotContainsString('body{display:none', $css);
            $this->assertStringNotContainsString('/*', $css);
            // One refused declaration drops itself and nothing else.
            $this->assertStringContainsString('background:#ffffff', $css);
        }
    }

    /**
     * REGRESSION — CSS INJECTION THROUGH A STORED BAND-TOKEN VALUE.
     *
     * Found by the adversarial review pass, confirmed by execution, fixed here.
     *
     * A band token is emitted as a custom-property DECLARATION on the band root
     * (`--pp-<name>:<value>;`), which makes the value CSS source text. The write
     * gate validates it; the emitter did not. Everything that bypasses the write
     * gate therefore reached raw CSS unchecked: a raw meta write, a composition
     * stored before this rule existed, and restore_composition, which reports
     * findings without blocking (#233).
     *
     * The payload below closed the band's own rule and injected a rule of its
     * own. It is the style-block escape the shared reject set exists to stop,
     * arriving through the one path that was not consulting it.
     *
     * The lesson generalises past this bug: EVERY string that becomes CSS needs
     * the boundary, not just the ones shaped like values.
     */
    public function testAStoredBandTokenCannotEscapeItsRuleAndInjectCss(): void
    {
        $css = pp_udc_page_css([[
            'component' => 'testimonials',
            'id'        => 'pp-aabbccdd',
            'props'     => [],
            'udc'       => [
                '_tokens' => ['x' => 'red} body{display:none} .z{color:red'],
                'quote'   => [
                    'typography' => [
                        'color' => '@x',
                        // Authored, well-formed, same role and group: what the
                        // refused token must NOT be able to take down with it.
                        'size'  => '19px',
                    ],
                ],
            ],
        ]]);

        $this->assertStringNotContainsString('body{display:none}', $css, 'the payload must never reach the stylesheet');
        $this->assertStringNotContainsString('--pp-x:', $css, 'and the hostile token is not emitted at all');

        // Every brace in the output belongs to a rule this engine opened: the count
        // of `{` equals the count of `}`, and no rule body contains a brace.
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), 'braces stay balanced');

        // The band still paints. One refused token drops itself, not the band.
        $this->assertStringContainsString('[data-pp-band="pp-aabbccdd"]', $css);
        $this->assertMatchesRegularExpression(
            '/\[data-pp-band="pp-aabbccdd"\][^{]*\{[^}]*font-size:19px/',
            $css,
            'the authored sibling of the refused token still applies'
        );
        $this->assertStringContainsString(
            'font-style:normal',
            $css,
            'and the role defaults still apply, from the component-scoped defaults block'
        );
    }

    /**
     * The same boundary, on the token NAME and on a reference name — both are
     * interpolated into CSS source (`--pp-<name>` and `var(--pp-<name>)`).
     */
    public function testAStoredTokenOrReferenceNameCannotCarryCssSyntax(): void
    {
        foreach ([
            'closes the var()'      => 'a) red',
            'opens a comment'       => 'a/*',
            'closes a rule'         => 'a}',
            'ends the declaration'  => 'a;color:red',
            'too long'              => str_repeat('a', 65),
        ] as $label => $name) {
            $css = pp_udc_band_css([
                'component' => 'testimonials',
                'id'        => 'pp-aabbccdd',
                'props'     => [],
                'udc'       => [
                    '_tokens' => [$name => '#ff0000'],
                    'quote'   => ['typography' => ['color' => '@' . $name]],
                ],
            ]);
            $this->assertStringNotContainsString('--pp-' . $name, $css, "{$label}: the name must not reach the CSS");
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), "{$label}: braces stay balanced");
        }
    }

    /**
     * The emit boundary re-runs the TYPED grammar, not merely the reject set.
     *
     * v1's render boundary (pp_render_style_value_allowed, #330) delegates to the
     * full write-time validator, and v2 must not be the weaker of the two. A
     * stored value that clears the reject set but no longer satisfies its
     * parameter's grammar drops its own declaration and leaves the siblings
     * painting — the same degradation a refused v1 slot had.
     */
    public function testAStoredValueThatNoLongerFitsItsGrammarDropsOnlyItsOwnDeclaration(): void
    {
        $css = pp_udc_band_css([
            'component' => 'testimonials',
            'id'        => 'pp-aabbccdd',
            'props'     => [],
            'udc'       => ['quote' => ['typography' => [
                'size'  => 'enormous',      // clears the reject set, fails the grammar
                'style' => 'italic',        // valid, must still paint
            ]]],
        ]);

        $this->assertStringNotContainsString('font-size:enormous', $css);
        $this->assertStringContainsString('font-style:italic', $css, 'a refused sibling must not take the valid one down');
    }

    public function testAHostileStoredUdcMapNeverFatalsTheRender(): void
    {
        foreach ([
            'udc as a string'        => 'dark',
            'udc as a list'          => ['a', 'b'],
            'role as a scalar'       => ['quote' => 'italic'],
            'group as a scalar'      => ['quote' => ['typography' => 'italic']],
            'param as a nested list' => ['quote' => ['typography' => ['size' => [['x']]]]],
            'tokens as a scalar'     => ['_tokens' => 'x', 'quote' => ['typography' => ['style' => 'italic']]],
        ] as $label => $udc) {
            $item = $this->band();
            $item['udc'] = $udc;
            $css = pp_udc_band_css($item);
            $this->assertIsString($css, "{$label}: the emitter must degrade, never throw");
        }
    }

    // ── I35: nothing declared is silently ignored ───────────────────────────

    /**
     * §3.1: an unused `_tokens` entry is a lint WARNING, never an error.
     *
     * Reported on the WRITE side, which is the only side where a finding has
     * somewhere to go. The renderer used to build this message on every page view
     * for a consumer that did not exist — a disclosure nobody could read is the
     * promise being kept on paper only.
     */
    public function testATokenNothingReferencesIsReportedRatherThanSilentlyDropped(): void
    {
        $item = $this->band([
            '_tokens' => ['used' => '19px', 'orphan' => '17px'],
            'quote'   => ['typography' => ['size' => '@used']],
        ]);

        $compiled = pp_udc_compile_band($item);
        $this->assertArrayHasKey('used', $compiled['tokens']);
        $this->assertArrayNotHasKey('orphan', $compiled['tokens'], 'an unreferenced token emits nothing');

        $findings = pp_udc_composition_findings([$item]);
        $unused = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['type'] === 'udc_unused_band_token'
        ));
        $this->assertCount(1, $unused);
        $this->assertStringContainsString('orphan', $unused[0]['message']);
        $this->assertStringContainsString('has no effect', $unused[0]['message']);
        $this->assertSame(0, $unused[0]['index'], 'and it names the band it belongs to');
    }

    /**
     * REGRESSION — AN UNBALANCED DELIMITER IN A STORED TOKEN SWALLOWS THE PAGE.
     *
     * Found by the adversarial security pass, proven in an isolated copy, fixed
     * here. The shared reject set bans `{ } ; < >` and says nothing about an
     * unbalanced `(` or an unclosed `"` — but CSS tokenization treats both as
     * OPEN, so `--pp-t:rgb(;` consumes the terminating semicolon, the band's
     * closing brace, and every rule after it: the band's own role rules, every
     * LATER band's block, and the rest of the shared inline stylesheet.
     *
     * Not script execution (`<` is banned on every path), but one stored value
     * taking out a whole page's styling is not a degradation anyone would choose
     * — and it is reachable through the paths the engine names as the reason the
     * second layer exists: raw meta, and restore_composition, which reports
     * findings without blocking (#233).
     *
     * The fix is the general one rather than a denylist of delimiters: a token
     * DEFINITION is validated against the grammar of the parameter that
     * references it, exactly as the declaration is. `rgb(` is not a colour.
     */
    public function testAStoredTokenWithAnUnbalancedDelimiterCannotSwallowTheStylesheet(): void
    {
        foreach ([
            'unbalanced paren' => 'rgb(',
            'unclosed quote'   => '"abc',
            'both'             => 'rgb("',
            'rule escape'      => 'red} body{display:none} .z{color:red',
        ] as $label => $payload) {
            $css = pp_udc_page_css([[
                'component' => 'testimonials',
                'id'        => 'pp-aabbccdd',
                'props'     => [],
                'udc'       => [
                    '_tokens' => ['tk' => $payload],
                    'quote'   => ['typography' => ['color' => '@tk']],
                ],
            ]]);

            $this->assertStringNotContainsString('--pp-tk', $css, "{$label}: the token must not be emitted");
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), "{$label}: braces balanced");
            $this->assertSame(substr_count($css, '('), substr_count($css, ')'), "{$label}: parens balanced");
            $this->assertSame(0, substr_count($css, '"') % 2, "{$label}: quotes balanced");

            // The rest of the band still paints — one refused token is not a page.
            $this->assertStringContainsString('font-style:normal', $css, "{$label}: role defaults survive");
        }
    }

    /** The same value is refused at WRITE, so it never reaches storage in the first place. */
    public function testAHostileTokenValueIsRefusedAtWriteEvenWhenNothingReferencesIt(): void
    {
        $error = pp_udc_validate_map(['_tokens' => ['tk' => 'rgb(']], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('invalid_prop_value', $error->get_error_code());
        $this->assertStringContainsString('tk', $error->get_error_message());
    }

    public function testEveryEmittedDeclarationKnowsWhichLayerProducedIt(): void
    {
        $compiled = pp_udc_compile_band($this->band([
            'quote' => ['typography' => ['size' => '19px']],
        ]));

        $quote = null;
        foreach ($compiled['blocks'] as $block) {
            if ($block['role'] === 'quote' && $block['bp'] === 'd' && $block['state'] === '') {
                $quote = $block;
            }
        }
        $this->assertNotNull($quote);

        // The author's value won its parameter; the role default won the rest.
        // Provenance is what lets the write path disclose a value that cannot take
        // effect (I35) instead of dropping it silently.
        $this->assertSame('udc', $quote['decls']['font-size']['source']);
        $this->assertSame('19px', $quote['decls']['font-size']['literal']);
        $this->assertSame('defaults', $quote['decls']['line-height']['source']);
    }

    // ── The component's own boundary ────────────────────────────────────────

    public function testALegacyComponentAcceptsNoUdcMapAtAll(): void
    {
        $error = pp_udc_validate_map(['quote' => ['typography' => ['size' => '1rem']]], 'hero');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertStringContainsString('not on the UDC styling system', $error->get_error_message());

        // …and a legacy component emits no band block, whatever it stores.
        $this->assertSame('', pp_udc_band_css([
            'component' => 'hero',
            'id'        => 'pp-aabbccdd',
            'udc'       => ['quote' => ['typography' => ['size' => '1rem']]],
        ]));
    }

    /**
     * The other eleven components must keep working EXACTLY as before. The
     * sharpest evidence is that the v2 engine is inert for them: no ids minted,
     * no blocks emitted, no validation reaching their stored maps.
     */
    // ── Wiring guards ───────────────────────────────────────────────────────

    /**
     * F4 — A v2 COMPONENT THAT FORGETS THE ATTRIBUTE FAILS SILENTLY.
     *
     * The engine scopes every rule to `[data-pp-band="<id>"]`. If a component
     * rebuilt in a later sprint declares roles but never emits the attribute, its
     * CSS is generated, shipped in the head, and matches nothing — a whole
     * component silently unstyled with no error anywhere.
     *
     * There is no shared band wrapper to put the attribute in (each of the twelve
     * components owns its own <section>), so this lint is the guard. Discovered
     * from the schemas, so a component rebuilt next sprint joins it automatically.
     */
    public function testEveryComponentThatDeclaresRolesAlsoEmitsTheBandAttribute(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $name = basename(dirname($file));
            if (!pp_udc_is_v2_component($name)) {
                continue;
            }
            $checked++;
            $template = file_get_contents(dirname($file) . "/{$name}.php");

            $this->assertStringContainsString(
                'data-pp-band',
                $template,
                "{$name} declares roles but never emits data-pp-band — its band blocks would match nothing"
            );
            $this->assertStringContainsString(
                '__pp_udc_band',
                $template,
                "{$name} must read the band id from \$props, the only channel pp_get_component() has"
            );
            $this->assertStringContainsString(
                'pp_udc_valid_band_id',
                $template,
                "{$name} must gate the id before emitting it, so a malformed one yields NO attribute"
            );
            // And no v2 component emits an inline style attribute (§3.4).
            $this->assertStringNotContainsString(
                'pp_render_style_vars',
                $template,
                "{$name} is v2: the band block is the only styling source"
            );
        }
        $this->assertGreaterThan(0, $checked, 'the sweep must actually reach a v2 component');
    }

    /**
     * EVERY band loop promotes the band identity, through the one owner.
     *
     * There are three loops — the composition template, the front-page template,
     * and the editor preview — and a band whose identity never reaches its
     * template renders with no `data-pp-band`, so its CSS ships in the head and
     * matches nothing. The preview loop is the one that proves this is not
     * theoretical: it was already the copy that needed its own separate patch for
     * the <style> emission.
     */
    public function testAllThreeBandLoopsPromoteTheBandIdentity(): void
    {
        $loops = [
            'templates/composition.php',
            'templates/front-page.php',
            'lib/admin.php', // the editor preview renderer
        ];
        foreach ($loops as $file) {
            $source = file_get_contents(dirname(__DIR__) . '/' . $file);
            $this->assertStringContainsString(
                'pp_udc_promote_band_identity(',
                $source,
                "{$file} renders bands but never promotes the band identity — its v2 bands would ship CSS that matches nothing"
            );
            // …and beside the style promotion it mirrors, so the two cannot drift apart.
            $this->assertStringContainsString('__pp_style', $source);
        }
    }

    /**
     * F2 — THE EMITTER AND THE TEMPLATES MUST RESOLVE THE SAME COMPOSITION.
     *
     * The emitter runs at `wp_enqueue_scripts`, which fires before the <main>
     * loop, so it resolves the page independently. If the two ever disagreed the
     * page would ship CSS for bands it does not render (or render bands with no
     * CSS) and nothing would report it. Both go through the same two functions;
     * this pins that they still do.
     */
    public function testTheEmitterResolvesThePageThroughTheSameReadersTheTemplatesUse(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $start  = strpos($source, 'function pp_udc_current_composition');
        // Just this function's body — not everything after it in the file.
        $resolver = substr($source, $start, strpos($source, "\n}", $start) - $start);
        // COMMENTS STRIPPED FIRST. The function documents its own design — including
        // the sentence "not a second json_decode" — so a raw match cannot tell the
        // explanation from the defect and fails on the very comment recording the fix.
        // (The same trap DiagnosticReachTest documents for the admin.php locators.)
        $resolver = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $resolver);

        // The shared, corrupt-aware readers — never a second json_decode of the meta.
        $this->assertStringContainsString('pp_resolve_front_page_render', $resolver, 'the front page has its own classification arm');
        $this->assertStringContainsString('pp_get_composition_result', $resolver, 'and singular pages use the shared reader');
        $this->assertStringNotContainsString('json_decode', $resolver, 'a second decode would be a second answer');

        // A corrupt or absent composition resolves to NO bands on both paths, so a
        // page the template refuses to render never ships CSS for it either.
        $this->assertSame('', pp_udc_page_css([]));
    }

    /**
     * THE CLI SCHEMA REPORT MUST SURFACE ROLES.
     *
     * `wp pp schema <component>` is explicitly the surface for an agent with no
     * filesystem access to the theme. Without a roles arm it reports a v2
     * component as having no props problems, no slots and no recipes — which
     * reads as "this component cannot be styled", about a component with a full
     * design surface. That is the same wrong answer the report's empty-decode
     * guard exists to prevent, arriving through a component that is working.
     */
    public function testTheCliSchemaReportSurfacesRolesForAV2Component(): void
    {
        $report = pp_component_schema_report('testimonials');

        $this->assertArrayHasKey('roles', $report);
        $this->assertSame(count(pp_udc_component_roles('testimonials')), count($report['roles']));
        $this->assertArrayHasKey('udc_groups', $report, 'and the vocabulary the roles are addressed in');

        foreach ($report['roles'] as $entry) {
            foreach (['role', 'selector', 'groups', 'description'] as $key) {
                $this->assertArrayHasKey($key, $entry);
            }
        }

        // Reserved keys the report's own entry contract forbids colliding with.
        foreach ($report['roles'] as $entry) {
            $this->assertNotSame('name', $entry['role']);
            $this->assertNotSame('slot', $entry['role']);
        }

        // A legacy component says NOTHING, so an absent key is never mistaken for
        // "declared empty".
        $this->assertArrayNotHasKey('roles', pp_component_schema_report('hero'));
        $this->assertArrayNotHasKey('udc_groups', pp_component_schema_report('hero'));
    }

    // ── The schema side of the contract ─────────────────────────────────────

    /**
     * ROLE DEFAULTS GET THE SAME SCRUTINY AS AUTHOR VALUES.
     *
     * This is the gap the repricing leaned on without checking. Several existing
     * matrices dropped testimonials on the stated grounds that "the value is now a
     * role default" — and role defaults were the one part of the new system
     * nothing validated. `_pp_udc_place()` returns silently on an unknown param
     * name and continues silently on an unresolvable `@reference`, so a typo in a
     * schema default deletes that declaration from every band of that component
     * with no error anywhere. Proven by mutation: renaming `quote.defaults.
     * typography.size` to `sizee` removed the quote's entire default font-size and
     * left both suites green.
     *
     * A CI invariant rather than a runtime gate, matching how the repo already
     * treats schema conformance (pp_schema_definition_errors is explicitly
     * "a repo-CI invariant, not a runtime gate"): a shipped schema is repo-
     * controlled, so the place to catch a typo is the build, not the page view.
     */
    public function testEveryV2SchemaDefaultIsAValueTheEngineWouldAccept(): void
    {
        $groups  = pp_udc_groups();
        $checked = 0;

        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $roles     = pp_udc_component_roles($component);
            if ($roles === []) {
                continue;
            }

            foreach ($roles as $role => $definition) {
                $permitted = $definition['groups'] ?? [];
                foreach (($definition['defaults'] ?? []) as $group => $params) {
                    $this->assertArrayHasKey($group, $groups, "{$component}.{$role} defaults an unknown group {$group}");
                    $this->assertContains(
                        $group,
                        $permitted,
                        "{$component}.{$role} defaults the group {$group}, which the role does not permit"
                    );

                    foreach ($params as $param => $value) {
                        $this->assertArrayHasKey(
                            $param,
                            $groups[$group]['params'],
                            "{$component}.{$role}.{$group} defaults an unknown parameter {$param} — it would vanish silently"
                        );
                        $spec    = $groups[$group]['params'][$param];
                        $perBp   = is_array($value) ? $value : ['d' => $value];

                        foreach ($perBp as $bp => $literal) {
                            $this->assertArrayHasKey(
                                $bp,
                                pp_udc_breakpoints(),
                                "{$component}.{$role}.{$group}.{$param} defaults an unknown breakpoint {$bp}"
                            );
                            $literal = (string) $literal;

                            $ref = pp_udc_parse_reference($literal);
                            if ($ref !== null) {
                                // `true`: a schema default may also reference the shared
                                // design-system properties (band rhythm, heading scale)
                                // that base.css keeps out of the authorable registry.
                                $resolved = pp_udc_resolve_reference($ref, [], true);
                                $this->assertNotNull(
                                    $resolved,
                                    "{$component}.{$role}.{$group}.{$param} references @{$ref}, which resolves to neither a registered design token nor a shared :root property — the declaration would silently vanish"
                                );
                                $literal = $resolved['value'];
                            }

                            $this->assertTrue(
                                pp_udc_validate_value($literal, $spec) === true,
                                "{$component}.{$role}.{$group}.{$param} defaults \"{$literal}\", which its own grammar refuses"
                            );
                            $checked++;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'the sweep must actually reach the shipped defaults');
    }

    /**
     * THE OTHER HALF OF THE JOIN.
     *
     * A role maps to a selector; the component renders markup. If the two drift,
     * the engine ships a band block whose rules match no element on the page —
     * CSS generated, shipped, and inert. The attribute guard above checks that
     * the band identity reaches the template; this checks that the SELECTORS do.
     * Proven necessary by mutation: renaming `roles.quote.selector` to
     * `.testimonials__quotation` left both suites green.
     */
    public function testEveryRoleSelectorMatchesAnElementTheComponentActuallyRenders(): void
    {
        $fixtures = [
            'testimonials' => [
                'title'        => 'What they say',
                'title_accent' => 'they',
                'eyebrow'      => 'PROOF',
                'subheading'   => 'A supporting line',
                'items'        => [[
                    'quote'     => 'Great work.',
                    'author'    => 'Ada Lovelace',
                    'role'      => 'CTO',
                    'company'   => 'Analytical',
                    'image_url' => '/wp-content/uploads/ada.png',
                    'image_alt' => 'Ada Lovelace',
                ]],
            ],
        ];

        $checked = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $roles     = pp_udc_component_roles($component);
            if ($roles === []) {
                continue;
            }
            $this->assertArrayHasKey(
                $component,
                $fixtures,
                "{$component} is a v2 component with no fixture here — add one so its selectors are checked"
            );

            ob_start();
            try {
                pp_get_component($component, $fixtures[$component]);
            } finally {
                $html = ob_get_clean();
            }

            foreach ($roles as $role => $definition) {
                $selector = (string) ($definition['selector'] ?? '');
                if ($selector === '') {
                    continue; // `_band` is the band element itself.
                }
                // Every class the selector names must exist in the rendered markup.
                preg_match_all('/\.([A-Za-z0-9_-]+)/', $selector, $m);
                $this->assertNotEmpty($m[1], "{$component}.{$role} has a selector with no class to match");
                foreach ($m[1] as $class) {
                    $this->assertMatchesRegularExpression(
                        '/class="[^"]*\b' . preg_quote($class, '/') . '\b/',
                        $html,
                        "{$component}.{$role} maps to .{$class}, which the component never renders — "
                        . 'its band block would ship into the head and match nothing'
                    );
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(5, $checked, 'the sweep must actually reach the shipped roles');
    }

    // ── Band identity, clause by clause ─────────────────────────────────────

    /**
     * The mint/carry-forward algorithm, driven directly.
     *
     * Its docblock says it is "stated tightly because it has to be testable", and
     * then nothing tested it: two of its four clauses survived deletion with the
     * suite green. The one that matters most is the claim check — without it the
     * writer can carry a stored id onto a band while another incoming band already
     * holds it, producing two bands with one id and the cross-band design bleed
     * every other test in this file exists to prevent. It arrives through the MINT
     * path, which validation cannot see: `duplicate_band_id` runs before assignment.
     */
    public function testBandIdAssignmentHonoursEveryClauseOfItsAlgorithm(): void
    {
        $band = static fn (string $component, ?string $id = null): array => $id === null
            ? ['component' => $component, 'props' => []]
            : ['component' => $component, 'id' => $id, 'props' => []];

        // 1. An authored, valid id is honoured, never overwritten.
        $out = pp_udc_assign_band_ids([$band('testimonials', 'brand-quote')]);
        $this->assertSame('brand-quote', $out[0]['id']);

        // 2. Carried forward on index + component match.
        $stored = [$band('testimonials', 'pp-11111111')];
        $out    = pp_udc_assign_band_ids([$band('testimonials')], $stored);
        $this->assertSame('pp-11111111', $out[0]['id'], 'same index, same component: carry it');

        // 3. NOT carried when the component at that index differs.
        $out = pp_udc_assign_band_ids([$band('testimonials')], [$band('hero', 'pp-22222222')]);
        $this->assertNotSame('pp-22222222', $out[0]['id'], 'a different component must not inherit the id');
        $this->assertTrue(pp_udc_valid_band_id($out[0]['id']));

        // 4. NOT carried when another incoming band already claims it — the clause
        //    whose deletion produces two bands sharing one scope.
        $out = pp_udc_assign_band_ids(
            [$band('testimonials', 'pp-33333333'), $band('testimonials')],
            [$band('testimonials', 'pp-44444444'), $band('testimonials', 'pp-33333333')]
        );
        $this->assertSame('pp-33333333', $out[0]['id']);
        $this->assertNotSame('pp-33333333', $out[1]['id'], 'a claimed id must not be carried onto a second band');

        // 5. A legacy component is never minted one at all.
        $out = pp_udc_assign_band_ids([$band('hero')]);
        $this->assertArrayNotHasKey('id', $out[0]);

        // 6. THE POST-CONDITION, which is what all of the above is for.
        //
        // MEASURED, because the reviewer who asked for this test predicted that
        // deleting the claim check in clause 4 would "produce two bands with one
        // id", and it does not. The claim check and the `while (isset($claimed…))`
        // regeneration loop are REDUNDANT with each other: removing either one
        // alone still yields unique ids (both mutations verified green in a scratch
        // copy), and removing BOTH is what this assertion catches. So the claim
        // check earns its place by making the carry decision explicit rather than
        // leaning on a backstop — not by being the thing that prevents collisions.
        // The invariant below is the one worth pinning, and it is pinned here.
        $out = pp_udc_assign_band_ids(array_fill(0, 8, $band('testimonials')));
        $ids = array_column($out, 'id');
        $this->assertCount(8, $ids);
        $this->assertSame($ids, array_unique($ids), 'no two bands may leave the writer sharing an id');

        // Including when stored ids and authored ids both compete for the same value.
        $out = pp_udc_assign_band_ids(
            [$band('testimonials', 'pp-55555555'), $band('testimonials'), $band('testimonials')],
            [$band('testimonials', 'pp-55555555'), $band('testimonials', 'pp-55555555'), $band('testimonials', 'pp-55555555')]
        );
        $ids = array_column($out, 'id');
        $this->assertSame($ids, array_unique($ids), 'a stored id claimed three ways still yields three distinct bands');
    }

    // ── Write-gate refusals that had no pin ─────────────────────────────────

    /**
     * The write-side `_tokens` gates, each proven independently of the emit-side
     * backstop. Without these, deleting a write gate leaves a write reported
     * successful that changes nothing — the accepted-but-dead class, arriving
     * through the surface this engine just built.
     */
    public function testTheWriteGateRefusesMalformedTokensOnItsOwn(): void
    {
        $cases = [
            'name carrying CSS syntax' => ['x}body{display:none;--y' => '#fff'],
            'name over 64 characters'  => [str_repeat('a', 65) => '#fff'],
            'non-scalar value'         => ['x' => ['#fff']],
        ];
        foreach ($cases as $label => $tokens) {
            $error = pp_udc_validate_map(['_tokens' => $tokens], 'testimonials');
            $this->assertInstanceOf(WP_Error::class, $error, "{$label} must be refused at write");
        }

        // And an empty value on a parameter.
        $empty = pp_udc_validate_map(['quote' => ['typography' => ['size' => '']]], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $empty);
    }

    // ── What the authoring model is actually told ───────────────────────────

    /**
     * v1's deepest documentation defect, closed and pinned.
     *
     * The runtime system prompt told the model which types reject `var()` and
     * which keywords are refused, and NEVER ONCE stated which units were legal.
     * The model learned the unit set from rejection messages, one refusal at a
     * time — which is how `ch` came to be refused from every authoring surface
     * while the theme's own base.css shipped `--measure-body: 70ch`.
     *
     * Derived from the one owner, so the prompt cannot drift from the validator.
     */
    public function testTheRuntimePromptStatesTheAcceptedGrammarExplicitly(): void
    {
        $prompt = pp_ai_system_prompt();

        foreach (pp_css_length_units() as $unit) {
            $this->assertStringContainsString(
                $unit,
                $prompt,
                "the model must be TOLD that {$unit} is accepted, not left to discover it from a refusal"
            );
        }

        // The UDC vocabulary reaches the model too — a surface it is not told about
        // is a surface it will correctly report as impossible.
        $this->assertStringContainsString('Universal Design Contract', $prompt);
        $this->assertStringContainsString('"@token-name"', $prompt, 'the reference syntax');
        $this->assertStringContainsString('unknown_udc_role', $prompt, 'and what a refusal will say');
        foreach (array_keys(pp_udc_groups()) as $group) {
            $this->assertStringContainsString($group, $prompt);
        }
    }

    /**
     * A v2 component has no `theme` prop, so the model has to be taught how to say
     * "dark band" in the new language — AND told that contrast is now its job.
     * The standing rule is that colour fixes belong in the values an author
     * chooses, never baked into component CSS, which means the obligation has to
     * travel with the instruction that creates it.
     */
    public function testTheModelIsTaughtTheDarkBandExpressionAndOwnsItsContrast(): void
    {
        $prompt = pp_ai_system_prompt();

        $this->assertStringContainsString('A DARK BAND', $prompt);
        $this->assertStringContainsString('there is no `theme` prop', $prompt);
        $this->assertStringContainsString('YOU OWN THE CONTRAST', $prompt);
        $this->assertStringContainsString('4.5:1', $prompt, 'the WCAG AA threshold, stated as a number');
        // Every text role must be named, or the instruction leaves a gap that
        // renders as dark ink on a dark band.
        foreach (['quote', 'author', 'meta', 'heading', 'subheading', 'eyebrow'] as $role) {
            $this->assertStringContainsString($role, $prompt);
        }
    }

    public function testTheEngineIsInertForEveryComponentStillOnTheLegacySystem(): void
    {
        $legacy = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $name = basename(dirname($file));
            if (!pp_udc_is_v2_component($name)) {
                $legacy[] = $name;
            }
        }
        $this->assertGreaterThanOrEqual(11, count($legacy), 'eleven components stay on the legacy system');
        $this->assertNotContains('testimonials', $legacy);

        foreach ($legacy as $name) {
            $this->assertSame([], pp_udc_component_roles($name));
            $this->assertSame('', pp_udc_band_css(['component' => $name, 'id' => 'pp-aabbccdd']));
        }
    }
}
