<?php
/**
 * tests/UdcMultiValueLengthTest.php
 *
 * A clamp()/calc() length inside a MULTI-VALUE length parameter (#1191).
 *
 * `spacing.padding`, `spacing.margin`, `border.width` and `border.radius` take one to four
 * space-separated lengths, and the documented grammar says a length may be a clamp()/calc()
 * expression. CSS REQUIRES spaces around `+` and `-` inside calc(), and clamp() is written with
 * a space after each comma, so a fluid length carries spaces of its own. The tokenizer used to
 * split on EVERY space, so `clamp(1.75rem, 1.2rem + 1.5vw, 2.25rem)` counted as five values and
 * `0 clamp(1rem, 2vw, 3rem)` produced a token `clamp(1rem,` that no grammar accepts. The
 * documented value was refused on all four parameters (and on `gap`, pinned separately below)
 * while the same clamp() passed on every single-value longhand.
 *
 * The split is now PARENTHESIS-AWARE: the one grammar owner (pp_udc_validate_value()) calls the
 * shared top-level splitter (_pp_css_split_top_level(), lib/apply.php), so the write gate, the
 * emit-time re-validation and the `_css` typed path agree without a second rule.
 *
 * SECTION 14.1: the accept/refuse matrices, the update_composition, `_css`, gap and border.style
 * cases are authored through the real action surface (create_page / update_composition), not a
 * raw `_pp_composition` meta write, and the accepted value is read back out of the page CSS the
 * stored composition emits. The tests that pin gate ORDER, COST and PARITY call the owner,
 * pp_udc_validate_value(), directly on purpose: their subject is the owner itself.
 *
 * The refusals are half the contract: five values are still five values, a clamp() does not
 * smuggle a fifth one in, and an unclosed parenthesis is still refused, because v2 emits CSS
 * SOURCE TEXT where a dangling `(` swallows every rule after it.
 */

use PHPUnit\Framework\TestCase;

final class UdcMultiValueLengthTest extends TestCase
{
    private const CLAMP  = 'clamp(1rem, 0.8rem + 1vw, 2rem)';
    private const CALC   = 'calc(1rem + 2px)';
    /** Grouping parentheses inside a clamp() argument: nested parens, one level down. */
    private const NESTED = 'clamp((1rem + 1px) * 1.5, 2vw, (3rem - 2px))';
    private const GROUPED = 'calc((1rem + 2px) * 2)';

    /** The four one-to-four length parameters (gap, the fifth list, is pinned separately below). */
    private const PARAMS = [
        'spacing.padding' => ['spacing', 'padding', 'padding'],
        'spacing.margin'  => ['spacing', 'margin', 'margin'],
        'border.width'    => ['border', 'width', 'border-width'],
        'border.radius'   => ['border', 'radius', 'border-radius'],
    ];

    private function band(string $group, string $param, string $value): array
    {
        return [
            'component' => 'testimonials',
            'props'     => ['items' => [['quote' => 'They shipped in six weeks.', 'author' => 'Ada Lovelace', 'role' => 'CTO', 'company' => 'Analytical']]],
            'udc'       => ['card' => [$group => [$param => $value]]],
        ];
    }

    private function createPage(array $band): array
    {
        return pp_execute_action('create_page', ['title' => 'Fluid lengths', 'composition' => [$band]]);
    }

    private function assertAcceptedAndPainted(array $result, string $group, string $param, string $property, string $value): void
    {
        $this->assertTrue(
            $result['ok'] ?? false,
            sprintf('%s.%s "%s" is a documented-valid length list and must be accepted; got: %s',
                $group, $param, $value, $result['error'] ?? var_export($result, true))
        );
        $post_id = (int) $result['target']['post_id'];
        $stored  = pp_get_composition($post_id);
        $this->assertSame($value, $stored[0]['udc']['card'][$group][$param], 'stored verbatim, no coercion');
        $this->assertStringContainsString(
            $property . ':' . $value,
            pp_udc_page_css($stored),
            'what was stored is what paints: the whole list reaches the stylesheet as one declaration'
        );
    }

