<?php
/**
 * tests/TypedInputRefusalTest.php
 *
 * Untyped input is refused by name before it reaches typed code (#913, #931, #935).
 *
 * ONE CLASS, THREE SURFACES. In each, a presence or allowlist check let a value of the
 * wrong type through, and the typed PHP behind it fataled with a TypeError instead of the
 * surface answering with its own refusal:
 *
 *   #931  pp_validate_action() (and pp_validate_apply()) exempted null from the type check,
 *         so a REQUIRED param sent as null reached a typed executor. Measured on main: at
 *         least 21 required params across the registry threw. In a batch the throw escaped the step
 *         loop before the rollback, so earlier steps stayed applied.
 *   #913  _pp_validate_seo_meta() judged keys, not values, and strlen() fataled on an array
 *         under an allowed key.
 *   #935  the editor AJAX endpoints guarded `composition` with isset() only, and
 *         stripslashes() fataled on `composition[]=x`.
 *
 * WHAT STAYS AS IT WAS, pinned here beside the refusals:
 *   - an OPTIONAL param sent as null still means "not sent" (§1);
 *   - the batch rollback's restore door still writes back a stored non-string (#875, §2);
 *   - a raw-written null seo_title at RENDER still falls back to the core title with an
 *     E_DEPRECATED notice (#914, §3) — this change is write-side only.
 *
 * Sections: §1 the param gate (#931), §2 SEO meta values (#913), §3 the #914 render pin,
 * §4 the editor endpoints (#935).
 */

use PHPUnit\Framework\TestCase;

class TypedInputRefusalTest extends TestCase
{
    /** Every non-string shape a JSON body or a raw meta row can carry. */
    private const NON_STRINGS = [null, 0, 42, 1.5, true, false, [], ['a', 'b'], ['k' => 'v']];

    private const SEO_KEYS = ['meta_description', 'seo_title', 'canonical_url', 'og_title', 'twitter_title'];

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

    private function page(string $title = 'Original title'): int
    {
        $id = pp_create_page($title, 'publish');
        pp_update_composition($id, [['component' => 'section', 'props' => ['title' => 'T', 'body' => 'B']]]);
        return $id;
    }

    /** A value of the declared type that passes the STRUCTURAL check (semantics may still refuse). */
    private function typed(string $type, int $page)
    {
        switch ($type) {
            case 'int':   return $page;
            case 'array': return [];
            case 'bool':  return true;
            default:      return 'x';
        }
    }

    /**
     * Every required param of a registry, each sent as null with the others well-typed.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: array}>  [name, param, type, params]
     */
    private function requiredNullCases(array $registry, int $page): array
    {
        $cases = [];
        foreach ($registry as $name => $def) {
            foreach (($def['params'] ?? []) as $param => $param_def) {
                if (empty($param_def['required'])) {
                    continue;
                }
                $params = [];
                foreach ($def['params'] as $other => $other_def) {
                    if (!empty($other_def['required'])) {
                        $params[$other] = $this->typed($other_def['type'] ?? 'string', $page);
                    }
                }
                $params[$param] = null;
                $cases[] = [$name, $param, $param_def['type'] ?? 'string', $params];
            }
        }
        return $cases;
    }

    // ── §1. The param gate (#931) ───────────────────────────────────────────

    /**
     * THE WHOLE REGISTRY, not the one action #931 was found on. With only the required
     * params filled, main threw a TypeError for 19 of these (post_id on most page actions,
     * plus title, slug, from, meta, composition and order); update_component and
     * style_component's post_id threw too once their optional params were realistic. Walking
     * the registry means an action registered later is covered without anyone remembering to
     * add it here.
     */
    public function testEveryRequiredActionParamSentAsNullIsRefusedByName(): void
    {
        $cases = $this->requiredNullCases(pp_get_registered_actions(), $this->page());
        $this->assertGreaterThan(30, count($cases), 'premise: the walk reaches the registry');

        foreach ($cases as [$name, $param, $type, $params]) {
            try {
                $result = pp_execute_action($name, $params);
            } catch (\Throwable $e) {
                $this->fail("{$name}.{$param} = null threw " . get_class($e) . ': ' . $e->getMessage());
            }
            $this->assertFalse($result['ok'], "{$name}.{$param} = null is refused");
            $this->assertSame('invalid_param_type', $result['error_code'], "{$name}.{$param}: by the type rule");
            $this->assertSame(
                sprintf('Param "%s" must be %s, got null. Action "%s" requires it, and null does not count as a value.', $param, $type, $name),
                $result['error'],
                "{$name}.{$param}: the message names the param, the type and the rule"
            );
        }
    }

