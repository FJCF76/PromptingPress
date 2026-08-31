<?php
/**
 * tests/StoredGridItemsMapRenderGuardTest.php
 *
 * #738 — a stored grid `items` JSON OBJECT must never fatal the public page.
 *
 * THE FIFTH SIBLING, in landing order: tests/StoredImageUrlRenderGuardTest.php (#641),
 * tests/StoredBackgroundImageRenderGuardTest.php (#705),
 * tests/StoredTitleRenderGuardTest.php (#706),
 * tests/StoredStyleAndItemsRenderGuardTest.php (#708) and
 * tests/StoredLinkAndRichTextRenderGuardTest.php (#730/#739/#742). It exists for the
 * reason that family exists: the per-component shapes are pinned in ComponentPropsTest, which renders
 * a component directly from a props array. That proves a guard works; it does not prove
 * the bad value can REACH the renderer. This class writes real stored bytes and renders
 * them through the loop templates/composition.php actually runs, so what is asserted is
 * what a visitor's browser receives.
 *
 * THE BOUNDARY IS ARITHMETIC, NOT A TYPED CALL, which is what makes this one different
 * from every earlier sibling. They guard a value on its way into a typed parameter
 * (`string $url`, `array $style`), a builtin (`count()`), or a core escaper
 * (`esc_url()`, `wp_kses_post()`). Here the fatal was the
 * language itself:
 *
 *     components/grid/grid.php   foreach ($items as $index => $item) :
 *                                    $item_number = $item['number'] ?? (string) ($index + 1);
 *
 * `$index` is the array KEY. For a LIST it is an int and `$index + 1` is the 1-based
 * position. For a JSON OBJECT it is a STRING, and PHP 8 raises
 * "Unsupported operand types: string + int". templates/composition.php:16-26 calls
 * pp_get_component() with no try/catch, so that TypeError is a 500 for the whole PUBLIC
 * page — measured on main before the fix, through bytes stored by pp_update_composition().
 *
 * `??` SHORT-CIRCUITS, and that is why the bug hid. A map whose every entry carries
 * `number` never evaluates the right-hand side, so it renders a complete band. The page
 * dies the moment an author deletes one field from one card. Both halves are asserted
 * below; a test that only rendered the fataling half would leave the short-circuit free to
 * disappear.
 *
 * THE GUARD IS A POSITIONAL COUNTER, NOT AN is_int() REJECTION, and the choice is
 * asserted rather than argued. The earlier siblings guard values whose only honest
 * degradation is absence — a corrupt image_url has no right answer, so the image is
 * dropped. Here there IS a right answer: `$index + 1` was only ever spelling "the 1-based
 * position of this card", and a counter says that for every container shape. So the
 * degradation is not a missing badge; it is the SAME band the equivalent list produces,
 * which is what testAStoredMapRendersByteIdenticallyToTheEquivalentList() measures.
 *
 * TWO MECHANISMS, NEITHER SUFFICIENT ALONE, and this file is only one of them. Since #738
 * the WRITE path also refuses an object-shaped `items` outright (a declared
 * `type: "array"` prop must be a JSON list — pinned in
 * tests/ListShapedPropWriteEnforcementTest.php). That closes the front door. This file
 * covers what a write gate cannot reach, which is the whole reason the guard family
 * exists: compositions authored before the rule landed, `restore_composition` (which
 * reports findings and never blocks, #233), and raw `_pp_composition` meta writes.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode)            │
 *                                              └─ foreach ($items as $item)
 *                                                   $item_position++            <- the guard
 *                                                   $item['number'] ?? (string) $item_position
 *
 * FIXTURES ARE RAW META, DELIBERATELY. pp_update_composition() would still store the map
 * (it is the storage writer, not a validator), but authoring the fixture through it would
 * mint per-write component ids and make the byte-equality case compare noise. Raw meta
 * with explicit ids is the shape the byte-equality claim needs, and it is also the honest
 * channel: after #738 raw meta is one of the three ways this shape can still arrive.
 * Section 14.1's authoring-path mandate is satisfied by the write-refusal file, which
 * drives the real actions — the two files split the two halves of the issue.
 *
 * ASSERTED AFFIRMATIVELY, NEVER BY ABSENCE OF A FATAL. phpunit.xml sets
 * failOnWarning="false", so a test that only proved "nothing threw" would pass against an
 * implementation that silently rendered an empty band. Every case below asserts what the
 * emitted HTML actually contains.
 */

