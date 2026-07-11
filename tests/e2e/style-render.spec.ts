import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * Rendered-proof E2E for the safe-surface sprint.
 *
 * The static StyleSlotContractTest proves the CSS *consumes* a slot var; it cannot
 * prove the browser actually *renders* the slot value once the full cascade (media
 * queries, specificity) is applied. These tests close that gap with getComputedStyle:
 *
 *   #86 — a per-instance `--grid-heading-color` must win at the 1280px DESKTOP
 *         breakpoint, where the old `main > .grid .grid__heading { color: var(--color-text) }`
 *         rule used to clobber it (mobile always passed).
 *   #24 — a per-instance `--hero-surface-*` slot must reach the rendered `.hero__surface`
 *         shell (previously hardcoded, uncontrollable through safe surfaces).
 *   #225 — the hero eyebrow must render as a pill sized to its text. It is a direct flex
 *          item of the flex-column `.hero__content`, which blockifies its declared
 *          `inline-block` and stretched it across the full content width. The CSS-text
 *          pins in tests/js/css-lint.test.js can only prove the declaration is present;
 *          only a rendered box can prove the pill is not a band.
 *   #255 — the cta eyebrow must render as a pill ABOVE the title. `display: contents` on
 *          `#home-cta .cta__text` promotes it into a grid, which blockified it AND
 *          auto-placed it into row 3, column 1 — a band the full width of the title
 *          column, below the button. Position, not just width, is what a rendered box
 *          proves here.
 */

// ── Helpers (mirrors validation.spec.ts) ────────────────────────────────────

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

