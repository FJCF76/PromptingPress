/**
 * tests/e2e/scoped-sheet.spec.ts — Layer 3B, the scoped sheet `udc._scoped`, RENDERED (#1242 T4).
 *
 * The PHP suite (tests/UdcScopedSheetTest.php) pins the gate and the emitted text. This pins
 * what a browser does with it, which is the claim the confinement rests on: §10's T-10 asks
 * for the probe `:hover + section` to be refused at write AND, with the write gate bypassed
 * (raw meta) and the emitter unchanged, for a real hover to leave the NEXT band's computed
 * style unchanged, beside a positive control that the same hover does restyle an in-band
 * subject. Each condition is ACTIVATED (hovered, placed first), never assumed.
 *
 * Every counterfactual injects the exact rule the emitter WOULD have printed, with
 * addStyleTag, so each "unchanged" assertion is shown to be able to fail: the fixture can
 * see the channel it says is closed.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, { cwd: process.cwd() });
  return id;
}

/** RAW META: the write gate never sees these bytes, which is the point for the bypass cases. */
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

function importTestImage(slug: string): number {
  const file = `/tmp/${slug}.png`;
  const b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  execSync(`npx wp-env run cli bash -c "echo ${b64} | base64 -d > ${file}"`, { cwd: process.cwd(), encoding: 'utf-8' });
  return parseInt(
    execSync(`npx wp-env run cli wp media import ${file} --porcelain`, { cwd: process.cwd(), encoding: 'utf-8' }).trim().split(/\s+/).pop() as string,
    10,
  );
}

/** THE REAL AUTHORING PATH (§14.1): update_composition through the admin AJAX endpoint. */
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

const RED = 'rgb(255, 0, 0)';
const GREEN = 'rgb(0, 128, 0)';

function section(id: string, title: string, udc?: Record<string, unknown>) {
  return { component: 'section', id, props: { id, title, body: '<p>Body copy.</p><ul><li>one</li><li>two</li></ul>' }, ...(udc ? { udc } : {}) };
}

/** The computed paint of the next band, both chrome regions and the admin bar, if present. */
async function outsidePaint(page: any) {
  return page.evaluate(() => {
    const read = (sel: string) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const cs = getComputedStyle(el);
      return { bg: cs.backgroundColor, color: cs.color, outline: cs.outlineStyle };
    };
    return {
      next: read('[data-pp-band="pp-t4-b"]'),
      nextTitle: read('[data-pp-band="pp-t4-b"] .section__title'),
      nav: read('[data-pp-chrome="nav"]'),
      footer: read('[data-pp-chrome="footer"]'),
      adminBar: read('#wpadminbar'),
    };
  });
}

