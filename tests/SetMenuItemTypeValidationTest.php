<?php
/**
 * tests/SetMenuItemTypeValidationTest.php
 *
 * set_menu's menu items are validated by type before any change is made — the same rule
 * the #913 / #931 / #935 line applies to top-level params, carried down into the nested
 * `items` list (and each item's `children`), where the top-level param gate cannot see.
 *
 * THE RULE. An item field takes add_menu_item's declared type, because both actions take
 * the same item: page_id an int, url a string, label a string. A present value of any other
 * type is refused with `invalid_param_type` and a message naming the field by its path
 * (`Param "items[0].children[1].label" must be string, got array.`). A present null means
 * "not sent", exactly as an optional null does at the top-level gate.
 *
 * WHAT IS PINNED, for each malformed shape:
 *   - the validator refuses it by name;
 *   - preview refuses it the same way;
 *   - execute refuses it the same way, and the existing menu (items, order, location) is
 *     exactly what it was, with no new menu created;
 *   - in a batch, the refusal fails that step and the existing rollback undoes the steps
 *     before it, leaving the menu as it was.
 *
 * Every test authors through the real set_menu action (Section 14.1), never raw meta.
 */

use PHPUnit\Framework\TestCase;

class SetMenuItemTypeValidationTest extends TestCase
{
    private const MENU = 'Main Menu';

    /** Every non-null shape that is not a string. null is "not sent" and pinned separately. */
    private const NON_STRINGS = [0, 42, 1.5, true, false, [], ['a', 'b'], ['k' => 'v']];

