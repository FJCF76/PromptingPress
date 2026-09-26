import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1028 in the real browser: `marker.color` colours a pseudo-element glyph without any
 * role addressing the glyph.
 *
 * The param sets `--pp-list-marker-color` on the holding role's own box, and the
 * stylesheet's glyph rules read `var(--pp-list-marker-color, <fallback>)`. So the proof
 * is COMPUTED pseudo-element colour, not emitted text: the separator's `::before` (start
 * packing) and `::after` (centre packing), the body list's check, the panel list's dash
 * and a grid card's check each take the authored colour, while the row's own text keeps
 * its colour. On an UNAUTHORED band both fallback chains survive: the separator follows
 * its row (`currentColor`), the markers take the accent.
 *
 * Stress states: a strip long enough to wrap at 375 (the leading separator of each line
 * stays clipped), the centred variant's trailing dot, a single card carrying its own
 * marker colour beside an unstyled one.
 */

const CWD = process.cwd();
const wp = (args: string): string =>
  execSync(`npx wp-env run cli wp ${args}`, { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string;

let pageId = 0;

async function updateComposition(page: Page, postId: number, composition: unknown[]) {
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

async function canon(page: Page, value: string): Promise<string> {
  return page.evaluate((c: string) => {
    const probe = document.createElement('span');
    probe.style.color = c;
    document.body.appendChild(probe);
    const out = getComputedStyle(probe).color;
    probe.remove();
    return out;
  }, value);
}

async function pseudoColor(page: Page, selector: string, pseudo: string): Promise<string> {
  return page.locator(selector).first().evaluate((el: Element, p: string) => getComputedStyle(el, p).color, pseudo);
}

const LONG_ITEMS = ['First-draft speed', 'Revision debt', 'Handoff clarity', 'Safer edits', 'Rollback', 'Screenshots'];

function composition(authored: boolean): unknown[] {
  const m = (color: string) => (authored ? { marker: { color } } : {});
  return [
    {
      component: 'section',
      props: { id: 'strip-start', title: 'Start strip', body_items: LONG_ITEMS },
      udc: { 'inline-items': { typography: { color: '#101828' }, ...m('#FF5C2E') } },
    },
    {
      component: 'section',
      props: { id: 'strip-center', title: 'Centre strip', body_items: LONG_ITEMS, body_items_align: 'center' },
      udc: { 'inline-items': { typography: { color: '#101828' }, ...m('#FF5C2E') } },
    },
    {
      component: 'section',
      props: {
        id: 'lists', title: 'Lists', layout: 'text-panel', body: '<ul><li>one</li><li>two</li></ul>',
        body_marker: 'check', panel_items: ['alpha', 'beta'], panel_items_marker: 'dash',
      },
      udc: { body: m('#2a9d8f'), 'panel-list': m('#e76f51') },
    },
    {
      component: 'grid',
      props: {
        id: 'cards',
        items: [
          { title: 'Own colour', text: 'x', bullets: ['a', 'b'], ...(authored ? { udc: { 'card-bullets': { marker: { color: '#6a4c93' } } } } : {}) },
          { title: 'Plain', text: 'y', bullets: ['c'] },
        ],
      },
    },
  ];
}

test.describe('#1028 marker.color colours the glyph, not the text', () => {
  test.beforeAll(() => {
    pageId = parseInt(wp('post create --post_type=page --post_status=publish --post_title="marker-1028" --porcelain'), 10);
    wp(`post meta update ${pageId} _wp_page_template composition.php`);
  });

  test.afterAll(() => {
    try {
      wp(`post delete ${pageId} --force`);
    } catch {
      /* already gone */
    }
  });

  for (const width of [375, 1280]) {
    test(`authored marker colours land on every glyph @ ${width}`, async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      const res = await updateComposition(page, pageId, composition(true));
      expect(res.success, JSON.stringify(res).slice(0, 400)).toBe(true);

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const orange = await canon(page, '#FF5C2E');
      const ink = await canon(page, '#101828');

      // Separator, start packing: a mid-line item's ::before takes the marker colour,
      // the item text keeps the row's ink.
      expect(await pseudoColor(page, '#strip-start .section__inline-item:nth-child(2)', '::before')).toBe(orange);
      expect(await page.locator('#strip-start .section__inline-item').nth(1).evaluate((el: Element) => getComputedStyle(el).color)).toBe(ink);
      // Separator, centre packing: the trailing ::after.
      expect(await pseudoColor(page, '#strip-center .section__inline-item:first-child', '::after')).toBe(orange);
      // Body list check, panel list dash, one card's check.
      expect(await pseudoColor(page, '#lists .section__content > ul > li', '::before')).toBe(await canon(page, '#2a9d8f'));
      expect(await pseudoColor(page, '#lists .section__panel-list > li', '::before')).toBe(await canon(page, '#e76f51'));
      const cards = page.locator('#cards .grid__item');
      expect(await cards.nth(0).locator('.grid__item-bullet').first().evaluate((el: Element) => getComputedStyle(el, '::before').color))
        .toBe(await canon(page, '#6a4c93'));
      // The neighbouring card, unstyled, keeps the accent.
      const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim());
      expect(await cards.nth(1).locator('.grid__item-bullet').first().evaluate((el: Element) => getComputedStyle(el, '::before').color))
        .toBe(await canon(page, accent));

      // Stressed state: at 375 the strip wraps, and the FIRST item of every line keeps its
      // leading separator clipped by the row (the #489 mechanism is unchanged): the item's
      // box starts left of the row's content edge by the pull, so its ::before lies
      // outside the row, and the row clips overflow.
      if (width === 375) {
        const geo = await page.locator('#strip-start .section__inline-items').evaluate((row: Element) => {
          const rowLeft = row.getBoundingClientRect().left + parseFloat(getComputedStyle(row).paddingLeft);
          const items = [...row.querySelectorAll('.section__inline-item')] as HTMLElement[];
          const tops = [...new Set(items.map((e) => Math.round(e.getBoundingClientRect().top)))];
          const leaders = tops.map((t) => items.find((e) => Math.round(e.getBoundingClientRect().top) === t) as HTMLElement);
          return {
            lines: tops.length,
            overflow: getComputedStyle(row).overflowX,
            leaderStartsLeftOfRow: leaders.map((e) => e.getBoundingClientRect().left < rowLeft - 1),
          };
        });
        expect(geo.lines, 'the stress fixture must actually wrap').toBeGreaterThan(1);
        expect(geo.overflow, 'the row clips what the pull pushes out').toBe('hidden');
        expect(geo.leaderStartsLeftOfRow.every(Boolean), 'every line-leading item pulls its ::before outside the row').toBe(true);
      }
      await page.screenshot({ path: `test-results/marker-1028-authored-${width}.png`, fullPage: true });
    });
  }

  test('an unauthored band keeps both fallbacks', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, pageId, composition(false));
    expect(res.success).toBe(true);
    await page.goto(`/?page_id=${pageId}`);
    const css = await page.evaluate(() => [...document.querySelectorAll('style')].map((s) => s.textContent).join(''));
    expect(css, 'nothing emits the variable when nothing authored it').not.toContain('--pp-list-marker-color:');
    // Separator follows its row (currentColor)…
    expect(await pseudoColor(page, '#strip-start .section__inline-item:nth-child(2)', '::before')).toBe(await canon(page, '#101828'));
    // …the markers take the accent.
    const accent = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim());
    expect(await pseudoColor(page, '#lists .section__content > ul > li', '::before')).toBe(await canon(page, accent));
    expect(await pseudoColor(page, '#lists .section__panel-list > li', '::before')).toBe(await canon(page, accent));
    expect(await page.locator('#cards .grid__item-bullet').first().evaluate((el: Element) => getComputedStyle(el, '::before').color))
      .toBe(await canon(page, accent));
    // The centred separator's trailing ::after follows its row too.
    expect(await pseudoColor(page, '#strip-center .section__inline-item:first-child', '::after')).toBe(await canon(page, '#101828'));
    await page.screenshot({ path: 'test-results/marker-1028-unauthored-1280.png', fullPage: true });
  });
});
