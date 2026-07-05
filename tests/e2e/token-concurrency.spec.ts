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
 * Two scenarios:
 *   1. Two concurrent writers touching DIFFERENT tokens: both writes must
 *      survive (no lost update).
 *   2. A writer that starts while another real connection holds the same
 *      advisory lock: it must fail closed (explicit failure, GET_LOCK times
 *      out) rather than silently skip the lock and clobber. To make this
 *      deterministic rather than timing-sensitive, the lock holder signals
 *      "lock acquired" via a marker file on the bind-mounted theme directory
 *      (shared between the host and every wp-env container) instead of a
 *      guessed sleep.
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
 * event loop), resolving with the exit code and cleaned combined output once
 * the process closes. Used so two `wp-env run cli` invocations can genuinely
 * overlap via `Promise.all` instead of running one after another.
 */
function wpCliAsync(cmd: string): Promise<{ code: number; output: string }> {
  return new Promise((resolve) => {
    const child = spawn(`npx wp-env run cli ${cmd}`, {
      cwd: process.cwd(),
      shell: true,
    });
    let output = '';
    child.stdout.on('data', (d) => (output += d.toString()));
    child.stderr.on('data', (d) => (output += d.toString()));
    child.on('close', (code) => resolve({ code: code ?? -1, output: stripWpEnvNoise(output) }));
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
  const { output } = await wpCliAsync(
    `wp pp apply execute update_design_token --run-id=${runId} --params='${json}'`,
  );
  let ok = false;
  try {
    ok = parseCliJson(output, 'apply execute').ok === true;
  } catch {
    /* leave ok=false; raw output carries the diagnostic */
  }
  return { ok, raw: output };
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

  test('a writer fails closed (not silently lost) while another connection holds the lock', async () => {
    test.setTimeout(90000);

    const themeDir = containerThemeDir();
    const marker = `.pp-lock-marker-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const markerContainerPath = `${themeDir}/${marker}`;
    const markerHostPath = path.join(process.cwd(), marker);

    // Holder: acquires the SAME named advisory lock `_pp_with_token_lock()` uses,
    // signals acquisition via a marker file on the bind-mounted theme directory
    // (visible from the host immediately — no guessed sleep needed to know when
    // the lock is actually held), then holds it for HOLD_SECS before releasing.
    // HOLD_SECS must comfortably outlast not just the contender's GET_LOCK wait
    // (the default 5s _pp_token_lock_timeout()) but everything BEFORE that wait
    // even starts — the contender's own newRunId()+preflight setup (~2 wp-env
    // invocations, ~1s each) runs AFTER the marker appears. Too short a hold and
    // the holder could release WHILE the contender is still mid-setup, letting
    // it acquire the lock cleanly instead of proving the fail-closed path.
    // Written with double-quoted PHP string literals and wrapped in shell
    // SINGLE quotes (not escaped double quotes) so `$wpdb` reaches PHP
    // literally instead of being shell-expanded to empty before `wp eval` ever
    // sees it.
    //
    // Empirically, the contender's own GET_LOCK call doesn't start the instant
    // its wp-env process launches — WordPress bootstrap inside that fresh
    // container adds ~1s first — so its 5s wait window lands roughly
    // 1s-after-preflight-finishes to 6s-after. A HOLD_SECS too close to that
    // window's end is a real race (measured: HOLD_SECS=15 let the contender
    // acquire the lock 5ms after the holder released, instead of timing out).
    // 25s leaves a comfortable margin.
    const HOLD_SECS = 25;
    const holderPhp =
      'global $wpdb; ' +
      '$wpdb->get_var("SELECT GET_LOCK(" . $wpdb->prepare("%s", _pp_token_lock_name()) . ", 10)"); ' +
      `file_put_contents("${markerContainerPath}", "1"); ` +
      `sleep(${HOLD_SECS}); ` +
      '$wpdb->query("SELECT RELEASE_LOCK(" . $wpdb->prepare("%s", _pp_token_lock_name()) . ")");';
    const holderPromise = wpCliAsync(`wp eval '${holderPhp}'`);

    try {
      // Wait for the holder to actually have the lock. Deterministic via the
      // marker file rather than a fixed sleep — wp-env's per-invocation
      // container startup time is not itself predictable.
      const deadline = Date.now() + 15000;
      while (!fs.existsSync(markerHostPath)) {
        if (Date.now() > deadline) {
          throw new Error('Timed out waiting for the lock-holder marker file.');
        }
        await new Promise((r) => setTimeout(r, 100));
      }

      const before = readTokenOverrides();

      // The default GET_LOCK timeout (_pp_token_lock_timeout(), 5s) is shorter
      // than the holder's HOLD_SECS hold (measured from marker-time), so this
      // contender's lock acquisition times out while the holder still has it —
      // proving the fail-closed path, not just the happy path.
      const runC = newRunId();
      preflightForTokenApply(runC);
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
      fs.rmSync(markerHostPath, { force: true });
    }
  });
});
