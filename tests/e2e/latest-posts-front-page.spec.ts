import { test, expect, Browser, Page } from '@playwright/test';
import { execFileSync } from 'child_process';

/**
 * #1173 in real WordPress: what `/` renders and writes.
 *
 * 1. Settings -> Reading "Your latest posts". Core still loads front-page.php (is_front_page()
 *    precedes is_home() in the template loader). Before the fix that rendered the default
 *    homepage with NO CSS (hero padding 0) and, on the visitor's GET, wrote a page
 *    composition onto the newest blog post. Now `/` is the posts index: the listing,
 *    its role defaults in the head, and no write.
 * 2. The newest post carries a password AND a stored composition (ruling 2): none of its
 *    bands, text or band CSS reach `/`.
 * 3. A password-protected STATIC front page with no composition stored (ruling 1): a visitor
 *    without the password sees the form and causes no seed; with the password, the
 *    defaults are seeded once and render, as #506 promises.
 *
 * Writes and reads go through WP-CLI with each argument its own argv entry (no shell).
 * The shared options are snapshotted first and restored, every created post deleted.
 */

const CWD = process.cwd();
const wp = (...args: string[]): string =>
  (execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string);

const PASSWORD = 'opensesame';
const SECRET = 'Thursday at eight, members only';
const INK = '#7a3e9d';

const created: number[] = [];
let savedShowOnFront = 'page';
let savedPageOnFront = '0';
let snapshotTaken = false;

function createPost(type: 'post' | 'page', title: string, extra: string[] = []): number {
  const id = parseInt(wp('post', 'create', `--post_type=${type}`, '--post_status=publish', `--post_title=${title}`, ...extra, '--porcelain'), 10);
  created.push(id);
  return id;
}

function compositionCount(id: number): string {
  return wp('post', 'meta', 'list', String(id), '--keys=_pp_composition', '--format=count');
}

/** Every post and page on the site that stores a composition: a write anywhere shows here. */
function storedCompositions(): string {
  return wp('post', 'list', '--post_type=post,page', '--post_status=any', '--meta_key=_pp_composition', '--format=ids');
}

async function visitor(browser: Browser): Promise<Page> {
  // A logged-out visitor. A context made from `browser` does not inherit the project's
  // `use` options, so the baseURL is passed on.
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    storageState: { cookies: [], origins: [] },
  });
  return context.newPage();
}

test.describe('the front page (#1173)', () => {
  test.beforeAll(() => {
    savedShowOnFront = wp('option', 'get', 'show_on_front');
    savedPageOnFront = wp('option', 'get', 'page_on_front');
    snapshotTaken = true;
  });

  test.afterAll(() => {
    if (snapshotTaken) {
      wp('option', 'update', 'show_on_front', savedShowOnFront);
      wp('option', 'update', 'page_on_front', savedPageOnFront);
    }
    for (const id of created) {
      try {
        wp('post', 'delete', String(id), '--force');
      } catch {
        /* already gone */
      }
    }
  });

  test('a latest-posts front page renders the posts index with its CSS and writes nothing', async ({ browser }) => {
    createPost('post', 'Latest-posts older entry', ['--post_date=2001-01-01 10:00:00']);
    const newest = createPost('post', 'Latest-posts newest entry');
    wp('option', 'update', 'show_on_front', 'posts');
    const before = storedCompositions();

    const anon = await visitor(browser);
    try {
      for (const width of [375, 1280]) {
        await anon.setViewportSize({ width, height: 900 });
        const res = await anon.goto('/');
        expect(res?.status()).toBe(200);
        // The posts index: home.php's hero and a card per post, never the default homepage.
        await expect(anon.locator('main [data-pp-component="hero"] h1')).toHaveText('Blog');
        await expect(anon.locator('main [data-pp-component="grid"]')).toContainText('Latest-posts newest entry');
        await expect(anon.locator('main #home-hero')).toHaveCount(0);
        // Its role defaults are printed (bare markup had a hero with zero padding).
        const html = await anon.content();
        expect(html).toContain('[data-pp-component="hero"]');
        const pad = await anon.locator('main [data-pp-component="hero"]').evaluate((e: Element) => parseFloat(getComputedStyle(e).paddingTop));
        expect(pad).toBeGreaterThan(0);
        await anon.screenshot({ path: `test-results/latest-posts-front-${width}.png`, fullPage: true });
      }
      // Paginated, the same route (no write either).
      await anon.goto('/?paged=2');
    } finally {
      await anon.context().close();
    }

    // The visitor's GETs wrote nothing: not onto the newest post, nor anywhere else.
    expect(compositionCount(newest)).toBe('0');
    expect(storedCompositions()).toBe(before);
  });

  test("a protected newest post's stored composition never renders on a latest-posts front page", async ({ browser }) => {
    const newest = createPost('post', 'members-newest', [`--post_password=${PASSWORD}`]);
    // A render fixture: the composition is stored as it would be by any writer.
    wp('post', 'meta', 'update', String(newest), '_pp_composition', JSON.stringify([
      {
        component: 'section',
        props: { id: 'members-schedule', title: 'Members-only schedule', body: `<p>${SECRET}</p>` },
        udc: { body: { typography: { color: INK } } },
      },
    ]));
    wp('option', 'update', 'show_on_front', 'posts');

    const anon = await visitor(browser);
    try {
      await anon.goto('/');
      // Listed as core lists any protected post; its own bands are not painted.
      await expect(anon.locator('main [data-pp-component="grid"]')).toContainText('Protected: members-newest');
      const html = await anon.content();
      expect(html).not.toContain(SECRET);
      expect(html).not.toContain('Members-only schedule');
      expect(html.toLowerCase()).not.toContain(INK);
      await expect(anon.locator('main #members-schedule')).toHaveCount(0);
    } finally {
      await anon.context().close();
    }
  });

  test('a protected static front page with no composition is not seeded by a visitor without the password', async ({ browser }) => {
    const front = createPost('page', 'members-front', [`--post_password=${PASSWORD}`]);
    wp('option', 'update', 'show_on_front', 'page');
    wp('option', 'update', 'page_on_front', String(front));
    expect(compositionCount(front)).toBe('0');

    const before = storedCompositions();
    const anon = await visitor(browser);
    try {
      await anon.goto('/');
      await expect(anon.locator('main h1')).toHaveText('Protected: members-front');
      await expect(anon.locator('main form.post-password-form')).toHaveCount(1);
      await expect(anon.locator('main [data-pp-component]')).toHaveCount(0);
      expect(compositionCount(front)).toBe('0');
      expect(storedCompositions()).toBe(before);

      // With the password, the configured front page is seeded once and renders (#506).
      await anon.locator('main input[name="post_password"]').fill(PASSWORD);
      await Promise.all([anon.waitForNavigation(), anon.locator('main form.post-password-form [type="submit"]').click()]);
      await expect(anon.locator('main form.post-password-form')).toHaveCount(0);
      await expect(anon.locator('main [data-pp-component="hero"]').first()).toBeVisible();
      expect(compositionCount(front)).toBe('1');
    } finally {
      await anon.context().close();
    }
  });
});
