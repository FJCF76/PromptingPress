import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Rendered-proof E2E for the mobile nav disclosure (issue 426).
 *
 * The shipped bug: opening the mobile menu made `.nav__menu` a third item in the
 * header's nowrap flex row, crushing it into a ~94px column at x≈283 (375px vp) and
 * growing the sticky header 65px -> 229px. The static css-lint pin proves the CSS
 * declares `position: absolute` at max-width:767px; only a real browser proves the
 * open menu leaves the logo/toggle row byte-identical and renders as a full-width
 * left-aligned panel below it. Desktop (>=768px) must be untouched, including the
 * #381 submenu disclosure. Resize across the breakpoint must reset an open menu.
 */

const cli = (cmd: string) =>
  execSync(`npx wp-env run cli wp ${cmd}`, { cwd: process.cwd(), encoding: 'utf-8' }).trim();

let menuId = 0;
let parentItemId = 0;
let pageId = 0;

function createPage(title: string): number {
  const id = parseInt(
    cli(`post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`),
    10,
  );
  cli(`post meta update ${id} _wp_page_template composition.php`);
  cli(
    `post meta update ${id} _pp_composition '${JSON.stringify([
      { component: 'section', props: { id: 'pp-sec01', title: 'Body', body: '<p>Content below the header.</p>' } },
    ])}'`,
  );
  return id;
}

