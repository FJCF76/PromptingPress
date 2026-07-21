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
 *   #412 — a full-width cta authored with an ORDINARY id must center its title/body/button
 *          in the BASE rules, not via a reserved demo id. (The former #255/#257/#258/#265
 *          CTA-grid pins tested demo-id decoration that issue 412 evicted; they were
 *          removed with the eviction and the css-lint ID guard forbids the ids' return.)
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
  componentIndex = 0,
) {
  return page.evaluate(
    async (args: {
      pid: number;
      style: Record<string, unknown>;
      recipe?: string;
      componentIndex: number;
    }) => {
      const config = (window as any).ppAiChat;

      // style_component is composition-mutating, so the chat execute endpoint now
      // requires a CAS baseline (#404). Read the page's current version first —
      // fresh each call, so repeated styling on one page never false-conflicts —
      // and thread it as expected_version, exactly as the real chat UI does.
      const baselineData = new FormData();
      baselineData.append('action', 'pp_ai_page_baseline');
      baselineData.append('nonce', config.executeNonce);
      baselineData.append('post_id', String(args.pid));
      const baselineResp = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: baselineData,
      });
      const baseline = await baselineResp.json();

      const data = new FormData();
      data.append('action', 'pp_ai_execute');
      data.append('nonce', config.executeNonce);
      data.append('type', 'action');
      data.append('name', 'style_component');
      data.append('params[post_id]', String(args.pid));
      data.append('params[component_index]', String(args.componentIndex));
      if (baseline && baseline.success && baseline.data) {
        data.append('params[expected_version]', String(baseline.data.version));
      }
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
    { pid: postId, style, recipe, componentIndex },
  );
}

/**
 * Where a flex row's CONTENT actually sits, versus the column it is supposed to align to
 * (issue 338).
 *
 * The row's own box proves nothing here: `.hero__proof` STRETCHES (its parent
 * `.hero__content` is a flex column that sets no align-items), so the box is already
 * centered in a centered hero while the content packs left inside it. Measuring
 * `boundingBox()` — what the #225 eyebrow pins do, because there the box IS the bug —
 * would pass on the broken CSS.
 *
 * Content is measured with a Range over the row's contents rather than a child locator:
 * the operator's proof is arbitrary wp_kses_post HTML and is usually a BARE TEXT RUN,
 * which becomes an anonymous flex item with no element to select. Union the fragment
 * rects from getClientRects() (not getBoundingClientRect, whose single rect is the one a
 * browser could legitimately report at line-box width) so what is measured is where the
 * glyphs landed.
 *
 * The reference is the CONTENT COLUMN, not the row's own box: that is the centerline the
 * reader perceives, and it stays the right question even if a future change shrink-wraps
 * the row.
 */
function measureRowContent(el: Element) {
  const range = document.createRange();
  range.selectNodeContents(el);
  const rects = Array.from(range.getClientRects()).filter((r) => r.width > 0 && r.height > 0);
  // rectCount is reported so callers can prove the Range measured SOMETHING. Math.min of an
  // empty list is Infinity, so an unrendered row would produce contentWidth === -Infinity —
  // which silently SATISFIES a "content is narrower than the column" floor. The guard against
  // a vacuous pin needs its own guard.
  const left = Math.min(...rects.map((r) => r.left));
  const right = Math.max(...rects.map((r) => r.right));

  // The reference is the column's CONTENT box, not its border box: .hero__content has neither
  // padding nor border, but .hero__surface (the split proof's parent) carries both, and
  // measuring against its border box would read its 32px padding as a 32px misalignment.
  // Split renders the proof inside .hero__surface instead of .hero__content.
  const column = (el.closest('.hero__content') ?? el.parentElement) as Element;
  const box = column.getBoundingClientRect();
  const cs = getComputedStyle(column);
  const padLeft = parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
  const padRight = parseFloat(cs.paddingRight) + parseFloat(cs.borderRightWidth);
  const columnLeft = box.left + padLeft;
  const columnWidth = box.width - padLeft - padRight;

  // Per-FLEX-LINE boxes, for wrapped rows. justify-content packs each line independently, so
  // a wrapped row's union rect (above) cannot see which line is misplaced. Children sharing a
  // top edge are on the same line. Empty for a bare-text proof, which has no element children.
  const byTop = new Map<number, { left: number; right: number }>();
  for (const child of Array.from(el.children)) {
    const r = child.getBoundingClientRect();
    if (r.width === 0) continue;
    const key = Math.round(r.top);
    const cur = byTop.get(key);
    if (cur) {
      cur.left = Math.min(cur.left, r.left);
      cur.right = Math.max(cur.right, r.right);
    } else {
      byTop.set(key, { left: r.left, right: r.right });
    }
  }

  return {
    rectCount: rects.length,
    contentLeft: left,
    contentWidth: right - left,
    contentCenter: (left + right) / 2,
    columnLeft,
    columnWidth,
    columnCenter: columnLeft + columnWidth / 2,
    justifyContent: getComputedStyle(el).justifyContent,
    lines: [...byTop.entries()]
      .sort((a, b) => a[0] - b[0])
      .map(([, v]) => ({ left: v.left, width: v.right - v.left, center: (v.left + v.right) / 2 })),
  };
}

// Sub-pixel layout noise, not a meaningful offset. The bug this file guards misplaces content
// by hundreds of pixels, so a 2px window fails on the regression without pinning exact metrics.
const ALIGN_TOLERANCE_PX = 2;
// "Centered" and "left" are only DIFFERENT questions while the content is narrower than the
// column it sits in. A row that fills its column reads the same under either alignment, so any
// pin on it would pass on any justify-content. Rows must clear this bar to be worth asserting.
const NON_VACUITY_MAX_FILL = 0.9;

/** One measured box (content run, flex line, or button) against the column it aligns to. */
type AlignedBox = { left: number; width: number; center: number };
type ColumnBox = { columnLeft: number; columnWidth: number; columnCenter: number };

function expectBoxAligned(box: AlignedBox, column: ColumnBox, align: 'start' | 'center') {
  expect(box.width).toBeGreaterThan(0);
  expect(box.width).toBeLessThan(column.columnWidth * NON_VACUITY_MAX_FILL);

  if (align === 'center') {
    // The bug: the words sat flush left inside a perfectly centered box.
    expect(Math.abs(box.center - column.columnCenter)).toBeLessThan(ALIGN_TOLERANCE_PX);
  } else {
    expect(Math.abs(box.left - column.columnLeft)).toBeLessThan(ALIGN_TOLERANCE_PX);
  }
}

