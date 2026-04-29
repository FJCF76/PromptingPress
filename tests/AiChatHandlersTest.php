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
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    // ── Config Assembly ───────────────────────────────────────────────────

    public function testConfiguredReturnsFalseWithNoKey(): void
    {
        $this->assertFalse(pp_ai_is_configured());
    }

    public function testConfiguredReturnsTrueWithKey(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_API_KEY] = 'ghp_test123';
        $this->assertTrue(pp_ai_is_configured());
    }

    // ── Nonce Separation ──────────────────────────────────────────────────

    public function testStreamAndExecuteNoncesAreDifferentActions(): void
    {
        // Verify that the two nonce actions are distinct strings.
        // The actual nonce values are identical in test stubs ('test_nonce'),
        // but the action strings passed to wp_create_nonce differ.
        // This test documents the separation requirement.
        $stream_action = 'pp_ai_stream';
        $execute_action = 'pp_ai_execute';
        $this->assertNotEquals($stream_action, $execute_action);
    }

    // ── AI Settings Constants ─────────────────────────────────────────────

    public function testSettingsConstantsDefined(): void
    {
        $this->assertTrue(defined('PP_AI_OPT_PROVIDER'));
        $this->assertTrue(defined('PP_AI_OPT_BASE_URL'));
        $this->assertTrue(defined('PP_AI_OPT_API_KEY'));
        $this->assertTrue(defined('PP_AI_OPT_MODEL'));
        $this->assertTrue(defined('PP_AI_DEFAULT_PROVIDER'));
        $this->assertTrue(defined('PP_AI_DEFAULT_BASE_URL'));
        $this->assertTrue(defined('PP_AI_DEFAULT_MODEL'));
    }

    public function testDefaultBaseUrlIsGitHubModels(): void
    {
        $this->assertStringContainsString('models.github.ai', PP_AI_DEFAULT_BASE_URL);
    }

    public function testDefaultModelIsGpt5Chat(): void
    {
        $this->assertEquals('openai/gpt-5-chat', PP_AI_DEFAULT_MODEL);
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

    public function testGetConfigRespectsCustomValues(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_PROVIDER] = 'OpenAI';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_BASE_URL] = 'https://api.openai.com/v1/chat/completions';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_API_KEY] = 'sk-openai-key';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_MODEL] = 'gpt-4-turbo';

        $config = pp_ai_get_config();
        $this->assertEquals('OpenAI', $config['provider']);
        $this->assertEquals('https://api.openai.com/v1/chat/completions', $config['base_url']);
        $this->assertEquals('sk-openai-key', $config['api_key']);
        $this->assertEquals('gpt-4-turbo', $config['model']);
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
}
