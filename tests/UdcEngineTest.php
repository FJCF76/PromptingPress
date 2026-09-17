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
     * THE ROLE-SELECTOR CHARSET GATE, WIDENED BY ONE CHARACTER AND NO MORE (#994, D4).
     *
     * A role selector becomes CSS SOURCE TEXT, so lib/udc.php gates it on a charset
     * even though schemas are repo-owned and integrity-checked. A violating selector
     * is SILENTLY SKIPPED — the role emits nothing — which is the right failure for a
     * theme bug an operator cannot act on, and the wrong failure to leave untested:
     * silence is what a deleted gate looks like too.
     *
     * `>` joined the charset so `link-current` could say `li.current-menu-item > a`,
     * the child form the retired CSS used; the descendant form would have painted every
     * link in a current parent's dropdown. This proves the widening landed AND that it
     * widened nothing else — the exclusions are the gate.
     *
     * THE ANCHORS ARE TESTED SEPARATELY FROM THE CLASS, deliberately. `^...$` with no
     * `/m` is what makes the charset a whole-string claim; a class widening cannot
     * break it, but a careless rewrite of the pattern could, and a trailing-newline
     * payload is the classic way that shows up.
     */
    public function testTheRoleSelectorCharsetAdmitsTheChildCombinatorAndNothingElse(): void
    {
        $accepted = [
            '.nav__menu ul li.current-menu-item > a',  // the #994 widening
            '.site-footer__nav ul',
            '.testimonials__quote',
            '.a-b_c.d e > f',
        ];
        $refused = [
            '.a, .b',                    // a selector LIST — one role owning two surfaces
            '.a[data-x="y"]',            // an attribute match
            '.a:hover',                  // a pseudo-class; states are a separate dimension
            '.a{color:red}.b',           // a closed rule and a second selector
            ".a\n.b",                    // a newline with content after it
            "a\n",                       // THE ANCHOR CASE, and the one `$` would have
                                         // accepted: PCRE's `$` matches before a final
                                         // newline, so only `\z` refuses this. The
                                         // case above passes under BOTH anchors, which
                                         // is why it never proved the claim it sat under.
            '.a > b; .c',
            str_repeat('.x', 61),        // over the 120-character bound
        ];

        foreach ($accepted as $selector) {
            $this->assertSame(
                1,
                preg_match('/^[A-Za-z0-9_ .>\-]{1,120}\z/', $selector),
                "the charset must accept {$selector}"
            );
        }
        foreach ($refused as $selector) {
            $this->assertSame(
                0,
                preg_match('/^[A-Za-z0-9_ .>\-]{1,120}\z/', $selector),
                'the charset must refuse ' . json_encode($selector)
            );
        }

        // AND THE GATE IS STILL WIRED TO THAT PATTERN. Asserting the regex in isolation
        // proves a string, not a behaviour: the source pin is what fails if someone
        // relaxes the live gate while this test keeps passing on a copy.
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            "preg_match('/^[A-Za-z0-9_ .>\\-]{1,120}\\z/', \$selector)",
            $source,
            'the compile-time selector gate must use exactly this pattern, anchors included'
        );
    }

    /**
     * EVERY SHIPPED ROLE SELECTOR IS A WELL-FORMED SELECTOR, not merely a permitted
     * string.
     *
     * The compile-time gate bounds the CHARACTER SET and says nothing about shape, so
     * `> a`, `a >` and `a >> b` all clear it and are all invalid CSS. That is not a
     * cosmetic problem: _pp_udc_reduced_motion_guard() groups every motion-carrying
     * role selector into ONE comma-separated rule, and CSS discards an entire grouped
     * rule when any selector in the list is invalid — so a single malformed role
     * selector silently removes the engine's `prefers-reduced-motion` guard from every
     * role in that scope. An accessibility guarantee, lost with no error anywhere.
     *
     * The input is repo-controlled, so this is a theme bug rather than an attack, and
     * the right place to catch a theme bug is CI. Pinned as a SHAPE check over what is
     * actually shipped rather than as a new runtime refusal, which would be a widening
     * of the write contract nobody ruled on.
     */
    public function testEveryShippedRoleSelectorIsAWellFormedSelector(): void
    {
        $checked = 0;

        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                $selector = trim((string) ($definition['selector'] ?? ''));
                if ($selector === '') {
                    continue; // `_band` addresses the root and carries no selector.
                }
                $checked++;

                // A combinator needs a simple selector on BOTH sides, and two in a row
                // is never valid. Whitespace around `>` is legal and normalised first so
                // the check is about structure, not formatting.
                $normalised = preg_replace('/\s*>\s*/', '>', $selector);
                $this->assertDoesNotMatchRegularExpression(
                    '/(^>|>$|>>)/',
                    $normalised,
                    "{$component}.{$role} declares \"{$selector}\", which passes the charset gate "
                    . 'but is not a valid selector — it would invalidate the grouped '
                    . 'prefers-reduced-motion rule for every role in its scope'
                );

                // And no empty compound between descendant combinators either.
                foreach (explode(' ', $normalised) as $part) {
                    $this->assertNotSame('', trim($part), "{$component}.{$role} has an empty compound");
                }
            }
        }

        $this->assertGreaterThan(30, $checked, 'the sweep must actually reach the shipped selectors');
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

            $compiled = pp_udc_compile_band($item, 'all');
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

        $compiled = pp_udc_compile_band($item, 'all');
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

    // ── The same gate, on the door the author actually uses ──────────────────

    /**
     * THE AUTHORED HALF OF THE SAME HAZARD, which the guard did not cover.
     *
     * `_pp_udc_delimiters_balanced()` was called from one place — the `_tokens`
     * loop — so a value submitted as a PARAMETER never met it. It met only the
     * shared reject set (which bans `{ } ; < >` and says nothing about an
     * unbalanced `(`) and its parameter's grammar, and `font-family`'s grammar
     * accepted any non-empty string. `rgb(` therefore validated, stored, and
     * emitted into the band's block as CSS source text.
     *
     * These are the review's own vectors, built here rather than pasted so the
     * shape of each one is visible: an odd quote count, an open paren, and the
     * open paren that also looks like a function.
     */
    public function testAnAuthoredValueWithAnUnbalancedDelimiterIsRefusedAtWrite(): void
    {
        $vectors = [
            'odd double quote' => 'Foo' . '"' . 'Bar',
            'open paren'       => 'Foo' . '(',
            'function-shaped'  => 'rgb' . '(',
            'odd apostrophe'   => 'Foo' . "'" . 'Bar',
        ];

        foreach ($vectors as $label => $payload) {
            $error = pp_udc_validate_map(
                ['quote' => ['typography' => ['family' => $payload]]],
                'testimonials'
            );
            $this->assertInstanceOf(WP_Error::class, $error, "{$label}: must be refused");
            $this->assertSame('invalid_prop_value', $error->get_error_code(), $label);
            // The message has to name the parameter, or the author cannot act on it.
            $this->assertStringContainsString('family', $error->get_error_message(), $label);
            // AND IT HAS TO BE THE GATE THAT REFUSED, not the family grammar.
            //
            // Both now refuse these vectors, so "it was refused" proves nothing
            // about the fix this issue is about: deleting the gate call from
            // pp_udc_validate_value() leaves the whole suite green, because
            // `font-family` falls through to the tightened _pp_validate_font_family()
            // which independently rejects all four. The gate runs FIRST and its
            // message is the only one that says this, so asserting the message is
            // what makes this test go red when the gate is removed.
            $this->assertStringContainsString(
                'swallow every declaration',
                $error->get_error_message(),
                "{$label}: the delimiter gate must be what refuses, not the per-type grammar"
            );
        }
    }

    /**
     * THE SAME REFUSAL THROUGH THE REAL AUTHORING SURFACE (rule 14.1).
     *
     * Everything above calls pp_udc_validate_map() directly, one hop below where
     * an author actually writes. That hop matters: lib/admin.php wraps a udc
     * refusal through `_pp_claim_item_finding()`, and whether a finding BLOCKS a
     * write or is merely reported depends on the sink — `restore_composition`
     * reports without blocking (#233). So a direct-validator test cannot tell
     * "refused" from "stored with a finding attached", which is the difference
     * the whole gate exists to make.
     *
     * This one goes through `create_page`, the surface the authoring model uses.
     */
    public function testAnUnbalancedAuthoredValueIsRefusedThroughTheRealWriteSurface(): void
    {
        $result = pp_validate_action('create_page', [
            'title'       => 'Hostile family',
            'composition' => [$this->band(['quote' => ['typography' => ['family' => 'rgb' . '(']]])],
        ]);

        $this->assertInstanceOf(WP_Error::class, $result, 'the real write surface must refuse it');
        $this->assertSame('invalid_prop_value', $result->get_error_code());

        $message = $result->get_error_message();
        // Locates the failure for the author: which band, which role, which
        // group, which parameter — and that the delimiter gate is what fired.
        $this->assertStringContainsString('testimonials', $message);
        $this->assertStringContainsString('quote', $message);
        $this->assertStringContainsString('typography', $message);
        $this->assertStringContainsString('family', $message);
        $this->assertStringContainsString('swallow every declaration', $message);
    }

    /**
     * And the second layer, for data that never passed the write gate — a raw
     * meta write, a row written before this rule, or restore_composition, which
     * reports findings without blocking (#233).
     *
     * The declaration drops; the band keeps painting. A guard that blanked the
     * whole block would turn one bad value into a dead page, which is the
     * failure the #330 render boundary was shaped to avoid.
     */
    public function testAStoredAuthoredValueWithAnUnbalancedDelimiterCannotSwallowTheStylesheet(): void
    {
        foreach (['Foo' . '"' . 'Bar', 'Foo' . '(', 'rgb' . '('] as $payload) {
            $css = pp_udc_page_authored_css([[
                'component' => 'testimonials',
                'id'        => 'pp-aabbccdd',
                'props'     => [],
                'udc'       => ['quote' => ['typography' => [
                    'family' => $payload,
                    'style'  => 'italic',
                ]]],
            ]]);

            $this->assertStringNotContainsString('font-family', $css, "{$payload}: must not be emitted");
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), "{$payload}: braces balanced");
            $this->assertSame(substr_count($css, '('), substr_count($css, ')'), "{$payload}: parens balanced");
            $this->assertSame(0, substr_count($css, '"') % 2, "{$payload}: quotes balanced");
            // The sibling declaration in the same group still paints.
            $this->assertStringContainsString('font-style:italic', $css, "{$payload}: siblings survive");
        }
    }

    /**
     * THE GATE MUST COST NOTHING THAT THE GRAMMARS ALREADY ALLOW.
     *
     * A balance check placed ahead of every typed grammar is only safe if no
     * value those grammars accept is unbalanced — otherwise it silently narrows
     * the accepted set of every parameter at once, which is the opposite of the
     * defect it was added for. Every well-formed CSS value IS balanced, so this
     * should hold by construction; asserting it converts "should" into a pin
     * that fails the day someone adds a grammar with a string literal in it.
     *
     * The corpus is the shapes the unified grammar actually accepts, one per
     * family, including the function-bearing ones where the risk would live.
     */
    public function testTheDelimiterGateRefusesNothingTheGrammarsAccept(): void
    {
        // Addressed by ROLE.GROUP.PARAM so each value goes through the parameter
        // that really carries it. Asserting against _pp_udc_delimiters_balanced()
        // directly would decouple this from the grammars it claims to speak for:
        // a hand-written literal proves only that the literal is balanced, while
        // pp_udc_validate_value() proves the gate and the grammar agree — which
        // is the property that matters, since the gate now runs ahead of every
        // grammar and can veto any of them.
        $groups = pp_udc_groups();
        $corpus = [
            ['typography', 'size',           ['19px', '0', '1.5rem', '70ch', '100%', '4vmin',
                                              'calc(100% - 2rem)', 'clamp(1rem, 2vw, 3rem)']],
            ['typography', 'letter-spacing', ['-0.02em', '0']],
            ['typography', 'color',          ['#fff', '#ffffff', 'rgb(255, 0, 0)', 'rgba(0, 0, 0, 0.55)',
                                              'hsl(120, 50%, 50%)', 'transparent', 'currentColor']],
            ['typography', 'family',         ['system-ui, sans-serif', '"Helvetica Neue", Helvetica, sans-serif',
                                              "ui-monospace, 'Cascadia Code', monospace", 'var(--font-heading)']],
            ['typography', 'style',          ['italic', 'normal']],
            ['typography', 'weight',         ['600', 'bold']],
            ['typography', 'line-height',    ['1.6']],
            ['typography', 'transform',      ['uppercase']],
            ['typography', 'align',          ['center']],
            ['typography', 'wrap',           ['balance']],
            ['typography', 'decoration',     ['underline']],
            ['spacing',    'margin',         ['0 auto']],
            ['spacing',    'padding',        ['1rem 2rem 3rem 4rem']],
            // The two remaining FUNCTION-BEARING types, which is exactly where a
            // paren-counting gate could plausibly bite.
            ['shadow',     'box',            ['0 4px 12px rgba(0, 0, 0, 0.1)', 'none', 'var(--shadow-md)']],
            ['background', 'fill',           ['#ffffff', 'transparent',
                                              'linear-gradient(135deg, #fff, #000)',
                                              'radial-gradient(circle at top left, #fff, #000)']],
        ];

        $checked = 0;
        foreach ($corpus as [$group, $param, $values]) {
            $spec = $groups[$group]['params'][$param] ?? null;
            $this->assertNotNull($spec, "the corpus names a real parameter: {$group}.{$param}");
            foreach ($values as $value) {
                $checked++;
                $this->assertTrue(
                    pp_udc_validate_value($value, $spec) === true,
                    "{$group}.{$param}: the engine must still accept {$value}"
                );
            }
        }
        $this->assertGreaterThan(30, $checked, 'the corpus must actually be exercised');
    }

    public function testEveryEmittedDeclarationKnowsWhichLayerProducedIt(): void
    {
        $compiled = pp_udc_compile_band($this->band([
            'quote' => ['typography' => ['size' => '19px']],
        ]), 'all');

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
        // `section`, not hero: hero joined the UDC in #986, so asking it this question
        // now tests the opposite of what the name promises (I40). section is the largest
        // component still on the v1 slot system.
        $error = pp_udc_validate_map(['quote' => ['typography' => ['size' => '1rem']]], 'section');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertStringContainsString('not on the UDC styling system', $error->get_error_message());

        // …and a legacy component emits no band block, whatever it stores.
        $this->assertSame('', pp_udc_band_css([
            'component' => 'section',
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

            // CHROME SCOPES ON A DIFFERENT ATTRIBUTE, AND FAILS THE SAME WAY.
            //
            // nav and footer are template-owned: they are never composed, so there
            // is no band and no id to carry — their blocks scope to
            // `[data-pp-chrome="<name>"]`, a template CONSTANT. The failure this
            // lint exists to catch is identical either way (CSS generated, shipped
            // in the head, matching nothing), so chrome is checked rather than
            // exempted — just against the attribute it actually uses.
            if (pp_udc_is_chrome($name)) {
                $this->assertStringContainsString(
                    'data-pp-chrome="' . $name . '"',
                    $template,
                    "{$name} declares roles but never emits data-pp-chrome — its blocks would match nothing"
                );
                $this->assertStringNotContainsString(
                    'data-pp-band',
                    $template,
                    "{$name} is chrome, not a band: a band attribute here would scope its CSS to an id it can never have"
                );
                // Chrome's OWN inline-style escape hatch is gone too. It used to
                // build one with pp_chrome_style_attr() from the pp_header_* /
                // pp_footer_* options; an inline style attribute outranks every
                // stylesheet, so leaving that path open beside the UDC would put
                // chrome back outside the cascade the engine exists to provide.
                $this->assertStringNotContainsString(
                    'pp_chrome_style_attr',
                    $template,
                    "{$name} is on the UDC now: an inline chrome style attribute would outrank its own band block"
                );
                $this->assertStringNotContainsString(
                    '$style_attr',
                    $template,
                    "{$name} must emit no style attribute — the scoped block is the only styling source"
                );
                continue;
            }

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
        $this->assertArrayNotHasKey('roles', pp_component_schema_report('section'));
        $this->assertArrayNotHasKey('udc_groups', pp_component_schema_report('section'));
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
    /**
     * The three-tier cascade, pinned at the emitter.
     *
     * Which tier a UDC rule lands in is carried by two things and nothing else:
     * the specificity its scope contributes, and where the layer prints. Both are
     * easy to undo by accident and neither is visible in the rendered page until a
     * brand does not render, so both are pinned here as well as in the browser.
     *
     * The history is the argument for this test. The shared adjacent-band rhythm
     * rule first outranked authored band values (an authored padding-top rendered
     * the shared value instead); flattening that rule then let the DEFAULTS layer
     * outrank it, and an unauthored band fell out of the shared rhythm rulings.
     * Both were silent. The tiers only work as a set.
     */
    public function testTheDefaultsLayerCarriesNoScopeSpecificityAndIsSeparableFromAuthoredValues(): void
    {
        $defaults = pp_udc_component_defaults_css('testimonials');
        $this->assertNotSame('', $defaults, 'the defaults layer must not be empty, or this pins nothing');

        // A BAND-ROOT default carries zero specificity: the design system's
        // contextual band rules aim at the same element and must win over it, or
        // an unauthored band falls out of the #430/#431 rhythm rulings.
        $this->assertMatchesRegularExpression(
            '/:where\(\[data-pp-component="testimonials"\]\)\s*\{[^}]*padding-top:/',
            $defaults,
            'the band root emits inside :where(), so it yields to the shared rhythm'
        );

        // An ELEMENT default keeps the full scope weight — [0,2,0]. Nothing in the
        // design system aims at these elements, but ordinary structural CSS does,
        // including rules like base.css's `p:last-child` [0,1,1] that zeroed the
        // subheading rhythm in #336. Zeroing this scope reintroduces that bug.
        $this->assertStringContainsString(
            '[data-pp-component="testimonials"] .testimonials__heading{',
            $defaults
        );
        $this->assertStringNotContainsString(
            ':where([data-pp-component="testimonials"]) .testimonials__',
            $defaults,
            'element defaults must NOT be wrapped — at [0,1,0] they lose to p:last-child [0,1,1]'
        );

        // The two layers are separately obtainable, because they print in different
        // places: defaults before the theme stylesheets, authored after. A single
        // combined emitter could not express that, and the ordering is the only
        // thing separating two zero-specificity layers.
        $band = [
            'component' => 'testimonials',
            'id'        => 'pp-aabbccdd',
            'props'     => [],
            'udc'       => ['_band' => ['spacing' => ['padding-top' => '5px']]],
        ];
        $authored = pp_udc_page_authored_css([$band]);
        $this->assertStringContainsString('[data-pp-band="pp-aabbccdd"]', $authored);
        $this->assertStringNotContainsString(
            'data-pp-component',
            $authored,
            'the authored layer carries no defaults — it would print on the wrong side of the stylesheets'
        );
        $this->assertSame($defaults, pp_udc_page_defaults_css([$band]));
        $this->assertSame(pp_udc_page_css([$band]), $defaults . $authored);
    }

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

                    // STATE SUB-MAPS ARE FLATTENED IN, not skipped (#994).
                    //
                    // A group map may carry a `:hover` / `:focus-visible` / `:active`
                    // sibling of its params, and chrome's retirement is the first place
                    // a SCHEMA DEFAULT uses one — nav's `logo`, `link` and `toggle` all
                    // ship the accent hover that used to live in components.css. This
                    // sweep walked group keys as if they were all params, so a state
                    // map read as a parameter named ":hover" and failed with "unknown
                    // parameter — it would vanish silently", which was exactly backwards:
                    // the ENGINE emits it correctly (verified in the rendered defaults
                    // block), and the SWEEP was the thing that could not see it.
                    //
                    // Flattening rather than special-casing means a state's values get
                    // the identical grammar, reference and breakpoint checks the base
                    // state's do — a `:hover` colour that no grammar accepts has to fail
                    // here, not at render time.
                    $states = pp_udc_states();
                    $flat   = [];
                    foreach ($params as $key => $value) {
                        if (isset($states[$key])) {
                            $this->assertIsArray(
                                $value,
                                "{$component}.{$role}.{$group}.{$key} must be a map of parameters"
                            );
                            foreach ($value as $stateParam => $stateValue) {
                                $flat["{$key} {$stateParam}"] = [$stateParam, $stateValue];
                            }
                            continue;
                        }
                        $flat[$key] = [$key, $value];
                    }

                    foreach ($flat as $where => [$param, $value]) {
                        $this->assertArrayHasKey(
                            $param,
                            $groups[$group]['params'],
                            "{$component}.{$role}.{$group} defaults an unknown parameter {$where} — it would vanish silently"
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
                                // THE SAME PREDICATE THE ENGINE USES, not a second
                                // opinion (#986). A reference is judged by the token's
                                // DECLARED registry type (#972, ruling D3) — which is
                                // what lets `@btn-padding-y` stand in a length
                                // parameter even though its VALUE is `var(--space-sm)`
                                // and the length grammar is literal-only on purpose.
                                // Re-parsing the resolved text here asked the question
                                // the ruling retired, and would have forbidden schema
                                // defaults that the write gate and the emitter both
                                // accept — including the ones the button presets
                                // already ship.
                                $check = _pp_udc_reference_check($resolved, $spec);
                                $this->assertTrue(
                                    $check === true,
                                    "{$component}.{$role}.{$group}.{$param} references @{$ref}, which the engine refuses here: "
                                    . ($check === true ? '' : $check->get_error_message())
                                );
                                $checked++;
                                continue;
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
            // CHROME FIXTURES MUST TURN EVERYTHING ON. Almost every footer block
            // is optional and renders only when its content prop is set, so a
            // sparse fixture would silently skip most of the role sweep and this
            // lint would pass while declaring selectors nobody had checked.
            // HERO NEEDS THREE FIXTURES, and it is the first component that does.
            // Three of its roles are mutually exclusive in one render: `proof` only
            // renders on a NON-split layout, while `surface` is what the same `proof`
            // markup becomes on split, and `media` is the split column `surface` takes
            // over when proof is present. One prop set can therefore never reach all
            // thirteen, and two cannot either. The list form below renders each of the
            // three and checks every role against their union — which keeps the lint's promise (no role goes
            // unchecked) instead of quietly dropping the three it cannot reach.
            'hero' => [
                [
                    'title'        => 'Ship it',
                    'title_accent' => 'it',
                    'eyebrow'      => 'NEW',
                    'subheading'   => 'A supporting line.',
                    'button_text'  => 'Start',
                    'button_url'   => '#a',
                    'button2_text' => 'Docs',
                    'button2_url'  => '#b',
                    'layout'       => 'centered',
                    'proof'        => '<p>Trusted by teams</p>',
                ],
                [
                    'title'     => 'Ship it',
                    'layout'    => 'split',
                    'image_url' => '/wp-content/uploads/hero.png',
                    'image_alt' => 'Hero',
                ],
                [
                    'title'  => 'Ship it',
                    'layout' => 'split',
                    'proof'  => '<p>Workflow</p>',
                ],
            ],
            'nav' => [
                'location' => 'primary',
            ],
            'footer' => [
                'location'      => 'footer',
                'show_logo'     => true,
                'blurb'         => 'We build things.',
                'contact'       => "hello@example.com\n+34 600 000 000",
                'copyright'     => '© Example',
                'menu_label'    => 'Company',
                'contact_label' => 'Contact',
                'note'          => 'All rights reserved.',
                'social'        => '[{"network":"x","url":"https://x.com/example"}]',
            ],
        ];

        // CLASSES WORDPRESS EMITS, NOT THE COMPONENT.
        //
        // Chrome renders its menus through wp_nav_menu(), so the <ul>/<li>/<a>
        // tree and its state classes come from core's walker, not from nav.php.
        // A role targeting one of those is still a real selector against real
        // markup — the template simply is not where it can be read. Enumerated
        // here rather than skipped by a wildcard so that adding a role on a class
        // NOBODY emits still fails, which is the whole point of this lint.
        // THE ALLOWLIST IS EMPTY SINCE #994, and the emptiness is the point.
        //
        // It held `current-menu-item` and `sub-menu` because the harness's wp_nav_menu
        // stub emitted a single flat `<ul><li><a>` and could not show them, so the two
        // classes had to be taken on trust — which made `link-current` and `submenu`
        // the two chrome role selectors this lint could never fail on. They are also
        // the two the retirement leans on hardest.
        //
        // tests/bootstrap.php now drives the THEME's own walker over a two-level
        // fixture that marks a current item and nests a sub-menu, so both classes are
        // in the rendered markup like any other and are checked like any other. Kept
        // as an empty list rather than deleted so the next reader sees that the bypass
        // was closed deliberately, not that it never existed.
        $emittedByWordPress = [];

        // A resolvable logo, so the chrome fixtures render the IMAGE branch of the
        // logo (.nav__logo-image) rather than the wordmark fallback. Without it
        // that role's selector would go unchecked, which is the silent-skip this
        // lint exists to prevent.
        $GLOBALS['_pp_test_store']['posts'][7]                 = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][7]   = true;
        $GLOBALS['_pp_test_store']['attachment_urls'][7]       = 'https://example.com/logo.png';
        $GLOBALS['_pp_test_store']['options']['pp_logo_id']    = '7';

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

            // One fixture or several: a list means "render each and check the roles
            // against their union", for a component whose roles cannot all coexist.
            $sets = $fixtures[$component];
            if (!array_is_list($sets)) {
                $sets = [$sets];
            }
            $html = '';
            foreach ($sets as $set) {
                ob_start();
                try {
                    pp_get_component($component, $set);
                } finally {
                    $html .= ob_get_clean();
                }
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
                    if (in_array($class, $emittedByWordPress, true)) {
                        $checked++;
                        continue;
                    }
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
        $out = pp_udc_assign_band_ids([$band('section')]);
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
        // Nine now, not eleven: testimonials was rebuilt in Sprint 0, and nav and
        // footer joined the engine as the CHROME container in Sprint 1 (ruling A1).
        // The number is asserted rather than loosened so that a component quietly
        // falling OFF the engine still trips this.
        $this->assertCount(8, $legacy, 'eight components stay on the legacy system');
        $this->assertNotContains('testimonials', $legacy);
        $this->assertNotContains('nav', $legacy);
        $this->assertNotContains('footer', $legacy);

        foreach ($legacy as $name) {
            $this->assertSame([], pp_udc_component_roles($name));
            $this->assertSame('', pp_udc_band_css(['component' => $name, 'id' => 'pp-aabbccdd']));
        }
    }

    // ── Reference typing (#972, ruling D3) ───────────────────────────────────

    /**
     * ALL FIVE chain-holding tokens, each named explicitly.
     *
     * These are the whole population of shipped tokens whose value is one level of
     * `var()` indirection rather than a literal. Before the ruling the three
     * colours were ACCEPTED and the two lengths REFUSED — same shape of token,
     * opposite answers, decided by whether the referencing param's grammar happened
     * to tolerate a bare `var()`. They are pinned together, by name, because the
     * invariant is that they are treated ALIKE; testing them apart would let the
     * asymmetry come back one token at a time.
     */
    public function testEveryTokenHoldingAVarChainIsReferenceableByItsDeclaredType(): void
    {
        $cases = [
            ['btn-padding-y',     'spacing',    'padding-top'],
            ['btn-padding-x',     'spacing',    'padding-left'],
            ['btn-text',          'typography', 'color'],
            ['text-meta-color',   'typography', 'color'],
            ['text-kicker-color', 'typography', 'color'],
        ];

        foreach ($cases as [$token, $group, $param]) {
            // The premise: each really does hold a chain, not a literal. If a future
            // retune flattens one, this row stops testing what it claims to.
            $resolved = pp_udc_resolve_reference($token, []);
            $this->assertNotNull($resolved, "@{$token} must resolve");
            $this->assertStringContainsString(
                'var(',
                $resolved['value'],
                "@{$token} is only interesting while its value is a var() chain"
            );

            $this->assertNull(
                pp_udc_validate_map(['quote' => [$group => [$param => '@' . $token]]], 'testimonials'),
                "@{$token} must be referenceable from {$group}.{$param} by its declared type"
            );
        }
    }

    /**
     * The mismatch half, on BOTH paths.
     *
     * A literal-valued token keeps its old refusal verbatim, because the ruling
     * only ever moves chain-holders — `@color-accent` in a length parameter was
     * dead CSS before and is refused by the same value parse now. A CHAIN-holding
     * token is the one whose refusal changes shape: there is no literal to quote,
     * so the message names the two types instead.
     */
    public function testAReferenceWhoseTypeCannotSatisfyTheParamIsStillRefused(): void
    {
        // Literal-valued: unchanged path, unchanged message.
        foreach ([['color-accent', 'spacing', 'padding-top'], ['space-sm', 'typography', 'color']] as [$t, $g, $p]) {
            $error = pp_udc_validate_map(['quote' => [$g => [$p => '@' . $t]]], 'testimonials');
            $this->assertInstanceOf(WP_Error::class, $error, "@{$t} must not satisfy {$g}.{$p}");
        }

        // Chain-holding: refused by declared type, and the message says so.
        $error = pp_udc_validate_map(
            ['quote' => ['typography' => ['color' => '@btn-padding-y']]],
            'testimonials'
        );
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('"length"-typed design token', $error->get_error_message());
        $this->assertStringContainsString('takes a "color" value', $error->get_error_message());
    }

    /**
     * THE NON-WIDENING PIN, and the reason the check parses a literal before it
     * consults the type table at all.
     *
     * Five shipped button tokens declare `color`/`shadow` but hold the CSS-wide
     * keyword `initial` as an "unset" sentinel, which their own grammars refuse.
     * A type-first gate would have turned those standing refusals into
     * acceptances — an extension of the accepted surface this ruling did not ask
     * for, and one that would have emitted `var(--btn-bg)` resolving to `initial`.
     */
    public function testASentinelValuedTokenIsStillRefusedDespiteItsDeclaredType(): void
    {
        foreach ([['btn-bg', 'color'], ['btn-hover-bg', 'color'], ['btn-border-color', 'color']] as [$token, $type]) {
            $resolved = pp_udc_resolve_reference($token, []);
            $this->assertNotNull($resolved, "@{$token} must resolve");
            $this->assertSame($type, $resolved['type'], "@{$token} declares {$type}");
            $this->assertSame('initial', trim($resolved['value']), "@{$token} holds the sentinel");

            $this->assertInstanceOf(
                WP_Error::class,
                pp_udc_validate_map(['quote' => ['typography' => ['color' => '@' . $token]]], 'testimonials'),
                "@{$token} must stay refused: a declared type rescues a chain, not a sentinel"
            );
        }
    }

    /**
     * `--transition` is refused for a STATED REASON, which is the ruling's point:
     * it is the registry's only `raw` token (`150ms ease` — a duration and an
     * easing in one string), so it satisfies neither motion param. Before, it was
     * refused by a grammar accident and the author had to infer why from a parse
     * error about time units. Splitting it into two typed tokens would make it
     * referenceable and is its own token-registry ruling (#972 option C).
     */
    public function testTheRawTransitionTokenIsRefusedWithAStatedReason(): void
    {
        foreach (['transition-duration', 'timing-function'] as $param) {
            $error = pp_udc_validate_map(['quote' => ['motion' => [$param => '@transition']]], 'testimonials');
            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertStringContainsString('"raw"-typed design token', $error->get_error_message());
            $this->assertStringContainsString('declares no single CSS grammar', $error->get_error_message());
        }
    }

    /**
     * ONE PREDICATE, BOTH GATES (I29). A reference the write path accepts must
     * emit, and one it refuses must not — otherwise a value validates green at
     * write and silently drops at render, which is the write/render disagreement
     * the diagnostics invariants forbid. This is the regression that would have
     * shipped if only the write gate had been taught the new rule.
     */
    public function testTheWriteGateAndTheEmitterAgreeOnAChainHoldingReference(): void
    {
        $band = $this->band(['quote' => ['spacing' => ['padding-top' => '@btn-padding-y']]]);
        $this->assertNull(pp_udc_validate_map($band['udc'], 'testimonials'), 'accepted at write');
        $this->assertStringContainsString(
            'padding-top:var(--btn-padding-y);',
            pp_udc_band_css(pp_udc_normalize_band($band)),
            'and therefore emitted, not dropped'
        );

        // The refusing direction, through the same two gates.
        $dead = $this->band(['quote' => ['motion' => ['transition-duration' => '@transition']]]);
        $this->assertInstanceOf(WP_Error::class, pp_udc_validate_map($dead['udc'], 'testimonials'));
        $this->assertStringNotContainsString(
            'transition-duration:var(--transition)',
            pp_udc_band_css(pp_udc_normalize_band($dead))
        );
    }

    /**
     * NO BEHAVIOUR CHANGE FOR THE ORDINARY CASE. For every shipped token whose
     * value is a plain literal, judging by declared type and parsing the value
     * give the same answer on every param in the taxonomy. That is what makes this
     * a narrowing of one seam rather than a new grammar: the ~50 literal-valued
     * tokens are unaffected, and only the five chain-holders move.
     */
    public function testDeclaredTypeAndValueParsingAgreeOnEveryLiteralValuedToken(): void
    {
        $params = [];
        foreach (pp_udc_groups() as $group => $definition) {
            foreach ($definition['params'] as $name => $spec) {
                $params[$group . '.' . $name] = $spec;
            }
        }

        $checked = 0;
        foreach (pp_design_tokens() as $name => $definition) {
            $value = is_array($definition) ? ($definition['value'] ?? '') : (string) $definition;
            $type  = is_array($definition) ? (string) ($definition['type'] ?? '') : '';
            if (!is_string($value) || strpos($value, 'var(') !== false) {
                continue; // the five chain-holders are the deliberate exception
            }
            if ($type === 'raw') {
                // `--transition` is the other deliberate exception, and it is
                // STRICTER than the value parse rather than looser: its literal
                // `150ms ease` is accepted by the deliberately-permissive
                // font-family grammar, and the ruling requires it refused for a
                // stated reason instead. Covered by its own test above.
                continue;
            }
            $resolved = pp_udc_resolve_reference(ltrim($name, '-'), []);
            if ($resolved === null) {
                continue;
            }
            foreach ($params as $where => $param) {
                if (($param['type'] ?? '') === 'attachment_id') {
                    continue; // not a CSS grammar; never reference-bearing
                }
                $this->assertSame(
                    pp_udc_validate_value($resolved['value'], $param) === true,
                    _pp_udc_reference_check($resolved, $param) === true,
                    "declared-type and value-parse must agree for {$name} at {$where}"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(500, $checked, 'the sweep must actually cover the registry');
    }

    // ── sizing.aspect-ratio (ruling D1, #986) ────────────────────────────────

    /**
     * THE FORWARD DIRECTION: a valid ratio authors through and paints.
     *
     * `aspect-ratio` is the one property the hero rebuild found with NO home in
     * either v2 system — absent from the taxonomy AND from the structural-CSS
     * lint's classification, which is fail-closed. Without this param the
     * capability would have been deleted rather than migrated (the #901 class).
     *
     * The four accepted shapes are the v1 `ratio` type's, unchanged: the `auto`
     * keyword (natural proportions — the type's own documented default, the same
     * "own preset is settable" pattern `shadow: none` has), a bare positive
     * number, and two positive numbers around a slash with or without spaces.
     */
    public function testAValidAspectRatioAuthorsThroughAndPaints(): void
    {
        foreach (['auto', '1', '1.6', '16/9', '4 / 3'] as $value) {
            $band = $this->band(['avatar' => ['sizing' => ['aspect-ratio' => $value]]]);

            $this->assertNull(
                pp_udc_validate_map($band['udc'], 'testimonials'),
                "aspect-ratio {$value} must be accepted at write"
            );
            $this->assertStringContainsString(
                'aspect-ratio:' . $value . ';',
                pp_udc_band_css(pp_udc_normalize_band($band)),
                "aspect-ratio {$value} must reach the emitted band block verbatim"
            );
        }
    }

    /**
     * THE REVERSE DIRECTION, and the half the ruling asked to be proven with the
     * slash edge cases specifically — because `/` is GRAMMAR here, not a banned
     * construct.
     *
     * The shared reject set bans the COMMENT delimiters `/*` and `*​/` but not a
     * bare slash, so `16/9` clears the injection gate on its own merits and the
     * ratio grammar is what has to reject everything below. Two failure families
     * are deliberately covered together, because they are refused by different
     * gates and a test that only covered one would let the other regress:
     *
     *   grammar      0, 16/0, -16/9, 1/2/3, 16//9, 16 9, calc(16/9), ''
     *   injection    16/*9*​/, 16/9}, auto;color:red, url(x)
     *
     * Zero and negative are refused on BOTH sides of the slash: a zero denominator
     * is a declaration that validates green and paints nothing, which is the I19
     * class the engine refuses rather than emits.
     */
    public function testAMalformedOrHostileAspectRatioIsRefusedAtWrite(): void
    {
        $cases = [
            '0', '16/0', '0/16', '-16/9', '16/-9', '1/2/3', '16//9', '16 9',
            'calc(16/9)', '', '  ', 'auto auto', '16/9px', 'none',
            '16/*9*/', '16/9}', 'auto;color:red', 'url(x)', 'var(--r)',
        ];

        foreach ($cases as $value) {
            $error = pp_udc_validate_map(
                ['avatar' => ['sizing' => ['aspect-ratio' => $value]]],
                'testimonials'
            );
            $this->assertInstanceOf(
                WP_Error::class,
                $error,
                sprintf('aspect-ratio %s must be refused at write', json_encode($value))
            );
        }
    }

    /**
     * The emitter re-rejects a stored ratio the write gate would have refused, so
     * data that reached storage another way (raw meta, a restore per #233) drops
     * its own declaration instead of painting an inert or hostile one. The sibling
     * of testAStoredValueThatNoLongerFitsItsGrammarDropsOnlyItsOwnDeclaration,
     * pinned for this param because it is the newest one.
     */
    public function testAStoredHostileAspectRatioDropsOnlyItsOwnDeclaration(): void
    {
        $band = $this->band([
            'avatar' => ['sizing' => ['aspect-ratio' => '16/9}', 'width' => '3rem']],
        ]);
        $css = pp_udc_band_css(pp_udc_normalize_band($band));

        $this->assertStringNotContainsString('aspect-ratio', $css);
        $this->assertStringNotContainsString('}' . 'aspect', $css);
        $this->assertStringContainsString('width:3rem;', $css, 'the sibling declaration must survive');
    }

    /**
     * ONE OWNER. The ratio grammar is _pp_validate_ratio() in lib/apply.php,
     * reached through _pp_validate_token_value()'s `case 'ratio'` — the same route
     * a v1 `ratio`-typed style slot takes. If this param ever grew its own
     * validator the two surfaces could drift, which is the forked-grammar the repo
     * architecture forbids; so the pin is that both routes agree, value for value.
     */
    public function testTheAspectRatioParamUsesTheSharedRatioGrammarAndNotASecondOne(): void
    {
        $param = pp_udc_groups()['sizing']['params']['aspect-ratio'];
        $this->assertSame('ratio', $param['type']);
        $this->assertSame('aspect-ratio', $param['property']);

        foreach (['auto', '16/9', '1.6', '0', '16/0', '1/2/3', 'calc(16/9)'] as $value) {
            $this->assertSame(
                _pp_validate_token_value($value, 'ratio') === true,
                pp_udc_validate_value($value, $param) === true,
                sprintf('the udc param and the shared ratio grammar must agree on %s', json_encode($value))
            );
        }
    }

    // ── Background-image companions (#986) ───────────────────────────────────

    private function stubImage(int $id): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
        $GLOBALS['_pp_test_store']['attachment_urls'][$id]     = 'https://example.com/bg.jpg';
    }

    /**
     * An authored band image gets cover/no-repeat/center unless the author said
     * otherwise. v1's `.hero--cover` supplied these in structural CSS; on v2 any
     * layout can carry a band image, so the engine supplies them instead.
     */
    public function testAnAuthoredBackgroundImageGetsItsCompanionDefaults(): void
    {
        $this->stubImage(42);
        $css = pp_udc_band_css([
            'component' => 'hero',
            'id'        => 'pp-aabb1122',
            'udc'       => ['_band' => ['background' => ['image' => '42']]],
        ]);

        $this->assertStringContainsString('background-image:url("', $css);
        $this->assertStringContainsString('background-size:cover', $css);
        $this->assertStringContainsString('background-repeat:no-repeat', $css);
        $this->assertStringContainsString('background-position:center', $css);
    }

    /** A companion is a DEFAULT: an authored value for the same param wins. */
    public function testAnAuthoredSizeBeatsTheCompanionDefault(): void
    {
        $this->stubImage(42);
        $css = pp_udc_band_css([
            'component' => 'hero',
            'id'        => 'pp-aabb1122',
            'udc'       => ['_band' => ['background' => ['image' => '42', 'size' => 'contain']]],
        ]);

        $this->assertStringContainsString('background-size:contain', $css);
        $this->assertStringNotContainsString('background-size:cover', $css);
        // The two the author did NOT set are still supplied.
        $this->assertStringContainsString('background-repeat:no-repeat', $css);
        $this->assertStringContainsString('background-position:center', $css);
    }

    /**
     * THE ORDERING TRAP, pinned. A `background` shorthand from `background.fill`
     * resets these longhands to their initial values, so a companion emitted
     * BEFORE it would be silently erased and the image would tile again.
     */
    public function testCompanionsSurviveABackgroundShorthandOnTheSameBand(): void
    {
        $this->stubImage(42);
        $css = pp_udc_band_css([
            'component' => 'hero',
            'id'        => 'pp-aabb1122',
            'udc'       => ['_band' => ['background' => ['fill' => '#ffffff', 'image' => '42']]],
        ]);

        $shorthand = strpos($css, 'background:');
        $size      = strpos($css, 'background-size:cover');
        $this->assertNotFalse($shorthand, 'the fill must still emit its shorthand');
        $this->assertNotFalse($size, 'the companion must still emit');
        $this->assertGreaterThan(
            $shorthand,
            $size,
            'background-size must print AFTER the background shorthand, or the shorthand resets it'
        );
    }

    /** No image, no companions — they must not appear on an ordinary band. */
    public function testABandWithNoImageGetsNoCompanions(): void
    {
        $css = pp_udc_band_css([
            'component' => 'hero',
            'id'        => 'pp-aabb1122',
            'udc'       => ['_band' => ['background' => ['fill' => '#ffffff']]],
        ]);

        $this->assertStringNotContainsString('background-size', $css);
        $this->assertStringNotContainsString('background-repeat', $css);
        $this->assertStringNotContainsString('background-position', $css);
    }
}
