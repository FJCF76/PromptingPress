<?php
/**
 * tests/ChatUndoBoundTrait.php — the PHP side of the chat undo card's rendering contract (#822).
 *
 * WHY A PHP TEST READS A JAVASCRIPT FILE AT ALL, since this is the repo's first such coupling
 * and the pattern will get copied. #822's fix lives entirely in `assets/js/pp-ai-chat.js`: the
 * chat's "Undo these changes" link used to render a refused restore as the two words "Undo
 * failed" and discard the server's message. The messages it now renders are built HERE, in
 * PHP, and two of their properties are only checkable from this side:
 *
 *   - they must FIT the client's bound, or the bound cuts off the actionable clause. Both
 *     refusals put it LAST — `history_entry_not_restorable` ends with
 *     `wp pp operate composition-history --post_id=N`, `history_target_shifted` ends with the
 *     re-selection advice — so a truncation removes exactly the sentence the fix exists to
 *     show, and every JavaScript test would still pass.
 *   - the bound must stay the SERVER'S number. `PP_CHAT_UNDO_ERROR_MAX` is a hand-copy of
 *     `PP_REFLECTED_ERROR_MAX` (lib/ai-chat.php), and its docblock's whole argument is that
 *     copying it keeps ONE answer to "how long may a reflected error be". Nothing but an
 *     assertion makes that true; without one the two silently diverge and the docblock becomes
 *     a false statement about a number.
 *
 * AND ONE PROPERTY OF THE WIRING, for a reason worth stating: the single line that fixes #822
 * — the renderer call in the undo link's failure branch — is otherwise reachable by NO unit
 * test in either language. `buildPostApplyCard()` is nested inside the chat script's DOM-ready
 * closure and is not exported, so vitest can only reach the exported helpers, which keep
 * passing if the call site is deleted. Measured, not assumed: deleting that one line in a
 * scratch copy of the tree left both suites green and only the Playwright spec red. A static
 * grep is a poor test of behavior and an excellent tripwire for a deletion, and it runs in the
 * sub-second loop rather than in a 30-minute browser suite.
 *
 * THE REGEXES ARE DELIBERATELY LOOSE about spelling and whitespace and strict about the thing
 * being asserted. A test that breaks when someone reformats the file, or modernizes `var` to
 * `const`, teaches maintainers to delete tripwires.
 *
 * Used by CompositionHistoryRawPreservationTest (which owns the #818 fixture) and
 * CompositionRestoreSelectorLockTest (which owns the #829 one), so each refusal is measured
 * where its state is already seeded.
 */

trait ChatUndoBoundTrait
{
    /** The shipped chat script, read once per call — small, and never cached across a mutation. */
    private function chatScriptSource(): string
    {
        $path = dirname(__DIR__) . '/assets/js/pp-ai-chat.js';
        $this->assertFileExists($path, 'the chat script this contract is shared with must exist');

        return (string) file_get_contents($path);
    }

    /**
     * `PP_CHAT_UNDO_ERROR_MAX` as the shipped chat script declares it.
     *
     * Read rather than restated: the point of every assertion below is that the two sides
     * cannot drift, and a second copy of the number here would BE the drift.
     */
    private function chatUndoErrorMax(): int
    {
        $matched = preg_match(
            '/(?:var|let|const)\s+PP_CHAT_UNDO_ERROR_MAX\s*=\s*(\d+)\s*;/',
            $this->chatScriptSource(),
            $m
        );
        $this->assertSame(1, $matched, 'the chat script must still declare the bound this test reads');

        return (int) $m[1];
    }

    /**
     * The bound is the server's own number for this species, and stays it.
     *
     * `PP_REFLECTED_ERROR_MAX` is what `_pp_clean_reflected_text()` allows a reflected WP_Error
     * on the PREVIEW path. The EXECUTE path the undo card reads does not pass through that
     * helper — `_pp_ai_execute_error_payload()` reflects `$result['error']` verbatim — so the
     * client's constant is the only bound this message ever meets, and it is only "one answer"
     * while the two match.
     */
    private function assertChatUndoBoundTracksTheServer(): void
    {
        $this->assertSame(
            PP_REFLECTED_ERROR_MAX,
            $this->chatUndoErrorMax(),
            'PP_CHAT_UNDO_ERROR_MAX claims to be the server\'s reflected-error budget; keep them equal or drop that claim'
        );
    }

    /**
     * A refusal the undo card must be able to render WHOLE.
     *
     * Two assertions, because "fits" and "fits with room" fail at different times. The first is
     * the contract: the client truncates only when the length EXCEEDS the bound, so equality
     * still renders whole and `assertLessThanOrEqual` is the matching comparison. The second is
     * the tripwire that can actually fire — at ~370 and ~690 characters against 4096 the first
     * assertion only catches an order-of-magnitude mistake, while requiring genuine headroom
     * catches a bound quietly lowered toward the messages.
     *
     * UNITS, stated because the repo is careful about this elsewhere: the client bound counts
     * UTF-16 code units (`String.length`) and this counts characters. They coincide for these
     * messages, which are ASCII, and `mb_strlen` is the closer of the two available measures —
     * `strlen` would count bytes and silently over-count any non-ASCII refusal.
     */
    private function assertChatUndoCardCanRenderWhole(string $message): void
    {
        $max    = $this->chatUndoErrorMax();
        $length = mb_strlen($message, 'UTF-8');

        $this->assertLessThanOrEqual(
            $max,
            $length,
            'the refusal must fit whole inside the bound the chat card renders it under'
        );
        $this->assertGreaterThanOrEqual(
            $length * 2,
            $max,
            'the bound must keep real headroom over the message, not merely exceed it'
        );
    }

    /**
     * The undo failure branch still routes the payload to the renderer.
     *
     * EXACTLY ONE CALL SITE is the assertion, and both halves of that matter. One proves the
     * wiring #822 added is still there. Not more than one proves it has not been copied onto
     * the transport-failure `.catch`, which is deliberately the branch with no server message
     * to render — an "obvious symmetry fix" that would put a row on a card whose request never
     * came back.
     */
    private function assertChatUndoFailureRendererIsWired(): void
    {
        $this->assertSame(
            1,
            preg_match_all(
                '/ppChatAppendUndoFailure\s*\(\s*card\s*,\s*resp\.data\s*\)/',
                $this->chatScriptSource()
            ),
            'the undo failure branch must call ppChatAppendUndoFailure(card, resp.data) exactly once (#822)'
        );
    }
}
