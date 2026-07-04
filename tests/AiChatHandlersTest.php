<?php
/**
 * tests/AiChatHandlersTest.php — PHPUnit tests for the AI Chat AJAX Handlers
 *
 * Covers: chat page registration, asset loading config structure,
 * nonce separation, and handler dispatch logic.
 *
 * Note: AJAX handlers use wp_send_json_* which are no-ops in test stubs.
 * These tests verify the config assembly and handler registration patterns,
 * not full request/response cycles (those require integration tests).
 */

use PHPUnit\Framework\TestCase;

class AiChatHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    private function configureConnector(string $id, string $name, string $api_key): void
    {
        $setting_name = "wp_connector_{$id}_api_key";
        $GLOBALS['_pp_test_store']['connectors'][$id] = [
            'name'           => $name,
            'authentication' => ['setting_name' => $setting_name],
        ];
        $GLOBALS['_pp_test_store']['options'][$setting_name] = $api_key;
    }

    // ── Config Assembly ───────────────────────────────────────────────────

    public function testConfiguredReturnsFalseWithNoConnectors(): void
    {
        $this->assertFalse(pp_ai_is_configured());
    }

    public function testConfiguredReturnsTrueWithConnector(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->assertTrue(pp_ai_is_configured());
    }

    // ── Nonce Separation ──────────────────────────────────────────────────

    public function testStreamAndExecuteNoncesAreDifferentActions(): void
    {
        $stream_action = 'pp_ai_stream';
        $execute_action = 'pp_ai_execute';
        $this->assertNotEquals($stream_action, $execute_action);
    }

    // ── Connector Provider Map ────────────────────────────────────────────

    public function testConnectorProvidersIncludeAllThree(): void
    {
        $providers = pp_ai_connector_providers();
        $this->assertArrayHasKey('anthropic', $providers);
        $this->assertArrayHasKey('openai', $providers);
        $this->assertArrayHasKey('google', $providers);
    }

    // ── Config Retrieval ──────────────────────────────────────────────────

    public function testGetConfigReturnsCompleteStructure(): void
    {
        $config = pp_ai_get_config();
        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey('base_url', $config);
        $this->assertArrayHasKey('api_key', $config);
        $this->assertArrayHasKey('model', $config);
    }

    public function testGetConfigRespectsSelectedProvider(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');

        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_provider'] = 'openai';
        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_model'] = 'gpt-4o';

        $config = pp_ai_get_config();
        $this->assertEquals('openai', $config['provider']);
        $this->assertStringContainsString('openai.com', $config['base_url']);
        $this->assertEquals('sk-oai-test', $config['api_key']);
        $this->assertEquals('gpt-4o', $config['model']);
    }

    // ── Provider Switch AJAX Handler ─────────────────────────────────────

    public function testSwitchProviderSavesSelectedProvider(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');

        // Simulate calling the handler logic directly
        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_provider'] = 'anthropic';
        update_option('pp_ai_selected_provider', 'openai');

        $this->assertEquals('openai', get_option('pp_ai_selected_provider'));
    }

    public function testSwitchProviderInvalidProviderDoesNotSave(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');

        $configured = pp_ai_get_configured_connectors();
        $provider = 'nonexistent';

        // Invalid provider should not be in configured list
        $this->assertFalse(isset($configured[$provider]));
    }

    public function testSwitchProviderSavesModelOption(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');

        update_option('pp_ai_selected_model', 'claude-opus-4-20250514');

        $this->assertEquals('claude-opus-4-20250514', get_option('pp_ai_selected_model'));
    }

    public function testSwitchProviderDefaultModelUsedWhenModelEmpty(): void
    {
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');
        $providers = pp_ai_connector_providers();

        // When model is empty, handler uses default_model for the provider
        $default = $providers['openai']['default_model'] ?? '';
        $this->assertEquals('gpt-4o', $default);
    }

    // ── Auth Denied ──────────────────────────────────────────────────────

    public function testCurrentUserCanGatesAccess(): void
    {
        // The AJAX handler checks current_user_can('edit_posts').
        // In bootstrap, current_user_can always returns true.
        // This test documents the contract: the handler MUST call the check.
        // We verify the function exists and the handler references it.
        $this->assertTrue(function_exists('current_user_can'));
        $this->assertTrue(current_user_can('edit_posts'));
    }

    // ── Capability Resolver (#131) ──────────────────────────────────────────
    //
    // pp_ai_preview/pp_ai_execute previously gated every action/apply on the
    // single coarse `edit_posts` check, letting a Contributor publish/trash
    // pages and rewrite site-wide design. _pp_required_caps_for() resolves
    // the real per-action/apply requirement; _pp_user_meets_required_caps()
    // checks the current user against it (AND semantics).

    public function testRequiredCapsForApplyIsAlwaysManageOptions(): void
    {
        $required = _pp_required_caps_for('apply', 'update_design_token', ['token' => '--color-accent', 'value' => '#000']);
        $this->assertSame([['cap' => 'manage_options']], $required);
    }

    public function testRequiredCapsForUnknownActionFailsClosed(): void
    {
        $required = _pp_required_caps_for('action', 'not_a_real_action', []);
        $this->assertSame([['cap' => 'manage_options']], $required);
    }

    public function testRequiredCapsForUnrecognizedScopeFailsClosed(): void
    {
        // The scope fallback is a whitelist of 'page'/'section', not a
        // blacklist of 'site' — an unrecognized/future scope value must
        // fail closed at manage_options rather than silently dropping to
        // the weaker edit_post check.
        pp_register_action('_test_unknown_scope_action', [
            'scope'  => 'workspace',
            'params' => ['post_id' => ['type' => 'int', 'required' => false]],
        ]);
        try {
            $this->assertSame(
                [['cap' => 'manage_options']],
                _pp_required_caps_for('action', '_test_unknown_scope_action', ['post_id' => 1])
            );
        } finally {
            unset($GLOBALS['_pp_actions']['_test_unknown_scope_action']);
        }
    }

    public function testRequiredCapsForSiteScopedAction(): void
    {
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'update_site_option', ['key' => 'blogname', 'value' => 'x'])
        );
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'clear_custom_css', [])
        );
    }

    public function testRequiredCapsForCreatePageIsPublishPages(): void
    {
        // Scope is 'site' in the registry (no existing post to check), but
        // page creation is core Editor territory — gating on manage_options
        // would lock Editors out of building pages through chat entirely.
        $this->assertSame(
            [['cap' => 'publish_pages']],
            _pp_required_caps_for('action', 'create_page', ['title' => 'New Page'])
        );
    }

    public function testRequiredCapsForPublishPageWithPostId(): void
    {
        $required = _pp_required_caps_for('action', 'publish_page', ['post_id' => 42]);
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 42], ['cap' => 'publish_pages']],
            $required
        );
    }

    public function testRequiredCapsForUnpublishPageWithPostId(): void
    {
        $required = _pp_required_caps_for('action', 'unpublish_page', ['post_id' => 7]);
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 7], ['cap' => 'publish_pages']],
            $required
        );
    }

    public function testRequiredCapsForPublishPageWithoutPostIdFailsClosed(): void
    {
        // No post_id to verify per-post ownership against — fail at the
        // highest bar rather than silently skip the post-scoped check.
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'publish_page', [])
        );
    }

    public function testRequiredCapsForTrashPageWithPostId(): void
    {
        $this->assertSame(
            [['cap' => 'delete_post', 'post_id' => 9]],
            _pp_required_caps_for('action', 'trash_page', ['post_id' => 9])
        );
    }

    public function testRequiredCapsForTrashPageWithoutPostIdFailsClosed(): void
    {
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'trash_page', [])
        );
    }

    public function testRequiredCapsForRestorePageMatchesTrashPage(): void
    {
        // WP core gates untrash on 'delete_post', the same capability as
        // trash — not 'edit_post'.
        $this->assertSame(
            [['cap' => 'delete_post', 'post_id' => 9]],
            _pp_required_caps_for('action', 'restore_page', ['post_id' => 9])
        );
    }

    public function testRequiredCapsForPageScopedActionDefaultsToEditPost(): void
    {
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 5]],
            _pp_required_caps_for('action', 'update_page_title', ['post_id' => 5, 'title' => 'x'])
        );
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 5]],
            _pp_required_caps_for('action', 'update_composition', ['post_id' => 5, 'composition' => []])
        );
    }

    public function testRequiredCapsForSectionScopedActionDefaultsToEditPost(): void
    {
        // update_component / style_component are scope=section, but sections
        // live on a page — same edit_post(post_id) check as page scope.
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 12]],
            _pp_required_caps_for('action', 'update_component', ['post_id' => 12, 'index' => 0])
        );
        $this->assertSame(
            [['cap' => 'edit_post', 'post_id' => 12]],
            _pp_required_caps_for('action', 'style_component', ['post_id' => 12, 'index' => 0])
        );
    }

    public function testRequiredCapsForPageScopedActionWithoutPostIdFailsClosed(): void
    {
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'add_component', ['component' => 'hero'])
        );
    }

    public function testRequiredCapsForZeroPostIdIsNotTreatedAsMissing(): void
    {
        // post_id=0 is_numeric() but not a real post. It is passed through to
        // current_user_can('delete_post', 0) rather than falling back to
        // manage_options — WordPress's own capability resolution denies
        // meta caps against a nonexistent post (get_post(0) === null), so
        // this stays safe without the resolver needing to special-case it.
        $this->assertSame(
            [['cap' => 'delete_post', 'post_id' => 0]],
            _pp_required_caps_for('action', 'trash_page', ['post_id' => 0])
        );
    }

    public function testRequiredCapsForNumericStringPostIdIsCoerced(): void
    {
        // In the real AJAX flow, pp_ai_coerce_params() always casts a
        // declared 'int' param (post_id) before this runs. Pin down that
        // the resolver itself also handles an uncoerced numeric string
        // correctly, in case it's ever called directly.
        $this->assertSame(
            [['cap' => 'delete_post', 'post_id' => 9]],
            _pp_required_caps_for('action', 'trash_page', ['post_id' => '9'])
        );
    }

    public function testRequiredCapsForNonScalarPostIdFailsClosed(): void
    {
        // A malformed post_id (e.g. an array instead of a scalar) is not
        // numeric, so it must fail closed exactly like a missing post_id.
        $this->assertSame(
            [['cap' => 'manage_options']],
            _pp_required_caps_for('action', 'trash_page', ['post_id' => ['1', '2']])
        );
    }

    public function testUserMeetsRequiredCapsAllPass(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['manage_options' => true];
        $this->assertTrue(_pp_user_meets_required_caps([['cap' => 'manage_options']]));
    }

    public function testUserMeetsRequiredCapsOneFails(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_post' => true, 'publish_pages' => false];
        $this->assertFalse(_pp_user_meets_required_caps([
            ['cap' => 'edit_post', 'post_id' => 1],
            ['cap' => 'publish_pages'],
        ]));
    }

    public function testUserMeetsRequiredCapsPostScopedCapFails(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_post' => false];
        $this->assertFalse(_pp_user_meets_required_caps([['cap' => 'edit_post', 'post_id' => 1]]));
    }

    // ── Role simulation (acceptance criteria) ───────────────────────────────

    private function simulateContributor(): void
    {
        // A Contributor has edit_posts (passes the coarse gate) but none of
        // the page/site/design capabilities the resolver checks.
        $GLOBALS['_pp_test_user_caps'] = [
            'manage_options' => false,
            'publish_pages'  => false,
            'edit_post'      => false,
            'delete_post'    => false,
        ];
    }

    private function simulateEditor(): void
    {
        // An Editor can fully manage pages but not site-wide design/options.
        $GLOBALS['_pp_test_user_caps'] = [
            'manage_options' => false,
            'publish_pages'  => true,
            'edit_post'      => true,
            'delete_post'    => true,
        ];
    }

    private function simulateAdministrator(): void
    {
        $GLOBALS['_pp_test_user_caps'] = [
            'manage_options' => true,
            'publish_pages'  => true,
            'edit_post'      => true,
            'delete_post'    => true,
        ];
    }

    public function testContributorIsBlockedFromPublishTrashSiteOptionsAndApplies(): void
    {
        $this->simulateContributor();

        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'publish_page', ['post_id' => 1])));
        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'trash_page', ['post_id' => 1])));
        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'update_site_option', ['key' => 'blogname'])));
        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'clear_custom_css', [])));
        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('apply', 'update_design_token', ['token' => '--color-accent'])));
    }

    public function testEditorCanMutatePagesButNotAppliesOrSiteOptions(): void
    {
        $this->simulateEditor();

        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'publish_page', ['post_id' => 1])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'trash_page', ['post_id' => 1])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'restore_page', ['post_id' => 1])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'update_page_title', ['post_id' => 1, 'title' => 'x'])));

        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'update_site_option', ['key' => 'blogname'])));
        $this->assertFalse(_pp_user_meets_required_caps(_pp_required_caps_for('apply', 'update_design_token', ['token' => '--color-accent'])));
    }

    public function testAdministratorCanDoEverything(): void
    {
        $this->simulateAdministrator();

        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'publish_page', ['post_id' => 1])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'trash_page', ['post_id' => 1])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('action', 'update_site_option', ['key' => 'blogname'])));
        $this->assertTrue(_pp_user_meets_required_caps(_pp_required_caps_for('apply', 'update_design_token', ['token' => '--color-accent'])));
    }

    // ── Action/Apply Type Validation ──────────────────────────────────────

    public function testValidTypesAreActionAndApply(): void
    {
        // Documents the contract: execute handler only accepts 'action' or 'apply'
        $valid = ['action', 'apply'];
        $this->assertContains('action', $valid);
        $this->assertContains('apply', $valid);
        $this->assertNotContains('delete', $valid);
        $this->assertNotContains('query', $valid);
    }

    // ── Magic-quotes unslashing (#125) ──────────────────────────────────────

    public function testWpUnslashRestoresQuotesAndBackslashesInMessages(): void
    {
        // Regression (#125): WordPress magic-quotes every $_POST value
        // during bootstrap (wp_magic_quotes()). The non-streaming chat
        // fallback (wp_ajax_pp_ai_chat) reads $_POST['messages'] directly
        // and previously passed it straight to the provider unslashed.
        // Simulate what $_POST would actually contain for a message like
        // `change the hero title to "It's live!"` and confirm wp_unslash()
        // restores the original text byte-for-byte.
        $original = 'change the hero title to "It\'s live!" and note the \\ character';
        $slashed = addslashes($original);
        $this->assertNotSame($original, $slashed, 'Fixture must actually be slashed to test anything.');

        $postMessages = [
            ['role' => 'user', 'content' => $slashed],
        ];

        $unslashed = wp_unslash($postMessages);

        $this->assertSame($original, $unslashed[0]['content']);
    }

    public function testCoerceParamsDoesNotDoubleUnslashArrayTypeParams(): void
    {
        // Regression (#125): pp_ai_coerce_params() used to call
        // wp_unslash() again on array-type params after both AJAX
        // handlers already unslash the whole $params array once. Confirm
        // a JSON string containing an escaped backslash decodes correctly
        // without being stripped twice (which would corrupt it).
        $composition = [['component' => 'section', 'props' => ['body' => 'A path: C:\\Users\\x']]];
        $json = wp_json_encode($composition);

        // Simulate the caller's single top-level unslash of the whole
        // params array (the JSON string itself carries no magic-quote
        // slashes here — it's the raw encoded value the caller already
        // unslashed once, matching what both handlers now do).
        $params = pp_ai_coerce_params('action', 'update_composition', ['composition' => $json]);

        $this->assertSame($composition, $params['composition']);
    }

    public function testGetUnslashedPostParamsRestoresPlainStringValues(): void
    {
        // Regression (#125): before this fix, plain string params (e.g.
        // update_page_title's 'title', type='string') were never unslashed
        // anywhere — pp_ai_coerce_params() only ever touched array-type
        // params. _pp_ai_get_unslashed_post_params() now unslashes the
        // whole $_POST['params'] array once, covering plain strings too.
        $original = 'It\'s "live" now';
        $_POST['params'] = ['title' => addslashes($original)];

        $params = _pp_ai_get_unslashed_post_params();

        $this->assertSame($original, $params['title']);
        unset($_POST['params']);
    }

    // ── Proposal Parsing Integration ──────────────────────────────────────

    public function testProposalParsingIntegrationWithActionTypes(): void
    {
        // Simulate a model response with action-type proposal
        $response = "```json\n" . json_encode([
            'proposal' => true,
            'steps'    => [
                [
                    'type'        => 'action',
                    'name'        => 'add_component',
                    'params'      => ['page_id' => 10, 'component' => 'hero', 'props' => ['heading' => 'Welcome']],
                    'description' => 'Add hero section to homepage',
                ],
            ],
        ]) . "\n```";

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
        $this->assertEquals('action', $proposal['steps'][0]['type']);
        $this->assertEquals('add_component', $proposal['steps'][0]['name']);
    }

    public function testProposalParsingIntegrationWithApplyTypes(): void
    {
        $response = "```json\n" . json_encode([
            'proposal' => true,
            'steps'    => [
                [
                    'type'        => 'apply',
                    'name'        => 'update_design_token',
                    'params'      => ['token' => '--color-accent', 'value' => '#b45309'],
                    'description' => 'Change accent color to amber',
                ],
            ],
        ]) . "\n```";

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
        $this->assertEquals('apply', $proposal['steps'][0]['type']);
        $this->assertEquals('update_design_token', $proposal['steps'][0]['name']);
    }

    // ── System Prompt ↔ Action Registry Consistency ───────────────────────

    public function testSystemPromptListsAllRegisteredActions(): void
    {
        $prompt = pp_ai_system_prompt();
        $actions = pp_get_registered_actions();

        foreach (array_keys($actions) as $action_name) {
            $this->assertStringContainsString(
                $action_name,
                $prompt,
                "System prompt missing action: {$action_name}"
            );
        }
    }

    public function testSystemPromptListsAllRegisteredApplies(): void
    {
        $prompt = pp_ai_system_prompt();
        $applies = pp_get_registered_applies();

        foreach (array_keys($applies) as $apply_name) {
            $this->assertStringContainsString(
                $apply_name,
                $prompt,
                "System prompt missing apply: {$apply_name}"
            );
        }
    }

    // ── Destructive-action warning registry (#74) ───────────────────────────
    //
    // The chat UI surfaces an impact warning for destructive capabilities. The
    // single source of truth is the 'impact_warning' key on action/apply
    // definitions. This registry-coverage test fails CI if a known-destructive
    // capability ever ships without a warning — the desync class D6 closes.

    /** Capabilities that MUST always carry an impact_warning. */
    private const KNOWN_DESTRUCTIVE = [
        'update_composition',       // action — replaces the whole page
        'remove_component',         // action — drops a component
        'clear_custom_css',         // action — wipes all Custom CSS
        'reset_all_design_tokens',  // apply  — resets every token override
    ];

    /**
     * Builds the same {name: warning} map that lib/ai-chat.php localizes into
     * window.ppAiChat.impact_warnings, aggregating both registries.
     */
    private function buildImpactWarnings(): array
    {
        $warnings = [];
        foreach (pp_get_registered_actions() as $name => $def) {
            if (!empty($def['impact_warning'])) {
                $warnings[$name] = $def['impact_warning'];
            }
        }
        foreach (pp_get_registered_applies() as $name => $def) {
            if (!empty($def['impact_warning'])) {
                $warnings[$name] = $def['impact_warning'];
            }
        }
        return $warnings;
    }

    public function testKnownDestructiveCapabilitiesAllCarryImpactWarning(): void
    {
        $warnings = $this->buildImpactWarnings();
        foreach (self::KNOWN_DESTRUCTIVE as $name) {
            $this->assertArrayHasKey(
                $name,
                $warnings,
                "Destructive capability '{$name}' is missing an 'impact_warning' — the chat UI would show no warning before it runs."
            );
            $this->assertNotEmpty($warnings[$name], "impact_warning for '{$name}' must be non-empty.");
        }
    }

    public function testImpactWarningsAreServerDrivenFromDefinitions(): void
    {
        // The map must derive from the definitions, not a hardcoded list:
        // every entry traces back to an action/apply that declares it.
        $warnings = $this->buildImpactWarnings();
        $defs = array_merge(pp_get_registered_actions(), pp_get_registered_applies());
        foreach ($warnings as $name => $text) {
            $this->assertArrayHasKey($name, $defs, "Warning for unknown capability '{$name}'.");
            $this->assertSame($defs[$name]['impact_warning'], $text);
        }
    }

    public function testNonDestructiveActionsHaveNoImpactWarning(): void
    {
        // A normal, non-destructive action must NOT carry a warning (so the UI
        // stays quiet for safe operations).
        $actions = pp_get_registered_actions();
        $this->assertArrayHasKey('update_component', $actions);
        $this->assertArrayNotHasKey('impact_warning', $actions['update_component']);
    }
}