    /** @return array<string, array{string, string}> */
    public static function fluidLists(): array
    {
        $values = [
            'single clamp (the #1191 report)' => 'clamp(1.75rem, 1.2rem + 1.5vw, 2.25rem)',
            'single calc'                     => self::CALC,
            'clamp at position 1 of 4'        => self::CLAMP . ' 1px 2px 3px',
            'clamp at position 2 of 4'        => '1px ' . self::CLAMP . ' 2px 3px',
            'clamp at position 3 of 4'        => '1px 2px ' . self::CLAMP . ' 3px',
            'clamp at position 4 of 4'        => '1px 2px 3px ' . self::CLAMP,
            'calc at position 1 of 4'         => self::CALC . ' 1px 2px 3px',
            'calc at position 2 of 4'         => '1px ' . self::CALC . ' 2px 3px',
            'calc at position 3 of 4'         => '1px 2px ' . self::CALC . ' 3px',
            'calc at position 4 of 4'         => '1px 2px 3px ' . self::CALC,
            'zero then clamp'                 => '0 clamp(1rem, 2vw, 3rem)',
            'calc 1rem calc 0'                => 'calc(1rem + 2px) 1rem calc(2rem - 1px) 0',
            'grouping parens inside clamp'    => self::NESTED . ' 0',
            'grouping parens inside calc'     => '1px ' . self::GROUPED,
            'four fluid values'               => self::CLAMP . ' ' . self::CALC . ' ' . self::NESTED . ' ' . self::CLAMP,
            'a run of spaces between values'  => '1px  2px',
            'a run of spaces before a clamp'  => '0   ' . self::CLAMP,
        ];
        $cases = [];
        foreach (array_keys(self::PARAMS) as $name) {
            foreach ($values as $label => $value) {
                $cases[$name . ': ' . $label] = [$name, $value];
            }
        }
        return $cases;
    }

    /** @dataProvider fluidLists */
    public function testAFluidLengthIsAcceptedAtEveryPositionThroughCreatePage(string $name, string $value): void
    {
        [$group, $param, $property] = self::PARAMS[$name];
        $this->assertAcceptedAndPainted($this->createPage($this->band($group, $param, $value)), $group, $param, $property, $value);
    }

    /** The same contract through the other whole-composition write, on the reported value. */
    public function testTheReportedPanelPaddingIsAcceptedThroughUpdateComposition(): void
    {
        foreach (self::PARAMS as [$group, $param, $property]) {
            $value   = '0 clamp(1.75rem, 1.2rem + 1.5vw, 2.25rem)';
            $post_id = pp_create_page('Update ' . $param, 'draft');
            $result  = pp_execute_action('update_composition', [
                'post_id'     => $post_id,
                'composition' => [$this->band($group, $param, $value)],
            ]);
            $this->assertTrue($result['ok'] ?? false, sprintf('%s.%s via update_composition: %s', $group, $param, $result['error'] ?? ''));
            $stored = pp_get_composition($post_id);
            $this->assertSame($value, $stored[0]['udc']['card'][$group][$param]);
            $this->assertStringContainsString($property . ':' . $value, pp_udc_page_css($stored));
        }
    }

    /**
     * `_css` keeps a known property's grammar (pp_udc_css_param() copies the registry entry), so
     * the raw channel must agree with the parameter: accepted there iff accepted here.
     */
    public function testTheCssChannelAgreesWithTheParameter(): void
    {
        $value  = self::CLAMP . ' 0';
        $band   = $this->band('spacing', 'padding', '1px');
        $band['udc']['card'] = [PP_UDC_CSS_KEY => ['padding' => $value, 'border-radius' => self::NESTED]];
        $result = $this->createPage($band);
        $this->assertTrue($result['ok'] ?? false, '`_css` padding/border-radius with a fluid length: ' . ($result['error'] ?? ''));
        $css = pp_udc_page_css(pp_get_composition((int) $result['target']['post_id']));
        $this->assertStringContainsString('padding:' . $value, $css);
        $this->assertStringContainsString('border-radius:' . self::NESTED, $css);

        $band['udc']['card'] = [PP_UDC_CSS_KEY => ['padding' => '1px 2px 3px 4px ' . self::CLAMP]];
        $this->assertFalse($this->createPage($band)['ok'] ?? true, '`_css` still refuses a fifth value');
    }

