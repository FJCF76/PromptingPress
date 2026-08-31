<?php
/**
 * tests/BatchRollbackCreatedArtifactsTest.php — a rolled-back batch no longer leaves a live
 * redirect or an imported attachment behind an empty report (#854).
 *
 * THE BUG THIS PINS. `rollback_errors` reports what the ROLLBACK could not restore, which is
 * bounded by what the SNAPSHOT covered — and two step classes wrote site state the snapshot
 * never looked at. `create_redirect` / `remove_redirect` write the `pp_redirects` option
 * through `update_option()` (lib/wp.php), and that option is not in
 * `pp_allowed_site_options()`, so it could not arrive through the `update_site_option`
 * capture either. `import_media`'s attachment was excluded deliberately, documented as
 * "additive and non-destructive". So a batch of `[create_redirect, update_component]` whose
 * second step lost a compare-and-swap returned:
 *
 *     ok: false   failed_at: 1   error_code: composition_conflict
 *     rolled_back: true
 *     rollback_errors: []      ← nothing to report, because nothing was ever covered
 *
 * and the chat's conflict card read that as an explicitly clean report and told the operator
 * "Nothing was applied." while a site-wide 301 was live. No malformed payload required;
 * stock server output. Since #797 both failure exits present that sentence as EVIDENCE-GATED
 * — derived from a channel that was checked — which is what made an uncovered channel worse
 * than the flat claim it replaced: an unverified statement that reads as verified.
 *
 * THE FIX (ruling T1). A rollback MAY delete artifacts the SAME batch created, and never
 * anything that pre-existed it. Where deletion is unsafe or fails, the entry is refused and
 * the survivor is NAMED in `rollback_errors`, which the #797-landed client adapter already
 * renders. "Clean" must mean clean.
 *
 *              ┌─ pre-batch state exists ─▶ SNAPSHOT it ─▶ write it back
 *   an artifact┤
 *              └─ no pre-batch state ─────▶ TRACK the creation ─▶ delete it
 *                                                              └─ can't? NAME it
 *
 * WHAT IS PINNED HERE, IN THREE KINDS.
 *
 *   RED PROOF — fails against the pre-fix source. The end-to-end repro, the redirect
 *   deletion, the attachment deletion, and the honest-empty-report assertions.
 *
 *   THE BOUNDARY — passes before AND after, and it is the half that matters most, because
 *   the cheapest way to make the red proofs green is to delete too much. A redirect that
 *   pre-existed the batch is RESTORED, never deleted. A redirect the batch never named is
 *   untouched, so a concurrent admin's row survives a rollback (the defect TODOS.md already
 *   records against `_pp_restore_menu_state()`, not repeated here). An `import_media` that
 *   DEDUPED to an existing attachment never records it, so a months-old asset is not
 *   destroyed by a batch that merely referenced it.
 *
 *   REFUSE-AND-REPORT — the third outcome, and the one that keeps the empty report worth
 *   something. Every branch that declines to delete puts a named survivor on the channel.
 *
 * NO NETWORK, NO REAL UPLOADS. `download_url()` and `media_handle_sideload()` are stubbed in
 * tests/bootstrap.php — the sideload stub registers a real `attachment`-typed post in the
 * test store, which is what makes an end-to-end `import_media` batch drivable here and what
 * `get_post()` reads when the rollback checks the ID before deleting it.
 */

use PHPUnit\Framework\TestCase;

