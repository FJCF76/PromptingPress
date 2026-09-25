import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1125 in the real browser: the role-paint accessor's cascade model, checked against Chromium.
 *
 * pp_udc_role_paint() decides which ink and which surface paint on a role from the tiers the
 * renderer emits. The PHP suite pins its ATTRIBUTION (which tier won) against the emitted CSS;
 * this spec pins the FINAL VALUES the browser computes for the same cases, so the model cannot be
 * right about the CSS and wrong about the page. Each case also reads the write envelope: the
 * finding fires exactly where the browser shows the author's ink on the role's default surface.
 */

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, { cwd: process.cwd() });
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`, { cwd: process.cwd(), encoding: 'utf-8' });
}

function deletePost(id: number): void {
  try {
    execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
  } catch {
    /* already gone */
  }
}

async function execute(page: any, name: string, params: Record<string, string>) {
  return page.evaluate(
    async (args: { name: string; params: Record<string, string> }) => {
      const config = (window as any).ppAiChat;
      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', args.name);
      for (const [k, v] of Object.entries(args.params)) data.append(`params[${k}]`, v);
      return (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })).json();
    },
    { name, params },
  );
}

async function updateComposition(page: any, postId: number, composition: unknown[]) {
  const baseline = await page.evaluate(async (pid: number) => {
    const config = (window as any).ppAiChat;
    const data = new FormData();
    data.append('action', 'pp_ai_page_baseline');
    data.append('nonce', config.executeNonce);
    data.append('post_id', String(pid));
    return (await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })).json();
  }, postId);
  const params: Record<string, string> = { post_id: String(postId), composition: JSON.stringify(composition) };
  if (baseline && baseline.success && baseline.data) params.expected_version = String(baseline.data.version);
  return execute(page, 'update_composition', params);
}

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

const ownSurface = (res: any) => ((res.data && res.data.findings) || []).filter((f: any) => f.type === 'udc_role_ink_over_own_surface');

async function paint(page: any, selector: string) {
  return page.locator(selector).first().evaluate((el: Element) => {
    const cs = getComputedStyle(el);
    return { color: cs.color, background: cs.backgroundColor };
  });
}

test.describe('#1125 a role\'s own surface under a new ink', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) deletePost(pageId);
    pageId = 0;
  });

  async function band(page: any, component: string, props: Record<string, unknown>, udc: Record<string, unknown>) {
    pageId = createPage('E2E 1125 Own Surface');
    setComposition(pageId, [{ component: 'section', props: { id: 'pp-sec11', body: '<p>b</p>' } }]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await updateComposition(page, pageId, [{ component, props, udc }]);
    expect(res.success, `write: ${JSON.stringify(res)}`).toBe(true);
    await page.goto(`/?page_id=${pageId}`);
    return res;
  }

  test('#1125 the author ink paints on the default pill, and the write names it @smoke', async ({ page }) => {
    const res = await band(page, 'section', { id: 'pp-sec12', eyebrow: 'SECTION', title: 'Dark', body: '<p>b</p>' }, {
      _band: { background: { fill: '#101828' } },
      eyebrow: { typography: { color: '#ffffff' } },
    });
    expect(ownSurface(res).length, JSON.stringify(res.data)).toBe(1);
    const eyebrow = await paint(page, '.section__eyebrow');
    expect(eyebrow.color, 'the author ink paints').toBe(await canon(page, '#ffffff'));
    expect(eyebrow.background, 'the DEFAULT pill paints under it').toBe(await token(page, '--color-surface-accent'));
  });

  test('#1125 an authored fill paints under the ink, and the write is silent', async ({ page }) => {
    const res = await band(page, 'section', { id: 'pp-sec13', eyebrow: 'SECTION', title: 'Dark', body: '<p>b</p>' }, {
      _band: { background: { fill: '#101828' } },
      eyebrow: { typography: { color: '#ffffff' }, background: { fill: '#1d2939' } },
    });
    expect(ownSurface(res).length).toBe(0);
    const eyebrow = await paint(page, '.section__eyebrow');
    expect(eyebrow.background).toBe(await canon(page, '#1d2939'));
  });

  test('#1125 a preset fill the default shadows does not paint: the pill stays and the write names it', async ({ page }) => {
    pageId = createPage('E2E 1125 Preset');
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const saved = await execute(page, 'save_preset', { name: 'e2e-1125-pill', grain: 'role', udc: JSON.stringify({ background: { fill: '#222222' } }) });
    expect(saved.success, JSON.stringify(saved)).toBe(true);
    try {
      deletePost(pageId);
      pageId = 0;
      const res = await band(page, 'section', { id: 'pp-sec14', eyebrow: 'SECTION', title: 'Dark', body: '<p>b</p>' }, {
        _band: { background: { fill: '#101828' } },
        eyebrow: { _preset: 'e2e-1125-pill', typography: { color: '#ffffff' } },
      });
      expect(ownSurface(res).length, JSON.stringify(res.data)).toBe(1);
      const eyebrow = await paint(page, '.section__eyebrow');
      expect(eyebrow.background, 'the preset fill is shadowed by the default: the pill paints').toBe(await token(page, '--color-surface-accent'));
    } finally {
      deletePost(pageId);
      pageId = 0;
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      await execute(page, 'delete_preset', { name: 'e2e-1125-pill' });
    }
  });

  test('#1125 in :hover the author hover ink lands on the default hover fill, named with its state', async ({ page }) => {
    const res = await band(page, 'cta', { id: 'pp-cta11', title: 'C', button_text: 'Go', button_url: '/x', button2_text: 'More', button2_url: '/y' }, {
      _band: { background: { fill: '#101828' } },
      'button-secondary': { typography: { color: '#ffffff', ':hover': { color: '#fff5a0' } } },
    });
    expect(ownSurface(res).length, JSON.stringify(res.data)).toBe(1);
    expect(ownSurface(res)[0].message).toContain('in the :hover state');
    await page.emulateMedia({ reducedMotion: 'reduce' }); // base.css collapses transitions: no mid-transition read
    const button = page.locator('.cta__button--secondary').first();
    const rest = await paint(page, '.cta__button--secondary');
    expect(rest.background, 'transparent at rest: no own surface').toBe('rgba(0, 0, 0, 0)');
    const hoverInk = await canon(page, '#fff5a0');
    const hoverFill = await token(page, '--color-accent');
    await button.hover();
    // Polled, not read once: a late layout shift can move the element out from under the pointer.
    await expect.poll(async () => (await paint(page, '.cta__button--secondary')).color, { message: 'the author hover ink paints' }).toBe(hoverInk);
    expect((await paint(page, '.cta__button--secondary')).background, 'the default hover fill paints under it').toBe(hoverFill);
  });

  test('#1125 a press is :active AND :hover: the author :active ink lands on the default :hover fill', async ({ page }) => {
    const res = await band(page, 'cta', { id: 'pp-cta12', title: 'C', button_text: 'Go', button_url: '/x', button2_text: 'More', button2_url: '/y' }, {
      _band: { background: { fill: '#101828' } },
      'button-secondary': { typography: { color: '#ffffff', ':active': { color: '#fff5a0' } } },
    });
    expect(ownSurface(res).length, JSON.stringify(res.data)).toBe(1);
    expect(ownSurface(res)[0].message).toContain('in the :hover and :active states together');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    // Expected values FIRST: releasing the press completes a click on the link, which navigates.
    const ink = await canon(page, '#fff5a0');
    const fill = await token(page, '--color-accent');
    const button = page.locator('.cta__button--secondary').first();
    await button.hover();
    // The pointer must be ON the button before the press (CI read the resting ink once: a late layout
    // shift had moved the element out from under the pointer, as in the hover test above). The default
    // :hover fill painting proves it; then press, and poll the pressed ink.
    await expect.poll(async () => (await paint(page, '.cta__button--secondary')).background, { message: 'hovered before the press' }).toBe(fill);
    await page.mouse.down();
    await expect.poll(async () => (await paint(page, '.cta__button--secondary')).color, { message: 'the author :active ink paints while pressed' }).toBe(ink);
    expect((await paint(page, '.cta__button--secondary')).background, 'on the default :hover fill').toBe(fill);
  });
});