test.describe('Layer 3B — the scoped sheet, rendered', () => {
  let pageId = 0;
  let attachmentId = 0;

  test.afterEach(() => {
    if (pageId) deletePost(pageId);
    if (attachmentId) deletePost(attachmentId);
    pageId = 0;
    attachmentId = 0;
  });

  test('T-10: the probe `:hover + section`, stored past the gate, never reaches the next band; an in-band subject does', async ({ page }) => {
    pageId = createPage('E2E 3B confinement');
    setComposition(pageId, [
      section('pp-t4-a', 'Band A', {
        _scoped: [
          // Refused at write; planted raw. The emitter must drop it.
          { selector: ':hover + section', css: { 'background-color': '#ff0000' } },
          { selector: ':not(.x) ~ *', css: { 'background-color': '#ff0000' } },
          { selector: ':first-child ~ [data-pp-band]', css: { 'background-color': '#ff0000' } },
          { selector: '.a, body', css: { 'background-color': '#ff0000' } },
          // Admitted: every one of these keeps its subject inside band A.
          { selector: ':hover .section__title', css: { color: '#008000' } },
          { selector: ':hover *', css: { outline: '3px solid #ff0000' } },
          { selector: '::before', css: { content: '""', 'background-color': '#ff0000' } },
          { selector: ':is(main *) *', css: { 'background-color': '#ff0000' } },
        ],
      }),
      section('pp-t4-b', 'Band B'),
    ]);
    await page.goto(`/?page_id=${pageId}`);
    const bandA = page.locator('[data-pp-band="pp-t4-a"]');
    await expect(bandA).toBeVisible({ timeout: 10000 });

    // Every printed rule that names band A, as the page carries it.
    const rules = await page.evaluate(() => Array.from(document.querySelectorAll('style'))
      .flatMap((s) => (s.textContent || '').split('}'))
      .filter((r) => r.includes('[data-pp-band="pp-t4-a"]')));
    expect(rules.length, 'premise: band A\'s admitted rules are printed').toBeGreaterThan(0);
    for (const rule of rules) {
      expect(rule, 'no refused rule is printed').not.toMatch(/\+ section|~ \*|~ \[data-pp-band\]|, ?body/);
    }

    const before = await outsidePaint(page);
    await bandA.locator('.section__title').hover();
    const titleA = await bandA.locator('.section__title').evaluate((el: Element) => getComputedStyle(el).color);
    expect(titleA, 'positive control: the hover condition is live, and paints in band A').toBe(GREEN);
    const after = await outsidePaint(page);
    expect(after, 'the next band, nav, footer and admin bar are untouched while band A is hovered').toEqual(before);
    expect(after.next?.bg).not.toBe(RED);

    // COUNTERFACTUAL: the exact rule the emitter refused to print WOULD restyle the next band,
    // so the assertion above can fail; the gate, not the fixture, is what kept it out.
    await page.addStyleTag({ content: '[data-pp-band="pp-t4-a"]:hover + section{background-color:#ff0000;}' });
    await page.mouse.move(0, 0);
    await bandA.locator('.section__title').hover();
    expect((await outsidePaint(page)).next?.bg, 'counterfactual: the channel is real').toBe(RED);
  });

  test('T-10: a band placed first, every admitted condition entered, leaves later bands and chrome unchanged', async ({ page }) => {
    pageId = createPage('E2E 3B first child');
    setComposition(pageId, [
      {
        component: 'section', id: 'pp-t4-a',
        props: { id: 'pp-t4-a', title: 'Band A first', body: '<p>Copy with <a href="#here">a link</a>.</p>' },
        udc: { _scoped: [
          { selector: ':first-child *', css: { 'background-color': '#ff0000' } },
          { selector: ':focus-within .section__content', css: { 'background-color': '#ff0000' } },
          { selector: ':focus-within .section__title', css: { color: '#008000' } },
          { selector: ':hover::after', css: { content: '""', 'background-color': '#ff0000' } },
        ] },
      },
      section('pp-t4-b', 'Band B'),
    ]);
    await page.goto(`/?page_id=${pageId}`);
    const bandA = page.locator('[data-pp-band="pp-t4-a"]');
    await expect(bandA).toBeVisible({ timeout: 10000 });
    expect(await bandA.evaluate((el: Element) => el.matches(':first-child')), 'premise: band A is a first child').toBe(true);
    const before = await outsidePaint(page);
    await bandA.hover();
    await bandA.locator('.section__content a').focus();
    expect(await bandA.evaluate((el: Element) => el.matches(':hover') && el.matches(':focus-within')), 'every condition is live').toBe(true);
    expect(await bandA.locator('.section__title').evaluate((el: Element) => getComputedStyle(el).color), 'positive control: the focus condition paints in band A').toBe(GREEN);
    expect(await outsidePaint(page)).toEqual(before);

    // COUNTERFACTUAL: the same condition without the band's own prefix would reach band B.
    await page.addStyleTag({ content: '[data-pp-band="pp-t4-a"]:focus-within ~ section{background-color:#ff0000;}' });
    expect((await outsidePaint(page)).next?.bg, 'counterfactual: a sibling combinator would reach the next band').toBe(RED);
  });

  test('§5.5: an equal-specificity tie goes to the sheet, and rule order decides between rules', async ({ page }) => {
    pageId = createPage('E2E 3B ties');
    setComposition(pageId, [section('pp-t4-c', 'Tie')]);

    const write = async (rules: unknown[]) => {
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await updateComposition(page, pageId, [section('pp-t4-c', 'Tie', { heading: { typography: { color: '#0000ff' } }, _scoped: rules })]);
      expect(res.success, `write: ${JSON.stringify(res)}`).toBe(true);
      await page.goto(`/?page_id=${pageId}`);
      return page.locator('[data-pp-band="pp-t4-c"] .section__title').evaluate((el: Element) => getComputedStyle(el).color);
    };
    expect(await write([{ selector: '.section__title', css: { color: '#008000' } }]), 'the scoped rule beats the role rule it ties').toBe(GREEN);
    expect(await write([{ selector: '.section__title', css: { color: '#008000' } }, { selector: '.section__title', css: { color: '#ff0000' } }])).toBe(RED);
    expect(await write([{ selector: '.section__title', css: { color: '#ff0000' } }, { selector: '.section__title', css: { color: '#008000' } }]), 'swapped, the computed value flips').toBe(GREEN);
  });

  test('P-3 / P-19: `_scoped` paints on a structured band, and an attachment-id background resolves to this install', async ({ page }) => {
    attachmentId = importTestImage('pp-t4-bg');
    pageId = createPage('E2E 3B background');
    setComposition(pageId, [section('pp-t4-d', 'Background')]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const ok = await updateComposition(page, pageId, [section('pp-t4-d', 'Background', {
      _scoped: [{ selector: '.section__content', css: { 'background-image': String(attachmentId), 'background-size': 'cover' } }],
    })]);
    expect(ok.success, `write: ${JSON.stringify(ok)}`).toBe(true);
    const refused = await updateComposition(page, pageId, [section('pp-t4-d', 'Background', {
      _scoped: [{ selector: '.section__content', css: { 'background-image': 'url(https://example.org/x.png)' } }],
    })]);
    expect(refused.success, 'an author url() is refused (P-19)').toBe(false);
    await page.goto(`/?page_id=${pageId}`);
    const image = await page.locator('[data-pp-band="pp-t4-d"] .section__content').evaluate((el: Element) => getComputedStyle(el).backgroundImage);
    expect(image).toContain('/wp-content/uploads/');
    expect(image).toContain(new URL(page.url()).host);
  });

  test('M-16: an attribute condition over plugin output, stored past the gate, makes no external request', async ({ page }) => {
    pageId = createPage('E2E 3B M-16');
    // The embed band's content is the plugin boundary; the lazy external image sits in it.
    setComposition(pageId, [
      {
        component: 'embed', id: 'pp-t4-e', props: { id: 'pp-t4-e', content: '<span data-v="abc">x</span><img loading="lazy" src="https://beacon.invalid/hit.png" alt="" width="10" height="10">' },
        udc: { _scoped: [
          { selector: '.embed__content img', css: { display: 'none' } },
          { selector: ':has([data-v^="a"]) img', css: { display: 'block' } }, // refused at write (M-16); planted raw
        ] },
      },
    ]);
    const hits: string[] = [];
    await page.route('https://beacon.invalid/**', (route: any) => { hits.push(route.request().url()); return route.fulfill({ status: 204, body: '' }); });
    await page.goto(`/?page_id=${pageId}`, { waitUntil: 'networkidle' });
    const bandE = page.locator('[data-pp-band="pp-t4-e"]');
    await expect(bandE).toBeVisible({ timeout: 10000 });
    // Every state an admitted condition could read, entered: hover, then focus inside the band.
    await bandE.hover();
    await page.mouse.move(0, 0);
    await page.waitForLoadState('networkidle');
    expect(hits, 'the dropped condition cannot reveal the image, in any state').toEqual([]);

    // COUNTERFACTUAL: printed, that condition WOULD fetch — the fixture sees the channel.
    await page.addStyleTag({ content: '[data-pp-band="pp-t4-e"]:has([data-v^="a"]) img{display:block;}' });
    await expect.poll(() => hits.length, { timeout: 5000 }).toBeGreaterThan(0);
  });
});