/** Assert a measured row is real (see measureRowContent) and aligned as the layout intends. */
function expectRowAligned(m: ReturnType<typeof measureRowContent>, align: 'start' | 'center') {
  // The Range measured actual glyphs, not an empty box.
  expect(m.rectCount).toBeGreaterThan(0);
  expectBoxAligned(
    { left: m.contentLeft, width: m.contentWidth, center: m.contentCenter },
    m,
    align,
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

  // #442: the `theme` enum value must PREDICT the rendered band background. The static
  // schema/helper tests prove the class mapping; only getComputedStyle proves the CSS
  // cascade actually paints it. Seed one band per theme value and assert the computed
  // background-color matches the documented meaning:
  //   default  -> transparent (page background shows through)
  //   muted    -> --color-surface (#f4f7fb) — the LIGHT tinted band
  //   dark     -> IDENTICAL to muted (deprecated alias; existing pages unchanged)
  //   inverted -> --color-bg-inverted (#0f172a) — the genuinely dark band
  test('#442 theme values render the documented band background', async ({ page }) => {
    pageId = createPage('E2E Theme Band Backgrounds');
    setComposition(pageId, [
      { component: 'grid', props: { id: 'pp-theme-default', theme: 'default', items: [{ title: 'D', text: 'x' }] } },
      { component: 'grid', props: { id: 'pp-theme-muted', theme: 'muted', items: [{ title: 'M', text: 'x' }] } },
      { component: 'grid', props: { id: 'pp-theme-dark', theme: 'dark', items: [{ title: 'K', text: 'x' }] } },
      { component: 'grid', props: { id: 'pp-theme-inverted', theme: 'inverted', items: [{ title: 'I', text: 'x' }] } },
    ]);

    await page.goto(`/?page_id=${pageId}`);

    const bg = async (sel: string) => {
      const el = page.locator(sel);
      await expect(el).toBeVisible({ timeout: 10000 });
      return el.evaluate((n) => getComputedStyle(n).backgroundColor);
    };

    const SURFACE = 'rgb(244, 247, 251)'; // --color-surface #f4f7fb
    const INVERTED = 'rgb(15, 23, 42)';   // --color-bg-inverted #0f172a
    const TRANSPARENT = 'rgba(0, 0, 0, 0)';

    expect(await bg('#pp-theme-default')).toBe(TRANSPARENT);
    const muted = await bg('#pp-theme-muted');
    const dark = await bg('#pp-theme-dark');
    expect(muted).toBe(SURFACE);
    // The whole point of #442: `muted` is a LIGHT band, not dark.
    expect(muted).not.toBe(INVERTED);
    // The deprecated `dark` alias renders byte-identically to `muted`.
    expect(dark).toBe(muted);
    expect(await bg('#pp-theme-inverted')).toBe(INVERTED);
  });

  // #349: an explicit per-instance --grid-item-text-color must win over a text_role
  // color preset (.text-meta / .text-kicker) at BOTH breakpoints. The bug was a
  // breakpoint split: the role utility (utilities.css, enqueued after components.css)
  // is (0,1,0) and defeated the (0,1,0) base slot rule on the source-order tie below
  // 768px, while the (0,2,1) desktop premium rule out-specified it — so the slot was
  // honored on desktop and DEAD on mobile. A single-viewport pin would miss it exactly
  // as it shipped, so this asserts at 375px (mobile) AND 1280px (desktop).
  //
  // Four cards prove both halves in one render (per-item `style`, the #306 per-instance
  // path): cards 0/1 SET --grid-item-text-color and must render the slot colour at both
  // breakpoints (the fix); cards 2/3 leave it UNSET and must render byte-identically to
  // today — the role colour on mobile (--text-meta-color=--color-muted #5e6677 /
  // --text-kicker-color=--color-accent #3157f4), and the premium fallback
  // --color-text-secondary (#2d3648) on desktop, where the (0,2,1) rule still governs.
  // The unset assertions guard that the fix changed NO default colour.
  test('#349 explicit --grid-item-text-color beats text_role at both breakpoints @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Text Role Slot Precedence');
    const SLOT = '#ff0080'; // vivid, no token uses it — a leak or clobber is obvious
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Role vs slot',
          items: [
            { title: 'One', text: 'Set meta', text_role: 'meta', style: { '--grid-item-text-color': SLOT } },
            { title: 'Two', text: 'Set kicker', text_role: 'kicker', style: { '--grid-item-text-color': SLOT } },
            { title: 'Three', text: 'Unset meta', text_role: 'meta' },
            { title: 'Four', text: 'Unset kicker', text_role: 'kicker' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const textColor = (i: number) =>
      page.locator('.grid__item').nth(i).locator('.grid__item-text')
        .evaluate((el) => getComputedStyle(el).color);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.grid__item')).toHaveCount(4, { timeout: 10000 });

      // Set slot wins at BOTH breakpoints (mobile is the case that shipped broken).
      expect(await textColor(0)).toBe('rgb(255, 0, 128)'); // set + meta
      expect(await textColor(1)).toBe('rgb(255, 0, 128)'); // set + kicker

      // Unset output is byte-identical to today: role colour on mobile, premium
      // --color-text-secondary on desktop. No default colour changed.
      if (width >= 768) {
        expect(await textColor(2)).toBe('rgb(45, 54, 72)'); // unset meta -> --color-text-secondary
        expect(await textColor(3)).toBe('rgb(45, 54, 72)'); // unset kicker -> --color-text-secondary
      } else {
        expect(await textColor(2)).toBe('rgb(94, 102, 119)'); // unset meta -> --text-meta-color (--color-muted)
        expect(await textColor(3)).toBe('rgb(49, 87, 244)'); // unset kicker -> --text-kicker-color (--color-accent)
      }
    }
  });

  // #357: grid card content alignment is authorable via the `align`-typed
  // --grid-item-text-align slot. Default `left` is byte-identical to today; `center`
  // and `right` must actually MOVE the glyphs, not merely set a declaration. The
  // StyleSlotContractTest proves the CSS consumes var(--grid-item-text-align, left)
  // and GridItemStyleTest proves the inline var reaches the card; only a rendered box
  // proves the browser honors it. Per the #338 lesson (a flex container ignores
  // text-align for ITEM placement), the card body is a flex column, so we assert BOTH
  // the computed declaration AND the geometry of a card's title glyphs relative to the
  // body's content box — center card centered, right card flush right, unset card flush
  // left (the byte-identical default). Two viewports, per the #86/#349 mobile-hid-it
  // lesson. #361 extends this: the card's link/button follows the SAME slot value (via
  // the derived --pp-grid-link-align companion), so the link box is asserted to track
  // the text — a centered card is fully centered, unset stays left-pinned.
  test('#357/#361 grid card content + link honor --grid-item-text-align (center/right/left) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Item Text Align');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'Alignment',
          items: [
            { title: 'Center', text: 'hola', link_url: '/x', link_text: 'Reach us', style: { '--grid-item-text-align': 'center' } },
            { title: 'Right', text: 'hola', link_url: '/x', link_text: 'Reach us', style: { '--grid-item-text-align': 'right' } },
            { title: 'Unset', text: 'hola', link_url: '/x', link_text: 'Reach us' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Computed declaration on the card body (inherited by every content item).
    const bodyAlign = (i: number) =>
      page.locator('.grid__item').nth(i).locator('.grid__item-body')
        .evaluate((el) => getComputedStyle(el).textAlign);

    // Geometry: where a card title's GLYPHS actually land, relative to the body's
    // content box. Returns the fraction of leftover horizontal space that sits to the
    // LEFT of the glyph run (0 = flush left, ~0.5 = centered, ~1 = flush right). This is
    // the mutation-check: without the CSS rule the declaration would be `start` and all
    // three fractions collapse to ~0, so center/right assertions go red.
    const titleOffsetFraction = (i: number) =>
      page.locator('.grid__item').nth(i).locator('.grid__item-title')
        .evaluate((el) => {
          const range = document.createRange();
          range.selectNodeContents(el);
          const rects = Array.from(range.getClientRects()).filter((r) => r.width > 0 && r.height > 0);
          if (rects.length === 0) return -1; // guard against a vacuous measurement
          const glyphLeft = Math.min(...rects.map((r) => r.left));
          const glyphRight = Math.max(...rects.map((r) => r.right));
          const glyphWidth = glyphRight - glyphLeft;
          const body = el.parentElement as HTMLElement; // .grid__item-body
          const cs = getComputedStyle(body);
          const rect = body.getBoundingClientRect();
          const contentLeft = rect.left + parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
          const contentRight = rect.right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);
          const slack = (contentRight - contentLeft) - glyphWidth;
          if (slack <= 1) return -2; // title fills the column — geometry can't discriminate
          return (glyphLeft - contentLeft) / slack;
        });

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.grid__item')).toHaveCount(3, { timeout: 10000 });

      // Declaration: the slot value reaches the rendered body; unset falls back to left.
      expect(await bodyAlign(0)).toBe('center');
      expect(await bodyAlign(1)).toBe('right');
      expect(await bodyAlign(2)).toBe('left');

      // Geometry: the glyphs actually moved (this is what a declaration-only pin misses).
      // The sentinels -1 (nothing measured) / -2 (title fills the column) must never
      // reach the band asserts, so gate them first — otherwise a vacuous measurement
      // could satisfy the left-flush floor. Bands carry a subpixel tolerance: glyph ink
      // can sit a hair outside the content box (font hinting/letter-spacing), so left is
      // "near 0" and right is "near 1", not exact.
      const centerFrac = await titleOffsetFraction(0);
      const rightFrac = await titleOffsetFraction(1);
      const leftFrac = await titleOffsetFraction(2);
      for (const f of [centerFrac, rightFrac, leftFrac]) {
        expect(f, 'geometry measured a real glyph run (not a -1/-2 sentinel)').toBeGreaterThan(-0.5);
      }
      expect(centerFrac).toBeGreaterThan(0.3);
      expect(centerFrac).toBeLessThan(0.7);
      expect(rightFrac).toBeGreaterThan(0.85);
      expect(leftFrac).toBeLessThan(0.15);

      // #361: the link/button FOLLOWS the card's alignment. The "Read more" link is
      // a content-width flex item placed by align-self, so per the #338 flex trap
      // text-align cannot move it; grid.php derives a --pp-grid-link-align companion
      // from the same slot value so the link tracks the text. Measure the link BOX,
      // not just the computed property: fraction of the body's leftover horizontal
      // space that sits LEFT of the link box (0 = flush left, ~0.5 = centered, ~1 =
      // flush right). This is the mutation-check — delete the companion (or the CSS
      // var consumption) and center/right collapse to ~0, going red. Unset stays
      // flush-left (byte-identical to today), so this same pin guards the default.
      const linkOffsetFraction = (i: number) =>
        page.locator('.grid__item').nth(i).locator('.grid__item-link')
          .evaluate((a: HTMLElement) => {
            const body = a.closest('.grid__item-body') as HTMLElement;
            const cs = getComputedStyle(body);
            const rect = body.getBoundingClientRect();
            const contentLeft = rect.left + parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
            const contentRight = rect.right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);
            const link = a.getBoundingClientRect();
            const slack = (contentRight - contentLeft) - link.width;
            const alignSelf = getComputedStyle(a).alignSelf;
            if (slack <= 1) return { alignSelf, frac: -2 }; // link fills the column — can't discriminate
            return { alignSelf, frac: (link.left - contentLeft) / slack };
          });

      const centerLink = await linkOffsetFraction(0); // --grid-item-text-align: center
      const rightLink = await linkOffsetFraction(1);  // --grid-item-text-align: right
      const unsetLink = await linkOffsetFraction(2);  // unset — must stay left-pinned

      // Computed align-self reflects the derived companion (unset falls back to flex-start).
      expect(centerLink.alignSelf).toBe('center');
      expect(rightLink.alignSelf).toBe('flex-end');
      expect(unsetLink.alignSelf).toBe('flex-start');

      // Geometry: the link box actually moved (what a property-only pin misses). Gate
      // the -2 sentinel (link fills the column) so a vacuous read can't satisfy a band.
      for (const l of [centerLink, rightLink, unsetLink]) {
        expect(l.frac, 'link box has measurable slack in its column').toBeGreaterThan(-0.5);
      }
      expect(centerLink.frac).toBeGreaterThan(0.3);
      expect(centerLink.frac).toBeLessThan(0.7);
      expect(rightLink.frac).toBeGreaterThan(0.85);
      expect(unsetLink.frac).toBeLessThan(0.15); // byte-identical default: link hugs content-left
    }
  });

  // #467: heading letter-spacing is tokenized (--letter-spacing-heading, default
  // -0.03em). The static TypographyRoleTest proves the h1-h6 rule routes through the
  // token; only getComputedStyle proves the browser renders it once the cascade applies,
  // and that a :root override (the operator's real write path via update_design_token,
  // injected as an inline :root block after pp-base) actually changes heading tracking.
  //
  // A section renders its title as a real <h2 class="section__title">, and no component
  // rule sets its own letter-spacing, so the shared base rule governs it. Assert at 1280
  // AND 375 (the #86/#349 mobile-hid-it lesson) using the RATIO letter-spacing/font-size,
  // which is font-size-independent (em tracking is relative to the element's own size):
  //   unset  -> ratio ~ -0.03 (byte-identical default)
  //   set     -> ratio ~ the override, and the computed value actually changed.
  test('#467 headings honor --letter-spacing-heading; unset is byte-identical at both breakpoints @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Heading Letter Spacing Token');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec01', title: 'Heading tracking', body: '<p>Body copy.</p>' } },
    ]);

    // Ensure a clean :root (no stray token override from another run).
    execSync('npx wp-env run cli wp option delete pp_token_overrides', {
      cwd: process.cwd(),
      stdio: 'ignore',
    });

    const trackingRatio = () =>
      page.locator('main .section__title').first().evaluate((el) => {
        const cs = getComputedStyle(el);
        const font = parseFloat(cs.fontSize);
        const ls = parseFloat(cs.letterSpacing); // computed to px; "normal" -> NaN
        return { font, ls, ratio: ls / font };
      });

    try {
      // 1) UNSET: the default -0.03em renders on the heading at both breakpoints.
      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        await expect(page.locator('main .section__title')).toBeVisible({ timeout: 10000 });
        const unset = await trackingRatio();
        expect(unset.font).toBeGreaterThan(0);
        expect(unset.ls).toBeLessThan(0); // negative tracking, not "normal"
        expect(Math.abs(unset.ratio - -0.03)).toBeLessThan(0.005);
      }

      // 2) A :root override through the real design-token store changes heading tracking.
      // 0.25em is positive and distinctive, so a clobber or a dead token is unmistakable.
      execSync(
        `npx wp-env run cli wp option update pp_token_overrides '{"--letter-spacing-heading":"0.25em"}' --format=json`,
        { cwd: process.cwd(), stdio: 'ignore' },
      );

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        await expect(page.locator('main .section__title')).toBeVisible({ timeout: 10000 });
        const set = await trackingRatio();
        expect(set.ls).toBeGreaterThan(0); // flipped from negative — the override took
        expect(Math.abs(set.ratio - 0.25)).toBeLessThan(0.005);
      }
    } finally {
      execSync('npx wp-env run cli wp option delete pp_token_overrides', {
        cwd: process.cwd(),
        stdio: 'ignore',
      });
    }
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

  // #440: a `split` hero with no image and no proof has nothing for the second
  // column. The bug reserved an empty right half-band by keeping the two-column
  // split grid; the fix degrades the hero to the single-column `left` layout so
  // no empty column is reserved and the text is not squeezed into the left half.
  //
  // This is the computed-geometry test that would have caught the bug. The visible
  // defect is the reserved second column, which at >=1024px shows up as a real
  // second track in `.hero__inner`'s `grid-template-columns`. A raw content-width
  // ratio is NOT a clean signal here: the theme caps `.hero__content` at a 56rem
  // readability measure and, in `left`, sizes it to its content (align-items:
  // flex-start), so the degraded content is intentionally narrower than the band.
  // The unambiguous geometry is the track count: two tracks = reserved empty
  // column (bug), <=1 track = single-column (fixed).
  test('#440 image-less split hero reserves no empty second column (degrades to single column)', async ({ page }) => {
    pageId = createPage('E2E Hero Split No Media');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'split',
          title: 'A deliberately long hero headline that would be squeezed in a half-band column',
          subtitle: 'Split was chosen before media was imported.',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const hero = page.locator('.hero');
    await expect(hero).toBeVisible({ timeout: 10000 });
    // Degraded to the single-column layout: the split class must be gone.
    await expect(hero).toHaveClass(/hero--left/);

    const geom = await page.evaluate(() => {
      const inner = document.querySelector('.hero__inner') as HTMLElement;
      const cs = getComputedStyle(inner);
      const cols = cs.gridTemplateColumns; // e.g. "none" or "570px 480px"
      return {
        display: cs.display,
        gridTemplateColumns: cols,
        trackCount: cols === 'none' ? 0 : cols.trim().split(/\s+/).length,
      };
    });

    // No reserved second column: at 1280w the broken split renders `.hero__inner`
    // as a two-track grid; the degraded single-column layout has at most one track.
    expect(geom.trackCount).toBeLessThanOrEqual(1);
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

  // Symptom 2 (issue 412): a full-width CTA authored with an ORDINARY id must center its
  // title/body/button in the BASE rules — the centering must not depend on a reserved
  // demo id. Before the eviction, `.cta--full-width .cta__inner` centered its children and
  // capped `.cta__title`/`.cta__body` at --cta-content-width, but the `.cta__text` wrapper
  // had no width constraint: a long body stretched it to the full inner width, so the
  // capped title left-pinned inside it (text-align only centers the glyphs WITHIN that
  // left-pinned box). The base `.cta--full-width .cta__text { max-width: var(--cta-content-width,
  // 40rem); margin-inline: auto }` rule fixes it for every id. Use a normal authored id
  // (never one of the evicted demo ids) so this proves the BASE behavior, not decoration.
  test('#412 a full-width cta with a normal id centers title/body/button in the base rules @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Full Width Centering');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'inicio-analisis',
          layout: 'full-width',
          title: 'A deliberately long closing headline for the full width layout',
          text: 'Supporting copy that sits below the headline in the full-width layout.',
          button_text: 'Get started',
          button_url: '/start',
        },
      },
    ]);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const title = page.locator('.cta__title');
    const body = page.locator('.cta__body');
    const button = page.locator('.cta__button');
    await expect(title).toBeVisible({ timeout: 10000 });
    await expect(body).toBeVisible();
    await expect(button).toBeVisible();

    const titleBox = (await title.boundingBox())!;
    const bodyBox = (await body.boundingBox())!;
    const buttonBox = (await button.boundingBox())!;
    const innerBox = (await page.locator('.cta__inner').boundingBox())!;
    const innerCenter = innerBox.x + innerBox.width / 2;

    // The measured symptom: at 1440px the title box was 640px pinned LEFT inside a
    // full-width .cta__text (176px left gap / 624px right gap). Assert every box is
    // centered on .cta__inner instead — a left-pinned title fails loudly (its center sits
    // hundreds of px left of the inner center). 2px of slack for sub-pixel layout.
    const titleCenter = titleBox.x + titleBox.width / 2;
    const bodyCenter = bodyBox.x + bodyBox.width / 2;
    const buttonCenter = buttonBox.x + buttonBox.width / 2;
    expect(Math.abs(titleCenter - innerCenter)).toBeLessThan(2);
    expect(Math.abs(bodyCenter - innerCenter)).toBeLessThan(2);
    expect(Math.abs(buttonCenter - innerCenter)).toBeLessThan(2);
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

  // Header-rhythm axis (#352): faq's heading->list gap is authorable via
  // --faq-heading-margin-bottom, the faq analogue of #343's section/grid
  // title->subheading slot (faq renders no subheading, so the slot governs the gap
  // before the accordion list). faq re-declares this margin in THREE places — the
  // base rule plus the desktop (>=768px) and mobile (<768px) premium rules — so a
  // single-viewport pin could pass while the other breakpoint's literal still
  // clobbered the slot (the #86/#349 mobile-hid-it lesson). Assert at 1280 (desktop
  // rule, 1.65rem fallback) AND 375 (mobile rule, 1.25rem fallback). Two faq
  // instances in one render prove both halves: index 0 SETS the slot and must win at
  // both breakpoints; index 1 leaves it UNSET and must compute today's literal
  // (26.4px desktop / 20px mobile) — the byte-identical-unset guard.
  test('#352 faq honors --faq-heading-margin-bottom at both breakpoints, unset unchanged @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E FAQ Heading Margin Slot');
    setComposition(pageId, [
      { component: 'faq', props: { id: 'pp-faq01', title: 'Set gap', items: [{ question: 'Q?', answer: 'A.' }] } },
      { component: 'faq', props: { id: 'pp-faq02', title: 'Unset gap', items: [{ question: 'Q?', answer: 'A.' }] } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A pixel value no token resolves to, so a premium-rule clobber is unmistakable.
    const res = await styleComponent(page, pageId, { '--faq-heading-margin-bottom': '48px' }, undefined, 0);
    expect(res.success).toBe(true);

    const marginBottom = (id: string) =>
      page.locator(`#${id} .faq__heading`).evaluate((el) => getComputedStyle(el).marginBottom);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-faq01 .faq__heading')).toBeVisible({ timeout: 10000 });

      // Set slot wins at BOTH breakpoints (mobile is the case that ships broken when a
      // media-query literal is left un-routed through the slot).
      expect(await marginBottom('pp-faq01')).toBe('48px');

      // Unset output byte-identical to today: 1.65rem (26.4px) desktop, 1.25rem (20px)
      // mobile. No default changed.
      expect(await marginBottom('pp-faq02')).toBe(width >= 768 ? '26.4px' : '20px');
    }
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

  // #226: the `card_emphasis: uniform` PROP opts the first card out of the entire
  // featured treatment so a symmetric/peer card row renders equal cards. This is the
  // measured 1.0-H symptom: the featured first card's body padding-top (2.25rem) +
  // larger title pushed card 0's checklist ~36px below its peers, so three spec
  // cards could not line up. Unlike the uniform-cards RECIPE (slot-only, which can
  // neutralize the bar/texture/glow but NOT the :first-child padding-top or title
  // size), the prop drops every featured :first-child rule via :not(.grid--uniform),
  // so card 0 falls through to the shared all-cards rules and equals its siblings.
  // Two grids on one page: `uniform` (all cards equal) and `featured` (the default,
  // which MUST still emphasize card 0 — the byte-identical / mutation guard). On the
  // pre-#226 CSS the uniform grid's card 0 stays featured and the equality pins fail.
  test('#226 card_emphasis:uniform equalizes the first card; featured stays emphasized @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Card Emphasis Uniform');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid-uniform',
          card_emphasis: 'uniform',
          title: 'Uniform spec row',
          items: [
            { title: 'Método de análisis', text: 'First' },
            { title: 'Datos y privacidad', text: 'Second' },
            { title: 'Compatibilidad', text: 'Third' },
          ],
        },
      },
      {
        component: 'grid',
        props: {
          id: 'pp-grid-featured',
          title: 'Featured row',
          items: [
            { title: 'Lead', text: 'First' },
            { title: 'Two', text: 'Second' },
            { title: 'Three', text: 'Third' },
          ],
        },
      },
    ]);

    // Desktop: the padding-top (min-width:1024px) and larger-title rules that create
    // the first-card asymmetry live here, so the symptom only manifests at >=1024px.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    // Grab the metrics that make card 0 DIFFERENT from its siblings under the
    // featured treatment: body padding-top (the 36px push), title font-size, the
    // ::before accent bar, plus the shared surface fields from grabCardStyles.
    const cardMetrics = (sel: string) =>
      page.locator(sel).evaluate((el) => {
        const body = el.querySelector('.grid__item-body') as HTMLElement;
        const title = el.querySelector('.grid__item-title') as HTMLElement;
        const before = getComputedStyle(el, '::before');
        return {
          padTop: getComputedStyle(body).paddingTop,
          titleSize: getComputedStyle(title).fontSize,
          barHeight: before.height,
          barImage: before.backgroundImage,
          shadow: getComputedStyle(el).boxShadow,
          border: getComputedStyle(el).borderTopColor,
        };
      });

    await expect(page.locator('#pp-grid-uniform .grid__item')).toHaveCount(3, {
      timeout: 10000,
    });

    // ── Uniform grid: card 0 is identical to its siblings ──
    const uFirst = await cardMetrics('#pp-grid-uniform .grid__item:nth-child(1)');
    const uSib = await cardMetrics('#pp-grid-uniform .grid__item:nth-child(2)');

    expect(uFirst.padTop).toBe(uSib.padTop); // the 36px offset is gone (THE symptom)
    expect(uFirst.titleSize).toBe(uSib.titleSize); // no larger featured title
    expect(uFirst.barHeight).toBe(uSib.barHeight); // hairline, not the 4px accent bar
    expect(uFirst.barImage).toBe(uSib.barImage); // no accent gradient bar (both 'none')
    expect(uFirst.barImage).toBe('none');
    expect(uFirst.shadow).toBe(uSib.shadow); // shared shadow, no blue glow
    expect(uFirst.shadow).not.toContain('inset');
    expect(uFirst.border).toBe(uSib.border); // no accent border

    // ── Featured grid (default): card 0 MUST still be emphasized ──
    // Proves the guard neutralizes emphasis ONLY under .grid--uniform, and that the
    // default remains byte-identical to the historical featured treatment.
    const fFirst = await cardMetrics('#pp-grid-featured .grid__item:nth-child(1)');
    const fSib = await cardMetrics('#pp-grid-featured .grid__item:nth-child(2)');

    expect(fFirst.padTop).not.toBe(fSib.padTop); // featured card 0 sits lower
    expect(fFirst.titleSize).not.toBe(fSib.titleSize); // featured card 0 title larger
    expect(fFirst.barHeight).toBe('4px'); // the accent bar
    expect(fSib.barHeight).toBe('2px');
    expect(fFirst.barImage).toContain('linear-gradient'); // accent gradient bar
    expect(fFirst.shadow).toContain('inset'); // the blue glow ring

    // Mobile (<768px): a separate featured-shadow rule (the max-width:767px block)
    // also carries the :not(.grid--uniform) guard, so the featured glow must be
    // dropped there too. Pin card 0 == its sibling under uniform, and the featured
    // grid keeping its glow, at this second chain site.
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-grid-uniform .grid__item')).toHaveCount(3, {
      timeout: 10000,
    });

    // The mobile featured glow is a blue-tinted (37,99,235) drop shadow, not the
    // desktop inset ring; siblings/uniform cards get the neutral (15,23,42) shadow.
    const uFirstM = await cardMetrics('#pp-grid-uniform .grid__item:nth-child(1)');
    const uSibM = await cardMetrics('#pp-grid-uniform .grid__item:nth-child(2)');
    expect(uFirstM.shadow).toBe(uSibM.shadow); // no featured glow on mobile
    expect(uFirstM.shadow).not.toContain('37, 99, 235'); // not the blue featured glow

    const fFirstM = await cardMetrics('#pp-grid-featured .grid__item:nth-child(1)');
    expect(fFirstM.shadow).toContain('37, 99, 235'); // featured grid keeps its glow on mobile
  });

  // #226: the featured treatment includes a dark-theme lift (translateY on card 0 at
  // >=768px). A partial opt-out that neutralized padding/bar but left the lift would
  // still misalign a dark uniform row, so card_emphasis:uniform must drop it too.
  test('#226 card_emphasis:uniform neutralizes the dark-theme first-card lift', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Card Emphasis Dark Lift');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid-dark-uniform',
          theme: 'dark',
          card_emphasis: 'uniform',
          title: 'Dark uniform',
          items: [
            { title: 'One', text: 'First' },
            { title: 'Two', text: 'Second' },
          ],
        },
      },
      {
        component: 'grid',
        props: {
          id: 'pp-grid-dark-featured',
          theme: 'dark',
          title: 'Dark featured',
          items: [
            { title: 'One', text: 'First' },
            { title: 'Two', text: 'Second' },
          ],
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const transformOf = (sel: string) =>
      page.locator(sel).evaluate((el) => getComputedStyle(el).transform);

    await expect(page.locator('#pp-grid-dark-uniform .grid__item')).toHaveCount(2, {
      timeout: 10000,
    });

    // Uniform: card 0 has NO lift — same transform as its sibling.
    const duFirst = await transformOf('#pp-grid-dark-uniform .grid__item:nth-child(1)');
    const duSib = await transformOf('#pp-grid-dark-uniform .grid__item:nth-child(2)');
    expect(duFirst).toBe(duSib);
    expect(duFirst).toBe('none');

    // Featured (default): card 0 IS lifted — a real transform, unlike its sibling.
    const dfFirst = await transformOf('#pp-grid-dark-featured .grid__item:nth-child(1)');
    const dfSib = await transformOf('#pp-grid-dark-featured .grid__item:nth-child(2)');
    expect(dfFirst).not.toBe('none'); // translateY lift present
    expect(dfFirst).not.toBe(dfSib);
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
      slots: {
        '--grid-card-border-width': '0px',
        '--grid-eyebrow-border-width': '0px',
        '--grid-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'faq',
      props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] },
      slots: {
        '--faq-border-color': '#ff0080',
        '--faq-eyebrow-border-width': '0px',
        '--faq-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'testimonials',
      props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] },
      slots: {
        '--testimonials-card-border-width': '0px',
        '--testimonials-eyebrow-border-width': '0px',
        '--testimonials-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'cta',
      props: { id: 'pp-cta01', button_text: 'Go', button_url: '/go' },
      slots: {
        '--cta-border-width': '0px',
        '--cta-border-color': 'transparent',
        '--cta-eyebrow-border-width': '0px',
        '--cta-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'section',
      props: { id: 'pp-sec01', body: '<p>Panel body.</p>' },
      slots: {
        '--section-border-width': '0px',
        '--section-border-color': 'transparent',
        '--section-panel-border-width': '0px',
        '--section-panel-border-color': 'transparent',
        '--section-eyebrow-border-width': '0px',
        '--section-eyebrow-border-color': 'transparent',
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
        '--hero-eyebrow-border-width': '0px',
        '--hero-eyebrow-border-color': 'transparent',
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

  /*
   * #338 — the hero proof line rendered LEFT-ALIGNED in a centered hero.
   *
   * Third instance of the class #225 and #255 already hit: a flexbox default silently
   * overriding the component's alignment intent. `.hero__proof` is a flex container with
   * no justify-content, so its items packed at the initial `flex-start`. `text-align:
   * center` IS inherited onto it from `.hero--centered .hero__inner` — and a flex
   * container ignores text-align when placing its items. The box was centered; the words
   * inside it were not.
   *
   * This is invisible at the declaration level, which is exactly how it shipped: every
   * slot worked, the composition validated, and the computed style said `text-align:
   * center`. Reading one computed property is what made the first dogfood pass call it
   * "already centered". So these pins measure GEOMETRY — where the glyphs actually
   * landed relative to the content column — and never trust a single declaration.
   *
   * Both proof shapes are covered, because they produce different flex items:
   *   - a bare text run  -> ONE anonymous flex item (the shape the dogfood used)
   *   - element children -> one flex item PER element, which also wrap independently
   * Both viewports are covered, per the #86/#225 lesson recorded above: "mobile always
   * passed" is how the last cascade bug in this file hid.
   */
  const proofLayouts: { layout: string; align: 'start' | 'center' }[] = [
    { layout: 'left', align: 'start' },
    { layout: 'centered', align: 'center' },
    { layout: 'cover', align: 'center' },
  ];
  const proofShapes: { label: string; proof: string }[] = [
    { label: 'bare text', proof: 'No card required' },
    { label: 'element children', proof: '<span>No card</span><span>No setup</span>' },
  ];
  const proofViewports = [
    { label: 'desktop', width: 1280, height: 900 },
    { label: 'mobile', width: 375, height: 800 },
  ];

  for (const { layout, align } of proofLayouts) {
    for (const shape of proofShapes) {
      for (const viewport of proofViewports) {
        // Post-merge main runs ONLY the @smoke subset, so the subset must carry BOTH halves
        // of the invariant, not just the reported bug. Centered/bare text/desktop is the
        // shipped bug (packing must be centered). Left/bare text/desktop is the mirror-image
        // regression an unscoped `.hero__proof { justify-content: center }` would cause
        // (packing must stay left) — a fix that over-applies is as wrong as one that
        // under-applies, and only a rendered pin can tell them apart.
        const smoke =
          (layout === 'centered' || layout === 'left') &&
          shape.label === 'bare text' &&
          viewport.label === 'desktop'
            ? ' @smoke'
            : '';

        test(`#338 hero proof content follows the layout's alignment (${layout}, ${shape.label}, ${viewport.label})${smoke}`, async ({
          page,
        }) => {
          pageId = createPage(`E2E Hero Proof ${layout} ${shape.label} ${viewport.label}`);
          setComposition(pageId, [
            {
              component: 'hero',
              props: {
                id: 'pp-hero01',
                layout,
                // A long title widens .hero__content, so the proof row has room to be
                // wrong in — with a narrow column, left and centre would coincide.
                title: 'A deliberately long hero headline that widens the content column',
                proof: shape.proof,
              },
            },
          ]);

          await page.setViewportSize({ width: viewport.width, height: viewport.height });
          await page.goto(`/?page_id=${pageId}`);

          const proof = page.locator('.hero__proof');
          await expect(proof).toBeVisible({ timeout: 10000 });

          expectRowAligned(await proof.evaluate(measureRowContent), align);
        });
      }
    }
  }

  /*
   * The other half of the fix: the split hero renders its proof markup inside
   * `.hero__surface` (components/hero/hero.php), NOT under `.hero__content`, and split is
   * a LEFT-aligned layout. The centered/cover overrides must not leak into it — an
   * unscoped `.hero__proof { justify-content: center }` would fix the reported bug and
   * silently centre every left-aligned proof line on the site, with all the pins above
   * still green. Same scope failure #255 records for the CTA.
   */
  /*
   * Both viewports, but they can assert different things, and the difference is the point.
   *
   * At >=1024px `.hero--split .hero__inner` is a GRID, so `.hero__surface` is stretched by its
   * track: the column is wider than the proof, and left-vs-centre is a real question that
   * geometry can answer.
   *
   * Below that the split hero is a flex column with `align-items: flex-start`, so
   * `.hero__surface` SHRINK-WRAPS its content. The row and its column are then the same box,
   * and no packing is observable — every alignment renders identically. Asserting geometry
   * there would be a pin that cannot fail. So mobile asserts the computed declaration (proving
   * the centered/cover overrides did not leak into split in that media context) and asserts
   * the shrink-wrap itself, so that if `.hero__surface` ever stops shrink-wrapping — the
   * moment geometry becomes meaningful again — this fails loudly instead of quietly guarding
   * nothing.
   */
  for (const viewport of proofViewports) {
    test(`#338 a split hero keeps its proof line left-packed — the fix stays scoped (${viewport.label})`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero Proof Split Scope ${viewport.label}`);
      setComposition(pageId, [
        {
          component: 'hero',
          props: {
            id: 'pp-hero01',
            layout: 'split',
            title: 'A deliberately long hero headline that widens the content column',
            // Split passes the proof markup through verbatim into .hero__surface, so the
            // class comes from the authored markup — the shape `.hero__surface .hero__proof`
            // already exists to style.
            proof: '<div class="hero__proof">No card required</div>',
          },
        },
      ]);

      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(`/?page_id=${pageId}`);

      const proof = page.locator('.hero__proof');
      await expect(proof).toBeVisible({ timeout: 10000 });

      const m = await proof.evaluate(measureRowContent);

      // The declaration half, asserted in BOTH media contexts: split must inherit the base
      // packing. A leak of the centered/cover override would show up here first.
      expect(m.justifyContent).toBe('flex-start');

      if (viewport.label === 'desktop') {
        expectRowAligned(m, 'start');
      } else {
        // The surface shrink-wraps: row == column, so alignment is unobservable by
        // construction. Pin the shrink-wrap rather than pretend to pin the alignment.
        expect(m.rectCount).toBeGreaterThan(0);
        expect(m.contentWidth).toBeGreaterThan(m.columnWidth * NON_VACUITY_MAX_FILL);
      }
    });
  }

  /*
   * justify-content packs each flex LINE independently, and `.hero__proof` is `flex-wrap:
   * wrap`. The pins above all measure single-line rows, where the union of the content rects
   * is the whole story. A wrapped row is where packing is most visible — the partially filled
   * LAST line is the one a reader sees hanging left under a centered hero — and a rule that
   * packed only the first line would keep every other pin in this file green.
   *
   * Lines are recovered by grouping the Range's client rects by their top edge, then each
   * line is checked on its own. The union-vs-column floor used elsewhere cannot work here:
   * a wrapped row's full lines fill the column by definition.
   */
  for (const { layout, align } of [
    { layout: 'centered', align: 'center' },
    { layout: 'left', align: 'start' },
  ] as const) {
    test(`#338 every line of a wrapped proof row follows the layout's alignment (${layout})`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero Proof Wrapped ${layout}`);
      setComposition(pageId, [
        {
          component: 'hero',
          props: {
            id: 'pp-hero01',
            layout,
            title: 'A deliberately long hero headline that widens the content column',
            // Enough items that they cannot sit on one line, and an item count that leaves
            // the last line partially filled (where centering vs left-packing diverges).
            proof:
              '<span>No card required</span><span>No setup</span><span>No install</span>' +
              '<span>No lock-in</span><span>Cancel anytime</span>',
          },
        },
      ]);

      await page.setViewportSize({ width: 375, height: 800 });
      await page.goto(`/?page_id=${pageId}`);

      const proof = page.locator('.hero__proof');
      await expect(proof).toBeVisible({ timeout: 10000 });

      const m = await proof.evaluate(measureRowContent);

      // Precondition: the row really wrapped. On one line this pin proves nothing new.
      expect(m.lines.length).toBeGreaterThan(1);

      // The LAST line is the partially filled one, so its packing is unambiguous. Full lines
      // span the column and read the same under either alignment; expectBoxAligned's
      // non-vacuity floor enforces that the line asserted here is genuinely short.
      expectBoxAligned(m.lines[m.lines.length - 1], m, align);
    });
  }

  /*
   * `.hero--cover { justify-content: center }` is the one declaration in this change with no
   * behavior of its own: `.container`'s auto inline margins already absorb the free space. It
   * is declared so the row states its intent, but "inert" is a claim worth pinning, because a
   * flex container's justify-content DOES set the static position of its absolutely positioned
   * children — and `.hero__overlay` is exactly that. It is only harmless because the overlay
   * pins all four sides with `inset: 0`. If that ever becomes width-based, this declaration
   * would silently offset the overlay, and nothing else here would notice.
   */
  test('#338 the cover hero overlay still covers the whole section', async ({ page }) => {
    pageId = createPage('E2E Hero Cover Overlay');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero01',
          layout: 'cover',
          title: 'A deliberately long hero headline that widens the content column',
          proof: 'No card required',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const hero = page.locator('.hero--cover');
    await expect(hero).toBeVisible({ timeout: 10000 });

    const cover = await hero.evaluate((el) => {
      const overlay = el.querySelector('.hero__overlay') as Element;
      const h = el.getBoundingClientRect();
      const o = overlay.getBoundingClientRect();
      return {
        heroLeft: h.left,
        heroWidth: h.width,
        overlayLeft: o.left,
        overlayWidth: o.width,
        justifyContent: getComputedStyle(el).justifyContent,
      };
    });

    expect(cover.justifyContent).toBe('center');
    // The overlay is flush with the section on both edges — justify-content did not shift it.
    expect(Math.abs(cover.overlayLeft - cover.heroLeft)).toBeLessThan(1);
    expect(Math.abs(cover.overlayWidth - cover.heroWidth)).toBeLessThan(1);
  });

  /*
   * The CTA group carries the SAME unset-justify-content hole, but it hides: `align-self:
   * center` shrink-wraps the group's box, so where the box packs its buttons never comes
   * up — until the buttons WRAP. Then the box fills the content column and the rows pack
   * left, in a centered hero, exactly like the proof line did. Left unfixed, this is the
   * bug's next reappearance, one row up.
   *
   * The wrap is forced through the documented `--hero-content-width` slot rather than a
   * narrow viewport, because below 768px `main .btn` is `width: 100%` — full-bleed buttons
   * have no alignment left to get wrong, so a mobile fixture cannot discriminate the bug.
   * Narrowing the column at DESKTOP keeps the buttons at their natural width and makes the
   * packing observable. The wrap is asserted before the alignment: a fixture whose buttons
   * stopped wrapping would otherwise turn this pin green while guarding nothing.
   */
  for (const { layout, align } of [
    { layout: 'centered', align: 'center' },
    // Cover carries its own `.hero--cover .hero__cta-group` override. Without it here, that
    // rule's only evidence would be a declaration pin — the standard that let #338 ship.
    { layout: 'cover', align: 'center' },
    { layout: 'left', align: 'start' },
  ] as const) {
    test(`#338 a wrapped hero cta group follows the layout's alignment (${layout})`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero CTA Wrap ${layout}`);
      setComposition(pageId, [
        {
          component: 'hero',
          props: {
            id: 'pp-hero01',
            layout,
            title: 'A deliberately long hero headline that widens the content column',
            cta_text: 'Get started',
            cta_url: '/start',
            cta2_text: 'Book a demo',
            cta2_url: '/contact',
          },
        },
      ]);

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

      // Narrower than the two buttons side by side (each floors at `main .btn`'s
      // min-width: 13.25rem), so they must wrap — and wide enough that one button is well
      // short of filling its row, so "centered" and "left" stay different answers.
      const res = await styleComponent(page, pageId, { '--hero-content-width': '26rem' });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const group = page.locator('.hero__cta-group');
      await expect(group).toBeVisible({ timeout: 10000 });

      const boxes = await group.evaluate((el) => {
        const g = el.getBoundingClientRect();
        return {
          justifyContent: getComputedStyle(el).justifyContent,
          // The group IS the column its buttons align to.
          columnLeft: g.left,
          columnWidth: g.width,
          columnCenter: g.left + g.width / 2,
          buttons: Array.from(el.children).map((c) => {
            const r = c.getBoundingClientRect();
            return { left: r.left, top: r.top, width: r.width, center: r.left + r.width / 2 };
          }),
        };
      });

      expect(boxes.justifyContent).toBe(align === 'center' ? 'center' : 'flex-start');
      expect(boxes.buttons).toHaveLength(2);

      // Precondition: the buttons really are on separate rows. Without the wrap the group box
      // shrink-wraps to its content and every assertion below is trivially true. If this ever
      // fails, suspect `main .btn`'s min-width (13.25rem) or --space-sm, not the hero.
      expect(Math.abs(boxes.buttons[0].top - boxes.buttons[1].top)).toBeGreaterThan(1);

      // Each wrapped row holds one button narrower than the group, so its placement within
      // that row is a real constraint.
      for (const button of boxes.buttons) {
        expectBoxAligned(button, boxes, align);
      }
    });
  }

  // ── #336: weak defaults / missing slots ──────────────────────────────────
  //
  // Declaration-level assertions are not enough here. Every one of these three
  // properties WAS declared in components.css and still failed to reach the
  // element (the subheading rhythm lost the cascade to base.css's
  // `p:last-child { margin-bottom: 0 }`). Only computed style proves a slot
  // actually lands, so each strand is pinned twice: unset -> documented default,
  // and set -> the operator's value wins.

  // Strand 3 regression. `p:last-child` (0,1,1) outranked `.grid__subheading`
  // (0,1,0), and the subheading is always the header's last child — so the
  // declared `margin-bottom: var(--space-lg)` computed to 0px on every page.
  // All three subheading-bearing components shared the bug.
  for (const { component, slot, expected } of [
    { component: 'section', slot: '--section-subheading-margin-bottom', expected: '16px' },
    { component: 'grid', slot: '--grid-subheading-margin-bottom', expected: '32px' },
    { component: 'testimonials', slot: '--testimonials-subheading-margin-bottom', expected: '32px' },
  ]) {
    test(`#336 ${component} subheading keeps its bottom rhythm as the header's last child @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E ${component} Subheading Rhythm`);
      setComposition(pageId, [
        {
          component,
          props: {
            id: 'pp-sub01',
            title: 'Rhythm',
            eyebrow: 'Kicker',
            subheading: 'The sub-heading must not collide with the content below it.',
            // Each component's own required props (section: body, grid/testimonials: items).
            ...(component === 'section' ? { body: '<p>Body copy.</p>' } : {}),
            ...(component === 'grid' ? { items: [{ title: 'One', text: 'Card' }] } : {}),
            ...(component === 'testimonials'
              ? { items: [{ quote: 'Great.', author: 'A. Person' }] }
              : {}),
          },
        },
      ]);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const sub = page.locator(`.${component}__subheading`);
      await expect(sub).toBeVisible({ timeout: 10000 });

      // Unset: the component's own declared rhythm survives the global prose reset.
      // Before #336 this was '0px' — the reset won and the subheading collided.
      const unset = await sub.evaluate((el) => getComputedStyle(el).marginBottom);
      expect(unset).toBe(expected);

      // The element really is the last child, so the reset genuinely applies to it.
      // If this ever fails, the markup changed and the regression above is no longer pinned.
      const isLastChild = await sub.evaluate((el) => el === el.parentElement?.lastElementChild);
      expect(isLastChild).toBe(true);

      // Rendered proof that THIS component's eyebrow radius slot reaches the element.
      // All six eyebrow blocks are identical, but a declared slot that no rule consumes
      // is exactly the failure the unit guards cannot see at computed-style level.
      const radius = await page
        .locator(`.${component}__eyebrow`)
        .evaluate((el) => getComputedStyle(el).borderRadius);
      expect(radius).toBe('3px');

      // Set: the new slot drives it. A value no token resolves to.
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, { [slot]: '61px' });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const set = await page.locator(`.${component}__subheading`).evaluate(
        (el) => getComputedStyle(el).marginBottom
      );
      expect(set).toBe('61px');
    });
  }

  // ── #343: title -> subheading gap is now slot-driven ──────────────────────
  //
  // #336 made the subheading's BOTTOM margin authorable; the title's OWN bottom
  // margin (the title -> subheading gap, the TOP half of the header rhythm) stayed
  // hardcoded. This routes it through a slot so the whole header rhythm is
  // slot-driven. The gap is NOT the simple base literal: for section/grid a shared
  // "premium typography" rule (`main > .X .heading`, [0,2,1]) overrides the base
  // [0,1,0] rule at every breakpoint, so the value that actually renders is 1.65rem
  // at >=768px (this test's 1280px viewport) and 1.25rem below it. The slot is
  // routed through the base rule AND both premium breakpoints (the #302 split), so
  // a declaration-level assertion would not prove the slot survives the premium
  // override — only computed style does. testimonials has no premium override, so
  // its base var(--space-lg) (32px) is what renders. 1.65rem @ 16px root = 26.4px.
  // Pinned twice: unset -> the real rendered default, and set -> the operator wins.
  for (const { component, locator, slot, expected } of [
    { component: 'section', locator: '.section__title', slot: '--section-title-margin-bottom', expected: '26.4px' },
    { component: 'grid', locator: '.grid__heading', slot: '--grid-heading-margin-bottom', expected: '26.4px' },
    { component: 'testimonials', locator: '.testimonials__heading', slot: '--testimonials-heading-margin-bottom', expected: '32px' },
  ]) {
    test(`#343 ${component} title keeps its slot-driven gap above the subheading @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E ${component} Title Rhythm`);
      setComposition(pageId, [
        {
          component,
          props: {
            id: 'pp-ttl01',
            title: 'Rhythm',
            eyebrow: 'Kicker',
            subheading: 'The title must not collide with the sub-heading below it.',
            // Each component's own required props (section: body, grid/testimonials: items).
            ...(component === 'section' ? { body: '<p>Body copy.</p>' } : {}),
            ...(component === 'grid' ? { items: [{ title: 'One', text: 'Card' }] } : {}),
            ...(component === 'testimonials'
              ? { items: [{ quote: 'Great.', author: 'A. Person' }] }
              : {}),
          },
        },
      ]);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const title = page.locator(locator);
      await expect(title).toBeVisible({ timeout: 10000 });

      // Unset: today's literal renders (byte-identical to pre-#343). The slot adds
      // capability, not a new default. The title is NOT the header's last child, so
      // the `p:last-child` reset never applied here in the first place.
      const unset = await title.evaluate((el) => getComputedStyle(el).marginBottom);
      expect(unset).toBe(expected);
      const isLastChild = await title.evaluate((el) => el === el.parentElement?.lastElementChild);
      expect(isLastChild).toBe(false);

      // Set: the new slot drives it. A value no token resolves to.
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, { [slot]: '61px' });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const set = await page.locator(locator).evaluate(
        (el) => getComputedStyle(el).marginBottom
      );
      expect(set).toBe('61px');
    });
  }

  // Strand 1. The eyebrow had color/bg slots but no radius slot, so the pill
  // shape was unreachable.
  //
  // The `home-hero` case is now a #412 parity guard. That demo id once carried a
  // `#home-hero, #how-hero, #agencies-hero, #implementers-hero .hero__eyebrow` block that
  // re-declared border-radius at ID specificity (1,1,0); issue 412 evicted every such ID
  // selector and the css-lint ID guard forbids their return. So `home-hero` must now render
  // the eyebrow through the SAME base rules as any other id — exercising both ids proves the
  // reserved id gets no special treatment (acceptance: home-hero renders like any id).
  for (const heroId of ['pp-hero01', 'home-hero']) {
    test(`#336 hero eyebrow radius is slot-driven and defaults to the documented 3px (#${heroId}) @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero Eyebrow Radius Slot ${heroId}`);
      setComposition(pageId, [
        { component: 'hero', props: { id: heroId, eyebrow: 'Kicker', title: 'Radius' } },
      ]);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const eyebrow = page.locator('.hero__eyebrow');
      await expect(eyebrow).toBeVisible({ timeout: 10000 });

      // Unset output stays byte-identical to pre-#336: the slot adds capability, not opinion.
      expect(await eyebrow.evaluate((el) => getComputedStyle(el).borderRadius)).toBe('3px');

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, { '--hero-eyebrow-radius': '999px' });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const rounded = await page
        .locator('.hero__eyebrow')
        .evaluate((el) => getComputedStyle(el).borderRadius);
      expect(rounded).toBe('999px');
    });
  }

  // #356: the eyebrow pill had color/bg/radius slots but no border slot, so an
  // OUTLINED pill was inexpressible. Border width/color slots make it authorable.
  //
  // `home-hero` is a #412 parity guard, exactly as in the #336 radius strand above: the
  // demo-id `.hero__eyebrow` block that once re-declared `border-color` at ID specificity
  // was evicted in issue 412, so `home-hero` must render the border through the same base
  // slot rules as any other id (exercising both ids proves the reserved id is not special).
  for (const heroId of ['pp-hero01', 'home-hero']) {
    test(`#356 hero eyebrow border is slot-driven and defaults to no border (#${heroId}) @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero Eyebrow Border Slot ${heroId}`);
      setComposition(pageId, [
        { component: 'hero', props: { id: heroId, eyebrow: 'Kicker', title: 'Border' } },
      ]);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const eyebrow = page.locator('.hero__eyebrow');
      await expect(eyebrow).toBeVisible({ timeout: 10000 });

      // Unset output stays byte-identical to pre-#356: the default pill has no
      // visible border (0-width), the slot adds capability, not opinion.
      expect(await eyebrow.evaluate((el) => getComputedStyle(el).borderTopWidth)).toBe('0px');

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, {
        '--hero-eyebrow-border-width': '3px',
        '--hero-eyebrow-border-color': '#ff0080',
      });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const outlined = await page.locator('.hero__eyebrow').evaluate((el) => {
        const s = getComputedStyle(el);
        return { width: s.borderTopWidth, color: s.borderTopColor };
      });
      // The outlined pill now renders: a real 3px border in the asked-for color,
      // reaching even the ID-specificity benchmark heroes (routing proven).
      expect(outlined.width).toBe('3px');
      expect(outlined.color).toBe('rgb(255, 0, 128)');
    });
  }

  // #370: the eyebrow pill baked `text-transform: uppercase` with no slot, so a
  // sentence-case kicker was inexpressible. The per-component text-transform slot
  // makes the casing authorable while keeping uppercase as the unset default.
  //
  // `home-hero` is a #412 parity guard like the #356 strand above: its demo-id
  // `.hero__eyebrow` block was evicted in issue 412, so the base rule's
  // `var(--hero-eyebrow-text-transform, uppercase)` is the only rule that applies —
  // testing `home-hero` proves the reserved id renders the casing like any other id.
  for (const heroId of ['pp-hero01', 'home-hero']) {
    test(`#370 hero eyebrow text-transform is slot-driven and defaults to uppercase (#${heroId}) @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E Hero Eyebrow Text-Transform Slot ${heroId}`);
      setComposition(pageId, [
        { component: 'hero', props: { id: heroId, eyebrow: 'Kicker', title: 'Casing' } },
      ]);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      const eyebrow = page.locator('.hero__eyebrow');
      await expect(eyebrow).toBeVisible({ timeout: 10000 });

      // Unset output stays byte-identical to pre-#370: the eyebrow still computes
      // `uppercase`. The slot adds capability, not a new default.
      expect(await eyebrow.evaluate((el) => getComputedStyle(el).textTransform)).toBe('uppercase');

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, {
        '--hero-eyebrow-text-transform': 'none',
      });
      expect(res.success).toBe(true);

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      // Setting the slot to `none` renders the kicker sentence-case, reaching even
      // the ID-specificity benchmark heroes (routing proven).
      expect(
        await page.locator('.hero__eyebrow').evaluate((el) => getComputedStyle(el).textTransform),
      ).toBe('none');
    });
  }

  // Strand 2. .stats__number had a color slot but no size slot at all, so the
  // headline figure's scale was simply not authorable.
  test('#336 stats number size is slot-driven and defaults to the documented 2.5rem @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Stats Number Size Slot');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-stats01', items: [{ number: '98%', label: 'Uptime' }] },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const number = page.locator('.stats__number');
    await expect(number).toBeVisible({ timeout: 10000 });

    // 2.5rem at the 16px root = 40px. Unchanged by #336 (slot added, default kept).
    expect(await number.evaluate((el) => getComputedStyle(el).fontSize)).toBe('40px');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--stats-number-size': '73px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const sized = await page
      .locator('.stats__number')
      .evaluate((el) => getComputedStyle(el).fontSize);
    expect(sized).toBe('73px');
  });
});

