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
    /** @var array|null The pp_token_overrides row the DB returns (null = no row). */
    public $db_overrides = null;

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $a) {
            $query = preg_replace('/%s/', "'" . $a . "'", $query, 1);
        }
        return $query;
    }

    public function get_var(string $sql)
    {
        $this->calls[] = $sql;
        if (strpos($sql, 'GET_LOCK') !== false) {
            return $this->get_lock_return;
        }
        // The in-lock authoritative read of pp_token_overrides (#97).
        if (strpos($sql, 'option_value') !== false && strpos($sql, 'pp_token_overrides') !== false) {
            return $this->db_overrides === null ? null : serialize($this->db_overrides);
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
}
