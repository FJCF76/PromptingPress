<?php
/**
 * tests/UdcMarkerGroupTest.php
 *
 * #1028 — the `marker` group: a v2-native route for a pseudo-element glyph's colour.
 *
 * THE GAP. #1023 and #1101 retired the four v1 slots that coloured a glyph drawn with
 * `content` on a `::before`/`::after`: `--section-separator-color`,
 * `--section-body-marker-color`, `--section-panel-marker-color` and
 * `--grid-item-bullet-color`. Ruling A3 defers pseudo-elements, so no role can address
 * the glyph, and the "set `--pp-list-marker-color`" route the migration notes named is
 * refused as "not a registered design token". A separator a DIFFERENT colour from its
 * sibling text (production authors it #FF5C2E on a cream row) had no v2 expression.
 *
 * THE ROUTE (plan review R3, forced by #1028's own analysis). Not a site token: a
 * `:root` declaration would collapse the separator's `currentColor` fallback into the
 * markers' `--color-accent` chain. A GROUP PARAM instead: `marker.color` emits the
 * custom property `--pp-list-marker-color` on the ROLE'S OWN BOX, the three glyph rules
 * (seven selectors) already read `var(--pp-list-marker-color, <fallback>)`, and a custom property inherits
 * into the pseudo-element. The glyph is still never addressed; A3 stays deferred.
 *
 * WHAT THIS FILE PROVES:
 *   1. The registry entry: one param, a registry-owned property name, the colour grammar.
 *   2. AUTHORED ONLY: no role declares a marker default anywhere, so an unauthored band
 *      renders byte-identically and both fallback chains survive.
 *   3. EXPOSURE IS DERIVED, both directions (plan review R4): every stylesheet rule that
 *      reads the variable, in every enqueued sheet, has its role declaring `marker`, and
 *      every role declaring it has a consumer. Then, in rendered markup, each role's own
 *      schema selector must be the glyph host or one of its ancestors: a role whose box
 *      does not contain the glyph could set the variable and paint nothing.
 *   4. The real write surface accepts it and refuses what it must (14.1).
 *   5. Emission: band, state, breakpoint and ITEM grain (a single grid card).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UdcMarkerGroupTest extends TestCase
{
    /**
     * Each stylesheet consumer, keyed by the leading class of its rule (a `--modifier`
     * suffix stripped), and the role that must carry `marker` for it.
     */
    private const CONSUMERS = [
        'section__inline-items' => ['section', 'inline-items'],
        'section__content'      => ['section', 'body'],
        'pp-marker-list'        => ['section', 'panel-list'],
        'grid__item-bullet'     => ['grid', 'card-bullets'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── 1. The registry entry ───────────────────────────────────────────────

    public function testTheMarkerGroupHasOneColourParamEmittingTheSharedVariable(): void
    {
        $groups = pp_udc_groups();
        $this->assertArrayHasKey('marker', $groups);
        $this->assertSame(['color'], array_keys($groups['marker']['params']));
        $param = $groups['marker']['params']['color'];
        $this->assertSame('--pp-list-marker-color', $param['property']);
        $this->assertSame('color', $param['type']);
        $this->assertArrayNotHasKey('single_valued', $param, 'a marker colour may vary by state and width');
    }

    // ── 2. Authored only ────────────────────────────────────────────────────

    /**
     * Every component that carries roles: the composable roster AND site chrome (nav,
     * footer). Chrome roles take the same groups, so a chrome role declaring `marker`
     * or a marker default must fail here too.
     *
     * @return list<string>
     */
    private static function allComponents(): array
    {
        $names = array_merge(array_map('strval', array_keys(pp_composable_components())), pp_udc_chrome_names());
        return array_values(array_unique($names));
    }


    public function testNoRoleDeclaresAMarkerDefault(): void
    {
        $checked = 0;
        foreach (self::allComponents() as $component) {
            foreach (pp_udc_component_roles((string) $component) as $role => $def) {
                $checked++;
                foreach (['defaults', 'overlay_defaults'] as $tier) {
                    $this->assertArrayNotHasKey('marker', (array) ($def[$tier] ?? []), sprintf(
                        '%s role "%s" declares a marker %s. A default would emit the variable on every '
                        . 'unauthored band and replace BOTH fallbacks (currentColor for the separator, the '
                        . 'accent for the markers) with one value.',
                        $component, $role, $tier
                    ));
                }
            }
        }
        $this->assertGreaterThan(100, $checked, 'the walk must cover the real role roster');
        foreach (self::allComponents() as $component) {
            $this->assertStringNotContainsString(
                '--pp-list-marker-color',
                pp_udc_component_defaults_css((string) $component),
                "{$component}'s defaults tier must not set the marker variable"
            );
        }
        $this->assertContains('nav', self::allComponents(), 'the walk reaches site chrome');
        $this->assertStringNotContainsString('--pp-list-marker-color', pp_udc_chrome_defaults_css());
    }

    // ── 3. Exposure, derived both directions ────────────────────────────────

    /**
     * Every consumer selector of the variable, across EVERY stylesheet the theme enqueues.
     *
     * @return array{classes: array<string, int>, classless: list<string>}
     */
    private static function stylesheetConsumers(): array
    {
        $out = ['classes' => [], 'classless' => []];
        foreach (['base.css', 'components.css', 'utilities.css'] as $sheet) {
            $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/' . $sheet);
            $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (strpos($rule[2], 'var(--pp-list-marker-color') === false) {
                    continue;
                }
                foreach (explode(',', $rule[1]) as $selector) {
                    if (!preg_match('~\.([a-z0-9_-]+)~', trim($selector), $m)) {
                        $out['classless'][] = $sheet . ': ' . trim($selector);
                        continue;
                    }
                    $class = (string) preg_replace('~--.*\z~', '', $m[1]);
                    $out['classes'][$class] = ($out['classes'][$class] ?? 0) + 1;
                }
            }
        }
        ksort($out['classes']);
        return $out;
    }

    public function testEveryStylesheetConsumerHasItsRoleAndNothingElseDoes(): void
    {
        $consumers = self::stylesheetConsumers();
        $this->assertSame([], $consumers['classless'], 'a consumer led by no class cannot be mapped to a role: give it one');
        // Pinned exactly: the two separator rules (::before, and ::after on the centred row)
        // plus the five-selector marker block. A new selector has to be looked at here.
        $this->assertSame(7, array_sum($consumers['classes']), 'the glyph rules carry 7 selectors');
        $expected = array_keys(self::CONSUMERS);
        sort($expected);
        $this->assertSame($expected, array_keys($consumers['classes']), 'a new consumer of --pp-list-marker-color needs a role, and a role needs a consumer');

        $declaring = [];
        foreach (self::allComponents() as $component) {
            foreach (pp_udc_component_roles((string) $component) as $role => $def) {
                if (in_array('marker', (array) ($def['groups'] ?? []), true)) {
                    $declaring[] = [(string) $component, (string) $role];
                }
            }
        }
        $roles = array_values(self::CONSUMERS);
        sort($roles);
        sort($declaring);
        $this->assertSame($roles, $declaring, 'exactly the consumers\' roles declare the marker group');
    }

    private static function render(string $component, array $props): \DOMXPath
    {
        ob_start();
        pp_get_component($component, $props);
        $html = (string) ob_get_clean();
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>');
        libxml_clear_errors();
        return new \DOMXPath($doc);
    }

    private static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /**
     * The glyph's host element for each consumer, found from the CONSUMER'S class alone
     * (independent of the role), so the role's schema selector can be checked against it.
     */
    private const HOSTS = [
        'section__inline-items' => "//*[%s]//li",
        'section__content'      => "//*[%s]/ul/li",
        'pp-marker-list'        => "//*[%s]/li",
        'grid__item-bullet'     => "//li[%s]",
    ];

    private const HOST_CLASS = [
        'section__inline-items' => 'section__inline-items',
        'section__content'      => 'section__content--marker-check',
        'pp-marker-list'        => 'pp-marker-list',
        'grid__item-bullet'     => 'grid__item-bullet',
    ];

    public function testEachRolesSelectorHoldsItsGlyphInTheRenderedMarkup(): void
    {
        $renders = [
            'section' => self::render('section', [
                'title' => 'T', 'layout' => 'text-panel', 'body' => '<ul><li>a</li></ul>',
                'body_marker' => 'check', 'panel_items' => ['one', 'two'], 'panel_items_marker' => 'dash',
                'body_items' => ['A', 'B'],
            ]),
            'grid' => self::render('grid', ['items' => [['title' => 'Card', 'text' => 'x', 'bullets' => ['one', 'two']]]]),
        ];
        $hostsChecked = 0;
        foreach (self::CONSUMERS as $consumer => [$component, $role]) {
            $selector = (string) (pp_udc_component_roles($component)[$role]['selector'] ?? '');
            $this->assertMatchesRegularExpression('~^\.[a-z0-9_-]+\z~', $selector, "{$component}.{$role}: a single-class selector the check can read");
            $roleClass = substr($selector, 1);
            $hosts = $renders[$component]->query(sprintf(self::HOSTS[$consumer], self::hasClass(self::HOST_CLASS[$consumer])));
            $this->assertGreaterThan(0, $hosts->length, "{$consumer}: the fixture renders the glyph's host");
            foreach ($hosts as $host) {
                $held = $renders[$component]->query('ancestor-or-self::*[' . self::hasClass($roleClass) . ']', $host)->length > 0;
                $this->assertTrue($held, "{$component}.{$role} ({$selector}) must be the glyph host or its ancestor, or the variable set there cannot reach the ::before");
                $hostsChecked++;
            }
        }
        $this->assertGreaterThanOrEqual(7, $hostsChecked, 'every host in the fixtures was checked');
    }

    // ── 4. The write surface (14.1) ─────────────────────────────────────────

    private function page(array $composition): int
    {
        $id = pp_create_page('marker', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $composition]);
        $this->assertTrue($result['ok'], 'premise: ' . ($result['error'] ?? ''));
        return $id;
    }

    private function sectionPage(): int
    {
        return $this->page([['component' => 'section', 'props' => ['title' => 'Row', 'body_items' => ['A', 'B', 'C']]]]);
    }

    public function testUpdateComponentAcceptsAMarkerColourOnTheRowAndStoresIt(): void
    {
        $id = $this->sectionPage();
        $result = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0,
            'udc' => ['inline-items' => ['marker' => ['color' => '#FF5C2E']]],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['inline-items' => ['marker' => ['color' => '#FF5C2E']]], pp_get_composition($id)[0]['udc']);
    }

    public function testATokenReferenceAndStateAndWidthMapsAreAccepted(): void
    {
        $id = $this->sectionPage();
        $result = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0,
            'udc' => ['inline-items' => ['marker' => [
                'color' => ['d' => '@color-accent', 'p' => '#FF5C2E'],
                ':hover' => ['color' => '#101828'],
            ]]],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        // A width map that mixes a token reference with a literal stores the literal as a
        // minted band token (the engine's `udc_token_minted` behaviour, not marker-specific),
        // so the stored map is pinned in that form, value included.
        $this->assertSame(
            [
                'inline-items' => ['marker' => [
                    'color' => ['d' => '@color-accent', 'p' => '@inline-items-marker-color-p'],
                    ':hover' => ['color' => '#101828'],
                ]],
                '_tokens' => ['inline-items-marker-color-p' => '#FF5C2E'],
            ],
            pp_get_composition($id)[0]['udc'],
            'the width map and the state map are stored, the literal minted as a band token'
        );
    }

    /** A token reference resolves to the token's own variable, not to a literal. */
    public function testATokenReferenceEmitsTheTokensVariable(): void
    {
        $css = pp_udc_band_css([
            'component' => 'section', 'id' => 'pp-t1',
            'props' => ['title' => 'Row', 'body_items' => ['A', 'B']],
            'udc' => ['inline-items' => ['marker' => ['color' => '@color-accent']]],
        ]);
        $this->assertMatchesRegularExpression(
            '~\[data-pp-band="pp-t1"\] \.section__inline-items\{[^}]*--pp-list-marker-color:var\(--color-accent\)~',
            $css
        );
    }

    public function testRefusals(): void
    {
        $id = $this->sectionPage();
        foreach ([
            'not a colour'          => [['inline-items' => ['marker' => ['color' => '12px']]], 'group "marker" parameter "color": Value must be a valid CSS color'],
            'injection'             => [['inline-items' => ['marker' => ['color' => 'red;x:y']]], 'group "marker" parameter "color": Value must not contain'],
            'a url'                 => [['inline-items' => ['marker' => ['color' => 'url(x)']]], 'group "marker" parameter "color": Value must not name an external resource'],
            'unknown param'         => [['inline-items' => ['marker' => ['glyph' => 'dash']]], 'group "marker" has no parameter glyph'],
            'a role without marker' => [['heading' => ['marker' => ['color' => '#FF5C2E']]], 'does not permit the UDC group "marker"'],
        ] as $label => [$udc, $reason]) {
            $result = pp_execute_action('update_component', ['post_id' => $id, 'component_index' => 0, 'udc' => $udc]);
            $this->assertFalse($result['ok'], "{$label} must be refused");
            $this->assertStringContainsString($reason, (string) ($result['error'] ?? ''), "{$label} must be refused for the right reason");
        }
        $this->assertArrayNotHasKey('udc', pp_get_composition($id)[0], 'a refused write stores nothing');
    }

    // ── 5. Emission ─────────────────────────────────────────────────────────

    public function testTheBandEmitsTheVariableOnTheRoleBoxAtRestHoverAndWidth(): void
    {
        $css = pp_udc_band_css([
            'component' => 'section', 'id' => 'pp-m1',
            'props' => ['title' => 'Row', 'body_items' => ['A', 'B']],
            'udc' => ['inline-items' => ['marker' => [
                'color' => ['d' => '#FF5C2E', 'p' => '#101828'],
                ':hover' => ['color' => '#2233aa'],
            ]]],
        ]);
        $this->assertMatchesRegularExpression('~\[data-pp-band="pp-m1"\] \.section__inline-items\{[^}]*--pp-list-marker-color:#ff5c2e~i', $css);
        $this->assertMatchesRegularExpression('~\[data-pp-band="pp-m1"\] \.section__inline-items:hover\{[^}]*--pp-list-marker-color:#2233aa~i', $css);
        $this->assertMatchesRegularExpression('~@media[^{]*\{[^@]*\.section__inline-items\{[^}]*--pp-list-marker-color:#101828~i', $css);
    }

    public function testASingleCardCarriesItsOwnMarkerColour(): void
    {
        $id = $this->page([['component' => 'grid', 'props' => ['items' => [
            ['title' => 'Dark', 'text' => 'x', 'bullets' => ['a']],
            ['title' => 'Plain', 'text' => 'y', 'bullets' => ['b']],
        ]]]]);
        $items = pp_get_composition($id)[0]['props']['items'];
        $items[0]['udc'] = ['card-bullets' => ['marker' => ['color' => '#E8E2D4']]];
        $result = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0, 'props' => ['items' => $items],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $band = pp_get_composition($id)[0];
        $css  = pp_udc_band_css($band);
        $itemId = (string) $band['props']['items'][0]['id'];
        $this->assertMatchesRegularExpression(
            '~\[data-pp-item="' . $itemId . '"\][^{]*\.grid__item-bullets\{[^}]*--pp-list-marker-color:#E8E2D4~i',
            $css,
            'the item tier scopes the variable, with its value, to that one card'
        );
        // The unstyled card is minted no item id (ids exist only where an item carries
        // styling), so the neighbour is pinned by count: that one rule is the band's only one.
        $this->assertSame(1, substr_count($css, '--pp-list-marker-color'), 'the unstyled card carries no marker rule');

        // Item grain refuses a card role that does not declare the group, and the
        // refused write stores nothing: the card keeps the value written above.
        $refused = $items;
        $refused[1]['udc'] = ['card-title' => ['marker' => ['color' => '#FF5C2E']]];
        $result = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0, 'props' => ['items' => $refused],
        ]);
        $this->assertFalse($result['ok'], 'card-title does not declare marker');
        $this->assertStringContainsString('does not permit the UDC group "marker"', (string) ($result['error'] ?? ''));
        $stored = pp_get_composition($id)[0]['props']['items'];
        $this->assertArrayNotHasKey('udc', $stored[1], 'a refused item write stores nothing');
        $this->assertSame(['card-bullets' => ['marker' => ['color' => '#E8E2D4']]], $stored[0]['udc']);
    }

    /**
     * A site preset may carry the group like any other. On a role that declares it the
     * value emits; on a role that does not, the group is skipped and the write envelope
     * says so rather than silently dropping it.
     */
    public function testAPresetCarriesTheGroupWhereTheRolePermitsItAndDisclosesTheSkip(): void
    {
        $saved = pp_execute_action('save_preset', [
            'name' => 'orange-marks', 'grain' => 'role',
            'udc'  => ['marker' => ['color' => '#FF5C2E'], 'typography' => ['weight' => '700']],
        ]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));

        $id = $this->sectionPage();
        $result = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0,
            'udc' => ['inline-items' => ['_preset' => 'orange-marks'], 'heading' => ['_preset' => 'orange-marks']],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $css = pp_udc_band_css(pp_get_composition($id)[0]);
        $this->assertMatchesRegularExpression('~\.section__inline-items\{[^}]*--pp-list-marker-color:#ff5c2e~i', $css);
        // The heading's selector is read from its schema, so a renamed class cannot turn
        // this into an assertion about a selector that never renders.
        $heading = (string) pp_udc_component_roles('section')['heading']['selector'];
        $this->assertDoesNotMatchRegularExpression(
            '~' . str_replace('.', '\\.', $heading) . '[^{]*\{[^}]*--pp-list-marker-color~',
            $css,
            'the skipped heading emits no marker variable'
        );

        $skipped = array_values(array_filter(
            (array) ($result['findings'] ?? []),
            static fn($f): bool => is_array($f) && ($f['type'] ?? '') === 'udc_preset_groups_skipped'
        ));
        $this->assertCount(1, $skipped, 'only the heading skips a group');
        $this->assertStringContainsString('heading', (string) $skipped[0]['message']);
        $this->assertStringContainsString('marker', (string) $skipped[0]['message']);
    }

    public function testAnUnauthoredBandEmitsNoMarkerVariable(): void
    {
        foreach (['section', 'grid'] as $component) {
            $css = pp_udc_band_css(['component' => $component, 'id' => 'pp-u1', 'props' => [],
                'udc' => ['_band' => ['background' => ['fill' => '#101828']]]]);
            $this->assertStringNotContainsString('--pp-list-marker-color', $css);
        }
    }

    /**
     * The default-homepage seed carries its own original v1 marker values (#FF5C2E on the
     * two trust strips' separators and on the grid band's checks) through the new route.
     */
    public function testTheSeedCarriesItsOriginalMarkerColours(): void
    {
        $hits = [];
        foreach (pp_default_homepage_composition() as $i => $band) {
            foreach (['inline-items', 'card-bullets'] as $role) {
                if (isset($band['udc'][$role]['marker'])) {
                    $hits[] = $band['component'] . '.' . $role;
                    $this->assertSame(['color' => '#FF5C2E'], $band['udc'][$role]['marker']);
                    $css = pp_udc_band_css($band + ['id' => 'pp-seed' . $i]);
                    $this->assertStringContainsString('--pp-list-marker-color:', $css);
                }
            }
        }
        sort($hits);
        $this->assertSame(['grid.card-bullets', 'section.inline-items', 'section.inline-items'], $hits);
    }

    /**
     * The AI-facing group vocabulary names every group the engine has (api-contract finding:
     * "Eight groups" silently survived the ninth).
     */
    public function testTheStyleGuideListsEveryEngineGroup(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__) . '/ai-instructions/style-component.md');
        preg_match_all('/^- \*\*([a-z]+)\*\* — /m', $doc, $m);
        $this->assertGreaterThanOrEqual(9, count($m[1]), 'the guide\'s group list was found');
        $this->assertEqualsCanonicalizing(array_keys(pp_udc_groups()), $m[1]);
        $words = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
        $this->assertStringContainsString(ucfirst($words[count(pp_udc_groups())]) . ' groups.', $doc);
        // Every AI-facing file that COUNTS the groups must count the engine's number.
        $perFile = [];
        foreach (glob(dirname(__DIR__) . '/ai-instructions/*.md') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            // Only the three TOTAL forms these files use, so a subset sentence ("These two
            // groups are ...") is not read as a total: a line or sentence opening "Nine groups.",
            // "The nine groups are", and "The nine are `typography`".
            preg_match_all('/(?:^|\\. )([A-Z][a-z]+) groups\\.|\\bThe ([a-z]+) groups are\\b|\\bThe ([a-z]+) are `typography`/m', $text, $mm);
            $mm[1] = array_values(array_filter(array_merge($mm[1], $mm[2], $mm[3])));
            foreach ($mm[1] as $word) {
                $i = array_search(strtolower($word), $words, true);
                if ($i !== false && $i > 1) {
                    $perFile[basename($file)] = ($perFile[basename($file)] ?? 0) + 1;
                    $this->assertSame(count(pp_udc_groups()), $i, basename($file) . " says \"{$word}\" groups");
                }
            }
        }
        foreach (['style-component.md', 'website-building.md', 'add-component.md'] as $f) {
            $this->assertGreaterThan(0, $perFile[$f] ?? 0, "{$f} states the group count in a form the scan reads");
        }

        // The engine TOTAL stated anywhere else a reader or `wp pp schema` meets it: schema
        // descriptions, component READMEs and docs ("none of the seven UDC groups" was wrong
        // twice over). Only the explicit total forms are read, "none of the <N> UDC groups" and
        // "all <N> UDC groups", in words or digits, so a subset ("this role permits three UDC
        // groups") or a dated sentence ("adds two UDC groups") is not taken for a total. Files
        // that QUOTE an old total as dated evidence are excluded by name: LAYER-2-CONTRACT.md
        // quotes the old sentence, with line numbers, in a ratified contract.
        $root  = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/components/*/schema.json') ?: [],
            glob($root . '/components/*/README.md') ?: [],
            glob($root . '/docs/*.md') ?: [],
            glob($root . '/docs/v2/*.md') ?: []
        );
        $scanned = 0;
        foreach ($files as $file) {
            if (basename($file) === 'LAYER-2-CONTRACT.md') {
                continue;
            }
            $scanned++;
            preg_match_all('/\\b(?:none\\s+of\\s+the|all)\\s+([A-Za-z]+|\\d+)\\s+UDC\\s+groups\\b/i', (string) file_get_contents($file), $mm);
            foreach ($mm[1] as $word) {
                $i = ctype_digit($word) ? (int) $word : array_search(strtolower($word), $words, true);
                if ($i !== false && $i > 1) {
                    $this->assertSame(count(pp_udc_groups()), $i, substr($file, strlen($root) + 1) . " says \"{$word} UDC groups\"");
                }
            }
        }
        $this->assertGreaterThan(20, $scanned, 'the total-count scan reaches the schemas, READMEs and docs');
    }

    /**
     * v1 PARITY, STATED (red team; #1177 tracks a write-time disclosure): a section list on
     * the default `disc` marker is the browser's native ::marker, carries no glyph class, and
     * so ignores `marker.color`, exactly as v1's slots were "ignored while disc". The schema
     * must say so wherever it offers the colour.
     */
    public function testADiscListCarriesNoGlyphClassAndTheSchemaSaysSo(): void
    {
        $x = self::render('section', ['title' => 'T', 'layout' => 'text-panel', 'body' => '<ul><li>a</li></ul>', 'panel_items' => ['one']]);
        $this->assertSame(0, $x->query('//*[' . self::hasClass('pp-marker-list') . ']')->length);
        $this->assertSame(0, $x->query('//*[contains(@class, "section__content--marker-")]')->length);
        $props = json_decode((string) file_get_contents(dirname(__DIR__) . '/components/section/schema.json'), true)['props'];
        foreach (['body_marker', 'panel_items_marker'] as $prop) {
            $this->assertStringContainsString('ignores `marker.color`', $props[$prop]['description'], "{$prop} must state the disc caveat");
        }
        $roles = json_decode((string) file_get_contents(dirname(__DIR__) . '/components/section/schema.json'), true)['roles'];
        foreach (['body', 'panel-list'] as $role) {
            $this->assertMatchesRegularExpression('~`marker\.color`.*`disc`.*ignores it~s', $roles[$role]['description'], "the {$role} role must state the disc caveat");
        }
    }

    /**
     * The in-admin assistant reads neither the schema descriptions nor ai-instructions/: it
     * sees `marker` only as a group name on the catalog's role lines. The caveat must be in
     * its own prompt, or it writes an accepted value on a disc list that paints nothing.
     */
    public function testTheInAdminPromptCarriesTheDiscCaveat(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('marker (color)', $prompt);
        $this->assertMatchesRegularExpression('~MARKER: `marker\.color` paints only a drawn glyph[^\n]*`disc` list ignores it~', $prompt);
    }
}
