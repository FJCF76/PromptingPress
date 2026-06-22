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
 * Run `wp pp operate inspect` and return its run_id. The action layer requires
 * a run token with a completed INSPECT step (step-ordering enforcement); the
 * token persists across wp-env CLI invocations, so one run-id covers a whole
 * create → add → publish sequence.
 */
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

    // 1. Create a page via the action layer
    const createResult = ppAction('create_page', { title: 'E2E Action Test' }, runId);
    expect(createResult.ok).toBe(true);
    pageId = (createResult.target as any).post_id;
    expect(pageId).toBeGreaterThan(0);

    // 2. Add a hero component via the action layer
    const addResult = ppAction('add_component', {
      post_id: pageId,
      component: 'hero',
      props: { title: 'CLI Hero Title' },
    }, runId);
    expect(addResult.ok).toBe(true);

    // 3. Publish the page via the action layer
    const pubResult = ppAction('publish_page', { post_id: pageId }, runId);
    expect(pubResult.ok).toBe(true);

    // 4. Navigate to the page and verify the hero renders
    await page.goto(`/?page_id=${pageId}`);
    const hero = page.locator('.hero');
    await expect(hero).toBeVisible({ timeout: 10000 });
    await expect(hero.locator('.hero__title')).toContainText('CLI Hero Title');
  });
});
