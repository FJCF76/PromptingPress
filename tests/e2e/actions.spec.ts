import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

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
