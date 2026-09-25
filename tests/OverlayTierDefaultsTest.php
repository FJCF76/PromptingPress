<?php
/**
 * tests/OverlayTierDefaultsTest.php — the overlay tier of role defaults (#1010, ruling D4 = B).
 *
 * THE DEFECT. A band that paints an image under a scrim is marked `data-pp-band-overlay` by
 * the engine, and the focus ring already re-lights off that marker (#986). The accent-ink
 * roles did not: `title-accent` over a dark scrim measured 1.05:1 in Chromium (#1010), and
 * 2.27:1 on this sprint's probe page.
 *
 * THE RULING (D4 = B). Every accent-ink role default (`title-accent`, `heading-accent`) on a
 * component that emits the overlay marker is re-lit to `--color-accent-on-overlay` when the
 * band carries the marker. Muted and inherited inks stay as they are. An authored value
 * always wins.
 *
 * THE MECHANISM, prototyped in Chromium before it was built (evidence-1127/prc/
 * overlay-tier-prototype*). A role declares `overlay_defaults` in its schema; the engine
 * emits them after the element defaults under
 * `:where([data-pp-component="X"])[data-pp-band-overlay] <role selector>`: the same (0,2,0)
 * as the element default, so it wins on source order; the band's authored blocks print
 * later at the same weight, so the author still wins. The engine names no component.
 */

use PHPUnit\Framework\TestCase;

final class OverlayTierDefaultsTest extends TestCase
{
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

