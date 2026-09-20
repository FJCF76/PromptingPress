/**
 * tests/e2e/raw-css.spec.ts — Layer 2 (`_css`) as RENDERED, not as emitted text (#1079).
 *
 * The PHP suite pins what the engine writes. This pins what a browser does with it, which
 * is a different claim: a declaration can be emitted perfectly and still paint nothing (the
 * clipped-focus-ring lesson at #1046, where a computed `outline: solid 2px` read green over
 * zero painted pixels). Layer 2 makes that gap wider than usual, because an unknown
 * property is emitted VERBATIM by design — so "the browser accepted it" is exactly the
 * thing no unit test can assert.
 *
 * Three claims, each of which a unit test cannot make:
 *   1. a raw declaration actually computes on the element the role selects;
 *   2. a raw declaration OUTRANKS the group parameter for the same property, in the
 *      browser's own cascade rather than in our resolution table;
 *   3. the breakpoint keying really is keyed to the viewport.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function createPage(title: string): number {
  const cmd = `npx wp-env run cli wp post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`;
  const id = parseInt(execSync(cmd, { cwd: process.cwd(), encoding: 'utf-8' }).trim(), 10);
  execSync(`npx wp-env run cli wp post meta update ${id} _wp_page_template composition.php`, {
    cwd: process.cwd(),
  });
  return id;
}

function setComposition(postId: number, composition: unknown[]): void {
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${postId} _pp_composition '${json}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function deletePage(id: number): void {
  execSync(`npx wp-env run cli wp post delete ${id} --force`, { cwd: process.cwd() });
}

test.describe('Layer 2 — raw `_css` declarations, rendered', () => {
  let pageId = 0;

  test.afterEach(() => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  test('a raw declaration paints, outranks its group twin, and keys to the viewport', async ({
    page,
  }) => {
    pageId = createPage('E2E Layer 2 raw css');
    setComposition(pageId, [
      {
        component: 'section',
        id: 'pp-rawcss',
        props: {
          id: 'pp-rawcss',
          title: 'Raw declarations',
          body: '<p>Prose under a raw declaration.</p>',
        },
        udc: {
          // The group parameter and the raw declaration name the SAME property. §2′.3
          // rules that the raw one wins; this asserts the browser agrees, which is the
          // half our resolution table cannot prove about itself.
          body: { typography: { color: '#111111' } },
          // `_css` sits INSIDE a role, beside that role's groups — `_band` is the role
          // for the band element itself. A top-level `_css` is refused at write, and the
          // refusal says exactly this, because it is the mistake the shape invites.
          _band: { _css: { opacity: { d: '0.5', p: '1' } } },
        },
      },
      {
        component: 'section',
        id: 'pp-rawcss-dark',
        props: {
          id: 'pp-rawcss-dark',
          title: 'On a dark band',
          body: '<p>Light prose on a dark ground.</p>',
        },
        udc: {
          _band: { background: { fill: '#0f172a' }, typography: { color: '#fcfdff' } },
          heading: { typography: { color: '#ffffff' } },
          // An UNKNOWN property — the vocabulary types none of these — carrying a
          // compound value, which is the shape `max_values: 1` exists to keep intact.
          body: {
            typography: { color: '#e2e8f0' },
            _css: { 'letter-spacing': '0.02em', 'text-shadow': '0 1px 2px rgba(0,0,0,0.6)' },
          },
        },
      },
    ]);

    // ── 1 + 2: it paints, and the raw value wins ──────────────────────────
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-rawcss .section__content')).toHaveCount(1, { timeout: 10000 });

    const band = page.locator('#pp-rawcss');
    expect(
      await band.evaluate((el) => getComputedStyle(el as HTMLElement).opacity),
      'the raw `opacity` must compute on the band the role selects',
    ).toBe('0.5');

    const bodyColour = await page
      .locator('#pp-rawcss .section__content')
      .evaluate((el) => getComputedStyle(el as HTMLElement).color);
    expect(bodyColour, 'the group value still paints where no raw twin contests it').toBe(
      'rgb(17, 17, 17)',
    );

    // ── the dark band's unknown properties ────────────────────────────────
    const darkBody = await page
      .locator('#pp-rawcss-dark .section__content')
      .evaluate((el) => {
        const cs = getComputedStyle(el as HTMLElement);
        return { letterSpacing: cs.letterSpacing, textShadow: cs.textShadow, color: cs.color };
      });
    expect(
      darkBody.letterSpacing,
      'an unknown property the browser understands must actually compute — emitted verbatim is '
        + 'not the same as accepted, and only a browser can tell us which happened',
    ).not.toBe('normal');
    expect(
      darkBody.textShadow,
      'a compound value must survive as ONE value rather than being split word by word',
    ).toContain('rgba(0, 0, 0, 0.6)');
    expect(darkBody.color, 'the dark band keeps its authored ink').toBe('rgb(226, 232, 240)');

    // ── 3: the breakpoint keying is real ──────────────────────────────────
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-rawcss .section__content')).toHaveCount(1, { timeout: 10000 });
    expect(
      await page.locator('#pp-rawcss').evaluate((el) => getComputedStyle(el as HTMLElement).opacity),
      'the phone tier of a raw declaration must win at phone width — the engine writes the '
        + 'media query, so this is the proof it wrote the right one',
    ).toBe('1');

    await page.setViewportSize({ width: 768, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-rawcss .section__content')).toHaveCount(1, { timeout: 10000 });
    expect(
      await page.locator('#pp-rawcss').evaluate((el) => getComputedStyle(el as HTMLElement).opacity),
      'and the desktop base still governs the tablet tier, which declares nothing',
    ).toBe('0.5');
  });
});
