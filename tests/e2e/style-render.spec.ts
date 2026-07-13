import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

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
async function styleComponent(
  page: any,
  postId: number,
  style: Record<string, unknown>,
  recipe?: string,
) {
  return page.evaluate(
    async (args: { pid: number; style: Record<string, unknown>; recipe?: string }) => {
      const config = (window as any).ppAiChat;
      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'style_component');
      data.append('params[post_id]', String(args.pid));
      data.append('params[component_index]', '0');
      if (Object.keys(args.style).length > 0) {
        data.append('params[style]', JSON.stringify(args.style));
      }
      if (args.recipe) {
        data.append('params[recipe]', args.recipe);
      }
      const resp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
      });
      return resp.json();
    },
    { pid: postId, style, recipe },
  );
}

/** Computed featured-treatment surfaces of one grid card (issue 293). */
function grabCardStyles(el: Element) {
  const before = getComputedStyle(el, '::before');
  const s = getComputedStyle(el);
  return {
    barHeight: before.height,
    barImage: before.backgroundImage,
    barColor: before.backgroundColor,
    shadow: s.boxShadow,
    bg: s.backgroundImage,
    border: s.borderTopColor,
  };
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

  // Same scope failure as #255, one row down. `#home-cta .cta__body { align-self: end }`
  // and `#home-cta .cta__button { align-self: start }` are grid-cross-axis placement for
  // the inline layout, but they were declared bare. `.cta__inner` is a flex COLUMN in the
  // default full-width layout, where the cross axis is horizontal — so align-self:end
  // shoved the body to the right edge and align-self:start shoved the button to the left,
  // while the eyebrow and title stayed centered. The fix scopes both to `.cta--inline`; in
  // full-width the body falls back to `.cta--full-width .cta__inner`'s align-items:center
  // and the button to the shared four-CTA rule's align-self:center. Both end up centered.
  test('#257 the full-width cta centers its body and button (fix stays scoped) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Full Width Centering');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'full-width',
          title: 'A deliberately long closing headline for the full width layout',
          text: 'Supporting copy that sits below the headline in the full-width layout.',
          button_text: 'Get started',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const body = page.locator('.cta__body');
    const button = page.locator('.cta__button');
    await expect(body).toBeVisible({ timeout: 10000 });
    await expect(button).toBeVisible();

    const bodyBox = (await body.boundingBox())!;
    const buttonBox = (await button.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;
    const innerCenter = innerBox.x + innerBox.width / 2;

    // Both boxes are narrower than .cta__inner (body caps at 22rem, the button at its
    // fit-content width), so "centered" is a real constraint: the regression renders the
    // body hard against the right edge (x+width ~ inner right) and the button hard against
    // the left (x ~ inner left). Assert the centers line up instead.
    const bodyCenter = bodyBox.x + bodyBox.width / 2;
    const buttonCenter = buttonBox.x + buttonBox.width / 2;
    expect(Math.abs(bodyCenter - innerCenter)).toBeLessThan(2);
    expect(Math.abs(buttonCenter - innerCenter)).toBeLessThan(2);
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

  // All four inline CTA ids share the same overflow class. #home-cta is sized by its own
  // override (#258); #how-cta / #agencies-cta / #implementers-cta are sized by the shared
  // four-CTA rule, which had the same fixed floors and scrolled the page sideways at
  // tablet widths (#265). Parametrize over every id so the shared rule is proven too, not
  // just the home override.
  const ctaOverflowIds = ['home-cta', 'how-cta', 'agencies-cta', 'implementers-cta'];

  for (const id of ctaOverflowIds) {
    for (const width of ctaOverflowViewports) {
      // One @smoke case per id at the worst-overflowing width, so the post-merge main run
      // (which executes only the @smoke subset) still watches for the sideways scroll on
      // every inline CTA, not only #home-cta.
      const smoke = width === 768 ? ' @smoke' : '';

      test(`#265 the #${id} inline cta does not scroll the page sideways at ${width}px${smoke}`, async ({
        page,
      }) => {
        pageId = createPage(`E2E CTA Overflow ${id} ${width}`);
        setComposition(pageId, [
          {
            component: 'cta',
            props: {
              // The overflowing track override / shared rule is scoped to these ids.
              id,
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
        // all — if the component stopped emitting the id, or the media query moved, the
        // grid would quietly fall back to the flex column and every assertion below would
        // still pass while guarding nothing. Prove the two-column grid is live first.
        const tracks = await page.evaluate(
          () => getComputedStyle(document.querySelector('.cta__inner')!).gridTemplateColumns,
        );
        expect(tracks.split(/\s+/).filter(Boolean)).toHaveLength(2);

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(scrollWidth).toBeLessThanOrEqual(width + 1);
      });
    }
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

  /*
   * #305 — slot-contract rendered proof, one pin per dead-slot axis that shipped.
   *
   * The static guard (StyleSlotContractTest) proves every consumed slot survives the
   * stylesheet TEXT; only getComputedStyle after the REAL style_component action proves
   * the browser renders the value once specificity, media queries, and parent
   * constraints all apply. Each pin below targets the exact surface of a shipped
   * incident, at the 1280px desktop breakpoint where the premium rules that killed
   * them live. Pre-#302/#292 each of these assertions fails with the action still
   * reporting success — the trust breach #305 exists to make impossible.
   */

  // Padding axis (#302): the premium clamp() re-declaration used to beat the slot.
  test('#305 section honors --section-padding-top at 1280px desktop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Padding Slot');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Slot contract',
          body: '<p>Padding must be controllable per instance.</p>',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A pixel value no token resolves to, so a premium clamp() clobber is unmistakable.
    const res = await styleComponent(page, pageId, { '--section-padding-top': '77px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const section = page.locator('main > .section');
    await expect(section).toBeVisible({ timeout: 10000 });

    const paddingTop = await section.evaluate((el) => getComputedStyle(el).paddingTop);
    expect(paddingTop).toBe('77px');
  });

  // Type-scale axis (#302): the shared premium heading rule used to beat the slot.
  test('#305 grid heading honors --grid-heading-size at 1280px desktop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Heading Size Slot');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Scale is controllable',
          items: [{ title: 'One', text: 'First' }],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--grid-heading-size': '41px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const heading = page.locator('.grid__heading');
    await expect(heading).toBeVisible({ timeout: 10000 });

    const fontSize = await heading.evaluate((el) => getComputedStyle(el).fontSize);
    expect(fontSize).toBe('41px');
  });

  // Card-border axis (#226/#292): the featured first card (#226) AND cards 2..N (#292)
  // each had their own bypass, fixed separately — so assert BOTH boxes render the slot.
  test('#305 grid cards honor --grid-card-border on featured AND non-featured cards @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Card Border Slot');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Cards are controllable',
          items: [
            { title: 'One', text: 'Featured card, the #226 surface' },
            { title: 'Two', text: 'Non-featured card, the #292 surface' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A vivid color no token uses; both accent and neutral fallbacks differ from it.
    const res = await styleComponent(page, pageId, { '--grid-card-border': '#ff0080' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const cards = page.locator('.grid__item');
    await expect(cards).toHaveCount(2, { timeout: 10000 });

    // Color alone is not proof: getComputedStyle reports borderTopColor even when
    // width is 0 / style is none, so a future border-style/width clobber would
    // render no border while a color-only assertion stayed green. Pin all three.
    const featured = await cards.nth(0).evaluate((el) => {
      const s = getComputedStyle(el);
      return { color: s.borderTopColor, width: s.borderTopWidth, style: s.borderTopStyle };
    });
    const plain = await cards.nth(1).evaluate((el) => {
      const s = getComputedStyle(el);
      return { color: s.borderTopColor, width: s.borderTopWidth, style: s.borderTopStyle };
    });

    expect(featured.color).toBe('rgb(255, 0, 128)'); // #226 surface
    expect(plain.color).toBe('rgb(255, 0, 128)'); // #292 surface
    expect(featured.width).not.toBe('0px');
    expect(plain.width).not.toBe('0px');
    expect(featured.style).not.toBe('none');
    expect(plain.style).not.toBe('none');
  });

  // Featured remnants (#293), half 1: the rule move into the COMPONENT: grid block
  // must not change UNSET rendering. Pin the featured defaults at the computed level:
  // 4px accent bar + inset glow on card 1, 2px hairline + no inset glow on card 2.
  test('#293 unset grid keeps the featured first-card defaults after the rule move', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Featured Defaults');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Featured defaults survive',
          items: [
            { title: 'One', text: 'Featured card' },
            { title: 'Two', text: 'Plain card' },
          ],
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const cards = page.locator('.grid__item');
    await expect(cards).toHaveCount(2, { timeout: 10000 });

    const featured = await cards.nth(0).evaluate(grabCardStyles);
    const plain = await cards.nth(1).evaluate(grabCardStyles);

    expect(featured.barHeight).toBe('4px');
    expect(featured.barImage).toContain('linear-gradient'); // accent gradient bar
    expect(plain.barHeight).toBe('2px');
    expect(plain.barImage).toBe('none'); // hairline is a background-color, not an image
    expect(featured.shadow).toContain('inset'); // the blue glow's inset ring
    expect(plain.shadow).not.toContain('inset');
    expect(featured.bg).toContain('37, 99, 235'); // texture stripe literal
  });

  // Featured remnants (#293), half 2: the acceptance path, through the documented
  // uniform-cards RECIPE (so the recipe expansion is exercised end-to-end, not a
  // re-typed copy of its values) — including at a mobile width, where a separate
  // featured-glow rule re-declares the shadow chain (the featured-shadow slot would
  // otherwise silently no-op below 768px).
  test('#293 uniform-cards recipe neutralizes the featured treatment @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Uniform Row');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Uniform card row',
          items: [
            { title: 'One', text: 'First' },
            { title: 'Two', text: 'Second' },
            { title: 'Three', text: 'Third' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, {}, 'uniform-cards');
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const cards = page.locator('.grid__item');
    await expect(cards).toHaveCount(3, { timeout: 10000 });

    const featured = await cards.nth(0).evaluate(grabCardStyles);
    const plain = await cards.nth(1).evaluate(grabCardStyles);

    expect(featured.barHeight).toBe('0px'); // bar removed
    expect(plain.barHeight).toBe('0px');
    expect(featured.shadow).toBe(plain.shadow); // one shared shadow, no glow
    expect(featured.shadow).not.toContain('inset');
    expect(featured.border).toBe(plain.border); // accent-strong border neutralized
    expect(featured.bg).not.toContain('37, 99, 235'); // texture stripe neutralized

    // Mobile: the max-width 767px featured rule must route the same chain.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(cards).toHaveCount(3, { timeout: 10000 });

    const featuredMobile = await cards.nth(0).evaluate(grabCardStyles);
    const plainMobile = await cards.nth(1).evaluate(grabCardStyles);
    expect(featuredMobile.shadow).toBe(plainMobile.shadow);
    expect(featuredMobile.shadow).not.toContain('inset');
  });

  // #293: --grid-featured-shadow must have discriminating rendered power of its own.
  // The uniform-row test neutralizes via --grid-card-shadow, which the PRE-#293 CSS
  // already routed — it would pass with the featured-shadow chain reverted. This
  // test sets the featured slot to a distinctive value and proves it renders on the
  // featured card only, at desktop AND mobile (the two chain sites), and that it
  // outranks a simultaneously-set --grid-card-shadow.
  test('#293 --grid-featured-shadow renders on the featured card at both breakpoints', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Featured Shadow Slot');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Featured shadow slot',
          items: [
            { title: 'One', text: 'Featured card' },
            { title: 'Two', text: 'Plain card' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, {
      '--grid-featured-shadow': '0 2px 4px rgba(1, 2, 3, 0.5)',
      '--grid-card-shadow': '0 6px 12px rgba(7, 8, 9, 0.4)',
    });
    expect(res.success).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const cards = page.locator('.grid__item');
      await expect(cards).toHaveCount(2, { timeout: 10000 });

      const featured = await cards.nth(0).evaluate(grabCardStyles);
      const plain = await cards.nth(1).evaluate(grabCardStyles);
      expect(featured.shadow).toContain('1, 2, 3'); // featured slot wins on card 1
      expect(plain.shadow).toContain('7, 8, 9'); // shared slot on cards 2..N
      expect(plain.shadow).not.toContain('1, 2, 3');
    }
  });

  // #293: the shared bar slots must pin ONE identical bar on the featured and
  // plain cards simultaneously — the featured accent-gradient default has to be
  // overridden, not layered under.
  test('#293 bar slots pin an identical top bar on featured and plain cards', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Pinned Bar');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Pinned bar',
          items: [
            { title: 'One', text: 'Featured card' },
            { title: 'Two', text: 'Plain card' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, {
      '--grid-card-bar-color': 'rgb(9, 8, 7)',
      '--grid-card-bar-height': '3px',
    });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const cards = page.locator('.grid__item');
    await expect(cards).toHaveCount(2, { timeout: 10000 });

    const featured = await cards.nth(0).evaluate(grabCardStyles);
    const plain = await cards.nth(1).evaluate(grabCardStyles);

    expect(featured.barHeight).toBe('3px');
    expect(plain.barHeight).toBe('3px');
    expect(featured.barColor).toBe('rgb(9, 8, 7)');
    expect(plain.barColor).toBe('rgb(9, 8, 7)');
    expect(featured.barImage).toBe('none'); // gradient default overridden, not layered
    expect(plain.barImage).toBe('none');
  });

  // Parent-constrains-child axis (#302's --section-body-width): the pre-fix bug
  // was a literal max-width on the OUTER .section__body capping the slotted inner
  // .section__content — a shape the static guard's own docblock says no
  // same-subject textual scan can prove. This rendered pin is the layer that owns
  // it: if any ancestor cap returns, the inner box cannot reach the slot value.
  test('#305 section body honors --section-body-width past its wrapper at 1280px @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Body Width Slot');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Width is controllable',
          body: '<p>The body width slot must reach the rendered content box.</p>',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Wider than the 40rem (640px) wrapper default, so a re-introduced ancestor
    // cap fails this loudly instead of hiding inside the old limit.
    const res = await styleComponent(page, pageId, { '--section-body-width': '700px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const content = page.locator('.section__content');
    await expect(content).toBeVisible({ timeout: 10000 });

    const widths = await content.evaluate((el) => {
      const wrapper = el.closest('.section__body') as Element;
      return {
        content: getComputedStyle(el).maxWidth,
        wrapper: wrapper ? getComputedStyle(wrapper).maxWidth : null,
        rendered: el.getBoundingClientRect().width,
      };
    });

    // The wrapper must carry the slot value, and the rendered box must actually
    // exceed the old 640px wrapper default — the rendered proof, not just the
    // computed property. The INNER .section__content now honors the slot too:
    // issue 309 routed the text-only 49rem literal (main > .section--text-only
    // .section__content) through var(--section-body-width, 49rem), so the inner
    // content box that used to cap dead at 784px now follows the slot to 700px.
    expect(widths.content).toBe('700px');
    expect(widths.wrapper).toBe('700px');
    expect(widths.rendered).toBeGreaterThan(640);
  });

  test('#266 an unbreakable button label wraps instead of scrolling the page', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Overflow Unbreakable Button');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'home-cta',
          layout: 'inline',
          title: 'A deliberately long closing headline that widens the title column',
          text: 'Supporting copy that occupies the right-hand column of the grid.',
          // No spaces: the label cannot wrap at a word boundary, so without a
          // last-resort break it grows past its grid track and scrolls the page.
          button_text: 'StartYourFreeThirtyDayTrialNowNoCardRequiredToday',
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

    // The button wrapped inside .cta__inner's content box rather than widening past it.
    const buttonBox = (await button.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;
    expect(buttonBox.x + buttonBox.width).toBeLessThanOrEqual(innerBox.x + innerBox.width + 1);
  });

  /**
   * #332 — WP core's global stylesheet ships attribute-SUBSTRING selectors:
   *
   *   html :where([style*=border-width]){border-style:solid}
   *   html :where([style*=border-color]){border-style:solid}
   *
   * Our style slots render as inline CUSTOM PROPERTIES on the component root
   * (`style="--grid-card-border-width:0px"`). The substring lives in the property
   * NAME, so the selector matches the root — even when the value is 0 and the
   * border the slot controls actually lives on a DESCENDANT (the card). Roots that
   * declared no border of their own then computed core's injected `solid` at the
   * initial `medium` width: a 3px border nobody asked for. The 1.0-H dogfood hit
   * this on --grid-card-border-width and --section-panel-border-width and had to
   * abandon two documented slots.
   *
   * No static check over our own CSS can see this: our stylesheet is correct, the
   * slot is consumed, and the defect is contributed by a FOREIGN stylesheet at
   * runtime. Only a rendered box under real WP core CSS proves the immunity — the
   * same argument this file's header makes for #86/#24. The declaration-level half
   * (a new slot name embedding a trigger substring) is pinned statically in
   * StyleSlotContractTest::testBorderTriggerSlotsHaveCascadeImmunity.
   */
  // All 13 trigger slots, grouped by the component root that carries them inline.
  // Setting a component's FULL trigger set at once is the acceptance criterion:
  // "setting any of the 13 slots (including to 0) produces exactly the border the slot
  // specifies — no injected 3px border on the root."
  const BORDER_TRIGGER_CASES: {
    component: string;
    props: Record<string, unknown>;
    slots: Record<string, string>;
  }[] = [
    {
      component: 'grid',
      props: { id: 'pp-grid01', items: [{ title: 'One', text: 'First' }] },
      slots: { '--grid-card-border-width': '0px' },
    },
    {
      component: 'faq',
      props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] },
      slots: { '--faq-border-color': '#ff0080' },
    },
    {
      component: 'testimonials',
      props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] },
      slots: { '--testimonials-card-border-width': '0px' },
    },
    {
      component: 'cta',
      props: { id: 'pp-cta01', button_text: 'Go', button_url: '/go' },
      slots: { '--cta-border-width': '0px', '--cta-border-color': 'transparent' },
    },
    {
      component: 'section',
      props: { id: 'pp-sec01', body: '<p>Panel body.</p>' },
      slots: {
        '--section-border-width': '0px',
        '--section-border-color': 'transparent',
        '--section-panel-border-width': '0px',
        '--section-panel-border-color': 'transparent',
      },
    },
    {
      component: 'hero',
      props: { id: 'pp-hero01', title: 'Hero' },
      slots: {
        '--hero-border-width': '0px',
        '--hero-border-color': 'transparent',
        '--hero-surface-border-width': '0px',
        '--hero-surface-border-color': 'transparent',
      },
    },
  ];

  // Guard the guard. Derived from schema.json, NOT compared to a hardcoded count: a
  // count check can only fail if someone edits this same array, so it could not notice
  // a 14th border-trigger slot appearing in a schema — exactly the drift it exists to
  // catch (testing-specialist finding). Set-equality against the schemas can.
  test('#332 the rendered pins cover every border-trigger slot in schema.json', () => {
    // Same per-side-aware pattern as StyleSlotContractTest::WP_CORE_BORDER_TRIGGER_REGEX.
    const TRIGGER = /border(?:-(?:top|right|bottom|left))?-(?:width|color)/;
    const root = path.resolve(__dirname, '..', '..');

    const declared = new Set<string>();
    for (const dir of fs.readdirSync(path.join(root, 'components'))) {
      const schemaPath = path.join(root, 'components', dir, 'schema.json');
      if (!fs.existsSync(schemaPath)) continue;
      const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf-8'));
      for (const slot of Object.keys(schema?.styling?.style_slots ?? {})) {
        if (TRIGGER.test(slot)) declared.add(slot);
      }
    }

    const covered = new Set(BORDER_TRIGGER_CASES.flatMap((c) => Object.keys(c.slots)));

    // Fail-closed floor: 13 trigger slots existed at issue 332.
    expect(declared.size).toBeGreaterThanOrEqual(13);
    expect([...covered].sort()).toEqual([...declared].sort());
  });

  for (const c of BORDER_TRIGGER_CASES) {
    test(`#332 ${c.component}: border-trigger slots inject no core 3px border on the root`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Border Trigger ${c.component}`);
      setComposition(pageId, [{ component: c.component, props: c.props }]);

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

      const res = await styleComponent(page, pageId, c.slots);
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      // Scope to THIS component's root — a bare `.grid`/`.section` locator could match
      // chrome or a future template partial rather than the component under test.
      const root = page.locator(`[data-pp-component="${c.component}"]`);
      await expect(root).toBeVisible({ timeout: 10000 });

      // Non-vacuity floor: this pin is only meaningful while WP core actually ships the
      // substring trigger THIS case depends on. `--faq-border-color` rides the
      // border-color rule, the width slots ride the border-width rule — so assert the
      // triggers the case's own slot names imply, not a hardcoded one. If core ever drops
      // one, this fails loudly ("the immunity may be removable") instead of passing free.
      const triggers = [
        ...new Set(
          Object.keys(c.slots).map((s) => (s.includes('border-width') ? 'border-width' : 'border-color')),
        ),
      ];
      const missing = await page.evaluate((needed: string[]) => {
        const found: string[] = [];
        for (const sheet of Array.from(document.styleSheets)) {
          let rules: CSSRule[];
          try {
            rules = Array.from(sheet.cssRules ?? []);
          } catch {
            continue; // cross-origin sheet, not ours
          }
          for (const rule of rules) {
            const sel = (rule as CSSStyleRule).selectorText;
            if (!sel) continue;
            for (const n of needed) {
              // CSSOM re-serializes the attribute value WITH quotes: core's source form
              // `[style*=border-width]` reads back as `[style*="border-width"]`.
              if (new RegExp(`\\[style\\*=["']?${n}["']?\\]`).test(sel)) found.push(n);
            }
          }
        }
        return needed.filter((n) => !found.includes(n));
      }, triggers);
      expect(
        missing,
        `WP core no longer ships :where([style*=…]) for ${missing.join(', ')} — re-evaluate the #332 immunity baseline.`,
      ).toEqual([]);

      // The root asked for NO border. Every side must be 0 — pre-fix, the sides the
      // component did not declare computed to core's `solid` at `medium` (3px).
      const border = await root.evaluate((el) => {
        const s = getComputedStyle(el);
        return {
          top: s.borderTopWidth,
          right: s.borderRightWidth,
          bottom: s.borderBottomWidth,
          left: s.borderLeftWidth,
        };
      });
      expect(border).toEqual({ top: '0px', right: '0px', bottom: '0px', left: '0px' });
    });
  }

  // The OTHER inline-slot surface: issue 306's per-card style renders the custom property
  // on the .grid__item itself (components/grid/grid.php), so core's [style*=border-width]
  // matches the CARD, not the root. That is the second half of the immunity baseline and it
  // had no rendered coverage at all (adversarial-review finding 5) — deleting `.grid__item`
  // from the baseline broke no test. Per-card style is set through the composition, not
  // style_component (which is component-scoped).
  test('#332 a per-card border-trigger slot injects no core 3px border on the card', async ({
    page,
  }) => {
    pageId = createPage('E2E Border Trigger Grid Per-Card');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          items: [
            { title: 'One', text: 'First', style: { '--grid-card-border-width': '0px' } },
            { title: 'Two', text: 'Second' },
          ],
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const styledCard = page.locator('.grid__item').first();
    await expect(styledCard).toBeVisible({ timeout: 10000 });

    // The slot really is inline ON THE CARD — otherwise this pin proves nothing.
    const inline = await styledCard.evaluate((el) => el.getAttribute('style'));
    expect(inline).toContain('--grid-card-border-width');

    const border = await styledCard.evaluate((el) => {
      const s = getComputedStyle(el);
      return {
        top: s.borderTopWidth,
        right: s.borderRightWidth,
        bottom: s.borderBottomWidth,
        left: s.borderLeftWidth,
      };
    });
    expect(border).toEqual({ top: '0px', right: '0px', bottom: '0px', left: '0px' });

    // The sibling card, which carries no per-card style, keeps the 1px default.
    const plain = page.locator('.grid__item').nth(1);
    const plainWidth = await plain.evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(plainWidth).toBe('1px');
  });

  // Criterion 2 — "unset output is byte-identical to today". The immunity baseline sits
  // at the same (0,1,0) weight as the component rules, so a source-order slip would let
  // it erase the borders components legitimately draw. With NO slot set, the default 1px
  // card border must still render. (Codex outside-voice finding: the 0px pins alone
  // cannot tell "slot honored" apart from "baseline killed every border".)
  test('#332 an unstyled grid still renders its default 1px card border', async ({ page }) => {
    pageId = createPage('E2E Border Trigger Grid Default');
    setComposition(pageId, [
      {
        component: 'grid',
        props: { id: 'pp-grid01', items: [{ title: 'One', text: 'First' }] },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const card = page.locator('.grid__item').first();
    await expect(card).toBeVisible({ timeout: 10000 });

    const border = await card.evaluate((el) => {
      const s = getComputedStyle(el);
      return { width: s.borderTopWidth, style: s.borderTopStyle };
    });
    expect(border).toEqual({ width: '1px', style: 'solid' });
  });

  // The slot must still DO its job — a fix that simply killed all borders would pass
  // the immunity pins above. The dogfood's actual intent: a borderless card.
  test('#332 --grid-card-border-width still reaches the card (0 = no card border)', async ({
    page,
  }) => {
    pageId = createPage('E2E Border Trigger Grid Card Intent');
    setComposition(pageId, [
      {
        component: 'grid',
        props: { id: 'pp-grid01', items: [{ title: 'One', text: 'First' }] },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--grid-card-border-width': '0px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const card = page.locator('.grid__item').first();
    await expect(card).toBeVisible({ timeout: 10000 });

    const width = await card.evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(width).toBe('0px'); // slot honored: the card lost its default 1px
  });

  // Positive control: a non-zero border slot must still RENDER the border it asks for,
  // on exactly the sides the component declares (cta borders top/bottom only).
  test('#332 a non-zero --cta-border-width still renders on the declared sides', async ({
    page,
  }) => {
    pageId = createPage('E2E Border Trigger CTA Positive');
    setComposition(pageId, [
      {
        component: 'cta',
        props: { id: 'pp-cta01', button_text: 'Go', button_url: '/go' },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, {
      '--cta-border-width': '4px',
      '--cta-border-color': '#ff0080',
    });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const root = page.locator('.cta').first();
    await expect(root).toBeVisible({ timeout: 10000 });

    const border = await root.evaluate((el) => {
      const s = getComputedStyle(el);
      return {
        top: s.borderTopWidth,
        bottom: s.borderBottomWidth,
        left: s.borderLeftWidth,
        color: s.borderTopColor,
      };
    });
    // Top/bottom carry the slot; left/right stay off — the immunity baseline must not
    // suppress a border the operator actually asked for.
    expect(border.top).toBe('4px');
    expect(border.bottom).toBe('4px');
    expect(border.left).toBe('0px');
    expect(border.color).toBe('rgb(255, 0, 128)');
  });
});
