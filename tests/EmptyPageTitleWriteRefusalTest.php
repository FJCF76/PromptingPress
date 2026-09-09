<?php
/**
 * tests/EmptyPageTitleWriteRefusalTest.php
 *
 * update_page_title refuses a BLANK title instead of blanking the page (#888).
 *
 * THE DEFECT, found in the v1.19.0 release smoke (2026-08-31) on a real page. The action
 * declares `'title' => ['type' => 'string', 'required' => true]`, and
 * pp_validate_action()'s required check is `array_key_exists()` — PRESENCE, not content.
 * `''` is present. So the whole chain ran and reported success:
 *
 *     write                                          verdict   stored post_title
 *     ─────────────────────────────────────────────  ────────  ─────────────────
 *     update_page_title(N, '')                       ok:TRUE   '' — page blanked
 *     update_page_title(N, '   ')                    ok:TRUE   '   ' — reads blank
 *     update_page_title(N, 'Real title')             ok:true   'Real title'
 *
 * That is the reported-success class one step worse than usual: the effect was not
 * missing, it was DESTRUCTIVE, and the envelope said the write worked.
 *
 * THE RULING (T4, recorded in #888's body; D-A posture, canonical text in #724's body),
 * and the orchestrator's Option B on the width question. The refusal uses THE SAME
 * PREDICATE AND THE SAME ERROR CODE create_page already applies to the same field in the
 * same file — `trim($title) === ''` -> `WP_Error('empty_title', 'Page title cannot be
 * empty.')`. Refusing only the literal `''` would have left this the one rule of three
 * (create_page, update_page_slug, update_page_title) that still accepts `"   "`, which
 * reproduces the exact blank-looking page the issue was filed for; and a fresh
 * `invalid_title` code would have been a third vocabulary for one fact. The file
 * disagreeing with itself was the defect.
 *
 * REJECT, NEVER COERCE. `trim()` decides the VERDICT and never the stored value: a title
 * carrying meaningful leading or trailing space is stored exactly as sent (§2).
 *
 * WHAT THIS FILE PINS. Each entry names the section that holds it:
 *
 *   §1 THE REFUSAL, through the real action (Section 14.1) — both blank shapes, the
 *      envelope, and that nothing was written.
 *   §2 THE ACCEPT SIDE — every ordinary title still lands, and whitespace INSIDE a real
 *      title survives verbatim. Paired with §1 so a fixture failing for an unrelated
 *      reason cannot read as a pass.
 *   §3 THE RESTORE PATH IS UNTOUCHED — the reason the gate sits at the action layer and
 *      not in pp_update_page_title(). The most important section in the file.
 *   §4 THE #121 OUTCOME — a blank-title call still promotes no auto-draft.
 *   §5 THE CONSISTENCY TRIPWIRE — create_page and update_page_title agree, input for
 *      input, so the drift this issue closed cannot silently reopen.
 */

use PHPUnit\Framework\TestCase;

class EmptyPageTitleWriteRefusalTest extends TestCase
{
    /** The shapes ruling T4 refuses: empty, and whitespace-only in several flavours. */
    private const BLANK_TITLES = ['', ' ', '   ', "\t", "\n", " \t\n ", '  ' . "\r\n"];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    // ── §1. The refusal, through the real action ────────────────────────────

    /**
     * THE SMOKE'S REPRO, through the action it was measured on.
     *
     * Section 14.1 requires the authoring contract to be exercised through the real
     * surface rather than a direct pp_update_page_title() call: the defect was that the
     * ACTION's validate step let the value through, so a test that called the writer
     * would have proved nothing about the rule.
     */
    public function testEveryBlankTitleIsRefusedAndThePageKeepsItsTitle(): void
    {
        foreach (self::BLANK_TITLES as $blank) {
            $post_id = pp_create_page('Original title', 'publish');

            $result = pp_execute_action('update_page_title', [
                'post_id' => $post_id,
                'title'   => $blank,
            ]);

            $label = var_export($blank, true);
            $this->assertFalse($result['ok'], "a blank title {$label} must not return ok:true");
            $this->assertSame('empty_title', $result['error_code'],
                'and it uses the code create_page already uses for this fact — never a second spelling');
            $this->assertSame('Page title cannot be empty.', $result['error']);
            $this->assertSame('Original title', get_the_title($post_id),
                "a refused write leaves the stored title alone — {$label} must not blank the page");
        }
    }