/**
 * #383 — stats contained rounded metrics card, rendered proof.
 *
 * The static StyleSlotContractTest proves `--stats-radius` / `--stats-max-width`
 * are CONSUMED as var() on a length-compatible property; only a real browser proves
 * they WIN once the cascade resolves (the #302 dead-slot class the issue calls out).
 * These pins assert: (1) unset renders full-bleed + square, byte-identical to pre-383;
 * (2) the radius reaches the rendered band at 375 AND 1280; (3) max-width caps and
 * centers the band at 1280 and never overflows at 375.
 */
test.describe('#383 stats contained rounded card renders', () => {
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

  test('#383 unset stats band is full-bleed and square (byte-identical) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Stats Card Defaults');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-stats01', items: [{ number: '98%', label: 'Uptime' }] },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const band = page.locator('.stats');
    await expect(band).toBeVisible({ timeout: 10000 });

    // Defaults are inert: max-width: none, border-radius: 0 — the pre-383 render.
    const metrics = await band.evaluate((el) => {
      const cs = getComputedStyle(el);
      return {
        radius: cs.borderTopLeftRadius,
        maxWidth: cs.maxWidth,
        docScroll: document.documentElement.scrollWidth,
        docClient: document.documentElement.clientWidth,
      };
    });
    expect(metrics.radius).toBe('0px');
    expect(metrics.maxWidth).toBe('none');
    // No horizontal overflow at the default full-bleed width.
    expect(metrics.docScroll).toBeLessThanOrEqual(metrics.docClient + 1);
  });

  test('#383 --stats-radius rounds the band at 375 and 1280 @smoke', async ({ page }) => {
    pageId = createPage('E2E Stats Card Radius');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-stats01', items: [{ number: '98%', label: 'Uptime' }] },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--stats-radius': '24px' });
    expect(res.success).toBe(true);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const band = page.locator('.stats');
      await expect(band).toBeVisible({ timeout: 10000 });
      const radius = await band.evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
      expect(radius).toBe('24px');
    }
  });

  test('#383 --stats-max-width caps and centers the band at 1280 @smoke', async ({ page }) => {
    pageId = createPage('E2E Stats Card Max Width');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-stats01', items: [{ number: '98%', label: 'Uptime' }] },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--stats-max-width': '640px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const band = page.locator('.stats');
    await expect(band).toBeVisible({ timeout: 10000 });

    const geo = await band.evaluate((el) => {
      const rect = el.getBoundingClientRect();
      return {
        maxWidth: getComputedStyle(el).maxWidth,
        width: rect.width,
        left: rect.left,
        right: document.documentElement.clientWidth - rect.right,
      };
    });
    // The slot reaches the band, the band is capped to 640, and auto side-margins
    // center it (equal gutters within a rounding tolerance) — a contained card, not
    // a full-bleed band pinned to the left edge.
    expect(geo.maxWidth).toBe('640px');
    expect(geo.width).toBeLessThanOrEqual(641);
    expect(geo.width).toBeGreaterThan(600);
    expect(Math.abs(geo.left - geo.right)).toBeLessThanOrEqual(2);
    expect(geo.left).toBeGreaterThan(100); // real gutters exist (not left-pinned)
  });

  test('#383 --stats-max-width never overflows at 375 @smoke', async ({ page }) => {
    pageId = createPage('E2E Stats Card Max Width Mobile');
    setComposition(pageId, [
      {
        component: 'stats',
        props: {
          id: 'pp-stats01',
          items: [
            { number: '98%', label: 'Uptime' },
            { number: '+30', label: 'Years' },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    // 640px cap is wider than the 375px viewport, so the band must fall back to the
    // viewport width with no horizontal scroll (acceptance: no overflow at 375px).
    const res = await styleComponent(page, pageId, { '--stats-max-width': '640px' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const band = page.locator('.stats');
    await expect(band).toBeVisible({ timeout: 10000 });

    const overflow = await page.evaluate(() => ({
      scroll: document.documentElement.scrollWidth,
      client: document.documentElement.clientWidth,
    }));
    expect(overflow.scroll).toBeLessThanOrEqual(overflow.client + 1);
  });
});

/**
 * #333 — header/footer chrome, rendered proof.
 *
 * The header and footer are template-owned chrome, so their styling surface is the
 * pp_header_* / pp_footer_* SITE OPTIONS rather than composition style slots. That puts
 * them outside the issue-305 schema guard entirely, and StyleSlotContractTest only scans
 * for slot names it can discover from a schema — so nothing static can prove these
 * options reach the browser.
 *
 * They need a rendered pin more than any slot does, because of the specific bug this
 * issue found: `--header-bg` and `--footer-bg` accept a GRADIENT, and a gradient is a
 * CSS <image>. Routed through `background-color` it is invalid, so the browser silently
 * drops the declaration and the chrome paints nothing — the option validates on write,
 * round-trips through restore, passes every unit test that asserts the custom property
 * is present in the HTML, and still never appears on screen. Only getComputedStyle can
 * tell "the gradient painted" from "the declaration was dropped".
 *
 * Gradient serialization (color format, angle, whitespace, stop syntax) is
 * browser-normalized, so these assert LOOSELY: the layer is a gradient and is not `none`.
 */
function setSiteOption(key: string, value: string): void {
  const safe = value.replace(/'/g, "'\\''");
  execSync(`npx wp-env run cli wp option update ${key} '${safe}'`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function deleteSiteOption(key: string): void {
  try {
    execSync(`npx wp-env run cli wp option delete ${key}`, { cwd: process.cwd() });
  } catch {
    /* not set — nothing to clean */
  }
}

test.describe('#333 chrome site options render', () => {
  let pageId: number;
  const CHROME_OPTIONS = ['pp_header_bg', 'pp_header_text', 'pp_header_link_color', 'pp_footer_bg'];

  test.afterEach(async () => {
    // No residue: these are SITE options, so a leak would style every later test's page.
    for (const key of CHROME_OPTIONS) {
      deleteSiteOption(key);
    }
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  test('#333 pp_header_bg paints a real gradient on the header @smoke', async ({ page }) => {
    pageId = createPage('E2E Header Gradient');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    setSiteOption('pp_header_bg', 'linear-gradient(135deg, #1a1a2e, #16121f)');
    setSiteOption('pp_header_text', '#e8e8f0');
    setSiteOption('pp_header_link_color', '#c8c8e0');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    // THE assertion. Under `background-color` this comes back 'none' — the declaration
    // is invalid CSS and the browser drops it, so the gradient never paints.
    const bgImage = await header.evaluate((el) => getComputedStyle(el).backgroundImage);
    expect(bgImage).not.toBe('none');
    expect(bgImage).toContain('gradient');

    // The logo wordmark follows --header-text; the nav links follow --header-link-color.
    const logoColor = await page.locator('.nav__logo').evaluate((el) => getComputedStyle(el).color);
    expect(logoColor).toBe('rgb(232, 232, 240)');
  });

  test('#333 pp_footer_bg paints a real gradient on the footer', async ({ page }) => {
    pageId = createPage('E2E Footer Gradient');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    // Widened from color-only to the color-OR-gradient union in #333.
    setSiteOption('pp_footer_bg', 'linear-gradient(135deg, #1a1a2e, #16121f)');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const footer = page.locator('.site-footer');
    await expect(footer).toBeAttached({ timeout: 10000 });

    const bgImage = await footer.evaluate((el) => getComputedStyle(el).backgroundImage);
    expect(bgImage).not.toBe('none');
    expect(bgImage).toContain('gradient');
  });

  test('#333 a plain color still works on the gradient-typed background option', async ({
    page,
  }) => {
    // `gradient` is a color-OR-gradient UNION, so widening the type must not break the
    // plain-color case that #300 shipped.
    pageId = createPage('E2E Header Solid Color');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    setSiteOption('pp_header_bg', '#1a1a2e');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    const bgColor = await header.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bgColor).toBe('rgb(26, 26, 46)');
  });

  test('#333 an unstyled header is unchanged (no gradient, no inline style)', async ({ page }) => {
    // Defaults stay neutral: this issue adds a capability, never a color opinion.
    pageId = createPage('E2E Header Default');
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const header = page.locator('.site-header');
    await expect(header).toBeVisible({ timeout: 10000 });

    const bgImage = await header.evaluate((el) => getComputedStyle(el).backgroundImage);
    expect(bgImage).toBe('none');

    const inlineStyle = await header.evaluate((el) => el.getAttribute('style'));
    expect(inlineStyle).toBeNull();
  });

  /**
   * #339 — the promoted list marker must actually PAINT on the panel and body
   * lists, over the issue-295 disc rules. StyleSlotContractTest scans only
   * components.css, so it cannot see that `.section__content ul { list-style: disc }`
   * (0,1,1) and `.section__panel-list { list-style: disc }` (0,1,0) are beaten by
   * the shared marker rules on source order — that is exactly the #342 guard gap
   * (a marker that validates but never paints is the #302 failure mode). Only a
   * rendered box proves it. Lead with list-style + a slot-driven marker colour
   * (robust); the ::before content is checked tolerantly (CSSOM quotes `content`
   * inconsistently across engines).
   */
  test('#339 text-panel check marker paints over the disc rule + honors its colour slot @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Panel Check Marker');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          layout: 'text-panel',
          title: 'Honest',
          body: '<p>Left.</p>',
          panel_heading: 'Included',
          panel_items: ['No fine print', 'No lock-in'],
          panel_items_marker: 'check',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A vivid colour no theme token uses, so a failure to reach the marker is obvious.
    const res = await styleComponent(page, pageId, { '--section-panel-marker-color': '#ff0080' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const list = page.locator('.section__panel-list');
    await expect(list).toBeVisible({ timeout: 10000 });

    // The disc rule is beaten: the <ul> renders no native marker.
    const listStyle = await list.evaluate((el) => getComputedStyle(el).listStyleType);
    expect(listStyle).toBe('none');

    // The glyph paints, in the operator's chosen colour.
    const marker = await page.locator('.section__panel-item').first().evaluate((el) => {
      const b = getComputedStyle(el, '::before');
      return { content: b.content, color: b.color };
    });
    expect(marker.content).not.toBe('none');
    expect(marker.content).not.toBe('normal');
    expect(marker.color).toBe('rgb(255, 0, 128)');
  });

  test('#339 body check marker paints on the top-level list + honors its colour slot @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Body Check Marker');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Honest',
          body: '<ul><li>No fine print</li><li>No lock-in</li></ul>',
          body_marker: 'check',
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--section-body-marker-color': '#ff0080' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const list = page.locator('.section__content--marker-check > ul');
    await expect(list).toBeVisible({ timeout: 10000 });

    const listStyle = await list.evaluate((el) => getComputedStyle(el).listStyleType);
    expect(listStyle).toBe('none');

    const marker = await list.locator('> li').first().evaluate((el) => {
      const b = getComputedStyle(el, '::before');
      return { content: b.content, color: b.color };
    });
    expect(marker.content).not.toBe('none');
    expect(marker.content).not.toBe('normal');
    expect(marker.color).toBe('rgb(255, 0, 128)');
  });

  test('#339 an unstyled body list is unchanged — still a disc, no marker class @smoke', async ({
    page,
  }) => {
    // Defaults stay neutral and byte-identical: disc adds no class and no ::before.
    pageId = createPage('E2E Body Marker Default');
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec01', title: 'Plain', body: '<ul><li>Still a disc</li></ul>' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const content = page.locator('.section__content');
    await expect(content).toBeVisible({ timeout: 10000 });

    const cls = await content.evaluate((el) => el.getAttribute('class'));
    expect(cls).toBe('section__content');

    const listStyle = await content.locator('ul').evaluate((el) => getComputedStyle(el).listStyleType);
    expect(listStyle).toBe('disc');
  });

  test('#339 grid bullets still honor --grid-bullet-color after the shared-treatment refactor @smoke', async ({
    page,
  }) => {
    // Regression proof for the byte-identical grid claim: #339 moved grid's bullet
    // rules into the shared block and rewired the colour through the internal
    // --pp-list-marker-color indirection. StyleSlotContractTest only proves
    // --grid-bullet-color is *consumed*; only a rendered box proves the grid
    // check mark still paints in the operator's colour after the rewrite.
    pageId = createPage('E2E Grid Bullet Color Regression');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          items: [{ title: 'Perimeter', text: 'x', bullets: ['HTTP headers', 'SSL/TLS'] }],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--grid-bullet-color': '#ff0080' });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const bullet = page.locator('.grid__item-bullet').first();
    await expect(bullet).toBeVisible({ timeout: 10000 });

    const marker = await bullet.evaluate((el) => {
      const b = getComputedStyle(el, '::before');
      return { content: b.content, color: b.color };
    });
    expect(marker.content).not.toBe('none');
    expect(marker.color).toBe('rgb(255, 0, 128)');
  });

  test('#339 a nested body list keeps its disc under a check marker (direct-child scoping) @smoke', async ({
    page,
  }) => {
    // The body marker is scoped to the DIRECT-CHILD <ul> on purpose, so nested
    // lists (and plugin/embed markup) keep their default disc. Pin that scoping.
    pageId = createPage('E2E Body Marker Nested');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          title: 'Nested',
          body: '<ul><li>Top<ul><li>Nested child</li></ul></li></ul>',
          body_marker: 'check',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const top = page.locator('.section__content--marker-check > ul');
    await expect(top).toBeVisible({ timeout: 10000 });
    expect(await top.evaluate((el) => getComputedStyle(el).listStyleType)).toBe('none');

    // The nested <ul> is NOT a direct child of the container → keeps disc.
    const nested = top.locator('ul').first();
    expect(await nested.evaluate((el) => getComputedStyle(el).listStyleType)).toBe('disc');
  });

  test('#339 dash and arrow markers also paint, not just check @smoke', async ({ page }) => {
    // The dash (–) and arrow (→) glyphs must survive the same cross-sheet
    // cascade as check; otherwise a broken source-order interaction for them would
    // ship (only check had a rendered pin before). One page exercises both: panel
    // dash + body arrow.
    pageId = createPage('E2E Dash And Arrow Markers');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          layout: 'text-panel',
          title: 'Both',
          body: '<ul><li>Arrowed</li></ul>',
          body_marker: 'arrow',
          panel_heading: 'Dashed',
          panel_items: ['Dashed item'],
          panel_items_marker: 'dash',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const panelItem = page.locator('.section__panel-item').first();
    await expect(panelItem).toBeVisible({ timeout: 10000 });
    const dash = await panelItem.evaluate((el) => getComputedStyle(el, '::before').content);
    expect(dash).toContain('–');

    const bodyItem = page.locator('.section__content--marker-arrow > ul > li').first();
    const arrow = await bodyItem.evaluate((el) => getComputedStyle(el, '::before').content);
    expect(arrow).toContain('→');
  });

  test('#334 paired rows: mono font + per-row accent paint, row marker suppressed @smoke', async ({
    page,
  }) => {
    // Cross-sheet PAINT proof (the #342 gap: a slot can validate yet never
    // render). One page exercises all three parts of the capability:
    //   - --section-panel-font: var(--font-mono) actually reaches the panel font;
    //   - a per-row style recolours ONE row via the item_eligible --section-panel-text;
    //   - a paired row shows NO marker glyph while a string bullet in the same
    //     list still does (mixed list, marker on the <ul>).
    pageId = createPage('E2E Panel Paired Rows');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec01',
          layout: 'text-panel',
          title: 'Environment',
          body: '<p>Left.</p>',
          panel_heading: 'Runtime',
          panel_items_marker: 'check',
          panel_items: [
            'All checks passing',
            { label: 'WordPress', value: '6.7.1' },
            { label: 'Uptime', value: '99.9%', style: { '--section-panel-text': '#22d3ee' } },
          ],
        },
        style: { '--section-panel-font': 'var(--font-mono)' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    // 1. The mono font slot reaches the panel.
    const panel = page.locator('.section__panel');
    await expect(panel).toBeVisible({ timeout: 10000 });
    const font = await panel.evaluate((el) => getComputedStyle(el).fontFamily);
    expect(font).toContain('monospace');

    // 2. The string bullet keeps its check marker; the paired rows suppress it.
    const bulletMarker = await page
      .locator('.section__panel-item')
      .first()
      .evaluate((el) => getComputedStyle(el, '::before').content);
    expect(bulletMarker).not.toBe('none');
    expect(bulletMarker).not.toBe('normal');

    const rowMarker = await page
      .locator('.section__panel-row')
      .first()
      .evaluate((el) => getComputedStyle(el, '::before').content);
    expect(rowMarker).toBe('none');

    // 3. The per-row accent recolours only the styled row (last row = Uptime).
    const rowColor = await page
      .locator('.section__panel-row')
      .last()
      .evaluate((el) => getComputedStyle(el).color);
    expect(rowColor).toBe('rgb(34, 211, 238)');
  });

  // #354 — a section with layout:centered centered its heading but LEFT-PINNED its body
  // copy. The outer .section__body centers itself (max-width --measure-centered = 56rem,
  // margin auto) but the inner .section__content carried a narrower cap (42rem) with NO
  // auto margins, so it left-pinned inside the wider centered wrapper: title center-x 640,
  // paragraph center-x ~528, a visible ~112px asymmetry. This is invisible at the
  // declaration level — the bug is the ABSENCE of a margin rule, exactly how it shipped —
  // so only a rendered box under the full cascade proves the fix. The mirror-image guard
  // (a text-only section in the same page stays left-pinned) proves the fix is scoped to
  // .section--centered and did not leak into the other four layouts.
  test('#354 centered layout centers the body copy under its heading (scoped) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Centered Body Alignment');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec-centered',
          layout: 'centered',
          title: 'Especificaciones',
          body: '<p>The body copy of a centered section must sit centered under its heading, not pinned to the left edge of the wider centered wrapper.</p>',
        },
      },
      {
        component: 'section',
        props: {
          id: 'pp-sec-textonly',
          layout: 'text-only',
          title: 'Plain section',
          body: '<p>A non-centered section keeps its body copy pinned to the left; the centered fix must not reach it.</p>',
        },
      },
    ]);

    // 1280px: the container caps at 72rem (1152px), so the centered body reaches its full
    // 56rem (896px) and the inner content its 42rem (672px) — ~224px of real free space
    // for the auto margins to redistribute. Without that free space the center-x equality
    // would be vacuous (a box that fills its parent is trivially "centered" in it).
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const centeredBody = page.locator('#pp-sec-centered .section__body');
    await expect(centeredBody).toBeVisible({ timeout: 10000 });

    const centered = await page.evaluate(() => {
      const body = document.querySelector('#pp-sec-centered .section__body') as HTMLElement;
      const content = document.querySelector('#pp-sec-centered .section__content') as HTMLElement;
      const b = body.getBoundingClientRect();
      const c = content.getBoundingClientRect();
      const cs = getComputedStyle(content);
      return {
        bodyWidth: b.width,
        contentWidth: c.width,
        bodyCenterX: b.left + b.width / 2,
        contentCenterX: c.left + c.width / 2,
        marginLeft: cs.marginLeft,
        marginRight: cs.marginRight,
      };
    });

    // Free space is real: the content wrapper is meaningfully narrower than its centered
    // body, so a centered center-x is a genuine reposition, not a fill artifact.
    expect(centered.bodyWidth - centered.contentWidth).toBeGreaterThan(150);
    // The FIX: the content block centers under the (already centered) heading. Tolerance
    // 2px absorbs sub-pixel rounding / device-scale jitter; the broken state is ~112px off,
    // far above it, so the mutation sensitivity is unaffected.
    expect(Math.abs(centered.contentCenterX - centered.bodyCenterX)).toBeLessThanOrEqual(2);
    // Auto margins resolved to real, symmetric, non-zero pixels (the ~112px each side).
    // Without the #354 rule this computes to '0px' and the center-x assertion above fails.
    expect(centered.marginLeft).not.toBe('0px');
    expect(centered.marginLeft).toBe(centered.marginRight);

    // Mirror-image guard: the text-only section is unchanged. Its .section__content fills
    // its wrapper (no free space) and stays left-pinned, and the rule does not attach —
    // computed side-margins stay 0px. This proves the fix is scoped to .section--centered
    // and did not leak into the other layouts.
    const textOnly = await page.evaluate(() => {
      const body = document.querySelector('#pp-sec-textonly .section__body') as HTMLElement;
      const content = document.querySelector('#pp-sec-textonly .section__content') as HTMLElement;
      const b = body.getBoundingClientRect();
      const c = content.getBoundingClientRect();
      const cs = getComputedStyle(content);
      return {
        bodyLeft: b.left,
        contentLeft: c.left,
        marginLeft: cs.marginLeft,
        marginRight: cs.marginRight,
      };
    });
    expect(textOnly.marginLeft).toBe('0px');
    expect(textOnly.marginRight).toBe('0px');
    expect(Math.abs(textOnly.contentLeft - textOnly.bodyLeft)).toBeLessThanOrEqual(2);
  });

  // #367 — same class as #354, on the stats component. `.stats__heading` is a block <h2>
  // that carries `max-width: var(--cta-content-width, 40rem)` (the shared cap rule) and
  // `text-align: center`, but shipped with NO auto inline margins. A block h2 fills to its
  // max-width cap inside the wider .container, so the 40rem box pinned to the container's
  // LEFT edge (measured x 96-736 at 1280px; the container content center is ~x 288-928) and
  // text-align:center only centered the text WITHIN that left-pinned box — the heading sat
  // left of page center for ANY title length, not just long ones. The bug is the ABSENCE of
  // a margin rule (exactly how it shipped), invisible at the declaration level, so only a
  // rendered box under the full cascade proves the fix. We compare the heading box center-x
  // to its actual containing block (the .container), the parent margin-inline:auto centers it
  // in — not a sibling proxy — with real free space between them so the equality is a genuine
  // reposition, not a fill artifact. Mutation-verified: reverting the CSS left-pins the box
  // and fails the center-x assertion by ~224px ((container 1088 - cap 640) / 2).
  test('#367 stats heading centers in its container, not pinned left @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Stats Heading Centering');
    setComposition(pageId, [
      {
        component: 'stats',
        props: {
          id: 'pp-stats-centered',
          title: 'The numbers behind our platform performance and reliability',
          items: [
            { number: '99.9%', label: 'Uptime' },
            { number: '2.4M', label: 'Requests / day' },
            { number: '<50ms', label: 'Median latency' },
          ],
        },
      },
    ]);

    // 1280px: the container caps at 72rem (1152px), leaving the heading's 40rem (640px) cap
    // well inside the container content width (~1088px after padding) — ~448px of real free
    // space for the auto margins to redistribute. Without that free space the center-x
    // equality would be vacuous (a box that fills its parent is trivially "centered" in it).
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const heading = page.locator('#pp-stats-centered .stats__heading');
    await expect(heading).toBeVisible({ timeout: 10000 });

    const geom = await page.evaluate(() => {
      const h = document.querySelector('#pp-stats-centered .stats__heading') as HTMLElement;
      const container = h.parentElement as HTMLElement; // the .stats .container the heading centers in
      const list = document.querySelector('#pp-stats-centered .stats__list') as HTMLElement;
      const hb = h.getBoundingClientRect();
      const cb = container.getBoundingClientRect();
      const lb = list.getBoundingClientRect();
      const cs = getComputedStyle(h);
      return {
        headingWidth: hb.width,
        containerWidth: cb.width,
        headingCenterX: hb.left + hb.width / 2,
        containerCenterX: cb.left + cb.width / 2,
        listCenterX: lb.left + lb.width / 2,
        marginLeft: cs.marginLeft,
        marginRight: cs.marginRight,
      };
    });

    // Free space is real: the capped heading is meaningfully narrower than its container,
    // so a centered center-x is a genuine reposition, not a fill artifact.
    expect(geom.containerWidth - geom.headingWidth).toBeGreaterThan(150);
    // THE FIX: the heading box centers within the containing block that actually matters
    // (its parent .container). Tolerance 2px absorbs sub-pixel rounding; the broken state is
    // ~224px off, far above it, so mutation sensitivity is unaffected.
    expect(Math.abs(geom.headingCenterX - geom.containerCenterX)).toBeLessThanOrEqual(2);
    // ...and it lines up with the already-centered stats list beneath it (the visual band).
    expect(Math.abs(geom.headingCenterX - geom.listCenterX)).toBeLessThanOrEqual(2);
    // Auto margins resolved to real, symmetric, non-zero pixels (~224px each side). Without
    // the #367 rule these compute to '0px' and the center-x assertions above fail.
    expect(geom.marginLeft).not.toBe('0px');
    expect(geom.marginLeft).toBe(geom.marginRight);
  });
});