    /**
     * The two post_id cases the minimal walk above cannot reach as fatals: with only the
     * required params, these actions refuse on a missing optional first. With realistic
     * optional params, both threw a TypeError on main.
     */
    public function testANullPostIdWithRealisticParamsIsRefusedByName(): void
    {
        $cases = [
            'update_component' => ['post_id' => null, 'component_index' => 0, 'props' => ['title' => 'Z']],
            'style_component'  => ['post_id' => null, 'component_index' => 0, 'recipe' => 'x'],
        ];
        foreach ($cases as $name => $params) {
            $result = pp_execute_action($name, $params);
            $this->assertFalse($result['ok'], "{$name}: refused");
            $this->assertSame('invalid_param_type', $result['error_code'], "{$name}: by the type rule");
        }
    }

    /** The same rule on the apply registry, whose gate carried the identical line. */
    public function testEveryRequiredApplyParamSentAsNullIsRefusedByName(): void
    {
        $cases = $this->requiredNullCases(pp_get_registered_applies(), $this->page());
        $this->assertNotEmpty($cases, 'premise: applies declare required params');

        foreach ($cases as [$name, $param, $type, $params]) {
            $result = pp_execute_apply($name, $params);
            $this->assertFalse($result['ok'], "{$name}.{$param} = null is refused");
            $this->assertSame(
                sprintf('Param "%s" must be %s, got null. Apply "%s" requires it, and null does not count as a value.', $param, $type, $name),
                $result['error'],
                "{$name}.{$param}: refused by the type rule, before the apply's own validator"
            );
        }
    }

