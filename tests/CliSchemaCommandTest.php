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
        // `styling.chrome_custom_properties`, which #688 scoped out of this report (see
        // pp_component_schema_report()'s "what it deliberately does not emit"). Pinned
        // with the reason attached so the empty list is never read as "nav has no
        // styling contract".
        $this->assertSame([], $report['style_slots']);
        $this->assertSame([], $report['recipes']);
        $this->assertArrayNotHasKey(
            'style_slots',
            $this->shippedSchema('nav')['styling'],
            'the emptiness comes from the schema, not from the projection'
        );
        $this->assertNotEmpty($this->shippedSchema('nav')['styling']['chrome_custom_properties']);
    }

    public function testComponentWithoutRecipesReportsAnEmptyList(): void
    {
        $report = pp_component_schema_report('embed');
        $this->assertSame([], $report['recipes']);
        $this->assertNotEmpty($report['style_slots'], 'embed does declare style slots');
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
        $checked     = 0;
        $withNote    = 0;
        $noteOnly    = 0;

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

                    if (isset($declaration['conditionality_note'])) {
                        $withNote++;
                        if (empty($declaration['applies_when'])) {
                            $noteOnly++;
                        }
                    }
                }
            }
        }

        // Not vacuous, and specifically not vacuous on the branch that used to be
        // exempt: the shipped set really does exercise clause-only, clause-plus-note,
        // and note-only declarations, so all three compositions are measured.
        $this->assertGreaterThan(20, $checked, 'the shipped schemas declare many conditions');
        $this->assertGreaterThan(10, $withNote, 'and many of them conjoin a prose note');
        $this->assertGreaterThan(0, $noteOnly, 'and some are prose-only');
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

        $this->assertGreaterThan(100, $seen, 'discovery is not vacuous');
    }

    public function testEverySlotEntryIsTheDeclaredDefinitionVerbatim(): void
    {
        $seen = 0;

        foreach ($this->shippedComponents() as $name) {
            $declared = $this->shippedSchema($name)['styling']['style_slots'] ?? [];
            foreach (pp_component_schema_report($name)['style_slots'] as $entry) {
                $stripped = $entry;
                unset($stripped['slot'], $stripped['applies_when_rendered']);

                $this->assertSame(
                    $declared[$entry['slot']],
                    $stripped,
                    "{$name} {$entry['slot']} is the declaration verbatim"
                );
                $seen++;
            }
        }

        $this->assertGreaterThan(200, $seen, 'discovery is not vacuous');
    }

    public function testEveryRecipeEntryIsTheDeclaredDefinitionVerbatim(): void
    {
        $seen = 0;

        foreach ($this->shippedComponents() as $name) {
            $declared = $this->shippedSchema($name)['styling']['recipes'] ?? [];
            foreach (pp_component_schema_report($name)['recipes'] as $entry) {
                $stripped = $entry;
                unset($stripped['name']);

                $this->assertSame(
                    $declared[$entry['name']],
                    $stripped,
                    "{$name} recipe {$entry['name']} is the declaration verbatim"
                );
                $seen++;
            }
        }

        $this->assertGreaterThan(5, $seen, 'discovery is not vacuous');
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
        $this->assertSame(
            [],
            array_diff_key($declaredSlots, $reportedSlots),
            'every slot key the shipped schemas declare reaches the report'
        );

        // Not vacuous, and bounded by the closed sets: the report adds exactly the
        // promoted identity key and the one derived field, and invents nothing else.
        $this->assertSame([], array_diff(array_keys($declaredProps), pp_prop_definition_keys()));
        $this->assertSame([], array_diff(array_keys($declaredSlots), pp_slot_definition_keys()));
        $this->assertSame(
            ['name', 'applies_when_rendered'],
            array_values(array_diff(array_keys($reportedProps), array_keys($declaredProps)))
        );
        $this->assertSame(
            ['slot', 'applies_when_rendered'],
            array_values(array_diff(array_keys($reportedSlots), array_keys($declaredSlots)))
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
        $this->assertNotContains('schema', PP_CLI_PAGE_ADDRESSED_OPERATE_SUBCOMMANDS);
    }
}
