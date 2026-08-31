<?php
/**
 * tests/RollbackErrorKindsTest.php — every `rollback_errors` entry says which of the two
 * things it means (#855).
 *
 * THE BUG THIS PINS. The channel had 23 producers and two meanings, and a consumer
 * received opaque strings:
 *
 *   a restore was OWED and did not happen   ──┐
 *                                             ├──▶ rollback_errors: [ "...", "..." ]
 *   a restore was WITHHELD on purpose       ──┘        one shape, no way to tell
 *
 * Both chat failure exits key on the channel's SIZE, so on the withheld producers the card
 * announced a rollback failure over bytes nothing had failed against — the #756 case most
 * sharply, where the batch found the page unreadable, no pre-batch composition exists to
 * return it to, and leaving the stored bytes alone IS the honest restore. False alarm,
 * where #755 and #797 had just removed the matching false reassurance, out of the same
 * channel.
 *
 * RULING T2 RESOLVES IT ADDITIVELY: each entry carries a `kind`, the envelope gains an
 * index-aligned `rollback_error_kinds` beside an unchanged `rollback_errors`, and the
 * renderer draws the kinds it knows while falling back to today's rendering for the rest.
 * No channel split, no new operator vocabulary — the wording half stays pooled on #664.
 *
 * WHAT IS PINNED HERE, IN FOUR FAMILIES.
 *
 *   THE INVENTORY — one assertion per producer, because the value of the kind is exactly
 *   its correctness at each of the 23 sites and a channel where one site is mislabelled is
 *   worse than one where none is labelled. 5 are withholds (#756, #749, #833, and the two
 *   attachment refusals); 18 are failures.
 *
 *   THE PROJECTIONS — `rollback_errors` is byte-identical to what it was, the two lists are
 *   the same length and both are LISTS (a key-preserving edit upstream makes wp_json_encode
 *   emit a JSON object and the client then refuses to call the channel clean), and the
 *   envelope carries the new key on ALL THREE returns so an absent key means "older server"
 *   and nothing else.
 *
 *   THE SOURCE TRIPWIRES — the twenty-fourth producer is the one this file cannot see.
 *   Every append inside _pp_restore_batch_snapshot_report() must go through
 *   _pp_rollback_entry(), and the menu layer must keep producing bare strings so the single
 *   blanket tag at the merge stays honest.
 *
 *   THE AUTHORING PATH (Section 14.1) — both kinds in ONE report, reached through the real
 *   chat handler rather than a hand-built bundle.
 *
 * WHAT IS DELIBERATELY NOT HERE. Counts and presence: a withheld entry still costs the
 * clean claim, because the rollback was not clean — bytes were left in a state the operator
 * must know about. Everything keyed on the channel's size (ppChatRollbackSentence,
 * ppChatConflictOutcome, the #856 survival branch, _pp_ai_rollback_clause) is untouched by
 * this change and is pinned where it already lives.
 */

use PHPUnit\Framework\TestCase;

/**
 * Grants the advisory lock so real composition writes land, and nothing else. Same shape
 * and same reason as PP_ChatBatchCarveOut_Lockable_Wpdb in
 * tests/ChatBatchCorruptRepairCarveOutTest.php; named separately, by that file's own stated
 * convention, so neither file's harness can drift into the other's expectations.
 */
class PP_RollbackKinds_Lockable_Wpdb extends wpdb
{
    public function get_var(string $query)
    {
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        return parent::get_var($query);
    }

    public function query(string $query)
    {
        return 1; // RELEASE_LOCK
    }
}

/**
 * Denies the composition advisory lock so pp_update_composition() returns
 * WP_Error('composition_lock_failed') and writes NOTHING — the one production failure of
 * that writer reachable without a concurrent process, and the staging #857 uses to reach
 * the REFUSED composition write (the branch that is a failure, not a withhold).
 */
class PP_RollbackKinds_LockDenied_Wpdb extends PP_RollbackKinds_Lockable_Wpdb
{
    public function get_var(string $query)
    {
        if (str_contains($query, 'GET_LOCK')) {
            return '0'; // lock busy: acquired by nobody, granted to nobody
        }
        return parent::get_var($query);
    }
}

