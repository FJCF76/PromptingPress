<?php
/**
 * tests/CompositionShapeTrustTest.php
 *
 * The composition CONTAINER is a list — at the write path (#724) and in what the read
 * path admits about it (#725). Two halves of one stored state, pinned together.
 *
 * THE DEFECT, MEASURED (v1.15.0 release smoke, fixture page 234). `update_composition`
 * with `{"1": hero, "3": section}` — a JSON OBJECT, not a list — returned `ok:true`,
 * bumped `composition_version` to 3, reported `findings: []`, and replaced a five-band
 * composition with two bands. `wp pp check page` then classified the very same stored
 * value `composition data integrity error (unexpected_shape) ... treat as corrupted, not
 * empty`. The write path could manufacture, behind a success envelope, exactly the state
 * the diagnostics exist to detect. Both bands in the payload were WELL-FORMED, which is
 * why every per-item rule stayed silent: nothing below the container was wrong.
 *
 * The sibling half, on that same page: `wp pp operate inspect-composition --post_id=N`
 * returned `[]`. No fabricated locators, so the honest-locator contract held — but an
 * agent reading `[]` concludes "no components" when the truth is "unreadable", and the
 * next thing it does is author over the recoverable bytes.
 *
 * THE RULING (D-A, canonical text in #724's body). REJECT, NEVER COERCE. The write path
 * refuses a non-list composition with the standard rejection envelope, consistent with
 * the #687-era read-path classification. No reindexing, no `array_values()`, no migration
 * of stored data. A documented breaking NARROWING of write acceptance.
 *
 * WHAT THIS FILE PINS, and why each part is here rather than assumed:
 *
 *   1. THE CONTAINER RULE ITSELF, above every per-item rule, returning ONE finding with
 *      no band locator — because inside a container that is not a composition there is no
 *      honest answer to "which band?" (#634/#650/#652), and because the read path models
 *      exactly this: a non-list yields `composition: []` and says nothing about contents.
 *   2. THE LIST PATH IS BYTE-IDENTICAL. Every shipped example, doc snippet and existing
 *      test authors a list; the overwhelmingly common case must not move at all.
 *   3. THE AUTHORING PATH (Section 14.1) — create_page / update_composition, the surfaces
 *      an agent actually reaches for, each rejection paired with the well-formed
 *      counterpart. And the full non-write proof: the five bands, the VERSION and the
 *      history ring all unchanged. The measured defect included a version bump, so
 *      "nothing persisted" is not proven by reading the bands back alone.
 *   4. THE STATED LIMITS, so they are not discovered later as bugs. `{"0":..,"1":..}` and
 *      `{}` decode to a PHP list and an empty array; the object/list distinction is
 *      destroyed before any PHP here can see it. Same limit #652 recorded one layer down.
 *   5. THE BOUNDARY THE NARROWING MUST NOT CROSS: restore_composition still restores
 *      verbatim and REPORTS rather than blocks (#233) — with the container named, and
 *      without an advisory carrying a fabricated index beside it.
 *   6. THE READ HALF (#725): the same stored state, seen through inspect-composition and
 *      the CLI, never as `[]`; and the integrity sentence spelled ONE way across surfaces.
 *   7. THE PAIRED PROOF: one seeded stored state, both halves asserted against it, plus
 *      the repair route that gets the page back.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/DiagnosticReachTest) ───────────
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

class CompositionShapeTrustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    /** Five well-formed bands — the composition the measured repro destroyed. */
    private function fiveBands(): array
    {
        return [
            ['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'One']],
            ['component' => 'hero', 'props' => ['id' => 'band-2', 'title' => 'Two']],
            ['component' => 'hero', 'props' => ['id' => 'band-3', 'title' => 'Three']],
            ['component' => 'hero', 'props' => ['id' => 'band-4', 'title' => 'Four']],
            ['component' => 'hero', 'props' => ['id' => 'band-5', 'title' => 'Five']],
        ];
    }

    /**
     * The measured payload shape: a JSON object keyed by position, whose two bands are
     * themselves WELL-FORMED. That is the whole point — nothing below the container is
     * wrong, so only a container rule can catch it.
     */
    private function objectShapedPayload(): array
    {
        return [
            1 => ['component' => 'hero', 'props' => ['id' => 'kept-1', 'title' => 'Kept one']],
            3 => ['component' => 'hero', 'props' => ['id' => 'kept-3', 'title' => 'Kept three']],
        ];
    }

    // ── 1. The container rule ────────────────────────────────────────────────

    public function testAnObjectShapedCompositionIsRefusedWithTheReadPathsOwnClassification(): void
    {
        $errors = pp_validate_composition_errors($this->objectShapedPayload());

        $this->assertCount(1, $errors, 'one container, one fact — no per-item rules run inside a corrupt container');
        $this->assertSame(
            'unexpected_shape',
            $errors[0]->get_error_code(),
            'the write path must name this state with the read path\'s own word, not a fourth spelling'
        );
    }

    public function testTheContainerErrorNamesTheShapeAndNeverABand(): void
    {
        $message = pp_validate_composition_errors($this->objectShapedPayload())[0]->get_error_message();

        $this->assertStringContainsString('must be a list of components', $message);
        $this->assertStringContainsString('JSON object', $message);
        $this->assertStringContainsString('2 entries', $message);
        // The honest-locator contract (#650/#652, D1): for a non-list the problem is the
        // CONTAINER, so the message must not invent a band the operator would go repair.
        $this->assertStringNotContainsString('Item ', $message, 'a container-level problem names no band');
        $this->assertStringNotContainsString('key "1"', $message, 'no band locator, not even an honest one');
    }

    public function testTheContainerErrorCarriesNoCompositionOffset(): void
    {
        // Same posture as duplicate_component_id: an error that belongs to no single band
        // carries no index rather than a fabricated one.
        $this->assertNull(
            pp_composition_error_index(pp_validate_composition_errors($this->objectShapedPayload())[0])
        );
    }

    public function testAStringKeyedCompositionIsRefusedByTheSameRule(): void
    {
        // Not every non-list has numeric keys. The rule is "not a list", and the message
        // must not narrow that to a claim about key ordering.
        $errors = pp_validate_composition_errors([
            'hero' => ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('unexpected_shape', $errors[0]->get_error_code());
        $this->assertStringContainsString('1 entry', $errors[0]->get_error_message());
    }

    public function testTheFirstErrorWinsWrapperReturnsTheContainerErrorUnprefixed(): void
    {
        $error = pp_validate_composition($this->objectShapedPayload());

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unexpected_shape', $error->get_error_code());
        // #642's band-naming renderer must leave it alone: no stamped offset, no prefix.
        $this->assertStringStartsWith('The composition must be a list', $error->get_error_message());
    }

    // ── 2. The list path does not move ───────────────────────────────────────

    public function testAWellFormedListStillValidatesClean(): void
    {
        $this->assertSame([], pp_validate_composition_errors($this->fiveBands()));
    }

    public function testAnEmptyCompositionIsStillAValidList(): void
    {
        $this->assertSame([], pp_validate_composition_errors([]));
    }

    public function testABrokenListStillReportsItsPerItemErrorsExactlyAsBefore(): void
    {
        // The guard must gate ONLY the container. A list whose bands are broken still gets
        // the full per-item treatment, with its band locators intact.
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'Fine']],
            ['props' => ['title' => 'No component key']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
        $this->assertStringContainsString('Item 1', $errors[0]->get_error_message());
        $this->assertSame(1, pp_composition_error_index($errors[0]));
    }

    // ── 3. The authoring path (Section 14.1) ─────────────────────────────────

    /**
     * The filed repro, end to end: the object-shaped write is refused, and NOTHING about
     * the page moves — not the bands, not the version, not the history ring.
     */
    public function testUpdateCompositionRefusesTheObjectPayloadAndDestroysNothing(): void
    {
        $post_id = pp_create_page('Five band page', 'draft');
        pp_update_composition($post_id, $this->fiveBands());
        $version_before = pp_get_composition_marker($post_id)['version'];
        $history_before = pp_get_composition_history($post_id);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->objectShapedPayload(),
        ]);

        $this->assertFalse($result['ok'], 'the measured defect was ok:true behind a destructive write');
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertStringContainsString('must be a list of components', $result['error']);

        $stored = pp_get_composition($post_id);
        $this->assertCount(5, $stored, 'all five bands survive a refused write');
        $this->assertSame(
            ['band-1', 'band-2', 'band-3', 'band-4', 'band-5'],
            array_column(array_column($stored, 'props'), 'id')
        );
        $this->assertSame(
            $version_before,
            pp_get_composition_marker($post_id)['version'],
            'the measured defect bumped composition_version to 3; a refused write must not touch it'
        );
        $this->assertSame(
            $history_before,
            pp_get_composition_history($post_id),
            'a refused write pushes nothing onto the history ring'
        );
    }

    public function testUpdateCompositionStillAcceptsTheSameBandsAsAList(): void
    {
        $post_id = pp_create_page('Five band page', 'draft');
        pp_update_composition($post_id, $this->fiveBands());

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => array_values($this->objectShapedPayload()),
        ]);

        $this->assertTrue($result['ok'], 'the counterpart write — same bands, list container — must still land');
        $stored = pp_get_composition($post_id);
        $this->assertCount(2, $stored);
        $this->assertSame(['kept-1', 'kept-3'], array_column(array_column($stored, 'props'), 'id'));
    }

    public function testCreatePageRefusesAnObjectShapedCompositionAndCreatesNoPage(): void
    {
        $before = count($GLOBALS['_pp_test_store']['posts']);

        $result = pp_execute_action('create_page', [
            'title'       => 'Never created',
            'composition' => $this->objectShapedPayload(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertCount($before, $GLOBALS['_pp_test_store']['posts'], 'validation runs before the page is created');
    }

    public function testCreatePageStillAcceptsTheWellFormedCounterpart(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Created',
            'composition' => array_values($this->objectShapedPayload()),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, pp_get_composition($result['target']['post_id']));
    }

    public function testTheComponentLevelActionsAreUnaffectedOnAHealthyPage(): void
    {
        // add_component validates a SYNTHETIC one-element array and update_component builds
        // its test composition from the stored list, so neither can present a non-list to
        // the engine. Pinned so the guard is never blamed for a regression here.
        $post_id = pp_create_page('Healthy page', 'draft');
        pp_update_composition($post_id, $this->fiveBands());

        $added = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'hero',
            'props'     => ['id' => 'band-6', 'title' => 'Six'],
        ]);
        $this->assertTrue($added['ok'], 'add_component is not a whole-composition container write');

        $updated = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['title' => 'One, edited'],
        ]);
        $this->assertTrue($updated['ok']);
        $this->assertSame('One, edited', pp_get_composition($post_id)[0]['props']['title']);
    }

    // ── 4. The stated limits ─────────────────────────────────────────────────

    public function testAnOrderedNumericObjectDecodesAsAListAndIsAccepted(): void
    {
        // json_decode('{"0":a,"1":b}', true) IS a PHP list — the keys are 0..n-1 in order
        // and the object/list distinction is destroyed before any PHP here can see it. It
        // is also the harmless case: key and position agree, so nothing is dropped. Same
        // limit #652 recorded for item locators, stated here so it is not read as a hole.
        $decoded = json_decode('{"0":{"component":"hero","props":{"title":"A"}},"1":{"component":"hero","props":{"title":"B"}}}', true);

        $this->assertTrue(pp_is_list($decoded), 'the premise: PHP hands this back as a list');
        $this->assertSame([], pp_validate_composition_errors($decoded));
    }

    public function testAnEmptyJsonObjectIsIndistinguishableFromAnEmptyListAndIsAccepted(): void
    {
        $this->assertSame([], json_decode('{}', true), 'the premise: {} and [] decode identically');
        $this->assertSame([], pp_validate_composition_errors(json_decode('{}', true)));
    }

    public function testANonArrayCompositionParamIsRefusedBeforeTheValidatorEverSeesIt(): void
    {
        // The container rule only has to cover array-but-not-list, because the action
        // layer's own gettype() check already refuses a scalar or null `composition`.
        $post_id = pp_create_page('Typed param page', 'draft');
        pp_update_composition($post_id, $this->fiveBands());

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => 'not even an array',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_param_type', $result['error_code']);
        $this->assertCount(5, pp_get_composition($post_id));
    }

    // ── 5. The boundary: restore reports, never blocks (#233) ────────────────

    public function testRestoreStillRestoresAnObjectShapedSnapshotAndReportsTheContainer(): void
    {
        // Undo must never be refused by a rule that landed after the snapshot. The history
        // ring accepts an object snapshot behind an is_array() gate, so this state is
        // reachable on any install that predates #724 — restore must still replay it, and
        // must say what it replayed.
        $post_id = pp_create_page('Aged page', 'draft');
        pp_update_composition($post_id, $this->objectShapedPayload());
        pp_update_composition($post_id, $this->fiveBands());

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'restore is never blocked by current validation rules (#233)');
        $this->assertCount(1, $result['findings'], 'one container, one fact — no advisories about bands inside it');
        $this->assertSame('unexpected_shape', $result['findings'][0]['type']);
        $this->assertSame('error', $result['findings'][0]['severity']);
        $this->assertNull($result['findings'][0]['index'], 'no band owns a container-level finding');

        // AND THE STATE IT LEAVES BEHIND, which is the half worth recording: restore is the
        // one surface that can legitimately RE-CORRUPT a page after #724 closed the write
        // path, because #233 outranks the new rule. The page is corrupt again, every read
        // surface says so (including #725's, which returned [] here before), and the same
        // one-list repair recovers it. Intended behavior, pinned rather than discovered.
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error']);
        $this->assertInstanceOf(WP_Error::class, pp_inspect_composition($post_id));

        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->fiveBands(),
        ]);
        $this->assertTrue($repaired['ok']);
        $this->assertCount(5, pp_inspect_composition($post_id));
    }

    /**
     * The component-level gate on a CORRUPT page: pinned as the state it is, not fixed.
     *
     * pp_action_composition_precondition() reads through pp_get_composition(), which
     * degrades a non-list to [], so every component-level action and `operate patch`
     * answer `composition_required` — "post N has none yet. Populate it first" — which is
     * false for a page that is corrupt rather than blank. That is the SAME wrong conclusion
     * #725 removed from inspect-composition, reached through a different door.
     *
     * Not fixed here: the gate is shared by six surfaces and needs its own code-and-noun
     * ruling (keep `composition_required` with a corrected message, or return the
     * classification?), so it is filed as #748. The gate fails CLOSED — no stored bytes are
     * touched — so the defect is diagnostic, not destructive. Pinned so the gap is a
     * recorded decision rather than an omission, and so #748's fix has a failing assertion
     * waiting for it.
     */
    public function testComponentLevelActionsStillCallACorruptPageEmptyPending748(): void
    {
        $post_id = pp_create_page('Corrupt page', 'draft');
        pp_update_composition($post_id, $this->objectShapedPayload());

        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['title' => 'Edited'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_required', $result['error_code'],
            '#748: reports absent when the truth is unreadable');
        $this->assertStringContainsString('has none yet', $result['error']);

        // Fails CLOSED: the recoverable bytes are still there for #725 to report on.
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error']);
    }

    public function testTheFindingsReportSkipsAdvisoriesForANonListContainer(): void
    {
        // The smell rules format their locator with %d and stamp 'index' => $i, so on an
        // object container they would print a FABRICATED index 0 next to the honest
        // container finding. A composition that is not a composition gets one fact.
        $findings = _pp_composition_findings([
            2 => ['component' => 'nav', 'props' => []],   // chrome: a smell AND an error on a list
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('unexpected_shape', $findings[0]['type']);
        foreach ($findings as $finding) {
            $this->assertNull($finding['index']);
        }
    }

    public function testTheFindingsReportStillCarriesAdvisoriesForAList(): void
    {
        // The counterpart: the smell pass is gated on the container, not disabled.
        $findings = _pp_composition_findings([
            ['component' => 'nav', 'props' => []],
        ]);

        $severities = array_column($findings, 'severity');
        $this->assertContains('error', $severities);
        $this->assertContains('warning', $severities, 'a list still gets its advisories');
    }

    // ── 6. The read half (#725) ──────────────────────────────────────────────

    public function testInspectCompositionReportsTheCorruptionInsteadOfAnEmptyList(): void
    {
        $post_id = pp_create_page('Object page', 'draft');
        pp_update_composition($post_id, $this->objectShapedPayload());

        $result = pp_inspect_composition($post_id);

        $this->assertInstanceOf(WP_Error::class, $result, 'the measured defect returned [] — "no components", not "unreadable"');
        $this->assertSame('unexpected_shape', $result->get_error_code());
        $this->assertStringContainsString('treat as corrupted, not empty', $result->get_error_message());
        $this->assertStringContainsString('update_composition', $result->get_error_message(), 'the report names a repair route');
    }

    public function testInspectCompositionReportsAnUndecodableRowWithItsOwnClassification(): void
    {
        $post_id = pp_create_page('Undecodable page', 'draft');
        update_post_meta($post_id, '_pp_composition', '{"component":');

        $result = pp_inspect_composition($post_id);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('decode_error', $result->get_error_code());
    }

    public function testInspectCompositionIsUnchangedOnAHealthyPage(): void
    {
        $post_id = pp_create_page('Healthy page', 'draft');
        pp_update_composition($post_id, $this->fiveBands());

        $result = pp_inspect_composition($post_id);

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
        $this->assertSame('hero', $result[0]['component_type']);
        $this->assertSame('band-1', $result[0]['component_id']);
    }

    public function testInspectCompositionStillReturnsAnEmptyListForAGenuinelyBlankPage(): void
    {
        // The distinction the whole issue is about: blank is not corrupt, and must not
        // start erroring.
        $post_id = pp_create_page('Blank page', 'draft');

        $this->assertSame([], pp_inspect_composition($post_id));
    }

    public function testTheCliCommandErrorsInsteadOfPrintingAnEmptyList(): void
    {
        $post_id = pp_create_page('Object page', 'draft');
        pp_update_composition($post_id, $this->objectShapedPayload());

        try {
            (new PP_Operate_Command())->inspect_composition([], ['post_id' => (string) $post_id]);
            $this->fail('inspect-composition must not exit 0 with a reassuring [] on a corrupt page');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('composition data integrity error (unexpected_shape)', $e->getMessage());
        }
        $this->assertSame([], WP_CLI::$lines, 'nothing is printed to stdout for an unreadable page');
    }

    public function testTheIntegritySentenceIsSpelledOneWayAcrossSurfaces(): void
    {
        // check page's bytes are unchanged by the move into the shared builder. Pinning the
        // literal is the point: the builder exists so a second surface cannot introduce a
        // fourth spelling of one classification (#650/#652).
        $this->assertSame(
            'Page 234: composition data integrity error (unexpected_shape). '
            . 'The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.',
            pp_composition_integrity_message(234, 'unexpected_shape')
        );
        // Both halves of the builder's input domain — the classification is the one part
        // that varies, so pinning only one of two leaves half of it unguarded.
        $this->assertSame(
            'Page 7: composition data integrity error (decode_error). '
            . 'The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.',
            pp_composition_integrity_message(7, 'decode_error')
        );
    }

    /**
     * `wp pp validate site` keeps its OWN wording of the integrity sentence (it names the
     * page title, which the shared builder's signature cannot know). The lib/wp.php
     * docblock calls that a phrasing VARIANT rather than a second vocabulary; this is what
     * makes that claim checkable instead of prose, because two strings that can drift apart
     * with a green suite is the precise failure #650/#652 spent an iteration undoing.
     *
     * A SOURCE SLICE, not a command call, and the reason is worth recording: driving
     * PP_Validate_Command::site() from PHPUnit is not reliable, because pp_composition_pages()
     * caches statically for the life of the process — the command passes in isolation and
     * silently finds zero pages in a full-suite run, which is a test that reports success
     * for the wrong reason. Per the repo's source-tripwire convention, the specific branch
     * is sliced out and asserted for its POSITIVE shape rather than grepping the whole file
     * for a symbol that could survive a reword.
     */
    public function testValidateSiteSpellsTheSameClassificationAsTheSharedBuilder(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/cli.php');
        $start  = strpos($source, "if (!\$result['ok']) {", strpos($source, 'public function site('));
        $this->assertNotFalse($start, 'the corrupt-page branch of `validate site` was not found — has it been restructured?');
        $branch = substr($source, $start, 900);

        $shared = pp_composition_integrity_message(234, 'unexpected_shape');
        foreach (['composition data integrity error', 'not a valid composition list'] as $clause) {
            $this->assertStringContainsString($clause, $shared, 'premise: the shared builder carries this clause');
            $this->assertStringContainsString(
                $clause,
                $branch,
                'validate site must stay a phrasing variant of the shared sentence, not a second vocabulary'
            );
        }
        $this->assertStringContainsString('$pass = false;', $branch, 'and it must still fail the gate CI runs');
    }

    // ── 7. The paired proof: one stored state, both halves ───────────────────

    /**
     * The state #724 stops the write path from CREATING is the state #725 makes legible on
     * the pages that already carry it. Both halves, asserted against the same seeded bytes,
     * plus the route that gets the page back.
     */
    public function testBothHalvesAgreeOnOneStoredStateAndThePageIsRepairable(): void
    {
        $post_id = 234;
        $GLOBALS['_pp_test_store']['posts'][$post_id] = [
            'ID' => $post_id, 'post_title' => 'Smoke fixture', 'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_wp_page_template'] = 'composition.php';
        // Seeded through the NON-validating writer — the aged/raw path #724 cannot reach
        // any more, and the only way this state still arrives on a real install.
        pp_update_composition($post_id, $this->objectShapedPayload());

        // (a) the classifier, unchanged since #144
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error']);

        // (b) check page, unchanged
        (new PP_Check_Command())->page([], ['post_id' => $post_id]);
        $this->assertSame([], WP_CLI::$successes, 'a corrupt page never reports clean');
        $this->assertStringContainsString(
            'composition data integrity error (unexpected_shape)',
            implode("\n", WP_CLI::$warnings)
        );

        // (c) #725: the read surface agrees instead of saying "no components"
        $inspected = pp_inspect_composition($post_id);
        $this->assertInstanceOf(WP_Error::class, $inspected);
        $this->assertSame('unexpected_shape', $inspected->get_error_code());

        // (d) #724: the write path refuses to re-create the state
        $refused = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->objectShapedPayload(),
        ]);
        $this->assertFalse($refused['ok']);
        $this->assertSame('unexpected_shape', $refused['error_code']);

        // (e) the repair route works, and every surface goes quiet
        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->fiveBands(),
        ]);
        $this->assertTrue($repaired['ok'], 'a corrupt page is repaired by one full list write');
        $this->assertTrue(pp_get_composition_result($post_id)['ok']);
        $this->assertCount(5, pp_inspect_composition($post_id));
    }
}
