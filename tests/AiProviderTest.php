<?php
/**
 * tests/AiProviderTest.php — PHPUnit tests for the AI Provider Layer
 *
 * Covers: WP 7.0 connector config, provider/model resolution,
 * error paths, proposal parsing, proposal validation.
 *
 * Integration tests (@group integration) hit real APIs when keys are available.
 */

use PHPUnit\Framework\TestCase;

class AiProviderTest extends TestCase
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

    // ── Helper: configure a test connector ─────────────────────────────────

    private function configureConnector(string $id, string $name, string $api_key): void
    {
        $setting_name = "wp_connector_{$id}_api_key";
        $GLOBALS['_pp_test_store']['connectors'][$id] = [
            'name'           => $name,
            'authentication' => [
                'setting_name'  => $setting_name,
                'constant_name' => strtoupper($id) . '_API_KEY',
                'env_var_name'  => strtoupper($id) . '_API_KEY',
            ],
        ];
        $GLOBALS['_pp_test_store']['options'][$setting_name] = $api_key;
    }

    // ── Connector Provider Map ────────────────────────────────────────────

    public function testConnectorProvidersReturnsThreeProviders(): void
    {
        $providers = pp_ai_connector_providers();
        $this->assertCount(3, $providers);
        $this->assertArrayHasKey('anthropic', $providers);
        $this->assertArrayHasKey('openai', $providers);
        $this->assertArrayHasKey('google', $providers);
    }

    public function testConnectorProvidersHaveBaseUrlAndDefaultModel(): void
    {
        $providers = pp_ai_connector_providers();
        foreach ($providers as $id => $provider) {
            $this->assertArrayHasKey('base_url', $provider, "$id missing base_url");
            $this->assertArrayHasKey('default_model', $provider, "$id missing default_model");
            $this->assertNotEmpty($provider['base_url'], "$id has empty base_url");
            $this->assertNotEmpty($provider['default_model'], "$id has empty default_model");
        }
    }

    // ── Configured Connectors ─────────────────────────────────────────────

    public function testGetConfiguredConnectorsReturnsEmptyWithNoConnectors(): void
    {
        $this->assertEmpty(pp_ai_get_configured_connectors());
    }

    public function testGetConfiguredConnectorsReturnsOnlyKeyed(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        // Add openai connector WITHOUT a key
        $GLOBALS['_pp_test_store']['connectors']['openai'] = [
            'name'           => 'OpenAI',
            'authentication' => ['setting_name' => 'wp_connector_openai_api_key'],
        ];

        $configured = pp_ai_get_configured_connectors();
        $this->assertCount(1, $configured);
        $this->assertArrayHasKey('anthropic', $configured);
        $this->assertEquals('sk-ant-test', $configured['anthropic']['api_key']);
    }

    public function testGetConfiguredConnectorsSkipsUnknownProviders(): void
    {
        // Add a connector not in pp_ai_connector_providers()
        $GLOBALS['_pp_test_store']['connectors']['huggingface'] = [
            'name'           => 'Hugging Face',
            'authentication' => ['setting_name' => 'wp_connector_hf_api_key'],
        ];
        $GLOBALS['_pp_test_store']['options']['wp_connector_hf_api_key'] = 'hf-test';

        $this->assertEmpty(pp_ai_get_configured_connectors());
    }

    // ── Model Listing ─────────────────────────────────────────────────────

    public function testGetConnectorModelsReturnsEmptyWithoutAiClient(): void
    {
        // WordPress\AiClient\AiClient class doesn't exist in test env
        $models = pp_ai_get_connector_models('anthropic');
        $this->assertIsArray($models);
        $this->assertEmpty($models);
    }

    public function testGetProviderModelsFallsBackToDefault(): void
    {
        // Without AiClient, pp_ai_get_connector_models returns [],
        // so pp_ai_get_provider_models should fall back to the hardcoded default
        $models = pp_ai_get_provider_models('anthropic');
        $this->assertCount(1, $models);
        $this->assertEquals('claude-sonnet-4-5-20250514', $models[0]['id']);
    }

    public function testGetProviderModelsReturnsEmptyForUnknownProvider(): void
    {
        $models = pp_ai_get_provider_models('nonexistent');
        $this->assertEmpty($models);
    }

    // ── Is Configured ─────────────────────────────────────────────────────

    public function testIsConfiguredReturnsFalseWithNoConnectors(): void
    {
        $this->assertFalse(pp_ai_is_configured());
    }

    public function testIsConfiguredReturnsTrueWithKeyedConnector(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->assertTrue(pp_ai_is_configured());
    }

    // ── Get Config ────────────────────────────────────────────────────────

    public function testGetConfigReturnsEmptyWhenNoConnectors(): void
    {
        $config = pp_ai_get_config();
        $this->assertEquals('', $config['provider']);
        $this->assertEquals('', $config['base_url']);
        $this->assertEquals('', $config['api_key']);
        $this->assertEquals('', $config['model']);
    }

    public function testGetConfigUsesFirstConnectorAsDefault(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');

        $config = pp_ai_get_config();
        $this->assertEquals('anthropic', $config['provider']);
        $this->assertStringContainsString('anthropic', $config['base_url']);
        $this->assertEquals('sk-ant-test', $config['api_key']);
        $this->assertEquals('claude-sonnet-4-5-20250514', $config['model']);
    }

    public function testGetConfigRespectsSelectedProvider(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');

        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_provider'] = 'openai';

        $config = pp_ai_get_config();
        $this->assertEquals('openai', $config['provider']);
        $this->assertEquals('sk-oai-test', $config['api_key']);
        $this->assertEquals('gpt-4o', $config['model']);
    }

    public function testGetConfigRespectsSelectedModel(): void
    {
        $this->configureConnector('anthropic', 'Anthropic', 'sk-ant-test');

        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_model'] = 'claude-opus-4-20250514';

        $config = pp_ai_get_config();
        $this->assertEquals('claude-opus-4-20250514', $config['model']);
    }

    public function testGetConfigFallsBackWhenSelectedProviderInvalid(): void
    {
        $this->configureConnector('openai', 'OpenAI', 'sk-oai-test');

        $GLOBALS['_pp_test_store']['options']['pp_ai_selected_provider'] = 'nonexistent';

        $config = pp_ai_get_config();
        $this->assertEquals('openai', $config['provider']);
        $this->assertEquals('sk-oai-test', $config['api_key']);
    }

    public function testGetConfigGoogleProvider(): void
    {
        $this->configureConnector('google', 'Google', 'aiza-test');

        $config = pp_ai_get_config();
        $this->assertEquals('google', $config['provider']);
        $this->assertStringContainsString('generativelanguage.googleapis.com', $config['base_url']);
        $this->assertEquals('gemini-2.5-flash', $config['model']);
    }

    // ── Stream Completion Error Paths ─────────────────────────────────────

    public function testStreamCompletionFailsWithoutApiKey(): void
    {
        $result = pp_ai_stream_completion([], function () {});
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API key', $result['error']);
    }

    public function testStreamCompletionFailsWithoutBaseUrl(): void
    {
        // Configure a connector but with a provider not in the map (edge case)
        // Actually: configure properly but clear the base_url — not possible
        // since base_url comes from the hardcoded map. Instead test the empty
        // config path (no connectors configured).
        $result = pp_ai_stream_completion([], function () {});
        $this->assertFalse($result['ok']);
    }

    public function testNonStreamingCompletionFailsWithoutApiKey(): void
    {
        $result = pp_ai_completion([]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API key', $result['error']);
    }

    // ── Error Response Parsing ────────────────────────────────────────────

    public function testParseErrorResponse401(): void
    {
        $msg = pp_ai_parse_error_response(401, '');
        $this->assertStringContainsString('rejected the API key', $msg);
        $this->assertStringContainsString('Settings > Connectors', $msg);
    }

    public function testParseErrorResponse429(): void
    {
        $msg = pp_ai_parse_error_response(429, '');
        $this->assertStringContainsString('Rate limited', $msg);
    }

    public function testParseErrorResponse404(): void
    {
        $msg = pp_ai_parse_error_response(404, '');
        $this->assertStringContainsString('Model not found', $msg);
        $this->assertStringContainsString('Settings > Connectors', $msg);
    }

    public function testParseErrorResponse400(): void
    {
        $msg = pp_ai_parse_error_response(400, '');
        $this->assertStringContainsString('rejected the request', $msg);
        $this->assertStringContainsString('Settings > Connectors', $msg);
    }

    public function testParseErrorResponseWithJsonBody(): void
    {
        $body = json_encode(['error' => ['message' => 'Quota exceeded']]);
        $msg = pp_ai_parse_error_response(503, $body);
        $this->assertStringContainsString('Quota exceeded', $msg);
    }

    public function testParseErrorResponseGenericFallback(): void
    {
        $msg = pp_ai_parse_error_response(500, 'not json');
        $this->assertStringContainsString('HTTP 500', $msg);
    }

    // ── Proposal Parsing ──────────────────────────────────────────────────

    public function testParseProposalFromFencedJson(): void
    {
        $response = "I'll change the accent color.\n\n```json\n" .
            '{"proposal": true, "steps": [{"type": "apply", "name": "update_design_token", "params": {"token": "--accent", "value": "#b45309"}, "description": "Change accent color"}]}' .
            "\n```";

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
        $this->assertTrue($proposal['proposal']);
        $this->assertCount(1, $proposal['steps']);
        $this->assertEquals('apply', $proposal['steps'][0]['type']);
        $this->assertEquals('update_design_token', $proposal['steps'][0]['name']);
    }

    public function testParseProposalFromBareJson(): void
    {
        $response = '{"proposal": true, "steps": [{"type": "action", "name": "create_page", "params": {"title": "FAQ"}, "description": "Create FAQ page"}]}';

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
        $this->assertCount(1, $proposal['steps']);
        $this->assertEquals('action', $proposal['steps'][0]['type']);
    }

    public function testParseProposalFromPrefixedJson(): void
    {
        $response = 'Here is my proposal: {"proposal": true, "steps": [{"type": "action", "name": "add_component", "params": {"page_id": 1, "component": "hero"}, "description": "Add hero"}]}';

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
    }

    public function testParseProposalReturnsNullForNoProposal(): void
    {
        $response = "Your site has 3 pages: Homepage, About, Contact.";
        $this->assertNull(pp_ai_parse_proposal($response));
    }

    public function testParseProposalReturnsNullForMalformedJson(): void
    {
        $response = '```json\n{"proposal": true, "steps": [INVALID}\n```';
        $this->assertNull(pp_ai_parse_proposal($response));
    }

    // ── Proposal Validation ───────────────────────────────────────────────

    public function testValidateProposalAcceptsValidStructure(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'Test']],
                ['type' => 'apply', 'name' => 'update_design_token', 'params' => ['token' => '--accent', 'value' => '#000']],
            ],
        ];
        $this->assertNotNull(pp_ai_validate_proposal($proposal));
    }

    public function testValidateProposalRejectsInvalidType(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'delete', 'name' => 'something', 'params' => []],
            ],
        ];
        $this->assertNull(pp_ai_validate_proposal($proposal));
    }

    public function testValidateProposalRejectsMissingName(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'params' => ['title' => 'Test']],
            ],
        ];
        $this->assertNull(pp_ai_validate_proposal($proposal));
    }

    public function testValidateProposalRejectsMissingParams(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'name' => 'create_page'],
            ],
        ];
        $this->assertNull(pp_ai_validate_proposal($proposal));
    }

    public function testValidateProposalRejectsNoSteps(): void
    {
        $proposal = ['proposal' => true];
        $this->assertNull(pp_ai_validate_proposal($proposal));
    }

    // ── Multi-Step Proposal ───────────────────────────────────────────────

    public function testParseMultiStepProposal(): void
    {
        $response = "```json\n" . json_encode([
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'FAQ'], 'description' => 'Create page'],
                ['type' => 'action', 'name' => 'add_component', 'params' => ['page_id' => 1, 'component' => 'faq'], 'description' => 'Add FAQ component'],
            ],
        ]) . "\n```";

        $proposal = pp_ai_parse_proposal($response);
        $this->assertNotNull($proposal);
        $this->assertCount(2, $proposal['steps']);
    }

    // ── Unregistered Capability Rejection ─────────────────────────────────

    public function testValidateProposalRejectsUnregisteredAction(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'name' => 'delete_page', 'params' => ['page_id' => 1]],
            ],
        ];
        $result = pp_ai_validate_proposal($proposal);
        $this->assertNotNull($result);
        $this->assertEmpty($result['steps']);
        $this->assertCount(1, $result['rejected']);
        $this->assertEquals('delete_page', $result['rejected'][0]['name']);
    }

    public function testValidateProposalSeparatesValidAndInvalidSteps(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'action', 'name' => 'add_component', 'params' => ['page_id' => 1, 'component' => 'hero'], 'description' => 'Add hero'],
                ['type' => 'action', 'name' => 'delete_page', 'params' => ['page_id' => 1], 'description' => 'Delete page'],
            ],
        ];
        $result = pp_ai_validate_proposal($proposal);
        $this->assertNotNull($result);
        $this->assertCount(1, $result['steps']);
        $this->assertEquals('add_component', $result['steps'][0]['name']);
        $this->assertCount(1, $result['rejected']);
        $this->assertEquals('delete_page', $result['rejected'][0]['name']);
    }

    public function testValidateProposalRejectsUnregisteredApply(): void
    {
        $proposal = [
            'proposal' => true,
            'steps'    => [
                ['type' => 'apply', 'name' => 'delete_everything', 'params' => []],
            ],
        ];
        $result = pp_ai_validate_proposal($proposal);
        $this->assertNotNull($result);
        $this->assertEmpty($result['steps']);
        $this->assertCount(1, $result['rejected']);
    }
}
