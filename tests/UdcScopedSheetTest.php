<?php
/**
 * tests/UdcScopedSheetTest.php — Layer 3B, the scoped sheet `udc._scoped` (#1242 T4).
 *
 * The contract is docs/v2/LAYER-3-CONTRACT.md §6, with the rulings #1167 and #1242 record
 * (P-3, P-13, P-14, P-19, P-20, P-21, P-22, M-5, M-6, M-7, M-8, M-16, M-17, M-20). This file is
 * the PHP half of §10's T-10 and T-18 rows; tests/e2e/scoped-sheet.spec.ts is the rendered half.
 *
 * WHAT IT GUARDS, in order of stakes:
 *   1. CONFINEMENT. Every emitted rule's SUBJECT is the band root or a descendant of it. The
 *      selector is author input interpolated into a style element, so the gate that admits it
 *      is the security boundary, and the emitted form is the mechanism (§6.2's table).
 *   2. ONE PREDICATE, TWO CALLERS. What the write gate refuses, the compiler drops and
 *      ledgers; what it admits, the compiler paints (#570).
 *   3. The ratified admissions land, each paired with its nearest refusal, so a gate that
 *      refused everything (or admitted everything) fails here.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class UdcScopedSheetTest extends TestCase
{
    private const ID = 'pp-t4scoped';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['_pp_test_store']['posts'][42]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = true;
    }

    /** One rule through the shared write gate. */
    private function validate(array $rule, string $component = 'section', array $udc = [])
    {
        return pp_udc_validate_map($udc + [PP_UDC_SCOPED_KEY => [$rule]], $component);
    }

    private function selectorOk(string $selector, string $component = 'section'): ?string
    {
        return pp_udc_scoped_selector_problem($selector, $component);
    }

    private function band(array $rules, string $component = 'section', array $udc = []): array
    {
        return ['component' => $component, 'id' => self::ID, 'props' => [], 'udc' => $udc + [PP_UDC_SCOPED_KEY => $rules]];
    }

    // ── §6.2: the selector gate ──────────────────────────────────────────────

    /**
     * THE PROBE VERBATIM, and its family (T-10). Each of these, emitted, would make a SIBLING
     * of the band root the subject: the next band, or anything after it.
     */
    public function testEverySelectorThatWouldReachTheNextBandIsRefused(): void
    {
        foreach ([
            ':hover + section'                => 'next band',
            ':not(.x) ~ *'                    => 'every later sibling',
            ':first-child ~ [data-pp-band]'   => 'every later band',
            ':focus-within ~ section .x'      => 'a descendant of a later band',
            '::before + .x'                   => 'a pseudo-element root entry',
            '+ .x'                            => 'a leading +',
            '~ .x'                            => 'a leading ~',
            'a, + .x'                         => 'a top-level comma (M-20)',
            '.a, .b'                          => 'a top-level comma (M-20)',
        ] as $selector => $why) {
            $this->assertNotNull($this->selectorOk($selector), "{$selector} must be refused ({$why})");
        }
        // The nearest admitted shapes: a sibling combinator BETWEEN DESCENDANTS stays inside.
        $this->assertStringContainsString('one rule per selector', (string) $this->selectorOk('.a, .b'),
            'M-20 is refused by its own clause, naming the fix');
        // Rule 7 is about DEPTH 0 OF AN ENTRY THAT BEGINS ON THE ROOT, whatever sits between:
        // `:hover .a + .b` is refused too, as the contract words it ("may not contain + or ~ at
        // depth 0 of the entry at all"), though its subject happens to stay inside.
        $this->assertNotNull($this->selectorOk(':hover .a + .b'));
        foreach (['* + .x', '.a ~ .b', '*:hover .a + .b', '> .a ~ .b', '.x:has(~ .y)', ':is(.a, .b) .c', ':hover .a:is(* + .b)'] as $selector) {
            $this->assertNull($this->selectorOk($selector), "{$selector} must be admitted");
        }
    }

    /** §6.2's emission table, row by row (M-5). */
    public function testEachEntryEmitsInTheFormItsFirstByteSelects(): void
    {
        $this->assertSame('[data-pp-band="b1"]:hover .x', pp_udc_scoped_emitted_selector('b1', ':hover .x'));
        $this->assertSame('[data-pp-band="b1"]::before', pp_udc_scoped_emitted_selector('b1', '::before'));
        $this->assertSame('[data-pp-band="b1"] > .x', pp_udc_scoped_emitted_selector('b1', '> .x'));
        $this->assertSame('[data-pp-band="b1"] .x a', pp_udc_scoped_emitted_selector('b1', '.x a'));
        $this->assertSame('[data-pp-band="b1"] *:is(.a, .b)', pp_udc_scoped_emitted_selector('b1', ' *:is(.a, .b) '),
            'entries are trimmed of ASCII whitespace before anything runs');
    }

    /**
     * THE CONFINEMENT SWEEP. Every admitted selector in a broad matrix compiles to a rule whose
     * selector starts with the band's own attribute and continues with `:` (a compound on the
     * root) or a space (a descendant), and the selector text contains no top-level comma — so
     * the rule's subject is the root or inside it. Paired with a stored-hostile sweep: the same
     * emitter never prints a refused selector at all.
     */
    public function testEveryAdmittedSelectorEmitsWithItsSubjectInsideTheBand(): void
    {
        $admitted = [
            '.section__content ul', ':hover .x', '::before', '::after', '> p', '*:is(.a, .b)', 'a:hover',
            'li:nth-child(2n + 1)', 'li:nth-last-child(odd of .x)', ':has(> img) .x', '.x:not(.y)', 'p::first-line',
            ':focus-within', '[data-pp-item="it-1"] .grid__title', ':is(.dark *) .x', ':popover-open', ':modal',
            ':lang(pt-BR) q', ':dir(rtl) .x', 'details:open', '.日本語', '[title="Österreich"]', '::marker',
        ];
        $rules = array_map(static fn(string $s): array => ['selector' => $s, 'css' => ['color' => '#123456']], $admitted);
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => $rules], 'section'), 'premise: every selector is admitted');
        $css = pp_udc_band_css($this->band($rules));
        preg_match_all('/([^{}]+)\{color:#123456;\}/', $css, $m);
        $this->assertCount(count($admitted), $m[1], 'every admitted rule paints, once');
        $prefix = '[data-pp-band="' . self::ID . '"]';
        foreach ($m[1] as $selector) {
            $this->assertStringStartsWith($prefix, $selector);
            $this->assertContains(substr($selector, strlen($prefix), 1), [':', ' '], "{$selector}: the entry must hang off the band root");
            $this->assertSame(0, preg_match('/,(?![^(]*\))/', $selector), "{$selector}: no top-level selector list");
        }
    }

    public function testStoredHostileSelectorsNeverReachTheSheetAndAreLedgered(): void
    {
        $hostile = [':hover + section', '.a, body', '.x{}', '.x}body{color:red', '</style>', ':root', 'body .x',
            '.a\\62 ody', "\x00.x", '.x /* c */', '@import "x"', '.a, .b'];
        $rules = array_map(static fn(string $s): array => ['selector' => $s, 'css' => ['color' => '#ff0000']], $hostile);
        $rules[] = ['selector' => '.ok', 'css' => ['color' => '#00ff00']];
        $drops = [];
        $compiled = pp_udc_compile_band($this->band($rules), 'authored', $drops);
        $css = pp_udc_band_css($this->band($rules));
        $this->assertStringNotContainsString('#ff0000', $css, 'no refused selector may paint');
        $this->assertStringContainsString('[data-pp-band="' . self::ID . '"] .ok{color:#00ff00;}', $css, 'the healthy sibling still paints');
        $this->assertCount(count($hostile), $drops, 'every discarded rule is ledgered: a silent drop is the I35 class');
        foreach ($drops as $drop) {
            $this->assertStringContainsString('"_scoped"[', $drop['where']);
        }
        $this->assertCount(1, $compiled['scoped']);
    }

    /** Rule 1: the byte allowlist, outside and inside a quoted value (M-17 governs non-ASCII). */
    public function testTheByteGateIsAnAllowlist(): void
    {
        foreach (['.a\\b', '.a{', '.a}', '.a;', ".a\tb", ".a\nb", '.a@b', '.a&b', '.a<b', '.a/b', '.a%b', '.a!b', '.a`b'] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s) . ' must be refused');
        }
        foreach (['[title="@x"]', '[href*="a&b"]', '[href*="/pricing"]', '[href*="%20"]', "[title=\"it's\"]", '[title="a)b"]'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s}: printable bytes inside a quoted value are admitted");
        }
        foreach (['[title="a\\b"]', '[title="a<b"]', '[title="a{b"]', '[title="a}b"]', '[title="a;b"]', "[title=\"a\x01b\"]"] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s) . ': still refused inside a quoted value');
        }
        $this->assertNotNull($this->selectorOk(str_repeat('a', 257)), 'over 256 bytes');
        $this->assertNull($this->selectorOk(str_repeat('a', 256)), 'exactly 256 bytes');
    }

    /** M-17 (routed item 14, RULED): Unicode letters and digits in class/id names and quoted values. */
    public function testUnicodeLettersAndDigitsAreSelectableAndNothingElseIs(): void
    {
        foreach (['.مرحبا', '#日本', '.κείμενο', '.x١٢', '[title="Привет"]', '[data-x="日本語"]'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s} must be admitted (M-17)");
        }
        foreach (['.a→b', '.a😀', '[title="a😀"]', '.a' . "\u{200B}" . 'b', 'é', "[title=\"\xC3\x28\"]", "\xff.a"] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s) . ': a non-letter code point, a non-ASCII type selector or invalid UTF-8');
        }
    }

    /** Rules 2 and 3: balance, operators, no namespaces, unquoted values must be identifiers. */
    public function testBracketsOperatorsAndValuesFollowTheGrammar(): void
    {
        foreach (['[a=b]', '[a~="b"]', '[a|="b"]', '[a^="b"]', '[a$="b"]', '[a*="b"]', '[a="b" i]', '[a="b" s]', '[a]'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s} must be admitted");
        }
        foreach (['[a', 'a]', '[]', '[[a]]', '[a b]', '[a=1]', 'a$b', 'a=b', 'ns|a', '*|a', '[ns|a]', '.a||.b', ':is(.a', '.a)', '[a="b"', '[a=b x]'] as $s) {
            $this->assertNotNull($this->selectorOk($s), "{$s} must be refused");
        }
    }

    /** Rule 4 (P-22, I19, P-14): the pinned list, its exclusions, its argument grammars. */
    public function testPseudoClassesComeFromThePinnedListMinusTheNamedExclusions(): void
    {
        foreach (array_keys(pp_udc_scoped_pseudo_classes()) as $name) {
            $kind = pp_udc_scoped_pseudo_classes()[$name];
            $arg  = ['plain' => '', 'selectors' => '(.a)', 'relative' => '(> .a)', 'nth' => '(2n+1)',
                     'nth-of' => '(2n+1 of .a)', 'lang' => '(en)', 'dir' => '(ltr)'][$kind];
            $base = rtrim($name, '()');
            $this->assertNull($this->selectorOk('.x:' . $base . $arg), ":{$base}{$arg} is on the pinned list");
        }
        foreach (['popover-open', 'modal', 'any-link', 'valid', 'user-invalid', 'read-only', 'indeterminate', 'default', 'playing', 'paused', 'autofill'] as $named) {
            $this->assertArrayHasKey($named, pp_udc_scoped_pseudo_classes(), ":{$named} is named by P-14/P-22");
        }
        foreach ([':root', ':scope', ':host', ':host(.a)', ':defined', ':-webkit-autofill', ':-moz-focusring', ':foo', ':hover()', ':not', ':before', ':state(x)'] as $s) {
            $this->assertNotNull($this->selectorOk('.x' . $s), "{$s} must be refused");
        }
        $this->assertStringContainsString('::before', (string) $this->selectorOk('.x:before'), 'the one-colon spelling is pointed at its one spelling');
    }

    public function testTheNthArgumentGrammarIsTheContractsRegex(): void
    {
        foreach (['odd', 'even', '3', '+3', '-3', 'n', '2n', '-n+3', '2n + 1', '2n- 1', '999n+999'] as $arg) {
            $this->assertNull($this->selectorOk("li:nth-child({$arg})"), "{$arg} must be admitted");
        }
        foreach (['', '+', '5+3', '+-1', '1000', 'n+1000', '2n+1n', 'x'] as $arg) {
            $this->assertNotNull($this->selectorOk("li:nth-child({$arg})"), "\"{$arg}\" must be refused");
        }
        $this->assertNull($this->selectorOk('li:nth-child(2n of .a)'));
        $this->assertNotNull($this->selectorOk('li:nth-of-type(2n of .a)'), '`of S` is on the -child forms only');
    }

    public function testFunctionalNestingIsBoundedAndHasCannotNest(): void
    {
        $this->assertNull($this->selectorOk(':is(:not(:where(.a)))'), 'depth 3');
        $this->assertNotNull($this->selectorOk(':is(:not(:where(:is(.a))))'), 'depth 4');
        $this->assertNotNull($this->selectorOk(':has(:has(.a))'));
        $this->assertNotNull($this->selectorOk(':has(:is(:has(.a)))'), ':has() inside :has() at any depth');
        $this->assertNotNull($this->selectorOk(':is()'), 'an empty list paints nothing');
        $this->assertNotNull($this->selectorOk(':is(.a,)'), 'nor an empty item, which forgiving parsing would drop silently');
    }

    /** Rules 5 and 6: one pseudo-element, last, from the pinned list; html/head/body refused. */
    public function testPseudoElementsAndTypeSelectors(): void
    {
        foreach (array_keys(pp_udc_scoped_pseudo_elements()) as $name) {
            $s = '.x::' . (str_ends_with($name, '()') ? rtrim($name, '()') . ($name === 'highlight()' ? '(hl)' : '(.a)') : $name);
            $this->assertNull($this->selectorOk($s), "{$s} is on the pinned list");
        }
        foreach (['::target-text', '::details-content', '::cue'] as $named) {
            $this->assertNull($this->selectorOk('.x' . $named), "{$named} is one of P-22's named omissions");
        }
        foreach (['.x::before::after', '::before .x', '.x::before:hover', ':is(::before)', '.x::part(a)', '.x::slotted(a)', '.x::-webkit-scrollbar', '.x::bar'] as $s) {
            $this->assertNotNull($this->selectorOk($s), "{$s} must be refused");
        }
        foreach (['html', 'HEAD .x', 'body > p', ':is(body) .x'] as $s) {
            $this->assertNotNull($this->selectorOk($s), "{$s} must be refused (I19)");
        }
        $this->assertNull($this->selectorOk('bodyx'), 'only the exact names');
    }

    /** M-16: attribute conditions that read plugin output, on this band or another. */
    public function testAttributeConditionsThatCouldReadPluginOutputAreRefused(): void
    {
        $this->assertNotNull($this->selectorOk('input[value^="a"]', 'embed'), 'an embed band carries plugin output');
        $this->assertNotNull($this->selectorOk(':has([name="_wpnonce"]) .x', 'embed'));
        $this->assertNull($this->selectorOk('.x input', 'embed'), 'class and element reads stay admitted on embed');
        $this->assertNull($this->selectorOk('input[value^="a"]', 'section'), 'and attribute reads on a band with no plugin output');

        foreach ([':has(~ section [value^="a"])', ':has(+ [data-pp-component="embed"]) .x', ':not(:has([a]))', ':is(main:has([a]) *) .x', ':where(:has([a])) .x', 'li:nth-child(odd of :has([a]))'] as $s) {
            $this->assertNotNull($this->selectorOk($s), "{$s}: a :has() that can see outside the band");
        }
        foreach (['.x:has(~ [a])', ':has(> [a]) .x', ':has([a])', '.x:has(+ .y [a])', ':has(~ .x)'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s}: a :has() that sees only inside the band");
        }
    }

    // ── §6.3: declarations ───────────────────────────────────────────────────

    public function testTheCssMapIsLayerTwosGrammar(): void
    {
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123', 'mix-blend-mode' => 'multiply', '-webkit-line-clamp' => '3']]));
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['padding-left' => ['d' => '1.5rem', 'p' => '1rem']]]));
        foreach ([
            ['color' => 'red'],               // typed where known: color takes hex/rgb/hsl
            ['Color' => '#123'],              // charset
            ['all' => 'unset'],               // §6.0 exclusion
            ['-webkit-all' => 'unset'],       // through a vendor prefix
            ['opacity' => '@nope'],           // a reference on an untyped property
            ['width' => '10px !important'],   // !important
            ['color' => ['x' => '#123']],     // unknown breakpoint
            ['color' => []],                  // empty map
            ['color' => true],                // not a scalar
            ['width' => '1px} body{color:red'],
        ] as $css) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => $css]), json_encode($css) . ' must be refused');
        }
        $error = $this->validate(['selector' => '.x', 'css' => ['Color' => '#123']]);
        $this->assertStringContainsString('write "color"', $error->get_error_message(), 'a real property keeps its lowercase hint');
    }

    public function testStateKeysAndAtRulesAreRefusedInsideCss(): void
    {
        foreach ([[':hover' => ['color' => '#123']], [':focus-visible' => ['color' => '#123']], ['@keyframes' => 'x'], ['@import' => 'x'], ['@media' => 'print']] as $css) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => $css]), json_encode($css) . ' must be refused');
        }
        $this->assertStringContainsString('selector', $this->validate(['selector' => '.x', 'css' => [':hover' => ['color' => '#123']]])->get_error_message(),
            'the refusal routes the state into the selector, its one spelling');
    }

    /** P-20 as RULED, with routed item 15 (case-sensitive names). */
    public function testAuthorCustomPropertiesAreAdmittedAndEngineOwnedOnesAreNot(): void
    {
        foreach (['--brandColor', '--brandcolor', '--my-gap', '--_x', '---x', '--x2'] as $name) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => [$name => '4px']]), "{$name} is the author's");
        }
        $this->assertNotEmpty(pp_design_tokens(), 'premise: the token parser has names to refuse');
        foreach (array_keys(pp_design_tokens()) as $token) {
            $error = $this->validate(['selector' => '.x', 'css' => [$token => '1px']]);
            $this->assertNotNull($error, "{$token} is a site design token");
            $this->assertStringContainsString($token, $error->get_error_message(), 'named exactly');
        }
        foreach (['--pp-band-padding', '--pp-x', '--pp-list-marker-color', '--title-typography-size-d', '--heading-_css-opacity-p'] as $name) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$name => '1px']]), "{$name} is engine-owned");
        }
        $error = $this->validate(['selector' => '.x', 'css' => ['--Color-Accent' => '1px']]);
        $this->assertNull($error, 'custom-property names are case-sensitive: --Color-Accent is not --color-accent');
        foreach (['--', '--a b', '--a;b', '--' . str_repeat('a', 65), '--é'] as $name) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$name => '1px']]), json_encode($name) . ' fails the name gate');
        }
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['--x' => '@color-accent']]), 'a reference needs a declared type');
        $css = pp_udc_band_css($this->band([['selector' => '.x', 'css' => ['--brandColor' => '#123456', 'outline' => '2px solid var(--brandColor)']]]));
        $this->assertStringContainsString('{--brandColor:#123456;outline:2px solid var(--brandColor);}', $css, 'emitted as written, in author order');
        // A TYPED property keeps its grammar (R1′, §6.3), and `color`'s admits var() of a
        // registered token only, so an author property is read where the grammar is open.
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['color' => 'var(--brandColor)']]));
    }

    /** §6.4 / M-7: content creates a box; it never carries author text. */
    public function testContentTakesOnlyTheBoxMakingForms(): void
    {
        foreach (['""', "''", 'none', 'normal'] as $value) {
            $this->assertNull($this->validate(['selector' => '::before', 'css' => ['content' => $value]]), "{$value} must be admitted");
        }
        foreach (['inherit', 'initial', 'unset', 'revert', 'REVERT-LAYER', ' revert ', '"x"', '"\\201C"', 'attr(title)', 'counter(item, lower-alpha)', 'counters(item, "a")', 'counters(item, "1")', 'counter(none)', '"" "x"', 'url(#x)', 'var(--x)', '"“"', 'counter(item)', 'counters(item, ".")',
                  'open-quote', 'close-quote', 'no-open-quote', 'NO-CLOSE-QUOTE'] as $value) {
            $this->assertNotNull($this->validate(['selector' => '::before', 'css' => ['content' => $value]]), "{$value} must be refused");
        }
        $this->assertNotNull(pp_udc_validate_map(['heading' => [PP_UDC_CSS_KEY => ['content' => '""']]], 'section'),
            '`content` stays excluded from `_css`: the departure is the scoped sheet\'s alone');
    }

    /** Δ3's text-bearing rule, and routed item 5 (var() text channel) failing closed. */
    public function testTextBearingPropertiesTakeKeywordsOnly(): void
    {
        foreach (pp_layer3_text_bearing_properties() as $property) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => '"BUY NOW"']]), "{$property} with a string");
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => "'BUY NOW'"]]), "{$property} with a single-quoted string");
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => 'var(--x)']]), "{$property} through var()");
        }
        foreach (['list-style-type' => 'square', 'quotes' => 'auto', 'text-overflow' => 'ellipsis', 'list-style' => 'disc inside'] as $property => $keyword) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => [$property => $keyword]]), "{$property}: {$keyword}");
        }
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['-webkit-text-emphasis' => '"x"']]), 'through a vendor prefix too');
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['font-family' => '"Inter", sans-serif']]), 'a string elsewhere is not text');
    }

    /** §6.7: CSS functions default-deny; the url() family refused except P-13's fragment. */
    public function testCssFunctionsAreDefaultDenyAndNoValueNamesAnExternalResource(): void
    {
        foreach (['url(https://x.test/a.png)', 'url("//x.test/a")', 'URL(x)', 'image-set("a.png" 1x)', 'image("a.png")', 'src("a")',
                  '-moz-element(#a)', 'element(#a)', 'paint(x)', 'attr(data-x)', 'symbols(cyclic "*")', 'expression(alert(1))',
                  'cross-fade(a, b)', 'foo(1)', '-webkit-gradient(linear)', 'url(#a) , url(https://x)', 'url( #a"'] as $value) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['mask' => $value]]), "{$value} must be refused");
        }
        foreach (['url(#grad)', "url('#g')", 'url( "#g" )'] as $value) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => ['fill' => $value]]), "{$value}: a same-document fragment (P-13)");
        }
        foreach (['calc(1px + 2px)', 'clamp(1rem, 2vw, 3rem)', 'linear-gradient(#fff, #000)', 'color-mix(in srgb, #fff 50%, #000)', 'rotate(3deg) scale(1.1)'] as $value) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => ['--v' => $value]]), "{$value} calls admitted functions");
        }
        $css = pp_udc_band_css($this->band([['selector' => '.x', 'css' => ['fill' => 'url(#grad)']]]));
        $this->assertStringContainsString('fill:url(#grad);', $css, 'admitted at write is painted at emit (#570)');
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['background' => 'url(#g)']]),
            'on a TYPED property the fragment is refused at write, never accepted then dropped by its typed emit gate');
    }

    /** P-19: the attachment-id background, built exactly as `_band.background.image`. */
    public function testAnAttachmentIdBackgroundIsBuiltByTheEngine(): void
    {
        $this->assertNull($this->validate(['selector' => '::before', 'css' => ['content' => '""', 'background-image' => '42']]));
        $css = pp_udc_band_css($this->band([['selector' => '::before', 'css' => ['content' => '""', 'background-image' => '42']]]));
        $this->assertStringContainsString('background-image:url("' . pp_udc_background_image_url('42') . '")', $css);
        $this->assertStringContainsString('/wp-content/uploads/', $css, 'the same-install URL WordPress returns for the attachment');
        foreach (['https://x.test/a.png', 'url(a.png)', '7', ['d' => '42', 'p' => '42'], 'linear-gradient(#fff,#000)'] as $value) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['background-image' => $value]]), json_encode($value) . ' must be refused');
        }
        // Deleted after a valid write: that one declaration drops, never url() of a dead id,
        // and check 8c reports it (one report: the emit ledger stays silent, as for roles).
        unset($GLOBALS['_pp_test_store']['posts'][42]);
        $band  = $this->band([['selector' => '.x', 'css' => ['background-image' => '42', 'color' => '#123']]]);
        $drops = [];
        pp_udc_compile_band($band, 'authored', $drops);
        $css = pp_udc_band_css($band);
        $this->assertStringNotContainsString('url(', $css);
        $this->assertStringContainsString('color:#123;', $css, 'its sibling declaration still paints');
        $this->assertSame([], $drops, 'the 8c advisory owns this report, not the emit ledger');
        $this->assertSame([['role' => '_scoped[0]', 'id' => 42]], _pp_udc_dangling_background_images($band['udc']),
            'the dangling-image advisory sees a scoped rule\'s background as it sees a role\'s');
    }

    public function testATokenReferenceResolvesAndItsBandTokenIsEmitted(): void
    {
        $band = $this->band([['selector' => '.x', 'css' => ['color' => '@brand']]], 'section', ['_tokens' => ['brand' => '#ff5c2e']]);
        $this->assertNull(pp_udc_validate_map($band['udc'], 'section'));
        $css = pp_udc_band_css($band);
        $this->assertStringContainsString('--pp-brand:#ff5c2e;', $css, 'a token only a scoped rule references is still emitted');
        $this->assertStringContainsString('[data-pp-band="' . self::ID . '"] .x{color:var(--pp-brand);}', $css);
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['color' => '@color-accent']]), 'site tokens resolve');
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['color' => '@space-md']]), 'a reference is type-checked');
    }

    // ── §6.5: conditions ─────────────────────────────────────────────────────

    public function testTheConditionMatrix(): void
    {
        $ok = [
            'media'     => ['(prefers-reduced-motion: reduce)', '(prefers-color-scheme: dark)', '(hover: hover) and (pointer: fine)', 'print',
                            'screen and (orientation: landscape)', 'not print', '(min-height: 600px)', '(min-resolution: 2dppx)', '(aspect-ratio: 16/9)',
                            '(forced-colors: active) or (prefers-contrast: more)', '(hover)'],
            'supports'  => ['(display: grid)', 'not (display: grid)', '(display: grid) and (gap: 1rem)', '(--x: 1)', '((a: b) or (c: d))'],
            'container' => ['(min-width: 400px)', 'card (min-inline-size: 30rem)', '(orientation: portrait)', '(width: 300px) and (height: 200px)'],
        ];
        foreach ($ok as $kind => $preludes) {
            foreach ($preludes as $prelude) {
                $this->assertNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], $kind => $prelude]), "{$kind} {$prelude}");
            }
        }
        $refused = [
            'media'     => ['(min-width: 600px)', '(max-width: 600px)', '(width: 600px)', '(width >= 600px)', '(height < 600px)', 'tv', '(prefers-color-scheme: blue)',
                            '(foo: 1)', '(min-hover: hover)', '(min-height)', '(a: b), print', 'screen or (hover)', '(hover) and (pointer: fine) or (hover)', '', ' ',
                            str_repeat('(hover) and ', 21) . '(hover)', '((((((hover))))))', '(min-height: 600pxx)', '(color: 1x)', '(aspect-ratio: 16/x)', '(resolution: 2dpx)'],
            'supports'  => ['selector(:has(a))', '(background: url(x))', '(content: attr(x))', 'font-tech(color-COLRv1)', '(a)', '(x: y; z)', '(a: b} body{',
                            '(color: red/*)', '(x: a*/)', '(x: (a)', "(x: \"a\nb\")"],
            'container' => ['style(--x: 1)', 'scroll-state(stuck: top)', 'none (min-width: 1px)', '(min-color: 1)', '(width > 1px)'],
        ];
        foreach ($refused as $kind => $preludes) {
            foreach ($preludes as $prelude) {
                $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], $kind => $prelude]), "{$kind} \"{$prelude}\" must be refused");
            }
        }
        $this->assertStringContainsString('engine-owned', $this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => '(min-width: 600px)'])->get_error_message(),
            'the width refusal routes the author to the breakpoint map (P-21)');
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'keyframes' => 'x']), 'no other at-rule key exists');
    }

    public function testConditionsNestInsideTheEngineBreakpointMedia(): void
    {
        $css = pp_udc_band_css($this->band([[
            'selector' => '.x', 'css' => ['padding-left' => ['d' => '2rem', 'p' => '1rem']],
            'media' => '(hover: hover)', 'supports' => '(display: grid)', 'container' => 'card (min-width: 20rem)',
        ]]));
        $inner = '[data-pp-band="' . self::ID . '"] .x{padding-left:1rem;}';
        $this->assertStringContainsString('@media ' . pp_udc_breakpoints()['p']['media'] . '{@media (hover: hover){@supports (display: grid){@container card (min-width: 20rem){' . $inner . '}}}}', $css);
        $this->assertStringContainsString('@media (hover: hover){@supports (display: grid){@container card (min-width: 20rem){[data-pp-band="' . self::ID . '"] .x{padding-left:2rem;}}}}', $css);
    }

    // ── §6.1 / P-3: the rule list, every band, never chrome ──────────────────

    public function testTheSheetIsAnOrderedListOfAtMostOneHundredTwentyEightRules(): void
    {
        $rule = ['selector' => '.x', 'css' => ['color' => '#123']];
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => array_fill(0, 128, $rule)], 'section'));
        $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => array_fill(0, 129, $rule)], 'section'), 'M-8');
        foreach ([['selector' => '.x', 'css' => ['color' => '#123']], 'x', [['selector' => '.x']], [['css' => ['color' => '#123']]],
                  [['selector' => '.x', 'css' => []]], [['selector' => '.x', 'css' => ['color' => '#123'], 'when' => 'x']], [['selector' => 7, 'css' => ['color' => '#123']]],
                  [['selector' => '.x', 'css' => [['color' => '#123']]]], [[]]] as $bad) {
            $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => $bad], 'section'), json_encode($bad) . ' must be refused');
        }
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => []], 'section'), 'an empty sheet is a sheet');
        // Order is the tie-breaker, and it is preserved.
        $css = pp_udc_band_css($this->band([['selector' => '.x', 'css' => ['color' => '#111111']], ['selector' => '.x', 'css' => ['color' => '#222222']]]));
        $this->assertLessThan(strpos($css, '#222222'), strpos($css, '#111111'));
    }

    public function testEveryComposableComponentAcceptsAndPaintsTheSheet(): void
    {
        foreach (pp_get_registered_components() as $name => $definition) {
            if (!pp_udc_is_v2_component($name) || pp_udc_is_chrome($name)) {
                continue;
            }
            $rule = ['selector' => '.x', 'css' => ['color' => '#123456']];
            $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [$rule]], $name), "{$name} accepts _scoped (P-3)");
            $this->assertStringContainsString('[data-pp-band="' . self::ID . '"] .x{color:#123456;}', pp_udc_band_css($this->band([$rule], $name)), "{$name} paints it");
        }
    }

    public function testChromeAndItemsRefuseTheSheetAndChromeNeverPaintsOne(): void
    {
        $rule = ['selector' => '.x', 'css' => ['color' => '#123456']];
        foreach (pp_udc_chrome_names() as $chrome) {
            $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [$rule]], $chrome), "{$chrome} is not a band");
            $drops = [];
            $compiled = pp_udc_compile_band(['component' => $chrome, 'id' => $chrome, 'udc' => [PP_UDC_SCOPED_KEY => [$rule]]], 'authored', $drops);
            $this->assertSame([], $compiled['scoped'] ?? [], "{$chrome}: a stored sheet compiles to nothing");
        }
        $this->assertNotEmpty(pp_udc_chrome_names(), 'premise');
        $this->assertArrayHasKey(PP_UDC_SCOPED_KEY, pp_udc_item_reserved_keys(), 'an item map refuses it with a stated reason');
    }

    /**
     * §5.5: after the role and `_css` rules, so an equal-specificity tie goes to the sheet;
     * before the motion guard, which keeps the last word on the engine's own motion.
     */
    public function testTheSheetPrintsAfterTheRolesAndBeforeTheMotionGuard(): void
    {
        $css = pp_udc_band_css($this->band(
            [
                ['selector' => '.section__title', 'css' => ['color' => '#222222']],
                ['selector' => '.section__content p', 'css' => ['transition' => 'color 2s']],
            ],
            'section',
            ['heading' => ['typography' => ['color' => '#111111'], PP_UDC_CSS_KEY => ['transition-duration' => '1s']]]
        ));
        $band         = '[data-pp-band="' . self::ID . '"]';
        $role_at      = strpos($css, '#111111');
        $scoped_at    = strpos($css, '#222222');
        $scoped_guard = strpos($css, '@media (prefers-reduced-motion: reduce){' . $band . ' .section__content p{transition-duration:0.01ms;animation-duration:0.01ms;animation-iteration-count:1;}}');
        $engine_guard = strpos($css, '@media (prefers-reduced-motion: reduce){' . $band . ' .section__title{transition-duration:0.01ms;}}');
        foreach ([$role_at, $scoped_at, $scoped_guard, $engine_guard] as $at) {
            $this->assertNotFalse($at, 'premise: each piece is emitted (a scoped transition gets its own guard rule)');
        }
        $this->assertLessThan($scoped_at, $role_at, 'role rules first, so an equal-specificity tie goes to the sheet');
        $this->assertLessThan($scoped_guard, $scoped_at);
        $this->assertLessThan($engine_guard, $scoped_guard, 'the engine\'s own motion guard prints last');
        $this->assertStringNotContainsString('!important', $css);
    }

    // ── one predicate, every surface ─────────────────────────────────────────

    public function testTheUncheckedSetIsDisclosedOnTheLayerTwoFindingType(): void
    {
        $findings = pp_udc_composition_findings([$this->band([
            ['selector' => '.x', 'css' => ['mix-blend-mode' => 'multiply', 'color' => '#123', 'content' => 'none', '--gap' => '1px']],
            ['selector' => ':hover + section', 'css' => ['mix-blend-mode' => 'multiply']],
        ])]);
        $unchecked = array_values(array_filter($findings, static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property'));
        $this->assertCount(1, $unchecked, 'mix-blend-mode only: not the typed color, not content, not the author\'s own --gap (P-20 admits it), not the refused rule');
        $this->assertStringContainsString('"_scoped"[0]', $unchecked[0]['message']);
    }

    /** §14.1: through the real composition surfaces, not raw meta. */
    public function testTheSheetRoundTripsThroughTheRealWriteSurfaces(): void
    {
        $GLOBALS['wpdb'] = new \PP_Lockable_Wpdb();
        try {
            $id = pp_create_page('scoped', 'draft');
            $rule = ['selector' => '.section__content ul', 'css' => ['list-style' => 'disc', 'padding-left' => ['d' => '1.5rem', 'p' => '1rem']]];
            $write = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
                'component' => 'section', 'props' => ['title' => 'T', 'body' => '<ul><li>a</li></ul>'],
                'udc' => [PP_UDC_SCOPED_KEY => [$rule]],
            ]]]);
            $this->assertTrue($write['ok'], (string) ($write['error'] ?? ''));
            $stored = pp_get_composition($id)[0]['udc'][PP_UDC_SCOPED_KEY];
            $this->assertSame([$rule], $stored, 'stored as written: no minting, no rewriting (mints are role-keyed)');

            $refused = pp_execute_action('update_component', ['post_id' => $id, 'component_index' => 0,
                'udc' => [PP_UDC_SCOPED_KEY => [['selector' => ':hover + section', 'css' => ['color' => '#123']]]]]);
            $this->assertFalse($refused['ok']);
            $this->assertStringContainsString('next band', (string) $refused['error']);
            $this->assertSame([$rule], pp_get_composition($id)[0]['udc'][PP_UDC_SCOPED_KEY], 'nothing stored on a refusal');

            $replace = pp_execute_action('update_component', ['post_id' => $id, 'component_index' => 0,
                'udc' => [PP_UDC_SCOPED_KEY => [['selector' => '::before', 'css' => ['content' => '""']]]]]);
            $this->assertTrue($replace['ok'], (string) ($replace['error'] ?? ''));
            $this->assertSame([['selector' => '::before', 'css' => ['content' => '""']]], pp_get_composition($id)[0]['udc'][PP_UDC_SCOPED_KEY],
                'a sent sheet replaces the stored one whole (per-key merge)');

            $clear = pp_execute_action('update_component', ['post_id' => $id, 'component_index' => 0, 'udc' => [PP_UDC_SCOPED_KEY => null]]);
            $this->assertTrue($clear['ok'], (string) ($clear['error'] ?? ''));
            $this->assertArrayNotHasKey(PP_UDC_SCOPED_KEY, pp_get_composition($id)[0]['udc'] ?? []);
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    /** Admin: the only non-front-end emitter of authored band CSS is the isolated preview document. */
    public function testAuthoredBandCssReachesNoAdminSurfaceButTheIsolatedPreview(): void
    {
        $root    = dirname(__DIR__);
        $callers = [];
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/lib/*.php'), glob($root . '/templates/*.php')) as $file) {
            $code = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', (string) file_get_contents($file));
            if (preg_match_all('/(?<!function )\b(pp_udc_page_authored_css|pp_udc_band_css|_pp_udc_render_scoped)\s*\(/', $code, $m)) {
                foreach ($m[1] as $fn) {
                    $callers[] = basename($file) . ':' . $fn;
                }
            }
        }
        $callers = array_values(array_unique($callers));
        sort($callers);
        $this->assertSame([
            'admin.php:pp_udc_page_authored_css',     // pp_preview_document_head(): the isolated preview origin (#1246)
            'functions.php:pp_udc_page_authored_css', // the front end
            'udc.php:_pp_udc_render_scoped',          // inside _pp_udc_render_blocks()
            'udc.php:pp_udc_band_css',                // inside pp_udc_page_authored_css()
            'udc.php:pp_udc_page_authored_css',       // inside pp_udc_page_css(), which no sink may call (PreviewCascadeParityTest)
        ], $callers);
    }

    // ── regressions from the pre-landing review ──────────────────────────────

    /**
     * SECURITY (pre-landing review): a control byte inside P-13's fragment url() vanished with
     * the url() from what the gates read, then ended a CSS string early on the page and opened
     * one that swallowed every later rule. The literal is judged now, not only the probe.
     */
    public function testAControlByteCannotHideInsideAFragmentUrl(): void
    {
        foreach (["\"url(#a\n)\"", "url(#a\r)", "url(\x0b#a)", "\"url(#a\f)\"", "url(#a\t)", "url( #a\n)"] as $value) {
            foreach (['--x', 'grid-template-areas', 'mask'] as $property) {
                $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => $value]]), json_encode([$property, $value]));
            }
        }
        $css = pp_udc_band_css($this->band([['selector' => '.x', 'css' => ['--x' => "\"url(#a\n)\"", 'color' => '#123']]]));
        $this->assertSame(0, preg_match('/[\x00-\x1f\x7f]/', $css), 'no control byte reaches the sheet from storage either');
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['mask' => 'url( #a )']]), 'spaces around the fragment stay fine');
    }

    /** A function name inside a quoted string calls nothing (Codex adversarial pass). */
    public function testAParenthesisInsideAStringIsNotAFunctionCall(): void
    {
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['grid-template-areas' => '"plan (pro)"']]));
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['font-family' => '"Foo (Bar)", serif']]));
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['grid-template-areas' => '"x" foo(1)']]), 'outside the string it still calls foo()');
    }

    /** The bytes a functional argument's own grammar is the only guard on. */
    public function testFunctionalArgumentsCannotCarryStructuralBytes(): void
    {
        foreach ([':lang(en})', ':lang(x;}body{color:red)', ':lang()', ':lang(a b)', ':dir(up)', ':dir(</style>)', ':dir()',
                  '.x::highlight(a}b)', '.x::highlight(</style>)', '.x::highlight(a b)', '.x::highlight(', '.x::highlight()',
                  ':is(+ .a)', ':not(~ .x)', ':is(:is(li:nth-child(1 of :is(.a))))', '.x::cue(:is(:is(:is(.a))))', '', '   '] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s));
        }
        $this->assertNull($this->selectorOk('.x::highlight(--brand)'), 'a dashed-ident highlight name');
        $this->assertNull($this->selectorOk(':dir(RTL) .x'), 'keywords are ASCII case-insensitive');
    }

    /** CSS's An+B grammar: a space only around the sign between An and B (adversarial pass). */
    public function testTheNthGrammarRefusesSpacesTheBrowserRejects(): void
    {
        foreach (['+ 2n', '+ 5', '- n+1', '2 n', '2n+ +1'] as $arg) {
            $this->assertNotNull($this->selectorOk("li:nth-child({$arg})"), "\"{$arg}\" is dropped by a browser");
        }
        foreach (['2N+1', 'ODD', ' 2n - 1 ', '-n + 3'] as $arg) {
            $this->assertNull($this->selectorOk("li:nth-child({$arg})"), "\"{$arg}\" is valid CSS");
        }
    }

    public function testConditionKeywordsAreCaseInsensitiveAndPreludesTrimLikeSelectors(): void
    {
        foreach (['(HOVER: hover)', '(hover: HOVER)', 'PRINT', "\t(hover: hover)\n"] as $prelude) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => $prelude]), json_encode($prelude));
        }
        $css = pp_udc_band_css($this->band([['selector' => '.x', 'css' => ['color' => '#123'], 'media' => "\t(hover: hover)\n"]]));
        $this->assertStringContainsString('@media (hover: hover){', $css, 'emitted trimmed, exactly as the gate read it');
        $this->assertStringContainsString('cannot be mixed', $this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => '(hover) and (pointer: fine) or (hover)'])->get_error_message(),
            'refused for mixing and/or, not for some other reason');
        $this->assertStringContainsString('min-/max-', $this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => '(min-hover: hover)'])->get_error_message());
    }

    public function testARefusalNamesAnUnprintableByteInHex(): void
    {
        $this->assertStringContainsString('0x09', (string) $this->selectorOk(":hover\t+ x"));
    }

    /** The sheet reaches pseudo-elements base.css's global animation kill switch does not. */
    public function testTheScopedMotionGuardNeutralisesAnimationToo(): void
    {
        $css = pp_udc_band_css($this->band([['selector' => 'li::marker', 'css' => ['animation' => 'x 1s infinite']]]));
        $this->assertStringContainsString('li::marker{transition-duration:0.01ms;animation-duration:0.01ms;animation-iteration-count:1;}}', $css);
    }

    /** The list shape is its own gate, at write and at emit (testing pass). */
    public function testAKeyedMapOfValidRulesIsNotAList(): void
    {
        $rule = ['selector' => '.x', 'css' => ['color' => '#123456']];
        $error = pp_udc_validate_map([PP_UDC_SCOPED_KEY => ['a' => $rule]], 'section');
        $this->assertNotNull($error);
        $this->assertStringContainsString('must be a list', $error->get_error_message());
        $drops = [];
        $compiled = pp_udc_compile_band($this->band(['a' => $rule]), 'authored', $drops);
        $this->assertSame([], $compiled['scoped']);
        $this->assertCount(1, $drops);
        $this->assertStringContainsString('not a list of rules', $drops[0]['reason']);
    }

    /** M-8 at emit: a stored sheet past the bound paints its first 128 rules and says so. */
    public function testAStoredSheetPastTheBoundPaintsOnlyItsFirstRulesAndLedgersTheRest(): void
    {
        $rules = [];
        for ($k = 0; $k < 130; $k++) {
            $rules[] = ['selector' => '.r' . $k, 'css' => ['color' => '#123456']];
        }
        $drops = [];
        $compiled = pp_udc_compile_band($this->band($rules), 'authored', $drops);
        $this->assertCount(PP_UDC_SCOPED_MAX_RULES, $compiled['scoped']);
        $this->assertStringContainsString('.r127{', pp_udc_band_css($this->band($rules)));
        $this->assertStringNotContainsString('.r128{', pp_udc_band_css($this->band($rules)));
        $this->assertSame(['"_scoped"'], array_column($drops, 'where'));
        $this->assertStringContainsString('first 128', $drops[0]['reason']);
    }

    public function testFindingsSkipChromeAndRefusedRulesAndStayBounded(): void
    {
        $chrome = pp_udc_chrome_names()[0];
        $none = pp_udc_composition_findings([['component' => $chrome, 'id' => 'pp-c', 'udc' => [PP_UDC_SCOPED_KEY => [['selector' => '.x', 'css' => ['mix-blend-mode' => 'multiply']]]]]]);
        $this->assertSame([], array_values(array_filter($none, static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property')));
        $rules = [];
        for ($r = 0; $r < 4; $r++) {
            $css = [];
            for ($k = 0; $k < PP_UDC_SCOPED_MAX_DECLARATIONS; $k++) {
                $css['x-unknown-' . $k] = '1';
            }
            $rules[] = ['selector' => '.x' . $r, 'css' => $css];
        }
        $many = pp_udc_composition_findings([$this->band($rules)]);
        $this->assertCount(PP_UDC_MAX_EMIT_DROPS, array_filter($many, static fn(array $f): bool => $f['type'] === 'udc_css_unchecked_property'));
    }

    /**
     * T-18, independently of the implementation's own tables: names from the pinned snapshot
     * that must stay admitted, so deleting a table row fails here.
     */
    public function testThePinnedSnapshotStaysAdmittedAndAnUnknownNameIsRefusedByName(): void
    {
        foreach (['.x:target-within', 'a:local-link', 'input:user-valid', '.x:blank', 'video:volume-locked', '.x:future',
                  'td:nth-col(2)', 'p::spelling-error', 'p::grammar-error', 'input::file-selector-button', 'dialog::backdrop', '.x::details-content'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s} is in the pinned snapshot");
        }
        foreach ([':foo .x' => ':foo', '.x::bar' => '::bar'] as $s => $named) {
            $error = $this->validate(['selector' => $s, 'css' => ['color' => '#123']]);
            $this->assertStringContainsString($named, $error->get_error_message(), 'the refusal names the selector (I19)');
        }
    }

    /**
     * P-13 on a TYPED property whose grammar takes `none`: the substituted probe would pass, so
     * only judging the literal refuses it at write (rather than accepting and dropping at emit).
     */
    public function testAFragmentUrlOnATypedPropertyIsRefusedAtWrite(): void
    {
        $typed = null;
        foreach (['box-shadow', 'max-width', 'text-decoration', 'background'] as $candidate) {
            $param = pp_udc_css_param($candidate);
            if (empty($param['untyped']) && pp_udc_validate_value('none', $param) === true) {
                $typed = $candidate;
                break;
            }
        }
        $this->assertNotNull($typed, 'premise: a typed property that takes none');
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$typed => 'url(#g)']]), "{$typed}: url(#g)");
    }

    // ── the four rulings of 2026-10-05 (#1242 T4 7A) ─────────────────────────

    /** Q1: M-16 extended to selector reach inside the constraining functional pseudo-classes. */
    public function testAnAttributeTestPairedWithACombinatorInsideAConstrainingArgumentIsRefused(): void
    {
        foreach (['.x:is([data-n^="a"] *)', ':is(#a[data-n^="a"] ~ * *)', '*:not([data-n^="a"] *)', 'li:nth-child(1 of [data-n^="a"] *)',
                  ':has(:is([value^="a"] *))', '.x:where(body [a] .y)', '.x::cue(:is([a] b))', ':is(:not([a]) *) .x', '.x:current([a] *)'] as $s) {
            $error = $this->selectorOk($s);
            $this->assertNotNull($error, "{$s} reads attributes outside the band");
        }
        $this->assertStringContainsString('M-16', (string) $this->selectorOk('.x:is([data-n^="a"] *)'));
        foreach (['.x:is([lang=fr])', 'a:not([href^="http"])', ':is(.dark *) .x', '.x:is(.a, [b])', 'li:nth-child(odd of [data-x])', '[data-y] .x:is(.a *)'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s}: an attribute test on the subject itself, or a pure class read");
        }
    }

    /** Q2: counter values take names only (M-7's reasoning); names keep ordinary numbering. */
    public function testCounterValuesTakeNamesOnly(): void
    {
        foreach (['counter-reset' => 'x 5551234', 'counter-set' => 'list-item 8233101', 'counter-increment' => 'x 2', '-webkit-counter-reset' => 'x 1'] as $property => $value) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => $value]]), "{$property}: {$value}");
        }
        foreach (['counter-reset' => 'reversed(x)', 'counter-set' => 'x var(--n)', 'counter-increment' => 'x calc(1)'] as $property => $value) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => $value]]), "{$property}: {$value}");
        }
        foreach (['counter-reset' => 'item', 'counter-increment' => 'item other', 'counter-set' => 'none'] as $property => $value) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => [$property => $value]]), "{$property}: {$value}");
        }
    }

    /** Q3: 64 declarations per rule and 64 KiB of compiled sheet per band, at write and at emit. */
    public function testTheDeclarationAndSheetSizeBounds(): void
    {
        $css = [];
        for ($k = 0; $k < PP_UDC_SCOPED_MAX_DECLARATIONS; $k++) {
            $css['--v' . $k] = '1';
        }
        $this->assertNull($this->validate(['selector' => '.x', 'css' => $css]), 'exactly 64');
        $css['--v64'] = '1';
        $this->assertStringContainsString('at most 64 declarations', $this->validate(['selector' => '.x', 'css' => $css])->get_error_message());

        // Long selectors over four breakpoint-keyed declarations: well past 64 KiB within 128 rules.
        $rules = [];
        for ($k = 0; $k < 128; $k++) {
            $rules[] = ['selector' => '.r' . $k . ' ' . str_repeat('a ', 100) . 'b', 'css' => ['padding' => ['d' => '1px', 't' => '2px', 'p' => '3px']]];
        }
        $error = pp_udc_validate_map([PP_UDC_SCOPED_KEY => $rules], 'section');
        $this->assertNotNull($error);
        $this->assertStringContainsString('64 KiB', $error->get_error_message());
        preg_match('/"_scoped"\[(\d+)\]/', $error->get_error_message(), $m);
        $clip = (int) $m[1];
        $this->assertGreaterThan(10, $clip);
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => array_slice($rules, 0, $clip)], 'section'), 'the rules before the clip fit');

        $drops = [];
        $compiled = pp_udc_compile_band($this->band($rules), 'authored', $drops);
        $this->assertGreaterThanOrEqual($clip, count($compiled['scoped']), 'the emit bound is measured under the real, shorter id');
        $this->assertLessThanOrEqual(PP_UDC_SCOPED_MAX_SHEET_BYTES, strlen(_pp_udc_render_scoped($compiled['scoped'])));
        $this->assertCount(1, $drops);
        $this->assertStringContainsString('64 KiB', $drops[0]['reason']);
    }

    /** Q4: combining marks after a letter; never leading. */
    public function testCombiningMarksFollowingALetterAreSelectable(): void
    {
        foreach (['.हिन्दी', '.עִבְרִית', '.العَرَبِيَّة', '[title="हिन्दी"]', '#tamil-தமிழ்', '.é'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s}: letters with their combining marks (M-17, Q4)");
        }
        foreach (['.' . "\u{0301}" . 'a', '.-' . "\u{093F}" . 'x', '[title="' . "\u{05B4}" . 'a"]', '.a1' . "\u{0301}"] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s) . ': a mark that follows no letter');
        }
    }

    // ── review cycle 2 ───────────────────────────────────────────────────────

    /** Q1 with explicit combinators only, with and without spaces (cycle-2 testing pass). */
    public function testTheM16ExtensionCatchesExplicitCombinators(): void
    {
        foreach (['.x:is([data-n^="a"] > *)', '.x:is([a]+*)', ':not([a] ~ *) .x', '.x:where([a]>*)', 'li:nth-child(1 of [a] + *)', '.x::cue([a] > b)'] as $s) {
            $this->assertStringContainsString('M-16', (string) $this->selectorOk($s), $s);
        }
    }

    /** An `of S` on the band root tests its siblings, the other bands (cycle-2 security pass). */
    public function testAnOfSelectorOnTheBandRootMayNotTestAttributes(): void
    {
        foreach ([':nth-child(1 of [data-s^="a"])', ':hover:nth-last-child(1 of [id^="pp-"])', ':is(:nth-child(1 of [data-s^="a"]))', ':not(:nth-child(odd of [a])) .x'] as $s) {
            $this->assertStringContainsString('M-16', (string) $this->selectorOk($s), $s);
        }
        foreach (['li:nth-child(1 of [data-x])', ':nth-child(1 of .a) .x', ':hover li:nth-child(odd of [a])'] as $s) {
            $this->assertNull($this->selectorOk($s), "{$s}: an of-selector over in-band siblings, or a class read");
        }
    }

    /** The gate trims before it reads the root byte: the T-10 probes with whitespace around them. */
    public function testTheProbesWithSurroundingWhitespaceAreStillRefused(): void
    {
        foreach ([' :hover + section', "\t:hover ~ *", "\n:first-child + section", ' ::before ~ *', ":hover + section \f"] as $s) {
            $this->assertNotNull($this->selectorOk($s), json_encode($s));
        }
        $css = pp_udc_band_css($this->band([['selector' => ' :hover + section', 'css' => ['color' => '#ff0000']]]));
        $this->assertStringNotContainsString('#ff0000', $css);
    }

    /** Q2: a repeated counter name is a number again. */
    public function testARepeatedCounterNameIsRefused(): void
    {
        foreach ([['counter-increment', 'n n'], ['counter-increment', 'list-item ' . str_repeat('list-item ', 7)], ['counter-reset', 'a b a'], ['counter-set', 'x  x']] as [$property, $value]) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => $value]]), "{$property}: {$value}");
        }
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['counter-increment' => 'a b c']]));
    }

    public function testBoundaryAndStringAwareCases(): void
    {
        $this->assertNull($this->selectorOk('[title="العَرَبِيَّة"]'), 'two consecutive marks inside a string value');
        $this->assertNull($this->selectorOk(':is([title=")"]) .x'), 'a quoted ")" does not close the argument');
        $this->assertNull($this->selectorOk('.x:not([title="a(b"])'));
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => '((((hover))))']), 'exactly three nested condition levels');
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => '(((((hover)))))']), 'four');
        $this->assertStringContainsString('"and" only', $this->validate(['selector' => '.x', 'css' => ['color' => '#123'], 'media' => 'screen and (hover) or (pointer: fine)'])->get_error_message());
    }

    /** Q3 at the edge, and the write gate's guarantee under the longest legal band id. */
    public function testTheSheetBoundAtItsEdgeAndUnderTheLongestId(): void
    {
        $long = str_repeat('b', PP_UDC_BAND_ID_MAX);
        $rule = static fn(int $k): array => ['selector' => '.r' . $k . ' ' . str_repeat('a ', 100) . 'b', 'css' => ['padding' => ['d' => '1px', 't' => '2px', 'p' => '3px']]];
        $rules = [];
        for ($k = 0; $k < 128; $k++) {
            $rules[] = $rule($k);
        }
        preg_match('/"_scoped"\[(\d+)\]/', pp_udc_validate_map([PP_UDC_SCOPED_KEY => $rules], 'section')->get_error_message(), $m);
        $accepted = array_slice($rules, 0, (int) $m[1]);
        $drops = [];
        $compiled = pp_udc_compile_band(['component' => 'section', 'id' => $long, 'props' => [], 'udc' => [PP_UDC_SCOPED_KEY => $accepted]], 'authored', $drops);
        $this->assertSame([], $drops, 'what the write gate accepted is never clipped at emit, even under the longest id');
        $this->assertCount(count($accepted), $compiled['scoped']);
        $bytes = strlen(_pp_udc_render_scoped($compiled['scoped']));
        $this->assertLessThanOrEqual(PP_UDC_SCOPED_MAX_SHEET_BYTES, $bytes);
        $this->assertGreaterThan(PP_UDC_SCOPED_MAX_SHEET_BYTES, $bytes + strlen(_pp_udc_render_scoped([pp_udc_compile_band(['component' => 'section', 'id' => $long, 'props' => [], 'udc' => [PP_UDC_SCOPED_KEY => [$rules[(int) $m[1]]]]], 'authored')['scoped'][0]])),
            'the next rule is the one that crosses the bound');
    }

    /** 8c over the sheet: the right index, and only numeric ids. */
    public function testTheDanglingImageWalkNamesTheRuleAndSkipsNonIds(): void
    {
        unset($GLOBALS['_pp_test_store']['posts'][42]);
        $udc = [PP_UDC_SCOPED_KEY => [
            ['selector' => '.a', 'css' => ['background-image' => 'https://x.test/a.png']],
            ['selector' => '.b', 'css' => ['background-image' => true]],
            ['selector' => '.c', 'css' => ['background-image' => '42']],
            ['selector' => '.d', 'css' => ['background-image' => ['d' => '42']]],
        ]];
        $this->assertSame([['role' => '_scoped[2]', 'id' => 42]], _pp_udc_dangling_background_images($udc));
        $row = _pp_udc_background_image_row('band 1 ("%s")', 'section', '_scoped[2]', 42);
        $this->assertStringStartsWith('rule "', $row['where_display']);
    }

    /** A rule whose only declaration drops at emit prints nothing at all. */
    public function testARuleLeftWithNoDeclarationsPrintsNoSelector(): void
    {
        unset($GLOBALS['_pp_test_store']['posts'][42]);
        $css = pp_udc_band_css($this->band([['selector' => '.lonely', 'css' => ['background-image' => '42']], ['selector' => '.x', 'css' => ['color' => '#123']]]));
        $this->assertStringNotContainsString('.lonely', $css);
        $this->assertStringContainsString('.x{color:#123;}', $css);
    }

    // ── the cap rulings of 2026-10-05 (#1242 T4, Q5, Q6) ────────────────────

    /** Q5: the quote keywords are refused by their own named clause (M-7's open set). */
    public function testTheQuoteKeywordsAreRefusedByName(): void
    {
        $this->assertStringContainsString('quote keyword', $this->validate(['selector' => '::before', 'css' => ['content' => 'open-quote']])->get_error_message());
    }

    /** Q6: a fragment url() only where it references an element; the page is re-fetched otherwise. */
    public function testAFragmentUrlIsAdmittedOnlyOnElementReferencingProperties(): void
    {
        foreach (['filter', 'clip-path', 'mask', 'mask-image', '-webkit-mask-image', 'marker', 'marker-end', 'fill', 'stroke'] as $property) {
            $this->assertNull($this->validate(['selector' => '.x', 'css' => [$property => 'url(#g)']]), "{$property}: url(#g)");
        }
        foreach (['background-image', 'cursor', 'list-style', 'list-style-image', 'border-image', 'border-image-source', 'shape-outside', '-webkit-box-reflect', '--x',
                  '-webkit-mask-box-image', '-webkit-mask-box-image-source', 'mask-border', 'mask-border-source', '-webkit-mask-repeat', 'mask-mode', '-x-filter', '-webkit-filter'] as $property) {
            $this->assertNotNull($this->validate(['selector' => '.x', 'css' => [$property => 'url(#g)']]), "{$property}: url(#g) re-fetches the page");
        }
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['cursor' => 'url(#b), auto']]));
    }

    /** Q5: a scoped list item would paint the band's own `_css` string marker. */
    public function testListItemDisplayIsRefusedWhereTheBandSetsAStringMarker(): void
    {
        $marker = ['heading' => [PP_UDC_CSS_KEY => [':hover' => ['list-style-type' => '"BUY NOW"']]]];
        $rule   = ['selector' => '.x', 'css' => ['display' => 'list-item']];
        $this->assertNotNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [$rule]], 'section'), 'a string marker in any state counts');
        $this->assertNotNull(pp_udc_validate_map(['heading' => [PP_UDC_CSS_KEY => ['list-style' => ['d' => "'X' inside"]]]] + [PP_UDC_SCOPED_KEY => [['selector' => '.x', 'css' => ['display' => ['p' => 'inline list-item']]]]], 'section'));
        $this->assertNull(pp_udc_validate_map(['heading' => [PP_UDC_CSS_KEY => ['list-style-type' => 'square']]] + [PP_UDC_SCOPED_KEY => [$rule]], 'section'), 'a keyword marker is fine');
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [$rule]], 'section'));
        $drops = [];
        $compiled = pp_udc_compile_band($this->band([$rule], 'section', $marker), 'authored', $drops);
        $this->assertSame([], $compiled['scoped'], 'stored past the gate, the rule does not paint');
        $this->assertStringContainsString('list-item', $drops[0]['reason'] ?? '');
    }

    /** Vendor-prefixed motion joins the scoped reduced-motion guard. */
    public function testVendorPrefixedMotionIsGuardedToo(): void
    {
        $css = pp_udc_band_css($this->band([['selector' => 'li::marker', 'css' => ['-webkit-animation' => 'x 1s infinite']]]));
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce){[data-pp-band="' . self::ID . '"] li::marker{transition-duration:0.01ms;', $css);
    }

    // ── cycle-4 rulings: closed keyword sets on a marker band ────────────────

    /** The three Chromium reproductions, and the class they belong to. */
    public function testAMarkerBandTakesOnlyClosedKeywordsForDisplayAndListStyle(): void
    {
        $marker = ['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => '"BUY NOW"']]];
        $refused = [
            [['selector' => 'ul', 'css' => ['list-style-type' => 'inherit']], ['selector' => 'li', 'css' => ['display' => 'revert']]],
            [['selector' => 'li', 'css' => ['--d' => 'list-item', 'display' => 'var(--d)']]],
            [['selector' => 'li', 'css' => ['display' => 'list-item']]],
        ];
        foreach ($refused as $rules) {
            $this->assertNotNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => $rules], 'stats'), json_encode($rules));
            $drops = [];
            $compiled = pp_udc_compile_band($this->band($rules, 'stats', $marker), 'authored', $drops);
            $this->assertNotSame(count($rules), count($compiled['scoped']), 'stored past the gate, the reproduction does not paint whole');
        }
        foreach (['display' => ['unset', 'initial', 'revert-layer', 'env(x)', 'inline list-item', 'block flow'], 'list-style-type' => ['unset', 'revert', 'var(--m)', 'initial'], 'list-style' => ['inherit', 'unset', 'square inherit', 'disc inside outside']] as $property => $values) {
            foreach ($values as $value) {
                $this->assertNotNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => [$property => $value]]]], 'stats'), "{$property}: {$value}");
            }
        }
        foreach (['display' => 'unset', 'list-style-type' => 'hebrew', 'list-style' => 'square inherit'] as $property => $value) {
            $this->assertStringContainsString('fixed set of keywords', pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => [$property => $value]]]], 'stats')->get_error_message(),
                "{$property}: {$value} is refused by the closed set's own clause");
        }
        foreach (['display' => 'flex', 'list-style-type' => 'square', 'list-style' => 'disc inside'] as $property => $value) {
            $this->assertNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => [$property => ['d' => $value, 'p' => 'none']]]]], 'stats'), "{$property}: {$value}");
        }
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => ['display' => 'revert']]]], 'stats'), 'the closed set applies on a marker band only');
    }

    /** Every marker spelling the band scan must see (cycle-4 testing pass). */
    public function testTheMarkerScanSeesEveryForm(): void
    {
        $rule = [PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => ['display' => 'list-item']]]];
        foreach ([['list-style' => "'X' inside"], ['list-style-type' => "'X'"], ['list-style-type' => ['d' => '"X"']], ['-webkit-list-style-type' => '"X"'], ['-webkit-list-style-type' => ['d' => '"X"']], [':hover' => ['list-style' => ['p' => '"X"']]]] as $css) {
            $this->assertTrue(_pp_udc_band_has_string_list_marker(['_band' => [PP_UDC_CSS_KEY => $css]]), json_encode($css));
            $this->assertNotNull(pp_udc_validate_map(['_band' => [PP_UDC_CSS_KEY => $css]] + $rule, 'stats'), json_encode($css));
        }
        $this->assertFalse(_pp_udc_band_has_string_list_marker(['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => 'square', 'content-visibility' => 'auto']]]));
    }

    /** The findings walk drops the same rules the gate refuses (one predicate, two surfaces). */
    public function testFindingsDoNotDescribeARuleTheMarkerBandRefuses(): void
    {
        $marker = ['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => '"X"']]];
        $findings = pp_udc_composition_findings([$this->band([['selector' => 'li', 'css' => ['display' => 'list-item', 'mix-blend-mode' => 'multiply']]], 'stats', $marker)]);
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString('"_scoped"[0]', $finding['message'], 'a refused rule is never "emitted exactly as written"');
        }
    }

    // ── cycle-5 rulings: the cross-layer var() text channel ───────────────────

    /** F-1: a band token read by Layer 2 through var(--pp-…) is a marker channel. */
    public function testAVarMarkerInLayerTwoMakesTheBandAMarkerBand(): void
    {
        $udc = [
            '_tokens' => ['tok' => '"BUY NOW "'],
            'heading' => ['typography' => ['family' => '@tok']],
            '_band'   => [PP_UDC_CSS_KEY => ['list-style-type' => 'var(--pp-tok)']],
        ];
        $this->assertTrue(_pp_udc_band_has_string_list_marker($udc), 'presence of the channel, not the spelling of the payload');
        $this->assertNotNull(pp_udc_validate_map($udc + [PP_UDC_SCOPED_KEY => [['selector' => '.x', 'css' => ['display' => 'list-item', 'list-style-position' => 'inside']]]], 'section'));
        foreach (['var(--m)', 'square var(--x)', 'env(x)', 'inherit', 'revert'] as $value) {
            $this->assertTrue(_pp_udc_band_has_string_list_marker(['_band' => [PP_UDC_CSS_KEY => ['list-style' => $value]]]), $value);
        }
    }

    /** F-2: a scoped custom property holds no string while routed item 5 is open. */
    public function testAScopedCustomPropertyHoldsNoStringWhileItemFiveIsOpen(): void
    {
        foreach (['"BUY NOW "', "'X'", '"a" "b"', 'x "y"'] as $value) {
            $error = $this->validate(['selector' => ':not(.q)', 'css' => ['--m' => $value]]);
            $this->assertNotNull($error, $value);
            $this->assertStringContainsString('may not hold a string value', $error->get_error_message());
        }
        $this->assertNotNull($this->validate(['selector' => '.x', 'css' => ['--m' => ['d' => '4px', 'p' => '"X"']]]), 'every breakpoint leaf');
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['--m' => '4px']]));
        // The F-2 reproduction itself, Layer 2 reading the scoped property, is refused at write.
        $this->assertNotNull(pp_udc_validate_map(['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => 'var(--m)']], PP_UDC_SCOPED_KEY => [['selector' => ':not(.q)', 'css' => ['--m' => '"BUY NOW "']], ['selector' => '.x', 'css' => ['display' => 'list-item']]]], 'section'));
    }

    /** A breakpoint map whose later leaf leaves the closed set (cycle-5 testing pass). */
    public function testEveryLeafOfABreakpointMapIsInTheClosedSet(): void
    {
        $marker = ['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => '"X"']]];
        foreach ([['display' => ['d' => 'flex', 'p' => 'list-item']], ['list-style-type' => ['d' => 'square', 'p' => 'inherit']]] as $css) {
            $this->assertNotNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => $css]]], 'stats'), json_encode($css));
        }
        $drops = [];
        $compiled = pp_udc_compile_band($this->band([['selector' => 'li', 'css' => ['display' => ['d' => 'flex', 'p' => 'list-item']]]], 'stats', $marker), 'authored', $drops);
        $this->assertSame([], $compiled['scoped']);
    }

    // ── cycle-6 rulings: the counter channel, CSS-wide keywords, the emit pin ─

    /** Layer-2 integer counters make a marker band; scoped content there is "", none or normal. */
    public function testLayerTwoIntegerCountersOpenTheChannelAndRestrictScopedContent(): void
    {
        $repros = [
            [['_band' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x 5551234']]], [['selector' => '.x::before', 'css' => ['content' => 'counter(x)']]]],
            [['_band' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x 555']], 'heading' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x 1234']]], [['selector' => '.x::before', 'css' => ['content' => 'counters(x, "-")']]]],
            [['heading' => [PP_UDC_CSS_KEY => ['counter-set' => 'list-item 731']]], [['selector' => '.x', 'css' => ['display' => 'list-item', 'list-style-type' => 'upper-alpha']]]],
        ];
        foreach ($repros as [$udc, $rules]) {
            $this->assertTrue(_pp_udc_band_has_string_list_marker($udc), json_encode($udc));
            $this->assertNotNull(pp_udc_validate_map($udc + [PP_UDC_SCOPED_KEY => $rules], 'section'), json_encode($rules));
            $this->assertSame([], pp_udc_compile_band($this->band($rules, 'section', $udc), 'authored')['scoped'], 'nor does it paint from storage');
        }
        foreach (['x 1', ['d' => 'x', 'p' => 'x 2'], 'x x', 'x calc(1)'] as $value) {
            $this->assertTrue(_pp_udc_band_has_string_list_marker(['heading' => [PP_UDC_CSS_KEY => ['counter-increment' => $value]]]), json_encode($value));
        }
        $this->assertFalse(_pp_udc_band_has_string_list_marker(['heading' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x y']]]), 'names alone are no channel');
        $marker = ['_band' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x 9']]];
        foreach (['""', 'none', 'normal'] as $value) {
            $this->assertNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => '::before', 'css' => ['content' => $value]]]], 'section'), $value);
        }
        $this->assertNotNull(pp_udc_validate_map($marker + [PP_UDC_SCOPED_KEY => [['selector' => '::before', 'css' => ['content' => ['d' => '""', 'p' => 'counter(y)']]]]], 'section'));
        $this->assertSame('content on this band takes only "" (an empty string), none or normal: its "_css" opens a list-marker or counter channel, and counter() would print it (M-7)',
            _pp_udc_scoped_marker_band_problem('content', 'open-quote'), 'the marker-band clause stays as the second line of defence');
    }

    /** CSS-wide keywords refuse on every scoped text-bearing property (cycle-6 ruling). */
    public function testTextBearingPropertiesRefuseCssWideKeywords(): void
    {
        foreach (pp_layer3_text_bearing_properties() as $property) {
            foreach (['inherit', 'initial', 'unset', 'revert', 'REVERT-LAYER', ' inherit', 'inherit ', '  unset  '] as $keyword) {
                $error = $this->validate(['selector' => '.x', 'css' => [$property => $keyword]]);
                $this->assertNotNull($error, "{$property}: {$keyword}");
                $this->assertStringContainsString('CSS-wide keyword', $error->get_error_message());
            }
        }
        $this->assertNull($this->validate(['selector' => '.x', 'css' => ['text-overflow' => 'ellipsis', 'mix-blend-mode' => 'inherit']]), 'own keywords, and CSS-wide keywords elsewhere, stay fine');
    }

    /** The custom-property string refusal holds at emit too (cycle-6 testing pass). */
    public function testAStoredCustomPropertyStringIsDroppedAtEmit(): void
    {
        $udc   = ['_band' => [PP_UDC_CSS_KEY => ['list-style-type' => 'var(--m)']]];
        $rules = [['selector' => '.x', 'css' => ['--m' => '"BUY NOW "', 'opacity' => '0.5']]];
        $drops = [];
        $compiled = pp_udc_compile_band($this->band($rules, 'section', $udc), 'authored', $drops);
        $this->assertSame([], $compiled['scoped']);
        $this->assertStringContainsString('may not hold a string value', $drops[0]['reason'] ?? '');
        $this->assertStringNotContainsString('BUY NOW', pp_udc_band_css($this->band($rules, 'section', $udc)));
    }

    // ── the final ruling: counters stay out of `content` until #1254 ──────────

    /** Counters are not band-scoped: both Chromium reproductions, pinned red. */
    public function testCountersAreRefusedSoNoBandOrHeaderCanFeedThem(): void
    {
        foreach (['counter(x)', 'counters(x, "-")', 'COUNTER(x)', 'counter( list-item )'] as $value) {
            $error = $this->validate(['selector' => '::before', 'css' => ['content' => $value]]);
            $this->assertNotNull($error, $value);
            $this->assertStringContainsString('#1254', $error->get_error_message(), 'the deviation names its binding');
        }
        // The earlier-band reproduction: band A sets the integer, band B prints it.
        $a = ['component' => 'section', 'id' => 'pp-a', 'props' => [], 'udc' => ['_band' => [PP_UDC_CSS_KEY => ['counter-reset' => 'x 5551234']]]];
        $b = ['component' => 'section', 'id' => 'pp-b', 'props' => [], 'udc' => [PP_UDC_SCOPED_KEY => [['selector' => '::before', 'css' => ['content' => 'counter(x)']]]]];
        $this->assertNotNull(pp_udc_validate_map($b['udc'], 'section'), 'band B is refused at write');
        $page = pp_udc_page_authored_css([$a, $b]);
        $this->assertStringNotContainsString('counter(x)', $page, 'and from storage it never prints the counter');
        // The site-header reproduction: chrome sets the integer, any band would print it.
        $this->assertNull(pp_udc_validate_map(['_band' => [PP_UDC_CSS_KEY => ['counter-reset' => 'y 8675309']]], pp_udc_chrome_names()[0]), 'premise: Layer 2 chrome still takes the integer (#1254)');
        $c = ['component' => 'section', 'id' => 'pp-c', 'props' => [], 'udc' => [PP_UDC_SCOPED_KEY => [['selector' => '::before', 'css' => ['content' => 'counter(y)']]]]];
        $this->assertNotNull(pp_udc_validate_map($c['udc'], 'section'));
        $this->assertStringNotContainsString('counter(y)', pp_udc_band_css($c));
    }

    /** The last ruling: a rule that can match the band root takes only the closed display set. */
    public function testARootMatchingRuleTakesOnlyTheClosedDisplaySet(): void
    {
        // The Chromium reproduction: band A's Layer-2 counter numbers band B's root marker.
        $b = [PP_UDC_SCOPED_KEY => [['selector' => ':not(.zz)', 'css' => ['display' => 'list-item', 'list-style-type' => 'decimal', 'list-style-position' => 'inside']]]];
        $error = pp_udc_validate_map($b, 'section');
        $this->assertNotNull($error);
        $this->assertStringContainsString('can match the band root', $error->get_error_message());
        $this->assertSame([], pp_udc_compile_band($this->band($b[PP_UDC_SCOPED_KEY]), 'authored')['scoped'], 'nor does it paint from storage');
        foreach ([':hover', '::before', ' :is(.a)', ':first-child'] as $selector) {
            foreach (['list-item', 'inline list-item', 'revert', 'var(--d)', ['d' => 'block', 'p' => 'list-item']] as $display) {
                $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => $selector, 'css' => ['display' => $display]]]], 'section'), json_encode([$selector, $display]));
            }
            $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => $selector, 'css' => ['display' => 'flex']]]], 'section'), "{$selector}: flex");
        }
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => 'li', 'css' => ['display' => 'list-item']]]], 'section'), 'a descendant list item is numbered from its own parent and stays admitted');
    }

    /** The final ruling: no custom property on a rule that can match the band root. */
    public function testARootMatchingRuleSetsNoCustomProperty(): void
    {
        $repro = [
            '_band'          => [PP_UDC_CSS_KEY => ['display' => 'var(--d, block)']],
            PP_UDC_SCOPED_KEY => [['selector' => ':not(.q)', 'css' => ['--d' => 'list-item', 'list-style-type' => 'decimal', 'list-style-position' => 'inside']]],
        ];
        $error = pp_udc_validate_map($repro, 'section');
        $this->assertNotNull($error, 'the Chromium reproduction ("5551234.") is refused at write');
        $this->assertStringContainsString('may not be set on a rule that can match the band root', $error->get_error_message());
        $this->assertSame([], pp_udc_compile_band($this->band($repro[PP_UDC_SCOPED_KEY], 'section', ['_band' => $repro['_band']]), 'authored')['scoped'], 'and dropped from storage');
        $fallback = [PP_UDC_SCOPED_KEY => [['selector' => ':hover', 'css' => ['--d' => 'block', 'display' => 'var(--d, list-item)']]]];
        $this->assertNotNull(pp_udc_validate_map($fallback, 'section'), 'the var(--d, list-item) fallback shape');
        foreach ([':hover', '::before', ' :is(.a)', ':first-child'] as $selector) {
            $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => $selector, 'css' => ['--gap' => '4px']]]], 'section'), $selector);
        }
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => '.inner', 'css' => ['--gap' => '4px']]]], 'section'), 'on an inner element a custom property stays admitted');
        $this->assertNotNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => ':hover', 'css' => ['opacity' => '0.5', '--d' => 'list-item']]]], 'section'), 'not only the first declaration');
        $this->assertNull(pp_udc_validate_map([PP_UDC_SCOPED_KEY => [['selector' => ':hover', 'css' => ['-webkit-line-clamp' => '2']]]], 'section'), 'a vendor prefix is not a custom property');
    }

    /** `wp pp schema` is a CLI operator's discovery route; every field derives from the engine. */
    public function testTheSchemaReportTellsAnOperatorTheScopedSheetExists(): void
    {
        $report = pp_component_schema_report('section');
        $this->assertSame(PP_UDC_SCOPED_KEY, $report['udc_scoped']['key']);
        $this->assertSame(pp_udc_scoped_rule_keys(), $report['udc_scoped']['rule_keys']);
        $this->assertSame(PP_UDC_SCOPED_MAX_DECLARATIONS, $report['udc_scoped']['max_declarations']);
        $this->assertSame(array_keys(pp_udc_scoped_pseudo_classes()), $report['udc_scoped']['pseudo_classes']);
        $this->assertArrayNotHasKey('udc_scoped', pp_component_schema_report(pp_udc_chrome_names()[0]), 'chrome takes no scoped sheet');
    }

    /** One owner for the Layer-3 CSS function list: the content gate reads the scoped sheet's. */
    public function testBothLayerThreeChannelsReadOneFunctionList(): void
    {
        $this->assertSame(pp_layer3_css_functions(), pp_content_css_functions(), 'byte-for-byte the same list (#1242 unification)');
        foreach (['path', 'stylistic', 'swash'] as $ruled) {
            $this->assertArrayHasKey($ruled, pp_layer3_css_functions(), "{$ruled}() was ruled admissible for Layer 3");
        }
        foreach (['attr', 'anchor', 'anchor-size', 'paint', 'element', '-moz-element', 'url', 'image-set'] as $refused) {
            $this->assertArrayNotHasKey($refused, pp_layer3_css_functions());
        }
    }
}
