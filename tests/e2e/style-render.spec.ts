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

  // The `theme` enum value must PREDICT the rendered band background. The static
  // schema/helper tests prove the class mapping; only getComputedStyle proves the CSS
  // cascade actually paints it. Seed one band per theme value and assert the computed
  // background-color matches the documented meaning:
  //   default  -> transparent (page background shows through)
  //   muted    -> --color-surface (#f4f7fb) — the LIGHT tinted band
  //   inverted -> --color-bg-inverted (#0f172a) — the genuinely dark band
  //
  // The fourth band is the STORED-BYTES route (#605). `dark` is no longer an accepted
  // input value, so this band is seeded directly into composition meta as a page
  // written before the removal holds it — the write path would now reject it. It must
  // paint as DEFAULT, not as muted: the deliberate stale-data breakage, proven where
  // it actually matters, in a real browser's computed cascade.
  test('theme values render the documented band background; a stored `dark` renders default', async ({ page }) => {
    pageId = createPage('E2E Theme Band Backgrounds');
    setComposition(pageId, [
      { component: 'grid', props: { id: 'pp-theme-default', theme: 'default', items: [{ title: 'D', text: 'x' }] } },
      { component: 'grid', props: { id: 'pp-theme-muted', theme: 'muted', items: [{ title: 'M', text: 'x' }] } },
      // Stale storage only — never writable through create_page/update_component.
      { component: 'grid', props: { id: 'pp-theme-stale-dark', theme: 'dark', items: [{ title: 'K', text: 'x' }] } },
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
    const staleDark = await bg('#pp-theme-stale-dark');
    expect(muted).toBe(SURFACE);
    // `muted` is a LIGHT band, not dark — and it still paints through the legacy
    // `--dark` CSS class name, which #605 deliberately kept (#570 DG-4).
    expect(muted).not.toBe(INVERTED);
    // #605: a stored `dark` no longer renders as muted. It renders as DEFAULT.
    expect(staleDark).toBe(TRANSPARENT);
    expect(staleDark).not.toBe(SURFACE);
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

  // #473: the steps badge NUMERAL color is authorable via --grid-step-text-color,
  // separate from --grid-step-bg (the fill). Before #473 the numeral was a
  // hardcoded `color: var(--color-bg)`, so a light fill (the issue's lime badge)
  // forced a low-contrast light numeral with no way to set ink. The default is
  // var(--color-bg), so an UNSET card must render byte-identically to an EXPLICIT
  // var(--color-bg). Three step cards prove both halves in one render:
  //   card 0 — per-card light lime fill (--grid-step-bg) + per-card ink
  //            --grid-step-text-color: the issue's exact case. Numeral must be ink,
  //            badge fill must be the lime (proves the two slots are independent).
  //            Both slots are item-eligible, so they ride on the card's own `style`.
  //   card 1 — UNSET numeral: must render byte-identically to card 2.
  //   card 2 — explicit --grid-step-text-color: var(--color-bg): the resolved default.
  // The numeral has NO breakpoint-specific color rule (only size changes at <=767px),
  // but per the #86/#349 mobile-hid-it lesson we still assert at 1280 AND 375.
  test('#473 steps badge numeral honors --grid-step-text-color; unset is byte-identical @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Step Numeral Color Slot');
    const INK = '#101010'; // ink numeral for the light-fill badge
    const LIME = '#93c22a'; // the issue's light brand-green fill
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid01',
          title: 'How it works',
          layout: 'steps',
          items: [
            { title: 'Ink on lime', number: '1', style: { '--grid-step-bg': LIME, '--grid-step-text-color': INK } },
            { title: 'Unset', number: '2' },
            { title: 'Explicit default', number: '3', style: { '--grid-step-text-color': 'var(--color-bg)' } },
          ],
        },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const numeral = (i: number) => page.locator('.grid__item').nth(i).locator('.grid__step-number');
    const numeralColor = (i: number) =>
      numeral(i).evaluate((el) => getComputedStyle(el).color);
    const numeralFill = (i: number) =>
      numeral(i).evaluate((el) => getComputedStyle(el).backgroundColor);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.grid__step-number')).toHaveCount(3, { timeout: 10000 });

      // The set slot reaches the numeral at BOTH breakpoints — the issue's case.
      expect(await numeralColor(0)).toBe('rgb(16, 16, 16)'); // ink numeral
      // The fill slot is independent of the numeral slot — the per-card
      // --grid-step-bg keeps the badge lime.
      expect(await numeralFill(0)).toBe('rgb(147, 194, 42)');

      // Unset numeral renders byte-identically to an explicit var(--color-bg):
      // #473 changed NO default. (Compared to the resolved default, not a literal,
      // so this holds whatever theme --color-bg resolves to.)
      const unset = await numeralColor(1);
      const explicitDefault = await numeralColor(2);
      expect(unset).toBe(explicitDefault);
      // And the default is NOT the ink slot value — the slot genuinely changed card 0.
      expect(unset).not.toBe('rgb(16, 16, 16)');
    }
  });

  // #475: section.body_items renders a row of short items with a CSS-generated
  // `li::before` middot separator (on EVERY item since #489's hanging-separator
  // clip; the 2nd item read below is a mid-line item whose separator is visible).
  // The separator color routes
  // through --section-separator-color (default --color-muted); on the inverted band
  // the default follows the light on-inverted text like sibling text (--color-muted
  // is remapped to --color-bg there). PHP/CSS pins prove the routing declarations;
  // only getComputedStyle('::before') proves the browser paints the middot the right
  // color once the cascade applies. The items also inherit the #470 body type slots,
  // so the brand strip (15px/600 + a lime middot) is fully expressible. Per the
  // #86/#349 mobile-hid-it lesson, assert at 1280 AND 375.
  test('#475 body_items separator honors --section-separator-color + inverted routing + brand type @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Inline Items Separator');
    const LIME = '#84cc16'; // rgb(132, 204, 22) — the brand strip's colored middot
    setComposition(pageId, [
      // 0: default separator (unset slot) — muted default.
      { component: 'section', props: { id: 'pp-sec-default', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] } },
      // 1: explicit var(--color-muted) — must be byte-identical to the unset default.
      { component: 'section', props: { id: 'pp-sec-explicit', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] }, style: { '--section-separator-color': 'var(--color-muted)' } },
      // 2: overridden separator color — the slot genuinely changes the middot.
      { component: 'section', props: { id: 'pp-sec-override', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] }, style: { '--section-separator-color': LIME } },
      // 3: inverted band, unset separator — routes like sibling text (light).
      { component: 'section', props: { id: 'pp-sec-inverted', theme: 'inverted', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] } },
      // 4: the brand strip — 15px/600 body type + a lime middot.
      { component: 'section', props: { id: 'pp-sec-brand', body: '<p>Body.</p>', body_items: ['One', 'Two', 'Three'] }, style: { '--section-body-size': '15px', '--section-body-weight': '600', '--section-separator-color': LIME } },
    ]);

    // The 2nd <li> is a mid-line item; its ::before separator is visible (not
    // clipped). Read its ::before color.
    const sepColor = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(1).evaluate((el) => getComputedStyle(el, '::before').color);
    const itemColor = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(1).evaluate((el) => getComputedStyle(el).color);
    const itemType = (id: string) =>
      page.locator(`#${id} .section__inline-item`).nth(0).evaluate((el) => {
        const cs = getComputedStyle(el);
        return { size: cs.fontSize, weight: cs.fontWeight };
      });

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__inline-items')).toHaveCount(5, { timeout: 10000 });

      // Default separator == explicit var(--color-muted): #475 changed no default.
      const def = await sepColor('pp-sec-default');
      const explicit = await sepColor('pp-sec-explicit');
      expect(def).toBe(explicit);

      // The override slot genuinely repaints the middot lime, and differs from muted.
      expect(await sepColor('pp-sec-override')).toBe('rgb(132, 204, 22)');
      expect(await sepColor('pp-sec-override')).not.toBe(def);

      // Inverted routing: the separator follows the light sibling text (both resolve
      // through the band's remapped --color-muted → --color-bg), and it is NOT the
      // dark default muted the light bands paint.
      const invSep = await sepColor('pp-sec-inverted');
      expect(invSep).toBe(await itemColor('pp-sec-inverted'));
      expect(invSep).not.toBe(def);

      // Brand strip: the items inherit the #470 body type slots (15px / 600) and the
      // separator is lime — the full original symptom, expressible with zero new
      // typography slots.
      const brand = await itemType('pp-sec-brand');
      expect(brand.size).toBe('15px');
      expect(brand.weight).toBe('600');
      expect(await sepColor('pp-sec-brand')).toBe('rgb(132, 204, 22)');
    }
  });

  // #475: the row is a flex-wrap row — its responsive behavior is wrapping to
  // additional rows as the space it is given shrinks, with no mobile-specific rule.
  //
  // EXPECTATION CORRECTED (#696, 2026-08-17). The previous version of this test
  // asserted `lineCount() === 1` at 1280 for a SIX-item fixture, on the premise that
  // VIEWPORT width governs how many items fit on a line. That premise is false, and
  // was false when it was written: the row is a sibling of .section__content inside
  // .section__body (components/section/section.php), and on the DEFAULT `text-only`
  // layout .section__body carries `max-width: var(--section-body-measure, 40rem)` =
  // 640px (assets/css/components.css, the outer-cap routing added by issue 302, which
  // PREDATES #475). So the space the row gets is the section's PROSE MEASURE, not the
  // viewport — identical at 1280, 1400 and 1600 — and six items of that length need
  // ~878px (measured), which cannot fit 640px at any desktop width.
  //
  //     All five bars share one scale (~46px per character):
  //
  //     viewport 1280         ────────────────────────────  1280px
  //     .container content    ────────────────────────       1120px (72rem - 2x16 pad)
  //     .section__body        ──────────────                  640px <- the cap (issue 302)
  //     six-item row needs    ───────────────────             ~878px => 2 lines, always
  //     three-item row needs  ──────────                      ~445px => 1 line
  //
  // SCOPE OF THAT CLAIM, stated precisely so this comment cannot be read as a law
  // about the component: 640px is the DEFAULT text-only measure. `.section--centered
  // .section__body` resolves to var(--measure-centered) = 56rem = 896px at higher
  // specificity, and --section-body-measure is an authorable slot — so under the
  // centered layout, or with the slot widened, six items of this length WOULD fit one
  // line at desktop. The old expectation was wrong for the fixture it shipped with
  // (default layout + six LONG items), not because no desktop width can ever fit six.
  //
  // It never passed anywhere: the full suite runs only on the nightly schedule (PR CI
  // runs the @smoke subset and this test is not @smoke-tagged), and the first nightly
  // after #475 landed — 2026-07-23, run 29989316461 — went red on this exact test and
  // stayed red for 25 consecutive nights.
  //
  // The PRODUCT is right and unchanged. Wrapping is the recorded, designed behavior:
  // components/section/schema.json describes --section-inline-items-align as the
  // "per-line alignment of the body_items row WHEN IT WRAPS", #489 exists solely to
  // clip separators on wrapped lines, #510 solely to centre them, and the schema
  // permits 8 items x 80 chars, which no 640px measure could hold on one line.
  // What #475 actually asked for was "a centered single-row band of 4-6 SHORT items";
  // the old fixture used six LONG ones. Both cases are pinned below.
  test('#475 body_items row line count is governed by the section body measure, not by viewport width alone', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Inline Items Wrap');
    setComposition(pageId, [
      // A — #475's actual reported case: short items that FIT the 640px body measure.
      // Measured need ~445px (diagnostic, not asserted), so ~44% headroom at desktop
      // and ~55% over the 288px measure a 320px viewport leaves.
      {
        component: 'section',
        props: {
          id: 'pp-sec-fits',
          body: '<p>Body.</p>',
          body_items: ['No credit card required', 'Thirty day guarantee', 'Ships worldwide'],
        },
      },
      // B — the original six-item fixture, kept for continuity with #475. Measured
      // need ~878px (diagnostic, not asserted): it exceeds the body measure, so it
      // wraps at EVERY width, and wraps further as the measure shrinks.
      {
        component: 'section',
        props: {
          id: 'pp-sec-wrap',
          body: '<p>Body.</p>',
          body_items: [
            'No credit card required',
            'Cancel anytime',
            'Thirty day guarantee',
            'Priority support included',
            'Unlimited seats',
            'Ships worldwide',
          ],
        },
      },
    ]);

    // Distinct rounded top offsets = distinct flex lines.
    const lineCount = (id: string) =>
      page.locator(`#${id} .section__inline-item`).evaluateAll((els) => {
        const tops = new Set(els.map((el) => Math.round(el.getBoundingClientRect().top)));
        return tops.size;
      });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-fits .section__inline-item')).toHaveCount(3, { timeout: 10000 });
    await expect(page.locator('#pp-sec-wrap .section__inline-item')).toHaveCount(6, { timeout: 10000 });

    // The cap that decides every line count below, asserted as COMPUTED CSS rather
    // than a rendered width. If this ever moves, the expectations below must be
    // re-derived rather than patched — that is the mistake #696 cleaned up. Resolved
    // against the root font size instead of a hardcoded "640px": the value is 40rem,
    // and this file already resolves rems that way (see the #470 body-size pin).
    const measure = async () =>
      page
        .locator('#pp-sec-wrap .section__body')
        .first()
        .evaluate((el) => {
          const rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize);
          return { maxWidth: getComputedStyle(el).maxWidth, expected: `${40 * rootPx}px` };
        });
    const cap = await measure();
    expect(cap.maxWidth, 'section body measure (40rem, issue 302) governs the row width').toBe(
      cap.expected,
    );

    // Short items fit the measure: one line at desktop — #475's reported case.
    expect(await lineCount('pp-sec-fits')).toBe(1);
    // Long items exceed the measure: they wrap at desktop too. Left as an inequality
    // on purpose. An exact count here would look stronger and buy nothing: breaking
    // the #489 per-item negative-margin cancellation adds only --space-sm + --space-xs
    // (12px) per item, which still packs this row into 2 lines against the 640px cap,
    // and that cancellation is already pinned to 0.5px by the @smoke #489 test below.
    // The load-bearing assertion is the 1600 comparison that follows.
    const wrapAtDesktop = await lineCount('pp-sec-wrap');
    expect(wrapAtDesktop).toBeGreaterThan(1);

    // The row stays block-centred under its measure at desktop. #475's requirement is
    // a CENTERED strip, and the two tests that pin that geometry (#489 and #510) both
    // loop [768, 375, 320] — so without this, a rule scoped to a >=1024px media query
    // could left-pin every desktop trust strip with all line counts still green.
    const centring = await page.locator('#pp-sec-fits .section__inline-items').evaluate((ul) => {
      const r = ul.getBoundingClientRect();
      const p = ul.parentElement as HTMLElement;
      const pr = p.getBoundingClientRect();
      const pcs = getComputedStyle(p);
      return {
        ulCenter: (r.left + r.right) / 2,
        parentCenter:
          (pr.left + parseFloat(pcs.paddingLeft) + pr.right - parseFloat(pcs.paddingRight)) / 2,
      };
    });
    expect(Math.abs(centring.ulCenter - centring.parentCenter)).toBeLessThanOrEqual(1.5);

    // SAME measure, DIFFERENT viewport. This is the assertion that would have caught
    // the original mistake at authoring time: .container maxes out at --max-width
    // (72rem) so .section__body is still capped at 640px here, and both row line
    // counts must be byte-identical to 1280 even though the viewport grew 320px. Every
    // other width below moves the measure AND the viewport together, so this step is
    // the only one that isolates "the viewport is not what governs this".
    await page.setViewportSize({ width: 1600, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-wrap .section__inline-item')).toHaveCount(6, { timeout: 10000 });
    const capWide = await measure();
    expect(capWide.maxWidth, 'the measure does not widen with the viewport').toBe(
      capWide.expected,
    );
    expect(await lineCount('pp-sec-fits')).toBe(1);
    expect(await lineCount('pp-sec-wrap')).toBe(wrapAtDesktop);

    // Narrower viewport = narrower measure = more lines, with no mobile-specific rule.
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-wrap .section__inline-item')).toHaveCount(6, { timeout: 10000 });
    expect(await lineCount('pp-sec-wrap')).toBeGreaterThan(wrapAtDesktop);

    // Even the short row wraps once the measure gets narrow enough — same markup,
    // same rule. Asserted at 320 (the narrowest width this file exercises) so the
    // margin is structural rather than font-metric-dependent.
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-fits .section__inline-item')).toHaveCount(3, { timeout: 10000 });
    expect(await lineCount('pp-sec-fits')).toBeGreaterThan(1);
  });

  // #489: before this fix the separator was a `li + li::before` glyph, so a
  // wrapped line whose leading item was not the row's first item still painted a
  // middot — a stray "·" dangling in the left margin at the start of every wrapped
  // line (the live prod mobile-homepage defect). The fix is a hanging-separator
  // clip: the separator is on EVERY item's `::before`, each item is pulled left by
  // exactly the separator's occupied width, and the row `overflow: hidden` clips
  // whatever lands left of its content box. So on the FIRST item of every visual
  // line — the row's first item AND the first item of each wrapped line — the
  // separator falls entirely outside the box and is never painted; the item text
  // lands exactly at the content edge. A mid-line item keeps its visible "·".
  //
  // Computed-style alone can't prove a glyph isn't painted, so this asserts the
  // GEOMETRY that makes the clip exact and total: (1) the per-item left pull equals
  // the `::before`'s occupied width (box width + right margin) to the pixel, so the
  // separator lands exactly at the content edge and no item text is clipped; (2)
  // every line-leading item's border box hangs left of the ul content box by that
  // pull, i.e. its separator sits wholly inside the clipped region; (3) the row is
  // overflow:hidden. Verified at 320/375/768 with a fixture that actually wraps to
  // multiple lines at mobile. Screenshots of the stressed wrapped state are captured
  // as CI artifacts. The single-line case stays block-centered (checked separately).
  test('#489 body_items separator never dangles at the start of a wrapped line @smoke', async ({
    page,
  }, testInfo) => {
    pageId = createPage('E2E Section Inline Items Clip');
    setComposition(pageId, [
      // A strip long enough to wrap to 2-3 lines at mobile widths. body is present
      // (a body_items-only band is a separate concern, #488) so this exercises only
      // the separator clip.
      {
        component: 'section',
        props: {
          id: 'pp-sec-clip',
          body: '<p>Body.</p>',
          body_items: [
            'Recuperación incluida',
            'Copias diarias',
            'Sin permanencia',
            'Soporte en español',
            '99,9% de disponibilidad',
          ],
        },
      },
      // A short strip that fits one line even at 320 — proves the non-wrapping row
      // is still centered as a block (the common desktop trust-strip look).
      {
        component: 'section',
        props: { id: 'pp-sec-oneline', body: '<p>Body.</p>', body_items: ['Rápido', 'Seguro', 'Fiable'] },
      },
    ]);

    // Geometry of one row: computed pull, the ::before's occupied width, the ul's
    // content-left, and each item's border-box left grouped into visual lines.
    // NB: the section id is on the <section>; the row is the descendant
    // <ul class="section__inline-items">. Target the ul, not the section.
    const geom = (rowId: string) =>
      page.locator(`#${rowId} .section__inline-items`).evaluate((ul: HTMLElement) => {
        const cs = getComputedStyle(ul);
        const first = ul.querySelector('li') as HTMLElement;
        const before = getComputedStyle(first, '::before');
        const items = Array.from(ul.querySelectorAll('li')) as HTMLElement[];
        const ulRect = ul.getBoundingClientRect();
        return {
          overflow: cs.overflowX,
          pull: parseFloat(getComputedStyle(first).marginLeft), // negative
          beWidth: parseFloat(before.width),
          beMarginRight: parseFloat(before.marginRight),
          ulContentLeft: ulRect.left + parseFloat(cs.paddingLeft),
          ulCenter: (ulRect.left + ulRect.right) / 2,
          parentCenter: (() => {
            const p = ul.parentElement as HTMLElement;
            const pr = p.getBoundingClientRect();
            const pcs = getComputedStyle(p);
            return (pr.left + parseFloat(pcs.paddingLeft) + pr.right - parseFloat(pcs.paddingRight)) / 2;
          })(),
          rows: items.map((li) => {
            const r = li.getBoundingClientRect();
            return { left: r.left, top: Math.round(r.top) };
          }),
        };
      });

    for (const width of [768, 375, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-clip .section__inline-item')).toHaveCount(5, { timeout: 10000 });

      const g = await geom('pp-sec-clip');

      // (3) the clip surface is active.
      expect(g.overflow).toBe('hidden');

      // (1) the pull equals the separator's occupied width to the pixel: the
      // separator lands exactly at the content edge — no dangling sliver, no clipped
      // item text.
      const occupied = g.beWidth + g.beMarginRight;
      expect(g.pull).toBeLessThan(0);
      expect(Math.abs(-g.pull - occupied)).toBeLessThanOrEqual(0.5);

      // (2) group items into visual lines by rounded top; the leading item of EVERY
      // line hangs left of the ul content box by exactly the pull (its ::before is
      // inside the clipped region), while non-leading items sit at/right of the
      // content edge (their ::before is painted between two items).
      const byTop = new Map<number, { left: number }[]>();
      for (const it of g.rows) {
        const arr = byTop.get(it.top) ?? [];
        arr.push(it);
        byTop.set(it.top, arr);
      }
      for (const [, lineItems] of byTop) {
        const leader = lineItems.reduce((a, b) => (a.left < b.left ? a : b));
        // leader hangs into the clip zone: left ≈ ulContentLeft - |pull|
        expect(Math.abs(leader.left - (g.ulContentLeft + g.pull))).toBeLessThanOrEqual(1.5);
        // every other item on the line starts at/after the content edge (separator visible)
        for (const it of lineItems) {
          if (it === leader) continue;
          expect(it.left).toBeGreaterThan(g.ulContentLeft - 0.5);
        }
      }

      // At mobile the strip must actually wrap — otherwise this test never exercises
      // the leading-separator edge it exists to guard.
      if (width <= 375) {
        expect(byTop.size).toBeGreaterThan(1);
      }

      // The short strip fits one line and stays centered as a block.
      const one = await geom('pp-sec-oneline');
      expect(Math.abs(one.ulCenter - one.parentCenter)).toBeLessThanOrEqual(1.5);

      await testInfo.attach(`inline-clip-${width}`, {
        body: await page.locator('#pp-sec-clip').screenshot(),
        contentType: 'image/png',
      });
    }
  });

  // #510: the --section-inline-items-align style slot ('start' | 'center', default
  // 'start') gives the author a lever over the wrap alignment. 'start' keeps the
  // #489 hanging-clip left-packing (its own test above). 'center' switches to
  // per-line centering with a TRAILING separator (li:not(:last-child)::after): the
  // leading ::before is suppressed (content: none) so a wrapped line NEVER opens
  // with a dangling middot, and every wrapped line is centered. The documented
  // trade is that a trailing middot at a wrap point stays visible (a centered line
  // ends mid-box, so it cannot be edge-clipped) — that is accepted, not a bug.
  //
  // This proves the two guarantees computed-style can prove: (1) NO ::before dot on
  // any item in center mode (content: none), while a mid-line item DOES paint a
  // trailing ::after middot; (2) every visual line is centered — the left gap
  // (first item to the ul's left edge) equals the right gap (ul's right edge to the
  // last item) to within a pixel or two. Verified at wrapped mobile widths where
  // the row breaks to 2-3 lines, plus a single-line row that stays block-centered.
  test('#510 body_items center alignment centers wrapped lines with no leading separator @smoke', async ({
    page,
  }, testInfo) => {
    pageId = createPage('E2E Section Inline Items Center');
    setComposition(pageId, [
      // A centered strip long enough to wrap to 2-3 lines at mobile. Top-level
      // `style` sets the align slot (component-level style, not props.style).
      {
        component: 'section',
        props: {
          id: 'pp-sec-center',
          body: '<p>Body.</p>',
          body_items: [
            'Recuperación incluida',
            'Copias diarias',
            'Sin permanencia',
            'Soporte en español',
            '99,9% de disponibilidad',
          ],
        },
        style: { '--section-inline-items-align': 'center' },
      },
      // A short centered strip that fits one line even at 320 — still block-centered.
      {
        component: 'section',
        props: { id: 'pp-sec-center-oneline', body: '<p>Body.</p>', body_items: ['Rápido', 'Seguro', 'Fiable'] },
        style: { '--section-inline-items-align': 'center' },
      },
    ]);

    const geom = (rowId: string) =>
      page.locator(`#${rowId} .section__inline-items`).evaluate((ul: HTMLElement) => {
        const items = Array.from(ul.querySelectorAll('li')) as HTMLElement[];
        const ulRect = ul.getBoundingClientRect();
        const first = items[0];
        // A mid-line item (2nd) reads its trailing ::after; every item reads ::before.
        const mid = items[1] ?? items[0];
        return {
          justify: getComputedStyle(ul).justifyContent,
          ulLeft: ulRect.left,
          ulRight: ulRect.right,
          ulCenter: (ulRect.left + ulRect.right) / 2,
          parentCenter: (() => {
            const p = ul.parentElement as HTMLElement;
            const pr = p.getBoundingClientRect();
            const pcs = getComputedStyle(p);
            return (pr.left + parseFloat(pcs.paddingLeft) + pr.right - parseFloat(pcs.paddingRight)) / 2;
          })(),
          // ::before must be gone on every item; ::after must paint on a mid item.
          beforeContent: getComputedStyle(first, '::before').content,
          afterContent: getComputedStyle(mid, '::after').content,
          rows: items.map((li) => {
            const r = li.getBoundingClientRect();
            return { left: r.left, right: r.right, top: Math.round(r.top) };
          }),
        };
      });

    for (const width of [768, 375, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-center .section__inline-item')).toHaveCount(5, { timeout: 10000 });

      const g = await geom('pp-sec-center');

      // The slot drives justify-content to center.
      expect(g.justify).toBe('center');

      // (1) No leading separator in center mode: ::before is suppressed everywhere,
      // so a wrapped line can never open with a dangling middot. The trailing
      // ::after still paints the middot on a non-last item.
      expect(g.beforeContent === 'none' || g.beforeContent === 'normal').toBe(true);
      expect(g.afterContent).toContain('·');

      // (2) Every visual line is centered: group items into lines by rounded top,
      // then the left gap (line's first item to ul left edge) equals the right gap
      // (ul right edge to line's last item) to within ~2px.
      const byTop = new Map<number, { left: number; right: number }[]>();
      for (const it of g.rows) {
        const arr = byTop.get(it.top) ?? [];
        arr.push(it);
        byTop.set(it.top, arr);
      }
      for (const [, lineItems] of byTop) {
        const firstLeft = Math.min(...lineItems.map((i) => i.left));
        const lastRight = Math.max(...lineItems.map((i) => i.right));
        const leftGap = firstLeft - g.ulLeft;
        const rightGap = g.ulRight - lastRight;
        expect(Math.abs(leftGap - rightGap)).toBeLessThanOrEqual(2);
      }

      // At mobile the strip must actually wrap, or the centered-wrap edge is untested.
      if (width <= 375) {
        expect(byTop.size).toBeGreaterThan(1);
      }

      // The short strip fits one line and stays centered as a block.
      const one = await geom('pp-sec-center-oneline');
      expect(Math.abs(one.ulCenter - one.parentCenter)).toBeLessThanOrEqual(1.5);

      await testInfo.attach(`inline-center-${width}`, {
        body: await page.locator('#pp-sec-center').screenshot(),
        contentType: 'image/png',
      });
    }
  });

  // #488: a body_items-only band (no body copy) is a first-class strip. Its
  // top margin — a body-relative separation — zeroes so the band's symmetric
  // padding centers the row, while a strip WITH body copy keeps var(--space-md).
  // Computed-style pins (0 vs 16px) at both viewports, plus a body-less WRAPPING
  // strip to prove the #489 hanging-separator clip still holds without a body.
  test('#488 body-less body_items strip zeroes its top margin and keeps the #489 clip @smoke', async ({
    page,
  }, testInfo) => {
    pageId = createPage('E2E Section Body-less Strip');
    setComposition(pageId, [
      // A body-less strip: no body key, body_items only. This is the exact shape
      // #488 reports as previously unauthorable (it needed a body:"" placeholder).
      {
        component: 'section',
        props: { id: 'pp-sec-bodyless', theme: 'inverted', body_items: ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'] },
      },
      // A strip WITH body copy: keeps the base var(--space-md) top margin.
      {
        component: 'section',
        props: { id: 'pp-sec-withbody', body: '<p>Everything you need to launch.</p>', body_items: ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'] },
      },
      // A body-less strip long enough to wrap at mobile: the #489 clip must still
      // hide the line-leading separator on every wrapped line even with flush-top.
      {
        component: 'section',
        props: {
          id: 'pp-sec-bodyless-wrap',
          theme: 'inverted',
          body_items: ['Recuperación incluida', 'Copias diarias', 'Sin permanencia', 'Soporte en español', '99,9% de disponibilidad'],
        },
      },
    ]);

    const marginTop = (rowId: string) =>
      page.locator(`#${rowId} .section__inline-items`).evaluate(
        (ul: HTMLElement) => parseFloat(getComputedStyle(ul).marginTop),
      );

    const wrapGeom = (rowId: string) =>
      page.locator(`#${rowId} .section__inline-items`).evaluate((ul: HTMLElement) => {
        const cs = getComputedStyle(ul);
        const first = ul.querySelector('li') as HTMLElement;
        const items = Array.from(ul.querySelectorAll('li')) as HTMLElement[];
        const ulRect = ul.getBoundingClientRect();
        return {
          pull: parseFloat(getComputedStyle(first).marginLeft),
          ulContentLeft: ulRect.left + parseFloat(cs.paddingLeft),
          rows: items.map((li) => {
            const r = li.getBoundingClientRect();
            return { left: r.left, top: Math.round(r.top) };
          }),
        };
      });

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-bodyless .section__inline-item')).toHaveCount(3, { timeout: 10000 });

      // Core #488 assertion: body-less strip zeroes its top margin; a strip WITH
      // body keeps var(--space-md) (16px). Same at every width.
      expect(await marginTop('pp-sec-bodyless')).toBe(0);
      expect(await marginTop('pp-sec-withbody')).toBe(16);

      // #489 clip holds on the body-less wrapping strip: the leading item of every
      // visual line hangs into the clip zone (its ::before is clipped, no dangling
      // middot at a line start).
      const g = await wrapGeom('pp-sec-bodyless-wrap');
      const byTop = new Map<number, { left: number }[]>();
      for (const it of g.rows) {
        const arr = byTop.get(it.top) ?? [];
        arr.push(it);
        byTop.set(it.top, arr);
      }
      for (const [, lineItems] of byTop) {
        const leader = lineItems.reduce((a, b) => (a.left < b.left ? a : b));
        expect(Math.abs(leader.left - (g.ulContentLeft + g.pull))).toBeLessThanOrEqual(1.5);
      }
      if (width <= 375) {
        expect(byTop.size).toBeGreaterThan(1);
      }

      await testInfo.attach(`bodyless-strip-${width}`, {
        body: await page.locator('#pp-sec-bodyless').screenshot(),
        contentType: 'image/png',
      });
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
          subheading: 'Split was chosen before media was imported.',
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

  // #477: a split hero with vertical_align="stretch" must make the media column
  // track the CONTENT column's height, so one fixed asset balances a tall
  // headline instead of floating as "a small card beside a huge headline." The
  // measured failure on the real site was media at 69-80% of a 4-5 line
  // headline's height; the fix should bring it to ~100%.
  //
  // This is the computed-geometry test that would have caught the gap and proves
  // the fix. Two split heroes render in ONE composition, both with the SAME tall
  // multi-line headline and the SAME wide, short image (a 40x8 data URI, so the
  // NON-stretch media renders far shorter than the headline column — the "before"
  // state). Hero 0 uses vertical_align="stretch", hero 1 uses the default
  // "center". The signal is the ratio media-wrap-height / content-column-height:
  //   stretch -> ~1.0 (equal-height columns, the fix)
  //   center  -> well under 0.9 (the fixed-aspect card is much shorter — the bug)
  // The image-wrap is the grid ITEM, so under align-items:stretch it fills the
  // row height regardless of whether the image itself loaded; a broken image
  // would still stretch, so the pin measures the CAPABILITY, not image decode.
  //
  // Split is a desktop (>=1024px) two-column grid; all vertical_align CSS lives
  // in a min-width:1024px block, so this asserts at 1280. Below 1024px the split
  // stacks and stretch is a no-op — mobile is unaffected and not regressed.
  test('#477 split hero vertical_align=stretch makes media track the content column height @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Hero Split Stretch Media');
    // Wide + short so the natural (non-stretch) media is much shorter than a tall
    // headline column, reproducing the issue's "small card beside a huge headline".
    const WIDE_SHORT_PNG =
      'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACgAAAAICAIAAAAEMCoMAAAAGElEQVR42mMIqDgxIIhh1OJRi0ctphYCAPUY9BAC1F1zAAAAAElFTkSuQmCC';
    // A deliberately long headline. #578 deleted the 12ch title cap, so the wrapping is now
    // done by the split grid's own track (~553px at 1280) rather than by a character cap;
    // the headline still wraps to several lines and makes the content column genuinely tall,
    // which is all this fixture needs.
    const TALL_HEADLINE = 'A deliberately long split hero headline that wraps to several lines';
    setComposition(pageId, [
      {
        component: 'hero',
        props: {
          id: 'pp-hero-stretch',
          layout: 'split',
          title: TALL_HEADLINE,
          subheading: 'Media should fill this column, not float below center.',
          image_url: WIDE_SHORT_PNG,
          image_alt: 'stretch media',
          vertical_align: 'stretch',
        },
      },
      {
        component: 'hero',
        props: {
          id: 'pp-hero-center',
          layout: 'split',
          title: TALL_HEADLINE,
          subheading: 'Media should fill this column, not float below center.',
          image_url: WIDE_SHORT_PNG,
          image_alt: 'center media',
          // default vertical_align (center) — the "before" fixed-aspect card.
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    await expect(page.locator('#pp-hero-stretch .hero__image-wrap')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#pp-hero-center .hero__image-wrap')).toBeVisible({ timeout: 10000 });

    // Ratio of media-wrap height to content-column height for one hero.
    const ratio = (heroId: string) =>
      page.locator(`#${heroId}`).evaluate((hero) => {
        const wrap = hero.querySelector('.hero__image-wrap') as HTMLElement;
        const content = hero.querySelector('.hero__content') as HTMLElement;
        const wh = wrap.getBoundingClientRect().height;
        const ch = content.getBoundingClientRect().height;
        return { wrapHeight: wh, contentHeight: ch, ratio: ch > 0 ? wh / ch : -1 };
      });

    const stretch = await ratio('pp-hero-stretch');
    const center = await ratio('pp-hero-center');

    // Sanity: the headline column is genuinely tall (multi-line), so the ratio is
    // a meaningful signal — a short content column would make both ratios ~1 and
    // the pin vacuous. The wide-short image guarantees the content column wins.
    expect(stretch.contentHeight).toBeGreaterThan(200);
    expect(center.contentHeight).toBeGreaterThan(200);

    // The fix: stretched media fills the content column's height (equal-height
    // columns). Allow a small tolerance for sub-pixel grid rounding.
    expect(stretch.ratio).toBeGreaterThan(0.98);
    expect(stretch.ratio).toBeLessThan(1.02);

    // The bug state (default center): the fixed-aspect card is much shorter than
    // the headline — well under the issue's measured 69-80% ceiling. This proves
    // stretch genuinely changed the geometry rather than every split stretching.
    expect(center.ratio).toBeLessThan(0.9);
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
  // capped `.cta__title`/`.cta__body` at --cta-heading-measure, but the `.cta__text` wrapper
  // had no width constraint: a long body stretched it to the full inner width, so the
  // capped title left-pinned inside it (text-align only centers the glyphs WITHIN that
  // left-pinned box). The base `.cta--full-width .cta__text { max-width: var(--cta-heading-measure,
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
          body: 'Supporting copy that sits below the headline in the full-width layout.',
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
   * #474 — the cta's optional SECOND button.
   *
   * Two things only a rendered box can prove. (1) The dark-band routing: outline is
   * the DEFAULT second-button variant, and outline paints its ink and ring directly
   * on the band, so on `theme: inverted` and on a background_image band the
   * light-surface --color-accent (#3157f4) rendered at 3.23:1 and 1.17:1 — both
   * below AA. The fallback now routes through the same role tokens this component
   * already uses for its dark-band body links (#437/#461). (2) The per-instance slot
   * must still WIN over that routed fallback, or the #61/#86 dark-surface-slot
   * contract is broken. The static StyleSlotContractTest proves the var() is
   * consumed; only getComputedStyle proves which value the cascade actually paints.
   */
  test('#474 second button outline routes to the AA role token on both dark bands; the slot still wins @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Second Button Dark Bands');
    setComposition(pageId, [
      // 0: inverted band, default (outline) second button.
      {
        component: 'cta',
        props: {
          title: 'Inverted closing band',
          button_text: 'Ver planes',
          button_url: '/precios',
          button2_text: 'Hablar con nosotros',
          button2_url: '/contacto',
          theme: 'inverted',
        },
      },
      // 1: background-image band, default (outline) second button.
      {
        component: 'cta',
        props: {
          title: 'Overlay closing band',
          button_text: 'Ver planes',
          button_url: '/precios',
          button2_text: 'Hablar con nosotros',
          button2_url: '/contacto',
          background_image: 'https://example.com/nonexistent.jpg',
        },
      },
      // 2: inverted band with an explicit per-instance override — the slot must beat
      // the routed fallback (the safe-surface contract).
      {
        component: 'cta',
        props: {
          title: 'Inverted, author override',
          button_text: 'Ver planes',
          button_url: '/precios',
          button2_text: 'Hablar con nosotros',
          button2_url: '/contacto',
          theme: 'inverted',
        },
        style: { '--cta-button2-color': '#ffd166', '--cta-button2-border': '#ffd166' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const secondaries = page.locator('.cta__button--secondary');
    await expect(secondaries).toHaveCount(3, { timeout: 10000 });

    const colorOf = async (i: number, prop: string) =>
      secondaries.nth(i).evaluate(
        (el, p) => getComputedStyle(el).getPropertyValue(p),
        prop,
      );

    // --color-accent-on-inverted (#9dafee) = 8.33:1 on --color-bg-inverted,
    // replacing --color-accent's failing 3.23:1.
    expect(await colorOf(0, 'color')).toBe('rgb(157, 175, 238)');
    expect(await colorOf(0, 'border-top-color')).toBe('rgb(157, 175, 238)');

    // --color-accent-on-overlay (#fafbff) = 4.59:1 over the worst-case
    // overlay-over-white composite, replacing --color-accent's 1.17:1.
    expect(await colorOf(1, 'color')).toBe('rgb(250, 251, 255)');
    expect(await colorOf(1, 'border-top-color')).toBe('rgb(250, 251, 255)');

    // The per-instance slot beats the routed dark-band fallback.
    expect(await colorOf(2, 'color')).toBe('rgb(255, 209, 102)');
    expect(await colorOf(2, 'border-top-color')).toBe('rgb(255, 209, 102)');
  });

  /*
   * #535 — the rest of the dark-band button class #474 opened.
   *
   * #474 fixed the cta's SECOND button. Everything else that paints ink directly on a
   * dark band was still on the light-surface accent (or, on the cover hero, on near-black
   * --color-text): the PRIMARY outline/ghost on both cta dark bands, the hero's primary
   * AND its default-outline second CTA on the `.hero--cover` scrim. Separately, the FILLED
   * primary on the two OVERLAY bands had no separation from the band at all — its gradient
   * measured under 2:1 against the worst-case composite and its border followed the fill, so
   * the button's SHAPE vanished and only the label carried it.
   *
   * Only a rendered box proves these. The cover-hero case in particular is a pure CASCADE
   * defect: `.hero--cover .btn--outline` existed but sat ABOVE `.hero .btn--outline` at
   * identical [0,2,0] specificity, so it never painted. A static "the rule exists" check
   * passed for years while the rendered button stayed near-black. css-lint pins the source
   * order; this pins the colour the cascade actually resolves.
   *
   * Ratios quoted below are against the worst-case composites documented in base.css:
   * --color-bg-inverted for the solid band, the --overlay-bg scrim over a pure-WHITE
   * image for the overlay bands.
   */
  test('#535 dark-band primary + cover-hero buttons route to the AA role tokens; rings, slots and light bands hold @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Dark Band Button Contrast');
    setComposition(pageId, [
      // 0/1: cta PRIMARY outline + ghost on the solid inverted band (3.23:1 -> 8.33:1).
      { component: 'cta', props: { title: 'Inverted outline', button_text: 'Ver planes', button_url: '/precios', theme: 'inverted', button_variant: 'outline' } },
      { component: 'cta', props: { title: 'Inverted ghost', button_text: 'Ver planes', button_url: '/precios', theme: 'inverted', button_variant: 'ghost' } },
      // 2/3: cta PRIMARY outline + ghost on the bg-image scrim (1.17:1 -> 4.59:1).
      { component: 'cta', props: { title: 'Overlay outline', button_text: 'Ver planes', button_url: '/precios', background_image: 'https://example.com/nonexistent.jpg', button_variant: 'outline' } },
      { component: 'cta', props: { title: 'Overlay ghost', button_text: 'Ver planes', button_url: '/precios', background_image: 'https://example.com/nonexistent.jpg', button_variant: 'ghost' } },
      // 4: FILLED primary on the bg-image band — gains the separation ring.
      { component: 'cta', props: { title: 'Overlay filled', button_text: 'Ver planes', button_url: '/precios', background_image: 'https://example.com/nonexistent.jpg' } },
      // 5: FILLED primary on the INVERTED band — deliberately NOT ringed (Q2).
      { component: 'cta', props: { title: 'Inverted filled', button_text: 'Ver planes', button_url: '/precios', theme: 'inverted' } },
      // 6: per-instance slots must beat the routed dark-band fallback (#61/#86 contract).
      {
        component: 'cta',
        props: { title: 'Inverted, author override', button_text: 'Ver planes', button_url: '/precios', theme: 'inverted', button_variant: 'outline' },
        style: { '--cta-button-color': '#ffd166', '--cta-button-border': '#ffd166' },
      },
      // 7: LIGHT band control — must be byte-identical to before the change.
      { component: 'cta', props: { title: 'Light outline', button_text: 'Ver planes', button_url: '/precios', button_variant: 'outline' } },
      // 8: BOTH dark-band classes at once. cta.php emits the theme class and the bg-image
      // class independently, so this renders `.cta--inverted.cta--has-bg-image` with the
      // scrim over the inverted background. The two routing rules tie at [0,3,0], so only
      // source order decides — and the OVERLAY role must win, since on-inverted is barely
      // 2.2:1 over an arbitrary image.
      { component: 'cta', props: { title: 'Inverted AND overlay', button_text: 'Ver planes', button_url: '/precios', theme: 'inverted', background_image: 'https://example.com/nonexistent.jpg', button_variant: 'outline' } },
      // 9: an authored --cta-accent on the bg-image band. The ring rule replaces only the
      // TERMINAL fallback, so this brand colour must still paint the ring; jumping straight
      // to the role token would have silently repainted every authored ring near-white.
      {
        component: 'cta',
        props: { title: 'Overlay filled, authored accent', button_text: 'Ver planes', button_url: '/precios', background_image: 'https://example.com/nonexistent.jpg' },
        style: { '--cta-accent': 'rgb(255, 92, 46)' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const buttons = page.locator('.cta__button');
    await expect(buttons).toHaveCount(10, { timeout: 10000 });

    const prop = async (i: number, p: string) =>
      buttons.nth(i).evaluate((el, name) => getComputedStyle(el).getPropertyValue(name), p);

    const ON_INVERTED = 'rgb(157, 175, 238)'; // #9dafee, 8.33:1 on --color-bg-inverted
    const ON_OVERLAY = 'rgb(250, 251, 255)';  // #fafbff, 4.59:1 on the worst-case scrim
    const BARE_ACCENT = 'rgb(49, 87, 244)';   // #3157f4 — the failing light-surface accent

    // Solid inverted band: outline gets ink AND ring; ghost is borderless by design.
    expect(await prop(0, 'color')).toBe(ON_INVERTED);
    expect(await prop(0, 'border-top-color')).toBe(ON_INVERTED);
    expect(await prop(1, 'color')).toBe(ON_INVERTED);

    // Overlay band: on-overlay, NOT on-inverted (which is only ~2.2:1 over the scrim).
    expect(await prop(2, 'color')).toBe(ON_OVERLAY);
    expect(await prop(2, 'border-top-color')).toBe(ON_OVERLAY);
    expect(await prop(3, 'color')).toBe(ON_OVERLAY);

    // Filled primary on the overlay band: the ring no longer follows the fill, so the
    // pill has a visible edge (ring -> 4.59:1) while the fill itself is unchanged.
    expect(await prop(4, 'border-top-color')).toBe(ON_OVERLAY);
    expect(await prop(4, 'background-color')).toBe(BARE_ACCENT);

    // Filled primary on the INVERTED band: NOT ringed. Its fill already measures 3.23:1
    // against the band, clearing the 3:1 non-text bar, so the border stays on the fill.
    expect(await prop(5, 'border-top-color')).toBe(BARE_ACCENT);

    // The per-instance slots beat the routed fallback on a dark band.
    expect(await prop(6, 'color')).toBe('rgb(255, 209, 102)');
    expect(await prop(6, 'border-top-color')).toBe('rgb(255, 209, 102)');

    // Light band is untouched: still the bare accent at 5.14:1 on --color-surface.
    expect(await prop(7, 'color')).toBe(BARE_ACCENT);
    expect(await prop(7, 'border-top-color')).toBe(BARE_ACCENT);

    // Both dark-band classes at once: the overlay role wins, not on-inverted.
    expect(await prop(8, 'color')).toBe(ON_OVERLAY);
    expect(await prop(8, 'border-top-color')).toBe(ON_OVERLAY);

    // An authored --cta-accent still paints the ring; only the terminal fallback moved.
    expect(await prop(9, 'border-top-color')).toBe('rgb(255, 92, 46)');

    /*
     * HOVER must NOT inherit the dark-band routing. The rest rules sit at [0,3,0], the
     * same specificity as the `:hover` rules they follow (a pseudo-class counts as a
     * class), so without the explicit hover restoration the routed ink won by source
     * order and landed on a fill it was never measured against: on-inverted ink over the
     * accent fill is 2.58:1, and on-overlay ink over the near-white ghost hover fill is
     * effectively invisible. On hover each variant paints its own contrasting fill, so
     * the correct value is the variant's ORIGINAL hover ink, not the role token.
     */
    const PAGE_BG = 'rgb(252, 253, 255)';   // --color-bg, outline hover ink (4.70:1 on the accent fill)
    const SURFACE = 'rgb(244, 247, 251)';   // --color-surface, ghost hover fill

    // .btn animates colour over --transition (150ms), so getComputedStyle right after
    // hover() returns a mid-flight blend. Kill transitions rather than sleeping: the
    // assertion is about which value the CASCADE resolves, not how it gets there.
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });

    for (const i of [0, 2]) { // inverted + overlay outline
      await buttons.nth(i).hover();
      expect(await prop(i, 'color')).toBe(PAGE_BG);
      expect(await prop(i, 'background-color')).toBe(BARE_ACCENT);
      expect(await prop(i, 'border-top-color')).toBe(BARE_ACCENT);
    }
    for (const i of [1, 3]) { // inverted + overlay ghost
      await buttons.nth(i).hover();
      expect(await prop(i, 'color')).toBe(BARE_ACCENT);
      expect(await prop(i, 'background-color')).toBe(SURFACE);
    }

    /*
     * The separation ring must SURVIVE hover. `.cta .btn:not(...):hover` is [0,6,0] and
     * its border follows the hover fill, so the [0,5,0] rest ring alone left the button
     * ringed at rest and dissolving again under the pointer — the defect reappearing in
     * the state a user is most likely looking at. WCAG 1.4.11 covers hover too.
     */
    await buttons.nth(4).hover();
    expect(await prop(4, 'border-top-color')).toBe(ON_OVERLAY);
    // The authored-accent ring survives hover through --cta-accent-hover's own chain
    // rather than snapping to the role token.
    await buttons.nth(9).hover();
    expect(await prop(9, 'border-top-color')).not.toBe(BARE_ACCENT);
  });

  test('#535 cover-hero primary and default second CTA clear AA on the scrim @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Cover Hero Button Contrast');
    setComposition(pageId, [
      // 0/1: outline PRIMARY + the DEFAULT (outline) second CTA. Before #535 both painted
      // var(--hero-heading-color, var(--color-text)) = near-black #101828 on the scrim — the dead
      // `.hero--cover .btn--outline` rule never won.
      {
        component: 'hero',
        props: {
          title: 'Cover outline', layout: 'cover',
          button_text: 'Empezar', button_url: '/a', button_variant: 'outline',
          button2_text: 'Hablar', button2_url: '/b',
        },
      },
      // 2/3: ghost PRIMARY + ghost second CTA (both fell to --color-accent at 1.17:1).
      {
        component: 'hero',
        props: {
          title: 'Cover ghost', layout: 'cover',
          button_text: 'Empezar', button_url: '/a', button_variant: 'ghost',
          button2_text: 'Hablar', button2_url: '/b', button2_variant: 'ghost',
        },
      },
      // 4: FILLED primary — gains the separation ring on the scrim.
      { component: 'hero', props: { title: 'Cover filled', layout: 'cover', button_text: 'Empezar', button_url: '/a' } },
      // 5: per-instance slot still wins over the routed fallback.
      {
        component: 'hero',
        props: { title: 'Cover override', layout: 'cover', button_text: 'Empezar', button_url: '/a', button_variant: 'outline' },
        style: { '--hero-heading-color': '#ffd166' },
      },
      // 6: a NON-cover hero must be untouched (no scrim, no routing).
      { component: 'hero', props: { title: 'Plain hero', layout: 'centered', button_text: 'Empezar', button_url: '/a', button_variant: 'outline' } },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const ctas = page.locator('.hero__cta');
    await expect(ctas).toHaveCount(7, { timeout: 10000 });

    const prop = async (i: number, p: string) =>
      ctas.nth(i).evaluate((el, name) => getComputedStyle(el).getPropertyValue(name), p);

    const ON_OVERLAY = 'rgb(250, 251, 255)';
    const NEAR_BLACK = 'rgb(16, 24, 40)';   // --color-text, the defect's rendered value
    const BARE_ACCENT = 'rgb(49, 87, 244)';

    // 0: outline PRIMARY on the cover scrim — ink and ring both on the overlay role.
    expect(await prop(0, 'color')).toBe(ON_OVERLAY);
    expect(await prop(0, 'border-top-color')).toBe(ON_OVERLAY);
    expect(await prop(0, 'color')).not.toBe(NEAR_BLACK);
    // 1: the DEFAULT second CTA (button2_variant defaults to `outline`) — #535 Q3.
    expect(await prop(1, 'color')).toBe(ON_OVERLAY);
    expect(await prop(1, 'border-top-color')).toBe(ON_OVERLAY);

    // 2/3: ghost primary + ghost second CTA.
    expect(await prop(2, 'color')).toBe(ON_OVERLAY);
    expect(await prop(3, 'color')).toBe(ON_OVERLAY);

    // 4: FILLED primary gains the ring; the fill itself is unchanged.
    expect(await prop(4, 'border-top-color')).toBe(ON_OVERLAY);

    // 5: --hero-heading-color still wins over the routed fallback.
    expect(await prop(5, 'color')).toBe('rgb(255, 209, 102)');
    expect(await prop(5, 'border-top-color')).toBe('rgb(255, 209, 102)');

    // 6: a plain (non-cover) hero keeps today's --hero-heading-color -> --color-text outline and
    // its accent-bordered fill. The routing is scoped to the scrim, nothing else moved.
    expect(await prop(6, 'color')).toBe(NEAR_BLACK);
    expect(await prop(6, 'border-top-color')).toBe(NEAR_BLACK);
    expect(await prop(6, 'color')).not.toBe(BARE_ACCENT);

    /*
     * HOVER, same leak class as the cta test above. `.hero--cover .btn--ghost` [0,2,0]
     * ties with the shared `.btn--ghost:hover` and follows it, so the on-overlay ink
     * survived onto a hover that fills with the near-white --color-surface. The outline
     * twin was already safe ([0,3,0] hover outranks it) and is pinned here so a future
     * specificity change to either rule is caught.
     */
    const PAGE_BG = 'rgb(252, 253, 255)';
    const SURFACE = 'rgb(244, 247, 251)';

    // See the cta test: kill the 150ms colour transition so the assertion reads the
    // cascade's resolved value rather than a mid-flight blend.
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });

    await ctas.nth(0).hover();
    expect(await prop(0, 'color')).toBe(PAGE_BG);
    expect(await prop(0, 'background-color')).toBe(BARE_ACCENT);
    expect(await prop(0, 'border-top-color')).toBe(BARE_ACCENT);

    await ctas.nth(2).hover();
    expect(await prop(2, 'color')).toBe(BARE_ACCENT);
    expect(await prop(2, 'background-color')).toBe(SURFACE);

    // The second CTA's own :hover rules are [0,5,0] and outrank the [0,4,0] cover
    // routing, so its hover ink is the variant's normal value, not a role token.
    await ctas.nth(1).hover();
    expect(await prop(1, 'color')).toBe(PAGE_BG);
    expect(await prop(1, 'background-color')).toBe(BARE_ACCENT);

    // The filled primary's ring must survive hover here too (see the cta test).
    await ctas.nth(4).hover();
    expect(await prop(4, 'border-top-color')).toBe(ON_OVERLAY);
  });

  /*
   * #474 — the compensating proof for the three SLOT_DECLARATION_EXEMPTIONS entries
   * added in StyleSlotContractTest. That guard normally forbids a stylesheet rule from
   * DECLARING a schema slot, because declaring it beats the renderer's inline value on
   * every descendant. The cta2-style isolation rule is exempt, so the guard can no
   * longer catch a regression here — this test is what replaces it. It pins both halves
   * of the mechanism that the exemption exists to enable, which is exactly the #514/#526
   * leak class: the primary's slots must not repaint a filled second button, and the
   * second button's own fill slot must resolve the premium `background` SHORTHAND so the
   * gradient is cleared rather than masking the flat color.
   */
  test('#474 primary button slots do not leak into a filled second button; --cta-button2-bg clears the gradient @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Button2 Slot Isolation');
    setComposition(pageId, [
      // 0: the PRIMARY is flattened to a brand color. button2 is the filled `primary`
      // variant, so it matches the same premium cascade and would be repainted too.
      {
        component: 'cta',
        props: {
          title: 'Isolation',
          button_text: 'Primary',
          button_url: '/a',
          button2_text: 'Second',
          button2_url: '/b',
          button2_variant: 'primary',
        },
        style: {
          '--cta-button-bg': 'rgb(185, 28, 28)',
          '--cta-button-color': 'rgb(0, 255, 0)',
          '--cta-button-shadow': 'none',
        },
      },
      // 1: the SECOND button is recolored on its own slot; the primary must stay default.
      {
        component: 'cta',
        props: {
          title: 'Flat second',
          button_text: 'Primary',
          button_url: '/a',
          button2_text: 'Second',
          button2_url: '/b',
          button2_variant: 'primary',
        },
        style: { '--cta-button2-bg': 'rgb(21, 128, 61)' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const bands = page.locator('section.cta');
    await expect(bands).toHaveCount(2, { timeout: 10000 });

    const boxOf = (loc: any) =>
      loc.evaluate((el: Element) => {
        const cs = getComputedStyle(el);
        return {
          bg: cs.backgroundColor,
          img: cs.backgroundImage,
          color: cs.color,
          shadow: cs.boxShadow,
        };
      });

    // Band 0 — the primary's per-instance slots must NOT reach the second button.
    const primary0 = await boxOf(bands.nth(0).locator('.cta__button').nth(0));
    const second0 = await boxOf(bands.nth(0).locator('.cta__button--secondary'));
    expect(primary0.bg).toBe('rgb(185, 28, 28)');
    expect(primary0.img).toBe('none'); // flat fill cleared the gradient
    expect(primary0.shadow).toBe('none');
    expect(second0.bg).not.toBe('rgb(185, 28, 28)');
    expect(second0.color).not.toBe('rgb(0, 255, 0)');
    expect(second0.shadow).not.toBe('none'); // keeps the premium bevel
    expect(second0.img).not.toBe('none'); // keeps the premium gradient

    // Band 1 — the reverse direction: button2's fill slot must resolve the premium
    // `background` shorthand (clearing the gradient) and must not touch the primary.
    const primary1 = await boxOf(bands.nth(1).locator('.cta__button').nth(0));
    const second1 = await boxOf(bands.nth(1).locator('.cta__button--secondary'));
    expect(second1.bg).toBe('rgb(21, 128, 61)');
    expect(second1.img).toBe('none');
    expect(primary1.bg).not.toBe('rgb(21, 128, 61)');
    expect(primary1.img).not.toBe('none'); // primary keeps its gradient
  });

  /*
   * #540 — the hover-fill flash. The FIRST test in this file that asserts a transition,
   * and it has to be: every other hover test here kills transitions or reads the settled
   * value, which is precisely how a 7-8 frame off-brand flash shipped through review.
   *
   * A hover fill slot set WITHOUT its resting counterpart used to ramp the button through
   * a colour the author never chose and never saw. The resting fill is a gradient
   * background-IMAGE; that layer is not interpolable, so it drops to `none` the instant the
   * flat hover slot resolves the `background` shorthand, exposing the background-COLOR it
   * had been masking — and THAT masked colour is where the tween starts. Measured before
   * the fix: rgb(80, 74, 195) violet on a button authored blue-at-rest, red-on-hover, with
   * the 1px ring crossing the same unchosen ground one layer out.
   *
   * The fix scopes a `transition-property` to the filled premium selector that drops
   * background-color and border-color, so both swap instantly between the two authored
   * states. What this test proves that a settled-value assertion structurally cannot:
   * every SAMPLED FRAME of the fill and the ring is one of the two endpoints. The settled
   * assertions are here too, because the whole point is that they were ALWAYS green — the
   * byte-identity bar this repo holds is untouched, only the in-between is.
   */
  test('#540 a hover-only fill slot never renders an unchosen colour, fill or ring @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Hover Fill Flash');
    setComposition(pageId, [
      // 0: the reported repro — hover fill authored, resting fill left at the gradient.
      {
        component: 'hero',
        props: { title: 'Hover only', button_text: 'Get started', button_url: '/a' },
        style: { '--hero-button-hover-bg': 'rgb(185, 28, 28)' },
      },
      // 1: an OUTLINE second button in the same page. Its fill and border are visible at
      // rest, so its tween is honest and must survive the fix untouched.
      {
        component: 'cta',
        props: {
          title: 'Outline second',
          button_text: 'Primary',
          button_url: '/a',
          button2_text: 'Second',
          button2_url: '/b',
          button2_variant: 'outline',
        },
      },
    ]);

    /* The whole point of this test is that the 150ms tween RUNS, so the reduced-motion
       preference has to be pinned. base.css clamps transition-duration to 0.01ms under
       `reduce`, which would collapse every sample and fail the outline control with a
       confusing message if a future config turns it on suite-wide. */
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const filled = page.locator('.hero__cta').first();
    const outline = page.locator('.cta__button--secondary');
    await expect(filled).toBeVisible({ timeout: 10000 });

    const read = (loc: any) =>
      loc.evaluate((n: Element) => {
        const s = getComputedStyle(n);
        return { bg: s.backgroundColor, img: s.backgroundImage, ring: s.borderTopColor };
      });

    /* Sample the computed fill and ring on every animation frame across the hover window.
       Two things this helper is careful about, both of which would otherwise turn a real
       regression into a green run (or a green tree into a red one) on a loaded CI worker:

       1. The settled hover value is READ BACK after the pointer has landed and the window
          has closed, never taken from the last sampled frame. The rAF loop self-terminates
          on its own clock, so on a slow worker its final frame can predate the hover.
       2. Every frame is stamped, and the caller asserts that frames actually fell INSIDE
          the transition window. rAF can be throttled or coalesced to a single tick; a
          bare "we collected some frames" check passes on that, with zero tween coverage. */
    const sampleHover = async (loc: any) => {
      const handle = await loc.elementHandle();
      const rest = await read(loc);
      const box = (await loc.boundingBox())!;
      await page.evaluate((n: Element) => {
        (window as any).__f = [];
        (window as any).__hoverAt = null;
        const t0 = performance.now();
        const tick = () => {
          const s = getComputedStyle(n);
          (window as any).__f.push({
            t: performance.now(),
            bg: s.backgroundColor,
            img: s.backgroundImage,
            ring: s.borderTopColor,
          });
          if (performance.now() - t0 < 1200) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      }, handle);
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      // Stamp the moment the pointer landed, from the PAGE clock the frames are stamped on.
      const hoverAt = await page.evaluate(() => performance.now());
      await page.waitForTimeout(600);
      const frames = await page.evaluate(() => (window as any).__f);
      const hover = await read(loc); // settled, read by state and not by wall clock
      await page.mouse.move(0, 0);
      await page.waitForTimeout(400);
      // The transition is 150ms; allow a frame of slack on each side.
      const inWindow = frames.filter((fr: any) => fr.t >= hoverAt && fr.t <= hoverAt + 180);
      return { rest, hover, frames, inWindow };
    };

    const f = await sampleHover(filled);

    // Settled states: the authored hover fill wins and clears the gradient, exactly as
    // before the fix. These pass on BOTH sides of #540 — that is the point.
    expect(f.rest.img).not.toBe('none'); // resting slot unset -> premium gradient
    expect(f.hover.bg).toBe('rgb(185, 28, 28)');
    expect(f.hover.img).toBe('none');

    /* The tween window was genuinely sampled. Without this the two assertions below can
       filter an empty (or entirely post-settle) frame set and pass for the wrong reason. */
    expect(f.inWindow.length).toBeGreaterThanOrEqual(3);

    /* The guarantee: once the gradient mask is gone, the only fill colour that may render
       is the authored one. Pre-fix this collected 7-8 frames of blue-to-red blend. */
    const offBrandFill = f.frames.filter(
      (fr: any) => fr.img === 'none' && fr.bg !== 'rgb(185, 28, 28)',
    );
    expect(offBrandFill).toEqual([]);

    /* The ring rides the same swap (the #540 decision took border-color out of the list
       too). Every frame must be one of the two authored endpoints, never between them.
       The endpoints must actually DIFFER, or the two-element set collapses to one and the
       ring half of the decision goes unverified while the assertion still passes. */
    expect(f.rest.ring).not.toBe(f.hover.ring);
    const ringEndpoints = new Set([f.rest.ring, f.hover.ring]);
    const offBrandRing = f.frames.filter((fr: any) => !ringEndpoints.has(fr.ring));
    expect(offBrandRing).toEqual([]);

    // The narrowed list is scoped to the filled premium button...
    expect(await filled.evaluate((n: Element) => getComputedStyle(n).transitionProperty)).toBe(
      'box-shadow, color, transform',
    );
    // ...and the transparent variants keep the full five-property list, tween intact.
    expect(await outline.evaluate((n: Element) => getComputedStyle(n).transitionProperty)).toBe(
      'background-color, border-color, box-shadow, color, transform',
    );
    const o = await sampleHover(outline);
    // Same window guard on the control, so a collapsed sampler fails as a sampler problem
    // rather than as a bogus "the outline stopped animating".
    expect(o.inWindow.length).toBeGreaterThanOrEqual(3);
    const outlineRamp = o.inWindow.filter(
      (fr: any) => fr.bg !== o.rest.bg && fr.bg !== o.hover.bg,
    );
    expect(outlineRamp.length).toBeGreaterThanOrEqual(2); // still genuinely animating
  });

  test('#474 an unset second button leaves the cta byte-identical; a set one renders the pair', async ({
    page,
  }) => {
    pageId = createPage('E2E CTA Second Button Presence');
    setComposition(pageId, [
      {
        component: 'cta',
        props: { title: 'Single', button_text: 'Ver planes', button_url: '/precios' },
      },
      {
        component: 'cta',
        props: {
          title: 'Pair',
          button_text: 'Ver planes',
          button_url: '/precios',
          button2_text: 'Hablar',
          button2_url: '/contacto',
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const bands = page.locator('section.cta');
    await expect(bands).toHaveCount(2, { timeout: 10000 });

    // Unset: no wrapper, no second anchor — the pre-#474 shape exactly.
    await expect(bands.nth(0).locator('.cta__buttons')).toHaveCount(0);
    await expect(bands.nth(0).locator('.cta__button')).toHaveCount(1);

    // Set: the pair sits in one wrapper, side by side on desktop (same row).
    await expect(bands.nth(1).locator('.cta__buttons')).toHaveCount(1);
    await expect(bands.nth(1).locator('.cta__button')).toHaveCount(2);

    const primaryBox = (await bands.nth(1).locator('.cta__button').nth(0).boundingBox())!;
    const secondBox = (await bands.nth(1).locator('.cta__button').nth(1).boundingBox())!;
    expect(Math.abs(primaryBox.y - secondBox.y)).toBeLessThan(2);
    expect(secondBox.x).toBeGreaterThan(primaryBox.x);

    // Mobile: the pair stacks one button per row (the shared `main .btn` width rule
    // plus flex-wrap, the mechanism .hero__cta-group relies on).
    await page.setViewportSize({ width: 375, height: 800 });
    const mPrimary = (await bands.nth(1).locator('.cta__button').nth(0).boundingBox())!;
    const mSecond = (await bands.nth(1).locator('.cta__button').nth(1).boundingBox())!;
    expect(mSecond.y).toBeGreaterThan(mPrimary.y + mPrimary.height - 2);
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
  test('#305 grid cards honor --grid-item-border-color on featured AND non-featured cards @smoke', async ({
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
    const res = await styleComponent(page, pageId, { '--grid-item-border-color': '#ff0080' });
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
  // The uniform-row test neutralizes via --grid-item-shadow, which the PRE-#293 CSS
  // already routed — it would pass with the featured-shadow chain reverted. This
  // test sets the featured slot to a distinctive value and proves it renders on the
  // featured card only, at desktop AND mobile (the two chain sites), and that it
  // outranks a simultaneously-set --grid-item-shadow.
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
      '--grid-item-shadow': '0 6px 12px rgba(7, 8, 9, 0.4)',
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
      '--grid-item-bar-color': 'rgb(9, 8, 7)',
      '--grid-item-bar-height': '3px',
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
  // The lift keys off the `--dark` surface-band class, which the CANONICAL `muted`
  // value emits (#570 DG-4). The fixture used to author it as `theme: "dark"`; that
  // input value was removed in #605, so it now authors `muted` and proves the same
  // thing through the same emitted class.
  test('#226 card_emphasis:uniform neutralizes the muted-band first-card lift', async ({
    page,
  }) => {
    pageId = createPage('E2E Grid Card Emphasis Muted Lift');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid-muted-uniform',
          theme: 'muted',
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
          id: 'pp-grid-muted-featured',
          theme: 'muted',
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

    await expect(page.locator('#pp-grid-muted-uniform .grid__item')).toHaveCount(2, {
      timeout: 10000,
    });

    // Uniform: card 0 has NO lift — same transform as its sibling.
    const duFirst = await transformOf('#pp-grid-muted-uniform .grid__item:nth-child(1)');
    const duSib = await transformOf('#pp-grid-muted-uniform .grid__item:nth-child(2)');
    expect(duFirst).toBe(duSib);
    expect(duFirst).toBe('none');

    // Featured (default): card 0 IS lifted — a real transform, unlike its sibling.
    const dfFirst = await transformOf('#pp-grid-muted-featured .grid__item:nth-child(1)');
    const dfSib = await transformOf('#pp-grid-muted-featured .grid__item:nth-child(2)');
    expect(dfFirst).not.toBe('none'); // translateY lift present
    expect(dfFirst).not.toBe(dfSib);
  });

  // Parent-constrains-child axis (#302's --section-body-measure): the pre-fix bug
  // was a literal max-width on the OUTER .section__body capping the slotted inner
  // .section__content — a shape the static guard's own docblock says no
  // same-subject textual scan can prove. This rendered pin is the layer that owns
  // it: if any ancestor cap returns, the inner box cannot reach the slot value.
  test('#305 section body honors --section-body-measure past its wrapper at 1280px @smoke', async ({
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
    const res = await styleComponent(page, pageId, { '--section-body-measure': '700px' });
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
    // .section__content) through var(--section-body-measure, 49rem), so the inner
    // content box that used to cap dead at 784px now follows the slot to 700px.
    expect(widths.content).toBe('700px');
    expect(widths.wrapper).toBe('700px');
    expect(widths.rendered).toBeGreaterThan(640);
  });

  // #470: the section body text size + weight are authorable via --section-body-size
  // (length) and --section-body-weight (number). Before #470 the body font-size/weight
  // was baked as literals in the desktop premium and mobile rules, so a deliberate
  // type step (compact utility band, emphasis paragraph) was unreachable. The slots
  // must reach the rendered body at BOTH breakpoints (the #86/#349 mobile-hid-it
  // lesson), and an UNSET section must render byte-identically to today: weight 430 at
  // both, size 1.065rem (desktop) / 1rem (mobile) resolved against the page's own root.
  // Two sections prove both halves in one render: section 0 SET, section 1 UNSET.
  test('#470 section body honors --section-body-size / --section-body-weight at both breakpoints; unset byte-identical @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Section Body Type Slots');
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec01', title: 'Set body type', body: '<p>Deliberate size and weight.</p>' },
      },
      {
        component: 'section',
        props: { id: 'pp-sec02', title: 'Default body type', body: '<p>Unchanged defaults.</p>' },
      },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // Distinctive, unambiguous values: 22px is no theme literal, 850 is no default weight.
    const res = await styleComponent(
      page,
      pageId,
      { '--section-body-size': '22px', '--section-body-weight': '850' },
      undefined,
      0,
    );
    expect(res.success).toBe(true);

    const bodyType = (i: number) =>
      page.locator('.section__content').nth(i).locator('p').first().evaluate((el) => {
        const cs = getComputedStyle(el);
        const rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize);
        return { fontSize: cs.fontSize, fontWeight: cs.fontWeight, rootPx };
      });

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__content')).toHaveCount(2, { timeout: 10000 });

      // Set slot reaches the body at BOTH breakpoints — the issue's case.
      const set = await bodyType(0);
      expect(set.fontSize).toBe('22px');
      expect(set.fontWeight).toBe('850');

      // Unset renders byte-identically to today: weight 430 at both breakpoints,
      // size 1.065rem (desktop) / 1rem (mobile) resolved against the page's own root
      // font-size — the exact historical literal, and NOT the set section's value.
      const unset = await bodyType(1);
      expect(unset.fontWeight).toBe('430');
      const remFactor = width >= 768 ? 1.065 : 1;
      expect(unset.fontSize).toBe(`${remFactor * unset.rootPx}px`);
      expect(unset.fontSize).not.toBe('22px');
    }
  });

  /**
   * #332 — WP core's global stylesheet ships attribute-SUBSTRING selectors:
   *
   *   html :where([style*=border-width]){border-style:solid}
   *   html :where([style*=border-color]){border-style:solid}
   *
   * Our style slots render as inline CUSTOM PROPERTIES on the component root
   * (`style="--grid-item-border-width:0px"`). The substring lives in the property
   * NAME, so the selector matches the root — even when the value is 0 and the
   * border the slot controls actually lives on a DESCENDANT (the card). Roots that
   * declared no border of their own then computed core's injected `solid` at the
   * initial `medium` width: a 3px border nobody asked for. The 1.0-H dogfood hit
   * this on --grid-item-border-width and --section-panel-border-width and had to
   * abandon two documented slots.
   *
   * No static check over our own CSS can see this: our stylesheet is correct, the
   * slot is consumed, and the defect is contributed by a FOREIGN stylesheet at
   * runtime. Only a rendered box under real WP core CSS proves the immunity — the
   * same argument this file's header makes for #86/#24. The declaration-level half
   * (a new slot name embedding a trigger substring) is pinned statically in
   * StyleSlotContractTest::testBorderTriggerSlotsHaveCascadeImmunity.
   */
  // Every trigger slot the schemas declare, grouped by the component root that carries
  // them inline. Setting a component's FULL trigger set at once is the acceptance
  // criterion: "setting any of the slots (including to 0) produces exactly the border
  // the slot specifies — no injected 3px border on the root." The count was 13 at
  // issue 332 and is 27 today; the set-equality guard below is what keeps this array
  // honest as the slot surface grows, so never hardcode the number here.
  //
  // COVERAGE RESTORED (#696, 2026-08-17): --grid-item-border-color and
  // --testimonials-item-border-color were missing. #576 ("apply the canonical slot and
  // prop vocabulary across all ten components") renamed --grid-card-border-width ->
  // --grid-item-border-width and --testimonials-card-border-width ->
  // --testimonials-item-border-width AND newly ADDED the two colour slots; this array
  // was updated for the renames only. That is exactly the drift the guard below exists
  // to catch, and it caught it — the array was stale, the guard was right.
  //
  // On the VALUES: what trips WP core's `:where([style*=border-color])` is the slot
  // NAME appearing in the root's inline style attribute, not the colour it resolves
  // to, and the rendered pin asserts computed border WIDTHS on the root. So
  // 'transparent' covers the trigger exactly as a visible colour would; it is chosen
  // to match each case's existing sibling eyebrow-border-color entry.
  const BORDER_TRIGGER_CASES: {
    component: string;
    props: Record<string, unknown>;
    slots: Record<string, string>;
  }[] = [
    {
      component: 'grid',
      props: { id: 'pp-grid01', items: [{ title: 'One', text: 'First' }] },
      slots: {
        '--grid-item-border-width': '0px',
        '--grid-item-border-color': 'transparent',
        '--grid-eyebrow-border-width': '0px',
        '--grid-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'faq',
      props: { id: 'pp-faq01', items: [{ question: 'Q?', answer: 'A.' }] },
      slots: {
        '--faq-item-border-color': '#ff0080',
        '--faq-eyebrow-border-width': '0px',
        '--faq-eyebrow-border-color': 'transparent',
      },
    },
    {
      component: 'testimonials',
      props: { id: 'pp-tst01', items: [{ quote: 'It works.', author: 'A' }] },
      slots: {
        '--testimonials-item-border-width': '0px',
        '--testimonials-item-border-color': 'transparent',
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
  // a NEW border-trigger slot appearing in a schema — exactly the drift it exists to
  // catch (testing-specialist finding). Set-equality against the schemas can, and did:
  // it is what caught the two slots #576 added and this array never picked up (#696).
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
      // substring trigger THIS case depends on. `--faq-item-border-color` rides the
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
            { title: 'One', text: 'First', style: { '--grid-item-border-width': '0px' } },
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
    expect(inline).toContain('--grid-item-border-width');

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
  test('#332 --grid-item-border-width still reaches the card (0 = no card border)', async ({
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

    const res = await styleComponent(page, pageId, { '--grid-item-border-width': '0px' });
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
            button_text: 'Get started',
            button_url: '/start',
            button2_text: 'Book a demo',
            button2_url: '/contact',
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
    { component: 'section', locator: '.section__title', slot: '--section-heading-margin-bottom', expected: '26.4px' },
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

  // Strand 3 (#472). The number's family was never declared, so it silently took
  // the BODY font, and its weight was the literal 700 — a serif heading system
  // could not reach the biggest figures on the page. The static contract test
  // proves the two new slots are consumed; only the browser proves they WIN the
  // cascade, that the unset band is unmoved, and that the sibling label does not
  // follow the number's face.
  test('#472 stats number font and weight are slot-driven; unset is unmoved and the label never follows @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E Stats Number Typography Slots');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-stats02', items: [{ number: '1,250,000+', label: 'Documents processed' }] },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);

    const read = () =>
      page.evaluate(() => {
        const number = document.querySelector('.stats__number') as HTMLElement;
        const label = document.querySelector('.stats__label') as HTMLElement;
        return {
          numberFamily: getComputedStyle(number).fontFamily,
          numberWeight: getComputedStyle(number).fontWeight,
          labelFamily: getComputedStyle(label).fontFamily,
          bodyFamily: getComputedStyle(document.body).fontFamily,
        };
      });

    await expect(page.locator('.stats__number')).toBeVisible({ timeout: 10000 });
    const before = await read();
    // Unset stays byte-identical to pre-#472: weight 700 (NOT the 650 that
    // --font-weight-heading carries) and the same inherited family as the label.
    expect(before.numberWeight).toBe('700');
    // Compare against the BODY font, not the label: the claim being pinned is
    // "the number takes the page body font when unset", and keying that to a
    // sibling would break the day the label gets a font role of its own.
    expect(before.numberFamily).toBe(before.bodyFamily);
    // Guard the swap assertion below: if the baseline stack already contained the
    // face we set, "family contains Georgia" would pass without the slot doing
    // anything, and this test would prove nothing.
    expect(before.numberFamily).not.toContain('Georgia');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    // A literal stack, not var(--font-heading): the token resolves to the same
    // system stack the body already uses on a default install, so a token value
    // could not distinguish "the slot won" from "nothing happened".
    const res = await styleComponent(page, pageId, {
      '--stats-number-font': 'Georgia, serif',
      '--stats-number-weight': '600',
    });
    expect(res.success).toBe(true);

    // Both breakpoints, deliberately: .stats__number carries no media query today,
    // so this asserts the slots are viewport-independent. Add a mobile stats rule
    // later and the 375 pass is what catches it dropping the slot.
    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const after = await read();
      expect(after.numberWeight).toBe('600');
      expect(after.numberFamily).toContain('Georgia');
      expect(after.numberFamily).not.toBe(before.numberFamily);
      // The label is a SIBLING span: the display face must not reach it.
      expect(after.labelFamily).toBe(before.labelFamily);
    }
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

  test('#339 grid bullets still honor --grid-item-bullet-color after the shared-treatment refactor @smoke', async ({
    page,
  }) => {
    // Regression proof for the byte-identical grid claim: #339 moved grid's bullet
    // rules into the shared block and rewired the colour through the internal
    // --pp-list-marker-color indirection. StyleSlotContractTest only proves
    // --grid-item-bullet-color is *consumed*; only a rendered box proves the grid
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

    const res = await styleComponent(page, pageId, { '--grid-item-bullet-color': '#ff0080' });
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

  // #568 — a paired row had NO mobile rule: it kept its two-column geometry at every
  // width, so at 375 the value was squeezed into ~170px of a 247px row content box and a
  // 5-12 word comparison value wrapped to four RIGHT-aligned lines beside a one-word
  // label. (The operator who hit this on live production content abandoned paired rows
  // for plain string bullets to keep shipping.) The ruled default stacks the pair below
  // the mobile breakpoint, with five properties, and leaves >=768px alone.
  //
  // Declaration-level pins for all five live in SectionTextPanelTest. What only a
  // rendered box can prove is that the cascade DELIVERS them, and two of the five are
  // exactly the kind that go green on a declaration and wrong on the page:
  //
  //   * Property 4 (inter-pair rhythm). `.section__panel-list li` (0,1,1) owns
  //     margin-bottom and `:last-child` (0,1,2) zeroes it, so the obvious
  //     `.section__panel-row { margin-bottom }` (0,1,0) is a silent no-op and the
  //     (0,2,0) fix that beats it ALSO beats the last-child zero, hanging 16px of dead
  //     space above the panel CTA. The shipped answer is an adjacent-sibling margin-top.
  //     Both failures are invisible in the CSS text and obvious in a rendered margin.
  //   * Property 5 (the stacked pair reads as label-then-fact). The theme ships no
  //     webfont (--font-body is `system-ui, sans-serif`), so which weights exist is the
  //     client's business: in a bare Linux/CI font environment 600 resolves to the same
  //     face as 400 and renders PIXEL-IDENTICAL — the 375px A/B for this issue produced
  //     byte-identical PNGs for a weight-only treatment. So the font-SIZE step, which
  //     always renders, is what stands for the distinction here; the weight assertion
  //     pins the declaration only and is explicitly not the visual proof.
  //
  // The fixture is stressed on purpose: a long value (the reported defect), a long
  // LABEL, a long URL-ish value, a mixed string bullet, a label-only and a value-only
  // half-row, a per-row recolour, and a separate single-row panel whose one row is both
  // first and last child (the margin-top edge) sitting above a panel CTA.
  //
  // What this test does NOT pin, deliberately: containment of a value with NO line-break
  // opportunity at all (a hex digest, a long camelCase run). base.css sets overflow-wrap
  // only on p and h1-h6, so such a value paints outside the panel and scrolls the page —
  // at 375 that is document.scrollWidth 654 against a 375 client. That is PRE-EXISTING
  // and this change strictly improves it (742 before, 654 after: stacking widens the box
  // the text starts from), but it does not close it, and closing it is a sixth property
  // the #568 ruling does not carry. Filed as a follow-up. The URL value above is not
  // that case — it breaks at every `/` and `-` and wraps cleanly. Note also that the
  // valueWidth/rowWidth assertion below reads the BORDER box of a stretched flex item,
  // so it is structurally blind to overflowing text; it proves the value gets the full
  // measure, not that the text stays inside it.
  test('#568 paired rows stack below 767px and give the value the full measure; desktop unchanged @smoke', async ({
    page,
  }, testInfo) => {
    pageId = createPage('E2E Panel Row Mobile Stack');
    setComposition(pageId, [
      {
        component: 'section',
        props: {
          id: 'pp-sec-stack',
          layout: 'text-panel',
          title: 'Project shape',
          body: '<p>Left.</p>',
          panel_heading: 'At a glance',
          panel_items_marker: 'check',
          panel_items: [
            'All checks passing',
            { label: 'Timeline', value: 'Six to eight weeks from kickoff to launch' },
            { label: 'Included', value: 'Discovery, design, build, QA and a handover session' },
            { label: 'Support and maintenance retainer', value: 'Optional' },
            { label: 'Docs', value: 'https://example.com/a-very-long-unbreakable-documentation-path' },
            // Half-rows: the renderer emits BOTH spans even when one side is empty
            // (section.php:259-261), so an empty span is still a flex item and still
            // takes the column gap. Supported shapes, so their stacked geometry is
            // pinned too.
            { label: 'Notes' },
            { value: 'Standalone' },
            { label: 'Uptime', value: '99.9%', style: { '--section-panel-text': '#1d4ed8' } },
          ],
        },
      },
      {
        component: 'section',
        props: {
          id: 'pp-sec-stack-one',
          layout: 'text-panel',
          title: 'Single row',
          body: '<p>Left.</p>',
          panel_heading: 'Only one',
          panel_items: [
            { label: 'Plan', value: 'A single paired row is both the first and the last child of its list' },
          ],
          panel_cta_text: 'Get started',
          panel_cta_url: 'https://example.com',
        },
      },
    ]);

    // One row's geometry + type, plus the per-row margins of a whole list. Line count
    // is derived from the value box height over its line-height: the value span is a
    // flex ITEM, so it is blockified and getClientRects() collapses to a single rect —
    // counting rects would silently report 1 for a four-line value.
    const readPanel = (sectionId: string) =>
      page.locator(`#${sectionId} .section__panel-list`).evaluate((ul: HTMLElement) => {
        const rows = Array.from(ul.querySelectorAll('.section__panel-row')) as HTMLElement[];
        const read = (li: HTMLElement) => {
          const lab = li.querySelector('.section__panel-row-label') as HTMLElement;
          const val = li.querySelector('.section__panel-row-value') as HTMLElement;
          const vcs = getComputedStyle(val);
          const lcs = getComputedStyle(lab);
          const lics = getComputedStyle(li);
          const vr = val.getBoundingClientRect();
          const lr = lab.getBoundingClientRect();
          const lir = li.getBoundingClientRect();
          return {
            direction: lics.flexDirection,
            marginTop: parseFloat(lics.marginTop),
            marginBottom: parseFloat(lics.marginBottom),
            valueAlign: vcs.textAlign,
            valueSize: parseFloat(vcs.fontSize),
            labelSize: parseFloat(lcs.fontSize),
            valueWeight: vcs.fontWeight,
            labelWeight: lcs.fontWeight,
            labelColor: lcs.color,
            valueColor: vcs.color,
            labelTracking: lcs.letterSpacing,
            valueTracking: vcs.letterSpacing,
            labelLeft: lr.left,
            valueLeft: vr.left,
            valueWidth: vr.width,
            // CONTENT width: on a marker list `.pp-marker-list > li` carries a
            // 1.5rem marker indent even on a markerless paired row (pre-existing,
            // both breakpoints), so the border box is not the measure the value
            // can occupy.
            rowWidth: lir.width - parseFloat(lics.paddingLeft) - parseFloat(lics.paddingRight),
            valueLines: Math.round(vr.height / parseFloat(vcs.lineHeight)),
          };
        };
        return { rows: rows.map(read) };
      });

    // ── DESKTOP: #568 changes nothing at >=768px ───────────────────────────────
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-stack .section__panel-row')).toHaveCount(7, {
      timeout: 10000,
    });

    const desktop = await readPanel('pp-sec-stack');
    for (const r of desktop.rows) {
      expect(r.direction).toBe('row');
      expect(r.valueAlign).toBe('right');
      // Label and value stay one type: same size, weight and tracking.
      expect(r.labelSize).toBe(r.valueSize);
      expect(r.labelWeight).toBe(r.valueWeight);
      expect(r.labelTracking).toBe(r.valueTracking);
      // The label sits BESIDE the value, not above it.
      expect(r.valueLeft).toBeGreaterThan(r.labelLeft);
      // No inter-pair rhythm at desktop — the list's own 4px is the whole story.
      expect(r.marginTop).toBe(0);
    }
    expect(desktop.rows.slice(0, -1).every((r) => r.marginBottom === 4)).toBe(true);
    expect(desktop.rows[desktop.rows.length - 1].marginBottom).toBe(0);

    const desktopOne = await readPanel('pp-sec-stack-one');
    expect(desktopOne.rows[0].direction).toBe('row');
    expect(desktopOne.rows[0].marginTop).toBe(0);
    expect(desktopOne.rows[0].marginBottom).toBe(0);

    // ── MOBILE: the ruled five-property stack ──────────────────────────────────
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-stack .section__panel-row')).toHaveCount(7, {
      timeout: 10000,
    });

    const mobile = await readPanel('pp-sec-stack');
    for (const r of mobile.rows) {
      expect(r.direction).toBe('column'); // 1
      expect(r.valueAlign).toBe('left'); // 2
      // 2 (the point of it): ONE shared left edge, not a ragged one.
      expect(Math.abs(r.valueLeft - r.labelLeft)).toBeLessThanOrEqual(0.5);
      // The value gets the FULL row measure, not a share of it.
      expect(Math.abs(r.valueWidth - r.rowWidth)).toBeLessThanOrEqual(0.5);
      // 5: an explicit, always-rendered SIZE step marks the label as a label. This
      // is the assertion that stands for the visual distinction — see the header.
      expect(r.labelSize).toBeLessThan(r.valueSize);
      expect(r.labelTracking).not.toBe(r.valueTracking);
      // The weight split follows .hero__surface-key / -value. Computed weight is
      // reported from the DECLARATION, not from the face that got picked, so this
      // pins the declaration's presence; it is NOT the proof of distinction.
      expect(Number(r.valueWeight)).toBeGreaterThan(Number(r.labelWeight));
      // The distinction stays typographic: nothing here may recolour the label
      // away from the row's --section-panel-text.
      expect(r.labelColor).toBe(r.valueColor);
    }

    // The per-row --section-panel-text override still recolours the WHOLE pair at
    // mobile (the last row carries it), so the new label rule has not stolen the
    // item_eligible slot from the label half.
    expect(mobile.rows[mobile.rows.length - 1].labelColor).toBe('rgb(29, 78, 216)');
    expect(mobile.rows[mobile.rows.length - 1].valueColor).toBe('rgb(29, 78, 216)');

    // 4: the inter-pair rhythm is 16px BETWEEN pairs, and nowhere else. Row 0 follows
    // the string bullet, so it keeps the list's own rhythm (accepted: the ruling is
    // about the gap between PAIRS); rows 1..n follow a row and take the wider step.
    expect(mobile.rows[0].marginTop).toBe(0);
    for (const r of mobile.rows.slice(1)) {
      expect(r.marginTop).toBe(16);
    }
    // No dead space under the last row.
    expect(mobile.rows[mobile.rows.length - 1].marginBottom).toBe(0);

    // 3 vs 4: the intra-pair gap must be visibly TIGHTER than the inter-pair one, or
    // four pairs read as one eight-line block.
    const gap = await page
      .locator('#pp-sec-stack .section__panel-row')
      .first()
      .evaluate((el) => parseFloat(getComputedStyle(el).rowGap));
    expect(gap).toBe(4);
    expect(gap).toBeLessThan(mobile.rows[1].marginTop);

    // The reported defect, measured: the longest value takes FEWER lines at the full
    // stacked measure than it did in the old ~170px column. Measured as a difference
    // against the SAME text in the SAME font at the two widths, not as an absolute
    // line count — the theme ships no webfont, so an absolute count is a hostage to
    // whichever face `system-ui` resolves to on the machine running the suite, and
    // would go red on a font change that has nothing to do with this CSS.
    const wrap = await page
      .locator('#pp-sec-stack .section__panel-row-value')
      .nth(1) // "Discovery, design, build, QA and a handover session" — the longest
      .evaluate((el: HTMLElement) => {
        const lh = parseFloat(getComputedStyle(el).lineHeight);
        const lines = () => Math.round(el.getBoundingClientRect().height / lh);
        const atFullMeasure = lines();
        const prior = el.style.width;
        el.style.width = '170px'; // the pre-#568 column this value was squeezed into
        const atOldColumn = lines();
        el.style.width = prior;
        return { atFullMeasure, atOldColumn };
      });
    expect(wrap.atFullMeasure).toBeLessThan(wrap.atOldColumn);

    // The single-row panel: nothing to follow, so no margin-top, and no trailing gap
    // hanging above the panel CTA.
    const mobileOne = await readPanel('pp-sec-stack-one');
    expect(mobileOne.rows[0].direction).toBe('column');
    expect(mobileOne.rows[0].marginTop).toBe(0);
    expect(mobileOne.rows[0].marginBottom).toBe(0);

    for (const id of ['pp-sec-stack', 'pp-sec-stack-one']) {
      await testInfo.attach(`panel-row-stack-375-${id}`, {
        body: await page.locator(`#${id} .section__panel`).screenshot(),
        contentType: 'image/png',
      });
    }
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
  // that carries `max-width: var(--stats-heading-measure, var(--measure-heading))` (its own
  // cap since #578 severed the shared rule; the resolved value is still 40rem) and
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
 * (`border-radius: var(--grid-item-radius, var(--radius))`). --radius is
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
        body: 'Supporting copy.',
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
 * premium rule and routes color through `var(--hero-button2-color, var(--btn-text, var(--color-bg)))`.
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
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Secondary action',
          button2_url: '/learn',
          // PRIMARY variant so the secondary cta matches the [0,6,0] ink rule that
          // routes through --btn-text (the outline default would take a different rule).
          button2_variant: 'primary',
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
          button_text: 'Primary',
          button_url: '/start',
          button2_text: 'Secondary',
          button2_url: '/learn',
          button2_variant: 'primary', // matches the [0,6,0] ink rule that routes through --btn-text
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
      // The hero's SECOND cta, same two properties (#554). Its ink was already pinned above,
      // but ink is not what that issue changed: it rewrote this button's fill and ring chains.
      // Byte-identity-when-unset is the gate condition #554 shipped under, so it is asserted
      // on the RENDERED pixel here, not only as chain text in css-lint. A reorder that
      // repaints an unset cta2 through the premium-rule interaction is invisible to a static
      // pin and lands here instead.
      expect(got.heroSecondary.bgColor).toBe(got.accentFill);
      expect(got.heroSecondary.border).toBe(got.accentBorder);

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
      return {
        cta: read(sel.cta),
        heroPrimary: read(sel.heroPrimary),
        heroSecondary: read(sel.heroSecondary),
        section: read(sel.section),
      };
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

    /*
     * The hero's SECOND cta at REST (#554) — the surface this tier used to miss.
     *
     * Rendered, not static, because the defect was invisible to a chain pin: --btn-bg ALREADY
     * reached this button's background-IMAGE through the shared premium rule (clearing the
     * gradient) while its own [0,7,0] background-COLOR kept painting --color-accent. The
     * computed result was a FLAT ACCENT pill, which no static chain assertion describes. The
     * gradient-cleared check is what makes the pair assertion meaningful rather than
     * accidentally-passing.
     */
    expect(got.heroSecondary.bgColor, 'hero cta2 rest fill follows --btn-bg').toBe('rgb(1, 2, 3)');
    expect(got.heroSecondary.border, 'hero cta2 rest ring follows --btn-border-color').toBe(
      'rgb(7, 8, 9)',
    );
    expect(got.heroSecondary.bgImage, 'hero cta2 gradient cleared, not merely overpainted').toBe(
      'none',
    );
    // Stated as the pair property, so a future split fails with the right message.
    expect(got.heroSecondary.bgColor, 'hero pair rest fill must match').toBe(got.heroPrimary.bgColor);
    expect(got.heroSecondary.border, 'hero pair rest ring must match').toBe(got.heroPrimary.border);
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
 * #539 — the global button surface survives a HOVER.
 *
 * #458 (above) made `--btn-*` a real one-knob at REST. #530 gave every filled surface a
 * per-instance HOVER fill slot. Between them sat this gap: the global tier was
 * resting-state only, so an operator who rethemed every button with `--btn-bg` /
 * `--btn-border-color` got their brand at rest and the theme's premium accent gradient
 * back the moment a pointer touched any button on the site. `--btn-hover-bg` /
 * `--btn-hover-border-color` close it.
 *
 * Why this is an E2E and not a computed-chain unit pin: the bug lives in the CASCADE, not
 * in any single chain. The premium `main .btn:not(...):hover` rule [0,5,1] owns
 * background-IMAGE, while the component hover rules [0,6,0] own background-COLOR. Adding
 * the tier only to the premium rule computes correctly, clears the gradient, and is then
 * overridden right back to `--color-accent-hover` by the component rule — a knob that
 * looks wired in every unit assertion and is dead in a browser. Only a real hover on a
 * real composed page catches that, which is why all five filled surfaces are hovered here.
 *
 * Proven three ways, mirroring the #458 block:
 *   (a) UNSET is byte-identical on hover — the hovered button matches the browser's own
 *       resolution of the historical premium hover gradient, at 1280 AND 375.
 *   (b) SET reaches every filled surface, including the section-panel CTA (which #536 gave
 *       no per-instance hover slot, so the global tier is its ONLY hover fill authority).
 *   (c) per-instance hover slots still outrank the global knobs.
 */
test.describe('#539 the global button surface survives a hover (real WP)', () => {
  let pageId = 0;

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  // All FIVE filled surfaces on one page: hero primary, hero cta2, cta primary, cta
  // button2, section panel CTA. Both second buttons use `primary` variants so they are
  // FILLED (an outline/ghost second button takes a different rule and would not exercise
  // the gradient-clearing path at all).
  function buildHoverPage(): number {
    const id = createPage('E2E btn hover tier #539');
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'btn539-hero',
          title: 'Ship faster',
          button_text: 'Primary',
          button_url: '/start',
          button2_text: 'Secondary',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
      },
      {
        component: 'cta',
        props: {
          id: 'btn539-cta',
          title: 'Join us',
          button_text: 'Sign up',
          button_url: '/join',
          button2_text: 'Talk to us',
          button2_url: '/contact',
          button2_variant: 'primary',
        },
      },
      {
        component: 'section',
        props: {
          id: 'btn539-section',
          title: 'Details',
          layout: 'text-panel',
          panel_heading: 'Panel',
          panel_cta_text: 'Learn more',
          panel_cta_url: '/more',
        },
      },
    ]);
    return id;
  }

  const SEL = {
    heroPrimary: '.hero__cta:not(.hero__cta--secondary)',
    heroCta2: '.hero__cta--secondary',
    ctaPrimary: '.cta__button:not(.cta__button--secondary)',
    ctaButton2: '.cta__button--secondary',
    panelCta: '.section__panel-cta',
  };

  // Transitions animate colour over 150ms, so every read below must happen with them off or
  // getComputedStyle samples an intermediate frame (the #458 block's lesson).
  const NO_TRANSITION =
    '*,*::before,*::after{transition:none !important;animation:none !important;}';

  for (const width of [1280, 375]) {
    test(`unset global hover knobs keep every filled surface byte-identical on hover (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = buildHoverPage();
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(SEL.panelCta)).toBeVisible({ timeout: 10000 });
      await page.addStyleTag({ content: NO_TRANSITION });

      // The historical values, resolved by the BROWSER through throwaway probes that inherit
      // :root — so this compares against the theme's own resolution rather than brittle
      // hardcoded hex. The gradient alone is not enough: this diff changed the BORDER chain on
      // six rules and the background-color chain on four, and a reordering that repaints an
      // unset button while leaving background-image untouched would slip straight through.
      const probe = await page.evaluate(() => {
        const resolve = (prop: string, value: string) => {
          const el = document.createElement('div');
          el.style.setProperty(prop, value);
          document.body.appendChild(el);
          const v = getComputedStyle(el).getPropertyValue(prop);
          el.remove();
          return v;
        };
        return {
          gradient: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent) 0%, var(--color-accent-strong) 100%)',
          ),
          accentHover: resolve('background-color', 'var(--color-accent-hover)'),
          accent: resolve('background-color', 'var(--color-accent)'),
        };
      });

      // Each surface's hover border resolves a DIFFERENT literal when unset, so the expected
      // value is named per surface rather than asserted loosely. A bare truthiness check here
      // would pass through a reordered chain, a wrong literal, or an accidental repaint —
      // which is the whole failure class this test exists to catch.
      const expectedBorder: Record<string, string> = {
        heroPrimary: probe.accentHover,
        heroCta2: probe.accentHover,
        ctaPrimary: probe.accentHover,
        ctaButton2: probe.accentHover,
        // The generic panel CTA is governed by the premium rule, which bottoms out at
        // --color-accent rather than --color-accent-hover.
        panelCta: probe.accent,
      };

      for (const [name, sel] of Object.entries(SEL)) {
        await page.locator(sel).first().hover();
        const got = await page
          .locator(sel)
          .first()
          .evaluate((el) => {
            const cs = getComputedStyle(el);
            return { bgImage: cs.backgroundImage, border: cs.borderTopColor };
          });
        // Unset, the chain bottoms out at the premium hover gradient on every surface —
        // the gradient is still THERE, which is precisely the behaviour #539 complains
        // about when the operator HAS set a global fill, and must preserve when they have not.
        expect(got.bgImage, `${name} bgImage @${width}px`).toBe(probe.gradient);
        // The border chains gained a tier too: six rules changed. Unset, each must still
        // resolve the exact literal it resolved before.
        expect(got.border, `${name} border @${width}px`).toBe(expectedBorder[name]);
      }
    });
  }

  test('setting --btn-hover-bg / --btn-hover-border-color reaches every filled surface @smoke', async ({
    page,
  }) => {
    pageId = buildHoverPage();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.panelCta)).toBeVisible({ timeout: 10000 });

    // Sentinels no theme token resolves to, so a match proves the global knob reached.
    await page.addStyleTag({
      content:
        NO_TRANSITION +
        ':root{--btn-hover-bg:rgb(1,2,3);--btn-hover-border-color:rgb(7,8,9);}',
    });

    // The hero's SECOND cta joined this group in #554. It used to be carved out here to pin a
    // halfway outcome: its own [0,7,0] chains routed neither global knob, so the shared premium
    // rule cleared its gradient while its own background-color kept painting the theme accent —
    // a FLAT ACCENT pill beside a brand-coloured primary. Its chains now route the tier in both
    // states, so it behaves like every other filled surface and is asserted like one.
    const covered = ['heroPrimary', 'heroCta2', 'ctaPrimary', 'ctaButton2', 'panelCta'] as const;

    for (const name of covered) {
      await page.locator(SEL[name]).first().hover();
      const got = await page
        .locator(SEL[name])
        .first()
        .evaluate((el) => {
          const cs = getComputedStyle(el);
          return { bgColor: cs.backgroundColor, bgImage: cs.backgroundImage, border: cs.borderTopColor };
        });

      // A flat colour resolves the premium `background` SHORTHAND to `background: <color>`,
      // which resets background-image to `none` and CLEARS the gradient that used to mask
      // every hover fill. Both halves matter: the colour AND the absence of the gradient.
      expect(got.bgColor, `${name} hover fill`).toBe('rgb(1, 2, 3)');
      expect(got.bgImage, `${name} hover gradient cleared`).toBe('none');
      expect(got.border, `${name} hover border`).toBe('rgb(7, 8, 9)');
    }

    /*
     * PAIR CONSISTENCY (#554) — the property the loop above cannot state on its own.
     *
     * Each surface is asserted against the sentinel individually, so a regression that split
     * the hero's two buttons apart again would surface as two separate failures rather than as
     * the thing that actually matters: the pair no longer matching. Assert it directly, so the
     * failure message names the defect.
     */
    // Sampled ONE AT A TIME, each while it is the hovered element. Only one element can be
    // :hover at a time, so a single evaluate() comparing both buttons would read them BOTH at
    // rest — which passes even if the hover chains are deleted outright. The pointer is over
    // panelCta when the loop above ends, so without an explicit hover per read this measures
    // the resting state and cannot fail.
    const readHovered = async (sel: string) => {
      await page.locator(sel).first().hover();
      return page
        .locator(sel)
        .first()
        .evaluate((el) => {
          const cs = getComputedStyle(el);
          return { bgColor: cs.backgroundColor, border: cs.borderTopColor };
        });
    };
    const pair = {
      primary: await readHovered(SEL.heroPrimary),
      cta2: await readHovered(SEL.heroCta2),
    };
    // Guard the guard: prove these are hover reads, not rest reads. The sentinel only appears
    // in the hover chains, so a rest sample cannot produce it.
    expect(pair.primary.bgColor, 'sanity: primary sampled while hovered').toBe('rgb(1, 2, 3)');
    expect(pair.cta2.bgColor, 'hero pair hover fill must match under a site-wide retheme').toBe(
      pair.primary.bgColor,
    );
    expect(pair.cta2.border, 'hero pair hover ring must match under a site-wide retheme').toBe(
      pair.primary.border,
    );
  });

  test('a per-instance hover slot still beats the global --btn-hover-bg @smoke', async ({
    page,
  }) => {
    pageId = buildHoverPage();

    // Style ONLY the hero's own hover fill slot (component index 0), then set a conflicting
    // global knob. The per-instance slot must win on that button; the cta primary, which has
    // no per-instance hover slot set, must still take the global one.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await styleComponent(
      page,
      pageId,
      { '--hero-button-hover-bg': 'rgb(20,30,40)' },
      undefined,
      0,
    );
    expect(res.success).toBeTruthy();

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.ctaPrimary)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({
      content: NO_TRANSITION + ':root{--btn-hover-bg:rgb(1,2,3);}',
    });

    await page.locator(SEL.heroPrimary).first().hover();
    const heroFill = await page
      .locator(SEL.heroPrimary)
      .first()
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    // Per-instance slot wins over the global knob.
    expect(heroFill).toBe('rgb(20, 30, 40)');

    await page.locator(SEL.ctaPrimary).first().hover();
    const ctaFill = await page
      .locator(SEL.ctaPrimary)
      .first()
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    // ...and the global knob still governs the surface that set no slot of its own.
    expect(ctaFill).toBe('rgb(1, 2, 3)');
  });

  test('a per-instance hover BORDER slot still beats the global --btn-hover-border-color @smoke', async ({
    page,
  }) => {
    pageId = buildHoverPage();

    // --btn-hover-border-color was threaded into six border chains at six different positions.
    // Chain-order string pins catch a text edit, but only a rendered hover proves the
    // PRECEDENCE actually holds in the cascade — the exact distinction that makes this whole
    // block an E2E rather than a unit test.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    const res = await styleComponent(
      page,
      pageId,
      { '--cta-button-hover-border': 'rgb(60,70,80)' },
      undefined,
      1,
    );
    expect(res.success).toBeTruthy();

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(SEL.ctaPrimary)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({
      content: NO_TRANSITION + ':root{--btn-hover-border-color:rgb(7,8,9);}',
    });

    await page.locator(SEL.ctaPrimary).first().hover();
    const authored = await page
      .locator(SEL.ctaPrimary)
      .first()
      .evaluate((el) => getComputedStyle(el).borderTopColor);
    // The authored per-instance ring wins over the global knob.
    expect(authored).toBe('rgb(60, 70, 80)');

    await page.locator(SEL.panelCta).first().hover();
    const global = await page
      .locator(SEL.panelCta)
      .first()
      .evaluate((el) => getComputedStyle(el).borderTopColor);
    // ...and a surface with no per-instance ring still takes the global one.
    expect(global).toBe('rgb(7, 8, 9)');
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
      { component: 'stats', props: { id: 'pp-stats01', theme: 'muted', items: [{ number: '10', label: 'Ten' }] } },
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'cta', props: { id: 'pp-cta01', theme: 'muted', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', theme: 'muted', title: 'Closing', button_text: 'Go', button_url: '/go' } },
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
    { comp: 'table', sel: '.table-section', slot: '--table-padding-top', px: '5px',
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
    { band: 'section', sel: '.section__title', slot: '--section-heading-size' },
    { band: 'grid', sel: '.grid__heading', slot: '--grid-heading-size' },
    { band: 'cta', sel: '.cta__title', slot: '--cta-heading-size' },
    { band: 'stats', sel: '.stats__heading', slot: '--stats-heading-size' },
    { band: 'table', sel: '.table-section__heading', slot: '--table-heading-size' },
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
  // existing slot (--cta-heading-size) plus ALL FOUR slots first introduced by #436
  // (--table-heading-size, --logos-heading-size, --embed-heading-size,
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
      { idx: 0, slot: '--cta-heading-size', px: '60px', sel: '.cta__title' },
      { idx: 1, slot: '--table-heading-size', px: '61px', sel: '.table-section__heading' },
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
      { component: 'stats', props: { id: 'pp-stats01', theme: 'muted', title: 'Stats', items: [{ number: '10', label: 'Ten' }] } },
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'cta', props: { id: 'pp-cta01', theme: 'muted', title: 'CTA', button_text: 'Go', button_url: '/go' } },
      { component: 'grid', props: { id: 'pp-grid02', title: 'Grid Two', items: [{ title: 'Two', text: 'B' }] } },
      { component: 'section', props: { id: 'pp-sec01', title: 'Section', body: '<p>Section body.</p>' } },
      { component: 'cta', props: { id: 'pp-cta02', theme: 'muted', title: 'Closing', button_text: 'Go', button_url: '/go' } },
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
 * Since #439, cta.body and testimonials.quote render an inline-HTML subset, so a
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
      // #439: cta.body became an inline-HTML surface, so an inverted CTA can carry
      // a real body link sitting directly on the dark band. It must reach AA.
      name: 'cta body link on the dark band → on-inverted (AA)',
      composition: [
        {
          component: 'cta',
          props: {
            id: 'pp-cta01',
            theme: 'inverted',
            title: 'Inverted cta',
            body: 'Read our <a href="/terms">terms</a> before you sign up.',
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
        body: 'Read our <a href="/terms">terms</a> before you sign up.',
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
    (b[0].props as Record<string, unknown>).__pp_style = { '--section-body-link-color': SLOT };
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
        body: 'Sign up before the deadline.',
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
    { name: 'section title-accent', accent: '.section--has-bg-image .section__title-accent', pseudo: '', slot: '--section-heading-accent-color', overlay: '.section--has-bg-image .section__overlay' },
    { name: 'section list marker', accent: '.section--has-bg-image .section__content--marker-check > ul > li', pseudo: '::before', slot: '--section-body-marker-color', overlay: '.section--has-bg-image .section__overlay' },
    { name: 'cta title-accent', accent: '.cta--has-bg-image .cta__title-accent', pseudo: '', slot: '--cta-heading-accent-color', overlay: '.cta--has-bg-image .cta__overlay' },
    { name: 'stats heading-accent', accent: '.stats--has-bg-image .stats__heading-accent', pseudo: '', slot: '--stats-heading-accent-color', overlay: '.stats--has-bg-image .stats__overlay' },
    { name: 'hero cover title-accent', accent: '.hero--cover .hero__title-accent', pseudo: '', slot: '--hero-heading-accent-color', overlay: '.hero--cover .hero__overlay' },
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
      '--section-heading-accent-color': SLOT,
      '--section-body-marker-color': SLOT,
    };
    (b[1].props as Record<string, unknown>).__pp_style = { '--cta-heading-accent-color': SLOT };
    (b[2].props as Record<string, unknown>).__pp_style = { '--stats-heading-accent-color': SLOT };
    (b[3].props as Record<string, unknown>).__pp_style = { '--hero-heading-accent-color': SLOT };
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
 * #439 — a link in cta.body renders as a real anchor, not escaped source.
 *
 * Before #439 cta.body was esc_html, so `<a href=...>` written by the AI rendered
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
          body: 'Read our <a href="/terms">terms</a> first. <a href="javascript:alert(1)">x</a><script>alert(2)</script>',
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

  // Slot independence, half 2: an explicit --section-heading-color moves the on-band title
  // and must NOT reach into the self-contained panel heading.
  test('--section-heading-color moves the band title only @375 + @1280', async ({ page }) => {
    pageId = createPage('E2E 424 title-color slot');
    setComposition(pageId, invertedTextPanel());

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    const res = await styleComponent(page, pageId, { '--section-heading-color': '#00e5ff' });
    expect(res.success).toBe(true);

    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__panel-heading')).toBeVisible({ timeout: 10000 });

      const title = await colorOf(page, '.pp-section--inverted .section__title');
      const heading = await colorOf(page, '.section__panel-heading');
      expect(title, `@${width}: band title should honor --section-heading-color`).toBe('rgb(0, 229, 255)');
      expect(heading, `@${width}: --section-heading-color must not reach the panel heading`).not.toBe('rgb(0, 229, 255)');
    }
  });
});

/**
 * #526 — the hero's SECOND CTA is isolated from the primary button's fill slots, and its
 * own fill slot actually paints.
 *
 * Style slots are emitted as inline custom properties on the .hero ROOT, so #514's
 * --hero-button-* slots INHERIT onto the second CTA. A cta2 authored as the filled
 * `primary` variant also matches the shared premium `main .btn:not(...)` winner, so the
 * PRIMARY's fill/elevation repainted it (the leak). Separately, --hero-button2-bg was
 * consumed only as `background-color` and the premium gradient background-IMAGE covered
 * it (the mask) — the same defect #514 fixed for the primary.
 *
 * Both halves are invisible to CSS-TEXT pins: the masking bug lived for months while the
 * static --hero-button2-bg guards stayed green, because a background-color under a gradient
 * is present in the text and invisible on screen. Only getComputedStyle in a real browser
 * separates "declared" from "painted", so these are the acceptance pins for the fix.
 * Literals are probe-resolved (the #458 idiom) so byte-identical compares against the
 * browser's own resolution of the historical premium gradient, not a hardcoded hex.
 */
test.describe('#526 hero cta2 fill slots are isolated and painted (real WP)', () => {
  let pageId = 0;

  const CTA2_TEAL = '#0f766e';
  const PRIMARY_PURPLE = '#7c3aed';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  // A hero whose SECOND cta renders as the filled `primary` variant — the only shape in
  // which cta2 enters the premium cascade, i.e. the shape both defects need.
  function filledCta2Page(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'pp-526-hero',
          title: 'Ship faster',
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Second action',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  // Computed fill/ink/elevation for both hero CTAs, plus the browser's own resolution of
  // the premium rest literals the unset chains bottom out at.
  async function readButtons(page: any) {
    return page.evaluate(() => {
      const resolve = (prop: string, value: string) => {
        const el = document.createElement('div');
        el.style.setProperty(prop, value);
        document.body.appendChild(el);
        const out = getComputedStyle(el).getPropertyValue(prop);
        el.remove();
        return out.trim();
      };
      const read = (sel: string) => {
        const el = document.querySelector(sel) as HTMLElement;
        const cs = getComputedStyle(el);
        return {
          bgColor: cs.backgroundColor,
          bgImage: cs.backgroundImage,
          borderColor: cs.borderTopColor,
          color: cs.color,
          shadow: cs.boxShadow,
        };
      };
      return {
        primary: read('.hero__cta:not(.hero__cta--secondary)'),
        cta2: read('.hero__cta--secondary'),
        premiumGradient: resolve(
          'background-image',
          'linear-gradient(180deg, var(--color-accent-strong) 0%, var(--color-accent-hover) 100%)',
        ),
        teal: resolve('background-color', '#0f766e'),
      };
    });
  }

  for (const width of [1280, 375]) {
    // Half 1 — the LEAK. An author restyles the PRIMARY only; the filled cta2 must keep
    // the premium gradient and bevel it had before the primary was touched.
    test(`--hero-button-* never reach a filled cta2 (${width}px) @smoke`, async ({ page }) => {
      pageId = filledCta2Page('E2E 526 leak', {
        '--hero-button-bg': PRIMARY_PURPLE,
        '--hero-button-color': '#fffbe6',
        '--hero-button-shadow': 'none',
        // cta2's OWN ink, set so the ink assertion below can prove the positive
        // (cta2 keeps its own slot) and not merely the negative (it is not the
        // primary's ink, which a third unrelated color would also satisfy).
        '--hero-button2-color': '#e0f2f1',
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.hero__cta--secondary')).toBeVisible({ timeout: 10000 });

      const got = await readButtons(page);

      // Fixture sanity: the slots really did reach the PRIMARY (a no-op fixture would
      // make every cta2 assertion below pass vacuously).
      expect(got.primary.bgImage, `@${width}: primary should be a flat fill`).toBe('none');
      expect(got.primary.bgColor, `@${width}: primary should take --hero-button-bg`).toBe(
        'rgb(124, 58, 237)',
      );
      expect(got.primary.shadow, `@${width}: primary should be flattened`).toBe('none');

      // The leak: cta2 must be untouched by all three.
      expect(got.cta2.bgImage, `@${width}: cta2 must keep the premium gradient`).toBe(
        got.premiumGradient,
      );
      expect(got.cta2.bgColor, `@${width}: cta2 must not take the primary fill`).not.toBe(
        'rgb(124, 58, 237)',
      );
      expect(got.cta2.color, `@${width}: cta2 must keep its own --hero-button2-color`).toBe(
        'rgb(224, 242, 241)',
      );
      expect(got.cta2.shadow, `@${width}: cta2 must keep the premium bevel`).not.toBe('none');
    });

    // Half 2 — the MASK. --hero-button2-bg must clear the gradient and actually paint.
    test(`--hero-button2-bg paints a filled cta2 (${width}px) @smoke`, async ({ page }) => {
      pageId = filledCta2Page('E2E 526 fill', { '--hero-button2-bg': CTA2_TEAL });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.hero__cta--secondary')).toBeVisible({ timeout: 10000 });

      const got = await readButtons(page);

      expect(got.cta2.bgImage, `@${width}: the gradient must be cleared, not covering the slot`).toBe(
        'none',
      );
      expect(got.cta2.bgColor, `@${width}: cta2 must paint --hero-button2-bg`).toBe(got.teal);
      // Border FOLLOWS the fill when --hero-button2-border / --hero-accent are unset (issue 526,
      // the #514 idiom): a fill-only recolor must not leave a --color-accent ring around a
      // brand-colored button.
      expect(got.cta2.borderColor, `@${width}: cta2 border must follow the fill`).toBe(got.teal);
      // Slot independence: the untouched PRIMARY keeps the premium gradient.
      expect(got.primary.bgImage, `@${width}: the primary must be untouched`).toBe(
        got.premiumGradient,
      );
    });
  }

  // Byte-identical when unset: with neither slot family set, both CTAs render the premium
  // gradient — the invariant the isolation rule must not disturb.
  test('both CTAs stay byte-identical with no fill slots set @smoke', async ({ page }) => {
    pageId = filledCta2Page('E2E 526 unset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.hero__cta--secondary')).toBeVisible({ timeout: 10000 });

    const got = await readButtons(page);

    expect(got.cta2.bgImage, 'unset cta2 must keep the premium gradient').toBe(got.premiumGradient);
    expect(got.primary.bgImage, 'unset primary must keep the premium gradient').toBe(
      got.premiumGradient,
    );
    expect(got.cta2.bgColor).toBe(got.primary.bgColor);
    expect(got.cta2.color).toBe(got.primary.color);
    expect(got.cta2.shadow).toBe(got.primary.shadow);
  });
});

/**
 * #530 — per-instance HOVER fill slots actually paint on a FILLED button, and hover is
 * isolated between the two buttons the way rest already is.
 *
 * The shared premium hover rule paints a `background:` SHORTHAND carrying a gradient
 * background-IMAGE. Every component-level hover rule sets only `background-color`, which
 * that image covers, so --hero-button2-hover-bg / --cta-button2-hover-bg rendered NOTHING on a
 * filled button and the hero primary had no hover fill slot at all. Separately, the #514/#526
 * isolation rules re-pointed only the REST slot, so the primary's hover fill leaked onto the
 * second button (the coupling found in #474's review).
 *
 * This is precisely the class of defect CSS-TEXT pins cannot see: a background-color sitting
 * under a gradient is present in the stylesheet text and invisible on screen. It is also
 * invisible to a REST-state computed pin, which is why #514/#526/#474 all shipped it as an
 * accepted trait. Only getComputedStyle under a real :hover separates "declared" from
 * "painted" — so these are the acceptance pins, and they assert backgroundImage (the masking
 * layer) alongside backgroundColor, never backgroundColor alone.
 *
 * Literals are probe-resolved (the #458 idiom) so the byte-identical compares run against the
 * browser's own resolution of the historical premium hover gradient, not a hardcoded hex.
 */
test.describe('#530 hover fill slots paint and stay isolated (real WP)', () => {
  let pageId = 0;

  const PRIMARY_HOVER = '#b91c1c';
  const SECOND_HOVER = '#0f766e';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  // A hero whose SECOND cta renders filled `primary` — the only shape in which cta2 enters
  // the premium cascade, i.e. the shape the defect needs.
  function heroPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'pp-530-hero',
          title: 'Ship faster',
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Second action',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  // The cta component's equivalent: a filled `primary` button2 alongside the primary button.
  function ctaPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'cta',
        props: {
          id: 'pp-530-cta',
          title: 'Ready to start?',
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Second action',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  // Hover one button, then read BOTH — Playwright's hover leaves the pointer in place, so
  // the sibling is read in its resting state, which is exactly the comparison we want.
  async function readOnHover(page: any, hoverSel: string, primarySel: string, secondSel: string) {
    await page.hover(hoverSel);
    return page.evaluate(
      ([pSel, sSel, pHex, sHex]: [string, string, string, string]) => {
        const resolve = (prop: string, value: string) => {
          const el = document.createElement('div');
          el.style.setProperty(prop, value);
          document.body.appendChild(el);
          const out = getComputedStyle(el).getPropertyValue(prop);
          el.remove();
          return out.trim();
        };
        const read = (sel: string) => {
          const el = document.querySelector(sel) as HTMLElement;
          const cs = getComputedStyle(el);
          return {
            bgColor: cs.backgroundColor,
            bgImage: cs.backgroundImage,
            borderColor: cs.borderTopColor,
          };
        };
        return {
          primary: read(pSel),
          second: read(sSel),
          // The premium HOVER gradient literal (distinct from the rest one).
          hoverGradient: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent) 0%, var(--color-accent-strong) 100%)',
          ),
          // The premium REST gradient, for asserting an untouched sibling precisely.
          restGradient: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent-strong) 0%, var(--color-accent-hover) 100%)',
          ),
          primaryHover: resolve('background-color', pHex),
          secondHover: resolve('background-color', sHex),
        };
      },
      [primarySel, secondSel, PRIMARY_HOVER, SECOND_HOVER],
    );
  }

  const HERO_PRIMARY = '.hero__cta:not(.hero__cta--secondary)';
  const HERO_SECOND = '.hero__cta--secondary';
  const CTA_PRIMARY = '.cta__button:not(.cta__button--secondary)';
  const CTA_SECOND = '.cta__button--secondary';

  for (const width of [1280, 375]) {
    test(`hero primary hover fill paints and never reaches cta2 (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = heroPage('E2E 530 hero primary', { '--hero-button-hover-bg': PRIMARY_HOVER });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(HERO_SECOND)).toBeVisible({ timeout: 10000 });
      await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

      const got = await readOnHover(page, HERO_PRIMARY, HERO_PRIMARY, HERO_SECOND);

      // The fix: the gradient IMAGE is cleared, so the slot's flat color is the visible fill.
      expect(got.primary.bgImage, `@${width}: the hover gradient must be cleared`).toBe('none');
      expect(got.primary.bgColor, `@${width}: primary must paint --hero-button-hover-bg`).toBe(
        got.primaryHover,
      );
      // The hero primary's hover border FOLLOWS the new fill slot when --hero-accent-hover is
      // unset (issue 530, mirroring the rest chain). Pinned at RENDER level, not just CSS text.
      expect(got.primary.borderColor, `@${width}: primary hover border must follow the fill`).toBe(
        got.primaryHover,
      );
      // Isolation: the resting cta2 must be untouched by the primary's hover slot. Compare
      // against the resting gradient probe rather than merely `!== none`, which would pass for
      // any image at all.
      expect(got.second.bgImage, `@${width}: cta2 must not take the primary hover fill`).toBe(
        got.restGradient,
      );
    });

    test(`cta2 hover fill paints and is independent of the primary (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = heroPage('E2E 530 hero cta2', {
        '--hero-button-hover-bg': PRIMARY_HOVER,
        '--hero-button2-hover-bg': SECOND_HOVER,
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(HERO_SECOND)).toBeVisible({ timeout: 10000 });
      await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

      const got = await readOnHover(page, HERO_SECOND, HERO_PRIMARY, HERO_SECOND);

      expect(got.second.bgImage, `@${width}: cta2's hover gradient must be cleared`).toBe('none');
      expect(got.second.bgColor, `@${width}: cta2 must paint --hero-button2-hover-bg`).toBe(
        got.secondHover,
      );
      // The hover BORDER now FOLLOWS the hover fill (issue 538, Option 3). #530 pinned the
      // negative here; that pin is flipped, not deleted. With --hero-button2-hover-border and
      // --hero-accent-hover both unset, the fill is the last link before the theme default,
      // so a fill-only recolor gets a MATCHING ring instead of a --color-accent-hover one.
      expect(got.second.borderColor, `@${width}: cta2 hover border must follow the fill`).toBe(
        got.secondHover,
      );
      // The two buttons carry DISTINCT hover fills — the capability, not just the absence
      // of a leak.
      expect(got.second.bgColor).not.toBe(got.primaryHover);
    });

    test(`cta button2 hover fill paints and the primary's does not leak (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = ctaPage('E2E 530 cta pair', {
        '--cta-button-hover-bg': PRIMARY_HOVER,
        '--cta-button2-hover-bg': SECOND_HOVER,
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(CTA_SECOND)).toBeVisible({ timeout: 10000 });
      await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

      const onSecond = await readOnHover(page, CTA_SECOND, CTA_PRIMARY, CTA_SECOND);
      expect(onSecond.second.bgImage, `@${width}: button2's hover gradient must be cleared`).toBe(
        'none',
      );
      expect(onSecond.second.bgColor, `@${width}: button2 must paint --cta-button2-hover-bg`).toBe(
        onSecond.secondHover,
      );
      expect(onSecond.second.bgColor).not.toBe(onSecond.primaryHover);

      const onPrimary = await readOnHover(page, CTA_PRIMARY, CTA_PRIMARY, CTA_SECOND);
      expect(onPrimary.primary.bgImage, `@${width}: primary hover gradient must be cleared`).toBe(
        'none',
      );
      expect(onPrimary.primary.bgColor, `@${width}: primary must paint --cta-button-hover-bg`).toBe(
        onPrimary.primaryHover,
      );
    });
  }

  // The MASK half, in isolation. The paired tests above set BOTH hover slots, so the
  // gradient-clearing there could be credited to the PRIMARY's slot resolving the shared
  // shorthand rather than the second button's own. These set ONLY the second button's hover
  // slot, which is the actual capability #530 delivers for cta2 / button2.
  test('cta2 hover fill alone clears the gradient (primary hover slot unset) @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 530 cta2 alone', { '--hero-button2-hover-bg': SECOND_HOVER });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(HERO_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const got = await readOnHover(page, HERO_SECOND, HERO_PRIMARY, HERO_SECOND);

    expect(got.second.bgImage, 'cta2 own hover slot must clear the gradient').toBe('none');
    expect(got.second.bgColor, 'cta2 must paint its own hover fill').toBe(got.secondHover);
    // The untouched primary still hovers to the premium gradient.
    expect(got.primary.bgImage, 'primary must be unaffected').not.toBe('none');
  });

  test('button2 hover fill alone clears the gradient (primary hover slot unset) @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 530 button2 alone', { '--cta-button2-hover-bg': SECOND_HOVER });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(CTA_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const got = await readOnHover(page, CTA_SECOND, CTA_PRIMARY, CTA_SECOND);

    expect(got.second.bgImage, 'button2 own hover slot must clear the gradient').toBe('none');
    expect(got.second.bgColor, 'button2 must paint its own hover fill').toBe(got.secondHover);
    expect(got.primary.bgImage, 'primary must be unaffected').not.toBe('none');
  });

  // The HERO half of the isolation fix, at render level. Without this test, deleting
  // `--hero-button-hover-bg: var(--hero-button2-hover-bg)` from the hero isolation rule would
  // fail only the CSS-TEXT pin in StyleSlotContractTest — the exact pin class this block's
  // header says cannot see the defect. Note the other hero tests do NOT cover it: the primary
  // test reads cta2 at REST, and the cta2 test sets BOTH slots (cta2's own higher-specificity
  // background-color would still win there with the declaration removed).
  test('setting only the hero primary hover fill leaves cta2 on the premium gradient @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 530 hero leak', { '--hero-button-hover-bg': PRIMARY_HOVER });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(HERO_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const got = await readOnHover(page, HERO_SECOND, HERO_PRIMARY, HERO_SECOND);

    expect(got.second.bgImage, 'cta2 hover must keep the premium gradient').toBe(
      got.hoverGradient,
    );
    expect(got.second.bgColor, 'cta2 must not take the primary hover fill').not.toBe(
      got.primaryHover,
    );
  });

  // The cta pair's byte-identical-when-unset invariant (the hero has its own below). The cta
  // isolation rule gained a declaration too, so its unset render needs its own proof.
  test('cta pair hovers byte-identically with no hover fill slots set @smoke', async ({ page }) => {
    pageId = ctaPage('E2E 530 cta unset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(CTA_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const onPrimary = await readOnHover(page, CTA_PRIMARY, CTA_PRIMARY, CTA_SECOND);
    expect(onPrimary.primary.bgImage, 'unset cta primary must hover to the premium gradient').toBe(
      onPrimary.hoverGradient,
    );

    const onSecond = await readOnHover(page, CTA_SECOND, CTA_PRIMARY, CTA_SECOND);
    expect(onSecond.second.bgImage, 'unset button2 must hover to the premium gradient').toBe(
      onSecond.hoverGradient,
    );
    expect(onSecond.second.bgColor).toBe(onPrimary.primary.bgColor);
    expect(onSecond.second.borderColor).toBe(onPrimary.primary.borderColor);
  });

  // The #474 cross-button hover coupling, isolated: set ONLY the primary's hover fill and the
  // second button must keep the premium hover gradient. Before #530 the primary's slot
  // inherited down and cleared it.
  test('setting only the primary hover fill leaves button2 on the premium gradient @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 530 cta leak', { '--cta-button-hover-bg': PRIMARY_HOVER });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(CTA_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const got = await readOnHover(page, CTA_SECOND, CTA_PRIMARY, CTA_SECOND);

    expect(got.second.bgImage, 'button2 hover must keep the premium gradient').toBe(
      got.hoverGradient,
    );
    expect(got.second.bgColor, 'button2 must not take the primary hover fill').not.toBe(
      got.primaryHover,
    );
  });

  // Byte-identical when unset: with no hover slots set, both buttons hover to the premium
  // gradient — the invariant the new declarations must not disturb.
  test('both buttons hover byte-identically with no hover fill slots set @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 530 unset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(HERO_SECOND)).toBeVisible({ timeout: 10000 });
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });

    const onPrimary = await readOnHover(page, HERO_PRIMARY, HERO_PRIMARY, HERO_SECOND);
    expect(onPrimary.primary.bgImage, 'unset primary must hover to the premium gradient').toBe(
      onPrimary.hoverGradient,
    );

    const onSecond = await readOnHover(page, HERO_SECOND, HERO_PRIMARY, HERO_SECOND);
    expect(onSecond.second.bgImage, 'unset cta2 must hover to the premium gradient').toBe(
      onSecond.hoverGradient,
    );
    expect(onSecond.second.bgColor).toBe(onPrimary.primary.bgColor);
    expect(onSecond.second.borderColor).toBe(onPrimary.primary.borderColor);
  });
});

/**
 * #538 — the hover ring on the two FILLED second buttons follows the hover fill, but only
 * from the LAST fallback position (the maintainer's Option 3).
 *
 * At rest a second button's border already follows its fill (#526/#474), so a fill-only
 * recolor keeps a matching ring. On hover it did not: #530 deliberately left the fill out of
 * the hover border chain, because unlike the hover FILL (which the premium gradient masked,
 * so it rendered nothing and no shipped composition could depend on it) the hover BORDER has
 * always painted. Inserting the fill AHEAD of the accent knob would therefore have repainted
 * an explicitly authored ring on live sites — --hero-button2-hover-bg and --cta-button2-hover-bg
 * both shipped in v1.10.0. Option 3 puts the fill BEHIND the accent knob instead.
 *
 * That makes the contract directional, and direction is exactly what a CSS-text pin proves
 * weakly and a render pin proves outright. These tests walk the whole slot lattice per
 * component — fill-only, fill+accent, fill+border, accent-only — and assert the RESOLVED
 * border color under a real :hover. The middle two are the tests that distinguish Option 3
 * from the rejected Option 2: under Option 2 they would fail, under Option 3 they must show
 * the authored value winning and the fill being ignored.
 *
 * Literals are probe-resolved (the #458 idiom) so comparisons run against the browser's own
 * resolution rather than a hardcoded hex.
 */
test.describe('#538 filled second-button hover ring follows the hover fill (real WP)', () => {
  let pageId = 0;

  const FILL = '#7c3aed'; // the purple from the v1.11.0 dev smoke that exposed this
  const ACCENT = '#ff8800';
  const BORDER = '#10b981';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  function heroPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'pp-538-hero',
          title: 'Ship faster',
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Second action',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  function ctaPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'cta',
        props: {
          id: 'pp-538-cta',
          title: 'Ready to start?',
          button_text: 'Primary action',
          button_url: '/start',
          button2_text: 'Second action',
          button2_url: '/learn',
          button2_variant: 'primary',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  const HERO_SECOND = '.hero__cta--secondary';
  const CTA_SECOND = '.cta__button--secondary';

  /** Hover the second button and read its painted fill + ring, plus probe-resolved literals. */
  async function ringOnHover(page: any, sel: string) {
    await page.hover(sel);
    return page.evaluate(
      ([s, fillHex, accentHex, borderHex]: [string, string, string, string]) => {
        const resolve = (value: string) => {
          const el = document.createElement('div');
          el.style.setProperty('background-color', value);
          document.body.appendChild(el);
          const out = getComputedStyle(el).getPropertyValue('background-color');
          el.remove();
          return out.trim();
        };
        const cs = getComputedStyle(document.querySelector(s) as HTMLElement);
        return {
          bgColor: cs.backgroundColor,
          borderColor: cs.borderTopColor,
          fill: resolve(fillHex),
          accent: resolve(accentHex),
          border: resolve(borderHex),
          themeHover: resolve('var(--color-accent-hover)'),
        };
      },
      [sel, FILL, ACCENT, BORDER],
    );
  }

  async function open(page: any, id: number, sel: string, width: number) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${id}`);
    await expect(page.locator(sel)).toBeVisible({ timeout: 10000 });
    // Kill transitions so the hover read is the settled value, not a mid-animation sample.
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });
  }

  for (const width of [1280, 375]) {
    // THE DEFECT. Exactly the configuration the v1.11.0 dev smoke rendered: a purple hover
    // fill under a --color-accent-hover blue ring. Post-#538 the ring must be purple too.
    test(`hero cta2 fill-only hover ring matches the fill (${width}px) @smoke`, async ({ page }) => {
      pageId = heroPage('E2E 538 hero fill only', { '--hero-button2-hover-bg': FILL });
      await open(page, pageId, HERO_SECOND, width);

      const got = await ringOnHover(page, HERO_SECOND);
      expect(got.bgColor, `@${width}: cta2 must paint the authored hover fill`).toBe(got.fill);
      expect(got.borderColor, `@${width}: the ring must follow the fill`).toBe(got.fill);
      expect(got.borderColor, `@${width}: the ring must no longer fall to the theme default`).not.toBe(
        got.themeHover,
      );
    });

    test(`cta button2 fill-only hover ring matches the fill (${width}px) @smoke`, async ({ page }) => {
      pageId = ctaPage('E2E 538 cta fill only', { '--cta-button2-hover-bg': FILL });
      await open(page, pageId, CTA_SECOND, width);

      const got = await ringOnHover(page, CTA_SECOND);
      expect(got.bgColor, `@${width}: button2 must paint the authored hover fill`).toBe(got.fill);
      expect(got.borderColor, `@${width}: the ring must follow the fill`).toBe(got.fill);
      expect(got.borderColor, `@${width}: the ring must no longer fall to the theme default`).not.toBe(
        got.themeHover,
      );
    });
  }

  // OPTION 3's DEFINING CASE. An author who set BOTH the hover fill and the accent-hover knob
  // keeps the accent ring. Under the rejected Option 2 the fill would outrank it and this
  // site's authored orange would silently turn purple.
  test('hero cta2: an authored --hero-accent-hover still beats the hover fill @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 538 hero accent wins', {
      '--hero-button2-hover-bg': FILL,
      '--hero-accent-hover': ACCENT,
    });
    await open(page, pageId, HERO_SECOND, 1280);

    const got = await ringOnHover(page, HERO_SECOND);
    expect(got.bgColor, 'the fill slot still paints the fill').toBe(got.fill);
    expect(got.borderColor, 'the authored accent must win the ring').toBe(got.accent);
    expect(got.borderColor, 'the fill must NOT reach the ring here').not.toBe(got.fill);
  });

  test('cta button2: an authored --cta-accent-hover still beats the hover fill @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 538 cta accent wins', {
      '--cta-button2-hover-bg': FILL,
      '--cta-accent-hover': ACCENT,
    });
    await open(page, pageId, CTA_SECOND, 1280);

    const got = await ringOnHover(page, CTA_SECOND);
    expect(got.bgColor, 'the fill slot still paints the fill').toBe(got.fill);
    expect(got.borderColor, 'the authored accent must win the ring').toBe(got.accent);
    expect(got.borderColor, 'the fill must NOT reach the ring here').not.toBe(got.fill);
  });

  // The dedicated hover-border slot stays the strongest link in the chain.
  test('hero cta2: an authored --hero-button2-hover-border beats both @smoke', async ({ page }) => {
    pageId = heroPage('E2E 538 hero border wins', {
      '--hero-button2-hover-bg': FILL,
      '--hero-accent-hover': ACCENT,
      '--hero-button2-hover-border': BORDER,
    });
    await open(page, pageId, HERO_SECOND, 1280);

    const got = await ringOnHover(page, HERO_SECOND);
    expect(got.borderColor, 'the dedicated hover-border slot must win').toBe(got.border);
  });

  test('cta button2: an authored --cta-button2-hover-border beats both @smoke', async ({ page }) => {
    pageId = ctaPage('E2E 538 cta border wins', {
      '--cta-button2-hover-bg': FILL,
      '--cta-accent-hover': ACCENT,
      '--cta-button2-hover-border': BORDER,
    });
    await open(page, pageId, CTA_SECOND, 1280);

    const got = await ringOnHover(page, CTA_SECOND);
    expect(got.borderColor, 'the dedicated hover-border slot must win').toBe(got.border);
  });

  // The accent-only case is untouched by this change: with no hover fill set, the inserted
  // link is guaranteed-invalid and the chain resolves exactly as it did before #538.
  test('hero cta2: accent-hover alone still colors the ring, fill slot unset @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 538 hero accent only', { '--hero-accent-hover': ACCENT });
    await open(page, pageId, HERO_SECOND, 1280);

    const got = await ringOnHover(page, HERO_SECOND);
    expect(got.borderColor, 'accent-hover still colors the ring on its own').toBe(got.accent);
  });

  test('cta button2: accent-hover alone still colors the ring, fill slot unset @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 538 cta accent only', { '--cta-accent-hover': ACCENT });
    await open(page, pageId, CTA_SECOND, 1280);

    const got = await ringOnHover(page, CTA_SECOND);
    expect(got.borderColor, 'accent-hover still colors the ring on its own').toBe(got.accent);
  });

  // The terminal of the chain, pinned ABSOLUTELY. The #530 unset tests compare the second
  // button's ring against the PRIMARY's, which is a relative check: a change that moved both
  // terminals together would pass it. These assert the resolved --color-accent-hover literal,
  // so the byte-identical-when-unset guarantee this change had to preserve is pinned on its
  // own terms rather than against a sibling that shares the same fate.
  test('hero cta2: with no slots set the hover ring is still the theme default @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 538 hero unset');
    await open(page, pageId, HERO_SECOND, 1280);

    const got = await ringOnHover(page, HERO_SECOND);
    expect(got.borderColor, 'an unset cta2 must still hover to --color-accent-hover').toBe(
      got.themeHover,
    );
  });

  test('cta button2: with no slots set the hover ring is still the theme default @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 538 cta unset');
    await open(page, pageId, CTA_SECOND, 1280);

    const got = await ringOnHover(page, CTA_SECOND);
    expect(got.borderColor, 'an unset button2 must still hover to --color-accent-hover').toBe(
      got.themeHover,
    );
  });

  // 14.1 AUTHORING PATH. Every case above seeds _pp_composition directly. This one drives the
  // REAL surface — the style_component action, through validation — so the fix is proven on
  // the path an operator actually uses, not only on a hand-written fixture.
  test('hero cta2 hover ring follows a fill set through style_component @smoke', async ({
    page,
  }) => {
    pageId = heroPage('E2E 538 hero authoring path');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--hero-button2-hover-bg': FILL });
    expect(res.success, 'style_component must accept the hover fill slot').toBe(true);

    await open(page, pageId, HERO_SECOND, 1280);
    const got = await ringOnHover(page, HERO_SECOND);
    expect(got.bgColor, 'the action-written fill must paint').toBe(got.fill);
    expect(got.borderColor, 'and the ring must follow it').toBe(got.fill);
  });

  test('cta button2 hover ring follows a fill set through style_component @smoke', async ({
    page,
  }) => {
    pageId = ctaPage('E2E 538 cta authoring path');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--cta-button2-hover-bg': FILL });
    expect(res.success, 'style_component must accept the hover fill slot').toBe(true);

    await open(page, pageId, CTA_SECOND, 1280);
    const got = await ringOnHover(page, CTA_SECOND);
    expect(got.bgColor, 'the action-written fill must paint').toBe(got.fill);
    expect(got.borderColor, 'and the ring must follow it').toBe(got.fill);
  });
});

/**
 * #548 — the cta PRIMARY joins #538's Option-3 order, so a cta button PAIR rings ONE way.
 *
 * #538 deliberately stopped at the two SECOND buttons: reordering the primary repaints a
 * shipped render, so it needed its own maintainer decision rather than a silent cleanup.
 * The cost of stopping there was visible on a single band — a site authoring
 * --cta-accent-hover plus BOTH per-button hover fills hovered its primary to a fill-coloured
 * ring and its neighbour to an accent-coloured one, side by side, same component.
 *
 * The decision (recorded on #548) accepts exactly one rendered change: in the both-authored
 * configuration the primary's ring moves from the fill to the authored accent. Everything
 * else must be untouched, and "untouched" is the harder half of this issue — which is why
 * the fill-only and unset controls below assert ABSOLUTE resolved values (the fill literal,
 * the theme literal, the on-overlay role) rather than comparing against a sibling that
 * would move with them.
 *
 * Why these are render tests and not CSS-text pins: the contract is directional and lives in
 * a cascade. StyleSlotContractTest proves the token ORDER in the source; only a real :hover
 * in a real browser proves that the rule carrying that order is the one that paints, over
 * the premium [0,5,1] gradient rule, the #535/#543 separation rings and the #542 focus
 * routing that all target these same buttons.
 */
test.describe('#548 cta primary hover ring ranks the accent above the fill (real WP)', () => {
  let pageId = 0;

  const FILL = 'rgb(124, 58, 237)'; // #7c3aed, the hover fill
  const ACCENT = 'rgb(255, 136, 0)'; // #ff8800, the authored accent-hover
  const BORDER = 'rgb(16, 185, 129)'; // #10b981, the dedicated hover-border slot
  const GLOBAL_RING = 'rgb(7, 8, 9)'; // the #539 global --btn-hover-border-color
  const IMG = 'https://example.com/nonexistent.jpg'; // triggers .cta--has-bg-image, no upload
  const ON_OVERLAY = 'rgb(250, 251, 255)'; // #fafbff, the overlay twin's terminal

  const PRIMARY = '.cta__button:not(.cta__button--secondary)';
  const SECOND = '.cta__button--secondary';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  /** A filled PAIR so the primary can be compared against its neighbour on one band. */
  const ctaPair = (
    title: string,
    style?: Record<string, string>,
    props: Record<string, unknown> = {},
  ) => ({
    component: 'cta',
    props: {
      title,
      button_text: 'Primary action', button_url: '/start',
      button2_text: 'Second action', button2_url: '/learn',
      button2_variant: 'primary',
      ...props,
    },
    ...(style ? { style } : {}),
  });

  async function open(page: any, id: number, width: number) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${id}`);
    await expect(page.locator(PRIMARY).first()).toBeVisible({ timeout: 10000 });
    // The .btn colour transition is 150ms; a read straight after hover() samples a
    // mid-flight blend. The assertion is about which value the CASCADE resolves.
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });
  }

  const prop = (loc: any, name: string) =>
    loc.evaluate((el: Element, p: string) => getComputedStyle(el).getPropertyValue(p), name);

  /** Probe-resolve a literal through the browser (the #458 idiom), never a hardcoded hex. */
  const resolve = (page: any, value: string) =>
    page.evaluate((v: string) => {
      const el = document.createElement('div');
      el.style.setProperty('background-color', v);
      document.body.appendChild(el);
      const out = getComputedStyle(el).getPropertyValue('background-color').trim();
      el.remove();
      return out;
    }, value);

  for (const width of [1280, 375]) {
    /*
     * THE CHANGE, and the reason the issue was filed: one band, two filled buttons, both
     * knobs authored. Before #548 these two rings resolved to DIFFERENT colours. The pair
     * equality assertion is the contract; the absolute ACCENT assertions say which way it
     * was resolved, so a future regression that made both follow the FILL would still fail.
     */
    test(`both knobs authored: the pair rings identically, on the accent (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 548 both authored');
      setComposition(pageId, [
        ctaPair('Ready to start?', {
          '--cta-accent-hover': ACCENT,
          '--cta-button-hover-bg': FILL,
          '--cta-button2-hover-bg': FILL,
        }),
      ]);
      await open(page, pageId, width);

      const primary = page.locator(PRIMARY).first();
      const second = page.locator(SECOND);

      await primary.hover();
      expect(await prop(primary, 'background-color'), `@${width}: the hover fill still paints`).toBe(FILL);
      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: the authored --cta-accent-hover must win the primary's ring (#548). ` +
          'Before this change it resolved to the hover FILL.',
      ).toBe(ACCENT);

      // A ring with no width is not a ring — every colour assertion above stays green
      // under `border-width: 0`. Read it while the PRIMARY is still hovered: moving the
      // pointer to the second button first would measure the primary's REST width and let
      // a hover-state `border-width: 0` regression through.
      expect(
        parseFloat(await prop(primary, 'border-top-width')),
        `@${width}: the ring must have a width under the pointer`,
      ).toBeGreaterThan(0);

      await second.hover();
      expect(await prop(second, 'border-top-color'), `@${width}: button2 unchanged (#538)`).toBe(ACCENT);
    });

    /*
     * CONTROL 1 — the fill-only author. The border-follows-fill link SURVIVED the reorder,
     * one slot further down, so this render is unchanged. If the reorder had dropped the
     * fill instead of demoting it, this is the test that catches it.
     */
    test(`fill only: the ring still follows the fill, unchanged (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 548 fill only');
      setComposition(pageId, [ctaPair('Ready to start?', { '--cta-button-hover-bg': FILL })]);
      await open(page, pageId, width);

      const primary = page.locator(PRIMARY).first();
      await primary.hover();
      expect(await prop(primary, 'background-color'), `@${width}: the fill paints`).toBe(FILL);
      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: with no accent knob authored the ring must still follow the fill`,
      ).toBe(FILL);
    });
  }

  /*
   * CONTROL 2 — nothing authored. Pinned against the probe-resolved theme literal rather
   * than against the second button, because a change that moved BOTH terminals together
   * would sail through a sibling comparison.
   */
  test('unset: the hover ring is still the theme default @smoke', async ({ page }) => {
    pageId = createPage('E2E 548 unset');
    setComposition(pageId, [ctaPair('Ready to start?')]);
    await open(page, pageId, 1280);

    const primary = page.locator(PRIMARY).first();
    await primary.hover();
    expect(
      await prop(primary, 'border-top-color'),
      'an unset primary must still hover to --color-accent-hover',
    ).toBe(await resolve(page, 'var(--color-accent-hover)'));
  });

  // The author's dedicated ring knob is still the strongest link, ahead of both.
  test('an authored --cta-button-hover-border beats the accent and the fill @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 548 border wins');
    setComposition(pageId, [
      ctaPair('Ready to start?', {
        '--cta-button-hover-border': BORDER,
        '--cta-accent-hover': ACCENT,
        '--cta-button-hover-bg': FILL,
      }),
    ]);
    await open(page, pageId, 1280);

    const primary = page.locator(PRIMARY).first();
    await primary.hover();
    expect(await prop(primary, 'border-top-color'), 'the dedicated slot must win').toBe(BORDER);
  });

  /*
   * #564 INVERTED the half of the order #548 did not touch. #539's global ring tier still sits
   * under the per-instance ring slot and above the per-instance FILL link — an explicitly
   * authored global ring still beats one merely inferred from someone's fill — but it no
   * longer outranks the BAND ACCENT. This pin previously asserted GLOBAL_RING here; it is
   * flipped deliberately, not deleted (the #538/#530 pattern), per the maintainer decision on
   * issue 564 (issuecomment-5106604500). A narrower authored band role is not defeated by a
   * broader site-wide default; the escape hatch is the per-instance slot, pinned above.
   *
   * This is the RENDERED half of the contract. The CSS-text pins in css-lint.test.js and
   * StyleSlotContractTest prove the declaration order; only this proves what the browser
   * actually paints once the whole cascade has run.
   */
  test('the band accent outranks the global --btn-hover-border-color, which still outranks the fill @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 564 accent beats global ring');
    setComposition(pageId, [
      ctaPair('Ready to start?', {
        '--cta-accent': ACCENT,
        '--cta-accent-hover': ACCENT,
        '--cta-button-hover-bg': FILL,
      }),
    ]);
    await open(page, pageId, 1280);
    // The global tier is a THEME-level token, not a cta style slot: pp_render_style_vars()
    // renders only keys declared in the component's schema (lib/wp.php), so seeding
    // --btn-hover-border-color through the composition would be silently dropped and this
    // test would assert the accent while believing it asserted the global. Set it at :root,
    // the same idiom the #539 tests use.
    await page.addStyleTag({
      content: `:root{--btn-border-color:${GLOBAL_RING};--btn-hover-border-color:${GLOBAL_RING};}`,
    });

    const primary = page.locator(PRIMARY).first();
    const second = page.locator(SECOND);

    // REST is asserted too: #564 reordered the rest chain as well, and only a real browser
    // proves which of the competing rules actually paints.
    expect(
      await prop(primary, 'border-top-color'),
      'the authored band accent must beat the site-wide ring knob AT REST (issue 564)',
    ).toBe(ACCENT);
    expect(
      await prop(second, 'border-top-color'),
      'button2 resolves the same way at rest — the pair cannot disagree',
    ).toBe(ACCENT);

    await primary.hover();
    expect(
      await prop(primary, 'border-top-color'),
      'the authored band accent must beat the site-wide ring knob ON HOVER (issue 564)',
    ).toBe(ACCENT);

    await second.hover();
    expect(
      await prop(second, 'border-top-color'),
      'button2 resolves the same way on hover',
    ).toBe(ACCENT);
  });

  /*
   * The other half of the same order, still pinned in the LOSING direction: with NO band accent
   * authored, the global ring knob must still win over the per-instance hover fill. #564 moved
   * the accent above the knob; it did not move the fill above it, and #554's contract (a
   * site-wide retheme reaches these buttons) depends on the knob still engaging here.
   */
  test('with no band accent, the global --btn-hover-border-color still outranks the fill @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 564 global ring beats fill');
    setComposition(pageId, [ctaPair('Ready to start?', { '--cta-button-hover-bg': FILL })]);
    await open(page, pageId, 1280);
    await page.addStyleTag({ content: `:root{--btn-hover-border-color:${GLOBAL_RING};}` });

    const primary = page.locator(PRIMARY).first();
    await primary.hover();
    expect(
      await prop(primary, 'border-top-color'),
      "#539's global ring still beats a ring inferred from a fill (issue 554 coverage)",
    ).toBe(GLOBAL_RING);
  });

  /*
   * THE REPORTED DEFECT, rendered (issue 564, found by the v1.12.0 release smoke).
   *
   * On a `background_image` cta band the filled buttons are ringed with
   * --color-accent-on-overlay: near-white, measured at 4.59:1 against the worst-case
   * overlay-over-white composite, which is the only thing keeping the button's SHAPE visible
   * over an arbitrary photo (#535/#543, WCAG 1.4.11). A site-wide --btn-border-color /
   * --btn-hover-border-color used to sit ABOVE that role and repaint the ring — the smoke
   * measured the amber rgb(255,171,0) where the near-white role belonged. #564 removed the
   * global knobs from these chains entirely: above the role they defeat a measured guarantee,
   * below it they are dead code (--color-accent-on-overlay is a :root token, always set).
   *
   * Both buttons and BOTH states are asserted, because the twins are what keep the ring from
   * changing colour under the pointer.
   */
  for (const width of [1280, 375]) {
    test(`a global ring knob does not defeat the on-overlay separation ring (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 564 overlay role beats global ring');
      setComposition(pageId, [
        ctaPair('Ready to start?', undefined, {
          background_image: 'https://example.com/nonexistent.jpg',
        }),
      ]);
      await open(page, pageId, width);
      await page.addStyleTag({
        content: `:root{--btn-border-color:${GLOBAL_RING};--btn-hover-border-color:${GLOBAL_RING};}`,
      });

      const ON_OVERLAY = 'rgb(250, 251, 255)'; // #fafbff, 4.59:1 on the worst-case scrim
      const primary = page.locator(PRIMARY).first();
      const second = page.locator(SECOND);

      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: the primary's REST ring must stay on the measured on-overlay role`,
      ).toBe(ON_OVERLAY);
      expect(
        await prop(second, 'border-top-color'),
        `@${width}: button2's REST ring must stay on the measured on-overlay role`,
      ).toBe(ON_OVERLAY);

      await primary.hover();
      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: the primary's HOVER ring must stay on the role — this is the exact ` +
          'reading the v1.12.0 smoke caught as the amber global knob',
      ).toBe(ON_OVERLAY);
      expect(
        parseFloat(await prop(primary, 'border-top-width')),
        `@${width}: the ring must have a width under the pointer`,
      ).toBeGreaterThan(0);

      await second.hover();
      expect(
        await prop(second, 'border-top-color'),
        `@${width}: button2's HOVER ring must stay on the role`,
      ).toBe(ON_OVERLAY);
    });
  }

  /*
   * A per-instance ring slot is the documented ESCAPE HATCH on these bands: #564 removed the
   * GLOBAL knobs from the overlay chains, not the author's own. Pinned so a future tightening
   * of the role cannot quietly take the hatch away too.
   */
  test('a per-instance ring slot still beats the on-overlay role on a photo band @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 564 overlay escape hatch');
    setComposition(pageId, [
      ctaPair(
        'Ready to start?',
        { '--cta-button-border': BORDER, '--cta-button-hover-border': BORDER },
        { background_image: 'https://example.com/nonexistent.jpg' },
      ),
    ]);
    await open(page, pageId, 1280);
    await page.addStyleTag({
      content: `:root{--btn-border-color:${GLOBAL_RING};--btn-hover-border-color:${GLOBAL_RING};}`,
    });

    const primary = page.locator(PRIMARY).first();
    expect(await prop(primary, 'border-top-color'), 'the authored slot wins at rest').toBe(BORDER);
    await primary.hover();
    expect(await prop(primary, 'border-top-color'), 'the authored slot wins on hover').toBe(BORDER);
  });

  /*
   * The positional-twin property, rendered (issue 564): in the configuration that repaints —
   * band accent AND per-instance fill both authored, no global knob — the ring must resolve to
   * the SAME role at rest and under the pointer. Before #564 the cta rest chain ranked the fill
   * above the accent while its hover chain ranked the accent above the fill, so this exact
   * configuration showed a fill-coloured ring that flipped to the accent on pointer-enter.
   * Retiring that flip is #538's Option 2, reopened deliberately on #564.
   */
  test('band accent + per-instance fill: the ring does not change colour on pointer-enter @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 564 no rest-hover flip');
    setComposition(pageId, [
      ctaPair('Ready to start?', {
        '--cta-accent': ACCENT,
        '--cta-accent-hover': ACCENT,
        '--cta-button-bg': FILL,
        '--cta-button-hover-bg': FILL,
      }),
    ]);
    await open(page, pageId, 1280);

    const primary = page.locator(PRIMARY).first();
    const rest = await prop(primary, 'border-top-color');
    await primary.hover();
    const hover = await prop(primary, 'border-top-color');

    // Equality FIRST, so a future regression surfaces as "the ring flipped" rather than as a
    // colour mismatch. Asserted before the absolute pins, or it could never fail on its own.
    expect(hover, 'rest and hover must resolve to the SAME role — no flip under the pointer').toBe(rest);
    expect(rest, 'the accent must win at rest too since #564 (it used to be the fill)').toBe(ACCENT);
    expect(await prop(primary, 'background-color'), 'the authored fill still paints').toBe(FILL);
  });

  /*
   * THE OVERLAY TWIN. The second of the two edited declarations: same chain, different
   * terminal (--color-accent-on-overlay, the #535/#543 separation ring). It is a physically
   * separate rule, so it can drift from the plain one — both are pinned here and in
   * StyleSlotContractTest.
   */
  test('overlay band: the accent wins the primary ring, and the unset terminal is untouched @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 548 overlay band');
    setComposition(pageId, [
      ctaPair('Authored', {
        '--cta-accent-hover': ACCENT,
        '--cta-button-hover-bg': FILL,
      }, { background_image: IMG }),
      ctaPair('Fill only', { '--cta-button-hover-bg': FILL }, { background_image: IMG }),
      ctaPair('Unset', undefined, { background_image: IMG }),
    ]);
    await open(page, pageId, 1280);

    const bands = page.locator('.cta--has-bg-image');
    await expect(bands).toHaveCount(3, { timeout: 10000 });

    const authored = bands.nth(0).locator(PRIMARY).first();
    await authored.hover();
    expect(
      await prop(authored, 'border-top-color'),
      'on a photo band the authored accent-hover must win the primary ring too (#548)',
    ).toBe(ACCENT);
    expect(await prop(authored, 'border-top-color'), 'and must NOT be the fill').not.toBe(FILL);

    // The fill-only control on THIS declaration too: the border-follows-fill link must
    // still sit ahead of the on-overlay terminal, or a fill-only recolor would snap to the
    // near-white role token instead of matching its own fill.
    const fillOnly = bands.nth(1).locator(PRIMARY).first();
    await fillOnly.hover();
    expect(
      await prop(fillOnly, 'border-top-color'),
      'on a photo band a fill-only recolor must still ring itself, not the role token',
    ).toBe(FILL);

    const unset = bands.nth(2).locator(PRIMARY).first();
    await unset.hover();
    expect(
      await prop(unset, 'border-top-color'),
      'with nothing authored the separation ring still bottoms out at the on-overlay role — ' +
        'only the ORDER of the authored links moved, never the terminal (#535, #543)',
    ).toBe(ON_OVERLAY);
  });

  /*
   * 14.1 AUTHORING PATH. Every case above seeds _pp_composition directly. This one drives
   * the REAL surface — style_component, through validation — so the reordered contract is
   * proven on the path an operator actually uses, not only on a hand-written fixture.
   */
  test('the reordered ring holds for slots written through style_component @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 548 authoring path');
    setComposition(pageId, [ctaPair('Ready to start?')]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, {
      '--cta-accent-hover': ACCENT,
      '--cta-button-hover-bg': FILL,
    });
    expect(res.success, 'style_component must accept both hover slots').toBe(true);

    await open(page, pageId, 1280);
    const primary = page.locator(PRIMARY).first();
    await primary.hover();
    expect(await prop(primary, 'background-color'), 'the action-written fill must paint').toBe(FILL);
    expect(
      await prop(primary, 'border-top-color'),
      'and the action-written accent-hover must win the ring',
    ).toBe(ACCENT);
  });
});

/**
 * #543 — the filled SECOND button gets #535's separation ring on the two OVERLAY bands.
 *
 * #535 scoped that ring to the filled PRIMARY. The second button's own rules are one class
 * higher ([0,6,0] rest / [0,7,0] hover) and bottomed out at the bare --color-accent, so a
 * `primary` + `primary` pair on a photo band rendered ONE button with a visible edge next to
 * one dissolving into the scrim — same fill, same band, different treatment.
 *
 * This is a CASCADE fix and a rendered one. The four new rules tie on specificity with the
 * base button2 rules and win only by source order, which no CSS-text check can prove; and
 * #538 made the base HOVER ring follow the hover fill, so a rest-only ring would have
 * dissolved again the moment the pointer landed. Both states are therefore asserted from a
 * real browser, on both bands, at 1280 and 375.
 *
 * The three controls (light band, inverted band, authored slots) are the byte-identity half:
 * only the TERMINAL fallback moved, so anything an author already coloured — and every band
 * without the overlay class — must render exactly as before.
 */
test.describe('#543 filled second button is ringed on overlay bands (real WP)', () => {
  let pageId = 0;

  const IMG = 'https://example.com/nonexistent.jpg'; // triggers .cta--has-bg-image, no upload
  const ON_OVERLAY = 'rgb(250, 251, 255)'; // #fafbff, 4.59:1 over the worst-case scrim
  const BARE_ACCENT = 'rgb(49, 87, 244)'; // #3157f4 — the pre-fix ring, == the fill
  const FILL = 'rgb(124, 58, 237)';
  const RING = 'rgb(16, 185, 129)';
  const ACCENT_HOVER = 'rgb(255, 136, 0)';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  /** A filled PAIR: primary + a `primary` second button, on whichever band `props` names. */
  const ctaPair = (title: string, props: Record<string, unknown>, style?: Record<string, string>) => ({
    component: 'cta',
    props: {
      title,
      button_text: 'Primary action', button_url: '/start',
      button2_text: 'Second action', button2_url: '/learn',
      button2_variant: 'primary',
      ...props,
    },
    ...(style ? { style } : {}),
  });

  const heroPair = (title: string, props: Record<string, unknown>, style?: Record<string, string>) => ({
    component: 'hero',
    props: {
      title,
      button_text: 'Primary action', button_url: '/start',
      button2_text: 'Second action', button2_url: '/learn',
      button2_variant: 'primary',
      ...props,
    },
    ...(style ? { style } : {}),
  });

  async function open(page: any, id: number, width: number) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${id}`);
    // The .btn colour transition is 150ms, so a read right after hover() samples a
    // mid-flight blend. Kill transitions: the assertion is about which value the CASCADE
    // resolves, not how it animates there.
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; }' });
  }

  const prop = (loc: any, name: string) =>
    loc.evaluate((el: Element, p: string) => getComputedStyle(el).getPropertyValue(p), name);

  for (const width of [1280, 375]) {
    // THE DEFECT, both bands. Before this change the second button's ring resolved to
    // --color-accent — the same colour as its own fill — so the pill had no edge at all
    // while its neighbour did.
    test(`cta: a filled pair on a bg-image band is ringed symmetrically (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 543 cta overlay pair');
      setComposition(pageId, [ctaPair('Ready to start?', { background_image: IMG })]);
      await open(page, pageId, width);

      const primary = page.locator('.cta__button').first();
      const second = page.locator('.cta__button--secondary');
      await expect(second).toBeVisible({ timeout: 10000 });

      // Rest: both ringed with the role token, and the fill itself is untouched.
      expect(await prop(primary, 'border-top-color'), `@${width}: primary ring`).toBe(ON_OVERLAY);
      expect(await prop(second, 'border-top-color'), `@${width}: second ring`).toBe(ON_OVERLAY);
      // The pre-fix value, asserted directly. Comparing against the button's own
      // background-COLOR would be a weaker check that happens to pass for the wrong
      // reason: on an unset filled button the premium gradient background-IMAGE is what
      // paints, so the background-color the DOM reports is already masked.
      expect(await prop(second, 'border-top-color'), `@${width}: not the pre-fix accent`).not.toBe(
        BARE_ACCENT,
      );
      // A ring with no width is not a ring. Every colour assertion here would stay green
      // under `border-width: 0`, so pin the width that makes the colour visible.
      expect(
        parseFloat(await prop(second, 'border-top-width')),
        `@${width}: the ring must have a width`,
      ).toBeGreaterThan(0);

      // Hover: the ring must SURVIVE. #538 made the base hover ring follow the hover fill,
      // so without the hover twin this is exactly where the ring dissolved again.
      await second.hover();
      expect(await prop(second, 'border-top-color'), `@${width}: second ring under the pointer`).toBe(
        ON_OVERLAY,
      );
      expect(await prop(second, 'border-top-color')).not.toBe(await prop(second, 'background-color'));
    });

    test(`hero: a filled pair on a cover band is ringed symmetrically (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 543 hero cover pair');
      setComposition(pageId, [heroPair('Ship faster', { layout: 'cover' })]);
      await open(page, pageId, width);

      const primary = page.locator('.hero__cta').first();
      const second = page.locator('.hero__cta--secondary');
      await expect(second).toBeVisible({ timeout: 10000 });

      expect(await prop(primary, 'border-top-color'), `@${width}: primary ring`).toBe(ON_OVERLAY);
      expect(await prop(second, 'border-top-color'), `@${width}: second ring`).toBe(ON_OVERLAY);

      await second.hover();
      expect(await prop(second, 'border-top-color'), `@${width}: second ring under the pointer`).toBe(
        ON_OVERLAY,
      );
    });
  }

  /*
   * BYTE IDENTITY, the half that proves only the terminal moved. A light band and an
   * inverted band carry neither overlay class, so their filled second button must still
   * ring with the bare accent — and on the inverted band that is also #535 Q2's recorded
   * refusal (3.23:1 fill-vs-band already clears the 3:1 non-text bar), now pinned for the
   * second button at render level rather than only in CSS text.
   */
  test('light and inverted bands are unchanged; the SOLID inverted second button stays un-ringed @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 controls');
    setComposition(pageId, [
      ctaPair('Light band', {}),
      ctaPair('Inverted band', { theme: 'inverted' }),
      // A non-cover hero: `.hero--cover` is emitted by LAYOUT alone (hero.php), so a
      // centered hero differs from a cover hero by exactly one class. Without this the
      // hero half of "every band without the class is byte-identical" would be pinned
      // only in CSS text, on the side where the ring rule is loosest.
      heroPair('Centered hero', { layout: 'centered' }),
    ]);
    await open(page, pageId, 1280);

    const seconds = page.locator('.cta__button--secondary');
    await expect(seconds).toHaveCount(2, { timeout: 10000 });

    // Exact values on both states, not merely "not the role token". A regression that
    // repainted these to some THIRD colour (a reordered base chain, a terminal swapped to
    // --color-accent instead of --color-accent-hover) would slip past a not.toBe check.
    const accentHover = await page.evaluate(() => {
      const el = document.createElement('div');
      el.style.setProperty('background-color', 'var(--color-accent-hover)');
      document.body.appendChild(el);
      const out = getComputedStyle(el).backgroundColor;
      el.remove();
      return out;
    });

    const controls: [any, string][] = [
      [seconds.nth(0), 'light cta'],
      [seconds.nth(1), 'inverted cta'],
      [page.locator('.hero__cta--secondary'), 'centered hero'],
    ];
    for (const [btn, label] of controls) {
      expect(await prop(btn, 'border-top-color'), `${label} rest ring`).toBe(BARE_ACCENT);
      await btn.hover();
      expect(await prop(btn, 'border-top-color'), `${label} hover ring`).toBe(accentHover);
    }
  });

  /*
   * The COMBINED band. cta.php emits the theme class and the bg-image class
   * independently, so `theme: "inverted"` + `background_image` renders
   * `.cta--inverted.cta--has-bg-image` — the scrim painted over the inverted background.
   * The Q2 refusal above is scoped to the SOLID inverted band; here the overlay role must
   * win, because on-inverted measures only ~2.2:1 over an arbitrary image. #535 pins this
   * precedence for the primary and #542 for the focus ring; this is the second button's.
   */
  test('a band carrying BOTH dark classes rings the second button with the overlay role @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 combined band');
    setComposition(pageId, [ctaPair('Inverted AND overlay', { theme: 'inverted', background_image: IMG })]);
    await open(page, pageId, 1280);

    const second = page.locator('.cta__button--secondary');
    await expect(second).toBeVisible({ timeout: 10000 });
    expect(await prop(second, 'border-top-color'), 'the overlay role must win here').toBe(ON_OVERLAY);
    await second.hover();
    expect(await prop(second, 'border-top-color'), 'and survive hover').toBe(ON_OVERLAY);
  });

  /*
   * The ring must stay OFF the transparent-fill variants. The `:not()` exclusions are
   * pinned in CSS text, but the consequence is a cascade outcome: ghost's border bottoms
   * out at `transparent`, so routing the fill chain there would ADD a ring to a
   * deliberately borderless button, and outline already carries its own #474 on-overlay
   * ring that must not be re-derived from the fill chain.
   */
  test('ghost and outline second buttons on an overlay band are unaffected @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 transparent variants');
    setComposition(pageId, [
      ctaPair('Ghost second', { background_image: IMG, button2_variant: 'ghost' }),
      ctaPair('Outline second', { background_image: IMG, button2_variant: 'outline' }),
    ]);
    await open(page, pageId, 1280);

    const seconds = page.locator('.cta__button--secondary');
    await expect(seconds).toHaveCount(2, { timeout: 10000 });

    // Ghost stays borderless — the ring rule must not have reached it.
    expect(await prop(seconds.nth(0), 'border-top-color'), 'ghost stays transparent').toBe(
      'rgba(0, 0, 0, 0)',
    );
    // Outline keeps its own #474 routed ring, which is the same role token but arrives
    // from a different rule and a different chain (--cta-button2-border -> the role).
    expect(await prop(seconds.nth(1), 'border-top-color'), 'outline keeps its #474 ring').toBe(
      ON_OVERLAY,
    );
  });

  /*
   * AUTHORED SLOTS still win. The ring replaces only the TERMINAL fallback, so every link
   * ahead of it survives; jumping straight to the role token would have silently repainted
   * every authored ring on a photo band near-white.
   */
  test('an authored ring slot still beats the role token, at rest and on hover @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 authored slots');
    setComposition(pageId, [
      ctaPair('Authored rings', { background_image: IMG }, {
        '--cta-button2-border': RING,
        '--cta-button2-hover-border': RING,
      }),
      heroPair('Authored rings', { layout: 'cover' }, {
        '--hero-button2-border': RING,
        '--hero-button2-hover-border': RING,
      }),
    ]);
    await open(page, pageId, 1280);

    for (const sel of ['.cta__button--secondary', '.hero__cta--secondary']) {
      const btn = page.locator(sel);
      await expect(btn).toBeVisible({ timeout: 10000 });
      expect(await prop(btn, 'border-top-color'), `${sel} rest: the slot must win`).toBe(RING);
      await btn.hover();
      expect(await prop(btn, 'border-top-color'), `${sel} hover: the slot must win`).toBe(RING);
    }
  });

  /*
   * THE FILL-ONLY AUTHOR, and #538's Option-3 ORDER. Both are chain positions the new
   * terminal must not disturb:
   *   - fill slot alone -> the ring FOLLOWS the fill (matching the primary's own contract),
   *     not the near-white role token;
   *   - accent-hover knob set alongside the hover fill -> the ACCENT wins the hover ring,
   *     which is the case that distinguishes Option 3 from the rejected Option 2.
   */
  test('a fill-only recolor keeps a fill-matching ring; an authored accent-hover still wins @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 fill only');
    setComposition(pageId, [
      ctaPair('Fill only', { background_image: IMG }, { '--cta-button2-bg': FILL }),
      ctaPair('Accent hover wins', { background_image: IMG }, {
        '--cta-button2-hover-bg': FILL,
        '--cta-accent-hover': ACCENT_HOVER,
      }),
    ]);
    await open(page, pageId, 1280);

    const seconds = page.locator('.cta__button--secondary');
    await expect(seconds).toHaveCount(2, { timeout: 10000 });

    const fillOnly = seconds.nth(0);
    expect(await prop(fillOnly, 'background-color'), 'the fill slot must paint').toBe(FILL);
    expect(await prop(fillOnly, 'border-top-color'), 'the ring follows the authored fill').toBe(FILL);
    expect(await prop(fillOnly, 'border-top-color'), 'and does NOT snap to the role token').not.toBe(
      ON_OVERLAY,
    );

    const accentWins = seconds.nth(1);
    await accentWins.hover();
    expect(await prop(accentWins, 'background-color'), 'the hover fill still paints').toBe(FILL);
    expect(await prop(accentWins, 'border-top-color'), 'the authored accent-hover wins the ring').toBe(
      ACCENT_HOVER,
    );
  });

  /*
   * THREE INDICATORS AT ONCE. #542 routes the FOCUS outline on these same bands to the same
   * role token, so a focused + hovered second button now stacks a near-white separation ring,
   * a near-white focus outline and the hover fill. `outline-offset` paints the focus ring
   * OUTSIDE the border with a gap, so the two must remain distinguishable rather than merging
   * into one thick edge — the composition the primary has shipped since #535 + #542, now
   * reached by the second button too.
   */
  test('focus + hover compose: separation ring, focus outline and hover fill all present @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 focus plus hover');
    setComposition(pageId, [ctaPair('Focus and hover', { background_image: IMG })]);
    await open(page, pageId, 1280);

    const second = page.locator('.cta__button--secondary');
    await expect(second).toBeVisible({ timeout: 10000 });

    await second.focus();
    await second.hover();

    expect(await prop(second, 'border-top-color'), 'the separation ring survives focus+hover').toBe(
      ON_OVERLAY,
    );
    expect(await prop(second, 'outline-color'), 'the #542 focus ring is routed on this band').toBe(
      ON_OVERLAY,
    );
    // The two indicators must not be flush: a zero offset would render them as one edge.
    expect(await prop(second, 'outline-style'), 'the focus ring is actually painted').not.toBe('none');
    expect(
      parseFloat(await prop(second, 'outline-offset')),
      'outline-offset must keep a gap between the focus ring and the border',
    ).toBeGreaterThan(0);
    // And the hover fill is still the hover fill, not the rest fill.
    expect(await prop(second, 'background-color')).not.toBe(BARE_ACCENT);
  });

  /*
   * 14.1 AUTHORING PATH. Every case above seeds _pp_composition directly. This one drives
   * the REAL surface — the style_component action, through validation — so an author who
   * colours the ring on an overlay band is proven to still beat the role token on the path
   * an operator actually uses.
   */
  test('a ring slot written through style_component still beats the role token @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 543 authoring path');
    setComposition(pageId, [ctaPair('Authoring path', { background_image: IMG })]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--cta-button2-border': RING });
    expect(res.success, 'style_component must accept the button2 border slot').toBe(true);

    await open(page, pageId, 1280);
    const second = page.locator('.cta__button--secondary');
    await expect(second).toBeVisible({ timeout: 10000 });
    expect(await prop(second, 'border-top-color'), 'the action-written ring must win').toBe(RING);
  });

  /*
   * ── #565: a GLOBAL fill knob must not defeat the separation ring either ──────────────
   *
   * THE REPORTED DEFECT, rendered. #564 removed the global RING knobs from these eight
   * chains; the global FILL knobs sat in the same position and defeated the same measured
   * role by a different route — the border-follows-fill link (#535). A site setting only
   * `--btn-bg` at :root (a plausible site-wide button retheme, aimed at no band in
   * particular) repainted every UNAUTHORED filled ring on every photo band to that colour,
   * which was never measured against the scrim. Against the default scrim a dark neutral
   * effectively erases the ring — the exact failure #535 and #543 exist to prevent.
   *
   * Rendered rather than asserted from CSS text because the ring rules tie on specificity
   * with their base rules and win by source order alone: only a browser proves which
   * declaration paints once the whole cascade has run. Both bands, both buttons, both
   * states, both widths.
   *
   * The global tier is a THEME-level token, not a component style slot:
   * pp_render_style_vars() renders only keys declared in the component's schema, so seeding
   * --btn-bg through the composition would be silently DROPPED and this test would assert
   * the unset render while believing it asserted the global. Set at :root, the #539 idiom.
   */
  const GLOBAL_FILL = 'rgb(31, 36, 48)'; // #1f2430, the dark neutral from the issue report
  const GLOBAL_KNOB = `:root{--btn-bg:${GLOBAL_FILL};--btn-hover-bg:${GLOBAL_FILL};}`;

  /*
   * A ring assertion is colour AND width. Every colour check below would stay green under
   * `border-width: 0`, which is the exact failure the #543 tests added a width guard for; a
   * helper makes it impossible to remember it on one sample and forget it on the next seven.
   */
  const expectRing = async (loc: any, expected: string, label: string) => {
    expect(await prop(loc, 'border-top-color'), `${label}: ring colour`).toBe(expected);
    expect(parseFloat(await prop(loc, 'border-top-width')), `${label}: ring width`).toBeGreaterThan(
      0,
    );
  };

  /*
   * THE POSITIVE CONTROL, and the reason it is not optional. Every ring assertion under the
   * injected global knob expects ON_OVERLAY — which is also exactly what the UNSET render
   * produces. So if the injection ever stops landing (a token rename, a later :root, the
   * style tag beating the theme sheet), all of these tests keep passing while proving
   * nothing. The FILL chain still routes --btn-bg by design, so the fill is the witness that
   * the knob reached this element. A flat colour also resolves the premium `background`
   * SHORTHAND to a colour, clearing the gradient — both halves are checked, the #458 idiom.
   */
  const expectGlobalKnobReached = async (loc: any, label: string) => {
    expect(await prop(loc, 'background-color'), `${label}: the global fill knob must reach the FILL`).toBe(
      GLOBAL_FILL,
    );
    expect(await prop(loc, 'background-image'), `${label}: gradient cleared, so the knob really landed`).toBe(
      'none',
    );
  };

  for (const width of [1280, 375]) {
    test(`cta: a global --btn-bg does not defeat the on-overlay ring (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 565 cta global fill');
      setComposition(pageId, [ctaPair('Ready to start?', { background_image: IMG })]);
      await open(page, pageId, width);
      await page.addStyleTag({ content: GLOBAL_KNOB });

      const primary = page.locator('.cta__button').first();
      const second = page.locator('.cta__button--secondary');
      await expect(second).toBeVisible({ timeout: 10000 });

      // The knob really landed — see expectGlobalKnobReached. Asserted FIRST, so a failed
      // injection reports as "the knob never reached" rather than as a passing ring test.
      await expectGlobalKnobReached(primary, `@${width} cta primary rest`);
      await expectGlobalKnobReached(second, `@${width} cta button2 rest`);

      // REST. Both buttons: the ring rules are separate declarations and can drift apart.
      await expectRing(primary, ON_OVERLAY, `@${width} cta primary REST`);
      await expectRing(second, ON_OVERLAY, `@${width} cta button2 REST`);
      // The pre-fix value asserted directly, so a revert fails loudly rather than merely
      // failing to match the role.
      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: the primary ring must NOT be the global fill (the #565 defect)`,
      ).not.toBe(GLOBAL_FILL);
      expect(
        await prop(second, 'border-top-color'),
        `@${width}: button2's ring must NOT be the global fill either`,
      ).not.toBe(GLOBAL_FILL);

      // HOVER. The halves moved together, so the ring must not change role under the
      // pointer — sampled one at a time, since only one element can be :hover at once.
      await primary.hover();
      await expectGlobalKnobReached(primary, `@${width} cta primary hover`);
      await expectRing(primary, ON_OVERLAY, `@${width} cta primary HOVER (no rest->hover flip)`);
      await second.hover();
      await expectGlobalKnobReached(second, `@${width} cta button2 hover`);
      await expectRing(second, ON_OVERLAY, `@${width} cta button2 HOVER`);
    });

    test(`hero: a global --btn-bg does not defeat the cover ring (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = createPage('E2E 565 hero global fill');
      setComposition(pageId, [heroPair('Ship faster', { layout: 'cover' })]);
      await open(page, pageId, width);
      await page.addStyleTag({ content: GLOBAL_KNOB });

      const primary = page.locator('.hero__cta').first();
      const second = page.locator('.hero__cta--secondary');
      await expect(second).toBeVisible({ timeout: 10000 });

      // Same positive control and same width guards as the cta twin above — the two bands
      // are separate declarations and this test is worth exactly as much as that one.
      await expectGlobalKnobReached(primary, `@${width} hero primary rest`);
      await expectGlobalKnobReached(second, `@${width} hero cta2 rest`);

      await expectRing(primary, ON_OVERLAY, `@${width} cover primary REST`);
      await expectRing(second, ON_OVERLAY, `@${width} cover cta2 REST`);
      expect(
        await prop(primary, 'border-top-color'),
        `@${width}: the cover primary ring must NOT be the global fill`,
      ).not.toBe(GLOBAL_FILL);
      expect(
        await prop(second, 'border-top-color'),
        `@${width}: cta2's ring must NOT be the global fill either`,
      ).not.toBe(GLOBAL_FILL);

      await primary.hover();
      await expectGlobalKnobReached(primary, `@${width} hero primary hover`);
      await expectRing(primary, ON_OVERLAY, `@${width} cover primary HOVER`);
      await second.hover();
      await expectGlobalKnobReached(second, `@${width} hero cta2 hover`);
      await expectRing(second, ON_OVERLAY, `@${width} cover cta2 HOVER`);
    });
  }

  /*
   * THE NARROWING, not a reversal. #535's matching-ring promise survives for a fill an
   * author aimed at THIS band. Without this pin the fix reads as "the ring is always the
   * role on a photo band", which is a stronger contract than the one that was decided (the
   * issue's option (c)) and would have silently repainted every authored flattened button.
   */
  test('a per-instance fill still rings itself on a photo band, all four buttons @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 565 per-instance fill survives');
    setComposition(pageId, [
      ctaPair(
        'Ready to start?',
        { background_image: IMG },
        {
          '--cta-button-bg': FILL,
          '--cta-button-hover-bg': FILL,
          '--cta-button2-bg': FILL,
          '--cta-button2-hover-bg': FILL,
        },
      ),
      heroPair(
        'Ship faster',
        { layout: 'cover' },
        {
          '--hero-button-bg': FILL,
          '--hero-button-hover-bg': FILL,
          '--hero-button2-bg': FILL,
          '--hero-button2-hover-bg': FILL,
        },
      ),
    ]);
    await open(page, pageId, 1280);
    // The global knob is set TOO, so this proves precedence and not merely survival: the
    // band's own fill must beat the site-wide one, which is the escape-hatch contract.
    await page.addStyleTag({ content: GLOBAL_KNOB });

    // All FOUR ring rules, not just the cta primary. Each is a physically separate
    // declaration that ties on specificity with its base rule and wins by source order
    // alone, so a static chain pin cannot stand in for a rendered one on any of them.
    const buttons: Array<[string, string]> = [
      ['.cta__button', 'cta primary'],
      ['.cta__button--secondary', 'cta button2'],
      ['.hero__cta:not(.hero__cta--secondary)', 'cover primary'],
      ['.hero__cta--secondary', 'cover cta2'],
    ];

    for (const [selector, label] of buttons) {
      const btn = page.locator(selector).first();
      await expect(btn, `${label} must render`).toBeVisible({ timeout: 10000 });

      const rest = await prop(btn, 'border-top-color');
      await btn.hover();
      const hover = await prop(btn, 'border-top-color');

      // Equality FIRST, so a regression surfaces as "the ring flipped" rather than as a
      // colour mismatch. Asserted before the absolute pins, or it could never fail alone.
      expect(hover, `${label}: rest and hover must resolve alike — the halves moved together`).toBe(
        rest,
      );
      expect(
        rest,
        `${label}: the band's own fill must still ring itself (#535 survives, per-instance)`,
      ).toBe(FILL);
      expect(rest, `${label}: and the site-wide fill must not reach this ring`).not.toBe(
        GLOBAL_FILL,
      );
      expect(
        parseFloat(await prop(btn, 'border-top-width')),
        `${label}: the matching ring must have a width`,
      ).toBeGreaterThan(0);
    }
  });

  /*
   * CONTROL — nothing authored. The decision pins every unset configuration unchanged, so
   * this must read exactly as it did before the chains were shortened.
   */
  test('unset: a global --btn-bg leaves the unset photo-band ring untouched @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 565 unset control');
    setComposition(pageId, [ctaPair('Ready to start?', { background_image: IMG })]);
    await open(page, pageId, 1280);

    const primary = page.locator('.cta__button').first();
    await expect(primary).toBeVisible({ timeout: 10000 });
    const before = await prop(primary, 'border-top-color');
    expect(before, 'the unset ring is the measured role').toBe(ON_OVERLAY);

    // Adding the global knob must not move it — that is the whole point of the change.
    await page.addStyleTag({ content: GLOBAL_KNOB });
    expect(
      await prop(primary, 'border-top-color'),
      'adding a site-wide fill knob must not move an unset photo-band ring',
    ).toBe(before);
    // ...and the knob DID land, so "unchanged" means "immune", not "never applied".
    await expectGlobalKnobReached(primary, 'unset control');
  });

  /*
   * AUTHORING PATH (Section 14.1). Every case above seeds _pp_composition directly. This one
   * drives the REAL surface — style_component, through validation — so the surviving
   * per-instance link is proven on the path an operator actually uses.
   */
  test('the surviving per-instance link holds for slots written through style_component @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 565 authoring path');
    setComposition(pageId, [ctaPair('Ready to start?', { background_image: IMG })]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, {
      '--cta-button-bg': FILL,
      '--cta-button-hover-bg': FILL,
    });
    expect(res.success, 'style_component must accept both per-instance fill slots').toBe(true);

    await open(page, pageId, 1280);
    await page.addStyleTag({ content: GLOBAL_KNOB });

    const primary = page.locator('.cta__button').first();
    await expect(primary).toBeVisible({ timeout: 10000 });
    expect(
      await prop(primary, 'border-top-color'),
      'the action-written per-instance fill must still ring itself over the global knob',
    ).toBe(FILL);
    await primary.hover();
    expect(
      await prop(primary, 'border-top-color'),
      'and on hover too — the halves moved together',
    ).toBe(FILL);
  });
});

/**
 * #536 — the section's panel CTA is the last member of the #514 masked-fill class, and its
 * three new per-instance slots actually paint.
 *
 * `.section__panel-cta` has no .hero / .cta ancestor, so the shared premium
 * `main .btn:not(...)` cascade is its ONLY fill winner. That rule paints a `background:`
 * SHORTHAND carrying a gradient background-IMAGE, which sits above any background-COLOR the
 * section block sets — so before this change a branded section simply could not carry a
 * filled accent button through composition style slots. The defect class is invisible to
 * CSS-TEXT pins (a background-color under a gradient is present in the text and absent on
 * screen), so getComputedStyle in a real browser is the acceptance surface. Premium literals
 * are probe-resolved (the #458 idiom) rather than hardcoded, so the byte-identical-when-unset
 * assertions compare against the browser's own resolution of today's gradient.
 */
test.describe('#536 section panel-CTA fill slots paint (real WP)', () => {
  let pageId = 0;

  const PANEL_PURPLE = '#7c3aed';
  const PANEL_CTA = '.section__panel-cta';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  // A text-panel section whose panel renders a CTA — the only shape in which the slots apply.
  function panelPage(title: string, style?: Record<string, string>, variant?: string): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'section',
        props: {
          id: 'pp-536-section',
          layout: 'text-panel',
          title: 'Plans',
          body: 'Pick the plan that fits.',
          panel_heading: 'Starter',
          panel_body: 'Everything you need to launch.',
          panel_cta_text: 'Book a call',
          panel_cta_url: '/contact',
          ...(variant ? { panel_cta_variant: variant } : {}),
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  async function readPanelCta(page: any) {
    return page.evaluate(() => {
      const resolve = (prop: string, value: string) => {
        const el = document.createElement('div');
        el.style.setProperty(prop, value);
        document.body.appendChild(el);
        const out = getComputedStyle(el).getPropertyValue(prop);
        el.remove();
        return out.trim();
      };
      const el = document.querySelector('.section__panel-cta') as HTMLElement;
      const cs = getComputedStyle(el);
      return {
        bgColor: cs.backgroundColor,
        bgImage: cs.backgroundImage,
        borderColor: cs.borderTopColor,
        color: cs.color,
        shadow: cs.boxShadow,
        premiumGradient: resolve(
          'background-image',
          'linear-gradient(180deg, var(--color-accent-strong) 0%, var(--color-accent-hover) 100%)',
        ),
        purple: resolve('background-color', '#7c3aed'),
        accentStrong: resolve('background-color', 'var(--color-accent-strong)'),
        colorBg: resolve('background-color', 'var(--color-bg)'),
      };
    });
  }

  for (const width of [1280, 375]) {
    test(`--section-panel-cta-bg clears the premium gradient and paints (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = panelPage('E2E 536 fill', {
        '--section-panel-cta-bg': PANEL_PURPLE,
        '--section-panel-cta-color': '#fffbe6',
        '--section-panel-cta-shadow': 'none',
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(PANEL_CTA)).toBeVisible({ timeout: 10000 });

      const got = await readPanelCta(page);

      expect(got.bgImage, `@${width}: the gradient must be cleared, not covering the slot`).toBe(
        'none',
      );
      expect(got.bgColor, `@${width}: the panel CTA must paint --section-panel-cta-bg`).toBe(
        got.purple,
      );
      // Border FOLLOWS the fill when --btn-border-color is unset (the #526 convention), so a
      // fill-only recolor keeps a matching ring instead of a stray accent-strong outline.
      expect(got.borderColor, `@${width}: the border must follow the fill`).toBe(got.purple);
      expect(got.color, `@${width}: the panel CTA must paint the ink slot`).toBe(
        'rgb(255, 251, 230)',
      );
      expect(got.shadow, `@${width}: `+'`none` must flatten the button').toBe('none');

      // The elevation contract is rest AND hover (the #514 contract this slot mirrors):
      // without --section-panel-cta-shadow in the premium HOVER chain the bevel re-grows
      // mid-interaction, which a rest-only computed pin cannot see.
      await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });
      await page.locator(PANEL_CTA).hover();
      const hovered = await readPanelCta(page);
      expect(hovered.shadow, `@${width}: `+'`none` must flatten hover too').toBe('none');
    });
  }

  // Byte-identical when unset: the whole chain must bottom out at today's premium literals.
  test('an unset panel CTA renders byte-identically @smoke', async ({ page }) => {
    pageId = panelPage('E2E 536 unset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(PANEL_CTA)).toBeVisible({ timeout: 10000 });

    const got = await readPanelCta(page);

    expect(got.bgImage, 'unset panel CTA must keep the premium gradient').toBe(
      got.premiumGradient,
    );
    expect(got.shadow, 'unset panel CTA must keep the premium bevel').not.toBe('none');
    // The border and ink chains gained a link too (#536 routes --section-panel-cta-bg into
    // the premium border chain and --section-panel-cta-color into the ink chain), so both
    // need their own unset proof: a dropped fallback or a mis-nested paren there would leave
    // the resting ring or label unpinned at the rendered level.
    expect(got.borderColor, 'unset panel CTA must keep the premium accent ring').toBe(
      got.accentStrong,
    );
    expect(got.color, 'unset panel CTA must keep the premium ink').toBe(got.colorBg);
  });

  // Variant carve-out: the fill slot must not flatten a transparent panel CTA into a
  // look-alike filled button (the secondary-contrast defect class the premium :not() chain
  // exists to prevent).
  // All three transparent variants are named in the carve-out, so all three get a proof.
  // The ELEVATION slot is asserted here too: wiring it one specificity tier lower (on the
  // bare .section__panel-cta rule) escapes the carve-out and paints a drop shadow on a
  // transparent button, which is exactly the contract lie schema.json would then be telling.
  for (const variant of ['outline', 'ghost', 'secondary']) {
    test(`the fill and elevation slots never reach a ${variant} panel CTA @smoke`, async ({
      page,
    }) => {
      pageId = panelPage(
        `E2E 536 ${variant}`,
        { '--section-panel-cta-bg': PANEL_PURPLE, '--section-panel-cta-shadow': '0 8px 20px #000' },
        variant,
      );

      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator(PANEL_CTA)).toBeVisible({ timeout: 10000 });

      const got = await readPanelCta(page);

      expect(got.bgImage, `a ${variant} panel CTA must have no fill layer`).toBe('none');
      expect(got.bgColor, `a ${variant} panel CTA must not take the fill slot`).not.toBe(
        got.purple,
      );
      expect(got.shadow, `a ${variant} panel CTA must not take the elevation slot`).not.toContain(
        '8px 20px',
      );
    });
  }
});

/**
 * #551 — a transparent/light panel CTA on a DARK band must take its ink from the panel,
 * not from the band.
 *
 * `.section--has-bg-image a` [0,1,1] and `.pp-section--inverted a` [0,1,1] are band-WIDE,
 * and they outranked `.btn--outline` / `.btn--ghost` / `.btn--secondary` [0,1,0]. But the
 * only anchor those selectors reach inside the band is `.section__panel-cta`, which sits on
 * `.section__panel` — a self-contained LIGHT surface (--color-surface). So the band's
 * near-white overlay role (or the pale on-inverted tint) painted the button label onto a
 * near-white panel:
 *
 *   bg-image band   #fafbff on #f4f7fb = 1.04:1     inverted band  #9dafee on #f4f7fb = 1.99:1
 *
 * This is a CASCADE-REACH defect, so CSS-text pins can pass while the rendered button stays
 * invisible. getComputedStyle in a real browser is the acceptance surface (the same reason
 * #536 and #424 assert here). Assertions are written against the DEFAULT band as the control
 * rather than hardcoded hexes: the whole contract is "the panel CTA renders the same ink on
 * every band", so a theme retint moves control and subject together.
 */
test.describe('#551 panel CTA ink resolves against the light panel, not the band (rendered)', () => {
  const pageIds: number[] = [];

  // Worst case for the overlay role: the scrim over a pure-WHITE image.
  const WHITE_PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

  test.afterEach(async () => {
    while (pageIds.length) deletePage(pageIds.pop() as number);
  });

  // One text-panel section: a band link in the body (the byte-identity control) and a
  // panel CTA in the panel (the subject).
  function bandPage(title: string, variant: string, band: 'default' | 'inverted' | 'bgimage'): number {
    const id = createPage(title);
    pageIds.push(id);
    setComposition(id, [
      {
        component: 'section',
        props: {
          id: 'pp-551-section',
          layout: 'text-panel',
          title: 'Plans',
          body: '<p>Body copy with an <a href="/pricing">on-band link</a>.</p>',
          panel_heading: 'Starter',
          panel_body: 'Everything a small team needs.',
          panel_cta_text: 'Book a strategy call with our solutions team',
          panel_cta_url: '/contact',
          panel_cta_variant: variant,
          ...(band === 'inverted' ? { theme: 'inverted' } : {}),
          ...(band === 'bgimage' ? { background_image: WHITE_PNG } : {}),
        },
      },
    ]);
    return id;
  }

  const colorOf = (page: any, selector: string) =>
    page.locator(selector).first().evaluate((el: Element) => getComputedStyle(el).color);

  // Contrast of an element's ink against the first opaque painted ancestor background.
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
      let bg = [255, 255, 255];
      while (node) {
        const c = parseRgb(getComputedStyle(node).backgroundColor);
        if (c.length >= 3 && (c.length < 4 || c[3] > 0)) {
          bg = c.slice(0, 3);
          break;
        }
        node = node.parentElement;
      }
      const [l1, l2] = [lum(fg), lum(bg)];
      return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }, selector);

  // The transparent/light variants are the ones the band rule broke. `primary` is the
  // control: the premium chain [0,4,1] always outranked the band rule, so it never moved.
  for (const variant of ['outline', 'ghost', 'secondary']) {
    for (const width of [1280, 375]) {
      // One case is promoted to @smoke so this accessibility defect is guarded on EVERY
      // PR, not only in the nightly full run: `ghost` at 1280 is the worst of the set —
      // it has no border either, so before the fix the button disappeared completely
      // (1.04:1 label on a transparent fill). The remaining variants and the 375 width
      // stay in the full suite to keep the smoke subset fast.
      const smoke = variant === 'ghost' && width === 1280 ? ' @smoke' : '';
      test(`${variant} panel CTA takes panel ink on every band (${width}px)${smoke}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });

        // Control: the DEFAULT band, where no band ink rule applies at all.
        const defaultId = bandPage(`E2E 551 ${variant} default ${width}`, variant, 'default');
        await page.goto(`/?page_id=${defaultId}`);
        await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });
        const controlInk = await colorOf(page, '.section__panel-cta');

        for (const band of ['inverted', 'bgimage'] as const) {
          const id = bandPage(`E2E 551 ${variant} ${band} ${width}`, variant, band);
          await page.goto(`/?page_id=${id}`);
          await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });

          const ink = await colorOf(page, '.section__panel-cta');
          const ratio = await contrastOf(page, '.section__panel-cta');

          expect(
            ink,
            `@${width} ${band}/${variant}: panel CTA ink ${ink} must match the default-band control ${controlInk} — the panel is a LIGHT surface on every band`,
          ).toBe(controlInk);
          expect(
            ratio,
            `@${width} ${band}/${variant}: panel CTA contrast ${ratio.toFixed(2)} on the light panel (was 1.04 bg-image / 1.99 inverted before #551)`,
          ).toBeGreaterThanOrEqual(4.5);
        }
      });
    }
  }

  // BYTE-IDENTITY CONTROL. The carve-out narrows the band rule's REACH and must not touch
  // its behaviour where it still applies: an on-band link keeps the band role, and that
  // role must remain visibly different from the panel CTA's panel-resolved ink.
  for (const band of ['inverted', 'bgimage'] as const) {
    test(`${band} band link keeps its on-band ink after the carve-out`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });

      const defaultId = bandPage(`E2E 551 bandlink default ${band}`, 'outline', 'default');
      await page.goto(`/?page_id=${defaultId}`);
      await expect(page.locator('.section__content a')).toBeVisible({ timeout: 10000 });
      const defaultBandLink = await colorOf(page, '.section__content a');

      const id = bandPage(`E2E 551 bandlink ${band}`, 'outline', band);
      await page.goto(`/?page_id=${id}`);
      await expect(page.locator('.section__content a')).toBeVisible({ timeout: 10000 });

      const bandLink = await colorOf(page, '.section__content a');
      const panelCta = await colorOf(page, '.section__panel-cta');

      expect(
        bandLink,
        `${band}: the on-band link must still take the dark-band accent role, not the light-surface accent ${defaultBandLink}`,
      ).not.toBe(defaultBandLink);
      expect(
        bandLink,
        `${band}: the band link and the panel CTA must resolve against DIFFERENT surfaces`,
      ).not.toBe(panelCta);
    });
  }
});

/**
 * Per-instance button slots never reach an author-written nested `.btn` (#545, real WP).
 *
 * The five slot families are emitted on the COMPONENT ROOT and three of their consumers
 * (`main .btn:not(...)`, `.hero .btn:not(...)`, `.cta .btn:not(...)`) read them by INHERITANCE,
 * so before this fix a `.btn` an author hand-writes into a wp_kses_post rich-text prop —
 * `section.body` and `hero.proof` — was repainted by the band's own button styling. The fix
 * neutralises the families on any composed `.btn` that is not a renderer-owned button element.
 *
 * This is a browser-cascade defect: the leak lives in custom-property inheritance and in which
 * rule wins the `background` SHORTHAND, so only a rendered pin can see it. Every assertion below
 * is paired — the nested button must resolve the theme default AND the component's own button
 * must still resolve the authored slot, so a fix that killed both would fail here.
 */
test.describe('#545 per-instance button slots stay off nested author buttons (real WP)', () => {
  let pageId = 0;

  const PURPLE = '#7c3aed';
  const INK = '#fffbe6';

  test.afterEach(async () => {
    if (pageId) {
      deletePage(pageId);
      pageId = 0;
    }
  });

  function sectionPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'section',
        props: {
          id: 'pp-545-section',
          layout: 'text-panel',
          title: 'Plans',
          // The author-written button, in the real rich-text prop.
          // Two nested author buttons: the filled default (the leak surface) and an outline
          // variant, which the new rule also matches and must leave completely inert.
          body: '<p>Pick a plan. <a class="btn" href="/x">Inline CTA</a> '
            + '<a class="btn btn--outline" href="/y">Outline CTA</a></p>',
          panel_heading: 'Starter',
          panel_cta_text: 'Book a call',
          panel_cta_url: '/contact',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  function heroPage(title: string, style?: Record<string, string>): number {
    const id = createPage(title);
    setComposition(id, [
      {
        component: 'hero',
        props: {
          id: 'pp-545-hero',
          layout: 'centered',
          title: 'Ship faster',
          button_text: 'Start now',
          button_url: '/start',
          proof: '<p>Trusted by teams <a class="btn" href="/x">Inline CTA</a></p>',
        },
        ...(style ? { style } : {}),
      },
    ]);
    return id;
  }

  /** Reads a button plus the theme literals it must (or must not) resolve to. */
  async function readButtons(page: any, ownedSel: string, nestedSel: string) {
    return page.evaluate(
      ({ owned, nested }: { owned: string; nested: string }) => {
        const resolve = (prop: string, value: string) => {
          const el = document.createElement('div');
          el.style.setProperty(prop, value);
          document.body.appendChild(el);
          const out = getComputedStyle(el).getPropertyValue(prop);
          el.remove();
          return out.trim();
        };
        const read = (sel: string) => {
          const el = document.querySelector(sel) as HTMLElement;
          const cs = getComputedStyle(el);
          return {
            bgColor: cs.backgroundColor,
            bgImage: cs.backgroundImage,
            borderColor: cs.borderTopColor,
            color: cs.color,
            shadow: cs.boxShadow,
          };
        };
        return {
          owned: read(owned),
          nested: read(nested),
          premiumGradient: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent-strong) 0%, var(--color-accent-hover) 100%)',
          ),
          premiumHoverGradient: resolve(
            'background-image',
            'linear-gradient(180deg, var(--color-accent) 0%, var(--color-accent-strong) 100%)',
          ),
          purple: resolve('background-color', '#7c3aed'),
          colorBg: resolve('background-color', 'var(--color-bg)'),
        };
      },
      { owned: ownedSel, nested: nestedSel },
    );
  }

  for (const width of [1280, 375]) {
    test(`section: the panel fill slots paint the panel CTA and not the body button (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = sectionPage('E2E 545 section', {
        '--section-panel-cta-bg': PURPLE,
        '--section-panel-cta-color': INK,
        '--section-panel-cta-shadow': 'none',
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });

      const got = await readButtons(page, '.section__panel-cta', '.section__content .btn');

      // The slot still does its job (#536 stays green).
      expect(got.owned.bgColor, `@${width}: the panel CTA must still paint the fill slot`).toBe(
        got.purple,
      );
      expect(got.owned.shadow, `@${width}: the panel CTA must still flatten`).toBe('none');

      // The defect: the body button used to be purple, yellow-inked and flat.
      expect(
        got.nested.bgImage,
        `@${width}: the nested button must keep the premium gradient, not the panel fill`,
      ).toBe(got.premiumGradient);
      expect(got.nested.bgColor, `@${width}: the nested button must not take the fill slot`).not.toBe(
        got.purple,
      );
      expect(got.nested.borderColor, `@${width}: the nested ring must not follow the fill`).not.toBe(
        got.purple,
      );
      expect(got.nested.color, `@${width}: the nested button must keep the theme ink`).toBe(
        got.colorBg,
      );
      expect(got.nested.shadow, `@${width}: the elevation slot must not flatten it`).not.toBe('none');
    });

    test(`hero: the hero button slots paint the hero CTA and not the proof button (${width}px) @smoke`, async ({
      page,
    }) => {
      pageId = heroPage('E2E 545 hero', {
        '--hero-button-bg': PURPLE,
        '--hero-button-color': INK,
        '--hero-button-shadow': 'none',
        '--hero-button-hover-bg': '#4c1d95',
      });

      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('.hero__cta')).toBeVisible({ timeout: 10000 });

      const got = await readButtons(page, '.hero__cta', '.hero__proof .btn');

      expect(got.owned.bgColor, `@${width}: the hero CTA must still paint the fill slot`).toBe(
        got.purple,
      );
      expect(got.nested.bgImage, `@${width}: the proof button must keep the premium gradient`).toBe(
        got.premiumGradient,
      );
      expect(got.nested.bgColor, `@${width}: the proof button must not take the fill slot`).not.toBe(
        got.purple,
      );
      expect(got.nested.color, `@${width}: the proof button must keep the theme ink`).toBe(
        got.colorBg,
      );

      // HOVER is a separate winner chain (#530 routed --hero-button-hover-bg through the
      // premium hover rule), and it leaked exactly like rest did.
      await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });
      await page.locator('.hero__proof .btn').hover();
      const hoveredNested = await readButtons(page, '.hero__cta', '.hero__proof .btn');
      // Positive control, not just `not.toBe('none')`: any gradient would satisfy a negative
      // assertion, including a wrong one.
      expect(
        hoveredNested.nested.bgImage,
        `@${width}: the proof button must resolve the premium HOVER gradient exactly`,
      ).toBe(hoveredNested.premiumHoverGradient);
      expect(
        hoveredNested.nested.bgColor,
        `@${width}: the hero hover fill slot must not reach the proof button`,
      ).not.toBe('rgb(76, 29, 149)');

      // The paired half: the hero's OWN button must still take the hover slot, so a fix that
      // killed both would fail here.
      await page.locator('.hero__cta').first().hover();
      const hoveredOwned = await readButtons(page, '.hero__cta', '.hero__proof .btn');
      expect(
        hoveredOwned.owned.bgColor,
        `@${width}: the hero CTA must still paint --hero-button-hover-bg`,
      ).toBe('rgb(76, 29, 149)');
    });
  }

  // Unset: a nested button must render exactly like a composed button with no band styling,
  // and the owned buttons must be untouched too.
  test('with no slots set, nested and owned buttons are both the theme default @smoke', async ({
    page,
  }) => {
    pageId = sectionPage('E2E 545 unset');

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });

    const got = await readButtons(page, '.section__panel-cta', '.section__content .btn');

    expect(got.nested.bgImage, 'unset: the nested button is the premium gradient').toBe(
      got.premiumGradient,
    );
    expect(got.owned.bgImage, 'unset: the panel CTA is the premium gradient').toBe(
      got.premiumGradient,
    );
    expect(got.nested.color).toBe(got.owned.color);
    expect(got.nested.borderColor).toBe(got.owned.borderColor);
    expect(got.nested.shadow).toBe(got.owned.shadow);
  });

  // The rule matches transparent-variant nested buttons too. Those never read a per-instance
  // slot (every variant chain is scoped to an owned class), so neutralising must be inert:
  // the outline button keeps its transparent fill whether or not the band is styled.
  // The scope BOUNDARY, pinned as intended behaviour rather than left implicit: band-level
  // accents are deliberately NOT neutralised. `.hero .btn:not(...)` is [0,5,0], which outranks
  // the premium winner at [0,4,1], so a hero band accent rings an author-written proof button
  // exactly as it accents every other element in the band. If a future change decides that is
  // wrong, this test is where the decision gets revisited — it is not an accident.
  test('a hero BAND ACCENT still reaches a nested author button (deliberate) @smoke', async ({
    page,
  }) => {
    const ACCENT_545 = '#c2410c';
    pageId = heroPage('E2E 545 band accent', { '--hero-accent': ACCENT_545 });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.hero__proof .btn')).toBeVisible({ timeout: 10000 });

    const got = await page.evaluate(() => {
      const resolve = (value: string) => {
        const el = document.createElement('div');
        el.style.setProperty('background-color', value);
        document.body.appendChild(el);
        const out = getComputedStyle(el).backgroundColor;
        el.remove();
        return out;
      };
      const nested = document.querySelector('.hero__proof .btn') as HTMLElement;
      const owned = document.querySelector('.hero__cta') as HTMLElement;
      return {
        nestedBorder: getComputedStyle(nested).borderTopColor,
        ownedBorder: getComputedStyle(owned).borderTopColor,
        accent: resolve('#c2410c'),
      };
    });

    expect(got.nestedBorder, 'the band accent is not neutralised — it rings the nested button too')
      .toBe(got.accent);
    expect(got.ownedBorder, 'and it rings the hero CTA the same way').toBe(got.accent);
  });

  test('a nested OUTLINE author button is unaffected, styled band or not @smoke', async ({
    page,
  }) => {
    const read = async () =>
      page.evaluate(() => {
        const el = document.querySelector('.section__content .btn--outline') as HTMLElement;
        const cs = getComputedStyle(el);
        return {
          bgColor: cs.backgroundColor,
          bgImage: cs.backgroundImage,
          borderColor: cs.borderTopColor,
          color: cs.color,
        };
      });

    pageId = sectionPage('E2E 545 nested outline unset');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.section__content .btn--outline')).toBeVisible({ timeout: 10000 });
    const unset = await read();
    deletePage(pageId);

    pageId = sectionPage('E2E 545 nested outline styled', {
      '--section-panel-cta-bg': PURPLE,
      '--section-panel-cta-color': INK,
      '--section-panel-cta-shadow': 'none',
    });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.section__content .btn--outline')).toBeVisible({ timeout: 10000 });
    const styled = await read();

    expect(styled, 'the band fill slots must not reach a nested outline button').toEqual(unset);
    expect(unset.bgImage, 'an outline button keeps no gradient').toBe('none');
    expect(unset.bgColor, 'an outline button keeps a transparent fill').toBe('rgba(0, 0, 0, 0)');
  });

  // The GLOBAL tier is deliberately NOT neutralised: a site-wide button retheme must still
  // reach an author-written button, exactly as it reaches every composed one.
  test('a site-wide --btn-bg still repaints a nested author button @smoke', async ({ page }) => {
    pageId = sectionPage('E2E 545 global tier', { '--section-panel-cta-bg': PURPLE });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('.section__panel-cta')).toBeVisible({ timeout: 10000 });

    // Seed the global knob at :root — pp_render_style_vars() drops keys outside a
    // component's declared style_slots, so the global tier cannot be seeded per component.
    // Kill transitions in the SAME tag: .btn transitions background-color, so recolouring a
    // painted button mid-test reads back an interpolated value (observed: the teal at 11%
    // alpha) rather than the settled one.
    await page.addStyleTag({
      content: '*,*::before,*::after{transition:none !important;} :root { --btn-bg: #0e7490; }',
    });

    const got = await readButtons(page, '.section__panel-cta', '.section__content .btn');
    const teal = await page.evaluate(() => {
      const el = document.createElement('div');
      el.style.setProperty('background-color', '#0e7490');
      document.body.appendChild(el);
      const out = getComputedStyle(el).backgroundColor;
      el.remove();
      return out;
    });

    expect(got.nested.bgColor, 'the global tier must still reach the nested button').toBe(teal);
    expect(got.nested.bgImage, 'a flat global fill clears the gradient there too').toBe('none');
    // The per-instance slot still outranks the global tier on the button that owns it.
    expect(got.owned.bgColor, 'the panel CTA keeps its per-instance fill').toBe(got.purple);
  });
});


/**
 * #583 — stressed-state rendered coverage for `table`, `embed` and `logos`.
 *
 * These three components carry no component-level test of any kind. The shared
 * band suites above (#431 padding equality, #430 symmetry, #436 heading scale)
 * render all three incidentally and assert their BAND edges, but nothing anywhere
 * touches their internals: table cells / header / caption / scroll, embed's content
 * measure, logos items / labels / image caps / gap. Four gates in the v1.13.0 family
 * ship CSS and schema changes into exactly those internals. This block is the
 * regression net they diff against, so it is deliberately a BYTE-IDENTITY BASELINE:
 * it records the numbers that render today, not the numbers anyone wants.
 *
 *   BAND (padding edges)          <- already covered by #431/#430; re-asserted here
 *     |                              so the baseline is self-contained
 *     +-- heading  --------------- max-width: var(--<c>-heading-measure, var(--measure-heading))
 *                                     (was ONE shared var(--cta-heading-measure, 40rem); #578 severed it)
 *     +-- body / per-item surface
 *          table : .table-wrap > .table > thead/th, tbody/td, caption
 *          embed : .embed__content (max-width: 40rem)                          <- #578 + #577
 *          logos : .logos__list (gap) > .logos__item[--labeled] > img + label   <- #584
 *
 * SEEDING. Every fixture below is seeded through raw `_pp_composition` meta
 * (setComposition), which BYPASSES pp_validate_composition. Each fixture states
 * whether it is a shape the action surface would also accept ("authorable") or one
 * only raw meta can produce. The only non-authorable shape here is the logos
 * label-only item (see the mixed-strip test) — everything else is a normal write.
 *
 * MEASURE PINS ASSERT THE ROUTE, NOT ONLY THE NUMBER. A pin that only checks
 * "the heading is 640px wide" survives the deletion of the slot it is supposed to
 * protect: replacing the per-component `var(--<c>-heading-measure, …)` with a bare `40rem` keeps the
 * number and loses the capability. Each long-heading case therefore ALSO drives the
 * slot to a second value and re-measures, so #578 cannot sever the route without
 * this block noticing.
 *
 * NUMBERS. The #570 design corpus quotes 18.2:1 (table cells), 3.09:1 (caption and
 * logos label) and 1.02:1 (heading on a dark paint). Those were measured on a dev
 * install carrying NeoCompute dogfood brand tokens. Against the shipped defaults in
 * assets/css/base.css (--color-text #101828, --color-bg #fcfdff, --color-muted
 * #5e6677) the same three measurements are 17.44:1, 5.66:1 and 1.04:1. The theme's
 * own defaults are what this net records; the corpus figures are a property of that
 * one branded install, not of the product.
 */

/**
 * WCAG relative-luminance contrast of an element's computed `color` against the
 * first painted (alpha > 0.5) ancestor background.
 *
 * Read every figure it produces as COMPUTED INK CONTRAST, not as what a camera would
 * measure. It sees `color` and `background-color` only: opacity, gradients, background
 * images, overlays, borders and any translucent layer below alpha 0.5 are invisible to
 * it. That is enough for this net, whose job is to record which token reaches which
 * element before four gates re-route them — but it is not an accessibility audit.
 *
 * THROWS on a selector that matches nothing. The older in-describe copies return 0,
 * which reports a class rename as "expected 17.44, received 0" — a contrast failure
 * for what is really a missing element. Since the four v1.13.0 gates rename classes
 * and slots, the new copy fails loudly instead.
 *
 * Two honest limits, both relevant to how the results below are read:
 *  - `opacity` is invisible here. An element faded by itself or an ancestor still
 *    reports its unfaded ratio, so where fading matters (`.logos--inverted
 *    .logos__label` at 0.75) the opacity is asserted separately rather than folded
 *    into a misleadingly high number.
 *  - the channel scrape assumes Chromium's legacy `rgb()` serialization. A computed
 *    value in a modern colour space (`color(srgb …)`, `oklch(…)`, which `color-mix()`
 *    can produce) would parse as 0-1 channels and yield a wrong ratio rather than an
 *    error. None of the values measured here resolve through `color-mix()` today.
 *
 * Three older suites in this file (#437, #461/#463, #424) each carry their own copy
 * of this computation. They are left alone — this issue ships no production change
 * and refactoring neighbouring suites is out of its scope — but new suites should
 * use this one rather than adding a fifth copy.
 */
function measureContrast(page: any, selector: string): Promise<number> {
  return page.evaluate((sel: string) => {
    const parseRgb = (s: string): number[] => (s.match(/[\d.]+/g) || []).map(Number);
    const lum = (rgb: number[]): number => {
      const f = (v: number) => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
    };
    const el = document.querySelector(sel);
    if (!el) throw new Error(`measureContrast: no element matched ${sel}`);
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
}

/**
 * The rendered box of a heading plus how many LINE BOXES its text actually occupies.
 *
 * Line count comes from a Range over the element's contents: each wrapped line is a
 * separate client rect, and rects sharing a rounded `top` are the same line. Counting
 * rects rather than measuring height keeps the answer independent of line-height.
 *
 * `capPx` is resolved from the document's own root font size rather than hardcoded to
 * 640, and the callers compare `maxWidth` against `capPx` too — so the pin keeps
 * meaning "40rem" rather than "640px" if a browser or a future base rule moves the
 * root size. `containerWidth` is the container's CONTENT box (its side padding is
 * inside clientWidth), which is what the heading can actually spread across: at 1280
 * that is 1088px and the cap binds, at 375 it is 343px and the cap is inert.
 */
function measureHeadingBox(page: any, selector: string) {
  return page.evaluate((sel: string) => {
    const el = document.querySelector(sel) as HTMLElement;
    if (!el) throw new Error(`measureHeadingBox: no element matched ${sel}`);
    const range = document.createRange();
    range.selectNodeContents(el);
    const tops = new Set(
      Array.from(range.getClientRects())
        .filter((r) => r.width > 0 && r.height > 0)
        .map((r) => Math.round(r.top)),
    );
    const rootFont = parseFloat(getComputedStyle(document.documentElement).fontSize);
    const container = el.closest('.container') as HTMLElement;
    const ccs = getComputedStyle(container);
    return {
      measuredWidth: el.clientWidth,
      maxWidth: getComputedStyle(el).maxWidth,
      lineCount: tops.size,
      capPx: Math.round(rootFont * 40),
      containerWidth:
        container.clientWidth - parseFloat(ccs.paddingLeft) - parseFloat(ccs.paddingRight),
    };
  }, selector);
}

/** No part of the document may scroll sideways past the viewport. */
async function expectNoViewportOverflow(page: any, label: string) {
  const doc = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
  expect(doc.scroll, `${label}: document must not scroll horizontally`).toBeLessThanOrEqual(
    doc.client + 1,
  );
}

test.describe('#583 stressed-state rendered coverage (table, embed, logos)', () => {
  let pageId: number;

  // ── Rendered constants, named once ─────────────────────────────────────────
  // Every literal below is the computed serialization of a shipped default. They are
  // named because four follow-up gates route these exact values through new slots and
  // will need to find every pin, not eleven scattered copies of the same string.
  const INK = 'rgb(16, 24, 40)'; //        --color-text  #101828
  const PAGE_BG = 'rgb(252, 253, 255)'; // --color-bg    #fcfdff
  const MUTED_INK = 'rgb(94, 102, 119)'; //--color-muted #5e6677
  const SURFACE = 'rgb(244, 247, 251)'; // --color-surface #f4f7fb
  const BORDER = 'rgb(217, 224, 235)'; //  --color-border  #d9e0eb
  const INVERTED_BG = 'rgb(15, 23, 42)'; //--color-bg-inverted #0f172a
  const MEASURE = '640px'; //              the shared 40rem heading/body measure
  const LOGO_CAP_PX = 48; //               .logos__image          max-height: 3rem
  const LABELED_LOGO_CAP_PX = 40; //       .logos__item--labeled  max-height: 2.5rem
  const LIST_GAP = '32px'; //              .logos__list gap: var(--space-lg)
  const HEADING_RHYTHM = '32px'; //        heading margin-bottom: var(--space-lg)

  // Measured contrast, against the SHIPPED tokens (see the block docblock for why
  // these differ from the #570 corpus figures).
  const INK_ON_BG = 17.44;
  const MUTED_ON_BG = 5.66;
  const INK_ON_INVERTED = 17.54;
  const ACCENT_LINK_ON_INVERTED = 8.33;

  // [viewport, band padding edge, band heading size] — the shared band rhythm (#430/
  // #431) and heading scale (#436) at the two breakpoints this net measures.
  //
  // The 375 heading size is `28px` because `--pp-band-heading-size`'s clamp FLOOR
  // (1.75rem) wins there: the middle term evaluates to 27.9925px at a 375px viewport.
  // If this pin ever fails by a few thousandths of a pixel, the cause is the clamp
  // slope or the root font size moving, not the component.
  const BAND_VIEWPORTS = [
    [1280, '76.8px', '38.4px'],
    [375, '53.6px', '28px'],
  ] as const;

  // A 60x180 solid PNG. PORTRAIT and taller than both caps on purpose: an asset
  // shorter than 48px would render at its intrinsic height and the cap assertions
  // would pass without the cap ever binding. The mixed-strip test asserts
  // naturalHeight > LOGO_CAP_PX before measuring so that trap stays closed.
  const TALL_PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADwAAAC0CAIAAABHfdiQAAAAs0lEQVR4nO3OAQkAIBAAMStYwSz2z2QM72GwAFv73HHW94F0mLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHTAyPQD1EOdbnWkTyIAAAAASUVORK5CYII=';

  // 117 characters. At the 40rem (640px) desktop measure this cannot fit on one line
  // under any plausible font stack, so the "it wrapped" half of the pin does not
  // depend on CI font metrics. Measured today: 4 lines at 1280.
  const LONG_TITLE =
    'Trusted by product and platform teams shipping brand-consistent marketing sites every single week across many regions';

  const LONG_CELL =
    'Unlimited composed bands with per-instance style slots, brand token inheritance and a documented rollback path for every published revision';

  // Long enough to wrap INSIDE the table's own max-content width (~1596px with the
  // fixture below), which is the box the caption is actually laid out against — see
  // the long-content test for why that is not the band width.
  const LONG_CAPTION =
    'Figures reflect the published rate card and exclude taxes, onboarding and any negotiated multi-year discount agreed during procurement. ' +
    'Prices are reviewed annually and any change is announced at least one full billing cycle before it takes effect, with the previous rate ' +
    'honoured for the remainder of the current term.';

  /** Authorable: title + headers + rows + caption all pass pp_validate_composition. */
  const BASE_TABLE_PROPS = {
    id: 'pp-tbl583',
    title: 'How the plans compare',
    caption: 'Rate card figures.',
    headers: ['Capability', 'Starter'],
    rows: [['Composed bands', 'Unlimited']],
  };

  // Realistic multi-column comparison content: four columns, long nowrap headers and
  // one long cell. `.table` sizes itself with `width: max-content`, so both the
  // headers and the long cell push it well past the desktop container.
  const WIDE_TABLE_PROPS = {
    ...BASE_TABLE_PROPS,
    caption: LONG_CAPTION,
    headers: [
      'Capability',
      'Starter plan',
      'Growth plan for scaling teams',
      'Enterprise plan with dedicated support',
    ],
    rows: [
      [LONG_CELL, 'Included', 'Included with priority scheduling', 'Included with a named lead'],
      ['Support channels', 'Email', 'Email and chat', 'Email, chat and phone'],
    ],
  };

  /** Authorable: one unlabeled and one labeled item, both with a real image. */
  const BASE_LOGOS_PROPS = {
    id: 'pp-log583',
    title: 'Trusted by',
    items: [
      { image_url: TALL_PNG, image_alt: 'Unlabeled' },
      { image_url: TALL_PNG, image_alt: 'Labeled', label: 'Delivery' },
    ],
  };

  /** Size the viewport, load the seeded page, wait for the band to paint. */
  async function open(page: any, width: number, readySelector: string) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(readySelector).first()).toBeVisible({ timeout: 10000 });
  }

  /**
   * Wait for every logos image to decode, so height measurements are not taken on a
   * 0x0 box. `expected` is asserted, not assumed: `Array.every` on an EMPTY list is
   * true, so without a count this wait would resolve instantly the day `.logos__image`
   * stops being emitted — which is exactly what the #584 rename touches.
   */
  async function awaitLogoImages(page: any, expected: number) {
    await page.waitForFunction((n: number) => {
      const imgs = Array.from(document.querySelectorAll('.logos__image')) as any[];
      return imgs.length === n && imgs.every((i) => i.complete && i.naturalWidth > 0);
    }, expected);
    // The rendered image WIDTH pin below is fixture-derived (aspect ratio x cap), so a
    // silently altered asset would read as a CSS regression. Pin the asset instead.
    const natural = await page.locator('.logos__image').first().evaluate((el: any) => ({
      w: el.naturalWidth,
      h: el.naturalHeight,
    }));
    expect(natural, 'TALL_PNG must still be the 60x180 fixture asset').toEqual({ w: 60, h: 180 });
  }

  /** The shared band-heading contract: one scale, --color-text ink, 40rem measure. */
  function expectSharedHeading(
    got: { fontSize: string; color: string; marginBottom: string; maxWidth: string },
    headingSize: string,
    label: string,
  ) {
    expect(got, label).toEqual({
      fontSize: headingSize,
      color: INK,
      marginBottom: HEADING_RHYTHM,
      maxWidth: MEASURE,
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

  // ── long headings: one case per component, same contract ─────────────────────

  /**
   * The direct net for the measure-surface severance (#578), UPDATED BY IT.
   *
   * Before #578 all three headings capped through ONE shared rule reading
   * `var(--cta-heading-measure, 40rem)` — a CTA slot on three foreign elements — and
   * this pin drove `--cta-heading-measure` to prove the cap was slot-routed rather than
   * a literal. #578 severed that rule into `var(--<c>-heading-measure,
   * var(--measure-heading))`, so driving the CTA slot from a foreign band now correctly
   * moves NOTHING. The pin's contract is unchanged and its comment always said so ("it
   * deliberately does not pin the variable's spelling"); only the spelling moved.
   *
   * Four things are pinned, because only the last two survive a bad severance:
   *   1. the cap resolves to 40rem and binds at desktop (the number),
   *   2. the title actually wraps inside it (the fixture is genuinely stressed),
   *   3. driving the component's OWN slot MOVES the heading (the route still exists),
   *   4. driving `--cta-heading-measure` does NOT move it (the leak is really gone).
   *
   * Without (3) a rewrite to a literal `max-width: 40rem` keeps every number and loses
   * the authorable slot silently. Without (4) a severance that merely demoted the CTA
   * slot to an intermediate fallback — `var(--table-heading-measure,
   * var(--cta-heading-measure, 40rem))` — would keep every number AND keep the leak.
   */
  const HEADING_CASES = [
    {
      name: 'table',
      selector: '.table-section__heading',
      slot: '--table-heading-measure',
      props: () => ({ component: 'table', props: { ...WIDE_TABLE_PROPS, title: LONG_TITLE } }),
    },
    {
      name: 'embed',
      selector: '.embed__heading',
      slot: '--embed-heading-measure',
      props: () => ({
        component: 'embed',
        props: { id: 'pp-emb583', title: LONG_TITLE, content: '<p>Embedded body copy.</p>' },
      }),
    },
    {
      name: 'logos',
      selector: '.logos__heading',
      slot: '--logos-heading-measure',
      props: () => ({
        component: 'logos',
        props: { ...BASE_LOGOS_PROPS, title: LONG_TITLE },
      }),
    },
    // The other three the shared rule capped. `stats` is the one that most needs a
    // rendered check: its issue-367 auto-inline-margin centering is documented as
    // depending on this h2 having a 40rem box, so a cap that failed to resolve would
    // break the centering while every static text check stayed green.
    {
      name: 'cta',
      selector: '.cta__title',
      slot: '--cta-heading-measure',
      props: () => ({
        component: 'cta',
        props: { id: 'pp-cta583', title: LONG_TITLE, button_text: 'Go', button_url: '/go' },
      }),
    },
    {
      name: 'stats',
      selector: '.stats__heading',
      slot: '--stats-heading-measure',
      props: () => ({
        component: 'stats',
        props: { id: 'pp-st583', title: LONG_TITLE, items: [{ number: '99%', label: 'Uptime' }] },
      }),
    },
    {
      name: 'faq',
      selector: '.faq__heading',
      slot: '--faq-heading-measure',
      props: () => ({
        component: 'faq',
        props: { id: 'pp-faq583', title: LONG_TITLE, items: [{ question: 'Q?', answer: 'A.' }] },
      }),
    },
  ] as const;

  for (const heading of HEADING_CASES) {
    test(`#583 ${heading.name} long heading wraps inside the 40rem measure and follows the slot @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E 583 ${heading.name} long heading`);
      setComposition(pageId, [heading.props()]);

      // Desktop: the cap binds and the title wraps inside it.
      await open(page, 1280, heading.selector);
      const desktop = await measureHeadingBox(page, heading.selector);
      expect(desktop.maxWidth, 'the heading cap resolves to 40rem').toBe(`${desktop.capPx}px`);
      expect(desktop.containerWidth, 'the container is wider than the cap at 1280').toBeGreaterThan(
        desktop.capPx,
      );
      expect(desktop.measuredWidth, 'the heading box is the 40rem cap').toBe(desktop.capPx);
      // Measured today: 4 lines. Asserted as ">= 2" because line count is font-metric
      // dependent and this repo ships no webfont (CI runs a bare Linux font stack).
      expect(desktop.lineCount, 'the long title actually wrapped').toBeGreaterThanOrEqual(2);

      // Mobile: the container is narrower than the cap, so the cap goes inert. The
      // heading must fall back to the container rather than keep a 640px box.
      //
      // Measured BEFORE the slot injection below, so no assertion here depends on a
      // navigation discarding an injected <style>.
      await open(page, 375, heading.selector);
      const mobile = await measureHeadingBox(page, heading.selector);
      expect(mobile.containerWidth, 'the container is narrower than the cap at 375').toBeLessThan(
        mobile.capPx,
      );
      expect(mobile.measuredWidth, '@375: the container binds, the cap is inert').toBe(
        mobile.containerWidth,
      );
      expect(mobile.lineCount, '@375: the long title wrapped further').toBeGreaterThanOrEqual(
        desktop.lineCount,
      );
      await expectNoViewportOverflow(page, `${heading.name} long heading @375`);

      // (3) The ROUTE, not the number: drive the component's own slot and the heading must
      // follow. This pins that an AUTHORABLE measure still reaches the heading.
      // Verified by mutation: replacing the var() with the literal turns this red.
      // Back to 1280 first — at 375 the container binds and no cap value is observable.
      await open(page, 1280, heading.selector);
      await page.addStyleTag({ content: `:root { ${heading.slot}: 30rem; }` });
      const driven = await measureHeadingBox(page, heading.selector);
      const drivenCap = Math.round((desktop.capPx / 40) * 30);
      expect(driven.maxWidth, 'the heading measure is still slot-routed, not a literal').toBe(
        `${drivenCap}px`,
      );
      expect(driven.measuredWidth, 'the driven cap reaches the rendered box').toBe(drivenCap);
    });

    // (4) The severance itself. A fresh page so no injected <style> from the test above
    // survives; driving the CTA slot must leave this band exactly where it was.
    // Skipped for cta, which legitimately OWNS --cta-heading-measure — the leak was five
    // foreign bands reading it, never cta reading its own slot.
    //
    // NOT-APPLICABLE(cta owns --cta-heading-measure, so "does not follow the cta slot"
    // is not a property it can have; the other five heading variants all run). This is a
    // parametrised inapplicable case, NOT a quarantine: nothing here is excluded from the
    // gate pending a fix, so it carries no owner. See #697.
    // eslint-disable-next-line playwright/no-skipped-test
    (heading.name === 'cta' ? test.skip : test)(
      `#578 ${heading.name} heading no longer follows the cta measure slot @smoke`,
      async ({ page }) => {
      pageId = createPage(`E2E 578 ${heading.name} cta severance`);
      setComposition(pageId, [heading.props()]);

      await open(page, 1280, heading.selector);
      const before = await measureHeadingBox(page, heading.selector);
      await page.addStyleTag({ content: ':root { --cta-heading-measure: 30rem; }' });
      const after = await measureHeadingBox(page, heading.selector);

      expect(
        after.maxWidth,
        `${heading.name} must not read a CTA slot — it could never set one (the write path ` +
          'rejects a foreign slot), so a cap it cannot author is a cap it does not have',
      ).toBe(before.maxWidth);
      expect(after.measuredWidth, 'the rendered box is unmoved too').toBe(before.measuredWidth);
      // And the token the band DOES route still reaches it, so this is a severance, not a
      // freeze: the number above is held by --measure-heading, not by a literal.
      await page.addStyleTag({ content: ':root { --measure-heading: 30rem; }' });
      const retuned = await measureHeadingBox(page, heading.selector);
      expect(
        retuned.maxWidth,
        'one --measure-heading write must still retune this band (the point of A-39)',
      ).toBe(`${Math.round((before.capPx / 40) * 30)}px`);
      },
    );
  }

  // ── table ────────────────────────────────────────────────────────────────────

  test('#583 table baseline pins its band, heading, cells and caption at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 table baseline');
    setComposition(pageId, [{ component: 'table', props: BASE_TABLE_PROPS }]);

    for (const [width, bandPadding, headingSize] of BAND_VIEWPORTS) {
      await open(page, width, '.table-section');

      const got = await page.evaluate(() => {
        const cs = getComputedStyle;
        // Throws with the selector name rather than a bare null dereference, so a class
        // rename in a follow-up gate reports WHAT went missing.
        const q = (s: string) => {
          const el = document.querySelector(s) as HTMLElement;
          if (!el) throw new Error(`no element matched ${s}`);
          return el;
        };
        return {
          band: {
            top: cs(q('.table-section')).paddingTop,
            bottom: cs(q('.table-section')).paddingBottom,
          },
          heading: {
            fontSize: cs(q('.table-section__heading')).fontSize,
            color: cs(q('.table-section__heading')).color,
            marginBottom: cs(q('.table-section__heading')).marginBottom,
            maxWidth: cs(q('.table-section__heading')).maxWidth,
          },
          wrap: {
            overflowX: cs(q('.table-wrap')).overflowX,
            borderTopWidth: cs(q('.table-wrap')).borderTopWidth,
            borderTopColor: cs(q('.table-wrap')).borderTopColor,
            borderRadius: cs(q('.table-wrap')).borderTopLeftRadius,
          },
          table: {
            minWidth: cs(q('.table')).minWidth,
            backgroundColor: cs(q('.table')).backgroundColor,
            fontSize: cs(q('.table')).fontSize,
            borderCollapse: cs(q('.table')).borderCollapse,
          },
          head: { backgroundColor: cs(q('.table__head')).backgroundColor },
          th: {
            padding: cs(q('.table__header')).padding,
            whiteSpace: cs(q('.table__header')).whiteSpace,
            fontWeight: cs(q('.table__header')).fontWeight,
            color: cs(q('.table__header')).color,
            borderBottomWidth: cs(q('.table__header')).borderBottomWidth,
          },
          td: {
            padding: cs(q('.table__cell')).padding,
            whiteSpace: cs(q('.table__cell')).whiteSpace,
            overflowWrap: cs(q('.table__cell')).overflowWrap,
            color: cs(q('.table__cell')).color,
            verticalAlign: cs(q('.table__cell')).verticalAlign,
          },
          caption: {
            captionSide: cs(q('.table__caption')).captionSide,
            padding: cs(q('.table__caption')).padding,
            fontSize: cs(q('.table__caption')).fontSize,
            color: cs(q('.table__caption')).color,
            textAlign: cs(q('.table__caption')).textAlign,
          },
        };
      });

      // Band edges: the shared symmetric rhythm (#430/#431), re-pinned per component so
      // this baseline stands on its own when a slot rename moves the fallback chain.
      expect(got.band, `@${width}: table band edges`).toEqual({
        top: bandPadding,
        bottom: bandPadding,
      });
      expectSharedHeading(got.heading, headingSize, `@${width}: table heading`);
      expect(got.wrap, `@${width}: table scroll shell`).toEqual({
        overflowX: 'auto',
        borderTopWidth: '1px',
        borderTopColor: BORDER,
        borderRadius: '6px',
      });
      // The .table surface paints its OWN light island — this is why the cells stay legible
      // no matter what the band behind them is painted (the #570 corpus' key correction).
      expect(got.table, `@${width}: table surface`).toEqual({
        minWidth: '100%',
        backgroundColor: PAGE_BG,
        fontSize: '15px',
        borderCollapse: 'collapse',
      });
      expect(got.head.backgroundColor, `@${width}: thead surface`).toBe(SURFACE);
      expect(got.th, `@${width}: table header cell`).toEqual({
        padding: '8px 16px',
        whiteSpace: 'nowrap',
        fontWeight: '700',
        color: INK,
        borderBottomWidth: '2px',
      });
      expect(got.td, `@${width}: table body cell`).toEqual({
        padding: '8px 16px',
        whiteSpace: 'normal',
        overflowWrap: 'anywhere',
        color: INK,
        verticalAlign: 'top',
      });
      // The caption is UNSLOTTED today: colour is the bare var(--color-muted) literal.
      expect(got.caption, `@${width}: table caption`).toEqual({
        captionSide: 'bottom',
        padding: '8px 16px',
        fontSize: '14px',
        color: MUTED_INK,
        textAlign: 'left',
      });

      await expectNoViewportOverflow(page, `table baseline @${width}`);
    }
  });

  /**
   * Long content. The table's horizontal scroll is VIEWPORT-INDEPENDENT: `.table` is
   * `width: max-content` inside an `overflow-x: auto` shell, so the mechanism is driven
   * by content, not by a media query. The schema and README call it "mobile" behaviour;
   * that description is wrong and is corrected by the docs gate (#585). Asserted at BOTH
   * widths so the fixture records the real mechanism.
   *
   * Three consequences worth recording because they are counter-intuitive:
   *  - the LONG CELL alone is enough to overflow, even though `.table__cell` wraps
   *    anywhere: `width: max-content` ignores soft wrap opportunities.
   *  - for the same reason `.table__cell { white-space: normal; overflow-wrap:
   *    anywhere }` is DECLARED but never observable: the table always sizes itself so
   *    the cell fits on one line. Probed at both breakpoints with a 300-character
   *    unbroken token as well: the cell rendered 2550px wide, still on ONE line. The
   *    declared asymmetry against `.table__header { white-space: nowrap }` is real in
   *    the cascade and inert in the render, so the cell half is pinned as one line —
   *    if that pin ever goes red, a table-width constraint shipped and the wrap
   *    capability became live.
   *  - the `<caption>` is laid out against the TABLE's max-content box, not the band,
   *    so it is ~1596px wide here and scrolls sideways WITH the table. It wraps only
   *    when the text exceeds that, which is why LONG_CAPTION is as long as it is.
   */
  test('#583 table long content scrolls at 1280 AND 375; header nowrap, cell wrap inert @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 table long content');
    setComposition(pageId, [{ component: 'table', props: WIDE_TABLE_PROPS }]);

    for (const width of [1280, 375]) {
      await open(page, width, '.table-wrap');

      const got = await page.evaluate(() => {
        const lineCount = (el: Element) => {
          const r = document.createRange();
          r.selectNodeContents(el);
          return new Set(
            Array.from(r.getClientRects())
              .filter((x) => x.width > 0 && x.height > 0)
              .map((x) => Math.round(x.top)),
          ).size;
        };
        const wrap = document.querySelector('.table-wrap') as HTMLElement;
        const table = document.querySelector('.table') as HTMLElement;
        const headers = Array.from(document.querySelectorAll('.table__header')) as HTMLElement[];
        const td = document.querySelector('.table__cell') as HTMLElement;
        const caption = document.querySelector('.table__caption') as HTMLElement;
        return {
          wrapClient: wrap.clientWidth,
          wrapScroll: wrap.scrollWidth,
          tableWidth: Math.round(table.getBoundingClientRect().width),
          headerCount: headers.length,
          // Longest header: 'Enterprise plan with dedicated support'. The FIRST header
          // is a single unwrappable word, so measuring it would prove nothing about
          // white-space: nowrap.
          longestHeaderLines: lineCount(headers[headers.length - 1]),
          thWhiteSpace: getComputedStyle(headers[0]).whiteSpace,
          tdWhiteSpace: getComputedStyle(td).whiteSpace,
          tdOverflowWrap: getComputedStyle(td).overflowWrap,
          cellLines: lineCount(td),
          captionWidth: Math.round(caption.getBoundingClientRect().width),
          captionLines: lineCount(caption),
        };
      });

      // The scroll mechanism fires at desktop too, not only at 375.
      expect(
        got.wrapScroll,
        `@${width}: the table overflows its shell (scroll engaged)`,
      ).toBeGreaterThan(got.wrapClient);
      // The asymmetry IS the design: the header refuses to wrap, the cell wraps anywhere.
      expect(got.thWhiteSpace, `@${width}: header refuses to wrap`).toBe('nowrap');
      expect(got.headerCount, `@${width}: the fixture really is four columns`).toBe(4);
      expect(
        got.longestHeaderLines,
        `@${width}: the longest header stays on ONE line (nowrap)`,
      ).toBe(1);
      expect(got.tdWhiteSpace, `@${width}: cell wraps`).toBe('normal');
      expect(got.tdOverflowWrap, `@${width}: cell breaks inside words`).toBe('anywhere');
      expect(
        got.cellLines,
        `@${width}: the cell's wrap capability is inert under width:max-content — a red here means a table-width constraint shipped`,
      ).toBe(1);
      // The caption tracks the TABLE box, not the band — it scrolls with the table.
      expect(got.captionWidth, `@${width}: caption spans the max-content table`).toBe(
        got.tableWidth,
      );
      expect(got.captionLines, `@${width}: the long caption wrapped`).toBeGreaterThanOrEqual(2);

      // Degradation: the shell absorbs the overflow — the PAGE never scrolls sideways.
      await expectNoViewportOverflow(page, `table long content @${width}`);
    }
  });

  // ── embed ────────────────────────────────────────────────────────────────────

  test('#583 embed baseline pins its band, heading and content surface at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 embed baseline');
    setComposition(pageId, [
      {
        component: 'embed',
        props: { id: 'pp-emb583', title: 'Book a call', content: '<p>Embedded body copy.</p>' },
      },
    ]);

    for (const [width, bandPadding, headingSize] of BAND_VIEWPORTS) {
      await open(page, width, '.embed');

      const got = await page.evaluate(() => {
        const cs = getComputedStyle;
        // Throws with the selector name rather than a bare null dereference, so a class
        // rename in a follow-up gate reports WHAT went missing.
        const q = (s: string) => {
          const el = document.querySelector(s) as HTMLElement;
          if (!el) throw new Error(`no element matched ${s}`);
          return el;
        };
        return {
          band: { top: cs(q('.embed')).paddingTop, bottom: cs(q('.embed')).paddingBottom },
          heading: {
            fontSize: cs(q('.embed__heading')).fontSize,
            color: cs(q('.embed__heading')).color,
            marginBottom: cs(q('.embed__heading')).marginBottom,
            maxWidth: cs(q('.embed__heading')).maxWidth,
          },
          content: {
            maxWidth: cs(q('.embed__content')).maxWidth,
            color: cs(q('.embed__content')).color,
          },
        };
      });

      expect(got.band, `@${width}: embed band edges`).toEqual({
        top: bandPadding,
        bottom: bandPadding,
      });
      expectSharedHeading(got.heading, headingSize, `@${width}: embed heading`);
      // `.embed__content` carries a bare 40rem literal today; #578 routes it through
      // --embed-body-measure and #577 gives the inverted ink a slot. Both diff against this.
      expect(got.content, `@${width}: embed content surface`).toEqual({
        maxWidth: MEASURE,
        color: INK,
      });

      await expectNoViewportOverflow(page, `embed baseline @${width}`);
    }
  });

  // Long content on BOTH the base and the inverted variant: `.embed--inverted
  // .embed__content` re-declares colour as a bare var(--color-bg) literal, which #577
  // routes through a new --embed-body-color. This records the pre-routing ink.
  test('#583 embed long content holds the measure on base and inverted at 1280 and 375 @smoke', async ({
    page,
  }) => {
    const LONG_BODY =
      '<p>' +
      'Long embedded body copy that has to be capped by the content measure rather than by the container. '.repeat(
        8,
      ) +
      '</p>';
    pageId = createPage('E2E 583 embed long content');
    setComposition(pageId, [
      { component: 'embed', props: { id: 'pp-emb-base', title: 'Base band', content: LONG_BODY } },
      {
        component: 'embed',
        props: { id: 'pp-emb-inv', theme: 'inverted', title: 'Inverted band', content: LONG_BODY },
      },
    ]);

    for (const width of [1280, 375]) {
      await open(page, width, '#pp-emb-inv');

      const got = await page.evaluate(() => {
        const cs = getComputedStyle;
        const base = document.querySelector('#pp-emb-base .embed__content') as HTMLElement;
        const inv = document.querySelector('#pp-emb-inv .embed__content') as HTMLElement;
        const container = base.closest('.container') as HTMLElement;
        const ccs = cs(container);
        return {
          baseWidth: base.clientWidth,
          invWidth: inv.clientWidth,
          baseColor: cs(base).color,
          invColor: cs(inv).color,
          invBandBg: cs(document.querySelector('#pp-emb-inv') as HTMLElement).backgroundColor,
          // Content box, not client box — .container's side padding is inside clientWidth.
          containerWidth:
            container.clientWidth - parseFloat(ccs.paddingLeft) - parseFloat(ccs.paddingRight),
          rootFont: parseFloat(cs(document.documentElement).fontSize),
        };
      });

      const cap = Math.round(got.rootFont * 40);
      const expected = Math.min(cap, got.containerWidth);
      // At 1280 the 40rem measure binds; at 375 the container is narrower and binds first.
      expect(got.baseWidth, `@${width}: base embed content width`).toBe(expected);
      expect(got.invWidth, `@${width}: inverted embed content width`).toBe(expected);
      expect(got.baseColor, `@${width}: base content ink`).toBe(INK);
      expect(got.invColor, `@${width}: inverted content ink (unslotted literal today)`).toBe(
        PAGE_BG,
      );
      expect(got.invBandBg, `@${width}: inverted band paint`).toBe(INVERTED_BG);

      await expectNoViewportOverflow(page, `embed long content @${width}`);
    }
  });

  // ── logos ────────────────────────────────────────────────────────────────────

  test('#583 logos baseline pins its band, heading, list, items and labels at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 logos baseline');
    setComposition(pageId, [{ component: 'logos', props: BASE_LOGOS_PROPS }]);

    for (const [width, bandPadding, headingSize] of BAND_VIEWPORTS) {
      await open(page, width, '.logos');
      await awaitLogoImages(page, 2);

      const got = await page.evaluate(() => {
        const cs = getComputedStyle;
        // Throws with the selector name rather than a bare null dereference, so a class
        // rename in a follow-up gate reports WHAT went missing.
        const q = (s: string) => {
          const el = document.querySelector(s) as HTMLElement;
          if (!el) throw new Error(`no element matched ${s}`);
          return el;
        };
        // `.logos__item--labeled` ALSO matches `.logos__item`, so the plain-item read
        // must exclude it explicitly — otherwise it silently retargets if a fixture
        // ever lists the labeled item first.
        const plainItem = '.logos__item:not(.logos__item--labeled)';
        return {
          band: { top: cs(q('.logos')).paddingTop, bottom: cs(q('.logos')).paddingBottom },
          heading: {
            fontSize: cs(q('.logos__heading')).fontSize,
            color: cs(q('.logos__heading')).color,
            marginBottom: cs(q('.logos__heading')).marginBottom,
            maxWidth: cs(q('.logos__heading')).maxWidth,
          },
          list: {
            display: cs(q('.logos__list')).display,
            flexWrap: cs(q('.logos__list')).flexWrap,
            alignItems: cs(q('.logos__list')).alignItems,
            justifyContent: cs(q('.logos__list')).justifyContent,
            gap: cs(q('.logos__list')).gap,
            listStyleType: cs(q('.logos__list')).listStyleType,
            padding: cs(q('.logos__list')).padding,
            margin: cs(q('.logos__list')).margin,
          },
          item: {
            display: cs(q(plainItem)).display,
            alignItems: cs(q(plainItem)).alignItems,
            justifyContent: cs(q(plainItem)).justifyContent,
            flexDirection: cs(q(plainItem)).flexDirection,
          },
          labeledItem: {
            flexDirection: cs(q('.logos__item--labeled')).flexDirection,
            gap: cs(q('.logos__item--labeled')).gap,
            minWidth: cs(q('.logos__item--labeled')).minWidth,
          },
          image: {
            maxHeight: cs(q(`${plainItem} .logos__image`)).maxHeight,
            objectFit: cs(q(`${plainItem} .logos__image`)).objectFit,
          },
          // Not a CSS literal: `.logos__image` declares `width: auto`, so this is the
          // TALL_PNG aspect ratio (60/180) applied to the 48px cap. It moves if the
          // fixture asset changes, which is not a CSS regression.
          fixtureDrivenImageWidth: cs(q(`${plainItem} .logos__image`)).width,
          labeledImageMaxHeight: cs(q('.logos__item--labeled .logos__image')).maxHeight,
          label: {
            fontSize: cs(q('.logos__label')).fontSize,
            color: cs(q('.logos__label')).color,
            textAlign: cs(q('.logos__label')).textAlign,
            opacity: cs(q('.logos__label')).opacity,
          },
        };
      });

      expect(got.band, `@${width}: logos band edges`).toEqual({
        top: bandPadding,
        bottom: bandPadding,
      });
      expectSharedHeading(got.heading, headingSize, `@${width}: logos heading`);
      // gap is the var(--space-lg) literal #584 is about to route through --logos-gap.
      expect(got.list, `@${width}: logos list`).toEqual({
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        justifyContent: 'center',
        gap: LIST_GAP,
        listStyleType: 'none',
        padding: '0px',
        margin: '0px',
      });
      // flexDirection 'row' is the CSS initial value, not a declaration — it is pinned
      // so that `--labeled`'s `column` cannot start leaking onto plain items.
      expect(got.item, `@${width}: unlabeled item box`).toEqual({
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexDirection: 'row',
      });
      expect(got.labeledItem, `@${width}: labeled item box`).toEqual({
        flexDirection: 'column',
        gap: '8px',
        minWidth: '96px',
      });
      // The two max-height literals #584 is about to route through --logos-image-size.
      expect(got.image, `@${width}: unlabeled image`).toEqual({
        maxHeight: `${LOGO_CAP_PX}px`,
        objectFit: 'contain',
      });
      expect(got.fixtureDrivenImageWidth, `@${width}: image keeps its aspect ratio`).toBe('16px');
      expect(got.labeledImageMaxHeight, `@${width}: labeled image cap`).toBe(
        `${LABELED_LOGO_CAP_PX}px`,
      );
      // The label has NO colour or size slot: both are bare literals. opacity 1 is the
      // initial value, pinned so `--inverted`'s 0.75 fade cannot leak onto light bands.
      expect(got.label, `@${width}: logos label`).toEqual({
        fontSize: '13px',
        color: MUTED_INK,
        textAlign: 'center',
        opacity: '1',
      });

      await expectNoViewportOverflow(page, `logos baseline @${width}`);
    }
  });

  /**
   * The label-driven image-height switch, rendered — the specific undocumented behaviour
   * #581 discloses and #584 gives a slot.
   *
   *   items[].label present  ->  li.logos__item.logos__item--labeled  ->  max-height 2.5rem
   *   items[].label absent   ->  li.logos__item                       ->  max-height 3rem
   *
   * A MIXED strip is the fixture because that is the state an author actually lands in:
   * two different logo heights in one row with nothing in the schema to explain why.
   *
   * The fourth seed item is LABEL-ONLY (no image_url). The `if ($image_url)` guard in
   * components/logos/logos.php renders an item only when image_url is non-empty, so it
   * disappears with no warning. That shape is NOT authorable through the action surface
   * as of #579, which makes nested `required` enforcement produce a named error for it —
   * this pin is on the RENDER path and is expected to survive #579; only the write path
   * changes there.
   */
  test('#583 logos mixed labeled/unlabeled strip pins both caps, the gap and the label-only drop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 logos mixed strip');
    setComposition(pageId, [
      {
        component: 'logos',
        props: {
          ...BASE_LOGOS_PROPS,
          title: 'Mixed strip',
          items: [
            { image_url: TALL_PNG, image_alt: 'Unlabeled one' },
            {
              image_url: TALL_PNG,
              image_alt: 'Labeled one',
              label: 'Continuous deployment and release orchestration',
            },
            { image_url: TALL_PNG, image_alt: 'Unlabeled two' },
            // RAW-META ONLY: label with no image_url. Silently drops (see docblock).
            { label: 'Label with no image at all' },
          ],
        },
      },
    ]);

    for (const width of [1280, 375]) {
      await open(page, width, '.logos__list');
      await awaitLogoImages(page, 3);

      const got = await page.evaluate(() => {
        const items = Array.from(document.querySelectorAll('.logos__item')) as HTMLElement[];
        const imgOf = (el: HTMLElement) => el.querySelector('.logos__image') as HTMLImageElement;
        return {
          itemCount: items.length,
          labeledCount: items.filter((i) => i.classList.contains('logos__item--labeled')).length,
          labelCount: document.querySelectorAll('.logos__label').length,
          gap: getComputedStyle(document.querySelector('.logos__list') as HTMLElement).gap,
          heights: items.map((i) => Math.round(imgOf(i).getBoundingClientRect().height)),
          naturals: items.map((i) => imgOf(i).naturalHeight),
          labeledFlags: items.map((i) => i.classList.contains('logos__item--labeled')),
        };
      });

      // Four items seeded, three rendered: the label-only entry vanished.
      expect(got.itemCount, `@${width}: label-only item is silently dropped (4 seeded)`).toBe(3);
      expect(got.labeledCount, `@${width}: exactly one item carries the labeled modifier`).toBe(1);
      expect(got.labelCount, `@${width}: only the item with an image renders its label`).toBe(1);
      // Guard: the asset must be taller than both caps, or the height pins prove nothing.
      for (const n of got.naturals) {
        expect(n, `@${width}: fixture asset must exceed both caps`).toBeGreaterThan(LOGO_CAP_PX);
      }
      // The switch itself: 48px unlabeled, 40px labeled, in the SAME strip. Paired with
      // labeledFlags so a fixture reorder fails loudly instead of silently passing.
      expect(got.heights, `@${width}: mixed strip renders two different logo heights`).toEqual([
        LOGO_CAP_PX,
        LABELED_LOGO_CAP_PX,
        LOGO_CAP_PX,
      ]);
      expect(got.labeledFlags, `@${width}: the 40px one is the labeled one`).toEqual([
        false,
        true,
        false,
      ]);
      // The gap literal #584 is about to route through --logos-gap.
      expect(got.gap, `@${width}: logos list gap`).toBe(LIST_GAP);

      await expectNoViewportOverflow(page, `logos mixed strip @${width}`);
    }
  });

  // ── contrast, as it renders today ────────────────────────────────────────────

  /**
   * The three figures the deferred band-background gate (#590) treats as entry evidence,
   * measured against the SHIPPED defaults rather than the branded dev install the #570
   * corpus used. Corpus vs here: cells 18.2 -> 17.44, caption/label 3.09 -> 5.66. The
   * gap is entirely token choice: --color-muted #5e6677 on --color-bg #fcfdff is 5.66:1,
   * which clears AA; the corpus install's muted token did not.
   */
  test('#583 default-token contrast: table cells, caption and logos label as rendered @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 contrast defaults');
    setComposition(pageId, [
      { component: 'table', props: BASE_TABLE_PROPS },
      {
        component: 'logos',
        props: { ...BASE_LOGOS_PROPS, items: [BASE_LOGOS_PROPS.items[1]] },
      },
    ]);

    await open(page, 1280, '.table__cell');

    const cell = await measureContrast(page, '.table__cell');
    const caption = await measureContrast(page, '.table__caption');
    const label = await measureContrast(page, '.logos__label');
    const tableHeading = await measureContrast(page, '.table-section__heading');
    const logosHeading = await measureContrast(page, '.logos__heading');

    // Cells sit on the table's own light island, so they are the safe part of the band.
    expect(cell, `table cell ink ${cell.toFixed(2)}:1`).toBeCloseTo(INK_ON_BG, 1);
    // Unslotted muted ink, at 14px (caption) and 13px (label).
    expect(caption, `table caption ink ${caption.toFixed(2)}:1`).toBeCloseTo(MUTED_ON_BG, 1);
    expect(label, `logos label ink ${label.toFixed(2)}:1`).toBeCloseTo(MUTED_ON_BG, 1);
    // Both band headings are --color-text on the default page background.
    expect(tableHeading, `table heading ink ${tableHeading.toFixed(2)}:1`).toBeCloseTo(INK_ON_BG, 1);
    expect(logosHeading, `logos heading ink ${logosHeading.toFixed(2)}:1`).toBeCloseTo(INK_ON_BG, 1);
  });

  /**
   * The one dark paint these components can actually reach today: theme "inverted".
   * table has no theme prop and no .table-section--* rule at all, so it cannot.
   *
   * Unlike the simulated dark paint below, this is shipped behaviour and therefore a real
   * regression surface. Note the label's ratio is PRE-OPACITY: `.logos--inverted
   * .logos__label` fades to 0.75, which measureContrast cannot see, so the opacity is
   * asserted on its own rather than folded into a misleadingly high ratio.
   */
  test('#583 inverted logos and embed bands: ink as rendered today @smoke', async ({ page }) => {
    pageId = createPage('E2E 583 inverted ink');
    setComposition(pageId, [
      {
        component: 'logos',
        props: {
          ...BASE_LOGOS_PROPS,
          id: 'pp-log-inv',
          theme: 'inverted',
          title: 'Inverted logos band',
          items: [BASE_LOGOS_PROPS.items[1]],
        },
      },
      {
        component: 'embed',
        props: {
          id: 'pp-emb-inv',
          theme: 'inverted',
          title: 'Inverted embed band',
          content: '<p>Inverted body copy with an <a href="/x">inline link</a>.</p>',
        },
      },
    ]);

    await open(page, 1280, '#pp-emb-inv');

    const logosHeading = await measureContrast(page, '#pp-log-inv .logos__heading');
    const logosLabel = await measureContrast(page, '#pp-log-inv .logos__label');
    const embedHeading = await measureContrast(page, '#pp-emb-inv .embed__heading');
    const embedBody = await measureContrast(page, '#pp-emb-inv .embed__content');
    const embedLink = await measureContrast(page, '#pp-emb-inv .embed__content a');

    // Both bands re-route their heading to var(--color-bg) on the inverted paint, so the
    // 1.02:1 failure the #570 corpus reports does NOT occur on the shipped dark variant.
    expect(logosHeading, `inverted logos heading ${logosHeading.toFixed(2)}:1`).toBeCloseTo(
      INK_ON_INVERTED,
      1,
    );
    expect(embedHeading, `inverted embed heading ${embedHeading.toFixed(2)}:1`).toBeCloseTo(
      INK_ON_INVERTED,
      1,
    );
    expect(embedBody, `inverted embed body ${embedBody.toFixed(2)}:1`).toBeCloseTo(
      INK_ON_INVERTED,
      1,
    );
    // Dark-band body link routed to the on-inverted accent role (#437).
    expect(embedLink, `inverted embed link ${embedLink.toFixed(2)}:1`).toBeCloseTo(
      ACCENT_LINK_ON_INVERTED,
      1,
    );
    expect(logosLabel, `inverted logos label, PRE-opacity ${logosLabel.toFixed(2)}:1`).toBeCloseTo(
      INK_ON_INVERTED,
      1,
    );
    const labelOpacity = await page
      .locator('#pp-log-inv .logos__label')
      .evaluate((el: Element) => getComputedStyle(el).opacity);
    expect(labelOpacity, 'the inverted label is faded, so its effective ratio is lower').toBe(
      '0.75',
    );
  });

  /**
   * SIMULATION, not shipped behaviour — deliberately NOT @smoke.
   *
   * There is no --table-bg or --logos-bg slot, and `theme: "inverted"` re-routes both
   * headings (proved above), so the "heading default dies on a dark paint" failure the
   * #570 corpus records has no product path today. The corpus produced it by injecting
   * `main .logos { background: #101418 }` client-side; this reproduces that injection
   * exactly so the figure is generated rather than cited. It is entry evidence for the
   * deferred band-background gate (#590), not a regression pin — if it ever starts
   * failing, that means a band-background capability shipped and #590's prerequisites
   * apply.
   *
   * Corpus: 1.02:1. Here, with shipped tokens: 1.04:1.
   */
  test('#583 simulated dark band paint kills the heading default (entry evidence for #590)', async ({
    page,
  }) => {
    pageId = createPage('E2E 583 dark paint simulation');
    setComposition(pageId, [
      { component: 'table', props: BASE_TABLE_PROPS },
      {
        component: 'logos',
        props: { ...BASE_LOGOS_PROPS, items: [BASE_LOGOS_PROPS.items[1]] },
      },
    ]);

    await open(page, 1280, '.logos__heading');

    await page.addStyleTag({
      content: 'main .logos { background: #101418; } main .table-section { background: #101418; }',
    });

    const tableHeading = await measureContrast(page, '.table-section__heading');
    const logosHeading = await measureContrast(page, '.logos__heading');
    const logosLabel = await measureContrast(page, '.logos__label');
    const tableCell = await measureContrast(page, '.table__cell');

    expect(tableHeading, `table heading on a dark paint ${tableHeading.toFixed(2)}:1`).toBeCloseTo(
      1.04,
      1,
    );
    expect(logosHeading, `logos heading on a dark paint ${logosHeading.toFixed(2)}:1`).toBeCloseTo(
      1.04,
      1,
    );
    expect(logosLabel, `logos label on a dark paint ${logosLabel.toFixed(2)}:1`).toBeCloseTo(
      3.21,
      1,
    );
    // The correction the #570 screenshot evidence made: the CELLS stay safe, because
    // `.table` paints its own light island regardless of the band behind it.
    expect(
      tableCell,
      `table cell is unaffected by the band paint ${tableCell.toFixed(2)}:1`,
    ).toBeCloseTo(INK_ON_BG, 1);
  });
});

/**
 * issue 577 — dead and defeated style slots actually render.
 *
 * Ten entries in one class: a declared slot an author can write, that reports success,
 * and that renders NOTHING — or a literal that defeats a slot or a token that already
 * exists. Every cascade claim behind that issue was a STATIC trace confirmed by no
 * browser, which is exactly what these pins fix: `StyleSlotContractTest` proves the CSS
 * CONSUMES a slot; only a rendered box proves the slot WINS once media queries,
 * specificity and source order have all had their say.
 *
 * Eight of the gate's eleven register rows land here, and they are asserted as register
 * rows — a deliberate before/after value, not "some change happened":
 *
 *   row 1  A-1  (slot)     adjacent hero obeys --hero-padding-top
 *   row 2  A-2             section theme bg + borders route their slots
 *   row 3  A-3             bg-image cta borders route their slots
 *   row 4  A-4             inverted title_accent takes --color-accent-on-inverted
 *   row 5  A-14            inverted-stack testimonials meta colour
 *   rows 6+7  A-36         the two MEASURED overlay-band contrast corrections
 *   row 9  A-1  (fallback) the UNSET adjacent hero keeps hero's own opener rhythm
 *
 * Everything else the issue touches is byte-identical unset and is pinned that way.
 *
 * Authoring path: slot values are written through `styleComponent()` — the real
 * `style_component` action over admin-ajax, with the CAS baseline the chat UI uses —
 * not raw `_pp_composition` meta. A slot the write path would reject can therefore
 * never pass these tests (Section 14.1).
 */
test.describe('#577 dead and defeated style slots render', () => {
  let pageId: number;

  // Rendered serializations of the shipped tokens these pins compare against.
  const INK = 'rgb(16, 24, 40)'; //             --color-text               #101828
  const PAGE_BG = 'rgb(252, 253, 255)'; //      --color-bg                 #fcfdff
  const SURFACE = 'rgb(244, 247, 251)'; //      --color-surface            #f4f7fb
  const BORDER = 'rgb(217, 224, 235)'; //       --color-border             #d9e0eb
  const INVERTED_BG = 'rgb(15, 23, 42)'; //     --color-bg-inverted        #0f172a
  const ACCENT = 'rgb(49, 87, 244)'; //         --color-accent             #3157f4
  const ACCENT_ON_INVERTED = 'rgb(157, 175, 238)'; // --color-accent-on-inverted #9dafee
  const MUTED_ON_OVERLAY = 'rgb(250, 251, 255)'; //   --color-muted-on-overlay   #fafbff
  const MUTED_INK = 'rgb(94, 102, 119)'; //     --color-muted              #5e6677
  const OVERLAY_BG = 'rgba(0, 0, 0, 0.55)'; //  --overlay-bg

  // Hero's OWN opener rhythm — the whole point of row 9's exception.
  const HERO_OPENER_DESKTOP = '112px'; //       --space-2xl  7rem
  const HERO_OPENER_MOBILE = '64px'; //         --space-xl   4rem
  // .hero--left's own rhythm is --space-xl on BOTH edges at BOTH breakpoints, so the
  // left/split adjacent edge lands here at 1280 as well as at 375.
  const HERO_OPENER_COMPACT = '64px'; //        --space-xl   4rem
  // What an unset adjacent hero used to get from the generic catch-all, and must not
  // get any more. Desktop is the fluid clamp(4.25rem, 6vw, 5rem) evaluated at 1280px
  // (6vw = 76.8px, inside the clamp), mobile is the flat 3.35rem override.
  const OLD_BAND_TIER_DESKTOP = '76.8px';
  const OLD_BAND_TIER_MOBILE = '53.6px';

  // A 2x2 white PNG. The overlay-over-pure-white composite (effective rgb(115,115,115))
  // is the documented worst case for every bg-image band — see --color-accent-on-overlay
  // in base.css. Reused from the #461 block for exactly that reason.
  const WHITE_PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWP8//8/AwMDEwMDAwMDAwAkBgMBmjCi+wAAAABJRU5ErkJggg==';

  // A value no token resolves to, so a dead-slot no-op is unmistakable.
  const LOUD_PX = '5px';
  const LOUD_COLOR = 'rgb(0, 229, 255)'; // #00e5ff, vivid cyan
  const LOUD_HEX = '#00e5ff';

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

  async function computed(page: any, selector: string, props: string[]) {
    return page.locator(selector).first().evaluate((el: Element, ps: string[]) => {
      const cs = getComputedStyle(el);
      const out: Record<string, string> = {};
      ps.forEach((p) => {
        out[p] = cs.getPropertyValue(p);
      });
      return out;
    }, props);
  }

  // ── A-1 / register rows 1 and 9 — the hero adjacent-top edge ───────────────
  //
  // `.hero` is [0,1,0]; the generic adjacent catch-all
  // `main > [data-pp-component] + [data-pp-component]` is [0,2,1]. So on the adjacent
  // edge the catch-all won at BOTH breakpoints and hero's declared --hero-padding-top
  // was dead there — while the CSS comment above the catch-all claimed hero had no
  // padding slot at all. Two register rows fall out of the one fix.

  test('#577 row 1: an authored --hero-padding-top wins on an ADJACENT hero at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 hero adjacent slot');
    // A section leads, so the hero renders SECOND — the adjacent position, the only
    // place this slot was dead.
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: '<p>Lead band.</p>' } },
      { component: 'hero', props: { id: 'pp-hero-adj', title: 'Adjacent hero' } },
    ]);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    // component_index 1 = the hero (index 0 is the leading section).
    const res = await styleComponent(page, pageId, { '--hero-padding-top': LOUD_PX }, undefined, 1);
    expect(res.success).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const hero = page.locator('#pp-hero-adj');
      await expect(hero).toBeVisible({ timeout: 10000 });
      const { 'padding-top': top } = await computed(page, '#pp-hero-adj', ['padding-top']);
      expect(top, `adjacent hero --hero-padding-top @${width}`).toBe(LOUD_PX);
    }
  });

  test('#577 row 9: an UNSET adjacent hero keeps hero\'s own opener rhythm, not the band tier @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 hero adjacent fallback');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: '<p>Lead band.</p>' } },
      { component: 'hero', props: { id: 'pp-hero-adj', title: 'Adjacent hero' } },
      // A THIRD band proves the leading hero case is untouched by re-rendering the
      // same page with a hero in first position below.
    ]);

    for (const [width, expected, oldValue] of [
      [1280, HERO_OPENER_DESKTOP, OLD_BAND_TIER_DESKTOP],
      [375, HERO_OPENER_MOBILE, OLD_BAND_TIER_MOBILE],
    ] as [number, string, string][]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      const hero = page.locator('#pp-hero-adj');
      await expect(hero).toBeVisible({ timeout: 10000 });
      const { 'padding-top': top, 'padding-bottom': bottom } = await computed(page, '#pp-hero-adj', [
        'padding-top',
        'padding-bottom',
      ]);
      // The registered change: hero's own opener rhythm, NOT the shared band tier.
      expect(top, `unset adjacent hero top @${width}`).toBe(expected);
      expect(top, `unset adjacent hero must not sit on the old band tier @${width}`).not.toBe(oldValue);
      // And because the bottom edge always came from .hero's own rule, the adjacent
      // hero is now SYMMETRIC — the visible shape of the fix.
      expect(bottom, `unset adjacent hero bottom @${width}`).toBe(expected);
    }
  });

  // Row 9 applies PER VARIANT, not as one flat value. OQ-1 (ii)'s principle is that hero
  // keeps ITS OWN opener rhythm on the adjacent edge, and .hero--left's own rhythm is
  // --space-xl on BOTH edges ("inner pages; compact vertical rhythm") — not --space-2xl.
  // A single flat fallback measured 112px top against a 64px bottom at 1280 on every
  // inner-page hero. The left twin restores symmetry at both breakpoints.
  //
  // An image-less `split` hero degrades to .hero--left (issue 440), so it is covered by
  // the same rule and is asserted here rather than assumed.
  test('#577 row 9: an adjacent LEFT/SPLIT hero keeps the compact opener rhythm, symmetric @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 hero left adjacent fallback');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-lead', title: 'Lead', body: '<p>Lead band.</p>' } },
      { component: 'hero', props: { id: 'pp-hero-left', layout: 'left', title: 'Left hero' } },
      { component: 'hero', props: { id: 'pp-hero-split', layout: 'split', title: 'Split hero' } },
    ]);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-hero-left')).toBeVisible({ timeout: 10000 });

      for (const id of ['pp-hero-left', 'pp-hero-split']) {
        const cls = await page.locator(`#${id}`).getAttribute('class');
        expect(cls, `${id} @${width} must carry the left variant class`).toContain('hero--left');

        const { 'padding-top': top, 'padding-bottom': bottom } = await computed(page, `#${id}`, [
          'padding-top',
          'padding-bottom',
        ]);
        // Compact opener rhythm at BOTH breakpoints, and symmetric with its own bottom.
        expect(top, `adjacent ${id} top @${width}`).toBe(HERO_OPENER_COMPACT);
        expect(bottom, `adjacent ${id} bottom @${width}`).toBe(HERO_OPENER_COMPACT);
        // Not the centered hero's rhythm, and not the old shared band tier.
        expect(top, `adjacent ${id} must not take the centered opener @${width}`).not.toBe(HERO_OPENER_DESKTOP);
      }
    }
  });

  test('#577 A-1: a LEADING hero is untouched at 1280 and 375', async ({ page }) => {
    pageId = createPage('E2E 577 hero leading unchanged');
    setComposition(pageId, [
      { component: 'hero', props: { id: 'pp-hero-lead', title: 'Leading hero' } },
      { component: 'section', props: { id: 'pp-sec02', title: 'After', body: '<p>After.</p>' } },
    ]);

    for (const [width, expected] of [
      [1280, HERO_OPENER_DESKTOP],
      [375, HERO_OPENER_MOBILE],
    ] as [number, string][]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-hero-lead')).toBeVisible({ timeout: 10000 });
      const { 'padding-top': top, 'padding-bottom': bottom } = await computed(page, '#pp-hero-lead', [
        'padding-top',
        'padding-bottom',
      ]);
      expect(top, `leading hero top @${width}`).toBe(expected);
      expect(bottom, `leading hero bottom @${width}`).toBe(expected);
    }
  });

  // ── A-2 / register row 2 — section theme bg + borders ──────────────────────
  //
  // `.pp-section--dark` and `.pp-section--inverted` set background-color and border-*
  // as BARE LITERALS at [0,1,0], AFTER `.section`'s slot-routed declarations at equal
  // specificity — so --section-bg / --section-border-* were dead on any themed section,
  // while tests/AiContextTest.php already promised an author the override wins.

  for (const [theme, themeBg] of [
    ['muted', SURFACE],
    ['inverted', INVERTED_BG],
  ] as [string, string][]) {
    test(`#577 row 2: --section-bg and the border slots win on a ${theme} section @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E 577 section ${theme} slots`);
      setComposition(pageId, [
        { component: 'section', props: { id: 'pp-sec-themed', theme, title: 'Themed', body: '<p>Body.</p>' } },
      ]);

      // UNSET first: the theme literal must still be exactly what it always was.
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-themed')).toBeVisible({ timeout: 10000 });
      const before = await computed(page, '#pp-sec-themed', [
        'background-color',
        'border-top-width',
        'border-top-color',
        'border-bottom-width',
        'border-bottom-color',
      ]);
      expect(before['background-color'], `${theme} unset background`).toBe(themeBg);
      // Unset borders too, per theme. muted (.pp-section--dark) frames the band with
      // 1px --color-border; inverted declares no border of its own, so it keeps
      // .section's own 0/transparent. Both must survive the routing untouched.
      const expectedBorder = theme === 'muted'
        ? { width: '1px', color: BORDER }
        : { width: '0px', color: 'rgba(0, 0, 0, 0)' };
      expect(before['border-top-width'], `${theme} unset border-top-width`).toBe(expectedBorder.width);
      expect(before['border-bottom-width'], `${theme} unset border-bottom-width`).toBe(expectedBorder.width);
      expect(before['border-top-color'], `${theme} unset border-top-color`).toBe(expectedBorder.color);
      expect(before['border-bottom-color'], `${theme} unset border-bottom-color`).toBe(expectedBorder.color);

      // Now author all three slots through the real write path.
      await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
      await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
      const res = await styleComponent(page, pageId, {
        '--section-bg': LOUD_HEX,
        '--section-border-width': LOUD_PX,
        '--section-border-color': LOUD_HEX,
      });
      expect(res.success).toBe(true);

      for (const width of [1280, 375]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/?page_id=${pageId}`);
        await expect(page.locator('#pp-sec-themed')).toBeVisible({ timeout: 10000 });
        const after = await computed(page, '#pp-sec-themed', [
          'background-color',
          'border-top-width',
          'border-top-color',
          'border-bottom-width',
          'border-bottom-color',
        ]);
        expect(after['background-color'], `${theme} --section-bg @${width}`).toBe(LOUD_COLOR);
        expect(after['border-top-width'], `${theme} --section-border-width top @${width}`).toBe(LOUD_PX);
        expect(after['border-bottom-width'], `${theme} --section-border-width bottom @${width}`).toBe(LOUD_PX);
        expect(after['border-top-color'], `${theme} --section-border-color top @${width}`).toBe(LOUD_COLOR);
        expect(after['border-bottom-color'], `${theme} --section-border-color bottom @${width}`).toBe(LOUD_COLOR);
      }
    });
  }

  test('#577 A-2: an unset MUTED section still paints the surface literal and its 1px framing borders', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 section muted byte-identical');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-muted', theme: 'muted', title: 'Muted', body: '<p>Body.</p>' } },
    ]);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-sec-muted')).toBeVisible({ timeout: 10000 });
    const cs = await computed(page, '#pp-sec-muted', [
      'background-color',
      'border-top-width',
      'border-top-color',
      'border-bottom-width',
      'border-bottom-color',
    ]);
    expect(cs['background-color']).toBe(SURFACE);
    expect(cs['border-top-width']).toBe('1px');
    expect(cs['border-bottom-width']).toBe('1px');
    expect(cs['border-top-color']).toBe(BORDER);
    expect(cs['border-bottom-color']).toBe(BORDER);
  });

  // ── A-3 / register row 3 — bg-image cta borders ────────────────────────────
  //
  // `.cta--has-bg-image { border: none }` was a SHORTHAND whose border-style:none
  // suppressed the slot-routed longhands `.cta` declares, killing --cta-border-width
  // and --cta-border-color on every background-image band.

  test('#577 row 3: the cta border slots win on a background-image cta @smoke', async ({ page }) => {
    pageId = createPage('E2E 577 cta bg-image borders');
    setComposition(pageId, [
      {
        component: 'cta',
        props: {
          id: 'pp-cta-img',
          background_image: WHITE_PNG,
          title: 'Overlay cta',
          body: 'Body copy.',
          button_text: 'Go',
          button_url: '/go',
        },
      },
    ]);

    // UNSET: `border: none` painted nothing, and the replacement longhands must too.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-cta-img')).toBeVisible({ timeout: 10000 });
    const before = await computed(page, '#pp-cta-img', [
      'border-top-width',
      'border-bottom-width',
      'border-left-width',
      'border-right-width',
    ]);
    expect(before['border-top-width'], 'unset bg-image cta top border').toBe('0px');
    expect(before['border-bottom-width'], 'unset bg-image cta bottom border').toBe('0px');
    // The shorthand also zeroed the two sides `.cta` never declares — the longhand
    // replacement must leave them at 0 too, or the band grows edges it never had.
    expect(before['border-left-width'], 'unset bg-image cta left border').toBe('0px');
    expect(before['border-right-width'], 'unset bg-image cta right border').toBe('0px');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, {
      '--cta-border-width': LOUD_PX,
      '--cta-border-color': LOUD_HEX,
    });
    expect(res.success).toBe(true);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-cta-img')).toBeVisible({ timeout: 10000 });
      const after = await computed(page, '#pp-cta-img', [
        'border-top-width',
        'border-top-color',
        'border-bottom-width',
        'border-bottom-color',
        'border-left-width',
        'border-right-width',
      ]);
      expect(after['border-top-width'], `bg-image cta --cta-border-width @${width}`).toBe(LOUD_PX);
      expect(after['border-bottom-width'], `bg-image cta --cta-border-width @${width}`).toBe(LOUD_PX);
      expect(after['border-top-color'], `bg-image cta --cta-border-color @${width}`).toBe(LOUD_COLOR);
      expect(after['border-bottom-color'], `bg-image cta --cta-border-color @${width}`).toBe(LOUD_COLOR);
      // The whole hazard of swapping a four-side shorthand for two longhands: an
      // authored width must not grow edges the band never had.
      expect(after['border-left-width'], `authored bg-image cta must not grow a left border @${width}`).toBe('0px');
      expect(after['border-right-width'], `authored bg-image cta must not grow a right border @${width}`).toBe('0px');
    }
  });

  // ── A-4 / register row 4 — inverted title_accent ───────────────────────────
  //
  // The accent substring paints its OWN color and does not inherit the light title
  // beside it. The --has-bg-image twins have routed the overlay role since #463; the
  // INVERTED twins were never written, so the highlighted word rendered bare
  // --color-accent at 3.23:1 on the dark band.

  test('#577 row 4: an inverted section/cta title_accent takes --color-accent-on-inverted @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 inverted title accent');
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec-inv', theme: 'inverted', title: 'Ship faster today', title_accent: 'faster', body: '<p>Body.</p>' },
      },
      {
        component: 'cta',
        props: {
          id: 'pp-cta-inv',
          theme: 'inverted',
          title: 'Start now today',
          title_accent: 'now',
          button_text: 'Go',
          button_url: '/go',
        },
      },
    ]);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-sec-inv .section__title-accent')).toBeVisible({ timeout: 10000 });

      const sec = await computed(page, '#pp-sec-inv .section__title-accent', ['color']);
      const cta = await computed(page, '#pp-cta-inv .cta__title-accent', ['color']);
      expect(sec.color, `inverted section title_accent @${width}`).toBe(ACCENT_ON_INVERTED);
      expect(cta.color, `inverted cta title_accent @${width}`).toBe(ACCENT_ON_INVERTED);
      // The defect this replaces: the bare light-surface accent on a dark band.
      expect(sec.color).not.toBe(ACCENT);
      expect(cta.color).not.toBe(ACCENT);
    }
  });

  test('#577 A-4: a per-instance heading-accent slot still wins on an inverted band', async ({ page }) => {
    pageId = createPage('E2E 577 inverted title accent slot wins');
    setComposition(pageId, [
      {
        component: 'section',
        props: { id: 'pp-sec-inv', theme: 'inverted', title: 'Ship faster today', title_accent: 'faster', body: '<p>Body.</p>' },
      },
    ]);
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--section-heading-accent-color': LOUD_HEX });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const cs = await computed(page, '#pp-sec-inv .section__title-accent', ['color']);
    expect(cs.color).toBe(LOUD_COLOR);
  });

  test('#577 A-4: a PLAIN (non-inverted) band still renders the bare accent', async ({ page }) => {
    pageId = createPage('E2E 577 plain title accent unchanged');
    setComposition(pageId, [
      { component: 'section', props: { id: 'pp-sec-plain', title: 'Ship faster today', title_accent: 'faster', body: '<p>Body.</p>' } },
    ]);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const cs = await computed(page, '#pp-sec-plain .section__title-accent', ['color']);
    expect(cs.color, 'plain band title_accent must be byte-identical').toBe(ACCENT);
  });

  // ── A-14 / register row 5 — inverted-stack testimonials meta ───────────────
  //
  // --testimonials-quote-color and --testimonials-author-color each get an
  // inverted+stack rule supplying a light default. --testimonials-meta-color got none,
  // so the role/company line resolved to --color-muted on a dark band.

  test('#577 row 5: the inverted-STACK testimonials meta line takes a light default @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 testimonials inverted stack meta');
    setComposition(pageId, [
      {
        component: 'testimonials',
        props: {
          id: 'pp-tst-inv',
          theme: 'inverted',
          layout: 'stack',
          items: [{ quote: 'It works.', author: 'Ada Lovelace', role: 'Head of Engineering', company: 'Analytical Ltd' }],
        },
      },
    ]);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);
      await expect(page.locator('#pp-tst-inv .testimonials__meta')).toBeVisible({ timeout: 10000 });
      const cs = await computed(page, '#pp-tst-inv .testimonials__meta', ['color']);
      expect(cs.color, `inverted stack meta @${width}`).toBe(PAGE_BG);
      expect(cs.color, 'must no longer resolve to the light-surface muted ink').not.toBe(MUTED_INK);
    }
  });

  test('#577 A-14: the GRID layout keeps its light card, so meta stays muted', async ({ page }) => {
    pageId = createPage('E2E 577 testimonials inverted grid meta unchanged');
    setComposition(pageId, [
      {
        component: 'testimonials',
        props: {
          id: 'pp-tst-grid',
          theme: 'inverted',
          layout: 'grid',
          items: [{ quote: 'It works.', author: 'Ada Lovelace', role: 'Head of Engineering', company: 'Analytical Ltd' }],
        },
      },
    ]);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const cs = await computed(page, '#pp-tst-grid .testimonials__meta', ['color']);
    // The grid variant's item is a LIGHT card, so the muted ink is correct there and
    // the new rule (scoped to .testimonials--stack) must not reach it.
    expect(cs.color, 'inverted GRID meta must be byte-identical').toBe(MUTED_INK);
  });

  // ── A-36 / register rows 6 and 7 — the two MEASURED contrast corrections ───
  //
  // Both bands lay --overlay-bg over an arbitrary image. The worst case is the scrim
  // over a pure-WHITE image, an effective background of rgb(115,115,115), whose
  // contrast CEILING for ANY foreground is 4.74:1. `color: --color-bg` at
  // `opacity: 0.85` composited to rgb(231,232,234) and measured 3.87:1 — a FAIL against
  // the 4.5:1 normal-text bar. The remedy is a ROLE TOKEN, never a second literal.
  //
  // The measurement below composites BOTH stages the browser does: the overlay over
  // white, then the element's own `opacity` over that. So reintroducing an opacity
  // literal fails here even if the declared `color` is untouched — which is the exact
  // regression this pin exists to catch.

  const OVERLAY_SURFACES = [
    {
      name: 'cta body',
      row: 6,
      ink: '#pp-ov-cta .cta__body',
      overlay: '#pp-ov-cta .cta__overlay',
      slot: '--cta-body-color',
    },
    {
      name: 'stats label',
      row: 7,
      ink: '#pp-ov-stats .stats__label',
      overlay: '#pp-ov-stats .stats__overlay',
      slot: '--stats-label-color',
    },
  ];

  function overlayBands() {
    return [
      {
        component: 'cta',
        props: {
          id: 'pp-ov-cta',
          background_image: WHITE_PNG,
          title: 'Overlay cta',
          body: 'Body copy that has to stay readable over an arbitrary photograph.',
          button_text: 'Go',
          button_url: '/go',
        },
      },
      {
        component: 'stats',
        props: {
          id: 'pp-ov-stats',
          background_image: WHITE_PNG,
          title: 'Overlay stats',
          items: [{ number: '42', label: 'Deployments every single week' }],
        },
      },
    ];
  }

  /** Effective contrast of `inkSel` against `overlaySel` composited over pure white. */
  async function overlayContrast(page: any, inkSel: string, overlaySel: string) {
    return page.evaluate(
      ({ inkS, ovS }: { inkS: string; ovS: string }) => {
        const parseRgb = (str: string): number[] => (str.match(/[\d.]+/g) || []).map(Number);
        const lum = (rgb: number[]): number => {
          const f = (v: number) => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
          };
          return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
        };
        const el = document.querySelector(inkS) as HTMLElement | null;
        const ov = document.querySelector(ovS) as HTMLElement | null;
        if (!el || !ov) return { found: false, ratio: 0, alpha: -1, textOpacity: -1, fg: [] as number[], bg: [] as number[] };

        const o = parseRgb(getComputedStyle(ov).backgroundColor);
        const alpha = o.length >= 4 ? o[3] : 1;
        // Stage 1: the scrim over a pure-white image — the worst case.
        const bg = [0, 1, 2].map((i) => alpha * (o[i] ?? 0) + (1 - alpha) * 255);

        // Stage 2: the element's own opacity over that. `opacity: 1` is a no-op, which
        // is the shipped state after issue 577; any literal below 1 pulls the ink
        // toward the band and shows up directly in the ratio.
        const textOpacity = parseFloat(getComputedStyle(el).opacity);
        const declared = parseRgb(getComputedStyle(el).color);
        const fg = [0, 1, 2].map((i) => textOpacity * declared[i] + (1 - textOpacity) * bg[i]);

        const L1 = lum(fg);
        const L2 = lum(bg);
        return {
          found: true,
          alpha,
          textOpacity,
          fg,
          bg,
          ratio: (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05),
        };
      },
      { inkS: inkSel, ovS: overlaySel },
    );
  }

  test('#577 rows 6+7: both overlay-band de-emphasis surfaces clear AA at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 overlay de-emphasis contrast');
    setComposition(pageId, overlayBands());

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      for (const s of OVERLAY_SURFACES) {
        await expect(page.locator(s.ink)).toBeVisible({ timeout: 10000 });
        const res = await overlayContrast(page, s.ink, s.overlay);

        expect(res.found, `${s.name} or its overlay not found @${width}`).toBe(true);
        // Guard a vacuous pass: the overlay must still be a translucent scrim.
        expect(res.alpha, `${s.name} @${width}: overlay alpha ${res.alpha}`).toBeGreaterThan(0);
        expect(res.alpha).toBeLessThan(1);
        // The worst-case composite really is the documented rgb(115,115,115).
        res.bg.forEach((c: number) => expect(Math.round(c)).toBe(115));
        // No opacity literal survives on this surface.
        expect(res.textOpacity, `${s.name} @${width}: opacity literal is back`).toBe(1);
        // The role token, not a hand-picked literal.
        const ink = await computed(page, s.ink, ['color']);
        expect(ink.color, `${s.name} @${width} ink`).toBe(MUTED_ON_OVERLAY);
        // The register row: 3.87:1 -> >= 4.5:1.
        expect(
          res.ratio,
          `${s.name} @${width}: fg=${JSON.stringify(res.fg)} bg=${JSON.stringify(res.bg)} ratio=${res.ratio?.toFixed(2)} (need >= 4.5, was 3.87)`,
        ).toBeGreaterThanOrEqual(4.5);
        expect(res.ratio, `${s.name} @${width}: must be above the old failing 3.87:1`).toBeGreaterThan(3.87);
      }
    }
  });

  test('#577 A-36: the per-instance ink slot still wins on both overlay surfaces', async ({ page }) => {
    pageId = createPage('E2E 577 overlay ink slot wins');
    setComposition(pageId, overlayBands());

    // Through the REAL write path, not a raw __pp_style meta write — the block's
    // authoring-path claim has to hold for every test that makes one (Section 14.1).
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const resCta = await styleComponent(page, pageId, { '--cta-body-color': LOUD_HEX }, undefined, 0);
    expect(resCta.success).toBe(true);
    const resStats = await styleComponent(page, pageId, { '--stats-label-color': LOUD_HEX }, undefined, 1);
    expect(resStats.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    for (const s of OVERLAY_SURFACES) {
      const cs = await computed(page, s.ink, ['color', 'opacity']);
      expect(cs.color, `${s.name} slot override`).toBe(LOUD_COLOR);
      expect(cs.opacity, `${s.name} must carry no opacity literal`).toBe('1');
    }
  });

  // The band that carries BOTH dark classes. cta.php and stats.php emit the theme class
  // and the bg-image class INDEPENDENTLY, so `theme:"inverted"` + `background_image`
  // renders `.cta--inverted.cta--has-bg-image`. The inverted opacity literals are
  // ratified against the SOLID inverted band (12.76:1 / 10.22:1); on the overlay band
  // the same alpha is exactly the 3.87:1 failure rows 6+7 exist to correct. Without the
  // `:not(--has-bg-image)` carve-out the inverted rule's opacity survives here — and for
  // stats it is the EARLIER rule, so the label would render at 0.75, DIMMER than the
  // 0.85 that already failed. This pin is the reason that carve-out exists.
  test('#577 rows 6+7: an inverted + background_image band still clears AA at 1280 and 375 @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 inverted overlay combined band');
    const b = overlayBands() as any[];
    b[0].props.theme = 'inverted';
    b[1].props.theme = 'inverted';
    setComposition(pageId, b);

    for (const width of [1280, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`/?page_id=${pageId}`);

      // Both classes really are on the band (guards a vacuous pass if the renderer
      // ever stops combining them).
      for (const [sel, a, bb] of [
        ['#pp-ov-cta', 'cta--inverted', 'cta--has-bg-image'],
        ['#pp-ov-stats', 'stats--inverted', 'stats--has-bg-image'],
      ] as [string, string, string][]) {
        const cls = await page.locator(sel).getAttribute('class');
        expect(cls, `${sel} @${width} classes`).toContain(a);
        expect(cls).toContain(bb);
      }

      for (const s of OVERLAY_SURFACES) {
        const res = await overlayContrast(page, s.ink, s.overlay);
        expect(res.found, `${s.name} not found @${width}`).toBe(true);
        expect(res.textOpacity, `${s.name} @${width}: the inverted opacity literal leaked onto the overlay band`).toBe(1);
        expect(
          res.ratio,
          `${s.name} on a combined inverted+image band @${width}: ratio=${res.ratio?.toFixed(2)} (need >= 4.5)`,
        ).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  test('#577 A-36: the three PASSING opacity literals are untouched', async ({ page }) => {
    pageId = createPage('E2E 577 surviving opacity literals');
    setComposition(pageId, [
      {
        component: 'cta',
        props: { id: 'pp-cta-inv', theme: 'inverted', title: 'Inverted cta', body: 'Body copy.', button_text: 'Go', button_url: '/go' },
      },
      {
        component: 'stats',
        props: { id: 'pp-stats-inv', theme: 'inverted', title: 'Inverted stats', items: [{ number: '42', label: 'Metric' }] },
      },
      {
        component: 'logos',
        props: {
          id: 'pp-logos-inv',
          theme: 'inverted',
          title: 'Inverted logos',
          items: [{ image_url: 'https://example.com/l.png', image_alt: 'Logo', label: 'Acme' }],
        },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    // These three measured 12.76:1 / 10.22:1 / 10.22:1 against a 4.5:1 bar and were
    // ratified as deliberate de-emphasis. The correction is scoped to the two that
    // FAILED, so these must keep their literals exactly.
    const cta = await computed(page, '#pp-cta-inv .cta__body', ['opacity']);
    const stats = await computed(page, '#pp-stats-inv .stats__label', ['opacity']);
    const logos = await computed(page, '#pp-logos-inv .logos__label', ['opacity']);
    expect(cta.opacity, 'inverted cta body opacity').toBe('0.85');
    expect(stats.opacity, 'inverted stats label opacity').toBe('0.75');
    expect(logos.opacity, 'inverted logos label opacity').toBe('0.75');
  });

  // ── A-13 — stats gains the overlay slot the other three bands already had ──

  test('#577 A-13: --stats-overlay-bg drives the stats scrim; unset is byte-identical', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 stats overlay slot');
    setComposition(pageId, [
      {
        component: 'stats',
        props: { id: 'pp-ov-stats', background_image: WHITE_PNG, title: 'Overlay stats', items: [{ number: '42', label: 'Metric' }] },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-ov-stats .stats__overlay')).toBeVisible({ timeout: 10000 });
    const before = await computed(page, '#pp-ov-stats .stats__overlay', ['background-color']);
    expect(before['background-color'], 'unset stats overlay').toBe(OVERLAY_BG);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--stats-overlay-bg': 'rgba(0, 40, 90, 0.7)' });
    expect(res.success).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    const after = await computed(page, '#pp-ov-stats .stats__overlay', ['background-color']);
    expect(after['background-color'], 'authored stats overlay').toBe('rgba(0, 40, 90, 0.7)');
  });

  // ── A-7 — sever the grid-slot leak into faq ────────────────────────────────
  //
  // One rule capped BOTH components from a single selector reading the GRID card slot,
  // so faq consumed a grid slot on a faq element: it could neither set it (the write
  // path rejects a foreign slot) nor resolve it (inline slot properties land on the
  // owning component's root).

  test('#577 A-7: --faq-item-radius drives the faq item; --grid-item-radius no longer reaches it', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 faq item radius');
    setComposition(pageId, [
      { component: 'grid', props: { id: 'pp-grid01', title: 'Grid', items: [{ title: 'One', text: 'A' }] } },
      { component: 'faq', props: { id: 'pp-faq01', title: 'FAQ', items: [{ question: 'Q?', answer: 'A.' }] } },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-faq01 .faq__item')).toBeVisible({ timeout: 10000 });
    // Byte-identical unset: 4px on BOTH sides of the split.
    const beforeFaq = await computed(page, '#pp-faq01 .faq__item', ['border-top-left-radius']);
    const beforeGrid = await computed(page, '#pp-grid01 .grid__item', ['border-top-left-radius']);
    expect(beforeFaq['border-top-left-radius'], 'unset faq item radius').toBe('4px');
    expect(beforeGrid['border-top-left-radius'], 'unset grid card radius').toBe('4px');

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    // component_index 1 = the faq band.
    const res = await styleComponent(page, pageId, { '--faq-item-radius': '18px' }, undefined, 1);
    expect(res.success).toBe(true);
    // component_index 0 = the grid band; a DIFFERENT value proves the two are severed.
    const res2 = await styleComponent(page, pageId, { '--grid-item-radius': '2px' }, undefined, 0);
    expect(res2.success).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    const afterFaq = await computed(page, '#pp-faq01 .faq__item', ['border-top-left-radius']);
    const afterGrid = await computed(page, '#pp-grid01 .grid__item', ['border-top-left-radius']);
    expect(afterFaq['border-top-left-radius'], 'faq follows its OWN slot').toBe('18px');
    expect(afterGrid['border-top-left-radius'], 'grid follows its own slot').toBe('2px');
  });

  // ── A-8a — the ONE declaration that actually defeated --grid-item-padding ──
  //
  // The issue named three. Verified against the cascade, only the featured-card rule
  // below was a genuine defeat:
  //   .grid__item-body:first-child      — [0,2,0], and `main > .grid .grid__item-body
  //                                       :first-child` [0,3,1] already routed the slot
  //                                       at both breakpoints, so it is unreachable
  //                                       inside <main>. It IS routed, but only because
  //                                       the slot-contract guard requires uniform
  //                                       routing per subject — not because it defeated
  //                                       anything. Byte-identical, so no pin here.
  //   .grid--steps .grid__item          — grid.php renders .grid__item-body for steps
  //                                       cards too, and that body already routed the
  //                                       slot. Left alone: routing the outer box
  //                                       through the SAME slot would double-inset an
  //                                       authored card and desync the connector.
  // See both comments in components.css.

  test('#577 A-8a: --grid-item-padding wins on the FEATURED first card body at >=1024', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 grid featured padding');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-grid-feat',
          title: 'Cards',
          items: [
            { title: 'One', text: 'A' },
            { title: 'Two', text: 'B' },
          ],
        },
      },
    ]);

    // 1280 is the breakpoint where the featured override lives.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-grid-feat .grid__item').first()).toBeVisible({ timeout: 10000 });
    const before = await computed(page, '#pp-grid-feat .grid__item:first-child .grid__item-body', ['padding-top']);
    expect(before['padding-top'], 'unset featured body top').toBe('36px'); // 2.25rem

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res = await styleComponent(page, pageId, { '--grid-item-padding': LOUD_PX });
    expect(res.success).toBe(true);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    const after = await computed(page, '#pp-grid-feat .grid__item:first-child .grid__item-body', ['padding-top']);
    // The 0.25rem residue the grid schema's uniform-cards recipe used to have to
    // document: card 1's body top no longer diverges from the authored padding.
    expect(after['padding-top'], 'featured body top follows the slot').toBe(LOUD_PX);
  });

  // ── A-10 — embed body ink joins the slot surface ───────────────────────────

  test('#577 A-10: --embed-body-color drives embed content ink on the base AND inverted band', async ({
    page,
  }) => {
    pageId = createPage('E2E 577 embed body color');
    setComposition(pageId, [
      { component: 'embed', props: { id: 'pp-emb-plain', title: 'Embed', content: '<p>Embedded copy.</p>' } },
      { component: 'embed', props: { id: 'pp-emb-inv', theme: 'inverted', title: 'Embed', content: '<p>Embedded copy.</p>' } },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-emb-inv .embed__content')).toBeVisible({ timeout: 10000 });
    // Byte-identical unset, BOTH bands. The base band is the one that gained a `color`
    // declaration where it previously had none, so its `inherit` fallback has to be
    // pinned or a future change from `inherit` to a literal lands unnoticed on every
    // default and muted embed.
    const beforePlain = await computed(page, '#pp-emb-plain .embed__content', ['color']);
    const beforeInv = await computed(page, '#pp-emb-inv .embed__content', ['color']);
    expect(beforePlain.color, 'unset base embed content must still inherit the body ink').toBe(INK);
    expect(beforeInv.color, 'unset inverted embed content').toBe(PAGE_BG);

    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });
    const res0 = await styleComponent(page, pageId, { '--embed-body-color': LOUD_HEX }, undefined, 0);
    expect(res0.success).toBe(true);
    const res1 = await styleComponent(page, pageId, { '--embed-body-color': LOUD_HEX }, undefined, 1);
    expect(res1.success).toBe(true);

    await page.goto(`/?page_id=${pageId}`);
    const plain = await computed(page, '#pp-emb-plain .embed__content', ['color']);
    const inv = await computed(page, '#pp-emb-inv .embed__content', ['color']);
    expect(plain.color, 'base embed content follows the slot').toBe(LOUD_COLOR);
    expect(inv.color, 'inverted embed content follows the slot').toBe(LOUD_COLOR);
  });

  // ── A-43 — the hero subtitle stops defeating --line-height-body ────────────

  test('#577 A-43: retuning --line-height-body moves the hero subtitle too', async ({ page }) => {
    pageId = createPage('E2E 577 hero subtitle leading');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-hero-lh', title: 'Hero', subheading: 'A supporting line long enough to wrap onto several lines at any viewport width.' },
      },
    ]);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator('#pp-hero-lh .hero__subtitle')).toBeVisible({ timeout: 10000 });

    // Byte-identical at the shipped token value: 1.6 x 17px (1.0625rem) = 27.2px.
    const before = await computed(page, '#pp-hero-lh .hero__subtitle', ['line-height', 'font-size']);
    const fontPx = parseFloat(before['font-size']);
    expect(parseFloat(before['line-height'])).toBeCloseTo(fontPx * 1.6, 1);

    // Retune the token the way a site retheme does; the subtitle must follow.
    await page.addStyleTag({ content: ':root { --line-height-body: 2.5; }' });
    const after = await computed(page, '#pp-hero-lh .hero__subtitle', ['line-height']);
    expect(
      parseFloat(after['line-height']),
      'hero subtitle must follow --line-height-body, not a duplicated 1.6 literal',
    ).toBeCloseTo(fontPx * 2.5, 1);
  });
});

/**
 * #578 — the measure gate's ONE deliberate render change: hero's `12ch` title cap.
 *
 *   BEFORE                                    AFTER
 *   .hero--left  .hero__title ┐ max-width:    .hero__title { max-width:
 *   .hero--split .hero__title ┘ 12ch            var(--hero-heading-measure, none) }
 *
 * WHY THE COLUMN, NOT THE HEADING, IS THE SUBJECT. `.hero__content` is a flex COLUMN
 * item, so it shrink-wraps to its WIDEST child. A cap on the H1 therefore narrowed the
 * whole content column — title, subtitle AND button group — not just the headline.
 * Measured on the seeded bands at 1280 that was 468px of a 1088px inner: 43%.
 *
 * WHY NOT A SMALLER ch VALUE. `ch` is viewport-local; the column is not. 24ch renders
 * 896px at 1440 and 1280 but 792px at 1152 and 744px at 1024, so it re-strands the
 * column at exactly the laptop widths most of the audience uses. The smallest value
 * inert down to 1024 is ~29ch, which is `none` with extra steps. On `split` the grid
 * track binds first (~17ch), so 16/20/24ch and `none` are indistinguishable there —
 * only 12ch differed, and it left 85px of an already-narrow track unused.
 *
 * THE FIVE VIEWPORTS ARE THE EVIDENCE. The ch-vs-column argument is only visible across
 * the range, so the ruling pins 1440 / 1280 / 1152 / 1024 / 375 rather than the usual
 * 1280 + 375 pair. At 375 both states are column-bound, which is the claim that the
 * change is desktop-only.
 *
 * ROW 3 CARRY-IN. `.hero__subtitle` keeps a RATIFIED 40ch cap, and `.hero__content`
 * shrink-wraps to its widest child — so on a SHORT title the subtitle can become the new
 * column binder. That is checked explicitly rather than assumed.
 */
test.describe('#578 hero heading measure', () => {
  let pageId = 0;

  // Long enough to exceed any plausible cap at 1440; short enough to read in a diff.
  const LONG_TITLE = 'A deliberately long hero headline written to overflow every plausible measure';
  const SHORT_TITLE = 'Ship faster';
  const SUBTITLE =
    'A supporting lede that is comfortably longer than the short headline above it, so the ' +
    'subtitle 40ch cap is the widest thing in the column when the title is short.';

  // 1024 and 1152 are the laptop widths the ch analysis turns on; 768 is the split
  // stack boundary, so 1024 is also the narrowest true two-column split.
  const VIEWPORTS = [1440, 1280, 1152, 1024, 375];

  test.afterEach(() => {
    if (pageId) {
      try {
        deletePage(pageId);
      } catch {
        /* already cleaned */
      }
      pageId = 0;
    }
  });

  async function open(page: any, width: number, selector: string) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(selector)).toBeVisible({ timeout: 10000 });
  }

  /** Title box, content column box, and the inner the column sits in. */
  function measureHero(page: any, rootSel: string) {
    return page.evaluate((sel: string) => {
      const root = document.querySelector(sel) as HTMLElement;
      if (!root) throw new Error(`no hero matched ${sel}`);
      const title = root.querySelector('.hero__title') as HTMLElement;
      const content = root.querySelector('.hero__content') as HTMLElement;
      const inner = root.querySelector('.hero__inner') as HTMLElement;
      const subtitle = root.querySelector('.hero__subtitle') as HTMLElement | null;

      const lines = (el: HTMLElement) => {
        const range = document.createRange();
        range.selectNodeContents(el);
        return new Set(
          Array.from(range.getClientRects())
            .filter((r) => r.width > 0 && r.height > 0)
            .map((r) => Math.round(r.top)),
        ).size;
      };

      return {
        titleMaxWidth: getComputedStyle(title).maxWidth,
        titleWidth: Math.round(title.getBoundingClientRect().width),
        titleLines: lines(title),
        contentWidth: Math.round(content.getBoundingClientRect().width),
        innerWidth: Math.round(inner.getBoundingClientRect().width),
        subtitleWidth: subtitle ? Math.round(subtitle.getBoundingClientRect().width) : 0,
        subtitleMaxWidth: subtitle ? getComputedStyle(subtitle).maxWidth : '',
      };
    }, rootSel);
  }

  /**
   * The cap is GONE on both formerly-capped layouts, at every viewport. `none` is
   * max-width's initial value, so this is also the assertion that centered and cover —
   * which never had the cap — are unchanged.
   */
  for (const layout of ['left', 'split'] as const) {
    test(`#578 ${layout} hero title is uncapped at every viewport @smoke`, async ({ page }) => {
      pageId = createPage(`E2E 578 ${layout} uncapped`);
      setComposition(pageId, [
        {
          component: 'hero',
          props: {
            id: 'pp-h578',
            layout,
            title: LONG_TITLE,
            subheading: SUBTITLE,
            // split needs a second-column ingredient or the renderer degrades it to left.
            ...(layout === 'split' ? { proof: '<p>Trusted by teams</p>' } : {}),
          },
        },
      ]);

      for (const width of VIEWPORTS) {
        await open(page, width, '#pp-h578 .hero__title');
        const got = await measureHero(page, '#pp-h578');
        expect(got.titleMaxWidth, `@${width}: the 12ch cap must be gone`).toBe('none');
        await expectNoViewportOverflow(page, `#578 ${layout} hero @${width}`);
      }
    });
  }

  /**
   * The measured claim of register row 8, on the layout that carried the defect worst:
   * the LEFT hero's content column was 468px of a 1088px inner at 1280 and is now the
   * full --measure-centered column. Asserted as a RATIO rather than a pixel count so a
   * font-metric difference on a bare CI font stack cannot flake it.
   */
  test('#578 left hero content column is no longer stranded at desktop @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 578 left column reclaim');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-h578l', layout: 'left', title: LONG_TITLE, subheading: SUBTITLE },
      },
    ]);

    for (const width of [1440, 1280, 1152, 1024]) {
      await open(page, width, '#pp-h578l .hero__title');
      const got = await measureHero(page, '#pp-h578l');
      // Pre-#578 this ratio was 0.43 at 1280. The column is now bound by
      // --hero-content-width (--measure-centered, 896px) or by the inner, whichever
      // is narrower — never by a character count on the H1.
      expect(
        got.contentWidth / got.innerWidth,
        `@${width}: the content column must use its inner, not 43% of it`,
      ).toBeGreaterThan(0.7);
      expect(got.titleLines, `@${width}: a long headline still wraps`).toBeGreaterThanOrEqual(2);
    }
  });

  /**
   * MOBILE, CORRECTED AGAINST MEASUREMENT. Register row 8 states "At 375 both are
   * column-bound — no mobile change." Measured on a stressed fixture that is true of the
   * COLUMN and false of the TITLE: at 375 the content column is 343px before and after
   * (the container binds it, so no sibling band moves and no layout shifts), but 12ch of
   * the 40px mobile heading is 287.8px — NARROWER than that 343px column — so the cap did
   * bind on the headline itself. Deleting it widens a long mobile headline 288 -> 343px
   * and drops it from 7 lines to 5 (band height 288 -> 206px).
   *
   * The direction is the same defect the ruling names (a character count leaving 55px of
   * the column unused), so the decision is unaffected; only that one clause of the row's
   * impact text was wrong. Pinned here as MEASURED rather than as CLAIMED.
   */
  test('#578 at 375 the column is unchanged but the headline reclaims it @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 578 mobile');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-h578m', layout: 'left', title: LONG_TITLE, subheading: SUBTITLE },
      },
    ]);

    await open(page, 375, '#pp-h578m .hero__title');
    const got = await measureHero(page, '#pp-h578m');
    expect(got.titleMaxWidth).toBe('none');
    // The claim that holds: the column is the container's, so nothing around it moves.
    expect(got.contentWidth).toBe(got.innerWidth);
    // The claim that does not: the title now fills that column instead of stopping at 12ch.
    expect(got.titleWidth).toBe(got.contentWidth);
    await expectNoViewportOverflow(page, '#578 hero @375');
  });

  /**
   * ROW 3 CARRY-IN, checked rather than assumed. With the title cap gone, a SHORT title
   * no longer sets the column width — `.hero__content` shrink-wraps to its widest child,
   * so the subtitle's ratified 40ch cap becomes the binder. That is the expected and
   * intended outcome (a lede measure is a better column driver than a headline
   * character count), and this pin records it so a later change to either cap has to
   * confront the interaction.
   */
  test('#578 short-title left hero: the subtitle 40ch cap becomes the column binder @smoke', async ({
    page,
  }) => {
    pageId = createPage('E2E 578 short title subtitle binding');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-h578s', layout: 'left', title: SHORT_TITLE, subheading: SUBTITLE },
      },
    ]);

    await open(page, 1280, '#pp-h578s .hero__title');
    const got = await measureHero(page, '#pp-h578s');

    expect(got.subtitleMaxWidth, 'the subtitle keeps its ratified 40ch cap').not.toBe('none');
    // The column is the subtitle's box, not the short title's, and not the full inner.
    expect(got.contentWidth).toBe(got.subtitleWidth);
    expect(got.contentWidth, 'a short title no longer strands the column').toBeLessThan(
      got.innerWidth,
    );
    expect(got.titleWidth, 'the short title is narrower than the column it sits in').toBeLessThan(
      got.contentWidth + 1,
    );
  });

  /** The slot ships and works: an operator can now cap a hero headline deliberately. */
  test('#578 --hero-heading-measure caps the title on every layout @smoke', async ({ page }) => {
    pageId = createPage('E2E 578 hero measure slot');
    setComposition(pageId, [
      {
        component: 'hero',
        props: { id: 'pp-h578c', layout: 'centered', title: LONG_TITLE, subheading: SUBTITLE },
      },
    ]);

    await open(page, 1280, '#pp-h578c .hero__title');
    expect((await measureHero(page, '#pp-h578c')).titleMaxWidth).toBe('none');

    // styleComponent dispatches through the admin AJAX endpoint, so it needs the chat
    // screen's localized config (window.ppAiChat) — it is not present on the front end.
    await page.goto('/wp-admin/admin.php?page=pp-ai-chat');
    await page.waitForSelector('#pp-ai-messages', { timeout: 10000 });

    // `centered` never carried the 12ch rule, so this also proves the slot was declared
    // on the unscoped .hero__title rule rather than on the two formerly-capped layouts.
    const res = await styleComponent(page, pageId, { '--hero-heading-measure': '20rem' });
    expect(res.success).toBe(true);

    await open(page, 1280, '#pp-h578c .hero__title');
    const capped = await measureHero(page, '#pp-h578c');
    const rootFont = await page.evaluate(() =>
      parseFloat(getComputedStyle(document.documentElement).fontSize),
    );
    expect(capped.titleMaxWidth).toBe(`${Math.round(rootFont * 20)}px`);
    expect(capped.titleWidth).toBe(Math.round(rootFont * 20));
  });
});

/**
 * #584 rendered coverage — the four families this gate completes, measured in the browser.
 *
 * Why rendered and not just source pins: every CSS assertion elsewhere in this change is a
 * text match on components.css, and a text match cannot see WHICH RULE WINS. That is not a
 * hypothetical — the cover-hero ring gap in this very issue (a per-instance slot added to
 * `.hero .btn:not3` while `.hero--cover .hero__cta:not3`, the live winner on a cover band,
 * kept its old chain) passed every source pin. The same class produced #543, #564 and #565.
 *
 * Each ring case is measured TWICE: once with only the per-instance slot set, and once with
 * the site-wide knob ALSO set. The second read is the one that matters — it is the exact
 * configuration where a slot placed one link too low silently loses, which is what #564 was
 * filed for.
 */
test.describe('#584 slot families, as rendered', () => {
  let pageId: number;

  // Authored values, deliberately far from any theme token so a fallback cannot impersonate
  // a win. Named once; the probe below resolves them the same way the page would.
  const RING = '#7c3aed'; //        per-instance ring   -> rgb(124, 58, 237)
  const RING_HOVER = '#15803d'; //  per-instance hover  -> rgb(21, 128, 61)
  const GLOBAL_RING = '#b91c1c'; // site-wide knob      -> rgb(185, 28, 28)
  const RING_RGB = 'rgb(124, 58, 237)';
  const RING_HOVER_RGB = 'rgb(21, 128, 61)';

  // The same 60x180 portrait PNG the #583 block uses: taller than every cap under test,
  // so a cap assertion cannot pass by the asset simply being small.
  const TALL_PNG_584 =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADwAAAC0CAIAAABHfdiQAAAAs0lEQVR4nO3OAQkAIBAAMStYwSz2z2QM72GwAFv73HHW94F0mLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHSAtLR0gLS0dIC0tHTAyPQD1EOdbnWkTyIAAAAASUVORK5CYII=';

  test.afterEach(() => {
    if (pageId) deletePage(pageId);
    pageId = 0 as unknown as number;
  });

  async function open584(page: any, width: number, readySelector: string) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`/?page_id=${pageId}`);
    await expect(page.locator(readySelector).first()).toBeVisible({ timeout: 10000 });
    // Transitions off, or a hover read samples a mid-animation colour.
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none !important;}' });
  }

  const ringOf = (page: any, sel: string) =>
    page.evaluate(
      (s: string) => getComputedStyle(document.querySelector(s) as HTMLElement).borderTopColor,
      sel,
    );

  // ── A-38: the four new ring slots actually paint, and outrank the global knob ──────

  const RING_CASES = [
    {
      name: 'hero primary (plain band)',
      sel: '.hero__cta',
      component: (style: Record<string, string>) => ({
        component: 'hero',
        props: {
          id: 'pp-h584a',
          layout: 'left',
          title: 'Ring slot',
          button_text: 'Start',
          button_url: '/start',
        },
        style,
      }),
      slots: { rest: '--hero-button-border', hover: '--hero-button-hover-border' },
    },
    {
      // The case the source pins missed. `.hero--cover .hero__cta:not3` is the live border
      // winner here, NOT `.hero .btn:not3`.
      name: 'hero primary (cover band)',
      sel: '.hero__cta',
      component: (style: Record<string, string>) => ({
        component: 'hero',
        props: {
          id: 'pp-h584b',
          layout: 'cover',
          title: 'Ring slot on a photo band',
          image_url: TALL_PNG_584,
          image_alt: 'Backdrop',
          button_text: 'Start',
          button_url: '/start',
        },
        style,
      }),
      slots: { rest: '--hero-button-border', hover: '--hero-button-hover-border' },
    },
    {
      // The panel CTA's ring is decided by the SHARED premium rule, not by the section
      // block's keystone. If the slot had only been routed in the keystone (the literal
      // reading of the issue's single citation), this case would read the theme accent.
      name: 'section panel CTA',
      sel: '.section__panel-cta',
      component: (style: Record<string, string>) => ({
        component: 'section',
        props: {
          id: 'pp-s584',
          layout: 'text-panel',
          body: '<p>Body copy for the text panel band.</p>',
          panel_heading: 'Panel',
          panel_cta_text: 'Book a call',
          panel_cta_url: '/call',
          panel_cta_variant: 'primary',
        },
        style,
      }),
      slots: { rest: '--section-panel-cta-border', hover: '--section-panel-cta-hover-border' },
    },
  ];

  for (const { name, sel, component, slots } of RING_CASES) {
    test(`#584 ${name}: the per-instance ring paints at rest AND on hover @smoke`, async ({
      page,
    }) => {
      pageId = createPage(`E2E 584 ring ${name}`);
      setComposition(pageId, [
        component({ [slots.rest]: RING, [slots.hover]: RING_HOVER }),
      ]);

      for (const width of [1280, 375]) {
        await open584(page, width, sel);
        expect(await ringOf(page, sel), `${name} @${width}: resting ring`).toBe(RING_RGB);
        await page.hover(sel);
        expect(await ringOf(page, sel), `${name} @${width}: hover ring`).toBe(RING_HOVER_RGB);
      }
    });

    test(`#584 ${name}: the per-instance ring outranks the site-wide knob @smoke`, async ({
      page,
    }) => {
      // A slot one link too low still paints when nothing competes. This is the read that
      // separates "declared" from "wins" — the #564 defect class, one tier down.
      pageId = createPage(`E2E 584 ring vs global ${name}`);
      setComposition(pageId, [
        component({
          [slots.rest]: RING,
          [slots.hover]: RING_HOVER,
          '--btn-border-color': GLOBAL_RING,
          '--btn-hover-border-color': GLOBAL_RING,
        }),
      ]);

      await open584(page, 1280, sel);
      expect(await ringOf(page, sel), `${name}: per-instance ring must beat --btn-border-color`)
        .toBe(RING_RGB);
      await page.hover(sel);
      expect(
        await ringOf(page, sel),
        `${name}: per-instance hover ring must beat --btn-hover-border-color`,
      ).toBe(RING_HOVER_RGB);
    });
  }

  // ── A-40: one slot, both caps, and the strip gap ──────────────────────────────────

  test('#584 --logos-image-size caps BOTH logo heights and --logos-gap moves the strip @smoke', async ({
    page,
  }) => {
    // Unset, #583 already pins 48px unlabelled / 40px labelled / 32px gap in this exact
    // fixture shape. Set, the label-driven switch stops applying and both collapse to one
    // value — the behaviour the slot description promises an author, measured rather than
    // asserted from the stylesheet text.
    pageId = createPage('E2E 584 logos sizing');
    setComposition(pageId, [
      {
        component: 'logos',
        props: {
          id: 'pp-l584',
          title: 'Trusted by',
          items: [
            { image_url: TALL_PNG_584, image_alt: 'Unlabeled' },
            { image_url: TALL_PNG_584, image_alt: 'Labeled', label: 'Delivery' },
          ],
        },
        style: { '--logos-image-size': '4rem', '--logos-gap': '12px' },
      },
    ]);

    for (const width of [1280, 375]) {
      await open584(page, width, '.logos__list');
      await page.waitForFunction(() => {
        const imgs = Array.from(document.querySelectorAll('.logos__image')) as any[];
        return imgs.length === 2 && imgs.every((i) => i.complete && i.naturalWidth > 0);
      });

      const got = await page.evaluate(() => {
        const items = Array.from(document.querySelectorAll('.logos__item')) as HTMLElement[];
        return {
          gap: getComputedStyle(document.querySelector('.logos__list') as HTMLElement).gap,
          heights: items.map((i) =>
            Math.round((i.querySelector('.logos__image') as HTMLElement).getBoundingClientRect().height),
          ),
          labeled: items.map((i) => i.classList.contains('logos__item--labeled')),
          naturals: items.map((i) => (i.querySelector('.logos__image') as any).naturalHeight),
        };
      });

      // Guard: the asset must exceed the authored cap, or the height pins prove nothing.
      for (const n of got.naturals) {
        expect(n, `@${width}: fixture asset must exceed the 64px cap`).toBeGreaterThan(64);
      }
      // The switch is retired while the slot is set: BOTH items land on 4rem.
      expect(got.labeled, `@${width}: fixture must still be one plain + one labelled`).toEqual([
        false,
        true,
      ]);
      expect(got.heights, `@${width}: both caps collapse to --logos-image-size`).toEqual([64, 64]);
      expect(got.gap, `@${width}: strip gap follows --logos-gap`).toBe('12px');
    }
  });

  // ── A-41: the band-fusing step is now executable on all ten bands ─────────────────

  test('#584 heading rhythm: the six new slots zero their band heading margin @smoke', async ({
    page,
  }) => {
    // The whole justification for the row: band fusing requires margin-bottom 0 on the upper
    // band's last element, and six of ten bands could not express it. Measured on all six.
    const BANDS: Array<{ slot: string; sel: string; band: unknown }> = [
      {
        slot: '--hero-heading-margin-bottom',
        sel: '.hero__title',
        band: { component: 'hero', props: { id: 'pp-x1', layout: 'left', title: 'Hero' } },
      },
      {
        slot: '--cta-heading-margin-bottom',
        sel: '.cta__title',
        band: {
          component: 'cta',
          props: { id: 'pp-x2', title: 'Cta', button_text: 'Go', button_url: '/go' },
        },
      },
      {
        slot: '--stats-heading-margin-bottom',
        sel: '.stats__heading',
        band: {
          component: 'stats',
          props: { id: 'pp-x3', title: 'Stats', items: [{ number: '10', label: 'Teams' }] },
        },
      },
      {
        slot: '--table-heading-margin-bottom',
        sel: '.table-section__heading',
        band: {
          component: 'table',
          props: { id: 'pp-x4', title: 'Table', headers: ['A'], rows: [['1']] },
        },
      },
      {
        slot: '--embed-heading-margin-bottom',
        sel: '.embed__heading',
        band: { component: 'embed', props: { id: 'pp-x5', title: 'Embed', content: 'Embedded.' } },
      },
      {
        slot: '--logos-heading-margin-bottom',
        sel: '.logos__heading',
        band: {
          component: 'logos',
          props: {
            id: 'pp-x6',
            title: 'Logos',
            items: [{ image_url: TALL_PNG_584, image_alt: 'Mark' }],
          },
        },
      },
    ];

    // Unset first: each band must still render the margin it always had, or "byte-identical
    // unset" is a claim rather than a fact. Then zeroed, in one composition.
    pageId = createPage('E2E 584 heading rhythm');
    setComposition(pageId, BANDS.map(({ band }) => band));
    await open584(page, 1280, '.hero__title');
    const unset = await page.evaluate(
      (sels: string[]) =>
        sels.map((s) =>
          getComputedStyle(document.querySelector(s) as HTMLElement).marginBottom,
        ),
      BANDS.map((b) => b.sel),
    );
    // hero's shipped value is the universal reset's 0; four are var(--space-lg) = 32px and
    // cta is var(--space-xs) = 4px. These are the exact literals the six new slots carry as
    // their fallbacks, measured rather than restated from the stylesheet.
    expect(unset, 'unset heading rhythm must be unchanged').toEqual([
      '0px',
      '4px',
      '32px',
      '32px',
      '32px',
      '32px',
    ]);

    // LIVE first, with a value no default could produce. Asserting only the zeroed state
    // would be vacuous on hero, whose shipped margin is already 0 — it would read as proof
    // of a capability without exercising it.
    setComposition(
      pageId,
      BANDS.map(({ band, slot }) => ({ ...(band as object), style: { [slot]: '11px' } })),
    );
    for (const width of [1280, 375]) {
      await open584(page, width, '.hero__title');
      const authored = await page.evaluate(
        (sels: string[]) =>
          sels.map((s) =>
            getComputedStyle(document.querySelector(s) as HTMLElement).marginBottom,
          ),
        BANDS.map((b) => b.sel),
      );
      expect(authored, `@${width}: every new slot must actually reach its heading`).toEqual(
        BANDS.map(() => '11px'),
      );
    }

    // Then zero, the value the header-tightening case actually asks for. hero is excluded
    // because its shipped margin is already 0, so a 0 assertion there proves nothing. NOTE:
    // on none of these bands is the heading the band's trailing element (each has a required
    // content prop that renders after it), so this is the band's INTERNAL header rhythm —
    // the seam with the band below is closed with --<component>-padding-bottom.
    const FUSABLE = BANDS.filter((b) => b.slot !== '--hero-heading-margin-bottom');
    setComposition(
      pageId,
      BANDS.map(({ band, slot }) =>
        slot === '--hero-heading-margin-bottom'
          ? band
          : { ...(band as object), style: { [slot]: '0' } },
      ),
    );
    await open584(page, 1280, '.hero__title');
    const zeroed = await page.evaluate(
      (sels: string[]) =>
        sels.map((s) => getComputedStyle(document.querySelector(s) as HTMLElement).marginBottom),
      FUSABLE.map((b) => b.sel),
    );
    expect(zeroed, 'the five non-hero bands must be able to zero their header rhythm').toEqual(
      FUSABLE.map(() => '0px'),
    );
  });

  // ── A-42: srcset arrives without moving the painted box ───────────────────────────

  test('#584 grid and testimonials images gain srcset without changing the painted box @smoke', async ({
    page,
  }) => {
    // The mechanism claim, both halves: markup changes, paint does not. Measured against the
    // SAME fixture rendered with and without a resolvable image_id would need a real
    // attachment; here the id is deliberately unresolvable, which is the branch every
    // already-published page takes — so this pins that the helper swap left those pages alone.
    pageId = createPage('E2E 584 item images');
    setComposition(pageId, [
      {
        component: 'grid',
        props: {
          id: 'pp-g584',
          items: [
            { title: 'Card', text: 'Body', image_url: TALL_PNG_584, image_alt: 'Banner' },
          ],
        },
      },
      {
        component: 'testimonials',
        props: {
          id: 'pp-t584',
          items: [
            { quote: 'Great.', author: 'Jane', image_url: TALL_PNG_584, image_alt: 'Jane' },
          ],
        },
      },
    ]);

    for (const width of [1280, 375]) {
      await open584(page, width, '.grid__item-image');
      const got = await page.evaluate(() => {
        const g = document.querySelector('.grid__item-image') as HTMLImageElement;
        const a = document.querySelector('.testimonials__avatar') as HTMLImageElement;
        const box = (el: HTMLElement) => {
          const r = el.getBoundingClientRect();
          const cs = getComputedStyle(el);
          return {
            w: Math.round(r.width),
            h: Math.round(r.height),
            fit: cs.objectFit,
            loading: (el as HTMLImageElement).loading,
          };
        };
        return {
          gridSrcset: g.getAttribute('srcset'),
          avatarSrcset: a.getAttribute('srcset'),
          grid: box(g),
          avatar: box(a),
        };
      });

      // No resolvable id -> no srcset, exactly today's single-source <img>.
      expect(got.gridSrcset, `@${width}: unresolvable id keeps the plain grid <img>`).toBeNull();
      expect(got.avatarSrcset, `@${width}: unresolvable id keeps the plain avatar`).toBeNull();
      // The boxes the CSS owns are untouched by the helper swap.
      expect(got.grid.fit, `@${width}: card banner keeps object-fit`).toBe('cover');
      expect(got.grid.loading).toBe('lazy');
      expect(got.avatar.fit, `@${width}: avatar keeps object-fit`).toBe('cover');
      expect(got.avatar.loading).toBe('lazy');
      expect(got.avatar.w, `@${width}: avatar stays a fixed square`).toBe(got.avatar.h);
      expect(got.grid.w, `@${width}: card banner still has width`).toBeGreaterThan(0);
    }
  });
});
