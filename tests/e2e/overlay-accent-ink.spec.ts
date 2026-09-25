import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1010 (ruling D4 = B) and #1125 (ruling D5 = B), in the real browser.
 *
 * #1010: on a band the engine marks `data-pp-band-overlay`, the accent-ink roles default to
 * `--color-accent-on-overlay` (the overlay tier of role defaults) instead of the bare
 * `--color-accent`, which measured 1.05:1 over a dark scrim. An authored ink still wins,
 * and a band without the marker keeps the bare accent.
 *
 * #1125: recolouring a role that ships its own background fill on a band whose background
 * you set is disclosed on the write as `udc_role_ink_over_own_surface`.
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

test.describe('#1010 overlay tier of accent inks / #1125 a role\'s own surface', () => {
  let pageId = 0;
  let attachmentId = 0;

  test.afterEach(() => {
    if (pageId) deletePost(pageId);
    if (attachmentId) deletePost(attachmentId);
    pageId = 0;
    attachmentId = 0;
  });

  async function heroPage(page: any, udc: Record<string, unknown>) {
    pageId = createPage('E2E 1010 Overlay Accent Ink');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec01', body: '<p>b</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      { component: 'hero', props: { id: 'pp-hero01', layout: 'centered', title: 'Build pages with clear intent', title_accent: 'clear intent' }, udc },
    ]);
    expect(res.success, `write: ${JSON.stringify(res)}`).toBe(true);
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.hero').first()).toBeVisible({ timeout: 10000 });
  }

  test('#1010 the hero title accent re-lights to the on-overlay ink over a scrim @smoke', async ({ page }) => {
    attachmentId = importTestImage('pp-1010-scrim');
    await heroPage(page, { _band: { background: { image: attachmentId, overlay: 'rgba(6,10,28,0.72)' } } });
    const hero = page.locator('.hero').first();
    expect(await hero.evaluate((el: Element) => el.hasAttribute('data-pp-band-overlay'))).toBe(true);
    const accent = await page.locator('.hero__title-accent').first().evaluate((el: Element) => getComputedStyle(el).color);
    expect(accent, 'the accent ink follows the scrim').toBe(await token(page, '--color-accent-on-overlay'));
  });

  test('#1010 an authored accent ink still wins over the overlay tier', async ({ page }) => {
    attachmentId = importTestImage('pp-1010-authored');
    await heroPage(page, {
      _band: { background: { image: attachmentId, overlay: 'rgba(6,10,28,0.72)' } },
      'title-accent': { typography: { color: '#8fd0ff' } },
    });
    const accent = await page.locator('.hero__title-accent').first().evaluate((el: Element) => getComputedStyle(el).color);
    expect(accent).toBe(await canon(page, '#8fd0ff'));
  });

  test('#1010 a band without the overlay marker keeps the bare accent', async ({ page }) => {
    await heroPage(page, { _band: { background: { fill: '#f7f8fa' } } });
    const hero = page.locator('.hero').first();
    expect(await hero.evaluate((el: Element) => el.hasAttribute('data-pp-band-overlay'))).toBe(false);
    const accent = await page.locator('.hero__title-accent').first().evaluate((el: Element) => getComputedStyle(el).color);
    expect(accent).toBe(await token(page, '--color-accent'));
  });

  test('#1010 a light scrim is disclosed and the stats number re-lights on a dark one', async ({ page }) => {
    attachmentId = importTestImage('pp-1010-stats');
    pageId = createPage('E2E 1010 Off Scrim');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec03', body: '<p>b</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const stats = (overlay: string) => [
      {
        component: 'stats',
        props: { id: 'pp-stat01', title: 'Numbers', items: [{ number: '42', label: 'Answers' }] },
        udc: { _band: { background: { image: attachmentId, overlay } } },
      },
    ];
    const light = await updateComposition(page, pageId, stats('rgba(255,255,255,0.8)'));
    expect(light.success, `write: ${JSON.stringify(light)}`).toBe(true);
    const off = ((light.data && light.data.findings) || []).filter((f: any) => f.type === 'udc_overlay_accent_off_scrim');
    expect(off.length, JSON.stringify(light.data)).toBe(1);
    expect(off[0].message).toContain('"number"');
    expect(off[0].message).toContain('the scrim you set is light');

    const dark = await updateComposition(page, pageId, stats('rgba(6,10,28,0.72)'));
    expect(dark.success).toBe(true);
    expect(((dark.data && dark.data.findings) || []).filter((f: any) => f.type === 'udc_overlay_accent_off_scrim')).toEqual([]);
    await page.goto(`/?page_id=${pageId}`);
    const number = page.locator('.stats__number').first();
    await expect(number).toBeVisible({ timeout: 10000 });
    expect(await number.evaluate((el: Element) => getComputedStyle(el).color)).toBe(await token(page, '--color-accent-on-overlay'));
  });

  test('#1125 recolouring the eyebrow on a darkened band is disclosed on the write', async ({ page }) => {
    pageId = createPage('E2E 1125 Own Surface');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec02', body: '<p>b</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec02', eyebrow: 'SECTION', title: 'Dark band', body: '<p>Body</p>' },
        udc: {
          _band: { background: { fill: '@color-bg-inverted' } },
          eyebrow: { typography: { color: '@color-accent-on-inverted' } },
        },
      },
    ]);
    expect(res.success, `write: ${JSON.stringify(res)}`).toBe(true);
    const findings = (res.data && res.data.findings) || [];
    const own = findings.filter((f: any) => f.type === 'udc_role_ink_over_own_surface');
    expect(own.length, JSON.stringify(findings)).toBe(1);
    expect(own[0].message).toContain('role "eyebrow"');
  });
});
