<?php
/**
 * tests/AiProviderTest.php — PHPUnit tests for the AI Provider Layer
 *
 * Covers: config reading, error paths (missing key, invalid URL),
 * proposal parsing (bare JSON, fenced JSON, malformed fallback),
 * proposal validation.
 *
 * Note: actual curl/streaming tests require integration testing.
 * These tests cover the pure functions in ai-provider.php.
 */

use PHPUnit\Framework\TestCase;

class AiProviderTest extends TestCase
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

    // ── Config Reading ────────────────────────────────────────────────────

    public function testGetConfigReadsFromOptions(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_BASE_URL] = 'https://custom.api/v1/chat';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_API_KEY] = 'sk-test-key';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_MODEL] = 'gpt-4o-mini';

        $config = pp_ai_get_config();
        $this->assertEquals('https://custom.api/v1/chat', $config['base_url']);
        $this->assertEquals('sk-test-key', $config['api_key']);
        $this->assertEquals('gpt-4o-mini', $config['model']);
    }

    public function testGetConfigUsesDefaults(): void
    {
        $config = pp_ai_get_config();
        $this->assertEquals(PP_AI_DEFAULT_BASE_URL, $config['base_url']);
        $this->assertEquals('', $config['api_key']);
        $this->assertEquals(PP_AI_DEFAULT_MODEL, $config['model']);
    }

    public function testIsConfiguredReturnsFalseWithoutKey(): void
    {
        $this->assertFalse(pp_ai_is_configured());
    }

    public function testIsConfiguredReturnsTrueWithKey(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_API_KEY] = 'test-key';
        $this->assertTrue(pp_ai_is_configured());
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
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_API_KEY] = 'test-key';
        $GLOBALS['_pp_test_store']['options'][PP_AI_OPT_BASE_URL] = '';

        $result = pp_ai_stream_completion([], function () {});
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Base URL', $result['error']);
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
        $this->assertStringContainsString('Check AI Settings', $msg);
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
    }

    public function testParseErrorResponse400(): void
    {
        $msg = pp_ai_parse_error_response(400, '');
        $this->assertStringContainsString('rejected the request', $msg);
        $this->assertStringContainsString('Check AI Settings', $msg);
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
