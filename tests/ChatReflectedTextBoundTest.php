<?php
/**
 * tests/ChatReflectedTextBoundTest.php — the chat client's ceiling on server error text (#793).
 *
 * WHY A PHP TEST READS A JAVASCRIPT FILE. The pattern is #822's (tests/ChatUndoBoundTrait.php)
 * and the argument is the same one, applied to four more sites. `addStatusMessage()`,
 * `executeProposal()` and `handleStreamError()` are all nested inside the chat script's
 * DOM-ready closure and are not exported, so vitest can reach the exported HELPER but not the
 * lines that CALL it. Delete a call and every JavaScript test still passes. A static grep is a
 * poor test of behavior and an excellent tripwire for a deletion, and it runs in the sub-second
 * loop rather than in a 30-minute browser suite.
 *
 * AND ONE PROPERTY THAT ONLY THIS SIDE CAN CHECK: the bound must stay the SERVER'S number.
 * `PP_CHAT_REFLECTED_ERROR_MAX` is a hand-copy of `PP_REFLECTED_ERROR_MAX` (lib/ai-chat.php),
 * and its docblock's whole argument is that copying it keeps ONE answer to "how long may a
 * reflected error be". Nothing but an assertion makes that true.
 *
 * WHAT #793 IS, since the issue body predates its own answer. The body asks for a JS twin of
 * `_pp_cli_printable()` — a `\p{Cc}\p{Cf}` strip — and offers, as its own alternative, that the
 * fix might belong in the server's message builder instead. v1.17.8 (#647/#649) took the
 * alternative and recorded why: "a second strip in JavaScript would be a second definition of
 * 'clean' that could only drift, and the client cannot repair invalid UTF-8." So the strip half
 * is closed in a way that FORBIDS what the body asked for, and what #647 left behind is written
 * into the code it wrote — `_pp_ai_execute_error_payload()` (lib/ai-chat.php): "What the client
 * still owns is the LENGTH it renders under (#793)". A ceiling, not a sanitizer. The last test
 * in this file pins the sanitizer's ABSENCE, so a later reader who finds the issue body before
 * finding the ruling cannot quietly reintroduce the twin.
 *
 * THE REGEXES ARE DELIBERATELY LOOSE about whitespace and strict about the thing being
 * asserted, for the reason ChatUndoBoundTrait states: a test that breaks when someone reformats
 * the file teaches maintainers to delete tripwires.
 */

class ChatReflectedTextBoundTest extends \PHPUnit\Framework\TestCase
{
    /** The shipped chat script, read once per call — small, and never cached across an edit. */
    private function chatScriptSource(): string
    {
        $path = dirname(__DIR__) . '/assets/js/pp-ai-chat.js';
        $this->assertFileExists($path, 'the chat script this contract is shared with must exist');

        return (string) file_get_contents($path);
    }

    /**
     * The bound is the server's own number for this species, and stays it.
     *
     * `PP_REFLECTED_ERROR_MAX` is what `_pp_clean_reflected_text()` allows a reflected
     * validator message. The client copy is what the chat RENDERS under. Keeping them equal is
     * what makes "one answer to how long may a reflected error be" true across the wire.
     */
    public function testTheClientBoundIsTheServersOwnNumber(): void
    {
        // `[\d_]+` rather than `\d+`: an ES2021 numeric separator (`4_096`) is the same number
        // and must not read as a different one, or as no declaration at all.
        $matched = preg_match(
            '/(?:var|let|const)\s+PP_CHAT_REFLECTED_ERROR_MAX\s*=\s*([\d_]+)\s*;/',
            $this->chatScriptSource(),
            $m
        );

        $this->assertSame(1, $matched, 'the chat script must still declare the bound this test reads');
        $this->assertSame(
            PP_REFLECTED_ERROR_MAX,
            (int) str_replace('_', '', $m[1]),
            'PP_CHAT_REFLECTED_ERROR_MAX claims to be the server\'s reflected-error budget; keep them equal or drop that claim'
        );
    }