    /**
     * NOTHING IS WRITTEN AT ALL on a refusal — asserted on the stored row rather than
     * only through get_the_title(), so a future change that "helpfully" wrote a
     * placeholder would be caught.
     */
    public function testARefusedTitleWriteTouchesNothing(): void
    {
        $post_id = pp_create_page('Original title', 'publish');
        $before  = $GLOBALS['_pp_test_store']['posts'][$post_id];

        $result = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => '   ']);

        $this->assertFalse($result['ok']);
        $this->assertSame($before, $GLOBALS['_pp_test_store']['posts'][$post_id],
            'the whole post row is untouched — no title, no status, no slug side effect');
    }

    /**
     * The refusal is a VALIDATION refusal, so `preview` refuses it too rather than
     * showing an operator a diff for a write that cannot land.
     *
     * pp_preview_action() returns the validator's WP_Error straight through rather than
     * an envelope, so this asserts that shape — the point is that both surfaces route
     * through pp_validate_action() and neither can offer what the other would refuse.
     */
    public function testPreviewRefusesABlankTitleToo(): void
    {
        $post_id = pp_create_page('Original title', 'publish');

        $result = pp_preview_action('update_page_title', ['post_id' => $post_id, 'title' => '']);

        $this->assertInstanceOf(WP_Error::class, $result,
            'a preview must not offer a diff for a write validation will refuse');
        $this->assertSame('empty_title', $result->get_error_code());
        $this->assertSame('Original title', get_the_title($post_id), 'and a preview never writes');
    }

    // ── §2. The accept side ─────────────────────────────────────────────────

    /**
     * Ordinary titles still land, and WHITESPACE INSIDE A REAL TITLE SURVIVES VERBATIM.
     *
     * This is the reject-never-coerce half, and it is the assertion that separates this
     * rule from a trimming rule: `trim()` decides the verdict and is never applied to the
     * stored value. A change that started storing the trimmed string would keep §1 green
     * and break this.
     */
    public function testRealTitlesAreStoredExactlyAsSent(): void
    {
        foreach ([
            'A real title',
            '  Leading space is meaningful  ',
            "Title\twith a tab",
            '0',
            'é — unicode and punctuation',
        ] as $title) {
            $post_id = pp_create_page('Original title', 'publish');

            $result = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => $title]);

            $this->assertTrue($result['ok'], $result['error'] ?? 'a non-blank title must still be accepted');
            $this->assertSame($title, get_the_title($post_id),
                'stored byte-for-byte — trim() decides the verdict, never the value');
        }
    }

    /**
     * `'0'` deserves its own sentence, because it is the classic falsy-string trap: a
     * rule written as `empty($title)` or `!$title` would refuse it. The predicate is a
     * `=== ''` comparison against the trimmed value precisely so a page titled "0" is a
     * page titled "0".
     */
    public function testAZeroTitleIsNotBlank(): void
    {
        $this->assertNotSame('', trim('0'), 'the premise');

        $post_id = pp_create_page('Original title', 'publish');
        $result  = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => '0']);

        $this->assertTrue($result['ok'], $result['error'] ?? '"0" is a title, not a blank');
        $this->assertSame('0', get_the_title($post_id));
    }

    /**
     * UNICODE BLANKS ARE ACCEPTED, and this pins the limit rather than the intent.
     *
     * `trim()` strips ASCII whitespace only — space, tab, newline, CR, NUL, vertical tab.
     * It does not know about U+00A0 (no-break space), U+3000 (ideographic space), U+200B
     * (zero width space) or U+FEFF, so a title made only of those is ACCEPTED and stores a
     * title that renders visually blank: the same outcome #888 was filed about, one
     * character class over.
     *
     * IT IS PINNED AS ACCEPTED, not fixed, for the reason the width of this rule was
     * settled on in the first place: the predicate is create_page's, byte for byte, and
     * agreement between the two actions is the property that matters most here (§5).
     * Widening both to Unicode blanks is a different ruling on a shape nothing has
     * measured. Asserted on BOTH actions so the tripwire below cannot pass while these
     * two quietly diverge on the same inputs.
     */
    public function testUnicodeBlanksAreAcceptedBecauseTrimIsAsciiOnly(): void
    {
        foreach (["\u{00A0}", "\u{2007}", "\u{3000}", "\u{200B}", "\u{FEFF}"] as $blank) {
            $label = 'U+' . strtoupper(bin2hex(mb_convert_encoding($blank, 'UTF-16BE', 'UTF-8')));

            $post_id = pp_create_page('Original title', 'publish');
            $updated = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => $blank]);
            $this->assertTrue($updated['ok'],
                "{$label} is not ASCII whitespace, so trim() does not see it and the title is accepted");
            $this->assertSame($blank, get_the_title($post_id), 'and it is stored byte-for-byte');

            $created = pp_execute_action('create_page', ['title' => $blank]);
            $this->assertSame($updated['ok'], $created['ok'],
                "and create_page must answer {$label} the same way — the two share one predicate");
        }

        // The ASCII controls trim() DOES strip, asserted beside them so the boundary is
        // visible rather than implied.
        foreach (["\0", "\x0B"] as $ascii_blank) {
            $post_id = pp_create_page('Original title', 'publish');
            $this->assertFalse(
                pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => $ascii_blank])['ok'],
                'trim() strips NUL and vertical tab, so a title of only those IS blank'
            );
        }
    }

    // ── §3. The restore path is untouched ───────────────────────────────────

    /**
     * THE BATCH ROLLBACK STILL RESTORES AN EMPTY TITLE, and this is why the gate sits in
     * the action's validate closure rather than in pp_update_page_title().
     *
     * Since #857 that helper is ALSO the rollback's title-restore writer, called
     * DIRECTLY from the rollback loop — not through pp_execute_action(), so not through
     * the validate closure. A post may legitimately hold an empty title, its snapshot
     * then captures '', and a refusal inside the helper would turn "this had no title
     * and still doesn't" into a reported rollback FAILURE on every such post. That is
     * exactly the trap update_page_slug works around with its compare-first guard; the
     * title needs no guard because the refusal is one layer up.
     *
     * Asserted at the WRITER, which is the seam that would actually break: if a later
     * change moves the rule into pp_update_page_title(), this fails and names why.
     */
    public function testTheRestoreWriterStillAcceptsAnEmptyTitle(): void
    {
        $post_id = pp_create_page('Had a title', 'publish');

        $restored = pp_update_page_title($post_id, '');

        $this->assertNotInstanceOf(WP_Error::class, $restored,
            'the rollback writes a captured title back through this helper directly (#857) — a snapshot'
            . ' that captured an empty title must restore, or undo fails exactly when it is needed (#233)');
        $this->assertTrue($restored);
        $this->assertSame('', get_the_title($post_id), 'and the empty title is actually restored');
    }

    /**
     * The forward path and the restore path disagree ON PURPOSE, asserted as a pair so
     * the asymmetry reads as a decision rather than an oversight.
     */
    public function testTheActionRefusesExactlyWhatTheWriterAccepts(): void
    {
        $post_id = pp_create_page('Had a title', 'publish');

        $this->assertFalse(
            pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => ''])['ok'],
            'the AUTHORING surface refuses a blank title'
        );
        $this->assertSame('Had a title', get_the_title($post_id));

        $this->assertTrue(
            pp_update_page_title($post_id, ''),
            'while the RESTORE writer underneath it still accepts one — two layers, two contracts'
        );
    }

    /**
     * THE ROLLBACK ITSELF RESTORES AN EMPTY TITLE, driven through
     * _pp_restore_batch_snapshot() rather than through the writer it calls.
     *
     * The two tests above pin the WRITER's contract, which is the seam a change to
     * pp_update_page_title() would break. This pins the CALLER, which is the seam a change
     * to the rollback loop would break — a future guard added there rather than inside the
     * helper would leave both of those green while turning "this page had no title and
     * still doesn't" into a reported FAILED entry on every such page. #233's contract is
     * about what undo does, so it is worth one test that actually runs undo.
     *
     * The assertion is on the ERROR CHANNEL as much as on the title: a rollback that
     * restored the empty title AND reported a failure would still be a regression, because
     * the report is what an operator acts on.
     */
    public function testTheBatchRollbackRestoresAnEmptyTitleWithoutReportingAFailure(): void
    {
        $post_id = pp_create_page('', 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Original']]]);

        // What the snapshotter captures for a page that legitimately holds no title.
        $snapshot = [
            'posts' => [$post_id => [
                'title'       => '',
                'slug'        => get_post($post_id)->post_name,
                'status'      => 'draft',
                'composition' => [['component' => 'hero', 'props' => ['title' => 'Original']]],
                'seo_meta'    => pp_get_seo_meta($post_id),
            ]],
            'created_posts'   => [],
            'unreadable'      => [],
            'site_options'    => [],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        // The batch retitles the page, then something fails and the rollback runs.
        pp_update_page_title($post_id, 'Title the batch set');
        $this->assertSame('Title the batch set', get_the_title($post_id));

        $errors = _pp_restore_batch_snapshot($snapshot);

        // ASSERTED ON THE TITLE CHANNEL, not on `$errors === []`, and the difference is a
        // property of the harness rather than of the change. Under the PHPUnit stubs there
        // is no authoritative database read, so the rollback withholds the COMPOSITION and
        // says so — a real entry about a real (stubbed) condition, unrelated to the title,
        // and its own message ends "Every other field on this page was rolled back." What
        // this test owns is that the title is not one of the failures, so it keys on the
        // exact guidance string a failed title revert emits.
        $title_failures = array_filter(
            $errors,
            static fn (string $e): bool => str_contains($e, 'Set the previous title back by hand.')
        );
        $this->assertSame([], $title_failures,
            'a page whose snapshot captured an empty title must roll its title back CLEANLY —'
            . ' the forward-path refusal must never reach undo (#233). If this fails, the rule'
            . ' has moved into pp_update_page_title() or into the rollback loop.');
        $this->assertSame('', get_the_title($post_id), 'and the empty title is actually restored');
    }

    // ── §4. The #121 outcome ────────────────────────────────────────────────

    /**
     * A blank-title save still promotes no auto-draft (#121), now because the write is
     * refused rather than because pp_execute_action()'s $is_noop_title_save carve-out
     * skips the promotion.
     *
     * THE CARVE-OUT ITSELF IS NOT PINNABLE and this test does not pretend otherwise:
     * the refusal returns first, so deleting that branch leaves the suite green. What is
     * pinned here is the OUTCOME both mechanisms agree on, which is the thing #121
     * actually promised. The branch is retained as a fail-safe for a future relaxation of
     * the title rule; that trade-off is recorded at the branch.
     */
    public function testABlankTitleSavePromotesNoAutoDraft(): void
    {
        foreach (['', '   '] as $blank) {
            $post_id = pp_create_page('', 'auto-draft');

            $result = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => $blank]);

            $this->assertFalse($result['ok'], 'the blank save is refused');
            $this->assertSame('auto-draft', $GLOBALS['_pp_test_store']['posts'][$post_id]['post_status'],
                'and the auto-draft is not promoted — the "(no title)" permanent-draft bug stays closed');
        }
    }

    /** The counterpart: a real title still promotes, which is the #121 feature itself. */
    public function testARealTitleStillPromotesAnAutoDraft(): void
    {
        $post_id = pp_create_page('', 'auto-draft');

        $result = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => 'A real title']);

        $this->assertTrue($result['ok'], $result['error'] ?? 'a real title must still promote');
        $this->assertSame('draft', $GLOBALS['_pp_test_store']['posts'][$post_id]['post_status']);
    }

    // ── §5. The consistency tripwire ────────────────────────────────────────

    /**
     * create_page AND update_page_title ANSWER THE SAME WAY, INPUT FOR INPUT.
     *
     * The whole argument for the width of this rule was that the file already contained
     * the answer: create_page has judged the same field with `trim($title) === ''` ->
     * `empty_title` since it shipped, and #888 was update_page_title disagreeing with it.
     * Consistency that is argued in a comment and not asserted is consistency that drifts
     * back the first time one of the two is edited — which is the whole history of this
     * defect. This walks both actions over one input list and requires identical verdicts
     * and identical error codes.
     *
     * It deliberately does NOT compare error MESSAGES: create_page names "Page title",
     * update_page_title may one day name the page it is retitling, and locking two
     * sentences together would make an improvement to one a failure of the other. The
     * contract is the verdict and the code.
     */
    public function testCreatePageAndUpdatePageTitleAgreeOnEveryTitleInput(): void
    {
        $inputs = array_merge(self::BLANK_TITLES, ['A real title', '0', '  padded  ', 'é']);

        foreach ($inputs as $title) {
            $label = var_export($title, true);

            $created = pp_execute_action('create_page', ['title' => $title]);

            $post_id = pp_create_page('Original title', 'publish');
            $updated = pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => $title]);

            $this->assertSame(
                $created['ok'],
                $updated['ok'],
                "create_page and update_page_title must agree on {$label} — #888 was exactly this drift"
            );
            $this->assertSame(
                $created['error_code'] ?? null,
                $updated['error_code'] ?? null,
                "and they must refuse {$label} with the SAME code, never two spellings for one fact"
            );
        }
    }

    /**
     * THE RULE AND ITS #121 FAIL-SAFE SHARE ONE PREDICATE, asserted directly.
     *
     * pp_execute_action()'s auto-draft promotion carries a fail-safe that must skip
     * promotion for exactly the set this rule refuses. No test can enter that branch (the
     * refusal returns first), so the only way to hold the two together is to give them one
     * function and test the function. Before #888 the branch tested the literal `=== ''`
     * while the rule tested `trim(...) === ''` — a fail-safe guarding a narrower class than
     * the rule it backs up, which would have let `"   "` promote a draft the moment the
     * rule was relaxed.
     */
    public function testTheBlanknessPredicateIsSharedAndCoversEveryBlankShape(): void
    {
        foreach (self::BLANK_TITLES as $blank) {
            $this->assertTrue(_pp_title_is_blank($blank),
                sprintf('%s is blank', var_export($blank, true)));
        }
        foreach (['A real title', '0', '  padded  ', 'é', "\u{00A0}"] as $real) {
            $this->assertFalse(_pp_title_is_blank($real),
                sprintf('%s is a title', var_export($real, true)));
        }
        // Non-strings are not this predicate's business — that scope boundary is what
        // leaves null to #931 instead of letting trim()'s deprecated coercion decide it.
        foreach ([null, 42, true, false, []] as $non_string) {
            $this->assertFalse(_pp_title_is_blank($non_string),
                sprintf('%s is not a blank STRING', var_export($non_string, true)));
        }
    }

    /**
     * THE ONE INPUT THEY DIVERGE ON, asserted so the divergence is a recorded decision
     * rather than a gap the walk above happens not to cover.
     *
     * `null` is exempted from pp_validate_action()'s type check
     * (`$params[$param_name] !== null`), so it reaches each validate closure raw.
     * create_page calls `trim($params['title'])` bare, which returns '' while raising
     * E_DEPRECATED, so it answers `empty_title`. update_page_title guards with
     * `is_string()` and lets null fall through to the typed writer, where it throws —
     * which is #931, filed separately because the cause is the registry-wide null gate,
     * not the title rule.
     *
     * Closing null HERE was considered and rejected: a local guard on one of many
     * affected actions would make #931 look closed while every other typed executor
     * stayed exposed, and it would do so by depending on a deprecated coercion. This test
     * exists so that when #931 is fixed at the gate, the fix has an assertion telling it
     * this line is one of the places to revisit.
     */
    public function testNullIsDeliberatelyLeftToIssue931(): void
    {
        $post_id = pp_create_page('Original title', 'publish');

        $threw = false;
        try {
            pp_execute_action('update_page_title', ['post_id' => $post_id, 'title' => null]);
        } catch (TypeError $e) {
            $threw = true;
            $this->assertStringContainsString('must be of type string, null given', $e->getMessage());
        }

        $this->assertTrue($threw,
            'null still reaches the typed writer and throws (#931). If this now returns a WP_Error,'
            . ' #931 was fixed — re-read the is_string() guard in update_page_title\'s validate closure'
            . ' and the divergence note in §5, which may no longer be needed.');
        $this->assertSame('Original title', get_the_title($post_id),
            'and nothing was written on the way to the throw');
    }
}
