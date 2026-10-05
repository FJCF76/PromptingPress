import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Layer 3A's RENDER side on live core, read from the browser-parsed page (#1242 T3b;
 * docs/v2/LAYER-3-CONTRACT.md §2.3, test row T-4; §10 "rendered truth, not re-derivation").
 *
 * The stored composition is planted as RAW META (the only way bytes the write gate refuses
 * can exist: a restore, a raw write, a site upgraded across the contract), then the page is
 * opened in Chromium:
 *   - a refused construct is absent from the DOM, and the rest of its prop renders;
 *   - a refused <script>'s body leaves no byte in the band's text (E4);
 *   - a prop the parser cannot verify (P-16) renders EMPTY, its band renders, and the NEXT
 *     band's sentinel is in its own band (a stray closer is never a DOM node);
 *   - a stored title that fails the predicate renders as text equal to the stored text, with
 *     no element created, and is never empty;
 *   - what the gate admits paints (an inline `sub`);
 * and the read-only census and `check page` name each prop with its clause.
 */

const CWD = process.cwd();
const wp = (args: string): string =>
  (execSync(`npx wp-env run cli wp ${args}`, { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string);

function plant(postId: number, composition: unknown): void {
  const b64 = Buffer.from(JSON.stringify(composition), 'utf-8').toString('base64');
  execSync(`npx wp-env run cli wp eval 'update_post_meta(${postId}, "_pp_composition", wp_slash(base64_decode("${b64}")));'`, { cwd: CWD });
}

const STORED_TITLE = 'The <code> element';

let pageId = 0;

test.describe('Layer 3A render side (T-4, live)', () => {
  test.beforeAll(() => {
    pageId = parseInt(wp('post create --post_type=page --post_status=publish --post_title="l3-render" --porcelain'), 10);
    wp(`post meta update ${pageId} _wp_page_template composition.php`);
    plant(pageId, [
      { component: 'section', props: { title: STORED_TITLE, body: '<p onclick="window.__pwned=1">kept text</p><script>window.__leak="secret-token"</script><p>after the script</p>' } },
      { component: 'section', props: { title: 'Blank band', body: '<p><b>Note</p><p>lost paragraph</p>' } },
      { component: 'section', props: { title: 'Next band', body: '<p id="next-band-sentinel">next band body</p>' } },
      { component: 'cta', props: { title: 'Water', body: 'H<sub>2</sub>O', button_text: 'Go', button_url: '/go' } },
    ]);
  });

  test.afterAll(() => {
    if (pageId > 0) wp(`post delete ${pageId} --force`);
  });

  test('the browser-parsed page shows each stored prop as the predicate renders it', async ({ page }) => {
    await page.goto(`/?page_id=${pageId}`);
    const bands = page.locator('main section[data-pp-component]');
    await expect(bands).toHaveCount(4);

    // A refused construct is absent; the rest of its prop renders.
    const first = bands.nth(0);
    await expect(first.locator('.section__content')).toContainText('kept text');
    await expect(first.locator('.section__content')).toContainText('after the script');
    expect(await page.locator('main [onclick]').count()).toBe(0);
    expect(await page.locator('main script').count()).toBe(0);
    expect(await first.innerText()).not.toContain('secret-token');
    expect(await page.evaluate(() => (window as any).__leak)).toBeUndefined();

    // The stored title that fails the predicate: text, character for character, no element.
    const title = first.locator('h2.section__title');
    expect(await title.textContent()).toBe(STORED_TITLE);
    expect(await title.locator('*').count()).toBe(0);

    // P-16: the prop renders EMPTY, its band renders, the next band is its own band.
    const blank = bands.nth(1);
    await expect(blank.locator('h2.section__title')).toHaveText('Blank band');
    expect((await blank.locator('.section__content').innerText()).trim()).toBe('');
    expect(await page.getByText('lost paragraph').count()).toBe(0);
    const sentinel = page.locator('#next-band-sentinel');
    await expect(sentinel).toHaveText('next band body');
    expect(await sentinel.evaluate((el) => el.closest('section[data-pp-component]') === document.querySelectorAll('main section[data-pp-component]')[2])).toBe(true);

    // What the gate admits paints.
    await expect(bands.nth(3).locator('.cta__body sub')).toHaveText('2');
  });

  test('the census and check page name each prop with its clause, and the census writes nothing', () => {
    const before = wp(`post meta list ${pageId} --format=json`);
    const out = execSync(`npx wp-env run cli wp pp content census --post_id=${pageId} --format=json`, { cwd: CWD, encoding: 'utf-8' });
    const rows = JSON.parse(out.slice(out.indexOf('['), out.lastIndexOf(']') + 1));
    const got = rows.map((r: any) => `${r.band} ${r.prop} ${r.outcome} ${r.clause}`).sort();
    expect(got).toEqual(['0 "body" stripped E1', '0 "title" text P-16', '1 "body" empty P-16'].sort());
    expect(wp(`post meta list ${pageId} --format=json`)).toBe(before);

    const report = execSync(`npx wp-env run cli wp pp check page --post_id=${pageId}`, { cwd: CWD, encoding: 'utf-8' });
    expect(report).toContain('content_stripped_at_render');
    expect(report).toContain('renders EMPTY');
  });

  test('content SVG: an icon inside a line of text flows inline; one standing alone stays a block; chrome icons keep the reset', async ({ page }) => {
    const icon = '<svg viewBox="0 0 10 10" width="16" height="16"><circle cx="5" cy="5" r="4"/></svg>';
    const big = '<svg viewBox="0 0 400 120" width="400" height="120"><rect width="400" height="120"/></svg>';
    const comp = [{ component: 'section', props: { title: 'Icons', body: `<p>Call us ${icon} today</p>${big}<p>after</p>` } }];
    const b64 = Buffer.from(JSON.stringify(comp), 'utf-8').toString('base64');
    // A trusted, checked write (the widened set renders only for content a full-tier gated write admitted).
    const id = parseInt(wp('post create --post_type=page --post_status=publish --post_title="l3-svg" --porcelain'), 10);
    wp(`post meta update ${id} _wp_page_template composition.php`);
    execSync(`npx wp-env run cli wp eval 'wp_set_current_user(1); $r = pp_execute_action("update_composition", ["post_id" => ${id}, "composition" => json_decode(base64_decode("${b64}"), true)]); echo $r["ok"] ? "ok" : $r["error"];'`, { cwd: CWD });
    try {
      await page.goto(`/?page_id=${id}`);
      const display = await page.evaluate(() => ({
        inline: getComputedStyle(document.querySelector('.section__content p svg') as Element).display,
        block: getComputedStyle(document.querySelector('.section__content > svg') as Element).display,
        chrome: [...document.querySelectorAll('header svg')].map((s) => getComputedStyle(s).display),
      }));
      expect(display.inline).toBe('inline');
      expect(display.block).toBe('block');
      for (const d of display.chrome) expect(d).toBe('block');
    } finally {
      wp(`post delete ${id} --force`);
    }
  });
});
