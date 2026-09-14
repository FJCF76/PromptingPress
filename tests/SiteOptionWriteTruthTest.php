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
}
