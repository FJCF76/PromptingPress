import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Rendered-proof E2E for the footer baseline (issue 427).
 *
 * The static css-lint / PHP pins prove the markup and CSS declare the column grid,
 * the nav landmark, the <address> contact, and the #382 landing slot. Only a real
 * browser proves the applied cascade: three top-aligned columns with real gaps at
 * desktop, a clean stacked order (brand -> nav -> contact -> bottom bar) at mobile
 * with the bottom bar delimited, and an intentional minimal footer once every
 * pp_footer_* option is cleared. The structural scenario is tagged @smoke so PR CI
 * (which runs only @smoke) watches it — the #423 lesson: layout regressions must not
 * be nightly-only.
 */

const cli = (cmd: string) =>
  execSync(`npx wp-env run cli wp ${cmd}`, { cwd: process.cwd(), encoding: 'utf-8' }).trim();

let menuId = 0;
let pageId = 0;

// Every pp_footer_* option this suite sets, so afterAll can guarantee no residue.
const FOOTER_OPTIONS = [
  'pp_footer_blurb',
  'pp_footer_contact',
  'pp_footer_copyright',
  'pp_footer_note',
  'pp_footer_menu_label',
  'pp_footer_contact_label',
];

function createPage(title: string): number {
  const id = parseInt(
    cli(`post create --post_type=page --post_status=publish --post_author=1 --post_title="${title}" --porcelain`),
    10,
  );
  cli(`post meta update ${id} _wp_page_template composition.php`);
  cli(
    `post meta update ${id} _pp_composition '${JSON.stringify([
      { component: 'section', props: { id: 'pp-sec01', title: 'Body', body: '<p>Content above the footer.</p>' } },
    ])}'`,
  );
  return id;
}

function setFullFooter(): void {
  cli(`option update pp_footer_blurb "Ship credible sites fast."`);
  // One line exercising BOTH auto-link paths: an email and an international phone.
  cli(`option update pp_footer_contact "Email hello@promptingpress.test or call +1 (555) 123-4567"`);
  cli(`option update pp_footer_contact_label "Contact"`);
  cli(`option update pp_footer_menu_label "Explore"`);
  cli(`option update pp_footer_copyright "© 2026 PromptingPress."`);
  // A note triggers the delimited bottom bar (#335).
  cli(`option update pp_footer_note "Made with care."`);
}

function clearFooter(): void {
  for (const opt of FOOTER_OPTIONS) {
    try { cli(`option delete ${opt}`); } catch { /* not set */ }
  }
}

const box = (page: any, sel: string) => page.locator(sel).first().boundingBox();
const near = (a: number, b: number, eps = 2) => Math.abs(a - b) < eps;

