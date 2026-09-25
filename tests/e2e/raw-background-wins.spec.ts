import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1141 (ruling D1 = A), in the real browser: a raw `_css` background on `_band` outranks the group's image
 * and scrim at the same coordinate (contract §2'.3), so the page paints the raw value, the scrim is gone,
 * and the overlay marker (which re-lights accent inks and the focus ring) is not set. Before the fix the
 * shorthand printed first and the image and scrim painted over it.
 */

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, { cwd: process.cwd() });
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function deletePost(id: number): void {
  try {
    execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
  } catch {
    /* already gone */
  }
}

function importTestImage(slug: string): number {
  const file = `/tmp/${slug}.png`;
  const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  execSync(`npx wp-env run cli bash -c "echo ${b64} | base64 -d > ${file}"`, { cwd: process.cwd(), encoding: 'utf-8' });
  return parseInt(
    execSync(`npx wp-env run cli wp media import ${file} --porcelain`, { cwd: process.cwd(), encoding: 'utf-8' })
      .trim()
      .split(/\s+/)
      .pop() as string,
    10,
  );
}

async function updateComposition(page: any, postId: number, composition: unknown[]) {
  return page.evaluate(
    async (args: { pid: number; composition: unknown[] }) => {
      const config = (window as any).ppAiChat;
      const baselineData = new FormData();
      baselineData.append('action', 'pp_ai_page_baseline');
      baselineData.append('nonce', config.executeNonce);
      baselineData.append('post_id', String(args.pid));
      const baseline = await (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: baselineData })).json();
      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'update_composition');
      data.append('params[post_id]', String(args.pid));
      data.append('params[composition]', JSON.stringify(args.composition));
      if (baseline && baseline.success && baseline.data) {
        data.append('params[expected_version]', String(baseline.data.version));
      }
      return (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })).json();
    },
    { pid: postId, composition },
  );
}

/** Canonicalise a CSS colour value through the browser. */
async function canon(page: any, value: string): Promise<string> {
  return page.evaluate((c: string) => {
    const probe = document.createElement('span');
    probe.style.color = c;
    document.body.appendChild(probe);
    const out = getComputedStyle(probe).color;
    probe.remove();
    return out;
  }, value);
}

async function token(page: any, name: string): Promise<string> {
  const raw = await page.evaluate((n: string) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
  expect(raw, `${name} must be defined`).not.toBe('');
  return canon(page, raw);
}

test.describe('#1141 a raw background wins over the band image', () => {
  let pageId = 0;
  let attachmentId = 0;

  test.afterEach(() => {
    if (pageId) deletePost(pageId);
    if (attachmentId) deletePost(attachmentId);
    pageId = 0;
    attachmentId = 0;
  });

  async function heroPage(page: any, udc: Record<string, unknown>) {
    pageId = createPage('E2E 1141 Raw Background');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec41', body: '<p>b</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [{ component: 'hero', props: { id: 'pp-hero41', layout: 'centered', title: 'Raw wins' }, udc }]);
    expect(res.success, `write: ${JSON.stringify(res)}`).toBe(true);
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.hero').first()).toBeVisible({ timeout: 10000 });
    return res;
  }

  test('#1141 the raw shorthand paints; the image and scrim do not; no overlay marker', async ({ page }) => {
    attachmentId = importTestImage('pp-1141-raw');
    const res = await heroPage(page, { _band: { background: { image: attachmentId, overlay: 'rgba(6,10,28,0.72)' }, _css: { background: '#fdf6e3' } } });
    const collision = ((res.data && res.data.findings) || []).filter((f: any) => f.type === 'udc_css_overrides_group_value');
    expect(collision.length, JSON.stringify(res.data)).toBeGreaterThan(0);
    const hero = page.locator('.hero').first();
    const paint = await hero.evaluate((el: Element) => ({ image: getComputedStyle(el).backgroundImage, colour: getComputedStyle(el).backgroundColor, marked: el.hasAttribute('data-pp-band-overlay') }));
    expect(paint.image, 'no image or scrim layer paints').toBe('none');
    expect(paint.colour, 'the raw value paints').toBe(await canon(page, '#fdf6e3'));
    expect(paint.marked, 'no scrim, no marker').toBe(false);
  });

  test('#1141 control: without the raw background the image and scrim paint and the band is marked', async ({ page }) => {
    attachmentId = importTestImage('pp-1141-ctl');
    await heroPage(page, { _band: { background: { image: attachmentId, overlay: 'rgba(6,10,28,0.72)' } } });
    const hero = page.locator('.hero').first();
    const paint = await hero.evaluate((el: Element) => ({ image: getComputedStyle(el).backgroundImage, marked: el.hasAttribute('data-pp-band-overlay') }));
    expect(paint.image).toContain('url(');
    expect(paint.image).toContain('linear-gradient');
    expect(paint.marked).toBe(true);
  });
});
