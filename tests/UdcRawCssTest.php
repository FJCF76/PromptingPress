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

    /**
     * THE REFUSAL IS A REFLECTING CHANNEL TOO (I37), and this one had to be bounded
     * deliberately: `pp_udc_parse_reference()` applies NO charset — it returns everything
     * after the `@` — so the name in this message is arbitrary author bytes of arbitrary
     * length. Found by the pre-landing review, probed rather than reasoned: a 227-byte
     * hostile name produced a 481-byte refusal carrying it verbatim.
     */
    public function testTheUntypedReferenceRefusalBoundsAndCleansTheNameItEchoes(): void
    {
        $hostile = '@' . str_repeat('A', 200) . '}<script>';
        $error   = $this->validate(['mix-blend-mode' => $hostile]);
        $message = $error->get_error_message();

        $this->assertStringNotContainsString(str_repeat('A', 120), $message,
            'the echoed name must be bounded — every other value this engine reflects is');
        $this->assertStringNotContainsString('<script>', $message,
            'and cleaned at the sink, like every other reflected value');
        $this->assertLessThan(400, strlen($message),
            'an unbounded name makes an unbounded refusal, which is the shape the '
            . 'reflected-text owner exists to stop');
    }

    /**
     * NO VALUE MAY NAME AN EXTERNAL RESOURCE, and `url(` was an incomplete enumeration
     * of that rule rather than the rule itself.
     *
     * Found by the pre-landing security pass. §6.0 argues that no url()-bearing property
     * needs excluding because `url(` is refused in every VALUE — so the moment a value can
     * name a host WITHOUT writing `url(`, that argument collapses and every untyped
     * image-accepting property becomes a way to point a visitor's browser at a third-party
     * host from stored content. CSS has three such spellings and only one of them contains
     * the banned token.
     */
    public function testNoValueCanNameAnExternalResourceHoweverItIsSpelled(): void
    {
        $spellings = [
            'mask-image'          => 'image-set("https://example.invalid/x.png" 1x)',
            '-webkit-mask-image'  => '-webkit-image-set("https://example.invalid/x.png" 1x)',
            'list-style-image'    => 'image("https://example.invalid/x.png")',
            'border-image-source' => 'src("https://example.invalid/x.png")',
            'cursor'              => 'image-set("https://example.invalid/x.png" 1x), auto',
            'offset-path'         => 'url("https://example.invalid/x.svg#p")',
        ];
        foreach ($spellings as $property => $value) {
            $this->assertInstanceOf(
                \WP_Error::class,
                $this->validate([$property => $value]),
                "\"{$property}\" must not admit an external resource — the Media Library is the "
                . 'only source of external assets, and §6.0 leaves these properties admissible '
                . 'precisely because the VALUE gate is supposed to close this'
            );
        }

        // RED-PROOFED IN THE OTHER DIRECTION, or the sweep above would pass equally well
        // against a gate that refused every value on these properties.
        $this->assertNull($this->validate(['mask-image' => 'linear-gradient(black, transparent)']),
            'a value that names no resource must still be accepted on the same property');
        $this->assertNull($this->validate(['cursor' => 'pointer']));
        $this->assertNull($this->validate(['background-repeat' => 'no-repeat']),
            'and an ordinary hyphenated identifier containing neither function must be untouched');
    }

    /**
     * R1′.3 IS DOUBLED AT EMIT, because the write gate is not the only door.
     *
     * The write gate refuses an `@reference` on a property the vocabulary cannot type.
     * Stored data reaches the emitter without passing it — a raw meta write, a composition
     * written before this layer, `restore_composition` (#233) — so a rule enforced on one
     * side only means refused-at-write and PAINTED-from-storage. Found by the pre-landing
     * security pass: the two gates were disagreeing, which is what the #570 convergence
     * rule forbids in either direction.
     */
    public function testAStoredUntypedReferenceIsDroppedAtEmitAndLedgered(): void
    {
        $drops    = [];
        $compiled = pp_udc_compile_band([
            'component' => 'faq', 'id' => 'pp-1079ref', 'props' => [],
            'udc' => [
                '_tokens' => ['tok' => 'multiply'],
                'answer'  => [PP_UDC_CSS_KEY => ['mix-blend-mode' => '@tok']],
            ],
        ], 'authored', $drops);

        $this->assertStringNotContainsString(
            'mix-blend-mode',
            json_encode($compiled),
            'refused at write means refused from storage too, or the rule is only half a rule'
        );
        $this->assertNotSame([], $drops, 'and the emit-time refusal must reach the ledger, not be silent');

        // Red-proof: a TYPED property still takes its reference, so this is a targeted
        // rule and not a blanket refusal of references inside `_css`.
        $typed = pp_udc_compile_band([
            'component' => 'faq', 'id' => 'pp-1079typed', 'props' => [],
            'udc' => ['_tokens' => ['ink' => '#111111'], 'answer' => [PP_UDC_CSS_KEY => ['color' => '@ink']]],
        ], 'authored');
        $this->assertStringContainsString('color', json_encode($typed));
    }

    /**
     * THE DISCLOSURE CHANNEL BOUNDS ITS OWN ALLOCATION, and skips a role that cannot paint.
     *
     * `_css` is the first finding producer whose key space is the AUTHOR'S rather than the
     * registry's finite group-by-param table, so it is the first that can be asked for an
     * unbounded number of findings. Its sibling the drop ledger already caps itself and
     * says why: slicing the reader's output does not bound the input. `wp pp check page`
     * and restore reach this function without the write path's size gate in front of them.
     */
    public function testTheDisclosuresAreBoundedAndSkipARoleThatCannotPaint(): void
    {
        $css = [];
        for ($i = 0; $i < 5000; $i++) {
            $css['zz-prop-' . $i] = '1';
        }
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079cap', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => $css]],
        ]]);
        $unchecked = array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property'
        );
        $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, count($unchecked),
            'the disclosure channel must bound its own allocation, as the drop ledger does');

        // BOUNDED ACROSS THE COMPOSITION, not per band. Scoped per item the cap bounded
        // nothing that matters: 400 bands x 400 properties measured 80,000 findings and
        // +56 MB in one call, on paths with no size gate in front of them. The ledger it
        // cites as precedent shares one collector across every band.
        $many = [];
        for ($b = 0; $b < 40; $b++) {
            $props = [];
            for ($k = 0; $k < 40; $k++) {
                $props['zz-p-' . $k] = '1';
            }
            $many[] = ['component' => 'faq', 'id' => 'pp-b' . $b, 'props' => [],
                       'udc' => ['answer' => [PP_UDC_CSS_KEY => $props]]];
        }
        $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, count(pp_udc_composition_findings($many)),
            'the cap is global across the composition, not per band');
        $this->assertNotSame([], $unchecked, 'and must still report — a cap is not a mute');

        $this->assertSame(
            [],
            pp_udc_composition_findings([[
                'component' => 'faq', 'id' => 'pp-1079ghost', 'props' => [],
                'udc' => ['no-such-role' => [PP_UDC_CSS_KEY => ['mix-blend-mode' => 'multiply']]],
            ]]),
            'a role the component does not declare is never walked by the compiler, so '
            . 'claiming its CSS "is emitted exactly as written" reports on the wrong subject'
        );
    }

    /**
     * THE WHOLE STATE DIMENSION OF THE WRITE GATE, which was entirely unpinned.
     *
     * Measured by the testing pass: neutering `_pp_udc_validate_css_map()`'s state arm,
     * its nested-state refusal AND its `:`-key route left the full suite at 5191 tests /
     * 32882 assertions, byte-identical. All three branches were correct — and unguarded,
     * which is the same thing as untested. `:hover` was reaching only `pp_udc_band_css()`
     * and the findings, never `validate()`, so a broken state arm would have refused every
     * author writing a hover map with nothing to notice.
     */
    public function testAStateNestsInsideRawDeclarationsAndOnlyOneLevelDeep(): void
    {
        $this->assertNull($this->validate([':hover' => ['opacity' => '0.5']]),
            'a state reaches `_css` on the same terms as a group — the WRITE half of the '
            . 'emission test below, which until now was the only half that existed');

        $nested = $this->validate([':hover' => [':focus-visible' => ['opacity' => '0.5']]]);
        $this->assertInstanceOf(\WP_Error::class, $nested);
        $this->assertStringContainsString('States do not nest', $nested->get_error_message());

        $pseudo = $this->validate(['::before' => ['opacity' => '0.5']]);
        $this->assertInstanceOf(\WP_Error::class, $pseudo);
        $this->assertSame('invalid_prop_value', $pseudo->get_error_code(),
            'a state-SHAPED key is a STATE refusal, not a property-charset one: `::before` '
            . 'and `:disabled` are things an author will try, and answering with the charset '
            . 'rule sends them to the wrong question entirely');
        $this->assertStringContainsString('does not exist', $pseudo->get_error_message());
    }

    /**
     * THE REFUSAL LOCATOR IS LAYER 2'S OWN VOCABULARY, and it was unpinned — the branch
     * could be reverted to the group spelling with the suite green.
     *
     * Spelled the group way it read `role "answer" "_css" group "_css" parameter "opacity"`:
     * the key named twice and the word "group" applied to something that is not one. A
     * diagnostic that names the wrong KIND of thing sends an operator to the wrong surface,
     * which is what I27's one-canonical-vocabulary rule is about.
     */
    public function testARawDeclarationRefusalNamesTheKeyOnceAndNeverCallsItAGroup(): void
    {
        $message = $this->validate([':hover' => ['color' => '3rem']])->get_error_message();
        $this->assertStringContainsString('"_css" :hover property "color"', $message);
        $this->assertStringNotContainsString('group "_css"', $message,
            'the group spelling named the key twice and called it a group');
        $this->assertStringNotContainsString('parameter "color"', $message,
            '`_css` keys are properties, not parameters');
    }

    /**
     * A STORED HOSTILE **VALUE**, which the emit-side proof was missing entirely — it
     * covered hostile property NAMES and one excluded property, and nothing else.
     *
     * The write-gate sweep above never runs for a raw `_pp_composition` meta write, a
     * composition written before this layer, or `restore_composition` (#233). This file's
     * whole thesis is that the value reaches CSS source text; the emitter has to hold on
     * its own.
     */
    public function testAStoredHostileValueIsRefusedAtEmitToo(): void
    {
        $hostile = [
            'a typed property, declaration break' => ['color' => 'red;} body{display:none'],
            'an untyped property, rule break'     => ['mix-blend-mode' => 'multiply;} body{display:none'],
            'an untyped property, url()'          => ['mask-image' => 'url(https://example.invalid/x.svg)'],
            'the image-set() spelling'            => ['mask-image' => 'image-set("https://example.invalid/x.png" 1x)'],
            'a style-tag break'                   => ['mix-blend-mode' => '</style><script>alert(1)</script>'],
        ];
        foreach ($hostile as $why => $map) {
            $this->assertSame(
                '',
                $this->emit(['answer' => [PP_UDC_CSS_KEY => $map]]),
                "a stored hostile VALUE must never reach the sheet: {$why}"
            );
        }
        $this->assertNotSame('', $this->emit(['answer' => [PP_UDC_CSS_KEY => ['mix-blend-mode' => 'multiply']]]),
            'red-proofed: a clean stored value on the same property still paints');
    }

    /**
     * A STORED PROPERTY THE EMITTER DROPS MUST NOT BE DISCLOSED AS EMITTED VERBATIM.
     *
     * The unknown-ROLE guard beside this one is tested; the property equivalent was not,
     * and neutering it made the findings tell an author that a stored `all` or `opa}city`
     * is "emitted exactly as written" while the compiler drops both. Same wrong-subject
     * defect, one field over.
     */
    public function testAStoredPropertyTheEmitterDropsIsNotDisclosedAsEmittedVerbatim(): void
    {
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079drop', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [
                'opa}city'       => '1',
                'all'            => 'unset',
                'mix-blend-mode' => 'multiply',
            ]]],
        ]]);
        $unchecked = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property'
        ));
        $this->assertCount(1, $unchecked, 'only the property that actually emits is disclosed');
        $this->assertStringContainsString('mix-blend-mode', $unchecked[0]['message'],
            'and the legitimate sibling still is — a skip is not a mute');
    }

    /**
     * A RAW SHORTHAND RESETS THE LONGHANDS A GROUP OWNS, and that is a collision.
     *
     * Untyped properties sort after every registry property, so `border: 1px solid red`
     * written raw wipes an authored `border.width`/`style`/`color` that emitted three
     * declarations earlier in the same block. Measured: all three dead, one
     * unchecked-property finding, nothing saying anything was overridden.
     */
    public function testARawShorthandDisclosesEveryGroupLonghandItResets(): void
    {
        $findings = pp_udc_composition_findings([[
            'component' => 'faq', 'id' => 'pp-1079sh', 'props' => [],
            'udc' => ['answer' => [
                'border'       => ['width' => '2px', 'style' => 'solid', 'color' => '#111111'],
                PP_UDC_CSS_KEY => ['border' => '1px solid red'],
            ]],
        ]]);
        $overrides = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_css_overrides_group_value'
        ));
        $this->assertCount(3, $overrides,
            'one per authored longhand the shorthand kills — silence here is the I35 class '
            . '`_pp_udc_property_rank()`\'s own docblock names');
        foreach (['border-width', 'border-style', 'border-color'] as $longhand) {
            $this->assertStringContainsString(
                $longhand,
                implode(' ', array_column($overrides, 'message'))
            );
        }
    }

    /** `_band._css` gets the same inheritance disclosure the group form gets. */
    public function testABandRawDeclarationIsDisclosedWhenARoleDefaultCancelsIt(): void
    {
        $types = static fn(array $band): array => array_column(
            pp_udc_composition_findings([[
                'component' => 'faq', 'id' => 'pp-1079bnd', 'props' => [], 'udc' => ['_band' => $band],
            ]]),
            'type'
        );
        $this->assertContains('udc_band_value_shadowed_by_role_default',
            $types([PP_UDC_CSS_KEY => ['color' => '#ff0000']]),
            'byte-identical emitted CSS must not be reported oppositely depending on which '
            . 'surface the author used to write it');
        $this->assertContains('udc_band_value_shadowed_by_role_default',
            $types(['typography' => ['color' => '#ff0000']]),
            'red-proofed: the group form still reports, so the assertion above is not '
            . 'passing because the helper reports everything');
    }

    /** The collision disclosure is per STATE; a raw `:hover` does not contest a resting value. */
    public function testTheCollisionIsDisclosedPerStateAndNotAcrossStates(): void
    {
        $find = static fn(array $udc): array => array_column(
            pp_udc_composition_findings([[
                'component' => 'faq', 'id' => 'pp-1079st', 'props' => [], 'udc' => ['answer' => $udc],
            ]]),
            'type'
        );

        $this->assertContains('udc_css_overrides_group_value', $find([
            'typography'   => [':hover' => ['color' => '#111111']],
            PP_UDC_CSS_KEY => [':hover' => ['color' => '#ff0000']],
        ]), 'a collision inside a state is still a collision');

        $this->assertNotContains('udc_css_overrides_group_value', $find([
            'typography'   => ['color' => '#111111'],
            PP_UDC_CSS_KEY => [':hover' => ['color' => '#ff0000']],
        ]), 'but a raw `:hover` and a resting group value are different coordinates, so '
          . 'nothing the author wrote lost and reporting one would be a false collision');
    }

    /** A `_css` that is not a map: refused at write, and LEDGERED rather than dropped silently. */
    public function testARawDeclarationListThatIsNotAMapIsRefusedAndLedgered(): void
    {
        $error = pp_udc_validate_map(['answer' => [PP_UDC_CSS_KEY => 'color:red']], 'faq');
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertStringContainsString('must be an object of CSS property => value', $error->get_error_message());

        $drops = [];
        pp_udc_compile_band([
            'component' => 'faq', 'id' => 'pp-1079shp', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => 'color:red']],
        ], 'authored', $drops);
        $this->assertCount(1, $drops, 'stored data the write gate never saw must be ledgered, not silent');
        $this->assertStringContainsString(PP_UDC_CSS_KEY, $drops[0]['where']);
    }

    /**
     * `!important` IS NEVER AUTHORABLE (contract §6.14), and Layer 2 was the first way in.
     *
     * Every typed grammar rejects it as a side effect of being a grammar, so the rule had
     * never needed its own gate — until an untyped `_css` value started reaching the sheet
     * verbatim. Not cosmetic: this engine's cascade story is that specificity is flat by
     * construction and `!important` never appears, so one authored `!important` makes a
     * band value unbeatable by the component's own defaults, by a later band, and by the
     * author's own next write. Found by the pre-landing maintainability pass, which
     * noticed the engine header and the contract both asserting a guarantee the code had
     * just stopped keeping.
     */
    public function testImportantIsRefusedOnARawDeclarationAtBothGates(): void
    {
        foreach (['0.5 !important', '0.5!important', '0.5 ! important', '0.5 !IMPORTANT'] as $value) {
            $this->assertInstanceOf(
                \WP_Error::class,
                $this->validate(['opacity' => $value]),
                "\"{$value}\" must be refused: the engine keeps specificity flat by construction"
            );
        }
        $this->assertSame(
            '',
            $this->emit(['answer' => [PP_UDC_CSS_KEY => ['opacity' => '0.5 !important']]]),
            'and stored data the write gate never saw must not paint it either'
        );
        $this->assertNotSame('', $this->emit(['answer' => [PP_UDC_CSS_KEY => ['opacity' => '0.5']]]),
            'red-proofed: the same property without it still paints');
    }

    /**
     * A NEAR-MISS `_css` MUST NOT BE TOLD THE REAL KEY DOES NOT EXIST (contract §2.7, C1).
     *
     * `_css` is not a registry group, so the unknown-group listing omitted it and an
     * author who typed `css` or `_cs` was sent to look for something else. The contract
     * named this as a required change and called for this pin; neither shipped until the
     * maintainability pass found the gap.
     */
    public function testAMistypedRawDeclarationKeyIsOfferedTheRealOne(): void
    {
        foreach (['css', '_cs', '_CSS'] as $typo) {
            $message = pp_udc_validate_map(['answer' => [$typo => ['opacity' => '1']]], 'faq')
                ->get_error_message();
            $this->assertStringContainsString(PP_UDC_CSS_KEY, $message,
                "a near-miss \"{$typo}\" must be offered the key that exists, not a list that omits it");
        }
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

    /**
     * A state-shaped key with a NON-MAP value gets the same diagnosis at both gates.
     *
     * The write gate calls `{":hover": "red"}` a state whose value must be an object. The
     * emitter used to gate on `isset($states[$key]) && is_array($value)`, so the same
     * bytes fell through to the property branch and were ledgered as "not an available CSS
     * property" — two names for one defect, sending an operator reading a restore report
     * to the wrong question. Found by the adversarial pass.
     */
    public function testAStateHoldingSomethingOtherThanAMapIsDiagnosedAsAStateAtBothGates(): void
    {
        $error = $this->validate([':hover' => 'red']);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertStringContainsString('must be an object', $error->get_error_message());

        $drops = [];
        pp_udc_compile_band([
            'component' => 'faq', 'id' => 'pp-1079stx', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [':hover' => 'red']]],
        ], 'authored', $drops);
        $this->assertCount(1, $drops);
        $this->assertStringContainsString('state', $drops[0]['reason'],
            'the emitter must call it a state too, not "not an available CSS property"');
    }

    /**
     * TWO COORDINATES MUST NEVER MINT ONE NAME AND DESTROY EACH OTHER.
     *
     * Mint names join segments with `-` and escape nothing, so two coordinates collide as
     * soon as a segment can contain `-`. Unreachable while every parameter came from the
     * registry — none ends in a state name — and ordinary the moment `_css` let the author
     * name it. Measured before the fix, on ONE accepted write: the first value existed
     * nowhere in storage afterwards, the base declaration painted the hover value, the
     * stored form re-validated clean, and two findings said otherwise. Found by the
     * adversarial pass.
     */
    public function testTwoCoordinatesThatWouldMintOneNameBothSurvive(): void
    {
        $normalized = pp_udc_normalize_band([
            'component' => 'faq', 'id' => 'pp-1079col', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [
                'x-hover' => ['d' => 'AAA', 'p' => 'BBB'],
                ':hover'  => ['x' => ['d' => 'CCC', 'p' => 'DDD']],
            ]]],
        ]);

        $stored = json_encode($normalized);
        foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $literal) {
            $this->assertStringContainsString($literal, $stored,
                "\"{$literal}\" must survive normalization — a mint collision must never "
                . 'destroy an author value');
        }
        $this->assertNull(pp_udc_validate_map($normalized['udc'], 'faq'),
            'and the stored form must still validate: the decoders have to agree that an '
            . 'AMBIGUOUS mint name is the engine\'s own, which needs both readings of the '
            . 'tail, not just the greedy one');

        $css = pp_udc_band_css($normalized);
        $this->assertStringContainsString('x-hover:', $css, 'the at-rest declaration paints');
        $this->assertStringContainsString(':hover', $css, 'and the hover one does too');
    }

    /** `_css` must not escape the reduced-motion guard the motion group gets. */
    public function testRawMotionDeclarationsAreNeutralisedUnderReducedMotion(): void
    {
        foreach (['transition' => '2s all', 'animation-duration' => '2s'] as $property => $value) {
            $this->assertStringContainsString(
                'prefers-reduced-motion',
                $this->emit(['answer' => [PP_UDC_CSS_KEY => [$property => $value]]]),
                "\"{$property}\" animates, so the engine's accessibility guarantee must "
                . 'follow the PROPERTY rather than the registry parameter list'
            );
        }
        $this->assertStringNotContainsString(
            'prefers-reduced-motion',
            $this->emit(['answer' => [PP_UDC_CSS_KEY => ['opacity' => '0.5']]]),
            'red-proofed: a property that does not animate gets no guard'
        );
    }

    /** An exclusion survives a vendor prefix; a legitimate prefixed property does not. */
    public function testAnExcludedPropertyCannotBeReachedThroughAVendorPrefix(): void
    {
        foreach (['-webkit-all', '-ms-behavior', '-webkit-content'] as $property) {
            $this->assertInstanceOf(\WP_Error::class, $this->validate([$property => 'initial']),
                "\"{$property}\" must inherit its unprefixed form's exclusion — the charset "
                . 'admits one vendor prefix, which made an exact-string exclusion set sidesteppable');
        }
        $this->assertNull($this->validate(['-webkit-line-clamp' => '3']),
            'red-proofed: a prefixed property that is NOT excluded stays writable');
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

        // AND IT MUST PAINT. This assertion is the one that was missing, and its absence
        // let the feature's ordinary documented case ship completely broken: the emitter's
        // untyped-`@reference` guard — added one round earlier to close the OPPOSITE
        // divergence — had no engine-mint carve-out, so a responsive raw value minted,
        // validated, and emitted nothing at all. Checking that a normalized map VALIDATES
        // is not the same claim as checking that it RENDERS, and only the second one is
        // what an author gets. Caught by the adversarial pass.
        $drops = [];
        $css   = pp_udc_band_css($normalized);
        pp_udc_compile_band($normalized, 'authored', $drops);

        $this->assertStringContainsString('opacity:var(--pp-answer-_css-opacity-d)', $css,
            'the minted desktop value must reach the page');
        $this->assertStringContainsString('@media (max-width: 767px)', $css,
            'and the phone tier must too — this is the whole point of minting a responsive value');
        $this->assertSame([], $drops,
            'and nothing may be ledgered as dropped: the engine minted these names itself');
    }

    /**
     * A COLLISION THAT BITES ON ONE BREAKPOINT MUST NOT LEAVE THE OTHER ONE MINTED.
     *
     * The collision guard bails from inside the breakpoint loop, and `$tokens` is passed
     * by reference — so before this pin, a breakpoint that minted BEFORE the colliding one
     * stayed written while the caller discarded the rewrite and kept the literals. The
     * leftover was not merely dead weight: it is a mint-SHAPED name that nothing
     * references, which is exactly what `testAnAuthorSquattingAMintedRawNameIsStillRefused`
     * below defines as a squat. So an ACCEPTED write stored a band that failed
     * `pp_udc_validate_map()` on the engine's own output, and would keep failing on every
     * post-write envelope, `wp pp check page` and restore.
     *
     * `x-hover` carrying only `p` is what makes the two coordinates collide on `p` while
     * leaving `d` free; with both breakpoints on both coordinates the bail happens on the
     * first iteration and nothing is left behind, which is why the original measurement
     * missed it.
     */
    public function testAPartialMintCollisionLeavesNoTokenBehind(): void
    {
        $normalized = pp_udc_normalize_band([
            'component' => 'faq', 'id' => 'pp-1079bbbb', 'props' => [],
            'udc' => ['answer' => [PP_UDC_CSS_KEY => [
                'x-hover' => ['p' => '1'],
                ':hover'  => ['x' => ['d' => '2', 'p' => '3']],
            ]]],
        ]);

        $tokens = $normalized['udc']['_tokens'] ?? [];
        $this->assertArrayNotHasKey('answer-_css-x-hover-d', $tokens,
            'the `d` coordinate of the colliding value must not be minted on its own: the '
            . 'value it belongs to kept its literals, so the name would reference nothing');

        $css = pp_udc_band_css($normalized);
        foreach (array_keys($tokens) as $name) {
            $this->assertStringContainsString('var(--pp-' . $name . ')', $css,
                sprintf('every minted token must be referenced by a declaration; "%s" is not', $name));
        }

        $this->assertNull(
            pp_udc_validate_map($normalized['udc'], 'faq'),
            'THE REGRESSION: the stored form of an accepted write must re-validate. An '
            . 'orphan mint-shaped name reads as an author squatting the namespace, and '
            . 'validation runs over stored compositions on every envelope, check and restore.'
        );

        // Non-destructive, as the collision guard intends: nothing the author wrote is lost.
        foreach (['1', '2', '3'] as $value) {
            $this->assertStringContainsString(':' . $value, $css,
                'both colliding coordinates must still paint — the guard declines to mint, '
                . 'it does not drop a value');
        }
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

    /**
     * `_css` AT THE TOP LEVEL IS THE MISTAKE THE SHAPE INVITES, so the refusal routes
     * rather than merely refusing. Raw declarations feel band-wide and `_tokens` really
     * does sit at that level, so "no role called _css; available roles are …" answers a
     * question the author did not ask. I24 wants a stated reason AND a route back.
     */
    public function testATopLevelCssKeyIsRefusedWithTheRoleItBelongsInside(): void
    {
        $error = pp_udc_validate_map([PP_UDC_CSS_KEY => ['opacity' => '0.5']], 'faq');
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertStringContainsString('"_band"', $error->get_error_message(),
            'the refusal must name the role that styles the band itself, which is what an '
            . 'author reaching for a top-level `_css` almost always meant');
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