class BatchRollbackCreatedArtifactsTest extends TestCase
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
        // The batch gate reads the postmeta row and fails closed without a handle (#833),
        // so every batch naming a page needs one — production always has it.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_option_writes'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['_pp_test_undeletable_attachments'],
            $GLOBALS['_pp_test_undeletable_posts'],
            $GLOBALS['_pp_test_unwritable_options'],
            $GLOBALS['_pp_test_option_writes']
        );
        parent::tearDown();
    }

    /** A page with a real, readable composition and a known version marker. */
    private function pageWithComposition(string $title = 'Target'): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Before']]]);
        return $id;
    }

    /** A step that always fails, with no page of its own to complicate the rollback. */
    private function failingStep(): array
    {
        return ['type' => 'action', 'name' => 'unknown_action', 'params' => []];
    }

    private function createRedirectStep(string $from, string $to, ?int $code = null): array
    {
        $params = ['from' => $from, 'to' => $to];
        if ($code !== null) {
            $params['code'] = $code;
        }
        return ['type' => 'action', 'name' => 'create_redirect', 'params' => $params];
    }

    private function importMediaStep(string $url): array
    {
        return ['type' => 'apply', 'name' => 'import_media', 'params' => ['url' => $url]];
    }

    // ── the reported repro, end to end ───────────────────────────────────────────

    /**
     * THE ISSUE'S OWN SCENARIO, driven through the real executor with real registered
     * steps: a batch that creates a redirect and imports media, then loses a
     * compare-and-swap on a later step. Before #854 this returned `rollback_errors: []`
     * with a live 301 and a new attachment still on the site.
     */
    public function testConflictFailedBatchWithRedirectAndMediaLeavesNothingBehind(): void
    {
        $page = $this->pageWithComposition();
        $version = pp_get_composition_marker($page)['version'];

        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/old-launch', '/launch'),
            $this->importMediaStep('https://example.com/hero.jpg'),
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $page, 'component_index' => 0, 'props' => ['title' => 'After']]],
        ], [$page => $version - 1]); // stale baseline ⇒ composition_conflict on step 3

        // The failure is the one the issue names, at the step the issue names.
        $this->assertFalse($batch['ok']);
        $this->assertSame(2, $batch['failed_at']);
        $this->assertSame('composition_conflict', $batch['steps'][2]['error_code']);
        $this->assertTrue($batch['rolled_back']);

        // The two artifacts the earlier steps created are gone.
        $this->assertNull(
            pp_resolve_redirect('/old-launch'),
            'a redirect this batch created must not survive its rollback'
        );
        $attachmentId = $batch['steps'][1]['changes'][0]['attachment_id'];
        $this->assertNull(
            get_post($attachmentId),
            'an attachment this batch imported must not survive its rollback'
        );

        // And ONLY now is the empty report honest.
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertTrue(array_is_list($batch['rollback_errors']));
    }

    // ── redirects: created, overwritten, removed, untouched ──────────────────────

    public function testRollbackDeletesARedirectTheBatchCreated(): void
    {
        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/gone', '/here'),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['steps'][0]['ok']);
        $this->assertNull(pp_resolve_redirect('/gone'));
        $this->assertSame([], $batch['rollback_errors']);
    }

    /**
     * THE OVERWRITE CASE, and the one ruling T1 draws its line around. `create_redirect`
     * is create-OR-REPLACE: a second create for the same `from` replaces the stored row.
     * The batch did not create that row, so the rollback must put the PRIOR one back —
     * deleting it would destroy something that pre-existed the batch, which is the exact
     * failure T1 forbids.
     */
    public function testRollbackRestoresARedirectTheBatchOverwroteRatherThanDeletingIt(): void
    {
        pp_create_redirect('/legacy', '/original-target', 301);

        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/legacy', '/new-target', 302),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(
            ['to' => '/original-target', 'code' => 301],
            pp_resolve_redirect('/legacy'),
            'an overwritten redirect must be restored to its prior target AND status code'
        );
        $this->assertSame([], $batch['rollback_errors']);
    }

    public function testRollbackRestoresARedirectTheBatchRemoved(): void
    {
        pp_create_redirect('/kept', '/somewhere', 302);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'remove_redirect', 'params' => ['from' => '/kept']],
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(
            ['to' => '/somewhere', 'code' => 302],
            pp_resolve_redirect('/kept'),
            'a redirect this batch removed must come back'
        );
    }

    /**
     * THE CONCURRENCY BOUNDARY. The restore patches only the keys the batch NAMED, over a
     * fresh read — so a redirect written by another admin during the batch window is not
     * reverted along with it. Reverting the option whole would be shorter and would
     * reintroduce, one artifact class over, the defect TODOS.md records against
     * `_pp_restore_menu_state()`.
     */
    public function testRollbackLeavesRedirectsTheBatchNeverNamedUntouched(): void
    {
        pp_create_redirect('/unrelated', '/elsewhere', 301);

        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/named', '/target'),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertNull(pp_resolve_redirect('/named'), 'the named row is rolled back');
        $this->assertSame(
            ['to' => '/elsewhere', 'code' => 301],
            pp_resolve_redirect('/unrelated'),
            'a row this batch never named must survive its rollback'
        );
    }

    /**
     * Two steps whose `from` values normalize to the SAME stored key share ONE baseline,
     * captured from pre-batch state. Without the shared normalizer the second spelling
     * would key a baseline nothing reads, and the live row would survive.
     */
    public function testTwoSpellingsOfOneSourceShareOneBaselineFromPreBatchState(): void
    {
        pp_create_redirect('/dup', '/first', 301);

        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/dup', '/second', 302),
            $this->createRedirectStep('https://example.com/dup/?x=1', '/third', 301),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(
            ['to' => '/first', 'code' => 301],
            pp_resolve_redirect('/dup'),
            'both spellings address one row, and the rollback returns it to PRE-batch state'
        );
    }

    /**
     * The failing step is ITSELF a redirect step. The rollback runs on the failing step's
     * own turn, so a redirect an EARLIER redirect step created is still undone.
     */
    public function testARedirectStepThatFailsStillRollsBackTheEarlierRedirect(): void
    {
        $batch = pp_ai_execute_batch([
            $this->createRedirectStep('/first-one', '/ok'),
            // Refused by create_redirect's own validator: the site root is not a legal source.
            $this->createRedirectStep('/', '/anywhere'),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(1, $batch['failed_at']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertNull(pp_resolve_redirect('/first-one'));
    }

    /** The counterpart: a batch that SUCCEEDS keeps what it created. */
    public function testASucceedingBatchKeepsTheRedirectItCreated(): void
    {
        $batch = pp_ai_execute_batch([$this->createRedirectStep('/stays', '/target', 302)]);

        $this->assertTrue($batch['ok']);
        $this->assertFalse($batch['rolled_back']);
        $this->assertSame(['to' => '/target', 'code' => 302], pp_resolve_redirect('/stays'));
    }

    // ── attachments: created vs reused ───────────────────────────────────────────

    /**
     * THE DEDUPE BOUNDARY. #298's source-URL dedupe hands back an attachment that was
     * already on the site, reporting `action: 'reused'` and writing nothing. That ID must
     * never be recorded as created — deleting it would destroy an asset the batch did not
     * create and other pages may render. Driven end to end so the discriminator is proved
     * where it is actually read, not only in the helper.
     */
    public function testRollbackKeepsAnAttachmentImportMediaOnlyReused(): void
    {
        $url = 'https://example.com/shared-logo.jpg';
        $first = pp_execute_apply('import_media', ['url' => $url]);
        $this->assertSame('import', $first['changes'][0]['action']);
        $existingId = $first['changes'][0]['attachment_id'];

        $batch = pp_ai_execute_batch([
            $this->importMediaStep($url),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame('reused', $batch['steps'][0]['changes'][0]['action']);
        $this->assertSame($existingId, $batch['steps'][0]['changes'][0]['attachment_id']);
        $this->assertNotNull(
            get_post($existingId),
            'an attachment that merely got REUSED pre-existed this batch and must survive'
        );
        $this->assertSame([], $batch['rollback_errors']);
    }

    /**
     * ORDERING. A later step points a site option at the attachment this batch just
     * imported. The rollback restores the option first and deletes the attachment after,
     * so the site is never left holding an option that names a file that is already gone.
     */
    public function testAnOptionPointedAtTheNewAttachmentIsRestoredBeforeItIsDeleted(): void
    {
        // THE MIDDLE STEP HAS TO ACTUALLY SUCCEED, or this test pins nothing. update_site_option
        // validates pp_logo_id as a real Media Library IMAGE, so both halves are arranged:
        // the ID is the one the sideload stub is about to mint (setUp resets next_id to 100),
        // and it is registered as an image so the validator accepts it. Both are asserted
        // below — if ID allocation or the validator moves, this fails loudly instead of
        // quietly passing on a batch that died at step 2.
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '7';
        $GLOBALS['_pp_test_store']['attachment_is_image'][100] = true;

        $batch = pp_ai_execute_batch([
            $this->importMediaStep('https://example.com/brand.png'),
            ['type' => 'action', 'name' => 'update_site_option', 'params' => [
                'key' => 'pp_logo_id', 'value' => '100']],
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $newId = $batch['steps'][0]['changes'][0]['attachment_id'];
        $this->assertSame(100, $newId, 'the imported ID must be the one the option was pointed at');
        // THE PREMISE, ASSERTED: the batch reached the failing step, so the option really
        // was written to the new attachment and really did have to be rolled back.
        $this->assertSame(2, $batch['failed_at'], 'the batch must fail at the LAST step');
        $this->assertTrue($batch['steps'][1]['ok'], 'the option write must have succeeded');

        $this->assertNull(get_post($newId), 'the imported attachment is deleted');
        $this->assertSame(
            '7',
            get_option('pp_logo_id'),
            'and the option is back on its pre-batch value, so nothing dangles'
        );
        $this->assertSame([], $batch['rollback_errors']);
    }

    // ── refuse and report ────────────────────────────────────────────────────────

    /**
     * A delete that does not happen becomes a NAMED survivor rather than silence. This is
     * the branch that keeps the empty report meaningful: the report is only trustworthy
     * because the alternative to deleting is reporting, never shrugging.
     */
    public function testAnUndeletableImportedAttachmentIsNamedInTheReport(): void
    {
        // The sideload stub mints IDs from the store's `next_id`, which setUp() resets to
        // 100, so the attachment this batch is about to import is ID 100 — refusing THAT
        // delete up front is how the branch is reached end to end. Asserted below, so a
        // change in ID allocation fails loudly instead of quietly testing nothing.
        $GLOBALS['_pp_test_undeletable_attachments'][100] = true;

        $batch = pp_ai_execute_batch([
            $this->importMediaStep('https://example.com/stuck.jpg'),
            $this->failingStep(),
        ]);

        $survivorId = $batch['steps'][0]['changes'][0]['attachment_id'];
        $this->assertSame(100, $survivorId, 'the refused ID and the imported ID must be the same');
        $this->assertTrue($batch['rolled_back']);
        $this->assertNotNull(get_post($survivorId), 'the delete really was refused');

        $this->assertCount(1, $batch['rollback_errors']);
        $this->assertStringContainsString((string) $survivorId, $batch['rollback_errors'][0]);
        $this->assertStringContainsString('Media Library', $batch['rollback_errors'][0]);
        $this->assertTrue(array_is_list($batch['rollback_errors']));
    }

    /**
     * An ID that no longer addresses an attachment is REFUSED, not force-deleted. Driven
     * at the restorer with a hand-built bundle because the shipped import cannot produce
     * this state (WordPress does not reissue post IDs) — which is exactly why the guard
     * has to be asserted rather than assumed.
     */
    public function testAnIdThatIsNoLongerAnAttachmentIsRefusedAndReported(): void
    {
        $pageId = pp_create_page('Innocent bystander', 'publish');

        $errors = _pp_restore_batch_snapshot([
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [$pageId],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [],
        ]);

        $this->assertNotNull(get_post($pageId), 'the page must NOT be deleted');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString((string) $pageId, $errors[0]);
        $this->assertStringContainsString('no longer an attachment', $errors[0]);
    }

    /** An ID already gone is not a survivor: nothing to delete, nothing to report. */
    public function testAnAlreadyDeletedAttachmentIsNotReportedAsASurvivor(): void
    {
        $errors = _pp_restore_batch_snapshot([
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [4242],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [],
        ]);

        $this->assertSame([], $errors);
    }

    /**
     * A refused redirect write names every row it would have changed — and ONLY those. A
     * batch can name a redirect whose step never ran (an earlier step failed first); that
     * row is already where the rollback wants it, so reporting it would be a sentence
     * about a redirect that was never created.
     */
    public function testARefusedRedirectWriteNamesOnlyTheRowsItWouldHaveChanged(): void
    {
        $errors = _pp_restore_batch_snapshot([
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [
                '/created'   => ['exists' => false, 'entry' => null],
                '/also-written' => ['exists' => false, 'entry' => null],
            ],
            'redirects_written'   => ['/created', '/also-written'],
        ]);
        $this->assertSame([], $errors, 'nothing live, nothing to write, nothing to report');

        // Now with a live row and the option write refused.
        pp_create_redirect('/created', '/wherever', 301);
        $GLOBALS['_pp_test_unwritable_options'][PP_REDIRECTS_OPTION] = true;

        $errors = _pp_restore_batch_snapshot([
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [
                '/created'      => ['exists' => false, 'entry' => null],
                '/also-written' => ['exists' => false, 'entry' => null],
            ],
            'redirects_written'   => ['/created', '/also-written'],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('/created', $errors[0]);
        $this->assertStringNotContainsString('/also-written', $errors[0]);
        $this->assertStringContainsString('still live', $errors[0]);
        // The refused write left the row exactly where it was.
        $this->assertSame(['to' => '/wherever', 'code' => 301], pp_resolve_redirect('/created'));
    }

    /**
     * NO WRITE WHEN NOTHING WOULD CHANGE. `update_option()` returns false both for a write
     * it refused and for a value already stored, so a rollback that wrote unconditionally
     * would report a survivor for a redirect step that never ran. The guard is what makes
     * a false return mean "refused".
     */
    public function testAnUnchangedRedirectMapIsNotWrittenAndNotReported(): void
    {
        $GLOBALS['_pp_test_unwritable_options'][PP_REDIRECTS_OPTION] = true;
        $GLOBALS['_pp_test_option_writes'] = []; // count only what the restore does

        $errors = _pp_restore_batch_snapshot([
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => ['/absent-either-way' => ['exists' => false, 'entry' => null]],
            'redirects_written'   => ['/absent-either-way'],
        ]);

        $this->assertSame(
            [],
            $errors,
            'a no-op patch must not be written, so a refused write cannot be inferred from it'
        );
        // COUNTED, NOT INSPECTED. The stored value looks identical whether the guard ran or
        // not, so only a write COUNT discriminates the guard this test is named for.
        $this->assertSame(
            0,
            $GLOBALS['_pp_test_option_writes'][PP_REDIRECTS_OPTION] ?? 0,
            'the option must not be written at all when the patch changes nothing'
        );
    }

    // ── naming a row is not writing it ───────────────────────────────────────────

    /**
     * THE ROW THE BATCH NAMED BUT NEVER WROTE. The snapshotter baselines every redirect a
     * batch NAMES, including rows belonging to steps that never run. Acting on that baseline
     * alone deletes a row this batch never created — and the window is real, because another
     * admin can create exactly that path while the batch is running. Ruling T1 forbids it
     * outright, which is why the restore is gated on what the executor actually WROTE.
     */
    public function testARowNamedByAStepThatNeverRanIsLeftAlone(): void
    {
        $batch = pp_ai_execute_batch([
            // Fails at step 0, so the create_redirect below never executes.
            $this->failingStep(),
            $this->createRedirectStep('/never-reached', '/somewhere'),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(0, $batch['failed_at'], 'the redirect step must never have run');
        $this->assertSame([], $batch['rollback_errors']);

        // Now the same batch shape, with the row created concurrently during the window.
        // The rollback must not touch it: this batch did not create it.
        pp_create_redirect('/never-reached', '/written-by-someone-else', 301);
        $second = pp_ai_execute_batch([
            $this->failingStep(),
            $this->createRedirectStep('/never-reached', '/somewhere'),
        ]);

        $this->assertFalse($second['ok']);
        $this->assertSame(
            ['to' => '/written-by-someone-else', 'code' => 301],
            pp_resolve_redirect('/never-reached'),
            'a row this batch never wrote must survive its rollback untouched'
        );
        $this->assertSame([], $second['rollback_errors']);
    }

    /**
     * A remove_redirect that removed NOTHING wrote nothing. It still returns ok — the action
     * documents a missing source as an ok no-op — so `ok` alone is not evidence of a write,
     * and treating it as one re-opens the hole above one layer in: the baseline says the row
     * was absent, so the rollback would unset a path another admin created meanwhile.
     */
    public function testANoOpRemoveRedirectDoesNotAuthorizeDeletingThatRow(): void
    {
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'remove_redirect', 'params' => ['from' => '/nothing-here']],
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['steps'][0]['ok'], 'a no-op remove still reports ok');
        $this->assertFalse(
            $batch['steps'][0]['changes'][0]['removed'],
            'and reports that it removed nothing'
        );
        $this->assertSame([], $batch['rollback_errors']);

        // Same batch, with the row created concurrently during the window.
        pp_create_redirect('/nothing-here', '/new-row', 302);
        $second = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'remove_redirect', 'params' => ['from' => '/nothing-here']],
            $this->failingStep(),
        ]);

        $this->assertFalse($second['ok']);
        // This second batch's remove DID remove the row, so its rollback restores it.
        $this->assertSame(
            ['to' => '/new-row', 'code' => 302],
            pp_resolve_redirect('/nothing-here'),
            'the row the batch actually removed comes back'
        );
    }

    /**
     * A NON-STRING `from` NEVER REACHES THE WRITER, and this pins the layer that stops it so
     * the predicate's wider gate is not mistaken for load-bearing. pp_validate_action() runs
     * before every execute and rejects the step on a strict gettype() check against the
     * declared `'from' => ['type' => 'string']`, so no row is created and there is nothing
     * for a rollback to miss. _pp_batch_redirect_step_source() accepts any scalar anyway, so
     * that it agrees with the WRITER (which is coercive) rather than with that declaration —
     * defence in depth against a future edit to the registry, not a live hole.
     */
    public function testANonStringSourceIsRefusedBeforeAnyRowIsCreated(): void
    {
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_redirect', 'params' => [
                'from' => 42, 'to' => '/target']],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertFalse($batch['steps'][0]['ok'], 'the param type gate refuses the step');
        $this->assertNull(pp_resolve_redirect('/42'), 'so no row was ever created');

        // The predicate itself still answers for a coerced source, which is what keeps the
        // snapshotter honest if that type declaration ever changes.
        $this->assertSame('/42', _pp_batch_redirect_step_source(
            ['name' => 'create_redirect', 'params' => ['from' => 42]]
        ));
    }

    // ── refusing a delete that is unsafe for a reason outside the attachment ─────

    /**
     * A WITHHELD COMPOSITION MAKES THE DELETE UNSAFE. The three withhold branches leave a
     * page holding the composition THIS BATCH wrote, and the documented import_media idiom
     * feeds the imported URL straight into an image slot — so that page can be pointing at
     * the very file the rollback is about to delete, and the bytes do not come back. T1's
     * third clause covers it: refuse, and name the survivor.
     */
    public function testAnImportedAttachmentIsNotDeletedWhileAPageRestoreWasWithheld(): void
    {
        $errors = _pp_restore_batch_snapshot([
            'posts'               => [7 => [
                'title'       => 'Corrupt',
                'slug'        => 'corrupt',
                'status'      => 'publish',
                'composition' => [],
                'seo_meta'    => [],
            ]],
            'created_posts'       => [],
            'created_attachments' => [55],
            // The page was already unreadable when the batch snapshotted it (#756), so its
            // composition restore is withheld and its live composition stays mid-batch.
            'unreadable'          => [7 => 'decode_error'],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [],
            'redirects_written'   => [],
        ]);

        $this->assertCount(2, $errors, 'the withheld page AND the refused media');
        $survivor = array_values(array_filter(
            $errors,
            fn($e) => str_contains($e, 'Media item 55')
        ));
        $this->assertCount(1, $survivor);
        $this->assertStringContainsString('may still reference this media', $survivor[0]);
    }

    /**
     * A baseline claiming a row existed but carrying no usable copy of it has nothing to
     * restore — and nothing to restore is never a reason to DELETE a row the bundle itself
     * says pre-existed the batch. The tempting spelling folds this into the unset branch.
     */
    public function testAMalformedBaselineLeavesThePreExistingRowAlone(): void
    {
        pp_create_redirect('/pre-existing', '/still-here', 301);

        foreach ([null, 'not-an-array', 42] as $brokenEntry) {
            $errors = _pp_restore_batch_snapshot([
                'posts'               => [],
                'created_posts'       => [],
                'created_attachments' => [],
                'unreadable'          => [],
                'site_options'        => [],
                'custom_css'          => null,
                'token_overrides'     => null,
                'font_urls'           => null,
                'menus'               => null,
                'redirects'           => ['/pre-existing' => ['exists' => true, 'entry' => $brokenEntry]],
                'redirects_written'   => ['/pre-existing'],
            ]);

            $this->assertSame([], $errors);
            $this->assertSame(
                ['to' => '/still-here', 'code' => 301],
                pp_resolve_redirect('/pre-existing'),
                'a malformed baseline must never delete a row it says pre-existed the batch'
            );
        }
    }

    // ── the executor's own recording guards ──────────────────────────────────────

    public function testASucceedingBatchKeepsTheAttachmentItImported(): void
    {
        $batch = pp_ai_execute_batch([$this->importMediaStep('https://example.com/keep.jpg')]);

        $this->assertTrue($batch['ok']);
        $this->assertFalse($batch['rolled_back']);
        $this->assertNotNull(
            get_post($batch['steps'][0]['changes'][0]['attachment_id']),
            'a successful batch must not delete what it imported'
        );
    }

    /**
     * The two guards on the recorder itself, which the helper's own unit tests cannot see:
     * a FAILED import_media contributes nothing, and a repeated id is recorded once so the
     * restore's delete loop stays idempotent.
     */
    public function testTheRecorderIgnoresAFailedImportAndDedupesRepeatedIds(): void
    {
        $GLOBALS['_pp_test_store']['download_url_result'] = new WP_Error('http_404', 'nope');

        $failed = pp_ai_execute_batch([
            $this->importMediaStep('https://example.com/broken.jpg'),
        ]);
        $this->assertFalse($failed['ok']);
        $this->assertSame([], $failed['rollback_errors'], 'a failed import records no artifact');

        unset($GLOBALS['_pp_test_store']['download_url_result']);

        // Two steps, one source URL: the second DEDUPES to the first's attachment, so the
        // id is seen twice and must be deleted exactly once.
        $url = 'https://example.com/twice.jpg';
        $batch = pp_ai_execute_batch([
            $this->importMediaStep($url),
            $this->importMediaStep($url),
            $this->failingStep(),
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame('import', $batch['steps'][0]['changes'][0]['action']);
        $this->assertSame('reused', $batch['steps'][1]['changes'][0]['action']);
        $this->assertNull(get_post($batch['steps'][0]['changes'][0]['attachment_id']));
        $this->assertSame([], $batch['rollback_errors']);
    }

    public function testRedirectStepWroteReadsTheRemovedFlagNotTheOkFlag(): void
    {
        $this->assertTrue(_pp_batch_redirect_step_wrote('create_redirect', []));
        $this->assertTrue(_pp_batch_redirect_step_wrote(
            'remove_redirect',
            ['changes' => [['removed' => true]]]
        ));
        $this->assertFalse(_pp_batch_redirect_step_wrote(
            'remove_redirect',
            ['changes' => [['removed' => false]]]
        ));
        // No evidence of a write must never authorize a deletion.
        $this->assertFalse(_pp_batch_redirect_step_wrote('remove_redirect', []));
        $this->assertFalse(_pp_batch_redirect_step_wrote('remove_redirect', ['changes' => 'x']));
    }

    // ── the bundle contract ──────────────────────────────────────────────────────

    /**
     * A bundle from before #854 carries neither new key. It must roll back what it DOES
     * describe rather than fataling on what it does not — this function is called directly,
     * with hand-built bundles, from several test files and from any caller that assembles
     * one itself.
     */
    public function testALegacyBundleWithNeitherNewKeyStillRestores(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_og_site_name'] = 'changed-mid-batch';

        $errors = _pp_restore_batch_snapshot([
            'posts'           => [],
            'created_posts'   => [],
            'unreadable'      => [],
            'site_options'    => ['pp_og_site_name' => ['exists' => true, 'value' => 'original']],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('original', get_option('pp_og_site_name'));
    }

    public function testSnapshotRecordsPresenceSeparatelyFromTheStoredEntry(): void
    {
        pp_create_redirect('/has-row', '/target', 302);

        $snapshot = _pp_snapshot_batch_targets([
            $this->createRedirectStep('/has-row', '/new'),
            $this->createRedirectStep('/no-row', '/new'),
        ]);

        $this->assertSame(
            ['exists' => true, 'entry' => ['to' => '/target', 'code' => 302]],
            $snapshot['redirects']['/has-row']
        );
        $this->assertSame(
            ['exists' => false, 'entry' => null],
            $snapshot['redirects']['/no-row'],
            'a source with no stored row captures absent-shaped, so the rollback DELETES it'
        );
        $this->assertSame([], $snapshot['created_attachments']);
    }

    public function testSnapshotCapturesNoRedirectsForABatchThatNamesNone(): void
    {
        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'list_redirects', 'params' => []],
        ]);

        $this->assertSame([], $snapshot['redirects']);
    }

    // ── the two predicates ───────────────────────────────────────────────────────

    public function testRedirectStepSourceNormalizesThroughTheWritersOwnNormalizer(): void
    {
        $this->assertSame(
            '/old',
            _pp_batch_redirect_step_source(
                ['name' => 'create_redirect', 'params' => ['from' => 'https://example.com/old/?q=1']]
            )
        );
        $this->assertSame(
            '/gone',
            _pp_batch_redirect_step_source(['name' => 'remove_redirect', 'params' => ['from' => '/gone']])
        );
    }

    public function testRedirectStepSourceIgnoresEveryOtherStepShape(): void
    {
        $this->assertNull(_pp_batch_redirect_step_source(
            ['name' => 'list_redirects', 'params' => ['from' => '/old']]
        ));
        $this->assertNull(_pp_batch_redirect_step_source(
            ['name' => 'create_redirect', 'params' => ['from' => ['/old']]]
        ));
        $this->assertNull(_pp_batch_redirect_step_source(['name' => 'create_redirect', 'params' => []]));
        $this->assertNull(_pp_batch_redirect_step_source([]));
    }

    public function testImportedAttachmentIdsReadsOnlyTheImportAction(): void
    {
        $this->assertSame([55], _pp_batch_imported_attachment_ids(
            ['changes' => [['action' => 'import', 'attachment_id' => 55]]]
        ));
        $this->assertSame([], _pp_batch_imported_attachment_ids(
            ['changes' => [['action' => 'reused', 'attachment_id' => 55]]]
        ));
        // Every entry is scanned, so an envelope that grows a second one cannot leak it.
        $this->assertSame([55, 56], _pp_batch_imported_attachment_ids(
            ['changes' => [
                ['action' => 'import', 'attachment_id' => 55],
                ['action' => 'reused', 'attachment_id' => 99],
                ['action' => 'import', 'attachment_id' => '56'],
            ]]
        ));
    }

    public function testImportedAttachmentIdsNeverThrowsOnAMalformedEnvelope(): void
    {
        $this->assertSame([], _pp_batch_imported_attachment_ids([]));
        $this->assertSame([], _pp_batch_imported_attachment_ids(['changes' => 'not-a-list']));
        $this->assertSame([], _pp_batch_imported_attachment_ids(['changes' => ['scalar']]));
        $this->assertSame([], _pp_batch_imported_attachment_ids(
            ['changes' => [['action' => 'import']]]
        ));
        $this->assertSame([], _pp_batch_imported_attachment_ids(
            ['changes' => [['action' => 'import', 'attachment_id' => 'abc']]]
        ));
        $this->assertSame([], _pp_batch_imported_attachment_ids(
            ['changes' => [['action' => 'import', 'attachment_id' => 0]]]
        ));
    }
}
