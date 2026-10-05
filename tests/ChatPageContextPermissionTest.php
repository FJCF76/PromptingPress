<?php
/**
 * tests/ChatPageContextPermissionTest.php — the chat's page context honours per-page permissions.
 *
 * The chat names one page per turn, and that page's composition is placed in the model
 * context. These pins hold that a page reaches the context only for a user who may edit it,
 * at every way in:
 *
 *   THE READER        pp_ai_page_context() / pp_ai_format_messages(): the composition is
 *                     absent for a page the user may not edit, present for one they may.
 *   THE FALLBACK      _pp_ai_chat_fallback_response(): refuses with the same answer as its
 *                     existing capability refusal, before any other check runs.
 *   THE STREAM        ai-stream.php is a standalone script that exits, so it is pinned by
 *                     its statements: the per-page refusal sits right after the capability
 *                     refusal, with the same status, refusal marker and body, ahead of the
 *                     configuration check and the context assembly. The client side of the
 *                     refusal is pinned in tests/js/pp-ai-chat-stream-refusal.test.js.
 *
 * A page that does not exist gets the same answer as one the user may not edit, so neither
 * surface says whether a page exists. The per-object grant is simulated with the bootstrap's
 * Closure form of $GLOBALS['_pp_test_user_caps'] — WordPress core maps `edit_post` on a
 * missing post to `do_not_allow`, which the closures below mirror by granting only listed ids.
 */

use PHPUnit\Framework\TestCase;

