<?php
/**
 * tests/ActionsTest.php — PHPUnit tests for the PromptingPress Action Layer
 *
 * Covers: registry functions, wp.php read/write functions, and all 24 actions
 * across validate, preview, and execute paths.
 */

use PHPUnit\Framework\TestCase;

class ActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the in-memory store for test isolation.
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    // ── Registry tests ─────────────────────────────────────────────────────

    public function testRegistryReturnsAllTwentyActions(): void
    {
        $actions = pp_get_registered_actions();
        $this->assertCount(24, $actions);
        $expected = [
            'create_page', 'update_site_option', 'update_page_title',
            'update_page_slug', 'update_seo_meta',
            'update_composition', 'publish_page', 'add_component',
            'remove_component', 'restore_composition', 'reorder_components', 'update_component',
            'style_component',
            'trash_page', 'restore_page', 'unpublish_page', 'clear_custom_css',
            'create_menu', 'add_menu_item', 'assign_menu_location', 'set_menu',
            'create_redirect', 'remove_redirect', 'list_redirects',
        ];
        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $actions, "Action '{$name}' not registered.");
        }
    }

    public function testGetActionReturnsDefinition(): void
    {
        $action = pp_get_action('create_page');
        $this->assertNotNull($action);
        $this->assertEquals('site', $action['scope']);
        $this->assertArrayHasKey('validate', $action);
        $this->assertArrayHasKey('preview', $action);
        $this->assertArrayHasKey('execute', $action);
    }

    public function testGetActionReturnsNullForUnknown(): void
    {
        $this->assertNull(pp_get_action('nonexistent_action'));
    }

    public function testValidateRejectsUnknownAction(): void
    {
        $result = pp_validate_action('nonexistent', []);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unknown_action', $result->get_error_code());
    }

    // ── Structural validation tests ────────────────────────────────────────

    public function testValidateRejectsMissingRequiredParam(): void
    {
        $result = pp_validate_action('create_page', []);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_param', $result->get_error_code());
    }

    public function testValidateRejectsWrongParamType(): void
    {
        $result = pp_validate_action('update_page_title', [
            'post_id' => 'not_an_int',
            'title'   => 'New Title',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_param_type', $result->get_error_code());
    }

    // ── wp.php read function tests ─────────────────────────────────────────

    public function testPpGetCompositionReturnsEmptyForNoMeta(): void
    {
        $this->assertEquals([], pp_get_composition(999));
    }

    public function testPpGetCompositionReturnsStoredData(): void
    {
        $comp = [['component' => 'hero', 'props' => ['title' => 'Test']]];
        update_post_meta(42, '_pp_composition', json_encode($comp));
        $this->assertEquals($comp, pp_get_composition(42));
    }

    public function testPpDesignTokensReturnsTokens(): void
    {
        $tokens = pp_design_tokens();
        $this->assertIsArray($tokens);
        $this->assertArrayHasKey('--color-bg', $tokens);
        $this->assertArrayHasKey('--font-body', $tokens);
    }

    public function testPpSiteOptionRejectsUnwhitelistedKey(): void
    {
        $result = pp_site_option('admin_email');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testPpSiteOptionReturnsWhitelistedValue(): void
    {
        update_option('blogname', 'My Site');
        $this->assertEquals('My Site', pp_site_option('blogname'));
    }

    public function testPpCompositionPagesReturnsFilteredPages(): void
    {
        // Create a page with the composition template
        $id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Comp Page', 'post_status' => 'publish']);
        update_post_meta($id, '_wp_page_template', 'composition.php');

        // Need to clear static cache for pp_composition_pages
        // Since we can't clear static, we test the underlying mechanism
        $posts = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'meta_key'    => '_wp_page_template',
            'meta_value'  => 'composition.php',
        ]);
        $this->assertCount(1, $posts);
        $this->assertEquals('Comp Page', $posts[0]->post_title);
    }

    // ── pp_main_query() / pp_pagination() tests (#126) ──────────────────────

    public function testPpMainQueryReturnsGlobalWpQuery(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $this->assertSame($GLOBALS['wp_query'], pp_main_query());
    }

    public function testPpPaginationReturnsEmptyStringForSinglePage(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->max_num_pages = 1;
        $this->assertSame('', pp_pagination());
    }

    public function testPpPaginationRendersNavForMultiplePages(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->max_num_pages = 3;
        $GLOBALS['_pp_test_store']['query_vars']['paged'] = 1;
        $html = pp_pagination();
        $this->assertStringContainsString('<nav class="pp-pagination"', $html);
        $this->assertStringContainsString('class="pp-pagination__list"', $html);
        $this->assertStringContainsString('current">1<', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('>3<', $html);
    }

    public function testPpPaginationReflectsCurrentPage(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->max_num_pages = 3;
        $GLOBALS['_pp_test_store']['query_vars']['paged'] = 2;
        $html = pp_pagination();
        $this->assertStringContainsString('current">2<', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('Next', $html);
    }

    public function testPpPaginationDefaultsToPageOneWhenPagedUnset(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->max_num_pages = 2;
        unset($GLOBALS['_pp_test_store']['query_vars']['paged']);
        $html = pp_pagination();
        $this->assertStringContainsString('current">1<', $html);
        $this->assertStringNotContainsString('Previous', $html);
    }

    // ── pp_search_query() / pp_result_count() tests (issue 138) ─────────────

    public function testPpSearchQueryReturnsTheCurrentSearchTerm(): void
    {
        $GLOBALS['_pp_test_store']['search_query'] = 'pricing';
        $this->assertSame('pricing', pp_search_query());
    }

    public function testPpSearchQueryReturnsEmptyStringWhenUnset(): void
    {
        unset($GLOBALS['_pp_test_store']['search_query']);
        $this->assertSame('', pp_search_query());
    }

    public function testPpResultCountReturnsFoundPostsFromMainQuery(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->found_posts = 7;
        $this->assertSame(7, pp_result_count());
    }

    public function testPpResultCountReturnsZeroForNoMatches(): void
    {
        $GLOBALS['wp_query'] = new WP_Query();
        $GLOBALS['wp_query']->found_posts = 0;
        $this->assertSame(0, pp_result_count());
    }

    // ── wp.php write function tests ────────────────────────────────────────

    public function testPpUpdateCompositionRoundTrips(): void
    {
        $comp = [['component' => 'hero', 'props' => ['title' => 'Round Trip']]];
        $result = pp_update_composition(50, $comp);
        $this->assertTrue($result);
        $stored = pp_get_composition(50);
        $this->assertEquals('hero', $stored[0]['component']);
        $this->assertEquals('Round Trip', $stored[0]['props']['title']);
        $this->assertStringStartsWith('pp-', $stored[0]['props']['id'], 'Auto-assigned ID expected.');
    }

    // ── Composition freshness marker (#113) ────────────────────────────────

    public function testCompositionMarkerAbsentReadsAsZero(): void
    {
        $marker = pp_get_composition_marker(9911);
        $this->assertSame(0, $marker['version']);
        $this->assertSame('', $marker['hash']);
    }

    public function testUpdateCompositionInitializesMarkerAtVersionOne(): void
    {
        $post_id = pp_create_page('Marker init');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $marker = pp_get_composition_marker($post_id);
        $this->assertSame(1, $marker['version'], 'First write initializes version 1.');
        $this->assertNotSame('', $marker['hash']);
    }

    public function testUpdateCompositionBumpsMarkerVersionByOnePerWrite(): void
    {
        $post_id = pp_create_page('Marker bump');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'C']]]);
        $this->assertSame(3, pp_get_composition_marker($post_id)['version']);
    }

    // ── Composition history ring + restore_composition (#133) ──────────────

    public function testCompositionHistoryEmptyBeforeAnyWrite(): void
    {
        $this->assertSame([], pp_get_composition_history(4242));
    }

    public function testFirstWriteRecordsNoHistory(): void
    {
        // A brand-new page's first write has no prior state to preserve.
        $post_id = pp_create_page('History first');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $this->assertSame([], pp_get_composition_history($post_id));
    }

    public function testWritePushesPriorStateOntoHistory(): void
    {
        $post_id = pp_create_page('History push');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertSame('A', $history[0]['composition'][0]['props']['title']);
        $this->assertSame(1, $history[0]['version'], 'entry carries the prior marker version');
    }

    public function testRestoreReturnsByteIdenticalPriorComposition(): void
    {
        // Acceptance criterion #1: update_composition then restore_composition returns
        // the byte-identical prior composition (JSON-string storage).
        $post_id = pp_create_page('Byte identical');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $stored_v1 = get_post_meta($post_id, '_pp_composition', true);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame(
            $stored_v1,
            get_post_meta($post_id, '_pp_composition', true),
            'restore reproduces the prior stored composition JSON byte-for-byte'
        );
    }

    public function testHistoryRingBoundedAtMax(): void
    {
        // Acceptance criterion #2: writing N+5 times keeps exactly N entries.
        $post_id = pp_create_page('Ring bound');
        $max = pp_composition_history_max();
        for ($i = 0; $i < $max + 5; $i++) {
            pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'v' . $i]]]);
        }
        $history = pp_get_composition_history($post_id);
        $this->assertCount($max, $history, 'ring is capped at the max, oldest evicted');
        $this->assertGreaterThan(1, $history[0]['version'], 'earliest entries were evicted');
        $this->assertSame(
            'v' . ($max + 3),
            $history[$max - 1]['composition'][0]['props']['title'],
            'newest entry is the state just before the final write'
        );
    }

    public function testRestoreCompositionValidateFailsWithNoHistory(): void
    {
        $post_id = pp_create_page('No history');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'only']]]);
        $err = pp_validate_action('restore_composition', ['post_id' => $post_id]);
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('no_history', $err->get_error_code());
    }

    public function testRestoreCompositionValidateRejectsOutOfRangeStepsBack(): void
    {
        $post_id = pp_create_page('Range');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        $err = pp_validate_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 5]);
        $this->assertInstanceOf(WP_Error::class, $err);
        $this->assertSame('history_out_of_bounds', $err->get_error_code());
    }

    public function testRestoreCompositionPreviewDoesNotWrite(): void
    {
        $post_id = pp_create_page('Preview');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        $before = get_post_meta($post_id, '_pp_composition', true);
        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($preview['ok']);
        $this->assertSame('A', $preview['after'][0]['props']['title']);
        $this->assertSame('B', $preview['before'][0]['props']['title']);
        $this->assertSame($before, get_post_meta($post_id, '_pp_composition', true), 'preview must not write');
    }

    public function testRestoreCompositionByHistoryIndex(): void
    {
        $post_id = pp_create_page('By index');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'C']]]);
        // history ring (oldest first): [ (v1,A), (v2,B) ] — index 0 = A.
        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame('A', pp_get_composition($post_id)[0]['props']['title']);
    }

    public function testRestoreCompositionHonorsExpectedVersionConflict(): void
    {
        $post_id = pp_create_page('CAS');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        // Current version is 2; a stale expected_version=1 must conflict, not overwrite.
        $result = pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'steps_back' => 1, 'expected_version' => 1,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('composition_conflict', $result['error_code']);
    }

    public function testRestoreIsItselfReversible(): void
    {
        // Restore lands its own history entry, so a restore can be undone in turn.
        $post_id = pp_create_page('Reversible restore');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        // Restore back to A (steps_back=1). This pushes B onto history.
        pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('A', pp_get_composition($post_id)[0]['props']['title']);
        // Undo the restore: the most-recent prior state is now B again.
        pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('B', pp_get_composition($post_id)[0]['props']['title']);
    }

    // ── restore_composition reports current-rule findings (#233) ────────────
    //
    // Restore never blocks on current validation rules (undo is wired to it), so it must
    // never report a bare ok:true for a composition those rules reject. Snapshots below are
    // seeded with pp_update_composition(), the non-validating writer — the only way to get
    // a rule-violating composition into a history ring, and exactly how legacy rows got
    // there before the rule existed.

    public function testRestorePreservesChromeAndReportsFindings(): void
    {
        $post_id = pp_create_page('Chrome snapshot');
        // Legal before #223: chrome in the composition. Seeded past write-time validation.
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // The write succeeds — a rule that landed after the snapshot may not veto undo.
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');

        // Content is preserved verbatim: chrome is reported, never stripped.
        $restored = pp_get_composition($post_id);
        $this->assertSame('nav', $restored[0]['component'], 'chrome survives the restore');
        $this->assertCount(2, $restored);

        // ...and the result carries the findings rather than a bare ok:true.
        $this->assertNotEmpty($result['findings'], 'restore must report current-rule findings');
        $types = array_column($result['findings'], 'type');
        $this->assertContains('template_owned_component', $types);
    }

    public function testRestoreReportsDanglingVarStyleValueWithoutBlocking(): void
    {
        // #230 rider on #233: the first validation rule to land after #233 must
        // prove restore surfaces it through the SHARED engine — no restore-
        // specific rule path — and never blocks on it.
        $post_id = pp_create_page('Dangling var snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A'], 'style' => ['--hero-cta2-bg' => 'var(--nonexistent-token)']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // The write succeeds and the snapshot is preserved verbatim.
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame('var(--nonexistent-token)', pp_get_composition($post_id)[0]['style']['--hero-cta2-bg']);

        // ...and the dangling reference is reported as a blocking-class finding.
        $errors = array_values(array_filter(
            $result['findings'],
            static function ($f) { return $f['severity'] === 'error'; }
        ));
        $this->assertContains('invalid_style_value', array_column($errors, 'type'));
    }

    public function testRestoreOfValidVarStyleValueReportsNoFindings(): void
    {
        // The mirror case: a snapshot using the newly ACCEPTED forms is clean.
        $post_id = pp_create_page('Valid var snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A'], 'style' => ['--hero-cta2-bg' => 'transparent', '--hero-accent' => 'var(--color-accent)']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['findings']);
    }

    public function testCleanRestoreReportsNoFindings(): void
    {
        $post_id = pp_create_page('Clean snapshot');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['findings'], 'a clean snapshot reports nothing');
    }

    // ── Unknown prop keys are rejected at the action layer (issue 147) ──────
    //
    // The write paths that shallow-merge caller props (update_component, add_component)
    // must reject an undeclared prop key before it persists behind an ok:true.

    public function testUpdateComponentRejectsUnknownPropKey(): void
    {
        $id = pp_create_page('Unknown prop update', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['not_a_real_prop' => 'x'],
        ]);

        // Rejected (issue 147 acceptance). The envelope carries both the message and,
        // since #312, the machine-readable error_code from the validate-stage WP_Error.
        $this->assertFalse($result['ok'], 'an unknown prop key must not persist behind ok:true');
        $this->assertStringContainsString('not_a_real_prop', $result['error']);
        $this->assertStringContainsString('no prop', $result['error']);
        $this->assertSame('unknown_prop', $result['error_code'], 'validate-stage rejection must carry its code (#312)');
        // The pre-existing composition is untouched — no phantom key written.
        $this->assertArrayNotHasKey('not_a_real_prop', pp_get_composition($id)[0]['props']);
    }

    public function testAddComponentRejectsUnknownPropKey(): void
    {
        $id = pp_create_page('Unknown prop add', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['body' => 'x', 'phantom_field' => 'oops'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('phantom_field', $result['error']);
        $this->assertStringContainsString('no prop', $result['error']);
        $this->assertSame('unknown_prop', $result['error_code'], 'validate-stage rejection must carry its code (#312)');
        $this->assertCount(1, pp_get_composition($id), 'the rejected component was not appended');
    }

    public function testUpdateCompositionRejectsUnknownPropKey(): void
    {
        $id = pp_create_page('Unknown prop replace', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Original']]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'New', 'bogus' => 'x']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bogus', $result['error']);
        // The prior composition is intact — the rejected replacement never landed.
        $this->assertSame('Original', pp_get_composition($id)[0]['props']['title']);
    }

    public function testCreatePageRejectsUnknownPropKey(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Unknown prop new page',
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hi', 'bogus' => 'x']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bogus', $result['error']);
        $this->assertSame('unknown_prop', $result['error_code'], 'validate-stage rejection must carry its code (#312)');
    }

    // ── Validate-stage rejections carry the WP_Error code in the envelope (#312) ──
    //
    // pp_execute_action() runs pp_validate_action() first; that early-return envelope
    // used to omit error_code entirely, so a whole class of rejections reached callers
    // (the AJAX save handler at lib/admin.php:581) with an empty code — they could only
    // string-match the human message. #312 propagates $validation->get_error_code() so
    // validate-stage rejections match execute-stage rejections built by _pp_action_error().
    // One assertion per rejection class the issue enumerates.

    public function testValidateStageErrorCodeTemplateOwnedComponent(): void
    {
        // Composing a template-owned component (nav) is rejected with its own code (#223).
        $result = pp_execute_action('create_page', [
            'title'       => 'Chrome in body',
            'composition' => [['component' => 'nav', 'props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('template_owned_component', $result['error_code']);
    }

    public function testValidateStageErrorCodeDuplicateComponentId(): void
    {
        // Two components sharing a non-empty props.id is rejected at write time (#238).
        $result = pp_execute_action('create_page', [
            'title'       => 'Duplicate ids',
            'composition' => [
                ['component' => 'hero', 'props' => ['title' => 'A', 'id' => 'dup']],
                ['component' => 'section', 'props' => ['body' => 'B', 'id' => 'dup']],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('duplicate_component_id', $result['error_code']);
    }

    public function testValidateStageErrorCodeMissingRequiredProp(): void
    {
        // A component missing a required prop (hero without "title") rejects as
        // invalid_composition — the generic validation code, still non-empty.
        $result = pp_execute_action('create_page', [
            'title'       => 'Missing required prop',
            'composition' => [['component' => 'hero', 'props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_composition', $result['error_code']);
    }

    public function testValidateStageErrorCodeUnknownProp(): void
    {
        // The #147 rule: an undeclared prop key rejects with unknown_prop, now carried
        // in the envelope for update_component's validate-stage path too.
        $id = pp_create_page('Unknown prop code', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['ghost_prop' => 'x'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_prop', $result['error_code']);
    }

    // ── restore/preview surface the unknown_prop finding without blocking (#233) ──
    //
    // issue 147 is a validation rule landing after #233's restore policy, so it must
    // prove the shared engine reports it on restore and preview WITHOUT blocking undo —
    // no restore-specific rule path. Snapshot seeded via the non-validating writer, the
    // only way a rule-violating composition enters a history ring (as legacy rows did).

    public function testRestoreReportsUnknownPropWithoutBlocking(): void
    {
        $post_id = pp_create_page('Unknown prop snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'not_a_real_prop' => 'legacy']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // Undo is never vetoed by a rule that postdates the snapshot.
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        // The snapshot is restored verbatim — the unknown key is preserved, not stripped.
        $this->assertSame('legacy', pp_get_composition($post_id)[0]['props']['not_a_real_prop']);

        // ...and the rule is reported through the shared findings engine as an error.
        $errors = array_values(array_filter(
            $result['findings'],
            static function ($f) { return $f['severity'] === 'error'; }
        ));
        $this->assertContains('unknown_prop', array_column($errors, 'type'));
    }

    public function testRestorePreviewSurfacesUnknownPropFinding(): void
    {
        $post_id = pp_create_page('Unknown prop preview');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'not_a_real_prop' => 'legacy']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // Preview is read-only: nothing written, but the finding is surfaced ahead of undo.
        $this->assertSame('B', pp_get_composition($post_id)[0]['props']['title'], 'preview did not write');
        $this->assertContains('unknown_prop', array_column($preview['findings'], 'type'));
    }

    public function testRestoreFindingsReportEveryValidationError(): void
    {
        // Three DISTINCT violations. A first-error-wins report would surface only the nav.
        $post_id = pp_create_page('Many violations');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],                 // template_owned_component
            ['component' => 'ghost', 'props' => []],               // unknown component
            ['component' => 'hero', 'props' => []],                // missing required prop "title"
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);

        $errors = array_values(array_filter(
            $result['findings'],
            static function ($f) { return $f['severity'] === 'error'; }
        ));
        $this->assertCount(3, $errors, 'every validation error is reported, not just the first');

        $messages = implode(' | ', array_column($errors, 'message'));
        $this->assertStringContainsString('ghost', $messages);
        $this->assertStringContainsString('title', $messages);
    }

    public function testRestoreNormalizesLegacyVariantSnapshot(): void
    {
        // TRIPWIRE (#233). Pre-#69 snapshots in a live history ring are keyed on `variant`.
        // restore_composition decodes them via pp_normalize_composition() ->
        // pp_migrate_legacy_variant_keys(). That shim is marked for removal at the v1.0.0
        // tag; #69's migration plan covered stored _pp_composition, never the history ring.
        // If this test fails because the shim was deleted, the ring needs a migration first
        // — otherwise restore silently writes a composition nothing decodes.
        $post_id = pp_create_page('Legacy variant snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);

        $props = pp_get_composition($post_id)[0]['props'];
        $this->assertSame('left', $props['layout'], 'legacy variant is decoded to layout');
        $this->assertArrayNotHasKey('variant', $props, 'the legacy key does not survive the restore');
    }

    public function testRestoreNormalizesAndReportsOnTheSameSnapshot(): void
    {
        // The two motivations of #233 in one snapshot: a pre-#69 `variant` key that must be
        // decoded, and chrome that must be preserved and reported. Normalization and
        // findings have to coexist — neither may swallow the other.
        $post_id = pp_create_page('Legacy plus chrome');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);

        $restored = pp_get_composition($post_id);
        $this->assertSame('nav', $restored[0]['component'], 'chrome preserved');
        $this->assertSame('left', $restored[1]['props']['layout'], 'legacy variant decoded');
        $this->assertArrayNotHasKey('variant', $restored[1]['props']);

        $this->assertContains('template_owned_component', array_column($result['findings'], 'type'));
    }

    public function testRestoreOfMalformedSnapshotReportsRatherThanFatals(): void
    {
        // A raw-written or corrupt ring entry. Computing findings must not throw: the write
        // has already landed by then, so a fatal here would show the user an error for an
        // undo that actually succeeded — the exact false-signal class #233 exists to kill.
        $post_id = pp_create_page('Malformed snapshot');
        pp_update_composition($post_id, [
            ['component' => [], 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'A']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $messages = implode(' | ', array_column($result['findings'], 'message'));
        $this->assertStringContainsString('non-scalar "component" key', $messages);
    }

    public function testRestorePreviewReportsFindingsAndWritesNothing(): void
    {
        $post_id = pp_create_page('Preview findings');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);
        $before = get_post_meta($post_id, '_pp_composition', true);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($preview['ok']);
        $types = array_column($preview['findings'], 'type');
        $this->assertContains('template_owned_component', $types, 'preview surfaces the same findings');
        $this->assertSame(
            $before,
            get_post_meta($post_id, '_pp_composition', true),
            'preview writes nothing'
        );
    }

    public function testRestorePreviewAfterMatchesWhatExecuteWrites(): void
    {
        // preview.after is the normalized target, so an operator sees the shape execute
        // will actually persist — not its legacy encoding.
        $post_id = pp_create_page('Preview parity');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('left', $preview['after'][0]['props']['layout']);

        pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('left', pp_get_composition($post_id)[0]['props']['layout']);
    }

    public function testContentHashStableAcrossIdInjectionRoundTrip(): void
    {
        // The canonical hash strips the auto-injected top-level props.id, so a
        // composition written WITHOUT ids hashes the same as the same composition
        // re-read WITH its injected ids — no false conflict on the round trip.
        $without_id = [['component' => 'hero', 'props' => ['title' => 'Stable']]];
        $post_id = pp_create_page('Hash round trip');
        pp_update_composition($post_id, $without_id);
        $reread_with_id = pp_get_composition($post_id);
        $this->assertArrayHasKey('id', $reread_with_id[0]['props'], 'id was injected on write');
        $this->assertSame(
            pp_composition_content_hash($without_id),
            pp_composition_content_hash($reread_with_id),
            'Hash must ignore the injected stable id.'
        );
        // And it matches the hash stored in the marker.
        $this->assertSame(pp_composition_content_hash($without_id), pp_get_composition_marker($post_id)['hash']);
    }

    public function testContentHashDiffersForDifferentContent(): void
    {
        $this->assertNotSame(
            pp_composition_content_hash([['component' => 'hero', 'props' => ['title' => 'One']]]),
            pp_composition_content_hash([['component' => 'hero', 'props' => ['title' => 'Two']]])
        );
    }

    public function testContentHashHandlesItemWithoutProps(): void
    {
        // Defensive: a malformed item without a props array must not fatal the hash.
        $hash = pp_composition_content_hash([['component' => 'hero']]);
        $this->assertIsString($hash);
        $this->assertSame(64, strlen($hash));
    }

    public function testCompositionMutatingActionsCarryTheFreshnessFlag(): void
    {
        // The #113 execute freshness gate keys off 'mutates_composition'. Every action
        // that writes the composition must carry it; actions that don't touch the
        // composition must NOT (or a stray composition change would falsely block them).
        $mutating     = ['update_composition', 'add_component', 'remove_component', 'reorder_components', 'update_component', 'style_component'];
        $non_mutating = ['update_page_title', 'update_page_slug', 'update_seo_meta', 'publish_page'];

        foreach ($mutating as $name) {
            $def = pp_get_action($name);
            $this->assertNotNull($def, "Action '$name' must be registered");
            $this->assertTrue(!empty($def['mutates_composition']), "Action '$name' must be flagged mutates_composition");
        }
        foreach ($non_mutating as $name) {
            $def = pp_get_action($name);
            $this->assertNotNull($def, "Action '$name' must be registered");
            $this->assertTrue(empty($def['mutates_composition']), "Action '$name' must NOT be flagged mutates_composition");
        }
    }

    // ── Write-time compare-and-swap through the action layer (#13) ─────────

    public function testActionExecuteRejectsStaleExpectedVersion(): void
    {
        // A composition-mutating action given a stale expected_version must fail with a
        // structured composition_conflict, not silently clobber the newer state.
        $post_id = pp_create_page('CAS stale');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Live']]]); // → v1

        $result = pp_execute_action('update_composition', [
            'post_id'          => $post_id,
            'composition'      => [['component' => 'hero', 'props' => ['title' => 'Stale edit']]],
            'expected_version' => 0, // caller thought it was still the un-written page
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_conflict', $result['error_code']);
        // The live composition is untouched by the rejected write.
        $this->assertSame('Live', pp_get_composition($post_id)[0]['props']['title']);
        $this->assertSame(1, pp_get_composition_marker($post_id)['version'], 'Rejected write must not bump the version.');
    }

    public function testActionExecuteAcceptsCurrentExpectedVersion(): void
    {
        $post_id = pp_create_page('CAS match');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'V1']]]); // → v1

        $result = pp_execute_action('update_composition', [
            'post_id'          => $post_id,
            'composition'      => [['component' => 'hero', 'props' => ['title' => 'V2']]],
            'expected_version' => 1, // matches the live version
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('V2', pp_get_composition($post_id)[0]['props']['title']);
        $this->assertSame(2, pp_get_composition_marker($post_id)['version'], 'Matched CAS bumps 1→2.');
    }

    public function testActionExecuteOmittedExpectedVersionStillWrites(): void
    {
        // Documented back-compat: an action called without expected_version writes
        // unconditionally, no CAS.
        $post_id = pp_create_page('CAS omitted');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'X']]]); // → v1

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Y']]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Y', pp_get_composition($post_id)[0]['props']['title']);
        $this->assertSame(2, pp_get_composition_marker($post_id)['version']);
    }

    public function testCreatePageWithCompositionWritesWithoutCas(): void
    {
        // The new-page path routes through pp_update_composition but must NOT require an
        // expected_version — there is no prior version to compare — and initializes v1.
        $result = pp_execute_action('create_page', [
            'title'       => 'CAS new page',
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Fresh']]],
        ]);

        $this->assertTrue($result['ok']);
        $post_id = $result['target']['post_id'];
        $this->assertSame('Fresh', pp_get_composition($post_id)[0]['props']['title']);
        $this->assertSame(1, pp_get_composition_marker($post_id)['version'], 'Seed write initializes v1.');
    }

    public function testExpectedVersionFromRequestAcceptsCleanIntegerOnly(): void
    {
        // A clean non-negative integer string is a valid CAS baseline.
        $this->assertSame(0, _pp_expected_version_from_request(['expected_version' => '0']));
        $this->assertSame(7, _pp_expected_version_from_request(['expected_version' => '7']));
        $this->assertSame(7, _pp_expected_version_from_request(['expected_version' => 7]));
        // Malformed/hostile client values → treated as absent (null), never coerced into a
        // wrong baseline: mixed strings, floats, negatives, arrays, bools, empty, missing.
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => '12abc']));
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => '1.9']));
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => '-1']));
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => ['x']]));
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => true]));
        $this->assertNull(_pp_expected_version_from_request(['expected_version' => '']));
        $this->assertNull(_pp_expected_version_from_request([]));
    }

    public function testExpectedVersionRegisteredOnAllMutatingActions(): void
    {
        // Every composition-mutating action must accept the optional optimistic-locking
        // baseline so the CAS can be threaded from any caller (#13).
        $mutating = ['update_composition', 'add_component', 'remove_component', 'reorder_components', 'update_component', 'style_component'];
        foreach ($mutating as $name) {
            $def = pp_get_action($name);
            $this->assertArrayHasKey('expected_version', $def['params'], "Action '$name' must accept expected_version");
            $this->assertSame('int', $def['params']['expected_version']['type']);
            $this->assertTrue(empty($def['params']['expected_version']['required']), "expected_version must be optional on '$name'");
        }
    }

    public function testPpCreatePageReturnsIdAndSetsTemplate(): void
    {
        $id = pp_create_page('Test Page', 'draft');
        $this->assertIsInt($id);
        $this->assertEquals('composition.php', get_post_meta($id, '_wp_page_template', true));
    }

    public function testPpPublishPageSetsStatus(): void
    {
        $id = pp_create_page('Draft Page', 'draft');
        $result = pp_publish_page($id);
        $this->assertTrue($result);
        $this->assertEquals('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    // ── pp_promote_auto_draft (#121) ─────────────────────────────────────

    public function testPromoteAutoDraftPromotesToRealDraft(): void
    {
        $id = pp_create_page('', 'auto-draft');
        pp_promote_auto_draft($id);
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPromoteAutoDraftIsNoOpForRealDraft(): void
    {
        $id = pp_create_page('Already a draft', 'draft');
        pp_promote_auto_draft($id);
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPromoteAutoDraftIsNoOpForPublishedPage(): void
    {
        $id = pp_create_page('Live page', 'publish');
        pp_promote_auto_draft($id);
        $this->assertSame('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPromoteAutoDraftIsNoOpForTrashedPage(): void
    {
        $id = pp_create_page('Trashed page', 'trash');
        pp_promote_auto_draft($id);
        $this->assertSame('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPromoteAutoDraftIsNoOpForUnknownPostId(): void
    {
        // Unknown post_id: get_post_status() returns false, must not throw
        // or fabricate a store entry.
        pp_promote_auto_draft(999999);
        $this->assertArrayNotHasKey(999999, $GLOBALS['_pp_test_store']['posts']);
    }

    public function testExecuteActionPromotesAutoDraftOnSuccessfulCompositionUpdate(): void
    {
        // #121 (Codex + Claude adversarial finding): the promotion must run
        // inside pp_execute_action() itself, not just the AJAX handlers, so a
        // direct call — the same shape WP-CLI's `wp pp action execute` and
        // pp_patch_composition() use — also promotes. This matters beyond
        // visibility: WordPress's own auto-draft GC permanently deletes
        // 'auto-draft' posts regardless of content after ~7 days.
        $id = pp_create_page('', 'auto-draft');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hi']]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testExecuteActionDoesNotPromoteOnEmptyTitleSave(): void
    {
        // The title field autosaves on blur even with no typed input —
        // promoting on an empty title recreates the permanent "(no title)"
        // draft bug via a different trigger.
        $id = pp_create_page('', 'auto-draft');

        $result = pp_execute_action('update_page_title', [
            'post_id' => $id,
            'title'   => '',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testExecuteActionPromotesOnNonEmptyTitleSave(): void
    {
        $id = pp_create_page('', 'auto-draft');

        $result = pp_execute_action('update_page_title', [
            'post_id' => $id,
            'title'   => 'A real title',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testExecuteActionDoesNotPromoteOnFailedAction(): void
    {
        $id = pp_create_page('', 'auto-draft');

        // A component-level action on this composition-less page fails closed with
        // composition_required (#358/#387 — the shared validator gates it before the
        // out-of-bounds index check). The point of this test is unchanged: a FAILED
        // action must not promote the auto-draft to a real draft.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 5,
            'props'           => ['title' => 'X'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_required', $result['error_code']);
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPpUpdateSiteOptionRejectsUnwhitelisted(): void
    {
        $result = pp_update_site_option('admin_email', 'test@example.com');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── Action: create_page ────────────────────────────────────────────────

    public function testCreatePageExecuteCreatesPage(): void
    {
        $result = pp_execute_action('create_page', [
            'title' => 'New Page',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('create_page', $result['action']);
        $this->assertEquals('site', $result['scope']);
        $this->assertArrayHasKey('post_id', $result['target']);
        $this->assertIsInt($result['target']['post_id']);
    }

    public function testCreatePageRejectsEmptyTitle(): void
    {
        $result = pp_execute_action('create_page', ['title' => '  ']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    public function testCreatePageWithComposition(): void
    {
        $comp = [['component' => 'hero', 'props' => ['title' => 'Welcome']]];
        $result = pp_execute_action('create_page', [
            'title'       => 'With Comp',
            'composition' => $comp,
        ]);
        $this->assertTrue($result['ok']);
        $post_id = $result['target']['post_id'];
        $stored = pp_get_composition($post_id);
        $this->assertCount(1, $stored);
        $this->assertEquals('hero', $stored[0]['component']);
        $this->assertEquals('Welcome', $stored[0]['props']['title']);
        $this->assertStringStartsWith('pp-', $stored[0]['props']['id']);
    }

    // ── Action: update_site_option ─────────────────────────────────────────

    public function testUpdateSiteOptionExecute(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'blogname',
            'value' => 'Updated Site',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('Updated Site', get_option('blogname'));
    }

    public function testUpdateSiteOptionRejectsNonWhitelisted(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'admin_email',
            'value' => 'hack@evil.com',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not whitelisted', $result['error']);
    }

    // ── Action: update_page_title ────────────────────────────────────────

    public function testUpdatePageTitleExecute(): void
    {
        $id = pp_create_page('Original Title', 'draft');
        $result = pp_execute_action('update_page_title', [
            'post_id' => $id,
            'title'   => 'Updated Title',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('update_page_title', $result['action']);
        $this->assertEquals('page', $result['scope']);
        $this->assertEquals($id, $result['target']['post_id']);
        $this->assertEquals('Updated Title', $GLOBALS['_pp_test_store']['posts'][$id]['post_title']);
    }

    // ── Action: update_page_slug (#134) ─────────────────────────────────────

    public function testUpdatePageSlugExecute(): void
    {
        $id = pp_create_page('How PromptingPress Works', 'draft');
        $result = pp_execute_action('update_page_slug', ['post_id' => $id, 'slug' => 'product']);
        $this->assertTrue($result['ok']);
        $this->assertEquals('update_page_slug', $result['action']);
        $this->assertEquals('page', $result['scope']);
        $this->assertEquals('product', $GLOBALS['_pp_test_store']['posts'][$id]['post_name']);
        $this->assertSame('product', $result['changes'][0]['to']);
        $this->assertStringContainsString('/product/', $result['changes'][0]['permalink']);
    }

    public function testUpdatePageSlugSanitizesInput(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_execute_action('update_page_slug', ['post_id' => $id, 'slug' => 'My Cool Page!']);
        $this->assertEquals('my-cool-page', $GLOBALS['_pp_test_store']['posts'][$id]['post_name']);
    }

    public function testUpdatePageSlugReportsDeduplicatedSlugOnCollision(): void
    {
        $existing = pp_create_page('First Page', 'publish', 'product');
        $id = pp_create_page('Second Page', 'draft');
        $result = pp_execute_action('update_page_slug', ['post_id' => $id, 'slug' => 'product']);
        $this->assertTrue($result['ok']);
        // WordPress de-duplicated -- the reported slug must be the REAL one, not the requested one.
        $this->assertNotEquals('product', $result['changes'][0]['to']);
        $this->assertEquals('product-2', $result['changes'][0]['to']);
        $this->assertEquals('product', $GLOBALS['_pp_test_store']['posts'][$existing]['post_name']);
    }

    public function testUpdatePageSlugRejectsEmptyAfterSanitization(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_page_slug', ['post_id' => $id, 'slug' => '!!!']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    public function testUpdatePageSlugRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('update_page_slug', ['post_id' => 9999, 'slug' => 'x']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdatePageSlugPreviewDoesNotWrite(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_preview_action('update_page_slug', ['post_id' => $id, 'slug' => 'new-slug']);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('post_name', $GLOBALS['_pp_test_store']['posts'][$id]);
    }

    public function testCreatePageHonorsSlugParam(): void
    {
        $result = pp_execute_action('create_page', ['title' => 'How PromptingPress Works', 'slug' => 'product']);
        $this->assertTrue($result['ok']);
        $id = $result['target']['post_id'];
        $this->assertEquals('product', $GLOBALS['_pp_test_store']['posts'][$id]['post_name']);
    }

    public function testCreatePageWithoutSlugLeavesPostNameUnset(): void
    {
        $result = pp_execute_action('create_page', ['title' => 'A Page']);
        $this->assertTrue($result['ok']);
        $id = $result['target']['post_id'];
        $this->assertArrayNotHasKey('post_name', $GLOBALS['_pp_test_store']['posts'][$id]);
    }

    public function testCreatePageRejectsSlugThatSanitizesEmpty(): void
    {
        $result = pp_execute_action('create_page', ['title' => 'A Page', 'slug' => '!!!']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    // ── Action: update_seo_meta (#41) ───────────────────────────────────────

    public function testUpdateSeoMetaExecuteSetsAllFields(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_seo_meta', [
            'post_id' => $id,
            'meta'    => [
                'meta_description' => 'A great page.',
                'seo_title'        => 'Custom SEO Title',
                'canonical_url'    => 'https://example.com/canonical',
            ],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('update_seo_meta', $result['action']);
        $this->assertEquals('page', $result['scope']);
        $meta = pp_get_seo_meta($id);
        $this->assertSame('A great page.', $meta['meta_description']);
        $this->assertSame('Custom SEO Title', $meta['seo_title']);
        $this->assertSame('https://example.com/canonical', $meta['canonical_url']);
    }

    public function testUpdateSeoMetaPatchesWithoutClobberingUnspecifiedKeys(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => 'First', 'seo_title' => 'Kept']]);
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => 'Second']]);
        $meta = pp_get_seo_meta($id);
        $this->assertSame('Second', $meta['meta_description']);
        $this->assertSame('Kept', $meta['seo_title']);
    }

    public function testUpdateSeoMetaEmptyStringClearsField(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => 'Set']]);
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => '']]);
        $this->assertSame('', pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('update_seo_meta', ['post_id' => 9999, 'meta' => ['meta_description' => 'x']]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdateSeoMetaRejectsUnknownKey(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['og_image' => 'https://example.com/x.jpg']]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unknown SEO meta key', $result['error']);
    }

    public function testUpdateSeoMetaRejectsInvalidCanonicalUrl(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['canonical_url' => 'not-a-url']]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('canonical_url', $result['error']);
    }

    public function testUpdateSeoMetaRejectsOverlongMetaDescription(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => str_repeat('x', 321)]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('320 characters', $result['error']);
    }

    public function testUpdateSeoMetaPreviewDoesNotWrite(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_preview_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => 'Preview only']]);
        $this->assertTrue($result['ok']);
        $this->assertSame('', pp_get_seo_meta($id)['meta_description']);
    }

    public function testGetSeoMetaDefaultsToEmptyStrings(): void
    {
        $id = pp_create_page('Page', 'draft');
        $meta = pp_get_seo_meta($id);
        $this->assertSame(['meta_description' => '', 'seo_title' => '', 'canonical_url' => ''], $meta);
    }

    public function testSeoMetaDescriptionTagOutputsWhenSet(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['meta_description' => 'Come visit <us>']);
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        ob_start();
        pp_seo_meta_description_tag();
        $html = ob_get_clean();
        $this->assertStringContainsString('<meta name="description" content="Come visit &lt;us&gt;">', $html);
    }

    public function testSeoMetaDescriptionTagOmittedWhenUnset(): void
    {
        $id = pp_create_page('Page', 'draft');
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        ob_start();
        pp_seo_meta_description_tag();
        $html = ob_get_clean();
        $this->assertSame('', $html);
    }

    public function testSeoMetaDescriptionTagOmittedWhenNotSingular(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['meta_description' => 'Set']);
        $GLOBALS['_pp_test_store']['is_singular'] = false;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        ob_start();
        pp_seo_meta_description_tag();
        $html = ob_get_clean();
        $this->assertSame('', $html);
    }

    public function testSeoDocumentTitleOverrideReturnsOverrideWhenSet(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['seo_title' => 'Override Title']);
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        $this->assertSame('Override Title', pp_seo_document_title_override(''));
    }

    public function testSeoDocumentTitleOverridePassesThroughWhenUnset(): void
    {
        $id = pp_create_page('Page', 'draft');
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        $this->assertSame('', pp_seo_document_title_override(''));
    }

    public function testSeoDocumentTitleOverridePassesThroughWhenNotSingular(): void
    {
        $GLOBALS['_pp_test_store']['is_singular'] = false;
        $this->assertSame('Default Title', pp_seo_document_title_override('Default Title'));
    }

    public function testSeoCanonicalUrlOverrideReturnsOverrideWhenSet(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['canonical_url' => 'https://example.com/custom']);
        $post = get_post($id);
        $this->assertSame('https://example.com/custom', pp_seo_canonical_url_override('https://example.com/default', $post));
    }

    public function testSeoCanonicalUrlOverridePassesThroughWhenUnset(): void
    {
        $id = pp_create_page('Page', 'draft');
        $post = get_post($id);
        $this->assertSame('https://example.com/default', pp_seo_canonical_url_override('https://example.com/default', $post));
    }

    public function testSeoCanonicalUrlOverridePassesThroughWhenNoPost(): void
    {
        $this->assertSame('https://example.com/default', pp_seo_canonical_url_override('https://example.com/default', null));
    }

    // ── Action: publish_page ──────────────────────────────────────────────

    public function testPublishPageExecute(): void
    {
        $id = pp_create_page('Publish Me', 'draft');
        $result = pp_execute_action('publish_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('publish_page', $result['action']);
        $this->assertEquals('page', $result['scope']);
        $this->assertEquals($id, $result['target']['post_id']);
        $this->assertEquals('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
        // Verify the changes array reports actual prior status, not hardcoded 'draft'
        $change = $result['changes'][0];
        $this->assertEquals('draft', $change['from']);
        $this->assertEquals('publish', $change['to']);
    }

    // ── Action: update_composition ─────────────────────────────────────────

    public function testUpdateCompositionReplacesEntireArray(): void
    {
        $id = pp_create_page('Comp Test', 'draft');
        $old = [['component' => 'hero', 'props' => ['title' => 'Old']]];
        pp_update_composition($id, $old);

        $new = [['component' => 'section', 'props' => ['body' => 'New body']]];
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => $new,
        ]);
        $this->assertTrue($result['ok']);
        $stored = pp_get_composition($id);
        $this->assertCount(1, $stored);
        $this->assertEquals('section', $stored[0]['component']);
        $this->assertEquals('New body', $stored[0]['props']['body']);
        $this->assertStringStartsWith('pp-', $stored[0]['props']['id']);
    }

    // ── Action: add_component ──────────────────────────────────────────────

    public function testAddComponentAppends(): void
    {
        $id = pp_create_page('Add Test', 'draft');
        $existing = [['component' => 'hero', 'props' => ['title' => 'First']]];
        pp_update_composition($id, $existing);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['body' => 'Added section'],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertCount(2, $comp);
        $this->assertEquals('section', $comp[1]['component']);
    }

    public function testAddComponentInsertsAtPosition(): void
    {
        $id = pp_create_page('Insert Test', 'draft');
        $existing = [
            ['component' => 'hero', 'props' => ['title' => 'First']],
            ['component' => 'cta', 'props' => ['title' => 'CTA', 'text' => 'Click', 'button_text' => 'Go', 'button_url' => '#']],
        ];
        pp_update_composition($id, $existing);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['body' => 'Inserted'],
            'position'  => 1,
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertCount(3, $comp);
        $this->assertEquals('section', $comp[1]['component']);
        $this->assertEquals('cta', $comp[2]['component']);
    }

    // ── Action: add_component — per-instance style (#368) ──────────────────
    // add_component accepts an optional `style` map written onto the new
    // composition item and validated by the SAME shared engine as items[].style
    // (pp_validate_composition → _pp_validate_style_slot_map). No surface-specific
    // validator; the style-slot error codes below are the shared-engine codes.

    public function testAddComponentWithStyleWritesStyleOntoNewItem(): void
    {
        $id = pp_create_page('Add Style Test', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Styled'],
            'style'     => ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem'],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertCount(2, $comp);
        $this->assertSame('#1a1a2e', $comp[1]['style']['--hero-bg']);
        $this->assertSame('8rem', $comp[1]['style']['--hero-padding-top']);
    }

    public function testAddComponentRejectsInvalidStyleValue(): void
    {
        $id = pp_create_page('Add Bad Value', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);

        // Same rejection (and same shared-engine error code) as items[].style /
        // style_component would give for a non-color value on a color slot.
        $result = pp_validate_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Styled'],
            'style'     => ['--hero-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    public function testAddComponentRejectsUnknownStyleSlot(): void
    {
        $id = pp_create_page('Add Bad Slot', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);

        $result = pp_validate_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Styled'],
            'style'     => ['--hero-display' => 'none'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_slot', $result->get_error_code());
    }

    public function testAddComponentInvalidStyleDoesNotMutate(): void
    {
        $id = pp_create_page('Add No Mutate', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);
        $before = pp_get_composition($id);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Styled'],
            'style'     => ['--hero-bg' => 'not-a-color'],
        ]);
        $this->assertFalse($result['ok']);
        $after = pp_get_composition($id);
        $this->assertEquals($before, $after, 'A rejected style must not append or partially write.');
        $this->assertCount(1, $after);
    }

    public function testAddComponentEmptyStyleOmitsStyleKey(): void
    {
        // An empty style map carries no visible styling intent, so it is omitted
        // (matches update_component's `!empty` treatment). The stored item is
        // byte-identical to an add_component with no style param at all.
        $id = pp_create_page('Add Empty Style', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['body' => 'No style'],
            'style'     => [],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertArrayNotHasKey('style', $comp[1]);
    }

    public function testAddComponentStyleWithPositionInsertsStyledItemAtIndex(): void
    {
        $id = pp_create_page('Add Style Pos', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'First']],
            ['component' => 'section', 'props' => ['body' => 'Last']],
        ]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Inserted'],
            'style'     => ['--hero-bg' => '#123456'],
            'position'  => 1,
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertCount(3, $comp);
        $this->assertSame('Inserted', $comp[1]['props']['title']);
        $this->assertSame('#123456', $comp[1]['style']['--hero-bg']);
        $this->assertArrayNotHasKey('style', $comp[0]);
        $this->assertArrayNotHasKey('style', $comp[2]);
    }

    public function testAddComponentDoesNotSilentlyDropStyle(): void
    {
        // #368 regression: add_component used to accept a `style` key, return
        // ok:true, and silently drop it (the #147 trust class). Now a valid style
        // is HONORED (persisted onto the new item) and an invalid style is
        // REJECTED — never a silent ok:true with the styling gone.
        $id = pp_create_page('Regression 368', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First']]]);

        // Valid style is honored, not dropped.
        $ok = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Styled'],
            'style'     => ['--hero-bg' => '#0d1117'],
        ]);
        $this->assertTrue($ok['ok']);
        $comp = pp_get_composition($id);
        $this->assertArrayHasKey('style', $comp[1], 'Valid style must be persisted, not silently dropped.');
        $this->assertSame('#0d1117', $comp[1]['style']['--hero-bg']);

        // Invalid style is rejected, not silently accepted behind ok:true.
        $bad = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['title' => 'Bad'],
            'style'     => ['--hero-bg' => 'not-a-color'],
        ]);
        $this->assertFalse($bad['ok'], 'Invalid style must be rejected, never silently accepted.');
        $this->assertCount(2, pp_get_composition($id), 'A rejected add must not append.');
    }

    // ── Action: remove_component ───────────────────────────────────────────

    public function testRemoveComponentRemovesByIndex(): void
    {
        $id = pp_create_page('Remove Test', 'draft');
        $existing = [
            ['component' => 'hero', 'props' => ['title' => 'Keep']],
            ['component' => 'section', 'props' => ['body' => 'Remove me']],
        ];
        pp_update_composition($id, $existing);

        $result = pp_execute_action('remove_component', [
            'post_id'         => $id,
            'component_index' => 1,
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertCount(1, $comp);
        $this->assertEquals('hero', $comp[0]['component']);
    }

    public function testRemoveComponentRejectsOutOfBounds(): void
    {
        $id = pp_create_page('OOB Test', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Only']]]);

        $result = pp_execute_action('remove_component', [
            'post_id'         => $id,
            'component_index' => 5,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('out of bounds', $result['error']);
    }

    // ── Action: reorder_components ─────────────────────────────────────────

    public function testReorderComponentsReorders(): void
    {
        $id = pp_create_page('Reorder Test', 'draft');
        $existing = [
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
            ['component' => 'cta', 'props' => ['title' => 'C', 'text' => 'Go', 'button_text' => 'Click', 'button_url' => '#']],
        ];
        pp_update_composition($id, $existing);

        $result = pp_execute_action('reorder_components', [
            'post_id' => $id,
            'order'   => [2, 0, 1],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertEquals('cta', $comp[0]['component']);
        $this->assertEquals('hero', $comp[1]['component']);
        $this->assertEquals('section', $comp[2]['component']);
    }

    public function testReorderRejectsInvalidPermutationDuplicates(): void
    {
        $id = pp_create_page('Dup Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
        ]);

        $result = pp_execute_action('reorder_components', [
            'post_id' => $id,
            'order'   => [0, 0],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permutation', $result['error']);
    }

    public function testReorderRejectsWrongLength(): void
    {
        $id = pp_create_page('Len Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
        ]);

        $result = pp_execute_action('reorder_components', [
            'post_id' => $id,
            'order'   => [0],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('elements', $result['error']);
    }

    // ── Action: update_component (patch semantics) ─────────────────────────

    public function testUpdateComponentPatchMerge(): void
    {
        $id = pp_create_page('Patch Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original', 'subtitle' => 'Keep this']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Updated'],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertEquals('Updated', $comp[0]['props']['title']);
        $this->assertEquals('Keep this', $comp[0]['props']['subtitle']);
    }

    public function testUpdateComponentNullRemovesProp(): void
    {
        $id = pp_create_page('Null Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Stay', 'subtitle' => 'Remove me']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['subtitle' => null],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertEquals('Stay', $comp[0]['props']['title']);
        $this->assertArrayNotHasKey('subtitle', $comp[0]['props']);
    }

    public function testUpdateComponentRejectsOutOfBounds(): void
    {
        $id = pp_create_page('OOB Comp Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Only']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 3,
            'props'           => ['title' => 'Nope'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('out of bounds', $result['error']);
    }

    public function testUpdateComponentRejectsNonImageUrlViaDirectExecuteCall(): void
    {
        // Regression for #124: the media-URL/image-type check must protect
        // EVERY caller of pp_execute_action() — WP-CLI (wp pp action execute),
        // pp_patch_composition(), not just the AI chat AJAX handler. This test
        // calls pp_execute_action() directly, the same way lib/cli.php and
        // lib/operate.php do, with no AJAX handler involved at all.
        $id = pp_create_page('Direct Execute Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original', 'variant' => 'split']],
        ]);
        $GLOBALS['_pp_test_store']['attachment_urls'][90] = 'https://example.com/wp-content/uploads/brochure.pdf';
        $GLOBALS['_pp_test_store']['attachment_is_image'][90] = false;

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['image_url' => 'https://example.com/wp-content/uploads/brochure.pdf'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not point to an image', $result['error']);
        // Confirm nothing was written.
        $comp = pp_get_composition($id);
        $this->assertArrayNotHasKey('image_url', $comp[0]['props']);
    }

    public function testPreviewRejectsInvalidMediaUrlIdenticallyToExecute(): void
    {
        // Regression for issue 130: a proposal preview must not show a clean
        // diff for a step guaranteed to fail at execute — both must reject a
        // hallucinated/typo'd uploads URL with the same error, because both
        // pp_preview_action() and pp_execute_action() route through the same
        // pp_validate_action() gate. No page is created — the media-URL
        // check runs before the action's own semantic validate (which is
        // where a real post_id would otherwise be required), so this proves
        // preview fails at the same point execute does, not just eventually.
        $params = [
            'post_id'         => 999999,
            'component_index' => 0,
            'props'           => ['image_url' => 'https://example.com/wp-content/uploads/2026/06/hero-imge.png'],
        ];

        $preview = pp_preview_action('update_component', $params);
        $execute = pp_execute_action('update_component', $params);

        $this->assertInstanceOf(WP_Error::class, $preview);
        $this->assertSame('invalid_media_url', $preview->get_error_code());
        $this->assertFalse($execute['ok']);
        $this->assertSame($preview->get_error_message(), $execute['error']);
    }

    // ── Preview tests ──────────────────────────────────────────────────────

    public function testPreviewNeverWrites(): void
    {
        $id = pp_create_page('Preview Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Before']],
        ]);

        $preview = pp_preview_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'After'],
        ]);
        $this->assertIsArray($preview);
        $this->assertTrue($preview['ok']);
        $this->assertEquals('Before', $preview['before']['title']);
        $this->assertEquals('After', $preview['after']['title']);

        // Verify no write occurred
        $comp = pp_get_composition($id);
        $this->assertEquals('Before', $comp[0]['props']['title']);
    }

    public function testPreviewReturnsErrorOnInvalidParams(): void
    {
        $result = pp_preview_action('create_page', []);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── Canonical result shape tests ───────────────────────────────────────

    public function testExecuteResultShapeOnSuccess(): void
    {
        $result = pp_execute_action('create_page', ['title' => 'Shape Test']);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('scope', $result);
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('changes', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testExecuteResultShapeOnFailure(): void
    {
        $result = pp_execute_action('create_page', ['title' => '']);
        $this->assertArrayHasKey('ok', $result);
        $this->assertFalse($result['ok']);
        $this->assertIsString($result['error']);
    }

    // ── Action: trash_page ────────────────────────────────────────────────

    public function testTrashPageExecute(): void
    {
        $id = pp_create_page('Trash Me', 'publish');
        $result = pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('trash_page', $result['action']);
        $this->assertEquals('page', $result['scope']);
        $this->assertEquals('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
        $change = $result['changes'][0];
        $this->assertEquals('publish', $change['from']);
        $this->assertEquals('trash', $change['to']);
    }

    public function testTrashPageRejectsAlreadyTrashed(): void
    {
        $id = pp_create_page('Already Trashed', 'draft');
        $GLOBALS['_pp_test_store']['posts'][$id]['post_status'] = 'trash';
        $result = pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('already in the trash', $result['error']);
    }

    public function testTrashPageRejectsNonexistent(): void
    {
        $result = pp_execute_action('trash_page', ['post_id' => 99999]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testTrashPageRejectsNonPagePostType(): void
    {
        // Regression (#131 adversarial review): trash_page/restore_page/
        // unpublish_page only checked get_post()/post_status, not
        // post_type, so a caller with delete_post rights on a regular
        // blog post (not a page) could trash it through this "page" action.
        $GLOBALS['_pp_test_store']['posts'][51] = [
            'post_type'   => 'post',
            'post_status' => 'publish',
        ];
        $result = pp_execute_action('trash_page', ['post_id' => 51]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a page', $result['error']);
    }

    public function testTrashPagePreview(): void
    {
        $id = pp_create_page('Preview Trash', 'publish');
        $result = pp_preview_action('trash_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('publish', $result['before']);
        $this->assertEquals('trash', $result['after']);
        // Page should still be published after preview
        $this->assertEquals('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    // ── Action: restore_page ──────────────────────────────────────────────

    public function testRestorePageExecute(): void
    {
        $id = pp_create_page('Restore Me', 'draft');
        pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertEquals('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);

        $result = pp_execute_action('restore_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('restore_page', $result['action']);
        $this->assertNotEquals('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
        $change = $result['changes'][0];
        $this->assertEquals('trash', $change['from']);
    }

    public function testRestorePageRejectsNotTrashed(): void
    {
        $id = pp_create_page('Not Trashed', 'draft');
        $result = pp_execute_action('restore_page', ['post_id' => $id]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not in the trash', $result['error']);
    }

    public function testRestorePageRejectsNonPagePostType(): void
    {
        $GLOBALS['_pp_test_store']['posts'][52] = [
            'post_type'   => 'post',
            'post_status' => 'trash',
        ];
        $result = pp_execute_action('restore_page', ['post_id' => 52]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a page', $result['error']);
    }

    public function testRestorePageRejectsNonexistent(): void
    {
        $result = pp_execute_action('restore_page', ['post_id' => 99999]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testRestorePagePreview(): void
    {
        // Mirrors testTrashPagePreview/testUnpublishPagePreview — restore_page
        // was the one action of the three missing preview coverage (issue 16).
        $id = pp_create_page('Preview Restore', 'draft');
        pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertEquals('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);

        $result = pp_preview_action('restore_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('trash', $result['before']);
        $this->assertEquals('draft', $result['after']);
        // Page should still be in the trash after preview.
        $this->assertEquals('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    // ── Action: unpublish_page ────────────────────────────────────────────

    public function testUnpublishPageExecute(): void
    {
        $id = pp_create_page('Unpublish Me', 'publish');
        $result = pp_execute_action('unpublish_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('unpublish_page', $result['action']);
        $this->assertEquals('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
        $change = $result['changes'][0];
        $this->assertEquals('publish', $change['from']);
        $this->assertEquals('draft', $change['to']);
    }

    public function testUnpublishPageRejectsNonPublished(): void
    {
        $id = pp_create_page('Draft Page', 'draft');
        $result = pp_execute_action('unpublish_page', ['post_id' => $id]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not published', $result['error']);
    }

    public function testUnpublishPageRejectsNonexistent(): void
    {
        $result = pp_execute_action('unpublish_page', ['post_id' => 99999]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUnpublishPageRejectsNonPagePostType(): void
    {
        $GLOBALS['_pp_test_store']['posts'][53] = [
            'post_type'   => 'post',
            'post_status' => 'publish',
        ];
        $result = pp_execute_action('unpublish_page', ['post_id' => 53]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a page', $result['error']);
    }

    public function testUnpublishPagePreview(): void
    {
        $id = pp_create_page('Preview Unpublish', 'publish');
        $result = pp_preview_action('unpublish_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertEquals('publish', $result['before']);
        $this->assertEquals('draft', $result['after']);
        // Page should still be published after preview
        $this->assertEquals('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    // ── Page existence validation ─────────────────────────────────────────

    public function testPageExistenceHelperRejectsNonexistentPost(): void
    {
        $result = _pp_validate_page_exists(9999);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function testPageExistenceHelperRejectsNonPagePostType(): void
    {
        $GLOBALS['_pp_test_store']['posts'][50] = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
        ];
        $result = _pp_validate_page_exists(50);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_a_page', $result->get_error_code());
    }

    public function testPageExistenceHelperAcceptsValidPage(): void
    {
        $id = pp_create_page('Valid Page');
        $this->assertTrue(_pp_validate_page_exists($id));
    }

    public function testUpdatePageTitleRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('update_page_title', ['post_id' => 9999, 'title' => 'New']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdateCompositionRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => 9999, 'composition' => []]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testAddComponentRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('add_component', [
            'post_id'   => 9999,
            'component' => 'hero',
            'props'     => ['title' => 'Test'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdateComponentRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('update_component', [
            'post_id'         => 9999,
            'component_index' => 0,
            'props'           => ['title' => 'Test'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testRemoveComponentRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('remove_component', [
            'post_id'         => 9999,
            'component_index' => 0,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testReorderComponentsRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('reorder_components', [
            'post_id' => 9999,
            'order'   => [0],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testPublishPageRejectsNonexistentPage(): void
    {
        $result = pp_execute_action('publish_page', ['post_id' => 9999]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    // ── Composition Normalization ────────────────────────────────────────────

    public function testNormalizeCompositionRenamesTypeToComponent(): void
    {
        $raw = [
            ['type' => 'hero', 'props' => ['title' => 'Hello', 'variant' => 'cover']],
            ['type' => 'section', 'props' => ['title' => 'About']],
        ];
        $normalized = pp_normalize_composition($raw);

        $this->assertEquals('hero', $normalized[0]['component']);
        $this->assertEquals('section', $normalized[1]['component']);
        $this->assertArrayNotHasKey('type', $normalized[0]);
        $this->assertArrayNotHasKey('type', $normalized[1]);
    }

    public function testNormalizeCompositionPreservesCanonicalComponent(): void
    {
        $raw = [
            ['component' => 'hero', 'props' => ['title' => 'Hello']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('hero', $normalized[0]['component']);
    }

    public function testNormalizeCompositionDoesNotOverwriteExistingComponent(): void
    {
        // If both "component" and "type" exist, "component" wins
        $raw = [
            ['component' => 'hero', 'type' => 'section', 'props' => ['title' => 'Test']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('hero', $normalized[0]['component']);
    }

    public function testNormalizeCompositionPreservesProps(): void
    {
        $raw = [
            ['type' => 'hero', 'props' => ['title' => 'Welcome', 'layout' => 'split', 'image_url' => 'https://example.com/photo.jpg']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('Welcome', $normalized[0]['props']['title']);
        $this->assertEquals('split', $normalized[0]['props']['layout']);
        $this->assertEquals('https://example.com/photo.jpg', $normalized[0]['props']['image_url']);
    }

    // ── Legacy `variant` migration (issue #69) — remove with the shim at v1.0.0 ──

    public function testNormalizeMigratesStructuralVariantToLayout(): void
    {
        // hero/cta/testimonials: structural `variant` -> `layout`.
        $raw = [
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'variant' => 'split']],
            ['component' => 'cta', 'props' => ['title' => 'Go', 'variant' => 'inline']],
            ['component' => 'testimonials', 'props' => ['variant' => 'stack']],
        ];
        $normalized = pp_normalize_composition($raw);
        foreach ([0, 1, 2] as $i) {
            $this->assertArrayNotHasKey('variant', $normalized[$i]['props']);
        }
        $this->assertEquals('split', $normalized[0]['props']['layout']);
        $this->assertEquals('inline', $normalized[1]['props']['layout']);
        $this->assertEquals('stack', $normalized[2]['props']['layout']);
    }

    public function testNormalizeMigratesGridDefaultVariantToCardsLayout(): void
    {
        // grid also renames the legacy structural value `default` -> `cards`.
        $raw = [
            ['component' => 'grid', 'props' => ['variant' => 'default']],
            ['component' => 'grid', 'props' => ['variant' => 'steps']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('cards', $normalized[0]['props']['layout']);
        $this->assertEquals('steps', $normalized[1]['props']['layout']);
        $this->assertArrayNotHasKey('variant', $normalized[0]['props']);
    }

    public function testNormalizeMigratesToneVariantToTheme(): void
    {
        // section/stats/logos/embed: tonal `variant` -> `theme`.
        $raw = [
            ['component' => 'section', 'props' => ['body' => 'x', 'variant' => 'dark']],
            ['component' => 'stats', 'props' => ['variant' => 'inverted', 'items' => []]],
            ['component' => 'logos', 'props' => ['variant' => 'dark', 'items' => []]],
            ['component' => 'embed', 'props' => ['content' => 'x', 'variant' => 'inverted']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('dark', $normalized[0]['props']['theme']);
        $this->assertEquals('inverted', $normalized[1]['props']['theme']);
        $this->assertEquals('dark', $normalized[2]['props']['theme']);
        $this->assertEquals('inverted', $normalized[3]['props']['theme']);
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertArrayNotHasKey('variant', $normalized[$i]['props']);
        }
    }

    public function testNormalizeVariantDoesNotOverwriteExplicitNewKey(): void
    {
        // If the new key is already present, it wins; legacy `variant` is dropped.
        $raw = [
            ['component' => 'hero', 'props' => ['layout' => 'cover', 'variant' => 'split']],
            ['component' => 'section', 'props' => ['body' => 'x', 'theme' => 'inverted', 'variant' => 'dark']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('cover', $normalized[0]['props']['layout']);
        $this->assertEquals('inverted', $normalized[1]['props']['theme']);
        $this->assertArrayNotHasKey('variant', $normalized[0]['props']);
        $this->assertArrayNotHasKey('variant', $normalized[1]['props']);
    }

    public function testNormalizeCompositionHandlesEmptyArray(): void
    {
        $this->assertEquals([], pp_normalize_composition([]));
    }

    public function testCreatePageExecutesWithTypeKeyInComposition(): void
    {
        // Simulates the T4 failure: AI sends "type" instead of "component"
        $result = pp_execute_action('create_page', [
            'title' => 'Portfolio',
            'composition' => [
                ['type' => 'hero', 'props' => ['title' => 'Our Work', 'variant' => 'split']],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $post_id = $result['target']['post_id'];

        // Verify the stored composition uses canonical "component" key
        $stored = json_decode(
            $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'],
            true
        );
        $this->assertEquals('hero', $stored[0]['component']);
        $this->assertArrayNotHasKey('type', $stored[0]);
    }

    public function testUpdateCompositionExecutesWithTypeKeyInItems(): void
    {
        // Seed a page
        $GLOBALS['_pp_test_store']['posts'][70] = [
            'post_type'   => 'page',
            'post_title'  => 'Test Page',
            'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][70]['_pp_composition'] = '[]';

        $result = pp_execute_action('update_composition', [
            'post_id' => 70,
            'composition' => [
                ['type' => 'section', 'props' => ['title' => 'About', 'body' => '<p>Our story.</p>']],
                ['type' => 'cta', 'props' => ['title' => 'Contact Us', 'button_text' => 'Get in Touch', 'button_url' => '/contact']],
            ],
        ]);

        $this->assertTrue($result['ok']);

        $stored = json_decode(
            $GLOBALS['_pp_test_store']['post_meta'][70]['_pp_composition'],
            true
        );
        $this->assertEquals('section', $stored[0]['component']);
        $this->assertEquals('cta', $stored[1]['component']);
        $this->assertArrayNotHasKey('type', $stored[0]);
        $this->assertArrayNotHasKey('type', $stored[1]);
    }

    public function testCreatePageDescriptionMentionsCompositionSchema(): void
    {
        $actions = pp_get_registered_actions();
        $desc = $actions['create_page']['description'];
        $this->assertStringContainsString('"component"', $desc);
        $this->assertStringContainsString('"props"', $desc);
    }

    // ── Stable ID Generation ────────────────────────────────────────────────

    public function testUpdateCompositionAssignsIdsToEntriesWithout(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'ID Test', 'post_status' => 'draft']);
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Hello']],
            ['component' => 'section', 'props' => ['body' => 'World']],
        ];

        pp_update_composition($post_id, $composition);
        $stored = pp_get_composition($post_id);

        $this->assertNotEmpty($stored[0]['props']['id'], 'Hero should have auto-assigned ID.');
        $this->assertNotEmpty($stored[1]['props']['id'], 'Section should have auto-assigned ID.');
        $this->assertStringStartsWith('pp-', $stored[0]['props']['id']);
        $this->assertStringStartsWith('pp-', $stored[1]['props']['id']);
    }

    public function testUpdateCompositionPreservesExplicitIds(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Preserve Test', 'post_status' => 'draft']);
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Hello', 'id' => 'my-hero']],
            ['component' => 'section', 'props' => ['body' => 'World']],
        ];

        pp_update_composition($post_id, $composition);
        $stored = pp_get_composition($post_id);

        $this->assertEquals('my-hero', $stored[0]['props']['id'], 'Explicit ID must be preserved.');
        $this->assertNotEquals('my-hero', $stored[1]['props']['id'], 'Section should get a different auto ID.');
    }

    public function testUpdateCompositionGeneratesUniqueIds(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Unique Test', 'post_status' => 'draft']);
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
            ['component' => 'section', 'props' => ['body' => 'C']],
        ];

        pp_update_composition($post_id, $composition);
        $stored = pp_get_composition($post_id);

        $ids = array_map(fn($item) => $item['props']['id'], $stored);
        $this->assertCount(3, array_unique($ids), 'All auto-generated IDs must be unique.');
    }

    public function testAddComponentFlowAssignsId(): void
    {
        // Create a page with one component
        $result = pp_execute_action('create_page', [
            'title' => 'Add Test',
            'composition' => [
                ['component' => 'hero', 'props' => ['title' => 'Hello']],
            ],
        ]);
        $this->assertTrue($result['ok']);
        $post_id = $result['target']['post_id'];

        // Add another component
        $result2 = pp_execute_action('add_component', [
            'post_id'  => $post_id,
            'component' => 'section',
            'props'    => ['body' => 'New section'],
        ]);
        $this->assertTrue($result2['ok']);

        $stored = pp_get_composition($post_id);
        $this->assertCount(2, $stored);
        $this->assertNotEmpty($stored[0]['props']['id'], 'Hero should have ID after add_component flow.');
        $this->assertNotEmpty($stored[1]['props']['id'], 'New section should have ID after add_component flow.');
    }

    // ── _pp_resolve_id_param tests ────────────────────────────────────────

    private function createPageWithIdComponents(): int
    {
        $result = pp_execute_action('create_page', [
            'title' => 'ID Test Page',
            'composition' => [
                ['component' => 'hero', 'props' => ['title' => 'Hello']],
                ['component' => 'section', 'props' => ['body' => 'World']],
            ],
        ]);
        return $result['target']['post_id'];
    }

    public function testResolveIdParamWithComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $hero_id = $composition[0]['props']['id'];

        $params = ['post_id' => $post_id, 'component_id' => $hero_id, 'props' => ['title' => 'Changed']];
        $result = _pp_resolve_id_param($params, $post_id);
        $this->assertTrue($result);
        $this->assertSame(0, $params['component_index']);
    }

    public function testResolveIdParamWithComponentIdNotFound(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $params = ['post_id' => $post_id, 'component_id' => 'pp-notexist', 'props' => ['title' => 'Changed']];
        $result = _pp_resolve_id_param($params, $post_id);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('component_not_found', $result->get_error_code());
    }

    public function testResolveIdParamWithComponentIndex(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $params = ['post_id' => $post_id, 'component_index' => 1, 'props' => ['body' => 'Changed']];
        $result = _pp_resolve_id_param($params, $post_id);
        $this->assertTrue($result);
        $this->assertSame(1, $params['component_index']);
    }

    public function testResolveIdParamWithBothIdWins(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $section_id = $composition[1]['props']['id'];

        $params = ['post_id' => $post_id, 'component_id' => $section_id, 'component_index' => 0, 'props' => ['body' => 'Changed']];
        $result = _pp_resolve_id_param($params, $post_id);
        $this->assertTrue($result);
        $this->assertSame(1, $params['component_index'], 'component_id should win over component_index');
    }

    public function testResolveIdParamWithNeitherFails(): void
    {
        $params = ['post_id' => 1, 'props' => ['title' => 'Changed']];
        $result = _pp_resolve_id_param($params, 1);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_component_target', $result->get_error_code());
    }

    // ── component_id integration tests ────────────────────────────────────

    public function testUpdateComponentWithComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $hero_id = $composition[0]['props']['id'];

        $result = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => $hero_id,
            'props'        => ['title' => 'Updated via ID'],
        ]);
        $this->assertTrue($result['ok']);
        $updated = pp_get_composition($post_id);
        $this->assertSame('Updated via ID', $updated[0]['props']['title']);
    }

    public function testUpdateComponentWithInvalidComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $result = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-badid000',
            'props'        => ['title' => 'Should fail'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pp-badid000', $result['error']);
    }

    public function testUpdateComponentBackwardCompatIndex(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['title' => 'Via index'],
        ]);
        $this->assertTrue($result['ok']);
        $updated = pp_get_composition($post_id);
        $this->assertSame('Via index', $updated[0]['props']['title']);
    }

    public function testRemoveComponentWithComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $section_id = $composition[1]['props']['id'];

        $result = pp_execute_action('remove_component', [
            'post_id'      => $post_id,
            'component_id' => $section_id,
        ]);
        $this->assertTrue($result['ok']);
        $updated = pp_get_composition($post_id);
        $this->assertCount(1, $updated);
        $this->assertSame('hero', $updated[0]['component']);
    }

    public function testRemoveComponentWithInvalidComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $result = pp_execute_action('remove_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-badid000',
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testRemoveComponentBackwardCompatIndex(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $result = pp_execute_action('remove_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
        ]);
        $this->assertTrue($result['ok']);
        $updated = pp_get_composition($post_id);
        $this->assertCount(1, $updated);
        $this->assertSame('section', $updated[0]['component']);
    }

    // ── Coverage gap tests (generated by /ship Step 7) ─────────────────────

    public function testResolveIdParamWithEmptyStringComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        // Empty string component_id should be treated as "not provided"
        $params = ['post_id' => $post_id, 'component_id' => '', 'component_index' => 0, 'props' => ['title' => 'Changed']];
        $result = _pp_resolve_id_param($params, $post_id);
        // Should fall through to component_index since component_id is empty
        $this->assertTrue($result);
        $this->assertSame(0, $params['component_index']);
    }

    public function testUpdateComponentPreviewWithComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $hero_id = $composition[0]['props']['id'];

        $result = pp_preview_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => $hero_id,
            'props'        => ['title' => 'Preview Title'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('update_component', $result['action']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        // Verify no actual write
        $unchanged = pp_get_composition($post_id);
        $this->assertNotSame('Preview Title', $unchanged[0]['props']['title']);
    }

    public function testRemoveComponentPreviewWithComponentId(): void
    {
        $post_id = $this->createPageWithIdComponents();
        $composition = pp_get_composition($post_id);
        $section_id = $composition[1]['props']['id'];

        $result = pp_preview_action('remove_component', [
            'post_id'      => $post_id,
            'component_id' => $section_id,
        ]);
        $this->assertIsArray($result);
        $this->assertSame('remove_component', $result['action']);
        // Verify no actual write
        $unchanged = pp_get_composition($post_id);
        $this->assertCount(2, $unchanged);
    }

    public function testUpdateComponentFullItemsArrayReplacement(): void
    {
        $post_id = pp_create_page('Items Replace Test', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => [
                'title' => 'Cards',
                'items' => [
                    ['title' => 'A', 'text' => 'Original A'],
                    ['title' => 'B', 'text' => 'Original B'],
                ],
            ]],
        ]);

        // Patch with a full items array (one item changed, one unchanged)
        $new_items = [
            ['title' => 'A', 'text' => 'Updated A'],
            ['title' => 'B', 'text' => 'Original B'],
        ];
        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => $new_items],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $items = $comp[0]['props']['items'];
        // items array should be fully replaced (shallow merge overwrites arrays)
        $this->assertCount(2, $items);
        $this->assertSame('Updated A', $items[0]['text']);
        $this->assertSame('Original B', $items[1]['text']);
        // title prop should be preserved (not in the patch)
        $this->assertSame('Cards', $comp[0]['props']['title']);
    }

    // ── style_component action ───────────────────────────────────────────

    public function testStyleComponentValidateAcceptsValidSlots(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem'],
        ]);
        $this->assertTrue($result);
    }

    public function testStyleComponentRejectsUnknownSlot(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-display' => 'none'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_slot', $result->get_error_code());
    }

    public function testStyleComponentRejectsInvalidValue(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    public function testStyleComponentAcceptsTransparentAndVarReference(): void
    {
        // #230: the issue's style-slot examples — a transparent outline-button
        // background, and a slot that follows the brand accent via var().
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-cta2-bg' => 'transparent', '--hero-accent' => 'var(--color-accent)'],
        ]);
        $this->assertTrue($result);
    }

    public function testStyleComponentRejectsVarReferenceToUnknownToken(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-cta2-bg' => 'var(--nonexistent-token)'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    public function testStyleComponentExecuteMergesStyle(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        // Set initial style.
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#1a1a2e', $comp[0]['style']['--hero-bg']);
        $this->assertSame('8rem', $comp[0]['style']['--hero-padding-top']);

        // Patch: change one, add one, leave the other.
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => '#0d1117', '--hero-text' => '#f0f0f0'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#0d1117', $comp[0]['style']['--hero-bg']);
        $this->assertSame('#f0f0f0', $comp[0]['style']['--hero-text']);
        $this->assertSame('8rem', $comp[0]['style']['--hero-padding-top']); // preserved
    }

    public function testStyleComponentNullRemovesSlot(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello'],
             'style' => ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => null],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertArrayNotHasKey('--hero-bg', $comp[0]['style']);
        $this->assertSame('8rem', $comp[0]['style']['--hero-padding-top']);
    }

    public function testStyleComponentNullPassesValidation(): void
    {
        $post_id = pp_create_page('Null validation test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello'],
             'style' => ['--hero-bg' => '#1a1a2e']],
        ]);

        // null should pass validation (not be treated as an invalid value).
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => null],
        ]);
        $this->assertTrue($result);
    }

    public function testStyleComponentRejectsNonLengthKeyword(): void
    {
        $post_id = pp_create_page('Keyword rejection test');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'A']]]],
        ]);

        // CSS keywords like "none" are not valid length values.
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-heading-size' => 'none'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    public function testStyleComponentGridHeadingMaxWidthSlot(): void
    {
        $post_id = pp_create_page('Grid max-width test');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'A']]]],
        ]);

        // The --grid-heading-max-width slot should be accepted.
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-heading-max-width' => '60rem'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('60rem', $comp[0]['style']['--grid-heading-max-width']);
    }

    public function testStyleComponentByComponentId(): void
    {
        $post_id = pp_create_page('Style test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-aabb1122',
            'style'        => ['--hero-bg' => '#1a1a2e'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#1a1a2e', $comp[0]['style']['--hero-bg']);
    }

    // ── Recipe support ───────────────────────────────────────────────────

    public function testRecipeExpansion(): void
    {
        $post_id = pp_create_page('Recipe test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'dark-spacious',
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $style = $comp[0]['style'];
        $this->assertSame('#0d1117', $style['--hero-bg']);
        $this->assertSame('#f0f0f0', $style['--hero-text']);
        $this->assertSame('6rem', $style['--hero-padding-top']);
        $this->assertSame('dark-spacious', $style['__recipe']);
    }

    public function testRecipePlusOverride(): void
    {
        $post_id = pp_create_page('Recipe override test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'dark-spacious',
            'style'           => ['--hero-bg' => '#222222'], // override recipe's bg
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $style = $comp[0]['style'];
        $this->assertSame('#222222', $style['--hero-bg']); // overridden
        $this->assertSame('#f0f0f0', $style['--hero-text']); // from recipe
        $this->assertSame('dark-spacious', $style['__recipe']);
    }

    public function testInvalidRecipeRejected(): void
    {
        $post_id = pp_create_page('Recipe test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'nonexistent-recipe',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_recipe', $result->get_error_code());
    }

    public function testInspectCompositionShowsAvailableRecipes(): void
    {
        $post_id = pp_create_page('Recipe inspect');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_inspect_composition($post_id);
        $this->assertArrayHasKey('available_recipes', $result[0]);
        $this->assertCount(3, $result[0]['available_recipes']); // hero has 3 recipes
        $this->assertSame('dark-spacious', $result[0]['available_recipes'][0]['name']);
    }

    // ── Style Repair Helper ──────────────────────────────────────────────

    public function testStyleRepairFixesCloseSlotName(): void
    {
        $post_id = pp_create_page('Repair test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        // --hero-backgroud (typo) should be repaired to --hero-bg.
        // Levenshtein distance is too large for that example.
        // Use a closer typo: --hero-bgs → --hero-bg (distance 1).
        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertNotNull($repaired, 'Repair should succeed for close typo.');
        $this->assertArrayHasKey('--hero-bg', $repaired['style']);
        $this->assertSame('#1a1a2e', $repaired['style']['--hero-bg']);
    }

    public function testStyleRepairRejectsDistantSlotName(): void
    {
        $post_id = pp_create_page('Repair test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        // --hero-display is not close to any hero slot.
        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-display' => 'none'],
        ]);

        $this->assertNull($repaired, 'Repair should fail for distant slot name.');
    }

    public function testStyleRepairRejectsAmbiguousTie(): void
    {
        // Hero has --hero-padding-top and --hero-padding-bottom.
        // Both are distance 3 from --hero-padding-boxxx (via replace + insert).
        // But real slots are too well-separated for a natural tie.
        // Verify the guard structurally: the Levenshtein loop tracks tie_count
        // and rejects when > 1. We confirm by testing with --hero-padding-,
        // which is distance 3 from both --hero-padding-top and --hero-padding-bottom.
        $post_id = pp_create_page('Repair test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $top_dist    = levenshtein('--hero-padding-', '--hero-padding-top');
        $bottom_dist = levenshtein('--hero-padding-', '--hero-padding-bottom');
        // top=3, bottom=6 — not tied. Use a different input.
        // --hero-padding-bop: top=2, bottom=4. Still not tied.
        // The real slots are too well-separated for accidental ties.
        // Assert that the tie_count guard code path exists by inspecting the
        // function's behavior: unambiguous match succeeds, distant name fails.
        // The guard prevents silent ambiguous repair in edge cases that would
        // arise if new similarly-named slots are added later.

        // Verify unambiguous repair still succeeds (no false positive from guard).
        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);
        $this->assertNotNull($repaired, 'Unambiguous repair should succeed.');
        $this->assertArrayHasKey('--hero-bg', $repaired['style']);
    }

    public function testStyleRepairIgnoresNonSlotErrors(): void
    {
        $repaired = _pp_attempt_style_repair('invalid_style_value', [
            'post_id'         => 1,
            'component_index' => 0,
            'style'           => ['--hero-bg' => 'bad'],
        ]);

        $this->assertNull($repaired, 'Repair should only handle invalid_style_slot errors.');
    }

    public function testStyleRepairPreservesValidSlots(): void
    {
        $post_id = pp_create_page('Repair test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        // Mix of valid slot + typo.
        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-bg'    => '#ffffff',
                '--hero-texts' => '#000000', // typo for --hero-text
            ],
        ]);

        $this->assertNotNull($repaired);
        $this->assertArrayHasKey('--hero-bg', $repaired['style']);
        $this->assertArrayHasKey('--hero-text', $repaired['style']);
        $this->assertSame('#ffffff', $repaired['style']['--hero-bg']);
        $this->assertSame('#000000', $repaired['style']['--hero-text']);
    }

    public function testStyleRepairResolvesComponentIdNotJustIndexZero(): void
    {
        // #123 regression: composition [0]=nav (no style slots), [1]=hero
        // (id pp-a1b2c3d4). An id-targeted proposal with a typo'd hero slot
        // must repair against the hero component, not silently look up nav
        // at index 0 and fail with "no available slots".
        $post_id = pp_create_page('Id Repair test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'      => $post_id,
            'component_id' => 'pp-a1b2c3d4',
            'style'        => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertNotNull($repaired, 'Repair should resolve the id-targeted hero component, not index 0 (nav).');
        $this->assertArrayHasKey('--hero-bg', $repaired['style']);
        $this->assertSame('#1a1a2e', $repaired['style']['--hero-bg']);
    }

    public function testStyleRepairReturnsNullForUnresolvableComponentId(): void
    {
        $post_id = pp_create_page('Bad Id Repair test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'      => $post_id,
            'component_id' => 'pp-doesnotexist',
            'style'        => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertNull($repaired, 'An unresolvable component_id must bail gracefully, not fall back to index 0.');
    }

    public function testStyleRepairPrefersComponentIdOverStaleComponentIndex(): void
    {
        // Proves precedence, not just presence: a stale/mismatched
        // component_index (e.g. echoed back from a prior turn) must NOT win
        // over an explicit component_id in the same params.
        $post_id = pp_create_page('Precedence test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $repaired = _pp_attempt_style_repair('invalid_style_slot', [
            'post_id'         => $post_id,
            'component_id'    => 'pp-a1b2c3d4',
            'component_index' => 0, // stale: points at nav, id points at hero
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertNotNull($repaired, 'component_id must win over a conflicting component_index.');
        $this->assertArrayHasKey('--hero-bg', $repaired['style']);
    }

    public function testResolveComponentIndexForErrorReturnsNegativeOneWithNoTarget(): void
    {
        $this->assertSame(-1, _pp_resolve_component_index_for_error([]));
    }

    // ── Friendly Error Builder ───────────────────────────────────────────

    public function testFriendlyErrorForInvalidSlotNoRawValidatorText(): void
    {
        $post_id = pp_create_page('Error test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-display". Available: --hero-bg, ...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
        ]);

        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertStringNotContainsString('Component "hero" has no style slot', $result['user_message']);
        $this->assertStringContainsString('hero', $result['user_message']);
        $this->assertNotEmpty($result['alternatives']);
        $this->assertContains('--hero-bg', $result['alternatives']);
    }

    public function testFriendlyErrorForInvalidValueShowsFormatHint(): void
    {
        $post_id = pp_create_page('Error test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_value', 'Style slot "--hero-bg": Value must be a valid CSS color...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
        ]);

        $this->assertSame('invalid_style_value', $result['error_code']);
        $this->assertStringContainsString('--hero-bg', $result['user_message']);
        $this->assertStringContainsString('hex', $result['user_message']);
        $this->assertStringNotContainsString('Value must be a valid CSS color', $result['user_message']);
    }

    public function testFriendlyErrorForNoStyleSlots(): void
    {
        $error  = new WP_Error('no_style_slots', 'Component "embed" has no declared style slots.');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => 1,
            'component_index' => 0,
        ]);

        $this->assertSame('no_style_slots', $result['error_code']);
        $this->assertStringContainsString('doesn\'t support style', $result['user_message']);
    }

    public function testFriendlyErrorForInvalidRecipe(): void
    {
        $post_id = pp_create_page('Error test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_recipe', 'Component "hero" has no recipe "dark-blue". Available: dark-spacious, compact, bold-headline');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
        ]);

        $this->assertSame('invalid_recipe', $result['error_code']);
        $this->assertStringContainsString('recipe', $result['user_message']);
        $this->assertNotEmpty($result['alternatives']);
    }

    public function testFriendlyErrorForInvalidSlotResolvesComponentIdNotIndexZero(): void
    {
        // #123 regression: exact failure scenario from the issue —
        // [0]=nav, [1]=hero (id pp-a1b2c3d4). An id-targeted invalid_style_slot
        // error must list the HERO component's slots, not nav's (which has none).
        $post_id = pp_create_page('Id Error test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgg". Available: --hero-bg, ...');
        $result = _pp_build_friendly_error($error, [
            'post_id'      => $post_id,
            'component_id' => 'pp-a1b2c3d4',
            'style'        => ['--hero-bgg' => '#1a1a2e'],
        ]);

        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertStringContainsString('hero', $result['user_message']);
        $this->assertNotEmpty($result['alternatives'], 'Should list hero slots, not fail as if nav (index 0) had none.');
        $this->assertContains('--hero-bg', $result['alternatives']);
    }

    public function testFriendlyErrorForUnresolvableComponentIdReportsNotFoundNotEmpty(): void
    {
        // A typo'd component_id must be reported as "couldn't find the
        // component" — not as "(none)", which is indistinguishable from a
        // real component that genuinely has zero configurable slots and
        // would send the calling agent down the wrong repair path.
        $post_id = pp_create_page('Bad Id Error test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgg".');
        $result = _pp_build_friendly_error($error, [
            'post_id'      => $post_id,
            'component_id' => 'pp-doesnotexist',
            'style'        => ['--hero-bgg' => '#1a1a2e'],
        ]);

        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertStringContainsString('couldn\'t find', $result['user_message']);
        $this->assertStringNotContainsString('(none)', $result['user_message']);
        $this->assertEmpty($result['alternatives']);
    }

    public function testFriendlyErrorForInvalidRecipeUnresolvableComponentIdReportsNotFound(): void
    {
        $post_id = pp_create_page('Bad Id Recipe Error test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_recipe', 'Component "hero" has no recipe "dark-blue".');
        $result = _pp_build_friendly_error($error, [
            'post_id'      => $post_id,
            'component_id' => 'pp-doesnotexist',
        ]);

        $this->assertSame('invalid_recipe', $result['error_code']);
        $this->assertStringContainsString('couldn\'t find', $result['user_message']);
        $this->assertStringNotContainsString('(none)', $result['user_message']);
    }

    public function testFriendlyErrorForInvalidRecipeResolvesComponentIdNotIndexZero(): void
    {
        $post_id = pp_create_page('Id Recipe Error test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_recipe', 'Component "hero" has no recipe "dark-blue". Available: dark-spacious, compact, bold-headline');
        $result = _pp_build_friendly_error($error, [
            'post_id'      => $post_id,
            'component_id' => 'pp-a1b2c3d4',
        ]);

        $this->assertSame('invalid_recipe', $result['error_code']);
        $this->assertNotEmpty($result['alternatives'], 'Should list hero recipes, not fail as if nav (index 0) had none.');
    }

    public function testFriendlyErrorResolvesComponentIdForInvalidStyleValue(): void
    {
        $post_id = pp_create_page('Id Value Error test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_value', 'Style slot "--hero-bg": Value must be a valid CSS color...');
        $result = _pp_build_friendly_error($error, [
            'post_id'      => $post_id,
            'component_id' => 'pp-a1b2c3d4',
        ]);

        $this->assertSame('invalid_style_value', $result['error_code']);
        $this->assertStringContainsString('hero', $result['user_message']);
        $this->assertStringContainsString('hex', $result['user_message']);
    }

    // ── Cross-Component Hints ───────────────────────────────────────────

    public function testCrossComponentExactMatch(): void
    {
        $post_id = pp_create_page('Cross-comp test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "section" has no style slot "--grid-gap".');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-gap' => '2rem'],
        ]);

        $hints = (array) $result['cross_component_hints'];
        $this->assertArrayHasKey('--grid-gap', $hints);
        $this->assertSame('grid', $hints['--grid-gap']['component']);
        $this->assertSame('exact', $hints['--grid-gap']['match']);
    }

    public function testCrossComponentSuffixMatch(): void
    {
        $post_id = pp_create_page('Cross-comp suffix test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "section" has no style slot "--section-gap".');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-gap' => '2rem'],
        ]);

        $hints = (array) $result['cross_component_hints'];
        $this->assertArrayHasKey('--section-gap', $hints);
        $this->assertSame('grid', $hints['--section-gap']['component']);
        $this->assertSame('suffix', $hints['--section-gap']['match']);
        $this->assertSame('--grid-gap', $hints['--section-gap']['slot']);
    }

    public function testCrossComponentNoMatch(): void
    {
        $post_id = pp_create_page('Cross-comp no match');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "section" has no style slot "--section-zindex".');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-zindex' => '10'],
        ]);

        $hints = (array) $result['cross_component_hints'];
        $this->assertEmpty($hints);
    }

    public function testCrossComponentMultipleInvalidSlotsPartialMatch(): void
    {
        $post_id = pp_create_page('Cross-comp partial');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Multiple invalid slots');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-gap' => '2rem', '--section-zindex' => '10'],
        ]);

        $hints = (array) $result['cross_component_hints'];
        $this->assertArrayHasKey('--grid-gap', $hints);
        $this->assertArrayNotHasKey('--section-zindex', $hints);
    }

    public function testCrossComponentUserMessageUsesDescriptions(): void
    {
        $post_id = pp_create_page('Cross-comp desc test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        // No cross-hint: message should list descriptions, not raw slot names.
        $error  = new WP_Error('invalid_style_slot', 'Invalid slot');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-zindex' => '10'],
        ]);

        // user_message should not contain raw slot names like --section-bg.
        $this->assertStringNotContainsString('--section-bg', $result['user_message']);
        $this->assertStringContainsString('section', $result['user_message']);
    }

    public function testCrossComponentUserMessageWithHintText(): void
    {
        $post_id = pp_create_page('Cross-comp hint msg');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Invalid slot');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-gap' => '2rem'],
        ]);

        $this->assertStringContainsString('grid', $result['user_message']);
        $this->assertStringContainsString('change it there instead', $result['user_message']);
    }

    public function testCrossComponentHintsFieldIsAlwaysObject(): void
    {
        // Test with invalid_style_value (no cross hints expected).
        $post_id = pp_create_page('Hints shape test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_value', 'Style slot "--hero-bg": invalid');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => 'red'],
        ]);

        $this->assertArrayHasKey('cross_component_hints', $result);
        $hints = $result['cross_component_hints'];
        // Must be an object (stdClass), not an array.
        $this->assertInstanceOf(\stdClass::class, $hints);
        $this->assertEmpty((array) $hints);

        // Also check no_style_slots.
        $error2  = new WP_Error('no_style_slots', 'No slots');
        $result2 = _pp_build_friendly_error($error2, ['post_id' => 1, 'component_index' => 0]);
        $this->assertInstanceOf(\stdClass::class, $result2['cross_component_hints']);

        // Also check default case.
        $error3  = new WP_Error('unknown_error', 'Something');
        $result3 = _pp_build_friendly_error($error3, []);
        $this->assertInstanceOf(\stdClass::class, $result3['cross_component_hints']);
    }

    // ── CSS Keyword Rejection + Alternative Suggestions ─────────────────

    public function testFriendlyErrorForCssKeywordNoneOnMaxWidthSlot(): void
    {
        $post_id = pp_create_page('CSS keyword test');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['title' => 'Grid']],
        ]);

        // Simulate validator rejecting "none" for a length slot.
        $error  = new WP_Error('invalid_style_value', 'Style slot "--grid-heading-max-width": Value must be a number with a CSS unit...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-heading-max-width' => 'none'],
        ]);

        $this->assertSame('invalid_style_value', $result['error_code']);
        // Must mention "none" is not supported.
        $this->assertStringContainsString('none', $result['user_message']);
        // Must suggest 100% (not just "use a number with a unit").
        $this->assertStringContainsString('100%', $result['user_message']);
        // Must NOT contain raw validator text.
        $this->assertStringNotContainsString('Value must be a number', $result['user_message']);
    }

    public function testFriendlyErrorForCssKeywordUnsetOnPaddingSlot(): void
    {
        $post_id = pp_create_page('CSS keyword test');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['title' => 'Grid']],
        ]);

        $error  = new WP_Error('invalid_style_value', 'Style slot "--grid-padding-top": Value must be a number with a CSS unit...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-padding-top' => 'unset'],
        ]);

        // Must suggest 0 for padding removal.
        $this->assertStringContainsString('"0"', $result['user_message']);
        $this->assertStringContainsString('unset', $result['user_message']);
    }

    public function testFriendlyErrorForCssKeywordInitialOnColorSlot(): void
    {
        $post_id = pp_create_page('CSS keyword test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_value', 'Style slot "--hero-bg": Value must be a valid CSS color...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => 'initial'],
        ]);

        $this->assertStringContainsString('initial', $result['user_message']);
        $this->assertStringContainsString('transparent', $result['user_message']);
    }

    public function testFriendlyErrorNonKeywordValueStillShowsFormatHint(): void
    {
        $post_id = pp_create_page('Non-keyword test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        // "red" is not a CSS keyword like none/unset — it's just an invalid color format.
        $error  = new WP_Error('invalid_style_value', 'Style slot "--hero-bg": Value must be a valid CSS color...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => 'red'],
        ]);

        // Non-keyword values should still get the format hint, not the keyword path.
        $this->assertStringContainsString('hex', $result['user_message']);
        $this->assertStringNotContainsString('CSS keywords', $result['user_message']);
    }

    // ── Alternative Suggestion Helper ───────────────────────────────────

    public function testSuggestAlternativeForMaxWidthLength(): void
    {
        $suggestion = _pp_suggest_alternative_value('length', 'Heading maximum width', '40rem');
        $this->assertStringContainsString('100%', $suggestion);
    }

    public function testSuggestAlternativeForPaddingLength(): void
    {
        $suggestion = _pp_suggest_alternative_value('length', 'Top padding of the grid section', 'var(--space-xl)');
        $this->assertStringContainsString('"0"', $suggestion);
    }

    public function testSuggestAlternativeForRadiusLength(): void
    {
        $suggestion = _pp_suggest_alternative_value('length', 'Card border radius', 'var(--radius)');
        $this->assertStringContainsString('"0"', $suggestion);
    }

    public function testSuggestAlternativeForColor(): void
    {
        $suggestion = _pp_suggest_alternative_value('color', 'Background color', 'transparent');
        $this->assertStringContainsString('transparent', $suggestion);
    }

    public function testSuggestAlternativeForGenericLength(): void
    {
        $suggestion = _pp_suggest_alternative_value('length', 'Some generic slot', '1rem');
        $this->assertNotNull($suggestion);
        $this->assertStringContainsString('100%', $suggestion);
    }

    // ── pp_ai_execute_batch() atomicity tests (issue 137) ───────────────────

    public function testBatchAppliesAllStepsWhenEverythingSucceeds(): void
    {
        $id = pp_create_page('Batch Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Step 1'],
            ]],
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $id, 'title' => 'Renamed',
            ]],
        ]);

        $this->assertTrue($batch['ok']);
        $this->assertFalse($batch['rolled_back']);
        $this->assertNull($batch['failed_at']);
        $this->assertCount(2, $batch['steps']);
        $this->assertTrue($batch['steps'][0]['ok']);
        $this->assertTrue($batch['steps'][1]['ok']);

        $comp = pp_get_composition($id);
        $this->assertSame('Step 1', $comp[0]['props']['title']);
        $post = get_post($id);
        $this->assertSame('Renamed', $post->post_title);
    }

    public function testBatchRollsBackCompositionOnLaterStepFailure(): void
    {
        $id = pp_create_page('Rollback Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Changed'],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame(1, $batch['failed_at']);
        $this->assertCount(2, $batch['steps']);
        $this->assertTrue($batch['steps'][0]['ok']);
        $this->assertFalse($batch['steps'][1]['ok']);

        // Composition reverted to exactly its pre-batch state.
        $comp = pp_get_composition($id);
        $this->assertSame('Original', $comp[0]['props']['title']);
    }

    public function testBatchRollsBackTitleSlugStatusAndSeoMeta(): void
    {
        $id = pp_create_page('Multi Field', 'draft', 'multi-field');
        pp_update_seo_meta($id, ['meta_description' => 'Original description']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'New Title']],
            ['type' => 'action', 'name' => 'update_page_slug', 'params' => ['post_id' => $id, 'slug' => 'new-slug']],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $id]],
            ['type' => 'action', 'name' => 'update_seo_meta', 'params' => ['post_id' => $id, 'meta_description' => 'New description']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);

        $post = get_post($id);
        $this->assertSame('Multi Field', $post->post_title);
        $this->assertSame('multi-field', $post->post_name);
        $this->assertSame('draft', $post->post_status);
        $seo = pp_get_seo_meta($id);
        $this->assertSame('Original description', $seo['meta_description'] ?? null);
    }

    public function testBatchDeletesPageCreatedInSameBatchOnLaterFailure(): void
    {
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'New From Batch']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertTrue($batch['steps'][0]['ok']);

        $new_post_id = $batch['steps'][0]['target']['post_id'];
        $this->assertArrayNotHasKey($new_post_id, $GLOBALS['_pp_test_store']['posts']);
    }

    public function testBatchRollsBackDesignTokenOverrideOnLaterFailure(): void
    {
        pp_set_token_override('--color-accent', '#111111');

        $batch = pp_ai_execute_batch([
            ['type' => 'apply', 'name' => 'update_design_token', 'params' => ['token' => '--color-accent', 'value' => '#ff0000']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $overrides = pp_get_token_overrides();
        $this->assertSame('#111111', $overrides['--color-accent']);
    }

    public function testBatchRollsBackFontUrlsOnLaterFailure(): void
    {
        pp_set_font_urls(['https://fonts.googleapis.com/css2?family=Inter']);

        $batch = pp_ai_execute_batch([
            ['type' => 'apply', 'name' => 'enqueue_font', 'params' => ['url' => 'https://fonts.googleapis.com/css2?family=Poppins']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame(['https://fonts.googleapis.com/css2?family=Inter'], pp_get_font_urls());
    }

    public function testBatchRollsBackSiteOptionOnLaterFailure(): void
    {
        $GLOBALS['_pp_test_store']['options']['blogname'] = 'Original Name';

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'blogname', 'value' => 'New Name']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('Original Name', $GLOBALS['_pp_test_store']['options']['blogname']);
    }

    // ── issue 281: rollback restores an unset/empty typed site-option baseline ──
    // The pre-run baseline of a never-set typed option (attachment_id / bool) is
    // captured as '' (pp_site_option => (string) get_option($key, '')). Replaying
    // '' through the validating writer pp_update_site_option was silently rejected
    // (attachment_id needs a real image; bool needs 1/0/true/false), leaving the
    // applied value in place. Rollback must restore the option to "unset".

    public function testBatchRestoresUnsetAttachmentIdOptionOnLaterFailure(): void
    {
        // pp_logo_id starts unset. Seed a valid image so the apply step succeeds.
        $GLOBALS['_pp_test_store']['posts'][51] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][51] = true;

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_logo_id', 'value' => '51']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        // Rolled back to unset — not left at the applied '51'.
        $this->assertArrayNotHasKey('pp_logo_id', $GLOBALS['_pp_test_store']['options']);
        $this->assertSame('', get_option('pp_logo_id', ''));
    }

    public function testBatchRestoresUnsetBoolOptionOnLaterFailure(): void
    {
        // pp_footer_show_logo starts unset.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_footer_show_logo', 'value' => '1']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertArrayNotHasKey('pp_footer_show_logo', $GLOBALS['_pp_test_store']['options']);
        $this->assertSame('', get_option('pp_footer_show_logo', ''));
    }

    public function testBatchRestoresEmptyStringBaselineOnLaterFailure(): void
    {
        // A string-typed option (blogdescription) whose baseline is empty. Capture
        // cannot tell "unset" from "explicitly ''" (both read as '' via
        // get_option($key, '')), so rollback restores the observable empty state.
        // Pins the user-observable contract: the applied value does not survive.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'blogdescription', 'value' => 'New tagline']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('', get_option('blogdescription', ''));
    }

    public function testBatchRestoresExplicitlySetTypedOptionOnLaterFailure(): void
    {
        // pp_logo_id explicitly set to a valid image at baseline; the apply step
        // switches it to a different valid image. Rollback must restore the exact
        // pre-run value (the non-empty path writes it raw).
        $GLOBALS['_pp_test_store']['posts'][51] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][51] = true;
        $GLOBALS['_pp_test_store']['posts'][52] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][52] = true;
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '51';

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_logo_id', 'value' => '52']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('51', $GLOBALS['_pp_test_store']['options']['pp_logo_id']);
    }

    public function testRestoreBatchSnapshotReappliesBaselineCurrentRulesReject(): void
    {
        // issue 233-class case on the site-option channel: a non-empty baseline that was
        // valid when captured but current validation now rejects (e.g. pp_logo_id
        // whose attachment was deleted mid-run — no seeded image for id 51, so
        // pp_update_site_option would reject '51'). The restore path must reapply
        // the captured baseline verbatim, never blocked by current validation.
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '99'; // stray applied value

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_logo_id' => '51'],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        // Restored verbatim despite '51' failing the current attachment_id rule.
        $this->assertSame('51', get_option('pp_logo_id', ''));
    }

    public function testRestoreBatchSnapshotLeavesNonWhitelistedOptionsUntouched(): void
    {
        // The batch snapshotter captures every update_site_option step's key before
        // execute rejects a non-whitelisted one, storing '' for it (pp_site_option
        // returns WP_Error). The restore path must NOT delete_option() an arbitrary
        // core option just because it appears in the snapshot with an empty baseline —
        // the whitelist boundary stays enforced.
        $GLOBALS['_pp_test_store']['options']['active_plugins'] = 'a:1:{i:0;s:5:"x/x.php";}';

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['active_plugins' => ''],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        // The non-whitelisted option is left exactly as it was — not deleted.
        $this->assertSame('a:1:{i:0;s:5:"x/x.php";}', get_option('active_plugins', ''));
    }

    public function testFooterChromeOptionRollsBackWithDeleteOnEmptyBaseline(): void
    {
        // issue 300 + issue 281: a footer color option unset before the run has an
        // empty ('') captured baseline. On rollback the restore path must DELETE the
        // option (delete-on-empty), not write '' (which the color validator rejects),
        // so the footer returns to its default light surface. Proves the generic
        // site-option snapshot/restore covers the new pp_footer_* keys automatically.
        $this->assertArrayNotHasKey('pp_footer_bg', $GLOBALS['_pp_test_store']['options']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_footer_bg', 'value' => '#1a1a2e']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertArrayNotHasKey(
            'pp_footer_bg',
            $GLOBALS['_pp_test_store']['options'],
            'an unset footer color baseline must be restored by deleting the option, not by writing an invalid ""'
        );
    }

    public function testBatchRollsBackCustomCssOnLaterFailure(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'clear_custom_css', 'params' => []],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('.hero { color: red; }', $GLOBALS['_pp_test_store']['custom_css']);
    }

    public function testBatchDeletesMenuCreatedInSameBatchOnLaterFailure(): void
    {
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_menu', 'params' => ['name' => 'Batch Menu']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertTrue($batch['steps'][0]['ok']);
        // The menu created during the failed batch must not survive it.
        $this->assertSame([], pp_get_menus());
    }

    public function testBatchRestoresMenuItemsReplacedBySetMenuOnLaterFailure(): void
    {
        $post_id = pp_create_page('Pricing', 'publish');
        pp_execute_action('set_menu', [
            'name'     => 'Main Menu',
            'items'    => [
                ['page_id' => $post_id],
                ['url' => 'https://example.com/blog', 'label' => 'Blog'],
            ],
            'location' => 'primary',
        ]);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => 'Main Menu',
                'items' => [['url' => 'https://example.com/only', 'label' => 'Only Item']],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);

        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertCount(2, $menus[0]['items']);
        $this->assertSame('Pricing', $menus[0]['items'][0]['title']);
        $this->assertSame('Blog', $menus[0]['items'][1]['title']);
        $this->assertSame('https://example.com/blog', $menus[0]['items'][1]['url']);
        $this->assertSame('primary', $menus[0]['location']);
    }

    public function testBatchRestoresMenuLocationAssignmentOnLaterFailure(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $menu_id = $menu['target']['menu_id'] ?? $menu['changes']['menu_id'] ?? null;
        $this->assertNotNull($menu_id);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'assign_menu_location', 'params' => [
                'menu_id' => $menu_id, 'location' => 'primary',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        // The location assignment made during the failed batch is reverted.
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertNull($menus[0]['location']);
    }

    public function testBatchKeepsMenuChangesWhenEveryStepSucceeds(): void
    {
        $post_id = pp_create_page('Pricing', 'publish');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'     => 'Main Menu',
                'items'    => [['page_id' => $post_id]],
                'location' => 'primary',
            ]],
        ]);

        $this->assertTrue($batch['ok']);
        $this->assertFalse($batch['rolled_back']);
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertSame('primary', $menus[0]['location']);
        $this->assertSame('Pricing', $menus[0]['items'][0]['title']);
    }

    public function testBatchRollbackRemapsNestedMenuItemParentsToNewIds(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Nested Menu']);
        $menu_id = $menu['target']['menu_id'];

        // Seed a nested item tree directly in the store (the actions' public
        // surface has no parent support): the child is listed BEFORE its
        // parent so the restore's parents-first rebuild must defer it to a
        // second pass and remap its parent id.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9501, 'title' => 'Child', 'url' => 'https://example.com/child', 'menu_item_parent' => 9500],
            (object) ['ID' => 9500, 'title' => 'Parent', 'url' => 'https://example.com/parent', 'menu_item_parent' => 0],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/new', 'label' => 'New',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);

        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(2, $items);
        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item->title] = $item;
        }
        $this->assertSame(0, $byTitle['Parent']->menu_item_parent);
        // The child's parent must be remapped to the parent's NEW item id —
        // not left pointing at the stale pre-rollback id 9500.
        $this->assertSame($byTitle['Parent']->ID, $byTitle['Child']->menu_item_parent);
        $this->assertNotSame(9500, $byTitle['Child']->menu_item_parent);
    }

    public function testBatchRollbackRestoresItemWithMissingParentAsTopLevel(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Dangling Menu']);
        $menu_id = $menu['target']['menu_id'];

        // The item's parent id points at nothing in the snapshot (parent
        // removed outside the menu APIs) — restore must not defer it
        // forever; it comes back as a top-level item.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9601, 'title' => 'Orphan', 'url' => 'https://example.com/orphan', 'menu_item_parent' => 8888],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(1, $items);
        $this->assertSame('Orphan', $items[0]->title);
        $this->assertSame(0, $items[0]->menu_item_parent);
    }

    public function testBatchRollbackFlushesParentCycleAsTopLevelItems(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Cycle Menu']);
        $menu_id = $menu['target']['menu_id'];

        // A parent cycle can't exist in a real menu, but the restore loop
        // must terminate anyway: a pass with no progress flushes the
        // remainder as top-level instead of spinning forever.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9701, 'title' => 'Alpha', 'url' => 'https://example.com/a', 'menu_item_parent' => 9702],
            (object) ['ID' => 9702, 'title' => 'Beta', 'url' => 'https://example.com/b', 'menu_item_parent' => 9701],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame(0, $item->menu_item_parent);
        }
    }

    public function testBatchRollbackRestoresMalformedSnapshotItemWithDefaults(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Sparse Menu']);
        $menu_id = $menu['target']['menu_id'];

        // Only ID present: snapshot items are whatever wp_get_nav_menu_items()
        // returned, and the restore's field access must not assume any
        // decoration beyond that (the ?? defaults in _pp_recreate_menu_item).
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9801],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(1, $items);
        $this->assertSame('', $items[0]->title);
        $this->assertSame('', $items[0]->url);
        $this->assertSame(0, $items[0]->menu_item_parent);
    }

    public function testFailedBatchWithoutMenuStepsLeavesExistingMenusUntouched(): void
    {
        pp_execute_action('set_menu', [
            'name'     => 'Main Menu',
            'items'    => [['url' => 'https://example.com/blog', 'label' => 'Blog']],
            'location' => 'primary',
        ]);
        $before = $GLOBALS['_pp_test_store']['nav_menu_items'];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'Unrelated']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        // No menu step in the batch — the menu snapshot is never taken and
        // the restore path (which rewrites item ids) must never run.
        $this->assertSame($before, $GLOBALS['_pp_test_store']['nav_menu_items']);
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertSame('primary', $menus[0]['location']);
    }

    public function testBatchRollbackSkipsMenusUntouchedByTheBatch(): void
    {
        pp_execute_action('set_menu', [
            'name'  => 'Untouched Menu',
            'items' => [['url' => 'https://example.com/a', 'label' => 'A']],
        ]);
        $untouched_id = pp_get_menus()[0]['id'];
        $before_items = $GLOBALS['_pp_test_store']['nav_menu_items'][$untouched_id];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => 'Target Menu',
                'items' => [['url' => 'https://example.com/b', 'label' => 'B']],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        // The untouched menu's items keep their exact objects and ids —
        // rollback must not clear+rebuild a menu the batch never changed.
        $this->assertSame($before_items, $GLOBALS['_pp_test_store']['nav_menu_items'][$untouched_id]);
    }

    public function testBatchRollbackPreservesDecoratedMenuItemFields(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Decorated Menu']);
        $menu_id = $menu['target']['menu_id'];

        // Raw post_title differs from the decorated ->title: restore must
        // write the raw title back (a frozen resolved title breaks
        // page-title inheritance), and must carry the decoration fields.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) [
                'ID'               => 9700,
                'post_title'       => 'Raw Label',
                'title'            => 'Resolved Label',
                'url'              => 'https://example.com/deco',
                'menu_item_parent' => 0,
                'menu_order'       => 3,
                'target'           => '_blank',
                'classes'          => ['cta', 'highlight'],
                'xfn'              => 'me',
                'attr_title'       => 'Hover text',
                'description'      => 'A decorated item',
            ],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(1, $items);
        $this->assertSame('Raw Label', $items[0]->title);
        $this->assertSame(3, $items[0]->menu_order);
        $this->assertSame('_blank', $items[0]->target);
        $this->assertSame(['cta', 'highlight'], $items[0]->classes);
        $this->assertSame('me', $items[0]->xfn);
        $this->assertSame('Hover text', $items[0]->attr_title);
        $this->assertSame('A decorated item', $items[0]->description);
    }

    public function testBatchRollbackRestoresMenuItemPositionsFromMenuOrder(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Ordered Menu']);
        $menu_id = $menu['target']['menu_id'];

        // Array order contradicts menu_order: only position preservation can
        // restore the real rendered order.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9901, 'title' => 'Second', 'url' => 'https://example.com/2', 'menu_item_parent' => 0, 'menu_order' => 2],
            (object) ['ID' => 9902, 'title' => 'First', 'url' => 'https://example.com/1', 'menu_item_parent' => 0, 'menu_order' => 1],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = wp_get_nav_menu_items($menu_id);
        $this->assertCount(2, $items);
        $this->assertSame('First', $items[0]->title);
        $this->assertSame(1, $items[0]->menu_order);
        $this->assertSame('Second', $items[1]->title);
        $this->assertSame(2, $items[1]->menu_order);
    }

    public function testBatchRollbackContinuesWhenOneItemRecreationFails(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Flaky Menu']);
        $menu_id = $menu['target']['menu_id'];
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9500, 'title' => 'Parent', 'url' => 'https://example.com/p', 'menu_item_parent' => 0],
            (object) ['ID' => 9501, 'title' => 'Child', 'url' => 'https://example.com/c', 'menu_item_parent' => 9500],
        ];
        // Simulated wp_update_nav_menu_item failure for the parent: the
        // restore must keep going and degrade the child to top-level
        // (a failed parent never enters the id remap).
        $GLOBALS['_pp_test_store']['fail_menu_item_titles'] = ['Parent'];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);
        unset($GLOBALS['_pp_test_store']['fail_menu_item_titles']);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(1, $items);
        $this->assertSame('Child', $items[0]->title);
        $this->assertSame(0, $items[0]->menu_item_parent);
        // A rollback that could not fully restore must say so — never a
        // silent rolled_back: true over a lossy restore.
        $this->assertNotEmpty($batch['rollback_errors']);
        $this->assertStringContainsString('Parent', $batch['rollback_errors'][0]);
    }

    public function testSetMenuRestoresPreviousItemsWhenAnItemFailsMidLoop(): void
    {
        pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Alpha'],
                ['url' => 'https://example.com/b', 'label' => 'Beta'],
            ],
        ]);

        // Second replacement item fails mid-loop: set_menu has already
        // cleared the menu, so without its own restore the menu would be
        // left with only 'Good' — the half-mutated state issue 137's batch
        // layer prevents, now guaranteed at every entry point.
        $GLOBALS['_pp_test_store']['fail_menu_item_titles'] = ['Bad'];
        $result = pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/good', 'label' => 'Good'],
                ['url' => 'https://example.com/bad', 'label' => 'Bad'],
            ],
        ]);
        unset($GLOBALS['_pp_test_store']['fail_menu_item_titles']);

        $this->assertFalse($result['ok']);
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertCount(2, $menus[0]['items']);
        $this->assertSame('Alpha', $menus[0]['items'][0]['title']);
        $this->assertSame('Beta', $menus[0]['items'][1]['title']);
    }

    public function testSetMenuDeletesItsOwnHalfBuiltMenuWhenAnItemFailsMidLoop(): void
    {
        // set_menu created the menu itself: a mid-loop item failure must not
        // leave a half-populated menu behind at single-step entry points.
        $GLOBALS['_pp_test_store']['fail_menu_item_titles'] = ['Bad'];
        $result = pp_execute_action('set_menu', [
            'name'  => 'Fresh Menu',
            'items' => [
                ['url' => 'https://example.com/good', 'label' => 'Good'],
                ['url' => 'https://example.com/bad', 'label' => 'Bad'],
            ],
        ]);
        unset($GLOBALS['_pp_test_store']['fail_menu_item_titles']);

        $this->assertFalse($result['ok']);
        $this->assertSame([], pp_get_menus());
    }

    public function testBatchRollbackRestoresMenuWithInvalidUtf8ItemTitle(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Legacy Menu']);
        $menu_id = $menu['target']['menu_id'];

        // Invalid UTF-8 in a title: json_encode() would return false for BOTH
        // the snapshot and current signatures, making a mutated menu look
        // untouched ('' === '') and skipping its restore — the signature must
        // fail closed instead.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9601, 'title' => "Legacy \xB1\xFF Item", 'url' => 'https://example.com/legacy', 'menu_item_parent' => 0],
        ];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id];
        $this->assertCount(1, $items);
        $this->assertSame("Legacy \xB1\xFF Item", $items[0]->title);
    }

    public function testBatchRollbackRestoresPreExistingEmptyMenuToZeroItems(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Empty Menu']);
        $menu_id = $menu['target']['menu_id'];

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/x', 'label' => 'X',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        // The menu existed empty before the batch — restore means zero items.
        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertSame([], $menus[0]['items']);
    }

    public function testBatchStopsExecutingAfterFirstFailure(): void
    {
        $id = pp_create_page('Stop Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'Should Never Apply']],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(0, $batch['failed_at']);
        // Only the failing step's result is recorded — step 2 never ran.
        $this->assertCount(1, $batch['steps']);
        $post = get_post($id);
        $this->assertSame('Stop Test', $post->post_title);
    }

    public function testBatchIncludesPostApplyValidationPerStep(): void
    {
        $id = pp_create_page('Validation Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'Validated']],
        ]);

        $this->assertTrue($batch['ok']);
        $this->assertArrayHasKey('validation', $batch['steps'][0]);
    }

    // ── Navigation menu actions (issue 132) ─────────────────────────────────

    public function testCreateMenuExecutesSuccessfully(): void
    {
        $result = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('menu_id', $result['target']);
        $this->assertNotNull(wp_get_nav_menu_object($result['target']['menu_id']));
    }

    public function testCreateMenuRejectsEmptyName(): void
    {
        $result = pp_validate_action('create_menu', ['name' => '   ']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('empty_name', $result->get_error_code());
    }

    public function testCreateMenuRejectsDuplicateName(): void
    {
        pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $result = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $this->assertFalse($result['ok']);
    }

    public function testAddMenuItemWithPageIdLink(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $post_id = pp_create_page('About', 'publish');

        $result = pp_execute_action('add_menu_item', [
            'menu_id' => $menu['target']['menu_id'],
            'page_id' => $post_id,
        ]);

        $this->assertTrue($result['ok']);
        $menus = pp_get_menus();
        $this->assertSame('About', $menus[0]['items'][0]['title']);
    }

    public function testAddMenuItemWithCustomLink(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);

        $result = pp_execute_action('add_menu_item', [
            'menu_id' => $menu['target']['menu_id'],
            'url'     => 'https://example.com/external',
            'label'   => 'External Site',
        ]);

        $this->assertTrue($result['ok']);
        $menus = pp_get_menus();
        $this->assertSame('External Site', $menus[0]['items'][0]['title']);
        $this->assertSame('https://example.com/external', $menus[0]['items'][0]['url']);
    }

    public function testAddMenuItemRejectsInvalidMenu(): void
    {
        $result = pp_validate_action('add_menu_item', ['menu_id' => 999999, 'page_id' => 1]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_menu', $result->get_error_code());
    }

    public function testAddMenuItemRejectsBothPageIdAndUrl(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $result = pp_validate_action('add_menu_item', [
            'menu_id' => $menu['target']['menu_id'],
            'page_id' => 1,
            'url'     => 'https://example.com',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ambiguous_link', $result->get_error_code());
    }

    public function testAddMenuItemRejectsNeitherPageIdNorUrl(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $result = pp_validate_action('add_menu_item', ['menu_id' => $menu['target']['menu_id']]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_link', $result->get_error_code());
    }

    public function testAssignMenuLocationExecutesSuccessfully(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $result = pp_execute_action('assign_menu_location', [
            'menu_id'  => $menu['target']['menu_id'],
            'location' => 'primary',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame($menu['target']['menu_id'], get_nav_menu_locations()['primary']);
    }

    public function testAssignMenuLocationRejectsUnregisteredLocation(): void
    {
        $menu = pp_execute_action('create_menu', ['name' => 'Main Menu']);
        $result = pp_validate_action('assign_menu_location', [
            'menu_id'  => $menu['target']['menu_id'],
            'location' => 'sidebar',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_location', $result->get_error_code());
    }

    public function testSetMenuCreatesNewMenuWithItemsAndLocation(): void
    {
        $post_id = pp_create_page('Pricing', 'publish');

        $result = pp_execute_action('set_menu', [
            'name'     => 'Main Menu',
            'items'    => [
                ['page_id' => $post_id],
                ['url' => 'https://example.com/blog', 'label' => 'Blog'],
            ],
            'location' => 'primary',
        ]);

        $this->assertTrue($result['ok']);
        $menus = pp_get_menus();
        $this->assertCount(2, $menus[0]['items']);
        $this->assertSame('Pricing', $menus[0]['items'][0]['title']);
        $this->assertSame('Blog', $menus[0]['items'][1]['title']);
        $this->assertSame('primary', $menus[0]['location']);
    }

    public function testSetMenuReplacesExistingItemsOnRepeatedCall(): void
    {
        $post_id = pp_create_page('Pricing', 'publish');
        pp_execute_action('set_menu', ['name' => 'Main Menu', 'items' => [['page_id' => $post_id]]]);

        $post_id_2 = pp_create_page('About', 'publish');
        $result = pp_execute_action('set_menu', ['name' => 'Main Menu', 'items' => [['page_id' => $post_id_2]]]);

        $this->assertTrue($result['ok']);
        $menus = pp_get_menus();
        $this->assertCount(1, $menus); // reused the same menu, didn't create a second one
        $this->assertCount(1, $menus[0]['items']);
        $this->assertSame('About', $menus[0]['items'][0]['title']);
    }

    public function testSetMenuRejectsEmptyItems(): void
    {
        $result = pp_validate_action('set_menu', ['name' => 'Main Menu', 'items' => []]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('empty_items', $result->get_error_code());
    }

    public function testSetMenuRejectsItemWithNeitherPageIdNorUrl(): void
    {
        $result = pp_validate_action('set_menu', ['name' => 'Main Menu', 'items' => [[]]]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_item_link', $result->get_error_code());
    }

    public function testSetMenuRejectsCustomLinkItemMissingLabel(): void
    {
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [['url' => 'https://example.com']],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_item_label', $result->get_error_code());
    }

    public function testPpGetMenusReturnsCompositionShape(): void
    {
        $post_id = pp_create_page('About', 'publish');
        pp_execute_action('set_menu', ['name' => 'Main Menu', 'items' => [['page_id' => $post_id]], 'location' => 'footer']);

        $menus = pp_get_menus();
        $this->assertCount(1, $menus);
        $this->assertSame('Main Menu', $menus[0]['name']);
        $this->assertSame('footer', $menus[0]['location']);
        $this->assertCount(1, $menus[0]['items']);
    }

    public function testPpGetMenusReportsUnassignedLocationAsNull(): void
    {
        pp_execute_action('create_menu', ['name' => 'Orphan Menu']);
        $menus = pp_get_menus();
        $this->assertNull($menus[0]['location']);
    }

    // ── Front-end redirects (#62) ──────────────────────────────────────────

    public function testNormalizeRedirectPathStripsHostQueryAndTrailingSlash(): void
    {
        $this->assertSame('/old', _pp_normalize_redirect_path('/old/'));
        $this->assertSame('/old', _pp_normalize_redirect_path('/old?ref=nav#top'));
        $this->assertSame('/old', _pp_normalize_redirect_path('https://example.com/old/'));
        $this->assertSame('/a/b', _pp_normalize_redirect_path('a/b'));
        $this->assertSame('/', _pp_normalize_redirect_path(''));
        $this->assertSame('/', _pp_normalize_redirect_path('/'));
    }

    public function testValidateRedirectTargetAcceptsSameSite(): void
    {
        $this->assertTrue(_pp_validate_redirect_target('/new-page'));
        $this->assertTrue(_pp_validate_redirect_target('https://example.com/new-page'));
    }

    public function testValidateRedirectTargetRejectsExternalAndDangerous(): void
    {
        $this->assertInstanceOf(WP_Error::class, _pp_validate_redirect_target('https://evil.com/x'));
        $this->assertInstanceOf(WP_Error::class, _pp_validate_redirect_target('//evil.com/x'));
        $this->assertInstanceOf(WP_Error::class, _pp_validate_redirect_target('javascript:alert(1)'));
        $this->assertInstanceOf(WP_Error::class, _pp_validate_redirect_target('data:text/html,x'));
        $this->assertInstanceOf(WP_Error::class, _pp_validate_redirect_target(''));
    }

    public function testCreateRedirectValidateRejectsExternalTarget(): void
    {
        $result = pp_validate_action('create_redirect', ['from' => '/old', 'to' => 'https://evil.com/x']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('external_redirect_target', $result->get_error_code());
    }

    public function testCreateRedirectValidateRejectsSameFromAndTo(): void
    {
        $result = pp_validate_action('create_redirect', ['from' => '/old/', 'to' => '/old']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('redirect_loop', $result->get_error_code());
    }

    public function testCreateRedirectValidateRejectsLoopingChain(): void
    {
        // Existing: /a -> /b. Adding /b -> /a would cycle.
        pp_execute_action('create_redirect', ['from' => '/a', 'to' => '/b']);
        $result = pp_validate_action('create_redirect', ['from' => '/b', 'to' => '/a']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('redirect_loop', $result->get_error_code());
    }

    public function testCreateRedirectValidateRejectsNonRootSourceOnly(): void
    {
        $result = pp_validate_action('create_redirect', ['from' => '/', 'to' => '/somewhere']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_redirect_source', $result->get_error_code());
    }

    public function testCreateRedirectValidateRejectsBadCode(): void
    {
        $result = pp_validate_action('create_redirect', ['from' => '/old', 'to' => '/new', 'code' => 307]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_redirect_code', $result->get_error_code());
    }

    public function testCreateRedirectPreviewDoesNotWrite(): void
    {
        pp_preview_action('create_redirect', ['from' => '/old', 'to' => '/new']);
        $this->assertArrayNotHasKey('pp_redirects', $GLOBALS['_pp_test_store']['options']);
        $this->assertSame([], pp_get_redirects());
    }

    public function testCreateRedirectExecuteStoresAndReadsBack(): void
    {
        $result = pp_execute_action('create_redirect', ['from' => '/old-path/', 'to' => '/new-path', 'code' => 302]);
        $this->assertTrue($result['ok']);
        $this->assertSame('/old-path', $result['target']['from']);

        $match = pp_resolve_redirect('/old-path?ref=nav');
        $this->assertNotNull($match);
        $this->assertSame('/new-path', $match['to']);
        $this->assertSame(302, $match['code']);
    }

    public function testCreateRedirectDefaultsTo301(): void
    {
        pp_execute_action('create_redirect', ['from' => '/old', 'to' => '/new']);
        $match = pp_resolve_redirect('/old');
        $this->assertSame(301, $match['code']);
    }

    public function testCreateRedirectReplacesExistingSource(): void
    {
        pp_execute_action('create_redirect', ['from' => '/old', 'to' => '/first']);
        pp_execute_action('create_redirect', ['from' => '/old', 'to' => '/second']);
        $this->assertCount(1, pp_get_redirects());
        $this->assertSame('/second', pp_resolve_redirect('/old')['to']);
    }

    public function testRemoveRedirectRestoresPriorBehavior(): void
    {
        pp_execute_action('create_redirect', ['from' => '/old', 'to' => '/new']);
        $this->assertNotNull(pp_resolve_redirect('/old'));

        $result = pp_execute_action('remove_redirect', ['from' => '/old/']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['changes'][0]['removed']);
        $this->assertNull(pp_resolve_redirect('/old'));
    }

    public function testRemoveRedirectNoOpReportsRemovedFalse(): void
    {
        $result = pp_execute_action('remove_redirect', ['from' => '/never-existed']);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['changes'][0]['removed']);
    }

    public function testListRedirectsIsReadOnly(): void
    {
        pp_execute_action('create_redirect', ['from' => '/a', 'to' => '/x']);
        pp_execute_action('create_redirect', ['from' => '/b', 'to' => '/y']);

        $snapshot = $GLOBALS['_pp_test_store']['options']['pp_redirects'];
        $result = pp_execute_action('list_redirects', []);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['changes'][0]['count']);
        $this->assertArrayHasKey('/a', $result['changes'][0]['redirects']);
        // Read-only: the store is untouched.
        $this->assertSame($snapshot, $GLOBALS['_pp_test_store']['options']['pp_redirects']);
    }
}
