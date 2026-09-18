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

  // UNCONDITIONAL NET for the site-global chrome option.
  //
  // The styled-panel test below clears it in a `finally`, which is the fast path — but
  // a Playwright TIMEOUT tears the test down without guaranteeing that block runs, and
  // `pp_site_udc` is SITE-wide. With `workers: 1` and `fullyParallel: false`, a leak
  // from here would still be styling the header when style-render.spec.ts starts
  // reading computed header colours, and those failures would point anywhere but at
  // this file. afterEach always runs, so it is the net under the fast path.
  test.afterEach(() => {
    try { cli('option delete pp_site_udc'); } catch { /* not set — nothing to clean */ }
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

    // #381 submenu disclosure: the theme's nav walker renders a .nav__submenu-toggle
    // and main.js wires it (the button moved server-side in #994); clicking it
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
    // One-shot on purpose (see the note below): the click handler runs in the same
    // task as the click, so this must already be true — a retry here would hand a
    // 5s grace period to a state that has to settle in 0ms.
    expect(await toggle.getAttribute('aria-expanded')).toBe('true');

    await page.setViewportSize({ width: 1280, height: 900 });
    // Back on desktop: no lingering open state, hamburger hidden again.
    //
    // FLAKE FIXED (#696, 2026-08-17). These two were one-shot
    // `expect(await toggle.getAttribute(...))` reads and the second one failed roughly
    // 1 run in 2 with Received "true" — red on the 2026-08-16 nightly, green on a
    // local rerun of the identical commit. Two DIFFERENT mechanisms settle this state:
    // the hamburger hides by pure CSS (the `@media (min-width: 768px)` rule in
    // components.css), which a forced style recalc satisfies immediately, while
    // aria-expanded is cleared by JS — assets/js/main.js resets the open menu from a
    // `matchMedia('(min-width: 768px)')` CHANGE listener, and Chromium dispatches
    // MediaQueryList change events in the rendering lifecycle, which can lag that
    // recalc. So `toBeHidden()` could go green a frame before the listener had run,
    // and the non-retrying read that followed sampled the stale attribute.
    //
    // Web-first assertions retry, which is the correct wait condition for a value two
    // independent mechanisms converge on. No product change is warranted: the toggle
    // is `display: none` at >=768px, so it is out of the accessibility tree entirely
    // and the sub-frame attribute state is unobservable to assistive tech — it was
    // only ever visible to a DOM-attribute read.
    //
    // The other aria-expanded reads in this file deliberately stay one-shot: each
    // follows a click or keypress whose handler runs in the same task as the event, so
    // a single mechanism settles the attribute and there is nothing to converge with.
    // Only a viewport resize splits the work across CSS and a matchMedia listener.
    await expect(page.locator('.nav__toggle')).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  });

  /**
   * THE DISCLOSURE STILL WORKS WHEN THE PANEL IS STYLED (#991).
   *
   * The mobile menu's open/close BEHAVIOUR is structural: `position: absolute` at
   * max-width 767px, the `hidden` attribute owned by main.js. Its APPEARANCE is now
   * authorable — `menu` is a UDC role, and an authored chrome block is unlayered, so
   * it outranks the very `@media (max-width: 767px)` rule that makes the panel a panel.
   *
   * That is the risk worth a rendered test: an authored `background` is harmless, but
   * it proves the two systems coexist on the same element. What must survive is the
   * mechanism — opens, panels BELOW the header row at full width, and closes again.
   * Close is asserted explicitly because an open-only test would pass against a panel
   * that can never be dismissed, which on a phone is the whole navigation gone.
   */
  test('an authored menu panel still opens and closes at 375', async ({ page }) => {
    // Perturb the panel with values that could actually break it, not just a fill:
    // padding and a border change its box, and a radius its shape — all authored on
    // the very element whose out-of-flow positioning makes the disclosure work.
    const map = JSON.stringify({
      nav: {
        _band: { background: { fill: '#101828' } },
        menu: {
          background: { fill: '#101828' },
          spacing: { padding: '12px' },
          border: { width: '2px', color: '#ffd166', radius: '8px' },
        },
        link: { typography: { color: '#f7f8fa', ':hover': { color: '#ffd166' } } },
        toggle: { typography: { color: '#ffffff', ':hover': { color: '#ffd166' } } },
      },
    });
    // Single-quote escape, matching style-render.spec.ts: the value is hardcoded today,
    // but an apostrophe in a future fixture would otherwise break out of the quoting
    // and silently mangle the command.
    cli(`option update pp_site_udc '${map.replace(/'/g, `'\\''`)}'`);

    try {
      await page.setViewportSize({ width: 375, height: 812 });
      await page.goto(`/?page_id=${pageId}`);

      const toggle = page.locator('.nav__toggle');
      const menu = page.locator('#pp-nav-menu');
      await expect(toggle).toBeVisible();
      await expect(menu).toBeHidden();

      const headerClosed = (await box(page, '.site-header'))!;

      // OPEN.
      await toggle.click();
      await expect(menu).toBeVisible();
      expect(await toggle.getAttribute('aria-expanded')).toBe('true');

      // The authored fill landed...
      expect(await menu.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe(
        'rgb(16, 24, 40)',
      );
      // ...and the structural mechanism it now outranks is intact: still out of flow,
      // still anchored under the header row, still full width.
      expect(await menu.evaluate((el) => getComputedStyle(el).position)).toBe('absolute');
      const panel = (await box(page, '#pp-nav-menu'))!;
      expect(near(panel.x, 0)).toBe(true);
      expect(near(panel.width, 375)).toBe(true);
      expect(panel.y >= headerClosed.y + headerClosed.height - 1).toBe(true);

      // The sticky header did not grow to swallow the panel.
      const headerOpen = (await box(page, '.site-header'))!;
      expect(near(headerOpen.height, headerClosed.height)).toBe(true);

      // CLOSE — the affordance still dismisses a styled panel.
      await toggle.click();
      await expect(menu).toBeHidden();
      expect(await toggle.getAttribute('aria-expanded')).toBe('false');
    } finally {
      try {
        cli('option delete pp_site_udc');
      } catch {
        /* nothing stored */
      }
    }
  });
});