    /**
     * The two client bounds agree with each other, because they agree with the same server one.
     *
     * They are separate constants on purpose — `PP_CHAT_UNDO_ERROR_MAX` carries a headroom
     * promise about two specific undo refusals that this one does not make — but a reader who
     * sees two numbers is owed the guarantee that they cannot diverge.
     */
    public function testTheTwoClientBoundsCannotDiverge(): void
    {
        $src = $this->chatScriptSource();

        preg_match('/(?:var|let|const)\s+PP_CHAT_REFLECTED_ERROR_MAX\s*=\s*([\d_]+)\s*;/', $src, $reflected);
        preg_match('/(?:var|let|const)\s+PP_CHAT_UNDO_ERROR_MAX\s*=\s*([\d_]+)\s*;/', $src, $undo);

        $this->assertNotEmpty($reflected, 'the reflected-text bound must still be declared');
        $this->assertNotEmpty($undo, 'the undo bound must still be declared');
        $this->assertSame(
            (int) str_replace('_', '', $undo[1]),
            (int) str_replace('_', '', $reflected[1]),
            'both client bounds copy PP_REFLECTED_ERROR_MAX; they cannot hold different numbers'
        );
    }

    /** The helper exists exactly once, and is the only thing that owns the truncation. */
    public function testTheBoundingHelperIsDeclaredExactlyOnce(): void
    {
        $this->assertSame(
            1,
            preg_match_all('/function\s+ppChatBoundReflectedText\s*\(/', $this->chatScriptSource()),
            'ppChatBoundReflectedText must be declared exactly once (#793)'
        );
    }

    /**
     * The truncation idiom is the repo's, not a new one.
     *
     * `_pp_clean_reflected_text()` on the PHP side, and `ppChatUndoFailureText()` /
     * `ppChatPreviewRenderErrorText()` / `ppChatFormatDiffValue()` on this side, all cut to
     * `max - 3` and mark with `...`. A fifth spelling of "this was shortened" is the #650/#652
     * lesson; this pins that none was invented.
     */
    public function testTheTruncationMarkerReusesTheExistingConvention(): void
    {
        $src = $this->chatScriptSource();

        // Both patterns are name-agnostic on purpose. Pinning the local `cut` would make a pure
        // rename a red test, which is the "teaches maintainers to delete tripwires" failure this
        // file's own header warns about. The cut pattern also accepts `- MARKER.length`, so
        // extracting the marker into a named constant — a strict improvement, and the
        // deduplication this repo prefers — is not blocked by its own tripwire.
        $this->assertSame(
            1,
            preg_match_all('/PP_CHAT_REFLECTED_ERROR_MAX\s*-\s*(?:3|\w+\.length)/', $src),
            'the cut must be to max minus the marker length, the convention shared with _pp_clean_reflected_text'
        );
        $this->assertMatchesRegularExpression(
            "/return\s+\w+\s*\+\s*'\.\.\.'/",
            $src,
            "the marker must stay '...', the convention every other bound in this repo uses"
        );
    }

    /**
     * A cut never SPLITS a well-formed surrogate pair.
     *
     * `substring` cuts by code unit, so on astral-plane text the cut can land between the two
     * halves of one character. Without the guard the bound produces malformed display text on
     * exactly the non-ASCII input it exists to contain. The JS suite proves the BEHAVIOR; this
     * pins that the guard was not "simplified away" as dead code by someone reading only ASCII
     * fixtures.
     *
     * Deliberately a single `if` and not a loop: text that ALREADY held lone surrogates can
     * still end on one, and removing those would be cleaning input the cut did not orphan —
     * sanitizing, which #647 placed on the server and ruled out on this side.
     */
    public function testTheCutGuardsAgainstSplittingASurrogatePair(): void
    {
        // Name-agnostic and order-agnostic: the assertion is that the guard tests the
        // high-surrogate RANGE, not that it spells the comparison in one particular direction
        // with one particular local variable.
        $this->assertMatchesRegularExpression(
            '/0x[dD]800[\s\S]{0,60}0x[dD]BFF|0x[dD]BFF[\s\S]{0,60}0x[dD]800/',
            $this->chatScriptSource(),
            'the high-surrogate guard on the cut must still be there (#793)'
        );
    }

    /**
     * Every render site of server error text is still wired to the bound.
     *
     * ONE CALL EACH is the assertion, and both halves matter: at least one proves the wiring is
     * still there, and not more than one proves it has not been duplicated onto a neighbouring
     * branch that renders something else.
     *
     * @dataProvider renderSiteProvider
     */
    public function testEveryRenderSiteOfServerErrorTextIsBounded(string $label, string $pattern): void
    {
        $this->assertSame(
            1,
            preg_match_all($pattern, $this->chatScriptSource()),
            sprintf('%s must route its server-supplied span through ppChatBoundReflectedText exactly once (#793)', $label)
        );
    }

