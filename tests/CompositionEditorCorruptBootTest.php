<?php
/**
 * tests/CompositionEditorCorruptBootTest.php — the composition editor on an unreadable page (#750)
 *
 * The editor is the one repair surface #767 measured as working, and until #750 it could
 * not tell an operator that a page needed repairing — or, for two stored shapes, could not
 * open at all. Two PHP seams carry that now, and both are pinned here:
 *
 *   pp_composition_editor_text()       what the JSON pane loads, per stored shape
 *   pp_composition_editor_integrity()  what the client is told about the stored row
 *
 * The client half (JSON-only mode, the notice, the add-component guard) is pinned in
 * tests/js/pp-editor-corrupt-boot.test.js against the real booted editor.
 */

use PHPUnit\Framework\TestCase;

class CompositionEditorCorruptBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    private function seed(int $post_id, $stored): void
    {
        $GLOBALS['_pp_test_store']['posts'][$post_id] = [
            'post_type'   => 'page',
            'post_title'  => 'Page',
            'post_status' => 'publish',
        ];
        if ($stored !== null) {
            $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] = $stored;
        }
    }

    // ── The pane text ─────────────────────────────────────────────────────

    /**
     * The blank pane is for the values the CLASSIFIER calls a blank page, and for no others.
     * This is the pin that would have caught the `'0'` bug: `if ($raw)` and `$raw ?: ''` are
     * both false for the string zero, so a page storing the JSON scalar 0 — an
     * `unexpected_shape` row — opened as an empty editor.
     */
    public function testOnlyGenuinelyAbsentValuesRenderABlankPane(): void
    {
        $this->assertSame('', pp_composition_editor_text(''));
        $this->assertSame('', pp_composition_editor_text(null));
        $this->assertSame('', pp_composition_editor_text(false));

        $this->assertSame('0', pp_composition_editor_text('0'));
        $this->assertSame('0.0', pp_composition_editor_text('0.0'));
        $this->assertSame('""', pp_composition_editor_text('""'));
    }

    public function testAStoredListIsPrettyPrinted(): void
    {
        $text = pp_composition_editor_text('[{"component":"hero","props":{"title":"Hi"}}]');

        $this->assertStringContainsString("\n", $text, 'a pretty-printed list spans lines');
        $this->assertSame(
            [['component' => 'hero', 'props' => ['title' => 'Hi']]],
            json_decode($text, true),
            'and decodes back to exactly what was stored'
        );
    }

    /** The corrupt-but-decodable shape stays visible and editable — that is the repair path. */
    public function testAStoredJsonObjectIsShownAsJsonRatherThanHidden(): void
    {
        $text = pp_composition_editor_text('{"1":{"component":"hero"}}');

        $this->assertSame(['1' => ['component' => 'hero']], json_decode($text, true));
    }

    /** Undecodable bytes are shown verbatim: there is nothing to re-encode, and guessing would lose them. */
    public function testUndecodableBytesAreShownVerbatim(): void
    {
        $this->assertSame('not json at{', pp_composition_editor_text('not json at{'));
    }

    /**
     * The FATAL class. get_post_meta() unserializes on the way out, so a row written as a
     * PHP array (an importer, `wp post meta update … --format=json`) came back as an array,
     * and `json_decode(array)` is a TypeError on PHP 8 — the editor page died before
     * rendering. Both the list and non-list variants are covered: one is a healthy
     * composition stored the unusual way, the other is `unexpected_shape`.
     */
    public function testANonStringStoredValueIsRenderedAsJsonInsteadOfFataling(): void
    {
        $list = pp_composition_editor_text([['component' => 'hero']]);
        $this->assertSame([['component' => 'hero']], json_decode($list, true));

        $map = pp_composition_editor_text(['1' => ['component' => 'hero']]);
        $this->assertSame(['1' => ['component' => 'hero']], json_decode($map, true));

        $this->assertSame('5', pp_composition_editor_text(5));
        $this->assertSame('true', pp_composition_editor_text(true));
    }

    /**
     * The blank pane has a third cause that is not a shape at all: an ENCODER giving up.
     * wp_json_encode() returns false on a value it cannot encode, and `(string) false` is
     * '' — so a stored row carrying invalid UTF-8 would open blank, which is the exact
     * presentation this issue removes. JSON_INVALID_UTF8_SUBSTITUTE is what stops it.
     */
    public function testAnUnencodableStoredValueStillRendersSomething(): void
    {
        $text = pp_composition_editor_text(['title' => "bad \xB1\x31 bytes"]);

        $this->assertNotSame('', $text);
        $this->assertStringContainsString('title', $text);
    }

    /**
     * And the same hole in the ESCAPER, one layer further out: esc_textarea() is
     * htmlspecialchars($text, ENT_QUOTES, ...) with explicit flags, so PHP 8's
     * ENT_SUBSTITUTE default does not apply and invalid UTF-8 escapes to ''. Truncated or
     * binary-garbage bytes are a real decode_error shape, so the pane text has to be
     * printable UTF-8 by the time it leaves this function.
     */
    public function testUndecodableBytesThatAreNotUtf8AreStillPrintable(): void
    {
        $text = pp_composition_editor_text("not json at{\xC3\x28");

        $this->assertNotSame('', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'), 'the pane text must survive esc_textarea()');
        $this->assertNotSame('', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), 'what esc_textarea would print');
        $this->assertStringContainsString('not json at{', $text, 'the readable part is not thrown away');
    }

    /**
     * THE PANE MUST NEVER TURN CORRUPT BYTES INTO A SAVEABLE COMPOSITION.
     *
     * The fixture is the dangerous shape and the reason this test exists: bytes that are
     * STRUCTURALLY valid JSON and fail only on encoding (a latin-1 title from an importer).
     * Substituting the bad byte — what mb_convert_encoding does — yields `"caf?"`, which
     * parses, validates, saves, and clears the corruption notice as "repaired", having
     * silently replaced a character of the author's content. Transcribing it as `\xE9`
     * cannot parse, so the save is refused and the author sees the byte.
     */
    public function testTranscribedBytesCannotBeSavedAsAComposition(): void
    {
        $stored = "[{\"component\":\"hero\",\"props\":{\"title\":\"caf\xE9 latte\"}}]";
        $this->assertSame('decode_error', pp_classify_composition_value($stored)['error'], 'premise');

        $text = pp_composition_editor_text($stored);

        $this->assertNull(json_decode($text, true), 'the pane text must not parse as JSON');
        $this->assertStringContainsString('\\xE9', $text, 'the offending byte is named, not swapped');
        $this->assertStringContainsString('caf', $text);
        $this->assertStringContainsString('latte', $text);
        $this->assertStringNotContainsString('caf?', $text, 'a silent substitution is the bug');
    }

    /** A valid multibyte run inside otherwise-broken bytes is kept, not escaped away. */
    public function testTranscriptionKeepsValidMultibyteRuns(): void
    {
        $text = pp_composition_editor_text("Café \xE9 日本語");

        $this->assertStringContainsString('Café', $text);
        $this->assertStringContainsString('日本語', $text);
        $this->assertStringContainsString('\\xE9', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    /** Valid multibyte content is untouched: it round-trips, whatever the escaping of it. */
    public function testNonAsciiCompositionSurvivesThePane(): void
    {
        $stored = json_encode([['component' => 'hero', 'props' => ['title' => 'Café — 日本語']]]);
        $text   = pp_composition_editor_text($stored);

        $this->assertSame(
            [['component' => 'hero', 'props' => ['title' => 'Café — 日本語']]],
            json_decode($text, true)
        );
    }

    // ── The seams (#750) ──────────────────────────────────────────────────
    //
    // Both helpers below are exhaustively unit-tested and both are reached from code no test
    // can call: one from the workspace page callback, one from an `admin_enqueue_scripts`
    // closure (tests/bootstrap.php stubs add_action to a no-op). Unwire either and every
    // other assertion in this file stays green while the shipped editor goes back to
    // fatalling on an array-shaped row and booting a corrupt page into #745's drift notice.
    // These read the source back, in the spirit of NavReadinessTest.

    public function testTheWorkspacePageSourcesThePaneFromTheSharedHelper(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/lib/admin.php');

        $this->assertStringContainsString(
            "pp_composition_editor_text(get_post_meta(\$post_id, '_pp_composition', true))",
            $src,
            'the editor pane must be built by the helper this file pins'
        );
        // Matched on the ECHOED expression, not on the string anywhere in the file: the
        // docblock above quotes the old one to explain the bug it was.
        $this->assertMatchesRegularExpression('/echo esc_textarea\(\$raw\);/', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/echo esc_textarea\(\$raw \?:/',
            $src,
            "the `?: ''` fallback is the bug: it blanks the pane for the stored string '0'"
        );
    }

    public function testTheEditorShipsTheIntegrityPayloadUnderTheKeyTheClientReads(): void
    {
        $php = file_get_contents(dirname(__DIR__) . '/lib/admin.php');
        $js  = file_get_contents(dirname(__DIR__) . '/assets/js/pp-admin-editor.js');

        $this->assertMatchesRegularExpression(
            "/'compositionIntegrity' *=> *pp_composition_editor_integrity\(\\\$post_id/",
            $php,
            'the classification must actually be localized to the editor'
        );
        $this->assertStringContainsString(
            'ppAdminEditor.compositionIntegrity',
            $js,
            'and the client must read it under that same key'
        );
    }

    // ── The integrity payload ─────────────────────────────────────────────

    public function testAReadablePageShipsNoIntegrityPayload(): void
    {
        $this->seed(10, '[{"component":"hero"}]');
        $this->assertNull(pp_composition_editor_integrity(10));

        $this->seed(11, '[]');
        $this->assertNull(pp_composition_editor_integrity(11), 'a blank page is readable, not corrupt');

        $this->seed(12, null);
        $this->assertNull(pp_composition_editor_integrity(12));
    }

    /**
     * @dataProvider corruptRows
     */
    public function testACorruptPageShipsTheClassificationAndBothSharedSentences($stored, string $classification): void
    {
        $this->seed(13, $stored);
        $payload = pp_composition_editor_integrity(13);

        $this->assertIsArray($payload);
        $this->assertSame($classification, $payload['error']);
        // Ruling R-C: the diagnosis and the route are the shared owners' words, verbatim.
        // A caller-local rewrite of either — a fifth spelling of one state — fails here.
        $this->assertSame(pp_composition_integrity_message(13, $classification), $payload['message']);
        $this->assertStringContainsString(pp_corrupt_repair_route_message(13), $payload['repair']);
    }

    /**
     * The lead-in is the only local part, and it has one job: say that THIS surface is a
     * repair surface, because the shared route names it in the third person ("the dashboard
     * composition editor does the same job") to an operator who is already standing in it.
     */
    public function testTheRepairSentenceNamesTheInSurfaceAction(): void
    {
        $this->seed(14, '{"1":{"component":"hero"}}');
        $payload = pp_composition_editor_integrity(14);

        $this->assertStringContainsString('replace the JSON below', $payload['repair']);
        $this->assertStringContainsString('save', $payload['repair']);
    }

    /**
     * And withdraws it for the author who cannot act on it. With syntax highlighting off in
     * the WordPress profile there is no CodeMirror instance, and the editor's Save/Publish
     * handlers return early without one — so "fix it here and save" would be a new lie
     * standing where this change just removed an old one.
     */
    public function testTheRepairSentenceDoesNotPromiseASaveThatCannotRun(): void
    {
        $this->seed(15, '{"1":{"component":"hero"}}');
        $payload = pp_composition_editor_integrity(15, false);

        $this->assertStringNotContainsString('replace the JSON below', $payload['repair']);
        $this->assertStringContainsString('syntax highlighting', $payload['repair']);
        // The shared route still ships: the repair is reachable, just not from here.
        $this->assertStringContainsString(pp_corrupt_repair_route_message(15), $payload['repair']);
    }

    public static function corruptRows(): array
    {
        return [
            'json object'       => ['{"1":{"component":"hero"}}', 'unexpected_shape'],
            'undecodable json'  => ['not json at{', 'decode_error'],
            'json scalar zero'  => ['0', 'unexpected_shape'],
            'non-string scalar' => [5, 'unexpected_shape'],
            'php array map'     => [['1' => ['component' => 'hero']], 'unexpected_shape'],
        ];
    }
}