/**
 * #355 — the active/current header link must honor pp_header_link_color.
 *
 * Bug: `.nav__menu li.current-menu-item > a` (and the `aria-current="page"` variant)
 * hard-coded `color: var(--color-accent)`, which won over `--header-link-color`. On a
 * one-page anchor-nav marketing site every link points at the current page, so WordPress
 * marks them all current and the WHOLE menu ignored pp_header_link_color, rendering the
 * accent instead. Fix: the active link color routes through
 * `var(--header-link-color, var(--color-accent))`, keeping `font-weight:700` — the operator's
 * color wins when set, the accent stays the fallback when unset (so an unstyled header is
 * byte-identical to before), and the current item stays bold either way.
 *
 * Static CSS-text pins (css-lint.test.js) can prove the declaration is present; only
 * getComputedStyle on a REAL current-menu-item can prove the rendered cascade. That needs a
 * SEEDED WP menu whose item points at the viewed page — which is exactly why #333 pinned only
 * the logo color, not a nav link. WordPress marks the item server-side: `current-menu-item` on
 * the `<li>` and `aria-current="page"` on the `<a>`, so both fixed selectors are exercised.
 */
function createMenu(name: string): number {
  return parseInt(
    execSync(`npx wp-env run cli wp menu create "${name}" --porcelain`, {
      cwd: process.cwd(),
      encoding: 'utf-8',
    }).trim(),
    10,
  );
}