    /**
     * The five sites, and what each one renders.
     *
     * Three of them have NO server length bound at all today: `_pp_action_error()`
     * (lib/actions.php) stores `'error' => $error` verbatim, so the batch envelope error and
     * every `steps[i].error` reflect validator messages uncapped; `_pp_bounded_findings()` is a
     * COUNT bound by its own docblock, so `findings[].message` has no ceiling; and
     * `pp_ai_parse_error_response()` (lib/ai-provider.php) returns a third party's error body.
     * Closing those server-side is #864, deferred. Until it lands these are the only bounds
     * those strings meet.
     */
    public static function renderSiteProvider(): array
    {
        // EACH PATTERN ANCHORS ON THE CONSUMING EXPRESSION, not on the helper call alone.
        // A grep for `ppChatBoundReflectedText(batch.error)` by itself is satisfied by a
        // DEAD call sitting beside an unbounded `addStatusMessage(...)` — the tripwire
        // would stay green while the site it names went back to rendering raw text. Tying
        // each pattern to the assignment or argument position that actually reaches the DOM
        // is what makes it a test of the wiring rather than of the file's vocabulary.
        return [
            'the finding row (the site #793 is named for)' => [
                'the finding row (both halves)',
                '/div\.textContent\s*=\s*ppChatBoundReflectedText\(\s*ppChatFindingLocator\(\s*item\s*\)\s*\)\s*\+\s*ppChatBoundReflectedText\(\s*item\.message\s*\)/',
            ],
            'the batch up-front refusal status line' => [
                'the batch up-front refusal',
                '/addStatusMessage\(\s*\'Error: \'\s*\+\s*ppChatBoundReflectedText\(\s*\(\s*resp\.data\s*&&\s*resp\.data\.error\s*\)/',
            ],
            'the batch envelope error' => [
                'the batch envelope error',
                '/addStatusMessage\(\s*\'Error: \'\s*\+\s*ppChatBoundReflectedText\(\s*batch\.error\s*\|\|/',
            ],
            'the failed-step error' => [
                'the failed-step error',
                '/message\s*=\s*\'Error on step \'[^;]*ppChatBoundReflectedText\(\s*failedResult\.error\s*\|\|/',
            ],
            'the stream / provider error' => [
                'the stream error body',
                '/msgBody\.textContent\s*=\s*ppChatBoundReflectedText\(\s*errorText\s*\)/',
            ],
        ];
    }

    /**
     * The Connectors classification reads what ARRIVED, not what is displayed.
     *
     * This is the one place the ORDER of the bound is load-bearing rather than cosmetic. A
     * provider can return a multi-megabyte error body, and the phrase that earns the "Settings
     * > Connectors" link can sit past the cut. Classifying on the truncated copy would silently
     * drop the single affordance that fixes the error being reported — a bound that breaks the
     * recovery path is worse than no bound. So the raw `errorText` must still be what the
     * `indexOf` tests read.
     */
    public function testTheConnectorsLinkIsClassifiedOnTheUnboundedText(): void
    {
        $src = $this->chatScriptSource();

        $this->assertMatchesRegularExpression(
            "/errorText\.indexOf\(\s*'API key'\s*\)/",
            $src,
            'the Connectors classification must still read the raw errorText'
        );
        $this->assertSame(
            0,
            preg_match_all('/ppChatBoundReflectedText\(\s*errorText\s*\)\s*\.\s*indexOf/', $src),
            'classifying on the bounded copy would drop the Connectors link off the end of a long provider error'
        );

        // PIN THE PROPERTY, NOT ONE SPELLING OF IT. The assertion above forbids exactly one
        // way to write the defect and nothing else, which measured out as a FALSE GREEN:
        // inserting `errorText = ppChatBoundReflectedText(errorText);` on the line AFTER the
        // render reproduces this bug in full — every `indexOf` below then reads the truncated
        // copy — and the whole PHPUnit and vitest suites stay green. Only the Playwright case
        // catches it, and the reason this file exists is to catch it in the sub-second loop.
        //
        // Reassignment is the general shape of that defect, so reassignment is what is banned:
        // the parameter must reach the classification as it arrived.
        $this->assertSame(
            0,
            preg_match_all('/errorText\s*=\s*ppChatBoundReflectedText/', $src),
            'errorText must never be reassigned to its bounded copy; the classification below it reads that variable (#793)'
        );

        // And within the renderer, the sink may be written ONCE. Wiring the bound and then
        // overwriting the element with the raw text on the next line reverts the render while
        // leaving every pattern in this file satisfied — presence of a call is not the same
        // claim as effect of it.
        //
        // Scoped to the function body rather than the file, because both sink names are
        // legitimately written twice at file scope: `msgBody.textContent` also carries the
        // streamed assistant text (which is model prose, not reflected error text, and is
        // deliberately unbounded), and `div.textContent` also builds the status line.
        $this->assertSame(
            1,
            preg_match_all('/msgBody\.textContent\s*=/', $this->functionBody($src, 'handleStreamError', '    ')),
            'handleStreamError must write its element exactly once, or a later write can undo the bound'
        );
        $this->assertSame(
            1,
            preg_match_all('/div\.textContent\s*=/', $this->functionBody($src, 'ppChatValidationItemRow', '')),
            'the finding row must write its element exactly once, or a later write can undo the bound'
        );
    }

