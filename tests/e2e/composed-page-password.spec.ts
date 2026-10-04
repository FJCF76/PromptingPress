import { test, expect, Browser, Page } from '@playwright/test';
import { execFileSync } from 'child_process';

/**
 * Composed pages honour post passwords, in the real browser with core's own cookie.
 *
 * A page on the Composition template, and the same page as the static front page, carry
 * a post password. A logged-out visitor sees core's password form and none of the page's
 * bands, and the head carries none of the page's authored CSS. After entering the
 * password through that form (core's wp-login.php?action=postpass sets the cookie), the
 * bands render with their authored styles.
 *
 * The composition is authored through the REAL write path (the admin AJAX
 * `update_composition`). tests/ComposedPagePasswordTest.php pins the same gates at unit
 * level; this spec is the only place core's cookie check runs.
 */

const CWD = process.cwd();
// WP-CLI through wp-env, with each argument passed as its own argv entry (no shell), so a
// value read back from the site is never re-parsed as command text.
const wp = (...args: string[]): string =>
  (execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: CWD, encoding: 'utf-8' }).trim().split('\n').pop() as string);

const PASSWORD = 'opensesame';
const INK = '#7a3e9d';
const COMPOSITION = [
  {
    component: 'section',
    props: { id: 'members-schedule', title: 'Members-only schedule', body: '<p>Thursday at eight.</p>' },
    udc: { body: { typography: { color: INK } } },
  },
];

let pageId = 0;
let savedShowOnFront = 'posts';
let savedPageOnFront = '0';
let snapshotTaken = false;

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

async function visitor(browser: Browser): Promise<Page> {
  // A logged-out visitor: an explicitly empty session (the config's admin state would
  // otherwise be applied to a new context too).
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  return context.newPage();
}

async function expectLocked(anon: Page) {
  // Core's own protected title, as a default-template page shows it.
  await expect(anon.locator('main h1')).toHaveText('Protected: members-password');
  await expect(anon.locator('main form.post-password-form')).toHaveCount(1);
  await expect(anon.locator('main input[name="post_password"]')).toHaveCount(1);
  await expect(anon.locator('main [data-pp-component]')).toHaveCount(0);
  const html = await anon.content();
  expect(html).not.toContain('Members-only schedule');
  expect(html).not.toContain('Thursday at eight.');
  expect(html.toLowerCase()).not.toContain(INK);
}

async function enterPassword(anon: Page) {
  await anon.locator('main input[name="post_password"]').fill(PASSWORD);
  await Promise.all([anon.waitForNavigation(), anon.locator('main form.post-password-form [type="submit"]').click()]);
}

async function expectUnlocked(anon: Page) {
  await expect(anon.locator('main form.post-password-form')).toHaveCount(0);
  const band = anon.locator('main [data-pp-component="section"]');
  await expect(band).toHaveCount(1);
  await expect(band).toContainText('Thursday at eight.');
  const colour = await band.locator('.section__content p').evaluate((e: Element) => getComputedStyle(e).color);
  expect(colour).toBe('rgb(122, 62, 157)');
}

test.describe('composed pages honour post passwords', () => {
  test.beforeAll(() => {
    savedShowOnFront = wp('option', 'get', 'show_on_front');
    savedPageOnFront = wp('option', 'get', 'page_on_front');
    snapshotTaken = true;
    pageId = parseInt(wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=members-password', `--post_password=${PASSWORD}`, '--porcelain'), 10);
    wp('post', 'meta', 'update', String(pageId), '_wp_page_template', 'composition.php');
  });

  test.afterAll(() => {
    if (snapshotTaken) {
      wp('option', 'update', 'show_on_front', savedShowOnFront);
      wp('option', 'update', 'page_on_front', savedPageOnFront);
    }
    if (pageId) {
      try {
        wp('post', 'delete', String(pageId), '--force');
      } catch {
        /* already gone */
      }
    }
  });

  test('protected page shows the password form, then its bands once the password is entered', async ({ page, browser }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, pageId, COMPOSITION);
    expect(res.success, JSON.stringify(res).slice(0, 400)).toBe(true);

    const anon = await visitor(browser);
    const url = `${new URL(page.url()).origin}/?page_id=${pageId}`;
    for (const width of [375, 1280]) {
      await anon.setViewportSize({ width, height: 900 });
      await anon.goto(url);
      await expectLocked(anon);
      await anon.screenshot({ path: `test-results/composed-page-password-form-${width}.png`, fullPage: true });
    }

    await enterPassword(anon);
    await expectUnlocked(anon);
    await anon.context().close();
  });

  test('protected static front page shows the password form, then its bands', async ({ page, browser }) => {
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await updateComposition(page, pageId, COMPOSITION);
    expect(res.success, JSON.stringify(res).slice(0, 400)).toBe(true);
    wp('option', 'update', 'show_on_front', 'page');
    wp('option', 'update', 'page_on_front', String(pageId));

    const anon = await visitor(browser);
    await anon.goto(`${new URL(page.url()).origin}/`);
    await expectLocked(anon);

    await enterPassword(anon);
    await expectUnlocked(anon);
    await anon.context().close();
  });
});