function addPageToMenu(menuId: number, pageId: number): void {
  execSync(`npx wp-env run cli wp menu item add-post ${menuId} ${pageId}`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function assignMenuToPrimary(menuId: number): void {
  execSync(`npx wp-env run cli wp menu location assign ${menuId} primary`, {
    cwd: process.cwd(),
    encoding: 'utf-8',
  });
}

function deleteMenu(menuId: number): void {
  try {
    // Deleting the menu also clears its `primary` location assignment.
    execSync(`npx wp-env run cli wp menu delete ${menuId}`, { cwd: process.cwd() });
  } catch {
    /* already gone */
  }
}

test.describe('#355 active header link honors pp_header_link_color', () => {
  let pageId = 0;
  let menuId = 0;

  test.afterEach(async () => {
    // Site option + menu are site-global — a leak would style/route every later test's page.
    deleteSiteOption('pp_header_link_color');
    if (menuId) {
      deleteMenu(menuId);
      menuId = 0;
    }
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  // Seed a page, put it in a menu, and attach that menu to the header's `primary` location,
  // so visiting the page makes its own menu item the current-menu-item.
  function seedCurrentItemPage(title: string): void {
    pageId = createPage(title);
    setComposition(pageId, [{ component: 'hero', props: { id: 'pp-hero01', title: 'Hero' } }]);
    menuId = createMenu(`E2E 355 ${Date.now()}`);
    addPageToMenu(menuId, pageId);
    assignMenuToPrimary(menuId);
  }

  test('#355 the active link follows pp_header_link_color, keeping its bold weight @smoke', async ({
    page,
  }) => {
    seedCurrentItemPage('E2E Active Link Color');
    // #c8c8e0 = rgb(200, 200, 224); distinct from the accent default #3157f4 = rgb(49, 87, 244).
    setSiteOption('pp_header_link_color', '#c8c8e0');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });

    // THE assertion. Before the fix this came back rgb(49, 87, 244) — the hard-coded
    // var(--color-accent) won over --header-link-color and the operator's color was ignored.
    const color = await activeLink.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(200, 200, 224)');

    // Emphasis is preserved — the current item is still distinguishable by weight.
    const weight = await activeLink.evaluate((el) => getComputedStyle(el).fontWeight);
    expect(weight).toBe('700');
  });

  test('#355 an unset header link color leaves the active link on the accent (unchanged)', async ({
    page,
  }) => {
    // Default stays neutral: with no pp_header_link_color, the active link falls back to
    // --color-accent, so existing sites render byte-identically to before the fix.
    seedCurrentItemPage('E2E Active Link Default');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const activeLink = page.locator('.nav__menu li.current-menu-item > a');
    await expect(activeLink).toBeVisible({ timeout: 10000 });

    const color = await activeLink.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(49, 87, 244)');

    const weight = await activeLink.evaluate((el) => getComputedStyle(el).fontWeight);
    expect(weight).toBe('700');
  });
});

/**
 * #369 — --btn-radius is a real, settable design token.
 *
 * components.css reads `border-radius: var(--btn-radius, var(--radius))`, but
 * --btn-radius was never declared in :root, so update_design_token rejected it
 * as unregistered. The only reachable lever was the GLOBAL --radius — which also
 * rounds every card. A pill CTA over square cards was inexpressible.
 *
 * Registering the token is not enough on its own: the WINNING cascade rule for
 * every composed button is the premium-CTA block `main .btn { border-radius: 4px }`
 * (components.css), which overrode the base `.btn { var(--btn-radius, var(--radius)) }`
 * and hardcoded 4px. So the fix ALSO routes that winning rule through
 * `var(--btn-radius, 4px)`, and registers --btn-radius defaulting to 4px (the
 * composed button's actual current radius). A validator-only test cannot see any
 * of this — only a rendered box proves the two goals:
 *   1. DECOUPLING — setting --btn-radius=100px through the REAL update_design_token
 *      apply pills the `.btn`, while a card's radius (which reads --radius) does
 *      NOT move. Button radius is now independent of the global radius.
 *   2. BYTE-IDENTICAL UNSET — with no override, the composed button computes 4px,
 *      exactly the hardcoded value it rendered before the token existed.
 *
 * The button is a `.cta__button.btn`; the card is a `.grid__item`
 * (`border-radius: var(--grid-card-radius, var(--radius))`). --radius is
 * 0.375rem = 6px at the default 16px root; the composed button default is 4px.
 */
test.describe('#369 --btn-radius rendered proof (real WP)', () => {
  // wp-env wraps command output in "ℹ Starting"/"✔ Ran" banner lines (ANSI-colored).
  function stripWpEnvNoise(raw: string): string {
    return raw
      .split('\n')
      .filter((line) => {
        const t = line.replace(/\x1b\[[0-9;]*m/g, '').trim();
        return !(t.startsWith('ℹ Starting') || t.startsWith('✔ Ran') || t.startsWith('✖'));
      })
      .join('\n')
      .trim();
  }

  function wpCli(cmd: string): string {
    return stripWpEnvNoise(
      execSync(`npx wp-env run cli ${cmd}`, { cwd: process.cwd(), encoding: 'utf-8' }),
    );
  }

  // Brace-match the first balanced JSON object, skipping any wrapper/"Success:" text.
  function parseCliJson(raw: string, what: string): Record<string, unknown> {
    const start = raw.indexOf('{');
    if (start === -1) throw new Error(`No JSON object in ${what}: ${raw}`);
    let depth = 0,
      inStr = false,
      esc = false;
    for (let i = start; i < raw.length; i++) {
      const c = raw[i];
      if (inStr) {
        if (esc) esc = false;
        else if (c === '\\') esc = true;
        else if (c === '"') inStr = false;
      } else if (c === '"') inStr = true;
      else if (c === '{') depth++;
      else if (c === '}' && --depth === 0) return JSON.parse(raw.slice(start, i + 1));
    }
    throw new Error(`Unbalanced JSON in ${what}: ${raw}`);
  }

  // Drive the SAME apply path a chat AI would: operate inspect → preflight → execute.
  function applyToken(token: string, value: string): void {
    const runId = parseCliJson(wpCli('wp pp operate inspect'), 'operate inspect').run_id as string;
    if (!runId) throw new Error('operate inspect returned no run_id');
    wpCli(`wp pp apply preflight --run-id=${runId} --apply=update_design_token`);
    const json = JSON.stringify({ token, value }).replace(/'/g, "'\\''");
    const out = wpCli(
      `wp pp apply execute update_design_token --run-id=${runId} --params='${json}'`,
    );
    if (parseCliJson(out, 'apply execute').ok !== true) {
      throw new Error(`update_design_token ${token}=${value} did not apply: ${out}`);
    }
  }

  function resetToken(token: string): void {
    try {
      const runId = parseCliJson(wpCli('wp pp operate inspect'), 'operate inspect').run_id as string;
      wpCli(`wp pp apply preflight --run-id=${runId} --apply=update_design_token`);
      wpCli(`wp pp apply reset --run-id=${runId} --token=${token}`);
    } catch {
      /* nothing to reset */
    }
  }

  const CTA_GRID = [
    {
      component: 'cta',
      props: {
        id: 'radius-cta',
        title: 'Get started today',
        text: 'Supporting copy.',
        button_text: 'Get started',
        button_url: '/start',
      },
    },
    {
      component: 'grid',
      props: {
        id: 'radius-grid',
        title: 'Cards keep their radius',
        items: [{ title: 'One', text: 'A card that reads --radius' }],
      },
    },
  ];

  let pageId = 0;

  test.afterEach(async () => {
    resetToken('--btn-radius');
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  test('setting --btn-radius=100px pills the button without touching --radius or the card @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E btn-radius decouples from global radius');
    setComposition(pageId, CTA_GRID);

    applyToken('--btn-radius', '100px');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const button = page.locator('.cta__button.btn');
    const card = page.locator('.grid__item');
    await expect(button).toBeVisible({ timeout: 10000 });
    await expect(card).toBeVisible({ timeout: 10000 });

    const buttonRadius = await button.evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
    const cardRadius = await card.evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
    // The GLOBAL radius token itself — the ONLY lever this button had before #369.
    const rootRadius = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--radius').trim(),
    );

    // The button follows the new per-element token — it pills.
    expect(buttonRadius).toBe('100px');
    // The decoupling the issue is about: setting --btn-radius touched NEITHER the
    // global --radius (still its 0.375rem default) NOR the card, which keeps its
    // own 4px default. Before #369 the only way to round the button was to move
    // --radius, which would have rounded the card with it. A validator-only test
    // cannot see that --radius and the card stayed put.
    expect(rootRadius).toBe('0.375rem');
    expect(cardRadius).toBe('4px');
    expect(cardRadius).not.toBe(buttonRadius);
  });

  test('an unset --btn-radius renders the composed button at its historical 4px (byte-identical) @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E btn-radius unset is byte-identical');
    setComposition(pageId, CTA_GRID);
    // No applyToken: --btn-radius uses its registered 4px default.

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const button = page.locator('.cta__button.btn');
    await expect(button).toBeVisible({ timeout: 10000 });

    const buttonRadius = await button.evaluate((el) => getComputedStyle(el).borderTopLeftRadius);

    // Unset, the composed button computes 4px — exactly the hardcoded value it
    // rendered before --btn-radius existed. Registering the token defaulting to
    // 4px and routing the winning `main .btn` rule through var(--btn-radius, 4px)
    // changed nothing about the default rendering.
    expect(buttonRadius).toBe('4px');
  });
});

/**
 * #441 — registering the global button color tokens is byte-identical when unset.
 *
 * `--btn-bg` / `--btn-text` / `--btn-border-color` / `--btn-shadow` are now registered
 * design tokens (base.css first :root block) so the AI can discover the shared `.btn`
 * primitive's defaults. Each is registered AT its historical hard-coded fallback value,
 * so an unset button must render exactly as before. The static css-lint test pins the
 * registered VALUES == the historical fallbacks; this render pin proves the CASCADE
 * agrees at the ONE composed-page site where a `--btn-*` token actually wins.
 *
 * On a composed page every primary button is inside `<main>`, where the premium
 * `main .btn:not(...)` cascade ([0,4,1]) governs fill/border/ink/shadow through
 * `--cta-*` / `--color-bg`, NOT `--btn-*` (which is why #441 does NOT claim a site-wide
 * button knob — see ai-instructions/retheme.md). The hero SECONDARY cta, rendered as the
 * PRIMARY variant, is the exception: its ink rule
 * `.hero .hero__cta-group .hero__cta--secondary:not(...)×3` ([0,6,0]) OUTRANKS the
 * premium rule and routes color through `var(--hero-cta2-color, var(--btn-text, var(--color-bg)))`.
 * So `--btn-text` is the live fallback there, and registering `--btn-text: var(--color-bg)`
 * must keep that ink at the historical `--color-bg` (#fcfdff). A wrong registration value
 * would move THIS pixel, so the assertion is load-bearing, not a tautology.
 */
test.describe('#441 global button tokens are byte-identical unset (real WP)', () => {
  let pageId = 0;

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  test('an unset --btn-text renders the hero secondary CTA ink at its historical --color-bg @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E btn-text unset is byte-identical');
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'btn441-hero',
          title: 'Ship faster',
          cta_text: 'Primary action',
          cta_url: '/start',
          cta2_text: 'Secondary action',
          cta2_url: '/learn',
          // PRIMARY variant so the secondary cta matches the [0,6,0] ink rule that
          // routes through --btn-text (the outline default would take a different rule).
          cta2_variant: 'primary',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const cta2 = page.locator('.hero__cta--secondary');
    await expect(cta2).toBeVisible({ timeout: 10000 });

    const ink = await cta2.evaluate((el) => getComputedStyle(el).color);
    // --color-bg #fcfdff — the historical fallback --btn-text now resolves to. Unchanged.
    expect(ink).toBe('rgb(252, 253, 255)');
  });
});

/**
 * #458 — the global button surface is a REAL one-knob.
 *
 * #441 registered `--btn-*` but on a composed page the premium `main .btn` primary cascade
 * (and the `.cta` / `.hero` primary rules) routed fill/border/ink/shadow through `--cta-*` /
 * `--hero-*` / `--color-*` literals, NOT `--btn-*` — so setting `--btn-bg` at `:root` did
 * nothing to a composed button (the token was discoverable but inert). #458 reroutes every
 * composed-primary cascade winner through `--btn-*`, which now register as `initial` (unset)
 * so the fallback chains still bottom out at today's literal when the token is unset. So a SET
 * `--btn-*` restyles the section-panel CTA, the CTA-block button, and the hero button
 * site-wide, while an UNSET one is byte-identical and per-component slots still win.
 *
 * Proven three ways:
 *   (a) UNSET is byte-identical — each routed property equals the exact literal its chain
 *       bottoms out at, resolved through a throwaway probe element so the assertion compares
 *       against the BROWSER's resolution of the historical literal (accent gradient,
 *       `--color-accent-strong`, the premium bevel), not a brittle hardcoded hex. At 1280 AND
 *       375 (the #86/#349 lesson: a mobile media query can hide a desktop-only regression).
 *   (b) SET restyles every composed primary — including the generic `.section__panel-cta`
 *       (governed ONLY by the premium block) and the box-shadow: the two surfaces the
 *       docs-only alternative would have left inert are the proof this reroute mattered.
 *   (c) a per-component `--cta-button-bg` still beats the global `--btn-bg`.
 */
