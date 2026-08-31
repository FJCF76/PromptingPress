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
     * REACHED WITHOUT ANY HARNESS HOOK, because pp_update_seo_meta() re-validates on the way
     * in: a stored meta_description longer than the 320-character cap round-trips out of
     * pp_get_seo_meta() into the snapshot and is then REJECTED on the way back. That is a
     * #233 violation in its own right (a restore blocked by a current validation rule) and
     * is filed separately; what is pinned here is that the rollback no longer hides it.
     */
    public function testARefusedSeoMetaRestoreIsNamed(): void
    {
        $page = $this->page();
        update_post_meta($page, '_pp_seo_meta', wp_json_encode([
            'meta_description' => str_repeat('a', 400),
        ]));
        $state = $this->capturedState($page);
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => 'batch']));

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
}