class RollbackErrorKindsTest extends TestCase
{
    private const CORRUPT_BYTES = 'NOT_VALID_JSON{{{';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'     => [],
            'posts'         => [],
            'options'       => ['siteurl' => 'https://example.com'],
            'connectors'    => [],
            'next_id'       => 100,
            'wpdb_postmeta' => [],
        ];
        $GLOBALS['wpdb'] = new PP_RollbackKinds_Lockable_Wpdb();
        $GLOBALS['_pp_test_option_writes'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['_pp_test_user_caps'],
            $GLOBALS['_pp_test_undeletable_posts'],
            $GLOBALS['_pp_test_undeletable_attachments'],
            $GLOBALS['_pp_test_unwritable_posts'],
            $GLOBALS['_pp_test_unwritable_options'],
            $GLOBALS['_pp_test_option_writes'],
            $GLOBALS['_pp_test_option_deletes']
        );
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /** A snapshot bundle with every key the restorer reads, mirroring the snapshotter. */
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

    /**
     * A page that WORKED and later went wrong — two real versioned writes, then a raw meta
     * write that breaks the composition. Corrupting from birth would leave no version
     * marker and no history ring, and "recoverable" would stop meaning anything.
     */
    private function corruptPage(string $title): int
    {
        $post_id = pp_create_page($title, 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'v1', 'title' => 'First']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'v2', 'title' => 'Second']]]);
        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $this->assertFalse(pp_get_composition_result($post_id)['ok'], 'premise: corrupt to the cached reader');
        return $post_id;
    }

    /**
     * The kind the report gave the ONE entry containing $needle.
     *
     * Asserting on exactly one match rather than the first is the half that matters: a
     * producer that fired twice, or a needle loose enough to match two different sentences,
     * would otherwise silently pin whichever came first.
     */
    private function kindOf(array $report, string $needle, string $why): string
    {
        $hits = array_values(array_filter(
            $report,
            static fn($entry) => str_contains($entry['message'], $needle)
        ));
        $this->assertCount(1, $hits, $why . ' — got: ' . var_export(array_column($report, 'message'), true));
        return $hits[0]['kind'];
    }

    private function assertWithheld(array $report, string $needle, string $why): void
    {
        $this->assertSame(PP_ROLLBACK_ERROR_WITHHELD, $this->kindOf($report, $needle, $why), $why);
    }

    private function assertFailed(array $report, string $needle, string $why): void
    {
        $this->assertSame(PP_ROLLBACK_ERROR_FAILED, $this->kindOf($report, $needle, $why), $why);
    }

    // ═══ 1. THE INVENTORY — the five withholds ═══════════════════════════════════

    /**
     * PRODUCER (2) — #756, the entry this whole issue is named for. The page was ALREADY
     * unreadable when the batch snapshotted it, so there is no pre-batch composition to
     * return it to and the snapshot is a `[]` stand-in for bytes nobody could decode.
     * Withholding the write costs nothing that was ever owed; before this change the card
     * called that a rollback failure.
     */
    public function testTheCaptureBasedCompositionWithholdIsTaggedWithheld(): void
    {
        $page = $this->page();

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'posts'      => [$page => $this->capturedState($page)],
            'unreadable' => [$page => 'decode_error'],
        ]));

        $this->assertWithheld(
            $report,
            'could not be read when this batch snapshotted them',
            'the #756 withhold is a policy refusal, not a failed revert'
        );
    }

    /**
     * PRODUCER (5) — #749. The page was readable at capture and the bytes went unreadable
     * during the batch, so writing the honest snapshot over them would destroy the only
     * recoverable copy. The rollback declines, deliberately.
     */
    public function testTheMidBatchCompositionWithholdIsTaggedWithheld(): void
    {
        $page     = $this->page();
        $snapshot = $this->bundle(['posts' => [$page => $this->capturedState($page)]]);
        // The row goes corrupt after the capture; the cached copy stays healthy, which is
        // exactly what a request cannot notice through get_post_meta().
        update_post_meta($page, '_pp_composition', self::CORRUPT_BYTES);

        $report = _pp_restore_batch_snapshot_report($snapshot);

        $this->assertWithheld(
            $report,
            'changed to an unreadable state during this batch',
            'the #749 withhold protects the only recoverable copy'
        );
    }

    /**
     * PRODUCER (3) — #833. Nothing is known about the stored bytes at all, because the row
     * could not be read authoritatively. The gate fails closed and the write is withheld:
     * a statement about the READ, not about the page.
     */
    public function testTheUnverifiableCompositionWithholdIsTaggedWithheld(): void
    {
        $page     = $this->page();
        $snapshot = $this->bundle(['posts' => [$page => $this->capturedState($page)]]);
        // No handle, so pp_composition_db_handle() answers null and the re-classify refuses.
        unset($GLOBALS['wpdb']);

        $report = _pp_restore_batch_snapshot_report($snapshot);

        $this->assertWithheld(
            $report,
            'a stored state nobody could confirm',
            'a read the gate could not make is a withhold, not a write that failed'
        );
    }

    /**
     * PRODUCER (8) — the attachment left in place because a page on this batch kept a
     * composition that may reference it. Ruling T1's third clause is a REFUSAL: deleting
     * would break a live page, so the delete is declined and the survivor is named. The
     * file staying in the Media Library is the price of the protection, not a failure.
     */
    public function testTheAttachmentRefusedBecauseOfAWithheldPageIsTaggedWithheld(): void
    {
        $page  = $this->page();
        $media = pp_create_page('Imported', 'draft');
        $GLOBALS['_pp_test_store']['posts'][$media]['post_type'] = 'attachment';

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'posts'               => [$page => $this->capturedState($page)],
            'unreadable'          => [$page => 'decode_error'],
            'created_attachments' => [$media],
        ]));

        $this->assertWithheld(
            $report,
            "Media item {$media} was imported by this batch and was NOT deleted",
            'a delete declined to protect a page is a withhold'
        );
        $this->assertNotNull(get_post($media), 'and the file really is still in the library');
    }

    /**
     * PRODUCER (7) — an ID that no longer addresses an attachment. Force-deleting it would
     * destroy something this batch never created, which is the one outcome T1 rules out
     * absolutely, so the rollback refuses on purpose. The sentence already says "did NOT
     * delete it" rather than "could not".
     */
    public function testTheAttachmentIdThatIsNoLongerAnAttachmentIsTaggedWithheld(): void
    {
        $bystander = pp_create_page('Innocent bystander', 'publish');

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'created_attachments' => [$bystander],
        ]));

        $this->assertWithheld(
            $report,
            'no longer an attachment',
            'a delete refused to protect an unrelated post is a withhold'
        );
        $this->assertNotNull(get_post($bystander), 'and the page really was not deleted');
    }

    // ═══ 2. THE INVENTORY — the eighteen failures ════════════════════════════════

    /**
     * PRODUCER (9) — a page this batch CREATED whose delete was refused, so a page the
     * operator never asked to keep is live on the site.
     */
    public function testASurvivingCreatedPageIsTaggedFailed(): void
    {
        $created = $this->page('Brand new', 'brand-new');
        $GLOBALS['_pp_test_undeletable_posts'][$created] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle(['created_posts' => [$created]]));

        $this->assertFailed(
            $report,
            "Page {$created} was created by this batch and could NOT be deleted",
            'a removal that was owed and refused is a failure'
        );
    }

    /**
     * PRODUCER (10) — the composition write that was ATTEMPTED and refused. The one
     * composition branch that is not a withhold, and the sentence already spells the
     * difference out in words ("the rollback tried and could not"). This is the pin that
     * proves the kind follows the branch rather than the topic: four composition sentences,
     * three withholds and this one failure.
     */
    public function testARefusedCompositionWriteIsTaggedFailed(): void
    {
        $page  = $this->page();
        $state = $this->capturedState($page);
        pp_update_composition($page, [['component' => 'hero', 'props' => ['title' => 'Mid-batch']]]);

        $GLOBALS['wpdb'] = new PP_RollbackKinds_LockDenied_Wpdb();
        $report = _pp_restore_batch_snapshot_report($this->bundle(['posts' => [$page => $state]]));

        $this->assertFailed(
            $report,
            "Page {$page}: its composition was NOT rolled back: the restoring write was refused",
            'the rollback tried and could not, which is a failure'
        );
    }

    /**
     * PRODUCERS (11), (12), (13) — the title, slug and status restores, all three riding
     * wp_update_post(). Each attempted a write the rollback owed and did not land it.
     */
    public function testRefusedTitleSlugAndStatusRestoresAreTaggedFailed(): void
    {
        $page  = $this->page('Original title', 'original-slug');
        $state = $this->capturedState($page);
        $GLOBALS['_pp_test_store']['posts'][$page]['post_title']  = 'Batch title';
        $GLOBALS['_pp_test_store']['posts'][$page]['post_name']   = 'batch-slug';
        $GLOBALS['_pp_test_store']['posts'][$page]['post_status'] = 'publish';
        $GLOBALS['_pp_test_unwritable_posts'][$page] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle(['posts' => [$page => $state]]));

        $this->assertFailed($report, "Page {$page}: its title was NOT rolled back", 'title');
        $this->assertFailed($report, "Page {$page}: its slug (permalink) was NOT rolled back", 'slug');
        $this->assertFailed($report, "Page {$page}: its published/draft status was NOT rolled back", 'status');
    }

    /**
     * PRODUCER (15) — the slug that landed DIFFERENT because the previous one was taken.
     * The write SUCCEEDED, and it is still a failure: the kind describes the RESTORE, not
     * the write, and this page did not get its permalink back.
     */
    public function testADeduplicatedSlugIsTaggedFailed(): void
    {
        $page  = $this->page('Target', 'shared-slug');
        $state = $this->capturedState($page);
        $GLOBALS['_pp_test_store']['posts'][$page]['post_name'] = 'batch-slug';
        $this->page('Squatter', 'shared-slug');

        $report = _pp_restore_batch_snapshot_report($this->bundle(['posts' => [$page => $state]]));

        $this->assertFailed(
            $report,
            'rolled back to a DIFFERENT value',
            'a restore that landed somewhere else is a failure, not a protection'
        );
    }

    /** PRODUCER (14) — the SEO-metadata restore whose write was refused. */
    public function testARefusedSeoMetaRestoreIsTaggedFailed(): void
    {
        $page = $this->page();
        update_post_meta($page, '_pp_seo_meta', wp_json_encode([
            'meta_description' => str_repeat('a', 400),
        ]));
        $state = $this->capturedState($page);
        update_post_meta($page, '_pp_seo_meta', wp_json_encode(['meta_description' => 'batch']));

        $report = _pp_restore_batch_snapshot_report($this->bundle(['posts' => [$page => $state]]));

        $this->assertFailed($report, "Page {$page}: its SEO metadata was NOT rolled back", 'SEO metadata');
    }

    /** PRODUCER (16), write arm — a whitelisted site option whose restoring write was refused. */
    public function testARefusedSiteOptionWriteIsTaggedFailed(): void
    {
        update_option('blogname', 'Batch name');
        $GLOBALS['_pp_test_unwritable_options']['blogname'] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'site_options' => ['blogname' => ['exists' => true, 'value' => 'Original name']],
        ]));

        $this->assertFailed($report, 'The site setting "blogname" was NOT rolled back', 'option write');
    }

    /** PRODUCER (16), delete arm — an option the batch INVENTED whose row could not be removed. */
    public function testARefusedSiteOptionDeleteIsTaggedFailed(): void
    {
        update_option('pp_logo_alt', 'Invented by the batch');
        $GLOBALS['_pp_test_unwritable_options']['pp_logo_alt'] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'site_options' => ['pp_logo_alt' => ['exists' => false, 'value' => '']],
        ]));

        $this->assertFailed($report, 'The site setting "pp_logo_alt" was NOT rolled back', 'option delete');
    }

    /** PRODUCER (5, #854) — a redirect row whose restoring write was refused. */
    public function testARefusedRedirectWriteIsTaggedFailed(): void
    {
        pp_create_redirect('/created', '/wherever', 301);
        $GLOBALS['_pp_test_unwritable_options'][PP_REDIRECTS_OPTION] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'redirects'         => ['/created' => ['exists' => false, 'entry' => null]],
            'redirects_written' => ['/created'],
        ]));

        $this->assertFailed($report, 'The redirect for "/created" was NOT rolled back', 'redirect map');
    }

    /** PRODUCERS (17) and (18) — the design-token overrides and the custom font URLs. */
    public function testRefusedTokenAndFontRestoresAreTaggedFailed(): void
    {
        update_option('pp_token_overrides', ['--color-accent' => '#batch']);
        update_option('pp_font_urls', ['https://example.com/batch.css']);
        $GLOBALS['_pp_test_unwritable_options']['pp_token_overrides'] = true;
        $GLOBALS['_pp_test_unwritable_options']['pp_font_urls']       = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'token_overrides' => ['--color-accent' => '#original'],
            'font_urls'       => ['https://example.com/original.css'],
        ]));

        $this->assertFailed($report, 'design token overrides were NOT rolled back', 'tokens');
        $this->assertFailed($report, 'custom font URLs were NOT rolled back', 'fonts');
    }

    /** PRODUCER (19), write arm — the Custom CSS restore whose write was refused. */
    public function testARefusedCustomCssRestoreIsTaggedFailed(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '';
        $GLOBALS['_pp_test_unwritable_posts'][999] = true; // the harness's virtual CSS post

        $report = _pp_restore_batch_snapshot_report($this->bundle(['custom_css' => 'body { color: red }']));

        $this->assertFailed($report, 'the restoring write was refused', 'Custom CSS write');
    }

    /**
     * PRODUCER (19), vanished-post arm — and the one failure where NO write was attempted,
     * which is why it earns its own pin. A withhold protects the stored bytes; here the
     * stylesheet is already gone and the rollback could not bring it back. That is a loss,
     * so it is a failure — the discriminator is "was a restore owed and not made", never
     * "was a write issued".
     */
    public function testAVanishedCustomCssPostIsTaggedFailedEvenThoughNoWriteWasTried(): void
    {
        $report = _pp_restore_batch_snapshot_report($this->bundle(['custom_css' => 'body { color: red }']));

        $this->assertFailed($report, 'the Custom CSS post no longer exists', 'nowhere to write it back');
    }

    /** PRODUCER (6) — an imported attachment whose delete was refused. */
    public function testAnUndeletableAttachmentIsTaggedFailed(): void
    {
        $media = pp_create_page('Imported', 'draft');
        $GLOBALS['_pp_test_store']['posts'][$media]['post_type'] = 'attachment';
        $GLOBALS['_pp_test_undeletable_attachments'][$media] = true;

        $report = _pp_restore_batch_snapshot_report($this->bundle(['created_attachments' => [$media]]));

        $this->assertFailed(
            $report,
            "Media item {$media} was imported by this batch and could NOT be deleted",
            'a delete that was owed and refused is a failure'
        );
    }

    /**
     * PRODUCER (1) — the menu layer, tagged at the merge.
     *
     * ONE PIN COVERS ITS THREE SENTENCES because there is one tagging site: the merge loop
     * maps every string _pp_restore_menu_state() returns. The other two — a menu list the
     * layer could not READ (wp_get_nav_menus failing) and a batch-created menu it could not
     * DELETE — are unreachable through this harness (its wp_get_nav_menus() is typed
     * `: array` and its wp_delete_nav_menu() cannot refuse a menu it just listed), so the
     * source tripwire below is what carries them: it proves the layer emits bare strings
     * and that the merge is the only place a kind is attached to any of them.
     */
    public function testAMenuItemThatCouldNotBeRecreatedIsTaggedFailed(): void
    {
        $menu_id = wp_create_nav_menu('Main');
        $item    = (object) [
            'ID'         => 501,
            'post_title' => 'Doomed',
            'title'      => 'Doomed',
            'type'       => 'custom',
            'url'        => 'https://example.com/doomed',
            'menu_order' => 1,
        ];
        // The live menu differs from the snapshot, so the signature guard does NOT skip the
        // rebuild — and the rebuild is then refused for this item's title.
        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = [];
        $GLOBALS['_pp_test_store']['fail_menu_item_titles']    = ['Doomed'];

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'menus' => [
                'menus'     => [$menu_id => ['name' => 'Main', 'items' => [$item]]],
                'locations' => [],
            ],
        ]));

        $this->assertFailed(
            $report,
            'could not recreate menu item "Doomed"',
            'the menu layer reports only what it could not restore, which is a failure'
        );
    }

    /**
     * THE ONE UNTYPED BOUNDARY, AND IT MUST NOT FATAL. _pp_rollback_entry() takes a typed
     * string, and the menu layer is the only place the report tags a value it did not
     * author. This theme declares no strict_types, so a scalar coerces silently — but an
     * array or an object would throw INSIDE the reporter, on the failure path, replacing the
     * operator's entire survivor report with a stack trace. Unreachable through the shipped
     * menu layer (every producer there is a sprintf), reachable through the hand-built
     * bundles this function's docblock invites, and pinned because the cost is the whole
     * report rather than one entry.
     */
    public function testANonScalarMessageDegradesRatherThanFatals(): void
    {
        // DRIVEN AT THE HELPER, WHICH IS WHERE THE GUARD IS. Pushing an array through the
        // menu layer cannot reach it — every producer there is a sprintf() result — so a test
        // that staged a menu and then asserted a count would exercise nothing and pass
        // against a bare `$message`. This calls the boundary directly.
        $entry = _pp_rollback_entry(PP_ROLLBACK_ERROR_FAILED, ['not', 'a', 'string']);

        $this->assertSame(PP_ROLLBACK_ERROR_FAILED, $entry['kind'], 'the kind is still stated');
        $this->assertSame('', $entry['message'], 'and the message degrades instead of throwing');

        // A SCALAR STILL COERCES, which is what the old string hint did silently.
        $this->assertSame('42', _pp_rollback_entry(PP_ROLLBACK_ERROR_FAILED, 42)['message']);

        // AND THE SLOT SURVIVES, which is the load-bearing half: the count is what carries
        // the dirty claim, so an unrenderable entry must not shorten the channel.
        $this->assertSame(
            ['', 'ok'],
            _pp_rollback_messages([
                _pp_rollback_entry(PP_ROLLBACK_ERROR_FAILED, (object) ['x' => 1]),
                _pp_rollback_entry(PP_ROLLBACK_ERROR_FAILED, 'ok'),
            ]),
            'an unrenderable entry keeps its slot rather than shortening the channel'
        );
    }

    // ═══ 3. THE PROJECTIONS ══════════════════════════════════════════════════════

    /**
     * THE COMPAT PROOF ITSELF. `_pp_restore_batch_snapshot()` returns exactly the strings
     * the report carries, in the same order — so every consumer that predates #855, and
     * every suite that drives these branches, reads what it always read. "Additive" is a
     * claim about this function.
     */
    public function testTheStringViewIsExactlyTheReportsMessagesInOrder(): void
    {
        // ONE RUN OF THE RESTORER, NOT TWO. Running a state-mutating restorer twice and
        // comparing the results holds only while every write the bundle stages is refused;
        // a future producer that fires once (a delete that removes its own subject) would
        // turn this into a false red that reads like a projection bug. The projection is
        // proved from a single report, and the delegation itself is proved from the source.
        $report  = _pp_restore_batch_snapshot_report($this->mixedBundle());
        $strings = _pp_rollback_messages($report);

        $this->assertGreaterThan(1, count($report), 'premise: the bundle really produces several entries');
        $this->assertSame(array_column($report, 'message'), $strings, 'the string view is a pure projection');
        $this->assertSame(array_keys($strings), range(0, count($strings) - 1), 'and it is still a list');

        // AND THE SHIM IS THAT PROJECTION AND NOTHING ELSE — no second assembly path that
        // could drift from the report's 23-branch decision.
        $this->assertSame(
            'return _pp_rollback_messages(_pp_restore_batch_snapshot_report($snapshot));',
            trim($this->functionSource('_pp_restore_batch_snapshot'), "{}\n "),
            'the string view stays a one-line delegation'
        );
    }

    /**
     * THE ALIGNMENT CONTRACT, which is the whole value of a parallel field: kind i
     * describes message i. Equal lengths and list-ness are both load-bearing — a
     * key-preserving edit upstream would make wp_json_encode emit a JSON OBJECT for either
     * key, and the client refuses to call a non-list channel clean.
     */
    public function testTheTwoEnvelopeListsAreEqualLengthAlignedLists(): void
    {
        $report   = _pp_restore_batch_snapshot_report($this->mixedBundle());
        $messages = _pp_rollback_messages($report);
        $kinds    = _pp_rollback_kinds($report);

        $this->assertSame(count($messages), count($kinds), 'one kind per message');
        $this->assertTrue(array_is_list($messages), 'messages stay a JSON array');
        $this->assertTrue(array_is_list($kinds), 'kinds stay a JSON array');
        foreach ($kinds as $i => $kind) {
            $this->assertContains($kind, [PP_ROLLBACK_ERROR_WITHHELD, PP_ROLLBACK_ERROR_FAILED], "entry {$i}");
            $this->assertSame($report[$i]['kind'], $kind, "entry {$i} kept its own kind");
            $this->assertSame($report[$i]['message'], $messages[$i], "entry {$i} kept its own message");
        }
        $this->assertContains(PP_ROLLBACK_ERROR_WITHHELD, $kinds, 'premise: the bundle carries a withhold');
        $this->assertContains(PP_ROLLBACK_ERROR_FAILED, $kinds, 'premise: and a failure');
    }

    /**
     * THE COLUMN HELPER CANNOT SHORTEN, AND CANNOT INVENT A WITHHOLD.
     *
     * array_column() — the obvious spelling — SKIPS a row missing the requested key, so one
     * malformed entry would silently shorten the kinds list and shift every kind after it
     * onto the wrong message. That failure is quiet and it points the wrong way: it
     * relabels a failure as a withhold. array_map cannot change the length, and the
     * fallback is the kind whose rendering is today's.
     */
    public function testTheColumnHelperKeepsLengthAndFallsBackToFailed(): void
    {
        // KEYED, NOT A LIST, and that is the half that tests the array_values() call rather
        // than assuming it. array_map() with ONE array PRESERVES keys, so without that call
        // a key-preserving report would project to a key-preserving pair and wp_json_encode
        // would emit JSON OBJECTS for both — at which point the client refuses to call the
        // channel clean and the card goes quiet. A list input can never fail that assertion.
        $entries = [
            3 => _pp_rollback_entry(PP_ROLLBACK_ERROR_WITHHELD, 'first'),
            7 => ['message' => 'no kind at all'],
            9 => ['kind' => PP_ROLLBACK_ERROR_WITHHELD],
            11 => 'not an entry at all',
        ];

        $messages = _pp_rollback_messages($entries);
        $kinds    = _pp_rollback_kinds($entries);

        $this->assertTrue(array_is_list($messages), 'a keyed report still projects to a JSON array');
        $this->assertTrue(array_is_list($kinds), 'both halves, or the pair encodes as objects');
        $this->assertCount(4, $messages, 'no entry is dropped');
        $this->assertCount(4, $kinds, 'and the two lists stay the same length');
        $this->assertSame(
            [PP_ROLLBACK_ERROR_WITHHELD, PP_ROLLBACK_ERROR_FAILED, PP_ROLLBACK_ERROR_WITHHELD, PP_ROLLBACK_ERROR_FAILED],
            $kinds,
            'an entry that cannot state its kind gets the one that renders as it always did'
        );
        $this->assertSame(['first', 'no kind at all', '', ''], $messages);
    }

    /**
     * PRESENT ON EVERY RETURN, so an ABSENT key means "an older server" and nothing else.
     * All three exits are covered: the #749 up-front refusal where no step ran, the
     * executed failure that rolls back, and the successful batch.
     */
    public function testEveryEnvelopeReturnCarriesTheKindsKey(): void
    {
        $healthy = $this->page('Healthy', 'healthy');
        $success = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $healthy, 'title' => 'Renamed']],
        ]);
        $this->assertTrue($success['ok'], 'premise: the batch ran clean');
        $this->assertSame([], $success['rollback_error_kinds'], 'the clean envelope carries an empty list');

        $failed = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $healthy, 'title' => 'Again']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);
        $this->assertFalse($failed['ok'], 'premise: the second step fails');
        $this->assertArrayHasKey('rollback_error_kinds', $failed, 'the rollback envelope carries the key');

        $corrupt = $this->corruptPage('Refused up front');
        $refused = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $corrupt, 'title' => 'Nope']],
        ]);
        $this->assertFalse($refused['ok'], 'premise: the #749 gate refused before step 1');
        $this->assertSame([], $refused['steps'], 'premise: and no step ran');
        $this->assertSame([], $refused['rollback_error_kinds'], 'the refusal envelope carries an empty list');
    }

    // ═══ 4. SOURCE TRIPWIRES — the producer this file cannot see ═════════════════

    /**
     * EVERY APPEND GOES THROUGH _pp_rollback_entry().
     *
     * Twenty-three producers is twenty-three chances to append a bare string, and a bare
     * string on this channel is indistinguishable from a deliberate `failed` — the exact
     * ambiguity #855 exists to remove, re-entered one producer at a time. The behavioural
     * pins above can only cover the producers that exist today; this covers the next one.
     */
    public function testEveryReportAppendIsBuiltByTheSharedEntryHelper(): void
    {
        $body = $this->functionSource('_pp_restore_batch_snapshot_report');

        preg_match_all('/\$entries\[\]\s*=\s*([A-Za-z_]+)\s*\(/', $body, $matches);
        $this->assertNotEmpty($matches[1], 'the report really does append entries');
        $this->assertSame(
            [],
            array_values(array_unique(array_diff($matches[1], ['_pp_rollback_entry']))),
            'every append must state a kind through _pp_rollback_entry()'
        );
        $this->assertSame(
            substr_count($body, '$entries[] ='),
            count($matches[1]),
            'and no append may take a different shape that this pattern cannot see'
        );
        // THE SIDE DOORS THE COUNT COMPARISON ABOVE CANNOT SEE. Every shape that still spells
        // `$entries[] =` — a bare variable, an array literal, a ternary, a multi-line call —
        // lands in the substring count, misses the call pattern, and fails this test loudly.
        // These do not spell it at all, so they would append a kindless entry and pass both
        // checks silently. array_merge is the likeliest of them, because it is the spelling
        // the pre-#855 code used for this very merge (`return array_merge($errors, ...)`).
        //
        // FROZEN BY COUNT, WHICH IS WHAT MAKES THE LIST EXHAUSTIVE. Naming forbidden
        // spellings one at a time is a game the next spelling wins; pinning the total number
        // of times `$entries` is touched means ANY new way of reaching it has to come through
        // here. The initializer plus one mention per append is the whole budget.
        $this->assertSame(
            2 + count($matches[1]),
            substr_count($body, '$entries'),
            'the only mentions of $entries are its initializer, the counted appends and the'
            . ' return — an array_merge, an array_splice or a reassignment would show up here'
        );
    }

    /**
     * THE MENU LAYER STILL EMITS BARE STRINGS, which is what makes one blanket tag at the
     * merge honest. Every producer there reports something it could not READ, DELETE or
     * RECREATE — there is no policy-withhold branch — so `failed` is true for all three.
     * The day a withhold is added there, this tripwire is what fails: the layer would have
     * to carry its own kinds, and the blanket tag would be mislabelling it.
     */
    public function testTheMenuLayerProducesNoKindsOfItsOwn(): void
    {
        // THE PRODUCER COUNT IS THE HALF THAT ACTUALLY GUARDS THE BLANKET TAG. Asserting the
        // layer names no kind catches a maintainer who reaches for one; it does NOT catch the
        // failure this exists for, which arrives as an ordinary `$errors[] = '...'` — a
        // protective decline that names nothing, trips nothing, and is silently tagged
        // `failed` a thousand lines away. Freezing the count means a new producer THERE has to
        // be looked at HERE, which is the only place the kind is decided.
        $expected_producers = ['_pp_restore_menu_state' => 3, '_pp_rebuild_menu_items' => 2];
        foreach ($expected_producers as $fn => $sites) {
            $body = $this->functionSource($fn);
            $this->assertStringNotContainsString('_pp_rollback_entry', $body, "{$fn} tags nothing itself");
            $this->assertStringNotContainsString('PP_ROLLBACK_ERROR_', $body, "{$fn} names no kind");
            $this->assertSame(
                $sites,
                substr_count($body, '$errors[] =') + substr_count($body, "return ['menu list unavailable"),
                "{$fn} grew or lost a producer — decide its kind at the merge in"
                . ' _pp_restore_batch_snapshot_report() before updating this count'
            );
        }

        // SLICED, NOT REGEX-MATCHED ON ONE LINE. The loop's exact formatting is not the
        // contract and pinning it makes this fail for a re-wrap; what IS the contract is
        // that the loop over the menu layer's return tags FAILED and nothing else.
        $merge = $this->functionSource('_pp_restore_batch_snapshot_report');
        $start = strpos($merge, 'foreach (_pp_restore_menu_state(');
        $this->assertNotFalse($start, 'the merge still loops over the menu layer');
        $loop = substr($merge, $start, strpos($merge, "\n        }", $start) - $start);
        $this->assertStringContainsString('_pp_rollback_entry(', $loop, 'and tags what it merges');
        $this->assertStringContainsString(
            'PP_ROLLBACK_ERROR_FAILED',
            $loop,
            'the merge is the single site that tags the menu layer, and it tags it failed'
        );
        $this->assertStringNotContainsString(
            'PP_ROLLBACK_ERROR_WITHHELD',
            $loop,
            'the menu layer has no policy-withhold branch, so the merge must not invent one'
        );
    }

    /**
     * THE TOKEN CROSSES A WIRE, SO IT NEEDS A PIN THAT CROSSES ONE TOO.
     *
     * PP_ROLLBACK_ERROR_WITHHELD (lib/actions.php) and PP_CHAT_ROLLBACK_KIND_WITHHELD
     * (assets/js/pp-ai-chat.js) must hold the same literal, and there is no shared module to
     * make that automatic. Every other pin in this file asserts through the PHP constant and
     * every pin in the JS suite asserts through the JS constant — so changing ONE of the two
     * values leaves BOTH suites green while silently disabling the whole feature: no entry
     * ever matches, every row falls back to `failed`, and the card is the pre-#855 card
     * again. A regression that both test suites call a pass is exactly the kind this repo
     * pins across languages.
     */
    public function testTheWithheldTokenMatchesTheOneTheClientLooksFor(): void
    {
        $client = file_get_contents(dirname(__DIR__) . '/assets/js/pp-ai-chat.js');
        $this->assertMatchesRegularExpression(
            '/var PP_CHAT_ROLLBACK_KIND_WITHHELD = \'([^\']+)\';/',
            $client,
            'the client still declares the token it matches on'
        );
        preg_match('/var PP_CHAT_ROLLBACK_KIND_WITHHELD = \'([^\']+)\';/', $client, $m);

        $this->assertSame(
            PP_ROLLBACK_ERROR_WITHHELD,
            $m[1],
            'the server tags withholds with a token the client does not recognize, so every'
            . ' withheld entry would draw as a failure and #855 would be silently reverted'
        );
    }

    /** The source of one function in lib/actions.php, brace-matched from its signature. */
    private function functionSource(string $name): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/actions.php');
        $start  = strpos($source, "function {$name}(");
        $this->assertNotFalse($start, "{$name} exists in lib/actions.php");
        $open   = strpos($source, '{', $start);
        $depth  = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') $depth++;
            if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        $this->fail("could not brace-match {$name}");
    }

    // ═══ 5. THE AUTHORING PATH (Section 14.1) ════════════════════════════════════

    /**
     * BOTH KINDS IN ONE REPORT, THROUGH THE REAL CHAT HANDLER — the shape #855 exists for,
     * and the one a hand-built bundle can only imitate.
     *
     * The batch is the corrupt-page repair carve-out (ruling D-1): a single
     * `update_composition` on a page that is already unreadable, which #756 admits and
     * every other unreadable batch is refused before step 1. It carries a stale CAS
     * baseline, so the step comes back `composition_conflict` and the executor rolls back.
     * The rollback then produces both meanings against the SAME page:
     *
     *   the composition ──▶ WITHHELD  nothing was owed; the snapshot is a stand-in for
     *                                 bytes nobody could decode, and leaving them alone is
     *                                 the honest restore
     *   the title       ──▶ FAILED    a write the rollback owed, refused
     *   the status      ──▶ FAILED    likewise
     *
     * That is the report the card used to draw entirely in the failure colour: three
     * sentences, one of which is a protection and two of which are real.
     */
    public function testTheChatEnvelopeCarriesBothKindsInOneReport(): void
    {
        $post_id = $this->corruptPage('Conflicted repair');
        $steps   = [['type' => 'action', 'name' => 'update_composition', 'params' => [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['id' => 'r', 'title' => 'Recovered']]],
        ]]];
        $stale = pp_get_composition_marker($post_id)['version'] - 1;

        $this->assertGreaterThanOrEqual(1, $stale, 'premise: the stale baseline is a real one');
        $this->assertSame($post_id, _pp_batch_corrupt_repair_admitted($steps), 'premise: admitted while corrupt');

        // The batch moved the title, and the rollback's write of the previous one is refused.
        $GLOBALS['_pp_test_store']['posts'][$post_id]['post_title'] = 'Batch title';
        $GLOBALS['_pp_test_unwritable_posts'][$post_id] = true;

        $resp = _pp_ai_execute_batch_response([
            'steps'     => json_encode($steps),
            'baselines' => json_encode([$post_id => $stale]),
        ]);

        $this->assertTrue($resp['ok'], 'the batch was admitted and ran');
        $batch = $resp['data'];
        $this->assertFalse($batch['ok']);
        $this->assertSame(0, $batch['failed_at'], 'premise: the step ran and lost the CAS');
        $this->assertTrue($batch['rolled_back']);

        $messages = $batch['rollback_errors'];
        $kinds    = $batch['rollback_error_kinds'];
        $this->assertSame(count($messages), count($kinds), 'the envelope stays aligned end to end');
        $this->assertTrue(array_is_list($kinds), 'and the new key is a JSON array');

        $byKind = [];
        foreach ($messages as $i => $message) {
            $byKind[$kinds[$i]][] = $message;
        }
        $this->assertArrayHasKey(PP_ROLLBACK_ERROR_WITHHELD, $byKind, 'the withheld composition is on the envelope');
        $this->assertArrayHasKey(PP_ROLLBACK_ERROR_FAILED, $byKind, 'and so is a real failed revert');
        $this->assertCount(1, $byKind[PP_ROLLBACK_ERROR_WITHHELD], 'exactly one composition sentence');
        $this->assertStringContainsString(
            'could not be read when this batch snapshotted them',
            $byKind[PP_ROLLBACK_ERROR_WITHHELD][0],
            'and it is the #756 withhold, the producer whose alarm was false'
        );
        $this->assertStringContainsString(
            'its title was NOT rolled back',
            implode(' | ', $byKind[PP_ROLLBACK_ERROR_FAILED]),
            'beside a restore that really did fail'
        );

        // AND THE COUNTS ARE UNCHANGED, which is the half T2 does NOT relax. A withheld
        // entry still costs the clean claim — the rollback was not clean, bytes were left
        // somewhere the operator has to know about — so nothing anywhere filters the channel
        // by kind. The model-facing clause is the cheapest place to prove it: it counts
        // entries and it still counts all three, the withhold included.
        $this->assertCount(3, $messages, 'one withhold and two failed field restores');
        $this->assertSame(
            'The rollback reported 3 errors, so some changes may not have been reverted.',
            _pp_ai_rollback_clause($batch),
            'the count-keyed consumers see the same channel they always did'
        );
        $this->assertSame(
            self::CORRUPT_BYTES,
            get_post_meta($post_id, '_pp_composition', true),
            'and the only recoverable copy of those bytes is still there'
        );
    }

    /**
     * A bundle that produces one withhold and several failures, shared by the projection
     * pins so each states only the thing it is about.
     */
    private function mixedBundle(): array
    {
        $page = $this->page('Mixed', 'mixed');
        update_option('blogname', 'Batch name');
        $GLOBALS['_pp_test_unwritable_options']['blogname'] = true;
        $GLOBALS['_pp_test_unwritable_posts'][$page]        = true;
        $GLOBALS['_pp_test_store']['posts'][$page]['post_title'] = 'Batch title';

        return $this->bundle([
            'posts'        => [$page => [
                'title'       => 'Mixed',
                'slug'        => 'mixed',
                'status'      => 'draft',
                'composition' => [],
                'seo_meta'    => [],
            ]],
            'unreadable'   => [$page => 'decode_error'],
            'site_options' => ['blogname' => ['exists' => true, 'value' => 'Original name']],
        ]);
    }
}