test.describe('#458 the global button surface is a real one-knob (real WP)', () => {
  let pageId = 0;

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  // hero (primary + secondary-as-primary) + cta block + text-panel section on ONE page, so a
  // single render exercises all four composed-primary contexts. Component order is fixed:
  // hero = index 0, cta = index 1, section = index 2 (used by the precedence test).
  function buildOneKnobPage(): number {
    const id = createPage('E2E btn one-knob #458');
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'btn458-hero',
          title: 'Ship faster',
          cta_text: 'Primary',
          cta_url: '/start',
          cta2_text: 'Secondary',
          cta2_url: '/learn',
          cta2_variant: 'primary', // matches the [0,6,0] ink rule that routes through --btn-text
        },
      },
      {
        component: 'cta',
        props: { id: 'btn458-cta', title: 'Join us', button_text: 'Sign up', button_url: '/join' },
      },
      {
        component: 'section',
        props: {
          id: 'btn458-section',
          title: 'Details',
          layout: 'text-panel', // required for the panel (and its .section__panel-cta) to render
          panel_heading: 'Panel',
          panel_cta_text: 'Learn more',
          panel_cta_url: '/more',
        },
      },
    ]);
    return id;
  }

  // The four composed-primary selectors. The hero primary is `.hero__cta` WITHOUT the
  // --secondary modifier (both share `.hero__cta`).
  const SEL = {
    cta: '.cta__button',
    heroPrimary: '.hero__cta:not(.hero__cta--secondary)',
    heroSecondary: '.hero__cta--secondary',
    section: '.section__panel-cta',
  };

  for (const width of [1280, 375]) {
    test(`unset --btn-* keep every composed primary byte-identical (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = buildOneKnobPage();
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(SEL.section)).toBeVisible({ timeout: 10000 });

      const got = await page.evaluate((sel) => {
        // Resolve a CSS value expression to its computed string via a throwaway element that
        // inherits :root's tokens — so "byte-identical" compares the button against the
        // browser's OWN resolution of the historical literal, not a hardcoded theme hex.
        const resolve = (prop: string, value: string) => {
          const el = document.createElement('div');
          el.style.setProperty(prop, value);
          document.body.appendChild(el);
          const out = getComputedStyle(el).getPropertyValue(prop);
          el.remove();
          return out;
        };
        const read = (s: string) => {
          const cs = getComputedStyle(document.querySelector(s) as Element);
          return {
            bgColor: cs.backgroundColor,
            bgImage: cs.backgroundImage,
            border: cs.borderTopColor,
            ink: cs.color,
            shadow: cs.boxShadow,
          };
        };
        return {
          cta: read(sel.cta),
          heroPrimary: read(sel.heroPrimary),
          heroSecondary: read(sel.heroSecondary),
          section: read(sel.section),
          accentFill: resolve('background-color', 'var(--color-accent)'),
          accentBorder: resolve('border-top-color', 'var(--color-accent)'),
          accentStrongBorder: resolve('border-top-color', 'var(--color-accent-strong)'),
          bgInk: resolve('color', 'var(--color-bg)'),
          premiumFill: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent-strong) 0%, var(--color-accent-hover) 100%)',
          ),
          premiumShadow: resolve(
            'box-shadow',
            'inset 0 1px 0 rgba(255, 255, 255, 0.16), 0 10px 22px color-mix(in srgb, var(--color-accent-strong) 14%, transparent)',
          ),
        };
      }, SEL);

      // INK — every composed primary bottoms out at --color-bg (the premium first-block color
      // winner routed through --btn-text; the hero secondary via its own [0,6,0] rule).
      expect(got.cta.ink).toBe(got.bgInk);
      expect(got.heroPrimary.ink).toBe(got.bgInk);
      expect(got.heroSecondary.ink).toBe(got.bgInk);
      expect(got.section.ink).toBe(got.bgInk);

      // FILL + BORDER for the .cta/.hero [0,5,0] winners bottom out at --color-accent.
      expect(got.cta.bgColor).toBe(got.accentFill);
      expect(got.cta.border).toBe(got.accentBorder);
      expect(got.heroPrimary.bgColor).toBe(got.accentFill);
      expect(got.heroPrimary.border).toBe(got.accentBorder);

      // SECTION-PANEL primary is governed ONLY by the premium block: fill = the accent
      // gradient (background-IMAGE), border = --color-accent-strong, shadow = premium bevel.
      expect(got.section.bgImage).toBe(got.premiumFill);
      expect(got.section.border).toBe(got.accentStrongBorder);
      expect(got.section.shadow).toBe(got.premiumShadow);
    });
  }

  test('setting --btn-* at :root restyles every composed primary incl. section-panel + shadow @smoke', async ({
    page,
  }) => {
    pageId = buildOneKnobPage();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.section)).toBeVisible({ timeout: 10000 });

    // Sentinel values no theme token resolves to, so a match proves the global knob reached.
    // Appended after the theme sheet, so this :root wins the cascade (same-specificity, later).
    // `.btn` transitions color/background/border/shadow over 150ms, so kill transitions in the
    // same tag — otherwise getComputedStyle reads an intermediate frame of the token change.
    await page.addStyleTag({
      content:
        '*,*::before,*::after{transition:none !important;animation:none !important;}' +
        ':root{--btn-bg:rgb(1,2,3);--btn-text:rgb(4,5,6);--btn-border-color:rgb(7,8,9);' +
        '--btn-shadow:0px 0px 0px 5px rgb(10,11,12);}',
    });

    const got = await page.evaluate((sel) => {
      const read = (s: string) => {
        const cs = getComputedStyle(document.querySelector(s) as Element);
        return {
          bgColor: cs.backgroundColor,
          bgImage: cs.backgroundImage,
          border: cs.borderTopColor,
          ink: cs.color,
          shadow: cs.boxShadow,
        };
      };
      return { cta: read(sel.cta), heroPrimary: read(sel.heroPrimary), section: read(sel.section) };
    }, SEL);

    // INK follows --btn-text on every composed primary.
    expect(got.cta.ink).toBe('rgb(4, 5, 6)');
    expect(got.heroPrimary.ink).toBe('rgb(4, 5, 6)');
    expect(got.section.ink).toBe('rgb(4, 5, 6)');

    // FILL follows --btn-bg. Setting a solid turns the shorthand from a gradient(image) into a
    // color, so the computed background-COLOR carries the knob.
    expect(got.cta.bgColor).toBe('rgb(1, 2, 3)');
    expect(got.heroPrimary.bgColor).toBe('rgb(1, 2, 3)');
    expect(got.section.bgColor).toBe('rgb(1, 2, 3)');

    // The section-panel primary — the surface the docs-only alternative left inert. Its BORDER
    // and SHADOW (premium-block winners) now track the global tokens too.
    expect(got.section.border).toBe('rgb(7, 8, 9)');
    expect(got.section.shadow).toContain('rgb(10, 11, 12)');
  });

  test('a per-component --cta-button-bg still beats the global --btn-bg @smoke', async ({
    page,
  }) => {
    pageId = buildOneKnobPage();

    // Style ONLY the cta component's own fill slot (index 1), then set a conflicting global
    // --btn-bg. The per-component slot must win on that button (slot precedence preserved).
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await styleComponent(page, pageId, { '--cta-button-bg': 'rgb(20,30,40)' }, undefined, 1);
    expect(res.success).toBeTruthy();

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.cta)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({
      content: '*,*::before,*::after{transition:none !important;}:root{--btn-bg:rgb(1,2,3);}',
    });

    const ctaFill = await page
      .locator(SEL.cta)
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    // --cta-button-bg (component slot) wins over the global --btn-bg.
    expect(ctaFill).toBe('rgb(20, 30, 40)');
  });
});

/**
 * Computed-rhythm proof for the shared section-band spacing model (issue 431).
 *
 * The band-level components used to hard-code their own vertical-padding literals
 * per component per media block, so they disagreed: stats/testimonials sat at 64px
 * while section/grid/faq were 76.8px on desktop, cta stayed 68px on mobile, and
 * testimonials was missing from BOTH adjacent-sibling routing lists (its
 * --testimonials-padding-top slot was dead on the adjacent-top edge). Issue 438
 * folded the last three holdouts (table, logos, embed) into the same contract, so
 * this suite now measures all NINE bands. It proves, at the rendered level, that all
 * nine consume ONE shared rhythm definition (--pp-band-padding for a band's own
 * edges, --pp-band-padding-adjacent-top for a band that follows another band):
 *
 *   - every band reports identical unset padding-top AND padding-bottom (both
 *     breakpoints), testimonials included — the css-lint suite proves the fallback
 *     chains route through the shared prop; only a rendered box proves the full
 *     cascade (base + premium + adjacent + mobile) actually resolves them equal;
 *   - a per-instance --testimonials-padding-top wins on the ADJACENT-top edge at
 *     1280 and 375 (the resurrected dead slot);
 *   - retuning the single shared definition at :root moves all six together.
 *
 * The equalities are asserted component-vs-component, not against a hardcoded px
 * value, so the companion band-symmetry issue (430) can retune the shared values
 * without churning these tests. A leading hero puts all six bands in the adjacent
 * position, so their top edges are directly comparable to each other.
 */
test.describe('Shared section-band rhythm (#431)', () => {
  let pageId: number;

  // Nine bands: issue 438 folded table/logos/embed into the shared rhythm contract.
  const BANDS = ['section', 'grid', 'cta', 'stats', 'table', 'testimonials', 'logos', 'embed', 'faq'] as const;

  // Band -> root class. All map 1:1 EXCEPT table, whose root class is .table-section.
  const BAND_CLASS: Record<string, string> = {
    section: 'section', grid: 'grid', cta: 'cta', stats: 'stats',
    table: 'table-section', testimonials: 'testimonials', logos: 'logos',
    embed: 'embed', faq: 'faq',
  };

  // Hero first so all six bands render in the adjacent-sibling position (their
  // top edges are then the same tier and directly comparable). Minimal valid
  // props per component so each renders as a direct child of <main>.
  //
  // faq is last here only for stable band ordering — its position is no longer
  // load-bearing. faq.php once echoed its FAQPage JSON-LD <script> as a trailing
  // SIBLING after </section>, which made the script the previous element sibling
  // of the next band and defeated the `main > [data-pp-component] + .band`
  // combinator. #432 moved the <script> INSIDE the faq <section>, so a band after
  // a faq now gets correct adjacency (proven by the dedicated #432 suite below).
  const STACK = [
    { component: 'hero', props: { id: 'pp-hero01', title: 'Lead' } },
    { component: 'section', props: { id: 'pp-sec01', body: '<p>Section body.</p>' } },
    { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
    { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
    { component: 'stats', props: { id: 'pp-stats01', items: [{ number: '10', label: 'Ten' }] } },
    { component: 'table', props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
    { component: 'testimonials', props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] } },
    { component: 'logos', props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
    { component: 'embed', props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
    { component: 'faq', props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] } },
  ];

  async function bandPadding(page: any, band: string) {
    return page.locator(`main > .${BAND_CLASS[band]}`).evaluate((el: Element) => {
      const cs = getComputedStyle(el);
      return { top: cs.paddingTop, bottom: cs.paddingBottom };
    });
  }

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

  // Core scenario: every band agrees on unset padding at BOTH breakpoints. This is
  // the heart of #431 — one spacing model, no per-component drift, testimonials in.
  test('#431 all nine bands report identical unset padding-top and padding-bottom at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Shared Band Rhythm Equality');
    setComposition(pageId, STACK);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('main > .faq')).toBeVisible({ timeout: 10000 });

      const measured: Record<string, { top: string; bottom: string }> = {};
      for (const band of BANDS) {
        measured[band] = await bandPadding(page, band);
      }

      const tops = BANDS.map((b) => measured[b].top);
      const bottoms = BANDS.map((b) => measured[b].bottom);

      // Non-trivial values (a 0px collapse would make equality vacuously pass).
      expect(tops.every((t) => t && t !== '0px'), `tops @${width}: ${JSON.stringify(measured)}`).toBe(true);
      expect(bottoms.every((b) => b && b !== '0px'), `bottoms @${width}: ${JSON.stringify(measured)}`).toBe(true);

      // The invariant: all nine share one top tier and one bottom tier. Compare
      // component-to-component (not to a hardcoded px) so #430 can retune freely.
      expect(new Set(tops).size, `padding-top drift @${width}: ${JSON.stringify(measured)}`).toBe(1);
      expect(new Set(bottoms).size, `padding-bottom drift @${width}: ${JSON.stringify(measured)}`).toBe(1);
    }
  });

  // The resurrected dead slot: testimonials was absent from both adjacent routing
  // lists, so --testimonials-padding-top no-op'd on the adjacent-top edge. It must
  // now win at both breakpoints, exactly like section did in #305/#302.
  test('#431 --testimonials-padding-top wins on an adjacent testimonials band at 1280 and 375', async ({
    page,
  }) => {
    pageId = createPage('E2E Testimonials Adjacent Slot Resurrected');
    // section first, testimonials second => testimonials is in the adjacent position.
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
      { component: 'testimonials', props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A pixel value no token resolves to, so a dead-slot no-op is unmistakable.
    // component_index 1 = the testimonials band (index 0 is the leading section).
    const res = await styleComponent(page, pageId, { '--testimonials-padding-top': '5px' }, undefined, 1);
    expect(res.success).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const tst = page.locator('main > .testimonials');
      await expect(tst).toBeVisible({ timeout: 10000 });
      const paddingTop = await tst.evaluate((el) => getComputedStyle(el).paddingTop);
      expect(paddingTop, `adjacent-top slot @${width}`).toBe('5px');
    }
  });

  // One knob retunes the whole site's rhythm: overriding the shared definition at
  // :root moves every band's every edge together. Proves the fallback chains really
  // terminate in the two shared props, not per-component copies — and covers all
  // three routed paths at the computed level: a band's own top (first-position),
  // every band's own bottom, and the adjacent-top tier.
  //
  // A band leads the stack (no hero) so the first band renders in the OWN-padding
  // position: its top comes from --pp-band-padding, not --pp-band-padding-adjacent-top.
  // The two shared props get DISTINCT override values so the own tier (5px) and the
  // adjacent tier (7px) are told apart. faq stays last for stable ordering; since
  // #432 moved its JSON-LD script inside the section, its position no longer matters.
  test('#431 :root overrides of both shared props move every band edge (own top+bottom, adjacent top)', async ({
    page,
  }) => {
    pageId = createPage('E2E Shared Band Rhythm Retune');
    const bandLed = [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Section body.</p>' } },
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'stats', props: { id: 'pp-stats01', items: [{ number: '10', label: 'Ten' }] } },
      { component: 'table', props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
      { component: 'testimonials', props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] } },
      { component: 'logos', props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
      { component: 'embed', props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
      { component: 'faq', props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] } },
    ];
    setComposition(pageId, bandLed);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('main > .faq')).toBeVisible({ timeout: 10000 });

    // Two distinct override values, appended after the theme sheet so they win the
    // :root cascade. 5px/7px resolve from no token, so a band still using a
    // per-component literal on any edge would fail to move.
    await page.addStyleTag({
      content: ':root { --pp-band-padding: 5px; --pp-band-padding-adjacent-top: 7px; }',
    });

    // section is first => its top is the OWN tier (5px), proving the own-top path.
    const first = await bandPadding(page, 'section');
    expect(first.top, 'first band own-top did not follow --pp-band-padding').toBe('5px');
    expect(first.bottom, 'first band own-bottom did not follow --pp-band-padding').toBe('5px');

    // Every band's own bottom follows --pp-band-padding; every ADJACENT band's top
    // follows --pp-band-padding-adjacent-top. Proves both shared props drive all nine.
    for (const band of ['grid', 'cta', 'stats', 'table', 'testimonials', 'logos', 'embed', 'faq']) {
      const { top, bottom } = await bandPadding(page, band);
      expect(bottom, `${band} own-bottom did not follow --pp-band-padding`).toBe('5px');
      expect(top, `${band} adjacent-top did not follow --pp-band-padding-adjacent-top`).toBe('7px');
    }
  });

  // ── issue 430: symmetric band rhythm ──────────────────────────────────────
  //
  // #431 (above) proved all six bands AGREE on a single top tier and a single
  // bottom tier, but deliberately left the VALUES to this issue, so those tests
  // pass even on the old 32px-top / 76.8px-bottom shape. issue 430 pins the
  // adjacent-top tier to --pp-band-padding, so a band that follows another band
  // gets the SAME top as its own bottom: every stacked band is a centered block,
  // never top-cramped / bottom-heavy, at every breakpoint and under any background
  // alternation. Because both edges now resolve to the identical custom property,
  // the check is EXACT equality (top === bottom), stronger than the issue's stated
  // 10% tolerance — a re-split of the tier would fail here by tens of px.

  // Core scenario: every band that follows another band is vertically symmetric.
  // Hero leads so all six bands render in the adjacent-top position (the edge the
  // old 32px tier used to shave). faq stays last for stable ordering; #432 moved
  // its JSON-LD <script> inside the section, so its position is no longer special.
  test('#430 every stacked band is vertically symmetric (top === bottom) at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Band Rhythm Symmetry');
    setComposition(pageId, STACK);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('main > .faq')).toBeVisible({ timeout: 10000 });

      for (const band of BANDS) {
        const { top, bottom } = await bandPadding(page, band);
        // Non-trivial (a 0px collapse would make symmetry vacuously pass).
        expect(top && top !== '0px', `${band} top vacuous @${width}: ${top}`).toBe(true);
        // The deliberate visual change: adjacent-top no longer shaved to 32px.
        expect(top, `${band} not symmetric @${width}: top=${top} bottom=${bottom}`).toBe(bottom);
      }
    }
  });

  // data-pp-spacing overrides (compact / spacious) must stay symmetric too — they
  // set both edges to one scale step, so a band carrying either attribute reads as
  // a centered block (compact = --space-lg; spacious = --space-2xl mobile /
  // --space-3xl desktop). Only hero.php emits data-pp-spacing, and a hero normally
  // leads the page, so each spacing variant is seeded as the SOLE component (own
  // position) — that isolates the data-pp-spacing rule from the generic
  // adjacent-sibling band rhythm. (The AFTER-another-band corner — where the mobile
  // adjacent rule used to shave only the top edge — is issue 434's fix and is proven
  // by the adjacent-position test immediately below.)
  test('#430 data-pp-spacing compact/spacious stay symmetric at 1280 and 375', async ({
    page,
  }) => {
    pageId = createPage('E2E Spacing Attr Symmetry');

    for (const spacing of ['compact', 'spacious']) {
      setComposition(pageId, [
        { component: 'hero', props: { id: 'pp-hero-spacing', title: 'Spacing', spacing } },
      ]);

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        const hero = page.locator('#pp-hero-spacing');
        await expect(hero).toBeVisible({ timeout: 10000 });

        const { top, bottom } = await hero.evaluate((el: Element) => {
          const cs = getComputedStyle(el);
          return { top: cs.paddingTop, bottom: cs.paddingBottom };
        });
        expect(top && top !== '0px', `${spacing} top vacuous @${width}: ${top}`).toBe(true);
        expect(top, `${spacing} not symmetric @${width}: top=${top} bottom=${bottom}`).toBe(bottom);
      }
    }
  });

  // Issue 434: the narrow corner the test above isolates AWAY. A data-pp-spacing hero
  // placed AFTER another band used to be shaved on mobile only: the generic mobile
  // adjacent rule `main > [data-pp-component] + [data-pp-component]` [0,2,1] out-ordered
  // the base [data-pp-spacing] rules [0,2,0] and won padding-top alone, so a spacious
  // hero measured top=53.6px (band rhythm) / bottom=112px (--space-2xl) — bottom-heavy,
  // not centered. Desktop was already correct (its min-width:768px restatement out-orders
  // the desktop adjacent rule). The fix adds the mirror-image mobile restatement so an
  // explicit spacing override wins BOTH edges at every breakpoint.
  //
  // A leading section puts the spaced hero in the adjacent (second) position — the exact
  // shape the sole-component test above cannot reach. Assertions are EXACT expected values
  // per breakpoint, not just top===bottom: a "both edges wrong" regression (e.g. both
  // collapsing to the band rhythm) would satisfy symmetry alone, so symmetry is necessary
  // but not sufficient. compact = --space-lg (32px) at both breakpoints; spacious =
  // --space-2xl (112px) mobile / --space-3xl (160px) desktop.
  const SPACING_ADJ_EXPECTED: Record<string, Record<number, string>> = {
    compact: { 375: '32px', 1280: '32px' },
    spacious: { 375: '112px', 1280: '160px' },
  };
  test('#434 data-pp-spacing hero AFTER another band stays symmetric + exact at 1280 and 375', async ({
    page,
  }) => {
    pageId = createPage('E2E Spacing Attr Adjacent Symmetry');

    for (const spacing of ['compact', 'spacious']) {
      // Section leads; the spaced hero renders SECOND (adjacent position) so the mobile
      // adjacent rule is in play against the data-pp-spacing override.
      setComposition(pageId, [
        { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: 'Lead band.' } },
        { component: 'hero', props: { id: 'pp-hero-adj', title: 'Spacing', spacing } },
      ]);

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        const hero = page.locator('#pp-hero-adj');
        await expect(hero).toBeVisible({ timeout: 10000 });

        const { top, bottom } = await hero.evaluate((el: Element) => {
          const cs = getComputedStyle(el);
          return { top: cs.paddingTop, bottom: cs.paddingBottom };
        });
        const expected = SPACING_ADJ_EXPECTED[spacing][width];
        // Symmetric AND at the explicit override value — the top edge is no longer shaved
        // to the adjacent band rhythm (the pre-fix mobile bug).
        expect(top, `adjacent ${spacing} not symmetric @${width}: top=${top} bottom=${bottom}`).toBe(bottom);
        expect(top, `adjacent ${spacing} top not at override value @${width}: ${top}`).toBe(expected);
        expect(bottom, `adjacent ${spacing} bottom not at override value @${width}: ${bottom}`).toBe(expected);
      }
    }
  });

  // The measured webfiable.com defect sequence (issue 430 body): hero → stats →
  // grid → cta → grid → section → cta, alternating inverted/plain backgrounds.
  // Every band after the hero used to render 32px top / 76.8px bottom. After the
  // fix none may: each is symmetric, and NO band shows the old shape. (No faq in
  // this sequence; since #432 a faq no longer interferes with a following band.)
  test('#430 webfiable-shaped stack shows no 32/77 band; every band symmetric @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Webfiable Rhythm Shape');
    setComposition(pageId, [
      { component: 'hero', props: { id: 'pp-hero01', title: 'Lead' } },
      { component: 'stats', props: { id: 'pp-stats01', theme: 'dark', items: [{ number: '10', label: 'Ten' }] } },
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'cta', props: { id: 'pp-cta01', theme: 'dark', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', theme: 'dark', title: 'Closing', button_text: 'Go', button_url: '/go' } },
    ]);

    // Every band-level component after the leading hero, by id (grid/cta appear twice).
    const bandIds = ['pp-stats01', 'pp-grid01', 'pp-cta01', 'pp-grid02', 'pp-sec01', 'pp-cta02'];

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-cta02')).toBeVisible({ timeout: 10000 });

      for (const id of bandIds) {
        const { top, bottom } = await page.locator(`#${id}`).evaluate((el: Element) => {
          const cs = getComputedStyle(el);
          return { top: cs.paddingTop, bottom: cs.paddingBottom };
        });
        // Symmetric at both breakpoints (the load-bearing #430 proof).
        expect(top, `${id} not symmetric @${width}: top=${top} bottom=${bottom}`).toBe(bottom);
        // The old shaved adjacent-top was 32px (var(--space-lg)) on DESKTOP only —
        // mobile adjacent-top was already 3.35rem, so a 32px check there is vacuous.
        if (width === 1280) {
          expect(top, `${id} still shows the old 32px adjacent-top`).not.toBe('32px');
        }
      }
    }
  });

  // Slot contract under symmetry (issue 430 acceptance criterion): a per-component
  // --*-padding-top set on a band in the ADJACENT position must still win over the
  // now-symmetric shared fallback, at both breakpoints. The pinned adjacent-top tier
  // is only a FALLBACK — an author's explicit slot value still governs. A section
  // leads so the cta renders in the adjacent-top position; 5px resolves from no
  // token, so a fallback leak (symmetric ~76.8px/53.6px) would fail loudly.
  test('#430 --cta-padding-top wins on an adjacent cta band at 1280 and 375', async ({
    page,
  }) => {
    pageId = createPage('E2E Adjacent Slot Beats Symmetric Fallback');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // component_index 1 = the cta band (index 0 is the leading section).
    const res = await styleComponent(page, pageId, { '--cta-padding-top': '5px' }, undefined, 1);
    expect(res.success).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const cta = page.locator('main > .cta');
      await expect(cta).toBeVisible({ timeout: 10000 });
      const paddingTop = await cta.evaluate((el) => getComputedStyle(el).paddingTop);
      expect(paddingTop, `adjacent-top slot override @${width}`).toBe('5px');
    }
  });

  // Issue 438: the three newly-minted band padding slots must each win on the
  // ADJACENT-top edge (the trickiest cascade case) at both breakpoints, exactly
  // like cta above. A section leads so the target renders in the adjacent position;
  // a distinct px per component (no token resolves to it) catches a fallback leak
  // or a cross-wired slot name. This is the render-level proof that the slot the
  // schema declares reaches the DOM through pp_render_style_vars.
  const NEW_BAND_SLOTS = [
    { comp: 'table', sel: '.table-section', slot: '--table-section-padding-top', px: '5px',
      props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
    { comp: 'logos', sel: '.logos', slot: '--logos-padding-top', px: '6px',
      props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
    { comp: 'embed', sel: '.embed', slot: '--embed-padding-top', px: '7px',
      props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
  ];

  for (const { comp, sel, slot, px, props } of NEW_BAND_SLOTS) {
    test(`#438 ${slot} wins on an adjacent ${comp} band at 1280 and 375`, async ({ page }) => {
      pageId = createPage(`E2E Adjacent Slot ${comp}`);
      setComposition(pageId, [
        { component: 'section', props: { id: 'pp-sec01', body: '<p>Body.</p>' } },
        { component: comp, props },
      ]);

      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

      // component_index 1 = the target band (index 0 is the leading section).
      const res = await styleComponent(page, pageId, { [slot]: px }, undefined, 1);
      expect(res.success, `${slot} set: ${JSON.stringify(res)}`).toBe(true);

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        const band = page.locator(`main > ${sel}`);
        await expect(band).toBeVisible({ timeout: 10000 });
        const paddingTop = await band.evaluate((el) => getComputedStyle(el).paddingTop);
        expect(paddingTop, `${slot} adjacent-top override @${width}`).toBe(px);
      }
    });
  }
});