    /** @return array<string, array{string, string}> */
    public static function refusedLists(): array
    {
        $values = [
            'five plain values'           => ['1px 2px 3px 4px 5px', 'at most 4'],
            'four plain values + a clamp' => ['1px 2px 3px 4px ' . self::CLAMP, 'at most 4'],
            'a clamp + four plain values' => [self::CLAMP . ' 1px 2px 3px 4px', 'at most 4'],
            'five fluid values'           => [str_repeat(self::CALC . ' ', 4) . self::CALC, 'at most 4'],
            'unclosed clamp'              => ['clamp(1rem, 2vw', 'unbalanced'],
            'stray closing paren'         => ['1rem)', 'unbalanced'],
            'unclosed clamp then a value' => ['clamp(1rem, 2vw 0', 'unbalanced'],
            'closer before opener'        => ['1rem) (2rem', 'unbalanced'],
            'two lengths glued by parens' => ['calc(1rem)calc(2rem)', 'CSS unit'],
            'a word inside calc'          => ['calc(1rem + var(--x)) 0', 'CSS unit'],
        ];
        $cases = [];
        foreach (array_keys(self::PARAMS) as $name) {
            foreach ($values as $label => [$value, $why]) {
                $cases[$name . ': ' . $label] = [$name, $value, $why];
            }
        }
        return $cases;
    }

    /** @dataProvider refusedLists */
    public function testTheRefusalsStillRefuseThroughCreatePage(string $name, string $value, string $why): void
    {
        [$group, $param] = self::PARAMS[$name];
        $result = $this->createPage($this->band($group, $param, $value));
        $this->assertFalse($result['ok'] ?? true, sprintf('%s.%s "%s" must be refused', $group, $param, $value));
        $this->assertStringContainsString($why, $result['error'] ?? '', 'refused for the right reason');
    }

    /**
     * A breakpoint map mints each literal as a band token, and emit re-checks the token against
     * the referencing parameter's grammar (a failure there is a SILENT drop, not a refusal).
     * A fluid list in a map must be accepted, painted per tier, and a five-value tier refused.
     */
    public function testAFluidListInABreakpointMapIsAcceptedAndPaintsEachTier(): void
    {
        $map  = ['d' => '0 clamp(1.75rem, 1.2rem + 1.5vw, 2.25rem)', 'p' => '0 ' . self::CALC];
        $band = $this->band('spacing', 'padding', '1px');
        $band['udc']['card']['spacing']['padding'] = $map;
        $result = $this->createPage($band);
        $this->assertTrue($result['ok'] ?? false, 'breakpoint map with fluid lists: ' . ($result['error'] ?? ''));
        $css = pp_udc_page_css(pp_get_composition((int) $result['target']['post_id']));
        $this->assertStringContainsString(':' . $map['d'] . ';', $css, 'desktop tier token carries the whole list');
        $this->assertStringContainsString(':' . $map['p'] . ';', $css, 'phone tier token carries the whole list');
        $this->assertMatchesRegularExpression('/@media \(max-width: 767px\)\{[^}]*padding:var\(--pp-card-spacing-padding-p\)/', $css);

        $band['udc']['card']['spacing']['padding'] = ['d' => '1px', 'p' => '1px 2px 3px 4px ' . self::CLAMP];
        $bad = $this->createPage($band);
        $this->assertFalse($bad['ok'] ?? true, 'a five-value tier is still five values');
        $this->assertStringContainsString('at most 4', $bad['error'] ?? '');
    }

    /** A state map carries the same grammar: a fluid list under :hover paints as one declaration. */
    public function testAFluidListUnderAHoverStateIsAccepted(): void
    {
        $value = '0 clamp(1rem, 2vw + 1px, 3rem)';
        $band  = $this->band('spacing', 'padding', '1px');
        $band['udc']['card']['spacing'][':hover'] = ['padding' => $value];
        $result = $this->createPage($band);
        $this->assertTrue($result['ok'] ?? false, ':hover fluid list: ' . ($result['error'] ?? ''));
        // Pinned INSIDE the :hover rule, so a value emitted on the base selector cannot pass.
        $this->assertStringContainsString(':hover{padding:' . $value . ';}', pp_udc_page_css(pp_get_composition((int) $result['target']['post_id'])));
    }

