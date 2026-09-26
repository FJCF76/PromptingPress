import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1171 in the real browser: a page a TEMPLATE renders paints the same role defaults
 * a composed band gets.
 *
 * Before the fix the defaults tier was built from the page composition alone, so the
 * posts page, a single post, archives, search results, the 404 and a page on the
 * default template printed no defaults CSS and rendered their v2 components as bare
 * markup (hero padding 0, no card fill or border). Each assertion here compares a
 * template-rendered component against the SAME component rendered in a composed
 * reference page, property by property, at 375 and 1280, rather than against
 * hard-coded numbers: the contract is "what a composed band gets", not a value.
 *
 * Stress states covered: a post title long enough to wrap, a post with no thumbnail,
 * an EMPTY search (the template's section branch), the 404, and the posts page.
 * No band id is invented for a template band, which the last assertion pins.
 */

const CWD = process.cwd();
const wp = (args: string): string =>
  execSync(`npx wp-env run cli wp ${args}`, { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string;

let refPage = 0;
let postId = 0;
let plainPage = 0;
let postsPage = 0;
let emptyCat = 0;
let savedPostsPage = '0';
let savedShowOnFront = 'page';
let snapshotTaken = false;

const LONG_TITLE =
  'A deliberately long post title that has to wrap across several lines on a narrow phone screen to stress the hero';

test.beforeAll(() => {
  // Snapshot the shared site settings FIRST, before anything can throw, and restore
  // only what was actually read.
  savedPostsPage = wp('option get page_for_posts');
  savedShowOnFront = wp('option get show_on_front');
  snapshotTaken = true;
  refPage = parseInt(wp('post create --post_type=page --post_status=publish --post_title="tpl-ref" --porcelain'), 10);
  wp(`post meta update ${refPage} _wp_page_template composition.php`);
  const composition = [
    { component: 'hero', props: { title: 'Reference hero', layout: 'left' } },
    {
      component: 'grid',
      props: { items: [{ title: 'Card', text: 'Card text', link_url: '/', link_text: 'Read post' }] },
    },
    { component: 'section', props: { body: '<p>Reference body</p>', layout: 'text-only' } },
    {
      component: 'cta',
      props: { title: 'Reference cta', button_text: 'Go', button_url: '/', layout: 'inline' },
    },
  ];
  const json = JSON.stringify(composition).replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp post meta update ${refPage} _pp_composition '${json}'`, { cwd: CWD });

  postId = parseInt(
    wp(`post create --post_type=post --post_status=publish --post_title="${LONG_TITLE}" --post_content="<p>Body of the post.</p>" --porcelain`),
    10,
  );
  plainPage = parseInt(
    wp('post create --post_type=page --post_status=publish --post_title="tpl-plain" --post_content="<p>Plain page body.</p>" --porcelain'),
    10,
  );
  // New pages are given the Composition template by the theme; page.php is the
  // `default` template, so the fixture selects it explicitly.
  wp(`post meta update ${plainPage} _wp_page_template default`);
  postsPage = parseInt(wp('post create --post_type=page --post_status=publish --post_title="tpl-posts" --porcelain'), 10);
  emptyCat = parseInt(wp('term create category "tpl-empty-1171" --porcelain'), 10);
  // A posts page is only the posts index (home.php) when the front page is a static
  // page; pinned here rather than assumed from the environment.
  wp('option update show_on_front page');
  wp(`option update page_for_posts ${postsPage}`);
});

test.afterAll(() => {
  if (snapshotTaken) {
    wp(`option update page_for_posts ${savedPostsPage}`);
    wp(`option update show_on_front ${savedShowOnFront}`);
  }
  try {
    wp(`term delete category ${emptyCat}`);
  } catch {
    /* already gone */
  }
  for (const id of [refPage, postId, plainPage, postsPage]) {
    try {
      wp(`post delete ${id} --force`);
    } catch {
      /* already gone */
    }
  }
});

type Probe = { sel: string; props: string[] };

const HERO: Probe[] = [
  { sel: '[data-pp-component="hero"]', props: ['padding-top', 'padding-bottom', 'background-color'] },
  { sel: '[data-pp-component="hero"] .hero__title', props: ['font-size', 'line-height'] },
  { sel: '[data-pp-component="hero"] .hero__content', props: ['max-width', 'row-gap'] },
];
const GRID: Probe[] = [
  {
    sel: '[data-pp-component="grid"] .grid__item',
    props: ['background-color', 'border-top-width', 'border-top-color', 'border-top-left-radius', 'box-shadow'],
  },
  { sel: '[data-pp-component="grid"] .grid__item-bar', props: ['height', 'background-color'] },
  { sel: '[data-pp-component="grid"] .grid__item-title', props: ['font-size', 'font-weight'] },
];
const SECTION: Probe[] = [
  { sel: '[data-pp-component="section"] .section__content', props: ['font-size', 'line-height', 'color', 'max-width'] },
];
const CTA: Probe[] = [
  { sel: '[data-pp-component="cta"]', props: ['background-color', 'border-top-width', 'border-top-color'] },
  { sel: '[data-pp-component="cta"] .cta__button', props: ['background-color', 'border-top-color', 'color'] },
];

async function read(page: Page, probes: Probe[]): Promise<Record<string, string>> {
  return page.evaluate((ps: Probe[]) => {
    const out: Record<string, string> = {};
    for (const p of ps) {
      const el = document.querySelector(p.sel);
      if (!el) {
        out[p.sel] = 'MISSING';
        continue;
      }
      const cs = getComputedStyle(el);
      for (const prop of p.props) out[`${p.sel} :: ${prop}`] = cs.getPropertyValue(prop);
    }
    return out;
  }, probes);
}

const ROUTES: { name: string; url: () => string; probes: Probe[] }[] = [
  { name: 'posts page (home.php)', url: () => `/?page_id=${postsPage}`, probes: [...HERO, ...GRID] },
  { name: 'category archive (archive.php)', url: () => '/?cat=1', probes: [...HERO, ...GRID] },
  { name: 'single post, long title, no thumbnail (single.php)', url: () => `/?p=${postId}`, probes: [...HERO, ...SECTION, ...CTA] },
  { name: 'search with results (search.php, grid branch)', url: () => '/?s=deliberately', probes: [...HERO, ...GRID] },
  { name: 'empty category archive (archive.php, section branch)', url: () => `/?cat=${emptyCat}`, probes: [...HERO, ...SECTION] },
  { name: 'empty search (search.php, section branch)', url: () => '/?s=zzqq-no-such-term-1171', probes: [...HERO, ...SECTION] },
  { name: '404 (404.php)', url: () => '/?p=987654321', probes: [...HERO, ...CTA] },
  { name: 'page on the default template (page.php)', url: () => `/?page_id=${plainPage}`, probes: [...HERO, ...SECTION] },
];

for (const width of [375, 1280]) {
  test.describe(`#1171 template-rendered bands paint the composed defaults @ ${width}`, () => {
    for (const route of ROUTES) {
      test(`${route.name}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${refPage}`);
        const expected = await read(page, route.probes);
        for (const [k, v] of Object.entries(expected)) {
          expect(v, `reference is missing ${k}`).not.toBe('MISSING');
        }

        await page.goto(route.url());
        const actual = await read(page, route.probes);
        expect(actual).toEqual(expected);

        // The contract is the SHIPPED look, not merely "some CSS": a hero with no
        // defaults measured 0px padding, so a non-zero equal value is the fix.
        expect(parseFloat(actual['[data-pp-component="hero"] :: padding-top'])).toBeGreaterThan(0);

        // No band id is invented for a template band (the authored tier cannot
        // reach it; that surface is a separate, unbuilt follow-up).
        expect(await page.locator('main [data-pp-band]').count()).toBe(0);
      });
    }
  });
}

test('#1171 the defaults tier is printed for the template components and only once each', async ({ page }) => {
  await page.goto(`/?page_id=${postsPage}`);
  const css = await page.evaluate(() => document.getElementById('pp-base-inline-css')?.textContent || '');
  for (const name of ['hero', 'grid']) {
    expect(css, `${name} defaults`).toContain(`[data-pp-component="${name}"]`);
  }
  // Each component's block opens its `pp-zero` root tier exactly once (the root rule
  // itself repeats inside the per-breakpoint media queries, so count the opener): a
  // second opener would mean the component printed twice.
  for (const name of ['hero', 'grid']) {
    expect(css.split(`@layer pp-zero{:where([data-pp-component="${name}"])`).length - 1, name).toBe(1);
  }
});
