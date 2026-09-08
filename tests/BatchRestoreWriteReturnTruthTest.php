<?php
/**
 * tests/BatchRestoreWriteReturnTruthTest.php — a rollback whose own writes were REFUSED no
 * longer reports `rollback_errors: []` (#857).
 *
 * THE BUG THIS PINS. `_pp_restore_batch_snapshot()` (lib/actions.php) is the producer that
 * makes `rolled_back: true` trustworthy — `pp_ai_execute_batch()`'s envelope says so in as
 * many words: "a consumer must not treat rolled_back: true as clean without checking it".
 * It appended to `$errors` for the cases it WITHHELD, and discarded the return of every
 * write it actually ATTEMPTED. So a refused write left the applied state in place and said
 * nothing:
 *
 *     ok: false   failed_at: 2   rolled_back: true
 *     rollback_errors: []      ← nothing to report, because nothing was ever checked
 *
 * and since #755/#797 all three batch-failure exits present that empty array as EVIDENCE
 * that the revert was clean. An unverified statement rendered as a verified one.
 *
 * SAME CONSEQUENCE CLASS AS #854, BY THE OTHER ROUTE. There the write was never attempted
 * (a created redirect or attachment the snapshot did not cover); here it is attempted and
 * its failure is dropped. #854 checked the writes it ADDED and left the rest named for this
 * slot; its docblock said so at the attachment loop.
 *
 *   a restore write ─┬─ succeeded ─────────────────▶ silent (nothing survived)
 *                    ├─ nothing to do (unchanged) ─▶ silent (verified-unnecessary)
 *                    └─ REFUSED ───────────────────▶ NAME it on rollback_errors
 *
 * WHAT IS PINNED HERE, IN THREE KINDS.
 *
 *   RED PROOF — fails against the pre-fix source. One case per write: the created page
 *   whose delete is refused, the composition whose write is refused, the title / slug /
 *   status / SEO-metadata restores, the whitelisted site option and its delete, the design
 *   tokens, the font URLs, the Custom CSS (both the refused write and the vanished post),
 *   and the slug that lands de-duplicated. Plus the authoring-path case: the same failure
 *   reached through the real `pp_ai_execute_batch()` envelope.
 *
 *   THE BOUNDARY — passes before AND after, and it is the half that matters most, because
 *   the cheapest way to make the red proofs green is to report everything. An UNCHANGED
 *   option value must not report (`update_option()` returns false for it), an
 *   already-absent row must not report (`delete_option()` returns false for it too), a
 *   created page that is provably GONE must not report, a page that no longer exists must
 *   not report four field survivors, and a fully clean rollback must still say `[]`.
 *
 *   THE BOUNDARY PINS ASSERT WRITE AND DELETE COUNTS, NOT JUST THE EMPTY REPORT, wherever
 *   the guard is a skip. With no guard at all the write runs, succeeds, and the report is
 *   empty too — so an assertion on the report alone is vacuous and passes either way. The
 *   counters (`_pp_test_option_writes`, `_pp_test_option_deletes`) are the only thing that
 *   can tell "the compare-first guard skipped it" from "it ran and happened to work". A
 *   review pass caught the delete arm shipping with exactly that vacuous shape.
 *
 *   NO DOUBLE-REPORT — the #749/#756/#833 withhold branches already own the "composition
 *   was not rolled back" sentence. The attempted-write branch is a fourth outcome, not a
 *   fourth withhold, and a withheld page must produce exactly ONE composition sentence.
 *
 * THE HARNESS HOOKS. `_pp_test_undeletable_posts`, `_pp_test_unwritable_options` and
 * `_pp_test_option_writes` were staged in tests/bootstrap.php by #854 for this slot;
 * `_pp_test_unwritable_posts` is added here for the same reason (an existing post's
 * `wp_update_post` otherwise always succeeds in the harness, leaving the title/slug/status
 * failure branch unreachable). All are opt-in and inert when unset.
 */

use PHPUnit\Framework\TestCase;

/**
 * Denies the composition advisory lock so `pp_update_composition()` returns
 * WP_Error('composition_lock_failed') and writes NOTHING. That is the one production
 * failure of that writer reachable without a concurrent process, and its all-or-nothing
 * contract is what lets the rollback state, from the WP_Error alone, that the page still
 * holds the composition the batch wrote.
 *
 * Only GET_LOCK diverges from PP_Lockable_Wpdb; the postmeta and option point-reads stay
 * the shared harness's, so the #833 gate still classifies the target as readable and the
 * restore reaches the WRITE rather than a withhold branch.
 */
class PP_LockDenied_Wpdb extends PP_Lockable_Wpdb
{
    public function get_var(string $query)
    {
        if (str_contains($query, 'GET_LOCK')) {
            return '0'; // lock busy: acquired by nobody, granted to nobody
        }
        return parent::get_var($query);
    }
}

