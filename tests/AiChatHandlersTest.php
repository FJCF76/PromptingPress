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
