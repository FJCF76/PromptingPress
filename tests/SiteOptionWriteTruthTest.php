<?php
/**
 * tests/SiteOptionWriteTruthTest.php
 *
 * Invariant I1 on the site-option write path, and the advisory lock on the
 * rollback that replays it (#978, #979).
 *
 * TWO DEFECTS, ONE NEIGHBOURHOOD. `pp_update_site_option()` ended in
 * `update_option($key, $value); return true;` — the return discarded, the `true`
 * unconditional. And the batch rollback that restores those same rows wrote
 * `pp_site_udc` with a bare `update_option()`, outside the advisory lock every
 * forward write of that key takes.
 *
 * WHY THE FIRST ONE IS I1 AND NOT TIDINESS. I1 reads: "an envelope's ok/failure
 * claim is the VERIFIED outcome of the store it describes — in both directions:
 * no success over a write that was refused, skipped, or never checked, and no
 * failure reported over an ambiguous API return the code did not disambiguate."
 * Both directions bite here, which is why the fix is not simply
 * `if (!update_option(...)) return new WP_Error(...)`:
 *
 *   - Forward:  core returns false for a REFUSED write. Reporting ok:true over it
 *               ships a `changes[]` row describing a change that did not happen.
 *   - Backward: core ALSO returns false for a write whose value is UNCHANGED, and
 *               a `pre_update_option_*` / `sanitize_option_*` filter can rewrite a
 *               value so the stored bytes already match. Converting either of
 *               those to a failure is the second clause of I1 violated instead of
 *               the first.
 *
 * So the writer compares first (the `_pp_restore_write_if_changed()` idiom this
 * repo already ships at five call sites), and on the remaining false return it
 * DISAMBIGUATES with one read-back before deciding. That is disambiguation of an
 * ambiguous return, not read-back verification of every write — the posture
 * `_pp_restore_write_if_changed()`'s docblock parks with the concurrency cluster
 * stays parked.
 *
 * WHAT IS DELIBERATELY NOT CLAIMED: compare-then-write is not atomic. Another
 * writer can land between the read and the write. This change makes the RETURN
 * honest; it does not make the write a transaction. The one key that needs more
 * than that already has it — `pp_site_udc` carries its own version counter and
 * advisory lock — which is exactly what #979 is about.
 *
 * AUTHORING-PATH MANDATE (Section 14.1): the envelope assertions go through
 * `pp_execute_action('update_site_option')`, the real write surface, because I1 is
 * an invariant about the ENVELOPE. Direct calls are used only to pin the return
 * contract of the writer itself.
 */

use PHPUnit\Framework\TestCase;

class SiteOptionWriteTruthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store']              = [
            'options'             => [],
            'posts'               => [],
            'post_meta'           => [],
            'attachment_urls'     => [],
            'attachment_is_image' => [],
        ];
        $GLOBALS['_pp_test_option_writes']      = [];
        $GLOBALS['_pp_test_unwritable_options'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_pp_test_unwritable_options'],
            $GLOBALS['_pp_test_option_writes']
        );
        parent::tearDown();
    }

    /**
     * THE RED PROOF (#978). A refused write reported success.
     *
     * `pp_logo_alt` is a plain string option, so nothing but the write itself can
     * fail — which is what makes it the honest fixture for "the store said no".
     */
    public function testARefusedSiteOptionWriteIsReportedAsAFailure(): void
    {
        $GLOBALS['_pp_test_unwritable_options']['pp_logo_alt'] = true;

        $result = pp_update_site_option('pp_logo_alt', 'Acme, Inc.');

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            'a write the store refused must not be reported as a success (I1)'
        );
        $this->assertFalse(
            isset($GLOBALS['_pp_test_store']['options']['pp_logo_alt']),
            'the fixture must really not have stored the row'
        );
    }

    /** The refusal carries a code, so the envelope has something to name (I28). */
    public function testTheRefusalNamesAnErrorCode(): void
    {
        $GLOBALS['_pp_test_unwritable_options']['pp_logo_alt'] = true;

        $result = pp_update_site_option('pp_logo_alt', 'Acme, Inc.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('site_option_write_failed', $result->get_error_code());
        $this->assertStringContainsString(
            'pp_logo_alt',
            $result->get_error_message(),
            'the refusal must name the option it could not write'
        );
    }

    /**
     * I1's SECOND clause: an unchanged write is a success, not a failure.
     *
     * Pinned with the WRITE COUNTER rather than the stored value, because the
     * stored value looks identical whether the guard ran or not — counting is the
     * only way to prove the skip.
     */
    public function testWritingTheValueTheOptionAlreadyHoldsSucceedsWithoutWriting(): void
    {
        $this->assertTrue(pp_update_site_option('pp_logo_alt', 'Acme, Inc.'));
        $this->assertSame(1, $GLOBALS['_pp_test_option_writes']['pp_logo_alt'] ?? 0);

        $this->assertTrue(
            pp_update_site_option('pp_logo_alt', 'Acme, Inc.'),
            'an unchanged write is not a failed one'
        );
        $this->assertSame(
            1,
            $GLOBALS['_pp_test_option_writes']['pp_logo_alt'] ?? 0,
            'the unchanged write must be skipped, not retried and misreported'
        );
    }

    /** A real change still writes and still reports success. */
    public function testAChangedValueIsWrittenAndReportedAsASuccess(): void
    {
        $this->assertTrue(pp_update_site_option('pp_logo_alt', 'Acme, Inc.'));
        $this->assertTrue(pp_update_site_option('pp_logo_alt', 'Acme Corporation'));

        $this->assertSame('Acme Corporation', $GLOBALS['_pp_test_store']['options']['pp_logo_alt']);
        $this->assertSame(2, $GLOBALS['_pp_test_option_writes']['pp_logo_alt'] ?? 0);
    }

    /**
     * The compare is against the NORMALIZED value, not the caller's raw string.
     *
     * `attachment_id` canonicalises through `(string) (int)`, so '00042' and '42'
     * are the same stored row. Comparing before normalising would write anyway and
     * make the skip depend on how the caller happened to spell the number.
     */
    public function testTheUnchangedCompareRunsOnTheNormalizedValue(): void
    {
        $GLOBALS['_pp_test_store']['posts'][42]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_urls'][42]     = 'https://example.test/logo.png';
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = true;

        $this->assertTrue(pp_update_site_option('pp_logo_id', '42'));
        $writes = $GLOBALS['_pp_test_option_writes']['pp_logo_id'] ?? 0;

        $this->assertTrue(pp_update_site_option('pp_logo_id', '00042'));
        $this->assertSame(
            $writes,
            $GLOBALS['_pp_test_option_writes']['pp_logo_id'] ?? 0,
            'the same attachment spelled differently is not a change'
        );
    }

    /**
     * I1 IS AN ENVELOPE INVARIANT, so it has to be proven on the envelope.
     *
     * The writer returning WP_Error is necessary and not sufficient: the execute
     * arm has to turn that into ok:false rather than shipping a changes[] row for
     * a change that never landed.
     */
    public function testTheActionEnvelopeReportsTheRefusedWriteAsNotOk(): void
    {
        $GLOBALS['_pp_test_unwritable_options']['pp_logo_alt'] = true;

        $envelope = pp_execute_action('update_site_option', [
            'key'   => 'pp_logo_alt',
            'value' => 'Acme, Inc.',
        ]);

        $this->assertIsArray($envelope);
        $this->assertFalse($envelope['ok'] ?? true, 'a refused write must not report ok:true (I1)');
        $this->assertNotEmpty(
            $envelope['error_code'] ?? '',
            'a refusal must carry an error_code (I28)'
        );
        $this->assertEmpty(
            $envelope['changes'] ?? [],
            'a refused write must not describe a change that did not happen'
        );
    }

    // ── #979: the rollback that replays pp_site_udc must take its lock ──────

    /** A full-shaped snapshot bundle with only the site_options arm populated. */
    private function bundle(array $site_options): array
    {
        return [
            'posts'               => [],
            'compositions'        => [],
            'created_posts'       => [],
            'created_attachments' => [],
            'redirects'           => [],
            'redirects_written'   => [],
            'unreadable'          => [],
            'site_options'        => $site_options,
            'custom_css'          => null,
            'token_overrides'     => null,
            'font_urls'           => null,
            'menus'               => ['locations' => []],
        ];
    }

    /**
     * THE RED PROOF (#979). The restore wrote pp_site_udc with no lock at all.
     *
     * Every FORWARD write of this key runs inside _pp_site_udc_lock_name()
     * (_pp_update_site_udc, lib/wp.php), because the row carries a version counter
     * that a concurrent writer can clobber. The rollback replaying that same row
     * took no lock, so it could land on top of a CAS-guarded write that had just
     * committed — the one writer in the system able to defeat the guarantee ruling
     * A1 introduced.
     */
    public function testTheSiteUdcRollbackTakesTheLockItsForwardWritesTake(): void
    {
        $GLOBALS['wpdb'] = new PP_SiteOptionWriteTruth_RecordingWpdb();
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{"_version":9,"nav":{}}';

        _pp_restore_batch_snapshot_report($this->bundle([
            PP_SITE_UDC_OPTION => ['exists' => true, 'value' => '{"_version":4,"nav":{}}'],
        ]));

        $locks = array_filter(
            $GLOBALS['wpdb']->recorded,
            static fn(string $q): bool => str_contains($q, 'GET_LOCK')
        );
        $this->assertNotEmpty(
            $locks,
            'the site-udc restore must run inside the advisory lock its forward writes take'
        );
    }

    /**
     * And the live compare must read the ROW, not the autoload cache.
     *
     * The forward writer refuses get_option() here on purpose
     * (_pp_read_site_udc_locked): an autoloaded option is served from a per-request
     * snapshot that a concurrent commit cannot invalidate. A rollback comparing
     * against those stale bytes can conclude "already equal" and skip a restore it
     * genuinely owed, then report the rollback clean.
     */
    public function testTheSiteUdcRollbackComparesAgainstTheRowNotTheCache(): void
    {
        $baseline = '{"_version":4,"nav":{}}';
        $wpdb = new PP_SiteOptionWriteTruth_RecordingWpdb();
        // The ROW holds a third party's newer map; the request cache still holds the
        // baseline, so a cached compare reads "nothing to do".
        $wpdb->row_value = '{"_version":11,"nav":{}}';
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = $baseline;
        $GLOBALS['_pp_test_option_writes'][PP_SITE_UDC_OPTION] = 0;

        _pp_restore_batch_snapshot_report($this->bundle([
            PP_SITE_UDC_OPTION => ['exists' => true, 'value' => $baseline],
        ]));

        $this->assertSame(
            1,
            $GLOBALS['_pp_test_option_writes'][PP_SITE_UDC_OPTION] ?? 0,
            'the restore was owed against the real row and must not be skipped on stale cached bytes'
        );
    }

    /**
     * A busy lock is reported, never silent.
     *
     * PP_ROLLBACK_ERROR_FAILED is the ruled vocabulary for it: the discriminator at
     * lib/actions.php:2238-2241 reads "a restore or a removal was owed, was attempted
     * (or WAS IMPOSSIBLE), and did not happen". Owed + impossible + did not happen.
     */
    public function testABusyLockMakesTheSiteUdcRollbackReportAFailureRatherThanSucceedQuietly(): void
    {
        $GLOBALS['wpdb'] = new PP_SiteOptionWriteTruth_LockDeniedWpdb();
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{"_version":9,"nav":{}}';

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            PP_SITE_UDC_OPTION => ['exists' => true, 'value' => '{"_version":4,"nav":{}}'],
        ]));

        // The report is a FLAT LIST of ['kind' => ..., 'message' => ...] entries.
        $this->assertNotEmpty($report, 'a restore that could not be attempted must be reported');
        $this->assertSame(
            PP_ROLLBACK_ERROR_FAILED,
            $report[0]['kind'] ?? '',
            'owed + impossible + did not happen is FAILED, not WITHHELD'
        );
        $this->assertStringContainsString(
            PP_SITE_UDC_OPTION,
            $report[0]['message'] ?? '',
            'the entry must name the option that was not rolled back'
        );
    }

    /** Every OTHER whitelisted key still restores without a lock it never needed. */
    public function testANonChromeSiteOptionRestoresWithoutTakingTheChromeLock(): void
    {
        $GLOBALS['wpdb'] = new PP_SiteOptionWriteTruth_RecordingWpdb();
        $GLOBALS['_pp_test_store']['options']['pp_logo_alt'] = 'after the batch';

        $report = _pp_restore_batch_snapshot_report($this->bundle([
            'pp_logo_alt' => ['exists' => true, 'value' => 'before the batch'],
        ]));

        $this->assertSame('before the batch', get_option('pp_logo_alt'));
        $this->assertSame([], $report, 'a faithful restore reports nothing');
    }
}