    /**
     * The split walks parentheses, not strings, while the delimiter gate walks strings too. A
     * string that opens with `)` clears the gate (equal counts, and the walk skips string
     * content) while the split reads that `)` as a closer and goes below zero. No length
     * carries a quote, so the value is refused either way; this pins that the disagreement ends
     * in a refusal, never in a guessed split.
     */
    public function testAValueTheSplitCannotPairIsRefused(): void
    {
        $value = '0 ")("';
        $this->assertTrue(_pp_udc_delimiters_balanced($value), 'precondition: the delimiter gate lets this through');
        $result = pp_udc_validate_value($value, pp_udc_groups()['spacing']['params']['padding']);
        $this->assertInstanceOf(WP_Error::class, $result);
        // Refused BY THE SPLIT, not by a later grammar reading a guessed token.
        $this->assertSame('invalid_udc_value', $result->get_error_code());
        $this->assertStringContainsString('parentheses pair up', $result->get_error_message());
    }

    /**
     * THE COST IS LINEAR. Emit re-validates every stored value on every request, so the split
     * must not grow faster than its input (learning
     * pp-a-recursive-css-grammar-is-a-dos-vector-because-emit-revalidates). Pinned SCALE-
     * RELATIVELY rather than against a wall clock (pp-wall-clock-perf-pins-fail-both-ways):
     * N and 8N are timed in the same process, interleaved so a burst of load hits both sizes,
     * and machine speed cancels out. Linear predicts 8x and quadratic 64x; 22 sits on the log
     * midpoint, which leaves a wide margin either way on a busy CI box. The denominator is
     * floored so timer granularity on a fast run cannot inflate the ratio.
     *
     * Two shapes, because cost can grow on two axes: bytes INSIDE one entry (the walk steps
     * over a long clamp/calc), and the NUMBER of top-level entries (the split emits a part per
     * separator). The many-entries value is refused on arity, but only after the whole split
     * has run, so its time is the split's.
     */
    public function testTheSplitCostGrowsLinearlyWithTheValue(): void
    {
        $param = pp_udc_groups()['spacing']['params']['padding'];
        // CPU time, not wall clock: a run preempted on a busy CI box is charged only for the
        // time it actually ran, so oversubscription cannot inflate one size and not the other.
        // USER + SYSTEM together: the kernel can move a whole scheduler tick from one to the
        // other inside a single call, which makes user time alone read low on the small input
        // (the denominator); their sum stays exact.
        $cpu   = static function (): float {
            $u = getrusage();
            return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6
                + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
        };
        $ratio = static function (string $small, string $large, ?callable $run = null) use ($param, $cpu): float {
            $run ??= static fn (string $v) => pp_udc_validate_value($v, $param);
            $best = [INF, INF];
            for ($i = 0; $i < 7; $i++) {
                foreach ([$small, $large] as $k => $v) {
                    $t0       = $cpu();
                    $run($v);
                    $best[$k] = min($best[$k], $cpu() - $t0);
                }
            }
            return $best[1] / max($best[0], 0.0005);
        };

        // Axis 1: one long entry whose inner spaces the walk steps over (accepted at both sizes).
        $inside = static fn (int $terms): string => 'calc(1px' . str_repeat(' + 1px', $terms) . ') 0';
        $this->assertTrue(pp_udc_validate_value($inside(2000), $param));
        $this->assertTrue(pp_udc_validate_value($inside(16000), $param));
        $r1 = $ratio($inside(2000), $inside(16000));
        // The split ALONE too: in the whole validator the calc grammar that runs after the
        // split is ~80% of the cost and linear, which dilutes a quadratic split toward the limit.
        $split = $ratio($inside(2000), $inside(16000), static fn (string $v) => _pp_css_split_top_level($v));
        $this->assertLessThan(22.0, $split, sprintf('the split alone: 8x the bytes took %.1fx the time; linear ~8x, quadratic ~64x', $split));
        $this->assertLessThan(22.0, $r1, sprintf('8x the bytes in one entry took %.1fx the time; linear ~8x, quadratic ~64x', $r1));

        // Axis 2: many top-level entries (refused on arity, after the full split).
        $entries = static fn (int $n): string => trim(str_repeat('0 ', $n));
        $refused = pp_udc_validate_value($entries(64000), $param);
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertStringContainsString('at most 4', $refused->get_error_message());
        $r2 = $ratio($entries(8000), $entries(64000));
        $this->assertLessThan(22.0, $r2, sprintf('8x the entries took %.1fx the time; linear ~8x, quadratic ~64x', $r2));
    }

