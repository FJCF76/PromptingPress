<?php
/**
 * tests/AiContextTest.php — PHPUnit tests for the AI Context Layer
 *
 * Covers: system prompt assembly, page context, media inventory,
 * message formatting, schema condensing, param formatting.
 */

use PHPUnit\Framework\TestCase;

class AiContextTest extends TestCase
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

    // ── System Prompt ─────────────────────────────────────────────────────

    public function testSystemPromptContainsSiteIdentity(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('Test Site', $prompt);
        $this->assertStringContainsString('https://example.com', $prompt);
    }

    public function testSystemPromptContainsPageSection(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## Pages', $prompt);
    }

    public function testSystemPromptShowsNoPagesWhenEmpty(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('No pages exist yet', $prompt);
    }

    public function testSystemPromptContainsComponentCatalog(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## Available Components', $prompt);
    }

    public function testSystemPromptContainsActionSignatures(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## Available Actions', $prompt);
        $this->assertStringContainsString('create_page', $prompt);
        $this->assertStringContainsString('add_component', $prompt);
    }

    public function testSystemPromptContainsApplySignatures(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## Available Applies', $prompt);
        $this->assertStringContainsString('update_design_token', $prompt);
    }

    public function testSystemPromptContainsResponseInstructions(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## How to Respond', $prompt);
        $this->assertStringContainsString('"proposal": true', $prompt);
    }

    public function testSystemPromptContainsDesignTokens(): void
    {
        // Design tokens require base.css to exist
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('## Design Tokens', $prompt);
    }

    // ── Schema Condensing ─────────────────────────────────────────────────

    public function testCondenseSchemaWithRequiredAndOptional(): void
    {
        $schema = [
            'properties' => [
                'heading' => ['type' => 'string'],
                'body'    => ['type' => 'string'],
                'cta_url' => ['type' => 'string'],
            ],
            'required' => ['heading'],
        ];
        $result = pp_ai_condense_schema($schema);
        $this->assertStringContainsString('heading: string', $result);
        $this->assertStringContainsString('body?: string', $result);
        $this->assertStringContainsString('cta_url?: string', $result);
    }

    public function testCondenseSchemaEmptyReturnsNoProps(): void
    {
        $this->assertEquals('(no props)', pp_ai_condense_schema([]));
    }

    // ── Param Formatting ──────────────────────────────────────────────────

    public function testFormatParamsProducesCompactString(): void
    {
        $params = [
            'page_id' => ['type' => 'int', 'required' => true],
            'title'   => ['type' => 'string', 'required' => false],
        ];
        $result = pp_ai_format_params($params);
        $this->assertStringContainsString('page_id: int', $result);
        $this->assertStringContainsString('title?: string', $result);
    }

    public function testFormatParamsEmptyReturnsNone(): void
    {
        $this->assertEquals('(none)', pp_ai_format_params([]));
    }

    // ── Page Context ──────────────────────────────────────────────────────

    public function testPageContextReturnsDataForExistingPage(): void
    {
        $GLOBALS['_pp_test_store']['posts'][20] = [
            'post_type'   => 'page',
            'post_title'  => 'About Us',
            'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][20]['_pp_composition'] = '[{"component":"hero"}]';

        $ctx = pp_ai_page_context(20);
        $this->assertEquals(20, $ctx['id']);
        $this->assertEquals('About Us', $ctx['title']);
        $this->assertEquals('publish', $ctx['status']);
        $this->assertArrayHasKey('composition', $ctx);
    }

    public function testPageContextReturnsEmptyForMissingPage(): void
    {
        $ctx = pp_ai_page_context(999);
        $this->assertEmpty($ctx);
    }

    // ── Message Formatting ────────────────────────────────────────────────

    public function testFormatMessagesPrependsSystemPrompt(): void
    {
        $conversation = [
            ['role' => 'user', 'content' => 'Hello'],
        ];
        $messages = pp_ai_format_messages('System prompt here', $conversation);

        $this->assertCount(2, $messages);
        $this->assertEquals('system', $messages[0]['role']);
        $this->assertStringContainsString('System prompt here', $messages[0]['content']);
        $this->assertEquals('user', $messages[1]['role']);
        $this->assertEquals('Hello', $messages[1]['content']);
    }

    public function testFormatMessagesIncludesPageContext(): void
    {
        $GLOBALS['_pp_test_store']['posts'][30] = [
            'post_type'   => 'page',
            'post_title'  => 'Contact',
            'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][30]['_pp_composition'] = '[]';

        $conversation = [['role' => 'user', 'content' => 'Edit this page']];
        $messages = pp_ai_format_messages('System', $conversation, 30);

        $this->assertStringContainsString('Contact', $messages[0]['content']);
        $this->assertStringContainsString('Current Page Context', $messages[0]['content']);
    }

    public function testFormatMessagesSkipsMalformedConversation(): void
    {
        $conversation = [
            ['role' => 'user', 'content' => 'Hello'],
            ['bad' => 'data'],
            ['role' => 'assistant', 'content' => 'Hi'],
        ];
        $messages = pp_ai_format_messages('System', $conversation);
        // System + 2 valid messages (malformed one skipped)
        $this->assertCount(3, $messages);
    }

    // ── Site Context Bundle ───────────────────────────────────────────────

    public function testSiteContextBundleStructure(): void
    {
        $ctx = pp_ai_site_context();
        $this->assertArrayHasKey('site', $ctx);
        $this->assertArrayHasKey('pages', $ctx);
        $this->assertArrayHasKey('components', $ctx);
        $this->assertArrayHasKey('actions', $ctx);
        $this->assertArrayHasKey('applies', $ctx);
        $this->assertArrayHasKey('tokens', $ctx);
        $this->assertEquals('Test Site', $ctx['site']['name']);
    }
}