/*
 * Band-after-faq adjacent rhythm (issue 432).
 *
 * faq.php used to echo its FAQPage JSON-LD <script> as a trailing SIBLING right
 * after </section> (lib/wp.php pp_render_faq_schema). In the rendered DOM that
 * put a non-component <script> between the faq <section> and the next band:
 *
 *   <section class="faq" data-pp-component="faq">…</section>
 *   <script type="application/ld+json">{…FAQPage…}</script>   <-- interloper
 *   <section class="testimonials" data-pp-component="testimonials">…</section>
 *
 * The desktop adjacent rhythm uses the IMMEDIATE-sibling combinator
 * (`main > [data-pp-component] + .testimonials`). With the <script> as the
 * previous element sibling, that `+` missed the band after the faq, so it fell
 * back to its OWN top tier (--pp-band-padding) instead of the adjacent tier
 * (--pp-band-padding-adjacent-top). #430 pins those two tiers equal, which
 * MASKED the defect at the rendered level — so the discriminating probe below
 * un-pins them at :root (own=5px, adjacent=7px) and asserts the band after the
 * faq lands on the ADJACENT tier (7px) at desktop. Pre-fix that band read 5px
 * (the own tier, via the missed combinator); post-fix the <script> lives inside
 * the faq section, the faq is again the band's immediate component sibling, and
 * the `+` resolves to 7px.
 *
 * NOTE on why a slot override alone would NOT prove this: a per-instance
 * --testimonials-padding-top wins via the BASE rule too
 * (`.testimonials { padding-top: var(--testimonials-padding-top, …) }`), so it
 * renders identically whether or not the adjacent combinator matched — it can't
 * tell the two code paths apart. Splitting the two shared tiers is the only probe
 * that isolates the combinator. Both breakpoints carry an immediate-sibling
 * adjacent rule (desktop at min-width:768px, mobile at max-width:767px), so the
 * band after a faq resolves the adjacent tier (7px) at 1280 AND 375 — pre-fix it
 * read 5px (the own tier) at both, since the `+` missed the band at both.
 */
test.describe('Band-after-faq adjacent rhythm (#432)', () => {
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

  test('#432 band after a faq resolves the adjacent-top tier at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Band After FAQ Adjacency');
    // hero leads so the faq is itself in the adjacent position; testimonials
    // follows the faq — the exact band the trailing <script> used to orphan.
    setComposition(pageId, [
      { component: 'hero', props: { id: 'pp-hero01', title: 'Lead' } },
      { component: 'faq', props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] } },
      { component: 'testimonials', props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] } },
    ]);

    // Sanity: the JSON-LD really renders, and it is NOT an element sibling of the
    // testimonials band (it lives inside the faq section post-#432). If the script
    // were still a trailing sibling, this immediate-sibling probe would find it.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('main > .testimonials')).toBeVisible({ timeout: 10000 });

    const jsonLdPresent = await page.locator('script[type="application/ld+json"]').count();
    expect(jsonLdPresent, 'FAQPage JSON-LD must still render').toBeGreaterThan(0);
    const scriptBeforeBand = await page
      .locator('main > .faq + script[type="application/ld+json"]')
      .count();
    expect(scriptBeforeBand, 'JSON-LD must not sit between the faq and the next band (#432)').toBe(0);

    // Un-pin the two shared tiers so the adjacent tier (7px) is distinguishable
    // from the own tier (5px). Appended after the theme sheet to win the :root cascade.
    await page.addStyleTag({
      content: ':root { --pp-band-padding: 5px; --pp-band-padding-adjacent-top: 7px; }',
    });

    // Desktop: the band after the faq must land on the ADJACENT tier — this is the
    // combinator match #432 restores (pre-fix it read 5px, the own tier).
    const topDesktop = await page
      .locator('main > .testimonials')
      .evaluate((el) => getComputedStyle(el).paddingTop);
    expect(topDesktop, 'band after faq must use the adjacent-top tier at 1280').toBe('7px');

    // Mobile carries its own immediate-sibling adjacent rule (max-width:767px), so
    // the band after the faq must ALSO resolve the adjacent tier here — same
    // combinator, same #432 dependency. Pre-fix this read 5px (the own tier).
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('main > .testimonials')).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({
      content: ':root { --pp-band-padding: 5px; --pp-band-padding-adjacent-top: 7px; }',
    });
    const topMobile = await page
      .locator('main > .testimonials')
      .evaluate((el) => getComputedStyle(el).paddingTop);
    expect(topMobile, 'band after faq must use the adjacent-top tier at 375').toBe('7px');
  });
});

test.describe('Band heading scale (#436)', () => {
  let pageId: number;

  // Every band-level heading is an <h2> that shares ONE responsive scale
  // (--pp-band-heading-size) as the fallback of its own size slot. Before #436
  // section/grid/cta collapsed to 16px body size on mobile (cta at every
  // viewport) and the rest disagreed. Selector + size slot per band.
  const HEADINGS: { band: string; sel: string; slot: string }[] = [
    { band: 'section', sel: '.section__title', slot: '--section-title-size' },
    { band: 'grid', sel: '.grid__heading', slot: '--grid-heading-size' },
    { band: 'cta', sel: '.cta__title', slot: '--cta-title-size' },
    { band: 'stats', sel: '.stats__heading', slot: '--stats-title-size' },
    { band: 'table', sel: '.table-section__heading', slot: '--table-section-heading-size' },
    { band: 'testimonials', sel: '.testimonials__heading', slot: '--testimonials-heading-size' },
    { band: 'logos', sel: '.logos__heading', slot: '--logos-heading-size' },
    { band: 'embed', sel: '.embed__heading', slot: '--embed-heading-size' },
    { band: 'faq', sel: '.faq__heading', slot: '--faq-heading-size' },
  ];

  // Hero first so every band renders in-flow; faq last for stable ordering to
  // match the #430/#431 stacks (since #432 its trailing JSON-LD <script> lives
  // inside the section, so its position is no longer special). Adjacency does not
  // affect font-size anyway. Each band carries a `title` so its <h2> renders.
  const STACK = [
    { component: 'hero', props: { id: 'pp-hero01', title: 'Lead' } },
    { component: 'section', props: { id: 'pp-sec01', title: 'Section', body: '<p>Body.</p>' } },
    { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
    { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
    { component: 'stats', props: { id: 'pp-stats01', title: 'Stats', items: [{ number: '10', label: 'Ten' }] } },
    { component: 'table', props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
    { component: 'testimonials', props: { id: 'pp-tst01', title: 'Testimonials', items: [{ quote: 'It works.', author: 'A' }] } },
    { component: 'logos', props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
    { component: 'embed', props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
    { component: 'faq', props: { id: 'pp-faq01', title: 'FAQ', items: [{ question: 'Q?', answer: 'A.' }] } },
  ];

  async function headingMetrics(page: any, sel: string) {
    return page.locator(`main ${sel}`).first().evaluate((el: Element) => {
      const cs = getComputedStyle(el);
      return { tag: el.tagName, size: parseFloat(cs.fontSize) };
    });
  }

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

  // Core scenario: every band <h2> shares one computed size per breakpoint, never
  // collapses to body size, and clears the 1.5rem mobile floor. The regression pin
  // (no band h2 equals the body font-size at any tested viewport) lives here.
  test('#436 all band headings share one size per breakpoint, never body-size, >=1.5rem @375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Band Heading Scale Equality');
    setComposition(pageId, STACK);

    for (const width of [375, 768, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('main .faq__heading').first()).toBeVisible({ timeout: 10000 });

      const bodySize = await page.evaluate(() =>
        parseFloat(getComputedStyle(document.body).fontSize),
      );
      const measured: Record<string, number> = {};
      for (const { band, sel } of HEADINGS) {
        const m = await headingMetrics(page, sel);
        expect(m.tag, `${band} heading is not an <h2> @${width}`).toBe('H2');
        measured[band] = m.size;
        // The regression that shipped the bug: a band h2 computing at body size.
        expect(m.size, `${band} h2 collapsed to body size @${width}: ${JSON.stringify(measured)}`).not.toBe(bodySize);
        // Mobile floor: never below 1.5rem (24px at the 16px root).
        if (width === 375) {
          expect(m.size, `${band} below 1.5rem floor @375: ${m.size}`).toBeGreaterThanOrEqual(24);
        }
      }
      // Cross-component equality: all band h2s are peers at this breakpoint (they
      // all resolve the same --pp-band-heading-size clamp when unset).
      const sizes = HEADINGS.map((h) => measured[h.band]);
      expect(new Set(sizes).size, `band heading size drift @${width}: ${JSON.stringify(measured)}`).toBe(1);
    }
  });

  // Slot contract preserved AND every newly-minted slot works end-to-end: the
  // existing slot (--cta-title-size) plus ALL FOUR slots first introduced by #436
  // (--table-section-heading-size, --logos-heading-size, --embed-heading-size,
  // --testimonials-heading-size) must each validate (styleComponent success) and
  // win over the shared scale at mobile AND desktop. This is the only render-level
  // proof that the fresh pp_render_style_vars wiring in table/logos/embed.php uses
  // the correct component-name string — a typo there would validate but never
  // reach the DOM. Pixel values no scale step resolves to, so a fallback leak is
  // unmistakable.
  test('#436 existing and all newly-minted heading slots override the shared scale at 375 and 1280', async ({
    page,
  }) => {
    pageId = createPage('E2E Band Heading Slot Override');
    setComposition(pageId, [
      { component: 'cta', props: { id: 'pp-cta01', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'table', props: { id: 'pp-tbl01', title: 'Table', headers: ['A', 'B'], rows: [['1', '2']] } },
      { component: 'logos', props: { id: 'pp-logo01', title: 'Logos', items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo' }] } },
      { component: 'embed', props: { id: 'pp-emb01', title: 'Embed', content: 'https://example.com/video' } },
      { component: 'testimonials', props: { id: 'pp-tst01', title: 'Testimonials', items: [{ quote: 'It works.', author: 'A' }] } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // index 0 = cta (existing slot); 1..4 = the slots minted in #436. Distinct px
    // per component so a cross-wired value would be caught.
    const overrides = [
      { idx: 0, slot: '--cta-title-size', px: '60px', sel: '.cta__title' },
      { idx: 1, slot: '--table-section-heading-size', px: '61px', sel: '.table-section__heading' },
      { idx: 2, slot: '--logos-heading-size', px: '62px', sel: '.logos__heading' },
      { idx: 3, slot: '--embed-heading-size', px: '63px', sel: '.embed__heading' },
      { idx: 4, slot: '--testimonials-heading-size', px: '64px', sel: '.testimonials__heading' },
    ];
    for (const o of overrides) {
      const r = await styleComponent(page, pageId, { [o.slot]: o.px }, undefined, o.idx);
      expect(r.success, `${o.slot} set: ${JSON.stringify(r)}`).toBe(true);
    }

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      for (const o of overrides) {
        const h = page.locator(`main ${o.sel}`).first();
        await expect(h).toBeVisible({ timeout: 10000 });
        expect(await h.evaluate((el) => getComputedStyle(el).fontSize), `${o.slot} @${width}`).toBe(o.px);
      }
    }
  });

  // Live-shape check (acceptance criterion): the measured webfiable.com defect
  // sequence (hero -> stats -> grid -> cta -> grid -> section -> cta) renders a
  // DISTINGUISHABLE heading hierarchy at 375w — every band h2 clearly larger than
  // body text and all peers equal, instead of the old 16px collapse where the
  // section and both CTA titles were indistinguishable from body copy. No faq in
  // this sequence, so bug 432 cannot interfere.
  test('#436 webfiable-shaped stack renders distinguishable band headings at 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Band Heading Webfiable Shape');
    setComposition(pageId, [
      { component: 'hero', props: { id: 'pp-hero01', title: 'Lead' } },
      { component: 'stats', props: { id: 'pp-stats01', theme: 'dark', title: 'Stats', items: [{ number: '10', label: 'Ten' }] } },
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'cta', props: { id: 'pp-cta01', theme: 'dark', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', title: 'Section', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', theme: 'dark', title: 'Closing', button_text: 'Go', button_url: '/go' } },
    ]);

    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-cta02 .cta__title')).toBeVisible({ timeout: 10000 });

    const bodySize = await page.evaluate(() => parseFloat(getComputedStyle(document.body).fontSize));
    // The band titles that used to collapse to 16px on this exact sequence.
    const titleSelectors = [
      '#pp-stats01 .stats__heading',
      '#pp-grid01 .grid__heading',
      '#pp-cta01 .cta__title',
      '#pp-grid02 .grid__heading',
      '#pp-sec01 .section__title',
      '#pp-cta02 .cta__title',
    ];
    const sizes: number[] = [];
    for (const sel of titleSelectors) {
      const size = await page.locator(sel).evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
      // Distinguishable from body copy (the bug rendered these AT body size).
      expect(size, `${sel} not distinguishable from body @375: ${size}`).toBeGreaterThan(bodySize);
      expect(size, `${sel} below 1.5rem floor @375: ${size}`).toBeGreaterThanOrEqual(24);
      sizes.push(size);
    }
    // Peers: all band h2s at the same structural level share one step.
    expect(new Set(sizes).size, `band heading drift on webfiable shape @375: ${JSON.stringify(sizes)}`).toBe(1);
  });
});

/**
 * #437 — inverted-band link contrast (rendered proof).
 *
 * The default light-surface accent (--color-accent #3157f4) measures only 3.23:1
 * on the dark inverted band (--color-bg-inverted #0f172a) and fails WCAG AA for
 * body text. This suite seeds a real link in every inverted variant that can render
 * one and asserts the COMPUTED contrast of the link against its actual rendered
 * background — the stronger, surface-aware check the css-lint structural test can't
 * make. Two truths must both hold, at 375 (mobile) and 1280 (desktop):
 *
 *   - Dark-band body links (section body, embed content) route through
 *     --color-accent-on-inverted (#9dafee, ~8.3:1) → AA (>= 4.5:1).
 *   - Light-card links (grid card link, faq answer on the light .faq__item) STAY on
 *     --color-accent (unchanged) → still AA on the light card (~4.7:1). A naive
 *     "remap every inverted variant" would have dropped these to ~2:1; this proves
 *     the surface-aware scope did not touch them.
 *
 *   - Inverted stats numbers (large accent text on the dark band) clear the 3:1
 *     large-text bar with the new default.
 *
 * Since #439, cta.text and testimonials.quote render an inline-HTML subset, so a
 * body link CAN now exist on them: the cta body link and the testimonials STACK
 * quote link both sit on the dark band and are seeded here (contrast >= 4.5). The
 * grid item-text link and the testimonials GRID quote stay on light cards (accent
 * unchanged) — the grid item-text case is seeded as 'staysAccent'. Structural
 * remaps are additionally pinned by tests/js/css-lint.test.js.
 */
test.describe('#437 inverted link contrast (rendered)', () => {
  let pageId = 0;

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

  // Two assertion modes:
  //  - 'contrast': compute the WCAG ratio against the link's rendered background and
  //    require >= minRatio. Used where the surface is a solid painted color the probe
  //    can read (the dark band; faq's solid light panel).
  //  - 'staysAccent': assert the link keeps the light-surface accent (rgb(49,87,244)),
  //    proving the surface-aware scope did NOT remap it. Used for grid, whose card
  //    background is a subtle GRADIENT (main > .grid:not(.grid--steps) .grid__item) —
  //    its computed background-color is transparent, so a color-vs-background probe
  //    can't see the light card and would misread the dark band behind it. Accent on
  //    the light card is a documented-AA pairing (~4.7:1); the regression risk this
  //    guards is the link being remapped to the light on-inverted tint (~2:1), which a
  //    color-identity check catches exactly.
  type Case = {
    name: string;
    composition: unknown[];
    linkSelector: string;
    mode: 'contrast' | 'staysAccent';
    minRatio?: number;
    openDetails?: boolean;
  };
  const ACCENT_RGB = [49, 87, 244]; // --color-accent #3157f4, default palette

  const cases: Case[] = [
    {
      name: 'section body link on the dark band → on-inverted (AA)',
      composition: [
        {
          component: 'section',
          props: {
            id: 'pp-sec01',
            theme: 'inverted',
            title: 'Inverted section',
            body: '<p>Body copy with an inline <a href="/somewhere">text link</a> to prove contrast.</p>',
          },
        },
      ],
      linkSelector: '.pp-section--inverted .section__content a',
      mode: 'contrast',
      minRatio: 4.5,
    },
    {
      name: 'embed content link on the dark band → on-inverted (AA)',
      composition: [
        {
          component: 'embed',
          props: {
            id: 'pp-embed01',
            theme: 'inverted',
            heading: 'Inverted embed',
            content: '<p>Embedded copy with an <a href="/somewhere">embed link</a>.</p>',
          },
        },
      ],
      linkSelector: '.embed--inverted a',
      mode: 'contrast',
      minRatio: 4.5,
    },
    {
      name: 'grid card link stays on --color-accent (light card, AA)',
      composition: [
        {
          component: 'grid',
          props: {
            id: 'pp-grid01',
            theme: 'inverted',
            title: 'Inverted grid',
            items: [
              { title: 'Card', text: 'Card body', link_url: '/somewhere', link_text: 'card link' },
            ],
          },
        },
      ],
      linkSelector: '.grid--inverted .grid__item-link',
      mode: 'staysAccent',
    },
    {
      name: 'faq answer link stays on --color-accent (light panel, AA)',
      composition: [
        {
          component: 'faq',
          props: {
            id: 'pp-faq01',
            theme: 'inverted',
            title: 'Inverted faq',
            items: [
              { question: 'Question?', answer: '<p>Answer with a <a href="/somewhere">faq link</a>.</p>' },
            ],
          },
        },
      ],
      linkSelector: '.faq--inverted .faq__answer a',
      mode: 'contrast',
      minRatio: 4.5,
      openDetails: true,
    },
    {
      name: 'stats number on the dark band clears the 3:1 large-text bar',
      composition: [
        {
          component: 'stats',
          props: {
            id: 'pp-stats01',
            theme: 'inverted',
            title: 'Inverted stats',
            items: [{ number: '42', label: 'Metric' }],
          },
        },
      ],
      linkSelector: '.stats--inverted .stats__number',
      mode: 'contrast',
      minRatio: 3.0,
    },
    {
      // #439: cta.text became an inline-HTML surface, so an inverted CTA can carry
      // a real body link sitting directly on the dark band. It must reach AA.
      name: 'cta body link on the dark band → on-inverted (AA)',
      composition: [
        {
          component: 'cta',
          props: {
            id: 'pp-cta01',
            theme: 'inverted',
            title: 'Inverted cta',
            text: 'Read our <a href="/terms">terms</a> before you sign up.',
            button_text: 'Get started',
            button_url: '/signup',
          },
        },
      ],
      linkSelector: '.cta--inverted .cta__body a',
      mode: 'contrast',
      minRatio: 4.5,
    },
    {
      // #439: testimonials.quote became an inline-HTML surface. In the STACK layout
      // the quote sits directly on the dark band (transparent card), so its link
      // must reach AA. (The GRID layout keeps a light card — covered below.)
      name: 'testimonials stack quote link on the dark band → on-inverted (AA)',
      composition: [
        {
          component: 'testimonials',
          props: {
            id: 'pp-tst01',
            theme: 'inverted',
            layout: 'stack',
            title: 'Inverted stack',
            items: [
              { quote: 'A great <a href="/case">case study</a> to read.', author: 'Ana' },
            ],
          },
        },
      ],
      linkSelector: '.testimonials--inverted.testimonials--stack .testimonials__quote a',
      mode: 'contrast',
      minRatio: 4.5,
    },
    {
      // #439: grid.items[].text became an inline-HTML surface, but the card stays a
      // LIGHT surface even on the inverted band, so its link must STAY on
      // --color-accent (already AA on the light card) — not be remapped to the dark
      // on-inverted tint (which would drop to ~2:1 on the light card).
      name: 'grid item-text link stays on --color-accent (light card, AA)',
      composition: [
        {
          component: 'grid',
          props: {
            id: 'pp-grid02',
            theme: 'inverted',
            title: 'Inverted grid text',
            items: [
              { title: 'Card', text: 'See the <a href="/docs">docs</a> for details.' },
            ],
          },
        },
      ],
      linkSelector: '.grid--inverted .grid__item-text a',
      mode: 'staysAccent',
    },
  ];

  for (const c of cases) {
    test(`${c.name} @375 + @1280`, async ({ page }) => {
      pageId = createPage(`E2E 437 ${c.name}`);
      setComposition(pageId, c.composition);

      for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        if (c.openDetails) {
          await page.locator('.faq__item').first().evaluate((el: HTMLDetailsElement) => {
            el.open = true;
          });
        }
        await expect(page.locator(c.linkSelector).first()).toBeVisible({ timeout: 10000 });
        // WCAG relative-luminance contrast of the link's computed text color against
        // its EFFECTIVE background — walk ancestors past transparent links/wrappers to
        // the first painted surface (the dark band or the light card).
        const res = await page.evaluate((selector) => {
          const parseRgb = (s: string): number[] => (s.match(/[\d.]+/g) || []).map(Number);
          const lum = (rgb: number[]): number => {
            const f = (v: number) => {
              v /= 255;
              return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
          };
          const el = document.querySelector(selector);
          if (!el) return { found: false, fg: [] as number[], bg: [] as number[], ratio: 0 };
          const fg = parseRgb(getComputedStyle(el).color);
          let node: Element | null = el;
          let bg: number[] | null = null;
          while (node) {
            const p = parseRgb(getComputedStyle(node).backgroundColor);
            // Opaque enough to be the painted surface (alpha undefined => opaque).
            if (p.length >= 3 && (p.length < 4 || p[3] > 0.5)) {
              bg = p;
              break;
            }
            node = node.parentElement;
          }
          if (!bg) bg = [255, 255, 255];
          const L1 = lum(fg);
          const L2 = lum(bg);
          const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
          return { found: true, fg, bg, ratio };
        }, c.linkSelector);
        expect(res.found, `${c.linkSelector} not found @${width}`).toBe(true);
        if (c.mode === 'staysAccent') {
          expect(
            res.fg,
            `${c.name} @${width}: link color ${JSON.stringify(res.fg)} was remapped off --color-accent ${JSON.stringify(ACCENT_RGB)} (light-card link must stay accent)`,
          ).toEqual(ACCENT_RGB);
        } else {
          expect(
            res.ratio,
            `${c.name} @${width}: fg=${JSON.stringify(res.fg)} bg=${JSON.stringify(res.bg)} ratio=${res.ratio?.toFixed(2)} (need >= ${c.minRatio})`,
          ).toBeGreaterThanOrEqual(c.minRatio as number);
        }
      }
    });
  }
});

test.describe('#461 bg-image band accent contrast (rendered)', () => {
  let pageId = 0;

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

  // A bg-image band lays a dark rgba(0,0,0,.55) overlay over an ARBITRARY image. The
  // WORST case for a light-tinted foreground is the overlay over a pure-WHITE image
  // (effective bg rgb(115,115,115)), where the contrast ceiling is 4.74:1 — so #461's
  // default accent (--color-accent-on-overlay #fafbff, 4.59:1) is near-white by
  // necessity. We seed each band with a WHITE background-image fixture. The overlay is
  // a SEPARATE absolutely-positioned element (a sibling of the link, NOT an ancestor),
  // so the link's own/ancestor background never carries the scrim: we read the rendered
  // overlay's real rgba() and composite it over white(255) analytically — that IS the
  // worst-case rendered composite, and it exercises each component's real overlay recipe.
  const WHITE_PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWP8//8/AwMDEwMDAwMDAwAkBgMBmjCi+wAAAABJRU5ErkJggg==';

  const bands = () => [
    {
      component: 'section',
      props: {
        id: 'pp-ov-sec',
        background_image: WHITE_PNG,
        title: 'Overlay section',
        body: '<p>Body copy with an inline <a href="/somewhere">text link</a> on the image band.</p>',
      },
    },
    {
      component: 'cta',
      props: {
        id: 'pp-ov-cta',
        background_image: WHITE_PNG,
        title: 'Overlay cta',
        text: 'Read our <a href="/terms">terms</a> before you sign up.',
        button_text: 'Go',
        button_url: '/signup',
      },
    },
    {
      component: 'stats',
      props: {
        id: 'pp-ov-stats',
        background_image: WHITE_PNG,
        title: 'Overlay stats',
        items: [{ number: '42', label: 'Metric' }],
      },
    },
  ];

  // Each accent surface + the overlay element whose rendered rgba() sits behind it.
  const SURFACES = [
    { name: 'section link', accent: '.section--has-bg-image .section__content a', overlay: '.section--has-bg-image .section__overlay' },
    { name: 'cta body link', accent: '.cta--has-bg-image .cta__body a', overlay: '.cta--has-bg-image .cta__overlay' },
    { name: 'stats number', accent: '.stats--has-bg-image .stats__number', overlay: '.stats--has-bg-image .stats__overlay' },
  ];

  test('all three bg-image accent surfaces clear AA (4.5:1) over the overlay-over-white worst case @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 461 overlay accent contrast');
    setComposition(pageId, bands());

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      for (const s of SURFACES) {
        await expect(page.locator(s.accent).first()).toBeVisible({ timeout: 10000 });

        const res = await page.evaluate(
          ({ accentSel, overlaySel }) => {
            const parseRgb = (str: string): number[] => (str.match(/[\d.]+/g) || []).map(Number);
            const lum = (rgb: number[]): number => {
              const f = (v: number) => {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
              };
              return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
            };
            const el = document.querySelector(accentSel);
            const ov = document.querySelector(overlaySel);
            if (!el || !ov) return { found: false, fg: [] as number[], comp: [] as number[], alpha: -1, ratio: 0 };
            const fg = parseRgb(getComputedStyle(el).color);
            const o = parseRgb(getComputedStyle(ov).backgroundColor); // rgba(r,g,b,a)
            const alpha = o.length >= 4 ? o[3] : 1;
            // Composite the rendered overlay over a pure-white image (the worst case).
            const comp = [0, 1, 2].map((i) => alpha * (o[i] ?? 0) + (1 - alpha) * 255);
            const L1 = lum(fg);
            const L2 = lum(comp);
            const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
            return { found: true, fg, comp, alpha, ratio };
          },
          { accentSel: s.accent, overlaySel: s.overlay },
        );

        expect(res.found, `${s.name} or its overlay not found @${width}`).toBe(true);
        // The overlay must actually be a translucent scrim (guards a vacuous pass if a
        // refactor made the overlay opaque or dropped its alpha).
        expect(res.alpha, `${s.name} @${width}: overlay alpha ${res.alpha} is not the expected translucent scrim`).toBeGreaterThan(0);
        expect(res.alpha).toBeLessThan(1);
        expect(
          res.ratio,
          `${s.name} @${width}: fg=${JSON.stringify(res.fg)} overlay-over-white=${JSON.stringify(res.comp)} ratio=${res.ratio?.toFixed(2)} (need >= 4.5)`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  test('a per-instance slot wins over the on-overlay default on every band @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 461 overlay slot wins');
    const SLOT = '#00e5ff'; // vivid cyan no token uses — a leak or clobber is obvious
    const b = bands();
    // Attach the per-instance style slot that each band's accent rule reads first.
    (b[0].props as Record<string, unknown>).__pp_style = { '--section-accent': SLOT };
    (b[1].props as Record<string, unknown>).__pp_style = { '--cta-body-color': SLOT };
    (b[2].props as Record<string, unknown>).__pp_style = { '--stats-number-color': SLOT };
    setComposition(pageId, b);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      for (const s of SURFACES) {
        await expect(page.locator(s.accent).first()).toBeVisible({ timeout: 10000 });
        const color = await page.evaluate((sel) => getComputedStyle(document.querySelector(sel)!).color, s.accent);
        expect(color, `${s.name} @${width}: per-instance slot must win over the on-overlay default`).toBe('rgb(0, 229, 255)');
      }
    }
  });
});

test.describe('#463 bg-image band title-accent + markers contrast (rendered)', () => {
  let pageId = 0;

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

  // #461 fixed links/numbers on the three bg-image bands. #463 closes the remaining
  // bare-accent surfaces on the same dark overlay-over-image bands: the accented title
  // substring (which paints its OWN color and does NOT inherit the near-white band
  // title, so it hit --color-accent at 1.16:1), the section body list markers, and
  // .hero--cover's title-accent (same --overlay-bg scrim idiom). Same worst-case method
  // as #461: seed a WHITE background-image, read the rendered overlay's real rgba() and
  // composite it over white(255) — the worst case for a light-tinted foreground.
  const WHITE_PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWP8//8/AwMDEwMDAwMDAwAkBgMBmjCi+wAAAABJRU5ErkJggg==';

  const bands = () => [
    {
      component: 'section',
      props: {
        id: 'pp-ov463-sec',
        background_image: WHITE_PNG,
        title: 'Overlay accent heading',
        title_accent: 'accent',
        body_marker: 'check',
        body: '<p>Body copy on the image band.</p><ul><li>First point</li><li>Second point</li></ul>',
      },
    },
    {
      component: 'cta',
      props: {
        id: 'pp-ov463-cta',
        background_image: WHITE_PNG,
        title: 'Overlay accent cta',
        title_accent: 'accent',
        text: 'Sign up before the deadline.',
        button_text: 'Go',
        button_url: '/signup',
      },
    },
    {
      component: 'stats',
      props: {
        id: 'pp-ov463-stats',
        background_image: WHITE_PNG,
        title: 'Overlay accent stats',
        title_accent: 'accent',
        items: [{ number: '42', label: 'Metric' }],
      },
    },
    {
      component: 'hero',
      props: {
        id: 'pp-ov463-hero',
        layout: 'cover',
        image_url: WHITE_PNG,
        title: 'Overlay accent hero',
        title_accent: 'accent',
      },
    },
  ];

  // Each accent surface: the selector, an optional ::before pseudo (list marker glyph),
  // the per-instance slot the rule reads first, and the overlay whose rgba() sits behind it.
  const SURFACES = [
    { name: 'section title-accent', accent: '.section--has-bg-image .section__title-accent', pseudo: '', slot: '--section-title-accent-color', overlay: '.section--has-bg-image .section__overlay' },
    { name: 'section list marker', accent: '.section--has-bg-image .section__content--marker-check > ul > li', pseudo: '::before', slot: '--section-body-marker-color', overlay: '.section--has-bg-image .section__overlay' },
    { name: 'cta title-accent', accent: '.cta--has-bg-image .cta__title-accent', pseudo: '', slot: '--cta-title-accent-color', overlay: '.cta--has-bg-image .cta__overlay' },
    { name: 'stats heading-accent', accent: '.stats--has-bg-image .stats__heading-accent', pseudo: '', slot: '--stats-title-accent-color', overlay: '.stats--has-bg-image .stats__overlay' },
    { name: 'hero cover title-accent', accent: '.hero--cover .hero__title-accent', pseudo: '', slot: '--hero-title-accent-color', overlay: '.hero--cover .hero__overlay' },
  ];

  test('every bg-image title-accent + marker clears AA (4.5:1) over the overlay-over-white worst case @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 463 overlay accent-span contrast');
    setComposition(pageId, bands());

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      for (const s of SURFACES) {
        await expect(page.locator(s.accent).first()).toBeVisible({ timeout: 10000 });

        const res = await page.evaluate(
          ({ accentSel, pseudo, overlaySel }) => {
            const parseRgb = (str: string): number[] => (str.match(/[\d.]+/g) || []).map(Number);
            const lum = (rgb: number[]): number => {
              const f = (v: number) => {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
              };
              return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
            };
            const el = document.querySelector(accentSel);
            const ov = document.querySelector(overlaySel);
            if (!el || !ov) return { found: false, fg: [] as number[], comp: [] as number[], alpha: -1, ratio: 0 };
            const fg = parseRgb(getComputedStyle(el, pseudo || undefined).color);
            const o = parseRgb(getComputedStyle(ov).backgroundColor); // rgba(r,g,b,a)
            const alpha = o.length >= 4 ? o[3] : 1;
            const comp = [0, 1, 2].map((i) => alpha * (o[i] ?? 0) + (1 - alpha) * 255);
            const L1 = lum(fg);
            const L2 = lum(comp);
            const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
            return { found: true, fg, comp, alpha, ratio };
          },
          { accentSel: s.accent, pseudo: s.pseudo, overlaySel: s.overlay },
        );

        expect(res.found, `${s.name} or its overlay not found @${width}`).toBe(true);
        // The overlay must actually be a translucent scrim (guards a vacuous pass).
        expect(res.alpha, `${s.name} @${width}: overlay alpha ${res.alpha} is not the expected translucent scrim`).toBeGreaterThan(0);
        expect(res.alpha).toBeLessThan(1);
        expect(
          res.ratio,
          `${s.name} @${width}: fg=${JSON.stringify(res.fg)} overlay-over-white=${JSON.stringify(res.comp)} ratio=${res.ratio?.toFixed(2)} (need >= 4.5)`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  test('a per-instance slot wins over the on-overlay default on every accent surface @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 463 overlay accent-span slot wins');
    const SLOT = '#00e5ff'; // vivid cyan no token uses — a leak or clobber is obvious
    const b = bands();
    // Attach the per-instance style slot each surface's rule reads first. section band
    // carries both its title-accent and its body-marker slot.
    (b[0].props as Record<string, unknown>).__pp_style = {
      '--section-title-accent-color': SLOT,
      '--section-body-marker-color': SLOT,
    };
    (b[1].props as Record<string, unknown>).__pp_style = { '--cta-title-accent-color': SLOT };
    (b[2].props as Record<string, unknown>).__pp_style = { '--stats-title-accent-color': SLOT };
    (b[3].props as Record<string, unknown>).__pp_style = { '--hero-title-accent-color': SLOT };
    setComposition(pageId, b);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      for (const s of SURFACES) {
        await expect(page.locator(s.accent).first()).toBeVisible({ timeout: 10000 });
        const color = await page.evaluate(
          ({ sel, pseudo }) => getComputedStyle(document.querySelector(sel)!, pseudo || undefined).color,
          { sel: s.accent, pseudo: s.pseudo },
        );
        expect(color, `${s.name} @${width}: per-instance slot must win over the on-overlay default`).toBe('rgb(0, 229, 255)');
      }
    }
  });
});

/*
 * #439 — a link in cta.text renders as a real anchor, not escaped source.
 *
 * Before #439 cta.text was esc_html, so `<a href=...>` written by the AI rendered
 * as visible source code in the CTA band. This proves the upgraded prop now emits a
 * working anchor and that no literal `<a` characters survive in the band's text.
 */
test.describe('#439 cta body link renders as an anchor (rendered)', () => {
  let pageId = 0;

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

  test('seeded cta link is clickable, and script/javascript: payloads are stripped on real WP', async ({ page }) => {
    pageId = createPage('E2E 439 cta link');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'pp-cta439',
          title: 'Sign up',
          // A legitimate link, plus hostile payloads that WordPress core wp_kses
          // (the production sanitizer, not the unit-test stub) must neutralize.
          text: 'Read our <a href="/terms">terms</a> first. <a href="javascript:alert(1)">x</a><script>alert(2)</script>',
          button_text: 'Get started',
          button_url: '/signup',
        },
      },
    ]);
    await page.goto(`/?page_id=${pageId}`);

    const anchor = page.locator('#pp-cta439 .cta__body a').first();
    await expect(anchor).toBeVisible({ timeout: 10000 });
    await expect(anchor).toHaveAttribute('href', '/terms');
    await expect(anchor).toHaveText('terms');

    // The band's visible text must NOT contain the literal escaped-source `<a`.
    const bandText = await page.locator('#pp-cta439 .cta__body').textContent();
    expect(bandText ?? '').not.toContain('<a');

    // Real-WP sanitization proof: no executable script tag and no javascript: URL
    // survive in the rendered band markup.
    const bandHtml = await page.locator('#pp-cta439 .cta__body').innerHTML();
    expect(bandHtml.toLowerCase()).not.toContain('<script');
    expect(bandHtml.toLowerCase()).not.toContain('javascript:');
  });
});