    /**
     * Every OTHER max_values > 1 parameter goes through the same line. border.style is a keyword
     * list: grouping parentheses there must not let a length in.
     */
    public function testAKeywordListStillRefusesAFluidLength(): void
    {
        $result = $this->createPage($this->band('border', 'style', 'solid ' . self::CLAMP));
        $this->assertFalse($result['ok'] ?? true);
        $this->assertTrue($this->createPage($this->band('border', 'style', 'solid dashed none dotted'))['ok'] ?? false);
        $five = $this->createPage($this->band('border', 'style', 'solid dashed none dotted solid'));
        $this->assertFalse($five['ok'] ?? true, 'five keywords are five values');
        $this->assertStringContainsString('at most 4', $five['error'] ?? '');
    }

    /**
     * An empty or all-space list is refused before the split. The split collapses whitespace
     * runs, so on its own it would return ZERO parts, and a zero-part list would clear the
     * arity check with nothing type-checked: the empty-value gate ahead of it is what holds.
     */
    public function testAnEmptyOrAllSpaceListIsRefused(): void
    {
        foreach (['spacing.padding', 'border.radius'] as $name) {
            [$group, $param] = self::PARAMS[$name];
            foreach (['', '   '] as $value) {
                $result = pp_udc_validate_value($value, pp_udc_groups()[$group]['params'][$param]);
                $this->assertInstanceOf(WP_Error::class, $result, sprintf('%s %s', $name, json_encode($value)));
                $this->assertSame('empty_value', $result->get_error_code());
            }
        }
        $this->assertSame('empty_value', pp_udc_validate_value('   ', pp_udc_groups()['spacing']['params']['gap'])->get_error_code());
        $this->assertSame('empty_value', pp_udc_validate_value('', pp_udc_groups()['border']['params']['style'])->get_error_code());
    }

