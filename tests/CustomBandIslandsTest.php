<?php
/**
 * tests/CustomBandIslandsTest.php
 *
 * Layer 3C: the custom band and its content islands (docs/v2/LAYER-3-CONTRACT.md §7; #1242
 * T5; the §10 T-13 row, routed item 10, the E6 emission belt, P-7, M-8 and M-21).
 *
 * Every write goes through the real authoring surface (Section 14.1): update_composition,
 * update_component, create_page, add_component and `wp pp operate patch`. Raw meta is used
 * only where a pin exists to test STORED bytes that went around the gate (the render path's
 * fail-closed and belt pins).
 *
 *   §1 the component and its admissions (stored verbatim, rendered into the hosts)
 *   §2 the island rules in markup (name, kind, host matrix per kind, emptiness, caps)
 *   §3 the band-level rules (unknown island, non-string island, E6 inside islands)
 *   §4 routed item 10: island content verified in its host's full ancestor chain
 *   §5 M-8 bounds
 *   §6 the editing surfaces: merge by key, the one-field diff, CAS, undo, operate patch, P-7
 *   §7 the render path: composition, fail closed, no shortcodes, the E6 emission belt
 *   §8 findings: custom_band_unverified (info), custom_island_empty (warning)
 *   §9 T-17: a maximal custom band's write and render cost
 */

use PHPUnit\Framework\TestCase;

class CustomBandIslandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    private function band(string $markup, array $islands = [], array $extra = []): array
    {
        $props = ['markup' => $markup] + ($islands === [] ? [] : ['islands' => $islands]);
        return ['component' => 'custom', 'props' => $props + $extra];
    }

    private function page(): int
    {
        return pp_create_page('Custom band', 'draft');
    }

    private function write(int $post_id, array $composition): array
    {
        return pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $composition]);
    }

    /** Raw meta: STORED bytes that never passed the gate (render-path pins only). */
    private function storeRaw(int $post_id, array $composition): void
    {
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode($composition)));
    }

    private function render(array $props): string
    {
        ob_start();
        pp_get_component('custom', $props);
        return (string) ob_get_clean();
    }

    private function assertRefused(array $result, string $clause_text, string $prop_fragment = ''): void
    {
        $this->assertFalse($result['ok'], 'the write must be refused');
        $this->assertSame('content_construct_excluded', $result['error_code'], $result['error'] ?? '');
        $this->assertStringContainsString($clause_text, $result['error']);
        if ($prop_fragment !== '') {
            $this->assertStringContainsString($prop_fragment, $result['error']);
        }
        $this->assertStringContainsString('Nothing was stored', $result['error']);
    }

    // ── §1 the component and its admissions ──────────────────────────────────

    private const MARKUP = '<div class="split"><h2 data-pp-island="title" data-pp-island-kind="inline"></h2>'
        . '<div data-pp-island="body" data-pp-island-kind="rich"></div>'
        . '<a class="btn" href="/start"><span data-pp-island="cta"></span></a></div>';

    private const ISLANDS = [
        'title' => 'Ship <em>faster</em>',
        'body'  => '<p>One band, <strong>your</strong> structure.</p><ul><li>a</li></ul>',
        'cta'   => 'Get started & go',
    ];

    public function testACustomBandIsStoredVerbatimAndRendersEachIslandIntoItsHost(): void
    {
        $post_id = $this->page();
        $result = $this->write($post_id, [$this->band(self::MARKUP, self::ISLANDS)]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $stored = pp_get_composition($post_id)[0]['props'];
        $this->assertSame(self::MARKUP, $stored['markup'], 'refuse, never coerce: accepted bytes are stored as sent');
        $this->assertSame(self::ISLANDS, $stored['islands']);

        $html = $this->render($stored + ['__pp_udc_band' => 'pp-1a2b3c4d']);
        $this->assertMatchesRegularExpression('#<section id="pp-[0-9a-f]{8}" class="custom" data-pp-component="custom" data-pp-band="pp-1a2b3c4d">#', $html,
            'the band root is the template\'s own, with the engine-minted anchor and band id');
        $this->assertMatchesRegularExpression('#<h2\s*>Ship <em>faster</em></h2>#', $html, 'the inline island renders as markup in its host');
        $this->assertMatchesRegularExpression('#<div\s*><p>One band, <strong>your</strong> structure.</p><ul><li>a</li></ul></div>#', $html);
        $this->assertMatchesRegularExpression('#<span\s*>Get started &amp; go</span>#', $html, 'a plain island is escaped text');
        $this->assertStringNotContainsString('data-pp-island', $html, 'the island attributes never reach the page (the belt)');
    }

    public function testEveryAuthoringSurfaceAcceptsACustomBand(): void
    {
        $created = pp_execute_action('create_page', ['title' => 'C', 'composition' => [$this->band(self::MARKUP, self::ISLANDS)]]);
        $this->assertTrue($created['ok'], (string) ($created['error'] ?? ''));

        $post_id = $this->page();
        $this->write($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);
        $added = pp_execute_action('add_component', ['post_id' => $post_id, 'component' => 'custom',
            'props' => ['markup' => self::MARKUP, 'islands' => self::ISLANDS]]);
        $this->assertTrue($added['ok'], (string) ($added['error'] ?? ''));
        $this->assertSame('custom', pp_get_composition($post_id)[1]['component']);

        $refused = pp_execute_action('add_component', ['post_id' => $post_id, 'component' => 'custom',
            'props' => ['markup' => '<p data-pp-island="a">text</p>']]);
        $this->assertFalse($refused['ok'], 'add_component runs the same gate');
        $this->assertSame('content_construct_excluded', $refused['error_code']);
    }

    // ── §2 the island rules in markup ───────────────────────────────────────

    /** §7.2 + P-25: every listed host of every kind is admitted. */
    public static function admittedHosts(): array
    {
        $out = [];
        foreach (pp_content_island_hosts() as $kind => $hosts) {
            foreach (array_keys($hosts) as $tag) {
                $out["{$kind} in <{$tag}>"] = [$kind, $tag];
            }
        }
        return $out;
    }

    private static function hostMarkup(string $kind, string $tag): string
    {
        $host = sprintf('<%1$s data-pp-island="x" data-pp-island-kind="%2$s"></%1$s>', $tag, $kind);
        return match ($tag) {
            'li'          => '<ul>' . $host . '</ul>',
            'dd', 'dt'    => '<dl>' . $host . '</dl>',
            'td', 'th'    => '<table><tbody><tr>' . $host . '</tr></tbody></table>',
            'caption'     => '<table>' . $host . '<tbody><tr><td>c</td></tr></tbody></table>',
            'summary'     => '<details>' . $host . '</details>',
            'figcaption'  => '<figure>' . $host . '</figure>',
            'legend'      => '<fieldset>' . $host . '</fieldset>',
            default       => $host,
        };
    }

    /** @dataProvider admittedHosts */
    public function testEveryListedHostIsAdmittedForItsKind(string $kind, string $tag): void
    {
        $markup = self::hostMarkup($kind, $tag);
        $losses = array_filter(pp_content_sanitize($markup, 'custom')['losses'],
            static fn ($l) => $l['clause'] === '§7.2');
        $this->assertSame([], array_values($losses), "<{$tag}> must host a {$kind} island");
    }

    public function testP25ThePlainOnlyHostsAreAdmittedAndAnAWritesItsLabelAsText(): void
    {
        foreach (['a', 'button', 'time', 'code', 'abbr', 'sub', 'sup'] as $tag) {
            $this->assertArrayHasKey($tag, pp_content_island_hosts()['plain'], "<{$tag}> is a plain host (P-25)");
            $this->assertArrayNotHasKey($tag, pp_content_island_hosts()['inline']);
        }
        $post_id = $this->page();
        $ok = $this->write($post_id, [$this->band('<a href="/x" data-pp-island="label"></a>', ['label' => '<a href="/y">x</a>'])]);
        $this->assertTrue($ok['ok'], 'a plain island holds TEXT, so markup in it is admitted as text');
        $html = $this->render(pp_get_composition($post_id)[0]['props']);
        $this->assertStringContainsString('&lt;a href=&quot;/y&quot;&gt;x&lt;/a&gt;</a>', $html, 'never a nested link');
    }

    public static function refusedHosts(): array
    {
        return [
            '`a` as an inline host (a link inside a link)' => ['<a href="/" data-pp-island="x" data-pp-island-kind="inline"></a>'],
            '`a` as a rich host'                            => ['<a href="/" data-pp-island="x" data-pp-island-kind="rich"></a>'],
            '`span` as a rich host'                         => ['<span data-pp-island="x" data-pp-island-kind="rich"></span>'],
            '`p` as a rich host'                            => ['<p data-pp-island="x" data-pp-island-kind="rich"></p>'],
            'a void element'                                => ['<img src="/a.png" alt="" data-pp-island="x">'],
            'a table part other than a cell'                => ['<table><tbody><tr data-pp-island="x" data-pp-island-kind="rich"><td>a</td></tr></tbody></table>'],
            'an SVG element'                                => ['<svg viewBox="0 0 1 1"><g data-pp-island="x"></g></svg>'],
            'an HTML host inside MathML'                    => ['<math><mtext><span data-pp-island="x"></span></mtext></math>'],
            'an unlisted element'                           => ['<nav data-pp-island="x"></nav>'],
        ];
    }

    /** @dataProvider refusedHosts */
    public function testAWrongHostIsRefusedNamingTheIslandRule(string $markup): void
    {
        $result = $this->write($this->page(), [$this->band($markup)]);
        $this->assertRefused($result, 'refused by §7.2', 'prop "markup"');
    }

    public function testTheNameGateUniquenessKindAndCount(): void
    {
        $cases = [
            'an uppercase name'      => '<p data-pp-island="Title"></p>',
            'a leading digit'        => '<p data-pp-island="1a"></p>',
            'a space'                => '<p data-pp-island="a b"></p>',
            'a 65-character name'    => '<p data-pp-island="a' . str_repeat('b', 64) . '"></p>',
            'a duplicate name'       => '<p data-pp-island="a"></p><p data-pp-island="a"></p>',
            'an unknown kind'        => '<p data-pp-island="a" data-pp-island-kind="html"></p>',
            'a kind with no island'  => '<p data-pp-island-kind="inline"></p>',
            'the 65th island'        => str_repeat('<p data-pp-island="i%d"></p>', 1),
        ];
        $cases['the 65th island'] = implode('', array_map(static fn ($n) => '<p data-pp-island="i' . $n . '"></p>', range(1, 65)));
        foreach ($cases as $label => $markup) {
            $result = $this->write($this->page(), [$this->band($markup)]);
            $this->assertFalse($result['ok'], "{$label} must be refused");
            $this->assertStringContainsString('§7.2', $result['error'], $label);
        }
        // The positive controls: the longest name and exactly 64 islands.
        $ok = $this->write($this->page(), [$this->band('<p data-pp-island="a' . str_repeat('b', 63) . '"></p>')]);
        $this->assertTrue($ok['ok'], 'a 64-character name is admitted');
        $ok = $this->write($this->page(), [$this->band(implode('', array_map(static fn ($n) => '<p data-pp-island="i' . $n . '"></p>', range(1, 64))))]);
        $this->assertTrue($ok['ok'], 'exactly 64 islands are admitted');
    }

    public function testAnIslandElementMustBeEmptyInMarkup(): void
    {
        foreach (['text' => 'x', 'whitespace' => ' ', 'a comment' => '<!-- c -->', 'an element' => '<em>x</em>'] as $label => $inside) {
            $result = $this->write($this->page(), [$this->band('<p data-pp-island="a">' . $inside . '</p>')]);
            $this->assertRefused($result, 'must be empty in markup', 'prop "markup"');
        }
    }

    // ── §3 band-level rules ─────────────────────────────────────────────────

    public function testAnIslandEntryWithNoHostIsRefusedNamedCustomIslandUnknown(): void
    {
        $result = $this->write($this->page(), [$this->band('<p data-pp-island="a"></p>', ['a' => 'A', 'b' => 'B'])]);
        $this->assertRefused($result, 'custom_island_unknown', 'islands "b"');
    }

    public function testANonStringIslandIsRefused(): void
    {
        $result = $this->write($this->page(), [$this->band('<p data-pp-island="a"></p>', ['a' => ['x']])]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('islands "a"', $result['error']);
    }

    public function testTheIslandAttributesAreRefusedOutsideCustomMarkupAndInsideIslandContent(): void
    {
        $section = pp_execute_action('update_composition', ['post_id' => $this->page(), 'composition' => [
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p data-pp-island="a"></p>']],
        ]]);
        $this->assertRefused($section, 'refused by E6');

        $inside = $this->write($this->page(), [$this->band(
            '<div data-pp-island="a" data-pp-island-kind="rich"></div>', ['a' => '<p data-pp-island="b"></p>']
        )]);
        $this->assertRefused($inside, 'refused by E6', 'islands "a"');
    }

    public function testIslandContentIsJudgedInTheSinkItsKindDeclares(): void
    {
        // inline: a <div> is outside the INLINE set.
        $inline = $this->write($this->page(), [$this->band('<p data-pp-island="a" data-pp-island-kind="inline"></p>', ['a' => '<div>x</div>'])]);
        $this->assertRefused($inline, 'refused by P-2', 'islands "a"');
        // rich: an event handler is E1 whatever the kind.
        $rich = $this->write($this->page(), [$this->band('<div data-pp-island="a" data-pp-island-kind="rich"></div>', ['a' => '<p onclick="x()">y</p>'])]);
        $this->assertRefused($rich, 'refused by E1', 'islands "a"');
        // plain: never parsed, so never a Loss.
        $plain = $this->write($this->page(), [$this->band('<p data-pp-island="a"></p>', ['a' => '<script>x</script>'])]);
        $this->assertTrue($plain['ok'], 'plain content is escaped text');
    }

    public function testAnIdReferenceResolvesAcrossMarkupAndIslandsOfTheSameBand(): void
    {
        $ok = $this->write($this->page(), [$this->band(
            '<p id="note">n</p><div data-pp-island="a" data-pp-island-kind="rich"></div>',
            ['a' => '<button type="button" aria-describedby="note">b</button>']
        )]);
        $this->assertTrue($ok['ok'], 'one band: the reference resolves in band (E12)');

        $out = $this->write($this->page(), [
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p id="elsewhere">n</p>']],
            $this->band('<div data-pp-island="a" data-pp-island-kind="rich"></div>', ['a' => '<button type="button" aria-describedby="elsewhere">b</button>']),
        ]);
        // The two bands are both new: the refusal names the E12 clause (whichever side of
        // the cross-band pair the gate reports first).
        $this->assertRefused($out, 'refused by E12');
    }

    // ── §4 routed item 10: the full ancestor chain ──────────────────────────

    /** Each passes the island's OWN sink check, and restructures the markup in context. */
    public static function restructuringIslands(): array
    {
        return [
            'a link under an authored link'    => ['<a href="/x"><span data-pp-island="q" data-pp-island-kind="inline"></span> after</a><p>n</p>', '<a href="/y">in</a>'],
            'a button under an authored button' => ['<button type="button"><div data-pp-island="q" data-pp-island-kind="rich"></div></button><p>n</p>', '<button type="button">in</button>'],
            'a list item in a list-item host'  => ['<ul><li data-pp-island="q" data-pp-island-kind="rich"></li><li>two</li></ul>', '<li>x</li>'],
            'a cell in a cell host'            => ['<table><tbody><tr><td data-pp-island="q" data-pp-island-kind="rich"></td><td>2</td></tr></tbody></table>', '<table><tbody><tr><td>x</td></tr></tbody></table><td>y</td>'],
        ];
    }

    /** @dataProvider restructuringIslands */
    public function testIslandContentThatRestructuresItsHostsContextIsRefused(string $markup, string $content): void
    {
        $result = $this->write($this->page(), [$this->band($markup, ['q' => $content])]);
        $this->assertFalse($result['ok'], 'refused fail-closed');
        $this->assertSame('content_construct_excluded', $result['error_code'], (string) $result['error']);
        $this->assertStringContainsString('islands "q"', $result['error']);
    }

    public function testTheNestedLinkCaseIsRefusedByTheContextCheckNotByTheIslandsOwnSink(): void
    {
        // The island alone, in its own sink, is clean: only its host's ancestors make it fail.
        $this->assertSame([], pp_content_sanitize('<a href="/y">in</a>', 'inline')['losses']);
        $result = $this->write($this->page(), [$this->band(
            '<a href="/x"><span data-pp-island="q" data-pp-island-kind="inline"></span> after</a>', ['q' => '<a href="/y">in</a>']
        )]);
        $this->assertRefused($result, 'routed item 10', 'islands "q"');
    }

    public function testOnlyTheRestructuringIslandIsNamedNotTheSiblingItDisturbs(): void
    {
        // The bad island closes the authored link, which moves the sibling after it too: the
        // sibling is re-checked alone and passes, so only the culprit is named.
        $result = $this->write($this->page(), [$this->band(
            '<a href="/x"><span data-pp-island="q" data-pp-island-kind="inline"></span><span data-pp-island="ok"></span></a>',
            ['q' => '<a href="/y">in</a>', 'ok' => 'Fine']
        )]);
        $this->assertRefused($result, 'routed item 10', 'islands "q"');
        $this->assertStringNotContainsString('islands "ok"', $result['error']);
    }

    public function testAnIslandJudgedOnlyAtCompositionReportsItsLosses(): void
    {
        // When the per-value pass did not judge an island in its host's sink (no finished
        // result supplied, or the lexical routing disagreed with the parsed host), the band
        // check judges it there, and its losses are reported, never dropped.
        $item = $this->band('<div data-pp-island="a" data-pp-island-kind="rich"></div>', ['a' => '<p onclick="x()">y</p>']);
        $out = pp_content_custom_compose($item, [], [], false);
        $clauses = array_map(static fn ($l) => $l['loss']['clause'], $out['losses']);
        $this->assertContains('E1', $clauses);
        $this->assertSame('islands "a"', $out['losses'][0]['prop']);
    }

    public function testTheComposedBandMustCarryExactlyTheMarkupsHosts(): void
    {
        // Island content cannot carry an island attribute (E6 in its own sink), so this rule
        // is reached only by html the predicate did not make. Injected here as a finished
        // result, the composed pass sees a host the markup never declared and refuses.
        $item = $this->band('<div data-pp-island="a" data-pp-island-kind="rich"></div>', ['a' => 'x']);
        $forged = ['html' => '<p data-pp-island="extra"></p>', 'losses' => [], 'notes' => [], 'ids' => []];
        $out = pp_content_custom_compose($item, ['islands "a"' => $forged], [], false);
        $messages = array_map(static fn ($l) => $l['loss']['message'], $out['losses']);
        $this->assertNotSame([], $messages);
        $this->assertStringContainsString('exactly the island hosts its markup declares', implode("\n", $messages));
        // And the render path falls back to the markup alone.
        $this->assertStringNotContainsString('extra', pp_content_custom_compose($item, ['islands "a"' => $forged], [], true)['html']);
    }

    public function testTheSameContentInAHostWithoutThatAncestorIsAdmitted(): void
    {
        // Positive controls for every refusal above: the content is fine where its host's
        // ancestors leave it be.
        $cases = [
            ['<p><span data-pp-island="q" data-pp-island-kind="inline"></span> after</p>', '<a href="/y">in</a>'],
            ['<div><div data-pp-island="q" data-pp-island-kind="rich"></div></div>', '<button type="button">in</button>'],
            ['<div data-pp-island="q" data-pp-island-kind="rich"></div>', '<ul><li>x</li></ul>'],
            ['<table><tbody><tr><td data-pp-island="q" data-pp-island-kind="rich"></td></tr></tbody></table>', '<p>x</p>'],
        ];
        foreach ($cases as [$markup, $content]) {
            $result = $this->write($this->page(), [$this->band($markup, ['q' => $content])]);
            $this->assertTrue($result['ok'], $markup . ' / ' . $content . ': ' . ($result['error'] ?? ''));
        }
    }

    public function testAStrayCloserInIslandContentIsRefusedByItsOwnSink(): void
    {
        // `x</b>` aimed at an authored <b> ancestor: the island's own check already refuses a
        // stray closer (P-16), so it never reaches the context check.
        $result = $this->write($this->page(), [$this->band(
            '<p><b><span data-pp-island="q" data-pp-island-kind="inline"></span> bold</b></p>', ['q' => 'x</b>']
        )]);
        $this->assertRefused($result, 'refused by P-16', 'islands "q"');
    }

    // ── §5 M-8 bounds ────────────────────────────────────────────────────────

    public function testTheIslandMarkupAndComposedBandBounds(): void
    {
        $island = $this->write($this->page(), [$this->band('<p data-pp-island="a"></p>', ['a' => str_repeat('x', PP_CONTENT_ISLAND_MAX_BYTES + 1)])]);
        $this->assertFalse($island['ok']);
        $this->assertSame('content_too_large', $island['error_code']);
        $this->assertStringContainsString('an island may be at most', $island['error']);

        $at_cap = $this->write($this->page(), [$this->band('<p data-pp-island="a"></p>', ['a' => str_repeat('x', PP_CONTENT_ISLAND_MAX_BYTES)])]);
        $this->assertTrue($at_cap['ok'], 'exactly the island cap is admitted');

        $markup = $this->write($this->page(), [$this->band('<p>' . str_repeat('x', PP_CONTENT_PROP_MAX_BYTES) . '</p>')]);
        $this->assertSame('content_too_large', $markup['error_code']);

        $names = range(1, 9);
        $big = implode('', array_map(static fn ($n) => '<p data-pp-island="i' . $n . '"></p>', $names));
        $islands = array_combine(array_map(static fn ($n) => 'i' . $n, $names), array_fill(0, 9, str_repeat('y', 15000)));
        $composed = $this->write($this->page(), [$this->band($big, $islands)]);
        $this->assertSame('content_too_large', $composed['error_code'], (string) $composed['error']);
        $this->assertStringContainsString('a custom band may carry at most', $composed['error']);
    }

    // ── §6 the editing surfaces ─────────────────────────────────────────────

    private function seeded(): int
    {
        $post_id = $this->page();
        $this->assertTrue($this->write($post_id, [$this->band(self::MARKUP, self::ISLANDS)])['ok']);
        return $post_id;
    }

    public function testAnIslandEditMergesByKeyAndKeepsEverySiblingByteIdentical(): void
    {
        $post_id = $this->seeded();
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['islands' => ['cta' => 'Start now']],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $islands = pp_get_composition($post_id)[0]['props']['islands'];
        $this->assertSame('Start now', $islands['cta']);
        $this->assertSame(self::ISLANDS['title'], $islands['title'], 'the planted sibling survives byte-identical');
        $this->assertSame(self::ISLANDS['body'], $islands['body']);
        $this->assertSame(self::MARKUP, pp_get_composition($post_id)[0]['props']['markup']);

        // ONE FIELD in the diff: props.islands.cta, never the whole map.
        $this->assertSame([[
            'path' => 'composition[0].props.islands.cta', 'from' => self::ISLANDS['cta'], 'to' => 'Start now',
        ]], $result['changes']);
    }

    public function testNullRemovesExactlyOneIsland(): void
    {
        $post_id = $this->seeded();
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['islands' => ['body' => null]],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['title' => self::ISLANDS['title'], 'cta' => self::ISLANDS['cta']], pp_get_composition($post_id)[0]['props']['islands']);
    }

    public function testAnIslandWriteIsOneCasGuardedVersionWithItsOwnUndo(): void
    {
        $post_id = $this->seeded();
        $version = pp_get_composition_marker($post_id)['version'];

        $stale = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['islands' => ['cta' => 'Stale']],
            'expected_version' => $version - 1,
        ]);
        $this->assertFalse($stale['ok']);
        $this->assertSame('composition_conflict', $stale['error_code']);
        $this->assertSame(self::ISLANDS['cta'], pp_get_composition($post_id)[0]['props']['islands']['cta']);

        $fresh = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['islands' => ['cta' => 'Fresh']],
            'expected_version' => $version,
        ]);
        $this->assertTrue($fresh['ok']);
        $this->assertSame($version + 1, pp_get_composition_marker($post_id)['version']);

        // Undo: the history ring holds the state this island write replaced.
        $history = pp_get_composition_history($post_id);
        $this->assertSame(self::ISLANDS['cta'], $history[0]['composition'][0]['props']['islands']['cta']);
        $undo = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($undo['ok'], (string) ($undo['error'] ?? ''));
        $this->assertSame(self::ISLANDS, pp_get_composition($post_id)[0]['props']['islands']);
    }

    public function testOperatePatchEditsOneIslandAndRefusesMarkupByNameP7(): void
    {
        $post_id = $this->seeded();
        $before = pp_get_composition($post_id)[0]['props']['markup'];

        $refused = pp_patch_composition($post_id, 'custom.markup', '<p>replaced</p>');
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('field_not_editable', $refused->get_error_code());
        $this->assertStringContainsString('structural', $refused->get_error_message());
        $this->assertStringContainsString('P-7', $refused->get_error_message());
        $this->assertSame($before, pp_get_composition($post_id)[0]['props']['markup'], 'markup unchanged byte for byte');

        // The positive control on the same band.
        $ok = pp_patch_composition($post_id, 'custom.islands.title', 'New <em>title</em>');
        $this->assertTrue(is_array($ok) && $ok['ok'], is_wp_error($ok) ? $ok->get_error_message() : (string) ($ok['error'] ?? ''));
        $islands = pp_get_composition($post_id)[0]['props']['islands'];
        $this->assertSame('New <em>title</em>', $islands['title']);
        $this->assertSame(self::ISLANDS['body'], $islands['body'], 'siblings kept (merge by key)');

        // The island is judged in its sink through the patch too.
        $bad = pp_patch_composition($post_id, 'custom.islands.title', '<div>x</div>');
        $this->assertFalse(is_array($bad) && $bad['ok']);

        $selector = pp_parse_composition_selector('custom.islands.Bad');
        $this->assertInstanceOf(WP_Error::class, $selector);
        $this->assertSame('invalid_selector', $selector->get_error_code());
    }

    public function testOperatePatchByBandIdAndInspectListsEachIsland(): void
    {
        $post_id = $this->page();
        $this->assertTrue($this->write($post_id, [$this->band('<p data-pp-island="a"></p><p data-pp-island="b"></p>', ['a' => 'A'], ['id' => 'intro'])])['ok']);
        $ok = pp_patch_composition($post_id, 'custom[id="intro"].islands.b', 'B');
        $this->assertTrue(is_array($ok) && $ok['ok']);
        $this->assertSame(['a' => 'A', 'b' => 'B'], pp_get_composition($post_id)[0]['props']['islands']);

        $fields = array_column(pp_get_component_fields('custom'), 'name');
        $this->assertNotContains('markup', $fields, 'P-7: markup is never a patched field');
        $this->assertContains('islands.<name>', $fields);

        // inspect-composition lists one field per island (stored or named in markup), each
        // with a selector `operate patch` accepts.
        $inspected = pp_inspect_composition($post_id);
        $this->assertIsArray($inspected);
        $band = $inspected['components'][0] ?? $inspected[0] ?? null;
        $this->assertIsArray($band, 'inspect returns the band');
        $listed = array_column($band['fields'], 'current_value', 'selector');
        $this->assertSame(['custom.id' => 'intro', 'custom[id="intro"].islands.a' => 'A', 'custom[id="intro"].islands.b' => 'B'], $listed);
        $this->assertArrayNotHasKey('custom.markup', $listed, 'P-7: inspect offers no markup selector');
    }

    // ── §7 the render path ──────────────────────────────────────────────────

    public function testStoredIslandContentThatRestructuresRendersEmptyFailClosed(): void
    {
        // Raw meta: bytes that went around the gate.
        $props = ['markup' => '<a href="/x"><span data-pp-island="q" data-pp-island-kind="inline"></span> after</a><p data-pp-island="ok"></p>',
            'islands' => ['q' => '<a href="/evil">in</a>', 'ok' => 'Fine']];
        $html = $this->render($props);
        $this->assertStringNotContainsString('/evil', $html, 'the failing island renders empty');
        $this->assertMatchesRegularExpression('#<a href="/x"><span\s*></span> after</a>#', $html, 'the markup keeps its structure');
        $this->assertStringContainsString('Fine', $html, 'a verified sibling still renders');
    }

    public function testCustomMarkupGetsNoShortcodeExpansion(): void
    {
        // The test bootstrap's do_shortcode() is the identity, so the render alone cannot see an
        // expansion; the source pin below is the contract, and the render shows the bracket
        // text reaches the page as text.
        $html = $this->render(['markup' => '<p>[caption id="main"]hello[/caption]</p>']);
        $this->assertStringContainsString('hello[/caption]</p>', $html);
        $source = (string) file_get_contents(dirname(__DIR__) . '/components/custom/custom.php');
        $this->assertStringNotContainsString('do_shortcode(', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source),
            'the custom template never calls do_shortcode: author markup is not plugin output (§7.5)');
    }

    public function testTheEmissionBeltStripsEngineIdentityFromEmittedBytes(): void
    {
        $forged = '<div data-pp-band="pp-1a2b3c4d" data-pp-component="hero" data-pp-item="it-1a2b3c4d" '
            . 'data-pp-chrome="nav" data-pp-band-overlay DATA-PP-X="1" class="keep"><p id="pp-0a0b0c0d">a</p>'
            . '<p id="it-00000000">b</p><p id="main">c</p><p id="pp-nav-menu">d</p><p id="mine">e</p></div>';
        $belted = pp_content_emission_belt($forged);
        $this->assertDoesNotMatchRegularExpression('/data-pp-/i', $belted);
        foreach (['pp-0a0b0c0d', 'it-00000000', '"main"', 'pp-nav-menu'] as $id) {
            $this->assertStringNotContainsString($id, $belted);
        }
        $this->assertStringContainsString('class="keep"', $belted);
        $this->assertStringContainsString('id="mine"', $belted, 'an ordinary author id is kept');
    }

    public function testAForgedEngineMarkerStoredAroundTheGateNeverReachesThePage(): void
    {
        // Raw meta carrying every §1.4 marker; the render emits only the template's own root.
        $html = $this->render([
            'markup' => '<div data-pp-band="pp-1a2b3c4d" data-pp-item="it-1a2b3c4d" data-pp-chrome="nav" data-pp-component="hero">x</div>'
                . '<p id="pp-0a0b0c0d">y</p>',
            '__pp_udc_band' => 'pp-99999999',
        ]);
        $inner = preg_replace('#^\s*<section[^>]*>#', '', $html);
        $this->assertDoesNotMatchRegularExpression('/data-pp-/', $inner);
        $this->assertStringNotContainsString('pp-0a0b0c0d', $inner);
        $this->assertSame(1, substr_count($html, 'data-pp-band='), 'the only band marker is the template\'s');
    }

    public function testTheBeltHoldsWhenThePredicateIsBypassed(): void
    {
        // What the belt guards: html the predicate did not produce. Spliced composed bytes
        // pass through it unchanged except for engine identity.
        $this->assertSame('<p >x</p>', pp_content_emission_belt('<p data-pp-island="a">x</p>'));
        $this->assertSame('<p class="a">x</p>', pp_content_emission_belt('<p class="a">x</p>'));
        $this->assertSame('', pp_content_emission_belt(''));
    }

    // ── §8 findings ─────────────────────────────────────────────────────────

    public function testEveryCustomBandCarriesTheUnverifiedDisclosureAsInformation(): void
    {
        $post_id = $this->seeded();
        $findings = _pp_composition_findings(pp_get_composition($post_id), $post_id);
        $unverified = array_values(array_filter($findings, static fn ($f) => $f['type'] === 'custom_band_unverified'));
        $this->assertCount(1, $unverified);
        $this->assertSame('info', $unverified[0]['severity']);
        $this->assertSame(0, $unverified[0]['index']);
        $this->assertStringContainsString('checked for safety', $unverified[0]['message']);
        $this->assertStringContainsString('_band', $unverified[0]['message']);

        $plain = _pp_composition_findings([['component' => 'hero', 'props' => ['title' => 'H']]], null);
        $this->assertSame([], array_filter($plain, static fn ($f) => $f['type'] === 'custom_band_unverified'), 'only custom bands carry it');
    }

    public function testAnIslandWithNoContentRendersEmptyWithAFinding(): void
    {
        $post_id = $this->page();
        $ok = $this->write($post_id, [$this->band('<h2 data-pp-island="title"></h2><p data-pp-island="lede"></p>', ['title' => 'T'])]);
        $this->assertTrue($ok['ok'], 'a missing island is not a refusal');
        $findings = array_values(array_filter(_pp_composition_findings(pp_get_composition($post_id), $post_id),
            static fn ($f) => $f['type'] === 'custom_island_empty'));
        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertStringContainsString('"lede"', $findings[0]['message']);
        $this->assertStringNotContainsString('"title"', $findings[0]['message']);
        $this->assertMatchesRegularExpression('#<p\s*></p>#', $this->render(pp_get_composition($post_id)[0]['props']));
    }

    public function testACustomElementInMarkupCarriesThePluginBoundaryDisclosure(): void
    {
        $post_id = $this->page();
        $this->assertTrue($this->write($post_id, [$this->band('<my-widget>x</my-widget>')])['ok']);
        $types = array_column(_pp_composition_findings(pp_get_composition($post_id), $post_id), 'type');
        $this->assertContains('content_plugin_output', $types);
    }

    // ── §9 T-17: cost of a maximal custom band ──────────────────────────────

    public function testAMaximalCustomBandWritesAndRendersWithinBudget(): void
    {
        // 64 rich islands filling the 128 KiB composed cap with the densest admitted shape.
        $markup = implode('', array_map(static fn ($n) => '<div data-pp-island="i' . $n . '" data-pp-island-kind="rich"></div>', range(1, 64)));
        $each = intdiv(PP_CONTENT_CUSTOM_MAX_BYTES - strlen($markup), 64);
        $content = str_repeat('<b>x</b>', intdiv($each, 8));
        $islands = [];
        foreach (range(1, 64) as $n) {
            $islands['i' . $n] = $content;
        }
        $post_id = $this->page();
        $t = microtime(true);
        $ok = $this->write($post_id, [$this->band($markup, $islands)]);
        $write = microtime(true) - $t;
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $t = microtime(true);
        $html = $this->render(pp_get_composition($post_id)[0]['props']);
        $render = microtime(true) - $t;
        $this->assertSame(64 * intdiv($each, 8), substr_count($html, '<b>x</b>'), 'every island rendered');
        // Measured on the T-17 rig (PHP 8.3, WordPress 7.0's HTML API, 2026-10-05, best of
        // two): write 2.2 s, render 2.4 s for this worst shape (64 rich islands of `<b>x</b>`
        // at the 128 KiB composed cap). The render path has no cache yet; the first lever is
        // the render cache T-17 names (#1089). The bound is the write path's ~10 s target.
        $this->assertLessThan(10.0, $write, sprintf('write took %.2f s', $write));
        $this->assertLessThan(10.0, $render, sprintf('render took %.2f s', $render));
    }
}
