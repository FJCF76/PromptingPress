import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { randomUUID } from 'crypto';

/**
 * Action layer CLI round-trip: create a page and add a component via
 * `wp pp action execute`, then verify the front-end renders it.
 */

/** Run a WP-CLI command inside wp-env and return trimmed stdout. */
function wpCli(cmd: string): string {
  return execSync(`npx wp-env run cli ${cmd}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  }).trim();
}

/**
 * Extract the first balanced JSON object from wp-env CLI output. wp-env wraps
 * command output with status lines ("ℹ Starting...", "✔ Ran ... --params={...}")
 * and wp-cli adds a trailing "Success:" line — any of which can contain braces —
 * so we brace-match (string-aware) from the first "{" and ignore everything
 * after the matching "}". JSON.parse-to-end would choke on the trailing text.
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

/**
 * Run `wp pp operate inspect` and return its run_id. The action layer requires
 * a run token with a completed INSPECT step (step-ordering enforcement); the
 * token persists across wp-env CLI invocations, so one run-id covers a whole
 * create → add → publish sequence.
 */
function ppOperateInspect(): string {
  const data = parseCliJson(wpCli('wp pp operate inspect'), 'operate inspect');
  if (!data.run_id) throw new Error('operate inspect returned no run_id');
  return data.run_id as string;
}

/** Run `wp pp action execute` (requires a run-id) and return the parsed result JSON. */
function ppAction(name: string, params: Record<string, unknown>, runId: string): Record<string, unknown> {
  const json = JSON.stringify(params).replace(/'/g, "'\\''");
  const raw = wpCli(`wp pp action execute ${name} --run-id=${runId} --params='${json}'`);
  return parseCliJson(raw, `action ${name}`);
}

/**
 * Run `wp pp apply preflight` and record coverage for the run. Pass a postId to
 * cover page/section mutations on that page; omit it for a site-scoped preflight
 * (e.g. before create_page). #96: every DB-backed mutation needs a covering
 * preflight first.
 */
function ppPreflight(runId: string, postId?: number): void {
  const target = postId !== undefined ? ` --post_id=${postId}` : '';
  wpCli(`wp pp apply preflight --run-id=${runId}${target}`);
}

/**
 * Run a wp-env CLI command that is EXPECTED to fail (non-zero exit), returning
 * the combined stderr+stdout so the caller can assert on the error message.
 * Throws if the command unexpectedly succeeds.
 */
function wpCliExpectFail(cmd: string): string {
  try {
    execSync(`npx wp-env run cli ${cmd}`, { cwd: process.cwd(), encoding: 'utf-8', stdio: 'pipe' });
  } catch (e: any) {
    return `${e.stderr ?? ''}${e.stdout ?? ''}`;
  }
  throw new Error(`Expected command to fail but it succeeded: ${cmd}`);
}

/** Delete a page via WP-CLI inside wp-env. */
function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
}

test.describe('Action Layer CLI', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned */ }
      pageId = 0;
    }
  });

  test('create_page + add_component renders on front-end @smoke', async ({ page }) => {
    // 0. Obtain a run token (INSPECT step) — required by the action layer.
    const runId = ppOperateInspect();

    // 1. Site-scoped preflight, then create a page WITH a composition so it can
    //    later clear a page-scoped preflight (#96: mutations need a covering
    //    preflight; create_page is site-scoped).
    ppPreflight(runId);
    const createResult = ppAction('create_page', {
      title: 'E2E Action Test',
      composition: [{ component: 'hero', props: { title: 'Seed Hero' } }],
    }, runId);
    expect(createResult.ok).toBe(true);
    pageId = (createResult.target as any).post_id;
    expect(pageId).toBeGreaterThan(0);

    // 2. Preflight the new page, then add a hero component and publish.
    ppPreflight(runId, pageId);
    const addResult = ppAction('add_component', {
      post_id: pageId,
      component: 'hero',
      props: { title: 'CLI Hero Title' },
    }, runId);
    expect(addResult.ok).toBe(true);

    const pubResult = ppAction('publish_page', { post_id: pageId }, runId);
    expect(pubResult.ok).toBe(true);

    // 3. Navigate to the page and verify the hero renders
    await page.goto(`/?page_id=${pageId}`);
    const hero = page.locator('.hero').first();
    await expect(hero).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.hero__title').first()).toContainText('Seed Hero');
  });

  test('update_seo_meta renders non-ASCII meta description as UTF-8 (#471)', async ({ page }) => {
    const description = 'prueba áéíóú ñ —guion';
    const runId = ppOperateInspect();

    // 1. Create + publish a page so it renders on the front end.
    ppPreflight(runId);
    const createResult = ppAction('create_page', {
      title: 'E2E SEO Meta Test',
      composition: [{ component: 'hero', props: { title: 'Seed Hero' } }],
    }, runId);
    expect(createResult.ok).toBe(true);
    pageId = (createResult.target as any).post_id;

    // 2. Set the Spanish meta description through the real action path — the
    //    exact repro from #471, via WP-CLI with no JSON tooling in between.
    ppPreflight(runId, pageId);
    const seoResult = ppAction('update_seo_meta', {
      post_id: pageId,
      meta: { meta_description: description },
    }, runId);
    expect(seoResult.ok).toBe(true);

    const pubResult = ppAction('publish_page', { post_id: pageId }, runId);
    expect(pubResult.ok).toBe(true);

    // 3. The rendered <head> must carry the correct UTF-8 text, not the
    //    "u00e1"-mangled escapes that #471 stored.
    await page.goto(`/?page_id=${pageId}`);
    const content = await page
      .locator('head meta[name="description"]')
      .getAttribute('content');
    expect(content).toBe(description);
    expect(content).not.toContain('u00e1');
  });
});

/**
 * #96: every DB-backed mutation (action execute, operate patch) refuses to run
 * until the run has a completed PREFLIGHT covering its target. Previews stay
 * read-only and ungated.
 */
test.describe('Preflight-before-mutation gate (#96)', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned */ }
      pageId = 0;
    }
  });

  test('action execute with INSPECT but no PREFLIGHT is blocked', () => {
    const runId = ppOperateInspect();
    ppPreflight(runId); // site preflight only
    const created = ppAction('create_page', {
      title: 'Gate Test Page',
      composition: [{ component: 'hero', props: { title: 'Seed' } }],
    }, runId);
    pageId = (created.target as any).post_id;

    // No page-scoped preflight for pageId yet → page mutation must be refused.
    const json = JSON.stringify({ post_id: pageId, title: 'Renamed' }).replace(/'/g, "'\\''");
    const err = wpCliExpectFail(`wp pp action execute update_page_title --run-id=${runId} --params='${json}'`);
    expect(err).toContain('no completed PREFLIGHT covering post ' + pageId);
    expect(err).toContain('wp pp apply preflight');
  });

  test('action execute succeeds after a covering PREFLIGHT', () => {
    const runId = ppOperateInspect();
    ppPreflight(runId);
    const created = ppAction('create_page', {
      title: 'Gate Test Page 2',
      composition: [{ component: 'hero', props: { title: 'Seed' } }],
    }, runId);
    pageId = (created.target as any).post_id;

    ppPreflight(runId, pageId); // now covers the page
    const renamed = ppAction('update_page_title', { post_id: pageId, title: 'Renamed OK' }, runId);
    expect(renamed.ok).toBe(true);
  });

  test('operate patch mutation is blocked without a covering PREFLIGHT', () => {
    const runId = ppOperateInspect();
    ppPreflight(runId);
    const created = ppAction('create_page', {
      title: 'Patch Gate Page',
      composition: [{ component: 'hero', props: { title: 'Seed', subtitle: 'before' } }],
    }, runId);
    pageId = (created.target as any).post_id;

    const err = wpCliExpectFail(
      `wp pp operate patch ${pageId} --target=hero.subtitle --value="after" --run-id=${runId}`,
    );
    expect(err).toContain('no completed PREFLIGHT covering post ' + pageId);
  });

  test('operate patch --preview is read-only and needs no run-id', () => {
    const runId = ppOperateInspect();
    ppPreflight(runId);
    const created = ppAction('create_page', {
      title: 'Patch Preview Page',
      composition: [{ component: 'hero', props: { title: 'Seed', subtitle: 'before' } }],
    }, runId);
    pageId = (created.target as any).post_id;

    // No run-id, no preflight — preview must still work.
    const raw = wpCli(`wp pp operate patch ${pageId} --target=hero.subtitle --value="after" --preview`);
    const result = parseCliJson(raw, 'patch preview');
    expect(result.ok).toBe(true);
  });

  test('action preview needs no run-id and never mutates', () => {
    const runId = ppOperateInspect();
    ppPreflight(runId);
    const created = ppAction('create_page', {
      title: 'Action Preview Page',
      composition: [{ component: 'hero', props: { title: 'before' } }],
    }, runId);
    pageId = (created.target as any).post_id;

    // No run-id passed to preview at all.
    const json = JSON.stringify({ post_id: pageId, title: 'previewed' }).replace(/'/g, "'\\''");
    const raw = wpCli(`wp pp action preview update_page_title --params='${json}'`);
    const result = parseCliJson(raw, 'action preview');
    expect(result.ok).toBe(true);
  });
});

test.describe('Preflight fail-closed JSON result (#227)', () => {
  /**
   * stdout is the machine-readable channel: an AI operator branches on the
   * parsed `ok`. A preflight that cannot record its state must never emit
   * {"ok": true} — the recording failure has to be visible in the JSON
   * itself, not only in the exit code and stderr.
   */
  test('preflight with an unminted run token exits 1 with ok:false JSON on stdout', () => {
    // Syntactically valid UUID v4 that was never minted by `wp pp operate inspect`.
    const unminted = randomUUID();
    let stdout = '';
    let stderr = '';
    let failed = false;
    try {
      execSync(`npx wp-env run cli wp pp apply preflight --run-id=${unminted}`, {
        cwd: process.cwd(), encoding: 'utf-8', stdio: 'pipe',
      });
    } catch (e: any) {
      failed = true;
      stdout = e.stdout ?? '';
      stderr = e.stderr ?? '';
    }
    expect(failed).toBe(true);

    // The JSON on stdout must report the failure — parsed, not substring-matched.
    const json = parseCliJson(stdout, 'preflight (unminted token)');
    expect(json.ok).toBe(false);
    expect(typeof json.error).toBe('string');
    // #409 replaced the temp-file run-state store with the options-table store and
    // split the old blanket "Could not record PREFLIGHT state" message into distinct
    // causes (not-found / expired / foreign / corrupt) via pp_operate_run_status().
    // A syntactically valid UUID that was never minted classifies as `not_found`, so
    // the operator must see the NOT-FOUND message class specifically — pin that
    // distinct cause, not merely "some error". Asserting the not-found opener plus its
    // "never minted" detail keeps this from silently passing on the wrong class (e.g. a
    // regression that mis-reports an unminted token as expired/foreign/corrupt, or
    // reverts to the pre-#409 blanket text).
    expect(json.error as string).toContain('No run state found for run token');
    expect(json.error as string).toContain('never minted on this install');
    // The failure payload still carries the computed checks for diagnosis.
    expect(Array.isArray(json.checks)).toBe(true);

    // Never a success payload anywhere on stdout.
    expect(stdout).not.toContain('"ok": true');

    // Human-readable detail and the recovery hint go to stderr.
    expect(stderr).toContain('No run state found for run token');
    expect(stderr).toContain('wp pp operate inspect');
  });

  test('preflight with a minted run token still emits ok:true JSON on stdout', () => {
    const runId = ppOperateInspect();
    const raw = wpCli(`wp pp apply preflight --run-id=${runId}`);
    const json = parseCliJson(raw, 'preflight (minted token)');
    expect(json.ok).toBe(true);
    expect(Array.isArray(json.checks)).toBe(true);

    // ok:true must mean the PREFLIGHT state was actually recorded — prove it
    // by clearing the gate it unlocks (a site-scoped mutation succeeds).
    let pageId = 0;
    try {
      const created = ppAction('create_page', {
        title: 'Preflight Recorded Proof',
        composition: [{ component: 'hero', props: { title: 'Seed' } }],
      }, runId);
      expect(created.ok).toBe(true);
      pageId = (created.target as any).post_id;
    } finally {
      if (pageId) deletePage(pageId);
    }
  });

  test('preflight fails closed with ok:false JSON when the token baseline is unreadable (#207 corrupt row)', () => {
    const runId = ppOperateInspect();
    // Corrupt pp_token_overrides into a non-array so pp_snapshot_token_overrides()
    // returns null — the deterministic trigger for the snapshot-null failure exit.
    wpCli(`wp option update pp_token_overrides corrupted-scalar-value`);
    try {
      let stdout = '';
      let failed = false;
      try {
        execSync(`npx wp-env run cli wp pp apply preflight --run-id=${runId}`, {
          cwd: process.cwd(), encoding: 'utf-8', stdio: 'pipe',
        });
      } catch (e: any) {
        failed = true;
        stdout = e.stdout ?? '';
      }
      expect(failed).toBe(true);
      const json = parseCliJson(stdout, 'preflight (corrupt overrides row)');
      expect(json.ok).toBe(false);
      expect(json.error as string).toContain('atomic pre-apply token baseline');
      expect(stdout).not.toContain('"ok": true');
    } finally {
      // Remove the corrupt row so later runs see a genuinely absent option
      // (a valid empty baseline), leaving no test residue behind.
      wpCli(`wp option delete pp_token_overrides`);
    }
  });
});

/**
 * #62: a create_redirect makes a renamed/moved path 301 to its canonical target
 * instead of 404ing; remove_redirect restores the 404. Both actions are
 * site-scoped and resolve on template_redirect only for otherwise-unmatched
 * (404) requests.
 */
test.describe('Front-end redirects (#62)', () => {
  let pageId = 0;
  const fromPath = '/e2e-redirect-old-source';

  // The resolver fires on WordPress's 404, which only sees a slug path when
  // pretty permalinks route it through index.php. wp-env defaults to plain
  // permalinks (Apache 404s slug paths before PHP), so switch to the realistic
  // pretty-permalink scenario for this spec, then restore plain + a clean map.
  test.beforeAll(() => {
    wpCli(`wp rewrite structure '/%postname%/' --hard`);
    wpCli(`wp rewrite flush --hard`);
  });

  test.afterAll(() => {
    wpCli(`wp option delete pp_redirects || true`);
    wpCli(`wp rewrite structure '' `);
    wpCli(`wp rewrite flush`);
  });

  test.afterEach(() => {
    // Redirects are DB-backed and outlive the page, so always clear the map.
    try {
      const runId = ppOperateInspect();
      ppPreflight(runId);
      ppAction('remove_redirect', { from: fromPath }, runId);
    } catch { /* nothing to clean */ }
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned */ }
      pageId = 0;
    }
  });

  test('create_redirect 301s an old path to a live page, remove restores 404 @smoke', async ({ page, request }) => {
    const runId = ppOperateInspect();
    ppPreflight(runId);

    // A published target page the redirect points at.
    const created = ppAction('create_page', {
      title: 'E2E Redirect Target',
      composition: [{ component: 'hero', props: { title: 'Redirect Target Hero' } }],
      status: 'publish',
    }, runId);
    expect(created.ok).toBe(true);
    pageId = (created.target as any).post_id;

    const targetUrl = wpCli(`wp post get ${pageId} --field=url`);
    const parsed = new URL(targetUrl);
    const targetPath = parsed.pathname + parsed.search;

    // Before any redirect exists, the old path 404s.
    const before = await request.get(fromPath, { maxRedirects: 0 });
    expect(before.status()).toBe(404);

    // create_redirect is site-scoped — record a fresh site preflight, then add it.
    ppPreflight(runId);
    const redir = ppAction('create_redirect', { from: fromPath, to: targetPath }, runId);
    expect(redir.ok).toBe(true);

    // The old path now 301s to the target...
    const resp = await request.get(fromPath, { maxRedirects: 0 });
    expect(resp.status()).toBe(301);
    expect(resp.headers()['location']).toContain(targetPath);

    // ...which resolves 200 and renders the target page.
    await page.goto(fromPath);
    await expect(page.locator('.hero__title').first()).toContainText('Redirect Target Hero');

    // remove_redirect restores the original 404.
    ppPreflight(runId);
    const removed = ppAction('remove_redirect', { from: fromPath }, runId);
    expect(removed.ok).toBe(true);
    const after = await request.get(fromPath, { maxRedirects: 0 });
    expect(after.status()).toBe(404);
  });
});

/**
 * #113: a composition mutation is rejected when the composition changed since the
 * covering PREFLIGHT (freshness, not just ordering). A run's OWN sequential mutations
 * still flow (the baseline refreshes after each write); an EXTERNAL interleaved write
 * (here, a second run) makes the first run's next mutation stale. Previews stay ungated.
 */
test.describe('Preflight composition freshness (#113)', () => {
  let pageId = 0;

  // Seed one published page with a hero composition, freshly for each test.
  function seedPage(runId: string, title: string): number {
    ppPreflight(runId);
    const created = ppAction('create_page', {
      title,
      composition: [{ component: 'hero', props: { title: 'Seed', subtitle: 'before' } }],
      status: 'publish',
    }, runId);
    expect(created.ok).toBe(true);
    return (created.target as any).post_id;
  }

  test.afterEach(() => {
    if (pageId) {
      try { deletePage(pageId); } catch { /* already cleaned */ }
      pageId = 0;
    }
  });

  test('unchanged composition passes the freshness gate', () => {
    const runId = ppOperateInspect();
    pageId = seedPage(runId, 'Freshness Control Page');

    ppPreflight(runId, pageId);
    // No intervening change → the update lands.
    const ok = ppAction('update_component', {
      post_id: pageId, component_index: 0, props: { subtitle: 'after' },
    }, runId);
    expect(ok.ok).toBe(true);
  });

  test('a composition changed via another run is rejected as stale @smoke', () => {
    const runA = ppOperateInspect();
    pageId = seedPage(runA, 'Freshness Stale Page');

    // Run A preflights the page (records the marker as its baseline).
    ppPreflight(runA, pageId);

    // A SECOND run mutates the same page's composition, bumping the marker.
    const runB = ppOperateInspect();
    ppPreflight(runB, pageId);
    const bWrite = ppAction('update_component', {
      post_id: pageId, component_index: 0, props: { subtitle: 'changed-by-B' },
    }, runB);
    expect(bWrite.ok).toBe(true);

    // Run A's mutation now sees a stale baseline → rejected with a distinct conflict.
    const json = JSON.stringify({ post_id: pageId, component_index: 0, props: { subtitle: 'A-too-late' } }).replace(/'/g, "'\\''");
    const err = wpCliExpectFail(`wp pp action execute update_component --run-id=${runA} --params='${json}'`);
    expect(err).toContain('Stale preflight for post ' + pageId);
    expect(err).toContain('composition_conflict');
  });

  test("a run's own sequential mutations keep passing (baseline refresh)", () => {
    const runId = ppOperateInspect();
    pageId = seedPage(runId, 'Freshness Same-Run Page');

    ppPreflight(runId, pageId);
    // First mutation bumps the marker AND refreshes this run's baseline.
    const first = ppAction('add_component', {
      post_id: pageId, component: 'hero', props: { title: 'Second Hero' },
    }, runId);
    expect(first.ok).toBe(true);

    // Second mutation in the SAME run must still pass despite the marker having moved.
    const second = ppAction('update_component', {
      post_id: pageId, component_index: 0, props: { subtitle: 'after' },
    }, runId);
    expect(second.ok).toBe(true);
  });

  test('operate patch is rejected when the composition changed since preflight', () => {
    const runA = ppOperateInspect();
    pageId = seedPage(runA, 'Freshness Patch Page');
    ppPreflight(runA, pageId);

    // External change via a second run.
    const runB = ppOperateInspect();
    ppPreflight(runB, pageId);
    ppAction('update_component', { post_id: pageId, component_index: 0, props: { subtitle: 'b' } }, runB);

    const err = wpCliExpectFail(
      `wp pp operate patch ${pageId} --target=hero.subtitle --value="a" --run-id=${runA}`,
    );
    expect(err).toContain('Stale preflight for post ' + pageId);
  });

  test('preview is never blocked by a stale composition', () => {
    const runId = ppOperateInspect();
    pageId = seedPage(runId, 'Freshness Preview Page');

    // Change the composition after seeding, without any preflight for a preview run.
    const runB = ppOperateInspect();
    ppPreflight(runB, pageId);
    ppAction('update_component', { post_id: pageId, component_index: 0, props: { subtitle: 'b' } }, runB);

    // Preview needs no run-id and must still work despite the marker having moved.
    const raw = wpCli(`wp pp operate patch ${pageId} --target=hero.subtitle --value="preview-only" --preview`);
    const result = parseCliJson(raw, 'patch preview');
    expect(result.ok).toBe(true);
  });
});
