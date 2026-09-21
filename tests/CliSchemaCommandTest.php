<?php
/**
 * tests/CliSchemaCommandTest.php — `wp pp schema <component>` (#688).
 *
 * The schema is the most-consulted contract in the system and, until #688, the only one
 * with no sanctioned read surface: an SSH-only or chat-context agent had to open
 * `components/<name>/schema.json` off disk to learn a slot name. `wp pp schema` answers
 * that question over the CLI.
 *
 * The RISK the command carries is not that it under-reports — it is that it becomes a
 * SECOND VIEW OF THE SCHEMA. A projection that hand-picks keys drifts the moment a
 * schema key is added, and a surface with its own idea of the contract is the #223
 * root-cause class. So the load-bearing half of this file is not the happy path; it is
 * the drift guards:
 *
 *     schema.json declaration        report entry
 *     ────────────────────────       ────────────────────────────────────
 *     { "type": "color", … }   ───►  { "slot": "--x", "type": "color", …,
 *                                      "applies_when_rendered": "…" }
 *                                     └──────────┬──────────┘
 *                                       strip the promoted identity key
 *                                       and the ONE derived field, and
 *                                       what is left must be === the
 *                                       declaration. Not a subset. Equal.
 *
 * Measured over all twelve shipped schemas, both directions (nothing added, nothing
 * dropped, order preserved), so a future refactor that starts curating keys fails here
 * instead of shipping a quietly incomplete contract.
 *
 * The other pins worth naming:
 *
 *   - `applies_when_rendered` must be the CATALOG's words. It is checked against
 *     pp_ai_definition_suffix() — the runtime catalog's own emitter — not against a
 *     string this file re-derives. Two phrasings of one condition is the defect.
 *   - One unrenderable clause voids the WHOLE rendered condition. Joining the
 *     survivors of an ANDed list emits a SHORTER condition that reads as complete.
 *   - The #685 page-addressing hook must not touch this command's positional. Pinned
 *     directly against the hook's own predicate, so widening the hook later cannot
 *     silently start swallowing component names.
 *
 * Section 14.1 (authoring path) does not apply: #688 adds no prop, no schema field and
 * no validation rule. It is a read surface over declarations that already exist, and
 * every fixture below reaches it through the canonical loader from a real on-disk theme
 * root — never by injecting a hand-built schema array past the loader, which would
 * encode a second schema model in the test itself.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/DiagnosticReachTest/ReadinessFindingsTest) ──
if (!class_exists('WpCliExitException')) {
    class WpCliExitException extends \RuntimeException {}
}
if (!class_exists('WpCliHaltException')) {
    class WpCliHaltException extends \RuntimeException {}
}
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static array $lines = [];
        public static array $warnings = [];
        public static array $successes = [];
        public static function error($message, $exit = true): void { throw new WpCliExitException((string) $message); }
        public static function add_command($name, $handler, $args = []): void {}
        // #685 registers a before_run_command hook at load time; the stub only
        // needs to accept it — the hook's decision is pinned directly instead.
        public static function add_hook($when, $callback): void {}
        public static function line($message = ''): void { self::$lines[] = (string) $message; }
        public static function warning($message = ''): void { self::$warnings[] = (string) $message; }
        public static function success($message = ''): void { self::$successes[] = (string) $message; }
        public static function debug($message = '', $group = false): void {}
        public static function log($message = ''): void {}
        public static function halt($code = 0): void { throw new WpCliHaltException((string) $code, (int) $code); }
    }
}

require_once dirname(__DIR__) . '/lib/cli.php';

class CliSchemaCommandTest extends TestCase
{
    /** Fixture theme roots created by a test, removed in tearDown. */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];

        // Clean on BOTH edges (the ApplyTest/SchemaTruthfulnessTest convention). tearDown
        // covers the export edge; this covers the inherit edge, because other classes
        // repoint the theme root and not all of them restore it. Without this, a leaked
        // fixture root would have shippedComponents() reading a foreign registry while
        // shippedSchema() reads the real repo off disk.
        unset($GLOBALS['_pp_test_template_dir']);
        $GLOBALS['_pp_registered_components_invalidate'] = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            $this->recursiveDelete($root);
        }
        $this->fixtureRoots = [];
        unset($GLOBALS['_pp_test_template_dir']);
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds a throwaway theme root holding exactly the components described, then
     * repoints get_template_directory() at it and invalidates the registry cache.
     *
     * Every fixture goes through the SAME loader the production path uses
     * (pp_get_registered_components scans `<root>/components/<name>/`, requires a
     * `<name>.php` sibling, and json_decodes `schema.json`), so nothing here can encode
     * a schema shape the loader would not actually produce.
     *
     * @param array<string,string> $schemas  component name => raw schema.json contents.
     */
    private function useFixtureTheme(array $schemas): string
    {
        $root = sys_get_temp_dir() . '/pp-schema-fixture-' . getmypid() . '-' . count($this->fixtureRoots);
        $this->recursiveDelete($root);
        foreach ($schemas as $name => $raw) {
            $dir = $root . '/components/' . $name;
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/' . $name . '.php', "<?php // fixture component\n");
            file_put_contents($dir . '/schema.json', $raw);
        }
        $this->fixtureRoots[] = $root;

        $GLOBALS['_pp_test_template_dir']                 = $root;
        $GLOBALS['_pp_registered_components_invalidate']   = true;

        return $root;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** The raw decoded schema for one shipped component, read straight off disk. */
    private function shippedSchema(string $component): array
    {
        $path = dirname(__DIR__) . '/components/' . $component . '/schema.json';
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "{$component}/schema.json decodes to an array");
        return $decoded;
    }

    /** Every shipped component name, from the canonical loader. */
    private function shippedComponents(): array
    {
        return array_keys(pp_get_registered_components());
    }

    // ── Index: bare `wp pp schema` ───────────────────────────────────────────

    public function testIndexListsEveryRegisteredComponent(): void
    {
        $index = pp_component_schema_index();

        $this->assertSame(
            $this->shippedComponents(),
            array_column($index, 'component'),
            'the index is the loader registry, in loader order — not a hand-kept list'
        );
        $this->assertCount(12, $index, 'the twelve shipped components');
    }

    public function testIndexMarksTemplateOwnedChromeAsNotComposable(): void
    {
        $composable = [];
        foreach (pp_component_schema_index() as $entry) {
            $composable[$entry['component']] = $entry['composable'];
        }

        // Registered ⊋ composable. Derived from pp_template_owned_components() rather
        // than a literal ['nav','footer'] here, so adding a third chrome component
        // cannot leave this surface advertising it as composable (the #223 defect).
        foreach ($this->shippedComponents() as $name) {
            $this->assertSame(
                !pp_is_template_owned_component($name),
                $composable[$name],
                "{$name} composable flag tracks the template-owned list"
            );
        }
        $this->assertFalse($composable['nav']);
        $this->assertFalse($composable['footer']);
        $this->assertTrue($composable['hero']);
        $this->assertCount(2, array_filter($composable, static fn ($c) => $c === false));
    }

    public function testIndexEntriesCarryOnlyIdentityAndComposability(): void
    {
        $index = pp_component_schema_index();
        $this->assertCount(12, $index, 'discovery is not vacuous');

        foreach ($index as $entry) {
            $this->assertSame(['component', 'composable'], array_keys($entry));
        }
    }

    // ── Report: shipped components ───────────────────────────────────────────

    public function testReportCarriesIdentityDescriptionAndComposability(): void
    {
        $report = pp_component_schema_report('hero');
        $this->assertIsArray($report);

        $schema = $this->shippedSchema('hero');
        $this->assertSame('hero', $report['component']);
        $this->assertSame($schema['description'], $report['description']);
        $this->assertTrue($report['composable']);
    }

    public function testReportSurfacesEveryDeclaredPropSlotAndRecipe(): void
    {
        $this->assertCount(12, $this->shippedComponents(), 'discovery is not vacuous');

        foreach ($this->shippedComponents() as $name) {
            $schema = $this->shippedSchema($name);
            $report = pp_component_schema_report($name);
            $this->assertIsArray($report, "{$name} reports");

            $this->assertSame(
                array_keys($schema['props'] ?? []),
                array_column($report['props'], 'name'),
                "{$name}: every declared prop, in declaration order"
            );
            $this->assertSame(
                array_keys($schema['styling']['style_slots'] ?? []),
                array_column($report['style_slots'], 'slot'),
                "{$name}: every declared style slot, in declaration order"
            );
            $this->assertSame(
                array_keys($schema['styling']['recipes'] ?? []),
                array_column($report['recipes'], 'name'),
                "{$name}: every declared recipe, in declaration order"
            );
        }
    }

    public function testReportEmitsContentRequirementOnlyWhenDeclared(): void
    {
        // `section` is the one component that declares it (#488): every prop optional,
        // but a band still needs SOME content.
        $section = pp_component_schema_report('section');
        $this->assertSame(
            $this->shippedSchema('section')['content_requirement'],
            $section['content_requirement']
        );

        // Absent, not null — so "declared empty" and "not declared" stay distinguishable.
        $hero = pp_component_schema_report('hero');
        $this->assertArrayNotHasKey('content_requirement', $hero);
    }

    public function testTemplateOwnedChromeIsReadableButMarkedNotComposable(): void
    {
        // `nav` is registered and renderable, so its props are legitimately readable —
        // an agent needs to see them to understand what the header offers. What it must
        // NOT read as is a component it may place in a composition (#223).
        $report = pp_component_schema_report('nav');
        $this->assertIsArray($report);
        $this->assertFalse($report['composable']);
        $this->assertNotEmpty($report['props'], 'nav declares props');

        // Empty because nav declares NO `styling.style_slots` and NO `styling.recipes`
        // key at all — not because its styling surface is empty. Its actual surface is
        // its UDC ROLES, reported through the roles projection rather than the
        // style-slot one. Pinned with the reason attached so the empty list is never
        // read as "nav has no styling contract".
        $this->assertSame([], $report['style_slots']);
        $this->assertSame([], $report['recipes']);
        $this->assertArrayNotHasKey(
            'style_slots',
            $this->shippedSchema('nav')['styling'],
            'the emptiness comes from the schema, not from the projection'
        );
        $this->assertNotEmpty($this->shippedSchema('nav')['roles'], 'nav\'s styling surface is its roles');
        $this->assertNotEmpty($report['roles'] ?? [], 'and the report must carry them');
    }


    /**
     * A ROLE'S DECLARED OBLIGATIONS REACH THE CLI REPORT (#1087).
     *
     * The mechanism is two-channel and this is the half that is easy to forget. The runtime
     * prompt composes obligations for the chat AI, which has no way to fetch a schema. An
     * agent WITH filesystem or CLI access is told to run `wp pp schema <component>` instead
     * of carrying a copy of role detail — so if the command does not emit them, "fetch it
     * rather than duplicate it" points at a surface that does not have it, and the
     * duplication it forbids becomes the only way to know.
     *
     * Emitted only when a role declares any, matching how every other optional block in this
     * report behaves: absence must not read as "declared empty".
     */
    public function testRoleObligationsAreReportedWhenDeclared(): void
    {
        $report = \pp_component_schema_report('faq');
        $this->assertIsArray($report);

        $byRole = [];
        foreach ($report['roles'] as $entry) {
            $byRole[$entry['role']] = $entry;
        }

        $this->assertArrayHasKey('question', $byRole);
        $this->assertArrayHasKey(
            'obligations',
            $byRole['question'],
            'faq.question declares an obligation with question-open; the report must carry it'
        );
        $obligation = $byRole['question']['obligations'][0];
        $this->assertSame('outranked_by_default', $obligation['kind']);
        $this->assertSame('question-open', $obligation['with']);
        $this->assertNotSame('', trim($obligation['why']), 'the instruction must come with it');

        // And the absence half: a role with none must not carry an empty key.
        $this->assertArrayHasKey('heading', $byRole);
        $this->assertArrayNotHasKey(
            'obligations',
            $byRole['heading'],
            'a role that declares none must omit the key rather than report an empty list'
        );

        // Every declared obligation in the registry reaches this report, derived in both
        // directions so a component added later cannot be silently skipped.
        $reported = 0;
        foreach (array_keys(\pp_get_registered_components()) as $component) {
            $componentReport = \pp_component_schema_report($component);
            if (!is_array($componentReport) || !isset($componentReport['roles'])) {
                continue;
            }
            foreach ($componentReport['roles'] as $entry) {
                $declared = \pp_udc_component_roles($component)[$entry['role']]['obligations'] ?? [];
                $this->assertSame(
                    $declared === [] ? null : array_values($declared),
                    $entry['obligations'] ?? null,
                    "{$component}.{$entry['role']}'s reported obligations must equal its declaration"
                );
                $reported += count($entry['obligations'] ?? []);
            }
        }
        $this->assertGreaterThan(12, $reported, 'the obligations stopped reaching the CLI report');
    }

    // ── applies_when: one vocabulary, all-or-nothing ─────────────────────────

    public function testAppliesWhenRenderedUsesTheRuntimeCatalogVocabulary(): void
    {
        // THE mechanical guard against two phrasings of one condition. Checked against
        // pp_ai_definition_suffix() — the emitter that writes the catalog line the chat
        // AI reads — never against a phrase re-derived here. Applied to EVERY declared
        // condition with no exemptions: a declaration carrying both `applies_when` and
        // `conditionality_note` must render the SAME conjunction the catalog renders, or
        // the CLI would report a strictly looser condition that reads as complete.
        $checked       = 0;
        $clauseOnly    = 0;
        $clausePlusNote = 0;
        $noteOnly      = 0;

        foreach ($this->shippedComponents() as $name) {
            $report = pp_component_schema_report($name);

            foreach ([['props', 'name'], ['style_slots', 'slot']] as [$bucket, $key]) {
                foreach ($report[$bucket] as $entry) {
                    if ($entry['applies_when_rendered'] === null) {
                        continue;
                    }
                    $declaration = $entry;
                    unset($declaration[$key], $declaration['applies_when_rendered']);

                    // ENDS WITH, not contains. Containment cannot prove the conjunction:
                    // a renderer that dropped the note half would still be a substring of
                    // the catalog's fuller phrase and this pin would stay green on the
                    // exact regression it exists to catch. pp_ai_definition_suffix()
                    // appends the applies-when bit LAST (after any `role:` bits), so the
                    // suffix ending IS the whole condition, and equality there is exact.
                    $this->assertStringEndsWith(
                        'applies when ' . $entry['applies_when_rendered'],
                        pp_ai_definition_suffix($declaration),
                        "{$name}.{$entry[$key]} renders the WHOLE condition, in the catalog's words"
                    );
                    $checked++;

                    $hasNote   = isset($declaration['conditionality_note']);
                    $hasClause = !empty($declaration['applies_when']);
                    if ($hasNote && $hasClause) {
                        $clausePlusNote++;
                    } elseif ($hasNote) {
                        $noteOnly++;
                    } elseif ($hasClause) {
                        $clauseOnly++;
                    }
                }
            }
        }

        // THE POPULATION CHANGED SHAPE AT #1101, and the counters below are rewritten to
        // say so rather than lowered. This used to measure 20+ conditions of which 10+
        // conjoined a prose note, because grid's slot map declared 20 conditional slots —
        // the theme's last. It retired with grid's v2 rebuild, and what is left is the
        // PROP surface: footer's five notes and one clause, nav's two notes, logos' one.
        //
        // So the floors are stated as the three COMPOSITIONS rather than as a total, which
        // is what the test was always about. Two of the three still ship and are asserted
        // non-empty. The third — a declaration carrying BOTH clauses and a note, the branch
        // this pin exists for, because a renderer dropping the note half is invisible
        // otherwise — has no declaring surface left anywhere in the theme. Asserted as
        // EXACTLY ZERO, not silently unmeasured: a shipped schema declaring both again must
        // fail here, because on that day this pin starts covering its own hardest case and
        // the reader needs to know the branch went from unreachable back to live.
        // AppliesWhenTest::testTheClauseAndNoteConjunctionStillRendersThoughNothingDeclaresBoth
        // carries the same census and the direct renderer pin that replaced it.
        $this->assertGreaterThan(0, $clauseOnly, 'no clause-only condition ships any more');
        $this->assertGreaterThan(0, $noteOnly, 'no prose-only condition ships any more');
        $this->assertSame(
            0,
            $clausePlusNote,
            'A shipped declaration conjoins clauses AND a note again. That branch has been '
            . 'unreachable since #1101; it is live now, so this test covers its hardest case '
            . 'again — say so here rather than letting the count drift.'
        );
        $this->assertSame(
            $clauseOnly + $noteOnly,
            $checked,
            'every rendered condition falls in one of the three compositions counted above'
        );
        $this->assertGreaterThan(5, $checked, 'the shipped schemas still declare conditions to check');
    }

    public function testAProseOnlyConditionStillRendersTheCatalogPhrase(): void
    {
        // `conditionality_note` carries the three condition classes the clause grammar
        // deliberately cannot express. A declaration with only a note still HAS a
        // condition, so `applies_when_rendered` must state it rather than reporting null
        // and implying the slot always paints.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'                => 'color',
                    'default'             => 'red',
                    'description'         => 'bar',
                    'conditionality_note' => 'the band is dark.',
                ],
            ]],
        ])]);

        $slot = pp_component_schema_report('widget')['style_slots'][0];

        // Trailing period trimmed, exactly as pp_ai_definition_suffix() trims it.
        $this->assertSame('the band is dark', $slot['applies_when_rendered']);
    }

    public function testAppliesWhenRenderedIsNullWhenNothingIsDeclared(): void
    {
        $report = pp_component_schema_report('hero');

        $title = null;
        foreach ($report['props'] as $prop) {
            if ($prop['name'] === 'title') {
                $title = $prop;
            }
        }
        $this->assertNotNull($title);
        $this->assertArrayNotHasKey('applies_when', $title, 'hero.title declares no condition');
        $this->assertNull($title['applies_when_rendered']);
    }

    public function testConditionalityNoteShipsVerbatimAndIsNotFoldedIntoTheClause(): void
    {
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'                => 'color',
                    'default'             => 'red',
                    'description'         => 'bar',
                    'applies_when'        => [['prop' => 'mode', 'equals' => 'split']],
                    'conditionality_note' => 'the band is dark',
                ],
            ]],
        ])]);

        $slot = pp_component_schema_report('widget')['style_slots'][0];

        $this->assertSame('the band is dark', $slot['conditionality_note'], 'verbatim');
        $this->assertSame(
            [['prop' => 'mode', 'equals' => 'split']],
            $slot['applies_when'],
            'the machine-readable clauses ship verbatim too'
        );
        // Both halves, ANDed, clauses first — the catalog's composition. Rendering the
        // clause alone would advertise a slot that paints whenever layout is split.
        $this->assertSame(
            'mode = "split" AND the band is dark',
            $slot['applies_when_rendered']
        );
    }

    public function testOneUnrenderableClauseVoidsTheWholeRenderedCondition(): void
    {
        // ANDed clauses. Rendering only the survivor would emit `mode = "split"` — a
        // strictly LOOSER condition that reads as complete, telling an agent the slot
        // paints in circumstances where it does not.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'         => 'color',
                    'default'      => 'red',
                    'description'  => 'bar',
                    'applies_when' => [
                        ['prop' => 'mode', 'equals' => 'split'],
                        ['prop' => 'mode', 'slot' => '--x', 'equals' => 'no'], // two subjects: rejected
                    ],
                ],
            ]],
        ])]);

        $slot = pp_component_schema_report('widget')['style_slots'][0];

        $this->assertNull($slot['applies_when_rendered']);
        // Nothing is hidden: the raw declaration still ships, so the operator can see
        // both clauses and judge for themselves.
        $this->assertCount(2, $slot['applies_when']);
    }

    public function testAnEntirelyInvalidConditionRendersNullRatherThanEmptyString(): void
    {
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'         => 'color',
                    'default'      => 'red',
                    'description'  => 'bar',
                    'applies_when' => [['nonsense' => true]],
                ],
            ]],
        ])]);

        $this->assertNull(pp_component_schema_report('widget')['style_slots'][0]['applies_when_rendered']);
    }

    /**
     * @dataProvider unreadableAppliesWhenShapes
     */
    public function testADeclaredButUnreadableAppliesWhenVoidsTheNoteToo($appliesWhen): void
    {
        // The regression this guards: with `applies_when` present but not an array, a
        // renderer that only checks is_array() falls through and publishes the NOTE as
        // the complete condition — "the band is dark" — when the real condition also
        // needs a mode. That is strictly looser and reads as complete, which is worse
        // than saying nothing. A declared-but-unreadable condition renders nothing.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'                => 'color',
                    'default'             => 'red',
                    'description'         => 'bar',
                    'applies_when'        => $appliesWhen,
                    'conditionality_note' => 'the band is dark',
                ],
            ]],
        ])]);

        $slot = pp_component_schema_report('widget')['style_slots'][0];

        $this->assertNull($slot['applies_when_rendered']);
        // The declaration is still fully visible, so nothing is hidden by the refusal.
        $this->assertSame('the band is dark', $slot['conditionality_note']);
        $this->assertSame($appliesWhen, $slot['applies_when']);
    }

    public static function unreadableAppliesWhenShapes(): array
    {
        return [
            'string' => ['mode = split'],
            'bool'   => [true],
            'number' => [5],
        ];
    }

    public function testAPunctuationOnlyNoteIsNotAppendedAsADanglingConjunct(): void
    {
        // rtrim(trim($note), '.') on "." yields '' — appending it would emit
        // `mode = "split" AND ` (a truncated condition) or a bare '' (a third state the
        // contract does not define). Nothing declared, nothing rendered.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'                => 'color',
                    'default'             => 'red',
                    'description'         => 'bar',
                    'applies_when'        => [['prop' => 'mode', 'equals' => 'split']],
                    'conditionality_note' => '.',
                ],
                '--widget-baz' => [
                    'type'                => 'color',
                    'default'             => 'red',
                    'description'         => 'baz',
                    'conditionality_note' => '   ',
                ],
            ]],
        ])]);

        $slots = pp_component_schema_report('widget')['style_slots'];

        $this->assertSame('mode = "split"', $slots[0]['applies_when_rendered']);
        $this->assertNull($slots[1]['applies_when_rendered']);
    }

    public function testADeclarationThatCollidesWithAPromotedFieldDisclosesTheShadowing(): void
    {
        // `props: [{"name": "title", …}]` — the list-shaped hand-edit — arrives as key
        // "0" carrying a declared `name`. The promoted key has to win (it is what
        // addresses the entry), but the displaced declaration must not vanish silently
        // from an agent-facing contract.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => [['name' => 'title', 'type' => 'string']],
            'styling'   => ['style_slots' => [
                '--widget-bar' => [
                    'type'                  => 'color',
                    'default'               => 'red',
                    'description'           => 'bar',
                    'applies_when_rendered' => 'MINE',
                ],
            ]],
        ])]);

        $report = pp_component_schema_report('widget');

        $this->assertSame('0', $report['props'][0]['name'], 'the promoted key addresses the entry');
        $this->assertSame(['name'], $report['props'][0]['shadowed_keys']);
        $this->assertSame(['applies_when_rendered'], $report['style_slots'][0]['shadowed_keys']);

        // Its ABSENCE on well-formed entries is already pinned, and pinned hard: the
        // verbatim drift tests strip only the promoted key and the derived field before
        // asserting exact equality with the declaration, so a stray `shadowed_keys` on any
        // of the shipped twelve fails there.
    }

    public function testAnEmptyAppliesWhenArrayRendersNull(): void
    {
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string', 'applies_when' => []]],
            'styling'   => [],
        ])]);

        $this->assertNull(pp_component_schema_report('widget')['props'][0]['applies_when_rendered']);
    }

    // ── Unknown names ────────────────────────────────────────────────────────

    public function testUnknownComponentReturnsAnErrorNamingTheAvailableSet(): void
    {
        $error = pp_component_schema_report('carousel');

        $this->assertInstanceOf('WP_Error', $error);
        $this->assertSame('unknown_component', $error->get_error_code());
        $message = $error->get_error_message();
        $this->assertStringContainsString('carousel', $message);
        foreach ($this->shippedComponents() as $name) {
            $this->assertStringContainsString($name, $message, "the refusal names {$name}");
        }
    }

    /**
     * @dataProvider nonCanonicalNames
     */
    public function testComponentNamesAreNeverCanonicalised(string $input): void
    {
        // No name is canonicalised anywhere in the composition pipeline (#603-#606).
        // A schema reader that quietly accepted `Hero` would be the only surface in the
        // system that does, and the refusal already names the exact spelling to use.
        $this->assertInstanceOf('WP_Error', pp_component_schema_report($input));
    }

    public static function nonCanonicalNames(): array
    {
        return [
            'title case'    => ['Hero'],
            'upper case'    => ['HERO'],
            'leading space' => [' hero'],
            'empty string'  => [''],
        ];
    }

    // ── Drift guards: the report IS the schema's data ────────────────────────

    public function testEveryPropEntryIsTheDeclaredDefinitionVerbatim(): void
    {
        $seen = 0;

        foreach ($this->shippedComponents() as $name) {
            $declared = $this->shippedSchema($name)['props'] ?? [];
            foreach (pp_component_schema_report($name)['props'] as $entry) {
                $stripped = $entry;
                unset($stripped['name'], $stripped['applies_when_rendered']);

                // === , not a subset check: nothing added, nothing dropped, order kept.
                $this->assertSame(
                    $declared[$entry['name']],
                    $stripped,
                    "{$name}.{$entry['name']} prop is the declaration verbatim"
                );
                $seen++;
            }
        }

        // 98 shipped props today. The floor was 100 until #1101 took grid's six styling
        // props (`theme`, `title_align`, `card_emphasis`, `image_treatment`,
        // `items[].text_role`, `items[].style`) out of the registry with its v2 rebuild.
        // It stays a FLOOR rather than an exact count so adding a prop does not fail this,
        // while a walk that stops discovering props still does — and unlike the slot walk
        // below, this surface is in no danger of emptying: every component declares props
        // whatever styling system it is on.
        $this->assertGreaterThan(90, $seen, 'discovery is not vacuous');
    }

    /**
     * THE SHIPPED SLOT AND RECIPE WALKS RETIRED AT #1101, EXACTLY AS THEIR OWN NOTES SAID
     * THEY WOULD, and this is the replacement the notes prescribed.
     *
     * (1) WHAT THEY PROVED. testEverySlotEntryIsTheDeclaredDefinitionVerbatim and
     *     testEveryRecipeEntryIsTheDeclaredDefinitionVerbatim walked every shipped schema
     *     and asserted that stripping the promoted identity key and the one derived field
     *     off a report entry leaves the declaration EXACTLY — not a subset, same keys,
     *     same order. That is the drift guard this whole file is founded on: a projection
     *     that starts curating keys becomes a second view of the schema.
     *
     * (2) WHY THE SUBJECT IS GONE. The slot floor was lowered deliberately at each v2
     *     rebuild with the measured number in hand — 150 -> 130 (#1023, section's 47) ->
     *     90 (#1026, cta's 40) -> 63 (#1066, table's six and embed's eight, after #1046
     *     took faq's twenty-one) -> 38, at which point grid's slot map WAS the theme's
     *     entire slot surface. Its own note set the terms for today: "when grid rebuilds,
     *     the slot walk has no subject and THIS assertion retires — it does not get
     *     lowered to zero." grid rebuilt. Recipes went with it for the reason that note
     *     also gave: a recipe IS a bundle of style slots, so a component declaring no
     *     slots can declare no recipe. Both shipped surfaces are now empty.
     *
     * (3) WHERE THE CLAIM WENT. Onto a FIXTURE theme, which is this file's own idiom for
     *     exactly this situation (see testAnUnregisteredDeclarationKeyStillReachesTheReport
     *     below, which has always needed a fixture because the shipped twelve declare only
     *     closed-registry keys). The fixture goes through the canonical loader from a real
     *     on-disk theme root, so nothing here encodes a schema shape the loader would not
     *     produce — the constraint stated in this file's header. The projection is
     *     therefore still asserted byte-for-byte; what is no longer asserted is that it
     *     holds across a POPULATION of real declarations, because there is no population.
     *
     * (4) THE PIN. The emptiness is asserted first, over the shipped twelve, so this
     *     retirement cannot silently reverse: the day a shipped component declares a slot
     *     or a recipe again, this fails and the population walks come back.
     */
    public function testNoShippedComponentDeclaresSlotsOrRecipesAndTheProjectionIsPinnedOnAFixture(): void
    {
        $withSlots   = [];
        $withRecipes = [];
        $components  = 0;
        $props       = 0;

        foreach ($this->shippedComponents() as $name) {
            $components++;
            $schema = $this->shippedSchema($name);
            $props += count($schema['props'] ?? []);
            if (!empty($schema['styling']['style_slots'])) {
                $withSlots[] = $name;
            }
            if (!empty($schema['styling']['recipes'])) {
                $withRecipes[] = $name;
            }
            // The REPORT agrees with the declaration about the emptiness, which is the
            // half a census of schema.json alone would miss: a projection inventing slot
            // entries out of the role surface would pass a file-level census and fail here.
            $report = pp_component_schema_report($name);
            $this->assertSame([], $report['style_slots'], "{$name} reports no style slots");
            $this->assertSame([], $report['recipes'], "{$name} reports no recipes");
        }

        // NOT VACUOUS: the walk has to have visited the shipped twelve and found real
        // declarations in them, or an empty registry would satisfy everything above.
        $this->assertSame(12, $components, 'the twelve shipped components');
        $this->assertGreaterThan(90, $props, 'the walk is reading real schemas');
        $this->assertSame([], $withSlots, 'a shipped component declares style slots again — restore the population walk');
        $this->assertSame([], $withRecipes, 'a shipped component declares recipes again — restore the population walk');

        // THE PROJECTION ITSELF, on a loader-built fixture. Two slots (one unconditional,
        // one conditional, so the derived field is exercised on both of its branches) and
        // two recipes, all declaring the full shape a real declaration carries.
        $slots = [
            '--widget-bg' => [
                'type'        => 'gradient',
                'default'     => 'transparent',
                'description' => 'Band background.',
            ],
            '--widget-heading-color' => [
                'type'         => 'color',
                'default'      => 'var(--color-text)',
                'description'  => 'Heading ink.',
                'applies_when' => [['prop' => 'title', 'present' => true]],
            ],
        ];
        $recipes = [
            'calm' => ['description' => 'Quiet band.', 'slots' => ['--widget-bg' => '#ffffff']],
            'loud' => ['description' => 'Shouty band.', 'slots' => ['--widget-bg' => '#000000']],
        ];
        $this->useFixtureTheme(['widget' => json_encode([
            'component'   => 'widget',
            'description' => 'fixture',
            'props'       => ['title' => ['type' => 'string', 'required' => false, 'default' => '', 'description' => 'Heading.']],
            'styling'     => ['style_slots' => $slots, 'recipes' => $recipes],
        ])]);

        $report = pp_component_schema_report('widget');

        $this->assertCount(2, $report['style_slots'], 'both slots reach the report');
        foreach ($report['style_slots'] as $entry) {
            $stripped = $entry;
            unset($stripped['slot'], $stripped['applies_when_rendered']);
            // === , not a subset check: nothing added, nothing dropped, order kept.
            $this->assertSame($slots[$entry['slot']], $stripped, "{$entry['slot']} is the declaration verbatim");
        }
        // The one derived field, on both branches.
        $rendered = array_column($report['style_slots'], 'applies_when_rendered', 'slot');
        $this->assertSame(
            ['--widget-bg' => null, '--widget-heading-color' => 'title is set'],
            $rendered,
            'the derived field states the condition, and states nothing when there is none'
        );

        $this->assertCount(2, $report['recipes'], 'both recipes reach the report');
        foreach ($report['recipes'] as $entry) {
            $stripped = $entry;
            unset($stripped['name']);
            $this->assertSame($recipes[$entry['name']], $stripped, "recipe {$entry['name']} is the declaration verbatim");
        }
    }

    public function testAnUnregisteredDeclarationKeyStillReachesTheReport(): void
    {
        // THE test that distinguishes "copies the declaration" from "copies the keys the
        // registry knows". Every pin above measures the shipped twelve, and those declare
        // ONLY closed-registry keys — so a projection that filtered on
        // pp_prop_definition_keys()/pp_slot_definition_keys() would produce byte-identical
        // output for all twelve and leave the whole file green while quietly becoming the
        // second schema view this command exists not to be. Only a fixture can tell them
        // apart, and it still goes through the canonical loader.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => ['type' => 'string', 'future_prop_key' => 'v1']],
            'styling'   => [
                'style_slots' => ['--widget-bar' => [
                    'type'            => 'color',
                    'default'         => 'red',
                    'description'     => 'bar',
                    'future_slot_key' => ['nested' => 1],
                ]],
                'recipes' => ['calm' => ['description' => 'x', 'future_recipe_key' => true]],
            ],
        ])]);

        $report = pp_component_schema_report('widget');

        $this->assertSame(
            ['name' => 'mode', 'type' => 'string', 'future_prop_key' => 'v1', 'applies_when_rendered' => null],
            $report['props'][0],
            'an unregistered prop key survives the projection, in declaration order'
        );
        $this->assertSame(['nested' => 1], $report['style_slots'][0]['future_slot_key']);
        $this->assertTrue($report['recipes'][0]['future_recipe_key']);

        // The keys are genuinely outside the closed registries, so a registry-filtered
        // projection could not satisfy this test.
        $this->assertNotContains('future_prop_key', pp_prop_definition_keys());
        $this->assertNotContains('future_slot_key', pp_slot_definition_keys());
    }

    public function testThePromotedAndDerivedFieldNamesCannotCollideWithADeclaration(): void
    {
        // _pp_schema_report_entries() promotes the map key with `+` (left wins) and
        // assigns applies_when_rendered last (right wins). Both silently discard a
        // declared field of the same name, so the projection is lossless only while these
        // names sit OUTSIDE the closed registries. Pinned here so the guard fires when the
        // REGISTRY gains the name — a rename away from being avoided — rather than when a
        // component first declares it and an agent loses data.
        foreach (['name', 'slot', 'applies_when_rendered', 'malformed', 'declaration'] as $reserved) {
            $this->assertNotContains($reserved, pp_prop_definition_keys(), "prop registry must not claim {$reserved}");
            $this->assertNotContains($reserved, pp_slot_definition_keys(), "slot registry must not claim {$reserved}");
        }
    }

    public function testEveryDeclaredDefinitionKeyReachesTheReport(): void
    {
        // The complement of the verbatim pins above, stated in terms of the CLOSED key
        // registries (#575): if the shipped schemas exercise a key, the CLI surfaces it.
        // A future schema key added to the registry and used by a component reaches
        // agents on the day it is declared, with no edit to the report builder.
        $declaredProps = [];
        $declaredSlots = [];
        $reportedProps = [];
        $reportedSlots = [];

        foreach ($this->shippedComponents() as $name) {
            $schema = $this->shippedSchema($name);
            foreach ($schema['props'] ?? [] as $def) {
                $declaredProps += array_flip(array_keys($def));
            }
            foreach ($schema['styling']['style_slots'] ?? [] as $def) {
                $declaredSlots += array_flip(array_keys($def));
            }

            $report = pp_component_schema_report($name);
            foreach ($report['props'] as $entry) {
                $reportedProps += array_flip(array_keys($entry));
            }
            foreach ($report['style_slots'] as $entry) {
                $reportedSlots += array_flip(array_keys($entry));
            }
        }

        $this->assertSame(
            [],
            array_diff_key($declaredProps, $reportedProps),
            'every prop key the shipped schemas declare reaches the report'
        );
        // THE SLOT HALF OF THIS TEST WENT EMPTY AT #1101, and the emptiness is asserted
        // rather than left to pass silently. No shipped component declares a style slot
        // any more (grid's map was the last — see the retirement note on
        // testNoShippedComponentDeclaresSlotsOrRecipesAndTheProjectionIsPinnedOnAFixture),
        // so `$declaredSlots` and `$reportedSlots` are both `[]` and every set operation
        // over them is trivially satisfied. The assertion above is kept because it is the
        // gate that fires the day a slot is declared again; these two make sure a reader
        // is not misled into thinking it measured something today, and that the
        // slot-reporting projection really is reporting nothing rather than quietly
        // dropping keys off a non-empty surface.
        $this->assertSame([], $declaredSlots, 'no shipped schema declares a style-slot key any more');
        $this->assertSame([], $reportedSlots, 'and the report invents none — the slot half of this pin is dormant, not broken');

        // Not vacuous, and bounded by the closed sets: the report adds exactly the
        // promoted identity key and the one derived field, and invents nothing else.
        // PROPS ONLY since #1101 — the slot arm of this pair moved onto the loader-built
        // fixture in testNoShippedComponentDeclaresSlotsOrRecipesAndTheProjectionIsPinnedOnAFixture,
        // which asserts the same `slot` + `applies_when_rendered` delta against a schema
        // that actually declares slots.
        $this->assertNotSame([], $declaredProps, 'the prop half must still measure a real population');
        $this->assertSame([], array_diff(array_keys($declaredProps), pp_prop_definition_keys()));
        $this->assertSame(
            ['name', 'applies_when_rendered'],
            array_values(array_diff(array_keys($reportedProps), array_keys($declaredProps)))
        );
    }

    // ── Degraded input ───────────────────────────────────────────────────────

    public function testComponentWithUndecodableSchemaStillReportsItsIdentity(): void
    {
        // The loader yields [] for a schema.json it cannot decode (lib/admin.php). The
        // report must then answer with the one thing that is still true — the directory
        // name that addresses the component — and visibly empty declarations, rather
        // than fataling or vanishing from the index.
        $this->useFixtureTheme(['widget' => '{ this is not json']);

        $this->assertSame(
            [['component' => 'widget', 'composable' => true]],
            pp_component_schema_index()
        );

        $report = pp_component_schema_report('widget');
        $this->assertSame('widget', $report['component']);
        // Marked, not merely empty. An unmarked empty report is a valid-LOOKING document
        // that says "this component has no contract", and an agent would act on it.
        $this->assertTrue($report['malformed']);
        $this->assertNull($report['description']);
        $this->assertSame([], $report['props']);
        $this->assertSame([], $report['style_slots']);
        $this->assertSame([], $report['recipes']);
        $this->assertArrayNotHasKey('content_requirement', $report);
    }

    public function testAScalarDeclarationIsReportedRatherThanSwallowed(): void
    {
        // A hand-edited schema can put a string where a definition object belongs. The
        // entry stays in the list with its name and its raw value, so the operator sees
        // a malformed declaration instead of a silently shorter prop list — flagged, and
        // still carrying applies_when_rendered so a consumer branches on `malformed`
        // rather than on a missing key.
        $this->useFixtureTheme(['widget' => json_encode([
            'component' => 'widget',
            'props'     => ['mode' => 'string'],
            'styling'   => ['recipes' => ['calm' => 'oops']],
        ])]);

        $report = pp_component_schema_report('widget');

        $this->assertSame(
            [['name' => 'mode', 'malformed' => true, 'declaration' => 'string', 'applies_when_rendered' => null]],
            $report['props']
        );
        // Recipes carry no condition, so the degenerate recipe entry carries no
        // applies_when_rendered either — it matches the shape of a well-formed recipe.
        $this->assertSame(
            [['name' => 'calm', 'malformed' => true, 'declaration' => 'oops']],
            $report['recipes']
        );
    }

    public function testAScalarPropsContainerIsReportedRatherThanFatal(): void
    {
        // `props: "nope"` reaches the projection as a string; an `array` parameter type
        // would fatal the whole report with an uncaught TypeError.
        //
        // Scope, stated so this test is not read as broader than it is: the equivalent
        // `styling.style_slots: "nope"` / `styling.recipes: "nope"` shapes still fatal,
        // inside pp_get_style_slots()/pp_get_style_recipes() (lib/wp.php), whose `: array`
        // return types reject the value before the projection sees it. That is a
        // pre-existing defect on the shared accessors, reachable from the render path and
        // the AI catalog as well, and #688 deliberately leaves it alone rather than
        // guarding one of five callers. Tracked as its own issue.
        $this->useFixtureTheme([
            'widget' => '{"component":"widget","description":"w","props":"nope","styling":{"style_slots":{}}}',
        ]);

        $report = pp_component_schema_report('widget');

        $this->assertSame('widget', $report['component']);
        $this->assertSame('w', $report['description'], 'the readable half still reports');
        $this->assertSame([], $report['props']);
        $this->assertSame([], $report['style_slots']);
        $this->assertSame([], $report['recipes']);
    }

    // ── The CLI command ──────────────────────────────────────────────────────

    public function testBareCommandPrintsTheComponentIndexAsJson(): void
    {
        (new PP_Schema_Command())->__invoke([], []);

        $this->assertCount(1, WP_CLI::$lines);
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'stdout is valid JSON');
        $this->assertSame(['components' => pp_component_schema_index()], $decoded);
    }

    public function testNamedCommandPrintsTheComponentReportAsJson(): void
    {
        (new PP_Schema_Command())->__invoke(['hero'], []);

        $this->assertCount(1, WP_CLI::$lines);
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'stdout is valid JSON');
        $this->assertSame(pp_component_schema_report('hero'), $decoded);
    }

    public function testAnEmptyPositionalIsJudgedByTheBuilderNotCollapsedToTheIndex(): void
    {
        // One decision, one place: an ABSENT positional lists everything; anything
        // PRESENT is a component name. Collapsing "" into "absent" at the CLI layer would
        // make the two layers answer the same input differently (the builder refuses "")
        // and would print an index in response to what is almost always a typo.
        try {
            (new PP_Schema_Command())->__invoke([''], []);
            $this->fail('expected WP_CLI::error');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('Unknown component ""', $e->getMessage());
        }
        $this->assertSame([], WP_CLI::$lines);
    }

    public function testUnknownComponentFailsClosedWithTheBuilderMessage(): void
    {
        try {
            (new PP_Schema_Command())->__invoke(['carousel'], []);
            $this->fail('expected WP_CLI::error');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('Unknown component "carousel"', $e->getMessage());
            $this->assertStringContainsString('hero', $e->getMessage());
        }
        $this->assertSame([], WP_CLI::$lines, 'nothing is printed to the machine channel');
    }

    public function testCommandNeedsNoRunTokenAndMutatesNothing(): void
    {
        // Same class as `inspect-composition`: read-only, so it must neither demand a
        // run token nor mint one. Snapshotting the whole store is the blunt version of
        // that promise — a run token would land in `options`.
        $before = $GLOBALS['_pp_test_store'] ?? [];

        (new PP_Schema_Command())->__invoke([], []);
        (new PP_Schema_Command())->__invoke(['hero'], []);

        $this->assertSame($before, $GLOBALS['_pp_test_store'] ?? []);
        $this->assertCount(2, WP_CLI::$lines);
    }

    public function testThePageAddressingHookDoesNotClaimAComponentPositional(): void
    {
        // #685 refuses a positional PAGE argument before dispatch. This command's
        // positional is a COMPONENT name, which no --post_id could express. Pinned
        // against the hook's own predicate so a later widening of its scope cannot
        // silently start swallowing component names.
        $this->assertNull(_pp_cli_positional_page_arg_error(['pp', 'schema', 'hero'], []));
        $this->assertNull(_pp_cli_positional_page_arg_error(['pp', 'schema'], []));
        // #726 widened the guard from `pp operate <sub>` to whole command paths,
        // so the pin moves with it: no `pp schema ...` path may be page-addressed.
        foreach (PP_CLI_PAGE_ADDRESSED_COMMANDS as $command) {
            $this->assertStringStartsNotWith('pp schema', $command);
        }
    }

    /**
     * `wp pp schema <component>` IS THE CLI OPERATOR'S ONLY ROUTE TO `_css`.
     *
     * The report is read-only and needs no run token, which is exactly why it is the
     * discovery surface for someone on SSH with no chat session. The /ship coverage audit
     * deleted the whole `udc_raw_css` block as a mutant and the suite stayed green at
     * 5204 tests — the feature could have vanished from the CLI without one red test, the
     * same shape as the AI-prompt paragraph pinned in AiContextTest.
     *
     * Every field is DERIVED from the engine rather than matched as prose, so this cannot
     * become a copy that drifts from the gate it describes (I43) — which is the entire
     * reason `excluded` is built from `pp_udc_css_excluded_properties()` in the first place.
     */
    public function testTheSchemaReportTellsAnOperatorRawCssExists(): void
    {
        $report = pp_component_schema_report('faq');

        $this->assertArrayHasKey('udc_raw_css', $report,
            'a v2 component\'s schema report must name the raw-CSS escape hatch');

        $this->assertSame(PP_UDC_CSS_KEY, $report['udc_raw_css']['key'],
            'the key an operator has to type comes from the constant, never a literal');

        $this->assertSame(
            array_keys(pp_udc_css_excluded_properties()),
            $report['udc_raw_css']['excluded'],
            'THE DRIFT CLASS: a sixth exclusion added to the engine must appear here '
            . 'without anyone remembering to update a list'
        );
    }
}