    /** Every non-null shape that is not an int, including the numeric-looking ones. */
    private const NON_INTS = ['12', '', 'abc', 12.0, 1.5, true, false, [], ['12']];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
            'custom_css' => '',
            'filters'    => [],
        ];
        // The batch gate reads the postmeta row through the handle and fails closed without
        // one (#833); production always has one.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** A real menu with a page link, a dropdown parent with a child, and a location. */
    private function seedMenu(): array
    {
        $page   = pp_create_page('Pricing', 'publish');
        $result = pp_execute_action('set_menu', [
            'name'     => self::MENU,
            'items'    => [
                ['page_id' => $page],
                ['url' => 'https://example.com/blog', 'label' => 'Blog', 'children' => [
                    ['url' => 'https://example.com/blog/news', 'label' => 'News'],
                ]],
            ],
            'location' => 'primary',
        ]);
        $this->assertTrue($result['ok'], 'premise: the seed menu was built');
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertCount(3, $menus[0]['items'], 'premise: parent, dropdown parent and child');
        return $menus;
    }

    private function describe($value): string
    {
        return gettype($value) . ':' . json_encode($value);
    }

    /**
     * Asserts one malformed items list is refused by name on all three entry points, and
     * that a refused execute leaves every menu exactly as it was.
     */
    private function assertRefusedEverywhere(array $items, string $code, string $message, string $case): void
    {
        $params = ['name' => self::MENU, 'items' => $items, 'location' => 'primary'];
        $before = pp_get_menus();

        $validated = pp_validate_action('set_menu', $params);
        $this->assertInstanceOf(WP_Error::class, $validated, "{$case}: validate refuses");
        $this->assertSame($code, $validated->get_error_code(), "{$case}: validate code");
        $this->assertSame($message, $validated->get_error_message(), "{$case}: validate names the field");

        $preview = pp_preview_action('set_menu', $params);
        $this->assertInstanceOf(WP_Error::class, $preview, "{$case}: preview refuses");
        $this->assertSame($code, $preview->get_error_code(), "{$case}: preview code");
        $this->assertSame($message, $preview->get_error_message(), "{$case}: preview message");

        try {
            $result = pp_execute_action('set_menu', $params);
        } catch (\Throwable $e) {
            $this->fail("{$case}: execute threw " . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertFalse($result['ok'], "{$case}: execute refuses");
        $this->assertSame($code, $result['error_code'], "{$case}: execute code");
        $this->assertSame($message, $result['error'], "{$case}: execute message");
        $this->assertSame($before, pp_get_menus(), "{$case}: every menu is exactly as it was");
    }

    // ── Top-level items ─────────────────────────────────────────────────────

    public function testANonStringLabelIsRefusedByNameAndTheMenuIsUnchanged(): void
    {
        $this->seedMenu();
        foreach (self::NON_STRINGS as $label) {
            $this->assertRefusedEverywhere(
                [['url' => 'https://example.com/ok', 'label' => 'Fine'], ['url' => 'https://example.com/x', 'label' => $label]],
                'invalid_param_type',
                sprintf('Param "items[1].label" must be string, got %s.', gettype($label)),
                'label ' . $this->describe($label)
            );
        }
    }

    public function testANonStringUrlIsRefusedByNameAndTheMenuIsUnchanged(): void
    {
        $this->seedMenu();
        foreach (self::NON_STRINGS as $url) {
            $this->assertRefusedEverywhere(
                [['url' => $url, 'label' => 'X']],
                'invalid_param_type',
                sprintf('Param "items[0].url" must be string, got %s.', gettype($url)),
                'url ' . $this->describe($url)
            );
        }
    }

    public function testANonIntPageIdIsRefusedByNameAndTheMenuIsUnchanged(): void
    {
        $this->seedMenu();
        foreach (self::NON_INTS as $page_id) {
            $this->assertRefusedEverywhere(
                [['page_id' => $page_id]],
                'invalid_param_type',
                sprintf('Param "items[0].page_id" must be int, got %s.', gettype($page_id)),
                'page_id ' . $this->describe($page_id)
            );
        }
    }

    /** A field typed wrong is refused by type even beside a field that would be ambiguous. */
    public function testTheTypeRuleRunsBeforeTheItemsOtherRules(): void
    {
        $this->seedMenu();
        $page = pp_create_page('About', 'publish');
        $this->assertRefusedEverywhere(
            [['page_id' => $page, 'label' => ['x']]],
            'invalid_param_type',
            'Param "items[0].label" must be string, got array.',
            'label on a page item'
        );
    }

    // ── Dropdown children ───────────────────────────────────────────────────

    public function testAChildWithANonStringFieldIsRefusedByItsPath(): void
    {
        $this->seedMenu();
        $page = pp_create_page('Team', 'publish');
        $cases = [
            'label'   => [['url' => 'https://example.com/c', 'label' => ['x']], 'Param "items[0].children[1].label" must be string, got array.'],
            'url'     => [['url' => ['x'], 'label' => 'C'], 'Param "items[0].children[1].url" must be string, got array.'],
            'page_id' => [['page_id' => (string) $page], 'Param "items[0].children[1].page_id" must be int, got string.'],
        ];
        foreach ($cases as $field => [$child, $message]) {
            $this->assertRefusedEverywhere(
                [['url' => 'https://example.com/p', 'label' => 'Parent', 'children' => [
                    ['url' => 'https://example.com/ok', 'label' => 'Fine child'],
                    $child,
                ]]],
                'invalid_param_type',
                $message,
                "child {$field}"
            );
        }
    }

    /** A child that is not an object at all keeps its existing refusal, before any change. */
    public function testAChildThatIsNotAnObjectIsRefusedBeforeAnyChange(): void
    {
        $this->seedMenu();
        $this->assertRefusedEverywhere(
            [['url' => 'https://example.com/p', 'label' => 'Parent', 'children' => ['just a string']]],
            'invalid_item',
            'items[0].children[0] must be an object.',
            'non-object child'
        );
    }

    /**
     * A grandchild is refused by the depth rule (one level of nesting), whatever its fields
     * hold — the depth refusal answers first, and it too comes before any change.
     */
    public function testAGrandchildIsRefusedByTheDepthRuleBeforeAnyChange(): void
    {
        $this->seedMenu();
        $this->assertRefusedEverywhere(
            [['url' => 'https://example.com/p', 'label' => 'Parent', 'children' => [
                ['url' => 'https://example.com/c', 'label' => 'Child', 'children' => [
                    ['url' => 'https://example.com/g', 'label' => ['x']],
                ]],
            ]]],
            'nesting_too_deep',
            'items[0].children[0]: menus support one level of nesting — a child cannot have its own children.',
            'grandchild'
        );
    }

    /** A refused set_menu naming a menu that does not exist yet creates nothing. */
    public function testARefusedSetMenuForANewMenuCreatesNoMenu(): void
    {
        $before = $this->seedMenu();
        $result = pp_execute_action('set_menu', [
            'name'  => 'Brand New Menu',
            'items' => [['url' => 'https://example.com/x', 'label' => ['x']]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_param_type', $result['error_code']);
        $this->assertSame($before, pp_get_menus(), 'no menu was created');
    }

    /**
     * The chat path sends `items` as one JSON string, which the chat transport decodes
     * (pp_ai_coerce_params) before validation. Its numeric-string cast applies to
     * top-level params only, so a quoted page_id inside an item stays a string and is
     * refused by the same rule; a JSON integer page_id is accepted.
     */
    public function testTheChatTransportPathAppliesTheSameItemRule(): void
    {
        $this->seedMenu();
        $page = pp_create_page('About', 'publish');

        $quoted = pp_ai_coerce_params('action', 'set_menu', [
            'name'  => self::MENU,
            'items' => json_encode([['page_id' => (string) $page]]),
        ]);
        $this->assertSame([['page_id' => (string) $page]], $quoted['items'], 'premise: items decoded, page_id still a string');
        $refused = pp_validate_action('set_menu', $quoted);
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('invalid_param_type', $refused->get_error_code());
        $this->assertSame('Param "items[0].page_id" must be int, got string.', $refused->get_error_message());

        $typed = pp_ai_coerce_params('action', 'set_menu', [
            'name'  => self::MENU,
            'items' => json_encode([['page_id' => $page]]),
        ]);
        $this->assertTrue(pp_validate_action('set_menu', $typed), 'a JSON integer page_id is accepted');
    }

    // ── What is still accepted ──────────────────────────────────────────────

    /**
     * A present null means "not sent", as an optional null does at the top-level gate: a
     * page item carrying url/label null, and a custom link carrying page_id null, both
     * still build.
     */
    public function testANullItemFieldStillMeansNotSent(): void
    {
        $page   = pp_create_page('Pricing', 'publish');
        $result = pp_execute_action('set_menu', [
            'name'  => self::MENU,
            'items' => [
                ['page_id' => $page, 'url' => null, 'label' => null],
                ['page_id' => null, 'url' => 'https://example.com/blog', 'label' => 'Blog', 'children' => [
                    ['page_id' => null, 'url' => 'https://example.com/blog/news', 'label' => 'News'],
                ]],
            ],
        ]);
        $this->assertTrue($result['ok'], 'null fields are absent, not refused');
        $menus = pp_get_menus();
        $this->assertSame(['Pricing', 'Blog', 'News'], array_column($menus[0]['items'], 'title'));
    }

    /** Well-typed items keep building exactly as before, including an int page_id of 0 beside a link. */
    public function testWellTypedItemsStillBuild(): void
    {
        $page   = pp_create_page('Pricing', 'publish');
        $result = pp_execute_action('set_menu', [
            'name'  => self::MENU,
            'items' => [
                ['page_id' => $page],
                ['page_id' => 0, 'url' => 'https://example.com/blog', 'label' => 'Blog'],
            ],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(['Pricing', 'Blog'], array_column(pp_get_menus()[0]['items'], 'title'));
    }

    // ── Batches ─────────────────────────────────────────────────────────────

    /**
     * A batch whose later set_menu carries a mistyped item fails at that step, by name,
     * and the existing rollback undoes the step before it; the menu is as it was.
     */
    public function testABatchWithAMistypedMenuItemRollsBackAndKeepsTheMenu(): void
    {
        $before = $this->seedMenu();
        $page   = pp_create_page('Landing', 'publish');
        pp_update_seo_meta($page, ['seo_title' => 'Original SEO title']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_seo_meta', 'params' => [
                'post_id' => $page, 'meta' => ['seo_title' => 'Changed by step 0'],
            ]],
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => self::MENU,
                'items' => [['url' => 'https://example.com/x', 'label' => ['not', 'a', 'string']]],
            ]],
        ]);

        $this->assertTrue($batch['steps'][0]['ok'], 'premise: step 0 really landed');
        $this->assertFalse($batch['ok'], 'the batch failed');
        $this->assertSame(1, $batch['failed_at'], 'at the set_menu step');
        $this->assertSame('invalid_param_type', $batch['steps'][1]['error_code'], 'refused by name');
        $this->assertTrue($batch['rolled_back'], 'and rolled back');
        $this->assertSame([], $batch['rollback_errors'], 'cleanly');
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title'], 'step 0 is undone');
        $this->assertSame($before, pp_get_menus(), 'the menu is as it was');
    }

    /** The same when the step before is itself a set_menu on the same menu. */
    public function testABatchThatReplacesTheMenuThenSendsAMistypedChildRestoresTheMenu(): void
    {
        $before = $this->seedMenu();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => self::MENU,
                'items' => [['url' => 'https://example.com/only', 'label' => 'Only']],
            ]],
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => self::MENU,
                'items' => [['url' => 'https://example.com/p', 'label' => 'P', 'children' => [
                    ['url' => 42, 'label' => 'C'],
                ]]],
            ]],
        ]);

        $this->assertTrue($batch['steps'][0]['ok'], 'premise: step 0 replaced the menu');
        $this->assertFalse($batch['ok']);
        $this->assertSame(1, $batch['failed_at']);
        $this->assertSame('invalid_param_type', $batch['steps'][1]['error_code']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);

        $after = pp_get_menus();
        $this->assertCount(1, $after);
        $this->assertSame(
            array_column($before[0]['items'], 'title'),
            array_column($after[0]['items'], 'title'),
            'the seeded items are back, in order'
        );
        $this->assertSame('primary', $after[0]['location'], 'and the location');
    }
}
