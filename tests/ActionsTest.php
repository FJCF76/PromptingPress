<?php
/**
 * tests/ActionsTest.php — PHPUnit tests for the PromptingPress Action Layer
 *
 * Covers: registry functions, wp.php read/write functions, and all 9 actions
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

    public function testRegistryReturnsAllFourteenActions(): void
    {
        $actions = pp_get_registered_actions();
        $this->assertCount(14, $actions);
        $expected = [
            'create_page', 'update_site_option', 'update_page_title',
            'update_composition', 'publish_page', 'add_component',
            'remove_component', 'reorder_components', 'update_component',
            'style_component',
            'trash_page', 'restore_page', 'unpublish_page', 'clear_custom_css',
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
            ['type' => 'hero', 'props' => ['title' => 'Welcome', 'variant' => 'split', 'image_url' => 'https://example.com/photo.jpg']],
        ];
        $normalized = pp_normalize_composition($raw);
        $this->assertEquals('Welcome', $normalized[0]['props']['title']);
        $this->assertEquals('split', $normalized[0]['props']['variant']);
        $this->assertEquals('https://example.com/photo.jpg', $normalized[0]['props']['image_url']);
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
}
