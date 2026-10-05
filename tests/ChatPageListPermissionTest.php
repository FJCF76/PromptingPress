<?php
/**
 * tests/ChatPageListPermissionTest.php — the chat's page list shows the pages you can work on.
 *
 * The chat already refuses a turn on a page the user may not edit (ChatPageContextPermissionTest).
 * These pins hold that the LISTS the chat shows agree with that check:
 *
 *   THE FILTER        pp_ai_editable_pages(): keeps a page only when the per-page check admits
 *                     it, in order; an administrator keeps every page; a row with no positive
 *                     id is dropped.
 *   THE PROMPT        the system prompt's page inventory lists only those pages. An empty list
 *                     says "No pages exist yet." only to a user who can open the Pages screen and
 *                     see private pages there (edit_pages + read_private_pages) on a site with
 *                     none, and "None you can edit." otherwise, so it never tells a user whether
 *                     pages they cannot see exist. The byte budget holds for both.
 *   THE DROPDOWN      both copies (the server-rendered <select> and the list handed to the
 *                     script) are built from the filter; an empty list renders WordPress's own
 *                     "No pages found." as the only, empty-valued, option.
 *   THE BASELINE READ _pp_ai_page_baseline_response() answers a missing page exactly like one
 *                     the user may not edit.
 *
 * THE PAGE-LIST MEMO. pp_composition_pages() memoises for the life of the process and has no
 * reset seam (its docblock), so in a full-suite run the list it returns is whatever the first
 * caller saw (an empty list, measured on the branch that added this file). Every pin that reads
 * that list therefore runs in its own process (#[RunInSeparateProcess]), where the memo starts
 * empty and is filled from this file's own seeded store. The filter and the dropdown markup take
 * the list as an argument and are pinned directly; every call site that feeds them
 * pp_composition_pages() is also pinned by statement.
 *
 * The per-object grant is the bootstrap's Closure form of $GLOBALS['_pp_test_user_caps'];
 * WordPress core maps `edit_post` on a missing post to `do_not_allow`, which the closures
 * mirror by granting only listed ids.
 */

use PHPUnit\Framework\TestCase;