    /**
     * THE APPLY GATE KEEPS THE OPTIONAL-null CONTRACT TOO. The action-side twin is pinned
     * below; this pins the apply gate's own copy of the `required` condition, which the
     * registry walk above cannot reach (it only ever sends nulls to required params).
     *
     * Value: protects=pp_validate_apply treats an OPTIONAL null param as not sent (enqueue_font family/apply_to);
     * fails_when=the apply gate's null refusal drops its `required` condition and refuses every null;
     * why_new=only required-null applies and optional-null ACTIONS were pinned; the apply copy was not;
     * seam=none
     */
    public function testAnOptionalApplyParamSentAsNullStillMeansNotSent(): void
    {
        $result = pp_validate_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Inter',
            'family'   => null,
            'apply_to' => null,
        ]);
        $this->assertTrue($result, 'optional family/apply_to null are absent, not refused');
    }

    /** The issue's repro, end to end: nothing written, and preview answers the same way. */
    public function testTheIssueReproIsRefusedAndPreviewAgrees(): void
    {
        $id = $this->page('Real title');

        $result = pp_execute_action('update_page_title', ['post_id' => $id, 'title' => null]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_param_type', $result['error_code']);
        $this->assertSame('Real title', get_the_title($id), 'nothing was written');

        $preview = pp_preview_action('update_page_title', ['post_id' => $id, 'title' => null]);
        $this->assertInstanceOf('WP_Error', $preview, 'preview refuses what execute refuses');
        $this->assertSame('invalid_param_type', $preview->get_error_code());
    }

    /**
     * AN OPTIONAL null STILL MEANS "NOT SENT". That is a contract: expected_version null is
     * an unconditional write, and the batch executor and CLI lean on it. Tightening the gate
     * for every param, not just required ones, would turn these into refusals.
     */
    public function testAnOptionalParamSentAsNullStillMeansNotSent(): void
    {
        $id = $this->page();

        $write = pp_execute_action('update_composition', [
            'post_id'          => $id,
            'composition'      => [['component' => 'section', 'props' => ['title' => 'New', 'body' => 'B']]],
            'expected_version' => null,
        ]);
        $this->assertTrue($write['ok'], 'expected_version null is an unconditional write');

        $add = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'Added', 'body' => 'B'],
            'position'  => null,
            'udc'       => null,
        ]);
        $this->assertTrue($add['ok'], 'optional position/udc null are absent, not refused');

        $page = pp_execute_action('create_page', ['title' => 'Fresh', 'status' => null, 'slug' => null]);
        $this->assertTrue($page['ok'], 'create_page optional nulls are absent');
    }

    /**
     * THE BATCH ANGLE, the deepest of the three. On main the null step's TypeError escaped
     * pp_ai_execute_batch() before its rollback, so step 0's retitle stayed applied and the
     * caller got a 500. Now the null step is an ordinary validate-stage refusal: it fails
     * at its own turn, BEFORE its executor runs, and the existing rollback undoes step 0.
     */
    public function testABatchStepWithANullRequiredParamRollsBackTheStepsBeforeIt(): void
    {
        $id = $this->page('Before the batch');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'Changed by step 0']],
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => null]],
        ]);

        $this->assertTrue($batch['steps'][0]['ok'], 'premise: step 0 really landed');
        $this->assertFalse($batch['ok'], 'the batch failed');
        $this->assertSame(1, $batch['failed_at'], 'at the null step');
        $this->assertSame('invalid_param_type', $batch['steps'][1]['error_code'], 'refused by name');
        $this->assertTrue($batch['rolled_back'], 'and rolled back');
        $this->assertSame([], $batch['rollback_errors'], 'cleanly');
        $this->assertSame('Before the batch', get_the_title($id), 'step 0 is undone');
    }

    // ── §2. SEO meta values (#913) ──────────────────────────────────────────

    /** The issue's repro, against the validator itself: it answers instead of fataling. */
    public function testTheValidatorAnswersANonStringInsteadOfFataling(): void
    {
        $result = _pp_validate_seo_meta(['seo_title' => ['a', 'b']]);
        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('invalid_seo_meta_type', $result->get_error_code());
        $this->assertSame(
            'SEO meta "seo_title" must be a string, got array. Send an empty string to clear it.',
            $result->get_error_message()
        );
    }

    /**
     * Through the real action (Section 14.1), every key, every non-string shape: refused by
     * name, and nothing written. Before #913, arrays/objects fataled on the capped keys,
     * and null/int/bool were stored as-is.
     */
    public function testEveryNonStringUnderEveryKeyIsRefusedThroughTheAction(): void
    {
        $id = $this->page();
        pp_update_seo_meta($id, ['seo_title' => 'Kept', 'canonical_url' => 'https://example.com/kept']);
        $before = pp_get_seo_meta($id);

        foreach (self::SEO_KEYS as $key) {
            foreach (self::NON_STRINGS as $value) {
                $label = $key . ' = ' . var_export($value, true);
                $result = pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => [$key => $value]]);
                $this->assertFalse($result['ok'], "{$label} is refused");
                $this->assertSame('invalid_seo_meta_type', $result['error_code'], $label);
                $this->assertStringContainsString(
                    sprintf('SEO meta "%s" must be a string, got %s.', $key, get_debug_type($value)),
                    $result['error'],
                    $label
                );
                $this->assertSame($before, pp_get_seo_meta($id), "{$label}: nothing written");
            }
        }
    }

    /** The accept side, so a refusal for an unrelated reason cannot read as a pass. */
    public function testStringsAndTheEmptyStringClearStillLand(): void
    {
        $id = $this->page();
        $meta = [
            'meta_description' => 'A description',
            'seo_title'        => 'A title',
            'canonical_url'    => 'https://example.com/a',
            'og_title'         => 'OG',
            'twitter_title'    => 'TW',
        ];
        $this->assertTrue(pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => $meta])['ok']);
        $this->assertSame($meta, pp_get_seo_meta($id));

        $this->assertTrue(pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['seo_title' => '']])['ok']);
        $this->assertSame('', pp_get_seo_meta($id)['seo_title'], 'the empty string still clears');
    }

    /**
     * THE #875 RESTORE DOOR IS UNTOUCHED. The type rule is a VALUE rule and sits below the
     * bypass, so a restore puts back a non-string the row already held, under every key,
     * while the forward writer refuses the same bytes.
     */
    public function testTheRestoreDoorStillWritesAStoredNonStringUnderEveryKey(): void
    {
        $id = $this->page();
        $stored = [
            'meta_description' => 42,
            'seo_title'        => ['a', 'b'],
            'canonical_url'    => null,
            'og_title'         => true,
            'twitter_title'    => ['k' => 'v'],
        ];

        $forward = pp_update_seo_meta($id, $stored);
        $this->assertInstanceOf('WP_Error', $forward, 'the forward writer refuses');
        $this->assertSame('invalid_seo_meta_type', $forward->get_error_code());

        $this->assertTrue(_pp_write_seo_meta($id, $stored, true), 'the restore door writes');
        $this->assertSame($stored, pp_get_seo_meta($id), 'verbatim');
    }

    /** The same through the real batch rollback, on the key that used to fatal strlen(). */
    public function testTheBatchRollbackRestoresARawStoredArraySeoTitle(): void
    {
        $id = $this->page();
        update_post_meta($id, '_pp_seo_meta', wp_json_encode(['seo_title' => ['a', 'b']]));

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_seo_meta', 'params' => ['post_id' => $id, 'meta' => ['seo_title' => 'New']]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['steps'][0]['ok'], 'premise: the forward step landed');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors'], 'the restore did not refuse the stored array');
        $this->assertSame(['a', 'b'], pp_get_seo_meta($id)['seo_title'], 'and put it back');
    }

    // ── §3. The #914 render pin ──────────────────────────────────────────────

    /**
     * A RAW-WRITTEN null seo_title AT RENDER: the core title, with an E_DEPRECATED notice.
     * #914 recorded this as the post-fix behaviour (strip_tags(null) is a deprecated
     * coercion to '', and a value with no visible text falls back). #913 changes what the
     * WRITE path accepts; it must not change this. If the notice disappears, someone
     * changed the render path and should say so.
     */
    public function testARawNullSeoTitleRendersTheCoreTitleWithADeprecationNotice(): void
    {
        $id = $this->page();
        update_post_meta($id, '_pp_seo_meta', '{"seo_title":null}');
        $this->assertNull(pp_get_seo_meta($id)['seo_title'], 'premise: the null round-trips out of the store');

        $GLOBALS['_pp_test_store']['is_singular']       = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;

        $notices = [];
        set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
            $notices[] = $msg;
            return true;
        }, E_DEPRECATED);
        try {
            $title = pp_seo_document_title_override('Core assembled title');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('Core assembled title', $title, 'the core title, not a TypeError');
        $this->assertCount(1, $notices, 'exactly one deprecation');
        $this->assertStringContainsString('strip_tags()', $notices[0]);
        $this->assertStringContainsString('null', $notices[0]);
    }

    // ── §4. The editor endpoints (#935) ─────────────────────────────────────

    /** The issue's repro through the real save handler: the refusal, and no write. */
    public function testTheEditorSaveRefusesANonStringCompositionWithoutWriting(): void
    {
        $id = $this->page();
        $before = pp_get_composition($id);

        foreach ([['a'], ['k' => 'v'], [['component' => 'section']]] as $body) {
            $resp = _pp_save_composition_response(['post_id' => $id, 'nonce' => 'x', 'composition' => $body]);
            $this->assertSame(['ok' => false, 'data' => 'Invalid JSON.'], $resp,
                'the same refusal every other malformed body gets');
        }
        $this->assertSame($before, pp_get_composition($id), 'nothing was written');
    }

    /**
     * The reader the save, preview and publish handlers share. NULL is "sent, but not text",
     * kept apart from the caller's absent default: publish reads absent as "nothing to save",
     * and a malformed field must refuse there, never publish without saving.
     */
    public function testTheEditorCompositionFieldReaderSeparatesAbsentFromMalformed(): void
    {
        $this->assertSame('[]', _pp_editor_composition_field([], '[]'), 'absent: the caller default');
        $this->assertSame('', _pp_editor_composition_field([], ''), 'absent: the caller default');
        $this->assertSame('[{"a":"b\"c"}]', _pp_editor_composition_field(['composition' => '[{"a":"b\\\\\"c"}]'], ''),
            'a string is unslashed exactly as before');
        $this->assertSame('', _pp_editor_composition_field(['composition' => ''], '[]'), 'an empty string is a string');
        foreach ([['x'], ['k' => 'v'], 42, true] as $bad) {
            $this->assertNull(_pp_editor_composition_field(['composition' => $bad], ''),
                var_export($bad, true) . ' is malformed, not absent');
        }
    }
}
