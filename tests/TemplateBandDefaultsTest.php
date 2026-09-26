<?php
/**
 * tests/TemplateBandDefaultsTest.php
 *
 * #1171 — a page a TEMPLATE renders (the posts page, a single post, an archive, search
 * results, the 404, a page on the default template) paints its components' role defaults.
 *
 * THE DEFECT. The role-defaults tier prints under `[data-pp-component="<name>"]`, and
 * every template-rendered section already carries that attribute. What was missing was
 * the EMISSION: functions.php built the tier from pp_udc_current_composition() alone,
 * which answers [] on every route that is not a singular page with a composition. So
 * those pages rendered their v2 components as bare markup (dev /blog/: hero padding 0,
 * no card fill, no card border).
 *
 * THE RULED SHAPE (orchestrator D1 = A). A template DECLARES the components it renders,
 * as the second argument of pp_base_template(), which records the list before
 * wp_head() — the emitter runs inside wp_head at `wp_enqueue_scripts`, before the
 * content callback, so it cannot learn from the render. The defaults tier is then the
 * composition's components plus the template's, deduplicated. No band id is invented,
 * so nothing authored can reach a template band (that surface is the recorded
 * follow-up candidate, not this change), and a composed page is byte-identical.
 *
 * WHAT THIS FILE PROVES:
 *   1. The declaration keeps only v2, non-chrome component names, in order, once each.
 *   2. The request's defaults items are the composition's then the template's, and a
 *      composed page with nothing declared emits exactly what it emitted before.
 *   3. functions.php feeds that union to the DEFAULTS tier and only the composition to
 *      the AUTHORED tier (call-shape pins, comments stripped).
 *   4. pp_base_template() records the list BEFORE wp_head() (a real run of the shipped
 *      base.php in a child process with WordPress's output functions stubbed).
 *   5. THE DRIFT PIN: every file that calls pp_base_template() declares exactly the
 *      components it renders by literal name, and any other pp_get_component() call
 *      shape in such a file FAILS (fail-closed: a variable name would be a component
 *      the declaration cannot see). The composition loop is the one sanctioned
 *      non-literal shape, and a file that uses it declares nothing.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TemplateBandDefaultsTest extends TestCase
{
    protected function tearDown(): void
    {
        pp_udc_declare_template_components([]);
    }

    // ── 1. The declaration ──────────────────────────────────────────────────

    public function testTheDeclarationKeepsV2NamesInOrderOnceEach(): void
    {
        pp_udc_declare_template_components(['hero', 'grid', 'hero', 'section']);
        $this->assertSame(['hero', 'grid', 'section'], pp_udc_template_components());
    }

    public function testTheDeclarationDropsChromeUnknownAndNonStringNames(): void
    {
        // Chrome has its own tier (pp_udc_chrome_defaults_css): printed as a component
        // row it would be the #1125 shape, a second copy under the wrong scope.
        pp_udc_declare_template_components(['nav', 'footer', 'sidebar', '../hero', 42, null, ['hero'], 'cta']);
        $this->assertSame(['cta'], pp_udc_template_components());
    }

    public function testAnEmptyDeclarationClearsAnEarlierOne(): void
    {
        pp_udc_declare_template_components(['hero']);
        pp_udc_declare_template_components([]);
        $this->assertSame([], pp_udc_template_components());
    }

    /**
     * The store's INITIAL value, read in a fresh process: in this process every test's
     * tearDown() has already reset it, so an in-process read could never see a wrong
     * initial value (the testing specialist's mutation proof: a store seeded with
     * ['hero'] stayed green). A request that never reaches pp_base_template() must
     * print no template defaults.
     */
    public function testNothingIsDeclaredUntilATemplateDeclaresIt(): void
    {
        $out = $this->runBaseTemplate('echo "INIT:" . json_encode(pp_udc_template_components()) . "\n";');
        $this->assertStringContainsString('INIT:[]', $out);
    }

    // ── 2. The request's defaults items ─────────────────────────────────────

    public function testATemplateRequestEmitsItsComponentsDefaults(): void
    {
        pp_udc_declare_template_components(['hero', 'grid']);
        $css = pp_udc_page_defaults_css(pp_udc_request_defaults_items([]));
        $this->assertSame(
            pp_udc_component_defaults_css('hero') . pp_udc_component_defaults_css('grid'),
            $css
        );
        $this->assertStringContainsString('[data-pp-component="hero"]', $css);
        $this->assertStringContainsString('[data-pp-component="grid"]', $css);
    }

    public function testWithNothingDeclaredEveryComposedPageEmitsExactlyWhatItDidBefore(): void
    {
        $checked = 0;
        foreach (array_keys(pp_composable_components()) as $name) {
            $composition = [['component' => (string) $name, 'props' => []]];
            $this->assertSame(
                pp_udc_page_defaults_css($composition),
                pp_udc_page_defaults_css(pp_udc_request_defaults_items($composition)),
                "a composed {$name} band must emit byte-identical defaults"
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(10, $checked, 'the loop must actually cover the component roster');
        // And the whole multi-band case, order included.
        $all = array_map(static fn($n) => ['component' => (string) $n], array_keys(pp_composable_components()));
        $this->assertSame(pp_udc_page_defaults_css($all), pp_udc_page_defaults_css(pp_udc_request_defaults_items($all)));
    }

    public function testAComponentOnBothSidesEmitsOnceAndTheCompositionOrderLeads(): void
    {
        pp_udc_declare_template_components(['hero', 'grid']);
        $composition = [['component' => 'cta'], ['component' => 'hero']];
        $css = pp_udc_page_defaults_css(pp_udc_request_defaults_items($composition));
        $this->assertSame(
            pp_udc_component_defaults_css('cta') . pp_udc_component_defaults_css('hero') . pp_udc_component_defaults_css('grid'),
            $css
        );
        $this->assertSame(1, substr_count($css, pp_udc_component_defaults_css('hero')));
    }

    public function testTheTemplateItemsCarryNoBandIdSoTheAuthoredTierIgnoresThem(): void
    {
        pp_udc_declare_template_components(['hero', 'section']);
        $items = pp_udc_request_defaults_items([]);
        foreach ($items as $item) {
            $this->assertSame(['component'], array_keys($item), 'a template item is a component name and nothing else');
        }
        $this->assertCount(2, $items);
        $this->assertSame('', pp_udc_page_authored_css($items), 'no id means no authored block');
    }

    // ── 3. functions.php wiring ─────────────────────────────────────────────

    /**
     * A file's source with its comments removed BY THE PHP TOKENIZER. Several of these
     * files explain the change in prose that names the very calls the pins look for, and
     * a regex stripper cannot tell a `/*` inside a string literal from a comment.
     */
    private static function code(string $relative): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__) . '/' . $relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    public function testFunctionsPhpFeedsTheUnionToTheDefaultsTierAndTheCompositionToTheAuthoredTier(): void
    {
        $code = self::code('functions.php');
        $this->assertMatchesRegularExpression(
            '/\$pp_udc_defaults\s*=\s*pp_udc_page_defaults_css\(\s*pp_udc_request_defaults_items\(\s*\$pp_udc_composition\s*\)\s*\)\s*;/',
            $code
        );
        $this->assertMatchesRegularExpression(
            '/\$pp_udc_authored\s*=\s*pp_udc_page_authored_css\(\s*\$pp_udc_composition\s*\)\s*;/',
            $code,
            'the authored tier stays composition-only: a template band has no id to scope to'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/pp_udc_page_defaults_css\(\s*\$pp_udc_composition\s*\)/',
            $code,
            'the composition-only defaults call is exactly the #1171 defect'
        );
    }

    // ── 4. pp_base_template() records the list before wp_head() ─────────────

    /**
     * Runs the SHIPPED templates/base.php in a child process (it defines functions and
     * prints a document, so it cannot share this process), with the WordPress output
     * functions stubbed. The wp_head() stub prints what the declaration holds at the
     * moment the head is printed, which is the moment the emitter reads it.
     */
    private function runBaseTemplate(string $call): string
    {
        $root   = dirname(__DIR__);
        $script = '
            require ' . var_export($root . '/tests/bootstrap.php', true) . ';
            foreach (["language_attributes","wp_body_open","wp_footer"] as $f) { if (!function_exists($f)) { eval("function $f(...\$a) {}"); } }
            if (!function_exists("bloginfo")) { function bloginfo(...$a) {} }
            if (!function_exists("esc_html_e")) { function esc_html_e($t, ...$a) { echo $t; } }
            if (!function_exists("wp_head")) { function wp_head() { echo "HEAD:" . json_encode(pp_udc_template_components()) . "\n"; } }
            if (!function_exists("pp_get_component")) { function pp_get_component($n, $p = []) {} }
            require ' . var_export($root . '/templates/base.php', true) . ';
            ' . $call . '
        ';
        $out = [];
        $rc  = 0;
        exec(PHP_BINARY . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "base.php run failed:\n" . implode("\n", $out));
        return implode("\n", $out);
    }

    public function testBaseTemplateRecordsTheDeclarationBeforeTheHeadIsPrinted(): void
    {
        $out = $this->runBaseTemplate('pp_base_template(function () { echo "BODY:" . json_encode(pp_udc_template_components()); }, ["hero", "nav", "cta"]);');
        $head = strpos($out, 'HEAD:["hero","cta"]');
        $this->assertNotFalse($head, "wp_head must see the filtered declaration:\n" . $out);
        $body = strpos($out, 'BODY:');
        $this->assertNotFalse($body);
        $this->assertLessThan($body, $head);
    }

    public function testBaseTemplateWithNoDeclarationClearsAnyEarlierOne(): void
    {
        $out = $this->runBaseTemplate('pp_udc_declare_template_components(["hero"]); pp_base_template(function () {});');
        $this->assertStringContainsString('HEAD:[]', $out, "a composition template declares nothing:\n" . $out);
    }

    // ── 5. The drift pin ────────────────────────────────────────────────────

    /**
     * What a template file renders, read from PHP's own tokens (never a regex over the
     * text: a `/*` inside a string literal would otherwise hide calls, the testing
     * specialist's second mutation proof).
     *
     * @return array{base: bool, literals: list<string>, loops: int, declared: ?list<string>, illegal: list<string>}
     */
    private static function analyse(string $source): array
    {
        $toks = [];
        foreach (token_get_all($source) as $t) {
            $t = is_array($t) ? $t : [null, $t];
            if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $toks[] = $t;
            }
        }
        // Component names compare VERBATIM: the runtime is case-sensitive on both sides
        // (pp_udc_is_v2_component('Hero') is false), so a pin that lowercased them would
        // pass a declaration the runtime drops. Only FUNCTION names are case-insensitive.
        $unquote = static fn(string $s): string => substr($s, 1, -1);
        $name    = static fn(array $t): string => in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            ? strtolower(ltrim($t[1], '\\')) : '';
        $out = ['base' => false, 'literals' => [], 'loops' => 0, 'declared' => null, 'illegal' => []];
        $n   = count($toks);
        // Variables this file assigns a closure or arrow function: the one dynamic call
        // a template may make (front-page.php's `$notice(...)`). No name-in-a-string rule
        // can close a split or computed name (`'pp_get_' . 'component'`, a nowdoc,
        // strrev()), so any OTHER dynamic call fails closed on its call shape.
        // A variable qualifies only when EVERY assignment to it in the file is a closure:
        // one later reassigned a string (or touched by a compound assignment) does not.
        $closures = [];
        $assignOps = [T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
            T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
            T_POW_EQUAL, T_COALESCE_EQUAL];
        for ($i = 0; $i + 1 < $n; $i++) {
            if ($toks[$i][0] !== T_VARIABLE) {
                continue;
            }
            $var = $toks[$i][1];
            if (in_array($toks[$i + 1][0], $assignOps, true)) {
                $closures[$var] = false;
            } elseif ($toks[$i + 1][1] === '=') {
                $isClosure = in_array($toks[$i + 2][0] ?? null, [T_FUNCTION, T_FN], true)
                    || (($toks[$i + 2][0] ?? null) === T_STATIC && in_array($toks[$i + 3][0] ?? null, [T_FUNCTION, T_FN], true));
                $closures[$var] = ($closures[$var] ?? true) && $isClosure;
            }
        }
        $closures = array_filter($closures);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            $next = $toks[$i + 1][1] ?? '';
            if ($next === '(' && (($t[0] === T_VARIABLE && !isset($closures[$t[1]]))
                || ($t[0] === null && in_array($t[1], [')', ']'], true)))) {
                $out['illegal'][] = 'a dynamic call (a variable, a call result or an element called as a function)';
            }
            if ($name($t) === 'pp_get_component' && ($toks[$i - 1][0] ?? null) !== T_FUNCTION) {
                if ($next !== '(') {
                    $out['illegal'][] = 'pp_get_component named without being called';
                    continue;
                }
                $a = $toks[$i + 2] ?? [null, ''];
                if ($a[0] === T_CONSTANT_ENCAPSED_STRING && in_array($toks[$i + 3][1] ?? '', [',', ')'], true)) {
                    $out['literals'][] = $unquote($a[1]);
                } elseif (($a[0] ?? null) === T_STRING_CAST
                    && ($toks[$i + 3][1] ?? '') === '$item' && ($toks[$i + 4][1] ?? '') === '['
                    && ($toks[$i + 5][1] ?? '') === "'component'" && ($toks[$i + 6][1] ?? '') === ']'
                    && ($toks[$i + 7][1] ?? '') === ',') {
                    $out['loops']++;
                } else {
                    $out['illegal'][] = 'a pp_get_component() call whose name is not a literal';
                }
                continue;
            }
            // A callable string, with or without its leading backslash, and any literal
            // carrying `get_component` (a concatenated name) fail closed.
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && stripos($unquote($t[1]), 'get_component') !== false) {
                $out['illegal'][] = 'pp_get_component as a callable string';
            }
            if (in_array($name($t), ['get_template_part', 'locate_template', 'load_template', 'call_user_func', 'call_user_func_array'], true)) {
                $out['illegal'][] = $name($t) . '() renders through a path the declaration cannot see';
            }
            if (in_array($t[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $stmt = '';
                for ($j = $i + 1; $j < $n && $toks[$j][1] !== ';'; $j++) {
                    $stmt .= $toks[$j][1];
                }
                if (strpos($stmt, "'/templates/base.php'") === false) {
                    $out['illegal'][] = 'an include other than templates/base.php';
                }
            }
            if ($name($t) === 'pp_base_template' && $next === '(') {
                $out['base'] = true;
                $depth = 0;
                $second = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    // STRUCTURAL tokens only: a string fragment that happens to be `)`
                    // (the `)` of "Results ($count)") is T_ENCAPSED_AND_WHITESPACE, not
                    // a bracket, and counting it would end the walk early.
                    $id = $toks[$j][0];
                    $v  = $id === null ? $toks[$j][1] : '';
                    if (in_array($v, ['(', '[', '{'], true) || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                        $depth++;
                    } elseif (in_array($v, [')', ']', '}'], true)) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($v === ',' && $depth === 1 && $second === null) {
                        $second = [];
                        continue;
                    }
                    if ($second !== null && $depth >= 1) {
                        $second[] = $toks[$j];
                    }
                }
                if ($second !== null) {
                    $list = [];
                    $ok   = ($second[0][1] ?? '') === '[';
                    for ($k = 1; $ok && $k < count($second); $k++) {
                        [$id, $v] = $second[$k];
                        if ($id === T_CONSTANT_ENCAPSED_STRING) {
                            $list[] = $unquote($v);
                        } elseif ($v !== ',' && $v !== ']') {
                            $ok = false;
                        }
                    }
                    if ($ok) {
                        $out['declared'] = $list;
                    } else {
                        $out['illegal'][] = "pp_base_template()'s second argument is not a literal list";
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string, array> relative path => analyse(), for every pp_base_template() caller. */
    private static function callers(): array
    {
        $out = [];
        foreach (self::themePhpFiles() as $file) {
            $root = dirname(__DIR__);
            $rel = substr($file, strlen($root) + 1);
            if ($rel === 'templates/base.php') {
                continue; // the definer; its nav/footer calls are chrome
            }
            $a = self::analyse((string) file_get_contents($file));
            if ($a['base']) {
                $out[$rel] = $a;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every theme PHP file, at any depth (a template in templates/parts/ or
     * page-templates/ must not escape the pin), minus vendored, test and build trees.
     *
     * @return list<string>
     */
    private static function themePhpFiles(): array
    {
        $root  = dirname(__DIR__);
        $skip  = ['vendor', 'node_modules', 'tests', 'test-results', 'evidence'];
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f) use ($root, $skip): bool {
                if ($f->isDir()) {
                    $rel = substr($f->getPathname(), strlen($root) + 1);
                    return $f->getFilename()[0] !== '.' && !in_array($rel, $skip, true);
                }
                return $f->getExtension() === 'php';
            }
        ));
        foreach ($it as $f) {
            $files[] = $f->getPathname();
        }
        sort($files);
        return $files;
    }

    /**
     * A HELPER that renders a component is as invisible to a template's declaration as
     * a variable name is (the adversarial pass's finding: pp_comments_template() renders
     * none today, a future helper might). So the set of lib/ call sites of
     * pp_get_component() is pinned: every one of today's renders a COMPOSITION or chrome
     * (post-apply validation, the editor preview, role paint), never a template's bands.
     * A new call site fails here and has to be looked at before it can ship.
     */
    public function testNoLibHelperRendersAComponentOutsideTheKnownCompositionPaths(): void
    {
        $sites = [];
        $root  = dirname(__DIR__);
        // THE WHOLE THEME TREE, not lib/ alone: functions.php, comments.php, a lib/
        // subdirectory or a component that nests another component would each be a
        // render path no declaration sees. The pp_base_template() callers are the drift
        // pin's subjects and are checked there; everything else is pinned here.
        $callers = array_keys(self::callers());
        foreach (self::themePhpFiles() as $file) {
            $rel = substr($file, strlen($root) + 1);
            if (in_array($rel, $callers, true)) {
                continue;
            }
            $n = count(self::analyse((string) file_get_contents($file))['literals'])
                + count(array_filter(
                    self::analyse((string) file_get_contents($file))['illegal'],
                    // Every pp_get_component()-specific shape: a non-literal call, the name
                    // as a callable string (call_user_func('pp_get_component', …)), and the
                    // name without a call (a first-class callable). Includes and
                    // call_user_func() in general are ordinary in lib/ and not counted.
                    static fn(string $r): bool => in_array($r, [
                        'a pp_get_component() call whose name is not a literal',
                        'pp_get_component as a callable string',
                        'pp_get_component named without being called',
                    ], true)
                ));
            if ($n > 0) {
                $sites[$rel] = $n;
            }
        }
        ksort($sites);
        $this->assertSame(
            ['lib/admin.php' => 3, 'lib/post-apply-validate.php' => 1, 'lib/udc.php' => 1, 'templates/base.php' => 2],
            $sites,
            'a lib/ helper that renders a component is a render path no template declaration can see'
        );
    }

    public function testEveryTemplateDeclaresExactlyTheComponentsItRendersByLiteralName(): void
    {
        $callers = self::callers();
        $this->assertSame(
            ['404.php', 'templates/archive.php', 'templates/composition.php', 'templates/front-page.php',
             'templates/home.php', 'templates/page.php', 'templates/search.php', 'templates/single.php'],
            array_keys($callers),
            'the subject set: a caller added or lost must be looked at, not silently scanned or skipped'
        );

        $declaring = 0;
        foreach ($callers as $rel => $a) {
            $this->assertSame([], $a['illegal'], "{$rel}: renders through a path the declaration cannot see");

            if ($a['loops'] > 0) {
                $this->assertSame([], $a['literals'], "{$rel}: a composition template renders only its composition");
                $this->assertTrue($a['declared'] === null || $a['declared'] === [], "{$rel}: a composition template declares nothing");
                continue;
            }

            $this->assertNotNull($a['declared'], "{$rel}: renders components but declares none (the #1171 defect)");
            $rendered = array_values(array_unique($a['literals']));
            sort($rendered);
            $list = $a['declared'];
            sort($list);
            $this->assertSame($rendered, $list, "{$rel}: declared list must equal the literal pp_get_component() names");
            $this->assertNotSame([], $rendered);
            $declaring++;
        }
        $this->assertSame(6, $declaring, 'six templates render components by name');
    }

    /**
     * The analyser itself, against the shapes that would walk around it. Each source is a
     * template a future edit could plausibly write; each must be SEEN, not scanned past.
     */
    public function testTheDriftAnalyserSeesThroughStringsCommentsAndIndirection(): void
    {
        $wrap = static fn(string $body, string $list = "['hero']"): string =>
            "<?php\nrequire_once get_template_directory() . '/templates/base.php';\npp_base_template(function () {\n{$body}\n}, {$list});\n";

        // A `/*` inside a string does not open a comment: the grid call is counted.
        $a = self::analyse($wrap("pp_get_component('hero', ['a' => 'image/*']);\npp_get_component('grid', ['b' => '*/']);"));
        $this->assertSame(['hero', 'grid'], $a['literals']);
        $this->assertSame([], $a['illegal']);
        // A real comment naming a call is ignored rather than counted.
        $a = self::analyse($wrap("/* pp_get_component('grid', []); */\n// pp_get_component('cta', []);\npp_get_component('hero', []);"));
        $this->assertSame(['hero'], $a['literals']);
        // PHP function names are case-insensitive, and so is the analyser.
        $this->assertSame(['grid'], self::analyse($wrap("PP_Get_Component('grid', []);"))['literals']);
        $this->assertSame(['grid'], self::analyse($wrap("\\pp_get_component('grid', []);"))['literals']);

        foreach ([
            'variable name'        => "pp_get_component(\$name, []);",
            'callable string'      => "call_user_func('pp_get_component', 'grid', []);",
            'string in a variable' => "\$f = 'pp_get_component'; \$f('grid');",
            'first-class callable' => "\$f = pp_get_component(...);",
            'a partial'            => "require __DIR__ . '/partial.php';",
            'a template part'      => "get_template_part('templates/partial');",
            'a concatenated name'  => "pp_get_component('gr' . 'id', []);",
        ] as $label => $body) {
            $this->assertNotSame([], self::analyse($wrap($body))['illegal'], "{$label} must fail closed");
        }
        $this->assertNotSame([], self::analyse($wrap("pp_get_component('hero', []);", '$list'))['illegal'], 'a non-literal list');
        // Component names compare verbatim: a 'Hero' declaration is one the runtime drops.
        $a = self::analyse($wrap("pp_get_component('hero', []);", "['Hero']"));
        $this->assertNotSame($a['literals'], $a['declared']);
        // A bracket inside a string is not a bracket: the list after it is still read.
        $a = self::analyse($wrap("echo \"Results (\$count)\";\npp_get_component('hero', []);"));
        $this->assertSame(['hero'], $a['declared']);
        $this->assertSame([], $a['illegal']);
        // A callable string with a leading backslash, and a concatenated name, fail closed.
        $this->assertNotSame([], self::analyse($wrap("array_map('\\\\pp_get_component', ['grid']);"))['illegal']);
        $this->assertNotSame([], self::analyse($wrap("\$f = 'pp_' . 'get_component'; \$f('grid');"))['illegal']);
        // A split or computed name no string rule can see still fails closed on its call.
        foreach ([
            "\$f = 'pp_get_' . 'component'; \$f('grid');",
            "\$f = <<<'X'\npp_get_component\nX;\n\$f('grid');",
            "\$f = implode('', ['pp_get_compo', 'nent']); \$f('grid');",
            "(\$f = 'strrev')('tnenopmoc_teg_pp')('grid');",
            "\$m['x']('grid');",
            "\$n = function () {}; \$n = 'pp_get_' . 'component'; \$n('grid');",
            "\$n = function () {}; \$n .= 'x'; \$n('grid');",
        ] as $body) {
            $this->assertNotSame([], self::analyse($wrap($body))['illegal'], $body);
        }
        // The sanctioned shape: a closure the file defines, called by its variable.
        $this->assertSame([], self::analyse($wrap("\$n = function (string \$x): void { echo \$x; };\n\$n('hi');\npp_get_component('hero', []);"))['illegal']);

        $none = self::analyse("<?php\npp_base_template(function () {\npp_get_component('hero', []);\n});\n");
        $this->assertTrue($none['base']);
        $this->assertNull($none['declared'], 'no second argument reads as no declaration');
        $this->assertSame(['hero'], $none['literals']);
    }
}