class ChatPageListPermissionTest extends TestCase
{
    private const OWN_PAGE     = 50;
    private const OTHER_DRAFT  = 51;
    private const OTHER_PUBLIC = 52;
    private const MISSING      = 5252;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
        $this->seedPage(self::OWN_PAGE, 'Team page', 'draft');
        $this->seedPage(self::OTHER_DRAFT, 'Unannounced launch', 'draft');
        $this->seedPage(self::OTHER_PUBLIC, 'Pricing', 'publish');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    private function seedPage(int $id, string $title, string $status): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => 'page',
            'post_title'  => $title,
            'post_status' => $status,
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_page_template'] = 'composition.php';
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition'] = wp_json_encode([
            ['component' => 'hero', 'props' => ['headline' => $title]],
        ]);
    }

    /**
     * A user who can use the chat (edit_posts) and edit pages, cannot edit others' pages or read
     * private ones, and may edit only the listed pages: page rights on their own pages only.
     */
    private function actAsUserWhoMayEdit(int ...$ids): void
    {
        $GLOBALS['_pp_test_user_caps'] = [
            'edit_posts'         => true,
            'edit_pages'         => true,
            'edit_others_pages'  => false,
            'read_private_pages' => false,
            'edit_post'          => static fn ($id = null): bool => in_array((int) $id, $ids, true),
        ];
    }

    private static function row(int $id, string $title, string $status = 'draft'): array
    {
        return ['id' => $id, 'title' => $title, 'status' => $status, 'url' => "http://example.com/?page_id={$id}"];
    }

    /** The prompt's "## Pages" block, up to (not including) the POSTS PAGE line that follows it. */
    private static function pagesBlock(string $prompt): string
    {
        $start = strpos($prompt, "## Pages\n");
        self::assertNotFalse($start, 'the prompt has no "## Pages" block');
        $end = strpos($prompt, "\nPOSTS PAGE:", $start);
        self::assertNotFalse($end, 'the "## Pages" block is not followed by the POSTS PAGE line');
        return substr($prompt, $start, $end - $start);
    }

    // ── The filter ────────────────────────────────────────────────────────

    public function testEditablePagesKeepsOnlyThePagesTheUserMayEdit(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE, 60);
        $pages = [
            self::row(self::OTHER_DRAFT, 'Unannounced launch'),
            self::row(self::OWN_PAGE, 'Team page'),
            self::row(self::OTHER_PUBLIC, 'Pricing', 'publish'),
            self::row(60, 'Own private notes', 'private'),
        ];

        $this->assertSame(
            [self::row(self::OWN_PAGE, 'Team page'), self::row(60, 'Own private notes', 'private')],
            pp_ai_editable_pages($pages)
        );
    }

    public function testEditablePagesKeepsEveryPageForAnAdministrator(): void
    {
        // No cap map: the bootstrap's all-capabilities user.
        $pages = [self::row(self::OTHER_DRAFT, 'Unannounced launch'), self::row(self::OWN_PAGE, 'Team page')];

        $this->assertSame($pages, pp_ai_editable_pages($pages));
    }

    public function testEditablePagesDropsARowWithoutAPositiveId(): void
    {
        // The per-page check answers "permitted" for no page at all; a list row is never that.
        $pages = [['id' => 0, 'title' => 'Zero'], ['title' => 'No id'], self::row(self::OWN_PAGE, 'Team page')];

        $this->assertSame([self::row(self::OWN_PAGE, 'Team page')], pp_ai_editable_pages($pages));
    }

    public function testEditablePagesOfAnEmptyListIsEmpty(): void
    {
        $this->assertSame([], pp_ai_editable_pages([]));
    }

    // ── The prompt ────────────────────────────────────────────────────────

    // Every pin in this section reads pp_composition_pages(), so each runs in its own process
    // (see the file docblock): the memo starts empty and fills from this file's seeded store.

    private function emptyStore(): void
    {
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptListsOnlyThePagesTheUserMayEdit(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $block = self::pagesBlock(pp_ai_system_prompt());

        $this->assertStringContainsString('- Team page (ID: ' . self::OWN_PAGE . ', status: draft,', $block);
        $this->assertStringNotContainsString('Unannounced launch', $block);
        $this->assertStringNotContainsString('(ID: ' . self::OTHER_DRAFT . ',', $block);
        $this->assertStringNotContainsString('(ID: ' . self::OTHER_PUBLIC . ',', $block);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptListsEveryPageForAnAdministrator(): void
    {
        // No cap map: the bootstrap's all-capabilities user. Byte-equal to the unfiltered list.
        $block = self::pagesBlock(pp_ai_system_prompt());

        $lines = array_map('pp_ai_page_inventory_line', pp_composition_pages());
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith("## Pages\n" . implode("\n", $lines) . "\n", $block);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptWithNoEditablePagesSaysNoneYouCanEdit(): void
    {
        $this->actAsUserWhoMayEdit();

        $this->assertSame("## Pages\nNone you can edit.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptOnASiteWithNoPagesSaysNoneYouCanEditToAUserWithOwnPageRightsOnly(): void
    {
        // The sentence does not depend on what the site holds for this user, so it cannot
        // tell them whether pages they cannot see exist.
        $this->emptyStore();
        $this->actAsUserWhoMayEdit();

        $this->assertSame("## Pages\nNone you can edit.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptOnASiteWithNoPagesSaysNoneExistToAUserWhoSeesEveryPage(): void
    {
        // edit_pages + read_private_pages: core's Pages screen lists every page title to them.
        $this->emptyStore();
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_pages' => true, 'read_private_pages' => true];

        $this->assertSame("## Pages\nNo pages exist yet.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptNeverSaysNoneExistWhilePagesExist(): void
    {
        // A user who sees every page title but may edit none of these pages (a custom role, or
        // a per-page restriction) gets an empty list on a site that has pages. It is told the
        // list is empty for it, not that the site has no pages.
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_pages' => true, 'read_private_pages' => true, 'edit_post' => false];

        $this->assertSame("## Pages\nNone you can edit.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptOnASiteWithNoPagesSaysNoneYouCanEditToAUserWithoutThePagesScreen(): void
    {
        // read_private_pages without edit_pages (a custom role): no Pages screen, so the empty
        // site is not theirs to learn about either.
        $this->emptyStore();
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_pages' => false, 'read_private_pages' => true];

        $this->assertSame("## Pages\nNone you can edit.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptOnASiteWithNoPagesSaysNoneYouCanEditToAUserWhoCannotSeePrivatePages(): void
    {
        // Without read_private_pages the user cannot see every page title anywhere, so the
        // empty site is not theirs to learn about: same sentence as a site with hidden pages.
        $this->emptyStore();
        // edit_others_pages is granted to show it plays no part in the sentence.
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_pages' => true, 'edit_others_pages' => true, 'read_private_pages' => false];

        $this->assertSame("## Pages\nNone you can edit.", self::pagesBlock(pp_ai_system_prompt()));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTheSiteContextListsOnlyThePagesTheUserMayEdit(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $this->assertSame([self::OWN_PAGE], array_column(pp_ai_site_context()['pages'], 'id'));
    }

    /**
     * The budget pin (AiContextTest) measures an administrator on an empty site. A user with a
     * filtered list gets a page block no longer than the administrator's on the same site, so
     * both shapes fit, and the empty-list line is no longer than the one the pin measures.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testThePromptFitsItsByteBudgetForAUserWithAFilteredListOnAnEmptySite(): void
    {
        $this->emptyStore();

        $admin = strlen(pp_ai_system_prompt());
        $this->actAsUserWhoMayEdit();
        $restricted = strlen(pp_ai_system_prompt());

        $this->assertLessThanOrEqual(PP_AI_PROMPT_BUDGET, $admin, 'premise: the measured shape fits');
        $this->assertLessThanOrEqual(PP_AI_PROMPT_BUDGET, $restricted);
        $this->assertLessThanOrEqual($admin, $restricted);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAFilteredPromptIsNeverLongerThanTheAdministratorsOnTheSameSite(): void
    {
        $admin = strlen(pp_ai_system_prompt());
        foreach ([[self::OWN_PAGE], []] as $grants) {
            $this->actAsUserWhoMayEdit(...$grants);
            $this->assertLessThan($admin, strlen(pp_ai_system_prompt()), 'grants: ' . json_encode($grants));
        }
    }

    // ── The dropdown ──────────────────────────────────────────────────────

    public function testTheDropdownListsThePagesItIsGivenEscaped(): void
    {
        $html = pp_ai_chat_page_select_options([
            self::row(self::OWN_PAGE, 'Team "A" <b>&</b>'),
            self::row(61, ''),
        ]);

        $this->assertSame(
            '<option value="">— Select a page —</option>'
            . '<option value="50">Team &quot;A&quot; &lt;b&gt;&amp;&lt;/b&gt;</option>'
            . '<option value="61">(untitled)</option>',
            $html
        );
    }

    public function testTheDropdownWithNoPagesShowsNoPagesFound(): void
    {
        $this->assertSame('<option value="">No pages found.</option>', pp_ai_chat_page_select_options([]));

        // Core's own words and translation: the call carries no text domain. The bootstrap's
        // translation stub ignores the domain, so the statement is pinned instead.
        $code = self::codeOnly(file_get_contents(dirname(__DIR__) . '/lib/ai-chat.php'));
        $this->assertSame(1, preg_match_all("/esc_html__\\('No pages found\\.'\\)/", $code), 'core text domain');
    }

    /**
     * Both dropdown copies are built from the filter. Matched as whole statements, never bare
     * names, so a comment that mentions a function cannot satisfy the pin.
     */
    public function testBothDropdownCopiesAreBuiltFromThePagesTheUserMayEdit(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/lib/ai-chat.php');

        $this->assertSame(1, preg_match_all('/^\s*\$pages = pp_ai_editable_pages\(pp_composition_pages\(\)\);$/m', $src), 'the script copy (ppAiChat.pages)');
        $this->assertSame(1, preg_match_all('/^\s*\'pages\'\s*=> \$pages,$/m', $src), 'ppAiChat.pages is that list');
        $this->assertSame(1, preg_match_all('/^\s*<\?php \$pp_ai_chat_pages = pp_ai_editable_pages\(pp_composition_pages\(\)\); \?>$/m', $src), 'the rendered <select>');
        $this->assertSame(1, preg_match_all('/<\?php echo pp_ai_chat_page_select_options\(\$pp_ai_chat_pages\);/', $src), 'the <select> options come from that list');
        $code = self::codeOnly($src);
        $this->assertSame(
            preg_match_all('/pp_ai_editable_pages\(pp_composition_pages\(\)\)/', $code),
            preg_match_all('/pp_composition_pages\(/', $code),
            'every read of the page list in the chat page goes through the filter'
        );
    }

    /**
     * The prompt and the site context read the list through the filter. The behavioural pins
     * above prove it; this statement pin says where, so a second, unfiltered read added next to
     * the filtered one is caught even if it feeds something the behavioural pins do not print.
     */
    public function testThePromptAndTheSiteContextReadThePagesTheUserMayEdit(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/lib/ai-context.php');

        $this->assertSame(1, preg_match_all('/^    \$pages = pp_ai_editable_pages\(pp_composition_pages\(\)\);$/m', $src), 'the prompt inventory');
        $this->assertSame(1, preg_match_all('/^\s*\'pages\'\s*=> pp_ai_editable_pages\(pp_composition_pages\(\)\),$/m', $src), 'pp_ai_site_context()');
        // The one unfiltered read allowed is the emptiness check behind "No pages exist yet.",
        // which prints nothing from the list.
        $code = self::codeOnly($src);
        $this->assertSame(1, preg_match_all('/pp_composition_pages\(\) === \[\]/', $code), 'the emptiness check');
        $this->assertSame(
            preg_match_all('/pp_ai_editable_pages\(pp_composition_pages\(\)\)/', $code) + 1,
            preg_match_all('/pp_composition_pages\(/', $code),
            'every other read of the page list in the AI context goes through the filter'
        );
    }

    /** PHP source with comments removed, so prose that names a function cannot satisfy a pin. */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    // ── The baseline read ─────────────────────────────────────────────────

    public function testTheBaselineReadAnswersTheSameForAMissingPageAsForAForbiddenOne(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $forbidden = _pp_ai_page_baseline_response(['post_id' => (string) self::OTHER_DRAFT]);
        $missing   = _pp_ai_page_baseline_response(['post_id' => (string) self::MISSING]);

        $this->assertSame(['ok' => false, 'data' => 'Permission denied.'], $forbidden);
        $this->assertSame($forbidden, $missing);
    }

    public function testTheBaselineReadAnswersTheSameForAMissingPageForAnAdministrator(): void
    {
        $this->assertSame(
            ['ok' => false, 'data' => 'Permission denied.'],
            _pp_ai_page_baseline_response(['post_id' => self::MISSING])
        );
    }

    public function testTheBaselineReadStillRejectsAMalformedId(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        foreach (['0', '-3', 'abc', ''] as $bad) {
            $this->assertSame(['ok' => false, 'data' => 'Invalid page.'], _pp_ai_page_baseline_response(['post_id' => $bad]), "post_id '{$bad}'");
        }
        $this->assertSame(['ok' => false, 'data' => 'Invalid page.'], _pp_ai_page_baseline_response([]));
    }

    public function testTheBaselineReadAnswersForAPageTheUserMayEdit(): void
    {
        $this->actAsUserWhoMayEdit(self::OWN_PAGE);

        $resp = _pp_ai_page_baseline_response(['post_id' => (string) self::OWN_PAGE]);

        $this->assertTrue($resp['ok']);
        $this->assertSame(self::OWN_PAGE, $resp['data']['post_id']);
    }
}