    /**
     * One function's body, so a "written exactly once" claim can be scoped to the renderer that
     * owns the sink rather than to every use of that variable name in a 4000-line file.
     *
     * Closes on the first line that is a lone `}` at the function's own indent, which is this
     * file's consistent style. Deliberately not a brace counter: a test that reimplements a
     * JavaScript parser is a second thing to get wrong.
     */
    private function functionBody(string $src, string $name, string $indent): string
    {
        $matched = preg_match(
            '/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{(.*?)\n' . preg_quote($indent, '/') . '\}/s',
            $src,
            $m
        );

        $this->assertSame(1, $matched, sprintf('%s() must still be declared for this contract to be checkable', $name));

        return $m[1];
    }

    /**
     * The undo card keeps its OWN ceiling and is not quietly double-bounded.
     *
     * `ppChatUndoFailureText()` is deliberately outside the new helper: it carries its own
     * constant and its own headroom promise (#822). Routing it through
     * `ppChatBoundReflectedText()` as a "consistency" tidy-up would be invisible today, because
     * the two constants hold the same number and the second pass is a no-op — and would become
     * a real behaviour change the moment either constant moved. Cheap to forbid, expensive to
     * discover later.
     */
    public function testTheUndoCardIsNotRoutedThroughThisBound(): void
    {
        $this->assertSame(
            0,
            preg_match_all('/ppChatBoundReflectedText\s*\(\s*ppChatUndoFailureText/', $this->chatScriptSource()),
            'the undo card owns its own ceiling (PP_CHAT_UNDO_ERROR_MAX, #822); do not double-bound it'
        );
    }

    /**
     * The client still does NOT sanitize, and this is the assertion that keeps it that way.
     *
     * #793's body asks for `/[\p{Cc}\p{Cf}]+/gu` in JavaScript. v1.17.8 answered that request
     * on the SERVER and recorded why the JS twin must not exist: two definitions of "clean" can
     * only drift, and the client cannot repair invalid UTF-8 the way `_pp_clean_reflected_text()`
     * does. Anyone re-reading the issue body without the CHANGELOG beside it will reach for the
     * twin again; this makes that a red test rather than a merged one.
     *
     * Its limits, stated honestly. It pins ONE spelling: a sanitizer written as an explicit
     * code-point enumeration would pass. It catches the copy-the-issue-body path, which is the
     * one the issue body itself makes likely, not every conceivable reimplementation. And it
     * searches the WHOLE script including prose, so a future maintainer cannot quote these two
     * character classes even to EXPLAIN the ruling — which is a real cost and the reason this
     * docblock names them instead. If that ever blocks a legitimate comment, scope the
     * assertion to non-comment source; do not delete it.
     */
    public function testTheClientStillHasNoCharacterClassSanitizer(): void
    {
        $src = $this->chatScriptSource();

        $this->assertStringNotContainsString(
            '\p{Cc}',
            $src,
            'control-character stripping is single-owned by _pp_clean_reflected_text() on the server (#647); the JS twin was ruled out in v1.17.8'
        );
        $this->assertStringNotContainsString(
            '\p{Cf}',
            $src,
            'format-character stripping is single-owned by _pp_clean_reflected_text() on the server (#647); the JS twin was ruled out in v1.17.8'
        );
    }
}