/*
 * #424 — inverted text-panel heading legibility (rendered proof).
 *
 * A `theme: inverted` + `layout: text-panel` section renders a LIGHT panel box on the
 * dark band. The panel heading is `<h3 class="section__panel-heading">`, whose own rule
 * routes color through --section-panel-text (the panel's dark text). But the inverted
 * band's `h3` rule (0,1,1) outranked it and painted the panel heading in the band's
 * LIGHT title color — light-on-light, invisible on the light panel, while the panel LIST
 * items (not headings) stayed dark and legible. The css-lint pin proves the carve-out
 * selector shape; only getComputedStyle after the full cascade proves the browser
 * actually renders the panel heading in the panel's dark text at BOTH breakpoints, and
 * that the two color slots stay independently authorable.
 */
test.describe('#424 inverted text-panel heading legibility (rendered)', () => {
  let pageId = 0;

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

  // One inverted text-panel section: an on-band title plus a panel with a heading and
  // list items. Reused by every case below (styled variants restyle component 0).
  const invertedTextPanel = (extra: Record<string, unknown> = {}) => [
    {
      component: 'section',
      props: {
        id: 'pp-sec01',
        theme: 'inverted',
        layout: 'text-panel',
        title: 'Included in every plan',
        panel_heading: 'Included, no exceptions',
        panel_items: ['First perk', 'Second perk', 'Third perk'],
        ...extra,
      },
    },
  ];

  // Computed `color` of the first match of a selector, as the browser resolves it.
  const colorOf = (page: any, selector: string) =>
    page.locator(selector).first().evaluate((el: Element) => getComputedStyle(el).color);

  // WCAG relative-luminance contrast of an element's text color against its first
  // painted (opaque) ancestor background — the light panel surface here.
  const contrastOf = (page: any, selector: string) =>
    page.evaluate((sel: string) => {
      const parseRgb = (s: string): number[] => (s.match(/[\d.]+/g) || []).map(Number);
      const lum = (rgb: number[]): number => {
        const f = (v: number) => {
          v /= 255;
          return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
      };
      const el = document.querySelector(sel);
      if (!el) return 0;
      const fg = parseRgb(getComputedStyle(el).color);
      let node: Element | null = el;
      let bg: number[] | null = null;
      while (node) {
        const p = parseRgb(getComputedStyle(node).backgroundColor);
        if (p.length >= 3 && (p.length < 4 || p[3] > 0.5)) {
          bg = p;
          break;
        }
        node = node.parentElement;
      }
      if (!bg) bg = [255, 255, 255];
      const L1 = lum(fg);
      const L2 = lum(bg);
      return (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    }, selector);

  // The regression itself: panel heading must render the panel's dark text (same color
  // as the panel list items) and NOT the light on-band title color, at both breakpoints.
  test('panel heading takes panel dark text, band title stays light @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 424 base');
    setComposition(pageId, invertedTextPanel());

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      await expect(page.locator('.section__panel-heading')).toBeVisible({ timeout: 10000 });

      const heading = await colorOf(page, '.section__panel-heading');
      const item = await colorOf(page, '.section__panel-item');
      const title = await colorOf(page, '.pp-section--inverted .section__title');
      const ratio = await contrastOf(page, '.section__panel-heading');

      // Heading routes through the SAME panel slot as the list items (both dark).
      expect(heading, `@${width}: panel heading ${heading} != panel item ${item}`).toBe(item);
      // Heading is NOT the light on-band title color (the exact pre-fix bug).
      expect(heading, `@${width}: panel heading ${heading} must differ from band title ${title}`).not.toBe(title);
      // And it is actually legible on the light panel.
      expect(ratio, `@${width}: panel heading contrast ${ratio.toFixed(2)} on the light panel`).toBeGreaterThanOrEqual(4.5);
    }
  });

  // Slot independence, half 1: an explicit --section-panel-text moves the panel heading
  // and must NOT bleed into the on-band title.
  test('--section-panel-text moves the panel heading only @375 + @1280', async ({ page }) => {
    pageId = createPage('E2E 424 panel-text slot');
    setComposition(pageId, invertedTextPanel());

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // A vivid color no theme token resolves to, so a leak is unmistakable.
    const res = await styleComponent(page, pageId, { '--section-panel-text': '#ff0080' });
    expect(res.success).toBe(true);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__panel-heading')).toBeVisible({ timeout: 10000 });

      const heading = await colorOf(page, '.section__panel-heading');
      const item = await colorOf(page, '.section__panel-item');
      const title = await colorOf(page, '.pp-section--inverted .section__title');
      expect(heading, `@${width}: panel heading should honor --section-panel-text`).toBe('rgb(255, 0, 128)');
      // The heading must move WITH the rest of the panel (the slot is the panel's,
      // not a heading-only override), so the list items track it too.
      expect(item, `@${width}: panel items should track the same --section-panel-text`).toBe('rgb(255, 0, 128)');
      expect(title, `@${width}: --section-panel-text must not bleed into the band title`).not.toBe('rgb(255, 0, 128)');
    }
  });

  // The parallel dark surface: a text-panel on a background-image section. The
  // .section--has-bg-image class is added whenever background_image is set
  // (independent of theme/layout), so its bare h2,h3 rule defeated the panel slot
  // exactly like the inverted band. The image itself need not load — the class,
  // overlay, and the panel's own opaque light surface are what drive the cascade.
  test('bg-image text-panel: panel heading takes panel dark text, band title stays light @375 + @1280', async ({
    page,
  }) => {
    pageId = createPage('E2E 424 bg-image');
    setComposition(pageId, invertedTextPanel({ theme: 'default', background_image: '/pp-424-probe.jpg' }));

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      await expect(page.locator('.section--has-bg-image .section__panel-heading')).toBeVisible({ timeout: 10000 });

      const heading = await colorOf(page, '.section--has-bg-image .section__panel-heading');
      const item = await colorOf(page, '.section--has-bg-image .section__panel-item');
      const title = await colorOf(page, '.section--has-bg-image .section__title');
      const ratio = await contrastOf(page, '.section--has-bg-image .section__panel-heading');

      expect(heading, `@${width}: bg-image panel heading ${heading} != panel item ${item}`).toBe(item);
      expect(heading, `@${width}: bg-image panel heading ${heading} must differ from band title ${title}`).not.toBe(title);
      expect(ratio, `@${width}: bg-image panel heading contrast ${ratio.toFixed(2)} on the light panel`).toBeGreaterThanOrEqual(4.5);
    }
  });

  // Slot independence, half 2: an explicit --section-title-color moves the on-band title
  // and must NOT reach into the self-contained panel heading.
  test('--section-title-color moves the band title only @375 + @1280', async ({ page }) => {
    pageId = createPage('E2E 424 title-color slot');
    setComposition(pageId, invertedTextPanel());

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--section-title-color': '#00e5ff' });
    expect(res.success).toBe(true);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__panel-heading')).toBeVisible({ timeout: 10000 });

      const title = await colorOf(page, '.pp-section--inverted .section__title');
      const heading = await colorOf(page, '.section__panel-heading');
      expect(title, `@${width}: band title should honor --section-title-color`).toBe('rgb(0, 229, 255)');
      expect(heading, `@${width}: --section-title-color must not reach the panel heading`).not.toBe('rgb(0, 229, 255)');
    }
  });
});
