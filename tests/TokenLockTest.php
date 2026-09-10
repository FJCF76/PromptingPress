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

    /**
     * MODELLED WITH CORE'S REAL VISIBILITY, not as a public convenience (#830, the #857
     * harness-fidelity precedent). Real wpdb declares this `protected` and exposes it
     * through the magic accessors below, so production code reaches it via __get/__set.
     * A public property here would let a guard that only works on a public property pass
     * this suite and fail against WordPress — the harness must make the same demand
     * production does.
     */
    protected $reconnect_retries = 5;

    /**
     * One of the four names core's wpdb::__set() actually REFUSES, kept here so the
     * whitelist can be tested from both sides: the budget must take, this must not.
     * Core declares it protected too.
     */
    protected $table_charset = 'utf8mb4_sentinel';

    /**
     * @var bool Opt-in: after GET_LOCK succeeds, model a connection that has GONE AWAY
     * while wpdb's retry budget is 0. INERT when unset, so every existing test in this
     * file is unaffected.
     *
     * THE BUDGET DECIDES WHAT A DEAD CONNECTION DOES, which is the whole point and is why
     * this branch reads $this->reconnect_retries instead of failing flat:
     *
     *   retries > 0 (the pre-#830 world) — core RECONNECTS and re-runs the statement on a
     *     NEW connection. It returns correct-looking data with last_error === '', so the
     *     mutator sails on... and the connection-scoped GET_LOCK is GONE. `lock_voided`
     *     records that. This is the bug, modelled faithfully, and it is what makes the
     *     refusal test below genuinely red: remove the guard and this arm runs.
     *   retries === 0 (the guarded world) — check_connection() skips its now-empty retry
     *     loop and the statement FAILS.
     *
     * WHAT CANNOT BE MODELLED. On the retries-0 path core's DEFAULT branch reaches
     * bail()/dead_db() and terminates the request, which a unit test cannot stage. What is
     * modelled is core's other branch — taken after `template_redirect`, where
     * check_connection() returns false and wpdb::query() does `$this->insert_id = 0; return
     * false;`. The load-bearing detail is that last_error stays EMPTY there: query()
     * flush()es it before the statement and only captures mysqli_error() AFTER the
     * errno-2006 branch, so this failure is invisible to the #212 last_error guards by
     * construction. Modelling it with a non-empty last_error would make the guards look
     * like they catch it.
     */
    public bool $dead_after_lock = false;

    /** @var bool True once a modelled self-heal has relocated the session off the locked connection. */
    public bool $lock_voided = false;

    /** @var bool Set once GET_LOCK has been answered, so `dead_after_lock` starts AFTER the acquire. */
    private bool $lock_held = false;

    /** @var array<int,array{sql:string,retries:int}> The retry budget visible at each statement. */
    public array $retries_at_call = [];

    /** Reads the protected budget the way core's own code does — from inside the class. */
    public function retriesNow(): int
    {
        return $this->reconnect_retries;
    }

    /**
     * The budget WITHOUT an int return type, for the subclass that holds it as a numeric
     * string. This file declares strict_types, so retriesNow() cannot carry that value.
     *
     * @return mixed
     */
    public function rawBudget()
    {
        return $this->reconnect_retries;
    }

    /**
     * Reads any property from inside class scope, bypassing __get. Lets a test check what a
     * whitelisted __set actually did without the magic accessor answering for it.
     *
     * @param string $name
     * @return mixed
     */
    public function rawProperty(string $name)
    {
        return $this->$name ?? null;
    }

    /** @return int[] the retry budget seen at each statement issued after GET_LOCK succeeded. */
    public function retriesInsideSection(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['retries'],
            array_filter(
                $this->retries_at_call,
                fn (array $c) => strpos($c['sql'], 'GET_LOCK') === false
                    && strpos($c['sql'], 'RELEASE_LOCK') === false
            )
        ));
    }

    /** @return int|null the retry budget in force when RELEASE_LOCK was issued, or null if it never was. */
    public function retriesAtRelease(): ?int
    {
        foreach ($this->retries_at_call as $c) {
            if (strpos($c['sql'], 'RELEASE_LOCK') !== false) {
                return $c['retries'];
            }
        }
        return null;
    }

    /** Core's wpdb::__get() verbatim (minus the col_info lazy-load, which has no analogue here). */
    public function __get($name)
    {
        return $this->$name;
    }

    /**
     * Core's wpdb::__set() verbatim, including the $protected_members whitelist. The list is
     * copied rather than paraphrased because the whole reason this guard can be a plain
     * property write is that `reconnect_retries` is NOT on it.
     */
    public function __set($name, $value)
    {
        $protected_members = array(
            'col_meta',
            'table_charset',
            'check_current_query',
            'allow_unsafe_unquoted_parameters',
        );
        if (in_array($name, $protected_members, true)) {
            return;
        }
        $this->$name = $value;
    }

    /**
     * Answers a statement issued on a connection that has gone away, the way core would.
     *
     * @param string $sql The statement being issued.
     * @return bool true when the statement must FAIL (budget suspended); false when it
     *              proceeds normally — either because no death is armed, or because core
     *              self-healed onto a new connection.
     */
    private function deadConnectionFailsThisStatement(string $sql): bool
    {
        if (!$this->dead_after_lock || !$this->lock_held) {
            return false;
        }
        if ($this->reconnect_retries > 0) {
            // Core reconnects and re-runs the statement on a new connection.
            //
            // RELEASE_LOCK IS EXEMPT FROM THE VOIDING FLAG, and the exemption is the design
            // rather than a convenience: the release is issued AFTER the restore, outside
            // the section, precisely so a dead connection self-heals there into a harmless
            // no-op instead of reaching dead_db() during unwinding. Nothing is protected at
            // that point, so a relocation there voids nothing. Flagging it would make the
            // model claim the guard failed on every dead-connection test.
            if (strpos($sql, 'RELEASE_LOCK') === false) {
                $this->lock_voided = true;
            }
            return false;
        }
        return true;
    }

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
        $this->retries_at_call[] = ['sql' => $sql, 'retries' => $this->reconnect_retries];
        // wpdb::query() flush()es last_error to '' at the start of every query.
        $this->last_error = '';
        if (strpos($sql, 'GET_LOCK') !== false) {
            if ($this->get_lock_return === '1' || $this->get_lock_return === 1) {
                $this->lock_held = true;
            }
            return $this->get_lock_return;
        }
        // A connection that went away inside the critical section, with the retry budget
        // suspended (#830). null, and last_error deliberately left EMPTY — see the
        // $dead_after_lock docblock for why that emptiness is the point.
        if ($this->deadConnectionFailsThisStatement($sql)) {
            return null;
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
        $this->retries_at_call[] = ['sql' => $sql, 'retries' => $this->reconnect_retries];
        $this->last_error = '';
        // RELEASE_LOCK on a connection that died: wpdb returns false, last_error empty.
        if ($this->deadConnectionFailsThisStatement($sql)) {
            return false;
        }
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

/**
 * A handle whose magic accessors RAISE on the retry budget (#830).
 *
 * Not hypothetical enough to skip: a db.php drop-in or wpdb subclass is free to type the
 * property, mark it readonly, or validate in __set, and any of those raises from outside
 * class scope. The reason this matters more than an ordinary degradation is WHERE it
 * happens — the advisory lock is already HELD by the time the suspension is attempted, so
 * an exception escaping there would skip RELEASE_LOCK and strand the lock for the rest of
 * the request. That is a worse failure than the one #830 set out to fix.
 */
class PP_HostileAccessor_Wpdb extends PP_Mock_Wpdb
{
    public function __get($name)
    {
        if ($name === 'reconnect_retries') {
            throw new \RuntimeException('this handle does not expose the retry budget');
        }
        return parent::__get($name);
    }

    public function __set($name, $value)
    {
        if ($name === 'reconnect_retries') {
            throw new \RuntimeException('this handle refuses retry-budget writes');
        }
        parent::__set($name, $value);
    }
}

/** A handle holding the retry budget as a NUMERIC STRING, which still reconnects (#830). */
class PP_StringBudget_Wpdb extends PP_Mock_Wpdb
{
    public function __construct()
    {
        // Assigned through the magic setter exactly as production would reach it.
        $this->reconnect_retries = '5';
    }
}

/**
 * A handle whose __set SILENTLY NORMALIZES the budget and never accepts 0 (#830).
 *
 * The shape a real drop-in takes, and distinct from the throwing one: a db.php that clamps
 * its own retry budget refuses the write without raising, so the guard's read-back is the
 * only thing that can notice. This is the handle the "BEST-EFFORT" contract is written for.
 */
class PP_ClampingBudget_Wpdb extends PP_Mock_Wpdb
{
    public function __set($name, $value)
    {
        if ($name === 'reconnect_retries') {
            parent::__set($name, max(1, (int) $value));
            return;
        }
        parent::__set($name, $value);
    }
}

/** A handle whose budget is not numeric at all — nothing we may reason about (#830). */
class PP_OpaqueBudget_Wpdb extends PP_Mock_Wpdb
{
    public function __construct()
    {
        $this->reconnect_retries = 'unlimited';
    }
}

/**
 * A handle that ACCEPTS the suspension and only then starts raising (#830).
 *
 * Reaches the restore's own catch, which the throwing handle above cannot: that one makes
 * the SUSPEND return null, so the restore exits at its `$saved === null` guard before the
 * try is ever entered. The point of that catch is that RELEASE_LOCK must survive a raising
 * restore, so it needs a handle that gets far enough to test it.
 */
class PP_TurnsHostile_Wpdb extends PP_Mock_Wpdb
{
    public function __get($name)
    {
        if ($name === 'reconnect_retries' && parent::__get('reconnect_retries') === 0) {
            throw new \RuntimeException('the handle stopped exposing the budget mid-section');
        }
        return parent::__get($name);
    }
}

/**
 * A handle that GENUINELY lacks the retry budget — a HyperDB-style third-party replacement.
 *
 * Deliberately NOT a wpdb subclass. Since #830 the bootstrap `wpdb` stub carries core's real
 * `reconnect_retries`, because it is this harness's model of WordPress ITSELF; using it (or
 * PP_Lockable_Wpdb, which descends from it) as the fixture for "a third-party drop-in" would
 * pin a test to a harness infidelity rather than to a real handle shape.
 */
class PP_NoBudget_Wpdb
{
    public string $options  = 'wp_options';
    public string $postmeta = 'wp_postmeta';

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function get_var(string $query)
    {
        return strpos($query, 'GET_LOCK') !== false ? '1' : null;
    }

    public function query(string $query)
    {
        return 1;
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

    // ── Write-time compare-and-swap (#13) ──────────────────────────────────

    public function testUpdateCompositionCasMatchWritesAndBumps(): void
    {
        // expected_version matches the fresh in-lock DB version → the write proceeds and the
        // version bumps normally.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '5';
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(90, [['component' => 'hero', 'props' => ['title' => 'M']]], 5);

        $this->assertTrue($result);
        $this->assertSame(6, (int) get_post_meta(90, '_pp_composition_version', true), 'Matched CAS bumps 5→6.');
        unset($GLOBALS['_pp_test_store']['post_meta'][90]);
    }

    public function testUpdateCompositionCasMismatchReturnsConflictAndWritesNothing(): void
    {
        // An interleaved external write bumped the version (DB=9) after the caller read v5.
        // The CAS must reject with composition_conflict and touch NOTHING — not the
        // composition, not either marker — but still release the lock it took (finally).
        update_post_meta(91, '_pp_composition', 'SENTINEL_JSON');
        update_post_meta(91, '_pp_composition_hash', 'SENTINEL_HASH');
        update_post_meta(91, '_pp_composition_version', 9);
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '9'; // DB truth moved to 9 since the caller read 5
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(91, [['component' => 'hero', 'props' => ['title' => 'STALE']]], 5);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('composition_conflict', $result->get_error_code());
        // Markers and composition unchanged — a rejected write leaves state exactly as it was.
        $this->assertSame('SENTINEL_JSON', get_post_meta(91, '_pp_composition', true), 'Composition must be untouched on conflict.');
        $this->assertSame('SENTINEL_HASH', get_post_meta(91, '_pp_composition_hash', true), 'Hash marker must be untouched on conflict.');
        $this->assertSame(9, (int) get_post_meta(91, '_pp_composition_version', true), 'Version marker must not bump on conflict.');
        // The lock is still acquired and released even when the CAS rejects inside it.
        $releases = array_filter($wpdb->calls, fn ($c) => strpos($c, 'RELEASE_LOCK') !== false);
        $this->assertCount(1, $releases, 'The lock must be released even on a CAS conflict.');
        unset($GLOBALS['_pp_test_store']['post_meta'][91]);
    }

    public function testUpdateCompositionCasNullSkipsCheck(): void
    {
        // Back-compat: a null expected_version skips the CAS entirely, so a legacy/direct
        // caller writes unconditionally regardless of the current version.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '9';
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(92, [['component' => 'hero', 'props' => ['title' => 'N']]], null);

        $this->assertTrue($result, 'Omitted expected_version must still write.');
        $this->assertSame(10, (int) get_post_meta(92, '_pp_composition_version', true), 'No CAS → normal bump 9→10.');
        unset($GLOBALS['_pp_test_store']['post_meta'][92]);
    }

    public function testUpdateCompositionCasLegacyZeroInitializes(): void
    {
        // A legacy/never-written page has no marker → the in-lock read is 0. A caller that
        // read that absent marker sends expected_version=0; it matches and the write
        // initializes the marker to version 1.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = null; // absent row → 0
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(93, [['component' => 'hero', 'props' => ['title' => 'L']]], 0);

        $this->assertTrue($result, 'expected_version=0 against an absent marker must write.');
        $this->assertSame(1, (int) get_post_meta(93, '_pp_composition_version', true), 'First write initializes to v1.');
        unset($GLOBALS['_pp_test_store']['post_meta'][93]);
    }

    // ── #830: the reconnect that voided the lock ────────────────────────────────────
    //
    // wpdb's errno-2006 self-heal re-ran a statement on a NEW connection and reported
    // success with an empty last_error, silently dropping the connection-scoped GET_LOCK
    // for the whole rest of the mutator. _pp_with_advisory_lock() now suspends the retry
    // budget for the section and restores it on every exit. These pin the lifecycle (the
    // budget is actually 0 while the section runs, and actually restored afterwards), the
    // refusal a dead connection produces, nesting, and inertness against a handle that has
    // no such property.

    public function testReconnectRetriesAreZeroInsideTheSectionAndRestoredAfter(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '4';
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame(5, $wpdb->retriesNow(), 'Precondition: the double starts at core\'s default budget.');

        $result = pp_update_composition(830, [['component' => 'hero', 'props' => ['title' => 'A']]], 4);

        $this->assertTrue($result, 'The healthy path must be unaffected by the guard.');
        $inside = $wpdb->retriesInsideSection();
        $this->assertNotEmpty($inside, 'The mutator must have issued at least one statement inside the lock.');
        $this->assertSame(
            array_fill(0, count($inside), 0),
            $inside,
            'Every statement inside the critical section must run with the reconnect budget at 0 — '
            . 'a single statement at 5 is a statement that can silently relocate to a new connection.'
        );
        $this->assertSame(5, $wpdb->retriesNow(), 'The budget must be restored after a successful write.');
        unset($GLOBALS['_pp_test_store']['post_meta'][830]);
    }

    public function testReconnectRetriesRestoredAfterTheReleaseSoTheReleaseItselfCanSelfHeal(): void
    {
        // Restore runs BEFORE the release, deliberately: a RELEASE_LOCK issued on a
        // connection that died during the section then self-heals into a harmless no-op
        // (the dead connection already dropped the lock) instead of reaching dead_db()
        // during unwinding and masking the original failure.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '1';
        $GLOBALS['wpdb'] = $wpdb;

        pp_update_composition(831, [['component' => 'hero', 'props' => ['title' => 'B']]], 1);

        $this->assertSame(
            [0],
            array_unique($wpdb->retriesInsideSection()),
            'Precondition: the section really was suspended, so the release value below means something.'
        );
        $this->assertSame(5, $wpdb->retriesAtRelease(), 'RELEASE_LOCK must be issued with the budget already restored.');
        unset($GLOBALS['_pp_test_store']['post_meta'][831]);
    }

    public function testReconnectRetriesRestoredWhenTheWriteIsRefused(): void
    {
        // A CAS mismatch returns a WP_Error out of the mutator. The restore lives in a
        // `finally`, so a refusal must leave the budget exactly as a success does.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '7';
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(832, [['component' => 'hero', 'props' => ['title' => 'C']]], 2);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Precondition: a stale baseline must refuse.');
        $this->assertSame('composition_conflict', $result->get_error_code());
        $this->assertSame(5, $wpdb->retriesNow(), 'A refusal must restore the reconnect budget.');
    }

    public function testReconnectRetriesRestoredWhenTheMutatorThrows(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        try {
            _pp_with_advisory_lock('pp_test_830_throw', function () {
                throw new \RuntimeException('boom');
            }, false, 'test');
            $this->fail('The exception must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(5, $wpdb->retriesNow(), 'An exception unwinding out of the mutator must still restore the budget.');
        $this->assertSame(
            ['GET_LOCK', 'RELEASE_LOCK'],
            array_map(fn ($c) => strpos($c, 'RELEASE_LOCK') !== false ? 'RELEASE_LOCK' : 'GET_LOCK', $wpdb->lockCalls()),
            'The release must still happen when the mutator throws.'
        );
    }

    public function testLockAcquisitionFailureNeverSuspendsTheBudget(): void
    {
        // The suspension is scoped to the section, and there is no section when the acquire
        // fails: the mutator does not run, so the budget must never be touched.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->get_lock_return = '0'; // contention
        $GLOBALS['wpdb'] = $wpdb;

        $ran = false;
        $result = _pp_with_advisory_lock('pp_test_830_busy', function () use (&$ran) {
            $ran = true;
            return true;
        }, 'FAILED', 'test');

        $this->assertSame('FAILED', $result);
        $this->assertFalse($ran, 'Precondition: the mutator must not run on acquisition failure.');
        $this->assertSame(5, $wpdb->retriesNow(), 'A failed acquire must leave the budget untouched.');
        $this->assertSame([], $wpdb->retriesInsideSection(), 'No statement should have run inside a section that never opened.');
    }

    public function testNestedLocksRestoreThroughTheCallStackWithoutClobbering(): void
    {
        // Nesting is safe BY CONSTRUCTION because the save takes the ACTUAL CURRENT value:
        // outer saves 5 → 0, inner saves 0 → 0, inner restores 0, outer restores 5. This
        // pins that composition, so a future rewrite that saves a hard-coded default (or
        // adds a re-entrancy refusal) has to change a test rather than a behaviour.
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $seen = [];
        _pp_with_advisory_lock('pp_test_830_outer', function () use ($wpdb, &$seen) {
            $seen['inside_outer'] = $wpdb->retriesNow();
            _pp_with_advisory_lock('pp_test_830_inner', function () use ($wpdb, &$seen) {
                $seen['inside_inner'] = $wpdb->retriesNow();
                return true;
            }, false, 'test');
            $seen['after_inner'] = $wpdb->retriesNow();
            return true;
        }, false, 'test');
        $seen['after_outer'] = $wpdb->retriesNow();

        $this->assertSame(0, $seen['inside_outer'], 'The outer section suspends.');
        $this->assertSame(0, $seen['inside_inner'], 'The inner section stays suspended.');
        $this->assertSame(0, $seen['after_inner'], 'The inner restore must put back what IT found (0), not the default.');
        $this->assertSame(5, $seen['after_outer'], 'The outer restore returns the original budget.');
    }

    public function testDeadConnectionInsideTheSectionRefusesTheWriteWithoutWritingAnything(): void
    {
        // THE POINT OF THE WHOLE ISSUE. Before #830 the errno-2006 self-heal re-ran this
        // read on a new connection and the write proceeded UNLOCKED. With the budget
        // suspended the read fails instead, the in-lock version reads 0, and a caller
        // carrying a baseline is refused by the EXISTING composition_conflict — no new
        // refusal class was minted. Nothing is written.
        //
        // WHICH BRANCH THIS MODELS, stated because the name does not say it. Core answers a
        // dead connection two ways, and the one that RETURNS (rather than calling dead_db()
        // and killing the request) is gated on did_action('template_redirect'). Every write
        // surface in this theme is WP-CLI, admin-AJAX or the activation seed, and none of
        // them fires that action — so in production the dead connection terminates the
        // request and this refusal is not what an operator meets. What is pinned here is the
        // CODE's behaviour when an in-lock read fails without taking the request down with
        // it: the guarantee that nothing relocates onto another connection, which holds on
        // both branches. See _pp_with_advisory_lock()'s docblock for all four shapes.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '3';
        $wpdb->dead_after_lock = true;
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(833, [['component' => 'hero', 'props' => ['title' => 'D']]], 3);

        $this->assertFalse(
            $wpdb->lock_voided,
            'THE REGRESSION THIS EXISTS FOR: with the budget suspended, no statement may '
            . 'self-heal onto a new connection — that relocation is what silently released '
            . 'the lock and let the rest of the write run unserialized.'
        );
        $this->assertInstanceOf(\WP_Error::class, $result, 'A dead connection inside the lock must not report success.');
        $this->assertSame('composition_conflict', $result->get_error_code());
        $this->assertSame(
            '',
            (string) get_post_meta(833, '_pp_composition', true),
            'The refusal must leave the composition unwritten.'
        );
        $this->assertSame(
            '',
            (string) get_post_meta(833, '_pp_composition_version', true),
            'The refusal must leave the version marker unwritten.'
        );
        $this->assertSame(5, $wpdb->retriesNow(), 'Even on the dead-connection path the budget is restored.');
    }

    public function testDeadConnectionWithNoBaselineIsTheDisclosedOptimisticReturn(): void
    {
        // THE DISCLOSED RESIDUAL, pinned so it lives in the suite rather than only in prose.
        // A caller that supplies no expected_version (create_page, the homepage seed, legacy
        // direct writes) has no CAS to catch the failed read, so the write is not refused.
        // Nothing runs unlocked — that is what #830 guarantees — but the RETURN is
        // optimistic. Closing it needs update_post_meta()'s verdict to be readable, which is
        // the half of the #821 ruling deferred to the #857 compare-first idiom.
        //
        // SAME BRANCH CAVEAT AS ITS SIBLING: this models the core path that RETURNS on a dead
        // connection, which is gated on did_action('template_redirect') and so is not the one
        // production reaches. The invariant it pins — nothing relocates onto another
        // connection — holds on both.
        //
        // NOT STAGEABLE HERE: the meta writes themselves. update_post_meta() is a test-store
        // stub, not a call through this double, so the harness cannot make them fail the way
        // a dead connection would in production. Shape 4 on _pp_with_advisory_lock() (a death
        // BETWEEN two of the three meta writes, leaving new content under a stale marker) is
        // out of reach here for the same reason. What this pins is the OBSERVABLE signature
        // at this layer — no refusal — so a future change that starts refusing has to update
        // this test deliberately instead of silently.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '3';
        $wpdb->dead_after_lock = true;
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(834, [['component' => 'hero', 'props' => ['title' => 'E']]], null);

        // PROVE THE INJECTION ACTUALLY FIRED FIRST. Every other assertion in this test is
        // an outcome a perfectly HEALTHY connection also produces, so without this the test
        // would stay green if the dead-connection wiring silently stopped arming — it would
        // then be pinning the ordinary path under the name of the residual. The version read
        // resolving to 0 against a stored '3' is the signature that the read really failed.
        $this->assertSame(
            1,
            (int) get_post_meta(834, '_pp_composition_version', true),
            'The dead read must have answered 0, making this write v1 rather than v4 — if it is 4, '
            . 'the injection did not fire and the rest of this test proves nothing.'
        );
        $this->assertFalse(
            $wpdb->lock_voided,
            'The residual is an optimistic RETURN, not an unlocked write. Even here nothing may '
            . 'relocate onto a new connection — that is the guarantee #830 actually makes.'
        );
        $this->assertTrue($result, 'Documented residual: with no baseline there is no gate to catch the failed read.');
        $this->assertSame(5, $wpdb->retriesNow(), 'The budget is restored regardless.');
        unset($GLOBALS['_pp_test_store']['post_meta'][834]);
    }

    public function testDeadConnectionWithAZeroBaselineAlsoLandsInTheResidual(): void
    {
        // THE SECOND FACE OF THE RESIDUAL, and the one that is easy to state backwards. A
        // dead read answers version 0, and a caller can legitimately HOLD 0 as its baseline
        // (an absent marker reads as 0 — the documented back-compat path by which a
        // never-written page initializes to v1). So the CAS compares 0 against 0, PASSES,
        // and the write is NOT refused. It is the same trap already recorded on
        // _pp_read_composition_version_locked(): "a caller that supplied expected_version = 0
        // passes a CAS it should have failed."
        //
        // Pinned separately from the null-baseline case because a reader of the docblock
        // could reasonably conclude "any caller carrying expected_version is protected",
        // and that is false for exactly this value.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->db_composition_version = '6';
        $wpdb->dead_after_lock = true;
        $GLOBALS['wpdb'] = $wpdb;

        $result = pp_update_composition(835, [['component' => 'hero', 'props' => ['title' => 'F']]], 0);

        $this->assertFalse($wpdb->lock_voided, 'Nothing may relocate onto a new connection.');
        $this->assertTrue(
            $result,
            'expected_version=0 meets a dead read of 0, so the CAS passes and the write is not refused — '
            . 'the disclosed residual, not a refusal.'
        );
        $this->assertSame(5, $wpdb->retriesNow(), 'The budget is restored regardless.');
        unset($GLOBALS['_pp_test_store']['post_meta'][835]);
    }

    public function testGuardIsInertAgainstAHandleWithNoReconnectBudget(): void
    {
        // A $wpdb replacement (HyperDB, a db.php drop-in) may not carry the property at all.
        // The guard must degrade to a no-op — no fabricated property, no PHP warning, and
        // above all no pretending the section is protected.
        //
        // THE FIXTURE IS PURPOSE-BUILT, and that is the point. It used to be PP_Lockable_Wpdb,
        // which descends from the bootstrap `wpdb` stub — this harness's model of WordPress
        // ITSELF. That stub now carries core's real reconnect budget (#830), so using it here
        // would have pinned this test to a harness gap rather than to a real handle shape:
        // the moment the stub got more faithful, the "third-party drop-in" fixture stopped
        // being one.
        $wpdb = new PP_NoBudget_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertFalse(
            property_exists($wpdb, 'reconnect_retries'),
            'Precondition: the bare stub models a handle with no reconnect budget.'
        );

        $ran = false;
        $result = _pp_with_advisory_lock('pp_test_830_inert', function () use (&$ran) {
            $ran = true;
            return 'ok';
        }, false, 'test');

        $this->assertSame('ok', $result, 'The mutator must still run.');
        $this->assertTrue($ran);
        $this->assertFalse(
            property_exists($wpdb, 'reconnect_retries'),
            'The guard must not conjure the property onto a handle that never had one.'
        );
    }

    public function testHandleWhoseAccessorsThrowStillReleasesTheLock(): void
    {
        // THE WORST FAILURE THIS CHANGE COULD HAVE INTRODUCED. The suspension is attempted
        // with the lock ALREADY HELD, so an exception escaping it would skip RELEASE_LOCK
        // and strand the lock for the rest of the request — worse than the bug #830 fixes.
        // Two things keep that shut: the helper swallows Throwable, and the call sits inside
        // the caller's own try/finally. This pins the observable consequence of both.
        $wpdb = new PP_HostileAccessor_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $ran = false;
        $result = _pp_with_advisory_lock('pp_test_830_hostile', function () use (&$ran) {
            $ran = true;
            return 'ok';
        }, false, 'test');

        $this->assertSame('ok', $result, 'A handle that refuses the budget must not break the write.');
        $this->assertTrue($ran, 'The mutator must still run — unguarded, but running.');
        $this->assertSame(
            ['GET_LOCK', 'RELEASE_LOCK'],
            array_map(
                fn ($c) => strpos($c, 'RELEASE_LOCK') !== false ? 'RELEASE_LOCK' : 'GET_LOCK',
                $wpdb->lockCalls()
            ),
            'THE LOCK MUST STILL BE RELEASED. A raising accessor may not strand it.'
        );
    }

    public function testNumericStringBudgetIsSuspendedAndRestoredVerbatim(): void
    {
        // A drop-in holding "5" still reconnects — PHP compares the loop bound numerically —
        // so a strict is_int() test would report "nothing to suspend" on a handle that is
        // genuinely exposed. The saved value goes back exactly as found, coercing nothing.
        $wpdb = new PP_StringBudget_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame('5', $wpdb->rawBudget(), 'Precondition: the budget is a numeric string.');

        $seen = null;
        _pp_with_advisory_lock('pp_test_830_stringy', function () use ($wpdb, &$seen) {
            $seen = $wpdb->rawBudget();
            return true;
        }, false, 'test');

        $this->assertSame(0, $seen, 'A numeric-string budget must still be suspended.');
        $this->assertSame('5', $wpdb->rawBudget(), 'The restore must put back exactly what it found.');
    }

    public function testRestoreDoesNotClobberABudgetSetInsideTheSection(): void
    {
        // update_post_meta() fires WordPress's meta hooks, so third-party code runs inside
        // this lock. If any of it sets its own budget, the value is no longer 0 and putting
        // ours back would silently overwrite a decision that is not this theme's to make.
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        _pp_with_advisory_lock('pp_test_830_plugin', function () use ($wpdb) {
            $wpdb->reconnect_retries = 3; // a plugin callback, deliberately
            return true;
        }, false, 'test');

        $this->assertSame(3, $wpdb->retriesNow(), 'A deliberate in-section change must survive the restore.');
    }

    public function testRestoreDoesNotClobberANonNumericBudgetSetInsideTheSection(): void
    {
        // THE HOLE THE FIRST SPELLING LEFT. The yield guard used to read
        // `is_numeric($current) && (int) $current !== 0`, which silently EXCLUDED non-numeric
        // values — so a callback that set the budget to something exotic inside the section
        // had it overwritten, and because the yield never fired, nothing was logged either.
        // That is the exact case the branch exists to respect, failing open and silent. The
        // test is "not a numeric zero", not "a numeric non-zero".
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        _pp_with_advisory_lock('pp_test_830_exotic', function () use ($wpdb) {
            $wpdb->reconnect_retries = 'unlimited'; // a drop-in-aware plugin, mid-section
            return true;
        }, false, 'test');

        $this->assertSame(
            'unlimited',
            $wpdb->rawBudget(),
            'A non-numeric in-section value is still someone else\'s decision and must survive.'
        );
    }

    public function testTheWhitelistTheWholeGuardRestsOnIsTheOneCoreApplies(): void
    {
        // THE LOAD-BEARING FACT, asserted behaviourally rather than by inspecting a
        // declaration. The guard can be a plain property write only because core's
        // wpdb::__set() refuses exactly four names and `reconnect_retries` is not among
        // them. This drives the double's copy of that whitelist through the same magic
        // accessor production uses: the budget must take, a whitelisted name must not.
        //
        // It replaces a ReflectionProperty check on the double's own visibility, which
        // could not fail from any change to lib/wp.php and so pinned nothing.
        $wpdb = new PP_Mock_Wpdb();

        $wpdb->reconnect_retries = 3;
        $this->assertSame(3, $wpdb->retriesNow(), 'reconnect_retries is NOT whitelisted, so the write must take.');

        $wpdb->table_charset = 'clobbered';
        $this->assertSame(
            'utf8mb4_sentinel',
            $wpdb->rawProperty('table_charset'),
            'table_charset IS whitelisted, so core refuses the write — if this ever changes, the '
            . 'double has stopped modelling core\'s __set and the guard\'s premise is unverified.'
        );
    }

    public function testTheDoubleActuallyDetectsAnUnguardedRelocation(): void
    {
        // WHO WATCHES THE WATCHMAN. `lock_voided` is the double's model of the #830 bug, and
        // it is asserted FALSE in several tests above and TRUE in none of them. If the
        // injection wiring ever drifts — GET_LOCK's return shape changes so $lock_held stops
        // being set, dead_after_lock gets renamed, the dead-statement condition is refactored
        // — every one of those assertFalse calls goes vacuously green in silence, including
        // the one carrying the comment "THE REGRESSION THIS EXISTS FOR".
        //
        // So prove the bug arm can still fire: put the budget back to core's default inside
        // the section, which is precisely the pre-#830 world, and the modelled self-heal must
        // relocate.
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->dead_after_lock = true;
        $GLOBALS['wpdb'] = $wpdb;

        _pp_with_advisory_lock('pp_test_830_selftest', function ($db) {
            $db->reconnect_retries = 5; // undo the guard for THIS section only
            return $db->get_var('SELECT meta_value FROM wp_postmeta WHERE post_id = 1');
        }, false, 'test');

        $this->assertTrue(
            $wpdb->lock_voided,
            'The double must still model the pre-#830 self-heal, or every assertFalse(lock_voided) '
            . 'in this file is vacuous.'
        );
    }

    public function testHandleThatSilentlyRefusesTheWriteIsPutBackAndRunsUnguarded(): void
    {
        // The degradation the BEST-EFFORT contract is actually written for, and the one the
        // throwing handle cannot reach: a __set that CLAMPS instead of raising. The read-back
        // is the only thing that can notice, and without the put-back the handle would be
        // left permanently clamped at the normalized value after every lock hold — a silent,
        // cumulative state leak on exactly the handles this arm exists to protect.
        $wpdb = new PP_ClampingBudget_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $seen = null;
        _pp_with_advisory_lock('pp_test_830_clamp', function () use ($wpdb, &$seen) {
            $seen = $wpdb->rawBudget();
            return true;
        }, false, 'test');

        $this->assertSame(5, $seen, 'A write that did not take is put back BEFORE the section runs, never left clamped.');
        $this->assertSame(5, $wpdb->rawBudget(), 'And the restore must not write over it again on the way out.');
    }

    public function testNonNumericBudgetIsLeftExactlyAsFound(): void
    {
        // "Only a numeric budget is ours to reason about" is a real branch, not a comment.
        // Without it the guard would write int 0 over a handle whose budget was 'unlimited'
        // and then hand an int back to the restore.
        $wpdb = new PP_OpaqueBudget_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $seen = null;
        _pp_with_advisory_lock('pp_test_830_opaque', function () use ($wpdb, &$seen) {
            $seen = $wpdb->rawBudget();
            return true;
        }, false, 'test');

        $this->assertSame('unlimited', $seen, 'A non-numeric budget is not ours to write 0 into.');
        $this->assertSame('unlimited', $wpdb->rawBudget(), 'And not ours to leave an int behind in.');
    }

    public function testARestoreThatRaisesStillReleasesTheLock(): void
    {
        // The restore's own catch exists so that a raising handle cannot strand the lock on
        // the way out. Reaching it needs a handle that ACCEPTS the suspension and only then
        // starts raising — the throwing handle makes the suspend return null, so the restore
        // exits at its `$saved === null` guard and the catch is never entered.
        $wpdb = new PP_TurnsHostile_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $result = _pp_with_advisory_lock('pp_test_830_turns', fn () => 'ok', false, 'test');

        $this->assertSame('ok', $result, 'The mutator\'s return must still reach the caller.');
        $this->assertSame(
            ['GET_LOCK', 'RELEASE_LOCK'],
            array_map(
                fn ($c) => strpos($c, 'RELEASE_LOCK') !== false ? 'RELEASE_LOCK' : 'GET_LOCK',
                $wpdb->lockCalls()
            ),
            'A restore that raises may not strand the lock.'
        );
    }

    public function testTokenOverrideLockInheritsTheGuardToo(): void
    {
        // THE OTHER LOCK FAMILY. Every other test here drives the per-post composition lock
        // or the engine directly, so nothing proved the token-override family inherits the
        // suspension — and the safety doc states the guarantee for all three families. The
        // #830 exposure on this path is its own in-lock read
        // (_pp_read_token_overrides_locked_strict) re-running on a relocated connection,
        // which no composition test can stand in for.
        $wpdb = new PP_Mock_Wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertTrue(pp_set_token_override('--color-accent', '#123456'));

        $inside = $wpdb->retriesInsideSection();
        $this->assertNotEmpty($inside, 'The token mutator must issue a statement inside the lock.');
        $this->assertSame(
            array_fill(0, count($inside), 0),
            $inside,
            'The token family inherits the suspension too.'
        );
        $this->assertSame(5, $wpdb->retriesAtRelease(), 'RELEASE_LOCK issued with the budget restored.');
    }

    public function testTokenOverrideDeadConnectionDoesNotRelocateOffTheLockedConnection(): void
    {
        $wpdb = new PP_Mock_Wpdb();
        $wpdb->dead_after_lock = true;
        $GLOBALS['wpdb'] = $wpdb;

        pp_set_token_override('--color-accent', '#abcdef');

        $this->assertFalse(
            $wpdb->lock_voided,
            'A dead connection inside the TOKEN lock must fail the statement, not self-heal onto '
            . 'a connection that never held it.'
        );
    }
}