/** Dispatch a style_component action via the admin AJAX endpoint (picks up the nonce). */
async function styleComponent(page: any, postId: number, style: Record<string, unknown>) {
  return page.evaluate(
    async (args: { pid: number; style: Record<string, unknown> }) => {
      const config = (window as any).ppAiChat;
      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'style_component');
      data.append('params[post_id]', String(args.pid));
      data.append('params[component_index]', '0');
      data.append('params[style]', JSON.stringify(args.style));
      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    },
    { pid: postId, style },
  );
}

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Safe-surface rendered proof', () => {
  let pageId: number;

  test.afterEach(async () => {
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  // @smoke — #86: the grid heading-color slot must win on DESKTOP, not just be present
  // in the CSS. Regression proof for the cross-block override that buried it at >=768px.
  test('#86 grid heading honors --grid-heading-color at 1280px desktop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Heading Color');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'What makes the site AI-operable',
          items: [{ title: 'One', text: 'First' }],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A vivid color no theme token uses, so a clobber by --color-text would be obvious.
    const res = await styleComponent(page, pageId, { '--grid-heading-color': '#ff0080' });
    expect(res.success).toBe(true);

    // Desktop viewport: this is the breakpoint where #86 manifested.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const heading = page.locator('.grid__heading');
    await expect(heading).toBeVisible({ timeout: 10000 });

    const color = await heading.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(255, 0, 128)');
  });

  // #24: a hero-surface slot must reach the rendered inner shell (.hero__surface only
  // renders for the split variant with proof markup).
  test('#24 hero surface honors --hero-surface-border-width', async ({ page }) => {
    pageId = createPage('E2E Hero Surface Slot');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'split',
          title: 'Hero',
          proof: '<p>Product workflow surface</p>',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Distinct from the 1px default, so honoring the slot is unambiguous.
    const res = await styleComponent(page, pageId, { '--hero-surface-border-width': '7px' });
    expect(res.success).toBe(true);

    await page.goto(`/?page_id=${pageId}`);

    const surface = page.locator('.hero__surface');
    await expect(surface).toBeVisible({ timeout: 10000 });

    const borderWidth = await surface.evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(borderWidth).toBe('7px');
  });

  // #225: the eyebrow is a pill, not a band. Each layout is its own test so a failure
  // names the layout that regressed. `left`/`split` flush the pill to the content's
  // leading edge; `centered`/`cover` center it, matching how those layouts already
  // treat the CTA group.
  //
  // Both viewports matter. A band restored by a `max-width: 767px` rule is invisible at
  // desktop, and #86's docblock above records that "mobile always passed" is exactly how
  // the last cascade bug in this file hid.
  const eyebrowLayouts: { layout: string; align: 'start' | 'center' }[] = [
    { layout: 'left', align: 'start' },
    { layout: 'split', align: 'start' },
    { layout: 'centered', align: 'center' },
    { layout: 'cover', align: 'center' },
  ];
  const eyebrowViewports = [
    { label: 'desktop', width: 1280, height: 900 },
    { label: 'mobile', width: 375, height: 800 },
  ];

  for (const { layout, align } of eyebrowLayouts) {
    for (const viewport of eyebrowViewports) {
      // One @smoke case, so the post-merge main run (which executes only the @smoke
      // subset) still watches the pill. The rest run nightly.
      const smoke = layout === 'left' && viewport.label === 'desktop' ? ' @smoke' : '';

      test(`#225 hero eyebrow renders as a pill, not a full-width band (${layout}, ${viewport.label})${smoke}`, async ({
        page,
      }) => {
        pageId = createPage(`E2E Hero Eyebrow Pill ${layout} ${viewport.label}`);
        setComposition(pageId, [
          {
            component: 'hero',
            props: {
              id: 'pp-hero01',
              layout,
              // A long title widens .hero__content, so a stretched eyebrow is unmistakable.
              title: 'A deliberately long hero headline that widens the content column',
              eyebrow: 'BETA',
            },
          },
        ]);

        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.goto(`/?page_id=${pageId}`);

        const eyebrow = page.locator('.hero__eyebrow');
        await expect(eyebrow).toBeVisible({ timeout: 10000 });

        const eyebrowBox = (await eyebrow.boundingBox())!;
        const contentBox = (await page.locator('.hero__content').boundingBox())!;

        // The bug: the eyebrow spanned the full content width. "BETA" in a padded pill is
        // nowhere near half the column, so this fails loudly on any return of the band.
        expect(eyebrowBox.width).toBeLessThan(contentBox.width * 0.5);

        if (align === 'start') {
          expect(Math.abs(eyebrowBox.x - contentBox.x)).toBeLessThan(2);
        } else {
          const eyebrowCenter = eyebrowBox.x + eyebrowBox.width / 2;
          const contentCenter = contentBox.x + contentBox.width / 2;
          expect(Math.abs(eyebrowCenter - contentCenter)).toBeLessThan(2);
        }
      });
    }
  }

  // #255: the same visual failure as #225 on the CTA, but reached through the grid.
  // `#home-cta .cta__text` is `display: contents` at >=768px, so the eyebrow is promoted
  // into the `.cta__inner` GRID, blockified, and — with no placement of its own —
  // auto-flowed past every explicitly-placed sibling into a stretched row-3 cell. The
  // rendered bug was a band the full width of the title column, BELOW the button, so
  // width alone does not prove the fix: these assert the box is a pill AND sits above
  // the title.
  //
  // 768px is covered to prove the placement rules take effect the moment the media
  // query matches. It now also FITS there: #258 replaced the old fixed track floors
  // (minmax(36rem, 40rem) + minmax(18rem, 1fr), which needed ~57rem the 768px
  // breakpoint never had) with shrinkable ones. The overflow those floors caused is
  // asserted against directly by the #258 block below.
  const ctaEyebrowViewports = [
    { label: 'desktop', width: 1280, height: 900 },
    { label: 'breakpoint', width: 768, height: 900 },
    { label: 'mobile', width: 375, height: 800 },
  ];

  for (const viewport of ctaEyebrowViewports) {
    // One @smoke case, so the post-merge main run (which executes only the @smoke
    // subset) still watches the pill.
    const smoke = viewport.label === 'desktop' ? ' @smoke' : '';

    test(`#255 cta eyebrow renders as a pill above the title (${viewport.label})${smoke}`, async ({
      page,
    }) => {
      pageId = createPage(`E2E CTA Eyebrow Pill ${viewport.label}`);
      setComposition(pageId, [
        {
          component: 'cta',
          props: {
            // The bug is ID-scoped: only #home-cta gets `display: contents`.
            id: 'home-cta',
            layout: 'inline',
            title: 'A deliberately long closing headline that widens the title column',
            text: 'Supporting copy that occupies the right-hand column of the grid.',
            eyebrow: 'BETA',
            button_text: 'Get started',
            button_url: '/start',
          },
        },
      ]);

      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(`/?page_id=${pageId}`);

      const eyebrow = page.locator('.cta__eyebrow');
      await expect(eyebrow).toBeVisible({ timeout: 10000 });

      const eyebrowBox = (await eyebrow.boundingBox())!;
      const titleBox = (await page.locator('.cta__title').boundingBox())!;

      // The band spanned the whole title column. "BETA" in a padded pill is nowhere near
      // half of it, so this fails loudly on any return of the stretch.
      expect(eyebrowBox.width).toBeLessThan(titleBox.width * 0.5);

      // The half a width-only assertion would miss: auto-placement put the pill in row 3,
      // under the button. An eyebrow that renders below its own headline is not an eyebrow.
      expect(eyebrowBox.y + eyebrowBox.height).toBeLessThanOrEqual(titleBox.y + 1);

      // Same column as the title, flush to its leading edge.
      expect(Math.abs(eyebrowBox.x - titleBox.x)).toBeLessThan(2);
    });
  }

  // The state every shipped page is actually in: #home-cta with NO eyebrow. The fix moved
  // the title off row 1, so an empty row now sits above it on every live CTA. That row
  // collapses only while the row gap is 0 — a `gap` shorthand added to the desktop block
  // would silently open a band of dead space above every homepage headline. The CSS-text
  // pins guard the declarations; only a rendered box proves the row actually collapsed.
  test('#255 a cta with no eyebrow keeps its title flush to the top (empty row collapses) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA No Eyebrow Row Collapse');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'inline',
          title: 'A deliberately long closing headline that widens the title column',
          text: 'Supporting copy that occupies the right-hand column of the grid.',
          button_text: 'Get started',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const title = page.locator('.cta__title');
    await expect(title).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.cta__eyebrow')).toHaveCount(0);

    const titleBox = (await title.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;

    // The title's inset from the top of .cta__inner is what a phantom row 1 inflates.
    // Measured in chromium at 1280px: 71px with the row collapsed (correct), 107px once
    // a `gap: 1.5rem` shorthand resets row-gap and the empty row takes up space. 90px
    // sits clear of both, so this fails on the regression without pinning exact metrics.
    expect(titleBox.y - innerBox.y).toBeLessThan(90);
  });

  // The placement rules are deliberately scoped to `.cta--inline`, because `.cta__inner`
  // is a flex COLUMN in the default full-width layout — where the cross axis is
  // horizontal and `align-self: end` would push the pill to the right edge instead of
  // leaving it centered. An unscoped `#home-cta .cta__eyebrow` selector would fix the
  // inline layout by breaking the default one, and no inline-only test would notice.
  test('#255 the full-width cta keeps its centered pill (fix stays scoped) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Eyebrow Full Width');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'full-width',
          title: 'A deliberately long closing headline for the full width layout',
          eyebrow: 'BETA',
          button_text: 'Get started',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const eyebrow = page.locator('.cta__eyebrow');
    await expect(eyebrow).toBeVisible({ timeout: 10000 });

    const eyebrowBox = (await eyebrow.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;

    expect(eyebrowBox.width).toBeLessThan(innerBox.width * 0.5);

    // Centered, not flushed to either edge. The regression this guards renders the pill
    // hard against the right edge of .cta__inner.
    const eyebrowCenter = eyebrowBox.x + eyebrowBox.width / 2;
    const innerCenter = innerBox.x + innerBox.width / 2;
    expect(Math.abs(eyebrowCenter - innerCenter)).toBeLessThan(2);
  });

  /*
   * #258: the inline CTA grid turns on at 768px, but the old track floors
   * (minmax(36rem, 40rem) + minmax(18rem, 1fr) + a >=3rem gap = ~57rem) could not fit
   * in the content box that breakpoint actually provides — the container's padding and
   * .cta__inner's own clamp(2rem, 4vw, 2.6rem) padding + border leave roughly 40rem at
   * 768px. The grid could not shrink, so it pushed the page sideways.
   *
   * Measured on THIS page with the fix reverted (documentElement.scrollWidth): 768px ->
   * 977, 800px -> 977, 860px -> 983, 912px -> 983, 1024px -> fits. So 768..912 are the
   * cases that actually catch the regression; 1024 is carried as the clean upper edge, to
   * fail if a future change ever widens the band into it rather than to prove today's bug.
   *
   * A page that scrolls sideways is the whole bug, so assert exactly that, at the widths
   * that used to fail. scrollWidth is rounded up to an integer, so allow 1px of slack
   * rather than pinning a fractional layout.
   */
  const ctaOverflowViewports = [768, 800, 860, 912, 1024];

  for (const width of ctaOverflowViewports) {
    // One @smoke case at the worst-overflowing width, so the post-merge main run (which
    // executes only the @smoke subset) still watches for the sideways scroll.
    const smoke = width === 768 ? ' @smoke' : '';

    test(`#258 the inline cta does not scroll the page sideways at ${width}px${smoke}`, async ({
      page,
    }) => {
      pageId = createPage(`E2E CTA Overflow ${width}`);
      setComposition(pageId, [
        {
          component: 'cta',
          props: {
            // The overflowing track override is scoped to #home-cta.
            id: 'home-cta',
            layout: 'inline',
            title: 'A deliberately long closing headline that widens the title column',
            text: 'Supporting copy that occupies the right-hand column of the grid.',
            eyebrow: 'BETA',
            button_text: 'Get started',
            button_url: '/start',
          },
        },
      ]);

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.cta__inner')).toBeVisible({ timeout: 10000 });

      // "No overflow" is also true of a page where the rule under test never applied at
      // all — if the component stopped emitting id="home-cta", or the media query moved,
      // the grid would quietly fall back to the flex column and every assertion below
      // would still pass while guarding nothing. Prove the two-column grid is live first.
      const tracks = await page.evaluate(
        () => getComputedStyle(document.querySelector('.cta__inner')!).gridTemplateColumns,
      );
      expect(tracks.split(/\s+/).filter(Boolean)).toHaveLength(2);

      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      expect(scrollWidth).toBeLessThanOrEqual(width + 1);
    });
  }

  /*
   * The fix floors column 1 at 0 (`minmax(0, 2fr)`), which only lets the track shrink
   * because base.css declares `overflow-wrap: break-word` — that collapses the title's
   * min-content size. A grid item's `min-width: auto` otherwise resolves to min-content
   * and holds the track open regardless of the 0 floor, which would bring the sideways
   * scroll straight back for any headline containing a long unbreakable word.
   *
   * That dependency is invisible in the CTA's own rules, so pin the behavior rather than
   * the declaration: a headline that cannot wrap at a space must still not scroll the
   * page. Guards a future edit to base.css, not just to this component.
   */
  test('#258 a long unbreakable headline word still does not scroll the page sideways', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Overflow Long Word');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'inline',
          title: 'Internationalisierungszusammenarbeitsvereinbarung',
          text: 'Supporting copy that occupies the right-hand column of the grid.',
          button_text: 'Get started',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 768, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.cta__inner')).toBeVisible({ timeout: 10000 });

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidth).toBeLessThanOrEqual(769);
  });

  /*
   * Column 2's floor IS the button's min-width, which is the only reason the button can
   * never overflow its own track. (The exact value is derived from the stylesheet by the
   * css-lint pin rather than restated here, so the two cannot drift apart.) That holds
   * because the button wraps its label (`white-space: normal`) instead of growing past
   * the floor. A `white-space: nowrap` added to .cta__button would make a long label
   * widen the track and reopen the overflow, with every other pin here still green.
   */
  test('#258 a long button label wraps instead of widening the action column', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Overflow Long Button');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'inline',
          title: 'A deliberately long closing headline that widens the title column',
          text: 'Supporting copy that occupies the right-hand column of the grid.',
          button_text: 'Start your free 30-day trial now, no card required',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 768, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const button = page.locator('.cta__button');
    await expect(button).toBeVisible({ timeout: 10000 });

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidth).toBeLessThanOrEqual(769);

    // The button stayed inside .cta__inner's content box rather than pushing through it.
    const buttonBox = (await button.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;
    expect(buttonBox.x + buttonBox.width).toBeLessThanOrEqual(innerBox.x + innerBox.width + 1);
  });
});