class ChatPageContextPermissionTest extends TestCase
{
    private const OWN_PAGE   = 40;
    private const OTHER_PAGE = 41;
    private const MISSING    = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
        $this->seedPage(self::OWN_PAGE, 'Team page', 'Our team at a glance');
        $this->seedPage(self::OTHER_PAGE, 'Roadmap', 'Quarterly roadmap working notes');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    private function seedPage(int $id, string $title, string $headline): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => 'page',
            'post_title'  => $title,
            'post_status' => 'draft',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition'] = wp_json_encode([
            ['component' => 'hero', 'props' => ['headline' => $headline]],
        ]);
    }

    /** A user who can use the chat (edit_posts) and may edit only the listed pages. */
    private function actAsUserWhoMayEdit(int ...$ids): void
    {
        $GLOBALS['_pp_test_user_caps'] = [
            'edit_posts' => true,
            'edit_post'  => static fn ($id = null): bool => in_array((int) $id, $ids, true),
        ];
    }

    private function systemContent(int $page_id): string
    {
        $messages = pp_ai_format_messages('System', [['role' => 'user', 'content' => 'Tighten the copy']], $page_id);
        return $messages[0]['content'];
    }

    // ── The reader ────────────────────────────────────────────────────────

    public function testChatContextRequiresPermissionForTheTargetPage(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $content = $this->systemContent(self::OTHER_PAGE);

        $this->assertStringNotContainsString('Current Page Context', $content);
        $this->assertStringNotContainsString('Quarterly roadmap working notes', $content);
        $this->assertStringNotContainsString('Roadmap (ID: ' . self::OTHER_PAGE, $content);
        $this->assertSame([], pp_ai_page_context(self::OTHER_PAGE));
    }

    public function testChatContextIncludesAPageTheUserMayEdit(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $content = $this->systemContent(self::OWN_PAGE);

        $this->assertStringContainsString('Current Page Context', $content);
        $this->assertStringContainsString('Our team at a glance', $content);
    }

    public function testChatContextIncludesAnyPageForAnAdministrator(): void
    {
        // No cap map: the bootstrap's all-capabilities user.
        $content = $this->systemContent(self::OTHER_PAGE);

        $this->assertStringContainsString('Current Page Context', $content);
        $this->assertStringContainsString('Quarterly roadmap working notes', $content);
    }

    public function testChatContextAnswersTheSameForAMissingPageAsForAForbiddenOne(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $this->assertSame(pp_ai_page_context(self::MISSING), pp_ai_page_context(self::OTHER_PAGE));
        $this->assertSame($this->systemContent(self::MISSING), $this->systemContent(self::OTHER_PAGE));
    }

    public function testNoPageInScopeNeedsNoPagePermission(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_post' => false];

        $this->assertTrue(pp_ai_page_context_permitted(null));
        $this->assertTrue(pp_ai_page_context_permitted(0));
        $this->assertFalse(pp_ai_page_context_permitted(self::OWN_PAGE));
    }

    // ── The non-streaming fallback ────────────────────────────────────────

    public function testChatFallbackRequiresPermissionForTheTargetPage(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $result = _pp_ai_chat_fallback_response([
            'messages' => [['role' => 'user', 'content' => 'Tighten the copy']],
            'page_id'  => (string) self::OTHER_PAGE,
        ]);

        $this->assertSame(['ok' => false, 'data' => 'Permission denied.'], $result);
    }

    public function testChatFallbackRefusesBeforeAnyOtherCheck(): void
    {
        // Provider not configured AND no messages: either would answer first if the page
        // check came later. The refusal is the answer, and carries nothing else.
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $this->assertSame(
            ['ok' => false, 'data' => 'Permission denied.'],
            _pp_ai_chat_fallback_response(['messages' => [], 'page_id' => self::OTHER_PAGE])
        );
    }

    public function testChatFallbackPageRefusalMatchesItsCapabilityRefusal(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => false];
        $capability_refusal = _pp_ai_chat_fallback_response(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->actAsUserWhoMayEdit(self::OWN_PAGE);
        $page_refusal    = _pp_ai_chat_fallback_response(['messages' => [['role' => 'user', 'content' => 'hi']], 'page_id' => self::OTHER_PAGE]);
        $missing_refusal = _pp_ai_chat_fallback_response(['messages' => [['role' => 'user', 'content' => 'hi']], 'page_id' => self::MISSING]);

        $this->assertSame($capability_refusal, $page_refusal);
        $this->assertSame($page_refusal, $missing_refusal);
    }

    public function testChatFallbackLetsAPermittedPageThrough(): void
    {
        // The next check (provider configuration) answers, so the page check passed.
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $result = _pp_ai_chat_fallback_response([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'page_id'  => self::OWN_PAGE,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', $result['data']);
    }

    // ── The stream ────────────────────────────────────────────────────────

    /**
     * Statement positions in ai-stream.php. Matched as whole statements, never bare names,
     * so a comment that mentions a function cannot satisfy the pin.
     */
    private static function streamOffsets(): array
    {
        $src = file_get_contents(dirname(__DIR__) . '/ai-stream.php');
        $find = static function (string $pattern) use ($src): ?int {
            return preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
        };
        $refusal = '\s*http_response_code\(403\);\s*header\(\'X-PP-Refusal: 1\'\);\s*echo \'Insufficient permissions\.\';\s*exit;\s*\}';
        return [
            'capability' => $find('/^if \(!current_user_can\(\'edit_posts\'\)\) \{' . $refusal . '/m'),
            'page_id'    => $find('/^\$page_id = isset\(\$input\[\'page_id\'\]\) \? \(int\) \$input\[\'page_id\'\] : null;$/m'),
            'page_gate'  => $find('/^if \(!pp_ai_page_context_permitted\(\$page_id\)\) \{' . $refusal . '/m'),
            'configured' => $find('/^if \(!pp_ai_is_configured\(\)\) \{/m'),
            'assembly'   => $find('/^\$messages = pp_ai_format_messages\(\$system_prompt, \$conversation, \$page_id\);$/m'),
            'baseline'   => $find('/^if \(\$page_id && get_post\(\$page_id\)\) \{$/m'),
        ];
    }

    public function testChatStreamRequiresPermissionForTheTargetPage(): void
    {
        $at = self::streamOffsets();

        foreach ($at as $name => $offset) {
            $this->assertNotNull($offset, "ai-stream.php: statement '{$name}' not found");
        }
        $this->assertLessThan($at['page_id'], $at['capability'], 'the capability check comes first');
        $this->assertLessThan($at['page_gate'], $at['page_id'], 'page_id is read before the page check');
        $this->assertLessThan($at['configured'], $at['page_gate'], 'the page check precedes the configuration check');
        $this->assertLessThan($at['assembly'], $at['page_gate'], 'the page check precedes the context assembly');
        $this->assertLessThan($at['baseline'], $at['assembly'], 'the baseline is captured after the read it describes');
    }

    public function testEveryChatStreamRefusalCarriesTheRefusalMarker(): void
    {
        // The chat client shows a 403 as a refusal only when it carries this marker; an
        // unmarked one is treated as a proxy failure and retried through the fallback.
        $src = file_get_contents(dirname(__DIR__) . '/ai-stream.php');
        $refusals = preg_match_all('/http_response_code\(403\);/', $src);
        $this->assertSame(3, $refusals, 'nonce, capability and page refusals');
        $this->assertSame($refusals, preg_match_all('/http_response_code\(403\);\s*header\(\'X-PP-Refusal: 1\'\);/', $src));
    }

    public function testChatStreamReadsThePageIdExactlyOnce(): void
    {
        // A second read further down would be a page id the gate never saw.
        $src = file_get_contents(dirname(__DIR__) . '/ai-stream.php');
        $this->assertSame(1, preg_match_all('/\$page_id\s*=(?!=)/', $src));
    }
}