test.describe('Footer baseline (issue 427)', () => {
  test.beforeAll(() => {
    menuId = parseInt(cli('menu create "E2E Footer 427" --porcelain'), 10);
    cli(`menu item add-custom ${menuId} "Privacy" "#privacy" --porcelain`);
    cli(`menu item add-custom ${menuId} "Terms" "#terms" --porcelain`);
    cli(`menu location assign ${menuId} footer`);
    pageId = createPage('E2E Footer 427 Host');
  });

  test.afterAll(() => {
    clearFooter();
    try { if (pageId) cli(`post delete ${pageId} --force`); } catch { /* noop */ }
    try { if (menuId) cli(`menu location remove ${menuId} footer`); } catch { /* noop */ }
    try { if (menuId) cli(`menu delete ${menuId}`); } catch { /* noop */ }
  });

  test('desktop columns are top-aligned with real gaps, nav is a labelled landmark @smoke', async ({ page }) => {
    setFullFooter();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    // The footer nav is a real landmark distinct from the header's.
    const footerNav = page.locator('.site-footer nav.site-footer__nav');
    await expect(footerNav).toHaveAttribute('aria-label', 'Footer navigation');

    const brand = (await box(page, '.site-footer__brand'))!;
    const nav = (await box(page, '.site-footer__nav'))!;
    const contact = (await box(page, '.site-footer__contact'))!;
    expect(brand).toBeTruthy();
    expect(nav).toBeTruthy();
    expect(contact).toBeTruthy();

    // Three columns laid left-to-right in DOM order, each with a positive gap.
    expect(nav.x).toBeGreaterThan(brand.x + brand.width);
    expect(contact.x).toBeGreaterThan(nav.x + nav.width);

    // Tops aligned (align-items: start on the grid).
    expect(near(nav.y, brand.y), `nav/brand top: ${nav.y} vs ${brand.y}`).toBe(true);
    expect(near(contact.y, brand.y), `contact/brand top: ${contact.y} vs ${brand.y}`).toBe(true);

    // The contact block is an <address> and its email is an actionable link.
    await expect(page.locator('.site-footer__address')).toHaveCount(1);
    await expect(page.locator('.site-footer__address a[href="mailto:hello@promptingpress.test"]')).toHaveCount(1);
    await expect(page.locator('.site-footer__address a[href="tel:+15551234567"]')).toHaveCount(1);
  });

  test('mobile stacks brand -> nav -> contact -> delimited bottom bar', async ({ page }) => {
    setFullFooter();
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/?page_id=${pageId}`);

    const brand = (await box(page, '.site-footer__brand'))!;
    const nav = (await box(page, '.site-footer__nav'))!;
    const contact = (await box(page, '.site-footer__contact'))!;
    const bottom = (await box(page, '.site-footer__bottom'))!;

    // Stacked, single column, in reading order.
    expect(nav.y).toBeGreaterThan(brand.y + brand.height - 1);
    expect(contact.y).toBeGreaterThan(nav.y + nav.height - 1);
    expect(bottom.y).toBeGreaterThan(contact.y + contact.height - 1);

    // The bottom bar keeps its delimited treatment (#335): a real top border.
    const borderTop = await page
      .locator('.site-footer__bottom')
      .evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(parseFloat(borderTop)).toBeGreaterThan(0);
  });

  test('a cleared footer still renders an intentional minimal footer', async ({ page }) => {
    clearFooter();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    // No option-driven columns, but the nav landmark + copyright still render.
    await expect(page.locator('.site-footer')).toHaveCount(1);
    await expect(page.locator('.site-footer nav.site-footer__nav')).toHaveAttribute(
      'aria-label',
      'Footer navigation',
    );
    await expect(page.locator('.site-footer__copyright')).toHaveCount(1);
    // No orphaned option-driven blocks left behind.
    await expect(page.locator('.site-footer__brand')).toHaveCount(0);
    await expect(page.locator('.site-footer__contact')).toHaveCount(0);
    await expect(page.locator('.site-footer__bottom')).toHaveCount(0);
  });
});

/**
 * Rendered-proof E2E for the SECOND footer menu column (issue 469).
 *
 * The PHP pins prove the markup renders only when a menu is assigned to the
 * footer_secondary location, with a distinct aria-label and an optional heading.
 * Only a real browser proves the applied grid: with a menu assigned, the footer
 * lays out FOUR equal columns left-to-right at desktop and stacks the second menu
 * in reading order (brand -> primary nav -> secondary nav -> contact) at mobile,
 * with no CSS change beyond the existing auto-flow grid. Kept in its own describe
 * so the footer_secondary assignment never perturbs the single-nav #427 assertions.
 */
test.describe('Footer secondary menu column (issue 469)', () => {
  let primaryMenuId = 0;
  let secondaryMenuId = 0;
  let hostPageId = 0;

  test.beforeAll(() => {
    primaryMenuId = parseInt(cli('menu create "E2E Footer 469 Primary" --porcelain'), 10);
    cli(`menu item add-custom ${primaryMenuId} "Servicio" "#servicio" --porcelain`);
    cli(`menu item add-custom ${primaryMenuId} "Empresa" "#empresa" --porcelain`);
    cli(`menu location assign ${primaryMenuId} footer`);

    secondaryMenuId = parseInt(cli('menu create "E2E Footer 469 Legal" --porcelain'), 10);
    cli(`menu item add-custom ${secondaryMenuId} "Aviso legal" "#aviso" --porcelain`);
    cli(`menu item add-custom ${secondaryMenuId} "Privacidad" "#privacidad" --porcelain`);
    cli(`menu item add-custom ${secondaryMenuId} "Cookies" "#cookies" --porcelain`);
    cli(`menu location assign ${secondaryMenuId} footer_secondary`);

    // Headings on both menu columns.
    cli(`option update pp_footer_menu_label "Explore"`);
    cli(`option update pp_footer_secondary_label "Legal"`);

    hostPageId = createPage('E2E Footer 469 Host');
  });

  test.afterAll(() => {
    try { cli(`option delete pp_footer_menu_label`); } catch { /* noop */ }
    try { cli(`option delete pp_footer_secondary_label`); } catch { /* noop */ }
    try { if (hostPageId) cli(`post delete ${hostPageId} --force`); } catch { /* noop */ }
    try { if (primaryMenuId) cli(`menu location remove ${primaryMenuId} footer`); } catch { /* noop */ }
    try { if (secondaryMenuId) cli(`menu location remove ${secondaryMenuId} footer_secondary`); } catch { /* noop */ }
    try { if (primaryMenuId) cli(`menu delete ${primaryMenuId}`); } catch { /* noop */ }
    try { if (secondaryMenuId) cli(`menu delete ${secondaryMenuId}`); } catch { /* noop */ }
  });

  test('desktop renders a second footer menu column with a distinct landmark @smoke', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${hostPageId}`);

    // Two footer nav landmarks with distinct aria-labels.
    await expect(page.locator('.site-footer nav.site-footer__nav')).toHaveCount(2);
    await expect(
      page.locator('.site-footer nav[aria-label="Footer navigation"]'),
    ).toHaveCount(1);
    const secondary = page.locator('.site-footer nav[aria-label="Footer secondary navigation"]');
    await expect(secondary).toHaveCount(1);
    // Its heading + a real link from the assigned Legal menu.
    await expect(secondary.locator('h2.site-footer__heading')).toHaveText('Legal');
    await expect(secondary.locator('a', { hasText: 'Privacidad' })).toHaveCount(1);

    // Four equal columns laid out left-to-right, each with a positive gap.
    const primaryNav = (await box(page, 'nav[aria-label="Footer navigation"]'))!;
    const secondaryNav = (await box(page, 'nav[aria-label="Footer secondary navigation"]'))!;
    expect(primaryNav).toBeTruthy();
    expect(secondaryNav).toBeTruthy();
    expect(secondaryNav.x).toBeGreaterThan(primaryNav.x + primaryNav.width);
    expect(near(secondaryNav.y, primaryNav.y), `secondary/primary top: ${secondaryNav.y} vs ${primaryNav.y}`).toBe(true);
  });

  test('mobile stacks the second menu in reading order after the primary menu', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/?page_id=${hostPageId}`);

    const primaryNav = (await box(page, 'nav[aria-label="Footer navigation"]'))!;
    const secondaryNav = (await box(page, 'nav[aria-label="Footer secondary navigation"]'))!;
    expect(primaryNav).toBeTruthy();
    expect(secondaryNav).toBeTruthy();
    // Stacked single-column: the secondary menu sits below the primary menu.
    expect(secondaryNav.y).toBeGreaterThan(primaryNav.y + primaryNav.height - 1);
  });
});