test.describe('Mobile nav disclosure (issue 426)', () => {
  test.beforeAll(() => {
    // A primary menu with a top-level item, an in-page-anchor item (so a link click
    // doesn't navigate away mid-test), and a parent+child pair for the #381 dropdown.
    menuId = parseInt(cli('menu create "E2E Nav 426" --porcelain'), 10);
    cli(`menu item add-custom ${menuId} "Home" "#home" --porcelain`);
    cli(`menu item add-custom ${menuId} "About" "#about" --porcelain`);
    parentItemId = parseInt(cli(`menu item add-custom ${menuId} "Services" "#services" --porcelain`), 10);
    cli(`menu item add-custom ${menuId} "Cloud" "#cloud" --parent-id=${parentItemId} --porcelain`);
    cli(`menu location assign ${menuId} primary`);
    pageId = createPage('E2E Nav 426 Host');
  });

  test.afterAll(() => {
    try { if (pageId) cli(`post delete ${pageId} --force`); } catch { /* noop */ }
    // Unassign the `primary` location first (explicit, so the theme mod is left in
    // its pre-test/unassigned state), then delete the menu. Deleting alone also
    // unassigns, but doing it explicitly documents the no-residue intent.
    try { if (menuId) cli(`menu location remove ${menuId} primary`); } catch { /* noop */ }
    try { if (menuId) cli(`menu delete ${menuId}`); } catch { /* noop */ }
  });

  // Box helpers.
  const box = (page: any, sel: string) => page.locator(sel).first().boundingBox();
  const near = (a: number, b: number, eps = 0.75) => Math.abs(a - b) < eps;

  // @smoke — the core geometry proof: opening the menu must NOT move the logo/toggle
  // row or grow the header, and the menu must render as a full-width left-aligned
  // panel below. Tagged @smoke so PR CI (which runs only @smoke) watches it — the
  // #423 lesson: a geometry regression must not be nightly-only.
  test('open menu leaves the logo/toggle row byte-identical and panels below @smoke', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/?page_id=${pageId}`);

    const toggle = page.locator('.nav__toggle');
    const menu = page.locator('#pp-nav-menu');
    await expect(toggle).toBeVisible();

    // Closed state: capture the row geometry + header height.
    await expect(menu).toBeHidden();
    const logoClosed = (await box(page, '.nav__logo'))!;
    const toggleClosed = (await box(page, '.nav__toggle'))!;
    const headerClosed = (await box(page, '.site-header'))!;
    expect(await toggle.getAttribute('aria-expanded')).toBe('false');

    // Open.
    await toggle.click();
    await expect(menu).toBeVisible();
    expect(await toggle.getAttribute('aria-expanded')).toBe('true');

    // The logo/toggle row is byte-identical (this is THE acceptance criterion).
    const logoOpen = (await box(page, '.nav__logo'))!;
    const toggleOpen = (await box(page, '.nav__toggle'))!;
    const headerOpen = (await box(page, '.site-header'))!;
    for (const k of ['x', 'y', 'width', 'height'] as const) {
      expect(near(logoOpen[k], logoClosed[k]), `logo ${k}`).toBe(true);
      expect(near(toggleOpen[k], toggleClosed[k]), `toggle ${k}`).toBe(true);
    }
    // The sticky header row height does not grow (the 65 -> 229 symptom).
    expect(near(headerOpen.height, headerClosed.height, 1)).toBe(true);

    // The menu is a full-width, left-aligned panel BELOW the row.
    const container = (await box(page, '.nav__container'))!;
    const firstLink = (await box(page, '#pp-nav-menu a'))!;
    expect(firstLink.y).toBeGreaterThan(toggleOpen.y + toggleOpen.height - 1); // below the row
    expect(firstLink.x).toBeLessThan(container.x + 24); // left-aligned, not a 94px right column
    expect(firstLink.width).toBeGreaterThan(container.width * 0.8); // spans the container

    // The toggle now shows the close (X) affordance, not the hamburger.
    const iconDisplay = await toggle.evaluate((el) => ({
      open: getComputedStyle(el.querySelector('.nav__toggle-icon--open')!).display,
      close: getComputedStyle(el.querySelector('.nav__toggle-icon--close')!).display,
    }));
    expect(iconDisplay.open).toBe('none');
    expect(iconDisplay.close).not.toBe('none');

    // Re-click closes.
    await toggle.click();
    await expect(menu).toBeHidden();
    expect(await toggle.getAttribute('aria-expanded')).toBe('false');
  });

  test('closes via Escape, outside click, and link click', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/?page_id=${pageId}`);

    const toggle = page.locator('.nav__toggle');
    const menu = page.locator('#pp-nav-menu');

    // Escape (returns focus to the toggle).
    await toggle.click();
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    expect(await toggle.evaluate((el) => el === document.activeElement)).toBe(true);

    // Outside click: a raw coordinate click well BELOW the open panel (the panel
    // overlays the top of the content, so a click there would land inside it). This
    // is bare page content outside .site-header, so the menu collapses.
    await toggle.click();
    await expect(menu).toBeVisible();
    await page.mouse.click(187, 650);
    await expect(menu).toBeHidden();
    expect(await toggle.getAttribute('aria-expanded')).toBe('false');

    // Link click (an in-page anchor, so it does not navigate away).
    await toggle.click();
    await expect(menu).toBeVisible();
    await page.locator('#pp-nav-menu a', { hasText: 'Home' }).click();
    await expect(menu).toBeHidden();
    expect(await toggle.getAttribute('aria-expanded')).toBe('false');
  });

  test('desktop is unaffected and an open mobile menu resets on resize', async ({ page }) => {
    // Desktop: hamburger hidden, menu horizontal, #381 dropdown still discloses.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    await expect(page.locator('.nav__toggle')).toBeHidden();
    await expect(page.locator('#pp-nav-menu')).toBeVisible();
    const listDir = await page.locator('#pp-nav-menu > ul').evaluate((el) => getComputedStyle(el).flexDirection);
    expect(listDir).toBe('row');

    // #381 submenu disclosure: main.js injects a .nav__submenu-toggle; clicking it
    // opens the group (is-open + the sub-menu renders). Proves #381 is untouched.
    const subToggle = page.locator('.nav__submenu-toggle').first();
    await expect(subToggle).toHaveCount(1);
    const parentLi = page.locator('li.pp-has-dropdown').first();
    expect(await parentLi.evaluate((el) => el.classList.contains('is-open'))).toBe(false);
    await subToggle.click();
    expect(await parentLi.evaluate((el) => el.classList.contains('is-open'))).toBe(true);
    expect(await subToggle.getAttribute('aria-expanded')).toBe('true');

    // Resize reset (addendum #1): open the menu at mobile, then grow past 768px.
    await page.setViewportSize({ width: 375, height: 812 });
    const toggle = page.locator('.nav__toggle');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(page.locator('#pp-nav-menu')).toBeVisible();
    expect(await toggle.getAttribute('aria-expanded')).toBe('true');

    await page.setViewportSize({ width: 1280, height: 900 });
    // Back on desktop: no lingering open state, hamburger hidden again.
    await expect(page.locator('.nav__toggle')).toBeHidden();
    expect(await toggle.getAttribute('aria-expanded')).toBe('false');
  });
});
