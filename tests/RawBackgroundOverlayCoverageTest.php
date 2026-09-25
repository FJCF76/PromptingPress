<?php
/**
 * tests/RawBackgroundOverlayCoverageTest.php — branches of PR-2 of #1145 (#1141, #1142, #1144) that the
 * review-cycle pins left unexercised: the drop reason on a role that is not the band, the joined
 * uncovered-width causes, the effective accessor's `raw` key, the own-background reader and the
 * per-axis helpers called directly, `within` fully filtered out of `wp pp schema`, an empty
 * `overlay_defaults` value, the refused-raw message inside a state, and the prompt clauses.
 */

use PHPUnit\Framework\TestCase;

final class RawBackgroundOverlayCoverageTest extends TestCase
{
    private const DARK = 'rgba(6,10,28,0.72)';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        foreach ([9001, 9002] as $id) {
            $GLOBALS['_pp_test_store']['posts'][$id]               = ['post_type' => 'attachment'];
            $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function item(array $udc, string $component = 'cta'): array
    {
        return ['component' => $component, 'id' => 'pp-a1b2c3d4', 'props' => ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'], 'udc' => $udc];
    }

    private function offScrim(array $udc): array
    {
        return array_values(array_filter(pp_udc_composition_findings([$this->item($udc)]),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_accent_off_scrim'));
    }

    /**
     * A ROLE THAT IS NOT THE BAND (#1141): `band_scrim_at` is written for the resting `_band` only, so a scrim dropped
     * under a raw background on an inner role takes the plain arm: it names the raw background, never "Set
     * background.image", and says nothing about the band's marker or accents it cannot know.
     */
    public function testADroppedScrimOnAnInnerRoleNamesTheRawBackgroundWithoutBandWording(): void
    {
        $drops = [];
        pp_udc_compile_band($this->item(['text' => ['background' => ['image' => 9001, 'overlay' => self::DARK], PP_UDC_CSS_KEY => ['background' => '#ffffff']]]), 'authored', $drops);
        $rows = array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('removed the image, so background.image and this scrim do not paint at this width. Write the whole treatment in one place', $rows[0]['reason']);
        $this->assertStringNotContainsString('Set background.image', $rows[0]['reason']);
        $this->assertStringNotContainsString('marked', $rows[0]['reason'], 'the band marker is not this role\'s');
        $this->assertStringNotContainsString('accent', $rows[0]['reason'], 'band_relights is read for _band only');
    }

    /** The effective accessor says WHAT replaced an image (#1141): `raw` true for `_css`, false for a group fill, absent where the image paints. */
    public function testTheEffectiveAccessorMarksWhatReplacedTheImage(): void
    {
        $raw = pp_udc_band_effective_background(pp_udc_compile_band($this->item(['_band' => ['background' => ['image' => 9001, 'overlay' => self::DARK],
            PP_UDC_CSS_KEY => ['background' => ['p' => '#ffffff']]]]), 'authored'));
        $this->assertTrue($raw['tiers']['p']['raw']);
        $this->assertFalse($raw['tiers']['p']['image']);
        $this->assertArrayNotHasKey('raw', $raw['tiers']['d'], 'absent where the image paints');
        $this->assertSame('no-repeat', $raw['tiers']['d']['repeat'], 'the emitted repeat companion is carried');
        $this->assertSame('cover', $raw['tiers']['d']['size']);

        $fill = pp_udc_band_effective_background(pp_udc_compile_band($this->item(['_band' => ['background' => ['image' => 9001, 'overlay' => self::DARK, 'fill' => ['p' => '#ffffff']]]]), 'authored'));
        $this->assertFalse($fill['tiers']['p']['image']);
        $this->assertFalse($fill['tiers']['p']['raw'], 'a group fill is not raw');
    }

    /** Several uncovered-width causes in one band are joined into ONE condition, each with its own widths (#1142, by cause). */
    public function testUncoveredWidthCausesAreJoinedInOneCondition(): void
    {
        // Desktop: image, no scrim. Tablet: scrim. Phone: a light raw background replaced the image.
        $found = $this->offScrim(['_band' => ['background' => ['image' => 9001, 'overlay' => ['t' => self::DARK, 'p' => self::DARK]],
            PP_UDC_CSS_KEY => ['background' => ['p' => '#ffffff']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the scrim is set only at the tablet width, so at the desktop width the accent sits on the unscrimmed image, '
            . 'and at the phone width the raw background in _css replaces the image and its scrim', $found[0]['message']);

        // Desktop scrim; tablet: raw light background; phone: a light fill set there.
        $found = $this->offScrim(['_band' => ['background' => ['image' => 9001, 'overlay' => self::DARK, 'fill' => ['p' => '#ffffff']],
            PP_UDC_CSS_KEY => ['background' => ['t' => '#ffffff']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('at the tablet width the raw background in _css replaces the image and its scrim, so the accent sits on that background, '
            . 'and at the phone width the background you set there replaces the image and its scrim', $found[0]['message']);

        // Both replacing backgrounds readably dark: the gate removes every part, so no condition is written.
        $this->assertSame([], $this->offScrim(['_band' => ['background' => ['image' => 9001, 'overlay' => self::DARK, 'fill' => ['p' => '#0a0a12']],
            PP_UDC_CSS_KEY => ['background' => ['t' => '#0a0a12']]]]));
    }

    /** _pp_udc_band_own_background's contract, directly: desktop then the width in emitted order; what shows under a painting image. */
    public function testBandOwnBackgroundReadsTheEmittedCascade(): void
    {
        $block = static fn (string $bp, array $decls, string $state = '', string $item = '', string $role = '_band'): array
            => ['role' => $role, 'item' => $item, 'state' => $state, 'bp' => $bp, 'decls' => array_map(static fn (string $css): array => ['css' => $css], $decls)];
        $this->assertNull(_pp_udc_band_own_background(['blocks' => []], 'd'), 'no declaration: null');
        $this->assertNull(_pp_udc_band_own_background(['blocks' => [
            $block('d', ['background' => '#fff'], ':hover'), $block('d', ['background' => '#fff'], '', 'i1'), $block('d', ['background' => '#fff'], '', '', 'text'),
        ]], 'd'), 'state, item and other-role blocks are not the band\'s resting background');

        $compiled = ['tokens' => ['bg' => '#0a0a12'], 'blocks' => [
            $block('d', ['background' => 'linear-gradient(#000, #111) #222222']),
            $block('p', ['background-color' => 'var(--pp-bg)']),
        ]];
        $this->assertSame('linear-gradient(#000, #111)', _pp_udc_band_own_background($compiled, 'd'), 'image layers paint where no image does');
        $this->assertSame('#222222', _pp_udc_band_own_background($compiled, 'd', true), 'under the painting image only the colour shows');
        $this->assertSame('#0a0a12', _pp_udc_band_own_background($compiled, 'p', true), 'the width\'s later colour wins, band token put back');
        $this->assertSame('linear-gradient(#000, #111)', _pp_udc_band_own_background($compiled, 't'), 'tablet inherits desktop only');

        $none = ['blocks' => [$block('d', ['background-color' => '#333333', 'background-image' => 'none'])]];
        $this->assertSame('#333333', _pp_udc_band_own_background($none, 'd'), 'an image of none leaves the colour');
    }

    /** The per-axis helpers at their edges, directly (#1142 item 1). */
    public function testRepeatAxesAndPartialPredicateEdges(): void
    {
        $this->assertSame(['repeat', 'repeat'], _pp_udc_repeat_axes(''), 'initial repeat');
        $this->assertSame(['repeat', 'no-repeat'], _pp_udc_repeat_axes('REPEAT-X'));
        $this->assertSame(['space', 'space'], _pp_udc_repeat_axes(' space '));
        $this->assertSame(['round', 'no-repeat'], _pp_udc_repeat_axes('round  no-repeat'), 'two keywords, per axis');

        $this->assertFalse(_pp_udc_scrim_leaves_part_uncovered('', 'no-repeat'), 'unset size is auto');
        $this->assertFalse(_pp_udc_scrim_leaves_part_uncovered('COVER', 'no-repeat'));
        $this->assertFalse(_pp_udc_scrim_leaves_part_uncovered('100.0%', 'no-repeat'), 'a decimal 100% covers');
        $this->assertTrue(_pp_udc_scrim_leaves_part_uncovered('.5%', 'no-repeat'), 'a leading-dot percentage under 100');
        $this->assertTrue(_pp_udc_scrim_leaves_part_uncovered('calc(100% - 1px)', 'no-repeat'), 'a value the engine cannot read is not claimed to cover');
        $this->assertFalse(_pp_udc_scrim_leaves_part_uncovered('50%', 'repeat no-repeat'), 'x tiles; y is auto and covers');
        $this->assertTrue(_pp_udc_scrim_leaves_part_uncovered('100% 50%', 'repeat no-repeat'), 'y is short and does not tile');
        $this->assertFalse(_pp_udc_scrim_leaves_part_uncovered('200px 200px', ''), 'the initial repeat tiles both axes');

        $this->assertSame('and tiled only across', _pp_udc_repeat_phrase('round no-repeat'));
        $this->assertSame('and tiled only down', _pp_udc_repeat_phrase('no-repeat repeat'));
        $this->assertSame('and spaced, which can leave gaps', _pp_udc_repeat_phrase('no-repeat space'));
        $this->assertSame('without tiling', _pp_udc_repeat_phrase('no-repeat'));
    }

    /** `within` whose every name is filtered out is OMITTED from `wp pp schema`, never printed as an empty list (#1144). */
    public function testTheSchemaReportOmitsWithinWhenNoNameIsARole(): void
    {
        $root = sys_get_temp_dir() . '/pp-within-none-' . uniqid();
        mkdir($root . '/components/ppwithinnone', 0777, true);
        file_put_contents($root . '/components/ppwithinnone/ppwithinnone.php', '<?php echo "<section data-pp-component=\"ppwithinnone\"></section>";');
        file_put_contents($root . '/components/ppwithinnone/schema.json', json_encode([
            'component' => 'ppwithinnone', 'description' => 'scratch', 'props' => ['id' => ['type' => 'string', 'required' => false, 'description' => 'id', 'default' => '']],
            'roles' => [
                'outer' => ['selector' => '.o', 'groups' => ['typography'], 'description' => 'o'],
                'inner' => ['selector' => '.i', 'groups' => ['typography'], 'description' => 'i', 'within' => ['ghost']],
            ],
        ]));
        $previous = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        try {
            $report = \pp_component_schema_report('ppwithinnone');
            $this->assertIsArray($report, 'premise: the scratch component is registered');
            $by_role = array_column($report['roles'], null, 'role');
            $this->assertArrayHasKey('inner', $by_role);
            $this->assertArrayNotHasKey('within', $by_role['inner'], 'no role name left: the key is absent');
            $this->assertArrayNotHasKey('overlay_defaults', $by_role['inner']);
            $this->assertArrayNotHasKey('text_content', $by_role['inner']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previous;
            }
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            array_map('unlink', glob($root . '/components/ppwithinnone/*'));
            rmdir($root . '/components/ppwithinnone');
            rmdir($root . '/components');
            rmdir($root);
        }
    }

    /** An EMPTY `overlay_defaults` value (an empty breakpoint map) is refused like an empty string (#1142 item 2). */
    public function testAnEmptyOverlayDefaultsValueIsRefused(): void
    {
        $errors = pp_schema_definition_errors(['selector' => '.x', 'groups' => ['typography'], 'overlay_defaults' => ['typography' => ['color' => []]]], 'role', 'c role r');
        $this->assertContains('c role r: `overlay_defaults` group `typography` parameter `color` must be a single-line string or a number, or a breakpoint map of them.', $errors);
        $errors = pp_schema_definition_errors(['selector' => '.x', 'groups' => ['typography'], 'overlay_defaults' => ['typography' => ['color' => ['d' => '#fff', 't' => "a\rb"]]]], 'role', 'c role r');
        $this->assertNotSame([], $errors, 'a line break inside a breakpoint leaf');
    }

    /** The refused-raw message names the state it was written in (#1141): both arms carry `:hover`. */
    public function testTheRefusedRawMessageNamesItsState(): void
    {
        $found = array_values(array_filter(pp_udc_composition_findings([$this->item(['_band' => [
            'background' => [':hover' => ['fill' => '#000000']], PP_UDC_CSS_KEY => [':hover' => ['background' => 'not a colour']],
        ]])]), static fn (array $f): bool => $f['type'] === 'udc_css_overrides_group_value'));
        $this->assertNotSame([], $found);
        $message = implode(' | ', array_column($found, 'message'));
        $this->assertStringContainsString('role "_band" :hover: the raw declaration "background"', $message);
        $this->assertStringContainsString('the stored raw value cannot be emitted', $message);
    }

    /**
     * THE RT2 GUARD'S OTHER ARM IS NOT REACHABLE (#1141): a narrower bucket keeps an image of its own under a raw desktop
     * background only through a per-breakpoint raw `background-image`, which the write gate refuses; a stored one is
     * dropped at emit WITH a ledger row, so the phone scrim is dropped and names the raw background. Pinned so the
     * guard's reachability is re-checked the day either rule changes.
     */
    public function testANarrowerRawImageUnderARawDesktopBackgroundIsRefusedAndLedgered(): void
    {
        $item = $this->item(['_band' => ['background' => ['image' => 9001, 'overlay' => ['p' => self::DARK]],
            PP_UDC_CSS_KEY => ['background' => '#ffffff', 'background-image' => ['p' => 9002]]]]);
        $refusal = pp_udc_validate_map($item['udc'], 'cta');
        $this->assertInstanceOf(WP_Error::class, $refusal);
        $this->assertStringContainsString('"background-image" cannot be set per breakpoint', $refusal->get_error_message());
        $drops = [];
        pp_udc_compile_band($item, 'authored', $drops);
        $wheres = array_column($drops, 'where');
        $this->assertContains('role "_band" "_css" background-image', $wheres, 'the stored per-width raw image is ledgered');
        $rows = array_values(array_filter($drops, static fn (array $r): bool => ($r['code'] ?? '') === 'overlay_without_image'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('removed the image, so background.image and', $rows[0]['reason']);
        $this->assertSame('[data-pp-band="pp-a1b2c3d4"]{background:#ffffff;}', pp_udc_band_css($item));
    }

    /** The two contract facts the prompt budget was raised for reach the model (#1141/#1142, ai-context). */
    public function testThePromptCarriesTheRawWinsAndNewOffScrimClauses(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('a raw `background` cancels every `background` value there (image and scrim included)', $prompt);
        // The two new causes carry their lightness gate in the prompt too (/ship api-contract + design).
        $this->assertStringContainsString('a light or unreadable background replacing the image or beside a partial scrim', $prompt);
    }
}