class BatchRestoreWriteReturnTruthTest extends TestCase
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
            $GLOBALS['_pp_test_undeletable_posts'],
            $GLOBALS['_pp_test_undeletable_attachments'],
            $GLOBALS['_pp_test_unwritable_posts'],
            $GLOBALS['_pp_test_unwritable_options'],
            $GLOBALS['_pp_test_unwritable_theme_mods'],
            $GLOBALS['_pp_test_option_writes'],
            $GLOBALS['_pp_test_option_deletes']
        );
        parent::tearDown();
    }

    /**
     * A snapshot bundle with every key the restorer reads, so each test states only the
     * one thing it is about. Mirrors the shape _pp_snapshot_batch_targets() produces.
     */
    private function bundle(array $overrides = []): array
    {
        return $overrides + [
            'posts'               => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'unreadable'          => [],
            'site_options'        => [],
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => null,
            'redirects'           => [],
            'redirects_written'   => [],
        ];
    }

    /** A real page with a readable composition, as the snapshotter would find it. */
    private function page(string $title = 'Target', string $slug = 'target'): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_page_slug($id, $slug);
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Before']]]);
        return $id;
    }

    /** The captured pre-batch state of a page, in the shape the restorer consumes. */
    private function capturedState(int $post_id, array $overrides = []): array
    {
        $post = get_post($post_id);
        return $overrides + [
            'title'       => $post->post_title,
            'slug'        => $post->post_name,
            'status'      => $post->post_status,
            'composition' => pp_get_composition($post_id),
            'seo_meta'    => pp_get_seo_meta($post_id),
        ];
    }

    private function assertOneEntryContaining(array $errors, string $needle, string $why): void
    {
        $hits = array_values(array_filter($errors, static fn($e) => str_contains($e, $needle)));
        $this->assertCount(1, $hits, $why . ' — got: ' . var_export($errors, true));
    }

    // ── 1. a page this batch CREATED, whose delete is refused ────────────────────

    /**
     * THE SHARPEST OF THE LOT ALONGSIDE THE CUSTOM CSS. A create_page step ran, a later
     * step failed, and the rollback's `wp_delete_post()` was refused — so a page the
     * operator never asked to keep is live on the site while the card says everything was
     * reverted. #854's own docblock named this exact case as belonging to #857.
     */
    public function testACreatedPageWhoseDeleteIsRefusedIsNamed(): void
    {
        $created = $this->page('Brand new', 'brand-new');
        $GLOBALS['_pp_test_undeletable_posts'][$created] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle(['created_posts' => [$created]]));

        $this->assertOneEntryContaining(
            $errors,
            "Page {$created} was created by this batch and could NOT be deleted",
            'the surviving page is named'
        );
        $this->assertNotNull(get_post($created), 'and it really is still there');
    }

    /**
     * THE BOUNDARY, and the reason the null/false split exists. Core returns NULL when no
     * row exists at that ID and FALSE when the delete was refused. Folding null into the
     * failure branch would report a survivor for a page that is provably gone — a false
     * entry on the one channel this change exists to make trustworthy.
     */
    public function testACreatedPageThatIsAlreadyGoneReportsNothing(): void
    {
        $errors = _pp_restore_batch_snapshot($this->bundle(['created_posts' => [4242]]));

        $this->assertSame([], $errors, 'nothing deleted, nothing survived, nothing to report');
    }

    // ── 2. the composition write, as distinct from the three withholds ───────────

    /**
     * pp_update_composition() returns WP_Error and writes NOTHING when it cannot take the
     * per-post advisory lock ("never a silent non-atomic write", lib/wp.php). The rollback
     * discarded that, so a locked page kept the composition the batch wrote behind a clean
     * report — the exact state a rollback exists to undo.
     */
    public function testARefusedCompositionWriteIsNamed(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        pp_update_composition($page, [['component' => 'hero', 'props' => ['title' => 'Mid-batch']]]);

        $GLOBALS['wpdb'] = new PP_LockDenied_Wpdb();
        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertOneEntryContaining(
            $errors,
            "Page {$page}: its composition was NOT rolled back",
            'the refused composition write is named'
        );
        $this->assertSame(
            'Mid-batch',
            pp_get_composition($page)[0]['props']['title'],
            'and the page really does still hold what the batch wrote'
        );
    }

    /**
     * THE REFUSED WRITE ALSO REFUSES THE ATTACHMENT DELETE. `$withheld_pages` means "this
     * page still holds the composition THIS BATCH wrote", which a refused write makes
     * true — and the documented import_media idiom feeds an imported URL straight into an
     * image slot, so deleting the file would leave a live page pointing at media that is
     * gone. Naming the failed write without joining that list would name the page and then
     * dangle it.
     */
    public function testARefusedCompositionWriteAlsoRefusesTheAttachmentDelete(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        $media = pp_create_page('Imported', 'draft');
        $GLOBALS['_pp_test_store']['posts'][$media]['post_type'] = 'attachment';

        $GLOBALS['wpdb'] = new PP_LockDenied_Wpdb();
        $errors = _pp_restore_batch_snapshot($this->bundle([
            'posts'               => [$page => $state],
            'created_attachments' => [$media],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            "Media item {$media} was imported by this batch and was NOT deleted",
            'the delete is refused because a composition restore did not land'
        );
        $this->assertNotNull(get_post($media), 'and the file is still in the library');
    }

    /**
     * NO DOUBLE-REPORT. The three withhold branches and the attempted write are arms of one
     * if/elseif/else, so a withheld page can never also report a refused write. Pinned
     * because the obvious way to add the new branch is to append it after the chain.
     */
    public function testAWithheldPageStillProducesExactlyOneCompositionSentence(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'posts'      => [$page => $state],
            'unreadable' => [$page => 'decode_error'],
        ]));

        $composition_entries = array_values(array_filter(
            $errors,
            static fn($e) => str_contains($e, 'composition was NOT rolled back')
        ));
        $this->assertCount(1, $composition_entries, 'the withhold, and only the withhold');
        $this->assertStringContainsString(
            'the stored bytes could not be read when this batch snapshotted them',
            $composition_entries[0],
            'and it is the #756 withhold wording, not the #857 refused-write one'
        );
    }

    // ── 3-5. title, slug and status ──────────────────────────────────────────────

    /**
     * All three ride wp_update_post(), so one refusal covers them — and each must land its
     * OWN sentence rather than one lumped "the page did not roll back", because an operator
     * fixing this by hand needs to know which fields to set back.
     */
    public function testRefusedTitleSlugAndStatusRestoresAreEachNamed(): void
    {
        $page  = $this->page('Original title', 'original-slug');
        $state = $this->capturedState($page);
        // The batch moved all three, so all three have something to restore.
        $GLOBALS['_pp_test_store']['posts'][$page]['post_title']  = 'Batch title';
        $GLOBALS['_pp_test_store']['posts'][$page]['post_name']   = 'batch-slug';
        $GLOBALS['_pp_test_store']['posts'][$page]['post_status'] = 'publish';
        $GLOBALS['_pp_test_unwritable_posts'][$page] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertOneEntryContaining($errors, "Page {$page}: its title was NOT rolled back", 'title');
        $this->assertOneEntryContaining($errors, "Page {$page}: its slug (permalink) was NOT rolled back", 'slug');
        $this->assertOneEntryContaining($errors, "Page {$page}: its published/draft status was NOT rolled back", 'status');
    }

    /**
     * THE SLUG'S SECOND FAILURE MODE, and the reason pp_update_page_slug() returns the
     * LANDED slug rather than a bool: WordPress de-duplicates post_name inside
     * wp_update_post(), so the write can SUCCEED and store something else. Discarding the
     * return hid that completely — the page came back on a different permalink under a
     * clean report.
     */
    public function testASlugThatLandsDeduplicatedIsNamed(): void
    {
        $page  = $this->page('Target', 'shared-slug');
        $state = $this->capturedState($page);
        $GLOBALS['_pp_test_store']['posts'][$page]['post_name'] = 'batch-slug';
        // A second page took the original slug during the batch window.
        $this->page('Squatter', 'shared-slug');

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertOneEntryContaining(
            $errors,
            "Page {$page}: its slug (permalink) was rolled back to a DIFFERENT value",
            'the de-duplicated landing is named'
        );
        $this->assertSame(
            'shared-slug-2',
            get_post($page)->post_name,
            'and it really did land somewhere else'
        );
    }

    /**
     * THE BOUNDARY FOR THE SLUG, and the false alarm it exists to prevent. A page can hold
     * no post_name at all, and pp_update_page_slug() REFUSES an empty slug outright. Without
     * the compare-first guard every rollback naming such a page would report a slug failure
     * for a slug that never changed and never existed.
     */
    public function testAnUnchangedEmptySlugReportsNothing(): void
    {
        $page = pp_create_page('No slug', 'draft');
        $GLOBALS['_pp_test_store']['posts'][$page]['post_name'] = '';
        $state = $this->capturedState($page);

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame([], $errors, 'nothing changed, so nothing failed to change back');
    }

    /**
     * THE BOUNDARY FOR THE WHOLE PAGE. A page deleted inside the batch window refuses all
     * four field writes. Reporting them would put four sentences on the channel telling the
     * operator to go and fix fields on a page that is not there — the #855 mirror-bug, four
     * times over. Nothing was restored because nothing survived.
     */
    public function testAPageThatNoLongerExistsReportsNoFieldSurvivors(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        unset($GLOBALS['_pp_test_store']['posts'][$page]);

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertSame([], $errors, 'a vanished page is not a survivor');
    }

    // ── 6. SEO metadata ──────────────────────────────────────────────────────────

    /**
     * REACHED THROUGH THE KEY ALLOWLIST, which is the guard the restore keeps.
     *
     * THIS CASE USED TO BE AN OVER-LENGTH DESCRIPTION, and that it no longer can be is the
     * point of #875. The writer re-validated on the way in, so a stored meta_description
     * past the 320-character cap round-tripped out of pp_get_seo_meta() into the snapshot
     * and was REJECTED on the way back — a #233 violation this pin could only report, not
     * undo. The restore now bypasses the VALUE rules, so that trigger restores instead of
     * refusing (BatchRestoreSeoValidationBypassTest owns it).
     *
     * WHAT STILL REFUSES is a baseline naming meta this theme does not own: the allowlist is
     * authorization, not content, and it runs before anything else. Re-based here rather than
     * deleted, because #857's guarantee is about the WRITE — an SEO restore that is refused
     * for any reason must still reach the channel — and a producer with no pin is a producer
     * that quietly stops producing.
     */
    public function testARefusedSeoMetaRestoreIsNamed(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        $state['seo_meta']['not_ours'] = 'value';

        $errors = _pp_restore_batch_snapshot($this->bundle(['posts' => [$page => $state]]));

        $this->assertOneEntryContaining(
            $errors,
            "Page {$page}: its SEO metadata was NOT rolled back",
            'the refused SEO restore is named'
        );
    }

    // ── 7-8. whitelisted site options: the write and the delete ──────────────────

    public function testARefusedSiteOptionWriteIsNamed(): void
    {
        update_option('blogname', 'Batch name');
        $GLOBALS['_pp_test_unwritable_options']['blogname'] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['blogname' => ['exists' => true, 'value' => 'Original name']],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'The site setting "blogname" was NOT rolled back',
            'the refused option write is named'
        );
        $this->assertSame('Batch name', get_option('blogname'), 'and the batch value is still live');
    }

    /**
     * THE TRAP THIS WHOLE DESIGN TURNS ON. update_option() returns FALSE for a value that is
     * already stored, exactly as it does for a refused write, and PHP cannot tell them apart
     * from the return. A batch whose option step never ran (an earlier step failed first)
     * restores the value that is already there — so reporting on the bare return would name
     * a survivor for a setting nothing ever touched. The write is skipped entirely, which is
     * the only assertion that can prove the guard ran: the stored value looks identical
     * either way.
     */
    public function testAnUnchangedSiteOptionNeitherWritesNorReports(): void
    {
        update_option('blogname', 'Unchanged');
        $GLOBALS['_pp_test_option_writes'] = [];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['blogname' => ['exists' => true, 'value' => 'Unchanged']],
        ]));

        $this->assertSame([], $errors, 'nothing to restore is not a failure to restore');
        $this->assertSame(
            0,
            $GLOBALS['_pp_test_option_writes']['blogname'] ?? 0,
            'and no write was attempted at all'
        );
    }

    /**
     * THE DELETE ARM'S OWN RED PROOF. An option the batch INVENTED (absent before it ran)
     * whose row cannot be removed stays live with the batch's value in it — and reported
     * nothing at all before this change.
     */
    public function testARefusedSiteOptionDeleteIsNamed(): void
    {
        update_option('pp_logo_alt', 'Invented by the batch');
        $GLOBALS['_pp_test_unwritable_options']['pp_logo_alt'] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['pp_logo_alt' => ['exists' => false, 'value' => '']],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'The site setting "pp_logo_alt" was NOT rolled back',
            'a delete the rollback could not perform is named'
        );
        $this->assertSame(
            'Invented by the batch',
            get_option('pp_logo_alt'),
            'and the batch value really is still live'
        );
    }

    /**
     * THE SAME TRAP ON THE DELETE ARM. delete_option() returns false for a row that was not
     * there, so the presence test — read with the snapshotter's own object sentinel, because
     * a stored '' must not read as absent — is what keeps this honest.
     *
     * THE DELETE COUNTER IS THE LOAD-BEARING ASSERTION, exactly as the write counter is on
     * the update arm. Asserting only the empty report proves nothing about the guard: with
     * no guard at all the delete runs, succeeds, returns true, and the report is empty too.
     * Counting is the only way to tell "the presence test skipped the delete" from "the
     * delete ran and happened to work".
     */
    public function testAnAlreadyAbsentSiteOptionIsNeitherDeletedNorReported(): void
    {
        $GLOBALS['_pp_test_option_deletes'] = [];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['pp_logo_alt' => ['exists' => false, 'value' => '']],
        ]));

        $this->assertSame([], $errors, 'the row was already absent, which is the target state');
        $this->assertSame(
            0,
            $GLOBALS['_pp_test_option_deletes']['pp_logo_alt'] ?? 0,
            'and no delete was attempted at all — the only proof the presence test ran'
        );
    }

    /**
     * THE NON-SCALAR DEGRADATION the site-options compare documents: a hand-written array in
     * a whitelisted option is not a shape the captured string can equal, so the restore must
     * PROCEED and write the trusted baseline over it. Pinned with the write counter because
     * the stored value alone cannot distinguish "wrote the baseline" from "skipped, and the
     * baseline happened to be there".
     */
    public function testANonScalarLiveRowIsOverwrittenByTheTrustedBaseline(): void
    {
        $GLOBALS['_pp_test_store']['options']['blogname'] = ['unexpected' => 'shape'];
        $GLOBALS['_pp_test_option_writes'] = [];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['blogname' => ['exists' => true, 'value' => 'Original name']],
        ]));

        $this->assertSame([], $errors, 'the write succeeded, so nothing is reported');
        $this->assertSame(1, $GLOBALS['_pp_test_option_writes']['blogname'] ?? 0, 'the restore was NOT skipped');
        $this->assertSame('Original name', get_option('blogname'));
    }

    /**
     * AN EXPLICIT '' IS NOT AN ABSENT ROW (#291), and the sentinel is what keeps them apart
     * here. A stored '' must still be DELETED when the baseline says the row did not exist.
     */
    public function testAnEmptyStringRowIsStillDeletedWhenTheBaselineSaysAbsent(): void
    {
        update_option('pp_logo_alt', '');

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'site_options' => ['pp_logo_alt' => ['exists' => false, 'value' => '']],
        ]));

        $this->assertSame([], $errors, 'the delete succeeded');
        $this->assertFalse(get_option('pp_logo_alt'), 'and the row is really gone');
    }

    // ── 9-10. design tokens and font URLs ────────────────────────────────────────

    public function testRefusedTokenOverrideAndFontUrlRestoresAreNamed(): void
    {
        update_option('pp_token_overrides', ['--color-accent' => '#batch']);
        update_option('pp_font_urls', ['https://example.com/batch.css']);
        $GLOBALS['_pp_test_unwritable_options']['pp_token_overrides'] = true;
        $GLOBALS['_pp_test_unwritable_options']['pp_font_urls']       = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'token_overrides' => ['--color-accent' => '#original'],
            'font_urls'       => ['https://example.com/original.css'],
        ]));

        $this->assertOneEntryContaining($errors, 'design token overrides were NOT rolled back', 'tokens');
        $this->assertOneEntryContaining($errors, 'custom font URLs were NOT rolled back', 'fonts');
    }

    /**
     * THE BOUNDARY FOR BOTH. The font comparison is made in the shape pp_set_font_urls()
     * STORES (array_values), not the shape the snapshot holds — a captured list with
     * non-sequential keys would otherwise never compare equal to its own stored form and
     * the guard would write on every rollback.
     */
    public function testUnchangedTokensAndFontsNeitherWriteNorReport(): void
    {
        update_option('pp_token_overrides', ['--color-accent' => '#same']);
        update_option('pp_font_urls', ['https://example.com/same.css']);
        $GLOBALS['_pp_test_option_writes'] = [];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'token_overrides' => ['--color-accent' => '#same'],
            // A non-sequential key: array_values() normalizes it to the stored shape.
            'font_urls'       => [3 => 'https://example.com/same.css'],
        ]));

        $this->assertSame([], $errors, 'nothing to restore');
        $this->assertSame(0, $GLOBALS['_pp_test_option_writes']['pp_token_overrides'] ?? 0, 'no token write');
        $this->assertSame(0, $GLOBALS['_pp_test_option_writes']['pp_font_urls'] ?? 0, 'no font write');
    }

    // ── 11. Custom CSS, including the branch that had no else at all ─────────────

    public function testARefusedCustomCssRestoreIsNamed(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '';
        // The virtual Custom CSS post is ID 999 in the harness.
        $GLOBALS['_pp_test_unwritable_posts'][999] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle(['custom_css' => 'body { color: red }']));

        $this->assertOneEntryContaining(
            $errors,
            'Custom CSS was NOT rolled back: the restoring write was refused',
            'the refused CSS write is named'
        );
    }

    /**
     * THE `if ($css_post)` WITH NO ELSE — the sharpest silence of the set. A batch cleared
     * the Custom CSS, the Custom CSS post was removed inside the batch window, and the
     * rollback had nowhere to write the stylesheet back to. It returned a clean report while
     * the site's entire Custom CSS stayed deleted.
     */
    public function testACustomCssPostThatVanishedIsNamed(): void
    {
        // No 'custom_css' key at all => wp_get_custom_css_post() answers null (never created
        // / removed), and wp_get_custom_css() reads ''.
        $errors = _pp_restore_batch_snapshot($this->bundle(['custom_css' => 'body { color: red }']));

        $this->assertOneEntryContaining(
            $errors,
            'the Custom CSS post no longer exists',
            'the lost stylesheet is named'
        );
    }

    /**
     * THE BOUNDARY. With no Custom CSS post AND an empty snapshot there is nothing to lose,
     * so a batch that merely NAMED clear_custom_css on a site with no Custom CSS must not
     * report a survivor.
     */
    public function testAnAbsentCustomCssPostWithNothingToRestoreReportsNothing(): void
    {
        $errors = _pp_restore_batch_snapshot($this->bundle(['custom_css' => '']));

        $this->assertSame([], $errors, 'there was no stylesheet to put back');
    }

    // ── the shared compare-first helper, directly ────────────────────────────────

    /**
     * FOUR CALL SITES SHARE THIS, so it is pinned directly rather than only through them.
     *
     * THE STRICTNESS IS THE POINT, and it is invisible from the call sites: under PHP 8 a
     * loose `==` compares two numeric strings NUMERICALLY ('1e3' == '1000') and two arrays
     * ORDER-INSENSITIVELY. Either spelling would treat a genuinely different value as
     * "nothing to do", skip the restore, and report a clean rollback for a setting that
     * never went back — the exact false-clean this issue exists to remove, reintroduced
     * inside the helper written to prevent it.
     */
    public function testRestoreWriteIfChangedComparesStrictlyAndOnlyWritesWhenNeeded(): void
    {
        $calls  = 0;
        $writer = function () use (&$calls) {
            $calls++;
            return true;
        };

        $this->assertTrue(_pp_restore_write_if_changed('same', 'same', $writer));
        $this->assertSame(0, $calls, 'an unchanged value is never written');

        $this->assertTrue(_pp_restore_write_if_changed('a', 'b', $writer));
        $this->assertSame(1, $calls, 'a real difference is written');

        $this->assertFalse(
            _pp_restore_write_if_changed('a', 'b', static fn() => false),
            'past the guard, a falsy return is provably a refusal'
        );

        $calls = 0;
        _pp_restore_write_if_changed('1e3', '1000', $writer);
        _pp_restore_write_if_changed(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], $writer);
        $this->assertSame(2, $calls, 'numeric strings and reordered arrays are DIFFERENT values');
    }

    // ── the clean case, and the authoring path (Section 14.1) ────────────────────

    /**
     * THE CLAIM THE WHOLE ISSUE PROTECTS. A rollback whose writes all landed still reports
     * `[]`, and now that empty array means every write was checked rather than none of them.
     */
    public function testAFullyCleanRollbackStillReportsAnEmptyArray(): void
    {
        $page  = $this->page('Original', 'original');
        $state = $this->capturedState($page);
        $GLOBALS['_pp_test_store']['posts'][$page]['post_title'] = 'Batch title';
        update_option('blogname', 'Batch name');
        $GLOBALS['_pp_test_store']['custom_css'] = 'batch';

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'posts'           => [$page => $state],
            'site_options'    => ['blogname' => ['exists' => true, 'value' => 'Original name']],
            'custom_css'      => 'original',
            'token_overrides' => ['--color-accent' => '#original'],
            'font_urls'       => ['https://example.com/original.css'],
        ]));

        $this->assertSame([], $errors, 'everything went back, so nothing is reported');
        $this->assertSame('Original', get_post($page)->post_title);
        $this->assertSame('Original name', get_option('blogname'));
        $this->assertSame('original', wp_get_custom_css());
    }

    /**
     * SECTION 14.1 — the failure driven through the REAL batch surface, not a hand-built
     * bundle. A create_page step runs, a later step fails, the executor rolls back, and the
     * rollback's own delete is refused. What the consumer sees is the ENVELOPE: rolled_back
     * true beside a rollback_errors that is no longer empty. Before this change the same
     * envelope carried `[]` with the created page still live.
     */
    public function testTheEnvelopeCarriesARefusedRestoreThroughTheRealExecutor(): void
    {
        $GLOBALS['_pp_test_undeletable_posts'] = [];
        // Every post created from here on refuses deletion, which is what the create_page
        // step's page will hit when the rollback tries to remove it.
        $next_id = $GLOBALS['_pp_test_store']['next_id'];
        for ($id = $next_id; $id < $next_id + 20; $id++) {
            $GLOBALS['_pp_test_undeletable_posts'][$id] = true;
        }

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => [
                'title' => 'Made by the batch', 'status' => 'draft']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok'], 'the second step fails');
        $this->assertTrue($batch['rolled_back'], 'so the executor rolls back');
        $this->assertNotSame(
            [],
            $batch['rollback_errors'],
            'and the envelope no longer claims a clean revert while the page is still live'
        );
        $survivors = array_values(array_filter(
            $batch['rollback_errors'],
            static fn($e) => str_contains($e, 'was created by this batch and could NOT be deleted')
        ));
        $this->assertCount(1, $survivors, 'the surviving page is named on the envelope');
    }

    // ── 14. THE MENU LAYER'S OWN WRITES (#876) ───────────────────────────────────
    //
    // THE LAST FALSE-CLEAN POCKET IN THE ROLLBACK. #857 checked every write
    // _pp_restore_batch_snapshot_report() makes ITSELF and said so in its @return: the
    // menu layer it delegates to, _pp_restore_menu_state(), reported only what it could
    // not RECREATE. Its two other writes were discarded — the wp_delete_post() calls
    // inside pp_clear_nav_menu_items() (declared `: void`, so there was nothing to check)
    // and set_theme_mod() for the location assignments. The pins below are the same three
    // kinds as the rest of this file: a RED proof per write class, the BOUNDARY that stops
    // the cheapest way of making them green (report everything), and the authoring path.

    /**
     * RED — a menu item the rollback could not remove is NAMED.
     *
     * The live menu holds an item the batch added; the rollback clears the menu before
     * rebuilding it from the snapshot, and that delete is refused. The item stays, the
     * rebuild puts the snapshotted list back around it, and the operator ends up with a
     * menu holding one item too many while the card said everything was reverted.
     */
    public function testAMenuItemWhoseRemovalIsRefusedIsNamed(): void
    {
        $menu_id  = wp_create_nav_menu('Main');
        $snapshot = (object) [
            'ID' => 501, 'post_title' => 'Home', 'title' => 'Home',
            'type' => 'custom', 'url' => 'https://example.com/', 'menu_order' => 1,
        ];
        // Live = the snapshot item plus one the batch added, so the signature guard does
        // NOT skip this menu and the clear/rebuild actually runs.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            $snapshot,
            (object) ['ID' => 502, 'title' => 'Added by the batch', 'url' => 'https://example.com/new', 'menu_order' => 2],
        ];
        $GLOBALS['_pp_test_undeletable_posts'][502] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => [
                'menus'     => [$menu_id => ['name' => 'Main', 'items' => [$snapshot]]],
                'locations' => [],
            ],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'menu item 502 could not be removed before the rebuild',
            'a refused delete leaves the item in the menu, so the rollback must name it'
        );
        // NAMED BY ID, NOT BY TITLE. This sentence reaches the chat card, and
        // _pp_restore_field_failure_message() states the rule for that channel: nothing
        // stored is reflected into it while #864's reflected-text ownership is open. A live
        // menu item's title is text the batch may have written moments earlier.
        $this->assertSame(
            [],
            array_values(array_filter($errors, static fn($e) => str_contains($e, 'Added by the batch'))),
            'the live title must not be reflected into the rollback report'
        );
        // AND THE WORLD REALLY LOOKS LIKE THE SENTENCE SAYS IT DOES. The report promises
        // "the restored list plus that item", which is the deliberate decision at the call
        // site: the rebuild runs anyway after a partial clear, because a gutted menu is
        // worse than a complete one with a duplicate. Asserting only that the survivor is
        // present would leave that decision unpinned — dropping the rebuild on a partial
        // clear would still satisfy it.
        $live = array_map(static fn($i) => $i->title, wp_get_nav_menu_items($menu_id));
        $this->assertContains('Added by the batch', $live, 'premise: the refused item is still there');
        $this->assertContains('Home', $live, 'and the rebuild still ran, so the snapshot list came back');
        $this->assertCount(2, $live, 'complete menu beside the survivor — exactly what the report describes');
    }

    /**
     * EVERY SHORT-CIRCUIT SPELLING IS A REFUSAL, AND `null` ALONE IS SILENCE.
     *
     * The three-way read in pp_clear_nav_menu_items() is the load-bearing design of the new
     * producer, and a source tripwire cannot prove it: the shapes that matter are ones the
     * ordinary stub never produces. Core returns the `pre_delete_post` filter's value
     * verbatim and documents it as "Anything other than null will short-circuit deletion",
     * so a plugin can hand back `true` — a common "pretend it succeeded" idiom — and a
     * falsiness test would call that a delete that happened. `_pp_test_delete_post_returns`
     * exists so each spelling can be driven directly.
     *
     * @dataProvider preDeletePostShortCircuits
     */
    public function testEveryPreDeletePostShortCircuitLeavesTheItemReported($short_circuit, string $why): void
    {
        $menu_id = wp_create_nav_menu('Main');
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 801, 'title' => 'Stuck', 'url' => 'https://example.com/x', 'menu_order' => 1],
        ];
        $GLOBALS['_pp_test_delete_post_returns'][801] = $short_circuit;

        $this->assertSame([801], pp_clear_nav_menu_items($menu_id), $why);
    }

    public static function preDeletePostShortCircuits(): array
    {
        return [
            'true'         => [true, 'a truthy short-circuit is NOT a delete — this is the one falsiness misses'],
            'zero'         => [0, 'an int short-circuit is a refusal'],
            'empty string' => ['', 'a string short-circuit is a refusal'],
            'false'        => [false, 'the ordinary refusal'],
            // WP_Error is covered by its own test below: a static provider runs before the
            // bootstrap's class definitions are guaranteed, so it cannot construct one.
        ];
    }

    /** WP_Error cannot be built in a static provider before the bootstrap defines it. */
    public function testAWpErrorShortCircuitLeavesTheItemReported(): void
    {
        $menu_id = wp_create_nav_menu('Main');
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 803, 'title' => 'Stuck', 'url' => 'https://example.com/z', 'menu_order' => 1],
        ];
        $GLOBALS['_pp_test_delete_post_returns'][803] = new WP_Error('nope', 'refused');

        $this->assertSame(
            [803],
            pp_clear_nav_menu_items($menu_id),
            'a WP_Error is an object, so a bare is_object() test would call it a success'
        );
    }

    /**
     * THE OTHER ARM, AND IT IS THE ONE THAT MUST STAY SILENT. A row that is provably gone
     * survived nothing; reporting it would be a false entry on the channel this change
     * exists to make trustworthy.
     */
    public function testAnAbsentRowIsNeverReportedAsASurvivor(): void
    {
        $menu_id = wp_create_nav_menu('Main');
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 802, 'title' => 'Gone', 'url' => 'https://example.com/y', 'menu_order' => 1],
        ];
        $GLOBALS['_pp_test_delete_post_returns'][802] = null;

        $this->assertSame([], pp_clear_nav_menu_items($menu_id), 'provably gone: nothing to report');
    }

    /**
     * BOUNDARY — a menu whose clear succeeds reports nothing, and the whole restore is
     * clean. Without this the red proof above is satisfiable by reporting every item.
     */
    public function testAMenuClearedSuccessfullyReportsNothing(): void
    {
        $menu_id  = wp_create_nav_menu('Main');
        $snapshot = (object) [
            'ID' => 601, 'post_title' => 'Home', 'title' => 'Home',
            'type' => 'custom', 'url' => 'https://example.com/', 'menu_order' => 1,
        ];
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            $snapshot,
            (object) ['ID' => 602, 'title' => 'Added by the batch', 'url' => 'https://example.com/new', 'menu_order' => 2],
        ];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => [
                'menus'     => [$menu_id => ['name' => 'Main', 'items' => [$snapshot]]],
                'locations' => [],
            ],
        ]));

        $this->assertSame([], $errors, 'every delete landed, so there is nothing to report');
        $live = array_map(static fn($i) => $i->title, wp_get_nav_menu_items($menu_id));
        $this->assertSame(['Home'], $live, 'and the menu really is back to its snapshot');
    }

    /**
     * THE `null` ARM IS PINNED AT THE SOURCE, because the harness cannot reach it.
     *
     * Core answers NULL when there is no row at that ID (`$post = $wpdb->get_row( ... );
     * if ( ! $post ) { return $post; }`, wp-includes/post.php) and something ELSE falsy when
     * the delete did not happen — the `pre_delete_post` filter is documented as "Anything
     * other than null will short-circuit deletion" and core returns its value verbatim, so
     * `false`, `true`, `0`, `''` and a WP_Error are all reachable short-circuits. Two
     * mistakes are therefore possible and they point opposite ways: testing truthiness alone
     * reports an item that is provably GONE (a false survivor), and testing `=== false`
     * alone reads every other short-circuit as a successful delete (the silence this whole
     * change removes).
     *
     * It is unreachable HERE because the stub's item lookup reads the same store
     * wp_get_nav_menu_items() does, so anything the loop is handed is by construction
     * findable. The ordering is therefore pinned the way RollbackErrorKindsTest pins its own
     * unreachable producers: from the source. Null must be separated FIRST, and everything
     * else falsy must then be recorded.
     */
    public function testTheClearSeparatesAnAbsentRowFromEveryOtherFalsyReturn(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $start  = strpos($source, 'function pp_clear_nav_menu_items(');
        $this->assertNotFalse($start, 'pp_clear_nav_menu_items exists in lib/wp.php');
        $body = substr($source, $start, strpos($source, "\n}", $start) - $start);

        $null_arm = strpos($body, '=== null');
        $this->assertNotFalse($null_arm, 'an absent row is separated explicitly, not by truthiness');
        $this->assertStringContainsString('continue;', substr($body, $null_arm), 'and it reports nothing');

        $fail_arm = strpos($body, '$survivors[] =');
        $this->assertNotFalse($fail_arm, 'a refused delete is recorded');
        $this->assertLessThan(
            $fail_arm,
            $null_arm,
            'the absent-row test must come FIRST — after it, everything falsy is a refusal,'
            . ' which is what makes a pre_delete_post short-circuit of any shape reportable'
        );
        $this->assertStringNotContainsString(
            '=== false',
            $body,
            'a `=== false` test would read a short-circuit returning true/0/\'\'/WP_Error as a'
            . ' successful delete and leave the surviving item unreported'
        );
    }

    /**
     * RED — a menu whose ITEM LIST could not be read is NAMED, and this one is the trap the
     * first version of this change walked into.
     *
     * `wp_get_nav_menu_items()` answers FALSE when the menu term is gone or the taxonomy is
     * not registered — a concurrent deletion during the batch window reaches it. The obvious
     * `$items ?: []` spelling folds that into "this menu had no items", so nothing is
     * deleted, nothing is enumerated, and an EMPTY survivor list means "everything was
     * removed". The rebuild then puts the whole snapshot back on top of rows that were never
     * removed and the report says clean. Distinguishing the two is what makes the empty list
     * mean what its contract claims.
     */
    public function testAMenuWhoseItemListCannotBeReadIsNamed(): void
    {
        $menu_id  = wp_create_nav_menu('Main');
        $snapshot = (object) [
            'ID' => 901, 'post_title' => 'Home', 'title' => 'Home',
            'type' => 'custom', 'url' => 'https://example.com/', 'menu_order' => 1,
        ];
        // The store answers false for this menu's items — core's shape when the term is gone.
        // The signature guard reads it as [] and so does NOT skip, which is what puts the
        // clear on this path in the first place.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = false;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => [
                'menus'     => [$menu_id => ['name' => 'Main', 'items' => [$snapshot]]],
                'locations' => [],
            ],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'its item list could not be read during the rollback',
            'nothing was removed and nothing was enumerated, so an empty survivor list would lie'
        );
    }

    /**
     * AND THE UNIT-LEVEL HALF OF THE SAME DISTINCTION: null is not [].
     */
    public function testTheClearAnswersNullRatherThanEmptyWhenTheListIsUnreadable(): void
    {
        $menu_id = wp_create_nav_menu('Main');
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = false;
        $this->assertNull(pp_clear_nav_menu_items($menu_id), 'unreadable is not empty');

        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [];
        $this->assertSame([], pp_clear_nav_menu_items($menu_id), 'genuinely empty stays empty');
    }

    /**
     * BOUNDARY — the location restore runs even when the MENU LIST is unreadable.
     *
     * That sentence used to be an early `return`, which skipped this write entirely: the
     * location map was left as the batch left it, unattempted and unreported, on the one path
     * where the menu layer is most broken. "Every write this function makes is checked" has
     * to hold on every path.
     */
    public function testTheLocationRestoreStillRunsWhenTheMenuListIsUnreadable(): void
    {
        $GLOBALS['_pp_test_store']['nav_menus'] = new WP_Error('term_fail', 'get_terms failed');
        $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'] = ['primary' => 9];

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => ['menus' => [], 'locations' => ['primary' => 7]],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'menu list unavailable during rollback',
            'the unreadable menu list is still reported'
        );
        $this->assertSame(
            ['primary' => 7],
            $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'],
            'and the location map was still restored rather than skipped with it'
        );
    }

    /**
     * RED — a refused `nav_menu_locations` restore is NAMED.
     *
     * set_theme_mod() has returned update_option()'s bool since WP 5.6, so this write was
     * checkable all along and simply was not checked. A refusal leaves the menus assigned
     * wherever the batch put them.
     */
    public function testARefusedNavMenuLocationRestoreIsNamed(): void
    {
        $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'] = ['primary' => 9]; // what the batch left
        $GLOBALS['_pp_test_unwritable_theme_mods']['nav_menu_locations'] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => ['menus' => [], 'locations' => ['primary' => 7]],
        ]));

        $this->assertOneEntryContaining(
            $errors,
            'navigation location assignments were NOT rolled back',
            'the assignment the batch made is still live, so the rollback must say so'
        );
        // CAUSE-NEUTRAL WORDING. wp_delete_nav_menu() ZEROES a location pointing at a menu
        // the rollback removes, so "the menus this batch assigned are still assigned" is
        // wrong on a reachable path — the location can be EMPTY rather than mis-assigned.
        $this->assertSame(
            [],
            array_values(array_filter($errors, static fn($e) => str_contains($e, 'are still assigned'))),
            'the sentence must not claim a direction it cannot know'
        );
        $this->assertSame(
            ['primary' => 9],
            $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'],
            'premise: the refused write really did leave the batch\'s assignment in place'
        );
    }

    /**
     * BOUNDARY — locations that already match the snapshot are neither written nor
     * reported, and this is the half that matters most.
     *
     * set_theme_mod() ends in update_option(), which returns false for a REFUSED write and
     * for a write with NOTHING TO DO. Keying on the bare return would put a survivor on the
     * channel for every batch that touched menus without touching the location map. The
     * refusal knob is ARMED here on purpose: if the compare-first guard were removed, the
     * write would run, return false, and this test would report — so it cannot pass
     * vacuously the way an assertion on an empty report alone would.
     */
    public function testUnchangedNavMenuLocationsAreNeitherWrittenNorReported(): void
    {
        $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'] = ['primary' => 7];
        $GLOBALS['_pp_test_unwritable_theme_mods']['nav_menu_locations'] = true;

        $errors = _pp_restore_batch_snapshot($this->bundle([
            'menus' => ['menus' => [], 'locations' => ['primary' => 7]],
        ]));

        $this->assertSame([], $errors, 'nothing needed restoring, so nothing is reported');
        $this->assertSame(
            ['primary' => 7],
            $GLOBALS['_pp_test_store']['theme_mods']['nav_menu_locations'],
            'and the stored value is untouched'
        );
    }

    /**
     * SECTION 14.1 — the same failure driven through the REAL batch surface.
     *
     * A menu action runs, a later step fails, the executor rolls back, and the rollback's
     * own delete of the item that action added is refused. What the consumer sees is the
     * ENVELOPE: rolled_back true beside a rollback_errors that is no longer empty. Before
     * this change the same envelope carried `[]` with the batch's menu item still in the
     * menu.
     */
    public function testTheEnvelopeCarriesARefusedMenuRemovalThroughTheRealExecutor(): void
    {
        $menu    = pp_execute_action('create_menu', ['name' => 'Primary']);
        $menu_id = $menu['target']['menu_id'];
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [
            (object) ['ID' => 9601, 'title' => 'Home', 'url' => 'https://example.com/', 'menu_order' => 1],
        ];
        // Every id the batch is about to mint refuses deletion, which is what the
        // rollback's clear hits when it tries to remove the item add_menu_item created.
        $next_id = $GLOBALS['_pp_test_store']['next_id'];
        for ($id = $next_id; $id < $next_id + 20; $id++) {
            $GLOBALS['_pp_test_undeletable_posts'][$id] = true;
        }

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_menu_item', 'params' => [
                'menu_id' => $menu_id, 'url' => 'https://example.com/new', 'label' => 'Added by the batch',
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok'], 'the second step fails');
        $this->assertTrue($batch['rolled_back'], 'so the executor rolls back');
        $survivors = array_values(array_filter(
            $batch['rollback_errors'],
            static fn($e) => str_contains($e, 'could not be removed before the rebuild')
        ));
        $this->assertCount(1, $survivors, 'the surviving menu item is named on the envelope');
        $this->assertSame(
            [PP_ROLLBACK_ERROR_FAILED],
            array_values(array_unique($batch['rollback_error_kinds'])),
            'and it is tagged as a failed revert, not a protective withhold (#855)'
        );
    }
}
