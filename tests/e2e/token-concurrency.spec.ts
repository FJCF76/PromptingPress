import { test, expect } from '@playwright/test';
import { execSync, spawn } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Real WP+MySQL concurrency harness for token applies (#98).
 *
 * #97 added a MySQL advisory lock (`GET_LOCK`) around `pp_token_overrides`
 * read-modify-write cycles so concurrent applies (agents parallelizing tool
 * calls) can't silently lose one writer's update. The PHPUnit suite only
 * proves the lock is *invoked* inside a single shared PHP process/DB
 * connection — it cannot prove the lock actually serializes two INDEPENDENT
 * MySQL connections under real concurrent load.
 *
 * This spec drives `wp pp apply execute update_design_token` through
 * wp-env's real WordPress + MySQL container, launching genuinely separate
 * `wp-env run cli` processes (each its own OS process and DB connection) via
 * async `spawn` + `Promise.all` rather than sequential `execSync`, so the two
 * writes actually overlap in time.
 *
 * Three scenarios:
 *   1. Two concurrent writers touching DIFFERENT tokens: both writes must
 *      survive (no lost update).
 *   2. Two concurrent writers touching the SAME token: exactly one clean
 *      value must win — no torn/interleaved write. This is the actual race
 *      #97's lock defends against; (1) alone doesn't prove same-key
 *      contention resolves cleanly.
 *   3. A writer that starts while another real connection holds the same
 *      advisory lock: it must fail closed (explicit failure, GET_LOCK times
 *      out) rather than silently skip the lock and clobber. Made
 *      deterministic via a marker-file handshake rather than a timing guess:
 *      the holder verifies its OWN GET_LOCK actually succeeded before
 *      signaling "acquired" (an unchecked/failed acquisition would let the
 *      contender through cleanly and invalidate the test), then waits for the
 *      contender to signal "about to call execute" before holding for the
 *      install's real `_pp_token_lock_timeout()` (read at runtime, not
 *      assumed) plus a small margin — anchored to the contender's actual
 *      readiness rather than a guessed multi-step wall-clock budget.
 *
 * `--color-bg` and `--space-md` are used because neither has a derived token
 * family (see `pp_token_families()` in lib/wp.php) — an unrelated token's
 * derived writes would otherwise obscure whether the SPECIFIC keys under
 * test survived.
 */

const TOKEN_A = '--color-bg';
const VALUE_A = '#0f4c81';
const TOKEN_B = '--space-md';
const VALUE_B = '24px';

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * wp-env prints its own "ℹ Starting '<the command as invoked>' ..." and
 * "✔ Ran `<the command as invoked>` ..." lines around the command's real
 * stdout. When the command includes `--params='{"token":...}'`, that echoed
 * command text contains its OWN balanced `{...}` fragment BEFORE the real
 * (pretty-printed) result JSON — a naive "brace-match from the first {" would
 * lock onto the echoed params object instead of the actual result. Stripping
 * these wrapper lines first removes the spurious braces at the source.
 */
