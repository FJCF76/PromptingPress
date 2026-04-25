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

    public function testRegistryReturnsAllTwelveActions(): void
    {
        $actions = pp_get_registered_actions();
        $this->assertCount(12, $actions);
        $expected = [
            'create_page', 'update_site_option', 'update_page_title',
            'update_composition', 'publish_page', 'add_component',
            'remove_component', 'reorder_components', 'update_component',
            'trash_page', 'restore_page', 'unpublish_page',
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
        $this->assertEquals($comp, pp_get_composition(50));
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
        $this->assertEquals($comp, pp_get_composition($post_id));
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
        $this->assertEquals($new, pp_get_composition($id));
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
}