    /**
     * The model is told each list's arity in one sentence of the runtime prompt. Derived from
     * the registry, so a changed `max_values` fails here instead of leaving the prompt wrong.
     */
    public function testThePromptStatesEachListArityTheRegistryEnforces(): void
    {
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $prompt = pp_ai_system_prompt();
        $groups = pp_udc_groups();
        $this->assertSame(4, $groups['spacing']['params']['padding']['max_values']);
        $this->assertSame(4, $groups['spacing']['params']['margin']['max_values']);
        $this->assertSame(4, $groups['border']['params']['width']['max_values']);
        $this->assertSame(4, $groups['border']['params']['radius']['max_values']);
        $this->assertStringContainsString('`padding`, `margin`, `border.width`, `border.radius` take 1-4 space-separated lengths', $prompt);
        $this->assertStringContainsString('`gap` 1-' . $groups['spacing']['params']['gap']['max_values'], $prompt);
        $this->assertStringContainsString('`border.style` 1-' . $groups['border']['params']['style']['max_values'] . ' keywords', $prompt);
        $this->assertStringContainsString('a `clamp()`/`calc()` is ONE value', $prompt);
        // Every param with a list arity is one the sentence names.
        $listed = [];
        foreach ($groups as $group => $def) {
            foreach ($def['params'] as $param => $p) {
                if (($p['max_values'] ?? 1) > 1) {
                    $listed[] = $group . '.' . $param;
                }
            }
        }
        sort($listed);
        $this->assertSame(['border.radius', 'border.style', 'border.width', 'spacing.gap', 'spacing.margin', 'spacing.padding'], $listed);

        // The shipped instruction file states the same arities in prose (whitespace-normalised,
        // because the sentence wraps across lines).
        $words = [2 => 'two', 4 => 'four'];
        $doc   = preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__) . '/ai-instructions/style-component.md'));
        $this->assertStringContainsString('`padding`, `margin`, `border.width` and `border.radius` take one to four space-separated lengths', $doc);
        $this->assertStringContainsString('`gap` takes one or ' . $words[$groups['spacing']['params']['gap']['max_values']], $doc);
        $this->assertStringContainsString('`border.style` takes one to ' . $words[$groups['border']['params']['style']['max_values']] . ' keywords', $doc);
        $this->assertStringContainsString('A `clamp()`/`calc()` counts as ONE value', $doc);
    }

    /**
     * `\s` also matched CR, FF and VT, the space-aware split does not. That difference is
     * unreachable because the shared reject set refuses every control character first; pinned so
     * a change to that order is seen.
     */
    public function testControlWhitespaceIsRefusedBeforeTheSplit(): void
    {
        $param = pp_udc_groups()['spacing']['params']['padding'];
        foreach (["1rem\r2rem", "1rem\f2rem", "1rem\v2rem", "1rem\t2rem", "1rem\n2rem"] as $value) {
            $result = pp_udc_validate_value($value, $param);
            $this->assertInstanceOf(WP_Error::class, $result, 'control whitespace: ' . json_encode($value));
            $this->assertSame('injection', $result->get_error_code());
        }
    }

    /**
     * PARITY: a list entry is judged exactly as the same value alone. A calc() NESTED AS A FUNCTION inside a
     * clamp() is outside the shared length grammar (_pp_css_length() admits only unit words
     * as alphabetic runs inside a function, which is what keeps var()/url() out), so it is
     * refused on the single-value longhand too. Each value must get the SAME answer and the
     * SAME code as a list entry as it gets alone on padding-top — accepted or refused.
     */
    public function testAListEntryIsJudgedExactlyAsTheSameValueAlone(): void
    {
        $groups = pp_udc_groups();
        $pairs  = [
            [$groups['spacing']['params']['padding'], $groups['spacing']['params']['padding-top']],
            [$groups['spacing']['params']['margin'], $groups['spacing']['params']['margin-top']],
            [$groups['border']['params']['width'], $groups['border']['params']['width-top']],
            [$groups['border']['params']['radius'], $groups['border']['params']['radius-top-left']],
        ];
        $values = [
            'clamp(calc(1rem + 1px), 2vw, 3rem)', 'clamp(1rem, calc(1rem + 1vw), 3rem)', 'calc(1rem + calc(2px * 2))',
            self::CLAMP, self::CALC, self::NESTED, self::GROUPED, 'calc(1rem + var(--x))', '-1px',
        ];
        $refused  = 0;
        $accepted = 0;
        foreach ($pairs as [$list, $alone]) {
            foreach ($values as $value) {
                $single = pp_udc_validate_value($value, $alone);
                foreach (['%s 0', '0 %s', '0 0 %s 0'] as $shape) {
                    $inList = pp_udc_validate_value(sprintf($shape, $value), $list);
                    $this->assertSame(
                        $single === true ? true : $single->get_error_code(),
                        $inList === true ? true : $inList->get_error_code(),
                        sprintf('"%s" as a list entry (%s) must be judged as it is alone', $value, $list['property'])
                    );
                }
                $refused  += $single === true ? 0 : 1;
                $accepted += $single === true ? 1 : 0;
            }
        }
        // Non-vacuity: both answers occur (CLAMP, CALC, NESTED and GROUPED are accepted alone on
        // every pair), and the nested-function values are among the refusals.
        $this->assertGreaterThanOrEqual(4 * 4, $accepted);
        $this->assertGreaterThanOrEqual(4 * 3, $refused);
        $this->assertInstanceOf(WP_Error::class, pp_udc_validate_value('clamp(calc(1rem + 1px), 2vw, 3rem)', $groups['spacing']['params']['padding-top']));
    }

    /**
     * `spacing.gap` takes one or two lengths and goes through the same owner, so it gets the
     * same fix (ruled with the orchestrator: a gap-only carve-out would be a second rule).
     */
    public function testGapAcceptsAFluidLengthAndStillRefusesAThirdValue(): void
    {
        foreach (['clamp(1rem, 2vw + 0.5rem, 3rem)', self::CLAMP . ' 1rem', '1rem ' . self::CALC, self::CLAMP . '  1rem'] as $value) {
            $this->assertAcceptedAndPainted($this->createPage($this->band('spacing', 'gap', $value)), 'spacing', 'gap', 'gap', $value);
        }
        foreach (['1rem 2rem 3rem', self::CLAMP . ' 1rem 2rem', '1rem ' . self::CLAMP . ' ' . self::CALC] as $value) {
            $result = $this->createPage($this->band('spacing', 'gap', $value));
            $this->assertFalse($result['ok'] ?? true, sprintf('gap "%s" is three values', $value));
            $this->assertStringContainsString('at most 2', $result['error'] ?? '');
        }
    }
}