function stripWpEnvNoise(raw: string): string {
  return raw
    .split('\n')
    .filter((line) => {
      // wp-env colorizes these lines with ANSI escape codes around the leading
      // icon, so a plain startsWith on the raw line never matches — strip
      // escape sequences first.
      const t = line.replace(/\x1b\[[0-9;]*m/g, '').trim();
      return !(t.startsWith('ℹ Starting') || t.startsWith('✔ Ran') || t.startsWith('✖'));
    })
    .join('\n')
    .trim();
}

/** Run a WP-CLI command inside wp-env synchronously and return its cleaned stdout. */
function wpCli(cmd: string): string {
  const raw = execSync(`npx wp-env run cli ${cmd}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
  return stripWpEnvNoise(raw);
}

/**
 * Run a WP-CLI command inside wp-env asynchronously (does not block the
 * event loop), resolving with the exit code once the process closes. Used so
 * two `wp-env run cli` invocations can genuinely overlap via `Promise.all`
 * instead of running one after another.
 *
 * stdout and stderr are captured SEPARATELY, not merged: JSON extraction
 * (`parseCliJson`) only ever looks at `stdout`. A PHP deprecation/warning/notice
 * on stderr could otherwise inject a stray `{` ahead of the real JSON and hijack
 * the brace-match — the same class of problem `stripWpEnvNoise` exists to guard
 * against for wp-env's own banner text, but from the invoked PHP process itself.
 * `output` (stdout+stderr combined, for error messages/diagnostics only) is also
 * returned so callers can show full context on failure.
 */
function wpCliAsync(cmd: string): Promise<{ code: number; stdout: string; output: string }> {
  return new Promise((resolve) => {
    const child = spawn(`npx wp-env run cli ${cmd}`, {
      cwd: process.cwd(),
      shell: true,
    });
    let stdout = '';
    let combined = '';
    child.stdout.on('data', (d) => {
      const s = d.toString();
      stdout += s;
      combined += s;
    });
    child.stderr.on('data', (d) => (combined += d.toString()));
    child.on('close', (code) =>
      resolve({ code: code ?? -1, stdout: stripWpEnvNoise(stdout), output: stripWpEnvNoise(combined) }),
    );
  });
}

/**
 * Extract the first balanced JSON object from (already wrapper-stripped)
 * wp-env CLI output, brace-matching (string-aware) rather than
 * JSON.parse-ing the whole blob (which also carries a trailing "Success:"/
 * "Error:" line).
 */
function parseCliJson(raw: string, what: string): Record<string, unknown> {
  const start = raw.indexOf('{');
  if (start === -1) throw new Error(`No JSON object in ${what} output: ${raw}`);
  let depth = 0, inStr = false, esc = false;
  for (let i = start; i < raw.length; i++) {
    const c = raw[i];
    if (inStr) {
      if (esc) esc = false;
      else if (c === '\\') esc = true;
      else if (c === '"') inStr = false;
    } else if (c === '"') {
      inStr = true;
    } else if (c === '{') {
      depth++;
    } else if (c === '}') {
      depth--;
      if (depth === 0) return JSON.parse(raw.slice(start, i + 1));
    }
  }
  throw new Error(`Unbalanced JSON in ${what} output: ${raw}`);
}

/** Run `wp pp operate inspect` and return its run_id. */
function newRunId(): string {
  const data = parseCliJson(wpCli('wp pp operate inspect'), 'operate inspect');
  if (!data.run_id) throw new Error('operate inspect returned no run_id');
  return data.run_id as string;
}

/** Preflight a run for the `update_design_token` apply so `execute`/`reset` will unlock. */
function preflightForTokenApply(runId: string): void {
  wpCli(`wp pp apply preflight --run-id=${runId} --apply=update_design_token`);
}

/** Fire `wp pp apply execute update_design_token` asynchronously; parses the result JSON. */
async function executeTokenApplyAsync(
  runId: string,
  token: string,
  value: string,
): Promise<{ ok: boolean; raw: string }> {
  const json = JSON.stringify({ token, value }).replace(/'/g, "'\\''");
  const { stdout, output } = await wpCliAsync(
    `wp pp apply execute update_design_token --run-id=${runId} --params='${json}'`,
  );
  let ok = false;
  try {
    ok = parseCliJson(stdout, 'apply execute').ok === true;
  } catch {
    /* leave ok=false; raw output carries the diagnostic */
  }
  return { ok, raw: output };
}

/** Reads the effective GET_LOCK wait (`_pp_token_lock_timeout()`) from the running install. */
function readEffectiveLockTimeout(): number {
  const raw = wpCli(`wp eval 'echo _pp_token_lock_timeout();'`);
  const n = parseInt(raw, 10);
  if (!Number.isFinite(n) || n <= 0) {
    throw new Error(`_pp_token_lock_timeout() returned an unexpected value: "${raw}"`);
  }
  return n;
}

/** Reads pp_token_overrides via CLI; returns {} if the option doesn't exist yet. */
function readTokenOverrides(): Record<string, unknown> {
  try {
    return parseCliJson(wpCli('wp option get pp_token_overrides --format=json'), 'option get');
  } catch {
    return {};
  }
}

/** Resets a single token to its product default (best-effort; ignores failures during cleanup). */
function resetToken(token: string): void {
  try {
    const runId = newRunId();
    preflightForTokenApply(runId);
    wpCli(`wp pp apply reset --run-id=${runId} --token=${token}`);
  } catch {
    /* nothing to reset, or already clean */
  }
}

/** Absolute path to the active theme directory INSIDE the wp-env container. */
function containerThemeDir(): string {
  return wpCli(`wp eval 'echo WP_CONTENT_DIR . "/themes/" . get_stylesheet();'`);
}

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Token override concurrency (real WP+MySQL, #98)', () => {
  test.afterEach(() => {
    resetToken(TOKEN_A);
    resetToken(TOKEN_B);
  });

  test('two concurrent writers on different tokens both survive (no lost update)', async () => {
    // Two independent run tokens so each writer's preflight/execute is its own
    // covered run; the INSPECT+PREFLIGHT setup is sequential (cheap, no shared
    // state risk) — only the two `execute` calls below need to overlap.
    const runA = newRunId();
    preflightForTokenApply(runA);
    const runB = newRunId();
    preflightForTokenApply(runB);

    // Two genuinely separate wp-env CLI processes (separate OS processes,
    // separate MySQL connections) firing their read-modify-write at the same
    // time via Promise.all, not one after another.
    const [resA, resB] = await Promise.all([
      executeTokenApplyAsync(runA, TOKEN_A, VALUE_A),
      executeTokenApplyAsync(runB, TOKEN_B, VALUE_B),
    ]);

    expect(resA.ok, `worker A (token ${TOKEN_A}) did not succeed:\n${resA.raw}`).toBe(true);
    expect(resB.ok, `worker B (token ${TOKEN_B}) did not succeed:\n${resB.raw}`).toBe(true);

    const overrides = readTokenOverrides();
    expect(overrides[TOKEN_A], 'token A survived the concurrent write').toBe(VALUE_A);
    expect(overrides[TOKEN_B], 'token B survived the concurrent write').toBe(VALUE_B);
  });

  test('two concurrent writers on the SAME token: exactly one clean value wins (no torn write)', async () => {
    // Different-token contention (above) proves independent keys don't clobber
    // each other, but the actual hazard #97's lock defends against is two writers
    // hitting the SAME key at once — a real read-modify-write race on one map
    // entry. This proves the lock serializes that case into a clean single
    // winner rather than a corrupted/interleaved value.
    const runA = newRunId();
    preflightForTokenApply(runA);
    const runB = newRunId();
    preflightForTokenApply(runB);

    const [resA, resB] = await Promise.all([
      executeTokenApplyAsync(runA, TOKEN_A, '#111111'),
      executeTokenApplyAsync(runB, TOKEN_A, '#222222'),
    ]);

    expect(resA.ok, `worker A did not succeed:\n${resA.raw}`).toBe(true);
    expect(resB.ok, `worker B did not succeed:\n${resB.raw}`).toBe(true);

    const overrides = readTokenOverrides();
    expect(['#111111', '#222222']).toContain(overrides[TOKEN_A]);
  });

  test('a writer fails closed (not silently lost) while another connection holds the lock', async () => {
    test.setTimeout(60000);

    fs.mkdirSync(path.join(process.cwd(), 'test-results'), { recursive: true });
    const themeDir = containerThemeDir();
    // The install's actual GET_LOCK wait, read at runtime rather than assumed —
    // `_pp_token_lock_timeout()` defaults to 5s but is overridable via the
    // PP_TOKEN_LOCK_TIMEOUT constant, so hardcoding "5" would silently drift
    // from whatever this install is really configured with.
    const effectiveTimeout = readEffectiveLockTimeout();

    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    // Markers live under test-results/ (already gitignored Playwright output
    // dir) rather than the repo root, so an abnormal process kill between
    // marker-creation and the `finally` cleanup below can't leave a stray file
    // in the actual git working tree.
    const acquired = `test-results/.pp-lock-acquired-${id}`;
    const failed = `test-results/.pp-lock-failed-${id}`;
    const ready = `test-results/.pp-lock-ready-${id}`;
    const hostPath = (rel: string) => path.join(process.cwd(), rel);
    const containerPath = (rel: string) => `${themeDir}/${rel}`;

    // Holder: acquires the SAME named advisory lock `_pp_with_token_lock()`
    // uses. Two-marker handshake instead of a guessed fixed sleep:
    //   1. Verify GET_LOCK actually returned '1' before claiming "acquired" —
    //      signaling success on an unchecked/failed acquisition would let the
    //      contender acquire the real lock cleanly and the test would assert
    //      the wrong thing (false pass OR spurious failure).
    //   2. Wait for the CONTENDER to signal "about to call execute" (bounded
    //      poll, 20s cap) instead of guessing how long its setup takes.
    //   3. THEN hold for effectiveTimeout+3s — long enough that the contender's
    //      own GET_LOCK(name, effectiveTimeout) call (which starts shortly after
    //      its ready-signal, once its fresh wp-env container finishes bootstrap)
    //      is guaranteed to still be waiting, so it genuinely times out rather
    //      than racing the release.
    // This anchors the hold to the contender's REAL readiness signal rather
    // than a multi-step wall-clock estimate of its setup time — the earlier
    // version's HOLD_SECS=25 (measured from marker-time, before this fix)
    // let the contender acquire the lock 5ms after release in one run, because
    // its budget was a guess, not a signal.
    // Written with double-quoted PHP string literals and wrapped in shell
    // SINGLE quotes (not escaped double quotes) so `$wpdb` reaches PHP
    // literally instead of being shell-expanded to empty before `wp eval` ever
    // sees it.
    const READY_WAIT_MAX = 20;
    const holdAfterReady = effectiveTimeout + 3;
    const holderPhp =
      'global $wpdb; ' +
      '$got = $wpdb->get_var("SELECT GET_LOCK(" . $wpdb->prepare("%s", _pp_token_lock_name()) . ", 10)"); ' +
      'if ($got !== "1") { ' +
      `file_put_contents("${containerPath(failed)}", (string) $got); ` +
      'exit(1); ' +
      '} ' +
      `file_put_contents("${containerPath(acquired)}", "1"); ` +
      `$deadline = time() + ${READY_WAIT_MAX}; ` +
      `while (!file_exists("${containerPath(ready)}") && time() < $deadline) { usleep(100000); } ` +
      `sleep(${holdAfterReady}); ` +
      '$wpdb->query("SELECT RELEASE_LOCK(" . $wpdb->prepare("%s", _pp_token_lock_name()) . ")");';
    const holderPromise = wpCliAsync(`wp eval '${holderPhp}'`);

    try {
      // Wait for the holder to signal EITHER real acquisition or a failed
      // GET_LOCK attempt. Deterministic via marker files (bind-mounted, so the
      // host sees them immediately) rather than a fixed sleep.
      const deadline = Date.now() + 15000;
      while (!fs.existsSync(hostPath(acquired)) && !fs.existsSync(hostPath(failed))) {
        if (Date.now() > deadline) {
          throw new Error('Timed out waiting for the lock-holder to signal acquisition.');
        }
        await new Promise((r) => setTimeout(r, 100));
      }
      if (fs.existsSync(hostPath(failed))) {
        const got = fs.readFileSync(hostPath(failed), 'utf-8');
        throw new Error(`Lock holder failed to acquire its own GET_LOCK (returned ${got}) — cannot run this test.`);
      }

      const before = readTokenOverrides();

      const runC = newRunId();
      preflightForTokenApply(runC);

      // Signal readiness immediately before the contended call itself, so the
      // holder's remaining hold time is anchored to this real moment rather
      // than a guess about how long setup takes.
      fs.writeFileSync(hostPath(ready), '1');
      const contender = await executeTokenApplyAsync(runC, TOKEN_A, '#ffffff');

      expect(
        contender.ok,
        `contender must fail closed while the lock is held, not silently write:\n${contender.raw}`,
      ).toBe(false);

      const after = readTokenOverrides();
      expect(after[TOKEN_A], 'contender must not have written its value').not.toBe('#ffffff');
      expect(after[TOKEN_A]).toBe(before[TOKEN_A]);
    } finally {
      await holderPromise;
      for (const rel of [acquired, failed, ready]) {
        fs.rmSync(hostPath(rel), { force: true });
      }
    }
  });
});