    /** The components whose template renders the engine's overlay marker. */
    private function markerComponents(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/components/*/*.php') as $file) {
            if (str_contains((string) file_get_contents($file), "__pp_udc_overlay")) {
                $out[] = basename(dirname($file));
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    private function overlayTierRule(string $component, string $selector): string
    {
        return ':where([data-pp-component="' . $component . '"])[data-pp-band-overlay] ' . $selector
            . '{color:var(--color-accent-on-overlay);}';
    }

    public function testTheMarkerComponentsAreTheSevenTheRulingNames(): void
    {
        $this->assertSame(['cta', 'embed', 'faq', 'hero', 'logos', 'stats', 'table'], $this->markerComponents());
    }

    public function testEveryAccentInkRoleOnAMarkerComponentIsReLitOnOverlay(): void
    {
        $relit = 0;
        foreach ($this->markerComponents() as $component) {
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                // EVERY accent-ink default on a marker component (ruling D4 = B, faithfully
                // applied: stats `number` is one), with two stated exclusions:
                //   faq `question-open` sits on its item's own light fill, not on the scrim, so
                //     the near-white ink would vanish there;
                //   cta `button-secondary` is a set (ink, border, hover fill) that a partial
                //     re-light would break; it has its own issue.
                $ink = $definition['defaults']['typography']['color'] ?? null;
                if ($ink !== '@color-accent') {
                    continue;
                }
                if (in_array($component . '.' . $role, ['faq.question-open', 'cta.button-secondary'], true)) {
                    $this->assertArrayNotHasKey('overlay_defaults', $definition, "{$component}.{$role} is a stated exclusion");
                    continue;
                }
                $relit++;
                $css = pp_udc_component_defaults_css($component);
                $this->assertStringContainsString(
                    $this->overlayTierRule($component, (string) $definition['selector']),
                    $css,
                    "{$component}.{$role} is an accent ink and must re-light on an overlay band"
                );
            }
        }
        $this->assertSame(5, $relit, 'premise: hero title-accent, cta/faq/stats heading-accent, stats number');
    }

    /** The tier prints AFTER the element default it outranks: equal weight, source order. */
    public function testTheOverlayTierPrintsAfterTheElementDefault(): void
    {
        $css      = pp_udc_component_defaults_css('hero');
        $default  = strpos($css, '[data-pp-component="hero"] .hero__title-accent{');
        $tier     = strpos($css, ':where([data-pp-component="hero"])[data-pp-band-overlay] .hero__title-accent{');
        $this->assertNotFalse($default, 'premise: the element default is emitted');
        $this->assertNotFalse($tier);
        $this->assertGreaterThan($default, $tier);
    }

    /** Muted/inherited inks and non-accent roles are untouched (ruling D4 = B, not C). */
    public function testOnlyAccentInksCarryAnOverlayTier(): void
    {
        $seen = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                if (!isset($definition['overlay_defaults'])) {
                    continue;
                }
                $this->assertContains($component, $this->markerComponents(),
                    "{$component}.{$role}: an overlay tier on a component that never emits the marker is dead CSS");
                $this->assertSame('@color-accent', $definition['defaults']['typography']['color'] ?? null,
                    "{$component}.{$role}: only accent-ink defaults are re-lit");
                $this->assertSame(['typography' => ['color' => '@color-accent-on-overlay']], $definition['overlay_defaults']);
                $seen++;
            }
        }
        $this->assertSame(5, $seen, 'premise: the five accent inks declare the tier');
    }

    /** Components without an overlay tier emit exactly what they did before. */
    public function testAComponentWithNoOverlayTierEmitsNone(): void
    {
        foreach (['grid', 'section', 'logos', 'table', 'embed'] as $component) {
            $this->assertStringNotContainsString('[data-pp-band-overlay]', pp_udc_component_defaults_css($component), $component);
        }
    }

    /** Every declared overlay default is a value the authored grammar accepts on that role. */
    public function testEveryOverlayDefaultIsAValidAuthoredValue(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                if (!isset($definition['overlay_defaults'])) {
                    continue;
                }
                $checked++;
                $result = pp_udc_validate_map([$role => $definition['overlay_defaults']], $component);
                $this->assertNull($result,
                    "{$component}.{$role} overlay_defaults must validate: " . (is_wp_error($result) ? $result->get_error_message() : ''));
            }
        }
        $this->assertSame(5, $checked);
    }

    /** `overlay_defaults` is a map of groups the role permits; anything else is refused at CI. */
    public function testOverlayDefaultsMustBeAMapOfPermittedGroups(): void
    {
        $role = ['selector' => '.a', 'description' => 'd', 'groups' => ['typography'], 'defaults' => []];
        $this->assertSame([], pp_schema_definition_errors($role + ['overlay_defaults' => []], 'role', 'r'));
        $this->assertSame([], pp_schema_definition_errors($role + ['overlay_defaults' => ['typography' => ['color' => '#fff']]], 'role', 'r'));
        foreach ([[['typography' => []]], 'x'] as $bad) {
            $this->assertStringContainsString('`overlay_defaults` must be a MAP',
                implode(' | ', pp_schema_definition_errors($role + ['overlay_defaults' => $bad], 'role', 'r')));
        }
        $this->assertStringContainsString('`overlay_defaults` group `shadow` is not one of this role\'s `groups`',
            implode(' | ', pp_schema_definition_errors($role + ['overlay_defaults' => ['shadow' => ['x' => 'y']]], 'role', 'r')));
    }

    private function liveImage(int $id): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
    }

    /**
     * The marker says what the emitter paints: a deleted attachment paints no image and no
     * scrim, so the band is not marked and the tier cannot turn its accent near-white on the
     * light fill underneath (1.01:1).
     */
    public function testADeletedImageDoesNotMarkTheBand(): void
    {
        $this->liveImage(9001);
        $band = static fn (int $image): array => ['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['image' => $image, 'overlay' => 'rgba(0,0,0,0.55)']]]];
        $this->assertTrue(pp_udc_band_has_overlay($band(9001)), 'premise: a live image under a scrim is marked');
        $this->assertFalse(pp_udc_band_has_overlay($band(9002)), 'no attachment 9002: nothing paints, no marker');
        $this->assertFalse(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['image' => 9001]]]]), 'an image with no scrim is not an overlay');
        // No usable band id: the emitter writes no CSS for the band, so nothing paints.
        $idless = $band(9001);
        unset($idless['id']);
        $this->assertFalse(pp_udc_band_has_overlay($idless), 'no band id, no band CSS, no marker');
        $this->assertFalse(pp_udc_band_has_overlay(['id' => 'bad id"'] + $band(9001)), 'an invalid band id');
        $this->assertArrayNotHasKey('__pp_udc_overlay', pp_udc_promote_band_identity($idless, []));
        $this->assertSame('1', pp_udc_promote_band_identity($band(9001), [])['__pp_udc_overlay'] ?? null, 'premise: the valid band is marked');
    }

    /** An image and a scrim a preset supplies paint like the map's own, so they mark the band. */
    public function testAPresetSuppliedImageAndScrimMarkTheBand(): void
    {
        $this->liveImage(9001);
        $saved = pp_execute_action('save_preset', ['name' => 'photo-scrim', 'grain' => 'background',
            'udc' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.55)']]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        $this->assertTrue(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['_preset' => 'photo-scrim']]]]));
        $this->assertTrue(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['image' => 9001, '_preset' => 'photo-scrim']]]]), 'own image, preset scrim');
    }

    /** A role-grain preset supplies image and scrim at the preset's `background`; an empty scrim marks nothing. */
    public function testTheMarkerReadsRoleGrainPresetsAndRefusesEmptyShapes(): void
    {
        $this->liveImage(9001);
        $saved = pp_execute_action('save_preset', ['name' => 'photo-role', 'grain' => 'role',
            'udc' => ['background' => ['image' => 9001, 'overlay' => ['p' => 'rgba(0,0,0,0.55)']]]]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        $this->assertTrue(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'udc' => ['_band' => ['_preset' => 'photo-role']]]),
            'role-grain preset image and breakpoint-map scrim');
        $this->assertTrue(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['overlay' => ''], '_preset' => 'photo-role']]]),
            'an empty own scrim falls through to the preset\'s');

        $band = static fn ($overlay): array => ['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'udc' => ['_band' => ['background' => ['image' => 9001, 'overlay' => $overlay]]]];
        $this->assertFalse(pp_udc_band_has_overlay($band('')), 'an empty scrim paints nothing');
        $this->assertFalse(pp_udc_band_has_overlay($band([])), 'an empty map paints nothing');
        $this->assertTrue(pp_udc_band_has_overlay($band(['t' => '#000000'])), 'a scrim at one width still marks');
        $this->assertFalse(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'udc' => ['_band' => 'x']]), 'a non-map _band');
        $this->assertFalse(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4']), 'no udc at all');
        $this->assertFalse(pp_udc_band_has_overlay(['component' => 'hero', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_band' => ['background' => ['image' => 9001, '_preset' => 'no-such-preset']]]]), 'an unresolved preset supplies nothing');
    }

    /** The overlay tier reaches the front-end page CSS for a marker component, and only its roles. */
    public function testTheTierIsScopedToItsComponentAndRole(): void
    {
        $css = pp_udc_component_defaults_css('stats');
        $this->assertSame(2, substr_count($css, ':where([data-pp-component="stats"])[data-pp-band-overlay] '),
            'stats heading-accent and number, one rule each');
        $this->assertStringNotContainsString('[data-pp-component="hero"])[data-pp-band-overlay]', $css);
    }

    /**
     * A role with no `groups` list: the group cross-check is skipped rather than crashed on
     * (the missing `groups` is the required-keys check's to report, elsewhere).
     */
    public function testOverlayDefaultsOnARoleWithoutGroupsDoesNotCrashTheValidator(): void
    {
        $errors = pp_schema_definition_errors(['selector' => '.a', 'description' => 'd',
            'overlay_defaults' => ['typography' => ['color' => '#fff']]], 'role', 'r');
        $this->assertStringNotContainsString('`overlay_defaults` group', implode(' | ', $errors));
    }

    /**
     * The prompt names the re-lit roles from the schemas, and every hand-written doc that
     * lists them lists exactly those: adding `overlay_defaults` to a role breaks this test
     * until the docs say so.
     */
    public function testThePromptAndDocsNameExactlyTheReLitRoles(): void
    {
        $expected = [];
        foreach ($this->markerComponents() as $component) {
            foreach (pp_udc_component_roles($component) as $role => $definition) {
                if (isset($definition['overlay_defaults'])) {
                    $expected[] = $component . ' `' . $role . '`';
                }
            }
        }
        sort($expected);
        $summary = explode(', ', pp_udc_overlay_tier_summary());
        sort($summary);
        $this->assertSame($expected, $summary);
        $this->assertStringContainsString(': ' . pp_udc_overlay_tier_summary() . '.', pp_ai_system_prompt());

        $root = dirname(__DIR__);
        foreach (['AI_CONTEXT.md', 'ai-instructions/retheme.md', 'ai-instructions/style-component.md'] as $doc) {
            $text = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/' . $doc));
            // Anchored to the rule's own sentence, not to any mention in the file: the exclusion
            // is stated verbatim, and the re-lit roles are named in the 600 bytes before it (the
            // same sentence), so older text that merely mentions these roles cannot satisfy it.
            $exclusion = "faq `question-open` is not re-lit because it sits on its item's own light fill";
            $at = strpos($text, $exclusion);
            $this->assertNotFalse($at, "{$doc} states the question-open exclusion verbatim");
            $window = substr($text, max(0, (int) $at - 600), 600);
            $this->assertStringContainsString('#1010', substr($text, max(0, (int) $at - 900), 900), "{$doc}: the #1010 rule");
            foreach (['`title-accent`', '`heading-accent`', '`cta`', '`faq`', '`stats`', '`number`'] as $name) {
                $this->assertStringContainsString($name, $window, "{$doc} names {$name} in the rule's sentence");
            }
        }
        $this->assertSame(['cta `heading-accent`', 'faq `heading-accent`', 'hero `title-accent`', 'stats `heading-accent`', 'stats `number`'], $expected,
            'the docs above name these five; widen them with the schemas');
    }

    /**
     * `within` names the roles whose elements enclose a re-lit accent (the off-scrim finding
     * reads a surface only on those). Every name is a role of the same component, the shape
     * is refused otherwise, and each re-lit role declares it.
     */
    public function testEveryReLitRoleDeclaresTheRolesThatEncloseIt(): void
    {
        $declared = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $roles     = pp_udc_component_roles($component);
            foreach ($roles as $role => $definition) {
                if (!isset($definition['within'])) {
                    continue;
                }
                foreach ($definition['within'] as $outer) {
                    $this->assertArrayHasKey($outer, $roles, "{$component}.{$role} within names a role of {$component}");
                    $this->assertNotSame($role, $outer);
                }
                $declared[$component . '.' . $role] = $definition['within'];
            }
        }
        ksort($declared);
        $this->assertSame([
            'cta.heading-accent'   => ['inner', 'text', 'heading'],
            'faq.heading-accent'   => ['heading'],
            'hero.title-accent'    => ['inner', 'content', 'title'],
            'stats.heading-accent' => ['heading'],
            'stats.number'         => ['list', 'item'],
        ], $declared, 'the enclosing roles, read off each template\'s markup');

        $role = ['selector' => '.a', 'description' => 'd', 'groups' => ['typography'], 'defaults' => []];
        $this->assertSame([], pp_schema_definition_errors($role + ['within' => ['x', 'y']], 'role', 'r'));
        foreach ([['within' => 'x'], ['within' => ['a' => 'x']], ['within' => ['']], ['within' => [3]]] as $bad) {
            $this->assertStringContainsString('`within` must be a LIST of role names',
                implode(' | ', pp_schema_definition_errors($role + $bad, 'role', 'r')), json_encode($bad));
        }
    }

    /** An AUTHORED value still wins: the band's own block prints after the overlay tier. */
    public function testAnAuthoredAccentInkStillWinsOverTheTier(): void
    {
        $composition = [[
            'component' => 'hero',
            'id'        => 'pp-a1b2c3d4',
            'udc'       => ['title-accent' => ['typography' => ['color' => '#8fd0ff']]],
            'props'     => ['title' => 'T', 'title_accent' => 'A', 'layout' => 'centered'],
        ]];
        $css    = pp_udc_page_css($composition);
        $tier   = strpos($css, '[data-pp-band-overlay] .hero__title-accent{');
        $author = strpos($css, '[data-pp-band="pp-a1b2c3d4"] .hero__title-accent{');
        $this->assertNotFalse($tier, 'premise: the page carries the overlay tier');
        $this->assertNotFalse($author);
        $this->assertGreaterThan($tier, $author, 'the authored block prints later at the same weight');
    }

    /**
     * #1142 item 2: `overlay_defaults` and `within` are validated at the definition gate. A state key would print at
     * (0,3,0) and outrank an author's resting value (contradicting "your value wins"); a non-parameter key was dropped
     * silently at render; a `within` name that is no role of the component names a surface that never exists.
     */
    public function testTheDefinitionGateValidatesOverlayDefaultsAndWithin(): void
    {
        $role = ['selector' => '.x', 'groups' => ['typography'], 'overlay_defaults' => ['typography' => ['color' => '@color-accent-on-overlay']]];
        $this->assertSame([], pp_schema_definition_errors($role, 'role', 'c role r'), 'premise: a valid tier');
        $bad = $role;
        $bad['overlay_defaults']['typography'][':hover'] = ['color' => '#fff'];
        $this->assertContains('c role r: `overlay_defaults` group `typography` must not hold a state (`:hover`): the tier is a resting default.', pp_schema_definition_errors($bad, 'role', 'c role r'));
        $bad = $role;
        $bad['overlay_defaults']['typography']['nope'] = '1';
        $this->assertContains('c role r: `overlay_defaults` group `typography` has no parameter `nope`.', pp_schema_definition_errors($bad, 'role', 'c role r'));
        $within = $role + ['within' => ['text', 'ghost']];
        $this->assertSame([], pp_schema_definition_errors($within, 'role', 'c role r'), 'without the sibling roster the name cannot be checked');
        $this->assertContains('c role r: `within` names `ghost`, which is not a role of this component.',
            pp_schema_definition_errors($within, 'role', 'c role r', ['r' => true, 'text' => true]));
        $this->assertSame([], pp_schema_definition_errors($role + ['within' => ['text']], 'role', 'c role r', ['r' => true, 'text' => true]));
    }

    /** `overlay_defaults` VALUES are checked too: a single-line string, or a breakpoint map of them (PR-2 review, security). */
    public function testTheDefinitionGateChecksOverlayDefaultValues(): void
    {
        $role = static fn ($value): array => ['selector' => '.x', 'groups' => ['typography'], 'overlay_defaults' => ['typography' => ['color' => $value]]];
        $this->assertSame([], pp_schema_definition_errors($role('@color-accent-on-overlay'), 'role', 'c role r'));
        $this->assertSame([], pp_schema_definition_errors($role(['d' => '#fff', 'p' => '#eee']), 'role', 'c role r'), 'a breakpoint map');
        foreach ([['deep' => ['x' => 1]], "two\nlines", 7, '', [':hover' => '#fff'], ['d' => '#000', 'hover' => '#fff']] as $bad) {
            $this->assertContains('c role r: `overlay_defaults` group `typography` parameter `color` must be a single-line string, or a breakpoint map of them.',
                pp_schema_definition_errors($role($bad), 'role', 'c role r'), var_export($bad, true));
        }
    }

    /** A GROUP that is not a map of parameters is refused, so no value reaches `wp pp schema` unchecked (PR-2 red team, informational; needs a schema write). */
    public function testAnOverlayDefaultsGroupMustBeAMapOfParameters(): void
    {
        foreach (["x\ny\u{202E}", 7, ['#fff']] as $bad) {
            $this->assertContains('c role r: `overlay_defaults` group `typography` must be a MAP of parameters.',
                pp_schema_definition_errors(['selector' => '.x', 'groups' => ['typography'], 'overlay_defaults' => ['typography' => $bad]], 'role', 'c role r'),
                var_export($bad, true));
        }
    }
}
