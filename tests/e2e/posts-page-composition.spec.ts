import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * #1181 in the real browser: the posts page renders its stored composition, and its
 * listing band's cards are the posts index's main query.
 *
 * Authored through the REAL write path (the admin AJAX `update_composition`, the one the
 * in-admin assistant and the editor use), then read as a visitor at 375 / 768 / 1280:
 *   - the composed bands render (with their band ids) instead of the hard-coded ones;
 *   - the listing shows this page of posts (posts_per_page of them), and page 2 the rest;
 *   - the page links render inside the listing band;
 *   - a post title shaped like markup renders as TEXT (the card sinks escape it) and runs
 *     nothing;
 *   - the band's authored `udc` reaches EVERY query-bound card (the head's emitter read
 *     the same composition the body rendered);
 *   - the same band written to an ordinary page is refused, naming the posts page.
 */

const CWD = process.cwd();
const wp = (args: string): string =>
  execSync(`npx wp-env run cli wp ${args}`, { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string;

let postsPage = 0;
let otherPage = 0;
const posts: number[] = [];
let savedPostsPage = '0';
let savedShowOnFront = 'page';
let snapshotTaken = false;
// Derived from the site after the fixture posts exist, so leftover posts from another spec
// (or a missing default post) cannot make the page counts wrong.
let perPage = 10;
let totalPosts = 0;

const MARKUP_TITLE = '<img src=x onerror="window.__ppXss=1">Escaped title';
const INK = '#6a4c93';

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

const COMPOSITION = [
  { component: 'hero', props: { id: 'blog-hero', title: 'The journal', layout: 'left' } },
  {
    component: 'grid',
    props: { id: 'blog-listing', title: 'Latest', items_source: 'posts', items: [] },
    udc: { 'card-title': { typography: { color: INK } } },
  },
];

test.describe('#1181 the posts page renders its stored composition', () => {
  test.beforeAll(() => {
    savedPostsPage = wp('option get page_for_posts');
    savedShowOnFront = wp('option get show_on_front');
    snapshotTaken = true;
    postsPage = parseInt(wp('post create --post_type=page --post_status=publish --post_title="posts-1181" --porcelain'), 10);
    otherPage = parseInt(wp('post create --post_type=page --post_status=publish --post_title="other-1181" --porcelain'), 10);
    // The markup-titled post is created LAST so it is the newest and lands on page 1
    // (the posts index lists newest first).
    for (let i = 1; i <= 11; i++) {
      const title = i === 11 ? MARKUP_TITLE.replace(/"/g, '\\"') : `Post 1181 number ${i}`;
      posts.push(parseInt(wp(`post create --post_type=post --post_status=publish --post_title="${title}" --post_content="<p>Body ${i}.</p>" --porcelain`), 10));
    }
    totalPosts = parseInt(wp('post list --post_type=post --post_status=publish --format=count'), 10);
    perPage = parseInt(wp('option get posts_per_page'), 10);
    wp('option update show_on_front page');
    wp(`option update page_for_posts ${postsPage}`);
  });

  test.afterAll(() => {
    if (snapshotTaken) {
      wp(`option update page_for_posts ${savedPostsPage}`);
      wp(`option update show_on_front ${savedShowOnFront}`);
    }
    for (const id of [postsPage, otherPage, ...posts]) {
      try {
        wp(`post delete ${id} --force`);
      } catch {
        /* already gone */
      }
    }
  });

  test('the listing band is refused on a page that is not the posts page', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, otherPage, COMPOSITION);
    expect(res.success).toBe(false);
    expect(JSON.stringify(res)).toContain('the posts page');
  });

  for (const width of [375, 768, 1280]) {
    test(`authored /blog/ renders its composition and the main query @ ${width}`, async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      const res = await updateComposition(page, postsPage, COMPOSITION);
      expect(res.success, JSON.stringify(res).slice(0, 400)).toBe(true);

      await page.setViewportSize({ width, height: 900 });
      await page.addInitScript(() => { (window as any).__ppXss = 0; });
      await page.goto(`/?page_id=${postsPage}`);

      // The composed bands, not the hard-coded ones.
      await expect(page.locator('main [data-pp-band]')).toHaveCount(2);
      await expect(page.locator('#blog-hero .hero__title')).toHaveText('The journal');
      await expect(page.locator('main .hero__title', { hasText: 'Blog' })).toHaveCount(0);

      // This page of the main query: posts_per_page of them, newest first, page links
      // inside the listing band.
      expect(totalPosts, 'premise: more posts than one page holds').toBeGreaterThan(perPage);
      const listing = page.locator('#blog-listing');
      await expect(listing.locator('li.grid__item')).toHaveCount(perPage);
      await expect(listing.locator('.pp-pagination')).toHaveCount(1);

      // A markup-shaped title is text: the image never became an element and ran nothing.
      await expect(listing.locator('.grid__item-title', { hasText: 'Escaped title' })).toHaveCount(1);
      // Not vacuous: the stored title really carries the markup (WordPress keeps it raw),
      // and the card shows those characters as text.
      expect(await listing.locator('.grid__item-title', { hasText: 'Escaped title' }).textContent()).toContain('<img src=x');
      await expect(listing.locator('.grid__item-title img')).toHaveCount(0);
      expect(await page.evaluate(() => (window as any).__ppXss)).toBe(0);

      // The band's authored udc reaches every query-bound card.
      const ink = await canon(page, INK);
      const colours = await listing.locator('.grid__item-title').evaluateAll((els: Element[]) => els.map((e) => getComputedStyle(e).color));
      expect(colours.length).toBe(perPage);
      expect(new Set(colours)).toEqual(new Set([ink]));

      await page.screenshot({ path: `test-results/posts-page-1181-${width}.png`, fullPage: true });
    });
  }

  test('a posts page a visitor may not see keeps its composition to itself', async ({ page, browser }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, postsPage, COMPOSITION);
    expect(res.success).toBe(true);

    // Published, the composition is public: a logged-out visitor sees it.
    const publicVisitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const pub = await publicVisitor.newPage();
    await pub.goto(`${new URL(page.url()).origin}/?page_id=${postsPage}`);
    await expect(pub.locator('#blog-hero .hero__title')).toHaveText('The journal');
    await expect(pub.locator('#blog-listing li.grid__item')).toHaveCount(perPage);
    await publicVisitor.close();

    wp(`post update ${postsPage} --post_status=private`);
    try {
      // A logged-out visitor: an explicitly empty session (the config's admin state would
      // otherwise be applied to a new context too).
      const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      const anon = await visitor.newPage();
      await anon.goto(`${new URL(page.url()).origin}/?page_id=${postsPage}`);
      await expect(anon.locator('#blog-hero')).toHaveCount(0);
      await expect(anon.locator('main .hero__title', { hasText: 'Blog' })).toHaveCount(1);
      await expect(anon.locator('main li.grid__item')).toHaveCount(perPage);
      await visitor.close();

      // The editor may read the private page, so sees its composition.
      await page.goto(`/?page_id=${postsPage}`);
      await expect(page.locator('#blog-hero .hero__title')).toHaveText('The journal');
    } finally {
      wp(`post update ${postsPage} --post_status=publish`);
    }
  });

  test('page 2 of the listing is the rest of the main query', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, postsPage, COMPOSITION);
    expect(res.success).toBe(true);
    await page.goto(`/?page_id=${postsPage}&paged=2`);
    await expect(page.locator('#blog-listing li.grid__item')).toHaveCount(Math.min(perPage, totalPosts - perPage));
  });
});
