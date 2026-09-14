<?php
/**
 * tests/FontPathInputValidationTest.php
 *
 * Input-validation hardening of the font path (issue #965), pinned in both
 * directions at both of its boundaries.
 *
 * TWO BOUNDARIES, ONE SINK. `enqueue_font` can point a --font-heading/--font-body
 * override at a family it DERIVED from the URL's `family=` parameter rather than
 * one the caller typed, and functions.php emits every stored override into a
 * `:root { … }` inline stylesheet. So a font family reaches CSS source text by a
 * route that used to pass no grammar at all:
 *
 *     url=…?family=<x>  ──derive──>  "<x>, system-ui, sans-serif"
 *          │                                    │
 *          │ (1) the write boundary             │ (2) the render boundary
 *          ▼                                    ▼
 *     enqueue_font validate arm            functions.php :root block
 *     _pp_validate_font_family()           pp_token_override_renders()
 *
 * HOSTILE BYTES ARE BUILT PROGRAMMATICALLY (chr()), never written as literal
 * escapes: the editing tools that author this file silently convert an escape
 * sequence into the character it names, so a fixture spelled as an escape does
 * not test what it appears to test.
 *
 * Both directions are pinned, because a validator that refuses everything is not
 * a fix: every Google Fonts and Bunny Fonts URL shape this repo's code, tests and
 * docs actually use must still derive a family that passes.
 */

use PHPUnit\Framework\TestCase;

class FontPathInputValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
        pp_invalidate_design_tokens_cache();
    }

    protected function tearDown(): void
    {
        pp_invalidate_design_tokens_cache();
        parent::tearDown();
    }

    /** A font stylesheet URL whose `family=` parameter carries the given raw bytes. */
    private function fontUrl(string $rawFamily): string
    {
        return 'https://fonts.googleapis.com/css2?family=' . rawurlencode($rawFamily) . '&display=swap';
    }

    private function validateEnqueueFont(array $params)
    {
        return call_user_func(pp_get_apply('enqueue_font')['validate'], $params);
    }

    // ── (1) The write boundary: the derived family ──────────────────────────

    /**
     * The confirmed vectors refuse at the validate arm.
     *
     * Each is a string CSS tokenization would treat as still open, or as ending
     * the declaration early, once it lands in `:root { --font-body: …; }`. All
     * three survive FILTER_VALIDATE_URL and the HTTPS test above them, because
     * percent-encoding is perfectly valid in a URL and `parse_str()` DECODES it.
     */
    public function testDerivedFamilyCarryingCssDelimitersIsRefused(): void
    {
        $lparen  = chr(40);   // (
        $rbrace  = chr(125);  // }
        $lt      = chr(60);   // <
        $gt      = chr(62);   // >
        $semi    = chr(59);   // ;
        $dquote  = chr(34);   // "

        $vectors = [
            'unbalanced function-open' => 'rgb' . $lparen,
            'block close'              => $rbrace . $lt . '/style' . $gt,
            // The deriver cuts at ':' (the CSS2 axis separator), so the stored
            // bytes are the part before it — still a declaration terminator.
            'declaration terminator'   => 'X' . $semi . 'color',
            'unterminated quote'       => $dquote . 'Foo',
        ];

        foreach ($vectors as $label => $raw) {
            $url = $this->fontUrl($raw);

            // Premise: the URL itself is well-formed and HTTPS, so nothing before
            // the family grammar can refuse it.
            $this->assertNotFalse(
                filter_var($url, FILTER_VALIDATE_URL),
                "premise failed for [$label]: the probe URL must be a valid URL"
            );
            $this->assertSame(
                $raw,
                _pp_derive_font_family_from_url($url),
                "premise failed for [$label]: the bytes must survive derivation"
            );

            $result = $this->validateEnqueueFont(['url' => $url, 'apply_to' => 'body']);
            $this->assertInstanceOf(
                WP_Error::class,
                $result,
                "[$label] must be refused at the validate arm"
            );
            $this->assertSame('invalid_font_family', $result->get_error_code(), "[$label]");
        }
    }

    /**
     * A derived family is refused whether or not `apply_to` routes it to a token.
     *
     * The disclosed narrowing. One rule for the derived family, applied at one
     * place, is what keeps validate-time and apply-time from drifting back apart;
     * a check that fired only under `apply_to` would be a second rule.
     */
    public function testDerivedFamilyIsRefusedEvenWithoutApplyTo(): void
    {
        $url = $this->fontUrl('rgb' . chr(40));

        $result = $this->validateEnqueueFont(['url' => $url]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_font_family', $result->get_error_code());
    }

    /** The refusal names the derived value, since the caller never typed it. */
    public function testTheRefusalNamesTheDerivedFamily(): void
    {
        $result = $this->validateEnqueueFont(['url' => $this->fontUrl('rgb' . chr(40))]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('derived from the URL', $result->get_error_message());
        $this->assertStringContainsString('rgb' . chr(40), $result->get_error_message());
    }

    /**
     * The named value goes through the reflected-text owner.
     *
     * A derived family is caller-influenced text that reaches whatever renders the
     * refusal, including the model reading its own tool output. A bidi-formatting
     * character in it must not survive into the message, and the message must stay
     * bounded however long the `family=` parameter is.
     */
    public function testTheNamedDerivedFamilyIsCleanedAndBounded(): void
    {
        $rlo = mb_chr(0x202E, 'UTF-8');   // RIGHT-TO-LEFT OVERRIDE
        $url = $this->fontUrl('Foo' . $rlo . 'Bar' . chr(40));

        $message = $this->validateEnqueueFont(['url' => $url])->get_error_message();
        $this->assertStringNotContainsString($rlo, $message);

        $long    = $this->fontUrl(str_repeat('A', 4000) . chr(40));
        $message = $this->validateEnqueueFont(['url' => $long])->get_error_message();
        $this->assertLessThan(
            PP_REFLECTED_NAME_MAX + 600,
            mb_strlen($message, 'UTF-8'),
            'the refusal must stay bounded however long the derived family is'
        );
    }

    // ── (1) Both directions: legitimate URLs must still work ────────────────

    /**
     * Every legitimate font-URL shape this repo uses still derives a passing family.
     *
     * Swept out of the codebase, tests and docs, plus the real-world Google shapes
     * the deriver's own docblock describes. A tightening that refused any of these
     * would break font enqueueing outright, so this half of the pin is the one
     * that would fail first if the grammar were over-narrowed.
     */
    public function testEveryLegitimateFontUrlStillDerivesAPassingFamily(): void
    {
        $urls = [
            'https://fonts.googleapis.com/css2?family=Inter',
            'https://fonts.googleapis.com/css2?family=Open+Sans',
            'https://fonts.googleapis.com/css2?family=Poppins',
            'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap',
            'https://fonts.googleapis.com/css?family=Open+Sans:400,700&subset=latin-ext',
            'https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,400;1,700',
            'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400&display=swap',
            'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400',
            'https://fonts.googleapis.com/css2?family=Press+Start+2P',
            'https://fonts.bunny.net/css?family=inter:400,600,700|playfair-display:700',
        ];

        foreach ($urls as $url) {
            $derived = _pp_derive_font_family_from_url($url);
            $this->assertNotSame('', $derived, "no family derived from $url");
            $this->assertTrue(
                _pp_validate_font_family($derived),
                "the family derived from $url must still pass the grammar (got: $derived)"
            );
            $this->assertTrue(
                $this->validateEnqueueFont(['url' => $url, 'apply_to' => 'both']),
                "enqueue_font must still accept $url"
            );
        }
    }

    /**
     * A URL with no `family=` parameter still derives nothing and is still accepted.
     *
     * Unchanged behaviour, pinned because the new check must fire on "derived a bad
     * family", never on "derived no family" — the latter writes no token at all and
     * has its own long-standing refusal (`missing_family`) only under `apply_to`.
     */
    public function testAUrlWithNoFamilyParamIsUnaffected(): void
    {
        $url = 'https://use.typekit.net/abc1234.css';

        $this->assertSame('', _pp_derive_font_family_from_url($url));
        $this->assertTrue($this->validateEnqueueFont(['url' => $url]));

        $withApplyTo = $this->validateEnqueueFont(['url' => $url, 'apply_to' => 'body']);
        $this->assertInstanceOf(WP_Error::class, $withApplyTo);
        $this->assertSame('missing_family', $withApplyTo->get_error_code());
    }

    /** An explicit family is still validated exactly as before. */
    public function testAnExplicitFamilyKeepsItsOwnRefusalCode(): void
    {
        $result = $this->validateEnqueueFont([
            'url'    => 'https://fonts.googleapis.com/css2?family=Inter',
            'family' => 'rgb' . chr(40),
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_font_family', $result->get_error_code());
        $this->assertStringNotContainsString(
            'derived from the URL',
            $result->get_error_message(),
            'an explicit family must not be described as derived'
        );
    }

    // ── (1) One derivation owner ────────────────────────────────────────────

    /**
     * Validate-time and apply-time resolve the family through the SAME owner.
     *
     * The defect this change closes was not a missing check so much as three
     * transcriptions of one rule, only two of which carried it. A behavioural pin
     * alone cannot catch a future fourth copy, so this asserts the structural
     * fact: no arm of `enqueue_font` calls the deriver directly any more.
     */
    public function testAllArmsResolveTheFamilyThroughOneOwner(): void
    {
        $this->assertSame(
            ['family' => 'Inter', 'source' => 'derived'],
            _pp_enqueue_font_family(['url' => 'https://fonts.googleapis.com/css2?family=Inter'])
        );
        $this->assertSame(
            ['family' => 'Georgia', 'source' => 'explicit'],
            _pp_enqueue_font_family(['url' => 'https://fonts.googleapis.com/css2?family=Inter', 'family' => 'Georgia'])
        );
        $this->assertSame(
            ['family' => '', 'source' => null],
            _pp_enqueue_font_family(['url' => 'https://use.typekit.net/abc1234.css'])
        );

        // CODE ONLY, NOT PROSE. Counting occurrences in raw source would also
        // count the function's own name where a comment explains it, so this
        // tokenizes and drops comments first — the tripwire has to fail when an
        // arm calls the deriver again, not when someone writes about it.
        $block = $this->enqueueFontArmsCode();

        $this->assertSame(
            0,
            substr_count($block, '_pp_derive_font_family_from_url('),
            'no enqueue_font arm may call the deriver directly — resolve through _pp_enqueue_font_family()'
        );
        $this->assertSame(
            3,
            substr_count($block, '_pp_enqueue_font_family('),
            'all three enqueue_font arms must resolve through the one owner'
        );
    }

    /** The `enqueue_font` registration block with every comment stripped out. */
    private function enqueueFontArmsCode(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/apply.php');
        $start  = strpos($source, "pp_register_apply('enqueue_font'");
        $end    = strpos($source, "pp_register_apply('remove_font'");

        // ASSERT THE DELIMITERS BEFORE SLICING. strpos() returns false when a
        // sentinel moves, `(int) false` is 0, and substr() with a negative length
        // silently trims from the end instead of erroring — which turns "someone
        // renamed remove_font" into a confident, wrong failure about code that is
        // correct. Fail on the real cause instead.
        $this->assertIsInt($start, "the enqueue_font block sentinel moved — update this tripwire");
        $this->assertIsInt($end, "the remove_font block sentinel moved — update this tripwire");
        $this->assertGreaterThan($start, $end, 'block delimiters are out of order — update this tripwire');

        return $this->codeOnly('<?php ' . substr($source, $start, $end - $start));
    }

    /** PHP source with every comment and docblock removed, so prose cannot match. */
    private function codeOnly(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    // ── (2) The render boundary: stored overrides ───────────────────────────

    /**
     * A planted bad override is dropped; healthy overrides beside it still emit.
     *
     * `--transition` is the theme's only `raw`-typed token, which is what makes it
     * the honest probe here: `raw` has no case in the type switch, so this value
     * is the shape most likely to reach storage.
     */
    public function testAPlantedBadOverrideIsDroppedWhileHealthyOnesEmit(): void
    {
        $overrides = [
            '--color-accent' => '#b45309',
            '--font-body'    => 'rgb' . chr(40) . ', system-ui, sans-serif',
            '--space-lg'     => '2rem',
        ];

        $partitioned = pp_partition_token_overrides($overrides, pp_design_tokens());

        $this->assertSame(['--font-body'], $partitioned['dropped']);
        $this->assertSame(
            ['--color-accent' => '#b45309', '--space-lg' => '2rem'],
            $partitioned['emit']
        );
    }

    /** An override for a token the theme does not declare is dropped, name and all. */
    public function testAnUnregisteredOverrideIsDropped(): void
    {
        $overrides = [
            '--color-accent'     => '#b45309',
            '--not-a-pp-token'   => 'red',
        ];

        $partitioned = pp_partition_token_overrides($overrides, pp_design_tokens());

        $this->assertSame(['--not-a-pp-token'], $partitioned['dropped']);
        $this->assertSame(['--color-accent' => '#b45309'], $partitioned['emit']);
    }

    /**
     * The block the emitter builds stays well-formed, so the tier below survives.
     *
     * The `:root` block and the v2 UDC defaults tier both attach to the `pp-base`
     * handle, and WordPress concatenates a handle's inline styles into ONE <style>
     * element. An unterminated declaration in the first would swallow the second.
     * This asserts the property that matters — the emitted block is balanced and
     * closed, and the planted bytes are not in it — rather than the byte string.
     */
    public function testTheEmittedBlockSurvivesAPlantedBadOverride(): void
    {
        // THE PRODUCTION EMITTER, not a copy of it. Building the block inside the
        // test would assert that the test's loop is correct, which is exactly how
        // a reverted emitter stayed green.
        $block = pp_token_overrides_inline_css(
            ['--color-accent' => '#b45309', '--transition' => 'rgb' . chr(40)],
            pp_design_tokens()
        );

        $this->assertStringContainsString('--color-accent: #b45309;', $block);
        $this->assertStringNotContainsString('rgb' . chr(40), $block);
        $this->assertSame(1, substr_count($block, chr(123)), 'exactly one block open');
        $this->assertSame(1, substr_count($block, chr(125)), 'exactly one block close');
        $this->assertSame(
            substr_count($block, chr(40)),
            substr_count($block, chr(41)),
            'the emitted block must not leave a function open'
        );

        // The tier that shares the handle still reads as its own rule afterwards.
        $concatenated = $block . "\n" . ':where([data-pp-band]) { color: red; }';
        $this->assertStringEndsWith('color: red; }', $concatenated);
        $this->assertSame(2, substr_count($concatenated, chr(125)));
    }

    /** Every shipped default value passes its own declared type. */
    public function testEveryStoredOverrideOfAShippedDefaultValueStillEmits(): void
    {
        $registry  = pp_design_tokens();
        $overrides = [];
        foreach ($registry as $token => $info) {
            $overrides[$token] = $info['value'];
        }

        $dropped = pp_partition_token_overrides($overrides, $registry)['dropped'];

        $this->assertSame(
            [],
            array_values(array_diff($dropped, [
                // Declared-type/default-value mismatches that predate this change
                // and are tracked in #967. An INVENTORY pin, not an exemption:
                // the seven are written out so the set cannot grow unnoticed, and
                // whichever way #967 resolves, this list shrinks with it.
                '--btn-padding-y', '--btn-padding-x', '--btn-bg', '--btn-border-color',
                '--btn-shadow', '--btn-hover-bg', '--btn-hover-border-color',
            ])),
            'no shipped default value may be newly dropped by the render boundary'
        );
    }

    /**
     * The bootstrap actually routes the block through the named emitter.
     *
     * The behavioural tests above execute pp_token_overrides_inline_css()
     * directly; nothing executes the `wp_enqueue_scripts` closure that is supposed
     * to CALL it, because an anonymous closure inside add_action() is never
     * invoked by this suite. So the wiring needs a tripwire — asserted as a
     * POSITIVE (the call is present, in code, exactly once) rather than as the
     * absence of some spelling of a loop, which a rename defeats.
     */
    public function testTheBootstrapEmitsThroughTheNamedEmitter(): void
    {
        $code = $this->codeOnly((string) file_get_contents(dirname(__DIR__) . '/functions.php'));

        // The positive is the whole tripwire: reverting the bootstrap to a raw
        // loop means REMOVING this call, whatever the loop variables get named.
        $this->assertSame(
            1,
            substr_count($code, 'pp_token_overrides_inline_css('),
            'functions.php must build the :root block through pp_token_overrides_inline_css()'
        );
    }

    // ── The delimiter guard, at every boundary it serves ────────────────────

    /**
     * An unmatched square bracket is refused everywhere the guard runs.
     *
     * `[` is a CSS simple-block delimiter exactly like `(` — CSS Syntax L3
     * "consume a simple block" has an unclosed one consume across the terminating
     * `;` and the closing `}` to EOF. The shared reject set does not ban it, and
     * `--transition` is `raw`-typed so no grammar looks at the value either; the
     * balance guard is the only thing standing at every one of these doors.
     */
    public function testAnUnmatchedSquareBracketIsRefusedAtEveryBoundary(): void
    {
        $lb = chr(91);   // [
        $rb = chr(93);   // ]

        foreach ([$lb, '150ms ease ' . $lb, $rb . 'x', $lb . $lb . $rb, '(' . $rb . ')'] as $value) {
            // The guard itself.
            $this->assertFalse(
                _pp_udc_delimiters_balanced($value),
                'unbalanced brackets must not balance: ' . $value
            );
            // The v1 design-token render boundary.
            $this->assertFalse(
                pp_token_override_renders('--transition', $value, pp_design_tokens()),
                'an unmatched bracket must not reach the :root block: ' . $value
            );
        }

        // The v1 emitter drops it while its healthy sibling still emits.
        $block = pp_token_overrides_inline_css(
            ['--color-accent' => '#b45309', '--transition' => '150ms ease ' . $lb],
            pp_design_tokens()
        );
        $this->assertStringContainsString('--color-accent: #b45309;', $block);
        $this->assertStringNotContainsString($lb, $block);
        $this->assertSame(substr_count($block, chr(123)), substr_count($block, chr(125)));

        // The v2 UDC write path, which shares the guard. Same function, same
        // bytes, same answer — that is the point of there being one guard.
        $this->assertInstanceOf(
            WP_Error::class,
            pp_udc_validate_value('Georgia ' . $lb, pp_udc_groups()['typography']['params']['family'])
        );
    }

    /**
     * A BALANCED bracket value still passes — this is a balance gate, not a ban.
     *
     * A grid track list names its lines with brackets, which is the real-world CSS
     * construct that must never regress here.
     */
    public function testBalancedBracketsStillPass(): void
    {
        foreach ([
            '[full-start] 1fr [full-end]',
            '[a] minmax(0, 1fr) [b]',
            '[x [y] z]',
            '"Foo[Bar]Baz"',
        ] as $value) {
            $this->assertTrue(
                _pp_udc_delimiters_balanced($value),
                'a balanced bracket value must still pass: ' . $value
            );
        }
    }

    /**
     * A closer hidden inside a CSS string does not discharge a real opener.
     *
     * CSS consumes a string before anything looks at its bytes, so the `)` in
     * `(")"` is string content and the parenthesis is still open at end of value.
     * A byte-counting walk sees one `(` and one `)` with even quote parity and
     * calls it balanced — the same escape as an unmatched bracket, wearing a
     * different delimiter. The quote-kind case (`"'"'`) is the twin: both marks
     * have even counts, but CSS reads `"'"` as one string and the trailing `'`
     * opens a second that never closes.
     */
    public function testAQuoteShieldedCloserDoesNotBalanceAnOpener(): void
    {
        $lp = chr(40); $rp = chr(41); $lb = chr(91); $rb = chr(93);
        $dq = chr(34); $sq = chr(39);

        $vectors = [
            'paren closer shielded by double quotes' => $lp . $dq . $rp . $dq,
            'paren closer shielded by single quotes' => $lp . $sq . $rp . $sq,
            'bracket closer shielded'                => $lb . $dq . $rb . $dq,
            'nested shielded'                        => $lp . $lp . $dq . $rp . $dq . $rp,
            'shielded after a real value'            => '150ms ease ' . $lp . $dq . $rp . $dq,
            'quote kinds interleaved'                => $dq . $sq . $dq . $sq,
        ];

        foreach ($vectors as $label => $value) {
            $this->assertFalse(
                _pp_udc_delimiters_balanced($value),
                "[$label] must not count as balanced"
            );
            $this->assertFalse(
                pp_token_override_renders('--transition', $value, pp_design_tokens()),
                "[$label] must not reach the :root block"
            );
        }

        // And the emitted block stays closed, with the sibling below it intact.
        $block = pp_token_overrides_inline_css(
            ['--color-accent' => '#b45309', '--transition' => $lp . $dq . $rp . $dq, '--radius' => '4px'],
            pp_design_tokens()
        );
        $this->assertStringContainsString('--radius: 4px;', $block);
        $this->assertStringNotContainsString($dq, $block);
        $this->assertSame(substr_count($block, $lp), substr_count($block, $rp));
    }

    /**
     * A bracket inside a CSS string is treated exactly as a paren already was.
     *
     * This guard counts bytes; it does not parse strings, so a delimiter opened
     * inside quotes and never closed is refused wherever it sits. That is
     * pre-existing behaviour for `(`, and the point of pinning it is that the
     * widening introduces no NEW asymmetry — both characters now answer the same
     * way for the same input, which is what "balanced delimiters" has to mean if
     * it is to mean one thing.
     */
    public function testABracketInsideAStringBehavesExactlyAsAParenDoes(): void
    {
        foreach (['Georgia, "Foo%sBar", sans-serif', '"%s"'] as $template) {
            $this->assertSame(
                _pp_udc_delimiters_balanced(sprintf($template, chr(40))),
                _pp_udc_delimiters_balanced(sprintf($template, chr(91))),
                'an unclosed bracket and an unclosed paren must answer alike'
            );
        }
    }

    /** Every row bad means no block at all, not an empty malformed one. */
    public function testTheEmitterProducesNothingWhenEveryRowIsDropped(): void
    {
        $this->assertSame(
            '',
            pp_token_overrides_inline_css(['--not-a-pp-token' => 'red'], pp_design_tokens())
        );
    }

    /**
     * Gate 3 alone: balanced, registered, but wrong for its declared type.
     *
     * Every other planted value in this file is UNBALANCED, so gate 2 decides and
     * gate 3 — the #330 delegation that is the whole reason this predicate exists
     * — never gets a vote. Replacing its body with `return true` must fail a test,
     * and without this one it did not.
     */
    public function testABalancedValueThatFailsItsDeclaredTypeIsStillDropped(): void
    {
        $registry = pp_design_tokens();

        // Premise: gates 0, 1 and 2 all pass, so only gate 3 can be deciding.
        $this->assertArrayHasKey('--color-accent', $registry);
        $this->assertTrue(_pp_udc_delimiters_balanced('not-a-color'));

        $this->assertFalse(pp_token_override_renders('--color-accent', 'not-a-color', $registry));
        $this->assertSame(
            ['--color-accent'],
            pp_partition_token_overrides(['--color-accent' => 'not-a-color'], $registry)['dropped']
        );
    }

    /**
     * A non-string stored value is decided by the predicate, not beside it.
     *
     * Restore (#233) and out-of-band DB writes are this boundary's stated threat
     * model, and a JSON round-trip is exactly how an int, float, null or array
     * lands in this option. Whatever the answer is, the partition and the
     * predicate have to give the same one, or the advisory describes a row the
     * page treated differently.
     */
    public function testNonStringStoredValuesAreDecidedByTheSharedPredicate(): void
    {
        $registry  = pp_design_tokens();
        $overrides = [
            '--transition'   => 1.5,
            '--space-lg'     => 2,
            '--color-accent' => null,
            '--radius'       => ['x'],
        ];

        $partitioned = pp_partition_token_overrides($overrides, $registry);

        foreach ($overrides as $token => $value) {
            $this->assertSame(
                pp_token_override_renders((string) $token, $value, $registry),
                isset($partitioned['emit'][$token]),
                "partition and predicate disagree about $token"
            );
        }
        $this->assertSame([], $partitioned['emit'], 'the write path types these as string; none of these came from it');
    }

    // ── (2) The advisory ────────────────────────────────────────────────────

    /** A dropped override is reported, classed and acknowledgeable, never silent. */
    public function testADroppedOverrideIsReportedAsAConfigurationFinding(): void
    {
        update_option('pp_token_overrides', [
            '--color-accent' => '#b45309',
            '--transition'   => 'rgb' . chr(40),
        ]);
        pp_invalidate_design_tokens_cache();

        $rows = pp_check_token_override_validity();

        $this->assertCount(1, $rows, 'only the dropped override is reported');
        $row = $rows[0];
        $this->assertSame('token_override_validity', $row['check']);
        $this->assertFalse($row['pass']);
        $this->assertSame('warning', $row['severity']);
        $this->assertSame('configuration', $row['class']);
        $this->assertTrue($row['acknowledgeable']);
        $this->assertNotSame('', $row['next_action']);
        $this->assertStringContainsString('--transition', $row['message']);
        $this->assertStringNotContainsString(
            'rgb' . chr(40),
            $row['message'],
            'the advisory names the token, never the value that failed'
        );
    }

    /** Healthy overrides produce no standing rows. */
    public function testHealthyOverridesReportNothing(): void
    {
        update_option('pp_token_overrides', ['--color-accent' => '#b45309']);
        pp_invalidate_design_tokens_cache();

        $this->assertSame([], pp_check_token_override_validity());
    }

    /** The advisory and the emitter cannot disagree about what paints. */
    public function testTheAdvisoryAndTheEmitterAgree(): void
    {
        $overrides = [
            '--color-accent'   => '#b45309',
            '--transition'     => 'rgb' . chr(40),
            '--not-a-pp-token' => 'red',
        ];
        update_option('pp_token_overrides', $overrides);
        pp_invalidate_design_tokens_cache();

        $dropped = pp_partition_token_overrides($overrides, pp_design_tokens())['dropped'];
        $rows    = pp_check_token_override_validity();

        $this->assertCount(count($dropped), $rows);
        foreach ($dropped as $token) {
            $this->assertNotEmpty(
                array_filter($rows, static fn(array $r): bool => str_contains($r['message'], $token)),
                "the emitter drops $token but the advisory does not report it"
            );
        }
    }

    /**
     * Two unregistered names that PRESENT identically still get distinct keys.
     *
     * The advisory strips \p{Cf} and truncates before display, and that treatment
     * is many-to-one. A key built from the displayed name would collapse these
     * two, and acknowledging one would silently acknowledge the other.
     */
    public function testUnregisteredFindingKeysDoNotCollideOnCleanedNames(): void
    {
        $zwsp = mb_chr(0x200B, 'UTF-8');   // ZERO WIDTH SPACE, a \p{Cf} character
        update_option('pp_token_overrides', [
            '--not-a-pp-token'          => 'red',
            '--not-a-pp-token' . $zwsp  => 'red',
        ]);
        pp_invalidate_design_tokens_cache();

        $rows = pp_check_token_override_validity();
        $keys = array_column($rows, 'finding_key');

        $this->assertCount(2, $rows);
        $this->assertCount(2, array_unique($keys), 'two distinct stored names must not share one finding key');
        foreach ($keys as $key) {
            $this->assertStringNotContainsString($zwsp, $key);
        }
    }

    // ── The log sink ────────────────────────────────────────────────────────

    /**
     * A stored token name cannot forge extra log lines or write an unbounded one.
     *
     * The names reaching this function are exactly the ones that FAILED
     * validation, so they are the attacker-influenced part of the drop path. A
     * newline would otherwise append a line of the attacker's choosing to the
     * site's error log.
     */
    public function testTheDropLogBoundsAndStripsStoredTokenNames(): void
    {
        $lf   = chr(10);
        $cr   = chr(13);
        $line = $this->captureDropLog([
            '--a' . $lf . 'PromptingPress: forged line' . $cr,
            '--b' . str_repeat('X', 5000),
        ]);

        // ONE entry, not two. error_log() terminates each entry itself, so the
        // capture always ends in a newline; what must not appear is a SECOND one.
        $this->assertSame(1, substr_count($line, $lf), 'a stored name must not forge a second log entry');
        $this->assertStringEndsWith($lf, $line);
        $this->assertStringNotContainsString($cr, $line);
        $this->assertLessThan(
            2 * PP_REFLECTED_NAME_MAX + 400,
            mb_strlen($line, 'UTF-8'),
            'one oversized stored name must not produce an oversized log line'
        );
    }

    /** Many dropped names report an honest total but a bounded line. */
    public function testTheDropLogCapsHowManyNamesItSpellsOut(): void
    {
        $names = [];
        for ($i = 0; $i < 400; $i++) {
            $names[] = '--planted-' . $i;
        }

        $line = $this->captureDropLog($names);

        $this->assertStringContainsString('dropped 400 design-token override(s)', $line);
        $this->assertStringContainsString('(+' . (400 - PP_DROPPED_TOKEN_LOG_MAX) . ' more)', $line);
        $this->assertSame(
            PP_DROPPED_TOKEN_LOG_MAX,
            substr_count($line, '--planted-'),
            'the line names at most PP_DROPPED_TOKEN_LOG_MAX of them'
        );
    }

    /** Nothing dropped, nothing logged. */
    public function testTheDropLogIsSilentWhenNothingWasDropped(): void
    {
        $this->assertSame('', $this->captureDropLog([]));
    }

    // ── The apply arm's last-ditch re-check ─────────────────────────────────

    private function applyEnqueueFont(array $params)
    {
        return call_user_func(pp_get_apply('enqueue_font')['apply'], $params);
    }

    /**
     * Reached directly — not through pp_execute_apply(), which refuses first —
     * the apply arm commits the URL and DECLINES the token write, rather than
     * reporting a failure for a write that already happened.
     */
    public function testTheApplyArmSkipsTheTokenWriteForAnUnvalidatedFamily(): void
    {
        $url = $this->fontUrl('rgb' . chr(40));

        $result = $this->applyEnqueueFont(['url' => $url, 'apply_to' => 'both']);

        $this->assertTrue($result['ok'], 'the URL write already happened; do not report failure');
        $this->assertSame([$url], pp_get_font_urls());
        $this->assertSame([], pp_get_token_overrides(), 'no --font-* token may be written');
        $this->assertArrayNotHasKey('family', $result, 'the result must not claim a family it declined');
        $this->assertArrayNotHasKey('family_source', $result);
        foreach ($result['changes'] as $change) {
            $this->assertArrayNotHasKey('token', $change);
        }
    }

    /** The same arm still writes both tokens for a family that passes. */
    public function testTheApplyArmStillWritesTokensForAValidDerivedFamily(): void
    {
        $result = $this->applyEnqueueFont([
            'url'      => 'https://fonts.googleapis.com/css2?family=Inter',
            'apply_to' => 'both',
        ]);

        $this->assertSame('Inter', $result['family']);
        $this->assertSame('derived', $result['family_source']);
        $this->assertSame(
            'Inter, system-ui, sans-serif',
            pp_get_token_overrides()['--font-body'] ?? null
        );
    }

    // ── The advisory's wiring and its two branches ──────────────────────────

    /** The advisory actually reaches the operator through preflight. */
    public function testPreflightCarriesTheTokenOverrideAdvisory(): void
    {
        update_option('pp_token_overrides', ['--transition' => 'rgb' . chr(40)]);
        pp_invalidate_design_tokens_cache();

        $rows = array_values(array_filter(
            pp_preflight()['checks'],
            static fn(array $c): bool => ($c['check'] ?? '') === 'token_override_validity'
        ));

        $this->assertCount(1, $rows, 'pp_preflight() must splice in the token-override advisory');
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertStringContainsString('--transition', $rows[0]['message']);
    }

    /** Each branch tells the operator the right thing to do about that row. */
    public function testTheAdvisoryBranchesCarryTheirOwnGuidance(): void
    {
        update_option('pp_token_overrides', [
            '--transition'     => 'rgb' . chr(40),   // registered, invalid value
            '--not-a-pp-token' => 'red',             // unregistered
        ]);
        pp_invalidate_design_tokens_cache();

        $byBranch = [];
        foreach (pp_check_token_override_validity() as $row) {
            $byBranch[str_contains($row['message'], '--transition') ? 'registered' : 'unregistered'] = $row;
        }

        $this->assertStringContainsString('no longer validates for its declared type', $byBranch['registered']['message']);
        $this->assertStringContainsString('update_design_token', $byBranch['registered']['next_action']);

        $this->assertStringContainsString('not a design token this theme declares', $byBranch['unregistered']['message']);
        $this->assertStringContainsString('reset_design_token', $byBranch['unregistered']['next_action']);
        $this->assertStringNotContainsString('update_design_token', $byBranch['unregistered']['next_action']);
    }

    /**
     * An unreadable registry is reported as a THEME problem, never as advice to
     * clear the operator's tokens.
     *
     * pp_design_tokens() returns [] when base.css cannot be read or its first
     * :root block does not parse. Every override then fails gate 1, and the
     * unregistered branch would otherwise tell the operator to reset_design_token
     * every token they own — a destructive instruction caused by a file read.
     */
    public function testAnUnreadableRegistryDoesNotAdviseClearingEveryToken(): void
    {
        $rows = $this->checkWithRegistry([], ['--color-accent' => '#b45309', '--space-lg' => '2rem']);

        $this->assertCount(1, $rows, 'one finding about the theme, not one per token');
        $this->assertSame('integrity', $rows[0]['class']);
        $this->assertStringNotContainsString('reset_design_token', $rows[0]['next_action']);
        $this->assertStringContainsString('base.css', $rows[0]['next_action']);
        $this->assertStringContainsString('2 stored token override(s)', $rows[0]['message']);
    }

    /** The advisory is bounded by stored data, exactly as the log line is. */
    public function testTheAdvisoryCapsHowManyRowsItEmits(): void
    {
        $overrides = [];
        for ($i = 0; $i < 400; $i++) {
            $overrides['--planted-' . $i] = 'red';
        }
        update_option('pp_token_overrides', $overrides);
        pp_invalidate_design_tokens_cache();

        $rows = pp_check_token_override_validity();

        $this->assertCount(PP_DROPPED_TOKEN_LOG_MAX + 1, $rows, 'named rows plus one summary row');
        $overflow = end($rows);
        $this->assertSame('token_override_validity:overflow', $overflow['finding_key']);
        $this->assertStringContainsString((string) (400 - PP_DROPPED_TOKEN_LOG_MAX), $overflow['message']);
    }

    /** Runs pp_check_token_override_validity() against a substituted registry. */
    private function checkWithRegistry(array $registry, array $overrides): array
    {
        update_option('pp_token_overrides', $overrides);
        // pp_design_tokens() reads base.css and merges the option; an empty
        // registry is what it returns when that file cannot be read. Simulated
        // by pointing the template directory at a location with no base.css,
        // which is the real cause this branch exists for.
        $previous = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = sys_get_temp_dir() . '/pp-no-theme-' . getmypid();
        pp_invalidate_design_tokens_cache();
        try {
            $this->assertSame($registry, pp_design_tokens(), 'premise: the registry must really be unreadable');
            return pp_check_token_override_validity();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previous;
            }
            pp_invalidate_design_tokens_cache();
        }
    }

    /** Runs pp_log_dropped_token_overrides() and returns what it wrote, if anything. */
    private function captureDropLog(array $dropped): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pp-droplog-');
        $previous = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            pp_log_dropped_token_overrides($dropped);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $written = (string) file_get_contents($tmp);
        unlink($tmp);

        return $written;
    }
}