/** Records every query so a test can assert the lock was taken, and grants it. */
class PP_SiteOptionWriteTruth_RecordingWpdb extends wpdb
{
    /**
     * Core wpdb declares $options; the harness's stand-in does not, and the locked
     * read is guarded on isset($wpdb->options) exactly as _pp_read_site_udc_locked()
     * is. Without this the row-vs-cache branch is unreachable from a test and the
     * stale-compare defect could not be pinned at all.
     */
    public string $options = 'wp_options';

    /** @var string[] */
    public $recorded = [];

    /** Bytes the pp_site_udc ROW holds, when a test stages a cache/row divergence. */
    public $row_value = null;

    public function get_var(string $query)
    {
        $this->recorded[] = $query;
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        if ($this->row_value !== null && str_contains($query, 'option_value')) {
            return $this->row_value;
        }
        return parent::get_var($query);
    }

    public function query(string $query)
    {
        $this->recorded[] = $query;
        return 1; // RELEASE_LOCK
    }
}

/** Denies the lock, so the restore cannot be attempted at all. */
class PP_SiteOptionWriteTruth_LockDeniedWpdb extends PP_SiteOptionWriteTruth_RecordingWpdb
{
    public function get_var(string $query)
    {
        $this->recorded[] = $query;
        if (str_contains($query, 'GET_LOCK')) {
            return '0'; // busy
        }
        return wpdb::get_var($query);
    }

    /**
     * The writer must see the same row the compare just read.
     *
     * Reading the row authoritatively is only half the lock's job: update_option()
     * does its own get_option() against the autoloaded cache, so without an
     * invalidation inside the section the compare and the write look at two
     * different views of one row — and a restore that was genuinely owed gets
     * skipped and reported FAILED. Found by the security specialist.
     */
    public function testTheSiteUdcRollbackInvalidatesTheCachedRowInsideTheLock(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/actions.php');
        $this->assertIsString($source);

        $start = strpos($source, '$restore_one = static function ($wpdb)');
        $this->assertNotFalse($start, 'the locked restore closure must exist');
        $end = strpos($source, '};', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            "wp_cache_delete($key, 'options')",
            $body,
            'the cached row must be dropped inside the lock, before the write'
        );
        $this->assertLessThan(
            strpos($body, '_pp_restore_write_if_changed'),
            strpos($body, 'wp_cache_delete'),
            'the invalidation must happen BEFORE the compare-and-write, not after'
        );
    }
}
