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

/** Run `wp pp action execute` and return the parsed result JSON. */
function ppAction(name: string, params: Record<string, unknown>): Record<string, unknown> {
  const json = JSON.stringify(params).replace(/'/g, "'\\''");
  const raw = wpCli(`wp pp action execute ${name} --params='${json}'`);
  // Output has WP-CLI success/table prefix lines; find the JSON object
  const jsonStart = raw.indexOf('{');
  if (jsonStart === -1) throw new Error('No JSON in action output: ' + raw);
  return JSON.parse(raw.slice(jsonStart));
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

  test('create_page + add_component renders on front-end', async ({ page }) => {
    // 1. Create a page via the action layer
    const createResult = ppAction('create_page', { title: 'E2E Action Test' });
    expect(createResult.ok).toBe(true);
    pageId = (createResult.target as any).post_id;
    expect(pageId).toBeGreaterThan(0);

    // 2. Add a hero component via the action layer
    const addResult = ppAction('add_component', {
      post_id: pageId,
      component: 'hero',
      props: { title: 'CLI Hero Title' },
    });
    expect(addResult.ok).toBe(true);

    // 3. Publish the page via the action layer
    const pubResult = ppAction('publish_page', { post_id: pageId });
    expect(pubResult.ok).toBe(true);

    // 4. Navigate to the page and verify the hero renders
    await page.goto(`/?page_id=${pageId}`);
    const hero = page.locator('.hero');
    await expect(hero).toBeVisible({ timeout: 10000 });
    await expect(hero.locator('.hero__title')).toContainText('CLI Hero Title');
  });
});
