<?php
/**
 * tests/UdcRawCssTest.php — Layer 2, the `_css` raw declaration list (#1079).
 *
 * The contract is docs/v2/LAYER-2-CONTRACT.md; §8.R carries the rulings, and the two that
 * shape this file are R2′ (broad-by-default admission: every CSS property except a named
 * few) and R1′ (generic values through the security gates, typed where known, `@references`
 * only where a type exists).
 *
 * WHAT THIS FILE IS ACTUALLY GUARDING. Everywhere else in the engine the CSS property text
 * is looked up from `pp_udc_groups()` and the registry's docblock says an author "can no
 * more influence the property text than they can invent a role" — v1 emitted the author's
 * own slot key as a property name behind nothing but an `isset()`, and the registry exists
 * to record that as a mistake. Layer 2 gives the property back to the author by design, and
 * interpolates it into CSS SOURCE TEXT. So `pp_udc_valid_css_property()` is not a tidiness
 * rule, it is the boundary, and the hostile-byte sweep below is the point of this file.
 *
 * EVERY GATE IS RED-PROOFED IN BOTH DIRECTIONS, because a refusal test that would also pass
 * against a gate that refuses everything proves nothing. Each hostile case is paired with
 * the nearest ACCEPTED one — `opacity` against `opa city`, `color` against `Color`,
 * `-webkit-line-clamp` against `--webkit-thing` — so the assertions move when the charset
 * moves in either direction.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class UdcRawCssTest extends TestCase
{
    /** A `_css` map on faq's `answer` role, validated through the shared write gate. */
    private function validate(array $css, string $role = 'answer', string $component = 'faq')
    {
        return pp_udc_validate_map([$role => [PP_UDC_CSS_KEY => $css]], $component);
    }

    private function emit(array $udc, string $component = 'faq', string $id = 'pp-1079aaaa'): string
    {
        return pp_udc_band_css([
            'component' => $component,
            'id'        => $id,
            'props'     => [],
            'udc'       => $udc,
        ]);
    }

    // ── The property-name gate: the security boundary ───────────────────────

    /**
     * THE HOSTILE-BYTE SWEEP. Each of these, if admitted, reaches CSS source text inside a
     * `<style>` block immediately before a `:` — so each is a different way to stop being a
     * property and start being syntax.
     */
    public function testThePropertyNameGateRefusesEveryByteThatIsNotAPropertyName(): void
    {
        $hostile = [
            'a closing brace ends the rule'        => 'opa}city',
            'an opening brace starts one'          => 'opa{city',
            'a semicolon ends the declaration'     => 'opa;city',
            'a colon starts the value early'       => 'opacity:x',
            'whitespace splits the token'          => 'opa city',
            'a comment delimiter swallows the rest' => 'opa/*city',
            'a newline is not whitespace CSS keeps' => "opacity\n",
            'a trailing newline alone (the \\z case)' => "color\n",
            'a leading newline'                    => "\ncolor",
            'an angle bracket closes a style tag'   => 'opa<city',
            'a backslash escapes into a new byte'  => 'opa\\63 ity',
            'a NUL byte'                           => "opa\0city",
            'a quote opens a string'               => 'opa"city',
            'a paren opens a block'                => 'opa(city',
            'an at-sign starts an at-rule'         => '@import',
            'a custom property is _tokens business' => '--brand',
            'a double hyphen after one'            => '---x',
            'uppercase is a second spelling'       => 'Color',
            'mixed case likewise'                  => 'fontSize',
            'an empty name'                        => '',
            'a lone hyphen'                        => '-',
            'over 64 characters'                   => 'a' . str_repeat('b', 64),
            'a digit may not lead'                 => '2d-transform',
            'a non-ASCII letter'                   => 'colöur',
        ];

        foreach ($hostile as $why => $property) {
            $error = $this->validate([$property => '1']);
            $this->assertInstanceOf(
                \WP_Error::class,
                $error,
                "the property name " . var_export($property, true) . " must be refused: {$why}"
            );
            $this->assertSame(
                'unknown_udc_css_property',
                $error->get_error_code(),
                'a malformed property name is a property refusal, not a value refusal — the '
                . 'author needs to be sent to the name, not to the grammar'
            );
        }
    }

    /**
     * THE OTHER HALF. Without these the sweep above would pass against a gate that refused
     * everything, which is the vacuous-guard shape this repo has been bitten by.
     */
    public function testThePropertyNameGateAdmitsOrdinaryAndVendorPrefixedProperties(): void
    {
        $accepted = [
            'opacity',
            'color',
            'mix-blend-mode',
            'text-indent',
            'z-index',
            'grid-template-columns',
            '-webkit-line-clamp',
            '-moz-osx-font-smoothing',
            str_repeat('a', 64),
        ];
        foreach ($accepted as $property) {
            // THE ASSERTION IS ABOUT THE NAME, so it is made about the name. Using one
            // value for every property would test the VALUE grammars instead — `inherit`
            // is a fine raw value and an invalid `color`, so the first draft of this test
            // failed on a typed property while the gate it was checking worked perfectly.
            $error = $this->validate([$property => 'inherit']);
            $this->assertNotSame(
                'unknown_udc_css_property',
                is_wp_error($error) ? $error->get_error_code() : '',
                "\"{$property}\" is a legitimate CSS property NAME and must clear the charset "
                . 'gate — the freedom guarantee is the default and the exclusions are the exceptions'
            );
        }
    }

    /** The lowercase refusal earns its keep only if it tells the author the fix. */
    public function testAnUppercasePropertyIsRefusedWithItsLowercaseForm(): void
    {
        $error = $this->validate(['Color' => 'red']);
        $this->assertStringContainsString(
            'write "color"',
            $error->get_error_message(),
            'CSS property names are case-insensitive, so the refusal has to name the one '
            . 'spelling the engine keys on rather than leaving the author to guess'
        );
    }

    // ── The exclusion set ───────────────────────────────────────────────────

    /** Every §6.0 entry is refused, and the refusal carries its stated reason. */
    public function testEveryExcludedPropertyIsRefusedWithItsReason(): void
    {
        $excluded = pp_udc_css_excluded_properties();
        $this->assertNotEmpty($excluded, 'the exclusion set must not be empty — broad-by-default '
            . 'admission means these carry the whole burden of justification');

        foreach ($excluded as $property => $reason) {
            $error = $this->validate([$property => 'inherit']);
            $this->assertInstanceOf(\WP_Error::class, $error, "\"{$property}\" must be refused");
            $this->assertStringContainsString(
                $reason,
                $error->get_error_message(),
                "the refusal for \"{$property}\" must state WHY, not merely that it is unavailable"
            );
        }
    }

    /**
     * `url()` is a VALUE problem and is refused on every property, which is why no
     * url()-bearing property is on the exclusion list. Pinned so nobody "fixes" the
     * exclusion set by adding properties that never needed excluding.
     */
    public function testUrlIsRefusedByValueOnAnyPropertyRatherThanByExcludingProperties(): void
    {
        foreach (['background-image', 'cursor', 'mask', 'filter', 'border-image'] as $property) {
            $this->assertArrayNotHasKey(
                $property,
                pp_udc_css_excluded_properties(),
                "\"{$property}\" must NOT be excluded: the risk is url() in the VALUE, and "
                . 'excluding the property would suggest otherwise while costing the author the property'
            );
        }
        $error = $this->validate(['mask' => 'url(https://evil.example/x.svg)']);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertStringContainsString('url()', $error->get_error_message());
    }

    // ── Typed where known, verbatim where not (R1′) ─────────────────────────

    /** A property a registry param emits keeps that param's whole typed grammar. */
    public function testAPropertyTheVocabularyKnowsIsTypeChecked(): void
    {
        $this->assertNull($this->validate(['color' => '#ff0000']), 'a valid colour is accepted');
        $error = $this->validate(['color' => '3rem']);
        $this->assertInstanceOf(\WP_Error::class, $error, 'a length on `color` must be refused: '
            . 'the property is one the vocabulary types, so it keeps its grammar');
        $this->assertStringContainsString('valid CSS color', $error->get_error_message());
    }

    /**
     * An unknown property passes the SECURITY gates and nothing else, and its value reaches
     * the sheet byte for byte — including a compound the engine must not split into words.
     */
    public function testAnUnknownPropertyPassesSecurityOnlyAndEmitsVerbatim(): void
    {
        $this->assertNull($this->validate(['mix-blend-mode' => 'multiply']));
        $this->assertNull(
            $this->validate(['grid-template-columns' => 'repeat(3, minmax(0, 1fr))']),
            'a compound value must survive as ONE value: max_values is 1 for an untyped '
            . 'property precisely so it is not judged word by word'
        );

        $css = $this->emit(['answer' => [PP_UDC_CSS_KEY => [
            'grid-template-columns' => 'repeat(3, minmax(0, 1fr))',
        ]]]);
        $this->assertStringContainsString(
            'grid-template-columns:repeat(3, minmax(0, 1fr));',
            $css,
            'the value emits exactly as written — that is what "verbatim after the security '
            . 'gates" means, and it is what the unchecked-property finding discloses'
        );
    }

    /** The security gates run on an untyped value exactly as they do on a typed one. */
    public function testTheSecurityGatesRunOnUntypedValuesToo(): void
    {
        $hostile = [
            'declaration break'  => '1;color:red',
            'rule break'         => '1}.x{color:red',
            'comment delimiter'  => '1/*x*/',
            'url()'              => 'url(x)',
            '@import'            => '@import "x"',
            'expression()'       => 'expression(alert(1))',
            'unbalanced paren'   => 'calc(1',
            'unbalanced quote'   => '"abc',
            'backslash escape'   => '\\3b color:red',
            'angle bracket'      => '1<style',
        ];
        foreach ($hostile as $why => $value) {
            $this->assertInstanceOf(
                \WP_Error::class,
                $this->validate(['mix-blend-mode' => $value]),
                "an untyped property must not become a hole: {$why}"
            );
        }
        // The paired accept, so the sweep cannot pass against a gate that refuses everything.
        $this->assertNull($this->validate(['mix-blend-mode' => 'multiply']));
    }

    /**
     * R1′.3 — the clause that survived the Q1 reversal verbatim, and the one thing the
     * reversal was NOT allowed to reopen: a reference needs a type to be judged against,
     * or a colour token resolves onto a length-ish property and paints nothing (#230).
     */
    public function testAReferenceNeedsADeclaredTypeAndIsRefusedWithoutOne(): void
    {
        $this->assertNull(
            $this->validate(['color' => '@color-accent']),
            'a reference on a TYPED property is checkable, so it is accepted'
        );
        $error = $this->validate(['mix-blend-mode' => '@color-accent']);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertStringContainsString(
            'no declared grammar',
            $error->get_error_message(),
            'the refusal must say WHY a reference cannot be checked here, and that a literal is fine'
        );
        // Also inside a breakpoint map, which is the shape that would slip past a scalar-only check.
        $this->assertInstanceOf(
            \WP_Error::class,
            $this->validate(['mix-blend-mode' => ['d' => 'multiply', 'p' => '@color-accent']])
        );
    }

    // ── Emission: breakpoints, states, and the ruled rank ───────────────────

    public function testBreakpointsAndStatesEmitThroughTheEngineSMachinery(): void
    {
        $css = $this->emit(['answer' => [PP_UDC_CSS_KEY => [
            'opacity'  => ['d' => '1', 'p' => '0.6'],
            ':hover'   => ['opacity' => '0.9'],
        ]]]);

        $this->assertStringContainsString('.faq__answer:hover{opacity:0.9;}', $css,
            'a state reaches raw declarations on the same terms as a group');
        $this->assertStringContainsString('@media (max-width: 767px)', $css,
            'the ENGINE writes the media query — an author never does');
        $this->assertStringContainsString('opacity:0.6;', $css,
            'the phone value lands in the phone tier. (Minting is a WRITE-time normalization, '
            . 'not an emission one, so a band compiled straight from literals emits literals — '
            . 'the mint round trip is pinned separately below.)');
    }

    /**
     * §2′.3 — `_css` outranks a group value, IN BOTH KEY ORDERS.
     *
     * The second half is the one that matters. _pp_udc_place() lets a later placement
     * overwrite an earlier one, and the compiler walks the author's own map order, so
     * before the fix this band painted `#ff0000` or `#111111` depending on which key the
     * JSON happened to carry first — a rank no author can see and no finding could
     * describe.
     */
    public function testCssOutranksAGroupValueWhicheverOrderTheAuthorWroteThemIn(): void
    {
        $orders = [
            'group first' => ['typography' => ['color' => '#111111'], PP_UDC_CSS_KEY => ['color' => '#ff0000']],
            '_css first'  => [PP_UDC_CSS_KEY => ['color' => '#ff0000'], 'typography' => ['color' => '#111111']],
        ];
        foreach ($orders as $label => $role_map) {
            $css = $this->emit(['answer' => $role_map]);
            $this->assertStringContainsString('color:#ff0000;', $css, "{$label}: the raw value must win");
            $this->assertStringNotContainsString('color:#111111;', $css, "{$label}: the group value must not");
        }
    }

    // ── The disclosures ─────────────────────────────────────────────────────

    public function testTheCollisionIsDisclosedNamingTheParameterThatLost(): void
    {
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [
                'typography'      => ['color' => '#111111'],
                PP_UDC_CSS_KEY    => ['color' => '#ff0000'],
            ]],
        ]]);
        $types = array_column($findings, 'type');
        $this->assertContains('udc_css_overrides_group_value', $types);

        $message = $findings[array_search('udc_css_overrides_group_value', $types, true)]['message'];
        $this->assertStringContainsString('typography.color', $message,
            'the finding must name the parameter that lost, or the author cannot act on it');

        // And it must NOT fire when the author wrote only the raw one — nothing of theirs lost.
        $alone = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => ['color' => '#ff0000']]],
        ]]);
        $this->assertNotContains('udc_css_overrides_group_value', array_column($alone, 'type'));
    }

    public function testTheUncheckedPropertyIsDisclosedOncePerDeclarationIncludingStates(): void
    {
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [
                'mix-blend-mode' => 'multiply',
                'color'          => '#ff0000',
                ':hover'         => ['mix-blend-mode' => 'normal'],
            ]]],
        ]]);
        $unchecked = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property'
        ));
        $this->assertCount(2, $unchecked,
            'once for the resting declaration and once for the `:hover` one — and NOT for '
            . '`color`, which the vocabulary types and therefore checks');
        $this->assertStringContainsString(':hover', $unchecked[1]['message'],
            'the state has to be in the locator or the author cannot tell the two apart');
    }

    /**
     * A band with NO `_tokens` is the overwhelmingly common band, and it is the one the
     * disclosures originally missed entirely — they sat after the token section's early
     * return. Pinned as its own case because the general tests above all happen to avoid
     * minting, which is exactly how the defect survived being written.
     */
    public function testTheDisclosuresFireOnABandThatMintsNoToken(): void
    {
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => ['mix-blend-mode' => 'multiply']]],
        ]]);
        $this->assertNotEmpty($findings, 'a band with raw CSS and no band tokens must still '
            . 'be reported on — the token section early-returns and is not the end of the work');
    }

    // ── The mint namespace: the CRITICAL regression ─────────────────────────

    /**
     * A `_css` mint is `<role>-_css-<property>-<bp>`, and two decoders have to agree that
     * such a name is the ENGINE'S OWN. If they do not, validation — which runs over STORED
     * compositions on every post-write envelope, `wp pp check page` and restore — reports
     * an error on the engine's own output forever, for every band holding a responsive raw
     * value. Both docblocks in lib/udc.php record that failure mode; this is its pin.
     */
    public function testAResponsiveRawValueMintsANameTheEngineRecognisesAsItsOwn(): void
    {
        $normalized = pp_udc_normalize_band([
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => ['opacity' => ['d' => '1', 'p' => '0.6']]]],
        ]);

        $this->assertArrayHasKey('answer-_css-opacity-p', $normalized['udc']['_tokens'] ?? [],
            'a responsive raw value mints exactly as a group value does');

        $this->assertNull(
            pp_udc_validate_map($normalized['udc'], 'faq'),
            'THE REGRESSION: the engine must accept its own minted name back. A decoder that '
            . 'does not know the `_css` pseudo-group reads this as an author squatting the '
            . 'mint namespace and refuses every band that holds one, permanently.'
        );
    }

    /** The other direction: the namespace is still reserved against an author squatting it. */
    public function testAnAuthorSquattingAMintedRawNameIsStillRefused(): void
    {
        $error = pp_udc_validate_map([
            '_tokens' => ['answer-_css-opacity-p' => '0.2'],
            'answer'  => [PP_UDC_CSS_KEY => ['opacity' => '1']],
        ], 'faq');
        $this->assertInstanceOf(\WP_Error::class, $error,
            'the name is mint-shaped and nothing in this map references it as the engine '
            . 'would, so it is a squat and the author\'s value would be overwritten');
    }

    // ── Presets, chrome, and the authoring path ─────────────────────────────

    public function testAPresetMayNotCarryRawDeclarations(): void
    {
        $error = pp_udc_validate_preset_definition('brandish', [
            'grain' => 'role',
            'udc'   => [PP_UDC_CSS_KEY => ['opacity' => '0.5']],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $error,
            'a site-wide named bundle carrying raw declarations is an escape nobody can '
            . 'attribute to the band that used it');

        $this->assertNull(
            pp_udc_validate_map(['answer' => [PP_UDC_CSS_KEY => ['opacity' => '0.5']]], 'faq'),
            'and the band path still accepts it — the preset refusal must not be implemented '
            . 'as a blanket one'
        );
    }

    /** Chrome routes through the same engine, so the valve reaches nav and footer for free. */
    public function testRawDeclarationsReachChromeThroughTheSameEngine(): void
    {
        $roles = pp_udc_component_roles('nav');
        $this->assertNotEmpty($roles, 'nav must declare roles for this test to mean anything');
        $role = array_key_first(array_diff_key($roles, ['_band' => true])) ?? '_band';

        $this->assertNull(
            pp_udc_validate_map([$role => [PP_UDC_CSS_KEY => ['opacity' => '0.9']]], 'nav'),
            'chrome is validated by pp_udc_validate_map() itself — "THE SAME ENGINE. Not a '
            . 'chrome-flavoured copy of it."'
        );
    }

    /**
     * 14.1 AUTHORING-PATH MANDATE — through the real write surface, not a raw meta write.
     * Raw seeding bypasses exactly the gate this whole file is about.
     */
    public function testRawCssRoundTripsThroughTheRealCompositionWritePath(): void
    {
        $composition = [[
            'component' => 'faq',
            'id'        => 'pp-1079feed',
            'props'     => ['title' => 'Q', 'items' => [['question' => 'Q?', 'answer' => '<p>A.</p>']]],
            'udc'       => ['answer' => [PP_UDC_CSS_KEY => ['mix-blend-mode' => 'multiply']]],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'a well-formed raw declaration must be accepted by the composition gate every '
            . 'ingress path traverses'
        );

        $composition[0]['udc']['answer'][PP_UDC_CSS_KEY] = ['opa}city' => '1'];
        $this->assertNotTrue(
            pp_validate_composition($composition),
            'and a hostile property name must be refused there too — the gate is shared, so '
            . 'this is the proof that the shared path really reaches it'
        );
    }

    /**
     * THE WRITE-ACCEPT / EMIT-DROP PROHIBITION (#570 convergence, #1048's class).
     *
     * R1′ makes this the sharpest constraint in the layer: whatever the write gate accepts,
     * the emitter emits or DISCLOSES. The inverse is checked here — stored data the gate
     * would have refused must be DROPPED AND LEDGERED rather than painted, because raw meta
     * writes, pre-rule compositions and restore all reach the emitter directly.
     */
    public function testStoredDataTheGateWouldRefuseIsDroppedAndLedgeredRatherThanPainted(): void
    {
        $drops = [];
        $css   = pp_udc_compile_band([
            'component' => 'faq', 'id' => 'pp-1079aaaa', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [
                'opa}city' => '1',
                'all'      => 'unset',
                'opacity'  => '0.5',
            ]]],
        ], 'authored', $drops);

        $rendered = json_encode($css);
        $this->assertStringNotContainsString('opa}city', $rendered, 'a refused property name must never reach the sheet');
        $this->assertStringNotContainsString('all:unset', $rendered, 'nor an excluded property');
        $this->assertStringContainsString('opacity', $rendered, 'while the legitimate sibling still paints');

        $this->assertCount(2, $drops, 'BOTH discards must be ledgered: a silent drop is the '
            . 'reported-success-without-effect class I35 forbids, and the ledger is the only '
            . 'channel that reaches stored data the write gate never saw');
        foreach ($drops as $drop) {
            $this->assertStringContainsString(PP_UDC_CSS_KEY, $drop['where'],
                'the locator must name the surface the author would look at');
        }
    }
}
