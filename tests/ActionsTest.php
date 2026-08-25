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

    /**
     * #441: the global button color surface is part of the public token contract.
     * Registering the four tokens in base.css's first :root block exposes them
     * through pp_design_tokens() (and therefore to the AI via lib/ai-context.php)
     * with the correct annotated types, so a rethemer can discover the one-knob
     * button surface instead of falling back to per-component --cta-button-* rescues.
     */
    public function testPpDesignTokensExposesGlobalButtonSurface(): void
    {
        $tokens = pp_design_tokens();

        $this->assertArrayHasKey('--btn-bg', $tokens);
        $this->assertArrayHasKey('--btn-text', $tokens);
        $this->assertArrayHasKey('--btn-border-color', $tokens);
        $this->assertArrayHasKey('--btn-shadow', $tokens);

        // Types are derived from the annotated /* type: ... */ comments.
        $this->assertSame('color', $tokens['--btn-bg']['type']);
        $this->assertSame('color', $tokens['--btn-text']['type']);
        $this->assertSame('color', $tokens['--btn-border-color']['type']);
        $this->assertSame('shadow', $tokens['--btn-shadow']['type']);

        // #458 rerouted the premium/.cta/.hero primary-button cascade through --btn-*, so the
        // fill/border/shadow tokens register as `initial` (unset-by-default knobs): each
        // consuming rule resolves its own literal until the token is set, keeping unset output
        // byte-identical while a SET token restyles every composed primary. --btn-text keeps
        // its concrete default (its value equals the universal ink literal, the intentional
        // --color-bg inversion coupling), so it stays discoverable AND overridable.
        $this->assertSame('initial', $tokens['--btn-bg']['value']);
        $this->assertSame('var(--color-bg)', $tokens['--btn-text']['value']);
        $this->assertSame('initial', $tokens['--btn-border-color']['value']);
        $this->assertSame('initial', $tokens['--btn-shadow']['value']);
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
            ['component' => 'hero', 'props' => ['title' => 'A'], 'style' => ['--hero-button2-bg' => 'var(--nonexistent-token)']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // The write succeeds and the snapshot is preserved verbatim.
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame('var(--nonexistent-token)', pp_get_composition($post_id)[0]['style']['--hero-button2-bg']);

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
        // `--hero-button2-color` (the button's INK), not `--hero-button2-bg`: the bg
        // slot declares `role: "fill"`, and since #579 a transparent fill raises a
        // non-blocking `transparent_fill` advisory, which would make this findings
        // assertion about the warn channel instead of about var() acceptance.
        //
        // The two button labels are load-bearing for the SAME reason since #580:
        // `--hero-button2-*` declares applies_when button_text + button2_text, so a
        // hero with neither renders no second button and the slot raises an
        // `inert_slot` advisory — again the warn channel, not var() acceptance.
        $post_id = pp_create_page('Valid var snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'button_text' => 'One', 'button2_text' => 'Two'], 'style' => ['--hero-button2-color' => 'transparent', '--hero-accent' => 'var(--color-accent)']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['findings']);
    }

    // ── The restore report is exhaustive per item (#621) ────────────────────
    //
    // Section 14.1: asserted through the REAL surface (pp_execute_action), because the
    // report an operator reads is the action envelope, not the validator's return value.
    // The snapshot below is one band with three independent problems. Before #621 it
    // reported ONE of them and the operator learned about the next only by restoring
    // again after a repair — on a page whose whole point is "undo, then see what is
    // dead", that is the report failing at its job.

    /** A snapshot band carrying a retired prop name, a dead slot and a dead card link. */
    private function multiProblemSnapshot(): array
    {
        return [
            ['component' => 'cta', 'props' => [
                'title' => 'Ready?', 'button_text' => 'Go', 'button_url' => '/go', 'cta_text' => 'Go',
            ], 'style' => ['--cta-not-a-slot' => 'red']],
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'link_url' => 'javascript:alert(1)'],
            ]]],
        ];
    }

    public function testRestoreExecuteReportsEveryProblemInABandNotJustTheFirst(): void
    {
        $post_id = pp_create_page('Multi-problem snapshot');
        pp_update_composition($post_id, $this->multiProblemSnapshot());
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Current']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        // Restore still never blocks (#233) — exhaustiveness changed WHAT is reported,
        // never WHETHER the write happens.
        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame('cta', pp_get_composition($post_id)[0]['component']);

        $errors = array_values(array_filter($result['findings'], static fn ($f) => $f['severity'] === 'error'));
        $types  = array_column($errors, 'type');
        $this->assertContains('unknown_prop', $types, 'the retired prop name');
        $this->assertContains('invalid_style_slot', $types, 'the dead slot, which #621 unmasked');
        $this->assertContains('invalid_prop_value', $types, 'the dead card link on the SECOND band');

        // Each finding still names the band that owns it (#622) — a longer list is only
        // useful if it stays attributable.
        foreach ($errors as $finding) {
            $this->assertContains($finding['index'], [0, 1]);
        }
        $bandZero = array_column(array_filter($errors, static fn ($f) => $f['index'] === 0), 'type');
        $this->assertSame(['unknown_prop', 'invalid_style_slot'], $bandZero);
    }

    public function testRestorePreviewReportsTheSameExhaustiveFindingsAndWritesNothing(): void
    {
        // preview must describe exactly what execute would write — including the extra
        // findings — or an agent that previews before restoring still learns one problem.
        $post_id = pp_create_page('Multi-problem preview');
        pp_update_composition($post_id, $this->multiProblemSnapshot());
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Current']]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertSame('hero', pp_get_composition($post_id)[0]['component'], 'preview writes nothing');
        $types = array_column($preview['findings'], 'type');
        $this->assertContains('unknown_prop', $types);
        $this->assertContains('invalid_style_slot', $types);
        $this->assertContains('invalid_prop_value', $types);
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

    // ── Write-time type + link-URL enforcement through the action surface (#507) ──
    //
    // Section 14.1 authoring-path proofs: an accepted write must render as authored,
    // so a wrong-typed prop or a dead-button link URL is rejected at the REAL write
    // surface (create_page / update_component / update_composition), not neutered at
    // render behind ok:true. Accepts AND rejects, including the previously-silent
    // classes (a URL esc_url would empty, a non-scalar scalar prop).

    public function testCreatePageRejectsDeadButtonLinkUrl(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Dead button page',
            'composition' => [['component' => 'cta', 'props' => [
                'button_text' => 'Click', 'button_url' => 'javascript:alert(1)',
            ]]],
        ]);
        $this->assertFalse($result['ok'], 'a javascript: button_url must not persist behind ok:true');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('button_url', $result['error']);
        $this->assertStringContainsString('dead link', $result['error']);
    }

    public function testCreatePageAcceptsRenderableLinkUrls(): void
    {
        // #anchor (dev's real content uses #booking), site-relative, mailto:, tel:, absolute.
        foreach (['#booking', '/pricing', 'mailto:hi@example.com', 'tel:+15551234567', 'https://example.com'] as $url) {
            $result = pp_execute_action('create_page', [
                'title'       => 'Good link page',
                'composition' => [['component' => 'cta', 'props' => [
                    'button_text' => 'Click', 'button_url' => $url,
                ]]],
            ]);
            $this->assertTrue($result['ok'], sprintf('button_url "%s" must be accepted; got: %s', $url, $result['error'] ?? ''));
        }
    }

    // ── CTA second button, through the REAL write surface (issue 474) ──────
    //
    // Section 14.1 authoring-path proofs. Raw _pp_composition meta writes bypass
    // validation entirely, so the authoring CONTRACT for each new prop is exercised
    // once here through pp_execute_action: the pair is accepted and persisted, the
    // enum is enforced, and button2_url inherits the #507 link_url family.

    public function testCreatePageAcceptsCtaSecondButtonPair(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Closing CTA with a pair',
            'composition' => [['component' => 'cta', 'props' => [
                'button_text'     => 'Ver planes',
                'button_url'      => '/precios',
                'button2_text'    => 'Hablar con nosotros',
                'button2_url'     => '/contacto',
                'button2_variant' => 'outline',
            ]]],
        ]);

        $this->assertTrue($result['ok'], 'the primary+secondary pair must be accepted: ' . ($result['error'] ?? ''));
        $props = pp_get_composition((int) $result['target']['post_id'])[0]['props'];
        $this->assertSame('Hablar con nosotros', $props['button2_text']);
        $this->assertSame('/contacto', $props['button2_url']);
        $this->assertSame('outline', $props['button2_variant']);
    }

    public function testUpdateComponentAddsSecondButtonToAnExistingCta(): void
    {
        // The reported symptom: an existing single-button closing CTA gains a
        // secondary action through a targeted edit.
        $id = pp_create_page('Existing closing CTA', 'draft');
        pp_update_composition($id, [['component' => 'cta', 'props' => [
            'button_text' => 'Ver planes', 'button_url' => '/precios',
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['button2_text' => 'Hablar', 'button2_url' => '/contacto'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $props = pp_get_composition($id)[0]['props'];
        $this->assertSame('Hablar', $props['button2_text']);
        $this->assertSame('Ver planes', $props['button_text'], 'the primary button is untouched');
    }

    public function testCreatePageRejectsDeadButton2LinkUrl(): void
    {
        // #507 link_url family applies to button2_url exactly as to button_url:
        // esc_url() would neuter this into an empty href — a dead second button.
        $result = pp_execute_action('create_page', [
            'title'       => 'Dead second button',
            'composition' => [['component' => 'cta', 'props' => [
                'button_text'  => 'Click',
                'button_url'   => '/ok',
                'button2_text' => 'Also click',
                'button2_url'  => 'javascript:alert(1)',
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'a javascript: button2_url must not persist behind ok:true');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('button2_url', $result['error']);
        $this->assertStringContainsString('dead link', $result['error']);
    }

    public function testUpdateComponentRejectsOutOfEnumButton2Variant(): void
    {
        // #579, A-32 INVERTED this. button2_variant used to be one of the 28 enums
        // that declared no `strict`, so `neon` was accepted at write, persisted, and
        // silently coerced to `outline` at render — a write that reported ok:true and
        // did something else. Every enum is strict now, so the write is REJECTED with
        // a named error instead. Nothing rendered changes: the renderer coerced this
        // value before and would still coerce it, but the value can no longer get in.
        $id = pp_create_page('Out-of-enum second variant', 'draft');
        pp_update_composition($id, [['component' => 'cta', 'props' => [
            'button_text' => 'Go', 'button_url' => '/',
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['button2_text' => 'Second', 'button2_variant' => 'neon'],
        ]);

        $this->assertFalse($result['ok'], 'every enum is strict since #579');
        $this->assertStringContainsString('button2_variant', $result['error']);
        $this->assertStringContainsString('primary, secondary, outline, ghost', $result['error']);
        // Nothing persisted.
        $this->assertArrayNotHasKey('button2_variant', pp_get_composition($id)[0]['props']);
        // The render-side coercion the strict gate now makes unreachable through the
        // action layer is still pinned for raw/restore paths in
        // ComponentPropsTest::testCtaButton2VariantInvalidFallsBackToOutline.
    }

    public function testUpdateComponentIsBlockedByAnUntouchedBandHoldingTheRemovedThemeValue(): void
    {
        // #605 INVERTS the #579/#575 alias pin this replaces. `theme` is strict and
        // advertises only default|muted|inverted; `dark` is no longer an accepted
        // input value, so it is no longer part of the strict membership test.
        //
        // The load-bearing shape is still the SECOND band, and the consequence is now
        // the deliberate one: the READ-MODIFY-WRITE actions validate the WHOLE
        // composition, so an untouched band that STORES `theme: "dark"` blocks an
        // edit to a different band on the same page. That is the accepted stale-data
        // breakage stated plainly — not a regression, not something to migrate around.
        //
        // The blast radius is UNEVEN and the unevenness is pinned below, not merely
        // asserted here: `add_component` validates only the item it adds and
        // `style_component` validates no props at all, so both still succeed on the
        // same stale page. Exactly the boundary AI_CONTEXT.md describes for retired
        // prop NAMES; a retired VALUE lands in the same place.
        $id = pp_create_page('Stale theme value', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Legacy band', 'body' => 'B', 'theme' => 'dark']],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);

        $this->assertFalse($result['ok'], 'a stored `dark` band must now block the whole-composition validation');
        $this->assertStringContainsString('default, muted, inverted', $result['error']);

        // Nothing was written: the stale band is untouched and the edit did not land.
        $composition = pp_get_composition($id);
        $this->assertSame('Other band', $composition[1]['props']['title']);
        $this->assertSame('dark', $composition[0]['props']['theme'], 'storage is never rewritten behind the author');
    }

    public function testTheStaleThemeValueDoesNotBlockTheItem_ScopedActions(): void
    {
        // The OTHER half of the uneven blast radius. Same stale page, the two actions
        // that do not validate the whole composition still work.
        $id = pp_create_page('Stale theme value, item-scoped actions', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Legacy band', 'body' => 'B', 'theme' => 'dark']],
        ]);

        $added = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'Fresh band', 'body' => 'C', 'theme' => 'muted'],
        ]);
        $this->assertTrue($added['ok'], $added['error'] ?? 'add_component validates only the item it adds');

        $styled = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-bg' => '#101014'],
        ]);
        $this->assertTrue($styled['ok'], $styled['error'] ?? 'style_component validates no props at all');

        // Both wrote the stale band back VERBATIM — never silently repaired.
        $this->assertSame('dark', pp_get_composition($id)[0]['props']['theme']);
    }

    public function testRepairingTheStaleThemeValueUnblocksTheWholeComposition(): void
    {
        // THE WAY OUT. The intended breakage must be escapable through the ordinary
        // authoring surface, or a stale page would be permanently unwritable — which
        // would be a bug, not a ruling. Repair the offending band, and the edit that
        // was blocked lands.
        $id = pp_create_page('Repairable stale theme value', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Legacy band', 'body' => 'B', 'theme' => 'dark']],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertFalse($blocked['ok'], 'precondition: the stale band blocks the sibling edit');

        // Repair the band that actually holds the retired value.
        $repair = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0, 'props' => ['theme' => 'muted'],
        ]);
        $this->assertTrue($repair['ok'], $repair['error'] ?? 'the offending band must be repairable in place');

        // And now the previously blocked edit lands.
        $retry = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertTrue($retry['ok'], $retry['error'] ?? 'repairing the band unblocks the page');
        $composition = pp_get_composition($id);
        $this->assertSame('muted', $composition[0]['props']['theme']);
        $this->assertSame('Edited', $composition[1]['props']['title']);
    }

    // ── Nested items[] enums, through the REAL write surface (issue #600) ──
    //
    // Section 14.1 authoring-path proofs. pp_update_composition() and raw meta writes
    // both bypass the action layer, so the authoring CONTRACT for the newly-strict
    // nested enum is exercised here through pp_execute_action: an out-of-set role is
    // rejected and persists nothing, a declared role is accepted and persists, and
    // the whole-composition blast radius (and the way out of it) is pinned rather
    // than described.

    public function testUpdateComponentRejectsAnOutOfSetNestedTextRole(): void
    {
        // THE REPORTED DEFECT, inverted. Before #600 this returned ok:true, stored
        // `terminal`, and rendered ordinary body text — the author was told the card
        // was marked as code and it was not. The gate that already covered every
        // TOP-LEVEL enum walked $schema['props'] only, so one level down the same
        // `strict` declaration was a no-op.
        $id = pp_create_page('Grid with a bogus card role', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => [
            'items' => [['title' => 'Deploy', 'text' => '$ deploy --now']],
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => 'Deploy', 'text' => '$ deploy --now', 'text_role' => 'terminal'],
            ]],
        ]);

        $this->assertFalse($result['ok'], 'a nested enum is strict since #600');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('item 0 field "text_role"', $result['error']);
        $this->assertStringContainsString('mono, meta, label, kicker', $result['error']);

        // Nothing persisted: the whole action is refused, not partially applied.
        $items = pp_get_composition($id)[0]['props']['items'];
        $this->assertArrayNotHasKey('text_role', $items[0], 'the rejected role must not reach storage');
        $this->assertSame('$ deploy --now', $items[0]['text'], 'the stored item is untouched');
    }

    public function testCreatePageAcceptsAndPersistsADeclaredNestedTextRole(): void
    {
        // The other half: the advertised vocabulary still authors cleanly through the
        // action layer, on the surface the docs point an agent at.
        $result = pp_execute_action('create_page', [
            'title'       => 'Terminal card',
            'composition' => [['component' => 'grid', 'props' => [
                'items' => [
                    ['title' => 'Ship it', 'text' => '$ deploy --now', 'text_role' => 'mono'],
                    ['title' => 'Plain',   'text' => 'No role here'],
                ],
            ]]],
        ]);

        $this->assertTrue($result['ok'], 'a declared role must be accepted: ' . ($result['error'] ?? ''));
        $items = pp_get_composition((int) $result['target']['post_id'])[0]['props']['items'];
        $this->assertSame('mono', $items[0]['text_role']);
        $this->assertArrayNotHasKey('text_role', $items[1], 'an omitted role stays omitted — the unset sentinel');
    }

    public function testAStoredOutOfSetNestedTextRoleBlocksAnEditToADifferentBand(): void
    {
        // THE ACCEPTED STALE-DATA COST, stated as a test rather than as a footnote.
        // Every read-modify-write action validates the WHOLE composition, so a page
        // that already stores an out-of-set role in band 0 cannot be edited at band 1
        // until band 0 is repaired. That is the v1.13.0 no-compat posture working as
        // intended — the alternative is an alias or a coercion, and both are barred.
        $id = pp_create_page('Legacy card role', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid',    'props' => ['items' => [['title' => 'Legacy', 'text_role' => 'terminal']]]],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);

        $this->assertFalse($result['ok'], 'the stale nested role must block the whole-composition validation');
        $this->assertStringContainsString('text_role', $result['error']);

        // Nothing was written: the stale band is untouched and the edit did not land.
        $composition = pp_get_composition($id);
        $this->assertSame('Other band', $composition[1]['props']['title']);
        $this->assertSame('terminal', $composition[0]['props']['items'][0]['text_role'], 'storage is never rewritten behind the author');
    }

    // ── Undeclared nested items[] fields, through the REAL write surface (#643) ──
    //
    // Section 14.1 authoring-path proofs. pp_update_composition() and raw meta writes both
    // bypass the action layer, so the authoring CONTRACT for the new nested unknown-key
    // gate is exercised here through pp_execute_action on all three write verbs: a
    // misspelled item field is rejected and persists nothing, the declared spelling is
    // accepted and persists, and the whole-composition blast radius (and the way out of
    // it) is pinned rather than described.

    public function testUpdateComponentRejectsAnUndeclaredNestedItemField(): void
    {
        // THE REPORTED DEFECT, inverted (#643). `imageId` is one keystroke from the
        // declared `image_id`. Before this rule the action returned ok:true, stored the
        // key, and rendered nothing from it — reported success without effect, on the
        // field #614 had just finished hardening against a rarer mistake.
        $id = pp_create_page('Logo strip with a misspelled field', 'draft');
        pp_update_composition($id, [['component' => 'logos', 'props' => [
            'items' => [['image_url' => '/a.png', 'image_alt' => 'Acme']],
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme', 'imageId' => 42],
            ]],
        ]);

        $this->assertFalse($result['ok'], 'an undeclared item field is rejected since #643');
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('item 0 has no field "imageId"', $result['error']);
        $this->assertStringContainsString('Available fields: image_url, image_alt, image_id, label', $result['error']);

        // Nothing persisted: the whole action is refused, not partially applied.
        $items = pp_get_composition($id)[0]['props']['items'];
        $this->assertArrayNotHasKey('imageId', $items[0], 'the rejected key must not reach storage');
        $this->assertSame('Acme', $items[0]['image_alt'], 'the stored item is untouched');
    }

    public function testCreatePageRejectsAnUndeclaredNestedItemField(): void
    {
        // The create verb runs the same shared validator — there is no second surface
        // where an unknown item field is still accepted (#223 root-cause class).
        $result = pp_execute_action('create_page', [
            'title'       => 'Cards with a typo',
            'composition' => [['component' => 'grid', 'props' => [
                'items' => [['title' => 'Deploy', 'txet' => 'a typo for text']],
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'create_page validates through the same gate');
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('has no field "txet"', $result['error']);
    }

    public function testUpdateCompositionRejectsAnUndeclaredNestedItemField(): void
    {
        // The third write verb, and the one an agent reaches for when rewriting a whole
        // page: a single misspelled field in one card refuses the entire composition.
        $id = pp_create_page('Whole-composition rewrite', 'draft');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                ['component' => 'section',      'props' => ['title' => 'Intro', 'body' => 'Copy.']],
                ['component' => 'testimonials', 'props' => ['items' => [
                    ['quote' => 'It works.', 'auther' => 'Ada'],
                ]]],
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('prop "items" item 0 has no field "auther"', $result['error']);
        $this->assertStringContainsString('Available fields: quote, author, role, company', $result['error']);
    }

    public function testARenamedRequiredFieldIsRepairableInOneRound(): void
    {
        // ORDERING plus its consequence, both pinned. RULE 5 runs AFTER the declared-field
        // loop so a missing required field keeps first-error document order, exactly as the
        // top-level `unknown_prop` gate runs after the required-prop loop (#147/#621, and
        // the claim set makes that ordering load-bearing).
        //
        // That ordering means the write path — first-error-wins — would otherwise report
        // only the canonical name that is MISSING and never the key sitting next to it,
        // which is a guaranteed two-round repair: add image_url, keep imageUrl, get
        // rejected again. #622 closed exactly this at the top level with an undeclared-keys
        // hint on the missing-required message; #643 mirrors it here, same helper and same
        // grammar, so ONE message carries the whole repair.
        $id = pp_create_page('Renamed field', 'draft');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'logos', 'props' => ['items' => [
                ['imageUrl' => '/a.png', 'imageAlt' => 'Acme'],
            ]]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_composition', $result['error_code']);
        $this->assertSame(
            'Component 0 ("logos") prop "items" item 0 is missing required field "image_url".'
                . ' This item also carries field(s) "items" entries do not declare: imageUrl, imageAlt.'
                . ' Available fields: image_url, image_alt, image_id, label.',
            $result['error'],
            'one message must name what is missing AND what is sitting under the wrong name'
        );

        // Repairing to the canonical names in ONE pass now succeeds — the round trip the
        // hint exists to remove.
        $repair = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme'],
            ]]]],
        ]);
        $this->assertTrue($repair['ok'], $repair['error'] ?? 'the named repair must be sufficient');
    }

    public function testTheMissingRequiredHintReadsAlikeAtBothDepths(): void
    {
        // PARITY, asserted rather than described. #643's whole premise is that the nested
        // rules mirror the top-level ones; a hint that drifted in wording would be the
        // asymmetry the #614/#600/#634 arc exists to remove. Both messages are built from
        // _pp_render_undeclared_prop_keys() and both close with an `Available ...:` tail.
        $topLevel = pp_validate_composition([
            ['component' => 'cta', 'props' => ['title' => 'A', 'cta_text' => 'Go']],
        ]);
        $nested = pp_validate_composition([
            ['component' => 'logos', 'props' => ['items' => [['imageUrl' => '/a.png']]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $topLevel);
        $this->assertInstanceOf(\WP_Error::class, $nested);
        $this->assertSame('invalid_composition', $topLevel->get_error_code());
        $this->assertSame('invalid_composition', $nested->get_error_code());

        foreach ([$topLevel->get_error_message(), $nested->get_error_message()] as $message) {
            $this->assertStringContainsString('is missing required ', $message);
            $this->assertStringContainsString(' This item also carries ', $message);
            $this->assertStringContainsString(' does not declare: ', str_replace(
                ' entries do not declare: ', ' does not declare: ', $message
            ), 'both depths name the undeclared keys in the same clause');
            $this->assertMatchesRegularExpression('/ Available (props|fields): .+\.$/', $message);
        }
    }

    public function testTheDeclaredSpellingStillAuthorsCleanlyThroughTheActionLayer(): void
    {
        // The other half of every strictness rule: the advertised field names must still
        // author cleanly on the surface the docs point an agent at. `image_id` is the
        // declared spelling `imageId` was reaching for.
        $result = pp_execute_action('create_page', [
            'title'       => 'Logo strip',
            'composition' => [['component' => 'logos', 'props' => [
                'items' => [
                    ['image_url' => '/a.png', 'image_alt' => 'Acme', 'image_id' => 42, 'label' => 'Acme'],
                    ['image_url' => '/b.png', 'image_alt' => 'Globex'],
                ],
            ]]],
        ]);

        $this->assertTrue($result['ok'], 'the declared field must be accepted: ' . ($result['error'] ?? ''));
        $items = pp_get_composition((int) $result['target']['post_id'])[0]['props']['items'];
        $this->assertSame(42, $items[0]['image_id']);
        $this->assertArrayNotHasKey('image_id', $items[1], 'an omitted optional field stays omitted');
    }

    public function testAStoredUndeclaredNestedFieldBlocksAnEditToADifferentBand(): void
    {
        // THE ACCEPTED STALE-DATA COST, stated as a test rather than as a footnote. Every
        // read-modify-write action validates the WHOLE composition, so a page that already
        // stores an undeclared item key in band 0 cannot be edited at band 1 until band 0
        // is repaired. That is the v1.13.0 no-compat posture working as intended — the
        // alternatives are an alias or a silent strip, and both are barred. This is the
        // shape aged sites will meet after #643.
        $id = pp_create_page('Aged logo strip', 'draft');
        pp_update_composition($id, [
            ['component' => 'logos',   'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme', 'imageId' => 42],
            ]]],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);

        $this->assertFalse($result['ok'], 'the stale nested key must block the whole-composition validation');
        $this->assertStringContainsString('imageId', $result['error']);

        // Nothing was written: the stale band is untouched and the edit did not land.
        $composition = pp_get_composition($id);
        $this->assertSame('Other band', $composition[1]['props']['title']);
        $this->assertSame(42, $composition[0]['props']['items'][0]['imageId'], 'storage is never rewritten behind the author');
    }

    public function testRepairingTheStoredUndeclaredNestedFieldUnblocksTheWholeComposition(): void
    {
        // THE WAY OUT, which is what keeps the cost above a ruling rather than a bug: the
        // intended breakage must be escapable through the ordinary authoring surface, with
        // no migration and no tool the docs do not already describe.
        $id = pp_create_page('Repairable logo strip', 'draft');
        pp_update_composition($id, [
            ['component' => 'logos',   'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme', 'imageId' => 42],
            ]]],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertFalse($blocked['ok'], 'precondition: the stale band blocks the sibling edit');

        // Repair the band that actually holds the undeclared key. A prop shallow-merge
        // replaces the items array wholesale, exactly as the docs tell an agent.
        $repair = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme', 'image_id' => 42],
            ]],
        ]);
        $this->assertTrue($repair['ok'], $repair['error'] ?? 'repairing the band must be possible');

        $retry = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertTrue($retry['ok'], $retry['error'] ?? 'repairing the band unblocks the page');
        $this->assertSame('Edited', pp_get_composition($id)[1]['props']['title']);
    }

    public function testRestoreReportsAnUndeclaredNestedFieldWithoutBlocking(): void
    {
        // #643 is a validation rule landing after #233's restore policy, so it must prove
        // the shared engine reports it on restore WITHOUT blocking undo — no restore-
        // specific rule path, and no strip. This is the durability guarantee that makes
        // the stale-data cost above survivable: the snapshot comes back verbatim.
        $post_id = pp_create_page('Undeclared nested field snapshot');
        pp_update_composition($post_id, [
            ['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme', 'imageId' => 42],
            ]]],
        ]);
        pp_update_composition($post_id, [
            ['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'Acme'],
            ]]],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore failed');
        $this->assertSame(42, pp_get_composition($post_id)[0]['props']['items'][0]['imageId'],
            'the snapshot is restored verbatim — the undeclared key is preserved, not stripped');

        $errors = array_values(array_filter(
            $result['findings'],
            static function ($f) { return $f['severity'] === 'error'; }
        ));
        $this->assertContains('unknown_prop', array_column($errors, 'type'));
    }

    public function testRestorePreviewSurfacesTheUndeclaredNestedFieldFinding(): void
    {
        // Preview is where an operator learns, before committing, that the version they
        // are about to restore will refuse its next edit.
        $post_id = pp_create_page('Undeclared nested field preview');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Deploy', 'txet' => 'typo']]]],
        ]);
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Deploy']]]],
        ]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertArrayNotHasKey('txet', pp_get_composition($post_id)[0]['props']['items'][0],
            'preview did not write');
        $this->assertContains('unknown_prop', array_column($preview['findings'], 'type'));
    }

    public function testRepairingTheStoredNestedTextRoleUnblocksTheWholeComposition(): void
    {
        // THE WAY OUT, which is what keeps the cost above a ruling rather than a bug:
        // the intended breakage must be escapable through the ordinary authoring
        // surface, with no migration and no tool the docs do not already describe.
        $id = pp_create_page('Repairable card role', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid',    'props' => ['items' => [['title' => 'Legacy', 'text_role' => 'terminal']]]],
            ['component' => 'section', 'props' => ['title' => 'Other band', 'body' => 'C']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertFalse($blocked['ok'], 'precondition: the stale band blocks the sibling edit');

        // Repair the band that actually holds the out-of-set role. A prop shallow-merge
        // replaces the items array wholesale, exactly as the docs tell an agent.
        $repair = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [['title' => 'Legacy', 'text_role' => 'mono']]],
        ]);
        $this->assertTrue($repair['ok'], $repair['error'] ?? 'the offending band must be repairable in place');

        $retry = pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertTrue($retry['ok'], $retry['error'] ?? 'repairing the band unblocks the page');
        $composition = pp_get_composition($id);
        $this->assertSame('mono', $composition[0]['props']['items'][0]['text_role']);
        $this->assertSame('Edited', $composition[1]['props']['title']);
    }

    public function testTheStoredNestedTextRoleDoesNotBlockTheItemScopedActions(): void
    {
        // The blast radius is UNEVEN, and the unevenness is the same one AI_CONTEXT.md
        // already describes for retired prop NAMES: add_component validates only the
        // item it adds and style_component validates no props at all, so both still
        // succeed on the stale page and write the stale band back verbatim.
        $id = pp_create_page('Legacy card role, item-scoped actions', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Legacy', 'text_role' => 'terminal']]]],
        ]);

        $added = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'Fresh', 'text_role' => 'kicker']]],
        ]);
        $this->assertTrue($added['ok'], $added['error'] ?? 'add_component validates only the item it adds');

        // …but "only the item it adds" is still VALIDATED. The narrow blast radius is
        // about which bands are checked, never about the rule being optional on the
        // surface that writes them.
        $rejected = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'Also bogus', 'text_role' => 'terminal']]],
        ]);
        $this->assertFalse($rejected['ok'], 'add_component still enforces RULE 4 on the item it adds');
        $this->assertSame('invalid_prop_value', $rejected['error_code']);
        $this->assertCount(2, pp_get_composition($id), 'the rejected band must not be appended');

        $styled = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--grid-bg' => '#101014'],
        ]);
        $this->assertTrue($styled['ok'], $styled['error'] ?? 'style_component validates no props at all');

        $this->assertSame('terminal', pp_get_composition($id)[0]['props']['items'][0]['text_role']);
    }

    public function testRestoreCompositionReportsTheNestedTextRoleButNeverBlocks(): void
    {
        // The #233 rider, which every new rejection in the shared validator inherits:
        // restore_composition restores the snapshot VERBATIM and REPORTS the violation
        // in findings rather than blocking. Undo never fails, and the author is told
        // which declaration is dead instead of being locked out of their own history.
        $id = pp_create_page('Restorable card role', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Legacy', 'text_role' => 'terminal']]]],
        ]);
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Current', 'text_role' => 'mono']]]],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'restore must never block on a validation rule (#233)');
        $restored = pp_get_composition($id);
        $this->assertSame('terminal', $restored[0]['props']['items'][0]['text_role'], 'the snapshot is restored verbatim');
        $findings = json_encode($result['findings'] ?? []);
        $this->assertStringContainsString('text_role', $findings, 'the dead declaration is reported, not silently restored');
    }

    public function testUpdateComponentRejectsTheRemovedThemeValueWrittenDirectly(): void
    {
        // The removed value is rejected at WRITE, on the surface an agent actually uses.
        $id = pp_create_page('Direct removed-value write', 'draft');
        pp_update_composition($id, [['component' => 'section', 'props' => ['title' => 'A', 'body' => 'B']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['theme' => 'dark'],
        ]);

        $this->assertFalse($result['ok'], '`dark` must be rejected, not accepted as an alias');
        $this->assertStringContainsString('default, muted, inverted', $result['error']);
        $this->assertArrayNotHasKey('theme', pp_get_composition($id)[0]['props'], 'nothing persisted');
    }

    public function testUpdateComponentRejectsAnUndeclaredThemeValue(): void
    {
        // Since #605 there is no alias tier at all: `dark` and `darkish` are both
        // simply unadvertised values, and both are rejected the same way.
        $id = pp_create_page('Undeclared theme', 'draft');
        pp_update_composition($id, [['component' => 'section', 'props' => ['title' => 'A', 'body' => 'B']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['theme' => 'darkish'],
        ]);

        $this->assertFalse($result['ok']);
        // The error names the canonical values — and since #605 that list IS the
        // whole accepted set, with no accepted-but-unadvertised footnote behind it.
        $this->assertStringContainsString('default, muted, inverted', $result['error']);
        $this->assertStringNotContainsString('dark;', $result['error']);
        $this->assertStringNotContainsString('legacy', $result['error']);
    }

    public function testUpdateComponentRejectsNonScalarStringProp(): void
    {
        $id = pp_create_page('Non-scalar title', 'draft');
        pp_update_composition($id, [['component' => 'cta', 'props' => ['title' => 'Ok', 'button_text' => 'Go', 'button_url' => '/']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => ['unexpected' => 'array']],
        ]);
        $this->assertFalse($result['ok'], 'a non-scalar title must not persist behind ok:true');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('must be a string', $result['error']);
        // The prior composition is intact.
        $this->assertSame('Ok', pp_get_composition($id)[0]['props']['title']);
    }

    public function testUpdateCompositionRejectsScalarWhereObjectItemArrayBelongs(): void
    {
        $id = pp_create_page('Bad grid items', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => ['items' => [['title' => 'One']]]]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'grid', 'props' => ['items' => ['just a string']]]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('must be an object', $result['error']);
        // The prior valid composition never got replaced.
        $this->assertSame('One', pp_get_composition($id)[0]['props']['items'][0]['title']);
    }

    public function testUpdateComponentRejectsDeadButtonInNestedGridLink(): void
    {
        $id = pp_create_page('Nested dead link', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => ['items' => [['title' => 'One']]]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => 'One', 'link_url' => '/ok'],
                ['title' => 'Two', 'link_url' => 'data:text/html,x'],
            ]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('item 1', $result['error']);
        $this->assertStringContainsString('link_url', $result['error']);
    }

    public function testRestoreReportsDeadButtonLinkWithoutBlocking(): void
    {
        // #233 pattern for the #507 rule: restore never blocks, so a snapshot holding
        // a dead-button link (seeded past write-time validation) restores verbatim and
        // reports the violation as a finding rather than a bare ok:true.
        $post_id = pp_create_page('Dead link snapshot');
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => ['button_text' => 'Go', 'button_url' => 'javascript:alert(1)']],
        ]);
        pp_update_composition($post_id, [['component' => 'cta', 'props' => ['button_text' => 'Go', 'button_url' => '/ok']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must not block');
        $this->assertSame('javascript:alert(1)', pp_get_composition($post_id)[0]['props']['button_url'], 'content restored verbatim');
        $this->assertContains('invalid_prop_value', array_column($result['findings'], 'type'), 'the dead-button link is reported as a finding');
    }

    // ── The legacy prop-KEY alias surface is GONE (#604) ──
    //
    // #495 shipped a 13-entry map of component-scoped prop RENAMES (cta.cta_text ->
    // button_text, hero.subtitle -> subheading, section/grid/testimonials.heading_align
    // -> title_align, ...), extended by #576 and routed through every read path by
    // #575. #604 deleted all of it, under the #570 Addendum #4 ruling: backward
    // compatibility, old compositions, migrations and convenience aliases are
    // NON-GOALS, and a speculative or legacy-driven benefit files for removal.
    //
    // WHY THIS IS A VALIDATION STRENGTHENING, NOT A LOOSENING. The map was accepted at
    // WRITE and healed silently — no `changes` entry was emitted — so an agent that
    // wrote `cta_text` got ok:true and never learned it had used a retired name. That
    // is a hole in the #147 strict unknown_prop gate, sized at exactly 13 names.
    // Removing it returns all 13 to a named rejection. The tests below are the proof,
    // and they author through the REAL action surface (Section 14.1), not raw meta.
    //
    // THE STALE-DATA CONSEQUENCE IS INTENDED, NOT A REGRESSION. A stored composition
    // carrying a retired name renders the SCHEMA DEFAULT for that prop, and any
    // whole-composition validating action on that page now fails. Do not "fix" this
    // with a migration, a coercion, a warning-only tolerance or a widened schema —
    // all four are explicitly ruled out. `restore_composition` still restores verbatim
    // and REPORTS the violations (#233); that is pinned below too.

    /**
     * Every one of the 13 retired prop names rejects on every validating write path.
     *
     * The map was per-component and that shape is preserved in the rejection: the
     * fixture pairs each retired name with the component it was retired ON, so a
     * partial removal that left one component's entries behind fails here.
     */
    public static function retiredPropNameProvider(): array
    {
        return [
            'cta.cta_text'                => ['cta', ['cta_text' => 'Go'], 'cta_text'],
            'cta.cta_url'                 => ['cta', ['cta_url' => '/go'], 'cta_url'],
            'cta.text'                    => ['cta', ['text' => 'Body copy'], 'text'],
            'hero.subtitle'               => ['hero', ['subtitle' => 'Sub'], 'subtitle'],
            'hero.cta_text'               => ['hero', ['cta_text' => 'Go'], 'cta_text'],
            'hero.cta_url'                => ['hero', ['cta_url' => '/go'], 'cta_url'],
            'hero.cta_variant'            => ['hero', ['cta_variant' => 'primary'], 'cta_variant'],
            'hero.cta2_text'              => ['hero', ['cta2_text' => 'More'], 'cta2_text'],
            'hero.cta2_url'               => ['hero', ['cta2_url' => '/more'], 'cta2_url'],
            'hero.cta2_variant'           => ['hero', ['cta2_variant' => 'outline'], 'cta2_variant'],
            'section.heading_align'       => ['section', ['heading_align' => 'center'], 'heading_align'],
            'grid.heading_align'          => ['grid', ['heading_align' => 'center'], 'heading_align'],
            'testimonials.heading_align'  => ['testimonials', ['heading_align' => 'center'], 'heading_align'],
        ];
    }

    /** Minimal canonical props so a rejection is attributable to the retired name alone. */
    private static function canonicalBaseProps(string $component): array
    {
        switch ($component) {
            case 'cta':          return ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go'];
            case 'hero':         return ['title' => 'T'];
            case 'grid':         return ['title' => 'T', 'items' => [['title' => 'One']]];
            case 'testimonials': return ['title' => 'T', 'items' => [['quote' => 'Great.']]];
            default:             return ['title' => 'T', 'body' => 'B'];
        }
    }

    /** @dataProvider retiredPropNameProvider */
    public function testCreatePageRejectsEveryRetiredPropName(string $component, array $legacy, string $name): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => "Retired {$name} on create",
            'composition' => [['component' => $component, 'props' => array_merge(self::canonicalBaseProps($component), $legacy)]],
        ]);

        $this->assertFalse($result['ok'], "create_page must reject the retired prop {$component}.{$name}");
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString($name, $result['error'], 'the error names the retired prop');
    }

    /** @dataProvider retiredPropNameProvider */
    public function testUpdateCompositionRejectsEveryRetiredPropName(string $component, array $legacy, string $name): void
    {
        $id = pp_create_page("Retired {$name} on replace", 'draft');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => $component, 'props' => array_merge(self::canonicalBaseProps($component), $legacy)]],
        ]);

        $this->assertFalse($result['ok'], "update_composition must reject the retired prop {$component}.{$name}");
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString($name, $result['error']);
    }

    /** @dataProvider retiredPropNameProvider */
    public function testAddComponentRejectsEveryRetiredPropName(string $component, array $legacy, string $name): void
    {
        $id = pp_create_page("Retired {$name} on add", 'draft');
        pp_update_composition($id, [['component' => 'section', 'props' => ['body' => 'x']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => $component,
            'props'     => array_merge(self::canonicalBaseProps($component), $legacy),
        ]);

        $this->assertFalse($result['ok'], "add_component must reject the retired prop {$component}.{$name}");
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString($name, $result['error']);
        $this->assertCount(1, pp_get_composition($id), 'the rejected component was not appended');
    }

    /** @dataProvider retiredPropNameProvider */
    public function testUpdateComponentRejectsEveryRetiredPropName(string $component, array $legacy, string $name): void
    {
        $id = pp_create_page("Retired {$name} on patch", 'draft');
        pp_update_composition($id, [['component' => $component, 'props' => self::canonicalBaseProps($component)]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => $legacy,
        ]);

        $this->assertFalse($result['ok'], "update_component must reject the retired prop {$component}.{$name}");
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString($name, $result['error']);
        $this->assertArrayNotHasKey($name, pp_get_composition($id)[0]['props'], 'the rejected patch did not land');
    }

    /**
     * The both-keys case, which canonical-wins used to absorb silently.
     *
     * An item storing BOTH the canonical and the retired name used to keep the
     * canonical value and DROP the retired key without a word. Now the retired key is
     * simply unknown, so the write is rejected on its name even though a perfectly
     * good canonical value sits beside it — and, critically, the canonical value is
     * NOT quietly rewritten or lost on the way to that rejection.
     */
    public function testBothKeysStoredRejectsOnTheRetiredNameAndLeavesStorageUntouched(): void
    {
        $id = pp_create_page('Conflict page', 'draft');
        // Thin writer, no validation — persists the both-keys shape as a live install would hold it.
        pp_update_composition($id, [
            ['component' => 'cta', 'props' => [
                'cta_text'    => 'OLD legacy label',
                'button_text' => 'NEW canonical label',
                'cta_url'     => 'https://old.example.com',
                'button_url'  => 'https://new.example.com',
            ]],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Heading'],
        ]);

        $this->assertFalse($result['ok'], 'the retired key is unknown, so the whole-composition write is rejected');
        $this->assertSame('unknown_prop', $result['error_code']);

        // The rejected write left the stored bytes exactly as they were: no heal, no
        // canonical-wins drop, no partial rewrite.
        $cta = pp_get_composition($id)[0]['props'];
        $this->assertSame('OLD legacy label', $cta['cta_text'], 'the retired key is still stored verbatim');
        $this->assertSame('NEW canonical label', $cta['button_text'], 'the canonical value was never touched');
        $this->assertSame('https://old.example.com', $cta['cta_url']);
        $this->assertSame('https://new.example.com', $cta['button_url']);
        $this->assertArrayNotHasKey('title', $cta, 'the rejected edit did not land');
    }

    /**
     * THE HEADLINE CONSEQUENCE, pinned: an UNTOUCHED band carrying a retired prop name
     * now blocks an edit to a DIFFERENT band on the same page.
     *
     * This is the exact dogfood bug #495's alias map was built to fix, deliberately
     * re-introduced by #604. Every action validates the WHOLE composition, so one stale
     * band is enough to refuse an unrelated edit. Under the governing ruling that is
     * correct — the page genuinely does not satisfy the current contract, and silently
     * healing it around the author was the behavior being removed. Author the canonical
     * name (or fix the stale band) and the edit lands.
     *
     * Pinned rather than narrated because it is the single most user-visible effect of
     * this gate, and a future iteration that "fixes" it has re-added the alias surface.
     */
    public function testUntouchedBandWithARetiredPropNameBlocksAnEditToAnotherBand(): void
    {
        // TWO stale shapes, because they fail with DIFFERENT codes and both matter:
        //   `section.heading_align` — section declares no required props, so the retired
        //      name reaches the unknown-prop gate and is named directly.
        //   `cta.cta_text/cta_url`  — the alias was the ONLY thing satisfying cta's
        //      REQUIRED button_text/button_url, so the required-prop check (which runs
        //      first and short-circuits the item) reports invalid_composition instead.
        // A test asserting only one of these would miss half the breakage.
        $cases = [
            ['section', ['title' => 'Intro', 'body' => 'Copy.', 'heading_align' => 'center'], 'unknown_prop', 'heading_align'],
            ['cta',     ['cta_text' => 'View', 'cta_url' => '/repo'],      'invalid_composition', 'button_text'],
        ];

        foreach ($cases as [$component, $staleProps, $code, $needle]) {
            $id = pp_create_page("Stale {$component} sibling", 'draft');
            // Thin writer, no validation — persists the stale shape as a live install holds it.
            pp_update_composition($id, [
                ['component' => 'section',   'props' => ['title' => 'Intro', 'body' => 'Hello world.']],
                ['component' => $component,  'props' => $staleProps],
            ]);

            $result = pp_execute_action('update_component', [
                'post_id'         => $id,
                'component_index' => 0, // the FIRST section — never the stale band
                'props'           => ['title' => 'Updated intro'],
            ]);

            $this->assertFalse($result['ok'], "a stale untouched {$component} must block the whole-composition write");
            $this->assertSame($code, $result['error_code'], "{$component} rejects with {$code}");
            $this->assertStringContainsString($needle, $result['error'], 'the error points at the offending band');

            // Nothing landed: the rejected write left both bands exactly as stored.
            $comp = pp_get_composition($id);
            $this->assertSame('Intro', $comp[0]['props']['title'], 'the rejected edit did not land');
            // Subset compare: pp_update_composition() injects its own props.id.
            foreach ($staleProps as $k => $v) {
                $this->assertSame($v, $comp[1]['props'][$k], "the stale band kept {$k} — no heal behind the rejection");
            }
        }
    }

    /**
     * add_component's PREVIEW and EXECUTE must agree, byte for byte.
     *
     * Both dropped their _pp_apply_legacy_prop_aliases() call in #604. They dropped it
     * independently, so a partial removal that fixed one and not the other would make
     * the preview a lie about what gets stored. That is exactly the divergence class
     * this pin exists to catch.
     */
    public function testAddComponentPreviewMatchesWhatExecuteStores(): void
    {
        $id = pp_create_page('Add preview parity', 'draft');
        pp_update_composition($id, [['component' => 'section', 'props' => ['body' => 'x']]]);
        $props = ['title' => 'Ready?', 'button_text' => 'Sign up', 'button_url' => '/signup'];

        $preview = pp_get_action('add_component')['preview']([
            'post_id' => $id, 'component' => 'cta', 'props' => $props,
        ]);
        $this->assertTrue($preview['ok'], $preview['error'] ?? 'preview must succeed');
        foreach ($props as $k => $v) {
            $this->assertSame($v, $preview['after'][1]['props'][$k], "preview carries {$k} verbatim");
        }

        $result = pp_execute_action('add_component', [
            'post_id' => $id, 'component' => 'cta', 'props' => $props,
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'execute must succeed');

        $stored = pp_get_composition($id)[1]['props'];
        foreach ($props as $k => $v) {
            $this->assertSame($v, $stored[$k], "execute stores {$k} exactly as previewed");
        }
    }

    /**
     * A genuinely unknown key and a RETIRED key are now the same class of error.
     *
     * Before #604 these were different: `not_a_real_prop` rejected while `cta_text`
     * was healed. Asserting both reject with the same code is what makes "the 13
     * names returned to the gate" a property rather than a claim.
     */
    public function testRetiredAndUnknownKeysRejectIdenticallyOnALegacyComposition(): void
    {
        $id = pp_create_page('Legacy-shaped page', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Intro', 'body' => 'Hello world.']],
            ['component' => 'cta',     'props' => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        foreach (['not_a_real_prop', 'cta_text'] as $key) {
            $result = pp_execute_action('update_component', [
                'post_id'         => $id,
                'component_index' => 1,
                'props'           => [$key => 'x'],
            ]);

            $this->assertFalse($result['ok'], "{$key} must reject");
            $this->assertSame('unknown_prop', $result['error_code'], "{$key} rejects with unknown_prop");
            $this->assertStringContainsString($key, $result['error'], "the error names {$key}");
        }
    }

    /**
     * A stored retired name is handed back VERBATIM by the read path.
     *
     * The array half of the stale-data breakage: the read resolves nothing, so the
     * canonical key the renderer wants is simply absent and the prop falls back to its
     * schema default downstream. The RENDERED half is proven separately, in
     * StoredCompositionAliasRenderTest, which drives the real template loop.
     */
    public function testStoredRetiredPropIsReadBackVerbatim(): void
    {
        $id = pp_create_page('Stale render', 'draft');
        pp_update_composition($id, [
            ['component' => 'cta', 'props' => ['title' => 'Still here', 'cta_text' => 'Lost label', 'cta_url' => '/lost']],
        ]);

        $comp = pp_get_composition($id);
        $this->assertSame('Lost label', $comp[0]['props']['cta_text'], 'the read returns the stored bytes, unresolved');
        $this->assertArrayNotHasKey('button_text', $comp[0]['props'], 'nothing manufactures the canonical key on read');
        $this->assertSame('Still here', $comp[0]['props']['title'], 'canonical props on the same band are unaffected');
    }

    /**
     * restore_composition keeps its #233 contract against the removal.
     *
     * A snapshot carrying retired names restores VERBATIM (no heal, no rewrite) and
     * the violations are REPORTED as findings rather than blocking the restore. Undo
     * must never fail, even when what it restores no longer validates.
     */
    public function testRestoreKeepsRetiredPropsVerbatimAndReportsThemAsFindings(): void
    {
        $id = pp_create_page('Restore stale', 'draft');
        // v1: a snapshot carrying retired names on two bands. The `section` band shows the
        // unknown-prop class cleanly; the `cta` band additionally loses its REQUIRED props
        // (button_text/button_url used to be satisfied by the alias), which is the other
        // half of the stale-data breakage and is reported as invalid_composition.
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['body' => 'Copy.', 'heading_align' => 'center']],
            ['component' => 'cta',     'props' => ['title' => 'T', 'cta_text' => 'Legacy', 'cta_url' => '/legacy']],
        ]);
        // v2: a different composition, so v1 is pushed onto the history ring.
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['body' => 'current']],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $this->assertTrue($result['ok'], 'restore must never block on current validation rules (#233)');

        $restored = pp_get_composition($id);
        $this->assertSame('center', $restored[0]['props']['heading_align'], 'the snapshot is restored VERBATIM, not healed');
        $this->assertArrayNotHasKey('title_align', $restored[0]['props'], 'restore invents no canonical key');
        $this->assertSame('Legacy', $restored[1]['props']['cta_text']);
        $this->assertSame('/legacy', $restored[1]['props']['cta_url']);
        $this->assertArrayNotHasKey('button_text', $restored[1]['props']);

        $types = array_column($result['findings'], 'type');
        $this->assertContains('unknown_prop', $types, 'the retired prop name is REPORTED as a finding');
        $this->assertContains(
            'invalid_composition',
            $types,
            'the cta whose required props were only satisfied by the alias is reported too'
        );
    }
    // ── Retired `variant` prop is rejected at write time (#388) ──
    //
    // #69 split `variant` into `layout`/`theme`. v1's public API accepts NO alias:
    // every write path must reject `variant` with unknown_prop, never silently migrate
    // it. Restore/read paths still decode it (see testRestoreNormalizesLegacyVariantSnapshot)
    // — that asymmetry is the whole point of #388. One test per write path.

    public function testCreatePageRejectsRetiredVariantProp(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Legacy variant on create',
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hi', 'variant' => 'split']]],
        ]);

        $this->assertFalse($result['ok'], 'v1 write path must not accept the retired variant prop');
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('variant', $result['error']);
    }

    public function testUpdateCompositionRejectsRetiredVariantProp(): void
    {
        $id = pp_create_page('Legacy variant on replace', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Original']]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'section', 'props' => ['body' => 'x', 'variant' => 'dark']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('variant', $result['error']);
        // The rejected replacement never landed; the prior composition is intact.
        $this->assertSame('Original', pp_get_composition($id)[0]['props']['title']);
    }

    public function testAddComponentRejectsRetiredVariantProp(): void
    {
        $id = pp_create_page('Legacy variant on add', 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'cta',
            'props'     => ['button_text' => 'Go', 'button_url' => '/x', 'variant' => 'inline'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('variant', $result['error']);
        $this->assertCount(1, pp_get_composition($id), 'the rejected component was not appended');
    }

    public function testUpdateComponentRejectsRetiredVariantProp(): void
    {
        $id = pp_create_page('Legacy variant on update', 'draft');
        pp_update_composition($id, [['component' => 'section', 'props' => ['body' => 'Hi']]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['variant' => 'inverted'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_prop', $result['error_code']);
        $this->assertStringContainsString('variant', $result['error']);
        $this->assertArrayNotHasKey('variant', pp_get_composition($id)[0]['props']);
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

    public function testRestoreKeepsLegacyVariantVerbatimAndReportsIt(): void
    {
        // SUPERSEDES testRestoreNormalizesLegacyVariantSnapshot (#233, #388 -> #604).
        //
        // The old pin was a TRIPWIRE guarding pp_migrate_legacy_variant_keys() against
        // deletion, on the argument that removing it without a history-ring migration
        // would make an old page "come back subtly wrong rather than loudly wrong".
        // #604 removed the shim and deliberately did NOT ship that migration: backward
        // compatibility and migrations are NON-GOALS, and the loud failure is the point.
        //
        // So the contract inverts. A pre-#69 snapshot restores VERBATIM — `variant`
        // survives, no `layout` is manufactured — and the retired key is REPORTED in
        // findings instead of being silently decoded. Restore still never blocks (#233).
        $post_id = pp_create_page('Legacy variant snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok'], 'restore must not block on a snapshot current rules reject (#233)');
        $this->assertContains(
            'unknown_prop',
            array_column($result['findings'], 'type'),
            'the retired variant key is REPORTED rather than silently decoded'
        );

        $props = pp_get_composition($post_id)[0]['props'];
        $this->assertSame('left', $props['variant'], 'the snapshot is restored verbatim');
        $this->assertArrayNotHasKey('layout', $props, 'nothing manufactures layout from variant any more');
    }

    public function testRestoreNormalizesAndReportsOnTheSameSnapshot(): void
    {
        // The #233 contract on one snapshot carrying TWO violation classes: chrome, and a
        // retired `variant` key. Both must be preserved verbatim and both must be
        // reported. Neither may swallow the other, and neither may block the restore.
        // (Pre-#604 the variant half asserted decoding; #604 removed the read migration,
        // so the assertion is now verbatim-plus-report on both halves alike.)
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
        $this->assertSame('left', $restored[1]['props']['variant'], 'the retired key is preserved verbatim');
        $this->assertArrayNotHasKey('layout', $restored[1]['props'], 'no layout is manufactured');

        $types = array_column($result['findings'], 'type');
        $this->assertContains('template_owned_component', $types);
        $this->assertContains('unknown_prop', $types, 'the retired variant key is reported alongside the chrome');
    }

    public function testRestorePreviewShowsLegacyVariantVerbatimAndFlagsIt(): void
    {
        // SUPERSEDES testRestorePreviewMigratesLegacyVariantAndDoesNotFlagIt (#388 -> #604).
        //
        // The preview describes what execute will actually write. Since #604 that is the
        // snapshot's own bytes, so `after` shows `variant` and findings DO surface
        // unknown_prop for it — the operator is told, before committing, that the
        // declaration they are restoring no longer paints.
        $post_id = pp_create_page('Legacy variant preview');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($preview['ok']);
        $this->assertSame('left', $preview['after'][0]['props']['variant'], 'preview shows the stored key verbatim');
        $this->assertArrayNotHasKey('layout', $preview['after'][0]['props'], 'preview manufactures no layout');
        $this->assertContains(
            'unknown_prop',
            array_column($preview['findings'], 'type'),
            'the retired key is flagged in the preview, before the operator commits'
        );
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
        // preview.after is the exact target execute persists. The parity property is what
        // matters and it is unchanged by #604 — only the shape both sides agree on moved,
        // from the decoded `layout` to the stored `variant`. Using a retired key here is
        // deliberate: parity is easiest to break precisely where one side normalizes.
        $post_id = pp_create_page('Preview parity');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'A', 'variant' => 'left']],
        ]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('left', $preview['after'][0]['props']['variant']);

        pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertSame('left', pp_get_composition($post_id)[0]['props']['variant']);
        $this->assertSame(
            $preview['after'],
            pp_get_composition($post_id),
            'preview.after and the stored result are the same array, byte for byte'
        );
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

    // ── #160: page lifecycle actions reject unsaved auto-draft targets ──────
    // The four actions that assume a real, materialized page reject a target
    // still in 'auto-draft' with the standard ok:false / error_code:auto_draft
    // envelope, authored through the real pp_execute_action() surface. The two
    // promote-on-write actions (update_composition, update_page_title) and
    // restore_page are deliberately NOT gated — pinned elsewhere in this file.

    public function testPublishPageRejectsAutoDraftTarget(): void
    {
        $id = pp_create_page('', 'auto-draft');
        $result = pp_execute_action('publish_page', ['post_id' => $id]);
        $this->assertFalse($result['ok']);
        $this->assertSame('auto_draft', $result['error_code']);
        // Rejected before execute: still a hidden auto-draft, not published.
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testTrashPageRejectsAutoDraftTarget(): void
    {
        $id = pp_create_page('', 'auto-draft');
        $result = pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertFalse($result['ok']);
        $this->assertSame('auto_draft', $result['error_code']);
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testUpdatePageSlugRejectsAutoDraftTarget(): void
    {
        $id = pp_create_page('', 'auto-draft');
        $result = pp_execute_action('update_page_slug', ['post_id' => $id, 'slug' => 'product']);
        $this->assertFalse($result['ok']);
        $this->assertSame('auto_draft', $result['error_code']);
        // Accepted side effect (#160): a slug write no longer promotes an auto-draft.
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testUpdateSeoMetaRejectsAutoDraftTarget(): void
    {
        $id = pp_create_page('', 'auto-draft');
        $result = pp_execute_action('update_seo_meta', [
            'post_id' => $id,
            'meta'    => ['meta_description' => 'Should not apply'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('auto_draft', $result['error_code']);
        // Accepted side effect (#160): an SEO write no longer promotes an auto-draft.
        $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testPublishPageAllowsRealStatusTargets(): void
    {
        // Normal statuses are unaffected by the auto-draft gate.
        $draft = pp_create_page('Draft Page', 'draft');
        $this->assertTrue(pp_execute_action('publish_page', ['post_id' => $draft])['ok']);
        $this->assertSame('publish', $GLOBALS['_pp_test_store']['posts'][$draft]['post_status']);

        $published = pp_create_page('Published Page', 'publish');
        $this->assertTrue(pp_execute_action('publish_page', ['post_id' => $published])['ok']);
    }

    public function testTrashPageAllowsRealStatusTarget(): void
    {
        $id = pp_create_page('Draft Page', 'draft');
        $result = pp_execute_action('trash_page', ['post_id' => $id]);
        $this->assertTrue($result['ok']);
        $this->assertSame('trash', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testAutoDraftGateLeavesPromoteThenPublishLifecycleIntact(): void
    {
        // The #121 promote-on-write path is untouched: writing composition to a
        // brand-new auto-draft still succeeds and promotes it to 'draft', after
        // which publish_page (now gated) accepts it as a real page.
        $id = pp_create_page('', 'auto-draft');
        $write = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hi']]],
        ]);
        $this->assertTrue($write['ok']);
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);

        $publish = pp_execute_action('publish_page', ['post_id' => $id]);
        $this->assertTrue($publish['ok']);
        $this->assertSame('publish', $GLOBALS['_pp_test_store']['posts'][$id]['post_status']);
    }

    public function testRejectAutoDraftHelperIsDefensiveOnEmptyId(): void
    {
        // Callers run _pp_validate_page_exists() first; the guard must not treat
        // a 0 id as an auto-draft (get_post(0) yields no post).
        $this->assertTrue(_pp_reject_auto_draft(0));
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
        $this->assertSame([
            'meta_description' => '',
            'seo_title'        => '',
            'canonical_url'    => '',
            'og_title'         => '',
            'twitter_title'    => '',
        ], $meta);
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

    // ── #471: non-ASCII / special-char round-trip through the store ─────────
    // These read the meta straight back after a write and assert byte-identical
    // values. Before the wp_slash()+JSON_UNESCAPED_UNICODE fix, update_post_meta
    // unslashed the \uXXXX escapes wp_json_encode() emitted, so "á" was stored
    // as the literal "u00e1". The harness (tests/bootstrap.php) now models WP's
    // unslash-on-write, so a missing wp_slash() would resurface the corruption.

    public function testUpdateSeoMetaPreservesSpanishAccentsAndEmDash(): void
    {
        $id = pp_create_page('Page', 'draft');
        $value = 'prueba áéíóú ñ —guion';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaPreservesNonBmpEmoji(): void
    {
        // Non-BMP (4-byte UTF-8) must survive too — the composition path already
        // stores raw UTF-8 the same way, and WP's meta table is utf8mb4.
        $id = pp_create_page('Page', 'draft');
        $value = 'Launch day 🚀🎉 — vámonos';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaPreservesNonAsciiInSeoTitle(): void
    {
        $id = pp_create_page('Page', 'draft');
        $value = 'Título en Español — 日本語';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['seo_title' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['seo_title']);
    }

    public function testUpdateSeoMetaPreservesDoubleQuotes(): void
    {
        $id = pp_create_page('Page', 'draft');
        $value = 'She said "hola" to everyone';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaPreservesLiteralBackslash(): void
    {
        $id = pp_create_page('Page', 'draft');
        $value = 'path C:\\Users\\ñoño and a trailing \\';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaPreservesLiteralUnicodeEscapeString(): void
    {
        // A user who literally types the six characters á must get them
        // back verbatim — not have them collapse to "á" or "u00e1".
        $id = pp_create_page('Page', 'draft');
        $value = 'literal escape: \\u00e1 stays as-is';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => $value]]);
        $this->assertSame($value, pp_get_seo_meta($id)['meta_description']);
    }

    public function testUpdateSeoMetaNonAsciiPatchDoesNotCorruptExistingKey(): void
    {
        // The stored JSON is re-read and merged on every patch; a non-ASCII
        // value written first must survive a later patch to a different key.
        $id = pp_create_page('Page', 'draft');
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['meta_description' => 'café ñandú —']]);
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['seo_title' => 'Título']]);
        $meta = pp_get_seo_meta($id);
        $this->assertSame('café ñandú —', $meta['meta_description']);
        $this->assertSame('Título', $meta['seo_title']);
    }

    public function testSeoMetaDescriptionTagRendersNonAsciiCorrectly(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['meta_description' => 'prueba áéíóú ñ —guion']);
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        ob_start();
        pp_seo_meta_description_tag();
        $html = ob_get_clean();
        $this->assertStringContainsString('content="prueba áéíóú ñ —guion"', $html);
        $this->assertStringNotContainsString('u00e1', $html);
    }

    public function testSeoDocumentTitleOverrideReturnsNonAsciiVerbatim(): void
    {
        $id = pp_create_page('Page', 'draft');
        pp_update_seo_meta($id, ['seo_title' => 'Título en Español — 日本語']);
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        $this->assertSame('Título en Español — 日本語', pp_seo_document_title_override(''));
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
            ['component' => 'cta', 'props' => ['title' => 'CTA', 'body' => 'Click', 'button_text' => 'Go', 'button_url' => '#']],
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
            ['component' => 'cta', 'props' => ['title' => 'C', 'body' => 'Go', 'button_text' => 'Click', 'button_url' => '#']],
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
            ['component' => 'hero', 'props' => ['title' => 'Original', 'subheading' => 'Keep this']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Updated'],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertEquals('Updated', $comp[0]['props']['title']);
        $this->assertEquals('Keep this', $comp[0]['props']['subheading']);
    }

    public function testUpdateComponentNullRemovesProp(): void
    {
        $id = pp_create_page('Null Test', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Stay', 'subheading' => 'Remove me']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['subheading' => null],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($id);
        $this->assertEquals('Stay', $comp[0]['props']['title']);
        $this->assertArrayNotHasKey('subheading', $comp[0]['props']);
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

    // ── #154: schema-driven image-URL prop allowlist ──────────────────────

    /**
     * The media-validated prop set is derived from the schemas, not a hardcoded
     * array. Pins current coverage: adding a `format: image_url` prop with a NEW
     * NAME to any schema changes this set and trips the assertion, forcing a
     * conscious review that the new prop really should be media-validated —
     * instead of a hand-maintained list in lib/actions.php silently drifting.
     */
    public function testSchemaImageUrlPropsDerivedFromSchemas(): void
    {
        $this->assertSame(
            ['background_image', 'image_url'],
            _pp_schema_image_url_props(),
            'Derived image-URL prop set drifted. If you added a new format:image_url '
            . 'prop NAME, update this baseline and confirm it must be media-validated.'
        );
    }

    /**
     * Drift-catcher (#154), forgotten-annotation axis. A prop that IS an image URL
     * but forgets `"format": "image_url"` would silently bypass media validation.
     * Two nets:
     *   (a) convention pin — every prop literally named `image_url` or
     *       `background_image`, at top level or in an items[] item schema, MUST
     *       carry the format (a new component reusing the canonical name can't
     *       forget it);
     *   (b) description net — any string prop whose description reads as a media
     *       image URL (and isn't an *_id / *_alt / link prop) MUST carry it too,
     *       catching a differently-NAMED future image prop (avatar_url, poster_url…);
     *   (c) *_image suffix net — a string prop whose NAME ends in `_image` is
     *       unambiguously an image (unlike `_url`, which is shared with link
     *       props like button_url/link_url), so it MUST carry the format too.
     *
     * Residual limitation (disclosed, not a regression): a novel image prop named
     * with an ambiguous `_url` suffix AND a non-image-sounding description (e.g.
     * "poster_url" described only as "The hero poster.") is caught by none of the
     * three nets. It is still media-validated the moment its schema declares
     * format:image_url — and it was equally unvalidated before #154 — so this is a
     * limit of syntactic drift detection, not a coverage regression.
     */
    public function testEveryImageShapedSchemaPropDeclaresImageUrlFormat(): void
    {
        $canonicalNames = ['image_url', 'background_image'];
        // A description clearly denoting a media image URL.
        $imageDescRe = '/\bimage url\b|\bavatar image\b|\bbackground image url\b|\bphoto url\b/i';
        // Unambiguous image-name suffix (NOT `_url`, which link props also use).
        $imageNameRe = '/_image$/';
        // Props that are URLs but NOT media images, or not URLs at all.
        $excludeNameRe = '/(_id|_alt|link_url|button_url|cta\d?_url|panel_cta_url)$/';

        $checked = 0;
        foreach (pp_get_registered_components() as $component => $schema) {
            if (!isset($schema['props']) || !is_array($schema['props'])) {
                continue;
            }
            // Flatten to (name, def) pairs across top level + one items[] level.
            $pairs = [];
            foreach ($schema['props'] as $name => $def) {
                $pairs[] = [$name, $def, ''];
                if (is_array($def) && isset($def['items']) && is_array($def['items'])) {
                    foreach ($def['items'] as $iname => $idef) {
                        $pairs[] = [$iname, $idef, "{$name}[]."];
                    }
                }
            }
            foreach ($pairs as [$name, $def, $path]) {
                if (!is_array($def)) {
                    continue;
                }
                $hasFormat = _pp_prop_def_is_image_url($def);
                $isString  = ($def['type'] ?? null) === 'string';
                $desc      = (string) ($def['description'] ?? '');
                $where     = "{$component}.{$path}{$name}";

                // (a) convention pin
                if (in_array($name, $canonicalNames, true)) {
                    $this->assertTrue($hasFormat, "{$where} is a canonical image prop but is missing \"format\": \"image_url\".");
                    $checked++;
                    continue;
                }
                // (b) description net + (c) *_image suffix net
                if ($isString && !preg_match($excludeNameRe, $name)
                    && (preg_match($imageDescRe, $desc) || preg_match($imageNameRe, $name))) {
                    $this->assertTrue($hasFormat, "{$where} reads as a media image URL (name \"{$name}\", desc \"{$desc}\") but is missing \"format\": \"image_url\".");
                    $checked++;
                }
            }
        }
        // Guard the guard: if the enumeration ever finds nothing, the test would
        // pass vacuously. We know there are 8 image-URL props today.
        $this->assertGreaterThanOrEqual(8, $checked, 'Image-prop enumeration found too few props — the drift-catcher is not actually running.');
    }

    /**
     * Depth guard (#154). The params walker handles image props at top level or
     * exactly one items[] level. Pin that no schema nests a format:image_url prop
     * deeper, so a future second-level nesting fails here loudly rather than
     * silently bypassing validation (the walker would never reach it).
     */
    public function testImageUrlPropsNestNoDeeperThanOneItemsLevel(): void
    {
        // $itemsLevels = number of items[] array descents to reach $node.
        // A format:image_url prop at 0 (top-level) or 1 (one items[] level) is
        // what the params walker handles; 2+ would be silently missed.
        $findDeep = function ($node, int $itemsLevels, string $path) use (&$findDeep): array {
            $hits = [];
            if (!is_array($node)) {
                return $hits;
            }
            if (_pp_prop_def_is_image_url($node) && $itemsLevels > 1) {
                $hits[] = $path;
            }
            if (isset($node['items']) && is_array($node['items'])) {
                foreach ($node['items'] as $iname => $idef) {
                    $hits = array_merge($hits, $findDeep($idef, $itemsLevels + 1, "{$path}/items/{$iname}"));
                }
            }
            return $hits;
        };

        foreach (pp_get_registered_components() as $component => $schema) {
            if (!isset($schema['props']) || !is_array($schema['props'])) {
                continue;
            }
            foreach ($schema['props'] as $name => $def) {
                $deep = $findDeep($def, 0, "{$component}/{$name}");
                $this->assertSame([], $deep, 'Image prop nested deeper than one items[] level: ' . implode(', ', $deep) . '. Update _pp_collect_urls_from_props() to walk that depth.');
            }
        }
    }

    /**
     * Parity (#154): every image-URL prop across every component — flat and
     * nested — is still extracted after the switch from the hardcoded list.
     */
    public function testExtractUrlsCoversAllExistingImagePropsAcrossComponents(): void
    {
        $params = [
            'composition' => [
                ['component' => 'hero',         'props' => ['image_url' => 'https://x/hero.jpg']],
                ['component' => 'cta',          'props' => ['background_image' => 'https://x/cta.jpg']],
                ['component' => 'stats',        'props' => ['background_image' => 'https://x/stats.jpg']],
                ['component' => 'section',      'props' => ['image_url' => 'https://x/sec.jpg', 'background_image' => 'https://x/sec-bg.jpg']],
                ['component' => 'logos',        'props' => ['items' => [['image_url' => 'https://x/logo.jpg']]]],
                ['component' => 'grid',         'props' => ['items' => [['image_url' => 'https://x/grid.jpg']]]],
                ['component' => 'testimonials', 'props' => ['items' => [['image_url' => 'https://x/avatar.jpg']]]],
            ],
        ];
        $urls = _pp_extract_urls_from_params($params);
        sort($urls);
        $expected = [
            'https://x/avatar.jpg', 'https://x/cta.jpg', 'https://x/grid.jpg',
            'https://x/hero.jpg', 'https://x/logo.jpg', 'https://x/sec-bg.jpg',
            'https://x/sec.jpg', 'https://x/stats.jpg',
        ];
        $this->assertSame($expected, $urls);
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

    // ── Composition normalization: SHAPE ONLY, no name aliases (#604) ────────
    //
    // pp_normalize_composition() no longer renames anything. The `type` -> `component`
    // item-key alias is gone, and the retired `variant` prop and the 13-entry prop-key
    // alias map were never rewritten on this path anyway (#388 / #604). What is left is
    // one shape rule: an empty `style` array is stripped.

    public function testNormalizeCompositionDoesNotRenameTypeToComponent(): void
    {
        // SUPERSEDES testNormalizeCompositionRenamesTypeToComponent (#604). `component`
        // is the only key that names a component. A `type`-keyed item is left exactly as
        // it arrived so the validator can reject it by name, instead of the normalizer
        // absorbing a hallucinated key and denying the author the correction.
        $raw = [
            ['type' => 'hero', 'props' => ['title' => 'Hello']],
            ['type' => 'section', 'props' => ['title' => 'About']],
        ];
        $normalized = pp_normalize_composition($raw);

        $this->assertSame($raw, $normalized, 'a type-keyed item passes through untouched');
        $this->assertArrayNotHasKey('component', $normalized[0], 'no component key is manufactured');
    }

    public function testTypeKeyedItemIsRejectedByName(): void
    {
        // The validator's own rule, pinned as the counterpart the normalizer no longer
        // masks. pp_validate_composition() never ran pp_normalize_composition(), so this
        // rejection predates #604 — what changed is that nothing rewrites the item
        // before it gets here any more, so the rule is now what callers actually meet.
        // The tests that discriminate on the REMOVAL are
        // testNormalizeCompositionDoesNotRenameTypeToComponent and the two
        // create_page/update_composition authoring-path pins below.
        $result = pp_validate_composition([['type' => 'hero', 'props' => ['title' => 'Hello']]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_composition', $result->get_error_code());
        $this->assertStringContainsString('component', $result->get_error_message());
    }

    public function testNormalizeCompositionPreservesCanonicalComponent(): void
    {
        $raw = [
            ['component' => 'hero', 'props' => ['title' => 'Hello']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('hero', $normalized[0]['component']);
    }

    public function testNormalizeCompositionPreservesProps(): void
    {
        $raw = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'layout' => 'split', 'image_url' => 'https://example.com/photo.jpg']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('Welcome', $normalized[0]['props']['title']);
        $this->assertEquals('split', $normalized[0]['props']['layout']);
        $this->assertEquals('https://example.com/photo.jpg', $normalized[0]['props']['image_url']);
    }

    public function testNormalizeCompositionDoesNotMigrateVariant(): void
    {
        // #388, and now unconditionally true (#604): NOTHING migrates `variant`, on any
        // path. The retired key is left in place so pp_validate_composition() rejects it
        // as unknown_prop. Before #604 the read/restore paths still decoded it; that
        // asymmetry — rejected at write, accepted at read — is what the removal ended.
        $raw = [
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'variant' => 'split']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertArrayHasKey('variant', $normalized[0]['props'], 'the retired variant key is left untouched');
        $this->assertSame('split', $normalized[0]['props']['variant']);
        $this->assertArrayNotHasKey('layout', $normalized[0]['props'], 'no layout is synthesized from variant');
    }

    public function testNormalizeCompositionHandlesEmptyArray(): void
    {
        $this->assertEquals([], pp_normalize_composition([]));
    }

    public function testCreatePageRejectsATypeKeyedComposition(): void
    {
        // SUPERSEDES testCreatePageExecutesWithTypeKeyInComposition (#604). The T4
        // failure mode this originally absorbed — an AI sending `type` instead of
        // `component` — is now surfaced to the author rather than silently repaired.
        $result = pp_execute_action('create_page', [
            'title' => 'Portfolio',
            'composition' => [
                ['type' => 'hero', 'props' => ['title' => 'Our Work', 'layout' => 'split']],
            ],
        ]);

        $this->assertFalse($result['ok'], 'a type-keyed item must be rejected, not absorbed');
        $this->assertSame('invalid_composition', $result['error_code']);
        $this->assertStringContainsString('component', $result['error']);
    }

    public function testUpdateCompositionRejectsATypeKeyedComposition(): void
    {
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
            ],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_composition', $result['error_code']);
        // The rejected write stored nothing.
        $this->assertSame('[]', $GLOBALS['_pp_test_store']['post_meta'][70]['_pp_composition']);
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
            'style'           => ['--hero-button2-bg' => 'transparent', '--hero-accent' => 'var(--color-accent)'],
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
            'style'           => ['--hero-button2-bg' => 'var(--nonexistent-token)'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    // ── #514 hero primary-button fill slots (authoring-path mandate) ──────────

    /**
     * Authoring-path acceptance (issue 514): the three new hero primary-button
     * fill slots are accepted through the REAL validate surface, exercising the
     * shared schema-derived validator (a color, a color, and a shadow slot).
     */
    public function testStyleComponentAcceptsHeroButtonFillSlots(): void
    {
        $post_id = pp_create_page('Hero button fill test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-button-bg'     => '#7c3aed',
                '--hero-button-color'  => '#ffffff',
                '--hero-button-shadow' => 'none',
            ],
        ]);
        $this->assertTrue($result);
    }

    /**
     * The `color`-typed --hero-button-bg goes through the same shared validator as
     * every other color slot: a non-color value is rejected with the standard code
     * (authoring-path negative branch, issue 514).
     */
    public function testStyleComponentRejectsInvalidHeroButtonBg(): void
    {
        $post_id = pp_create_page('Hero button fill reject test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-button-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    /**
     * End-to-end authoring: setting the fill slots through the real apply surface
     * persists them onto the component's style map (issue 514), so a site-builder
     * AI can produce a filled brand-accent hero button through composition style
     * slots alone — the capability #514 adds.
     */
    public function testStyleComponentPersistsHeroButtonFillSlots(): void
    {
        $post_id = pp_create_page('Hero button fill persist test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-button-bg'     => '#7c3aed',
                '--hero-button-color'  => '#ffffff',
                '--hero-button-shadow' => 'none',
                '--hero-accent'        => '#7c3aed',
            ],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#7c3aed', $comp[0]['style']['--hero-button-bg']);
        $this->assertSame('#ffffff', $comp[0]['style']['--hero-button-color']);
        $this->assertSame('none', $comp[0]['style']['--hero-button-shadow']);
        $this->assertSame('#7c3aed', $comp[0]['style']['--hero-accent']);
    }

    // ── #584 authoring path: the twelve new slots and the two new item props ──
    //
    // Section 14.1 (authoring-path mandate): a slot or prop that only ever gets exercised by
    // raw _pp_composition meta writes has never met the validator that decides whether an
    // agent can actually set it. Every family this issue completes goes through the REAL
    // surface here — style_component for the slots, update_component for the item props —
    // plus one reject branch each, because "accepted" is only meaningful against a
    // demonstrated rejection.

    public function testStyleComponentPersistsTheHeadingRhythmSlots(): void
    {
        // The six components that could not execute band fusing. Zero is the value the
        // procedure actually asks for, so zero is the value authored here.
        $cases = [
            'hero'   => ['--hero-heading-margin-bottom'   => '0'],
            'cta'    => ['--cta-heading-margin-bottom'    => '0'],
            'stats'  => ['--stats-heading-margin-bottom'  => '0'],
            'table'  => ['--table-heading-margin-bottom'  => '1.5rem'],
            'embed'  => ['--embed-heading-margin-bottom'  => '0'],
            'logos'  => ['--logos-heading-margin-bottom'  => '0'],
        ];
        $props = [
            'hero'  => ['title' => 'Hello'],
            'cta'   => ['title' => 'Go', 'button_text' => 'Start', 'button_url' => '/start'],
            'stats' => ['title' => 'Numbers', 'items' => [['number' => '10', 'label' => 'x']]],
            'table' => ['title' => 'Plans', 'headers' => ['A'], 'rows' => [['1']]],
            'embed' => ['title' => 'Embed', 'content' => '[shortcode]'],
            'logos' => ['title' => 'Clients', 'items' => [['image_url' => 'a.png', 'image_alt' => 'A']]],
        ];

        foreach ($cases as $component => $style) {
            $post_id = pp_create_page("Heading rhythm {$component}");
            pp_update_composition($post_id, [
                ['component' => $component, 'props' => $props[$component]],
            ]);

            $result = pp_execute_action('style_component', [
                'post_id'         => $post_id,
                'component_index' => 0,
                'style'           => $style,
            ]);
            $this->assertTrue($result['ok'], "style_component must accept {$component}'s heading-rhythm slot.");

            $comp = pp_get_composition($post_id);
            foreach ($style as $slot => $value) {
                $this->assertSame($value, $comp[0]['style'][$slot], "{$slot} must persist.");
            }
        }
    }

    public function testStyleComponentRejectsANonLengthHeadingRhythmValue(): void
    {
        $post_id = pp_create_page('Heading rhythm reject');
        pp_update_composition($post_id, [
            ['component' => 'logos', 'props' => [
                'title' => 'Clients',
                'items' => [['image_url' => 'a.png', 'image_alt' => 'A']],
            ]],
        ]);
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--logos-heading-margin-bottom' => 'medium-ish'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testStyleComponentPersistsTheHeroPrimaryRingSlots(): void
    {
        // Rest AND hover together: the positional-twin discipline this issue applies is only
        // real if an author can actually set both through the surface.
        $post_id = pp_create_page('Hero ring slots');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => [
                'title' => 'Hello', 'button_text' => 'Start', 'button_url' => '/start',
            ]],
        ]);
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-button-border'       => '#7c3aed',
                '--hero-button-hover-border' => '#5b21b6',
            ],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('#7c3aed', $comp[0]['style']['--hero-button-border']);
        $this->assertSame('#5b21b6', $comp[0]['style']['--hero-button-hover-border']);
    }

    public function testStyleComponentPersistsThePanelCtaRingSlots(): void
    {
        $post_id = pp_create_page('Panel CTA ring slots');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => [
                'layout'            => 'text-panel',
                'body'              => '<p>Body</p>',
                'panel_heading'     => 'Panel',
                'panel_cta_text'    => 'Book a call',
                'panel_cta_url'     => '/call',
                'panel_cta_variant' => 'primary',
            ]],
        ]);
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--section-panel-cta-bg'           => '#0f766e',
                '--section-panel-cta-border'       => '#134e4a',
                '--section-panel-cta-hover-border' => '#115e59',
            ],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('#134e4a', $comp[0]['style']['--section-panel-cta-border']);
        $this->assertSame('#115e59', $comp[0]['style']['--section-panel-cta-hover-border']);
    }

    public function testStyleComponentStillRejectsAPanelCtaHoverFillSlot(): void
    {
        // #536 shipped the panel CTA resting-state-only for the FILL and #584 did not revisit
        // that: the RING gained a hover twin, the fill did not. An agent that infers
        // --section-panel-cta-hover-bg from the new hover ring must be rejected, not stored.
        $post_id = pp_create_page('Panel CTA hover fill reject');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => [
                'layout'         => 'text-panel',
                'body'           => '<p>Body</p>',
                'panel_cta_text' => 'Book a call',
                'panel_cta_url'  => '/call',
            ]],
        ]);
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-panel-cta-hover-bg' => '#115e59'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
    }

    public function testStyleComponentRejectsTheUnadoptedBandAccentTier(): void
    {
        // The narrowing this issue committed to in writing: the panel CTA reaches its third
        // tier through a ring slot, NOT through a --section-button-accent band-accent tier.
        $post_id = pp_create_page('Band accent tier reject');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['body' => '<p>Body</p>']],
        ]);
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-button-accent' => '#0f766e'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
    }

    public function testStyleComponentPersistsTheLogosSizingSlots(): void
    {
        $post_id = pp_create_page('Logos sizing slots');
        pp_update_composition($post_id, [
            ['component' => 'logos', 'props' => [
                'title' => 'Clients',
                'items' => [
                    ['image_url' => 'a.png', 'image_alt' => 'A'],
                    ['image_url' => 'b.png', 'image_alt' => 'B', 'label' => 'Sector'],
                ],
            ]],
        ]);
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--logos-image-size' => '4rem', '--logos-gap' => '1rem'],
        ]);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('4rem', $comp[0]['style']['--logos-image-size']);
        $this->assertSame('1rem', $comp[0]['style']['--logos-gap']);
    }

    public function testStyleComponentRejectsATokenReferenceOnALengthSizingSlot(): void
    {
        // Both new logos slots are `length`-typed, which is literal-only: var() is rejected in
        // every form. Pinned because "route the token" is the natural first instinct and the
        // failure is otherwise only discovered at write time on a live site.
        $post_id = pp_create_page('Logos sizing reject');
        pp_update_composition($post_id, [
            ['component' => 'logos', 'props' => [
                'items' => [['image_url' => 'a.png', 'image_alt' => 'A']],
            ]],
        ]);
        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--logos-gap' => 'var(--space-md)'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testUpdateComponentAcceptsItemImageIdOnGridAndTestimonials(): void
    {
        // The authoring half of A-42: image_id must survive the real write path on both
        // components, or the responsive branch is unreachable from an agent.
        $post_id = pp_create_page('Item image_id authoring');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => [
                'items' => [['title' => 'Card', 'image_url' => 'https://example.com/c.jpg', 'image_alt' => 'C']],
            ]],
            ['component' => 'testimonials', 'props' => [
                'items' => [['quote' => 'Q', 'author' => 'Jane', 'image_url' => 'https://example.com/j.jpg', 'image_alt' => 'J']],
            ]],
        ]);

        $grid = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [[
                'title' => 'Card', 'image_url' => 'https://example.com/c.jpg',
                'image_alt' => 'C', 'image_id' => 42,
            ]]],
        ]);
        $this->assertTrue($grid['ok'], 'update_component must accept grid.items[].image_id.');

        $tst = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 1,
            'props'           => ['items' => [[
                'quote' => 'Q', 'author' => 'Jane', 'image_url' => 'https://example.com/j.jpg',
                'image_alt' => 'J', 'image_id' => 7,
            ]]],
        ]);
        $this->assertTrue($tst['ok'], 'update_component must accept testimonials.items[].image_id.');

        $comp = pp_get_composition($post_id);
        $this->assertSame(42, $comp[0]['props']['items'][0]['image_id']);
        $this->assertSame(7, $comp[1]['props']['items'][0]['image_id']);
    }

    public function testItemImageIdStaysOptionalUnderNestedRequiredEnforcement(): void
    {
        // #579 enforces `required: true` on items[] fields. image_id is optional, so an item
        // that omits it must still validate — otherwise every already-stored grid and
        // testimonials band on every page would start failing validation, which blocks edits
        // to unrelated bands on the same page.
        $post_id = pp_create_page('Item image_id optional');
        $result  = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [
                ['component' => 'grid', 'props' => [
                    'items' => [['title' => 'No image at all']],
                ]],
                ['component' => 'testimonials', 'props' => [
                    'items' => [['quote' => 'No avatar at all']],
                ]],
            ],
        ]);
        $this->assertTrue($result['ok']);
    }

    // ── #526 hero cta2 fill slot (authoring-path mandate) ────────────────────

    /**
     * Authoring path for the issue 526 combination: a filled second CTA styled with its
     * own --hero-button2-* slots ALONGSIDE the primary's #514 --hero-button-* slots goes
     * through the real validate + apply surface, and both families persist independently.
     * The leak only bites when an author sets --hero-button-* AND makes cta2 a filled
     * `primary`, so the path that PRODUCES that state is exercised here rather than
     * assumed. This pins authoring only — it says nothing about the rendered cascade;
     * the fix itself is proven by the CSS pins in StyleSlotContractTest and the rendered
     * computed-style pins in tests/e2e/style-render.spec.ts.
     */
    public function testStyleComponentPersistsIndependentHeroCta2AndPrimaryFillSlots(): void
    {
        $post_id = pp_create_page('Hero cta2 fill test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => [
                'id'           => 'pp-aabb1122',
                'title'        => 'Hello',
                'button_text'     => 'Get started',
                'button2_text'    => 'Talk to sales',
                'button2_variant' => 'primary',
            ]],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-button-bg'     => '#7c3aed',
                '--hero-button-shadow' => 'none',
                '--hero-button2-bg'       => '#0f766e',
                '--hero-button2-color'    => '#ffffff',
            ],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#7c3aed', $comp[0]['style']['--hero-button-bg']);
        $this->assertSame('none', $comp[0]['style']['--hero-button-shadow']);
        $this->assertSame('#0f766e', $comp[0]['style']['--hero-button2-bg']);
        $this->assertSame('#ffffff', $comp[0]['style']['--hero-button2-color']);
        $this->assertSame('primary', $comp[0]['props']['button2_variant']);
    }

    // ── #530 hover fill slots (authoring-path mandate) ───────────────────────

    /**
     * Authoring path for issue 530: the four filled-button HOVER fill slots go through the
     * real validate + apply surface together. --hero-button-hover-bg is the NEW slot this
     * issue adds, so the authoring contract for it is exercised rather than assumed (raw
     * _pp_composition seeding would bypass the schema allowlist entirely and prove nothing
     * about whether an author can actually set it). The cta2/button2 hover slots already
     * existed but were DEAD on a filled button, so they are pinned here too — the capability
     * #530 delivers is the pair (rest + hover) being settable and independent per button.
     * This pins authoring only; the rendered cascade is proven by the CSS pins in
     * StyleSlotContractTest and the computed-style :hover pins in e2e/style-render.spec.ts.
     */
    public function testStyleComponentPersistsHeroHoverFillSlotsIndependently(): void
    {
        $post_id = pp_create_page('Hero hover fill test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => [
                'id'           => 'pp-aabb1122',
                'title'        => 'Hello',
                'button_text'     => 'Get started',
                'button2_text'    => 'Talk to sales',
                'button2_variant' => 'primary',
            ]],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--hero-button-bg'        => '#7c3aed',
                '--hero-button-hover-bg'  => '#6d28d9',
                '--hero-button2-bg'          => '#0f766e',
                '--hero-button2-hover-bg'    => '#115e59',
            ],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#7c3aed', $comp[0]['style']['--hero-button-bg']);
        $this->assertSame('#6d28d9', $comp[0]['style']['--hero-button-hover-bg']);
        $this->assertSame('#0f766e', $comp[0]['style']['--hero-button2-bg']);
        $this->assertSame('#115e59', $comp[0]['style']['--hero-button2-hover-bg']);
    }

    /** The cta component's two hover fill slots author independently too (issue 530). */
    public function testStyleComponentPersistsCtaHoverFillSlotsIndependently(): void
    {
        $post_id = pp_create_page('CTA hover fill test');
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => [
                'id'              => 'pp-ccdd3344',
                'title'           => 'Ready?',
                'button_text'     => 'Start',
                'button_url'      => '/start',
                'button2_text'    => 'Talk to sales',
                'button2_url'     => '/sales',
                'button2_variant' => 'primary',
            ]],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--cta-button-hover-bg'  => '#6d28d9',
                '--cta-button2-hover-bg' => '#115e59',
            ],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#6d28d9', $comp[0]['style']['--cta-button-hover-bg']);
        $this->assertSame('#115e59', $comp[0]['style']['--cta-button2-hover-bg']);
    }

    /** --hero-button-hover-bg is a color slot on the shared validator: garbage is rejected (issue 530). */
    public function testStyleComponentRejectsInvalidHeroButtonHoverBg(): void
    {
        $post_id = pp_create_page('Hero hover fill reject test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-button-hover-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    /** --hero-button2-bg is a color slot on the shared validator: garbage is rejected (issue 526). */
    public function testStyleComponentRejectsInvalidHeroCta2Bg(): void
    {
        $post_id = pp_create_page('Hero cta2 fill reject test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-button2-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    // ── #536 section panel-CTA fill slots (authoring-path mandate) ────────────

    /**
     * Authoring path for issue 536: the three new panel-CTA slots go through the REAL
     * validate + apply surface on a text-panel section that actually renders a CTA, and
     * persist onto that component's style map. Raw _pp_composition seeding would bypass
     * the schema-derived allowlist entirely and prove nothing about whether a site-builder
     * AI can set them (Section 14.1). This pins authoring only; the cascade that makes the
     * fill VISIBLE is pinned by StyleSlotContractTest and the rendered computed-style pins
     * in tests/e2e/style-render.spec.ts.
     */
    public function testStyleComponentPersistsSectionPanelCtaFillSlots(): void
    {
        $post_id = pp_create_page('Section panel CTA fill test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => [
                'id'             => 'pp-eeff5566',
                'layout'         => 'text-panel',
                'title'          => 'Plans',
                'body'           => 'Pick a plan.',
                'panel_heading'  => 'Starter',
                'panel_cta_text' => 'Book a call',
                'panel_cta_url'  => '/contact',
            ]],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => [
                '--section-panel-cta-bg'     => '#7c3aed',
                '--section-panel-cta-color'  => '#ffffff',
                '--section-panel-cta-shadow' => 'none',
            ],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#7c3aed', $comp[0]['style']['--section-panel-cta-bg']);
        $this->assertSame('#ffffff', $comp[0]['style']['--section-panel-cta-color']);
        $this->assertSame('none', $comp[0]['style']['--section-panel-cta-shadow']);
    }

    /**
     * #551 authoring-path proof. The panel CTA variants whose ink the band rules broke
     * (outline / ghost / secondary — every TRANSPARENT-or-light variant) must be reachable
     * through the REAL write surface, not just constructible as a render fixture. Raw
     * _pp_composition seeding would bypass the schema-derived enum entirely and prove
     * nothing about whether a site-builder AI can actually author the affected state.
     *
     * The filled variant is the control: it is the one the carve-out does NOT touch,
     * because its ink comes from the premium chain via --section-panel-cta-color.
     */
    public function testCreatePageAcceptsEveryPanelCtaVariantWithTheInkSlot(): void
    {
        // The defect needs BOTH halves: a transparent/light variant AND a dark band. The
        // `theme` half is authored here so the actual broken state — not just the variant
        // in isolation — is proven reachable through the real write surface.
        foreach (['primary', 'secondary', 'outline', 'ghost'] as $variant) {
            foreach ([null, 'inverted'] as $theme) {
                $label  = $theme ? "$variant/$theme" : "$variant/default";
                $result = pp_execute_action('create_page', [
                    'title'       => "Panel CTA variant $label",
                    'composition' => [[
                        'component' => 'section',
                        'props'     => array_merge([
                            'layout'            => 'text-panel',
                            'title'             => 'Plans',
                            'body'              => 'Pick a plan.',
                            'panel_heading'     => 'Starter',
                            'panel_cta_text'    => 'Book a call',
                            'panel_cta_url'     => '/contact',
                            'panel_cta_variant' => $variant,
                        ], $theme ? ['theme' => $theme] : []),
                        // The #536 per-instance ink slot must keep authoring cleanly
                        // alongside every variant — the carve-out must not disturb the
                        // slot contract on the one variant the slot actually reaches.
                        'style'     => ['--section-panel-cta-color' => '#0b7a3b'],
                    ]],
                ]);

                $this->assertTrue(
                    $result['ok'],
                    "panel_cta_variant=$label must be accepted through create_page: "
                    . ($result['error'] ?? '')
                );

                $comp = pp_get_composition((int) $result['target']['post_id']);
                $this->assertSame($variant, $comp[0]['props']['panel_cta_variant']);
                $this->assertSame('#0b7a3b', $comp[0]['style']['--section-panel-cta-color']);
                if ($theme) {
                    $this->assertSame($theme, $comp[0]['props']['theme']);
                }
            }
        }
    }

    /**
     * The `color`-typed --section-panel-cta-bg goes through the same shared validator as
     * every other color slot: a non-color value is rejected with the standard code
     * (authoring-path negative branch, issue 536).
     */
    public function testStyleComponentRejectsInvalidSectionPanelCtaBg(): void
    {
        $post_id = pp_create_page('Section panel CTA reject test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => [
                'id'     => 'pp-eeff5566',
                'layout' => 'text-panel',
                'title'  => 'Plans',
                'body'   => 'Pick a plan.',
            ]],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-panel-cta-bg' => 'not-a-color'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    /**
     * The `shadow`-typed --section-panel-cta-shadow is validated as a shadow, not a color:
     * a color literal there is rejected, so the three slots are not silently interchangeable
     * (issue 536).
     */
    public function testStyleComponentRejectsInvalidSectionPanelCtaShadow(): void
    {
        $post_id = pp_create_page('Section panel CTA shadow reject test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => [
                'id'     => 'pp-eeff5566',
                'layout' => 'text-panel',
                'title'  => 'Plans',
                'body'   => 'Pick a plan.',
            ]],
        ]);

        $result = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-panel-cta-shadow' => 'javascript:alert(1)'],
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
            'style'           => ['--hero-bg' => '#0d1117', '--hero-heading-color' => '#f0f0f0'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('#0d1117', $comp[0]['style']['--hero-bg']);
        $this->assertSame('#f0f0f0', $comp[0]['style']['--hero-heading-color']);
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

        // The --grid-heading-measure slot should be accepted.
        $result = pp_execute_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-heading-measure' => '60rem'],
        ]);
        $this->assertTrue($result['ok']);

        $comp = pp_get_composition($post_id);
        $this->assertSame('60rem', $comp[0]['style']['--grid-heading-measure']);
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
        $this->assertSame('#f0f0f0', $style['--hero-heading-color']);
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
        $this->assertSame('#f0f0f0', $style['--hero-heading-color']); // from recipe
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

    // ── Misspelled Style Slots Are Rejected, Never Repaired (#607) ────────
    //
    // The chat preview path used to intercept invalid_style_slot and substitute
    // the nearest declared slot by Levenshtein distance (40% of the rejected
    // name's length), returning a preview of a DIFFERENT slot flagged only
    // `repaired: true`. #607 removed it: a slot the component doesn't declare is
    // invalid_style_slot, the same verdict every other surface reports.
    //
    //   style_component proposal
    //     └─ pp_preview_action -> pp_validate_action
    //          ├─ declared slot   -> preview array (NO 'repaired' key)
    //          └─ undeclared slot -> WP_Error invalid_style_slot
    //                                  └─ _pp_build_friendly_error
    //                                       ├─ raw_error     names the REJECTED slot
    //                                       └─ alternatives  names the DECLARED slots

    public function testUndeclaredStyleSlotIsRejectedNamingTheSlotTheAuthorWrote(): void
    {
        $post_id = pp_create_page('Reject test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        // --hero-bgs is one edit from --hero-bg: exactly the case the removed
        // repair used to substitute silently. It is now simply invalid.
        $result = pp_preview_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertInstanceOf(WP_Error::class, $result, 'A misspelled slot must not preview.');
        $this->assertSame('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString(
            '--hero-bgs',
            $result->get_error_message(),
            'The validator names the slot the author actually wrote.'
        );
    }

    public function testFriendlyErrorForMisspelledSlotNamesRejectedSlotAndKeepsAlternatives(): void
    {
        // The chat preview handler's whole style_component error branch after
        // #607: the validator's verdict, structured for the UI. raw_error is the
        // teaching surface (it carries the rejected name); alternatives is
        // unchanged by the removal.
        $post_id = pp_create_page('Reject test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ];
        $error  = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);

        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertSame('invalid_style_slot', $friendly['error_code']);
        $this->assertStringContainsString(
            '--hero-bgs',
            $friendly['raw_error'],
            'The rejected slot name must reach the author verbatim.'
        );
        $this->assertSame(
            array_keys(pp_get_style_slots('hero')),
            $friendly['alternatives'],
            'The alternatives list is the declared slot set, unchanged by #607.'
        );
        $this->assertContains('--hero-bg', $friendly['alternatives']);
    }

    public function testDeclaredStyleSlotStillPreviews(): void
    {
        // AC#4: the happy path is untouched by the removal. The no-'repaired'
        // assertion here documents the preview array's shape; it is NOT the
        // regression guard for #607, because pp_preview_action never set that key
        // on any path — only the deleted AJAX retry branch did. The guard for the
        // removal itself lives in
        // testPreviewStyleComponentBranchOnlyReportsTheValidatorVerdict.
        $post_id = pp_create_page('Happy path test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $preview = pp_preview_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bg' => '#1a1a2e'],
        ]);

        $this->assertNotInstanceOf(WP_Error::class, $preview, 'A declared slot must still preview.');
        $this->assertIsArray($preview);
        $this->assertArrayNotHasKey('repaired', $preview);
    }

    public function testPreviewStyleComponentBranchOnlyReportsTheValidatorVerdict(): void
    {
        // The preview AJAX handler is a closure registered through add_action,
        // which is a no-op in this bootstrap, so its body is unreachable from
        // PHPUnit. Pin it at the source level instead (same technique as the
        // lib/cli.php gate invariant, tests/CliGateTest.php).
        //
        // Assert on the style_component BRANCH, not on the whole file, and assert
        // the POSITIVE shape as well as the negative one. A tripwire that only
        // greps for the old helper's name is defeated by a repair hop reintroduced
        // under any other name (similar_text/soundex/inline), or by a flag written
        // with double quotes. What must stay true is structural: this branch
        // reports an error and never returns a preview.
        $src = file_get_contents(dirname(__DIR__) . '/lib/ai-chat.php');
        $this->assertNotFalse($src);

        $start = strpos($src, "if (\$name === 'style_component') {");
        $this->assertNotFalse($start, 'The style_component error branch must exist.');
        $end = strpos($src, "\n        }\n", $start);
        $this->assertNotFalse($end, 'The style_component error branch must be closed.');
        $branch = substr($src, $start, $end - $start);

        // Positive: the branch's whole job is to report the validator's verdict.
        $this->assertStringContainsString('_pp_build_friendly_error(', $branch);
        $this->assertStringContainsString('wp_send_json_error(', $branch);

        // Negative, name- and quote-agnostic: no repair-and-retry hop may re-run the
        // preview and hand back a slot the author never asked for (#607).
        $this->assertStringNotContainsString('wp_send_json_success', $branch);
        $this->assertStringNotContainsString('pp_preview_action', $branch);
        $this->assertStringNotContainsString('pp_preview_apply', $branch);
        $this->assertDoesNotMatchRegularExpression('/repaired/i', $branch);

        // The helper itself is gone, and nothing in lib/ reintroduced the heuristic.
        $this->assertFalse(
            function_exists('_pp_attempt_style_repair'),
            'The Levenshtein slot repair was removed in #607.'
        );
        foreach (glob(dirname(__DIR__) . '/lib/*.php') as $lib_file) {
            $lib_src = file_get_contents($lib_file);
            $this->assertStringNotContainsString('_pp_attempt_style_repair', $lib_src, basename($lib_file));
            $this->assertDoesNotMatchRegularExpression('/levenshtein\s*\(/i', $lib_src, basename($lib_file));
        }
    }

    public function testResolveComponentIndexForErrorPrefersComponentIdOverStaleIndex(): void
    {
        // The precedence contract itself, pinned directly on the helper that
        // owns it rather than only through a caller.
        $post_id = pp_create_page('Direct precedence test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $this->assertSame(1, _pp_resolve_component_index_for_error([
            'post_id'         => $post_id,
            'component_id'    => 'pp-a1b2c3d4',
            'component_index' => 0,
        ]));
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

    public function testFriendlyErrorPrefersComponentIdOverStaleComponentIndex(): void
    {
        // Proves precedence, not just presence (the sibling test above covers
        // presence). #607 re-pinned this case here: it used to live only in the
        // deleted style-repair block, so removing that block would otherwise have
        // dropped the #123 guarantee that a stale/mismatched component_index —
        // echoed back from a prior turn — never wins over an explicit component_id.
        $post_id = pp_create_page('Precedence test');
        pp_update_composition($post_id, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['id' => 'pp-a1b2c3d4', 'title' => 'Hi']],
        ]);

        $error  = new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgs". Available: --hero-bg, ...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_id'    => 'pp-a1b2c3d4',
            'component_index' => 0, // stale: points at nav, id points at hero
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertContains(
            '--hero-bg',
            $result['alternatives'],
            'component_id must win over a conflicting component_index.'
        );
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

    public function testUserMessageWithNoHintNamesSlotsAndCounts(): void
    {
        $post_id = pp_create_page('Cross-comp desc test');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        // No cross-hint, and no stamped context either — the fallback path, answering
        // from the composition as it reads now. Until #661 this listed the DESCRIPTION
        // of all 47 declared slots; it now names a bounded sample of the slot NAMES
        // plus the total, which is what a mistyped name actually needs.
        $error  = new WP_Error('invalid_style_slot', 'Invalid slot');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-zindex' => '10'],
        ]);

        // No stamped context, so the rejection is second-hand and the message must NOT
        // quote a single rejected name — the fallback set is not recipe-expanded, so it
        // cannot know the name it would be quoting is the one that was refused.
        $this->assertStringNotContainsString('I tried to set "', $result['user_message']);
        $this->assertStringContainsString('section', $result['user_message']);

        // Computed, not hard-coded: a future section slot would otherwise break this
        // test for a reason unrelated to what it pins.
        $declared = pp_get_style_slots('section');
        $this->assertStringContainsString('It has ' . count($declared) . ' style settings', $result['user_message']);
        $this->assertGreaterThan(PP_FRIENDLY_SLOT_SAMPLE_MAX, count($declared), 'Premise: section declares more than the message samples.');
        $this->assertStringContainsString('--section-bg', $result['user_message']);

        // Bounded: the descriptions this used to concatenate are not in it.
        $descriptions = array_column($declared, 'description');
        // An empty description would make assertStringNotContainsString('', ...) fail
        // with no hint that the FIXTURE is what changed, so state the premise first.
        $this->assertNotContains('', $descriptions, 'Premise: every declared slot carries a description.');
        foreach ($descriptions as $description) {
            $this->assertStringNotContainsString($description, $result['user_message']);
        }
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

    // ── Response Bounds ─────────────────────────────────────────────────
    //
    // The bounds on a chat-side error response exist to keep a pathological input
    // from producing a pathological response. The load-bearing property is the
    // other direction: an ORDINARY rejection must come back exactly as it did
    // before. Most of the tests below pin that, against the shipped registry and
    // the shipped starter composition rather than hand-picked values, because a
    // bound tuned to a fixture is a bound that fires in production.

    public function testEveryComponentsValidatorMessageReachesTheAuthorIntact(): void
    {
        // raw_error carries the "Available: <every declared slot>" list, which is
        // the part of the message an author reads to find the name they meant. It
        // runs to 1290 characters on the widest component. Nothing may shorten it.
        $trimmed = [];

        foreach (array_keys(pp_get_registered_components()) as $component) {
            $slots = pp_get_style_slots($component);
            if ($slots === []) {
                continue;
            }
            $message = sprintf(
                'Component "%s" has no style slot "%s". Available: %s',
                $component,
                '--typo-slot',
                implode(', ', array_keys($slots))
            );

            $friendly = _pp_build_friendly_error(
                new WP_Error('invalid_style_slot', $message),
                []
            );

            if ($friendly['raw_error'] !== $message) {
                $trimmed[] = $component;
            }
        }

        $this->assertSame([], $trimmed, 'No component\'s real rejection message may be shortened.');
    }

    public function testOrdinaryRejectionRoundTripsUnchanged(): void
    {
        $post_id = pp_create_page('Bounds baseline');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ];
        $error    = pp_preview_action('style_component', $params);
        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertSame(
            $error->get_error_message(),
            $friendly['raw_error'],
            'A single mistyped slot is the common case and must pass through untouched.'
        );
        $this->assertStringContainsString('--hero-bgs', $friendly['raw_error']);
        $this->assertArrayNotHasKey(
            'unknown_slots_unscanned',
            $friendly,
            'The response shape on an ordinary rejection is unchanged.'
        );
    }

    public function testWidestShippedStyleMapIsReportedComplete(): void
    {
        // The widest style map the theme itself ships, aimed at the wrong component.
        // This is the ordinary mistake cross-component hints exist to explain, so it
        // is precisely the case that must never come back partial.
        $widest = [];
        foreach (pp_default_homepage_composition() as $band) {
            $style = $band['style'] ?? [];
            if (count($style) > count($widest)) {
                $widest = $style;
            }
        }
        $this->assertGreaterThanOrEqual(20, count($widest), 'The starter still carries a wide style map.');

        $post_id = pp_create_page('Widest shipped map');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "section" has no style slot.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => $widest]
        );

        $this->assertArrayNotHasKey('unknown_slots_unscanned', $result);
    }

    public function testWidestComponentsFullSlotSetIsReportedComplete(): void
    {
        // The largest legitimate case there is: every slot the widest component
        // declares, aimed at a different component.
        $widest_name  = '';
        $widest_slots = [];
        foreach (array_keys(pp_get_registered_components()) as $component) {
            $slots = pp_get_style_slots($component);
            if (count($slots) > count($widest_slots)) {
                $widest_slots = $slots;
                $widest_name  = $component;
            }
        }
        $this->assertNotSame('section', $widest_name, 'The target below must differ from the source.');

        $style = [];
        foreach (array_keys($widest_slots) as $slot) {
            $style[$slot] = '1rem';
        }

        $post_id = pp_create_page('Widest component map');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "section" has no style slot.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => $style]
        );

        $this->assertArrayNotHasKey(
            'unknown_slots_unscanned',
            $result,
            sprintf('%s declares %d slots, which must sit inside the bound.', $widest_name, count($widest_slots))
        );
    }

    public function testUnknownSlotKeysBeyondTheBoundAreCounted(): void
    {
        $post_id = pp_create_page('Bound applied');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        // Names no component declares, so the count is exact rather than dependent
        // on which of them happen to match somewhere.
        $style = [];
        for ($i = 0; $i < PP_CROSS_COMPONENT_HINT_MAX + 6; $i++) {
            $style['--zz-unknown-' . $i] = '1rem';
        }

        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "section" has no style slot.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => $style]
        );

        $this->assertSame(6, $result['unknown_slots_unscanned']);
        $this->assertLessThanOrEqual(
            PP_CROSS_COMPONENT_HINT_MAX,
            count((array) $result['cross_component_hints'])
        );
    }

    public function testKeysBeyondTheBoundAreNotExaminedForHints(): void
    {
        // The cost of bounding the scan, pinned so it is a recorded trade-off rather
        // than a surprise: the cap cuts the key list before any matching runs, so a
        // key that WOULD have produced a hint is dropped when it sits past the bound.
        // Deciding otherwise would mean scanning every key to find out, which is the
        // work the cap exists to avoid.
        $post_id = pp_create_page('Beyond the bound');
        pp_update_composition($post_id, [
            ['component' => 'section', 'props' => ['title' => 'Hi']],
        ]);

        $style = [];
        for ($i = 0; $i < PP_CROSS_COMPONENT_HINT_MAX; $i++) {
            $style['--zz-unknown-' . $i] = '1rem';
        }
        // Real slots on another component, positioned past the bound.
        $tail = array_slice(array_keys(pp_get_style_slots('hero')), 0, 5);
        foreach ($tail as $slot) {
            $style[$slot] = '1rem';
        }

        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "section" has no style slot.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => $style]
        );

        $this->assertSame(count($tail), $result['unknown_slots_unscanned']);
        $this->assertEmpty(
            (array) $result['cross_component_hints'],
            'Keys past the bound are not examined, so their hints are not found.'
        );
    }

    public function testNameLongerThanTheErrorBudgetDegradesWithoutReflectingIt(): void
    {
        // The two bounds interact: raw_error is cut to its own budget first, which
        // takes the closing quote off a name longer than that budget, so the slot
        // name can no longer be extracted for user_message. The message degrades to
        // its generic form. That is the safe direction — the alternative is echoing
        // a multi-kilobyte name back — so pin it rather than leave it incidental.
        $long   = '--hero-' . str_repeat('x', PP_REFLECTED_ERROR_MAX);
        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_value', sprintf('Style slot "%s": not a length.', $long)),
            []
        );

        $this->assertStringNotContainsString(str_repeat('x', 300), $result['user_message']);
        $this->assertStringContainsString('the style slot', $result['user_message']);
        $this->assertLessThanOrEqual(PP_REFLECTED_ERROR_MAX, mb_strlen($result['raw_error']));
    }

    public function testReflectedTextDropsTheWiderInvisibleCharacterSet(): void
    {
        // The categories, not a hand-listed subset: U+061C sits with the other
        // bidirectional controls, U+00AD and the U+E0000 tag block are invisible the
        // same way the zero-width set is, and an enumeration reaches none of them.
        foreach (["\u{061C}", "\u{00AD}", "\u{180E}", "\u{206A}", "\u{FEFF}", "\u{E0041}"] as $ch) {
            $this->assertSame(
                '--hero-bg',
                _pp_clean_reflected_text('--hero-' . $ch . 'bg', PP_REFLECTED_NAME_MAX),
                sprintf('U+%04X must be dropped.', mb_ord($ch))
            );
        }
    }

    public function testReflectedNameLengthIsBounded(): void
    {
        // user_message quotes back the name the validator rejected, which the
        // validator took from the caller. This is the surface where the name budget
        // does real work: the value bound (below) leaves room for a name this long,
        // and nothing else shortens it.
        $post_id = pp_create_page('Long name');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $long   = '--hero-' . str_repeat('x', 2000);
        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_value', sprintf('Style slot "%s": not a length.', $long)),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => [$long => '2rem']]
        );

        $this->assertStringNotContainsString(str_repeat('x', 300), $result['user_message']);
        $this->assertLessThan(600, mb_strlen($result['user_message']));
    }

    public function testDeclaredSlotNameSurvivesInTheMessage(): void
    {
        // The other half of the bound above: a real name is quoted back in full.
        $post_id = pp_create_page('Real name');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_value', 'Style slot "--hero-bg": not a colour.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--hero-bg' => 'nope']]
        );

        $this->assertStringContainsString('"--hero-bg"', $result['user_message']);
    }

    public function testReflectedErrorLengthIsBounded(): void
    {
        $result = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', str_repeat('a', 20000)),
            []
        );

        $this->assertLessThanOrEqual(PP_REFLECTED_ERROR_MAX, mb_strlen($result['raw_error']));
        $this->assertStringEndsWith('...', $result['raw_error']);
    }

    public function testReflectedTextDropsCharactersThatCarryNoMeaning(): void
    {
        // Control, zero-width and bidirectional-formatting characters. None of them
        // belong in a slot name, and all of them survive into whatever renders the
        // response, where they are invisible.
        $this->assertSame(
            '--hero-bg',
            _pp_clean_reflected_text("--hero\x00-\x1Fbg", PP_REFLECTED_NAME_MAX),
            'Control characters are dropped.'
        );
        $this->assertSame(
            '--hero-bg',
            _pp_clean_reflected_text("--hero\u{200B}-\u{FEFF}bg", PP_REFLECTED_NAME_MAX),
            'Zero-width characters are dropped.'
        );
        $this->assertSame(
            '--hero-bg',
            _pp_clean_reflected_text("--hero\u{202E}-\u{2069}bg", PP_REFLECTED_NAME_MAX),
            'Bidirectional-formatting characters are dropped.'
        );
        $this->assertSame(
            'Component "section" has no style slot "--x".',
            _pp_clean_reflected_text('Component "section" has no style slot "--x".', PP_REFLECTED_ERROR_MAX),
            'Ordinary text is returned byte-identical.'
        );
    }

    public function testReflectedTextSurvivesUndecodableInput(): void
    {
        // Returning null here would blank the field, which reads to the author as
        // "no detail was reported" rather than "the detail was unprintable".
        $result = _pp_clean_reflected_text("bad\xC3\x28name\x07", PP_REFLECTED_NAME_MAX);

        $this->assertIsString($result);
        $this->assertNotSame('', $result);
        $this->assertStringNotContainsString("\x07", $result);
    }

    public function testReflectedTextCountsCharactersNotBytes(): void
    {
        // A budget measured in bytes would cut a multi-byte name at a fraction of
        // the stated length and could split a character in half.
        $name   = str_repeat('é', 400);
        $result = _pp_clean_reflected_text($name, PP_REFLECTED_NAME_MAX);

        $this->assertSame(PP_REFLECTED_NAME_MAX, mb_strlen($result));
        $this->assertSame($result, mb_convert_encoding($result, 'UTF-8', 'UTF-8'));
    }

    // ── CSS Keyword Rejection + Alternative Suggestions ─────────────────

    public function testFriendlyErrorForCssKeywordNoneOnMaxWidthSlot(): void
    {
        $post_id = pp_create_page('CSS keyword test');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['title' => 'Grid']],
        ]);

        // Simulate validator rejecting "none" for a length slot.
        $error  = new WP_Error('invalid_style_value', 'Style slot "--grid-heading-measure": Value must be a number with a CSS unit...');
        $result = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--grid-heading-measure' => 'none'],
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
        $suggestion = _pp_suggest_alternative_value('length', 'Top padding of the band. Defaults to the shared symmetric band rhythm (fluid ~68-80px desktop, ~54px mobile); set an explicit length to override.', 'var(--pp-band-padding)');
        $this->assertStringContainsString('"0"', $suggestion);
    }

    public function testSuggestAlternativeForRadiusLength(): void
    {
        $suggestion = _pp_suggest_alternative_value('length', 'Card border radius', 'var(--radius)');
        $this->assertStringContainsString('"0"', $suggestion);
    }

    public function testSuggestAlternativeForLengthOrNone(): void
    {
        // #579 — the band-geometry width cap can express "remove the cap" directly,
        // so the chat suggestion must name the keyword and NOT the pre-#579 `100%`
        // workaround, which existed only because the type could not say `none`.
        $suggestion = _pp_suggest_alternative_value(
            'length-or-none',
            'Maximum width of the stats band.',
            'none'
        );
        $this->assertStringContainsString('"none"', $suggestion);
        $this->assertStringNotContainsString('100%" to use all available', $suggestion);
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

    // ── issue 291: snapshot captures PRESENCE separately from VALUE ─────────────
    // Pre-#291 the batch snapshot stored only the value, so an ABSENT option (no DB
    // row) and one holding an explicit '' both collapsed to the same captured '' and
    // a rollback could not tell "delete the row" from "restore ''". The per-key
    // ['exists' => bool, 'value' => string] shape keeps them distinct.

    public function testSnapshotCapturesAbsentWhitelistedOptionAsNotExists(): void
    {
        // pp_og_image (attachment_id) has no row before the run.
        $this->assertArrayNotHasKey('pp_og_image', $GLOBALS['_pp_test_store']['options']);

        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_og_image']],
        ]);

        $this->assertSame(
            ['exists' => false, 'value' => ''],
            $snapshot['site_options']['pp_og_image'],
            'an absent whitelisted option must capture exists=false'
        );
    }

    public function testSnapshotCapturesExplicitEmptyWhitelistedOptionAsExists(): void
    {
        // pp_og_site_name (string) has a row holding an explicit '' before the run.
        $GLOBALS['_pp_test_store']['options']['pp_og_site_name'] = '';

        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_og_site_name']],
        ]);

        $this->assertSame(
            ['exists' => true, 'value' => ''],
            $snapshot['site_options']['pp_og_site_name'],
            'an explicit empty-string row must capture exists=true so it is not deleted on rollback'
        );
    }

    public function testSnapshotCapturesWhitelistedValue(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_twitter_card'] = 'summary';

        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_twitter_card']],
        ]);

        $this->assertSame(
            ['exists' => true, 'value' => 'summary'],
            $snapshot['site_options']['pp_twitter_card']
        );
    }

    public function testSnapshotDoesNotReadNonWhitelistedOptionValue(): void
    {
        // active_plugins is a real WP option stored as an ARRAY. The snapshotter
        // records every update_site_option step's key up front (before execute rejects
        // an unauthorized one), but capture stays whitelist-scoped: a non-whitelisted
        // key is recorded absent-shaped WITHOUT reading (and (string)-casting) its
        // value. This preserves the read boundary and avoids an "Array to string
        // conversion" on a non-scalar core option.
        $GLOBALS['_pp_test_store']['options']['active_plugins'] = ['akismet/akismet.php'];

        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'active_plugins']],
        ]);

        $this->assertSame(
            ['exists' => false, 'value' => ''],
            $snapshot['site_options']['active_plugins'],
            'a non-whitelisted key must be recorded absent-shaped, never read'
        );
        // The core option itself is untouched by capture.
        $this->assertSame(['akismet/akismet.php'], $GLOBALS['_pp_test_store']['options']['active_plugins']);
    }

    public function testRestoreDeletesAbsentBaselineDistinctFromExplicitEmpty(): void
    {
        // An ABSENT baseline (exists=false) restores by DELETING the row, even though
        // the value field is '' — the presence bit, not the value, decides.
        $GLOBALS['_pp_test_store']['options']['pp_twitter_card'] = 'summary_large_image'; // stray applied value

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_twitter_card' => ['exists' => false, 'value' => '']],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey(
            'pp_twitter_card',
            $GLOBALS['_pp_test_store']['options'],
            'an absent baseline must be restored by deleting the row'
        );
    }

    public function testRestoreWritesExplicitEmptyBaselineDistinctFromAbsent(): void
    {
        // An EXPLICIT '' baseline (exists=true, value='') restores by WRITING '' — the
        // row must survive as an empty string, NOT be deleted. This is the exact case
        // #291 exists to separate from the absent case above.
        $GLOBALS['_pp_test_store']['options']['pp_og_site_name'] = 'Applied Co'; // stray applied value

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_og_site_name' => ['exists' => true, 'value' => '']],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        $this->assertArrayHasKey(
            'pp_og_site_name',
            $GLOBALS['_pp_test_store']['options'],
            'an explicit empty baseline must keep the row (write \'\'), not delete it'
        );
        $this->assertSame('', $GLOBALS['_pp_test_store']['options']['pp_og_site_name']);
    }

    public function testRestoreWritesValueBaselineNewShape(): void
    {
        // A present, non-empty baseline (attachment-ID option) restores verbatim.
        $GLOBALS['_pp_test_store']['options']['pp_og_image'] = '99'; // stray applied value

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_og_image' => ['exists' => true, 'value' => '77']],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        $this->assertSame('77', $GLOBALS['_pp_test_store']['options']['pp_og_image']);
    }

    public function testRestoreLeavesNonWhitelistedNewShapeUntouched(): void
    {
        // The whitelist guard runs BEFORE shape normalization, so even a new-shape
        // baseline for a non-whitelisted key never mutates an unrelated core option.
        $GLOBALS['_pp_test_store']['options']['active_plugins'] = 'a:1:{i:0;s:5:"x/x.php";}';

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['active_plugins' => ['exists' => false, 'value' => '']],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        $this->assertSame('a:1:{i:0;s:5:"x/x.php";}', $GLOBALS['_pp_test_store']['options']['active_plugins']);
    }

    public function testRestoreDegradesLegacyValueOnlyShape(): void
    {
        // Defensive back-compat: a pre-#291 value-only string baseline degrades to the
        // #281 rule (empty => delete, non-empty => write raw). The snapshot bundle is
        // request-scoped and never persisted, so this cannot occur in practice, but the
        // restore path must not error on the old shape.
        $GLOBALS['_pp_test_store']['options']['pp_twitter_card']  = 'summary';       // will be deleted (legacy '')
        $GLOBALS['_pp_test_store']['options']['pp_og_default_description'] = 'stray'; // will be overwritten

        $snapshot = [
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => [
                'pp_twitter_card'           => '',              // legacy empty => delete
                'pp_og_default_description' => 'Prior summary', // legacy value  => write raw
            ],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('pp_twitter_card', $GLOBALS['_pp_test_store']['options']);
        $this->assertSame('Prior summary', $GLOBALS['_pp_test_store']['options']['pp_og_default_description']);
    }

    public function testBatchRestoresAbsentSocialOptionOnLaterFailure(): void
    {
        // End-to-end (#382 + #291): pp_footer_social (the structured 'social' list
        // option) is absent before the run. On rollback the absent baseline deletes the
        // row — proving the new option types added since #281 flow through the generic
        // presence-aware snapshot/restore automatically.
        $this->assertArrayNotHasKey('pp_footer_social', $GLOBALS['_pp_test_store']['options']);

        $social = json_encode([['network' => 'github', 'url' => 'https://github.com/acme']]);
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_site_option', 'params' => ['key' => 'pp_footer_social', 'value' => $social]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertArrayNotHasKey(
            'pp_footer_social',
            $GLOBALS['_pp_test_store']['options'],
            'an absent social-option baseline must be restored by deleting the row'
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

    // ── set_menu dropdown children (#381) ──────────────────────────────────

    public function testSetMenuCreatesDropdownChildrenWithParentIds(): void
    {
        $services = pp_create_page('Servicios', 'publish');

        $result = pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/', 'label' => 'Inicio'],
                ['page_id' => $services, 'children' => [
                    ['url' => 'https://example.com/cloud', 'label' => 'Cloud'],
                    ['url' => 'https://example.com/hosting', 'label' => 'Hosting'],
                ]],
            ],
        ]);

        $this->assertTrue($result['ok']);
        $menu_id = $result['target']['menu_id'];
        $items   = array_values($GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id]);

        // Flat store holds parent then its children, in author order.
        $this->assertCount(4, $items);
        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item->title] = $item;
        }
        // Top-level items stay top-level.
        $this->assertSame(0, $byTitle['Inicio']->menu_item_parent);
        $this->assertSame(0, $byTitle['Servicios']->menu_item_parent);
        // Children point at their parent's freshly-minted item id.
        $this->assertSame($byTitle['Servicios']->ID, $byTitle['Cloud']->menu_item_parent);
        $this->assertSame($byTitle['Servicios']->ID, $byTitle['Hosting']->menu_item_parent);
        // Author order is preserved: Servicios, then Cloud, then Hosting.
        $order = array_map(fn($i) => $i->title, $items);
        $this->assertSame(['Inicio', 'Servicios', 'Cloud', 'Hosting'], $order);
    }

    public function testSetMenuRejectsChildrenNotArray(): void
    {
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Parent', 'children' => 'nope'],
            ],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_children', $result->get_error_code());
    }

    public function testSetMenuRejectsChildWithItsOwnChildren(): void
    {
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Parent', 'children' => [
                    ['url' => 'https://example.com/b', 'label' => 'Child', 'children' => [
                        ['url' => 'https://example.com/c', 'label' => 'Grandchild'],
                    ]],
                ]],
            ],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('nesting_too_deep', $result->get_error_code());
    }

    public function testSetMenuRejectsMalformedChildMissingLink(): void
    {
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Parent', 'children' => [[]]],
            ],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_item_link', $result->get_error_code());
        // Error path is child-scoped so the operator can locate it.
        $this->assertStringContainsString('children[0]', $result->get_error_message());
    }

    public function testSetMenuRejectsChildCustomLinkMissingLabel(): void
    {
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Parent', 'children' => [
                    ['url' => 'https://example.com/b'],
                ]],
            ],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_item_label', $result->get_error_code());
    }

    public function testSetMenuRejectsTooManyChildren(): void
    {
        $children = [];
        for ($i = 0; $i <= PP_MENU_MAX_CHILDREN; $i++) {
            $children[] = ['url' => 'https://example.com/c' . $i, 'label' => 'Child ' . $i];
        }
        $result = pp_validate_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/a', 'label' => 'Parent', 'children' => $children],
            ],
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('too_many_children', $result->get_error_code());
    }

    public function testSetMenuChildFailureMidLoopRestoresPreviousItems(): void
    {
        pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [['url' => 'https://example.com/old', 'label' => 'Original']],
        ]);

        // A CHILD (not a top-level item) fails mid-loop after the menu was
        // already cleared and a parent recreated: the same restore path that
        // guards a top-level failure must also fire for children (#381).
        $GLOBALS['_pp_test_store']['fail_menu_item_titles'] = ['BadChild'];
        $result = pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['url' => 'https://example.com/p', 'label' => 'Parent', 'children' => [
                    ['url' => 'https://example.com/bad', 'label' => 'BadChild'],
                ]],
            ],
        ]);
        unset($GLOBALS['_pp_test_store']['fail_menu_item_titles']);

        $this->assertFalse($result['ok']);
        $menus = pp_get_menus();
        $this->assertCount(1, $menus[0]['items']);
        $this->assertSame('Original', $menus[0]['items'][0]['title']);
    }

    public function testSetMenuChildFailureDeletesItsOwnNewMenu(): void
    {
        // set_menu created the menu itself and a child fails: no half-built
        // menu (parent + failed child) may survive (#381).
        $GLOBALS['_pp_test_store']['fail_menu_item_titles'] = ['BadChild'];
        $result = pp_execute_action('set_menu', [
            'name'  => 'Fresh Menu',
            'items' => [
                ['url' => 'https://example.com/p', 'label' => 'Parent', 'children' => [
                    ['url' => 'https://example.com/bad', 'label' => 'BadChild'],
                ]],
            ],
        ]);
        unset($GLOBALS['_pp_test_store']['fail_menu_item_titles']);

        $this->assertFalse($result['ok']);
        $this->assertSame([], pp_get_menus());
    }

    public function testSetMenuAuthoredNestingRoundTripsThroughBatchRollback(): void
    {
        $services = pp_create_page('Servicios', 'publish');
        pp_execute_action('set_menu', [
            'name'  => 'Main Menu',
            'items' => [
                ['page_id' => $services, 'children' => [
                    ['url' => 'https://example.com/cloud', 'label' => 'Cloud'],
                ]],
            ],
        ]);
        $menu_id = pp_get_menus()[0]['id'];

        // A later batch step fails, forcing a full rollback. The nested tree
        // authored via set_menu must be restored exactly, parent link intact.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/new', 'label' => 'New',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $items   = array_values($GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id]);
        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item->title] = $item;
        }
        $this->assertArrayHasKey('Servicios', $byTitle);
        $this->assertArrayHasKey('Cloud', $byTitle);
        $this->assertSame(0, $byTitle['Servicios']->menu_item_parent);
        $this->assertSame($byTitle['Servicios']->ID, $byTitle['Cloud']->menu_item_parent);
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

    // ── Batch composition CAS baselines (#404) ──────────────────────────────
    // The batch executor threads a per-post baseline map into composition-mutating
    // steps and chains the server-derived post-write version after each success, so
    // a batch never false-conflicts against its own earlier writes.

    public function testCompositionMutatingDetectionMatchesMutatesFlag(): void
    {
        // The chat mandate keys on pp_action_is_composition_mutating(); it must
        // track the same mutates_composition flag the CLI baseline gate reads.
        foreach (['update_composition', 'add_component', 'remove_component',
                  'restore_composition', 'reorder_components', 'update_component',
                  'style_component'] as $name) {
            $this->assertTrue(pp_action_is_composition_mutating($name), "$name should be mutating");
        }
        foreach (['create_page', 'update_page_title', 'update_site_option',
                  'publish_page', 'trash_page'] as $name) {
            $this->assertFalse(pp_action_is_composition_mutating($name), "$name should not be mutating");
        }
    }

    public function testBatchNoFalseConflictRepeatedMutationsOnePageWithInitialBaseline(): void
    {
        $id = pp_create_page('Batch chain');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $baseline = pp_get_composition_marker($id)['version']; // e.g. 1

        // Three mutations to the same page, only the INITIAL baseline supplied. The
        // executor must chain the post-write version into each subsequent step so
        // none false-conflicts against the batch's own earlier write.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'B']]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'C']]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'D']]],
        ], [$id => $baseline]);

        $this->assertTrue($batch['ok'], 'batch must not false-conflict against its own writes');
        $this->assertFalse($batch['rolled_back']);
        $this->assertSame('D', pp_get_composition($id)[0]['props']['title']);
        // A3: the versions envelope carries the final post-write version (+3 writes).
        $this->assertArrayHasKey($id, $batch['versions']);
        $this->assertSame($baseline + 3, $batch['versions'][$id]);
    }

    public function testBatchStaleInitialBaselineFirstStepConflictsAndRollsBack(): void
    {
        $id = pp_create_page('Batch stale');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'A']]]);
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        $current = pp_get_composition_marker($id)['version']; // 2

        // Supplying a STALE initial baseline (1 vs current 2) must conflict on the
        // first mutating step and roll everything back.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'C']]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'D']]],
        ], [$id => $current - 1]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame(0, $batch['failed_at']);
        $this->assertSame('composition_conflict', $batch['steps'][0]['error_code']);
        // Content rolled back to its pre-batch state; no partial versions survived.
        // (The rollback's snapshot restore rewrites unconditionally, so the version
        // marker advances even though content is identical — existing batch-rollback
        // behavior, fail-safe: the empty versions map forces the client to re-read.)
        $this->assertSame('B', pp_get_composition($id)[0]['props']['title']);
        $this->assertSame([], $batch['versions']);
    }

    public function testBatchChainsPerPageAcrossTwoPages(): void
    {
        $a = pp_create_page('Page A');
        $b = pp_create_page('Page B');
        pp_update_composition($a, [['component' => 'hero', 'props' => ['title' => 'A0']]]);
        pp_update_composition($b, [['component' => 'hero', 'props' => ['title' => 'B0']]]);
        $va = pp_get_composition_marker($a)['version'];
        $vb = pp_get_composition_marker($b)['version'];

        // Interleaved writes across two pages, each with its own baseline; the map is
        // keyed per page so neither page's chaining bleeds into the other.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $a, 'component_index' => 0, 'props' => ['title' => 'A1']]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $b, 'component_index' => 0, 'props' => ['title' => 'B1']]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $a, 'component_index' => 0, 'props' => ['title' => 'A2']]],
        ], [$a => $va, $b => $vb]);

        $this->assertTrue($batch['ok']);
        $this->assertSame('A2', pp_get_composition($a)[0]['props']['title']);
        $this->assertSame('B1', pp_get_composition($b)[0]['props']['title']);
        $this->assertSame($va + 2, $batch['versions'][$a]);
        $this->assertSame($vb + 1, $batch['versions'][$b]);
    }

    public function testBatchCreatePageMidBatchNeedsNoBaselineAndJoinsVersionMap(): void
    {
        // A page created mid-batch starts at version-0 semantics — no browser
        // baseline required — and joins the returned versions map at its new version.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => [
                'title' => 'Fresh',
                'composition' => [['component' => 'hero', 'props' => ['title' => 'New']]],
            ]],
        ], []);

        $this->assertTrue($batch['ok']);
        $new_id = $batch['steps'][0]['target']['post_id'];
        $this->assertArrayHasKey($new_id, $batch['versions']);
        $this->assertSame(1, $batch['versions'][$new_id]);
    }

    public function testBatchLegacyPageVersionZeroBaselineInitializes(): void
    {
        // A page whose composition was never written reads as version 0; a batch
        // update_composition with baseline 0 must initialize it to version 1.
        $id = pp_create_page('Legacy');
        $this->assertSame(0, pp_get_composition_marker($id)['version']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_composition', 'params' => [
                'post_id' => $id,
                'composition' => [['component' => 'hero', 'props' => ['title' => 'First']]],
            ]],
        ], [$id => 0]);

        $this->assertTrue($batch['ok']);
        $this->assertSame(1, pp_get_composition_marker($id)['version']);
        $this->assertSame(1, $batch['versions'][$id]);
    }
}
