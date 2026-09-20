<?php

/**
 * THE LAYOUT GROUP'S GRAMMARS (#1084, docs/v2/LAYOUT-GROUP-CONTRACT.md §4).
 *
 * Six properties the Layout group claimed from the STRUCTURAL-only set. Every
 * assertion here runs through `_pp_validate_token_value()` — the ONE dispatcher —
 * rather than calling the private validators directly, because that is the path
 * both the write gate and the render boundary take. A test that called the
 * helpers would pass even if the dispatcher never routed the type.
 *
 * TWO THINGS THIS FILE IS DELIBERATELY STRICT ABOUT:
 *
 * 1. WIDTH, not tidiness. Claiming a property types every `_css` write of it
 *    (lib/udc.php pp_udc_css_param()), and a stored value that stops validating
 *    locks an UNRELATED band's edit, because update_composition validates the whole
 *    composition and every read surface re-validates stored ones (the #1007 class).
 *    So `safe center` and friends are pinned as ACCEPTED: they are valid CSS and
 *    `_css` took them before the registry claimed the property.
 * 2. DEAD VALUES, refused — and the rule is narrower than "anything that computes
 *    to zero", which is why it is spelled out. A zero FRACTION (`0fr`), a zero
 *    REPEAT (`repeat(0, 1fr)`) and a NEGATIVE track are refused: none of them is a
 *    design anyone means, and each one validates green and paints nothing, which is
 *    the I19 class. A zero LENGTH (`0px 1fr`) is ACCEPTED, deliberately: a
 *    deliberately collapsed track is a real layout — it is how an author hides a
 *    column at one breakpoint and keeps the grid's shape — and refusing it would be
 *    this engine inventing a constraint CSS does not have (the #988 lesson). The
 *    first draft of this docblock claimed the wider rule and the tests only proved
 *    the narrow one; the pre-landing testing pass caught the gap between them.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class UdcLayoutGrammarTest extends TestCase
{
    /** @return array<int, mixed> */
    private function ok(string $value, string $type): void
    {
        $result = _pp_validate_token_value($value, $type);
        $this->assertTrue(
            $result === true || $result === null,
            sprintf('%s "%s" must be accepted, got: %s', $type, $value, $this->reason($result))
        );
    }

    private function no(string $value, string $type, string $why): void
    {
        $result = _pp_validate_token_value($value, $type);
        $this->assertInstanceOf(
            \WP_Error::class,
            $result,
            sprintf('%s "%s" must be refused — %s', $type, $value, $why)
        );
    }

    private function reason($result): string
    {
        return $result instanceof \WP_Error ? $result->get_error_message() : var_export($result, true);
    }

    public function testFlexDirectionTakesTheFourCssKeywordsAndNothingElse(): void
    {
        foreach (['row', 'column', 'row-reverse', 'column-reverse', 'ROW', ' column '] as $good) {
            $this->ok($good, 'flex-direction');
        }
        $this->no('vertical', 'flex-direction', 'not a CSS keyword');
        $this->no('row column', 'flex-direction', 'flex-direction takes one keyword');
        $this->no('inherit', 'flex-direction', 'the engine accepts no global CSS keywords');
    }

    public function testFlexWrapTakesTheThreeCssKeywords(): void
    {
        foreach (['wrap', 'nowrap', 'wrap-reverse'] as $good) {
            $this->ok($good, 'flex-wrap');
        }
        $this->no('none', 'flex-wrap', '"none" is not a flex-wrap value');
        $this->no('wrap wrap', 'flex-wrap', 'one keyword only');
    }

    /**
     * THE VOCABULARY IS SHARED, THE EXTRAS ARE NOT — and the extras are where a
     * copy-pasted keyword list would have drifted. `space-between` belongs to
     * justify-content alone, `auto` and `self-start` to align-self alone, and
     * `left`/`right` are inline-axis words that mean nothing on the block axis.
     */
    public function testTheBoxAlignmentVocabularyIsSharedButItsPerPropertyExtrasAreNot(): void
    {
        foreach (['center', 'start', 'end', 'flex-start', 'flex-end', 'stretch', 'normal', 'baseline'] as $shared) {
            $this->ok($shared, 'justify-content');
            $this->ok($shared, 'align-items');
            $this->ok($shared, 'align-self');
        }

        foreach (['space-between', 'space-around', 'space-evenly', 'left', 'right'] as $distribution) {
            $this->ok($distribution, 'justify-content');
            $this->no($distribution, 'align-items', 'a distribution/inline-axis value is justify-content\'s alone');
            $this->no($distribution, 'align-self', 'a distribution/inline-axis value is justify-content\'s alone');
        }

        $this->ok('auto', 'align-self');
        $this->no('auto', 'align-items', '"auto" is a self-property value');
        $this->no('auto', 'justify-content', '"auto" is a self-property value');

        $this->ok('self-start', 'align-self');
        $this->ok('self-end', 'align-self');
        $this->no('self-start', 'align-items', 'self-* positions an item, not a container\'s children');

        foreach (['first baseline', 'last baseline'] as $twoWord) {
            $this->ok($twoWord, 'align-items');
            $this->ok($twoWord, 'align-self');
        }
    }

    /**
     * THE `safe`/`unsafe` PREFIXES ARE THE POINT OF §3.2, so they get their own
     * test: a closed "positional keywords only" set would have refused a value CSS
     * accepts and `_css` already stored, turning an unrelated edit into a page
     * lockout. They qualify a POSITION only — never a distribution, a baseline or
     * `stretch` — which is what stops the rule becoming "any two words".
     */
    public function testTheOverflowAlignmentPrefixesAreAcceptedOnPositionsAndNowhereElse(): void
    {
        foreach (['safe center', 'unsafe center', 'safe flex-end', 'unsafe start'] as $good) {
            $this->ok($good, 'align-items');
            $this->ok($good, 'justify-content');
        }
        $this->ok('safe self-end', 'align-self');

        $this->no('safe stretch', 'align-items', 'stretch is not a positional value');
        $this->no('safe baseline', 'align-items', 'baseline is not a positional value');
        $this->no('safe space-between', 'justify-content', 'a distribution is not a positional value');
        $this->no('safe', 'align-items', 'the prefix alone is not a value');
        $this->no('maybe center', 'align-items', 'only safe/unsafe qualify a position');
        $this->no('safe safe center', 'align-items', 'one prefix');
    }

    public function testAColumnCountIsAcceptedWithinItsBoundAndNothingHalfway(): void
    {
        foreach (['1', '2', '4', '12'] as $good) {
            $this->ok($good, 'track-list');
        }
        $this->no('0', 'track-list', 'zero columns is not a layout');
        $this->no('13', 'track-list', 'the recorded bound is 12');
        $this->no('2.5', 'track-list', 'half a column is not a thing');
        $this->no('-2', 'track-list', 'a negative count is not a layout');
    }

    public function testATrackListTakesTheFormsTheShippedRawPathAlreadyAccepted(): void
    {
        foreach ([
            '1fr 1fr',
            '3fr 2fr',
            'minmax(0, 1fr) minmax(0, 1fr)',
            'repeat(4, minmax(0, 1fr))',
            'repeat(auto-fit, minmax(20rem, 1fr))',
            'repeat(auto-fill, minmax(20rem, 1fr))',
            '240px 1fr',
            '25% 75%',
            'auto 1fr auto',
            'min-content max-content',
            'minmax(0, 1.08fr) minmax(0, 0.92fr)',
            // A deliberately collapsed track: accepted, per the rule stated in the
            // file docblock. Pinned as ACCEPTED so the refusals below cannot be
            // widened into it by accident.
            '0px 1fr',
            '0% 1fr',
        ] as $good) {
            $this->ok($good, 'track-list');
        }
    }

    /**
     * THE RED PROOFS (§4). Each one is a value that would either crash a naive
     * parser, paint nothing, or reach the stylesheet as something other than a
     * track list.
     */
    public function testTheTrackListGrammarRefusesTheHostileAndTheDeadAlike(): void
    {
        $this->no('repeat(2, repeat(2, 1fr))', 'track-list', 'repeat() does not nest, in CSS or here');
        $this->no('minmax(0, 1fr', 'track-list', 'unbalanced parentheses must fail as a whole value');
        $this->no('minmax(0, 1fr))', 'track-list', 'a stray closing paren must fail');
        $this->no('-1fr', 'track-list', 'a negative track is not a size');
        $this->no('0fr', 'track-list', 'a zero track paints nothing (the I19 class)');
        $this->no('repeat(0, 1fr)', 'track-list', 'a zero repeat paints nothing');
        $this->no('repeat(40, 1fr)', 'track-list', 'past the recorded 12-track bound');
        $this->no('repeat(auto-fit)', 'track-list', 'repeat() needs a count AND a track');
        $this->no('repeat(auto, 1fr)', 'track-list', 'auto is not a repeat count');
        $this->no('1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr', 'track-list', '13 tracks is past the bound');
        $this->no('calc(100% / 3) 1fr', 'track-list', 'calc() inside a track is not accepted in this cut');
        $this->no('subgrid', 'track-list', 'subgrid is not in this cut');
        $this->no('1fr, 1fr', 'track-list', 'tracks are space separated, not comma separated');
        // The three bounds the implementation states in prose and nothing pinned,
        // found by the pre-landing testing pass. Each behaves correctly today; a
        // later relaxation would have landed silently.
        $this->no('repeat(2, 1fr) repeat(2, 1fr)', 'track-list', 'one repeat() per list is the stated bound');
        $this->no('minmax(0)', 'track-list', 'minmax() takes exactly two tracks');
        $this->no('minmax(0, 1fr, 2fr)', 'track-list', 'minmax() takes exactly two tracks');
        $this->no('-10px 1fr', 'track-list', 'a negative LENGTH track, distinct from the -1fr case above');
        $this->no('1fr -10px', 'track-list', 'and in either position');
        // EMPTY PARTS, found by the pre-landing security pass. Each of these
        // validated and reached the stylesheet verbatim: the browser drops the
        // malformed declaration and KEEPS the engine's display:grid companion, so a
        // flex row silently became an untracked grid with nothing reported.
        $this->no('repeat(2, )', 'track-list', 'a repeat() with no track is not a track list');
        $this->no('repeat(2,,1fr)', 'track-list', 'two commas are two delimiters with an empty item between');
        $this->no('repeat(2,1fr,)', 'track-list', 'a trailing comma leaves an empty track');
        $this->no('minmax(0,)', 'track-list', 'minmax() needs both of its two tracks');
        $this->no('minmax(,1fr)', 'track-list', 'in either position');

        // The shared reject set still owns the injection classes, ahead of the
        // grammar — pinned here because this is the first type whose values carry
        // parentheses AND commas, the shape those gates exist for.
        foreach ([
            'url(http://evil.test/x.png)',
            'image-set("x.png" 1x)',
            '1fr; color: red',
            '1fr } body {',
            '1fr /* x */',
            '@import "x"',
        ] as $hostile) {
            $this->no($hostile, 'track-list', 'the shared injection gate owns this class');
        }
    }

    /**
     * VACUITY PROBE. Every assertion above is a claim about the DISPATCHER, so the
     * one failure that would make them all meaningless is a type that never
     * reaches it: an unrouted type falls through to "no type metadata, generic
     * validation only" and accepts almost anything. This proves the six types are
     * genuinely routed by showing a value that ONLY a routed type refuses.
     */
    public function testEachNewTypeIsActuallyRoutedByTheDispatcher(): void
    {
        $canary = 'definitely-not-a-layout-value';
        foreach (['flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-self', 'track-list'] as $type) {
            $this->assertInstanceOf(
                \WP_Error::class,
                _pp_validate_token_value($canary, $type),
                sprintf('type "%s" must be routed — an unrouted type accepts this string', $type)
            );
        }
        // The control: the same string against no type at all IS accepted, which is
        // what makes the six assertions above evidence rather than coincidence.
        $this->assertTrue(
            _pp_validate_token_value($canary, null) === true || _pp_validate_token_value($canary, null) === null,
            'the untyped path accepts the canary — otherwise the probe proves nothing'
        );
    }
}
