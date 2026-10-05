import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'child_process';

/**
 * The custom band and its content islands, live (LAYER-3-CONTRACT.md §7, #1242 T5).
 *
 *   1. AUTHOR the band through the CLI's structural write (`wp pp action execute
 *      create_page`), edit ONE island through `wp pp operate patch`, and see `markup`
 *      refused there by name (P-7);
 *   2. RENDER the page and read the browser's DOM: each island's content sits in its host,
 *      the sibling island survived the patch, and no `data-pp-*` attribute from content
 *      reaches the page (the E6 emission belt) beside the template's own band root;
 *   3. the ISOLATED preview (#1246) renders the same band in an opaque-origin frame.
 *
 * Plus the routed-item-10 refusal through the same CLI: island content that would
 * restructure the markup in its host's context is refused and nothing is stored.
 */

const CWD = process.cwd();
const wpRaw = (...args: string[]): string =>
  execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: CWD, encoding: 'utf-8' });

/** Brace-matched first JSON object in CLI output (wp-env adds status lines around it). */
function firstJson(raw: string): Record<string, any> {
  const start = raw.indexOf('{');
  let depth = 0, inStr = false, esc = false;
  for (let i = start; start !== -1 && i < raw.length; i++) {
    const c = raw[i];
    if (inStr) { if (esc) esc = false; else if (c === '\\') esc = true; else if (c === '"') inStr = false; }
    else if (c === '"') inStr = true;
    else if (c === '{') depth++;
    else if (c === '}' && --depth === 0) return JSON.parse(raw.slice(start, i + 1));
  }
  throw new Error(`no JSON object in: ${raw}`);
}

function failing(...args: string[]): string {
  try {
    wpRaw(...args);
  } catch (e: any) {
    return `${e.stderr ?? ''}${e.stdout ?? ''}`;
  }
  throw new Error(`expected the command to fail: wp ${args.join(' ')}`);
}

const MARKUP = '<div class="e2e-split"><h2 data-pp-island="title" data-pp-island-kind="inline"></h2>'
  + '<div data-pp-island="body" data-pp-island-kind="rich"></div>'
  + '<a class="btn" href="/start"><span data-pp-island="cta"></span></a></div>';
const ISLANDS = {
  title: 'Ship <em>faster</em>',
  body: '<p>One band, <strong>your</strong> structure.</p>',
  cta: 'Get started',
};

test.describe('custom band and islands', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) {
      try { wpRaw('post', 'delete', String(pageId), '--force'); } catch { /* already gone */ }
      pageId = 0;
    }
  });

  async function checkBand(page: Page, root: ReturnType<Page['locator']>): Promise<void> {
    await expect(root.locator('h2')).toHaveText('Ship faster');
    await expect(root.locator('h2 em')).toHaveText('faster');
    await expect(root.locator('div > div > p strong')).toHaveText('your');
    await expect(root.locator('a.btn span')).toHaveText('Edited CTA');
  }

  test('author by CLI, patch one island, render, and preview in isolation @smoke', async ({ page }) => {
    const runId = firstJson(wpRaw('pp', 'operate', 'inspect')).run_id as string;
    wpRaw('pp', 'apply', 'preflight', `--run-id=${runId}`);
    const created = firstJson(wpRaw('pp', 'action', 'execute', 'create_page', `--run-id=${runId}`,
      `--params=${JSON.stringify({ title: 'E2E Custom Band', status: 'publish',
        composition: [{ component: 'custom', props: { markup: MARKUP, islands: ISLANDS } }] })}`));
    expect(created.ok, JSON.stringify(created)).toBe(true);
    pageId = created.target.post_id;

    wpRaw('pp', 'apply', 'preflight', `--post_id=${pageId}`, `--run-id=${runId}`);
    const patched = firstJson(wpRaw('pp', 'operate', 'patch', `--post_id=${pageId}`,
      '--target=custom.islands.cta', '--value=Edited CTA', `--run-id=${runId}`));
    expect(patched.ok, JSON.stringify(patched)).toBe(true);
    expect(patched.changes).toEqual([{ path: 'composition[0].props.islands.cta', from: 'Get started', to: 'Edited CTA' }]);

    const p7 = failing('pp', 'operate', 'patch', `--post_id=${pageId}`, '--target=custom.markup',
      '--value=<p>x</p>', `--run-id=${runId}`);
    expect(p7).toContain('structural');
    expect(p7).toContain('P-7');

    // The rendered page: what a visitor's browser builds.
    await page.goto(`/?page_id=${pageId}`);
    const band = page.locator('section.custom[data-pp-component="custom"]');
    await expect(band).toHaveCount(1);
    await checkBand(page, band);
    const leaked = await band.evaluate((root) => Array.from(root.querySelectorAll('*'))
      .flatMap((el) => Array.from(el.attributes).map((a) => a.name))
      .filter((n) => n.startsWith('data-pp-')));
    expect(leaked, 'no engine attribute from content reaches the page').toEqual([]);

    // The isolated preview (#1246) renders the same band.
    await page.goto(`/wp-admin/admin.php?page=pp-composition&post=${pageId}`);
    await expect(page.locator('#pp-workspace')).toBeVisible();
    const preview = page.frameLocator('#pp-preview-frame');
    const previewBand = preview.locator('section.custom');
    await expect(previewBand.locator('a.btn span')).toHaveText('Edited CTA', { timeout: 15000 });
    await checkBand(page, previewBand);
    await expect(page.locator('#pp-preview-frame')).toHaveAttribute('sandbox', 'allow-scripts');
    const frame = await (await page.locator('#pp-preview-frame').elementHandle())!.contentFrame();
    expect(await frame!.evaluate(() => self.origin), 'the preview is an opaque origin').toBe('null');

    // The accordion shows the markup without a control and one box per island.
    await expect(page.locator('.pp-accordion-structural')).toHaveCount(1);
    await expect(page.locator('textarea[data-map-key="cta"]')).toHaveValue('Edited CTA');
  });

  test('island content that restructures its host context is refused and nothing is stored @smoke', async () => {
    const runId = firstJson(wpRaw('pp', 'operate', 'inspect')).run_id as string;
    wpRaw('pp', 'apply', 'preflight', `--run-id=${runId}`);
    const created = firstJson(wpRaw('pp', 'action', 'execute', 'create_page', `--run-id=${runId}`,
      `--params=${JSON.stringify({ title: 'E2E Custom Refusal',
        composition: [{ component: 'custom', props: { markup: '<p>start</p>' } }] })}`));
    pageId = created.target.post_id;
    wpRaw('pp', 'apply', 'preflight', `--post_id=${pageId}`, `--run-id=${runId}`);

    const refused = failing('pp', 'action', 'execute', 'update_composition', `--run-id=${runId}`,
      `--params=${JSON.stringify({ post_id: pageId, composition: [{ component: 'custom', props: {
        markup: '<a href="/x"><span data-pp-island="q" data-pp-island-kind="inline"></span> after</a>',
        islands: { q: '<a href="/y">in</a>' } } }] })}`);
    expect(refused).toContain('content_construct_excluded');
    expect(refused).toContain('islands \\"q\\"');
    const stored = wpRaw('post', 'meta', 'get', String(pageId), '_pp_composition');
    expect(stored).toContain('<p>start</p>');
  });
});
