<?php
/**
 * tests/TokenLockTest.php
 *
 * #97: the three pp_token_overrides writers (set / clear / clear-all) do a
 * read-modify-write on one option, so concurrent applies could last-writer-wins and
 * silently lose updates. They now serialize through a connection-scoped MySQL advisory
 * lock (GET_LOCK). The Brain Monkey suite runs single-process with no real $wpdb, so we
 * inject a mock $wpdb that records GET_LOCK/RELEASE_LOCK to prove the lock contract:
 *   - the lock is acquired before, and released after, the write (correct order);
 *   - an install-scoped lock name is used;
 *   - on acquisition failure the write does NOT happen and no RELEASE_LOCK is issued;
 *   - the lock is released even when the mutator throws (finally).
 *
 * True two-process interleaving needs a real WP+MySQL harness (tracked as a TODO) —
 * this proves the primitive is wired correctly, not that MySQL serializes under load.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

/** Minimal $wpdb stand-in that records lock SQL and returns a scripted GET_LOCK result. */
class PP_Mock_Wpdb
{
    public array $calls = [];
    /** @var mixed GET_LOCK return: '1' acquired, '0' timeout, null error. */
    public $get_lock_return = '1';
    public string $dbname = 'pp_test_db';
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';
    /** @var mixed Scripted DB value for the in-lock _pp_composition_version read (#113). */
    public $db_composition_version = null;
    /** @var array|null The pp_token_overrides row the DB returns (null = no row). */
    public $db_overrides = null;
    /**
     * @var string|null Verbatim option_value bytes to return for the pp_token_overrides
     * read, bypassing serialize(). Set this to simulate a corrupt/truncated/hand-edited
     * row (anything that does not maybe_unserialize() to an array). Takes precedence over
     * $db_overrides when non-null.
     */
    public $db_overrides_raw = null;
    /**
     * @var bool When true, the pp_token_overrides option SELECT simulates a DB read
     * FAILURE (#212): get_var() returns null AND sets $last_error non-empty, exactly as
     * wpdb does on a query error. Distinct from an absent row (null + empty last_error).
     */
    public bool $fail_option_read = false;
    /**
     * @var string Mirrors wpdb::$last_error. wpdb::query() flushes this to '' at the start
     * of every query and repopulates it on error, so get_var() below resets it per call.
     */
    public string $last_error = '';

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $a) {
            // Substitute the first remaining %s (quoted) or %d (bare), in order, like wpdb.
            $query = preg_replace_callback('/%[sd]/', function ($m) use ($a) {
                return $m[0] === '%d' ? (string) (int) $a : "'" . $a . "'";
            }, $query, 1);
        }
        return $query;
    }

    public function get_var(string $sql)
    {
        $this->calls[] = $sql;
        // wpdb::query() flush()es last_error to '' at the start of every query.
        $this->last_error = '';
        if (strpos($sql, 'GET_LOCK') !== false) {
            return $this->get_lock_return;
        }
        // The in-lock authoritative read of pp_token_overrides (#97).
        if (strpos($sql, 'option_value') !== false && strpos($sql, 'pp_token_overrides') !== false) {
            // A DB read failure (#212): null return WITH a non-empty last_error.
            if ($this->fail_option_read) {
                $this->last_error = 'MySQL server has gone away';
                return null;
            }
            // A corrupt/truncated row (#207): return the raw bytes verbatim, unserialized.
            if ($this->db_overrides_raw !== null) {
                return $this->db_overrides_raw;
            }
            return $this->db_overrides === null ? null : serialize($this->db_overrides);
        }
        // The in-lock fresh read of _pp_composition_version (#113). Matches on the
        // postmeta table (interpolated before prepare) so it's robust to placeholder
        // substitution; returns the scripted DB value so a test can prove the bump reads
        // from the DB, not the meta cache.
        if (strpos($sql, 'meta_value') !== false && strpos($sql, $this->postmeta) !== false) {
            return $this->db_composition_version;
        }
        return null;
    }

    public function query(string $sql)
    {
        $this->calls[] = $sql;
        return 1;
    }

    /** @return string[] only the GET_LOCK / RELEASE_LOCK calls, in order. */
    public function lockCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            fn ($c) => strpos($c, 'GET_LOCK') !== false || strpos($c, 'RELEASE_LOCK') !== false
        ));
    }
}

class TokenLockTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
    }

    public function testSetTokenAcquiresThenReleasesLockAroundWrite(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_set_token_override('--color-accent', '#123456');

        $lockCalls = $wpdb->lockCalls();
        $this->assertNotEmpty($lockCalls, 'A lock must be taken around the write.');
        $this->assertStringContainsString('GET_LOCK', $lockCalls[0], 'GET_LOCK must come first.');
        $this->assertStringContainsString('RELEASE_LOCK', end($lockCalls), 'RELEASE_LOCK must come last.');
        $this->assertStringContainsString('pp_tokovr_', $lockCalls[0], 'Lock name must be install-scoped.');
        $this->assertTrue($result, 'Write should succeed when the lock is acquired.');
        $this->assertSame(
            '#123456',
            $GLOBALS['_pp_test_store']['options']['pp_token_overrides']['--color-accent'] ?? null
        );
    }

    public function testLockNameIsInstallScoped(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $name = _pp_token_lock_name();
        $this->assertStringStartsWith('pp_tokovr_', $name);
        $this->assertLessThanOrEqual(64, strlen($name), 'MySQL lock names are capped at 64 chars.');
    }

    public function testAcquisitionFailureSkipsWriteAndDoesNotRelease(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '0'; // timed out
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_set_token_override('--color-accent', '#deadbe');

        $this->assertFalse($result, 'Acquisition failure must surface as an explicit false.');
        $this->assertArrayNotHasKey(
            'pp_token_overrides',
            $GLOBALS['_pp_test_store']['options'] ?? [],
            'No write may happen when the lock was not acquired.'
        );
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertEmpty($releases, 'A lock that was never acquired must not be released.');
    }

    /**
     * NULL from GET_LOCK (DB error / unhealthy server / backend without GET_LOCK) must be
     * treated as a hard failure — skip the write — NOT degraded to an unlocked write.
     * NULL usually means MySQL is sick, exactly when an unlocked read-modify-write would
     * reopen the lost-update race. Pins the cross-model review decision against regression.
     */
    public function testNullLockResultSkipsWriteAndDoesNotDegrade(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = null; // GET_LOCK error / unsupported
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_set_token_override('--color-accent', '#c0ffee');

        $this->assertFalse($result, 'A NULL lock result must surface as an explicit failure.');
        $this->assertArrayNotHasKey(
            'pp_token_overrides',
            $GLOBALS['_pp_test_store']['options'] ?? [],
            'A NULL lock result must NOT fall through to an unlocked write.'
        );
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertEmpty($releases, 'Nothing to release when the lock was never acquired.');
    }

    public function testLockReleasedWhenMutatorThrows(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        try {
            _pp_with_token_lock(function () {
                throw new \RuntimeException('boom');
            }, false);
            $this->fail('Expected the mutator exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertNotEmpty($releases, 'finally must release the lock even when the mutator throws.');
    }

    public function testClearAllRunsInsideLock(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_overrides = ['--radius' => '2px']; // the DB authoritatively holds one override
        $GLOBALS['wpdb'] = $wpdb;

        $count = pp_clear_all_token_overrides();

        $this->assertSame(1, $count);
        $lockCalls = $wpdb->lockCalls();
        $this->assertStringContainsString('GET_LOCK', $lockCalls[0]);
        $this->assertStringContainsString('RELEASE_LOCK', end($lockCalls));
    }

    /**
     * The lost-update fix depends on reading the AUTHORITATIVE DB row inside the lock,
     * not a stale cached map. Here the DB holds {--a,--b} while the cache/store only has
     * {--a}; setting --c must produce {--a,--b,--c} (it merged onto the fresh DB read),
     * never {--a,--c} (which would mean it clobbered --b from a stale cache).
     */
    public function testLockedWriteMergesOntoFreshDbNotStaleCache(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_overrides = ['--a' => '1', '--b' => '2']; // fresh, committed by a concurrent writer
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = ['--a' => '1']; // stale cache

        pp_set_token_override('--c', '3');

        $written = $GLOBALS['_pp_test_store']['options']['pp_token_overrides'];
        $this->assertSame(['--a' => '1', '--b' => '2', '--c' => '3'], $written,
            'The locked write must merge onto the fresh DB row, not overwrite it with a stale cache.');
    }

    /**
     * #200: the pre-apply snapshot must be read UNDER the lock for an atomic baseline.
     * When the lock is acquired, pp_snapshot_token_overrides() returns the authoritative
     * in-lock DB read (the same fresh row the writers see), not a stale cache.
     */
    public function testSnapshotReturnsInLockReadWhenLockAcquired(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_overrides = ['--a' => '1', '--b' => '2']; // authoritative committed row
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = ['--a' => 'stale']; // stale cache

        $snapshot = pp_snapshot_token_overrides();

        $this->assertSame(['--a' => '1', '--b' => '2'], $snapshot,
            'The snapshot must be the fresh in-lock DB read, not the stale cache.');
        $lockCalls = $wpdb->lockCalls();
        $this->assertStringContainsString('GET_LOCK', $lockCalls[0], 'The read must happen inside the lock.');
        $this->assertStringContainsString('RELEASE_LOCK', end($lockCalls));
    }

    /**
     * #200: on lock contention the snapshot must fail closed (return null), NOT silently
     * degrade to a plain, non-atomic cached read. A stale baseline recorded here is
     * exactly the wrong rollback target for a later `apply restore`, and it happens
     * precisely when a concurrent writer is racing — the case the lock exists to protect.
     */
    public function testSnapshotReturnsNullOnLockContention(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '0'; // another writer holds the lock (timed out)
        $wpdb->db_overrides = ['--a' => '1'];
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = ['--a' => 'stale'];

        $snapshot = pp_snapshot_token_overrides();

        $this->assertNull($snapshot,
            'A contended snapshot must fail closed, not return a stale non-atomic read.');
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertEmpty($releases, 'A lock that was never acquired must not be released.');
    }

    /**
     * #200: a NULL GET_LOCK result (DB error / unhealthy server) is also a hard snapshot
     * failure — null, never a degraded read.
     */
    public function testSnapshotReturnsNullOnLockError(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = null; // GET_LOCK error / unsupported backend
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertNull(pp_snapshot_token_overrides(),
            'A NULL lock result must surface as a null snapshot, not a degraded read.');
    }

    /**
     * #200: the null-vs-empty distinction the fix depends on. A lock-ACQUIRED snapshot of
     * an install with no overrides must return [] (a valid, recordable empty baseline),
     * never null (which the preflight CLI now treats as a hard lock-contention failure).
     * A regression that returned null here would make every preflight on a fresh install
     * wrongly report contention and record nothing.
     */
    public function testSnapshotReturnsEmptyArrayNotNullWhenNoOverridesAndLockAcquired(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1'; // lock acquired
        $wpdb->db_overrides = null;   // fresh install: no pp_token_overrides row
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertNotNull($snapshot, 'An empty-but-valid baseline must not be confused with lock failure.');
        $this->assertSame([], $snapshot, 'No overrides under a held lock is a recordable empty baseline, not null.');
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertNotEmpty($releases, 'A lock that was acquired must be released.');
    }

    /**
     * #207: under a HELD lock, a corrupt/unreadable pp_token_overrides row (anything that
     * does not unserialize to an array) must snapshot as null — NOT [] — so the run's
     * rollback baseline is never silently recorded as empty. Recording [] would make a
     * later `apply restore` DELETE every touched token (the unset() branch of
     * pp_revert_tokens) instead of restoring it. This is the sibling of #200's lock-
     * failure fail-close, reached through the corrupt-row door.
     */
    public function testSnapshotReturnsNullOnCorruptOverridesRowUnderHeldLock(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';                 // lock acquired
        $wpdb->db_overrides_raw = 'a:1:{s:3:"--a";'; // truncated serialized row — unreadable
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertNull($snapshot,
            'A corrupt/unreadable overrides row must fail closed (null), not coerce to an [] baseline.');
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertNotEmpty($releases, 'A lock that was acquired must still be released.');
    }

    /**
     * #207 Option A boundary: the fail-closed distinction lives ONLY at the snapshot
     * caller. The writer paths keep their pre-#207 "[]-means-start-fresh" handling of a
     * corrupt row — a set on an unreadable row must merge onto [], not abort. This proves
     * the shared _pp_read_token_overrides_locked() wrapper still coerces the strict null
     * back to [] for writers.
     */
    public function testWriterTreatsCorruptOverridesRowAsStartFresh(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';                 // lock acquired
        $wpdb->db_overrides_raw = 'a:1:{s:3:"--a";'; // truncated serialized row — unreadable
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_set_token_override('--color-accent', '#abcdef');

        $this->assertTrue($result, 'A writer must still succeed on a corrupt row (start-fresh semantics).');
        $this->assertSame(
            ['--color-accent' => '#abcdef'],
            $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] ?? null,
            'The write must merge onto a fresh [] baseline, not inherit the unreadable row.'
        );
    }

    /**
     * #207: a legitimately-empty overrides state stored as a serialized empty array
     * (a:0:{}) must snapshot as [] — a valid recordable baseline — NOT be misclassified
     * as corrupt (null). The writer paths delete_option() when empty, so a stored []
     * is rare, but it must never be confused with an unreadable row.
     */
    public function testSnapshotReturnsEmptyArrayForSerializedEmptyArrayRow(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';        // lock acquired
        $wpdb->db_overrides = [];            // stored empty array → serialize() = 'a:0:{}'
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertNotNull($snapshot, 'A serialized empty array is a valid empty baseline, not a corrupt row.');
        $this->assertSame([], $snapshot, 'a:0:{} must snapshot as [], never null.');
    }

    /**
     * #207: a serialized scalar (boolean/null/string) is a non-array row and must fail
     * closed as null, exactly like truncated bytes. Covers the b:0; (false), N; (null),
     * and s:5:"hello"; (string) shapes a hand-edit or a wrong writer could leave behind.
     */
    public function testSnapshotReturnsNullForSerializedScalarRows(): void
    {
        foreach (['b:0;', 'N;', 's:5:"hello";'] as $rawScalar) {
            $wpdb = new PP_Mock_Wpdb();
            $wpdb->get_lock_return = '1';           // lock acquired
            $wpdb->db_overrides_raw = $rawScalar;   // serialized non-array value
            $GLOBALS['wpdb'] = $wpdb;

            $this->assertNull(
                pp_snapshot_token_overrides(),
                "A serialized scalar row ($rawScalar) must fail closed as null, not coerce to []."
            );
        }
    }

    /**
     * #212: under a HELD lock, a DB READ FAILURE on the option SELECT (get_var() returns
     * null AND sets last_error) must snapshot as null — NOT [] — completing the fail-closed
     * trilogy (#200 lock-failure → #207 corrupt-row → #212 read-failure). get_var() returns
     * null on both a genuinely absent row and a query failure; last_error is what tells them
     * apart. Recording [] on a read failure would let a later `apply restore` DELETE every
     * touched token, the exact silent loss the trilogy exists to prevent.
     */
    public function testSnapshotReturnsNullOnOptionReadFailureUnderHeldLock(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';       // lock acquired (GET_LOCK succeeds)
        $wpdb->fail_option_read = true;     // the option SELECT errors → null + last_error
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertNull($snapshot,
            'A DB read failure on the option SELECT must fail closed (null), not coerce to an [] baseline.');
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertNotEmpty($releases, 'A lock that was acquired must still be released.');
    }

    /**
     * #212: the null-vs-[] distinction the fix hinges on. A genuinely absent row (get_var()
     * returns null with an EMPTY last_error — the query ran and matched nothing) must still
     * snapshot as [] — a valid recordable empty baseline — preserving the #207 absent-row
     * contract. Only a non-empty last_error turns null into a hard failure.
     */
    public function testSnapshotReturnsEmptyArrayForAbsentRowWithNoReadError(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';       // lock acquired
        $wpdb->db_overrides = null;         // absent row → get_var null, last_error stays ''
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertSame('', $wpdb->last_error, 'An absent row is not an error — last_error must stay empty.');
        $this->assertNotNull($snapshot, 'An absent row is a valid empty baseline, not a read failure.');
        $this->assertSame([], $snapshot, 'A genuinely absent row must snapshot as [], never null.');
    }

    /**
     * #212 boundary (mirrors the #207 writer test): the fail-closed distinction lives ONLY
     * at the snapshot caller. A writer that hits a read failure keeps the pre-existing
     * "[]-means-start-fresh" handling — the shared _pp_read_token_overrides_locked() wrapper
     * coerces the strict null back to [] — so a set on a read failure merges onto [], not
     * aborts. Writer paths must stay unchanged (issue #212 acceptance criteria).
     */
    public function testWriterTreatsOptionReadFailureAsStartFresh(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';       // lock acquired
        $wpdb->fail_option_read = true;     // the read-modify-write's read errors
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_set_token_override('--color-accent', '#abcdef');

        $this->assertTrue($result, 'A writer must still succeed on a read failure (start-fresh semantics).');
        $this->assertSame(
            ['--color-accent' => '#abcdef'],
            $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] ?? null,
            'The write must merge onto a fresh [] baseline, not fail closed like the snapshot.'
        );
    }

    /**
     * #212 contract guard: the fix relies on wpdb::query() flushing last_error to '' at the
     * START of every query, so error state from a PRIOR query can never be mistaken for a
     * failure of the option SELECT. This asserts that reliance directly — a non-empty
     * last_error left over from before the snapshot must NOT turn a genuinely absent row
     * into a false-positive null. Both the successful GET_LOCK and the option read flush it,
     * so a clean absent row still snapshots as []. Guards against a future mock/impl change
     * that stops resetting last_error per query (which would silently break this invariant).
     */
    public function testStalePriorErrorDoesNotPoisonAbsentRowSnapshot(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '1';       // lock acquired (GET_LOCK flushes last_error to '')
        $wpdb->db_overrides = null;         // absent row
        $wpdb->last_error = 'stale error from an earlier unrelated query';
        $GLOBALS['wpdb'] = $wpdb;

        $snapshot = pp_snapshot_token_overrides();

        $this->assertSame('', $wpdb->last_error,
            'Each query flushes last_error; the option read must leave it empty on success.');
        $this->assertSame([], $snapshot,
            'A stale pre-operation last_error must not misclassify an absent row as a read failure.');
    }

    public function testLockNameVariesByInstall(): void
    {
        // _pp_token_lock_name() keys on DB identity (here $wpdb->dbname, since DB_NAME is
        // undefined in tests). Two installs must get different lock names so they never
        // serialize against each other; the same install must get a stable name.
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->dbname = 'install_one';
        $nameOne = _pp_token_lock_name();
        $this->assertSame($nameOne, _pp_token_lock_name(), 'Lock name must be stable for one install.');

        $wpdb->dbname = 'install_two';
        $nameTwo = _pp_token_lock_name();

        $this->assertNotSame($nameOne, $nameTwo, 'Distinct installs must get distinct lock names.');
    }

    // ── Composition write lock (#113) ──────────────────────────────────────

    public function testUpdateCompositionAcquiresThenReleasesLock(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(77, [['component' => 'hero', 'props' => ['title' => 'X']]]);

        $this->assertTrue($result);
        $gets     = array_filter($wpdb->calls, fn ($c) => strpos($c, 'GET_LOCK') !== false);
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertCount(1, $gets, 'Exactly one GET_LOCK around the composition write.');
        $this->assertCount(1, $releases, 'The composition lock must be released after the write.');
    }

    public function testUpdateCompositionLockContentionReturnsWpError(): void
    {
        // The #200 lesson at composition-write time: a lock-acquire failure must propagate
        // as a WP_Error and write NOTHING — never a silent non-atomic write.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '0'; // another writer holds the per-post lock
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(78, [['component' => 'hero', 'props' => ['title' => 'Y']]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('composition_lock_failed', $result->get_error_code());
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertEmpty($releases, 'A lock never acquired must not be released.');
    }

    public function testUpdateCompositionNullLockResultReturnsWpError(): void
    {
        // NULL from GET_LOCK (sick DB / unsupported backend) is a hard failure, not a
        // degrade-to-unlocked-write.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = null;
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(79, [['component' => 'hero', 'props' => ['title' => 'Z']]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('composition_lock_failed', $result->get_error_code());
    }

    public function testUpdateCompositionBumpsFromDbVersionNotStaleCache(): void
    {
        // #113 stale-cache guard: warm the post-meta cache with an OLD version, then have
        // the DB report a NEWER version (as a concurrent writer would have committed while
        // we waited on the lock). The bump must read the DB value, so the write lands at
        // db_version + 1 — not cache_version + 1.
        update_post_meta(80, '_pp_composition_version', 5); // stale cache = 5
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '9'; // DB truth = 9 (wpdb returns strings)
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(80, [['component' => 'hero', 'props' => ['title' => 'Q']]]);

        $this->assertTrue($result);
        $this->assertSame(10, (int) get_post_meta(80, '_pp_composition_version', true), 'Bump must be DB(9)+1, not cache(5)+1.');
        unset($GLOBALS['_pp_test_store']['post_meta'][80]);
    }

    public function testCompositionLockNameVariesByPost(): void
    {
        // Different posts must get distinct lock names so writes to different posts never
        // serialize against each other; the same post is stable.
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame(_pp_composition_lock_name(10), _pp_composition_lock_name(10), 'Stable per post.');
        $this->assertNotSame(_pp_composition_lock_name(10), _pp_composition_lock_name(11), 'Distinct per post.');
    }
}