use PHPUnit\Framework\TestCase;

class StoredGridItemsMapRenderGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
            'filters'    => [],
        ];
    }

    /**
     * Stores composition bytes with NO validator and NO id minting in the way.
     *
     * This is the channel the guard exists for. wp_json_encode() then decode is the real
     * round trip: a PHP array with string keys encodes to a JSON object and decodes back
     * to a string-keyed array, which is exactly what an aged `_pp_composition` row holds.
     */
    private function seedRaw(int $id, array $composition): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'ID' => $id, 'post_title' => 'Stored map', 'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_page_template'] = 'composition.php';
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition']   = wp_json_encode($composition);
    }

    /**
     * Reproduces the render loop of templates/composition.php (its lines 16-26): read the
     * stored items, skip any without a `component` key, promote a `style` map to the
     * `__pp_style` prop, and render each component in order. Deliberately carries NO
     * try/catch, because the absence of one is the whole defect — a TypeError here is the
     * 500 a visitor gets. The buffer is closed in a `finally` so a regression reports as a
     * clean failure instead of a risky test with a leaked output buffer.
     *
     * Kept byte-identical to the same helper in StoredStyleAndItemsRenderGuardTest,
     * StoredTitleRenderGuardTest, StoredBackgroundImageRenderGuardTest and
     * StoredImageUrlRenderGuardTest, so all five drift together or not at all. It is a
     * REPRODUCTION of that loop, not an invocation: it calls pp_get_composition($post_id)
     * where the template calls pp_composition() (which resolves the CURRENT post, and
     * there is no global post in a unit test), and it omits the pp_base_template() chrome.
     * Everything between those two — the decode, the prop/style handling, and the uncaught
     * pp_get_component() call that is the actual 500 — is the template's own code path.
     */
    private function renderStored(int $post_id): string
    {
        ob_start();
        try {
            foreach (pp_get_composition($post_id) as $item) {
                if (!isset($item['component'])) {
                    continue;
                }
                $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
                $style = isset($item['style'])  && is_array($item['style'])  ? $item['style']  : [];
                if ($style) {
                    $props['__pp_style'] = $style;
                }
                pp_get_component((string) $item['component'], $props);
            }
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    /** A grid band with an explicit id, so two renders differ only where the fixture does. */
    private static function band(array $items, string $layout = 'steps'): array
    {
        return [['component' => 'grid', 'props' => [
            'id'     => 'pp-band-grid',
            'title'  => 'Grid heading',
            'layout' => $layout,
            'items'  => $items,
        ]]];
    }

    // ── The fatal itself ────────────────────────────────────────────────────

    /**
     * THE ISSUE'S REPRO, as stored bytes. String keys, entries omitting `number`, so the
     * `??` falls through to the arithmetic that used to raise
     * "Unsupported operand types: string + int" and take the page down.
     *
     * Asserted on CONTENT, not on "it did not throw": the cards must actually be in the
     * output, or a guard that swallowed the whole band would pass.
     */
    public function testAStoredItemsMapWithNoNumbersRendersInsteadOfFataling(): void
    {
        $this->seedRaw(400, self::band(['first' => ['title' => 'Card one'], 'second' => ['title' => 'Card two']]));

        $html = $this->renderStored(400);

        $this->assertStringContainsString('Card one', $html);
        $this->assertStringContainsString('Card two', $html);
        $this->assertStringContainsString('data-pp-count="2"', $html, 'the band still reports its real count');
    }

    /**
     * The ORDINALS are the positions, which is the whole claim of the fix — the map's keys
     * ("first", "second") appear nowhere, and the badges count 1, 2 exactly as a list's do.
     *
     * `steps` layout, because that is the only layout that RENDERS the number. The
     * arithmetic ran for every layout (it sits above the layout branch), which is why the
     * `cards` case below fataled too and is asserted separately.
     */
    public function testTheOrdinalsAreThePositionsNotTheKeys(): void
    {
        $this->seedRaw(401, self::band([
            'first'  => ['title' => 'Card one'],
            'second' => ['title' => 'Card two'],
            'third'  => ['title' => 'Card three'],
        ]));

        $html = $this->renderStored(401);

        $this->assertStringContainsString('<span class="grid__step-number">1</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">2</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">3</span>', $html);
        $this->assertStringNotContainsString('first', $html, 'a stored key is never painted as an ordinal');
    }

    /**
     * DEGRADES TO EXACTLY THE MARKUP THE EQUIVALENT LIST PRODUCES — the container shape
     * stops being observable in the output at all.
     *
     * Both fixtures carry the same explicit band id and the same card contents; only the
     * container shape differs. Byte equality is available precisely because these are raw
     * meta writes: pp_update_composition() mints `props['id']` per write, so two writes of
     * the same composition differ in eight hex characters and this assertion would compare
     * noise (the trap StoredStyleAndItemsRenderGuardTest records at bandProps()).
     *
     * WHAT IT CAN AND CANNOT CATCH, stated because equality tests flatter themselves. This
     * one only fires when the two shapes DIVERGE, so it is deliberately paired with an
     * anchor: a regression that made the ordinal uniformly WRONG — `$item['number'] ?? ''`
     * for every container — keeps both renders byte-identical and would sail past the
     * equality assertion alone (measured). The anchor is what makes the pair say
     * "identical AND correct" rather than merely "identical". The real net for
     * correctness is the five behavioural cases around it; this is the cheap guard against
     * a shape-CONDITIONAL branch reappearing, which is a different failure and one none of
     * those five would catch.
     */
    public function testAStoredMapRendersByteIdenticallyToTheEquivalentList(): void
    {
        $cards = [['title' => 'Card one', 'text' => 'One'], ['title' => 'Card two', 'text' => 'Two']];
        $this->seedRaw(402, self::band($cards));
        $this->seedRaw(403, self::band(['first' => $cards[0], 'second' => $cards[1]]));

        $list_html = $this->renderStored(402);
        $map_html  = $this->renderStored(403);

        $this->assertSame(
            $list_html,
            $map_html,
            'a stored items map must render exactly the band its list form renders'
        );
        // The anchor: equality alone is satisfied by a uniformly empty ordinal.
        $this->assertStringContainsString('<span class="grid__step-number">1</span>', $map_html);
        $this->assertStringContainsString('<span class="grid__step-number">2</span>', $map_html);
    }

    /**
     * THE SHORT-CIRCUIT HALF, which is how the defect stayed hidden: `??` never evaluates
     * the arithmetic for an entry that HAS `number`, so a fully-numbered map rendered a
     * complete band on main and only died once a field was deleted.
     *
     * The authored numbers must still win over the counter — a fix that always counted
     * would silently renumber every steps band in existence, including well-formed lists
     * whose authors chose "01", "1." or non-sequential labels.
     */
    public function testAnAuthoredNumberStillWinsOverTheCounter(): void
    {
        $this->seedRaw(404, self::band([
            'a' => ['title' => 'Card one', 'number' => '01'],
            'b' => ['title' => 'Card two', 'number' => '02'],
        ]));

        $html = $this->renderStored(404);

        $this->assertStringContainsString('<span class="grid__step-number">01</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">02</span>', $html);
        $this->assertStringNotContainsString('>1<', $html, 'the counter must not override an authored label');
    }

    /**
     * A PARTIALLY numbered map — the exact transition the issue describes. One card keeps
     * its authored label, the neighbour that lost its `number` falls through to the
     * counter, and the counter reports that card's POSITION rather than restarting or
     * continuing from the authored value.
     */
    public function testAMapMixingAuthoredAndMissingNumbersRendersBoth(): void
    {
        $this->seedRaw(405, self::band([
            'a' => ['title' => 'Card one', 'number' => 'A'],
            'b' => ['title' => 'Card two'],
            'c' => ['title' => 'Card three', 'number' => 'C'],
        ]));

        $html = $this->renderStored(405);

        $this->assertStringContainsString('<span class="grid__step-number">A</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">2</span>', $html,
            'the unnumbered card takes its own position, not the neighbour label');
        $this->assertStringContainsString('<span class="grid__step-number">C</span>', $html);
    }

    /**
     * THE `cards` LAYOUT, which emits no ordinal at all and fataled anyway.
     *
     * Worth its own case rather than folding into the sweep above: the arithmetic sat
     * ABOVE the `$is_steps` branch, so the page died before reaching the code that decides
     * whether a number is even painted. A reader who assumed "only steps grids were
     * affected" would under-scope both the fix and its disclosure.
     */
    public function testACardsLayoutMapRendersToo(): void
    {
        $this->seedRaw(406, self::band(['x' => ['title' => 'Card one'], 'y' => ['title' => 'Card two']], 'cards'));

        $html = $this->renderStored(406);

        $this->assertStringContainsString('Card one', $html);
        $this->assertStringContainsString('Card two', $html);
        $this->assertStringNotContainsString('grid__step-number', $html, 'cards paint no ordinal');
    }

    // ── Boundaries ──────────────────────────────────────────────────────────

    /**
     * A LIST IS BYTE-IDENTICAL TO EVERY VERSION BEFORE THE GUARD, which is what makes it
     * safe to land on data that renders today: for a list, position == key + 1 at every
     * element, so the counter changes nothing for the overwhelmingly common shape.
     *
     * Written with THREE cards so an off-by-one in the counter cannot hide behind a
     * single-element fixture.
     */
    public function testAWellFormedListStillNumbersFromOne(): void
    {
        $this->seedRaw(407, self::band([
            ['title' => 'Card one'], ['title' => 'Card two'], ['title' => 'Card three'],
        ]));

        $html = $this->renderStored(407);

        $this->assertStringContainsString('<span class="grid__step-number">1</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">2</span>', $html);
        $this->assertStringContainsString('<span class="grid__step-number">3</span>', $html);
        $this->assertStringNotContainsString('<span class="grid__step-number">0</span>', $html,
            'the counter is 1-based, exactly as ($index + 1) was');
    }

    /**
     * A SPARSE / REORDERED NUMERIC map, the #652 shape: keys {"5"} or {"1","0"} fold to
     * integers at decode, so the old arithmetic did NOT fatal on them — it silently
     * painted the KEY plus one. `{"5": card}` rendered a lone badge reading `6`, and
     * `{"1": a, "0": b}` numbered the first-rendered card `2`.
     *
     * That is a quieter defect than the 500 and it is fixed by the same counter, so it is
     * pinned here rather than left to be rediscovered: ordinals follow render order, always.
     */
    public function testAFoldedNumericMapNumbersByRenderOrderNotByKey(): void
    {
        $this->seedRaw(408, self::band(['5' => ['title' => 'Only card']]));
        $this->assertStringContainsString('<span class="grid__step-number">1</span>', $this->renderStored(408),
            'the lone card is the first one, whatever its stored key says');

        $this->seedRaw(409, self::band(['1' => ['title' => 'Rendered first'], '0' => ['title' => 'Rendered second']]));
        $html = $this->renderStored(409);
        $this->assertMatchesRegularExpression(
            '/step-number">1<.*Rendered first.*step-number">2<.*Rendered second/s',
            $html,
            'render order decides the ordinal; the folded keys are ignored'
        );
    }

    /**
     * A SCALAR OR NULL ENTRY inside a map does not fatal at this boundary, and the claim
     * is measured rather than assumed because it is easy to get backwards.
     * `$item['number']` on a string, int, float, bool or null returns null under `??`'s
     * isset() semantics — no TypeError, no warning — so the counter supplies the ordinal
     * and the card renders empty rather than taking the page down.
     *
     * Stated as a BOUNDARY, not a promise: entry shape is a different axis, owned at the
     * write path by the `item_type: "object"` rule, and other reads inside the loop have
     * their own residuals (#730's esc_url on an array `link_url` still fatals). What this
     * pins is only that the ORDINAL read this issue fixes is not itself a second fatal.
     */
    public function testScalarEntriesInAMapDoNotFatalAtTheOrdinalRead(): void
    {
        $this->seedRaw(410, self::band([
            'a' => 'not an object',
            'b' => 42,
            'c' => null,
            'd' => ['title' => 'A real card'],
        ]));

        $html = $this->renderStored(410);

        $this->assertStringContainsString('A real card', $html);
        $this->assertStringContainsString('data-pp-count="4"', $html);
        $this->assertStringContainsString('<span class="grid__step-number">4</span>', $html,
            'the real card is fourth, so the counter says 4');
    }

    /**
     * THE SOURCE TRIPWIRE. The whole fix is "stop doing arithmetic on the array key", and
     * the cheapest way to lose it is a future refactor reintroducing the key into the
     * foreach for some unrelated reason and then reusing it for the ordinal.
     *
     * TOKENS, NOT A REGEX, and the first draft of this test is why. It matched
     * `/foreach\s*\(\s*\$items\s+as\s+\$\w+\s*=>/` against comment-stripped source, which
     * looks airtight and is not: `foreach ($items as$index=>$item)` is valid PHP — the
     * `\s+` after `as` is required by the pattern but not by the language — and
     * token_get_all() preserves that spelling verbatim, so the defect walks straight
     * through. Measured, not theorised. A regex over source is a lexer with none of the
     * lexer's rules; the real lexer is right there, so this asks IT.
     *
     * Comments are dropped for the reason every source-level checker in this repo drops
     * them: the guard's own comment block quotes the old `(string) ($index + 1)`
     * expression verbatim to explain it, and a raw scan would count the explanation as
     * the defect.
     *
     * WHAT IT PINS, and what it deliberately does not. Two structural facts: no `foreach`
     * over `$items` binds a key, and the `$item_number` assignment reads `$item_position`.
     * It does NOT try to prove the counter is correct — that is the five behavioural cases
     * above, which is the right division of labour. A source check that tried to verify
     * arithmetic would be a worse copy of the tests that render actual HTML.
     */
    public function testTheCardLoopDoesNotBindOrDoArithmeticOnTheItemsKey(): void
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents(dirname(__DIR__) . '/components/grid/grid.php')),
            static fn ($t): bool => !is_array($t) || ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT)
        ));

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FOREACH) {
                continue;
            }
            // Collect the header tokens between the foreach's own parentheses, tracking
            // depth so a nested call in the subject expression cannot end it early.
            $depth  = 0;
            $header = [];
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t === '(') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                }
                if ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($depth >= 1) {
                    $header[] = $t;
                }
            }

            $subject_is_items = false;
            foreach ($header as $t) {
                if (is_array($t) && $t[0] === T_AS) {
                    break;      // everything before `as` is the subject expression
                }
                if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$items') {
                    $subject_is_items = true;
                }
            }
            if (!$subject_is_items) {
                continue;
            }

            $binds_key = false;
            foreach ($header as $t) {
                if (is_array($t) && $t[0] === T_DOUBLE_ARROW) {
                    $binds_key = true;
                }
            }
            $this->assertFalse(
                $binds_key,
                'the card loop must not bind the items key — an unused key is how the'
                . ' string-key arithmetic (#738) comes back'
            );
        }

        // The ordinal's fallback must READ the counter. Asserted on the assignment's own
        // tokens rather than on the literal `$item_position++` appearing anywhere in the
        // file: the literal would pass for a counter left behind in dead code, and would
        // FAIL a correct refactor to `$item_position += 1`.
        $found_assignment = false;
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$item_number') {
                continue;
            }
            // Only the ASSIGNMENT, not the later `esc_html($item_number)` READ. Without
            // this the read scans forward to its own `;`, finds no counter, and the test
            // fails against correct code — a tripwire that cries wolf gets deleted.
            $next = null;
            for ($k = $i + 1, $n = count($tokens); $k < $n; $k++) {
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    continue;
                }
                $next = $tokens[$k];
                break;
            }
            if ($next !== '=') {
                continue;
            }
            $reads_counter = false;
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                if ($tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === '$item_position') {
                    $reads_counter = true;
                }
            }
            $found_assignment = true;
            $this->assertTrue(
                $reads_counter,
                'the $item_number fallback must come from the positional counter, not the array key'
            );
        }
        $this->assertTrue($found_assignment, 'the $item_number assignment must still exist to be pinned');
    }
}
